<?php
declare(strict_types=1);
/**
 * test_starlink_invoice_import.php — reading a Starlink invoice.
 *
 * dishnet-starlink-finance ingested 24 of these and recorded the header of
 * every one and the money of none — subtotal 0, VAT 0, total 0, status
 * pending. A bill recorded at zero is worse than a missing one, because it
 * looks like a fact. So the thing under test here is not "does it parse" but
 * "does it refuse when it has misread".
 *
 * The fixtures are invented. Real supplier pricing does not belong in a repo,
 * and a test that depends on this month's invoice breaks next month.
 */
$pass = 0; $fail = 0;
function is_(bool $c, string $m): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m\n"; } }
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }

$root = dirname(__DIR__);
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/StockService.php';
require_once $root . '/lib/PurchaseService.php';
require_once $root . '/lib/StarlinkInvoiceImport.php';

/**
 * A hardware invoice in Starlink's layout, with invented figures that
 * reconcile: 100,000 + 50,000 + 10,000 shipping = 160,000, VAT 18% = 28,800,
 * total 188,800. Detail 118,000 + 59,000 = 177,000, plus shipping inc VAT
 * 11,800, reaches the same 188,800.
 */
function fixtureHardware(): string {
    return "                                          Receipt\n"
         . "Customer Tax Id: 1000000000               INV-DF-UGA-1111-22222-33\n"
         . "Attn: A Person\n"
         . "                                          Invoice Date: Saturday, September 12, 2026\n"
         . "                                          Customer Account: ACC-DF-11111111-22222-33\n"
         . "Product Description                          Qty            Amount\n"
         . "Test Widget                                    1       UGX 100,000\n"
         . "Regulatory Fee                                 1        UGX 50,000\n"
         . "Shipping & Handling - Standard                          UGX 10,000\n"
         . "Subtotal                                               UGX 160,000\n"
         . "VAT (18%)                                               UGX 28,800\n"
         . "Total Charges                                          UGX 188,800\n"
         . "Payment                                                UGX 188,800\n"
         . "Total Due                                                    UGX 0\n"
         . "\f                         Addon Lines\n"
         . "#   Product Description   Qty    Unit Price   Total Tax      Amount\n"
         . "1     Regulatory Fee       1     UGX 50,000    UGX 9,000  UGX 59,000\n"
         . "\f                        Hardware Lines\n"
         . "#   Product Description   Qty    Unit Price   Total Tax      Amount\n"
         . "1      Test Widget         1    UGX 100,000   UGX 18,000 UGX 118,000\n";
}

/**
 * A service invoice, in the OTHER date spelling and the OTHER currency
 * spelling, and bearing excise as the real ones do.
 *
 *   service line  80,000  + excise 12% 9,600 = 89,600, VAT 18% = 16,128
 *                 so its own tax is 9,600 + 16,128 = 25,728, amount 105,728
 *   addon         20,000  + VAT 18% 3,600                     amount  23,600
 *   subtotal      80,000 + 20,000 + 9,600 excise            = 109,600
 *   VAT 18% of the subtotal                                  =  19,728
 *   total                                                    = 129,328
 *
 * and 105,728 + 23,600 reaches the same 129,328. The service line's effective
 * tax rate is 32.16%, not 18% — which is the point of carrying a per-line rate.
 */
function fixtureService(): string {
    return "                                          Receipt\n"
         . "Customer Tax Id: 1000000000               INV-DF-UGA-4444-55555-66\n"
         . "                                          Invoice Date: Friday, 11 September 2026\n"
         . "                                          Customer Account: ACC-DF-44444444-55555-66\n"
         . "Product Description                          Qty            Amount\n"
         . "Residential 100 Mbps (Friday, 11 September 2026 - Sunday, 11 October 2026)   1     USh80,000\n"
         . "Regulatory Fee                                 1         USh20,000\n"
         . "Excise Tax (12%)                                          USh9,600\n"
         . "Subtotal                                                USh109,600\n"
         . "VAT (18%)                                                USh19,728\n"
         . "Total Charges                                           USh129,328\n"
         . "Payment                                                 USh129,328\n"
         . "Total Due                                                    USh0\n"
         . "\f                        Service Lines\n"
         . "#   Product Description   Qty   Unit Price   Total Tax      Amount\n"
         . "1   Residential 100 Mbps   1    USh80,000   USh25,728  USh105,728\n"
         . "\f                         Addon Lines\n"
         . "#   Product Description   Qty   Unit Price   Total Tax      Amount\n"
         . "1     Regulatory Fee       1    USh20,000     USh3,600    USh23,600\n";
}

echo "\nIt reads money in both of Starlink's spellings\n";
// UGX 1,107,408 and USh154,134 are the same shilling written two ways. A
// reader that knows only one silently loses half the invoices.
t('UGX with a space',  StarlinkInvoiceImport::money('UGX 1,107,408'), 1107408.0);
t('USh with none',     StarlinkInvoiceImport::money('USh154,134'),     154134.0);
t('and with decimals', StarlinkInvoiceImport::money('UGX 1,000.50'),   1000.50);
t('a bare number is not money', StarlinkInvoiceImport::money('154134'), null);
t('nor is prose',      StarlinkInvoiceImport::money('Total Due'),       null);

echo "\nIt reads dates in both of Starlink's spellings\n";
// One invoice says "Friday, 11 September 2026", the next says
// "Saturday, September 12, 2026". A wrong date puts a bill in the wrong period.
t('day first',   StarlinkInvoiceImport::date('Friday, 11 September 2026'),   '2026-09-11');
t('month first', StarlinkInvoiceImport::date('Saturday, September 12, 2026'), '2026-09-12');
// These lines wrap in the PDF, leaving the year on the next line. strtotime
// fills a missing year with the CURRENT one, which reads correctly today and
// books a December invoice twelve months out when it is read in January.
t('a year-less date is refused, not guessed',
  StarlinkInvoiceImport::date('Saturday, September 12,'), null);
t('even without the trailing comma', StarlinkInvoiceImport::date('September 12'), null);
t('and nonsense is null, not today',  StarlinkInvoiceImport::date('sometime soon'), null);

echo "\nA hardware invoice reads completely\n";
$r = StarlinkInvoiceImport::parse(fixtureHardware());
is_($r['ok'] === true, 'it parses');
$h = $r['invoice'];
t('invoice number', $h['invoice_number'], 'INV-DF-UGA-1111-22222-33');
t('account',        $h['account'],        'ACC-DF-11111111-22222-33');
t('date',           $h['date'],           '2026-09-12');
t('currency',       $h['currency'],       'UGX');
t('subtotal',       $h['subtotal'],       160000.0);
t('VAT',            $h['vat'],            28800.0);
t('VAT rate',       $h['vat_rate'],       18.0);
t('shipping',       $h['shipping'],       10000.0);
t('total',          $h['total'],          188800.0);
t('nothing due',    $h['due'],            0.0);
t('two detail lines', count($h['lines']), 2);
t('it reconciles',  StarlinkInvoiceImport::verify($h), []);

echo "\nA service invoice reads completely, in the other spellings\n";
$r2 = StarlinkInvoiceImport::parse(fixtureService());
is_($r2['ok'] === true, 'it parses');
$s = $r2['invoice'];
t('date the other way round', $s['date'],     '2026-09-11');
t('USh is UGX',               $s['currency'], 'UGX');
t('excise is captured',       $s['excise'],   9600.0);
t('at its stated rate',       $s['excise_rate'], 12.0);
t('no shipping on a service invoice', $s['shipping'], 0.0);
t('and it reconciles WITH the excise in the subtotal',
  StarlinkInvoiceImport::verify($s), []);

echo "\nA description containing commas, dates and a pipe survives\n";
// "Wall Mount | Mini" and "Residential 100 Mbps (Friday, 11 September 2026 …)"
// both contain characters that a naive split on whitespace or comma destroys.
$desc = array_column($s['lines'], 'description');
is_(in_array('Residential 100 Mbps', $desc, true), 'the service line keeps its name');
$sum = array_column($s['summary'], 'description');
is_(strpos((string)($sum[0] ?? ''), 'Sunday, 11 October 2026') !== false,
    'and the summary row keeps its whole billing period');

echo "\nIt refuses a document it has misread\n";
// Each of these is a real way a bill becomes a wrong number that looks right.
$noText = StarlinkInvoiceImport::parse('');
is_($noText['ok'] === false, 'an empty document');
$scan = StarlinkInvoiceImport::parse("REGISTRATION CERTIFICATE\nthis is a scan with no numbers");
is_($scan['ok'] === false, 'a scan with no text layer is refused, not booked at zero');
is_(strpos((string)$scan['error'], 'scanned') !== false, 'and says so plainly');
$noNum = StarlinkInvoiceImport::parse("Receipt\nSubtotal   UGX 1,000\nTotal Charges  UGX 1,180\n");
is_($noNum['ok'] === false, 'no invoice number');

echo "\nTampering with any figure is caught\n";
$bad = StarlinkInvoiceImport::parse(str_replace('UGX 188,800', 'UGX 999,999', fixtureHardware()));
is_($bad['ok'] === true, 'the tampered document still parses');
is_(StarlinkInvoiceImport::verify($bad['invoice']) !== [], 'but does not reconcile');

$badVat = StarlinkInvoiceImport::parse(str_replace('UGX 28,800', 'UGX 28,000', fixtureHardware()));
is_(StarlinkInvoiceImport::verify($badVat['invoice']) !== [], 'a VAT that is not the stated percentage');

$badLine = StarlinkInvoiceImport::parse(str_replace('UGX 118,000', 'UGX 120,000', fixtureHardware()));
$why = StarlinkInvoiceImport::verify($badLine['invoice']);
is_($why !== [], 'a detail line whose own arithmetic fails');
is_(strpos(implode(' ', $why), 'Test Widget') !== false, 'and it names the line');

echo "\nA misread invoice is never booked\n";
// verify() would be advisory if anything could bypass it, so book() re-runs
// it against a REAL PurchaseService and a real database — the question is
// what ends up in the ledger, not what a mock was told.
$tmp   = sys_get_temp_dir() . '/dn_slinv_' . bin2hex(random_bytes(4));
$store = SqliteStore::create($tmp);
$pdo   = $store->getPdo();
$stock = StockService::fromStore($store, $tmp);
$stock->ensureTables();
$imp   = new StarlinkInvoiceImport(new PurchaseService($pdo, $tmp, $stock));
$actor = ['id' => 0, 'name' => 'test'];
$rows  = static function (\PDO $db): int {
    return (int)$db->query("SELECT COUNT(*) FROM stock_purchases")->fetchColumn();
};

$res = $imp->book($bad['invoice'], $actor);
is_(($res['ok'] ?? true) === false, 'book() refuses it');
is_(strpos((string)($res['error'] ?? ''), 'reconcile') !== false, 'saying it does not reconcile');
t('and nothing reached the ledger', $rows($pdo), 0);

echo "\nA good invoice books once, however many times it is run\n";
$r1 = $imp->book($h, $actor);
is_(($r1['ok'] ?? false) === true, 'it books');
t('one purchase exists', $rows($pdo), 1);

$r2 = $imp->book($h, $actor);
is_(($r2['ok'] ?? false) === true, 'a second run succeeds');
is_(($r2['duplicate'] ?? false) === true, 'and says it is a duplicate');
t('still one purchase', $rows($pdo), 1);
t('the same one',       (int)($r2['id'] ?? 0), (int)($r1['id'] ?? -1));

echo "\nThe booked bill matches the invoice\n";
$got = $pdo->query("SELECT * FROM stock_purchases WHERE id = " . (int)$r1['id'])->fetch(\PDO::FETCH_ASSOC);
t('invoice number',        (string)$got['invoice_number'], 'INV-DF-UGA-1111-22222-33');
t('date',                  (string)$got['purchase_date'],  '2026-09-12');
t('total as billed',       round((float)$got['total_cost'], 2), 188800.0);
t('currency, unconverted', (string)$got['currency'],        'UGX');
t('the account as supplier ref', (string)$got['supplier_ref'], 'ACC-DF-11111111-22222-33');
is_(stripos((string)$got['supplier'], 'Starlink') !== false, 'and Starlink as the supplier');

echo "\nShipping is booked, not dropped\n";
// dr_orders.json drops every shipping line, which is why its totals cannot
// reconcile with the supplier's own figure. This must not repeat that.
$lines = $pdo->query("SELECT description, unit_cost, tax_rate, quantity FROM stock_purchase_items
                      WHERE purchase_id = " . (int)$r1['id'])->fetchAll(\PDO::FETCH_ASSOC);
$descs = array_column($lines, 'description');
t('three lines', count($lines), 3);
is_(in_array('Shipping & Handling', $descs, true), 'shipping is a line of its own');
is_(in_array('Test Widget', $descs, true),         'so is the hardware');

echo "\nEach line carries the rate that reproduces its own tax\n";
// A service line bears excise AND VAT; a hardware line bears only VAT.
// Storing a flat 18% everywhere multiplies back to the wrong money.
$r3 = $imp->book($s, $actor);
is_(($r3['ok'] ?? false) === true, 'the service invoice books');
$sl = $pdo->query("SELECT description, unit_cost, tax_rate, quantity FROM stock_purchase_items
                   WHERE purchase_id = " . (int)$r3['id'])->fetchAll(\PDO::FETCH_ASSOC);
foreach ($sl as $l) {
    $src = null;
    foreach ($s['lines'] as $o) if ($o['description'] === $l['description']) { $src = $o; break; }
    if ($src === null) continue;
    $back = round((float)$l['unit_cost'] * (float)$l['quantity'] * (float)$l['tax_rate'] / 100, 0);
    is_(abs($back - $src['tax']) <= 1.0,
        sprintf('%s at %.4f%% reproduces tax of %s', $l['description'],
                (float)$l['tax_rate'], number_format($src['tax'])));
}
$rates = [];
foreach ($sl as $l) $rates[$l['description']] = round((float)$l['tax_rate'], 2);
is_(($rates['Residential 100 Mbps'] ?? 0) > 18.0,
    'the service line is NOT booked at a flat 18% — excise is in it too');

array_map('unlink', (array)glob($tmp . '/*'));
@rmdir($tmp);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
