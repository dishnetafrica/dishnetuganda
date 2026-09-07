<?php
declare(strict_types=1);
/**
 * uganda_registration_expense.php — one-shot, guarded entry of the company
 * registration expense, on the operator's confirmation (2026-09-07):
 *
 *   UGX 2,193,000 (USD 600 @ 3,655) · paid from the company cash box ·
 *   approved by Bhavin Madlani · category Legal Fees / Registration
 *
 * The payment date lives on the receipt, so it is an ARGUMENT, not a guess:
 *
 *   php tools/uganda_registration_expense.php --date=2026-06-20            # dry run
 *   php tools/uganda_registration_expense.php --date=2026-06-20 --confirm  # execute
 *
 * SAFE BY DEFAULT:
 *   · without --confirm it is a DRY RUN — prints the exact row, writes nothing
 *   · refuses a date before 2026-06-15: the company cash box had no
 *     shillings before the June 15 conversion, so an earlier payment was
 *     personal money — that is a funding pair, not this entry
 *   · refuses to run twice (an active Legal Fees row of UGX 2,193,000
 *     already on the book aborts)
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/CashbookService.php';
require_once $root . '/lib/currency.php';

const URE_AMOUNT   = 2193000.0;
const URE_CURRENCY = 'UGX';
const URE_CATEGORY = 'Legal Fees';
const URE_RAW      = 'Registration';
const URE_DESC     = 'Company registration — USD 600 @ 3,655 — paid from company cash, approved by Bhavin Madlani';
const URE_PERSON   = 'Bhavin Madlani';
const URE_ACTOR    = 'Bhavin Madlani (CLI entry)';
const URE_MIN_DATE = '2026-06-15';   // the cash box's first shillings

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) $args[$m[1]] = $m[2] ?? '1';
}
$confirm = !empty($args['confirm']);
$date    = trim((string)($args['date'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    fwrite(STDERR,
        "The payment date from the receipt is required, in YYYY-MM-DD:\n"
      . "  php tools/uganda_registration_expense.php --date=2026-06-20            # dry run\n"
      . "  php tools/uganda_registration_expense.php --date=2026-06-20 --confirm  # execute\n");
    exit(2);
}

$dataDir = getenv('DN_DATA_DIR') ?: getDataDir($root);
$store   = SqliteStore::create($dataDir);
$cb      = new CashbookService($store, $dataDir);
$pdo     = $store->getPdo();
$mode    = $confirm ? 'EXECUTE' : 'DRY RUN (no writes — add --confirm to execute)';

echo "══ Company registration expense — {$mode}\n";
echo "   data dir: {$dataDir}\n";
echo "   book base: " . $cb->bookBase() . "\n\n";

if ($cb->bookBase() !== 'UGX') {
    fwrite(STDERR, "ABORT: this tool is for the Uganda (UGX-base) book only — this install books in " . $cb->bookBase() . ".\n");
    exit(1);
}
if ($date < URE_MIN_DATE) {
    fwrite(STDERR, "ABORT: {$date} is before " . URE_MIN_DATE . " — the company cash box had no shillings yet\n"
        . "(the USD was converted on 15 Jun 2026). A payment before that was personal money:\n"
        . "the company owes it back, which is a funding pair, not this entry. Tell Claude the date.\n");
    exit(1);
}
if ($date > date('Y-m-d')) {
    fwrite(STDERR, "ABORT: {$date} is in the future. Use the receipt's date.\n");
    exit(1);
}

// Guard: never twice
$dupe = $pdo->query(
    "SELECT id, sr, date FROM cb_ledger
     WHERE category = 'Legal Fees' AND direction = 'out' AND amount = " . URE_AMOUNT . "
       AND status NOT IN ('voided','voided_reconcile')"
)->fetchAll(PDO::FETCH_ASSOC);
if ($dupe) {
    foreach ($dupe as $r) {
        echo "ABORT: this expense is already on the book — {$r['sr']} dated {$r['date']}.\n";
    }
    echo "Running again would double it. Nothing was changed.\n";
    exit(1);
}

$dt = date('d M Y', strtotime($date));
echo "Will record: Cash OUT · " . URE_CURRENCY . " " . number_format(URE_AMOUNT, 0)
   . " · " . URE_CATEGORY . " / " . URE_RAW . " · dated {$date} ({$dt})\n";
echo "Description: " . URE_DESC . "\n\n";

if (!$confirm) {
    echo "DRY RUN complete — nothing was changed. Check the date reads right ({$dt}),\n"
       . "then re-run with --confirm to execute.\n";
    exit(0);
}

$r = $cb->addEntry([
    'project' => 'dishnet', 'direction' => 'out',
    'amount' => URE_AMOUNT, 'currency' => URE_CURRENCY,
    'category' => URE_CATEGORY, 'category_raw' => URE_RAW,
    'person' => URE_PERSON, 'date' => $date,
    'description' => URE_DESC,
], ['name' => URE_ACTOR], true);
if (!($r['ok'] ?? false)) { fwrite(STDERR, "ABORT: entry failed — " . ($r['error'] ?? $r['message'] ?? '?') . "\n"); exit(1); }
echo "✔ Recorded as {$r['sr']}.\n\n";

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
$pl = $cb->plByPeriod('dishnet', '2026-01-01', '2026-12-31');
echo "\n2026 P&L (UGX): revenue " . number_format((float)($pl['UGX']['revenue_total'] ?? 0), 0)
   . " · expenses " . number_format((float)($pl['UGX']['expense_total'] ?? 0), 0)
   . " · net " . number_format((float)($pl['UGX']['net'] ?? 0), 0) . "\n";
echo "\nDone. The registration cost sits in the P&L under Legal Fees;\n"
   . "the capital and conversion stay out of it, as they must.\n";
