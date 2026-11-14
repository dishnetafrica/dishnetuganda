<?php
declare(strict_types=1);
/**
 * reader.php — 5.18.105 (root docs/74 §H.1.0 E, §H.3.4 E): E's own reader of log statements, shared by sections D (D7)
 * and E of tests/test_log_mask.php. TEST ONLY. Modelled on tests/quote_followup/M_logging.php's qm_log_statements()
 * (which is not edited), with statements joined across lines.
 *
 * A log statement is a call of one of §H.1.0 E2's loggers — error_log, whLog, qwa_log, ilog, olog, evoLog, wsLog, waLog,
 * mlog, rclog, _cclog, cb_log, clog_*, log_msg_*, log_ja, pusherLog, logActivity and PluginLog::write — its span the name
 * to the call's closing parenthesis; and, in the CLI jobs only (cron/, cron_*.php, workers/), an echo or print, its span
 * the keyword to the statement's end. A definition (`function whLog(`) and a method of another class (`$x->ilog(`) are
 * not log statements. QM_LOGGERS' printf, fwrite, master_log and sf_out are not on the list (§H.3.4 E), and neither are
 * writeLog and appendWithId: they write stores code reads back, not logs (§H.2.5, gap 10).
 *
 * For each statement: its first and last line, the logger, its fixed words — the statement's string literals in order,
 * each with its whitespace collapsed and trimmed, the empty ones dropped, with every variable, every array key read and
 * every nested call (its arguments with it) left out — and its hits: each phone-carrying expression it names, with its
 * line. Phone-carrying (§H.1.0 E, §H.3.4 E1): a variable in LM_E_PHONE_VARS, or whose name contains a word of
 * LM_E_PHONE_WORDS; an array element read (`$x['…']`, or `$x[…]` inside a string) whose key contains such a word; a literal
 * key written into an array (`'phone' => …`) is not a read; and the file-by-file fallbacks of LM_E_FALLBACK. The one
 * exception: the whole argument of PluginLog::tail( or PluginLog::mask( — one variable, optionally read by keys, a
 * (string) cast allowed — is not a hit.
 */

const LM_E_LOGGERS = ['error_log', 'whlog', 'qwa_log', 'ilog', 'olog', 'evolog', 'wslog', 'walog', 'mlog', 'rclog', '_cclog', 'cb_log',
                      'log_ja', 'pusherlog', 'logactivity'];
const LM_E_LOGGER_PREFIXES = ['clog_', 'log_msg_'];
const LM_E_PHONE_VARS = ['phone', 'to', 'msisdn', 'number', 'digits', 'recipient', 'remotejid', 'jid'];
const LM_E_PHONE_WORDS = ['phone', 'msisdn', 'jid', 'digits', 'recipient'];
/** An expression that falls back to the number, file by file: [variable name] or ['key' => array key read]. */
const LM_E_FALLBACK = [
    'cron/wa_watchdog.php'  => ['var' => 'who'],                // :85, the name or else the number
    'cron_lead_alerts.php'  => ['var' => 'leadName'],           // :105
    'cron_leads.php'        => ['key' => 'customer_name'],      // :258, :270 — lib/AiLeadService.php:301 sets it to the phone
];

/** Is $rel a CLI job, whose echo and print are log lines (cron/, cron_*.php, workers/)? */
function lm_e_cli(string $rel): bool
{
    return strpos($rel, 'cron/') === 0 || strpos($rel, 'workers/') === 0 || preg_match('#^cron_[^/]*\.php$#', $rel) === 1;
}

/** Does a variable's name (without '$') carry a phone? */
function lm_e_phone_var(string $name, string $rel = ''): bool
{
    $l = strtolower($name);
    if (in_array($l, LM_E_PHONE_VARS, true)) return true;
    foreach (LM_E_PHONE_WORDS as $w) if (strpos($l, $w) !== false) return true;
    return isset(LM_E_FALLBACK[$rel]['var']) && LM_E_FALLBACK[$rel]['var'] === $name;
}

/** Does an array key read carry a phone? */
function lm_e_phone_key(string $key, string $rel = ''): bool
{
    $l = strtolower($key);
    foreach (LM_E_PHONE_WORDS as $w) if (strpos($l, $w) !== false) return true;
    return isset(LM_E_FALLBACK[$rel]['key']) && LM_E_FALLBACK[$rel]['key'] === $key;
}

/** The tokens of $src, each [id|null, text, first line, last line]; whitespace kept. */
function lm_e_tokens(string $src): array
{
    $out = []; $line = 1;
    foreach (token_get_all($src) as $t) {
        if (is_array($t)) { $line = $t[2]; $out[] = [$t[0], $t[1], $t[2], $t[2] + substr_count($t[1], "\n")]; $line = $t[2] + substr_count($t[1], "\n"); }
        else $out[] = [null, $t, $line, $line];
    }
    return $out;
}

/** The index of the next token after $i that is not whitespace or a comment; -1 when none. */
function lm_e_next(array $tk, int $i): int
{
    for ($j = $i + 1, $n = count($tk); $j < $n; $j++) {
        if (!in_array($tk[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) return $j;
    }
    return -1;
}

/** The index of the previous token before $i that is not whitespace or a comment; -1 when none. */
function lm_e_prev(array $tk, int $i): int
{
    for ($j = $i - 1; $j >= 0; $j--) {
        if (!in_array($tk[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) return $j;
    }
    return -1;
}

/** The index of the parenthesis closing the one opened at $open; -1 when unbalanced. */
function lm_e_close(array $tk, int $open): int
{
    $d = 0;
    for ($j = $open, $n = count($tk); $j < $n; $j++) {
        $s = $tk[$j][0] === null ? $tk[$j][1] : '';
        if ($s === '(' ) $d++;
        elseif ($s === ')') { if (--$d === 0) return $j; }
    }
    return -1;
}

/** A name token's text without its leading backslash ('\error_log' → 'error_log'), or '' for a token that is no name. */
function lm_e_name(array $t): string
{
    $ids = [T_STRING];
    foreach (['T_NAME_FULLY_QUALIFIED', 'T_NAME_QUALIFIED'] as $c) if (defined($c)) $ids[] = constant($c);
    return in_array($t[0], $ids, true) ? ltrim($t[1], '\\') : '';
}

/** Is the token at $i a call's name (a name, a variable or a closing bracket followed by '(')? */
function lm_e_is_call(array $tk, int $i): bool
{
    $n = lm_e_next($tk, $i);
    if ($n < 0 || $tk[$n][0] !== null || $tk[$n][1] !== '(') return false;
    return lm_e_name($tk[$i]) !== '' || $tk[$i][0] === T_VARIABLE || in_array($tk[$i][0], [T_ISSET, T_EMPTY], true);
}

/**
 * The log statements of $src (the file $rel, relative to the plugin): each ['line' => first, 'end' => last, 'logger' =>
 * name, 'fixed' => [literal, …], 'hits' => [[expression, line], …]].
 */
function lm_e_statements(string $src, string $rel): array
{
    $tk = lm_e_tokens($src);
    $n = count($tk);
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $t = $tk[$i];
        $logger = ''; $from = -1; $to = -1;
        $name = lm_e_name($t);
        if ($name !== '') {
            $p = lm_e_prev($tk, $i);
            $prevTok = $p >= 0 ? $tk[$p] : [null, '', 0, 0];
            if ($prevTok[0] === T_FUNCTION || in_array($prevTok[0], array_filter([T_OBJECT_OPERATOR, T_DOUBLE_COLON, defined('T_NULLSAFE_OBJECT_OPERATOR') ? constant('T_NULLSAFE_OBJECT_OPERATOR') : null]), true)
                || ($prevTok[0] === T_NEW)) {
                continue;
            }
            $l = strtolower($name);
            $isLogger = in_array($l, LM_E_LOGGERS, true);
            foreach (LM_E_LOGGER_PREFIXES as $pre) if (strpos($l, $pre) === 0 && strlen($l) > strlen($pre)) $isLogger = true;
            if ($isLogger && lm_e_is_call($tk, $i)) {
                $logger = $name; $from = lm_e_next($tk, $i); $to = lm_e_close($tk, $from);
            } elseif ($l === 'pluginlog') {
                $c = lm_e_next($tk, $i);
                $w = $c >= 0 ? lm_e_next($tk, $c) : -1;
                if ($c >= 0 && $tk[$c][0] === T_DOUBLE_COLON && $w >= 0 && strtolower($tk[$w][1]) === 'write' && lm_e_is_call($tk, $w)) {
                    $logger = 'PluginLog::write'; $from = lm_e_next($tk, $w); $to = lm_e_close($tk, $from);
                }
            }
        } elseif (in_array($t[0], [T_ECHO, T_PRINT], true) && lm_e_cli($rel)) {
            $logger = strtolower($t[1]); $from = $i; $d = 0;
            for ($j = $i + 1; $j < $n; $j++) {
                $s = $tk[$j][0] === null ? $tk[$j][1] : '';
                if ($s === '(' || $s === '[' || $s === '{' || in_array($tk[$j][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) $d++;
                elseif ($s === ')' || $s === ']' || $s === '}') $d--;
                if (($s === ';' && $d <= 0) || $tk[$j][0] === T_CLOSE_TAG) break;
            }
            $to = min($j, $n - 1);
        }
        if ($logger === '' || $from < 0 || $to < 0) continue;
        $out[] = ['line' => $t[2], 'end' => $tk[$to][3], 'logger' => $logger,
                  'fixed' => lm_e_fixed($tk, $from + 1, $to), 'hits' => lm_e_hits($tk, $from + 1, $to, $rel)];
    }
    return $out;
}

/** A literal's text as a fixed word: quotes taken off a constant string; whitespace collapsed; trimmed. */
function lm_e_word(array $t): string
{
    $s = $t[0] === T_CONSTANT_ENCAPSED_STRING ? substr($t[1], 1, -1) : $t[1];
    return trim((string)preg_replace('/\s+/u', ' ', $s));
}

/** The fixed words of tokens [$a, $b): literals in order; array keys read, variables and nested calls (with their arguments) left out. */
function lm_e_fixed(array $tk, int $a, int $b): array
{
    $w = [];
    for ($i = $a; $i < $b; $i++) {
        $t = $tk[$i];
        if (lm_e_is_call($tk, $i)) { $o = lm_e_next($tk, $i); $c = lm_e_close($tk, $o); if ($c < 0) break; $i = $c; continue; }
        if ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
            $p = lm_e_prev($tk, $i); $q = lm_e_next($tk, $i);
            if ($p >= 0 && $tk[$p][1] === '[' && $tk[$p][0] === null && $q >= 0 && $tk[$q][1] === ']' && $tk[$q][0] === null) continue;   // a key read
        } elseif ($t[0] !== T_ENCAPSED_AND_WHITESPACE) {
            continue;
        }
        $s = lm_e_word($t);
        if ($s !== '') $w[] = $s;
    }
    return $w;
}

/** The phone-carrying expressions named in tokens [$a, $b), each [expression, line]; the tail()/mask() exception applied. */
function lm_e_hits(array $tk, int $a, int $b, string $rel): array
{
    $h = [];
    for ($i = $a; $i < $b; $i++) {
        $t = $tk[$i];
        // PluginLog::tail( / PluginLog::mask( whose whole argument is one variable (keys allowed, a (string) cast allowed)
        if (strtolower(lm_e_name($t)) === 'pluginlog') {
            $c = lm_e_next($tk, $i);
            $m = $c >= 0 ? lm_e_next($tk, $c) : -1;
            if ($c >= 0 && $tk[$c][0] === T_DOUBLE_COLON && $m >= 0 && in_array(strtolower($tk[$m][1]), ['tail', 'mask'], true) && lm_e_is_call($tk, $m)) {
                $o = lm_e_next($tk, $m); $e = lm_e_close($tk, $o);
                if ($e > 0 && lm_e_whole_var($tk, $o + 1, $e)) { $i = $e; continue; }
            }
        }
        if ($t[0] === T_VARIABLE && $t[1] !== '$this' && lm_e_phone_var(substr($t[1], 1), $rel)) { $h[] = [$t[1], $t[2]]; continue; }
        if ($t[0] === null && $t[1] === '[') {
            $k = lm_e_next($tk, $i); $q = $k >= 0 ? lm_e_next($tk, $k) : -1;
            if ($k >= 0 && $q >= 0 && $tk[$q][0] === null && $tk[$q][1] === ']' && in_array($tk[$k][0], [T_CONSTANT_ENCAPSED_STRING, T_STRING], true)) {
                $key = $tk[$k][0] === T_CONSTANT_ENCAPSED_STRING ? substr($tk[$k][1], 1, -1) : $tk[$k][1];
                if (lm_e_phone_key($key, $rel)) $h[] = ["['{$key}']", $tk[$k][2]];
            }
        }
    }
    return $h;
}

/** Are tokens [$a, $b) one variable, optionally read by keys, with an optional (string) cast before it? */
function lm_e_whole_var(array $tk, int $a, int $b): bool
{
    $s = [];
    for ($i = $a; $i < $b; $i++) if (!in_array($tk[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) $s[] = $tk[$i];
    if ($s !== [] && $s[0][0] === T_STRING_CAST) array_shift($s);
    if ($s === [] || $s[0][0] !== T_VARIABLE) return false;
    for ($i = 1, $n = count($s); $i < $n; $i += 3) {
        if (!isset($s[$i + 2]) || $s[$i][1] !== '[' || $s[$i + 2][1] !== ']') return false;
        if (!in_array($s[$i + 1][0], [T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_STRING, T_VARIABLE], true)) return false;
    }
    return true;
}

/** The log statements of the file $rel under $root ([] when it is absent). */
function lm_e_file(string $root, string $rel): array
{
    $src = @file_get_contents($root . '/' . $rel);
    return $src === false ? [] : lm_e_statements((string)$src, $rel);
}

/** Is line $l inside one of $stmts' spans? */
function lm_e_in_stmt(array $stmts, int $l): bool
{
    foreach ($stmts as $s) if ($l >= $s['line'] && $l <= $s['end']) return true;
    return false;
}
