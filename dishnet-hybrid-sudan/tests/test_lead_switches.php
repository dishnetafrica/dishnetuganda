<?php
declare(strict_types=1);
/**
 * test_lead_switches.php — 5.18.87 (docs/07, 07 Oct): the Batch 0 lead fixes (docs/55 §3) released with the switches the
 * operator chose on 07 Oct — ai_lead_capture ON, ai_crm_lead_sync OFF ("Leads on, uCRM later") — and what each switch
 * does on its own.
 *
 * Written to run UNCHANGED on the development branch and on release/5.18.87 (live 5.18.86 + Batch 0 as built in b0674bd).
 * Proved at the worker level, as test_lead_path_batch0.php does: a canned model answer parsed by the REAL DishNetAiBrain
 * marker parser → AiReplyWorker → AiLeadService → the events queue → UcrmLeadWorker as WorkerBase runs it → the fake uCRM.
 *
 *   A  capture ON, uCRM OFF — the state the deploy requires: one lead row, with the pin the conversation sent; its
 *      crm.lead.sync event settled 'done' with "not synced — ai_crm_lead_sync is off", never retried; uCRM receives
 *      nothing; the lead carries no uCRM client
 *   B  the uCRM write switched ON afterwards: the lead captured while it was off is NOT sent by itself; the next qualified
 *      turn that adds something updates that lead (still one row), and that sync reaches uCRM — one client, the lead linked
 *   C  capture OFF: the customer's reply goes out with the marker stripped; no lead row; no event
 *   D  the support number answering a sales enquiry (ai_sales_on_all_numbers ON): its LEAD marker records a lead too
 *   E  the instruction to record a lead is in the prompt only where it can be acted on: ai_qualification ON, on a number
 *      that sells (sales; support and account only with ai_sales_on_all_numbers) — and never with ai_lead_capture OFF
 *   X  weakened copies, each caught: Batch 0's defect (a) put back; the uCRM write ignoring its switch; capture ignoring
 *      its switch; a switched-off sync treated as a failure and retried
 *
 * The switches are the EXISTING ones; nothing here changes their meaning, and they are set only inside the sandbox.
 * Every number, name and address here is fictitious; nothing leaves 127.0.0.1.
 * Driver mode: php tests/test_lead_switches.php --driver   (env DN_T_EVO_PORT, DN_T_UCRM_PORT) → one JSON line.
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
class LsFakeBrain extends DishNetAiBrain
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

function ls_hit(int $port, string $path): string {
    $ch = curl_init("http://127.0.0.1:{$port}{$path}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_PROXY => '']);
    $r = curl_exec($ch); curl_close($ch);
    return $r === false ? '' : (string)$r;
}

/** The whole scenario against the plugin at $root; returns facts only (no asserting), so a weakened copy can run it too. */
function ls_scenario(string $root, int $evoPort, int $ucrmPort): array
{
    $tmp = sys_get_temp_dir() . '/dn_ls_' . bin2hex(random_bytes(4));
    @mkdir($tmp, 0777, true);
    putenv('DN_DATA_DIR=' . $tmp);
    ls_hit($evoPort, '/__test/reset');
    ls_hit($ucrmPort, '/__test/reset');
    ls_hit($ucrmPort, '/__test/scenario?name=fresh_install');

    $store = SqliteStore::create($tmp);
    $pdo   = $store->getPdo();
    $svc   = new ConversationService($tmp, $pdo);
    $bus   = new EventBus($pdo);
    // Production's shape (docs/07, 07 Oct): one sales number; support and account on ONE shared number.
    $cfg = [
        'evo_api_url'             => "http://127.0.0.1:{$evoPort}",
        'evo_api_key'             => 'TESTKEY',
        'evo_instance_sales'      => 'ls_sales',
        'evo_instance_support'    => 'ls_shared',
        'evo_instance_account'    => 'ls_shared',
        'ai_provider'             => 'openai',
        'openai_api_key'          => 'test-key-never-called',
        'ai_qualification'        => '1',
        'ai_sales_on_all_numbers' => '1',
        'ai_lead_capture'         => '1',      // the operator's choice for 5.18.87 …
        'ai_crm_lead_sync'        => '0',      // … leads on, the uCRM write later
        'crm_base_url'            => "http://127.0.0.1:{$ucrmPort}",
        'crm_auth_token'          => 'test-key',
        'alert_whatsapp'          => '256700000999',
        'wa_human_cooldown_minutes' => 30,
    ];
    $workerOut = '';
    $runQuiet = function ($worker) use (&$workerOut) { ob_start(); try { $r = $worker->run(); } finally { $workerOut .= (string)ob_get_clean(); } return $r; };
    $mk = function (array $c, string $canned) use ($store): array {
        $w = new AiReplyWorker($store, $c, 30, 10);
        $b = new LsFakeBrain($c); $b->canned = $canned;
        $rp = new ReflectionProperty(AiReplyWorker::class, 'brain'); $rp->setAccessible(true); $rp->setValue($w, $b);
        return [$w, $b];
    };
    $emit = function (int $cid, string $phone, string $text, string $channel, string $instance) use ($bus): int {
        return $bus->emit('ai.reply', 'conversation', $cid, [
            'channel' => $channel, 'whatsapp_instance' => $instance, 'customer_phone' => $phone, 'message' => $text,
            'push_name' => 'Test', 'wa_message_id' => 'CUST-' . bin2hex(random_bytes(3)),
            'remote_jid' => $phone . '@s.whatsapp.net', 'received_at' => gmdate('c')], 3, 'test');
    };
    $sync = function () use ($pdo): array {
        return $pdo->query("SELECT id, status, attempts, payload FROM events WHERE event_type = 'crm.lead.sync' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    };
    $clients = function () use ($ucrmPort): array { return (array)((json_decode(ls_hit($ucrmPort, '/__test/clients'), true) ?: [])['clients'] ?? []); };
    $texts   = function () use ($evoPort): array { return (array)((json_decode(ls_hit($evoPort, '/__test/state'), true) ?: [])['text_calls'] ?? []); };
    $leadsOf = function (string $tail) use ($store): array {
        return array_values(array_filter((array)($store->load('leads.json') ?? []), function ($l) use ($tail) {
            return substr((string)($l['phone'] ?? ''), -9) === $tail; }));
    };
    $f = [];

    // ── A. capture ON, uCRM OFF: the lead is written, its sync is settled without touching uCRM ───────────────
    $phoneA = '256772000301';
    $cidA = (int)$svc->ensureConversation($phoneA, 'sales', null, 'test')['id'];
    $svc->storeMessage($cidA, ['direction' => 'in', 'role' => 'customer', 'body' => 'Location pin received: 0.3476, 32.5825',
        'media_type' => 'location', 'wa_message_id' => 'PIN-' . bin2hex(random_bytes(3)), 'location_lat' => 0.3476, 'location_lng' => 32.5825]);
    [$wA, $bA] = $mk($cfg, 'A Starlink Standard kit suits a guest house of that size; I will have a quotation prepared for you. '
        . '<<LEAD{"requirement":"Starlink for a 12-room guest house","location":"Kampala","customer_type":"business",'
        . '"ai_summary":"Guest house in Kampala wants Starlink"}>>');
    $emit($cidA, $phoneA, 'I need internet for my guest house in Kampala, 12 rooms.', 'sales', 'ls_sales');
    $runQuiet($wA);
    $leadA = $leadsOf('772000301');
    $evA = $sync();
    $f['a_reply_count']   = count($texts());
    $f['a_reply_clean']   = strpos((string)($texts()[0]['text'] ?? ''), '<<') === false;
    $f['a_leads']         = count($leadA);
    $f['a_source']        = (string)($leadA[0]['source'] ?? '');
    $f['a_lat']           = $leadA[0]['location_lat'] ?? null;
    $f['a_events']        = count($evA);
    $f['a_capture_failed']= strpos($workerOut, 'lead capture failed') !== false;
    $f['ctx_sales']       = $bA->lastContext ?? [];
    $ur = $runQuiet(new UcrmLeadWorker($store, $cfg, 30, 10));
    $evA2 = $sync();
    $f['a_sync_status']   = (string)($evA2[0]['status'] ?? 'none');
    $f['a_sync_attempts'] = (int)($evA2[0]['attempts'] ?? -1);
    $f['a_clients']       = count($clients());
    $f['a_log_off']       = preg_match('/lead #\d+ not synced — ai_crm_lead_sync is off/', $workerOut) === 1;
    $f['a_lead_client']   = (int)(($leadsOf('772000301')[0] ?? [])['crm_client_id'] ?? 0);
    // A second pass finds nothing left to do: a switched-off sync is a decision, not a fault, and is never retried.
    $runQuiet(new UcrmLeadWorker($store, $cfg, 30, 10));
    $f['a_clients_after_rerun'] = count($clients());
    $f['a_events_after_rerun']  = count($sync());

    // ── B. the uCRM write switched ON later ────────────────────────────────────────────────────────────────
    $cfgOn = ['ai_crm_lead_sync' => '1'] + $cfg;
    $runQuiet(new UcrmLeadWorker($store, $cfgOn, 30, 10));
    $f['b_clients_before_update'] = count($clients());
    [$wB] = $mk($cfgOn, 'Thank you — I have your e-mail and the team will send the quotation there. '
        . '<<LEAD{"requirement":"Starlink for a 12-room guest house","email":"guesthouse@example.test","quote_requested":true}>>');
    $emit($cidA, $phoneA, 'Please send the quotation to guesthouse@example.test', 'sales', 'ls_sales');
    $runQuiet($wB);
    $f['b_leads']          = count($leadsOf('772000301'));
    $f['b_events']         = count($sync());
    $f['b_log_updated']    = preg_match('/conv ' . $cidA . ': lead updated/', $workerOut) === 1;
    $runQuiet(new UcrmLeadWorker($store, $cfgOn, 30, 10));
    $evB = $sync();
    $cl  = $clients();
    $f['b_sync_status']    = (string)(end($evB)['status'] ?? 'none');
    $f['b_clients']        = count($cl);
    $f['b_client_tail']    = substr((string)($cl[0]['contacts'][0]['phone'] ?? $cl[0]['phone'] ?? ''), -9);
    $f['b_lead_client']    = (int)(($leadsOf('772000301')[0] ?? [])['crm_client_id'] ?? 0);

    // ── C. capture OFF: the reply still goes, nothing is recorded ──────────────────────────────────────────
    $cfgNoCap = ['ai_lead_capture' => '0'] + $cfg;
    $phoneC = '256772000302';
    $cidC = (int)$svc->ensureConversation($phoneC, 'sales', null, 'test')['id'];
    $n0 = count($texts()); $e0 = count($sync());
    [$wC] = $mk($cfgNoCap, 'A Starlink Mini kit suits a small shop; I can prepare a quotation. '
        . '<<LEAD{"requirement":"Starlink for a small shop","location":"Jinja","customer_type":"business"}>>');
    $emit($cidC, $phoneC, 'How much for internet in my shop in Jinja?', 'sales', 'ls_sales');
    $runQuiet($wC);
    $tC = array_slice($texts(), $n0);
    $f['c_replies']     = count($tC);
    $f['c_reply_clean'] = strpos((string)($tC[0]['text'] ?? ''), '<<') === false;
    $f['c_leads']       = count($leadsOf('772000302'));
    $f['c_new_events']  = count($sync()) - $e0;

    // ── D. the support number answering a sales enquiry records a lead too ─────────────────────────────────
    $phoneD = '256772000303';
    $cidD = (int)$svc->ensureConversation($phoneD, 'support', null, 'test')['id'];
    $n0 = count($texts()); $e0 = count($sync());
    [$wD, $bD] = $mk($cfg, 'Yes, we can connect your office; a Starlink Standard kit fits ten people and I will have a quotation prepared. '
        . '<<LEAD{"requirement":"Starlink for an office of 10","location":"Mbarara","customer_type":"business"}>>');
    $emit($cidD, $phoneD, 'How much to connect my office in Mbarara, 10 people?', 'support', 'ls_shared');
    $runQuiet($wD);
    $leadD = $leadsOf('772000303');
    $f['d_replies']     = count(array_slice($texts(), $n0));
    $f['d_reply_from']  = (string)((array_slice($texts(), $n0)[0] ?? [])['instance'] ?? '');
    $f['d_leads']       = count($leadD);
    $f['d_source']      = (string)($leadD[0]['source'] ?? '');
    $f['d_conv']        = (int)($leadD[0]['conversation_id'] ?? 0);
    $f['d_conv_expect'] = $cidD;
    $f['d_new_events']  = count($sync()) - $e0;
    $f['ctx_support']   = $bD->lastContext ?? [];

    // ── E. where the instruction to record a lead appears ──────────────────────────────────────────────────
    // A prompt that could not be built is an error, never "absent": an absence must be read off a prompt that exists.
    $has = function (array $c, array $ctx) {
        try { return strpos((new DishNetAiBrain($c))->promptPreview($ctx), 'RECORDING A SALES OPPORTUNITY') !== false; }
        catch (\Throwable $e) { return 'error: ' . $e->getMessage(); }
    };
    $ctxS = (array)$f['ctx_sales']; $ctxP = (array)$f['ctx_support'];
    $ctxS['channel'] = 'sales'; $ctxP['channel'] = 'support';
    $ctxA = $ctxP; $ctxA['channel'] = 'account';
    $f['e'] = [
        'sales'                    => $has($cfg, $ctxS),
        'support_sales_everywhere' => $has($cfg, $ctxP),
        'account_sales_everywhere' => $has($cfg, $ctxA),
        'support_sales_only'       => $has(['ai_sales_on_all_numbers' => '0'] + $cfg, $ctxP),
        'sales_no_qualification'   => $has(['ai_qualification' => '0'] + $cfg, $ctxS),
        'sales_no_capture'         => $has(['ai_lead_capture' => '0'] + $cfg, $ctxS),
    ];
    unset($f['ctx_sales'], $f['ctx_support']);
    exec('rm -rf ' . escapeshellarg($tmp));
    return $f;
}

// ── Driver mode: one JSON line, nothing else ───────────────────────────────────────────────────────────────
if ($isDriver) {
    echo json_encode(ls_scenario($root, (int)getenv('DN_T_EVO_PORT'), (int)getenv('DN_T_UCRM_PORT'))), "\n";
    exit(0);
}

// ── Main: the fakes, the tree under test, then the weakened copies ──────────────────────────────────────────
require_once $root . '/tests/fixtures/staff_jobs_sandbox.php';   // sj_weakened_copy()
$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d !== '' ? "\n       " . substr($d, 0, 900) : '') . "\n"; } }

function ls_boot(string $router, int $base, string $sig): array {
    foreach (range(0, 11) as $slot) {
        $cand = $base + ((getmypid() + $slot * 13) % 70);
        $p = proc_open(sprintf('exec php -S 127.0.0.1:%d %s', $cand, escapeshellarg($router)), [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 40; $i++) {
            $r = ls_hit($cand, '/__test/state');
            if ($r !== '') { if (strpos($r, $sig) !== false) return [$p, $cand]; break; }
            usleep(100000);
        }
        if (is_resource($p)) { proc_terminate($p); proc_close($p); }
    }
    return [null, 0];
}
[$evoSrv, $evoPort]   = ls_boot($root . '/tests/fixtures/fake_evo_server.php', 9520, 'FAKE-EVO-TEST');
[$ucrmSrv, $ucrmPort] = ls_boot($root . '/tests/fixtures/fake_ucrm_server.php', 9600, 'FAKE-UCRM-TEST');
register_shutdown_function(function () use (&$evoSrv, &$ucrmSrv) {
    foreach ([$evoSrv, $ucrmSrv] as $s) if (is_resource($s)) { proc_terminate($s); proc_close($s); } });
if ($evoSrv === null || $ucrmSrv === null) { echo "  FAIL could not start the fake Evolution / uCRM servers\n"; exit(1); }
putenv('DN_T_EVO_PORT=' . $evoPort); putenv('DN_T_UCRM_PORT=' . $ucrmPort);

$f = ls_scenario($root, $evoPort, $ucrmPort);
$dump = json_encode($f);

echo "A. Leads on, the uCRM write off — the state 5.18.87's deploy requires\n";
is_($f['a_reply_count'] === 1 && $f['a_reply_clean'] === true, 'the customer got one reply, the <<LEAD>> marker stripped from it', $dump);
is_($f['a_leads'] === 1 && $f['a_source'] === 'whatsapp_ai' && $f['a_capture_failed'] === false,
    'a qualified sales enquiry becomes exactly one lead, source whatsapp_ai — never "lead capture failed"', $dump);
is_(is_numeric($f['a_lat']) && abs((float)$f['a_lat'] - 0.3476) < 1e-6, 'the lead carries the pin the conversation sent', $dump);
is_($f['a_events'] === 1, 'one crm.lead.sync event is queued for it', $dump);
is_($f['a_sync_status'] === 'done' && $f['a_log_off'] === true,
    'the uCRM worker settles it as done: "not synced — ai_crm_lead_sync is off"', $dump);
is_($f['a_clients'] === 0 && $f['a_lead_client'] === 0, 'uCRM receives nothing, and the lead carries no uCRM client', $dump);
is_($f['a_clients_after_rerun'] === 0 && $f['a_events_after_rerun'] === 1, 'a second pass finds nothing to do: a switched-off sync is never retried', $dump);

echo "\nB. The uCRM write switched on afterwards\n";
is_($f['b_clients_before_update'] === 0, 'a lead captured while the write was off is NOT sent by itself when it is switched on', $dump);
is_($f['b_leads'] === 1 && $f['b_log_updated'] === true && $f['b_events'] === 2,
    'the same customer\'s next qualified turn updates that lead (still one row) and queues a second sync', $dump);
is_($f['b_sync_status'] === 'done' && $f['b_clients'] === 1 && $f['b_client_tail'] === '772000301' && $f['b_lead_client'] > 0,
    'that sync reaches uCRM: one lead client for that phone, and the lead now carries its id', $dump);

echo "\nC. Capture off\n";
is_($f['c_replies'] === 1 && $f['c_reply_clean'] === true, 'the customer still gets the reply, the marker stripped', $dump);
is_($f['c_leads'] === 0 && $f['c_new_events'] === 0, 'no lead is written and nothing is queued', $dump);

echo "\nD. The support number answering a sales enquiry (ai_sales_on_all_numbers)\n";
is_($f['d_replies'] === 1 && $f['d_reply_from'] === 'ls_shared', 'the reply leaves from the number the customer wrote to', $dump);
is_($f['d_leads'] === 1 && $f['d_source'] === 'whatsapp_ai' && $f['d_conv'] === $f['d_conv_expect'] && $f['d_new_events'] === 1,
    'its LEAD marker records a lead too, linked to that support conversation, with its sync queued', $dump);

echo "\nE. Where the assistant is asked to record a lead\n";
$e = (array)$f['e'];
is_($e['sales'] === true, 'on the sales number, with ai_qualification and ai_lead_capture on', $dump);
is_($e['support_sales_everywhere'] === true && $e['account_sales_everywhere'] === true,
    'on the support and account numbers too, because ai_sales_on_all_numbers puts them in the selling business', $dump);
is_($e['support_sales_only'] === false, 'not on the support number when ai_sales_on_all_numbers is off', $dump);
is_($e['sales_no_qualification'] === false, 'nowhere with ai_qualification off — the instruction lives in the qualification rules', $dump);
is_($e['sales_no_capture'] === false, 'nowhere with ai_lead_capture off', $dump);

echo "\nX. Weakened copies, each caught\n";
$mutants = [
  ['Batch 0 defect (a) put back: latestPin receives $ctx again', 'workers/AiReplyWorker.php',
   '$this->latestPin($convId, $context));', '$this->latestPin($convId, $ctx));',
   fn(array $m): bool => $m['a_leads'] === 0 && $m['a_capture_failed'] === true && $m['a_events'] === 0],
  ['the uCRM write ignores ai_crm_lead_sync', 'lib/UcrmLeadSync.php',
   "if (!\$this->enabled())          return \$this->no('disabled', 'ai_crm_lead_sync is off');",
   "if (false)          return \$this->no('disabled', 'ai_crm_lead_sync is off');",
   fn(array $m): bool => $m['a_clients'] > 0],
  ['lead capture ignores ai_lead_capture', 'lib/AiLeadService.php',
   "if (!\$this->enabled())  return \$this->no('disabled', 'ai_lead_capture is off');",
   "if (false)  return \$this->no('disabled', 'ai_lead_capture is off');",
   fn(array $m): bool => $m['c_leads'] > 0],
  ['a switched-off sync is treated as a failure and retried', 'workers/UcrmLeadWorker.php',
   "if (in_array(\$r['action'], ['disabled', 'skipped'], true)) {",
   "if (in_array(\$r['action'], ['skipped'], true)) {",
   fn(array $m): bool => $m['a_sync_status'] !== 'done'],
];
foreach ($mutants as $i => [$label, $rel, $old, $new, $caught]) {
    [$copy, $n] = sj_weakened_copy($root, $rel, $old, $new);
    is_($n === 1, 'mutant ' . ($i + 1) . ": the anchor is unique in $rel (the copy is only weakened when it is)", "count $n");
    $out = []; $rc = 0;
    exec('DN_T_EVO_PORT=' . $evoPort . ' DN_T_UCRM_PORT=' . $ucrmPort . ' php ' . escapeshellarg($copy . '/tests/test_lead_switches.php') . ' --driver 2>&1', $out, $rc);
    $m = json_decode((string)end($out), true) ?: [];
    is_($rc === 0 && $m !== [] && $caught($m) === true, 'mutant ' . ($i + 1) . " is caught: $label", "rc=$rc " . substr(implode("\n", $out), -700));
    exec('rm -rf ' . escapeshellarg($copy));
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
