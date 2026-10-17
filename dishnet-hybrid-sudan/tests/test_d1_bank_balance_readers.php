<?php
declare(strict_types=1);
/**
 * test_d1_bank_balance_readers.php — P3b (5.18.96; D1 and X5; SPEC §d.1 T4): no page, cron, worker, tool or API reads
 * an account balance — the bank's among them — except the readers the design keeps on purpose. Static: nothing is run,
 * rendered or opened but the plugin's own PHP files, read as tokens. It loads no configuration and writes nothing but
 * its own temporary folder.
 *
 * What it matches: CALLS ONLY — `->currencyPositions(`, `->balancesByAccount(`, `->accountBalance(` and `->accountLedger(`
 * (one account's rows, each with its running balance, a bank account's included; SPEC §b.3's fourth per-account
 * reader) — in every PHP file of the plugin outside a tests/ directory and data/, with comments stripped first
 * (token_get_all). A comment that names a method is not a call: accounts_dashboard.php says "currencyPositions() is
 * not called on this page any more".
 * Neither is a string, inline HTML, a property read, "$cb->m()" inside a string (a property, then text) or
 * `function m(`. A call is still a call with spaces or a comment between its parts, or another letter case (PHP's method
 * names have none). The defining class, lib/CashbookService.php, is excluded: its own calls are the readers'
 * implementation. Not matched, and not claimed: a dynamic call ($cb->$m(), $cb->{'m'}(), [$cb, 'm']).
 *
 * The allowed list is SPEC §d.1's, file by file, each with its method and its EXACT number of calls: a new call in a
 * listed file fails, and so does an entry left behind once its call has gone (X4 removing the API's three, say, changes
 * the list deliberately, with its reason). The CEO page may hold exactly one currencyPositions() call, and it must sit
 * in the `else` of `if ($_ceoRuleA)` and in no branch of such an `if` that runs under rule A. And $_ceoRuleA may only be
 * `false`, first and at the top level, then `$_ceoCb->cashOnlyRuleInForce()`: an `else` of a rule that is not the
 * service's own gate would prove nothing (SPEC §b.2.4; on the server T4 is the CEO page's only check, R24).
 *
 * In order:
 *   A  the matcher and the CEO-branch reader, on fixed snippets and on the tree's own files: what is a call, what is
 *      not, what is "the else";
 *   B  f4b4c96, the live code, from Git — the POSITIVE CONTROL. Its calls must equal the allowed list exactly, so the
 *      list is the live code's own inventory and not one fitted to this branch. Its CEO page, where the one call is
 *      unconditional and $_ceoRuleA does not exist, must FAIL the branch check and the gate check, and nothing else
 *      may fail there: that is the control that both checks can fire. No Git, or no such commit: a skip, never a pass;
 *   C  the tree under test: its calls equal the allowed list, and its CEO page passes both checks;
 *   D  planted calls, in memory (nothing is written). A call of each method added to each tab page is refused, judged
 *      against the two tab pages SPEC §d.1 names (T4_SPEC_TABS) rather than against the list it guards, so an entry
 *      added to the list for a tab (M26) is caught here. Every other unlisted file refuses one too, and every listed
 *      file refuses one call more than it is allowed;
 *   E  weakened copies (sj_weakened_copy, each anchor asserted unique): the unchanged copy passes; a call planted in a
 *      tab, one per method; a second CEO call, in the rule-A branch, in the rule-A tiles and in the else (M18); the one
 *      call moved into the rule-A branch (M18); an allowed-list entry added for a tab, alone and with a call planted
 *      there (M26); and this test's own branch and count checks made to accept too much. Each must fail this test. And
 *      this test, run on f4b4c96's own tree, must fail there on the CEO page and nowhere else.
 *
 *   php test_d1_bank_balance_readers.php [--root=DIR] [--baseline=DIR] [--no-mutants]
 *     --root      the plugin tree to judge (default: this checkout's)
 *     --baseline  f4b4c96's plugin tree, already extracted (E hands its own to every weakened copy)
 *     --no-mutants  skip E, which copies the plugin into the temporary folder (a server run, R24)
 */
$opt  = getopt('', ['root:', 'baseline:', 'no-mutants']);
$root = isset($opt['root']) ? rtrim((string)$opt['root'], '/') : dirname(__DIR__);
$baseArg     = isset($opt['baseline']) ? rtrim((string)$opt['baseline'], '/') : '';
$withMutants = !isset($opt['no-mutants']);

// This run's own temporary folder, and TMPDIR pointed at it before anything asks PHP for one (sys_get_temp_dir() keeps
// its first answer): the fixtures' baseline tree and weakened copies, and every child run, land inside it.
$own = rtrim((string)(getenv('TMPDIR') ?: '/tmp'), '/') . '/p3b-t4-' . getmypid() . '-' . bin2hex(random_bytes(3));
mkdir($own, 0700, true);
putenv('TMPDIR=' . $own);
register_shutdown_function(function () use ($own) { exec('rm -rf ' . escapeshellarg($own)); });
require_once __DIR__ . '/fixtures/staff_jobs_scenario.php';                 // sj_baseline_tree(), sj_weakened_copy()

/** The live code: 5.18.94, the release P3b replaces. */
const T4_LIVE = 'f4b4c96';
const T4_METHODS  = ['currencyPositions', 'balancesByAccount', 'accountBalance', 'accountLedger'];
/** The defining class: its own calls (currencyPositions → balancesByAccount, accountLedger → accountBalance) are the
 *  readers' implementation. */
const T4_EXCLUDED = ['lib/CashbookService.php'];
const T4_CEO  = 'tabs/admin/ceo_dashboard.php';
const T4_RULE = '$_ceoRuleA';
const T4_GATE = '$_ceoCb->cashOnlyRuleInForce()';
/** The two tab pages SPEC §d.1 lets read an account balance, and with what. Written apart from T4_ALLOWED so that D can
 *  judge a planted tab call against the SPEC instead of against the list it is guarding (M26). */
const T4_SPEC_TABS = ['tabs/accounts/opening_balances.php' => 'accountBalance', 'tabs/admin/ceo_dashboard.php' => 'currencyPositions'];
/** SPEC §d.1 T4's allowed list: file => [method => exact number of calls]. lib/BankImport.php:296-302 is
 *  existingCapital()'s own SQL and calls none of the four, so it adds nothing to the entry. */
const T4_ALLOWED = [
    'tabs/accounts/opening_balances.php'    => ['accountBalance' => 1],     // Opening Balances (:133): account administration, off the Uganda menu; P3c, Q14
    'tabs/admin/ceo_dashboard.php'          => ['currencyPositions' => 1],  // exactly one, in the else of if ($_ceoRuleA): rule off only (Q28)
    'lib/BankImport.php'                    => ['accountBalance' => 1],     // the statement import's book balance (:256)
    'includes/api/api_cashbook.php'         => ['currencyPositions' => 3],  // the API's positions (:332, :353, :368), until X4
    'tools/uganda_book_status.php'          => ['currencyPositions' => 1],  // the tools/uganda_* command-line tools
    'tools/uganda_capital_cash_rebook.php'  => ['currencyPositions' => 1],
    'tools/uganda_fund_leg_repair.php'      => ['currencyPositions' => 1],
    'tools/uganda_opening_correction.php'   => ['currencyPositions' => 1],
    'tools/uganda_registration_expense.php' => ['currencyPositions' => 1],
    'tools/uganda_site_expense_months.php'  => ['currencyPositions' => 1],
];

$pass = 0; $fail = 0; $skip = 0;
function is_(bool $c, string $m, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; }
    else    { $fail++; echo "  FAIL {$m}" . ($d !== '' ? "\n       " . substr($d, 0, 900) : '') . "\n"; }
}
function skip_(string $m): void { global $skip; $skip++; echo "  skip {$m}\n"; }

/** A file's tokens without comments and whitespace: [token id, or null for a one-character token; text; line]. */
function t4_tokens(string $src): array
{
    $out = []; $line = 1;
    foreach (token_get_all($src) as $t) {
        $id = is_array($t) ? $t[0] : null;
        $tx = is_array($t) ? $t[1] : $t;
        if (is_array($t)) $line = $t[2];
        if ($id !== T_COMMENT && $id !== T_DOC_COMMENT && $id !== T_WHITESPACE) $out[] = [$id, $tx, $line];
        $line += substr_count($tx, "\n");
    }
    return $out;
}
/** Is token $i the one-character token $ch? */
function t4_ch(array $tok, int $i, string $ch): bool { return isset($tok[$i]) && $tok[$i][0] === null && $tok[$i][1] === $ch; }
/** Is token $i of id $id? */
function t4_id(array $tok, int $i, int $id): bool { return isset($tok[$i]) && $tok[$i][0] === $id; }

/** The readers' calls (T4_METHODS) in a token list: [method as declared, line, index of the name]. */
function t4_calls(array $tok): array
{
    static $ops = null, $want = [];
    if ($ops === null) {
        $ops = [T_OBJECT_OPERATOR];
        // PHP 8 lexes ?-> as one token; PHP 7.4 lexes it as ? and ->, which the first entry already matches.
        if (defined('T_NULLSAFE_OBJECT_OPERATOR')) $ops[] = constant('T_NULLSAFE_OBJECT_OPERATOR');
        foreach (T4_METHODS as $m) $want[strtolower($m)] = $m;
    }
    $out = [];
    for ($i = 0, $n = count($tok); $i + 2 < $n; $i++) {
        if (!in_array($tok[$i][0], $ops, true) || !t4_id($tok, $i + 1, T_STRING)) continue;
        $k = strtolower($tok[$i + 1][1]);
        if (isset($want[$k]) && t4_ch($tok, $i + 2, '(')) $out[] = [$want[$k], $tok[$i + 1][2], $i + 1];
    }
    return $out;
}

/** Every PHP file of the plugin outside tests/ directories and data/, but the defining class, and its calls:
 *  ['files' => [rel], 'calls' => [rel => [method => [lines]]], 'unreadable' => [rel]]. */
function t4_scan(string $root): array
{
    $dir = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
    $flt = new RecursiveCallbackFilterIterator($dir, function ($cur, $key, $it) use ($root) {
        if (!$it->hasChildren()) return true;
        $rel = substr($cur->getPathname(), strlen($root) + 1);
        return $cur->getFilename() !== 'tests' && !in_array($rel, ['data', '.git'], true);
    });
    $rels = [];
    foreach (new RecursiveIteratorIterator($flt) as $f) {
        if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') continue;
        $rel = substr($f->getPathname(), strlen($root) + 1);
        if (!in_array($rel, T4_EXCLUDED, true)) $rels[] = $rel;
    }
    sort($rels);
    $calls = []; $bad = [];
    foreach ($rels as $rel) {
        $src = @file_get_contents($root . '/' . $rel);
        if ($src === false) { $bad[] = $rel; continue; }
        foreach (t4_calls(t4_tokens($src)) as [$m, $ln]) $calls[$rel][$m][] = $ln;
    }
    return ['files' => $rels, 'calls' => $calls, 'unreadable' => $bad];
}

/** An inventory against a list: [[kind, file, method, message]], kind 'not-allowed', 'count' or 'stale'. */
function t4_judge(array $calls, array $allowed): array
{
    $out = [];
    foreach ($calls as $rel => $byM) foreach ($byM as $m => $lines) {
        $want = (int)($allowed[$rel][$m] ?? 0);
        $at   = 'line' . (count($lines) > 1 ? 's ' : ' ') . implode(', ', $lines);
        if ($want === 0) $out[] = ['not-allowed', $rel, $m, "{$rel}: ->{$m}( at {$at} is not on the allowed list"];
        elseif (count($lines) !== $want) $out[] = ['count', $rel, $m, "{$rel}: " . count($lines) . " calls to ->{$m}( ({$at}); the allowed list says {$want}"];
    }
    foreach ($allowed as $rel => $byM) foreach ($byM as $m => $want) {
        if (!isset($calls[$rel][$m])) $out[] = ['stale', $rel, $m, "{$rel}: the allowed list expects {$want} call(s) to ->{$m}( and the file has none"];
    }
    return $out;
}

/** Index of the token closing the brace block opened at $i ('{', or "{$" / "${" inside a string), or -1. */
function t4_close(array $tok, int $i): int
{
    for ($d = 0, $n = count($tok); $i < $n; $i++) {
        $id = $tok[$i][0];
        if (t4_ch($tok, $i, '{') || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) $d++;
        elseif (t4_ch($tok, $i, '}') && --$d === 0) return $i;
    }
    return -1;
}
/** Index of the ')' closing the '(' at $i, or -1. */
function t4_paren(array $tok, int $i): int
{
    for ($d = 0, $n = count($tok); $i < $n; $i++) {
        if (t4_ch($tok, $i, '(')) $d++;
        elseif (t4_ch($tok, $i, ')') && --$d === 0) return $i;
    }
    return -1;
}

/**
 * The bodies of every `if ($rule)`: ['then' => [[from, to]], 'else' => [[from, to]]], token indexes, exclusive. Only
 * `{ … } else { … }` and `: … else: … endif` give an else. An elseif, `else if` or a body without braces gives none, so
 * a call there is not "in the else" — the conservative reading: it can only make the check refuse.
 */
function t4_rule_branches(array $tok, string $rule): array
{
    $then = []; $else = [];
    for ($i = 0, $n = count($tok); $i + 4 < $n; $i++) {
        if (!t4_id($tok, $i, T_IF) || !t4_ch($tok, $i + 1, '(') || !t4_id($tok, $i + 2, T_VARIABLE)
            || $tok[$i + 2][1] !== $rule || !t4_ch($tok, $i + 3, ')')) continue;
        $j = $i + 4;
        if (t4_ch($tok, $j, '{')) {
            $c = t4_close($tok, $j);
            if ($c < 0) continue;
            $then[] = [$j, $c];
            if (t4_id($tok, $c + 1, T_ELSE) && t4_ch($tok, $c + 2, '{') && ($e = t4_close($tok, $c + 2)) > 0) $else[] = [$c + 2, $e];
        } elseif (t4_ch($tok, $j, ':')) {
            // The alternative syntax: walk to this if's own else: / elseif / endif, past nested alternative-syntax ifs.
            $depth = 0; $stop = -1; $elseAt = -1; $chain = false;
            for ($k = $j + 1; $k < $n; $k++) {
                if (t4_id($tok, $k, T_IF) && t4_ch($tok, $k + 1, '(')) {
                    $p = t4_paren($tok, $k + 1);
                    if ($p > 0 && t4_ch($tok, $p + 1, ':')) $depth++;
                } elseif (t4_id($tok, $k, T_ENDIF)) {
                    if ($depth === 0) break;
                    $depth--;
                } elseif ($depth === 0 && t4_id($tok, $k, T_ELSEIF)) {
                    $chain = true; if ($stop < 0) $stop = $k;
                } elseif ($depth === 0 && t4_id($tok, $k, T_ELSE) && t4_ch($tok, $k + 1, ':')) {
                    if ($stop < 0) $stop = $k;
                    $elseAt = $k + 1;
                }
            }
            $then[] = [$j, $stop >= 0 ? $stop : $k];
            if ($elseAt > 0 && !$chain && $k < $n) $else[] = [$elseAt, $k];
        }
    }
    return ['then' => $then, 'else' => $else];
}
function t4_in(int $at, array $ranges): bool { foreach ($ranges as [$a, $b]) if ($a < $at && $at < $b) return true; return false; }

/** Every assignment to $var: [operator, right-hand side as its tokens' text, brace depth, token index]. */
function t4_assignments(array $tok, string $var): array
{
    $compound = [T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL, T_CONCAT_EQUAL, T_MOD_EQUAL, T_AND_EQUAL, T_OR_EQUAL,
                 T_XOR_EQUAL, T_SL_EQUAL, T_SR_EQUAL, T_POW_EQUAL, T_COALESCE_EQUAL];
    $out = []; $depth = 0;
    for ($i = 0, $n = count($tok); $i < $n; $i++) {
        $id = $tok[$i][0];
        if (t4_ch($tok, $i, '{') || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) { $depth++; continue; }
        if (t4_ch($tok, $i, '}')) { $depth--; continue; }
        if ($id !== T_VARIABLE || $tok[$i][1] !== $var || !isset($tok[$i + 1])) continue;
        if (!t4_ch($tok, $i + 1, '=') && !in_array($tok[$i + 1][0], $compound, true)) continue;
        $rhs = ''; $p = 0;
        for ($k = $i + 2; $k < $n; $k++) {
            if (t4_ch($tok, $k, '(') || t4_ch($tok, $k, '[')) $p++;
            if (t4_ch($tok, $k, ')') || t4_ch($tok, $k, ']')) $p--;
            if (($p <= 0 && t4_ch($tok, $k, ';')) || t4_id($tok, $k, T_CLOSE_TAG)) break;
            $rhs .= $tok[$k][1];
        }
        $out[] = [$tok[$i + 1][1], $rhs, $depth, $i];
    }
    return $out;
}

/** The CEO page's two checks on its source: [branch findings, gate findings], each a list of messages. */
function t4_ceo(string $src): array
{
    $tok = t4_tokens($src);
    $cp  = array_values(array_filter(t4_calls($tok), function ($c) { return $c[0] === 'currencyPositions'; }));
    $br  = t4_rule_branches($tok, T4_RULE);
    $branch = [];
    if (count($cp) !== 1) {
        $branch[] = T4_CEO . ': ' . count($cp) . ' calls to ->currencyPositions(' . ($cp ? ' (lines ' . implode(', ', array_column($cp, 1)) . ')' : '') . '; exactly one is allowed';
    }
    foreach ($cp as [$m, $ln, $at]) {
        if (t4_in($at, $br['then'])) $branch[] = T4_CEO . ": ->currencyPositions( at line {$ln} sits in a branch of if (" . T4_RULE . ') that runs under rule A';
        elseif (!t4_in($at, $br['else'])) $branch[] = T4_CEO . ": ->currencyPositions( at line {$ln} is not in the else of if (" . T4_RULE . ')';
    }
    $as = t4_assignments($tok, T4_RULE);
    $gate = [];
    $seen = implode(' | ', array_map(function ($a) { return T4_RULE . " {$a[0]} {$a[1]} (depth {$a[2]})"; }, $as)) ?: 'none';
    $okFirst  = isset($as[0]) && $as[0][0] === '=' && strtolower($as[0][1]) === 'false' && $as[0][2] === 0;
    $okSecond = isset($as[1]) && $as[1][0] === '=' && $as[1][1] === T4_GATE;
    if (count($as) !== 2 || !$okFirst || !$okSecond) {
        $gate[] = T4_CEO . ': ' . T4_RULE . ' must be set exactly twice — false at the top level, then ' . T4_GATE . "; found: {$seen}";
    }
    foreach ($br['then'] as [$a, $b]) {
        if (!$okFirst || $a < $as[0][3]) { $gate[] = T4_CEO . ': an if (' . T4_RULE . ') comes before ' . T4_RULE . ' is first set to false'; break; }
    }
    return [$branch, $gate];
}

/** One tree's whole verdict. */
function t4_evaluate(string $root): array
{
    $scan  = t4_scan($root);
    $judge = t4_judge($scan['calls'], T4_ALLOWED);
    foreach ($scan['unreadable'] as $rel) $judge[] = ['unreadable', $rel, '', "{$rel}: could not be read"];
    $src = @file_get_contents($root . '/' . T4_CEO);
    [$branch, $gate] = $src === false ? [[T4_CEO . ': missing'], [T4_CEO . ': missing']] : t4_ceo($src);
    return ['scan' => $scan, 'judge' => $judge, 'branch' => $branch, 'gate' => $gate];
}
function t4_msgs(array $findings): string { return implode('; ', array_map(function ($f) { return is_array($f) ? $f[3] : $f; }, $findings)); }
function t4_census(array $calls): array
{
    $n = 0; foreach ($calls as $byM) foreach ($byM as $lines) $n += count($lines);
    return [$n, count($calls)];
}
function t4_print_inventory(array $calls): void
{
    foreach ($calls as $rel => $byM) foreach ($byM as $m => $lines) {
        printf("       %-40s ->%s( ×%d  (line%s %s)\n", $rel, $m, count($lines), count($lines) > 1 ? 's' : '', implode(', ', $lines));
    }
}

// ═══════════════════════════════════════════════════════════════════════════
echo "A. the matcher and the CEO-branch reader, on fixed snippets and on the tree's own files\n";
$count = function (string $code): int { return count(t4_calls(t4_tokens('<?php ' . $code))); };
$isCall = [
    'a plain call'                              => '$cb->currencyPositions();',
    'spaces between the parts'                  => '$cb -> currencyPositions ( );',
    'a comment between the parts'               => '$cb->/* x */currencyPositions();',
    'another letter case'                       => '$cb->CurrencyPositions();',
    'through a property'                        => '$this->cb->accountBalance($id);',
    'inside a string, in braces'                => '$x = "{$cb->balancesByAccount()}";',
    'the nullsafe operator'                     => '$y = $cb?->currencyPositions();',
    'after a short echo tag'                    => '?><?= count($cb->currencyPositions()) ?>',
];
$notCall = [
    'a // comment'                              => '// $cb->currencyPositions();',
    'a # comment'                               => '# $cb->currencyPositions();',
    'a /* */ comment'                           => '/* $cb->currencyPositions(); */',
    'a docblock'                                => '/** $cb->currencyPositions(); */',
    "a single-quoted string"                    => '$x = \'$cb->currencyPositions()\';',
    'a property inside a string, then text'     => '$x = "$cb->currencyPositions()";',
    'inline HTML'                               => '?>$cb->currencyPositions()<?php ',
    'a declaration'                             => 'function currencyPositions() {}',
    "an array key ('accountBalance')"           => '$b = $client[\'accountBalance\'];',
    'a longer name'                             => '$cb->currencyPositionsAll();',
    'a property read'                           => '$x = $cb->currencyPositions;',
];
$miss = [];
foreach ($isCall as $label => $code)  if ($count($code) !== 1) $miss[] = "{$label} (" . $count($code) . ')';
foreach ($notCall as $label => $code) if ($count($code) !== 0) $miss[] = "{$label} (" . $count($code) . ')';
is_($miss === [], 'the matcher finds a call in each of ' . count($isCall) . ' forms and none in each of ' . count($notCall) . ' look-alikes', implode('; ', $miss));

// The CEO-branch reader: [is the one call in the else, and in no rule-A branch?, code]
$snip = [
    'brace form, the call in the else'              => [true,  'if ($_ceoRuleA) { $a = 1; } else { $p = $cb->currencyPositions(); }'],
    'alternative form, the call in the else'        => [true,  'if ($_ceoRuleA): ?>x<?php else: ?><?= count($cb->currencyPositions()) ?><?php endif; ?>'],
    'a nested alternative if inside the then'       => [true,  'if ($_ceoRuleA): ?><?php if ($x): ?>a<?php else: ?>b<?php endif; ?><?php else: ?><?php $cb->currencyPositions(); ?><?php endif;'],
    'a "{$x}" string inside the then'               => [true,  'if ($_ceoRuleA) { $a = "{$x}"; } else { $p = $cb->currencyPositions(); }'],
    'the call in the rule-A branch'                 => [false, 'if ($_ceoRuleA) { $p = $cb->currencyPositions(); } else { $a = 1; }'],
    'the call unconditional'                        => [false, 'if ($_ceoRuleA) { $a = 1; } else { $b = 2; } $p = $cb->currencyPositions();'],
    'the else of the negated rule'                  => [false, 'if (!$_ceoRuleA) { $a = 1; } else { $p = $cb->currencyPositions(); }'],
    'an elseif'                                     => [false, 'if ($_ceoRuleA) { $a = 1; } elseif ($x) { $p = $cb->currencyPositions(); }'],
    'an alternative elseif'                         => [false, 'if ($_ceoRuleA): ?>a<?php elseif ($x): ?><?php $cb->currencyPositions(); ?><?php endif;'],
    'the else of another variable'                  => [false, 'if ($_ceoRuleB) { $a = 1; } else { $p = $cb->currencyPositions(); }'],
    'an else nested inside the rule-A branch'       => [false, 'if ($_ceoRuleA) { if ($_ceoRuleA) { $a = 1; } else { $p = $cb->currencyPositions(); } } else { $b = 2; }'],
    'an alternative else nested inside the then'    => [false, 'if ($_ceoRuleA): ?><?php if ($x): ?>a<?php else: ?><?php $cb->currencyPositions(); ?><?php endif; ?><?php else: ?>b<?php endif;'],
];
$miss = [];
foreach ($snip as $label => [$want, $code]) {
    [$branch] = t4_ceo('<?php $_ceoRuleA = false; $_ceoRuleA = $_ceoCb->cashOnlyRuleInForce(); ' . $code);
    if (($branch === []) !== $want) $miss[] = $label . ($want ? ' (refused)' : ' (accepted)');
}
is_($miss === [], 'the CEO-branch reader accepts the else in ' . count(array_filter(array_column($snip, 0)))
    . ' forms and refuses ' . count(array_filter(array_column($snip, 0), function ($w) { return !$w; })) . ' others', implode('; ', $miss));

$tail = ' if ($_ceoRuleA) { $a = 1; } else { $p = $_ceoCb->currencyPositions(); }';
$gates = [
    'false at the top, then the gate'        => [true,  '$_ceoRuleA = false; if (!$s) { $_ceoRuleA = $_ceoCb->cashOnlyRuleInForce();' . $tail . ' }'],
    'forced true'                            => [false, '$_ceoRuleA = false; if (!$s) { $_ceoRuleA = true;' . $tail . ' }'],
    'no SSP alone'                           => [false, '$_ceoRuleA = false; if (!$s) { $_ceoRuleA = !$_ceoSSP;' . $tail . ' }'],
    'the gate widened'                       => [false, '$_ceoRuleA = false; if (!$s) { $_ceoRuleA = $_ceoCb->cashOnlyRuleInForce() || $x;' . $tail . ' }'],
    'a compound assignment after the gate'   => [false, '$_ceoRuleA = false; if (!$s) { $_ceoRuleA = $_ceoCb->cashOnlyRuleInForce(); $_ceoRuleA |= $x;' . $tail . ' }'],
    'false set inside a block'               => [false, 'if (!$s) { $_ceoRuleA = false; $_ceoRuleA = $_ceoCb->cashOnlyRuleInForce();' . $tail . ' }'],
    'the gate before false'                  => [false, 'if (!$s) { $_ceoRuleA = $_ceoCb->cashOnlyRuleInForce(); } $_ceoRuleA = false;' . $tail],
    'never set'                              => [false, ltrim($tail)],
];
$miss = [];
foreach ($gates as $label => [$want, $code]) {
    [, $gate] = t4_ceo('<?php ' . $code);
    if (($gate === []) !== $want) $miss[] = $label . ($want ? ' (refused)' : ' (accepted)');
}
is_($miss === [], 'the gate reader accepts false-then-' . T4_GATE . ' and refuses ' . (count($gates) - 1) . ' other ways of setting ' . T4_RULE, implode('; ', $miss));

// On the tree under test: the four names are the class's real methods (a misspelt name would make every check vacuous),
// the class calls some of them itself (so its exclusion matters), and the comment SPEC §d.1 cites is not a call.
$cbTok = t4_tokens((string)@file_get_contents($root . '/lib/CashbookService.php'));
$decl = [];
for ($i = 0, $n = count($cbTok); $i + 1 < $n; $i++) {
    if (t4_id($cbTok, $i, T_FUNCTION) && t4_id($cbTok, $i + 1, T_STRING)) $decl[strtolower($cbTok[$i + 1][1])] = true;
}
$undeclared = array_values(array_filter(T4_METHODS, function ($m) use ($decl) { return !isset($decl[strtolower($m)]); }));
is_($undeclared === [], 'lib/CashbookService.php declares all ' . count(T4_METHODS) . ': ' . implode(', ', T4_METHODS), 'not declared: ' . implode(', ', $undeclared));
$cbSelf = count(t4_calls($cbTok));
is_($cbSelf >= 1, "lib/CashbookService.php, the one file excluded, holds {$cbSelf} call(s) of its own — the exclusion matters",
    "found {$cbSelf}");
// The landing page is the case a grep gets wrong (SPEC §d.1): its comment names the method and the page calls none.
$dashSrc = (string)@file_get_contents($root . '/tabs/accounts/accounts_dashboard.php');
$dashCmt = 0;
foreach (token_get_all($dashSrc) as $t) {
    if (is_array($t) && ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT)) $dashCmt += substr_count($t[1], 'currencyPositions(');
}
$dashCalls = count(t4_calls(t4_tokens($dashSrc)));
is_($dashCmt >= 1 && $dashCalls === 0, "accounts_dashboard.php names currencyPositions() in {$dashCmt} comment(s) and holds {$dashCalls} reader calls",
    "comment mentions {$dashCmt}, calls found {$dashCalls}");

// ═══════════════════════════════════════════════════════════════════════════
echo "\nB. the positive control: f4b4c96, the live code (5.18.94), from Git\n";
$base = '';
if ($baseArg !== '') { $base = $baseArg; $why = ''; }
else { [$base, $why] = sj_baseline_tree(T4_LIVE); $base = (string)$base; }
if ($base === '' || !is_file($base . '/manifest.json')) {
    skip_('the live-code control: ' . ($why !== '' ? $why : 'no tree at ' . $base) . ' — not a pass');
    $base = '';
} else {
    $man = json_decode((string)file_get_contents($base . '/manifest.json'), true);
    is_((string)($man['information']['version'] ?? '') === '5.18.94', 'the baseline tree is 5.18.94 (' . T4_LIVE . ')',
        'its manifest says ' . (string)($man['information']['version'] ?? '?'));
    $B = t4_evaluate($base);
    [$bn, $bf] = t4_census($B['scan']['calls']);
    is_($B['judge'] === [], "its calls equal the allowed list exactly: {$bn} calls in {$bf} files, of " . count($B['scan']['files'])
        . ' scanned — the list is the live code\'s own inventory', t4_msgs($B['judge']));
    t4_print_inventory($B['scan']['calls']);
    $bBranch = array_values(array_filter($B['branch'], function ($m) { return strpos($m, 'is not in the else of') !== false; }));
    is_(count($B['branch']) === 1 && count($bBranch) === 1,
        'its CEO page FAILS the branch check, as it must: its one ->currencyPositions( is unconditional',
        'branch findings: ' . (t4_msgs($B['branch']) ?: 'none'));
    is_(count($B['gate']) >= 1 && strpos($B['gate'][0], 'found: none') !== false,
        'its CEO page FAILS the gate check, as it must: ' . T4_RULE . ' does not exist there',
        'gate findings: ' . (t4_msgs($B['gate']) ?: 'none'));
}

// ═══════════════════════════════════════════════════════════════════════════
echo "\nC. the tree under test\n";
$C = t4_evaluate($root);
[$cn, $cf] = t4_census($C['scan']['calls']);
is_($C['judge'] === [], "its calls equal the allowed list exactly: {$cn} calls in {$cf} files, of " . count($C['scan']['files']) . ' scanned',
    t4_msgs($C['judge']));
t4_print_inventory($C['scan']['calls']);
is_($C['branch'] === [], 'CEO page (' . T4_CEO . '): exactly one ->currencyPositions(, in the else of if (' . T4_RULE . ')',
    t4_msgs($C['branch']));
is_($C['gate'] === [], 'CEO page (' . T4_CEO . '): ' . T4_RULE . ' is false at the top level, then ' . T4_GATE . ', and nothing else',
    t4_msgs($C['gate']));
$tops = [];
foreach ($C['scan']['files'] as $rel) { $p = explode('/', $rel); $tops[count($p) > 1 ? $p[0] : '(root)'] = true; }
$absent = array_values(array_diff(['tabs', 'lib', 'includes', 'tools', 'cron', 'workers', '(root)'], array_keys($tops)));
is_($absent === [], 'the scan reached every code directory (tabs, lib, includes, tools, cron, workers) and the root files',
    'not reached: ' . implode(', ', $absent));

// ═══════════════════════════════════════════════════════════════════════════
echo "\nD. planted calls, in memory: each must be refused\n";
// The plant closes PHP if the file ends inside it (inline HTML otherwise), then opens a block of its own.
$plant = function (string $src, string $m): string { return $src . "\n?><?php \$t4Plant->{$m}();\n"; };
/** What the judge says about one file with a call planted: the kinds for (file, method), and whether the plant was seen. */
$plantJudge = function (string $rel, string $src, string $m) use ($C, $plant): array {
    $calls = [];
    foreach (t4_calls(t4_tokens($plant($src, $m))) as [$mm, $ln]) $calls[$mm][] = $ln;
    $seen  = count($calls[$m] ?? []) === count($C['scan']['calls'][$rel][$m] ?? []) + 1;
    $kinds = [];
    foreach (t4_judge([$rel => $calls], T4_ALLOWED) as $f) if ($f[1] === $rel && $f[2] === $m) $kinds[] = $f[0];
    return [$seen, $kinds];
};
$tabs = array_values(array_filter($C['scan']['files'], function ($r) { return strpos($r, 'tabs/') === 0; }));
$miss = []; $plants = 0;
foreach ($tabs as $rel) {
    $src = (string)file_get_contents($root . '/' . $rel);
    foreach (T4_METHODS as $m) {
        if ((T4_SPEC_TABS[$rel] ?? '') === $m) continue;               // the SPEC's own pair: one more call is D2's
        $plants++;
        [$seen, $kinds] = $plantJudge($rel, $src, $m);
        if (!$seen || $kinds !== ['not-allowed']) $miss[] = "{$rel} ->{$m}( (" . ($seen ? (implode(',', $kinds) ?: 'accepted') : 'plant not seen') . ')';
    }
}
$guarded = array_values(array_diff(['tabs/accounts/cashbook.php', 'tabs/accounts/accounts_dashboard.php'], $tabs));
is_($miss === [] && $guarded === [], "a call planted in each of the " . count($tabs) . " tab pages is refused there, {$plants} plants — judged against the SPEC's two pages, not the list",
    count($miss) . ' accepted: ' . implode('; ', array_slice($miss, 0, 6)) . ($guarded ? '; pages not reached: ' . implode(', ', $guarded) : ''));
$miss = [];
foreach (T4_SPEC_TABS as $rel => $specM) {
    $src = (string)@file_get_contents($root . '/' . $rel);
    [$seen, $kinds] = $plantJudge($rel, $src, $specM);
    if (!$seen || $kinds !== ['count']) $miss[] = "{$rel}: a second ->{$specM}( (" . (implode(',', $kinds) ?: 'accepted') . ')';
}
[$cBranch] = t4_ceo($plant((string)@file_get_contents($root . '/' . T4_CEO), 'currencyPositions'));
if ($cBranch === []) $miss[] = T4_CEO . ': the branch check accepts a second call after the page';
is_($miss === [], "each of the SPEC's two tab pages refuses a second call of its own method, and the CEO page's branch check refuses it too",
    implode('; ', $miss));
$miss = []; $listed = 0;
foreach (T4_ALLOWED as $rel => $byM) {
    if (isset(T4_SPEC_TABS[$rel])) continue;
    $src = (string)@file_get_contents($root . '/' . $rel);
    foreach (T4_METHODS as $m) {
        $listed++;
        [$seen, $kinds] = $plantJudge($rel, $src, $m);
        $want = isset($byM[$m]) ? 'count' : 'not-allowed';
        if (!$seen || $kinds !== [$want]) $miss[] = "{$rel} ->{$m}( (" . (implode(',', $kinds) ?: 'accepted') . ", wanted {$want})";
    }
}
is_($miss === [], 'every other listed file refuses one call more than allowed, and the methods it is not listed for (' . $listed . ' plants)',
    implode('; ', array_slice($miss, 0, 6)));
$miss = []; $others = 0;
foreach ($C['scan']['files'] as $rel) {
    if (strpos($rel, 'tabs/') === 0 || isset(T4_ALLOWED[$rel])) continue;
    $src = (string)file_get_contents($root . '/' . $rel);
    foreach (T4_METHODS as $m) {
        $others++;
        [$seen, $kinds] = $plantJudge($rel, $src, $m);
        if (!$seen || $kinds !== ['not-allowed']) $miss[] = "{$rel} ->{$m}( (" . ($seen ? (implode(',', $kinds) ?: 'accepted') : 'plant not seen') . ')';
    }
}
is_($miss === [] && $others > 0, "every other file outside the list refuses a planted call ({$others} plants)",
    count($miss) . ' accepted: ' . implode('; ', array_slice($miss, 0, 6)));
$listTabs = [];
foreach (T4_ALLOWED as $rel => $byM) if (strpos($rel, 'tabs/') === 0) $listTabs[$rel] = implode(',', array_keys($byM));
ksort($listTabs); $specTabs = T4_SPEC_TABS; ksort($specTabs);
is_($listTabs === $specTabs, 'the allowed list names exactly the two tab pages SPEC §d.1 names, each for its one method',
    'the list: ' . json_encode($listTabs));
is_(T4_EXCLUDED === ['lib/CashbookService.php'], 'exactly one file is excluded, the defining class', json_encode(T4_EXCLUDED));

// ═══════════════════════════════════════════════════════════════════════════
if ($withMutants) {
    echo "\nE. weakened copies: each must fail this test; the unchanged copy must pass\n";
    $T = 'tests/' . basename(__FILE__);
    $run = function (string $test, string $tree) use ($base): array {
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($test) . ' --root=' . escapeshellarg($tree)
            . ($base !== '' ? ' --baseline=' . escapeshellarg($base) : '') . ' --no-mutants 2>&1', $out, $rc);
        return [$rc, array_values(array_filter($out, function ($l) { return strpos($l, '  FAIL ') === 0; })), $out];
    };
    /** A second edit on a weakened copy, asserted: the anchor once, one replacement, the new text present. */
    $edit = function (string $file, string $old, string $new): bool {
        $src = (string)@file_get_contents($file);
        if (substr_count($src, $old) !== 1) return false;
        $res = str_replace($old, $new, $src, $k);
        return $k === 1 && strpos($res, $new) !== false && file_put_contents($file, $res) !== false;
    };
    $ceoElse = "        } else {\n            \$_ceoPositions = \$_ceoCb->currencyPositions();\n        }";
    $ceoOne  = '$_ceoPositions = $_ceoCb->currencyPositions();';
    $ceoCih  = 'foreach (dn_book_currencies($config ?? null) as $_ceoCihCur) $_ceoCih[$_ceoCihCur] = $_ceoCb->cashInHand($_ceoCihCur);';
    $ceoTiles = '<?php $_ct = 0; foreach ($_ceoCih as $_ceoCihCur => $_ceoCihAmt): if ($_ct >= 2) break; $_ct++; ?>';
    $listAt  = 'const T4_' . 'ALLOWED = [';                                // split, so this file holds the anchor once
    $listAdd = "\n    'tabs/accounts/cashbook.php'            => ['currencyPositions' => 1],";
    $cbLive  = '$_cbLiveCount = $cb->countEntries($proj);';
    $cbPlant = '$_cbLiveCount = $cb->countEntries($proj); $_cbPos = $cb->currencyPositions();';
    $brThen  = 'if (t4_in($at, $br' . "['then']))";                     // each split, so this file holds it once
    $brElse  = 'elseif (!t4_in($at, $br' . "['else']))";
    $cntAt   = 'elseif (count($lines) !' . '== $want)';
    // [label, file, anchor, replacement, second edit [file, anchor, replacement] or null, must fail?]
    $MUTANTS = [
        ['the unchanged copy', T4_CEO, '$_ceoRuleA = false;', '$_ceoRuleA = false;', null, false],
        ['a call planted in a tab: the Cashbook page reads ->currencyPositions(', 'tabs/accounts/cashbook.php', $cbLive, $cbPlant, null, true],
        ['a call planted in a tab: the Cashbook page reads a bank account\'s ledger, ->accountLedger(', 'tabs/accounts/cashbook.php', $cbLive,
            $cbLive . ' $_cbLed = $cb->accountLedger(1); $_cbBankBal = $_cbLed[\'balance\'] ?? 0;', null, true],
        ['a call planted in a tab: the landing page reads ->balancesByAccount(', 'tabs/accounts/accounts_dashboard.php',
            '$_held = $_adJsonSvc->getUSDBalance($_hid);', '$_held = $_adJsonSvc->getUSDBalance($_hid); $_dashAcc = $cbDash->balancesByAccount();', null, true],
        ['a call planted in a tab: the CEO page reads ->accountBalance(', T4_CEO,
            "\$_ceoPl = \$_ceoCb->plByPeriod('dishnet', date('Y-m') . '-01', date('Y-m-d'));",
            "\$_ceoPl = \$_ceoCb->plByPeriod('dishnet', date('Y-m') . '-01', date('Y-m-d')); \$_ceoAb = \$_ceoCb->accountBalance(1);", null, true],
        ['M18: the CEO rule-A branch calls ->currencyPositions( again', T4_CEO, $ceoCih,
            $ceoCih . "\n            \$_ceoPositions = \$_ceoCb->currencyPositions();", null, true],
        ['M18: a second CEO ->currencyPositions( call, in the else', T4_CEO, $ceoOne,
            $ceoOne . ' $_ceoPositions = $_ceoCb->currencyPositions() ?: $_ceoPositions;', null, true],
        ['M18: the one CEO call moved into the rule-A branch', T4_CEO, $ceoElse,
            "            \$_ceoPositions = \$_ceoCb->currencyPositions();\n        } else {\n        }", null, true],
        ['M18: the CEO rule-A tiles read the positions again (the alternative-syntax branch)', T4_CEO, $ceoTiles,
            '<?php $_ceoPositions = $_ceoCb->currencyPositions(); ?>' . $ceoTiles, null, true],
        ['M26: an allowed-list entry added to T4 for a tab', $T, $listAt, $listAt . $listAdd, null, true],
        ['M26: an allowed-list entry added to T4 for a tab, with a call planted there', $T, $listAt, $listAt . $listAdd,
            ['tabs/accounts/cashbook.php', $cbLive, $cbPlant], true],
        // T4's own checks weakened so that they would accept too much: its controls (A, B, D) must refuse the copy.
        ['T4 weakened: the CEO-branch check accepts a call anywhere', $T, $brThen, 'if (false && t4_in($at, $br' . "['then']))",
            [$T, $brElse, 'elseif (false && !t4_in($at, $br' . "['else']))"], true],
        ['T4 weakened: an allowed file may hold any number of calls', $T, $cntAt, 'elseif (false && count($lines) !' . '== $want)', null, true],
    ];
    foreach ($MUTANTS as [$label, $rel, $old, $new, $second, $mustFail]) {
        [$tmp, $n] = sj_weakened_copy($root, $rel, $old, $new);
        $ok2 = $second === null || $edit($tmp . '/' . $second[0], $second[1], $second[2]);
        if ($n !== 1 || !$ok2) {
            is_(false, "weakened copy \"{$label}\": its anchor occurs once", $n !== 1 ? "found {$n} times in {$rel}" : "the second edit's anchor in {$second[0]}");
            exec('rm -rf ' . escapeshellarg($tmp)); continue;
        }
        // The copy's own test judges the copy: for M26 that is the weakened test, for the rest an identical one.
        [$rc, $fails] = $run($tmp . '/' . $T, $tmp);
        if ($mustFail) is_($rc !== 0 && $fails !== [], "caught: {$label} — " . count($fails) . ' assertion(s) failed', "exit {$rc}, no failure");
        else           is_($rc === 0 && $fails === [], "{$label} passes", "exit {$rc}; " . implode(' | ', array_map('trim', $fails)));
        foreach (array_slice($fails, 0, 3) as $l) echo '         ' . trim(substr($l, 7, 150)) . "\n";
        exec('rm -rf ' . escapeshellarg($tmp));
    }
    // The control on the controls: this test, unchanged, judging f4b4c96's own tree, fails — on the CEO page alone.
    if ($base === '') {
        skip_('this test on f4b4c96\'s tree: no baseline tree — not a pass');
    } else {
        [$rc, $fails] = $run(__FILE__, $base);
        $elsewhere = array_values(array_filter($fails, function ($l) { return strpos($l, T4_CEO) === false; }));
        is_($rc !== 0 && $fails !== [] && $elsewhere === [],
            'this test FAILS on f4b4c96\'s own tree, as it must, and only on the CEO page — ' . count($fails) . ' assertion(s) failed',
            "exit {$rc}; failures outside the CEO page: " . implode(' | ', array_map('trim', $elsewhere)));
        foreach ($fails as $l) echo '         ' . trim(substr($l, 7, 150)) . "\n";
    }
}

printf("\n%d passed, %d failed, %d skipped\n", $pass, $fail, $skip);
exit($fail === 0 ? 0 : 1);
