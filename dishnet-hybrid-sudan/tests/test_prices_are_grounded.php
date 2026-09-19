<?php
/**
 * test_prices_are_grounded.php — a price the model was not given is not sent.
 *
 * 16 September, 08:39–08:43, the support number. A prospect asked what it
 * costs to get connected and was given a kit price, an installation price, a
 * total and two monthly plan prices. None of the four figures exists in
 * uCRM. The log for that conversation shows six support-channel turns and no
 * "catalogue loaded" line after any of them, while every sales-channel turn
 * that morning has one: the support number was being told, by
 * ai_sales_on_all_numbers, to answer pricing questions "from PLANS", and
 * PLANS was fetched for the sales number alone. Nothing in its data section
 * said the list was missing, and nothing between the model and the customer
 * looked at the numbers. ReplyPrivacyGuard has had the rule since B3 — money
 * the tools did not return and the prompt does not contain is refused — and
 * it was wired into the WASender webhook, never into this worker.
 *
 * Three things are asserted here, each on the real worker against a fake
 * Evolution server and a fake uCRM: every number that sells receives the
 * catalogue; a missing catalogue is stated to the model on every channel;
 * and a reply carrying money the model was not given never reaches the
 * customer, while a correct reply — catalogue prices, their sum, a yearly
 * multiple, a figure the customer typed — passes untouched.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

date_default_timezone_set('UTC');
$tmp = sys_get_temp_dir() . '/dn_prices_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);

require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/ConversationService.php';
require_once $root . '/lib/EventBus.php';
require_once $root . '/lib/ReplyPrivacyGuard.php';
require_once $root . '/lib/DishNetAiBrain.php';
require_once $root . '/workers/WorkerBase.php';
require_once $root . '/workers/AiReplyWorker.php';

// ── Two fake servers: Evolution (what the customer receives) and uCRM (the catalogue)
$hit = function (int $port, string $p) {
    $ch = curl_init("http://127.0.0.1:{$port}{$p}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2, CURLOPT_PROXY => '']);
    $r = curl_exec($ch); curl_close($ch);
    return $r === false ? null : (string)$r;
};
$boot = function (string $router, int $basePort, string $marker) use ($hit): array {
    foreach (range(0, 9) as $slot) {
        $cand = $basePort + ((getmypid() + $slot * 29) % 60);
        $p = proc_open(sprintf('exec php -S 127.0.0.1:%d %s', $cand, escapeshellarg($router)),
                       [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $ours = false;
        for ($i = 0; $i < 40; $i++) {
            $got = $hit($cand, '/__test/state');
            if ($got !== null) { $ours = strpos($got, $marker) !== false; break; }
            usleep(100000);
        }
        if ($ours) return [$p, $cand];
        proc_terminate($p); proc_close($p);
    }
    fwrite(STDERR, "could not start {$router}\n"); exit(1);
};
array_map('unlink', glob(sys_get_temp_dir() . '/fake_evo_state_*.json') ?: []);
[$evoSrv, $evoPort] = $boot($root . '/tests/fixtures/fake_evo_server.php', 9800, 'FAKE-EVO-TEST');
[$crmSrv, $crmPort] = $boot($root . '/tests/fixtures/fake_ucrm_shadow.php', 9870, 'FAKE-UCRM-SHADOW');
// The catalogue cache is keyed by base URL and lives a minute; a stale file
// from an earlier run on this port would be the same fixture, but be tidy.
array_map('unlink', glob(sys_get_temp_dir() . '/dishnet_catalogue_*.json') ?: []);

$evoState  = fn() => json_decode((string)$hit($evoPort, '/__test/state'), true) ?: [];
$textCalls = fn() => $evoState()['text_calls'] ?? [];
$lastTextTo = function (string $phone) use ($textCalls): array {
    $out = [];
    foreach ($textCalls() as $c) if (($c['number'] ?? '') === $phone) $out = $c;
    return $out;
};

putenv('DN_DATA_DIR=' . $tmp);
$store = SqliteStore::create($tmp);
$pdo   = $store->getPdo();
$svc   = new ConversationService($tmp, $pdo);
$bus   = new EventBus($pdo);

$ALERT = '256700000999';
$base = [
    'evo_api_url'              => "http://127.0.0.1:{$evoPort}",
    'evo_api_key'              => 'TESTKEY',
    'evo_instance_sales'       => 'dishnet_ug',
    'evo_instance_support'     => 'dishnet_ug',
    'evo_instance_account'     => 'dishnet_ug',
    'ai_provider'              => 'openai',
    'openai_api_key'           => 'test-key-never-called',
    'ai_sales_on_all_numbers'  => '1',
    'ai_currency'              => 'UGX',
    'alert_whatsapp'           => $ALERT,
    'wa_human_cooldown_minutes'=> 0,
];
$withCrm = $base + ['crm_base_url' => "http://127.0.0.1:{$crmPort}", 'crm_auth_token' => 'TESTKEY'];

/** A brain that returns a scripted answer and remembers what it was shown. */
class FakeBrain extends DishNetAiBrain
{
    public array $seen = [];
    public array $script = [];
    public function isConfigured(): bool { return true; }
    public function reply(array $context): array
    {
        $this->seen[] = $context;
        $this->lastSystemPrompt = $this->promptPreview($context);   // what reply() records
        $r = count($this->script) > 1 ? array_shift($this->script) : (string)($this->script[0] ?? 'Hello!');
        return ['reply' => $r, 'escalate' => false, 'escalate_reason' => '',
                'send_flyer' => false, 'lead' => null, 'photo' => '', 'doc' => ''];
    }
    public function getLastUsage(): array { return []; }
}
$mkWorker = function (array $cfg, array $script) use ($store): array {
    $w = new AiReplyWorker($store, $cfg, 30, 10);
    $b = new FakeBrain($cfg);
    $b->script = $script;
    $rp = new ReflectionProperty(AiReplyWorker::class, 'brain');
    $rp->setAccessible(true);
    $rp->setValue($w, $b);
    return [$w, $b];
};
$conv = fn(string $phone, string $channel) => (int)$svc->ensureConversation($phone, $channel, null, 'test')['id'];
$ask  = function (int $cid, string $phone, string $text, string $channel) use ($bus, $svc): int {
    $svc->storeMessage($cid, ['direction' => 'in', 'role' => 'customer', 'body' => $text,
                              'wa_message_id' => 'C-' . bin2hex(random_bytes(3))]);
    return $bus->emit('ai.reply', 'conversation', $cid, [
        'channel' => $channel, 'whatsapp_instance' => 'dishnet_ug', 'customer_phone' => $phone,
        'message' => $text, 'push_name' => 'Prospect', 'wa_message_id' => 'C-' . bin2hex(random_bytes(3)),
        'remote_jid' => $phone . '@s.whatsapp.net', 'received_at' => gmdate('c'),
    ], 3, 'test');
};
$state = function (int $cid) use ($pdo): string {
    return (string)$pdo->query("SELECT state FROM wa_conversations WHERE id = {$cid}")->fetchColumn();
};
$events = fn() => (array)($store->load('ai_security_events.json') ?: []);
$run = function (AiReplyWorker $w): array {
    ob_start();
    $r = $w->run();
    $r['_log'] = (string)ob_get_clean();
    return $r;
};

// The chat of 16 Sep, as the model wrote it. Four figures, none in uCRM.
$INVENTED = "Here's the initial cost to get connected:\n\n- *Starlink kit*: UGX 1,700,000\n"
          . "- *Professional installation*: UGX 250,000\n\n*TOTAL TO GET CONNECTED: UGX 1,950,000*\n\n"
          . "Then a monthly plan: Residential UGX 95,000 or Residential Lite UGX 75,000.";
// What the fixture's catalogue supports: two plans, Mini Kit and installation.
$CORRECT  = "Here's what it takes to get connected:\n• Starlink Mini Kit — UGX 2,249,000\n"
          . "• Professional installation — UGX 150,000\nTOTAL TO GET CONNECTED: UGX 2,399,000\n"
          . "Then Residential Lite at UGX 249,000 per month — UGX 2,988,000 for a full year.";

// ═══════════════════════════════════════════════════════════════════════════
echo "\n1. The 16 Sep chat, replayed: support number, no catalogue, invented prices\n";
$ph1 = '256772100001'; $cid1 = $conv($ph1, 'support');
$ask($cid1, $ph1, 'what is initial cost', 'support');
[$w, $b] = $mkWorker($base, [$INVENTED]);            // no CRM configured — as on the day
$r = $run($w);
is_(($r['processed'] ?? 0) === 1, 'the event is processed', json_encode(array_diff_key($r, ['_log' => 1])));
is_(count($b->seen) === 1 && empty($b->seen[0]['products']),
    'no catalogue reached the model (CRM not configured)');
is_(strpos($b->lastSystemPrompt(), 'PLANS: unavailable right now') !== false,
    'the support prompt now says so in its data section',
    'before 5.18.10 that sentence was attached to the sales channel only');
is_(strpos($b->lastSystemPrompt(), 'HARDWARE: no kit or installation prices') !== false,
    'and says there are no kit or installation prices either');
$last = $lastTextTo($ph1);
is_(($last['text'] ?? '') === ReplyPrivacyGuard::SAFE_FALLBACK,
    'the customer receives the safe fallback, not the invented prices', json_encode($last));
$leaked = false;
foreach ($textCalls() as $c) if (strpos((string)$c['text'], '1,700,000') !== false) $leaked = true;
is_(!$leaked, 'none of the four figures left the building');
$alert = $lastTextTo($ALERT);
is_(stripos((string)($alert['text'] ?? ''), 'reply blocked by guard') !== false
    && stripos((string)($alert['text'] ?? ''), 'foreign:amount') !== false,
    'the team is paged with the category as the reason', json_encode($alert));
is_(stripos((string)($alert['text'] ?? ''), '1,700,000') === false, 'and the alert carries no figure either');
is_($state($cid1) === 'needs_human', 'the thread is marked for a person');
$ev = $events();
is_(count($ev) === 1
    && in_array('foreign:amount', (array)($ev[0]['categories'] ?? []), true)
    && (int)($ev[0]['conversation_id'] ?? 0) === $cid1
    && ($ev[0]['channel'] ?? '') === 'support'
    && (int)($ev[0]['blocked_length'] ?? 0) > 100,
    'one security event: category, conversation, channel, length', json_encode($ev));
is_(strpos($r['_log'], 'reply BLOCKED by guard') !== false && strpos($r['_log'], 'foreign:amount') !== false,
    'the worker log names the block and the category');
is_(strpos($r['_log'], '1,700,000') === false, 'and never the text');
$stored = (string)$pdo->query("SELECT body FROM wa_messages WHERE conversation_id = {$cid1} AND role = 'assistant' ORDER BY id DESC LIMIT 1")->fetchColumn();
is_($stored === ReplyPrivacyGuard::SAFE_FALLBACK, 'the conversation record holds the fallback');
exec('grep -rl ' . escapeshellarg('1,700,000') . ' ' . escapeshellarg($tmp) . ' 2>/dev/null', $files);
is_(($files ?? []) === [], 'the blocked text is written nowhere in the data directory', implode(',', $files ?? []));

// ═══════════════════════════════════════════════════════════════════════════
echo "\n2. With uCRM reachable, the support number receives the catalogue and a correct reply passes\n";
$ph2 = '256772100002'; $cid2 = $conv($ph2, 'support');
$ask($cid2, $ph2, 'what is initial cost', 'support');
[$w, $b] = $mkWorker($withCrm, [$CORRECT]);
$r = $run($w);
$seen = $b->seen[0] ?? [];
is_(count((array)($seen['products']['products'] ?? [])) === 2
    && count((array)($seen['products']['hardware'] ?? [])) === 2,
    'the model is handed 2 plans and 2 one-time items (the plan mirrors dropped)',
    json_encode(['plans' => count((array)($seen['products']['products'] ?? [])),
                 'hardware' => count((array)($seen['products']['hardware'] ?? []))]));
is_(strpos($r['_log'], 'catalogue loaded') !== false, 'and the log says so, as it always did for sales');
is_(strpos($b->lastSystemPrompt(), 'PLANS (live from our system') !== false
    && strpos($b->lastSystemPrompt(), 'PLANS: unavailable') === false,
    'the prompt carries the price list, not the fence');
is_(($lastTextTo($ph2)['text'] ?? '') === $CORRECT,
    'catalogue prices, their sum and a yearly multiple pass untouched', json_encode($lastTextTo($ph2)));
is_(count($events()) === 1, 'no new security event');
is_($state($cid2) !== 'needs_human', 'no hand-over');

echo "\n3. A figure the customer typed may be echoed back\n";
$ph3 = '256772100003'; $cid3 = $conv($ph3, 'support');
$ask($cid3, $ph3, 'Another seller quoted me 1,500,000 for the kit, is that right?', 'support');
[$w, $b] = $mkWorker($withCrm, ["1,500,000 is not our price. Our Starlink Mini Kit is UGX 2,249,000, installed for UGX 150,000."]);
$run($w);
is_(strpos((string)($lastTextTo($ph3)['text'] ?? ''), '1,500,000 is not our price') === 0,
    'the reply quoting the customer\'s own figure goes through', json_encode($lastTextTo($ph3)));
is_(count($events()) === 1, 'still no new security event');

echo "\n4. With the catalogue present, a price for something not in it is still blocked\n";
$ph4 = '256772100004'; $cid4 = $conv($ph4, 'support');
$ask($cid4, $ph4, 'how much is the standard kit?', 'support');
[$w, $b] = $mkWorker($withCrm, ['The Standard Kit is UGX 1,700,000 and installation UGX 250,000.']);
$run($w);
is_(($lastTextTo($ph4)['text'] ?? '') === ReplyPrivacyGuard::SAFE_FALLBACK,
    'a kit the catalogue does not list gets the fallback, not a price');
is_(count($events()) === 2 && (int)($events()[1]['conversation_id'] ?? 0) === $cid4, 'a second security event');

echo "\n5. Flag off: the support number gets no catalogue, is told so, and is still guarded\n";
$off = array_replace($withCrm, ['ai_sales_on_all_numbers' => '0']);
$ph5 = '256772100005'; $cid5 = $conv($ph5, 'support');
$ask($cid5, $ph5, 'prices?', 'support');
[$w, $b] = $mkWorker($off, [$INVENTED]);
$r = $run($w);
is_(empty($b->seen[0]['products']) && strpos($r['_log'], 'catalogue loaded') === false,
    'no catalogue is fetched for a number that does not sell');
is_(strpos($b->lastSystemPrompt(), 'PLANS: unavailable right now') !== false, 'the fence is there regardless');
is_(($lastTextTo($ph5)['text'] ?? '') === ReplyPrivacyGuard::SAFE_FALLBACK,
    'and an invented price is blocked regardless of the flag');

echo "\n6. The sales number is unchanged\n";
$ph6 = '256772100006'; $cid6 = $conv($ph6, 'sales');
$ask($cid6, $ph6, 'what is initial cost', 'sales');
[$w, $b] = $mkWorker($withCrm, [$CORRECT]);
$r = $run($w);
is_(count((array)($b->seen[0]['products']['products'] ?? [])) === 2 && strpos($r['_log'], 'catalogue loaded') !== false,
    'catalogue loaded on sales as before');
is_(($lastTextTo($ph6)['text'] ?? '') === $CORRECT, 'and the correct reply passes');

echo "\n7. The account number, and the brain on its own\n";
$brain = new DishNetAiBrain($base);
$acct  = $brain->promptPreview(['channel' => 'account', 'message' => 'what do your plans cost?', 'identity_state' => 'unknown']);
is_(strpos($acct, 'PLANS: unavailable right now') !== false
    && strpos($acct, "Amounts shown under THEIR SERVICES or ACCOUNT are the customer's own") !== false,
    'the account prompt is fenced, and told the customer\'s own amounts may still be stated');
$withList = $brain->promptPreview(['channel' => 'account', 'message' => 'x', 'identity_state' => 'unknown',
    'products' => ['products' => [['name' => 'Plan A', 'price' => 249000, 'period_months' => 1]],
                   'hardware' => [['name' => 'Kit', 'price' => 2249000]]]]);
is_(strpos($withList, 'PLANS: unavailable') === false && strpos($withList, 'Plan A') !== false,
    'with a catalogue the fence is gone and the list is there');

echo "\n8. The wiring is where the replies are made\n";
$wsrc = (string)file_get_contents($root . '/workers/AiReplyWorker.php');
$bsrc = (string)file_get_contents($root . '/lib/DishNetAiBrain.php');
is_(substr_count($wsrc, 'ReplyPrivacyGuard::check(') === 1, 'the worker calls the guard in exactly one place');
is_(strpos($wsrc, '$this->guardReply($this->brain->reply($context), $context, $this->brain->lastSystemPrompt())') !== false,
    'the in-process brain\'s reply goes through it');
is_(strpos($wsrc, '$this->guardReply($external, $context, $this->brain->promptPreview($context))') !== false,
    'and so does an external ShopBot reply');
is_(strpos($bsrc, '$this->lastSystemPrompt = $system;') !== false, 'the brain records the prompt reply() sent');
is_(strpos($wsrc, 'if ($this->sellsOnAllNumbers()) $this->loadCatalogue($ctx, $convId);') !== false
    && substr_count($wsrc, '$this->loadCatalogue($ctx, $convId);') === 3,
    'the catalogue is loaded on sales, and on support and account when every number sells');

// ── Clean up ────────────────────────────────────────────────────────────────
foreach ([$evoSrv, $crmSrv] as $s) { if ($s) { proc_terminate($s); proc_close($s); } }
array_map('unlink', glob(sys_get_temp_dir() . '/fake_evo_state_*.json') ?: []);
array_map('unlink', glob(sys_get_temp_dir() . '/fake_ucrm_shadow_*.json') ?: []);
array_map('unlink', glob(sys_get_temp_dir() . '/dishnet_catalogue_*.json') ?: []);
putenv('DN_DATA_DIR');
exec('rm -rf ' . escapeshellarg($tmp));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
