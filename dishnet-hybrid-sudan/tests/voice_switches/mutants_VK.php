<?php
declare(strict_types=1);
/**
 * The weakened copies of section VK of tests/test_voice_switches.php (5.18.104, root docs/73 §H.3.24 T2, T4, T5, T6, T7,
 * T9, T14; §H.2.18 IV-10), B9-fix1: Part V's own tool copies, the forms Part F's TL does not name. Each is a copy of the
 * plugin with one anchored edit, judged by VK run on the copy with the cases named. TEST ONLY.
 */
$VS = 'lib/VoiceSteps.php';
$LD = 'lib/SalesFollowupLadder.php';
$TO = 'tools/sales_followup.php';
$M = array_merge($M, [
    ['label' => 'T2 on voice-dry-run admitted without the Privacy Policy date', 'file' => $VS,
     'old' => "                if (!MediaPolicy::privacyLineOk(\$config)) return \$no('privacy_line_missing', ['ai_media_voice_privacy_line: not a date on or before today']);\n",
     'new' => '', 'sections' => 'VK', 'cases' => 'VK3'],
    ['label' => 'T2b on voice-dry-run admitted with a future Privacy Policy date (any date accepted)', 'file' => $VS,
     'old' => "                if (!MediaPolicy::privacyLineOk(\$config)) return \$no('privacy_line_missing',",
     'new' => "                if (trim((string)(\$config['ai_media_voice_privacy_line'] ?? '')) === '') return \$no('privacy_line_missing',",
     'sections' => 'VK', 'cases' => 'VK3'],
    ['label' => 'T2c on voice-dry-run admitted without the OpenAI key', 'file' => $VS,
     'old' => "                if (!MediaPolicy::keySet(\$config)) return \$no('key_not_set', ['openai key: not set']);\n",
     'new' => '', 'sections' => 'VK', 'cases' => 'VK3'],
    ['label' => 'T4 show prints the OpenAI key (in its key list)', 'file' => $TO,
     'old' => "    foreach (array_merge(SalesFollowupLadder::allKeys(), SalesFollowupLadder::settingKeys()) as \$k) {",
     'new' => "    foreach (array_merge(SalesFollowupLadder::allKeys(), SalesFollowupLadder::settingKeys(), ['openai_api_key']) as \$k) {",
     'sections' => 'VK', 'cases' => 'VK00'],
    ['label' => 'T5 off voice-measure leaves R2-R4 set', 'file' => $LD,
     'old' => "'check' => ['VN-ON1'], 'clears' => ['voice-dry-run', 'voice-staff', 'voice-reply'],", 'new' => "'check' => ['VN-ON1'], 'clears' => [],",
     'sections' => 'VK', 'cases' => 'VK2b'],
    ['label' => 'T6 (third form) on voice-reply admitted without --owner-approved', 'file' => $LD,
     'old' => "            if (!array_key_exists(\$name, \$opts) || trim((string)\$opts[\$name]) === '') return \$no('option_missing', \$step . ' needs --' . \$name);",
     'new' => "            if (!array_key_exists(\$name, \$opts) || trim((string)\$opts[\$name]) === '') { if (\$name === 'owner-approved') continue; return \$no('option_missing', \$step . ' needs --' . \$name); }",
     'sections' => 'VK', 'cases' => 'VK3'],
    ['label' => 'T7 --check writes (a Part V check changing a row through $ro)', 'file' => $VS,
     'old' => "        if (!isset(self::CHECKS[\$id])) return self::res('FAIL unknown_check', [], []);\n        \$def = self::CHECKS[\$id];",
     'new' => "        if (!isset(self::CHECKS[\$id])) return self::res('FAIL unknown_check', [], []);\n        \$ro->exec('PRAGMA query_only = OFF'); \$ro->exec('UPDATE _schema_checks SET found = found WHERE rowid = (SELECT MIN(rowid) FROM _schema_checks)');\n        \$def = self::CHECKS[\$id];",
     'sections' => 'VK', 'cases' => 'VK3'],
    ['label' => 'T9 a config key read in none of the ladder\'s lists, planted in a new Part V file (lib/VoiceAck.php)', 'file' => 'lib/VoiceAck.php',
     'old' => "final class VoiceAck\n", 'new' => "function t9_planted(array \$config) { return \$config['t9_planted_key'] ?? null; }   // planted (test)\nfinal class VoiceAck\n",
     'sections' => 'VK', 'cases' => 'VK1'],
    ['label' => 'T9b a config key read in none of the ladder\'s lists, planted in a Part V hunk of a changed file (evo_webhook.php)', 'file' => 'evo_webhook.php',
     'old' => "            \$vMedia = InboundMedia::fromEvoMessage(\$msg);\n",
     'new' => "            \$vMedia = InboundMedia::fromEvoMessage(\$msg);\n            \$t9Planted = \$config['t9_planted_key'] ?? null;   // planted (test)\n",
     'sections' => 'VK', 'cases' => 'VK1'],
    ['label' => 'T14 (step-5 form) step 5\'s settle run before the media keys clear', 'file' => $LD,
     'old' => "'clears' => ['voice-dry-run', 'voice-staff', 'voice-reply'], 'hook' => ['V', 'after', 'always'],",
     'new' => "'clears' => ['voice-dry-run', 'voice-staff', 'voice-reply'], 'hook' => ['V', 'before', 'always'],",
     'sections' => 'VK', 'cases' => 'VK2b'],
]);
unset($VS, $LD, $TO);
