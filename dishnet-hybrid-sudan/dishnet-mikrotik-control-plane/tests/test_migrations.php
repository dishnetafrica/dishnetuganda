<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
use Dn\Db\Database;
use Dn\Db\Migrator;

$owner = Database::owner();

t('migrations are idempotent — a second run applies nothing');
$again = (new Migrator($owner, dirname(__DIR__) . '/migrations'))->run(true);
is_($again, [], 're-running the migrator is a no-op');

t('every migration file is recorded exactly once');
$files = array_map('basename', glob(dirname(__DIR__) . '/migrations/*.sql'));
sort($files);
$rows = array_column($owner->query('SELECT filename FROM mt_migrations ORDER BY filename'), 'filename');
is_($rows, $files, 'mt_migrations matches the files on disk');
$dupes = $owner->query('SELECT filename FROM mt_migrations GROUP BY filename HAVING count(*) > 1');
is_(count($dupes), 0, 'no migration recorded twice');

t('the schema is what the plan said it would be');
foreach (['mt_customers','mt_principals','mt_auth_sessions','mt_services',
          'mt_entitlements','mt_sites','mt_audit_log','mt_idempotency'] as $tbl) {
    $r = $owner->one('SELECT to_regclass(?) AS t', [$tbl]);
    is_($r['t'], $tbl, "{$tbl} exists");
}

t('ids are uuids, not sequences — a sequence would let one customer infer another\'s volume');
foreach (['mt_customers','mt_principals','mt_services','mt_entitlements','mt_sites','mt_audit_log'] as $tbl) {
    $r = $owner->one(
        'SELECT data_type FROM information_schema.columns
          WHERE table_name = ? AND column_name = ?', [$tbl, 'id']);
    is_($r['data_type'], 'uuid', "{$tbl}.id is a uuid");
}
$seqs = $owner->query("SELECT sequencename FROM pg_sequences WHERE schemaname = 'public'");
is_(count($seqs), 0, 'no sequences exist at all');

t('money, when it arrives, has nowhere to become a float');
// No money columns in step 1 — asserted so the rule is already in force when
// mt_plans and mt_vouchers land in step 4/5 (docs/30: price_minor integers).
$floats = $owner->query(
    "SELECT table_name, column_name FROM information_schema.columns
      WHERE table_schema = 'public' AND data_type IN ('double precision','real')");
is_(count($floats), 0, 'no floating-point column exists anywhere');

exit(t_summary());
