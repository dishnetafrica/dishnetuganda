<?php
declare(strict_types=1);
namespace Dn\Admin;

use Dn\Db\Database;

/**
 * The first two estate writes on the Admin plane — G-C (docs/114 §K,
 * docs/118 §B D-3, D-11): register a router, assign a router to an operator.
 *
 * Each is one of migration 020's W-1 functions, owned by dnb_def_prov and
 * EXECUTE-able by dnb_adminwrite only, writing its own audit row inside its
 * own transaction with the ACTOR PASSED IN from the identity boundary — the
 * route hands over StaffIdentity::$subject, never anything a browser sent.
 * dnb_adminwrite holds no table privilege, so nothing this class could be
 * made to run reaches a table except through those two functions.
 *
 * What is NOT here: no device state change (mt_device_set_state), no desired
 * state, no credential, no intent. Queuing an action needs an Admin-plane
 * enqueue function that does not exist (docs/118 D-2), and none of the
 * others was asked for. Nothing in this class touches a router: a router
 * write here is a ROW, and only Dn\Jobs ever turns a row into a connection.
 */
final class RouterAdmin
{
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
