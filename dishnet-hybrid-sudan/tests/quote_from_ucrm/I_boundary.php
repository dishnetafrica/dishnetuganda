<?php
declare(strict_types=1);
/**
 * I — the release boundary (docs/71 §L.1 "The boundary", §L.4): the judged tree (--root) against f2055baf, the 5.18.101
 * release commit, taken from Git by this checkout — never from the working tree, which a later release moves on.
 *
 * Measured: only the declared files change; no migration; the manifest by its version line only; the files §L.4 keeps
 * are f2055baf's byte for byte; every hunk of every runtime file carries its 5.18.102 marker; php -l, and the PHP 7.4
 * rules on every added code line; each quote-create site, on Uganda, after the resolver's verdict in its own function.
 * The walk leaves out what a release does not ship and a weakened copy does not carry: docs/, prototype/,
 * dishnet-mikrotik-control-plane/, data/, .git and scripts/ (the deploy script and its harness are their own commit).
 * TEST ONLY.
 *
 * 5.18.102 D14 (docs/71 §R.9.1 I, §R.11): the declared files gain lib/UcrmQuoteLinks.php and the new section's file,
 * tests/quote_from_ucrm/L_links.php (this test's own directory is otherwise not declared file by file); the kept-file list
 * and the no-migration check are unchanged, and api/index.php stays f2055baf's. New: the inventory of every UcrmQuoteLines
 * construction site, each passing a link judge (I6); the loader — lib/UcrmQuoteLinks.php requires no sibling file, and
 * lib/UcrmQuoteLines.php requires it by its own path (I7); and kyc_form.php's kit loop choosing its cell by
 * UcrmQuoteLinks::offeredKit, with no type test of its own (I8). I5 is rewritten where §R.4.8 moved retry_quote's
 * gated path into the shared implementation.
 */

const QFU_I_BASE = 'f2055baf';
const QFU_I_SKIP = ['docs', 'prototype', 'dishnet-mikrotik-control-plane', 'data', '.git', 'scripts'];
/** Runtime files 5.18.102 adds or changes (§L.4, amended: the three product screens' "Done" on the 409, §K.3). */
const QFU_I_RUNTIME = [
    'includes/api/api_cron_debug.php', 'includes/api/api_products_admin.php', 'includes/post/post_sync.php',
    'lib/CrmApiClient.php', 'lib/DeliveryPdfService.php', 'lib/KycCrmSync.php', 'lib/KycService.php',
    'lib/NotificationService.php', 'lib/PluginQuotePdf.php', 'lib/QuotationService.php', 'lib/UcrmClientTarget.php',
    'lib/UcrmQuoteLines.php', 'lib/UcrmQuoteLinks.php', 'tabs/admin/settings.php', 'tabs/sales/applications.php', 'tabs/sales/hardware.php',
    'tabs/sales/kyc_form.php', 'tabs/sales/product_mapping.php', 'tabs/sales/send_quote.php',
    'tabs/sales/subscription_plans.php',
];
/** The manifest (its version line) and the documents in the plugin's own directory. */
const QFU_I_OTHER = ['SAFETY.md', 'manifest.json'];
/** Tests and fixtures 5.18.102 adds or rewrites on purpose (§L.2); tests/quote_from_ucrm/ is this test's own directory. */
const QFU_I_TESTS = [
    'tests/fixtures/fake_ucrm_kyc.php', 'tests/fixtures/fake_ucrm_quote_org.php', 'tests/fixtures/quote_company_journey.php',
    'tests/test_bank_boundaries.php',
    'tests/test_cashbook_physical_list.php', 'tests/test_distributor_apply.php', 'tests/test_distributor_link_ucrm.php',
    'tests/test_distributor_notify.php', 'tests/test_distributor_registry.php', 'tests/test_distributor_territory.php',
    'tests/test_field_cash_in_currency.php', 'tests/test_kyc_crm_messages.php', 'tests/test_notify_kyc_quote_send.php',
    'tests/test_notify_kyc_race.php', 'tests/test_quote_company.php', 'tests/test_quote_company_mutants.php',
    'tests/test_quote_from_ucrm.php', 'tests/test_quote_summary.php', 'tests/test_staff_manual_entry_currency.php',
];
/**
 * 5.18.102 D14 (docs/71 §R.11): the files D14 adds to this test's own directory, declared by name — the new section's
 * file. The directory is otherwise left out of I1's exact list; these must be present in it.
 */
const QFU_I_OWN_ADDED = ['tests/quote_from_ucrm/L_links.php'];
/** §L.4 "Kept byte for byte", named because each is read by, or sits beside, what changed. */
const QFU_I_KEPT = [
    'webhook.php', 'lib/QuoteOrganization.php', 'lib/QuoteTaxLine.php', 'lib/TenantProfile.php', 'lib/PublicPriceFeed.php',
    'cron_quote_wa.php', 'lib/CashbookService.php', 'includes/post/post_kyc.php', 'includes/api/api_retailer.php',
    'retailer/index.html',
];
/** The PHP 7.4 rules: the same as tests/test_quote_company.php's QC_I6_RULES (that file defines them as constants of its own). */
const QFU_I_RULES = [
    'match ('                        => '/\bmatch\s*\(/',
    'str_contains('                  => '/\bstr_contains\(/',
    'str_starts_with('               => '/\bstr_starts_with\(/',
    'str_ends_with('                 => '/\bstr_ends_with\(/',
    '?->'                            => '/\?->/',
    'readonly'                       => '/\breadonly\b/',
    'a named argument'               => '/[(,]\s*[a-zA-Z_][a-zA-Z0-9_]*\s*:(?!:)\s*[^\s:]/',
    'a union type'                   => '/(?:[(,]\s*|\)\s*:\s*)\??[A-Za-z_\\\\][A-Za-z0-9_\\\\]*\s*\|\s*\??[A-Za-z_\\\\][A-Za-z0-9_\\\\]*(?:\s+&?(?:\.\.\.)?\$|\s*[{;])/',
    'constructor promotion'          => '/\bfunction\s+__construct\s*\([^)]*\b(?:public|private|protected)\s/',
    'a mixed, static or never type'  => '/\)\s*:\s*\??(?:mixed|static|never)\b|[(,]\s*\??mixed\s+&?\$/',
    'throw as an expression'         => '/(?:=>|\?\?|\?:|(?<![=!<>])=(?![=>]))\s*throw\b/',
    '::class on an object'           => '/\$[A-Za-z_][A-Za-z0-9_]*(?:->[A-Za-z_][A-Za-z0-9_]*)*::class\b/',
    'a trailing comma in a parameter or use list' => '/\b(?:function|fn|use)\b[^()]*\([^()]*,\s*\)/',
];

$QFU_SECTIONS['I'] = ['title' => 'The boundary: the declared files against f2055baf, from Git', 'run' => function (QfuCtx $c) {
    $checkout = dirname(__DIR__, 2);   // this test's own checkout: Git is read here, whatever --root is
    $T = $c->root;
    // f2055baf's plugin tree, from Git, into the run's own folder
    $B = $c->dir('base-f2055baf');
    // From the repository's top level: run inside dishnet-hybrid-sudan/, git archive keeps only that prefix of the tree
    // it is given, and a tree that is already the plugin's would come out empty.
    $top = trim((string)shell_exec('git -C ' . escapeshellarg($checkout) . ' rev-parse --show-toplevel 2>/dev/null'));
    $o = []; exec('git -C ' . escapeshellarg($top !== '' ? $top : $checkout) . ' archive -o ' . escapeshellarg($B . '.tar') . ' '
        . escapeshellarg(QFU_I_BASE . ':dishnet-hybrid-sudan') . ' 2>&1 && tar -xf ' . escapeshellarg($B . '.tar') . ' -C ' . escapeshellarg($B) . ' 2>&1', $o, $rc);
    $ver = (string)(json_decode((string)@file_get_contents($B . '/manifest.json'), true)['information']['version'] ?? '');
    $c->t($rc === 0 && $ver === '5.18.101', 'I0 the control: Git supplies ' . QFU_I_BASE . ', the 5.18.101 release — without it nothing below can be measured: a failure, never a skip',
        json_encode([$rc, $ver, $o]));
    if ($rc !== 0 || $ver !== '5.18.101') return;

    $walk = function (string $tree): array {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($tree, \FilesystemIterator::SKIP_DOTS),
            function ($f) use ($tree) { return !in_array(explode('/', substr($f->getPathname(), strlen($tree) + 1))[0], QFU_I_SKIP, true); }));
        foreach ($it as $f) if ($f->isFile()) $out[substr($f->getPathname(), strlen($tree) + 1)] = $f->getPathname();
        ksort($out);
        return $out;
    };
    $base = $walk($B);
    $tree = $walk($T);
    $c->t(count($base) > 500 && isset($tree['lib/KycService.php'], $tree['webhook.php']), 'I0 … both walks see the plugin (' . count($base) . ' and ' . count($tree) . ' files)');
    $changed = []; $added = []; $removed = [];
    foreach ($tree as $rel => $p) {
        if (!isset($base[$rel])) { $added[] = $rel; continue; }
        if (hash_file('sha256', $p) !== hash_file('sha256', $base[$rel])) $changed[] = $rel;
    }
    foreach ($base as $rel => $p) if (!isset($tree[$rel])) $removed[] = $rel;
    $own = fn(string $r) => strpos($r, 'tests/quote_from_ucrm/') === 0;
    $all = array_values(array_filter(array_merge($changed, $added), fn($r) => !$own($r)));
    sort($all);
    $want = array_merge(QFU_I_RUNTIME, QFU_I_OTHER, QFU_I_TESTS);
    sort($want);

    // I1 — exactly the declared files
    $c->eq(['not declared' => array_values(array_diff($all, $want)), 'declared, not changed' => array_values(array_diff($want, $all)), 'removed' => $removed],
        ['not declared' => [], 'declared, not changed' => [], 'removed' => []],
        'I1 against ' . QFU_I_BASE . ' the tree changes or adds exactly the ' . count($want) . ' declared files (and this test\'s own directory), and removes none');
    // 5.18.102 D14 (rewritten on purpose, docs/71 §R.11): the link judge is the third new file
    $c->eq(array_values(array_filter($added, fn($r) => !$own($r))),
        ['lib/UcrmQuoteLines.php', 'lib/UcrmQuoteLinks.php', 'tests/test_quote_from_ucrm.php'],
        'I1 … of which three are new outside this test\'s directory: the resolver, the link judge (lib/UcrmQuoteLinks.php) and this test');
    $c->eq(array_values(array_diff(QFU_I_OWN_ADDED, $added)), [],
        'I1 … and this test\'s directory gains the file §R.11 declares there: the new section\'s, L_links.php (the links, §R.9.1 L)');

    // I2 — no migration; the manifest by its version line; the kept files
    $mig = function (string $t): array { $o = []; foreach (glob($t . '/migrations/*') ?: [] as $f) $o[basename($f)] = hash_file('sha256', $f); ksort($o); return $o; };
    $c->t($mig($T) === $mig($B) && count($mig($B)) > 50, 'I2 migrations/ is ' . QFU_I_BASE . '\'s, file for file (' . count($mig($B)) . ' files): no migration');
    $mb = str_replace('    "version": "5.18.101",', '    "version": "5.18.102",', (string)file_get_contents($B . '/manifest.json'), $mn);
    $c->eq([$mn, $mb === (string)@file_get_contents($T . '/manifest.json')], [1, true], 'I2 manifest.json is ' . QFU_I_BASE . '\'s with one line changed, its version, 5.18.101 → 5.18.102');
    $kept = [];
    foreach (QFU_I_KEPT as $rel) if (!is_file($T . '/' . $rel) || !is_file($B . '/' . $rel) || hash_file('sha256', $T . '/' . $rel) !== hash_file('sha256', $B . '/' . $rel)) $kept[] = $rel;
    $c->eq($kept, [], 'I2 the ' . count(QFU_I_KEPT) . ' files §L.4 keeps are ' . QFU_I_BASE . '\'s, byte for byte (webhook.php, QuoteOrganization.php, post_kyc.php, api_retailer.php …)');
    // 5.18.102 D14 (docs/71 §R.4.2, §R.11): the entry point is read from the web form's own marker, so the mobile API's
    // entry file is not changed either (the kept-file list itself is not amended)
    $c->t(is_file($T . '/api/index.php') && is_file($B . '/api/index.php') && hash_file('sha256', $T . '/api/index.php') === hash_file('sha256', $B . '/api/index.php'),
        'I2 … and api/index.php (the mobile API) is ' . QFU_I_BASE . '\'s, byte for byte');

    // I3 — every hunk of every runtime file names 5.18.102: in the hunk, in the 30 lines above it in the new file, or the
    // hunk only closes a block opened under a marker (a PHP endif or else tag, a brace).
    $unmarked = []; $hunkCount = 0;
    foreach (QFU_I_RUNTIME as $rel) {
        $new = $T . '/' . $rel;
        if (!is_file($new)) { $unmarked[] = $rel . ' (missing)'; continue; }
        $old = is_file($B . '/' . $rel) ? $B . '/' . $rel : '/dev/null';
        $nl = explode("\n", (string)file_get_contents($new));
        $d = []; exec('diff -U0 ' . escapeshellarg($old) . ' ' . escapeshellarg($new), $d);
        foreach ($d as $l) {
            if (!preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@/', $l, $h)) continue;
            $hunkCount++;
            $start = (int)$h[1]; $len = isset($h[2]) ? (int)$h[2] : 1;
            $from = max(0, ($len === 0 ? $start : $start - 1) - 30);
            $to = ($len === 0 ? $start : $start - 1 + $len);
            $span = implode("\n", array_slice($nl, $from, $to - $from + ($len === 0 ? 1 : 0)));
            $hunkLines = array_slice($nl, $len === 0 ? $start : $start - 1, $len);
            $closer = $hunkLines !== [] && array_filter($hunkLines, fn($x) => !preg_match('/^\s*(?:<\?php\s+(?:endif|else|endforeach);\s*\?>|[})\];,]+)\s*$/', $x)) === [];
            if (strpos($span, '5.18.102') === false && !$closer) $unmarked[] = $rel . ' +' . $start . ',' . $len . ': ' . mb_substr(trim($nl[max(0, $start - 1)] ?? ''), 0, 100);
        }
    }
    $c->eq($unmarked, [], 'I3 every one of the ' . $hunkCount . ' hunks in the ' . count(QFU_I_RUNTIME) . ' runtime files carries its 5.18.102 marker');

    // I4 — php -l on every new or changed PHP file; the PHP 7.4 rules on every added code line of the runtime files
    // The PHP code only: comments and a page's inline HTML/JavaScript taken out (the line count kept), so the PHP 7.4 rules
    // read PHP — a JavaScript object literal is not a named argument.
    $code = function (string $src): string {
        $o = '';
        foreach (token_get_all($src) as $tk) {
            if (is_array($tk) && in_array($tk[0], [T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML], true)) { $o .= str_repeat("\n", substr_count($tk[1], "\n")); continue; }
            $o .= is_array($tk) ? $tk[1] : $tk;
        }
        return $o;
    };
    $bins = ['php ' . PHP_VERSION => PHP_BINARY];
    foreach (['php7.4', 'php8.1'] as $bin) {
        $where = trim((string)shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null'));
        if ($where !== '') $bins[$bin] = $where;
        else echo "  note I4 limitation: {$bin} is not installed here, so no file was linted under it; PHP " . substr($bin, 3)
                . " syntax rests on the rules below and on php -l under PHP " . PHP_VERSION . " — a stated limitation, not a pass\n";
    }
    $lintFail = []; $bad = []; $linted = 0;
    foreach (array_merge($all, array_values(array_filter(array_keys($tree), $own))) as $rel) {
        if (substr($rel, -4) !== '.php') continue;
        $linted++;
        foreach ($bins as $bn => $bin) {
            $lo = []; exec(escapeshellarg($bin) . ' -l ' . escapeshellarg($T . '/' . $rel) . ' 2>&1', $lo, $lrc);
            if ($lrc !== 0) $lintFail[] = $rel . ' (' . $bn . ')';
        }
        if (!in_array($rel, QFU_I_RUNTIME, true)) continue;
        $cb = $c->tmp . '/i4_base.php'; $cn = $c->tmp . '/i4_new.php';
        file_put_contents($cb, is_file($B . '/' . $rel) ? $code((string)file_get_contents($B . '/' . $rel)) : '');
        file_put_contents($cn, $code((string)file_get_contents($T . '/' . $rel)));
        $d = []; exec('diff ' . escapeshellarg($cb) . ' ' . escapeshellarg($cn), $d);
        foreach ($d as $l) {
            if (strpos($l, '> ') !== 0) continue;
            $l = substr($l, 2);
            foreach (QFU_I_RULES as $what => $re) {
                if (preg_match($re, $l) && !($what === 'a named argument' && preg_match('/\?\s*[^:]*:/', $l))) { $bad[] = "{$rel}: {$what}: " . trim($l); break; }
            }
        }
    }
    $c->eq($lintFail, [], 'I4 php -l passes on every one of the ' . $linted . ' new or changed PHP files, under ' . implode(', ', array_keys($bins)));
    $c->eq($bad, [], 'I4 no added code line of the runtime files uses ' . implode(', ', array_keys(QFU_I_RULES)));
    $miss = [];
    foreach (['match (' => ['$x = match ($a) { 1 => 2 };', '$x = $matches[0];'], '?->' => ['$x = $a?->b;', '$x = $a->b ?? null;'],
              'a named argument' => ['foo(name: 1);', 'foo($a, B::C);'], 'str_contains(' => ['if (str_contains($h, $n)) {', 'if (strpos($h, $n) !== false) {']]
             as $what => [$hit, $clean]) {
        if (preg_match(QFU_I_RULES[$what], $hit) !== 1 || preg_match(QFU_I_RULES[$what], $clean) === 1) $miss[] = $what;
    }
    $c->eq($miss, [], 'I4 control: the rules catch a PHP 8 line and pass its PHP 7.4 form (four of them, sampled)');

    // I5 — every quote-create site, on Uganda, after the resolver's verdict in its own function (or action)
    $fns = function (string $src): array {
        $toks = token_get_all($src); $out = []; $line = 1; $stack = []; $pending = null; $depth = 0;
        foreach ($toks as $i => $tk) {
            $txt = is_array($tk) ? $tk[1] : $tk;
            if (is_array($tk) && $tk[0] === T_FUNCTION) {
                $name = 'closure';
                for ($j = $i + 1; $j < $i + 4 && isset($toks[$j]); $j++) if (is_array($toks[$j]) && $toks[$j][0] === T_STRING) { $name = $toks[$j][1]; break; }
                $pending = $name;
            }
            if ($txt === '{' || (is_array($tk) && in_array($tk[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
                if ($pending !== null && $txt === '{') { $stack[] = [$pending, $line, $depth]; $pending = null; }
            } elseif ($txt === '}') {
                if ($stack && end($stack)[2] === $depth) { [$n, $l0] = array_pop($stack); $out[] = [$n, $l0, $line]; }
                $depth--;
            } elseif ($txt === ';' && $pending !== null) {
                $pending = null;
            }
            $line += substr_count($txt, "\n");
        }
        return $out;
    };
    /** [file, function, the text before the call] for every match of $re in the tree's PHP (comments taken out). */
    $calls = function (string $re) use ($tree, $code, $fns): array {
        $out = [];
        foreach ($tree as $rel => $path) {
            if (substr($rel, -4) !== '.php' || strpos($rel, 'tests/') === 0) continue;
            $src = $code((string)file_get_contents($path));
            if (!preg_match_all($re, $src, $m, PREG_OFFSET_CAPTURE)) continue;
            $lines = explode("\n", $src);
            $fl = $fns($src);
            foreach ($m[0] as [$hit, $off]) {
                $ln = substr_count(substr($src, 0, $off), "\n") + 1;
                $scope = null;
                foreach ($fl as [$n, $a, $b]) if ($a <= $ln && $ln <= $b && ($scope === null || $a > $scope[1])) $scope = [$n, $a];
                if ($scope === null) {
                    for ($k = $ln - 1; $k >= 0; $k--) if (preg_match("#if \(\\\$act === '([a-z_0-9]+)'#", $lines[$k], $am)) { $scope = ['act:' . $am[1], $k + 1]; break; }
                    if ($scope === null) $scope = ['top', 1];
                }
                $out[] = [$rel, $scope[0], implode("\n", array_slice($lines, $scope[1] - 1, $ln - $scope[1])) . "\n" . substr($lines[$ln - 1], 0, max(0, $off - strlen(implode("\n", array_slice($lines, 0, $ln - 1))) - 1))];
            }
        }
        usort($out, fn($a, $b) => strcmp($a[0] . $a[1], $b[0] . $b[1]));
        return $out;
    };
    $sites = array_map(fn($s) => [$s[0], $s[1]], $calls('#->post\(\s*(["\'])(clients/\{?\$[A-Za-z_]+\}?/quotes|billing/quotes)\1#'));
    $c->eq($sites, [['includes/api/api_cron_debug.php', 'act:retry_quote'], ['lib/KycService.php', 'handleExisting'],
                    ['lib/KycService.php', 'postQuote'], ['lib/QuotationService.php', 'createCrmQuote']],
        'I5 the quote creates are 5.18.99\'s four (retry_quote, handleExisting, postQuote, createCrmQuote), no new one');
    // 5.18.102 D14 (rewritten on purpose, docs/71 §R.4.8, F-1): retry_quote's own POST is now 5.18.101's gate-off path. Where
    // the gate is on, the block guarded by $_qoRetry['on'] hands the application to the shared implementation
    // (->makeQuotation, which reaches postQuote only after retryLines — below) and answers every kind before that POST: its
    // last statement is an unconditional $er2, and it posts, patches and reads back nothing itself.
    $blockAfter = function (string $src, int $at): string {
        $open = strpos($src, '{', $at);
        if ($open === false) return '';
        $d = 0;
        for ($i = $open, $n = strlen($src); $i < $n; $i++) {
            if ($src[$i] === '{') $d++;
            elseif ($src[$i] === '}' && --$d === 0) return substr($src, $open + 1, $i - $open - 1);
        }
        return '';
    };
    $verdict = [];
    foreach ($calls('#->post\(\s*(["\'])(clients/\{?\$[A-Za-z_]+\}?/quotes)\1#') as [$rel, $fn, $before]) {
        if ($fn === 'postQuote') continue;
        if ($fn === 'act:retry_quote') {
            $ok = false;
            if (preg_match_all('#if \(\$_qoRetry\[\'on\'\]\) \{#', $before, $gm, PREG_OFFSET_CAPTURE)) {
                foreach ($gm[0] as [, $off]) {
                    $body = $blockAfter($before, $off);
                    $lines = array_values(array_filter(array_map('trim', explode("\n", $body)), fn($x) => $x !== ''));
                    if (strpos($body, '->makeQuotation(') !== false && preg_match('#->(post|patch)\(|readBack\(#', $body) !== 1
                        && $lines !== [] && strpos(end($lines), '$er2(') === 0) $ok = true;
                }
            }
            $verdict[$rel . ' ' . $fn] = $ok;
            continue;
        }
        $verdict[$rel . ' ' . $fn] = strpos($before, 'retryLines(') !== false || strpos($before, '->resolve(') !== false;
    }
    $c->eq($verdict, ['includes/api/api_cron_debug.php act:retry_quote' => true, 'lib/KycService.php handleExisting' => true],
        'I5 retry_quote and handleExisting each reach their POST only after the resolver\'s verdict: handleExisting after resolve(); retry_quote\'s POST is 5.18.101\'s gate-off path, its gated block quoting through ->makeQuotation and answering every kind before it');
    $pq = [];
    foreach ($calls('#(?:KycService|self)::postQuote\(#') as [$rel, $fn, $before]) {
        $pq[$rel . ' ' . $fn] = strpos($before, 'retryLines(') !== false || strpos($before, '->resolve(') !== false
                              || strpos($before, "\$ug['lines']") !== false || strpos($before, "\$r['lines']") !== false;
    }
    // 5.18.102 D14 (rewritten on purpose, docs/71 §R.4.8): the shared implementation is postQuote's fifth caller
    $c->eq(array_keys($pq), ['lib/KycCrmSync.php afterCreate', 'lib/KycCrmSync.php ugQuote', 'lib/KycService.php handleNew',
                             'lib/KycService.php makeQuotation', 'lib/KycService.php ugQuoteNew'],
        'I5 postQuote() has five callers: 5.18.101\'s two (handleNew, afterCreate) where the gate is off, and 5.18.102\'s ugQuoteNew, ugQuote and makeQuotation (the shared implementation of retry_quote and Make the quotation)');
    $c->eq([$pq['lib/KycCrmSync.php ugQuote'] ?? null, $pq['lib/KycService.php makeQuotation'] ?? null], [true, true],
        'I5 … ugQuote and makeQuotation each post the lines retryLines() gave, after it');
    $hn = (string)file_get_contents($T . '/lib/KycService.php');
    $iRes = strpos($hn, '->resolve(self::saleFromPost($post, $connectivity), $this->store)');
    $iUq  = strpos($hn, '$ugQ = $this->ugQuoteNew(');
    $c->t($iRes !== false && $iUq !== false && $iRes < $iUq && substr_count($hn, '$this->ugQuoteNew(') === 1,
        'I5 … ugQuoteNew is called once, from handleNew, after the resolver\'s verdict on the same sale');
    $qs = (string)file_get_contents($T . '/lib/QuotationService.php');
    $ok = true;
    foreach (['sendLeadQuote', 'sendManualQuote'] as $f) {
        $a = strpos($qs, 'public function ' . $f . '(');
        $u = $a === false ? false : strpos($qs, '$this->unpricedLine($items)', $a);
        $q = $a === false ? false : strpos($qs, '$this->createCrmQuote(', $a);
        if ($a === false || $u === false || $q === false || $u > $q) $ok = false;
    }
    $c->t($ok, 'I5 … Send Quotation\'s two senders refuse an unpriced line before they reach createCrmQuote()');

    // I6 — 5.18.102 D14 (docs/71 §R.4, §R.9.1 I): the inventory of every UcrmQuoteLines construction site. The link judge is
    // a required parameter (a forgotten site is a TypeError, never a resolver that prices by name), every site passes one,
    // and each one passed is a UcrmQuoteLinks built by fromStore() or fromRecords() — directly, or through a variable
    // assigned only from one.
    $sig = [];
    exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('require ' . var_export($T . '/lib/UcrmQuoteLines.php', true) . ';
        $o = [];
        foreach ([["__construct", 2], ["forPage", 3]] as [$m, $i]) {
            $p = (new ReflectionMethod("UcrmQuoteLines", $m))->getParameters()[$i] ?? null;
            $t = $p ? $p->getType() : null;
            $o[$m] = $p ? [$p->getName(), $t ? $t->getName() : "", $t ? $t->allowsNull() : true, $p->isOptional()] : null;
        }
        echo json_encode($o);') . ' 2>&1', $sig, $src);
    $c->eq(json_decode(implode('', $sig), true), ['__construct' => ['links', 'UcrmQuoteLinks', false, false], 'forPage' => ['links', 'UcrmQuoteLinks', false, false]],
        'I6 the link judge is required: UcrmQuoteLines::__construct\'s third and forPage\'s fourth parameter are a UcrmQuoteLinks, neither nullable nor optional');
    $judgeOf = function (string $expr, string $src, int $depth = 0) use (&$judgeOf): bool {
        $expr = trim($expr);
        if (preg_match('#^UcrmQuoteLinks::from(Store|Records)\(#', $expr)) return true;
        if ($depth > 3 || !preg_match('#^\$[A-Za-z_]\w*(\[\'[A-Za-z_]\w*\'\])?$#', $expr)) return false;
        if (!preg_match_all('#' . preg_quote($expr, '#') . '\s*=(?![=>])\s*([^;]+);#', $src, $am)) return false;
        foreach ($am[1] as $rhs) if (!$judgeOf($rhs, $src, $depth + 1)) return false;
        return true;
    };
    $sites = []; $noJudge = [];
    foreach ($tree as $rel => $path) {
        if (substr($rel, -4) !== '.php' || strpos($rel, 'tests/') === 0) continue;
        $src = $code((string)file_get_contents($path));
        if (strpos($src, 'UcrmQuoteLines') === false) continue;
        if (!preg_match_all('#(new\s+UcrmQuoteLines|UcrmQuoteLines::forPage)\s*\(#', $src, $sm, PREG_OFFSET_CAPTURE)) continue;
        foreach ($sm[0] as $k => [$hit, $off]) {
            $isNew = strpos($sm[1][$k][0], 'new') === 0;
            $sites[$rel][$isNew ? 'new' : 'forPage'] = ($sites[$rel][$isNew ? 'new' : 'forPage'] ?? 0) + 1;
            // the argument list, split at its top-level commas
            $args = []; $d = 0; $cur = ''; $q = null;
            for ($i = $off + strlen($hit), $n = strlen($src); $i < $n; $i++) {
                $ch = $src[$i];
                if ($q !== null) { $cur .= $ch; if ($ch === '\\') { $cur .= $src[++$i] ?? ''; continue; } if ($ch === $q) $q = null; continue; }
                if ($ch === '"' || $ch === "'") { $q = $ch; $cur .= $ch; continue; }
                if ($ch === '(' || $ch === '[') $d++;
                if (($ch === ')' || $ch === ']') && $d === 0) { $args[] = $cur; break; }
                if ($ch === ')' || $ch === ']') $d--;
                if ($ch === ',' && $d === 0) { $args[] = $cur; $cur = ''; continue; }
                $cur .= $ch;
            }
            $links = $args[$isNew ? 2 : 3] ?? '';
            if (!$judgeOf($links, $src)) $noJudge[] = $rel . ' ' . ($isNew ? 'new' : 'forPage') . ' #' . ($k + 1) . ': ' . (trim($links) !== '' ? trim(preg_replace('/\s+/', ' ', $links)) : '(no such argument)');
        }
    }
    ksort($sites);
    $c->eq($sites, [
        'includes/post/post_sync.php' => ['new' => 1], 'lib/KycCrmSync.php' => ['new' => 1], 'lib/KycService.php' => ['new' => 3],
        'lib/QuotationService.php' => ['forPage' => 1, 'new' => 1], 'tabs/sales/hardware.php' => ['new' => 2, 'forPage' => 1],
        'tabs/sales/kyc_form.php' => ['forPage' => 1], 'tabs/sales/subscription_plans.php' => ['new' => 2, 'forPage' => 1],
    ], 'I6 the inventory: fourteen construction sites in seven files — the form, the submission (2) and Make the quotation, the cron retry, Send Quotation (2), the link save, the Plans and Hardware screens (3 each); none left in api_cron_debug.php (§R.4.8)');
    $c->eq($noJudge, [], 'I6 … and every one passes a link judge built by UcrmQuoteLinks::fromStore() or ::fromRecords()');
    $ctl = 'function f() { $a = UcrmQuoteLinks::fromStore($s, $t); $b = $a; $c2 = null; $x = new UcrmQuoteLines($k, $t, $b); }';
    $c->eq([$judgeOf('$b', $ctl), $judgeOf('$c2', $ctl), $judgeOf('null', $ctl), $judgeOf('$zz', $ctl)], [true, false, false, false],
        'I6 control: the judge check follows a variable to fromStore(), and refuses null, a variable assigned null, and one never assigned');

    // I6b — 5.18.102 D14 review (G3): SAFETY.md ships with the plugin; its 5.18.102 entry describes D14 (docs/71 §R.6) —
    // the link store, Make the quotation, --after-only, section L — and no longer the spelling rule it replaced
    $need = ['ucrm_quote_links', 'Make the quotation', '--after-only', 'L:'];
    $gone = ['spelled alike in its plugin name', 'nine sections A–I'];
    // the predicate: [the entry between '## 5.18.102' and '## 5.18.101' found, each $need in it, each $gone in it]
    $i6b = function (string $saf) use ($need, $gone): array {
        $sa = strpos($saf, '## 5.18.102'); $sb = strpos($saf, '## 5.18.101');
        $entry = ($sa !== false && $sb !== false && $sa < $sb) ? substr($saf, $sa, $sb - $sa) : '';
        $n = []; foreach ($need as $w) $n[$w] = strpos($entry, $w) !== false;
        $g = []; foreach ($gone as $w) $g[$w] = strpos($entry, $w) !== false;
        return [$entry !== '', $n, $g];
    };
    // 5.18.102 D14 review round 2 (FX-tests-2): the control, self-contained (no Git read). An entry holding the two replaced
    // texts exactly as 5.18.102's first cut spelled them (its operator step 3 and its test record, the dash an en dash,
    // U+2013), written out here and never built from $gone, so a mistyped needle cannot match it, and naming none of the
    // four: both halves of the check must be able to fire
    $firstCut = "## 5.18.102 (the first cut's two replaced texts, written out for the control)\n"
              . "3. Check the catalogue: each plan offered on the form spelled alike in its plugin name, exactly one uCRM Product and\n"
              . "- Tests: `tests/test_quote_from_ucrm.php` (new): 513 passed, 0 failed. That is 409 checks in nine sections A\u{2013}I, and\n"
              . "## 5.18.101\n";
    $c->eq($i6b($firstCut), [true, array_fill_keys($need, false), array_fill_keys($gone, true)],
        'I6b control: on an entry spelled as 5.18.102\'s first cut spelled it, the entry is found, names none of the four, and holds both replaced texts');
    [$found, $hasN, $hasG] = $i6b((string)@file_get_contents($T . '/SAFETY.md'));
    $c->eq([$found, $hasN, $hasG], [true, array_fill_keys($need, true), array_fill_keys($gone, false)],
        'I6b SAFETY.md\'s 5.18.102 entry describes D14 (§R.6), not the spelling rule: it names ucrm_quote_links, Make the quotation, --after-only and section L, and neither replaced text');

    // I7 — 5.18.102 D14 (docs/71 §R.1.5, E-5, F-6): the loader. lib/UcrmQuoteLinks.php requires no sibling file (its callers
    // load lib/SqliteStore.php and lib/QuoteOrganization.php themselves, so the deploy's stage-A reading never declares a
    // class twice); lib/UcrmQuoteLines.php requires it by its own path, so every caller of the resolver has the judge.
    $incl = function (string $file): array {
        $o = [];
        foreach (token_get_all((string)@file_get_contents($file)) as $tk) {
            if (is_array($tk) && in_array($tk[0], [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], true)) $o[] = $tk[1];
        }
        return $o;
    };
    $c->eq(count($incl($T . '/lib/UcrmQuoteLines.php')), 4,
        'I7 the control: the same token count finds lib/UcrmQuoteLines.php\'s four require_once lines');
    $c->eq([is_file($T . '/lib/UcrmQuoteLinks.php'), $incl($T . '/lib/UcrmQuoteLinks.php')], [true, []],
        'I7 lib/UcrmQuoteLinks.php holds no require, require_once, include or include_once');
    $c->eq(substr_count($code((string)file_get_contents($T . '/lib/UcrmQuoteLines.php')), "require_once __DIR__ . '/UcrmQuoteLinks.php'"), 1,
        'I7 lib/UcrmQuoteLines.php holds require_once __DIR__ . \'/UcrmQuoteLinks.php\' (F-6)');

    // I8 — 5.18.102 D14 (docs/71 §R.3.1 rule 8, F-5): kyc_form.php's kit loop chooses between the kit's own cell and the
    // Fiber cell by UcrmQuoteLinks::offeredKit — rule 8's "offered", one implementation — and compares no type itself. The
    // control: the plan loop beside it keeps its own Fiber test, and the same needles find it.
    $kf = $code((string)file_get_contents($T . '/tabs/sales/kyc_form.php'));
    $loop = function (string $head) use ($kf, $blockAfter): string { $at = strpos($kf, $head); return $at === false ? '' : $blockAfter($kf, $at); };
    $kit  = $loop('foreach ((array)($devices ?? []) as $_q102D)');
    $plan = $loop("foreach ((array)\$store->load('subscription_plans.json') as \$_q102Sp)");
    $typeTest = fn(string $b): bool => preg_match('#[\'"]fiber[\'"]#i', $b) === 1 || strpos($b, "['type']") !== false;
    $c->t($plan !== '' && $typeTest($plan), 'I8 the control: the plan loop is found, and its own Fiber test is seen by the needles');
    $c->eq([$kit !== '', substr_count($kit, 'UcrmQuoteLinks::offeredKit($_q102D)'), $typeTest($kit)], [true, 1, false],
        'I8 the kit loop calls UcrmQuoteLinks::offeredKit($_q102D) once and compares no type with "fiber" itself');
}];
