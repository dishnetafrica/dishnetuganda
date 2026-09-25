<?php
declare(strict_types=1);
namespace Dn\Admin;

use Dn\Db\Database;

/**
 * The estate writes on the Admin plane that concern a router — G-C (docs/114
 * §K, docs/118 §B D-3, D-11) completed by docs/121: register a router, assign
 * it to an operator, record where it is in its lifecycle, queue its
 * configuration.
 *
 * Each is one SECURITY DEFINER function owned by dnb_def_prov and EXECUTE-able
 * by dnb_adminwrite, writing its own audit row inside its own transaction with
 * the ACTOR PASSED IN from the identity boundary — the route hands over
 * StaffIdentity::$subject, never anything a browser sent. dnb_adminwrite holds
 * no table privilege, so nothing this class could be made to run reaches a
 * table except through those four functions.
 *
 * What is NOT here: no desired-state authoring, no credential, no WAN fact,
 * and nothing that touches a router. A router write here is a ROW — a registry
 * row, or an intent row for the worker — and only Dn\Jobs ever turns a row
 * into a connection (F2).
 */
final class RouterAdmin
{
    /**
     * The lifecycle states a DishNet staff member may RECORD (docs/121 D-3).
     *
     * `diverged` is missing on purpose: migration 012 says divergence is
     * COMPUTED from desired and actual, so recording it by hand would assert a
     * measurement nobody made. `registered` is the initial state and has no
     * transition into it. Whether a transition is LEGAL is migration 012's
     * trigger's decision, not this list's: the list only says which words a
     * person may put in.
     */
    public const RECORDABLE_STATES = [
        'staged', 'shipped', 'connected', 'provisioned', 'active', 'orphaned', 'decommissioned',
    ];

    private ?Database $db = null;

    /** @param \Closure(): Database $connect opens the Admin write connection on first use */
    public function __construct(private readonly \Closure $connect) {}

    public static function on(Database $db): self { return new self(static fn(): Database => $db); }

    /**
     * Record a router staged by $actor. Identity established at the bench
     * (docs/31 §3.1 step 11): serial, model, version, WireGuard public key,
     * tunnel /32. The row enters the estate owned by nobody (customer_id NULL).
     *
     * @return array the mt_devices row as the function returned it
     */
    public function register(string $serial, string $model, ?string $rosVersion,
                             ?string $wgPubkey, ?string $tunnelIp, string $actor): array
    {
        return $this->call('SELECT * FROM mt_device_register(?,?,?,?,?,?)',
            [$serial, $model, $rosVersion, $wgPubkey, $tunnelIp, self::actor($actor)]);
    }

    /**
     * Assign a router to an operator (the explicit target — never a tenant
     * context, docs/114 D-AUTH-3) and optionally to one of that operator's
     * sites. The customer/site pairing is a CONSTRAINT (W-2), so a foreign
     * site is refused below this code and takes the audit row with it.
     *
     * @return array|null the row, or null when no such device exists
     */
    public function assign(string $deviceId, string $customerId, ?string $siteId,
                           ?string $name, string $actor): ?array
    {
        $row = $this->call('SELECT * FROM mt_device_assign(?,?,?,?,?)',
            [$deviceId, $customerId, $siteId, $name, self::actor($actor)]);
        return ($row['id'] ?? null) === null ? null : $row;
    }

    /**
     * Record the lifecycle state $actor OBSERVED (docs/121 D-3, D-4, D-10).
     *
     * Migration 012's trigger refuses an illegal transition and the refusal
     * comes back as RouterRefused carrying its reason. A state the row already
     * holds is a no-op with no audit row (migration 028, RULE I-1), so a
     * browser retry cannot leave a false record behind.
     *
     * @return array|null the row, or null when no such device exists
     */
    public function setState(string $deviceId, string $state, string $actor): ?array
    {
        if (!in_array($state, self::RECORDABLE_STATES, true)) {
            throw new \InvalidArgumentException("'{$state}' is not a state a staff member may record");
        }
        $row = $this->call('SELECT * FROM mt_device_set_state(?,?,?)',
            [$deviceId, $state, self::actor($actor)]);
        return ($row['id'] ?? null) === null ? null : $row;
    }

    /**
     * Queue the router's configuration for delivery — an INTENT (F2), never a
     * command (docs/121 D-5..D-9). The operator is derived from the device row
     * inside the function; an unassigned, undeliverable, decommissioned or
     * address-less router is refused there with a plain reason. The same key
     * for the same router returns the existing intent and writes nothing
     * (RULE I-1); the same key for a different request is refused.
     *
     * @return array{replayed: bool, intent: array}|null null when no such device exists
     */
    public function requestProvision(string $deviceId, string $idempotencyKey, string $actor): ?array
    {
        $key = trim($idempotencyKey);
        if ($key === '') {
            throw new \InvalidArgumentException('a provisioning request requires an idempotency key');
        }
        $row = $this->call('SELECT mt_device_provision_request(?,?,?) AS r',
            [$deviceId, $key, self::actor($actor)]);
        if (($row['r'] ?? null) === null) { return null; }
        $j = json_decode((string) $row['r'], true, 512, JSON_THROW_ON_ERROR);
        return ['replayed' => (bool) ($j['replayed'] ?? false), 'intent' => (array) ($j['intent'] ?? [])];
    }

    private static function actor(string $actor): string
    {
        $a = trim($actor);
        if ($a === '') {
            throw new \InvalidArgumentException('a router write requires the identity of the staff member performing it');
        }
        return $a;
    }

    private function call(string $sql, array $args): array
    {
        try {
            $db  = $this->db ??= ($this->connect)();
            $row = $db->attempt(static fn(Database $d) => $d->one($sql, $args));
        } catch (\PDOException $e) {
            $state = (string) ($e->errorInfo[0] ?? $e->getCode());
            if ($state === '23505') {
                throw new RouterRefused('serial, WireGuard key or tunnel address is already registered', 0, $e);
            }
            if ($state === '23503') {
                throw new RouterRefused('operator or site not found, or the site belongs to another operator', 0, $e);
            }
            if (in_array($state, ['23514', '23502', 'DN409'], true)) {
                throw new RouterRefused(self::reason($e->getMessage()), 0, $e);
            }
            throw $e;
        }
        return $row ?? [];
    }

    private static function reason(string $driverMessage): string
    {
        if (preg_match('/ERROR:\s+(.+?)(\r?\n|$)/', $driverMessage, $m)) { return trim($m[1]); }
        return 'refused';
    }
}
