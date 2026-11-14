<?php
declare(strict_types=1);
/**
 * Weakened copies for section D of tests/test_log_mask.php — 5.18.105 (root docs/74 §H.3.4 "Weakened copies", D1-D7).
 * TEST ONLY.
 *
 *   D1-w   an undeclared runtime file changed (lib/MailService.php's header): D1 fails.
 *   D2-w   a planted migration (migrations/092_lm_probe.sql): D2 fails, and 091 is no longer last.
 *   D3-w   tools/sales_followup.php touched (its usage line): D3 fails.
 *   D4-w1  an unmarked 5.18.105 hunk (cron_lead_alerts.php's head, 90 lines above its first marker; asserted planted
 *          outside every marker's window): D4 fails, and taken out the tree is not 994d660's.
 *   D4-w2  a hunk that wears 5.18.104's marker in its own added lines (LN-30's marker line): D4 fails.
 *   D5-w   manifest.json changed beyond its version line: D5 fails.
 *   D6-w   a new $config read inside a masked hunk (LN-02's line, still one log statement): D6 fails.
 *   D7-w   a non-log code change inside a masked hunk (LN-02's): D7 fails.
 */
$lmLn02 = 'error_log("[staff_ssp_report] Sent to {$staffName}  SSP={$sspBal}  USD={$usdExposure}");';

$M[] = ['label' => 'D1-w lib/MailService.php: an undeclared runtime file changed',
        'edits' => [['lib/MailService.php', ' * MailService — single source of truth for outbound email.', ' * MailService — single source of truth for outbound email. (weakened copy, test)']],
        'sections' => 'D', 'cases' => '^D1 ', 'pattern' => '/D1 against/'];

$M[] = ['label' => 'D2-w migrations/092_lm_probe.sql: a planted migration',
        'files' => ['migrations/092_lm_probe.sql' => "-- weakened copy (test_log_mask): a planted migration\nSELECT 1;\n"],
        'sections' => 'D', 'cases' => '^D2 ', 'patterns' => ['/D2 migrations\//', '/D2 … and 091 is the last/']];

$M[] = ['label' => 'D3-w tools/sales_followup.php: a held file touched',
        'edits' => [['tools/sales_followup.php',
            ' *   php tools/sales_followup.php on <step> [--opt value | --opt=value] [--accept-not-yet "<reason>"]',
            ' *   php tools/sales_followup.php on <step> [--opt value | --opt=value] [--accept-not-yet "<reason>"] (weakened copy, test)']],
        'sections' => 'D', 'cases' => '^D3 ', 'pattern' => '/D3 the /'];

$M[] = ['label' => 'D4-w1 cron_lead_alerts.php: an unmarked hunk',
        'edits' => [['cron_lead_alerts.php', "\$config = \$store->load('kyc_config.json') ?? [];\n",
                     "\$config = \$store->load('kyc_config.json') ?? [];   // planted, unmarked (weakened copy, test)\n"]],
        'unmarked' => ['cron_lead_alerts.php'],
        'sections' => 'D', 'cases' => '^D4 ', 'patterns' => ['/D4 every one of/', '/D4 the judged tree/']];

$M[] = ['label' => 'D4-w2 cron_lead_alerts.php: a hunk wearing 5.18.104\'s marker',
        'edits' => [['cron_lead_alerts.php', '// 5.18.105 (root docs/74 §H.1.16, LN-30): the lead id;',
                     '// 5.18.105 (root docs/74 §H.1.16, LN-30) and 5.18.104 (root docs/73 §H.2.14): the lead id;']],
        'sections' => 'D', 'cases' => '^D4 ', 'pattern' => '/D4 no hunk carries 5\.18\.104/'];

$M[] = ['label' => 'D5-w manifest.json: a second line changed',
        'edits' => [['manifest.json', '    "displayName": "DishNet Hybrid — Sudan Edition",', '    "displayName": "DishNet Hybrid — Sudan Edition (test)",']],
        'sections' => 'D', 'cases' => '^D5 ', 'pattern' => '/D5 /'];

$M[] = ['label' => 'D6-w cron/staff_ssp_report.php: a new $config read inside LN-02\'s hunk',
        'edits' => [['cron/staff_ssp_report.php', $lmLn02,
            'error_log("[staff_ssp_report] Sent to {$staffName}  SSP={$sspBal}  USD={$usdExposure}" . (empty($config[\'lm_log_suffix\']) ? \'\' : \' (test)\'));']],
        'sections' => 'D', 'cases' => '^D6 ', 'pattern' => '/D6 TL14/'];

$M[] = ['label' => 'D7-w cron/staff_ssp_report.php: a non-log code change inside LN-02\'s hunk',
        'edits' => [['cron/staff_ssp_report.php', $lmLn02, "\$skipped += 0;\n        " . $lmLn02]],
        'sections' => 'D', 'cases' => '^D7 ', 'pattern' => '/D7 every added/'];

unset($lmLn02);
