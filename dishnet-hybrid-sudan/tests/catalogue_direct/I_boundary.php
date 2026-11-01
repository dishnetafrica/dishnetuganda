<?php
declare(strict_types=1);
/**
 * Section I — 5.18.103 (root docs/72 §G.12): the release boundary — the judged tree (--root) against 1a0ea5f, the 5.18.102
 * release commit, taken from Git by this checkout, never from the working tree.
 *
 * Measured: only the declared files change, none is removed; no migration; the manifest by its version line only; the
 * files §G.12 keeps are 1a0ea5f's byte for byte; every hunk of every runtime file carries its 5.18.103 marker (in the hunk,
 * in the 30 lines above it, or a hunk that only closes a block); php -l on every new or changed PHP file, and the PHP 7.4
 * rules (5.18.102's own list, tests/quote_from_ucrm/I_boundary.php) on every added code line of the runtime files. The walk
 * leaves out what a release does not ship and a weakened copy does not carry: docs/, prototype/,
 * dishnet-mikrotik-control-plane/, data/, .git and scripts/. TEST ONLY.
 */
require_once dirname(__DIR__) . '/quote_from_ucrm/I_boundary.php';   // QFU_I_RULES, QFU_I_SKIP (its section is not run from here)

const CD_I_BASE = '1a0ea5f';
/** Runtime files 5.18.103 adds or changes (§G.12). */
const CD_I_RUNTIME = [
    'includes/api/api_cron_debug.php', 'includes/api/api_products_admin.php', 'includes/navigation.php',
    'includes/post/post_leads.php', 'includes/post/post_sync.php', 'lib/KycCrmSync.php', 'lib/KycService.php',
    'lib/QuotationService.php', 'lib/UcrmCatalogue.php', 'lib/UcrmQuoteLines.php', 'public.php',
    'tabs/sales/applications.php', 'tabs/sales/hardware.php', 'tabs/sales/kyc_form.php', 'tabs/sales/product_mapping.php',
    'tabs/sales/send_quote.php', 'tabs/sales/subscription_plans.php', 'tabs/sales/ucrm_catalogue.php',
];
const CD_I_ADDED_RUNTIME = ['lib/UcrmCatalogue.php', 'tabs/sales/ucrm_catalogue.php'];
const CD_I_OTHER = ['SAFETY.md', 'manifest.json'];
/** Tests 5.18.103 adds or rewrites on purpose (§G.13); tests/catalogue_direct/ is this test's own directory. */
const CD_I_TESTS = [
    'tests/test_catalogue_direct.php',
    'tests/test_bank_boundaries.php', 'tests/test_cashbook_physical_list.php', 'tests/test_distributor_apply.php',
    // the Cashbook pages held to 5.18.94's bytes: the uCRM catalogue's menu entry taken out and counted
    'tests/test_cash_in_hand_p3b_south_sudan.php',
    'tests/test_distributor_link_ucrm.php', 'tests/test_distributor_notify.php', 'tests/test_distributor_registry.php',
    'tests/test_distributor_territory.php', 'tests/test_quote_company.php',
    'tests/quote_from_ucrm/I_boundary.php', 'tests/fixtures/r103_revert.php',
    // 5.18.102's weakened copies re-anchored where 5.18.103 moved their code (the same fault each), and ML79's refusal copy,
    // which 5.18.102's round 11 had already left unplantable
    'tests/quote_from_ucrm/mutants.php', 'tests/quote_from_ucrm/mutants_C.php', 'tests/quote_from_ucrm/mutants_F.php',
    'tests/quote_from_ucrm/mutants_G.php', 'tests/quote_from_ucrm/mutants_L.php',
];
/** §G.12 "Byte for byte". */
const CD_I_KEPT = [
    'webhook.php', 'cron_quote_wa.php', 'api/index.php', 'lib/QuoteOrganization.php', 'lib/PublicPriceFeed.php', 'prices.php',
    'shop.php', 'tools/set_config.php', 'lib/UcrmQuoteLinks.php', 'lib/QuoteTaxLine.php', 'lib/TenantProfile.php',
    'includes/post/post_kyc.php', 'includes/api/api_retailer.php', 'retailer/index.html', 'cron/kyc_crm_sync.php',
];

$CD_SECTIONS['I'] = ['title' => 'the boundary: the declared files against 1a0ea5f, from Git', 'run' => function (QfuCtx $c) {
    $checkout = dirname(__DIR__, 2);
    $T = $c->root;
    $B = $c->dir('base-1a0ea5f');
    $top = trim((string)shell_exec('git -C ' . escapeshellarg($checkout) . ' rev-parse --show-toplevel 2>/dev/null'));
    $o = []; exec('git -C ' . escapeshellarg($top !== '' ? $top : $checkout) . ' archive -o ' . escapeshellarg($B . '.tar') . ' '
        . escapeshellarg(CD_I_BASE . ':dishnet-hybrid-sudan') . ' 2>&1 && tar -xf ' . escapeshellarg($B . '.tar') . ' -C ' . escapeshellarg($B) . ' 2>&1', $o, $rc);
    $ver = (string)(json_decode((string)@file_get_contents($B . '/manifest.json'), true)['information']['version'] ?? '');
    $c->t($rc === 0 && $ver === '5.18.102', 'I0 the control: Git supplies ' . CD_I_BASE . ', the 5.18.102 release — without it nothing below is measured: a failure, never a skip',
        json_encode([$rc, $ver, $o]));
    if ($rc !== 0 || $ver !== '5.18.102') return;

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
    $own = function (string $r): bool { return strpos($r, 'tests/catalogue_direct/') === 0; };
    $all = array_values(array_filter(array_merge($changed, $added), function ($r) use ($own) { return !$own($r); }));
    sort($all);
    $want = array_merge(CD_I_RUNTIME, CD_I_OTHER, CD_I_TESTS);
    sort($want);

    // I1 — exactly the declared files
    $c->eq(['not declared' => array_values(array_diff($all, $want)), 'declared, not changed' => array_values(array_diff($want, $all)), 'removed' => $removed],
        ['not declared' => [], 'declared, not changed' => [], 'removed' => []],
        'I1 against ' . CD_I_BASE . ' the tree changes or adds exactly the ' . count($want) . ' declared files (and this test\'s own directory), and removes none');
    $c->eq(array_values(array_filter($added, function ($r) use ($own) { return !$own($r); })),
        ['lib/UcrmCatalogue.php', 'tabs/sales/ucrm_catalogue.php', 'tests/fixtures/r103_revert.php', 'tests/test_catalogue_direct.php'],
        'I1 … of which four are new outside this test\'s directory: the store, the screen, this test and the revert fixture the older boundaries read through');

    // I2 — no migration; the manifest by its version line; the kept files
    $mig = function (string $t): array { $o = []; foreach (glob($t . '/migrations/*') ?: [] as $f) $o[basename($f)] = hash_file('sha256', $f); ksort($o); return $o; };
    $c->t($mig($T) === $mig($B) && count($mig($B)) > 50, 'I2 migrations/ is ' . CD_I_BASE . '\'s, file for file (' . count($mig($B)) . ' files): no migration');
    $mb = str_replace('    "version": "5.18.102",', '    "version": "5.18.103",', (string)file_get_contents($B . '/manifest.json'), $mn);
    $c->eq([$mn, $mb === (string)@file_get_contents($T . '/manifest.json')], [1, true], 'I2 manifest.json is ' . CD_I_BASE . '\'s with one line changed, its version, 5.18.102 → 5.18.103');
    $kept = [];
    foreach (CD_I_KEPT as $rel) if (!is_file($T . '/' . $rel) || !is_file($B . '/' . $rel) || hash_file('sha256', $T . '/' . $rel) !== hash_file('sha256', $B . '/' . $rel)) $kept[] = $rel;
    $c->eq($kept, [], 'I2 the ' . count(CD_I_KEPT) . ' files §G.12 keeps are ' . CD_I_BASE . '\'s, byte for byte (webhook.php, api/index.php, QuoteOrganization.php, set_config.php, UcrmQuoteLinks.php …)');

    // I3 — every hunk of every runtime file names 5.18.103
    $unmarked = []; $hunkCount = 0;
    foreach (CD_I_RUNTIME as $rel) {
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
            $closer = $hunkLines !== [] && array_filter($hunkLines, function ($x) { return !preg_match('/^\s*(?:<\?php\s+(?:endif|else|endforeach);\s*\?>|[})\];,]+)\s*$/', $x); }) === [];
            if (strpos($span, '5.18.103') === false && !$closer) $unmarked[] = $rel . ' +' . $start . ',' . $len . ': ' . mb_substr(trim($nl[max(0, $start - 1)] ?? ''), 0, 100);
        }
    }
    $c->eq($unmarked, [], 'I3 every one of the ' . $hunkCount . ' hunks in the ' . count(CD_I_RUNTIME) . ' runtime files carries its 5.18.103 marker');

    // I4 — php -l on every new or changed PHP file; the PHP 7.4 rules on every added code line of the runtime files
    $code = function (string $src): string {
        $o = '';
        foreach (token_get_all($src) as $tk) {
            if (is_array($tk) && in_array($tk[0], [T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML], true)) { $o .= str_repeat("\n", substr_count($tk[1], "\n")); continue; }
            $o .= is_array($tk) ? $tk[1] : $tk;
        }
        return $o;
    };
    $lintFail = []; $bad = []; $linted = 0;
    foreach (array_merge($all, array_values(array_filter(array_keys($tree), $own))) as $rel) {
        if (substr($rel, -4) !== '.php') continue;
        $linted++;
        $lo = []; exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($T . '/' . $rel) . ' 2>&1', $lo, $lrc);
        if ($lrc !== 0) $lintFail[] = $rel;
        if (!in_array($rel, CD_I_RUNTIME, true)) continue;
        $cb = $c->tmp . '/cd_i4_base.php'; $cn = $c->tmp . '/cd_i4_new.php';
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
    $c->eq($lintFail, [], 'I4 php -l passes on every one of the ' . $linted . ' new or changed PHP files, under PHP ' . PHP_VERSION);
    $c->eq($bad, [], 'I4 no added code line of the runtime files uses ' . implode(', ', array_keys(QFU_I_RULES)));

    // I5 — the new store loads no sibling at file scope; every UcrmCatalogue read where a store is opened passes the gate.
    // Read by PHP's own tokenizer, the brace depth counted: a recursive regex over the whole file exhausted PCRE's JIT
    // stack, preg_replace answered null, and null read as "no require" — the check was vacuous until MI6 caught it.
    $fileScope = function (string $s): array {
        $d = 0; $out = [];
        foreach (token_get_all($s) as $t) {
            if (is_string($t)) { if ($t === '{') $d++; elseif ($t === '}') $d--; continue; }
            if ($t[0] === T_CURLY_OPEN || $t[0] === T_DOLLAR_OPEN_CURLY_BRACES) { $d++; continue; }
            if ($d === 0 && in_array($t[0], [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE, T_CLASS], true)) $out[] = token_name($t[0]);
        }
        return $out;
    };
    $c->eq($fileScope("<?php\nrequire_once 'a.php';\nfinal class A { public function f(): void { require 'b.php'; \$x = \"{\$y}\"; } }\ninclude 'c.php';\n"),
        ['T_REQUIRE_ONCE', 'T_CLASS', 'T_INCLUDE'], 'I5 the control: the reader sees a require and an include at file scope, and not the one inside a method');
    $src = (string)@file_get_contents($T . '/lib/UcrmCatalogue.php');
    $c->eq([$fileScope($src), substr_count($src, '$store->load(')], [['T_CLASS'], 1],
        'I5 lib/UcrmCatalogue.php requires nothing at file scope (its one class seen there), and reads its table in one place (fromStore, behind the gate)');
}];
