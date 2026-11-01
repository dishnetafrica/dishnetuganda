<?php
// The labels of test_quote_from_ucrm's weakened copies not yet caught in a log ("ok   <label> is caught by …"),
// as one anchored regex for --mutants. Reads only.
$__ROOT = $argv[1]; $__LOG = $argv[2]; $__TD = $__ROOT . '/tests';
$QFU_MUTANTS = [];
require $__TD . '/quote_from_ucrm/mutants.php';
foreach (glob($__TD . '/quote_from_ucrm/mutants_[A-Z].php') ?: [] as $__f) require $__f;
$done = [];
foreach (file($__LOG, FILE_IGNORE_NEW_LINES) as $l)
    if (preg_match('/^\s*ok\s+(.*) is caught by [A-Z](, [A-Z])*$/', $l, $mm)) $done[$mm[1]] = true;
$left = []; $n = 0;
foreach ($QFU_MUTANTS as $m) { if (!isset($done[$m['label']])) { $left[] = $m['label']; } else $n++; }
fwrite(STDERR, sprintf("%d copies, %d caught in the log, %d left (%d distinct labels left)\n", count($QFU_MUTANTS), $n, count($left), count(array_unique($left))));
echo '^(?:' . implode('|', array_map(function ($s) { return preg_quote($s, '/'); }, array_unique($left))) . ')$';
