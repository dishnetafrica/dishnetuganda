<?php
declare(strict_types=1);
/**
 * Weakened copies for section F of tests/test_voice_ack.php (5.18.104, root docs/73 §H.3.24: V28's three forms, V29,
 * the voice context, block, log key and modality of (d)–(f), the dead acknowledgement, and V34's two wrapper forms).
 * TEST ONLY.
 */
$AW = 'workers/AiReplyWorker.php';
$M = array_merge($M, [
    ['label' => 'V28a the F20 text wait removed', 'file' => $AW,
     'old' => "        if (\$this->voiceUg() && !\$voiceTurn && \$convId > 0) \$this->waitForEarlierVoice(\$event, \$convId, \$channel);\n", 'new' => '',
     'sections' => 'F', 'cases' => 'F03', 'pattern' => '/F03 a note 10 s/'],
    ['label' => 'V28b the F20 wait without its 90 s bound', 'file' => $AW,
     'old' => "                                         AND created_at <= ? AND created_at > ?", 'new' => "                                         AND created_at <= ? AND ? IS NOT NULL",
     'sections' => 'F', 'cases' => 'F03', 'pattern' => '/F03 control: the note 100 s/'],
    ['label' => 'V28c the F20 wait on every number, R4 or not', 'file' => $AW,
     'old' => "        if (\\MediaPolicy::voiceMode(\$fresh, \$this->dataDir !== '' ? \$this->dataDir : null, \$channel) !== 'reply') return;\n", 'new' => '',
     'sections' => 'F', 'cases' => 'F03', 'pattern' => '/F03 control: R4 not on/'],
    ['label' => 'V29 the out-of-order rule removed', 'file' => $AW,
     'old' => "        if (\$voiceTurn && \$convId > 0 && \$this->voiceAnsweredOutOfOrder(\$p, \$convId)) {", 'new' => "        if (false) {",
     'sections' => 'F', 'cases' => 'F04', 'pattern' => '/F04 handed/'],
    ['label' => 'VA-CTX the voice key not passed into the sales contract', 'file' => $AW,
     'old' => "                    'voice'     => \$ctx['voice'] ?? null,\n", 'new' => '',
     'sections' => 'F', 'cases' => 'F01', 'pattern' => '/F01 (the brain asked|the prompt)/'],
    ['label' => 'VA-BLK the VOICE MESSAGE block removed', 'file' => 'lib/DishNetAiBrain.php',
     'old' => "        \$voice = \$ctx['voice'] ?? null;\n        if (is_array(\$voice)) {\n            \$secs", 'new' => "        \$voice = \$ctx['voice'] ?? null;\n        if (false) {\n            \$secs",
     'sections' => 'F', 'cases' => 'F01', 'pattern' => '/F01 the prompt/'],
    ['label' => 'VA-LOG origin=voice dropped from the "in" line', 'file' => $AW,
     'old' => "            (string)(\$p['origin'] ?? '') === 'voice' ? ' origin=voice' : ''));", 'new' => "            ''));",
     'sections' => 'F', 'cases' => 'F01', 'pattern' => '/F01 the "in" line/'],
    ['label' => 'VA-MOD the audit\'s modality always text', 'file' => $AW,
     'old' => "                'modality'        => is_array(\$ctx['voice'] ?? null) ? 'voice' : 'text',", 'new' => "                'modality'        => 'text',",
     'sections' => 'F', 'cases' => 'F05', 'pattern' => '/F05 /'],
    ['label' => 'VA-DEAD a dead acknowledgement event handed over as a reply\'s', 'file' => $AW,
     'old' => "        if ((\$event['event_type'] ?? '') === 'ai.voice_ack') return;\n        \$p       = \$event['_payload'] ?? [];", 'new' => "        \$p       = \$event['_payload'] ?? [];",
     'sections' => 'F', 'cases' => 'F06', 'pattern' => '/F06 /'],
    ['label' => 'V34w-a the wrapper drops the line\'s owner', 'file' => $AW,
     'old' => "            function (string \$level, string \$message): void { \$this->log(\$level, \$message); }, 'ai_reply_worker',\n            \$this->handoverOwner(\$channel));",
     'new' => "            function (string \$level, string \$message): void { \$this->log(\$level, \$message); }, 'ai_reply_worker',\n            null);",
     'sections' => 'F', 'cases' => 'F07', 'pattern' => '/F07 an owner/'],
    ['label' => 'V34w-b the wrapper ignores alreadyAnswered', 'file' => $AW,
     'old' => "            \$convId, \$channel, \$phone, \$reason, \$alreadyAnswered,\n            function", 'new' => "            \$convId, \$channel, \$phone, \$reason, false,\n            function",
     'sections' => 'F', 'cases' => 'F07', 'pattern' => '/F07 /'],
]);
