<?php
declare(strict_types=1);
/**
 * Section N of tests/test_quote_followup.php — the boundary (5.18.104, root docs/73 §H.2.3, §H.2.14 N; ruling R10): the
 * judged tree (--root) against 221c9dc, the 5.18.103 release commit, taken from Git by this checkout, never from a
 * working tree. The one merged declared list of every part — F, V, O and P — is tests/quote_followup/declared.php;
 * `wa_offers M` and `quote_agent_copy J` are reads of it, by slice.
 *
 * Measured: only the declared files change, none is removed, each declared new file is new and each declared changed
 * file existed; migrations/ is 221c9dc's plus exactly 089 (Part F's only migration), 090 (V's) and 091 (O's); every part's
 * held files byte for byte; CD_I_KEPT and test_quote_company's I3 unchanged, tools/set_config.php with them; every hunk of
 * every declared changed file carries its marker — so that taking them out gives back 221c9dc, file for file; the
 * manifest by its version line only; php -l on every new or changed PHP file, and the PHP 7.4 rules
 * (tests/quote_from_ucrm/I_boundary.php's QFU_I_RULES) on every added code line of the runtime files. TEST ONLY.
 */
require_once __DIR__ . '/declared.php';
require_once dirname(__DIR__) . '/quote_from_ucrm/I_boundary.php';   // QFU_I_RULES (its section is not run from here)
require_once dirname(__DIR__) . '/fixtures/r105_revert.php';          // 5.18.105 (root docs/74 §H.3.2): the layer, r105_less()

$QF_SECTIONS['N'] = ['title' => 'The boundary: the one merged declared list against 221c9dc, from Git (§H.2.14 N; ruling R10)', 'run' => function (QfCtx $ctx) {
    // 5.18.105 (root docs/74 §H.3.2), re-pointed on purpose, never weakened: N judges the judged tree with 5.18.105 taken out
    // against 994d660 (tests/fixtures/r105_revert.php, r105_less, built only from the judged root), so 5.18.104's boundary
    // reads 994d660's tree exactly as before; 5.18.105's own boundary is tests/test_log_mask.php D. N09 is the control on
    // the controls: the same checks on the judged tree itself must fail on 5.18.105.
    try { $T = r105_less($ctx->root, $ctx->tmp); $why105 = ''; } catch (\RuntimeException $e) { $T = ''; $why105 = $e->getMessage(); }
    $ctx->t($T !== '', 'N00 control: Git supplies ' . R105_BASE . ' (5.18.104), the tree 5.18.105 is taken out against — without it nothing below is measured: a failure, never a skip', $why105);
    if ($T === '') return;
    $w105 = (string)@file_get_contents($T . '/evo_webhook.php');
    $ctx->t(strpos($w105, "error_log('[evo_webhook] opt-out recorded for ' . \$phone") !== false && strpos($w105, 'opt-out recorded for conversation') === false
            && !is_file($T . '/tests/test_log_mask.php') && !is_dir($T . '/tests/log_mask'),
            'N00 … and the tree judged was built: its evo_webhook.php opt-out line reads ' . R105_BASE . '\'s form, and tests/test_log_mask.php is absent ('
            . r105_less_left($ctx->root, $ctx->tmp) . ' unmarked 5.18.105 hunk(s) left, for tests/test_log_mask.php D)');
    [$B, $why] = qf_n_base($ctx->tmp . '/base221');
    $ctx->t($B !== null, 'N00 control: Git supplies ' . QF_N_BASE . ', the 5.18.103 release — without it nothing below is measured: a failure, never a skip', $why);
    if ($B === null) return;
    $base = qf_n_walk($B);
    $tree = qf_n_walk($T);
    $ctx->t(count($base) > 1000 && isset($tree['lib/KycService.php'], $base['webhook.php']) && hash_file('sha256', $tree['lib/MailService.php']) === hash_file('sha256', $base['lib/MailService.php']),
            'N00 … both walks see the plugin (' . count($base) . ' and ' . count($tree) . ' files), and a held file reads as its own');
    $badRow = [];
    foreach (QF_DECLARED as $p => $row) {
        $parts = explode(',', $row[0]);
        if (array_diff($parts, ['F', 'V', 'O', 'P']) !== [] || !in_array($row[1], ['new', 'changed'], true) || ($row[1] === 'changed' && substr($p, -1) === '/')) $badRow[] = $p;
    }
    $ctx->eq($badRow, [], 'N00 every entry of the list names its parts (F, V, O, P) and its kind (new, changed; a directory only new)');

    $changed = []; $added = []; $removed = [];
    foreach ($tree as $rel => $p) {
        if (!isset($base[$rel])) { $added[] = $rel; continue; }
        if (hash_file('sha256', $p) !== hash_file('sha256', $base[$rel])) $changed[] = $rel;
    }
    foreach ($base as $rel => $p) if (!isset($tree[$rel])) $removed[] = $rel;
    $moved = array_merge($changed, $added);

    $ctx->run('N01 exactly the declared files change or are added, none is removed; each new one is new, each changed one existed', function () use ($ctx, $moved, $added, $removed, $tree, $base) {
        $undeclared = array_values(array_filter($moved, function ($r) { return qf_declared_entry($r) === null; }));
        $still = [];
        foreach (QF_DECLARED as $p => $row) {
            if (substr($p, -1) === '/') {
                if (array_filter(array_keys($tree), function ($r) use ($p) { return strpos($r, $p) === 0; }) === []) $still[] = $p;
            } elseif (!in_array($p, $moved, true)) $still[] = $p;
        }
        $kind = [];
        foreach ($moved as $r) {
            $e = qf_declared_entry($r);
            if ($e === null) continue;
            if (QF_DECLARED[$e][1] === 'new' && isset($base[$r])) $kind[] = $r . ' (declared new, but ' . QF_N_BASE . ' has it)';
            if (QF_DECLARED[$e][1] === 'changed' && !isset($base[$r])) $kind[] = $r . ' (declared changed, but ' . QF_N_BASE . ' has none)';
        }
        $ctx->eq(['not declared' => $undeclared, 'declared, not changed' => $still, 'removed' => $removed, 'of the wrong kind' => $kind],
                 ['not declared' => [], 'declared, not changed' => [], 'removed' => [], 'of the wrong kind' => []],
                 'N01 against ' . QF_N_BASE . ' the tree changes or adds exactly the ' . count(QF_DECLARED) . ' declared entries (' . count($moved) . ' files), and removes none');
        $ctx->t(count($added) > 100 && count($moved) > count($added), 'N01 control: the walk saw 5.18.104 (' . count($added) . ' new files, ' . (count($moved) - count($added)) . ' changed)');
    });

    $ctx->run('N02 migrations/ is 221c9dc\'s plus exactly 089, 090 and 091; 089 is Part F\'s only migration', function () use ($ctx, $T, $B) {
        $m = function (string $t): array { $o = []; foreach (glob($t . '/migrations/*') ?: [] as $f) $o[basename($f)] = hash_file('sha256', $f); ksort($o); return $o; };
        $mt = $m($T); $mb = $m($B);
        $extra = array_diff_key($mt, $mb);
        $ctx->eq([array_keys($extra), array_intersect_key($mt, $mb) === $mb, count($mb) > 80],
                 [array_values(QF_N_MIGRATIONS), true, true], 'N02 the three new migrations, and ' . QF_N_BASE . '\'s ' . count($mb) . ' byte for byte');
        $own = [];
        foreach (array_keys($extra) as $f) $own[$f] = QF_DECLARED['migrations/' . $f][0] ?? null;
        $ctx->eq($own, ['089_quote_followups.sql' => 'F', '090_wa_voice.sql' => 'V', '091_wa_offers.sql' => 'O'], 'N02 each is its part\'s one migration (C2): 089 Part F\'s, 090 Part V\'s, 091 Part O\'s; Part P adds none');
        $sql = array_values(array_filter(array_keys($mt), function ($f) { return substr($f, -4) === '.sql'; }));
        $ctx->eq(end($sql), '091_wa_offers.sql', 'N02 … and 091 is the last migration');
    });

    $ctx->run('N03 every part\'s held files, byte for byte, and none of them declared', function () use ($ctx, $T, $B) {
        $bad = []; $decl = []; $n = 0;
        foreach (QF_HELD as $part => $list) foreach ($list as $rel) {
            $n++;
            if (!is_file($T . '/' . $rel) || !is_file($B . '/' . $rel) || hash_file('sha256', $T . '/' . $rel) !== hash_file('sha256', $B . '/' . $rel)) $bad[] = "{$part}: {$rel}";
            if (qf_declared_entry($rel) !== null) $decl[] = "{$part}: {$rel}";
        }
        $ctx->eq(['changed' => $bad, 'declared' => $decl], ['changed' => [], 'declared' => []], 'N03 the ' . $n . ' held entries of Parts F, V, O and P are ' . QF_N_BASE . '\'s');
    });

    $ctx->run('N04 CD_I_KEPT and test_quote_company\'s I3 unchanged, tools/set_config.php with them (C1)', function () use ($ctx, $T, $B) {
        $kept = function (string $root): ?string {
            $s = (string)@file_get_contents($root . '/tests/catalogue_direct/I_boundary.php');
            return preg_match('/const CD_I_KEPT = \[.*?\];/s', $s, $m) ? $m[0] : null;
        };
        $i3 = function (string $root): ?string {
            $s = (string)@file_get_contents($root . '/tests/test_quote_company.php');
            $a = strpos($s, '// I3 — tools/set_config.php'); $b = strpos($s, '// I4 — the delta against');
            return $a !== false && $b !== false && $b > $a ? substr($s, $a, $b - $a) : null;
        };
        $ctx->t($kept($B) !== null && strpos((string)$kept($B), "'webhook.php'") !== false && $i3($B) !== null, 'N04 control: both read on ' . QF_N_BASE . ' (CD_I_KEPT names webhook.php)');
        $ctx->eq([$kept($T) === $kept($B), $i3($T) === $i3($B), hash_file('sha256', $T . '/tools/set_config.php') === hash_file('sha256', $B . '/tools/set_config.php')],
                 [true, true, true], 'N04 CD_I_KEPT\'s text, I3\'s text and tools/set_config.php are ' . QF_N_BASE . '\'s');
    });

    $ctx->run('N05 every hunk of every declared changed file carries its marker; taken out, the tree is 221c9dc\'s, file for file', function () use ($ctx, $T, $B, $changed, $base) {
        $unmarked = []; $hunks = 0;
        foreach ($changed as $rel) {
            if ($rel === 'manifest.json' || qf_declared_entry($rel) === null) continue;
            foreach (qf_n_hunks($B . '/' . $rel, $T . '/' . $rel) as [$os, $ol, $ns, $nl, $plus, $minus, $marked]) {
                $hunks++;
                if (!$marked) $unmarked[] = $rel . ' +' . $ns . ',' . $nl . ': ' . mb_substr(trim((string)($plus[0] ?? '(a deletion)')), 0, 100);
            }
        }
        $ctx->eq($unmarked, [], 'N05 each of the ' . $hunks . ' hunks carries "5.18.104 … root docs/73 §H" in itself or in the 30 lines above it, or only closes a block');
        $D = $ctx->dir('n-revert');
        $left = qf_n_revert_tree($T, $B, $D);
        $back = qf_n_walk($D); $diff = [];
        foreach ($back as $r => $p) if (!isset($base[$r]) || hash_file('sha256', $p) !== hash_file('sha256', $base[$r])) $diff[] = $r;
        foreach ($base as $r => $p) if (!isset($back[$r])) $diff[] = $r . ' (missing)';
        $ctx->eq([$left, $diff, count($back)], [0, [], count($base)], 'N05 the judged tree with 5.18.104\'s declared files and hunks taken out is ' . QF_N_BASE . '\'s, file for file (' . count($base) . ')');
        $ctx->t(qf_n_hunks($B . '/cron/master.php', $T . '/cron/master.php') !== [] && $hunks > 100, 'N05 control: the hunks were read (' . $hunks . ', cron/master.php\'s among them)');
    });

    $ctx->run('N06 manifest.json is 221c9dc\'s with one line changed, its version, 5.18.103 → 5.18.104', function () use ($ctx, $T, $B) {
        $mb = str_replace('    "version": "5.18.103",', '    "version": "5.18.104",', (string)file_get_contents($B . '/manifest.json'), $mn);
        $ctx->eq([$mn, $mb === (string)@file_get_contents($T . '/manifest.json')], [1, true], 'N06 the version line alone');
    });

    $ctx->run('N07 php -l on every new or changed PHP file; the PHP 7.4 rules on every added code line of the runtime files', function () use ($ctx, $T, $B, $moved, $base) {
        $lint = []; $bad = []; $linted = 0; $ruled = 0;
        foreach ($moved as $rel) {
            if (substr($rel, -4) !== '.php') continue;
            $linted++;
            $lo = []; exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($T . '/' . $rel) . ' 2>&1', $lo, $lrc);
            if ($lrc !== 0) $lint[] = $rel;
            if (strpos($rel, 'tests/') === 0) continue;
            $ruled++;
            $cb = $ctx->tmp . '/n07_base.php'; $cn = $ctx->tmp . '/n07_new.php';
            file_put_contents($cb, isset($base[$rel]) ? qf_n_code((string)file_get_contents($B . '/' . $rel)) : '');
            file_put_contents($cn, qf_n_code((string)file_get_contents($T . '/' . $rel)));
            $d = []; exec('diff ' . escapeshellarg($cb) . ' ' . escapeshellarg($cn), $d);
            foreach ($d as $l) {
                if (strpos($l, '> ') !== 0) continue;
                $l = substr($l, 2);
                foreach (QFU_I_RULES as $what => $re) {
                    if (preg_match($re, $l) && !($what === 'a named argument' && preg_match('/\?\s*[^:]*:/', $l))) { $bad[] = "{$rel}: {$what}: " . mb_substr(trim($l), 0, 120); break; }
                }
            }
        }
        $ctx->eq($lint, [], 'N07 php -l passes on every one of the ' . $linted . ' new or changed PHP files, under PHP ' . PHP_VERSION);
        $ctx->eq($bad, [], 'N07 no added code line of the ' . $ruled . ' runtime PHP files uses ' . implode(', ', array_keys(QFU_I_RULES)));
        $probe = qf_n_code("<?php\n\$x = match (\$a) { 1 => 2 }; // match (\n\$y = 'match (';\n");
        $hit = array_keys(array_filter(QFU_I_RULES, function ($re) use ($probe) { return preg_match($re, $probe) === 1; }));
        $ctx->eq([$hit, substr_count($probe, 'match (')], [['match ('], 1], 'N07 control: the rules find match ( in code, and not in a comment or a string');
    });

    $ctx->run('N08 every part has its slice: the parts wa_offers M and quote_agent_copy J read', function () use ($ctx) {
        $n = [];
        foreach (['F', 'V', 'O', 'P'] as $p) $n[$p] = count(qf_declared($p));
        $ctx->t(min($n) > 3 && array_sum($n) >= count(QF_DECLARED), 'N08 F ' . $n['F'] . ', V ' . $n['V'] . ', O ' . $n['O'] . ', P ' . $n['P'] . ' entries');
        $ctx->eq([isset(qf_declared('O')['public.php'], qf_declared('F')['public.php'], qf_declared('O')['includes/navigation.php'], qf_declared('P')['webhook.php']), isset(qf_declared('F')['webhook.php'])],
                 [true, false], 'N08 public.php and includes/navigation.php in both F\'s and O\'s slices (one entry, both markers); webhook.php declared once, as Part P\'s');
    });

    // 5.18.105 (root docs/74 §H.3.4, the control on the controls): N's own checks read on the judged tree itself, 5.18.105
    // left in (r105_less replaced by the root), must fail on 5.18.105's files and hunks — so the layer is what makes N pass,
    // and N still sees what it should.
    $ctx->run('N09 control on the controls: on the judged tree itself, 5.18.105 left in, N01, N03, N05 and N06 each fail on 5.18.105', function () use ($ctx, $B, $base) {
        // 5.18.106 (root docs/75 §J): the judged tree with 5.18.106 taken out (r106_less), so 5.18.105 is all that is left in
        $R = r106_less($ctx->root, $ctx->tmp);
        $held = [];
        foreach (QF_HELD as $list) foreach ($list as $rel) $held[$rel] = true;
        $fourteen = array_values(array_filter(array_keys(R105_DECLARED), function ($r) use ($held) {
            return R105_DECLARED[$r][0] === 'changed' && substr($r, -4) === '.php' && strpos($r, 'tests/') !== 0 && qf_declared_entry($r) === null && !isset($held[$r]);
        }));
        $undeclared = []; $heldMoved = []; $unmarked = [];
        foreach (qf_n_walk($R) as $rel => $p) {
            if (isset($base[$rel]) && hash_file('sha256', $p) === hash_file('sha256', $base[$rel])) continue;
            if (isset($held[$rel])) $heldMoved[] = $rel;
            if (qf_declared_entry($rel) === null) { $undeclared[] = $rel; continue; }
            if ($rel === 'manifest.json' || !isset($base[$rel])) continue;
            foreach (qf_n_hunks($B . '/' . $rel, $p) as $h) if (!$h[6]) $unmarked[$rel] = ($unmarked[$rel] ?? 0) + 1;
        }
        $ctx->eq([count($fourteen), array_values(array_diff($fourteen, $undeclared)), array_values(array_filter($undeclared, function ($r) { return r105_declared_entry($r) === null; }))],
                 [14, [], []], 'N09 N01 on it: the 14 runtime files 5.18.104 neither declares nor holds are undeclared, and every undeclared file is 5.18.105\'s');
        sort($heldMoved);
        $ctx->eq($heldMoved, ['cron_evo_sync.php', 'cron_quote_wa.php', 'includes/post/post_kyc.php'], 'N09 N03 on it: the three held files 5.18.105 changes are not ' . QF_N_BASE . '\'s');
        $ctx->eq([isset($unmarked['webhook.php'], $unmarked['evo_webhook.php'], $unmarked['lib/QuotationService.php']),
                  array_values(array_filter(array_keys($unmarked), function ($r) { return r105_declared_entry($r) === null; }))],
                 [true, []], 'N09 N05 on it: 5.18.105\'s hunks read as unmarked (webhook.php\'s, evo_webhook.php\'s and lib/QuotationService.php\'s among them), and only in its own files');
        $mb = str_replace('    "version": "5.18.103",', '    "version": "5.18.104",', (string)file_get_contents($B . '/manifest.json'));
        $ctx->t($mb !== (string)@file_get_contents($R . '/manifest.json'), 'N09 N06 on it: the manifest is not ' . QF_N_BASE . '\'s with 5.18.104\'s version line');
    });
}];
