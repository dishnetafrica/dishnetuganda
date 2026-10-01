<?php
declare(strict_types=1);
/**
 * test_staff_cashbook_scope.php — the Staff Cashbooks money-formatter scM() must
 * have $config in scope (5.18.56; regression for the "Undefined variable $config"
 * warning on tabs/accounts/staff_cashbooks.php:846).
 *
 * scM() is a top-level helper defined inside the tab:
 *   function scM(float $n):string{...dn_cur($config)...}
 * PHP functions do NOT inherit the including scope, so the bare $config raised
 * an E_WARNING that rendered inline on every figure card of the accountant/admin
 * Staff Cashbooks screen. The amounts were still correct (dn_cur() defaults to
 * 'UGX' when config is absent) — only the warning text leaked. The fix brings
 * $config into scope with `global $config`, matching the codebase's existing
 * pattern (tabs/customer_app/portal_data.php uses `global $config`).
 *
 * This test (1) asserts scM's definition brings $config into scope, (2) extracts
 * that exact definition, eval's it, and calls it with NO $config in scope to prove
 * it emits no "Undefined variable" warning and still renders the currency, and
 * (3) runs a control on the control: the pre-fix definition (global removed) DOES
 * warn under the same harness, so the check has real subject matter.
 *
 * No network, no secret, no staff name or phone appears here.
 */
$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d !== '' ? "\n       $d" : '') . "\n"; } }

$root = dirname(__DIR__);
$file = $root . '/tabs/accounts/staff_cashbooks.php';
$src  = (string)file_get_contents($file);

// There must be exactly one scM definition, and it must declare $config global
// (before it is used) so the including scope's absence cannot raise a warning.
is_(substr_count($src, 'function scM(') === 1, 'scM() is defined exactly once in the tab');
is_(preg_match('/function scM\(float \$n\):string\{\s*global \$config;/', $src) === 1,
    'scM() brings $config into scope via `global $config` before using it');

// Isolate the exact one-line definition (the body has no nested braces).
$ok = (bool)preg_match('/function scM\(float \$n\):string\{[^}]*\}/', $src, $mm);
is_($ok, 'the scM() definition can be isolated for a runtime check');

// A faithful dn_cur(): tolerates null/array, defaults to UGX — mirrors lib/currency.php.
if (!function_exists('dn_cur')) {
    require $root . '/lib/currency.php';
}
is_(function_exists('dn_cur'), 'dn_cur() is available (lib/currency.php)');

// (1) The FIXED scM(): declare it and call it in a scope with NO local $config,
// and with no global $config set either. `global $config` on an unset global
// yields null (no warning); dn_cur(null) -> "UGX ".
if ($ok) {
    eval($mm[0]);
    $warned = false;
    set_error_handler(function ($no, $str) use (&$warned) {
        if (stripos($str, 'undefined variable') !== false) { $warned = true; }
        return true;
    });
    $out = scM(300000.0);
    restore_error_handler();
    is_($warned === false, 'scM() raises no "Undefined variable" warning with no $config in scope');
    is_(is_string($out) && strpos($out, 'UGX') !== false, 'scM() still renders the UGX currency symbol', $out ?? '');
    is_(is_string($out) && strpos($out, '300,000.00') !== false, 'scM() still formats the amount (300,000.00)', $out ?? '');
    is_(is_string($out) && strpos(scM(-5000.0), '-') === 0, 'scM() keeps the leading minus for negatives');
}

// (2) Control on the control: the PRE-FIX definition (global removed, renamed so
// it can co-exist) MUST warn — otherwise the test above proves nothing.
if ($ok) {
    $buggy = str_replace('function scM(float', 'function scM_ctl(float',
             str_replace('global $config;', '', $mm[0]));
    eval($buggy);
    $warnedCtl = false;
    set_error_handler(function ($no, $str) use (&$warnedCtl) {
        if (stripos($str, 'undefined variable') !== false) { $warnedCtl = true; }
        return true;
    });
    scM_ctl(300000.0);
    restore_error_handler();
    is_($warnedCtl === true, 'control: the pre-fix scM (no `global $config`) DOES warn — the check has subject matter');
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
