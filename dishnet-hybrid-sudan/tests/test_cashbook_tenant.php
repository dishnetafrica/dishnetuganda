<?php
declare(strict_types=1);
/**
 * test_cashbook_tenant.php — 5.18.57: the accountant/admin cash UI shows the
 * TENANT's own currency. On Uganda (dn_ssp_selectable=false) SSP never renders
 * and the base cashbook tab reads UGX; on South Sudan every byte is unchanged
 * (USD cashbook + the 🇸🇸 SSP cashbook + the USD↔SSP exchange).
 *
 * SSP is South Sudan's local secondary cash. It leaked onto the Uganda "Staff
 * Cashbooks" screen as a "🇸🇸 SSP Cashbook" tab, an SSP bag column, an exchange
 * modal and dropdown options — none of which exist on a Uganda (UGX) install.
 *
 * This test (A) checks the tenant helpers, (B) RENDERS the real currency-tab bar
 * and manual-entry dropdown from staff_cashbooks.php under each tenant and reads
 * the output as an account manager would see it, (C) asserts the other six
 * accounting screens gate their SSP UI on dn_ssp_selectable, and (D) a control:
 * with the $scSSP gate stripped, the SSP tab renders on Uganda again.
 *
 * No network, no secret, no staff name/phone appears here.
 */
$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d !== '' ? "\n       $d" : '') . "\n"; } }

$root = dirname(__DIR__);
require_once $root . '/lib/currency.php';

$UG = ['currency_symbol'=>'UGX','currency_code'=>'UGX','cashbook_base_currency'=>'UGX','cashbook_currencies'=>'UGX,USD'];
$SS = ['cashbook_base_currency'=>'USD','cashbook_currencies'=>'USD,SSP']; // South Sudan default shape

echo "A. tenant helpers\n";
is_(dn_ssp_selectable($UG) === false, 'Uganda: SSP is not bookable');
is_(dn_book_base($UG) === 'UGX', 'Uganda: base currency is UGX');
is_(dn_ssp_selectable($SS) === true, 'South Sudan: SSP is bookable (control)');
is_(dn_book_base($SS) === 'USD', 'South Sudan: base currency is USD (control)');

// ── Render a fragment of staff_cashbooks.php exactly as it ships. ──
$sc = (string)file_get_contents($root . '/tabs/accounts/staff_cashbooks.php');
function fragment(string $src, string $from, string $to): string {
    $a = strpos($src, $from); $b = ($a !== false) ? strpos($src, $to, $a) : false;
    return ($a !== false && $b !== false) ? substr($src, $a, $b - $a) : '';
}
// Render a PHP/HTML fragment with a given config + vars, returning its output.
function render(string $fragment, array $config, array $vars): string {
    $GLOBALS['config'] = $config;          // scM()/dn_cur() read the global
    extract($vars, EXTR_SKIP);
    $scSSP = dn_ssp_selectable($config); $scBaseCode = dn_book_base($config);
    ob_start(); eval('?>' . $fragment); return (string)ob_get_clean();
}

$tabs = fragment($sc, '<!-- Currency Tabs -->', '<!-- Date Filter -->');
is_($tabs !== '', 'the currency-tab bar fragment was located');
$tabVars = ['selId'=>1,'curTab'=>'usd','fFrom'=>'','fTo'=>'','uPend'=>0,'sPend'=>0];

echo "\nB. the currency tabs, rendered as an account manager sees them\n";
$ugTabs = render($tabs, $UG, $tabVars);
is_(strpos($ugTabs, 'UGX Cashbook') !== false, 'Uganda: the base tab reads "UGX Cashbook"', $ugTabs);
is_(strpos($ugTabs, 'SSP Cashbook') === false, 'Uganda: there is NO "SSP Cashbook" tab');
is_(strpos($ugTabs, '🇸🇸') === false, 'Uganda: the South Sudan flag never appears');
is_(strpos($ugTabs, 'USD Cashbook') === false, 'Uganda: the base tab is not mislabeled "USD Cashbook"');

$ssTabs = render($tabs, $SS, $tabVars);
is_(strpos($ssTabs, 'USD Cashbook') !== false, 'South Sudan: the base tab reads "USD Cashbook" (unchanged)');
is_(strpos($ssTabs, '🇸🇸 SSP Cashbook') !== false, 'South Sudan: the "🇸🇸 SSP Cashbook" tab is still present (unchanged)');

echo "\nB2. the manual-entry currency dropdown, rendered\n";
$dd = fragment($sc, '<select name="man_currency"', '</select>') . '</select>';
$ugDd = render($dd, $UG, []);
is_(strpos($ugDd, '>SSP<') === false && strpos($ugDd, 'value="SSP"') === false, 'Uganda: the manual-entry dropdown offers no SSP');
is_(strpos($ugDd, 'value="UGX"') !== false, 'Uganda: the manual-entry dropdown offers UGX');
$ssDd = render($dd, $SS, []);
is_(strpos($ssDd, 'value="USD"') !== false && strpos($ssDd, 'value="SSP"') !== false, 'South Sudan: the dropdown still offers USD and SSP (unchanged)');

echo "\nC. the other accounting screens gate their SSP UI on the tenant\n";
function nc(string $f): string { $o=''; foreach (token_get_all((string)file_get_contents($f)) as $k){ if(is_array($k)){ if(in_array($k[0],[T_COMMENT,T_DOC_COMMENT],true)) continue; $o.=$k[1]; } else $o.=$k; } return $o; }
$T = $root . '/tabs/accounts/'; $S = $root . '/tabs/support/';
// staff_cashbooks derives the flags and gates its SSP parts + exchange modal
$scNC = nc($T.'staff_cashbooks.php');
is_(strpos($scNC, '$scSSP') !== false && strpos($scNC, 'dn_ssp_selectable($config)') !== false, 'staff_cashbooks: derives $scSSP from dn_ssp_selectable');
is_(strpos($scNC, 'if($scSSP): ?>') !== false, 'staff_cashbooks: gates SSP blocks on $scSSP');
is_(strpos($scNC, '">💵 USD Cashbook') === false, 'staff_cashbooks: the old hardcoded "USD Cashbook" label is gone');
// whole-screen SSP tabs early-return on Uganda
foreach (['ssp_imprest.php','ssp_cashbook.php'] as $f) {
    $x = nc($T.$f);
    is_(strpos($x,'dn_ssp_selectable($config ?? null)')!==false && strpos($x,'SSP flows are not enabled')!==false,
        "$f: returns early when SSP is not bookable");
}
// cash_declaration gates its SSP cash-count blocks
$cd = nc($T.'cash_declaration.php');
is_(substr_count($cd,'if (dn_ssp_selectable($config ?? null)):') >= 3, 'cash_declaration: gates its three SSP blocks');
is_(strpos($cd,'ACTUAL <?= dn_book_base($config) ?> CASH COUNT')!==false, 'cash_declaration: the actual-cash label follows the base currency');
// the two dropdown options
is_(strpos(nc($T.'cash_advances.php'),'if (dn_ssp_selectable($config ?? null)): ?><option value="SSP">')!==false, 'cash_advances: SSP option gated');
is_(strpos(nc($T.'fiber_costs.php'),'if (dn_ssp_selectable($config ?? null)): ?><option value="SSP">')!==false, 'fiber_costs: SSP option gated');
// nav labels in public.php
$pub = nc($root.'/public.php');
is_(strpos($pub,"dn_ssp_selectable(\$config) ? 'Cashbook (USD & SSP)'")!==false, 'public.php: the Cashbook nav label is tenant-aware');
is_(strpos($pub,"'roles'=> (dn_ssp_selectable(\$config) ? ['accountant','admin'] : [])")!==false, 'public.php: the SSP Imprest nav item is hidden on Uganda');

echo "\nD. control on the control: strip the gate and the leak returns\n";
$broken = str_replace('<?php if($scSSP): ?>', '<?php if(true): ?>', $tabs);
$brokenUg = render($broken, $UG, $tabVars);
is_(strpos($brokenUg, '🇸🇸 SSP Cashbook') !== false, 'control: with the $scSSP gate removed, the SSP tab renders on Uganda again — the gate is load-bearing');

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
