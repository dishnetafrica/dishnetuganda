<?php
declare(strict_types=1);
/**
 * uganda_capital_cash_rebook.php — one-shot, guarded rebooking to the real
 * story, confirmed by the operator's own June entries (2026-09-07):
 *
 *   USD 10,000 share capital was brought IN CASH on 09 Jun 2026 (the
 *   "09-06-2026" value date is dd-mm — June, not September), exchanged to
 *   UGX 36,550,000 at 3,655 on 15 Jun 2026, and June expenses of
 *   UGX 1,350,000 followed — leaving UGX 35,200,000 in hand today, which
 *   reconciles exactly: 36,550,000 − 150,000 − 1,200,000 = 35,200,000.
 *
 * What the live book says              →  What it should say
 *   FUND-0001: into Ecobank USD,       →  into Cash – Uganda – USD,
 *              dated 2026-09-06            value date 2026-06-09
 *   FXC-0001:  out USD 10,000 (Sept)   →  account transfer Cash USD →
 *              + in-leg edited by hand     Cash – Uganda, USD 10,000 ↔
 *              to UGX 36.55M (15 Jun)      UGX 36,550,000 @ 3,655, 15 Jun
 *   CB-5/CB-6: June expenses           →  kept exactly as they are
 *
 * Steps (every voided leg stays on the ledger with its reason — audit trail):
 *   1. verify the live ledger matches EXACTLY this diagnosed state
 *   2. void the FXC-0001 exchange pair
 *   3. void the FUND-0001 funding pair
 *   4. find-or-create "Cash – Uganda – USD" (cash, USD)
 *   5. record share capital USD 10,000, value date 2026-06-09, into that
 *      cash account + Share Capital – Bhavin (USD)
 *   6. record the conversion as an account transfer: Cash – Uganda – USD →
 *      Cash – Uganda, USD 10,000 ↔ UGX 36,550,000 (money changer @ 3,655),
 *      dated 2026-06-15
 *   7. print the resulting per-currency positions
 *
 * SAFE BY DEFAULT:
 *   · without --confirm it is a DRY RUN — prints the exact plan, writes nothing
 *   · any active ledger row it does not recognise aborts the run untouched
 *   · running twice aborts (the rebooked rows are "unexpected" on pass two)
 *
 * Usage (inside the uCRM container, from the plugin root):
 *   php tools/uganda_capital_cash_rebook.php            # dry run
 *   php tools/uganda_capital_cash_rebook.php --confirm  # execute
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/CashbookService.php';
require_once $root . '/lib/currency.php';

const UCR_AMOUNT      = 10000.0;
const UCR_UGX_AMOUNT  = 36550000.0;
const UCR_OLD_UGX     = 35200000.0;   // the pre-edit in-leg, if the edit was undone
const UCR_RATE        = 3655.0;
const UCR_VALUE_DATE  = '2026-06-09';
const UCR_XFER_DATE   = '2026-06-15';
const UCR_EQUITY_NAME = 'Share Capital – Bhavin (USD)';
const UCR_BANK_NAME   = 'Ecobank Uganda – USD';
const UCR_CASH_USD    = 'Cash – Uganda – USD';
const UCR_CASH_UGX    = 'Cash – Uganda';
const UCR_ACTOR       = 'Bhavin Madlani (CLI rebook)';
const UCR_DESC_FUND   = 'Share capital contribution — Bhavin Madlani (brought in cash)';
const UCR_DESC_XFER   = 'Capital converted to UGX — money changer @ 3,655';
const UCR_VOID_FXC    = 'Rebooked as account transfer — actual conversion 15 Jun 2026 @ 3,655';
const UCR_VOID_FUND   = 'Capital was cash on 09 Jun 2026, not bank — rebooked into ' . UCR_CASH_USD;

// The operator's real June expense rows — recognised and KEPT untouched.
const UCR_KEEP = [
    ['amount' => 150000.0,  'category' => 'Vehicle'],
    ['amount' => 1200000.0, 'category' => 'Site Expense'],
];

$confirm = in_array('--confirm', array_slice($argv, 1), true);
$dataDir = cliDataDir($root);
$store   = SqliteStore::create($dataDir);
$cb      = new CashbookService($store, $dataDir);
$pdo     = $store->getPdo();
$mode    = $confirm ? 'EXECUTE' : 'DRY RUN (no writes — add --confirm to execute)';

echo "══ Uganda capital cash rebook (June story) — {$mode}\n";
echo "   data dir: {$dataDir}\n";
echo "   book base: " . $cb->bookBase() . "\n\n";

if ($cb->bookBase() !== 'UGX') {
    fwrite(STDERR, "ABORT: this tool is for the Uganda (UGX-base) book only — this install books in " . $cb->bookBase() . ".\n");
    exit(1);
}

// ── Accounts we rely on
$byName = [];
foreach ($cb->accounts() as $a) $byName[$a['name']] = $a;
foreach ([UCR_BANK_NAME => ['bank', 'USD'], UCR_EQUITY_NAME => ['equity', 'USD'], UCR_CASH_UGX => ['cash', 'UGX']] as $nm => [$kind, $cur]) {
    $acc = $byName[$nm] ?? null;
    if (!$acc || $acc['kind'] !== $kind || strtoupper($acc['currency']) !== $cur) {
        fwrite(STDERR, "ABORT: account '{$nm}' missing or not {$kind}/{$cur}. Investigate first.\n");
        exit(1);
    }
}
$bankId    = (int)$byName[UCR_BANK_NAME]['id'];
$equityId  = (int)$byName[UCR_EQUITY_NAME]['id'];
$cashUgxId = (int)$byName[UCR_CASH_UGX]['id'];

// ── Guard: every ACTIVE row must be a diagnosed leg or a kept expense
$active = $pdo->query(
    "SELECT id, sr, date, direction, amount, currency, category, account_id, source, validation_ref
     FROM cb_ledger WHERE status NOT IN ('voided','voided_reconcile') ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC);
$fundLegs = []; $fxcOut = []; $fxcIn = []; $kept = []; $strangers = [];
foreach ($active as $r) {
    $amt = (float)$r['amount'];
    if ($r['validation_ref'] === 'FUND-0001' && $r['source'] === 'funding'
        && $r['direction'] === 'in' && $amt === UCR_AMOUNT && $r['currency'] === 'USD'
        && in_array((int)$r['account_id'], [$bankId, $equityId], true)) { $fundLegs[] = $r; continue; }
    if ($r['validation_ref'] === 'FXC-0001' && $r['source'] === 'fx_exchange' && (int)$r['account_id'] === 0) {
        if ($r['direction'] === 'out' && $amt === UCR_AMOUNT && $r['currency'] === 'USD') { $fxcOut[] = $r; continue; }
        if ($r['direction'] === 'in' && $r['currency'] === 'UGX'
            && in_array($amt, [UCR_UGX_AMOUNT, UCR_OLD_UGX], true)) { $fxcIn[] = $r; continue; }
    }
    if ($r['source'] === 'manual' && $r['direction'] === 'out' && $r['currency'] === 'UGX' && (int)$r['account_id'] === 0) {
        foreach (UCR_KEEP as $k) {
            if ($amt === $k['amount'] && $r['category'] === $k['category']) { $kept[] = $r; continue 2; }
        }
    }
    $strangers[] = $r;
}
if ($strangers) {
    fwrite(STDERR, "ABORT: " . count($strangers) . " active row(s) this tool does not recognise — nothing was changed:\n");
    foreach ($strangers as $r) {
        fwrite(STDERR, "  #{$r['id']} {$r['sr']} {$r['date']} {$r['direction']} {$r['currency']} "
            . number_format((float)$r['amount'], 2) . " {$r['category']} acct={$r['account_id']} src={$r['source']} ref={$r['validation_ref']}\n");
    }
    fwrite(STDERR, "Paste this output back so the plan can be adjusted.\n");
    exit(1);
}
if (count($fundLegs) !== 2) {
    fwrite(STDERR, "ABORT: expected the FUND-0001 pair (2 active legs), found " . count($fundLegs) . ". Investigate first.\n");
    exit(1);
}
if (count($fxcOut) !== 1 || count($fxcIn) !== 1) {
    fwrite(STDERR, "ABORT: expected the FXC-0001 pair (1 out + 1 in active leg), found "
        . count($fxcOut) . " out / " . count($fxcIn) . " in. Investigate first.\n");
    exit(1);
}

echo "1. FUND-0001 pair found (into " . UCR_BANK_NAME . ", dated 2026-09-06) — will VOID both legs:\n   '" . UCR_VOID_FUND . "'.\n";
echo "2. FXC-0001 pair found (out USD 10,000 + in UGX " . number_format((float)$fxcIn[0]['amount'], 0)
   . ", the in-leg hand-edited) — will VOID both legs:\n   '" . UCR_VOID_FXC . "'.\n";
echo "3. Kept untouched: " . count($kept) . " real June expense row(s) — "
   . implode(', ', array_map(fn($r) => "{$r['sr']} {$r['category']} UGX " . number_format((float)$r['amount'], 0), $kept)) . ".\n";
echo "4. Cash account: " . UCR_CASH_USD . (isset($byName[UCR_CASH_USD]) ? " — exists." : " — will create (USD, cash).") . "\n";
echo "5. Will record: USD " . number_format(UCR_AMOUNT, 2) . " share capital, value date " . UCR_VALUE_DATE
   . " (09 Jun 2026), into " . UCR_CASH_USD . " + " . UCR_EQUITY_NAME . ".\n";
echo "6. Will record: transfer " . UCR_CASH_USD . " → " . UCR_CASH_UGX . ", USD " . number_format(UCR_AMOUNT, 2)
   . " ↔ UGX " . number_format(UCR_UGX_AMOUNT, 0) . " @ " . number_format(UCR_RATE, 0) . ", date " . UCR_XFER_DATE . " (15 Jun 2026).\n\n";

if (!$confirm) {
    echo "DRY RUN complete — nothing was changed. Re-run with --confirm to execute.\n";
    exit(0);
}

$v = $cb->voidEntry((int)$fxcOut[0]['id'], UCR_VOID_FXC, UCR_ACTOR);
if (!($v['ok'] ?? false) || (int)($v['voided'] ?? 0) !== 2) { fwrite(STDERR, "ABORT: FXC void failed — " . ($v['error'] ?? '?') . "\n"); exit(1); }
echo "✔ Voided the FXC-0001 exchange pair (2 legs).\n";

$v = $cb->voidEntry((int)$fundLegs[0]['id'], UCR_VOID_FUND, UCR_ACTOR);
if (!($v['ok'] ?? false) || (int)($v['voided'] ?? 0) !== 2) { fwrite(STDERR, "ABORT: FUND void failed — " . ($v['error'] ?? '?') . "\n"); exit(1); }
echo "✔ Voided the FUND-0001 funding pair (2 legs).\n";

if (!isset($byName[UCR_CASH_USD])) {
    $r = $cb->addAccount(UCR_CASH_USD, 'USD', 'cash');
    if (!($r['ok'] ?? false)) { fwrite(STDERR, "ABORT: could not create " . UCR_CASH_USD . " — " . ($r['error'] ?? '?') . "\n"); exit(1); }
    $byName[UCR_CASH_USD] = $cb->account((int)$r['id']);
    echo "✔ Created " . UCR_CASH_USD . ".\n";
}
$cashUsdId = (int)$byName[UCR_CASH_USD]['id'];

$f = $cb->recordFunding($cashUsdId, $equityId, UCR_AMOUNT, UCR_VALUE_DATE, UCR_DESC_FUND, '', UCR_ACTOR);
if (!($f['ok'] ?? false)) { fwrite(STDERR, "ABORT: funding failed — " . ($f['error'] ?? '?') . "\n"); exit(1); }
echo "✔ Share capital recorded in cash — ref {$f['ref']} (value date " . UCR_VALUE_DATE . ").\n";

$t = $cb->recordAccountTransfer($cashUsdId, $cashUgxId, UCR_AMOUNT, UCR_UGX_AMOUNT, UCR_XFER_DATE,
    UCR_DESC_XFER, 'Money changer @ 3,655', UCR_ACTOR);
if (!($t['ok'] ?? false)) { fwrite(STDERR, "ABORT: transfer failed — " . ($t['error'] ?? '?') . "\n"); exit(1); }
echo "✔ Conversion recorded as account transfer — ref {$t['ref']} @ " . number_format((float)$t['rate'], 2) . ".\n\n";

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
echo "\nDone. The story the book now tells: USD 10,000 brought in cash on\n"
   . "09 Jun 2026 (share capital), exchanged for UGX 36,550,000 at 3,655 on\n"
   . "15 Jun 2026, June expenses of UGX 1,350,000 — leaving UGX 35,200,000\n"
   . "in hand, matching the cash box. The June expenses stay unassigned for\n"
   . "now; per-account expense mapping arrives with the next phase.\n";
