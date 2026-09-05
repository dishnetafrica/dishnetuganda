<?php
declare(strict_types=1);
/**
 * EfrisInvoiceMapper: uCRM → internal model. Tolerant tax reading across the
 * shapes uCRM has shipped, validation that blocks what URA would bounce, and
 * the rule that nothing official is guessed (unmapped commodity codes and
 * unresolvable tax categories surface as warnings, never invented values).
 */
require_once dirname(__DIR__) . '/lib/EfrisInvoiceMapper.php';

$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function has(array $list, string $needle): bool {
    foreach ($list as $x) if (stripos($x, $needle) !== false) return true;
    return false;
}

$cfg = [
    'efris_tin' => '1059140632', 'efris_device_no' => 'DEV-TEST-1',
    'efris_legal_name' => 'DishNet Africa Limited', 'efris_address' => 'Mawanda Road, Kampala',
];
$client = [
    'id' => 7, 'firstName' => 'Bhavin', 'lastName' => 'M', 'companyName' => 'Kampala Traders Ltd',
    'street1' => 'Plot 1', 'city' => 'Kampala',
    'contacts' => [['phone' => '256700000001', 'email' => 'buyer@example.com']],
    'attributes' => [
        ['key' => 'efrisTin', 'value' => '1000000001'],
        ['key' => 'efrisBrn', 'value' => 'BRN-77'],
        ['key' => 'efrisTaxpayerType', 'value' => 'VAT-registered'],
        ['key' => 'salesPerson', 'value' => 'Yash'],
    ],
];
$invoice = [
    'id' => 101, 'number' => 'INV-2026-00125', 'status' => 3, 'currencyCode' => 'UGX',
    'createdDate' => '2026-09-01T10:00:00+0300', 'maturityDate' => '2026-09-15',
    'total' => 388220.0, 'amountPaid' => 388220.0,
    'items' => [
        ['label' => 'DishNet Home', 'quantity' => 1, 'price' => 329000.0, 'total' => 329000.0,
         'taxes' => [['id' => 1, 'name' => 'VAT 18%', 'rate' => 18, 'totalValue' => 59220.0]]],
    ],
];

echo "Happy path: business buyer, taxes[] shape\n";
$m = (new EfrisInvoiceMapper($cfg,
        ['dishnet home' => '99999999'], ['vat 18%' => 'standard']))->map($invoice, $client);
t('maps ok', $m['ok'], true);
t('seller tin from config', $m['model']['seller']['tin'], '1059140632');
t('buyer is business (has TIN + company)', $m['model']['buyer']['type'], 'business');
t('buyer TIN from custom attribute', $m['model']['buyer']['tin'], '1000000001');
t('buyer BRN read', $m['model']['buyer']['brn'], 'BRN-77');
t('taxpayer type read', $m['model']['buyer']['taxpayer_type'], 'VAT-registered');
t('payment status from uCRM status 3', $m['model']['invoice']['payment_status'], 'paid');
t('tax rate read, not hard-coded', $m['model']['items'][0]['tax']['rate'], 18.0);
t('tax amount read', $m['model']['items'][0]['tax']['amount'], 59220.0);
t('tax category via operator map', $m['model']['items'][0]['tax_category'], 'standard');
t('commodity code via operator map', $m['model']['items'][0]['commodity_code'], '99999999');
t('tax shape recorded for the probe', $m['model']['meta']['tax_shapes'], ['taxes[]']);
t('no warnings when fully mapped', $m['warnings'], []);

echo "\nUnmapped codes warn, never invent\n";
$m2 = (new EfrisInvoiceMapper($cfg))->map($invoice, $client);
t('still ok (warnings, not errors, in Phase 1)', $m2['ok'], true);
t('warns about the missing commodity code', has($m2['warnings'], 'commodity code'), true);
t('warns about the unresolvable tax category', has($m2['warnings'], 'tax category'), true);
t('commodity stays null — never guessed', $m2['model']['items'][0]['commodity_code'], null);

echo "\nOther uCRM tax shapes\n";
$inv3 = $invoice;
$inv3['items'] = [['label' => 'Starlink Mini Kit', 'quantity' => 1, 'price' => 2249000.0,
                   'total' => 2249000.0, 'tax1' => ['id' => 2, 'name' => 'VAT', 'rate' => 18.0, 'totalValue' => 404820.0]]];
$m3 = (new EfrisInvoiceMapper($cfg, [], ['id:2' => 'standard']))->map($inv3, $client);
t('tax1 shape detected', $m3['model']['meta']['tax_shapes'], ['tax1']);
t('category resolvable by uCRM tax id', $m3['model']['items'][0]['tax_category'], 'standard');

$inv4 = $invoice;
$inv4['items'] = [['label' => 'Install', 'quantity' => 1, 'price' => 150000.0, 'total' => 150000.0]];
$m4 = (new EfrisInvoiceMapper($cfg, [], ['__no_tax__' => 'exempt']))->map($inv4, $client);
t('tax-free line: operator decides the category', $m4['model']['items'][0]['tax_category'], 'exempt');
t('no-tax shape recorded', $m4['model']['meta']['tax_shapes'], ['none']);

echo "\nBlocking validation\n";
$draft = $invoice; $draft['status'] = 0; $draft['number'] = '';
$md = (new EfrisInvoiceMapper($cfg))->map($draft, $client);
t('draft blocked', $md['ok'], false);
t('says why (draft)', has($md['errors'], 'DRAFT'), true);

$void = $invoice; $void['status'] = 4;
t('void blocked', (new EfrisInvoiceMapper($cfg))->map($void, $client)['ok'], false);

$empty = $invoice; $empty['items'] = [];
t('no line items blocked', has((new EfrisInvoiceMapper($cfg))->map($empty, $client)['errors'], 'no line items'), true);

$noTin = (new EfrisInvoiceMapper(['efris_device_no' => 'D']))->map($invoice, $client);
t('missing seller TIN blocks', has($noTin['errors'], 'Seller TIN'), true);

echo "\nBuyer edge cases\n";
$individual = $client;
$individual['companyName'] = '';
$individual['attributes'] = [];
$mi = (new EfrisInvoiceMapper($cfg))->map($invoice, $individual);
t('no TIN + no company = individual buyer', $mi['model']['buyer']['type'], 'individual');

$badTin = $client;
$badTin['attributes'] = [['key' => 'efrisTin', 'value' => '12AB']];
$mb = (new EfrisInvoiceMapper($cfg))->map($invoice, $badTin);
t('non-10-digit TIN warns', has($mb['warnings'], 'not 10 digits'), true);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
