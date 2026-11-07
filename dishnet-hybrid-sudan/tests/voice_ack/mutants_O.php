<?php
declare(strict_types=1);
/**
 * Weakened copies for section O of tests/test_voice_ack.php (5.18.104, root docs/73 §H.3.24: T15's VN-ON3 copy, T17's
 * VN-ON3 copy, T21, T24, T25's VN-ON3 copy, T27's VN-ON3 copy). TEST ONLY.
 */
$VS = 'lib/VoiceSteps.php';
$M = array_merge($M, [
    ['label' => 'T21 VN-ON3 judging a holding line against every acknowledgement of its conversation, whenever stored', 'file' => $VS,
     'old' => "            if (UtcClock::parse((string)\$a['created_at']) < \$upd) return true;", 'new' => "            return true;",
     'sections' => 'O', 'cases' => 'O01', 'pattern' => '/O01 seven days on/'],
    ['label' => 'T24 VN-ON3 giving every holding line to the latest escalation, no ai_reply_worker competitor counted', 'file' => $VS,
     'old' => "            if (\$ai > 0) { self::inc(\$c, 'ambiguous_line ai_reply_worker'); continue; }\n", 'new' => '',
     'sections' => 'O', 'cases' => 'O02', 'pattern' => '/O02 VN-ON3 reads PASS/'],
    ['label' => 'T27b VN-ON3\'s Hand-overs over every handover row, decided or not, no media_disabled count', 'edits' => [
        [$VS, "            if (\$r['status'] === 'skipped' && \$r['failure_reason'] === 'media_disabled') { self::inc(\$c, 'media_disabled'); continue; }\n", ''],
        [$VS, "            if (!self::mediaDecided(\$ro, (int)\$r['id'])) { self::inc(\$c, \$aiOff ? 'undecided ai_enabled_off' : 'undecided ai_enabled_on'); continue; }\n", '']],
     'sections' => 'O', 'cases' => 'O03', 'pattern' => '/O03 seven days on/'],
    ['label' => 'T25a VN-ON3\'s dead-letter count dropped', 'file' => $VS,
     'old' => "{ self::inc(\$c, 'media_disabled'); continue; }\n            if (\$r['status'] === 'dead' && \$r['failure_reason'] === 'switched_off') { self::inc(\$c, 'dead_switched_off'); continue; }\n",
     'new' => "{ self::inc(\$c, 'media_disabled'); continue; }\n",
     'sections' => 'O', 'cases' => 'O04', 'pattern' => '/O04 VN-ON3 reads PASS/'],
    ['label' => 'T17b VN-ON3\'s Hand-overs judged from since, the event bound dropped', 'file' => $VS,
     'old' => "            if (UtcClock::parse((string)\$r['created_at']) < \$bound) { self::inc(\$c, 'outside_event_window'); continue; }",
     'new' => "            if (UtcClock::parse((string)\$r['created_at']) < \$since) { self::inc(\$c, 'outside_event_window'); continue; }",
     'sections' => 'O', 'cases' => 'O05', 'pattern' => '/O05 PASS/'],
    ['label' => 'T15c VN-ON3 counting every changed body, not its own rung\'s', 'file' => $VS,
     'old' => "        // Holding lines (R-19), over the handover rows inside the event window.\n",
     'new' => "        foreach (self::all(\$ro, \"SELECT * FROM wa_media WHERE kind = 'audio' AND created_at >= ?\", [\$sinceS]) as \$x) {\n"
            . "            if (self::bodyChanged(\$ro, \$x)) { self::inc(\$c, 'r3_acted'); \$fail[] = 'r3_acted'; }\n        }\n"
            . "        // Holding lines (R-19), over the handover rows inside the event window.\n",
     'sections' => 'O', 'cases' => 'O06', 'pattern' => '/O06 VN-ON3 reads PASS/'],
]);
