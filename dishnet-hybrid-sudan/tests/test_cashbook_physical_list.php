<?php
declare(strict_types=1);
/**
 * test_cashbook_physical_list.php — 5.18.98 (SPEC-separate-v2 §2.9): on a Uganda book (rule A: the Uganda tenant and a
 * book without SSP) the Cashbook's list, its pager and its export hold physical cash only — entries with no account,
 * or on a cash or mobile-money account — and every other listed entry is on the read-only "Bank & other accounts" tab
 * and its own export, on exactly one of its buttons. Nothing is deleted, moved or written: the split is a read. Wherever
 * rule A is off, every page and export is 5.18.97's byte for byte.
 *
 * The fixture plants every shape of SPEC-separate-v2 §2.1's table through the plugin's own writers (raw SQL only for
 * what no writer makes), with invented amounts and names. Each row's side and button are declared WHEN IT IS WRITTEN,
 * so the oracle never reads the list predicate; a positive control asserts that every planted shape is present at
 * least once, so no section can pass over zero rows.
 *
 *   A  Partition: per project × All/UGX/USD × filter set, ids(cash) ⊎ ids(not cash) = ids(no key), disjoint, in the
 *      no-key order once merged, each side the rows declared on it; countFiltered() per side; the buttons partition
 *      the not-cash rows; under the cap; blank-currency rows on no UGX-only list
 *   B  Shapes: each planted row on its declared side and button; awaiting-approval, rejected and voided cash rows on
 *      the cash side; the ghost and voided_reconcile rows on neither
 *   C  Balance: a cash-side row's running balance is the no-key list's; a not-cash row has none; both equal the
 *      fixture's own running sum
 *   D  Paging: per side and per button, the pages joined are the whole list; the page count and the last page; and
 *      under a search, and a search with dates, the pages joined are the fixture's own list
 *   E  The gate: on South Sudan, a non-Uganda book without SSP and a Uganda book with SSP, buildWhere() is the same
 *      with and without the keys. E2: the page's configuration says SSP while the service's rule is on — no key, no
 *      tab, the list and the CSV as 5.18.97's
 *   F  No DDL: on an estate without cb_accounts, sqlite_master (read from a second connection) is the same after the
 *      Ledger, the tab, the search hint, both exports and every service read; only rows with no account are cash;
 *      only "Other accounts" is offered
 *   G  A hand-made cb_accounts with a NULL id: every listed row on exactly one side; bank accounts whose id no button
 *      can select (negative, text, real) under Other accounts, and an id held by two records one button: the buttons
 *      still partition the not-cash rows
 *   H  Agreement with cash in hand: {listed ∧ cash ∧ approved} = {listed ∧ admitted by cashOnlyWhere()}
 *   I  Other callers, 5.18.97's tree against this one on one copied Uganda book: the evening WhatsApp, the
 *      maintenance and daily-report reads, four API actions, the landing and CEO pages, tools/cash_in_hand.php; T1
 *   J  The pages, as an admin, an accountant, a promoted accountant and a field agent; the admin with the ECO Bank
 *      switch off
 *   K  The exports: the cash export's rows, Balance cells and Entry state; the tab's export per button, every cell of
 *      every row, with no cb_proj, and refused to a record with no role; and where the export's own settings have no
 *      SSP but the files' rule is off (the reverse of E2), 5.18.97's export, no Entry state
 *   L  South Sudan and the other rule-off books: the new tab, a search, a button and the new export as 5.18.97's
 *   M  The boundary: what 5.18.98 changes and adds against the 5.18.97 release commit (5.18.99: measured on the 5.18.98
 *      release commit ce7ac388, from Git; this tree is then held to it — every file 5.18.98 added or changed is
 *      ce7ac388's, byte for byte, and manifest.json differs from ce7ac388's by its version line only)
 *
 * The Git baseline is ec0accd4 (the 5.18.97 release). When Git cannot supply it, the comparisons that need it are
 * reported as skips, never as passes.
 *
 *   php test_cashbook_physical_list.php [--root=DIR] [--base=DIR]
 *
 * --root is the tree under test (default: this one): its library is read in process and its pages are served. --base is
 * a 5.18.97 tree already taken from Git.
 */

// ── 0. A temporary folder of its own: this process runs again inside it, so that every sandbox, book and Git tree it
//       makes (all under sys_get_temp_dir(), which a running process cannot move) lands there, and goes.
if ((string)getenv('PL98_TMP') === '') {
    $own = sys_get_temp_dir() . '/pl98-' . getmypid() . '-' . bin2hex(random_bytes(3));
    mkdir($own, 0700, true);
    $cmd = 'PL98_TMP=' . escapeshellarg($own) . ' TMPDIR=' . escapeshellarg($own) . ' '
         . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__);
    foreach (array_slice($argv, 1) as $a) $cmd .= ' ' . escapeshellarg((string)$a);
    passthru($cmd, $rc);
    exec('rm -rf ' . escapeshellarg($own));
    exit((int)$rc);
}
$OWN = rtrim((string)getenv('PL98_TMP'), '/');
$RUN = $OWN . '/run-' . getmypid() . '-' . bin2hex(random_bytes(2));
mkdir($RUN, 0700, true);

$opt  = getopt('', ['root:', 'base:']);
$lib  = dirname(__DIR__);                                                   // this checkout: its fixtures and its Git
$root = isset($opt['root']) ? rtrim((string)$opt['root'], '/') : $lib;     // the tree under test

$pass = 0; $fail = 0; $skip = 0;
function is_(bool $c, string $m, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; }
    else    { $fail++; echo "  FAIL {$m}" . ($d !== '' ? "\n       " . substr($d, 0, 700) : '') . "\n"; }
}
function t(string $m, $got, $want): void {
    is_($got === $want, $m, 'got  ' . substr((string)json_encode($got, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 330)
        . "\n       want " . substr((string)json_encode($want, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 330));
}
function pl_skip(string $what, string $why): void {
    global $skip;
    $skip++;
    echo "  skip {$what}: {$why} (a skip, not a pass)\n";
}
function pl_threw(string $where, \Throwable $e): void {
    is_(false, "{$where}: ran to its end without an error", get_class($e) . ': ' . substr($e->getMessage(), 0, 300) . ' @' . basename($e->getFile()) . ':' . $e->getLine());
}
function pl_done(): void {
    global $pass, $fail, $skip;
    if ($skip > 0) echo "\n  ({$skip} skip(s): reported, never counted as passes)\n";
    printf("\n%d passed, %d failed\n", $pass, $fail);
    exit($fail === 0 ? 0 : 1);
}
/** Where two strings first differ — never what they say. */
function pl_where(string $a, string $b): string
{
    $n = min(strlen($a), strlen($b)); $i = 0;
    while ($i < $n && $a[$i] === $b[$i]) $i++;
    return sprintf('first difference at byte %d (line %d); lengths %d / %d', $i, substr_count(substr($a, 0, $i), "\n") + 1, strlen($a), strlen($b));
}

// A checkout holding a configuration or a book in its data folder would hand its settings to every book below.
foreach (array_unique([$lib, $root]) as $t0) {
    if (is_file($t0 . '/data/config.json') || is_file($t0 . '/data/kyc_config.json') || is_file($t0 . '/data/plugin.sqlite3')) {
        is_(false, 'this test runs from a code copy whose data folder holds no configuration or book', $t0 . '/data');
        pl_done();
    }
}

putenv('DN_VAULT_FILE=' . $RUN . '/vault-boot.json');
ini_set('error_log', $RUN . '/php-error.log');
date_default_timezone_set('Africa/Kampala');
$defVault  = dirname($lib) . '/.dishnet-sudan.vault.json';                  // ConfigVault::path()'s default for this tree
clearstatcache();
$defBefore = is_file($defVault) ? [hash_file('sha256', $defVault), filemtime($defVault)] : null;

require_once $lib . '/tests/fixtures/staff_jobs_scenario.php';             // SjSandbox, sj_baseline_tree()
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/currency.php';
require_once $root . '/lib/CashbookService.php';
if (is_file($root . '/lib/CashbookNonCashView.php')) require_once $root . '/lib/CashbookNonCashView.php';

const PL_BASE   = 'ec0accd4';                       // the 5.18.97 release commit
const PL_REL    = 'ce7ac388';                       // 5.18.99: the 5.18.98 release commit, which M's boundary is measured on
const PL_ADMIN  = 'RH-Admin One';
const PL_FIELD  = 'RH-Field One';                    // a field agent (role sales)
const PL_FIELD2 = 'RH-Field Two';                    // a field accountant
const PL_NOTE   = 'Physical cash only: cash, mobile money and entries with no account.';
const PL_TABLBL = "\u{1F3E6} Bank & other accounts";
const PL_SENT   = 'An administrator can see Ecobank\'s own balances under ECO Bank.';
const PL_LINK   = 'Ecobank\'s own balances, UGX and USD each on its own: open ECO Bank &rarr;';
const PL_CASH_KINDS = ['cash', 'momo'];              // written here, not read from the class: the oracle stays independent
const PL_STATES = ['approved' => 'approved', 'pending_approval' => 'awaiting approval', 'rejected' => 'rejected', 'voided' => 'voided'];
const PL_NC_HEADER = ['SR No.', 'Date', 'Account', 'Account kind', 'Type', 'Particulars', 'Category', 'Person', 'Currency',
    'Received', 'Payment', 'Ref', 'Entry state', 'Source'];

$UG = ['tenant_profile' => 'uganda', 'timezone' => 'Africa/Kampala', 'currency_symbol' => 'UGX', 'currency_code' => 'UGX',
       'cashbook_base_currency' => 'UGX', 'cashbook_currencies' => 'UGX,USD', 'bank_screen_enabled' => '1'];
$PROJ  = ['dishnet', '4g', 'bluecard'];
$VIEWS = ['' => 'All', 'UGX' => 'UGX', 'USD' => 'USD'];

/** A row's own account id as stored, for the oracle: NULL and the integer 0 are "no account"; text is never cast. */
function pl_no_account(array $r): bool
{
    return $r['acc_type'] === 'null' || ($r['acc_type'] === 'integer' && (int)$r['account_id'] === 0);
}

// ═══ The books ═══════════════════════════════════════════════════════════════════════════════════════════════════

/**
 * The Uganda estate U: every shape of SPEC-separate-v2 §2.1's table. Its cb_ledger is the legacy shape whose account_id
 * is nullable (P3b E1: initTable() only adds missing columns), so a NULL id can be planted beside the integer 0. Each
 * write is followed by reading back the ids it made and giving each its declared side and button, in the writer's own
 * order of legs. Returns [store path, declarations id => [shape, side, chip], accounts id => [name, currency, kind,
 * active], ok].
 */
function pl_build_u(string $dir, array $cfg): array
{
    mkdir($dir, 0700, true);
    putenv('DN_VAULT_FILE=' . $dir . '-vault.json');
    $store = SqliteStore::create($dir);                                    // first: a fresh folder's *.json is imported and renamed
    file_put_contents($dir . '/kyc_config.json', json_encode($cfg + ['data_dir' => $dir]));
    $pdo   = $store->getPdo();
    $pdo->exec('DROP TABLE IF EXISTS cb_ledger');
    $pdo->exec("CREATE TABLE cb_ledger (id INTEGER PRIMARY KEY AUTOINCREMENT, sr TEXT NOT NULL DEFAULT '', date TEXT NOT NULL DEFAULT '',
                direction TEXT NOT NULL DEFAULT 'in', amount REAL NOT NULL DEFAULT 0, currency TEXT NOT NULL DEFAULT 'USD',
                status TEXT NOT NULL DEFAULT 'approved', project TEXT NOT NULL DEFAULT 'dishnet', account_id INTEGER)");
    $cb  = new CashbookService($store, $dir);
    $me  = ['name' => PL_ADMIN];
    $ok  = [];
    $d   = function (int $n): string { return date('Y-m-d', strtotime("-{$n} days")); };
    $A   = [];                                                               // id => [name, currency, kind, active]
    $seed = $cb->seedStandardAccounts();
    $ok[] = !empty($seed['ok']);
    $byName = [];
    foreach ($cb->accounts() as $a) $byName[(string)$a['name']] = (int)$a['id'];
    foreach ([['Ecobank Uganda – UGX', 'UGX', 'bank'], ['Ecobank Uganda – USD', 'USD', 'bank'], ['Cash – Uganda', 'UGX', 'cash'],
              ['MTN Mobile Money', 'UGX', 'momo'], ['Airtel Money', 'UGX', 'momo'], ['Director/Shareholder Funding', 'UGX', 'director']] as [$n, $c, $k]) {
        $A[$byName[$n] ?? -1] = [$n, $c, $k, true];
    }
    $mk = function (string $name, string $cur, string $kind) use ($cb, &$ok, &$A): int {
        $r = $cb->addAccount($name, $cur, $kind); $ok[] = !empty($r['ok']);
        $id = (int)($r['id'] ?? 0); $A[$id] = [$name, $cur, $kind, true]; return $id;
    };
    $bankU = $byName['Ecobank Uganda – UGX'] ?? 0; $bankD = $byName['Ecobank Uganda – USD'] ?? 0; $cashU = $byName['Cash – Uganda'] ?? 0;
    $mtn   = $byName['MTN Mobile Money'] ?? 0;     $dirU  = $byName['Director/Shareholder Funding'] ?? 0;
    $cashD    = $mk('RH-Cash – USD', 'USD', 'cash');                         // id 7: the real account beside the text id '7abc'
    $oldCash  = $mk('RH-Old Cash – UGX', 'UGX', 'cash');
    $spare    = $mk('RH-Spare Bank 0123456789 – UGX', 'UGX', 'bank');        // a long number in the name: shown masked
    $oldBank  = $mk('RH-Old Bank – UGX', 'UGX', 'bank');                     // made inactive below; it holds rows
    $unused   = $mk('RH-Unused Old Bank – UGX', 'UGX', 'bank');              // made inactive below; it holds none
    $stock    = $mk('RH-Stock – UGX', 'UGX', 'inventory');
    $asset    = $mk('RH-Equipment – UGX', 'UGX', 'asset');
    $supplier = $mk('RH-Supplier – UGX', 'UGX', 'payable');
    $equity   = $mk('RH-Share Capital – UGX', 'UGX', 'equity');
    $debtor   = $mk('RH-Debtors – UGX', 'UGX', 'receivable');
    $kes      = $mk('RH-Kenya Bank – KES', 'KES', 'bank');                   // a currency the book does not keep
    // 'Bank' with a capital: addAccount() refuses an unknown kind, so the row is written as a hand-made one would be
    $pdo->exec("INSERT INTO cb_accounts (name, currency, kind) VALUES ('RH-Capital-B Bank – UGX', 'UGX', 'Bank')");
    $capB = (int)$pdo->lastInsertId();
    $A[$capB] = ['RH-Capital-B Bank – UGX', 'UGX', 'Bank', true];

    $C = ['side' => 'cash', 'chip' => null];
    $N = function ($chip, string $shape = '') { return ['side' => 'noncash', 'chip' => $chip] + ($shape !== '' ? ['shape' => $shape] : []); };
    $X = ['side' => null, 'chip' => null];
    $decl = []; $seen = 0;
    $plant = function (string $shape, array $legs, callable $write) use ($pdo, &$decl, &$seen, &$ok): void {
        $write();
        $ids = array_map('intval', $pdo->query('SELECT id FROM cb_ledger WHERE id > ' . (int)$seen . ' ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));
        if (count($ids) !== count($legs)) $ok[] = false;
        foreach ($ids as $i => $id) {
            $decl[$id] = ($legs[$i] ?? ['side' => '?', 'chip' => null]) + ['shape' => $shape];
            $seen = max($seen, $id);
        }
    };
    $entry = function (array $r, bool $approve = true) use ($cb, $me, &$ok): int {
        $x = $cb->addEntry($r + ['validation_status' => 'na', 'project' => 'dishnet', 'currency' => 'UGX', 'category' => 'Receipt'], $me, $approve);
        $ok[] = !empty($x['ok']); return (int)($x['id'] ?? 0);
    };
    $raw = function (array $r) use ($cb): string {
        return $cb->addEntryRaw($r + ['status' => 'approved', 'approved_by' => PL_ADMIN, 'validation_status' => 'na',
            'project' => 'dishnet', 'currency' => 'UGX', 'category' => 'Misc Expense', 'direction' => 'out']);
    };
    $set = function (string $sql, array $p) use ($pdo): void { $pdo->prepare($sql)->execute($p); };
    $one = function (array $r) use ($raw) { return function () use ($raw, $r) { $raw($r); }; };
    $ob  = function (int $acct, float $amt) use ($cb, $d, &$ok) {
        return function () use ($cb, $d, $acct, $amt, &$ok) { $ok[] = !empty($cb->recordOpeningBalance($acct, $amt, $d(300), 'RH opening', 'RH-OB', PL_ADMIN)['ok']); };
    };

    // ── openings ──
    $plant('opening balance, cash account', [$C], $ob($cashU, 5000));
    $plant('opening balance, bank account', [$N($bankU)], $ob($bankU, 70000));
    $plant('mobile-money account', [$C], $ob($mtn, 1200));
    $plant('opening balance, bank account', [$N($bankD)], $ob($bankD, 2000));
    $plant('cash account in USD (account 7)', [$C], $ob($cashD, 50));
    $plant('inactive cash account', [$C], $ob($oldCash, 250));
    $plant('opening balance, bank account', [$N($spare)], $ob($spare, 3000));
    $plant('inactive bank account', [$N($oldBank)], $ob($oldBank, 400));
    $plant('bank account in a currency the book does not keep', [$N($kes)], $ob($kes, 900));
    // ── blank currency, older than every UGX row ──
    $plant('blank currency, older than every UGX row', [$C], $one(['date' => $d(400), 'direction' => 'in', 'amount' => 70, 'currency' => '',
        'category' => 'Receipt', 'description' => 'RH-U blank-currency receipt, oldest']));
    // ── no account: typed, CRM, webhook, the adjustment, awaiting approval, rejected, voided, NULL ──
    $plant('typed entry, no account', [$C], function () use ($entry, $d) { $entry(['date' => $d(200), 'direction' => 'in', 'amount' => 800, 'description' => 'RH-Client One payment']); });
    $plant('CRM pull, no account', [$C], $one(['date' => $d(199), 'direction' => 'in', 'amount' => 1500, 'category' => 'Receipt',
        'description' => 'RH-Client Two payment (uCRM sync)', 'source' => 'crm_api_sync', 'crm_payment_id' => 9101]));
    $plant('CRM webhook, no account', [$C], $one(['date' => $d(198), 'direction' => 'in', 'amount' => 1250, 'category' => 'Receipt',
        'description' => 'RH-Client Three payment (uCRM webhook)', 'source' => 'crm_webhook', 'crm_payment_id' => 9102]));
    $plant('the adjustment, no account', [$C], $one(['date' => $d(197), 'direction' => 'in', 'amount' => 330, 'category' => 'Adjustment',
        'description' => 'RH-U adjustment as of 12 Sep', 'txn_type' => 'ADJUSTMENT']));
    $plant('awaiting approval, no account', [$C], function () use ($entry, $d) { $entry(['date' => $d(196), 'direction' => 'in', 'amount' => 333, 'description' => 'RH-U receipt awaiting approval'], false); });
    $plant('rejected, no account', [$C], function () use ($entry, $cb, $me, $d) {
        $id = $entry(['date' => $d(195), 'direction' => 'in', 'amount' => 444, 'description' => 'RH-U rejected receipt']);
        $cb->rejectEntry($id, 'RH test rejection', $me);
    });
    $plant('voided, no account', [$C], function () use ($entry, $cb, $d, &$ok) {
        $id = $entry(['date' => $d(194), 'direction' => 'in', 'amount' => 555, 'description' => 'RH-U voided receipt']);
        $ok[] = !empty($cb->voidEntry($id, 'RH test void', PL_ADMIN)['ok']);
    });
    $plant('NULL account id', [$C], function () use ($raw, $set, $d) {
        $sr = $raw(['date' => $d(193), 'direction' => 'in', 'amount' => 120, 'category' => 'Receipt', 'description' => 'RH-U row with a NULL account']);
        $set('UPDATE cb_ledger SET account_id = NULL WHERE sr = ?', [$sr]);
    });
    // ── cash and mobile-money accounts, active or not ──
    $plant('cash account entry', [$C], $one(['date' => $d(190), 'direction' => 'in', 'amount' => 640, 'category' => 'Receipt',
        'description' => 'RH-U cash box receipt', 'account_id' => $cashU]));
    $plant('mobile-money account', [$C], $one(['date' => $d(189), 'direction' => 'out', 'amount' => 90, 'description' => 'RH-U mobile money fee', 'account_id' => $mtn]));
    $plant('inactive cash account', [$C], $one(['date' => $d(188), 'direction' => 'out', 'amount' => 35, 'description' => 'RH-U old cash box spend', 'account_id' => $oldCash]));
    // ── exchanges, transfers, fundings ──
    $plant('money-changer exchange (FXC), both legs', [$C, $C], function () use ($cb, $d, &$ok) {
        $ok[] = !empty($cb->recordCashExchange(100, 3700, 'usd_to_base', $d(180), 'RH-U money changer', 'dishnet', 'RH-Changer', PL_ADMIN)['ok']);
    });
    $plant('field exchange, no account', [$C], $one(['date' => $d(179), 'direction' => 'in', 'amount' => 260, 'category' => 'Exchange',
        'description' => 'RH-U field exchange', 'source' => 'field_exchange']));
    $plant('transfer bank to cash', [$N($bankU, 'bank leg of a transfer'), ['side' => 'cash', 'chip' => null, 'shape' => 'cash leg of a transfer']],
        function () use ($cb, $bankU, $cashU, $d, &$ok) { $ok[] = !empty($cb->recordAccountTransfer($bankU, $cashU, 400, 400, $d(170), 'RH-U bank to cash', '', PL_ADMIN)['ok']); });
    $plant('transfer cash to mobile money, both cash', [$C, $C],
        function () use ($cb, $cashU, $mtn, $d, &$ok) { $ok[] = !empty($cb->recordAccountTransfer($cashU, $mtn, 150, 150, $d(169), 'RH-U cash to mobile money', '', PL_ADMIN)['ok']); });
    $plant('funding into cash', [['side' => 'cash', 'chip' => null, 'shape' => 'cash leg of a funding'], $N('other', 'director leg of a funding')],
        function () use ($cb, $cashU, $dirU, $d, &$ok) { $ok[] = !empty($cb->recordFunding($cashU, $dirU, 600, $d(168), 'RH-U funding into cash', '', PL_ADMIN)['ok']); });
    $plant('funding into the bank', [$N($bankU, 'bank leg of a funding'), $N('other', 'director leg of a funding')],
        function () use ($cb, $bankU, $dirU, $d, &$ok) { $ok[] = !empty($cb->recordFunding($bankU, $dirU, 9000, $d(167), 'RH-U funding into the bank', '', PL_ADMIN)['ok']); });
    $plant('bank to bank, both legs', [$N($bankU), $N($spare)],
        function () use ($cb, $bankU, $spare, $d, &$ok) { $ok[] = !empty($cb->recordAccountTransfer($bankU, $spare, 500, 500, $d(166), 'RH-U bank to bank', '', PL_ADMIN)['ok']); });
    $plant('a voided transfer pair', [$N($bankU, 'voided transfer pair, bank leg'), ['side' => 'cash', 'chip' => null, 'shape' => 'voided transfer pair, cash leg']],
        function () use ($cb, $pdo, $bankU, $cashU, $d, &$ok) {
            $ok[] = !empty($cb->recordAccountTransfer($bankU, $cashU, 77, 77, $d(165), 'RH-U voided transfer', '', PL_ADMIN)['ok']);
            $id = (int)$pdo->query("SELECT MAX(id) FROM cb_ledger")->fetchColumn();
            $ok[] = !empty($cb->voidEntry($id, 'RH test void of a pair', PL_ADMIN)['ok']);
        });
    // ── bank imports (lib/BankImport.php's shapes): many lines, and one supplier pair ──
    for ($i = 1; $i <= 56; $i++) {
        $plant('bank import line', [$N($bankU)], $one(['date' => $d(160 - ($i % 40)), 'direction' => $i % 3 === 0 ? 'in' : 'out', 'amount' => 100 + $i,
            'category' => $i % 3 === 0 ? 'Receipt' : 'Bank Charges', 'description' => sprintf('RH-U statement line %02d', $i),
            'validation_ref' => sprintf('RH-BANK-%03d', $i), 'validation_status' => 'online', 'source' => 'bank_import', 'account_id' => $bankU,
            'txn_type' => $i % 3 === 0 ? 'PAYMENT' : 'EXPENSE']));
    }
    $plant('bank import supplier pair', [$N($bankU), $N('other')], function () use ($cb, $bankU, $stock, $d, &$ok) {
        $ok[] = !empty($cb->recordAccountTransfer($bankU, $stock, 610, 610, $d(119), 'RH-U supplier payment', '', PL_ADMIN, 'RH-BANKREF-1', 'bank_import')['ok']);
    });
    // ── the other kinds, the capital-B kind, ids naming no account ──
    $plant('inactive bank account', [$N($oldBank)], $one(['date' => $d(118), 'direction' => 'out', 'amount' => 45, 'description' => 'RH-U old bank fee', 'account_id' => $oldBank]));
    foreach ([[$stock, 'inventory'], [$asset, 'asset'], [$supplier, 'payable'], [$equity, 'equity'], [$debtor, 'receivable'], [$dirU, 'director']] as $i => [$acc, $k]) {
        $plant("{$k} account", [$N('other')], $one(['date' => $d(117 - $i), 'direction' => 'out', 'amount' => 210 + $i, 'description' => "RH-U {$k} entry", 'account_id' => $acc]));
    }
    $plant("kind 'Bank' with a capital", [$N('other')], $one(['date' => $d(110), 'direction' => 'out', 'amount' => 66, 'description' => 'RH-U capital-B bank entry', 'account_id' => $capB]));
    $plant('an id naming no account (orphan)', [$N('other')], function () use ($raw, $set, $d) {
        $sr = $raw(['date' => $d(109), 'direction' => 'out', 'amount' => 87, 'description' => 'RH-U row on an account that does not exist']);
        $set('UPDATE cb_ledger SET account_id = 9999 WHERE sr = ?', [$sr]);
    });
    $plant('a negative id', [$N('other')], function () use ($raw, $set, $d) {
        $sr = $raw(['date' => $d(108), 'direction' => 'out', 'amount' => 88, 'description' => 'RH-U row on a negative account id']);
        $set('UPDATE cb_ledger SET account_id = -5 WHERE sr = ?', [$sr]);
    });
    $plant("a text id '7abc' beside cash account 7", [$N('other')], function () use ($raw, $set, $d) {
        $sr = $raw(['date' => $d(107), 'direction' => 'in', 'amount' => 89, 'category' => 'Receipt', 'description' => 'RH-U row on a text account id']);
        $set("UPDATE cb_ledger SET account_id = '7abc' WHERE sr = ?", [$sr]);
    });
    // ── states on not-cash rows ──
    $plant('awaiting approval, moved onto a bank account', [$N($bankU)], function () use ($entry, $cb, $bankU, $me, $d, &$ok) {
        $id = $entry(['date' => $d(106), 'direction' => 'in', 'amount' => 345, 'description' => 'RH-U receipt awaiting approval, put on the bank'], false);
        $ok[] = !empty($cb->assignAccount($id, $bankU, $me)['ok']);
    });
    $plant('rejected bank row', [$N($bankU)], $one(['date' => $d(105), 'direction' => 'out', 'amount' => 46, 'description' => 'RH-U rejected bank fee',
        'account_id' => $bankU, 'status' => 'rejected']));
    $plant('voided bank row', [$N($bankD)], $one(['date' => $d(104), 'direction' => 'in', 'amount' => 47, 'currency' => 'USD', 'category' => 'Receipt',
        'description' => 'RH-U voided dollar bank receipt', 'account_id' => $bankD, 'status' => 'voided']));
    $plant('awaiting approval, director account', [$N('other')], $one(['date' => $d(103), 'direction' => 'in', 'amount' => 48, 'category' => 'Loan Received',
        'description' => 'RH-U director loan awaiting approval', 'account_id' => $dirU, 'status' => 'pending_approval']));
    // ── dollars ──
    $plant('no account, USD', [$C], function () use ($entry, $d) { $entry(['date' => $d(102), 'direction' => 'out', 'amount' => 340, 'currency' => 'USD', 'category' => 'Misc Expense', 'description' => 'RH-U dollar spending, no account']); });
    $plant('bank account, USD', [$N($bankD)], $one(['date' => $d(101), 'direction' => 'in', 'amount' => 1000, 'currency' => 'USD', 'category' => 'Receipt',
        'description' => 'RH-Client Four dollar bank receipt', 'account_id' => $bankD]));
    $plant('cash account in USD (account 7)', [$C], $one(['date' => $d(100), 'direction' => 'out', 'amount' => 12, 'currency' => 'USD', 'description' => 'RH-U dollar cash spend', 'account_id' => $cashD]));
    // 5.18.98's review (TS-3): every text cell of the tab's export that can carry a formula-like value carries one here
    $plant('formula-like text, not cash', [$N($bankD)], $one(['date' => $d(99), 'direction' => 'out', 'amount' => 13, 'currency' => 'USD',
        'description' => '=RH-U formula(1)', 'person' => '@RH-Payee', 'account_id' => $bankD,
        'category' => '+RH-Formula category', 'validation_ref' => '-RH-FORMULA-REF', 'source' => '@RH-formula-source', 'txn_type' => '=RH-FORMULA-TYPE']));
    // ── the ghost row and voided_reconcile: listed nowhere ──
    $plant('ghost row (amount 0, no SR)', [$X], function () use ($raw, $set, $d) {
        $sr = $raw(['date' => $d(98), 'direction' => 'in', 'amount' => 5, 'category' => 'Receipt', 'description' => 'RH-U ghost row']);
        $set("UPDATE cb_ledger SET amount = 0, sr = '' WHERE sr = ?", [$sr]);
    });
    $plant('voided_reconcile', [$X], function () use ($raw, $set, $d) {
        $sr = $raw(['date' => $d(97), 'direction' => 'out', 'amount' => 650, 'description' => 'RH-U reconcile-voided row']);
        $set("UPDATE cb_ledger SET status = 'voided_reconcile' WHERE sr = ?", [$sr]);
    });
    // ── the searches: a needle on both sides, one only on later buttons, one cash only, one not cash only ──
    $plant('search needle, cash', [$C], function () use ($entry, $d) { $entry(['date' => $d(96), 'direction' => 'in', 'amount' => 71, 'description' => 'RH-Needle cash receipt one']); });
    $plant('search needle, cash', [$C], $one(['date' => $d(95), 'direction' => 'in', 'amount' => 72, 'category' => 'Receipt', 'description' => 'RH-Needle cash box two', 'account_id' => $cashU]));
    $plant('search needle, not cash', [$N($bankU)], $one(['date' => $d(94), 'direction' => 'out', 'amount' => 73, 'description' => 'RH-Needle bank charge', 'account_id' => $bankU]));
    $plant('search needle, not cash', [$N($bankD)], $one(['date' => $d(93), 'direction' => 'in', 'amount' => 74, 'currency' => 'USD', 'category' => 'Receipt', 'description' => 'RH-Needle dollar bank receipt', 'account_id' => $bankD]));
    $plant('search needle, not cash', [$N('other')], $one(['date' => $d(92), 'direction' => 'out', 'amount' => 75, 'description' => 'RH-Needle stock purchase', 'account_id' => $stock]));
    $plant('search pin, not on the first button', [$N($bankD)], $one(['date' => $d(91), 'direction' => 'out', 'amount' => 76, 'currency' => 'USD', 'description' => 'RH-Pin dollar fee', 'account_id' => $bankD]));
    $plant('search pin, not on the first button', [$N('other')], $one(['date' => $d(90), 'direction' => 'out', 'amount' => 77, 'description' => 'RH-Pin supplier bill', 'account_id' => $supplier]));
    $plant('search solo, cash only', [$C], function () use ($entry, $d) { $entry(['date' => $d(89), 'direction' => 'in', 'amount' => 78, 'description' => 'RH-Solo cash only']); });
    $plant('search away, not cash only', [$N($spare)], $one(['date' => $d(88), 'direction' => 'out', 'amount' => 79, 'description' => 'RH-Away spare bank fee', 'account_id' => $spare]));
    // ── a field agent's and a field accountant's rows ──
    $plant('field agent, cash', [$C], function () use ($entry, $d) { $entry(['date' => $d(87), 'direction' => 'in', 'amount' => 81, 'person' => PL_FIELD, 'description' => 'RH-Field collection one']); });
    $plant('field agent, cash', [$C], function () use ($entry, $d) { $entry(['date' => $d(86), 'direction' => 'out', 'amount' => 82, 'person' => PL_FIELD, 'category' => 'Staff Advance', 'description' => 'RH-Field advance']); });
    $plant('field agent, not cash', [$N($bankU)], $one(['date' => $d(85), 'direction' => 'in', 'amount' => 83, 'category' => 'Receipt', 'person' => PL_FIELD, 'description' => 'RH-Field bank deposit', 'account_id' => $bankU]));
    $plant('field accountant, cash', [$C], function () use ($entry, $d) { $entry(['date' => $d(84), 'direction' => 'in', 'amount' => 84, 'person' => PL_FIELD2, 'description' => 'RH-Field Two collection']); });
    $plant('field accountant, not cash', [$N($bankU)], $one(['date' => $d(83), 'direction' => 'in', 'amount' => 85, 'category' => 'Receipt', 'person' => PL_FIELD2, 'description' => 'RH-Field Two bank deposit', 'account_id' => $bankU]));
    // ── enough cash rows for a second page ──
    for ($i = 1; $i <= 60; $i++) {
        $plant('a cash row (paging)', [$C], function () use ($entry, $d, $i) {
            $entry(['date' => $d(80 - ($i % 30)), 'direction' => $i % 4 === 0 ? 'out' : 'in', 'amount' => 1000 + $i,
                    'category' => $i % 4 === 0 ? 'Misc Expense' : 'Receipt', 'description' => sprintf('RH-U bulk cash %02d', $i)]);
        });
    }
    // ── 4g and BlueCARD ──
    $plant('4g cash', [$C], function () use ($entry, $d) { $entry(['project' => '4g', 'date' => $d(40), 'direction' => 'in', 'amount' => 1750, 'description' => 'RH-Client Five 4G payment']); });
    $plant('4g cash', [$C], function () use ($entry, $d) { $entry(['project' => '4g', 'date' => $d(39), 'direction' => 'out', 'amount' => 30, 'currency' => 'USD', 'category' => 'Misc Expense', 'description' => 'RH-4G dollar spending']); });
    $plant('4g cash', [$C], function () use ($entry, $d) { $entry(['project' => '4g', 'date' => $d(38), 'direction' => 'out', 'amount' => 50, 'person' => PL_FIELD, 'category' => 'Staff Advance', 'description' => 'RH-4G advance, awaiting approval'], false); });
    $plant('4g not cash', [$N($bankU)], $one(['project' => '4g', 'date' => $d(37), 'direction' => 'out', 'amount' => 222, 'category' => 'Site Power', 'description' => 'RH-4G bank expense one', 'account_id' => $bankU]));
    $plant('4g not cash', [$N($bankU)], $one(['project' => '4g', 'date' => $d(36), 'direction' => 'out', 'amount' => 223, 'category' => 'Site Power', 'description' => 'RH-4G bank expense two', 'account_id' => $bankU]));
    $plant('4g not cash', [$N('other')], $one(['project' => '4g', 'date' => $d(35), 'direction' => 'out', 'amount' => 224, 'description' => 'RH-4G stock', 'account_id' => $stock]));
    $plant('BlueCARD cash', [$C], function () use ($entry, $d) { $entry(['project' => 'bluecard', 'date' => $d(34), 'direction' => 'in', 'amount' => 125, 'description' => 'RH-BlueCARD receipt']); });
    $plant('BlueCARD not cash', [$N($spare)], $one(['project' => 'bluecard', 'date' => $d(33), 'direction' => 'out', 'amount' => 126, 'description' => 'RH-BlueCARD bank fee', 'account_id' => $spare]));
    $plant('BlueCARD cash', [$C], function () use ($entry, $d) { $entry(['project' => 'bluecard', 'date' => $d(32), 'direction' => 'out', 'amount' => 9, 'currency' => 'USD', 'category' => 'Misc Expense', 'description' => 'RH-BlueCARD dollar spending']); });
    $plant('BlueCARD not cash', [$N($bankD)], $one(['project' => 'bluecard', 'date' => $d(31), 'direction' => 'in', 'amount' => 11, 'currency' => 'USD', 'category' => 'Receipt', 'description' => 'RH-BlueCARD dollar bank receipt', 'account_id' => $bankD]));
    // ── yesterday and today: the daily report and the maintenance summary read these ──
    $plant('yesterday, cash', [$C], function () use ($entry, $d) { $entry(['date' => $d(1), 'direction' => 'in', 'amount' => 91, 'description' => 'RH-U yesterday cash receipt']); });
    $plant('yesterday, not cash', [$N($bankU)], $one(['date' => $d(1), 'direction' => 'out', 'amount' => 92, 'category' => 'Bank Charges', 'description' => 'RH-U yesterday bank charge', 'account_id' => $bankU, 'source' => 'bank_import']));
    $plant('yesterday, not cash', [$N($bankU)], $one(['project' => '4g', 'date' => $d(1), 'direction' => 'out', 'amount' => 93, 'description' => 'RH-4G yesterday bank fee', 'account_id' => $bankU]));
    $plant('today, cash', [$C], function () use ($entry, $d) { $entry(['date' => $d(0), 'direction' => 'in', 'amount' => 94, 'description' => 'RH-U today cash receipt']); });
    $plant('today, not cash', [$N($bankU)], $one(['date' => $d(0), 'direction' => 'out', 'amount' => 95, 'category' => 'Bank Charges', 'description' => 'RH-U today bank charge', 'account_id' => $bankU, 'source' => 'bank_import']));
    // ── blank currency, newer than every UGX row (written last) ──
    $plant('blank currency, newer than every UGX row', [$C], $one(['date' => $d(0), 'direction' => 'in', 'amount' => 66, 'currency' => '',
        'category' => 'Receipt', 'description' => 'RH-U blank-currency receipt, newest']));

    foreach ([$oldCash, $oldBank, $unused] as $off) { $cb->setAccountActive($off, false); $A[$off][3] = false; }
    $ids = ['bankU' => $bankU, 'bankD' => $bankD, 'cashU' => $cashU, 'cashD' => $cashD, 'mtn' => $mtn, 'dirU' => $dirU, 'oldCash' => $oldCash,
            'spare' => $spare, 'oldBank' => $oldBank, 'unused' => $unused, 'stock' => $stock, 'kes' => $kes, 'capB' => $capB];
    return [$dir . '/plugin.sqlite3', $decl, $A, $ids, in_array(false, $ok, true) ? -1 : count($ok)];
}

/**
 * The estate without cb_accounts (F): the standard cb_ledger shape (account_id NOT NULL DEFAULT 0), written with no
 * account at all, then raw ids no writer makes. Only rows with no account can be cash here.
 */
function pl_build_nx(string $dir, array $cfg): array
{
    mkdir($dir, 0700, true);
    putenv('DN_VAULT_FILE=' . $dir . '-vault.json');
    $store = SqliteStore::create($dir);                                    // first: a fresh folder's *.json is imported and renamed
    file_put_contents($dir . '/kyc_config.json', json_encode($cfg + ['data_dir' => $dir]));
    $pdo = $store->getPdo();
    $cb = new CashbookService($store, $dir);
    $me = ['name' => PL_ADMIN]; $ok = []; $decl = [];
    $d = function (int $n): string { return date('Y-m-d', strtotime("-{$n} days")); };
    $add = function (string $shape, string $side, array $r, string $status = 'approved', $acct = null) use ($cb, $pdo, $me, &$ok, &$decl): void {
        $x = $cb->addEntry($r + ['validation_status' => 'na', 'project' => 'dishnet', 'currency' => 'UGX', 'category' => 'Receipt'], $me, $status !== 'pending_approval');
        $id = (int)($x['id'] ?? 0); $ok[] = $id > 0;
        if ($status === 'rejected') $cb->rejectEntry($id, 'RH test rejection', $me);
        if ($status === 'voided') $ok[] = !empty($cb->voidEntry($id, 'RH test void', PL_ADMIN)['ok']);
        if ($acct !== null) $pdo->prepare('UPDATE cb_ledger SET account_id = ? WHERE id = ?')->execute([$acct, $id]);
        $decl[$id] = ['shape' => $shape, 'side' => $side, 'chip' => $side === 'noncash' ? 'other' : null];
    };
    $add('no account', 'cash', ['date' => $d(20), 'direction' => 'in', 'amount' => 310, 'description' => 'RH-X receipt']);
    $add('no account, awaiting approval', 'cash', ['date' => $d(19), 'direction' => 'in', 'amount' => 311, 'description' => 'RH-X receipt awaiting approval'], 'pending_approval');
    $add('no account, rejected', 'cash', ['date' => $d(18), 'direction' => 'in', 'amount' => 312, 'description' => 'RH-X rejected receipt'], 'rejected');
    $add('no account, voided', 'cash', ['date' => $d(17), 'direction' => 'in', 'amount' => 313, 'description' => 'RH-X voided receipt'], 'voided');
    $add('no account, USD', 'cash', ['date' => $d(16), 'direction' => 'out', 'amount' => 14, 'currency' => 'USD', 'category' => 'Misc Expense', 'description' => 'RH-X dollar spend']);
    $add('no account, search needle', 'cash', ['date' => $d(15), 'direction' => 'in', 'amount' => 315, 'description' => 'RH-Needle X cash']);
    $add('an id naming no account (orphan)', 'noncash', ['date' => $d(14), 'direction' => 'out', 'amount' => 316, 'category' => 'Misc Expense', 'description' => 'RH-Needle X orphan'], 'approved', 9999);
    $add('a negative id', 'noncash', ['date' => $d(13), 'direction' => 'out', 'amount' => 317, 'category' => 'Misc Expense', 'description' => 'RH-X negative id'], 'approved', -5);
    $add("a text id '7abc'", 'noncash', ['date' => $d(12), 'direction' => 'out', 'amount' => 318, 'category' => 'Misc Expense', 'description' => 'RH-X text id'], 'approved', '7abc');
    $has = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'cb_accounts'")->fetchColumn();
    return [$dir . '/plugin.sqlite3', $decl, in_array(false, $ok, true) || $has !== 0 ? -1 : count($ok)];
}

/**
 * A legacy book (G): the nullable ledger of P3b E1 and a hand-made cb_accounts with no primary key, holding a cash and a
 * bank account whose id is NULL. Written by hand, as such a table would have been. 5.18.98's review (SQL-1, SQL-2) adds
 * the bank accounts no button can select — a negative id, a text id, a real id — and two records sharing id 5: the
 * first three have no button and their rows are under Other accounts; id 5 has one button.
 */
function pl_build_lg(string $dir, array $cfg): array
{
    mkdir($dir, 0700, true);
    putenv('DN_VAULT_FILE=' . $dir . '-vault.json');
    $store = SqliteStore::create($dir);                                    // first: a fresh folder's *.json is imported and renamed
    file_put_contents($dir . '/kyc_config.json', json_encode($cfg + ['data_dir' => $dir]));
    $pdo = $store->getPdo();
    $pdo->exec('DROP TABLE IF EXISTS cb_ledger');
    $pdo->exec('DROP TABLE IF EXISTS cb_accounts');
    $pdo->exec("CREATE TABLE cb_ledger (id INTEGER PRIMARY KEY AUTOINCREMENT, sr TEXT NOT NULL DEFAULT '', date TEXT NOT NULL DEFAULT '',
                direction TEXT NOT NULL DEFAULT 'in', amount REAL NOT NULL DEFAULT 0, currency TEXT NOT NULL DEFAULT 'USD',
                status TEXT NOT NULL DEFAULT 'approved', project TEXT NOT NULL DEFAULT 'dishnet', description TEXT NOT NULL DEFAULT '',
                account_id INTEGER)");
    $pdo->exec("CREATE TABLE cb_accounts (id INTEGER, name TEXT, currency TEXT, kind TEXT, active INTEGER)");
    $pdo->exec("INSERT INTO cb_accounts (id, name, currency, kind, active) VALUES
                (NULL, 'RH-Legacy cash, no id', 'UGX', 'cash', 1), (NULL, 'RH-Legacy bank, no id', 'UGX', 'bank', 1),
                (1, 'RH-L Cash', 'UGX', 'cash', 1), (2, 'RH-L Bank', 'UGX', 'bank', 1), (3, 'RH-L Momo', 'UGX', 'momo', 1), (4, 'RH-L Stock', 'UGX', 'inventory', 1),
                (-3, 'RH-L Bank, negative id', 'UGX', 'bank', 1), ('x9', 'RH-L Bank, text id', 'UGX', 'bank', 1), (6.5, 'RH-L Bank, real id', 'UGX', 'bank', 1),
                (5, 'RH-L Bank Five', 'UGX', 'bank', 1), (5, 'RH-L Bank Five, second record', 'UGX', 'bank', 1)");
    $rows = [[null, 'cash', null, 'NULL account id'], [0, 'cash', null, 'no account'], [1, 'cash', null, 'cash account'], [3, 'cash', null, 'mobile-money account'],
             [2, 'noncash', 2, 'bank account'], [4, 'noncash', 'other', 'inventory account'], [9, 'noncash', 'other', 'orphan id'],
             [-1, 'noncash', 'other', 'negative id'], ['x1', 'noncash', 'other', 'text id'],
             [-3, 'noncash', 'other', 'bank account, negative id'], ['x9', 'noncash', 'other', 'bank account, text id'],
             [6.5, 'noncash', 'other', 'bank account, real id'], [5, 'noncash', 5, 'bank account held by two records']];
    $decl = []; $st = $pdo->prepare("INSERT INTO cb_ledger (sr, date, direction, amount, currency, status, project, description, account_id) VALUES (?,?,?,?,?,?,?,?,?)");
    foreach ($rows as $i => [$acc, $side, $chip, $shape]) {
        foreach (['approved', 'pending_approval'] as $j => $status) {
            $st->execute([sprintf('RH-LG%02d%d', $i, $j), date('Y-m-d', strtotime('-' . (30 - $i) . ' days')), $i % 2 ? 'out' : 'in', 40 + $i, 'UGX', $status, 'dishnet', "RH-LG {$shape} {$status}", $acc]);
            $decl[(int)$pdo->lastInsertId()] = ['shape' => $shape, 'side' => $side, 'chip' => $chip];
        }
    }
    $cb = new CashbookService($store, $dir);                                 // initTable(): the missing columns only
    $col = null;
    foreach ($pdo->query("SELECT name, \"notnull\" FROM pragma_table_info('cb_ledger')")->fetchAll(\PDO::FETCH_ASSOC) as $c) if ($c['name'] === 'account_id') $col = $c;
    return [$dir . '/plugin.sqlite3', $decl, $col !== null && (int)$col['notnull'] === 0 ? 1 : -1];
}

/**
 * A book where rule A is off (E, L): the T3 shapes — cash and bank accounts with openings, a director pair, no-account
 * rows in the base and in SSP where SSP exists, awaiting-approval, rejected, voided and voided_reconcile rows, an orphan
 * id, a blank currency, bank-import lines, 4g rows, and a search needle on a cash row and on a bank row.
 */
function pl_build_off(string $dir, array $cfg): array
{
    mkdir($dir, 0700, true);
    putenv('DN_VAULT_FILE=' . $dir . '-vault.json');
    $store = SqliteStore::create($dir);                                    // first: a fresh folder's *.json is imported and renamed
    file_put_contents($dir . '/kyc_config.json', json_encode($cfg + ['data_dir' => $dir]));
    $cb    = new CashbookService($store, $dir);
    $curs  = array_map('trim', explode(',', (string)$cfg['cashbook_currencies']));
    $B     = $curs[0];
    $me    = ['name' => PL_ADMIN];
    $d     = function (int $n): string { return date('Y-m-d', strtotime("-{$n} days")); };
    $ok    = [];
    $acc   = function (string $name, string $cur, string $kind) use ($cb, &$ok): int { $r = $cb->addAccount($name, $cur, $kind); $ok[] = !empty($r['ok']); return (int)($r['id'] ?? 0); };
    $add   = function (array $r, bool $approve = true) use ($cb, $me, &$ok): int {
        $x = $cb->addEntry($r + ['validation_status' => 'na', 'project' => 'dishnet', 'category' => 'Receipt'], $me, $approve); $ok[] = !empty($x['ok']); return (int)($x['id'] ?? 0);
    };
    $raw   = function (array $r) use ($cb): string { return $cb->addEntryRaw($r + ['status' => 'approved', 'approved_by' => PL_ADMIN, 'validation_status' => 'na', 'project' => 'dishnet']); };
    $cashB = $acc("RH-Cash Box – {$B}", $B, 'cash');
    $bankB = $acc("RH-Bank – {$B}", $B, 'bank');
    $dirB  = $acc("RH-Director – {$B}", $B, 'director');
    $ok[] = !empty($cb->recordOpeningBalance($cashB, 400, $d(40), 'RH opening', 'RH-OB', PL_ADMIN)['ok']);
    $ok[] = !empty($cb->recordOpeningBalance($bankB, 2500, $d(40), 'RH opening', 'RH-OB', PL_ADMIN)['ok']);
    $ok[] = !empty($cb->recordFunding($bankB, $dirB, 700, $d(35), 'RH funding into the bank', '', PL_ADMIN)['ok']);
    $add(['date' => $d(30), 'direction' => 'in', 'amount' => 130, 'currency' => $B, 'description' => 'RH-Client One payment']);
    $add(['date' => $d(29), 'direction' => 'in', 'amount' => 131, 'currency' => $B, 'description' => 'RH-Needle cash receipt']);
    $raw(['date' => $d(28), 'direction' => 'out', 'amount' => 132, 'currency' => $B, 'category' => 'Bank Charges', 'description' => 'RH-Needle bank charge',
          'account_id' => $bankB, 'source' => 'bank_import', 'validation_status' => 'online']);
    $raw(['date' => $d(27), 'direction' => 'out', 'amount' => 133, 'currency' => $B, 'category' => 'Bank Charges', 'description' => 'RH statement line',
          'account_id' => $bankB, 'source' => 'bank_import', 'validation_status' => 'online']);
    $add(['date' => $d(20), 'direction' => 'out', 'amount' => 480, 'currency' => $B, 'category' => 'Misc Expense', 'description' => 'RH office expense, no account']);
    $add(['date' => $d(5), 'direction' => 'in', 'amount' => 210, 'currency' => $B, 'description' => 'RH-Client Two payment, awaiting approval'], false);
    $rej = $add(['date' => $d(6), 'direction' => 'in', 'amount' => 820, 'currency' => $B, 'description' => 'RH-Client Three payment']);
    $cb->rejectEntry($rej, 'RH test rejection', $me);
    $voi = $add(['date' => $d(7), 'direction' => 'in', 'amount' => 540, 'currency' => $B, 'description' => 'RH voided receipt']);
    $ok[] = !empty($cb->voidEntry($voi, 'RH test void', PL_ADMIN)['ok']);
    $raw(['date' => $d(8), 'direction' => 'out', 'amount' => 650, 'currency' => $B, 'category' => 'Misc Expense', 'description' => 'RH reconcile-voided row', 'status' => 'voided_reconcile']);
    $orphan = $raw(['date' => $d(9), 'direction' => 'out', 'amount' => 87, 'currency' => $B, 'category' => 'Misc Expense', 'description' => 'RH row on an account that does not exist']);
    $store->getPdo()->prepare('UPDATE cb_ledger SET account_id = 9999 WHERE sr = ?')->execute([$orphan]);
    $raw(['date' => $d(10), 'direction' => 'in', 'amount' => 66, 'currency' => '', 'category' => 'Receipt', 'description' => 'RH receipt stored with no currency']);
    $add(['project' => '4g', 'date' => $d(20), 'direction' => 'in', 'amount' => 1200, 'currency' => $B, 'description' => 'RH-Client Four 4G payment']);
    $raw(['project' => '4g', 'date' => $d(15), 'direction' => 'out', 'amount' => 95, 'currency' => $B, 'category' => 'Site Power', 'description' => 'RH 4G bank expense', 'account_id' => $bankB]);
    if (in_array('SSP', $curs, true)) {
        $add(['date' => $d(12), 'direction' => 'in', 'amount' => 50, 'currency' => 'SSP', 'ssp_amount' => 250000, 'ssp_rate' => 5000, 'description' => 'RH-Client Six SSP payment']);
    }
    if ($B !== 'USD' && in_array('USD', $curs, true)) {
        $bankD = $acc('RH-Bank – USD', 'USD', 'bank');
        $raw(['date' => $d(16), 'direction' => 'in', 'amount' => 300, 'currency' => 'USD', 'category' => 'Receipt', 'description' => 'RH-Client Seven bank receipt', 'account_id' => $bankD]);
    }
    return [$dir . '/plugin.sqlite3', $curs, in_array(false, $ok, true) ? -1 : count($ok)];
}

/** Every ledger row of a book, with the storage class of its account id; the oracles never cast an id. */
function pl_rows(string $book): array
{
    $pdo = new \PDO('sqlite:' . $book);
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $out = [];
    foreach ($pdo->query('SELECT *, typeof(account_id) AS acc_type FROM cb_ledger ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC) as $r) {
        $r['id'] = (int)$r['id'];
        $out[$r['id']] = $r;
    }
    return $out;
}

/**
 * The fixture's own listing — written from buildWhere()'s documented filters and the DECLARED side and button, never
 * from the SQL: listed means not a ghost (amount 0 and no SR) and not voided_reconcile. Newest first by (date, id).
 */
function pl_listed(array $rows, array $decl, array $f): array
{
    $has = function (array $r, string $k, string $needle): bool { return $needle === '' || stripos((string)($r[$k] ?? ''), $needle) !== false; };
    $o = [];
    foreach ($rows as $id => $r) {
        if ((float)$r['amount'] == 0.0 && (string)$r['sr'] === '') continue;
        if ((string)$r['status'] === 'voided_reconcile') continue;
        foreach (['project', 'direction', 'currency', 'category', 'validation_status', 'status'] as $k) {
            if (($f[$k] ?? '') !== '' && (string)$r[$k] !== (string)$f[$k]) continue 2;
        }
        if (($f['person'] ?? '') !== '' && !$has($r, 'person', (string)$f['person'])) continue;
        if (($f['date_from'] ?? '') !== '' && strcmp((string)$r['date'], (string)$f['date_from']) < 0) continue;
        if (($f['date_to'] ?? '') !== '' && strcmp((string)$r['date'], (string)$f['date_to']) > 0) continue;
        if (($f['search'] ?? '') !== '') {
            $q = (string)$f['search'];
            if (!$has($r, 'description', $q) && !$has($r, 'sr', $q) && !$has($r, 'person', $q) && !$has($r, 'validation_ref', $q)) continue;
        }
        if (($f['book'] ?? '') !== '' && ($decl[$id]['side'] ?? '?') !== $f['book']) continue;
        if (array_key_exists('book_account', $f) && ($decl[$id]['chip'] ?? null) !== $f['book_account']) continue;
        $o[] = $id;
    }
    usort($o, function (int $x, int $y) use ($rows): int {
        $c = strcmp((string)$rows[$y]['date'], (string)$rows[$x]['date']);
        return $c !== 0 ? $c : $y <=> $x;
    });
    return $o;
}

/** The ids getEntries() lists for a filter set (every page). */
function pl_ids(CashbookService $cb, array $f): array
{
    return array_map(function ($r) { return (int)$r['id']; }, $cb->getEntries($f + ['limit' => 9999, 'offset' => 0]));
}

/**
 * The buttons the tab must offer for a project, from the fixture's own accounts and declarations: each bank account
 * (kind exactly 'bank') that is active or holds a listed row of this project, in the book's currency order (UGX, USD,
 * then any other), then by id; then 'other'.
 */
function pl_chip_keys(array $rows, array $decl, array $accounts, string $proj): array
{
    $rank = ['UGX' => 0, 'USD' => 1];
    $keys = [];
    foreach ($accounts as $id => [$name, $cur, $kind, $active]) {
        if ($kind !== 'bank') continue;
        if (!$active && pl_listed($rows, $decl, array_filter(['project' => $proj, 'book' => 'noncash']) + ['book_account' => $id]) === []) continue;
        $keys[] = [$rank[$cur] ?? 2, $id];
    }
    usort($keys, function ($a, $b) { return $a <=> $b; });
    return array_merge(array_map(function ($k) { return $k[1]; }, $keys), ['other']);
}

/** The book's rows, copied into a sandbox's store: its three tables, their schema, their indexes and rows. */
function pl_copy_book(string $src, string $dst): int
{
    $pdo = new \PDO('sqlite:' . $dst);
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $pdo->exec('ATTACH DATABASE ' . $pdo->quote($src) . ' AS src');
    $n = 0;
    foreach (['cb_accounts', 'cb_ledger', 'fin_audit'] as $tb) {
        $sql = $pdo->query("SELECT sql FROM src.sqlite_master WHERE type = 'table' AND name = " . $pdo->quote($tb))->fetchColumn();
        if (!$sql) continue;
        if ($pdo->query("SELECT 1 FROM main.sqlite_master WHERE type = 'table' AND name = " . $pdo->quote($tb))->fetchColumn()) {
            if ((int)$pdo->query("SELECT COUNT(*) FROM main.{$tb}")->fetchColumn() !== 0) throw new \RuntimeException("a fresh sandbox already holds rows in {$tb}");
            $pdo->exec("DROP TABLE main.{$tb}");
        }
        $pdo->exec((string)$sql);
        $pdo->exec("INSERT INTO main.{$tb} SELECT * FROM src.{$tb}");
        foreach ($pdo->query("SELECT sql FROM src.sqlite_master WHERE type = 'index' AND tbl_name = " . $pdo->quote($tb) . ' AND sql IS NOT NULL')->fetchAll(\PDO::FETCH_COLUMN) as $ix) {
            $pdo->exec((string)$ix);
        }
        $n += (int)$pdo->query("SELECT COUNT(*) FROM main.{$tb}")->fetchColumn();
    }
    $pdo->exec('DETACH DATABASE src');
    return $n;
}

/** sqlite_master, read from a connection of its own: every column, every object, in a fixed order. */
function pl_master(string $file): array
{
    $pdo = new \PDO('sqlite:' . $file);
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $rows = $pdo->query('SELECT type, name, tbl_name, rootpage, sql FROM sqlite_master ORDER BY type, name')->fetchAll(\PDO::FETCH_ASSOC);
    $pdo = null;
    return $rows;
}

// ═══ The sandboxes ═══════════════════════════════════════════════════════════════════════════════════════════════

/** Each tree's own library, read inside its sandbox: the reads the other callers make, through that tree's code. */
const PL_READER = <<<'PHP'
<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/StoreInterface.php';
require_once __DIR__ . '/lib/JsonStore.php';
require_once __DIR__ . '/lib/SqliteStore.php';
require_once __DIR__ . '/lib/CashbookService.php';
require_once __DIR__ . '/lib/DailyReportService.php';
$dataDir = (string)getenv('DN_DATA_DIR');
$cb    = new CashbookService(SqliteStore::create($dataDir), $dataDir);
$today = date('Y-m-d');
$yday  = date('Y-m-d', strtotime('-1 day'));
$out   = [];
// cron_maintenance.php's evening summary reads today's rows this way
$out['maintenance'] = $cb->getEntries(['date_from' => $today, 'date_to' => $today, 'limit' => 9999]);
// lib/DailyReportService.php: each project's rows of the day, the stats it works out, its CSV and its HTML
$projects = (new \ReflectionClass('DailyReportService'))->getConstant('PROJECTS');
$all = [];
foreach ($projects as $p) $all[$p] = $cb->getEntries(['project' => $p, 'date_from' => $yday, 'date_to' => $yday, 'limit' => 9999, 'offset' => 0]);
$svc = new DailyReportService($cb, $dataDir, ['daily_report_recipients' => 'rh-reports@example.test']);
$res = $svc->sendDailyReport($yday);
$gc = new \ReflectionMethod($svc, 'generateCsv');  $gc->setAccessible(true);
$gh = new \ReflectionMethod($svc, 'generateHtml'); $gh->setAccessible(true);
$out['daily_report'] = ['entries' => $all, 'result' => $res, 'csv' => $gc->invoke($svc, $all, $yday), 'html' => $gh->invoke($svc, $res['stats'], $yday)];
foreach (['UGX', 'USD'] as $c) foreach (['', 'dishnet', '4g', 'bluecard'] as $p) $out['cash_in_hand'][$c . '|' . $p] = $cb->cashInHand($c, $p);
echo json_encode($out), "\n", 'RULE ', ($cb->cashOnlyRuleInForce() ? 'true' : 'false'), "\n";
PHP;

/** The values that differ between any two runs of the same book on any tree — and nothing else (T3's list). */
function pl_norm(string $x, SjSandbox $s, array $stamps, bool $page = false): string
{
    if ($page && ($p = strpos($x, '</html>')) !== false) $x = substr($x, 0, $p + 7);
    static $ver = [];
    if (!isset($ver[$s->root])) {
        $m = json_decode((string)@file_get_contents($s->root . '/manifest.json'), true);
        $ver[$s->root] = (string)($m['information']['version'] ?? '');
    }
    if ($ver[$s->root] !== '') $x = (string)preg_replace('/\bv' . preg_quote($ver[$s->root], '/') . '\b/', 'v<version>', $x);
    foreach ($s->tok as $k => $tk) $x = str_replace($tk, "<tok:{$k}>", $x);
    $x = str_replace([$s->data, $s->plug, $s->sb], ['<data>', '<plug>', '<sb>'], $x);
    foreach (['crm' => $s->crm, 'evo' => $s->evo, 'plugin' => (string)preg_replace('#/public\.php$#', '', $s->base)] as $k => $a) {
        $x = str_replace([$a, str_replace('/', '\/', $a)], "<{$k}>", $x);
    }
    $x = (string)preg_replace('/\b\d{8}:[0-9a-f]{40}\b/', '<day-token>', $x);
    $x = (string)preg_replace('/name="_csrf" value="[^"]*"/', 'name="_csrf" value="<csrf>"', $x);
    $x = (string)preg_replace('/\b\d+[smhd] ago\b/', '<ago>', $x);
    $x = str_replace($stamps, array_map(function ($k) { return "<{$k}>"; }, array_keys($stamps)), $x);
    return (string)preg_replace('/\b\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?\b/', '<clock>', $x);
}

/** One output from each tree, read within one clock minute (a pair that straddles a minute is read again). */
function pl_pair(callable $get, SjSandbox $a, SjSandbox $b, bool $page): array
{
    for ($try = 0; $try < 4; $try++) {
        $t0 = time();
        $stamps = ['long-date' => date('l, d M Y', $t0), 'date' => date('d M Y', $t0), 'time' => date('g:i A', $t0)];
        $x = $get($a); $y = $get($b);
        if (date('Y-m-d H:i', time()) === date('Y-m-d H:i', $t0)) return [pl_norm($x, $a, $stamps, $page), pl_norm($y, $b, $stamps, $page), true];
    }
    return [pl_norm($x, $a, $stamps, $page), pl_norm($y, $b, $stamps, $page), false];
}

/** One request: [code, body, headers (lower-case names)]. */
function pl_req(SjSandbox $s, string $who, string $qs): array
{
    $ch = curl_init($s->base . '?' . $qs);
    $hd = [];
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
        CURLOPT_COOKIEJAR => $s->jars[$who], CURLOPT_COOKIEFILE => $s->jars[$who],
        CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$hd) {
            $p = strpos($h, ':');
            if ($p !== false) $hd[strtolower(trim(substr($h, 0, $p)))] = trim(substr($h, $p + 1));
            return strlen($h);
        }]);
    $r = curl_exec($ch);
    $c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$r === false ? 0 : $c, (string)$r, $hd];
}

/** A CSV body as rows of cells (the export writes with the escape '', as fgetcsv reads here). */
function pl_csv(string $body): array
{
    $fh = fopen('php://temp', 'w+'); fwrite($fh, $body); rewind($fh);
    $rows = [];
    while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) { if ($r === [null]) continue; $rows[] = $r; }
    fclose($fh);
    return $rows;
}

/** The tab's own markup, between its two markers; '' when the page holds none. */
function pl_seg(string $html): string
{
    $a = strpos($html, '<!-- cb-noncash:start -->'); $b = strpos($html, '<!-- cb-noncash:end -->');
    return ($a === false || $b === false || $b < $a) ? '' : substr($html, $a, $b - $a);
}

/** The ids of the desktop table rows and of the phone cards, in page order. */
function pl_page_ids(string $html): array
{
    preg_match_all('/<tr [^>]*data-id="(-?\d+)"/', $html, $t);
    preg_match_all('/<div class="cb3-card[^"]*"[^>]*data-id="(-?\d+)"/', $html, $c);
    return [array_map('intval', $t[1]), array_map('intval', $c[1])];
}

/** A sandbox of a tree with a book copied in, four accounts signed in, and the reader beside the code. */
function pl_sandbox(string $tree, array $cfg, string $tag, string $book, array $phones): SjSandbox
{
    $s = SjSandbox::start($tree, $cfg, $tag);
    $n = pl_copy_book($book, $s->data . '/plugin.sqlite3');
    if ($n <= 0) throw new \RuntimeException('the book was not copied into the sandbox');
    $s->staff('boss', ['name' => PL_ADMIN, 'email' => 'rh-admin@example.test', 'role' => 'admin', 'is_admin' => true, 'phone' => $phones[0]]);
    $s->staff('acc', ['name' => 'RH-Accountant One', 'email' => 'rh-acc@example.test', 'role' => 'accountant', 'phone' => $phones[1]]);
    $s->staff('fa', ['name' => PL_FIELD, 'email' => 'rh-fa@example.test', 'role' => 'sales', 'phone' => $phones[2]]);
    $s->staff('fa2', ['name' => PL_FIELD2, 'email' => 'rh-fa2@example.test', 'role' => 'field_accountant', 'phone' => $phones[3]]);
    foreach (['boss' => 'rh-admin', 'acc' => 'rh-acc', 'fa' => 'rh-fa', 'fa2' => 'rh-fa2'] as $who => $em) $s->login($who, $em . '@example.test', 'sj-password-1');
    file_put_contents($s->plug . '/__pl98_read.php', PL_READER);
    return $s;
}

/** Stop a sandbox and remove its fake Evolution's state file (named after its own path and port). */
function pl_stop(?SjSandbox $s): void
{
    if (!$s instanceof SjSandbox) return;
    $evo = sys_get_temp_dir() . '/fake_evo_state_' . md5((string)realpath($s->root . '/tests/fixtures/fake_evo_server.php')
         . (string)parse_url($s->evo, PHP_URL_PORT)) . '.json';
    $s->stop();
    @unlink($evo);
}

/** Two lists, each newest first, merged by (date DESC, id DESC). */
function pl_merge(array $a, array $b, array $rows): array
{
    $cmp = function (int $x, int $y) use ($rows): int {
        $c = strcmp((string)($rows[$y]['date'] ?? ''), (string)($rows[$x]['date'] ?? ''));
        return $c !== 0 ? $c : $y <=> $x;
    };
    $o = []; $i = 0; $j = 0; $na = count($a); $nb = count($b);
    while ($i < $na && $j < $nb) $o[] = $cmp($a[$i], $b[$j]) <= 0 ? $a[$i++] : $b[$j++];
    while ($i < $na) $o[] = $a[$i++];
    while ($j < $nb) $o[] = $b[$j++];
    return $o;
}

/**
 * The running balances the Balance column must show, worked out here from the declarations: a row runs exactly when it
 * is declared cash and approved (P3b's D2 = A); the base stream takes blank-currency rows in the All view and in the
 * base view; in the All view every other currency is its own stream. id => [balance or null, stream].
 */
function pl_streams(array $rows, array $decl, string $base, string $view, string $proj): array
{
    $streamCur = $view !== '' ? $view : $base;
    $sorted = array_values($rows);
    usort($sorted, function ($x, $y) { $c = strcmp((string)$x['date'], (string)$y['date']); return $c !== 0 ? $c : $x['id'] <=> $y['id']; });
    $run = 0.0; $baseMap = []; $otherRun = []; $otherMap = [];
    foreach ($sorted as $r) {
        if (($decl[$r['id']]['side'] ?? '') !== 'cash' || (string)$r['status'] !== 'approved') continue;
        if ($proj !== '' && (string)$r['project'] !== $proj) continue;
        $c = $r['currency'];
        $blank = ($c === null || $c === '');
        $inBase = ($view === '' || $view === $base) ? ($c === $base || $blank) : ($c === $streamCur);
        $signed = $r['direction'] === 'in' ? (float)$r['amount'] : -(float)$r['amount'];
        if ($inBase) { $run += $signed; $baseMap[$r['id']] = round($run, 2); }
        if ($view === '' && !$blank && $c !== $base) {
            $otherRun[$c] = ($otherRun[$c] ?? 0.0) + $signed;
            $otherMap[$r['id']] = [round($otherRun[$c], 2), (string)$c];
        }
    }
    $out = [];
    foreach ($rows as $id => $r) $out[$id] = ($view === '' && isset($otherMap[$id])) ? $otherMap[$id] : [$baseMap[$id] ?? null, $streamCur];
    return $out;
}

/** The label the tab and its export must print for a not-cash row: its account's name, masked, or "account not found". */
function pl_expect_label(array $r, array $acc): string
{
    if ($r['acc_type'] === 'integer' && isset($acc[(int)$r['account_id']])) {
        $t = (string)preg_replace_callback('/\d{5,}/', function ($m) { return "\u{2022}\u{2022}\u{2022}\u{2022}" . substr($m[0], -4); }, (string)$acc[(int)$r['account_id']][0]);
    } else {
        $t = 'account not found (#' . (string)$r['account_id'] . ')';
    }
    return htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
}

/** The names sqlite_master gained, lost or changed between two readings. */
function pl_master_diff(array $a, array $b): string
{
    $key = function (array $rows): array { $o = []; foreach ($rows as $r) $o[$r['type'] . ':' . $r['name']] = json_encode($r); return $o; };
    $ka = $key($a); $kb = $key($b);
    $chg = array_keys(array_filter($kb, function ($v, $k) use ($ka) { return isset($ka[$k]) && $ka[$k] !== $v; }, ARRAY_FILTER_USE_BOTH));
    return 'added ' . json_encode(array_keys(array_diff_key($kb, $ka))) . ', removed ' . json_encode(array_keys(array_diff_key($ka, $kb))) . ', changed ' . json_encode($chg);
}

// ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════
echo "The books, each written once in a folder of its own (every amount and name invented)\n";
$d = function (int $n): string { return date('Y-m-d', strtotime("-{$n} days")); };
$U_DIR = $RUN . '/book-u';
try {
    [$U_BOOK, $DECL, $ACC, $AID, $uOk] = pl_build_u($U_DIR, $UG);
} catch (\Throwable $e) {
    pl_threw('writing the Uganda estate', $e);
    pl_done();
}
$ROWS = pl_rows($U_BOOK);
is_($uOk > 0, "the Uganda estate is written through the plugin's own writers, every one accepting its row ({$uOk} acts)");
t('every row of the estate was declared — its side and its button — as it was written',
    [count($ROWS) > 150, array_values(array_diff(array_keys($ROWS), array_keys($DECL))), array_values(array_diff(array_keys($DECL), array_keys($ROWS)))], [true, [], []]);
$SHAPES = ['typed entry, no account', 'CRM pull, no account', 'CRM webhook, no account', 'the adjustment, no account', 'awaiting approval, no account',
    'rejected, no account', 'voided, no account', 'NULL account id', 'opening balance, cash account', 'cash account entry', 'mobile-money account',
    'inactive cash account', 'cash account in USD (account 7)', 'money-changer exchange (FXC), both legs', 'field exchange, no account',
    'cash leg of a transfer', 'bank leg of a transfer', 'transfer cash to mobile money, both cash', 'cash leg of a funding', 'director leg of a funding',
    'bank leg of a funding', 'opening balance, bank account', 'bank import line', 'bank import supplier pair', 'bank to bank, both legs',
    'voided transfer pair, bank leg', 'voided transfer pair, cash leg', 'inactive bank account', 'bank account in a currency the book does not keep',
    'inventory account', 'asset account', 'payable account', 'equity account', 'receivable account', 'director account', "kind 'Bank' with a capital",
    'an id naming no account (orphan)', 'a negative id', "a text id '7abc' beside cash account 7", 'awaiting approval, moved onto a bank account',
    'rejected bank row', 'voided bank row', 'awaiting approval, director account', 'no account, USD', 'bank account, USD', 'formula-like text, not cash',
    'ghost row (amount 0, no SR)', 'voided_reconcile', 'blank currency, older than every UGX row', 'blank currency, newer than every UGX row',
    'search needle, cash', 'search needle, not cash', 'search pin, not on the first button', 'search solo, cash only', 'search away, not cash only',
    'field agent, cash', 'field agent, not cash', 'field accountant, cash', 'field accountant, not cash', 'a cash row (paging)',
    '4g cash', '4g not cash', 'BlueCARD cash', 'BlueCARD not cash', 'yesterday, cash', 'yesterday, not cash', 'today, cash', 'today, not cash'];
$byShape = [];
foreach ($DECL as $id => $x) $byShape[$x['shape']][] = $id;
t('positive control: the estate holds at least one row of each of the ' . count($SHAPES) . ' planted shapes, and no shape it does not name',
    [array_values(array_filter($SHAPES, function ($sh) use ($byShape) { return empty($byShape[$sh]); })), array_values(array_diff(array_keys($byShape), $SHAPES))], [[], []]);
$rowOf = function (string $shape) use ($byShape, $ROWS): array { return $ROWS[$byShape[$shape][0] ?? 0] ?? []; };
$tx = $rowOf("a text id '7abc' beside cash account 7");
t("the text id is stored as text '7abc', beside account 7, a cash account", [$tx['acc_type'] ?? '', (string)($tx['account_id'] ?? ''), $AID['cashD'], $ACC[7][2] ?? ''], ['text', '7abc', 7, 'cash']);
t('the NULL id is stored as NULL; the negative id and the orphan are integers naming no account',
    [$rowOf('NULL account id')['acc_type'] ?? '', $rowOf('a negative id')['account_id'] ?? null, $rowOf('an id naming no account (orphan)')['acc_type'] ?? '',
     isset($ACC[(int)($rowOf('an id naming no account (orphan)')['account_id'] ?? 0)])], ['null', -5, 'integer', false]);
$upd = new \PDO('sqlite:' . $U_BOOK);
$nullable = null;
foreach ($upd->query("SELECT name, \"notnull\" FROM pragma_table_info('cb_ledger')")->fetchAll(\PDO::FETCH_ASSOC) as $c) if ($c['name'] === 'account_id') $nullable = (int)$c['notnull'] === 0;
$upd = null;
is_($nullable === true, 'the estate\'s account_id is nullable after the service was built (pragma_table_info): the NULL case is real');
$ugxRows = array_filter($ROWS, function ($r) { return $r['currency'] === 'UGX'; });
$bo = $rowOf('blank currency, older than every UGX row'); $bn = $rowOf('blank currency, newer than every UGX row');
is_($ugxRows !== [] && !array_filter($ugxRows, function ($r) use ($bo) { return strcmp($r['date'], (string)$bo['date']) <= 0; })
    && !array_filter($ugxRows, function ($r) use ($bn) { return [$r['date'], $r['id']] >= [$bn['date'], $bn['id']]; }),
    'one blank-currency row is older than every UGX row, the other newer (by date, then id)');
$srs = array_map(function ($id) use ($ROWS) { return (string)$ROWS[$id]['sr']; }, array_keys(array_filter($DECL, function ($x) { return $x['side'] !== null; })));
is_(count($srs) === count(array_unique($srs)) && !in_array('', $srs, true), 'every listed row has an SR of its own (the exports are matched row by row on it)');
$bankAcc = array_filter($ACC, function ($a) { return $a[2] === 'bank'; });
t('the accounts: active bank accounts in UGX, USD and a currency the book does not keep, an inactive one with rows and one without, an inactive cash account, kind \'Bank\'',
    [count(array_filter($bankAcc, function ($a) { return $a[3] && $a[1] === 'UGX'; })) >= 2, count(array_filter($bankAcc, function ($a) { return $a[3] && $a[1] === 'USD'; })),
     $ACC[$AID['kes']][3] ?? null, $ACC[$AID['oldBank']][3] ?? null, $ACC[$AID['unused']][3] ?? null, $ACC[$AID['oldCash']][3] ?? null, $ACC[$AID['capB']][2] ?? ''],
    [true, 1, true, false, false, false, 'Bank']);

$X_DIR = $RUN . '/book-nx'; $L_DIR = $RUN . '/book-lg';
try {
    [$X_BOOK, $XDECL, $xOk] = pl_build_nx($X_DIR, $UG);
    is_($xOk > 0, 'the estate without cb_accounts is written with no account at all, and holds no cb_accounts table');
    [$L_BOOK, $LDECL, $lOk] = pl_build_lg($L_DIR, $UG);
    is_($lOk > 0 && count($LDECL) === 26, 'the legacy book: a nullable ledger and a hand-made cb_accounts holding two NULL ids (' . count($LDECL) . ' rows)');
} catch (\Throwable $e) { pl_threw('writing the estate without cb_accounts and the legacy book', $e); }
$OFF = [
    'SS' => ['label' => 'South Sudan (south-sudan; USD,SSP)', 'tz' => 'Africa/Juba', 'phone' => '+211900000981',
             'cfg' => ['tenant_profile' => 'south-sudan', 'currency_code' => 'USD', 'currency_symbol' => '$', 'cashbook_base_currency' => 'USD', 'cashbook_currencies' => 'USD,SSP']],
    'SN' => ['label' => 'a South Sudan tenant without SSP (south-sudan; USD)', 'tz' => 'Africa/Juba', 'phone' => '+211900000982',
             'cfg' => ['tenant_profile' => 'south-sudan', 'currency_code' => 'USD', 'currency_symbol' => '$', 'cashbook_base_currency' => 'USD', 'cashbook_currencies' => 'USD']],
    'UP' => ['label' => 'a Uganda tenant with SSP (uganda; UGX,USD,SSP)', 'tz' => 'Africa/Kampala', 'phone' => '+256700000983',
             'cfg' => ['tenant_profile' => 'uganda', 'currency_code' => 'UGX', 'currency_symbol' => 'UGX', 'cashbook_base_currency' => 'UGX', 'cashbook_currencies' => 'UGX,USD,SSP']],
];
$OFFB = [];
foreach ($OFF as $tag => $B) {
    date_default_timezone_set($B['tz']);
    try {
        [$book, $curs, $n] = pl_build_off($RUN . '/book-' . strtolower($tag), $B['cfg'] + ['timezone' => $B['tz']]);
        $OFFB[$tag] = [$book, $curs];
        is_($n > 0, "[{$tag}] {$B['label']}: written through the plugin's own writers ({$n} acts)");
    } catch (\Throwable $e) { pl_threw("writing book {$tag}", $e); }
}
date_default_timezone_set('Africa/Kampala');
$svcAt = function (string $dir): CashbookService { putenv('DN_VAULT_FILE=' . $dir . '-vault.json'); return new CashbookService(SqliteStore::create($dir), $dir); };
$cbU = $svcAt($U_DIR);
is_($cbU->cashOnlyRuleInForce() === true, 'control: rule A is in force on the Uganda estate (the service\'s own gate)');
$hasView = class_exists('CashbookNonCashView') && method_exists($cbU, 'nonCashAccounts') && method_exists($cbU, 'accountLabels');
is_($hasView, 'the tree under test holds CashbookNonCashView, nonCashAccounts() and accountLabels()');

// ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════
echo "\nA. Partition: every listed row on exactly one side, in the no-key order\n";
$FSETS = ['none' => [], 'direction in' => ['direction' => 'in'], 'direction out' => ['direction' => 'out'], 'category' => ['category' => 'Receipt'],
          'search' => ['search' => 'RH-Needle'], 'dates' => ['date_from' => $d(170), 'date_to' => $d(90)], 'status' => ['status' => 'pending_approval'],
          'validation status' => ['validation_status' => 'online']];
$maxN = 0;
try {
    foreach (['dishnet', '4g', 'bluecard', ''] as $p) {
        foreach ($VIEWS as $v => $vl) {
            $bad = ['part' => [], 'order' => [], 'count' => [], 'oracle' => [], 'side' => []]; $nAll = 0;
            foreach ($FSETS as $fl => $fs) {
                $f = array_filter(['project' => $p, 'currency' => $v] + $fs, function ($x) { return $x !== ''; });
                $all = pl_ids($cbU, $f); $cash = pl_ids($cbU, $f + ['book' => 'cash']); $non = pl_ids($cbU, $f + ['book' => 'noncash']);
                $nAll += count($all); $maxN = max($maxN, count($all));
                $u = array_merge($cash, $non); sort($u); $a2 = $all; sort($a2);
                if (array_intersect($cash, $non) !== [] || $u !== $a2) $bad['part'][] = $fl;
                if (pl_merge($cash, $non, $ROWS) !== $all) $bad['order'][] = $fl;
                if ($cbU->countFiltered($f + ['book' => 'cash']) !== count($cash) || $cbU->countFiltered($f + ['book' => 'noncash']) !== count($non)
                    || $cbU->countFiltered($f) !== count($all)) $bad['count'][] = $fl;
                if (pl_listed($ROWS, $DECL, $f) !== $all) $bad['oracle'][] = $fl;
                // 5.18.98 (weakened copy W2): a split that moves a whole kind of row to the other side still partitions the
                // list, so each side is also held to the rows declared on it as they were written
                if (pl_listed($ROWS, $DECL, $f + ['book' => 'cash']) !== $cash || pl_listed($ROWS, $DECL, $f + ['book' => 'noncash']) !== $non) $bad['side'][] = $fl;
            }
            $sc = ($p !== '' ? $p : 'all projects') . ", {$vl} view";
            is_($bad['part'] === [] && $nAll > 0, "{$sc}: over " . count($FSETS) . " filter sets ({$nAll} rows), ids(cash) ⊎ ids(not cash) = ids(no key), disjoint", 'failed: ' . implode(', ', $bad['part']));
            is_($bad['order'] === [], "{$sc}: each side keeps the no-key order, and the two merged by (date DESC, id DESC) are the no-key list", 'failed: ' . implode(', ', $bad['order']));
            is_($bad['count'] === [], "{$sc}: countFiltered() equals each side's and the no-key list's length", 'failed: ' . implode(', ', $bad['count']));
            is_($bad['oracle'] === [], "{$sc}: control: the fixture's own listing (never read from the SQL) is buildWhere()'s no-key list", 'failed: ' . implode(', ', $bad['oracle']));
            is_($bad['side'] === [], "{$sc}: under every filter set each side holds exactly the rows declared on it as they were written, in order", 'failed: ' . implode(', ', $bad['side']));
        }
    }
    foreach (['dishnet', '4g', 'bluecard', ''] as $p) {
        $bad = []; $nChips = 0;
        foreach (['none' => ['', '', ''], 'search' => ['RH-Needle', '', ''], 'dates' => ['', $d(170), $d(90)]] as $fl => [$q, $fr, $to]) {
            if (!$hasView) { $bad[] = $fl; continue; }
            $chips = CashbookNonCashView::chips($cbU, $p, $q, $fr, $to);
            $nChips = max($nChips, count($chips));
            $f = array_filter(['project' => $p, 'search' => $q, 'date_from' => $fr, 'date_to' => $to]);
            $non = pl_ids($cbU, $f + ['book' => 'noncash']);
            $got = []; $cntBad = false;
            foreach ($chips as $c) {
                $l = pl_ids($cbU, $f + ['book' => 'noncash', 'book_account' => $c['key']]);
                $got = array_merge($got, $l);
                if ($c['count'] !== count($l)) $cntBad = true;
            }
            $g2 = $got; sort($g2); $n2 = $non; sort($n2);
            if (count($got) !== count(array_unique($got)) || $g2 !== $n2 || $cntBad) $bad[] = $fl;
        }
        is_($bad === [] && $nChips > 1, ($p !== '' ? $p : 'all projects') . ": the buttons' rows ⊎ = the not-cash rows, disjoint, each button's count its own list's (no filter, a search, dates; {$nChips} buttons)",
            'failed: ' . implode(', ', $bad));
    }
    is_($maxN > 0 && $maxN < 9999, "every list holds fewer rows than the exports' cap of 9999 (the longest: {$maxN})");
    $blank = array_merge($byShape['blank currency, older than every UGX row'] ?? [], $byShape['blank currency, newer than every UGX row'] ?? []);
    $inUgx = [];
    foreach (['dishnet', ''] as $p) foreach (['', 'cash', 'noncash'] as $bk) $inUgx = array_merge($inUgx, array_intersect($blank, pl_ids($cbU, array_filter(['project' => $p, 'currency' => 'UGX', 'book' => $bk]))));
    t('the blank-currency rows are on no UGX-only list, either side, as today; the All view lists both, on the cash side',
        [array_values(array_unique($inUgx)), count(array_intersect($blank, pl_ids($cbU, ['project' => 'dishnet', 'book' => 'cash']))), count($blank)], [[], 2, 2]);
} catch (\Throwable $e) { pl_threw('section A', $e); }

// ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════
echo "\nB. Shapes: each planted row on its declared side and button\n";
try {
    $bankIds = array_keys($bankAcc);
    $lists = [];
    foreach ($PROJ as $p) {
        $L = ['all' => array_flip(pl_ids($cbU, ['project' => $p])), 'cash' => array_flip(pl_ids($cbU, ['project' => $p, 'book' => 'cash'])),
              'noncash' => array_flip(pl_ids($cbU, ['project' => $p, 'book' => 'noncash'])), 'chips' => []];
        foreach (array_merge($bankIds, ['other']) as $k) $L['chips'][$k] = array_flip(pl_ids($cbU, ['project' => $p, 'book' => 'noncash', 'book_account' => $k]));
        $lists[$p] = $L;
    }
    $res = [];
    foreach ($DECL as $id => $x) {
        $L = $lists[(string)$ROWS[$id]['project']] ?? null;
        $res[$x['shape']] = $res[$x['shape']] ?? ['n' => 0, 'bad' => [], 'chip' => false];
        $res[$x['shape']]['n']++;
        if ($L === null) { $res[$x['shape']]['bad'][] = "#{$id} (no project list)"; continue; }
        $on = [];
        foreach ($L['chips'] as $k => $set) if (isset($set[$id])) $on[] = $k;
        $got = [isset($L['all'][$id]), isset($L['cash'][$id]), isset($L['noncash'][$id]), $on];
        $want = $x['side'] === null ? [false, false, false, []] : ($x['side'] === 'cash' ? [true, true, false, []] : [true, false, true, [$x['chip']]]);
        if ($x['side'] === 'noncash') $res[$x['shape']]['chip'] = true;
        if ($got !== $want) $res[$x['shape']]['bad'][] = "#{$id} " . json_encode($got);
    }
    foreach ($SHAPES as $sh) {
        $r = $res[$sh] ?? ['n' => 0, 'bad' => [], 'chip' => false];
        $side = array_values(array_unique(array_map(function ($id) use ($DECL) { $s = $DECL[$id]['side']; return $s === null ? 'listed nowhere' : ($s === 'cash' ? 'cash' : 'not cash'); }, $byShape[$sh] ?? [])));
        is_($r['n'] > 0 && $r['bad'] === [], "[{$sh}] {$r['n']} row(s): " . implode(' / ', $side) . ($r['chip'] ? ', each on its declared button and no other' : ''), implode('; ', array_slice($r['bad'], 0, 6)));
    }
    foreach (['pending_approval' => 'awaiting approval', 'rejected' => 'rejected', 'voided' => 'voided'] as $st => $w) {
        $ids = array_keys(array_filter($DECL, function ($x, $id) use ($ROWS, $st) { return $x['side'] === 'cash' && $ROWS[$id]['status'] === $st; }, ARRAY_FILTER_USE_BOTH));
        $off = array_filter($ids, function ($id) use ($lists, $ROWS) { $L = $lists[$ROWS[$id]['project']]; return !isset($L['cash'][$id]) || isset($L['noncash'][$id]); });
        is_(count($ids) > 0 && $off === [], "cash rows {$w}: " . count($ids) . ' row(s), every one listed on the cash side (no status term in the split)', json_encode(array_values($off)));
    }
    foreach ($PROJ as $p) {
        foreach ($VIEWS as $v => $vl) {
            $f = array_filter(['project' => $p, 'currency' => $v]);
            t("{$p}, {$vl} view: the cash list is exactly the declared cash rows, newest first", pl_ids($cbU, $f + ['book' => 'cash']), pl_listed($ROWS, $DECL, $f + ['book' => 'cash']));
            t("{$p}, {$vl} view: the not-cash list is exactly the declared not-cash rows, newest first", pl_ids($cbU, $f + ['book' => 'noncash']), pl_listed($ROWS, $DECL, $f + ['book' => 'noncash']));
        }
    }
    foreach (pl_chip_keys($ROWS, $DECL, $ACC, 'dishnet') as $k) {
        $f = ['project' => 'dishnet', 'book' => 'noncash', 'book_account' => $k];
        t("dishnet, button " . json_encode($k) . ': exactly the declared rows of that button', pl_ids($cbU, $f), pl_listed($ROWS, $DECL, $f));
    }
    if ($hasView) {
        $labels = $cbU->accountLabels(); $bad = []; $n = 0; $nf = 0;
        foreach ($PROJ as $p) foreach ($cbU->getEntries(['project' => $p, 'book' => 'noncash', 'limit' => 9999]) as $e) {
            $id = (int)$e['id']; $n++;
            $want = pl_expect_label($ROWS[$id], $ACC);
            if (strpos($want, 'account not found') === 0) $nf++;
            if (CashbookNonCashView::label($e['account_id'] ?? null, $labels) !== $want) $bad[] = "#{$id} " . CashbookNonCashView::label($e['account_id'] ?? null, $labels) . " ≠ {$want}";
        }
        is_($bad === [] && $n > 0 && $nf === 3, "every not-cash row's account reads its own name, masked, or \"account not found (#…)\" for the orphan, the negative and the text id ({$n} rows, {$nf} not found)", implode('; ', array_slice($bad, 0, 4)));
        $chips = CashbookNonCashView::chips($cbU, 'dishnet', '', '', '');
        $first = $chips[0]['key'] ?? null; $sp = (string)$AID['spare'];
        t("selected(): a cb_acct that is not 'other' or a string of digits naming a button gives the first button, never a cast ('{$sp}abc', ' {$sp}', '+{$sp}', '{$sp}.0', an array)",
            array_map(function ($g) use ($chips) { return CashbookNonCashView::selected($chips, $g, false); },
                [['cb_acct' => $sp . 'abc'], ['cb_acct' => ' ' . $sp], ['cb_acct' => '+' . $sp], ['cb_acct' => $sp . '.0'], ['cb_acct' => [$sp]]]),
            array_fill(0, 5, $first));
        t("control: '{$sp}' and 'other' name their buttons, and the first button is the UGX Ecobank account",
            [CashbookNonCashView::selected($chips, ['cb_acct' => $sp], false), CashbookNonCashView::selected($chips, ['cb_acct' => 'other'], false), $first],
            [$AID['spare'], 'other', $AID['bankU']]);
    }
} catch (\Throwable $e) { pl_threw('section B', $e); }

// ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════
echo "\nC. The Balance column: a cash row's running balance is the no-key list's; a not-cash row has none\n";
try {
    $nNonAll = 0;
    foreach (['dishnet', '4g', 'bluecard', ''] as $p) {
        foreach ($VIEWS as $v => $vl) {
            $f = array_filter(['project' => $p, 'currency' => $v]);
            $nk = [];
            foreach ($cbU->getEntries($f + ['limit' => 9999]) as $r) $nk[(int)$r['id']] = [$r['running_balance'] === null ? null : round((float)$r['running_balance'], 2), (string)$r['_bal_currency']];
            $cs = $cbU->getEntries($f + ['book' => 'cash', 'limit' => 9999]);
            $b1 = [];
            foreach ($cs as $r) {
                $id = (int)$r['id'];
                $got = [$r['running_balance'] === null ? null : round((float)$r['running_balance'], 2), (string)$r['_bal_currency']];
                if (!isset($nk[$id]) || $got !== $nk[$id]) $b1[] = "#{$id} " . json_encode($got) . ' vs ' . json_encode($nk[$id] ?? null);
            }
            $b2 = []; $nNon = 0;
            foreach ($nk as $id => $x) if (($DECL[$id]['side'] ?? '') === 'noncash') { $nNon++; if ($x[0] !== null) $b2[] = "#{$id}"; }
            foreach ($cbU->getEntries($f + ['book' => 'noncash', 'limit' => 9999]) as $r) if ($r['running_balance'] !== null) $b2[] = '#' . $r['id'] . ' (tab)';
            $nNonAll += $nNon;
            $exp = pl_streams($ROWS, $DECL, 'UGX', $v, $p); $b3 = [];
            foreach ($nk as $id => $x) if (($exp[$id] ?? null) !== $x) $b3[] = "#{$id} " . json_encode($x) . ' ≠ ' . json_encode($exp[$id] ?? null);
            $sc = ($p !== '' ? $p : 'all projects') . ", {$vl} view";
            is_($b1 === [] && count($cs) > 0, "{$sc}: " . count($cs) . ' cash row(s), each with the running balance and stream the no-key list gives it', implode('; ', array_slice($b1, 0, 4)));
            is_($b2 === [], "{$sc}: {$nNon} not-cash row(s), none with a running balance, on the no-key list or on the tab's", implode('; ', array_slice($b2, 0, 6)));
            is_($b3 === [], "{$sc}: and every balance is the fixture's own running sum of the approved cash rows, per stream", implode('; ', array_slice($b3, 0, 4)));
        }
    }
    is_($nNonAll > 100, "control: {$nNonAll} not-cash rows were read across the views");
} catch (\Throwable $e) { pl_threw('section C', $e); }

// ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════
echo "\nD. Paging: per side and per button, the pages joined are the whole list\n";
try {
    foreach (['dishnet', '4g'] as $p) {
        $sets = ['cash' => ['book' => 'cash'], 'not cash' => ['book' => 'noncash']];
        foreach (pl_chip_keys($ROWS, $DECL, $ACC, $p) as $k) $sets['button ' . json_encode($k)] = ['book' => 'noncash', 'book_account' => $k];
        foreach ($sets as $lbl => $fs) {
            $f = ['project' => $p] + $fs; $bad = []; $n = 0;
            foreach ([7, 50] as $size) {
                $full = pl_ids($cbU, $f); $n = $cbU->countFiltered($f); $tp = (int)ceil($n / $size);
                $joined = []; $last = 0;
                for ($k = 0; $k < $tp; $k++) {
                    $pg = array_map(function ($r) { return (int)$r['id']; }, $cbU->getEntries($f + ['limit' => $size, 'offset' => $k * $size]));
                    $joined = array_merge($joined, $pg); $last = count($pg);
                }
                $beyond = $cbU->getEntries($f + ['limit' => $size, 'offset' => $tp * $size]);
                if ($joined !== $full || $n !== count($full) || ($tp > 0 && $last !== $n - ($tp - 1) * $size) || $beyond !== []) $bad[] = "size {$size}: {$n} rows, {$tp} pages";
            }
            is_($bad === [], "{$p}, {$lbl}: {$n} row(s); at 7 and at 50 a page, the pages joined are the list, the page count is ceil(n / size), the last page holds the rest, and the next is empty",
                implode('; ', $bad));
        }
    }
    is_($cbU->countFiltered(['project' => 'dishnet', 'book' => 'cash']) > 50 && $cbU->countFiltered(['project' => 'dishnet', 'book' => 'noncash', 'book_account' => $AID['bankU']]) > 50,
        'control: the Ledger and the UGX Ecobank button each run to a second page of 50');
    // 5.18.98 (weakened copy W22): a parameter put in the wrong place shows only when other parameters follow it, so the
    // pages are also read under a search, and under a search with dates; and each list is held to the fixture's own,
    // since a list that came back empty would page consistently.
    $DSRCH = ['dishnet' => [['search' => 'RH-U'], ['search' => 'RH-', 'date_from' => $d(170), 'date_to' => $d(90)]],
              '4g'      => [['search' => 'RH-4G'], ['search' => 'RH-', 'date_from' => $d(40), 'date_to' => $d(36)]]];   // each project's own rows
    foreach ($DSRCH as $p => [$fq, $fqd]) {
        foreach (['a search' => $fq, 'a search and dates' => $fqd] as $fl => $ff) {
            $sets = ['cash' => ['book' => 'cash'], 'not cash' => ['book' => 'noncash']];
            foreach (pl_chip_keys($ROWS, $DECL, $ACC, $p) as $k) $sets['button ' . json_encode($k)] = ['book' => 'noncash', 'book_account' => $k];
            $bad = []; $tot = 0;
            foreach ($sets as $lbl => $fs) {
                $f = ['project' => $p] + $ff + $fs;
                $want = pl_listed($ROWS, $DECL, $f);
                $tot += count($want);
                foreach ([7, 50] as $size) {
                    $n = $cbU->countFiltered($f); $tp = (int)ceil($n / $size); $joined = [];
                    for ($k = 0; $k < $tp; $k++) {
                        $joined = array_merge($joined, array_map(function ($r) { return (int)$r['id']; }, $cbU->getEntries($f + ['limit' => $size, 'offset' => $k * $size])));
                    }
                    if ($joined !== $want || $n !== count($want)) $bad[] = "{$lbl} at {$size} a page: {$n} counted, " . count($joined) . ' paged, ' . count($want) . ' declared';
                }
            }
            is_($bad === [] && $tot > 0, "{$p}, under {$fl}: per side and per button (" . count($sets) . " lists, {$tot} rows), the pages joined at 7 and at 50 a page are the fixture's own list, and countFiltered() is its length",
                implode('; ', array_slice($bad, 0, 4)));
        }
    }
    is_($cbU->countFiltered(['project' => 'dishnet', 'search' => 'RH-U', 'book' => 'noncash', 'book_account' => $AID['bankU']]) > 50
        && $cbU->countFiltered(['project' => 'dishnet', 'search' => 'RH-', 'date_from' => $d(170), 'date_to' => $d(90), 'book' => 'cash']) > 7,
        'control: under the search the UGX Ecobank button runs to a second page of 50, and under the search and dates the cash list to a second page of 7');
} catch (\Throwable $e) { pl_threw('section D', $e); }

// ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════
echo "\nE. The gate: wherever rule A is off, the keys change nothing\n";
try {
    $bw = new \ReflectionMethod('CashbookService', 'buildWhere');
    $bw->setAccessible(true);
    $baseF = ['project' => 'dishnet', 'search' => 'RH', 'date_from' => $d(60), 'date_to' => $d(0)];
    $VAR = ['book=cash' => ['book' => 'cash'], 'book=noncash' => ['book' => 'noncash'], 'book_account alone (an id)' => ['book_account' => 2],
            "book_account alone ('other')" => ['book_account' => 'other'], 'book=noncash and an id' => ['book' => 'noncash', 'book_account' => 2],
            "book=noncash and 'other'" => ['book' => 'noncash', 'book_account' => 'other']];
    foreach ($OFFB as $tag => [$book, $curs]) {
        $cb = $svcAt(dirname($book));
        is_($cb->cashOnlyRuleInForce() === false, "[{$tag}] control: rule A is off on this book");
        foreach (['no other filter' => [], 'project, search and dates' => $baseF] as $bl => $bf) {
            $ref = $bw->invoke($cb, $bf); $diff = [];
            foreach ($VAR as $vl => $vf) if ($bw->invoke($cb, $bf + $vf) !== $ref) $diff[] = $vl;
            t("[{$tag}] buildWhere() returns the identical [\$w, \$p] with book, book_account, both or neither ({$bl})", $diff, []);
        }
        $ref = pl_ids($cb, ['project' => 'dishnet']); $refN = $cb->countFiltered(['project' => 'dishnet']); $diff = [];
        foreach ($VAR as $vl => $vf) if (pl_ids($cb, ['project' => 'dishnet'] + $vf) !== $ref || $cb->countFiltered(['project' => 'dishnet'] + $vf) !== $refN) $diff[] = $vl;
        t("[{$tag}] getEntries() and countFiltered() list the same " . count($ref) . ' rows with or without the keys', $diff, []);
    }
    $ref = $bw->invoke($cbU, $baseF);
    $nc2 = $bw->invoke($cbU, $baseF + ['book' => 'noncash', 'book_account' => 2]);
    t('control: on the Uganda estate book=cash and book=noncash each change the WHERE; book_account alone and an unknown book change nothing',
        [$bw->invoke($cbU, $baseF + ['book' => 'cash']) !== $ref, $bw->invoke($cbU, $baseF + ['book' => 'noncash']) !== $ref,
         $bw->invoke($cbU, $baseF + ['book_account' => 2]) === $ref, $bw->invoke($cbU, $baseF + ['book' => 'bogus']) === $ref], [true, true, true, true]);
    t('control: the split\'s clauses come after every other filter\'s, and the button\'s one parameter after the search\'s four',
        [strpos($nc2[0], $ref[0]) === 0, $nc2[1], substr_count($nc2[0], '?') === count($nc2[1])], [true, array_merge($ref[1], [2]), true]);
} catch (\Throwable $e) { pl_threw('section E', $e); }

// The 5.18.97 tree, and the two sandboxes of the Uganda estate (E2, I, J, K).
$OLDT = null; $whyOld = '';
if (isset($opt['base'])) {
    $OLDT = rtrim((string)$opt['base'], '/');
    if (!is_file($OLDT . '/manifest.json')) { $OLDT = null; $whyOld = 'the given --base holds no plugin'; }
} else {
    [$OLDT, $whyOld] = sj_baseline_tree(PL_BASE);
}
$verOf = function (string $tree): string { $m = json_decode((string)@file_get_contents($tree . '/manifest.json'), true); return (string)($m['information']['version'] ?? ''); };
if ($OLDT !== null) is_($verOf($OLDT) === '5.18.97', 'the baseline is 5.18.97, from Git (' . PL_BASE . ')', 'version ' . $verOf($OLDT));
$PH = ['+256700000991', '+256700000992', '+256700000993', '+256700000994'];
$NEW = $OLD = null;
try {
    $NEW = pl_sandbox($root, $UG, 'pl98n', $U_BOOK, $PH);
    if ($OLDT !== null) $OLD = pl_sandbox($OLDT, $UG, 'pl98o', $U_BOOK, $PH);
} catch (\Throwable $e) { pl_threw('starting the sandboxes of the Uganda estate', $e); }
$both = array_values(array_filter([$OLD, $NEW]));

echo "\nE2. The page's configuration says SSP while the service's rule is on (the store copy and the files disagree)\n";
if (!$NEW instanceof SjSandbox) {
    is_(false, 'E2: the sandbox of this tree started');
} else try {
    $kept = [];
    foreach ($both as $s) {
        $kept[$s->root] = $s->store()->load('kyc_config.json') ?: $s->cfg;
        $s->store()->save('kyc_config.json', array_merge($kept[$s->root], ['cashbook_currencies' => 'UGX,USD,SSP']));
    }
    $led = $NEW->page('boss', 'page=dashboard&tab=cashbook&cb_proj=dishnet&cb_view=ledger');
    [, $rd] = $NEW->run('__pl98_read.php');
    $fileCur = (string)(json_decode((string)@file_get_contents($NEW->data . '/kyc_config.json'), true)['cashbook_currencies'] ?? '');
    $storeCur = (string)(json_decode((string)($NEW->q('SELECT data FROM kyc_config WHERE id = 0')[0]['data'] ?? ''), true)['cashbook_currencies'] ?? '');
    t('control: the files say UGX,USD and the service reads them (rule A on); the store copy says UGX,USD,SSP and the page reads it (the two-currency hero)',
        [$fileCur, $storeCur, preg_match('/^RULE (\w+)$/m', $rd, $rm) ? $rm[1] : '', strpos($led, 'UGX BALANCE</div>') !== false], ['UGX,USD', 'UGX,USD,SSP', 'true', true]);
    [$ti] = pl_page_ids($led);
    t('no key: the Ledger lists every listed row, cash and not cash alike, as 5.18.97 does', $ti, array_slice(pl_listed($ROWS, $DECL, ['project' => 'dishnet']), 0, 50));
    t('no tab, no note, no tab body', [strpos($led, PL_TABLBL) !== false, strpos($led, 'cb_view=noncash') !== false, strpos($led, PL_NOTE) !== false, pl_seg($led) !== ''], [false, false, false, false]);
    $csv = pl_csv($NEW->page('boss', 'page=dashboard&tab=cashbook&cb_export=csv&cb_proj=dishnet'));
    t('the CSV: no Entry state column, and every listed row', [in_array('Entry state', $csv[0] ?? [], true), array_column(array_slice($csv, 1), 0)],
        [false, array_map(function ($id) use ($ROWS) { return (string)$ROWS[$id]['sr']; }, pl_listed($ROWS, $DECL, ['project' => 'dishnet']))]);
    if ($OLD instanceof SjSandbox) {
        foreach (['the Ledger' => ['page=dashboard&tab=cashbook&cb_proj=dishnet&cb_view=ledger', true],
                  'cb_view=noncash' => ['page=dashboard&tab=cashbook&cb_proj=dishnet&cb_view=noncash', true],
                  'the CSV' => ['page=dashboard&tab=cashbook&cb_export=csv&cb_proj=dishnet', false],
                  'the CSV asked for with cb_book=noncash&cb_acct=other' => ['page=dashboard&tab=cashbook&cb_export=csv&cb_proj=dishnet&cb_book=noncash&cb_acct=other', false]] as $lbl => [$qs, $isPage]) {
            [$a, $b, $inMin] = pl_pair(function (SjSandbox $s) use ($qs) { return $s->page('boss', $qs); }, $OLD, $NEW, $isPage);
            is_($inMin && $a === $b, "{$lbl}: 5.18.97's, byte for byte (" . strlen($b) . ' bytes)', $a === $b ? 'not read within one minute' : pl_where($a, $b));
        }
    } else {
        pl_skip("E2's comparison with 5.18.97", $whyOld);
    }
    foreach ($both as $s) $s->store()->save('kyc_config.json', $kept[$s->root]);
    $led = $NEW->page('boss', 'page=dashboard&tab=cashbook&cb_proj=dishnet&cb_view=ledger');
    is_(strpos($led, PL_NOTE) !== false && strpos($led, PL_TABLBL) !== false, 'control: the two copies agreeing again, the note and the tab are back');
} catch (\Throwable $e) { pl_threw('section E2', $e); }

// ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════
echo "\nF. No DDL on any read path: an estate without cb_accounts\n";
$XS = null;
try {
    $XROWS = pl_rows($X_BOOK);
    $XS = SjSandbox::start($root, $UG, 'pl98x');
    $nx = pl_copy_book($X_BOOK, $XS->data . '/plugin.sqlite3');
    $XS->staff('boss', ['name' => PL_ADMIN, 'email' => 'rh-admin@example.test', 'role' => 'admin', 'is_admin' => true, 'phone' => '+256700000995']);
    $XS->login('boss', 'rh-admin@example.test', 'sj-password-1');
    $xfile = $XS->data . '/plugin.sqlite3';
    // The store's own first-use work, done before the first reading, touching no cb_ table: the sidebar's Leads badge
    // (includes/navigation.php) loads leads.json, which creates that table; the store's next start indexes it
    // (SqliteStore::create() runs ensureIndexes() on every existing database).
    $XS->page('boss', 'page=dashboard&tab=retailers');
    $mw = pl_master($xfile);
    $XS->store()->load('leads.json');
    $XS->store();
    $m0 = pl_master($xfile);
    t('control: the warm-up created only the store\'s own objects — no cb_ table, no index on one',
        array_values(array_filter(array_column($m0, 'tbl_name'), function ($n) use ($mw) { return strpos($n, 'cb_') === 0 && !in_array($n, array_column($mw, 'tbl_name'), true); })), []);
    t('control: the sandbox holds the copied ledger and no cb_accounts table', [$nx > 0, in_array('cb_ledger', array_column($m0, 'name'), true), in_array('cb_accounts', array_column($m0, 'name'), true)], [true, true, false]);
    $REQ = ['the Ledger' => 'page=dashboard&tab=cashbook&cb_proj=dishnet&cb_view=ledger',
            'the Ledger with a search (its hint)' => 'page=dashboard&tab=cashbook&cb_proj=dishnet&cb_view=ledger&cb_q=RH-Needle',
            'the new tab' => 'page=dashboard&tab=cashbook&cb_proj=dishnet&cb_view=noncash',
            'the new tab on Other accounts, with a search' => 'page=dashboard&tab=cashbook&cb_proj=dishnet&cb_view=noncash&cb_acct=other&cb_q=RH-X',
            'the cash export' => 'page=dashboard&tab=cashbook&cb_export=csv&cb_proj=dishnet',
            "the tab's export" => 'page=dashboard&tab=cashbook&cb_export=csv&cb_proj=dishnet&cb_book=noncash&cb_acct=other'];
    $xp = [];
    foreach ($REQ as $lbl => $qs) {
        $xp[$lbl] = $XS->page('boss', $qs);
        $m = pl_master($xfile);
        t("{$lbl}: sqlite_master, read from a second connection, is as before", $m === $m0 ? 'unchanged' : pl_master_diff($m0, $m), 'unchanged');
    }
    $xc = pl_listed($XROWS, $XDECL, ['project' => 'dishnet', 'book' => 'cash']);
    $xn = pl_listed($XROWS, $XDECL, ['project' => 'dishnet', 'book' => 'noncash']);
    $xsr = function (array $ids) use ($XROWS): array { return array_map(function ($id) use ($XROWS) { return (string)$XROWS[$id]['sr']; }, $ids); };
    [$ti] = pl_page_ids($xp['the Ledger']);
    t('the Ledger is the rule-A page and lists exactly the rows with no account', [strpos($xp['the Ledger'], PL_NOTE) !== false, $ti, count($xc)], [true, $xc, 6]);
    is_(strpos($xp['the Ledger with a search (its hint)'], '1 entry matching &quot;RH-Needle&quot; is under Bank &amp; other accounts') !== false, 'the search hint counts the one not-cash match');
    $sg = pl_seg($xp['the new tab']);
    preg_match_all('/data-cb-nc-acct="([^"]*)"/', $sg, $km);
    [$tt] = pl_page_ids($sg);
    t('the tab offers only "Other accounts", and it holds every row with an account id', [$km[1], $tt, count($xn)], [['other'], $xn, 3]);
    t("the tab's export lists those rows, and the cash export the rows with no account",
        [array_column(array_slice(pl_csv($xp["the tab's export"]), 1), 0), array_column(array_slice(pl_csv($xp['the cash export']), 1), 0)], [$xsr($xn), $xsr($xc)]);
    putenv('DN_VAULT_FILE=' . $XS->vault);
    $cbX = new CashbookService($XS->store(), $XS->data);
    $rd = [pl_ids($cbX, ['project' => 'dishnet', 'book' => 'cash']), pl_ids($cbX, ['project' => 'dishnet', 'book' => 'noncash']),
           pl_ids($cbX, ['project' => 'dishnet', 'book' => 'noncash', 'book_account' => 'other']), pl_ids($cbX, ['project' => 'dishnet', 'book' => 'noncash', 'book_account' => 1]),
           $cbX->countFiltered(['project' => 'dishnet', 'book' => 'cash']), $cbX->countFiltered(['project' => 'dishnet', 'book' => 'noncash', 'search' => 'RH-Needle'])];
    $rd[] = $hasView ? [$cbX->nonCashAccounts(), $cbX->accountLabels(), array_column(CashbookNonCashView::chips($cbX, 'dishnet', '', '', ''), 'key')] : null;
    $cbX->cashInHand('UGX', 'dishnet'); $cbX->cashInHand('USD', '');
    $m = pl_master($xfile);
    t('every service read (both sides, both buttons, the counts, nonCashAccounts(), accountLabels(), the buttons, cash in hand): sqlite_master as before',
        $m === $m0 ? 'unchanged' : pl_master_diff($m0, $m), 'unchanged');
    t('the service: cash is the rows with no account; not cash the rest, all on Other accounts; an id names nothing; no accounts, no labels, one button',
        $rd, [$xc, $xn, $xn, [], count($xc), 1, [[], [], ['other']]]);
    $cp = $RUN . '/nx-control';
    mkdir($cp, 0700, true);
    $cst = SqliteStore::create($cp);
    file_put_contents($cp . '/kyc_config.json', json_encode($UG + ['data_dir' => $cp]));
    pl_copy_book($X_BOOK, $cp . '/plugin.sqlite3');
    putenv('DN_VAULT_FILE=' . $cp . '-vault.json');
    $ccb = new CashbookService($cst, $cp);
    $c0 = pl_master($cp . '/plugin.sqlite3');
    $ccb->accounts();
    $c1 = pl_master($cp . '/plugin.sqlite3');
    t('control: on a copy of this estate one call of accounts() — the DDL a read path must never run — changes sqlite_master, and the reading sees it',
        [$c0 !== $c1, in_array('cb_accounts', array_column($c0, 'name'), true), in_array('cb_accounts', array_column($c1, 'name'), true)], [true, false, true]);
} catch (\Throwable $e) { pl_threw('section F', $e); }
pl_stop($XS);

// ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════
echo "\nG. A hand-made cb_accounts with a NULL id: every listed row on exactly one side\n";
try {
    $cbL = $svcAt($L_DIR);
    $LROWS = pl_rows($L_BOOK);
    $lp = new \PDO('sqlite:' . $L_BOOK);
    $nullIds = (int)$lp->query("SELECT COUNT(*) FROM cb_accounts WHERE typeof(id) = 'null'")->fetchColumn();
    // 5.18.98's review (SQL-1, SQL-2): the bank ids no button can select, each stored as the shape it names, and id 5 twice
    $oddBank = $lp->query("SELECT typeof(id) || ':' || CAST(id AS TEXT) FROM cb_accounts WHERE kind = 'bank' AND id IS NOT NULL ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
    $oddRows = $lp->query("SELECT typeof(account_id) || ':' || CAST(account_id AS TEXT) FROM cb_ledger WHERE description LIKE 'RH-LG bank account,%approved' ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN);
    $lp = null;
    t('control: rule A is on, and cb_accounts holds two accounts whose id is NULL (one cash, one bank)', [$cbL->cashOnlyRuleInForce(), $nullIds], [true, 2]);
    t('control: the bank accounts are id 2, a negative id, a real id, a text id and two records with id 5; rows are on each of the odd three (as stored)',
        [$oddBank, $oddRows], [['integer:2', 'integer:5', 'integer:5', 'integer:-3', 'real:6.5', 'text:x9'], ['integer:-3', 'text:x9', 'real:6.5']]);
    $all = pl_ids($cbL, ['project' => 'dishnet']); $cash = pl_ids($cbL, ['project' => 'dishnet', 'book' => 'cash']); $non = pl_ids($cbL, ['project' => 'dishnet', 'book' => 'noncash']);
    $u = array_merge($cash, $non); sort($u); $a2 = $all; sort($a2);
    t('every listed row is on exactly one side: cash ⊎ not cash = the no-key list, disjoint', [count($all), array_values(array_intersect($cash, $non)), $u === $a2], [26, [], true]);
    t('each on its declared side', [$cash, $non], [pl_listed($LROWS, $LDECL, ['project' => 'dishnet', 'book' => 'cash']), pl_listed($LROWS, $LDECL, ['project' => 'dishnet', 'book' => 'noncash'])]);
    if ($hasView) {
        t('nonCashAccounts(): the bank accounts a button can select, each id once (the first of the two records with id 5)',
            array_map(function ($a) { return [$a['id'], $a['name']]; }, $cbL->nonCashAccounts()), [[2, 'RH-L Bank'], [5, 'RH-L Bank Five']]);
        $chips = CashbookNonCashView::chips($cbL, 'dishnet', '', '', '');
        $got = []; $perChip = [];
        foreach ($chips as $c) {
            $l = pl_ids($cbL, ['project' => 'dishnet', 'book' => 'noncash', 'book_account' => $c['key']]);
            $got = array_merge($got, $l);
            if ($l !== pl_listed($LROWS, $LDECL, ['project' => 'dishnet', 'book' => 'noncash', 'book_account' => $c['key']]) || $c['count'] !== count($l)) $perChip[] = $c['key'];
        }
        $g2 = $got; sort($g2); $n2 = $non; sort($n2);
        t('the buttons: the bank accounts that have a whole-number id above 0, each once, then Other accounts; together they hold every not-cash row exactly once',
            [array_column($chips, 'key'), $g2 === $n2, count($got) === count(array_unique($got)), array_values(array_diff($n2, $g2))], [[2, 5, 'other'], true, true, []]);
        t('each button holds exactly the rows declared on it as they were written (a negative, a text and a real bank id under Other accounts), its count its list\'s',
            $perChip, []);
    }
} catch (\Throwable $e) { pl_threw('section G', $e); }

// ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════
echo "\nH. Agreement with cash in hand: {listed ∧ cash side ∧ approved} = {listed ∧ admitted by cashOnlyWhere()}\n";
try {
    $cw = new \ReflectionMethod('CashbookService', 'cashOnlyWhere');
    $cw->setAccessible(true);
    $frag = (string)$cw->invoke($cbU);
    $hp = new \PDO('sqlite:' . $U_BOOK);
    $hp->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $inStream = function (array $r, string $c): bool { $x = $r['currency']; return $c === 'UGX' ? ($x === 'UGX' || $x === '' || $x === null) : $x === $c; };
    $leftUD = [];
    foreach (['dishnet', '4g', 'bluecard', ''] as $p) {
        foreach (['UGX', 'USD'] as $c) {
            $left = [];
            foreach ($cbU->getEntries(array_filter(['project' => $p, 'book' => 'cash']) + ['limit' => 9999]) as $r) {
                $rr = $ROWS[(int)$r['id']];
                if ($rr['status'] === 'approved' && $inStream($rr, $c)) $left[] = (int)$r['id'];
            }
            sort($left);
            if ($p === 'dishnet' && $c === 'UGX') $leftUD = $left;
            $sql = "SELECT id FROM cb_ledger WHERE NOT (amount=0 AND sr='') AND status != 'voided_reconcile'"
                 . ($p !== '' ? ' AND project = ' . $hp->quote($p) : '')
                 . ($c === 'UGX' ? " AND (currency = 'UGX' OR currency IS NULL OR currency = '')" : ' AND currency = ' . $hp->quote($c))
                 . $frag . ' ORDER BY id';
            $right = array_map('intval', $hp->query($sql)->fetchAll(\PDO::FETCH_COLUMN));
            $sum = 0.0;
            foreach ($left as $id) $sum += ($ROWS[$id]['direction'] === 'in' ? 1 : -1) * (float)$ROWS[$id]['amount'];
            $sc = ($p !== '' ? $p : 'all projects') . ", {$c}";
            t("{$sc}: the cash side's approved rows are exactly the rows cashOnlyWhere() admits (" . count($left) . '), and they add up to cashInHand()',
                [$left === $right, count($left) > 0, abs(round($sum, 2) - $cbU->cashInHand($c, $p)) < 0.005], [true, true, true]);
        }
    }
    $ghost = $byShape['ghost row (amount 0, no SR)'][0] ?? 0;
    $adm = array_map('intval', $hp->query('SELECT id FROM cb_ledger WHERE 1 = 1' . $frag)->fetchAll(\PDO::FETCH_COLUMN));
    $blank = array_merge($byShape['blank currency, older than every UGX row'] ?? [], $byShape['blank currency, newer than every UGX row'] ?? []);
    t('control: cashOnlyWhere() alone admits the ghost row (approved, no account) and both blank-currency rows; the ghost is listed on neither side, the blank rows are in the UGX set on both',
        [in_array($ghost, $adm, true), in_array($ghost, pl_ids($cbU, ['project' => 'dishnet']), true), count(array_intersect($blank, $adm)), count(array_intersect($blank, $leftUD))],
        [true, false, 2, 2]);
    $hp = null;
} catch (\Throwable $e) { pl_threw('section H', $e); }

// ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════
echo "\nI. Other callers pass no key: 5.18.97's tree and this one, on one copied Uganda book\n";
if (!$OLD instanceof SjSandbox || !$NEW instanceof SjSandbox) {
    pl_skip('the comparison of the other callers with 5.18.97', $OLDT === null ? $whyOld : 'a sandbox did not start');
} else try {
    $api = function (string $act, string $qs) { return function (SjSandbox $s) use ($act, $qs) { $r = $s->api('boss', 'GET', $act, null, $qs); return $r[0] . ' ' . $r[1]; }; };
    $out = [];
    $out['the evening WhatsApp (cron/cashbook_summary.php): its texts and its own output'] = [function (SjSandbox $s) {
        $n0 = count($s->texts());
        [$rc, $o] = $s->run('cron/cashbook_summary.php');
        $sent = array_map(function ($tx) { return [$tx['instance'] ?? '', $tx['number'] ?? '', $tx['text'] ?? '']; }, array_slice($s->texts(), $n0));
        return json_encode(['rc' => $rc, 'out' => $o, 'sent' => $sent], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }, false];
    $READ = "the maintenance summary's and the daily report's reads, the report's CSV and HTML, and cashInHand(), through each tree's library";
    $out[$READ] = [function (SjSandbox $s) { [$rc, $o] = $s->run('__pl98_read.php'); return $rc . "\n" . (string)preg_replace('/^RULE .*$/m', '', $o); }, false];
    foreach ($VIEWS as $v => $vl) $out["API cashbook_entries, {$vl}"] = [$api('cashbook_entries', '&limit=500' . ($v !== '' ? '&currency=' . $v : '')), false];
    foreach (['UGX', 'USD'] as $c) foreach (['dishnet', '4g'] as $p) $out["API cashbook_ledger, {$c}, {$p}"] = [$api('cashbook_ledger', '&currency=' . $c . '&project=' . $p), false];
    $out['API cashbook_retailer_summary, the whole book'] = [$api('cashbook_retailer_summary', '&date_from=' . $d(500) . '&date_to=' . $d(0)), false];
    $V2 = 'API cashbook_v2_entries, dishnet'; $V2P = 'API cashbook_v2_entries, dishnet, with book=cash&book_account=other in the query';
    $out[$V2] = [$api('cashbook_v2_entries', '&project=dishnet&limit=200'), false];
    $out['API cashbook_v2_entries, dishnet, a search and a status'] = [$api('cashbook_v2_entries', '&project=dishnet&search=RH-Needle&status=approved'), false];
    $out[$V2P] = [$api('cashbook_v2_entries', '&project=dishnet&limit=200&book=cash&book_account=other'), false];
    $out['the landing page (accounts dashboard), as admin'] = [function (SjSandbox $s) { return $s->page('boss', 'page=dashboard&tab=accounts_dashboard'); }, true];
    $out['the CEO page, as admin'] = [function (SjSandbox $s) { return $s->page('boss', 'page=dashboard&tab=ceo_dashboard'); }, true];
    $out['tools/cash_in_hand.php'] = [function (SjSandbox $s) { [$rc, $o] = $s->run('tools/cash_in_hand.php'); return $rc . "\n" . $o; }, false];
    $seenI = [];
    foreach ($out as $lbl => [$get, $isPage]) {
        [$a, $b, $inMin] = pl_pair($get, $OLD, $NEW, $isPage);
        if (!$inMin) { is_(false, "{$lbl}: read from both trees within one minute"); continue; }
        is_($a === $b, "{$lbl} (" . strlen($b) . ' bytes)', $a === $b ? '' : pl_where($a, $b));
        $seenI[$lbl] = $b;
    }
    $wa = json_decode($seenI['the evening WhatsApp (cron/cashbook_summary.php): its texts and its own output'] ?? '', true);
    $waTo = array_map(function ($x) { return (string)($x[1] ?? ''); }, (array)($wa['sent'] ?? []));
    is_(is_array($wa) && in_array(ltrim($PH[0], '+'), $waTo, true) && strpos((string)($wa['sent'][0][2] ?? ''), 'DishNet Cashbook') !== false,
        'control: the evening WhatsApp was sent, the admin among its ' . count($waTo) . ' recipient(s)',
        substr((string)($seenI['the evening WhatsApp (cron/cashbook_summary.php): its texts and its own output'] ?? ''), 0, 600));
    $rj = preg_match('/^\{.*$/m', (string)($seenI[$READ] ?? ''), $rjm) ? json_decode($rjm[0], true) : null;   // after any notice line
    $sides = function (array $rows) use ($DECL): array { $o = []; foreach ($rows as $r) $o[$DECL[(int)$r['id']]['side'] ?? '?'] = true; ksort($o); return array_keys($o); };
    t('control: the readers were run, and both the maintenance and the daily-report reads hold cash and not-cash rows (no key, both trees)',
        [is_array($rj), $sides((array)($rj['maintenance'] ?? [])), $sides(array_merge(...array_values((array)($rj['daily_report']['entries'] ?? [[]])))), isset($rj['cash_in_hand']['UGX|dishnet'])],
        [true, ['cash', 'noncash'], ['cash', 'noncash'], true]);
    if (!is_array($rj)) echo '       the reader said: ' . substr((string)($seenI[$READ] ?? ''), 0, 400) . "\n";
    is_(($seenI[$V2] ?? '') !== '' && ($seenI[$V2] ?? '') === ($seenI[$V2P] ?? 'x'), 'a client cannot pass book or book_account to the API: the same answer with them in the query as without');
    $ent = json_decode((string)substr($seenI['API cashbook_entries, All'] ?? '', 4), true);
    is_(is_array($ent) && count(array_filter((array)($ent['data']['entries'] ?? []), function ($r) use ($DECL) { return ($DECL[(int)$r['id']]['side'] ?? '') === 'noncash'; })) > 0,
        'control: the API answered, listing not-cash rows as before');
    is_(strpos($seenI['the CEO page, as admin'] ?? '', 'CEO Dashboard') !== false && strpos($seenI['the landing page (accounts dashboard), as admin'] ?? '', 'Cash in hand') !== false,
        'control: the landing and CEO pages are the real pages');
    [, $ro] = $NEW->run('__pl98_read.php');
    is_(preg_match('/^RULE true$/m', $ro) === 1, 'control: rule A is in force in the sandbox of this tree');
} catch (\Throwable $e) { pl_threw('section I', $e); }
$t1 = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($lib . '/tests/test_cash_in_hand_p3b.php') . ' --root=' . escapeshellarg($root) . ' 2>&1', $t1, $t1rc);
$t1l = (string)end($t1);
is_($t1rc === 0 && preg_match('/^\d+ passed, 0 failed$/', $t1l) === 1, "T1 (tests/test_cash_in_hand_p3b.php, unedited) passes against this tree: {$t1l}");

// ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════
echo "\nJ. The pages, as an admin, an accountant, a promoted accountant and a field agent\n";
$L   = function (array $f) use ($ROWS, $DECL): array { return pl_listed($ROWS, $DECL, $f); };
$srOf = function (array $ids) use ($ROWS): array { return array_map(function ($id) use ($ROWS) { return (string)$ROWS[$id]['sr']; }, $ids); };
$pager = function (string $html): ?int { return preg_match('/<div class="cb3-pg-info">[0-9,]+–[0-9,]+ of ([0-9,]+)<\/div>/', $html, $m) ? (int)str_replace(',', '', $m[1]) : null; };
$trOf = function (string $html, int $id): string { return preg_match('/<tr [^>]*data-id="' . $id . '"[^>]*>(.*?)<\/tr>/s', $html, $m) ? $m[1] : ''; };
if (!$NEW instanceof SjSandbox) {
    is_(false, 'J: the sandbox of this tree started');
} else try {
    $cbq = function (string $who, string $qs) use ($NEW): string { return $NEW->page($who, 'page=dashboard&tab=cashbook&' . $qs); };
    // ── The Ledger ──
    foreach ($PROJ as $p) {
        foreach ($VIEWS as $v => $vl) {
            $want = $L(array_filter(['project' => $p, 'currency' => $v]) + ['book' => 'cash']);
            $html = $cbq('boss', 'cb_proj=' . $p . '&cb_view=ledger' . ($v !== '' ? '&cb_curr=' . $v : ''));
            [$ti, $ci] = pl_page_ids($html);
            t("the Ledger, {$p}, {$vl} view: its rows, table and cards, are the first page of the cash list, and its pager counts " . count($want),
                [$ti, $ci, $pager($html)], [array_slice($want, 0, 50), array_slice($want, 0, 50), count($want)]);
        }
    }
    $want = $L(['project' => 'dishnet', 'book' => 'cash']);
    [$t2] = pl_page_ids($cbq('boss', 'cb_proj=dishnet&cb_view=ledger&cb_page=2'));
    t('the Ledger, dishnet, page 2: the next 50 of the cash list', $t2, array_slice($want, 50, 50));
    $led = $cbq('boss', 'cb_proj=4g&cb_view=ledger');
    $nq = preg_match('/' . preg_quote(PL_NOTE, '/') . ' Ecobank and other accounts are under <a href="\?([^"]*)"/', $led, $nm) ? $nm[1] : null;
    $nqa = []; if ($nq !== null) parse_str($nq, $nqa);
    t('the note: "Physical cash only…", its link to this project\'s Bank & other accounts', $nqa, ['page' => 'dashboard', 'tab' => 'cashbook', 'cb_proj' => '4g', 'cb_view' => 'noncash']);
    preg_match_all('/<a href="\?([^"]*)"\s+class="cb3-tab[^"]*"/', $led, $navm);
    $navViews = array_map(function ($q) { $a = []; parse_str(html_entity_decode($q), $a); return (string)($a['cb_view'] ?? ''); }, $navm[1]);
    t('the tab: "' . PL_TABLBL . '", once, right after the Ledger', [array_slice($navViews, 0, 2), count(array_keys($navViews, 'noncash', true)), substr_count($led, PL_TABLBL)], [['ledger', 'noncash'], 1, 1]);
    // the grey state tags (Q6)
    foreach (['awaiting approval' => 'awaiting approval', 'rejected' => 'rejected receipt'] as $state => $q) {
        $html = $cbq('boss', 'cb_proj=dishnet&cb_view=ledger&cb_q=' . rawurlencode($q));
        [$ti] = pl_page_ids($html);
        $flagged = array_values(array_filter($ti, function ($id) use ($ROWS) { return in_array($ROWS[$id]['status'], ['pending_approval', 'rejected'], true); }));
        $tagged = array_values(array_filter($ti, function ($id) use ($trOf, $html, $ROWS) {
            return strpos($trOf($html, $id), 'margin-left:4px;">' . PL_STATES[$ROWS[$id]['status']] . '</span>') !== false;
        }));
        t("the Ledger's grey tag reads \"{$state}\" on such a row, in the table and on the card, and on no approved row",
            [count($flagged) > 0, $tagged, preg_match_all('/margin-left:4px;">(?:awaiting approval|rejected)<\/span>/', $html)], [true, $flagged, 2 * count($flagged)]);
    }
    $p1 = $cbq('boss', 'cb_proj=dishnet&cb_view=ledger');
    [$ti] = pl_page_ids($p1);
    $nFlag = count(array_filter($ti, function ($id) use ($ROWS) { return in_array($ROWS[$id]['status'], ['pending_approval', 'rejected'], true); }));
    is_(preg_match_all('/margin-left:4px;">(?:awaiting approval|rejected)<\/span>/', $p1) === 2 * $nFlag, "page 1 of the Ledger: as many tags as awaiting-approval and rejected rows, twice each ({$nFlag})");
    // the hero, as 5.18.97 draws it
    $region = function (string $html): string { $a = strpos($html, '<!-- ══ BALANCE STRIP'); $b = strpos($html, '<div class="cb3-tabs">'); return ($a === false || $b === false || $b < $a) ? '' : substr($html, $a, $b - $a); };
    if ($OLD instanceof SjSandbox) {
        foreach (['dishnet' => '', '4g' => '', 'bluecard' => '', 'dishnet ' => 'USD'] as $p => $v) {
            $p = trim($p);
            $qs = 'page=dashboard&tab=cashbook&cb_proj=' . $p . '&cb_view=ledger' . ($v !== '' ? '&cb_curr=' . $v : '');
            [$a, $b, $inMin] = pl_pair(function (SjSandbox $s) use ($qs, $region) { return $region($s->page('boss', $qs)); }, $OLD, $NEW, false);
            is_($inMin && $a === $b && substr_count($b, 'CASH IN HAND</div>') >= 1, "the hero, {$p}" . ($v !== '' ? ", {$v} view" : '') . ": byte-identical to 5.18.97's (" . strlen($b) . ' bytes, ' . substr_count($b, 'CASH IN HAND</div>') . ' card(s))',
                $a === $b ? 'no card or not within one minute' : pl_where($a, $b));
        }
    } else {
        pl_skip("the hero's comparison with 5.18.97", $whyOld);
    }
    // the search hint
    $hint = function (string $html): ?array {
        if (!preg_match('/<a href="\?([^"]*)" style="color:#334155;font-weight:700;text-decoration:none;">([0-9,]+) entr(y|ies) matching &quot;(.*?)&quot; (is|are) under Bank &amp; other accounts &rarr;<\/a>/', $html, $m)) return null;
        $q = []; parse_str(html_entity_decode($m[1]), $q);
        $n = (int)str_replace(',', '', $m[2]);
        return [$n, $q, ($n === 1) === ($m[3] === 'y' && $m[5] === 'is')];
    };
    foreach ([['RH-Needle', '', '', ''], ['RH-Needle', $d(94), $d(93), ''], ['RH-Needle', '', '', 'USD'], ['RH-Solo', '', '', ''], ['RH-Away', '', '', '']] as [$q, $fr, $to, $cur]) {
        $f = array_filter(['project' => 'dishnet', 'search' => $q, 'date_from' => $fr, 'date_to' => $to]);
        $nn = count($L($f + ['book' => 'noncash']));
        $wantC = $L($f + array_filter(['currency' => $cur]) + ['book' => 'cash']);
        $html = $cbq('boss', http_build_query(array_filter(['cb_proj' => 'dishnet', 'cb_view' => 'ledger', 'cb_q' => $q, 'cb_from' => $fr, 'cb_to' => $to, 'cb_curr' => $cur])));
        [$ti] = pl_page_ids($html);
        $lbl = "\"{$q}\"" . ($fr !== '' ? " from {$fr} to {$to}" : '') . ($cur !== '' ? " in the {$cur} view" : '');
        t("the Ledger searching {$lbl}: " . ($nn > 0 ? "the hint says {$nn} not-cash match(es) and links to the tab with the search and dates only" : 'no hint, there being no not-cash match')
            . '; the list holds the ' . count($wantC) . ' cash match(es)',
            [$hint($html), $ti, $wantC === [] ? strpos($html, '<div class="cb3-empty">') !== false : true],
            [$nn > 0 ? [$nn, array_filter(['page' => 'dashboard', 'tab' => 'cashbook', 'cb_proj' => 'dishnet', 'cb_view' => 'noncash', 'cb_q' => $q, 'cb_from' => $fr, 'cb_to' => $to]), true] : null,
             array_slice($wantC, 0, 50), true]);
    }
    // ── The new tab, within its markers ──
    $chipsOf = function (string $seg): array {
        preg_match_all('/<a href="\?([^"]*)" data-cb-nc-acct="([^"]*)"\s+style="([^"]*)">(.*?) <span style="font-size:11px;font-weight:800;opacity:\.75;">\(([0-9,]+)\)<\/span><\/a>/s', $seg, $m, PREG_SET_ORDER);
        return array_map(function ($x) { return ['key' => $x[2], 'on' => strpos($x[3], 'background:#334155;color:#fff;') !== false,
            'text' => html_entity_decode($x[4], ENT_QUOTES, 'UTF-8'), 'count' => (int)str_replace(',', '', $x[5])]; }, $m);
    };
    $ecoOf = function (string $seg): ?array {
        if (!preg_match('/<a href="\?([^"]*)" style="color:#334155;font-weight:700;">' . preg_quote(PL_LINK, '/') . '<\/a>/', $seg, $m)) return null;
        $q = []; parse_str(html_entity_decode($m[1]), $q); return $q;
    };
    $flagOf = function (string $cur): string { return ['UGX' => "\u{1F1FA}\u{1F1EC}", 'USD' => "\u{1F4B5}"][$cur] ?? "\u{1F4B1}"; };
    $mask = function (string $n): string { return (string)preg_replace_callback('/\d{5,}/', function ($m) { return "\u{2022}\u{2022}\u{2022}\u{2022}" . substr($m[0], -4); }, $n); };
    $moneyOf = function (string $seg): array { preg_match_all('/\b(?:UGX|USD|KES|SSP) [0-9][0-9,]*\.[0-9]{2}\b/', $seg, $m); $o = $m[0]; sort($o); return $o; };
    $wantMoney = function (array $ids) use ($ROWS): array {
        $o = [];
        foreach ($ids as $id) { $c = strtoupper(trim((string)$ROWS[$id]['currency'])); $s = (($c === '' || $c === 'UGX') ? 'UGX' : $c) . ' ' . number_format((float)$ROWS[$id]['amount'], 2); $o[] = $s; $o[] = $s; }
        sort($o); return $o;
    };
    $FORBID = ['⋮', 'cbCrud', 'checkbox', 'cbBulkForm', 'Add Entry', 'cbv2OpenModal', '＋', 'andover', '>Balance', 'Balance</th>', 'cb-row-chk'];
    $offered = function ($k) use ($ACC): bool { return is_int($k) && isset($ACC[$k]) && $ACC[$k][2] === 'bank' && $ACC[$k][3] && in_array($ACC[$k][1], ['UGX', 'USD'], true); };
    $keysD = pl_chip_keys($ROWS, $DECL, $ACC, 'dishnet');
    $wantChips = array_map(function ($k) use ($ACC, $L, $flagOf, $mask) {
        return ['key' => (string)$k, 'text' => $k === 'other' ? 'Other accounts' : $flagOf($ACC[$k][1]) . ' ' . $mask($ACC[$k][0]),
                'count' => count($L(['project' => 'dishnet', 'book' => 'noncash', 'book_account' => $k]))];
    }, $keysD);
    $dflt = pl_seg($cbq('boss', 'cb_proj=dishnet&cb_view=noncash'));
    $ch = $chipsOf($dflt);
    t('the tab, dishnet: one button per bank account that is active or holds rows here, in the book\'s currency order, then Other accounts; each with its masked name and its count',
        array_map(function ($c) { return ['key' => $c['key'], 'text' => $c['text'], 'count' => $c['count']]; }, $ch), $wantChips);
    t('the default button is the first, the UGX Ecobank account', array_values(array_map(function ($c) { return $c['key']; }, array_filter($ch, function ($c) { return $c['on']; }))), [(string)$keysD[0]]);
    t('control: the buttons hold an inactive bank account with rows and one in a currency the book does not keep, and not the inactive one without rows',
        [in_array($AID['oldBank'], $keysD, true), in_array($AID['kes'], $keysD, true), in_array($AID['unused'], $keysD, true)], [true, true, false]);
    foreach ($keysD as $k) {
        $want = $L(['project' => 'dishnet', 'book' => 'noncash', 'book_account' => $k]);
        $html = $cbq('boss', 'cb_proj=dishnet&cb_view=noncash&cb_acct=' . $k);
        $seg = pl_seg($html);
        [$ti, $ci] = pl_page_ids($seg);
        $pg = array_slice($want, 0, 50);
        $sel = array_values(array_map(function ($c) { return $c['key']; }, array_filter($chipsOf($seg), function ($c) { return $c['on']; })));
        $cells = []; $wantCells = [];
        foreach ($pg as $id) {
            preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $trOf($seg, $id), $cm);
            $cells[] = [$cm[1][3] ?? null, $cm[1][8] ?? null];
            $wantCells[] = [pl_expect_label($ROWS[$id], $ACC), htmlspecialchars(PL_STATES[$ROWS[$id]['status']] ?? (string)$ROWS[$id]['status'], ENT_QUOTES, 'UTF-8')];
        }
        preg_match_all('/<th[^>]*>(.*?)<\/th>/s', $seg, $hm);
        $kl = json_encode($k);
        t("the tab, button {$kl}: it is the one selected; its rows, table and cards, are the first page of that button's list; its pager counts " . count($want),
            [$sel, $ti, $ci, $pager($seg)], [[(string)$k], $pg, $pg, count($want)]);
        t("the tab, button {$kl}: each row's Account (masked, or \"account not found\") and its state in words",
            $cells, $wantCells);
        $cardRS = []; $wantRS = [];
        foreach ($pg as $id) {
            preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $trOf($seg, $id), $cm);
            $a = strpos($seg, 'data-id="' . $id . '"');
            $b = $a === false ? false : strpos($seg, '<div class="cb3-card"', $a + 1);
            $c = $a === false ? false : strpos($seg, '<!-- ══ DESKTOP', $a + 1);
            $end = ($b !== false && ($c === false || $b < $c)) ? $b : $c;
            $card = $a === false ? '' : ($end === false ? substr($seg, $a) : substr($seg, $a, $end - $a));
            $ref = (string)($cm[1][7] ?? ''); $src = (string)($cm[1][9] ?? '');
            $cardRS[] = [$ref === '' || strpos($card, '>' . $ref . '<') !== false, $src !== '' && strpos($card, $src) !== false];
            $wantRS[] = [true, true];
        }
        t("the tab, button {$kl}: each phone card carries its row's Ref and Src, as the table prints them (SPEC §2.5)",
            $cardRS, $wantRS);
        t("the tab, button {$kl}: the columns, with no Balance; no balance or total of any kind (every figure is a row's own amount); none of ⋮, the row menu, a checkbox, the bulk form, Add, the handover banner",
            [array_map(function ($h) { return trim(html_entity_decode(strip_tags($h), ENT_QUOTES, 'UTF-8')); }, $hm[1]), $moneyOf($seg), array_values(array_filter($FORBID, function ($w) use ($seg) { return strpos($seg, $w) !== false; }))],
            [['Date', 'SR', 'Particulars', 'Account', 'Category', 'In ↓', 'Out ↑', 'Ref', 'State', 'Src'], $wantMoney($pg), []]);
        t("the tab, button {$kl}: the ECO Bank link (an admin, the gate open)" . ($offered($k) ? ' opens this account\'s lines' : ' carries no account, ECO Bank not offering this one') . '; no sentence',
            [$ecoOf($seg), strpos($seg, PL_SENT) !== false], [['page' => 'dashboard', 'tab' => 'bank'] + ($offered($k) ? ['bk_acct' => (string)$k] : []), false]);
    }
    $p2 = pl_seg($cbq('boss', 'cb_proj=dishnet&cb_view=noncash&cb_acct=' . $AID['bankU'] . '&cb_page=2'));
    [$ti] = pl_page_ids($p2);
    t('the tab, the UGX Ecobank button, page 2: the next 50 of its list', $ti, array_slice($L(['project' => 'dishnet', 'book' => 'noncash', 'book_account' => $AID['bankU']]), 50, 50));
    $spSeg = pl_seg($cbq('boss', 'cb_proj=dishnet&cb_view=noncash&cb_acct=' . $AID['spare']));
    t('a long number in an account name shows as •••• and its last four, on the buttons and in the Account column; the whole number nowhere',
        [strpos($spSeg, '0123456789') !== false, substr_count($spSeg, "\u{2022}\u{2022}\u{2022}\u{2022}6789") > 1], [false, true]);
    $other = pl_seg($cbq('boss', 'cb_proj=dishnet&cb_view=noncash&cb_acct=other'));
    t("Other accounts: the orphan, the negative and the text id read \"account not found\"; the text id never as account 7",
        [strpos($other, 'account not found (#9999)') !== false, strpos($other, 'account not found (#-5)') !== false, strpos($other, 'account not found (#7abc)') !== false,
         strpos($other, htmlspecialchars('RH-Cash – USD')) !== false], [true, true, true, false]);
    $allStates = [];
    foreach ($keysD as $k) {
        $sg = pl_seg($cbq('boss', 'cb_proj=dishnet&cb_view=noncash&cb_acct=' . $k));
        foreach ($L(['project' => 'dishnet', 'book' => 'noncash', 'book_account' => $k]) as $id) {
            if (!preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $trOf($sg, $id), $cm)) continue;
            $allStates[$cm[1][8] ?? ''] = true;
        }
    }
    ksort($allStates);
    t('the states shown on the tab: approved, awaiting approval, rejected and voided — never "pending", which on this page is the Pending tab',
        array_keys($allStates), ['approved', 'awaiting approval', 'rejected', 'voided']);
    foreach (['RH-Pin' => $AID['bankD'], 'RH-Needle' => $AID['bankU']] as $q => $exp) {
        $sg = pl_seg($cbq('boss', 'cb_proj=dishnet&cb_view=noncash&cb_q=' . $q));
        $sel = array_values(array_map(function ($c) { return $c['key']; }, array_filter($chipsOf($sg), function ($c) { return $c['on']; })));
        [$ti] = pl_page_ids($sg);
        t("searching \"{$q}\" with no button chosen: the first button with a match is chosen, and its matches listed",
            [$sel, $ti], [[(string)$exp], $L(['project' => 'dishnet', 'search' => $q, 'book' => 'noncash', 'book_account' => $exp])]);
    }
    foreach (['7abc', (string)$AID['unused'], (string)$AID['cashU'], '+' . $AID['spare'], $AID['spare'] . 'abc'] as $bad) {
        $sg = pl_seg($cbq('boss', 'cb_proj=dishnet&cb_view=noncash&cb_acct=' . rawurlencode($bad)));
        $sel = array_values(array_map(function ($c) { return $c['key']; }, array_filter($chipsOf($sg), function ($c) { return $c['on']; })));
        t("cb_acct={$bad} names no button: the first is shown", $sel, [(string)$keysD[0]]);
    }
    $sq = pl_seg($cbq('boss', 'cb_proj=dishnet&cb_view=noncash&cb_q=RH-Needle&cb_from=' . $d(200) . '&cb_to=' . $d(1)));
    $clr = preg_match('/<a href="\?([^"]*)"\s+style="[^"]*">&#10005; Clear<\/a>/', $sq, $cm) ? $cm[1] : null;
    $clq = []; if ($clr !== null) parse_str($clr, $clq);
    $exq = preg_match('/<a href="\?([^"]*)"\s+style="padding:8px 16px;background:#334155;[^"]*">&darr; CSV<\/a>/', $sq, $em) ? $em[1] : null;
    $exa = []; if ($exq !== null) parse_str(html_entity_decode($exq), $exa);
    t("the tab's controls: \"✕ Clear\" (only once filtered) back to the tab of this project; the export of this button with the dates and the search",
        [strpos($dflt, '&#10005; Clear') !== false, $clq, $exa],
        [false, ['page' => 'dashboard', 'tab' => 'cashbook', 'cb_proj' => 'dishnet', 'cb_view' => 'noncash'],
         ['page' => 'dashboard', 'tab' => 'cashbook', 'cb_export' => 'csv', 'cb_proj' => 'dishnet', 'cb_book' => 'noncash', 'cb_acct' => (string)$AID['bankU'],
          'cb_from' => $d(200), 'cb_to' => $d(1), 'cb_q' => 'RH-Needle']]);
    $keys4 = pl_chip_keys($ROWS, $DECL, $ACC, '4g');
    $s4 = pl_seg($cbq('boss', 'cb_proj=4g&cb_view=noncash'));
    t('the tab, 4g: the buttons of 4g (no inactive account without rows here)', array_map(function ($c) { return $c['key']; }, $chipsOf($s4)), array_map('strval', $keys4));
    // ── Who sees the ECO Bank link ──
    $acSeg = pl_seg($accPage = $cbq('acc', 'cb_proj=dishnet&cb_view=noncash'));
    t('an accountant, not an administrator: the tab, and the sentence instead of the link; control — the page\'s own widened $isAdmin is true for them (Categories is drawn)',
        [$acSeg !== '', $ecoOf($acSeg), strpos($acSeg, PL_SENT) !== false, strpos($accPage, 'Categories') !== false], [true, null, true, true]);
    $NEW->update($NEW->ids['acc'], ['is_admin' => true, 'role' => 'admin']);   // the record says admin; acc's session copy does not
    $pr = pl_seg($cbq('acc', 'cb_proj=dishnet&cb_view=noncash'));
    t('an accountant made admin inside the 300-second session window: still the sentence, as the dispatcher would refuse ECO Bank', [$ecoOf($pr), strpos($pr, PL_SENT) !== false], [null, true]);
    $NEW->login('acc', 'rh-acc@example.test', 'sj-password-1');
    $fr = pl_seg($cbq('acc', 'cb_proj=dishnet&cb_view=noncash'));
    $bankTab = $NEW->page('acc', 'page=dashboard&tab=bank');
    t('after a fresh sign-in: the link, and ECO Bank opens', [$ecoOf($fr), strpos($bankTab, '<div class="cb3-wrap bk-wrap">') !== false],
        [['page' => 'dashboard', 'tab' => 'bank', 'bk_acct' => (string)$AID['bankU']], true]);
    $NEW->update($NEW->ids['acc'], ['is_admin' => false, 'role' => 'accountant']);
    $NEW->login('acc', 'rh-acc@example.test', 'sj-password-1');
    t('control: reverted and signed in again, the sentence is back', $ecoOf(pl_seg($cbq('acc', 'cb_proj=dishnet&cb_view=noncash'))), null);
    // 5.18.98's review (TS-1): the open() half of BankGate::showLink(). The ECO Bank switch off in this sandbox's settings,
    // in the files BankGate reads and in the store copy alike: the admin gets the tab and the sentence, never the link.
    $swFile = (string)file_get_contents($NEW->data . '/kyc_config.json');
    $swStore = $NEW->store()->load('kyc_config.json') ?: $NEW->cfg;
    $swF = json_decode($swFile, true);
    if (!is_array($swF)) throw new \RuntimeException('the sandbox\'s settings file is not JSON');
    file_put_contents($NEW->data . '/kyc_config.json', json_encode(array_merge($swF, ['bank_screen_enabled' => '0'])));
    $NEW->store()->save('kyc_config.json', array_merge($swStore, ['bank_screen_enabled' => '0']));
    try {
        $offSeg = pl_seg($cbq('boss', 'cb_proj=dishnet&cb_view=noncash'));
        $offFile = (string)(json_decode((string)@file_get_contents($NEW->data . '/kyc_config.json'), true)['bank_screen_enabled'] ?? '');
        t('the ECO Bank switch off: an admin gets the tab and the sentence, not the link (BankGate::showLink() asks BankGate::open()); the control — the files say off',
            [$offSeg !== '', $ecoOf($offSeg), strpos($offSeg, PL_SENT) !== false, $offFile], [true, null, true, '0']);
    } finally {
        file_put_contents($NEW->data . '/kyc_config.json', $swFile); $NEW->store()->save('kyc_config.json', $swStore);
    }
    t('control: the switch on again, the admin\'s link is back', $ecoOf(pl_seg($cbq('boss', 'cb_proj=dishnet&cb_view=noncash'))),
        ['page' => 'dashboard', 'tab' => 'bank', 'bk_acct' => (string)$AID['bankU']]);
    foreach (['fa' => 'a field agent', 'fa2' => 'a field accountant'] as $who => $wl) {
        $html = $cbq($who, 'cb_proj=dishnet&cb_view=noncash');
        t("{$wl} sent to cb_view=noncash: the Field Register, and no tab of any kind", [strpos($html, 'Field Register') !== false, pl_seg($html), strpos($html, PL_TABLBL) !== false], [true, '', false]);
    }
} catch (\Throwable $e) { pl_threw('section J', $e); }

// ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════
echo "\nK. The exports\n";
if (!$NEW instanceof SjSandbox) {
    is_(false, 'K: the sandbox of this tree started');
} else try {
    $csvOf = function (SjSandbox $s, string $who, string $qs): array { [$c, $b, $h] = pl_req($s, $who, 'page=dashboard&tab=cashbook&cb_export=csv&' . $qs); return [$c, $b, $h, pl_csv($b)]; };
    $stateOf = function (int $id) use ($ROWS): string { return PL_STATES[$ROWS[$id]['status']] ?? (string)$ROWS[$id]['status']; };
    $vsOld = function (array $rows, array $orows): array {
        $by = []; foreach (array_slice($orows, 1) as $r) $by[$r[0]] = $r;
        $bad = []; foreach (array_slice($rows, 1) as $r) if (($by[$r[0]] ?? null) !== array_slice($r, 0, -1)) $bad[] = $r[0];
        return $bad;
    };
    foreach (['dishnet', '4g'] as $p) {
        foreach ($VIEWS as $v => $vl) {
            $qs = 'cb_proj=' . $p . ($v !== '' ? '&cb_curr=' . $v : '');
            [, , , $rows] = $csvOf($NEW, 'boss', $qs);
            $hdr = $rows[0] ?? []; $data = array_slice($rows, 1);
            $want = $L(array_filter(['project' => $p, 'currency' => $v]) + ['book' => 'cash']);
            t("the cash export, {$p}, {$vl}: its rows are the cash list's, in order; Entry state is its last column, each row's state in words",
                [array_column($data, 0), end($hdr), array_map(function ($r) { return end($r); }, $data), count(array_unique(array_map('count', $rows)))],
                [$srOf($want), 'Entry state', array_map($stateOf, $want), 1]);
            if ($OLD instanceof SjSandbox) {
                [, , , $orows] = $csvOf($OLD, 'boss', $qs);
                $bi = array_search($v === '' ? 'Balance' : "{$v} Balance", $hdr, true);
                $nb = count(array_filter($data, function ($r) use ($bi) { return $bi !== false && ($r[$bi] ?? '') !== ''; }));
                t("the cash export, {$p}, {$vl}: its header is 5.18.97's plus Entry state; every other cell of every row, the {$nb} Balance cells among them, is 5.18.97's for the same row",
                    [$hdr === array_merge($orows[0] ?? [], ['Entry state']), $vsOld($rows, $orows), $nb > 0], [true, [], true]);
            }
        }
    }
    if (!$OLD instanceof SjSandbox) pl_skip("the cash export's comparison with 5.18.97", $whyOld);
    foreach ($VIEWS as $v => $vl) {
        $qs = 'cb_proj=dishnet' . ($v !== '' ? '&cb_curr=' . $v : '');
        [, , , $frows] = $csvOf($NEW, 'fa', $qs);
        $wantF = $L(array_filter(['project' => 'dishnet', 'currency' => $v]) + ['book' => 'cash', 'person' => PL_FIELD]);
        t("a field agent's cash export, {$vl}: their own cash rows only (" . count($wantF) . "), Entry state last and right",
            [array_column(array_slice($frows, 1), 0), end($frows[0]), array_map(function ($r) { return end($r); }, array_slice($frows, 1))],
            [$srOf($wantF), 'Entry state', array_map($stateOf, $wantF)]);
        if ($OLD instanceof SjSandbox) {
            [, , , $ofrows] = $csvOf($OLD, 'fa', $qs);
            t("a field agent's cash export, {$vl}: its header is 5.18.97's plus Entry state; each row, Balance included, is 5.18.97's for the same row",
                [$frows[0] === array_merge($ofrows[0] ?? [], ['Entry state']), $vsOld($frows, $ofrows)], [true, []]);
        }
    }
    [, $fb] = $csvOf($NEW, 'fa', 'cb_proj=dishnet');
    [, $fb2] = $csvOf($NEW, 'fa', 'cb_proj=dishnet&cb_book=noncash&cb_acct=other');
    t("a field agent asking for the tab's export gets their cash export, byte for byte", $fb2 === $fb ? 'the same' : pl_where($fb, $fb2), 'the same');
    [, $gb] = $csvOf($NEW, 'fa2', 'cb_proj=dishnet');
    [, $gb2, , $grows2] = $csvOf($NEW, 'fa2', 'cb_proj=dishnet&cb_book=noncash&cb_acct=' . $AID['bankU']);
    t("a field accountant asking for the tab's export gets their cash export, byte for byte (the cash layout: a Balance column, no Account kind)",
        [$gb2 === $gb, in_array('Balance', $grows2[0] ?? [], true), in_array('Account kind', $grows2[0] ?? [], true)], [true, true, false]);
    // 5.18.98's review (SEC-2): a staff record with no role at all, not an administrator. The page reads it as 'sales'
    // (public.php's default), a field agent, and shows the Field Register; the tab's export refuses it the same way.
    $NEW->staff('nr', ['name' => 'RH-No Role One', 'email' => 'rh-nr@example.test', 'phone' => '+256700000995']);
    $NEW->login('nr', 'rh-nr@example.test', 'sj-password-1');
    $nrPage = $NEW->page('nr', 'page=dashboard&tab=cashbook&cb_proj=dishnet&cb_view=noncash');
    [, $hb] = $csvOf($NEW, 'nr', 'cb_proj=dishnet');
    [, $hb2, , $hrows2] = $csvOf($NEW, 'nr', 'cb_proj=dishnet&cb_book=noncash&cb_acct=other');
    t("a staff record with no role, not an administrator, asking for the tab's export gets their cash export, byte for byte; the control — no role is stored, and the page gives them the Field Register and no tab",
        [array_key_exists('role', $NEW->row($NEW->ids['nr'])), strpos($nrPage, 'Field Register') !== false, pl_seg($nrPage), $hb2 === $hb ? 'the same' : pl_where($hb, $hb2),
         in_array('Account kind', $hrows2[0] ?? [], true), in_array('Balance', $hrows2[0] ?? [], true)],
        [false, true, '', 'the same', false, true]);
    $neut = function (string $s): string { return ($s !== '' && strpos("=+-@\t\r", $s[0]) !== false) ? "'" . $s : $s; };
    $ncCheck = function (string $p, $k, string $qs, string $fileKey, array $want) use ($csvOf, $NEW, $ROWS, $ACC, $srOf, $stateOf, $neut) {
        $day0 = date('Y-m-d');
        [$code, , $hd, $rows] = $csvOf($NEW, 'boss', $qs);
        $day1 = date('Y-m-d');
        $fn = preg_match('/filename="([^"]+)"/', $hd['content-disposition'] ?? '', $fm) ? $fm[1] : '';
        $okName = in_array($fn, ['cashbook-other-accounts-' . strtoupper($p) . '-' . $fileKey . '-' . $day0 . '.csv', 'cashbook-other-accounts-' . strtoupper($p) . '-' . $fileKey . '-' . $day1 . '.csv'], true);
        $bad = [];
        foreach (array_slice($rows, 1) as $i => $r) {
            $id = $want[$i] ?? 0; $row = $ROWS[$id] ?? null;
            if ($row === null || count($r) !== 14) { $bad[] = "row {$i}"; continue; }
            $cur = strtoupper(trim((string)$row['currency'])); $cur = $cur === '' ? 'UGX' : $cur;
            $kind = ($row['acc_type'] === 'integer' && isset($ACC[(int)$row['account_id']])) ? $ACC[(int)$row['account_id']][2] : '';
            $in = $row['direction'] === 'in'; $amtCell = $in ? $r[9] : $r[10]; $other = $in ? $r[10] : $r[9];
            // 5.18.98's review (TS-3): every one of the 14 cells, each text cell neutralised as the rule says
            $got = [$r[0], $r[1], $r[2], $r[3], $r[4], $r[5], $r[6], $r[7], $r[8], is_numeric($amtCell) && abs((float)$amtCell - (float)$row['amount']) < 0.005 && $other === '',
                    $r[11], $r[12], $r[13]];
            $exp = [$neut((string)$row['sr']), $neut((string)$row['date']), $neut(html_entity_decode(pl_expect_label($row, $ACC), ENT_QUOTES, 'UTF-8')), $neut($kind),
                    $neut((string)($row['txn_type'] ?? '')), $neut((string)$row['description']), $neut((string)($row['category'] ?? '')), $neut((string)($row['person'] ?? '')),
                    $neut($cur), true, $neut((string)($row['validation_ref'] ?? '')), $neut($stateOf($id)), $neut((string)($row['source'] ?? 'manual'))];
            if ($got !== $exp) $bad[] = $row['sr'] . ' ' . json_encode($got, JSON_UNESCAPED_UNICODE);
        }
        return [[$code, strpos($hd['content-type'] ?? '', 'text/csv') === 0, $rows[0] ?? [], array_column(array_slice($rows, 1), 0), $okName, $bad, in_array('Balance', $rows[0] ?? [], true)],
                [200, true, PL_NC_HEADER, $srOf($want), true, [], false], $fn];
    };
    foreach ($keysD as $k) {
        $want = $L(['project' => 'dishnet', 'book' => 'noncash', 'book_account' => $k]);
        [$got, $exp, $fn] = $ncCheck('dishnet', $k, 'cb_proj=dishnet&cb_book=noncash&cb_acct=' . $k, is_int($k) ? 'ACCOUNT' . $k : 'OTHER', $want);
        t("the tab's export, button " . json_encode($k) . ": its 14 columns, no Balance; the button's " . count($want) . " rows; each row's account, kind, text (formula-like cells neutralised), amount and state; the file named {$fn}",
            $got, $exp);
    }
    $want = $L(['project' => 'dishnet', 'book' => 'noncash', 'book_account' => $AID['bankU']]);
    [$got, $exp] = $ncCheck('dishnet', $AID['bankU'], 'cb_proj=dishnet&cb_book=noncash', 'ACCOUNT' . $AID['bankU'], $want);
    t("the tab's export with no button named: the first button's (the UGX Ecobank account)", $got, $exp);
    // 5.18.98's review (SQL-3): no cb_proj at all, as a hand-written or bookmarked address carries none. The page reads that
    // as DishNet; the export does the same, and never stops with an error.
    [$got, $exp] = $ncCheck('dishnet', $AID['bankU'], 'cb_book=noncash', 'ACCOUNT' . $AID['bankU'], $want);
    t("the tab's export with no cb_proj: DishNet's, as the page reads it — the first button's rows, the file named for DISHNET", $got, $exp);
    [$got, $exp] = $ncCheck('dishnet', $AID['bankU'], 'cb_proj=dishnet&cb_book=noncash&cb_acct=7abc', 'ACCOUNT' . $AID['bankU'], $want);
    t("the tab's export with cb_acct=7abc: the first button's, never account 7", $got, $exp);
    $wantP = $L(['project' => 'dishnet', 'book' => 'noncash', 'book_account' => $AID['bankD'], 'search' => 'RH-Pin']);
    [$got, $exp] = $ncCheck('dishnet', $AID['bankD'], 'cb_proj=dishnet&cb_book=noncash&cb_q=RH-Pin', 'ACCOUNT' . $AID['bankD'], $wantP);
    t("the tab's export searching \"RH-Pin\" with no button named: the first button with a match, and its matches", $got, $exp);
    $want4 = $L(['project' => '4g', 'book' => 'noncash', 'book_account' => 'other']);
    [$got, $exp] = $ncCheck('4g', 'other', 'cb_proj=4g&cb_book=noncash&cb_acct=other', 'OTHER', $want4);
    t("the tab's export, 4g, Other accounts", $got, $exp);
    [, , , $fx] = $csvOf($NEW, 'boss', 'cb_proj=dishnet&cb_book=noncash&cb_acct=' . $AID['bankD']);
    $frm = array_values(array_filter(array_slice($fx, 1), function ($r) { return ($r[5] ?? '') === "'=RH-U formula(1)"; }));
    t("a formula-like row in the tab's export: its text cells prefixed with ' — Particulars, Person, Type, Category, Ref and Source — its amount a plain number",
        [count($frm), $frm[0][7] ?? null, $frm[0][4] ?? null, $frm[0][6] ?? null, $frm[0][11] ?? null, $frm[0][13] ?? null, $frm[0][10] ?? null],
        [1, "'@RH-Payee", "'=RH-FORMULA-TYPE", "'+RH-Formula category", "'-RH-FORMULA-REF", "'@RH-formula-source", '13']);
    // 5.18.98 (weakened copy W17): the reverse of E2. The export's own copy of the settings has no SSP while the files
    // the service reads do, so the export draws its non-SSP layouts with rule A off. Entry state belongs to rule A, not
    // to a layout: there the export is 5.18.97's, byte for byte, and has no Entry state.
    $keptF = [];
    foreach ($both as $s) {
        $keptF[$s->root] = (string)file_get_contents($s->data . '/kyc_config.json');
        $fc = json_decode($keptF[$s->root], true);
        if (!is_array($fc)) throw new \RuntimeException('the sandbox\'s settings file is not JSON');
        $fc['cashbook_currencies'] = 'UGX,USD,SSP';
        file_put_contents($s->data . '/kyc_config.json', json_encode($fc));
    }
    try {
        [, $rdR] = $NEW->run('__pl98_read.php');
        $storeR = (string)(json_decode((string)($NEW->q('SELECT data FROM kyc_config WHERE id = 0')[0]['data'] ?? ''), true)['cashbook_currencies'] ?? '');
        [, , , $rh] = $csvOf($NEW, 'boss', 'cb_proj=dishnet');
        t('control: the files say UGX,USD,SSP and the service reads them (rule A off); the export\'s own copy says UGX,USD and the export reads it (a non-SSP layout: Account, no SSP column)',
            [preg_match('/^RULE (\w+)$/m', $rdR, $rrm) ? $rrm[1] : '', $storeR, in_array('Account', $rh[0] ?? [], true), in_array('Received SSP', $rh[0] ?? [], true)], ['false', 'UGX,USD', true, false]);
        foreach ($VIEWS as $v => $vl) {
            $qs = 'cb_proj=dishnet' . ($v !== '' ? '&cb_curr=' . $v : '');
            [, , , $rr] = $csvOf($NEW, 'boss', $qs);
            t("rule A off under the export's own copy without SSP, {$vl}: the cash export has no Entry state and lists every listed row, bank rows included, as 5.18.97 does",
                [in_array('Entry state', $rr[0] ?? [], true), array_column(array_slice($rr, 1), 0)], [false, $srOf($L(array_filter(['project' => 'dishnet', 'currency' => $v])))]);
            if ($OLD instanceof SjSandbox) {
                foreach (['' => '', ', asked for the tab\'s export' => '&cb_book=noncash&cb_acct=other'] as $al => $extra) {
                    [$a, $b, $inMin] = pl_pair(function (SjSandbox $s) use ($qs, $extra) { return $s->page('boss', 'page=dashboard&tab=cashbook&cb_export=csv&' . $qs . $extra); }, $OLD, $NEW, false);
                    is_($inMin && $a === $b, "rule A off under the export's own copy without SSP, {$vl}{$al}: 5.18.97's, byte for byte (" . strlen($b) . ' bytes)',
                        $a === $b ? 'not read within one minute' : pl_where($a, $b));
                }
            }
        }
    } finally {
        foreach ($both as $s) file_put_contents($s->data . '/kyc_config.json', $keptF[$s->root]);
    }
    if (!$OLD instanceof SjSandbox) pl_skip("the reverse divergence's comparison with 5.18.97", $whyOld);
    [, , , $rb] = $csvOf($NEW, 'boss', 'cb_proj=dishnet');
    $rb0 = $rb[0] ?? [];
    is_(end($rb0) === 'Entry state', 'control: the settings files put back, the cash export carries Entry state again');
} catch (\Throwable $e) { pl_threw('section K', $e); }
foreach ([$OLD, $NEW] as $s) pl_stop($s);
$OLD = $NEW = null;

// ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════
echo "\nL. South Sudan and the other books where rule A is off: the new tab, a search, a button and the new export as 5.18.97's\n";
if ($OLDT === null) {
    pl_skip('section L', $whyOld);
} else {
    foreach ($OFFB as $tag => [$book, $curs]) {
        $B = $OFF[$tag];
        date_default_timezone_set($B['tz']);
        $o = $n = null;
        try {
            $cfg = $B['cfg'] + ['timezone' => $B['tz']];
            $ph = [$B['phone'], substr($B['phone'], 0, -1) . '4', substr($B['phone'], 0, -1) . '5', substr($B['phone'], 0, -1) . '6'];
            $o = pl_sandbox($OLDT, $cfg, 'pl98lo' . strtolower($tag), $book, $ph);
            $n = pl_sandbox($root, $cfg, 'pl98ln' . strtolower($tag), $book, $ph);
            $q0 = 'page=dashboard&tab=cashbook&cb_proj=dishnet';
            $last = $curs[count($curs) - 1];
            $outs = ['the Ledger' => [$q0 . '&cb_view=ledger', true], 'cb_view=noncash' => [$q0 . '&cb_view=noncash', true],
                     'cb_view=ledger&cb_q=RH-Needle' => [$q0 . '&cb_view=ledger&cb_q=RH-Needle', true],
                     'cb_view=noncash&cb_acct=other' => [$q0 . '&cb_view=noncash&cb_acct=other', true],
                     'cb_view=noncash&cb_acct=other&cb_q=RH-Needle' => [$q0 . '&cb_view=noncash&cb_acct=other&cb_q=RH-Needle', true],
                     'the CSV' => ['page=dashboard&tab=cashbook&cb_export=csv&cb_proj=dishnet', false],
                     'the CSV with cb_book=noncash&cb_acct=other' => ['page=dashboard&tab=cashbook&cb_export=csv&cb_proj=dishnet&cb_book=noncash&cb_acct=other', false],
                     "the CSV in {$last} with cb_book=noncash" => ['page=dashboard&tab=cashbook&cb_export=csv&cb_proj=dishnet&cb_curr=' . $last . '&cb_book=noncash', false]];
            $seenL = [];
            foreach ($outs as $lbl => [$qs, $isPage]) {
                [$a, $b, $inMin] = pl_pair(function (SjSandbox $s) use ($qs) { return $s->page('boss', $qs); }, $o, $n, $isPage);
                is_($inMin && $a === $b, "[{$tag}] {$lbl}: 5.18.97's, byte for byte (" . strlen($b) . ' bytes)', $a === $b ? 'not read within one minute' : pl_where($a, $b));
                $seenL[$lbl] = $b;
            }
            $led = $seenL['the Ledger']; $srch = $seenL['cb_view=ledger&cb_q=RH-Needle']; $csv = $seenL['the CSV with cb_book=noncash&cb_acct=other'];
            t("[{$tag}] control: the real pages — the Ledger lists the bank rows with the others, the search lists both needles, no tab, no hint; the CSV asked for the tab's export is the cash export, bank rows included",
                [strlen($led) > 20000, strpos($led, 'RH-Client One payment') !== false, strpos($led, 'RH statement line') !== false, strpos($srch, 'RH-Needle cash receipt') !== false,
                 strpos($srch, 'RH-Needle bank charge') !== false, strpos($led . $srch . $seenL['cb_view=noncash'], PL_TABLBL) !== false, strpos($led . $srch, 'under Bank &amp; other accounts') !== false,
                 pl_seg($seenL['cb_view=noncash']) . pl_seg($seenL['cb_view=noncash&cb_acct=other']), strpos($csv, 'RH-Needle bank charge') !== false, strpos($csv, 'Entry state') !== false || strpos($csv, 'Account kind') !== false],
                [true, true, true, true, true, false, false, '', true, false]);
            [, $ro] = $n->run('__pl98_read.php');
            is_(preg_match('/^RULE false$/m', $ro) === 1, "[{$tag}] control: rule A is off in the sandbox of this tree");
        } catch (\Throwable $e) { pl_threw("section L, book {$tag}", $e); }
        pl_stop($o); pl_stop($n);
    }
    date_default_timezone_set('Africa/Kampala');
}

// ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════
echo "\nM. The boundary: 5.18.98 (" . PL_REL . ") against the 5.18.97 release commit (" . PL_BASE . "), and this tree against " . PL_REL . "\n";
// 5.18.99 (rewritten on purpose, never weakened): M stated 5.18.98's delta by comparing ec0accd4 with the working tree, so
// any later release's files read as 5.18.98's. 5.18.98's boundary is now measured on its own release commit, ce7ac388,
// from Git — exactly the claim, and exact — and the tree is held to that commit for everything 5.18.98 added or changed.
// Every content check below still reads this tree.
$lsTree = function (string $rev) use ($lib): array {
    $raw = (string)shell_exec('git -C ' . escapeshellarg($lib) . ' ls-tree --full-tree -r -z ' . escapeshellarg($rev) . ' -- dishnet-hybrid-sudan/ 2>/dev/null');
    $out = [];
    foreach (explode("\0", $raw) as $ln) {
        if (preg_match('/^\d+ blob ([0-9a-f]{40})\tdishnet-hybrid-sudan\/(.+)$/s', $ln, $lm)) $out[$lm[2]] = $lm[1];
    }
    return $out;
};
$BASEB = $lsTree(PL_BASE);
$RELB  = $lsTree(PL_REL);
$gitShow = function (string $rel, string $rev = PL_BASE) use ($lib): ?string {
    $o = (string)shell_exec('git -C ' . escapeshellarg($lib) . ' show ' . escapeshellarg($rev . ':dishnet-hybrid-sudan/' . $rel) . ' 2>/dev/null; printf "\\n__RC=%s" "$?"');
    if (!preg_match('/\n__RC=(\d+)$/', $o, $m) || $m[1] !== '0') return null;
    return substr($o, 0, -strlen($m[0]));
};
if (count($BASEB) < 300 || $gitShow('manifest.json') === null || count($RELB) < 300 || $gitShow('manifest.json', PL_REL) === null) {
    pl_skip('section M', 'Git cannot supply ' . PL_BASE . ' and ' . PL_REL);
} else try {
    $blob = function (string $file): ?string { if (!is_file($file)) return null; $c = (string)file_get_contents($file); return sha1('blob ' . strlen($c) . "\0" . $c); };
    $SKIPD = ['tests', 'docs', 'data', '.git', 'prototype', 'dishnet-mikrotik-control-plane', 'node_modules'];
    $top = function (string $rel): string { return explode('/', $rel)[0]; };
    $baseFiles = array_values(array_filter(array_keys($BASEB), function ($r) use ($SKIPD, $top) { return !in_array($top($r), $SKIPD, true); }));
    $curFiles = [];
    $it = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        function ($f) use ($root, $SKIPD, $top) { return !in_array($top(substr($f->getPathname(), strlen($root) + 1)), $SKIPD, true); }));
    foreach ($it as $f) if ($f->isFile()) $curFiles[] = substr($f->getPathname(), strlen($root) + 1);
    $relFiles = array_values(array_filter(array_keys($RELB), function ($r) use ($SKIPD, $top) { return !in_array($top($r), $SKIPD, true); }));
    sort($baseFiles); sort($curFiles); sort($relFiles);
    $added = array_values(array_diff($relFiles, $baseFiles));
    $removed = array_values(array_diff($baseFiles, $relFiles));
    $changed = [];
    foreach ($relFiles as $rel) if (isset($BASEB[$rel]) && $RELB[$rel] !== $BASEB[$rel]) $changed[] = $rel;
    t('5.18.98 (' . PL_REL . ') adds exactly three files: the tab, its export, and their helpers', $added, ['includes/cashbook_noncash_csv.php', 'lib/CashbookNonCashView.php', 'tabs/accounts/cashbook_noncash.php']);
    t('and removes none (' . count($relFiles) . ' files outside tests/ and docs/)', [$removed, count($relFiles) > 300], [[], true]);
    t('outside tests/ and docs/ it changes exactly lib/CashbookService.php, tabs/accounts/cashbook.php, includes/routes.php and manifest.json, besides documents (*.md)',
        array_values(array_filter($changed, function ($r) { return substr($r, -3) !== '.md'; })), ['includes/routes.php', 'lib/CashbookService.php', 'manifest.json', 'tabs/accounts/cashbook.php']);
    $mb = (string)$gitShow('manifest.json');
    $mr = (string)$gitShow('manifest.json', PL_REL);
    $mw = str_replace('    "version": "5.18.97",', '    "version": "5.18.98",', $mb, $mn);
    t('manifest.json is ' . PL_BASE . "'s with one line changed, its version, 5.18.97 → 5.18.98 (in " . PL_REL . ')',
        [$mn, hash('sha256', $mw) === hash('sha256', $mr)], [1, true]);
    // This tree against 5.18.98: it removes none of 5.18.98's files, keeps every file 5.18.98 added or changed exactly as
    // 5.18.98 left it, and moves only the version line of manifest.json (5.18.99; a later release moves this pin on).
    t('this tree removes none of ' . PL_REL . "'s files (" . count($curFiles) . ' outside tests/ and docs/)',
        [array_values(array_diff($relFiles, $curFiles)), count($curFiles) > 300], [[], true]);
    $since = [];
    $held = array_values(array_filter(array_merge($added, array_values(array_diff($changed, ['manifest.json']))),
        function ($r) { return substr($r, -3) !== '.md'; }));   // documents (*.md) are not code, as above
    foreach ($held as $rel) {
        if ($blob($root . '/' . $rel) !== ($RELB[$rel] ?? 'absent')) $since[] = $rel;
    }
    t('every file 5.18.98 added or changed, besides documents, is ' . PL_REL . "'s, byte for byte, in this tree (" . count($held) . ' files)',
        [$since, count($held)], [[], 6]);
    $mt = str_replace('    "version": "5.18.98",', '    "version": "5.18.99",', $mr, $mtn);
    t('manifest.json in this tree is ' . PL_REL . "'s with one line changed, its version, 5.18.98 → 5.18.99",
        [$mtn, hash('sha256', $mt) === hash('sha256', (string)@file_get_contents($root . '/manifest.json'))], [1, true]);
    foreach (['lib/BankGate.php', 'lib/BankStatementStore.php', 'lib/EcobankDetailsCsv.php', 'tabs/accounts/bank.php', 'includes/post/post_bank.php',
              'includes/navigation.php', 'tabs/admin/ceo_dashboard.php', 'tabs/sales/more_menu.php', 'includes/styles.php', 'includes/post/post_cashbook.php'] as $rel) {
        t("{$rel} is byte-identical to " . PL_BASE, [isset($BASEB[$rel]), $blob($root . '/' . $rel) === ($BASEB[$rel] ?? 'absent')], [true, true]);
    }
    $WORDS = ['UPDATE', 'DELETE', 'INSERT', 'CREATE', 'ALTER', 'accounts(', 'ensureAccountsTable'];
    foreach ($added as $rel) {
        $txt = (string)@file_get_contents($root . '/' . $rel);
        t("{$rel}: no UPDATE, DELETE, INSERT, CREATE, ALTER, accounts( or ensureAccountsTable", array_values(array_filter($WORDS, function ($w) use ($txt) { return strpos($txt, $w) !== false; })), []);
    }
    $svcSrc = (string)@file_get_contents($root . '/lib/CashbookService.php');
    $baseSvc = (string)$gitShow('lib/CashbookService.php');
    $method = function (string $src, string $fn): string { return preg_match('/\n    (?:public |private |protected )(?:static )?function ' . $fn . '\(.*?\n    \}\n/s', $src, $m) ? $m[0] : ''; };
    foreach (['listLocationWhere', 'nonCashAccounts', 'accountLabels'] as $fn) {
        $body = $method($svcSrc, $fn);
        t("CashbookService::{$fn}(): present, and it runs no DDL and no write (no accounts(, ensureAccountsTable, CREATE, ALTER, INSERT, UPDATE, DELETE)",
            [$body !== '', array_values(array_filter(array_merge($WORDS, ['->accounts(']), function ($w) use ($body) { return strpos($body, $w) !== false; }))], [true, []]);
    }
    $same = [];
    foreach (['cashOnlyWhere', 'cashInHand', 'getEntries', 'countFiltered', 'countEntries', 'voidEntry', 'getPendingDisbursements', 'approveEntry', 'rejectEntry',
              'addEntry', 'addEntryRaw', 'assignAccount', 'accounts', 'ensureAccountsTable', 'accountsTableExists', 'cashOnlyRuleInForce'] as $fn) {
        $a = $method($baseSvc, $fn); $b = $method($svcSrc, $fn);
        if ($a === '' || $a !== $b) $same[] = $fn;
    }
    t('the service\'s readers of cash in hand, its Balance streams, its counters and its writers are 5.18.97\'s text, byte for byte (16 methods)', $same, []);
    $tabCode = '';
    foreach (token_get_all((string)@file_get_contents($root . '/tabs/accounts/cashbook_noncash.php')) as $tk) {
        if (is_array($tk) && in_array($tk[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
        $tabCode .= is_array($tk) ? $tk[1] : $tk;
    }
    t('the tab\'s code reads no balance: no currencyPositions, balancesByAccount, accountBalance or accountLedger',
        array_values(array_filter(['currencyPositions', 'balancesByAccount', 'accountBalance', 'accountLedger'], function ($w) use ($tabCode) { return strpos($tabCode, $w) !== false; })), []);
    $keep = ['tests/test_cash_in_hand_p3b.php', 'tests/test_cash_in_hand_p3b_south_sudan.php', 'tests/test_cash_in_hand_p3b_mutants.php',
             'tests/test_d1_bank_balance_readers.php', 'tests/test_cashbook_currency.php'];
    foreach (glob($root . '/tests/test_bank_*.php') ?: [] as $f) if (basename($f) !== 'test_bank_boundaries.php') $keep[] = 'tests/' . basename($f);
    t('the tests that must stay green unedited are 5.18.97\'s, byte for byte (' . count($keep) . ' files)',
        array_values(array_filter($keep, function ($rel) use ($BASEB, $blob, $root) { return !isset($BASEB[$rel]) || $blob($root . '/' . $rel) !== $BASEB[$rel]; })), []);
} catch (\Throwable $e) { pl_threw('section M', $e); }

// This run wrote no vault but its own and the sandboxes'.
clearstatcache();
$defAfter = is_file($defVault) ? [hash_file('sha256', $defVault), filemtime($defVault)] : null;
is_($defAfter === $defBefore, 'the default vault beside this checkout is untouched (' . ($defBefore === null ? 'still absent' : 'same bytes and mtime') . ')');
is_(strpos(sys_get_temp_dir(), $OWN) === 0, 'every temporary file of this run lives in its own folder, removed when it ends', sys_get_temp_dir());
pl_done();
