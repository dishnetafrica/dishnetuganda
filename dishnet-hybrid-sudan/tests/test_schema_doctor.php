<?php
declare(strict_types=1);
/**
 * test_schema_doctor.php — repairing a migration that was recorded as done.
 *
 * The runner marked every file applied whether or not its statements landed,
 * so a file that lost a CREATE TABLE to the semicolon-in-a-comment bug is
 * recorded as applied and will never run again. Fixing the splitter does not
 * help a database that already booted: the damage is in the past and the
 * bookkeeping says everything is fine.
 *
 * So this drops a table out of a healthy database — which is exactly the
 * state such a database is in — and asserts the doctor finds it, names the
 * file that was supposed to create it, and puts it back.
 */
require_once dirname(__DIR__) . '/lib/StoreInterface.php';
require_once dirname(__DIR__) . '/lib/JsonStore.php';
require_once dirname(__DIR__) . '/lib/SqliteStore.php';

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; } }

$tmp = sys_get_temp_dir() . '/dn_schemadoc_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);
$store = SqliteStore::create($tmp);
$pdo   = $store->getPdo();

$run = function (string $args = '') use ($root, $tmp): array {
    $out = []; $rc = 0;
    exec(sprintf('DN_DATA_DIR=%s php %s %s 2>&1',
         escapeshellarg($tmp), escapeshellarg($root . '/tools/schema_doctor.php'), $args), $out, $rc);
    return [implode("\n", $out), $rc];
};

// ── A healthy database ──────────────────────────────────────────────────────
echo "\nA database with nothing wrong with it\n";
[$o, $rc] = $run();
is_($rc === 0, 'exits clean', $o);
is_(strpos($o, 'Nothing to repair') !== false, 'and says there is nothing to repair', $o);

// ── Now break it exactly the way the bug did ────────────────────────────────
echo "\nA table the migration was supposed to create, missing\n";
$pdo->exec("DROP TABLE IF EXISTS overdue_workbench_log");
$pdo->exec("DROP TABLE IF EXISTS sl_suspension_log");
is_((int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name='overdue_workbench_log'")->fetchColumn() === 0,
    'the table is gone');
// The bookkeeping still says the migration ran — which is the whole problem.
$applied = (int)$pdo->query("SELECT COUNT(*) FROM _migrations WHERE filename LIKE '060%'")->fetchColumn();
is_($applied === 1, 'and the migration is still recorded as applied');

[$o, $rc] = $run();
is_($rc === 1, 'the doctor reports a problem', $o);
is_(strpos($o, 'overdue_workbench_log') !== false, 'naming the missing table', $o);
is_(strpos($o, '060_overdue_workbench.sql') !== false, 'and the file that declares it', $o);
is_(strpos($o, 'sl_suspension_log') !== false, 'and the second one too', $o);
is_(strpos($o, '--repair') !== false, 'and says what to run', $o);
is_((int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name='overdue_workbench_log'")->fetchColumn() === 0,
    'without --repair it changed nothing');

// ── Repair ──────────────────────────────────────────────────────────────────
echo "\nRepairing\n";
[$o, $rc] = $run('--repair');
is_($rc === 0, 'the repair succeeds', $o);
is_(strpos($o, 'repaired') !== false, 'and says so', $o);
foreach (['overdue_workbench_log', 'sl_suspension_log'] as $tbl) {
    is_((int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name='{$tbl}'")->fetchColumn() === 1,
        "{$tbl} is back");
}

// Re-applying a file means re-running statements that already succeeded, so
// the tables that were NOT missing must be untouched rather than recreated.
$rows = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn();
[$o, $rc] = $run();
is_($rc === 0, 'and the database is healthy again', $o);
is_((int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn() === $rows,
    'a second look changes nothing');

// ── Data already in a good table is not lost ────────────────────────────────
echo "\nRepairing does not touch what is already there\n";
$pdo->exec("INSERT INTO stock_categories (title, sku, service_type, track_mode, created_at)
            VALUES ('Keep Me','KEEP','general','serial','2026-09-11')");
$pdo->exec("DROP TABLE IF EXISTS overdue_workbench_log");
[$o, $rc] = $run('--repair');
is_($rc === 0, 'the repair runs', $o);
is_((int)$pdo->query("SELECT COUNT(*) FROM stock_categories WHERE sku='KEEP'")->fetchColumn() === 1,
    'the row in an untouched table survived — every statement is IF NOT EXISTS');

echo "\nAn unknown flag\n";
[$o, $rc] = $run('--fixit');
is_($rc === 2 && strpos($o, 'Unknown option') !== false,
    'is named rather than ignored', $o);

exec('rm -rf ' . escapeshellarg($tmp));
echo "\n  {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
