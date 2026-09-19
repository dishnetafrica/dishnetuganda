<?php
/**
 * test_bot_stays_awake.php — the assistant answers, or a person does; never neither.
 *
 * Conversation 419, 15 September: a prospect asked about plans at 21:56:28.
 * One second later the WhatsApp Business app sent its greeting from our
 * number. The webhook read that greeting as a colleague typing (fromMe, an
 * id nothing had claimed) and stood the AI down. The prospect's follow-up at
 * 21:58 — "Do you send starlink standard kit" — was logged as "human active,
 * skipping AI" and acked. Nobody answered it. The same greeting had reached
 * 49 conversations that week, each one taking the AI off the thread at the
 * exact moment the customer was asking.
 *
 * Around it, four smaller ways the bot went quiet: its own photo and flyer
 * sends echoed back unclaimed and read as a colleague; hand-over stamps and
 * retry times were read on the process clock, which is Africa/Kampala on the
 * scheduled path and UTC on the spawned one; a message whose retries were
 * spent went to the dead letters in silence; and notification rows stamped
 * three hours ahead made the watchdog think a waiting customer was answered.
 *
 * Each has a test here that fails on the 5.18.8 code.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

date_default_timezone_set('UTC');
$tmp = sys_get_temp_dir() . '/dn_awake_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);

require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/ConversationService.php';
require_once $root . '/lib/EventBus.php';
require_once $root . '/lib/UtcClock.php';
require_once $root . '/lib/AlertService.php';
require_once $root . '/lib/EvoWebhookGuard.php';
require_once $root . '/lib/DishNetAiBrain.php';
require_once $root . '/workers/WorkerBase.php';
require_once $root . '/workers/AiReplyWorker.php';

// ── Boot the fake Evolution server ──────────────────────────────────────────
array_map('unlink', glob(sys_get_temp_dir() . '/fake_evo_state_*.json') ?: []);
$router = $root . '/tests/fixtures/fake_evo_server.php';
$hit = function (int $port, string $p) {
    $ch = curl_init("http://127.0.0.1:{$port}{$p}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2, CURLOPT_PROXY => '']);
    $r = curl_exec($ch); curl_close($ch);
    return $r === false ? null : (string)$r;
};
$srv = null; $port = 0;
foreach (range(0, 9) as $slot) {
    $cand = 9700 + ((getmypid() + $slot * 23) % 80);
    $p = proc_open(sprintf('exec php -S 127.0.0.1:%d %s', $cand, escapeshellarg($router)),
                   [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    $ours = false;
    for ($i = 0; $i < 40; $i++) {
        $got = $hit($cand, '/__test/state');
        if ($got !== null) { $ours = strpos($got, 'FAKE-EVO-TEST') !== false; break; }
        usleep(100000);
    }
    if ($ours) { $srv = $p; $port = $cand; break; }
    proc_terminate($p); proc_close($p);
}
if ($port === 0) { fwrite(STDERR, "could not start the fake Evolution server\n"); exit(1); }
$evoState  = fn() => json_decode((string)$hit($port, '/__test/state'), true) ?: [];
$textCalls = fn() => $evoState()['text_calls'] ?? [];

putenv('DN_DATA_DIR=' . $tmp);
$store = SqliteStore::create($tmp);
$pdo   = $store->getPdo();
$svc   = new ConversationService($tmp, $pdo);
$bus   = new EventBus($pdo);

$base = [
    'evo_api_url'              => "http://127.0.0.1:{$port}",
    'evo_api_key'              => 'TESTKEY',
    'evo_instance_sales'       => 'dishnet_ug',
    'evo_instance_support'     => 'dishnet_ug',
    'ai_provider'              => 'openai',
    'openai_api_key'           => 'test-key-never-called',
    'wa_human_cooldown_minutes'=> 30,
    'ai_handover_message'      => 'Thank you for your patience — a colleague will be with you shortly.',
    'alert_whatsapp'           => '256700000999',
];

/** A brain that never leaves the process. */
class FakeBrain extends DishNetAiBrain
{
    public int $calls = 0;
    /** @var string|null null = the model is unreachable */
    public $answer = 'Yes — we stock the Starlink Standard Kit and deliver in Kampala.';
    public function isConfigured(): bool { return true; }
    public function reply(array $context): array
    {
        $this->calls++;
        if ($this->answer === null) throw new RuntimeException('model unreachable (test)');
        return ['reply' => $this->answer];
    }
    public function getLastUsage(): array { return []; }
}
$mkWorker = function (array $cfg, $answer = 'keep') use ($store): array {
    $w = new AiReplyWorker($store, $cfg, 30, 10);
    $b = new FakeBrain($cfg);
    if ($answer !== 'keep') $b->answer = $answer;
    $rp = new ReflectionProperty(AiReplyWorker::class, 'brain');
    $rp->setAccessible(true);
    $rp->setValue($w, $b);
    return [$w, $b];
};
$conv = function (string $phone, string $channel = 'sales') use ($svc): int {
    return (int)$svc->ensureConversation($phone, $channel, null, 'test')['id'];
};
$state = function (int $cid) use ($pdo): array {
    $st = $pdo->prepare("SELECT state, last_human_reply_at, last_agent_at, last_customer_at FROM wa_conversations WHERE id = ?");
    $st->execute([$cid]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
};
$eventRow = function (int $eid) use ($pdo): array {
    $st = $pdo->prepare("SELECT status, attempts, next_retry_at, error FROM events WHERE id = ?");
    $st->execute([$eid]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
};
$emitLikeWebhook = function (int $cid, string $phone, string $text, ?string $receivedAt = null) use ($bus): int {
    return $bus->emit('ai.reply', 'conversation', $cid, [
        'channel'           => 'sales',
        'whatsapp_instance' => 'dishnet_ug',
        'customer_phone'    => $phone,
        'message'           => $text,
        'push_name'         => 'Test',
        'wa_message_id'     => 'CUST-' . bin2hex(random_bytes(3)),
        'remote_jid'        => $phone . '@s.whatsapp.net',
        'received_at'       => $receivedAt ?? gmdate('c'),
    ], 3, 'test');
};
$handset = function (int $cid, string $text, string $id, string $sentAt = null) use ($svc): ?int {
    return $svc->storeMessage($cid, ['direction' => 'out', 'role' => 'agent', 'body' => $text,
        'agent_name' => 'Team', 'wa_message_id' => $id] + ($sentAt !== null ? ['sent_at' => $sentAt] : []));
};

// ═══════════════════════════════════════════════════════════════════════════
echo "\n1. The WhatsApp Business app's greeting is not a colleague\n";
$greet = 'Thanks for contacting us! We will get back to you shortly. Business hours 8am to 6pm.';
foreach ([1, 2, 3] as $i) {
    $handset($conv('25677200000' . $i), $greet, 'GREET-' . $i);
}
$cid4 = $conv('256772000004');
is_($svc->isCannedHandsetText($cid4, $greet),
    'the same sentence already sent to three other conversations this week is canned');
is_(!$svc->isCannedHandsetText($cid4, 'Let me check the Standard Kit stock for you and revert shortly.'),
    'a sentence never seen before is a person');

foreach ([1, 2, 3] as $i) $handset($conv('25677200000' . $i), 'Yes we do.', 'SHORT-' . $i);
is_(!$svc->isCannedHandsetText($cid4, 'Yes we do.'),
    'a short text repeated by hand is still a person each time',
    '"Ok" typed in four chats must not switch the pause off');

$two = 'Our office is closed today for the public holiday; we reopen tomorrow at 8am.';
foreach ([1, 2] as $i) $handset($conv('25677200000' . $i), $two, 'TWO-' . $i);
is_(!$svc->isCannedHandsetText($cid4, $two), 'two other conversations are not enough');

$old = 'Welcome to our WhatsApp line, an agent will respond to your message as soon as possible.';
foreach ([1, 2, 3] as $i) {
    $handset($conv('25677200000' . $i), $old, 'OLD-' . $i, gmdate('Y-m-d H:i:s', time() - 8 * 86400));
}
is_(!$svc->isCannedHandsetText($cid4, $old), 'rows older than the seven-day window do not count');

// What the webhook does with the verdict, on its real code
$hook = (string)file_get_contents($root . '/evo_webhook.php');
is_(strpos($hook, '$canned = $convSvc->isCannedHandsetText((int)$conv[\'id\'], $ownText);') !== false,
    'the webhook asks before it stores');
is_(preg_match('/\$stored\s*!==\s*null\s*&&\s*!\$canned/', $hook) === 1,
    'and stands the AI down only for a message that is not canned',
    'this is the line that silenced the AI 49 times in a week');
is_(strpos($hook, "\$canned ? ConversationService::AGENT_AUTO_REPLY : 'Team'") !== false,
    "the row is labelled as the app's, not a colleague's");

// The same steps the webhook takes, for the fourth conversation
$canned = $svc->isCannedHandsetText($cid4, $greet);
$stored = $svc->storeMessage($cid4, [
    'direction' => 'out', 'role' => 'agent', 'body' => $greet,
    'agent_name' => $canned ? ConversationService::AGENT_AUTO_REPLY : 'Team',
    'wa_message_id' => 'GREET-4',
    'metadata' => json_encode(['channel' => 'sales', 'source' => 'handset', 'canned' => $canned]),
]);
if ($stored !== null && !$canned) $svc->markHumanHandling($cid4);
is_(($state($cid4)['state'] ?? '') !== 'human_active', 'the fourth customer keeps the AI');
$who = $pdo->query("SELECT agent_name FROM wa_messages WHERE id = " . (int)$stored)->fetchColumn();
is_($who === ConversationService::AGENT_AUTO_REPLY, 'the greeting is stored, under the auto-reply label');
is_(!$svc->humanRepliedSince($cid4, gmdate('Y-m-d H:i:s', time() - 60)),
    'and it does not count as a colleague having replied');

$wsrc = (string)file_get_contents($root . '/workers/AiReplyWorker.php');
is_(strpos($wsrc, '$who === ConversationService::AGENT_AUTO_REPLY') !== false
    && strpos($wsrc, '[automatic message from our WhatsApp app] ') !== false,
    'history names it as an automatic message, not as a colleague\'s promise');

// ═══════════════════════════════════════════════════════════════════════════
echo "\n2. The bot's own picture does not stand it down\n";
file_put_contents($tmp . '/wa_flyer.jpg', 'FAKE-JPEG-BYTES-' . str_repeat('x', 64));
$cid5 = $conv('256772000005');
[$w5] = $mkWorker($base + ['wa_flyer_caption' => 'DishNet — Starlink plans']);
$m = new ReflectionMethod(AiReplyWorker::class, 'maybeSendFlyer');
$m->setAccessible(true);
$m->invoke($w5, $cid5, 'sales', '256772000005');
$media = $evoState()['media_calls'] ?? [];
is_(count($media) === 1, 'the flyer went out once');
$mediaId = 'FAKE-EVO-MEDIA-1';
$row = $pdo->query("SELECT wa_message_id FROM wa_messages WHERE conversation_id = {$cid5} AND media_url = 'flyer:plans'")->fetchColumn();
is_((string)$row === $mediaId, 'the row carries the id Evolution gave the picture', 'got: ' . var_export($row, true));
$seen = (int)$pdo->query("SELECT COUNT(*) FROM evo_webhook_seen WHERE message_id = '{$mediaId}'")->fetchColumn();
is_($seen === 1, 'and the id is claimed in the webhook\'s dedupe table');
$guard = new EvoWebhookGuard($pdo, $base);
is_(!$guard->claim($mediaId, 'dishnet_ug', 'messages.upsert'),
    'so the echo loses the idempotency race and never reaches the fromMe branch');
$echo = $svc->storeMessage($cid5, ['direction' => 'out', 'role' => 'agent', 'body' => 'DishNet — Starlink plans',
    'agent_name' => 'Team', 'wa_message_id' => $mediaId]);
is_($echo === null, 'and even a store of the echo dedupes to null');
is_(($state($cid5)['state'] ?? '') !== 'human_active', 'the AI keeps the conversation');
is_(substr_count($wsrc, '$this->claimOwnEcho($send, $channel,') === 4,
    'reply, photo, document and flyer all claim their echo the same way',
    'count: ' . substr_count($wsrc, '$this->claimOwnEcho($send, $channel,'));

// ═══════════════════════════════════════════════════════════════════════════
echo "\n3. Stored clocks are read as UTC on both worker paths\n";
$handling = function (array $cfg, int $cid) use ($store): bool {
    $w = new AiReplyWorker($store, $cfg);
    $m = new ReflectionMethod(AiReplyWorker::class, 'humanIsHandling');
    $m->setAccessible(true);
    return (bool)$m->invoke($w, $cid);
};
$cid6 = $conv('256772000006');
$svc->markHumanHandling($cid6);
date_default_timezone_set('Africa/Kampala');
is_($handling($base, $cid6),
    'under Africa/Kampala (the scheduled path) a colleague who replied a moment ago still pauses the AI',
    'strtotime() read the UTC stamp as Kampala time: three hours ago, pause over');
date_default_timezone_set('UTC');
is_($handling($base, $cid6), 'and under UTC (the spawned path) the same');

date_default_timezone_set('Africa/Kampala');
is_(UtcClock::parse('2026-09-15 19:00:00') === gmmktime(19, 0, 0, 9, 15, 2026),
    'a bare stamp reads as UTC whatever the process zone');
is_(UtcClock::parse('2026-09-15T19:00:00+00:00') === gmmktime(19, 0, 0, 9, 15, 2026),
    'an ISO stamp with its own zone is honoured');
is_(UtcClock::parse('2026-09-15T22:00:00+03:00') === gmmktime(19, 0, 0, 9, 15, 2026),
    'including a non-UTC one');
is_(UtcClock::parse('') === 0 && UtcClock::parse(null) === 0 && UtcClock::parse('never') === 0,
    'empty and unreadable read as 0');
is_(UtcClock::stamp(gmmktime(19, 0, 0, 9, 15, 2026)) === '2026-09-15 19:00:00', 'stamp() is the storage form');

$rows = [
    ['id' => 1, 'last_customer_at' => gmdate('Y-m-d H:i:s', time() - 120),     'last_agent_at' => ''],
    ['id' => 2, 'last_customer_at' => gmdate('Y-m-d H:i:s', time() - 15 * 60), 'last_agent_at' => ''],
    ['id' => 3, 'last_customer_at' => gmdate('Y-m-d H:i:s', time() - 15 * 60), 'last_agent_at' => gmdate('Y-m-d H:i:s', time() - 60)],
];
$ids = array_map(fn($r) => (int)$r['id'], AlertService::findUnanswered($rows, [], time(), 10));
is_($ids === [2],
    'the watchdog under Kampala pages for the customer who has waited 15 minutes and nobody else',
    'got: ' . json_encode($ids) . ' — the two-minute customer read as three hours');

$eidT = $bus->emit('ai.reply', 'conversation', 0, ['probe' => 1], 3, 'test');
$dead = $bus->fail($eidT, 'boom');
$nr   = UtcClock::parse($eventRow($eidT)['next_retry_at'] ?? '');
is_($dead === false, 'a first failure is not dead');
is_(abs($nr - (time() + 10)) <= 3,
    'its retry is 10 seconds ahead in UTC, not three hours',
    'next_retry_at is ' . ($nr - time()) . 's from now');
date_default_timezone_set('UTC');

// ═══════════════════════════════════════════════════════════════════════════
echo "\n4. A question that arrives during a colleague's pause is parked, not dropped\n";
$cid7 = $conv('256772000007');
$ph7  = '256772000007';
$svc->markHumanHandling($cid7);                       // a colleague replied just now
$svc->storeMessage($cid7, ['direction' => 'in', 'role' => 'customer', 'body' => 'Do you send the Starlink standard kit?', 'wa_message_id' => 'C7-1']);
$eid7 = $emitLikeWebhook($cid7, $ph7, 'Do you send the Starlink standard kit?', gmdate('c', time() - 5));
$before = count($textCalls());
[$w, $b] = $mkWorker($base);
$r = $w->run();
is_(($r['deferred'] ?? 0) === 1 && ($r['processed'] ?? 0) === 0 && ($r['failed'] ?? 0) === 0,
    'the worker parks it: deferred 1, nothing processed, nothing failed', json_encode($r));
$ev = $eventRow($eid7);
is_(($ev['status'] ?? '') === 'pending' && (int)($ev['attempts'] ?? -1) === 0,
    'the event is pending again with no attempt spent', json_encode($ev));
is_(UtcClock::parse($ev['next_retry_at'] ?? '') >= time() + 25, 'and due later, not now');
is_(stripos((string)($ev['error'] ?? ''), 'parked') !== false, 'the queue view says why');
is_($b->calls === 0, 'the model was not asked');
is_(count($textCalls()) === $before, 'the customer was not messaged');

echo "\n   (a) a colleague answers it while it is parked\n";
// the colleague types their answer a few seconds after the question arrived
$handset($cid7, 'Yes, we do — the Standard Kit is in stock, delivery is two days.', 'TEAM-7-1',
         gmdate('Y-m-d H:i:s', time() - 3));
$svc->markHumanHandling($cid7);
// time passes: the pause ends, the event comes due
$pdo->exec("UPDATE wa_conversations SET last_human_reply_at = '" . gmdate('Y-m-d H:i:s', time() - 31 * 60) . "' WHERE id = {$cid7}");
$pdo->exec("UPDATE events SET next_retry_at = '" . gmdate('Y-m-d H:i:s', time() - 1) . "' WHERE id = {$eid7}");
[$w, $b] = $mkWorker($base);
$r = $w->run();
is_(($r['processed'] ?? 0) === 1 && ($eventRow($eid7)['status'] ?? '') === 'done',
    'the parked event is acked', json_encode($r));
is_($b->calls === 0, 'the model was not asked — the colleague answered');
is_(count($textCalls()) === $before, 'and the customer is not answered twice');

echo "\n   (b) nobody answers it\n";
$svc->storeMessage($cid7, ['direction' => 'in', 'role' => 'customer', 'body' => 'And how much is it?', 'wa_message_id' => 'C7-2']);
$svc->markHumanHandling($cid7);                       // the colleague is active again
$eid7b = $emitLikeWebhook($cid7, $ph7, 'And how much is it?');
[$w, $b] = $mkWorker($base);
$r = $w->run();
is_(($r['deferred'] ?? 0) === 1, 'parked again', json_encode($r));
$pdo->exec("UPDATE wa_conversations SET last_human_reply_at = '" . gmdate('Y-m-d H:i:s', time() - 31 * 60) . "' WHERE id = {$cid7}");
$pdo->exec("UPDATE events SET next_retry_at = '" . gmdate('Y-m-d H:i:s', time() - 1) . "' WHERE id = {$eid7b}");
[$w, $b] = $mkWorker($base);
$r = $w->run();
is_(($r['processed'] ?? 0) === 1 && $b->calls === 1,
    'the pause over and nobody having answered, the model is asked', json_encode($r));
$calls = $textCalls();
$last  = end($calls) ?: [];
is_(($last['number'] ?? '') === $ph7 && ($last['text'] ?? '') === $b->answer,
    'and the customer gets the answer', json_encode($last));
$assistantRows = (int)$pdo->query("SELECT COUNT(*) FROM wa_messages WHERE conversation_id = {$cid7} AND role = 'assistant'")->fetchColumn();
is_($assistantRows === 1, 'recorded once');

echo "\n   (c) a question parked for hours is dropped rather than answered late\n";
$cid8 = $conv('256772000008');
$svc->markHumanHandling($cid8);
$eid8 = $emitLikeWebhook($cid8, '256772000008', 'Hello, anyone there?', gmdate('c', time() - 3 * 3600));
$n = count($textCalls());
[$w, $b] = $mkWorker($base);
$r = $w->run();
is_(($r['processed'] ?? 0) === 1 && ($r['deferred'] ?? 0) === 0 && ($eventRow($eid8)['status'] ?? '') === 'done',
    'three hours behind a still-active colleague: acked', json_encode($r));
is_($b->calls === 0 && count($textCalls()) === $n, 'not answered');

echo "\n   (d) cooldown 0 means the AI never stands down\n";
$cid9 = $conv('256772000009');
$handset($cid9, 'Checking that for you now.', 'TEAM-9-1');
$svc->markHumanHandling($cid9);
$eid9 = $emitLikeWebhook($cid9, '256772000009', 'Is the Mini Kit available?');
[$w0, $b0] = $mkWorker(array_replace($base, ['wa_human_cooldown_minutes' => 0]));
$r = $w0->run();
is_(($r['processed'] ?? 0) === 1 && $b0->calls === 1,
    'it reads what the colleague said and answers', json_encode($r));

// ═══════════════════════════════════════════════════════════════════════════
echo "\n5. When every retry has failed, the customer and the team are told\n";
$cid10 = $conv('256772000010');
$ph10  = '256772000010';
$eid10 = $emitLikeWebhook($cid10, $ph10, 'Hello?');
$pdo->exec("UPDATE events SET attempts = 4 WHERE id = {$eid10}");   // one attempt left of five
$n = count($textCalls());
[$w, $b] = $mkWorker($base, null);                                  // the model is unreachable
$r = $w->run();
is_(($r['failed'] ?? 0) === 1 && ($eventRow($eid10)['status'] ?? '') === 'dead',
    'the fifth failure makes the event dead', json_encode($r) . ' ' . json_encode($eventRow($eid10)));
$new = array_slice($textCalls(), $n);
$toCustomer = array_values(array_filter($new, fn($c) => ($c['number'] ?? '') === $ph10));
$toTeam     = array_values(array_filter($new, fn($c) => ($c['number'] ?? '') === $base['alert_whatsapp']));
is_(count($toCustomer) === 1 && ($toCustomer[0]['text'] ?? '') === $base['ai_handover_message'],
    'the customer gets the holding line, once', json_encode($new));
is_(count($toTeam) === 1 && stripos((string)($toTeam[0]['text'] ?? ''), 'needs a human') !== false
    && stripos((string)($toTeam[0]['text'] ?? ''), 'attempts') !== false,
    'the team is paged, with the reason', json_encode($toTeam));
is_(($state($cid10)['state'] ?? '') === 'needs_human', 'the thread is marked for a person');

$cid11 = $conv('256772000011');
$eid11 = $emitLikeWebhook($cid11, '256772000011', 'Hi');
$n = count($textCalls());
[$w, $b] = $mkWorker($base, null);
$r = $w->run();
is_(($r['failed'] ?? 0) === 1 && ($eventRow($eid11)['status'] ?? '') === 'failed' && count($textCalls()) === $n,
    'a first failure retries quietly — nobody is messaged', json_encode($eventRow($eid11)));

is_(strpos((string)file_get_contents($root . '/run_worker.php'), 'deferred=%d') !== false,
    'the spawned worker\'s summary line reports parked events');

// ═══════════════════════════════════════════════════════════════════════════
echo "\n6. Rows stamped on the wrong clock are repaired\n";
$cid12 = $conv('256772000012', 'accounts');
$ahead = $svc->storeMessage($cid12, ['direction' => 'out', 'role' => 'agent', 'body' => 'Your invoice is due.',
    'agent_name' => 'DishNet Plugin', 'sent_at' => gmdate('Y-m-d H:i:s', time() + 3 * 3600)]);   // date() under Kampala
$svc->storeMessage($cid12, ['direction' => 'in', 'role' => 'customer', 'body' => 'I paid already.', 'wa_message_id' => 'C12-1']);
$s = $state($cid12);
is_(UtcClock::parse($s['last_agent_at']) > UtcClock::parse($s['last_customer_at']),
    'before: last_agent_at sits in the future, so the watchdog reads this customer as answered');
$run = function (string $args) use ($root, $tmp): array {
    exec('DN_DATA_DIR=' . escapeshellarg($tmp) . ' php ' . escapeshellarg($root . '/tools/wa_clock_repair.php') . ' ' . $args . ' 2>&1', $out, $rc);
    return [$rc, implode("\n", $out)];
};
[$rc, $out] = $run('');
is_($rc === 0 && strpos($out, 'creation: 1') !== false && strpos($out, 'dry run') !== false,
    'the dry run reports the one row and changes nothing', $out);
$still = $pdo->query("SELECT sent_at > created_at FROM wa_messages WHERE id = " . (int)$ahead)->fetchColumn();
is_((int)$still === 1, 'the row is untouched after the dry run');
[$rc, $out] = $run('--fix');
is_($rc === 0 && strpos($out, 'repaired: 1 message rows') !== false, '--fix repairs it', $out);
$eq = $pdo->query("SELECT sent_at = created_at FROM wa_messages WHERE id = " . (int)$ahead)->fetchColumn();
is_((int)$eq === 1, 'sent_at now equals the row\'s own creation time');
$s = $state($cid12);
is_(UtcClock::parse($s['last_agent_at']) <= UtcClock::parse($s['last_customer_at']),
    'after: last_agent_at no longer sits in the future — the customer is waiting, as they really are');
is_(preg_match('/2567720000|invoice|paid/i', $out) === 0, 'the tool printed no phone or message content', $out);
[$rc, $out] = $run('');
is_($rc === 0 && strpos($out, 'nothing to repair') !== false, 'a second run finds nothing');

// ── Clean up ────────────────────────────────────────────────────────────────
if ($srv) { proc_terminate($srv); proc_close($srv); }
array_map('unlink', glob(sys_get_temp_dir() . '/fake_evo_state_*.json') ?: []);
putenv('DN_DATA_DIR');
exec('rm -rf ' . escapeshellarg($tmp));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
