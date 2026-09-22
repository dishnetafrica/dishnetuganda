<?php
declare(strict_types=1);
namespace Dn\Admin;

use Dn\Db\Database;

/**
 * The only way the Admin API reaches data.
 *
 * It can call migrations 019 and 021's projections and nothing else. The allowlist below
 * is not politeness — it is the reason a future route cannot pass an arbitrary
 * function name, or SQL, through this seam. The connecting role (`dnb_admin`)
 * holds EXECUTE on exactly these functions and no privilege on any table, so
 * even a bug here cannot read a base table.
 */
final class AdminReader
{
    private const ALLOWED = [
        'mt_admin_customers' => 0, 'mt_admin_customer' => 1,
        'mt_admin_sites' => 0,
        'mt_admin_routers' => 0,   'mt_admin_router' => 1,
        'mt_admin_plans' => 0,     'mt_admin_vouchers' => 0,
        'mt_admin_voucher_batches' => 0, 'mt_admin_sessions' => 0,
        'mt_admin_intents' => 0,   'mt_admin_audit' => 0,
        // Migration 021, approved as an extension of the eleven.
        'mt_admin_services' => 0,  'mt_admin_voucher' => 1,
    ];

    public function __construct(private readonly Database $db) {}

    /** @return list<array> */
    public function __invoke(string $fn, array $args = []): array
    {
        if (!array_key_exists($fn, self::ALLOWED)) {
            throw new \InvalidArgumentException("not an admin projection: {$fn}");
        }
        if (count($args) !== self::ALLOWED[$fn]) {
            throw new \InvalidArgumentException("wrong arity for {$fn}");
        }
        // A uuid argument that is not a uuid is a client error, not a query.
        foreach ($args as $a) {
            if (!preg_match('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i', (string) $a)) {
                return [];
            }
        }
        $ph = $args === [] ? '' : implode(',', array_fill(0, count($args), '?'));
        return $this->db->query("SELECT * FROM {$fn}({$ph})", $args);
    }
}
