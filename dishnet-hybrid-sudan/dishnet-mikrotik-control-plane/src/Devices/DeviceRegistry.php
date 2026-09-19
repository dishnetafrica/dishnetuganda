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
        // Via the admin function: a device may still be unassigned when it is
        // staged, and the policy on mt_device_secrets would refuse a row with
        // no customer under any context.
        $this->db->one('SELECT mt_device_set_secret(?,?,?) AS ok',
            [$deviceId, $username, $box->seal($password, $deviceId)]);
    }

    /** @return array{username:string,password:string}|null */
    public function credentials(string $deviceId): ?array
    {
        // Resolve the DEVICE first, through a table the caller's tenant context
        // governs. Audit finding S1: this method used to read the secret row
        // directly, so a caller holding any device id recovered that device's
        // password — the encryption opened happily, because the key is
        // process-wide and the associated data was supplied by the caller.
        //
        // ENCRYPTION IS NOT TENANT ISOLATION. The AEAD still binds an envelope
        // to one device so it cannot be moved between rows; deciding WHO MAY
        // ASK is authorization, and that is what the two checks below are.
        // RLS on mt_device_secrets is the boundary; this is the second layer.
        $device = $this->db->one('SELECT id FROM mt_devices WHERE id = ?', [$deviceId]);
        if ($device === null) { return null; }

        $row = $this->db->one('SELECT * FROM mt_device_secrets WHERE device_id = ?', [$deviceId]);
        if ($row === null) { return null; }
        $box = $this->box ?? new SecretBox();
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
        // Admin function, for the same reason as the secret above.
        $this->db->one('SELECT mt_device_set_desired(?, ?::jsonb) AS ok',
            [$deviceId, json_encode($desired, JSON_THROW_ON_ERROR)]);
    }

    public function setActual(string $deviceId, array $actual): void
    {
        // Written by the worker INSIDE the intent's tenant context, so the row
        // carries that customer and the policy is satisfied. Outside a tenant
        // context this fails, which is correct: read-back state belongs to
        // whoever owns the device.
        $this->db->exec(
            'INSERT INTO mt_device_config (device_id, customer_id, actual, actual_read_at)
             VALUES (?, mt_current_customer(), ?::jsonb, now())
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
