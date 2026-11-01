<?php
// Static check: which weakened copies of test_quote_from_ucrm can be planted on a tree (each anchor exactly once,
// applied in order as the runner applies them). Reads only; writes nothing.
$__ROOT = $argv[1]; $__TD = $__ROOT . "/tests";
$QFU_MUTANTS = [];
require $__TD . '/quote_from_ucrm/mutants.php';
foreach (glob($__TD . '/quote_from_ucrm/mutants_[A-Z].php') ?: [] as $__f) require $__f;
$bad = 0; $i = 0;
foreach ($QFU_MUTANTS as $m) {
    $i++;
    $edits = $m['edits'] ?? [[$m['file'], $m['old'], $m['new']]];
    $files = []; $why = '';
    foreach ($edits as $j => [$rel, $old, $new]) {
        if (!isset($files[$rel])) $files[$rel] = (string)@file_get_contents($__ROOT . "/" . $rel);
        $k = substr_count($files[$rel], $old);
        if ($k !== 1) { $why = 'anchor ' . ($j + 1) . " ($rel) found $k times"; break; }
        $files[$rel] = str_replace($old, $new, $files[$rel]);
    }
    if ($why !== '') { $bad++; printf("%3d UNPLANTABLE %s — %s\n", $i, $m['label'], $why); }
}
printf("%d copies, %d unplantable\n", count($QFU_MUTANTS), $bad);
