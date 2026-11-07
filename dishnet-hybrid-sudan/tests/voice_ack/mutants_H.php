<?php
declare(strict_types=1);
/**
 * Weakened copies for section H of tests/test_voice_ack.php (5.18.104, root docs/73 §H.3.24: A33, and one for each of
 * section H's text rules). TEST ONLY. A33's named argument lints on this runtime (PHP 8), so H01's token scan is what
 * catches it, as php -l under 7.4 would.
 */
$VA = 'lib/VoiceAck.php';
$M = array_merge($M, [
    ['label' => 'A33 the hand-over call with a named argument', 'file' => $VA,
     'old' => "                    true, \$this->log, self::SOURCE, \$this->owner(\$channel));",
     'new' => "                    true, \$this->log, self::SOURCE, owner: \$this->owner(\$channel));",
     'sections' => 'H', 'cases' => 'H01', 'pattern' => '/H01 none/'],
    ['label' => 'VA-AUDIO a plugin path that sends audio', 'file' => $VA,
     'old' => "    private function settleDecision(int \$id,",
     'new' => "    private function audioReply(): void { \$this->evo->sendMedia('sales', '256700000000', 'audio', 'x'); }\n\n    private function settleDecision(int \$id,",
     'sections' => 'H', 'cases' => 'H02', 'pattern' => '/H02 no sendAudio/'],
    ['label' => 'VA-SEND2 a second send in lib/VoiceAck.php, outside acknowledge', 'file' => $VA,
     'old' => "    private function settleDecision(int \$id,",
     'new' => "    private function nudge(string \$c, string \$p): void { \$this->evo->sendText(\$c, \$p, 'x', ContactOptOut::CLASS_REPLY); }\n\n    private function settleDecision(int \$id,",
     'sections' => 'H', 'cases' => 'H03', 'pattern' => '/H03 /'],
    ['label' => 'VA-NUM an acknowledgement limit written beside voice_ack outside VoiceAckLimits', 'file' => $VA,
     'old' => "        \$now     = (int)(\$this->now)();\n",
     'new' => "        \$now     = (int)(\$this->now)();\n        \$cap = ['voice_ack' => 25];\n",
     'sections' => 'H', 'cases' => 'H04', 'pattern' => '/H04 no 7/'],
    ['label' => 'VS-CONN VoiceSteps opens a connection of its own', 'file' => 'lib/VoiceSteps.php',
     'old' => "        if (!isset(self::CHECKS[\$id])) return self::res('FAIL unknown_check', [], []);",
     'new' => "        if (!isset(self::CHECKS[\$id])) return self::res('FAIL unknown_check', [], []);\n        if (\$dataDir === '\\0') \$ro = SqliteStore::create(\$dataDir)->getPdo();",
     'sections' => 'H', 'cases' => 'H05', 'pattern' => '/H05 /'],
    ['label' => 'VA-MARK a hunk without its marker', 'file' => 'includes/api/api_whatsapp.php',
     'old' => "            'messages'     => \$_msgs,   // 5.18.104 (root docs/73 §H.3.11): the messages read above, a transcript added on Uganda",
     'new' => "            'messages'     => \$_msgs,",
     'sections' => 'H', 'cases' => 'H06', 'pattern' => '/H06 each hunk/'],
]);
