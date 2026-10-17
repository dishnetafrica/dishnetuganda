<?php
// srccheck.php <file> — the static rules for scripts/bank-recon-r0.php (the specification's §5.5), read from its code
// with comments removed: P0's rules (scripts/harness/bank-recon-p0/srccheck.php) and R0's own. Exit 0 when every rule
// holds; otherwise prints what broke (rule names, ";"-separated) and exits 1. Each rule has a planted control in
// rehearse.sh T5.
$s = (string)file_get_contents($argv[1]);
$code = ''; $toks = [];
foreach (token_get_all($s) as $t) {
    if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
    $code .= is_array($t) ? $t[1] : $t;
    if (!(is_array($t) && $t[0] === T_WHITESPACE)) $toks[] = $t;
}
$tv = static fn($t) => is_array($t) ? $t[1] : $t;
$tid = static fn($t) => is_array($t) ? $t[0] : null;
$bad = [];
// ── P0's rules ──────────────────────────────────────────────────────────────────────────────────────────────────
if (preg_match('/\b(require|require_once|include|include_once|eval)\b/i', $code)) $bad[] = 'loads code';
if (preg_match('/CashbookService|SqliteStore|PluginConfig|ConfigVault|MigrationRunner|FinAudit::|StaffLedger/', $code)) $bad[] = 'names plugin code';
if (preg_match_all('/new\s+\\\\?\s*PDO\s*\(/', $code) !== 1) $bad[] = 'not exactly one PDO open';
if (!preg_match("/new PDO\('sqlite:file:' \. \\\$db \. '\?immutable=1', null, null, \[\s*PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,\s*PDO::ATTR_TIMEOUT => 15,\s*PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,\s*\]\)/", $code)) {
    $bad[] = 'the open is not immutable and read-only';
}
preg_match_all('/->exec\(\s*([^)]*)\)/', $code, $m);
foreach ($m[1] as $e) if (!in_array(trim($e), ["'PRAGMA query_only = ON'", "'PRAGMA temp_store = MEMORY'", "'BEGIN'", "'ROLLBACK'"], true)) $bad[] = "exec({$e})";
foreach (["'PRAGMA query_only = ON'", "'PRAGMA temp_store = MEMORY'"] as $need) if (!in_array($need, array_map('trim', $m[1]), true)) $bad[] = "no exec({$need})";
if (preg_match_all('/->(query|prepare)\(/', $code) !== 1) $bad[] = 'a query outside q()';
$noExec = preg_replace('/->exec\(/', '->X(', $code);
$FORBID = 'file_put_contents|fopen|fwrite|fputs|unlink|rename|copy|touch|mkdir|rmdir|chmod|chown|chgrp|tempnam|tmpfile|symlink|link|shell_exec|exec|system|passthru|proc_open|popen|pcntl_exec|mail|curl_init|fsockopen|stream_socket_client|file|ini_set|putenv|setcookie|header'
        . '|file_get_contents|getenv|is_writable|error_log|syslog|openlog|readfile|fpassthru|call_user_func|call_user_func_array|dl|set_include_path|ini_alter|highlight_file|show_source|parse_ini_file';
if (preg_match('/(?<![\w>:$])(' . $FORBID . ')\s*\(/i', $noExec, $mm)) $bad[] = 'a writing, process, network or logging function: ' . $mm[1];
if (strpos($code, '`') !== false) $bad[] = 'a backtick';
// ── R0: no second open by another class; no forbidden function named in a string ─────────────────────────────────
if (preg_match('/\b(SQLite3|SplFileObject|SplFileInfo|SplTempFileObject|DirectoryIterator)\b/', $code)) $bad[] = 'another file or database class';
foreach ($toks as $t) {
    if ($tid($t) !== T_CONSTANT_ENCAPSED_STRING) continue;
    $lit = substr($t[1], 1, -1);
    if (preg_match('/^(' . $FORBID . ')$/i', $lit)) $bad[] = 'a forbidden function named in a string: ' . $lit;
    if (preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE|CREATE|DROP|ALTER|ATTACH|DETACH|VACUUM|REINDEX)\b/', $lit)) $bad[] = 'a writing SQL word in a string: ' . substr($lit, 0, 40);
    if (preg_match('/PRAGMA (?!query_only = ON$|temp_store = MEMORY$)/', $lit)) $bad[] = 'a PRAGMA statement in a string';
}
foreach ($toks as $t) if ($tid($t) === T_ENCAPSED_AND_WHITESPACE && preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE|CREATE|DROP|ALTER|ATTACH|DETACH|VACUUM|REINDEX)\b/', $t[1])) $bad[] = 'a writing SQL word in a string: ' . substr($t[1], 0, 40);
// ── R0: catches ─────────────────────────────────────────────────────────────────────────────────────────────────
if (preg_match_all('/catch \(Throwable \$e\)/', $code) !== 2) $bad[] = 'not exactly two catch (Throwable $e)';
if (preg_match_all('/catch \(PDOException \$e\) \{([^}]*)\}/', $code, $pc) !== 1 || strpos($pc[1][0] ?? '', 'throw $e;') === false) $bad[] = 'the probe catch is not one catch (PDOException $e) that re-throws';
if (preg_match_all('/getMessage\(/', $code) !== 1 || !preg_match("/strpos\(\\\$e->getMessage\(\), 'no such function'\)/", $code)) $bad[] = 'getMessage outside the probe';
if (!preg_match('/\} catch \(Throwable \$e\) \{\s*if \(\$e instanceof PDOException\)/', $code)) $bad[] = 'the reading catch has no instanceof PDOException branch';
// ── R0: the first two statements ────────────────────────────────────────────────────────────────────────────────
$after = preg_split('/declare\(strict_types=1\);/', $code, 2)[1] ?? '';
$st = preg_split('/;\s*\n/', ltrim($after), 3);
if (!preg_match('/^set_error_handler\(static function \(int \$no, string \$s, string \$f, int \$l\): bool \{ if \(!\(error_reporting\(\) & \$no\)\) return false; throw new ErrorException\(\'\', 0, \$no, \'\', \$l\); \}\)$/', $st[0] ?? '')) $bad[] = 'the first statement is not the silent error handler';
if (!preg_match('/^set_exception_handler\(static function \(Throwable \$e\): void \{ echo "R0: FAILED — an internal error \(", get_class\(\$e\), ", line ", \$e->getLine\(\), "\); nothing was written; do not run it again — send the log\.\\\\n"; exit\(3\); \}\)$/', $st[1] ?? '')) $bad[] = 'the second statement is not the class-and-line exception handler';
// ── R0: every mask call's argument begins (string); every variable-index read is guarded ────────────────────────
$n = count($toks);
for ($k = 0; $k < $n - 2; $k++) {
    $t = $toks[$k];
    if ($tid($t) === T_STRING && in_array($t[1], ['pick', 'code', 'src', 'day'], true) && $tv($toks[$k + 1]) === '(') {
        $prev = $toks[$k - 1] ?? null;
        if ($prev !== null && in_array($tid($prev), [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) continue;
        if ($tid($toks[$k + 2]) !== T_STRING_CAST) $bad[] = "a {$t[1]}() call whose argument is not (string): line {$t[2]}";
    }
}
$isAssign = ['=', '??', T_COALESCE, T_COALESCE_EQUAL, T_PLUS_EQUAL, T_MINUS_EQUAL, T_CONCAT_EQUAL, T_MUL_EQUAL, T_INC, T_DEC, T_OR_EQUAL, T_AND_EQUAL];
for ($k = 1; $k < $n - 2; $k++) {
    if ($tv($toks[$k]) !== '[' || $tid($toks[$k + 1]) !== T_VARIABLE || $tv($toks[$k + 2]) !== ']') continue;
    $p = $toks[$k - 1];
    if (!($tid($p) === T_VARIABLE || $tv($p) === ']' || ($tid($p) === T_STRING && strtoupper($p[1]) === $p[1]))) continue;   // an index, not an array literal
    $e = $k + 2;
    while (($toks[$e + 1] ?? null) !== null && $tv($toks[$e + 1]) === '[') { $d = 0; for ($e = $e + 1; $e < $n; $e++) { if ($tv($toks[$e]) === '[') $d++; if ($tv($toks[$e]) === ']' && --$d === 0) break; } }
    $next = $toks[$e + 1] ?? null;
    if ($next !== null && (in_array($tid($next), $isAssign, true) || in_array($tv($next), ['=', '??'], true))) continue;
    // inside isset( / unset( / array_key_exists( ?
    $depth = 0; $ok = false;
    for ($b = $k - 1; $b >= 0; $b--) {
        $x = $tv($toks[$b]);
        if ($x === ')') $depth++;
        elseif ($x === '(') { if ($depth === 0) { $f = $toks[$b - 1] ?? null; if ($f !== null && (in_array($tid($f), [T_ISSET, T_UNSET], true) || ($tid($f) === T_STRING && $f[1] === 'array_key_exists'))) $ok = true; break; } $depth--; }
        elseif ($x === ';' || $x === '{' || $x === '}') break;
    }
    if (!$ok) $bad[] = 'an unguarded variable-index read: line ' . ($toks[$k + 1][2] ?? '?');
}
// ── R0: queries ─────────────────────────────────────────────────────────────────────────────────────────────────
if (!preg_match('/function q\(PDO \$pdo, HashContext \$h, string \$sql, array \$p = \[\]\): array \{.*?hash_update\(\$h, \$sql \. "\\\\n" \. serialize\(\$p\) \. "\\\\n"\);.*?foreach \(\$rows as \$row\) hash_update\(\$h, serialize\(\$row\) \. "\\\\n"\);\s*return \$rows;/s', $code)) $bad[] = 'q() does not feed every fetched row to the digest';
if (preg_match('/SELECT\s+\*\s+FROM\s+(payment_collections|cash_ins|cash_expenses|crm_payment_retry|cb_ledger|fin_audit|staff_ledger)\b/i', $code)) $bad[] = 'SELECT * on a table';
if (!preg_match('/const PAGE_ROWS = (\d+);/', $code, $pr) || !preg_match_all('/LIMIT (\d+)/', $code, $lm) || count($lm[1]) !== 1 || $lm[1][0] !== $pr[1]) $bad[] = 'the L1 LIMIT is not PAGE_ROWS';
// every q() call: its SQL argument holds no fetched value, only literals, the table loop variable and the literal builders
$qn = 0;
for ($k = 1; $k < $n - 1; $k++) {
    if (!($tid($toks[$k]) === T_STRING && $toks[$k][1] === 'q' && $tv($toks[$k + 1]) === '(' && $tid($toks[$k - 1]) !== T_FUNCTION)) continue;
    $qn++; $d = 0; $comma = 0; $arg = [];
    for ($e = $k + 1; $e < $n; $e++) {
        $x = $tv($toks[$e]);
        if ($x === '(' || $x === '[') { $d++; if ($d === 1) continue; }
        if ($x === ')' || $x === ']') { $d--; if ($d === 0) break; }
        if ($d === 1 && $x === ',') { $comma++; continue; }
        if ($comma === 2) $arg[] = $toks[$e];
    }
    foreach ($arg as $x) {
        if ($tid($x) !== T_VARIABLE) continue;
        if (!in_array($x[1], ['$t', '$accJoin', '$sql', '$sel'], true)) $bad[] = 'SQL built from ' . $x[1] . ' (line ' . $x[2] . ')';
    }
}
if ($qn < 10) $bad[] = 'q() calls not found';
if (!preg_match('/foreach \(TABLES as \$t\) \{\s*if \(!in_array\(\$t, \$present, true\)\) continue;/', $code)) $bad[] = 'a table name not taken from the literal list after in_array';
if (!preg_match('/function J\(string \$shape, array \$cols, string \$f\): string \{.*?return in_array\(\$f, \$cols, true\) \?/s', $code)) $bad[] = 'a proper column not checked against pragma_table_info';
// ── R0: no variable call but $o(, no new $x ─────────────────────────────────────────────────────────────────────
if (preg_match('/new\s+\$/', $code)) $bad[] = 'new $x';
if (preg_match_all('/(?<![\w>:])\$(\w+)\s*\(/', $code, $vc)) foreach ($vc[1] as $v) if ($v !== 'o') $bad[] = 'a variable call $' . $v . '(';
// ── R0: what output calls may carry ─────────────────────────────────────────────────────────────────────────────
preg_match_all('/(?:\$o|\bout|\becho)\s*\(?(.*?)\);\s*$/m', $code, $oc);
foreach ($oc[1] as $arg) {
    if (preg_match('/ADJ_CENTS_SHA|digest|getMessage|\bsha1\(|\bhash\(|\$desc\b|\[\'(description|person|vref|nref|th|np|category|txn_type|sr|voided_by|void_reason)\'\]/', $arg, $mm)) $bad[] = 'an output carries ' . $mm[0];
}
if (preg_match_all('/ADJ_CENTS_SHA/', $code) < 2) $bad[] = 'ADJ_CENTS_SHA unused';
if ($bad) { echo implode('; ', array_unique($bad)), "\n"; exit(1); }
