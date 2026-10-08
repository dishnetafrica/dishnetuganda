<?php
declare(strict_types=1);
/**
 * test_sales_pilot.php — the salesperson pilot (docs/65 §AC, docs/66): a salesperson's own WhatsApp number on 5.18.89,
 * end to end, in production's shape, switched on in the runbook's order: the registry (multi_number_channels_enabled), then
 * the two switches the operator decided on 07 Oct evening (sales_own_leads_only, wa_followups_on_owned_numbers).
 *
 * Production's shape, as its 5.18.89 deploy read it (A9, R12): the sales department number on its own instance, support
 * and account SHARING one (support takes the inbound, both send on it), every number selling (ai_sales_on_all_numbers).
 * Two salesperson numbers are added the only way production may add one — the Salesperson numbers card on the WhatsApp AI
 * screen: add, pair, verify, register the webhook, then switch on. Every person, number, instance and lead here is
 * fictitious; nothing leaves 127.0.0.1. The plugin runs under php -S (SjSandbox) beside a fake Evolution and a fake uCRM;
 * the AI worker runs in this process with a brain that never leaves it (its canned answer is parsed by the REAL marker
 * parser).
 *
 * The instruction's tests A–Q (Phase 9), the pilot checks 1–15 (Phase 4) and the rollback (Phase 11):
 *   setup       the card flow; the routing it produces (one instance ↔ one channel ↔ one salesperson)
 *   replies     A, B, C, D (two numbers, two customers, one customer on both), M (one brain), a colleague's number, the
 *               salesperson answering from their own phone
 *   inbox       E   the Inbox, text and media
 *   handover    F   the hand-over
 *   followup    G   a follow-up
 *   media       H   the assistant's photo and document
 *   refusals    I disabled, J disconnected (a send refused by the instance, the retries spent, a reply that may have gone,
 *               the guard), K unknown (an instance, a channel id, a channel moved)
 *   visibility  L   leads, pages, the call log, the Inbox, the brain
 *   departments O, P sales, support and account: identical with the registry OFF, ON alone, and ON with the pilot
 *   rollback    pilot OFF, registry OFF, the two other switches OFF, and back ON
 *   south_sudan N
 *   Q           Domain B: the pilot runs on a copy of the plugin without it, and nothing it loads lives there
 *
 * Driver mode: php tests/test_sales_pilot.php --driver <plugin-root> <part,part,...>
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
require_once $root . '/lib/StaffJobsGate.php';
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
require_once $root . '/lib/LineOwner.php';
require_once $root . '/lib/OwnedLead.php';
require_once $root . '/lib/LeadVisibility.php';
require_once $root . '/lib/OwnedNumberHold.php';
require_once $root . '/lib/SalesNumbersAdmin.php';
require_once $root . '/lib/MediaLibrary.php';
require_once $root . '/lib/FollowUpPolicy.php';
require_once $root . '/lib/FollowUpService.php';
require_once $root . '/workers/WorkerBase.php';
require_once $root . '/workers/AiReplyWorker.php';

/** A brain that never leaves the process; its canned answer goes through the REAL marker parser. */
class SpBrain extends DishNetAiBrain
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

/** A zone where it is now between 09:00 and 18:00 on a day that is not Sunday, or '' when there is none (follow-ups send only then). */
function sp_open_zone(): string
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

/** [[instance, number], ...] of a list of recorded sends. */
function sp_pairs(array $calls): array
{
    return array_values(array_map(function ($c) { return [(string)$c['instance'], (string)$c['number']]; }, $calls));
}

/** The sends of a list that went to one number: [[instance, text], ...]. */
function sp_to(array $calls, string $number): array
{
    return array_values(array_map(function ($c) { return [(string)$c['instance'], (string)($c['text'] ?? '')]; },
        array_filter($calls, function ($c) use ($number) { return (string)$c['number'] === $number; })));
}

// ═════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
// Uganda, in production's shape
// ═════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
function sp_uganda(string $root, array $parts): array
{
    $all  = in_array('all', $parts, true);
    $want = function (string $p) use ($all, $parts): bool { return $all || in_array($p, $parts, true); };
    $f    = [];
    $zone = sp_open_zone();
    $base = ['tenant_profile' => 'uganda', 'ai_enabled' => '1', 'ai_currency' => 'UGX', 'ai_provider' => 'openai',
             'openai_api_key' => 'test-key-never-called', 'alert_whatsapp' => '256700000999',
             'ai_handover_message' => 'SP-HOLDING-LINE: a colleague will reply shortly.', 'ai_lead_capture' => '1',
             'followup_enabled' => '1', 'wa_human_cooldown_minutes' => 30, 'ai_media_enabled' => '0',
             'plugin_public_url' => 'https://plugin.example.test',
             // Production's numbers, as its 5.18.89 deploy read them (A9, R12): sales on its own instance; support and
             // account SHARE one, support taking the inbound. And production's switches: every number sells and records
             // leads (ai_sales_on_all_numbers), lead capture on, the uCRM lead sync off (not set).
             'evo_instance_sales' => 'sp-sales', 'evo_instance_support' => 'sp-supacc', 'evo_instance_account' => 'sp-supacc',
             'ai_sales_on_all_numbers' => '1',
             'timezone' => $zone !== '' ? $zone : 'UTC'];
    $s   = SjSandbox::start($root, $base, 'sp');
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
        return $q('SELECT * FROM wa_conversations WHERE phone = ? AND channel = ?', [$phone, $channel])[0] ?? [];
    };
    $evoState   = function () use ($s): array { return (array)($s->http('GET', "{$s->evo}/__test/state")[2] ?? []); };
    $nText      = function () use ($evoState): int { return count($evoState()['text_calls'] ?? []); };
    $textsSince = function (int $n) use ($evoState): array { return array_slice((array)($evoState()['text_calls'] ?? []), $n); };
    $nMedia     = function () use ($evoState): int { return count($evoState()['media_calls'] ?? []); };
    $mediaSince = function (int $n) use ($evoState): array { return array_slice((array)($evoState()['media_calls'] ?? []), $n); };
    $nFailed    = function () use ($evoState): int { return count($evoState()['failed_calls'] ?? []); };
    $failedSince = function (int $n) use ($evoState): array { return array_slice((array)($evoState()['failed_calls'] ?? []), $n); };
    $instances  = function (array $rows) use ($s): void { $s->http('POST', "{$s->evo}/__test/instances", $rows, ['Content-Type: application/json']); };
    $live = function (array $state = []) use ($instances): void {
        $rows = [];
        foreach (['sp-sales' => '256700000900', 'sp-supacc' => '256700000903', 'sp-alpha' => '256700000901', 'sp-bravo' => '256700000902'] as $n => $num) {
            $rows[] = ['name' => $n, 'connectionStatus' => $state[$n] ?? 'open', 'ownerJid' => $num . '@s.whatsapp.net', 'profileName' => 'SP'];
        }
        $instances($rows);
    };
    $down = function (string $instance, int $code = 500) use ($s): void { $s->http('GET', "{$s->evo}/__test/fail_instance?name={$instance}&code={$code}"); };
    $up   = function (string $instance) use ($s): void { $s->http('GET', "{$s->evo}/__test/fail_instance?name={$instance}&off=1"); };
    $in = function (string $phone, string $text, string $instance) use ($s): array {
        $r = $s->evoInbound($phone, $text, $instance, 'SP-' . bin2hex(random_bytes(5)));
        return [$r[0], $r[2]['outcome'] ?? null, $r[2]['channel'] ?? null, $r[2]['queued'] ?? null];
    };
    // The salesperson typing on their own phone: Evolution posts it back as fromMe, with an id nothing here sent.
    $handset = function (string $customer, string $text, string $instance) use ($s): array {
        $payload = ['event' => 'messages.upsert', 'instance' => $instance, 'data' => [
            'key' => ['id' => 'SP-HS-' . bin2hex(random_bytes(5)), 'fromMe' => true, 'remoteJid' => $customer . '@s.whatsapp.net'],
            'message' => ['conversation' => $text], 'messageTimestamp' => time(), 'pushName' => 'Sandbox Seller']];
        $r = $s->http('POST', "{$s->base}?page=evo_webhook", $payload, ['Content-Type: application/json', 'X-DishNet-Token: ' . $s->evoKey]);
        return [$r[0], $r[2]['outcome'] ?? null];
    };
    $nEvents = function (string $type) use ($q): int { return (int)($q('SELECT COUNT(*) c FROM events WHERE event_type = ?', [$type])[0]['c'] ?? 0); };
    $replyEvent = function (int $convId) use ($q): array {
        return $q("SELECT id, status, attempts, max_attempts FROM events WHERE event_type = 'ai.reply' AND entity_id = ? ORDER BY id DESC LIMIT 1", [$convId])[0] ?? [];
    };
    $runWorker = function (string $canned) use ($s, &$cfgNow): array {
        $w = new AiReplyWorker($s->store(), $cfgNow, 30, 10);
        $b = new SpBrain($cfgNow); $b->canned = $canned;
        $rp = new ReflectionProperty(AiReplyWorker::class, 'brain'); $rp->setAccessible(true); $rp->setValue($w, $b);
        ob_start();
        try { $res = $w->run(); } finally { $log = (string)ob_get_clean(); }
        return ['brain' => $b, 'log' => $log, 'run' => $res];
    };
    $settle = function () use ($pdo): void {   // the AI's turns a part does not test are not left for the next part's worker
        $pdo->exec("UPDATE events SET status = 'done' WHERE event_type = 'ai.reply' AND status IN ('pending', 'failed')");
    };
    $reg  = function () use ($s): ChannelRegistry { return new ChannelRegistry($s->store()->getPdo(), $s->store()); };
    $norm = function (array $ctx): array {
        foreach (['customer_phone', 'conversation_id', 'whatsapp_instance', 'push_name'] as $k) if (array_key_exists($k, $ctx)) $ctx[$k] = '·';
        return $ctx;
    };
    $leadByPhone = function (string $tail) use ($s): array {
        foreach (($s->store()->load('leads.json') ?? []) as $l) if (substr((string)($l['phone'] ?? ''), -6) === $tail) return (array)$l;
        return [];
    };
    $leadMarker = function (string $name, string $where): string {
        return '<<LEAD{"customer_name":"' . $name . '","requirement":"Starlink for a shop","location":"' . $where . '",'
             . '"customer_type":"business","quote_requested":true,"ai_summary":"A shop asked for a quotation"}>>';
    };
    $reply = function (string $who, int $convId, string $text) use ($s): array {
        $r = $s->api($who, 'POST', 'wa_send_reply', ['conversation_id' => $convId, 'message' => $text]);
        return ['http' => $r[0], 'channel' => $r[2]['data']['channel'] ?? null, 'message' => (string)($r[2]['message'] ?? ($r[2]['error'] ?? ''))];
    };
    $fu = new FollowUpService($pdo);
    $approve = function (string $phone, string $channel, string $body) use ($s, $fu, $q): int {
        $c = (new ConversationService($s->data, $s->store()->getPdo()))->ensureConversation($phone, $channel);
        $s->store()->getPdo()->prepare("UPDATE wa_conversations SET last_customer_at = datetime('now', '-3 days') WHERE id = ?")->execute([(int)$c['id']]);
        $c = $q('SELECT * FROM wa_conversations WHERE id = ?', [(int)$c['id']])[0];
        $o = $fu->open($c);
        $d = $fu->draft((int)$o['id'], ['verdict' => 'SEND', 'message' => $body, 'reason' => 'test']);
        $fu->approve((int)$d['id'], 'pilot test');
        return (int)$o['id'];
    };
    $closed = function (int $id) use ($q): ?string { return $q('SELECT close_reason FROM followups WHERE id = ?', [$id])[0]['close_reason'] ?? null; };
    $textsWith = function (array $t, string $needle): array {
        return array_values(array_filter($t, function ($c) use ($needle) { return strpos((string)$c['text'], $needle) !== false; }));
    };

    // ── The people ───────────────────────────────────────────────────────────────────────────────────────────────
    $s->staff('admin', ['name' => 'Sandbox Admin', 'email' => 'admin@example.test', 'role' => 'admin', 'is_admin' => true]);
    $A = $s->staff('alpha', ['name' => 'Alpha Seller', 'email' => 'alpha@example.test', 'role' => 'sales', 'phone' => '0700000201']);
    $B = $s->staff('bravo', ['name' => 'Bravo Seller', 'email' => 'bravo@example.test', 'role' => 'sales', 'phone' => '0700000202']);
    // 5.18.90: Mike has a phone on record — a sales colleague who owns no number, for the standing rule below.
    $M = $s->staff('mgr', ['name' => 'Mike Manager', 'email' => 'mgr@example.test', 'role' => 'sales', 'phone' => '0700000204', 'modules' => ['leads', 'all_leads', 'send_quote']]);
    $s->staff('desk', ['name' => 'Sierra Support', 'email' => 'desk@example.test', 'role' => 'support', 'phone' => '0700000203']);
    foreach (['admin' => 'admin@example.test', 'alpha' => 'alpha@example.test', 'bravo' => 'bravo@example.test', 'mgr' => 'mgr@example.test'] as $who => $em) {
        $s->login($who, $em, 'sj-password-1');
    }
    $f['ids'] = ['A' => $A, 'B' => $B, 'M' => $M];
    $f['zone'] = $zone;
    $live();

    // ── The department numbers, measured before anything changes (O, P) ────────────────────────────────────────────
    $golden = function (string $tag) use ($in, $runWorker, $nText, $textsSince, $conv, $norm, $s, $reply, $q, &$cfgNow, $pdo): array {
        $p = ['off' => '1', 'alone' => '2', 'pilot' => '3'][$tag];
        $mask = function (string $text, string $phone): string { return str_replace(['+' . $phone, $phone], 'PHONE', $text); };
        $g = [];
        foreach (['sales' => 'sp-sales', 'support' => 'sp-supacc'] as $ch => $inst) {
            $phone = '2567729' . $p . ($ch === 'sales' ? '001' : '002');
            $n0  = $nText();
            $w   = $in($phone, "Golden question on {$ch}", $inst);
            $ev  = $q("SELECT payload FROM events WHERE event_type = 'ai.reply' ORDER BY id DESC LIMIT 1")[0]['payload'] ?? '';
            $ev  = json_decode((string)$ev, true) ?: [];
            $run = $runWorker("Golden answer on {$ch}");
            $ctx = $run['brain']->contexts[0] ?? [];
            $t   = $textsSince($n0);
            $cid = (int)($conv($phone, $ch)['id'] ?? 0);
            $n1  = $nText();
            $ir  = $reply('admin', $cid, "Colleague on {$ch}");
            $ti  = $textsSince($n1);
            $n2  = $nText();
            $in($phone, "Second golden question on {$ch}", $inst);
            $run2 = $runWorker('This waits for the colleague');
            $g[$ch] = [
                'webhook'    => [$w[0], $w[1], $w[2]],
                'payload'    => [$ev['channel'] ?? null, $ev['whatsapp_instance'] ?? null, $ev['message'] ?? null, array_keys($ev)],
                'role'       => $ctx['channel'] ?? null,
                'line_owner' => $ctx['line_owner'] ?? null,
                'context'    => json_encode($norm($ctx)),
                'prompt'     => md5($run['brain']->promptPreview($norm($ctx))),
                'reply_on'   => array_map(function ($c) use ($phone) { return [$c['instance'], $c['number'] === $phone, $c['text']]; }, $t),
                'inbox'      => [$ir['http'], $ir['channel'], array_column($ti, 'instance')],
                'paused'     => ['brain' => count($run2['brain']->contexts), 'sends' => count($textsSince($n2))],
            ];
        }
        // account: it shares support's instance, so nothing arrives on it — its Inbox threads and its notifications.
        $cs = new ConversationService($s->data, $s->store()->getPdo());
        $acc = $cs->ensureConversation('2567729' . $p . '003', 'account');
        $old = $cs->ensureConversation('2567729' . $p . '004', 'accounts');
        $n0 = $nText();
        $ra = $reply('admin', (int)$acc['id'], 'SP-ACCOUNT reply');
        $rb = $reply('admin', (int)$old['id'], 'SP-ACCOUNTS reply');
        $ns = new NotificationService($s->store(), $cfgNow);
        $ns->sendVia('support', '2567729' . $p . '005', 'SP-N support', 'sp_test');
        $ns->sendVia('accounts', '2567729' . $p . '005', 'SP-N accounts', 'sp_test');
        $g['account'] = ['inbox_account' => [$ra['http'], $ra['channel']], 'inbox_accounts' => [$rb['http'], $rb['channel']],
                         'sent_on' => array_map(function ($c) { return [$c['instance'], $c['text']]; }, $textsSince($n0))];
        // A hand-over on the department sales number: the alert it always was.
        $phone = '2567729' . $p . '006';
        $in($phone, 'I want to talk to a person', 'sp-sales');
        $n0 = $nText();
        $runWorker('<<ESCALATE customer asked for a person>>');
        $g['handover'] = array_map(function ($c) use ($phone, $mask) {
            return [$c['number'] === $phone ? 'customer' : $c['number'], $c['instance'], $mask((string)$c['text'], $phone)];
        }, $textsSince($n0));
        $g['handover_state'] = $conv($phone, 'sales')['state'] ?? null;
        $pdo->exec("UPDATE events SET status = 'done' WHERE event_type = 'ai.reply' AND status IN ('pending', 'failed')");
        return $g;
    };

    // ── Setup: the card, with the registry OFF (docs/65 §AA.3 step 1) ───────────────────────────────────────────────
    $qs   = 'page=dashboard&tab=wa_ai_setup';
    $post = function (array $fields) use ($s, $qs): string {
        $r = $s->form('admin', $fields, $qs, $qs);
        return preg_match('#<div class="wa-msg (wa-good|wa-bad)">(.*?)</div>#s', $r[1], $m)
            ? ($m[1] === 'wa-good' ? 'ok: ' : 'no: ') . html_entity_decode(trim($m[2]), ENT_QUOTES) : 'none: ' . $r[0];
    };
    $chan = function (string $id) use ($q): array { return $q('SELECT * FROM wa_channels WHERE channel_id = ?', [$id])[0] ?? []; };
    $setup = [];
    $setup['add'] = [$post(['wa_action' => 'sn_add', 'instance' => 'sp-alpha', 'owner_staff_id' => $A, 'reason' => 'the pilot']),
                     $post(['wa_action' => 'sn_add', 'instance' => 'sp-bravo', 'owner_staff_id' => $B, 'reason' => 'the second number'])];
    $setup['added'] = array_map(function ($id) use ($chan) {
        $r = $chan($id);
        return [$r['evo_instance'] ?? null, (int)($r['owner_staff_id'] ?? 0), $r['status'] ?? null, $r['role'] ?? null, $r['owner_type'] ?? null,
                $r['handover_to'] ?? null, $r['portfolio_scope'] ?? null, (int)($r['ai_enabled'] ?? -1), $r['display_name'] ?? null];
    }, ['sales-001' => 'sales-001', 'sales-002' => 'sales-002']);
    $setup['add_department_instance'] = $post(['wa_action' => 'sn_add', 'instance' => 'sp-supacc', 'owner_staff_id' => $A]);
    foreach (['sales-001' => 'sp-alpha', 'sales-002' => 'sp-bravo'] as $id => $inst) {
        $r = $s->form('admin', ['wa_action' => 'sn_pair', 'channel_id' => $id], $qs, $qs);
        $setup['pair'][$id] = strpos($r[1], 'data:image/png;base64,' . base64_encode('FAKE-QR-' . $inst)) !== false;
        $setup['verify'][$id] = [$post(['wa_action' => 'sn_verify', 'channel_id' => $id]), $chan($id)['business_number'] ?? null,
                                 $chan($id)['verified_by'] ?? null];
        $setup['webhook'][$id] = [$post(['wa_action' => 'sn_webhook', 'channel_id' => $id]),
            strpos((string)($evoState()['webhooks'][$inst]['url'] ?? ''), 'https://plugin.example.test/public.php?page=evo_webhook&token=') === 0];
    }
    // 5.18.90 (docs/65 §AD, docs/66 step 1.5): the department numbers verified on the card, the registry still off — sales,
    // and support (whose number covers account: they share an instance).
    $setup['verify_departments'] = [$post(['wa_action' => 'sn_verify_department', 'channel_id' => 'sales']),
                                    $post(['wa_action' => 'sn_verify_department', 'channel_id' => 'support']),
                                    $post(['wa_action' => 'sn_verify_department', 'channel_id' => 'account'])];
    $setup['on_registry_off'] = [$post(['wa_action' => 'sn_status', 'channel_id' => 'sales-001', 'status' => 'active']), $chan('sales-001')['status'] ?? null];
    $setup['in_registry_off'] = $in('256771900000', 'Hello, is this Alpha?', 'sp-alpha');
    $setup['in_registry_off_wrote'] = $conv('256771900000', 'sales-001') === [];

    if ($want('departments')) $f['g_off'] = $golden('off');

    // Step 2: the registry ON with the three departments alone.
    $flag(true);
    $setup['in_disabled'] = $in('256771900000', 'Hello again', 'sp-alpha');
    if ($want('departments')) $f['g_alone'] = $golden('alone');

    // The two other switches (decided 07 Oct), then the two numbers ON.
    $setCfg([LeadVisibility::FLAG => '1', OwnedNumberHold::FLAG => '1']);
    $setup['on'] = [$post(['wa_action' => 'sn_status', 'channel_id' => 'sales-001', 'status' => 'active', 'reason' => 'the pilot goes live']),
                    $post(['wa_action' => 'sn_status', 'channel_id' => 'sales-002', 'status' => 'active', 'reason' => 'the second number']),
                    $chan('sales-001')['status'] ?? null, $chan('sales-002')['status'] ?? null];
    // The routing that results — checks 2 and 3: one instance, one channel, one salesperson.
    $evo = EvolutionApiService::forStore($cfgNow, $s->store()->getPdo(), $s->data);
    $rt  = $reg()->routing(EvolutionApiService::configInstanceMap($cfgNow));
    $setup['routing'] = [
        'registry'  => $evo->registryOn(),
        'inbound'   => ['sp-alpha' => $evo->channelFor('sp-alpha'), 'sp-bravo' => $evo->channelFor('sp-bravo'), 'sp-sales' => $evo->channelFor('sp-sales'),
                        'sp-supacc' => $evo->channelFor('sp-supacc'), 'sp-nobody' => $evo->channelFor('sp-nobody')],
        'outbound'  => ['sales-001' => $evo->instanceFor('sales-001'), 'sales-002' => $evo->instanceFor('sales-002'), 'sales' => $evo->instanceFor('sales'),
                        'support' => $evo->instanceFor('support'), 'account' => $evo->instanceFor('account')],
        'alpha_channels' => array_keys(array_filter((array)$rt['channel_to_instance'], function ($i) { return $i === 'sp-alpha'; })),
        'conflicts' => $rt['conflicts'],
        'owner'     => [(int)($rt['contexts']['sales-001']->ownerId() ?? 0) === $A, (int)($rt['contexts']['sales-002']->ownerId() ?? 0) === $B],
    ];
    $setup['trail'] = array_map(function ($r) { return [$r['channel_id'], $r['action'], $r['actor']]; },
        $q("SELECT channel_id, action, actor FROM wa_channel_log WHERE channel_id IN ('sales-001', 'sales-002') ORDER BY id"));
    $setup['trail_masks'] = strpos(json_encode($q("SELECT * FROM wa_channel_log")), '700000901') === false;
    $f['setup'] = $setup;

    // ── A, B, C, D, M and the two standing rules ─────────────────────────────────────────────────────────────────
    if ($want('replies')) {
        // A. A customer writes to Alpha's number; the reply leaves on Alpha's number.
        $f['a_in'] = $in('256771900001', 'Hello, I need internet for my shop', 'sp-alpha');
        $n0 = $nText();
        $k  = $runWorker('Thank you, I will have a quotation prepared. SP-A ' . $leadMarker('SP Alpha Customer', 'Mbale'));
        $c  = $k['brain']->contexts[0] ?? [];
        $ca = $conv('256771900001', 'sales-001');
        $l  = $leadByPhone('900001');
        $f['a'] = ['replies' => sp_pairs($textsSince($n0)), 'persona' => [$c['line_owner'] ?? null,
                   strpos($k['brain']->promptPreview($c), "You are Alpha's assistant at DishNet") !== false],
                   'role' => $c['channel'] ?? null, 'conv' => $ca !== [],
                   'stored' => (int)($q("SELECT COUNT(*) c FROM wa_messages WHERE conversation_id = ? AND direction = 'out' AND body LIKE '%SP-A%'", [(int)($ca['id'] ?? 0)])[0]['c'] ?? 0),
                   'lead' => [(int)($l['assigned_to'] ?? 0) === $A, $l['assigned_by'] ?? null, $l['channel_id'] ?? null, $l['source_number'] ?? null,
                              (int)($l['channel_owner_id'] ?? 0) === $A, (int)($l['conversation_id'] ?? 0) === (int)($ca['id'] ?? -1)]];
        // B. Bravo's number, Bravo's reply.
        $f['b_in'] = $in('256771900002', 'Hello, do you install in Gulu?', 'sp-bravo');
        $n0 = $nText();
        $k  = $runWorker('Yes, we install in Gulu. SP-B ' . $leadMarker('SP Bravo Customer', 'Gulu'));
        $c  = $k['brain']->contexts[0] ?? [];
        $l  = $leadByPhone('900002');
        $f['b'] = ['replies' => sp_pairs($textsSince($n0)), 'persona' => [$c['line_owner'] ?? null,
                   strpos($k['brain']->promptPreview($c), "You are Bravo's assistant at DishNet") !== false],
                   'lead' => [(int)($l['assigned_to'] ?? 0) === $B, $l['channel_id'] ?? null, $l['source_number'] ?? null]];
        // C. Two customers, two numbers, one worker run.
        $in('256771900003', 'Price of the Mini kit?', 'sp-alpha');
        $in('256771900004', 'Price of the Standard kit?', 'sp-bravo');
        $n0 = $nText();
        $k  = $runWorker('Here are our prices. SP-C');
        // Both arrived in the same second; the queue's order between them is not the test's subject — each is matched
        // to its own message.
        $byMessage = [];
        foreach ($k['brain']->contexts as $c) $byMessage[(string)($c['message'] ?? '')] = $c['line_owner'] ?? null;
        ksort($byMessage);
        $replies = sp_pairs($textsSince($n0)); sort($replies);
        $f['c'] = ['replies' => $replies, 'personas' => $byMessage];
        // D. One customer, both numbers: two conversations, two histories, one identity.
        $pdo->prepare("INSERT INTO client_search_index (id, name, phone, phone_norm, service) VALUES (88, 'SP Delta Customer', '+256771900005', '771900005', '')")->execute();
        $in('256771900005', 'SP-D-FIRST question on the first number', 'sp-alpha');
        $n0 = $nText();
        $k1 = $runWorker('SP-D1 answer on the first number');
        $in('256771900005', 'SP-D-SECOND question on the second number', 'sp-bravo');
        $k2 = $runWorker('SP-D2 answer on the second number');
        $ctx2 = $k2['brain']->contexts[0] ?? [];
        $j2   = json_encode($ctx2);
        $d1 = $conv('256771900005', 'sales-001'); $d2 = $conv('256771900005', 'sales-002');
        $f['d'] = ['convs' => array_column($q("SELECT channel FROM wa_conversations WHERE phone = '256771900005' ORDER BY channel"), 'channel'),
                   'distinct' => (int)($d1['id'] ?? 0) > 0 && (int)($d2['id'] ?? 0) > 0 && (int)$d1['id'] !== (int)$d2['id'],
                   'replies' => sp_pairs($textsSince($n0)),
                   'isolated' => $ctx2 !== [] && strpos($j2, 'SP-D-FIRST') === false && strpos($j2, 'SP-D1') === false,
                   'identity' => [(int)($d1['crm_client_id'] ?? 0), (int)($d2['crm_client_id'] ?? 0),
                                  ($k1['brain']->contexts[0] ?? [])['identity_state'] ?? null, $ctx2['identity_state'] ?? null,
                                  json_encode(($k1['brain']->contexts[0] ?? [])['customer'] ?? null) === json_encode($ctx2['customer'] ?? null)
                                  && !empty($ctx2['customer'])],
                   'personas' => [($k1['brain']->contexts[0] ?? [])['line_owner'] ?? null, $ctx2['line_owner'] ?? null]];
        // M. One brain: the same question on the department number and on Alpha's — the same context and prompt, but
        // for the owner's first name and the persona it sets.
        $in('256771900011', 'Do you install in Gulu?', 'sp-sales');
        $in('256771900012', 'Do you install in Gulu?', 'sp-alpha');
        $n0 = $nText();
        $km = $runWorker('Yes, we install across Uganda. SP-M');
        // The same second again: the department's context is the one without an owner, whichever ran first.
        $c1 = []; $c2 = [];
        foreach ($km['brain']->contexts as $c) { if (array_key_exists('line_owner', $c)) $c2 = $c; else $c1 = $c; }
        $c2b = $c2; unset($c2b['line_owner']);
        $p1 = $km['brain']->promptPreview($c1); $p2 = $km['brain']->promptPreview($c2); $p2b = $km['brain']->promptPreview($c2b);
        $j  = json_encode($c2);
        $replies = sp_pairs($textsSince($n0)); sort($replies);
        $f['m'] = ['same_context' => $c1 !== [] && json_encode($c1) === json_encode($c2b),
                   'owner' => [count($km['brain']->contexts), array_key_exists('line_owner', $c1), $c2['line_owner'] ?? null],
                   'same_prompt' => $p1 !== '' && $p1 === $p2b,
                   'persona' => [strpos($p2, "You are Alpha's assistant at DishNet") !== false, strpos($p1, "assistant at DishNet, replying") === false],
                   'replies' => $replies,
                   'leaks' => [strpos($j, 'sp-alpha') !== false, strpos($j, '700000901') !== false, strpos($j, 'sales-001') !== false,
                               strpos($j, '256771900012') !== false, strpos($j, 'Bravo') !== false, strpos($j, 'sp-bravo') !== false,
                               strpos($j, '700000902') !== false, strpos($j, 'SP Bravo Customer') !== false]];
        // A colleague's number (5.18.50, J8): an admin's, support's or accounts' phone is kept for the team and never
        // answered; a sales colleague's is not a "colleague" under that rule (StaffDirectory::STAFF_ROLES) and is answered
        // like a customer's. What a live test from a staff phone will see.
        $e0 = $nEvents('ai.reply');
        $f['j8'] = ['in' => $in('256700000203', 'Testing the line', 'sp-alpha'), 'queued' => $nEvents('ai.reply') - $e0,
                    'category' => $conv('256700000203', 'sales-001')['category'] ?? null];
        $e0 = $nEvents('ai.reply');
        $f['j8_sales'] = ['in' => $in('256700000202', 'Testing the line too', 'sp-alpha'), 'queued' => $nEvents('ai.reply') - $e0,
                          'category' => $conv('256700000202', 'sales-001')['category'] ?? null];
        $e0 = $nEvents('ai.reply');
        $f['j8_colleague'] = ['in' => $in('256700000204', 'Testing the line as a colleague', 'sp-alpha'), 'queued' => $nEvents('ai.reply') - $e0];
        $settle();
        // The salesperson answers on their own phone: recorded as the team's, and the AI stands down on that chat.
        $hs = $handset('256771900001', 'Alpha here, I will call you this afternoon. SP-HANDSET', 'sp-alpha');
        $state = $conv('256771900001', 'sales-001')['state'] ?? null;
        $in('256771900001', 'Thank you, what time?', 'sp-alpha');
        $n0 = $nText();
        $kh = $runWorker('This must wait for the salesperson. SP-HANDSET-AI');
        $f['handset'] = ['webhook' => $hs, 'state' => $state,
                         'stored' => $q("SELECT agent_name FROM wa_messages WHERE body LIKE '%SP-HANDSET%' AND direction = 'out'"),
                         'brain' => count($kh['brain']->contexts), 'sends' => count($textsSince($n0))];
        $settle();
    }

    // ── E. The Inbox ─────────────────────────────────────────────────────────────────────────────────────────────
    if ($want('inbox')) {
        $in('256771900101', 'Inbox test on the first number', 'sp-alpha');
        $in('256771900102', 'Inbox test on the second number', 'sp-bravo');
        $settle();
        $ca = (int)($conv('256771900101', 'sales-001')['id'] ?? 0); $cb = (int)($conv('256771900102', 'sales-002')['id'] ?? 0);
        $n0 = $nText();
        $ra = $reply('admin', $ca, 'SP-E reply on the first number');
        $ta = $textsSince($n0);
        $n1 = $nText();
        $rb = $reply('admin', $cb, 'SP-E reply on the second number');
        $tb = $textsSince($n1);
        $m0 = $nMedia();
        $img = $s->api('admin', 'POST', 'wa_send_image', ['conversation_id' => $ca, 'image_url' => 'http://127.0.0.1:9/sp.jpg', 'caption' => 'SP-E-IMG']);
        $doc = $s->api('admin', 'POST', 'wa_send_document', ['conversation_id' => $ca, 'document_url' => 'http://127.0.0.1:9/sp.pdf', 'filename' => 'sp.pdf', 'caption' => 'SP-E-DOC']);
        $f['e'] = ['alpha' => [$ra['http'], $ra['channel'], sp_pairs($ta)], 'bravo' => [$rb['http'], $rb['channel'], sp_pairs($tb)],
                   'media' => [[$img[0], $doc[0]], array_map(function ($c) { return [$c['instance'], $c['mediatype'], $c['number']]; }, $mediaSince($m0))],
                   'stored' => (int)($q("SELECT COUNT(*) c FROM wa_messages WHERE conversation_id = ? AND body = 'SP-E reply on the first number' AND COALESCE(wa_message_id, '') != ''", [$ca])[0]['c'] ?? 0),
                   'state' => $conv('256771900101', 'sales-001')['state'] ?? null];
        $n0 = $nText();
        $r  = $s->api('alpha', 'POST', 'wa_send_reply', ['conversation_id' => $ca, 'message' => 'SP-E from a salesperson']);
        $f['e_salesperson'] = [$r[0], count($textsSince($n0))];
    }

    // ── F. The hand-over ─────────────────────────────────────────────────────────────────────────────────────────
    if ($want('handover')) {
        $in('256771900201', 'Can I speak to a person?', 'sp-alpha');
        $x0 = $nEvents('wa.escalation');
        $n0 = $nText();
        $runWorker('<<ESCALATE customer asked for a person>>');
        $t = $textsSince($n0);
        $f['f'] = ['customer' => sp_to($t, '256771900201'), 'owner' => sp_to($t, '256700000201'), 'central' => sp_to($t, '256700000999'),
                   'others' => count(array_filter($t, function ($c) { return !in_array((string)$c['number'], ['256771900201', '256700000201', '256700000999'], true); })),
                   'state' => $conv('256771900201', 'sales-001')['state'] ?? null, 'escalations' => $nEvents('wa.escalation') - $x0];
        // F2. The assistant has nothing to say: the operator's holding line goes to the customer, on Alpha's number.
        $in('256771900202', 'Hello, is anyone there?', 'sp-alpha');
        $n0 = $nText();
        $runWorker('');
        $t = $textsSince($n0);
        $f['f2'] = ['customer' => sp_to($t, '256771900202'), 'owner' => array_column(sp_to($t, '256700000201'), 0),
                    'central' => array_column(sp_to($t, '256700000999'), 0), 'state' => $conv('256771900202', 'sales-001')['state'] ?? null];
    }

    // ── G. A follow-up ───────────────────────────────────────────────────────────────────────────────────────────
    if ($want('followup')) {
        if ($zone === '') {
            $f['g'] = 'no open zone';
        } else {
            // The scan opens one on a quiet chat on Alpha's number: the hold is lifted by the decided switch.
            $cs = new ConversationService($s->data, $pdo);
            $quiet = $cs->ensureConversation('256771900301', 'sales-001');
            $pdo->prepare("UPDATE wa_conversations SET last_customer_at = datetime('now', '-30 hours'), status = 'active' WHERE id = ?")->execute([(int)$quiet['id']]);
            $s->run('cron/followup_scan.php');
            $opened = $q('SELECT id FROM followups WHERE conversation_id = ?', [(int)$quiet['id']]);
            foreach ($opened as $row) $fu->close((int)$row['id'], 'cancelled', 'test: no model call');
            // Approved, then sent: on Alpha's number and no other.
            $g1 = $approve('256771900302', 'sales-001', 'SP-G1 following up on your quotation');
            $n0 = $nText();
            $s->run('cron/followup_send.php');
            $t = $textsSince($n0);
            $g = ['scan' => count($opened), 'sent' => sp_pairs($textsWith($t, 'SP-G1')),
                  'record' => array_map(function ($r) { return [$r['channel'], (int)$r['attempt']]; },
                                        $q('SELECT channel, attempt FROM followup_sends WHERE followup_id = ?', [$g1])),
                  'closed' => $closed($g1)];
            // Without the decided switch the same follow-up is held (closed, never sent) — the switch is what sends it.
            $setCfg([OwnedNumberHold::FLAG => null]);
            $g2 = $approve('256771900303', 'sales-001', 'SP-G2 held without the switch');
            $n0 = $nText();
            $s->run('cron/followup_send.php');
            $g['held'] = [count($textsWith($textsSince($n0), 'SP-G2')), $closed($g2)];
            $setCfg([OwnedNumberHold::FLAG => '1']);
            $f['g'] = $g;
        }
    }

    // ── H. The assistant's photo and document ────────────────────────────────────────────────────────────────────
    if ($want('media')) {
        $up_ = $s->sb . '/upload'; @mkdir($up_, 0700, true);
        $mk = function (string $file, string $bytes) use ($up_): array {
            file_put_contents($up_ . '/' . $file, $bytes);
            return ['name' => $file, 'tmp_name' => $up_ . '/' . $file, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes)];
        };
        $png = (string)base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $f['h_store'] = [MediaLibrary::store($s->data, $mk('kit.png', $png), 'image', 'sp-kit', 'SP-H the kit')['ok'] ?? false,
                         MediaLibrary::store($s->data, $mk('spec.pdf', "%PDF-1.7\n1 0 obj\n<<>>\nendobj\n"), 'document', 'sp-spec', 'SP-H the spec sheet')['ok'] ?? false];
        $in('256771900401', 'Can I see the kit and its spec sheet?', 'sp-alpha');
        $n0 = $nText(); $m0 = $nMedia();
        $runWorker('Here is the kit. SP-H <<PHOTO sp-kit>> <<DOC sp-spec>>');
        $f['h'] = ['text' => sp_pairs($textsSince($n0)),
                   'media' => array_map(function ($c) { return [$c['instance'], $c['mediatype'], $c['number']]; }, $mediaSince($m0))];
    }

    // ── I, J, K. Refusals ────────────────────────────────────────────────────────────────────────────────────────
    if ($want('refusals')) {
        // I. Bravo's number switched off on the card.
        $in('256771900502', 'A message just before the switch-off', 'sp-bravo');            // queued while on
        $cb = (int)($conv('256771900502', 'sales-002')['id'] ?? 0);
        $f['i_off'] = [$post(['wa_action' => 'sn_status', 'channel_id' => 'sales-002', 'status' => 'disabled', 'reason' => 'test']), $chan('sales-002')['status'] ?? null];
        $e0 = $nEvents('ai.reply'); $m0 = (int)($q('SELECT COUNT(*) c FROM wa_messages')[0]['c']);
        $f['i_in'] = $in('256771900501', 'Is anyone on this number?', 'sp-bravo');
        $f['i_in_wrote'] = [$nEvents('ai.reply') - $e0, (int)($q('SELECT COUNT(*) c FROM wa_messages')[0]['c']) - $m0, $conv('256771900501', 'sales-002') === []];
        $x0 = $nEvents('wa.escalation'); $n0 = $nText();
        $ki = $runWorker('This must never be sent. SP-I');
        $t  = $textsSince($n0);
        $f['i_queued'] = ['customer' => sp_to($t, '256771900502'), 'others' => array_map(function ($c) { return [$c['instance'], (string)$c['number']]; }, $t),
                          'brain' => count($ki['brain']->contexts), 'state' => $conv('256771900502', 'sales-002')['state'] ?? null,
                          'escalations' => $nEvents('wa.escalation') - $x0, 'log' => strpos($ki['log'], 'channel_disabled') !== false,
                          // 5.18.90: the reply route refuses it first; the automated-send policy behind it would too.
                          'by_route' => strpos($ki['log'], 'reply NOT sent on channel sales-002 — channel_disabled; handed to a person') !== false];
        $n0 = $nText();
        $ri = $reply('admin', $cb, 'SP-I Inbox reply on a switched-off number');
        $f['i_inbox'] = [$ri['http'], strpos($ri['message'], 'Nothing was sent from any other number') !== false, count($textsSince($n0)),
                         (int)($q("SELECT COUNT(*) c FROM wa_messages WHERE body = 'SP-I Inbox reply on a switched-off number'")[0]['c']),
                         strpos($ri['message'], 'sp-') === false];
        $f['i_back'] = [$post(['wa_action' => 'sn_status', 'channel_id' => 'sales-002', 'status' => 'active', 'reason' => 'test over']), $chan('sales-002')['status'] ?? null];
        $settle();

        // J. Alpha's instance cannot send (a phone switched off, a number logged out).
        $down('sp-alpha');
        $in('256771900601', 'Hello, anyone there?', 'sp-alpha');
        $cj = (int)($conv('256771900601', 'sales-001')['id'] ?? 0);
        $n0 = $nText(); $fl0 = $nFailed(); $x0 = $nEvents('wa.escalation');
        $runWorker('SP-J this reply cannot leave');
        $ev = $replyEvent($cj);
        $f['j_first'] = ['sends' => count($textsSince($n0)), 'tried' => array_map(function ($c) { return [$c['instance'], $c['kind'], $c['number']]; }, $failedSince($fl0)),
                         'event' => [$ev['status'] ?? null, (int)($ev['attempts'] ?? -1)], 'state' => $conv('256771900601', 'sales-001')['state'] ?? null,
                         'escalations' => $nEvents('wa.escalation') - $x0];
        // The retries spent: the last attempt fails too, and a person is told.
        $pdo->prepare("UPDATE events SET attempts = max_attempts - 1, next_retry_at = datetime('now', '-1 minute') WHERE id = ?")->execute([(int)($ev['id'] ?? 0)]);
        $n0 = $nText(); $fl0 = $nFailed(); $x0 = $nEvents('wa.escalation');
        $runWorker('SP-J this reply cannot leave');
        $t  = $textsSince($n0);
        $ev = $replyEvent($cj);
        $f['j_dead'] = ['event' => $ev['status'] ?? null, 'customer' => sp_to($t, '256771900601'), 'owner' => sp_to($t, '256700000201'),
                        'central' => sp_to($t, '256700000999'), 'tried' => array_map(function ($c) { return [$c['instance'], $c['kind'], $c['number']]; }, $failedSince($fl0)),
                        'state' => $conv('256771900601', 'sales-001')['state'] ?? null, 'escalations' => $nEvents('wa.escalation') - $x0];
        // The Inbox while the instance is down: refused, said, nothing sent from another number.
        $n0 = $nText();
        $rj = $reply('admin', $cj, 'SP-J Inbox reply while the number is down');
        $f['j_inbox'] = [$rj['http'], strpos($rj['message'], 'Nothing was sent from any other number') !== false, count($textsSince($n0)), strpos($rj['message'], 'sp-') === false];
        // A gateway timeout instead: the reply may have gone, so it is never sent again — a person decides.
        $down('sp-alpha', 504);
        $in('256771900602', 'Hello again, anyone?', 'sp-alpha');
        $n0 = $nText(); $fl0 = $nFailed(); $x0 = $nEvents('wa.escalation');
        $runWorker('SP-J2 this reply may have gone');
        $t = $textsSince($n0);
        $ev = $replyEvent((int)($conv('256771900602', 'sales-001')['id'] ?? 0));
        $f['j_maybe'] = ['customer' => sp_to($t, '256771900602'), 'tried' => count($failedSince($fl0)), 'event' => $ev['status'] ?? null,
                         'state' => $conv('256771900602', 'sales-001')['state'] ?? null, 'escalations' => $nEvents('wa.escalation') - $x0,
                         'holding' => count($textsWith($t, 'SP-HOLDING-LINE'))];
        // The guard sees the number disconnected and says so on the alert number.
        $live(['sp-alpha' => 'close']);
        $n0 = $nText();
        $s->run('cron/wa_webhook_guard.php');
        $said = $textsWith($textsSince($n0), 'DISCONNECTED');
        $f['j_guard'] = array_map(function ($c) { return [(string)$c['number'], strpos((string)$c['text'], 'sales-001') !== false]; }, $said);
        // Reconnected: Alpha's number answers again, on Alpha's number.
        $live(); $up('sp-alpha');
        $in('256771900603', 'Back online?', 'sp-alpha');
        $n0 = $nText();
        $runWorker('Yes. SP-J3');
        $f['j_back'] = sp_pairs($textsSince($n0));
        $settle();

        // K. Unknown: an instance nothing knows; a channel id the registry does not know; a channel moved meanwhile.
        $e0 = $nEvents('ai.reply'); $m0 = (int)($q('SELECT COUNT(*) c FROM wa_messages')[0]['c']);
        $f['k_in'] = $in('256771900701', 'Hello?', 'sp-nobody');
        $f['k_in_wrote'] = [$nEvents('ai.reply') - $e0, (int)($q('SELECT COUNT(*) c FROM wa_messages')[0]['c']) - $m0];
        $ck = (new ConversationService($s->data, $pdo))->ensureConversation('256771900702', 'sales-099');
        (new EventBus($pdo))->emit('ai.reply', 'conversation', (int)$ck['id'], ['channel' => 'sales-099', 'whatsapp_instance' => 'sp-alpha',
            'customer_phone' => '256771900702', 'message' => 'Hello from a number nobody routes', 'push_name' => 'Sandbox Person',
            'wa_message_id' => 'SP-K-1', 'remote_jid' => '256771900702@s.whatsapp.net', 'received_at' => gmdate('c')], 3, 'pilot test');
        $x0 = $nEvents('wa.escalation'); $n0 = $nText();
        $kk = $runWorker('This must never be sent. SP-K');
        $t = $textsSince($n0);
        $f['k_channel'] = ['customer' => sp_to($t, '256771900702'), 'central' => array_column(sp_to($t, '256700000999'), 0),
                           'brain' => count($kk['brain']->contexts), 'state' => $conv('256771900702', 'sales-099')['state'] ?? null,
                           'escalations' => $nEvents('wa.escalation') - $x0, 'log' => strpos($kk['log'], 'unknown_channel') !== false,
                           'by_route' => strpos($kk['log'], 'reply NOT sent on channel sales-099 — unknown_channel; handed to a person') !== false];
        $cm = EvolutionApiService::configInstanceMap($cfgNow);
        $in('256771900703', 'Hello, moved?', 'sp-alpha');
        $reg()->setInstance('sales-001', 'sp-alpha-new', 'pilot test', 'the SIM moved to a new instance', $cm);
        $n0 = $nText();
        $km = $runWorker('This must never be sent. SP-K2');
        $t = $textsSince($n0);
        $f['k_moved'] = ['customer' => sp_to($t, '256771900703'), 'brain' => count($km['brain']->contexts), 'log' => strpos($km['log'], 'instance_mismatch') !== false];
        $reg()->setInstance('sales-001', 'sp-alpha', 'pilot test', 'moved back', $cm);
        $settle();
    }

    // ── L. No salesperson sees another's customers ───────────────────────────────────────────────────────────────
    if ($want('visibility')) {
        $in('256771900801', 'I need a quotation for my office', 'sp-alpha');
        $ka = $runWorker('Thank you. SP-L1 ' . $leadMarker('SP Lima Alpha', 'Jinja'));
        $in('256771900802', 'I need a quotation for my school', 'sp-bravo');
        $runWorker('Thank you. SP-L2 ' . $leadMarker('SP Lima Bravo', 'Lira'));
        $names = ['SP Lima Alpha', 'SP Lima Bravo'];
        $sees = function (string $who) use ($s, $names): array {
            $html = $s->page($who, 'page=dashboard&tab=leads&f=all');
            $out = [];
            foreach ($names as $n) $out[$n] = strpos($html, $n) !== false;
            return $out;
        };
        $la = $leadByPhone('900801'); $lb = $leadByPhone('900802');
        $f['l_leads'] = [[(int)($la['assigned_to'] ?? 0) === $A, $la['channel_id'] ?? null], [(int)($lb['assigned_to'] ?? 0) === $B, $lb['channel_id'] ?? null]];
        $f['l_pages'] = ['alpha' => $sees('alpha'), 'bravo' => $sees('bravo'), 'mgr' => $sees('mgr'), 'admin' => $sees('admin')];
        $f['l_open_foreign'] = strpos($s->page('alpha', 'page=dashboard&tab=leads&edit_lead=' . (int)($lb['id'] ?? 0)), 'value="SP Lima Bravo"') !== false;
        $f['l_call'] = [$s->api('alpha', 'POST', 'log_call', ['lead_id' => (int)($lb['id'] ?? 0), 'outcome' => 'answered', 'note' => 'SP-L-CALL'])[0],
                        $s->api('alpha', 'POST', 'log_call', ['lead_id' => (int)($la['id'] ?? 0), 'outcome' => 'answered', 'note' => 'SP-L-CALL'])[0]];
        // The Inbox is the administrators' alone by default: a salesperson can neither read it nor send from it.
        $f['l_inbox'] = [$s->api('alpha', 'GET', 'wa_conversations')[0],
                         $s->api('alpha', 'GET', 'wa_thread_messages', null, '&id=' . (int)($conv('256771900802', 'sales-002')['id'] ?? 0))[0]];
        // Granted to the sales role, it would show every number's chats: the Inbox is not filtered by number. The pilot
        // therefore needs wa_inbox_roles to grant no sales role (the runbook's read-only check).
        $setCfg(['wa_inbox_roles' => 'sales']);
        $r = $s->api('alpha', 'GET', 'wa_conversations', null, '&limit=200');
        $chs = array_values(array_unique(array_column((array)($r[2]['data']['conversations'] ?? []), 'channel')));
        sort($chs);
        $f['l_inbox_granted'] = [$r[0], in_array('sales-002', $chs, true)];
        $setCfg(['wa_inbox_roles' => null]);
        // The brain on Alpha's number is told nothing of Bravo's.
        $ctx = $ka['brain']->contexts[0] ?? [];
        $j = json_encode($ctx);
        $f['l_brain'] = [$ctx !== [], strpos($j, 'Bravo') !== false, strpos($j, '256771900802') !== false, strpos($j, 'SP Lima Bravo') !== false,
                         strpos($j, 'sp-bravo') !== false];
        $settle();
    }

    if ($want('departments')) $f['g_pilot'] = $golden('pilot');

    // ── The rollback (Phase 11) ──────────────────────────────────────────────────────────────────────────────────
    if ($want('rollback')) {
        // What there is before: a conversation, a lead, an approved follow-up, a queued message — all on Alpha's number.
        $in('256771900901', 'I want a quotation please', 'sp-alpha');
        $runWorker('Thank you. SP-R0 ' . $leadMarker('SP Romeo Alpha', 'Masaka'));
        $in('256771900910', 'I want a quotation too', 'sp-bravo');
        $runWorker('Thank you. SP-R0B ' . $leadMarker('SP Romeo Bravo', 'Hoima'));
        $cr = (int)($conv('256771900901', 'sales-001')['id'] ?? 0);
        $count = function () use ($q, $leadByPhone): array {
            $l = $leadByPhone('900901');
            return ['convs' => (int)($q("SELECT COUNT(*) c FROM wa_conversations WHERE channel = 'sales-001'")[0]['c']),
                    'messages' => (int)($q("SELECT COUNT(*) c FROM wa_messages m JOIN wa_conversations c ON c.id = m.conversation_id WHERE c.channel = 'sales-001'")[0]['c']),
                    'lead' => [(int)($l['assigned_to'] ?? 0), $l['channel_id'] ?? null, $l['source_number'] ?? null],
                    'channel' => (int)($q("SELECT COUNT(*) c FROM wa_channels WHERE channel_id = 'sales-001'")[0]['c']),
                    'trail' => (int)($q("SELECT COUNT(*) c FROM wa_channel_log WHERE channel_id = 'sales-001'")[0]['c']),
                    'trail_first' => md5(json_encode($q("SELECT * FROM wa_channel_log WHERE channel_id = 'sales-001' ORDER BY id")))];
        };
        $before = $count();
        $f['r_before'] = $before;
        $fr = $zone !== '' ? $approve('256771900902', 'sales-001', 'SP-R follow-up approved before the rollback') : 0;
        $in('256771900903', 'A message queued just before the rollback', 'sp-alpha');
        // R1. The pilot OFF, on the card.
        $f['r1_off'] = [$post(['wa_action' => 'sn_status', 'channel_id' => 'sales-001', 'status' => 'disabled', 'reason' => 'pilot rollback']), $chan('sales-001')['status'] ?? null];
        $after1 = $count();
        $e0 = $nEvents('ai.reply');
        $f['r1_in'] = $in('256771900901', 'Hello after the switch-off', 'sp-alpha');
        $f['r1_in_queued'] = $nEvents('ai.reply') - $e0;
        $n0 = $nText(); $x0 = $nEvents('wa.escalation');
        $kr = $runWorker('This must never be sent. SP-R1');
        $t = $textsSince($n0);
        $f['r1_queued'] = ['customer' => sp_to($t, '256771900903'), 'brain' => count($kr['brain']->contexts), 'escalations' => $nEvents('wa.escalation') - $x0];
        $n0 = $nText();
        $rr = $reply('admin', $cr, 'SP-R1 Inbox reply after the switch-off');
        $f['r1_inbox'] = [$rr['http'], strpos($rr['message'], 'Nothing was sent from any other number') !== false, count($textsSince($n0))];
        if ($fr > 0) {
            $n0 = $nText();
            $s->run('cron/followup_send.php');
            $f['r1_followup'] = [count($textsWith($textsSince($n0), 'SP-R follow-up')), $closed($fr)];
        }
        // The departments are not touched by it.
        $n0 = $nText();
        $in('256771900904', 'Sales question during the rollback', 'sp-sales');
        $in('256771900905', 'Support question during the rollback', 'sp-supacc');
        $runWorker('Answered. SP-R1-DEPT');
        $f['r1_departments'] = sp_pairs($textsSince($n0)); sort($f['r1_departments']);   // one second, two messages: order aside
        $f['r1_kept'] = ['before' => $before, 'after' => $after1];
        $settle();
        // R2. The registry OFF (set_config --clear).
        $flag(false);
        $f['r2_in'] = $in('256771900901', 'Hello after the registry is off', 'sp-alpha');
        $n0 = $nText();
        $rr = $reply('admin', $cr, 'SP-R2 Inbox reply with the registry off');
        $f['r2_inbox'] = [$rr['http'], strpos($rr['message'], 'Nothing was sent from any other number') !== false, count($textsSince($n0))];
        if ($zone !== '') {
            $fr2 = $approve('256771900906', 'sales-001', 'SP-R2 follow-up with the registry off');
            $n0 = $nText();
            $s->run('cron/followup_send.php');
            $f['r2_followup'] = count($textsWith($textsSince($n0), 'SP-R2 follow-up'));
        }
        $n0 = $nText();
        $in('256771900907', 'Sales question with the registry off', 'sp-sales');
        $in('256771900908', 'Support question with the registry off', 'sp-supacc');
        $runWorker('Answered. SP-R2-DEPT');
        $f['r2_departments'] = sp_pairs($textsSince($n0)); sort($f['r2_departments']);
        $f['r2_kept'] = $count();
        // R3. The two other switches OFF: the team's lead pages as before the pilot.
        $sawBefore = strpos($s->page('alpha', 'page=dashboard&tab=leads&f=all'), 'SP Romeo Bravo') !== false;
        $setCfg([LeadVisibility::FLAG => null, OwnedNumberHold::FLAG => null]);
        $f['r3_alpha_sees'] = [$sawBefore, strpos($s->page('alpha', 'page=dashboard&tab=leads&f=all'), 'SP Romeo Bravo') !== false];
        // R4. And back ON: nothing was damaged.
        $flag(true);
        $setCfg([LeadVisibility::FLAG => '1', OwnedNumberHold::FLAG => '1']);
        $f['r4_on'] = [$post(['wa_action' => 'sn_status', 'channel_id' => 'sales-001', 'status' => 'active', 'reason' => 'pilot back on']), $chan('sales-001')['status'] ?? null];
        $settle();
        $in('256771900909', 'Hello again after the pilot is back', 'sp-alpha');
        $n0 = $nText();
        $runWorker('Welcome back. SP-R4');
        $f['r4_reply'] = sp_pairs($textsSince($n0));
    }

    // Q (in part): everything above ran on a copy of the plugin without Domain B, and nothing this process loaded lives there.
    $f['q_loaded'] = array_values(array_filter(get_included_files(), function ($p) { return strpos($p, 'dishnet-mikrotik-control-plane') !== false; }));
    $f['q_sandbox'] = is_dir($s->plug . '/dishnet-mikrotik-control-plane');
    $s->stop();
    return $f;
}

// ═════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
// N. South Sudan: every switch set, a salesperson row in the registry — and nothing changes
// ═════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
function sp_south_sudan(string $root): array
{
    $f = [];
    $s = SjSandbox::start($root, ['tenant_profile' => 'south-sudan', 'timezone' => 'UTC', 'ai_enabled' => '1', 'ai_provider' => 'openai',
                                  'openai_api_key' => 'test-key-never-called', ChannelRegistry::FLAG => '1', LeadVisibility::FLAG => '1',
                                  OwnedNumberHold::FLAG => '1', 'followup_enabled' => '1',
                                  'evo_instance_sales' => 'sp-sales', 'evo_instance_support' => 'sp-supacc', 'evo_instance_account' => 'sp-supacc'], 'spss');
    $s->staff('admin', ['name' => 'Sandbox Admin', 'email' => 'admin@example.test', 'role' => 'admin', 'is_admin' => true]);
    $A = $s->staff('alpha', ['name' => 'Alpha Seller', 'email' => 'alpha@example.test', 'role' => 'sales', 'phone' => '0920000201']);
    $B = $s->staff('bravo', ['name' => 'Bravo Seller', 'email' => 'bravo@example.test', 'role' => 'sales']);
    $s->login('admin', 'admin@example.test', 'sj-password-1');
    $s->login('alpha', 'alpha@example.test', 'sj-password-1');
    $now = date('Y-m-d H:i:s');
    $s->store()->save('leads.json', [
        ['id' => 1, 'customer_name' => 'SP Alpha Lead', 'phone' => '+211920000301', 'status' => 'open', 'assigned_to' => $A, 'created_at' => $now, 'daily_assign_to' => $A, 'daily_assign_date' => date('Y-m-d')],
        ['id' => 2, 'customer_name' => 'SP Bravo Lead', 'phone' => '+211920000302', 'status' => 'open', 'assigned_to' => $B, 'created_at' => $now, 'daily_assign_to' => $B, 'daily_assign_date' => date('Y-m-d')],
    ]);
    $pdo = $s->store()->getPdo();
    (new ChannelRegistry($pdo, $s->store()))->create(['channel_id' => 'sales-001', 'evo_instance' => 'sp-alpha', 'display_name' => 'S', 'role' => 'sales',
        'owner_type' => 'staff', 'owner_staff_id' => $A, 'status' => 'active'], 'pilot test', 'a row the registry never reads here',
        EvolutionApiService::configInstanceMap($s->cfg));
    $in = function (string $phone, string $instance) use ($s): array {
        $r = $s->evoInbound($phone, 'Hello', $instance, 'SPSS-' . bin2hex(random_bytes(4)));
        return [$r[0], $r[2]['outcome'] ?? null, $r[2]['channel'] ?? null];
    };
    $f['n_webhook'] = ['sales' => $in('211912000001', 'sp-sales'), 'salesperson' => $in('211912000002', 'sp-alpha'), 'support' => $in('211912000003', 'sp-supacc')];
    $evo = EvolutionApiService::forStore($s->cfg, $pdo, $s->data);
    $f['n_registry'] = [$evo->registryOn(), $evo->channelFor('sp-alpha'), $evo->instanceFor('sales-001')];
    $f['n_card'] = strpos($s->page('admin', 'page=dashboard&tab=wa_ai_setup'), 'Salesperson numbers') !== false;
    $f['n_leads'] = strpos($s->page('alpha', 'page=dashboard&tab=leads&f=all'), 'SP Bravo Lead') !== false;
    $f['n_hold'] = OwnedNumberHold::forInstall($s->cfg, $s->data, $pdo)->holds('sales-001');
    $f['n_visibility'] = LeadVisibility::applies($s->cfg, $s->data);
    $s->stop();
    return $f;
}

if ($isDriver) {
    $out = [];
    if (in_array('south_sudan', $parts, true)) $out += sp_south_sudan($root);
    $ug = array_values(array_diff($parts, ['south_sudan']));
    if ($ug !== []) $out += sp_uganda($root, $ug);
    echo "\n" . json_encode($out, JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

// ═════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
// The assertions
// ═════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; } else { $fail++; echo "  FAIL {$m}" . ($d !== '' ? "\n       " . substr($d, 0, 900) : '') . "\n"; } }
$j = function ($v): string { return json_encode($v, JSON_UNESCAPED_UNICODE); };
$drive = function (string $tree, string $parts) use ($self): array {
    $out = (string)shell_exec('php ' . escapeshellarg($self) . ' --driver ' . escapeshellarg($tree) . ' ' . escapeshellarg($parts) . ' 2>/dev/null');
    $lines = array_values(array_filter(explode("\n", trim($out)), 'strlen'));
    $g = json_decode((string)end($lines), true);
    return is_array($g) ? $g : ['_raw' => substr($out, -600)];
};

$f = $drive($root, 'all');
if (isset($f['_raw'])) { echo "  FAIL the Uganda scenario did not finish\n       {$f['_raw']}\n"; exit(1); }
$A = (int)$f['ids']['A']; $B = (int)$f['ids']['B'];
$st = $f['setup'];

echo "\nSetup — the card, with the registry OFF (docs/65 §AA.3 step 1)\n";
is_(strpos($st['add'][0], 'ok: Added sales-001 for Alpha Seller') === 0 && strpos($st['add'][1], 'ok: Added sales-002 for Bravo Seller') === 0,
    'two numbers added on the card, each for its salesperson', $j($st['add']));
is_($st['added']['sales-001'] === ['sp-alpha', $A, 'disabled', 'sales', 'staff', 'owner', 'own', 1, 'Sales — Alpha Seller']
    && $st['added']['sales-002'] === ['sp-bravo', $B, 'disabled', 'sales', 'staff', 'owner', 'own', 1, 'Sales — Bravo Seller'],
    'each row: its own instance, its owner, role sales, hand-over to the owner, its own portfolio, assistant on — and switched OFF', $j($st['added']));
is_(strpos($st['add_department_instance'], 'no: ') === 0, 'a department\'s instance cannot be added as a salesperson\'s number', $st['add_department_instance']);
is_(($st['pair']['sales-001'] ?? false) && ($st['pair']['sales-002'] ?? false), 'pairing shows each number its own QR code');
is_(strpos($st['verify']['sales-001'][0], 'ok: Verified') === 0 && $st['verify']['sales-001'][1] === '+256700000901'
    && $st['verify']['sales-002'][1] === '+256700000902' && $st['verify']['sales-001'][2] === 'Sandbox Admin (#1)',
    'verify reads each business number from Evolution, and records who verified it', $j($st['verify']));
is_(strpos($st['webhook']['sales-001'][0], 'ok: ') === 0 && $st['webhook']['sales-001'][1] && $st['webhook']['sales-002'][1],
    'each webhook registered on its own instance', $j($st['webhook']));
is_(strpos($st['verify_departments'][0] ?? '', 'ok: Verified: the sales number') === 0
    && strpos($st['verify_departments'][1] ?? '', 'ok: Verified: the support number') === 0
    && strpos($st['verify_departments'][2] ?? '', "no: The account number uses the support number's instance") === 0,
    '5.18.90: the department numbers are verified on the card with the registry off — support\'s covers account, which shares its instance',
    $j($st['verify_departments'] ?? null));
is_(strpos($st['on_registry_off'][0], 'no: Not yet: switch the channel registry on first') === 0 && $st['on_registry_off'][1] === 'disabled',
    'switching a number on is refused while the registry is off', $j($st['on_registry_off']));
is_($st['in_registry_off'][1] === 'unknown_instance' && $st['in_registry_off_wrote'],
    'registry OFF: a message on the salesperson\'s instance is unknown, and nothing is written', $j($st['in_registry_off']));
is_($st['in_disabled'][1] === 'channel_disabled', 'registry ON, number still off: refused as switched off', $j($st['in_disabled']));
is_(strpos($st['on'][0], 'ok: ') === 0 && strpos($st['on'][1], 'ok: ') === 0 && $st['on'][2] === 'active' && $st['on'][3] === 'active',
    'registry ON: both numbers switched on', $j($st['on']));
$rt = $st['routing'];
is_($rt['registry'] === true && $rt['inbound'] === ['sp-alpha' => 'sales-001', 'sp-bravo' => 'sales-002', 'sp-sales' => 'sales', 'sp-supacc' => 'support', 'sp-nobody' => '']
    && $rt['outbound'] === ['sales-001' => 'sp-alpha', 'sales-002' => 'sp-bravo', 'sales' => 'sp-sales', 'support' => 'sp-supacc', 'account' => 'sp-supacc'],
    'checks 2–4: each instance resolves to exactly one channel and back; support and account still share theirs, support taking the inbound', $j($rt));
is_($rt['alpha_channels'] === ['sales-001'] && $rt['conflicts'] === [] && $rt['owner'] === [true, true],
    'check 3: the instance is one channel\'s, and the channel one salesperson\'s', $j($rt));
is_(array_map(function ($t) { return $t[0] . ':' . $t[1]; }, $st['trail'])
        === ['sales-001:created', 'sales-002:created', 'sales-001:number', 'sales-002:number', 'sales-001:status', 'sales-002:status']
    && count(array_filter($st['trail'], function ($t) { return $t[2] === 'Sandbox Admin (#1)'; })) === 6 && $st['trail_masks'],
    'the audit history: created, verified, switched on — each by the admin, the number masked; the refused switch-on left no row', $j($st['trail']));

echo "\nA–D. Each number answers on its own number\n";
is_($f['a_in'] === [200, 'accepted', 'sales-001', 1], 'A. check 4: a message on Alpha\'s instance resolves to Alpha\'s channel', $j($f['a_in']));
is_($f['a']['replies'] === [['sp-alpha', '256771900001']], 'A. check 8: the AI reply leaves on Alpha\'s number, and nothing else is sent', $j($f['a']['replies']));
is_($f['a']['conv'] && $f['a']['stored'] === 1, 'A. check 5: the conversation is Alpha\'s channel\'s, and the reply is stored in it', $j($f['a']));
is_($f['a']['role'] === 'sales' && $f['a']['persona'] === ['Alpha', true], 'A. checks 6, 7: the sales brain, as Alpha\'s assistant (D3)', $j([$f['a']['role'], $f['a']['persona']]));
is_($f['a']['lead'] === [true, 'channel:sales-001', 'sales-001', '+256700000901', true, true],
    'A. Phase 6: the lead is Alpha\'s, keeps its channel, the number it came in on and its conversation', $j($f['a']['lead']));
is_($f['b_in'][2] === 'sales-002' && $f['b']['replies'] === [['sp-bravo', '256771900002']] && $f['b']['persona'] === ['Bravo', true]
    && $f['b']['lead'] === [true, 'sales-002', '+256700000902'], 'B. Bravo\'s number: Bravo\'s reply, Bravo\'s assistant, Bravo\'s lead', $j($f['b']));
is_($f['c']['replies'] === [['sp-alpha', '256771900003'], ['sp-bravo', '256771900004']]
    && $f['c']['personas'] === ['Price of the Mini kit?' => 'Alpha', 'Price of the Standard kit?' => 'Bravo'],
    'C. two customers, two numbers, one worker run: each answered on its own number, each as its own salesperson\'s assistant', $j($f['c']));
is_($f['d']['convs'] === ['sales-001', 'sales-002'] && $f['d']['distinct'] && $f['d']['replies'] === [['sp-alpha', '256771900005'], ['sp-bravo', '256771900005']],
    'D. one customer on both numbers: two conversations, each answered on its own number', $j($f['d']));
is_($f['d']['isolated'], 'D. check 14: the second conversation\'s history holds nothing of the first');
is_($f['d']['identity'] === [88, 88, 'identified', 'identified', true], 'D. check 15: the customer is the same uCRM client on both numbers, and the brain knows them as the same person on each', $j($f['d']['identity']));
is_($f['d']['personas'] === ['Alpha', 'Bravo'], 'D. and each number speaks as its own salesperson\'s assistant', $j($f['d']['personas']));

echo "\nM. One central brain\n";
is_($f['m']['replies'] === [['sp-alpha', '256771900012'], ['sp-sales', '256771900011']], 'the department question answered on sales, the same question on Alpha\'s number on Alpha\'s', $j($f['m']['replies']));
is_($f['m']['same_context'] && $f['m']['owner'] === [2, false, 'Alpha'], 'the same context — knowledge, pricing, rules — but for the owner\'s first name', $j($f['m']['owner']));
is_($f['m']['same_prompt'] && $f['m']['persona'] === [true, true], 'the same prompt but for the persona it sets', $j($f['m']['persona']));
is_($f['m']['leaks'] === array_fill(0, 8, false), 'Phase 5: the brain is never told the instance, the business number, the channel id, the customer\'s number, nor anything of another salesperson', $j($f['m']['leaks']));

echo "\nThe two standing rules a live test meets\n";
is_($f['j8']['in'][1] === 'accepted' && $f['j8']['queued'] === 0 && $f['j8']['category'] === 'staff',
    'a support colleague\'s phone is kept for the team and never answered (5.18.50) — a live test must not come from an admin, support or accounts phone', $j($f['j8']));
// 5.18.90 (docs/65 §AD): rewritten, not deleted. Bravo owns sales-002, so Bravo's phone is a DishNet line owner's: kept for
// the team on Alpha's number and never answered — a salesperson's assistant never answers another salesperson.
is_($f['j8_sales']['in'][1] === 'accepted' && $f['j8_sales']['queued'] === 0 && $f['j8_sales']['category'] === 'staff',
    'a salesperson who owns a number is never answered by another salesperson\'s assistant (5.18.90) — kept for the team', $j($f['j8_sales']));
is_($f['j8_colleague']['in'][1] === 'accepted' && $f['j8_colleague']['queued'] === 1,
    'a sales colleague who owns no number is answered like a customer\'s — so they can test the line from their own phone', $j($f['j8_colleague']));
is_($f['handset']['webhook'][1] === 'accepted' && $f['handset']['state'] === 'human_active' && array_column($f['handset']['stored'], 'agent_name') === ['Team'],
    'the salesperson answering on their own phone is recorded as the team\'s, and the chat is theirs', $j($f['handset']));
is_($f['handset']['brain'] === 0 && $f['handset']['sends'] === 0, 'and the AI stands down on it: the next message is not answered', $j($f['handset']));

echo "\nE. The Inbox (check 12)\n";
is_($f['e']['alpha'] === [200, 'sales-001', [['sp-alpha', '256771900101']]] && $f['e']['bravo'] === [200, 'sales-002', [['sp-bravo', '256771900102']]],
    'a staff reply leaves on the conversation\'s own salesperson number', $j([$f['e']['alpha'], $f['e']['bravo']]));
is_($f['e']['media'] === [[200, 200], [['sp-alpha', 'image', '256771900101'], ['sp-alpha', 'document', '256771900101']]],
    'an image and a document from the Inbox: on the same number', $j($f['e']['media']));
is_($f['e']['stored'] === 1 && $f['e']['state'] === 'human_active', 'stored with its WhatsApp id; the chat is the team\'s', $j($f['e']));
is_($f['e_salesperson'] === [403, 0], 'a salesperson cannot send from the Inbox', $j($f['e_salesperson']));

echo "\nF. The hand-over (check 9)\n";
is_(array_column($f['f']['customer'], 0) === ['sp-alpha'], 'the customer\'s one message — the assistant\'s hand-over line — leaves on Alpha\'s number', $j($f['f']['customer']));
is_(count($f['f']['owner']) === 1 && $f['f']['owner'][0][0] === 'sp-sales' && strpos($f['f']['owner'][0][1], 'a customer on your WhatsApp line needs you') !== false,
    'Alpha is told on their phone, from the DishNet sales number (D4)', $j($f['f']['owner']));
is_(count($f['f']['central']) === 1 && $f['f']['central'][0][0] === 'sp-sales' && strpos($f['f']['central'][0][1], "(sales-001, Alpha's line)") !== false,
    'and the copy to the alert number, from the same', $j($f['f']['central']));
is_($f['f']['others'] === 0 && $f['f']['state'] === 'needs_human' && $f['f']['escalations'] === 1, 'nothing else is sent; the chat waits for a person', $j($f['f']));
is_($f['f2']['customer'] === [['sp-alpha', 'SP-HOLDING-LINE: a colleague will reply shortly.']] && $f['f2']['owner'] === ['sp-sales']
    && $f['f2']['central'] === ['sp-sales'] && $f['f2']['state'] === 'needs_human',
    'the assistant with nothing to say: the operator\'s holding line on Alpha\'s number; Alpha and the central number told', $j($f['f2']));

echo "\nG. A follow-up (check 10)\n";
if ($f['g'] === 'no open zone') {
    echo "  skip no time zone is inside the follow-up sending window now (09:00–18:00, not Sunday)\n";
} else {
    is_($f['g']['scan'] === 1, 'the scan opens one on a quiet chat on Alpha\'s number', $j($f['g']));
    is_($f['g']['sent'] === [['sp-alpha', '256771900302']] && $f['g']['record'] === [['sales-001', 1]] && $f['g']['closed'] === null,
        'an approved follow-up leaves on Alpha\'s number, recorded against Alpha\'s channel', $j($f['g']));
    is_($f['g']['held'] === [0, 'cancelled'], 'without wa_followups_on_owned_numbers it is held — the decided switch is what sends it', $j($f['g']['held']));
    echo "  note the follow-ups ran in the time zone {$f['zone']}, inside the sending window at this hour\n";
}

echo "\nH. The assistant's photo and document (check 11)\n";
is_($f['h_store'] === [true, true], 'a photo and a spec sheet in the media library', $j($f['h_store']));
is_($f['h']['text'] === [['sp-alpha', '256771900401']] && $f['h']['media'] === [['sp-alpha', 'image', '256771900401'], ['sp-alpha', 'document', '256771900401']],
    'the reply, the photo and the document all leave on Alpha\'s number', $j($f['h']));

echo "\nI. A switched-off number fails closed (check 13)\n";
is_(strpos($f['i_off'][0], 'ok: ') === 0 && $f['i_off'][1] === 'disabled', 'Bravo\'s number switched off on the card', $j($f['i_off']));
is_($f['i_in'][1] === 'channel_disabled' && $f['i_in_wrote'] === [0, 0, true], 'a new message on it: refused, nothing written', $j([$f['i_in'], $f['i_in_wrote']]));
is_($f['i_queued']['customer'] === [] && $f['i_queued']['brain'] === 0 && $f['i_queued']['state'] === 'needs_human'
    && $f['i_queued']['escalations'] === 1 && $f['i_queued']['log'],
    'a message queued before: nothing sent to the customer from ANY number, the brain never asked, a person told, the failure logged', $j($f['i_queued']));
is_(count($f['i_queued']['others']) === 1 && $f['i_queued']['others'][0] === ['sp-sales', '256700000999'], 'the only message is the alert to the central number', $j($f['i_queued']['others']));
is_(!empty($f['i_queued']['by_route']) && !empty($f['k_channel']['by_route']), '5.18.90: the reply route refuses these first (the automated-send policy behind it is the second line)', $j([$f['i_queued']['by_route'] ?? null, $f['k_channel']['by_route'] ?? null]));
is_($f['i_inbox'] === [502, true, 0, 0, true], 'the Inbox refuses it and says so; nothing sent, nothing stored, no instance named', $j($f['i_inbox']));
is_(strpos($f['i_back'][0], 'ok: ') === 0 && $f['i_back'][1] === 'active', 'switched back on', $j($f['i_back']));

echo "\nJ. A disconnected instance fails closed (check 13)\n";
is_($f['j_first']['sends'] === 0 && $f['j_first']['tried'] === [['sp-alpha', 'text', '256771900601']],
    'the reply is tried on Alpha\'s number alone; refused there, nothing leaves from any other number', $j($f['j_first']));
is_($f['j_first']['event'] === ['failed', 1] && $f['j_first']['escalations'] === 0, 'the message waits for a retry', $j($f['j_first']));
is_($f['j_dead']['event'] === 'dead' && $f['j_dead']['customer'] === [] && $f['j_dead']['state'] === 'needs_human' && $f['j_dead']['escalations'] === 1,
    'the retries spent: a person is told, and the customer is sent nothing from any other number', $j($f['j_dead']));
is_(array_column($f['j_dead']['owner'], 0) === ['sp-sales'] && array_column($f['j_dead']['central'], 0) === ['sp-sales'],
    'the alerts: Alpha and the central number, from the DishNet sales number', $j([$f['j_dead']['owner'], $f['j_dead']['central']]));
is_($f['j_dead']['tried'] === [['sp-alpha', 'text', '256771900601'], ['sp-alpha', 'text', '256771900601']],
    'the last attempt and the holding line were both tried on Alpha\'s number only', $j($f['j_dead']['tried']));
is_($f['j_inbox'] === [502, true, 0, true], 'the Inbox, while the number is down: refused and said, nothing sent elsewhere', $j($f['j_inbox']));
is_($f['j_maybe']['customer'] === [] && $f['j_maybe']['tried'] === 1 && $f['j_maybe']['event'] === 'done' && $f['j_maybe']['state'] === 'needs_human'
    && $f['j_maybe']['escalations'] === 1 && $f['j_maybe']['holding'] === 0,
    'a gateway timeout: the reply may have gone, so it is never sent again and no holding line follows — a person decides', $j($f['j_maybe']));
is_($f['j_guard'] === [['256700000999', true]], 'the guard sees the number disconnected and says so, naming sales-001, on the alert number', $j($f['j_guard']));
is_($f['j_back'] === [['sp-alpha', '256771900603']], 'reconnected: Alpha\'s number answers again, on Alpha\'s number', $j($f['j_back']));

echo "\nK. Unknown fails closed\n";
is_($f['k_in'][1] === 'unknown_instance' && $f['k_in_wrote'] === [0, 0], 'an unknown instance: refused, nothing written', $j([$f['k_in'], $f['k_in_wrote']]));
is_($f['k_channel']['customer'] === [] && $f['k_channel']['brain'] === 0 && $f['k_channel']['state'] === 'needs_human'
    && $f['k_channel']['escalations'] === 1 && $f['k_channel']['central'] === ['sp-sales'] && $f['k_channel']['log'],
    'a channel id nothing routes: no reply from any number; logged; the central number told', $j($f['k_channel']));
is_($f['k_moved']['customer'] === [] && $f['k_moved']['brain'] === 0 && $f['k_moved']['log'],
    'the channel moved to another instance meanwhile: the customer gets nothing from the new one', $j($f['k_moved']));

echo "\nL. No salesperson sees another's customers\n";
is_($f['l_leads'] === [[true, 'sales-001'], [true, 'sales-002']], 'each lead is its salesperson\'s, with its channel', $j($f['l_leads']));
is_($f['l_pages']['alpha'] === ['SP Lima Alpha' => true, 'SP Lima Bravo' => false] && $f['l_pages']['bravo'] === ['SP Lima Alpha' => false, 'SP Lima Bravo' => true],
    'own leads only: Alpha sees Alpha\'s, Bravo sees Bravo\'s', $j($f['l_pages']));
is_($f['l_pages']['mgr'] === ['SP Lima Alpha' => true, 'SP Lima Bravo' => true] && $f['l_pages']['admin'] === ['SP Lima Alpha' => true, 'SP Lima Bravo' => true],
    'a manager and an admin see both', $j($f['l_pages']));
is_($f['l_open_foreign'] === false && $f['l_call'] === [404, 200], 'another\'s lead cannot be opened by its id, nor a call logged on it', $j([$f['l_open_foreign'], $f['l_call']]));
is_($f['l_inbox'] === [403, 403], 'the Inbox: a salesperson can read no conversation', $j($f['l_inbox']));
is_($f['l_inbox_granted'] === [200, true],
    'measured: were the Inbox granted to the sales role, it would list another salesperson\'s chats — so the pilot keeps wa_inbox_roles free of it',
    $j($f['l_inbox_granted']));
is_($f['l_brain'] === [true, false, false, false, false], 'the brain on Alpha\'s number holds nothing of Bravo\'s customers', $j($f['l_brain']));

echo "\nO, P. Sales, support and account: the same with the registry OFF, ON alone, and ON with the pilot\n";
foreach (['sales', 'support'] as $ch) {
    foreach (['webhook', 'payload', 'role', 'line_owner', 'context', 'prompt', 'reply_on', 'inbox', 'paused'] as $k) {
        $a = $f['g_off'][$ch][$k] ?? null; $b = $f['g_alone'][$ch][$k] ?? null; $c = $f['g_pilot'][$ch][$k] ?? null;
        is_(isset($f['g_off'][$ch]) && array_key_exists($k, $f['g_off'][$ch]) && $a === $b && $b === $c, "{$ch}: {$k} identical in all three", $j([$a, $b, $c]));
    }
}
$go = $f['g_off'];
is_($go['sales']['webhook'] === [200, 'accepted', 'sales'] && $go['support']['webhook'] === [200, 'accepted', 'support'],
    'sales arrives as sales; the shared support/account instance arrives as support, as today', $j([$go['sales']['webhook'], $go['support']['webhook']]));
is_(array_column($go['sales']['reply_on'], 0) === ['sp-sales'] && array_column($go['support']['reply_on'], 0) === ['sp-supacc']
    && $go['sales']['line_owner'] === null && $go['support']['line_owner'] === null,
    'each answered on its own number, with no persona', $j([$go['sales']['reply_on'], $go['support']['reply_on']]));
is_($go['sales']['inbox'] === [200, 'sales', ['sp-sales']] && $go['support']['inbox'] === [200, 'support', ['sp-supacc']]
    && $go['sales']['paused'] === ['brain' => 0, 'sends' => 0], 'the Inbox on its own number, and the AI stands down after it', $j([$go['sales']['inbox'], $go['support']['inbox']]));
is_($f['g_off']['account'] === $f['g_alone']['account'] && $f['g_alone']['account'] === $f['g_pilot']['account']
    && $go['account']['inbox_account'] === [200, 'account'] && $go['account']['inbox_accounts'] === [200, 'accounts']
    && array_column($go['account']['sent_on'], 0) === ['sp-supacc', 'sp-supacc', 'sp-supacc', 'sp-supacc'],
    'P. account: its Inbox threads and its notifications on the shared instance, identical in all three', $j([$f['g_off']['account'], $f['g_pilot']['account']]));
is_($f['g_off']['handover'] === $f['g_alone']['handover'] && $f['g_alone']['handover'] === $f['g_pilot']['handover']
    && $f['g_off']['handover_state'] === 'needs_human' && $f['g_pilot']['handover_state'] === 'needs_human',
    'a hand-over on the department sales number: the same messages, from the same numbers, in all three', $j([$f['g_off']['handover'], $f['g_pilot']['handover']]));

echo "\nThe rollback (Phase 11): pilot OFF, registry OFF, and back\n";
is_(strpos($f['r1_off'][0], 'ok: ') === 0 && $f['r1_off'][1] === 'disabled', 'R1. the pilot switched off on the card — the channel is kept, not deleted', $j($f['r1_off']));
is_($f['r1_in'][1] === 'channel_disabled' && $f['r1_in_queued'] === 0, 'R1. a new message on it is refused; the AI never sends from it', $j($f['r1_in']));
is_($f['r1_queued']['customer'] === [] && $f['r1_queued']['brain'] === 0 && $f['r1_queued']['escalations'] === 1,
    'R1. a message queued before the switch-off: never answered from any number, a person told', $j($f['r1_queued']));
is_($f['r1_inbox'] === [502, true, 0], 'R1. the Inbox refuses it; nothing sent from any other number', $j($f['r1_inbox']));
if (isset($f['r1_followup'])) is_($f['r1_followup'] === [0, 'cancelled'], 'R1. a follow-up approved before is closed, never sent', $j($f['r1_followup']));
is_($f['r1_departments'] === [['sp-sales', '256771900904'], ['sp-supacc', '256771900905']], 'R1. sales and support unaffected', $j($f['r1_departments']));
$kb = $f['r1_kept']['before']; $ka = $f['r1_kept']['after'];
is_($kb['convs'] > 0 && $kb['lead'][0] === $A && $ka['convs'] >= $kb['convs'] && $ka['messages'] >= $kb['messages'] && $ka['lead'] === $kb['lead']
    && $ka['channel'] === 1 && $ka['trail'] === $kb['trail'] + 1, 'R1. conversations, messages and the lead kept; the channel row kept; one trail row added', $j($f['r1_kept']));
is_($f['r2_in'][1] === 'unknown_instance' && $f['r2_inbox'] === [502, true, 0], 'R2. registry OFF: the number is unknown again; the Inbox still sends nothing for it', $j([$f['r2_in'], $f['r2_inbox']]));
if (isset($f['r2_followup'])) is_($f['r2_followup'] === 0, 'R2. a follow-up on it is never sent from another number', $j($f['r2_followup']));
is_($f['r2_departments'] === [['sp-sales', '256771900907'], ['sp-supacc', '256771900908']], 'R2. sales and support exactly as before', $j($f['r2_departments']));
$k2 = $f['r2_kept'];
is_($k2['convs'] >= $ka['convs'] && $k2['lead'] === $kb['lead'] && $k2['channel'] === 1 && $k2['trail'] === $ka['trail'] && $k2['trail_first'] === $ka['trail_first'],
    'R2. everything kept; the registry switch changes no row of the trail', $j($k2));
is_($f['r3_alpha_sees'] === [false, true], 'R3. the two other switches cleared: the team\'s lead pages as before the pilot', $j($f['r3_alpha_sees']));
is_(strpos($f['r4_on'][0], 'ok: ') === 0 && $f['r4_on'][1] === 'active' && $f['r4_reply'] === [['sp-alpha', '256771900909']],
    'R4. switched back on: Alpha\'s number answers on Alpha\'s number again', $j([$f['r4_on'], $f['r4_reply']]));

echo "\nQ. Domain B\n";
is_($f['q_sandbox'] === false && $f['q_loaded'] === [], 'the whole pilot ran on a copy of the plugin without Domain B, and loaded nothing from it', $j([$f['q_sandbox'], $f['q_loaded']]));

echo "\nN. South Sudan — unchanged, every switch set\n";
$z = $drive($root, 'south_sudan');
is_(!isset($z['_raw']) && $z['n_webhook']['sales'] === [200, 'accepted', 'sales'] && $z['n_webhook']['support'] === [200, 'accepted', 'support']
    && $z['n_webhook']['salesperson'][1] === 'unknown_instance', 'the numbers arrive as configured; a salesperson\'s instance is unknown', $j($z['n_webhook'] ?? $z));
is_(($z['n_registry'] ?? null) === [false, '', ''] && ($z['n_card'] ?? true) === false && ($z['n_leads'] ?? false) === true
    && ($z['n_hold'] ?? true) === false && ($z['n_visibility'] ?? true) === false,
    'the registry never on, no card, every lead visible, nothing held', $j($z));

// ── X ──
echo "\nX. Weakened copies, each caught\n";
$mutants = [
    ['a failed send is sent again on the department sales number', 'lib/EvolutionApiService.php',
     "        return \$this->request('POST', '/message/sendText/' . rawurlencode(\$instance), [\n            'number' => self::normalisePhone(\$phone),\n            'text'   => \$text,\n        ]);",
     "        \$__r = \$this->request('POST', '/message/sendText/' . rawurlencode(\$instance), ['number' => self::normalisePhone(\$phone), 'text' => \$text]);\n"
   . "        if (empty(\$__r['ok']) && \$instance !== \$this->instanceFor('sales')) \$__r = \$this->request('POST', '/message/sendText/' . rawurlencode(\$this->instanceFor('sales')), ['number' => self::normalisePhone(\$phone), 'text' => \$text]);\n"
   . "        return \$__r;", 'refusals',
     function (array $x) { return ($x['j_first']['sends'] ?? 0) !== 0 || ($x['j_dead']['customer'] ?? []) !== []; }],
    ['a channel with no instance borrows the sales number', 'lib/EvolutionApiService.php',
     "        return \$this->channelToInstance[\$channel] ?? '';",
     "        return \$this->channelToInstance[\$channel] ?? (\$this->channelToInstance['sales'] ?? '');", 'refusals',
     function (array $x) { return ($x['i_inbox'][2] ?? 0) !== 0; }],
    ['the registry routes a switched-off number', 'lib/ChannelRegistry.php',
     "            if (\$ctx->isActive()) { \$c2i[\$id] = \$inst; \$i2c[\$key] = \$id; }\n            else                  { \$refused[\$key] = \$id; }",
     "            \$c2i[\$id] = \$inst; \$i2c[\$key] = \$id;", 'refusals',
     function (array $x) { return ($x['i_in'][1] ?? '') !== 'channel_disabled'; }],
    ['the webhook takes a switched-off number\'s messages as sales', 'evo_webhook.php',
     "    if (\$evo->instanceState(\$instance) === 'refused') {\n        error_log(EvoWebhookGuard::safeLogLine(\$event, \$instance, 'channel_disabled'));\n"
   . "        evoRespond(200, 'channel_disabled');\n    }\n    error_log(EvoWebhookGuard::safeLogLine(\$event, \$instance, 'unknown_instance'));\n"
   . "    evoRespond(200, 'unknown_instance');\n}",
     "    if (\$evo->instanceState(\$instance) === 'refused') {\n        \$channel = 'sales';\n    } else {\n"
   . "    error_log(EvoWebhookGuard::safeLogLine(\$event, \$instance, 'unknown_instance'));\n    evoRespond(200, 'unknown_instance');\n    }\n}", 'refusals',
     function (array $x) { return ($x['i_in'][1] ?? '') !== 'channel_disabled' || ($x['i_in_wrote'] ?? []) !== [0, 0, true]; }],
    ['the worker answers whatever the route says', 'workers/AiReplyWorker.php',
     "        if (\$route['ok']) return \$route['context']->role();",
     "        return \$route['context'] !== null ? \$route['context']->role() : 'sales';", 'refusals',
     // 5.18.90: amended, not deleted — the automated-send policy now refuses the same send behind the route, so the copy is
     // caught by WHICH layer refused it (the route must be first), as well as by the escalation count.
     function (array $x) { return ($x['i_queued']['escalations'] ?? 0) !== 1 || ($x['k_channel']['escalations'] ?? 0) !== 1
                               || empty($x['i_queued']['by_route']) || empty($x['k_channel']['by_route']); }],
    ['the Inbox answers a salesperson\'s chat from support', 'lib/InboxReplyRoute.php',
     "            return ['mode' => 'channel', 'sender' => '', 'channel' => \$ch, 'reason' => ''];",
     "            return ['mode' => 'sender', 'sender' => 'support', 'channel' => \$ch, 'reason' => ''];", 'inbox',
     function (array $x) { return ($x['e']['alpha'][2] ?? null) !== [['sp-alpha', '256771900101']]; }],
    ['the follow-up leaves on the department sales number', 'cron/followup_send.php',
     "    \$res = \$evo->sendText(\$chan, \$phone, \$body, ContactOptOut::CLASS_PROACTIVE);",
     "    \$res = \$evo->sendText('sales', \$phone, \$body, ContactOptOut::CLASS_PROACTIVE);", 'followup',
     function (array $x) { return ($x['g'] ?? null) === 'no open zone' ? null : ($x['g']['sent'] ?? null) !== [['sp-alpha', '256771900302']]; }],
    ['the photo leaves on the department sales number', 'workers/AiReplyWorker.php',
     "            \$send = \$this->evo->sendImage(\$channel, \$phone, \$media, (string)\$photo['caption']);",
     "            \$send = \$this->evo->sendImage('sales', \$phone, \$media, (string)\$photo['caption']);", 'media',
     function (array $x) { return ($x['h']['media'][0] ?? null) !== ['sp-alpha', 'image', '256771900401']; }],
    ['the document leaves on the department sales number', 'workers/AiReplyWorker.php',
     "            \$send = \$this->evo->sendMedia(\$channel, \$phone, 'document', \$media,",
     "            \$send = \$this->evo->sendMedia('sales', \$phone, 'document', \$media,", 'media',
     function (array $x) { return ($x['h']['media'][1] ?? null) !== ['sp-alpha', 'document', '256771900401']; }],
    // The hand-over moved into lib/Handover.php after 5.18.89 (docs/55 §9); on the 5.18.89 release it is in the worker.
    is_file($root . '/lib/Handover.php')
    ? ['the holding line leaves on the department sales number', 'lib/Handover.php',
       "                \$send = \$evo->sendText(\$channel, \$phone, \$holding, \\ContactOptOut::CLASS_REPLY);",
       "                \$send = \$evo->sendText('sales', \$phone, \$holding, \\ContactOptOut::CLASS_REPLY);", 'handover',
       function (array $x) { return ($x['f2']['customer'][0][0] ?? null) !== 'sp-alpha'; }]
    : ['the holding line leaves on the department sales number', 'workers/AiReplyWorker.php',
       "                \$send = \$this->evo->sendText(\$channel, \$phone, \$holding, ContactOptOut::CLASS_REPLY);",
       "                \$send = \$this->evo->sendText('sales', \$phone, \$holding, ContactOptOut::CLASS_REPLY);", 'handover',
       function (array $x) { return ($x['f2']['customer'][0][0] ?? null) !== 'sp-alpha'; }],
    ['account takes the shared instance\'s inbound once the registry is on', 'lib/ChannelRegistry.php',
     "            if (isset(\$i2c[\$key]) || isset(\$refused[\$key])) continue;   // an earlier department owns it for inbound",
     "", 'departments',
     function (array $x) { return ($x['g_alone']['support']['webhook'] ?? 'missing') !== ($x['g_off']['support']['webhook'] ?? 'missing'); }],
    ['own leads only lets everything through', 'lib/LeadVisibility.php',
     "        if (!self::applies(\$config, \$dataDir) || self::seesAll(\$viewer, \$rbac)) return true;\n        return self::mine(",
     "        return true;\n        return self::mine(", 'visibility',
     function (array $x) { return ($x['l_pages']['alpha']['SP Lima Bravo'] ?? true) !== false || ($x['l_call'][0] ?? 0) !== 404; }],
];
foreach ($mutants as [$name, $rel, $old, $new, $part, $caught]) {
    [$copy, $n] = sj_weakened_copy($root, $rel, $old, $new);
    if ($n !== 1) { is_(false, "mutant anchor is unique: {$name}", "count {$n} in {$rel}"); exec('rm -rf ' . escapeshellarg($copy)); continue; }
    $x = $drive($copy, $part);
    $caughtIt = isset($x['_raw']) ? false : $caught($x);
    if ($caughtIt === null) {
        echo "  skip mutant not run: {$name} — no time zone is inside the follow-up sending window now\n";
        exec('rm -rf ' . escapeshellarg($copy));
        continue;
    }
    is_($caughtIt, "mutant is caught: {$name}", isset($x['_raw']) ? $x['_raw'] : $j(array_intersect_key($x, array_flip(['j_first', 'j_dead', 'i_inbox', 'i_in', 'i_queued', 'k_channel', 'e', 'g', 'h', 'f', 'g_off', 'g_alone', 'l_pages', 'l_call']))));
    exec('rm -rf ' . escapeshellarg($copy));
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
