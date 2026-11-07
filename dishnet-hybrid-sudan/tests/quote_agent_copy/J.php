<?php
declare(strict_types=1);
/**
 * quote_agent_copy J — the boundary (root docs/73 §H.5.6; ruling R10): P7's slice of the one merged declared list. The
 * merged list is Part F's (quote_followup N, stage B10); until it exists this section asserts P7's slice against 221c9dc
 * from Git: exactly lib/QuotationService.php, webhook.php and lib/CustomerEmailDispatcher.php changed among the files
 * §H.5.6 names (Part F's ladder and tool, the deploy script and tests/test_quote_summary.php are declared too, and are
 * judged by the merged list); P7's new files present; §H.5.6's held files byte for byte; no migration from P7; every
 * P7 hunk marked "5.18.104 (root docs/73 §H.5" and "P7", adding lines only; PHP 7.4 on every added line and on the new
 * library. TEST ONLY.
 *
 * 5.18.104 (root docs/73 §H.5.17 IP-8; ruling R10), stage B10: the merged list exists (tests/quote_followup/declared.php,
 * which quote_followup N reads), and J now reads P7's slice from it rather than lists of its own — the files P owns,
 * changed and new, and QF_HELD['P'] — and asserts the slice is §H.5.6's: the three runtime files and
 * tests/test_quote_summary.php changed, Part F's ladder and tool carrying P's lines, the new library, tests and fixture.
 */
require_once dirname(__DIR__) . '/quote_followup/declared.php';   // 5.18.104 (root docs/73 §H.5.17 IP-8): the one merged list

/** The hunks of $new against $old (diff -U0): [[added lines], [removed lines]] each. */
function qac_j_hunks(string $old, string $new, string $tmp): array
{
    file_put_contents($tmp . '/a', $old);
    file_put_contents($tmp . '/b', $new);
    $o = [];
    exec('diff -U0 ' . escapeshellarg($tmp . '/a') . ' ' . escapeshellarg($tmp . '/b'), $o);
    $h = []; $i = -1;
    foreach ($o as $l) {
        if (strpos($l, '@@') === 0) { $h[++$i] = [[], []]; continue; }
        if ($i < 0 || strpos($l, '+++') === 0 || strpos($l, '---') === 0) continue;
        if ($l !== '' && $l[0] === '+') $h[$i][0][] = substr($l, 1);
        if ($l !== '' && $l[0] === '-') $h[$i][1][] = substr($l, 1);
    }
    return $h;
}

/** PHP code with comments and string literals taken out (so a word in prose or a message is not read as syntax). */
function qac_j_code(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $tk) {
        if (is_array($tk) && in_array($tk[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)) { $out .= ' '; continue; }
        $out .= is_array($tk) ? $tk[1] : $tk;
    }
    return $out;
}

/** The PHP 8 constructs PHP 7.4 refuses, found in $code (comments and strings already out). */
function qac_j_php74(string $code, bool $noPolyfills): array
{
    $bans = [
        'match'                 => '/(?<![\w$>:])match\s*\(/',
        'nullsafe ?->'          => '/\?->/',
        'attributes #['         => '/#\[/',
        'enum'                  => '/(?<![\w$>:])enum\s+\w+/',
        'readonly'              => '/(?<![\w$>:])readonly\b/',
        'never/mixed types'     => '/\)\s*:\s*\??(never|mixed)\b|\bmixed\s+\$/',
        'union types'           => '/(?:\(|,)\s*\??[\w\\\\]+\|[\w\\\\|]+\s+&?\$|\)\s*:\s*\??[\w\\\\]+\|/',
        'named arguments'       => '/[(,]\s*[a-zA-Z_]\w*\s*:(?!:)\s*[$\'"\d\[]/',
        'new in initializers'   => '/function\s*\w*\s*\([^)]*=\s*new\b/',
        'first-class callables' => '/\w\(\.\.\.\)/',
        'throw as expression'   => '/(\?\?|=>|\?)\s*throw\b/',
    ];
    if ($noPolyfills) $bans['str_contains/starts_with/ends_with'] = '/\bstr_(contains|starts_with|ends_with)\s*\(/';
    $hit = [];
    foreach ($bans as $k => $re) if (preg_match($re, $code)) $hit[] = $k;
    return $hit;
}

$QAC_SECTIONS['J'] = ['title' => 'The boundary: P7\'s slice against 221c9dc (§H.5.6; ruling R10)', 'run' => function (QfCtx $ctx) {
    $root = $ctx->root;
    // 5.18.104 (root docs/73 §H.5.17 IP-8), stage B10: P7's slice of the one merged declared list, never a list of J's own
    $mine = array_filter(qf_declared('P'), function ($row) { return explode(',', $row[0])[0] === 'P'; });
    $changedAll = array_keys(array_filter($mine, function ($row) { return $row[1] === 'changed'; }));
    $changed = array_values(array_filter($changedAll, function ($f) { return strpos($f, 'tests/') !== 0; }));
    $held = QF_HELD['P'];
    $new = [];
    foreach ($mine as $f => $row) {
        if ($row[1] !== 'new') continue;
        if (substr($f, -1) !== '/') { $new[] = $f; continue; }
        foreach (range('A', 'J') as $l) $new[] = $f . $l . '.php';
        foreach (['_lib.php', 'drive.php', 'mutants.php'] as $l) $new[] = $f . $l;
    }
    $ctx->run('J00 the slice J reads is §H.5.6\'s: three runtime files and tests/test_quote_summary.php changed; the library, its tests and fixture new; Part F\'s ladder and tool carry P\'s lines', function () use ($ctx, $changedAll, $mine) {
        $wantChanged = ['lib/CustomerEmailDispatcher.php', 'lib/QuotationService.php', 'tests/test_quote_summary.php', 'webhook.php'];
        $got = $changedAll; sort($got);
        $newOwn = array_keys(array_filter($mine, function ($row) { return $row[1] === 'new'; })); sort($newOwn);
        $ctx->eq([$got, $newOwn, isset(qf_declared('P')['lib/SalesFollowupLadder.php'], qf_declared('P')['tools/sales_followup.php'])],
                 [$wantChanged, ['lib/QuoteAgentCopy.php', 'tests/fixtures/fake_smtp_refuse.php', 'tests/quote_agent_copy/', 'tests/test_quote_agent_copy.php'], true],
                 'J00 P7\'s slice of tests/quote_followup/declared.php');
    });

    $ctx->run('J0 positive control: Git supplies 221c9dc, and a known held file reads as its own', function () use ($ctx, $root) {
        $m = qac_base_file('lib/MailService.php');
        $ctx->t($m !== null && $m === (string)@file_get_contents($root . '/lib/MailService.php'), 'J0 221c9dc\'s lib/MailService.php from Git equals this tree\'s');
        $ctx->t(qac_base_file('lib/QuoteAgentCopy.php') === null, 'J0 … and 221c9dc has no lib/QuoteAgentCopy.php');
    });

    $ctx->run('J1 among the files §H.5.6 names, exactly the three changed; P7\'s new files present', function () use ($ctx, $root, $changed, $held, $new) {
        $diff = [];
        foreach (array_merge($changed, $held) as $f) {
            $o = qac_base_file($f);
            if ($o === null || $o !== (string)@file_get_contents($root . '/' . $f)) $diff[] = $f;
        }
        sort($diff); $want = $changed; sort($want);
        $ctx->eq($diff, $want, 'J1 changed: exactly lib/QuotationService.php, webhook.php and lib/CustomerEmailDispatcher.php; the ' . count($held) . ' held files byte for byte');
        $ts = qac_base_file('tests/test_quote_summary.php');   // 5.18.104 (root docs/73 §H.5.13), stage B10: the fourth, declared
        $ctx->t($ts !== null && $ts !== (string)@file_get_contents($root . '/tests/test_quote_summary.php'), 'J1 … and tests/test_quote_summary.php changed (section M re-pointed, §H.5.13)');
        $ctx->eq(array_values(array_filter($new, function ($f) use ($root) { return !is_file($root . '/' . $f); })), [], 'J1 every new file of P7 is present');
    });

    $ctx->run('J2 no migration from P7', function () use ($ctx, $root) {
        $mig = [];
        foreach (glob($root . '/migrations/*') ?: [] as $f) if (stripos((string)file_get_contents($f), 'quote_agent') !== false) $mig[] = basename($f);
        $ctx->eq([$mig, count(glob($root . '/migrations/*.sql') ?: []) > 80], [[], true], 'J2 no migration mentions P7\'s collections (and the folder was read)');
    });

    $ctx->run('J3 every P7 hunk is marked and adds lines only; PHP 7.4 on every added line and on the new library', function () use ($ctx, $root, $changed) {
        $bad = []; $n = 0; $added = '';
        foreach ($changed as $f) {
            $old = qac_base_file($f);
            if ($old === null) { $bad[] = "{$f}: no 221c9dc copy"; continue; }
            foreach (qac_j_hunks($old, (string)file_get_contents($root . '/' . $f), $ctx->dir('j3')) as $i => [$plus, $minus]) {
                $n++;
                $txt = implode("\n", $plus);
                if ($minus !== []) $bad[] = "{$f} hunk {$i}: removes a line";
                if (strpos($txt, '5.18.104 (root docs/73 §H.5') === false || strpos($txt, 'P7') === false) $bad[] = "{$f} hunk {$i}: no P7 marker";
                $added .= $txt . "\n";
            }
        }
        $ctx->eq([$bad, $n], [[], 12], 'J3 twelve hunks (four in createCrmQuote, two in emailQuotePdf, two in whQuotationEmail, four in the dispatcher), each marked, none removing a line');
        $ctx->eq(qac_j_php74(qac_j_code("<?php\n" . $added), false), [], 'J3 the added lines use nothing PHP 7.4 lacks');
        $lib = (string)file_get_contents($root . '/lib/QuoteAgentCopy.php');
        $ctx->eq(qac_j_php74(qac_j_code($lib), true), [], 'J3 lib/QuoteAgentCopy.php uses nothing PHP 7.4 lacks, and no str_contains-family call (it loads no polyfill)');
        $ctx->eq(qac_j_php74(qac_j_code('<?php $x = match ($a) { 1 => 2 }; f(name: $v); $o?->p;'), true), ['match', 'nullsafe ?->', 'named arguments'],
            'J3 control: the scan finds match, ?-> and a named argument');
        $lint = [];
        foreach (array_merge($changed, ['lib/QuoteAgentCopy.php']) as $f) {
            $o = []; exec('php -l ' . escapeshellarg($root . '/' . $f) . ' 2>&1', $o, $rc);
            if ($rc !== 0) $lint[] = $f;
        }
        $ctx->eq($lint, [], 'J3 php -l passes on the four files (PHP ' . PHP_VERSION . '; php7.4 is not installed here — the scan above stands for it)');
    });
}];
