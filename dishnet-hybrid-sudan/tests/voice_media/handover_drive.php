<?php
declare(strict_types=1);
/**
 * TEST ONLY — section K's driver (5.18.104, root docs/73 §H.3.2, §H.3.24 V34): the hand-over, run once in its own process,
 * on a tree named by --tree, either through lib/Handover.php (--mode=lib, the tree under test) or through
 * AiReplyWorker::escalate (--mode=worker, 221c9dc), against the fake Evolution (--evo) and a fresh data folder (--data).
 * --owner=1 plants a salesperson's own number whose hand-over goes to its owner. Prints one JSON line: what each
 * hand-over left — the conversations' state, the wa.escalation events, every send and the rows stored — so section K
 * can compare the move with 221c9dc's statements. Fictitious numbers only.
 */
$o = getopt('', ['tree:', 'mode:', 'data:', 'evo:', 'owner:']);
$tree = (string)$o['tree']; $mode = (string)$o['mode']; $data = (string)$o['data']; $evoUrl = (string)$o['evo']; $withOwner = ($o['owner'] ?? '0') === '1';
date_default_timezone_set('UTC');
putenv('DN_DATA_DIR=' . $data);
foreach (['lib/bootstrap_data.php', 'lib/StoreInterface.php', 'lib/SqliteStore.php', 'lib/EventBus.php', 'lib/ConversationService.php',
          'lib/EvolutionApiService.php', 'lib/ChannelContext.php', 'lib/LineOwner.php', 'lib/AlertService.php', 'lib/ContactOptOut.php',
          'workers/WorkerBase.php'] as $f) require_once $tree . '/' . $f;
require_once __DIR__ . '/owner_evo.php';
$cfg = ['tenant_profile' => 'uganda', 'ai_enabled' => true, 'evo_api_url' => $evoUrl, 'evo_api_key' => 'vm-evo-key',
        'evo_instance_sales' => 'Sales-UG', 'evo_instance_support' => 'DishNet-UG', 'evo_instance_account' => 'DishNet-UG',
        'alert_whatsapp' => '256700000999', 'alert_hours_from' => 0, 'alert_hours_to' => 24,
        'ai_handover_message' => 'VM-HOLDING-LINE: a colleague will reply shortly.', '_data_dir' => $data];
file_put_contents($data . '/kyc_config.json', json_encode($cfg));
$store = SqliteStore::create($data);
$pdo = $store->getPdo();
$store->save('retailers.json', [['id' => 7, 'name' => 'Vee Seller', 'phone' => '0700000777', 'role' => 'sales', 'is_active' => true]]);
$cs = new ConversationService($data, $pdo);
$a = (int)$cs->ensureConversation('256771300901', 'sales')['id'];
$b = (int)$cs->ensureConversation('256771300902', 'sales')['id'];
$evo = $withOwner ? new VmOwnerEvo($cfg) : EvolutionApiService::forStore($cfg, $pdo, $data);
if ($withOwner) $evo->ctxRow = ['channel_id' => 'sales', 'role' => 'sales', 'owner_type' => 'staff', 'owner_staff_id' => 7, 'handover_to' => 'owner', 'status' => 'active'];
$owner = $withOwner ? LineOwner::of($evo->channelContext('sales'), $store, $cfg, $data) : null;
$calls = [[$a, 'first', false], [$a, 'again', false], [$b, 'answered', true]];
ob_start();
if ($mode === 'lib') {
    require_once $tree . '/lib/Handover.php';
    $bus = new EventBus($pdo);
    foreach ($calls as [$conv, $reason, $answered]) {
        $phone = (string)$pdo->query('SELECT phone FROM wa_conversations WHERE id = ' . $conv)->fetchColumn();
        Handover::escalate($pdo, $bus, $store, $cfg, $evo, $cs, $conv, 'sales', $phone, $reason, $answered, function () {}, 'ai_reply_worker', $owner);
    }
} else {
    require_once $tree . '/workers/AiReplyWorker.php';
    $w = new AiReplyWorker($store, $cfg, 30, 10);
    foreach (['evo' => $evo, 'lineOwner' => $owner] as $prop => $val) {
        $p = new ReflectionProperty(AiReplyWorker::class, $prop); $p->setAccessible(true); $p->setValue($w, $val);
    }
    $m = new ReflectionMethod(AiReplyWorker::class, 'escalate'); $m->setAccessible(true);
    foreach ($calls as [$conv, $reason, $answered]) {
        $phone = (string)$pdo->query('SELECT phone FROM wa_conversations WHERE id = ' . $conv)->fetchColumn();
        $m->invoke($w, $conv, 'sales', $phone, $reason, $answered);
    }
}
ob_end_clean();
$st = json_decode((string)file_get_contents($evoUrl . '/__test/state'), true) ?: [];
$out = [
    'states'  => $pdo->query('SELECT id, state FROM wa_conversations ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR),
    'events'  => array_map(function ($e) { return [$e['event_type'], (int)$e['entity_id'], json_decode($e['payload'], true), (int)$e['priority'], $e['created_by']]; },
                           $pdo->query('SELECT event_type, entity_id, payload, priority, created_by FROM events ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)),
    'sends'   => array_map(function ($c) { return [$c['instance'], $c['number'], $c['text']]; }, (array)($st['text_calls'] ?? [])),
    'stored'  => $pdo->query("SELECT conversation_id, direction, role, body, agent_name, metadata FROM wa_messages ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
    'alerts'  => array_map(function ($r) { return (string)($r['key'] ?? ''); }, (array)($store->load(AlertService::LOCK_FILE) ?? [])),
];
echo json_encode($out), "\n";
