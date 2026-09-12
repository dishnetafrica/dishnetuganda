<?php
declare(strict_types=1);

/**
 * followup_send.php — send follow-ups a PERSON has approved. Nothing else.
 *
 * There is no path in this file from "the AI decided" to "the customer
 * received". It reads followup_drafts where status = 'approved', which only a
 * human action sets. Automatic sending is a later milestone and will be a
 * different decision, not a flag flipped here.
 *
 * ── THE ECHO ────────────────────────────────────────────────────────────
 *
 * Evolution posts every outbound message back as fromMe, and the webhook
 * reads an unrecognised fromMe message as a colleague typing on the handset —
 * which stands the AI down for 24 hours. Our own follow-up must never look
 * like that, so its message id is claimed in EvoWebhookGuard the instant
 * Evolution returns it, before the echo can arrive as a separate request.
 * AiReplyWorker does the same thing for the same reason.
 */
if (PHP_SAPI !== 'cli') return;

$pluginRoot = dirname(__DIR__);
require_once $pluginRoot . '/lib/error_handler.php';
require_once $pluginRoot . '/lib/bootstrap_data.php';
require_once $pluginRoot . '/lib/StoreInterface.php';
require_once $pluginRoot . '/lib/JsonStore.php';
require_once $pluginRoot . '/lib/SqliteStore.php';
require_once $pluginRoot . '/lib/PluginConfig.php';
require_once $pluginRoot . '/lib/ConversationService.php';
require_once $pluginRoot . '/lib/ContactOptOut.php';
require_once $pluginRoot . '/lib/EvolutionApiService.php';
require_once $pluginRoot . '/lib/EvoWebhookGuard.php';
require_once $pluginRoot . '/lib/FollowUpPolicy.php';
require_once $pluginRoot . '/lib/FollowUpService.php';

$dataDir = getDataDir($pluginRoot);
$store   = SqliteStore::create($dataDir);
$config  = PluginConfig::load($pluginRoot, $dataDir);

if (empty($config['followup_enabled'])) return;

$pdo     = $store->getPdo();
$svc     = new FollowUpService($pdo);
$convSvc = new ConversationService($dataDir, $pdo);
$oo      = ContactOptOut::fromStore($store);
$evo     = new EvolutionApiService($config);
$guard   = new EvoWebhookGuard($pdo, $config);
$now     = gmdate('Y-m-d H:i:s');

$sent = 0; $held = 0; $failed = 0;

foreach ($svc->approvedDrafts(10) as $d) {
    $fuId  = (int)$d['followup_id'];
    $phone = (string)$d['phone'];
    $chan  = (string)$d['channel'];
    $body  = trim((string)($d['edited_body'] ?? '')) !== ''
           ? (string)$d['edited_body'] : (string)$d['body'];

    if (trim($body) === '') {
        $svc->recordFailure($fuId, (int)$d['id'], 'the approved draft has no message', 'sender');
        $failed++;
        continue;
    }

    // Re-check the things that can change between approval and send. A person
    // may have approved this yesterday; the customer may have replied, opted
    // out, or been taken over since.
    $conv = $convSvc->getConversation((int)$d['conversation_id']);
    if ($conv === null) {
        $svc->close($fuId, 'cancelled', 'the conversation no longer exists', 'sender');
        $held++;
        continue;
    }
    $v = $oo->blocks($phone, $chan, ContactOptOut::CLASS_PROACTIVE);
    if ($v['blocked']) {
        $svc->close($fuId, 'opted_out', $v['reason'], 'sender');
        $held++;
        continue;
    }
    if ((string)($conv['state'] ?? '') === 'human_active') {
        $svc->close($fuId, 'human_closed', 'a colleague took over after approval', 'sender');
        $held++;
        continue;
    }
    if ((string)($conv['last_customer_at'] ?? '') > (string)($d['decided_at'] ?? '')) {
        $svc->close($fuId, 'replied', 'the customer wrote back after this was approved', 'sender');
        $held++;
        continue;
    }
    $win = FollowUpPolicy::withinSendingWindow($now);
    if (!$win['ok']) { $held++; continue; }   // wait for the window; stay approved

    $res = $evo->sendText($chan, $phone, $body, ContactOptOut::CLASS_PROACTIVE);

    // Claim our own echo FIRST — before storing, before bookkeeping. The echo
    // is a separate HTTP request and could in principle arrive while we are
    // still writing rows; claiming the id here means the webhook drops it at
    // its idempotency check instead of reading it as a colleague's message.
    $waId = (string)($res['data']['key']['id'] ?? $res['key']['id'] ?? '');
    if ($waId !== '') {
        try {
            $guard->claim($waId, (string)($config['evo_instance_' . $chan] ?? ''), 'followup.send');
        } catch (\Throwable $e) { /* dedupe is a backstop */ }
    }

    $ok = empty($res['suppressed']) && (!isset($res['ok']) || !empty($res['ok']))
          && empty($res['error']);
    if (!$ok) {
        $svc->recordFailure($fuId, (int)$d['id'],
            (string)($res['error'] ?? 'the send failed'), 'sender');
        $failed++;
        continue;
    }

    // Put it in the conversation so the inbox and the AI both see what we sent.
    try {
        $convSvc->storeMessage((int)$d['conversation_id'], [
            'direction' => 'out', 'role' => 'agent', 'body' => $body,
            'agent_name' => 'DishNet', 'wa_message_id' => $waId ?: null,
            'metadata' => ['source' => 'followup', 'followup_id' => $fuId],
        ]);
    } catch (\Throwable $e) { /* the customer has it; bookkeeping must not retry */ }

    $svc->recordSend($fuId, (int)$d['id'], $body, $waId,
                     (string)($d['decided_by'] ?? 'staff'));
    $sent++;
}

if ($sent || $held || $failed) {
    echo date('Y-m-d H:i:s') . " [followup_send] sent={$sent} held={$held} failed={$failed}\n";
}
