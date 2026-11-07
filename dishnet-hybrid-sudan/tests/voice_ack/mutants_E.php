<?php
declare(strict_types=1);
/**
 * Weakened copies for section E of tests/test_voice_ack.php (5.18.104, root docs/73 §H.3.24: T14's third form, A35's two
 * forms, V30's two forms, V45, and the two hooks' drains, VN-RB2). TEST ONLY. T14's first and second forms (a hook run
 * before its keys clear) are the ladder's order, stage B9's.
 */
$VA = 'lib/VoiceAck.php';
$AW = 'workers/AiReplyWorker.php';
$VS = 'lib/VoiceSteps.php';
$M = array_merge($M, [
    ['label' => 'T14c step 13\'s fresh read removed (the switch and the ledger on the worker\'s configuration)', 'file' => $VA,
     'old' => "            \$fresh = PluginConfig::read(dirname(__DIR__), \$this->dataDir);", 'new' => "            \$fresh = \$this->config;",
     'sections' => 'E', 'cases' => 'E01|E02', 'pattern' => '/E0[12] the worker built|E02 skipped/'],
    ['label' => 'A35a step 13\'s voice-mode re-test removed', 'file' => $VA,
     'old' => "        if (MediaPolicy::voiceMode(\$fresh, \$this->dataDirOrNull(), \$channel) === 'reply') { \$this->skip(\$d, 'p4_answers'); return; }\n", 'new' => '',
     'sections' => 'E', 'cases' => 'E03', 'pattern' => '/E03 p4_answers/'],
    ['label' => 'A35b step 13\'s voice-mode re-test made on the worker\'s configuration', 'file' => $VA,
     'old' => "        if (MediaPolicy::voiceMode(\$fresh, \$this->dataDirOrNull(), \$channel) === 'reply')",
     'new' => "        if (MediaPolicy::voiceMode(\$this->config, \$this->dataDirOrNull(), \$channel) === 'reply')",
     'sections' => 'E', 'cases' => 'E03', 'pattern' => '/E03 p4_answers/'],
    ['label' => 'V30a the R-13 re-check removed', 'file' => $AW,
     'old' => "        if (\$voiceTurn && !\$this->voiceReplyStillOn(\$channel)) {", 'new' => "        if (false) {",
     'sections' => 'E', 'cases' => 'E04', 'pattern' => '/E04 handed/'],
    ['label' => 'V30b the R-13 re-check made on the worker\'s configuration (B13-03)', 'file' => $AW,
     'old' => "    private function voiceReplyStillOn(string \$channel): bool\n    {\n        \$fresh = \$this->freshConfig();",
     'new' => "    private function voiceReplyStillOn(string \$channel): bool\n    {\n        \$fresh = \$this->config;",
     'sections' => 'E', 'cases' => 'E04', 'pattern' => '/E04 handed/'],
    ['label' => 'V45 the voice-measure hook settles rows in flight too', 'file' => $VS,
     'old' => "                                 WHERE kind = 'audio' AND status = 'pending'\");",
     'new' => "                                 WHERE kind = 'audio' AND status IN ('pending', 'fetching')\");",
     'sections' => 'E', 'cases' => 'E05', 'pattern' => '/E05 the pending row/'],
    ['label' => 'VN-RB2a the ack hook does not wait for the drain', 'file' => $VS,
     'old' => "            \$c['waited_s'] = self::drain(function () use (\$rw): int { return self::ackEvents(\$rw); });", 'new' => "            \$c['waited_s'] = 0;",
     'sections' => 'E', 'cases' => 'E01', 'pattern' => '/E01 the hook waited/'],
    ['label' => 'VN-RB2b the voice-measure hook does not drain the voice turns', 'file' => $VS,
     'old' => "            \$c['waited_s'] = self::drain(function () use (\$rw): int { return self::voiceTurnsPending(\$rw); });", 'new' => "            \$c['waited_s'] = 0;",
     'sections' => 'E', 'cases' => 'E05', 'pattern' => '/E05 the pending row/'],
]);
