<?php
declare(strict_types=1);
/**
 * Weakened copies for section A of tests/test_voice_media.php (5.18.104, root docs/73 §H.3.24: V1-V6, T8). Each a
 * one-fault copy of lib/MediaPolicy.php that section A must catch. Appended to $M by the runner. TEST ONLY.
 */
$P = 'lib/MediaPolicy.php';
$M = array_merge($M, [
    ['label' => 'V1 MediaPolicy::enabled without StaffJobsGate', 'file' => $P,
     'old' => '        return StaffJobsGate::applies($config, $dataDir);', 'new' => '        return true;',
     'sections' => 'A', 'cases' => 'A02', 'pattern' => '/A02 /'],
    ['label' => 'V2 voiceEnabled ignoring ai_media_enabled', 'file' => $P,
     'old' => "        return self::enabled(\$config, \$dataDir)\n            && self::flag(\$config['ai_media_voice'] ?? null)",
     'new' => "        return (bool)(\$config['ai_enabled'] ?? false)\n            && self::flag(\$config['ai_media_voice'] ?? null)",
     'sections' => 'A', 'cases' => 'A03', 'pattern' => '/A03 /'],
    ['label' => 'V3 voiceEnabled ignoring the privacy date', 'file' => $P,
     'old' => "            && self::privacyLineOk(\$config)\n", 'new' => '',
     'sections' => 'A', 'cases' => 'A04', 'pattern' => '/A04 /'],
    ['label' => 'V4 voiceEnabled ignoring whether the key is set', 'file' => $P,
     'old' => "            && self::keySet(\$config);", 'new' => '            ;',
     'sections' => 'A', 'cases' => 'A04', 'pattern' => '/A04 /'],
    ['label' => 'V5 voiceReplyEnabled without the hand-over rung', 'file' => $P,
     'old' => "        return self::voiceHandoverEnabled(\$config, \$dataDir)\n            && self::flag(\$config['ai_media_voice_reply'] ?? null)",
     'new' => "        return self::voiceEnabled(\$config, \$dataDir)\n            && self::flag(\$config['ai_media_voice_reply'] ?? null)",
     'sections' => 'A', 'cases' => 'A05', 'pattern' => '/A05 /'],
    ['label' => 'V6 voiceReplyEnabled ignoring the number list', 'file' => $P,
     'old' => "            && self::channelListed(\$config['ai_media_voice_reply_channels'] ?? null, \$channel);", 'new' => '            ;',
     'sections' => 'A', 'cases' => 'A05', 'pattern' => '/A05 /'],
    ['label' => 'T8 voiceReplyEnabled ignoring its own switch ai_media_voice_reply (S2-11)', 'file' => $P,
     'old' => "            && self::flag(\$config['ai_media_voice_reply'] ?? null)\n", 'new' => '',
     'sections' => 'A', 'cases' => 'A05', 'pattern' => '/A05 /'],
    ['label' => 'MP-DATE a privacy date after today accepted', 'file' => $P,
     'old' => '        return strcmp($s, $today) <= 0;', 'new' => '        return true;',
     'sections' => 'A', 'cases' => 'A04|A05', 'pattern' => '/A0[45] /'],
    ['label' => 'MP-CLAMP a planted timeout above set\'s range honoured', 'file' => $P,
     'old' => 'self::VOICE_MIN_TIMEOUT_S, self::VOICE_CAP_TIMEOUT_S);', 'new' => 'self::VOICE_MIN_TIMEOUT_S, 9999);',
     'sections' => 'A', 'cases' => 'A06', 'pattern' => '/A06 /'],
]);
