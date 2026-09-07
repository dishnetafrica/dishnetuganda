<?php
declare(strict_types=1);
/**
 * uganda_opening_correction.php — one-shot, guarded correction of the stray
 * manual USD 10,000 "Opening Balance" row (live sr CB-1), replacing it with
 * a properly typed SHARE-CAPITAL funding pair. Operator-approved facts:
 *
 *   value date 2026-09-06 · nature: equity / share capital (NOT a loan —
 *   nothing is owed back) · contributor: Bhavin Madlani
 *
 * What it does, in order:
 *   1. verify the stray row still matches its known signature exactly
 *   2. VOID it — the row stays on the ledger with the reason welded on
 *   3. seed the standard Uganda accounts if none exist yet
 *   4. find-or-create the equity account "Share Capital – Bhavin (USD)"
 *   5. record USD 10,000 share capital, value date 2026-09-06, into
 *      Ecobank Uganda – USD (--into=bank) or Cash – Uganda – USD (--into=cash)
 *   6. print the resulting per-currency positions
 *
 * SAFE BY DEFAULT:
 *   · without --confirm it is a DRY RUN — prints the exact plan, writes nothing
 *   · it REFUSES to run twice: if share capital is already recorded it stops,
 *     so the USD 10,000 can never become 20,000
 *   · it refuses if the stray row's signature has changed since diagnosis
 *
 * Usage (inside the uCRM container, from the plugin root):
 *   php tools/uganda_opening_correction.php --into=bank            # dry run
 *   php tools/uganda_opening_correction.php --into=bank --confirm  # execute
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/CashbookService.php';
require_once $root . '/lib/currency.php';

const UOC_AMOUNT      = 10000.0;
const UOC_CURRENCY    = 'USD';
const UOC_VALUE_DATE  = '2026-09-06';
const UOC_EQUITY_NAME = 'Share Capital – Bhavin (USD)';
const UOC_BANK_NAME   = 'Ecobank Uganda – USD';
const UOC_CASH_NAME   = 'Cash – Uganda – USD';
const UOC_DESC        = 'Share capital contribution — Bhavin Madlani';
const UOC_ACTOR       = 'Bhavin Madlani (CLI correction)';
const UOC_VOID_REASON = 'Misclassified manual entry — replaced by typed share-capital funding';

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) $args[$m[1]] = $m[2] ?? '1';
}
$confirm = !empty($args['confirm']);
$into    = strtolower((string)($args['into'] ?? ''));
if (!in_array($into, ['bank', 'cash'], true)) {
    fwrite(STDERR,
        "Where did the USD 10,000 physically arrive?\n"
      . "  --into=bank   -> " . UOC_BANK_NAME . "\n"
      . "  --into=cash   -> " . UOC_CASH_NAME . " (created if missing)\n"
      . "Add --confirm to execute; without it this is a dry run.\n");
    exit(2);
}

$dataDir = getenv('DN_DATA_DIR') ?: getDataDir($root);
$store   = SqliteStore::create($dataDir);
$cb      = new CashbookService($store, $dataDir);
$pdo     = $store->getPdo();
$mode    = $confirm ? 'EXECUTE' : 'DRY RUN (no writes — add --confirm to execute)';

echo "══ Uganda opening correction — {$mode}\n";
echo "   data dir: {$dataDir}\n";
echo "   book base: " . $cb->bookBase() . "\n\n";

if ($cb->bookBase() !== 'UGX') {
    fwrite(STDERR, "ABORT: this tool is for the Uganda (UGX-base) book only — this install books in " . $cb->bookBase() . ".\n");
    exit(1);
}

// ── Guard A: share capital must not already exist (no duplicate money, ever)
$already = $pdo->query(
    "SELECT validation_ref, COUNT(*) n, SUM(amount) total FROM cb_ledger
     WHERE source='funding' AND category='Share Capital'
       AND status NOT IN ('voided','voided_reconcile')
     GROUP BY validation_ref"
)->fetchAll(PDO::FETCH_ASSOC);
if ($already) {
    foreach ($already as $r) {
        echo "ABORT: share capital already recorded — ref {$r['validation_ref']}, "
           . "{$r['n']} legs, USD " . number_format((float)$r['total'] / 2, 2) . ".\n";
    }
    echo "Running again would double the money. Nothing was changed.\n";
    exit(1);
}

// ── Guard B: locate the stray row by its exact diagnosed signature
$strays = $pdo->query(
    "SELECT id, sr, date, amount, currency, category, source, status, txn_type, account_id
     FROM cb_ledger
     WHERE source='manual' AND category='Opening Balance' AND direction='in'
       AND currency='" . UOC_CURRENCY . "' AND amount=" . UOC_AMOUNT
)->fetchAll(PDO::FETCH_ASSOC);
$toVoid = null;
$active = array_values(array_filter($strays, fn($r) => !in_array($r['status'], ['voided', 'voided_reconcile'], true)));
if (count($active) > 1) {
    fwrite(STDERR, "ABORT: " . count($active) . " matching stray rows found — expected exactly one. Investigate first.\n");
    exit(1);
}
if (count($active) === 1) {
    $toVoid = $active[0];
    if ((int)$toVoid['account_id'] !== 0 || (string)$toVoid['txn_type'] !== '') {
        fwrite(STDERR, "ABORT: row #{$toVoid['id']} no longer matches the diagnosed shape (account/type set). Investigate first.\n");
        exit(1);
    }
    echo "1. Stray row found: #{$toVoid['id']} {$toVoid['sr']} {$toVoid['date']} "
       . UOC_CURRENCY . " " . number_format(UOC_AMOUNT, 2) . " — will VOID (kept on ledger, reason attached).\n";
} else {
    $voided = count($strays) - count($active);
    echo "1. No active stray row (" . ($voided ? "{$voided} already voided" : "none found") . ") — void step will be skipped.\n";
}

// ── Plan: accounts
$accounts = $cb->accounts();
$byName   = [];
foreach ($accounts as $a) $byName[$a['name']] = $a;
$needSeed = count($accounts) === 0;
echo "2. Standard accounts: " . ($needSeed ? "none exist — will seed the six standard Uganda accounts." : count($accounts) . " account(s) already present — seeding skipped.") . "\n";

$recvName = $into === 'bank' ? UOC_BANK_NAME : UOC_CASH_NAME;
if (isset($byName[$recvName])) {
    $recvNote = ' — exists.';
} elseif ($into === 'bank' && $needSeed) {
    $recvNote = ' — created by seeding.';
} else {
    $recvNote = ' — will create (USD, ' . ($into === 'bank' ? 'bank' : 'cash') . ').';
}
echo "3. Receiving account: {$recvName}{$recvNote}\n";

$needEquity = !isset($byName[UOC_EQUITY_NAME]);
echo "4. Equity account: " . UOC_EQUITY_NAME . ($needEquity ? " — will create (USD, equity)." : " — exists.") . "\n";
echo "5. Will record: USD " . number_format(UOC_AMOUNT, 2) . " share capital, value date " . UOC_VALUE_DATE
   . ", into {$recvName} + " . UOC_EQUITY_NAME . " (two linked legs, typed DIRECTOR_FUNDING, category Share Capital, excluded from P&L by construction).\n\n";

if (!$confirm) {
    echo "DRY RUN complete — nothing was changed. Re-run with --confirm to execute.\n";
    exit(0);
}

// ── Execute
if ($toVoid !== null) {
    $v = $cb->voidEntry((int)$toVoid['id'], UOC_VOID_REASON, UOC_ACTOR);
    if (!($v['ok'] ?? false)) { fwrite(STDERR, "ABORT: void failed — " . ($v['error'] ?? '?') . "\n"); exit(1); }
    echo "✔ Voided row #{$toVoid['id']} ({$toVoid['sr']}).\n";
}

if ($needSeed) {
    $s = $cb->seedStandardAccounts();
    if (!($s['ok'] ?? false)) { fwrite(STDERR, "ABORT: seeding failed — " . ($s['error'] ?? '?') . "\n"); exit(1); }
    echo "✔ Seeded " . count($s['created'] ?? []) . " standard accounts.\n";
    $byName = [];
    foreach ($cb->accounts() as $a) $byName[$a['name']] = $a;
}

if (!isset($byName[$recvName])) {
    $r = $cb->addAccount($recvName, UOC_CURRENCY, $into === 'bank' ? 'bank' : 'cash');
    if (!($r['ok'] ?? false)) { fwrite(STDERR, "ABORT: could not create {$recvName} — " . ($r['error'] ?? '?') . "\n"); exit(1); }
    $byName[$recvName] = $cb->account((int)$r['id']);
    echo "✔ Created {$recvName}.\n";
}

if (!isset($byName[UOC_EQUITY_NAME])) {
    $r = $cb->addAccount(UOC_EQUITY_NAME, UOC_CURRENCY, 'equity');
    if (!($r['ok'] ?? false)) { fwrite(STDERR, "ABORT: could not create equity account — " . ($r['error'] ?? '?') . "\n"); exit(1); }
    $byName[UOC_EQUITY_NAME] = $cb->account((int)$r['id']);
    echo "✔ Created " . UOC_EQUITY_NAME . " (equity — capital, never a payable).\n";
}

$f = $cb->recordFunding(
    (int)$byName[$recvName]['id'],
    (int)$byName[UOC_EQUITY_NAME]['id'],
    UOC_AMOUNT, UOC_VALUE_DATE, UOC_DESC, '', UOC_ACTOR
);
if (!($f['ok'] ?? false)) { fwrite(STDERR, "ABORT: funding failed — " . ($f['error'] ?? '?') . "\n"); exit(1); }
echo "✔ Share capital recorded — ref {$f['ref']} (value date " . UOC_VALUE_DATE . ").\n\n";

echo "══ Resulting positions\n";
foreach ($cb->currencyPositions() as $pos) {
    echo "  {$pos['currency']} POSITION (cash): {$pos['currency']} " . number_format($pos['total'], 2) . "\n";
    foreach ($pos['accounts'] as $a) {
        if (!(float)$a['balance'] && empty($a['active'])) continue;
        echo "    {$a['name']} ({$a['kind']}): {$pos['currency']} " . number_format((float)$a['balance'], 2) . "\n";
    }
    if (abs($pos['unassigned']) > 0.004) {
        echo "    unassigned rows: {$pos['currency']} " . number_format($pos['unassigned'], 2) . "\n";
    }
    foreach ($pos['counterparts'] ?? [] as $a) {
        if (!(float)$a['balance'] && empty($a['active'])) continue;
        $kindLbl = $a['kind'] === 'equity' ? 'capital — not a debt' : $a['kind'];
        echo "    [{$kindLbl}] {$a['name']}: {$pos['currency']} " . number_format((float)$a['balance'], 2) . "\n";
    }
}
echo "\nDone. The voided CB-1 stays visible (struck through) as the audit trail;\n"
   . "the USD 10,000 now lives in {$recvName} with its equity counterpart —\n"
   . "capital, not debt, and invisible to the P&L by construction.\n";
