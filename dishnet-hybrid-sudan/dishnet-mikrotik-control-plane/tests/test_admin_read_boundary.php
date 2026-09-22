<?php
/**
 * Migration 019 — the Admin cross-customer read boundary.
 *
 * The claim under test is narrow and precise: staff read the estate WITHOUT
 * any weakening of the customer boundary. So these assertions are mostly
 * negative — what dnb_admin still cannot do — plus one positive that matters
 * more than it looks: the reads must actually return rows from BOTH customers.
 * A boundary that silently returns nothing looks identical to a working one,
 * and this project has been caught by that twice.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use Dn\Admin\AdminReader;
use Dn\Admin\Capability;
use Dn\Admin\DenyAllIdentity;
use Dn\Admin\StaffIdentity;
use Dn\Admin\StaffRole;
use Dn\Api\AdminRoutes;
use Dn\Db\Database;
use Dn\Http\Request;
use Dn\Http\Serializer\AdminProjection;
use Dn\Runtime\Bindings;

require_once __DIR__ . '/admin_identity_double.php';

$owner   = Database::inspector();
$adminapi = Database::adminApi();
$inspect = Database::inspector();
$ids = seed_two_customers($owner);
$A = $ids['A']; $B = $ids['B'];

$reader = new AdminReader($adminapi);
$req = new Request('GET', '/x');
function hitr(\Dn\Http\Router $r, string $m, string $p, Request $q): \Dn\Http\Response {
    $mm = $r->match($m, $p);
    if ($mm === null) { bad("no route {$m} {$p}"); return new \Dn\Http\Response(404); }
    [$h, $params] = $mm;
    return $h(new Request($m, $p, [], [], $params));
}
$routes = AdminRoutes::build(new FixedStaff(new StaffIdentity('s', StaffRole::Admin, 't')),
                             Bindings::defaults(), $reader);

// ===========================================================================
t('1. ADMIN SEES MULTIPLE CUSTOMERS — the boundary works, not merely errors');
$cust = hitr($routes, 'GET', '/api/v1/admin/customers', $req);
is_($cust->status, 200, 'the estate read succeeds');
$names = array_column($cust->body['customer'], 'id');
is_(in_array($A['customer'], $names, true), true, 'customer A is present');
is_(in_array($B['customer'], $names, true), true, 'customer B is present TOO');
is_(count($names) >= 2, true, 'so this is genuinely cross-customer, not an empty pass');

$sites = hitr($routes, 'GET', '/api/v1/admin/sites', $req);
$sc = array_column($sites->body['site'], 'customer_id');
is_(count(array_unique($sc)) >= 2, true, 'sites span both customers');

// ===========================================================================
t('2. dnb_admin CANNOT SELECT the base tables directly');
foreach (['mt_customers','mt_sites','mt_devices','mt_plans','mt_vouchers',
          'mt_voucher_batches','mt_sessions','mt_intents','mt_audit_log',
          'mt_device_secrets','mt_device_config','mt_auth_sessions','mt_principals'] as $t) {
    foreach (['SELECT','INSERT','UPDATE','DELETE'] as $priv) {
        is_($inspect->one('SELECT has_table_privilege(?,?,?) AS h', ['dnb_adminapi', $t, $priv])['h'],
            false, "dnb_adminapi has no {$priv} on {$t}");
    }
}
throws_(fn() => $adminapi->query('SELECT * FROM mt_customers'),
    'permission denied', 'and a direct SELECT is refused at the database');

t('9. NO WRITE CAPABILITY reached dnb_admin through this migration');
foreach (['mt_admin_customers()','mt_admin_routers()','mt_admin_audit()'] as $f) {
    is_($inspect->one("SELECT has_function_privilege('dnb_adminapi', ?, 'EXECUTE') AS h", [$f])['h'],
        true, "dnb_adminapi may EXECUTE {$f}");
}
is_($inspect->one("SELECT count(*) n FROM information_schema.role_table_grants
                    WHERE grantee='dnb_adminapi'")['n'], 0,
    'dnb_adminapi holds ZERO table grants of any kind');

// ===========================================================================
t('3. CUSTOMER RLS REMAINS ENABLED AND FORCED on every boundary table');
foreach (['mt_customers','mt_sites','mt_devices','mt_plans','mt_vouchers',
          'mt_voucher_batches','mt_sessions','mt_intents','mt_audit_log'] as $t) {
    $r = $inspect->one('SELECT relrowsecurity r, relforcerowsecurity f
                          FROM pg_class WHERE oid = ?::regclass', [$t]);
    is_([$r['r'], $r['f']], [true, true], "{$t} still has RLS + FORCE");
}
is_($inspect->one("SELECT rolbypassrls b FROM pg_roles WHERE rolname='dnb_adminapi'")['b'],
    false, 'dnb_adminapi has no BYPASSRLS');
is_($inspect->one("SELECT rolbypassrls b FROM pg_roles WHERE rolname='dnb_def_admin'")['b'],
    false, 'and neither does the definer owner');
is_($inspect->one("SELECT rolcanlogin l FROM pg_roles WHERE rolname='dnb_def_admin'")['l'],
    false, 'dnb_def_admin cannot log in at all');
is_($inspect->one("SELECT rolsuper s FROM pg_roles WHERE rolname='dnb_def_admin'")['s'],
    false, 'and is not superuser');

// ===========================================================================
t('4. app.customer_id CANNOT ALTER an estate read');
$before = count(hitr($routes, 'GET', '/api/v1/admin/customers', $req)->body['customer']);
$adminapi->exec("SET app.customer_id = '" . $A['customer'] . "'");
$after = count(hitr($routes, 'GET', '/api/v1/admin/customers', $req)->body['customer']);
is_($after, $before, 'setting a tenant context changes nothing — scope is the function\'s');
$adminapi->exec("SET app.customer_id = '00000000-0000-0000-0000-000000000000'");
is_(count(hitr($routes, 'GET', '/api/v1/admin/customers', $req)->body['customer']), $before,
    'nor does a forged one');
$adminapi->exec('RESET app.customer_id');

// ===========================================================================
t('5. THE COLUMN CONTRACT matches AdminProjection exactly, in both directions');
$pairs = [['mt_admin_customers', 'customer'], ['mt_admin_sites', 'site'],
          ['mt_admin_routers', 'router'],     ['mt_admin_plans', 'plan'],
          ['mt_admin_vouchers', 'voucher'],   ['mt_admin_voucher_batches', 'batch'],
          ['mt_admin_sessions', 'session'],   ['mt_admin_intents', 'intent'],
          ['mt_admin_audit', 'audit'],
          // Migration 021, approved as an extension of the eleven.
          ['mt_admin_services', 'service'],
          ['mt_admin_voucher', 'voucherDetail']];
foreach ($pairs as [$fn, $kind]) {
    $cols = array_column($inspect->query(
        "SELECT unnest(proargnames) AS c FROM pg_proc WHERE proname = ?", [$fn]), 'c');
    $cols = array_values(array_filter($cols, static fn($c) => $c !== null && $c !== ''
                                                && !str_starts_with($c, 'p_')));
    $proj = AdminProjection::many($kind, [array_fill_keys($cols, null)]);
    is_(array_keys($proj[0]), $cols,
        "{$fn} returns exactly what AdminProjection::{$kind}() allows");
}

// ===========================================================================
t('6/7/8. NO SECRET, FREE-FORM OR CREDENTIAL FIELD CROSSES THE BOUNDARY');
$blob = '';
foreach (['/api/v1/admin/customers','/api/v1/admin/sites','/api/v1/admin/routers',
          '/api/v1/admin/plans','/api/v1/admin/vouchers','/api/v1/admin/voucher-batches',
          '/api/v1/admin/sessions','/api/v1/admin/intents','/api/v1/admin/audit'] as $p) {
    $blob .= json_encode(hitr($routes, 'GET', $p, $req)->body);
}
foreach (['radius_ref','wg_pubkey','secret_sealed','credential_hash','token_hash',
          'code_hash','"code"','radius_username','mac'] as $f) {
    is_(str_contains($blob, $f), false, "no admin response carries {$f}");
}
// D-2. Asserted at the DATABASE, not merely on today's values: the function
// cannot return these columns at all, so future contents cannot leak either.
foreach ([['mt_admin_intents','payload'], ['mt_admin_intents','last_error'],
          ['mt_admin_audit','detail']] as [$fn, $col]) {
    $names = $inspect->one('SELECT coalesce(array_to_string(proargnames, \',\'), \'\') n
                              FROM pg_proc WHERE proname = ?', [$fn])['n'];
    is_(str_contains($names, $col), false,
        "{$fn} cannot return {$col} — the projection prevents it, not a test of today's data");
}

t('PINNED search_path and no PUBLIC EXECUTE on every projection');
foreach (array_column($pairs, 0) as $fn) {
    $cfg = $inspect->one("SELECT coalesce(array_to_string(proconfig,','),'') c
                            FROM pg_proc WHERE proname = ?", [$fn])['c'];
    is_(str_contains($cfg, 'search_path=public, pg_temp'), true, "{$fn} pins search_path");
    $pub = $inspect->one("SELECT count(*) n FROM pg_proc p, aclexplode(p.proacl) a
                           WHERE p.proname = ? AND a.grantee = 0", [$fn])['n'];
    is_((int) $pub, 0, "PUBLIC holds no EXECUTE on {$fn}");
}

t('THE READER accepts only the thirteen approved projections');
throws_(fn() => ($reader)('mt_customers'), 'not an admin projection', 'a table name is refused');
throws_(fn() => ($reader)('mt_device_secrets'), 'not an admin projection', 'and a secret table especially');
throws_(fn() => ($reader)('mt_admin_customer'), 'wrong arity', 'arity is checked');
is_(($reader)('mt_admin_customer', ['not-a-uuid']), [], 'a non-uuid argument yields nothing');

// ===========================================================================
t('021 — THE TWO APPROVED ADDITIONS BEHAVE LIKE THE ELEVEN');

foreach (['mt_admin_services()', 'mt_admin_voucher(uuid)'] as $fn) {
    is_($inspect->one('SELECT has_function_privilege(?,?,?) AS p',
        ['dnb_adminapi', $fn, 'EXECUTE'])['p'], true, "dnb_adminapi may execute {$fn}");
    is_($inspect->one('SELECT has_function_privilege(?,?,?) AS p',
        ['dnb_app', $fn, 'EXECUTE'])['p'], false, "the request role may NOT execute {$fn}");
    $def = $inspect->one('SELECT p.prosecdef, p.proconfig::text cfg FROM pg_proc p
                            JOIN pg_namespace n ON n.oid = p.pronamespace
                           WHERE n.nspname = \'public\' AND p.oid::regprocedure::text = ?', [$fn]);
    is_($def['prosecdef'], true, "{$fn} is SECURITY DEFINER");
    is_(str_contains((string) $def['cfg'], 'search_path=public'), true,
        "{$fn} pins its search_path");
}

t('021 — services reports a RECORDED state, across the estate');
$svc = ($reader)('mt_admin_services');
is_(count($svc) >= 2, true, 'more than one customer\'s service is visible — estate scope');
is_(count(array_unique(array_column($svc, 'customer_id'))) >= 2, true,
    'and they belong to different customers');
foreach ($svc as $row) {
    is_(array_key_exists('status', $row), true, 'each carries the recorded status');
    // The word that must never appear: this is a record, not a probe.
    is_(in_array('last_seen_at', array_keys($row), true), false,
        'and no liveness field rides along with it');
}
// SignalReport must still refuse to call HotSpot measured, whatever services says.
$hot = null;
foreach (\Dn\Network\SignalReport::inventory() as $x) { if ($x['key'] === 'hotspot') { $hot = $x; } }
is_($hot['status'], \Dn\Network\SignalReport::UNMEASURED,
    'HotSpot liveness stays UNMEASURED — a recorded service state is not a running server');

t('021 — voucher detail returns the LIST fields and nothing more');
// Issued through the real service: the fixture carries no vouchers, and a
// detail assertion against an empty estate would pass by proving nothing.
$ctxV = new \Dn\Tenancy\TenantContext(Database::app());
$ctxV->run($A['customer'], function (Database $db) use ($A) {
    $plan = (new \Dn\Policy\PlanRepository($db))->create($A['customer'],
        ['name' => 'boundary probe', 'duration_s' => 3600,
         'rate_down_bps' => 2000000, 'rate_up_bps' => 1000000, 'data_cap_bytes' => null,
         'devices_per_voucher' => 1, 'mode' => 'elapsed',
         'price_minor' => 1000, 'currency' => 'UGX'], $A['principal'], $A['site']);
    (new \Dn\Vouchers\VoucherService($db))->issueBatch(
        $A['customer'], $plan['id'], 2, $A['site'], $A['principal']);
});
$one = ($reader)('mt_admin_vouchers')[0] ?? null;
is_($one !== null, true, 'the estate has a voucher to open');
$detail = ($reader)('mt_admin_voucher', [$one['id']]);
is_(count($detail), 1, 'the detail projection returns exactly one row');
is_(array_keys($detail[0]), array_keys($one),
    'with exactly the columns the list returns — asking for one row reaches nothing extra');
foreach (['code', 'radius_username', 'secret', 'password'] as $leak) {
    is_(array_key_exists($leak, $detail[0]), false, "voucher detail withholds {$leak}");
}
is_(($reader)('mt_admin_voucher', ['00000000-0000-4000-8000-000000000000']), [],
    'an unknown id returns nothing rather than erroring');
is_(($reader)('mt_admin_voucher', ['not-a-uuid']), [], 'and a non-uuid is refused before the query');

t('14. WITH DenyAllIdentity BOUND, no projection is executed at all');
$denied = AdminRoutes::build(new DenyAllIdentity(), Bindings::defaults(),
    static fn(string $fn, array $a = []): array => throw new \RuntimeException('reader was called'));
foreach (['/api/v1/admin/customers','/api/v1/admin/routers','/api/v1/admin/audit'] as $p) {
    is_(hitr($denied, 'GET', $p, $req)->status, 401, "401 before any read: {$p}");
}

exit(t_summary());
