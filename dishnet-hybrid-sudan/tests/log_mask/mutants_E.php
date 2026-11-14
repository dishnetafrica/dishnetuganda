<?php
declare(strict_types=1);
/**
 * Weakened copies for section E of tests/test_log_mask.php — 5.18.105 (root docs/74 §H.1.0 "E's weakened copies",
 * §H.3.4 "Weakened copies"). TEST ONLY. (tail() and mask() stubbed are section A's copies, judged by A and E:
 * tests/log_mask/mutants_A.php.)
 *
 *   E-w1  a new error_log naming $phone inside a 5.18.105 hunk (LN-02's, the marker one line above): E1 and E2 fail.
 *   E-w2  the same outside every 5.18.105 hunk (cron_lead_alerts.php's head, 90 lines above its first marker; asserted
 *         planted outside every marker's window): E2 fails.
 *   E-w3  one K site's entry taken out of E2's list (K-03, in the copy's tests/log_mask/e2_allow.php): E2 fails.
 *   E-w4  a listed statement's fixed words changed in the copy of its file (K-05's win-back line): its entry matches
 *         nothing and the statement is unlisted, so E2 fails on both counts.
 */
$M[] = ['label' => 'E-w1 cron/staff_ssp_report.php: a new error_log naming $phone inside LN-02\'s hunk',
        'edits' => [['cron/staff_ssp_report.php',
            'error_log("[staff_ssp_report] Sent to {$staffName}  SSP={$sspBal}  USD={$usdExposure}");',
            'error_log("[staff_ssp_report] Sent to {$staffName}  SSP={$sspBal}  USD={$usdExposure}");' . "\n        error_log('[staff_ssp_report] to ' . \$phone);"]],
        'sections' => 'E', 'cases' => '^E[12] ', 'patterns' => ['/E1 no log statement/', '/E2 every log statement/']];

$M[] = ['label' => 'E-w2 cron_lead_alerts.php: a new error_log naming $phone outside every 5.18.105 hunk',
        'edits' => [['cron_lead_alerts.php',
            "\$config = \$store->load('kyc_config.json') ?? [];\n",
            "\$config = \$store->load('kyc_config.json') ?? [];\nerror_log('[lead_alerts] start for ' . \$phone);\n"]],
        'unmarked' => ['cron_lead_alerts.php'],
        'sections' => 'E', 'cases' => '^E2 ', 'pattern' => '/E2 every log statement/'];

$M[] = ['label' => 'E-w3 tests/log_mask/e2_allow.php: K-03\'s entry taken out of E2\'s list',
        'edits' => [['tests/log_mask/e2_allow.php',
            "    ['file' => 'lib/FiberInstallService.php', 'k' => 'K-03', 'line' => 318, 'reason' => '(b) Delivery note skipped (K-03)',\n"
            . "     'fixed' => ['[FiberInstall] Delivery note skipped for job #', '— no KYC app found for phone']],\n",
            '']],
        'sections' => 'E', 'cases' => '^E2 ', 'pattern' => '/E2 every log statement/'];

$M[] = ['label' => 'E-w4 cron_maintenance.php: K-05\'s win-back line no longer reads as its entry',
        'edits' => [['cron_maintenance.php', 'mlog("Win-back sent: {$custName}', 'mlog("Win-back mailed: {$custName}']],
        'sections' => 'E', 'cases' => '^E2 ', 'patterns' => ['/E2 every log statement/', '/E2 every entry/']];
