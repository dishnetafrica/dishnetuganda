<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use Dn\Db\Database;
use Dn\Tenancy\TenantContext;

/**
 * The audit write boundary — migrations 022 and 023, docs/113 (A-1).
 *
 * A-1: roles holding migration 015's blanket grant could INSERT into
 * mt_audit_log directly, so the audit trail was FORGEABLE. The table is
 * append-only by trigger, so a forged row could never be removed — worse than
 * a forgeable business write, which can be reversed.
 *
 *   T1 (022)  dnb_admin   REVOKED — its only caller is the excluded simulator
 *   T2 (023)  dnb_worker  REVOKED — its two audit sites now go through
 *                         mt_intent_audit(), a dnb_def_work definer
 *   T3        dnb_app     STILL HOLDS IT. Not an omission: none of its six
 *                         audited mutations sits behind a definer function to
 *                         move the audit into, and granting EXECUTE on
 *                         mt_audit_write would relocate the forgery rather
 *                         than remove it. Building those functions is B-2.
 *
 * The residue is asserted, not described, so closing B-2/T3 breaks these lines
 * rather than leaving the last forging role to be forgotten.
 *
 * Every negative assertion is paired with something proving the measurement
 * could have come out the other way, and the revoke itself is proved by
 * execution with the grant restored and withdrawn again.
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
is_($priv('dnb_worker', 'mt_audit_log', 'INSERT'), 0,
    'dnb_worker no longer holds audit INSERT — T2 closed it');

t('A-1/T2 — the worker audits through a definer, not a direct INSERT');

$fnpriv = fn(string $role): int => (int) $owner->one(
    "SELECT has_function_privilege(?, (SELECT oid FROM pg_proc WHERE proname='mt_intent_audit'),
            'EXECUTE')::int AS p", [$role])['p'];

is_($owner->one("SELECT pg_get_userbyid(proowner) AS o FROM pg_proc
                  WHERE proname = 'mt_intent_audit'")['o'], 'dnb_def_work',
    'mt_intent_audit is owned by the role that already owns the intent lifecycle');
is_($fnpriv('dnb_worker'), 1, 'dnb_worker may EXECUTE it');
is_($fnpriv('dnb_app'),    0, 'dnb_app may not');
is_($fnpriv('dnb_admin'),  0, 'dnb_admin may not');

// An intent to audit, enqueued the way the customer API does.
$ctxApp2 = new TenantContext($app);
$intentId = $ctxApp2->run($A, fn(Database $db) => (new \Dn\Intents\IntentQueue($db))
    ->enqueue($A, 'voucher.publish', ['probe' => true], null, 'probe', 'x', null, 'system'))['id'];
is_(is_string($intentId) && $intentId !== '', true, 'CONTROL: an intent exists to audit');

$worker = Database::worker();
$b4 = (int) $ins->one("SELECT count(*)::int AS c FROM mt_audit_log
                        WHERE target_id = ? AND action = 'intent.confirmed'", [$intentId])['c'];
$worker->one('SELECT mt_intent_audit(?,?,?)', [$intentId, 'worker:t2', 'confirmed']);
$rows = $ins->query("SELECT * FROM mt_audit_log
                      WHERE target_id = ? AND action = 'intent.confirmed'", [$intentId]);
is_(count($rows), $b4 + 1, 'the worker produced exactly one audit row');
is_($rows[0]['actor'], 'worker:t2', 'the actor is the identity the worker was given');
is_($rows[0]['actor_kind'], 'system', 'actor_kind is fixed to system by the function');
is_($rows[0]['customer_id'], $A, 'the customer was DERIVED from the intent, not supplied');

throws_(fn() => $worker->one('SELECT mt_intent_audit(?,?,?)',
    [$intentId, 'worker:t2', 'device.stolen']),
    'confirmed or failed', 'the action vocabulary is closed — an arbitrary action is refused');
throws_(fn() => $worker->one('SELECT mt_intent_audit(?,?,?)',
    ['00000000-0000-0000-0000-000000000000', 'worker:t2', 'confirmed']),
    'no such intent', 'an audit row cannot be attached to an intent that does not exist');

t('A-1/T2 — the worker keeps the privileges it legitimately needs');

is_($priv('dnb_worker', 'mt_intents', 'UPDATE'), 1,
    'dnb_worker still holds mt_intents UPDATE — the claim/lease is not audit');
$claimed = $worker->query('SELECT * FROM mt_intent_claim(?, ?::interval, ?)',
    ['worker:t2', '60 seconds', 5]);
is_(is_array($claimed), true, 'and mt_intent_claim still runs for the worker');

t('A-1/T2 — the revoke is proved by EXECUTION, and the control has subject matter');

// A grant is the weakest evidence this project accepts, so the boundary is
// measured by running the statement, not by reading has_table_privilege.
$ctxWorker = new TenantContext($worker);
$forge = fn(Database $db) => $db->exec(
    'INSERT INTO mt_audit_log (customer_id,actor,actor_kind,action,target_type,target_id,source)
     VALUES (?,?,?,?,?,?,?)',
    [$A, 'FORGERY', 'system', 'probe.forge', 'probe', 'x', 'worker']);

throws_(fn() => $ctxWorker->run($A, $forge), 'permission denied',
    'dnb_worker attempting a direct audit INSERT is refused');

// CONTROL ON THE CONTROL: restore the grant and the identical statement must
// succeed. Without this, the refusal above could be any failure at all — a
// broken column list, a dead connection, RLS — rather than the revoke.
$owner->exec('GRANT INSERT ON mt_audit_log TO dnb_worker');
try {
    $granted = null;
    try {
        $ctxWorker->run($A, function (Database $db) use ($forge, &$granted) {
            $granted = $forge($db);
            throw new RuntimeException('__rollback__');   // leave no forged row behind
        });
    } catch (RuntimeException $e) {
        if ($e->getMessage() !== '__rollback__') { throw $e; }
    }
    is_($granted, 1, 'CONTROL: with the grant restored the SAME statement inserts a row');
} finally {
    $owner->exec('REVOKE INSERT ON mt_audit_log FROM dnb_worker');
}

throws_(fn() => $ctxWorker->run($A, $forge), 'permission denied',
    'and with the revoke back in place it is refused again');
is_((int) $ins->one("SELECT count(*)::int AS c FROM mt_audit_log
                      WHERE actor = 'FORGERY'")['c'], 0,
    'no forged row survived the control — the probe left nothing behind');

t('A-1/T3 — NOT done, and the reason is asserted rather than described');

// T3 cannot be completed by granting dnb_app EXECUTE on mt_audit_write.
// Measured: of mt_audit_log's ten columns only `id` and `at` are not caller
// parameters, so EXECUTE on that function is exactly as forgeable as INSERT —
// it would relocate the forgery, not remove it, while looking remediated.
$notParams = $owner->query(
    "SELECT attname FROM pg_attribute
      WHERE attrelid = 'mt_audit_log'::regclass AND attnum > 0 AND NOT attisdropped
        AND attname NOT IN ('customer_id','actor','actor_kind','action',
                            'target_type','target_id','source','detail')");
is_(array_column($notParams, 'attname'), ['id', 'at'],
    'every audit column but id and at is a caller parameter of mt_audit_write');

$exec = fn(string $role): int => (int) $owner->one(
    "SELECT has_function_privilege(?, (SELECT oid FROM pg_proc WHERE proname='mt_audit_write'),
            'EXECUTE')::int AS p", [$role])['p'];
is_($exec('dnb_def_prov'), 1,
    'CONTROL: the probe reports 1 for a role that does hold EXECUTE on mt_audit_write');
is_($exec('dnb_app'), 0, 'dnb_app holds no EXECUTE on mt_audit_write — and must not be given it');

// The residue itself. dnb_app writes its own audit rows at six call sites, and
// none of the six mutations sits behind a definer function to move them into
// (that is B-2). This count is asserted so that closing B-2 breaks this line
// rather than leaving the residue to be forgotten.
$routes = file_get_contents(__DIR__ . '/../src/Api/Routes.php');
is_(substr_count($routes, 'new \\Dn\\Audit\\AuditLog($db))->record('), 6,
    'six caller-written audit sites remain on the customer API (F-8 / T3 open)');

$defs = $owner->query(
    "SELECT p.proname FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
      WHERE n.nspname = 'public' AND p.prosecdef
        AND has_function_privilege('dnb_app', p.oid, 'EXECUTE')
      ORDER BY 1");
$names = array_column($defs, 'proname');
is_(count(array_filter($names, fn($n) => str_starts_with($n, 'mt_auth_'))), 5,
    'CONTROL: dnb_app does reach definer functions — the five auth ones');
is_(array_values(array_filter($names, fn($n) => !str_starts_with($n, 'mt_auth_'))),
    ['mt_voucher_redeem'],
    'and the only other one is mt_voucher_redeem, which has no caller and is to be deleted');

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
is_(in_array('dnb_worker', $writers, true), false,
    'dnb_worker is absent too, after T2');
is_(in_array('dnb_app', $writers, true), true,
    'CONTROL: the enumeration does still find dnb_app, which legitimately can');

exit(t_summary());
