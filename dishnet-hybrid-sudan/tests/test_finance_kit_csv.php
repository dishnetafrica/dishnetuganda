<?php
declare(strict_types=1);
/**
 * test_finance_kit_csv.php — handing Finance our bindings in its own format.
 *
 * dishnet-data-report lists a customer's kits by reading Finance's
 * sl_kits.json and matching crm_client_id. On a box where Finance holds no
 * kits, that page says "No subscriptions found" however correct our binding
 * is. The fix is a CSV Finance imports itself — not a file we write into its
 * directory, which both sibling plugins' own contracts forbid.
 *
 * These pin the two things that make the projection safe: the columns are the
 * ones Finance actually maps, and the tool never reaches into another
 * plugin's files.
 */
$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function is_(bool $c, string $m): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m\n"; } }

$root = dirname(__DIR__);
$tool = (string)file_get_contents($root . '/tools/finance_kit_csv.php');

/**
 * The file with its comments removed.
 *
 * Asserting "this string must not appear" against raw source keeps failing on
 * the comment that EXPLAINS why the thing must not be done — five times in
 * this branch now. A rule about behaviour belongs against code, so the
 * tokenizer strips everything that is not.
 */
function codeOnly(string $src): string {
    $out = '';
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok)) {
            if (in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
            $out .= $tok[1];
        } else {
            $out .= $tok;
        }
    }
    return $out;
}
$code = codeOnly($tool);

echo "\nIt generates; Finance writes its own file\n";
// The contract both sibling plugins state: a data file is owned by one plugin,
// others may read it and must not write it.
is_(strpos($code, 'sl_kits.json') === false,
    'no code names the file it would be tempting to write');
is_(preg_match('#dishnet-(starlink-finance|data-report)/#', $code) === 0,
    'and no code builds a path into another plugin');
// Reading a sibling file is allowed by that contract and is how the Starlink
// account is recovered when the assignment holds none. What must not happen is
// a write, so the check is on writes, not on whether a sibling is touched.
$writes = [];
preg_match_all('/\b(?:file_put_contents|fopen|unlink|rename|mkdir|touch|copy)\s*\(\s*([^,)]+)/',
               $code, $wm);
foreach ($wm[1] as $target) $writes[] = trim($target);
// Every call that can touch the filesystem must aim at the path the operator
// named, or at an in-memory stream. Anything else is a write we did not intend.
$stray = array_values(array_filter($writes, static fn(string $t): bool =>
    $t !== '$out' && strpos($t, 'php://') === false));
is_($stray === [], 'every write targets $out or an in-memory stream')
    or print('       stray: ' . implode(' | ', $stray) . "\n");
is_(substr_count($code, 'file_put_contents') === 1,
    'it writes exactly one file — the CSV the operator asked for');
is_(preg_match('/file_put_contents\(\$out,/', $code) === 1,
    'and only to the path they named');

echo "\nThe columns are the ones Finance actually maps\n";
// Taken from its import_csv colMap. A column it does not recognise is simply
// dropped, so a wrong name here is a silently missing field.
foreach (['kit_number', 'serial_number', 'status', 'location', 'customer', 'plan',
          'starlink_account_number', 'starlink_account_status',
          'assigned_client_id', 'assigned_name', 'crm_client_id'] as $c) {
    is_(strpos($tool, "'" . $c . "'") !== false, "maps $c");
}

echo "\nLocation is a place, or it is blank\n";
// The assignment's note is an audit trail ("assigned via assign_kit"). Shipping
// it as Finance's location would put a false answer in a column a person reads
// as the install site. Pull the mapped expression out and check what feeds it.
preg_match("/'location'\s*=>(.*?),\n\s*'customer'/s", $code, $lm);
is_($lm !== [], 'the location column is mapped');
$locExpr = $lm[1] ?? '';
is_(strpos($locExpr, 'note') === false, 'and never fed by the assignment note');
is_(strpos($locExpr, 'Addr') !== false, 'it is fed by an address');
is_(strpos($code, "'street1'") !== false && strpos($code, "'city'") !== false,
    'assembled from uCRM address fields');

echo "\nThe client id is written to BOTH fields data-report reads\n";
// Its filter is crm_client_id ?? assigned_client_id ?? crm_id. A reader that
// finds neither shows the customer nothing at all.
is_(preg_match("/'assigned_client_id'\s*=>\s*\(string\)\\\$clientId/", $tool) === 1,
    'assigned_client_id');
is_(preg_match("/'crm_client_id'\s*=>\s*\(string\)\\\$clientId/", $tool) === 1,
    'crm_client_id');
is_(strpos($tool, 'data-report reads crm_client_id ?? assigned_client_id') !== false,
    'and the file says why both are set');

echo "\nequipment_assignments stays the source\n";
is_(strpos($tool, '$ea->liveAssignments()') !== false, 'it reads the authoritative binding');
is_(strpos($tool, 'Not a second source of truth') !== false,
    'and states that the CSV is a projection, not an authority');
is_(strpos($code, '--commit') === false,
    'it has no commit mode, because it changes nothing here');

echo "\nA binding with no serial is not a kit row\n";
is_(preg_match("/if \(\\\$kit === ''\) continue;/", $tool) === 1,
    'assignments without a serial are skipped rather than exported blank');

echo "\nAn unreachable uCRM does not silently produce blank names\n";
// Empty must never stand in for unknown — the same rule the review screen holds.
is_(strpos($tool, 'uCRM was unreachable') !== false,
    'it says so on stderr');
is_(strpos($tool, 'Finance matches on kit_number and crm_client_id') !== false,
    'and explains that the fields that matter are still correct');

echo "\nRe-running is the update mechanism, not a duplicate\n";
is_(strpos($tool, 're-importing updates rather than duplicates') !== false,
    'the operator is told Finance keys on kit_number');

echo "\nIt refuses to produce an empty file\n";
is_(preg_match("/No live assignments.*nothing to hand Finance/s", $tool) === 1,
    'with nothing bound it says so and exits, rather than writing a header-only CSV');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
