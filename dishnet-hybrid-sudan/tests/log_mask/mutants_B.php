<?php
declare(strict_types=1);
/**
 * Weakened copies for section B of tests/test_log_mask.php — 5.18.105 (root docs/74 §H.3.4 B). TEST ONLY.
 *
 *   B-w1  the report sent through the account handset instead of support: the sends and the rows move, so B01 must fail.
 *   B-w2  the masked line also drops the USD balance: more than the number moves in the log, so B02 must fail.
 */
$M[] = ['label' => 'B-w1 cron/staff_ssp_report.php: the report goes out on the account handset',
        'edits' => [['cron/staff_ssp_report.php',
            "\$notify->sendVia('support', \$phone, \$msg, 'staff_daily_report');",
            "\$notify->sendVia('account', \$phone, \$msg, 'staff_daily_report');"]],
        'sections' => 'B', 'cases' => '^B01 ', 'pattern' => '/B01 the Message Log rows/'];   // the fixture's support and account handsets share one instance, as production's do, so the sends' instance does not move; the row's sender does

$M[] = ['label' => 'B-w2 cron/staff_ssp_report.php: the masked line drops its USD balance as well',
        'edits' => [['cron/staff_ssp_report.php',
            'error_log("[staff_ssp_report] Sent to {$staffName}  SSP={$sspBal}  USD={$usdExposure}");',
            'error_log("[staff_ssp_report] Sent to {$staffName}  SSP={$sspBal}");']],
        'sections' => 'B', 'cases' => '^B02 ', 'pattern' => '/B02 the judged log/'];
