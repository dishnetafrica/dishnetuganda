<?php
declare(strict_types=1);
/**
 * test_cash_in_hand_p3b_south_sudan.php — P3b (5.18.95, D2 = A; SPEC §d.1 T3): wherever the cash-in-hand rule is not in
 * force, every output is 5.18.94's, byte for byte.
 *
 * Two trees, the 5.18.50 method: f4b4c96 (5.18.94, the live code) taken from Git by sj_baseline_tree(), and this tree.
 * When Git cannot supply f4b4c96 the comparison is reported as a skip, never as a pass. Each book below is built once,
 * through the plugin's own writers, in a folder of its own, and the same rows are copied into a sandbox of each tree, so
 * the two trees read the same book and only the code differs. Three books, each one P3b's rule would change if it ran:
 *   SS  South Sudan (south-sudan; USD,SSP), the live tenant
 *   SN  a South Sudan tenant without SSP (cashbook_currencies=USD): the tenant half of the gate is false
 *   UP  a Uganda tenant with SSP (UGX,USD,SSP): the SSP half of the gate is false
 * Each holds rows with no account in the base and in SSP (where SSP exists), a cash and a bank account with openings, a
 * director pair through recordFunding(), pending, rejected, voided and voided_reconcile rows, an orphan account id, a row
 * stored with no currency, and 4g rows.
 *
 * Compared on both trees, per book: the Cashbook (ledger per currency view, alerts, summary, pending; dishnet and 4g),
 * the CSV export per view, the SSP Cashbook, the landing and CEO pages as admin, the API's cashbook_entries and
 * cashbook_ledger, the evening WhatsApp (its text and the cron's own output), tools/cash_in_hand.php, and cashInHand()
 * and getEntries() read directly through each tree's library. Identical once the per-run values are normalised: ports,
 * tokens, form tokens, clocks, "Ns ago", the cron tick after </html>, each tree's own version label, the sandbox paths,
 * and the pages' printed date and time. Each output is read from both trees within one clock minute, the finest unit any
 * page prints, so month- and day-bounded tiles agree; a pair that straddles a minute is read again.
 *
 * The tool's banner and its new rule line are the only output that changes on every tenant (SPEC §c.2): they are taken
 * out of the comparison and checked against fixed text. A difference is reported by its position and length only.
 *
 * Two things are kept apart rather than normalised away, each as narrowly as it can be:
 *   - the tool's error stream (ConfigVault's notices go there, by error_log()) is compared on its own, byte for byte,
 *     beside the printed lines: 5.18.95 loads the configuration before its banner, to choose the rule line, and that
 *     moves where a notice falls among the printed lines, not what either stream says;
 *   - a page that fails on both trees prints the failing statement's line (lib/error_handler.php), which the lines P3b
 *     adds above it move. That number alone is normalised, and only where the line each tree cites holds the same
 *     statement: a failure anywhere else still differs.
 *
 * Then: weakened copies of the code each fail this test and the unchanged copy passes; a copy with the rule forced true
 * differs on each of the three books (the control on the control); and f4b4c96's own code fails exactly at the fixed
 * banner and rule-line checks and nowhere else (the control on the controls). Every amount, name and number is invented.
 *
 *   php test_cash_in_hand_p3b_south_sudan.php [--root=DIR] [--base=DIR] [--no-mutants]
 *
 * --root is the tree compared with f4b4c96 (default: this one); --base an f4b4c96 tree already taken from Git, which the
 * weakened-copy runs reuse; --no-mutants runs sections 1–3 only.
 */

// ── 0. A temporary folder of its own: this process runs again inside it, so that every sandbox, Git tree and weakened
//       copy it makes (they all use sys_get_temp_dir(), which a running process cannot move) lands there, and goes.
if ((string)getenv('P3B_T3_TMP') === '') {
    $own = sys_get_temp_dir() . '/p3b-t3-' . getmypid() . '-' . bin2hex(random_bytes(3));
    mkdir($own, 0700, true);
    $cmd = 'P3B_T3_TMP=' . escapeshellarg($own) . ' TMPDIR=' . escapeshellarg($own) . ' '
         . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__);
    foreach (array_slice($argv, 1) as $a) $cmd .= ' ' . escapeshellarg((string)$a);
    passthru($cmd, $rc);
    exec('rm -rf ' . escapeshellarg($own));
    exit((int)$rc);
}
$OWN = rtrim((string)getenv('P3B_T3_TMP'), '/');
$RUN = $OWN . '/run-' . getmypid() . '-' . bin2hex(random_bytes(2));   // this process's books and vaults (a copy run shares $OWN)
mkdir($RUN, 0700, true);

$opt  = getopt('', ['root:', 'base:', 'no-mutants']);
$lib  = dirname(__DIR__);                                                   // the book's writers: always this checkout's
$root = isset($opt['root']) ? rtrim((string)$opt['root'], '/') : $lib;     // the tree compared with f4b4c96
$withMutants = !isset($opt['no-mutants']);

$pass = 0; $fail = 0; $skip = 0;
function is_(bool $c, string $m, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; }
    else    { $fail++; echo "  FAIL {$m}" . ($d !== '' ? "\n       {$d}" : '') . "\n"; }
}
function t3_done(): void {
    global $pass, $fail, $skip;
    printf("\n%d passed, %d failed, %d skipped\n", $pass, $fail, $skip);
    exit($fail === 0 ? 0 : 1);
}

/** Where two strings first differ — never what they say: the books carry amounts and names. */
function t3_where(string $a, string $b): string
{
    $n = min(strlen($a), strlen($b)); $i = 0;
    while ($i < $n && $a[$i] === $b[$i]) $i++;
    return sprintf('first difference at byte %d (line %d); lengths %d / %d', $i, substr_count(substr($a, 0, $i), "\n") + 1, strlen($a), strlen($b));
}

// The in-process book writers read a configuration through PluginConfig::load(), which also reads <plugin>/data. A
// checkout holding a configuration or a book there would hand its settings to every book below: refuse first.
if (is_file($lib . '/data/config.json') || is_file($lib . '/data/kyc_config.json') || is_file($lib . '/data/plugin.sqlite3')) {
    is_(false, 'this test runs from a checkout whose data folder holds no configuration or book', $lib . '/data');
    t3_done();
}

// Its own vault before anything can load a configuration (ConfigVault does not follow DN_DATA_DIR); one per book below.
putenv('DN_VAULT_FILE=' . $RUN . '/vault-boot.json');
ini_set('error_log', $RUN . '/php-error.log');
$defVault  = dirname($lib) . '/.dishnet-sudan.vault.json';                  // ConfigVault::path()'s default for this tree
clearstatcache();
$defBefore = is_file($defVault) ? [hash_file('sha256', $defVault), filemtime($defVault)] : null;

require_once $lib . '/tests/fixtures/staff_jobs_scenario.php';             // sj_baseline_tree(), SjSandbox, sj_weakened_copy()
require_once $lib . '/lib/StoreInterface.php';
require_once $lib . '/lib/JsonStore.php';
require_once $lib . '/lib/SqliteStore.php';
require_once $lib . '/lib/CashbookService.php';

const T3_BASELINE   = 'f4b4c96';
const T3_OLD_BANNER = '== cash in hand (5.18.71) — read-only ==';
const T3_NEW_BANNER = '== cash in hand (5.18.95) — read-only ==';
const T3_NEW_RULE   = '  rule       every entry not voided, any account (as before 5.18.95)';
const T3_OLD_SENT   = "  the ledger's running balance per currency (rows not voided; accounts and the unassigned split play no part):";
const T3_ADMIN      = 'RH-Admin One';
const T3_STDERR     = "\n\0 the error stream \0\n";

// ── 1. The baseline tree ──────────────────────────────────────────────────────
if (isset($opt['base'])) {
    $base = rtrim((string)$opt['base'], '/'); $why = 'the given --base holds no plugin';
    if (!is_file($base . '/manifest.json')) $base = null;
} else {
    [$base, $why] = sj_baseline_tree(T3_BASELINE);
}
if ($base === null) {
    $skip++;
    echo "  skip the whole comparison: {$why}\n";
    t3_done();
}
$verOf = function (string $tree): string {
    $m = json_decode((string)@file_get_contents($tree . '/manifest.json'), true);
    return (string)($m['information']['version'] ?? '');
};
is_($verOf($base) === '5.18.94', 'the baseline is 5.18.94, from Git (' . T3_BASELINE . ')', 'version ' . $verOf($base));

// ── 2. The books ──────────────────────────────────────────────────────────────
$BOOKS = [
    'SS' => ['label' => 'South Sudan (south-sudan; USD,SSP)', 'tz' => 'Africa/Juba', 'phone' => '+211900000171',
             'cfg' => ['tenant_profile' => 'south-sudan', 'currency_code' => 'USD', 'currency_symbol' => '$',
                       'cashbook_base_currency' => 'USD', 'cashbook_currencies' => 'USD,SSP']],
    'SN' => ['label' => 'a South Sudan tenant without SSP (south-sudan; USD)', 'tz' => 'Africa/Juba', 'phone' => '+211900000172',
             'cfg' => ['tenant_profile' => 'south-sudan', 'currency_code' => 'USD', 'currency_symbol' => '$',
                       'cashbook_base_currency' => 'USD', 'cashbook_currencies' => 'USD']],
    'UP' => ['label' => 'a Uganda tenant with SSP (uganda; UGX,USD,SSP)', 'tz' => 'Africa/Kampala', 'phone' => '+256700000173',
             'cfg' => ['tenant_profile' => 'uganda', 'currency_code' => 'UGX', 'currency_symbol' => 'UGX',
                       'cashbook_base_currency' => 'UGX', 'cashbook_currencies' => 'UGX,USD,SSP']],
];

/**
 * One book, written through the plugin's own writers in a folder of its own: [path of its store, its currencies]. Raw
 * SQL adds only what no writer can make (an orphan account id). Dates are relative to today, in the book's own zone.
 */
function t3_build_book(string $dir, array $cfg): array
{
    mkdir($dir, 0700, true);
    putenv('DN_VAULT_FILE=' . $dir . '-vault.json');
    file_put_contents($dir . '/kyc_config.json', json_encode($cfg + ['data_dir' => $dir]));
    $store = SqliteStore::create($dir);
    $cb    = new CashbookService($store, $dir);
    $curs  = array_map('trim', explode(',', (string)$cfg['cashbook_currencies']));
    $B     = $curs[0];
    $me    = ['name' => T3_ADMIN];
    $d     = function (int $n): string { return date('Y-m-d', strtotime("-{$n} days")); };
    $ok    = [];
    $acc   = function (string $name, string $cur, string $kind) use ($cb, &$ok): int {
        $r = $cb->addAccount($name, $cur, $kind); $ok[] = !empty($r['ok']); return (int)($r['id'] ?? 0);
    };
    $add   = function (array $r, bool $approve = true) use ($cb, $me, &$ok): int {
        $x = $cb->addEntry($r + ['validation_status' => 'na'], $me, $approve); $ok[] = !empty($x['ok']); return (int)($x['id'] ?? 0);
    };
    $raw   = function (array $r) use ($cb): string {
        return $cb->addEntryRaw($r + ['status' => 'approved', 'approved_by' => T3_ADMIN, 'validation_status' => 'na']);
    };
    $cashB = $acc("RH-Cash Box – {$B}", $B, 'cash');
    $bankB = $acc("RH-Bank – {$B}", $B, 'bank');
    $dirB  = $acc("RH-Director – {$B}", $B, 'director');
    $ok[] = !empty($cb->recordOpeningBalance($cashB, 400, $d(40), 'RH opening', 'RH-OB', T3_ADMIN)['ok']);
    $ok[] = !empty($cb->recordOpeningBalance($bankB, 2500, $d(40), 'RH opening', 'RH-OB', T3_ADMIN)['ok']);
    if ($B !== 'USD') {
        $cashD = $acc('RH-Cash Box – USD', 'USD', 'cash');
        $bankD = $acc('RH-Bank – USD', 'USD', 'bank');
        $ok[] = !empty($cb->recordOpeningBalance($cashD, 60, $d(40), 'RH opening', 'RH-OB', T3_ADMIN)['ok']);
        $ok[] = !empty($cb->recordOpeningBalance($bankD, 900, $d(40), 'RH opening', 'RH-OB', T3_ADMIN)['ok']);
        $add(['project' => 'dishnet', 'date' => $d(18), 'direction' => 'out', 'amount' => 450, 'currency' => 'USD',
              'category' => 'Misc Expense', 'description' => 'RH dollar spending, no account']);
        $raw(['project' => 'dishnet', 'date' => $d(16), 'direction' => 'in', 'amount' => 300, 'currency' => 'USD',
              'category' => 'Receipt', 'description' => 'RH-Client Seven bank receipt', 'account_id' => $bankD]);
    }
    $ok[] = !empty($cb->recordFunding($bankB, $dirB, 700, $d(35), 'RH funding into the bank', '', T3_ADMIN)['ok']);
    $add(['project' => 'dishnet', 'date' => $d(30), 'direction' => 'in', 'amount' => 130, 'currency' => $B,
          'category' => 'Receipt', 'description' => 'RH-Client One payment']);
    $add(['project' => 'dishnet', 'date' => $d(20), 'direction' => 'out', 'amount' => 4800, 'currency' => $B,
          'category' => 'Misc Expense', 'description' => 'RH office expense, no account']);
    $add(['project' => 'dishnet', 'date' => $d(5), 'direction' => 'in', 'amount' => 210, 'currency' => $B,
          'category' => 'Receipt', 'description' => 'RH-Client Two payment, awaiting approval'], false);
    $rej = $add(['project' => 'dishnet', 'date' => $d(6), 'direction' => 'in', 'amount' => 820, 'currency' => $B,
                 'category' => 'Receipt', 'description' => 'RH-Client Three payment']);
    $cb->rejectEntry($rej, 'RH test rejection', $me);
    $voi = $add(['project' => 'dishnet', 'date' => $d(7), 'direction' => 'in', 'amount' => 540, 'currency' => $B,
                 'category' => 'Receipt', 'description' => 'RH voided receipt']);
    $ok[] = !empty($cb->voidEntry($voi, 'RH test void', T3_ADMIN)['ok']);
    $raw(['project' => 'dishnet', 'date' => $d(8), 'direction' => 'out', 'amount' => 650, 'currency' => $B,
          'category' => 'Misc Expense', 'description' => 'RH reconcile-voided row', 'status' => 'voided_reconcile']);
    $orphanSr = $raw(['project' => 'dishnet', 'date' => $d(9), 'direction' => 'out', 'amount' => 87, 'currency' => $B,
                      'category' => 'Misc Expense', 'description' => 'RH row on an account that does not exist']);
    $store->getPdo()->prepare('UPDATE cb_ledger SET account_id = 9999 WHERE sr = ?')->execute([$orphanSr]);
    $raw(['project' => 'dishnet', 'date' => $d(10), 'direction' => 'in', 'amount' => 66, 'currency' => '',
          'category' => 'Receipt', 'description' => 'RH receipt stored with no currency']);
    // dishnet takes no receipt in the last seven days and holds a pending disbursement: with the rejected receipt above,
    // its approved balance is below zero while today's cash in hand is above it, so Alert 2 and Alert 6 tell them apart
    $add(['project' => 'dishnet', 'date' => $d(0), 'direction' => 'out', 'amount' => 75, 'currency' => $B,
          'category' => 'Misc Expense', 'description' => 'RH office supplies today']);
    $add(['project' => 'dishnet', 'date' => $d(2), 'direction' => 'out', 'amount' => 30, 'currency' => $B, 'category' => 'Staff Advance',
          'person' => 'RH-Collector Two', 'description' => 'RH pending disbursement', 'validation_status' => 'pending']);
    // 4g: a receipt with no account (older than seven days), a bank expense, a pending disbursement
    $add(['project' => '4g', 'date' => $d(20), 'direction' => 'in', 'amount' => 1200, 'currency' => $B,
          'category' => 'Receipt', 'description' => 'RH-Client Four 4G payment']);
    $raw(['project' => '4g', 'date' => $d(15), 'direction' => 'out', 'amount' => 95, 'currency' => $B,
          'category' => 'Site Power', 'description' => 'RH 4G bank expense', 'account_id' => $bankB]);
    $add(['project' => '4g', 'date' => $d(3), 'direction' => 'out', 'amount' => 45, 'currency' => $B, 'category' => 'Staff Advance',
          'person' => 'RH-Collector One', 'description' => 'RH 4G pending disbursement', 'validation_status' => 'pending']);
    if (in_array('SSP', $curs, true)) {
        $add(['project' => 'dishnet', 'date' => $d(12), 'direction' => 'in', 'amount' => 50, 'currency' => 'SSP', 'ssp_amount' => 250000,
              'ssp_rate' => 5000, 'category' => 'Receipt', 'description' => 'RH-Client Six SSP payment']);
        $add(['project' => 'dishnet', 'date' => $d(4), 'direction' => 'out', 'amount' => 8, 'currency' => 'SSP', 'ssp_amount' => 40000,
              'ssp_rate' => 5000, 'category' => 'Misc Expense', 'description' => 'RH SSP expense awaiting approval'], false);
    }
    return [$dir . '/plugin.sqlite3', $curs, !in_array(false, $ok, true) ? count($ok) : -1];
}

/**
 * The book's rows, copied into a sandbox's store: its three tables, their schema and their rows, nothing else of the
 * book's folder. A fresh store already holds an empty cb_ledger in the store's own base shape (CashbookService adds its
 * columns on first use); that table is replaced by the book's. A table that already holds rows is refused.
 */
function t3_copy_book(string $src, string $dst): int
{
    $pdo = new \PDO('sqlite:' . $dst);
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $pdo->exec('ATTACH DATABASE ' . $pdo->quote($src) . ' AS src');
    $n = 0;
    foreach (['cb_accounts', 'cb_ledger', 'fin_audit'] as $t) {
        $sql = $pdo->query("SELECT sql FROM src.sqlite_master WHERE type = 'table' AND name = " . $pdo->quote($t))->fetchColumn();
        if (!$sql) continue;
        if ($pdo->query("SELECT 1 FROM main.sqlite_master WHERE type = 'table' AND name = " . $pdo->quote($t))->fetchColumn()) {
            if ((int)$pdo->query("SELECT COUNT(*) FROM main.{$t}")->fetchColumn() !== 0) throw new \RuntimeException("a fresh sandbox already holds rows in {$t}");
            $pdo->exec("DROP TABLE main.{$t}");
        }
        $pdo->exec((string)$sql);
        $pdo->exec("INSERT INTO main.{$t} SELECT * FROM src.{$t}");
        foreach ($pdo->query("SELECT sql FROM src.sqlite_master WHERE type = 'index' AND tbl_name = " . $pdo->quote($t) . ' AND sql IS NOT NULL')->fetchAll(\PDO::FETCH_COLUMN) as $ix) {
            $pdo->exec((string)$ix);
        }
        $n += (int)$pdo->query("SELECT COUNT(*) FROM main.{$t}")->fetchColumn();
    }
    $pdo->exec('DETACH DATABASE src');
    return $n;
}

/**
 * Today's cash in hand and D2 = A's, by raw SQL on the book itself — written here, not read from the library — so that
 * "rule A would change this book" is measured, not assumed.
 */
function t3_two_rules(\PDO $pdo, string $cur, string $base, string $proj): array
{
    $amt  = $cur === 'SSP' ? 'COALESCE(ssp_amount,0)' : 'amount';
    $curW = $cur === $base ? "(currency = '{$cur}' OR currency IS NULL OR currency = '')" : "currency = '{$cur}'";
    $sql  = "SELECT ROUND(COALESCE(SUM(CASE WHEN direction = 'in' THEN {$amt} ELSE -{$amt} END), 0), 2) FROM cb_ledger
             WHERE status NOT IN ('voided','voided_reconcile') AND {$curW}" . ($proj !== '' ? ' AND project = ' . $pdo->quote($proj) : '');
    $a    = " AND status = 'approved' AND (COALESCE(account_id,0) = 0 OR account_id IN (SELECT id FROM cb_accounts WHERE kind IN ('cash','momo')))";
    return [(float)$pdo->query($sql)->fetchColumn(), (float)$pdo->query($sql . $a)->fetchColumn()];
}

/** Each tree's own library, read directly: cashInHand() per currency and scope, getEntries() per view and project. */
const T3_READER = <<<'PHP'
<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/StoreInterface.php';
require_once __DIR__ . '/lib/JsonStore.php';
require_once __DIR__ . '/lib/SqliteStore.php';
require_once __DIR__ . '/lib/CashbookService.php';
$dataDir = (string)getenv('DN_DATA_DIR');
$cb   = new CashbookService(SqliteStore::create($dataDir), $dataDir);
$curs = json_decode((string)($argv[1] ?? '[]'), true);
$out  = ['cash_in_hand' => [], 'entries' => []];
foreach ($curs as $c) foreach (['', 'dishnet', '4g', 'bluecard'] as $p) $out['cash_in_hand'][$c . '|' . $p] = $cb->cashInHand($c, $p);
foreach (array_merge([''], $curs) as $v) foreach (['', 'dishnet', '4g'] as $p) {
    $out['entries'][$v . '|' . $p] = array_map(function ($r) { return [(int)$r['id'], $r['running_balance'], $r['_bal_currency']]; },
        $cb->getEntries(array_filter(['project' => $p, 'currency' => $v, 'limit' => 9999])));
}
$rule = method_exists($cb, 'cashOnlyRuleInForce') ? ($cb->cashOnlyRuleInForce() ? 'true' : 'false') : 'absent';
echo json_encode($out), "\n", 'RULE ', $rule, "\n";
PHP;

/** The values that differ between any two runs of the same book on any tree — and nothing else. */
function t3_norm(string $x, SjSandbox $s, array $stamps, bool $page = false): string
{
    if ($page && ($p = strpos($x, '</html>')) !== false) $x = substr($x, 0, $p + 7);   // the cron tick prints after it
    static $ver = [];
    if (!isset($ver[$s->root])) {
        $m = json_decode((string)@file_get_contents($s->root . '/manifest.json'), true);
        $ver[$s->root] = (string)($m['information']['version'] ?? '');
    }
    if ($ver[$s->root] !== '') $x = (string)preg_replace('/\bv' . preg_quote($ver[$s->root], '/') . '\b/', 'v<version>', $x);
    foreach ($s->tok as $k => $t) $x = str_replace($t, "<tok:{$k}>", $x);
    $x = str_replace([$s->data, $s->plug, $s->sb], ['<data>', '<plug>', '<sb>'], $x);
    foreach (['crm' => $s->crm, 'evo' => $s->evo, 'plugin' => (string)preg_replace('#/public\.php$#', '', $s->base)] as $k => $a) {
        $x = str_replace([$a, str_replace('/', '\/', $a)], "<{$k}>", $x);
    }
    $x = (string)preg_replace('/\b\d{8}:[0-9a-f]{40}\b/', '<day-token>', $x);
    $x = (string)preg_replace('/name="_csrf" value="[^"]*"/', 'name="_csrf" value="<csrf>"', $x);
    $x = (string)preg_replace('/\b\d+[smhd] ago\b/', '<ago>', $x);
    // the pages' printed date and time (ceo_dashboard.php's header, the Cashbook hero), read at the pair's minute
    $x = str_replace($stamps, array_map(function ($k) { return "<{$k}>"; }, array_keys($stamps)), $x);
    return (string)preg_replace('/\b\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?\b/', '<clock>', $x);
}

/** The trimmed source line $line of the one file named $name that a sandbox serves, or null when it is not one file. */
function t3_source_line(string $plug, string $name, int $line): ?string
{
    static $memo = [];
    $k = $plug . '|' . $name;
    if (!array_key_exists($k, $memo)) {
        $hits = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($plug, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getFilename() === $name && strpos($f->getPathname(), $plug . '/data/') !== 0) $hits[] = $f->getPathname();
        }
        $memo[$k] = count($hits) === 1 ? file($hits[0], FILE_IGNORE_NEW_LINES) : null;
    }
    return ($memo[$k] !== null && isset($memo[$k][$line - 1])) ? trim($memo[$k][$line - 1]) : null;
}

/**
 * A page that fails says where: "<file>:<line>" (lib/error_handler.php). The same failure on both trees cites the same
 * statement, and its line number moves whenever lines are added above it. Only that number is normalised, and only when
 * the line each tree cites holds the same statement: a failure at any other statement still differs. Counts into $n.
 */
function t3_same_statement(string $a, string $b, SjSandbox $sa, SjSandbox $sb, int &$n): array
{
    $re = '#(<small style="color:\#999">)([A-Za-z0-9_.-]+\.php):(\d+)(</small>)#';
    preg_match_all($re, $a, $ma, PREG_SET_ORDER);
    preg_match_all($re, $b, $mb, PREG_SET_ORDER);
    if ($ma === [] || count($ma) !== count($mb)) return [$a, $b];
    foreach ($ma as $i => $x) {
        $la = t3_source_line($sa->plug, $x[2], (int)$x[3]);
        if ($x[2] !== $mb[$i][2] || $la === null || $la !== t3_source_line($sb->plug, $mb[$i][2], (int)$mb[$i][3])) return [$a, $b];
    }
    $to = function ($m) { return $m[1] . $m[2] . ':<the same statement>' . $m[4]; };
    $n += count($ma);
    return [(string)preg_replace_callback($re, $to, $a), (string)preg_replace_callback($re, $to, $b)];
}

/** A plugin script run in the sandbox as SjSandbox::run() runs it, with its error stream kept apart. */
function t3_run_split(SjSandbox $s, string $script): string
{
    $err = $s->sb . '/stderr-' . bin2hex(random_bytes(3));
    $out = [];
    exec(sprintf('cd %s && DN_DATA_DIR=%s DN_VAULT_FILE=%s %s %s 2>%s', escapeshellarg($s->plug), escapeshellarg($s->data),
        escapeshellarg($s->vault), escapeshellarg(PHP_BINARY), escapeshellarg($script), escapeshellarg($err)), $out, $rc);
    $e = (string)@file_get_contents($err);
    @unlink($err);
    return $rc . "\n" . implode("\n", $out) . T3_STDERR . $e;
}

/**
 * One output from each tree, read within one clock minute; a pair that straddles a minute is read again. Returns the
 * two normalised outputs and whether they were read within one minute.
 */
function t3_pair(callable $get, SjSandbox $a, SjSandbox $b, bool $page): array
{
    for ($try = 0; $try < 4; $try++) {
        $t0 = time();
        $stamps = ['long-date' => date('l, d M Y', $t0), 'date' => date('d M Y', $t0), 'time' => date('g:i A', $t0)];
        $x = $get($a); $y = $get($b);
        if (gmdate('Y-m-d H:i', time()) === gmdate('Y-m-d H:i', $t0)) {
            return [t3_norm($x, $a, $stamps, $page), t3_norm($y, $b, $stamps, $page), true];
        }
    }
    return [t3_norm($x, $a, $stamps, $page), t3_norm($y, $b, $stamps, $page), false];
}

// ── 3. Each book, on both trees ───────────────────────────────────────────────
foreach ($BOOKS as $tag => $B) {
    echo "\n{$tag}. {$B['label']}\n";
    try {
        date_default_timezone_set($B['tz']);
        [$book, $curs, $nOk] = t3_build_book($RUN . '/book-' . strtolower($tag), $B['cfg']);
        $base0 = $curs[0];
        is_($nOk > 0, "[{$tag}] the book is written through the plugin's own writers, every one accepting its row ({$nOk} acts)");

        // The book discriminates: every class P3b treats differently is in it, and its rule would change the figures.
        $bp = new \PDO('sqlite:' . $book);
        $cls = [];
        foreach ($bp->query("SELECT l.status, l.currency, l.project, l.account_id, a.kind FROM cb_ledger l LEFT JOIN cb_accounts a ON a.id = l.account_id") as $r) {
            $k = [];
            if ($r['status'] !== 'approved') $k[] = 'status:' . $r['status'];
            if ((string)$r['account_id'] !== '0' && $r['kind'] === null) $k[] = 'orphan account id';
            if ($r['kind'] !== null) $k[] = 'kind:' . $r['kind'];
            if ((string)$r['account_id'] === '0') $k[] = 'no account';
            if ((string)$r['currency'] === '') $k[] = 'no currency';
            if ($r['project'] === '4g') $k[] = 'project 4g';
            if ($r['currency'] === 'SSP') $k[] = 'SSP';
            foreach ($k as $x) $cls[$x] = ($cls[$x] ?? 0) + 1;
        }
        $need = ['no account', 'kind:cash', 'kind:bank', 'kind:director', 'status:pending_approval', 'status:rejected', 'status:voided',
                 'status:voided_reconcile', 'orphan account id', 'no currency', 'project 4g'];
        if (in_array('SSP', $curs, true)) $need[] = 'SSP';
        $miss = array_values(array_filter($need, function ($k) use ($cls) { return ($cls[$k] ?? 0) === 0; }));
        is_($miss === [], "[{$tag}] the book holds a row of every class: " . implode(', ', $need), 'missing ' . implode(', ', $miss));
        $moves = [];
        foreach ($curs as $c) foreach (['', 'dishnet', '4g'] as $p) {
            [$t, $a] = t3_two_rules($bp, $c, $base0, $p);
            if (abs($t - $a) >= 0.005) $moves[] = $c . ($p !== '' ? "/{$p}" : '/all');
        }
        is_(in_array("{$base0}/dishnet", $moves, true) && in_array("{$base0}/4g", $moves, true) && in_array("{$base0}/all", $moves, true)
            && (!in_array('SSP', $curs, true) || in_array('SSP/dishnet', $moves, true)),
            "[{$tag}] control: rule A would change this book — today's figure and D2's differ, by raw SQL, in " . count($moves) . ' currency scopes',
            implode(' ', $moves));
        if ($base0 === 'USD') {
            // The Cashbook's Alert 2, Alert 6 and pill read $projBal (approved USD rows, any account) while the rule is off.
            // Where a weakened page read cash in hand instead, they must show it: on dishnet the two have opposite signs.
            $q = function (string $sql) use ($bp) { return (float)$bp->query($sql)->fetchColumn(); };
            $projBal = $q("SELECT COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END), 0) FROM cb_ledger WHERE project = 'dishnet'
                           AND status = 'approved' AND (currency = 'USD' OR currency IS NULL OR currency = '') AND NOT (amount = 0 AND sr = '')");
            $recentIn = $q("SELECT COUNT(*) FROM cb_ledger WHERE project = 'dishnet' AND direction = 'in' AND status = 'approved'
                            AND date >= " . $bp->quote(date('Y-m-d', strtotime('-7 days'))));
            $pendDisb = $q("SELECT COUNT(*) FROM cb_ledger WHERE project = 'dishnet' AND validation_status = 'pending' AND direction = 'out'");
            is_($projBal < 0 && t3_two_rules($bp, 'USD', 'USD', 'dishnet')[0] > 0 && $recentIn == 0 && $pendDisb > 0,
                "[{$tag}] control: on dishnet the approved balance is below zero, today's cash in hand above it, with no receipt in seven days and a pending disbursement");
        }
        $bp = null;

        // Two sandboxes, alive together, the same book copied into each, the same account signed in on each.
        $cfg = $B['cfg'] + ['timezone' => $B['tz']];
        $old = SjSandbox::start($base, $cfg, 't3o' . strtolower($tag));
        $new = SjSandbox::start($root, $cfg, 't3n' . strtolower($tag));
        $bp = new \PDO('sqlite:' . $book);
        $nBook = 0;
        foreach (['cb_accounts', 'cb_ledger', 'fin_audit'] as $t) $nBook += (int)$bp->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
        $bp = null;
        $nOld = t3_copy_book($book, $old->data . '/plugin.sqlite3');
        $nNew = t3_copy_book($book, $new->data . '/plugin.sqlite3');
        is_($nBook > 0 && $nOld === $nBook && $nNew === $nBook, "[{$tag}] the book's {$nBook} rows (accounts, ledger, audit) are copied into a sandbox of each tree",
            "{$nOld} / {$nNew}");
        foreach ([$old, $new] as $s) {
            $s->staff('admin', ['name' => T3_ADMIN, 'email' => 'rh-admin@example.test', 'role' => 'admin', 'is_admin' => true, 'phone' => $B['phone']]);
            $s->login('admin', 'rh-admin@example.test', 'sj-password-1');
            file_put_contents($s->plug . '/__p3b_t3_read.php', T3_READER);
        }

        $views = array_merge([''], $curs);
        $out   = [];   // label => [getter, is a page]
        foreach (['dishnet', '4g'] as $p) {
            foreach ($views as $v) {
                $qs = 'page=dashboard&tab=cashbook&cb_proj=' . $p . '&cb_view=ledger' . ($v !== '' ? '&cb_curr=' . $v : '');
                $out["Cashbook ledger, {$p}, " . ($v !== '' ? $v : 'All') . ' view'] = [function (SjSandbox $s) use ($qs) { return $s->page('admin', $qs); }, true];
            }
            foreach (['alerts', 'summary', 'pending'] as $cv) {
                $qs = 'page=dashboard&tab=cashbook&cb_proj=' . $p . '&cb_view=' . $cv;
                $out["Cashbook {$cv}, {$p}"] = [function (SjSandbox $s) use ($qs) { return $s->page('admin', $qs); }, true];
            }
            foreach ($views as $v) {
                $qs = 'page=dashboard&tab=cashbook&cb_export=csv&cb_proj=' . $p . ($v !== '' ? '&cb_curr=' . $v : '');
                $out["CSV export, {$p}, " . ($v !== '' ? $v : 'ALL')] = [function (SjSandbox $s) use ($qs) { return $s->page('admin', $qs); }, false];
            }
        }
        foreach (['the SSP Cashbook' => 'tab=ssp_cashbook', 'the landing page' => 'tab=accounts_dashboard', 'the CEO page' => 'tab=ceo_dashboard'] as $lbl => $t) {
            $out["page {$lbl}, as admin"] = [function (SjSandbox $s) use ($t) { return $s->page('admin', 'page=dashboard&' . $t); }, true];
        }
        foreach ($views as $v) {
            $out['API cashbook_entries, ' . ($v !== '' ? $v : 'All')] = [function (SjSandbox $s) use ($v) {
                $r = $s->api('admin', 'GET', 'cashbook_entries', null, '&limit=500' . ($v !== '' ? '&currency=' . $v : '')); return $r[0] . ' ' . $r[1];
            }, false];
        }
        foreach ($curs as $c) foreach (['dishnet', '4g'] as $p) {
            $out["API cashbook_ledger, {$c}, {$p}"] = [function (SjSandbox $s) use ($c, $p) {
                $r = $s->api('admin', 'GET', 'cashbook_ledger', null, '&currency=' . $c . '&project=' . $p); return $r[0] . ' ' . $r[1];
            }, false];
        }
        $out['the evening WhatsApp (cron/cashbook_summary.php): its texts and its own output'] = [function (SjSandbox $s) {
            $n0 = count($s->texts());
            [$rc, $o] = $s->run('cron/cashbook_summary.php');
            $sent = array_map(function ($t) { return [$t['instance'] ?? '', $t['number'] ?? '', $t['text'] ?? '']; }, array_slice($s->texts(), $n0));
            return json_encode(['rc' => $rc, 'out' => $o, 'sent' => $sent], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }, false];
        $out['cashInHand() and getEntries(), read directly through the tree\'s library'] = [function (SjSandbox $s) use ($curs) {
            [$rc, $o] = $s->run('__p3b_t3_read.php', [json_encode($curs)]);
            return $rc . "\n" . (string)preg_replace('/^RULE .*$/m', '', $o);
        }, false];

        $outside = []; $sameStmt = 0;
        foreach ($out as $lbl => [$get, $page]) {
            [$a, $b, $inMinute] = t3_pair($get, $old, $new, $page);
            if (!$inMinute) { is_(false, "[{$tag}] {$lbl}: read from both trees within one minute"); continue; }
            [$a, $b] = t3_same_statement($a, $b, $old, $new, $sameStmt);
            is_($a === $b, "[{$tag}] {$lbl} (" . strlen($b) . ' bytes)', $a === $b ? '' : t3_where($a, $b));
            $outside[$lbl] = $b;
        }

        // tools/cash_in_hand.php: the banner and the rule line against fixed text, everything else byte for byte
        if ($sameStmt > 0) echo "  note [{$tag}] {$sameStmt} page(s) failed on both trees at the same statement; only its line number was normalised\n";
        // Its error stream apart: ConfigVault's notices go there (error_log), and where they fall among the printed lines
        // follows when the tool first loads the configuration, which 5.18.95 does before its banner to choose the rule line.
        [$ta, $tb, $inMinute] = t3_pair(function (SjSandbox $s) { return t3_run_split($s, 'tools/cash_in_hand.php'); }, $old, $new, false);
        is_($inMinute, "[{$tag}] tools/cash_in_hand.php: read from both trees within one minute");
        [$ta, $errA] = array_pad(explode(T3_STDERR, $ta, 2), 2, '');
        [$tb, $errB] = array_pad(explode(T3_STDERR, $tb, 2), 2, '');
        is_($errA === $errB, "[{$tag}] tools/cash_in_hand.php, its error stream (" . strlen($errB) . ' bytes)', $errA === $errB ? '' : t3_where($errA, $errB));
        $la = explode("\n", $ta); $lb = explode("\n", $tb);
        $bannerA = array_values(preg_grep('/^== cash in hand /', $la)); $bannerB = array_values(preg_grep('/^== cash in hand /', $lb));
        $ruleA   = array_values(preg_grep('/^  rule /', $la));         $ruleB   = array_values(preg_grep('/^  rule /', $lb));
        is_($bannerA === [T3_OLD_BANNER] && $ruleA === [], "[{$tag}] control: f4b4c96's tool prints its own banner (5.18.71) and no rule line",
            t3_where(implode("\n", $bannerA) . '|' . implode("\n", $ruleA), T3_OLD_BANNER . '|'));
        is_($bannerB === [T3_NEW_BANNER], "[{$tag}] the tool's banner reads \"" . T3_NEW_BANNER . '"', t3_where(implode("\n", $bannerB), T3_NEW_BANNER));
        is_($ruleB === [T3_NEW_RULE], "[{$tag}] the tool's rule line reads \"" . trim(T3_NEW_RULE) . '"', t3_where(implode("\n", $ruleB), T3_NEW_RULE));
        $restA = implode("\n", array_values(array_filter($la, function ($l) { return strpos($l, '== cash in hand ') !== 0 && strpos($l, '  rule ') !== 0; })));
        $restB = implode("\n", array_values(array_filter($lb, function ($l) { return strpos($l, '== cash in hand ') !== 0 && strpos($l, '  rule ') !== 0; })));
        is_($restA === $restB, "[{$tag}] tools/cash_in_hand.php, every other line (" . strlen($restB) . ' bytes)', $restA === $restB ? '' : t3_where($restA, $restB));
        is_(in_array(T3_OLD_SENT, $la, true) && in_array(T3_OLD_SENT, $lb, true), "[{$tag}] the tool's sentence is the one before 5.18.95, on both trees");

        // What the comparison stood on: real pages, the rule off on this tree, the per-run values found where expected
        [, $rule] = $new->run('__p3b_t3_read.php', [json_encode($curs)]);
        is_(preg_match('/^RULE (false|absent)$/m', $rule) === 1, "[{$tag}] cashOnlyRuleInForce() is false on this book (absent: the tree predates it)",
            (string)(preg_match('/^RULE (\w+)$/m', $rule, $rm) ? $rm[1] : 'no answer'));
        $led = $outside['Cashbook ledger, dishnet, All view'] ?? '';
        is_(strlen($led) > 50000 && strpos($led, 'RH-Client One payment') !== false && strpos($led, 'RH row on an account that does not exist') !== false,
            "[{$tag}] control: the Cashbook is the real page, listing the book's rows");
        is_(substr_count($led, 'v<version>') >= 1, "[{$tag}] control: the version label — normalised, and nothing else of the release — is on the page");
        $ceo = $outside['page the CEO page, as admin'] ?? '';
        is_(strpos($ceo, '<long-date>') !== false && strpos($ceo, '<time>') !== false && strpos($ceo, 'CEO Dashboard') !== false,
            "[{$tag}] control: the CEO page is the real page, and its printed date and time were normalised");
        $csv = $outside["CSV export, dishnet, ALL"] ?? '';
        is_(substr_count($csv, "\n") > 10 && strpos($csv, 'RH-Client One payment') !== false, "[{$tag}] control: the CSV export lists the book's rows");
        $wa = json_decode($outside['the evening WhatsApp (cron/cashbook_summary.php): its texts and its own output'] ?? '', true);
        is_(is_array($wa) && count($wa['sent'] ?? []) === 1 && strpos((string)($wa['sent'][0][2] ?? ''), 'DishNet Cashbook') !== false,
            "[{$tag}] control: the evening WhatsApp was sent, once, to the admin");
        $dr = $outside['cashInHand() and getEntries(), read directly through the tree\'s library'] ?? '';
        is_(strpos($dr, '"cash_in_hand"') !== false && preg_match('/"' . $base0 . '\|dishnet":-?\d/', $dr) === 1, "[{$tag}] control: the library was read directly");
    } catch (\Throwable $e) {
        is_(false, "[{$tag}] the comparison ran to its end without an error", get_class($e) . ': ' . substr($e->getMessage(), 0, 200));
    }
    foreach ([$old ?? null, $new ?? null] as $s) {
        if (!$s instanceof SjSandbox) continue;
        $evo = sys_get_temp_dir() . '/fake_evo_state_' . md5((string)realpath($s->root . '/tests/fixtures/fake_evo_server.php')
             . (string)parse_url($s->evo, PHP_URL_PORT)) . '.json';
        $s->stop();
        @unlink($evo);
    }
    $old = $new = null;
}

// ── 4. Weakened copies, the control on the control, and the control on the controls ──────────────────────────────────
if ($withMutants) {
    echo "\n4. Weakened copies of the code must each fail this test; the unchanged copy passes\n";
    $child = function (string $tree) use ($base): array {
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --root=' . escapeshellarg($tree) . ' --base=' . escapeshellarg($base)
             . ' --no-mutants 2>&1', $out, $rc);
        $fails = array_values(array_filter($out, function ($l) { return strpos($l, '  FAIL ') === 0; }));
        $books = [];
        foreach ($fails as $f) if (preg_match('/^  FAIL \[(SS|SN|UP)\]/', $f, $m)) $books[$m[1]] = ($books[$m[1]] ?? 0) + 1;
        $tally = preg_match('/^(\d+) passed, (\d+) failed, (\d+) skipped$/', (string)end($out), $tm) ? [(int)$tm[1], (int)$tm[2], (int)$tm[3]] : null;
        return [$rc, $fails, $tally, $books];
    };
    $report = function (array $fails, ?array $tally, array $books): void {
        echo '         ' . count($fails) . ' assertion(s) failed' . ($tally ? " ({$tally[0]} passed)" : '')
           . ($books ? '; by book: ' . implode(', ', array_map(function ($k, $v) { return "{$k} {$v}"; }, array_keys($books), $books)) : '')
           . ($fails ? '; first: ' . trim(substr($fails[0], 7, 100)) : '') . "\n";
    };
    $CB = 'lib/CashbookService.php'; $PAGE = 'tabs/accounts/cashbook.php'; $CEO = 'tabs/admin/ceo_dashboard.php'; $TOOL = 'tools/cash_in_hand.php';
    $GATE = "\$this->cashRuleCache = StaffJobsGate::applies(\$cfg, \$this->dataDir) && !dn_ssp_selectable(\$cfg);";
    // [label, the books that must catch it (for the M rows, the SPEC's "caught by"), [file, anchor, replacement]...]
    $MUTANTS = [
        ['M10: the tenant half removed', ['SN'], [[$CB, $GATE, "\$this->cashRuleCache = !dn_ssp_selectable(\$cfg);"]]],
        ['M11: the SSP half removed', ['UP'], [[$CB, $GATE, "\$this->cashRuleCache = StaffJobsGate::applies(\$cfg, \$this->dataDir);"]]],
        ['M22 (the Uganda-with-SSP half): the tool\'s rule line chosen by $tenant instead of the gate', ['UP'], [[$TOOL,
            "\$ruleA   = \$cb->cashOnlyRuleInForce();", "\$ruleA   = \$tenant === 'uganda';"]]],
        ['M24a: a South Sudan branch touched — a CEO label', ['SS'], [[$CEO,
            '<div class="cd-kl">USD Balance</div><div class="cd-ks">Running total</div>', '<div class="cd-kl">USD Balance</div><div class="cd-ks">Running balance</div>']]],
        ['M24b: a South Sudan branch touched — an alert', ['SS'], [[$PAGE,
            "'title'=>'Cash balance is NEGATIVE: ' . dn_cur(\$config)", "'title'=>'Cash balance is negative: ' . dn_cur(\$config)"]]],
        ['M27a: Alert 2 gated on "no SSP" alone', ['SN'], [[$PAGE,
            "if (\$_cbRuleA) {\n    foreach (\$_cbCih as \$_cbCihCur => \$_cbCihBal) {",
            "if (!\$_cbSSP) {\n    foreach (array_map(function (\$c) use (\$cb, \$proj) { return \$cb->cashInHand(\$c, \$proj); }, array_combine(\$_cbCurrs, \$_cbCurrs)) as \$_cbCihCur => \$_cbCihBal) {"]]],
        ['M27b: Alert 6 gated on "no SSP" alone', ['SN'], [[$PAGE,
            "if ((\$_cbRuleA ? (\$_cbCih[\$_cbBase] ?? 0) : \$projBal) > 0", "if ((!\$_cbSSP ? \$cb->cashInHand(\$_cbBase, \$proj) : \$projBal) > 0"]]],
        ['M27b2: Alert 6 gated on "no SSP" alone, reading the cash-in-hand array the rule fills', ['SN'], [[$PAGE,
            "if ((\$_cbRuleA ? (\$_cbCih[\$_cbBase] ?? 0) : \$projBal) > 0", "if ((!\$_cbSSP ? (\$_cbCih[\$_cbBase] ?? 0) : \$projBal) > 0"]]],
        ['M27c: the Add Entry pill gated on "no SSP" alone', ['SN'], [[$PAGE,
            "<?php if (\$_cbRuleA): ?><?= htmlspecialchars(\$_cbBase) ?> <?php echo number_format(\$_cbCih[\$_cbBase] ?? 0, 2); ?>",
            "<?php if (!\$_cbSSP): ?><?= htmlspecialchars(\$_cbBase) ?> <?php echo number_format(\$cb->cashInHand(\$_cbBase, \$proj), 2); ?>"]]],
        ['M27d: the hero\'s count gated on "no SSP" alone', ['SN'], [[$PAGE,
            "<?= \$_cbRuleA ? ' in the book' : '' ?>", "<?= !\$_cbSSP ? ' in the book' : '' ?>"]]],
        ['M27e: the CEO tiles, heading and label gated on "no SSP" alone', ['SN'], [[$CEO,
            "\$_ceoRuleA = \$_ceoCb->cashOnlyRuleInForce();", "\$_ceoRuleA = true;"]]],
        ['M27f: the whole Cashbook page gated on "no SSP" alone', ['SN'], [[$PAGE,
            "\$_cbRuleA = !\$_cbSSP && \$cb->cashOnlyRuleInForce();", "\$_cbRuleA = !\$_cbSSP;"]]],
        // The line-number normalisation must not hide a failure: the Summary page fails on both trees for the Uganda
        // tenant with SSP, at the max() of an empty list in $maxIn; this copy fails on exactly that condition, at a
        // statement of its own just before it.
        ['control: a page that fails at another statement on this tree is not normalised away', ['UP'], [[$PAGE,
            "\$maxIn=max(1,max(array_column(\$summary['in']??[[1]],'total')));",
            "if (array_column(\$summary['in'] ?? [[1]], 'total') === []) throw new \\RuntimeException('RH weakened copy');\n\$maxIn=max(1,max(array_column(\$summary['in']??[[1]],'total')));"]]],
        ['M28: the tool\'s :34 sentence reworded on every tenant', ['SS'], [[$TOOL, "if (\$ruleA) {\n    echo \"\\n  the ledger's cash in hand",
            "if (true) {\n    echo \"\\n  the ledger's cash in hand"]]],
    ];

    // A copy runs sections 1–3 and the two closing checks: this run's passes so far, plus those two.
    $expectPass = $pass + 2;
    [$tmp, $n] = sj_weakened_copy($lib, $CB, 'public const CASH_IN_HAND_KINDS', 'public const CASH_IN_HAND_KINDS');
    if ($n === 1) {
        [$rc, $fails, $tally, $books] = $child($tmp);
        is_($rc === 0 && $fails === [] && $tally === [$expectPass, 0, 0],
            "the unchanged copy passes: {$expectPass} passed, 0 failed, as this run", 'exit ' . $rc . ' tally ' . json_encode($tally) . ' ' . ($fails[0] ?? ''));
    } else {
        is_(false, 'the unchanged copy: its anchor occurs once', "found {$n} times");
    }
    exec('rm -rf ' . escapeshellarg($tmp));

    // The control on the control: the rule forced true must differ on each of the three books.
    [$tmp, $n] = sj_weakened_copy($lib, $CB, $GATE, "\$this->cashRuleCache = true;");
    if ($n === 1) {
        [$rc, $fails, $tally, $books] = $child($tmp);
        is_($rc !== 0 && isset($books['SS'], $books['SN'], $books['UP']),
            'M9 and the control on the control: the rule forced true is caught, and differs on each of the three books', 'exit ' . $rc . ' ' . json_encode($books));
        $report($fails, $tally, $books);
    } else {
        is_(false, 'M9: its anchor occurs once', "found {$n} times");
    }
    exec('rm -rf ' . escapeshellarg($tmp));

    foreach ($MUTANTS as [$label, $expect, $edits]) {
        [$rel0, $old0, $new0] = $edits[0];
        [$tmp, $n] = sj_weakened_copy($lib, $rel0, $old0, $new0);
        $held = $n === 1;
        foreach (array_slice($edits, 1) as [$rel, $o, $w]) {               // any further edit, its anchor counted the same way
            $src = (string)@file_get_contents($tmp . '/' . $rel);
            if (substr_count($src, $o) !== 1) { $held = false; break; }
            file_put_contents($tmp . '/' . $rel, str_replace($o, $w, $src));
        }
        foreach ($edits as [$rel, $o, $w]) {   // the copy holds each change, and each anchor is left only where its change carries it
            $src = (string)@file_get_contents($tmp . '/' . $rel);
            if (strpos($src, $w) === false || substr_count($src, $o) !== substr_count($w, $o)) $held = false;
        }
        if (!$held) {
            is_(false, "weakened copy \"{$label}\": each anchor occurs once and the copy holds every change", "first anchor found {$n} times");
            exec('rm -rf ' . escapeshellarg($tmp));
            continue;
        }
        [$rc, $fails, $tally, $books] = $child($tmp);
        $by = array_intersect($expect, array_keys($books));
        is_($rc !== 0 && $fails !== [] && $by === $expect, "caught: {$label} — by book " . implode(', ', $expect) . ', the book that must catch it',
            'exit ' . $rc . ', ' . count($fails) . ' failure(s), by ' . json_encode($books));
        $report($fails, $tally, $books);
        exec('rm -rf ' . escapeshellarg($tmp));
    }

    // The control on the controls: f4b4c96's own code fails exactly where the SPEC says it must — the tool's banner and
    // rule line, on each book — and every comparison of its pages, exports, texts and figures with itself holds.
    [$rc, $fails, $tally, $books] = $child($base);
    $fixed = array_values(array_filter($fails, function ($l) { return preg_match("/^  FAIL \[(SS|SN|UP)\] the tool's (banner|rule line) reads /", $l) === 1; }));
    is_($rc !== 0 && count($fails) === 6 && $fixed === $fails && $books === ['SS' => 2, 'SN' => 2, 'UP' => 2],
        "control on the controls: f4b4c96's code fails this test at exactly the six fixed-text checks (banner and rule line × 3 books), and nowhere else",
        'exit ' . $rc . ', ' . count($fails) . ' failure(s), ' . count($fixed) . ' of them fixed-text, by ' . json_encode($books));
    $report($fails, $tally, $books);
}

// This run wrote no vault but its own and the sandboxes'.
clearstatcache();
$defAfter = is_file($defVault) ? [hash_file('sha256', $defVault), filemtime($defVault)] : null;
is_($defAfter === $defBefore, 'the default vault beside this checkout is untouched (' . ($defBefore === null ? 'still absent' : 'same bytes and mtime') . ')');
is_(strpos(sys_get_temp_dir(), $OWN) === 0, 'every temporary file of this run lives in its own folder, removed when it ends', sys_get_temp_dir());
t3_done();
