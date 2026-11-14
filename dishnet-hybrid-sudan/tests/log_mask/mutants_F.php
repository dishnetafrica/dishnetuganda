<?php
declare(strict_types=1);
/**
 * Weakened copies for section F of tests/test_log_mask.php — 5.18.105 (root docs/74 §H.3.4 F, §H.1.23). TEST ONLY.
 *
 *   F-w  str_pad back at the report's line 707 (994d660's cells, padded by bytes): F01 must fail on the ••••NN rows, the
 *        — rows and the NEVER_WROTE row, each its own assertion. (F02 still holds: the JSON is the same; that is the point
 *        of the fix — only the text's columns move.)
 */
$M[] = ['label' => 'F-w tools/sales_followup_report.php: str_pad back at line 707',
        'edits' => [['tools/sales_followup_report.php',
            "\$sfrSay('  ' . \$sfrPad((string)\$sfrN, 46) . \$sfrPad(\$sfrRole, 9) . \$sfrPad(\$sfrOwn, 13) . \$sfrPad(\$sfrLine, 7)",
            "\$sfrSay('  ' . str_pad(\$sfrN, 46) . str_pad(\$sfrRole, 9) . str_pad(\$sfrOwn, 13) . str_pad(\$sfrLine, 7)"]],
        'sections' => 'F', 'cases' => '^F01 ',
        'patterns' => ['/F01 every BY NUMBER row whose line is ••••NN/', '/F01 every BY NUMBER row whose line is —/', '/F01 the NEVER_WROTE row/']];
