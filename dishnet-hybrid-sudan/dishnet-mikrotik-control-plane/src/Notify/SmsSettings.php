<?php
declare(strict_types=1);
namespace Dn\Notify;

use Dn\Crypto\SecretBox;

/**
 * The SMS settings a DishNet Admin enters in the Admin panel (migration 033,
 * docs/128): the one validation rule, and the sealing of the API key.
 *
 * THE KEY NEVER RESTS IN CLEAR (SS-2). It is sealed here, in the Admin API,
 * before the database sees it, and opened only in the worker's memory. The key
 * that seals it is DERIVED from DNB_SECRET_KEY under a label of its own — the
 * CodeEnvelope and AdminSession precedent — so a settings envelope and a
 * sign-in-code envelope never open under each other. The associated data names
 * the account the key belongs to, so a key moved onto another username by
 * editing a row does not open.
 *
 * The fingerprint is a keyed hash under a second derived label. Its only use is
 * letting the database tell "the same key typed again" (no change, no audit
 * row — RULE I-1) from a new one. Without DNB_SECRET_KEY it says nothing about
 * the key; with it, the envelope could be opened anyway.
 *
 * The rules are the staging command's prompts' and the adapter's own, in one
 * place. Migration 033 repeats the username and sender rules as CHECKs: the
 * floor beneath this class.
 */
final class SmsSettings
{
    public const LABEL    = 'dn-sms-settings-v1';
    public const FP_LABEL = 'dn-sms-settings-fp-v1';

    public const PROVIDERS = ['none', 'africastalking'];
    public const USERNAME  = '/^[A-Za-z0-9_.-]{1,64}$/';
    public const API_KEY   = '/^[A-Za-z0-9_-]{16,256}$/';
    public const SENDER    = '/^[A-Za-z0-9 ._-]{1,15}$/';

    private SecretBox $box;
    private string $fpKey;

    public function __construct(?string $masterKey = null)
    {
        $master = $masterKey ?? (getenv('DNB_SECRET_KEY') ?: '');
        if ($master === '') {
            throw new \RuntimeException(
                'DNB_SECRET_KEY is not set, so an SMS API key can be neither sealed nor opened. '
                . 'Refusing to run with an implicit or default key.');
        }
        $this->box   = new SecretBox(hash_hmac('sha256', self::LABEL, $master, true));
        $this->fpKey = hash_hmac('sha256', self::FP_LABEL, $master, true);
    }

    /** The account a key belongs to, bound in as associated data. */
    public static function associatedData(string $username): string
    {
        return 'africastalking|' . $username;
    }

    public function seal(string $apiKey, string $username): string
    {
        return $this->box->seal($apiKey, self::associatedData($username));
    }

    /** @throws \RuntimeException when it does not open: another DNB_SECRET_KEY, another username, or tampered. */
    public function open(string $sealed, string $username): string
    {
        return $this->box->open($sealed, self::associatedData($username));
    }

    public function fingerprint(string $apiKey): string
    {
        return hash_hmac('sha256', $apiKey, $this->fpKey);
    }

    public static function validUsername(string $v): bool { return preg_match(self::USERNAME, $v) === 1; }
    public static function validApiKey(string $v): bool   { return preg_match(self::API_KEY, $v) === 1; }
    public static function validSender(string $v): bool   { return preg_match(self::SENDER, $v) === 1; }
}
