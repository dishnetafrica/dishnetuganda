<?php
declare(strict_types=1);
namespace Dn\Policy;

use Dn\Db\Database;

/**
 * Plans, through the commercial write boundary (migration 024, A-1/T3, B-2).
 *
 * Every mutation here is one call to a SECURITY DEFINER function owned by
 * dnb_def_comm, which performs the write AND its audit row in the same
 * transaction. dnb_app holds EXECUTE on those functions and no INSERT on
 * mt_audit_log at all, so the audit row is a consequence of the act rather
 * than a separate statement a caller could omit.
 *
 * NOTE there is no $customerId parameter any more. The function reads
 * mt_current_customer(), set by TenantContext from the authenticated
 * principal, so a forged customer is unrepresentable rather than rejected.
 * Reads are unchanged: they are RLS-scoped SELECTs and need no boundary.
 *
 * Customer-scoped. Call inside TenantContext::run().
 */
final class PlanRepository
{
    public function __construct(private Database $db) {}

    /**
     * @param array       $p      validated plan values (see PlanValidator)
     * @param string|null $actor  the authenticated principal, never a request field
     * @param string|null $source request context, recorded and not trusted
     */
    public function create(array $p, ?string $actor, ?string $siteId = null,
                           ?string $source = null): array
    {
        return $this->db->one(
            'SELECT * FROM mt_plan_create(?,?,?,?,?,?,?,?,?,?,?,?)',
            [trim((string) $p['name']), (int) $p['duration_s'],
             (int) $p['rate_down_bps'], (int) $p['rate_up_bps'],
             self::intOrNull($p['data_cap_bytes'] ?? null),
             (int) $p['devices_per_voucher'], $p['mode'],
             (int) $p['price_minor'], strtoupper((string) $p['currency']),
             $siteId, $actor, $source]
        );
    }

    /** @return list<array> */
    public function all(bool $includeRetired = true): array
    {
        $sql = 'SELECT * FROM mt_plans' . ($includeRetired ? '' : ' WHERE active')
             . ' ORDER BY active DESC, name';
        return $this->db->query($sql);
    }

    public function find(string $id): ?array
    {
        return $this->db->one('SELECT * FROM mt_plans WHERE id = ?', [$id]);
    }

    /**
     * Update a plan's commercial face. Its technical values move the profile
     * with them, so enforcement follows what is being sold.
     *
     * A null field means UNCHANGED, which is what
     * array_filter($body, fn($v) => $v !== null) meant before the merge moved
     * into the function -- including the consequence that there is no way to
     * clear a data cap.
     */
    public function update(string $id, array $p, ?string $actor,
                           ?string $source = null): ?array
    {
        return self::rowOrNull($this->db->one(
            'SELECT * FROM mt_plan_update(?,?,?,?,?,?,?,?,?,?,?,?)',
            [$id,
             isset($p['name']) ? trim((string) $p['name']) : null,
             self::intOrNull($p['duration_s'] ?? null),
             self::intOrNull($p['rate_down_bps'] ?? null),
             self::intOrNull($p['rate_up_bps'] ?? null),
             self::intOrNull($p['data_cap_bytes'] ?? null),
             self::intOrNull($p['devices_per_voucher'] ?? null),
             $p['mode'] ?? null,
             self::intOrNull($p['price_minor'] ?? null),
             isset($p['currency']) ? strtoupper((string) $p['currency']) : null,
             $actor, $source]
        ));
    }

    /** Retire, never delete: a voucher sold against a plan is a revenue record. */
    public function retire(string $id, ?string $actor, ?string $source = null): ?array
    {
        return self::rowOrNull($this->db->one(
            'SELECT * FROM mt_plan_retire(?,?,?)', [$id, $actor, $source]));
    }

    private static function intOrNull(mixed $v): ?int
    {
        return $v === null ? null : (int) $v;
    }

    /**
     * A composite-returning function that returns NULL still yields ONE row,
     * with every column null -- not zero rows. Without this a "not found"
     * would look like a successful update of a plan with no id.
     */
    private static function rowOrNull(?array $row): ?array
    {
        return ($row['id'] ?? null) === null ? null : $row;
    }
}
