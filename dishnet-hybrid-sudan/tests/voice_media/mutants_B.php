<?php
declare(strict_types=1);
/**
 * Weakened copies for section B of tests/test_voice_media.php (5.18.104, root docs/73 §H.3.24: V7, V33, V38, V46, V47,
 * and the second spawn). Each a one-fault copy that section B must catch. TEST ONLY.
 */
$W = 'evo_webhook.php';
$M = array_merge($M, [
    ['label' => 'V7 the webhook records a photo or a document', 'file' => $W,
     'old' => "if (\$vMedia !== null && \$vMedia['kind'] === 'audio') {", 'new' => 'if ($vMedia !== null) {',
     'sections' => 'B', 'cases' => 'B03', 'pattern' => '/B03 /'],
    ['label' => 'V33 conversationFor without the phone and channel check', 'file' => 'lib/InboundMedia.php',
     'old' => "WHERE m.wa_message_id = ? AND m.direction = 'in' AND c.phone = ? AND c.channel = ? LIMIT 1\");\n        \$q->execute([\$waMessageId, \$phone, \$channel]);",
     'new' => "WHERE m.wa_message_id = ? AND m.direction = 'in' LIMIT 1\");\n        \$q->execute([\$waMessageId]);",
     'sections' => 'B', 'cases' => 'B05', 'pattern' => '/B05 /'],
    ['label' => 'V38a a response key other than media_queued', 'file' => $W,
     'old' => "\$_evoOut['media_queued'] = \$mediaQueued;", 'new' => "\$_evoOut['media'] = \$mediaQueued;",
     'sections' => 'B', 'cases' => 'B02', 'pattern' => '/B02 /'],
    ['label' => 'V38b media_queued present while dark', 'file' => $W,
     'old' => "if (MediaPolicy::enabled(\$_evoCfg, \$dataDir)) { \$_evoOut['media_queued']", 'new' => "if (true) { \$_evoOut['media_queued']",
     'sections' => 'B', 'cases' => 'B01', 'pattern' => '/B01 /'],
    ['label' => 'V46 step 8c without its try/catch', 'edits' => [
        [$W, "    if (\$_evoVoiceUg) {\n        try {\n            \$vMedia = InboundMedia::fromEvoMessage(\$msg);", "    if (\$_evoVoiceUg) {\n        {\n            \$vMedia = InboundMedia::fromEvoMessage(\$msg);"],
        [$W, "        } catch (\\Throwable \$e) {\n            error_log('[evo_webhook] voice note step failed: ' . get_class(\$e) . ' (' . \$channel . ')');\n        }\n", "        }\n"]],
     'sections' => 'B', 'cases' => 'B07', 'pattern' => '/B07 /'],
    ['label' => 'V47 record() called with the first round\'s argument order', 'file' => $W,
     'old' => 'InboundMedia::record($pdo, (int)$vConv, $vMedia, $instance, $channel, $messageId)', 'new' => 'InboundMedia::record($pdo, $vConv, $msg, $vMedia, $channel, $instance)',
     'sections' => 'B', 'cases' => 'B02', 'pattern' => '/B02 /'],
    ['label' => 'W-SPAWN the media worker never spawned', 'file' => $W,
     'old' => "if (\$mediaQueued > 0) {\n    \$_evoMw = __DIR__ . '/run_media_worker.php';", 'new' => "if (false) {\n    \$_evoMw = __DIR__ . '/run_media_worker.php';",
     'sections' => 'B', 'cases' => 'B08', 'pattern' => '/B08 /'],
    ['label' => 'W-GATE step 8c off Uganda\'s gate (the gate taken as true)', 'file' => $W,
     'old' => "            \$_evoVoiceUg = StaffJobsGate::applies(is_array(\$config) ? \$config : [], \$dataDir);\n            if (\$_evoVoiceUg) {\n                require_once __DIR__ . '/lib/MediaPolicy.php';\n                require_once __DIR__ . '/lib/InboundMedia.php';",
     'new' => "            \$_evoVoiceUg = false;\n            if (\$_evoVoiceUg) {\n                require_once __DIR__ . '/lib/MediaPolicy.php';\n                require_once __DIR__ . '/lib/InboundMedia.php';",
     'sections' => 'B', 'cases' => 'B02', 'pattern' => '/B02 /'],
]);
