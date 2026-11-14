<?php
declare(strict_types=1);
/**
 * Weakened copies for section C of tests/test_log_mask.php — 5.18.105 (root docs/74 §H.3.4 C, "a W2 string altered").
 * TEST ONLY.
 *
 *   C-w1  `$phone` renamed in the WaSeenMarks::note call (994d660:300): C01 and C03 must fail (the design's copy).
 *   C-w2  the other string altered (`$text === ''` → `$text == ''`, :298): C01 and C03 must fail.
 */
$M[] = ['label' => 'C-w1 evo_webhook.php: $phone renamed in the W2 WaSeenMarks::note call',
        'edits' => [['evo_webhook.php',
            "WaSeenMarks::note(\$pdo, \$config, \$dataDir, 'in', \$phone,",
            "WaSeenMarks::note(\$pdo, \$config, \$dataDir, 'in', \$phoneSeen,"]],
        'sections' => 'C', 'cases' => '^C0[13] ', 'patterns' => ['/C01 /', '/C03 on chase/']];

$M[] = ['label' => 'C-w2 evo_webhook.php: the W2 condition altered',
        'edits' => [['evo_webhook.php', "\$convId === null && \$text === ''", "\$convId === null && \$text == ''"]],
        'sections' => 'C', 'cases' => '^C0[13] ', 'patterns' => ['/C01 /', '/C03 on chase/']];
