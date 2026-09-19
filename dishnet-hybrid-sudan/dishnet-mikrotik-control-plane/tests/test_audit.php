<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
use Dn\Db\Database;
use Dn\Tenancy\TenantContext;
use Dn\Audit\AuditLog;

$owner = Database::owner();
$ids = seed_two_customers($owner);
$A = $ids['A']; $B = $ids['B'];
$app = Database::app();
$ctx = new TenantContext($app);

t('audit writes and reads within a customer');
$id = $ctx->run($A['customer'], fn($db) => (new AuditLog($db))->record(
    $A['customer'], $A['principal'], 'principal', 'site.created',
    'site', $A['site'], '10.0.0.1', ['name' => 'lobby']));
is_(is_string($id) && strlen($id) === 36, true, 'record() returns a uuid');
$rows = $ctx->run($A['customer'], fn($db) => $db->query('SELECT action, detail FROM mt_audit_log'));
is_(count($rows), 1, 'A sees its own audit row');
is_(json_decode($rows[0]['detail'], true)['name'], 'lobby', 'detail survives the round trip');

t('audit is isolated like everything else');
$rows = $ctx->run($B['customer'], fn($db) => $db->query('SELECT id FROM mt_audit_log'));
is_(count($rows), 0, "B cannot see A's audit rows");
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
is_($still['action'], 'site.created', 'the row is genuinely intact after every attempt');

t('a system event with no customer belongs to nobody');
$owner->exec("INSERT INTO mt_audit_log (customer_id, actor, actor_kind, action)
              VALUES (NULL, 'system', 'system', 'job.ran')");
$rows = $ctx->run($A['customer'], fn($db) => $db->query("SELECT id FROM mt_audit_log WHERE action = 'job.ran'"));
is_(count($rows), 0, 'a NULL-customer system row is visible to no customer');

exit(t_summary());
