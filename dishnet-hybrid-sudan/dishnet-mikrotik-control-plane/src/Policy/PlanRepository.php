<?php
declare(strict_types=1);
namespace Dn\Policy;

use Dn\Db\Database;

/** Customer-scoped. Call inside TenantContext::run(). */
final class PlanRepository
{
    public function __construct(private Database $db) {}

    public function create(string $customerId, array $p, ?string $createdBy, ?string $siteId = null): array
    {
        $profileId = (new ProfileResolver($this->db))->resolve(
            (int) $p['rate_down_bps'], (int) $p['rate_up_bps'],
            (int) $p['duration_s'], (int) $p['devices_per_voucher'],
            isset($p['data_cap_bytes']) && $p['data_cap_bytes'] !== null
                ? (int) $p['data_cap_bytes'] : null
        );

        return $this->db->one(
            'INSERT INTO mt_plans
               (customer_id, site_id, profile_id, name, duration_s,
                rate_down_bps, rate_up_bps, data_cap_bytes,
                devices_per_voucher, mode, price_minor, currency, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?) RETURNING *',
            [$customerId, $siteId, $profileId, trim((string) $p['name']),
             (int) $p['duration_s'], (int) $p['rate_down_bps'], (int) $p['rate_up_bps'],
             isset($p['data_cap_bytes']) && $p['data_cap_bytes'] !== null
                 ? (int) $p['data_cap_bytes'] : null,
             (int) $p['devices_per_voucher'], $p['mode'],
             (int) $p['price_minor'], strtoupper((string) $p['currency']), $createdBy]
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
     */
    public function update(string $id, array $p): ?array
    {
        $cur = $this->find($id);
        if ($cur === null) { return null; }

        $merged = array_merge($cur, array_filter($p, fn($v) => $v !== null));
        $profileId = (new ProfileResolver($this->db))->resolve(
            (int) $merged['rate_down_bps'], (int) $merged['rate_up_bps'],
            (int) $merged['duration_s'], (int) $merged['devices_per_voucher'],
            $merged['data_cap_bytes'] !== null ? (int) $merged['data_cap_bytes'] : null
        );

        return $this->db->one(
            'UPDATE mt_plans
                SET name = ?, duration_s = ?, rate_down_bps = ?, rate_up_bps = ?,
                    data_cap_bytes = ?, devices_per_voucher = ?, mode = ?,
                    price_minor = ?, currency = ?, profile_id = ?
              WHERE id = ? RETURNING *',
            [trim((string) $merged['name']), (int) $merged['duration_s'],
             (int) $merged['rate_down_bps'], (int) $merged['rate_up_bps'],
             $merged['data_cap_bytes'] !== null ? (int) $merged['data_cap_bytes'] : null,
             (int) $merged['devices_per_voucher'], $merged['mode'],
             (int) $merged['price_minor'], strtoupper((string) $merged['currency']),
             $profileId, $id]
        );
    }

    /** Retire, never delete: a voucher sold against a plan is a revenue record. */
    public function retire(string $id): ?array
    {
        return $this->db->one(
            'UPDATE mt_plans SET active = false WHERE id = ? RETURNING *', [$id]);
    }
}
