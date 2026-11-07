<?php
declare(strict_types=1);
/**
 * Weakened copies for section M of tests/test_voice_ack.php (5.18.104, root docs/73 §H.3.24: T15's VN-ON1 copy, T16,
 * T28, and one each for the fetch rate's retry rule and the processing lease). TEST ONLY.
 */
$VS = 'lib/VoiceSteps.php';
$M = array_merge($M, [
    ['label' => 'T15a VN-ON1 counting every row\'s calls, not its own rung\'s', 'file' => $VS,
     'old' => "\$calls = (int)self::one(\$ro, \"SELECT COUNT(*) FROM wa_voice_calls WHERE voice_rung = 'fetch_only' AND created_at >= ?\", [\$sinceS]);",
     'new' => "\$calls = (int)self::one(\$ro, \"SELECT COUNT(*) FROM wa_voice_calls WHERE created_at >= ?\", [\$sinceS]);",
     'sections' => 'M', 'cases' => 'M02', 'pattern' => '/M02 VN-ON1 reads PASS/'],
    ['label' => 'T16 VN-ON1 judging ai_media\'s last duration_ms (FAIL above 60000)', 'file' => $VS,
     'old' => "        \$notes[] = self::durationNote(\$ro);\n",
     'new' => "        \$notes[] = self::durationNote(\$ro);\n        if (preg_match('/duration_ms (\\d+)/', (string)end(\$notes), \$mm) && (int)\$mm[1] > 60000) \$fail[] = 'run_time';\n",
     'sections' => 'M', 'cases' => 'M01', 'pattern' => '/M01 PASS each time/'],
    ['label' => 'T28 VN-ON1 judging its fetch_only rows\' calls by media_id alone', 'file' => $VS,
     'old' => "\"SELECT COUNT(*) FROM wa_voice_calls WHERE voice_rung = 'fetch_only' AND created_at >= ?\"",
     'new' => "\"SELECT COUNT(*) FROM wa_voice_calls WHERE media_id IN (SELECT id FROM wa_media WHERE voice_rung = 'fetch_only') AND created_at >= ?\"",
     'sections' => 'M', 'cases' => 'M03', 'pattern' => '/M03 two days on/'],
    ['label' => 'VS-RETRY a failed fetch with a retry still to come counted against the rate', 'file' => $VS,
     'old' => "            if (\$r['status'] === 'failed' && (int)\$r['attempts'] < 3) continue;          // a retry still to come\n", 'new' => '',
     'sections' => 'M', 'cases' => 'M04', 'pattern' => '/M04 20 of 22/'],
    ['label' => 'VS-LEASE any ai.media event processing read as left there', 'file' => $VS,
     'old' => "AND (locked_at IS NULL OR locked_at < ?)\", [UtcClock::stamp(\$now - 120)]);", 'new' => "AND ? <> ''\", [UtcClock::stamp(\$now - 120)]);",
     'sections' => 'M', 'cases' => 'M05', 'pattern' => '/M05 a lease 30 s old/'],
]);
