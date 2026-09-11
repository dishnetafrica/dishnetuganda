<?php
declare(strict_types=1);
/**
 * test_bank_statement.php — the statement is the only proof money moved.
 *
 * Everything else this system holds is intent: an order placed, a bill
 * booked, a payment marked as made. The bank says what actually left the
 * account, and until the two agree the books are a story rather than a
 * record.
 *
 * The assertions that matter most:
 *
 *   A STATEMENT THAT DOES NOT ADD UP IS REFUSED. Every row carries the
 *   balance after it, so the file can check itself; a mistyped digit or a
 *   dropped row breaks the chain and nothing is imported.
 *
 *   BUYING STOCK IS NOT AN EXPENSE. A supplier payment for equipment moves
 *   bank → inventory, typed TRANSFER, so it never appears as a trading loss
 *   for kits the business still owns.
 *
 *   A PAYMENT WITH NO ORDER IS NOT INVENTORY. It goes to suspense: the bank
 *   reconciles, nothing false is claimed, and the suspense balance is the
 *   size of the work left.
 *
 *   MONEY ARRIVING IS NOT GUESSED AT. Capital, a director's loan and a
 *   customer paying look identical to a bank.
 */
require_once dirname(__DIR__) . '/lib/StoreInterface.php';
require_once dirname(__DIR__) . '/lib/JsonStore.php';
require_once dirname(__DIR__) . '/lib/SqliteStore.php';
require_once dirname(__DIR__) . '/lib/CashbookService.php';
require_once dirname(__DIR__) . '/lib/StockService.php';
require_once dirname(__DIR__) . '/lib/PurchaseService.php';
require_once dirname(__DIR__) . '/lib/BankStatement.php';
require_once dirname(__DIR__) . '/lib/BankImport.php';

$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; } }

$root = dirname(__DIR__);
$tmp  = sys_get_temp_dir() . '/dn_bank_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);

// ── Reading the two awkward column types ────────────────────────────────────
echo "Money and dates as banks actually write them\n";
t('a plain figure',        BankStatement::money('1859520'), 1859520.0);
t('with a currency on it', BankStatement::money('UGX 1,859,520.00'), 1859520.0);
t('with a dollar sign',    BankStatement::money('$ 505.30'), 505.3);
t('an empty cell',         BankStatement::money(''), 0.0);
t('a dash for nothing',    BankStatement::money('-'), 0.0);
t('brackets mean negative',BankStatement::money('(500.00)'), -500.0);
t('Ecobank writes the month first', BankStatement::date('09/11/2026'), '2026-09-11');
t('a day past the 12th settles it', BankStatement::date('13/09/2026'), '2026-09-13');
t('an ISO date passes through',     BankStatement::date('2026-09-07'), '2026-09-07');
t('an empty date is no date',       BankStatement::date(''), '');

echo "\nWhat a narration can and cannot prove\n";
t('a card purchase names its supplier',
    BankStatement::classify('STARLINK GLOBAL INTERNE256781468254 UG - POS PURCHASE (ON-US)', false), 'supplier');
t('a fee is a fee',      BankStatement::classify('CURRENT ACCOUNT MAINTENANCE FEE', false), 'bank_charge');
t('so is the VAT on it', BankStatement::classify('VALUE ADDED TAX', false), 'bank_charge');
t('cash out is a withdrawal', BankStatement::classify('CASH W/D IFO BHAVIN MADLANI', false), 'withdrawal');
t('money in is only ever a deposit', BankStatement::classify('DEP BY BHAVIN MADLANI', true), 'deposit');
t('and an unknown debit stays unknown', BankStatement::classify('SUNDRY', false), 'unclassified');
t('the supplier out of the shouting',
    BankStatement::supplierName('STARLINK GLOBAL INTERNE256781468254 UG - POS PURCHASE (ON-US)'), 'Starlink');

// ── A statement checks itself ───────────────────────────────────────────────
echo "\nA statement that does not add up is refused\n";
$good = <<<CSV
Posting Date,Description,Debit,Credit,Running Balance,Transaction Reference
09/02/2026,DEP BY BHAVIN MADLANI,,"UGX 7,000,000.00","UGX 7,000,000.00",2453770313
09/02/2026,STARLINK GLOBAL INTERNE256781468254 UG - POS PURCHASE (ON-US),"UGX 1,859,520.00",,"UGX 5,140,480.00",2454007677
09/03/2026,CURRENT ACCOUNT MAINTENANCE FEE,"UGX 25,000.00",,"UGX 5,115,480.00",2450886290
09/04/2026,Starlink 256781468254 UG - POS PURCHASE (ON-US),"UGX 1,859,520.00",,"UGX 3,255,960.00",2457254136
09/05/2026,CASH W/D IFO BHAVIN MADLANI,"UGX 500,000.00",,"UGX 2,755,960.00",2455826375
CSV;
file_put_contents($tmp . '/good.csv', $good);
$p = BankStatement::parse($good);
t('it parses',        $p['ok'], true);
t('five rows',        count($p['rows']), 5);
t('the date is read', $p['rows'][0]['date'], '2026-09-02');
t('the credit is read', $p['rows'][0]['credit'], 7000000.0);
t('the debit is read',  $p['rows'][1]['debit'], 1859520.0);
t('and the reference',  $p['rows'][1]['ref'], '2454007677');
$chain = BankStatement::verifyChain($p['rows']);
t('the chain holds', $chain['ok'], true);
t('opening at nil',  $chain['opening'], 0.0);
t('closing where the bank says', $chain['closing'], 2755960.0);

// One digit changed, nothing else.
$bad = str_replace('UGX 5,140,480.00', 'UGX 5,140,470.00', $good);
$pb  = BankStatement::parse($bad);
$cb_ = BankStatement::verifyChain($pb['rows']);
t('one wrong digit breaks it', $cb_['ok'], false);
t('and it says which row', $cb_['breaks'][0]['line'], 3);
t('and by how much',       $cb_['breaks'][0]['out_by'], -10.0);
// A mistyped balance shows as a PAIR: the row that carries the wrong figure,
// and the next row, where the file returns to its own arithmetic. That pair
// brackets the bad row exactly — what must never happen is every later row
// reporting a break.
t('a mistyped figure is bracketed, not smeared down the file', count($cb_['breaks']), 2);
t('the second break is the mirror of the first', $cb_['breaks'][1]['out_by'], 10.0);

// A dropped row is the same failure.
$dropped = implode("\n", array_values(array_filter(explode("\n", $good),
    static fn($l) => strpos($l, '2450886290') === false)));
$cd = BankStatement::verifyChain(BankStatement::parse($dropped)['rows']);
t('a missing row breaks it too', $cd['ok'], false);

echo "\nA file that is not a statement\n";
$r = BankStatement::parse("Name,Amount\nBhavin,100");
t('is refused', $r['ok'], false);
is_(strpos((string)$r['error'], 'Columns found: Name, Amount') !== false,
    'and says what columns it did see', (string)$r['error']);

// ── Into the book ───────────────────────────────────────────────────────────
echo "\nInto the cashbook\n";
$store = SqliteStore::create($tmp);
$pdo   = $store->getPdo();
$cb    = new CashbookService($store, $tmp);
$stock = StockService::fromStore($store, $tmp);
$stock->ensureTables();
$bank  = (int)($cb->addAccount('Ecobank Uganda – UGX', 'UGX', 'bank')['id'] ?? 0);
is_($bank > 0, 'there is a bank account to import into');

// One booked purchase, paid — so one of the two card payments has an order
// behind it and the other does not.
$pur = new PurchaseService($pdo, $tmp, $stock);
$kit = (int)($stock->saveCategory(['title' => 'Starlink Kit', 'sku' => 'SL',
    'service_type' => 'starlink', 'track_mode' => 'serial', 'buy_price' => 1477778])['id'] ?? 0);
$rec = $pur->receive(['supplier' => 'Starlink', 'invoice_number' => 'INV-DF-UGA-2531-55173-40',
    'supplier_ref' => 'ORD-DF-1TV9KE23FHG7VBKXHL', 'purchase_date' => '2026-09-02',
    'currency' => 'UGX', 'total_cost' => 1859520, 'idem_key' => 'starlink-order:ORD-1'],
    [['category_id' => $kit, 'quantity' => 1, 'unit_cost' => 1575864, 'tax_rate' => 18]],
    ['id' => 0, 'name' => 'test']);
$pur->recordPayment((int)$rec['id'], ['amount' => 1859520, 'paid_on' => '2026-09-02',
    'method' => 'card'], ['id' => 0, 'name' => 'test']);

$imp  = new BankImport($cb, $pdo);
$rows = BankStatement::parse($good)['rows'];

$dry = $imp->import($rows, $bank, []);
t('a dry run would book three rows', $dry['posted'], 3);
t('one matches a real purchase',     $dry['matched'], 1);
t('one has no order behind it',      $dry['suspense'], 1);
t('two wait on a person',            $dry['skipped'], 2);
t('and nothing was written',
    (int)$pdo->query("SELECT COUNT(*) FROM cb_ledger")->fetchColumn(), 0);

$w = $imp->import($rows, $bank, ['commit' => true]);
t('committing books them', $w['posted'], 3);
t('with no errors',        $w['errors'], []);

echo "\nBuying stock is not an expense\n";
$inv = null;
foreach ($cb->accounts() as $a) if ($a['kind'] === 'inventory') $inv = $a;
is_($inv !== null, 'an inventory account exists now');
t('in the same currency as the bank', $inv['currency'] ?? '', 'UGX');
t('the matched payment landed in it', $cb->accountBalance((int)$inv['id']), 1859520.0);
$sus = null;
foreach ($cb->accounts() as $a) if ($a['kind'] === 'asset') $sus = $a;
is_($sus !== null, 'and a suspense account for the one with no order');
t('holding the unmatched payment', $cb->accountBalance((int)$sus['id']), 1859520.0);

$pl = $cb->plByPeriod('', '', '');
t('neither shows up as a trading expense', $pl['UGX']['expenses']['Bank Transfer'] ?? 0.0, 0.0);
t('the bank charge does',                  $pl['UGX']['expenses']['Bank Charges'] ?? 0.0, 25000.0);
t('so the P&L is 25,000 of expense, not 3.7 million', $pl['UGX']['expense_total'], 25000.0);

echo "\nThe bank's own balance is reproduced\n";
// 7,000,000 in was skipped as needing a decision, so the account is short by
// exactly that — which is the point of saying so rather than guessing.
t('the bank account holds what was booked', $cb->accountBalance($bank), -3744040.0);
$pos = $cb->currencyPositions();
$ugx = $pos['UGX'] ?? [];
$kinds = [];
foreach (($ugx['accounts'] ?? []) as $a) $kinds[$a['kind']] = true;
is_(!isset($kinds['inventory']) && !isset($kinds['asset']),
    'inventory and suspense are never counted as cash');

echo "\nThe purchase is tied to the row that proves it\n";
$pay = $pdo->query("SELECT * FROM stock_purchase_payments")->fetch(PDO::FETCH_ASSOC);
is_((int)$pay['cb_ledger_id'] > 0, 'the recorded payment now points at a ledger row');
$led = $pdo->query("SELECT * FROM cb_ledger WHERE id = " . (int)$pay['cb_ledger_id'])->fetch(PDO::FETCH_ASSOC);
t('the row the bank charged us on', $led['validation_ref'], 'BANK-2454007677');
t('money leaving the bank',         $led['direction'], 'out');
t('typed as a transfer',            $led['txn_type'], 'TRANSFER');
require_once dirname(__DIR__) . '/lib/FinAudit.php';
$h = FinAudit::history($pdo, 'stock_purchase_payment', (int)$pay['id']);
is_(count($h) >= 1 && strpos((string)$h[0]['reason'], 'BANK-2454007677') !== false,
    'and the match itself is on the record', (string)($h[0]['reason'] ?? ''));

echo "\nA bank cannot post a payment before it was made\n";
// The live case, from Starlink's own payment feed: four payments of the same
// amount made on the 2nd, the 8th and twice on the 10th, against twelve
// identical bank debits. A symmetric date window let the debit posted on the
// 3rd claim the payment made on the 8th — five days in the future — and with
// twelve identical amounts it looked like a clean match. Starlink stamps UTC
// and Ecobank posts in EAT, so a posting date is never earlier than the
// payment date. Same day or after, never before.
$tmpA = sys_get_temp_dir() . '/dn_bank_amb_' . bin2hex(random_bytes(4));
@mkdir($tmpA, 0777, true);
$sA = SqliteStore::create($tmpA); $pA = $sA->getPdo();
$cbA = new CashbookService($sA, $tmpA);
$stA = StockService::fromStore($sA, $tmpA); $stA->ensureTables();
$kA = (int)($stA->saveCategory(['title' => 'Kit', 'sku' => 'K', 'service_type' => 'starlink',
    'track_mode' => 'serial'])['id'] ?? 0);
$purA = new PurchaseService($pA, $tmpA, $stA);
$order = function (string $ref, string $paidOn) use ($purA, $kA) {
    $x = $purA->receive(['supplier' => 'Starlink', 'invoice_number' => 'INV-' . $ref,
        'supplier_ref' => $ref, 'purchase_date' => $paidOn, 'currency' => 'UGX',
        'total_cost' => 1859520, 'idem_key' => 'o' . $ref],
        [['category_id' => $kA, 'quantity' => 1, 'unit_cost' => 1575864, 'tax_rate' => 18]],
        ['id' => 0, 'name' => 't']);
    $purA->recordPayment((int)$x['id'], ['amount' => 1859520, 'paid_on' => $paidOn, 'method' => 'card'],
        ['id' => 0, 'name' => 't']);
};
// Exactly the live shape: one on the 2nd, one on the 8th, two on the 10th.
$order('ORD-E92VQ', '2026-09-02');
$order('ORD-NCEAA', '2026-09-08');
$order('ORD-I1GQ2', '2026-09-10');
$order('ORD-1TV9K', '2026-09-10');
$bA = (int)($cbA->addAccount('Ecobank – UGX', 'UGX', 'bank')['id'] ?? 0);

// Twelve identical debits, on the days the bank actually posted them.
$days = ['09/02', '09/03', '09/03', '09/04', '09/04', '09/07', '09/07', '09/07',
         '09/07', '09/09', '09/10', '09/10'];
$csv = "Posting Date,Description,Debit,Credit,Running Balance,Transaction Reference\n";
$bal = 25000000.0; $n = 0;
foreach ($days as $d) { $bal -= 1859520; $csv .= "{$d}/2026,Starlink POS PURCHASE,1859520,,{$bal}," . ('B' . ++$n) . "\n"; }
$rowsA = BankStatement::parse($csv)['rows'];
t('the statement still adds up', BankStatement::verifyChain($rowsA)['ok'], true);

$rA = (new BankImport($cbA, $pA))->import($rowsA, $bA, ['commit' => true]);
t('four of the twelve are ours', $rA['matched'], 4);
t('and eight belong to accounts we have not imported', $rA['suspense'], 8);

$linked = $pA->query(
    "SELECT s.supplier_ref, l.date FROM stock_purchase_payments p
     JOIN stock_purchases s ON s.id = p.purchase_id
     JOIN cb_ledger l ON l.id = p.cb_ledger_id
     ORDER BY s.supplier_ref")->fetchAll(PDO::FETCH_KEY_PAIR);
t('the payment made on the 2nd is the debit posted on the 2nd',
    $linked['ORD-E92VQ'] ?? '', '2026-09-02');
t('the payment made late on the 8th posted on the 9th, not the 3rd',
    $linked['ORD-NCEAA'] ?? '', '2026-09-09');
t('and the two made on the 10th posted on the 10th',
    [$linked['ORD-I1GQ2'] ?? '', $linked['ORD-1TV9K'] ?? ''], ['2026-09-10', '2026-09-10']);
is_(!in_array('2026-09-03', $linked, true) && !in_array('2026-09-04', $linked, true)
    && !in_array('2026-09-07', $linked, true),
    'no debit claims a payment that had not happened yet', implode(' ', $linked));

// The two made on the same day ARE interchangeable, and it says so.
t('only the same-day pair is a guess', $rA['ambiguous'], 2);
is_(count(array_filter($rA['notes'], static fn($n) => strpos($n, 'best fit, not a certainty') !== false)) === 1,
    'said once, not per row');
$invA = null;
foreach ($cbA->accounts() as $a) if ($a['kind'] === 'inventory') $invA = $a;
t('the money is right whichever way round they went',
    $cbA->accountBalance((int)$invA['id']), 7438080.0);
t('each payment is claimed exactly once',
    (int)$pA->query("SELECT COUNT(*) FROM stock_purchase_payments WHERE cb_ledger_id > 0")->fetchColumn(), 4);

// The settlement window has to be ENFORCED, not merely written down. PDO
// binds an int as a string, and SQLite sorts any text above any number, so
// `BETWEEN 0 AND ?` with a bound 3 silently accepts every payment ever made.
// It passed its own tests while matching a debit to a payment three weeks old.
$far = BankStatement::parse(
    "Posting Date,Description,Debit,Credit,Running Balance,Transaction Reference\n"
  . "09/30/2026,Starlink POS PURCHASE,1859520,,1000000,FAR1")['rows'];
$rFar = (new BankImport($cbA, $pA))->import($far, $bA, []);
t('a debit three weeks after the last payment matches nothing', $rFar['matched'], 0);
t('and goes to suspense instead', $rFar['suspense'], 1);

exec('rm -rf ' . escapeshellarg($tmpA));

echo "\nImporting the same statement again\n";
$again = $imp->import($rows, $bank, ['commit' => true]);
t('nothing is booked twice', $again['posted'], 0);
t('all three are recognised', $again['already'], 3);
t('still six ledger rows',
    (int)$pdo->query("SELECT COUNT(*) FROM cb_ledger")->fetchColumn(), 5);

echo "\nMoney arriving, once someone says what it is\n";
$dep = $imp->import($rows, $bank, ['commit' => true, 'deposits' => 'director']);
t('the deposit is booked now', $dep['posted'], 1);
$in = $pdo->query("SELECT * FROM cb_ledger WHERE direction='in' AND source='bank_import'
                   AND account_id = {$bank}")->fetch(PDO::FETCH_ASSOC);
t('as a loan from the director', $in['category'], 'Loan Received');
t('for what the bank received',  (float)$in['amount'], 7000000.0);
is_(count(array_filter($dep['notes'], static fn($n) => strpos($n, 'arriving a second time') !== false)) === 0,
    'no duplicate warning when the book had no capital in it');
$pl2 = $cb->plByPeriod('', '', '');
t('and a loan is not revenue', $pl2['UGX']['revenue_total'] ?? 0.0, 0.0);
t('the account now agrees with the bank, less the withdrawal still unexplained',
    $cb->accountBalance($bank), 3255960.0);

echo "\nDoes the book agree with the bank?\n";
// The only question that matters after an import. An account reading minus
// four million is alarming until you can say "that is the rows nobody has
// classified yet, to the shilling".
$recA = $imp->reconcile($bank, BankStatement::verifyChain($rows)['closing'], $dep['needs_decision']);
t('the bank ends where the statement says', $recA['bank'], 2755960.0);
t('the book is ahead of it',                $recA['book'], 3255960.0);
t('by the cash withdrawal still unbooked',  $recA['difference'], -500000.0);
t('which those rows account for exactly',   $recA['pending'], -500000.0);
t('so nothing is unexplained',              $recA['unexplained'], 0.0);
t('though it does not claim to agree',      $recA['agrees'], false);

// Before anything was classified, the gap is the deposit and the withdrawal
// together — still fully explained, just larger.
$tmpR = sys_get_temp_dir() . '/dn_bank_rec_' . bin2hex(random_bytes(4));
@mkdir($tmpR, 0777, true);
$sR = SqliteStore::create($tmpR); $cbR = new CashbookService($sR, $tmpR);
$bR = (int)($cbR->addAccount('Ecobank – UGX', 'UGX', 'bank')['id'] ?? 0);
$impR = new BankImport($cbR, $sR->getPdo());
$rowsR = BankStatement::parse($good)['rows'];
$closeR = BankStatement::verifyChain($rowsR)['closing'];
$wR = $impR->import($rowsR, $bR, ['commit' => true]);
$recR = $impR->reconcile($bR, $closeR, $wR['needs_decision']);
t('the account is short',        $recR['book'], -3744040.0);
t('against what the bank says',  $recR['bank'], 2755960.0);
t('by 6,500,000',                $recR['difference'], 6500000.0);
t('which is the deposit less the withdrawal', $recR['pending'], 6500000.0);
t('leaving nothing unexplained', $recR['unexplained'], 0.0);

echo "\nAnd when something else has touched the account\n";
// A hand-typed entry that is on no statement. This is the case that must be
// loud: every shilling of a difference should be a row the import refused to
// guess at, and anything else is money nobody can account for.
$cbR->addEntryRaw(['date' => '2026-09-06', 'direction' => 'out', 'amount' => 123456,
    'currency' => 'UGX', 'category' => 'Misc Expense', 'description' => 'Typed in by hand',
    'status' => 'approved', 'account_id' => $bR]);
$recX = $impR->reconcile($bR, $closeR, $wR['needs_decision']);
t('the pending rows no longer cover the gap', $recX['unexplained'], 123456.0);
t('and it does not agree', $recX['agrees'], false);

$toolPath = $root . '/tools/bank_statement.php';
file_put_contents($tmpR . '/s.csv', $good);
$oR = []; exec('DN_DATA_DIR=' . escapeshellarg($tmpR) . ' php ' . escapeshellarg($toolPath)
    . ' --file ' . escapeshellarg($tmpR . '/s.csv') . ' --account ' . $bR . ' 2>&1', $oR, $cR);
$tR = implode("\n", $oR);
is_(strpos($tR, 'AGAINST THE BANK') !== false, 'the tool reports it', $tR);
is_(strpos($tR, 'NOT accounted for') !== false,
    'and shouts when the gap is not just the pending rows', $tR);
is_(strpos($tR, '123,456') !== false, 'naming the amount nobody can explain');
exec('rm -rf ' . escapeshellarg($tmpR));

echo "\nThe same money reaching the book twice\n";
// The live trap: USD 10,000 of share capital was typed in when it arrived,
// and the statement that records it is about to be imported on top. Counted
// twice, the company looks twice as funded as it is.
$tmpD = sys_get_temp_dir() . '/dn_bank_dup_' . bin2hex(random_bytes(4));
@mkdir($tmpD, 0777, true);
$sD = SqliteStore::create($tmpD); $cbD = new CashbookService($sD, $tmpD);
$bD = (int)($cbD->addAccount('Ecobank – UGX', 'UGX', 'bank')['id'] ?? 0);
$cbD->addEntryRaw(['date' => '2026-06-09', 'direction' => 'in', 'amount' => 7000000,
    'currency' => 'UGX', 'category' => 'Share Capital', 'status' => 'approved',
    'source' => 'manual']);
$dD = (new BankImport($cbD, $sD->getPdo()))->import(
    BankStatement::parse($good)['rows'], $bD, ['commit' => true, 'deposits' => 'capital']);
is_(count(array_filter($dD['notes'], static fn($n) => strpos($n, 'arriving a second time') !== false)) === 1,
    'it warns that the book already holds capital', implode(' | ', $dD['notes']));
is_(strpos(implode(' ', $dD['notes']), '7,000,000') !== false, 'and how much');
t('once, not once per deposit',
    count(array_filter($dD['notes'], static fn($n) => strpos($n, 'arriving a second time') !== false)), 1);
t('the deposit is still booked — it warns, it does not refuse', $dD['posted'], 4);
exec('rm -rf ' . escapeshellarg($tmpD));

// ── A second account, a second currency ─────────────────────────────────────
echo "\nA USD statement cannot land in a UGX account\n";
$usdAcct = (int)($cb->addAccount('Ecobank Uganda – USD', 'USD', 'bank')['id'] ?? 0);
$usd = "Posting Date,Description,Debit,Credit,Running Balance,Transaction Reference\n"
     . "09/03/2026,Starlink 256781468254 UG - POS PURCHASE (ON-US),\$ 505.30,,\$ 9676.30,2455724045";
$ur  = BankStatement::parse($usd)['rows'];
$uw  = $imp->import($ur, $usdAcct, ['commit' => true]);
t('the USD row books against the USD account', $uw['posted'], 1);
// No UGX order matches a 505.30 USD debit — and it must not be forced into
// one. It goes to a USD suspense account of its own.
t('and finds no order behind it', $uw['suspense'], 1);
$usus = null;
foreach ($cb->accounts() as $a) if ($a['kind'] === 'asset' && $a['currency'] === 'USD') $usus = $a;
is_($usus !== null, 'with its own USD suspense account — currencies never merge');
t('holding dollars', $cb->accountBalance((int)$usus['id']), 505.3);
t('while the UGX suspense account is untouched by it',
    $cb->accountBalance((int)$sus['id']), 1859520.0);
$posU = $cb->currencyPositions();
is_(isset($posU['USD']) && isset($posU['UGX']), 'and the two currencies stay apart');

// ── The tool ────────────────────────────────────────────────────────────────
echo "\nThe tool\n";
$tool = $root . '/tools/bank_statement.php';
is_(is_file($tool), 'tools/bank_statement.php exists');
$tmp2 = sys_get_temp_dir() . '/dn_bank2_' . bin2hex(random_bytes(4));
@mkdir($tmp2, 0777, true);
file_put_contents($tmp2 . '/good.csv', $good);
file_put_contents($tmp2 . '/bad.csv', $bad);
$env = 'DN_DATA_DIR=' . escapeshellarg($tmp2) . ' ';

$o = []; exec($env . 'php ' . escapeshellarg($tool) . ' --file ' . escapeshellarg($tmp2 . '/good.csv') . ' 2>&1', $o, $c);
$txt = implode("\n", $o);
t('a bare run exits clean', $c, 0);
is_(strpos($txt, "agrees with the bank's own running balance") !== false, 'it verifies the chain', $txt);
is_(strpos($txt, 'Which account is this statement for?') !== false, 'and asks which account');

$o2 = []; exec($env . 'php ' . escapeshellarg($tool) . ' --file ' . escapeshellarg($tmp2 . '/bad.csv') . ' 2>&1', $o2, $c2);
$txt2 = implode("\n", $o2);
t('a broken statement is refused', $c2, 1);
is_(strpos($txt2, 'DOES NOT ADD UP') !== false, 'loudly', $txt2);
is_(strpos($txt2, 'out by') !== false, 'and by how much');

$o3 = []; exec($env . 'php ' . escapeshellarg($tool) . ' --file ' . escapeshellarg($tmp2 . '/good.csv') . ' --account X 2>&1', $o3, $c3);
t('a non-numeric account is refused, not ignored', $c3, 2);

$s2 = SqliteStore::create($tmp2);
$cb2 = new CashbookService($s2, $tmp2);
$b2 = (int)($cb2->addAccount('Ecobank Uganda – UGX', 'UGX', 'bank')['id'] ?? 0);
$o4 = []; exec($env . 'php ' . escapeshellarg($tool) . ' --file ' . escapeshellarg($tmp2 . '/good.csv')
    . ' --account ' . $b2 . ' --commit 2>&1', $o4, $c4);
$txt4 = implode("\n", $o4);
t('committing through the tool works', $c4, 0);
is_((bool)preg_match('/rows booked\s+3/', $txt4), 'three rows booked', $txt4);
is_(strpos($txt4, 'THESE NEED YOU') !== false, 'and it names what it would not guess');
t('the ledger has them',
    (int)$s2->getPdo()->query("SELECT COUNT(*) FROM cb_ledger")->fetchColumn(), 5);

exec('rm -rf ' . escapeshellarg($tmp) . ' ' . escapeshellarg($tmp2));
echo "\n  {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
