<?php
declare(strict_types=1);
/**
 * test_reporting.php — a management figure that admits what it rests on.
 *
 * The failure running through this whole system is a zero that means "I could
 * not ask" wearing the clothes of a zero that means "there is nothing". Tables
 * a migration never created. Cache keys stripped on read. Two plugins that
 * were not installed. Starlink accounts with no cookie, whose invoices came
 * back as "0 new invoices" rather than "nobody is logged in". Every one of
 * them reached a screen as a confident number.
 *
 * A reporting layer is where that becomes expensive, because a number on a
 * management screen is acted on. So no figure here is a bare float: each
 * carries what it was computed from and whether that basis is complete, and a
 * caller that prints one walks past the reason it might be wrong.
 *
 * The other rule is currency. A USD invoice added to a UGX one is wrong by a
 * factor of about 3,700 and looks entirely reasonable.
 */
require_once dirname(__DIR__) . '/lib/StoreInterface.php';
require_once dirname(__DIR__) . '/lib/JsonStore.php';
require_once dirname(__DIR__) . '/lib/SqliteStore.php';
require_once dirname(__DIR__) . '/lib/StockService.php';
require_once dirname(__DIR__) . '/lib/PurchaseService.php';
require_once dirname(__DIR__) . '/lib/ReportingService.php';

$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; } }

// ── An install with nothing on it ───────────────────────────────────────────
echo "\nA business that has not traded yet\n";
$empty = sys_get_temp_dir() . '/dn_rep_empty_' . bin2hex(random_bytes(4));
@mkdir($empty, 0777, true);
$es = SqliteStore::create($empty);
$er = new ReportingService($es, $empty, $es->getPdo(), ['book_base_currency' => 'UGX']);
$sum = $er->summary();

t('sales are zero', $sum['sales']['NONE']['value'], 0.0);
is_(strpos($sum['sales']['NONE']['caveat'], 'no invoice has been issued') !== false,
    'and say WHY they are zero, rather than just being zero',
    json_encode($sum['sales']));
// The distinction the whole system keeps getting wrong.
is_($sum['payments']['NONE']['complete'] === false,
    'payments received is marked incomplete');
is_(strpos($sum['payments']['NONE']['caveat'], 'cached per invoice') !== false,
    'because that cache only holds invoices somebody has opened — a floor, not a total');
$w = implode(' | ', $sum['warnings']);
is_(strpos($w, 'nothing to read, not because nothing was sold') !== false,
    'and the warnings say it in words', $w);
is_(strpos($w, 'No stock units exist') !== false, 'including why inventory is zero');
t('margin is not offered at all', $sum['margin']['available'], false);
is_(strpos($sum['margin']['reason'], 'no equipment has been installed') !== false,
    'with the reason, not a zero percent', $sum['margin']['reason']);
exec('rm -rf ' . escapeshellarg($empty));

// ── An install that has traded ──────────────────────────────────────────────
echo "\nA business with real transactions\n";
$tmp = sys_get_temp_dir() . '/dn_rep_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);
$store = SqliteStore::create($tmp);
$pdo   = $store->getPdo();
$stock = StockService::fromStore($store, $tmp);
$stock->ensureTables();
$pur   = new PurchaseService($pdo, $tmp, $stock);
$staff = ['id' => 7, 'name' => 'Bhavin'];

$store->save('ucrm_invoices_cache.json', [
    ['id' => 5001, 'clientId' => 4021, 'number' => 'INV-5001', 'total' => 2649000,
     'amountPaid' => 2649000, 'status' => 4, 'currencyCode' => 'UGX',
     'createdDate' => '2026-09-11', 'dueDate' => '2026-09-11',
     'items' => [['label' => 'Starlink Standard Kit', 'quantity' => 1, 'total' => 2649000]]],
    ['id' => 5002, 'clientId' => 4021, 'number' => 'INV-5002', 'total' => 400000,
     'amountPaid' => 0, 'status' => 2, 'currencyCode' => 'UGX',
     'createdDate' => '2026-09-11', 'dueDate' => '2026-09-01',
     'items' => [['label' => 'Starlink Residential 50Mbps', 'quantity' => 1, 'total' => 400000]]],
    // A draft is not a sale. Counting it reports revenue nobody has been billed.
    ['id' => 5003, 'clientId' => 4021, 'number' => 'DRAFT', 'total' => 9999999,
     'amountPaid' => 0, 'status' => 1, 'currencyCode' => 'UGX',
     'createdDate' => '2026-09-11', 'items' => [['label' => 'Not really sold', 'total' => 9999999]]],
]);
$store->save('ucrm_invoice_payments_cache.json', [
    '5001' => ['payments' => [['id' => 900, 'amount' => 2649000, 'method' => 'Mobile Money',
                              'createdDate' => '2026-09-11']]],
]);
$store->save('ucrm_clients_cache.json', [['id' => 4021, 'companyName' => 'Family Shoppers Ltd',
    'isActive' => true, 'contacts' => []]]);

$kit = (int)($stock->saveCategory(['title' => 'Starlink Standard Kit', 'sku' => 'SL-STD',
    'service_type' => 'starlink', 'track_mode' => 'serial'])['id'] ?? 0);
$pur->receive(['supplier' => 'Starlink Uganda', 'invoice_number' => 'SL-1',
               'currency' => 'UGX', 'purchase_date' => '2026-09-10', 'idem_key' => 'r1'],
    [['category_id' => $kit, 'quantity' => 2, 'unit_cost' => 2144704, 'tax_rate' => 18,
      'serials' => ['KIT-A', 'KIT-B']]], $staff);
$uid = (int)$pdo->query("SELECT id FROM stock_units WHERE serial_number='KIT-A'")->fetchColumn();
$stock->install($uid, ['crm_client_id' => 4021, 'client_name' => 'Family Shoppers'], 7, 'Richard');

$rep = new ReportingService($store, $tmp, $pdo, ['book_base_currency' => 'UGX']);
$s = $rep->summary();

echo "\nSales\n";
t('billed in shillings', $s['sales']['UGX']['value'], 3049000.0);
is_(strpos(json_encode($s['sales']), '9999999') === false,
    'THE DRAFT IS NOT COUNTED — it would have reported revenue nobody was billed');
t('and the basis says how many invoices', $s['sales']['UGX']['basis'], '2 invoice(s)');
t('the figure is complete', $s['sales']['UGX']['complete'], true);

echo "\nReceivable\n";
t('what the customer still owes', $s['receivable']['UGX']['value'], 400000.0);
is_(strpos($s['receivable']['UGX']['caveat'], 'overdue') !== false,
    'flagging that it is overdue', json_encode($s['receivable']));

echo "\nPayments received\n";
t('what actually came in', $s['payments']['UGX']['value'], 2649000.0);

echo "\nPurchases and payable\n";
// 2 × 2,144,704 = 4,289,408 net, + 18% VAT 772,093.44 = 5,061,501.44 gross.
t('what was bought', $s['purchases']['UGX']['value'], 5061501.44);
is_(strpos($s['purchases']['UGX']['caveat'], 'including 772,093 tax') !== false,
    'and the tax inside it is stated', $s['purchases']['UGX']['caveat']);
t('and is still owed to the supplier', $s['payable']['UGX']['value'], 5061501.44);
$pur->recordPayment(1, ['amount' => 5061501.44], $staff);
$s2 = (new ReportingService($store, $tmp, $pdo, ['book_base_currency' => 'UGX']))->summary();
t('paying it clears the payable', $s2['payable']['NONE']['value'], 0.0);
t('but not the purchase total — that is what was bought, not what is owed',
  $s2['purchases']['UGX']['value'], 5061501.44);

echo "\nInventory and equipment\n";
// One kit on the shelf, one at the customer. Neither figure includes the other.
t('the shelf is worth one kit', $s['inventory']['value']['value'], 2144704.0);
t('in the book currency', $s['inventory']['value']['currency'], 'UGX');
t('one unit is at a customer', $s['equipment']['count'], 1);
t('worth what it cost us', $s['equipment']['value']['value'], 2144704.0);

echo "\nSales by product\n";
t('two lines', count($s['by_product']), 2);
t('biggest first', $s['by_product'][0]['label'], 'Starlink Standard Kit');
t('at its value', $s['by_product'][0]['total'], 2649000.0);
t('carrying its currency', $s['by_product'][0]['currency'], 'UGX');
is_(strpos(json_encode($s['by_product']), 'Not really sold') === false,
    'and the draft line is absent here too');

echo "\nNothing to warn about\n";
t('no warnings on a complete install', $s['warnings'], []);

// ── Two currencies are never one number ─────────────────────────────────────
echo "\nA USD invoice is not a UGX invoice\n";
$store->save('ucrm_invoices_cache.json', array_merge($store->load('ucrm_invoices_cache.json'), [
    ['id' => 6001, 'clientId' => 4021, 'number' => 'INV-6001', 'total' => 1000,
     'amountPaid' => 0, 'status' => 2, 'currencyCode' => 'USD',
     'createdDate' => '2026-09-11', 'items' => [['label' => 'Kit in dollars', 'total' => 1000]]],
]));
$s3 = (new ReportingService($store, $tmp, $pdo, ['book_base_currency' => 'UGX']))->summary();
is_(isset($s3['sales']['UGX']) && isset($s3['sales']['USD']),
    'each currency is its own figure', json_encode(array_keys($s3['sales'])));
t('the shilling total is untouched by the dollar one', $s3['sales']['UGX']['value'], 3049000.0);
t('and the dollars stay dollars', $s3['sales']['USD']['value'], 1000.0);
is_(!isset($s3['sales']['TOTAL']),
    'THERE IS NO COMBINED TOTAL — adding them is wrong by a factor of ~3,700');

// ── An invoice with no currency at all ──────────────────────────────────────
echo "\nAn invoice that does not say what it is billed in\n";
$store->save('ucrm_invoices_cache.json', [
    ['id' => 7001, 'clientId' => 4021, 'number' => 'INV-7001', 'total' => 500,
     'amountPaid' => 0, 'status' => 2, 'createdDate' => '2026-09-11', 'items' => []],
]);
$s4 = (new ReportingService($store, $tmp, $pdo, ['book_base_currency' => 'UGX']))->summary();
is_(isset($s4['sales']['UNKNOWN']), 'it is reported under UNKNOWN, not guessed at');
t('and marked incomplete', $s4['sales']['UNKNOWN']['complete'], false);
is_(strpos($s4['sales']['UNKNOWN']['caveat'], 'sum of unlike things') !== false,
    'saying why that matters', $s4['sales']['UNKNOWN']['caveat']);

// ── The period filter ───────────────────────────────────────────────────────
echo "\nAsking about a period\n";
$store->save('ucrm_invoices_cache.json', [
    ['id' => 8001, 'clientId' => 4021, 'number' => 'OLD', 'total' => 100000, 'amountPaid' => 0,
     'status' => 2, 'currencyCode' => 'UGX', 'createdDate' => '2026-01-15', 'items' => []],
    ['id' => 8002, 'clientId' => 4021, 'number' => 'NEW', 'total' => 250000, 'amountPaid' => 0,
     'status' => 2, 'currencyCode' => 'UGX', 'createdDate' => '2026-09-11', 'items' => []],
]);
$sep = (new ReportingService($store, $tmp, $pdo, []))->summary(['from' => '2026-09-01', 'to' => '2026-09-30']);
t('only September is counted', $sep['sales']['UGX']['value'], 250000.0);
t('and the period is stated back', $sep['period']['from'], '2026-09-01');
$all = (new ReportingService($store, $tmp, $pdo, []))->summary();
t('no period means everything', $all['sales']['UGX']['value'], 350000.0);
t('and says so', $all['period']['from'], '(all time)');

// ── Margin is refused rather than invented ──────────────────────────────────
echo "\nMargin, where it cannot honestly be computed\n";
$m = $all['margin'];
t('not available', $m['available'], false);
is_(strpos($m['reason'], 'linked to the invoice line') !== false,
    'because a unit is not tied to the line that sold it — inventing one from a '
    . 'catalogue price would be a made-up number on a management screen', $m['reason']);

// ── The printed report ──────────────────────────────────────────────────────
echo "\nWhat management actually reads\n";
$root = dirname(__DIR__);
$out = []; $rc = 0;
exec(sprintf('DN_DATA_DIR=%s php %s 2>&1', escapeshellarg($tmp),
     escapeshellarg($root . '/tools/report.php')), $out, $rc);
$r = implode("\n", $out);
is_($rc === 0, 'it runs', substr($r, 0, 300));
foreach (['SALES', 'RECEIVABLE', 'PAYMENTS RECEIVED', 'PURCHASES', 'PAYABLE',
          'INVENTORY', 'GROSS MARGIN'] as $section) {
    is_(strpos($r, $section) !== false, "it prints {$section}");
}
is_(strpos($r, 'not available —') !== false,
    'and margin says why it is absent rather than printing a zero percent');

// Columns are padded in characters, not bytes. '—' is three bytes for one
// character, which shifted every block that used it against the ones that
// did not.
$cols = [];
foreach (explode("\n", $r) as $line) {
    if (preg_match('/^ {4}(\S.*?) +(-?[\d,]+)( |$)/u', $line, $m)) {
        $cols[] = mb_strpos($line, $m[2]) + mb_strlen($m[2]);
    }
}
is_(count($cols) > 3 && count(array_unique($cols)) === 1,
    'every amount ends in the same column', implode(',', array_unique($cols)));

[$o2, $rc2] = [null, 0];
$out2 = [];
exec(sprintf('DN_DATA_DIR=%s php %s --json 2>&1', escapeshellarg($tmp),
     escapeshellarg($root . '/tools/report.php')), $out2, $rc2);
$j = json_decode(implode("\n", $out2), true);
is_(is_array($j) && isset($j['sales'], $j['payable'], $j['warnings']),
    '--json gives the whole report to something else to render');

$out3 = []; $rc3 = 0;
exec(sprintf('DN_DATA_DIR=%s php %s --from nonsense 2>&1', escapeshellarg($tmp),
     escapeshellarg($root . '/tools/report.php')), $out3, $rc3);
is_($rc3 === 2 && strpos(implode(' ', $out3), 'must be a date') !== false,
    'and a date that is not a date is refused rather than silently ignored');

exec('rm -rf ' . escapeshellarg($tmp));
echo "\n  {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
