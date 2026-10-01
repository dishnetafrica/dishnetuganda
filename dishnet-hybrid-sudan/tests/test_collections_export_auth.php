<?php
declare(strict_types=1);
/**
 * test_collections_export_auth.php — the collections CSV export must authenticate
 * and authorise BEFORE it reads or emits any data (PD-1; docs/47 §7.2, docs/48 §5).
 *
 * The export block in includes/routes.php runs at public.php:739, before the page
 * login gate at public.php:1027. Until 5.18.55 it was keyed only on
 * tab=all_collections&col_export=csv with no sign-in or role check — an anonymous
 * full dump of every customer payment collection. The All Collections tab is
 * admin-only (public.php roles=>['admin']).
 *
 * This test reads includes/routes.php with comments stripped, isolates the
 * collections-export block, and asserts the block gates itself (requireLogin +
 * a role check) before the data read and the CSV write; plus a control on the
 * control — the same checks FAIL on a copy of the block with the gate removed.
 *
 * No network, no secret, no customer appears here.
 */
$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d !== '' ? "\n       $d" : '') . "\n"; } }
function codeNC(string $f): string {
    $o = '';
    foreach (token_get_all((string)file_get_contents($f)) as $k) {
        if (is_array($k)) { if (in_array($k[0], [T_COMMENT, T_DOC_COMMENT], true)) continue; $o .= $k[1]; }
        else $o .= $k;
    }
    return $o;
}

$root = dirname(__DIR__);
$src  = codeNC($root . '/includes/routes.php');

// Isolate the collections-export block: from its unique condition token
// (col_export) to the next export block (sc_export).
$start = strpos($src, 'col_export');
$end   = ($start !== false) ? strpos($src, 'sc_export', $start) : false;
is_($start !== false, 'the collections-export block is present (col_export)');
is_($end !== false && $end > $start, 'the next export block bounds it (sc_export)');
$block = ($start !== false && $end !== false) ? substr($src, $start, $end - $start) : '';

// One checker, run on the real block and on a gate-removed copy.
$check = function (string $b): array {
    $pReq  = strpos($b, 'requireLogin');
    $pData = strpos($b, 'payment_collections.json');
    $pCsv  = strpos($b, 'fputcsv');
    $role  = (strpos($b, 'is_admin') !== false) || (strpos($b, "'admin'") !== false);
    return [
        'login'       => $pReq !== false,
        'before_data' => $pReq !== false && $pData !== false && $pReq < $pData,
        'before_csv'  => $pReq !== false && $pCsv !== false && $pReq < $pCsv,
        'role'        => $role,
    ];
};

echo "\n1. The real export block gates itself before any data\n";
$r = $check($block);
is_($r['login'],       'the block calls $auth->requireLogin()');
is_($r['before_data'], 'requireLogin() precedes the payment_collections.json read');
is_($r['before_csv'],  'requireLogin() precedes fputcsv');
is_($r['role'],        'an admin/role check is present in the block');

echo "\n2. Control on the control: with the gate removed, the guard fails\n";
$weak = preg_replace('/^.*requireLogin.*$/m', '', $block);   // simulate pre-5.18.55
$w = $check($weak);
is_(!$w['login'],       'gate-removed copy has no requireLogin (the control is real)');
is_(!$w['before_data'], 'gate-removed copy would NOT gate before the data read');

echo "\n";
printf("collections-export-auth: %d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
