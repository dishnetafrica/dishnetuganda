<?php
declare(strict_types=1);
/**
 * Weakened copies for section G of tests/test_voice_ack.php (5.18.104, root docs/73 §H.3.24: V35's forms, A4's webhook
 * form, the Inbox's escaping, and STOP at R3). TEST ONLY.
 */
$API = 'includes/api/api_whatsapp.php';
$M = array_merge($M, [
    ['label' => 'V35a voice_transcript attached off Uganda', 'file' => $API,
     'old' => "            if (StaffJobsGate::applies(is_array(\$config ?? null) ? \$config : [], \$dataDir ?? null)\n                && ",
     'new' => "            if (true\n                && ",
     'sections' => 'G', 'cases' => 'G04', 'pattern' => '/G04 /'],
    ['label' => 'V35b voice_transcript attached for a non-handover row', 'file' => $API,
     'old' => "WHERE conversation_id = ? AND voice_rung = 'handover' AND understanding IS NOT NULL\");",
     'new' => "WHERE conversation_id = ? AND understanding IS NOT NULL\");",
     'sections' => 'G', 'cases' => 'G01', 'pattern' => '/G01 a reply-rung/'],
    ['label' => 'V35c the transcript written into the body', 'file' => $API,
     'old' => "                        if (\$_vk !== '' && isset(\$_vTx[\$_vk])) \$_msgs[\$_vi]['voice_transcript'] = \$_vTx[\$_vk];",
     'new' => "                        if (\$_vk !== '' && isset(\$_vTx[\$_vk])) { \$_msgs[\$_vi]['voice_transcript'] = \$_vTx[\$_vk]; \$_msgs[\$_vi]['body'] = \$_vTx[\$_vk]; }",
     'sections' => 'G', 'cases' => 'G01', 'pattern' => '/G01 the handover row/'],
    ['label' => 'A4a silence at R4 removed in the webhook', 'file' => 'evo_webhook.php',
     'old' => " && MediaPolicy::voiceMode(\$vCfg, \$dataDir, \$channel) !== 'reply';", 'new' => ';',
     'sections' => 'G', 'cases' => 'G03', 'pattern' => '/G03 /'],
    ['label' => 'VA-ESC the Inbox renders the transcript unescaped', 'file' => 'tabs/engage/wa_inbox.php',
     'old' => "'+esc(m.voice_transcript).replace(", 'new' => "'+(m.voice_transcript).replace(",
     'sections' => 'G', 'cases' => 'G05', 'pattern' => '/G05 the render line/'],
    ['label' => 'V35d the Inbox draws the transcript line off Uganda', 'file' => 'tabs/engage/wa_inbox.php',
     'old' => "<?php if (StaffJobsGate::applies(is_array(\$config ?? null) ? \$config : [], \$dataDir ?? null)): /* 5.18.104",
     'new' => "<?php if (true): /* 5.18.104",
     'sections' => 'G', 'cases' => 'G04', 'pattern' => '/G04 the South Sudan Inbox page/'],
    ['label' => 'V22-R3 STOP read off the transcript at the dry run only', 'file' => 'lib/VoiceTranscription.php',
     'old' => "            \$stop = ContactOptOut::detect(\$transcript);", 'new' => "            \$stop = \$mode === 'dry_run' ? ContactOptOut::detect(\$transcript) : ['stop' => false, 'matched' => ''];",
     'sections' => 'G', 'cases' => 'G06', 'pattern' => '/G06 one/'],
]);
