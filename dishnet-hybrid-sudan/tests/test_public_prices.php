<?php
require_once dirname(__DIR__) . '/lib/PublicPriceFeed.php';

$pass=0; $fail=0;
function t(string $n, $got, $want){ global $pass,$fail;
  if ($got===$want){$pass++;printf("  ok   %s\n",$n);}
  else{$fail++;printf("  FAIL %s\n       got  %s\n       want %s\n",$n,var_export($got,true),var_export($want,true));}}

$plans = [
    ['name' => 'DishNet Flex 12', 'price' => 435000, 'downloadSpeed' => 100],
    ['name' => 'DishNet Lite',    'price' => 215000, 'downloadSpeed' => 100],
    ['name' => 'DishNet Home',    'price' => 299000, 'downloadSpeed' => 400],
    ['name' => '',                'price' => 1],          // junk row
    ['name' => 'No Price Plan'],                          // junk row
];
$products = [
    ['name' => 'Starlink Standard Package', 'price' => 2749000, 'description' => 'Kit + install + first month'],
    ['name' => 'Starlink Mini Package',     'price' => 2399000],
];
$cfg = ['currency_symbol' => 'UGX'];

echo "Shape and ordering\n";
$f = PublicPriceFeed::build($plans, $products, $cfg);
t('currency carried', $f['currency'], 'UGX');
t('junk rows dropped', count($f['plans']), 3);
t('plans sorted cheapest first', array_column($f['plans'], 'name'),
  ['DishNet Lite', 'DishNet Home', 'DishNet Flex 12']);
t('hardware sorted cheapest first', $f['hardware'][0]['name'], 'Starlink Mini Package');
t('VAT note present', $f['vat_note'] !== '', true);

echo "\nNothing internal can leak — the shape has no cost fields\n";
$json = json_encode($f);
foreach (['cost', 'margin', 'profit', 'supplier', 'starlink_cost'] as $forbidden) {
    t("no '{$forbidden}' key anywhere", str_contains($json, '"' . $forbidden . '"'), false);
}

echo "\nExclusions (e.g. Flex held back until the Starlink letter)\n";
$f2 = PublicPriceFeed::build($plans, $products, $cfg + ['public_price_exclude' => ['dishnet flex 12', 'Starlink Mini Package']]);
t('excluded plan is absent', in_array('DishNet Flex 12', array_column($f2['plans'], 'name'), true), false);
t('other plans remain', count($f2['plans']), 2);
t('excluded hardware is absent', count($f2['hardware']), 1);

echo "\nCORS allow-list\n";
t('defaults to the Uganda site', PublicPriceFeed::allowedOrigins([]),
  ['https://dishnetuganda.com', 'https://www.dishnetuganda.com']);
t('config overrides', PublicPriceFeed::allowedOrigins(['site_origins' => ['https://x.example']]),
  ['https://x.example']);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
