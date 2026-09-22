<?php
/**
 * F6-A P3 — the staff surface and its authorization boundary.
 *
 * The point of these assertions is that the admin API is SAFE while it is
 * unfinished. Deny-all must be real, every route must be capability-gated, the
 * serializer must not leak, and "Reseller" must not have crept back in as a
 * tenant-like role.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use Dn\Admin\AdminIdentityPort;
use Dn\Admin\Capability;
use Dn\Admin\DenyAllIdentity;
use Dn\Admin\StaffIdentity;
use Dn\Admin\StaffRole;
use Dn\Api\AdminRoutes;
use Dn\Http\Request;
use Dn\Http\Serializer\AdminProjection;
use Dn\Runtime\Bindings;

require_once __DIR__ . '/admin_identity_double.php';

$req = new Request('GET', '/api/v1/admin/health');

function hit(\Dn\Http\Router $r, string $method, string $path, Request $req): \Dn\Http\Response {
    $m = $r->match($method, $path);
    if ($m === null) { bad("no route for {$method} {$path}"); return new \Dn\Http\Response(404); }
    [$handler, $params] = $m;
    return $handler($req, ...array_values($params));
}

// ===========================================================================
t('DENY-ALL — the shipped binding authenticates nobody');
$deny = new DenyAllIdentity();
is_($deny->identify($req), null, 'DenyAllIdentity identifies no one');
is_($deny->providerName(), 'deny-all', 'and names itself at /health');

$routes = AdminRoutes::build($deny, Bindings::defaults());
foreach ([['GET','/api/v1/admin/health'], ['GET','/api/v1/admin/customers'],
          ['GET','/api/v1/admin/routers'], ['POST','/api/v1/admin/routers'],
          ['GET','/api/v1/admin/audit']] as [$m, $p]) {
    is_(hit($routes, $m, $p, $req)->status, 401, "401 for {$m} {$p} under deny-all");
}

// ===========================================================================
t('CAPABILITY — an authenticated staff member without the capability gets 403');
$support = new FixedStaff(new StaffIdentity('s-1', StaffRole::Support, 'test'));
$sr = AdminRoutes::build($support, Bindings::defaults());
is_(hit($sr, 'GET', '/api/v1/admin/health', $req)->status, 200, 'Support may read health');
is_(hit($sr, 'POST', '/api/v1/admin/routers', $req)->status, 403,
    'Support may NOT register a router');
is_(hit($sr, 'POST', '/api/v1/admin/voucher-batches', $req)->status, 403,
    'Support may NOT generate vouchers');
is_(hit($sr, 'GET', '/api/v1/admin/audit', $req)->status, 403,
    'Support may NOT read the audit log');
// 403 and not 404 — see the note in AdminRoutes: staff already know the estate
// exists, so hiding a permission boundary would only disguise it as a bug.
is_(hit($sr, 'POST', '/api/v1/admin/routers', $req)->body['capability'],
    Capability::ROUTERS_REGISTER, 'and the refusal names the capability required');

t('CAPABILITY — Sales is commercial and touches no router');
$sales = AdminRoutes::build(new FixedStaff(new StaffIdentity('s-2', StaffRole::Sales, 'test')),
                            Bindings::defaults());
is_(hit($sales, 'POST', '/api/v1/admin/voucher-batches', $req)->status, 501,
    'Sales reaches the voucher-generation route (501 = not yet wired, not 403)');
is_(hit($sales, 'POST', '/api/v1/admin/routers/{device_id}/actions', $req)->status, 403,
    'Sales may NOT queue a router action');
is_(hit($sales, 'POST', '/api/v1/admin/routers', $req)->status, 403,
    'Sales may NOT register a router');

t('CAPABILITY — NOC operates routers but is not commercial');
$noc = AdminRoutes::build(new FixedStaff(new StaffIdentity('s-3', StaffRole::Noc, 'test')),
                          Bindings::defaults());
is_(hit($noc, 'POST', '/api/v1/admin/routers', $req)->status, 501, 'NOC may register a router');
is_(hit($noc, 'POST', '/api/v1/admin/sessions/{session_id}/disconnect', $req)->status, 501,
    'NOC may queue a disconnect');
is_(hit($noc, 'POST', '/api/v1/admin/plans', $req)->status, 403, 'NOC may NOT write plans');
is_(hit($noc, 'POST', '/api/v1/admin/voucher-batches', $req)->status, 403,
    'NOC may NOT generate vouchers');

t('CAPABILITY — Admin holds every declared capability');
$admin = new StaffIdentity('s-0', StaffRole::Admin, 'test');
foreach (AdminRoutes::declaredCapabilities() as $cap) {
    is_($admin->can($cap), true, "Admin can {$cap}");
}

// ===========================================================================
t('ESTATE READ — blocked honestly, not answered with an empty list');
$ar = AdminRoutes::build(new FixedStaff($admin), Bindings::defaults());
$res = hit($ar, 'GET', '/api/v1/admin/customers', $req);
is_($res->status, 501, 'an estate read returns 501, not 200');
is_($res->body['error'], 'estate_access_not_authorized', 'and names why');
// An empty 200 would render as "no customers exist" — a plausible-looking
// wrong answer, which is the failure mode this project keeps finding.
is_(isset($res->body['detail']), true, 'with the reason spelled out for the operator');

// ===========================================================================
t('RESELLER — is not a role, and cannot be constructed');
$cases = array_map(static fn(StaffRole $c): string => $c->value, StaffRole::cases());
is_(in_array('reseller', $cases, true), false, 'StaffRole has no reseller case');
is_(StaffRole::tryFrom('reseller'), null, 'and it cannot be built from a string');
is_(count($cases), 4, 'exactly four staff roles');
is_($cases, ['admin', 'noc', 'sales', 'support'], 'admin, noc, sales, support');

// ===========================================================================
t('SEPARATION — the admin surface cannot reach the customer authenticator');
$body = strip_php_comments(file_get_contents(__DIR__ . '/../src/Api/AdminRoutes.php'));
foreach (['Authenticator', 'mt_auth_', 'TenantContext'] as $n) {
    is_(str_contains($body, $n), false, "AdminRoutes does not reference {$n}");
}
$admDir = glob(__DIR__ . '/../src/Admin/*.php');
is_(count($admDir) >= 5, true, 'the Admin boundary exists as its own namespace');
foreach ($admDir as $f) {
    $b = strip_php_comments(file_get_contents($f));
    is_(str_contains($b, 'mt_principals'), false, basename($f) . ' does not touch mt_principals');
}

t('SEPARATION — no admin route accepts a customer id as authority on the customer surface');
// The customer rule is unchanged: /api/v1/me/* never takes a customer id. The
// admin surface legitimately addresses a customer by id, so the guard is that
// the two prefixes stay disjoint.
foreach (AdminRoutes::build($deny, Bindings::defaults())->patterns() as $p) {
    is_(str_starts_with($p, '/api/v1/admin/'), true, "{$p} is under the admin prefix");
}

// ===========================================================================
t('ADMIN SERIALIZER — an allowlist, and it withholds secrets from staff too');
$row = ['id' => 'r1', 'customer_id' => 'c1', 'serial' => 'SER', 'model' => 'hAP',
        'tunnel_ip' => '10.66.0.9', 'state' => 'active',
        'secret_sealed' => 'SHOULD-NEVER-APPEAR', 'wg_privkey' => 'NOR-THIS'];
$out = AdminProjection::router($row);
is_(isset($out['secret_sealed']), false, 'the sealed credential never serializes');
is_(isset($out['wg_privkey']), false, 'nor any private key');
is_($out['tunnel_ip'], '10.66.0.9', 'but staff do see operational fields');
is_(isset($out['state']), true, 'including device state, which the customer view withholds');

$v = AdminProjection::voucher(['id' => 'v1', 'code' => 'SECRET-CODE', 'state' => 'unused']);
is_(isset($v['code']), false, 'voucher CODES do not appear on admin list endpoints');
is_($v['state'], 'unused', 'but the state does');

foreach (['secret', 'password', 'token', 'hash', 'sealed', 'privkey'] as $forbidden) {
    $hits = array_filter(AdminProjection::allFields(),
        static fn(string $f): bool => str_contains($f, $forbidden));
    is_($hits, [], "no admin field name contains '{$forbidden}'");
}

// ===========================================================================
t('HEALTH — reports the binding, so a screen cannot imply a router answered');
$h = hit($ar, 'GET', '/api/v1/admin/health', $req);
is_($h->status, 200, 'health is readable by an authorized staff member');
is_($h->body['phase'], 'F6-A', 'the process reports F6-A');
is_($h->body['bindings']['publisher_simulated'], true, 'the publisher is flagged simulated');
is_($h->body['bindings']['real_bindings_allowed'], false, 'real bindings are not allowed');
is_($h->body['identity']['provider'], 'test-double', 'and the identity provider is named');

exit(t_summary());
