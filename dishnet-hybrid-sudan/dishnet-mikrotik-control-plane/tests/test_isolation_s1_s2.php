<?php
declare(strict_types=1);
/**
 * Adversarial suite for audit findings S1 and S2 (docs/57 §4.1, §4.2, §10).
 *
 * Every attack here SUCCEEDED before remediation. The before-evidence is in
 * the commit message and in docs/57; what follows asserts the denial, and
 * asserts that the legitimate paths those privileges existed for still work —
 * a security fix that breaks delivery is not a fix.
 */
require __DIR__ . '/bootstrap.php';
use Dn\Db\Database;
use Dn\Devices\DeviceRegistry;
use Dn\Intents\IntentQueue;
use Dn\Tenancy\TenantContext;

putenv('DNB_SECRET_KEY=test-key-for-suite-only');

$owner   = Database::inspector();
$app     = Database::app();      $ctx  = new TenantContext($app);
$workDb  = Database::worker();   $ctxW = new TenantContext($workDb);
$adminDb = Database::admin();    $ctxA = new TenantContext($adminDb);

$ids = seed_two_customers($owner);
$P = $ids['A']; $Q = $ids['B'];

// Two devices, staged then assigned — the real order of operations.
$dev = [];
foreach (['P' => $P, 'Q' => $Q] as $k => $who) {
    $d = $ctxA->runUnscoped(fn($x) => (new DeviceRegistry($x))->register(
        "SER-{$k}", 'hAP ax2', '7.14.3', "pk-{$k}", '10.66.0.' . ord($k), 'tech:t'));
    $ctxA->runUnscoped(fn($x) => (new DeviceRegistry($x))->setCredentials($d['id'], 'dn-mgmt', "pw-{$k}", 'test:staff'));
    $ctxA->runUnscoped(fn($x) => (new DeviceRegistry($x))->assign($d['id'], $who['customer'], $who['site'], "AP {$k}", 'test:staff'));
    $dev[$k] = $d['id'];
}

// ===========================================================================
t('S1 — the roles are what the design says they are');
foreach (['dnb_app', 'dnb_worker', 'dnb_admin'] as $role) {
    $r = $owner->one('SELECT rolsuper, rolbypassrls, rolcreaterole FROM pg_roles WHERE rolname = ?', [$role]);
    is_([$r['rolsuper'], $r['rolbypassrls'], $r['rolcreaterole']], [false, false, false],
        "{$role}: not superuser, no BYPASSRLS, cannot create roles");
}
$members = $owner->query(
    "SELECT r.rolname AS member, g.rolname AS grp FROM pg_auth_members m
       JOIN pg_roles r ON r.oid=m.member JOIN pg_roles g ON g.oid=m.roleid
      WHERE r.rolname IN ('dnb_app','dnb_worker','dnb_admin')");
is_($members, [], 'no application role is a member of another — none can SET ROLE into another');

t('S1 — ATTACK: read another customer\'s sealed credential row');
$row = $ctx->run($P['customer'], fn($db) =>
    $db->one('SELECT username, secret_sealed FROM mt_device_secrets WHERE device_id = ?', [$dev['Q']]));
is_($row, null, "P cannot read Q's secret row at all");

$all = $ctx->run($P['customer'], fn($db) => $db->query('SELECT device_id FROM mt_device_secrets'));
is_(count($all), 1, 'an unfiltered select returns only P\'s own');

t('S1 — ATTACK: decrypt another customer\'s credential through the helper');
is_($ctx->run($P['customer'], fn($db) => (new DeviceRegistry($db))->credentials($dev['Q'])), null,
    "credentials(Q's device) yields nothing for P");
$json = json_encode($ctx->run($P['customer'], fn($db) => (new DeviceRegistry($db))->credentials($dev['Q'])));
is_(str_contains((string) $json, 'pw-Q'), false, "and Q's password appears nowhere in the result");

t('S1 — ATTACK: device-id substitution cannot cross the boundary');
foreach ([$dev['Q'], '00000000-0000-4000-8000-000000000000'] as $probe) {
    is_($ctx->run($P['customer'], fn($db) => (new DeviceRegistry($db))->credentials($probe)), null,
        'a foreign id and a nonexistent id are equally fruitless');
}

t('S1 — ATTACK: steal the device first, then read (the two-step from §10.1)');
throws_(fn() => $ctx->run($P['customer'], fn($db) => $db->one(
    'SELECT * FROM mt_device_assign(?,?,?,?,?)', [$dev['Q'], $P['customer'], null, 'stolen', 'test:staff'])),
    'permission denied', 'the request role cannot call mt_device_assign at all');
is_($owner->one('SELECT customer_id FROM mt_devices WHERE id = ?', [$dev['Q']])['customer_id'],
    $Q['customer'], "and Q's device still belongs to Q");

t('S1 — ATTACK: the other admin primitives are equally closed');
foreach ([
    ['mt_device_register(?,?,?,?,?,?)', ['S','m',null,null,null,'t']],
    ['mt_device_set_state(?,?,?)', [null, 'active', 'test:staff']],
    ['mt_device_set_secret(?,?,?,?)', [null, 'u', 'v1.x', 'test:staff']],
] as [$sql, $args]) {
    throws_(fn() => $ctx->run($P['customer'], fn($db) => $db->one("SELECT * FROM {$sql}", $args)),
        'permission denied', 'request role denied: ' . explode('(', $sql)[0]);
}

t('S1 — LEGITIMATE: the owner still reads its own, and the worker still delivers');
$mine = $ctx->run($P['customer'], fn($db) => (new DeviceRegistry($db))->credentials($dev['P']));
is_($mine['password'], 'pw-P', 'P reads its own credential');
$asWorker = $ctxW->run($P['customer'], fn($db) => (new DeviceRegistry($db))->credentials($dev['P']));
is_($asWorker['password'], 'pw-P', 'and so does a worker inside that customer\'s context');
$wrongCtx = $ctxW->run($Q['customer'], fn($db) => (new DeviceRegistry($db))->credentials($dev['P']));
is_($wrongCtx, null, 'but a worker in the WRONG context gets nothing — the context is the authority');

t('S1 — device configuration is closed the same way (finding S3)');
$ctxA->run($P['customer'], fn($db) => (new DeviceRegistry($db))->setDesired($dev['P'], ['k' => 'v'], 'test:staff'));
is_($ctx->run($Q['customer'], fn($db) => $db->one('SELECT desired FROM mt_device_config WHERE device_id = ?', [$dev['P']])),
    null, "Q cannot read P's desired configuration");

// ===========================================================================
t('S2 — ATTACK: the request role invokes the worker claim primitive');
// Manufactured on the fixture identity: since migration 025 dnb_app may not
// INSERT mt_intents at all. What is under attack below is the CLAIM primitive,
// not the enqueue, so the rows only need to exist.
$iQ = (new IntentQueue($owner))
        ->enqueue($Q['customer'], 'secret.work', ['confidential' => 'Q-payload']);
$iP = (new IntentQueue($owner))
        ->enqueue($P['customer'], 'other.work', ['x' => 1]);

throws_(fn() => $ctx->run($P['customer'], fn($db) => (new IntentQueue($db))->claim('attacker', '5 minutes', 10)),
    'permission denied', 'the request role cannot call claim() — it has no EXECUTE');

t('S2 — ATTACK: nor the other cross-customer primitives');
foreach (['mt_intent_expire_overdue()', "mt_sessions_reap('1 hour'::interval)",
          "mt_uplink_prune('1 day'::interval)", 'mt_devices_samplable()'] as $fn) {
    throws_(fn() => $ctx->runUnscoped(fn($db) => $db->query("SELECT * FROM {$fn}")),
        'permission denied', "request role denied: " . explode('(', $fn)[0]);
}

t('S2 — ATTACK: the request role cannot become a worker');
throws_(fn() => $app->pdo()->exec('SET ROLE dnb_worker'), '', 'cannot SET ROLE to the worker');
// A grant you hold no grant option for is refused outright on some versions and
// answered with a WARNING and no change on others. Either is a denial, so the
// assertion reads the privilege back rather than depending on which one happens.
try {
    $app->pdo()->exec('GRANT EXECUTE ON FUNCTION mt_intent_claim(text,interval,integer) TO dnb_app');
} catch (\Throwable $e) { /* refused loudly — also fine */ }
is_($owner->one("SELECT has_function_privilege('dnb_app',
        'mt_intent_claim(text,interval,integer)', 'EXECUTE') AS p")['p'], false,
    'the self-grant changed nothing — dnb_app still has no EXECUTE');
throws_(fn() => $app->pdo()->exec('ALTER ROLE dnb_app BYPASSRLS'), '', 'cannot give itself BYPASSRLS');

t('S2 — ATTACK: reading another customer\'s intent directly is still closed');
is_($ctx->run($P['customer'], fn($db) => (new IntentQueue($db))->find($iQ['id'])), null,
    "P cannot fetch Q's intent by id");
$seen = $ctx->run($P['customer'], fn($db) => (new IntentQueue($db))->forCustomer());
is_(count($seen), 1, 'and sees only its own');

t('S2 — LEGITIMATE: the worker still claims across customers');
$claimed = (new IntentQueue($workDb))->claim('worker-1', '5 minutes', 10);
$kinds = array_column($claimed, 'kind'); sort($kinds);
is_($kinds, ['other.work', 'secret.work'], 'the worker claims both customers\' intents');
$custs = array_unique(array_column($claimed, 'customer_id'));
is_(count($custs), 2, 'spanning two customers, which is the whole point of the role');

t('S2 — LEGITIMATE: crash and retry semantics are unchanged');
$owner->exec("UPDATE mt_intents SET lease_expires_at = now() - interval '1 second', claimed_by = 'dead'");
$recovered = (new IntentQueue($workDb))->claim('worker-2', '5 minutes', 10);
is_(count($recovered), 2, 'a lapsed lease is reclaimable — nothing lost to the role change');
is_($owner->one('SELECT state FROM mt_intents WHERE id = ?', [$iQ['id']])['state'], 'queued',
    'and the intent is still queued');

t('S2 — LEGITIMATE: concurrent workers still claim disjoint sets');
$owner->exec('UPDATE mt_intents SET claimed_by = NULL, lease_expires_at = NULL');
for ($i = 0; $i < 18; $i++) {
    (new IntentQueue($owner))->enqueue($P['customer'], 'race.test');
}
$a = (new IntentQueue(Database::worker()))->claim('race-a', '5 minutes', 30);
$b = (new IntentQueue(Database::worker()))->claim('race-b', '5 minutes', 30);
is_(count(array_intersect(array_column($a, 'id'), array_column($b, 'id'))), 0,
    'no intent claimed by both');
is_(count($a) + count($b), 20, 'and every one claimed exactly once');

t('PUBLIC holds nothing — the grant default that made the first fix a no-op');
// Two ways a function can be PUBLIC-executable, and the second is the one that
// hides: an explicit ACL listing PUBLIC (grantee 0), and NO explicit ACL AT
// ALL, which means the built-in default — PUBLIC included. aclexplode(NULL)
// returns no rows, so a guard that only inspects proacl passes happily on a
// brand-new CREATE FUNCTION. An earlier version of this assertion did exactly
// that. Both shapes are checked here.
$public = $owner->query(
    "SELECT p.oid::regprocedure::text AS sig FROM pg_proc p
        JOIN pg_namespace n ON n.oid = p.pronamespace
       WHERE n.nspname = 'public' AND p.proname LIKE 'mt\\_%'
         AND (p.proacl IS NULL
              OR EXISTS (SELECT 1 FROM aclexplode(p.proacl) a WHERE a.grantee = 0))");
is_(array_column($public, 'sig'), [],
    'no mt_ function is executable by PUBLIC, explicitly or by default');

// Positive check on the mechanism rather than the absence of a symptom: the
// sweep reports how many functions it had to fix, so a second call returning 0
// proves the migrations left nothing for it to do. ALTER DEFAULT PRIVILEGES
// cannot provide this — measured not to work on 16.13 (docs/57 §12.2) — so
// this function plus this assertion ARE the control.
is_((int) $owner->one('SELECT mt_revoke_public_execute() AS n')['n'], 0,
    'and the sweep finds nothing left to revoke — migrations called it');

t('S5 — no application role can delete migration history');
foreach ([[$app, 'app'], [$workDb, 'worker'], [$adminDb, 'admin']] as [$conn, $name]) {
    throws_(fn() => $conn->exec('DELETE FROM mt_migrations'), 'permission denied',
        "{$name} cannot delete from mt_migrations");
}

exit(t_summary());
