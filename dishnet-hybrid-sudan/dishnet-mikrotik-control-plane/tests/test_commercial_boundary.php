<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use Dn\Db\Database;
use Dn\Tenancy\TenantContext;
use Dn\Policy\PlanRepository;
use Dn\Sessions\SessionService;
use Dn\Vouchers\VoucherService;

/**
 * The customer-plane commercial write boundary — migration 024 (A-1/T3, B-2).
 *
 * Six mutations used to happen in a repository class with the audit row
 * written beside them by the caller: "caller-written and skippable" (docs/86
 * F-8). Each is now one SECURITY DEFINER function owned by dnb_def_comm which
 * performs the write AND its audit row in the same transaction, and dnb_app
 * holds no INSERT on mt_audit_log at all.
 *
 * The property under test throughout is the PAIR: a successful mutation
 * leaves exactly one audit row, and a REFUSED one leaves none. An audit row
 * that can be written without the act, or an act that can be performed
 * without the audit row, both fail here.
 *
 * Tenancy is not enforced by the function bodies. dnb_def_comm was created
 * with no policy of its own, so it inherits <table>_isolation FOR ALL TO
 * public and is bound by customer_id = mt_current_customer() exactly as
 * dnb_app is. Every cross-customer refusal below is therefore RLS, measured
 * through the function rather than asserted about it.
 */

$ins = Database::inspector();          // BYPASSRLS — reads audit across tenants
$app = Database::app();
$ctx = new TenantContext($app);
$ids = seed_two_customers($ins);
$A = $ids['A']; $B = $ids['B'];

/** Audit rows for one customer and action. The inspector sees every tenant. */
$audits = fn(string $customer, string $action): array => $ins->query(
    'SELECT * FROM mt_audit_log WHERE customer_id = ? AND action = ? ORDER BY at',
    [$customer, $action]);
/** Total audit rows, so "wrote nothing at all" can be asserted, not just "wrote no plan.created". */
$allAudit = fn(): int => (int) $ins->one('SELECT count(*)::int AS c FROM mt_audit_log')['c'];

$planValues = fn(string $name): array => [
    'name' => $name, 'duration_s' => 3600,
    'rate_down_bps' => 2000000, 'rate_up_bps' => 1000000, 'data_cap_bytes' => null,
    'devices_per_voucher' => 1, 'mode' => 'elapsed',
    'price_minor' => 1000, 'currency' => 'UGX'];

// ===========================================================================
t('the boundary exists, is owned by its own role, and is reachable by dnb_app alone');

$SIX = ['mt_plan_create', 'mt_plan_update', 'mt_plan_retire',
        'mt_voucher_batch_issue', 'mt_voucher_revoke', 'mt_session_disconnect_request'];

$fn = fn(string $name): array => $ins->one(
    "SELECT pg_get_userbyid(proowner) AS owner, prosecdef,
            pg_get_function_arguments(oid) AS args,
            has_function_privilege('dnb_app', oid, 'EXECUTE')::int    AS app,
            has_function_privilege('dnb_admin', oid, 'EXECUTE')::int  AS admin,
            has_function_privilege('dnb_worker', oid, 'EXECUTE')::int AS worker
       FROM pg_proc WHERE proname = ?", [$name]);

foreach ($SIX as $name) {
    $f = $fn($name);
    is_($f['owner'], 'dnb_def_comm', "{$name} is owned by dnb_def_comm, not dnb_app");
    is_($f['prosecdef'], true, "{$name} is SECURITY DEFINER");
    is_([$f['app'], $f['admin'], $f['worker']], [1, 0, 0],
        "{$name}: dnb_app may execute it; dnb_admin and dnb_worker may not");
    // Derive, never accept: there is no customer parameter to forge.
    is_(str_contains($f['args'], 'customer'), false,
        "{$name} takes no customer parameter at all");
}
is_(str_contains($fn('mt_plan_create')['args'], 'p_actor_principal uuid'), true,
    'CONTROL: the argument probe does read real parameters — the actor is one');

is_((int) $ins->one(
    "SELECT has_function_privilege('dnb_app',
      (SELECT oid FROM pg_proc WHERE proname='mt_audit_write'),'EXECUTE')::int AS p")['p'], 0,
    'and dnb_app still may NOT execute the generic audit writer');
is_((int) $ins->one(
    "SELECT has_table_privilege('dnb_app','mt_audit_log','INSERT')::int AS p")['p'], 0,
    'nor INSERT into mt_audit_log directly');

// ===========================================================================
t('plan.create — one act, one audit row, every field derived');

$before = $allAudit();
$plan = $ctx->run($A['customer'], fn(Database $db) =>
    (new PlanRepository($db))->create($planValues('lobby hour'), $A['principal'],
                                      $A['site'], '10.1.1.1'));
is_($plan['customer_id'], $A['customer'], 'the plan belongs to the authenticated customer');
is_($plan['site_id'], $A['site'], 'and to the site it was given');
is_($plan['profile_id'] !== null, true, 'the enforcement profile was resolved for it');

$rows = $audits($A['customer'], 'plan.created');
is_(count($rows), 1, 'exactly one audit row');
is_($allAudit(), $before + 1, 'and exactly one row in the whole table');
is_($rows[0]['actor'], $A['principal'], 'actor is the authenticated principal');
is_($rows[0]['actor_kind'], 'principal', 'actor_kind is principal');
is_($rows[0]['target_id'], $plan['id'], 'target is the plan that was actually created');
is_($rows[0]['source'], '10.1.1.1', 'request context is recorded');
is_(json_decode($rows[0]['detail'], true)['name'], 'lobby hour', 'detail survives');

// ===========================================================================
t('plan.create — a refused create writes NOTHING');

// A cross-customer site: A names B's site id, which A cannot even read.
$before = $allAudit();
throws_(fn() => $ctx->run($A['customer'], fn(Database $db) =>
    (new PlanRepository($db))->create($planValues('forged site'), $A['principal'],
                                      $B['site'], '10.1.1.1')),
    'site is not this customer', "A cannot attach a plan to B's site");
is_($allAudit(), $before, 'and the refusal wrote no audit row');

// A cross-customer actor: B's principal is invisible inside A's context.
throws_(fn() => $ctx->run($A['customer'], fn(Database $db) =>
    (new PlanRepository($db))->create($planValues('forged actor'), $B['principal'],
                                      $A['site'], '10.1.1.1')),
    'actor is not a principal', "A cannot attribute a plan to B's principal");
is_($allAudit(), $before, 'still no audit row');

// No tenant context at all: the customer cannot be defaulted or guessed.
throws_(fn() => $ctx->runUnscoped(fn(Database $db) =>
    (new PlanRepository($db))->create($planValues('no tenant'), $A['principal'],
                                      null, '10.1.1.1')),
    'no tenant context', 'and with no tenant context it refuses outright');
is_($allAudit(), $before, 'no audit row from any of the three refusals');
is_(count($audits($A['customer'], 'plan.created')), 1,
    'CONTROL: the one legitimate plan.created row is still there');

// ===========================================================================
t('plan.update and plan.retire — cross-customer targets are simply not there');

$before = $allAudit();
is_($ctx->run($B['customer'], fn(Database $db) =>
    (new PlanRepository($db))->update($plan['id'], ['price_minor' => 1],
                                      $B['principal'], '10.2.2.2')), null,
    "B updating A's plan returns null — RLS, not a check in the function");
is_($allAudit(), $before, 'and wrote no audit row');
is_($ctx->run($B['customer'], fn(Database $db) =>
    (new PlanRepository($db))->retire($plan['id'], $B['principal'], '10.2.2.2')), null,
    "B retiring A's plan returns null");
is_($allAudit(), $before, 'and wrote no audit row');

// CONTROL: the same two calls, by the owner, do work and do audit.
$upd = $ctx->run($A['customer'], fn(Database $db) =>
    (new PlanRepository($db))->update($plan['id'], ['price_minor' => 2500],
                                      $A['principal'], '10.1.1.1'));
is_((int) $upd['price_minor'], 2500, 'CONTROL: A can update its own plan');
is_($upd['name'], 'lobby hour', 'and a field left null is unchanged, not blanked');
is_(count($audits($A['customer'], 'plan.updated')), 1, 'which wrote exactly one audit row');

$ret = $ctx->run($A['customer'], fn(Database $db) =>
    (new PlanRepository($db))->retire($plan['id'], $A['principal'], '10.1.1.1'));
is_($ret['active'], false, 'CONTROL: A can retire its own plan');
is_(count($audits($A['customer'], 'plan.retired')), 1, 'which wrote exactly one audit row');

// ===========================================================================
t('voucher.issued — the batch, the registry and the intent are one transaction');

$live = $ctx->run($A['customer'], fn(Database $db) =>
    (new PlanRepository($db))->create($planValues('live plan'), $A['principal'],
                                      $A['site'], '10.1.1.1'));
$before = $allAudit();
$out = $ctx->run($A['customer'], fn(Database $db) =>
    (new VoucherService($db))->issueBatch($live['id'], 3, $A['site'],
                                          $A['principal'], null, '10.1.1.1'));
is_(count($out['vouchers']), 3, 'three vouchers exist immediately');
is_(count(array_unique(array_column($out['vouchers'], 'code'))), 3, 'with distinct codes');
is_((int) $out['batch']['issued_count'], 3, 'the batch records what it issued');
is_($out['batch']['state'], 'issued', 'and is marked issued');
is_($out['intent']['kind'], 'voucher.publish', 'publication was queued as an intent');
is_((int) $ins->one('SELECT count(*)::int AS c FROM mt_hotspot_users WHERE voucher_id = ANY(?::uuid[])',
    ['{' . implode(',', array_column($out['vouchers'], 'id')) . '}'])['c'], 3,
    'the AAA registry row per voucher is still written at issue (docs/86, carried over unchanged)');

$rows = $audits($A['customer'], 'voucher.issued');
is_(count($rows), 1, 'exactly one audit row for the batch');
is_($allAudit(), $before + 1, 'and exactly one row in the whole table');
is_($rows[0]['target_id'], $out['batch']['id'], 'targeting the batch');
is_((int) json_decode($rows[0]['detail'], true)['count'], 3, 'recording what was issued');

t('voucher.issued — another customer\'s plan is not a plan');

$before = $allAudit();
throws_(fn() => $ctx->run($B['customer'], fn(Database $db) =>
    (new VoucherService($db))->issueBatch($live['id'], 2, null,
                                          $B['principal'], null, '10.2.2.2')),
    'plan not found', "B cannot issue vouchers against A's plan");
is_($allAudit(), $before, 'and the refusal wrote no audit row');

// ===========================================================================
t('voucher.revoked — the state guard sits AHEAD of the intent and the audit row');

$v = $out['vouchers'][0];
$before = $allAudit();
$rev = $ctx->run($A['customer'], fn(Database $db) =>
    (new VoucherService($db))->revoke($v['id'], $A['principal'], '10.1.1.1'));
is_($rev['voucher']['state'], 'revoked', 'the voucher is revoked');
is_(count($audits($A['customer'], 'voucher.revoked')), 1, 'one audit row');
is_((int) $ins->one("SELECT count(*)::int AS c FROM mt_intents
                      WHERE kind='voucher.revoke' AND target_id = ?", [$v['id']])['c'], 1,
    'and one intent');

// RULE I-1: the replay is refused BEFORE anything is written, so it leaves no
// second intent and — because mt_audit_log is append-only — no false record
// that could never be corrected afterwards.
$mid = $allAudit();
$again = $ctx->run($A['customer'], fn(Database $db) =>
    (new VoucherService($db))->revoke($v['id'], $A['principal'], '10.1.1.1'));
is_($again, null, 'revoking it a second time is refused — an invalid state transition');
is_($allAudit(), $mid, 'and writes no second audit row');
is_((int) $ins->one("SELECT count(*)::int AS c FROM mt_intents
                      WHERE kind='voucher.revoke' AND target_id = ?", [$v['id']])['c'], 1,
    'and no second intent');

$before = $allAudit();
is_($ctx->run($B['customer'], fn(Database $db) =>
    (new VoucherService($db))->revoke($out['vouchers'][1]['id'], $B['principal'], '10.2.2.2')),
    null, "B cannot revoke A's voucher");
is_($allAudit(), $before, 'and wrote no audit row');
is_($ins->one('SELECT state FROM mt_vouchers WHERE id = ?',
    [$out['vouchers'][1]['id']])['state'], 'unused',
    "CONTROL: A's voucher is genuinely untouched, not merely unreported");

// ===========================================================================
t('session.disconnect_requested — boundary only, and the replay gap is ASSERTED');

$sid = $ins->one(
    "INSERT INTO mt_sessions (customer_id, acct_session_id, radius_username)
     VALUES (?,?,?) RETURNING id", [$A['customer'], 'T3-SESSION-1', 'probe'])['id'];

$before = $allAudit();
is_($ctx->run($B['customer'], fn(Database $db) =>
    (new SessionService($db))->requestDisconnect($sid, $B['principal'], '10.2.2.2')), null,
    "B cannot request a disconnect of A's session");
is_($allAudit(), $before, 'and wrote no audit row');

$i1 = $ctx->run($A['customer'], fn(Database $db) =>
    (new SessionService($db))->requestDisconnect($sid, $A['principal'], '10.1.1.1'));
is_(is_string($i1) && $i1 !== '', true, 'CONTROL: A can, and gets an intent id');
$rows = $audits($A['customer'], 'session.disconnect_requested');
is_(count($rows), 1, 'exactly one audit row');
is_($rows[0]['target_id'], $sid, 'targeting the session');

// NOT FIXED HERE, deliberately. docs/108 records session.disconnect replay as
// a blocker to be closed before F6-B; this suite asserts the gap so that
// closing it breaks this line rather than leaving it to be assumed closed.
$i2 = $ctx->run($A['customer'], fn(Database $db) =>
    (new SessionService($db))->requestDisconnect($sid, $A['principal'], '10.1.1.1'));
is_($i2 !== $i1, true, 'a replay STILL enqueues a second intent — the open blocker');
is_(count($audits($A['customer'], 'session.disconnect_requested')), 2,
    'and still writes a second audit row. T3 gave it a boundary, not replay safety');

// ===========================================================================
t('what T3 did NOT close — asserted, so it cannot quietly be assumed closed');

$priv = fn(string $tbl, string $p): int => (int) $ins->one(
    'SELECT has_table_privilege(?,?,?)::int AS p', ['dnb_app', $tbl, $p])['p'];

// Migration 015 granted dnb_app SELECT/INSERT/UPDATE/DELETE on ALL tables and
// only the audit INSERT has been taken back. So the audit trail can no longer
// be FORGED, but a mutation can still be made WITHOUT one by writing a
// business table directly. Making these six functions the ONLY write path is
// the remaining half of B-2: it needs its own caller audit, not least because
// test_rls_isolation.php deliberately writes these tables as dnb_app to prove
// RLS, and would measure permission denial instead if the grants went.
foreach (['mt_plans', 'mt_vouchers', 'mt_voucher_batches',
          'mt_hotspot_users', 'mt_intents'] as $tbl) {
    is_($priv($tbl, 'INSERT'), 1,
        "dnb_app still holds direct INSERT on {$tbl} — the B-2 remainder");
}
is_($priv('mt_audit_log', 'INSERT'), 0,
    'CONTROL: but not on mt_audit_log, which is the one T3 closed');

// And the reason a grant is the weakest evidence in this project, measured in
// one pair: dnb_app IS granted UPDATE on the audit log and still cannot use it.
is_($priv('mt_audit_log', 'UPDATE'), 1, 'dnb_app is still GRANTED audit UPDATE');
throws_(fn() => $ctx->run($A['customer'], fn(Database $db) => $db->exec(
    "UPDATE mt_audit_log SET action = 'tampered' WHERE customer_id = ?", [$A['customer']])),
    'append-only',
    'yet the append-only trigger refuses it — the grant was never the boundary');

exit(t_summary());
