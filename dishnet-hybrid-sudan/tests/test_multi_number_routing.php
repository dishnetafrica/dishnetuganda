<?php
declare(strict_types=1);
/**
 * test_multi_number_routing.php — 5.18.86 (docs/65, multi-number Batch 1, parts A, B, H, I, J, K, L, P; Part Q tests 7–24):
 * every message goes in and out on its own number, end to end, on the real plugin.
 *
 * The plugin is served by php -S (SjSandbox) beside a fake Evolution and a fake uCRM; the webhook is POSTed exactly as
 * Evolution posts it, the Inbox is called through the staff API as the panel calls it, the cron scripts run as the
 * scheduler runs them, and the AI worker runs in this process with a brain that never leaves it (its
 * canned answer is parsed by the REAL marker parser). Every number, name and instance here is fictitious; nothing
 * leaves 127.0.0.1.
 *
 *   W  inbound: instance → channel → conversation (phone + channel); a switched-off number refused; the assistant off
 *   K  the AI worker: the role from the channel, the reply on the number the message came in on, never another;
 *      identity global, history per conversation; a mismatch, a disabled channel, an AI-off channel
 *   I  the Inbox (the approved fix): sales → sales, support → support, account → account, a registry channel → its own
 *      number, a failure reported and nothing sent from any other number
 *   L  leads carry the channel they came in on; the Batch 0 path; the uCRM sync
 *   F  follow-ups leave only on their own conversation's number
 *   N  notifications (sendVia) unchanged
 *   G  golden: the three department numbers, flag OFF and ON, identical in every observable
 *   S  South Sudan: unchanged, flag or no flag
 *   X  weakened copies, each caught
 *
 * release/5.18.88: production has no AI media layer, so this copy of Batch 1's test has no part M (the media worker), no
 * weakened copy of it and no media switch. The uncertain-send case (a gateway 504) is not driven here: production's fake
 * Evolution cannot answer one. That reply path is the Inbox's, live since 5.18.86 and unchanged by this release.
 *
 * Driver mode: php tests/test_multi_number_routing.php --driver <plugin-root> <part,part,...>  (env DN_T_UCRM_PORT)
 */
$self     = __FILE__;
$isDriver = in_array('--driver', $argv ?? [], true);
$di       = $isDriver ? array_search('--driver', $argv, true) : false;
$root     = $isDriver ? (string)$argv[$di + 1] : dirname(__DIR__);
$parts    = $isDriver ? explode(',', (string)($argv[$di + 2] ?? 'all')) : ['all'];
date_default_timezone_set('UTC');

require_once $root . '/tests/fixtures/staff_jobs_sandbox.php';
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
require_once $root . '/lib/EvolutionApiService.php';
require_once $root . '/lib/ChannelRegistry.php';
require_once $root . '/lib/NotificationService.php';
require_once $root . '/lib/FollowUpPolicy.php';
require_once $root . '/lib/FollowUpService.php';
require_once $root . '/workers/WorkerBase.php';
require_once $root . '/workers/AiReplyWorker.php';
require_once $root . '/workers/UcrmLeadWorker.php';

/** A brain that never leaves the process; its canned answer goes through the REAL marker parser. */
class MnBrain extends DishNetAiBrain
{
    public string $canned = '';
    /** @var array[] every context it was handed */
    public array $contexts = [];
    public function isConfigured(): bool { return true; }
    public function reply(array $context): array
    {
        $this->contexts[] = $context;
        $m = new ReflectionMethod(DishNetAiBrain::class, 'parseMarkers');
        $m->setAccessible(true);
        $out = $m->invoke($this, $this->canned);
        return is_array($out) ? $out : ['reply' => $this->canned];
    }
    public function getLastUsage(): array { return []; }
}

function mn_hit(int $port, string $path): string
{
    $ch = curl_init("http://127.0.0.1:{$port}{$path}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*']);
    $r = curl_exec($ch); curl_close($ch);
    return $r === false ? '' : (string)$r;
}

/** A zone where it is now between 09:00 and 18:00 on a day that is not Sunday, or '' when there is none. */
function mn_open_zone(): string
{
    foreach (['Africa/Kampala', 'Europe/London', 'Europe/Berlin', 'Asia/Dubai', 'Asia/Kolkata', 'Asia/Bangkok', 'Asia/Tokyo',
              'Australia/Sydney', 'Pacific/Auckland', 'Pacific/Kiritimati', 'Pacific/Pago_Pago', 'Pacific/Honolulu',
              'America/Anchorage', 'America/Los_Angeles', 'America/Denver', 'America/Chicago', 'America/New_York',
              'America/Sao_Paulo', 'Atlantic/Azores', 'Atlantic/Cape_Verde', 'America/Noronha', 'Asia/Kathmandu'] as $z) {
        $t = new DateTimeImmutable('now', new DateTimeZone($z));
        $h = (int)$t->format('G');
        if ((int)$t->format('w') !== 0 && $h >= 9 && $h <= 18) return $z;
    }
    return '';
}

/** The whole Uganda scenario (or the parts named), facts only. */
function mn_uganda(string $root, array $parts, int $ucrmPort): array
{
    $all = in_array('all', $parts, true);
    $want = function (string $p) use ($all, $parts): bool { return $all || in_array($p, $parts, true); };
    $f = [];
    $zone = mn_open_zone();
    $base = ['tenant_profile' => 'uganda', 'ai_enabled' => '1', 'ai_currency' => 'UGX', 'ai_provider' => 'openai',
             'openai_api_key' => 'test-key-never-called', 'alert_whatsapp' => '256700000999',
             'ai_handover_message' => 'MN-HOLDING-LINE: a colleague will reply shortly.', 'ai_lead_capture' => '1',
             'ai_crm_lead_sync' => '1', 'followup_enabled' => '1', 'wa_human_cooldown_minutes' => 30]
             + ($zone !== '' ? ['timezone' => $zone] : []);
    $s   = SjSandbox::start($root, $base, 'mn');
    $pdo = $s->store()->getPdo();
    $cfgNow = $s->cfg;
    $setCfg = function (array $ov) use ($s, &$cfgNow): void {
        $cfgNow = array_merge($cfgNow, $ov);
        foreach ($ov as $k => $v) if ($v === null) unset($cfgNow[$k]);
        $s->store()->save('kyc_config.json', $cfgNow);
        file_put_contents($s->data . '/kyc_config.json', json_encode($cfgNow));
    };
    $flag = function (bool $on) use ($setCfg): void { $setCfg([ChannelRegistry::FLAG => $on ? '1' : null]); };
    $q = function (string $sql, array $p = []) use ($s): array { return $s->q($sql, $p); };
    $conv = function (string $phone, string $channel) use ($q): array {
        $r = $q('SELECT id, state, crm_client_id FROM wa_conversations WHERE phone = ? AND channel = ?', [$phone, $channel]);
        return $r[0] ?? [];
    };
    $evoState = function () use ($s): array { return (array)($s->http('GET', "{$s->evo}/__test/state")[2] ?? []); };
    $nText = function () use ($evoState): int { return count($evoState()['text_calls'] ?? []); };
    $textsSince = function (int $n) use ($evoState): array { return array_slice((array)($evoState()['text_calls'] ?? []), $n); };
    $mediaSince = function (int $n) use ($evoState): array { return array_slice((array)($evoState()['media_calls'] ?? []), $n); };
    $nMedia = function () use ($evoState): int { return count($evoState()['media_calls'] ?? []); };
    $in = function (string $phone, string $text, string $instance, string $id = '') use ($s): array {
        $r = $s->evoInbound($phone, $text, $instance, $id !== '' ? $id : 'MN-' . bin2hex(random_bytes(5)));
        return ['http' => $r[0], 'outcome' => $r[2]['outcome'] ?? null, 'channel' => $r[2]['channel'] ?? null,
                'queued' => $r[2]['queued'] ?? null, 'skipped' => $r[2]['skipped'] ?? null];
    };
    $lastReplyEvent = function () use ($q): array {
        $r = $q("SELECT id, status, payload FROM events WHERE event_type = 'ai.reply' ORDER BY id DESC LIMIT 1");
        $p = json_decode((string)($r[0]['payload'] ?? ''), true) ?: [];
        return ['id' => (int)($r[0]['id'] ?? 0), 'status' => $r[0]['status'] ?? null, 'payload' => $p];
    };
    $nEvents = function (string $type) use ($q): int { return (int)($q('SELECT COUNT(*) c FROM events WHERE event_type = ?', [$type])[0]['c'] ?? 0); };
    $runWorker = function (string $canned) use ($s, &$cfgNow): array {
        $store = $s->store();
        $w = new AiReplyWorker($store, $cfgNow, 30, 10);
        $b = new MnBrain($cfgNow); $b->canned = $canned;
        $rp = new ReflectionProperty(AiReplyWorker::class, 'brain'); $rp->setAccessible(true); $rp->setValue($w, $b);
        ob_start();
        try { $res = $w->run(); } finally { $log = (string)ob_get_clean(); }
        return ['brain' => $b, 'log' => $log, 'run' => $res];
    };
    $reg = function () use ($s): ChannelRegistry { return new ChannelRegistry($s->store()->getPdo(), $s->store()); };
    $norm = function (array $ctx): array {
        foreach (['customer_phone', 'conversation_id', 'whatsapp_instance', 'push_name'] as $k) if (array_key_exists($k, $ctx)) $ctx[$k] = '·';
        return $ctx;
    };

    // ── Staff, and the registry channels this test uses ──────────────────────────────────────────────
    $s->staff('admin', ['name' => 'Sandbox Admin', 'email' => 'admin@example.test', 'role' => 'admin', 'is_admin' => true]);
    $seller = $s->staff('seller', ['name' => 'Sandbox Seller', 'email' => 'seller@example.test', 'role' => 'sales']);
    $cm = EvolutionApiService::configInstanceMap($cfgNow);
    $r = $reg();
    $r->create(['channel_id' => 'sales-002', 'evo_instance' => 'sj-sales-2', 'display_name' => 'Sales — Kampala 2', 'role' => 'sales',
                'owner_type' => 'staff', 'owner_staff_id' => $seller, 'business_number' => '+256700555666', 'status' => 'active'],
               'routing test', 'a second sales number', $cm);
    $r->create(['channel_id' => 'sales-003', 'evo_instance' => 'sj-sales-3', 'display_name' => 'Sales three (assistant off)',
                'role' => 'sales', 'status' => 'active', 'ai_enabled' => false], 'routing test', 'assistant off', $cm);
    $r->create(['channel_id' => 'sales-004', 'evo_instance' => 'sj-sales-4', 'display_name' => 'Sales four (switched off)',
                'role' => 'sales', 'status' => 'disabled'], 'routing test', 'switched off', $cm);

    // ── W. Inbound ────────────────────────────────────────────────────────────────────────────────────
    if ($want('webhook')) {
        $flag(false);
        $f['w_off_sales'] = $in('256772100001', 'Hello sales', 'sj-sales');
        $p = $lastReplyEvent()['payload'];
        $f['w_off_payload'] = [$p['channel'] ?? null, $p['whatsapp_instance'] ?? null, $p['message'] ?? null];
        $f['w_off_registry'] = $in('256772100001', 'Hello number two', 'sj-sales-2');
        $f['w_off_registry_conv'] = $conv('256772100001', 'sales-002') === [];
        $flag(true);
        $f['w_on_sales'] = $in('256772100002', 'Hello sales', 'sj-sales');
        $p = $lastReplyEvent()['payload'];
        $f['w_on_payload_sales'] = [$p['channel'] ?? null, $p['whatsapp_instance'] ?? null, $p['message'] ?? null];
        $f['w_on_registry'] = $in('256772100002', 'Hello number two', 'SJ-SALES-2');
        $p = $lastReplyEvent()['payload'];
        $f['w_on_payload_registry'] = [$p['channel'] ?? null, $p['whatsapp_instance'] ?? null, array_keys($p)];
        $f['w_on_support'] = $in('256772100002', 'Hello support', 'sj-support');
        $f['w_convs'] = array_map(function ($r) { return $r['channel']; },
            $q("SELECT channel FROM wa_conversations WHERE phone = '256772100002' ORDER BY channel"));
        $f['w_conv_ids_distinct'] = count(array_unique(array_column($q("SELECT id FROM wa_conversations WHERE phone = '256772100002'"), 'id'))) === 3;
        $e0 = $nEvents('ai.reply'); $m0 = (int)($q("SELECT COUNT(*) c FROM wa_messages")[0]['c']);
        $f['w_disabled'] = $in('256772100003', 'Anyone there?', 'sj-sales-4');
        $f['w_unknown']  = $in('256772100003', 'Anyone there?', 'sj-nobody');
        $f['w_refused_wrote'] = [$nEvents('ai.reply') - $e0, (int)($q("SELECT COUNT(*) c FROM wa_messages")[0]['c']) - $m0,
                                 $conv('256772100003', 'sales-004') === []];
        $e0 = $nEvents('ai.reply');
        $f['w_ai_off'] = $in('256772100004', 'Is the assistant there?', 'sj-sales-3');
        $f['w_ai_off_effect'] = [$nEvents('ai.reply') - $e0, $conv('256772100004', 'sales-003') !== [],
            (int)($q("SELECT COUNT(*) c FROM wa_messages m JOIN wa_conversations c ON c.id = m.conversation_id WHERE c.phone = '256772100004'")[0]['c'])];
        // Clear the queue the webhook steps left, so the worker steps below see only their own turns.
        $pdo->exec("UPDATE events SET status = 'done' WHERE event_type = 'ai.reply' AND status = 'pending'");
    }

    // ── K. The AI worker ──────────────────────────────────────────────────────────────────────────────
    if ($want('worker')) {
        // One customer the CRM knows, by their number.
        // client_search_index is a structured table (ClientSearchIndex): a save() of the JSON name is discarded.
        $s->store()->getPdo()->prepare("INSERT INTO client_search_index (id, name, phone, phone_norm, service) VALUES (77, 'Index Customer', '+256772300001', '772300001', '')")->execute();
        $flag(false);
        $n0 = $nText();
        $in('256772300001', 'What does Starlink cost?', 'sj-sales');
        $k1 = $runWorker('The Standard kit is listed in our plans. MN-K1');
        $t = $textsSince($n0);
        $f['k1_send'] = array_map(function ($c) { return [$c['instance'], $c['number']]; }, $t);
        $f['k1_ctx_channel'] = $k1['brain']->contexts[0]['channel'] ?? null;
        $f['k1_conv_crm'] = (int)($conv('256772300001', 'sales')['crm_client_id'] ?? 0);
        $flag(true);
        $n0 = $nText();
        $in('256772300001', 'And on this number?', 'sj-sales-2');
        $k2 = $runWorker('Yes, same price. MN-K2');
        $t = $textsSince($n0);
        $ctx = $k2['brain']->contexts[0] ?? [];
        $f['k2_send'] = array_map(function ($c) { return [$c['instance'], $c['number']]; }, $t);
        $f['k2_ctx_channel'] = $ctx['channel'] ?? null;
        $j = json_encode($ctx);
        $f['k2_ctx_leaks'] = [strpos($j, 'sj-sales') !== false, strpos($j, '700555666') !== false, strpos($j, 'sales-002') !== false,
                              strpos($j, '256772300001') !== false];
        $f['k2_history_isolated'] = strpos($j, 'What does Starlink cost') === false && strpos($j, 'MN-K1') === false;
        $f['k2_identity'] = [(int)($conv('256772300001', 'sales-002')['crm_client_id'] ?? 0), $ctx['identity_state'] ?? null,
                             $ctx['customer']['name'] ?? null];
        $f['k2_reply_stored'] = (int)($q("SELECT COUNT(*) c FROM wa_messages WHERE conversation_id = ? AND direction = 'out' AND body LIKE '%MN-K2%'",
                                        [(int)($conv('256772300001', 'sales-002')['id'] ?? 0)])[0]['c']);
        // J: the same question from two strangers, on the department sales number and on the second sales number —
        // the same context, the same prompt. The number changes nothing the brain sees.
        $in('256772300011', 'Do you install in Gulu?', 'sj-sales');
        $in('256772300012', 'Do you install in Gulu?', 'sj-sales-2');
        $kj = $runWorker('Yes, we install across Uganda. MN-KJ');
        $c1 = $kj['brain']->contexts[0] ?? []; $c2 = $kj['brain']->contexts[1] ?? [];
        $f['kj_same_context'] = $c1 !== [] && json_encode($c1) === json_encode($c2);
        $f['kj_same_prompt']  = $c1 !== [] && $kj['brain']->promptPreview($c1) === $kj['brain']->promptPreview($c2);
        // 14: the channel moved to another instance between the message and the reply.
        $n0 = $nText(); $x0 = $nEvents('wa.escalation');
        $in('256772300004', 'Hello, moved number?', 'sj-sales-2');
        $reg()->setInstance('sales-002', 'sj-sales-2b', 'routing test', 'the SIM moved', $cm);
        $k4 = $runWorker('This must never be sent. MN-K4');
        $t = $textsSince($n0);
        $f['k4'] = ['customer_sends' => count(array_filter($t, function ($c) { return $c['number'] === '256772300004'; })),
                    'alert_sends'    => count(array_filter($t, function ($c) { return $c['number'] === '256700000999'; })),
                    'holding_line'   => count(array_filter($t, function ($c) { return strpos($c['text'], 'MN-HOLDING-LINE') !== false; })),
                    'brain_called'   => count($k4['brain']->contexts),
                    'state'          => $conv('256772300004', 'sales-002')['state'] ?? null,
                    'escalations'    => $nEvents('wa.escalation') - $x0,
                    'log'            => strpos($k4['log'], 'instance_mismatch') !== false];
        $reg()->setInstance('sales-002', 'sj-sales-2', 'routing test', 'moved back', $cm);
        // A channel switched off between the message and the reply.
        $n0 = $nText();
        $in('256772300005', 'Hello again', 'sj-sales-2');
        $reg()->setStatus('sales-002', 'disabled', 'routing test', 'switched off');
        $k5 = $runWorker('This must never be sent. MN-K5');
        $t = $textsSince($n0);
        $f['k5'] = ['customer_sends' => count(array_filter($t, function ($c) { return $c['number'] === '256772300005'; })),
                    'holding_line' => count(array_filter($t, function ($c) { return strpos($c['text'], 'MN-HOLDING-LINE') !== false; })),
                    'brain_called' => count($k5['brain']->contexts), 'log' => strpos($k5['log'], 'channel_disabled') !== false];
        $reg()->setStatus('sales-002', 'active', 'routing test', 'back on');
        // The assistant switched off between the message and the reply: kept for the team, nobody told, nothing sent.
        $n0 = $nText(); $x0 = $nEvents('wa.escalation');
        $in('256772300006', 'Hello assistant?', 'sj-sales-2');
        $reg()->setAiEnabled('sales-002', false, 'routing test', 'assistant off');
        $k6 = $runWorker('This must never be sent. MN-K6');
        $f['k6'] = ['sends' => count($textsSince($n0)), 'escalations' => $nEvents('wa.escalation') - $x0,
                    'brain_called' => count($k6['brain']->contexts), 'event' => $lastReplyEvent()['status']];
        $reg()->setAiEnabled('sales-002', true, 'routing test', 'assistant back on');
        // 12: each department number answers on its own instance.
        $n0 = $nText();
        $in('256772300007', 'Support please', 'sj-support');
        $in('256772300007', 'My invoice please', 'sj-account');
        $runWorker('Answered. MN-K7');
        $f['k7'] = array_map(function ($c) { return [$c['instance'], $c['number']]; }, $textsSince($n0));
    }

    // ── I. The Inbox ──────────────────────────────────────────────────────────────────────────────────
    if ($want('inbox')) {
        $flag(false);
        $mk = function (string $phone, string $instance, string $text) use ($in, $pdo): void { $in($phone, $text, $instance);
            $pdo->exec("UPDATE events SET status = 'done' WHERE event_type = 'ai.reply' AND status = 'pending'"); };
        $mk('256772400001', 'sj-sales', 'Sales question');
        $mk('256772400002', 'sj-support', 'Support question');
        $mk('256772400003', 'sj-account', 'Account question');
        $legacy = (new ConversationService($s->data, $s->store()->getPdo()))->ensureConversation('256772400004', 'accounts');
        $reply = function (int $convId, string $text) use ($s): array {
            $r = $s->api('admin', 'POST', 'wa_send_reply', ['conversation_id' => $convId, 'message' => $text]);
            return ['http' => $r[0], 'status' => $r[2]['status'] ?? null, 'message' => $r[2]['message'] ?? null,
                    'channel' => $r[2]['data']['channel'] ?? null];
        };
        $cases = ['sales' => ['256772400001', 'sales'], 'support' => ['256772400002', 'support'],
                  'account' => ['256772400003', 'account'], 'accounts' => ['256772400004', 'accounts']];
        foreach ($cases as $k => [$phone, $ch]) {
            $cid = (int)($conv($phone, $ch)['id'] ?? ($k === 'accounts' ? $legacy['id'] : 0));
            $n0 = $nText();
            $res = $reply($cid, "Inbox reply on {$k} MN-I");
            $t = $textsSince($n0);
            $stored = $q("SELECT wa_message_id, agent_name FROM wa_messages WHERE conversation_id = ? AND body = ?", [$cid, "Inbox reply on {$k} MN-I"]);
            $f['i_' . $k] = ['res' => $res, 'sent_on' => array_column($t, 'instance'), 'to' => array_column($t, 'number'),
                             'stored' => count($stored), 'stored_with_id' => count(array_filter($stored, function ($m) { return (string)$m['wa_message_id'] !== ''; })),
                             'state' => $conv($phone, $ch)['state'] ?? ($k === 'accounts' ? 'n/a' : null),
                             'support_conv_created' => $ch !== 'support' && $conv($phone, 'support') !== []];
        }
        // A registry channel's conversation with the registry OFF: refused, nothing sent from any other number.
        $r2 = (new ConversationService($s->data, $s->store()->getPdo()))->ensureConversation('256772400005', 'sales-002');
        $n0 = $nText();
        $f['i_registry_off'] = ['res' => $reply((int)$r2['id'], 'MN-I registry off'), 'sends' => count($textsSince($n0)),
            'stored' => (int)($q("SELECT COUNT(*) c FROM wa_messages WHERE conversation_id = ?", [(int)$r2['id']])[0]['c'])];
        $flag(true);
        $n0 = $nText();
        $f['i_registry_on'] = ['res' => $reply((int)$r2['id'], 'MN-I registry on'), 'sent_on' => array_column($textsSince($n0), 'instance')];
        // Media on the sales conversation: an image, a document, the generic media action.
        $cid = (int)$conv('256772400001', 'sales')['id'];
        $m0 = $nMedia();
        $img = $s->api('admin', 'POST', 'wa_send_image', ['conversation_id' => $cid, 'image_url' => 'http://127.0.0.1:9/mn.jpg', 'caption' => 'MN-IMG']);
        $doc = $s->api('admin', 'POST', 'wa_send_document', ['conversation_id' => $cid, 'document_url' => 'http://127.0.0.1:9/mn.pdf', 'filename' => 'mn.pdf', 'caption' => 'MN-DOC']);
        $med = $s->api('admin', 'POST', 'wa_send_media', ['conversation_id' => $cid, 'media_url' => 'http://127.0.0.1:9/mn2.pdf', 'media_type' => 'document', 'filename' => 'mn2.pdf', 'caption' => 'MN-MED']);
        $f['i_media'] = ['codes' => [$img[0], $doc[0], $med[0]],
                         'calls' => array_map(function ($c) { return [$c['instance'], $c['mediatype']]; }, $mediaSince($m0))];
        // A failed send: reported, nothing stored, nothing sent from another number.
        $s->http('GET', "{$s->evo}/__test/fail_next?n=1");
        $n0 = $nText();
        $fl = $reply($cid, 'MN-I fails');
        $f['i_fail'] = ['http' => $fl['http'], 'status' => $fl['status'], 'message' => $fl['message'], 'sends' => count($textsSince($n0)),
                        'stored' => (int)($q("SELECT COUNT(*) c FROM wa_messages WHERE body = 'MN-I fails'")[0]['c'])];
        // A department number switched off in the registry refuses its replies too.
        $reg()->setStatus('support', 'disabled', 'routing test', 'support number off');
        $n0 = $nText();
        $off = $reply((int)$conv('256772400002', 'support')['id'], 'MN-I support off');
        $f['i_support_off'] = ['http' => $off['http'], 'message' => $off['message'], 'sends' => count($textsSince($n0))];
        $reg()->setStatus('support', 'active', 'routing test', 'support number back');
        $f['i_leak'] = strpos(json_encode([$fl, $off, $f['i_registry_off']]), 'sj-') !== false;
    }

    // ── L. Leads ──────────────────────────────────────────────────────────────────────────────────────
    if ($want('lead')) {
        $lead = '<<LEAD{"requirement":"Starlink for a 12-room guest house","location":"Jinja","customer_type":"business",'
              . '"quote_requested":true,"ai_summary":"Guest house in Jinja asked for a quotation"}>>';
        $flag(false);
        $in('256772500001', 'I need Starlink for my guest house in Jinja, please quote', 'sj-sales');
        $runWorker('Thank you, I will have a quotation prepared. ' . $lead);
        $flag(true);
        $in('256772500002', 'I need Starlink for my guest house in Jinja, please quote', 'sj-sales-2');
        $runWorker('Thank you, I will have a quotation prepared. ' . $lead);
        $leads = $s->store()->load('leads.json') ?? [];
        $by = [];
        foreach ($leads as $l) $by[substr((string)($l['phone'] ?? ''), -6)] = $l;
        $l1 = $by['500001'] ?? []; $l2 = $by['500002'] ?? [];
        $f['l1'] = ['exists' => $l1 !== [], 'has_origin' => array_key_exists('channel_id', $l1), 'conv' => (int)($l1['conversation_id'] ?? 0) > 0];
        $f['l2'] = ['exists' => $l2 !== [], 'channel_id' => $l2['channel_id'] ?? null, 'role' => $l2['channel_role'] ?? null,
                    'source_number' => $l2['source_number'] ?? null, 'owner' => [$l2['channel_owner_type'] ?? null, (int)($l2['channel_owner_id'] ?? 0) === $seller],
                    'territory' => array_key_exists('territory_region_id', $l2) ? $l2['territory_region_id'] : 'absent',
                    'conv_matches' => (int)($l2['conversation_id'] ?? 0) === (int)($conv('256772500002', 'sales-002')['id'] ?? -1)];
        $ev = $q("SELECT id, payload FROM events WHERE event_type = 'crm.lead.sync' ORDER BY id");
        $f['l_sync_payload_keys'] = array_map(function ($e) { return array_keys(json_decode((string)$e['payload'], true) ?: []); }, $ev);
        $f['l_sync_channel'] = array_map(function ($e) { return (json_decode((string)$e['payload'], true) ?: [])['channel_id'] ?? null; }, $ev);
        // The sync, against a fake uCRM that creates clients (the Batch 0 path), as WorkerBase runs it.
        mn_hit($ucrmPort, '/__test/reset'); mn_hit($ucrmPort, '/__test/scenario?name=fresh_install');
        $cfgSync = array_merge($cfgNow, ['crm_base_url' => "http://127.0.0.1:{$ucrmPort}", 'crm_auth_token' => 'test-key']);
        ob_start();
        try { $sr = (new UcrmLeadWorker($s->store(), $cfgSync, 30, 10))->run(); } finally { $slog = (string)ob_get_clean(); }
        $after = [];
        foreach (($s->store()->load('leads.json') ?? []) as $l) $after[substr((string)($l['phone'] ?? ''), -6)] = $l;
        $f['l_sync'] = ['processed' => (int)($sr['processed'] ?? -1), 'failed' => (int)($sr['failed'] ?? -1),
                        'done' => (int)($q("SELECT COUNT(*) c FROM events WHERE event_type = 'crm.lead.sync' AND status = 'done'")[0]['c']),
                        'clients' => (int)(json_decode(mn_hit($ucrmPort, '/__test/clients'), true)['count'] ?? -1),
                        'linked' => [(int)($after['500001']['crm_client_id'] ?? 0) > 0, (int)($after['500002']['crm_client_id'] ?? 0) > 0],
                        'origin_kept' => ($after['500002']['channel_id'] ?? null) === 'sales-002'
                                      && ($after['500002']['source_number'] ?? null) === '+256700555666'];
    }

    // ── F. Follow-ups ─────────────────────────────────────────────────────────────────────────────────
    if ($want('followup')) {
        $f['f_zone'] = $zone;
        if ($zone !== '') {
            $fu = new FollowUpService($s->store()->getPdo());
            $approve = function (string $phone, string $channel, string $body) use ($s, $fu, $q): int {
                $c = (new ConversationService($s->data, $s->store()->getPdo()))->ensureConversation($phone, $channel);
                $s->store()->getPdo()->prepare("UPDATE wa_conversations SET last_customer_at = datetime('now', '-3 days') WHERE id = ?")->execute([(int)$c['id']]);
                $c = $q('SELECT * FROM wa_conversations WHERE id = ?', [(int)$c['id']])[0];
                $o = $fu->open($c);
                $d = $fu->draft((int)$o['id'], ['verdict' => 'SEND', 'message' => $body, 'reason' => 'test']);
                $fu->approve((int)$d['id'], 'routing test');
                return (int)$o['id'];
            };
            $closed = function (int $id) use ($q): ?string { return $q('SELECT close_reason FROM followups WHERE id = ?', [$id])[0]['close_reason'] ?? null; };
            $flag(false);
            $a = $approve('256772700001', 'sales', 'MN-F1 following up');
            $b = $approve('256772700002', 'sales-002', 'MN-F2 following up (registry off)');
            $n0 = $nText();
            $s->run('cron/followup_send.php');
            $t = $textsSince($n0);
            $f['f_off'] = ['sales' => array_column(array_filter($t, function ($c) { return strpos($c['text'], 'MN-F1') !== false; }), 'instance'),
                           'registry_sends' => count(array_filter($t, function ($c) { return strpos($c['text'], 'MN-F2') !== false; })),
                           'registry_closed' => $closed($b)];
            $flag(true);
            $n0 = $nText();
            $s->run('cron/followup_send.php');   // the registry channel's draft, still approved, now routes
            $t = $textsSince($n0);
            $f['f_on_registry'] = array_column(array_filter($t, function ($c) { return strpos($c['text'], 'MN-F2') !== false; }), 'instance');
            $reg()->setStatus('sales-002', 'retired', 'routing test', 'retired for the follow-up test');
            $c3 = $approve('256772700003', 'sales-002', 'MN-F3 never sent');
            $n0 = $nText();
            $s->run('cron/followup_send.php');
            $f['f_retired'] = ['sends' => count($textsSince($n0)), 'closed' => $closed($c3)];
            $reg()->setStatus('sales-002', 'active', 'routing test', 'back on');
        }
    }

    // ── N. Notifications (sendVia) are unchanged by the switch ─────────────────────────────────────────
    if ($want('notify')) {
        $send = function (bool $on) use ($flag, $s, &$cfgNow, $nText, $textsSince): array {
            $flag($on);
            $ns = new NotificationService($s->store(), $cfgNow);
            $n0 = $nText();
            $ns->sendVia('support', '256772800001', 'MN-N support', 'mn_test');
            $ns->sendVia('accounts', '256772800001', 'MN-N accounts', 'mn_test');
            return array_column($textsSince($n0), 'instance');
        };
        $f['n_off'] = $send(false);
        $f['n_on']  = $send(true);
    }

    // ── G. Golden: the three department numbers, OFF and ON ────────────────────────────────────────────
    if ($want('golden')) {
        $golden = function (bool $on, string $tag) use ($flag, $in, $runWorker, $nText, $textsSince, $lastReplyEvent, $conv, $norm, $s, $q): array {
            $flag($on);
            $g = [];
            foreach (['sales' => 'sj-sales', 'support' => 'sj-support', 'account' => 'sj-account'] as $ch => $inst) {
                $phone = '25677290' . ($on ? '1' : '0') . ($ch === 'sales' ? '01' : ($ch === 'support' ? '02' : '03'));
                $n0 = $nText();
                $w = $in($phone, "Golden question on {$ch}", $inst);
                $ev = $lastReplyEvent()['payload'];
                $run = $runWorker("Golden answer on {$ch}");
                $ctx = $run['brain']->contexts[0] ?? [];
                $t = $textsSince($n0);
                // The human pause: a colleague replies in the Inbox, then the customer writes again.
                $cid = (int)($conv($phone, $ch)['id'] ?? 0);
                $s->api('admin', 'POST', 'wa_send_reply', ['conversation_id' => $cid, 'message' => "Colleague on {$ch}"]);
                $n1 = $nText();
                $in($phone, "Second golden question on {$ch}", $inst);
                $run2 = $runWorker('This waits for the colleague');
                $g[$ch] = [
                    'webhook'   => $w,
                    'payload'   => ['channel' => $ev['channel'] ?? null, 'instance' => $ev['whatsapp_instance'] ?? null,
                                    'message' => $ev['message'] ?? null, 'keys' => array_keys($ev)],
                    'conv'      => $cid > 0,
                    'role'      => $ctx['channel'] ?? null,
                    'context'   => json_encode($norm($ctx)),
                    'prompt'    => md5($run['brain']->promptPreview($norm($ctx))),
                    'reply_on'  => array_map(function ($c) { return [$c['instance'], $c['text']]; }, $t),
                    // Counted from after the colleague's reply: the customer's second message gets nothing from the AI.
                    'paused'    => ['brain' => count($run2['brain']->contexts), 'sends' => count($textsSince($n1))],
                ];
            }
            return $g;
        };
        $f['g_off'] = $golden(false, 'off');
        $f['g_on']  = $golden(true, 'on');
    }

    $s->stop();
    return $f;
}

/** South Sudan: the flag set, the registry holding a second number — and nothing changes. */
function mn_south_sudan(string $root): array
{
    $f = [];
    $s = SjSandbox::start($root, ['tenant_profile' => 'south-sudan', 'ai_enabled' => '1', 'ai_provider' => 'openai',
        'openai_api_key' => 'test-key-never-called', ChannelRegistry::FLAG => '1'], 'mnss');
    $s->staff('admin', ['name' => 'Sandbox Admin', 'email' => 'admin@example.test', 'role' => 'admin', 'is_admin' => true]);
    (new ChannelRegistry($s->store()->getPdo(), $s->store()))->create(['channel_id' => 'sales-002', 'evo_instance' => 'sj-sales-2',
        'display_name' => 'Sales two', 'role' => 'sales', 'status' => 'active'], 'routing test', 'south sudan control',
        EvolutionApiService::configInstanceMap($s->cfg));
    $in = function (string $phone, string $instance) use ($s): array {
        $r = $s->evoInbound($phone, 'Hello', $instance, 'MNSS-' . bin2hex(random_bytes(4)));
        return [$r[0], $r[2]['outcome'] ?? null, $r[2]['channel'] ?? null];
    };
    $f['ss_webhook'] = ['sales' => $in('211912000001', 'sj-sales'), 'registry' => $in('211912000001', 'sj-sales-2'),
                        'account' => $in('211912000002', 'sj-account')];
    $texts = function () use ($s): array { return (array)($s->http('GET', "{$s->evo}/__test/state")[2]['text_calls'] ?? []); };
    $cid = function (string $phone, string $ch) use ($s): int { return (int)($s->q('SELECT id FROM wa_conversations WHERE phone = ? AND channel = ?', [$phone, $ch])[0]['id'] ?? 0); };
    $n0 = count($texts());
    $r1 = $s->api('admin', 'POST', 'wa_send_reply', ['conversation_id' => $cid('211912000001', 'sales'), 'message' => 'MNSS sales reply']);
    $r2 = $s->api('admin', 'POST', 'wa_send_reply', ['conversation_id' => $cid('211912000002', 'account'), 'message' => 'MNSS account reply']);
    $t = array_slice($texts(), $n0);
    $f['ss_inbox'] = ['codes' => [$r1[0], $r2[0]], 'channels' => [$r1[2]['data']['channel'] ?? null, $r2[2]['data']['channel'] ?? null],
                      'sent_on' => array_column($t, 'instance')];
    $evo = EvolutionApiService::forStore($s->cfg, $s->store()->getPdo(), $s->data);
    $f['ss_registry_off'] = [$evo->registryOn(), $evo->channelFor('sj-sales-2')];
    $s->stop();
    return $f;
}

if ($isDriver) {
    $port = (int)getenv('DN_T_UCRM_PORT');
    $out = in_array('south_sudan', $parts, true) ? mn_south_sudan($root) : mn_uganda($root, $parts, $port);
    echo "\n" . json_encode($out) . "\n";
    exit(0);
}

$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; } else { $fail++; echo "  FAIL {$m}" . ($d !== '' ? "\n       " . substr($d, 0, 900) : '') . "\n"; } }
function mn_boot(string $router, int $base, string $sig): array {
    foreach (range(0, 11) as $slot) {
        $cand = $base + ((getmypid() + $slot * 13) % 70);
        $p = proc_open(sprintf('exec php -S 127.0.0.1:%d %s', $cand, escapeshellarg($router)), [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 40; $i++) {
            $r = mn_hit($cand, '/__test/state');
            if ($r !== '') { if (strpos($r, $sig) !== false) return [$p, $cand]; break; }
            usleep(100000);
        }
        if (is_resource($p)) { proc_terminate($p); proc_close($p); }
    }
    return [null, 0];
}
[$ucrmSrv, $ucrmPort] = mn_boot($root . '/tests/fixtures/fake_ucrm_server.php', 9780, 'FAKE-UCRM-TEST');
register_shutdown_function(function () use (&$ucrmSrv) { if (is_resource($ucrmSrv)) { proc_terminate($ucrmSrv); proc_close($ucrmSrv); } });
if ($ucrmSrv === null) { echo "  FAIL could not start the fake uCRM server\n"; exit(1); }

$f  = mn_uganda($root, ['all'], $ucrmPort);
$ss = mn_south_sudan($root);
$j  = function ($v): string { return json_encode($v, JSON_UNESCAPED_UNICODE); };

echo "\nW. Inbound: instance → channel → conversation (tests 7–9)\n";
is_($f['w_off_sales'] === ['http' => 200, 'outcome' => 'accepted', 'channel' => 'sales', 'queued' => 1, 'skipped' => 0], 'OFF: the sales number is received as always', $j($f['w_off_sales']));
is_($f['w_off_payload'] === ['sales', 'sj-sales', 'Hello sales'], 'OFF: the queued turn carries the channel and the instance it came in on', $j($f['w_off_payload']));
is_(($f['w_off_registry']['outcome'] ?? '') === 'unknown_instance' && $f['w_off_registry_conv'], 'OFF: the registry is dark — a registry number is unknown, nothing stored', $j($f['w_off_registry']));
is_($f['w_on_sales'] === $f['w_off_sales'] && $f['w_on_payload_sales'] === $f['w_off_payload'], '7. ON: the sales number is received exactly as OFF', $j([$f['w_on_sales'], $f['w_on_payload_sales']]));
is_(($f['w_on_registry']['outcome'] ?? '') === 'accepted' && ($f['w_on_registry']['channel'] ?? '') === 'sales-002', '7. ON: the second sales number resolves to its own channel (any case)', $j($f['w_on_registry']));
is_($f['w_on_payload_registry'][0] === 'sales-002' && $f['w_on_payload_registry'][1] === 'SJ-SALES-2'
    && $f['w_on_payload_registry'][2] === ['channel', 'whatsapp_instance', 'customer_phone', 'message', 'push_name', 'wa_message_id', 'remote_jid', 'received_at', 'location'],
    'the queued turn names the channel id; the instance travels for the check only; the payload\'s shape is unchanged', $j($f['w_on_payload_registry']));
is_($f['w_convs'] === ['sales', 'sales-002', 'support'] && $f['w_conv_ids_distinct'], '8, 9. one customer on three numbers: three conversations, keyed phone + channel', $j($f['w_convs']));
is_(($f['w_disabled']['outcome'] ?? '') === 'channel_disabled' && ($f['w_unknown']['outcome'] ?? '') === 'unknown_instance', '5, 4. a switched-off number is refused as such, an unknown one as unknown', $j([$f['w_disabled'], $f['w_unknown']]));
is_($f['w_refused_wrote'] === [0, 0, true], 'neither writes a message, a conversation or an event', $j($f['w_refused_wrote']));
is_(($f['w_ai_off']['outcome'] ?? '') === 'accepted' && $f['w_ai_off_effect'] === [0, true, 1], 'the assistant off on a number: the message is kept for the team, nothing queued', $j([$f['w_ai_off'], $f['w_ai_off_effect']]));

echo "\nK. The AI worker (tests 10–14, part J)\n";
is_($f['k1_send'] === [['sj-sales', '256772300001']] && $f['k1_ctx_channel'] === 'sales', 'OFF: a sales turn is answered on the sales number, role sales', $j([$f['k1_send'], $f['k1_ctx_channel']]));
is_($f['k2_send'] === [['sj-sales-2', '256772300001']], '11, 12. the second sales number\'s turn is answered on ITS instance, never the department\'s', $j($f['k2_send']));
is_($f['k2_ctx_channel'] === 'sales', 'J. the brain is told the ROLE (sales), not the channel id', (string)$f['k2_ctx_channel']);
is_($f['k2_ctx_leaks'] === [false, false, false, false], 'J. the brain is never told the instance, our number, the channel id or the customer\'s number', $j($f['k2_ctx_leaks']));
is_($f['k2_history_isolated'], 'J. the history is this conversation\'s: nothing from the same customer\'s chat on another number');
is_($f['k1_conv_crm'] === 77 && $f['k2_identity'][0] === 77 && $f['k2_identity'][1] === 'identified', '10. the customer\'s identity is global: the same uCRM client on both numbers', $j([$f['k1_conv_crm'], $f['k2_identity']]));
is_($f['k2_reply_stored'] === 1, 'the reply is stored in the second number\'s conversation', (string)$f['k2_reply_stored']);
is_($f['kj_same_context'] && $f['kj_same_prompt'], 'J. the same question on the department number and the second number: the same context and the same prompt — one brain');
is_($f['k4']['customer_sends'] === 0 && $f['k4']['holding_line'] === 0 && $f['k4']['brain_called'] === 0, '14. the channel moved after the message arrived: nothing is sent to the customer, not even a holding line', $j($f['k4']));
is_($f['k4']['state'] === 'needs_human' && $f['k4']['escalations'] === 1 && $f['k4']['alert_sends'] === 1 && $f['k4']['log'], '14. a person is told: the chat is marked, the escalation is queued, the staff alert goes', $j($f['k4']));
is_($f['k5']['customer_sends'] === 0 && $f['k5']['holding_line'] === 0 && $f['k5']['brain_called'] === 0 && $f['k5']['log'], 'a channel switched off after the message arrived: nothing sent', $j($f['k5']));
is_($f['k6'] === ['sends' => 0, 'escalations' => 0, 'brain_called' => 0, 'event' => 'done'], 'the assistant switched off after the message arrived: nothing sent, nobody alerted, the event settled', $j($f['k6']));
is_($f['k7'] === [['sj-support', '256772300007'], ['sj-account', '256772300007']], '12. support and account answer on their own numbers', $j($f['k7']));

echo "\nI. The Inbox — the approved fix (tests 15–17)\n";
$ok = function ($c) { return ($c['res']['http'] ?? 0) === 200 && ($c['res']['status'] ?? '') === 'success'; };
is_($ok($f['i_sales']) && $f['i_sales']['sent_on'] === ['sj-sales'] && $f['i_sales']['to'] === ['256772400001'], '15. a reply in a SALES conversation leaves on the sales number — no longer on support', $j($f['i_sales']));
is_($f['i_sales']['res']['channel'] === 'sales' && $f['i_sales']['stored'] === 1 && $f['i_sales']['stored_with_id'] === 1 && $f['i_sales']['support_conv_created'] === false
    && $f['i_sales']['state'] === 'human_active', 'stored once in its own conversation, with the id that dedupes the echo; no stray support thread; the AI stands down', $j($f['i_sales']));
is_($ok($f['i_support']) && $f['i_support']['sent_on'] === ['sj-support'] && $f['i_support']['state'] === 'human_active', '16. support → support, exactly as before', $j($f['i_support']));
is_($f['i_support']['stored'] === 2 && $f['i_support']['stored_with_id'] === 1 && $f['i_accounts']['stored'] === 2,
    'support and accounts keep their path exactly — stored twice, the Inbox\'s row and sendVia\'s copy, as before (docs/65 §Z.6)',
    $j([$f['i_support'], $f['i_accounts']]));
is_($ok($f['i_account']) && $f['i_account']['sent_on'] === ['sj-account'] && $f['i_account']['support_conv_created'] === false, '17. account → account (it went out on support until now)', $j($f['i_account']));
is_($ok($f['i_accounts']) && $f['i_accounts']['sent_on'] === ['sj-account'], 'the notification thread (accounts) → the account number, exactly as before', $j($f['i_accounts']));
is_(($f['i_registry_off']['res']['http'] ?? 0) === 502 && $f['i_registry_off']['sends'] === 0 && $f['i_registry_off']['stored'] === 0
    && strpos((string)$f['i_registry_off']['res']['message'], 'Nothing was sent from any other number') !== false,
    'a registry channel\'s chat with the registry OFF: refused and said — never sent from support', $j($f['i_registry_off']));
is_($ok($f['i_registry_on']) && $f['i_registry_on']['sent_on'] === ['sj-sales-2'], 'with the registry ON it leaves on its own number', $j($f['i_registry_on']));
is_($f['i_media']['codes'] === [200, 200, 200] && $f['i_media']['calls'] === [['sj-sales', 'image'], ['sj-sales', 'document'], ['sj-sales', 'document']],
    'images and documents from the Inbox leave on the conversation\'s number too', $j($f['i_media']));
is_($f['i_fail']['http'] === 502 && $f['i_fail']['sends'] === 0 && $f['i_fail']['stored'] === 0 && strpos((string)$f['i_fail']['message'], 'Not sent on the sales number') === 0,
    'a failed send is reported as not sent, nothing stored, nothing sent from another number', $j($f['i_fail']));
is_($f['i_support_off']['http'] === 409 && $f['i_support_off']['sends'] === 0, 'a department number switched off in the registry refuses Inbox replies too', $j($f['i_support_off']));
is_($f['i_leak'] === false, 'no Inbox message names an Evolution instance');

echo "\nL. Leads (tests 18–20)\n";
is_($f['l1']['exists'] && $f['l1']['conv'] && $f['l1']['has_origin'] === false, '19. OFF: the Batch 0 path writes the lead, exactly as 5.18.87 (no channel fields)', $j($f['l1']));
is_($f['l2']['exists'] && $f['l2']['channel_id'] === 'sales-002' && $f['l2']['role'] === 'sales' && $f['l2']['source_number'] === '+256700555666'
    && $f['l2']['owner'] === ['staff', true] && $f['l2']['territory'] === null && $f['l2']['conv_matches'],
    '18. ON: the lead carries the channel, its role, our number on it, its owner and territory, and its conversation', $j($f['l2']));
is_($f['l_sync_payload_keys'] === [['lead_id', 'conversation_id'], ['lead_id', 'conversation_id', 'channel_id']] && $f['l_sync_channel'] === [null, 'sales-002'],
    'the sync event is unchanged OFF and names the channel ON', $j([$f['l_sync_payload_keys'], $f['l_sync_channel']]));
is_($f['l_sync']['done'] === 2 && $f['l_sync']['failed'] === 0 && $f['l_sync']['clients'] === 2 && $f['l_sync']['linked'] === [true, true],
    '20. both leads reach uCRM through the queue', $j($f['l_sync']));
is_($f['l_sync']['origin_kept'], 'the sync keeps the lead\'s channel fields');

echo "\nF. Follow-ups\n";
if (($f['f_zone'] ?? '') === '') {
    echo "  --   no sending window is open anywhere at this moment (a Sunday hour): the follow-up cases did not run\n";
} else {
    is_($f['f_off']['sales'] === ['sj-sales'], 'a follow-up on a sales chat leaves on the sales number', $j($f['f_off']));
    is_($f['f_off']['registry_sends'] === 0 && $f['f_off']['registry_closed'] === null, 'OFF: a registry channel\'s follow-up is not sent from any number (it waits)', $j($f['f_off']));
    is_($f['f_on_registry'] === ['sj-sales-2'], 'ON: it leaves on its own number', $j($f['f_on_registry']));
    is_($f['f_retired'] === ['sends' => 0, 'closed' => 'cancelled'], 'a retired number\'s follow-up is closed, never sent', $j($f['f_retired']));
}

echo "\nN. Notifications\n";
is_($f['n_off'] === ['sj-support', 'sj-account'] && $f['n_on'] === $f['n_off'], 'sendVia(support) and sendVia(accounts) leave on the same numbers, flag or no flag', $j([$f['n_off'], $f['n_on']]));

echo "\nG. Golden — the three department numbers, OFF and ON (test 22)\n";
foreach (['sales', 'support', 'account'] as $ch) {
    $a = $f['g_off'][$ch] ?? []; $b = $f['g_on'][$ch] ?? [];
    foreach (['webhook', 'payload', 'conv', 'role', 'context', 'prompt', 'reply_on', 'paused'] as $k) {
        is_(($a[$k] ?? null) === ($b[$k] ?? null) && array_key_exists($k, $a), "{$ch}: {$k} identical OFF and ON", $j([$a[$k] ?? null, $b[$k] ?? null]));
    }
    is_(($a['role'] ?? '') === $ch && ($a['reply_on'][0][0] ?? '') === 'sj-' . $ch && ($a['paused'] ?? null) === ['brain' => 0, 'sends' => 0],
        "{$ch}: its own role, its own number, and it stands down for a colleague", $j([$a['role'] ?? null, $a['reply_on'] ?? null, $a['paused'] ?? null]));
}

echo "\nS. South Sudan — unchanged, flag or no flag (test 23)\n";
is_($ss['ss_webhook']['sales'] === [200, 'accepted', 'sales'] && $ss['ss_webhook']['account'] === [200, 'accepted', 'account'], 'the three numbers are received as configured', $j($ss['ss_webhook']));
is_($ss['ss_webhook']['registry'][1] === 'unknown_instance', 'the registry is never consulted: a registry number is unknown', $j($ss['ss_webhook']));
is_($ss['ss_inbox']['codes'] === [200, 200] && $ss['ss_inbox']['channels'] === ['support', 'support'] && $ss['ss_inbox']['sent_on'] === ['sj-support', 'sj-support'],
    'the Inbox keeps the 5.18.85 rule: sales and account chats are answered from support', $j($ss['ss_inbox']));
is_($ss['ss_registry_off'] === [false, ''], 'forStore() never turns the registry on', $j($ss['ss_registry_off']));

// ── X. Weakened copies ─────────────────────────────────────────────────────────────────────────────────
echo "\nX. Weakened copies, each caught (test 13 and the rest)\n";
$drive = function (string $tree, string $parts) use ($self, $ucrmPort): array {
    $out = (string)shell_exec('DN_T_UCRM_PORT=' . $ucrmPort . ' php ' . escapeshellarg($self) . ' --driver ' . escapeshellarg($tree) . ' ' . escapeshellarg($parts) . ' 2>/dev/null');
    $lines = array_values(array_filter(explode("\n", trim($out)), 'strlen'));
    $g = json_decode((string)end($lines), true);
    return is_array($g) ? $g : ['_raw' => substr($out, -400)];
};
$mutants = [
    ['13. the AI answers on the ROLE\'s department number instead of the conversation\'s channel', 'workers/AiReplyWorker.php',
     '        $send = $this->evo->sendText($channel, $phone, $reply, ContactOptOut::CLASS_REPLY);',
     '        $send = $this->evo->sendText($role, $phone, $reply, ContactOptOut::CLASS_REPLY);', 'worker',
     function (array $g) { return ($g['k2_send'] ?? null) !== [['sj-sales-2', '256772300001']]; }],
    ['13. the Inbox sends a sales chat through support again', 'lib/InboxReplyRoute.php',
     "            return ['mode' => 'channel', 'sender' => '', 'channel' => \$ch, 'reason' => ''];",
     "            return ['mode' => 'sender', 'sender' => 'support', 'channel' => \$ch, 'reason' => ''];", 'inbox',
     function (array $g) { return ($g['i_sales']['sent_on'] ?? null) !== ['sj-sales']; }],
    ['the channel send falls back to the support number', 'lib/NotificationService.php',
     "        \$instance = \$evo->instanceFor(\$channel);\n        if (\$instance === '') {",
     "        \$instance = \$evo->instanceFor(\$channel);\n        if (\$instance === '') { \$channel = 'support'; \$instance = \$evo->instanceFor('support'); }\n        if (\$instance === '') {", 'inbox',
     function (array $g) { return ($g['i_registry_off']['sends'] ?? 0) !== 0; }],
    ['the worker skips the reply-route check', 'workers/AiReplyWorker.php',
     "        if (!\$this->evo->registryOn()) return \$channel;\n        \$route = \$this->evo->replyRoute(",
     "        return 'sales';\n        \$route = \$this->evo->replyRoute(", 'worker',
     function (array $g) { return ($g['k4']['customer_sends'] ?? 0) !== 0 || ($g['k5']['customer_sends'] ?? 0) !== 0; }],
    ['the webhook ignores the registry', 'evo_webhook.php',
     '$evo     = EvolutionApiService::forStore($config, $pdo, $dataDir);', '$evo     = new EvolutionApiService($config);', 'webhook',
     function (array $g) { return ($g['w_on_registry']['channel'] ?? '') !== 'sales-002'; }],
    ['the webhook queues the assistant on a number where it is off', 'evo_webhook.php',
     '    if (!$aiOnChannel) {', '    if (false) {', 'webhook',
     function (array $g) { return ($g['w_ai_off_effect'][0] ?? 0) !== 0; }],
    ['a lead forgets the number it came in on', 'workers/AiReplyWorker.php',
     '                        $svc->withOrigin($this->leadOrigin($channel, $convId));', '                        $svc->withOrigin(null);', 'lead',
     function (array $g) { return ($g['l2']['channel_id'] ?? null) !== 'sales-002'; }],
];
foreach ($mutants as [$name, $rel, $old, $new, $part, $caught]) {
    [$copy, $n] = sj_weakened_copy($root, $rel, $old, $new);
    if ($n !== 1) { is_(false, "mutant anchor is unique: {$name}", "count {$n} in {$rel}"); exec('rm -rf ' . escapeshellarg($copy)); continue; }
    $g = $drive($copy, $part);
    is_(!isset($g['_raw']) && $caught($g), "mutant is caught: {$name}", isset($g['_raw']) ? $g['_raw'] : $j(array_intersect_key($g, array_flip(['k2_send', 'i_sales', 'i_registry_off', 'k4', 'k5', 'w_on_registry', 'w_ai_off_effect', 'l2']))));
    exec('rm -rf ' . escapeshellarg($copy));
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
