<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
use Dn\Db\Database;
use Dn\Tenancy\TenantContext;
use Dn\Policy\PlanRepository;

$owner = Database::inspector();
$ids = seed_two_customers($owner);
$A = $ids['A']; $B = $ids['B'];
$app = Database::app();
$ctx = new TenantContext($app);

t('audit writes and reads within a customer');
// Written the way production writes now: through a commercial boundary
// function (migration 024), which emits its audit row inside the same
// transaction. Dn\Audit\AuditLog is gone -- no login role may INSERT into
// mt_audit_log directly any more, so a class that did could only fail.
$plan = $ctx->run($A['customer'], fn($db) => (new PlanRepository($db))->create(
    ['name' => 'lobby', 'duration_s' => 3600,
     'rate_down_bps' => 2000000, 'rate_up_bps' => 1000000, 'data_cap_bytes' => null,
     'devices_per_voucher' => 1, 'mode' => 'elapsed',
     'price_minor' => 1000, 'currency' => 'UGX'],
    $A['principal'], $A['site'], '10.0.0.1'));
$id = $owner->one("SELECT id FROM mt_audit_log
                    WHERE customer_id = ? AND action = 'plan.created'",
                  [$A['customer']])['id'];
is_(is_string($id) && strlen($id) === 36, true, 'the act wrote exactly one audit row, and it has a uuid');
$rows = $ctx->run($A['customer'], fn($db) => $db->query(
    'SELECT action, detail FROM mt_audit_log ORDER BY at'));
// Three rows, not one: since migration 020 (W-1) onboarding writes its own
// audit row inside mt_customer_create, so seeding A is itself an audited act,
// and since 027 the first owner is created by mt_admin_principal_create, which
// audits too (actor_kind 'staff', the seeding identity). A test that still
// expected fewer rows would be asserting that a fix is absent.
is_(array_column($rows, 'action'), ['customer.created', 'principal.created', 'plan.created'],
    'A sees the onboarding rows it caused and the row its act wrote');
$planRow = array_values(array_filter($rows, fn($r) => $r['action'] === 'plan.created'))[0];
is_(json_decode($planRow['detail'], true)['name'], 'lobby', 'detail survives the round trip');

t('audit is isolated like everything else');
$rows = $ctx->run($B['customer'], fn($db) => $db->query(
    'SELECT action, target_id FROM mt_audit_log ORDER BY at'));
is_(array_column($rows, 'action'), ['customer.created', 'principal.created'],
    'B sees only its own two onboarding rows');
is_(in_array($plan['id'], array_column($rows, 'target_id'), true), false,
    "B cannot see A's audit rows");
$rows = $ctx->run($B['customer'], fn($db) => $db->query('SELECT id FROM mt_audit_log WHERE id = ?', [$id]));
is_(count($rows), 0, "B cannot read A's audit row by id");

t('audit is append-only — enforced by the database, not by convention');
throws_(fn() => $ctx->run($A['customer'], fn($db) =>
    $db->exec("UPDATE mt_audit_log SET action = 'tampered' WHERE id = ?", [$id])),
    'append-only', 'UPDATE is refused');
throws_(fn() => $ctx->run($A['customer'], fn($db) =>
    $db->exec('DELETE FROM mt_audit_log WHERE id = ?', [$id])),
    'append-only', 'DELETE is refused');

t('append-only survives the routes that usually bypass row triggers');
// TRUNCATE skips row-level triggers entirely. Without a statement-level
// trigger the append-only guarantee has a one-word hole in it.
throws_(fn() => $owner->pdo()->exec('TRUNCATE mt_audit_log'),
    'append-only', 'TRUNCATE is refused even for the owner');
// And the app role has no TRUNCATE privilege at all, so both layers hold.
throws_(fn() => $app->pdo()->exec('TRUNCATE mt_audit_log'),
    '', 'app role has no TRUNCATE privilege');

$still = $owner->one('SELECT action FROM mt_audit_log WHERE id = ?', [$id]);
is_($still['action'], 'plan.created', 'the row is genuinely intact after every attempt');

t('a system event with no customer belongs to nobody');
$owner->exec("INSERT INTO mt_audit_log (customer_id, actor, actor_kind, action)
              VALUES (NULL, 'system', 'system', 'job.ran')");
$rows = $ctx->run($A['customer'], fn($db) => $db->query("SELECT id FROM mt_audit_log WHERE action = 'job.ran'"));
is_(count($rows), 0, 'a NULL-customer system row is visible to no customer');

exit(t_summary());
