<?php
declare(strict_types=1);
/**
 * Section E of tests/test_log_mask.php — 5.18.105 (root docs/74 §H.3.4 E, §H.1.0 E): the logging rule, scoped to
 * changed lines (gap 3). TEST ONLY. E has its own reader (tests/log_mask/reader.php, modelled on qm_log_statements;
 * tests/quote_followup/M_logging.php is not edited). It judges phone numbers only, by the owner's ruling: names, ids,
 * `client #` labels and amounts are not flagged.
 *
 *   E1  5.18.105's own added lines only — the marked hunks of every declared runtime PHP file, read against 994d660 from
 *       Git: no log statement names a phone-carrying expression on them, except as the whole argument of
 *       PluginLog::tail( or PluginLog::mask( (a (string) cast allowed). Its controls plant, in a copy of a declared file,
 *       inside LN-02's hunk: error_log('x ' . $phone) is found; PluginLog::tail($phone) passes; a written key
 *       'phone' => PluginLog::tail((string)$phone) passes; $c['phone'] is found.
 *   E2  the rest of the plugin, as a ratchet: every log statement in runtime PHP (outside tests/, docs/ and Domain B) that
 *       names such an expression is on E2's list (tests/log_mask/e2_allow.php, read from the judged root), keyed by file
 *       and fixed words; an unlisted statement fails, and an entry that matches no statement, or more than one, or one
 *       that names no such expression, fails — no entry goes stale. E1's exception holds here too.
 *   E3  the runtime half: A's assertion 3 on every drive; and here, the exception E1 and E2 grant holds at run time —
 *       PluginLog::tail() and PluginLog::mask() of the judged root leave no form of a fixture number and no run of nine
 *       digits (so a tail() or mask() stubbed to return its input fails E as well as A).
 *
 * The weakened copies are tests/log_mask/mutants_E.php. Every number is fictitious (+2567000001xx).
 */
require_once __DIR__ . '/reader.php';

/**
 * E1 for one file: the phone-carrying expressions named by log statements on the added lines of $new's marked hunks
 * against $old (all hunks when $markedOnly is false); [violations, added line count].
 */
function lm_e1_file(string $old, string $new, string $rel, bool $markedOnly = true): array
{
    $added = [];
    foreach (r105_hunks($old, $new) as [$os, $ol, $ns, $nl, , , $marked]) {
        if ($markedOnly && !$marked) continue;
        for ($l = $ns; $l < $ns + $nl; $l++) $added[$l] = true;
    }
    $bad = [];
    foreach (lm_e_statements((string)@file_get_contents($new), $rel) as $s) {
        foreach ($s['hits'] as [$e, $l]) if (isset($added[$l])) $bad[] = "{$rel}:{$l} {$e} ({$s['logger']})";
    }
    return [$bad, count($added)];
}

/** The declared runtime PHP files 5.18.105 changes (R105_DECLARED, outside tests/). */
function lm_e_runtime_changed(): array
{
    $out = [];
    foreach (R105_DECLARED as $rel => [$kind]) {
        if ($kind === 'changed' && substr($rel, -4) === '.php' && strpos($rel, 'tests/') !== 0) $out[] = $rel;
    }
    return $out;
}

$LM_SECTIONS['E'] = ['title' => 'The logging rule: E1 on 5.18.105\'s added lines, E2 the ratchet, E3 the runtime half (§H.3.4 E)', 'run' => function (QfCtx $ctx) {
    $ctx->run('E1 5.18.105\'s added lines: no log statement names a phone-carrying expression, outside tail() and mask()', function () use ($ctx) {
        [$B, $why] = r105_base($ctx->dir('r105-base'));
        $ctx->t($B !== null, 'E1 control: Git supplies ' . R105_BASE . ' — without it nothing is measured: a failure, never a skip', (string)$why);
        if ($B === null) return;
        $bad = []; $added = 0; $files = lm_e_runtime_changed();
        foreach ($files as $rel) {
            if (!is_file($B . '/' . $rel) || !is_file($ctx->root . '/' . $rel)) { $bad[] = $rel . ' (not in both trees)'; continue; }
            [$b, $n] = lm_e1_file($B . '/' . $rel, $ctx->root . '/' . $rel, $rel);
            $bad = array_merge($bad, $b); $added += $n;
        }
        $ctx->eq($bad, [], 'E1 no log statement on 5.18.105\'s added lines (' . count($files) . ' runtime files) names a phone-carrying expression outside PluginLog::tail()/mask()');
        $ctx->t($added >= 70, 'E1 control: the added lines were read (' . $added . ': at least the 35 lines and their markers)');

        // the design's four controls, planted in a copy of a declared file, inside LN-02's hunk (the marker one line above)
        $rel = LM_LINES['LN-02']['file'];
        $src = (string)file_get_contents($ctx->root . '/' . $rel);
        $anchor = LM_LINES['LN-02']['new'];
        $ctx->eq(substr_count($src, $anchor), 1, 'E1 control: LN-02\'s line is found once in ' . $rel . ', to plant beside');
        $plant = function (string $line) use ($ctx, $B, $rel, $src, $anchor): array {
            $d = $ctx->dir('e1-plant');
            @mkdir(dirname($d . '/' . $rel), 0777, true);
            $made = 0;
            file_put_contents($d . '/' . $rel, str_replace($anchor, $anchor . "\n" . $line, $src, $made));
            if ($made !== 1) return ['(the plant was not made)'];
            return lm_e1_file($B . '/' . $rel, $d . '/' . $rel, $rel)[0];
        };
        $f = $plant("        error_log('x ' . \$phone);");
        $ctx->t(count($f) === 1 && strpos($f[0], '$phone') !== false, 'E1 control: a planted error_log(\'x \' . $phone) in a 5.18.105 hunk is found', implode(' | ', $f));
        $ctx->eq($plant("        error_log('x ' . PluginLog::tail(\$phone));"), [], 'E1 control: PluginLog::tail($phone) passes');
        $ctx->eq($plant("        whLog('t', 'm', ['phone' => PluginLog::tail((string)\$phone)]);"), [], 'E1 control: a planted \'phone\' => PluginLog::tail((string)$phone) passes (a written key is not a read)');
        $f = $plant("        error_log('x ' . \$c['phone']);");
        $ctx->t(count($f) === 1 && strpos($f[0], "['phone']") !== false, 'E1 control: a planted $c[\'phone\'] is found', implode(' | ', $f));
        $f = $plant("        whLog('t', 'm', ['tech_phone' => \$techPhone, 'x' => PluginLog::tail(\$phone . 'x')]);");
        $ctx->t(count($f) === 2, 'E1 control: \'tech_phone\' => $techPhone is found by $techPhone, and tail() of more than one variable is no exception', implode(' | ', $f));
    });

    $ctx->run('E2 every log statement naming a phone-carrying expression is on E2\'s list; every entry matches exactly one', function () use ($ctx) {
        $file = $ctx->root . '/tests/log_mask/e2_allow.php';
        $list = is_file($file) ? include $file : null;
        $ctx->t(is_array($list) && count($list) >= 20, 'E2 control: E2\'s list is read from the judged root (' . (is_array($list) ? count($list) : 0) . ' entries)');
        if (!is_array($list)) return;
        $byFile = [];
        foreach (r105_walk($ctx->root) as $rel => $abs) {
            if (substr($rel, -4) !== '.php' || strpos($rel, 'tests/') === 0) continue;
            $byFile[$rel] = lm_e_statements((string)file_get_contents($abs), $rel);
        }
        $nStmt = array_sum(array_map('count', $byFile));
        $unlisted = []; $hits = 0;
        foreach ($byFile as $rel => $stmts) {
            foreach ($stmts as $s) {
                if ($s['hits'] === []) continue;
                $hits++;
                $m = array_filter($list, function ($e) use ($rel, $s) { return ($e['file'] ?? '') === $rel && ($e['fixed'] ?? null) === $s['fixed']; });
                if (count($m) !== 1) {
                    $unlisted[] = $rel . ':' . $s['line'] . ' ' . $s['logger'] . ' ' . implode(' ', array_unique(array_column($s['hits'], 0)))
                        . ' fixed ' . json_encode($s['fixed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . (count($m) > 1 ? ' (matched ' . count($m) . ' entries)' : '');
                }
            }
        }
        $stale = [];
        foreach ($list as $e) {
            $rel = (string)($e['file'] ?? '');
            $m = array_values(array_filter($byFile[$rel] ?? [], function ($s) use ($e) { return $s['fixed'] === ($e['fixed'] ?? null); }));
            if (count($m) !== 1 || $m[0]['hits'] === [] || trim((string)($e['reason'] ?? '')) === '') {
                $stale[] = $rel . ' (994d660:' . ($e['line'] ?? '?') . ', ' . ($e['k'] ?? '?') . ') matches ' . count($m) . ' statement(s)'
                    . (count($m) === 1 && $m[0]['hits'] === [] ? ', which names no phone' : '') . (trim((string)($e['reason'] ?? '')) === '' ? ', no reason' : '');
            }
        }
        $ctx->eq($unlisted, [], 'E2 every log statement in runtime PHP that names a phone-carrying expression is on the list, by file and fixed words');
        $ctx->eq($stale, [], 'E2 every entry of the list matches exactly one log statement in its file, which names such an expression, and carries its reason');
        $ctx->t($nStmt > 500 && count($byFile) > 300 && $hits >= count($list), 'E2 control: the reader read the plugin (' . count($byFile) . ' runtime PHP files, '
            . $nStmt . ' log statements, ' . $hits . ' naming a phone)');
        // the reader's own controls, on text: a hit, the exception, a written key, echo only in the CLI jobs
        $probe = "<?php\nerror_log('a ' . \$custPhone);\nerror_log('b ' . PluginLog::mask((string)\$phone));\n\$x->ilog('c ' . \$phone);\nfunction olog(\$phone) {}\necho \"d {\$phone}\\n\";\n";
        $inCron = lm_e_statements($probe, 'cron_probe.php');
        $inLib = lm_e_statements($probe, 'lib/Probe.php');
        $ctx->eq([array_map(function ($s) { return [$s['logger'], array_column($s['hits'], 0)]; }, $inCron), count($inLib)],
                 [[['error_log', ['$custPhone']], ['error_log', []], ['echo', ['$phone']]], 2],
                 'E2 control: the reader finds $custPhone, keeps mask((string)$phone) as the exception, skips a method and a definition, and reads echo in a CLI job only');
    });

    $ctx->run('E3 tail() the exception E1 and E2 grant holds at run time: PluginLog::tail() leaves no form of the number', function () use ($ctx) {
        lm_lib($ctx, 'PluginLog');
        $bad = [];
        foreach ([31, 57, 99] as $xx) foreach (lm_forms(lm_num($xx)) as $f) {
            $o = method_exists('PluginLog', 'tail') ? PluginLog::tail($f) : $f;
            if (lm_number_hits($o, [lm_num($xx)]) !== [] || lm_long_runs($o) !== []) $bad[] = $f . ' → ' . $o;
        }
        $ctx->eq($bad, [], 'E3 tail() of every form of three fixture numbers carries no form of the number and no run of nine digits');
    });
    $ctx->run('E3 mask() the exception E1 and E2 grant holds at run time: PluginLog::mask() leaves no form of the number', function () use ($ctx) {
        lm_lib($ctx, 'PluginLog');
        $bad = [];
        foreach ([31, 57, 99] as $xx) foreach (lm_forms(lm_num($xx)) as $f) {
            $o = PluginLog::mask('Syncing ' . $f . ' (' . substr(lm_forms(lm_num($xx))[1], 0) . '@s.whatsapp.net)... (test)');
            if (lm_number_hits($o, [lm_num($xx)]) !== [] || lm_long_runs($o) !== []) $bad[] = $f . ' → ' . $o;
        }
        $ctx->eq($bad, [], 'E3 mask() of a line with every form of three fixture numbers, and a JID, carries no form of the number and no run of nine digits');
    });
}];
