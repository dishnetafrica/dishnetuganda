<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * schema_doctor.php — which tables a migration promised and did not deliver.
 *
 *   php tools/schema_doctor.php
 *   php tools/schema_doctor.php --repair
 *
 * MigrationRunner split every file on every semicolon it found — including
 * the ones inside -- comments. Such a semicolon is not a statement
 * terminator, so the split truncated the statement above it, SQLite answered
 * "incomplete input", and the remainder of the English sentence was handed
 * to it as SQL. Both errors were swallowed as "partial", the file was
 * recorded as applied regardless, and the table it declared did not exist.
 *
 * The splitter is fixed. This is the other half: a database that already
 * booted has those files recorded as applied, so the fix alone will never
 * re-run them. Nothing notices, because most tables are also created
 * defensively by their own service at runtime — the ones with no such
 * service are simply absent, and the first anyone hears of it is a page
 * failing on "no such table".
 *
 * Every migration statement is written IF NOT EXISTS, so re-applying a file
 * that is already correct does nothing at all. --repair re-runs only the
 * files whose tables are actually missing.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/MigrationRunner.php';

$args = array_slice($argv, 1);
foreach ($args as $a) {
    if (strpos($a, '--') !== 0) continue;
    if (!in_array($a, ['--repair', '--yes'], true)) {
        fwrite(STDERR, "\n  Unknown option: {$a}\n");
        fwrite(STDERR, "  Known options are --repair (and --yes to skip the prompt).\n\n");
        exit(2);
    }
}
$repair = in_array('--repair', $args, true);

$dataDir = cliDataDir($root);
$store   = SqliteStore::create($dataDir);
$pdo     = $store->getPdo();

// What the migrations say should exist.
$declared = [];          // table => [files that declare it]
$files    = glob($root . '/migrations/*.sql') ?: [];
sort($files);
foreach ($files as $f) {
    $sql = MigrationRunner::stripComments((string)file_get_contents($f));
    if (preg_match_all('/CREATE TABLE IF NOT EXISTS\s+\[?([A-Za-z0-9_]+)\]?/i', $sql, $m)) {
        foreach ($m[1] as $tbl) $declared[strtolower($tbl)][] = basename($f);
    }
}

// What is actually there.
$present = [];
foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type='table'") as $r) {
    $present[strtolower((string)$r['name'])] = true;
}

$missing = [];           // file => [tables]
foreach ($declared as $tbl => $from) {
    if (isset($present[$tbl])) continue;
    foreach ($from as $file) $missing[$file][] = $tbl;
}

echo "\n  SCHEMA DOCTOR — " . gmdate('Y-m-d H:i') . " UTC\n\n";
printf("    %-22s %s\n", 'database', $dataDir . '/plugin.sqlite3');
printf("    %-22s %d\n", 'migration files', count($files));
printf("    %-22s %d\n", 'tables declared', count($declared));
printf("    %-22s %d\n", 'tables present', count($present));
echo "\n";

if ($missing === []) {
    echo "  Every table a migration declares is present. Nothing to repair.\n\n";
    // Say which files were recorded as only partly applied, even when the
    // schema is now whole — it is the difference between "it was repaired"
    // and "it was never broken".
    $log = $dataDir . '/migration.log';
    if (is_file($log)) {
        $partials = [];
        foreach (array_slice(file($log) ?: [], -400) as $line) {
            if (stripos($line, 'PARTIAL:') !== false) $partials[trim($line)] = true;
        }
        if ($partials !== []) {
            echo "  The log does show " . count($partials) . " partial application(s) in the\n";
            echo "  recent past. The tables are all here now, so those statements were\n";
            echo "  either harmless or have since been created by their own service.\n\n";
        }
    }
    exit(0);
}

echo "  MISSING TABLES\n\n";
foreach ($missing as $file => $tables) {
    printf("    %-34s %s\n", $file, implode(', ', $tables));
}
echo "\n";

if (!$repair) {
    echo "  These migrations are recorded as applied, so they will never re-run\n";
    echo "  on their own. Every statement in them is written IF NOT EXISTS, so\n";
    echo "  re-applying is safe — nothing that already exists is touched.\n\n";
    echo "    php tools/schema_doctor.php --repair\n\n";
    exit(1);
}

echo "  REPAIRING\n\n";
$fixed = 0; $stillMissing = [];
foreach ($missing as $file => $tables) {
    $path = $root . '/migrations/' . $file;
    if (!is_file($path)) { echo "    {$file}: not found\n"; continue; }

    $sql  = MigrationRunner::stripComments((string)file_get_contents($path));
    $ok = 0; $errs = [];
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        try { $pdo->exec($stmt); $ok++; }
        catch (\Throwable $e) {
            $msg = $e->getMessage();
            // Re-applying a file means most of it is already in place.
            if (stripos($msg, 'already exists') !== false
                || stripos($msg, 'duplicate column') !== false) { $ok++; continue; }
            $errs[] = substr(preg_replace('/\s+/', ' ', $stmt), 0, 60) . ' — ' . $msg;
        }
    }

    $left = [];
    foreach ($tables as $t) {
        $n = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'
                               AND lower(name) = " . $pdo->quote($t))->fetchColumn();
        if ($n === 0) $left[] = $t;
    }

    if ($left === []) {
        printf("    ok    %-30s %d statement(s), %s created\n", $file, $ok, implode(', ', $tables));
        $fixed++;
    } else {
        printf("    FAIL  %-30s %s still missing\n", $file, implode(', ', $left));
        foreach (array_slice($errs, 0, 3) as $e) echo "            {$e}\n";
        $stillMissing[] = $file;
    }
}

echo "\n";
echo "  {$fixed} migration(s) repaired.\n";
if ($stillMissing !== []) {
    echo "  " . count($stillMissing) . " could not be: " . implode(', ', $stillMissing) . "\n";
    echo "  Those need a person — the statement above says what SQLite refused.\n\n";
    exit(1);
}
echo "\n";
exit(0);
