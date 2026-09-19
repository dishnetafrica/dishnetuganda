<?php
declare(strict_types=1);
/**
 * test_portal_locale.php — the country hint on the customer login screen.
 *
 * It used to read "Include country code. South Sudan: +211" on every install,
 * hardcoded. On the Uganda box that instructs a customer to type the wrong
 * country's number, on the one screen where they have no way to know better —
 * and a number in the wrong format simply never receives its code.
 *
 * The rule these pin: Uganda gets the right hint with NO configuration, and
 * Juba's behaviour does not change at all.
 */
require_once dirname(__DIR__) . '/lib/PortalLocale.php';

$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function is_(bool $c, string $m): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m\n"; } }

echo "\nThe currency an install already sets decides the country\n";
// Nothing new to configure: every install sets its ledger currency on day one.
$ug = PortalLocale::dialHint(['currency_code' => 'UGX']);
t('Uganda dial code', $ug['code'], '+256');
t('named',            $ug['country'], 'Uganda');
t('with a plausible example', $ug['example'], '+256 7XX XXX XXX');

$ss = PortalLocale::dialHint(['currency_code' => 'SSP']);
t('South Sudan dial code', $ss['code'], '+211');
t('named',                 $ss['country'], 'South Sudan');

echo "\nAn install that configures nothing behaves exactly as before\n";
// The Sudan installation must not change because this exists.
t('no config at all', PortalLocale::dialHint([]),
  ['code' => '+211', 'country' => 'South Sudan', 'example' => '+211 9XX XXX XXX']);
t('an unknown currency falls back the same way',
  PortalLocale::dialHint(['currency_code' => 'ZZZ'])['country'], 'South Sudan');

echo "\nThe cashbook base currency answers when there is no display currency\n";
t('from cashbook_base_currency',
  PortalLocale::dialHint(['cashbook_base_currency' => 'UGX'])['code'], '+256');
t('and the display currency wins when both are set',
  PortalLocale::dialHint(['currency_code' => 'UGX', 'cashbook_base_currency' => 'SSP'])['code'], '+256');

echo "\nAn explicit setting overrides the currency\n";
// A DishNet operating somewhere its ledger currency does not imply.
$ke = PortalLocale::dialHint(['currency_code' => 'UGX',
                              'portal_dial_code' => '+254', 'portal_country_name' => 'Kenya']);
t('the typed code wins',    $ke['code'], '+254');
t('and the typed country',  $ke['country'], 'Kenya');
t('with Kenya\'s example',  $ke['example'], '+254 7XX XXX XXX');

echo "\nA half-configured install is not left worse off\n";
t('code only — country comes from the currency',
  PortalLocale::dialHint(['currency_code' => 'UGX', 'portal_dial_code' => '+256'])['country'], 'Uganda');
t('country only — code comes from the currency',
  PortalLocale::dialHint(['currency_code' => 'UGX', 'portal_country_name' => 'Uganda Ltd'])['code'], '+256');

echo "\nA dial code typed loosely is cleaned, not rejected\n";
foreach (['256', ' +256 ', '00256', '+2 5 6'] as $typed) {
    t("\"$typed\"", PortalLocale::dialHint(['portal_dial_code' => $typed])['code'],
      $typed === '00256' ? '+00256' : '+256');
}
t('and an empty one does not blank the hint',
  PortalLocale::dialHint(['portal_dial_code' => '   '])['code'], '+211');

echo "\nAn unknown explicit code still gets a usable example\n";
$odd = PortalLocale::dialHint(['portal_dial_code' => '+44', 'portal_country_name' => 'UK']);
t('the code is shown',  $odd['code'], '+44');
is_(strpos($odd['example'], '+44') === 0, 'and the example starts with it');

echo "\nThe login page no longer hardcodes a country\n";
$page = (string)file_get_contents(dirname(__DIR__) . '/tabs/customer_app/login_web.php');
is_(strpos($page, 'South Sudan: <strong>+211</strong>') === false,
    'the hardcoded hint is gone');
is_(strpos($page, 'PortalLocale::dialHint(') !== false,
    'and the hint is resolved for this install');
is_(strpos($page, 'ConfigVault::fill(') !== false,
    'through the vault, because currency_code can live only there');
is_(substr_count($page, "htmlspecialchars(\$dial") === 3,
    'all three pieces are escaped into the page');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
