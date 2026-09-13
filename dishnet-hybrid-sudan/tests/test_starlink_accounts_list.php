<?php
declare(strict_types=1);
/**
 * test_starlink_accounts_list.php — reporting the Starlink accounts.
 *
 * Finance's Accounts screen cannot fill itself: it builds kits from uCRM, and
 * uCRM carries no Starlink account number. The numbers live in our bindings
 * and in the data plugin's service cache. This tool reports them so a person
 * can enter them in Finance; it must never enter them itself, and it must
 * never let "no source" read as "no accounts".
 */
$pass = 0; $fail = 0;
function is_(bool $c, string $m): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m\n"; } }
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }

$root = dirname(__DIR__);
$src  = (string)file_get_contents($root . '/tools/starlink_accounts_list.php');
function codeOnly3(string $s): string {
    $o = '';
    foreach (token_get_all($s) as $k) {
        if (is_array($k)) { if (in_array($k[0], [T_COMMENT, T_DOC_COMMENT], true)) continue; $o .= $k[1]; }
        else $o .= $k;
    }
    return $o;
}
$code = codeOnly3($src);

echo "\nIt is read-only\n";
// Both sibling plugins state the same contract: a data file is owned by one
// plugin, others may read it and must not write it. Finance owns its accounts.
// (?<![$>\w]) so a variable named $touch or a method ->copy() cannot be
// mistaken for the filesystem function of that name.
$writes = [];
preg_match_all('/(?<![\$>\w])(?:file_put_contents|fopen|unlink|rename|mkdir|touch|copy)\s*\(\s*([^,)]+)/',
               $code, $wm);
foreach ($wm[1] as $target) $writes[] = trim($target);
t('no filesystem-writing call at all', $writes, []);
is_(preg_match('/->(patch|post|put|delete)\(/', $code) === 0, 'and no remote write either');
is_(preg_match('/->(assign|release|save|store)\(/', $code) === 0, 'it changes no binding');

echo "\nIt distinguishes no source from no accounts\n";
// An unreadable sibling cache rendered as zero accounts would read as "the
// fleet has none", which is the opposite of what it means.
is_(strpos($code, '$rows === null') !== false, 'a null cache is handled separately from an empty one');
is_(strpos($src, 'NOT READABLE') !== false, 'and reported as unreadable, not as zero');
is_(strpos($src, 'not empty because the fleet is empty') !== false
    || strpos($src, 'empty because the fleet is empty') !== false,
    'the all-empty case says why rather than showing a blank table');
is_(strpos($code, '$finance === null') !== false,
    'Finance holding no file is distinguished from Finance holding none');

echo "\nEvery account is attributed to a source\n";
is_(strpos($code, "\$a['starlink_account']") !== false, 'our bindings are read');
is_(strpos($code, "\$rec['account_number']") !== false, "the data plugin's lines are read");
is_(strpos($code, 'sl_accounts.json') !== false, 'and what Finance already holds');
is_(preg_match('/readJson\(\s*\'dishnet-starlink-finance\'/', $code) === 1,
    'through SiblingPlugin, which reports a miss honestly');

echo "\nAn empty account number is never an account\n";
// A blank account would group every line without one under a single phantom
// account and look like a real fifth account.
is_(preg_match('/if\s*\(\$a === \'\'/', $code) === 1, 'the collector refuses a blank');
is_(substr_count($code, "if (\$n === '') continue;") >= 2,
    'and both sources skip a line that carries none');

echo "\nIt tells the operator where accounts actually go\n";
is_(strpos($src, 'Add Account') !== false, 'it names Finance\'s own screen');
is_(strpos($src, 'Nothing here writes them') !== false, 'and says it will not do it for them');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
