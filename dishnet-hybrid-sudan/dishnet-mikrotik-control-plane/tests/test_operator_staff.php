<?php
/**
 * T-2 — the Operator Owner / Operator Staff capability model.
 * Migration 027, docs/116 (§J records the build decisions).
 *
 * Proved here, in the order the instruction listed it:
 *   1  an owner holds every capability       2  staff holds exactly its list
 *   3  staff can never receive op.staff.manage
 *   4  the last active owner cannot be disabled   5  … nor demoted
 *   6  staff cannot manage staff even with the HTTP guard removed
 *   7  dnb_app cannot mutate mt_principals directly
 *   8  a cross-tenant principal mutation fails
 *   9  kind and capabilities are re-read live on an existing session
 *  10  disabling revokes every live session in the same transaction
 *  11  DishNet Staff create a principal for an EXPLICIT target operator
 *  12  that target cannot be substituted by mt_current_customer()
 *  13  the six commercial functions enforce the capability floor
 *  14  a refused act writes no audit row
 *  15  actor_kind semantics: 'staff' is DishNet staff, every operator person
 *      is 'principal', and nothing maps kind onto actor_kind
 *  16  the Admin projection withholds phone, email and every credential
 *
 * Every negative is paired with a positive control in the same section, and
 * every "no audit row" is a delta on the WHOLE table, not on one action. The
 * BYPASSRLS inspector reads state across tenants; it is never the subject of
 * a proof — each proof runs as the role it is about.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/admin_identity_double.php';

use Dn\Admin\AdminReader;
use Dn\Admin\StaffIdentity;
use Dn\Admin\StaffRole;
use Dn\Api\AdminRoutes;
use Dn\Api\Routes;
use Dn\Auth\Authenticator;
use Dn\Auth\OpCapability as C;
use Dn\Db\Database;
use Dn\Http\Kernel;
use Dn\Http\Request;
use Dn\Http\Response;
use Dn\Http\Router;
use Dn\Runtime\Bindings;
use Dn\Tenancy\TenantContext;

$ins = Database::inspector();          // BYPASSRLS fixture identity: reads state, proves nothing
$app = Database::app();
$ctx = new TenantContext($app);
$aw  = Database::adminWrite();
$ids = seed_two_customers($ins);
$A = $ids['A']; $B = $ids['B'];
$ABSENT = '00000000-0000-4000-8000-000000000000';

$auth = new Authenticator($app);
$k    = new Kernel(Routes::build($auth), $app, $auth, $ctx);
$call = fn(string $m, string $p, array $body = [], string $tok = '', array $headers = []): Response =>
    $k->handle(new Request($m, $p, ($tok !== '' ? ['Authorization' => "Bearer {$tok}"] : []) + $headers,
                           $body, [], '10.0.0.7'));

/** A session for a principal, minted the way Authenticator does (same keyed hash), no OTP round trip. */
$mint = function (string $principal, string $customer) use ($app): string {
    $tok = bin2hex(random_bytes(16));
    $h = hash_hmac('sha256', $tok, getenv('DNB_TOKEN_PEPPER') ?: 'dev-pepper-not-for-production');
    $app->one('SELECT mt_auth_create_session(?,?,?,?::interval) AS id', [$principal, $customer, $h, 'PT2H']);
    return $tok;
};
$audit     = fn(): int => (int) $ins->one('SELECT count(*)::int AS c FROM mt_audit_log')['c'];
$lastAudit = fn(string $action): ?array => $ins->one(
    'SELECT * FROM mt_audit_log WHERE action = ? ORDER BY at DESC, id DESC LIMIT 1', [$action]);
$row    = fn(string $id): ?array => $ins->one('SELECT * FROM mt_principals WHERE id = ?', [$id]);
$caps   = fn(string $id): array => C::fromPg($row($id)['capabilities']);
$sorted = function (array $a): array { sort($a); return $a; };
/** One statement as dnb_app inside a tenant context — the role every operator-plane proof is about. */
$as = fn(string $customer, string $sql, array $args = []): ?array =>
    $ctx->run($customer, fn(Database $db) => $db->one($sql, $args));
/** Assert a PDO failure with an exact SQLSTATE and a fragment of its message. */
$refused = function (callable $fn, string $state, string $needle, string $msg): void {
    try { $fn(); }
    catch (\PDOException $e) {
        $got = (string) ($e->errorInfo[0] ?? $e->getCode());
        if ($got === $state && str_contains($e->getMessage(), $needle)) { ok("{$msg}  [{$state}: {$needle}]"); return; }
        bad("{$msg} — expected {$state} '{$needle}', got {$got}: " . strtok($e->getMessage(), "\n")); return;
    }
    catch (\Throwable $e) { bad("{$msg} — unexpected " . $e::class . ': ' . $e->getMessage()); return; }
    bad("{$msg} — did not fail at all");
};

// ===========================================================================
t('1. THE CANON — one list in SQL, mirrored in PHP; kind is owner|staff; the CHECKs exist');
$sqlList = C::fromPg($ins->one('SELECT mt_op_capabilities()::text AS l')['l']);
is_($sqlList, C::ALL, 'OpCapability::ALL equals mt_op_capabilities(), element for element, in order');
is_(count($sqlList), 17, 'seventeen capabilities (docs/116 §A)');
$kindCheck = $ins->one("SELECT pg_get_constraintdef(oid) AS d FROM pg_constraint
                         WHERE conname = 'mt_principals_kind_check'")['d'];
is_(str_contains($kindCheck, "'owner'") && str_contains($kindCheck, "'staff'"), true, 'the kind CHECK names owner and staff');
is_(str_contains($kindCheck, "'operator'"), false, 'and no longer names operator — after T-1 the operator is the tenant');
foreach (['mt_principals_capabilities_known', 'mt_principals_kind_capabilities'] as $c) {
    is_((int) $ins->one('SELECT count(*)::int AS n FROM pg_constraint WHERE conname = ?', [$c])['n'], 1, "constraint {$c} exists");
}
is_((int) $ins->one("SELECT count(*)::int AS n FROM mt_principals WHERE kind = 'operator'")['n'], 0,
    'no principal carries the retired value');
// Both CHECKs refuse the retired value (the kind list, and the kind/capabilities
// pairing, which has no branch for it); PostgreSQL names whichever it evaluates
// first. The refusal is what is asserted; the kind CHECK's text was read above.
$refused(fn() => $ins->exec("INSERT INTO mt_principals (customer_id, kind, display_name) VALUES (?, 'operator', 'legacy')", [$A['customer']]),
    '23514', 'violates check constraint', 'CONTROL: an operator row cannot be created any more, even bypassing RLS');
$m027 = file_get_contents(__DIR__ . '/../migrations/027_operator_staff_capabilities.sql');
$pRole = strpos($m027, 'SET LOCAL ROLE dnb_def_auth');
$pRewrite = strpos($m027, "SET kind = 'staff'");
$pCheck = strpos($m027, "CHECK (kind IN ('owner','staff'))");
is_($pRole !== false && $pRewrite !== false && $pCheck !== false && $pRole < $pRewrite && $pRewrite < $pCheck, true,
    'the migration counts and rewrites AS dnb_def_auth (which sees every row) BEFORE tightening the CHECK — J-4');
is_(str_contains($m027, '027 census') && str_contains($m027, 'rewrote % principal row(s)'), true,
    'and reports the census it measured (RAISE NOTICE per kind and status, then the rewritten count) rather than a number it assumed');

t('1b. EXECUTE on every 027 function — exactly who, enumerated from pg_roles, owner included implicitly');
$execRoles = fn(string $sig): array => $sorted(array_column($ins->query(
    "SELECT rolname FROM pg_roles WHERE rolname LIKE 'dnb%' AND has_function_privilege(rolname, ?, 'EXECUTE')",
    [$sig]), 'rolname'));
$ownerRole = $ins->one("SELECT pg_get_userbyid(proowner) AS o FROM pg_proc WHERE proname = 'mt_op_capabilities'")['o'];
foreach ([
    'mt_principal_create(text,text,text,text[],uuid,text)'       => ['dnb_app', 'dnb_def_comm'],
    'mt_principal_set_capabilities(uuid,text[],uuid,text)'      => ['dnb_app', 'dnb_def_comm'],
    'mt_principal_set_kind(uuid,text,uuid,text)'                => ['dnb_app', 'dnb_def_comm'],
    'mt_principal_disable(uuid,uuid,text)'                      => ['dnb_app', 'dnb_def_comm'],
    'mt_principal_can(uuid,text)'                               => ['dnb_app', 'dnb_def_comm'],
    'mt_principal_require(uuid,text)'                           => ['dnb_def_comm'],
    'mt_admin_principal_create(uuid,text,text,text,text[],text)' => ['dnb_adminwrite', 'dnb_def_prov'],
    'mt_admin_principals()'                                     => ['dnb_adminapi', 'dnb_def_admin'],
    'mt_auth_revoke_principal_sessions(uuid)'                   => ['dnb_def_auth', 'dnb_def_comm'],
    'mt_op_capabilities()'                                      => [$ownerRole, 'dnb_def_auth', 'dnb_def_comm', 'dnb_def_prov'],
] as $sig => $who) {
    is_($execRoles($sig), $sorted($who), "EXECUTE on {$sig}: exactly " . implode(', ', $who));
}
is_(count($ins->query("SELECT 1 FROM pg_roles WHERE rolname LIKE 'dnb%' AND rolcanlogin")) >= 7, true,
    'CONTROL: the enumeration covers the seven login roles (and any stray one)');
is_((int) $ins->one("SELECT count(*)::int AS n FROM pg_proc p, aclexplode(p.proacl) a
                      WHERE p.proname LIKE 'mt_%' AND a.grantee = 0")['n'], 0,
    'PUBLIC holds EXECUTE on no mt_ function, mt_op_capabilities() included');

// ===========================================================================
t('2. THE FLOOR — dnb_app can no longer write mt_principals; the writer role can, and only inside its tenant');
$priv = fn(string $role, string $p): bool => (bool) $ins->one(
    "SELECT has_table_privilege(?, 'mt_principals', ?) AS p", [$role, $p])['p'];
foreach (['INSERT', 'UPDATE', 'DELETE'] as $p) { is_($priv('dnb_app', $p), false, "dnb_app holds no {$p} on mt_principals (grant)"); }
is_($priv('dnb_app', 'SELECT'), true, 'and keeps SELECT — /me and /me/staff are RLS-scoped reads');
foreach (['dnb_adminwrite', 'dnb_adminapi', 'dnb_staffauth', 'dnb_radius'] as $r) {
    foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $p) { is_($priv($r, $p), false, "{$r} holds no {$p} on mt_principals"); }
}
is_([$priv('dnb_def_comm', 'INSERT'), $priv('dnb_def_comm', 'UPDATE'), $priv('dnb_def_comm', 'DELETE')], [true, true, false],
    'dnb_def_comm: INSERT and UPDATE, never DELETE — disable, never delete (P-C)');
is_([$priv('dnb_def_prov', 'INSERT'), $priv('dnb_def_prov', 'SELECT')], [true, false],
    'dnb_def_prov: INSERT only — the Admin creator never reads a principal back (J-3)');
foreach (['dnb_admin', 'dnb_worker'] as $r) {
    is_($priv($r, 'INSERT'), true,
        "F-3 (OPEN, docs/112 A-1): {$r} still holds migration 015's blanket INSERT here — no production route connects as it, and it is not 027's to revoke");
}
is_((int) $ins->one("SELECT count(*)::int AS n FROM information_schema.role_table_grants WHERE grantee = 'dnb_adminwrite'")['n'], 0,
    'W-3 intact: dnb_adminwrite holds zero table privileges of any kind');

foreach ([
    ['UPDATE mt_principals SET kind = ? WHERE id = ?', ['owner', $A['principal']], 'promote by UPDATE'],
    ["INSERT INTO mt_principals (customer_id, kind, display_name) VALUES (?, 'owner', 'forged')", [$A['customer']], 'forge an owner by INSERT'],
    ['DELETE FROM mt_principals WHERE id = ?', [$A['principal']], 'DELETE'],
    ["UPDATE mt_principals SET capabilities = '{op.staff.manage}' WHERE id = ?", [$A['principal']], 'grant a capability by UPDATE'],
] as [$sql, $args, $what]) {
    $refused(fn() => $ctx->run($A['customer'], fn(Database $db) => $db->exec($sql, $args)),
        '42501', 'permission denied for table mt_principals', "as dnb_app in its OWN tenant, {$what} is refused by privilege");
}
is_((int) $ctx->run($A['customer'], fn(Database $db) => $db->one('SELECT count(*)::int AS n FROM mt_principals'))['n'] >= 1, true,
    'CONTROL: the same role and context still reads its principals');
$asComm = function (string $ctxCustomer, string $rowCustomer) use ($ins): void {
    $ins->exec('BEGIN');
    try {
        $ins->exec('SET LOCAL ROLE dnb_def_comm');
        $ins->exec("SELECT set_config('app.customer_id', ?, true)", [$ctxCustomer]);
        $ins->exec("INSERT INTO mt_principals (customer_id, kind, display_name) VALUES (?, 'staff', 'control')", [$rowCustomer]);
    } finally { $ins->exec('ROLLBACK'); }
};
$asComm($A['customer'], $A['customer']);
ok('CONTROL: dnb_def_comm inserts under its own tenant (rolled back)');
$refused(fn() => $asComm($A['customer'], $B['customer']), '42501', 'row-level security policy',
    'dnb_def_comm naming another tenant is refused by the POLICY — the writers are tenant-bound BELOW the function');
$rls = $ins->one("SELECT relrowsecurity AS r, relforcerowsecurity AS f FROM pg_class WHERE relname = 'mt_principals'");
is_([$rls['r'], $rls['f']], [true, true], 'mt_principals still has FORCE ROW LEVEL SECURITY');
is_((int) $ins->one("SELECT count(*)::int AS n FROM pg_policy WHERE 'dnb_def_comm'::regrole = ANY (polroles)")['n'], 0,
    'dnb_def_comm holds NO policy of its own on any table — it inherits the isolation policy exactly as dnb_app does');
$pols = array_column($ins->query("SELECT polname FROM pg_policy WHERE polrelid = 'mt_principals'::regclass ORDER BY 1"), 'polname');
is_(in_array('dnb_def_prov_mt_principals_insert', $pols, true), true, "the Admin creator's INSERT policy exists");
is_(in_array('dnb_def_admin_mt_principals_select', $pols, true), true, "the projection's SELECT policy exists");

t('2b. B-3 — still OPEN, and asserted so: 027 closed mt_principals ONLY');
$writes = array_column($ins->query(
    "SELECT DISTINCT table_name AS t FROM information_schema.role_table_grants
      WHERE grantee = 'dnb_app' AND table_schema = 'public'
        AND privilege_type IN ('INSERT','UPDATE','DELETE') ORDER BY 1"), 't');
is_($writes, ['mt_audit_log', 'mt_auth_sessions', 'mt_customers', 'mt_device_config', 'mt_device_secrets',
              'mt_devices', 'mt_entitlements', 'mt_idempotency', 'mt_migrations', 'mt_services',
              'mt_sessions', 'mt_sites', 'mt_uplink_samples'],
    'the thirteen tables B-3 inventories keep their dnb_app write grants (docs/116 §0, §F.3) — its own task, after this one');
is_(in_array('mt_principals', $writes, true), false, 'and mt_principals is no longer among them');

// ===========================================================================
t('3. OWNER IMPLIES ALL; STAFF HOLDS EXACTLY ITS LIST; op.staff.manage IS NOT GRANTABLE');
$create = fn(string $kind, string $name, ?string $phone, array $list, string $actor): string => $as($A['customer'],
    'SELECT mt_principal_create(?,?,?,?::text[],?,?) AS id', [$kind, $name, $phone, C::toPg($list), $actor, '10.0.0.7'])['id'];
$before = $audit();
$mandy = $create('staff', 'Mandy Manager', '+256700009001', C::PRESETS['manager'], $A['principal']);
$sam   = $create('staff', 'Sam Seller',    '+256700009002', C::PRESETS['seller'],  $A['principal']);
$vic   = $create('staff', 'Vic Viewer',    '+256700009003', C::PRESETS['viewer'],  $A['principal']);
$nell  = $create('staff', 'Nell Nothing',  null,            [],                    $A['principal']);
is_($audit() - $before, 4, 'four acts, four audit rows');
foreach ([['manager', $mandy], ['seller', $sam], ['viewer', $vic]] as [$preset, $id]) {
    is_($sorted($caps($id)), $sorted(C::PRESETS[$preset]), "{$preset}: the row stores exactly the preset's list");
    is_($row($id)['kind'], 'staff', "{$preset} is staff");
}
is_($caps($nell), [], 'a staff member may hold nothing at all');
is_($caps($A['principal']), [], "the owner's list is empty — owner implies all by kind, not by list");
$can = fn(string $p, string $c): bool => (bool) $as($A['customer'], 'SELECT mt_principal_can(?,?) AS ok', [$p, $c])['ok'];
$all = true; foreach (C::ALL as $c) { $all = $all && $can($A['principal'], $c); }
is_($all, true, 'mt_principal_can(owner, x) is true for every one of the seventeen');
$exact = true; foreach (C::ALL as $c) { $exact = $exact && ($can($mandy, $c) === in_array($c, C::PRESETS['manager'], true)); }
is_($exact, true, 'mt_principal_can(manager, x) is true exactly for the list, false for the rest');
is_($can($sam, C::PLANS_WRITE), false, 'a seller cannot write plans');
is_($can($sam, C::VOUCHERS_ISSUE), true, 'CONTROL: a seller can issue vouchers');
is_($can($nell, C::PROFILE_READ), false, 'empty staff can do nothing');
is_($can($mandy, C::STAFF_MANAGE), false, 'no staff member ever holds op.staff.manage');
is_($can($A['principal'], 'op.root'), false, 'an unknown capability is false even for an owner');
is_($can($B['principal'], C::PROFILE_READ), false, "another tenant's principal is false under A's context — RLS, not a lookup");
is_(C::allows(['kind' => 'owner', 'capabilities' => []], C::STAFF_MANAGE), true, 'the PHP mirror agrees: owner implies all');
is_(C::allows(['kind' => 'staff', 'capabilities' => C::PRESETS['seller']], C::PLANS_WRITE), false, 'and staff by list');
is_(C::allows(['kind' => 'owner', 'capabilities' => []], 'op.root'), false, 'and an unknown name is false');
foreach (C::PRESETS as $name => $list) {
    is_(C::known($list) && !in_array(C::STAFF_MANAGE, $list, true), true, "preset {$name} is a known list without op.staff.manage");
}
$before = $audit();
$refused(fn() => $create('staff', 'Sneaky', null, [C::STAFF_MANAGE], $A['principal']),
    '23514', 'mt_principals_kind_capabilities', 'a staff member cannot be created holding op.staff.manage (CHECK)');
$refused(fn() => $create('owner', 'Odd Owner', null, [C::PLANS_READ], $A['principal']),
    '23514', 'an owner holds every capability', 'an owner cannot be created with a list');
$refused(fn() => $create('staff', 'Rooty', null, ['op.root'], $A['principal']),
    '23514', 'mt_principals_capabilities_known', 'an unknown capability is a constraint violation, not a silent no-op');
$refused(fn() => $create('operator', 'Legacy', null, [], $A['principal']),
    '23514', 'kind must be owner or staff', 'the retired kind is refused by the function');
$refused(fn() => $as($A['customer'], 'SELECT mt_principal_set_capabilities(?,?::text[],?,NULL) AS r', [$sam, C::toPg([C::STAFF_MANAGE]), $A['principal']]),
    '23514', 'mt_principals_kind_capabilities', 'nor can op.staff.manage be granted later');
$refused(fn() => $as($A['customer'], 'SELECT mt_principal_set_capabilities(?,?::text[],?,NULL) AS r', [$A['principal'], C::toPg([C::PLANS_READ]), $A['principal']]),
    '23514', 'an owner holds every capability', "an owner's list cannot be set");
$refused(fn() => $create('staff', 'Dup Phone', '+256700001002', [], $A['principal']),
    '23505', 'duplicate key', "a phone in use anywhere — here B's owner's — is a REFUSAL, never an upsert (P-B)");
is_($audit() - $before, 0, 'seven refusals, zero audit rows');
$refused(fn() => $app->one('SELECT mt_principal_create(?,?,?,?::text[],?,NULL) AS id', ['staff', 'No Ctx', null, '{}', $A['principal']]),
    '23514', 'no tenant context', 'outside a tenant context the writer refuses — there is no customer parameter to supply instead');

// ===========================================================================
t('4. STAFF CANNOT MANAGE STAFF — with no HTTP guard in the way, the function is the floor');
$before = $audit();
foreach ([
    ['SELECT mt_principal_create(?,?,?,?::text[],?,NULL) AS r', ['staff', 'Sneak', null, '{}', $mandy], 'create a colleague'],
    ['SELECT mt_principal_set_capabilities(?,?::text[],?,NULL) AS r', [$sam, C::toPg([C::PLANS_READ]), $mandy], 'set a colleague\'s capabilities'],
    ['SELECT mt_principal_set_kind(?,?,?,NULL) AS r', [$mandy, 'owner', $mandy], 'promote ITSELF to owner'],
    ['SELECT mt_principal_set_kind(?,?,?,NULL) AS r', [$sam, 'owner', $mandy], 'promote a colleague'],
    ['SELECT mt_principal_disable(?,?,NULL) AS r', [$A['principal'], $mandy], 'disable the owner'],
] as [$sql, $args, $what]) {
    $refused(fn() => $as($A['customer'], $sql, $args), '42501', 'capability required: op.staff.manage',
        "a manager calling the function directly to {$what} is refused inside it");
}
is_($audit() - $before, 0, 'five refusals, zero audit rows');
is_($row($mandy)['kind'], 'staff', 'Mandy is still staff');
is_($row($A['principal'])['status'], 'active', 'the owner is still active');

// ===========================================================================
t('5. THE MATRIX — every /me route × owner, manager, seller, viewer, empty staff (docs/116 §A)');
$tok = [
    'owner'   => $mint($A['principal'], $A['customer']),
    'manager' => $mint($mandy, $A['customer']),
    'seller'  => $mint($sam, $A['customer']),
    'viewer'  => $mint($vic, $A['customer']),
    'empty'   => $mint($nell, $A['customer']),
];
$whoOf = [
    'owner'   => ['kind' => 'owner', 'capabilities' => []],
    'manager' => ['kind' => 'staff', 'capabilities' => C::PRESETS['manager']],
    'seller'  => ['kind' => 'staff', 'capabilities' => C::PRESETS['seller']],
    'viewer'  => ['kind' => 'staff', 'capabilities' => C::PRESETS['viewer']],
    'empty'   => ['kind' => 'staff', 'capabilities' => []],
];
$matrix = [
    ['GET',   '/api/v1/me',                                   C::PROFILE_READ,        200],
    ['GET',   '/api/v1/me/services',                          C::LOCATIONS_READ,      200],
    ['GET',   '/api/v1/me/sites',                             C::LOCATIONS_READ,      200],
    ['GET',   "/api/v1/me/sites/{$ABSENT}",                   C::LOCATIONS_READ,      404],
    ['GET',   '/api/v1/me/entitlements',                      C::BILLING_READ,        200],
    ['GET',   '/api/v1/me/plans',                             C::PLANS_READ,          200],
    ['POST',  '/api/v1/me/plans',                             C::PLANS_WRITE,         null],
    ['PATCH', "/api/v1/me/plans/{$ABSENT}",                   C::PLANS_WRITE,         null],
    ['POST',  "/api/v1/me/plans/{$ABSENT}/retire",            C::PLANS_WRITE,         404],
    ['GET',   '/api/v1/me/vouchers',                          C::VOUCHERS_READ,       200],
    ['POST',  '/api/v1/me/vouchers',                          C::VOUCHERS_ISSUE,      null],
    ['POST',  "/api/v1/me/vouchers/{$ABSENT}/revoke",         C::VOUCHERS_REVOKE,     404],
    ['GET',   '/api/v1/me/sessions',                          C::SESSIONS_READ,       200],
    ['POST',  "/api/v1/me/sessions/{$ABSENT}/disconnect",     C::SESSIONS_DISCONNECT, 404],
    ['GET',   '/api/v1/me/usage',                             C::REPORTS_READ,        200],
    ['GET',   '/api/v1/me/uplink',                            C::REPORTS_READ,        200],
    ['GET',   '/api/v1/me/intents',                           C::INTENTS_READ,        200],
    ['GET',   '/api/v1/me/staff',                             C::STAFF_MANAGE,        200],
    ['POST',  '/api/v1/me/staff',                             C::STAFF_MANAGE,        400],
    ['POST',  "/api/v1/me/staff/{$ABSENT}/capabilities",      C::STAFF_MANAGE,        404],
    ['POST',  "/api/v1/me/staff/{$ABSENT}/kind",              C::STAFF_MANAGE,        400],
    ['POST',  "/api/v1/me/staff/{$ABSENT}/disable",           C::STAFF_MANAGE,        404],
];
foreach ($whoOf as $name => $who) {
    $denied = 0;
    foreach ($matrix as [$m, $p, $cap, $expected]) {
        $res = $call($m, $p, [], $tok[$name]);
        if (C::allows($who, $cap)) {
            if ($expected !== null) { is_($res->status, $expected, "{$name}: {$m} {$p} → {$expected}"); }
            else { is_($res->status !== 403, true, "{$name}: {$m} {$p} is not refused (got {$res->status})"); }
        } else {
            is_([$res->status, $res->body['capability'] ?? null], [403, $cap], "{$name}: {$m} {$p} → 403 naming {$cap}");
            $denied++;
        }
    }
    ok("{$name}: {$denied} of " . count($matrix) . ' routes refused');
}
is_(C::allows($whoOf['owner'], C::STAFF_MANAGE) && !C::allows($whoOf['empty'], C::PROFILE_READ), true,
    'CONTROL: the matrix spans both extremes — the owner is refused nowhere, empty staff everywhere');

t('5b. 403 vs 404 — the record rule is untouched (J-13)');
$r1 = $call('GET', "/api/v1/me/sites/{$B['site']}", [], $tok['owner']);
$r2 = $call('GET', "/api/v1/me/sites/{$ABSENT}", [], $tok['owner']);
is_([$r1->status, $r1->body], [404, ['error' => 'not_found']], "the owner asking for B's site: 404, never 403");
is_([$r2->status, $r2->body], [$r1->status, $r1->body], 'and an absent id is indistinguishable from it');
$r3 = $call('GET', "/api/v1/me/sites/{$B['site']}", [], $tok['empty']);
is_([$r3->status, $r3->body['capability']], [403, C::LOCATIONS_READ], 'empty staff asking for the same: 403 — about its OWN role, not about the record');
is_($call('POST', '/api/v1/auth/logout', [], $tok['empty'])->status, 204, 'logout needs no capability at all');
is_($call('GET', '/api/v1/me', [], $tok['empty'])->status, 401, 'and the token is gone afterwards');
$tok['empty'] = $mint($nell, $A['customer']);

// ===========================================================================
t('6. /me/staff — the listing withholds the phone; creation validates; a duplicate phone answers neutrally');
$list = $call('GET', '/api/v1/me/staff', [], $tok['owner']);
is_($list->status, 200, 'the owner lists its people');
$rows = $list->body['staff'];
is_(count($rows), 5, 'A has five people');
is_(array_keys($rows[0]), ['id', 'kind', 'display_name', 'status', 'capabilities', 'phone_masked'], 'the listing shape');
$json = json_encode($list->body);
is_(str_contains($json, '700009001') || str_contains($json, '700001001'), false, 'no full phone number appears in the listing');
$mrow = current(array_filter($rows, fn($r) => $r['id'] === $mandy));
is_(str_ends_with((string) $mrow['phone_masked'], '001'), true, 'a phone is masked to its last three digits');
is_(str_contains((string) $mrow['phone_masked'], '9001'), false, 'and not one digit more');
$me = $call('GET', '/api/v1/me', [], $tok['manager']);
is_(array_keys($me->body['principal']), ['id', 'kind', 'display_name', 'status', 'capabilities'], '/me principal shape: status and capabilities added, nothing else');
is_($sorted($me->body['principal']['capabilities']), $sorted(C::PRESETS['manager']), '/me shows the live list');
foreach ([
    [['kind' => 'root', 'display_name' => 'x'],                                          400, 'an unknown kind'],
    [['kind' => 'staff'],                                                                 400, 'a missing display_name'],
    [['kind' => 'staff', 'display_name' => 'x', 'capabilities' => ['op.root']],          400, 'an unknown capability'],
    [['kind' => 'staff', 'display_name' => 'x', 'capabilities' => [C::STAFF_MANAGE]],    400, 'op.staff.manage requested for staff'],
    [['kind' => 'owner', 'display_name' => 'x', 'capabilities' => [C::PLANS_READ]],      400, 'a list for an owner'],
    [['kind' => 'staff', 'display_name' => 'x', 'capabilities' => 'op.plans.read'],      400, 'a non-list'],
    [['kind' => 'staff', 'display_name' => 'Dup', 'phone' => '+256700001002'],           409, "B's owner's phone"],
] as [$body, $status, $what]) {
    is_($call('POST', '/api/v1/me/staff', $body, $tok['owner'])->status, $status, "POST /me/staff with {$what} → {$status}");
}
is_($call('POST', '/api/v1/me/staff', ['kind' => 'staff', 'display_name' => 'Dup', 'phone' => '+256700001002'], $tok['owner'])->body,
    ['error' => 'phone unavailable'], 'the duplicate answer says nothing about whose number it is or where');
$before = $audit();
$r = $call('POST', '/api/v1/me/staff',
    ['kind' => 'staff', 'display_name' => 'Ola Overtime', 'phone' => '+256700009004', 'capabilities' => C::PRESETS['seller']], $tok['owner']);
is_($r->status, 201, 'the owner creates a seller over HTTP');
$ola = $r->body['principal']['id'];
is_([$r->body['principal']['kind'], $sorted($r->body['principal']['capabilities'])], ['staff', $sorted(C::PRESETS['seller'])], 'with the list asked for');
is_($audit() - $before, 1, 'one audit row');
$a = $lastAudit('principal.created');
is_([$a['actor_kind'], $a['actor'], $a['customer_id']], ['principal', $A['principal'], $A['customer']], "audited as the owner PRINCIPAL of A — actor_kind 'principal', never 'staff'");
$d = json_decode($a['detail'], true);
is_([$d['principal_kind'], $d['capability']], ['owner', C::STAFF_MANAGE], 'with the kind and the capability at act time');
$before = $audit();
$r = $call('POST', "/api/v1/me/staff/{$ola}/capabilities", ['capabilities' => C::PRESETS['seller']], $tok['owner']);
is_([$r->status, $r->body['changed']], [200, false], 'setting the same list reports no change');
is_($audit() - $before, 0, 'and writes no audit row');
$r = $call('POST', "/api/v1/me/staff/{$ola}/capabilities", ['capabilities' => [C::PROFILE_READ]], $tok['owner']);
is_([$r->status, $r->body['changed'], $r->body['principal']['capabilities']], [200, true, [C::PROFILE_READ]], 'a different list is a change');
is_($audit() - $before, 1, 'one audit row for it');
$d = json_decode($lastAudit('principal.capabilities_changed')['detail'], true);
is_([$d['principal_kind'], $d['capability']], ['owner', C::STAFF_MANAGE], 'recorded with the acting kind and capability');
foreach ([['GET', '/api/v1/me/staff'], ['POST', '/api/v1/me/staff'], ['POST', "/api/v1/me/staff/{$ola}/disable"]] as [$m, $p]) {
    $r = $call($m, $p, ['kind' => 'staff', 'display_name' => 'x'], $tok['manager']);
    is_([$r->status, $r->body['capability'] ?? null], [403, C::STAFF_MANAGE], "manager: {$m} {$p} → 403");
}

// ===========================================================================
t('7. LIVE RE-READ — a change to kind or capabilities binds the very next request of an existing session');
$plan = ['name' => 'Live plan', 'duration_s' => 3600, 'rate_down_bps' => 2000000, 'rate_up_bps' => 1000000,
         'data_cap_bytes' => null, 'devices_per_voucher' => 1, 'mode' => 'elapsed',
         'price_minor' => 1000, 'currency' => 'UGX', 'site_id' => $A['site']];
is_($call('POST', '/api/v1/me/plans', $plan, $tok['manager'])->status, 201, 'the manager creates a plan (op.plans.write)');
$less = array_values(array_diff(C::PRESETS['manager'], [C::PLANS_WRITE]));
is_($call('POST', "/api/v1/me/staff/{$mandy}/capabilities", ['capabilities' => $less], $tok['owner'])->status, 200, 'the owner removes op.plans.write');
$r = $call('POST', '/api/v1/me/plans', ['name' => 'Live plan 2'] + $plan, $tok['manager']);
is_([$r->status, $r->body['capability'] ?? null], [403, C::PLANS_WRITE], 'the SAME token is refused on its next request');
is_($call('GET', '/api/v1/me/plans', [], $tok['manager'])->status, 200, 'CONTROL: the session itself is intact — reads still work');
is_(in_array(C::PLANS_WRITE, $call('GET', '/api/v1/me', [], $tok['manager'])->body['principal']['capabilities'], true), false, '/me shows the capability gone');
$r = $call('POST', "/api/v1/me/staff/{$mandy}/kind", ['kind' => 'owner'], $tok['owner']);
is_([$r->status, $r->body['principal']['kind']], [200, 'owner'], 'the owner promotes Mandy');
is_($call('GET', '/api/v1/me/staff', [], $tok['manager'])->status, 200, 'as an owner, the same token now manages staff');
is_($caps($mandy), [], 'and the list was cleared — owner implies all (J-7)');
is_($call('POST', "/api/v1/me/staff/{$mandy}/kind", ['kind' => 'staff'], $tok['owner'])->status, 200, 'and demotes Mandy back');
is_($call('GET', '/api/v1/me/staff', [], $tok['manager'])->status, 403, 'the token loses staff management on its next request');
is_($call('GET', '/api/v1/me', [], $tok['manager'])->status, 403, 'a freshly demoted staff member holds nothing until granted (J-7)');
is_($call('POST', "/api/v1/me/staff/{$mandy}/capabilities", ['capabilities' => C::PRESETS['manager']], $tok['owner'])->status, 200, 'the owner restores the manager preset');
is_($call('GET', '/api/v1/me', [], $tok['manager'])->status, 200, 'and the token works again — nothing was revoked, everything was re-read');

// ===========================================================================
t('8. THE LAST ACTIVE OWNER can be neither disabled nor demoted; nobody acts on itself');
$before = $audit();
$r = $call('POST', "/api/v1/me/staff/{$A['principal']}/kind", ['kind' => 'staff'], $tok['owner']);
is_([$r->status, $r->body['error']], [409, 'the last active owner cannot be demoted'], 'A has one owner: demoting it is refused by the invariant');
$r = $call('POST', "/api/v1/me/staff/{$A['principal']}/disable", [], $tok['owner']);
is_([$r->status, $r->body['error']], [409, 'the last active owner cannot be disabled'], 'and so is disabling it');
$refused(fn() => $as($A['customer'], 'SELECT mt_principal_disable(?,?,NULL) AS r', [$A['principal'], $A['principal']]),
    '23514', 'the last active owner cannot be disabled', 'the invariant lives in the function, not in the route');
$refused(fn() => $as($A['customer'], 'SELECT mt_principal_set_kind(?,?,?,NULL) AS r', [$A['principal'], 'staff', $A['principal']]),
    '23514', 'the last active owner cannot be demoted', 'both halves of it');
is_($call('POST', "/api/v1/me/staff/{$mandy}/kind", ['kind' => 'owner'], $tok['owner'])->status, 200, 'a second owner (Mandy) is made');
$r = $call('POST', "/api/v1/me/staff/{$A['principal']}/kind", ['kind' => 'staff'], $tok['owner']);
is_([$r->status, $r->body['error']], [409, 'a principal cannot change its own kind'], 'with two owners, self-demotion is refused by the self-guard (J-6)');
$r = $call('POST', "/api/v1/me/staff/{$A['principal']}/disable", [], $tok['owner']);
is_([$r->status, $r->body['error']], [409, 'a principal cannot disable itself'], 'and so is self-disable');
is_($call('POST', "/api/v1/me/staff/{$A['principal']}/kind", ['kind' => 'staff'], $tok['manager'])->status, 200, 'Mandy, an owner, demotes the original owner — two owners, so allowed');
is_($row($A['principal'])['kind'], 'staff', 'the original owner is staff now');
is_($call('GET', '/api/v1/me', [], $tok['owner'])->status, 403, 'and its live session is refused at once, list cleared');
$r = $call('POST', "/api/v1/me/staff/{$mandy}/disable", [], $tok['manager']);
is_([$r->status, $r->body['error']], [409, 'the last active owner cannot be disabled'], 'Mandy is now the last owner and cannot disable itself');
$r = $call('POST', "/api/v1/me/staff/{$mandy}/kind", ['kind' => 'staff'], $tok['manager']);
is_([$r->status, $r->body['error']], [409, 'the last active owner cannot be demoted'], 'nor demote itself');
is_($call('POST', "/api/v1/me/staff/{$A['principal']}/kind", ['kind' => 'owner'], $tok['manager'])->status, 200, 'Mandy restores the original owner');
is_($call('POST', "/api/v1/me/staff/{$mandy}/kind", ['kind' => 'staff'], $tok['owner'])->status, 200, 'who demotes Mandy back');
is_($call('POST', "/api/v1/me/staff/{$mandy}/capabilities", ['capabilities' => C::PRESETS['manager']], $tok['owner'])->status, 200, 'and restores the manager preset');
is_($audit() - $before, 5, 'five successful acts audited; the eight refusals wrote nothing');

// ===========================================================================
t('9. CROSS-TENANT — another operator\'s owner can neither see nor change A\'s people');
$tokB = $mint($B['principal'], $B['customer']);
$before = $audit();
is_($as($B['customer'], 'SELECT mt_principal_set_capabilities(?,?::text[],?,NULL) AS r', [$mandy, C::toPg([C::PLANS_READ]), $B['principal']])['r'], null,
    "B's owner setting Mandy's capabilities: NULL — nothing to act on under B's context");
is_($as($B['customer'], 'SELECT mt_principal_set_kind(?,?,?,NULL) AS r', [$mandy, 'owner', $B['principal']])['r'], null, 'promoting her: NULL');
is_($as($B['customer'], 'SELECT mt_principal_disable(?,?,NULL) AS r', [$mandy, $B['principal']])['r'], null, 'disabling her: NULL');
is_($as($B['customer'], 'SELECT mt_principal_can(?,?) AS ok', [$mandy, C::PLANS_READ])['ok'], false, "mt_principal_can under B's context: false");
is_($can($mandy, C::PLANS_READ), true, "CONTROL: the same question under A's context: true");
is_([$row($mandy)['kind'], $row($mandy)['status'], $sorted($caps($mandy))], ['staff', 'active', $sorted(C::PRESETS['manager'])], 'Mandy is untouched');
$refused(fn() => $as($A['customer'], 'SELECT mt_principal_create(?,?,?,?::text[],?,NULL) AS r', ['staff', 'X', null, '{}', $B['principal']]),
    '23514', 'actor is not a principal of this customer', "B's owner cannot act as the actor inside A's context either");
foreach (['capabilities' => ['capabilities' => [C::PLANS_READ]], 'kind' => ['kind' => 'owner'], 'disable' => []] as $op => $body) {
    $r = $call('POST', "/api/v1/me/staff/{$mandy}/{$op}", $body, $tokB);
    $r2 = $call('POST', "/api/v1/me/staff/{$ABSENT}/{$op}", $body, $tokB);
    is_([$r->status, $r->body], [404, ['error' => 'not_found']], "over HTTP, B's owner on Mandy's {$op}: 404, never 403");
    is_([$r2->status, $r2->body], [$r->status, $r->body], 'identical to an id that does not exist');
}
is_(count($call('GET', '/api/v1/me/staff', [], $tokB)->body['staff']), 1, "B's listing shows B's one person only");
is_($audit() - $before, 0, 'no audit row anywhere');

// ===========================================================================
t('10. DISABLE revokes every live session — in the same transaction, proved by rolling one back');
$t1 = $mint($mandy, $A['customer']);
$t2 = $mint($mandy, $A['customer']);
is_([$call('GET', '/api/v1/me', [], $t1)->status, $call('GET', '/api/v1/me', [], $t2)->status], [200, 200], 'two live sessions for Mandy');
$open = fn(): int => (int) $ins->one('SELECT count(*)::int AS c FROM mt_auth_sessions WHERE principal_id = ? AND revoked_at IS NULL', [$mandy])['c'];
$openBefore = $open();
is_($openBefore >= 2, true, "at least those two are open ({$openBefore})");
$before = $audit();
$ins->exec('BEGIN');
try {
    $ins->exec('SET LOCAL ROLE dnb_app');
    $ins->exec("SELECT set_config('app.customer_id', ?, true)", [$A['customer']]);
    $r = $ins->one('SELECT mt_principal_disable(?,?,?) AS r', [$mandy, $A['principal'], '10.0.0.9'])['r'];
    is_((bool) $r, true, 'inside the transaction the disable reports done');
    $ins->exec('SET LOCAL ROLE postgres');
    is_((int) $ins->one('SELECT count(*)::int AS c FROM mt_auth_sessions WHERE principal_id = ? AND revoked_at IS NULL', [$mandy])['c'], 0,
        'and inside it every session is already revoked');
} finally { $ins->exec('ROLLBACK'); }
is_($open(), $openBefore, 'ROLLED BACK: every session is open again');
is_($row($mandy)['status'], 'active', 'and Mandy is active again — status and revocation are ONE transaction');
is_($audit() - $before, 0, 'and the audit row went with it');
$r = $call('POST', "/api/v1/me/staff/{$mandy}/disable", [], $tok['owner']);
is_([$r->status, $r->body['disabled'], $r->body['principal']['status']], [200, true, 'disabled'], 'the owner disables Mandy for real');
is_($open(), 0, 'no session of hers is open');
is_([$call('GET', '/api/v1/me', [], $t1)->status, $call('GET', '/api/v1/me', [], $t2)->status], [401, 401], 'both tokens are 401 on their next request');
is_($audit() - $before, 1, 'one audit row');
$d = json_decode($lastAudit('principal.disabled')['detail'], true);
is_([$d['principal_kind'], $d['capability'], $d['sessions_revoked']], ['owner', C::STAFF_MANAGE, $openBefore], 'recording how many sessions the act revoked');
is_($can($mandy, C::PLANS_READ), false, 'mt_principal_can is false for a disabled principal although its list still names the capability');
$refused(fn() => $as($A['customer'], 'SELECT id FROM mt_plan_create(?,3600,2000000,1000000,NULL,1,?,1000,?,?,?,?)',
        ['Ghost plan', 'elapsed', 'UGX', $A['site'], $mandy, '10.0.0.7']),
    '42501', 'capability required: op.plans.write', 'and the floor refuses a disabled actor even on a direct call');
is_($as($A['customer'], 'SELECT mt_principal_disable(?,?,NULL) AS r', [$mandy, $A['principal']])['r'], false, 'disabling again: false, no second act');
is_($audit() - $before, 1, 'still one audit row');

// ===========================================================================
t('11. THE SIX COMMERCIAL FUNCTIONS enforce the floor beneath the route guard; a refusal writes nothing');
$planId = $as($A['customer'], 'SELECT id FROM mt_plan_create(?,3600,2000000,1000000,NULL,1,?,1000,?,?,?,?)',
    ['Floor plan', 'elapsed', 'UGX', $A['site'], $A['principal'], '10.0.0.7'])['id'];
$d = json_decode($lastAudit('plan.created')['detail'], true);
is_([$d['principal_kind'], $d['capability']], ['owner', C::PLANS_WRITE], "the owner's act records principal_kind=owner and the capability it exercised");
$before = $audit();
$refused(fn() => $as($A['customer'], 'SELECT id FROM mt_plan_create(?,3600,2000000,1000000,NULL,1,?,1000,?,?,?,?)', ['Sam plan', 'elapsed', 'UGX', $A['site'], $sam, '10.0.0.7']),
    '42501', 'capability required: op.plans.write', 'mt_plan_create refuses the seller');
$refused(fn() => $as($A['customer'], 'SELECT * FROM mt_plan_update(?,?,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,?,NULL)', [$planId, 'renamed', $sam]),
    '42501', 'capability required: op.plans.write', 'mt_plan_update refuses the seller');
$refused(fn() => $as($A['customer'], 'SELECT * FROM mt_plan_retire(?,?,NULL)', [$planId, $sam]),
    '42501', 'capability required: op.plans.write', 'mt_plan_retire refuses the seller');
$refused(fn() => $as($A['customer'], 'SELECT * FROM mt_voucher_revoke(?,?,NULL)', [$ABSENT, $sam]),
    '42501', 'capability required: op.vouchers.revoke', 'mt_voucher_revoke refuses the seller before it even looks for the voucher');
$refused(fn() => $as($A['customer'], 'SELECT * FROM mt_session_disconnect_request(?,?,NULL)', [$ABSENT, $sam]),
    '42501', 'capability required: op.sessions.disconnect', 'mt_session_disconnect_request refuses the seller');
$refused(fn() => $as($A['customer'], 'SELECT * FROM mt_voucher_batch_issue(?,1,?,?,?::text[],NULL,NULL)', [$planId, $A['site'], $vic, '{VIEWER-0001}']),
    '42501', 'capability required: op.vouchers.issue', 'mt_voucher_batch_issue refuses the viewer');
is_($audit() - $before, 0, 'six refusals, zero audit rows — the check precedes the mutation (W-1.2)');
is_($ins->one('SELECT name FROM mt_plans WHERE id = ?', [$planId])['name'], 'Floor plan', 'and the plan is untouched');
$r = $call('POST', '/api/v1/me/vouchers', ['plan_id' => $planId, 'count' => 2, 'site_id' => $A['site']], $tok['seller'], ['Idempotency-Key' => 'floor-1']);
is_($r->status, 202, 'the seller issues vouchers — the capability it does hold');
$a = $lastAudit('voucher.issued'); $d = json_decode($a['detail'], true);
is_([$a['actor_kind'], $a['actor'], $d['principal_kind'], $d['capability']], ['principal', $sam, 'staff', C::VOUCHERS_ISSUE],
    "audited as principal / staff / op.vouchers.issue — never 'staff' as the actor kind");
$voucher = $ins->one('SELECT id FROM mt_vouchers WHERE customer_id = ? LIMIT 1', [$A['customer']])['id'];
$r = $call('POST', "/api/v1/me/vouchers/{$voucher}/revoke", [], $tok['seller']);
is_([$r->status, $r->body['capability']], [403, C::VOUCHERS_REVOKE], 'the seller cannot revoke over HTTP (the guard)');
is_($call('POST', "/api/v1/me/vouchers/{$voucher}/revoke", [], $tok['owner'])->status, 202, 'CONTROL: the owner can');
$d = json_decode($lastAudit('voucher.revoked')['detail'], true);
is_([$d['principal_kind'], $d['capability']], ['owner', C::VOUCHERS_REVOKE], 'and the revoke records owner / op.vouchers.revoke');

t('11b. REMOVE THE HTTP GUARD and the floor still answers 403 — through the Kernel, inside its transaction');
$raw = new Router();
$raw->post('/raw/plan', function (Request $req, Database $db, array $who) use ($A) {
    $db->one('SELECT id FROM mt_plan_create(?,3600,2000000,1000000,NULL,1,?,1000,?,?,?,?)',
        ['Raw plan ' . bin2hex(random_bytes(3)), 'elapsed', 'UGX', $A['site'], $who['principal_id'], $req->ip]);
    return Response::ok(['wrote' => true]);
});
$raw->get('/raw/grant', function (Request $req, Database $db) {
    $db->query('SELECT * FROM mt_staff');       // dnb_app holds nothing on it: a 42501 that is NOT a policy refusal
    return Response::ok([]);
});
$rawK = new Kernel($raw, $app, $auth, $ctx);
$hit = fn(string $m, string $p, string $t): Response => $rawK->handle(new Request($m, $p, ['Authorization' => "Bearer {$t}"], [], [], '10.0.0.7'));
$before = $audit();
is_([$hit('POST', '/raw/plan', $tok['seller'])->status, $hit('POST', '/raw/plan', $tok['seller'])->body],
    [403, ['error' => 'forbidden', 'capability' => C::PLANS_WRITE]],
    'an UNGUARDED route: the seller is still refused, by the function, and the Kernel answers 403 naming the capability');
is_($audit() - $before, 0, 'and nothing was written');
is_($hit('POST', '/raw/plan', $tok['owner'])->status, 200, 'CONTROL: the same unguarded route works for the owner — the 403 came from the floor, not the route');
is_($audit() - $before, 1, 'and that one act wrote its audit row');
$r = $hit('GET', '/raw/grant', $tok['owner']);
is_([$r->status, $r->body], [500, ['error' => 'internal']], 'any OTHER 42501 — a missing grant — stays a 500: a misconfiguration never reads as a policy refusal (J-9)');

// ===========================================================================
t('12. THE ADMIN PLANE — DishNet Staff create a principal for an EXPLICIT target operator');
$adminCreate = fn(?string $operator, string $kind, string $name, ?string $phone, array $list, string $actor): ?string =>
    $aw->one('SELECT mt_admin_principal_create(?,?,?,?,?::text[],?) AS id', [$operator, $kind, $name, $phone, C::toPg($list), $actor])['id'];
$before = $audit();
$bea = $adminCreate($B['customer'], 'staff', 'Bea Books', '+256700009101', [C::PLANS_READ], 'noc-user');
is_($row($bea)['customer_id'], $B['customer'], 'the row belongs to the target operator, B');
is_([$row($bea)['kind'], $caps($bea)], ['staff', [C::PLANS_READ]], 'with the kind and list asked for');
is_($audit() - $before, 1, 'one audit row');
$a = $lastAudit('principal.created'); $d = json_decode($a['detail'], true);
is_([$a['actor_kind'], $a['actor'], $a['customer_id'], $a['source'], $d['operator'] ?? null],
    ['staff', 'noc-user', $B['customer'], 'admin', $B['customer']],
    "actor_kind 'staff' (DishNet staff), the username as actor, the target operator recorded");
is_(str_contains($a['detail'], '700009101'), false, 'the phone is not in the audit detail');
$aw->exec("SET app.customer_id = '{$A['customer']}'");
$bea2 = $adminCreate($B['customer'], 'staff', 'Bea Two', null, [], 'noc-user');
is_($row($bea2)['customer_id'], $B['customer'], "with app.customer_id set to A on the connection, the row STILL lands in B — the GUC is not the target");
$refused(fn() => $adminCreate(null, 'staff', 'Nowhere', null, [], 'noc-user'),
    '23514', 'target operator is required', 'and with no target the function refuses rather than reading mt_current_customer()');
$aw->exec('RESET app.customer_id');
$src = $ins->one("SELECT prosrc FROM pg_proc WHERE proname = 'mt_admin_principal_create'")['prosrc'];
is_(str_contains($src, 'mt_current_customer'), false, 'the function body never consults the tenant context at all');
is_(str_contains($src, 'p_operator'), true, 'CONTROL: the body probe reads the real source — the target parameter is in it');
$before = $audit();
$refused(fn() => $adminCreate($ABSENT, 'staff', 'Ghost', null, [], 'noc-user'),
    '23503', 'foreign key', 'an unknown target operator is refused by the FK — the function never reads a customer row');
$refused(fn() => $adminCreate($B['customer'], 'staff', 'Dup', '+256700009001', [], 'noc-user'),
    '23505', 'duplicate key', "a phone in use (Mandy's, in A) is refused across operators (P-B)");
$refused(fn() => $adminCreate($B['customer'], 'owner', 'Odd', null, [C::PLANS_READ], 'noc-user'),
    '23514', 'an owner holds every capability', 'an owner with a list is refused');
$refused(fn() => $adminCreate($B['customer'], 'staff', 'Sneak', null, [C::STAFF_MANAGE], 'noc-user'),
    '23514', 'mt_principals_kind_capabilities', 'staff with op.staff.manage is refused by the CHECK');
$refused(fn() => $adminCreate($B['customer'], 'staff', 'Nobody', null, [], ''),
    '23514', 'identity of whoever performed it', 'an empty actor is refused — the actor is a parameter from the identity boundary (W-1)');
is_($audit() - $before, 0, 'five refusals, zero audit rows');
$refused(fn() => $aw->one('SELECT mt_principal_create(?,?,?,?::text[],?,NULL) AS id', ['staff', 'x', null, '{}', $A['principal']]),
    '42501', 'permission denied for function', 'dnb_adminwrite cannot call the operator-plane writer at all');
$refused(fn() => $app->one('SELECT mt_admin_principal_create(?,?,?,?,?::text[],?) AS id', [$A['customer'], 'staff', 'x', null, '{}', 'x']),
    '42501', 'permission denied for function', 'and dnb_app cannot call the Admin one');

// ===========================================================================
t('13. mt_admin_principals() — the fourteenth projection withholds phone, email and every credential');
$reader = new AdminReader(Database::adminApi());
$routes = AdminRoutes::build(new FixedStaff(new StaffIdentity('s', StaffRole::Admin, 't')), Bindings::defaults(), $reader);
$mm = $routes->match('GET', '/api/v1/admin/principals');
is_($mm !== null, true, 'GET /api/v1/admin/principals is routed');
$res = ($mm[0])(new Request('GET', '/api/v1/admin/principals', [], [], $mm[1]), null, null);
is_($res->status, 200, 'and answers');
$rows = $res->body['principal'];
is_(count(array_unique(array_column($rows, 'customer_id'))) >= 2, true, 'rows from both operators — genuinely cross-tenant, not an empty pass');
is_(array_keys($rows[0]), ['id', 'customer_id', 'kind', 'display_name', 'status', 'capabilities', 'created_at', 'last_login_at'],
    'exactly the eight approved fields (docs/116 D.12)');
$json = json_encode($res->body);
foreach (['phone', 'email', 'credential', 'token', 'hash', 'secret', '700009001', '700001001', '700009101'] as $needle) {
    is_(str_contains($json, $needle), false, "no '{$needle}' anywhere in the Admin response");
}
is_($ins->one("SELECT array_to_string(proargnames, ',') AS n FROM pg_proc WHERE proname = 'mt_admin_principals'")['n'],
    'id,customer_id,kind,display_name,status,capabilities,created_at,last_login_at', 'the function cannot return phone or email at all');
$f = $ins->one("SELECT pg_get_userbyid(proowner) AS o, prosecdef AS d, coalesce(array_to_string(proconfig, ','), '') AS c
                  FROM pg_proc WHERE proname = 'mt_admin_principals'");
is_([$f['o'], (bool) $f['d'], str_contains($f['c'], 'search_path=public, pg_temp')], ['dnb_def_admin', true, true],
    'owned by dnb_def_admin, SECURITY DEFINER, search_path pinned');
$mrow = current(array_filter($rows, fn($r) => $r['id'] === $mandy));
is_($mrow['status'], 'disabled', 'a disabled principal is visible as such');
$mm = $routes->match('POST', "/api/v1/admin/customers/{$B['customer']}/principals");
is_($mm !== null, true, 'the Admin creator PATH is declared');
is_(($mm[0])(new Request('POST', '/x', [], [], $mm[1], '127.0.0.1'), null, null)->status, 501,
    'and answers 501 — the function exists, binding the route is a separate instruction (J-1)');

// ===========================================================================
t('14. ACTOR KIND — staff means DishNet staff; every operator person is principal; nothing maps kind onto it');
is_((int) $ins->one("SELECT count(*)::int AS n FROM mt_audit_log WHERE action LIKE 'principal.%' AND actor_kind = 'staff' AND source IS DISTINCT FROM 'admin'")['n'], 0,
    "every principal.* row with actor_kind 'staff' came from the Admin plane");
is_((int) $ins->one("SELECT count(*)::int AS n FROM mt_audit_log WHERE action LIKE 'principal.%' AND actor_kind = 'principal'
                       AND (detail->>'principal_kind') IS DISTINCT FROM 'owner'")['n'], 0,
    'every operator-plane principal.* row was performed by an OWNER — the only kind that can');
is_((int) $ins->one("SELECT count(*)::int AS n FROM mt_audit_log WHERE action LIKE 'principal.%' AND actor_kind = 'principal'")['n'] >= 12, true,
    'CONTROL: there are many such rows');
is_((int) $ins->one("SELECT count(*)::int AS n FROM mt_audit_log WHERE action = 'principal.created' AND actor_kind = 'staff'")['n'] >= 4, true,
    'CONTROL: and the Admin-plane rows exist too (the two seeded owners, Bea, Bea Two)');
is_((int) $ins->one("SELECT count(*)::int AS n FROM mt_audit_log WHERE actor_kind NOT IN ('principal','staff','system')")['n'], 0, 'no other actor kind exists');
$ck = $ins->one("SELECT pg_get_constraintdef(oid) AS d FROM pg_constraint
                  WHERE conrelid = 'mt_audit_log'::regclass AND pg_get_constraintdef(oid) LIKE '%actor_kind%'")['d'];
is_(str_contains($ck, "'principal'") && str_contains($ck, "'staff'") && str_contains($ck, "'system'")
    && !str_contains($ck, "'owner'") && !str_contains($ck, "'guest'"), true,
    'actor_kind is still CHECK-constrained to principal | staff | system — no kind was added');
$arg = '((?:[^,()]|\([^()]*\))+)';
$calls = 0; $bad = [];
foreach (glob(__DIR__ . '/../migrations/*.sql') as $f) {
    $sql = preg_replace('/--[^\n]*/', '', file_get_contents($f));
    if (!preg_match_all("/mt_audit_write\s*\(\s*{$arg},\s*{$arg},\s*{$arg},/", $sql, $m, PREG_SET_ORDER)) { continue; }
    foreach ($m as $c) {
        $third = trim($c[3]);
        if (preg_match('/^p_\w+\s+\w+$/', $third) || $third === 'text') { continue; }   // the definition, a GRANT, a REVOKE
        $calls++;
        if (!preg_match("/^'(principal|staff|system)'$/", $third)) { $bad[] = basename($f) . ': ' . $third; }
    }
}
is_($bad, [], 'every mt_audit_write() call in every migration passes a LITERAL actor kind — never a variable, never a principal\'s kind');
is_($calls >= 20, true, "CONTROL: the scan found the calls ({$calls})");
$mapping = [];
foreach (array_merge(glob(__DIR__ . '/../src/*.php'), glob(__DIR__ . '/../src/*/*.php'), glob(__DIR__ . '/../src/*/*/*.php')) as $f) {
    $code = strip_php_comments(file_get_contents($f));
    if (preg_match('/actor_kind\W{0,6}(=>|=|:)\s*\$?\w*\[?[\'"]?kind\b/i', $code)) { $mapping[] = basename($f); }
}
is_($mapping, [], "no PHP file maps a principal's kind onto actor_kind");

// ===========================================================================
t('15. REPOSITORY STATE — 027 is applied and 028 (docs/121) follows it; the ledger agrees; the census gained its line');
$files = array_map('basename', glob(__DIR__ . '/../migrations/*.sql')); sort($files);
is_(count(array_filter($files, fn($f) => str_starts_with($f, '027'))), 1, 'exactly one 027 file exists');
is_(substr(end($files), 0, 3), '028', 'the last migration file is 028 — router lifecycle and provisioning (docs/121), not a T-2 change');
is_((int) $ins->one('SELECT count(*)::int AS n FROM mt_migrations')['n'], 28, 'the ledger records 28 migrations');
is_(substr($ins->one('SELECT max(filename) AS f FROM mt_migrations')['f'], 0, 3), '028', 'the latest applied is 028');
$census = file_get_contents(__DIR__ . '/../tools/audit/production_census.sql');
is_(str_contains($census, 'SECTION 1c') && str_contains($census, 'mt_admin_principals'), true,
    'the production census counts principals by kind and status (docs/79 §1c)');

exit(t_summary());
