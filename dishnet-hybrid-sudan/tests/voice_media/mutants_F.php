<?php
declare(strict_types=1);
/**
 * Weakened copies for section F of tests/test_voice_media.php (5.18.104, root docs/73 §H.3.7's table: R4's turn once,
 * origin voice, labelled). TEST ONLY.
 */
$VT = 'lib/VoiceTranscription.php';
$M = array_merge($M, [
    ['label' => 'VT-ORIGIN the R4 turn not marked origin voice', 'file' => $VT,
     'old' => "                    'origin'            => 'voice',", 'new' => "                    'origin'            => 'text',",
     'sections' => 'F', 'cases' => 'F01', 'pattern' => '/F01 /'],
    ['label' => 'VT-LABEL the R4 body unlabelled', 'file' => $VT,
     'old' => "        return self::LABEL . ' ' . \$transcript;", 'new' => '        return $transcript;',
     'sections' => 'F', 'cases' => 'F01', 'pattern' => '/F01 /'],
    ['label' => 'VT-ONCE complete() without its understood guard', 'file' => $VT,
     'old' => "updated_at = datetime('now') WHERE id = ? AND status <> 'understood'\");", 'new' => "updated_at = datetime('now') WHERE id = ?\");",
     'sections' => 'F', 'cases' => 'F03', 'pattern' => '/F03 /'],
    ['label' => 'VT-RUNG complete() at a rung that does not transcribe', 'file' => $VT,
     'old' => "        if (!in_array(\$mode, self::MODES, true)) return self::fail('switched_off', 'rung ' . \$mode);\n        \$id     = (int)(\$row['id'] ?? 0);\n        \$convId = (int)(\$row['conversation_id'] ?? 0);\n        \$q = ",
     'new' => "        \$id     = (int)(\$row['id'] ?? 0);\n        \$convId = (int)(\$row['conversation_id'] ?? 0);\n        \$q = ",
     'sections' => 'F', 'cases' => 'F03', 'pattern' => '/F03 /'],
]);
