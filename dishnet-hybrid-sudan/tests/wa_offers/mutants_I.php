<?php
declare(strict_types=1);
/**
 * Weakened copies judged by section I (5.18.104, root docs/73 §H.4.16 I): master's deadline not passed into the closure
 * (OM75's I half; its F half, the run given no deadline, is OM-F6). OM65 (the master entry without 'uganda') is stage
 * B10's, with master's four lines. TEST ONLY.
 */
$M = array_merge($M, [
    ['label' => 'OM75 master\'s deadline not passed into the closure', 'file' => 'cron/offers_send.php',
     'old' => '->runOnce($deadline);', 'new' => '->runOnce(null);',
     'sections' => 'I', 'cases' => 'I02', 'pattern' => '/I02 /'],
    // 5.18.104 (root docs/73 §H.4.16, OM65; ruling R1), stage B10: master's entry without 'uganda' — J's South Sudan run
    // dispatches it, and I's byte-for-byte read refuses the line
    ['label' => 'OM65 the master entry without \'uganda\'', 'file' => 'cron/master.php',
     'old' => "    'offers_send'      => ['interval' => 240, 'uganda' => true, 'flag' => 'offers_live_enabled',      'script'",
     'new' => "    'offers_send'      => ['interval' => 240, 'flag' => 'offers_live_enabled',                        'script'",
     'sections' => 'I,J', 'cases' => 'I03|J05', 'pattern' => '/I03 |J05 /'],
    ['label' => 'OM65b master without the flag rule (offers_send dispatched while offers_live_enabled is off)', 'file' => 'cron/master.php',
     'old' => "        if (!in_array(strtolower(trim((string)(\$_m_flagVal ?? ''))), ['1', 'true', 'on', 'yes'], true)) continue;\n", 'new' => "\n",
     'sections' => 'I', 'cases' => 'I04', 'pattern' => '/I04 /'],
]);
