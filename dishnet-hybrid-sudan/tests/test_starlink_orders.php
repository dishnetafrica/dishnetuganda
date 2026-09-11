<?php
declare(strict_types=1);
/**
 * test_starlink_orders.php — the first real money this business spent.
 *
 * DishNet Uganda placed four Starlink orders. The supplier billed 1,859,520
 * each — a kit at 1,477,778, shipping at 98,086, and 283,656 of tax — and the
 * system knew nothing about any of it. dishnet-data-report fetches this exact
 * endpoint in a cron that is not registered in its manifest, and when it does
 * run it drops the shipping line and the serial number, so even that would
 * not have reconciled.
 *
 * The fixture is the real response, with the contact details replaced. The
 * figures, the order numbers and the one delivered serial are untouched,
 * because a test against invented money proves nothing about this import.
 *
 * What is asserted here, in order of what would hurt most if it broke:
 *
 *   The supplier's own total is what gets booked, and the half-shilling its
 *   arithmetic is out by is REPORTED, not quietly absorbed.
 *
 *   Four orders paid at the till leave nothing on the payables report.
 *
 *   AN ORDER IS NOT STOCK. Four kits ordered and one delivered is one unit.
 *
 *   Running it twice is not eight purchases.
 */
require_once dirname(__DIR__) . '/lib/StoreInterface.php';
require_once dirname(__DIR__) . '/lib/JsonStore.php';
require_once dirname(__DIR__) . '/lib/SqliteStore.php';
require_once dirname(__DIR__) . '/lib/StockService.php';
require_once dirname(__DIR__) . '/lib/PurchaseService.php';
require_once dirname(__DIR__) . '/lib/StarlinkOrderImport.php';

$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; } }

$root = dirname(__DIR__);
$tmp  = sys_get_temp_dir() . '/dn_slorders_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);
$store = SqliteStore::create($tmp);
$pdo   = $store->getPdo();
$stock = StockService::fromStore($store, $tmp);
$stock->ensureTables();
$kit = (int)($stock->saveCategory(['title' => 'Starlink Standard Kit (Gen 3)', 'sku' => 'SL-STD-G3',
    'service_type' => 'starlink', 'track_mode' => 'serial', 'buy_price' => 1477778])['id'] ?? 0);
$actor = ['id' => 0, 'name' => 'order import'];

$raw = json_decode((string)file_get_contents(__DIR__ . '/fixtures/starlink_orders_raw.json'), true);
is_(is_array($raw), 'the captured Starlink response loads');

// ── Reading the response ────────────────────────────────────────────────────
echo "\nReading the orders endpoint\n";
$orders = StarlinkOrderImport::fromRawApi($raw);
t('all four orders are read', count($orders), 4);
t('with the supplier total', $orders[0]['total'], 1859520.0);
t('and its tax',             $orders[0]['tax'],   283656.0);
t('in shillings',            $orders[0]['currency'], 'UGX');
t('already paid',            $orders[0]['paid'], true);
t('not cancelled',           $orders[0]['cancelled'], false);
t('both lines kept — kit AND shipping', count($orders[0]['lines']), 2);
t('the kit line',      $orders[0]['lines'][0]['price'], 1477778.0);
t('the shipping line', $orders[0]['lines'][1]['price'], 98086.0);
t('shipping is typed as shipping', $orders[0]['lines'][1]['product_type'], 5);

echo "\nThe serial only exists on the line that actually arrived\n";
t('three orders are still in transit', $orders[0]['lines'][0]['delivered'], false);
t('and carry no serial',               $orders[0]['lines'][0]['serial'], '');
$delivered = $orders[3];
t('the fourth shipped',        $delivered['shipped'], true);
t('its kit line is delivered', $delivered['lines'][0]['delivered'], true);
t('and names what arrived',    $delivered['lines'][0]['serial'], 'KIT409033426KFR');

// ── The payment feed ────────────────────────────────────────────────────────
echo "\nStarlink's own payment feed\n";
// The ORDER date is not the PAYMENT date. Order ORD-…NCEAA was placed on the
// 8th and its card charged at 22:18 UTC — which is 01:18 the next morning in
// Kampala, so it reaches the bank statement on the 9th. Reconciling on the
// order date puts it two days early and, with twelve identical payments in
// nine days, quietly ties it to the wrong debit.
$payRaw = json_decode((string)file_get_contents(__DIR__ . '/fixtures/starlink_payments_raw.json'), true);
$pf = StarlinkOrderImport::paymentsFromRawApi($payRaw);
t('four captured payments', count($pf), 4);
t('keyed by the order they paid for', isset($pf['ORD-DF-E92VQQNWJEYRPN5WQ1']), true);
t('with the day the card was charged', $pf['ORD-DF-NCEAA5MG73JS68L76B']['date'], '2026-09-08');
t('and the moment, to the second',
    $pf['ORD-DF-NCEAA5MG73JS68L76B']['datetime'], '2026-09-08T22:18:29.966295');
t('the amount',   $pf['ORD-DF-NCEAA5MG73JS68L76B']['amount'], 1859520.0);
t('the currency', $pf['ORD-DF-NCEAA5MG73JS68L76B']['currency'], 'UGX');
t('and Starlink\'s own payment id',
    $pf['ORD-DF-NCEAA5MG73JS68L76B']['ref'], '01a08319-eecd-1a4b-52bf-1da069719292');
// A declined card is not money. Booking one would show a bill as settled that
// the supplier is still waiting to be paid.
t('a failed payment is not a payment', isset($pf['ORD-DF-DECLINED-EXAMPLE']), false);

$tmpP = sys_get_temp_dir() . '/dn_slpay_' . bin2hex(random_bytes(4));
@mkdir($tmpP, 0777, true);
$sP = SqliteStore::create($tmpP); $pP = $sP->getPdo();
$stP = StockService::fromStore($sP, $tmpP); $stP->ensureTables();
$impP = new StarlinkOrderImport($pP, $tmpP, [], null, $stP);
$impP->import($orders, $actor, ['commit' => true, 'payments' => $pf]);
$paid = $pP->query("SELECT s.supplier_ref, p.paid_on, p.reference FROM stock_purchase_payments p
                    JOIN stock_purchases s ON s.id = p.purchase_id")->fetchAll(PDO::FETCH_ASSOC);
$byOrder = [];
foreach ($paid as $row) $byOrder[$row['supplier_ref']] = $row;
t('the payment is dated when the card was charged',
    $byOrder['ORD-DF-NCEAA5MG73JS68L76B']['paid_on'], '2026-09-08');
t('and carries Starlink\'s payment id as its reference',
    $byOrder['ORD-DF-NCEAA5MG73JS68L76B']['reference'], '01a08319-eecd-1a4b-52bf-1da069719292');

// Without the feed, the order date has to stand in — which is what it is,
// a stand-in, and the import says so rather than pretending otherwise.
$tmpQ = sys_get_temp_dir() . '/dn_slpay2_' . bin2hex(random_bytes(4));
@mkdir($tmpQ, 0777, true);
$sQ = SqliteStore::create($tmpQ); $pQ = $sQ->getPdo();
$stQ = StockService::fromStore($sQ, $tmpQ); $stQ->ensureTables();
(new StarlinkOrderImport($pQ, $tmpQ, [], null, $stQ))->import($orders, $actor, ['commit' => true]);
$note = $pQ->query("SELECT note FROM stock_purchase_payments LIMIT 1")->fetchColumn();
t('and says the date is the order date', $note, 'paid to Starlink at order time');
exec('rm -rf ' . escapeshellarg($tmpP) . ' ' . escapeshellarg($tmpQ));

// ── A dry run writes nothing ────────────────────────────────────────────────
echo "\nA dry run is a dry run\n";
$imp = new StarlinkOrderImport($pdo, $tmp, [], null, $stock);
$dry = $imp->import($orders, $actor, ['category_id' => $kit]);
t('four bills would be booked', $dry['purchases'], 4);
t('one kit would land',         $dry['units'], 1);
t('and nothing was written',
    (int)$pdo->query("SELECT COUNT(*) FROM stock_purchases")->fetchColumn(), 0);
t('no unit either',
    (int)$pdo->query("SELECT COUNT(*) FROM stock_units")->fetchColumn(), 0);

// ── Committing ──────────────────────────────────────────────────────────────
echo "\nBooking them\n";
$r = $imp->import($orders, $actor, ['commit' => true, 'category_id' => $kit]);
t('it succeeded',           $r['ok'], true);
t('four purchases exist',   $r['purchases'], 4);
t('nothing was skipped',    $r['skipped'], 0);
t('one unit was received',  $r['units'], 1);
t('and there are four purchase rows',
    (int)$pdo->query("SELECT COUNT(*) FROM stock_purchases")->fetchColumn(), 4);

$pur = new PurchaseService($pdo, $tmp, $stock);
$id  = (int)$pdo->query("SELECT id FROM stock_purchases WHERE supplier_ref='ORD-DF-1TV9KE23FHG7VBKXHL'")->fetchColumn();
is_($id > 0, 'the order number is on the bill as the supplier reference');
$d = $pur->detail($id);
t('booked against the supplier', $d['supplier'], 'Starlink');
t('under its invoice number',    $d['invoice_number'], 'INV-DF-UGA-2531-55173-40');
t('dated when it was ordered',   $d['purchase_date'], '2026-09-10');
t('in shillings',                $d['currency'], 'UGX');
t('two lines, shipping included', count($d['items']), 2);
t('the kit is stock',      (int)$d['items'][0]['category_id'], $kit);
t('the shipping is not',   $d['items'][1]['category_id'], null);
t('no serial on an ordered line', $d['items'][0]['serials'], []);

// ── The arithmetic ──────────────────────────────────────────────────────────
echo "\nThe supplier's arithmetic, not ours\n";
// 1,477,778 + 98,086 = 1,575,864 net. Starlink's 283,656 of tax on that is
// 18.0000304% — the rate is READ off the bill, never assumed to be 18.
t('the tax rate comes off the bill', $r['planned'][0]['rate'], 18.0);
t('the net of both lines',           $r['planned'][0]['net'], 1575864.0);
t('the kit line tax',   (float)$d['items'][0]['tax_amount'], 266000.04);
t('the shipping tax',   (float)$d['items'][1]['tax_amount'], 17655.48);
// 1,575,864 + 283,655.52 = 1,859,519.52 against a bill of 1,859,520.
t('the billed total is what Starlink billed', (float)$d['total_cost'], 1859520.0);
t('the lines total to their own sum',         (float)$d['subtotal'] + (float)$d['tax_total'], 1859519.52);
is_(abs((float)$d['total_cost'] - ((float)$d['subtotal'] + (float)$d['tax_total']) - 0.48) < 0.005,
    'and the half shilling between them is kept visible, not absorbed');

// ── Paid means paid ─────────────────────────────────────────────────────────
echo "\nPaid at the till means nothing on the payables report\n";
t('the bill is settled',   $d['status'], 'paid');
t('for the full amount',   (float)$d['amount_paid'], 1859520.0);
t('nothing outstanding',   $d['outstanding'], 0.0);
t('one payment recorded',  count($d['payments']), 1);
t('by card',               $d['payments'][0]['method'], 'card');
t('and nothing at all sits on the payables list', $pur->supplierBalances(), []);
is_(in_array('ORD-DF-1TV9KE23FHG7VBKXHL: billed 1,859,520.00 where its own lines come to '
   . '1,859,519.52 — booked at the billed figure, 0.48 difference recorded on it',
   $r['notes'], true), 'and their rounding is said out loud, not absorbed');

// An order that has NOT been paid must land on that list, or the payables
// report is only ever empty because nothing ever reaches it.
$tmpU = sys_get_temp_dir() . '/dn_slorders_unpaid_' . bin2hex(random_bytes(4));
@mkdir($tmpU, 0777, true);
$sU = SqliteStore::create($tmpU); $pU = $sU->getPdo();
$stU = StockService::fromStore($sU, $tmpU); $stU->ensureTables();
$unpaid = [$orders[0]];
$unpaid[0]['paid'] = false;
(new StarlinkOrderImport($pU, $tmpU, [], null, $stU))
    ->import($unpaid, $actor, ['commit' => true]);
$balU = (new PurchaseService($pU, $tmpU, $stU))->supplierBalances();
t('an unpaid order is owed to Starlink', count($balU), 1);
t('the whole bill',   (float)$balU[0]['outstanding'], 1859520.0);
t('to that supplier', $balU[0]['supplier'], 'Starlink');
t('in shillings',     $balU[0]['currency'], 'UGX');
$hU = $pU->query("SELECT status, amount_paid FROM stock_purchases")->fetch(PDO::FETCH_ASSOC);
t('and it is not marked paid', $hU['status'], 'received');
t('with nothing paid on it',   (float)$hU['amount_paid'], 0.0);
exec('rm -rf ' . escapeshellarg($tmpU));

// ── An order is not stock ───────────────────────────────────────────────────
echo "\nFour ordered, one delivered, one on the shelf\n";
t('exactly one unit',
    (int)$pdo->query("SELECT COUNT(*) FROM stock_units")->fetchColumn(), 1);
$u = $pdo->query("SELECT * FROM stock_units WHERE serial_number='KIT409033426KFR'")->fetch(PDO::FETCH_ASSOC);
is_(is_array($u), 'and it is the one that actually arrived');
t('in stock',             $u['status'], 'in_stock');
t('carrying its cost',    (float)$u['purchase_cost'], 1477778.0);
t('and its invoice',      $u['purchase_ref'], 'INV-DF-UGA-2172-53582-43');
t('no phantom zero-value bill was invented to hold it',
    (int)$pdo->query("SELECT COUNT(*) FROM stock_purchases WHERE total_cost = 0")->fetchColumn(), 0);
$mv = $pdo->query("SELECT * FROM stock_movements WHERE movement_type='inbound' ORDER BY id DESC")->fetch(PDO::FETCH_ASSOC);
is_(strpos((string)($mv['note'] ?? ''), 'ORD-DF-E92VQQNWJEYRPN5WQ1') !== false,
    'the movement says which order delivered it', (string)($mv['note'] ?? ''));
is_(in_array('A received kit is costed at its own order line. Shipping stays a cost on the bill '
   . 'and is not rolled into the unit — say so if you want landed cost instead.', $r['notes'], true),
    'and the costing choice is stated rather than assumed');

// ── Run it as often as you like ─────────────────────────────────────────────
echo "\nImporting the same four orders again\n";
$again = $imp->import($orders, $actor, ['commit' => true, 'category_id' => $kit]);
t('nothing new is booked', $again['purchases'], 0);
t('all four are recognised', $again['skipped'], 4);
t('no second unit',        $again['units'], 0);
t('still four purchases',
    (int)$pdo->query("SELECT COUNT(*) FROM stock_purchases")->fetchColumn(), 4);
t('still one unit',
    (int)$pdo->query("SELECT COUNT(*) FROM stock_units")->fetchColumn(), 1);
t('and still one payment',
    (int)$pdo->query("SELECT COUNT(*) FROM stock_purchase_payments")->fetchColumn(), 4);

echo "\nA delivery landing after the bill was booked\n";
// The common real case: the bill was imported while in transit, the kit
// arrives, the import runs again. The unit must appear without a second bill.
$tmp2 = sys_get_temp_dir() . '/dn_slorders2_' . bin2hex(random_bytes(4));
@mkdir($tmp2, 0777, true);
$s2 = SqliteStore::create($tmp2); $p2 = $s2->getPdo();
$st2 = StockService::fromStore($s2, $tmp2); $st2->ensureTables();
$k2 = (int)($st2->saveCategory(['title' => 'Starlink Kit', 'sku' => 'SL', 'service_type' => 'starlink',
    'track_mode' => 'serial', 'buy_price' => 1477778])['id'] ?? 0);
$imp2 = new StarlinkOrderImport($p2, $tmp2, [], null, $st2);

$inTransit = $orders;                                   // as at order time
$inTransit[3]['lines'][0]['delivered'] = false;
$inTransit[3]['lines'][0]['serial']    = '';
$first = $imp2->import($inTransit, $actor, ['commit' => true, 'category_id' => $k2]);
t('four bills, no stock', [$first['purchases'], $first['units']], [4, 0]);
$second = $imp2->import($orders, $actor, ['commit' => true, 'category_id' => $k2]);   // now delivered
t('the delivery adds the unit', $second['units'], 1);
t('without a second bill',      $second['purchases'], 0);
t('four purchases still',
    (int)$p2->query("SELECT COUNT(*) FROM stock_purchases")->fetchColumn(), 4);

// ── A cancelled order is not a bill ─────────────────────────────────────────
echo "\nA cancelled order\n";
$cancelled = $raw;
$cancelled['content']['results'] = [$raw['content']['results'][0]];
$cancelled['content']['results'][0]['orderNumber']   = 'ORD-DF-CANCELLED-0001';
$cancelled['content']['results'][0]['cancelledDate'] = '2026-09-11T08:00:00';
$c = $imp2->import(StarlinkOrderImport::fromRawApi($cancelled), $actor,
                   ['commit' => true, 'category_id' => $k2]);
t('it is not booked', $c['purchases'], 0);
is_(in_array('ORD-DF-CANCELLED-0001: cancelled, not booked', $c['notes'], true),
    'and the import says why');
t('no fifth purchase row',
    (int)$p2->query("SELECT COUNT(*) FROM stock_purchases")->fetchColumn(), 4);

// ── Delivered, but nowhere to put it ────────────────────────────────────────
echo "\nDelivered with no stock category given\n";
$tmp3 = sys_get_temp_dir() . '/dn_slorders3_' . bin2hex(random_bytes(4));
@mkdir($tmp3, 0777, true);
$s3 = SqliteStore::create($tmp3); $p3 = $s3->getPdo();
$st3 = StockService::fromStore($s3, $tmp3); $st3->ensureTables();
$imp3 = new StarlinkOrderImport($p3, $tmp3, [], null, $st3);
$nc = $imp3->import($orders, $actor, ['commit' => true]);   // no category_id
t('the bills are still booked', $nc['purchases'], 4);
t('but no unit is guessed at',  $nc['units'], 0);
is_(in_array('KIT409033426KFR has been delivered but no stock category was given '
   . '— pass --category to put it on the shelf', $nc['notes'], true),
    'and it says exactly what is needed to fix that');

// ── The other source, and what it costs ─────────────────────────────────────
echo "\nRead from dr_orders.json instead\n";
// Exactly what dishnet-data-report's cron_orders.php keeps: no parts[], and
// productType 5 skipped outright.
$drOrders = [];
foreach ($raw['content']['results'] as $o) {
    $keep = $o;
    $keep['orderDetails'] = [];
    foreach ($o['orderDetails'] as $line) {
        if ((int)($line['productType'] ?? 0) === 5) continue;
        unset($line['parts']);
        $keep['orderDetails'][] = $line;
    }
    $drOrders['ACC-DF-15757047-82765-60'][] = $keep;
}
$fromDr = StarlinkOrderImport::fromDrOrders($drOrders);
t('the four orders are still there', count($fromDr), 4);
t('but each has lost its shipping line', count($fromDr[0]['lines']), 1);
t('and the delivered serial is gone',   $fromDr[3]['lines'][0]['serial'], '');
t('so the source is marked incomplete', $fromDr[0]['source_complete'], false);

$tmp4 = sys_get_temp_dir() . '/dn_slorders4_' . bin2hex(random_bytes(4));
@mkdir($tmp4, 0777, true);
$s4 = SqliteStore::create($tmp4); $p4 = $s4->getPdo();
$st4 = StockService::fromStore($s4, $tmp4); $st4->ensureTables();
$k4 = (int)($st4->saveCategory(['title' => 'Starlink Kit', 'sku' => 'SL', 'service_type' => 'starlink',
    'track_mode' => 'serial', 'buy_price' => 1477778])['id'] ?? 0);
$dr = (new StarlinkOrderImport($p4, $tmp4, [], null, $st4))
        ->import($fromDr, $actor, ['commit' => true, 'category_id' => $k4]);
t('the bill is still booked from it', $dr['purchases'], 4);
t('but no kit can land',              $dr['units'], 0);
is_(in_array('ORD-DF-1TV9KE23FHG7VBKXHL: read from dr_orders.json, which drops shipping lines '
   . 'and serial numbers — the bill is booked but may under-total, and no unit can be created '
   . 'from it', $dr['notes'], true), 'and the import names what that source cost it');
// The tax rate read off a bill missing its shipping line is 19.19%, not 18 —
// which is the visible symptom of the missing line, and why the tool prints
// any rate that is not 18.
is_(abs($dr['planned'][0]['rate'] - 19.1946) < 0.001,
    'the rate comes out wrong, which is how you can tell a line is missing',
    'got ' . $dr['planned'][0]['rate']);

// ── What management then sees ───────────────────────────────────────────────
echo "\nAnd it reaches the report\n";
require_once dirname(__DIR__) . '/lib/ReportingService.php';
$rep = new ReportingService($store, $tmp, $pdo, ['currency_code' => 'UGX']);
$purch = $rep->purchasesTotal();
t('purchases are reported in shillings', $purch['UGX']['currency'], 'UGX');
t('at what was actually spent',          $purch['UGX']['value'], 7438080.0);
is_(strpos((string)$purch['UGX']['caveat'], '1,134,622 tax') !== false,
    'with the tax on it', (string)$purch['UGX']['caveat']);
$pay = $rep->payable();
t('nothing is owed to suppliers', $pay['UGX']['value'] ?? 0.0, 0.0);
$inv = $rep->inventory();
t('and the shelf is worth one kit', $inv['value']['value'], 1477778.0);
t('with no uncosted units',         $inv['uncosted_units'], 0);

// ── The tool ────────────────────────────────────────────────────────────────
echo "\nThe tool itself\n";
$tool = $root . '/tools/import_starlink_orders.php';
is_(is_file($tool), 'tools/import_starlink_orders.php exists');

$tmp5 = sys_get_temp_dir() . '/dn_slorders5_' . bin2hex(random_bytes(4));
@mkdir($tmp5, 0777, true);
$env = 'DN_DATA_DIR=' . escapeshellarg($tmp5) . ' ';
exec($env . 'php ' . escapeshellarg($tool) . ' --file '
   . escapeshellarg(__DIR__ . '/fixtures/starlink_orders_raw.json') . ' 2>&1', $out, $code);
$txt = implode("\n", $out);
t('a bare run exits clean', $code, 0);
is_(strpos($txt, 'dry run — nothing written') !== false, 'and says it wrote nothing', $txt);
is_(strpos($txt, 'ORD-DF-1TV9KE23FHG7VBKXHL') !== false, 'listing the orders it found');
is_(strpos($txt, '1,859,520') !== false, 'with the supplier totals');
is_(strpos($txt, 'KIT409033426KFR') !== false, 'and the kit that has landed');
is_(!is_file($tmp5 . '/plugin.sqlite3')
    || (int)(new PDO('sqlite:' . $tmp5 . '/plugin.sqlite3'))
         ->query("SELECT COUNT(*) FROM stock_purchases")->fetchColumn() === 0,
    'and a dry run really did not write to the database');

echo "\nAnd the whole way through, from the tool\n";
// The dry run above proved it writes nothing. This proves the same command
// with --commit actually books the bills and lands the kit, through the tool
// rather than through the class.
$s5  = SqliteStore::create($tmp5);
$st5 = StockService::fromStore($s5, $tmp5); $st5->ensureTables();
$k5  = (int)($st5->saveCategory(['title' => 'Starlink Standard Kit (Gen 3)', 'sku' => 'SL-STD-G3',
    'service_type' => 'starlink', 'track_mode' => 'serial', 'buy_price' => 1477778])['id'] ?? 0);
$outC = []; exec($env . 'php ' . escapeshellarg($tool) . ' --file '
    . escapeshellarg(__DIR__ . '/fixtures/starlink_orders_raw.json')
    . ' --category ' . $k5 . ' --commit 2>&1', $outC, $codeC);
$txtC = implode("\n", $outC);
t('it commits cleanly', $codeC, 0);
is_((bool)preg_match('/purchases booked\s+4/', $txtC), 'four bills booked', $txtC);
is_((bool)preg_match('/units received\s+1/', $txtC), 'one kit received');
$p5 = $s5->getPdo();
t('four purchases in the database',
    (int)$p5->query("SELECT COUNT(*) FROM stock_purchases")->fetchColumn(), 4);
t('7,438,080 of them',
    (float)$p5->query("SELECT SUM(total_cost) FROM stock_purchases")->fetchColumn(), 7438080.0);
t('one unit on the shelf',
    (int)$p5->query("SELECT COUNT(*) FROM stock_units WHERE status='in_stock'")->fetchColumn(), 1);
$outC2 = []; exec($env . 'php ' . escapeshellarg($tool) . ' --file '
    . escapeshellarg(__DIR__ . '/fixtures/starlink_orders_raw.json')
    . ' --category ' . $k5 . ' --commit 2>&1', $outC2, $codeC2);
is_((bool)preg_match('/already booked\s+4/', implode("\n", $outC2)),
    'and running the same command again books nothing twice', implode("\n", $outC2));
t('still four purchases',
    (int)$p5->query("SELECT COUNT(*) FROM stock_purchases")->fetchColumn(), 4);

echo "\n--category is understood or refused, never ignored\n";
// --category N was read as (int)'N' = 0 — the same value as "no category" —
// so the tool booked the bills, left the kit off the shelf, and reported
// success. A flag that is present is either understood or refused.
$tmp6 = sys_get_temp_dir() . '/dn_slorders6_' . bin2hex(random_bytes(4));
@mkdir($tmp6, 0777, true);
$env6 = 'DN_DATA_DIR=' . escapeshellarg($tmp6) . ' ';
$run = function (string $flags) use ($env6, $tool): array {
    $o = []; $c = 0;
    exec($env6 . 'php ' . escapeshellarg($tool) . ' --file '
       . escapeshellarg(__DIR__ . '/fixtures/starlink_orders_raw.json') . ' ' . $flags . ' 2>&1', $o, $c);
    return [implode("\n", $o), $c];
};
[$txtN, $codeN] = $run("--category N --commit");
t('a letter is refused', $codeN, 2);
is_(strpos($txtN, "not 'N'") !== false, 'and named back', $txtN);
is_(!is_file($tmp6 . '/plugin.sqlite3')
    || (int)(new PDO('sqlite:' . $tmp6 . '/plugin.sqlite3'))
         ->query("SELECT COUNT(*) FROM stock_purchases")->fetchColumn() === 0,
    'and no bill was booked on the way past it');

$s6  = SqliteStore::create($tmp6);
$st6 = StockService::fromStore($s6, $tmp6); $st6->ensureTables();
$bulk = (int)($st6->saveCategory(['title' => 'CAT6 Cable (m)', 'sku' => 'CAT6',
    'service_type' => 'general', 'track_mode' => 'quantity'])['id'] ?? 0);
[$txt9, $code9] = $run("--category 999 --commit");
t('a category that does not exist is refused', $code9, 2);
is_(strpos($txt9, 'no stock category 999') !== false, 'and said so', $txt9);
[$txtB, $codeB] = $run("--category {$bulk} --commit");
t('a quantity-tracked category is refused', $codeB, 2);
is_(strpos($txtB, 'counted by quantity') !== false,
    'because a kit arrives with a serial on it', $txtB);
t('and still nothing is booked',
    (int)$s6->getPdo()->query("SELECT COUNT(*) FROM stock_purchases")->fetchColumn(), 0);

echo "\nCreating the category as part of the import\n";
[$txtD, $codeD] = $run("--new-category 'Starlink Standard Kit (Gen 3)'");
t('a dry run still writes nothing', $codeD, 0);
is_(strpos($txtD, 'Would create a serial-tracked stock category') !== false,
    'but says what it would create', $txtD);
t('and no category was created',
    (int)$s6->getPdo()->query("SELECT COUNT(*) FROM stock_categories WHERE track_mode='serial'")->fetchColumn(), 0);
[$txtM, $codeM] = $run("--new-category 'Starlink Standard Kit (Gen 3)' --commit");
t('with --commit it is created', $codeM, 0);
is_((bool)preg_match('/units received\s+1/', $txtM), 'and the kit lands in it', $txtM);
$made = $s6->getPdo()->query("SELECT * FROM stock_categories WHERE track_mode='serial'")->fetch(PDO::FETCH_ASSOC);
t('tracked by serial',      $made['track_mode'], 'serial');
t('as Starlink equipment',  $made['service_type'], 'starlink');
t('priced at what Starlink charges for one', (float)$made['buy_price'], 1477778.0);
[$txtX, $codeX] = $run("--category 1 --new-category 'Another' --commit");
t('asking for both at once is refused', $codeX, 2);

echo "\nBills booked first, category chosen afterwards\n";
// Exactly what happened live: the bills were booked before a stock category
// existed. The kit must be able to land later without re-booking anything.
$tmp7 = sys_get_temp_dir() . '/dn_slorders7_' . bin2hex(random_bytes(4));
@mkdir($tmp7, 0777, true);
$env7 = 'DN_DATA_DIR=' . escapeshellarg($tmp7) . ' ';
$o7 = []; exec($env7 . 'php ' . escapeshellarg($tool) . ' --file '
    . escapeshellarg(__DIR__ . '/fixtures/starlink_orders_raw.json') . ' --commit 2>&1', $o7, $c7);
is_((bool)preg_match('/purchases booked\s+4/', implode("\n", $o7)), 'four bills, no category');
$s7 = SqliteStore::create($tmp7); $p7 = $s7->getPdo();
t('and nothing on the shelf',
    (int)$p7->query("SELECT COUNT(*) FROM stock_units")->fetchColumn(), 0);
$o7b = []; exec($env7 . 'php ' . escapeshellarg($tool) . ' --file '
    . escapeshellarg(__DIR__ . '/fixtures/starlink_orders_raw.json')
    . " --new-category 'Starlink Standard Kit (Gen 3)' --commit 2>&1", $o7b, $c7b);
$t7b = implode("\n", $o7b);
is_((bool)preg_match('/purchases booked\s+0/', $t7b), 'the second run books nothing again', $t7b);
is_((bool)preg_match('/already booked\s+4/', $t7b), 'it recognises all four');
is_((bool)preg_match('/units received\s+1/', $t7b), 'and the kit finally lands');
t('four purchases, not eight',
    (int)$p7->query("SELECT COUNT(*) FROM stock_purchases")->fetchColumn(), 4);
t('one unit',
    (int)$p7->query("SELECT COUNT(*) FROM stock_units")->fetchColumn(), 1);
$ref = $p7->query("SELECT reference_id FROM stock_movements WHERE movement_type='inbound'")->fetchColumn();
$owner = (int)$p7->query("SELECT id FROM stock_purchases WHERE supplier_ref='ORD-DF-E92VQQNWJEYRPN5WQ1'")->fetchColumn();
t('booked against the order that delivered it', (int)$ref, $owner);
exec('rm -rf ' . escapeshellarg($tmp6) . ' ' . escapeshellarg($tmp7));

echo "\nA file with a session cookie in it is refused\n";
// Saving the whole request instead of the response body puts a live Starlink
// login on disk. Reading around it would teach the habit.
$bad = $tmp5 . '/with_cookie.json';
file_put_contents($bad, "cookie: Starlink.Com.Access.V1=eyJhbGciOi_not_a_real_token; path=/\n"
                      . (string)file_get_contents(__DIR__ . '/fixtures/starlink_orders_raw.json'));
$out2 = []; exec($env . 'php ' . escapeshellarg($tool) . ' --file ' . escapeshellarg($bad) . ' 2>&1', $out2, $code2);
$txt2 = implode("\n", $out2);
t('it refuses', $code2, 1);
is_(strpos($txt2, 'SESSION COOKIE') !== false, 'and says why', $txt2);
is_(strpos($txt2, 'response BODY only') !== false, 'and what to do instead');

$out3 = []; exec($env . 'php ' . escapeshellarg($tool) . ' --wat 2>&1', $out3, $code3);
t('an unknown option is refused rather than ignored', $code3, 2);

echo "\nNo secret in the fixture\n";
$fx = (string)file_get_contents(__DIR__ . '/fixtures/starlink_orders_raw.json');
foreach (['Starlink.Com.Sso', 'Starlink.Com.Access', 'clientside-cookie', 'dishnetafrica.com'] as $leak) {
    is_(stripos($fx, $leak) === false, "the fixture carries no {$leak}");
}
$fp = (string)file_get_contents(__DIR__ . '/fixtures/starlink_payments_raw.json');
foreach (['Starlink.Com.Sso', 'Starlink.Com.Access', 'clientside-cookie'] as $leak) {
    is_(stripos($fp, $leak) === false, "nor does the payment fixture carry {$leak}");
}

foreach ([$tmp, $tmp2, $tmp3, $tmp4, $tmp5] as $dir) exec('rm -rf ' . escapeshellarg($dir));
echo "\n  {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
