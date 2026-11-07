<?php
declare(strict_types=1);
/**
 * Section H of tests/test_voice_ack.php — what stage B6's files must hold by their text (5.18.104, root docs/73 §H.3.15,
 * §H.3.16, §H.3.24; S2-21, rulings R4, R10, R13; A33, HK7's method). TEST ONLY. PHP 7.4 syntax only (this runtime's
 * php -l is newer, so the forms 7.4 refuses are looked for by token); no plugin path sends audio; P5's one send is
 * `acknowledge`; the acknowledgement's numbers live in VoiceAckLimits alone; VoiceSteps opens no connection; and every
 * hunk of a changed file carries its 5.18.104 marker.
 */
$VA_SECTIONS['H'] = ['title' => 'The text of B6\'s files: PHP 7.4 only, no audio sent, one send in VoiceAck, the limits in one holder, no connection in VoiceSteps, markers', 'run' => function (QfCtx $ctx): void {
    $t = $ctx->tree();
    $new = ['lib/VoiceAck.php', 'lib/VoiceSteps.php'];
    $changed = ['workers/AiReplyWorker.php', 'lib/BrainContext.php', 'lib/DishNetAiBrain.php', 'includes/api/api_whatsapp.php', 'tabs/engage/wa_inbox.php'];
    $code = function (string $src): array {
        $out = [];
        foreach (token_get_all($src) as $tk) {
            if (is_array($tk) && in_array($tk[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) continue;
            $out[] = $tk;
        }
        return $out;
    };

    $ctx->run('H01 PHP 7.4 syntax only in B6\'s new and changed files (S2-21; A33)', function () use ($ctx, $t, $new, $changed, $code) {
        $bad = [];
        foreach (array_merge($new, $changed) as $f) {
            $src = (string)@file_get_contents($t . '/' . $f);
            if ($src === '') { $bad[] = "{$f}: missing"; continue; }
            $tk = $code($src);
            $n = count($tk);
            for ($i = 0; $i < $n; $i++) {
                $cur = $tk[$i];
                $txt = is_array($cur) ? $cur[1] : $cur;
                $prev = $i > 0 ? (is_array($tk[$i - 1]) ? $tk[$i - 1][1] : $tk[$i - 1]) : '';
                $next = $i + 1 < $n ? (is_array($tk[$i + 1]) ? $tk[$i + 1][1] : $tk[$i + 1]) : '';
                // A named argument: an identifier straight after '(' or ',' and followed by a single ':'.
                if (is_array($cur) && $cur[0] === T_STRING && ($prev === '(' || $prev === ',') && $next === ':') $bad[] = "{$f}: named argument {$txt}:";
                if (is_array($cur) && defined('T_MATCH') && $cur[0] === T_MATCH) $bad[] = "{$f}: match";
                if (is_array($cur) && defined('T_NULLSAFE_OBJECT_OPERATOR') && $cur[0] === T_NULLSAFE_OBJECT_OPERATOR) $bad[] = "{$f}: ?->";
                if (is_array($cur) && defined('T_ENUM') && $cur[0] === T_ENUM) $bad[] = "{$f}: enum";
                if (is_array($cur) && defined('T_READONLY') && $cur[0] === T_READONLY) $bad[] = "{$f}: readonly";
                if (is_array($cur) && defined('T_ATTRIBUTE') && $cur[0] === T_ATTRIBUTE) $bad[] = "{$f}: attribute";
                if ($txt === '...' && $prev === '(' && $next === ')') $bad[] = "{$f}: first-class callable";
            }
            if (preg_match('/\b(str_contains|str_starts_with|str_ends_with)\s*\(/', $src)) $bad[] = "{$f}: an 8.0 string function";
            if (preg_match('/function\s+\w*\s*\([^)]*\b(mixed|never)\s+\$/', $src) || preg_match('/\)\s*:\s*\??(mixed|never|static)\b/', $src)) $bad[] = "{$f}: an 8.0 type";
            if (preg_match('/function\s+\w+\s*\([^)]*\w\|\w[^)]*\$/', $src)) $bad[] = "{$f}: a union type";
            $o = []; exec('php -l ' . escapeshellarg($t . '/' . $f) . ' 2>&1', $o, $rc);
            if ($rc !== 0) $bad[] = "{$f}: php -l";
        }
        $ctx->eq($bad, [], 'H01 none of the forms PHP 7.4 refuses');
        $probe = $code('<?php f(a: 1); $x = $y?->z;');
        $ctx->t(count($probe) > 5, 'H01 control: the scanner reads a named argument\'s tokens');
    });

    $ctx->run('H02 no plugin path sends audio (the remaining assertion of the dropped test_handset_media.php)', function () use ($ctx, $t) {
        $hits = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($t, \FilesystemIterator::SKIP_DOTS));
        $n = 0;
        foreach ($it as $f) {
            $p = $f->getPathname();
            if (substr($p, -4) !== '.php' || strpos($p, '/tests/') !== false || strpos($p, '/data/') !== false) continue;
            $n++;
            $s = (string)file_get_contents($p);
            if (preg_match('/->sendAudio\s*\(|sendMedia\s*\([^;]*[\'"]audio[\'"]/', $s)) $hits[] = substr($p, strlen($t) + 1);
        }
        $ctx->t($n > 200, "H02 control: the scan read the plugin ({$n} files)");
        $ctx->eq($hits, [], 'H02 no sendAudio, no sendMedia of audio');
    });

    $ctx->run('H03 P5\'s one send: acknowledge is the only method in lib/VoiceAck.php that calls sendText (HK7; §H.3.16)', function () use ($ctx, $t) {
        $src = (string)file_get_contents($t . '/lib/VoiceAck.php');
        $tk = token_get_all($src);
        $method = ''; $depth = 0; $mdepth = -1; $sites = [];
        foreach ($tk as $i => $x) {
            if (is_array($x) && $x[0] === T_FUNCTION) {
                for ($j = $i + 1; $j < count($tk); $j++) { if (is_array($tk[$j]) && $tk[$j][0] === T_STRING) { $method = $tk[$j][1]; $mdepth = $depth; break; } if ($tk[$j] === '(') break; }
            }
            if ($x === '{' || (is_array($x) && in_array($x[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) $depth++;
            if ($x === '}') { $depth--; if ($depth <= $mdepth) { $method = ''; $mdepth = -1; } }
            if (is_array($x) && $x[0] === T_STRING && $x[1] === 'sendText') $sites[] = $method;
        }
        $ctx->eq($sites, ['acknowledge'], 'H03 one sendText call, in acknowledge');
    });

    $ctx->run('H04 the acknowledgement\'s numbers live in VoiceAckLimits alone (§H.3.15, K2-18; migrations/ hold 089\'s CHECK, the floor)', function () use ($ctx, $t) {
        $bad = [];
        $files = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($t, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $p = $f->getPathname();
            $rel = substr($p, strlen($t) + 1);
            if (substr($p, -4) !== '.php' || strpos($rel, 'tests/') === 0 || strpos($rel, 'data/') === 0 || $rel === 'lib/VoiceAckLimits.php') continue;
            $src = (string)file_get_contents($p);
            if (stripos($src, 'voice_ack') === false) continue;
            $files++;
            $line = 1; $buf = [];
            foreach (token_get_all($src) as $x) {
                $txt = is_array($x) ? $x[1] : $x;
                $isComment = is_array($x) && in_array($x[0], [T_COMMENT, T_DOC_COMMENT], true);
                if (!$isComment) $buf[$line] = ($buf[$line] ?? '') . $txt;
                $line += substr_count($txt, "\n");
            }
            foreach ($buf as $ln => $l) {
                if (stripos($l, 'voice_ack') !== false && preg_match('/(?<![\w.:-])(0?7|20|21|25)(?![\w.:-])/', $l)) $bad[] = "{$rel}:{$ln}";
            }
        }
        $ctx->t($files >= 3, "H04 control: the scan read the files that name voice_ack ({$files})");
        $ctx->eq($bad, [], 'H04 no 7, 20, 21 or 25 beside voice_ack outside VoiceAckLimits');
    });

    $ctx->run('H05 VoiceSteps opens no connection of its own (ruling R13)', function () use ($ctx, $t) {
        $src = (string)file_get_contents($t . '/lib/VoiceSteps.php');
        $ctx->eq([preg_match('/new\s+\\\\?PDO\b/', $src), substr_count($src, 'SqliteStore::'), substr_count($src, 'getPdo('), substr_count($src, 'PluginConfig::')],
                 [0, 0, 0, 0], 'H05 no new PDO, no SqliteStore, no getPdo, no configuration read of its own');
    });

    $ctx->run('H06 every hunk of B6\'s changed files carries its 5.18.104 marker', function () use ($ctx, $t, $changed) {
        $top = trim((string)shell_exec('git -C ' . escapeshellarg(dirname(__DIR__, 2)) . ' rev-parse --show-toplevel 2>/dev/null'));
        if ($top === '') { $ctx->t(false, 'H06 Git supplies 221c9dc'); return; }
        $bad = []; $hunks = 0;
        foreach ($changed as $f) {
            $base = $ctx->dir('h06') . '/base';
            exec('git -C ' . escapeshellarg($top) . ' show ' . VM_BASE . ':dishnet-hybrid-sudan/' . $f . ' > ' . escapeshellarg($base) . ' 2>/dev/null', $o, $rc);
            $diff = [];
            exec('git diff --no-index --no-color --diff-algorithm=histogram -U0 ' . escapeshellarg($base) . ' ' . escapeshellarg($t . '/' . $f), $diff);
            $newLines = file($t . '/' . $f) ?: [];
            $cur = null;
            $flush = function () use (&$cur, &$bad, &$hunks, $f, $newLines) {
                if ($cur === null) return;
                $hunks++;
                $ok = false;
                foreach ($cur['added'] as $l) if (strpos($l, '5.18.104 (root docs/73 §H.') !== false) $ok = true;
                for ($k = max(0, $cur['start'] - 15); !$ok && $k < min(count($newLines), $cur['start'] + $cur['len'] + 2); $k++) {
                    if (strpos($newLines[$k], '5.18.104 (root docs/73 §H.') !== false) $ok = true;
                }
                if (!$ok) $bad[] = "{$f}:" . ($cur['start'] + 1);
                $cur = null;
            };
            foreach ($diff as $l) {
                if (preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@/', $l, $m)) { $flush(); $cur = ['start' => (int)$m[1] - 1, 'len' => isset($m[2]) ? (int)$m[2] : 1, 'added' => []]; continue; }
                if ($cur !== null && isset($l[0]) && $l[0] === '+' && strpos($l, '+++') !== 0) $cur['added'][] = $l;
            }
            $flush();
        }
        $ctx->t($hunks >= 12, "H06 control: the five files differ from 221c9dc in {$hunks} hunks");
        $ctx->eq($bad, [], 'H06 each hunk names root docs/73 §H.x');
    });
}];
