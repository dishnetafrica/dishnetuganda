<?php
declare(strict_types=1);
/**
 * Phase B: accounts and opening balances. The accounting-safety contract:
 * one currency per account, one OPENING_BALANCE per account (correctable only
 * while the account has no other activity), typed rows that can never read as
 * sales or payments, balances that include the opening, and a standard-set
 * seeder that refuses to run twice.
 */
require_once dirname(__DIR__) . '/lib/StoreInterface.php';
require_once dirname(__DIR__) . '/lib/SqliteStore.php';
require_once dirname(__DIR__) . '/lib/CashbookService.php';

$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }

$tmp = sys_get_temp_dir() . '/cb_accounts_test_' . getmypid();
@mkdir($tmp, 0777, true);
$store = SqliteStore::create($tmp);
$cb = new CashbookService($store, $tmp);

echo "Account creation rules\n";
$r = $cb->addAccount('Ecobank Uganda – UGX', 'UGX', 'bank');
t('bank account created', $r['ok'], true);
$ecobank = (int)$r['id'];
t('duplicate name refused', $cb->addAccount('ecobank uganda – ugx', 'UGX', 'bank')['ok'], false);
t('bad currency refused', $cb->addAccount('X', 'SHILLING', 'bank')['ok'], false);
t('unknown kind refused', $cb->addAccount('X', 'UGX', 'vault')['ok'], false);
$r2 = $cb->addAccount('Director/Shareholder Funding', 'UGX', 'director');
$director = (int)$r2['id'];
t('director liability account created', $r2['ok'], true);
t('account carries its one currency', $cb->account($ecobank)['currency'], 'UGX');

echo "\nOpening balance: typed, once, correctable only while sole\n";
$r = $cb->recordOpeningBalance($ecobank, 5000000.0, '2026-09-06',
    'Opening balance at commencement of Uganda operations', 'STMT-001', 'Bhavin');
t('opening saved', $r['ok'], true);
$op = $cb->openingFor($ecobank);
t('row typed OPENING_BALANCE', $op['txn_type'], 'OPENING_BALANCE');
t('row source opening_balance', $op['source'], 'opening_balance');
t('row category Opening Balance', $op['category'], 'Opening Balance');
t('currency taken from the ACCOUNT', $op['currency'], 'UGX');
t('as-of date stored', $op['date'], '2026-09-06');
t('balance includes the opening', $cb->accountBalance($ecobank), 5000000.0);

$r = $cb->recordOpeningBalance($ecobank, 5500000.0, '2026-09-06', 'corrected', 'STMT-001b', 'Bhavin');
t('correction allowed while account has no activity', $r['ok'], true);
t('correction updates, not duplicates', $r['updated'] ?? false, true);
t('still exactly one opening row',
  count($store->getPdo()->query("SELECT id FROM cb_ledger WHERE account_id={$ecobank} AND txn_type='OPENING_BALANCE'")->fetchAll()), 1);
t('balance follows the correction', $cb->accountBalance($ecobank), 5500000.0);

// Real activity lands on the account → the opening becomes immutable.
$cb->addEntryRaw(['project' => 'dishnet', 'date' => '2026-09-07', 'direction' => 'out',
    'amount' => 250000.0, 'currency' => 'UGX', 'category' => 'Site Power',
    'description' => 'test expense', 'account_id' => $ecobank, 'txn_type' => 'EXPENSE',
    'source' => 'manual', 'approved_by' => 'test']);
$r = $cb->recordOpeningBalance($ecobank, 9999999.0, '2026-09-06', 'sneaky rewrite', '', 'X');
t('rewrite blocked once activity exists', $r['ok'], false);
t('block message points to ADJUSTMENT', stripos((string)$r['error'], 'ADJUSTMENT') !== false, true);
t('balance = opening minus expense', $cb->accountBalance($ecobank), 5250000.0);

echo "\nAccounting safety: openings are not revenue\n";
$rows = $store->getPdo()->query(
    "SELECT COUNT(*) FROM cb_ledger WHERE txn_type='OPENING_BALANCE' AND category != 'Opening Balance'"
)->fetchColumn();
t('every opening row wears its own category', (int)$rows, 0);
t('negative opening refused', $cb->recordOpeningBalance($director, -5.0, '2026-09-06', '', '', 'x')['ok'], false);
t('inactive account refused', (function () use ($cb, $director) {
    $cb->setAccountActive($director, false);
    $r = $cb->recordOpeningBalance($director, 1.0, '2026-09-06', '', '', 'x');
    $cb->setAccountActive($director, true);
    return $r['ok'];
})(), false);

echo "\nThe USD-injection chain: funding in, exchange to UGX, spend in UGX\n";
$usdBank  = (int)$cb->addAccount('Ecobank Uganda – USD', 'USD', 'bank')['id'];
$usdLiab  = (int)$cb->addAccount('Director Funding – USD', 'USD', 'director')['id'];

// 1. Investment arrives: USD 10,000 into the USD bank, owed to the director.
$r = $cb->recordFunding($usdBank, $usdLiab, 10000.0, '2026-09-06', 'Investment from outside', '', 'Bhavin');
t('funding recorded with a linked ref', $r['ok'] && strpos((string)$r['ref'], 'FUND-') === 0, true);
t('USD bank gained the funds', $cb->accountBalance($usdBank), 10000.0);
t('USD liability records what is owed', $cb->accountBalance($usdLiab), 10000.0);
$fundRows = $store->getPdo()->query(
    "SELECT txn_type, currency, category FROM cb_ledger WHERE validation_ref='{$r['ref']}'")->fetchAll(PDO::FETCH_ASSOC);
t('both funding legs typed DIRECTOR_FUNDING',
  count($fundRows) === 2 && $fundRows[0]['txn_type'] === 'DIRECTOR_FUNDING'
  && $fundRows[1]['txn_type'] === 'DIRECTOR_FUNDING', true);
t('no funding leg reads as a sales Receipt',
  $fundRows[0]['category'] !== 'Receipt' && $fundRows[1]['category'] !== 'Receipt', true);

// Currency-mismatch guard: USD funds cannot land against the UGX director account.
$ugxLiab = (int)$cb->addAccount('Director Funding – UGX', 'UGX', 'director')['id'];
$bad = $cb->recordFunding($usdBank, $ugxLiab, 500.0, '2026-09-06', '', '', 'x');
t('USD funding against UGX liability refused', $bad['ok'], false);
t('the message says how to fix it', stripos((string)$bad['error'], 'currency') !== false, true);

// 2. Exchange USD 8,000 → UGX 29,760,000 (operator-entered, both sides).
$r = $cb->recordAccountTransfer($usdBank, $ecobank, 8000.0, 29760000.0,
    '2026-09-07', '', 'Ecobank board rate', 'Bhavin');
t('exchange recorded', $r['ok'], true);
t('effective rate derived from the two amounts', $r['rate'], 3720.0);
t('USD account debited', $cb->accountBalance($usdBank), 2000.0);
t('UGX account credited', $cb->accountBalance($ecobank), 5250000.0 + 29760000.0);
$legs = $store->getPdo()->query(
    "SELECT direction, currency, fx_currency, fx_amount, fx_rate, fx_rate_source, txn_type
     FROM cb_ledger WHERE validation_ref='{$r['ref']}' ORDER BY direction")->fetchAll(PDO::FETCH_ASSOC);
t('two legs, one reference', count($legs), 2);
t('both legs typed TRANSFER', $legs[0]['txn_type'] === 'TRANSFER' && $legs[1]['txn_type'] === 'TRANSFER', true);
t('receiving leg remembers the original USD', $legs[0]['fx_currency'], 'USD');
t('receiving leg remembers the original amount', (float)$legs[0]['fx_amount'], 8000.0);
t('receiving leg records the rate', (float)$legs[0]['fx_rate'], 3720.0);
t('and where the rate came from', $legs[0]['fx_rate_source'], 'Ecobank board rate');
t('outgoing leg carries no fx fields', (string)$legs[1]['fx_currency'], '');

echo "\nTransfer guards\n";
t('FX without a rate source refused',
  $cb->recordAccountTransfer($usdBank, $ecobank, 100.0, 372000.0, '2026-09-07', '', '', 'x')['ok'], false);
t('same-currency transfer must balance',
  $cb->recordAccountTransfer($ecobank, $ecobank + 999, 100.0, 90.0, '2026-09-07', '', '', 'x')['ok'], false);
$cash = (int)$cb->addAccount('Cash – Uganda', 'UGX', 'cash')['id'];
$r = $cb->recordAccountTransfer($ecobank, $cash, 1000000.0, 1000000.0, '2026-09-08', '', '', 'Bhavin');
t('same-currency transfer works', $r['ok'], true);
t('no fake rate on a plain transfer', $r['rate'], null);
t('same account both sides refused',
  $cb->recordAccountTransfer($cash, $cash, 10.0, 10.0, '2026-09-08', '', '', 'x')['ok'], false);

echo "\nLedger streams: every running balance in its own currency\n";
// A UGX-base install (config file in the data dir, exactly how the live
// plugin reads it): UGX rows ride the base stream, USD rows their own —
// a USD 10,000 investment can never surface with a UGX running balance.
$t3 = sys_get_temp_dir() . '/cb_accounts_ugx_' . getmypid();
@mkdir($t3, 0777, true);
// Store first, config file second: SqliteStore::create() migrates any *.json
// it finds in a fresh data dir into sqlite (renaming it .migrated), which
// would eat the config. Live installs write overrides AFTER the store exists.
$st3 = SqliteStore::create($t3);
$cb3 = new CashbookService($st3, $t3);
file_put_contents($t3 . '/kyc_config.json', json_encode([
    'cashbook_base_currency' => 'UGX', 'cashbook_currencies' => 'UGX,USD']));
t('install books in UGX', $cb3->bookBase(), 'UGX');
foreach ([
    ['2026-09-10', 'in',  500000.0, 'UGX', 'UGX sale'],
    ['2026-09-11', 'in',  10000.0,  'USD', 'USD funding'],
    ['2026-09-12', 'out', 200000.0, 'UGX', 'UGX expense'],
    ['2026-09-13', 'out', 1500.0,   'USD', 'USD spend'],
] as $x) {
    $cb3->addEntryRaw(['project' => 'dishnet', 'date' => $x[0], 'direction' => $x[1],
        'amount' => $x[2], 'currency' => $x[3], 'category' => 'Receipt',
        'description' => $x[4], 'source' => 'manual', 'approved_by' => 'test']);
}
$byDesc = [];
foreach ($cb3->getEntries(['project' => 'dishnet', 'limit' => 50]) as $row) {
    $byDesc[$row['description']] = $row;
}
t('UGX sale balance runs in UGX', $byDesc['UGX sale']['running_balance'], 500000.0);
t('and says so', $byDesc['UGX sale']['_bal_currency'], 'UGX');
t('USD funding balance runs in its OWN stream', $byDesc['USD funding']['running_balance'], 10000.0);
t('and is labelled USD', $byDesc['USD funding']['_bal_currency'], 'USD');
t('UGX expense continues the UGX stream', $byDesc['UGX expense']['running_balance'], 300000.0);
t('USD spend continues the USD stream', $byDesc['USD spend']['running_balance'], 8500.0);
t('USD spend labelled USD', $byDesc['USD spend']['_bal_currency'], 'USD');

// Legacy rows without a stored currency (Sudan history) ride the base stream.
$cb3->addEntryRaw(['project' => 'dishnet', 'date' => '2026-09-14', 'direction' => 'in',
    'amount' => 100000.0, 'currency' => 'UGX', 'category' => 'Receipt',
    'description' => 'legacy row', 'source' => 'manual', 'approved_by' => 'test']);
$st3->getPdo()->exec("UPDATE cb_ledger SET currency='' WHERE description='legacy row'");
$byDesc = [];
foreach ($cb3->getEntries(['project' => 'dishnet', 'limit' => 50]) as $row) {
    $byDesc[$row['description']] = $row;
}
t('currency-less legacy row joins the base stream', $byDesc['legacy row']['running_balance'], 400000.0);
t('and wears the base label', $byDesc['legacy row']['_bal_currency'], 'UGX');

// A specific currency filter follows that currency only.
$usdOnly = $cb3->getEntries(['project' => 'dishnet', 'currency' => 'USD', 'limit' => 50]);
t('USD filter returns only USD rows', count($usdOnly), 2);
$byDesc = [];
foreach ($usdOnly as $row) $byDesc[$row['description']] = $row;
t('filtered USD balance unchanged', $byDesc['USD spend']['running_balance'], 8500.0);
t('filtered rows labelled USD', $byDesc['USD spend']['_bal_currency'], 'USD');

echo "\nStandard Uganda set\n";
$t2 = sys_get_temp_dir() . '/cb_accounts_seed_' . getmypid();
@mkdir($t2, 0777, true);
$st2 = SqliteStore::create($t2);
$cb2 = new CashbookService($st2, $t2);
// Explicit UGX base: the seeder refuses on any other base, and the config
// must not depend on whatever a vault file happens to gap-fill.
file_put_contents($t2 . '/kyc_config.json', json_encode([
    'cashbook_base_currency' => 'UGX', 'cashbook_currencies' => 'UGX,USD']));
$r = $cb2->seedStandardAccounts();
t('seed creates six accounts', count($r['created'] ?? []), 6);

// A USD-base install (Sudan) must never get the Uganda chart by accident.
$t4 = sys_get_temp_dir() . '/cb_accounts_usdbase_' . getmypid();
@mkdir($t4, 0777, true);
$st4 = SqliteStore::create($t4);
$cb4 = new CashbookService($st4, $t4);
file_put_contents($t4 . '/kyc_config.json', json_encode(['cashbook_base_currency' => 'USD']));
$r4 = $cb4->seedStandardAccounts();
t('seed refused on a USD-base install', $r4['ok'], false);
t('and the refusal names the reason', stripos((string)$r4['error'], 'UGX') !== false, true);
$byName = [];
foreach ($cb2->accounts() as $a) $byName[$a['name']] = $a;
t('Ecobank USD account is USD', $byName['Ecobank Uganda – USD']['currency'] ?? '', 'USD');
t('MoMo accounts are momo kind', $byName['MTN Mobile Money']['kind'] ?? '', 'momo');
t('director account kind', $byName['Director/Shareholder Funding']['kind'] ?? '', 'director');
t('second seed refused', $cb2->seedStandardAccounts()['ok'], false);
t('txn types include the accounting set',
  in_array('DIRECTOR_FUNDING', CashbookService::TXN_TYPES, true)
  && in_array('OPENING_BALANCE', CashbookService::TXN_TYPES, true), true);


echo "\nPhase C readers: positions, ledgers, P&L — currencies never merge\n";
$t5 = sys_get_temp_dir() . '/cb_phasec_' . getmypid();
@mkdir($t5, 0777, true);
$st5 = SqliteStore::create($t5);
$cb5 = new CashbookService($st5, $t5);
file_put_contents($t5 . '/kyc_config.json', json_encode([
    'cashbook_base_currency' => 'UGX', 'cashbook_currencies' => 'UGX,USD']));

$ugxBank5 = (int)$cb5->addAccount('Ecobank Uganda – UGX', 'UGX', 'bank')['id'];
$usdBank5 = (int)$cb5->addAccount('Ecobank Uganda – USD', 'USD', 'bank')['id'];
$usdLiab5 = (int)$cb5->addAccount('Director Funding – USD', 'USD', 'director')['id'];

// (3)/(4) an account books exactly one currency — wrong-currency rows throw.
$threw = false;
try { $cb5->addEntryRaw(['project'=>'dishnet','date'=>'2026-09-08','direction'=>'in',
    'amount'=>100.0,'currency'=>'USD','category'=>'Receipt','description'=>'wrong',
    'account_id'=>$ugxBank5,'source'=>'manual']); } catch (\Throwable $e) { $threw = true; }
t('UGX account refuses a USD row', $threw, true);
$threw = false;
try { $cb5->addEntryRaw(['project'=>'dishnet','date'=>'2026-09-08','direction'=>'in',
    'amount'=>100.0,'currency'=>'UGX','category'=>'Receipt','description'=>'wrong',
    'account_id'=>$usdBank5,'source'=>'manual']); } catch (\Throwable $e) { $threw = true; }
t('USD account refuses a UGX row', $threw, true);

// Build activity: UGX opening + UGX revenue + USD funding + FX transfer.
$cb5->recordOpeningBalance($ugxBank5, 5000000.0, '2026-09-06', 'opening', 'STMT', 'Bhavin');
$cb5->addEntryRaw(['project'=>'dishnet','date'=>'2026-09-08','direction'=>'in',
    'amount'=>329000.0,'currency'=>'UGX','category'=>'Receipt','description'=>'customer payment',
    'account_id'=>$ugxBank5,'source'=>'crm_webhook']);
$fund5 = $cb5->recordFunding($usdBank5, $usdLiab5, 10000.0, '2026-09-08', 'Investment', '', 'Bhavin');

// (1)/(2) a UGX transaction moves only the UGX position; USD only USD.
$pos = $cb5->currencyPositions();
t('positions carry one entry per currency', array_keys($pos), ['UGX', 'USD']);
t('UGX position = opening + customer payment only',
  $pos['UGX']['total'], 5329000.0);
t('USD CASH position = the bank leg only — the counterpart is not cash',
  $pos['USD']['total'], 10000.0);
t('the funding counterpart sits in its own bucket',
  $pos['USD']['counterparts_total'], 10000.0);
t('counterpart accounts are never in the cash account list',
  count(array_filter($pos['USD']['accounts'], fn($a) => $a['kind'] === 'director')), 0);
t('no combined figure exists anywhere in the position payload',
  !isset($pos['UGX']['combined']) && !isset($pos['USD']['combined'])
  && !array_key_exists('combined_usd', $pos) && !array_key_exists('total', $pos), true);

// (9)/(15-adjacent) FX conversion: two currency-specific legs, both positions move.
$fx5 = $cb5->recordAccountTransfer($usdBank5, $ugxBank5, 2000.0, 7440000.0,
    '2026-09-09', '', 'Ecobank board rate', 'Bhavin');
t('FX transfer recorded', $fx5['ok'], true);
$pos = $cb5->currencyPositions();
t('USD cash position dropped by the USD leg', $pos['USD']['total'], 8000.0);
t('UGX position rose by the UGX leg', $pos['UGX']['total'], 5329000.0 + 7440000.0);

// (6)/(7)/(8) P&L: capital flows invisible, revenue is only the customer payment.
$pl = $cb5->plByPeriod('dishnet', '2026-09-01', '2026-09-30');
t('P&L has a UGX section only (no USD trading yet)', array_keys($pl), ['UGX']);
t('UGX revenue = the customer payment alone', $pl['UGX']['revenue_total'], 329000.0);
t('opening balance is NOT revenue', isset($pl['UGX']['revenue']['Opening Balance']), false);
t('funding is NOT revenue anywhere', isset($pl['USD']), false);
t('transfer legs are NOT expenses', $pl['UGX']['expense_total'], 0.0);

// (10)/(11) account ledgers run independently, in the account currency.
$led = $cb5->accountLedger($ugxBank5);
t('UGX account ledger labelled UGX', $led['currency'], 'UGX');
t('UGX running balance includes opening + payment + FX in',
  $led['balance'], 5329000.0 + 7440000.0);
$ledU = $cb5->accountLedger($usdBank5);
t('USD account ledger independent of UGX', $ledU['balance'], 8000.0);

// (15) THE correction path: a stray manual "opening" can be voided and
// replaced by proper funding WITHOUT ever doubling the money.
$t6 = sys_get_temp_dir() . '/cb_correction_' . getmypid();
@mkdir($t6, 0777, true);
$st6 = SqliteStore::create($t6);
$cb6 = new CashbookService($st6, $t6);
file_put_contents($t6 . '/kyc_config.json', json_encode([
    'cashbook_base_currency' => 'UGX', 'cashbook_currencies' => 'UGX,USD']));
// The live CB-1 shape: manual, untyped, account-less, USD 10,000.
$cb6->addEntryRaw(['project'=>'dishnet','date'=>'2026-09-07','direction'=>'in',
    'amount'=>10000.0,'currency'=>'USD','category'=>'Opening Balance',
    'category_raw'=>'Opening Balance','person'=>'Bhavin',
    'description'=>'Opening Balance [2026-09]','source'=>'manual','approved_by'=>'Bhavin Madlani']);
$pos6 = $cb6->currencyPositions();
t('stray row shows as unassigned USD 10,000', $pos6['USD']['unassigned'] ?? null, 10000.0);
// The live 2026-09-07 screenshot: only USD activity existed, and the UGX
// hero card vanished. The BASE currency always has a position, even at zero.
t('the base currency card exists even with zero UGX activity',
  array_keys($pos6), ['UGX', 'USD']);
t('and it reads zero, honestly', $pos6['UGX']['total'], 0.0);

$strayId = (int)$st6->getPdo()->query("SELECT id FROM cb_ledger LIMIT 1")->fetchColumn();
$v6 = $cb6->voidEntry($strayId, 'misclassified — replaced by typed funding', 'Bhavin');
t('void succeeds with a reason', $v6['ok'], true);
t('void keeps the row (audit), removes the money',
  ($cb6->currencyPositions()['USD']['total'] ?? 0.0), 0.0);
t('void refuses a second attempt', $cb6->voidEntry($strayId, 'again', 'x')['ok'], false);
t('void without a reason refused', $cb6->voidEntry(999999, '', 'x')['ok'], false);

$usdBank6 = (int)$cb6->addAccount('Ecobank Uganda – USD', 'USD', 'bank')['id'];
$usdLiab6 = (int)$cb6->addAccount('Director Funding – USD', 'USD', 'director')['id'];
$cb6->recordFunding($usdBank6, $usdLiab6, 10000.0, '2026-09-06', 'Investment from Bhavin', '', 'Bhavin');
$pos6 = $cb6->currencyPositions();
t('after correction: USD bank holds exactly 10,000 — never 20,000',
  (float)($pos6['USD']['accounts'][0]['balance'] ?? 0) + 0.0, 10000.0);
t('voided stray contributes nothing', $pos6['USD']['unassigned'], 0.0);

// Pair-void: voiding one funding leg voids BOTH.
$fundRef6 = $st6->getPdo()->query("SELECT validation_ref FROM cb_ledger WHERE source='funding' LIMIT 1")->fetchColumn();
$legId6   = (int)$st6->getPdo()->query("SELECT id FROM cb_ledger WHERE source='funding' LIMIT 1")->fetchColumn();
$pv = $cb6->voidEntry($legId6, 'testing pair void', 'Bhavin');
t('voiding one funding leg voids the linked pair', $pv['voided'] ?? 0, 2);
t('pair-void zeroes both accounts',
  ($cb6->currencyPositions()['USD']['total'] ?? 0.0), 0.0);

exec('rm -rf ' . escapeshellarg($t5) . ' ' . escapeshellarg($t6));

echo "\nCash exchange (the wizard's Exchange tile) — honest USD ↔ base pair\n";
$t7  = sys_get_temp_dir() . '/cb_acct_t7_' . getmypid();
@mkdir($t7, 0777, true);
$st7 = SqliteStore::create($t7);
file_put_contents($t7 . '/kyc_config.json', json_encode(['cashbook_base_currency' => 'UGX', 'cashbook_currencies' => 'UGX,USD']));
$cb7 = new CashbookService($st7, $t7);
$x1 = $cb7->recordCashExchange(100.0, 3730.0, 'usd_to_base', '2026-09-07', '', 'dishnet', 'UAT', 'tester');
t('exchange records with an FXC ref', ($x1['ok'] ?? false) && strpos((string)$x1['ref'], 'FXC-') === 0, true);
t('the UGX side is the entered rate times the USD', $x1['base_amount'] ?? 0.0, 373000.0);
$pos7 = $cb7->currencyPositions();
t('USD position moved down by the USD given', (float)$pos7['USD']['total'], -100.0);
t('UGX position moved up by the UGX received', (float)$pos7['UGX']['total'], 373000.0);
t('each leg carries ONE currency (no ssp_amount machinery)',
  (int)$st7->getPdo()->query("SELECT COUNT(*) FROM cb_ledger WHERE source='fx_exchange' AND ssp_amount IS NOT NULL")->fetchColumn(), 0);
t('the receiving leg records the operator rate',
  (float)$st7->getPdo()->query("SELECT fx_rate FROM cb_ledger WHERE source='fx_exchange' AND direction='in'")->fetchColumn(), 3730.0);
$pl7 = $cb7->plByPeriod('dishnet', '2026-01-01', '2026-12-31');
t('an exchange is never revenue in ANY currency',
  (float)(($pl7['UGX']['revenue_total'] ?? 0) + ($pl7['USD']['revenue_total'] ?? 0)), 0.0);
t('and never an expense either',
  (float)(($pl7['UGX']['expense_total'] ?? 0) + ($pl7['USD']['expense_total'] ?? 0)), 0.0);
$x2 = $cb7->recordCashExchange(50.0, 3700.0, 'base_to_usd', '2026-09-07', '', 'dishnet', 'UAT', 'tester');
t('reverse direction books UGX out / USD in', ($x2['ok'] ?? false)
  && (float)$cb7->currencyPositions()['USD']['total'] === -50.0
  && (float)$cb7->currencyPositions()['UGX']['total'] === 188000.0, true);
$xLeg = (int)$st7->getPdo()->query("SELECT id FROM cb_ledger WHERE validation_ref='" . $x2['ref'] . "' LIMIT 1")->fetchColumn();
$xv   = $cb7->voidEntry($xLeg, 'UAT pair void', 'tester');
t('voiding one exchange leg voids the pair', $xv['voided'] ?? 0, 2);
t('pair-void restores both positions',
  (float)$cb7->currencyPositions()['USD']['total'] === -100.0
  && (float)$cb7->currencyPositions()['UGX']['total'] === 373000.0, true);
t('zero rate refused', $cb7->recordCashExchange(100.0, 0.0, 'usd_to_base', '2026-09-07', '', 'dishnet', '', 't')['ok'], false);

// The live FUND-0001 equity leg was hard-deleted — pair legs refuse delete now.
$xPairLeg = (int)$st7->getPdo()->query("SELECT id FROM cb_ledger WHERE source='fx_exchange' AND status='approved' LIMIT 1")->fetchColumn();
$xDel = $cb7->deleteEntry($xPairLeg, ['name' => 't']);
t('deleting one leg of a linked pair is refused', $xDel['ok'], false);
t('and the error points at Void', strpos((string)$xDel['error'], 'Void') !== false, true);
$plain7 = $cb7->addEntry(['project' => 'dishnet', 'direction' => 'in', 'amount' => 5.0,
    'category' => 'Receipt', 'description' => 'plain row'], ['name' => 't'], true);
t('a plain manual row still deletes', $cb7->deleteEntry((int)$plain7['id'], ['name' => 't'])['ok'], true);
// Editing one leg's money fields is the same trap as deleting it (live: the
// FXC in-leg was hand-edited to a new amount/date while the out leg kept the old).
$xEdit = $cb7->updateEntry($xPairLeg, ['amount' => 999.0], ['name' => 't']);
t('editing a pair leg amount is refused', $xEdit['ok'], false);
t('and the error points at Void', strpos((string)$xEdit['error'], 'Void') !== false, true);
t('a harmless description edit on a pair leg still works',
  $cb7->updateEntry($xPairLeg, ['description' => 'note'], ['name' => 't'])['ok'], true);
$plain8 = $cb7->addEntry(['project' => 'dishnet', 'direction' => 'in', 'amount' => 5.0,
    'category' => 'Receipt', 'description' => 'plain row 2'], ['name' => 't'], true);
t('a plain row amount edit still works',
  $cb7->updateEntry((int)$plain8['id'], ['amount' => 6.0], ['name' => 't'])['ok'], true);
t('junk direction refused', $cb7->recordCashExchange(100.0, 3730.0, 'sideways', '2026-09-07', '', 'dishnet', '', 't')['ok'], false);
$t8  = sys_get_temp_dir() . '/cb_acct_t8_' . getmypid();
@mkdir($t8, 0777, true);
$st8 = SqliteStore::create($t8);
file_put_contents($t8 . '/kyc_config.json', json_encode(['cashbook_base_currency' => 'USD']));
$cb8 = new CashbookService($st8, $t8);
t('a USD-base book has no counter-currency to exchange',
  strpos((string)($cb8->recordCashExchange(100.0, 3730.0, 'usd_to_base', '2026-09-07', '', 'dishnet', '', 't')['error'] ?? ''), 'counter-currency') !== false, true);
exec('rm -rf ' . escapeshellarg($t7) . ' ' . escapeshellarg($t8));

exec('rm -rf ' . escapeshellarg($tmp) . ' ' . escapeshellarg($t2) . ' ' . escapeshellarg($t3) . ' ' . escapeshellarg($t4));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
