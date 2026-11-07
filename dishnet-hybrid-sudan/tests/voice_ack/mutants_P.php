<?php
declare(strict_types=1);
/**
 * Weakened copies for section P of tests/test_voice_ack.php (5.18.104, root docs/73 §H.3.24: T17's VN-ON4 copy, T22's
 * two forms, T23's two forms, T25's VN-ON4 copy, T27's VN-ON4 copy, and the dead turn's hand-over). TEST ONLY.
 */
$VS = 'lib/VoiceSteps.php';
$M = array_merge($M, [
    ['label' => 'T23a VN-ON4 reading every done turn without an answer as FAIL', 'file' => $VS,
     'old' => "                self::inc(\$c, self::unansweredCause(\$ro, \$t, \$r));",
     'new' => "                self::inc(\$c, 'unanswered'); \$fail[] = 'unanswered';",
     'sections' => 'P', 'cases' => 'P01', 'pattern' => '/P01 VN-ON4 reads PASS/'],
    ['label' => 'T23b VN-ON4 taking only an Inbox reply or a Team row as a cause', 'file' => $VS,
     'old' => "                self::inc(\$c, self::unansweredCause(\$ro, \$t, \$r));",
     'new' => "                \$cz = self::unansweredCause(\$ro, \$t, \$r); self::inc(\$c, \$cz);\n"
            . "                if (!in_array(\$cz, ['meanwhile_row inbox_reply', 'meanwhile_row team_row'], true)) \$fail[] = 'unanswered';",
     'sections' => 'P', 'cases' => 'P01', 'pattern' => '/P01 VN-ON4 reads PASS/'],
    ['label' => 'VS-DEADH a dead turn read as FAIL though a person was told after it', 'file' => $VS,
     'old' => "if (\$told > 0) self::inc(\$c, 'dead_handed_over'); else", 'new' => "",
     'sections' => 'P', 'cases' => 'P01', 'pattern' => '/P01 with an ai_reply_worker/'],
    ['label' => 'T22a VN-ON4\'s P5 clause with the switch margin dropped', 'file' => $VS,
     'old' => "            if (UtcClock::parse((string)\$a['note_at']) < \$margin) { self::inc(\$c, 'inside_switch_margin'); continue; }\n", 'new' => '',
     'sections' => 'P', 'cases' => 'P02', 'pattern' => '/P02 three days on/'],
    ['label' => 'T22b VN-ON4\'s Scope judged over every turn, whenever its note was stored', 'edits' => [
        [$VS, "WHERE kind = 'audio' AND voice_rung = 'reply' AND created_at >= ? ORDER BY id\", [\$sinceS]) as \$r) {",
              "WHERE kind = 'audio' AND voice_rung = 'reply' AND created_at >= ? ORDER BY id\", ['1970-01-01 00:00:00']) as \$r) {"],
        [$VS, "            if (\$made < \$margin) {", "            if (\$made < \$margin && \$listed((string)\$r['channel'])) {"]],
     'sections' => 'P', 'cases' => 'P02', 'pattern' => '/P02 three days on/'],
    ['label' => 'T25b VN-ON4\'s dead-letter count dropped', 'file' => $VS,
     'old' => "            // A failure, handed over once its own event is decided.\n            if (\$r['status'] === 'dead' && \$r['failure_reason'] === 'switched_off') { self::inc(\$c, 'dead_switched_off'); continue; }\n",
     'new' => "            // A failure, handed over once its own event is decided.\n",
     'sections' => 'P', 'cases' => 'P03', 'pattern' => '/P03 VN-ON4 reads PASS/'],
    ['label' => 'T27c VN-ON4\'s Scope over every failed reply row, decided or not', 'file' => $VS,
     'old' => "            if (!self::mediaDecided(\$ro, (int)\$r['id'])) { self::inc(\$c, \$aiOff ? 'scope undecided ai_enabled_off' : 'scope undecided ai_enabled_on'); continue; }\n", 'new' => '',
     'sections' => 'P', 'cases' => 'P03', 'pattern' => '/P03 VN-ON4 reads PASS/'],
    ['label' => 'T17c VN-ON4\'s Answers judged from since, the event bound dropped', 'file' => $VS,
     'old' => "            if (\$made < \$bound) {", 'new' => "            if (\$made < \$since) {",
     'sections' => 'P', 'cases' => 'P04', 'pattern' => '/P04 PASS/'],
]);
