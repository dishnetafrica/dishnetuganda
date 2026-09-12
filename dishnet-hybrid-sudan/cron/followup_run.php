<?php
declare(strict_types=1);

/**
 * followup_run.php — for each due follow-up, run the gates and ask the AI.
 *
 * Writes a draft. Sends nothing. In this release nothing in the system sends
 * a follow-up without a person approving it first, and that is enforced by
 * there being no send here to disable.
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
require_once $pluginRoot . '/lib/FollowUpEvaluator.php';
require_once $pluginRoot . '/lib/ClaudeWaClient.php';

$dataDir = getDataDir($pluginRoot);
$store   = SqliteStore::create($dataDir);
$config  = PluginConfig::load($pluginRoot, $dataDir);

if (empty($config['followup_enabled'])) return;

$pdo     = $store->getPdo();
$svc     = new FollowUpService($pdo);
$convSvc = new ConversationService($dataDir, $pdo);
$oo      = ContactOptOut::fromStore($store);
$now     = gmdate('Y-m-d H:i:s');

$apiKey = (string)($config['claude_api_key'] ?? $config['anthropic_api_key'] ?? '');
if ($apiKey === '') {
    error_log('[followup_run] no API key — cannot evaluate');
    return;
}
$evaluator = new FollowUpEvaluator(new ClaudeWaClient($apiKey, $pdo), $config);

$cap      = (int)($config['followup_daily_cap'] ?? 30);
$perRun   = (int)($config['followup_run_limit'] ?? 5);   // model calls cost money
$drafted  = 0; $closed = 0; $deferred = 0; $skipped = 0;

foreach ($svc->due($now, $perRun) as $fu) {
    $conv = $convSvc->getConversation((int)$fu['conversation_id']);
    if ($conv === null) {
        $svc->close((int)$fu['id'], 'cancelled', 'the conversation no longer exists');
        $closed++;
        continue;
    }

    // One pending draft at a time: a person has not read the last one yet.
    if ($svc->pendingDraft((int)$fu['id']) !== null) { $skipped++; continue; }

    $thread = $convSvc->getMessages((int)$fu['conversation_id'], 60);
    $text   = implode("\n", array_map(static fn($m) => (string)($m['body'] ?? ''), $thread));

    $verdictGate = FollowUpPolicy::gate([
        'enabled'     => true,
        'now'         => $now,
        'conv'        => $conv,
        'followup'    => $fu,
        'opt_out'     => $oo->blocks((string)$fu['phone'], (string)$fu['channel'],
                                     ContactOptOut::CLASS_PROACTIVE),
        'sent_today'  => $svc->sentTodayOn((string)$fu['channel'], $now),
        'daily_cap'   => $cap,
        'thread_text' => $text,
    ]);

    $svc->log((int)$fu['id'], (int)$fu['conversation_id'], 'evaluated',
        $verdictGate['action'] . ($verdictGate['gate'] ? ' at ' . $verdictGate['gate'] : '')
        . ' — ' . $verdictGate['reason']);

    if ($verdictGate['action'] === 'close') {
        $reason = [
            'opt_out'      => 'opted_out',
            'human_active' => 'human_closed',
            'exhausted'    => 'exhausted',
            'escalation'   => 'escalated',
        ][$verdictGate['gate']] ?? 'vetoed';
        $svc->close((int)$fu['id'], $reason, $verdictGate['reason']);
        $closed++;
        continue;
    }
    if ($verdictGate['action'] === 'defer') {
        $svc->defer((int)$fu['id'], $verdictGate['defer_until'] ?: $now, $verdictGate['reason']);
        $deferred++;
        continue;
    }
    if ($verdictGate['action'] !== 'proceed') { $skipped++; continue; }

    // Only now does anything cost money.
    $level   = FollowUpPolicy::contentLevel($conv);
    $account = [];
    if ($level === FollowUpPolicy::CONTENT_ACCOUNT) {
        // Assembled only for a confirmed identity. A prompt that never carries
        // account facts cannot leak them.
        $account = [
            'name' => (string)($conv['crm_client_name'] ?? ''),
            'uCRM client id' => (int)($conv['crm_client_id'] ?? 0),
        ];
    }

    $verdict = $evaluator->evaluate($fu, $thread, $level, $account);

    $svc->setContext((int)$fu['id'], [
        'sales_stage' => $verdict['sales_stage'],
        'product'     => $verdict['product'],
        'objection'   => $verdict['objection'],
    ]);

    if ($verdict['verdict'] === 'DO_NOT_SEND') {
        $svc->draft((int)$fu['id'], $verdict, 'gates passed; the assistant declined');
        $svc->close((int)$fu['id'], 'not_interested', $verdict['reason'], 'ai');
        $closed++;
        continue;
    }
    if ($verdict['verdict'] === 'WAIT') {
        $until = gmdate('Y-m-d H:i:s',
            strtotime($now . ' UTC') + ($verdict['next_due_hours'] * 3600));
        $svc->defer((int)$fu['id'], $until, 'the assistant asked to wait: ' . $verdict['reason'], 'ai');
        $deferred++;
        continue;
    }
    if ($verdict['verdict'] === 'ESCALATE_TO_HUMAN') {
        $svc->draft((int)$fu['id'], $verdict, 'the assistant asked for a person');
        $svc->close((int)$fu['id'], 'escalated', $verdict['reason'], 'ai');
        $closed++;
        continue;
    }

    // SEND — which in this release means "propose". A person decides.
    $r = $svc->draft((int)$fu['id'], $verdict,
        'quiet since ' . (string)$fu['last_customer_at'] . '; attempt '
        . ((int)$fu['attempts'] + 1) . '; identity ' . $level);
    if ($r['ok']) $drafted++;
}

if ($drafted || $closed || $deferred || $skipped) {
    echo date('Y-m-d H:i:s')
       . " [followup_run] drafted={$drafted} closed={$closed} deferred={$deferred} skipped={$skipped}\n";
}
