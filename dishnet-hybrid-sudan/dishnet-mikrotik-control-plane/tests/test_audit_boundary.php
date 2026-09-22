<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use Dn\Db\Database;
use Dn\Tenancy\TenantContext;

/**
 * The audit write boundary — migration 022, docs/113 (A-1 / T1).
 *
 * A-1: roles holding migration 015's blanket grant could INSERT into
 * mt_audit_log directly, so the audit trail was FORGEABLE. The table is
 * append-only by trigger, so a forged row could never be removed — worse than
 * a forgeable business write, which can be reversed.
 *
 * T1 revokes dnb_admin, whose only caller is the excluded simulator. dnb_app
 * and dnb_worker keep theirs until T2/T3 give them controlled paths, and this
 * file asserts that residue truthfully rather than pretending it is closed.
 *
 * Every negative assertion is paired with something proving the measurement
 * could have come out the other way.
 */

$owner = Database::owner();
$ins   = Database::inspector();          // BYPASSRLS — reads audit across tenants
$admin = Database::admin();
$app   = Database::app();
$ids   = seed_two_customers($ins);
$A     = $ids['A']['customer'];   // seed_two_customers returns a bundle, not an id

$priv = fn(string $role, string $tbl, string $p): int => (int) $owner->one(
    "SELECT has_table_privilege(?,?,?)::int AS p", [$role, $tbl, $p])['p'];

t('A-1/T1 — dnb_admin can no longer forge an audit row');

is_($priv('dnb_app', 'mt_audit_log', 'INSERT'), 1,
    'CONTROL: the privilege probe still reports 1 for a role that does hold INSERT');
is_($priv('dnb_admin', 'mt_audit_log', 'INSERT'), 0,
    'dnb_admin holds no INSERT on mt_audit_log');

$ctxAdmin = new TenantContext($admin);
$seen = (int) $ctxAdmin->run($A, fn(Database $db) =>
    $db->one('SELECT count(*)::int AS c FROM mt_audit_log'))['c'];
is_($seen >= 0, true, 'CONTROL: dnb_admin is connected and can read audit rows in its tenant');

throws_(fn() => $ctxAdmin->run($A, fn(Database $db) => $db->exec(
    'INSERT INTO mt_audit_log (customer_id,actor,actor_kind,action,target_type,target_id,source)
     VALUES (?,?,?,?,?,?,?)', [$A, 'FORGERY', 'staff', 'probe.forge', 'probe', 'x', 'admin'])),
    'permission denied',
    'dnb_admin attempting a direct audit INSERT is refused');

t('the definer path is untouched — a caller needs no audit privilege');

is_($priv('dnb_adminwrite', 'mt_audit_log', 'INSERT'), 0,
    'dnb_adminwrite holds no audit privilege at all');
$before = (int) $ins->one(
    "SELECT count(*)::int AS c FROM mt_audit_log WHERE actor = 't1-boundary'")['c'];
$cid = Database::adminWrite()->one(
    'SELECT mt_customer_create(?,?) AS id', ['T1-BOUNDARY', 't1-boundary'])['id'];
is_(is_string($cid) && $cid !== '', true,
    'yet it can execute an audited provisioning function');
is_((int) $ins->one(
    "SELECT count(*)::int AS c FROM mt_audit_log WHERE actor = 't1-boundary'")['c'],
    $before + 1, 'and exactly one audit row was written, through dnb_def_audit');

t('append-only still holds for every role');

foreach (['dnb_admin' => $admin, 'dnb_app' => $app] as $label => $conn) {
    $ctx = new TenantContext($conn);
    throws_(fn() => $ctx->run($A, fn(Database $db) => $db->exec(
        "UPDATE mt_audit_log SET action = 'TAMPERED' WHERE customer_id = ?", [$A])),
        'append-only', "{$label} cannot UPDATE an audit row");
    throws_(fn() => $ctx->run($A, fn(Database $db) => $db->exec(
        'DELETE FROM mt_audit_log WHERE customer_id = ?', [$A])),
        'append-only', "{$label} cannot DELETE an audit row");
}

t('A-2 — the remaining residue is asserted, not hidden');

// T2/T3 are not done. Finishing them must BREAK these two lines, which is how
// the residue gets removed rather than forgotten.
is_($priv('dnb_app', 'mt_audit_log', 'INSERT'), 1,
    'dnb_app still holds audit INSERT — six customer-API sites need it (F-8/T3)');
is_($priv('dnb_worker', 'mt_audit_log', 'INSERT'), 1,
    'dnb_worker still holds audit INSERT — intent.confirmed/failed need it (T2)');

t('default privileges — the fix does not expire for dnb_admin');

$owner->exec('CREATE TABLE mt_t1_default_probe (id int)');
try {
    is_($priv('dnb_admin', 'mt_t1_default_probe', 'INSERT'), 0,
        'a NEWLY created table grants dnb_admin no INSERT');
    is_($priv('dnb_app', 'mt_t1_default_probe', 'INSERT'), 1,
        'CONTROL: the same new table does still grant dnb_app INSERT (A-2, open)');
} finally {
    $owner->exec('DROP TABLE mt_t1_default_probe');
}

t('roles are enumerated from the database, never assumed');

$roles = $owner->query(
    "SELECT rolname, has_table_privilege(rolname,'mt_audit_log','INSERT')::int AS ins
       FROM pg_roles WHERE rolname LIKE 'dnb%' AND rolcanlogin ORDER BY rolname");
is_(count($roles) >= 6, true,
    'the login roles were read from pg_roles, not from a hardcoded list');
$writers = array_map(fn($r) => $r['rolname'],
    array_filter($roles, fn($r) => (int) $r['ins'] === 1));
is_(in_array('dnb_admin', $writers, true), false,
    'dnb_admin is absent from the login roles that can write audit rows');
is_(in_array('dnb_app', $writers, true) && in_array('dnb_worker', $writers, true), true,
    'CONTROL: the enumeration does find the roles that legitimately still can');

exit(t_summary());
