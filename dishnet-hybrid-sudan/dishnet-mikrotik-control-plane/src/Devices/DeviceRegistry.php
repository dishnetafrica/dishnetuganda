<?php
declare(strict_types=1);
namespace Dn\Devices;

use Dn\Crypto\SecretBox;
use Dn\Db\Database;

/**
 * The device register.
 *
 * Reads are customer-scoped by RLS. Registration and staging are DishNet
 * operations and run without a customer context — a device belongs to nobody
 * until it is assigned, which is deliberate: unassigned stock must not be
 * visible to any customer.
 */
final class DeviceRegistry
{
    public function __construct(private Database $db, private ?SecretBox $box = null) {}

    /** Admin path. Records the trust anchor established at staging (docs/31 §3.1). */
    public function register(
        string $serial, string $model, ?string $rosVersion,
        ?string $wgPubkey, ?string $tunnelIp, ?string $stagedBy
    ): array {
        // Via the admin function (migration 013): unassigned stock belongs to
        // no customer, so there is no tenant context under which the policy's
        // WITH CHECK could pass.
        return $this->db->one(
            'SELECT * FROM mt_device_register(?,?,?,?,?,?)',
            [$serial, $model, $rosVersion, $wgPubkey, $tunnelIp, $stagedBy]);
    }

    /** Store management credentials sealed, bound to this device. */
    public function setCredentials(string $deviceId, string $username, string $password): void
    {
        $box = $this->box ?? new SecretBox();
        $this->db->exec(
            'INSERT INTO mt_device_secrets (device_id, username, secret_sealed)
             VALUES (?,?,?)
             ON CONFLICT (device_id) DO UPDATE
               SET username = EXCLUDED.username,
                   secret_sealed = EXCLUDED.secret_sealed, rotated_at = now()',
            [$deviceId, $username, $box->seal($password, $deviceId)]
        );
    }

    /** @return array{username:string,password:string}|null */
    public function credentials(string $deviceId): ?array
    {
        $row = $this->db->one('SELECT * FROM mt_device_secrets WHERE device_id = ?', [$deviceId]);
        if ($row === null) { return null; }
        $box = $this->box ?? new SecretBox();
        // The device id is the associated data, so an envelope lifted from
        // another device's row will not open here.
        return ['username' => $row['username'],
                'password' => $box->open($row['secret_sealed'], $deviceId)];
    }

    public function assign(string $deviceId, string $customerId, ?string $siteId, ?string $name): array
    {
        return $this->db->one('SELECT * FROM mt_device_assign(?,?,?,?)',
            [$deviceId, $customerId, $siteId, $name]);
    }

    public function transition(string $deviceId, string $state): array
    {
        return $this->db->one('SELECT * FROM mt_device_set_state(?,?)', [$deviceId, $state]);
    }

    public function find(string $deviceId): ?array
    {
        return $this->db->one('SELECT * FROM mt_devices WHERE id = ?', [$deviceId]);
    }

    /** @return list<array> customer-scoped */
    public function forCustomer(): array
    {
        return $this->db->query('SELECT * FROM mt_devices ORDER BY name NULLS LAST, serial');
    }

    public function setDesired(string $deviceId, array $desired): void
    {
        $this->db->exec(
            'INSERT INTO mt_device_config (device_id, desired, desired_at)
             VALUES (?, ?::jsonb, now())
             ON CONFLICT (device_id) DO UPDATE
               SET desired = EXCLUDED.desired, desired_at = now()',
            [$deviceId, json_encode($desired, JSON_THROW_ON_ERROR)]);
    }

    public function setActual(string $deviceId, array $actual): void
    {
        $this->db->exec(
            'INSERT INTO mt_device_config (device_id, actual, actual_read_at)
             VALUES (?, ?::jsonb, now())
             ON CONFLICT (device_id) DO UPDATE
               SET actual = EXCLUDED.actual, actual_read_at = now()',
            [$deviceId, json_encode($actual, JSON_THROW_ON_ERROR)]);
    }

    public function config(string $deviceId): array
    {
        return $this->db->one('SELECT * FROM mt_device_config WHERE device_id = ?', [$deviceId])
            ?? ['desired' => '{}', 'actual' => '{}'];
    }

    /**
     * Divergence is COMPUTED, never stored.
     *
     * A stored divergence flag is a third copy of the truth that goes stale
     * the moment either side changes without it.
     *
     * @return list<string> keys where desired and actual disagree
     */
    public function divergence(string $deviceId): array
    {
        $c = $this->config($deviceId);
        $desired = json_decode((string) ($c['desired'] ?? '{}'), true) ?: [];
        $actual  = json_decode((string) ($c['actual']  ?? '{}'), true) ?: [];
        $out = [];
        foreach ($desired as $path => $wanted) {
            if (!array_key_exists($path, $actual)) { $out[] = $path; continue; }
            if (!self::satisfies($actual[$path], $wanted)) { $out[] = $path; }
        }
        sort($out);
        return $out;
    }

    /**
     * Desired state is a SUBSET assertion, not an equality one.
     *
     * RouterOS REST answers a path with the rows it holds — a list of
     * profiles, each with a dozen attributes we never set. Requiring the
     * response to EQUAL what we asked for would call every device diverged
     * forever, because the router legitimately knows more about itself than
     * we told it.
     *
     * So: for each attribute we asked for, is there a row that has it? Values
     * are compared as strings, because RouterOS returns 'yes'/'no' and
     * numbers as text and a JSON round trip does not always preserve the
     * distinction.
     */
    private static function satisfies(mixed $actual, mixed $wanted): bool
    {
        if (!is_array($wanted)) { return (string) $actual === (string) $wanted; }

        // A list of rows: satisfied if ANY row carries every wanted attribute.
        if (is_array($actual) && $actual !== [] && array_is_list($actual)) {
            foreach ($actual as $row) {
                if (is_array($row) && self::satisfies($row, $wanted)) { return true; }
            }
            return false;
        }
        if (!is_array($actual)) { return false; }

        foreach ($wanted as $k => $v) {
            if (!array_key_exists($k, $actual)) { return false; }
            if (is_array($v)) {
                if (!self::satisfies($actual[$k], $v)) { return false; }
            } elseif ((string) $actual[$k] !== (string) $v) {
                return false;
            }
        }
        return true;
    }
}
