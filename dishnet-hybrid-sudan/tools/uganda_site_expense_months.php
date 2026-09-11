<?php
declare(strict_types=1);
/**
 * uganda_site_expense_months.php — one-shot, guarded entry of the recurring
 * site expense, on the operator's confirmation (2026-09-07):
 *
 *   UGX 1,200,000 per month for July, August and September 2026 — the same
 *   cost as the June entry (CB-6, Site Expense), same category so all four
 *   months line up in the P&L.
 *
 * Each row's date is the day the money was PAID (cash book = cash basis);
 * the month it pays FOR lives in the description. Dates are arguments:
 *
 *   # one date per payment:
 *   php tools/uganda_site_expense_months.php --july=2026-07-01 --aug=2026-08-01 --sep=2026-09-01
 *   # or, if all three were paid together on one day:
 *   php tools/uganda_site_expense_months.php --all=2026-09-05
 *   # add --confirm to execute; without it every form is a dry run
 *
 * SAFE BY DEFAULT:
 *   · without --confirm it is a DRY RUN — prints the exact rows, writes nothing
 *   · refuses dates before 2026-06-15 (no company shillings existed) or in
 *     the future
 *   · refuses to run twice: an active Site Expense row already labeled
 *     "July 2026" (or August/September) aborts the whole run
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/CashbookService.php';
require_once $root . '/lib/currency.php';

const USM_AMOUNT   = 1200000.0;
const USM_CURRENCY = 'UGX';
const USM_CATEGORY = 'Site Expense';
const USM_PERSON   = 'Bhavin Madlani';
const USM_ACTOR    = 'Bhavin Madlani (CLI entry)';
const USM_MIN_DATE = '2026-06-15';
const USM_MONTHS   = ['july' => 'July 2026', 'aug' => 'August 2026', 'sep' => 'September 2026'];

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) $args[$m[1]] = $m[2] ?? '1';
}
$confirm = !empty($args['confirm']);
$all     = trim((string)($args['all'] ?? ''));

$dates = [];
foreach (USM_MONTHS as $key => $label) {
    $d = $all !== '' ? $all : trim((string)($args[$key] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        fwrite(STDERR,
            "Each payment's date is required, in YYYY-MM-DD (missing: --{$key}):\n"
          . "  php tools/uganda_site_expense_months.php --july=2026-07-01 --aug=2026-08-01 --sep=2026-09-01\n"
          . "  php tools/uganda_site_expense_months.php --all=2026-09-05   # all paid the same day\n"
          . "Add --confirm to execute; without it this is a dry run.\n");
        exit(2);
    }
    $dates[$key] = $d;
}

$dataDir = cliDataDir($root);
$store   = SqliteStore::create($dataDir);
$cb      = new CashbookService($store, $dataDir);
$pdo     = $store->getPdo();
$mode    = $confirm ? 'EXECUTE' : 'DRY RUN (no writes — add --confirm to execute)';

echo "══ Site expense · July–September 2026 — {$mode}\n";
echo "   data dir: {$dataDir}\n";
echo "   book base: " . $cb->bookBase() . "\n\n";

if ($cb->bookBase() !== 'UGX') {
    fwrite(STDERR, "ABORT: this tool is for the Uganda (UGX-base) book only — this install books in " . $cb->bookBase() . ".\n");
    exit(1);
}
foreach ($dates as $key => $d) {
    if ($d < USM_MIN_DATE) {
        fwrite(STDERR, "ABORT: --{$key}={$d} is before " . USM_MIN_DATE . " — the company cash box had no shillings yet.\n");
        exit(1);
    }
    if ($d > date('Y-m-d')) {
        fwrite(STDERR, "ABORT: --{$key}={$d} is in the future. Use the day it was actually paid.\n");
        exit(1);
    }
}

// Guard: never twice — each month is labeled in its description
foreach (USM_MONTHS as $key => $label) {
    $n = (int)$pdo->query(
        "SELECT COUNT(*) FROM cb_ledger
         WHERE category = '" . USM_CATEGORY . "' AND direction = 'out'
           AND description LIKE '%" . $label . "%'
           AND status NOT IN ('voided','voided_reconcile')"
    )->fetchColumn();
    if ($n > 0) {
        echo "ABORT: a " . USM_CATEGORY . " row for {$label} is already on the book. Running again would double it. Nothing was changed.\n";
        exit(1);
    }
}

$i = 1;
foreach (USM_MONTHS as $key => $label) {
    $dt = date('d M Y', strtotime($dates[$key]));
    echo "{$i}. Cash OUT · " . USM_CURRENCY . " " . number_format(USM_AMOUNT, 0)
       . " · " . USM_CATEGORY . " · for {$label} · paid {$dates[$key]} ({$dt})\n";
    $i++;
}
echo "   Total: " . USM_CURRENCY . " " . number_format(USM_AMOUNT * 3, 0) . "\n\n";

if (!$confirm) {
    echo "DRY RUN complete — nothing was changed. Check each date reads right in words,\n"
       . "then re-run the same command with --confirm to execute.\n";
    exit(0);
}

foreach (USM_MONTHS as $key => $label) {
    $r = $cb->addEntry([
        'project' => 'dishnet', 'direction' => 'out',
        'amount' => USM_AMOUNT, 'currency' => USM_CURRENCY,
        'category' => USM_CATEGORY, 'category_raw' => USM_CATEGORY,
        'person' => USM_PERSON, 'date' => $dates[$key],
        'description' => 'Site expense for ' . $label . ' — UGX 1,200,000 monthly, paid from company cash',
    ], ['name' => USM_ACTOR], true);
    if (!($r['ok'] ?? false)) { fwrite(STDERR, "ABORT: {$label} entry failed — " . ($r['error'] ?? $r['message'] ?? '?') . "\n"); exit(1); }
    echo "✔ {$label} recorded as {$r['sr']}.\n";
}
echo "\n══ Resulting positions\n";
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
echo "\nDone. Four months of the site cost now line up under " . USM_CATEGORY . ",\n"
   . "one row per month, each dated the day it was paid.\n";
