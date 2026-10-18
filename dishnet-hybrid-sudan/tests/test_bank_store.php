<?php
declare(strict_types=1);
/**
 * test_bank_store.php — T-B2 of the Bank screen v1 (5.18.97; SPEC-bank §f.2): lib/BankStatementStore.php (check, then
 * keep; the held file; the overlap view; the private folder) and lib/BankGate.php (the Uganda gate and the permission
 * function), on books made in a temporary data directory through SqliteStore::create(), so the real runner applies 088.
 *
 * Every statement here is GENERATED (tests/fixtures/bank_statement_gen.php) and invented: account 0000000001, holder
 * "RH-Account Holder One", counterparties "RH-CLIENT n", bank line numbers 0000000101…. A real statement is never a
 * fixture, and nothing is read from any data directory but this test's own.
 *
 * In order:
 *   A  migration 088: its 5 tables, 11 indexes and 11 triggers by name; the runner's log line; the 28 probes of
 *      bankspec/mig/probe.php and the 13 of mig2/db2.php (every accepted variant's lines insert; one check row per code
 *      with the parameter the code really returns; the digest recomputes from the stored row; four controls refused)
 *   B  flows: check → keep → lines; the first file needs the link; overlaps add only their new lines (re-padded, and
 *      spelt without a leading zero); a restated line is refused; the shared-line join at both ends; the same file
 *      twice; nothing new; two identical payments on one day; a space in every empty side; a PDF; the account checks
 *      C1–C4 (currency both ways, wrong last four, linked elsewhere, inactive, kind cash, SSP, unknown); no cb_accounts
 *      table at all; 088 not applied; the upload mapping; a cell ending in a line feed is a recorded refusal at the
 *      check, on a linked account and on a first file — never a throw, never a pass that cannot be kept (BK97-R2)
 *   C  concurrency and the keep order: keep twice; two keeps on two connections; a statement kept in between, before
 *      the keep and while it is between its copy and its transaction; a held file altered on disk; the TTL; discard;
 *      a process that DIES (memory exhausted, no catch runs) after the copy and before the COMMIT; a keep (a child
 *      process) that FAILS at its BEGIN IMMEDIATE while another keep of the same check holds its transaction open
 *      (BK97-R1: each attempt writes and deletes only a copy of its own)
 *   D  files: under bank_statements/<id>/, never uploads/; 0700 and 0600; the row's sha256 is the file's; containment
 *      refuses a row whose path leaves the folder (another folder, a link, a NUL), never accepting a path from a request
 *   E  posts nothing: around a check, a keep, a refusal, a discard, an expiry and an export, cb_ledger, cb_accounts and
 *      fin_audit hold the same rows byte for byte, cashInHand() is unchanged on a Uganda book where rule A is in force,
 *      and no object outside bank_* in sqlite_master changed; the module's code names no ledger write
 *   F  the gate: open() and the halves on the books of SPEC.md §c.1, switch on and off; the halves equal
 *      CashbookService::cashOnlyRuleInForce() on every book; files against a store copy that says the opposite, both
 *      ways; allows() on a read that throws, returns null, is inactive, is demoted inside the session window.
 *      5.18.97: showLink() — its truth table, a session with no id, a session that is not an admin's reading nothing,
 *      no write in a fresh process (a child; each of its three measurements with a control that moves it) and under
 *      PRAGMA query_only; latestLine() directly; ecobankNamed(), "eco" starting a word
 *   G  the trail: the events of each act, each with its actor; no check row, event, statement or profile holds the
 *      marker, the account number or the holder's name; no file but the database and the statement files does
 *   H  memory: check and keep at the caps (10,000 lines near BYTES_MAX over 9,800 kept) in children under
 *      memory_limit=64M, with the control that 16M does not complete            (full run only)
 *   I  weakened copies: each made by an anchor asserted to occur exactly once, each caught at the case named for it;
 *      an unchanged copy passes with the same tally                                (full run only)
 *   J  the end check: every vault written is this test's own; nothing is left behind
 *
 * Arguments: --root=<tree> judges that tree (a weakened copy); --cases-only runs A–G and J; --crash-child,
 * --race-child, --showlink-child and --memory-child are the children of C, F and H, which read their orders on STDIN.
 *
 *   php test_bank_store.php
 */

$root = dirname(__DIR__); $mode = '';
foreach (array_slice($argv, 1) as $a) {
    if (strncmp($a, '--root=', 7) === 0) $root = rtrim(substr($a, 7), '/');
    elseif (in_array($a, ['--cases-only', '--crash-child', '--memory-child', '--race-child', '--showlink-child'], true)) $mode = $a;
    else { fwrite(STDERR, "test_bank_store.php: unknown argument\n"); exit(2); }
}
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/MigrationRunner.php';
require_once $root . '/lib/EcobankDetailsCsv.php';
require_once $root . '/lib/BankStatementStore.php';
require_once $root . '/lib/BankGate.php';

const BK_CUR = ['UGX', 'USD'];

// ── C's child: one keep that dies at a named step. Memory runs out inside the probe: a fatal, so no catch and no
//    finally runs, exactly the death SPEC-bank §a.10 designs for ────────────────────────────────────────────────────
if ($mode === '--crash-child') {
    $p = json_decode((string)stream_get_contents(STDIN), true);
    if (!is_array($p)) { echo "no orders\n"; exit(2); }
    $s = SqliteStore::create((string)$p['dir']);
    $bs = new BankStatementStore($s->getPdo(), (string)$p['dir'], BK_CUR, ['probe' => function (string $at) use ($p): void {
        if ($at !== $p['step']) return;
        echo "dying at {$at}\n";
        ini_set('memory_limit', (string)(memory_get_usage(true) + 4194304));
        $waste = str_repeat('x', 268435456);
        echo strlen($waste), "\n";
    }]);
    $r = $bs->keep((int)$p['check'], (int)$p['acct'], (bool)$p['link'], ['id' => (int)$p['actor']]);
    echo 'returned ', json_encode($r), "\n";
    exit(0);
}

// ── C's race child: one keep that places its copy, says so, waits to be let go, then meets the other keep's write lock
//    with a short busy timeout, so its BEGIN IMMEDIATE fails (SQLITE_BUSY) and its failure path runs (BK97-R1) ─────
if ($mode === '--race-child') {
    $p = json_decode((string)stream_get_contents(STDIN), true);
    if (!is_array($p)) { echo "no orders\n"; exit(2); }
    $s = SqliteStore::create((string)$p['dir']);
    $s->getPdo()->exec('PRAGMA busy_timeout = ' . (int)$p['busy_ms']);
    $bs = new BankStatementStore($s->getPdo(), (string)$p['dir'], BK_CUR, ['probe' => function (string $at) use ($p): void {
        if ($at !== 'after_copy') return;
        touch((string)$p['placed']);
        for ($i = 0; $i < 1500 && !is_file((string)$p['go']); $i++) usleep(20000);
    }]);
    try {
        $r = $bs->keep((int)$p['check'], (int)$p['acct'], (bool)$p['link'], ['id' => (int)$p['actor']]);
        echo 'returned ', json_encode($r), "\n";
    } catch (\Throwable $e) {
        echo 'threw ', $e->getMessage(), "\n";
    }
    exit(0);
}

// ── F's child: showLink() in a process that has not yet read the staff table, as a request inside the 300-second
//    sign-in window is (the session copy came without a store read): what the one call changes, measured around it ───
if ($mode === '--showlink-child') {
    $p = json_decode((string)stream_get_contents(STDIN), true);
    if (!is_array($p)) { echo json_encode(['error' => 'no orders']), "\n"; exit(2); }
    $dir = (string)$p['dir'];
    $s = SqliteStore::create($dir);
    $pdo = $s->getPdo();
    $tc = function () use ($pdo): int { return (int)$pdo->query('SELECT total_changes()')->fetchColumn(); };
    $snap = function () use ($dir): array {                         // the files, and sqlite_master read on a second connection
        $h = [];
        foreach (['', '-wal'] as $x) $h[] = is_file($dir . '/plugin.sqlite3' . $x) ? hash_file('sha256', $dir . '/plugin.sqlite3' . $x) : '-';
        $o = new PDO('sqlite:' . $dir . '/plugin.sqlite3');
        $m = $o->query('SELECT type, name, tbl_name, sql FROM sqlite_master ORDER BY type, name')->fetchAll(PDO::FETCH_ASSOC);
        return [implode(' ', $h), hash('sha256', (string)json_encode($m)), $h[0] !== '-'];
    };
    [$f0, $m0, $main] = $snap(); $c0 = $tc();
    $r = BankGate::showLink($dir, $s, (array)$p['session']);
    $c1 = $tc(); [$f1, $m1] = $snap();
    $pdo->exec('CREATE TEMP TABLE rh_throwaway (x INTEGER)');         // the control: one planted INSERT moves total_changes()
    $pdo->exec('INSERT INTO rh_throwaway (x) VALUES (1)');
    $c2 = $tc();
    // The control of the other two measurements: a write to the main schema of this throwaway book moves the files and
    // sqlite_master as the second connection reads them (a TEMP table touches neither, so it cannot show that).
    $pdo->exec('CREATE TABLE rh_ctl (x INTEGER)');
    $pdo->exec('INSERT INTO rh_ctl (x) VALUES (1)');
    [$f2, $m2] = $snap();
    echo json_encode(['r' => $r, 'tc' => [$c0, $c1, $c2], 'files' => [$f0, $f1, $f2], 'master' => [$m0, $m1, $m2], 'main' => $main]), "\n";
    exit(0);
}

// ── H's child: one check or one keep, nothing else; its peak is the measurement ─────────────────────────────────────
if ($mode === '--memory-child') {
    $p = json_decode((string)stream_get_contents(STDIN), true);
    if (!is_array($p)) { echo json_encode(['error' => 'no orders']), "\n"; exit(2); }
    $s = SqliteStore::create((string)$p['dir']);
    $bs = new BankStatementStore($s->getPdo(), (string)$p['dir'], BK_CUR);
    if ($p['act'] === 'check') {
        $bytes = (string)file_get_contents((string)$p['csv']);
        $r = $bs->check((int)$p['acct'], $bytes, ['id' => (int)$p['actor']]);
        unset($bytes);
    } else {
        $r = $bs->keep((int)$p['check'], (int)$p['acct'], (bool)$p['link'], ['id' => (int)$p['actor']]);
    }
    echo json_encode(['r' => $r, 'peak' => memory_get_peak_usage(), 'peak_real' => memory_get_peak_usage(true),
        'limit' => (string)ini_get('memory_limit')]), "\n";
    exit(0);
}

require_once $root . '/lib/CashbookService.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/StaffJobsGate.php';
require_once $root . '/lib/currency.php';
require_once $root . '/tests/fixtures/bank_statement_gen.php';

$pass = 0; $fail = 0;
function t(string $name, $got, $want): void {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok   {$name}\n"; }
    else { $fail++; echo "  FAIL {$name}\n       got  " . substr((string)json_encode($got), 0, 400) . "\n       want " . substr((string)json_encode($want), 0, 400) . "\n"; }
}
function is_(bool $c, string $name, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$name}\n"; } else { $fail++; echo "  FAIL {$name}" . ($d !== '' ? "\n       " . substr($d, 0, 600) : '') . "\n"; }
}

// A plugin folder with a configuration or a book beside its code would hand its settings to every book below
// (PluginConfig reads <root>/data/config.json first). Refuse, before anything is built.
if (is_file($root . '/data/config.json') || is_file($root . '/data/kyc_config.json') || is_file($root . '/data/plugin.sqlite3')) {
    is_(false, 'this test runs from a code copy, never from a plugin folder holding a configuration or a book', $root . '/data');
    printf("\n%d passed, %d failed\n", $pass, $fail);
    exit(1);
}
foreach (['uganda', 'south-sudan'] as $prof) {
    if (!is_array(json_decode((string)@file_get_contents($root . '/profiles/' . $prof . '.json'), true))) {
        is_(false, "this code copy holds profiles/{$prof}.json (the Uganda gate reads it)", $root . '/profiles');
        printf("\n%d passed, %d failed\n", $pass, $fail);
        exit(1);
    }
}

// ── This run's own folder, vaults and error log, before any store exists ──────────────────────────────────────────
$T = rtrim(sys_get_temp_dir(), '/') . '/bk-tb2-' . getmypid() . '-' . bin2hex(random_bytes(3));
foreach (['/vaults', '/data-env', '/books', '/files'] as $d) mkdir($T . $d, 0700, true);
putenv('TMPDIR=' . $T);
putenv('DN_DATA_DIR=' . $T . '/data-env');
ini_set('log_errors', '1');
ini_set('error_log', $T . '/php-errors.log');
/** @var array<string,bool> every vault file this test named */
$VAULTS = [];
function bk_vault(string $file): void { global $VAULTS; $VAULTS[$file] = true; putenv('DN_VAULT_FILE=' . $file); }
bk_vault($T . '/vaults/boot.json');
$defVault = dirname($root) . '/.dishnet-sudan.vault.json';
clearstatcache();
$defBefore = is_file($defVault) ? [hash_file('sha256', $defVault), filemtime($defVault)] : null;
function bk_rmtree(string $d): void {
    if (!is_dir($d) || is_link($d)) { @unlink($d); return; }
    foreach (scandir($d) ?: [] as $e) if ($e !== '.' && $e !== '..') bk_rmtree($d . '/' . $e);
    @rmdir($d);
}
// Removed last of all: every SqliteStore registers a checkpoint at shutdown, so this queues itself behind them.
register_shutdown_function(function () use ($T) { register_shutdown_function(function () use ($T) { bk_rmtree($T); }); });

$ME  = ['id' => 7, 'name' => 'RH-Admin One', 'is_admin' => 1, 'is_active' => 1];
$ME2 = ['id' => 8, 'name' => 'RH-Admin Two', 'is_admin' => 1, 'is_active' => 1];
const BK_MARK = 'RHMARKER';
const BK_UG_CFG = ['tenant_profile' => 'uganda', 'currency_code' => 'UGX', 'currency_symbol' => 'UGX',
    'cashbook_base_currency' => 'UGX', 'cashbook_currencies' => 'UGX,USD'];

/** A store over a data directory (the real runner applies every migration on a fresh one). */
function bk_open(string $dir): array { $s = SqliteStore::create($dir); return ['dir' => $dir, 'store' => $s, 'pdo' => $s->getPdo()]; }
/** A book of its own: a byte copy of $from's database (VACUUM INTO), opened by SqliteStore, so 088 is already applied. */
function bk_copy(array $from, string $name): array {
    global $T;
    $dir = $T . '/books/' . $name;
    mkdir($dir, 0700, true);
    $from['pdo']->exec('VACUUM INTO ' . $from['pdo']->quote($dir . '/plugin.sqlite3'));
    return bk_open($dir);
}
function bk_bs(array $B, array $opt = []): BankStatementStore { return new BankStatementStore($B['pdo'], $B['dir'], BK_CUR, $opt); }
/** A call that may throw: a weakened copy must fail a NAMED case, not end the run. */
function bk_try(callable $f): array {
    try { $r = $f(); return is_array($r) ? $r : ['ok' => false, 'code' => 'NOT AN ARRAY']; }
    catch (\Throwable $e) { return ['ok' => false, 'code' => 'THROWS ' . get_class($e), 'param' => '', 'msg' => substr($e->getMessage(), 0, 160)]; }
}
function bk_pick(array $r, array $keys): array { $o = []; foreach ($keys as $k) $o[$k] = $r[$k] ?? null; return $o; }
function bk_count(PDO $pdo, string $table): int { return (int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn(); }
function bk_counts(PDO $pdo): array {
    $o = [];
    foreach (BankStatementStore::TABLES as $tb) $o[$tb] = bk_count($pdo, $tb);
    return $o;
}
function bk_row(PDO $pdo, string $sql, array $p = []): ?array { $st = $pdo->prepare($sql); $st->execute($p); $r = $st->fetch(PDO::FETCH_ASSOC); $st->closeCursor(); return $r ?: null; }
function bk_all(PDO $pdo, string $sql, array $p = []): array { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchAll(PDO::FETCH_ASSOC); }
function bk_events(PDO $pdo, int $after = 0): array { return array_column(bk_all($pdo, 'SELECT event FROM bank_line_events WHERE id > ? ORDER BY id', [$after]), 'event'); }
function bk_last_event_id(PDO $pdo): int { return (int)$pdo->query('SELECT COALESCE(MAX(id), 0) FROM bank_line_events')->fetchColumn(); }
function bk_perm(string $p): string { clearstatcache(); return is_file($p) || is_dir($p) ? sprintf('%04o', fileperms($p) & 0777) : 'absent'; }
/** A table's rows in rowid order, as count and sha256: 'absent' when the table does not exist. */
function bk_digest(PDO $pdo, string $table): string {
    $n = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = " . $pdo->quote($table))->fetchColumn();
    if ($n === 0) return 'absent';
    $rows = $pdo->query("SELECT * FROM {$table} ORDER BY rowid")->fetchAll(PDO::FETCH_ASSOC);
    return count($rows) . ':' . hash('sha256', (string)json_encode($rows));
}
/** sqlite_master, every object, or every object outside the bank_* tables. */
function bk_master(PDO $pdo, bool $bankToo = true): string {
    $rows = $pdo->query('SELECT type, name, tbl_name, sql FROM sqlite_master ORDER BY type, name')->fetchAll(PDO::FETCH_ASSOC);
    if (!$bankToo) $rows = array_values(array_filter($rows, function ($r) { return strncmp((string)$r['tbl_name'], 'bank_', 5) !== 0; }));
    return count($rows) . ':' . hash('sha256', (string)json_encode($rows));
}
/** The lines a file of these history lines reads as (the reader's own view). */
function bk_read(string $csv): array { $r = EcobankDetailsCsv::read($csv); return $r['ok'] ? $r['lines'] : []; }
function bk_slice(array $H, int $from, int $n, array $opt = [], string $ccy = 'UGX'): string { return g_file(array_slice($H, $from, $n), $ccy, $opt); }
/** The kept lines of an account, as stored, bank order. */
function bk_lines(PDO $pdo, int $acct): array { return bk_all($pdo, 'SELECT * FROM bank_statement_lines WHERE cb_account_id = ? ORDER BY cheque_number', [$acct]); }
/** How many fields of a stored line differ from the reader's line (the two differences 088 allows are the point). */
function bk_line_diff(array $s, array $l, string $ccy): array {
    $map = ['cheque_number' => 'cheque_number', 'seq_nmbr' => 'seq', 'sr_no' => 'sr_no', 'file_row' => 'file_row', 'posting_date' => 'posting_date',
        'value_date' => 'value_date', 'posting_raw' => 'posting_raw', 'value_raw' => 'value_raw', 'type_code' => 'type_code',
        'type_desc' => 'type_desc', 'txn_code' => 'txn_code', 'credit_text' => 'credit_text', 'debit_text' => 'debit_text',
        'credit_units' => 'credit_units', 'debit_units' => 'debit_units', 'running_text' => 'running_text', 'running_units' => 'running_units',
        'cust_ref' => 'cust_ref', 'bank_ref' => 'bank_ref', 'remarks' => 'remarks', 'loan_outstanding' => 'loan_outstanding',
        'total_amount' => 'total_amount', 'reference' => 'reference', 'customer_reference' => 'customer_reference',
        'transaction_reference' => 'transaction_reference', 'compare_digest' => 'compare_digest'];
    $bad = [];
    foreach ($map as $col => $k) if ((string)$s[$col] !== (string)$l[$k]) $bad[] = $col;
    if ((string)$s['currency'] !== $ccy) $bad[] = 'currency';
    if ((string)$s['status'] !== 'imported') $bad[] = 'status';
    if ((string)$s['fingerprint'] !== BankStatementStore::fingerprint($l)) $bad[] = 'fingerprint';
    return $bad;
}

echo "store: " . (string)realpath($root . '/lib/BankStatementStore.php') . "\n";

// ═══════════════════════════════════════════════════════════════════════════
echo "\nA. Migration 088, through the real runner\n";
$TPL = bk_open($T . '/books/template');
$tp = $TPL['pdo'];
$applied = $tp->query('SELECT filename FROM _migrations ORDER BY filename')->fetchAll(PDO::FETCH_COLUMN);
t('SqliteStore::create() applies every migration, 088 last', [count($applied), (string)end($applied)],
    [count(glob($root . '/migrations/*.sql') ?: []), '088_bank_statements.sql']);
$mlog = (string)@file_get_contents($TPL['dir'] . '/migration.log');
t('088: migration.log holds OK: 088_bank_statements.sql (27 stmts,', strpos($mlog, 'OK: 088_bank_statements.sql (27 stmts,') !== false, true);
$bad088 = array_values(array_filter(explode("\n", $mlog), function ($l) { return strpos($l, '088_') !== false && strpos($l, 'OK: ') === false; }));
t('088: the log has no other line for 088 (no PARTIAL, no FAIL)', $bad088, []);
t('088: the runner splits it into 27 statements', count(MigrationRunner::splitStatements((string)file_get_contents($root . '/migrations/088_bank_statements.sql'))), 27);
$names = function (string $type) use ($tp): array {
    return $tp->query("SELECT name FROM sqlite_master WHERE type = '{$type}' AND tbl_name LIKE 'bank\\_%' ESCAPE '\\' AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
};
t('088: the five tables', $names('table'), ['bank_account_profiles', 'bank_checks', 'bank_line_events', 'bank_statement_lines', 'bank_statements']);
t('088: the 11 indexes, by name', $names('index'), ['idx_bank_checks_acct', 'idx_bank_checks_waiting', 'idx_bank_events_acct', 'idx_bank_events_check',
    'idx_bank_lines_date', 'idx_bank_lines_ref', 'idx_bank_lines_stmt', 'uq_bank_lines_chq', 'uq_bank_profiles_acct', 'uq_bank_statements_check',
    'uq_bank_statements_sha']);
t('088: the 11 triggers, by name', $names('trigger'), ['bank_checks_fixed', 'bank_checks_moves', 'bank_checks_no_delete', 'bank_events_no_delete',
    'bank_events_no_update', 'bank_lines_fixed', 'bank_lines_no_delete', 'bank_profiles_no_delete', 'bank_profiles_no_update',
    'bank_statements_fixed', 'bank_statements_no_delete']);
t('088: the five tables are empty after boot', array_values(bk_counts($tp)), [0, 0, 0, 0, 0]);
t('088: the template book has no cb_accounts table (CashbookService creates it at runtime)',
    (int)$tp->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'cb_accounts'")->fetchColumn(), 0);

// The 28 probes of bankspec/mig/probe.php, each as designed (line inserts carry compare_digest).
$PR = bk_copy($TPL, 'probes28'); $pp = $PR['pdo'];
$probe = function (string $label, string $sql, string $want) use ($pp): void {
    try { $pp->exec($sql); $g = 'ALLOWED'; } catch (PDOException $e) { $g = 'REFUSED'; }
    t("088 probe: {$label} — {$want}", $g, $want);
};
$pp->exec("INSERT INTO bank_checks (cb_account_id, sha256, bytes, profile, parser_version, outcome, status, checked_by_id) VALUES (7,'aa',10,'p',1,'passed','waiting',1)");
$pp->exec("INSERT INTO bank_checks (cb_account_id, sha256, bytes, profile, parser_version, outcome, code, param, status, checked_by_id) VALUES (7,'bb',10,'p',1,'refused','chain','12','closed',1)");
$probe('a refused check that waits', "INSERT INTO bank_checks (cb_account_id, sha256, bytes, profile, parser_version, outcome, status, checked_by_id) VALUES (7,'cc',10,'p',1,'refused','waiting',1)", 'REFUSED');
$probe('a param carrying text with a space', "INSERT INTO bank_checks (cb_account_id, sha256, bytes, profile, parser_version, outcome, param, status, checked_by_id) VALUES (7,'dd',10,'p',1,'refused','RH CLIENT ONE','closed',1)", 'REFUSED');
$probe('a param carrying lower case', "INSERT INTO bank_checks (cb_account_id, sha256, bytes, profile, parser_version, outcome, param, status, checked_by_id) VALUES (7,'ee',10,'p',1,'refused','rhclient','closed',1)", 'REFUSED');
$probe('waiting → kept without a statement id', "UPDATE bank_checks SET status='kept', closed_at=datetime('now') WHERE id=1", 'REFUSED');
$probe('waiting → discarded', "UPDATE bank_checks SET status='discarded', closed_by_id=1, closed_at=datetime('now') WHERE id=1", 'ALLOWED');
$probe('discarded → waiting', "UPDATE bank_checks SET status='waiting', closed_at=NULL WHERE id=1", 'REFUSED');
$probe('a refused check rewritten', "UPDATE bank_checks SET code='closing' WHERE id=2", 'REFUSED');
$probe('a check deleted', "DELETE FROM bank_checks WHERE id=2", 'REFUSED');
$stIns = "INSERT INTO bank_statements (cb_account_id, check_id, sha256, bytes, file_rel, profile, parser_version, currency, acct_last4, period_from, period_to, line_count, lines_added, lines_known, opening_stated, closing_stated, opening_derived, closing_derived, cheque_first, cheque_last, kept_by_id) VALUES ";
$pp->exec($stIns . "(7,1,'aa',10,'bank_statements/7/x.csv','p',1,'UGX','0001','2026-08-01','2026-08-07',2,2,0,0,90,0,90,'0000000101','0000000102',1)");
$probe('the same file kept twice for the account', $stIns . "(7,3,'aa',10,'f','p',1,'UGX','0001','a','b',2,2,0,0,90,0,90,'1','2',1)", 'REFUSED');
$probe('a statement whose closing does not chain', $stIns . "(7,4,'zz',10,'f','p',1,'UGX','0001','a','b',2,2,0,0,90,0,91,'1','2',1)", 'REFUSED');
$probe('a kept statement edited (file path)', "UPDATE bank_statements SET file_rel='elsewhere' WHERE id=1", 'REFUSED');
$probe('a kept statement deleted', "DELETE FROM bank_statements WHERE id=1", 'REFUSED');
$D = "'" . str_repeat('c', 64) . "'";
$li = "INSERT INTO bank_statement_lines (statement_id, cb_account_id, cheque_number, seq_nmbr, sr_no, file_row, posting_date, value_date, posting_raw, value_raw, type_code, type_desc, txn_code, credit_text, debit_text, credit_units, debit_units, running_text, running_units, currency, cust_ref, bank_ref, remarks, loan_outstanding, total_amount, reference, customer_reference, transaction_reference, fingerprint, compare_digest) VALUES ";
$pp->exec($li . "(1,7,'0000000101',1,'1',2,'2026-08-01','2026-08-01','01-AUG-26','01-AUG-26','NTRF','RH','RH01','.9','',90,0,'   0.90',90,'UGX','','','RH-CLIENT ONE','0','0','RH1','RH1','RH-CLIENT ONE','f',{$D})");
$probe('the same bank line twice for the account', $li . "(1,7,'0000000101',2,'2',3,'2026-08-01','2026-08-01','01-AUG-26','01-AUG-26','NTRF','RH','RH01','.9','',90,0,'   0.90',90,'UGX','','','RH-CLIENT TWO','0','0','RH1','RH1','RH-CLIENT TWO','g',{$D})", 'REFUSED');
$probe('the same bank line no. on ANOTHER account', $li . "(1,8,'0000000101',1,'1',2,'2026-08-01','2026-08-01','01-AUG-26','01-AUG-26','NTRF','RH','RH01','.9','',90,0,'   0.90',90,'USD','','','RH','0','0','RH1','RH1','RH','h',{$D})", 'ALLOWED');
$probe('a line with both credit and debit', $li . "(1,7,'0000000109',3,'3',4,'2026-08-01','2026-08-01','01-AUG-26','01-AUG-26','NTRF','RH','RH01','1','1',100,0,'1.00',100,'UGX','','','RH','0','0','RH1','RH1','RH','i',{$D})", 'REFUSED');
$probe('a nine-digit bank line no.', $li . "(1,7,'000000109',3,'3',4,'2026-08-01','2026-08-01','01-AUG-26','01-AUG-26','NTRF','RH','RH01','1','',100,0,'1.00',100,'UGX','','','RH','0','0','RH1','RH1','RH','j',{$D})", 'REFUSED');
$probe('an imported field edited (remarks)', "UPDATE bank_statement_lines SET remarks='RH EDITED' WHERE id=1", 'REFUSED');
$probe('an amount edited', "UPDATE bank_statement_lines SET credit_units=91 WHERE id=1", 'REFUSED');
$probe('a line deleted', "DELETE FROM bank_statement_lines WHERE id=1", 'REFUSED');
$probe('the status column moved (P3c room)', "UPDATE bank_statement_lines SET status='matched' WHERE id=1", 'ALLOWED');
$pp->exec("INSERT INTO bank_line_events (event, cb_account_id, actor_kind, actor_id, detail) VALUES ('statement_kept',7,'staff',1,'{}')");
$probe('a staff event without an actor', "INSERT INTO bank_line_events (event, actor_kind) VALUES ('x','staff')", 'REFUSED');
$probe('an event edited', "UPDATE bank_line_events SET detail='x' WHERE id=1", 'REFUSED');
$probe('an event deleted', "DELETE FROM bank_line_events WHERE id=1", 'REFUSED');
$pp->exec("INSERT INTO bank_account_profiles (cb_account_id, bank, profile, currency, acct_last4, confirmed_by_id) VALUES (7,'ecobank','p','UGX','0001',1)");
$probe('a second book account linked to the same Ecobank account', "INSERT INTO bank_account_profiles (cb_account_id, bank, profile, currency, acct_last4, confirmed_by_id) VALUES (9,'ecobank','p','UGX','0001',1)", 'REFUSED');
$probe('a full account number as last four', "INSERT INTO bank_account_profiles (cb_account_id, bank, profile, currency, acct_last4, confirmed_by_id) VALUES (10,'ecobank','p','USD','0000000001',1)", 'REFUSED');
$probe('a profile edited', "UPDATE bank_account_profiles SET acct_last4='0002' WHERE cb_account_id=7", 'REFUSED');
$probe('a profile deleted', "DELETE FROM bank_account_profiles WHERE cb_account_id=7", 'REFUSED');
$probe('P3c adds a column (ALTER ADD) and updates it', "ALTER TABLE bank_statements ADD COLUMN signoff_id INTEGER; UPDATE bank_statements SET signoff_id=1 WHERE id=1", 'ALLOWED');

// The 13 of bankspec/mig2/db2.php, on another copy, against this tree's reader and generator.
$M2 = bk_copy($TPL, 'probes13'); $mp = $M2['pdo'];
$cols = ['statement_id', 'cb_account_id', 'cheque_number', 'seq_nmbr', 'sr_no', 'file_row', 'posting_date', 'value_date', 'posting_raw', 'value_raw',
    'type_code', 'type_desc', 'txn_code', 'credit_text', 'debit_text', 'credit_units', 'debit_units', 'running_text', 'running_units', 'currency',
    'cust_ref', 'bank_ref', 'remarks', 'loan_outstanding', 'total_amount', 'reference', 'customer_reference', 'transaction_reference', 'fingerprint', 'compare_digest'];
$ins = $mp->prepare('INSERT INTO bank_statement_lines (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')');
$H40 = g_history(40, 3);
$variants = [
    'clean' => g_file($H40), 'shuffled' => g_file($H40, 'UGX', ['shuffle' => true]), 'no leading zero' => g_file($H40, 'UGX', ['nolead' => true]),
    'padded 25' => g_file($H40, 'UGX', ['pad' => 25]), 'usd' => g_file($H40, 'USD'),
    'space in the empty side' => g_file($H40, 'UGX', ['mutate' => function ($L) { foreach ($L as &$l) { if ($l['Credit'] === '') $l['Credit'] = ' '; else $l['Debit'] = ' '; } unset($l); return $L; }]),
    'backslash and multi-line remarks' => g_file($H40, 'UGX', ['mutate' => function ($L) { $L[4]['Remarks'] = "RH \\"; $L[5]['Remarks'] = "RH A\nRH B";
        $L[4]['Transaction Reference'] = $L[4]['Remarks']; $L[5]['Transaction Reference'] = $L[5]['Remarks']; return $L; }]),
];
$vacct = 100;
foreach ($variants as $vname => $csv) {
    $r = EcobankDetailsCsv::read($csv); $vacct++; $bad = 0;
    if (!$r['ok']) { t("088: variant '{$vname}' reads", $r['code'], 'ok'); continue; }
    $mp->beginTransaction();
    foreach ($r['lines'] as $l) {
        try { $ins->execute([1, $vacct, $l['cheque_number'], $l['seq'], $l['sr_no'], $l['file_row'], $l['posting_date'], $l['value_date'], $l['posting_raw'], $l['value_raw'],
            $l['type_code'], $l['type_desc'], $l['txn_code'], $l['credit_text'], $l['debit_text'], $l['credit_units'], $l['debit_units'], $l['running_text'], $l['running_units'],
            $r['facts']['currency'], $l['cust_ref'], $l['bank_ref'], $l['remarks'], $l['loan_outstanding'], $l['total_amount'], $l['reference'], $l['customer_reference'],
            $l['transaction_reference'], 'fp', $l['compare_digest']]); }
        catch (PDOException $e) { $bad++; }
    }
    $mp->commit();
    t("088: every line of '{$vname}' inserts under its CHECKs", $bad, 0);
}
$rows = $mp->query('SELECT * FROM bank_statement_lines')->fetchAll(PDO::FETCH_ASSOC); $mis = 0;
foreach ($rows as $row) { $row['side'] = $row['credit_text'] !== '' ? 'C' : 'D'; if (EcobankDetailsCsv::compareDigest($row) !== $row['compare_digest']) $mis++; }
t('088: the compare digest recomputes from every stored row (' . count($rows) . ')', [$mis, count($rows) === 280], [0, true]);
// One check row per code, with the parameter the code really returns: the reader's and fit()'s from real files, the
// store's in the shapes it returns them.
$set = function (int $at, string $col, string $v) { return function ($L) use ($at, $col, $v) { $L[$at][$col] = $v; return $L; }; };
$side = function (string $v) { return function ($L) use ($v) { $c = $L[5]['Credit'] !== '' ? 'Credit' : 'Debit'; $L[5][$c] = $v; return $L; }; };
$f40 = function (array $o) use ($H40) { return g_file($H40, 'UGX', $o); };
$H70 = g_history(70, 3);
$fitv = function (array $file, array $kept) {
    $fMin = $file[0]['cheque_number']; $fMax = $file[count($file) - 1]['cheque_number']; $known = [];
    foreach ($kept as $k) if (strcmp($k['cheque_number'], $fMin) >= 0 && strcmp($k['cheque_number'], $fMax) <= 0) $known[$k['cheque_number']] = $k['compare_digest'];
    $e = function ($k) { return ['cheque_number' => $k['cheque_number'], 'posting_date' => $k['posting_date'], 'running_units' => $k['running_units'],
        'credit_units' => $k['credit_units'], 'debit_units' => $k['debit_units']]; };
    return EcobankDetailsCsv::fit($file, $known, $e($kept[0]), $e($kept[count($kept) - 1]));
};
$hdrRow = '"' . implode('","', EcobankDetailsCsv::HEADER) . '"' . "\n";
$outs = [
    EcobankDetailsCsv::read(''), EcobankDetailsCsv::read(str_repeat('a', EcobankDetailsCsv::effectiveCap() + 1)), EcobankDetailsCsv::read('%PDF-1'),
    EcobankDetailsCsv::read("PK\x03\x04"), EcobankDetailsCsv::read(g_file($H40) . "\0"), EcobankDetailsCsv::read("\"a\xff\"\n"),
    EcobankDetailsCsv::read(str_replace("\n", "\r", g_file($H40))), EcobankDetailsCsv::read(g_summary(true)),
    EcobankDetailsCsv::read(str_replace('"Credit"', '"Credit Amount"', g_file($H40))), EcobankDetailsCsv::read(g_file($H40) . "\"a\"\n"),
    EcobankDetailsCsv::read($hdrRow . str_repeat(str_repeat('"",', 22) . "\"\"\n", EcobankDetailsCsv::LINES_MAX + 1)),
    EcobankDetailsCsv::read($f40(['mutate' => $set(3, 'Remarks', str_repeat('R', 1001))])), EcobankDetailsCsv::read($f40(['mutate' => $set(9, 'Account', '0000000002')])),
    EcobankDetailsCsv::read($f40(['mutate' => $set(9, 'Closing Available', 'UGX 1.00')])), EcobankDetailsCsv::read($f40(['mutate' => $set(9, 'SEQUENCE_NMBR', '0')])),
    EcobankDetailsCsv::read($f40(['mutate' => $set(3, 'Value Date', '32-AUG-26')])),
    EcobankDetailsCsv::read($f40(['mutate' => function ($L) { $L[5]['Credit'] = '1'; $L[5]['Debit'] = '1'; return $L; }])),
    EcobankDetailsCsv::read($f40(['mutate' => $side('1,000')])), EcobankDetailsCsv::read($f40(['mutate' => $side('1.125')])),
    EcobankDetailsCsv::read($f40(['mutate' => $set(7, 'Running Balance', '  12.5')])), EcobankDetailsCsv::read($f40(['mutate' => $set(20, 'Running Balance', '  1.00')])),
    EcobankDetailsCsv::read($f40(['mutate' => function ($L) { array_pop($L); return $L; }])), EcobankDetailsCsv::read($f40(['mutate' => $set(8, 'Cheque Number', '1')])),
    EcobankDetailsCsv::read($f40(['mutate' => function ($L) { $L[8]['Cheque Number'] = $L[7]['Cheque Number']; return $L; }])),
    EcobankDetailsCsv::read($f40(['mutate' => function ($L) { [$L[8]['Cheque Number'], $L[7]['Cheque Number']] = [$L[7]['Cheque Number'], $L[8]['Cheque Number']]; return $L; }])),
    $fitv(bk_read(bk_slice($H70, 30, 40, ['mutate' => $set(2, 'Remarks', 'RH RESTATED')])), bk_read(bk_slice($H70, 0, 40))),
    (function () use ($fitv, $H70) { $k = bk_read(bk_slice($H70, 0, 40)); array_splice($k, 20, 1); return $fitv(bk_read(g_file($H70)), $k); })(),
    $fitv(bk_read(bk_slice($H70, 40, 30)), bk_read(bk_slice($H70, 0, 40))), $fitv(bk_read(bk_slice($H70, 0, 40)), bk_read(bk_slice($H70, 40, 30))),
    $fitv(bk_read(bk_slice($H70, 0, 40)), bk_read(bk_slice($H70, 0, 40))),
];
$params = [];
foreach ($outs as $r) if (!$r['ok']) $params[$r['code']][$r['param']] = true;
$owned = array_merge(EcobankDetailsCsv::READER_CODES, EcobankDetailsCsv::FIT_CODES); sort($owned);
$gotCodes = array_keys($params); sort($gotCodes);
t('088: the files produce every code the reader and fit() own', $gotCodes, $owned);
foreach (['not_ready' => '', 'not_received' => '4', 'account_not_offered' => '', 'currency_mismatch' => 'USD|UGX', 'account_other' => '0002',
          'account_linked_elsewhere' => '0001', 'already_kept' => '2026-10-17', 'link_required' => '', 'changed_since_check' => '', 'check_expired' => ''] as $c => $p) {
    $params[$c][$p] = true;
}
t('088: the store\'s codes are exactly STORE_CODES', array_keys(array_diff_key($params, array_flip($owned))), BankStatementStore::STORE_CODES);
$chk = $mp->prepare("INSERT INTO bank_checks (cb_account_id, sha256, bytes, profile, parser_version, outcome, code, param, status, checked_by_id) VALUES (7, ?, 1, 'p', 1, ?, ?, ?, 'closed', 1)");
$i = 0; $bad = [];
foreach ($params as $code => $ps) foreach (array_keys($ps) as $p) {
    $outcome = in_array($code, ['nothing_new', 'already_kept'], true) ? $code : 'refused';
    try { $chk->execute([sprintf('%064d', ++$i), $outcome, $code, (string)$p]); } catch (PDOException $e) { $bad[] = "{$code}:{$p}"; }
}
t("088: one check row per code and parameter ({$i} rows, " . count($params) . ' codes) inserts', $bad, []);
$ctl = function (string $code, string $p) use ($chk): string { try { $chk->execute([bin2hex(random_bytes(32)), 'refused', $code, $p]); return 'ALLOWED'; } catch (PDOException $e) { return 'REFUSED'; } };
t('088 control: a lower-case param is refused', $ctl('not_csv', 'pdf'), 'REFUSED');
t('088 control: a param with a space is refused', $ctl('gap_after', '2026-08-07 TO 2026-08-09'), 'REFUSED');
try { $ins->execute([1, 999, '0000000999', 1, '1', 2, '2026-08-01', '2026-08-01', '01-AUG-26', '01-AUG-26', 'NTRF', 'RH', 'RH01', ' ', '5', 0, 500, '5.00', 500, 'UGX', '', '', 'RH', '0', '0', 'RH1', 'RH1', 'RH', 'fp', str_repeat('a', 64)]); $c = 'ALLOWED'; }
catch (PDOException $e) { $c = 'REFUSED'; }
t('088 control: an untrimmed empty side (" ") is refused (why the reader stores it trimmed)', $c, 'REFUSED');
try { $mp->exec("UPDATE bank_statement_lines SET compare_digest = '" . str_repeat('b', 64) . "' WHERE id = 1"); $c = 'ALLOWED'; } catch (PDOException $e) { $c = 'REFUSED'; }
t('088 control: compare_digest is fixed by the trigger', $c, 'REFUSED');

// ═══════════════════════════════════════════════════════════════════════════
echo "\nB. Flows\n";
// The Uganda template: the book's accounts and a few ledger rows, written through the Cashbook's own writers.
$UGT = bk_copy($TPL, 'ug-template');
$cbT = new CashbookService($UGT['store'], $UGT['dir']);
$ACC = [];
foreach ([['ugx', 'RH-Ecobank UGX', 'UGX', 'bank'], ['usd', 'RH-Ecobank USD', 'USD', 'bank'], ['cash', 'RH-Cash UGX', 'UGX', 'cash'],
          ['old', 'RH-Old Bank UGX', 'UGX', 'bank'], ['ssp', 'RH-Bank SSP', 'SSP', 'bank'], ['ugx2', 'RH-Second Bank UGX', 'UGX', 'bank'],
          ['usdcash', 'RH-Cash USD', 'USD', 'cash']] as [$k, $n, $c, $kind]) {
    $ACC[$k] = (int)($cbT->addAccount($n, $c, $kind)['id'] ?? 0);
}
$cbT->setAccountActive($ACC['old'], false);
$cbT->recordOpeningBalance($ACC['cash'], 210.00, '2026-09-01', 'RH-opening cash', '', 'RH-Tester');
$cbT->recordOpeningBalance($ACC['ugx'], 820.00, '2026-09-01', 'RH-opening bank', '', 'RH-Tester');
$cbT->recordOpeningBalance($ACC['usdcash'], 40.00, '2026-09-01', 'RH-opening cash usd', '', 'RH-Tester');
foreach ([['2026-09-03', 'in', 500.00, 'UGX', 0, 'RH-receipt'], ['2026-09-04', 'out', 20.00, 'UGX', $ACC['cash'], 'RH-cash out'],
          ['2026-09-04', 'out', 33.00, 'UGX', $ACC['ugx'], 'RH-bank out'], ['2026-09-05', 'in', 50.00, 'USD', 0, 'RH-usd receipt'],
          ['2026-09-05', 'in', 25.00, 'USD', $ACC['usd'], 'RH-usd bank']] as [$dt, $dir, $amt, $cur, $acc, $desc]) {
    $cbT->addEntryRaw(['project' => 'dishnet', 'date' => $dt, 'direction' => $dir, 'amount' => $amt, 'currency' => $cur,
        'category' => $dir === 'in' ? 'Receipt' : 'Misc Expense', 'category_raw' => $dir === 'in' ? 'Receipt' : 'Misc Expense',
        'description' => $desc, 'status' => 'approved', 'approved_by' => 'RH-Tester', 'source' => 'manual', 'account_id' => $acc]);
}
is_(min($ACC) > 0 && bk_count($UGT['pdo'], 'cb_ledger') === 8, 'setup: the Uganda book holds its seven accounts and eight ledger rows');

$H = g_history(70, 3);
$UGX = $ACC['ugx']; $USD = $ACC['usd'];
$F1 = bk_copy($UGT, 'f1'); $p1 = $F1['pdo']; $bs = bk_bs($F1);
t('C1: the offered accounts are the active bank accounts in a book currency, UGX first', array_column($bs->offeredAccounts(), 'id'),
    [$ACC['ugx'], $ACC['ugx2'], $ACC['usd']]);
$c = bk_try(function () use ($bs, $UGX, $H, $ME) { return $bs->check($UGX, bk_slice($H, 0, 40), $ME); });
t('flow: a check of 1–40 passes and waits, 40 new, nothing known, the link still to confirm',
    bk_pick($c, ['ok', 'outcome', 'lines_new', 'lines_known', 'needs_link']), ['ok' => true, 'outcome' => 'passed', 'lines_new' => 40, 'lines_known' => 0, 'needs_link' => true]);
$c1 = (int)($c['check_id'] ?? 0);
$row = $bs->checkRow($c1) ?? [];
$held = $bs->heldPath($row);
t('flow: the check waits, holding its file in bank_statements/.pending/ under a server-made name', [(string)($row['status'] ?? ''),
    (bool)preg_match('#^bank_statements/\.pending/' . $c1 . '-[0-9a-f]{16}\.csv$#', (string)($row['pending_rel'] ?? '')), $held !== null],
    ['waiting', true, true]);
t('files: the held file is 0600 in a 0700 .pending, and its sha256 is the check\'s', [bk_perm((string)$held), bk_perm($F1['dir'] . '/bank_statements/.pending'),
    $held !== null && hash_file('sha256', $held) === ($row['sha256'] ?? '')], ['0600', '0700', true]);
t('flow: nothing is kept by a check (no statement, no line, no profile)', [bk_count($p1, 'bank_statements'), bk_count($p1, 'bank_statement_lines'),
    bk_count($p1, 'bank_account_profiles')], [0, 0, 0]);
$k = bk_try(function () use ($bs, $c1, $UGX, $ME) { return $bs->keep($c1, $UGX, false, $ME); });
t('flow: keep without the link is refused link_required, and the check still waits', [$k['code'] ?? null, (string)($bs->checkRow($c1)['status'] ?? ''),
    bk_count($p1, 'bank_statements'), $bs->heldPath($row) !== null], ['link_required', 'waiting', 0, true]);
$k = bk_try(function () use ($bs, $c1, $UGX, $ME) { return $bs->keep($c1, $UGX, true, $ME); });
$prof = $bs->profile($UGX) ?? [];
t('flow: link and keep — 40 lines kept, the account linked to ••••0001 in UGX by the actor',
    [bk_pick($k, ['ok', 'lines_added', 'lines_known', 'linked']), bk_pick($prof, ['bank', 'profile', 'currency', 'acct_last4']), (int)($prof['confirmed_by_id'] ?? 0)],
    [['ok' => true, 'lines_added' => 40, 'lines_known' => 0, 'linked' => true],
     ['bank' => 'ecobank', 'profile' => 'ecobank_account_details_csv', 'currency' => 'UGX', 'acct_last4' => '0001'], 7]);
$st1 = bk_row($p1, 'SELECT * FROM bank_statements WHERE id = ?', [(int)($k['statement_id'] ?? 0)]) ?? [];
$row = $bs->checkRow($c1) ?? $row;
t('flow: the check is kept, naming its statement, closed by the actor', [(string)($row['status'] ?? ''), (int)($row['statement_id'] ?? 0), (int)($row['closed_by_id'] ?? 0)],
    ['kept', (int)($st1['id'] ?? -1), 7]);
t('flow: the held file is gone once the statement is kept', $held !== null && !is_file($held), true);
$file1 = $F1['dir'] . '/' . (string)($st1['file_rel'] ?? 'none');
t('files: the kept file is under bank_statements/<account id>/, never under uploads/', [(bool)preg_match('#^bank_statements/' . $UGX . '/' . $c1 . '-[0-9a-f]{16}-[0-9a-f]{16}\.csv$#',
    (string)($st1['file_rel'] ?? '')), is_file($file1), is_dir($F1['dir'] . '/uploads')], [true, true, false]);
t('files: folders 0700, the kept file 0600', [bk_perm($F1['dir'] . '/bank_statements'), bk_perm($F1['dir'] . '/bank_statements/' . $UGX), bk_perm($file1)],
    ['0700', '0700', '0600']);
t('files: the row\'s sha256 and byte count are the kept file\'s, and its name carries the sha256', [is_file($file1) ? hash_file('sha256', $file1) : '',
    is_file($file1) ? (int)filesize($file1) : -1, substr((string)($st1['sha256'] ?? ''), 0, 16) === substr(basename($file1), strlen((string)$c1) + 1, 16)],
    [(string)($st1['sha256'] ?? 'x'), (int)($st1['bytes'] ?? -2), true]);
t('files: statementPath() finds the kept file through containment', $bs->statementPath($st1), realpath($file1) ?: 'no file');
t('flow: the statement row — 40 lines, 40 added, the period, the bank line range, the actor',
    ['line_count' => (int)($st1['line_count'] ?? -1), 'lines_added' => (int)($st1['lines_added'] ?? -1), 'lines_known' => (int)($st1['lines_known'] ?? -1),
     'currency' => (string)($st1['currency'] ?? ''), 'acct_last4' => (string)($st1['acct_last4'] ?? ''), 'cheque_first' => (string)($st1['cheque_first'] ?? ''),
     'cheque_last' => (string)($st1['cheque_last'] ?? ''), 'kept_by_id' => (int)($st1['kept_by_id'] ?? -1)],
    ['line_count' => 40, 'lines_added' => 40, 'lines_known' => 0, 'currency' => 'UGX', 'acct_last4' => '0001', 'cheque_first' => $H[0]['Cheque Number'],
     'cheque_last' => $H[39]['Cheque Number'], 'kept_by_id' => 7]);
$read40 = bk_read(bk_slice($H, 0, 40));
$stored = bk_lines($p1, $UGX); $diffs = [];
foreach ($stored as $i => $s) { $d = bk_line_diff($s, $read40[$i] ?? [], 'UGX'); if ($d) $diffs[$s['cheque_number']] = $d; }
t('flow: the 40 stored lines are the reader\'s lines, column by column, status imported, in bank order', [count($stored), $diffs], [40, []]);
$k2 = bk_try(function () use ($bs, $c1, $UGX, $ME) { return $bs->keep($c1, $UGX, true, $ME); });
t('flow: keeping the kept check again is the note already_kept, dated, and changes nothing', [bk_pick($k2, ['ok', 'code', 'note']),
    (string)($k2['param'] ?? '') === substr((string)($st1['kept_at'] ?? ''), 0, 10), bk_count($p1, 'bank_statements'), bk_count($p1, 'bank_statement_lines')],
    [['ok' => false, 'code' => 'already_kept', 'note' => true], true, 1, 40]);
$c = bk_try(function () use ($bs, $UGX, $H, $ME) { return $bs->check($UGX, bk_slice($H, 0, 40), $ME); });
$crow = $bs->checkRow((int)($c['check_id'] ?? 0)) ?? [];
t('flow: the same file checked again is the note already_kept, a closed check naming the kept statement',
    [bk_pick($c, ['ok', 'code', 'outcome', 'note']), (string)($crow['status'] ?? ''), (int)($crow['statement_id'] ?? 0), (string)($crow['pending_rel'] ?? 'x')],
    [['ok' => false, 'code' => 'already_kept', 'outcome' => 'already_kept', 'note' => true], 'closed', (int)($st1['id'] ?? -1), '']);
$c = bk_try(function () use ($bs, $UGX, $H, $ME) { return $bs->check($UGX, bk_slice($H, 0, 60, ['pad' => 20]), $ME); });
t('flow: a from-opening export re-padded to 20 adds only its 20 new lines', bk_pick($c, ['ok', 'lines_new', 'lines_known', 'needs_link']),
    ['ok' => true, 'lines_new' => 20, 'lines_known' => 40, 'needs_link' => false]);
$k = bk_try(function () use ($bs, $c, $UGX, $ME) { return $bs->keep((int)($c['check_id'] ?? 0), $UGX, false, $ME); });
t('flow: kept without the link: the account is linked already', [bk_pick($k, ['ok', 'lines_added', 'lines_known', 'linked']), count(bk_lines($p1, $UGX))],
    [['ok' => true, 'lines_added' => 20, 'lines_known' => 40, 'linked' => false], 60]);
$twoDp = function ($L) { foreach ($L as &$l) { if ($l['Credit'] !== '') $l['Credit'] = g_money($l['_cents']); else $l['Debit'] = g_money($l['_cents']); } unset($l); return $L; };
$c = bk_try(function () use ($bs, $UGX, $H, $ME, $twoDp) { return $bs->check($UGX, bk_slice($H, 0, 60, ['nolead' => true, 'pad' => 12, 'mutate' => $twoDp]), $ME); });
$r2dp = bk_read(bk_slice($H, 0, 60, ['mutate' => $twoDp])); $r1dp = bk_read(bk_slice($H, 0, 60));
is_(count($r2dp) === 60 && array_column($r2dp, 'credit_units') === array_column($r1dp, 'credit_units') && array_column($r2dp, 'debit_units') === array_column($r1dp, 'debit_units')
    && array_merge(array_column($r2dp, 'credit_text'), array_column($r2dp, 'debit_text')) !== array_merge(array_column($r1dp, 'credit_text'), array_column($r1dp, 'debit_text')),
    'flow: the control — that file spells its amounts otherwise (two decimals) for the same values');
t('flow: the same 60 lines with balances spelt without a leading zero, padded to 12, and amounts with two decimals are the note nothing_new', bk_pick($c, ['ok', 'code', 'outcome']),
    ['ok' => false, 'code' => 'nothing_new', 'outcome' => 'nothing_new']);
$restate = $H[32]['Cheque Number'];
$c = bk_try(function () use ($bs, $UGX, $H, $ME, $set) { return $bs->check($UGX, bk_slice($H, 30, 40, ['mutate' => $set(2, 'Remarks', 'RH RESTATED')]), $ME); });
t('flow: an overlapping export that restates a kept Remark is refused overlap_differs at that bank line', bk_pick($c, ['ok', 'code', 'param']),
    ['ok' => false, 'code' => 'overlap_differs', 'param' => $restate]);
$c = bk_try(function () use ($bs, $UGX, $H, $ME) { return $bs->check($UGX, bk_slice($H, 60, 10), $ME); });
t('flow: a file starting one line after the last kept line is refused gap_after, dated that line', bk_pick($c, ['ok', 'code', 'param']),
    ['ok' => false, 'code' => 'gap_after', 'param' => (string)EcobankDetailsCsv::date($H[59]['Posting Date'])]);
$c = bk_try(function () use ($bs, $UGX, $H, $ME) { return $bs->check($UGX, bk_slice($H, 59, 11), $ME); });
t('flow: a file that starts AT the last kept line is accepted: 10 new, 1 known', bk_pick($c, ['ok', 'lines_new', 'lines_known']), ['ok' => true, 'lines_new' => 10, 'lines_known' => 1]);
$k = bk_try(function () use ($bs, $c, $UGX, $ME) { return $bs->keep((int)($c['check_id'] ?? 0), $UGX, false, $ME); });
t('flow: kept: the account now holds the bank\'s 70 lines, none missing, in bank order', [bk_pick($k, ['ok', 'lines_added']), array_column(bk_lines($p1, $UGX), 'cheque_number')],
    [['ok' => true, 'lines_added' => 10], array_column($H, 'Cheque Number')]);

// The front join, on a book of its own.
$F2 = bk_copy($UGT, 'f2'); $p2 = $F2['pdo']; $bs2 = bk_bs($F2);
$keepFile = function (BankStatementStore $b, int $acct, string $csv, bool $link) use ($ME): array {
    $c = bk_try(function () use ($b, $acct, $csv) { global $ME; return $b->check($acct, $csv, $ME); });
    if (empty($c['ok'])) return ['ok' => false, 'code' => 'check ' . ($c['code'] ?? '?')];
    return bk_try(function () use ($b, $c, $acct, $link, $ME) { return $b->keep((int)$c['check_id'], $acct, $link, $ME); });
};
$k = $keepFile($bs2, $UGX, bk_slice($H, 30, 30), true);
t('join: a first file of lines 31–60 is linked and kept', bk_pick($k, ['ok', 'lines_added']), ['ok' => true, 'lines_added' => 30]);
$c = bk_try(function () use ($bs2, $UGX, $H, $ME) { return $bs2->check($UGX, bk_slice($H, 0, 20), $ME); });
t('join: an older file that does not reach the first kept line is refused gap_before, dated that line', bk_pick($c, ['ok', 'code', 'param']),
    ['ok' => false, 'code' => 'gap_before', 'param' => (string)EcobankDetailsCsv::date($H[30]['Posting Date'])]);
$c = bk_try(function () use ($bs2, $UGX, $H, $ME) { return $bs2->check($UGX, bk_slice($H, 0, 31), $ME); });
t('join: an older file that reaches the first kept line is accepted: 30 new, 1 known', bk_pick($c, ['ok', 'lines_new', 'lines_known']), ['ok' => true, 'lines_new' => 30, 'lines_known' => 1]);
$k = bk_try(function () use ($bs2, $c, $UGX, $ME) { return $bs2->keep((int)($c['check_id'] ?? 0), $UGX, false, $ME); });
$k3 = $keepFile($bs2, $UGX, g_file($H), false);
t('join: then a from-opening export adds the last 10; the account holds all 70 lines in bank order', [bk_pick($k, ['ok', 'lines_added']), bk_pick($k3, ['ok', 'lines_added', 'lines_known']),
    array_column(bk_lines($p2, $UGX), 'cheque_number')], [['ok' => true, 'lines_added' => 30], ['ok' => true, 'lines_added' => 10, 'lines_known' => 60], array_column($H, 'Cheque Number')]);

// Two identical payments on one day; a space in every empty side; a PDF.
$F3 = bk_copy($UGT, 'f3'); $p3 = $F3['pdo']; $bs3 = bk_bs($F3);
$H3 = g_history(12, 9);
foreach (['Posting Date', 'Value Date', 'Type Code', 'Type Description', 'Remarks', 'Transaction Code', 'Reference', 'Customer Reference',
          'Transaction Reference', '_cents', '_credit'] as $col) $H3[5][$col] = $H3[4][$col];
$H3 = g_rechain($H3, 5);
$k = $keepFile($bs3, $UGX, g_file($H3), true);
$L3 = bk_lines($p3, $UGX);
t('flow: two identical payments on one day are two kept lines, told apart by their bank line numbers', [bk_pick($k, ['ok', 'lines_added']),
    count($L3), ($L3[4]['remarks'] ?? 'a') === ($L3[5]['remarks'] ?? 'b') && ($L3[4]['credit_units'] ?? 1) === ($L3[5]['credit_units'] ?? 2)
    && ($L3[4]['posting_date'] ?? 1) === ($L3[5]['posting_date'] ?? 2), ($L3[4]['cheque_number'] ?? 'x') !== ($L3[5]['cheque_number'] ?? 'x')],
    [['ok' => true, 'lines_added' => 12], 12, true, true]);
$F3b = bk_copy($UGT, 'f3b'); $bs3b = bk_bs($F3b);
$spaced = g_file(array_slice($H, 0, 30), 'UGX', ['mutate' => function ($L) { foreach ($L as &$l) { if ($l['Credit'] === '') $l['Credit'] = ' '; else $l['Debit'] = ' '; } unset($l); return $L; }]);
$k = $keepFile($bs3b, $UGX, $spaced, true);
$L3b = bk_lines($F3b['pdo'], $UGX);
$emptyOk = count($L3b) === 30;
foreach ($L3b as $s) if (!(($s['credit_text'] === '') xor ($s['debit_text'] === ''))) $emptyOk = false;
t('flow: a file with a space in every empty side is kept, the empty side stored empty', [bk_pick($k, ['ok', 'lines_added']), $emptyOk], [['ok' => true, 'lines_added' => 30], true]);
$c = bk_try(function () use ($bs3, $UGX, $ME) { return $bs3->check($UGX, "%PDF-1.4\nRH invented\n", $ME); });
$crow = $bs3->checkRow((int)($c['check_id'] ?? 0)) ?? [];
t('flow: a PDF is a refused check, not_csv, PDF', [bk_pick($c, ['ok', 'code', 'param']), bk_pick($crow, ['outcome', 'code', 'param', 'status'])],
    [['ok' => false, 'code' => 'not_csv', 'param' => 'PDF'], ['outcome' => 'refused', 'code' => 'not_csv', 'param' => 'PDF', 'status' => 'closed']]);

// The account checks, C1–C4.
$F4 = bk_copy($UGT, 'f4'); $p4 = $F4['pdo']; $bs4 = bk_bs($F4);
$c = bk_try(function () use ($bs4, $USD, $H, $ME) { return $bs4->check($USD, bk_slice($H, 0, 10), $ME); });
t('account: a UGX file on the USD account is refused currency_mismatch UGX|USD', bk_pick($c, ['ok', 'code', 'param']), ['ok' => false, 'code' => 'currency_mismatch', 'param' => 'UGX|USD']);
$c = bk_try(function () use ($bs4, $UGX, $H, $ME) { return $bs4->check($UGX, bk_slice($H, 0, 10, [], 'USD'), $ME); });
t('account: a USD file on the UGX account is refused currency_mismatch USD|UGX', bk_pick($c, ['ok', 'code', 'param']), ['ok' => false, 'code' => 'currency_mismatch', 'param' => 'USD|UGX']);
$k = $keepFile($bs4, $UGX, bk_slice($H, 0, 10), true);
$other = bk_slice($H, 0, 12, ['mutate' => function ($L) { foreach ($L as &$l) $l['Account'] = '0000000002'; unset($l); return $L; }]);
$c = bk_try(function () use ($bs4, $UGX, $other, $ME) { return $bs4->check($UGX, $other, $ME); });
t('account: a file for ••••0002 on the account linked to ••••0001 is refused account_other 0002', [bk_pick($k, ['ok']), bk_pick($c, ['ok', 'code', 'param'])],
    [['ok' => true], ['ok' => false, 'code' => 'account_other', 'param' => '0002']]);
$c = bk_try(function () use ($bs4, $ACC, $H, $ME) { return $bs4->check($ACC['ugx2'], bk_slice($H, 0, 12), $ME); });
t('account: ••••0001 already linked to the first UGX account is refused on the second, account_linked_elsewhere 0001', bk_pick($c, ['ok', 'code', 'param']),
    ['ok' => false, 'code' => 'account_linked_elsewhere', 'param' => '0001']);
$before4 = [bk_counts($p4), bk_master($p4)];
foreach ([['an inactive bank account', $ACC['old']], ['a cash account', $ACC['cash']], ['a bank account in SSP, not a book currency', $ACC['ssp']],
          ['an account id that does not exist', 999]] as [$what, $id]) {
    $c = bk_try(function () use ($bs4, $id, $H, $ME) { return $bs4->check($id, bk_slice($H, 0, 12), $ME); });
    $kk = bk_try(function () use ($bs4, $id, $ME) { return $bs4->keep(1, $id, true, $ME); });
    $dd = bk_try(function () use ($bs4, $id, $ME) { return $bs4->discard(1, $id, $ME); });
    t("account: {$what} is not offered; nothing is written", [$c['code'] ?? null, (int)($c['check_id'] ?? -1), $kk['code'] ?? null, $dd['code'] ?? null,
        [bk_counts($p4), bk_master($p4)] === $before4], ['account_not_offered', 0, 'account_not_offered', 'account_not_offered', true]);
}
$cu = bk_try(function () use ($bs4, $USD, $H, $ME) { return $bs4->check($USD, bk_slice($H, 0, 5, [], 'USD'), $ME); });
$ev0 = bk_last_event_id($p4); $n0 = bk_counts($p4);
$kk = bk_try(function () use ($bs4, $cu, $UGX, $ME) { return $bs4->keep((int)($cu['check_id'] ?? 0), $UGX, true, $ME); });
t('account: a keep naming another account\'s check is refused and writes nothing', [$cu['ok'] ?? null, $kk['code'] ?? null, bk_last_event_id($p4) === $ev0, bk_counts($p4) === $n0,
    (string)($bs4->checkRow((int)($cu['check_id'] ?? 0))['status'] ?? '')], [true, 'changed_since_check', true, true, 'waiting']);

// No cb_accounts table at all; 088 not applied.
$F5 = bk_copy($TPL, 'f5-no-accounts'); $bs5 = bk_bs($F5);
$m5 = bk_master($F5['pdo']);
$c = bk_try(function () use ($bs5, $H, $ME) { return $bs5->check(1, bk_slice($H, 0, 12), $ME); });
$kk = bk_try(function () use ($bs5, $ME) { return $bs5->keep(1, 1, true, $ME); });
t('no cb_accounts table: nothing offered, nothing thrown, nothing written, sqlite_master byte-identical',
    [$bs5->offeredAccounts(), $c['code'] ?? null, $kk['code'] ?? null, array_values(bk_counts($F5['pdo'])), bk_master($F5['pdo']) === $m5, is_dir($F5['dir'] . '/bank_statements')],
    [[], 'account_not_offered', 'account_not_offered', [0, 0, 0, 0, 0], true, false]);
mkdir($T . '/books/bare', 0700);
$bare = new PDO('sqlite:' . $T . '/books/bare/plugin.sqlite3', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$bare->exec("CREATE TABLE cb_accounts (id INTEGER PRIMARY KEY, name TEXT, currency TEXT, kind TEXT, active INTEGER)");
$bare->exec("INSERT INTO cb_accounts VALUES (1, 'RH-Ecobank UGX', 'UGX', 'bank', 1)");
$mb = bk_master($bare);
$bsN = new BankStatementStore($bare, $T . '/books/bare', BK_CUR);
$c = bk_try(function () use ($bsN, $H, $ME) { return $bsN->check(1, bk_slice($H, 0, 12), $ME); });
$kk = bk_try(function () use ($bsN, $ME) { return $bsN->keep(1, 1, true, $ME); });
$dd = bk_try(function () use ($bsN, $ME) { return $bsN->discard(1, 1, $ME); });
t('088 not applied: not ready; check, keep and discard answer not_ready and write nothing', [$bsN->ready(), $c['code'] ?? null, $kk['code'] ?? null, $dd['code'] ?? null,
    bk_master($bare) === $mb, is_dir($T . '/books/bare/bank_statements')], [false, 'not_ready', 'not_ready', 'not_ready', true, false]);
$bare = null;

// The upload mapping: what never arrived writes nothing; the name is never needed.
$plain = $T . '/files/plain.csv'; file_put_contents($plain, bk_slice($H, 0, 3));
$capMb = EcobankDetailsCsv::capMb(EcobankDetailsCsv::effectiveCap());
t('upload: a file larger than PHP accepts is too_large with the cap in MB; an interrupted one, none, or one that was not uploaded is not_received',
    [BankStatementStore::uploaded(['error' => UPLOAD_ERR_INI_SIZE]), BankStatementStore::uploaded(['error' => UPLOAD_ERR_PARTIAL]),
     BankStatementStore::uploaded([]), BankStatementStore::uploaded(['error' => UPLOAD_ERR_OK, 'tmp_name' => $plain])],
    [['ok' => false, 'code' => 'too_large', 'param' => $capMb, 'check_id' => 0, 'note' => false], ['ok' => false, 'code' => 'not_received', 'param' => $capMb, 'check_id' => 0, 'note' => false],
     ['ok' => false, 'code' => 'not_received', 'param' => $capMb, 'check_id' => 0, 'note' => false], ['ok' => false, 'code' => 'not_received', 'param' => $capMb, 'check_id' => 0, 'note' => false]]);
t('upload: every refusal\'s sentence is a fixed one with the cap only', [EcobankDetailsCsv::sentence('too_large', $capMb), EcobankDetailsCsv::sentence('not_received', $capMb)],
    ["This file is larger than {$capMb} MB; nothing was kept. Download a shorter period, if Ecobank allows it.",
     "The file did not arrive. It may be larger than {$capMb} MB, or the upload was interrupted. Try again; the limit is shown on the form."]);
// BK97-R2: a cell ending in a line feed inside its quotes. Before the reader anchored its patterns at the very end, the
// Account cell on a linked account made the check row's INSERT throw (088's param CHECK: no trail of the refusal), and on
// a first file an Account or a Cheque Number cell PASSED the check, then made every keep throw (088's CHECKs).
$LFB = bk_copy($UGT, 'lf-linked'); $lq = $LFB['pdo']; $bsL = bk_bs($LFB);
$k0 = bk_try(function () use ($bsL, $UGX, $H, $ME) { $c = $bsL->check($UGX, bk_slice($H, 0, 10), $ME); return $bsL->keep((int)($c['check_id'] ?? 0), $UGX, true, $ME); });
$n0 = bk_counts($lq); $e0 = bk_last_event_id($lq);
$c = bk_try(function () use ($bsL, $UGX, $H, $ME) {
    return $bsL->check($UGX, bk_slice($H, 0, 12, ['mutate' => function ($L) { foreach ($L as &$l) $l['Account'] = '0000000002' . "\n"; unset($l); return $L; }]), $ME); });
$row = $bsL->checkRow((int)($c['check_id'] ?? 0)) ?? [];
t('flow: on a linked account, an Account cell "0000000002<LF>" is a RECORDED refusal (account_mixed) — one closed check row, one event, not a throw',
    [bk_pick($k0, ['ok', 'linked']), bk_pick($c, ['ok', 'code', 'param']), (string)($row['status'] ?? ''), bk_count($lq, 'bank_checks') - $n0['bank_checks'], bk_events($lq, $e0)],
    [['ok' => true, 'linked' => true], ['ok' => false, 'code' => 'account_mixed', 'param' => ''], 'closed', 1, ['statement_checked']]);
foreach ([['Account', function ($L) { foreach ($L as &$l) $l['Account'] .= "\n"; unset($l); return $L; }, 'account_mixed', ''],
          ['Cheque Number', function ($L) { $L[6]['Cheque Number'] .= "\n"; return $L; }, 'lineid_form', '7']] as [$col, $mut, $want, $wp]) {
    $B2 = bk_copy($UGT, 'lf-first-' . strtolower(str_replace(' ', '-', $col))); $bs2 = bk_bs($B2);
    $c = bk_try(function () use ($bs2, $UGX, $H, $ME, $mut) { return $bs2->check($UGX, bk_slice($H, 0, 10, ['mutate' => $mut]), $ME); });
    $row = $bs2->checkRow((int)($c['check_id'] ?? 0)) ?? [];
    t("flow: on an account's first file, a {$col} cell ending in a line feed is refused at the check ({$want}), recorded, nothing held",
        [bk_pick($c, ['ok', 'code', 'param']), (string)($row['status'] ?? ''), (string)($row['param'] ?? 'x'), bk_count($B2['pdo'], 'bank_checks'),
         glob($B2['dir'] . '/bank_statements/.pending/*.csv') ?: []],
        [['ok' => false, 'code' => $want, 'param' => $wp], 'closed', $wp, 1, []]);
}
unset($B2, $bs2, $LFB, $bsL);

// ═══════════════════════════════════════════════════════════════════════════
echo "\nC. Concurrency and the keep order\n";
$C2 = bk_copy($UGT, 'c2'); $q2 = $C2['pdo'];
$A = bk_bs($C2); $Bconn = bk_open($C2['dir']); $B = bk_bs($Bconn);
$c = bk_try(function () use ($A, $UGX, $H, $ME) { return $A->check($UGX, bk_slice($H, 0, 40), $ME); });
$cid = (int)($c['check_id'] ?? 0); $bOut = null;
$Ap = bk_bs($C2, ['probe' => function (string $at) use (&$bOut, $B, $cid, $UGX, $ME2): void {
    if ($at === 'after_copy' && $bOut === null) $bOut = bk_try(function () use ($B, $cid, $UGX, $ME2) { return $B->keep($cid, $UGX, true, $ME2); });
}]);
$aOut = bk_try(function () use ($Ap, $cid, $UGX, $ME) { return $Ap->keep($cid, $UGX, true, $ME); });
$stC2 = bk_row($q2, 'SELECT * FROM bank_statements WHERE check_id = ?', [$cid]) ?? [];
t('concurrency: two keeps of one check on two connections keep once — the second session keeps, the first is told already_kept',
    [bk_pick($bOut ?? [], ['ok', 'lines_added']), bk_pick($aOut, ['ok', 'code', 'note']), bk_count($q2, 'bank_statements'), bk_count($q2, 'bank_statement_lines'), (int)($stC2['kept_by_id'] ?? 0)],
    [['ok' => true, 'lines_added' => 40], ['ok' => false, 'code' => 'already_kept', 'note' => true], 1, 40, 8]);
$fC2 = $C2['dir'] . '/' . (string)($stC2['file_rel'] ?? 'none');
t('concurrency: the statement kept by the second session keeps its file', [is_file($fC2) && hash_file('sha256', $fC2) === ($stC2['sha256'] ?? ''), $A->orphanFiles()], [true, []]);
$Bconn = null; $B = null;

// BK97-R1: a keep that FAILS while another keep of the same check is inside its transaction (a double click, two
// sessions, or a third writer on plugin.sqlite3 that uses up the busy timeout). B, a child process, places its copy and
// waits; A keeps up to its COMMIT and, holding the write lock there, lets B go: B's BEGIN IMMEDIATE times out (SQLITE_BUSY)
// and its failure path deletes the copy it made — and only then does A commit. With one shared final name per check, B
// deleted the very file A's statement then named, and A's step 9 deleted the held file: the original lost from both places.
$C9 = bk_copy($UGT, 'c9-race'); $q9 = $C9['pdo'];
$c = bk_try(function () use ($C9, $UGX, $H, $ME) { return bk_bs($C9)->check($UGX, bk_slice($H, 0, 40), $ME); });
$cid9 = (int)($c['check_id'] ?? 0);
$placed9 = $T . '/files/race-placed'; $go9 = $T . '/files/race-go';
$pr9 = proc_open(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --race-child --root=' . escapeshellarg($root) . ' 2>&1',
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pp9);
$bOut9 = ''; $bRc9 = -1;
if (is_resource($pr9)) {
    fwrite($pp9[0], (string)json_encode(['dir' => $C9['dir'], 'check' => $cid9, 'acct' => $UGX, 'link' => true, 'actor' => 8, 'busy_ms' => 300,
        'placed' => $placed9, 'go' => $go9]));
    fclose($pp9[0]);
}
for ($i = 0; $i < 1500 && is_resource($pr9) && !is_file($placed9); $i++) usleep(20000);
$bPlaced9 = is_file($placed9);
$finishB9 = function () use (&$pp9, &$pr9, &$bOut9, &$bRc9, $go9): void {
    if ($bRc9 !== -1 || !is_resource($pr9)) return;
    touch($go9);
    $bOut9 = (string)stream_get_contents($pp9[1]); fclose($pp9[1]);
    $bRc9 = proc_close($pr9);
};
$A9 = bk_bs($C9, ['probe' => function (string $at) use ($finishB9): void { if ($at === 'before_commit') $finishB9(); }]);
$aOut9 = $bPlaced9 ? bk_try(function () use ($A9, $cid9, $UGX, $ME) { return $A9->keep($cid9, $UGX, true, $ME); }) : ['ok' => false, 'code' => 'the child never placed its copy'];
$finishB9();                                                                  // if A never reached its COMMIT, B is let go here
$st9 = bk_row($q9, 'SELECT * FROM bank_statements WHERE check_id = ?', [$cid9]) ?? [];
$f9 = $C9['dir'] . '/' . (string)($st9['file_rel'] ?? 'none');
t('concurrency: a keep that fails at BEGIN IMMEDIATE while another keep of the same check holds its transaction — the other keeps',
    [$bPlaced9, $bRc9, strpos($bOut9, 'threw BankStatementStore: the keep did not complete; nothing was kept (PDOException') !== false, bk_pick($aOut9, ['ok', 'lines_added'])],
    [true, 0, true, ['ok' => true, 'lines_added' => 40]]);
t('concurrency: the kept statement\'s file is there, with the statement\'s sha256; nothing is held, nothing is orphaned',
    [is_file($f9) && hash_file('sha256', $f9) === ($st9['sha256'] ?? ''), glob($C9['dir'] . '/bank_statements/.pending/*.csv') ?: [], bk_bs($C9)->orphanFiles()],
    [true, [], []]);
@unlink($placed9); @unlink($go9);

$C3 = bk_copy($UGT, 'c3'); $q3 = $C3['pdo']; $bsC3 = bk_bs($C3);
$x = bk_try(function () use ($bsC3, $UGX, $H, $ME) { return $bsC3->check($UGX, bk_slice($H, 0, 40), $ME); });
$xRow = $bsC3->checkRow((int)($x['check_id'] ?? 0)) ?? [];
$y = bk_try(function () use ($bsC3, $UGX, $H, $ME) { return $bsC3->check($UGX, bk_slice($H, 0, 50), $ME); });
$yRow = $bsC3->checkRow((int)($y['check_id'] ?? 0)) ?? [];
t('keep: two checks wait at once; the first one\'s held file survives the second check', [$x['ok'] ?? null, $y['ok'] ?? null, $bsC3->heldPath($xRow) !== null, $bsC3->heldPath($yRow) !== null],
    [true, true, true, true]);
$kx = bk_try(function () use ($bsC3, $x, $UGX, $ME) { return $bsC3->keep((int)($x['check_id'] ?? 0), $UGX, true, $ME); });
$ky = bk_try(function () use ($bsC3, $y, $UGX, $ME) { return $bsC3->keep((int)($y['check_id'] ?? 0), $UGX, true, $ME); });
t('keep: a statement kept after this check was made refuses changed_since_check; the check expires and its file goes',
    [bk_pick($kx, ['ok']), bk_pick($ky, ['ok', 'code']), (string)($bsC3->checkRow((int)($y['check_id'] ?? 0))['status'] ?? ''), $bsC3->heldPath($yRow), count(bk_lines($q3, $UGX))],
    [['ok' => true], ['ok' => false, 'code' => 'changed_since_check'], 'expired', null, 40]);

$C4 = bk_copy($UGT, 'c4'); $q4 = $C4['pdo']; $bsC4 = bk_bs($C4); $Xconn = bk_open($C4['dir']); $bsX = bk_bs($Xconn);
$x = bk_try(function () use ($bsC4, $UGX, $H, $ME) { return $bsC4->check($UGX, bk_slice($H, 0, 40), $ME); });
$y = bk_try(function () use ($bsC4, $UGX, $H, $ME) { return $bsC4->check($UGX, bk_slice($H, 0, 50), $ME); });
$xOut = null; $xid = (int)($x['check_id'] ?? 0); $yid = (int)($y['check_id'] ?? 0);
$bsY = bk_bs($C4, ['probe' => function (string $at) use (&$xOut, $bsX, $xid, $UGX, $ME2): void {
    if ($at === 'after_copy' && $xOut === null) $xOut = bk_try(function () use ($bsX, $xid, $UGX, $ME2) { return $bsX->keep($xid, $UGX, true, $ME2); });
}]);
$yOut = bk_try(function () use ($bsY, $yid, $UGX, $ME) { return $bsY->keep($yid, $UGX, true, $ME); });
t('keep: a statement kept in between, while this keep was between its copy and its transaction, refuses changed_since_check',
    [bk_pick($xOut ?? [], ['ok', 'lines_added']), bk_pick($yOut, ['ok', 'code']), count(bk_lines($q4, $UGX)), (string)($bsC4->checkRow($yid)['status'] ?? '')],
    [['ok' => true, 'lines_added' => 40], ['ok' => false, 'code' => 'changed_since_check'], 40, 'expired']);
t('keep: the refused keep removed its own copy, and the other statement\'s file stays', [$bsC4->orphanFiles(), count(glob($C4['dir'] . '/bank_statements/' . $UGX . '/*.csv') ?: [])], [[], 1]);
$Xconn = null; $bsX = null;

$C5 = bk_copy($UGT, 'c5'); $q5 = $C5['pdo']; $bsC5 = bk_bs($C5);
$c = bk_try(function () use ($bsC5, $UGX, $H, $ME) { return $bsC5->check($UGX, bk_slice($H, 0, 40), $ME); });
$r5 = $bsC5->checkRow((int)($c['check_id'] ?? 0)) ?? [];
$h5 = (string)$bsC5->heldPath($r5);
$orig5 = $h5 === '' ? '' : (string)@file_get_contents($h5);
$alt5 = (string)preg_replace('/RH-CLIENT/', 'RH-CLIENX', $orig5, 1);
if ($h5 !== '') @file_put_contents($h5, $alt5);
$fresh5 = EcobankDetailsCsv::read($alt5);
is_($alt5 !== $orig5 && strlen($alt5) === strlen($orig5) && $fresh5['ok'] === true, 'keep: the control — the altered held file is the same length and still a valid statement on its own');
$k = bk_try(function () use ($bsC5, $c, $UGX, $ME) { return $bsC5->keep((int)($c['check_id'] ?? 0), $UGX, true, $ME); });
t('keep: a held file altered on disk refuses changed_since_check, and the check expires', [bk_pick($k, ['ok', 'code']), (string)($bsC5->checkRow((int)($c['check_id'] ?? 0))['status'] ?? ''),
    array_values(bk_counts($q5))[2] ?? -1, is_file($h5)], [['ok' => false, 'code' => 'changed_since_check'], 'expired', 0, false]);

// The TTL, with clocks the stores are given.
$C6 = bk_copy($UGT, 'c6'); $q6 = $C6['pdo'];
$t0 = 1791000000;
$at = function (int $s) use ($C6) { return bk_bs($C6, ['clock' => function () use ($s): int { return $s; }]); };
$c = bk_try(function () use ($at, $t0, $UGX, $H, $ME) { return $at($t0)->check($UGX, bk_slice($H, 0, 40), $ME); });
$cu = bk_try(function () use ($at, $t0, $USD, $H, $ME) { return $at($t0)->check($USD, bk_slice($H, 0, 20, [], 'USD'), $ME); });
$r6 = $at($t0)->checkRow((int)($c['check_id'] ?? 0)) ?? [];
$n6 = [bk_counts($q6), bk_last_event_id($q6)];
t('ttl: a waiting check is not expired one second before the TTL, and is at the TTL; looking writes nothing',
    [$at($t0 + BankStatementStore::PENDING_TTL - 1)->isExpired($r6), $at($t0 + BankStatementStore::PENDING_TTL)->isExpired($r6), [bk_counts($q6), bk_last_event_id($q6)] === $n6],
    [false, true, true]);
$k = bk_try(function () use ($at, $t0, $cu, $USD, $ME) { return $at($t0 + BankStatementStore::PENDING_TTL - 1)->keep((int)($cu['check_id'] ?? 0), $USD, true, $ME); });
t('ttl: a check kept one second inside the TTL is kept', bk_pick($k, ['ok', 'lines_added']), ['ok' => true, 'lines_added' => 20]);
$ev6 = bk_last_event_id($q6);
$k = bk_try(function () use ($at, $t0, $c, $UGX, $ME) { return $at($t0 + BankStatementStore::PENDING_TTL)->keep((int)($c['check_id'] ?? 0), $UGX, true, $ME); });
$r6 = $at($t0)->checkRow((int)($c['check_id'] ?? 0)) ?? [];
t('ttl: a check past the TTL refuses check_expired, nothing kept, its held file gone', [bk_pick($k, ['ok', 'code']), (string)($r6['status'] ?? ''),
    bk_count($q6, 'bank_statements'), is_file($C6['dir'] . '/' . (string)($r6['pending_rel'] ?? 'x'))], [['ok' => false, 'code' => 'check_expired'], 'expired', 1, false]);
$exp6 = bk_all($q6, "SELECT actor_kind, actor_id FROM bank_line_events WHERE id > ? AND event = 'check_expired'", [$ev6]);
t('ttl: the expiry is the system\'s — one check_expired event with no actor, and the check closed by nobody', [$exp6, array_key_exists('closed_by_id', $r6) ? $r6['closed_by_id'] : 'x'],
    [[['actor_kind' => 'system', 'actor_id' => null]], null]);

// Discard.
$C7 = bk_copy($UGT, 'c7'); $q7 = $C7['pdo']; $bsC7 = bk_bs($C7);
$c = bk_try(function () use ($bsC7, $UGX, $H, $ME) { return $bsC7->check($UGX, bk_slice($H, 0, 40), $ME); });
$r7 = $bsC7->checkRow((int)($c['check_id'] ?? 0)) ?? [];
$h7 = (string)$bsC7->heldPath($r7);
$ev7 = bk_last_event_id($q7);
$d = bk_try(function () use ($bsC7, $c, $UGX, $ME) { return $bsC7->discard((int)($c['check_id'] ?? 0), $UGX, $ME); });
$r7 = $bsC7->checkRow((int)($c['check_id'] ?? 0)) ?? [];
t('discard: a waiting check is discarded by the actor, its held file deleted, one check_discarded event', [bk_pick($d, ['ok']), (string)($r7['status'] ?? ''), (int)($r7['closed_by_id'] ?? 0),
    $h7 !== '' && !is_file($h7), bk_events($q7, $ev7)], [['ok' => true], 'discarded', 7, true, ['check_discarded']]);
$ev7 = bk_last_event_id($q7); $n7 = bk_counts($q7);
$d = bk_try(function () use ($bsC7, $c, $UGX, $ME) { return $bsC7->discard((int)($c['check_id'] ?? 0), $UGX, $ME); });
$k = bk_try(function () use ($bsC7, $c, $UGX, $ME) { return $bsC7->keep((int)($c['check_id'] ?? 0), $UGX, true, $ME); });
t('discard: a discarded check cannot be discarded again or kept; nothing is kept', [$d['code'] ?? null, $k['code'] ?? null, bk_events($q7, $ev7), bk_count($q7, 'bank_statements')],
    ['changed_since_check', 'changed_since_check', ['keep_refused'], 0]);

// Keep twice on one connection.
$C1 = bk_copy($UGT, 'c1'); $q1 = $C1['pdo']; $bsC1 = bk_bs($C1);
$c = bk_try(function () use ($bsC1, $UGX, $H, $ME) { return $bsC1->check($UGX, bk_slice($H, 0, 40), $ME); });
$k1 = bk_try(function () use ($bsC1, $c, $UGX, $ME) { return $bsC1->keep((int)($c['check_id'] ?? 0), $UGX, true, $ME); });
$k2 = bk_try(function () use ($bsC1, $c, $UGX, $ME) { return $bsC1->keep((int)($c['check_id'] ?? 0), $UGX, true, $ME); });
t('concurrency: keep twice on one connection keeps once', [bk_pick($k1, ['ok']), bk_pick($k2, ['ok', 'code']), bk_count($q1, 'bank_statements'), bk_count($q1, 'bank_statement_lines')],
    [['ok' => true], ['ok' => false, 'code' => 'already_kept'], 1, 40]);

// A process that dies between the copy and the COMMIT: at the copy, and with every row inserted and nothing committed.
function bk_crash_child(string $self, string $root, array $orders): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($self) . ' --crash-child --root=' . escapeshellarg($root) . ' 2>&1';
    $pr = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);
    if (!is_resource($pr)) return [-1, 'proc_open failed'];
    fwrite($pipes[0], (string)json_encode($orders)); fclose($pipes[0]);
    $out = (string)stream_get_contents($pipes[1]); fclose($pipes[1]);
    return [proc_close($pr), $out];
}
foreach (['after_copy', 'before_commit'] as $step) {
    $C8 = bk_copy($UGT, 'c8-' . $step); $q8 = $C8['pdo']; $bsC8 = bk_bs($C8);
    $c = bk_try(function () use ($bsC8, $UGX, $H, $ME) { return $bsC8->check($UGX, bk_slice($H, 0, 40), $ME); });
    $r8 = $bsC8->checkRow((int)($c['check_id'] ?? 0)) ?? [];
    $finalRe = '#^bank_statements/' . $UGX . '/' . (int)($r8['id'] ?? 0) . '-' . substr((string)($r8['sha256'] ?? ''), 0, 16) . '-[0-9a-f]{16}\.csv$#';
    [$rc, $out] = bk_crash_child(__FILE__, $root, ['dir' => $C8['dir'], 'check' => (int)($r8['id'] ?? 0), 'acct' => $UGX, 'step' => $step, 'link' => true, 'actor' => 7]);
    is_($rc === 255 && strpos($out, "dying at {$step}") !== false && strpos($out, 'Allowed memory size') !== false && strpos($out, 'returned') === false,
        "crash at {$step}: the child dies of exhausted memory, a fatal no catch sees", 'exit ' . $rc . ' ' . substr(str_replace("\n", ' | ', $out), 0, 300));
    $r8 = $bsC8->checkRow((int)($r8['id'] ?? 0)) ?? [];
    t("crash at {$step}: the check still waits, and nothing was kept (no statement, no line, no profile)", [(string)($r8['status'] ?? ''), bk_count($q8, 'bank_statements'),
        bk_count($q8, 'bank_statement_lines'), bk_count($q8, 'bank_account_profiles')], ['waiting', 0, 0, 0]);
    t("crash at {$step}: the held file is still in .pending/", $bsC8->heldPath($r8) !== null, true);
    $orph8 = $bsC8->orphanFiles(); $dead8 = (string)($orph8[0] ?? '');
    t("crash at {$step}: what is left is the dead attempt's own copy, that no row names, counted as an orphan",
        [count($orph8), (bool)preg_match($finalRe, $dead8), $dead8 !== '' && is_file($C8['dir'] . '/' . $dead8)], [1, true, true]);
    $k = bk_try(function () use ($bsC8, $r8, $UGX, $ME) { return $bsC8->keep((int)($r8['id'] ?? 0), $UGX, true, $ME); });
    $st8 = bk_row($q8, 'SELECT * FROM bank_statements WHERE check_id = ?', [(int)($r8['id'] ?? 0)]) ?? [];
    $rel8 = (string)($st8['file_rel'] ?? '');
    t("crash at {$step}: pressing Keep again keeps it under a name of its own; the dead attempt's copy is removed, and no orphan is left",
        [bk_pick($k, ['ok', 'lines_added', 'linked']), (bool)preg_match($finalRe, $rel8) && $rel8 !== $dead8, is_file($C8['dir'] . '/' . $rel8),
         $dead8 !== '' && !is_file($C8['dir'] . '/' . $dead8), $bsC8->orphanFiles(), $bsC8->heldPath($r8)],
        [['ok' => true, 'lines_added' => 40, 'linked' => true], true, true, true, [], null]);
}

// ═══════════════════════════════════════════════════════════════════════════
echo "\nD. Containment\n";
$k1row = bk_row($p1, 'SELECT * FROM bank_statements ORDER BY id LIMIT 1') ?? [];
$keptName = basename((string)($k1row['file_rel'] ?? 'x'));
t('containment: a row naming a file outside bank_statements is refused', $bs->statementPath(['file_rel' => 'plugin.sqlite3', 'cb_account_id' => $UGX]), null);
t('containment: a row naming another account\'s folder is refused', $bs->statementPath(['file_rel' => 'bank_statements/' . $UGX . '/' . $keptName, 'cb_account_id' => $USD]), null);
t('containment: a held path must lie in .pending: a kept file named as held is refused',
    $bs->heldPath(['pending_rel' => 'bank_statements/' . $UGX . '/' . $keptName]), null);
t('containment: a NUL byte is refused', $bs->statementPath(['file_rel' => 'bank_statements/' . $UGX . "/x\0.csv", 'cb_account_id' => $UGX]), null);
$link = $F1['dir'] . '/bank_statements/' . $UGX . '/9-0123456789abcdef.csv';
@symlink($F1['dir'] . '/plugin.sqlite3', $link);
t('containment: a link inside the folder that points at the database is refused (realpath leaves the folder)',
    [is_link($link), $bs->statementPath(['file_rel' => 'bank_statements/' . $UGX . '/9-0123456789abcdef.csv', 'cb_account_id' => $UGX])], [true, null]);
@unlink($link);
t('containment: the control — the kept row itself is found', $bs->statementPath($k1row) !== null, true);

// ═══════════════════════════════════════════════════════════════════════════
echo "\nE. Posts nothing\n";
$P = bk_copy($UGT, 'posts-nothing'); $pq = $P['pdo'];
file_put_contents($P['dir'] . '/config.json', (string)json_encode(BK_UG_CFG));
bk_vault($T . '/vaults/posts-nothing.json');
$cbP = new CashbookService($P['store'], $P['dir']);
is_($cbP->cashOnlyRuleInForce() === true, 'posts nothing: the control — rule A is in force on this Uganda book');
$snap = function () use ($pq, $P): array {
    $cb = new CashbookService($P['store'], $P['dir']);
    return ['cb_ledger' => bk_digest($pq, 'cb_ledger'), 'cb_accounts' => bk_digest($pq, 'cb_accounts'), 'fin_audit' => bk_digest($pq, 'fin_audit'),
        'master' => bk_master($pq, false), 'cash' => [round($cb->cashInHand('UGX'), 2), round($cb->cashInHand('USD'), 2),
        round($cb->cashInHand('UGX', 'dishnet'), 2), round($cb->cashInHand('USD', 'dishnet'), 2)]];
};
$before = $snap();
$cashSame = [round($cbP->cashInHand('UGX'), 2), round($cbP->cashInHand('USD'), 2)];
is_($before['cash'][0] > 0 && $before['cash'][1] > 0 && strpos($before['cb_ledger'], '8:') === 0 && strpos($before['fin_audit'], 'absent') === false,
    'posts nothing: the control — the book has cash in hand in both currencies, eight ledger rows and a fin_audit table', json_encode($before));
$bsP = bk_bs($P);
$acts = [];
$c = bk_try(function () use ($bsP, $UGX, $H, $ME) { return $bsP->check($UGX, bk_slice($H, 0, 40), $ME); }); $acts[] = $c['ok'] ?? null;
$k = bk_try(function () use ($bsP, $c, $UGX, $ME) { return $bsP->keep((int)($c['check_id'] ?? 0), $UGX, true, $ME); }); $acts[] = $k['ok'] ?? null;
$r = bk_try(function () use ($bsP, $UGX, $H, $ME, $set) { return $bsP->check($UGX, bk_slice($H, 0, 50, ['mutate' => $set(20, 'Running Balance', '  1.00')]), $ME); }); $acts[] = $r['code'] ?? null;
$c2 = bk_try(function () use ($bsP, $UGX, $H, $ME) { return $bsP->check($UGX, bk_slice($H, 0, 50), $ME); });
$d = bk_try(function () use ($bsP, $c2, $UGX, $ME) { return $bsP->discard((int)($c2['check_id'] ?? 0), $UGX, $ME); }); $acts[] = $d['ok'] ?? null;
$c3 = bk_try(function () use ($bsP, $USD, $H, $ME) { return $bsP->check($USD, bk_slice($H, 0, 20, [], 'USD'), $ME); });
$hk = bk_try(function () use ($P) { return bk_bs($P, ['clock' => function (): int { return time() + BankStatementStore::PENDING_TTL + 5; }])->housekeeping(); }); $acts[] = $hk['expired'] ?? null;
$ex = bk_try(function () use ($bsP, $UGX, $ME) { $bsP->recordExport($UGX, 40, 'all', $ME); return ['ok' => true]; }); $acts[] = $ex['ok'] ?? null;
t('posts nothing: the acts happened — a check and a keep, a refusal, a discard, an expiry, an export', $acts, [true, true, 'chain', true, 1, true]);
$after = $snap();
t('posts nothing: cb_ledger — the same rows, byte for byte (count and sha256 in id order)', $after['cb_ledger'], $before['cb_ledger']);
t('posts nothing: cb_accounts — the same rows, byte for byte', $after['cb_accounts'], $before['cb_accounts']);
t('posts nothing: fin_audit — the same rows, byte for byte', $after['fin_audit'], $before['fin_audit']);
t('posts nothing: cashInHand() per currency and project is unchanged (a fresh CashbookService)', $after['cash'], $before['cash']);
t('posts nothing: cashInHand() on the instance that read it before is unchanged', [round($cbP->cashInHand('UGX'), 2), round($cbP->cashInHand('USD'), 2)], $cashSame);
t('posts nothing: no object outside the bank_* tables changed in sqlite_master', $after['master'], $before['master']);
$tok = function (string $file): array {
    $strs = []; $ids = [];
    foreach (token_get_all((string)file_get_contents($file)) as $tk) {
        if (!is_array($tk)) continue;
        if ($tk[0] === T_CONSTANT_ENCAPSED_STRING || $tk[0] === T_ENCAPSED_AND_WHITESPACE) $strs[] = $tk[1];
        if ($tk[0] === T_STRING) $ids[] = $tk[1];
    }
    return [$strs, $ids];
};
[$sS, $sI] = $tok($root . '/lib/BankStatementStore.php');
[$gS, $gI] = $tok($root . '/lib/BankGate.php');
$named = function (array $strs, string $needle): int { return count(array_filter($strs, function ($s) use ($needle) { return stripos($s, $needle) !== false; })); };
t('posts nothing: the store\'s and the gate\'s code (not their comments) name no ledger table, no ledger write and no Cashbook writer',
    [$named(array_merge($sS, $gS), 'cb_ledger'), $named(array_merge($sS, $gS), 'fin_audit'), $named(array_merge($sS, $gS), 'INSERT INTO cb_'),
     $named(array_merge($sS, $gS), 'UPDATE cb_'), $named(array_merge($sS, $gS), 'DELETE FROM cb_'),
     count(array_intersect(array_merge($sI, $gI), ['CashbookService', 'FinAudit', 'BankImport', 'BankStatement', 'addEntryRaw', 'addEntry', 'error_log']))],
    [0, 0, 0, 0, 0, 0]);
t('posts nothing: its one read of an existing table is the SELECT of cb_accounts', $named($sS, 'FROM cb_accounts'), 1);
t('memory: the store\'s view of the kept lines reads two columns, plus five fields of each end, never a whole line',
    [$named($sS, 'SELECT cheque_number, compare_digest FROM bank_statement_lines'), $named($sS, 'SELECT * FROM bank_statement_lines'),
     $named($sS, 'SELECT cheque_number, posting_date, running_units, credit_units, debit_units FROM bank_statement_lines')], [1, 0, 2]);

// ═══════════════════════════════════════════════════════════════════════════
echo "\nF. The gate\n";
/** A gate book: its own folder and vault, its configuration in the files, and CashbookService's rule. */
function bk_gate_book(array $tpl, string $name, array $cfg, ?string $vaultText = null): array {
    global $T;
    $B = bk_copy($tpl, 'gate-' . $name);
    file_put_contents($B['dir'] . '/config.json', (string)json_encode($cfg));
    $vault = $T . '/vaults/gate-' . $name . '.json';
    if ($vaultText !== null) file_put_contents($vault, $vaultText);
    bk_vault($vault);
    $B['cfg'] = $cfg; $B['vault'] = $vault;
    return $B;
}
/** The gate's halves, then CashbookService's rule, read fresh, in that order (the gate writes nothing; load() may). */
function bk_gate_vs_rule(array $B): array {
    bk_vault($B['vault']);
    BankGate::reset(); StaffJobsGate::reset();
    $halves = BankGate::ugandaWithoutSsp($B['dir']);
    $cb = new CashbookService($B['store'], $B['dir']);
    $rule = $cb->cashOnlyRuleInForce();
    BankGate::reset(); StaffJobsGate::reset();
    return [$halves, $rule, BankGate::ugandaWithoutSsp($B['dir'])];
}
function bk_open_with(array $B, $switch): bool {
    $cfg = $B['cfg'];
    if ($switch !== null) $cfg[BankGate::SWITCH_KEY] = $switch;
    file_put_contents($B['dir'] . '/config.json', (string)json_encode($cfg));
    bk_vault($B['vault']);
    BankGate::reset(); StaffJobsGate::reset();
    $o = BankGate::open($B['dir']);
    file_put_contents($B['dir'] . '/config.json', (string)json_encode($B['cfg']));
    return $o;
}
$books = [
    ['Uganda (uganda; UGX,USD)', 'u', BK_UG_CFG, null, true],
    ['South Sudan (south-sudan; USD,SSP)', 'ss', ['tenant_profile' => 'south-sudan', 'currency_code' => 'USD', 'currency_symbol' => '$',
        'cashbook_base_currency' => 'USD', 'cashbook_currencies' => 'USD,SSP'], null, false],
    ['a South Sudan tenant without SSP', 'sn', ['tenant_profile' => 'south-sudan', 'currency_code' => 'USD', 'currency_symbol' => '$',
        'cashbook_base_currency' => 'USD', 'cashbook_currencies' => 'USD'], null, false],
    ['a Uganda tenant with SSP', 'up', ['tenant_profile' => 'uganda', 'currency_code' => 'UGX', 'currency_symbol' => 'UGX',
        'cashbook_base_currency' => 'UGX', 'cashbook_currencies' => 'UGX,USD,SSP'], null, false],
    ['Uganda held only in a readable vault', 'vc', ['cashbook_currencies' => 'UGX,USD'],
        (string)json_encode(['config' => ['currency_code' => 'UGX', 'cashbook_base_currency' => 'UGX']]), true],
    ['the same book with its vault unreadable', 'vu', ['cashbook_currencies' => 'UGX,USD'], '{"config": {"currency_code": "UGX"', false],
];
$GB = [];
foreach ($books as [$label, $key, $cfg, $vaultText, $want]) {
    $B = bk_gate_book($TPL, $key, $cfg, $vaultText); $GB[$key] = $B;
    [$h, $rule, $h2] = bk_gate_vs_rule($B);
    t("gate: {$label} — BankGate's halves equal cashOnlyRuleInForce(), both " . ($want ? 'true' : 'false'), [$h, $rule, $h2], [$want, $want, $want]);
    t("gate: {$label}, switch off — open() is false", bk_open_with($B, null), false);
    t("gate: {$label}, switch on — open() is " . ($want ? 'true' : 'false'), bk_open_with($B, true), $want);
}
// Any throwable inside the gate: a currency list stored as an array, read under a handler that throws.
$TB = bk_gate_book($TPL, 'throws', ['tenant_profile' => 'uganda', 'currency_code' => 'UGX', 'currency_symbol' => 'UGX', 'cashbook_base_currency' => 'UGX',
    'cashbook_currencies' => ['UGX', 'USD'], BankGate::SWITCH_KEY => true]);
$thrown = 0;
set_error_handler(function (int $no, string $str) use (&$thrown) { $thrown++; throw new \ErrorException($str, 0, $no); });
try { BankGate::reset(); StaffJobsGate::reset(); $gTh = [BankGate::ugandaWithoutSsp($TB['dir']), BankGate::open($TB['dir'])];
      $rTh = (new CashbookService($TB['store'], $TB['dir']))->cashOnlyRuleInForce(); }
finally { restore_error_handler(); }
set_error_handler(function () { return true; });
try { BankGate::reset(); StaffJobsGate::reset(); $gOk = [BankGate::ugandaWithoutSsp($TB['dir']), BankGate::open($TB['dir'])];
      $rOk = (new CashbookService($TB['store'], $TB['dir']))->cashOnlyRuleInForce(); }
finally { restore_error_handler(); }
t('gate: a gate that throws is false, as cashOnlyRuleInForce() is; the control — read without the throwable it is Uganda without SSP',
    [$thrown >= 1, $gTh, $rTh, $gOk, $rOk], [true, [false, false], false, [true, true], true]);
foreach (['1', 'true', 'on', 'yes', 'TRUE', ' On '] as $v) t("gate: the switch '{$v}' is on", bk_open_with($GB['u'], $v), true);
foreach (['0', 'false', 'off', 'no', '', '2', 'enabled'] as $v) t("gate: the switch '{$v}' is off", bk_open_with($GB['u'], $v), false);
BankGate::reset();
t('gate: no data directory, or an empty one, is false', [BankGate::open(null), BankGate::open(''), BankGate::open('   '), BankGate::ugandaWithoutSsp(null)], [false, false, false, false]);
// The files against a store copy that says the opposite, both ways (public.php's $config is the store copy).
$D1 = bk_gate_book($TPL, 'files-on', BK_UG_CFG + [BankGate::SWITCH_KEY => true]);
$D1['store']->save('kyc_config.json', ['tenant_profile' => 'south-sudan', 'currency_code' => 'USD', 'cashbook_base_currency' => 'USD',
    'cashbook_currencies' => 'USD,SSP', BankGate::SWITCH_KEY => false]);
BankGate::reset(); StaffJobsGate::reset();
t('gate: the files say Uganda and on, the store copy says South Sudan and off — BankGate follows the files',
    [(string)($D1['store']->load('kyc_config.json')['tenant_profile'] ?? ''), BankGate::open($D1['dir'])], ['south-sudan', true]);
$D2 = bk_gate_book($TPL, 'files-off', ['tenant_profile' => 'south-sudan', 'currency_code' => 'USD', 'cashbook_base_currency' => 'USD', 'cashbook_currencies' => 'USD,SSP']);
$D2['store']->save('kyc_config.json', BK_UG_CFG + [BankGate::SWITCH_KEY => true]);
BankGate::reset(); StaffJobsGate::reset();
t('gate: the files say South Sudan and off, the store copy says Uganda and on — BankGate follows the files',
    [(string)($D2['store']->load('kyc_config.json')['tenant_profile'] ?? ''), BankGate::open($D2['dir'])], ['uganda', false]);

// allows(): the fresh record, never the session copy.
final class BkFakeStore {
    private $mode; private $rec;
    public function __construct(string $mode, ?array $rec) { $this->mode = $mode; $this->rec = $rec; }
    public function findOne(string $file, string $key, $value): ?array {
        if ($this->mode === 'throws') throw new \RuntimeException('database is locked');
        if ($file !== 'retailers.json' || $key !== 'id') return null;
        return $this->mode === 'null' ? null : $this->rec;
    }
}
/** A store that counts its reads (5.18.97: what showLink() asks of the staff table). */
final class BkCountingStore {
    public $reads = 0; private $rec;
    public function __construct(?array $rec) { $this->rec = $rec; }
    public function findOne(string $file, string $key, $value): ?array { $this->reads++; return $file === 'retailers.json' && $key === 'id' ? $this->rec : null; }
}
$sessAdmin = ['id' => 7, 'name' => 'RH-Admin One (session copy)', 'is_admin' => 1, 'is_active' => 1];
$freshAdmin = ['id' => 7, 'name' => 'RH-Admin One', 'is_admin' => 1, 'is_active' => 1, 'role' => 'admin'];
t('allows: an active admin gets the FRESH record, for both keys', [BankGate::allows('bank.view', new BkFakeStore('ok', $freshAdmin), $sessAdmin),
    BankGate::allows('bank.import', new BkFakeStore('ok', $freshAdmin), $sessAdmin)], [$freshAdmin, $freshAdmin]);
t('allows: a store whose read throws is a refusal, never the session copy', BankGate::allows('bank.import', new BkFakeStore('throws', null), $sessAdmin), null);
t('allows: a read that returns nothing is a refusal, never the session copy', BankGate::allows('bank.view', new BkFakeStore('null', null), $sessAdmin), null);
t('allows: the demoted admin inside the 300-second window (session admin, store not) is refused',
    BankGate::allows('bank.import', new BkFakeStore('ok', ['is_admin' => 0] + $freshAdmin), $sessAdmin), null);
t('allows: an account made inactive is refused', BankGate::allows('bank.view', new BkFakeStore('ok', ['is_active' => 0] + $freshAdmin), $sessAdmin), null);
t('allows: an accountant, a support leader and a field accountant are refused in v1', [
    BankGate::allows('bank.view', new BkFakeStore('ok', ['is_admin' => 0, 'role' => 'accountant'] + $freshAdmin), $sessAdmin),
    BankGate::allows('bank.view', new BkFakeStore('ok', ['is_admin' => 0, 'role' => 'support_leader'] + $freshAdmin), $sessAdmin),
    BankGate::allows('bank.view', new BkFakeStore('ok', ['is_admin' => 0, 'role' => 'field_accountant'] + $freshAdmin), $sessAdmin)], [null, null, null]);
t('allows: an unknown key, a session with no id, and a record for another id are refused', [BankGate::allows('bank.approve', new BkFakeStore('ok', $freshAdmin), $sessAdmin),
    BankGate::allows('bank.view', new BkFakeStore('ok', $freshAdmin), ['name' => 'x']), BankGate::allows('bank.view', new BkFakeStore('ok', ['id' => 8] + $freshAdmin), $sessAdmin),
    BankGate::allows('bank.view', 'not a store', $sessAdmin)], [null, null, null, null]);
$RS = bk_copy($TPL, 'retailers');
$RS['store']->save('retailers.json', [$freshAdmin, ['id' => 8, 'name' => 'RH-Admin Demoted', 'is_admin' => 0, 'is_active' => 1, 'role' => 'sales']]);
t('allows: through a real store — the admin passes with the stored record, the demoted one is refused although the session says admin',
    [(string)(BankGate::allows('bank.import', $RS['store'], $sessAdmin)['name'] ?? ''), BankGate::allows('bank.import', $RS['store'], ['id' => 8, 'is_admin' => 1, 'is_active' => 1])],
    ['RH-Admin One', null]);
t('gate: GRANTS is admins only for bank.view and bank.import', BankGate::GRANTS, ['bank.view' => 'admin', 'bank.import' => 'admin']);

// 5.18.97 (the operator, 18 Oct): showLink(), the one gate of every link to ECO Bank. True exactly when the tab opens:
// the session copy's is_admin (the dispatcher's '*admin'), then open() over the files, then allows('bank.view') fresh.
$SL = bk_gate_book($TPL, 'showlink', BK_UG_CFG);
$slAt = function (bool $switch) use ($SL): void {
    file_put_contents($SL['dir'] . '/config.json', (string)json_encode($SL['cfg'] + [BankGate::SWITCH_KEY => $switch]));
    bk_vault($SL['vault']); BankGate::reset(); StaffJobsGate::reset();
};
$notAdmin = ['is_admin' => 0] + $sessAdmin;
$tt = [];
foreach ([true, false] as $sess) foreach ([true, false] as $sw) foreach ([true, false] as $fr) {
    $slAt($sw);
    $tt[($sess ? 'S' : 's') . ($sw ? 'W' : 'w') . ($fr ? 'F' : 'f')] = BankGate::showLink($SL['dir'], new BkFakeStore('ok', $fr ? $freshAdmin : ['is_admin' => 0] + $freshAdmin), $sess ? $sessAdmin : $notAdmin);
}
t('showLink(): of the 8 combinations of the session copy\'s is_admin (S), the switch (W) and the fresh record\'s is_admin (F), only all three is true',
    $tt, ['SWF' => true, 'SWf' => false, 'SwF' => false, 'Swf' => false, 'sWF' => false, 'sWf' => false, 'swF' => false, 'swf' => false]);
$slAt(true);
t('showLink(): a session copy with no id is false; the control — the same with its id is true',
    [BankGate::showLink($SL['dir'], new BkFakeStore('ok', $freshAdmin), ['is_admin' => 1, 'is_active' => 1]), BankGate::showLink($SL['dir'], new BkFakeStore('ok', $freshAdmin), $sessAdmin)], [false, true]);
// A session that is not an admin's reads nothing: no configuration (BankGate's memo stays empty: open() was never asked)
// and no staff record — even where the data directory cannot be read at all (it does not exist).
$memo = function (): array { $rp = new ReflectionProperty('BankGate', 'cfg'); $rp->setAccessible(true); return array_keys((array)$rp->getValue()); };
$noDir = $T . '/books/no-such-book';
BankGate::reset(); $cs1 = new BkCountingStore($freshAdmin);
$r1 = BankGate::showLink($noDir, $cs1, $notAdmin); $mm1 = $memo();
BankGate::reset(); $cs2 = new BkCountingStore($freshAdmin);
$r2 = BankGate::showLink($noDir, $cs2, $sessAdmin); $mm2 = $memo();
BankGate::reset(); $cs3 = new BkCountingStore($freshAdmin); $slAt(true);
$r3 = BankGate::showLink($SL['dir'], $cs3, $sessAdmin);
t('showLink(): a session that is not an admin\'s is false and reads nothing (no configuration, no staff record), even over an unreadable data directory; the controls — an admin\'s session asks open() of that directory (false, no record read), and on a readable book reads the record once (true)',
    [$r1, $mm1, $cs1->reads, $r2, $mm2, $cs2->reads, $r3, $cs3->reads], [false, [], 0, false, [$noDir], 0, true, 1]);
is_(!is_dir($noDir), 'showLink() made no folder for a data directory that does not exist');
// No write, in a fresh process that has not read the staff table yet (the state of a request inside the sign-in window).
$SW = bk_gate_book($TPL, 'showlink-child', BK_UG_CFG + [BankGate::SWITCH_KEY => true]);
$SW['store']->save('retailers.json', [$freshAdmin]);
$SW['pdo']->exec('PRAGMA wal_checkpoint(TRUNCATE)');
$slChild = function (array $session) use ($SW, $root): array {
    bk_vault($SW['vault']);
    $pr = proc_open(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --showlink-child --root=' . escapeshellarg($root),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($pr)) return ['error' => 'proc_open failed'];
    fwrite($pipes[0], (string)json_encode(['dir' => $SW['dir'], 'session' => $session])); fclose($pipes[0]);
    $out = (string)stream_get_contents($pipes[1]); $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $rc = proc_close($pr);
    $j = json_decode(trim($out), true);
    return is_array($j) ? $j + ['rc' => $rc] : ['error' => trim($out . ' ' . $err), 'rc' => $rc];
};
$ch = $slChild($sessAdmin);
t('showLink() in a fresh process, as an admin: true, and total_changes(), the database files and sqlite_master (read on a second connection) are the same after it; the controls — one planted INSERT moves total_changes(), and a table planted in the main schema afterwards moves both the files and sqlite_master, the main file having been read (not absent)',
    [$ch['rc'] ?? null, $ch['r'] ?? null, ($ch['tc'][1] ?? -1) - ($ch['tc'][0] ?? -2), ($ch['tc'][2] ?? 0) - ($ch['tc'][1] ?? 0), ($ch['files'][0] ?? 'a') === ($ch['files'][1] ?? 'b'),
     ($ch['master'][0] ?? 'a') === ($ch['master'][1] ?? 'b'), $ch['main'] ?? null, ($ch['files'][1] ?? 'a') !== ($ch['files'][2] ?? 'a'),
     ($ch['master'][1] ?? 'a') !== ($ch['master'][2] ?? 'a')], [0, true, 0, 1, true, true, true, true, true]);
// Under PRAGMA query_only = ON, after one earlier read of the staff table in this process (its table check is done): a
// write anywhere in showLink() would throw, allows() would answer null, and the link would be false.
$QO = bk_gate_book($TPL, 'showlink-qo', BK_UG_CFG + [BankGate::SWITCH_KEY => true]);
$QO['store']->save('retailers.json', [$freshAdmin]);
$QO['pdo']->exec('CREATE TABLE rh_qo_throwaway (x INTEGER)');
$qoRead = (string)($QO['store']->findOne('retailers.json', 'id', 7)['name'] ?? '');
$QO['pdo']->exec('PRAGMA query_only = ON');
bk_vault($QO['vault']); BankGate::reset(); StaffJobsGate::reset();
$qoLink = BankGate::showLink($QO['dir'], $QO['store'], $sessAdmin);
try { $QO['pdo']->exec('INSERT INTO rh_qo_throwaway (x) VALUES (1)'); $qoCtl = 'ALLOWED'; } catch (PDOException $e) { $qoCtl = 'REFUSED'; }
$QO['pdo']->exec('PRAGMA query_only = OFF');
t('showLink() under PRAGMA query_only = ON (the staff table read once before): still true for the admin; the control on the control — an INSERT is refused under the same pragma',
    [$qoRead, $qoLink, $qoCtl], ['RH-Admin One', true, 'REFUSED']);
BankGate::reset(); StaffJobsGate::reset();

// latestLine(): the cards' one read (5.18.97) — the account's kept line with the highest Cheque Number, never another's.
$LL = bk_copy($UGT, 'latest'); $bsL = bk_bs($LL);
t('latestLine(): null for each account before anything is kept', [$bsL->latestLine($UGX), $bsL->latestLine($USD)], [null, null]);
$HL = g_history(30, 5);
$HU = g_history(12, 6, 0, 9001);                                             // the USD account's numbers are all above every UGX one
$keepIt = function (int $acct, string $csv, bool $link) use ($bsL, $ME): bool {
    $c = bk_try(function () use ($bsL, $acct, $csv, $ME) { return $bsL->check($acct, $csv, $ME); });
    $k = bk_try(function () use ($bsL, $c, $acct, $link, $ME) { return $bsL->keep((int)($c['check_id'] ?? 0), $acct, $link, $ME); });
    return ($k['ok'] ?? false) === true;
};
$okL = [$keepIt($UGX, bk_slice($HL, 10, 20), true), $keepIt($UGX, bk_slice($HL, 0, 15), false), $keepIt($USD, bk_slice($HU, 0, 12, [], 'USD'), true)];
$pickL = function (?array $r): ?array { return $r === null ? null : bk_pick($r, ['cheque_number', 'running_units', 'currency']); };
t('latestLine(): the kept line with the highest Cheque Number — UGX\'s line 30 although lines 1–10 were kept after it; never the USD account\'s, whose numbers are all higher',
    [$okL, $pickL($bsL->latestLine($UGX)), $pickL($bsL->latestLine($USD))],
    [[true, true, true], ['cheque_number' => (string)$HL[29]['Cheque Number'], 'running_units' => $HL[29]['_run'], 'currency' => 'UGX'],
     ['cheque_number' => (string)$HU[11]['Cheque Number'], 'running_units' => $HU[11]['_run'], 'currency' => 'USD']]);
t('control: the UGX account holds lines 1–30, and its last inserted line is line 10, not its latest',
    [bk_count($LL['pdo'], 'bank_statement_lines') - 12, (string)(bk_row($LL['pdo'], 'SELECT cheque_number FROM bank_statement_lines WHERE cb_account_id = ? ORDER BY id DESC LIMIT 1', [$UGX])['cheque_number'] ?? '')],
    [30, (string)$HL[9]['Cheque Number']]);

// ecobankNamed(): the cards' "ECO BANK <CUR>" heading only where the book's own name says Ecobank (invented names).
t('ecobankNamed(): the book\'s name says Ecobank, in any case, with a space, a hyphen or nothing between; any other bank\'s name does not, nor "eco bank" inside a longer word',
    array_map(['BankStatementStore', 'ecobankNamed'], ['Ecobank Uganda – UGX', 'ECO BANK', 'Eco-Bank', 'RH-Ecobank Spare – USD', 'RH-Spare Bank 0123456789 – UGX', 'RH-Highland Bank – UGX', 'Economy Bank', 'RH-Deco-Bank – UGX']),
    [true, true, true, true, false, false, false, false]);

// ═══════════════════════════════════════════════════════════════════════════
echo "\nG. The trail, and no statement text anywhere but the lines and the kept file\n";
$MK = bk_copy($UGT, 'marker'); $mq = $MK['pdo']; $bsM = bk_bs($MK);
$mark = function (int $from, int $n, array $o = [], string $ccy = 'UGX') use ($H): string { return bk_slice($H, $from, $n, $o + ['marker' => BK_MARK], $ccy); };
$seq = [];
$step = function (string $label, callable $act) use ($mq, &$seq) { $e = bk_last_event_id($mq); $r = bk_try($act); $seq[$label] = [bk_events($mq, $e), $r['ok'] ?? null]; return $r; };
$c = $step('check', function () use ($bsM, $UGX, $mark, $ME) { return $bsM->check($UGX, $mark(0, 40), $ME); });
$step('first keep', function () use ($bsM, $c, $UGX, $ME) { return $bsM->keep((int)($c['check_id'] ?? 0), $UGX, true, $ME); });
$step('refused check', function () use ($bsM, $UGX, $mark, $ME, $set) { return $bsM->check($UGX, $mark(0, 50, ['mutate' => $set(20, 'Running Balance', '  1.00')]), $ME); });
$c2 = $step('second check', function () use ($bsM, $UGX, $mark, $ME) { return $bsM->check($UGX, $mark(0, 50), $ME); });
$step('discard', function () use ($bsM, $c2, $UGX, $ME) { return $bsM->discard((int)($c2['check_id'] ?? 0), $UGX, $ME); });
$step('keep of the discarded', function () use ($bsM, $c2, $UGX, $ME) { return $bsM->keep((int)($c2['check_id'] ?? 0), $UGX, false, $ME); });
$c3 = $step('later check', function () use ($bsM, $UGX, $mark, $ME) { return $bsM->check($UGX, $mark(0, 60), $ME); });
$step('later keep', function () use ($bsM, $c3, $UGX, $ME) { return $bsM->keep((int)($c3['check_id'] ?? 0), $UGX, false, $ME); });
$step('nothing new', function () use ($bsM, $UGX, $mark, $ME) { return $bsM->check($UGX, $mark(0, 60, ['pad' => 22]), $ME); });
$step('currency mismatch', function () use ($bsM, $USD, $mark, $ME) { return $bsM->check($USD, $mark(0, 60), $ME); });
$step('waiting check', function () use ($bsM, $USD, $mark, $ME) { return $bsM->check($USD, $mark(0, 30, [], 'USD'), $ME); });
$step('expiry', function () use ($MK) { return ['ok' => true] + bk_bs($MK, ['clock' => function (): int { return time() + BankStatementStore::PENDING_TTL + 5; }])->housekeeping(); });
$step('export', function () use ($bsM, $UGX, $ME) { $bsM->recordExport($UGX, 60, '2026-08', $ME); return ['ok' => true]; });
t('trail: the events of each act, in order', $seq, [
    'check' => [['statement_checked'], true], 'first keep' => [['account_linked', 'statement_kept'], true], 'refused check' => [['statement_checked'], false],
    'second check' => [['statement_checked'], true], 'discard' => [['check_discarded'], true], 'keep of the discarded' => [['keep_refused'], false],
    'later check' => [['statement_checked'], true], 'later keep' => [['statement_kept'], true], 'nothing new' => [['statement_checked'], false],
    'currency mismatch' => [['statement_checked'], false], 'waiting check' => [['statement_checked'], true], 'expiry' => [['check_expired'], true],
    'export' => [['lines_exported'], true]]);
$evs = bk_all($mq, 'SELECT * FROM bank_line_events ORDER BY id');
$actors = array_values(array_unique(array_map(function ($e) { return $e['actor_kind'] . ':' . (string)$e['actor_id']; }, $evs)));
sort($actors);
t('trail: every staff event names the actor, the expiry names nobody', $actors, ['staff:7', 'system:']);
t('trail: every event, line status, bank and profile written is on its list', [
    array_values(array_diff(array_column($evs, 'event'), BankStatementStore::EVENTS)),
    array_values(array_diff(array_column(bk_all($mq, 'SELECT DISTINCT status FROM bank_statement_lines'), 'status'), BankStatementStore::LINE_STATUSES)),
    array_values(array_diff(array_column(bk_all($mq, 'SELECT bank FROM bank_account_profiles'), 'bank'), BankStatementStore::BANKS)),
    array_values(array_diff(array_column(bk_all($mq, 'SELECT profile FROM bank_account_profiles UNION SELECT profile FROM bank_statements UNION SELECT profile FROM bank_checks'), 'profile'), BankStatementStore::PROFILES))],
    [[], [], [], []]);
$allCodes = array_merge(EcobankDetailsCsv::READER_CODES, EcobankDetailsCsv::FIT_CODES, BankStatementStore::STORE_CODES, ['']);
$badCk = [];
foreach ([$p1, $p2, $p3, $p4, $mq, $q6, $q7] as $pdo) {
    foreach (bk_all($pdo, 'SELECT code, param FROM bank_checks') as $r) {
        if (!in_array($r['code'], $allCodes, true) || !EcobankDetailsCsv::paramFits((string)$r['param'])) $badCk[] = $r['code'] . ':' . $r['param'];
    }
}
t('trail: every code a check row holds is on a list, and every parameter has 088\'s shape', $badCk, []);
$leak = function (array $rows): array {
    $hits = [];
    foreach ([BK_MARK, BK_GEN_ACCOUNT, BK_GEN_HOLDER, 'RH-CLIENT', 'RH RESTATED'] as $needle) if (strpos((string)json_encode($rows), $needle) !== false) $hits[] = $needle;
    return $hits;
};
t('trail: no check row, event, statement or profile holds the marker, the account or the holder', [
    $leak(bk_all($mq, 'SELECT * FROM bank_checks')), $leak($evs), $leak(bk_all($mq, 'SELECT * FROM bank_statements')),
    $leak(bk_all($mq, 'SELECT * FROM bank_account_profiles'))], [[], [], [], []]);
$mLines = bk_all($mq, 'SELECT * FROM bank_statement_lines');
t('trail: the control — the kept lines hold the marker (it was in the file), and never the account number or the holder\'s name',
    [count($mLines), strpos((string)json_encode($mLines), BK_MARK) !== false, strpos((string)json_encode($mLines), BK_GEN_ACCOUNT) !== false,
     strpos((string)json_encode($mLines), BK_GEN_HOLDER) !== false], [60, true, false, false]);
$kfiles = array_merge(glob($MK['dir'] . '/bank_statements/*/*.csv') ?: [], glob($MK['dir'] . '/bank_statements/.pending/*.csv') ?: []);
$kHasAll = $kfiles !== [];
foreach ($kfiles as $f) { $b = (string)file_get_contents($f); if (strpos($b, BK_MARK) === false || strpos($b, BK_GEN_ACCOUNT) === false) $kHasAll = false; }
is_($kHasAll && count($kfiles) === 2, 'trail: the control — the kept files hold the statement as the bank issued it (marker, account number)', (string)count($kfiles));
$stray = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($T, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = $f->getPathname();
    if (!$f->isFile() || $f->isLink()) continue;
    if (preg_match('#/plugin\.sqlite3(-wal|-shm)?$#', $p)) continue;                       // the database holds the lines
    if (preg_match('#/bank_statements/(\d+|\.pending)/\d+-[0-9a-f]{16}(-[0-9a-f]{16})?\.csv$#', $p)) continue;   // the kept and held files
    if (strpos($p, $T . '/files/') === 0) continue;                                       // this test's own inputs
    $b = (string)@file_get_contents($p);
    if (strpos($b, BK_MARK) !== false || strpos($b, BK_GEN_HOLDER) !== false) $stray[] = substr($p, strlen($T) + 1);
}
t('trail: no other file this run wrote holds statement text (logs, vaults, migration logs, temporary files, the PHP error log)', $stray, []);

$casesTally = [$pass, $fail];
if ($mode === '--cases-only') {
    printf("\n%d passed, %d failed\n", $pass, $fail);
    exit($fail === 0 ? 0 : 1);
}

// ═══════════════════════════════════════════════════════════════════════════
echo "\nH. Memory: check and keep at the caps, each in a child under memory_limit=64M\n";
$self = __FILE__;
function bk_mem_child(string $self, string $root, array $orders, string $limit): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' -d memory_limit=' . $limit . ' -d upload_max_filesize=8M -d post_max_size=8M ' . escapeshellarg($self)
        . ' --memory-child --root=' . escapeshellarg($root);
    $pr = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($pr)) return [-1, null, 'proc_open failed'];
    fwrite($pipes[0], (string)json_encode($orders)); fclose($pipes[0]);
    $out = (string)stream_get_contents($pipes[1]); $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($pr), json_decode(trim($out), true), trim($err . ' ' . $out)];
}
$BUDGET = 64 * 1048576;
$HM = g_history(EcobankDetailsCsv::LINES_MAX, 5);
foreach ($HM as &$l) { $l['Remarks'] = str_pad($l['Remarks'] . ' RH-INVENTED', 96, 'X'); $l['Transaction Reference'] = $l['Remarks']; }
unset($l);
$keptCsv = $T . '/files/mem-kept.csv'; $fullCsv = $T . '/files/mem-full.csv';
file_put_contents($keptCsv, g_file(array_slice($HM, 0, EcobankDetailsCsv::LINES_MAX - 200)));
file_put_contents($fullCsv, g_file($HM));
unset($HM);
$MM = bk_copy($UGT, 'memory');
$o = ['dir' => $MM['dir'], 'acct' => $UGX, 'actor' => 7];
[$rc, $res] = bk_mem_child($self, $root, $o + ['act' => 'check', 'csv' => $keptCsv], '-1');
[$rc2, $res2] = bk_mem_child($self, $root, $o + ['act' => 'keep', 'check' => (int)($res['r']['check_id'] ?? 0), 'link' => true], '-1');
is_($rc === 0 && $rc2 === 0 && ($res2['r']['lines_added'] ?? 0) === EcobankDetailsCsv::LINES_MAX - 200,
    'memory: setup — 9,800 lines kept for the account (no limit)', json_encode([$rc, $res['r'] ?? null, $rc2, $res2['r'] ?? null]));
$fullSize = (int)filesize($fullCsv);
is_($fullSize >= (int)(0.9 * EcobankDetailsCsv::BYTES_MAX) && $fullSize <= EcobankDetailsCsv::BYTES_MAX, sprintf('memory: the 10,000-line file is within 10%% under BYTES_MAX (%.2f MB)', $fullSize / 1048576));
[$rc16, $res16] = bk_mem_child($self, $root, $o + ['act' => 'check', 'csv' => $fullCsv], '16M');
is_($rc16 !== 0 && !isset($res16['r']), 'memory: the control — the same check under memory_limit=16M does not complete', 'exit ' . $rc16);
[$rc, $res, $err] = bk_mem_child($self, $root, $o + ['act' => 'check', 'csv' => $fullCsv], '64M');
$peakC = (int)($res['peak_real'] ?? 0);
is_($rc === 0 && ($res['r']['ok'] ?? false) === true && ($res['r']['lines_new'] ?? 0) === 200 && ($res['r']['lines_known'] ?? 0) === EcobankDetailsCsv::LINES_MAX - 200
    && $peakC > 0 && $peakC < $BUDGET, sprintf('memory: check of 10,000 lines (%.2f MB) over 9,800 kept completes under 64M: 200 new; peak %.1f MB',
    $fullSize / 1048576, $peakC / 1048576), 'exit ' . $rc . ' ' . substr((string)json_encode($res), 0, 300) . ' ' . substr((string)$err, 0, 300));
[$rc, $res, $err] = bk_mem_child($self, $root, $o + ['act' => 'keep', 'check' => (int)($res['r']['check_id'] ?? 0), 'link' => false], '64M');
$peakK = (int)($res['peak_real'] ?? 0);
is_($rc === 0 && ($res['r']['ok'] ?? false) === true && ($res['r']['lines_added'] ?? 0) === 200 && $peakK > 0 && $peakK < $BUDGET,
    sprintf('memory: keep of the same file completes under 64M: 200 added; peak %.1f MB', $peakK / 1048576),
    'exit ' . $rc . ' ' . substr((string)json_encode($res), 0, 300) . ' ' . substr((string)$err, 0, 300));
t('memory: the account holds the 10,000 lines', count(bk_lines($MM['pdo'], $UGX)), EcobankDetailsCsv::LINES_MAX);

// ═══════════════════════════════════════════════════════════════════════════
echo "\nI. Weakened copies: each must fail its own cases, at the case named for it\n";
const TB2_DIRS  = ['lib', 'migrations', 'profiles'];
const TB2_FILES = ['tests/test_bank_store.php', 'tests/fixtures/bank_statement_gen.php'];
/**
 * A copy of lib/, migrations/, profiles/, this test and its generator, with $edits applied: each anchor must occur
 * exactly once before its edit; afterwards the replacement must be present and the anchor must survive only where the
 * replacement carries it. The edited files must lint. Returns [the copy's folder, '' or why it could not be made].
 */
function bk_weakened_copy(string $root, string $own, array $edits): array {
    $tmp = $own . '/copy-' . bin2hex(random_bytes(4));
    mkdir($tmp . '/tests/fixtures', 0700, true);
    foreach (TB2_DIRS as $d) {
        exec('cp -R ' . escapeshellarg($root . '/' . $d) . ' ' . escapeshellarg($tmp . '/' . $d) . ' 2>&1', $o, $rc);
        if ($rc !== 0) return [$tmp, "cannot copy {$d}"];
    }
    foreach (TB2_FILES as $rel) if (!copy($root . '/' . $rel, $tmp . '/' . $rel)) return [$tmp, "cannot copy {$rel}"];
    foreach ($edits as [$rel, $old, $new]) {
        $src = (string)file_get_contents($tmp . '/' . $rel);
        $n = substr_count($src, $old);
        if ($n !== 1) return [$tmp, "anchor found {$n} times in {$rel}"];
        $out = str_replace($old, $new, $src, $cnt);
        if ($cnt !== 1 || ($new !== '' && strpos($out, $new) === false) || substr_count($out, $old) !== substr_count($new, $old)) return [$tmp, "edit not applied in {$rel}"];
        file_put_contents($tmp . '/' . $rel, $out);
    }
    foreach (array_unique(array_column($edits, 0)) as $rel) {
        if (substr($rel, -4) !== '.php') continue;
        $lint = []; exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmp . '/' . $rel) . ' 2>&1', $lint, $lrc);
        if ($lrc !== 0) return [$tmp, "the edited {$rel} does not lint"];
    }
    return [$tmp, ''];
}
function bk_child(string $tree): array {
    $out = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tree . '/tests/test_bank_store.php') . ' --root=' . escapeshellarg($tree) . ' --cases-only 2>&1', $out, $rc);
    $fails = array_values(array_filter($out, function ($l) { return strpos($l, '  FAIL ') === 0; }));
    $tally = preg_match('/^(\d+) passed, (\d+) failed$/', (string)end($out), $tm) ? [(int)$tm[1], (int)$tm[2]] : null;
    $store = '';
    foreach ($out as $l) if (strncmp($l, 'store: ', 7) === 0) { $store = substr($l, 7); break; }
    return [$rc, $fails, $tally, $store];
}
$own = $T . '/copies'; mkdir($own, 0700);
$ST = 'lib/BankStatementStore.php'; $GT = 'lib/BankGate.php'; $RD = 'lib/EcobankDetailsCsv.php'; $MG = 'migrations/088_bank_statements.sql';
[$tmp, $why] = bk_weakened_copy($root, $own, [[$ST, 'final class BankStatementStore', 'final class BankStatementStore']]);
if ($why === '') {
    [$rc, $fails, $tally, $store] = bk_child($tmp);
    is_($rc === 0 && $fails === [] && $tally === $casesTally && $store === realpath($tmp . '/' . $ST),
        'the control: an unchanged copy passes its own cases with the same tally (' . ($tally ? "{$tally[0]} passed, {$tally[1]} failed" : 'no tally') . ') and judges its own store',
        'exit ' . $rc . ' tally ' . json_encode($tally) . ' store ' . $store . ' ' . ($fails[0] ?? ''));
} else {
    is_(false, 'the control: an unchanged copy can be made', $why);
}
bk_rmtree($tmp);
// [label, edits, the case that must FAIL]
$MUTANTS = [
    ['BW16 keep trusts the check made before its transaction (no re-check inside it)',
        [[$ST, '$fit = EcobankDetailsCsv::fit($ev[\'lines\'], $known, $first, $last);', '$fit = [\'ok\' => true, \'new\' => $ev[\'new\'], \'known\' => $ev[\'known\']];']],
        'keep: a statement kept in between, while this keep was between its copy and its transaction, refuses changed_since_check'],
    ['BW17 keep without the sha256 re-check of the held file',
        [[$ST, " || !hash_equals((string)\$chk['sha256'], hash('sha256', \$bytes))", '']],
        'keep: a held file altered on disk refuses changed_since_check, and the check expires'],
    ['BW18 the UNIQUE (account, cheque_number) index removed from 088',
        [[$MG, "CREATE UNIQUE INDEX IF NOT EXISTS uq_bank_lines_chq ON bank_statement_lines(cb_account_id, cheque_number);\n", '']],
        '088 probe: the same bank line twice for the account — REFUSED'],
    ['BW19 a no-delete trigger removed from 088',
        [[$MG, "CREATE TRIGGER IF NOT EXISTS bank_lines_no_delete BEFORE DELETE ON bank_statement_lines FOR EACH ROW\nBEGIN SELECT RAISE(ABORT, 'bank_statement_lines: an imported line is never deleted'); END;\n", '']],
        '088 probe: a line deleted — REFUSED'],
    ['BW20 a trigger body that names period_end inside BEGIN … END (the splitter)',
        [[$MG, "BEGIN SELECT RAISE(ABORT, 'bank_checks: a check is never deleted'); END;", "BEGIN SELECT RAISE(ABORT, 'bank_checks: a check is never deleted') FROM (SELECT 1 AS period_end); END;"]],
        '088: migration.log holds OK: 088_bank_statements.sql (27 stmts,'],
    ['BW21 files stored under uploads/bank_statements/',
        [[$ST, "public const DIR       = 'bank_statements';", "public const DIR       = 'uploads/bank_statements';"]],
        'files: the kept file is under bank_statements/<account id>/, never under uploads/'],
    ['BW22 files written 0644, folders 0755',
        [[$ST, 'public const DIR_MODE  = 0700;', 'public const DIR_MODE  = 0755;'], [$ST, 'public const FILE_MODE = 0600;', 'public const FILE_MODE = 0644;']],
        'files: folders 0700, the kept file 0600'],
    ['BW23 a planted ledger write in the keep path',
        [[$ST, "\$this->step('before_commit');", "\$this->pdo->exec(\"INSERT INTO cb_ledger (sr, date, direction, amount, currency, description) VALUES ('RH-BW23', '2026-08-01', 'in', 1, 'UGX', 'RH-BW23')\"); \$this->step('before_commit');"]],
        'posts nothing: cb_ledger — the same rows, byte for byte (count and sha256 in id order)'],
    ['BW25 open() without the switch',
        [[$GT, 'return self::halves($cfg, (string)$dataDir) && self::switchOn($cfg);', 'return self::halves($cfg, (string)$dataDir);']],
        'gate: Uganda (uganda; UGX,USD), switch off — open() is false'],
    ['BW26a open() without the Uganda half',
        [[$GT, 'return StaffJobsGate::applies($cfg, $dataDir) && !dn_ssp_selectable($cfg);', 'return !dn_ssp_selectable($cfg);']],
        "gate: a South Sudan tenant without SSP — BankGate's halves equal cashOnlyRuleInForce(), both false"],
    ['BW26b open() without the no-SSP half',
        [[$GT, 'return StaffJobsGate::applies($cfg, $dataDir) && !dn_ssp_selectable($cfg);', 'return StaffJobsGate::applies($cfg, $dataDir);']],
        "gate: a Uganda tenant with SSP — BankGate's halves equal cashOnlyRuleInForce(), both false"],
    ['BW27 allows() reading the session copy',
        [[$GT, "\$fresh = \$store->findOne('retailers.json', 'id', \$id);", '$fresh = $sessionRetailer;']],
        'allows: the demoted admin inside the 300-second window (session admin, store not) is refused'],
    ['BW28 allows() falling back to the session copy when the read throws',
        [[$GT, "        } catch (\\Throwable \$e) {\n            return null;\n        }\n        if (!is_array(\$fresh)", "        } catch (\\Throwable \$e) {\n            \$fresh = \$sessionRetailer;\n        }\n        if (!is_array(\$fresh)"]],
        'allows: a store whose read throws is a refusal, never the session copy'],
    ['BW34 BankGate reading the store copy instead of PluginConfig::read',
        [[$GT, 'self::$cfg[$dataDir] = PluginConfig::read(dirname(__DIR__), $dataDir);', 'self::$cfg[$dataDir] = SqliteStore::create($dataDir)->load(\'kyc_config.json\');']],
        'gate: the files say Uganda and on, the store copy says South Sudan and off — BankGate follows the files'],
    ['BW43 the overlap compares running_text (the padding)',
        [[$RD, "'side','credit_units','debit_units','running_units',", "'side','credit_units','debit_units','running_text',"]],
        'flow: a from-opening export re-padded to 20 adds only its 20 new lines'],
    ['BW44 fit() joining by balance, no shared line',
        [[$RD, "if (\$after && !isset(\$inFile[\$kMax])) return self::no('gap_after', \$last['posting_date']);",
            "if (\$after) { \$a = null; foreach (\$new as \$nl) if (strcmp(\$nl['cheque_number'], \$kMax) > 0) { \$a = \$nl; break; } if (\$a['running_units'] - \$a['credit_units'] + \$a['debit_units'] !== \$last['running_units']) return self::no('gap_after', \$last['posting_date']); }"]],
        'flow: a file starting one line after the last kept line is refused gap_after, dated that line'],
    ['BW45 the amount text stored untrimmed',
        [[$RD, "'credit_text'=>\$ct, 'debit_text'=>\$dt,", "'credit_text'=>\$r[9], 'debit_text'=>\$r[10],"]],
        'flow: a file with a space in every empty side is kept, the empty side stored empty'],
    ['BW46 a lower-case not_csv param',
        [[$RD, "return self::no('not_csv', 'PDF');", "return self::no('not_csv', 'pdf');"]],
        'flow: a PDF is a refused check, not_csv, PDF'],
    ['BW48 the keep moving the held file before COMMIT',
        [[$ST, "\$this->place(\$bytes, (string)\$acct['id'], \$finalRel);", "\$this->folder((string)\$acct['id']); @rename(\$held, \$this->dataDir . '/' . \$finalRel);"]],
        'crash at after_copy: the held file is still in .pending/'],
    ['S1 keep without its housekeeping (the TTL left to the keep)',
        [[$ST, "        \$this->housekeeping();                                                        // 1\n", '']],
        "ttl: the expiry is the system's — one check_expired event with no actor, and the check closed by nobody"],
    ['S3 the held file left behind after the keep',
        [[$ST, "        \$this->removeHeld(\$chk);                                                      // 9: only now\n", '']],
        'flow: the held file is gone once the statement is kept'],
    ['S5 C1 offering an inactive account',
        [[$ST, "WHERE kind = 'bank' AND active = 1\");", "WHERE kind = 'bank'\");"], [$ST, "(string)\$r['kind'] !== 'bank' || (int)\$r['active'] !== 1 || ", "(string)\$r['kind'] !== 'bank' || "]],
        'account: an inactive bank account is not offered; nothing is written'],
    ['S6 C3 not checked (the profile\'s last four)',
        [[$ST, "            if ((string)\$profile['acct_last4'] !== \$f['last4']) return self::refusedAs(\$ev, 'account_other', \$f['last4']);\n", '']],
        'account: a file for ••••0002 on the account linked to ••••0001 is refused account_other 0002'],
    ['S7 C4 not checked (linked elsewhere)',
        [[$ST, "        } elseif (\$this->linkedElsewhere(\$acct['id'], \$f['currency'], \$f['last4'])) {\n            return self::refusedAs(\$ev, 'account_linked_elsewhere', \$f['last4']);\n        }", '        }']],
        'account: ••••0001 already linked to the first UGX account is refused on the second, account_linked_elsewhere 0001'],
    ['S8 C2 not checked (the currency)',
        [[$ST, "        if (\$f['currency'] !== \$acct['currency']) return self::refusedAs(\$ev, 'currency_mismatch', \$f['currency'] . '|' . \$acct['currency']);\n", '']],
        'account: a UGX file on the USD account is refused currency_mismatch UGX|USD'],
    ['S10 a keep told already_kept removes every copy of the check, the other session\'s kept file among them',
        [[$ST, "                \$this->dropCopy(\$finalRel);                                           // this attempt's own copy, whatever happened\n",
            "                foreach (glob(\$this->dataDir . '/' . self::DIR . '/' . \$acct['id'] . '/' . (int)\$chk['id'] . '-*.csv') ?: [] as \$_p) @unlink(\$_p);\n"]],
        'concurrency: the statement kept by the second session keeps its file'],
    ['R1 one final name per check, shared by every keep attempt (no random part)',
        [[$ST, "substr((string)\$chk['sha256'], 0, 16) . '-' . bin2hex(random_bytes(8)) . '.csv';", "substr((string)\$chk['sha256'], 0, 16) . '-' . str_repeat('0', 16) . '.csv';"]],
        'concurrency: the kept statement\'s file is there, with the statement\'s sha256; nothing is held, nothing is orphaned'],
    ['R1b a dead attempt\'s copy left behind after the retry commits',
        [[$ST, "        \$this->dropOtherCopies((int)\$acct['id'], (int)\$chk['id'], \$finalRel);\n", '']],
        'crash at after_copy: pressing Keep again keeps it under a name of its own; the dead attempt\'s copy is removed, and no orphan is left'],
    ['S12 housekeeping deleting the held files of waiting checks',
        [[$ST, "if (!isset(\$waiting[self::DIR . '/' . self::PENDING . '/' . \$name])) \$remove[] = \$p;", '$remove[] = $p;']],
        "keep: two checks wait at once; the first one's held file survives the second check"],
    ['BS1 showLink() without its session term',
        [[$GT, "return !empty(\$sessionRetailer['is_admin']) && self::open(\$dataDir) && self::allows('bank.view', \$store, \$sessionRetailer) !== null;",
            "return self::open(\$dataDir) && self::allows('bank.view', \$store, \$sessionRetailer) !== null;"]],
        "showLink(): of the 8 combinations of the session copy's is_admin (S), the switch (W) and the fresh record's is_admin (F), only all three is true"],
    ['BS2 ecobankNamed() without the i flag',
        [[$ST, "return preg_match('/\\beco[\\s-]*bank/i', \$name) === 1;", "return preg_match('/\\beco[\\s-]*bank/', \$name) === 1;"]],
        "ecobankNamed(): the book's name says Ecobank, in any case, with a space, a hyphen or nothing between; any other bank's name does not, nor \"eco bank\" inside a longer word"],
    ['BS3 showLink() writing a table to the book',
        [[$GT, "return !empty(\$sessionRetailer['is_admin']) && self::open(\$dataDir) && self::allows('bank.view', \$store, \$sessionRetailer) !== null;",
            "try { if (is_object(\$store) && method_exists(\$store, 'getPdo')) \$store->getPdo()->exec('CREATE TABLE IF NOT EXISTS rh_link_mark (x INTEGER)'); } catch (\\Throwable \$e) { } return !empty(\$sessionRetailer['is_admin']) && self::open(\$dataDir) && self::allows('bank.view', \$store, \$sessionRetailer) !== null;"]],
        "showLink() in a fresh process, as an admin: true, and total_changes(), the database files and sqlite_master (read on a second connection) are the same after it; the controls — one planted INSERT moves total_changes(), and a table planted in the main schema afterwards moves both the files and sqlite_master, the main file having been read (not absent)"],
    ['BS4 ecobankNamed() without the word boundary',
        [[$ST, "return preg_match('/\\beco[\\s-]*bank/i', \$name) === 1;", "return preg_match('/eco[\\s-]*bank/i', \$name) === 1;"]],
        "ecobankNamed(): the book's name says Ecobank, in any case, with a space, a hyphen or nothing between; any other bank's name does not, nor \"eco bank\" inside a longer word"],
    ['S15 a Remark written into the statement_kept event',
        [[$ST, "'sha8' => substr((string)\$chk['sha256'], 0, 8),", "'sha8' => substr((string)\$chk['sha256'], 0, 8), 'first' => (string)(\$new[0]['remarks'] ?? ''),"]],
        'trail: no check row, event, statement or profile holds the marker, the account or the holder'],
];
$caught = 0;
foreach ($MUTANTS as [$label, $edits, $catcher]) {
    [$tmp, $why] = bk_weakened_copy($root, $own, $edits);
    if ($why !== '') { is_(false, "weakened copy \"{$label}\" can be made", $why); bk_rmtree($tmp); continue; }
    [$rc, $fails, $tally, $store] = bk_child($tmp);
    $named = array_values(array_filter($fails, function ($l) use ($catcher) { return rtrim($l) === '  FAIL ' . $catcher; }));
    $ok = $rc !== 0 && $fails !== [] && $named !== [] && $store === realpath($tmp . '/' . $ST);
    if ($ok) $caught++;
    is_($ok, "caught: {$label} — by its named case",
        'exit ' . $rc . ', ' . count($fails) . ' failure(s), ' . count($named) . ' at the named case; store ' . $store . '; first: ' . ($fails[0] ?? 'none'));
    echo '         ' . count($fails) . ' case(s) failed' . ($tally ? " ({$tally[0]} passed)" : ' (no tally)') . ($fails ? '; first: ' . trim(substr($fails[0], 7, 110)) : '') . "\n";
    bk_rmtree($tmp);
}
t('every weakened copy was caught', $caught, count($MUTANTS));

// ═══════════════════════════════════════════════════════════════════════════
echo "\nJ. The end check\n";
$env = (string)getenv('DN_VAULT_FILE');
is_($env !== '' && strpos($env, $T . '/vaults/') === 0, 'DN_VAULT_FILE still names a file inside this test\'s own folder', $env === '' ? 'unset' : 'it names another file');
$unnamed = [];
foreach (scandir($T . '/vaults') ?: [] as $f) if ($f !== '.' && $f !== '..' && !isset($VAULTS[$T . '/vaults/' . $f])) $unnamed[] = $f;
t('every vault file in its folder is one this test named', $unnamed, []);
clearstatcache();
$defAfter = is_file($defVault) ? [hash_file('sha256', $defVault), filemtime($defVault)] : null;
is_($defAfter === $defBefore, $defBefore === null ? 'no vault appeared at the default path beside the code' : 'the vault at the default path beside the code is unchanged');
t('no weakened copy is left in this run\'s folder (the folder itself goes at exit)', array_values(array_diff(scandir($own) ?: [], ['.', '..'])), []);
is_(strpos((string)getenv('TMPDIR'), $T) === 0, 'every child ran with this run\'s own TMPDIR', (string)getenv('TMPDIR'));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
