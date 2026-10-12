<?php
declare(strict_types=1);
/**
 * test_lead_path_batch0.php — Batch 0 of the AI communication layer (docs/55 §3): the three defects on the live lead path.
 *
 *   (a) AiReplyWorker::handle() called latestPin($convId, $ctx) with a variable that did not exist — the context is
 *       $context — so PHP passed null to an array parameter, the TypeError was caught as "lead capture failed", and no
 *       WhatsApp lead was ever written or synced, whatever ai_lead_capture said.
 *   (b) UcrmLeadWorker read $event['payload'] as an array. WorkerBase decodes the stored JSON into '_payload' and leaves
 *       'payload' a string, so lead_id was always 0 and every crm.lead.sync event was "dropped" and acknowledged.
 *   (c) The sales channel's context is built by BrainContext, which (rightly) carries no conversation or customer id and
 *       (until now) no pin: the assistant never saw the LOCATION PIN block on the sales number, and the guard's log lines
 *       and ai_security_events rows recorded conversation 0 / customer 0 on every sales turn.
 *
 * Proved at the WORKER level, not by reflection into a service: a real DishNetAiBrain marker parse of a canned model
 * answer carrying <<LEAD{...}>> → AiReplyWorker → a `leads` row with the coordinates of the pin the conversation
 * actually sent → a crm.lead.sync event → UcrmLeadWorker, run as WorkerBase runs it, against the fake uCRM → a client
 * created and the lead linked. Four weakened copies are each caught by re-running the same scenario (this file re-enters
 * itself as a driver against the copy). The flags ai_lead_capture / ai_crm_lead_sync are the EXISTING ones; nothing here
 * changes their meaning, and the scenario sets them only inside its own sandbox.
 *
 * Driver mode: php tests/test_lead_path_batch0.php --driver   (env DN_T_EVO_PORT, DN_T_UCRM_PORT) → one JSON line.
 */
$root = dirname(__DIR__);
$isDriver = in_array('--driver', $argv ?? [], true);
date_default_timezone_set('UTC');

require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/ConversationService.php';
require_once $root . '/lib/EventBus.php';
require_once $root . '/lib/UtcClock.php';
require_once $root . '/lib/AlertService.php';
require_once $root . '/lib/EvoWebhookGuard.php';
require_once $root . '/lib/WaLocation.php';
require_once $root . '/lib/LeadMatcher.php';
require_once $root . '/lib/AiLeadService.php';
require_once $root . '/lib/CrmApiClient.php';
require_once $root . '/lib/UcrmLeadSync.php';
require_once $root . '/lib/BrainContext.php';
require_once $root . '/lib/ReplyPrivacyGuard.php';
require_once $root . '/lib/DishNetAiBrain.php';
require_once $root . '/workers/WorkerBase.php';
require_once $root . '/workers/AiReplyWorker.php';
require_once $root . '/workers/UcrmLeadWorker.php';

/** A brain that never leaves the process, but parses its canned answer with the REAL marker parser. */
class B0FakeBrain extends DishNetAiBrain
{
    public string $canned = '';
    public ?array $lastContext = null;
    public function isConfigured(): bool { return true; }
    public function reply(array $context): array
    {
        $this->lastContext = $context;
        $m = new ReflectionMethod(DishNetAiBrain::class, 'parseMarkers');
        $m->setAccessible(true);
        $out = $m->invoke($this, $this->canned);
        return is_array($out) ? $out : ['reply' => $this->canned];
    }
    public function getLastUsage(): array { return []; }
}

function b0_hit(int $port, string $path): string {
    $ch = curl_init("http://127.0.0.1:{$port}{$path}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_PROXY => '']);
    $r = curl_exec($ch); curl_close($ch);
    return $r === false ? '' : (string)$r;
}

/** The whole scenario against the plugin at $root; returns facts only (no asserting), so a weakened copy can run it too. */
function b0_scenario(string $root, int $evoPort, int $ucrmPort): array
{
    $tmp = sys_get_temp_dir() . '/dn_b0_' . bin2hex(random_bytes(4));
    @mkdir($tmp, 0777, true);
    putenv('DN_DATA_DIR=' . $tmp);
    b0_hit($evoPort, '/__test/reset');
    b0_hit($ucrmPort, '/__test/reset');
    b0_hit($ucrmPort, '/__test/scenario?name=fresh_install');

    $store = SqliteStore::create($tmp);
    $pdo   = $store->getPdo();
    $svc   = new ConversationService($tmp, $pdo);
    $bus   = new EventBus($pdo);
    $cfg = [
        'evo_api_url'          => "http://127.0.0.1:{$evoPort}",
        'evo_api_key'          => 'TESTKEY',
        'evo_instance_sales'   => 'dishnet_ug',
        'evo_instance_support' => 'dishnet_ug',
        'ai_provider'          => 'openai',
        'openai_api_key'       => 'test-key-never-called',
        'ai_lead_capture'      => '1',            // the EXISTING flags, inside this sandbox only
        'ai_crm_lead_sync'     => '1',
        'crm_base_url'         => "http://127.0.0.1:{$ucrmPort}",   // the fake uCRM, through CrmApiClient's manual path
        'crm_auth_token'       => 'test-key',
        'alert_whatsapp'       => '256700000999',
        'wa_human_cooldown_minutes' => 30,
    ];
    $phone = '256772000111';
    $cid   = (int)$svc->ensureConversation($phone, 'sales', null, 'test')['id'];
    // The pin this conversation actually sent, two turns ago: what the lead must carry (looked up, never believed).
    $svc->storeMessage($cid, ['direction' => 'in', 'role' => 'customer', 'body' => 'Location pin received: 0.3354, 32.5876',
        'media_type' => 'location', 'wa_message_id' => 'PIN-' . bin2hex(random_bytes(3)), 'location_lat' => 0.3354, 'location_lng' => 32.5876]);

    $mk = function (string $canned) use ($store, $cfg): array {
        $w = new AiReplyWorker($store, $cfg, 30, 10);
        $b = new B0FakeBrain($cfg); $b->canned = $canned;
        $rp = new ReflectionProperty(AiReplyWorker::class, 'brain'); $rp->setAccessible(true); $rp->setValue($w, $b);
        return [$w, $b];
    };
    $emit = function (int $cid, string $phone, string $text, ?array $location = null) use ($bus): int {
        $p = ['channel' => 'sales', 'whatsapp_instance' => 'dishnet_ug', 'customer_phone' => $phone, 'message' => $text,
              'push_name' => 'Test', 'wa_message_id' => 'CUST-' . bin2hex(random_bytes(3)), 'remote_jid' => $phone . '@s.whatsapp.net',
              'received_at' => gmdate('c')];
        if ($location !== null) $p['location'] = $location;
        return $bus->emit('ai.reply', 'conversation', $cid, $p, 3, 'test');
    };
    // WorkerBase::log() echoes; run_worker.php is what redirects that to ai_platform.log. Capture it here instead.
    $workerOut = '';
    $runQuiet = function ($worker) use (&$workerOut) { ob_start(); try { $r = $worker->run(); } finally { $workerOut .= (string)ob_get_clean(); } return $r; };
    $logText = function () use (&$workerOut): string { return $workerOut; };

    // ── 1. A sales turn whose answer carries a LEAD marker ─────────────────────────────────────────────
    [$w, $b] = $mk('Thank you — a Starlink Standard kit suits a hotel of that size, and I will have a quotation prepared for you. '
                 . '<<LEAD{"requirement":"Starlink for a 20-room hotel","location":"Gulu town","customer_type":"business",'
                 . '"company":"Acholi Inn","quote_requested":true,"ai_summary":"Hotel in Gulu wants Starlink, asked for a quotation"}>>');
    $emit($cid, $phone, 'I need Starlink for my hotel in Gulu, 20 rooms. Please send a quotation.');
    $runQuiet($w);
    $leads = $store->load('leads.json') ?? [];
    $lead  = $leads[0] ?? [];
    $evts  = $pdo->query("SELECT id, status, payload FROM events WHERE event_type = 'crm.lead.sync' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $evoState = json_decode(b0_hit($evoPort, '/__test/state'), true) ?: [];
    $facts = [
        'text_calls'        => count($evoState['text_calls'] ?? []),
        'reply_has_marker'  => strpos((string)(($evoState['text_calls'][0]['text'] ?? '')), '<<') !== false,
        'lead_count'        => count($leads),
        'lead_requirement'  => (string)($lead['requirement'] ?? ''),
        'lead_phone_tail'   => substr((string)($lead['phone'] ?? ''), -9),
        'lead_conversation' => (int)($lead['conversation_id'] ?? 0),
        'lead_source'       => (string)($lead['source'] ?? ''),
        'lead_lat'          => $lead['location_lat'] ?? null,
        'lead_lng'          => $lead['location_lng'] ?? null,
        'sync_events'       => count($evts),
        'sync_payload_lead' => (int)((json_decode((string)($evts[0]['payload'] ?? ''), true) ?: [])['lead_id'] ?? 0),
        'log_capture_failed'=> strpos($logText(), 'lead capture failed') !== false,
        'log_lead_line'     => preg_match('/conv \d+: lead (created|updated)/', $logText()) === 1,
        'ctx_has_location'  => false, 'prompt_has_pin' => false,
    ];

    // ── 2. A pin in the turn itself reaches the sales assistant ───────────────────────────────────────
    [$w2, $b2] = $mk('Thank you, I have your location and it is saved for the installation team.');
    $emit($cid, $phone, 'Location pin received', ['lat' => 0.3354, 'lng' => 32.5876, 'in_bounds' => true, 'name' => 'Hotel gate']);
    $runQuiet($w2);
    $lc = $b2->lastContext ?? [];
    $facts['ctx_has_location'] = isset($lc['location']['lat'], $lc['location']['lng']);
    $facts['ctx_location_name'] = (string)($lc['location']['name'] ?? '');
    $facts['ctx_has_conversation_id'] = array_key_exists('conversation_id', $lc);   // must stay false: not the model's business
    try { $facts['prompt_has_pin'] = strpos((new DishNetAiBrain($cfg))->promptPreview($lc), 'LOCATION PIN JUST RECEIVED') !== false; }
    catch (\Throwable $e) { $facts['prompt_has_pin'] = 'error: ' . $e->getMessage(); }

    // ── 3. A blocked reply on the sales number is audited against THIS conversation ───────────────────
    $cid2 = (int)$svc->ensureConversation('256772000222', 'sales', null, 'test')['id'];
    [$w3] = $mk('Between us, our cost on the Standard kit is far lower than the price, so there is room to negotiate.');
    $emit($cid2, '256772000222', 'Can you do a discount on the kit?');
    $runQuiet($w3);
    $audit = $store->load('ai_security_events.json') ?? [];
    $last  = is_array($audit) && $audit ? end($audit) : [];
    $facts['audit_rows']    = is_array($audit) ? count($audit) : -1;
    $facts['audit_conv_id'] = (int)($last['conversation_id'] ?? -1);
    $facts['audit_channel'] = (string)($last['channel'] ?? '');
    $facts['audit_expected_conv'] = $cid2;
    $calls = (json_decode(b0_hit($evoPort, '/__test/state'), true) ?: [])['text_calls'] ?? [];
    $facts['blocked_reply_sent_count'] = count($calls);
    $facts['blocked_text_leaked'] = false; $facts['fallback_sent'] = false; $facts['alert_sent'] = false;
    foreach ($calls as $c) {
        if (stripos((string)($c['text'] ?? ''), 'our cost') !== false) $facts['blocked_text_leaked'] = true;
        if (trim((string)($c['text'] ?? '')) === trim(\ReplyPrivacyGuard::SAFE_FALLBACK)) $facts['fallback_sent'] = true;
        if ((string)($c['number'] ?? '') === '256700000999') $facts['alert_sent'] = true;
    }

    // ── 4. The queue carries the lead into uCRM, as WorkerBase runs the worker ───────────────────────
    $uw = new UcrmLeadWorker($store, $cfg, 30, 10);
    $ur = $runQuiet($uw);
    $evts2 = $pdo->query("SELECT id, status, attempts, error FROM events WHERE event_type = 'crm.lead.sync' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $created = json_decode(b0_hit($ucrmPort, '/__test/clients'), true) ?: [];
    $leadAfter = ($store->load('leads.json') ?? [])[0] ?? [];
    $facts['sync_run']        = $ur;
    $facts['sync_status']     = (string)($evts2[0]['status'] ?? 'none');
    $facts['created_clients'] = count($created['clients'] ?? []);
    $facts['created_phone_tail'] = substr((string)(($created['clients'][0]['contacts'][0]['phone'] ?? $created['clients'][0]['phone'] ?? '')), -9);
    $facts['lead_crm_client'] = (int)($leadAfter['crm_client_id'] ?? 0);
    $facts['log_dropped']     = strpos($logText(), 'no lead_id — dropped') !== false;
    $facts['log_synced']      = preg_match('/lead #\d+ (created|linked|updated|patched)[^\n]*uCRM client #\d+/', $logText()) === 1;
    $facts['tmp'] = $tmp;
    exec('rm -rf ' . escapeshellarg($tmp));
    return $facts;
}

// ── Driver mode: one JSON line, nothing else ───────────────────────────────────────────────────────
if ($isDriver) {
    $facts = b0_scenario($root, (int)getenv('DN_T_EVO_PORT'), (int)getenv('DN_T_UCRM_PORT'));
    echo json_encode($facts), "\n";
    exit(0);
}

// ── Main: fakes, the real tree, then the weakened copies ─────────────────────────────────────────
require_once $root . '/tests/fixtures/staff_jobs_sandbox.php';   // sj_weakened_copy()
$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d !== '' ? "\n       " . substr($d, 0, 700) : '') . "\n"; } }

function b0_boot(string $router, int $base, string $sig): array {
    foreach (range(0, 11) as $slot) {
        $cand = $base + ((getmypid() + $slot * 13) % 70);
        $p = proc_open(sprintf('exec php -S 127.0.0.1:%d %s', $cand, escapeshellarg($router)), [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 40; $i++) {
            $r = b0_hit($cand, '/__test/state');
            if ($r !== '') { if (strpos($r, $sig) !== false) return [$p, $cand]; break; }
            usleep(100000);
        }
        if (is_resource($p)) { proc_terminate($p); proc_close($p); }
    }
    return [null, 0];
}
array_map('unlink', glob(sys_get_temp_dir() . '/fake_evo_state_*.json') ?: []);
array_map('unlink', glob(sys_get_temp_dir() . '/fake_ucrm_*.json') ?: []);
[$evoSrv, $evoPort]   = b0_boot($root . '/tests/fixtures/fake_evo_server.php', 9840, 'FAKE-EVO-TEST');
[$ucrmSrv, $ucrmPort] = b0_boot($root . '/tests/fixtures/fake_ucrm_server.php', 9920, 'FAKE-UCRM-TEST');
register_shutdown_function(function () use (&$evoSrv, &$ucrmSrv) {
    foreach ([$evoSrv, $ucrmSrv] as $s) if (is_resource($s)) { proc_terminate($s); proc_close($s); } });
if ($evoSrv === null || $ucrmSrv === null) { echo "  FAIL could not start the fake Evolution / uCRM servers\n"; exit(1); }
putenv('DN_T_EVO_PORT=' . $evoPort); putenv('DN_T_UCRM_PORT=' . $ucrmPort);

echo "A. The live tree: a LEAD marker becomes a lead, with the pin the conversation sent, and a sync event\n";
$f = b0_scenario($root, $evoPort, $ucrmPort);
$dump = json_encode($f);
is_($f['text_calls'] === 1 && $f['reply_has_marker'] === false, 'the customer got one reply and the <<LEAD>> marker was stripped from it', $dump);
is_($f['lead_count'] === 1, 'exactly one lead row was written (defect a: this used to be zero, every time)', $dump);
is_($f['lead_requirement'] === 'Starlink for a 20-room hotel' && $f['lead_phone_tail'] === '772000111' && $f['lead_source'] === 'whatsapp_ai',
    'the lead carries the requirement the model stated, the phone and the source', $dump);
is_($f['lead_conversation'] > 0, 'and the conversation id', $dump);
is_(is_numeric($f['lead_lat']) && abs((float)$f['lead_lat'] - 0.3354) < 1e-6 && abs((float)$f['lead_lng'] - 32.5876) < 1e-6,
    'its coordinates are the pin the conversation actually sent two turns earlier — looked up, not believed (latestPin now receives the context)', $dump);
is_($f['log_capture_failed'] === false && $f['log_lead_line'] === true, 'the log says "lead created", never "lead capture failed"', $dump);
is_($f['sync_events'] === 1 && $f['sync_payload_lead'] === 1, 'one crm.lead.sync event, its payload naming lead #1', $dump);

echo "\nB. The sales assistant is told about the pin (defect c), and still never told our database keys\n";
is_($f['ctx_has_location'] === true && $f['ctx_location_name'] === 'Hotel gate', 'the sales context carries the pin this turn sent, with its label', $dump);
is_($f['prompt_has_pin'] === true, 'the LOCATION PIN block appears in the sales prompt', $dump);
is_($f['ctx_has_conversation_id'] === false, 'conversation_id still does not travel to the model (BrainContext::NEVER_PRESENT)', $dump);

echo "\nC. A blocked reply on the sales number is audited against the real conversation (defect c)\n";
is_($f['audit_rows'] >= 1 && $f['audit_conv_id'] === $f['audit_expected_conv'] && $f['audit_conv_id'] > 0,
    'ai_security_events names the conversation that was blocked, not conversation 0', $dump);
is_($f['audit_channel'] === 'sales', '…on the sales channel', $dump);
is_($f['blocked_text_leaked'] === false && $f['fallback_sent'] === true && $f['alert_sent'] === true && $f['blocked_reply_sent_count'] === 4,
    'the customer received the safe fallback and never the blocked text; the staff alert went out (four sends in all: two replies, the fallback, the alert)', $dump);

echo "\nD. The queue carries the lead into uCRM, as WorkerBase runs the worker (defect b)\n";
is_($f['sync_status'] === 'done', 'the crm.lead.sync event is processed to done', $dump);
is_($f['created_clients'] === 1 && $f['created_phone_tail'] === '772000111', 'the fake uCRM received exactly one new client, for that phone', $dump);
is_($f['lead_crm_client'] > 0, 'and the lead row now carries the uCRM client id', $dump);
is_($f['log_dropped'] === false && $f['log_synced'] === true, 'the log says the lead reached uCRM, never "no lead_id — dropped"', $dump);

echo "\nE. Weakened copies are caught — each defect put back, one at a time\n";
$mutants = [
  ['defect a put back: latestPin receives $ctx again', 'workers/AiReplyWorker.php',
   '$this->latestPin($convId, $context));', '$this->latestPin($convId, $ctx));',
   fn(array $m): bool => $m['lead_count'] === 0 && $m['log_capture_failed'] === true && $m['sync_events'] === 0],
  ['defect b put back: the uCRM worker reads the undecoded payload', 'workers/UcrmLeadWorker.php',
   "        // nothing, logged \"no lead_id — dropped\" and acknowledged the event — so no lead ever reached uCRM.\n        \$p      = self::payloadOf(\$event);\n", "        // nothing, logged \"no lead_id — dropped\" and acknowledged the event — so no lead ever reached uCRM.\n        \$p      = is_array(\$event['payload'] ?? null) ? \$event['payload'] : [];\n",
   fn(array $m): bool => $m['sync_status'] === 'done' && $m['created_clients'] === 0 && $m['log_dropped'] === true && $m['lead_crm_client'] === 0],
  ['defect c put back: the sales context has no pin', 'workers/AiReplyWorker.php',
   "                    'location'  => \$ctx['location'] ?? null,\n", "",
   fn(array $m): bool => $m['ctx_has_location'] === false && $m['prompt_has_pin'] === false],
  ['defect c put back: the guard reads the id off the stripped context', 'workers/AiReplyWorker.php',
   "        return (int)(\$this->turnIds['conversation_id'] ?? (\$ctx['conversation_id'] ?? 0));", "        return (int)(\$ctx['conversation_id'] ?? 0);",
   fn(array $m): bool => $m['audit_conv_id'] === 0 && $m['audit_rows'] >= 1],
];
foreach ($mutants as $i => [$label, $rel, $old, $new, $caught]) {
    [$copy, $n] = sj_weakened_copy($root, $rel, $old, $new);
    is_($n === 1, "mutant " . ($i + 1) . ": the anchor is unique in $rel (the copy is only weakened when it is)", "count $n");
    $out = []; $rc = 0;
    exec('DN_T_EVO_PORT=' . $evoPort . ' DN_T_UCRM_PORT=' . $ucrmPort . ' php ' . escapeshellarg($copy . '/tests/test_lead_path_batch0.php') . ' --driver 2>&1', $out, $rc);
    $m = json_decode((string)end($out), true) ?: [];
    is_($rc === 0 && $m !== [] && $caught($m) === true, "mutant " . ($i + 1) . " is caught: $label", "rc=$rc " . substr(implode("\n", $out), -600));
    exec('rm -rf ' . escapeshellarg($copy));
}
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
