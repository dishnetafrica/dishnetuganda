<?php
// srccheck.php <file> — the static rules for scripts/bank-recon-p0.php, read from its code with comments removed.
// Exit 0 when every rule holds; otherwise prints what broke and exits 1.
$s = (string)file_get_contents($argv[1]);
$code = '';
foreach (token_get_all($s) as $t) {
    if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
    $code .= is_array($t) ? $t[1] : $t;
}
$bad = [];
if (preg_match('/\b(require|require_once|include|include_once|eval)\b/i', $code)) $bad[] = 'loads code';
if (preg_match('/CashbookService|SqliteStore|PluginConfig|ConfigVault|MigrationRunner/', $code)) $bad[] = 'names plugin code';
if (preg_match_all('/new\s+PDO\s*\(/', $code) !== 1) $bad[] = 'not exactly one PDO open';
if (!preg_match("/new PDO\('sqlite:file:' \. \\\$db \. '\?immutable=1', null, null, \[\s*PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,\s*PDO::ATTR_TIMEOUT => 15,\s*PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,\s*\]\)/", $code)) {
    $bad[] = 'the open is not immutable and read-only';
}
preg_match_all('/->exec\(\s*([^)]*)\)/', $code, $m);
foreach ($m[1] as $e) if (!in_array(trim($e), ["'PRAGMA query_only = ON'", "'BEGIN'", "'ROLLBACK'"], true)) $bad[] = "exec({$e})";
if (preg_match_all('/->(query|prepare)\(/', $code) !== 1) $bad[] = 'a query outside q()';
$noExec = preg_replace('/->exec\(/', '->X(', $code);
if (preg_match('/\b(file_put_contents|fopen|fwrite|fputs|unlink|rename|copy|touch|mkdir|rmdir|chmod|chown|chgrp|tempnam|tmpfile|symlink|link|shell_exec|exec|system|passthru|proc_open|popen|pcntl_exec|mail|curl_init|fsockopen|stream_socket_client|file|ini_set|putenv|setcookie|header)\s*\(/i', $noExec, $mm)) {
    $bad[] = 'a writing, process or network function: ' . $mm[1];
}
if (strpos($code, '`') !== false) $bad[] = 'a backtick';
if ($bad) { echo implode('; ', $bad), "\n"; exit(1); }
