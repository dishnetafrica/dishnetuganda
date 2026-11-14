<?php
declare(strict_types=1);
/**
 * Weakened copies for section A of tests/test_log_mask.php — 5.18.105 (root docs/74 §H.1.0 "The weakened copy, LN-nn-w",
 * §H.3.4 "Weakened copies"). TEST ONLY. Appended to $M; run by lm_mutants() (tests/log_mask/_lib.php).
 *
 *   LN-nn-w  35 copies, one per LN: 994d660's line put back by its anchor (LM_LINES 'new' found once, replaced once by
 *            'old'); the group the line is driven in runs alone on the copy and must fail that LN's A2.
 *   LN-b     19 controls, one per log file: the file replaced whole by 994d660's own, from Git (§H.1.0 "Each test also runs
 *            once on 994d660's own file as a control, where A2 must fail"); every LN of the file must fail its A2.
 *   tail()   PluginLog::tail() stubbed to return its argument: A00 tail, LN-17's drive (A2 and A3) and E3 must fail.
 *   mask()   PluginLog::mask() stubbed to return its input: A00 mask, the two lines that mask centrally (LN-03, LN-24: A3)
 *            and E3 must fail.
 */

/** [LN, the case regex of the group it is driven in]: the 35 line copies. */
foreach ([
    'LN-01' => '^G01 ', 'LN-02' => '^G02 ', 'LN-03' => '^G03 ', 'LN-04' => '^G04 ', 'LN-05' => '^G05 ', 'LN-06' => '^G06 ',
    'LN-07' => '^G07 ', 'LN-08' => '^G08 ', 'LN-09' => '^G09 ', 'LN-10' => '^G09 ', 'LN-11' => '^G10 ', 'LN-12' => '^G10 ',
    'LN-13' => '^G10 ', 'LN-14' => '^G10 ', 'LN-15' => '^G11 ', 'LN-16' => '^G12 ', 'LN-17' => '^G12 ', 'LN-18' => '^G12 ',
    'LN-19' => '^G12 ', 'LN-20' => '^G13 ', 'LN-21' => '^G12 ', 'LN-22' => '^G12 ', 'LN-23' => '^G14 ', 'LN-24' => '^G15 ',
    'LN-25' => '^G16 ', 'LN-26' => '^G17 ', 'LN-27' => '^G17 ', 'LN-28' => '^G18 ', 'LN-29' => '^G18 ', 'LN-30' => '^G19 ',
    'LN-31' => '^G19 ', 'LN-32' => '^G19 ', 'LN-33' => '^G20 ', 'LN-34' => '^G20 ', 'LN-35' => '^G21 ',
] as $lmLn => $lmCases) {
    $M[] = lm_line_copy($lmLn, $lmCases);
}

/** The 19 log files on 994d660's own text: each group of the file runs, and every LN of the file fails its A2. */
$lmByFile = [];
foreach (LM_LINES as $lmLn => $lmRow) $lmByFile[$lmRow['file']][] = $lmLn;
foreach ($lmByFile as $lmFile => $lmLns) {
    $lmGroups = [];
    foreach ($lmLns as $lmLn) $lmGroups[lm_group_of($lmLn)] = true;
    $M[] = ['label' => 'LN-b ' . $lmFile . ': 994d660\'s own file (' . implode(', ', $lmLns) . ')', 'base' => [$lmFile],
            'sections' => 'A', 'cases' => '^(' . implode('|', array_keys($lmGroups)) . ') ',
            'patterns' => array_map(function ($ln) { return '/' . preg_quote($ln, '/') . ' A2 /'; }, $lmLns)];
}
unset($lmByFile, $lmFile, $lmLns, $lmGroups, $lmLn, $lmRow, $lmCases);

$M[] = ['label' => 'tail() lib/PluginLog.php: PluginLog::tail() returns its argument',
        'edits' => [['lib/PluginLog.php',
            "        \$d = (string)preg_replace('/\\D+/', '', (string)\$number);\n        return \$d === '' ? 'none' : '••••' . substr(\$d, -2);",
            "        return (string)\$number;   // weakened copy (test_log_mask): tail() stubbed"]],
        'sections' => 'A,E', 'cases' => '^(A00 tail|G12 |E3 )',
        'patterns' => ['/A00 tail\(\): every form/', '/LN-17 A2 /', '/LN-17 A3 /', '/E3 tail\(\)/']];

$M[] = ['label' => 'mask() lib/PluginLog.php: PluginLog::mask() returns its input',
        'edits' => [['lib/PluginLog.php',
            "        \$s = mb_scrub(\$s, 'UTF-8');",
            "        return \$s;   // weakened copy (test_log_mask): mask() stubbed\n        \$s = mb_scrub(\$s, 'UTF-8');"]],
        'sections' => 'A,E', 'cases' => '^(A00 mask|G03 |G15 |E3 )',
        'patterns' => ['/A00 mask\(\): every form/', '/LN-03 A3 /', '/LN-24 A3 /', '/E3 mask\(\)/']];
