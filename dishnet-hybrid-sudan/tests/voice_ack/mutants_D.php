<?php
declare(strict_types=1);
/**
 * Weakened copies for section D of tests/test_voice_ack.php (5.18.104, root docs/73 §H.3.24: A14's three forms, A17,
 * A23, A25, A27's two forms, A28, A29, F15, and the two refusal-at-the-send mappings). TEST ONLY.
 */
$VA = 'lib/VoiceAck.php';
$echo = "                (\$this->echo)(\$send, \$channel, 'ai.voice_ack');\n";
$settle = "                \$ledger->settle(\$claim, 'sent', ['wa_message_id' => \$sentId]);\n";
$store = "                    'metadata'      => json_encode(['channel' => \$channel, 'voice_ack' => \$variant, 'source' => self::SOURCE]),\n                ]);\n";
$M = array_merge($M, [
    ['label' => 'A17 the ledger claim skipped', 'file' => $VA,
     'old' => "        \$c = \$ledger->claim(\$instance, 'voice_ack', \$ref);", 'new' => "        \$c = ['ok' => true, 'claim' => 0];",
     'sections' => 'D', 'cases' => 'D01', 'pattern' => '/D01 /'],
    ['label' => 'A27a handset_busy mapped to a final skip', 'file' => $VA,
     'old' => "    const LEDGER_DEFER = ['in_flight', 'spacing', 'handset_busy'];", 'new' => "    const LEDGER_DEFER = ['in_flight', 'spacing'];",
     'sections' => 'D', 'cases' => 'D02', 'pattern' => '/D02 a list in flight/'],
    ['label' => 'A27b in_flight mapped to a final skip', 'file' => $VA,
     'old' => "    const LEDGER_DEFER = ['in_flight', 'spacing', 'handset_busy'];", 'new' => "    const LEDGER_DEFER = ['spacing', 'handset_busy'];",
     'sections' => 'D', 'cases' => 'D02', 'pattern' => '/D02 its own ref/'],
    ['label' => 'A25 an unmapped ledger answer falls through to the send', 'file' => $VA,
     'old' => "            \$this->skip(\$d, 'ledger_unknown');                                    // any answer not in the table sends nothing (A25)\n            return;\n",
     'new' => '',
     'sections' => 'D', 'cases' => 'D03', 'pattern' => '/D03 three/'],
    ['label' => 'A29 a may-have-been-sent result settled plain sent', 'file' => $VA,
     'old' => "                    \$ledger->settle(\$claim, 'failed', ['maybe_sent' => true, 'wa_message_id' => \$sentId]);",
     'new' => "                    \$ledger->settle(\$claim, 'sent', ['wa_message_id' => \$sentId]);",
     'sections' => 'D', 'cases' => 'D04', 'pattern' => '/D04 a 504/'],
    ['label' => 'A23 an uncertain send sent again (the period, the ledger\'s already_sent and the decision\'s one row all passed over)', 'edits' => [
        [$VA, "        \$st = \$this->pdo->prepare(\"SELECT 1 FROM wa_voice_acks WHERE conversation_id = ? AND outcome IN ('sent','uncertain')",
              "        \$st = \$this->pdo->prepare(\"SELECT 1 FROM wa_voice_acks WHERE conversation_id = ? AND outcome IN ('sent')"],
        [$VA, "        if (empty(\$c['ok'])) {\n            \$code = (string)(\$c['defer'] ?? '');\n            if (\$code === 'already_sent') return;",
              "        if (empty(\$c['ok']) && (\$c['defer'] ?? '') !== 'already_sent') {\n            \$code = (string)(\$c['defer'] ?? '');"],
        [$VA, "        if (\$ins->rowCount() !== 1) {\n            \$ledger->settle(\$claim, 'failed', ['maybe_sent' => false]);\n            return;\n        }",
              "        if (false) {\n        }"]],
     'sections' => 'D', 'cases' => 'D04', 'pattern' => '/D04 the event again/'],
    ['label' => 'A28 the settle without the sent id', 'file' => $VA,
     'old' => $settle, 'new' => "                \$ledger->settle(\$claim, 'sent', []);\n",
     'sections' => 'D', 'cases' => 'D06', 'pattern' => '/D06 /'],
    ['label' => 'A14a the echo claim removed', 'file' => $VA, 'old' => $echo, 'new' => '',
     'sections' => 'D', 'cases' => 'D07', 'pattern' => '/D07 /'],
    ['label' => 'A14b the echo claimed after the settle', 'file' => $VA, 'old' => $echo . $settle, 'new' => $settle . $echo,
     'sections' => 'D', 'cases' => 'D07', 'pattern' => '/D07 /'],
    ['label' => 'A14c the echo claimed after the store', 'edits' => [[$VA, $echo, ''], [$VA, $store, $store . $echo]],
     'sections' => 'D', 'cases' => 'D07', 'pattern' => '/D07 /'],
    ['label' => 'F15 the acknowledgement sent as a proactive message', 'file' => $VA,
     'old' => "                \$send = \$this->evo->sendText(\$channel, \$phone, \$text, ContactOptOut::CLASS_REPLY);",
     'new' => "                \$send = \$this->evo->sendText(\$channel, \$phone, \$text, ContactOptOut::CLASS_PROACTIVE);",
     'sections' => 'D', 'cases' => 'D08', 'pattern' => '/D08 a proactive/'],
    ['label' => 'VA-SUP an opt-out suppression read as a failed send', 'file' => $VA,
     'old' => "            } elseif (!empty(\$send['suppressed'])) {", 'new' => "            } elseif (false) {",
     'sections' => 'D', 'cases' => 'D08', 'pattern' => '/D08 an `all`/'],
    ['label' => 'VA-POL a policy refusal at the send read as a failed send', 'file' => $VA,
     'old' => "            if (EvolutionApiService::policyRefused(\$send)) {", 'new' => "            if (false) {",
     'sections' => 'D', 'cases' => 'D09', 'pattern' => '/D09 /'],
]);
