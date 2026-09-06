<?php
declare(strict_types=1);
/**
 * Phase A regression: the cashbook may never hard-code a payment currency
 * again. uCRM's currencyCode decides; configuration decides the base and the
 * selectable list; the Sudan defaults are preserved exactly (base USD,
 * currencies USD,SSP) so an install that configures nothing behaves as
 * before, byte for byte.
 */
require_once dirname(__DIR__) . '/lib/currency.php';

$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }

echo "Book base and selectable currencies\n";
t('Sudan default base is USD (nothing configured)', dn_book_base([]), 'USD');
t('Sudan default currencies are USD,SSP', dn_book_currencies([]), ['USD', 'SSP']);
t('Uganda base from config', dn_book_base(['cashbook_base_currency' => 'UGX']), 'UGX');
t('Uganda currencies: UGX first, USD kept, no SSP',
  dn_book_currencies(['cashbook_base_currency' => 'UGX', 'cashbook_currencies' => 'UGX,USD']),
  ['UGX', 'USD']);
t('base is forced to the front even if listed later',
  dn_book_currencies(['cashbook_base_currency' => 'UGX', 'cashbook_currencies' => 'USD,UGX']),
  ['UGX', 'USD']);
t('junk entries are dropped',
  dn_book_currencies(['cashbook_base_currency' => 'UGX', 'cashbook_currencies' => 'UGX, usd , 12A, US']),
  ['UGX', 'USD']);
t('lowercase config value normalised', dn_book_base(['cashbook_base_currency' => 'ugx']), 'UGX');
t('invalid base falls back to USD', dn_book_base(['cashbook_base_currency' => 'shillings']), 'USD');

echo "\nPayment currency comes from the payment, never a literal\n";
t('UGX uCRM payment books as UGX',
  dn_payment_currency(['currencyCode' => 'UGX', 'amount' => 329000], []), 'UGX');
t('USD uCRM payment books as USD — even on a UGX book',
  dn_payment_currency(['currencyCode' => 'USD'], ['cashbook_base_currency' => 'UGX']), 'USD');
t('payment without a currency falls back to the configured base',
  dn_payment_currency(['amount' => 5], ['cashbook_base_currency' => 'UGX']), 'UGX');
t('Sudan fallback stays USD', dn_payment_currency([], []), 'USD');
t('lowercase currencyCode normalised', dn_payment_currency(['currencyCode' => 'ugx'], []), 'UGX');
t('garbage currencyCode falls back', dn_payment_currency(['currencyCode' => 'U$D'], []), 'USD');

echo "\nNo hard-coded ledger currency remains in the write paths\n";
$root = dirname(__DIR__);
$writePaths = [
    'webhook.php',
    'cron_sync.php',
    'cron/payment_catchup_sync.php',
    'lib/CashbookService.php',
];
$viol = [];
foreach ($writePaths as $rel) {
    $src = (string)@file_get_contents($root . '/' . $rel);
    foreach (explode("\n", $src) as $i => $line) {
        $trim = ltrim($line);
        if ($trim === '' || strpos($trim, '//') === 0 || strpos($trim, '*') === 0) continue;
        if (preg_match("/'currency'\s*=>\s*'(USD|SSP|UGX)'/", $line)) {
            $viol[] = "$rel:" . ($i + 1) . '  ' . trim(substr($line, 0, 100));
        }
    }
}
t('zero literal currency stamps in ledger write paths', $viol, []);
if ($viol) echo '    ' . implode("\n    ", $viol) . "\n";

echo "\nEntry forms are config-driven (SSP cannot appear on a UGX install)\n";
$fe = (string)file_get_contents($root . '/tabs/support/field_expenses.php');
t('field_expenses has no hard-coded SSP radio', strpos($fe, 'value="SSP"') === false, true);
t('field_expenses renders from dn_book_currencies', substr_count($fe, 'dn_book_currencies($config)') >= 2, true);
$cb = (string)file_get_contents($root . '/tabs/accounts/cashbook.php');
t('cashbook pills render from dn_book_currencies', strpos($cb, 'dn_book_currencies($config)') !== false, true);
t('cashbook JS carries the allowed list', strpos($cb, '_cb4Currs = <?= json_encode(dn_book_currencies($config)) ?>') !== false, true);
t('SSP FX flows are gated on SSP being selectable', strpos($cb, "indexOf('SSP') === -1") !== false, true);
t("no fixed cb4PillSSP markup remains", strpos($cb, 'id="cb4PillSSP"') === false, true);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
