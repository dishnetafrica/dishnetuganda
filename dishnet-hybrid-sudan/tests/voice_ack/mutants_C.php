<?php
declare(strict_types=1);
/**
 * Weakened copies for section C of tests/test_voice_ack.php (5.18.104, root docs/73 §H.3.24: A10, A11, A26 and its
 * control on the control, A32, and step 10's hand-over line). TEST ONLY.
 */
$VA = 'lib/VoiceAck.php';
$M = array_merge($M, [
    ['label' => 'A10 the human pause rule removed', 'file' => $VA,
     'old' => "        if (\$this->humanPauseRemaining(\$convId, \$now) > 0) { \$this->skip(\$d, 'human_active'); return; }", 'new' => '',
     'sections' => 'C', 'cases' => 'C01', 'pattern' => '/C01 pause running/'],
    ['label' => 'A11 C7 replaced by humanRepliedSince', 'file' => $VA,
     'old' => "        if (HumanTakeover::since(\$this->pdo, \$phone, [\$convId], UtcClock::stamp(\$recv - self::C7_BACK_S)) !== null) {",
     'new' => "        if (\$this->convSvc->humanRepliedSince(\$convId, UtcClock::stamp(\$recv - self::C7_BACK_S))) {",
     'sections' => 'C', 'cases' => 'C02', 'pattern' => '/C02 /'],
    ['label' => 'A26 W1\'s marks ignored by C7', 'file' => 'lib/HumanTakeover.php',
     'old' => "        if (\$hasMarks) {\n            \$st = \$pdo->query(\"SELECT DISTINCT channel", 'new' => "        if (false) {\n            \$st = \$pdo->query(\"SELECT DISTINCT channel",
     'sections' => 'C', 'cases' => 'C03', 'pattern' => '/C03 person_active/'],
    ['label' => 'A26-CTRL Part F\'s W1 gated on step 3 only (the control on the control, K2-09)', 'file' => 'lib/WaSeenMarks.php',
     'old' => "            if ((string)(\$config['wa_send_ledger'] ?? '') !== 'on') return;\n            if (!self::uganda(\$config, \$dataDir)) return;\n            if ((\$direction",
     'new' => "            if (empty(\$config['quote_tracking_enabled'])) return;\n            if (!self::uganda(\$config, \$dataDir)) return;\n            if ((\$direction",
     'sections' => 'C', 'cases' => 'C03', 'pattern' => '/C03 /'],
    ['label' => 'A32 C7 called with since = received_at', 'file' => $VA,
     'old' => "UtcClock::stamp(\$recv - self::C7_BACK_S)) !== null) {", 'new' => "UtcClock::stamp(\$recv)) !== null) {",
     'sections' => 'C', 'cases' => 'C04', 'pattern' => '/C04 /'],
    ['label' => 'VA-HO a hand-over line after the note not read', 'file' => $VA,
     'old' => "        if (\$this->handedOverSince(\$convId, \$recv)) { \$this->skip(\$d, 'handed_over'); return; }", 'new' => '',
     'sections' => 'C', 'cases' => 'C06', 'pattern' => '/C06 /'],
    ['label' => 'VA-HO2 the hand-over line matched only in one stored form', 'file' => $VA,
     'old' => "        return \"({\$col} LIKE \" . \$q(\$fragment) . \" OR {\$col} LIKE \" . \$q(str_replace('\"', '\\\\\"', \$fragment)) . ')';",
     'new' => "        return \"({\$col} LIKE \" . \$q(\$fragment) . ')';",
     'sections' => 'C', 'cases' => 'C06', 'pattern' => '/C06 /'],
]);
