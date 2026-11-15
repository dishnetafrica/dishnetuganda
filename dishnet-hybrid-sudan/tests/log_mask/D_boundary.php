<?php
declare(strict_types=1);
/**
 * Section D of tests/test_log_mask.php — 5.18.105 (root docs/74 §H.3.4 D, §H.3.1, §H.1.0 "The edit"): the boundary
 * against 994d660 (5.18.104's release commit), taken from Git by this checkout (r105_base, tests/fixtures/r105_revert.php),
 * never from a working tree. The list and the marker are R105_DECLARED, R105_HELD and R105_MARK, read through
 * tests/log_mask/declared.php: one list, never two. TEST ONLY.
 *
 *   D1  exactly the files in R105_DECLARED change or are added, none is removed; each new one is new, each changed one
 *       existed;
 *   D2  migrations/ is 994d660's byte for byte, with 091 last (no migration);
 *   D3  every file in R105_HELD is 994d660's byte for byte, and none of them is declared;
 *   D4  every hunk of every declared changed file carries R105_MARK (in its added lines or the 30 above, or it only closes
 *       a block); no hunk carries 5.18.104's marker (QF_N_MARK) in its own added lines; taken out (r105_revert_tree), the
 *       tree is 994d660's, file for file;
 *   D5  manifest.json differs by its version line only, 5.18.104 → 5.18.105;
 *   D6  php -l passes on every new or changed PHP file; the PHP 7.4 rules (QFU_I_RULES) hold on every added code line of
 *       the runtime files; TL14's key scan over 5.18.105's hunks finds no new $config key;
 *   D7  a hunk in a runtime file touches only log statements (E's reader, tests/log_mask/reader.php) and comments, the
 *       three declared exceptions — PluginLog::tail(), the report's column closure, the report's line 707 — and the four
 *       declared lines, each by its exact text (indentation aside), each once among the release's hunks; and each LN's new
 *       text stands once in its file and its old text nowhere (on 994d660 the reverse).
 */
require_once dirname(__DIR__) . '/quote_followup/declared.php';       // QF_N_MARK, qf_n_code()
require_once dirname(__DIR__) . '/quote_from_ucrm/I_boundary.php';    // QFU_I_RULES (its section is not run from here)
require_once dirname(__DIR__) . '/sales_followup_tool/_lib.php';      // sft_list_reads(), TL14's key scan
require_once __DIR__ . '/reader.php';

/** TL14's key read (tests/sales_followup_tool/_lib.php sft_key_reads): a literal key read from a configuration array. */
const LM_D_KEY_READ = '/\$(?:config|cfg|fc|lc|qc|fresh|storedConfig|this->config|this->evoConfig)\[\s*\'([a-z0-9_]+)\'\s*\]/';

/**
 * The four declared lines (§H.3.4 D7), each [file, exact text without its indentation]; with the one line the fourth
 * replaces, which D7 allows on the removed side.
 */
const LM_D_LINES = [
    ['lib/QuotationService.php', "if (!class_exists('PluginLog')) require_once __DIR__ . '/PluginLog.php';"],
    ['cron_evo_sync.php', "if (!class_exists('PluginLog')) require_once __DIR__ . '/lib/PluginLog.php';"],
    ['webhook.php', "require_once __DIR__ . '/lib/PluginLog.php';"],
    ['cron_evo_sync.php', "\$line = '[' . date('Y-m-d H:i:s') . '] ' . PluginLog::mask(\$msg) . PHP_EOL;"],
];
const LM_D_LINE_REPLACED = ['cron_evo_sync.php', "\$line = '[' . date('Y-m-d H:i:s') . '] ' . \$msg . PHP_EOL;"];
/** The report's two declared exceptions, by the start of their text (tools/sales_followup_report.php, §H.1.23). */
const LM_D_REPORT = 'tools/sales_followup_report.php';
const LM_D_REPORT_CLOSURE = '$sfrPad = function (string $s, int $w): string {';
const LM_D_REPORT_707_NEW = "\$sfrSay('  ' . \$sfrPad((string)\$sfrN, 46) . \$sfrPad(\$sfrRole, 9) . \$sfrPad(\$sfrOwn, 13) . \$sfrPad(\$sfrLine, 7)";
const LM_D_REPORT_707_OLD = "\$sfrSay('  ' . str_pad(\$sfrN, 46) . str_pad(\$sfrRole, 9) . str_pad(\$sfrOwn, 13) . str_pad(\$sfrLine, 7)";

/** Is a source line a comment or blank? */
function lm_d_comment(string $line): bool
{
    $t = trim($line);
    return $t === '' || strpos($t, '//') === 0 || strpos($t, '#') === 0 || strpos($t, '/*') === 0 || strpos($t, '*') === 0;
}

/** The lines of PluginLog::tail() in $src (its signature to its closing brace): [first, last], or [0, -1]. */
function lm_d_tail_span(string $src): array
{
    $tk = lm_e_tokens($src);
    for ($i = 0, $n = count($tk); $i < $n; $i++) {
        if ($tk[$i][0] !== T_FUNCTION) continue;
        $j = lm_e_next($tk, $i);
        if ($j < 0 || $tk[$j][0] !== T_STRING || $tk[$j][1] !== 'tail') continue;
        $d = 0;
        for ($k = $j; $k < $n; $k++) {
            if ($tk[$k][0] === null && $tk[$k][1] === '{') $d++;
            elseif ($tk[$k][0] === null && $tk[$k][1] === '}') { if (--$d === 0) return [$tk[$i][2], $tk[$k][2]]; }
        }
    }
    return [0, -1];
}

/** The added lines of $rel's runtime hunks ([line => text]) and the removed ones, against 994d660. */
function lm_d_sides(string $B, string $T, string $rel): array
{
    $new = explode("\n", (string)file_get_contents($T . '/' . $rel));
    $old = explode("\n", (string)file_get_contents($B . '/' . $rel));
    $plus = []; $minus = [];
    foreach (r105_hunks($B . '/' . $rel, $T . '/' . $rel) as [$os, $ol, $ns, $nl]) {
        for ($l = $ns; $l < $ns + $nl; $l++) $plus[$l] = (string)($new[$l - 1] ?? '');
        for ($l = $os; $l < $os + $ol; $l++) $minus[$l] = (string)($old[$l - 1] ?? '');
    }
    return [$plus, $minus];
}

$LM_SECTIONS['D'] = ['title' => 'The boundary against 994d660, from Git (§H.3.4 D1-D7)', 'run' => function (QfCtx $ctx) {
    [$B, $why] = r105_base($ctx->dir('r105-base'));
    $ctx->t($B !== null, 'D00 control: Git supplies ' . R105_BASE . ' (5.18.104) — without it nothing below is measured: a failure, never a skip', (string)$why);
    if ($B === null) return;
    $T = r106_less($ctx->root, $ctx->dir('r106'));   // 5.18.106 (root docs/75 §J): the judged root with 5.18.106 taken out against 7835906
    $base = r105_walk($B);
    $tree = r105_walk($T);
    $ctx->t(count($base) > 1000 && isset($tree['lib/KycService.php'], $base['webhook.php']), 'D00 control: both walks see the plugin (' . count($base) . ' and ' . count($tree) . ' files)');
    $changed = []; $added = []; $removed = [];
    foreach ($tree as $rel => $p) {
        if (!isset($base[$rel])) { $added[] = $rel; continue; }
        if (hash_file('sha256', $p) !== hash_file('sha256', $base[$rel])) $changed[] = $rel;
    }
    foreach ($base as $rel => $p) if (!isset($tree[$rel])) $removed[] = $rel;
    $moved = array_merge($changed, $added);
    $runtime = array_values(array_filter($changed, function ($r) { return substr($r, -4) === '.php' && strpos($r, 'tests/') !== 0; }));

    $ctx->run('D1 exactly the declared files change or are added, none is removed; each new one is new, each changed one existed', function () use ($ctx, $moved, $added, $removed, $tree, $base) {
        $undeclared = array_values(array_filter($moved, function ($r) { return r105_declared_entry($r) === null; }));
        $still = []; $kind = []; $badRow = [];
        foreach (R105_DECLARED as $p => $row) {
            if (!in_array($row[0], ['new', 'changed'], true) || trim((string)($row[1] ?? '')) === '' || ($row[0] === 'changed' && substr($p, -1) === '/')) $badRow[] = $p;
            if (substr($p, -1) === '/') {
                if (array_filter(array_keys($tree), function ($r) use ($p) { return strpos($r, $p) === 0; }) === []) $still[] = $p;
            } elseif (!in_array($p, $moved, true)) $still[] = $p;
        }
        foreach ($moved as $r) {
            $e = r105_declared_entry($r);
            if ($e === null) continue;
            if (R105_DECLARED[$e][0] === 'new' && isset($base[$r])) $kind[] = $r . ' (declared new, but ' . R105_BASE . ' has it)';
            if (R105_DECLARED[$e][0] === 'changed' && !isset($base[$r])) $kind[] = $r . ' (declared changed, but ' . R105_BASE . ' has none)';
        }
        $ctx->eq($badRow, [], 'D1 every entry of R105_DECLARED names its kind (new, changed; a directory only new) and its reason');
        $ctx->eq(['not declared' => $undeclared, 'declared, not changed' => $still, 'removed' => $removed, 'of the wrong kind' => $kind],
                 ['not declared' => [], 'declared, not changed' => [], 'removed' => [], 'of the wrong kind' => []],
                 'D1 against ' . R105_BASE . ' the tree changes or adds exactly the ' . count(R105_DECLARED) . ' declared entries (' . count($moved) . ' files), and removes none');
        $ctx->t(count($added) > 20 && count($moved) > count($added) + 30, 'D1 control: the walk saw 5.18.105 (' . count($added) . ' new files, ' . (count($moved) - count($added)) . ' changed)');
    });

    $ctx->run('D2 migrations/ is 994d660\'s byte for byte, with 091 last', function () use ($ctx, $tree, $base) {
        $m = function (array $walk): array {
            $o = [];
            foreach ($walk as $rel => $p) if (strpos($rel, 'migrations/') === 0) $o[$rel] = hash_file('sha256', $p);
            ksort($o);
            return $o;
        };
        $mt = $m($tree); $mb = $m($base);
        $ctx->eq(['added' => array_keys(array_diff_key($mt, $mb)), 'removed' => array_keys(array_diff_key($mb, $mt)), 'changed' => array_keys(array_diff_assoc(array_intersect_key($mt, $mb), $mb))],
                 ['added' => [], 'removed' => [], 'changed' => []], 'D2 migrations/ is ' . R105_BASE . '\'s, ' . count($mb) . ' files byte for byte: no migration');
        $sql = array_values(array_filter(array_keys($mt), function ($f) { return substr($f, -4) === '.sql'; }));
        sort($sql);
        $ctx->eq([end($sql), count($mb) > 80], ['migrations/091_wa_offers.sql', true], 'D2 … and 091 is the last migration (control: ' . count($mb) . ' files read)');
    });

    $ctx->run('D3 every file in R105_HELD is 994d660\'s byte for byte, and none of them is declared', function () use ($ctx, $tree, $base) {
        $bad = []; $decl = []; $n = 0;
        foreach (R105_HELD as $h) {
            if (r105_declared_entry($h) !== null || (substr($h, -1) === '/' && array_filter(array_keys(R105_DECLARED), function ($d) use ($h) { return strpos($d, $h) === 0; }) !== [])) $decl[] = $h;
            $files = substr($h, -1) === '/'
                ? array_values(array_unique(array_merge(array_filter(array_keys($tree), function ($r) use ($h) { return strpos($r, $h) === 0; }),
                                                        array_filter(array_keys($base), function ($r) use ($h) { return strpos($r, $h) === 0; }))))
                : [$h];
            if ($files === []) $bad[] = $h . ' (absent)';
            foreach ($files as $rel) {
                $n++;
                if (!isset($tree[$rel], $base[$rel]) || hash_file('sha256', $tree[$rel]) !== hash_file('sha256', $base[$rel])) $bad[] = $rel;
            }
        }
        $ctx->eq(['changed' => $bad, 'declared' => $decl], ['changed' => [], 'declared' => []], 'D3 the ' . count(R105_HELD) . ' held entries (' . $n . ' files) are ' . R105_BASE . '\'s, and none is declared');
        $ctx->t($n > 80 && in_array('tools/sales_followup.php', R105_HELD, true), 'D3 control: the held files were read (' . $n . ', tools/sales_followup.php among them)');
    });

    $ctx->run('D4 every hunk carries the 5.18.105 marker, none 5.18.104\'s; taken out, the tree is 994d660\'s, file for file', function () use ($ctx, $B, $T, $changed, $base) {
        $unmarked = []; $wears = []; $hunks = 0;
        foreach ($changed as $rel) {
            $e = r105_declared_entry($rel);
            if ($rel === 'manifest.json' || $e === null || R105_DECLARED[$e][0] !== 'changed') continue;
            foreach (r105_hunks($B . '/' . $rel, $T . '/' . $rel) as [$os, $ol, $ns, $nl, $plus, , $marked]) {
                $hunks++;
                if (!$marked) $unmarked[] = $rel . ' +' . $ns . ',' . $nl . ': ' . mb_substr(trim((string)($plus[0] ?? '(a deletion)')), 0, 100);
                if (preg_match(QF_N_MARK, implode("\n", $plus)) === 1) $wears[] = $rel . ' +' . $ns . ',' . $nl;
            }
        }
        $ctx->eq($unmarked, [], 'D4 every one of the ' . $hunks . ' hunks carries "5.18.105 … root docs/74 §" in itself or in the 30 lines above it, or only closes a block');
        $ctx->eq($wears, [], 'D4 no hunk carries 5.18.104\'s marker ("5.18.104 … root docs/73 §H") in its own added lines');
        $D = $ctx->dir('d4-revert');
        $left = r105_revert_tree($T, $B, $D);
        $back = r105_walk($D); $diff = [];
        foreach ($back as $r => $p) if (!isset($base[$r]) || hash_file('sha256', $p) !== hash_file('sha256', $base[$r])) $diff[] = $r;
        foreach ($base as $r => $p) if (!isset($back[$r])) $diff[] = $r . ' (missing)';
        $ctx->eq([$left, $diff, count($back)], [0, [], count($base)], 'D4 the judged tree with 5.18.105\'s declared files and hunks taken out is ' . R105_BASE . '\'s, file for file (' . count($base) . ')');
        $ctx->t($hunks > 60 && r105_hunks($B . '/webhook.php', $T . '/webhook.php') !== [], 'D4 control: the hunks were read (' . $hunks . ', webhook.php\'s among them)');
    });

    $ctx->run('D5 manifest.json is 994d660\'s with one line changed, its version, 5.18.104 → 5.18.105', function () use ($ctx, $B, $T) {
        $mb = str_replace('    "version": "5.18.104",', '    "version": "5.18.105",', (string)file_get_contents($B . '/manifest.json'), $mn);
        $ctx->eq([$mn, $mb === (string)@file_get_contents($T . '/manifest.json')], [1, true], 'D5 the version line alone');
    });

    $ctx->run('D6 php -l, the PHP 7.4 rules on added code lines, and no new $config key', function () use ($ctx, $B, $T, $moved, $base) {
        $lint = []; $bad = []; $keys = []; $linted = 0; $ruled = 0;
        foreach ($moved as $rel) {
            if (substr($rel, -4) !== '.php') continue;
            $linted++;
            $lo = []; exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($T . '/' . $rel) . ' 2>&1', $lo, $lrc);
            if ($lrc !== 0) $lint[] = $rel;
            if (strpos($rel, 'tests/') === 0) continue;
            $ruled++;
            $cb = $ctx->tmp . '/d6_base.php'; $cn = $ctx->tmp . '/d6_new.php';
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
            // TL14's key scan, over the added lines of the hunks (raw text: a key is a string)
            $text = isset($base[$rel]) ? implode("\n", array_merge(...array_map(function ($h) { return $h[4]; }, r105_hunks($B . '/' . $rel, $T . '/' . $rel) ?: [[0, 0, 0, 0, []]])))
                                       : (string)file_get_contents($T . '/' . $rel);
            if (preg_match_all(LM_D_KEY_READ, $text, $km)) foreach ($km[1] as $k) $keys[] = $rel . ': ' . $k;
            foreach (sft_list_reads($text) as $k) $keys[] = $rel . ': ' . $k . ' (a list read)';
        }
        $ctx->eq($lint, [], 'D6 php -l passes on every one of the ' . $linted . ' new or changed PHP files, under PHP ' . PHP_VERSION);
        $ctx->eq($bad, [], 'D6 no added code line of the ' . $ruled . ' runtime PHP files uses ' . implode(', ', array_keys(QFU_I_RULES)));
        $ctx->eq($keys, [], 'D6 TL14\'s key scan over 5.18.105\'s hunks finds no new $config key');
        $probe = "\$a = \$config['lm_probe_key'] ?? 0;\nforeach (['lm_list_a', 'lm_list_b'] as \$k) \$x = \$config[\$k];\n";
        preg_match_all(LM_D_KEY_READ, $probe, $pm);
        $ctx->eq([$pm[1], sft_list_reads($probe), $ruled >= 20], [['lm_probe_key'], ['lm_list_a', 'lm_list_b'], true],
                 'D6 control: the scan finds a literal read and a list read; ' . $ruled . ' runtime files were ruled');
        $mp = qf_n_code("<?php\n\$x = match (\$a) { 1 => 2 }; // match (\n");
        $ctx->eq(array_keys(array_filter(QFU_I_RULES, function ($re) use ($mp) { return preg_match($re, $mp) === 1; })), ['match ('], 'D6 control: the rules find match ( in code, and not in a comment');
    });

    $ctx->run('D7 runtime hunks touch only log statements, comments, the three exceptions and the four declared lines', function () use ($ctx, $B, $T, $runtime) {
        $bad = []; $used = array_fill(0, count(LM_D_LINES), 0); $readLines = 0;
        foreach ($runtime as $rel) {
            if (!isset(R105_DECLARED[$rel])) { $bad[] = $rel . ' (not declared: D1\'s)'; continue; }
            $newSrc = (string)file_get_contents($T . '/' . $rel);
            $oldSrc = (string)file_get_contents($B . '/' . $rel);
            $newStmts = lm_e_statements($newSrc, $rel);
            $oldStmts = lm_e_statements($oldSrc, $rel);
            $tail = $rel === 'lib/PluginLog.php' ? lm_d_tail_span($newSrc) : [0, -1];
            [$plus, $minus] = lm_d_sides($B, $T, $rel);
            foreach ($plus as $l => $text) {
                $readLines++;
                $t = trim($text);
                if (lm_d_comment($text) || lm_e_in_stmt($newStmts, $l) || ($l >= $tail[0] && $l <= $tail[1])) continue;
                if ($rel === LM_D_REPORT && (strpos($t, LM_D_REPORT_CLOSURE) === 0 || strpos($t, LM_D_REPORT_707_NEW) === 0)) continue;
                $hit = false;
                foreach (LM_D_LINES as $i => [$f, $exact]) if ($f === $rel && $t === $exact) { $used[$i]++; $hit = true; break; }
                if (!$hit) $bad[] = $rel . ' +' . $l . ': ' . mb_substr($t, 0, 120);
            }
            foreach ($minus as $l => $text) {
                $t = trim($text);
                if (lm_d_comment($text) || lm_e_in_stmt($oldStmts, $l)) continue;
                if ($rel === LM_D_REPORT && strpos($t, LM_D_REPORT_707_OLD) === 0) continue;
                if ($rel === LM_D_LINE_REPLACED[0] && $t === LM_D_LINE_REPLACED[1]) continue;
                $bad[] = $rel . ' -' . $l . ': ' . mb_substr($t, 0, 120);
            }
        }
        $ctx->eq($bad, [], 'D7 every added and removed line of the ' . count($runtime) . ' runtime files is a log statement\'s (E\'s reader), a comment, a declared exception or a declared line');
        $ctx->eq($used, [1, 1, 1, 1], 'D7 each of the four declared lines stands once among the release\'s hunks');
        $ctx->t($readLines >= 70 && count($runtime) >= 21, 'D7 control: ' . $readLines . ' added lines of ' . count($runtime) . ' runtime files were read');
        $blocks = [];
        foreach (LM_LINES as $ln => $row) {
            $n = (string)@file_get_contents($T . '/' . $row['file']);
            $o = (string)@file_get_contents($B . '/' . $row['file']);
            $got = [substr_count($n, $row['new']), substr_count($n, $row['old']), substr_count($o, $row['old']), substr_count($o, $row['new'])];
            if ($got !== [1, 0, 1, 0]) $blocks[] = $ln . ' ' . json_encode($got);
        }
        $ctx->eq($blocks, [], 'D7 each of the 35 LNs: its new text once in its file and its old text nowhere; on ' . R105_BASE . ' its old text once and its new text nowhere');
    });
}];
