<?php
declare(strict_types=1);
/**
 * test_migration_integrity.php — the semicolon in the comment.
 *
 * MigrationRunner split each file on every semicolon it found. A semicolon
 * inside a -- comment is not a statement terminator, so the split cut the
 * statement ABOVE it in half — SQLite answered "incomplete input" — and then
 * handed the rest of the English sentence to SQLite as if it were SQL. Both
 * errors were swallowed as "partial", the file was recorded as applied
 * anyway, and the table it was supposed to create simply did not exist.
 *
 * Two live migrations were losing a CREATE TABLE to this:
 *
 *   057  paused_macs_json ... DEFAULT '[]',  -- MACs we paused; will unpause
 *   060  -- Free-text trail (last note shown in list view; full history ...)
 *
 * Nobody noticed because most tables are also created defensively by their
 * own service at runtime. The ones without such a service were missing.
 *
 * Comments now come out before the split. These assertions hold that, and
 * hold every migration to actually applying end to end.
 */
require_once dirname(__DIR__) . '/lib/StoreInterface.php';
require_once dirname(__DIR__) . '/lib/JsonStore.php';
require_once dirname(__DIR__) . '/lib/SqliteStore.php';
require_once dirname(__DIR__) . '/lib/MigrationRunner.php';

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; } }

// ── stripComments ───────────────────────────────────────────────────────────
echo "\nComments come out, strings stay\n";
t('a trailing comment goes',
  trim(MigrationRunner::stripComments("SELECT 1 -- and a note\n")), 'SELECT 1');
t('a semicolon inside one goes with it',
  trim(MigrationRunner::stripComments("CREATE TABLE t (a TEXT) -- we paused; will unpause\n")),
  'CREATE TABLE t (a TEXT)');
t('a whole-line comment goes',
  trim(MigrationRunner::stripComments("-- header\nSELECT 1")), "SELECT 1");
// Two hyphens inside a quoted default are data. A regex that cannot see
// quotes would cut the statement in half right here.
t('two hyphens inside a string are left alone',
  trim(MigrationRunner::stripComments("INSERT INTO t VALUES ('a--b')")),
  "INSERT INTO t VALUES ('a--b')");
t("and an escaped quote does not end the string",
  trim(MigrationRunner::stripComments("INSERT INTO t VALUES ('it''s -- fine')")),
  "INSERT INTO t VALUES ('it''s -- fine')");
is_(substr_count(MigrationRunner::stripComments("a -- x\nb -- y\nc"), "\n") === 2,
    'line breaks survive, so error line numbers still mean something');

// ── Every migration file survives the split ─────────────────────────────────
echo "\nNo migration loses a statement to its own prose\n";
$files = glob($root . '/migrations/*.sql');
is_(count($files) > 50, 'the migrations are where they are expected', count($files) . ' found');
$damaged = [];
foreach ($files as $f) {
    $sql = (string)file_get_contents($f);
    foreach (explode(';', MigrationRunner::stripComments($sql)) as $chunk) {
        $chunk = trim($chunk);
        if ($chunk === '') continue;
        // Every real chunk must start with a SQL verb. A fragment of English
        // reaching this point is the exact symptom the splitter used to cause.
        if (!preg_match('/^(CREATE|ALTER|INSERT|UPDATE|DELETE|DROP|PRAGMA|BEGIN|COMMIT|WITH|SELECT|REPLACE)\b/i', $chunk)) {
            $damaged[] = basename($f) . ': ' . substr(preg_replace('/\s+/', ' ', $chunk), 0, 70);
        }
    }
}
is_($damaged === [], 'every chunk is a statement, not a sentence',
    implode("\n       ", array_slice($damaged, 0, 6)));

// ── A real run applies everything ───────────────────────────────────────────
echo "\nA fresh database gets the whole schema\n";
$tmp = sys_get_temp_dir() . '/dn_migr_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);
$store = SqliteStore::create($tmp);
$pdo   = $store->getPdo();

$applied = $pdo->query("SELECT COUNT(*) FROM _migrations")->fetchColumn();
is_((int)$applied === count($files), 'every migration file is recorded as applied',
    "applied {$applied} of " . count($files));

// Every table a migration declares must actually be there. This is the
// assertion that would have failed before the fix.
$declared = [];
foreach ($files as $f) {
    if (preg_match_all('/CREATE TABLE IF NOT EXISTS\s+([A-Za-z0-9_]+)/i',
                       MigrationRunner::stripComments((string)file_get_contents($f)), $m)) {
        foreach ($m[1] as $tbl) $declared[strtolower($tbl)] = basename($f);
    }
}
$present = [];
foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type='table'") as $r) {
    $present[strtolower($r['name'])] = true;
}
$missing = [];
foreach ($declared as $tbl => $from) if (!isset($present[$tbl])) $missing[] = "{$tbl} (from {$from})";
is_($missing === [], 'no declared table is missing from the database',
    implode(', ', $missing));

// The two that were being lost, named, so a regression is unmistakable.
foreach (['sl_suspension_state' => '057', 'sl_suspension_log' => '057',
          'overdue_workbench' => '060', 'overdue_workbench_log' => '060'] as $tbl => $mig) {
    is_(isset($present[$tbl]), "{$tbl} exists — migration {$mig} used to lose it");
}

// The new tables from this work.
foreach (['fin_audit', 'stock_purchase_items', 'stock_purchase_payments'] as $tbl) {
    is_(isset($present[$tbl]), "{$tbl} exists");
}
$qc = [];
foreach ($pdo->query("PRAGMA table_info(stock_quantities)") as $r) $qc[] = $r['name'];
is_(in_array('avg_cost', $qc, true), 'bulk stock has somewhere to keep its cost');

exec('rm -rf ' . escapeshellarg($tmp));
echo "\n  {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
