<?php
declare(strict_types=1);
namespace Dn\Delivery;

use Dn\Db\Database;
use Dn\Devices\DeviceRegistry;
use Dn\Runtime\Bindings;

/**
 * A deterministic router that lives in this process's memory — docs/80 §4's
 * "SimulatedRouterOs", built in G-C (docs/118 D-9).
 *
 * IT IS NOT A ROUTER, and everything about it says so:
 *
 *   - every result it returns carries `simulated = true`, and the binding
 *     reports isSimulated() at /health;
 *   - it WRITES NOTHING TO THE DATABASE: not mt_devices.state, not
 *     mt_device_config.actual. A device does not become "connected",
 *     "provisioned" or "active" because a simulator answered (instruction
 *     item 9); the intent row's own state is the worker's bookkeeping and
 *     the audit actor carries this binding's name;
 *   - it resolves the device through the SAME DeliveryTarget as the real
 *     adapter, so it refuses exactly what the real adapter refuses — a
 *     payload naming a destination, a foreign or malformed device id, a
 *     device below `connected`;
 *   - it refuses to exist in a process authorized for real bindings. A
 *     simulated router must never share a process with a real adapter — the
 *     same rule the estate simulator (Plugin/Simulator.php) already keeps.
 *
 * What it proves: the worker, the queue, the lease, the retry and the
 * confirm-is-a-read logic, against a router that behaves deterministically.
 * What it proves about RouterOS: nothing (docs/30 Artifact 13 rule 2).
 */
final class SimulatedRouterOs implements DeliveryPort
{
    /** @var array<string, array<string, mixed>> device id → path → values the "router" holds */
    private array $applied = [];
    /** @var array<string, list<string>> device id → HotSpot session ids still present */
    private array $sessions = [];
    /** @var list<string> device ids whose next write must fail, for tests of the failure path */
    private array $refusing = [];

    public function __construct()
    {
        if (Bindings::realBindingsAllowed()) {
            throw new \RuntimeException(
                'SimulatedRouterOs refuses to run in a process authorized for real bindings '
                . '(F6-B): a simulated router must never share a process with a real adapter.');
        }
    }

    public function bindingName(): string { return 'simulated-routeros'; }
    public function isSimulated(): bool { return true; }

    public function deliver(Database $db, array $intent): DeliveryResult
    {
        [$device, $refusal] = DeliveryTarget::resolve($db, $intent, simulated: true);
        if ($refusal !== null) { return $refusal; }

        return match ($intent['kind']) {
            'device.provision'   => $this->provision($db, $device),
            'session.disconnect' => $this->disconnect($device, $intent),
            'voucher.publish',
            'voucher.revoke'     => DeliveryResult::accepted(simulated: true),
            default              => DeliveryResult::permanent(
                                        'no delivery is defined for ' . $intent['kind'], simulated: true),
        };
    }

    public function confirm(Database $db, array $intent): bool
    {
        [$device, $refusal] = DeliveryTarget::resolve($db, $intent, simulated: true);
        if ($refusal !== null) { return false; }

        return match ($intent['kind']) {
            'device.provision'   => $device !== null && $this->matchesDesired($db, $device),
            'session.disconnect' => $device !== null && !in_array(
                                        (string) (DeliveryTarget::payload($intent)['nas_session_id'] ?? ''),
                                        $this->sessions[$device['id']] ?? [], true),
            'voucher.publish',
            'voucher.revoke'     => true,
            default              => false,
        };
    }

    // ── the simulated router's own controls, for tests ─────────────────────

    /** Make the next write to this device fail as a router would (5xx → retryable). */
    public function refuseNextWrite(string $deviceId): void { $this->refusing[] = $deviceId; }

    /** Pretend a HotSpot session exists on this device. */
    public function seedSession(string $deviceId, string $nasSessionId): void
    {
        $this->sessions[$deviceId][] = $nasSessionId;
    }

    /** What the simulated router believes it holds for a device. */
    public function applied(string $deviceId): array { return $this->applied[$deviceId] ?? []; }

    // -----------------------------------------------------------------------
    private function provision(Database $db, ?array $device): DeliveryResult
    {
        if ($device === null) { return DeliveryResult::permanent('device.provision names no device', simulated: true); }
        if (($i = array_search($device['id'], $this->refusing, true)) !== false) {
            unset($this->refusing[$i]);
            return DeliveryResult::retryable('simulated router error (500)', simulated: true);
        }
        $desired = json_decode((string) (new DeviceRegistry($db))->config($device['id'])['desired'], true) ?: [];
        // Memory only. Nothing is written back to the registry.
        $this->applied[$device['id']] = $desired;
        return DeliveryResult::accepted(simulated: true);
    }

    private function disconnect(?array $device, array $intent): DeliveryResult
    {
        if ($device === null) { return DeliveryResult::permanent('session.disconnect names no device', simulated: true); }
        $nas = DeliveryTarget::payload($intent)['nas_session_id'] ?? null;
        if (!is_string($nas) || $nas === '') { return DeliveryResult::permanent('session.disconnect names no session', simulated: true); }
        $this->sessions[$device['id']] = array_values(array_filter(
            $this->sessions[$device['id']] ?? [], static fn($s) => $s !== $nas));
        return DeliveryResult::accepted(simulated: true);
    }

    private function matchesDesired(Database $db, array $device): bool
    {
        $desired = json_decode((string) (new DeviceRegistry($db))->config($device['id'])['desired'], true) ?: [];
        return array_key_exists($device['id'], $this->applied)
            && json_encode($this->applied[$device['id']]) === json_encode($desired);
    }
}
