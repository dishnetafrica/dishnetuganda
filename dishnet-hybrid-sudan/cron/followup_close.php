<?php
declare(strict_types=1);

/**
 * followup_close.php — close follow-ups that events have overtaken.
 *
 * The customer replying is the commonest and most important: the whole point
 * of a follow-up is to restart a conversation, so a reply means it worked (or
 * that it was never needed) and the row must not survive to send a second.
 *
 * This runs on a timer rather than from the webhook because the webhook's job
 * is to be fast and to never fail; closing a follow-up is neither urgent nor
 * worth risking an inbound message for.
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

$closed = 0;
foreach ($svc->allOpen(200) as $fu) {
    $conv = $convSvc->getConversation((int)$fu['conversation_id']);
    if ($conv === null) {
        $svc->close((int)$fu['id'], 'cancelled', 'the conversation no longer exists', 'closer');
        $closed++;
        continue;
    }

    // CASE C. They wrote after this follow-up opened, so the enquiry is live
    // again and the normal assistant has it.
    $lastCust = (string)($conv['last_customer_at'] ?? '');
    if ($lastCust !== '' && $lastCust > (string)$fu['last_customer_at']) {
        $svc->close((int)$fu['id'], 'replied', 'the customer wrote at ' . $lastCust, 'closer');
        $closed++;
        continue;
    }

    // CASE H.
    if ((string)($conv['state'] ?? '') === 'human_active') {
        $svc->close((int)$fu['id'], 'human_closed', 'a colleague took over', 'closer');
        $closed++;
        continue;
    }

    // CASE D, when the opt-out arrived after the row was opened.
    $v = $oo->blocks((string)$fu['phone'], (string)$fu['channel'],
                     ContactOptOut::CLASS_PROACTIVE);
    if ($v['blocked']) {
        $svc->close((int)$fu['id'], 'opted_out', $v['reason'], 'closer');
        $closed++;
        continue;
    }

    // The cadence has run out: attempts spent, or no further due time armed.
    if ((int)$fu['attempts'] >= (int)$fu['max_attempts'] && empty($fu['due_at'])) {
        $svc->close((int)$fu['id'], 'exhausted',
            (int)$fu['attempts'] . ' attempts made, no reply', 'closer');
        $closed++;
    }
}

if ($closed) echo date('Y-m-d H:i:s') . " [followup_close] closed={$closed}\n";
