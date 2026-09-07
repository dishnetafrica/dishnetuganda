<?php
declare(strict_types=1);
/**
 * uganda_fund_leg_repair.php — one-shot, guarded repair of the FUND-0001
 * share-capital pair after its EQUITY leg was hard-deleted from the live
 * ledger (2026-09-07). The bank leg (Ecobank Uganda – USD, +10,000) survived;
 * this recreates the missing counterpart exactly as recordFunding() wrote it,
 * so the books are double-entry again and the equity card shows the capital.
 *
 * SAFE BY DEFAULT:
 *   · without --confirm it is a DRY RUN — prints the plan, writes nothing
 *   · it refuses unless FUND-0001 has EXACTLY ONE active leg (a balanced
 *     pair means nothing is missing; zero legs is a different problem)
 *   · it refuses if the equity account already carries any active row
 *     (recreating then would double the capital)
 *
 * Usage (inside the uCRM container, from the plugin root):
 *   php tools/uganda_fund_leg_repair.php            # dry run
 *   php tools/uganda_fund_leg_repair.php --confirm  # execute
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/CashbookService.php';
require_once $root . '/lib/currency.php';

const UFR_REF         = 'FUND-0001';
const UFR_AMOUNT      = 10000.0;
const UFR_CURRENCY    = 'USD';
const UFR_VALUE_DATE  = '2026-09-06';
const UFR_EQUITY_NAME = 'Share Capital – Bhavin (USD)';
const UFR_DESC        = 'Share capital contribution — Bhavin Madlani — share capital (' . UFR_EQUITY_NAME . ')';
const UFR_ACTOR       = 'Bhavin Madlani (CLI repair)';

$confirm = in_array('--confirm', array_slice($argv, 1), true);
$dataDir = getenv('DN_DATA_DIR') ?: getDataDir($root);
$store   = SqliteStore::create($dataDir);
$cb      = new CashbookService($store, $dataDir);
$pdo     = $store->getPdo();
$mode    = $confirm ? 'EXECUTE' : 'DRY RUN (no writes — add --confirm to execute)';

echo "══ FUND-0001 missing-leg repair — {$mode}\n";
echo "   data dir: {$dataDir}\n";
echo "   book base: " . $cb->bookBase() . "\n\n";

if ($cb->bookBase() !== 'UGX') {
    fwrite(STDERR, "ABORT: this tool is for the Uganda (UGX-base) book only — this install books in " . $cb->bookBase() . ".\n");
    exit(1);
}

// ── Guard A: the pair must be missing exactly one leg
$legs = $pdo->query(
    "SELECT id, sr, date, direction, amount, currency, account_id, txn_type, source, status
     FROM cb_ledger
     WHERE validation_ref = '" . UFR_REF . "' AND source = 'funding'
       AND status NOT IN ('voided','voided_reconcile')"
)->fetchAll(PDO::FETCH_ASSOC);
if (count($legs) === 2) {
    echo "ABORT: " . UFR_REF . " already has both legs — nothing is missing. Nothing was changed.\n";
    exit(1);
}
if (count($legs) !== 1) {
    fwrite(STDERR, "ABORT: expected exactly one surviving leg of " . UFR_REF . ", found " . count($legs) . ". Investigate first.\n");
    exit(1);
}
$leg = $legs[0];
if ((float)$leg['amount'] !== UFR_AMOUNT || $leg['currency'] !== UFR_CURRENCY
    || $leg['direction'] !== 'in' || $leg['txn_type'] !== 'DIRECTOR_FUNDING' || (int)$leg['account_id'] <= 0) {
    fwrite(STDERR, "ABORT: the surviving leg #{$leg['id']} does not match the diagnosed shape. Investigate first.\n");
    exit(1);
}
$bankAcct = $cb->account((int)$leg['account_id']);
echo "1. Surviving leg: #{$leg['id']} {$leg['sr']} {$leg['date']} " . UFR_CURRENCY . " "
   . number_format(UFR_AMOUNT, 2) . " in " . ($bankAcct['name'] ?? ('account #' . $leg['account_id'])) . " — kept as is.\n";

// ── Guard B: the equity account must exist, book USD, and be empty
$equity = null;
foreach ($cb->accounts() as $a) {
    if ($a['name'] === UFR_EQUITY_NAME) { $equity = $a; break; }
}
if (!$equity) {
    fwrite(STDERR, "ABORT: equity account '" . UFR_EQUITY_NAME . "' not found. Investigate first.\n");
    exit(1);
}
if ($equity['kind'] !== 'equity' || strtoupper($equity['currency']) !== UFR_CURRENCY) {
    fwrite(STDERR, "ABORT: '" . UFR_EQUITY_NAME . "' is {$equity['kind']}/{$equity['currency']} — expected equity/USD. Investigate first.\n");
    exit(1);
}
$eqRows = (int)$pdo->query(
    "SELECT COUNT(*) FROM cb_ledger WHERE account_id = " . (int)$equity['id']
  . " AND status NOT IN ('voided','voided_reconcile')"
)->fetchColumn();
if ($eqRows > 0) {
    fwrite(STDERR, "ABORT: the equity account already carries {$eqRows} active row(s) — recreating the leg would double the capital.\n");
    exit(1);
}
echo "2. Equity account: " . UFR_EQUITY_NAME . " (#{$equity['id']}) — empty, will receive the recreated leg.\n";
echo "3. Will recreate: " . UFR_CURRENCY . " " . number_format(UFR_AMOUNT, 2) . " in, value date " . UFR_VALUE_DATE
   . ", category Share Capital, typed DIRECTOR_FUNDING, ref " . UFR_REF . " — byte-matching what recordFunding() wrote.\n\n";

if (!$confirm) {
    echo "DRY RUN complete — nothing was changed. Re-run with --confirm to execute.\n";
    exit(0);
}

$sr = $cb->addEntryRaw([
    'project' => 'dishnet', 'date' => UFR_VALUE_DATE, 'direction' => 'in',
    'amount' => UFR_AMOUNT, 'currency' => UFR_CURRENCY,
    'category' => 'Share Capital', 'category_raw' => 'Share Capital',
    'description' => UFR_DESC,
    'validation_ref' => UFR_REF, 'validation_status' => 'na',
    'status' => 'approved', 'approved_by' => UFR_ACTOR, 'source' => 'funding',
    'account_id' => (int)$equity['id'], 'txn_type' => 'DIRECTOR_FUNDING',
]);
echo "✔ Equity leg recreated as {$sr}.\n\n";

echo "══ Resulting positions\n";
foreach ($cb->currencyPositions() as $pos) {
    echo "  {$pos['currency']} POSITION (cash): {$pos['currency']} " . number_format($pos['total'], 2) . "\n";
    foreach ($pos['accounts'] as $a) {
        if (!(float)$a['balance'] && empty($a['active'])) continue;
        echo "    {$a['name']} ({$a['kind']}): {$pos['currency']} " . number_format((float)$a['balance'], 2) . "\n";
    }
    foreach ($pos['counterparts'] ?? [] as $a) {
        if (!(float)$a['balance'] && empty($a['active'])) continue;
        $kindLbl = $a['kind'] === 'equity' ? 'capital — not a debt' : $a['kind'];
        echo "    [{$kindLbl}] {$a['name']}: {$pos['currency']} " . number_format((float)$a['balance'], 2) . "\n";
    }
}
echo "\nDone. FUND-0001 is double-entry again. Deleting either leg of a linked\n"
   . "pair is now refused by the ledger — corrections go through Void.\n";
