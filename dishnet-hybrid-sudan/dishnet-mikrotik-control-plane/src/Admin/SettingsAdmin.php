<?php
declare(strict_types=1);
namespace Dn\Admin;

use Dn\Db\Database;
use Dn\Notify\SmsSettings;

/**
 * The SMS settings a DishNet Admin saves in the Admin panel (migration 033,
 * docs/128 SS-6, SS-7): one SECURITY DEFINER function, mt_sms_settings_set(),
 * owned by dnb_def_auth and EXECUTE-able by dnb_adminwrite only, which writes
 * its own audit row with the ACTOR PASSED IN from the identity boundary.
 *
 * The API key arrives here in clear, once, from the request body, and leaves
 * SEALED: SmsSettings seals it under a key derived from DNB_SECRET_KEY, with the
 * account it belongs to as associated data, and fingerprints it so that the
 * database can tell the same key typed again from a new one. The database never
 * sees it in clear, and nothing here returns it, logs it or puts it in an
 * exception: a driver error is reduced to its SQLSTATE, because PostgreSQL's
 * own detail line can quote the row being written.
 */
final class SettingsAdmin
{
    private ?Database $db = null;

    /** @param \Closure(): Database $connect opens the Admin write connection on first use */
    public function __construct(private readonly \Closure $connect, private ?SmsSettings $crypto = null) {}

    public static function on(Database $db, ?SmsSettings $crypto = null): self
    {
        return new self(static fn(): Database => $db, $crypto);
    }

    /**
     * @param string      $provider 'none' or 'africastalking'
     * @param string|null $apiKey   null keeps the stored key (same username only)
     * @return array{changed: bool, version: int, key: string} key: set|replaced|kept|removed|none — never the key
     */
    public function saveSms(string $provider, ?string $username, ?string $apiKey, ?string $sender, string $actor): array
    {
        $actor = trim($actor);
        if ($actor === '') {
            throw new \InvalidArgumentException('an SMS settings change requires the identity of the staff member making it');
        }
        $sealed = $fp = null;
        if ($provider === 'africastalking' && $apiKey !== null) {
            if ($username === null) { throw new SettingsRefused('username is required'); }
            $crypto = $this->crypto ??= new SmsSettings();
            $sealed = $crypto->seal($apiKey, $username);
            $fp     = $crypto->fingerprint($apiKey);
        }
        try {
            $db  = $this->db ??= ($this->connect)();
            $row = $db->attempt(static fn(Database $d) => $d->one(
                'SELECT mt_sms_settings_set(?,?,?,?,?,?) AS r',
                [$provider, $username, $sender, $sealed, $fp, $actor]));
        } catch (\PDOException $e) {
            $state = (string) ($e->errorInfo[0] ?? $e->getCode());
            if ($state === '22023') {
                throw new SettingsRefused(self::reason($e->getMessage()));
            }
            // Never the driver's message: its DETAIL line can quote the row.
            throw new \RuntimeException('the SMS settings could not be saved (SQLSTATE ' . $state . ')');
        }
        $j = json_decode((string) ($row['r'] ?? 'null'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($j)) { throw new \LogicException('mt_sms_settings_set returned nothing'); }
        return ['changed' => (bool) $j['changed'], 'version' => (int) $j['version'], 'key' => (string) $j['key']];
    }

    /** The function's own sentence — the first line after ERROR:, never the DETAIL. */
    private static function reason(string $driverMessage): string
    {
        if (preg_match('/ERROR:\s+(.+?)(\r?\n|$)/', $driverMessage, $m)) { return trim($m[1]); }
        return 'refused';
    }
}
