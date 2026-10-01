<?php
declare(strict_types=1);
/**
 * test_sales_support_tenant.php — 5.18.58: the sales/support cash screens show the
 * TENANT's own currency. On Uganda (dn_ssp_selectable=false) South Sudan's SSP never
 * renders and the base amounts read UGX; on South Sudan every byte is unchanged.
 *
 * SSP is South Sudan's local secondary cash. It leaked onto the Uganda field-agent
 * screens: the Field Expenses balance hero ("🇸🇸 SSP Balance"), the My Account and
 * Wallet SSP-first heroes / SSP cashbook / SSP currency pills, and fiber_costs' "$".
 *
 * (A) tenant helpers, (B) RENDER the Field Expenses balance hero under each tenant,
 * (C) fc_fmt() currency symbol per tenant, (D) the support-role flag collapses on
 * Uganda (the mechanism behind my_account/wallet), (E) structural gate checks for all
 * four files, (F) a control: strip the gate and the SSP tile returns on Uganda.
 *
 * No network, no secret, no staff name/phone appears here.
 */
$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d !== '' ? "\n       $d" : '') . "\n"; } }

$root = dirname(__DIR__);
require_once $root . '/lib/currency.php';

$UG = ['currency_symbol'=>'UGX','currency_code'=>'UGX','cashbook_base_currency'=>'UGX','cashbook_currencies'=>'UGX,USD'];
$SS = ['currency_symbol'=>'$','cashbook_base_currency'=>'USD','cashbook_currencies'=>'USD,SSP']; // South Sudan: symbol $

echo "A. tenant helpers\n";
is_(dn_ssp_selectable($UG) === false, 'Uganda: SSP is not bookable');
is_(dn_book_base($UG) === 'UGX', 'Uganda: base currency is UGX');
is_(trim(dn_cur($UG)) === 'UGX', 'Uganda: currency symbol is UGX');
is_(dn_ssp_selectable($SS) === true, 'South Sudan: SSP is bookable (control)');
is_(dn_book_base($SS) === 'USD', 'South Sudan: base currency is USD (control)');
is_(trim(dn_cur($SS)) === '$', 'South Sudan: currency symbol is $ (control)');

// ── Fragment helpers ──
function fragment(string $src, string $from, string $to): string {
    $a = strpos($src, $from); $b = ($a !== false) ? strpos($src, $to, $a) : false;
    return ($a !== false && $b !== false) ? substr($src, $a, $b - $a) : '';
}
function render(string $fragment, array $config, array $vars): string {
    $GLOBALS['config'] = $config;
    extract($vars, EXTR_SKIP);
    ob_start(); eval('?>' . $fragment); return (string)ob_get_clean();
}
function nc(string $f): string { $o=''; foreach (token_get_all((string)file_get_contents($f)) as $k){ if(is_array($k)){ if(in_array($k[0],[T_COMMENT,T_DOC_COMMENT],true)) continue; $o.=$k[1]; } else $o.=$k; } return $o; }

$T = $root . '/tabs/accounts/'; $Sp = $root . '/tabs/support/'; $Sl = $root . '/tabs/sales/';

// ── B. the Field Expenses balance hero, rendered as the agent sees it ──
echo "\nB. Field Expenses balance hero, rendered\n";
$fe = (string)file_get_contents($Sp . 'field_expenses.php');
$heroFrag = fragment($fe, '<!-- Stats -->', '<!-- Tab bar -->');
is_($heroFrag !== '', 'the balance-hero fragment was located');
$summary = ['by_currency'=>['USD'=>['balance'=>1200.0,'pending'=>0.0],'SSP'=>['balance'=>50000.0,'pending'=>0.0]],
            'active_advances'=>1,'pending_expenses'=>0];
$ugHero = render($heroFrag, $UG, ['summary'=>$summary]);
is_(strpos($ugHero, 'UGX Balance') !== false, 'Uganda: the base tile reads "UGX Balance"', $ugHero);
is_(strpos($ugHero, 'SSP Balance') === false, 'Uganda: there is NO "SSP Balance" tile');
is_(strpos($ugHero, '🇸🇸') === false, 'Uganda: the South Sudan flag never appears');
is_(strpos($ugHero, 'USD Balance') === false, 'Uganda: the base tile is not mislabeled "USD Balance"');
$ssHero = render($heroFrag, $SS, ['summary'=>$summary]);
is_(strpos($ssHero, '💵 USD Balance') !== false, 'South Sudan: the base tile reads "💵 USD Balance" (unchanged)');
is_(strpos($ssHero, '🇸🇸 SSP Balance') !== false, 'South Sudan: the "🇸🇸 SSP Balance" tile is still present (unchanged)');

// ── C. fc_fmt() — the base-currency symbol follows the tenant ──
echo "\nC. fiber_costs fc_fmt() currency symbol\n";
$fcSrc = (string)file_get_contents($T . 'fiber_costs.php');
$fcFn = fragment($fcSrc, 'function fc_fmt(', "\n}\n") . "\n}";
is_($fcFn !== '' && strpos($fcFn, 'dn_cur($config)') !== false, 'fc_fmt derives its symbol from dn_cur');
if (!function_exists('fc_fmt')) { eval($fcFn); }
$GLOBALS['config'] = $UG;
is_(strpos(fc_fmt(100.0, 'USD'), 'UGX') === 0, 'Uganda: fc_fmt(100,"USD") begins with UGX, not $', fc_fmt(100.0,'USD'));
is_(strpos(fc_fmt(100.0, 'USD'), '$') === false, 'Uganda: fc_fmt never emits a $');
$GLOBALS['config'] = $SS;
is_(fc_fmt(100.0, 'USD') === '$100.00', 'South Sudan: fc_fmt(100,"USD") === "$100.00" (unchanged)');
is_(fc_fmt(100.0, 'SSP') === '100.00', 'South Sudan: fc_fmt(100,"SSP") === "100.00" (unchanged)');

// ── D. the support-role flag collapses on Uganda (the my_account / wallet mechanism) ──
echo "\nD. the SSP-first support presentation collapses on Uganda\n";
$roles = ['support_leader','support','sales','field_agent','collection'];
foreach (['support_leader','support'] as $r) {
    // exactly the expression my_account:546/672 and wallet:200 evaluate
    is_((dn_ssp_selectable($UG) && in_array($r, $roles, true)) === false, "Uganda: a '$r' agent is not treated as SSP-first");
    is_((dn_ssp_selectable($SS) && in_array($r, $roles, true)) === true,  "South Sudan: a '$r' agent is still SSP-first (unchanged)");
}

// ── E. structural gate checks ──
echo "\nE. every SSP UI element is gated on the tenant\n";
$ma = nc($Sl.'my_account.php');
is_(substr_count($ma, 'dn_ssp_selectable($config ?? null) && in_array($retailer') === 2, 'my_account: both $_mcIsSupport definitions require dn_ssp_selectable');
is_(strpos($ma, "\$v === 'ssp_book' && !dn_ssp_selectable") !== false, 'my_account: the ssp_book view redirects on Uganda');
is_(strpos($ma, "\$v === 'usd_book' && !dn_ssp_selectable") !== false, 'my_account: the usd_book view redirects on Uganda');
is_(strpos($ma, 'if ($maSSP): ?>') !== false, 'my_account: the field-accountant SSP tile is gated');
is_(substr_count($ma, 'if (dn_ssp_selectable($config ?? null)): ?>') >= 2, 'my_account: the expense and advance SSP radios are gated');
$wa = nc($Sl.'wallet.php');
is_(strpos($wa, 'dn_ssp_selectable($config ?? null) && in_array($userRole') !== false, 'wallet: $fr_is_support_role requires dn_ssp_selectable');
is_(strpos($wa, "dn_ssp_selectable(\$config ?? null) ? ['USD','SSP'] : ['USD']") !== false, 'wallet: the currency filter rejects SSP on Uganda');
is_(substr_count($wa, 'if (dn_ssp_selectable($config ?? null)): ?>') >= 3, 'wallet: the SSP filter button, summary and currency pill are gated');
is_(strpos($wa, "'' : '<?= rtrim(dn_cur(\$config)) ?>'") !== false, 'wallet: the entry-modal amount prefix follows the tenant');
$feNC = nc($Sp.'field_expenses.php');
is_(strpos($feNC, '$feSSP = dn_ssp_selectable($config ?? null)') !== false, 'field_expenses: derives $feSSP from the tenant');
is_(strpos($feNC, 'if ($feSSP): ?>') !== false, 'field_expenses: the SSP balance tile is gated');
is_(strpos($feNC, '💵 USD Balance') === false, 'field_expenses: the hardcoded "USD Balance" label is gone');
$fcNC = nc($T.'fiber_costs.php');
is_(strpos($fcNC, "'' : rtrim(dn_cur(\$config))) . number_format") !== false,
    'fiber_costs: fc_fmt derives its symbol from dn_cur (rtrim keeps Sudan byte-identical)');

// ── F. control on the control: strip the gate and the leak returns on Uganda ──
echo "\nF. control on the control\n";
$broken = str_replace('<?php if ($feSSP): ?>', '<?php if (true): ?>', $heroFrag);
$brokenUg = render($broken, $UG, ['summary'=>$summary]);
is_(strpos($brokenUg, '🇸🇸 SSP Balance') !== false, 'control: with the $feSSP gate removed, the SSP tile renders on Uganda again — the gate is load-bearing');

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
