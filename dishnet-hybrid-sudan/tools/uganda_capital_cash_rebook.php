<?php
declare(strict_types=1);
/**
 * uganda_capital_cash_rebook.php — one-shot, guarded rebooking after the
 * operator clarified the real-world story (2026-09-07):
 *
 *   the USD 10,000 share capital was brought IN CASH — not via Ecobank —
 *   and the whole amount was then exchanged to UGX 35,200,000 at 3,520.
 *
 * What the live book says today          →  What it should say
 *   FUND-0001: capital into Ecobank USD  →  capital into Cash – Uganda – USD
 *   FXC-0001:  unassigned USD -10,000 /  →  account transfer Cash USD →
 *              unassigned UGX +35.2M        Cash – Uganda (UGX), rate 3,520
 *
 * Steps (each leg voided stays on the ledger with its reason — audit trail):
 *   1. verify the live ledger matches EXACTLY this diagnosed state
 *   2. void the FXC-0001 exchange pair (if still active)
 *   3. void the FUND-0001 funding pair
 *   4. find-or-create "Cash – Uganda – USD" (cash, USD)
 *   5. record share capital USD 10,000, value date 2026-09-06, into that
 *      cash account + Share Capital – Bhavin (USD)
 *   6. record the conversion as an account transfer: Cash – Uganda – USD →
 *      Cash – Uganda, USD 10,000 ↔ UGX 35,200,000 (money changer @ 3,520)
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
const UCR_UGX_AMOUNT  = 35200000.0;
const UCR_RATE        = 3520.0;
const UCR_VALUE_DATE  = '2026-09-06';
const UCR_EQUITY_NAME = 'Share Capital – Bhavin (USD)';
const UCR_BANK_NAME   = 'Ecobank Uganda – USD';
const UCR_CASH_USD    = 'Cash – Uganda – USD';
const UCR_CASH_UGX    = 'Cash – Uganda';
const UCR_ACTOR       = 'Bhavin Madlani (CLI rebook)';
const UCR_DESC_FUND   = 'Share capital contribution — Bhavin Madlani (brought in cash)';
const UCR_DESC_XFER   = 'Capital converted to UGX — money changer @ 3,520';
const UCR_VOID_FXC    = 'Rebooked as account transfer between cash accounts';
const UCR_VOID_FUND   = 'Capital arrived as cash, not bank — rebooked into ' . UCR_CASH_USD;

$confirm = in_array('--confirm', array_slice($argv, 1), true);
$dataDir = getenv('DN_DATA_DIR') ?: getDataDir($root);
$store   = SqliteStore::create($dataDir);
$cb      = new CashbookService($store, $dataDir);
$pdo     = $store->getPdo();
$mode    = $confirm ? 'EXECUTE' : 'DRY RUN (no writes — add --confirm to execute)';

echo "══ Uganda capital cash rebook — {$mode}\n";
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
$bankId   = (int)$byName[UCR_BANK_NAME]['id'];
$equityId = (int)$byName[UCR_EQUITY_NAME]['id'];
$cashUgxId = (int)$byName[UCR_CASH_UGX]['id'];

// ── Guard: every ACTIVE row must be one of the four diagnosed legs
$active = $pdo->query(
    "SELECT id, sr, date, direction, amount, currency, category, account_id, source, validation_ref
     FROM cb_ledger WHERE status NOT IN ('voided','voided_reconcile') ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC);
$fundLegs = []; $fxcLegs = []; $strangers = [];
foreach ($active as $r) {
    $amt = (float)$r['amount'];
    if ($r['validation_ref'] === 'FUND-0001' && $r['source'] === 'funding'
        && $r['direction'] === 'in' && $amt === UCR_AMOUNT && $r['currency'] === 'USD'
        && in_array((int)$r['account_id'], [$bankId, $equityId], true)) { $fundLegs[] = $r; continue; }
    if ($r['validation_ref'] === 'FXC-0001' && $r['source'] === 'fx_exchange' && (int)$r['account_id'] === 0
        && (($r['direction'] === 'out' && $amt === UCR_AMOUNT && $r['currency'] === 'USD')
         || ($r['direction'] === 'in' && $amt === UCR_UGX_AMOUNT && $r['currency'] === 'UGX'))) { $fxcLegs[] = $r; continue; }
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
if (!in_array(count($fxcLegs), [0, 2], true)) {
    fwrite(STDERR, "ABORT: FXC-0001 has " . count($fxcLegs) . " active leg(s) — expected the pair or none. Investigate first.\n");
    exit(1);
}

$xferDate = UCR_VALUE_DATE;
foreach ($fxcLegs as $r) if ($r['direction'] === 'in') $xferDate = $r['date'];
if (!$fxcLegs) $xferDate = '2026-09-07';

echo "1. FUND-0001 pair found (into " . UCR_BANK_NAME . ") — will VOID both legs: '" . UCR_VOID_FUND . "'.\n";
echo "2. " . ($fxcLegs ? "FXC-0001 exchange pair found — will VOID both legs: '" . UCR_VOID_FXC . "'."
                        : "FXC-0001 already voided/absent — void step skipped.") . "\n";
echo "3. Cash account: " . UCR_CASH_USD . (isset($byName[UCR_CASH_USD]) ? " — exists." : " — will create (USD, cash).") . "\n";
echo "4. Will record: USD " . number_format(UCR_AMOUNT, 2) . " share capital, value date " . UCR_VALUE_DATE
   . ", into " . UCR_CASH_USD . " + " . UCR_EQUITY_NAME . " (typed pair, excluded from P&L).\n";
echo "5. Will record: transfer " . UCR_CASH_USD . " → " . UCR_CASH_UGX . ", USD " . number_format(UCR_AMOUNT, 2)
   . " ↔ UGX " . number_format(UCR_UGX_AMOUNT, 2) . " @ " . number_format(UCR_RATE, 0) . ", date {$xferDate}.\n\n";

if (!$confirm) {
    echo "DRY RUN complete — nothing was changed. Re-run with --confirm to execute.\n";
    exit(0);
}

if ($fxcLegs) {
    $v = $cb->voidEntry((int)$fxcLegs[0]['id'], UCR_VOID_FXC, UCR_ACTOR);
    if (!($v['ok'] ?? false) || (int)($v['voided'] ?? 0) !== 2) { fwrite(STDERR, "ABORT: FXC void failed — " . ($v['error'] ?? '?') . "\n"); exit(1); }
    echo "✔ Voided the FXC-0001 exchange pair (2 legs).\n";
}
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

$t = $cb->recordAccountTransfer($cashUsdId, $cashUgxId, UCR_AMOUNT, UCR_UGX_AMOUNT, $xferDate,
    UCR_DESC_XFER, 'Money changer @ 3,520', UCR_ACTOR);
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
echo "\nDone. The story the book now tells: Bhavin brought USD 10,000 in cash\n"
   . "(share capital), and it was exchanged for UGX 35,200,000 at 3,520 —\n"
   . "now sitting in " . UCR_CASH_UGX . ". If part of those shillings went into\n"
   . "Ecobank UGX or Mobile Money, record a same-currency Transfer on the\n"
   . "Opening Balances screen from " . UCR_CASH_UGX . " to that account.\n";
