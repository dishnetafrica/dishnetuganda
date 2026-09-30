<?php
/**
 * no_resend_probe.php — TEST ONLY (5.18.54, docs/46 row 31). Runs, from the plugin tree named and inside a data
 * directory of its own, either the AI reply worker on one customer message, or the follow-up sender on one approved
 * draft; as many times as asked, each run as soon as the last one allows (a failed event is made due at once). Prints
 * as JSON what that left behind. The Evolution URL is a local fake and the AI a stand-in in this process: nothing leaves
 * the machine.
 *
 *   php no_resend_probe.php <pluginRoot> <uganda|south-sudan> <ai|followup> <evolutionUrl> <dataDir> <runs> [timezone]
 *
 * The cron finds its data directory the way the plugin does (getDataDir: beside the plugin, whatever DN_DATA_DIR says),
 * so <pluginRoot> must be a copy of the plugin and <dataDir> the directory beside it.
 */
[$_, $root, $tenant, $what, $url, $dd, $runs, $tz] = array_pad($argv, 8, '');
$runs = max(1, (int)$runs);
putenv('DN_DATA_DIR=' . $dd);
putenv('DN_VAULT_FILE=' . $dd . '/no-vault.json');   // no configuration vault may decide the country here

$cfg = ['evo_api_url' => $url, 'evo_api_key' => 'TESTKEY', 'evo_instance_sales' => 'fake_inst',
        'evo_instance_support' => 'fake_inst', 'ai_provider' => 'openai', 'openai_api_key' => 'test-key-never-called',
        'followup_enabled' => true];
if ($tenant === 'uganda') $cfg['tenant_profile'] = 'uganda';
if ($tz !== '') $cfg['timezone'] = $tz;
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/ConversationService.php';
require_once $root . '/lib/EventBus.php';
$store = SqliteStore::create($dd);
// After the store's first boot, which moves every JSON file in the directory into SQLite and renames it: what
// PluginConfig::load and dn_tz() read.
file_put_contents($dd . '/kyc_config.json', json_encode($cfg));
require_once $root . '/lib/timezone.php';
if (function_exists('dn_tz_reset')) dn_tz_reset();   // a zone read before the file existed must not stay
$pdo   = $store->getPdo();
$svc   = new ConversationService($dd, $pdo);
$phone = '256700000001';
$cid   = (int)$svc->ensureConversation($phone, 'sales', null, 'test')['id'];
$out   = [];

if ($what === 'ai') {
    require_once $root . '/lib/UtcClock.php';
    require_once $root . '/lib/AlertService.php';
    require_once $root . '/lib/EvoWebhookGuard.php';
    require_once $root . '/lib/DishNetAiBrain.php';
    require_once $root . '/workers/WorkerBase.php';
    require_once $root . '/workers/AiReplyWorker.php';
    // A brain that never leaves the process, and counts how often it was asked.
    $brain = new class($cfg) extends DishNetAiBrain {
        public int $calls = 0;
        public function isConfigured(): bool { return true; }
        public function reply(array $context): array { $this->calls++; return ['reply' => 'FAKE-AI-REPLY: yes, we deliver in Kampala.']; }
        public function getLastUsage(): array { return []; }
    };
    $bus = new EventBus($pdo);
    $eid = $bus->emit('ai.reply', 'conversation', $cid, [
        'channel' => 'sales', 'whatsapp_instance' => 'fake_inst', 'customer_phone' => $phone,
        'message' => 'Do you deliver in Kampala?', 'push_name' => 'Test', 'wa_message_id' => 'CUST-' . bin2hex(random_bytes(3)),
        'remote_jid' => $phone . '@s.whatsapp.net', 'received_at' => gmdate('c'),
    ], 3, 'test');
    for ($i = 0; $i < $runs; $i++) {
        $pdo->exec("UPDATE events SET next_retry_at = datetime('now', '-1 second') WHERE id = " . (int)$eid
                   . " AND status IN ('pending', 'failed')");
        $w = new AiReplyWorker($store, $cfg, 30, 10);
        $rp = new ReflectionProperty(AiReplyWorker::class, 'brain');
        $rp->setAccessible(true);
        $rp->setValue($w, $brain);
        ob_start();
        try { $w->run(); } catch (\Throwable $e) { $out['exception'] = get_class($e) . ': ' . $e->getMessage(); }
        ob_end_clean();
    }
    $ev = $pdo->query("SELECT status, attempts, COALESCE(error, '') AS error FROM events WHERE id = " . (int)$eid)->fetch(PDO::FETCH_ASSOC) ?: [];
    $out += ['event' => $ev, 'brain_calls' => $brain->calls,
             'state' => (string)$pdo->query("SELECT state FROM wa_conversations WHERE id = {$cid}")->fetchColumn(),
             'escalations' => (int)$pdo->query("SELECT COUNT(*) FROM events WHERE event_type = 'wa.escalation'")->fetchColumn()];
} else {
    // One open follow-up with one approved draft, as the scan, the AI and a person leave it; the customer last wrote
    // two days ago, before the draft was approved.
    $pdo->exec("UPDATE wa_conversations SET last_customer_at = datetime('now', '-2 days') WHERE id = {$cid}");
    $pdo->prepare("INSERT INTO followups (conversation_id, channel, phone, last_customer_at, opened_by)
                   VALUES (?, 'sales', ?, datetime('now', '-2 days'), 'test')")->execute([$cid, $phone]);
    $fid = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO followup_drafts (followup_id, attempt, verdict, reason, body, status, decided_at, decided_by)
                   VALUES (?, 1, 'SEND', 'test', 'FAKE-FOLLOWUP: are you still interested in the kit?', 'approved',
                           datetime('now', '-1 minute'), 'test')")->execute([$fid]);
    for ($i = 0; $i < $runs; $i++) {
        ob_start();
        try { (function () use ($root) { include $root . '/cron/followup_send.php'; })(); }
        catch (\Throwable $e) { $out['exception'] = get_class($e) . ': ' . $e->getMessage(); }
        $out['run_output'][] = mb_substr((string)ob_get_clean(), -300);
    }
    $out += ['draft' => (string)$pdo->query("SELECT status FROM followup_drafts WHERE followup_id = {$fid}")->fetchColumn(),
             'events' => $pdo->query("SELECT event FROM followup_events WHERE followup_id = {$fid} ORDER BY id")->fetchAll(PDO::FETCH_COLUMN)];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
