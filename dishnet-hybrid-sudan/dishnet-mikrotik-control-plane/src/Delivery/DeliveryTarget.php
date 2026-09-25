<?php
declare(strict_types=1);
namespace Dn\Delivery;

use Dn\Db\Database;
use Dn\Devices\TunnelAddress;

/**
 * Which router an intent is about — derived, never accepted (docs/118 D-6).
 *
 * The ONLY source of a delivery destination is the mt_devices row the
 * intent names, read under the intent's own tenant context, so a device of
 * another operator is simply not found. A payload that tries to name a host,
 * an address, a serial or a credential is refused before anything is opened:
 * the request may say WHICH device; the record says WHERE and WITH WHAT.
 *
 * Shared by the real adapter and the simulated one on purpose. If the two had
 * separate resolvers, the simulator could accept an intent the real adapter
 * would refuse, and a test against the simulator would prove nothing.
 *
 * The lifecycle gate (docs/118 D-4): delivery presumes a tunnel, and the
 * registry records a tunnel as `connected` or later. Nothing here moves a
 * device between states — that is a staff act through mt_device_set_state.
 */
final class DeliveryTarget
{
    /** A payload may say which device. It may never say where, or as whom. */
    public const FORBIDDEN_KEYS = [
        'host', 'endpoint', 'address', 'tunnel_ip', 'ip', 'url', 'port',
        'serial', 'username', 'password', 'secret', 'wg_pubkey',
    ];

    /** States in which the registry records a management path to the device. */
    public const DELIVERABLE_STATES = ['connected', 'provisioned', 'active', 'diverged'];

    private const UUID = '/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i';

    public static function payload(array $intent): array
    {
        $p = json_decode((string) ($intent['payload'] ?? '{}'), true);
        return is_array($p) ? $p : [];
    }

    /**
     * Resolve the device an intent is about.
     *
     * @return array{0:?array,1:?DeliveryResult}
     *   [device row, null]      the device is resolvable and deliverable
     *   [null, null]            the intent names no device at all (the kind decides)
     *   [null, DeliveryResult]  refused; the result says whether to retry
     */
    public static function resolve(Database $db, array $intent, bool $simulated = false): array
    {
        $payload = self::payload($intent);
        foreach (self::FORBIDDEN_KEYS as $k) {
            if (array_key_exists($k, $payload)) {
                return [null, DeliveryResult::permanent(
                    "payload names a destination or credential ({$k}); the router is "
                    . 'derived from the device record, never from the request', $simulated)];
            }
        }

        $id = $payload['device_id']
            ?? ((($intent['target_type'] ?? null) === 'device') ? ($intent['target_id'] ?? null) : null);
        if ($id === null) { return [null, null]; }

        if (!is_string($id) || !preg_match(self::UUID, $id)) {
            return [null, DeliveryResult::permanent('device identity is malformed', $simulated)];
        }

        // Under the intent's tenant context. Another operator's device, or an
        // id that never existed, look the same here: not found.
        $device = $db->one('SELECT * FROM mt_devices WHERE id = ?', [$id]);
        if ($device === null) {
            return [null, DeliveryResult::permanent('device not found', $simulated)];
        }
        if ($device['state'] === 'decommissioned') {
            return [null, DeliveryResult::permanent('device is decommissioned', $simulated)];
        }
        if (!in_array($device['state'], self::DELIVERABLE_STATES, true)) {
            return [null, DeliveryResult::retryable(
                "device is recorded as {$device['state']}, not as connected", $simulated)];
        }
        if ($device['tunnel_ip'] === null || !TunnelAddress::isManagement((string) $device['tunnel_ip'])) {
            return [null, DeliveryResult::retryable('device has no management address recorded', $simulated)];
        }
        return [$device, null];
    }
}
