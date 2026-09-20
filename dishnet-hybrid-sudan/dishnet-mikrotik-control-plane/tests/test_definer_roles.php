<?php
declare(strict_types=1);
/**
 * Audit finding F2 (docs/58 §4, §13) — the superuser-owner dependency.
 *
 * The finding was not that two operations failed. It was that ELEVEN SECURITY
 * DEFINER functions silently did nothing and returned a success-shaped answer
 * when the owner was not a superuser: logins that never succeed, RADIUS
 * packets discarded, a worker reporting an empty queue forever.
 *
 * So the assertions here are mostly SIDE-EFFECT assertions. Checking that a
 * call did not raise would have passed against the broken system.
 */
require __DIR__ . '/bootstrap.php';
use Dn\Db\Database;
use Dn\Tenancy\TenantContext;

putenv('DNB_SECRET_KEY=test-key-for-suite-only');

$inspect = Database::inspector();
$owner   = Database::owner();
$app     = Database::app();    $ctx = new TenantContext($app);
$admin   = Database::admin();
$work    = Database::worker();

$ids = seed_two_customers($inspect);
$A = $ids['A']; $B = $ids['B'];

// ===========================================================================
t('F2 — no role in the runtime path is a superuser or bypasses RLS');
foreach (['dnb','dnb_app','dnb_worker','dnb_admin',
          'dnb_def_auth','dnb_def_net','dnb_def_work','dnb_def_prov'] as $r) {
    $x = $inspect->one('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = ?', [$r]);
    is_([$x['rolsuper'], $x['rolbypassrls']], [false, false],
        "{$r}: not superuser, no BYPASSRLS");
}
is_($owner->one('SELECT current_user AS u')['u'], 'dnb', 'the owner connection really is the owner');

t('F2 — the four function-owner roles cannot be connected to or created with');
foreach (['dnb_def_auth','dnb_def_net','dnb_def_work','dnb_def_prov'] as $r) {
    is_($inspect->one('SELECT rolcanlogin FROM pg_roles WHERE rolname = ?', [$r])['rolcanlogin'],
        false, "{$r} cannot log in");
    is_($inspect->one('SELECT has_schema_privilege(?, ?, ?) AS p', [$r, 'public', 'CREATE'])['p'],
        false, "{$r} cannot create objects — it could otherwise mint its own entry point");
}

t('F2 — no application role can assume another, or a definer role');
$members = $inspect->query(
    "SELECT r.rolname AS member, g.rolname AS grp FROM pg_auth_members m
       JOIN pg_roles r ON r.oid=m.member JOIN pg_roles g ON g.oid=m.roleid
      WHERE r.rolname IN ('dnb_app','dnb_worker','dnb_admin')");
is_($members, [], 'app, worker and admin are members of nothing at all');
foreach (['dnb_worker','dnb_def_auth','dnb_def_prov'] as $target) {
    throws_(fn() => $app->pdo()->exec("SET ROLE {$target}"), '',
        "the request role cannot SET ROLE to {$target}");
}

t('F2 — the OWNER is subject to RLS, which is what §11.4 claimed and could not deliver');
is_(count($owner->query('SELECT 1 FROM mt_customers')), 0, 'the owner reads no customers');
is_(count($owner->query('SELECT 1 FROM mt_device_secrets')), 0, 'and no device secrets');
throws_(fn() => $owner->exec("INSERT INTO mt_customers (name) VALUES ('by the owner')"),
    'row-level security', 'and cannot create a customer by hand');

// ===========================================================================
t('F2 — the authentication path WORKS, proven by side effect');
$phone = '+256700001001';   // seed_two_customers gives A's principal this number
$inspect->exec('TRUNCATE mt_auth_codes CASCADE');
$code = $app->one('SELECT mt_auth_issue_code(?,?,?::interval) AS id',
                  [$phone, 'hash-f2', '10 minutes'])['id'];
is_($code !== null, true, 'issue_code returned an id');
is_((int) $inspect->one('SELECT count(*) AS n FROM mt_auth_codes')['n'], 1,
    'AND A ROW EXISTS — the old failure returned an id-shaped answer and wrote nothing');

$v = $app->query('SELECT * FROM mt_auth_verify_code(?,?)', [$phone, 'hash-f2']);
is_(count($v), 1, 'verify_code resolves the principal');
is_((int) $inspect->one('SELECT count(*) AS n FROM mt_auth_codes WHERE consumed_at IS NOT NULL')['n'],
    1, 'and the code is genuinely marked consumed');

$app->one('SELECT mt_auth_create_session(?,?,?,?::interval) AS id',
          [$A['principal'], $A['customer'], 'tok-f2', '1 hour']);
is_((int) $inspect->one("SELECT count(*) AS n FROM mt_auth_sessions WHERE token_hash='tok-f2'")['n'],
    1, 'create_session wrote a session row');
$r = $app->query('SELECT * FROM mt_auth_resolve_token(?)', ['tok-f2']);
is_(count($r) === 1 && $r[0]['customer_id'] === $A['customer'], true,
    'resolve_token returns the right customer');
$app->one('SELECT mt_auth_revoke_token(?) AS n', ['tok-f2']);
is_((int) $inspect->one("SELECT count(*) AS n FROM mt_auth_sessions
                          WHERE token_hash='tok-f2' AND revoked_at IS NOT NULL")['n'],
    1, 'and revocation genuinely revokes');

t('F2 — customer creation works through its intended trusted path only');
$new = $admin->one('SELECT mt_customer_create(?,?) AS id', ['Probe Customer', 'staff:test'])['id'];
is_((int) $inspect->one('SELECT count(*) AS n FROM mt_customers WHERE id = ?', [$new])['n'], 1,
    'the admin role creates a customer');
throws_(fn() => $app->one('SELECT mt_customer_create(?,?) AS id', ['Sneaky', 'x']),
    'permission denied', 'the request role cannot');
throws_(fn() => $work->one('SELECT mt_customer_create(?,?) AS id', ['Sneaky', 'x']),
    'permission denied', 'nor the worker');
foreach ([['', 'staff'], ['   ', 'staff'], ['Name', ''], ['Name', null]] as [$n, $by]) {
    throws_(fn() => $admin->one('SELECT mt_customer_create(?,?) AS id', [$n, $by]), '',
        'refused: name=' . var_export($n, true) . ' by=' . var_export($by, true));
}
is_(count($ctx->run($new, fn($db) => $db->query('SELECT 1 FROM mt_customers'))), 1,
    'and the new customer is immediately usable as a tenant context');

t('F2 — the operations that silently did nothing now have side effects');
$dev = $admin->one("SELECT id FROM mt_device_register('SER-F2','hAP',null,null,'10.81.0.1','tech:f2')")['id'];
is_((int) $inspect->one('SELECT count(*) AS n FROM mt_devices WHERE serial=?', ['SER-F2'])['n'], 1,
    'register wrote a device row');
$admin->one('SELECT id FROM mt_device_assign(?,?,?,?)', [$dev, $A['customer'], $A['site'], 'F2 AP']);
is_($inspect->one('SELECT name FROM mt_devices WHERE id = ?', [$dev])['name'], 'F2 AP',
    'assign genuinely assigned — it used to return a row shape and change nothing');
$admin->one('SELECT mt_device_set_secret(?,?,?) AS ok', [$dev, 'mgmt', 'sealed-f2']);
is_((int) $inspect->one('SELECT count(*) AS n FROM mt_device_secrets WHERE device_id=?', [$dev])['n'],
    1, 'set_secret stored a credential');
$admin->one('SELECT wan_interface FROM mt_device_set_wan(?,?,?)', [$dev, 'sfp-f2', 'tech:f2']);
is_($inspect->one('SELECT wan_interface FROM mt_devices WHERE id=?', [$dev])['wan_interface'],
    'sfp-f2', 'set_wan recorded the fact');

$ctx->run($A['customer'], fn($db) => $db->exec(
    "INSERT INTO mt_intents (customer_id, kind) VALUES (?, 'f2.work')", [$A['customer']]));
$claimed = $work->query("SELECT * FROM mt_intent_claim('w-f2','5 minutes'::interval,10)");
is_(count($claimed) >= 1, true, 'the worker claims — it used to report an empty queue forever');
is_((int) $inspect->one("SELECT count(*) AS n FROM mt_intents WHERE claimed_by='w-f2'")['n'] >= 1,
    true, 'and the claim is genuinely recorded');

// ===========================================================================
t('F2 — each definer role holds ONLY the privileges its functions need');
// The declared matrix from docs/58 §13.2 and migration 017 §2. If a later
// migration widens a definer role, this fails — the point being that the
// privilege surface is a list somebody has to change on purpose.
$expected = [
    'dnb_def_auth' => ['mt_auth_codes'    => 'SELECT,INSERT,UPDATE',
                       'mt_principals'    => 'SELECT,UPDATE',
                       'mt_auth_sessions' => 'SELECT,INSERT,UPDATE'],
    'dnb_def_net'  => ['mt_vouchers'      => 'SELECT,UPDATE',
                       'mt_hotspot_users' => 'SELECT',
                       'mt_sessions'      => 'SELECT,INSERT,UPDATE'],
    'dnb_def_work' => ['mt_intents'       => 'SELECT,UPDATE',
                       'mt_sessions'      => 'SELECT,UPDATE',
                       'mt_uplink_samples'=> 'SELECT,INSERT,DELETE',
                       'mt_devices'       => 'SELECT'],
    'dnb_def_prov' => ['mt_devices'       => 'SELECT,INSERT,UPDATE',
                       'mt_device_secrets'=> 'SELECT,INSERT,UPDATE',
                       'mt_device_config' => 'SELECT,INSERT,UPDATE',
                       'mt_customers'     => 'INSERT'],
];
$order = ['SELECT' => 0, 'INSERT' => 1, 'UPDATE' => 2, 'DELETE' => 3];
foreach ($expected as $role => $tables) {
    $rows = $inspect->query(
        "SELECT c.relname AS t, p.polcmd::text AS cmd
           FROM pg_policy p JOIN pg_class c ON c.oid = p.polrelid
          WHERE ?::regrole = ANY (p.polroles) ORDER BY 1", [$role]);
    $actual = [];
    foreach ($rows as $row) {
        $cmd = ['r' => 'SELECT', 'a' => 'INSERT', 'w' => 'UPDATE', 'd' => 'DELETE',
                '*' => 'ALL'][$row['cmd']] ?? $row['cmd'];
        $actual[$row['t']][] = $cmd;
    }
    foreach ($actual as $t => &$cs) { usort($cs, fn($x, $y) => $order[$x] <=> $order[$y]); $cs = implode(',', $cs); }
    ksort($actual); $want = $tables; ksort($want);
    is_($actual, $want, "{$role}: policy set is exactly what its functions need");
}

t('F2 — a definer role cannot reach another trust context\'s data');
// The previous design gave all twenty functions superuser, so this question
// could not even be asked.
foreach ([['dnb_def_auth','mt_device_secrets'], ['dnb_def_auth','mt_sessions'],
          ['dnb_def_prov','mt_sessions'],       ['dnb_def_prov','mt_auth_codes'],
          ['dnb_def_net','mt_devices'],         ['dnb_def_work','mt_auth_codes'],
          ['dnb_def_work','mt_device_secrets']] as [$role, $tbl]) {
    is_($inspect->one('SELECT has_table_privilege(?,?,?) AS p', [$role, $tbl, 'SELECT'])['p'],
        false, "{$role} holds no SELECT on {$tbl}");
}

t('F2 — the test-only inspector identity never appears in application code');
$leaked = [];
foreach (array_merge(glob(__DIR__ . '/../src/*/*.php'), glob(__DIR__ . '/../src/*/*/*.php'),
                     glob(__DIR__ . '/../bin/*.php')) as $f) {
    // The accessor's own definition in Database.php is not a use of it; what
    // this guard forbids is application code CALLING it.
    if (str_contains(strip_php_comments(file_get_contents($f)), '::inspector(')) {
        $leaked[] = basename($f);
    }
}
is_($leaked, [], 'no file under src/ or bin/ uses Database::inspector()');

exit(t_summary());
