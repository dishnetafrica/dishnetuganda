<?php
declare(strict_types=1);
/**
 * EfrisInvoicePdf labelling rules — the honesty contract of the fiscal PDF:
 * never present a test or unfiscalised document as an official URA fiscal
 * invoice, and print fiscal values only from the stored transaction row.
 */
require_once dirname(__DIR__) . '/lib/EfrisInvoicePdf.php';

$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }

$model = [
    'seller' => ['tin' => '1059140632', 'legal_name' => 'DishNet Africa Limited',
                 'address' => 'Mawanda Road, Kampala', 'phone' => '256705993348',
                 'email' => 'info@dishnetafrica.com', 'device_no' => 'DEV-1'],
    'invoice' => ['ucrm_id' => 101, 'number' => 'INV-2026-00125', 'issued_date' => '2026-09-01',
                  'due_date' => '2026-09-15', 'currency' => 'UGX', 'payment_status' => 'paid'],
    'buyer' => ['type' => 'business', 'name' => 'Kampala Traders Ltd', 'tin' => '1000000001',
                'address' => 'Plot 1, Kampala', 'phone' => '256700000001', 'email' => 'b@example.com'],
    'items' => [['label' => 'DishNet Home', 'qty' => 1.0, 'unit_price' => 329000.0, 'discount' => 0.0,
                 'line_total' => 329000.0, 'tax' => ['name' => 'VAT 18%', 'rate' => 18.0, 'amount' => 59220.0],
                 'tax_category' => 'standard', 'commodity_code' => null]],
    'totals' => ['subtotal' => 329000.0, 'tax_total' => 59220.0, 'grand' => 388220.0],
];
$pdf = new EfrisInvoicePdf(sys_get_temp_dir(), []);

echo "Not fiscalised\n";
$html = $pdf->buildHtml($model, null);
t('says NOT FISCALISED', strpos($html, 'EFRIS STATUS: NOT FISCALISED') !== false, true);
t('never claims EFRIS FISCALISED', strpos($html, '>EFRIS FISCALISED<') === false, true);
t('explains it is not a fiscal invoice', stripos($html, 'not a fiscal invoice') !== false, true);
t('invoice number rendered', strpos($html, 'INV-2026-00125') !== false, true);
t('currency shown with amounts', strpos($html, 'UGX 388,220.00') !== false, true);
t('VAT rate from data, not hard-coded copy', strpos($html, '18%') !== false, true);

echo "\nTest fiscalisation is loudly test\n";
$txTest = ['status' => 'FISCALISED', 'environment' => 'test', 'fdn' => 'TEST-FDN-000001',
           'verification_code' => 'TEST-VERIFICATION-000001', 'qr_data' => 'TEST-QR|TEST-FDN-000001',
           'efris_reference' => 'TEST-REF-000001', 'fiscalised_at' => '2026-09-05 10:00:00'];
$html = $pdf->buildHtml($model, $txTest);
t('says TEST FISCALISED', strpos($html, 'TEST FISCALISED') !== false, true);
t('says SIMULATED / NOT OFFICIAL', strpos($html, 'NOT AN OFFICIAL URA FISCAL DOCUMENT') !== false, true);
t('every fiscal row carries the TEST VALUE badge', substr_count($html, 'TEST VALUE — NOT ISSUED BY URA') >= 4, true);
t('prints the stored TEST FDN verbatim', strpos($html, 'TEST-FDN-000001') !== false, true);
t('does not use the production banner', strpos($html, '>EFRIS FISCALISED<') === false, true);

echo "\nProduction banner (template only — the connector refuses in Phase 1)\n";
$txProd = ['status' => 'FISCALISED', 'environment' => 'production', 'fdn' => 'FDN-REAL',
           'verification_code' => 'VC-REAL', 'qr_data' => 'QR-REAL', 'fiscalised_at' => '2026-09-05'];
$html = $pdf->buildHtml($model, $txProd);
t('production shows EFRIS FISCALISED', strpos($html, '>EFRIS FISCALISED<') !== false, true);
t('no TEST badge on production values', strpos($html, 'TEST VALUE — NOT ISSUED BY URA') === false, true);
t('prints only the stored values', strpos($html, 'FDN-REAL') !== false, true);

echo "\nEdited-after-fiscalisation warning\n";
$txAdj = $txTest; $txAdj['status'] = 'NEEDS_ADJUSTMENT';
$html = $pdf->buildHtml($model, $txAdj);
t('flags the adjustment requirement', strpos($html, 'CREDIT/DEBIT NOTE REQUIRED') !== false, true);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
