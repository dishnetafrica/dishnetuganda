<?php
declare(strict_types=1);
namespace Dn\Admin;

use Dn\Db\Database;

/**
 * The Admin-plane writes that onboard an operator (migration 030, docs/125):
 * create the operator, start its HotSpot service, add a location.
 *
 * Each is one SECURITY DEFINER function owned by dnb_def_prov and EXECUTE-able
 * by dnb_adminwrite only, writing its own audit row with the ACTOR PASSED IN
 * from the identity boundary — the route hands over StaffIdentity::$subject,
 * never anything a browser sent. dnb_adminwrite holds no table privilege, so
 * nothing this class could be made to run reaches a table except through
 * those three functions.
 *
 * Every call carries an idempotency key. The function answers a replay before
 * it writes anything (RULE I-1): the same key for the same request returns the
 * first result with `replayed: true` and no audit row; the same key for a
 * different request is refused.
 *
 * A location takes NO operator: the function reads it from the service row
 * (derive, never accept). Nothing here contacts a router.
 *
 * Since docs/126 (J-1) the same connection also creates the people who can
 * sign in for an operator, through mt_admin_principal_create (migration 027).
 * That function takes no idempotency key: the phone's global unique index is
 * its replay guard (docs/108), so a retry fails at the insert, before the
 * audit row, and nothing is written twice.
 */
final class OnboardingAdmin
{
    private ?Database $db = null;

    /** @param \Closure(): Database $connect opens the Admin write connection on first use */
    public function __construct(private readonly \Closure $connect) {}

    public static function on(Database $db): self { return new self(static fn(): Database => $db); }

    /** @return array{replayed: bool, customer: array} */
    public function createOperator(string $name, string $idempotencyKey, string $actor): array
    {
        return $this->call('SELECT mt_admin_operator_create(?,?,?) AS r',
            [$name, $idempotencyKey, self::actor($actor)], 'customer')
            ?? throw new \LogicException('mt_admin_operator_create returned nothing');
    }

    /** @return array{replayed: bool, service: array}|null null when no such operator exists */
    public function startService(string $operatorId, string $idempotencyKey, string $actor): ?array
    {
        return $this->call('SELECT mt_admin_service_create(?,?,?) AS r',
            [$operatorId, $idempotencyKey, self::actor($actor)], 'service');
    }

    /** @return array{replayed: bool, site: array}|null null when no such service exists */
    public function addLocation(string $serviceId, string $name, ?string $location,
                                string $idempotencyKey, string $actor): ?array
    {
        return $this->call('SELECT mt_admin_site_create(?,?,?,?,?) AS r',
            [$serviceId, $name, $location, $idempotencyKey, self::actor($actor)], 'site');
    }

    /**
     * A person who can sign in for an operator (docs/126). The target operator
     * is explicit — the Admin plane never sets tenant context — and the phone
     * arrives already in its canonical form (Dn\Auth\Phone). The capability
     * names must already be known ones: OpCapability::toPg() does not quote.
     *
     * @param list<string> $capabilities
     * @return string|null the new principal's id; null when no such operator exists
     */
    public function addPrincipal(string $operatorId, string $kind, string $displayName, string $phone,
                                 array $capabilities, string $actor): ?string
    {
        if (!\Dn\Auth\OpCapability::known($capabilities)) {
            throw new \InvalidArgumentException('only known op.* capabilities may be passed');
        }
        $args = [$operatorId, $kind, $displayName, $phone,
                 \Dn\Auth\OpCapability::toPg($capabilities), self::actor($actor)];
        try {
            $db  = $this->db ??= ($this->connect)();
            $row = $db->attempt(static fn(Database $d) => $d->one(
                'SELECT mt_admin_principal_create(?,?,?,?,?::text[],?) AS id', $args));
        } catch (\PDOException $e) {
            $state = (string) ($e->errorInfo[0] ?? $e->getCode());
            if ($state === '23503') { return null; }
            if ($state === '23505') {
                // P-B: one phone, one principal. The wording is the customer
                // plane's and says nothing about WHERE the number is in use.
                throw new OnboardingRefused('phone unavailable', 0, $e);
            }
            if (in_array($state, ['23514', '23502', '22001'], true)) {
                $why = self::reason($e->getMessage());
                throw new OnboardingRefused(str_contains($why, 'violates check constraint') ? 'refused' : $why, 0, $e);
            }
            throw $e;
        }
        return (string) ($row['id'] ?? throw new \LogicException('mt_admin_principal_create returned nothing'));
    }

    private static function actor(string $actor): string
    {
        $a = trim($actor);
        if ($a === '') {
            throw new \InvalidArgumentException('an onboarding write requires the identity of the staff member performing it');
        }
        return $a;
    }

    /** @return array{replayed: bool}|null */
    private function call(string $sql, array $args, string $entity): ?array
    {
        try {
            $db  = $this->db ??= ($this->connect)();
            $row = $db->attempt(static fn(Database $d) => $d->one($sql, $args));
        } catch (\PDOException $e) {
            $state = (string) ($e->errorInfo[0] ?? $e->getCode());
            if ($state === '23503') {
                throw new OnboardingRefused('the operator or service it names does not exist', 0, $e);
            }
            if (in_array($state, ['DN409', '23514', '23502', '22001'], true)) {
                throw new OnboardingRefused(self::reason($e->getMessage()), 0, $e);
            }
            throw $e;
        }
        if (($row['r'] ?? null) === null) { return null; }
        $j = json_decode((string) $row['r'], true, 512, JSON_THROW_ON_ERROR);
        return ['replayed' => (bool) ($j['replayed'] ?? false), $entity => (array) ($j[$entity] ?? [])];
    }

    private static function reason(string $driverMessage): string
    {
        if (preg_match('/ERROR:\s+(.+?)(\r?\n|$)/', $driverMessage, $m)) { return trim($m[1]); }
        return 'refused';
    }
}
