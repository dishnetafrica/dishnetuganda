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

$owner = Database::owner();
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

t('every customer-scoped table has RLS enabled AND forced');
foreach (array_merge(['mt_customers','mt_auth_sessions','mt_audit_log','mt_idempotency'],
                     array_keys($SCOPED)) as $tbl) {
    $c = $owner->one('SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname = ?', [$tbl]);
    if ($c['relrowsecurity'] === true && $c['relforcerowsecurity'] === true) {
        ok("{$tbl}: RLS enabled and forced");
    } else {
        bad("{$tbl}: rowsecurity=" . var_export($c['relrowsecurity'], true)
            . " force=" . var_export($c['relforcerowsecurity'], true));
    }
}

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
