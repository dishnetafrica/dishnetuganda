<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/admin_identity_double.php';

use Dn\Admin\AdminReader;
use Dn\Admin\Capability;
use Dn\Admin\Csrf;
use Dn\Admin\DenyAllIdentity;
use Dn\Admin\DevSessionIdentity;
use Dn\Admin\DishnetStaffIdentity;
use Dn\Admin\StaffAdmin;
use Dn\Admin\StaffIdentityFactory;
use Dn\Admin\StaffRole;
use Dn\Admin\StaffToken;
use Dn\Admin\TransportPolicy;
use Dn\Api\AdminRoutes;
use Dn\Db\Database;
use Dn\Http\Request;
use Dn\Runtime\Bindings;

/**
 * G-B — DishNet Staff authentication (docs/114, migration 026).
 *
 * WHO is DishNet staff, and nothing more. Every proof below is an execution,
 * paired with a positive control where the claim is a negative, and stated
 * with its role and database — the project's own rule (docs/105 §0). The
 * things this file must keep true if it is edited:
 *
 *   * no login role holds ANY privilege on mt_staff or mt_staff_sessions;
 *   * dnb_staffauth reaches login / resolve / logout and the constants function,
 *     nothing else, and cannot reach a single write function;
 *   * no function a login role can EXECUTE returns a password hash or a TOTP
 *     secret (enrolment returns a NEW secret once, to the session that asked);
 *   * every credential failure is ONE byte-identical answer;
 *   * the decaying lockout happens inside mt_staff_login();
 *   * disabling a person revokes every live session in the same transaction,
 *     and resolve re-reads status live even for a row nothing revoked;
 *   * the provider is chosen explicitly, never by fallback; the development
 *     identity and the real one refuse to coexist; the real one WORKS under
 *     the real-bindings gate that makes the development one throw;
 *   * a session is issued only over TLS, only same-origin, only as JSON;
 *   * audit rows are exactly one per act, none on refusal, actor_kind='staff',
 *     no secret in detail — and the whole thing works over real HTTP.
 */

$ins   = Database::inspector();                 // postgres, BYPASSRLS: observation and fixtures only
$auth  = Database::staffAuth();                 // dnb_staffauth
$write = Database::adminWrite();                // dnb_adminwrite
$admin = StaffAdmin::on($write);
$reader = new AdminReader(Database::adminApi());

putenv('DNB_TOKEN_PEPPER=staff-suite-pepper');
$tokens = new StaffToken('staff-suite-pepper');
$tlsOff = new TransportPolicy([]);
$tlsOn  = new TransportPolicy(['127.0.0.1']);
$hasSql = static fn(string $t, string $c) => (bool) $ins->one(
    "SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = ? AND column_name = ?) AS e", [$t, $c])['e'];

// Clean slate: these tables are ours and not tenant-scoped.
$ins->exec('DELETE FROM mt_staff_sessions');
$ins->exec('DELETE FROM mt_staff');
// The audit log is append-only, so a re-run against the same database finds
// the previous run's rows. Every count below is therefore a DELTA from here.
$auditBefore = (int) $ins->one("SELECT count(*)::int c FROM mt_audit_log WHERE action LIKE 'staff.%'")['c'];
$startOf = [];
foreach (['staff.login', 'staff.logout', 'staff.locked', 'staff.created', 'staff.disabled', 'staff.enabled',
          'staff.totp_enrolled', 'staff.password_changed', 'staff.role_changed', 'staff.password_reset', 'staff.totp_reset'] as $a) {
    $startOf[$a] = (int) $ins->one('SELECT count(*)::int c FROM mt_audit_log WHERE action = ?', [$a])['c'];
}

$provider = static fn(TransportPolicy $tls, bool $require = true) =>
    new DishnetStaffIdentity($auth, static fn() => $write, $tokens, $tls, $require);
/** A router around a provider; $csrf defaults to "Origin must match Host". */
$routerFor = static fn(DishnetStaffIdentity $p, ?Csrf $csrf = null) =>
    AdminRoutes::build($p, Bindings::defaults(), $reader, $p, new StaffAdmin(static fn() => $write), $csrf);
$call = static function ($router, string $method, string $path, array $body = [], array $headers = [], string $ip = '127.0.0.1') {
    $req = new Request($method, $path, $headers, $body, [], $ip);
    $m = $router->match($method, $path);
    if ($m === null) { return null; }
    return $m[0]($req->withParams($m[1]), null, null);
};
$cookieOf = static function ($res): string {
    preg_match('/' . StaffToken::COOKIE . '=([0-9a-f]{64})/', $res->headers['Set-Cookie'] ?? '', $m);
    return $m[1] ?? '';
};
$TLS = ['X-Forwarded-Proto' => 'https'];
$withCookie = static fn(string $t, array $extra = []) => $extra + ['Cookie' => StaffToken::COOKIE . '=' . $t];
$auditCount = static fn(string $action) => (int) $ins->one(
    'SELECT count(*)::int c FROM mt_audit_log WHERE action = ?', [$action])['c'] - ($startOf[$action] ?? 0);

// ===========================================================================
t('1. ZERO PRIVILEGE — no login role can touch the credential tables (with a positive control)');

// EVERY dnb_* login role on the cluster, enumerated from pg_roles — including
// any stray one a development cluster carries (docs/113 found dnb_plain). The
// owner (dnb) is excluded on purpose: it is not a request identity, and it
// legitimately owns things. Where an assertion is about a role the MIGRATIONS
// create, $pluginLogin narrows to those.
$loginRoles = array_column($ins->query(
    "SELECT rolname FROM pg_roles WHERE rolcanlogin AND rolname LIKE 'dnb\\_%' ORDER BY rolname"), 'rolname');
$pluginLogin = array_values(array_intersect($loginRoles, \Dn\Plugin\Installer::ROLES));
is_(count($loginRoles) >= 7, true, 'enumerated ' . count($loginRoles) . ' dnb_* login roles from pg_roles, not from a list');
is_($pluginLogin, ['dnb_admin', 'dnb_adminapi', 'dnb_adminwrite', 'dnb_app', 'dnb_radius', 'dnb_staffauth', 'dnb_worker'],
    'the seven the migrations create are all present, dnb_staffauth among them');
foreach ($loginRoles as $role) {
    foreach (['mt_staff', 'mt_staff_sessions'] as $tbl) {
        foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $priv) {
            is_($ins->one('SELECT has_table_privilege(?,?,?) AS p', [$role, $tbl, $priv])['p'], false,
                "{$role} has no {$priv} on {$tbl}");
        }
    }
}
// The control: the owner of the tables — the NOLOGIN definer — can.
foreach (['mt_staff', 'mt_staff_sessions'] as $tbl) {
    is_($ins->one('SELECT has_table_privilege(?,?,?) AS p', ['dnb_def_staff', $tbl, 'SELECT'])['p'], true,
        "CONTROL: dnb_def_staff (the owner) CAN read {$tbl} — the negatives above are not a broken probe");
    is_($ins->one("SELECT tableowner FROM pg_tables WHERE tablename = ?", [$tbl])['tableowner'], 'dnb_def_staff',
        "{$tbl} is owned by dnb_def_staff, not by the migration owner");
}
is_($ins->query("SELECT 1 FROM pg_default_acl d JOIN pg_roles r ON r.oid = d.defaclrole WHERE r.rolname = 'dnb_def_staff'"), [],
    'dnb_def_staff has no default ACL, so a future table of its own grants nothing by default either');
is_($ins->one("SELECT rolcanlogin FROM pg_roles WHERE rolname = 'dnb_def_staff'")['rolcanlogin'], false,
    'dnb_def_staff cannot log in');
is_($ins->one("SELECT has_schema_privilege('dnb_def_staff','public','CREATE') AS p")['p'], false,
    'and holds no CREATE on the schema at rest');
// pgcrypto — the schema's first extension — was created by the non-superuser owner on install.
is_($ins->one("SELECT count(*)::int c FROM pg_extension WHERE extname = 'pgcrypto'")['c'], 1,
    'pgcrypto is installed (created by the non-superuser owner through the real installer)');
// The credential columns exist and are the ones the projection must never return.
is_($hasSql('mt_staff', 'password_hash') && $hasSql('mt_staff', 'totp_secret') && $hasSql('mt_staff_sessions', 'token_hash'), true,
    'CONTROL: password_hash, totp_secret and token_hash exist, so withholding them below is a real claim');

t('1b. the authentication role reaches exactly four functions and nothing else');
$reach = array_column($ins->query(
    "SELECT p.oid::regprocedure::text AS f FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
      WHERE n.nspname = 'public' AND has_function_privilege('dnb_staffauth', p.oid, 'EXECUTE')
        AND NOT has_function_privilege('public', p.oid, 'EXECUTE') ORDER BY 1"), 'f');
is_($reach, ['mt_staff_login(text,text,text,text,text)', 'mt_staff_logout(text)', 'mt_staff_policy()',
             'mt_staff_session_resolve(text)'],
    'dnb_staffauth: login, logout, resolve, and the constants function — the enumeration, not a list');
is_($ins->query("SELECT 1 FROM information_schema.role_table_grants WHERE grantee = 'dnb_staffauth'"), [],
    'and zero table privileges of any kind');
foreach (['mt_device_register(text,text,text,text,text,text)', 'mt_customer_create(text,text)',
          'mt_staff_create(text,text,text,text,text)', 'mt_staff_disable(uuid,text)',
          'mt_admin_staff()', 'mt_admin_customers()'] as $fn) {
    is_($ins->one('SELECT has_function_privilege(?,?,?) AS p', ['dnb_staffauth', $fn, 'EXECUTE'])['p'], false,
        "dnb_staffauth cannot EXECUTE {$fn} (M1: the pre-authentication role reaches no write and no projection)");
}
is_($ins->one("SELECT count(*)::int c FROM pg_auth_members WHERE member = 'dnb_staffauth'::regrole")['c'], 0,
    'and is a member of no role');
// Measured: the internal helpers are reachable by NO login role.
foreach (['mt_staff_insert(text,text,text,text,text,jsonb)', 'mt_staff_totp_step(bytea,text,timestamptz,bigint)',
          'mt_staff_revoke_sessions(uuid,text,text)'] as $fn) {
    foreach ($loginRoles as $role) {
        is_($ins->one('SELECT has_function_privilege(?,?,?) AS p', [$role, $fn, 'EXECUTE'])['p'], false,
            "{$role} cannot reach the internal helper {$fn}");
    }
}

t('1c. no function a login role can EXECUTE returns a hash, a secret or a session token');
$rows = $ins->query(
    "SELECT p.oid::regprocedure::text AS f, pg_get_function_result(p.oid) AS r
       FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
      WHERE n.nspname = 'public' AND (p.proname LIKE 'mt_staff%' OR p.proname = 'mt_admin_staff')");
is_(count($rows) >= 15, true, 'enumerated ' . count($rows) . ' staff functions');
foreach ($rows as $x) {
    foreach (['password_hash', 'totp_secret', 'token_hash'] as $col) {
        is_(str_contains((string) $x['r'], $col), false, "{$x['f']} does not return {$col}");
    }
}
// The one bytea-returning function is enrolment, and only the write connection may call it —
// and it hands back a NEW secret to the session that asked, never an existing one (proved in §7).
$bytea = array_column(array_filter($rows, static fn($x) => str_contains((string) $x['r'], 'bytea')), 'f');
is_($bytea, ['mt_staff_totp_enrol(text)'], 'exactly one function returns raw bytes: enrolment');
foreach ($pluginLogin as $role) {
    is_($ins->one('SELECT has_function_privilege(?,?,?) AS p', [$role, 'mt_staff_totp_enrol(text)', 'EXECUTE'])['p'],
        $role === 'dnb_adminwrite', "{$role} " . ($role === 'dnb_adminwrite' ? 'can' : 'cannot') . ' call enrolment');
}
is_(str_contains($ins->one("SELECT pg_get_function_result('mt_admin_staff()'::regprocedure) AS r")['r'], 'totp_enrolled'), true,
    'the Admin projection reports WHETHER an authenticator is enrolled, and nothing of it');

// ===========================================================================
t('2. THE FIRST ADMINISTRATOR — bootstrap once, then never again');

$alice = $admin->bootstrap('Alice', 'Alice A.', 'cli:suite');
is_(preg_match('/^[0-9a-f-]{36}$/', $alice['id']), 1, 'bootstrap returns an id');
is_(preg_match('/^[a-z2-9]{6}(-[a-z2-9]{6}){3}$/', $alice['password']), 1,
    'and a generated 27-character password from an unambiguous alphabet');
is_($ins->one('SELECT username, role, status FROM mt_staff WHERE id = ?', [$alice['id']]),
    ['username' => 'alice', 'role' => 'admin', 'status' => 'active'], 'username case-folded, role forced to admin');
throws_(fn() => $admin->bootstrap('mallory', 'M', 'cli:suite'), 'already exist',
    'a second bootstrap is REFUSED once anyone exists');
is_($auditCount('staff.created') - 0 >= 1, true, 'bootstrap audited staff.created');
$row = $ins->one("SELECT actor, actor_kind, customer_id, detail FROM mt_audit_log WHERE action = 'staff.created' ORDER BY at DESC LIMIT 1");
is_($row['actor'], 'cli:suite', 'the actor is the parameter the identity boundary passed');
is_($row['actor_kind'], 'staff', "actor_kind is 'staff' — no new actor kind");
is_($row['customer_id'], null, 'and no customer: DishNet staff are not a tenant');
is_(json_decode($row['detail'], true)['bootstrap'] ?? null, true, 'detail records that this was the bootstrap');
$hash = $ins->one('SELECT password_hash FROM mt_staff WHERE id = ?', [$alice['id']])['password_hash'];
is_(preg_match('/^\$2[aby]\$12\$/', $hash), 1, 'the stored hash is bcrypt at cost 12 — verified in the database, not assumed');
is_(str_contains($hash, $alice['password']), false, 'and the password itself is not in it');

$bob = $admin->create('bob', 'Bob B.', StaffRole::Noc, 'alice');
throws_(fn() => $admin->create('BOB', 'Bob again', StaffRole::Sales, 'alice'), 'already taken',
    'a duplicate username (case-insensitively) is refused with a message naming no constraint');
throws_(fn() => $write->one("SELECT mt_staff_create('carol','C','sales','short','alice')"), 'shorter than 12',
    'a short password is refused in the database');

// ===========================================================================
t('3. BYTE-IDENTICAL FAILURE — unknown, wrong password, wrong code, locked, disabled');

$p  = $provider($tlsOn);
$r  = $routerFor($p);
$refusals = [];
$refusals['unknown user']   = $call($r, 'POST', '/api/v1/admin/session', ['username' => 'nobody', 'password' => 'whatever-it-is'], $TLS);
$refusals['wrong password'] = $call($r, 'POST', '/api/v1/admin/session', ['username' => 'alice', 'password' => 'wrong-wrong-wrong'], $TLS);
$refusals['missing fields'] = $call($r, 'POST', '/api/v1/admin/session', [], $TLS);
$refusals['empty strings']  = $call($r, 'POST', '/api/v1/admin/session', ['username' => '', 'password' => ''], $TLS);
// A disabled account: create, disable, try.
$dora = $admin->create('dora', 'Dora', StaffRole::Support, 'alice');
$doraPw = $dora['password'];
is_($admin->disable($dora['id'], 'alice'), true, 'CONTROL: dora is disabled');
$refusals['disabled account'] = $call($r, 'POST', '/api/v1/admin/session', ['username' => 'dora', 'password' => $doraPw], $TLS);

$t0 = microtime(true);
$refusals['unknown user (timed)'] = $call($r, 'POST', '/api/v1/admin/session', ['username' => 'zz-not-here', 'password' => 'x'], $TLS);
$unknownMs = (microtime(true) - $t0) * 1000;

$first = null;
foreach ($refusals as $case => $res) {
    is_($res->status, 401, "{$case}: 401");
    is_($res->body, ['error' => 'invalid_credentials'], "{$case}: the one body");
    is_($res->headers, [], "{$case}: no header, so no cookie");
    $first ??= json_encode([$res->status, $res->body, $res->headers]);
    is_(json_encode([$res->status, $res->body, $res->headers]), $first, "{$case}: byte-identical to the first refusal");
}
is_($unknownMs > 40, true, sprintf('an unknown username still costs a bcrypt (%.0f ms) — the function spends one on every failing path', $unknownMs));
is_($ins->one('SELECT count(*)::int c FROM mt_staff_sessions')['c'], 0, 'no refusal created a session row');
is_($auditCount('staff.login'), 0, 'and no refusal wrote a staff.login audit row (failures are counters, not rows)');
is_((int) $ins->one("SELECT failed_attempts FROM mt_staff WHERE username = 'alice'")['failed_attempts'], 1,
    'CONTROL: the wrong password WAS counted against alice');

// ===========================================================================
t('4. TRANSPORT — a session is issued only over TLS, and Secure is not optional');

$plain = $routerFor($provider($tlsOff));
$res = $call($plain, 'POST', '/api/v1/admin/session', ['username' => 'alice', 'password' => $alice['password']]);
is_($res->status, 403, 'over plain HTTP the RIGHT password is refused');
is_($res->body['error'], 'insecure_transport', 'with the reason: insecure_transport, not a credential error');
is_($ins->one('SELECT count(*)::int c FROM mt_staff_sessions')['c'], 0, 'and no session row was written');
is_($auditCount('staff.login'), 0, 'and nothing was audited — the function never ran');
$res = $call($plain, 'POST', '/api/v1/admin/session', ['username' => 'alice', 'password' => $alice['password']], $TLS);
is_($res->status, 403, 'X-Forwarded-Proto: https from a client that is NOT a configured proxy is ignored');
$res = $call($routerFor($provider($tlsOn)), 'POST', '/api/v1/admin/session',
             ['username' => 'alice', 'password' => $alice['password']], $TLS, '10.9.9.9');
is_($res->status, 403, 'and ignored from an address other than the configured proxy');
$res = $call($routerFor($provider($tlsOff)), 'POST', '/api/v1/admin/session',
             ['username' => 'alice', 'password' => $alice['password']]);
is_($res->status, 403, 'CONTROL: still refused with no proxy configured at all');
$reqTls = new Request('POST', '/api/v1/admin/session', [], ['username' => 'alice', 'password' => $alice['password']], [], '127.0.0.1', true);
$m = $plain->match('POST', '/api/v1/admin/session');
$res = $m[0]($reqTls, null, null);
is_($res->status, 200, 'a request PHP itself received over TLS is accepted with no proxy configured');
is_(str_contains($res->headers['Set-Cookie'], '; Secure'), true, 'and the cookie carries Secure');
is_(str_contains($res->headers['Set-Cookie'], 'HttpOnly'), true, 'HttpOnly');
is_(str_contains($res->headers['Set-Cookie'], 'SameSite=Strict'), true, 'SameSite=Strict');
is_(preg_match('/Max-Age=(\d+)/', $res->headers['Set-Cookie'], $mm) === 1 && (int) $mm[1] <= 8 * 3600 && (int) $mm[1] > 8 * 3600 - 60, true,
    'Max-Age is the 8-hour policy from mt_staff_policy()');
$tok1 = $cookieOf($res);
is_(strlen($tok1), 64, 'the token is 64 hex characters — 256 random bits');
is_($ins->one('SELECT count(*)::int c FROM mt_staff_sessions WHERE token_hash = ?', [$tok1])['c'], 0,
    'the TOKEN is stored nowhere');
is_($ins->one('SELECT count(*)::int c FROM mt_staff_sessions WHERE token_hash = ?', [$tokens->hash($tok1)])['c'], 1,
    'only its HMAC under the label-derived key is');
$otherKey = new StaffToken('a-different-pepper');
is_($ins->one('SELECT count(*)::int c FROM mt_staff_sessions WHERE token_hash = ?', [$otherKey->hash($tok1)])['c'], 0,
    'CONTROL: a different pepper derives a different hash, so the pepper is load-bearing');
$logoutPlain = $call($plain, 'DELETE', '/api/v1/admin/session', [], $withCookie($tok1));
is_($logoutPlain->status, 204, 'logout works over plain HTTP too (revoking is never refused)');

t('4b. CROSS-SITE — Origin must match, cross-site is refused, only JSON is accepted');
$r = $routerFor($provider($tlsOn));
$creds = ['username' => 'alice', 'password' => $alice['password']];
$res = $call($r, 'POST', '/api/v1/admin/session', $creds, $TLS + ['Origin' => 'https://evil.example', 'Host' => 'admin.dishnet.test']);
is_($res->status, 403, 'an Origin naming another site is refused');
is_($res->body['error'], 'cross_origin', 'as cross_origin');
$res = $call($r, 'POST', '/api/v1/admin/session', $creds, $TLS + ['Sec-Fetch-Site' => 'cross-site', 'Host' => 'admin.dishnet.test']);
is_($res->status, 403, 'a browser declaring Sec-Fetch-Site: cross-site is refused even with no Origin');
$res = $call($r, 'POST', '/api/v1/admin/session', $creds, $TLS + ['Content-Type' => 'application/x-www-form-urlencoded']);
is_($res->status, 415, 'a form-encoded body — the classic cross-site vector — is refused');
$res = $call($r, 'POST', '/api/v1/admin/session', $creds, $TLS + ['Origin' => 'https://admin.dishnet.test', 'Host' => 'admin.dishnet.test']);
is_($res->status, 200, 'CONTROL: the same request with an Origin matching the Host is accepted');
$call($r, 'DELETE', '/api/v1/admin/session', [], $withCookie($cookieOf($res)));
$pinned = $routerFor($provider($tlsOn), new Csrf('https://portal.dishnet.test'));
$res = $call($pinned, 'POST', '/api/v1/admin/session', $creds, $TLS + ['Origin' => 'https://admin.dishnet.test', 'Host' => 'admin.dishnet.test']);
is_($res->status, 403, 'with DN_PORTAL_ORIGIN configured, an Origin matching only the Host is refused');
$res = $call($pinned, 'POST', '/api/v1/admin/session', $creds, $TLS + ['Origin' => 'https://portal.dishnet.test', 'Host' => 'admin.dishnet.test']);
is_($res->status, 200, 'and the configured origin is accepted');
$call($pinned, 'DELETE', '/api/v1/admin/session', [], $withCookie($cookieOf($res)));
is_($ins->one('SELECT count(*)::int c FROM mt_staff_sessions WHERE revoked_at IS NULL')['c'], 0, 'CONTROL: every session opened above is revoked again');

// ===========================================================================
t('5. THE SESSION — who am I, no role from the browser, expiry, logout revokes, replay refused');

$r = $routerFor($provider($tlsOn, false));   // second factor optional here; §7 covers required
$res = $call($r, 'POST', '/api/v1/admin/session', ['username' => 'bob', 'password' => $bob['password'], 'role' => 'admin'], $TLS);
is_($res->status, 200, 'bob (noc) signs in');
is_($res->body['identity']['role'], 'noc', "the browser asked for 'admin' and was given noc: the role comes from the row, never the request");
is_($res->body['identity']['subject'], 'bob', 'the subject is the immutable username');
is_($res->body['identity']['provider'], 'dishnet', 'the provider names itself');
is_(in_array(Capability::ROUTERS_READ, $res->body['identity']['capabilities'], true), true, 'capabilities are the role\'s');
is_(in_array(Capability::STAFF_MANAGE, $res->body['identity']['capabilities'], true), false, 'and noc has no staff.manage');
$tokBob = $cookieOf($res);
$loginAudit = $auditCount('staff.login');

$res = $call($r, 'GET', '/api/v1/admin/session', [], $withCookie($tokBob));
is_($res->status, 200, 'GET /session with the cookie answers');
is_($res->body['identity']['subject'], 'bob', 'as bob');
$res = $call($r, 'GET', '/api/v1/admin/routers', [], $withCookie($tokBob));
is_($res->status, 200, 'an estate route the role carries answers 200');
$res = $call($r, 'GET', '/api/v1/admin/staff', [], $withCookie($tokBob));
is_($res->status, 403, 'the staff roster is 403 for noc');
is_($res->body, ['error' => 'forbidden', 'capability' => 'staff.manage'], 'naming staff.manage — the existing guard, unchanged');
$res = $call($r, 'GET', '/api/v1/admin/session', [], $withCookie(strrev($tokBob)));
is_($res->status, 401, 'a tampered token is 401');
$res = $call($r, 'GET', '/api/v1/admin/session', [], $withCookie(bin2hex(random_bytes(32))));
is_($res->status, 401, 'a random token is 401');
is_($auditCount('staff.login'), $loginAudit, 'and none of that wrote an audit row');

// Expiry is absolute and server-side: move it into the past and the cookie is dead.
$ins->exec("UPDATE mt_staff_sessions SET expires_at = now() - interval '1 second' WHERE token_hash = ?", [$tokens->hash($tokBob)]);
$res = $call($r, 'GET', '/api/v1/admin/session', [], $withCookie($tokBob));
is_($res->status, 401, 'an expired session is 401 — state 5');
$ins->exec("UPDATE mt_staff_sessions SET expires_at = now() + interval '1 hour' WHERE token_hash = ?", [$tokens->hash($tokBob)]);
$res = $call($r, 'GET', '/api/v1/admin/session', [], $withCookie($tokBob));
is_($res->status, 200, 'CONTROL: restoring the expiry restores the session — expiry is what failed');

$logoutsBefore = $auditCount('staff.logout');
$res = $call($r, 'DELETE', '/api/v1/admin/session', [], $withCookie($tokBob, $TLS));
is_($res->status, 204, 'logout is 204');
is_($auditCount('staff.logout'), $logoutsBefore + 1, 'logout audited exactly once');
is_(str_contains($res->headers['Set-Cookie'], 'Max-Age=0'), true, 'and clears the cookie');
$res = $call($r, 'GET', '/api/v1/admin/routers', [], $withCookie($tokBob));
is_($res->status, 401, 'LOGOUT REVOKES: a client replaying the old cookie is refused');
is_($ins->one('SELECT revoked_reason FROM mt_staff_sessions WHERE token_hash = ?', [$tokens->hash($tokBob)])['revoked_reason'], 'logout',
    'the row records why');
$before = $auditCount('staff.logout');
$res = $call($r, 'DELETE', '/api/v1/admin/session', [], $withCookie($tokBob, $TLS));
is_($res->status, 204, 'a repeated logout is still 204');
is_($auditCount('staff.logout'), $before, 'and writes NO second audit row — an already-revoked session audits nothing');
$res = $call($r, 'DELETE', '/api/v1/admin/session', [], $TLS);
is_($res->status, 204, 'logout with no cookie at all is 204 and reveals nothing');

// ===========================================================================
t('6. DECAYING LOCKOUT — inside mt_staff_login(), with every number from mt_staff_policy()');

$pol = $ins->one('SELECT * FROM mt_staff_policy()');
is_((int) $pol['lockout_threshold'], 5, 'threshold 5');
is_($pol['lockout_base'], '00:15:00', 'first lock 15 minutes');
is_($pol['lockout_ceiling'], '24:00:00', 'ceiling 24 hours');
is_($pol['session_ttl'], '08:00:00', 'session 8 hours');
is_((int) $pol['bcrypt_cost'], 12, 'bcrypt cost 12');
// Nowhere else: no PHP file and no other SQL carries these numbers as policy.
$numbers = 0;
foreach (glob(__DIR__ . '/../src/Admin/*.php') as $f) {
    if (preg_match('/lockout|bcrypt_cost|threshold/i', strip_php_comments(file_get_contents($f)))) { $numbers++; }
}
is_($numbers, 0, 'no PHP file under src/Admin carries a lockout or bcrypt number — the database is the only place');

$ins->exec("UPDATE mt_staff SET failed_attempts = 0, lock_count = 0, locked_until = NULL, last_failed_at = NULL WHERE username = 'alice'");
$lockedBefore = $auditCount('staff.locked');
$r = $routerFor($provider($tlsOn, false));
for ($i = 1; $i <= 4; $i++) {
    $res = $call($r, 'POST', '/api/v1/admin/session', ['username' => 'alice', 'password' => "wrong-{$i}-wrong"], $TLS);
    is_($res->status, 401, "failure {$i}: 401");
}
is_((int) $ins->one("SELECT failed_attempts FROM mt_staff WHERE username = 'alice'")['failed_attempts'], 4, 'four failures counted');
$res = $call($r, 'POST', '/api/v1/admin/session', ['username' => 'alice', 'password' => 'wrong-5-wrong'], $TLS);
is_($res->status, 401, 'the fifth failure is the same 401');
$row = $ins->one("SELECT failed_attempts, lock_count, locked_until > now() AS locked, locked_until - now() AS left_ FROM mt_staff WHERE username = 'alice'");
is_($row['locked'], true, 'and the account is now locked');
is_((int) $row['lock_count'], 1, 'lock_count 1');
is_($auditCount('staff.locked'), $lockedBefore + 1, 'the LOCK is audited (staff.locked) — the failures were not');
preg_match('/^(\d\d):(\d\d)/', (string) $row['left_'], $lm);
is_((int) $lm[1] * 60 + (int) $lm[2] >= 14 && (int) $lm[1] * 60 + (int) $lm[2] <= 15, true, 'for about 15 minutes');

$res = $call($r, 'POST', '/api/v1/admin/session', ['username' => 'alice', 'password' => $alice['password']], $TLS);
is_($res->status, 401, 'the RIGHT password is refused while locked');
is_($res->body, ['error' => 'invalid_credentials'], 'with the same body as any other refusal — the lock is not disclosed');
is_($ins->one('SELECT count(*)::int c FROM mt_staff_sessions WHERE staff_id = ? AND revoked_at IS NULL', [$alice['id']])['c'], 0,
    'and no session was created');

// Decay, not a permanent lock: when the window passes, the right password works again.
$ins->exec("UPDATE mt_staff SET locked_until = now() - interval '1 second' WHERE username = 'alice'");
$res = $call($r, 'POST', '/api/v1/admin/session', ['username' => 'alice', 'password' => $alice['password']], $TLS);
is_($res->status, 200, 'after the lock expires the right password signs in');
$row = $ins->one("SELECT failed_attempts, lock_count, locked_until FROM mt_staff WHERE username = 'alice'");
is_([(int) $row['failed_attempts'], (int) $row['lock_count'], $row['locked_until']], [0, 0, null],
    'and a success resets the counters and the lock history');
$tokAlice = $cookieOf($res);

// Doubling: a second lock in a sustained attack lasts twice as long; the ceiling holds.
$ins->exec("UPDATE mt_staff SET failed_attempts = 4, lock_count = 1, last_failed_at = now() WHERE username = 'alice'");
$call($r, 'POST', '/api/v1/admin/session', ['username' => 'alice', 'password' => 'wrong-again'], $TLS);
$left = $ins->one("SELECT extract(epoch FROM locked_until - now())::int AS s, lock_count FROM mt_staff WHERE username = 'alice'");
is_((int) $left['s'] > 29 * 60 && (int) $left['s'] <= 30 * 60, true, 'the second lock is 30 minutes (15 × 2^1)');
is_((int) $left['lock_count'], 2, 'lock_count 2');
$ins->exec("UPDATE mt_staff SET failed_attempts = 4, lock_count = 20, locked_until = NULL, last_failed_at = now() WHERE username = 'alice'");
$call($r, 'POST', '/api/v1/admin/session', ['username' => 'alice', 'password' => 'wrong-again'], $TLS);
$left = $ins->one("SELECT extract(epoch FROM locked_until - now())::int AS s FROM mt_staff WHERE username = 'alice'");
is_((int) $left['s'] <= 24 * 3600 && (int) $left['s'] > 24 * 3600 - 60, true, 'and the 21st lock is capped at the 24-hour ceiling — never permanent');

// Failure decay: stale failures do not accumulate into a lock.
$ins->exec("UPDATE mt_staff SET failed_attempts = 4, lock_count = 0, locked_until = NULL, last_failed_at = now() - interval '2 hours' WHERE username = 'alice'");
$call($r, 'POST', '/api/v1/admin/session', ['username' => 'alice', 'password' => 'wrong-again'], $TLS);
$row = $ins->one("SELECT failed_attempts, locked_until FROM mt_staff WHERE username = 'alice'");
is_([(int) $row['failed_attempts'], $row['locked_until']], [1, null],
    'four failures older than the decay window plus one new failure is ONE failure, not a lock');
$ins->exec("UPDATE mt_staff SET failed_attempts = 0, lock_count = 0, locked_until = NULL, last_failed_at = NULL WHERE username = 'alice'");

// ===========================================================================
t('7. THE SECOND FACTOR — required by default; a password-only session may only enrol');

$rq = $routerFor($provider($tlsOn, true));
$res = $call($rq, 'GET', '/api/v1/admin/session');
is_($res->body['second_factor'] ?? null, 'required', 'the login surface says the deployment requires a second factor (a deployment fact, not an account fact)');
is_($res->body['mode'] ?? null, 'credentials', 'and asks for credentials');
is_($res->body['roles'] ?? null, [], 'and offers no role to pick');

$res = $call($rq, 'POST', '/api/v1/admin/session', ['username' => 'alice', 'password' => $alice['password']], $TLS);
is_($res->status, 200, 'alice signs in with her password');
is_($res->body['identity']['second_factor'], ['pending' => true, 'enrolled' => false], 'but the session is PENDING its second factor');
is_($res->body['identity']['capabilities'], [], 'and carries no capability');
$tokPend = $cookieOf($res);
$res = $call($rq, 'GET', '/api/v1/admin/routers', [], $withCookie($tokPend));
is_($res->status, 403, 'an estate route is 403 while pending');
is_($res->body['error'], 'second_factor_required', 'named second_factor_required, distinct from forbidden');
$res = $call($rq, 'GET', '/api/v1/admin/staff', [], $withCookie($tokPend));
is_($res->body['error'] ?? null, 'second_factor_required', 'the roster too, even for an admin');
$res = $call($rq, 'POST', '/api/v1/admin/session/password', ['current' => $alice['password'], 'replacement' => 'a-perfectly-fine-new-one'], $withCookie($tokPend, $TLS));
is_($res->status, 403, 'and a password change is refused while pending');
$res = $call($rq, 'GET', '/api/v1/admin/session', [], $withCookie($tokPend));
is_($res->status, 200, 'GET /session still answers (the panel needs to know what to draw)');

$res = $call($rq, 'POST', '/api/v1/admin/session/totp', [], $withCookie($tokPend, $TLS));
is_($res->status, 200, 'enrolment begins');
$e = $res->body['enrolment'];
is_(preg_match('/^[A-Z2-7]{32}$/', $e['key']), 1, 'the key is 20 random bytes in base32');
is_(str_starts_with($e['uri'], 'otpauth://totp/'), true, 'and an otpauth URI is given for the app');
is_(str_contains($e['uri'], 'alice'), true, 'naming the account');
is_($auditCount('staff.totp_enrolled'), 0, 'starting enrolment audits nothing — no security state changed yet');
is_($ins->one('SELECT totp_confirmed_at FROM mt_staff WHERE id = ?', [$alice['id']])['totp_confirmed_at'], null, 'and the row is unconfirmed');

// An INDEPENDENT RFC 6238 implementation computes the code from the key the API returned.
$b32 = static function (string $k): string {
    $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = '';
    foreach (str_split($k) as $c) { $bits .= str_pad(decbin(strpos($a, $c)), 5, '0', STR_PAD_LEFT); }
    $out = '';
    foreach (str_split(substr($bits, 0, intdiv(strlen($bits), 8) * 8), 8) as $b) { $out .= chr(bindec($b)); }
    return $out;
};
$secret = $b32($e['key']);
is_(strlen($secret), 20, 'the key decodes to 20 bytes');
$totp = static function (int $step) use ($secret): string {
    $h = hash_hmac('sha1', pack('J', $step), $secret, true);
    $o = ord($h[19]) & 15;
    $b = ((ord($h[$o]) & 127) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return sprintf('%06d', $b % 1000000);
};
is_($ins->one("SELECT encode(totp_secret,'hex') AS s FROM mt_staff WHERE id = ?", [$alice['id']])['s'], bin2hex($secret),
    'CONTROL: the bytes the API returned are the bytes the database holds');

$res = $call($rq, 'POST', '/api/v1/admin/session/totp/confirm', ['code' => '000000'], $withCookie($tokPend, $TLS));
is_($res->status, 400, 'a wrong code is refused');
$res = $call($rq, 'POST', '/api/v1/admin/session/totp/confirm', ['code' => $totp(intdiv(time(), 30))], $withCookie($tokPend, $TLS));
is_($res->status, 200, 'the code an independent TOTP computes is accepted — the database implements RFC 6238');
is_($res->body['identity']['second_factor'], ['pending' => false, 'enrolled' => true], 'and the SAME session is now complete');
is_($auditCount('staff.totp_enrolled'), 1, 'confirmation is audited once');
$res = $call($rq, 'GET', '/api/v1/admin/routers', [], $withCookie($tokPend));
is_($res->status, 200, 'the estate route now answers');
$res = $call($rq, 'POST', '/api/v1/admin/session/totp', [], $withCookie($tokPend, $TLS));
is_($res->status, 409, 'enrolling again is refused: an administrator must reset it');

// From now on alice's password alone is not enough, and a code is accepted once.
$res = $call($rq, 'POST', '/api/v1/admin/session', ['username' => 'alice', 'password' => $alice['password']], $TLS);
is_($res->status, 401, 'password without a code: refused');
is_($res->body, ['error' => 'invalid_credentials'], 'with the same body as every other refusal');
$step = intdiv(time(), 30);
$res = $call($rq, 'POST', '/api/v1/admin/session', ['username' => 'alice', 'password' => $alice['password'], 'code' => $totp($step - 5)], $TLS);
is_($res->status, 401, 'a code from five steps ago: refused');
$res = $call($rq, 'POST', '/api/v1/admin/session', ['username' => 'alice', 'password' => $alice['password'], 'code' => $totp($step + 1)], $TLS);
is_($res->status, 200, 'a code from the next step (±1 window) is accepted');
is_($res->body['identity']['second_factor'], ['pending' => false, 'enrolled' => true], 'as a complete two-factor session');
$tokAlice2 = $cookieOf($res);
$res = $call($rq, 'POST', '/api/v1/admin/session', ['username' => 'alice', 'password' => $alice['password'], 'code' => $totp($step + 1)], $TLS);
is_($res->status, 401, 'REPLAY: the same code a second time is refused');
is_($res->body, ['error' => 'invalid_credentials'], 'indistinguishably');
$res = $call($rq, 'POST', '/api/v1/admin/session', ['username' => 'alice', 'password' => $alice['password'], 'code' => $totp($step)], $TLS);
is_($res->status, 401, 'and so is an OLDER step once a newer one was accepted');

// ===========================================================================
t('8. DISABLE REVOKES — and resolve re-reads status live even for a row nothing revoked');

$ro = $routerFor($provider($tlsOn, false));
$res = $call($ro, 'POST', '/api/v1/admin/session', ['username' => 'bob', 'password' => $bob['password']], $TLS);
$tokB1 = $cookieOf($res);
$res = $call($ro, 'POST', '/api/v1/admin/session', ['username' => 'bob', 'password' => $bob['password']], $TLS);
$tokB2 = $cookieOf($res);
is_($ins->one('SELECT count(*)::int c FROM mt_staff_sessions WHERE staff_id = ? AND revoked_at IS NULL', [$bob['id']])['c'], 2,
    'CONTROL: bob has two live sessions');
is_($call($ro, 'GET', '/api/v1/admin/routers', [], $withCookie($tokB1))->status, 200, 'CONTROL: the first works');
$disBefore = $auditCount('staff.disabled');
// Through the ROUTE, as alice (admin), so the actor is the authenticated subject.
$res = $call($rq, 'POST', '/api/v1/admin/staff/' . $bob['id'] . '/disable', [], $withCookie($tokAlice2, $TLS));
is_($res->status, 200, 'alice disables bob through the roster route');
is_($res->body['disabled'], true, 'and it reports done');
foreach ([$tokB1, $tokB2] as $i => $t) {
    is_($call($ro, 'GET', '/api/v1/admin/routers', [], $withCookie($t))->status, 401, 'bob\'s session ' . ($i + 1) . ' is refused at once');
}
is_($ins->one('SELECT count(*)::int c FROM mt_staff_sessions WHERE staff_id = ? AND revoked_at IS NULL', [$bob['id']])['c'], 0,
    'both rows were revoked in the same transaction');
is_($auditCount('staff.disabled'), $disBefore + 1, 'audited once');
$row = $ins->one("SELECT actor, actor_kind, detail FROM mt_audit_log WHERE action = 'staff.disabled' ORDER BY at DESC LIMIT 1");
is_($row['actor'], 'alice', 'the actor is the authenticated subject, alice');
is_($row['actor_kind'], 'staff', "actor_kind 'staff'");
is_(json_decode($row['detail'], true)['sessions_revoked'], 2, 'and the row says how many sessions it ended');
$res = $call($ro, 'POST', '/api/v1/admin/session', ['username' => 'bob', 'password' => $bob['password']], $TLS);
is_($res->status, 401, 'a disabled person cannot sign in');
is_($res->body, ['error' => 'invalid_credentials'], 'and learns nothing');
$res = $call($rq, 'POST', '/api/v1/admin/staff/' . $bob['id'] . '/enable', [], $withCookie($tokAlice2, $TLS));
is_($res->body['enabled'] ?? null, true, 're-enabled');
$res = $call($ro, 'POST', '/api/v1/admin/session', ['username' => 'bob', 'password' => $bob['password']], $TLS);
is_($res->status, 200, 'and can sign in again');
$tokB3 = $cookieOf($res);
// The LIVE check: flip status under a session row that nothing revoked.
$ins->exec("UPDATE mt_staff SET status = 'disabled' WHERE id = ?", [$bob['id']]);
is_($ins->one('SELECT revoked_at FROM mt_staff_sessions WHERE token_hash = ?', [$tokens->hash($tokB3)])['revoked_at'], null,
    'CONTROL: the session row is still unrevoked');
is_($call($ro, 'GET', '/api/v1/admin/session', [], $withCookie($tokB3))->status, 401,
    'yet resolve refuses it — status is re-read live on every request');
$ins->exec("UPDATE mt_staff SET status = 'active' WHERE id = ?", [$bob['id']]);
is_($call($ro, 'GET', '/api/v1/admin/session', [], $withCookie($tokB3))->status, 200, 'CONTROL: and admits it again');

t('8b. role change, password reset and authenticator reset each revoke — and the last admin is protected');
$res = $call($rq, 'POST', '/api/v1/admin/staff/' . $bob['id'] . '/role', ['role' => 'sales'], $withCookie($tokAlice2, $TLS));
is_($res->body['changed'] ?? null, true, 'bob becomes sales');
is_($call($ro, 'GET', '/api/v1/admin/session', [], $withCookie($tokB3))->status, 401, 'and his live session is ended');
$res = $call($rq, 'POST', '/api/v1/admin/staff/' . $bob['id'] . '/role', ['role' => 'sales'], $withCookie($tokAlice2, $TLS));
is_($res->body['changed'] ?? null, false, 'the same role again is a no-op (no audit row either)');
$res = $call($rq, 'POST', '/api/v1/admin/staff/' . $bob['id'] . '/role', ['role' => 'owner'], $withCookie($tokAlice2, $TLS));
is_($res->status, 400, "'owner' is not a DishNet staff role — that word belongs to the operator plane");
$res = $call($rq, 'POST', '/api/v1/admin/staff/' . $bob['id'] . '/password', [], $withCookie($tokAlice2, $TLS));
is_($res->status, 200, 'an admin resets a password');
is_(preg_match('/^[a-z2-9]{6}(-[a-z2-9]{6}){3}$/', $res->body['password']), 1, 'and receives a GENERATED one, once');
$bobPw2 = $res->body['password'];
is_($ins->one("SELECT count(*)::int c FROM mt_audit_log WHERE detail::text LIKE ?", ['%' . $bobPw2 . '%'])['c'], 0,
    'the new password appears in no audit row');
is_($call($ro, 'POST', '/api/v1/admin/session', ['username' => 'bob', 'password' => $bob['password']], $TLS)->status, 401, 'the old password is dead');
$res = $call($ro, 'POST', '/api/v1/admin/session', ['username' => 'bob', 'password' => $bobPw2], $TLS);
is_($res->status, 200, 'the new one works');
$tokB4 = $cookieOf($res);
$res = $call($rq, 'POST', '/api/v1/admin/staff/' . $alice['id'] . '/disable', [], $withCookie($tokAlice2, $TLS));
is_($res->status, 409, 'alice cannot disable herself');
is_($res->body['detail'], 'a staff member cannot disable itself', 'and is told so');
$res = $call($rq, 'POST', '/api/v1/admin/staff/' . $alice['id'] . '/role', ['role' => 'noc'], $withCookie($tokAlice2, $TLS));
is_($res->status, 409, 'the last active admin cannot be demoted');
is_($res->body['detail'], 'the last active admin cannot be demoted', 'and is told so');
is_($ins->one("SELECT count(*)::int c FROM mt_staff WHERE role = 'admin' AND status = 'active'")['c'], 1,
    'CONTROL: alice is the only active admin, so the guard had something to protect');
$res = $call($rq, 'POST', '/api/v1/admin/staff/' . $alice['id'] . '/totp-reset', [], $withCookie($tokAlice2, $TLS));
is_($res->status, 200, 'an admin clears an authenticator');
is_($res->body['reset'], true, 'done');
is_($call($rq, 'GET', '/api/v1/admin/session', [], $withCookie($tokAlice2))->status, 401,
    'and her own sessions are revoked by it — including the one that asked');
is_($ins->one('SELECT totp_secret FROM mt_staff WHERE id = ?', [$alice['id']])['totp_secret'], null, 'the secret is gone from the row');

t('8c. self-service password change revokes every OTHER session and needs the current password');
$res = $call($ro, 'POST', '/api/v1/admin/session', ['username' => 'bob', 'password' => $bobPw2], $TLS);
$tokB5 = $cookieOf($res);
$res = $call($ro, 'POST', '/api/v1/admin/session/password', ['current' => 'not-it-at-all', 'replacement' => 'a-brand-new-long-one'], $withCookie($tokB5, $TLS));
is_($res->status, 401, 'a wrong current password is refused');
is_($res->body, ['error' => 'invalid_credentials'], 'with the login refusal body');
$res = $call($ro, 'POST', '/api/v1/admin/session/password', ['current' => $bobPw2, 'replacement' => 'short'], $withCookie($tokB5, $TLS));
is_($res->status, 409, 'a short replacement is refused by the database');
$res = $call($ro, 'POST', '/api/v1/admin/session/password', ['current' => $bobPw2, 'replacement' => 'a-brand-new-long-one'], $withCookie($tokB5, $TLS));
is_($res->status, 200, 'the change succeeds');
is_($call($ro, 'GET', '/api/v1/admin/session', [], $withCookie($tokB5))->status, 200, 'the session that changed it continues');
is_($call($ro, 'GET', '/api/v1/admin/session', [], $withCookie($tokB4))->status, 401, 'every OTHER session of bob\'s is ended');
is_($auditCount('staff.password_changed'), 1, 'audited once');

// ===========================================================================
t('9. AUDIT — one row per act, none on refusal, staff actor kind, and no secret anywhere in detail');

$rows = $ins->query("SELECT action, actor, actor_kind, customer_id, target_type, detail FROM mt_audit_log
                      WHERE action LIKE 'staff.%' ORDER BY at OFFSET ?", [$auditBefore]);
is_(count($rows) > 15, true, count($rows) . ' staff audit rows were written by this file');
$kinds = array_unique(array_column($rows, 'actor_kind'));
is_($kinds, ['staff'], "every one is actor_kind='staff'");
is_(array_unique(array_column($rows, 'customer_id')), [null], 'and none carries a customer');
is_(array_unique(array_column($rows, 'target_type')), ['staff'], 'and every target is a staff row');
$actions = array_unique(array_column($rows, 'action'));
sort($actions);
is_($actions, ['staff.created', 'staff.disabled', 'staff.enabled', 'staff.locked', 'staff.login', 'staff.logout',
               'staff.password_changed', 'staff.password_reset', 'staff.role_changed', 'staff.totp_enrolled', 'staff.totp_reset'],
    'the eleven audited acts — and NOT a failed login, which is a counter, not an event');
$allDetail = implode("\n", array_column($rows, 'detail'));
foreach ([$alice['password'], $bob['password'], $bobPw2, 'a-brand-new-long-one', $doraPw, $e['key'], bin2hex($secret)] as $needle) {
    is_(str_contains($allDetail, $needle), false, 'no audit detail carries a password, key or secret');
}
// As KEYS: the login row legitimately records factor = "password".
foreach (['password_hash', 'totp_secret', 'token_hash', '"password":', '"token":', '"secret":', '"key":', '"code":'] as $key) {
    is_(str_contains($allDetail, $key), false, "no audit detail carries a {$key} field");
}
is_(str_contains($allDetail, '"factor": "password"') || str_contains($allDetail, '"factor":"password"'), true,
    'CONTROL: the login rows do record the factor, so the scan reads real detail');
foreach ([$tokAlice2, $tokB5, $tokPend] as $t) {
    is_(str_contains($allDetail, $t), false, 'no audit detail carries a session token');
    is_(str_contains($allDetail, $tokens->hash($t)), false, 'nor its hash');
}
is_($ins->one("SELECT actor_kind FROM mt_audit_log WHERE action = 'staff.login' ORDER BY at DESC LIMIT 1")['actor_kind'], 'staff', 'CONTROL: the string search scans rows that exist');
$chk = $ins->one("SELECT pg_get_constraintdef(oid) AS d FROM pg_constraint WHERE conrelid = 'mt_audit_log'::regclass AND contype = 'c' AND pg_get_constraintdef(oid) LIKE '%actor_kind%'")['d'];
is_(preg_match_all("/'([a-z]+)'/", $chk, $ak) === 3 && !in_array('guest', $ak[1], true), true,
    'actor_kind is still exactly three values — no kind was added for DishNet staff');
// Every login role is still refused a direct audit INSERT (A-1 stays closed), while the definer can write one.
foreach ($pluginLogin as $role) {
    is_($ins->one('SELECT has_table_privilege(?,?,?) AS p', [$role, 'mt_audit_log', 'INSERT'])['p'], false, "{$role} still cannot INSERT mt_audit_log");
}
is_($ins->one("SELECT has_function_privilege('dnb_def_staff','mt_audit_write(uuid,text,text,text,text,text,text,jsonb)','EXECUTE') AS p")['p'], true,
    'CONTROL: dnb_def_staff may call mt_audit_write, which is how every row above got there');

// ===========================================================================
t('10. PROVIDER SELECTION — explicit, no fallback, no coexistence, and the real one works under real bindings');

$env = static function (array $vars, callable $fn) {
    $saved = [];
    foreach ($vars as $k => $v) { $saved[$k] = getenv($k); putenv($v === null ? $k : "{$k}={$v}"); }
    try { return $fn(); }
    finally { foreach ($saved as $k => $v) { putenv($v === false ? $k : "{$k}={$v}"); } }
};
$base = ['DN_STAFF_IDENTITY' => null, 'DN_DEV_STAFF_IDENTITY' => null, Bindings::REAL_GATE_ENV => null,
         'DN_STAFF_REQUIRE_TOTP' => null, 'DN_TRUSTED_PROXY' => null, 'DN_PORTAL_ORIGIN' => null,
         'DNB_TOKEN_PEPPER' => 'staff-suite-pepper'];

$with = static fn(array $o) => array_merge($base, $o);
[$i0, $s0, $a0] = $env($base, fn() => StaffIdentityFactory::fromEnvironment());
is_($i0 instanceof DenyAllIdentity, true, 'unset: DenyAllIdentity');
is_([$s0, $a0], [null, null], 'and nothing to mint with, nothing to manage staff with');

[$i1, $s1, $a1] = $env($with(['DN_STAFF_IDENTITY' => 'dishnet']), fn() => StaffIdentityFactory::fromEnvironment());
is_($i1 instanceof DishnetStaffIdentity, true, "'dishnet': the real provider");
is_($s1 === $i1 && $a1 instanceof StaffAdmin, true, 'which issues sessions and manages staff');
is_($i1->requiresSecondFactor(), true, 'requiring a second factor by default');
[$i2] = $env($with(['DN_STAFF_IDENTITY' => 'dishnet', 'DN_STAFF_REQUIRE_TOTP' => 'no']), fn() => StaffIdentityFactory::fromEnvironment());
is_($i2->requiresSecondFactor(), false, "DN_STAFF_REQUIRE_TOTP=no relaxes it (development only; the doctor blocks it)");

throws_(fn() => $env($with(['DN_STAFF_IDENTITY' => 'dishnet', 'DN_STAFF_REQUIRE_TOTP' => 'maybe']), fn() => StaffIdentityFactory::fromEnvironment()),
    'must be unset', 'a typo in DN_STAFF_REQUIRE_TOTP refuses to start rather than guessing');
throws_(fn() => $env($with(['DN_STAFF_IDENTITY' => 'ldap']), fn() => StaffIdentityFactory::fromEnvironment()),
    'refusing to start', 'an unknown provider name refuses to start — it does not fall back to deny-all');
throws_(fn() => $env($with(['DN_STAFF_IDENTITY' => 'dishnet', 'DN_DEV_STAFF_IDENTITY' => 'yes-development-only']),
                     fn() => StaffIdentityFactory::fromEnvironment()),
    'refuse to coexist', 'the development identity and the real provider REFUSE TO COEXIST');
[$i3, $s3] = $env($with(['DN_DEV_STAFF_IDENTITY' => 'yes-development-only']), fn() => StaffIdentityFactory::fromEnvironment());
is_($i3 instanceof DevSessionIdentity && $s3 === $i3, true, 'the development gate alone still binds the development identity, as before 026');

// The decisive pair: under the real-bindings gate the dev identity throws and the real one WORKS.
$gate = $with([Bindings::REAL_GATE_ENV => Bindings::REAL_GATE_VALUE, 'DN_TRUSTED_PROXY' => '127.0.0.1']);
$env($gate, function () use ($withCookie, $call, $routerFor) {
    is_(Bindings::realBindingsAllowed(), true, 'CONTROL: the real-bindings gate is genuinely open');
    putenv('DN_DEV_STAFF_IDENTITY=yes-development-only');
    throws_(fn() => new DevSessionIdentity(new \Dn\Admin\AdminSession('k')), 'real bindings',
        'DevSessionIdentity throws under it');
    throws_(fn() => StaffIdentityFactory::fromEnvironment(), 'real bindings', 'and cannot be reached through the factory either');
    putenv('DN_DEV_STAFF_IDENTITY');
    putenv('DN_STAFF_IDENTITY=dishnet');
    [$i4] = StaffIdentityFactory::fromEnvironment();
    is_($i4 instanceof DishnetStaffIdentity, true, 'the real provider constructs under the gate');
    $r = $routerFor($i4);
    $res = $call($r, 'POST', '/api/v1/admin/session', ['username' => 'bob', 'password' => 'a-brand-new-long-one'], ['X-Forwarded-Proto' => 'https']);
    is_($res->status, 200, 'and signs somebody in under it — the real provider is not gated on F6-B');
    preg_match('/=([0-9a-f]{64})/', $res->headers['Set-Cookie'] ?? '', $gm);
    $call($r, 'DELETE', '/api/v1/admin/session', [], $withCookie($gm[1] ?? '', ['X-Forwarded-Proto' => 'https']));
    putenv('DN_STAFF_IDENTITY');
});
is_(Bindings::realBindingsAllowed(), false, 'CONTROL: the gate is closed again');

// The entry point uses the factory and never catches its way to a fallback.
$entry   = strip_php_comments((string) file_get_contents(__DIR__ . '/../plugin/public/api.php'));
$factory = strip_php_comments((string) file_get_contents(__DIR__ . '/../src/Admin/StaffIdentityFactory.php'));
is_(str_contains($entry, 'StaffIdentityFactory::fromEnvironment()'), true, 'plugin/public/api.php chooses through the factory');
is_(preg_match('/catch[^}]*(DenyAllIdentity|DevSessionIdentity|DishnetStaffIdentity)/s', $entry . $factory), 0,
    'and neither it nor the factory catches a failure to bind a different provider');
is_(preg_match('/catch\s*\(\\\\Throwable[^}]*500/s', $entry), 1, 'a failed provider is a 500 on every request, loudly');
is_(str_contains($factory, 'new DenyAllIdentity()') && str_contains($factory, 'new DevSessionIdentity(') && str_contains($factory, 'new DishnetStaffIdentity('), true,
    'CONTROL: the factory is where all three are constructed');

// ===========================================================================
t('11. THE ROUTES NEVER TAKE AUTHORITY FROM THE BROWSER — scanned in code, and proved by execution');

$routesCode = strip_php_comments((string) file_get_contents(__DIR__ . '/../src/Api/AdminRoutes.php'));
$providerCode = strip_php_comments((string) file_get_contents(__DIR__ . '/../src/Admin/DishnetStaffIdentity.php'));
$adminCode = strip_php_comments((string) file_get_contents(__DIR__ . '/../src/Admin/StaffAdmin.php'));
// The functions that take an ACTOR are called only from StaffAdmin, only with its $actor parameter.
$actorFns = 'mt_staff_(bootstrap|create|disable|enable|set_role|reset_password|totp_reset)';
is_(preg_match("/{$actorFns}\\(/", $routesCode . $providerCode), 0,
    'no actor-taking staff function is called from a route or from the provider');
preg_match_all("/{$actorFns}\\([^)]*\\)[^;]{0,200};/s", $adminCode, $sites);
is_(count($sites[0]), 7, 'StaffAdmin calls the seven actor-taking functions');
foreach ($sites[0] as $site) {
    is_(str_contains($site, '$actor]'), true, 'and passes $actor — its own parameter — last: ' . trim(substr($site, 0, 50)));
}
is_(preg_match('/req->body/', $adminCode), 0, 'StaffAdmin never sees a request at all');
is_(preg_match_all('/\$s->subject/', $routesCode) >= 6, true, 'the roster routes pass $s->subject — the authenticated identity — as the actor');
// The credential-taking functions take the credential from the body — that is what a credential is —
// and never an actor or a role.
is_(preg_match('/mt_staff_(login|change_password)\([^;]{0,300}body\[.(role|actor|username_override)/s', $providerCode), 0,
    'login and password change take no role or actor from the body');
is_(str_contains($providerCode, "body['role']") || str_contains($providerCode, 'body["role"]'), false,
    'the real provider never reads a role from the request');
foreach (['customer_id', 'operator_id', 'mt_current_customer', 'app.customer_id', 'TenantContext'] as $n) {
    is_(str_contains($providerCode . $adminCode, $n), false, "the identity code never touches {$n} — the Admin plane sets no tenant context");
}
is_(str_contains($providerCode, 'mt_principals'), false, 'and never touches mt_principals (T-2 is a separate migration)');
// Execution: a support member cannot reach the roster whatever the request claims.
$res = $call($ro, 'POST', '/api/v1/admin/session', ['username' => 'dora', 'password' => $doraPw, 'role' => 'admin'], $TLS);
is_($res->status, 401, 'dora is still disabled, so a claimed role gets nothing');
$admin->enable($dora['id'], 'alice');
$res = $call($ro, 'POST', '/api/v1/admin/session', ['username' => 'dora', 'password' => $doraPw, 'role' => 'admin'], $TLS);
is_($res->body['identity']['role'] ?? null, 'support', 'enabled, dora signs in as support whatever the body claimed');
$tokDora = $cookieOf($res);
$res = $call($ro, 'POST', '/api/v1/admin/staff', ['username' => 'eve', 'display_name' => 'Eve', 'role' => 'admin'], $withCookie($tokDora, $TLS));
is_($res->status, 403, 'and cannot create an admin');
is_($ins->one("SELECT count(*)::int c FROM mt_staff WHERE username = 'eve'")['c'], 0, 'nothing was created');
foreach (['disable', 'enable', 'role', 'password', 'totp-reset'] as $verb) {
    $res = $call($ro, 'POST', '/api/v1/admin/staff/' . $alice['id'] . '/' . $verb, ['role' => 'noc'], $withCookie($tokDora, $TLS));
    is_($res->status, 403, "support cannot {$verb} anyone");
}
is_(StaffRole::Admin->can(Capability::STAFF_MANAGE) && !StaffRole::Noc->can(Capability::STAFF_MANAGE)
    && !StaffRole::Sales->can(Capability::STAFF_MANAGE) && !StaffRole::Support->can(Capability::STAFF_MANAGE), true,
    'staff.manage is carried by Admin and by nobody else (D-AUTH-7)');
is_(in_array(Capability::STAFF_MANAGE, AdminRoutes::declaredCapabilities(), true), true, 'and is declared for the guard test');

t('11b. the roster projection withholds every secret and the development identity cannot reach the roster at all');
$res = $call($rq, 'POST', '/api/v1/admin/session', ['username' => 'alice', 'password' => $alice['password']], $TLS);
is_($res->body['identity']['second_factor']['pending'] ?? null, true, 'alice (authenticator cleared) is pending again');
$rNo = $routerFor($provider($tlsOn, false));
$res = $call($rNo, 'POST', '/api/v1/admin/session', ['username' => 'alice', 'password' => $alice['password']], $TLS);
$tokA3 = $cookieOf($res);
$res = $call($rNo, 'GET', '/api/v1/admin/staff', [], $withCookie($tokA3));
is_($res->status, 200, 'alice reads the roster');
$blob = json_encode($res->body);
foreach (['password_hash', 'totp_secret', 'token_hash', '$2', 'failed_attempts', 'locked_until'] as $f) {
    is_(str_contains($blob, $f), false, "the roster carries no {$f}");
}
is_(count($res->body['staff']), 3, 'three people: alice, bob, dora');
is_(array_column($res->body['staff'], 'username'), ['alice', 'bob', 'dora'], 'in username order');
putenv('DN_DEV_STAFF_IDENTITY=yes-development-only');
$dev = new DevSessionIdentity(new \Dn\Admin\AdminSession('login-suite-key-only'));
$rDev = AdminRoutes::build($dev, Bindings::defaults(), $reader, $dev, null);
$res = $call($rDev, 'POST', '/api/v1/admin/session', ['role' => 'admin'], $TLS);
preg_match('/' . \Dn\Admin\AdminSession::COOKIE . '=([^;]+)/', $res->headers['Set-Cookie'] ?? '', $dm);
$res = $call($rDev, 'GET', '/api/v1/admin/staff', [], ['Cookie' => \Dn\Admin\AdminSession::COOKIE . '=' . $dm[1]]);
is_($res->status, 501, 'a development ADMIN gets 501 from the roster: staff management is bound only under the real provider');
is_($res->body['error'], 'staff_management_unavailable', 'and is told why');
$res = $call($rDev, 'POST', '/api/v1/admin/session/totp', [], ['Cookie' => \Dn\Admin\AdminSession::COOKIE . '=' . $dm[1]] + $TLS);
is_($res->status, 501, 'and has no authenticator to enrol');
putenv('DN_DEV_STAFF_IDENTITY');

// ===========================================================================
t('12. OVER REAL HTTP — the packaged server, the real entry point, the real provider');

$port = 58600 + (getmypid() % 300);
$root = dirname(__DIR__);
$envs = ['DNB_DSN' => getenv('DNB_DSN'), 'DNB_STAFFAUTH_PASS' => getenv('DNB_STAFFAUTH_PASS'),
         'DNB_ADMINWRITE_PASS' => getenv('DNB_ADMINWRITE_PASS'), 'DNB_ADMINAPI_PASS' => getenv('DNB_ADMINAPI_PASS'),
         'DNB_TOKEN_PEPPER' => 'staff-suite-pepper', 'DN_STAFF_IDENTITY' => 'dishnet',
         'DN_TRUSTED_PROXY' => '127.0.0.1', 'DN_STAFF_REQUIRE_TOTP' => 'no'];
$envStr = implode(' ', array_map(static fn($k, $v) => $k . '=' . escapeshellarg((string) $v), array_keys($envs), $envs));
$log = tempnam(sys_get_temp_dir(), 'dnb-staff');
$pid = (int) shell_exec("{$envStr} php -S 127.0.0.1:{$port} " . escapeshellarg($root . '/plugin/bin/serve.php') . " > {$log} 2>&1 & echo $!");
$up = false;
for ($i = 0; $i < 100; $i++) {
    $s = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
    if ($s) { fclose($s); $up = true; break; }
    usleep(50_000);
}
register_shutdown_function(static function () use ($pid, $log) {
    if ($pid > 0) { @shell_exec("kill {$pid} 2>/dev/null"); }
    @unlink($log);
});
is_($up, true, "php -S is serving plugin/bin/serve.php on {$port}");
$http = static function (string $method, string $path, array $body = [], array $hdr = []) use ($port): array {
    $h = ['Accept: application/json'];
    if ($body !== []) { $h[] = 'Content-Type: application/json'; }
    foreach ($hdr as $k => $v) { $h[] = "{$k}: {$v}"; }
    $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $h),
        'content' => $body ? json_encode($body) : '', 'ignore_errors' => true, 'timeout' => 10]]);
    $raw = @file_get_contents("http://127.0.0.1:{$port}{$path}", false, $ctx);
    $status = 0; $headers = [];
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) { $status = (int) $m[1]; continue; }
        if (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $headers[strtolower(trim($k))] = trim($v); }
    }
    return ['status' => $status, 'headers' => $headers, 'body' => $raw ? (json_decode($raw, true) ?? []) : []];
};
if ($up) {
    $r = $http('GET', '/api/v1/admin/session');
    is_($r['status'], 401, 'GET /session: 401');
    is_($r['body']['provider'] ?? null, 'dishnet', 'the real provider answered over the socket');
    is_($r['body']['mode'] ?? null, 'credentials', 'and asks for credentials');
    $r = $http('POST', '/api/v1/admin/session', ['username' => 'bob', 'password' => 'a-brand-new-long-one']);
    is_($r['status'], 403, 'over plain HTTP with no proxy header: 403');
    is_($r['body']['error'] ?? null, 'insecure_transport', 'insecure_transport — the wire path enforces it too');
    $r = $http('POST', '/api/v1/admin/session', ['username' => 'bob', 'password' => 'wrong-on-the-wire'], ['X-Forwarded-Proto' => 'https']);
    is_($r['status'], 401, 'a wrong password over the wire: 401');
    $r = $http('POST', '/api/v1/admin/session', ['username' => 'bob', 'password' => 'a-brand-new-long-one'], ['X-Forwarded-Proto' => 'https']);
    is_($r['status'], 200, 'the right one, asserted TLS by the trusted proxy: 200');
    preg_match('/' . StaffToken::COOKIE . '=([0-9a-f]{64})/', $r['headers']['set-cookie'] ?? '', $wm);
    $wire = $wm[1] ?? '';
    is_(strlen($wire), 64, 'a cookie arrived through the socket');
    is_(str_contains($r['headers']['set-cookie'] ?? '', 'Secure') && str_contains($r['headers']['set-cookie'] ?? '', 'HttpOnly'), true, 'Secure and HttpOnly');
    is_($r['headers']['cache-control'] ?? '', 'no-store', 'and the response is no-store');
    $r = $http('GET', '/api/v1/admin/routers', [], ['Cookie' => StaffToken::COOKIE . '=' . $wire]);
    is_($r['status'], 200, 'an estate read answers with the cookie');
    $r = $http('GET', '/api/v1/admin/staff', [], ['Cookie' => StaffToken::COOKIE . '=' . $wire]);
    is_($r['status'], 403, 'the roster is 403 for sales over the wire');
    $r = $http('DELETE', '/api/v1/admin/session', [], ['Cookie' => StaffToken::COOKIE . '=' . $wire, 'X-Forwarded-Proto' => 'https']);
    is_($r['status'], 204, 'logout: 204');
    $r = $http('GET', '/api/v1/admin/routers', [], ['Cookie' => StaffToken::COOKIE . '=' . $wire]);
    is_($r['status'], 401, 'and the replayed cookie is refused — revoked on the server, not just cleared in the browser');
    $r = $http('GET', '/');
    is_($r['status'], 200, 'the panel is served on the same origin');
    $r = $http('GET', '/staff.js');
    is_($r['status'], 200, 'and so is the identity-plane client');
    $errs = (string) file_get_contents($log);
    is_(str_contains($errs, 'PHP Fatal') || str_contains($errs, 'Uncaught'), false, 'the server logged no fatal error');
}

t('12b. a misconfigured real provider is a LOUD 500, never a quiet deny-all');
$port2 = $port + 1;
$bad = str_replace("DNB_STAFFAUTH_PASS=" . escapeshellarg((string) getenv('DNB_STAFFAUTH_PASS')), 'DNB_STAFFAUTH_PASS=', $envStr);
$bad = preg_replace('/DN_STAFF_IDENTITY=\S+/', "DN_STAFF_IDENTITY='dishnet' DN_DEV_STAFF_IDENTITY='yes-development-only'", $bad);
$log2 = tempnam(sys_get_temp_dir(), 'dnb-staff2');
$pid2 = (int) shell_exec("{$bad} php -S 127.0.0.1:{$port2} " . escapeshellarg($root . '/plugin/bin/serve.php') . " > {$log2} 2>&1 & echo $!");
$up2 = false;
for ($i = 0; $i < 100; $i++) { $s = @fsockopen('127.0.0.1', $port2, $e1, $e2, 0.2); if ($s) { fclose($s); $up2 = true; break; } usleep(50_000); }
register_shutdown_function(static function () use ($pid2, $log2) { if ($pid2 > 0) { @shell_exec("kill {$pid2} 2>/dev/null"); } @unlink($log2); });
if ($up2) {
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'ignore_errors' => true, 'timeout' => 10]]);
    $raw = @file_get_contents("http://127.0.0.1:{$port2}/api/v1/admin/session", false, $ctx);
    preg_match('#^HTTP/\S+\s+(\d{3})#', $http_response_header[0] ?? '', $sm);
    is_((int) ($sm[1] ?? 0), 500, 'dev gate + dishnet in one process: every request is 500');
    is_(json_decode((string) $raw, true)['error'] ?? null, 'identity_provider_unavailable', 'named identity_provider_unavailable');
    usleep(100_000);
    is_(str_contains((string) file_get_contents($log2), 'refuse to coexist'), true, 'and the reason reached the log');
}

// ===========================================================================
t('13. THE W-3 CONTRACT, UPDATED DELIBERATELY — the write role reaches the staff functions and still no table');
is_($ins->query("SELECT 1 FROM information_schema.role_table_grants WHERE grantee = 'dnb_adminwrite'"), [],
    'dnb_adminwrite still holds zero table privileges after 026');
$grantedToWrite = array_column($ins->query(
    "SELECT p.proname FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
      WHERE n.nspname = 'public' AND p.proname LIKE 'mt_staff%'
        AND has_function_privilege('dnb_adminwrite', p.oid, 'EXECUTE') ORDER BY 1"), 'proname');
is_($grantedToWrite, ['mt_staff_bootstrap', 'mt_staff_change_password', 'mt_staff_create', 'mt_staff_disable', 'mt_staff_enable',
                      'mt_staff_policy', 'mt_staff_reset_password', 'mt_staff_set_role', 'mt_staff_totp_confirm',
                      'mt_staff_totp_enrol', 'mt_staff_totp_reset'],
    'exactly the lifecycle and self-service functions, plus the constants — not login, resolve or logout');

exit(t_summary());
