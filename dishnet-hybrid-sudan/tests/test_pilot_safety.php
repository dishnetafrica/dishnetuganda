<?php
declare(strict_types=1);
/**
 * test_pilot_safety.php — the pre-pilot safety fix (5.18.90, docs/65 §AD, docs/66): no DishNet number's assistant ever
 * answers another DishNet number, and a number whose assistant is off sends nothing automated — follow-ups included.
 *
 * Production's shape (as test_sales_pilot.php): the sales department number on its own instance, support and account
 * SHARING one, every number selling; two salesperson numbers added on the card — Alpha's and Bravo's — the department
 * numbers verified on the card, the registry on, follow-ups on owned numbers on, followup_auto_send on. The plugin runs
 * under php -S (SjSandbox) beside a fake Evolution; the AI worker runs in this process with a brain that never leaves it.
 * Every person, number, instance and lead is fictitious; nothing leaves 127.0.0.1. Message ids are SHARED where WhatsApp
 * might share them, and fresh where it might not: the fix must not depend on either.
 *
 * The instruction's tests 1–25, by part:
 *   replies     1, 2 (each number answers from itself), 16 (isolation by channel id)
 *   cooldown    3   the human pause still works for a customer
 *   internal    4–7 (A→B, B→Sales, Support→Salesperson, Account→Salesperson, shared and fresh ids), 18 (no lead),
 *               19 (a customer-like question), 20 (after the pause; an event queued before the fix); a switched-off line's
 *               number; the owner, the alert number and a colleague who owns no number
 *   seed        the alert loop's seed: the owner's phone of record IS their line
 *   assistant   8, 9, 22 (assistant off: no reply, no follow-up — the automatic one included)
 *   followups   10, 11, 12, 23 (each from its own number), 21 (to a DishNet number: none), paused: waits, never closed
 *   refusals    13 (disabled), 14 (disconnected), 15 (unknown), 24 (a mapping changed), 25 (switched off between
 *               selection and send — in the worker, the sender and the cron), an unverified number
 *   inbox       17  a person's Inbox reply leaves from the conversation's own number, assistant on or off
 *   watchdog    an internal chat left unanswered on purpose is never paged about
 *   gaps        a department instance changed after verification: the salesperson numbers stop again; what a
 *               department verification may and may not take from another row; an @lid owner is never verified
 *   reported    the numbers Evolution reports: a re-paired phone is unverified (one phone on two instances included); a
 *               verification on the card records what Evolution reports at once
 *   stale       a number switched on again under an older read waits for the next one; a department with no instance
 *               is closed
 *   registry    OFF: 5.18.89 exactly — a DishNet line writing to the sales number is answered as before; the guard
 *               records nothing
 *   media       (development tree) a DishNet number's voice note; a conversation read that fails is retried
 *   unit        InternalNumbers and AutomationPolicy, in process
 *   south_sudan, Q (Domain B)
 *
 * Driver mode: php tests/test_pilot_safety.php --driver <plugin-root> <part,part,...>
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
require_once $root . '/lib/InternalNumbers.php';
require_once $root . '/lib/AutomationPolicy.php';
require_once $root . '/lib/NotificationService.php';
require_once $root . '/lib/LineOwner.php';
require_once $root . '/lib/OwnedLead.php';
require_once $root . '/lib/LeadVisibility.php';
require_once $root . '/lib/OwnedNumberHold.php';
require_once $root . '/lib/SalesNumbersAdmin.php';
require_once $root . '/lib/FollowUpPolicy.php';
require_once $root . '/lib/FollowUpService.php';
require_once $root . '/lib/ContactOptOut.php';
require_once $root . '/workers/WorkerBase.php';
require_once $root . '/workers/AiReplyWorker.php';
if (is_file($root . '/workers/MediaWorker.php')) require_once $root . '/workers/MediaWorker.php';   // development tree only

/** A brain that never leaves the process; its canned answer goes through the REAL marker parser. $onReply runs first. */
class PsBrain extends DishNetAiBrain
{
    public string $canned = '';
    /** @var callable|null something that happens while the model "thinks" */
    public $onReply = null;
    /** @var array[] */
    public array $contexts = [];
    public function isConfigured(): bool { return true; }
    public function reply(array $context): array
    {
        $this->contexts[] = $context;
        if (is_callable($this->onReply)) ($this->onReply)();
        $m = new ReflectionMethod(DishNetAiBrain::class, 'parseMarkers');
        $m->setAccessible(true);
        $out = $m->invoke($this, $this->canned);
        return is_array($out) ? $out : ['reply' => $this->canned];
    }
    public function getLastUsage(): array { return []; }
}

/**
 * A zone where it is now between 09:00 and 18:59 on a day that is not Sunday, or '' (follow-ups send 08:00–19:59, never on a
 * Sunday). With UTC+14 and UTC−12 in the list, '' happens only on Sundays from 07:00 to 18:59 UTC — and then the follow-up
 * sends cannot be run at all: the test says so as a FAILURE, never by skipping quietly.
 */
function ps_open_zone(): string
{
    foreach (['Africa/Kampala', 'Europe/London', 'Europe/Berlin', 'Asia/Dubai', 'Asia/Kolkata', 'Asia/Bangkok', 'Asia/Tokyo',
              'Australia/Sydney', 'Pacific/Auckland', 'Pacific/Kiritimati', 'Pacific/Pago_Pago', 'Pacific/Honolulu',
              'America/Anchorage', 'America/Los_Angeles', 'America/Denver', 'America/Chicago', 'America/New_York',
              'America/Sao_Paulo', 'Atlantic/Azores', 'Atlantic/Cape_Verde', 'America/Noronha', 'Asia/Kathmandu',
              'Etc/GMT-14', 'Etc/GMT+12'] as $z) {
        $t = new DateTimeImmutable('now', new DateTimeZone($z));
        $h = (int)$t->format('G');
        if ((int)$t->format('w') !== 0 && $h >= 9 && $h <= 18) return $z;
    }
    return '';
}

/** [[instance, number], ...] of recorded sends. */
function ps_pairs(array $calls): array
{
    return array_values(array_map(function ($c) { return [(string)$c['instance'], (string)$c['number']]; }, $calls));
}

/** The DishNet numbers of this scenario — lines, owners, the alert number — that nothing automated may ever reach. */
const PS_INTERNAL = ['256700000900', '256700000901', '256700000902', '256700000903', '256700000904', '256700000201',
                     '256700000202', '256700000999', '256700000913', '256700000922', '256700000981', '256700000982'];

// ═════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
// Uganda, in production's shape
// ═════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
function ps_uganda(string $root, array $parts): array
{
    $all  = in_array('all', $parts, true);
    $want = function (string $p) use ($all, $parts): bool { return $all || in_array($p, $parts, true); };
    $f    = [];
    $zone = ps_open_zone();
    $f['zone'] = $zone;
    $base = ['tenant_profile' => 'uganda', 'ai_enabled' => '1', 'ai_currency' => 'UGX', 'ai_provider' => 'openai',
             'openai_api_key' => 'test-key-never-called', 'alert_whatsapp' => '256700000999',
             'ai_handover_message' => 'PS-HOLDING-LINE: a colleague will reply shortly.', 'ai_lead_capture' => '1',
             'followup_enabled' => '1', 'followup_auto_send' => '1', 'wa_human_cooldown_minutes' => 5, 'ai_media_enabled' => '0',
             'plugin_public_url' => 'https://plugin.example.test',
             'evo_instance_sales' => 'ps-sales', 'evo_instance_support' => 'ps-supacc', 'evo_instance_account' => 'ps-supacc',
             'ai_sales_on_all_numbers' => '1', 'alert_hours_from' => 0, 'alert_hours_to' => 24, 'alert_patience_minutes' => 1,
             'timezone' => $zone !== '' ? $zone : 'UTC'];
    $s   = SjSandbox::start($root, $base, 'ps');
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
    $nPresence  = function () use ($evoState): int { return count($evoState()['presence_calls'] ?? []); };
    $presenceSince = function (int $n) use ($evoState): array { return array_slice((array)($evoState()['presence_calls'] ?? []), $n); };
    $nFailed    = function () use ($evoState): int { return count($evoState()['failed_calls'] ?? []); };
    $failedSince = function (int $n) use ($evoState): array { return array_slice((array)($evoState()['failed_calls'] ?? []), $n); };
    // The instances Evolution reports, each with its paired number; $over re-pairs one (a JID, @lid owners included).
    $live = function (array $over = []) use ($s): void {
        $rows = [];
        foreach (['ps-sales' => '256700000900', 'ps-supacc' => '256700000903', 'ps-alpha' => '256700000901',
                  'ps-bravo' => '256700000902', 'ps-acct' => '256700000904'] as $n => $num) {
            $rows[] = ['name' => $n, 'connectionStatus' => 'open',
                       'ownerJid' => $over[$n] ?? ($num . '@s.whatsapp.net'), 'profileName' => 'PS'];
        }
        foreach (array_diff_key($over, ['ps-sales' => 1, 'ps-supacc' => 1, 'ps-alpha' => 1, 'ps-bravo' => 1, 'ps-acct' => 1]) as $n => $jid) {
            $rows[] = ['name' => $n, 'connectionStatus' => 'open', 'ownerJid' => $jid, 'profileName' => 'PS'];
        }
        $s->http('POST', "{$s->evo}/__test/instances", $rows, ['Content-Type: application/json']);
    };
    $down = function (string $instance) use ($s): void { $s->http('GET', "{$s->evo}/__test/fail_instance?name={$instance}&code=500"); };
    $up   = function (string $instance) use ($s): void { $s->http('GET', "{$s->evo}/__test/fail_instance?name={$instance}&off=1"); };
    $in = function (string $phone, string $text, string $instance, string $id = '') use ($s): array {
        $r = $s->evoInbound($phone, $text, $instance, $id !== '' ? $id : 'PS-' . bin2hex(random_bytes(5)));
        return [$r[0], $r[2]['outcome'] ?? null, $r[2]['channel'] ?? null, $r[2]['queued'] ?? null];
    };
    // A DishNet line's handset sending to someone: Evolution posts it back as fromMe on the SENDING instance.
    $handset = function (string $to, string $text, string $instance, string $id = '') use ($s): array {
        $payload = ['event' => 'messages.upsert', 'instance' => $instance, 'data' => [
            'key' => ['id' => $id !== '' ? $id : 'PS-HS-' . bin2hex(random_bytes(5)), 'fromMe' => true, 'remoteJid' => $to . '@s.whatsapp.net'],
            'message' => ['conversation' => $text], 'messageTimestamp' => time(), 'pushName' => 'PS']];
        $r = $s->http('POST', "{$s->base}?page=evo_webhook", $payload, ['Content-Type: application/json', 'X-DishNet-Token: ' . $s->evoKey]);
        return [$r[0], $r[2]['outcome'] ?? null];
    };
    $nEvents = function (string $type) use ($q): int { return (int)($q('SELECT COUNT(*) c FROM events WHERE event_type = ?', [$type])[0]['c'] ?? 0); };
    $runWorker = function (string $canned, ?callable $onReply = null) use ($s, &$cfgNow): array {
        $w = new AiReplyWorker($s->store(), $cfgNow, 30, 10);
        $b = new PsBrain($cfgNow); $b->canned = $canned; $b->onReply = $onReply;
        $rp = new ReflectionProperty(AiReplyWorker::class, 'brain'); $rp->setAccessible(true); $rp->setValue($w, $b);
        ob_start();
        try { $res = $w->run(); } finally { $log = (string)ob_get_clean(); }
        return ['brain' => $b, 'log' => $log, 'run' => $res];
    };
    $settle = function () use ($pdo): void {
        $pdo->exec("UPDATE events SET status = 'done' WHERE event_type = 'ai.reply' AND status IN ('pending', 'failed')");
    };
    $reg  = function () use ($s): ChannelRegistry { return new ChannelRegistry($s->store()->getPdo(), $s->store()); };
    $nLeads = function () use ($s): int { return count((array)($s->store()->load('leads.json') ?? [])); };
    $reply = function (string $who, int $convId, string $text) use ($s): array {
        $r = $s->api($who, 'POST', 'wa_send_reply', ['conversation_id' => $convId, 'message' => $text]);
        return ['http' => $r[0], 'channel' => $r[2]['data']['channel'] ?? null];
    };
    $fu = new FollowUpService($pdo);
    $quietConv = function (string $phone, string $channel, int $hours = 30) use ($s, $q): array {
        $c = (new ConversationService($s->data, $s->store()->getPdo()))->ensureConversation($phone, $channel);
        $s->store()->getPdo()->prepare("UPDATE wa_conversations SET last_customer_at = datetime('now', ?), status = 'active' WHERE id = ?")
            ->execute(['-' . $hours . ' hours', (int)$c['id']]);
        return $q('SELECT * FROM wa_conversations WHERE id = ?', [(int)$c['id']])[0];
    };
    $approve = function (string $phone, string $channel, string $body, string $by = 'pilot safety test') use ($fu, $quietConv): int {
        $o = $fu->open($quietConv($phone, $channel, 72));
        $d = $fu->draft((int)$o['id'], ['verdict' => 'SEND', 'message' => $body, 'reason' => 'test']);
        $fu->approve((int)$d['id'], $by);
        return (int)$o['id'];
    };
    $due = function (string $phone, string $channel) use ($fu, $quietConv, $pdo): int {
        $o = $fu->open($quietConv($phone, $channel, 30));
        $pdo->prepare("UPDATE followups SET due_at = datetime('now', '-1 minutes') WHERE id = ?")->execute([(int)$o['id']]);
        return (int)$o['id'];
    };
    $fuRow = function (int $id) use ($q): array { return $q('SELECT * FROM followups WHERE id = ?', [$id])[0] ?? []; };
    $fuEvents = function (int $id) use ($q): array {
        return array_map(function ($r) { return [$r['event'], $r['actor']]; }, $q('SELECT event, actor FROM followup_events WHERE followup_id = ? ORDER BY id', [$id]));
    };
    $draftStatus = function (int $id) use ($q): ?string { return $q('SELECT status FROM followup_drafts WHERE followup_id = ? ORDER BY id DESC LIMIT 1', [$id])[0]['status'] ?? null; };
    // A cron whose model call can never leave the box: the provider address is a closed local port.
    $cron = function (string $script) use ($s): array {
        putenv('https_proxy=http://127.0.0.1:9'); putenv('HTTPS_PROXY=http://127.0.0.1:9');
        try { return $s->run($script); } finally { putenv('https_proxy'); putenv('HTTPS_PROXY'); }
    };
    $toInternal = function (array $calls): array {
        return array_values(array_filter($calls, function ($c) { return in_array((string)$c['number'], PS_INTERNAL, true); }));
    };
    // A message from $phone on $instance: was it queued for the assistant, filed, stored?
    $quiet = function (string $phone, string $text, string $instance, string $channel, string $id = '') use (&$in, $nEvents, $conv, $q): array {
        $e0 = $nEvents('ai.reply');
        $w  = $in($phone, $text, $instance, $id);
        $c  = $conv($phone, $channel);
        return ['in' => [$w[0], $w[1]], 'queued' => $nEvents('ai.reply') - $e0, 'category' => $c['category'] ?? null,
                'stored' => (int)($q('SELECT COUNT(*) c FROM wa_messages WHERE conversation_id = ? AND body = ?', [(int)($c['id'] ?? 0), $text])[0]['c'] ?? 0)];
    };
    $leadMarker = function (string $name, string $where): string {
        return '<<LEAD{"customer_name":"' . $name . '","requirement":"Starlink for a shop","location":"' . $where . '",'
             . '"customer_type":"business","quote_requested":true,"ai_summary":"A shop asked for a quotation"}>>';
    };
    $leadByPhone = function (string $tail) use ($s): array {
        foreach (($s->store()->load('leads.json') ?? []) as $l) if (substr((string)($l['phone'] ?? ''), -6) === $tail) return (array)$l;
        return [];
    };
    $evRow = function (int $convId) use ($q): array {
        return $q("SELECT status, error FROM events WHERE event_type = 'ai.reply' AND entity_id = ? ORDER BY id DESC LIMIT 1", [$convId])[0] ?? [];
    };
    // A variant of one of the plugin's crons, written beside it in THIS sandbox's copy: the anchors must each be found once.
    $variant = function (string $cron, string $as, array $swap) use ($s): int {
        $src = (string)file_get_contents($s->plug . '/' . $cron);
        foreach ($swap as $old => $new) {
            if (substr_count($src, $old) !== 1) return 0;
            $src = str_replace($old, $new, $src);
        }
        file_put_contents($s->plug . '/' . $as, $src);
        return 1;
    };

    // ── The people ───────────────────────────────────────────────────────────────────────────────────────────────
    $s->staff('admin', ['name' => 'Sandbox Admin', 'email' => 'admin@example.test', 'role' => 'admin', 'is_admin' => true]);
    $A = $s->staff('alpha', ['name' => 'Alpha Seller', 'email' => 'alpha@example.test', 'role' => 'sales', 'phone' => '0700000201']);
    $B = $s->staff('bravo', ['name' => 'Bravo Seller', 'email' => 'bravo@example.test', 'role' => 'sales', 'phone' => '0700000202']);
    $M = $s->staff('mike', ['name' => 'Mike Colleague', 'email' => 'mike@example.test', 'role' => 'sales', 'phone' => '0700000204']);
    $s->login('admin', 'admin@example.test', 'sj-password-1');
    $f['ids'] = ['A' => $A, 'B' => $B, 'M' => $M];
    $live();

    // ── Setup: the card, in the runbook's order (docs/66 §1–§5) ─────────────────────────────────────────────────────
    $qs   = 'page=dashboard&tab=wa_ai_setup';
    $post = function (array $fields) use ($s, $qs): string {
        $r = $s->form('admin', $fields, $qs, $qs);
        return preg_match('#<div class="wa-msg (wa-good|wa-bad)">(.*?)</div>#s', $r[1], $m)
            ? ($m[1] === 'wa-good' ? 'ok: ' : 'no: ') . html_entity_decode(trim($m[2]), ENT_QUOTES) : 'none: ' . $r[0];
    };
    $chan = function (string $id) use ($q): array { return $q('SELECT * FROM wa_channels WHERE channel_id = ?', [$id])[0] ?? []; };
    $setup = [];
    $setup['add'] = [$post(['wa_action' => 'sn_add', 'instance' => 'ps-alpha', 'owner_staff_id' => $A, 'reason' => 'the pilot']),
                     $post(['wa_action' => 'sn_add', 'instance' => 'ps-bravo', 'owner_staff_id' => $B, 'reason' => 'the second number'])];
    foreach (['sales-001', 'sales-002'] as $id) {
        $setup['verify'][$id] = $post(['wa_action' => 'sn_verify', 'channel_id' => $id]);
        $post(['wa_action' => 'sn_webhook', 'channel_id' => $id]);
    }
    // The registry cannot go on before the department numbers are verified, nor a salesperson number be switched on.
    $sc = $s->run('tools/set_config.php', ['--key', 'multi_number_channels_enabled', '--value', '1']);
    $kc = json_decode((string)@file_get_contents($s->data . '/kyc_config.json'), true) ?: [];
    $setup['setcfg_refused'] = [$sc[0], strpos($sc[1], 'Not yet: the department numbers are not all verified (sales, support)') !== false,
                                !array_key_exists('multi_number_channels_enabled', $kc)];
    $flag(true);
    $setup['on_no_departments'] = [$post(['wa_action' => 'sn_status', 'channel_id' => 'sales-001', 'status' => 'active']), $chan('sales-001')['status'] ?? null];
    $flag(false);
    $setup['in_before'] = $in('256771910000', 'Hello before anything', 'ps-alpha');
    $setup['verify_departments'] = [$post(['wa_action' => 'sn_verify_department', 'channel_id' => 'sales']),
                                    $post(['wa_action' => 'sn_verify_department', 'channel_id' => 'support']),
                                    $post(['wa_action' => 'sn_verify_department', 'channel_id' => 'account'])];
    $setup['dept_rows'] = [$chan('sales')['business_number'] ?? null, $chan('support')['business_number'] ?? null, $chan('account')['business_number'] ?? null];
    $sc = $s->run('tools/set_config.php', ['--key', 'multi_number_channels_enabled', '--value', '1']);
    $setup['setcfg_ok'] = [$sc[0], strpos($sc[1], 'multi_number_channels_enabled = 1') !== false];
    $s->run('tools/set_config.php', ['--key', 'multi_number_channels_enabled', '--clear']);   // the test sets it below, in one place
    $flag(true);
    $setCfg([OwnedNumberHold::FLAG => '1']);
    $setup['on'] = [$post(['wa_action' => 'sn_status', 'channel_id' => 'sales-001', 'status' => 'active', 'reason' => 'the pilot goes live']),
                    $post(['wa_action' => 'sn_status', 'channel_id' => 'sales-002', 'status' => 'active', 'reason' => 'the second number']),
                    $chan('sales-001')['status'] ?? null, $chan('sales-002')['status'] ?? null];
    $pol = AutomationPolicy::forInstall($cfgNow, $s->data, $pdo, $s->store());
    $setup['policy'] = [$pol->active(), $pol->numbersComplete(), $pol->refusal('sales-001', '256771910001'), $pol->refusal('sales-002', '256771910001'),
                        $pol->refusal('sales', '256771910001')];
    // The alert number is a person on DishNet's side on a salesperson's number only — never on a department, a
    // notification thread or a website chat.
    $setup['people_scope'] = [$pol->senderClass('sales-001', '256700000999'), $pol->senderClass('accounts', '256700000999'),
                              $pol->senderClass('web', '256700000999'), $pol->senderClass('sales', '256700000999')];
    $setup['channels_tool'] = strpos($s->run('tools/channels.php')[1], 'department numbers: all verified for their instances') !== false;
    $f['setup'] = $setup;
    // Each card verification records what Evolution reports (a layer of its own, proved in 'reported'). Every other part
    // proves the registry's and the configuration's numbers ALONE, so a weakened copy of either cannot hide behind it.
    $noReport = function () use ($s): void { $s->store()->save(InternalNumbers::REPORTED_FILE, []); };
    $noReport();

    // ── 1, 2, 16. Each number answers from itself ───────────────────────────────────────────────────────────────
    if ($want('replies')) {
        $in('256771910001', 'Hello, I need internet for my shop', 'ps-alpha');
        $n0 = $nText(); $p0 = $nPresence();
        $k  = $runWorker('Thank you, we can help. PS-T1');
        $f['t1'] = ['replies' => ps_pairs($textsSince($n0)), 'role' => ($k['brain']->contexts[0] ?? [])['channel'] ?? null,
                    'typing' => ps_pairs($presenceSince($p0))];
        $in('256771910002', 'Hello, do you install in Gulu?', 'ps-bravo');
        $n0 = $nText();
        $runWorker('Yes, we install in Gulu. PS-T2');
        $f['t2'] = ['replies' => ps_pairs($textsSince($n0))];
        // A lead on the sales desk: its channel, and no business number — the department's number is recorded since
        // 5.18.90 but a department's lead stays as it was.
        $in('256771910030', 'Hello sales desk, I need a quotation', 'ps-sales');
        $runWorker('Thank you, a quotation is on its way. PS-DL ' . $leadMarker('PS Desk Customer', 'Mbale'));
        $l = $leadByPhone('910030');
        $f['dept_lead'] = ['found' => $l !== [], 'channel' => $l['channel_id'] ?? null,
                           'number' => array_key_exists('source_number', $l) ? $l['source_number'] : 'absent'];
        $in('256771910003', 'PS-T16-FIRST question on Alpha', 'ps-alpha');
        $n0 = $nText();
        $runWorker('PS-T16 answer one');
        $in('256771910003', 'PS-T16-SECOND question on Bravo', 'ps-bravo');
        $k2 = $runWorker('PS-T16 answer two');
        $j2 = json_encode($k2['brain']->contexts[0] ?? []);
        $f['t16'] = ['convs' => array_column($q("SELECT channel FROM wa_conversations WHERE phone = '256771910003' ORDER BY channel"), 'channel'),
                     'replies' => ps_pairs($textsSince($n0)),
                     'isolated' => $j2 !== '[]' && strpos($j2, 'PS-T16-FIRST') === false && strpos($j2, 'PS-T16 answer one') === false];
        $settle();
    }

    // ── 3. The human pause still works for a customer ──────────────────────────────────────────────────────────
    if ($want('cooldown')) {
        $c = '256771910004';
        $in($c, 'Hello, a question for Alpha', 'ps-alpha');
        $runWorker('PS-T3 first answer');
        $handset($c, 'Alpha here, I will call you. PS-T3-HANDSET', 'ps-alpha');
        $state = $conv($c, 'sales-001')['state'] ?? null;
        $in($c, 'Thank you, what time?', 'ps-alpha');
        $n0 = $nText();
        $kp = $runWorker('This waits for Alpha. PS-T3-PARKED');
        $parked = count($textsSince($n0));
        $cid = (int)($conv($c, 'sales-001')['id'] ?? 0);
        $pdo->prepare("UPDATE wa_conversations SET last_human_reply_at = datetime('now', '-6 minutes') WHERE id = ?")->execute([$cid]);
        $pdo->prepare("UPDATE wa_messages SET sent_at = datetime('now', '-6 minutes') WHERE conversation_id = ? AND agent_name = 'Team'")->execute([$cid]);
        $pdo->prepare("UPDATE events SET next_retry_at = datetime('now', '-1 minutes') WHERE event_type = 'ai.reply' AND entity_id = ? AND status = 'pending'")->execute([$cid]);
        $n1 = $nText();
        $runWorker('The pause is over. PS-T3-AFTER');
        $f['t3'] = ['state' => $state, 'parked_sends' => $parked, 'parked_brain' => count($kp['brain']->contexts),
                    'after' => ps_pairs($textsSince($n1))];
        $settle();
    }

    // ── 4–7, 18–20. DishNet's own numbers are never answered ───────────────────────────────────────────────────
    if ($want('internal')) {
        $n0 = $nText(); $p0 = $nPresence(); $l0 = $nLeads();
        $f['t4'] = $quiet('256700000901', 'Hello from Alpha\'s number', 'ps-bravo', 'sales-002');
        // The shared-id case: Alpha's handset sends to Bravo's number; Bravo's instance reports the SAME id.
        $sid = 'PS-SHARED-' . bin2hex(random_bytes(4));
        $f['t4_shared'] = ['handset' => $handset('256700000902', 'PS-T4 shared id from Alpha', 'ps-alpha', $sid),
                           'inbound' => $quiet('256700000901', 'PS-T4 shared id from Alpha', 'ps-bravo', 'sales-002', $sid)];
        // ... and the other way round: the inbound first, so the id is new and the guard alone decides.
        $sid2 = 'PS-SHARED2-' . bin2hex(random_bytes(4));
        $f['t4_shared_first'] = ['inbound' => $quiet('256700000901', 'PS-T4 shared id, the inbound first', 'ps-bravo', 'sales-002', $sid2),
                                 'handset' => $handset('256700000902', 'PS-T4 shared id, the inbound first', 'ps-alpha', $sid2)];
        $f['t5'] = $quiet('256700000902', 'Hello from Bravo\'s number', 'ps-sales', 'sales');
        $f['t6'] = $quiet('256700000903', 'Hello from the support number', 'ps-alpha', 'sales-001');
        $f['t7'] = $quiet('256700000903', 'Hello from the account number (shared with support)', 'ps-bravo', 'sales-002');
        // Account on an instance of its own: verified on the card, then its number is refused too.
        $setCfg(['evo_instance_account' => 'ps-acct']);
        $f['t7_own'] = ['verify' => $post(['wa_action' => 'sn_verify_department', 'channel_id' => 'account'])];
        $noReport();
        $f['t7_own']['in'] = $quiet('256700000904', 'Hello from the account number on its own', 'ps-alpha', 'sales-001');
        $setCfg(['evo_instance_account' => 'ps-supacc']);
        $f['t18'] = ['owner' => $quiet('256700000202', 'I need a quotation for my shop in Gulu, please', 'ps-alpha', 'sales-001'),
                     'alert' => $quiet('256700000999', 'Is anyone handling this?', 'ps-alpha', 'sales-001'),
                     'colleague' => $quiet('256700000204', 'Testing the line as a colleague', 'ps-alpha', 'sales-001'),
                     'owner_to_department' => $quiet('256700000202', 'A question for the sales desk', 'ps-sales', 'sales')];
        $f['t19'] = $quiet('256700000901', 'Hello, I need internet for my shop, how much is the Standard kit and can you send a quotation?', 'ps-bravo', 'sales-002');
        // 20. After the pause: the chat was the team's 10 minutes ago (the pause is 5), and the line writes again.
        $cid = (int)($conv('256700000901', 'sales-002')['id'] ?? 0);
        $pdo->prepare("UPDATE wa_conversations SET state = 'human_active', last_human_reply_at = datetime('now', '-10 minutes') WHERE id = ?")->execute([$cid]);
        $f['t20'] = $quiet('256700000901', 'PS-T20 after the pause', 'ps-bravo', 'sales-002');
        // A line switched off is still a DishNet number.
        $post(['wa_action' => 'sn_status', 'channel_id' => 'sales-002', 'status' => 'disabled', 'reason' => 'test']);
        $f['t_disabled_line'] = $quiet('256700000902', 'Hello from a switched-off line', 'ps-alpha', 'sales-001');
        $post(['wa_action' => 'sn_status', 'channel_id' => 'sales-002', 'status' => 'active', 'reason' => 'test over']);
        // Everything above the colleague's (answered: Mike owns no number) is answered by nobody, ever.
        $settle();
        // An event queued before the fix — or by any other route — for a DishNet line, after the pause: still nothing.
        $bus = new EventBus($pdo);
        $x0 = $nEvents('wa.escalation'); $n1 = $nText();
        $evId = $bus->emit('ai.reply', 'conversation', $cid, ['channel' => 'sales-002', 'whatsapp_instance' => 'ps-bravo',
            'customer_phone' => '256700000901', 'message' => 'PS-T20 queued before the fix', 'push_name' => 'PS',
            'wa_message_id' => 'PS-Q-' . bin2hex(random_bytes(4)), 'remote_jid' => '256700000901@s.whatsapp.net',
            'received_at' => gmdate('c'), 'location' => null], 3, 'test');
        $k = $runWorker('This must never be sent. PS-T20-QUEUED ' . $leadMarker('PS Never A Lead', 'Gulu'));
        $ev = $q('SELECT status FROM events WHERE id = ?', [(int)$evId])[0] ?? [];
        $f['t20_queued'] = ['status' => $ev['status'] ?? null, 'brain' => count($k['brain']->contexts), 'sends' => count($textsSince($n1)),
                            'escalations' => $nEvents('wa.escalation') - $x0, 'worker_guard' => strpos($k['log'], 'a DishNet number (dishnet_line) on channel sales-002') !== false];
        // The same line writing while its number is PAUSED: the reply route would page a person — the worker's own check
        // must decide first, and silently.
        $post(['wa_action' => 'sn_status', 'channel_id' => 'sales-002', 'status' => 'paused', 'reason' => 'test']);
        $x0 = $nEvents('wa.escalation'); $n1 = $nText();
        $bus->emit('ai.reply', 'conversation', $cid, ['channel' => 'sales-002', 'whatsapp_instance' => 'ps-bravo',
            'customer_phone' => '256700000901', 'message' => 'PS-T20 on a paused number', 'push_name' => 'PS',
            'wa_message_id' => 'PS-QP-' . bin2hex(random_bytes(4)), 'remote_jid' => '256700000901@s.whatsapp.net',
            'received_at' => gmdate('c'), 'location' => null], 3, 'test');
        $k = $runWorker('This must never be sent. PS-T20-PAUSED');
        $f['t20_paused'] = ['brain' => count($k['brain']->contexts), 'sends' => count($textsSince($n1)), 'escalations' => $nEvents('wa.escalation') - $x0];
        $post(['wa_action' => 'sn_status', 'channel_id' => 'sales-002', 'status' => 'active', 'reason' => 'test over']);
        $settle();
        // Numbers saved through Settings live in the store, not in the configuration files: still DishNet's own.
        $s->store()->save('kyc_config.json', array_merge($cfgNow, ['wa_accounts_number' => '256700000981', 'whatsapp_admin_phone' => '0700000982']));
        $f['stored_cfg'] = ['line' => $quiet('256700000981', 'PS from a number saved in Settings only', 'ps-alpha', 'sales-001'),
                            'admin' => $quiet('256700000982', 'PS from the admin phone saved in Settings only', 'ps-alpha', 'sales-001')];
        $setCfg([]);   // the store as the configuration again
        $settle();
        $f['internal_totals'] = ['to_internal' => ps_pairs($toInternal($textsSince($n0))), 'typing_to_internal' => ps_pairs($toInternal($presenceSince($p0))),
                                 'leads' => $nLeads() - $l0];
    }

    // ── The alert loop's seed: Bravo's phone of record IS his line ──────────────────────────────────────────────
    if ($want('seed')) {
        $s->update($B, ['phone' => '0700000902']);
        $in('256771910005', 'Can I talk to a person, please?', 'ps-bravo');
        $n0 = $nText();
        $runWorker('<<ESCALATE customer asked for a person>>');
        $t = $textsSince($n0);
        $alert = array_values(array_filter($t, function ($c) { return (string)$c['number'] === '256700000902'; }));
        // The alert lands on Bravo's own line, from the sales department number.
        $e0 = $nEvents('ai.reply'); $n1 = $nText();
        $w = $in('256700000900', (string)($alert[0]['text'] ?? 'the AI needs a human for this chat'), 'ps-bravo');
        $runWorker('This must never be sent. PS-SEED');
        $f['seed'] = ['alert' => ps_pairs($alert), 'in' => [$w[0], $w[1]], 'queued' => $nEvents('ai.reply') - $e0,
                      'answered' => ps_pairs($textsSince($n1)), 'category' => $conv('256700000900', 'sales-002')['category'] ?? null];
        $s->update($B, ['phone' => '0700000202']);
        $settle();
    }

    // ── 8, 9, 22. The assistant off: nothing automated at all ──────────────────────────────────────────────────
    if ($want('assistant')) {
        $in('256771910006', 'A question just before the assistant goes off', 'ps-alpha');   // queued while on
        $f['t8_off'] = [$post(['wa_action' => 'sn_ai', 'channel_id' => 'sales-001', 'value' => '0']), (int)($chan('sales-001')['ai_enabled'] ?? 9)];
        $e0 = $nEvents('ai.reply');
        $w  = $in('256771910007', 'Is the assistant there?', 'ps-alpha');
        $queued = $nEvents('ai.reply') - $e0;
        $x0 = $nEvents('wa.escalation'); $n0 = $nText(); $p0 = $nPresence();
        $k = $runWorker('This must never be sent. PS-T8');
        $f['t8'] = ['in' => [$w[0], $w[1]], 'queued' => $queued, 'sends' => count($textsSince($n0)), 'typing' => count($presenceSince($p0)),
                    'brain' => count($k['brain']->contexts), 'escalations' => $nEvents('wa.escalation') - $x0,
                    'stored' => (int)($q("SELECT COUNT(*) c FROM wa_messages WHERE body = 'Is the assistant there?'")[0]['c'] ?? 0)];
        $settle();
        // The scan needs no sending window.
        $quietConv('256771910008', 'sales-001', 30);
        $s->run('cron/followup_scan.php');
        $f['t9_scan'] = count($q("SELECT f.id FROM followups f JOIN wa_conversations c ON c.id = f.conversation_id WHERE c.phone = '256771910008'"));
        if ($zone === '') {
            $f['t9'] = 'no open zone';
        } else {
            $g = $approve('256771910009', 'sales-001', 'PS-T9 must never be sent');
            $n0 = $nText();
            $s->run('cron/followup_send.php');
            $f['t9'] = ['sent' => count($textsSince($n0)), 'closed' => $fuRow($g)['close_reason'] ?? null,
                        'events' => $fuEvents($g),
                        'detail' => $q("SELECT detail FROM followup_events WHERE followup_id = ? AND event = 'closed'", [$g])[0]['detail'] ?? null];
            // The refusal at the send itself: a sender with its own query and pre-check out of the way leaves only the
            // central guard — whose final refusal must close the follow-up, never retry it.
            $ok = $variant('cron/followup_send.php', 'cron/followup_send_attime.php', [
                "[\$heldSql, \$heldArgs] = \$evo->automationPolicy()->sqlHeld('f.channel');" => "[\$heldSql, \$heldArgs] = ['', []];",
                "    \$why = \$evo->automationPolicy()->refusal(\$chan, \$phone);" => "    \$why = '';"]);
            $ga = $approve('256771910035', 'sales-001', 'PS-ATSEND must never be sent');
            $n0 = $nText();
            $s->run('cron/followup_send_attime.php');
            $f['t9_atsend'] = ['variant' => $ok, 'sent' => count($textsSince($n0)), 'closed' => $fuRow($ga)['close_reason'] ?? null,
                               'detail' => $q("SELECT detail FROM followup_events WHERE followup_id = ? AND event = 'closed'", [$ga])[0]['detail'] ?? null];
        }
        // 22. Due, followup_auto_send on: closed before any model call — no draft, nothing approved, nothing sent.
        $g = $due('256771910010', 'sales-001');
        $cron('cron/followup_run.php');
        $f['t22'] = ['closed' => $fuRow($g)['close_reason'] ?? null, 'events' => $fuEvents($g),
                     'drafts' => (int)($q('SELECT COUNT(*) c FROM followup_drafts WHERE followup_id = ?', [$g])[0]['c'] ?? -1)];
        $f['t8_on'] = [$post(['wa_action' => 'sn_ai', 'channel_id' => 'sales-001', 'value' => '1']), (int)($chan('sales-001')['ai_enabled'] ?? 9)];
    }

    // ── 10–12, 21, 23. Follow-ups: each from its own number, never to a DishNet number ─────────────────────────
    if ($want('followups')) {
        if ($zone === '') {
            $f['t10'] = 'no open zone';
        } else {
            $g10 = $approve('256771910011', 'sales-001', 'PS-T10 following up from Alpha');
            $g11 = $approve('256771910012', 'sales-002', 'PS-T11 following up from Bravo');
            $g12 = $approve('256771910013', 'sales', 'PS-T12 following up from the sales desk');
            $g23 = $approve('256771910014', 'sales-001', 'PS-T23 the automatic one', 'auto');
            // 21. To DishNet numbers: a line (on a salesperson's number and on a department's), and an owner.
            // (Fresh conversations — never filed 'staff' by an earlier part — so the recipient rule itself is what decides.)
            $g21a = $approve('256700000901', 'sales-001', 'PS-T21 to a DishNet line, from Alpha');
            $g21b = $approve('256700000901', 'sales', 'PS-T21 to Alpha\'s line, from the sales desk');
            $g21c = $approve('256700000201', 'sales-002', 'PS-T21 to Alpha\'s phone, from Bravo');
            $g21d = $approve('256700000202', 'sales', 'PS-T21 to Bravo\'s phone, from the sales desk');
            $n0 = $nText();
            $s->run('cron/followup_send.php');
            $t = $textsSince($n0);
            $by = function (string $needle) use ($t): array {
                return ps_pairs(array_values(array_filter($t, function ($c) use ($needle) { return strpos((string)$c['text'], $needle) !== false; })));
            };
            $f['t10'] = ['alpha' => $by('PS-T10'), 'bravo' => $by('PS-T11'), 'desk' => $by('PS-T12'), 'auto' => $by('PS-T23'),
                         'auto_record' => $q('SELECT channel, decided_by FROM followup_sends WHERE followup_id = ?', [$g23])];
            $f['t21'] = ['sent' => [$by('to a DishNet line, from Alpha'), $by('to Alpha\'s line'), $by('to Alpha\'s phone, from Bravo'), $by('to Bravo\'s phone, from the sales desk')],
                         'closed' => [$fuRow($g21a)['close_reason'] ?? null, $fuRow($g21b)['close_reason'] ?? null, $fuRow($g21c)['close_reason'] ?? null, $fuRow($g21d)['close_reason'] ?? null]];
            // A paused number: the follow-up waits, approved; it is never closed and never sent from another number.
            $post(['wa_action' => 'sn_status', 'channel_id' => 'sales-002', 'status' => 'paused', 'reason' => 'test']);
            $gp = $approve('256771910015', 'sales-002', 'PS-PAUSED waits');
            $n1 = $nText();
            $s->run('cron/followup_send.php');
            $f['paused_send'] = ['sent' => count($textsSince($n1)), 'closed' => $fuRow($gp)['close_reason'] ?? null, 'draft' => $draftStatus($gp)];
            $post(['wa_action' => 'sn_status', 'channel_id' => 'sales-002', 'status' => 'active', 'reason' => 'test over']);
        }
        // The scan opens nothing on a channel the registry does not route (here a website chat), and still opens a
        // department's — so followup_run never closes, and the next scan never reopens, the same rows forever.
        $cw = $quietConv('256771910032', 'web', 30);
        $cd = $quietConv('256771910031', 'support', 30);
        $s->run('cron/followup_scan.php');
        $f['scan_allow'] = ['web' => count($q('SELECT id FROM followups WHERE conversation_id = ?', [(int)$cw['id']])),
                            'department' => count($q('SELECT id FROM followups WHERE conversation_id = ? AND closed_at IS NULL', [(int)$cd['id']]))];
        // A chat filed 'staff' takes none of the scan's places: with one place, the customer behind it still opens.
        $setCfg(['followup_scan_limit' => 1]);
        $cs = $quietConv('256771910034', 'sales', 30);
        $cc2 = $quietConv('256771910033', 'sales', 30);
        $pdo->prepare("UPDATE wa_conversations SET category = 'staff', last_customer_at = datetime('now', '-1470 minutes') WHERE id = ?")->execute([(int)$cs['id']]);
        $pdo->prepare("UPDATE wa_conversations SET last_customer_at = datetime('now', '-1500 minutes') WHERE id = ?")->execute([(int)$cc2['id']]);
        $s->run('cron/followup_scan.php');
        $f['scan_staff'] = ['customer' => count($q('SELECT id FROM followups WHERE conversation_id = ? AND closed_at IS NULL', [(int)$cc2['id']])),
                            'staff' => count($q('SELECT id FROM followups WHERE conversation_id = ?', [(int)$cs['id']]))];
        $setCfg(['followup_scan_limit' => null]);
        // 21 at the scan: a quiet chat with the sales department's line on Alpha's number, filed before the fix (no category).
        $cInt = $quietConv('256700000900', 'sales-001', 30);
        $s->run('cron/followup_scan.php');
        $f['t21_scan'] = ['opened' => count($q('SELECT id FROM followups WHERE conversation_id = ? AND closed_at IS NULL', [(int)$cInt['id']])),
                          'logged' => (int)($q("SELECT COUNT(*) c FROM followup_events WHERE conversation_id = ? AND detail LIKE 'a DishNet number%'", [(int)$cInt['id']])[0]['c'] ?? 0)];
        // 23 at the run: a valid customer passes the gate and reaches the evaluation (the model is unreachable here).
        $g = $due('256771910016', 'sales-001');
        // The paused number at the run: put off, not closed.
        $post(['wa_action' => 'sn_status', 'channel_id' => 'sales-002', 'status' => 'paused', 'reason' => 'test']);
        $gpr = $due('256771910017', 'sales-002');
        // Both the first the run takes (it takes five, oldest due first).
        $pdo->prepare("UPDATE followups SET due_at = datetime('now', '-3 days') WHERE id = ?")->execute([$gpr]);
        $pdo->prepare("UPDATE followups SET due_at = datetime('now', '-2 days') WHERE id = ?")->execute([$g]);
        $before = $fuRow($gpr)['due_at'] ?? '';
        $cron('cron/followup_run.php');
        $f['t23_run'] = ['events' => $fuEvents($g), 'closed' => $fuRow($g)['close_reason'] ?? null];
        $f['paused_run'] = ['closed' => $fuRow($gpr)['close_reason'] ?? null, 'moved' => (string)($fuRow($gpr)['due_at'] ?? '') > $before,
                            'events' => $fuEvents($gpr)];
        $post(['wa_action' => 'sn_status', 'channel_id' => 'sales-002', 'status' => 'active', 'reason' => 'test over']);
        // The run asks again before it approves on its own: a model that says SEND (a stub, in this sandbox's copy only),
        // and a number whose assistant goes off while it "thinks". A control on another number is approved as usual.
        if ($zone !== '') {
            $ok = $variant('cron/followup_run.php', 'cron/followup_run_stub.php', [
                "    \$verdict = \$evaluator->evaluate(\$fu, \$thread, \$level, \$account);" =>
                "    \$verdict = in_array((string)\$fu['phone'], ['256771910043', '256771910044'], true)\n"
                . "        ? ['verdict' => 'SEND', 'message' => 'PS-STUB a short follow-up', 'reason' => 'test stub', 'sales_stage' => 'unknown',"
                . " 'product' => null, 'objection' => null, 'next_due_hours' => 24]\n"
                . "        : \$evaluator->evaluate(\$fu, \$thread, \$level, \$account);\n"
                . "    if ((string)\$fu['phone'] === '256771910044') \$pdo->exec(\"UPDATE wa_channels SET ai_enabled = 0 WHERE channel_id = 'sales-001'\");"]);
            $gc = $due('256771910043', 'sales-002');
            $gt = $due('256771910044', 'sales-001');
            $pdo->prepare("UPDATE followups SET due_at = datetime('now', '-9 days') WHERE id = ?")->execute([$gc]);
            $pdo->prepare("UPDATE followups SET due_at = datetime('now', '-8 days') WHERE id = ?")->execute([$gt]);
            $cron('cron/followup_run_stub.php');
            $dr = function (int $id) use ($q): array { return $q('SELECT status, decided_by FROM followup_drafts WHERE followup_id = ? ORDER BY id DESC LIMIT 1', [$id])[0] ?? []; };
            $f['run_recheck'] = ['variant' => $ok, 'control' => $dr($gc), 'target' => $dr($gt)];
            $pdo->exec("UPDATE wa_channels SET ai_enabled = 1 WHERE channel_id = 'sales-001'");
        } else {
            $f['run_recheck'] = 'no open zone';
        }
    }

    // ── 13–15, 24, 25. Refused, and never sent from another number ─────────────────────────────────────────────
    if ($want('refusals')) {
        // 13. Bravo's number switched off.
        $in('256771910018', 'A message just before the switch-off', 'ps-bravo');
        $post(['wa_action' => 'sn_status', 'channel_id' => 'sales-002', 'status' => 'disabled', 'reason' => 'test']);
        $w = $in('256771910019', 'Anyone on this number?', 'ps-bravo');
        $x0 = $nEvents('wa.escalation'); $n0 = $nText();
        $runWorker('This must never be sent. PS-T13');
        $t = $textsSince($n0);
        $f['t13'] = ['in' => [$w[0], $w[1]], 'customer' => ps_pairs(array_values(array_filter($t, function ($c) { return (string)$c['number'] === '256771910018'; }))),
                     'escalations' => $nEvents('wa.escalation') - $x0];
        $post(['wa_action' => 'sn_status', 'channel_id' => 'sales-002', 'status' => 'active', 'reason' => 'test over']);
        $settle();
        // 14. Alpha's instance cannot send.
        $down('ps-alpha');
        $in('256771910020', 'Hello, anyone there?', 'ps-alpha');
        $n0 = $nText(); $fl0 = $nFailed();
        $runWorker('PS-T14 this reply cannot leave');
        $f['t14'] = ['sends' => ps_pairs($textsSince($n0)), 'tried' => array_map(function ($c) { return [$c['instance'], $c['number']]; }, $failedSince($fl0))];
        $up('ps-alpha');
        $settle();
        // 15. An instance nobody knows; a channel id nobody knows.
        $w = $in('256771910021', 'Hello?', 'ps-nobody');
        $evo = EvolutionApiService::forStore($cfgNow, $pdo, $s->data);
        $n0 = $nText();
        $r = $evo->sendText('sales-099', '256771910021', 'PS-T15 never', ContactOptOut::CLASS_REPLY);
        $f['t15'] = ['in' => [$w[0], $w[1]], 'stored' => $conv('256771910021', 'sales') === [] && $conv('256771910021', 'sales-001') === [],
                     'send' => [EvolutionApiService::policyRefused($r), EvolutionApiService::policyReason($r)], 'sent' => $nText() - $n0];
        // 24. The mapping changed after this process read it.
        $evo = EvolutionApiService::forStore($cfgNow, $pdo, $s->data);
        $reg()->setInstance('sales-001', 'ps-moved', 'pilot safety test', 'test: moved', EvolutionApiService::configInstanceMap($cfgNow));
        $n0 = $nText();
        $r = $evo->sendText('sales-001', '256771910022', 'PS-T24 never', ContactOptOut::CLASS_REPLY);
        $f['t24'] = ['send' => [EvolutionApiService::policyRefused($r), EvolutionApiService::policyReason($r)], 'sent' => $nText() - $n0];
        $reg()->setInstance('sales-001', 'ps-alpha', 'pilot safety test', 'test over', EvolutionApiService::configInstanceMap($cfgNow));
        // 25. Switched off between selection and send: in a service that read the registry before — proactive and reply.
        $evo = EvolutionApiService::forStore($cfgNow, $pdo, $s->data);
        $reg()->setStatus('sales-002', 'disabled', 'pilot safety test', 'test');
        $n0 = $nText(); $p0 = $nPresence();
        $r1 = $evo->sendText('sales-002', '256771910023', 'PS-T25 never', ContactOptOut::CLASS_PROACTIVE);
        $r2 = $evo->sendImage('sales-002', '256771910023', 'http://127.0.0.1:9/ps.jpg', 'PS-T25 never');
        $r3 = $evo->sendTyping('sales-002', '256771910023');
        $f['t25'] = ['text' => EvolutionApiService::policyReason($r1), 'image' => EvolutionApiService::policyReason($r2),
                     'typing' => EvolutionApiService::policyReason($r3), 'sent' => $nText() - $n0, 'typed' => $nPresence() - $p0];
        $reg()->setStatus('sales-002', 'active', 'pilot safety test', 'test over');
        // ... in the worker: the number goes off while the model is thinking.
        $in('256771910024', 'Hello Alpha, a question', 'ps-alpha');
        $x0 = $nEvents('wa.escalation'); $n0 = $nText();
        $k = $runWorker('PS-T25 this reply must not leave', function () use ($reg) {
            $reg()->setStatus('sales-001', 'disabled', 'pilot safety test', 'switched off mid-turn');
        });
        $cid = (int)($conv('256771910024', 'sales-001')['id'] ?? 0);
        $ev = $q("SELECT status FROM events WHERE event_type = 'ai.reply' AND entity_id = ? ORDER BY id DESC LIMIT 1", [$cid])[0] ?? [];
        $f['t25_worker'] = ['brain' => count($k['brain']->contexts), 'customer' => count(array_filter($textsSince($n0), function ($c) { return (string)$c['number'] === '256771910024'; })),
                            'event' => $ev['status'] ?? null, 'escalations' => $nEvents('wa.escalation') - $x0,
                            'refused_at_send' => strpos($k['log'], 'refused by the automated-send policy (channel_disabled)') !== false];
        $reg()->setStatus('sales-001', 'active', 'pilot safety test', 'test over');
        $settle();
        // ... and in the cron: approved, then switched off, then the sender runs.
        if ($zone !== '') {
            $g = $approve('256771910025', 'sales-002', 'PS-T25 approved before the switch-off');
            $reg()->setStatus('sales-002', 'disabled', 'pilot safety test', 'test');
            $n0 = $nText();
            $s->run('cron/followup_send.php');
            $f['t25_cron'] = ['sent' => count(array_filter($textsSince($n0), function ($c) { return (string)$c['number'] === '256771910025'; })),
                              'closed' => $fuRow($g)['close_reason'] ?? null];
            $reg()->setStatus('sales-002', 'active', 'pilot safety test', 'test over');
        } else {
            $f['t25_cron'] = 'no open zone';
        }
        // A number switched on without being verified (outside the card): it answers nobody, and a person is told.
        $reg()->create(['channel_id' => 'sales-003', 'evo_instance' => 'ps-charlie', 'display_name' => 'Sales three', 'role' => 'sales',
                        'owner_type' => 'staff', 'owner_staff_id' => $M, 'status' => 'active'], 'pilot safety test', 'unverified', EvolutionApiService::configInstanceMap($cfgNow));
        $w = $in('256771910026', 'Hello, number three?', 'ps-charlie');
        $x0 = $nEvents('wa.escalation'); $n0 = $nText();
        $k = $runWorker('This must never be sent. PS-UNVERIFIED');
        $f['unverified'] = ['in' => [$w[0], $w[1]], 'sends' => count(array_filter($textsSince($n0), function ($c) { return (string)$c['number'] === '256771910026'; })),
                            'brain' => count($k['brain']->contexts),
                            'escalations' => $nEvents('wa.escalation') - $x0];
        $reg()->setStatus('sales-003', 'retired', 'pilot safety test', 'test over');
        $settle();
        // The registry cannot be read at the moment the worker sends: nothing sent, nobody paged — the event is retried.
        $in('256771910045', 'Hello Alpha, one more question', 'ps-alpha');
        $x0 = $nEvents('wa.escalation'); $n0 = $nText();
        // In process: while the model "thinks", the worker's policy loses its database — its fresh read then fails.
        $w = new AiReplyWorker($s->store(), $cfgNow, 30, 10);
        $b = new PsBrain($cfgNow); $b->canned = 'PS-UNREADABLE this reply must not leave';
        $b->onReply = function () use ($w) {
            $re = new ReflectionProperty(AiReplyWorker::class, 'evo'); $re->setAccessible(true);
            $rp = new ReflectionProperty(AutomationPolicy::class, 'pdo'); $rp->setAccessible(true);
            $mem = new PDO('sqlite::memory:'); $mem->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $rp->setValue($re->getValue($w)->automationPolicy(), $mem);
        };
        $rb = new ReflectionProperty(AiReplyWorker::class, 'brain'); $rb->setAccessible(true); $rb->setValue($w, $b);
        ob_start(); try { $w->run(); } finally { ob_end_clean(); }
        $k = ['brain' => $b];
        $ev = $evRow((int)($conv('256771910045', 'sales-001')['id'] ?? 0));
        $f['unreadable_worker'] = ['brain' => count($k['brain']->contexts),
                                   'customer' => count(array_filter($textsSince($n0), function ($c) { return (string)$c['number'] === '256771910045'; })),
                                   'event' => $ev['status'] ?? null, 'retried' => strpos((string)($ev['error'] ?? ''), 'the channel registry could not be read') !== false,
                                   'escalations' => $nEvents('wa.escalation') - $x0];
        $settle();
        // ... and in the follow-up run: a salesperson's follow-up waits for it; a department's goes on as before.
        $gq = $due('256771910046', 'sales-001');
        $gd = $due('256771910047', 'support');
        $pdo->prepare("UPDATE followups SET due_at = datetime('now', '-12 days') WHERE id IN (?, ?)")->execute([$gq, $gd]);
        // (legacy_alter_table: the rename checks no view — one unrelated view in this schema does not compile.)
        $pdo->exec('PRAGMA legacy_alter_table = ON');
        $pdo->exec('ALTER TABLE wa_channel_log RENAME TO wa_channel_log_ps_hidden');
        $cron('cron/followup_run.php');
        $pdo->exec('ALTER TABLE wa_channel_log_ps_hidden RENAME TO wa_channel_log');
        $pdo->exec('PRAGMA legacy_alter_table = OFF');
        $said = function (int $id, string $needle) use ($q): bool {
            return (int)($q('SELECT COUNT(*) c FROM followup_events WHERE followup_id = ? AND detail LIKE ?', [$id, '%' . $needle . '%'])[0]['c'] ?? 0) > 0;
        };
        $f['unreadable_run'] = ['salesperson' => [$fuRow($gq)['close_reason'] ?? null, $said($gq, '(registry_unreadable)')],
                                'department' => [$said($gd, 'registry_unreadable'), in_array('evaluated', array_column($fuEvents($gd), 0), true),
                                                 $fuRow($gd)['close_reason'] ?? null]];
    }

    // ── 17. A person's Inbox reply: from the conversation's own number, the assistant on or off ────────────────
    if ($want('inbox')) {
        $c  = (new ConversationService($s->data, $pdo))->ensureConversation('256771910027', 'sales-001');
        $ci = (new ConversationService($s->data, $pdo))->ensureConversation('256700000902', 'sales-001');
        $n0 = $nText();
        $r1 = $reply('admin', (int)$c['id'], 'PS-T17 a person replies, assistant on');
        $post(['wa_action' => 'sn_ai', 'channel_id' => 'sales-001', 'value' => '0']);
        $r2 = $reply('admin', (int)$c['id'], 'PS-T17 a person replies, assistant off');
        $r3 = $reply('admin', (int)$ci['id'], 'PS-T17 a person replies to a DishNet number');
        $post(['wa_action' => 'sn_ai', 'channel_id' => 'sales-001', 'value' => '1']);
        $f['t17'] = ['http' => [$r1['http'], $r2['http'], $r3['http']], 'sent' => ps_pairs($textsSince($n0))];
    }

    // ── The watchdog: a chat left unanswered on purpose is never paged about ───────────────────────────────────
    if ($want('watchdog')) {
        $in('256700000901', 'PS-WATCH from Alpha\'s line', 'ps-bravo');
        $in('256771910028', 'PS-WATCH a waiting customer', 'ps-alpha');
        $settle();
        $ci = (int)($conv('256700000901', 'sales-002')['id'] ?? 0);
        $cc = (int)($conv('256771910028', 'sales-001')['id'] ?? 0);
        $pdo->prepare("UPDATE wa_conversations SET display_name = 'PS-INTERNAL-WATCH', last_customer_at = datetime('now', '-20 minutes') WHERE id = ?")->execute([$ci]);
        $pdo->prepare("UPDATE wa_conversations SET display_name = 'PS-CUSTOMER-WATCH', last_customer_at = datetime('now', '-20 minutes') WHERE id = ?")->execute([$cc]);
        $n0 = $nText();
        $s->run('cron/wa_watchdog.php');
        $t = $textsSince($n0);
        $has = function (string $needle) use ($t): int { return count(array_filter($t, function ($c) use ($needle) { return strpos((string)$c['text'], $needle) !== false; })); };
        $f['watchdog'] = ['customer' => $has('PS-CUSTOMER-WATCH'), 'internal' => $has('PS-INTERNAL-WATCH')];
    }

    // ── A department moved to another instance after its number was verified: unverified again ─────────────────
    if ($want('gaps')) {
        // The move made on the card, as an admin would: told to verify again. (The configuration below follows it.)
        $f['gaps_note'] = [$post(['wa_action' => 'assign_instance', 'channel' => 'sales', 'instance' => 'ps-sales-new'])];
        $setCfg(['evo_instance_sales' => 'ps-sales-new']);
        $e0 = $nEvents('ai.reply');
        $w = $in('256771910029', 'Hello Alpha, during the move', 'ps-alpha');
        $pol = AutomationPolicy::forInstall($cfgNow, $s->data, $pdo, $s->store());
        $f['gaps'] = ['in' => [$w[0], $w[1]], 'queued' => $nEvents('ai.reply') - $e0, 'gaps' => $pol->gaps(),
                      'refusal' => $pol->refusal('sales-001', '256771910029'), 'department' => $pol->refusal('support', '256771910029')];
        // The operator's signal: a process that finds the department numbers incomplete says so in its log, once.
        $warnLine = 'the channel registry is on but the department numbers are not all verified';
        $f['gaps_warn'] = substr_count($s->run('cron/followup_scan.php')[1], $warnLine);
        if ($zone !== '') {
            // Eleven follow-ups held on a salesperson's number take none of the sender's ten places: the desk's still goes.
            $held = [];
            for ($i = 0; $i < 11; $i++) $held[] = $approve('2567719105' . str_pad((string)$i, 2, '0', STR_PAD_LEFT), 'sales-001', 'PS-HELD ' . $i);
            $pdo->exec("UPDATE followup_drafts SET decided_at = datetime('now', '-1 hours') WHERE followup_id IN (" . implode(',', $held) . ')');
            $gd = $approve('256771910040', 'support', 'PS-DESK goes while the others wait');
            $n0 = $nText();
            $s->run('cron/followup_send.php');
            $t = $textsSince($n0);
            $open = (int)($q("SELECT COUNT(*) c FROM followups f JOIN followup_drafts d ON d.followup_id = f.id WHERE f.id IN ("
                             . implode(',', $held) . ") AND f.closed_at IS NULL AND d.status = 'approved'")[0]['c'] ?? 0);
            $f['gaps_send'] = ['desk' => ps_pairs(array_values(array_filter($t, function ($c) { return strpos((string)$c['text'], 'PS-DESK') !== false; }))),
                               'held_sent' => count(array_filter($t, function ($c) { return strpos((string)$c['text'], 'PS-HELD') !== false; })),
                               'held_open' => $open];
            // The sender's own hold, reached: with its held-channel query out of the way (this sandbox's copy only), a
            // draft refused for a reason that clears by itself stays approved — at the pre-check, and at the send itself.
            $okU = $variant('cron/followup_send.php', 'cron/followup_send_unheld.php', [
                "[\$heldSql, \$heldArgs] = \$evo->automationPolicy()->sqlHeld('f.channel');" => "[\$heldSql, \$heldArgs] = ['', []];"]);
            $okA = $variant('cron/followup_send.php', 'cron/followup_send_attime.php', [
                "[\$heldSql, \$heldArgs] = \$evo->automationPolicy()->sqlHeld('f.channel');" => "[\$heldSql, \$heldArgs] = ['', []];",
                "    \$why = \$evo->automationPolicy()->refusal(\$chan, \$phone);" => "    \$why = '';"]);
            $gu = $approve('256771910041', 'sales-001', 'PS-UNHELD waits');
            $gat = $approve('256771910042', 'sales-001', 'PS-ATSEND-HELD waits');
            $pdo->prepare("UPDATE followup_drafts SET decided_at = datetime('now', '-3 hours') WHERE followup_id IN (?, ?)")->execute([$gu, $gat]);
            $n0 = $nText();
            $s->run('cron/followup_send_unheld.php');
            $s->run('cron/followup_send_attime.php');
            $f['gaps_unheld'] = ['variants' => [$okU, $okA], 'sent' => count($textsSince($n0)),
                                 'pre' => [$fuRow($gu)['close_reason'] ?? null, $draftStatus($gu)],
                                 'at_send' => [$fuRow($gat)['close_reason'] ?? null, $draftStatus($gat)]];
        } else {
            $f['gaps_send'] = 'no open zone';
        }
        $f['gaps_note'][] = $post(['wa_action' => 'assign_instance', 'channel' => 'sales', 'instance' => 'ps-sales']);
        $setCfg(['evo_instance_sales' => 'ps-sales']);
        $settle();
        // The same move saved with the numbers form: told likewise — and not once it is back.
        $saveNumbers = function (string $sales) use ($post): string {
            return $post(['wa_action' => 'save_channels', 'instance_sales' => $sales, 'instance_support' => 'ps-supacc', 'instance_account' => 'ps-supacc']);
        };
        $f['gaps_save'] = [$saveNumbers('ps-sales-new'), $saveNumbers('ps-sales')];

        // A number held by a row that can no longer own it is released when the department is verified — never a dead end.
        // (a) sales moved onto support's instance: support is covered now, and gives its number up.
        $setCfg(['evo_instance_sales' => 'ps-supacc']);
        $f['release_a'] = ['verify' => $post(['wa_action' => 'sn_verify_department', 'channel_id' => 'sales']),
                           'rows' => [$chan('sales')['business_number'] ?? null, $chan('support')['business_number'] ?? null],
                           'gaps' => AutomationPolicy::forInstall($cfgNow, $s->data, $pdo, $s->store())->gaps(),
                           'trail' => (int)($q("SELECT COUNT(*) c FROM wa_channel_log WHERE channel_id = 'support' AND action = 'number' AND reason = 'released: now the sales number'")[0]['c'] ?? 0)];
        $setCfg(['evo_instance_sales' => 'ps-sales']);
        $f['release_a_back'] = [$post(['wa_action' => 'sn_verify_department', 'channel_id' => 'sales']),
                                $post(['wa_action' => 'sn_verify_department', 'channel_id' => 'support']),
                                AutomationPolicy::forInstall($cfgNow, $s->data, $pdo, $s->store())->gaps()];
        // (b) the support phone re-paired to a number a retired line once held.
        $reg()->create(['channel_id' => 'sales-009', 'evo_instance' => 'ps-retired', 'display_name' => 'Sales nine', 'role' => 'sales',
                        'owner_type' => 'staff', 'owner_staff_id' => $M, 'status' => 'disabled'], 'pilot safety test', 'to be retired', EvolutionApiService::configInstanceMap($cfgNow));
        $pdo->exec("UPDATE wa_channels SET business_number = '+256700000913' WHERE channel_id = 'sales-009'");
        $reg()->setStatus('sales-009', 'retired', 'pilot safety test', 'retired with its number');
        $live(['ps-supacc' => '256700000913@s.whatsapp.net']);
        $f['release_b'] = ['verify' => $post(['wa_action' => 'sn_verify_department', 'channel_id' => 'support']),
                           'rows' => [$chan('support')['business_number'] ?? null, $chan('sales-009')['business_number'] ?? null]];
        $live();
        $f['release_b_back'] = [$post(['wa_action' => 'sn_verify_department', 'channel_id' => 'support']),
                                AutomationPolicy::forInstall($cfgNow, $s->data, $pdo, $s->store())->gaps()];
        // (c, d) …and nothing else gives one up: a department still taking its instance's inbound under that number, or a
        // salesperson's number, refuses the verification, and both rows keep their numbers.
        $live(['ps-sales' => '256700000903@s.whatsapp.net']);
        $f['release_c'] = [$post(['wa_action' => 'sn_verify_department', 'channel_id' => 'sales']),
                           $chan('sales')['business_number'] ?? null, $chan('support')['business_number'] ?? null];
        $live(['ps-sales' => '256700000901@s.whatsapp.net']);
        $f['release_d'] = [$post(['wa_action' => 'sn_verify_department', 'channel_id' => 'sales']),
                           $chan('sales')['business_number'] ?? null, $chan('sales-001')['business_number'] ?? null];
        // (e) support moved to another instance since its number was verified: verifying sales with that number takes it.
        $setCfg(['evo_instance_support' => 'ps-sup-new', 'evo_instance_account' => 'ps-sup-new']);
        $live(['ps-sales' => '256700000903@s.whatsapp.net']);
        $f['release_e'] = ['verify' => $post(['wa_action' => 'sn_verify_department', 'channel_id' => 'sales']),
                           'rows' => [$chan('sales')['business_number'] ?? null, $chan('support')['business_number'] ?? null]];
        $setCfg(['evo_instance_support' => 'ps-supacc', 'evo_instance_account' => 'ps-supacc']);
        $live();
        $f['release_e_back'] = [strpos($post(['wa_action' => 'sn_verify_department', 'channel_id' => 'sales']), 'ok:') === 0,
                                strpos($post(['wa_action' => 'sn_verify_department', 'channel_id' => 'support']), 'ok:') === 0,
                                AutomationPolicy::forInstall($cfgNow, $s->data, $pdo, $s->store())->gaps()];
        // (f) an instance whose owner Evolution reports as an @lid has no phone number to verify: the gap stays open.
        $setCfg(['evo_instance_sales' => 'ps-sales-lid']);
        $live(['ps-sales-lid' => '123456789012345@lid', 'ps-bravo' => '123456789012346@lid']);
        $f['lid'] = ['dept' => $post(['wa_action' => 'sn_verify_department', 'channel_id' => 'sales']),
                     'line' => $post(['wa_action' => 'sn_verify', 'channel_id' => 'sales-002']),
                     'none' => (function () use ($live, $post): string {   // no owner reported at all: not yet
                         $live(['ps-sales-lid' => '123456789012345@lid', 'ps-bravo' => '']);
                         return $post(['wa_action' => 'sn_verify', 'channel_id' => 'sales-002']);
                     })(),
                     'rows' => [$chan('sales')['business_number'] ?? null, $chan('sales-002')['business_number'] ?? null],
                     'gaps' => AutomationPolicy::forInstall($cfgNow, $s->data, $pdo, $s->store())->gaps()];
        $setCfg(['evo_instance_sales' => 'ps-sales']);
        $live();
        $f['lid_back'] = AutomationPolicy::forInstall($cfgNow, $s->data, $pdo, $s->store())->gaps();
        $f['gaps_warn_after'] = substr_count($s->run('cron/followup_scan.php')[1], $warnLine);
    }

    // ── A number re-paired, or a line nobody routes: DishNet's own once the webhook guard has read Evolution ─────
    if ($want('reported')) {
        // Support's phone and Bravo's re-paired to other numbers; an instance owned by an @lid. Nobody verifies anything.
        $live(['ps-supacc' => '256700000913@s.whatsapp.net', 'ps-bravo' => '256700000922@s.whatsapp.net', 'ps-lid' => '123456789012345@lid']);
        $g = $s->run('cron/wa_webhook_guard.php');
        $nums = array_map('strval', array_column((array)($s->store()->load(InternalNumbers::REPORTED_FILE) ?? []), 'number'));
        $pol = AutomationPolicy::forInstall($cfgNow, $s->data, $pdo, $s->store());
        $f['reported'] = ['guard' => $g[0], 'recorded' => in_array('256700000913', $nums, true), 'lid' => in_array('123456789012345', $nums, true),
                          'gaps' => $pol->gaps(), 'alpha' => $pol->refusal('sales-001', '256771910048'), 'bravo' => $pol->refusal('sales-002', '256771910048'),
                          'desk' => $pol->refusal('sales', '256771910048'),
                          'to_line' => $quiet('256700000913', 'PS from support, re-paired, to Alpha', 'ps-alpha', 'sales-001'),
                          'to_desk' => $quiet('256700000913', 'PS from support, re-paired, to the sales desk', 'ps-sales', 'sales'),
                          'customer' => $quiet('256771910048', 'PS a customer while support is re-paired', 'ps-alpha', 'sales-001')];
        // What the operator reads says the same as the policy: the card's gate and note, P3 and set_config.
        $sc = $s->run('tools/set_config.php', ['--key', 'multi_number_channels_enabled', '--value', '1']);
        $f['reported_readers'] = ['card' => (new SalesNumbersAdmin($pdo, $s->store(), $cfgNow, $s->data, EvolutionApiService::forStore($cfgNow, $pdo, $s->data)))->departmentGaps(),
                                  'p3' => strpos($s->run('tools/channels.php')[1], 'department numbers: NOT all verified — support') !== false,
                                  'set_config' => [$sc[0], strpos($sc[1], 'Not yet: the department numbers are not all verified (support)') !== false]];
        // A process built as the webhook, the AI worker and the follow-up sender are (forStore, then the store) logs that
        // gap too — it exists only in the record, so it is said once the store is given, once.
        file_put_contents($s->plug . '/cron/ps_warn_probe.php', '<?php
$pluginRoot = dirname(__DIR__);
foreach ([\'bootstrap_data\', \'StoreInterface\', \'JsonStore\', \'SqliteStore\', \'PluginConfig\', \'ContactOptOut\', \'EvolutionApiService\'] as $l) require_once "$pluginRoot/lib/$l.php";
$dataDir = getDataDir($pluginRoot);
$store   = SqliteStore::create($dataDir);
$evo     = EvolutionApiService::forStore(PluginConfig::load($pluginRoot, $dataDir), $store->getPdo(), $dataDir);
$evo->useStaffStore($store);
$evo->useStaffStore($store);
echo "probe done\n";
');
        $wp = $s->run('cron/ps_warn_probe.php');
        $f['reported_readers']['log'] = [substr_count($wp[1], 'the channel registry is on but the department numbers are not all verified'),
                                         strpos($wp[1], 'probe done') !== false];
        // Verified again on the card, with no guard run between: what Evolution reports now is recorded at once — support
        // first (Bravo still re-paired), then Bravo; each verification's own refresh is what clears its number.
        $live(['ps-bravo' => '256700000922@s.whatsapp.net']);
        $v = $post(['wa_action' => 'sn_verify_department', 'channel_id' => 'support']);
        $pol = AutomationPolicy::forInstall($cfgNow, $s->data, $pdo, $s->store());
        $f['reported_verify_dept'] = [$v, $pol->gaps(), $pol->refusal('sales-001', '256771910048'), $pol->refusal('sales-002', '256771910048')];
        // Bravo switched off and on again while the record says its phone is re-paired: Switch on refuses, as the policy
        // would; verified again, it is switched on.
        $sw = [$post(['wa_action' => 'sn_status', 'channel_id' => 'sales-002', 'status' => 'disabled', 'reason' => 'pilot safety test']),
               $post(['wa_action' => 'sn_status', 'channel_id' => 'sales-002', 'status' => 'active', 'reason' => 'pilot safety test'])];
        $live();
        $v = $post(['wa_action' => 'sn_verify', 'channel_id' => 'sales-002']);
        $sw[] = $post(['wa_action' => 'sn_status', 'channel_id' => 'sales-002', 'status' => 'active', 'reason' => 'pilot safety test']);
        $f['reported_switch'] = $sw;
        $f['reported_verify_line'] = [$v, AutomationPolicy::forInstall($cfgNow, $s->data, $pdo, $s->store())->refusal('sales-002', '256771910048')];
        // Paired back, as Evolution reports at the guard's next run: everything as it was.
        $live();
        $g2 = $s->run('cron/wa_webhook_guard.php');
        $pol = AutomationPolicy::forInstall($cfgNow, $s->data, $pdo, $s->store());
        $f['reported_back'] = [$g2[0], $pol->gaps(), $pol->refusal('sales-001', '256771910048'), $pol->refusal('sales-002', '256771910048')];
        // One phone, two linked devices: Alpha's instance paired with the sales desk's phone, which the desk's instance
        // still reports too — and 'ps-alpha' sorts before 'ps-sales'. Each instance is compared with its own row.
        $live(['ps-alpha' => '256700000900@s.whatsapp.net']);
        $g3 = $s->run('cron/wa_webhook_guard.php');
        $pol = AutomationPolicy::forInstall($cfgNow, $s->data, $pdo, $s->store());
        $f['reported_shared'] = [$g3[0], $pol->refusal('sales-001', '256771910049'), $pol->gaps(), $pol->refusal('sales', '256771910049'),
                                 $pol->refusal('sales-002', '256771910049')];
        // Paired back, nobody verifying anything: the guard's next read alone makes it whole again.
        $live();
        $g4 = $s->run('cron/wa_webhook_guard.php');
        $pol = AutomationPolicy::forInstall($cfgNow, $s->data, $pdo, $s->store());
        $f['reported_cleared'] = [$g4[0], $pol->gaps(), $pol->refusal('sales-001', '256771910049'), $pol->refusal('sales', '256771910049'),
                                  $pol->refusal('sales-002', '256771910049')];
        // Alpha's instance and the sales desk's re-paired onto phones Evolution reports with no phone number (@lid owners):
        // nothing verified on them can be confirmed — Alpha is unverified, sales a gap, and the card says so on both rows.
        $live(['ps-alpha' => '123456789012347@lid', 'ps-sales' => '123456789012348@lid']);
        $g5 = $s->run('cron/wa_webhook_guard.php');
        $pol = AutomationPolicy::forInstall($cfgNow, $s->data, $pdo, $s->store());
        $evoL = EvolutionApiService::forStore($cfgNow, $pdo, $s->data);
        $card = [];
        foreach ((new SalesNumbersAdmin($pdo, $s->store(), $cfgNow, $s->data, $evoL))->view($evoL->listInstances()) as $row) {
            $card[(string)$row['id']] = $row['live_differs'];
        }
        $f['reported_lid'] = [$g5[0], $pol->refusal('sales-001', '256771910049'), $pol->gaps(), $pol->refusal('sales-002', '256771910049'),
                              $card['sales-001'] ?? null, $card['sales'] ?? null, $card['sales-002'] ?? null];
        $reasons = [];
        foreach ((new SalesNumbersAdmin($pdo, $s->store(), $cfgNow, $s->data, $evoL))->view($evoL->listInstances()) as $row) {
            $reasons[(string)$row['id']] = $row['live_reason'] ?? null;
        }
        // Switched off and on again meanwhile: refused, saying why; paired back, it is switched on again.
        $sw = [$post(['wa_action' => 'sn_status', 'channel_id' => 'sales-001', 'status' => 'disabled', 'reason' => 'pilot safety test']),
               $post(['wa_action' => 'sn_status', 'channel_id' => 'sales-001', 'status' => 'active', 'reason' => 'pilot safety test'])];
        $live();
        $s->run('cron/wa_webhook_guard.php');
        $sw[] = $post(['wa_action' => 'sn_status', 'channel_id' => 'sales-001', 'status' => 'active', 'reason' => 'pilot safety test']);
        $f['reported_lid_card'] = ['reasons' => [$reasons['sales-001'] ?? null, $reasons['sales'] ?? null, $reasons['sales-002'] ?? null], 'switch' => $sw];
        $settle();
    }

    // ── A number switched on again while a process holds an older read; a department with no instance ──────────
    if ($want('stale')) {
        // Paused when the policy was read, switched on again before it is asked: it waits for the next read — a
        // salesperson's number is never closed as if it had no instance. A department with none configured is closed.
        $reg()->setStatus('sales-002', 'paused', 'pilot safety test', 'paused for a moment');
        $pol = AutomationPolicy::forInstall($cfgNow, $s->data, $pdo, $s->store());
        $before = $pol->channelRefusal('sales-002');
        $reg()->setStatus('sales-002', 'active', 'pilot safety test', 'switched on again');
        $after = $pol->channelRefusal('sales-002');
        $noAcct = AutomationPolicy::forInstall(['evo_instance_account' => ''] + $cfgNow, $s->data, $pdo, $s->store());
        $f['stale'] = ['salesperson' => [$before, $after, AutomationPolicy::transient($after),
                                         AutomationPolicy::forInstall($cfgNow, $s->data, $pdo, $s->store())->channelRefusal('sales-002')],
                       'department'  => [$noAcct->channelRefusal('account'), AutomationPolicy::transient($noAcct->channelRefusal('account')),
                                         $noAcct->channelRefusal('support')]];
    }

    // ── Registry OFF: 5.18.89 exactly ──────────────────────────────────────────────────────────────────────────
    if ($want('registry')) {
        $flag(false);
        $e0 = $nEvents('ai.reply');
        $w = $in('256700000902', 'Hello from Bravo\'s line, registry off', 'ps-sales');
        $queued = $nEvents('ai.reply') - $e0;
        $n0 = $nText();
        $runWorker('Answered as in 5.18.89. PS-OFF');
        $pol = AutomationPolicy::forInstall($cfgNow, $s->data, $pdo, $s->store());
        $evo = EvolutionApiService::forStore($cfgNow, $pdo, $s->data);
        $f['registry_off'] = ['in' => [$w[0], $w[1], $w[2]], 'queued' => $queued, 'replies' => ps_pairs($textsSince($n0)),
                              'policy' => [$pol->active(), $pol->refusal('sales', '256700000902'), $evo->registryOn(), $evo->automationPolicy()->active()]];
        // The webhook guard records nothing Evolution reports with the registry off.
        $noReport();
        $live(['ps-bravo' => '256700000922@s.whatsapp.net']);
        $gOff = $s->run('cron/wa_webhook_guard.php');
        $f['registry_off_guard'] = [$gOff[0], (array)($s->store()->load(InternalNumbers::REPORTED_FILE) ?? [])];
        $live();
        $flag(true);
        $settle();
    }

    // ── Media (development tree only): a DishNet line's voice note is never queued for the media worker ───────
    if ($want('media') && is_file($root . '/workers/MediaWorker.php')) {
        $setCfg(['ai_media_enabled' => '1']);
        $voice = function (string $from, string $instance) use ($s): array {
            $payload = ['event' => 'messages.upsert', 'instance' => $instance, 'data' => [
                'key' => ['id' => 'PS-MEDIA-' . bin2hex(random_bytes(4)), 'fromMe' => false, 'remoteJid' => $from . '@s.whatsapp.net'],
                'message' => ['audioMessage' => ['mimetype' => 'audio/ogg; codecs=opus', 'seconds' => 3, 'fileLength' => 2000]],
                'messageType' => 'audioMessage', 'messageTimestamp' => time(), 'pushName' => 'PS']];
            $r = $s->http('POST', "{$s->base}?page=evo_webhook", $payload, ['Content-Type: application/json', 'X-DishNet-Token: ' . $s->evoKey]);
            return ['outcome' => $r[2]['outcome'] ?? null, 'media_queued' => (int)($r[2]['media_queued'] ?? 0)];
        };
        $m0 = $nEvents('ai.media');
        $f['media'] = $voice('256700000901', 'ps-bravo') + ['events' => $nEvents('ai.media') - $m0];
        // The control: a customer's voice note on the same number IS queued.
        $f['media_control'] = $voice('256771910036', 'ps-bravo');
        // The media worker's own check: the customer's queued note, its conversation now one of DishNet's lines (planted —
        // the webhook never queues one), is settled with nothing fetched; one with no phone on record, likewise, by its own name.
        $f['media_control2'] = $voice('256771910037', 'ps-bravo');
        $ca = (int)($conv('256771910036', 'sales-002')['id'] ?? 0);
        $cb = (int)($conv('256771910037', 'sales-002')['id'] ?? 0);
        $pdo->prepare("UPDATE wa_conversations SET phone = '256700000902' WHERE id = ?")->execute([$ca]);
        $pdo->prepare("UPDATE wa_conversations SET phone = '' WHERE id = ?")->execute([$cb]);
        $fe0 = count($evoState()['media_fetch_calls'] ?? []);
        $mw = new MediaWorker($s->store(), $cfgNow, 30, 10);
        ob_start(); try { $mw->run(); } finally { ob_end_clean(); }
        $mr = function (int $cid) use ($q): array { return $q('SELECT status, failure_reason FROM wa_media WHERE conversation_id = ? ORDER BY id DESC LIMIT 1', [$cid])[0] ?? []; };
        $f['media_worker'] = ['dishnet' => $mr($ca), 'no_phone' => $mr($cb), 'fetched' => count($evoState()['media_fetch_calls'] ?? []) - $fe0];
        $pdo->prepare("UPDATE wa_conversations SET phone = '256771910036' WHERE id = ?")->execute([$ca]);
        $pdo->prepare("UPDATE wa_conversations SET phone = '256771910037' WHERE id = ?")->execute([$cb]);
        // A conversation read that fails is not an answer: nothing is settled, and the event is retried.
        $f['media_control3'] = $voice('256771910038', 'ps-bravo');
        $cc = (int)($conv('256771910038', 'sales-002')['id'] ?? 0);
        $pdo->exec('PRAGMA legacy_alter_table = ON');
        $pdo->exec('ALTER TABLE wa_conversations RENAME TO wa_conversations_ps_hidden');
        try {
            $mw2 = new MediaWorker($s->store(), $cfgNow, 30, 10);
            ob_start(); try { $mw2->run(); } catch (\Throwable $e) { } finally { ob_end_clean(); }
        } finally {
            $pdo->exec('ALTER TABLE wa_conversations_ps_hidden RENAME TO wa_conversations');
            $pdo->exec('PRAGMA legacy_alter_table = OFF');
        }
        $ev = $q("SELECT status, error FROM events WHERE event_type = 'ai.media' ORDER BY id DESC LIMIT 1")[0] ?? [];
        $f['media_read'] = ['media' => $mr($cc), 'event' => $ev['status'] ?? null, 'error' => (string)($ev['error'] ?? '') !== ''];
        // The worker's client knows the numbers Evolution reports from its first use, whatever path asks first (a retried
        // file skips the sender check): Bravo re-paired is refused for its hand-over holding line too.
        $live(['ps-bravo' => '256700000922@s.whatsapp.net']);
        $s->run('cron/wa_webhook_guard.php');
        $ec = new ReflectionMethod(MediaWorker::class, 'evoClient'); $ec->setAccessible(true);
        $f['media_store'] = $ec->invoke(new MediaWorker($s->store(), $cfgNow, 30, 10))->automationPolicy()->refusal('sales-002', '256771910039');
        $live();
        $s->run('cron/wa_webhook_guard.php');
        $setCfg(['ai_media_enabled' => '0']);
    }

    // Q. Domain B: the whole scenario ran on a copy of the plugin without it, and loaded nothing from it.
    $f['q_loaded'] = array_values(array_filter(get_included_files(), function ($p) { return strpos($p, 'dishnet-mikrotik-control-plane') !== false; }));
    $f['q_sandbox'] = is_dir($s->plug . '/dishnet-mikrotik-control-plane');
    $s->stop();
    return $f;
}

// ═════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
// In process: the two new classes
// ═════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
function ps_unit(string $root): array
{
    $f = [];
    $f['classes'] = [EvolutionApiService::AUTOMATED_CLASSES === [ContactOptOut::CLASS_REPLY, ContactOptOut::CLASS_PROACTIVE]];
    $cfg = ['tenant_profile' => 'uganda', 'alert_whatsapp' => '+256 700 000 999', 'whatsapp_admin_phone' => '0700000998',
            'wa_support_number' => '256700000980'];
    $rows = [
        'sales'     => ['channel_id' => 'sales', 'business_number' => '+256700000900', 'status' => 'active', 'owner_type' => 'department'],
        'support'   => ['channel_id' => 'support', 'business_number' => '+256700000903', 'status' => 'active', 'owner_type' => 'department'],
        'account'   => ['channel_id' => 'account', 'business_number' => null, 'status' => 'active', 'owner_type' => 'department'],
        'sales-001' => ['channel_id' => 'sales-001', 'business_number' => '+256700000901', 'status' => 'active', 'owner_type' => 'staff', 'owner_staff_id' => 7],
        'sales-002' => ['channel_id' => 'sales-002', 'business_number' => '+256700000902', 'status' => 'disabled', 'owner_type' => 'staff', 'owner_staff_id' => 8],
        'sales-003' => ['channel_id' => 'sales-003', 'business_number' => '+256700000905', 'status' => 'retired', 'owner_type' => 'staff', 'owner_staff_id' => 9],
    ];
    $cm  = ['sales' => 'u-sales', 'support' => 'u-sup', 'account' => 'u-sup'];
    $ver = ['sales' => 'u-sales', 'support' => 'U-SUP'];
    $store = new class {
        public $fail = false;
        public function load(string $f) {
            if ($this->fail) throw new \RuntimeException('test: the store is unreadable');
            return [['id' => 7, 'name' => 'U Alpha', 'role' => 'sales', 'is_active' => true, 'phone' => '0700000201'],
                    ['id' => 8, 'name' => 'U Bravo', 'role' => 'sales', 'is_active' => false, 'phone' => '0700000202'],
                    ['id' => 9, 'name' => 'U Charlie', 'role' => 'sales', 'is_active' => true, 'phone' => '0700000203'],
                    ['id' => 10, 'name' => 'U Dealer', 'role' => 'sales', 'is_active' => true, 'phone' => '0700000210']];
        }
    };
    $n = InternalNumbers::fromRows($rows, $cm, $ver, $cfg, null);
    $n->useStore($store);
    $sc = function (string $p, bool $dept) use ($n): string { return $n->senderClass($p, $dept); };
    $f['lines'] = [$sc('256700000900', true), $sc('+256 700-000 901', true), $sc('256700000902', false), $sc('256700000905', false),
                   $sc('256700000980', true), $sc('211700000901', false), $sc('00901', false), $sc('', false), $n->lineOf('256700000901')];
    $f['people'] = [$sc('256700000201', false), $sc('256700000201', true), $sc('256700000999', false), $sc('256700000998', false),
                    $sc('256700000999', true), $sc('256700000202', false), $sc('256700000203', false), $sc('256700000210', false)];
    $f['gaps'] = [$n->gaps(), InternalNumbers::gapsOf($rows, ['sales' => 'u-sales-2'] + $cm, $ver),
                  InternalNumbers::gapsOf($rows, ['sales' => 'u-sales', 'support' => 'u-sup', 'account' => 'u-acct'], $ver),
                  InternalNumbers::gapsOf($rows, ['sales' => '', 'support' => '', 'account' => ''], [])];
    $store2 = clone $store; $store2->fail = true;
    $n2 = InternalNumbers::fromRows($rows, $cm, $ver, $cfg, null);
    $n2->useStore($store2);
    $f['store_fails'] = [$n2->senderClass('256700000201', false), $n2->senderClass('256700000901', false), $n2->senderClass('256771999999', false)];
    $f['inert'] = [AutomationPolicy::inert()->refusal('sales-001', '256700000901'), AutomationPolicy::inert()->senderClass('sales-001', '256700000901'),
                   AutomationPolicy::inert()->active(), AutomationPolicy::inert()->sqlExclusion('c.channel')];
    $f['kinds'] = [AutomationPolicy::transient('channel_paused'), AutomationPolicy::transient('internal_numbers_incomplete'),
                   AutomationPolicy::transient('channel_assistant_disabled'), AutomationPolicy::transient('internal_recipient'),
                   AutomationPolicy::transient('channel_disabled'), AutomationPolicy::silent('internal_recipient'),
                   AutomationPolicy::silent('channel_disabled'), AutomationPolicy::transient('no_instance')];
    $saved = new class { public $files = []; public function save(string $f, array $d): void { $this->files[$f] = $d; } };
    $nRec = InternalNumbers::recordReported($saved, [
        ['name' => 'u-a', 'jid_phone' => '256700000913', 'phone' => '256700000913'],
        ['name' => 'u-lid', 'jid_phone' => '', 'phone' => '123456789012345'],
        ['name' => 'u-short', 'jid_phone' => '12345', 'phone' => '12345'], 'junk']);
    $f['recorded'] = [$nRec, array_map('strval', array_column((array)($saved->files[InternalNumbers::REPORTED_FILE] ?? []), 'number')),
                      array_map(function ($r) { return !empty($r['no_phone']); }, (array)($saved->files[InternalNumbers::REPORTED_FILE] ?? []))];
    // One phone, two linked devices: two instances report one number. Each is recorded, and compared with its own row,
    // whichever sorts first — a salesperson's instance on the sales desk's phone; the sales desk's on a salesperson's.
    $mem = function (array $instances) {
        $st = new class {
            public $files = [];
            public function save(string $f, array $d): void { $this->files[$f] = $d; }
            public function load(string $f) { return $this->files[$f] ?? null; }
        };
        InternalNumbers::recordReported($st, $instances);
        return $st;
    };
    $nA = InternalNumbers::fromRows($rows, $cm, $ver, $cfg, null);
    $nA->useStore($mem([['name' => 'u-alpha', 'jid_phone' => '256700000900'], ['name' => 'u-sales', 'jid_phone' => '256700000900'],
                        ['name' => 'u-sup', 'jid_phone' => '256700000903']]));
    $nB = InternalNumbers::fromRows($rows, $cm, $ver, $cfg, null);
    $nB->useStore($mem([['name' => 'u-sales', 'jid_phone' => '256700000901'], ['name' => 'u-sup', 'jid_phone' => '256700000903'],
                        ['name' => 'u-zeta', 'jid_phone' => '256700000901']]));
    $f['shared'] = [$nA->reportedFor('u-alpha'), $nA->reportedFor('U-SALES'), $nA->gaps(), $nB->reportedFor('u-sales'), $nB->reportedFor('u-zeta'), $nB->gaps()];
    // The sales desk's instance re-paired onto an @lid owner: no number, never a member — a gap, for the policy and for
    // every reader that asks gapsWithReported (the card, P3, set_config); gapsOf alone cannot see it.
    $stC = $mem([['name' => 'u-sales', 'jid_phone' => '', 'phone' => '123456789012348'], ['name' => 'u-sup', 'jid_phone' => '256700000903']]);
    $nC = InternalNumbers::fromRows($rows, $cm, $ver, $cfg, null);
    $nC->useStore($stC);
    $f['no_phone'] = [$nC->reportedFor('u-sales') === InternalNumbers::NO_PHONE, $nC->gaps(), $nC->senderClass('123456789012348', true),
                      InternalNumbers::gapsWithReported($rows, $cm, $ver, $stC), InternalNumbers::gapsOf($rows, $cm, $ver)];
    // A registry that cannot be read: the departments as configured, nothing else automated.
    $u = new ReflectionMethod(AutomationPolicy::class, '__construct'); $u->setAccessible(true);
    $p = (new ReflectionClass(AutomationPolicy::class))->newInstanceWithoutConstructor();
    $u->invoke($p, 'unreadable');
    $f['unreadable'] = [$p->refusal('sales', '256771999999'), $p->refusal('sales-001', '256771999999'), $p->senderClass('sales', '256700000901'),
                        $p->sqlExclusion('c.channel')];
    $f['bad_column'] = (function () { try { AutomationPolicy::inert()->sqlExclusion('x; DROP'); return 'accepted'; } catch (\InvalidArgumentException $e) { return 'refused'; } })();
    return $f;
}

// ═════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
// South Sudan: every switch set, and nothing here ever runs
// ═════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
function ps_south_sudan(string $root): array
{
    $f = [];
    $s = SjSandbox::start($root, ['tenant_profile' => 'south-sudan', 'timezone' => 'UTC', 'ai_enabled' => '1',
        ChannelRegistry::FLAG => '1', OwnedNumberHold::FLAG => '1', 'followup_enabled' => '1', 'followup_auto_send' => '1',
        'evo_instance_sales' => 'ps-sales', 'evo_instance_support' => 'ps-supacc', 'evo_instance_account' => 'ps-supacc',
        'alert_whatsapp' => '211900000999'], 'psss');
    $pdo = $s->store()->getPdo();
    $e0 = (int)($s->q("SELECT COUNT(*) c FROM events WHERE event_type = 'ai.reply'")[0]['c'] ?? 0);
    $r = $s->evoInbound('211900000999', 'Hello from the alert number', 'ps-sales', 'PSSS-' . bin2hex(random_bytes(4)));
    $f['n_webhook'] = [$r[0], $r[2]['outcome'] ?? null, (int)($s->q("SELECT COUNT(*) c FROM events WHERE event_type = 'ai.reply'")[0]['c'] ?? 0) - $e0];
    $evo = EvolutionApiService::forStore($s->cfg, $pdo, $s->data);
    $pol = AutomationPolicy::forInstall($s->cfg, $s->data, $pdo, $s->store());
    $f['n_policy'] = [$evo->registryOn(), $evo->automationPolicy()->active(), $pol->active(), $pol->refusal('sales', '211900000999'),
                      $pol->sqlExclusion('c.channel')];
    $ct = $s->run('tools/channels.php');
    $f['n_channels_tool'] = [$ct[0], strpos($ct[1], 'department numbers') === false, strpos($ct[1], 'sales') !== false];
    $s->http('POST', "{$s->evo}/__test/instances", [
        ['name' => 'ps-sales', 'connectionStatus' => 'open', 'ownerJid' => '211900000900@s.whatsapp.net', 'profileName' => 'PS'],
        ['name' => 'ps-supacc', 'connectionStatus' => 'open', 'ownerJid' => '211900000903@s.whatsapp.net', 'profileName' => 'PS']],
        ['Content-Type: application/json']);
    $g = $s->run('cron/wa_webhook_guard.php');
    $f['n_guard'] = [$g[0], (array)($s->store()->load(InternalNumbers::REPORTED_FILE) ?? [])];
    $s->stop();
    return $f;
}

if ($isDriver) {
    $out = [];
    if (in_array('unit', $parts, true) || in_array('all', $parts, true)) $out += ['unit' => ps_unit($root)];
    if (in_array('south_sudan', $parts, true) || in_array('all', $parts, true)) $out += ['ss' => ps_south_sudan($root)];
    $ug = array_values(array_diff($parts, ['south_sudan', 'unit']));
    if ($ug !== []) $out += ps_uganda($root, $ug);
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
if (isset($f['_raw'])) { echo "  FAIL the scenario did not finish\n       {$f['_raw']}\n"; exit(1); }
$st = $f['setup'];
$none = function (array $x): bool { return ($x['in'][0] ?? 0) === 200 && ($x['queued'] ?? -1) === 0 && ($x['category'] ?? null) === 'staff' && ($x['stored'] ?? 0) === 1; };
$noZone = ($f['zone'] ?? '') === '';
// Never a quiet skip: the follow-up sends could not be run at all, and that is a failure of this run, said plainly.
is_(!$noZone, 'the follow-up sends can be run now (some time zone is inside the 08:00–20:00, Monday–Saturday window)',
    'NOT RUN: it is Sunday between 07:00 and 19:00 UTC, when no time zone is inside the sending window — run this test again outside it');

echo "\nSetup — the card, the department numbers first (docs/66 §1)\n";
is_($st['setcfg_refused'] === [1, true, true], 'the registry cannot be switched on before the department numbers are verified (set_config refuses, nothing saved)', $j($st['setcfg_refused']));
is_(strpos($st['on_no_departments'][0], 'no: Not yet: verify the department numbers first') === 0 && $st['on_no_departments'][1] === 'disabled',
    'nor a salesperson number switched on (registry on, department numbers missing)', $j($st['on_no_departments']));
is_(strpos($st['verify_departments'][0], 'ok: Verified: the sales number') === 0 && strpos($st['verify_departments'][1], 'ok: Verified: the support number') === 0
    && strpos($st['verify_departments'][2], "no: The account number uses the support number's instance") === 0,
    'the department numbers are verified on the card with the registry off, from Evolution\'s report — support\'s covers account', $j($st['verify_departments']));
is_($st['dept_rows'] === ['+256700000900', '+256700000903', null], 'each recorded on the department that takes its instance\'s inbound', $j($st['dept_rows']));
is_($st['setcfg_ok'] === [0, true], 'then the registry may be switched on', $j($st['setcfg_ok']));
is_(strpos($st['on'][0], 'ok:') === 0 && strpos($st['on'][1], 'ok:') === 0 && $st['on'][2] === 'active' && $st['on'][3] === 'active',
    'and both salesperson numbers switched on', $j($st['on']));
is_($st['policy'] === [true, true, '', '', ''], 'the policy: on, complete, every number may send to a customer', $j($st['policy']));
is_(($st['people_scope'] ?? null) === ['alert_number', '', '', ''],
    'the alert number is a person on DishNet\'s side on a salesperson\'s number only — not on a notification thread, a website chat or a department', $j($st['people_scope'] ?? null));
is_(($st['channels_tool'] ?? null) === true, 'tools/channels.php says the department numbers are all verified', $j($st['channels_tool'] ?? null));

echo "\n1, 2, 16. Each number answers from itself, isolated by its channel id\n";
is_(($f['t1']['replies'] ?? null) === [['ps-alpha', '256771910001']] && ($f['t1']['role'] ?? null) === 'sales', 'TEST 1: customer → Alpha: the AI replies from Alpha\'s number', $j($f['t1'] ?? null));
is_(($f['t1']['typing'] ?? null) === [['ps-alpha', '256771910001']], 'and its typing indicator went to the customer from Alpha\'s number (the control for every "no typing" below)', $j($f['t1']['typing'] ?? null));
is_(($f['t2']['replies'] ?? null) === [['ps-bravo', '256771910002']], 'TEST 2: customer → Bravo: the AI replies from Bravo\'s number', $j($f['t2'] ?? null));
is_(($f['dept_lead']['found'] ?? false) === true && ($f['dept_lead']['channel'] ?? null) === 'sales'
    && array_key_exists('number', (array)($f['dept_lead'] ?? [])) && in_array($f['dept_lead']['number'], [null, 'absent'], true),
    'a lead on the sales desk names its channel and carries no business number, as before', $j($f['dept_lead'] ?? null));
is_(($f['t16']['convs'] ?? null) === ['sales-001', 'sales-002'] && ($f['t16']['replies'] ?? null) === [['ps-alpha', '256771910003'], ['ps-bravo', '256771910003']]
    && !empty($f['t16']['isolated']), 'TEST 16: one customer on both: two conversations, each answered on its own number, neither history in the other', $j($f['t16'] ?? null));

echo "\n3. The human pause still works\n";
is_(($f['t3']['state'] ?? null) === 'human_active' && ($f['t3']['parked_sends'] ?? -1) === 0 && ($f['t3']['parked_brain'] ?? -1) === 0,
    'TEST 3: Alpha answers on their phone: the assistant stands down on that chat', $j($f['t3'] ?? null));
is_(($f['t3']['after'] ?? null) === [['ps-alpha', '256771910004']], 'and once the pause is over the parked question is answered, on Alpha\'s number', $j($f['t3']['after'] ?? null));

echo "\n4–7, 18–20. DishNet's own numbers are never answered\n";
is_($none($f['t4']), 'TEST 4: Alpha\'s number → Bravo\'s: kept for the team, filed staff, nothing queued (a fresh message id)', $j($f['t4']));
is_(($f['t4_shared']['handset'][1] ?? null) === 'accepted' && ($f['t4_shared']['inbound']['queued'] ?? -1) === 0,
    'TEST 4: with the id WhatsApp may share, the handset\'s echo first: nothing queued (the message-id dedupe drops the inbound)', $j($f['t4_shared']));
is_($none($f['t4_shared_first']['inbound'] ?? []), 'TEST 4: the same shared id, the inbound first: the guard alone decides — kept for the team, nothing queued', $j($f['t4_shared_first'] ?? null));
is_($none($f['t5']), 'TEST 5: Bravo\'s number → the sales department: nothing queued', $j($f['t5']));
is_($none($f['t6']), 'TEST 6: the support number → Alpha\'s: nothing queued', $j($f['t6']));
is_($none($f['t7']), 'TEST 7: the account number (support\'s instance, as in production) → Bravo\'s: nothing queued', $j($f['t7']));
is_(strpos($f['t7_own']['verify'], 'ok: Verified: the account number') === 0 && $none($f['t7_own']['in']),
    'TEST 7: the account number on an instance of its own, verified on the card → Alpha\'s: nothing queued', $j($f['t7_own']));
is_($none($f['t18']['owner']), 'TEST 18: a salesperson who owns a number (Bravo\'s own phone) → Alpha\'s: no reply, no lead', $j($f['t18']['owner']));
is_($none($f['t18']['alert']), 'the internal alert number → Alpha\'s: nothing queued', $j($f['t18']['alert']));
is_(($f['t18']['colleague']['queued'] ?? 0) === 1 && array_key_exists('category', (array)$f['t18']['colleague']) && $f['t18']['colleague']['category'] === null,
    'a sales colleague who owns no number is answered like a customer (the J8 rule, unchanged)', $j($f['t18']['colleague']));
is_(($f['t18']['owner_to_department']['queued'] ?? 0) === 1, 'and the sales department keeps the J8 rule exactly: a salesperson\'s own phone is answered there', $j($f['t18']['owner_to_department']));
is_($none($f['t19']), 'TEST 19: a DishNet number asking what a customer asks: still nothing queued', $j($f['t19']));
is_($none($f['t20']), 'TEST 20: a DishNet number after the 5-minute pause has run out: still nothing queued', $j($f['t20']));
is_(($f['t20_queued']['status'] ?? null) === 'done' && ($f['t20_queued']['brain'] ?? -1) === 0 && ($f['t20_queued']['sends'] ?? -1) === 0
    && ($f['t20_queued']['escalations'] ?? -1) === 0 && !empty($f['t20_queued']['worker_guard']),
    'an event queued before the fix for a DishNet number: not answered, no model call, no hand-over, no retry', $j($f['t20_queued']));
is_($none($f['t_disabled_line']), 'a switched-off line\'s number is still a DishNet number', $j($f['t_disabled_line']));
is_(($f['t20_paused'] ?? null) === ['brain' => 0, 'sends' => 0, 'escalations' => 0],
    'a DishNet line writing while the number is paused: not answered, and nobody paged about it', $j($f['t20_paused'] ?? null));
is_($none($f['stored_cfg']['line'] ?? []) && $none($f['stored_cfg']['admin'] ?? []),
    'DishNet numbers saved through Settings (the store, not the files) — an accounts line, the admin phone — are refused too', $j($f['stored_cfg'] ?? null));
is_(($f['internal_totals'] ?? null) === ['to_internal' => [], 'typing_to_internal' => [], 'leads' => 0],
    'nothing at all went to a DishNet number — no reply, no typing indicator — and no lead was made', $j($f['internal_totals'] ?? null));

echo "\nThe alert loop's seed\n";
is_(($f['seed']['alert'] ?? null) !== [] && ($f['seed']['alert'][0][0] ?? null) === 'ps-sales', 'the hand-over alert goes to Bravo\'s phone of record — his own line — from the sales number', $j($f['seed']['alert'] ?? null));
is_(($f['seed']['in'][1] ?? null) === 'accepted' && ($f['seed']['queued'] ?? -1) === 0 && ($f['seed']['answered'] ?? null) === [] && ($f['seed']['category'] ?? null) === 'staff',
    'arriving on Bravo\'s line from the sales department\'s number, it is never answered: the loop cannot start', $j($f['seed'] ?? null));

echo "\n8, 9, 22. The assistant off: nothing automated\n";
is_(($f['t8_off'][1] ?? 9) === 0 && ($f['t8']['in'][1] ?? null) === 'accepted' && ($f['t8']['queued'] ?? -1) === 0 && ($f['t8']['stored'] ?? 0) === 1,
    'TEST 8: the assistant off: the message is kept for the team, nothing queued', $j([$f['t8_off'] ?? null, $f['t8'] ?? null]));
is_(($f['t8']['sends'] ?? -1) === 0 && ($f['t8']['typing'] ?? -1) === 0 && ($f['t8']['brain'] ?? -1) === 0 && ($f['t8']['escalations'] ?? -1) === 0,
    'and the message queued just before is not answered either: no reply, no typing, no model call, nobody paged', $j($f['t8'] ?? null));
is_(($f['t9_scan'] ?? -1) === 0, 'TEST 9: the assistant off: the scan opens no follow-up on that number', $j($f['t9_scan'] ?? null));
if (($f['t9'] ?? null) !== 'no open zone') {
    is_(($f['t9']['sent'] ?? -1) === 0 && ($f['t9']['closed'] ?? null) === 'channel_assistant_disabled'
        && in_array(['closed', 'sender'], (array)($f['t9']['events'] ?? []), true)
        && strpos((string)($f['t9']['detail'] ?? ''), 'nothing automated may go to this chat on channel sales-001') !== false,
        'TEST 9: an approved follow-up on it is closed — channel_assistant_disabled — and never sent', $j($f['t9'] ?? null));
    is_(($f['t9_atsend']['variant'] ?? 0) === 1 && ($f['t9_atsend']['sent'] ?? -1) === 0 && ($f['t9_atsend']['closed'] ?? null) === 'channel_assistant_disabled'
        && strpos((string)($f['t9_atsend']['detail'] ?? ''), 'refused at the send itself') !== false,
        'and refused at the send itself (the sender\'s own checks out of the way): closed, never retried, never sent', $j($f['t9_atsend'] ?? null));
}
is_(($f['t22']['closed'] ?? null) === 'channel_assistant_disabled' && ($f['t22']['drafts'] ?? -1) === 0
    && !in_array('evaluated', array_column((array)($f['t22']['events'] ?? []), 0), true),
    'TEST 22: the assistant off, due, followup_auto_send on: closed before any model call — no draft, nothing approved, nothing sent', $j($f['t22'] ?? null));
is_(($f['t8_on'][1] ?? 9) === 1, 'the assistant back on', $j($f['t8_on'] ?? null));

echo "\n10–12, 21, 23. Follow-ups: each from its own number, never to a DishNet number\n";
if (($f['t10'] ?? null) !== 'no open zone') {
    is_(($f['t10']['alpha'] ?? null) === [['ps-alpha', '256771910011']], 'TEST 10: Alpha\'s follow-up leaves from Alpha\'s number', $j($f['t10'] ?? null));
    is_(($f['t10']['bravo'] ?? null) === [['ps-bravo', '256771910012']], 'TEST 11: Bravo\'s from Bravo\'s', $j($f['t10']['bravo'] ?? null));
    is_(($f['t10']['desk'] ?? null) === [['ps-sales', '256771910013']], 'TEST 12: the sales desk\'s from the sales number', $j($f['t10']['desk'] ?? null));
    is_(($f['t10']['auto'] ?? null) === [['ps-alpha', '256771910014']] && ($f['t10']['auto_record'] ?? null) === [['channel' => 'sales-001', 'decided_by' => 'auto']],
        'TEST 23: the assistant on, a valid customer: the automatic follow-up leaves from the right number', $j($f['t10'] ?? null));
    is_(($f['t21']['sent'] ?? null) === [[], [], [], [['ps-sales', '256700000202']]]
        && ($f['t21']['closed'] ?? null) === ['internal_recipient', 'internal_recipient', 'internal_recipient', null],
        'TEST 21: a follow-up to a DishNet line (from a salesperson\'s number or a department\'s) or to an owner on a salesperson\'s number: closed, never sent — a department may still follow up a person', $j($f['t21'] ?? null));
    is_(($f['paused_send'] ?? null) === ['sent' => 0, 'closed' => null, 'draft' => 'approved'], 'a paused number: the approved follow-up waits, approved, never closed, never sent', $j($f['paused_send'] ?? null));
}
is_(($f['t21_scan'] ?? null) === ['opened' => 0, 'logged' => 1], 'TEST 21: the scan opens none for a chat with a DishNet number filed before the fix, and says why', $j($f['t21_scan'] ?? null));
is_(($f['scan_allow'] ?? null) === ['web' => 0, 'department' => 1],
    'the scan opens nothing on a channel the registry does not route (a website chat) — and still a department\'s', $j($f['scan_allow'] ?? null));
is_(($f['scan_staff'] ?? null) === ['customer' => 1, 'staff' => 0], 'a chat filed staff takes none of the scan\'s places: the customer behind it opens', $j($f['scan_staff'] ?? null));
if (($f['run_recheck'] ?? null) !== 'no open zone') {
    is_(($f['run_recheck']['variant'] ?? 0) === 1 && ($f['run_recheck']['control'] ?? null) === ['status' => 'approved', 'decided_by' => 'auto']
        && ($f['run_recheck']['target']['status'] ?? null) === 'pending',
        'TEST 23: the assistant switched off while the model "thought": the run asks again and does not approve on its own (a control on another number is)', $j($f['run_recheck'] ?? null));
}
is_(in_array('evaluated', array_column((array)($f['t23_run']['events'] ?? []), 0), true)
    && !in_array((string)($f['t23_run']['closed'] ?? ''), ['channel_assistant_disabled', 'internal_recipient', 'channel_unverified', 'internal_numbers_incomplete'], true),
    'TEST 23: a valid customer passes the gate and reaches the evaluation', $j($f['t23_run'] ?? null));
is_(array_key_exists('closed', (array)($f['paused_run'] ?? [])) && $f['paused_run']['closed'] === null && !empty($f['paused_run']['moved']),
    'a paused number at the run: the follow-up is put off, not closed, and takes no place in the queue meanwhile', $j($f['paused_run'] ?? null));

echo "\n13–15, 24, 25. Refused, and never from another number\n";
is_(($f['t13']['in'][1] ?? null) === 'channel_disabled' && ($f['t13']['customer'] ?? null) === [] && ($f['t13']['escalations'] ?? 0) === 1,
    'TEST 13: a switched-off number: refused in; a message queued before is answered from no number, and a person is told', $j($f['t13'] ?? null));
is_(($f['t14']['sends'] ?? null) === [] && ($f['t14']['tried'] ?? null) === [['ps-alpha', '256771910020']],
    'TEST 14: a disconnected number: tried on that number alone, never on another', $j($f['t14'] ?? null));
is_(($f['t15']['in'][1] ?? null) === 'unknown_instance' && !empty($f['t15']['stored']) && ($f['t15']['send'] ?? null) === [true, 'unknown_channel'] && ($f['t15']['sent'] ?? -1) === 0,
    'TEST 15: an unknown instance stores nothing; an unknown channel sends nothing, from any number', $j($f['t15'] ?? null));
is_(($f['t24']['send'] ?? null) === [true, 'instance_changed'] && ($f['t24']['sent'] ?? -1) === 0,
    'TEST 24: the mapping changed after it was read: refused, nothing sent', $j($f['t24'] ?? null));
is_(($f['t25'] ?? null) === ['text' => 'channel_disabled', 'image' => 'channel_disabled', 'typing' => 'channel_disabled', 'sent' => 0, 'typed' => 0],
    'TEST 25: switched off after the service read the registry: a follow-up, a photo and the typing indicator are all refused', $j($f['t25'] ?? null));
is_(($f['t25_worker'] ?? null) === ['brain' => 1, 'customer' => 0, 'event' => 'done', 'escalations' => 1, 'refused_at_send' => true],
    'TEST 25: switched off while the model was thinking: refused at the send, a person told, no retry', $j($f['t25_worker'] ?? null));
if (($f['t25_cron'] ?? null) !== 'no open zone') {
    is_(($f['t25_cron']['sent'] ?? -1) === 0 && ($f['t25_cron']['closed'] ?? null) === 'cancelled', 'TEST 25: approved, then switched off: the sender closes it, nothing sent', $j($f['t25_cron'] ?? null));
}
is_(($f['unverified']['in'][1] ?? null) === 'accepted' && ($f['unverified']['sends'] ?? -1) === 0 && ($f['unverified']['brain'] ?? -1) === 0
    && ($f['unverified']['escalations'] ?? 0) === 1, 'a number switched on without being verified answers nobody, and a person is told', $j($f['unverified'] ?? null));
is_(($f['unreadable_worker']['brain'] ?? 0) === 1 && ($f['unreadable_worker']['customer'] ?? -1) === 0 && ($f['unreadable_worker']['escalations'] ?? -1) === 0
    && in_array($f['unreadable_worker']['event'] ?? '', ['failed', 'pending'], true) && !empty($f['unreadable_worker']['retried']),
    'the registry unreadable at the send: nothing sent, nobody paged, the event retried', $j($f['unreadable_worker'] ?? null));
is_(($f['unreadable_run'] ?? null) === ['salesperson' => [null, true], 'department' => [false, true, 'not_interested']],
    'the registry unreadable in the follow-up run: a salesperson\'s follow-up waits (registry_unreadable); a department\'s passes every gate to the evaluator, as before (with no model reachable here, it declines)', $j($f['unreadable_run'] ?? null));

echo "\n17. A person's Inbox reply\n";
is_(($f['t17']['http'] ?? null) === [200, 200, 200]
    && ($f['t17']['sent'] ?? null) === [['ps-alpha', '256771910027'], ['ps-alpha', '256771910027'], ['ps-alpha', '256700000902']],
    'TEST 17: from the conversation\'s own number — with the assistant on, off, and to a DishNet number', $j($f['t17'] ?? null));

echo "\nThe watchdog\n";
is_(($f['watchdog'] ?? null) === ['customer' => 1, 'internal' => 0], 'a waiting customer is paged about; a DishNet number\'s chat, unanswered on purpose, is not', $j($f['watchdog'] ?? null));

echo "\nA department moved after verification\n";
is_(($f['gaps']['in'][1] ?? null) === 'accepted' && ($f['gaps']['queued'] ?? -1) === 0 && ($f['gaps']['gaps'] ?? null) === ['sales']
    && ($f['gaps']['refusal'] ?? null) === 'internal_numbers_incomplete' && ($f['gaps']['department'] ?? 'x') === '',
    'its number counts as unverified again: the salesperson numbers stop answering and sending; the departments go on', $j($f['gaps'] ?? null));
is_(strpos((string)($f['gaps_note'][0] ?? ''), 'ok: ps-sales-new is now the sales number. Verify the department number on the Salesperson numbers card (sales) for the instance it now has') === 0
    && strpos((string)($f['gaps_note'][1] ?? ''), 'ok: ps-sales is now the sales number.') === 0 && strpos((string)($f['gaps_note'][1] ?? ''), 'Verify') === false,
    'the admin who moves it is told to verify the number again — and is not, once it is back', $j($f['gaps_note'] ?? null));
is_(strpos((string)($f['gaps_save'][0] ?? ''), 'ok: WhatsApp numbers saved. Verify the department number on the Salesperson numbers card (sales)') === 0
    && ($f['gaps_save'][1] ?? null) === 'ok: WhatsApp numbers saved.',
    'the same move saved with the numbers form: told likewise — and not once it is back', $j($f['gaps_save'] ?? null));
is_(($f['gaps_warn'] ?? null) === 1 && ($f['gaps_warn_after'] ?? null) === 0,
    'a process that finds the department numbers incomplete says so in its log, once — and nothing once they are complete', $j([$f['gaps_warn'] ?? null, $f['gaps_warn_after'] ?? null]));
if (($f['gaps_send'] ?? null) !== 'no open zone') {
    is_(($f['gaps_send'] ?? null) === ['desk' => [['ps-supacc', '256771910040']], 'held_sent' => 0, 'held_open' => 11],
        'eleven follow-ups held on a salesperson\'s number take none of the sender\'s ten places: the desk\'s still goes; they stay approved', $j($f['gaps_send'] ?? null));
    is_(($f['gaps_unheld'] ?? null) === ['variants' => [1, 1], 'sent' => 0, 'pre' => [null, 'approved'], 'at_send' => [null, 'approved']],
        'and reached anyway, a reason that clears by itself keeps a draft approved — at the sender\'s pre-check and at the send itself', $j($f['gaps_unheld'] ?? null));
}
is_(strpos((string)($f['release_a']['verify'] ?? ''), 'ok: Verified: the sales number') === 0 && ($f['release_a']['rows'] ?? null) === ['+256700000903', null]
    && ($f['release_a']['gaps'] ?? null) === [] && ($f['release_a']['trail'] ?? 0) === 1,
    'sales moved onto support\'s instance: verifying sales takes the number from support, which is covered now — on the record, never a dead end', $j($f['release_a'] ?? null));
is_(strpos((string)($f['release_a_back'][0] ?? ''), 'ok:') === 0 && strpos((string)($f['release_a_back'][1] ?? ''), 'ok:') === 0 && ($f['release_a_back'][2] ?? null) === [],
    'and moved back, both verified again', $j($f['release_a_back'] ?? null));
is_(strpos((string)($f['release_b']['verify'] ?? ''), 'ok: Verified: the support number') === 0 && ($f['release_b']['rows'] ?? null) === ['+256700000913', null]
    && strpos((string)($f['release_b_back'][0] ?? ''), 'ok:') === 0 && ($f['release_b_back'][1] ?? null) === [],
    'a number a retired line once held is taken from it when a department is verified with it', $j([$f['release_b'] ?? null, $f['release_b_back'] ?? null]));
is_(strpos((string)($f['release_c'][0] ?? ''), 'no:') === 0 && strpos((string)($f['release_c'][0] ?? ''), "already channel support's") !== false
    && array_slice((array)($f['release_c'] ?? []), 1) === ['+256700000900', '+256700000903']
    && strpos((string)($f['release_d'][0] ?? ''), 'no:') === 0 && strpos((string)($f['release_d'][0] ?? ''), "already channel sales-001's") !== false
    && array_slice((array)($f['release_d'] ?? []), 1) === ['+256700000900', '+256700000901'],
    'and nothing else gives one up: a department still taking its instance\'s inbound under it, or a salesperson\'s number, refuses — both keep their numbers', $j([$f['release_c'] ?? null, $f['release_d'] ?? null]));
is_(strpos((string)($f['release_e']['verify'] ?? ''), 'ok: Verified: the sales number') === 0 && ($f['release_e']['rows'] ?? null) === ['+256700000903', null]
    && ($f['release_e_back'] ?? null) === [true, true, []],
    'support moved to another instance since its number was verified: verifying sales with that number takes it; moved back, both verified again', $j([$f['release_e'] ?? null, $f['release_e_back'] ?? null]));
is_(strpos((string)($f['lid']['dept'] ?? ''), "no: Evolution reports this instance's owner with no phone number (a WhatsApp @lid), so it cannot be verified") === 0
    && strpos((string)($f['lid']['line'] ?? ''), "no: Evolution reports this instance's owner with no phone number (a WhatsApp @lid), so it cannot be verified") === 0
    && strpos((string)($f['lid']['none'] ?? ''), 'no: Evolution reports no phone number for this instance yet — try again in a minute') === 0
    && ($f['lid']['rows'] ?? null) === ['+256700000900', '+256700000902'] && ($f['lid']['gaps'] ?? null) === ['sales'] && ($f['lid_back'] ?? null) === [],
    'an instance whose owner is an @lid has no phone number to verify: a department\'s and a salesperson\'s are refused, saying so (and one with no owner yet, to try again), both rows keep their numbers, and the gap stays open', $j([$f['lid'] ?? null, $f['lid_back'] ?? null]));

echo "\nA number re-paired, or on an instance nobody routes\n";
is_(($f['reported']['guard'] ?? 1) === 0 && ($f['reported']['recorded'] ?? false) === true && ($f['reported']['lid'] ?? true) === false,
    'the webhook guard records the number Evolution reports for every instance — never an @lid owner', $j($f['reported'] ?? null));
is_($none($f['reported']['to_line'] ?? []) && $none($f['reported']['to_desk'] ?? []),
    'support re-paired to another phone, nobody having verified it: its new number is refused on a salesperson\'s number and on the sales desk', $j($f['reported'] ?? null));
is_(($f['reported']['gaps'] ?? null) === ['support'] && ($f['reported']['alpha'] ?? null) === 'internal_numbers_incomplete'
    && ($f['reported']['bravo'] ?? null) === 'channel_unverified' && ($f['reported']['desk'] ?? 'x') === '',
    'and until each is verified again: support counts as unverified, so no salesperson number sends; Bravo\'s re-paired number is unverified itself; the departments go on', $j($f['reported'] ?? null));
is_(($f['reported']['customer']['queued'] ?? -1) === 0 && ($f['reported']['customer']['stored'] ?? 0) === 1
    && array_key_exists('category', (array)($f['reported']['customer'] ?? [])) && $f['reported']['customer']['category'] === null,
    'a customer writing to Alpha meanwhile is kept for the team, unanswered, and not filed as DishNet\'s', $j($f['reported']['customer'] ?? null));
is_(($f['reported_readers'] ?? null) === ['card' => ['support'], 'p3' => true, 'set_config' => [1, true], 'log' => [1, true]],
    'and what the operator reads says the same: the card\'s gate and note, P3 and set_config all count support as not verified; a process built as the webhook and the workers are logs it, once', $j($f['reported_readers'] ?? null));
is_(strpos((string)($f['reported_verify_dept'][0] ?? ''), 'ok: Verified: the support number') === 0
    && array_slice((array)($f['reported_verify_dept'] ?? []), 1) === [[], '', 'channel_unverified'],
    'support verified again on the card, no guard run between: what Evolution reports now is recorded at once — complete; Bravo, still re-paired, is not', $j($f['reported_verify_dept'] ?? null));
is_(strpos((string)($f['reported_verify_line'][0] ?? ''), 'ok: Verified:') === 0 && ($f['reported_verify_line'][1] ?? null) === '',
    'and Bravo verified again on the card: its number is what Evolution reports now — it may send again', $j($f['reported_verify_line'] ?? null));
is_(strpos((string)($f['reported_switch'][0] ?? ''), 'ok:') === 0
    && strpos((string)($f['reported_switch'][1] ?? ''), 'no: Evolution now reports another number for this instance: verify the number again first') === 0
    && strpos((string)($f['reported_switch'][2] ?? ''), 'ok:') === 0,
    'Bravo switched off, then on while its record says the phone is re-paired: Switch on refuses, as the policy would; verified again, it is on', $j($f['reported_switch'] ?? null));
is_(($f['reported_back'] ?? null) === [0, [], '', ''], 'paired back: still complete after the guard\'s next run (the verifications before it had already restored the record)', $j($f['reported_back'] ?? null));
is_(($f['reported_shared'] ?? null) === [0, 'channel_unverified', [], '', ''],
    'one phone on two instances — Alpha\'s paired with the sales desk\'s phone, its name sorting first: Alpha is unverified; the desk and Bravo go on', $j($f['reported_shared'] ?? null));
is_(($f['reported_cleared'] ?? null) === [0, [], '', '', ''],
    'paired back, nobody verifying anything: whole again at the guard\'s next read alone', $j($f['reported_cleared'] ?? null));
is_(($f['reported_lid'] ?? null) === [0, 'channel_unverified', ['sales'], 'internal_numbers_incomplete', true, true, false],
    'Alpha\'s instance and the sales desk\'s re-paired onto @lid owners (no phone number): Alpha unverified, sales a gap so no salesperson number sends, and the card flags both rows — never a number kept as verified', $j($f['reported_lid'] ?? null));
is_(($f['reported_lid_card']['reasons'] ?? null) === ['no_phone', 'no_phone', '']
    && strpos((string)($f['reported_lid_card']['switch'][0] ?? ''), 'ok:') === 0
    && strpos((string)($f['reported_lid_card']['switch'][1] ?? ''), "no: Evolution reports this instance's owner with no phone number (a WhatsApp @lid), so its number cannot be confirmed") === 0
    && strpos((string)($f['reported_lid_card']['switch'][2] ?? ''), 'ok:') === 0,
    'the card says why — an owner with no phone number, not "another number" — and Switch on refuses Alpha, saying so; paired back, it is on again', $j($f['reported_lid_card'] ?? null));

echo "\nA number switched on again under an older read\n";
is_(($f['stale']['salesperson'] ?? null) === ['channel_paused', 'instance_changed', true, ''],
    'paused when read, switched on since: it waits for the next read (instance_changed) — never closed; read afresh, it may send', $j($f['stale']['salesperson'] ?? null));
is_(($f['stale']['department'] ?? null) === ['no_instance', false, ''],
    'a department with no instance configured is closed (no_instance), as the sender always closed it; the others go on', $j($f['stale']['department'] ?? null));

echo "\nRegistry OFF: 5.18.89 exactly\n";
is_(($f['registry_off']['in'] ?? null) === [200, 'accepted', 'sales'] && ($f['registry_off']['queued'] ?? 0) === 1
    && ($f['registry_off']['replies'] ?? null) === [['ps-sales', '256700000902']] && ($f['registry_off']['policy'] ?? null) === [false, '', false, false],
    'a DishNet line writing to the sales number is answered as before; the policy is inert', $j($f['registry_off'] ?? null));
is_(($f['registry_off_guard'] ?? null) === [0, []], 'and the webhook guard records nothing Evolution reports', $j($f['registry_off_guard'] ?? null));

if (isset($f['media'])) {
    echo "\nMedia (development tree)\n";
    is_(($f['media']['outcome'] ?? null) === 'accepted' && ($f['media']['media_queued'] ?? -1) === 0 && ($f['media']['events'] ?? -1) === 0,
        'a DishNet line\'s voice note is never queued for the media worker', $j($f['media']));
    is_(($f['media_control']['media_queued'] ?? 0) === 1 && ($f['media_control2']['media_queued'] ?? 0) === 1, 'the control: a customer\'s voice note on the same number is', $j([$f['media_control'] ?? null, $f['media_control2'] ?? null]));
    is_(($f['media_worker'] ?? null) === ['dishnet' => ['status' => 'skipped', 'failure_reason' => 'dishnet_number'],
                                         'no_phone' => ['status' => 'skipped', 'failure_reason' => 'no_phone'], 'fetched' => 0],
        'and the media worker settles one from a DishNet number, or with no phone on record, by its own name — nothing fetched', $j($f['media_worker'] ?? null));
    is_(($f['media_control3']['media_queued'] ?? 0) === 1 && ($f['media_read']['media']['status'] ?? 'skipped') !== 'skipped'
        && in_array($f['media_read']['event'] ?? '', ['pending', 'failed'], true) && !empty($f['media_read']['error']),
        'a conversation read that fails is not an answer: the file is not settled, and the event is retried', $j([$f['media_control3'] ?? null, $f['media_read'] ?? null]));
    is_(($f['media_store'] ?? null) === 'channel_unverified', 'the worker\'s client knows the numbers Evolution reports from its first use: a re-paired number is refused on every path', $j($f['media_store'] ?? null));
}

echo "\nIn process: InternalNumbers and AutomationPolicy\n";
$u = $f['unit'];
is_($u['classes'] === [true], 'the automated classes are ContactOptOut\'s reply and proactive — staff and transactional sends are never asked', $j($u['classes']));
is_($u['lines'] === ['dishnet_line', 'dishnet_line', 'dishnet_line', '', 'dishnet_line', '', '', '', 'sales-001'],
    'lines: every number not retired, departments included, configured ones too, written any way; never a retired one, another country\'s with the same last digits, a fragment or nothing', $j($u['lines']));
is_($u['people'] === ['line_owner', '', 'alert_number', 'alert_number', '', '', '', ''],
    'people: an active owner and the alert numbers, on a salesperson\'s number only; never an inactive owner, a retired number\'s owner or a dealer', $j($u['people']));
is_($u['gaps'] === [[], ['sales'], ['account'], []],
    'gaps: complete; a department moved to another instance; one with an instance of its own not verified; none configured', $j($u['gaps']));
is_($u['store_fails'] === ['', 'dishnet_line', ''], 'the staff list unreadable: nobody refused who is not a line — the lines still are', $j($u['store_fails']));
is_($u['inert'] === ['', '', false, ['', []]], 'registry off: nothing refused, nothing classified, no SQL', $j($u['inert']));
is_($u['kinds'] === [true, true, false, false, false, true, false, false], 'which reasons wait and which are silent — a department with no instance is closed, as the sender always closed it', $j($u['kinds']));
is_($u['recorded'] === [1, ['256700000913', ''], [false, true]],
    'the numbers Evolution reports are recorded as whole numbers — an @lid owner as reporting no phone number, never its digits; never a fragment', $j($u['recorded']));
is_($u['no_phone'] === [true, ['sales'], '', ['sales'], []],
    'an instance re-paired onto an @lid owner: no number, never a member; a gap for the policy and for every reader of gapsWithReported — gapsOf alone cannot see it', $j($u['no_phone']));
is_($u['shared'] === ['256700000900', '256700000900', [], '256700000901', '256700000901', ['sales']],
    'one phone on two instances: each instance recorded and compared with its own row, whichever sorts first', $j($u['shared']));
is_($u['unreadable'] === ['', 'registry_unreadable', '', [' AND c.channel IN (?,?,?)', ['sales', 'support', 'account']]],
    'a registry that cannot be read: the departments as configured, nothing automated elsewhere, nobody classified', $j($u['unreadable']));
is_($u['bad_column'] === 'refused', 'the SQL helper takes a column name only', $u['bad_column']);

echo "\nSouth Sudan and Domain B\n";
is_(($f['ss']['n_webhook'] ?? null) === [200, 'accepted', 1], 'South Sudan, every switch set: the alert number is answered as before', $j($f['ss']['n_webhook'] ?? null));
is_(($f['ss']['n_policy'] ?? null) === [false, false, false, '', ['', []]], 'and the registry and the policy never on', $j($f['ss']['n_policy'] ?? null));
is_(($f['ss']['n_channels_tool'] ?? null) === [0, true, true], 'tools/channels.php there says nothing about department numbers — there is no card to verify them on', $j($f['ss']['n_channels_tool'] ?? null));
is_(($f['ss']['n_guard'] ?? null) === [0, []], 'and its webhook guard records nothing Evolution reports', $j($f['ss']['n_guard'] ?? null));
is_($f['q_sandbox'] === false && $f['q_loaded'] === [], 'the whole scenario ran on a copy of the plugin without Domain B, and loaded nothing from it', $j([$f['q_sandbox'], $f['q_loaded']]));

// ═════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
// Weakened copies: each new guard removed or loosened, alone — each must be caught
// ═════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
echo "\nW. Weakened copies, each caught\n";
$dev = is_file($root . '/workers/MediaWorker.php');
$mutants = [
    ['the webhook queues a DishNet number\'s message', 'evo_webhook.php',
     "        if (\$_evoWhy !== '') {", "        if (false) {", 'internal',
     function (array $x) { return ($x['t4']['queued'] ?? 0) !== 0 || ($x['t6']['queued'] ?? 0) !== 0 || ($x['t4_shared_first']['inbound']['queued'] ?? 0) !== 0; }],
    ['the worker answers a DishNet number (its own check gone)', 'workers/AiReplyWorker.php',
     "            if (\$own !== '') {", "            if (false) {", 'internal',
     // Behaviour, not a log line: on a paused number the reply route would page a person about a DishNet chat.
     function (array $x) { return ($x['t20_paused']['escalations'] ?? 0) !== 0 || empty($x['t20_queued']['worker_guard']); }],
    ['nobody is ever DishNet\'s own (every layer at once)', 'lib/AutomationPolicy.php',
     "        if (\$this->mode !== 'on' || \$this->internal === null) return '';", "        return '';", 'internal',
     function (array $x) { return ($x['internal_totals']['to_internal'] ?? []) !== [] || ($x['t4']['queued'] ?? 0) !== 0; }],
    ['a switched-off line\'s number is forgotten', 'lib/InternalNumbers.php',
     "            if (!is_array(\$r) || (string)(\$r['status'] ?? '') === 'retired') continue;",
     "            if (!is_array(\$r) || (string)(\$r['status'] ?? '') !== 'active') continue;", 'internal',
     function (array $x) { return ($x['t_disabled_line']['queued'] ?? 0) !== 0; }],
    ['the department numbers are forgotten', 'lib/InternalNumbers.php',
     "            if (\$d !== '') \$lines[\$d] = (string)\$id;",
     "            if (\$d !== '' && !in_array((string)\$id, ['sales', 'support', 'account'], true)) \$lines[\$d] = (string)\$id;", 'internal',
     function (array $x) { return ($x['t6']['queued'] ?? 0) !== 0 || ($x['t5']['queued'] ?? 0) !== 0; }],
    ['the owners are forgotten', 'lib/InternalNumbers.php',
     "                    if (\$d !== '' && !isset(\$out[\$d])) \$out[\$d] = self::LINE_OWNER;", "", 'internal',
     function (array $x) { return ($x['t18']['owner']['queued'] ?? 0) !== 0; }],
    ['the department numbers need not be complete', 'lib/InternalNumbers.php',
     "            if (\$number === '' || \$for !== \$inst) \$gaps[] = \$dept;", "", 'gaps',
     function (array $x) { return ($x['setup']['on_no_departments'][1] ?? '') !== 'disabled' || ($x['gaps']['queued'] ?? 0) !== 0; }],
    ['a department number verified for another instance still counts', 'lib/InternalNumbers.php',
     "            if (\$number === '' || \$for !== \$inst) \$gaps[] = \$dept;", "            if (\$number === '') \$gaps[] = \$dept;", 'gaps',
     function (array $x) { return ($x['gaps']['queued'] ?? 0) !== 0 || ($x['gaps']['gaps'] ?? []) === []; }],
    ['the central guard lets a proactive send through', 'lib/EvolutionApiService.php',
     "    const AUTOMATED_CLASSES = ['reply', 'proactive'];", "    const AUTOMATED_CLASSES = ['reply'];", 'refusals',
     function (array $x) { return ($x['t25']['text'] ?? '') !== 'channel_disabled' || ($x['t25']['sent'] ?? 0) !== 0; }],
    ['the central guard reads the registry once (no fresh read)', 'lib/AutomationPolicy.php',
     "        if (\$fresh && \$this->pdo !== null) {", "        if (false) {", 'refusals',
     function (array $x) { return ($x['t25']['sent'] ?? 0) !== 0 || ($x['t25_worker']['customer'] ?? 0) !== 0 || ($x['t24']['sent'] ?? 0) !== 0; }],
    ['the central guard blocks a person\'s Inbox reply too', 'lib/EvolutionApiService.php',
     "        if (\$this->registry === null || !in_array(\$class, self::AUTOMATED_CLASSES, true)) return null;",
     "        if (\$this->registry === null) return null;", 'inbox',
     function (array $x) { return ($x['t17']['sent'] ?? []) !== [['ps-alpha', '256771910027'], ['ps-alpha', '256771910027'], ['ps-alpha', '256700000902']]; }],
    ['nothing automated is ever refused for its recipient', 'lib/AutomationPolicy.php',
     "        return \$this->senderClass(\$channel, \$phone) !== '' ? 'internal_recipient' : '';", "        return '';", 'followups',
     function (array $x) { return ($x['t10'] ?? null) === 'no open zone' ? null : ($x['t21']['closed'][0] ?? '') !== 'internal_recipient'; }],
    ['the assistant switch is ignored for automated sends', 'lib/AutomationPolicy.php',
     "        if (!\$ai) return 'channel_assistant_disabled';", "", 'assistant',
     function (array $x) { return ($x['t22']['closed'] ?? '') !== 'channel_assistant_disabled'; }],
    ['the follow-up run drafts on any number', 'cron/followup_run.php',
     "    \$why = \$autoPolicy->refusal((string)\$fu['channel'], (string)\$fu['phone']);", "    \$why = '';", 'assistant',
     function (array $x) { return ($x['t22']['closed'] ?? '') !== 'channel_assistant_disabled' || in_array('evaluated', array_column((array)($x['t22']['events'] ?? []), 0), true); }],
    ['the follow-up sender asks nothing before it sends', 'cron/followup_send.php',
     "    \$why = \$evo->automationPolicy()->refusal(\$chan, \$phone);", "    \$why = '';", 'assistant',
     // The central guard behind it still refuses (and the record says so): caught by WHERE it was refused.
     function (array $x) { return ($x['t9'] ?? null) === 'no open zone' ? null : ($x['t9']['sent'] ?? 0) !== 0
                                 || strpos((string)($x['t9']['detail'] ?? ''), 'nothing automated may go to this chat') === false; }],
    ['a paused number\'s follow-up is closed instead of waiting', 'lib/AutomationPolicy.php',
     "    const TRANSIENT = ['channel_paused', ", "    const TRANSIENT = ['channel_paused_never', ", 'followups',
     function (array $x) { return ($x['paused_run']['closed'] ?? null) !== null; }],
    ['the scan opens follow-ups on any number', 'cron/followup_scan.php',
     "\$holdSql .= \$autoSql;\n\$holdArgs = array_merge(\$holdArgs, \$autoArgs);", "", 'assistant',
     function (array $x) { return ($x['t9_scan'] ?? 0) !== 0; }],
    ['the scan opens follow-ups for a DishNet number', 'cron/followup_scan.php',
     "    \$own = \$autoPolicy->senderClass((string)\$conv['channel'], (string)\$conv['phone']);   // 5.18.90 (docs/65 §AD)", "    \$own = '';", 'followups',
     function (array $x) { return ($x['t21_scan']['opened'] ?? 0) !== 0; }],
    ['the worker asks nothing before the model', 'workers/AiReplyWorker.php',
     "            if (\$this->refusedByPolicy(\$this->evo->automationPolicy()->refusal(\$channel, \$phone), \$convId, \$channel, \$phone)) return;", "", 'refusals',
     function (array $x) { return ($x['unverified']['brain'] ?? 0) !== 0; }],
    ['the worker retries a send the policy refused', 'workers/AiReplyWorker.php',
     "        if (\\EvolutionApiService::policyRefused(\$send)) {", "        if (false) {", 'refusals',
     function (array $x) { return ($x['t25_worker']['event'] ?? '') !== 'done'; }],
    ['the watchdog pages about a DishNet number\'s chat', 'cron/wa_watchdog.php',
     "        if (\$_wd_policy->active()) {", "        if (false) {", 'watchdog',
     function (array $x) { return ($x['watchdog']['internal'] ?? 0) !== 0; }],
    ['a salesperson number is switched on before the department numbers', 'lib/SalesNumbersAdmin.php',
     "            if (\$gaps !== []) {", "            if (false) {", 'gaps',
     function (array $x) { return ($x['setup']['on_no_departments'][1] ?? '') !== 'disabled'; }],
    ['the registry is switched on before the department numbers', 'tools/set_config.php',
     "        if (\$mnGaps !== []) {", "        if (false) {", 'gaps',
     function (array $x) { return ($x['setup']['setcfg_refused'][0] ?? 0) !== 1; }],
    // ── added after the independent review (docs/65 §AD.6) ──
    ['a department\'s lead records its number', 'workers/AiReplyWorker.php',
     "            'source_number'       => \$ctx->isDepartment() ? null : \$ctx->businessNumber(),",
     "            'source_number'       => \$ctx->businessNumber(),", 'replies',
     function (array $x) { return !array_key_exists('number', (array)($x['dept_lead'] ?? [])) || !in_array($x['dept_lead']['number'], [null, 'absent'], true); }],
    ['numbers saved through Settings are not known', 'lib/InternalNumbers.php',
     "                    if (!array_key_exists(\$k, \$cfg) || \$cfg[\$k] === '' || \$cfg[\$k] === null) \$cfg[\$k] = \$v;", "", 'internal',
     function (array $x) { return ($x['stored_cfg']['line']['queued'] ?? 0) !== 0 || ($x['stored_cfg']['admin']['queued'] ?? 0) !== 0; }],
    ['the alert number counts as a person on every channel that is not a department', 'lib/AutomationPolicy.php',
     "        \$salesperson = isset(\$this->contexts[\$channel]) && !self::isDepartment(\$channel);",
     "        \$salesperson = !self::isDepartment(\$channel);", 'internal',
     function (array $x) { return ($x['setup']['people_scope'] ?? []) !== ['alert_number', '', '', '']; }],
    ['the scan opens follow-ups on channels the registry does not route', 'lib/AutomationPolicy.php',
     "            if (\$this->channelRefusal((string)\$id, false) === '') \$ids[] = (string)\$id;\n        }\n        if (\$ids === []) return [' AND 1 = 0', []];\n        return [' AND ' . \$column . ' IN ('",
     "            if (\$this->channelRefusal((string)\$id, false) !== '') \$ids[] = (string)\$id;\n        }\n        if (\$ids === []) return ['', []];\n        return [' AND ' . \$column . ' NOT IN ('", 'followups',
     function (array $x) { return ($x['scan_allow']['web'] ?? 0) !== 0; }],
    ['a staff chat takes the scan\'s places', 'cron/followup_scan.php',
     "if (\$autoPolicy->active()) \$autoSql .= \" AND COALESCE(c.category, '') <> 'staff'\";", "", 'followups',
     function (array $x) { return ($x['scan_staff']['customer'] ?? 0) !== 1; }],
    ['held drafts take the sender\'s places', 'cron/followup_send.php',
     "foreach (\$svc->approvedDrafts(10, \$heldSql, \$heldArgs) as \$d) {", "foreach (\$svc->approvedDrafts(10) as \$d) {", 'gaps',
     function (array $x) { return ($x['gaps_send'] ?? null) === 'no open zone' ? null : ($x['gaps_send']['desk'] ?? []) === []; }],
    ['the sender closes a draft that is only held', 'cron/followup_send.php',
     "        if (!AutomationPolicy::transient(\$why)) {\n            \$svc->close(\$fuId, \$why, 'nothing automated may go to this chat on channel '",
     "        if (true) {\n            \$svc->close(\$fuId, \$why, 'nothing automated may go to this chat on channel '", 'gaps',
     function (array $x) { return ($x['gaps_send'] ?? null) === 'no open zone' ? null : ($x['gaps_unheld']['pre'] ?? []) !== [null, 'approved']; }],
    ['the sender retries what the policy refused at the send', 'cron/followup_send.php',
     "    if (EvolutionApiService::policyRefused(\$res)) {   // refused at the send itself: nothing left",
     "    if (false) {   // refused at the send itself: nothing left", 'assistant',
     function (array $x) { return ($x['t9'] ?? null) === 'no open zone' ? null : ($x['t9_atsend']['closed'] ?? null) !== 'channel_assistant_disabled'; }],
    ['the run approves on its own without asking again', 'cron/followup_run.php',
     "    if (\$auto['auto'] && \$autoPolicy->refusal((string)\$fu['channel'], (string)\$fu['phone']) !== '') {", "    if (false) {", 'followups',
     function (array $x) { return ($x['run_recheck'] ?? null) === 'no open zone' ? null : ($x['run_recheck']['target']['status'] ?? '') !== 'pending'; }],
    ['the worker pages a person when the registry cannot be read', 'workers/AiReplyWorker.php',
     "        if (\$why === 'registry_unreadable') {", "        if (false) {", 'refusals',
     function (array $x) { return ($x['unreadable_worker']['escalations'] ?? 0) !== 0 || empty($x['unreadable_worker']['retried']); }],
    ['a registry that cannot be read counts as off in the crons', 'lib/AutomationPolicy.php',
     "            return new self('unreadable');", "            return self::inert();", 'refusals',
     function (array $x) { return ($x['unreadable_run']['salesperson'] ?? []) !== [null, true]; }],
    ['verifying a department never takes a number from a row that cannot own it', 'lib/ChannelRegistry.php',
     "            if (\$instance !== null && (in_array(\$other, \$releasable, true) || (string)\$holder['status'] === 'retired')) {",
     "            if (false) {", 'gaps',
     function (array $x) { return ($x['release_a']['gaps'] ?? []) !== [] || ($x['release_b']['rows'][0] ?? '') !== '+256700000913'; }],
    ['the guard records nothing Evolution reports', 'cron/wa_webhook_guard.php',
     "            InternalNumbers::recordReported(\$_wg_store, array_values(\$_wg_live));", "", 'reported',
     function (array $x) { return ($x['reported']['recorded'] ?? false) !== true || ($x['reported']['to_desk']['queued'] ?? 0) !== 0; }],
    ['the numbers Evolution reports are never consulted', 'lib/InternalNumbers.php',
     " || isset(\$this->reported()[\$d])) return self::DISHNET_LINE;", ") return self::DISHNET_LINE;", 'reported',
     function (array $x) { return ($x['reported']['to_desk']['queued'] ?? 0) !== 0 || ($x['reported']['to_line']['category'] ?? null) !== 'staff'; }],
    ['a department re-paired to another phone still counts as verified', 'lib/InternalNumbers.php',
     "            if (\$now !== '' && \$now !== \$digits) \$g[] = \$dept;   // re-paired since it was verified (NO_PHONE included)", "", 'reported',
     function (array $x) { return ($x['reported']['gaps'] ?? []) === []; }],
    ['a salesperson number re-paired to another phone still counts as verified', 'lib/AutomationPolicy.php',
     "            if (\$now !== '' && \$now !== (string)preg_replace('/\\D+/', '', (string)(\$row['business_number'] ?? ''))) return 'channel_unverified';", "", 'reported',
     function (array $x) { return ($x['reported']['bravo'] ?? '') !== 'channel_unverified'; }],
    ['the channels tool speaks of department numbers in South Sudan', 'tools/channels.php',
     "if (StaffJobsGate::applies(\$config, \$dataDir)) {", "if (true) {", 'south_sudan',
     function (array $x) { return ($x['ss']['n_channels_tool'][1] ?? false) !== true; }],
    ['a department with no instance waits for ever', 'lib/AutomationPolicy.php',
     "    const TRANSIENT = ['channel_paused', 'instance_changed', ", "    const TRANSIENT = ['channel_paused', 'no_instance', 'instance_changed', ", 'unit',
     function (array $x) { return ($x['unit']['kinds'][7] ?? true) !== false; }],
    // ── added after the second review (docs/65 §AD.7) ──
    ['the numbers Evolution reports are kept one per number, not one per instance', 'lib/InternalNumbers.php',
     "\$rows[mb_strtolower(\$name)] = ['number' => \$d,", "\$rows[\$d] = ['number' => \$d,", 'unit,reported',
     function (array $x) { return ($x['reported_shared'][1] ?? '') !== 'channel_unverified'
                                 || ($x['unit']['shared'] ?? null) !== ['256700000900', '256700000900', [], '256700000901', '256700000901', ['sales']]; }],
    ['a salesperson verification leaves the guard\'s older read in place', 'lib/SalesNumbersAdmin.php',
     "        \$this->refreshReported();\n        return self::yes('Verified: ' . (string)\$row['display_name']",
     "        return self::yes('Verified: ' . (string)\$row['display_name']", 'reported',
     function (array $x) { return ($x['reported_verify_line'][1] ?? '') !== ''; }],
    ['a department verification leaves the guard\'s older read in place', 'lib/SalesNumbersAdmin.php',
     "        \$this->refreshReported();\n        return self::yes('Verified: the ' . \$id . ' number",
     "        return self::yes('Verified: the ' . \$id . ' number", 'reported',
     function (array $x) { return ($x['reported_verify_dept'][1] ?? null) !== []; }],
    ['an @lid owner\'s digits are verified as a phone number', 'lib/SalesNumbersAdmin.php',
     "        \$raw = array_key_exists('jid_phone', \$live) ? (string)\$live['jid_phone'] : (string)(\$live['phone'] ?? '');",
     "        \$raw = (string)(\$live['phone'] ?? '');", 'gaps',
     function (array $x) { return ($x['lid']['gaps'] ?? []) !== ['sales'] || ($x['lid']['rows'] ?? []) !== ['+256700000900', '+256700000902']; }],
    ['verifying a department takes a number from any holder', 'lib/ChannelRegistry.php',
     "            if (\$instance !== null && (in_array(\$other, \$releasable, true) || (string)\$holder['status'] === 'retired')) {",
     "            if (\$instance !== null) {", 'gaps',
     function (array $x) { return strpos((string)($x['release_c'][0] ?? ''), 'no:') !== 0 || strpos((string)($x['release_d'][0] ?? ''), 'no:') !== 0; }],
    ['every other department may give its number up', 'lib/SalesNumbersAdmin.php',
     "            if (\$di === '' || \$covered || mb_strtolower(trim((string)(\$verified[\$d] ?? ''))) !== \$di) \$releasable[] = \$d;",
     "            \$releasable[] = \$d;", 'gaps',
     function (array $x) { return strpos((string)($x['release_c'][0] ?? ''), 'no:') !== 0; }],
    ['a department moved since its verification keeps its number', 'lib/SalesNumbersAdmin.php',
     "            if (\$di === '' || \$covered || mb_strtolower(trim((string)(\$verified[\$d] ?? ''))) !== \$di) \$releasable[] = \$d;",
     "            if (\$di === '' || \$covered) \$releasable[] = \$d;", 'gaps',
     function (array $x) { return ($x['release_e']['rows'] ?? []) !== ['+256700000903', null]; }],
    ['saving the numbers form says nothing of the department numbers', 'tabs/engage/wa_ai_setup.php',
     "\n        if (\$ok && \$_wSN !== null) {", "\n        if (false) {", 'gaps',
     function (array $x) { return strpos((string)($x['gaps_save'][0] ?? ''), 'Verify the department number') === false; }],
    ['incomplete department numbers are never said in the log', 'lib/AutomationPolicy.php',
     "        \$said = true;\n        error_log(", "        \$said = true;\n        if (false) error_log(", 'gaps',
     function (array $x) { return ($x['gaps_warn'] ?? 0) !== 1; }],
    ['a salesperson number switched on under an older read is closed as having no instance', 'lib/AutomationPolicy.php',
     "return \$dept ? 'no_instance' : 'instance_changed';", "return 'no_instance';", 'stale',
     function (array $x) { return ($x['stale']['salesperson'][1] ?? '') !== 'instance_changed'; }],
    ['a department with no instance waits as if it might get one', 'lib/AutomationPolicy.php',
     "return \$dept ? 'no_instance' : 'instance_changed';", "return 'instance_changed';", 'stale',
     function (array $x) { return ($x['stale']['department'][0] ?? '') !== 'no_instance'; }],
    ['the guard records what Evolution reports with the registry off', 'cron/wa_webhook_guard.php',
     "    if (\$_wg_regOn) {", "    if (true) {", 'registry,south_sudan',
     function (array $x) { return ($x['registry_off_guard'][1] ?? null) !== [] || ($x['ss']['n_guard'][1] ?? null) !== []; }],
    // ── added after the third review (docs/65 §AD.8) ──
    ['a department\'s follow-up waits while the registry cannot be read', 'lib/AutomationPolicy.php',
     "        if (\$this->mode === 'unreadable') return \$dept ? '' : 'registry_unreadable';",
     "        if (\$this->mode === 'unreadable') return 'registry_unreadable';", 'refusals',
     function (array $x) { return ($x['unreadable_run']['department'] ?? []) !== [false, true, 'not_interested']; }],
    ['the card counts only the verification, never a re-pair', 'lib/SalesNumbersAdmin.php',
     "            return \\InternalNumbers::gapsWithReported(\$this->reg->rows(), \\EvolutionApiService::configInstanceMap(\$this->config),\n                                                      \$this->reg->verifiedInstances(), \$this->store);",
     "            return \\InternalNumbers::gapsOf(\$this->reg->rows(), \\EvolutionApiService::configInstanceMap(\$this->config),\n                                                      \$this->reg->verifiedInstances());", 'reported',
     function (array $x) { return ($x['reported_readers']['card'] ?? null) !== ['support']; }],
    ['P3 counts only the verification, never a re-pair', 'tools/channels.php',
     "InternalNumbers::gapsWithReported(\$reg->rows(), \$cmap, \$reg->verifiedInstances(), InternalNumbers::reader(\$pdo));",
     "InternalNumbers::gapsOf(\$reg->rows(), \$cmap, \$reg->verifiedInstances());", 'reported',
     function (array $x) { return ($x['reported_readers']['p3'] ?? false) !== true; }],
    ['set_config counts only the verification, never a re-pair', 'tools/set_config.php',
     "\$mnReg->verifiedInstances(), \$mnStore)", "\$mnReg->verifiedInstances(), null)", 'reported',
     function (array $x) { return ($x['reported_readers']['set_config'] ?? null) !== [1, true]; }],
    ['an @lid owner is not recorded at all', 'lib/InternalNumbers.php',
     "            } elseif (array_key_exists('jid_phone', \$i) && self::digits((string)(\$i['phone'] ?? '')) !== '') {",
     "            } elseif (false) {", 'unit,reported',
     function (array $x) { return ($x['reported_lid'][1] ?? '') !== 'channel_unverified' || ($x['unit']['recorded'][2] ?? []) !== [false, true]; }],
    ['an instance reported with no phone number counts as nothing reported', 'lib/InternalNumbers.php',
     "                if (!empty(\$r['no_phone'])) {", "                if (false) {", 'unit,reported',
     function (array $x) { return ($x['reported_lid'][2] ?? []) !== ['sales'] || ($x['unit']['no_phone'][0] ?? false) !== true; }],
    ['the card never flags a verified number re-paired onto an @lid owner', 'lib/SalesNumbersAdmin.php',
     "        return self::lidOwner(\$live) ? 'no_phone' : '';", "        return '';", 'reported',
     function (array $x) { return ($x['reported_lid'][4] ?? null) !== true || ($x['reported_lid'][5] ?? null) !== true; }],
];
if ($dev) $mutants[] = ['the media worker fetches a DishNet number\'s file', 'workers/MediaWorker.php',
    "            if (\$own !== '') {", "            if (false) {", 'media',
    function (array $x) { return ($x['media_worker']['dishnet']['failure_reason'] ?? '') !== 'dishnet_number'; }];
if ($dev) $mutants[] = ['a conversation read that fails settles the file', 'workers/MediaWorker.php',
    "            \$q = \$this->pdo->prepare('SELECT phone FROM wa_conversations WHERE id = ?');   // a failure propagates: retried\n"
    . "            \$q->execute([\$convId]);\n            \$phone = (string)(\$q->fetchColumn() ?: '');",
    "            try { \$q = \$this->pdo->prepare('SELECT phone FROM wa_conversations WHERE id = ?'); \$q->execute([\$convId]);"
    . " \$phone = (string)(\$q->fetchColumn() ?: ''); } catch (\\Throwable \$e) { \$phone = ''; }", 'media',
    function (array $x) { return ($x['media_read']['media']['status'] ?? '') === 'skipped' || !in_array($x['media_read']['event'] ?? '', ['pending', 'failed'], true); }];
// ── added after the fourth check (docs/65 §AD.9) ──
$mutants[] = ['Switch on ignores what Evolution reports for the number\'s own instance', 'lib/SalesNumbersAdmin.php',
    "            if (\$now !== '' && \$now !== (string)preg_replace('/\\D+/', '', (string)\$row['business_number'])) {",
    "            if (false) {", 'reported',
    function (array $x) { return strpos((string)($x['reported_switch'][1] ?? ''), 'no:') !== 0 || strpos((string)($x['reported_lid_card']['switch'][1] ?? ''), 'no:') !== 0; }];
$mutants[] = ['the card calls an @lid owner another number', 'lib/SalesNumbersAdmin.php',
    "        return self::lidOwner(\$live) ? 'no_phone' : '';", "        return self::lidOwner(\$live) ? 'number' : '';", 'reported',
    function (array $x) { return ($x['reported_lid_card']['reasons'] ?? null) !== ['no_phone', 'no_phone', '']; }];
$mutants[] = ['a gap only the store shows is never logged by the webhook and the workers', 'lib/EvolutionApiService.php',
    "        \$policy->warnIfIncomplete();\n    }", "    }", 'reported',
    function (array $x) { return ($x['reported_readers']['log'] ?? null) !== [1, true]; }];
if ($dev) $mutants[] = ['the media worker\'s client asks the policy without the store', 'workers/MediaWorker.php',
    "            \$this->evoClient->useStaffStore(\$this->store);\n", "", 'media',
    function (array $x) { return ($x['media_store'] ?? '') !== 'channel_unverified'; }];
foreach ($mutants as [$name, $rel, $old, $new, $part, $caught]) {
    [$copy, $n] = sj_weakened_copy($root, $rel, $old, $new);
    if ($n !== 1) { is_(false, "mutant anchor is unique: {$name}", "count {$n} in {$rel}"); exec('rm -rf ' . escapeshellarg($copy)); continue; }
    $x = $drive($copy, $part);
    $caughtIt = isset($x['_raw']) ? false : $caught($x);
    if ($caughtIt === null) {   // never a quiet skip: the run crossed into the window where nothing can be sent
        is_(false, "mutant not run: {$name}", 'no time zone is inside the follow-up sending window now — run this test again outside it');
        exec('rm -rf ' . escapeshellarg($copy));
        continue;
    }
    is_($caughtIt, "mutant is caught: {$name}", isset($x['_raw']) ? $x['_raw'] : '');
    exec('rm -rf ' . escapeshellarg($copy));
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
