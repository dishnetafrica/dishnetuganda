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

echo "\nStandard Uganda set\n";
$t2 = sys_get_temp_dir() . '/cb_accounts_seed_' . getmypid();
@mkdir($t2, 0777, true);
$cb2 = new CashbookService(SqliteStore::create($t2), $t2);
$r = $cb2->seedStandardAccounts();
t('seed creates six accounts', count($r['created'] ?? []), 6);
$byName = [];
foreach ($cb2->accounts() as $a) $byName[$a['name']] = $a;
t('Ecobank USD account is USD', $byName['Ecobank Uganda – USD']['currency'] ?? '', 'USD');
t('MoMo accounts are momo kind', $byName['MTN Mobile Money']['kind'] ?? '', 'momo');
t('director account kind', $byName['Director/Shareholder Funding']['kind'] ?? '', 'director');
t('second seed refused', $cb2->seedStandardAccounts()['ok'], false);
t('txn types include the accounting set',
  in_array('DIRECTOR_FUNDING', CashbookService::TXN_TYPES, true)
  && in_array('OPENING_BALANCE', CashbookService::TXN_TYPES, true), true);

exec('rm -rf ' . escapeshellarg($tmp) . ' ' . escapeshellarg($t2));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
