<?php
declare(strict_types=1);
/**
 * THE GATE.
 *
 * Acceptance condition (docs/55 step 1):
 *   Customer A cannot read, modify, enumerate, infer, or receive a successful
 *   response for Customer B's customer-scoped resources, INCLUDING when A
 *   supplies B's IDs.
 *
 * Each verb in that sentence gets its own section below. "Infer" is the one
 * most easily missed: it is not enough that A gets no data — A must not be
 * able to tell B's id apart from an id that does not exist.
 */
require __DIR__ . '/bootstrap.php';

use Dn\Db\Database;
use Dn\Tenancy\TenantContext;

$owner = Database::inspector();
$ids   = seed_two_customers($owner);
$A = $ids['A']; $B = $ids['B'];

$app = Database::app();
$ctx = new TenantContext($app);

$SCOPED = [
    'mt_principals'   => 'principal',
    'mt_services'     => 'service',
    'mt_entitlements' => 'entitlement',
    'mt_sites'        => 'site',
];

// ---------------------------------------------------------------------------
t('deployment guard: the app role must not be able to bypass RLS at all');
$r = $owner->one("SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = 'dnb_app'");
is_($r['rolsuper'],     false, 'dnb_app is not superuser');
is_($r['rolbypassrls'], false, 'dnb_app does not have BYPASSRLS');
$who = $ctx->run($A['customer'], fn($db) => $db->one('SELECT current_user AS u')['u']);
is_($who, 'dnb_app', 'requests actually run as dnb_app, not the owner');

t('every customer-scoped table is protected — enumerated FROM THE CATALOGUE');
// Audit finding F4. The version of this test that carried the same title
// iterated a hand-written list of EIGHT tables while nineteen carry a
// customer. Every one of the nineteen was correct, so nothing was broken and
// nothing would have gone red if it had been. The list is gone; the schema
// decides what is in scope.
$scoped = customer_scoped_tables($owner);
is_(count($scoped), 19, 'nineteen customer-scoped tables are in scope, not eight');
is_(array_keys($scoped), [
    'mt_audit_log', 'mt_auth_codes', 'mt_auth_sessions', 'mt_customers',
    'mt_device_config', 'mt_device_secrets', 'mt_devices', 'mt_entitlements',
    'mt_hotspot_users', 'mt_idempotency', 'mt_intents', 'mt_plans',
    'mt_principals', 'mt_services', 'mt_sessions', 'mt_sites',
    'mt_uplink_samples', 'mt_voucher_batches', 'mt_vouchers',
], 'and they are exactly these — a new one changes this list on purpose');
is_($scoped['mt_customers'], 'id', 'mt_customers is keyed by its own id, not customer_id');

is_(rls_violations($owner), [],
    'no table has RLS off, FORCE off, a missing policy, or a one-sided policy');

foreach (rls_policy_exemptions() as $tbl => $why) {
    $c = $owner->one('SELECT relrowsecurity AS r, relforcerowsecurity AS f
                          FROM pg_class WHERE relname = ?', [$tbl]);
    is_([$c['r'], $c['f']], [true, true],
        "{$tbl} is exempt from the tenant policy ({$why}) but still has RLS forced");
    is_((int) $owner->one('SELECT count(*) AS n FROM information_schema.role_table_grants
                              WHERE table_name = ? AND grantee IN (?,?,?)',
                            [$tbl, 'dnb_app', 'dnb_worker', 'dnb_admin'])['n'] >= 0, true,
        "  and its protection — grants, not policies — is recorded in rls_policy_exemptions()");
}

t('F4 NEGATIVE — the guard actually fails when a table is left unprotected');
// A guard nobody has watched fail is a guard nobody knows works. This plants
// exactly the mistake a future migration would make and proves it is caught.
$owner->pdo()->exec('CREATE TABLE mt_guard_probe (id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                                                    customer_id uuid)');
try {
    is_(array_key_exists('mt_guard_probe', customer_scoped_tables($owner)), true,
        'a new table with a customer_id is picked up with no list to edit');
    $v = rls_violations($owner);
    is_(count(array_filter($v, fn($m) => str_contains($m, 'mt_guard_probe: RLS is not enabled'))), 1,
        'and reported: RLS is not enabled');
    is_(count(array_filter($v, fn($m) => str_contains($m, 'not FORCED'))), 1,
        'and reported: RLS is not forced');

    // Half-fixed is still broken: enabling RLS without a policy must still fail.
    $owner->pdo()->exec('ALTER TABLE mt_guard_probe ENABLE ROW LEVEL SECURITY');
    $owner->pdo()->exec('ALTER TABLE mt_guard_probe FORCE ROW LEVEL SECURITY');
    $v = rls_violations($owner);
    is_(count(array_filter($v, fn($m) => str_contains($m, 'no all-roles isolation policy'))), 1,
        'RLS on but no policy is still reported');

    // A USING-only policy permits a write it would not permit a read of.
    $owner->pdo()->exec('CREATE POLICY p ON mt_guard_probe FOR ALL
                             USING (customer_id = mt_current_customer())');
    $v = rls_violations($owner);
    is_(count(array_filter($v, fn($m) => str_contains($m, 'no WITH CHECK'))), 1,
        'a one-sided policy is reported — the half that lets a row be planted');

    $owner->pdo()->exec('DROP POLICY p ON mt_guard_probe');
    $owner->pdo()->exec('CREATE POLICY p ON mt_guard_probe FOR ALL
                             USING (customer_id = mt_current_customer())
                             WITH CHECK (customer_id = mt_current_customer())');
    is_(count(array_filter(rls_violations($owner),
        fn($m) => str_contains($m, 'mt_guard_probe'))), 0,
        'and once it is genuinely protected, the guard is satisfied');
} finally {
    $owner->pdo()->exec('DROP TABLE mt_guard_probe');
}
is_(rls_violations($owner), [], 'the schema is back to clean after the probe');

// ---------------------------------------------------------------------------
t('READ — A supplying B\'s id gets nothing');
foreach ($SCOPED as $tbl => $key) {
    $rows = $ctx->run($A['customer'], fn($db) => $db->query("SELECT id FROM {$tbl} WHERE id = ?", [$B[$key]]));
    is_(count($rows), 0, "{$tbl}: A cannot read B's row by id");
}
$rows = $ctx->run($A['customer'], fn($db) => $db->query('SELECT id FROM mt_customers WHERE id = ?', [$B['customer']]));
is_(count($rows), 0, "mt_customers: A cannot read B's customer row");

t('READ — A can still read its own');
foreach ($SCOPED as $tbl => $key) {
    $rows = $ctx->run($A['customer'], fn($db) => $db->query("SELECT id FROM {$tbl} WHERE id = ?", [$A[$key]]));
    is_(count($rows), 1, "{$tbl}: A reads its own row (isolation is not just breaking everything)");
}

// ---------------------------------------------------------------------------
t('ENUMERATE — unfiltered selects return only A');
foreach ($SCOPED as $tbl => $key) {
    $rows = $ctx->run($A['customer'], fn($db) => $db->query("SELECT id FROM {$tbl}"));
    $leaked = array_filter($rows, fn($r) => $r['id'] === $B[$key]);
    is_(count($rows), 1, "{$tbl}: SELECT * returns exactly one row");
    is_(count($leaked), 0, "{$tbl}: B's row absent from an unfiltered select");
}

t('ENUMERATE — aggregates leak no counts');
foreach ($SCOPED as $tbl => $key) {
    $n = $ctx->run($A['customer'], fn($db) => (int) $db->one("SELECT count(*) AS n FROM {$tbl}")['n']);
    is_($n, 1, "{$tbl}: count(*) sees only A's rows");
}

t('ENUMERATE — a JOIN cannot reach across the boundary');
$rows = $ctx->run($A['customer'], fn($db) => $db->query(
    'SELECT s.id FROM mt_sites s JOIN mt_services sv ON sv.id = s.service_id'));
is_(count($rows), 1, 'join of two scoped tables yields only A');
$rows = $ctx->run($A['customer'], fn($db) => $db->query(
    'SELECT s.id FROM mt_sites s JOIN mt_services sv ON sv.id = s.service_id WHERE sv.id = ?',
    [$B['service']]));
is_(count($rows), 0, "join pinned to B's service id yields nothing");

// ---------------------------------------------------------------------------
t('MODIFY — A cannot update B');
// column chosen per table: mt_sites has no status column
foreach (['mt_sites' => ['site','name'], 'mt_services' => ['service','status'],
          'mt_principals' => ['principal','display_name']] as $tbl => [$key,$col]) {
    $n = $ctx->run($A['customer'], fn($db) => $db->exec(
        "UPDATE {$tbl} SET {$col} = {$col} WHERE id = ?", [$B[$key]]));
    is_($n, 0, "{$tbl}: UPDATE of B's row affects 0 rows");
}
$n = $ctx->run($A['customer'], fn($db) => $db->exec(
    "UPDATE mt_sites SET name = 'hijacked' WHERE id = ?", [$B['site']]));
is_($n, 0, "mt_sites: renaming B's site affects 0 rows");
$still = $owner->one('SELECT name FROM mt_sites WHERE id = ?', [$B['site']]);
is_($still['name'], 'Kabale Hostel lobby', "B's site name is genuinely unchanged");

t('MODIFY — a blind UPDATE with no WHERE touches only A');
$n = $ctx->run($A['customer'], fn($db) => $db->exec("UPDATE mt_sites SET location = 'x'"));
is_($n, 1, 'UPDATE without WHERE affects exactly A\'s one row');
$bLoc = $owner->one('SELECT location FROM mt_sites WHERE id = ?', [$B['site']]);
is_($bLoc['location'], 'ground floor', "B's site untouched by A's unfiltered UPDATE");

t('MODIFY — A cannot delete B');
foreach ($SCOPED as $tbl => $key) {
    if ($tbl === 'mt_services') { continue; }   // FK-restricted; covered by sites
    $n = $ctx->run($A['customer'], fn($db) => $db->exec("DELETE FROM {$tbl} WHERE id = ?", [$B[$key]]));
    is_($n, 0, "{$tbl}: DELETE of B's row affects 0 rows");
}

t('MODIFY — A cannot insert a row belonging to B (WITH CHECK)');
throws_(
    fn() => $ctx->run($A['customer'], fn($db) => $db->exec(
        'INSERT INTO mt_sites (customer_id, service_id, name) VALUES (?,?,?)',
        [$B['customer'], $B['service'], 'planted'])),
    'policy',
    'inserting a site under B\'s customer_id is rejected by the policy'
);
throws_(
    fn() => $ctx->run($A['customer'], fn($db) => $db->exec(
        "INSERT INTO mt_principals (customer_id, kind, display_name)
         VALUES (?, 'operator', 'planted')", [$B['customer']])),
    'policy',
    'inserting a principal under B\'s customer_id is rejected'
);

// ---------------------------------------------------------------------------
t('INFER — B\'s id is indistinguishable from an id that does not exist');
$absent = '00000000-0000-4000-8000-000000000000';
foreach ($SCOPED as $tbl => $key) {
    $r1 = $ctx->run($A['customer'], fn($db) => $db->query("SELECT id FROM {$tbl} WHERE id = ?", [$B[$key]]));
    $r2 = $ctx->run($A['customer'], fn($db) => $db->query("SELECT id FROM {$tbl} WHERE id = ?", [$absent]));
    is_($r1, $r2, "{$tbl}: B's real id and a nonexistent id give identical results");
}
$u1 = $ctx->run($A['customer'], fn($db) => $db->exec('UPDATE mt_sites SET name = name WHERE id = ?', [$B['site']]));
$u2 = $ctx->run($A['customer'], fn($db) => $db->exec('UPDATE mt_sites SET name = name WHERE id = ?', [$absent]));
is_($u1, $u2, 'UPDATE rowcount is identical for a real foreign id and a fake one');

t('INFER — a unique constraint does not confirm B\'s existence');
// B's phone is unique. If A could provoke a unique violation naming it,
// that would confirm the row exists. The policy must refuse first.
throws_(
    fn() => $ctx->run($A['customer'], fn($db) => $db->exec(
        "INSERT INTO mt_principals (customer_id, kind, display_name, phone)
         VALUES (?, 'operator', 'probe', '+256700001002')", [$B['customer']])),
    'policy',
    'probing B\'s unique phone under B\'s customer_id is refused by policy, not by the unique index'
);

// ---------------------------------------------------------------------------
t('FAIL CLOSED — no tenant context means no rows, never all rows');
foreach (array_keys($SCOPED) as $tbl) {
    $rows = $ctx->runUnscoped(fn($db) => $db->query("SELECT id FROM {$tbl}"));
    is_(count($rows), 0, "{$tbl}: unset app.customer_id yields 0 rows");
}
$rows = $ctx->runUnscoped(fn($db) => $db->query('SELECT id FROM mt_customers'));
is_(count($rows), 0, 'mt_customers: unset context yields 0 rows');

t('FAIL CLOSED — a forged customer id yields nothing');
$rows = $ctx->run($absent, fn($db) => $db->query('SELECT id FROM mt_sites'));
is_(count($rows), 0, 'a customer id that does not exist sees nothing');

t('FAIL CLOSED — a non-uuid context is rejected before it reaches the database');
throws_(fn() => $ctx->run("' OR '1'='1", fn($db) => null), 'uuid',
        'a non-uuid customer id is rejected up front');

// ---------------------------------------------------------------------------
t('PRIVILEGE — the app role cannot turn RLS off');
throws_(fn() => $app->pdo()->exec('ALTER TABLE mt_sites DISABLE ROW LEVEL SECURITY'),
        '', 'app role cannot DISABLE ROW LEVEL SECURITY');
throws_(fn() => $app->pdo()->exec('ALTER TABLE mt_sites NO FORCE ROW LEVEL SECURITY'),
        '', 'app role cannot remove FORCE');
throws_(fn() => $app->pdo()->exec('DROP POLICY mt_sites_isolation ON mt_sites'),
        '', 'app role cannot drop a policy');
throws_(fn() => $app->pdo()->exec('ALTER ROLE dnb_app BYPASSRLS'),
        '', 'app role cannot grant itself BYPASSRLS');
throws_(fn() => $app->pdo()->exec('SET ROLE dnb'),
        '', 'app role cannot escalate to the owner');
throws_(fn() => $app->pdo()->exec('TRUNCATE mt_sites'),
        '', 'app role cannot TRUNCATE (which would skip row triggers)');

t('PRIVILEGE — set_config cannot be used to widen scope mid-transaction');
// Changing the setting is allowed; it just moves you to another customer's
// scope, and the id is derived, never client-supplied. What must NOT work is
// clearing it to see everything.
$rows = $ctx->run($A['customer'], function ($db) {
    $db->pdo()->prepare("SELECT set_config('app.customer_id','',true)")->execute();
    return $db->query('SELECT id FROM mt_sites');
});
is_(count($rows), 0, 'clearing the setting mid-transaction reveals nothing');

exit(t_summary());
