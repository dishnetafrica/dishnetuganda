<?php
declare(strict_types=1);
/**
 * test_prospect_sales.php — a prospect is sold to, and remembered.
 *
 * From a real conversation on the sales number, 15 Sep. A prospect gave his
 * name and his company, an email address for a quotation, said he had just
 * spoken to us by phone, and asked twice for a basic quote for his business
 * and his home. Every reply was a version of "how can I help you today?".
 *
 * Two causes, both fixed here. The model was never shown the conversation:
 * a WhatsApp number not in billing is the UNKNOWN identity, and until 5.18.3
 * getMessagesForAi() replayed nothing for it, so every prospect on the sales
 * number was answered one message at a time. And nothing in the sales prompt
 * said what a salesperson does with a name, an email address, a reference to
 * a call, or a plain request for prices.
 *
 * This file proves the prompt carries the posture where it should and not
 * where it should not; that a typed email lands on the lead record; and,
 * through the real worker's context builder against a fake uCRM, that the
 * second message from an unknown prospect arrives at the brain with the first
 * exchange in front of it — while an ambiguous number still gets nothing and
 * a customer's turns never leak to an unknown caller.
 */
$pass = 0; $fail = 0;
function t(string $n, $got, $want): void { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d !== '' ? "\n       $d" : '') . "\n"; } }

$root = dirname(__DIR__);
if (!getenv('DN_VAULT_FILE')) putenv('DN_VAULT_FILE=' . tempnam(sys_get_temp_dir(), 'dn-vault-'));
require_once $root . '/lib/timezone.php';
require_once $root . '/lib/DishNetAiBrain.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/ConversationService.php';
require_once $root . '/lib/AiLeadService.php';

$plans = ['products' => ['products' => [
    ['name' => 'Residential Lite', 'price' => 180000, 'period_months' => 1],
    ['name' => 'Residential',      'price' => 250000, 'period_months' => 1],
], 'hardware' => [['name' => 'Standard Kit', 'price' => 1750000]], 'stock' => 'In stock.']];
$on  = ['openai_api_key' => 'x', 'ai_provider' => 'openai', 'ai_qualification' => '1', 'ai_lead_capture' => '1'];
$MARK = 'NOBODY IN OUR BILLING SYSTEM MATCHES THIS CONVERSATION';

// ═════════════════════════════════════════════════
echo "\n1. The prompt: a prospect is sold to\n";
// ═════════════════════════════════════════════════
$brain = new DishNetAiBrain($on);
$p = $brain->promptPreview(['channel' => 'sales', 'message' => 'Pls share the basic quote', 'identity_state' => 'unknown'] + $plans);
is_(strpos($p, $MARK) !== false,                                'an unknown caller on the sales number is a prospect');
is_(strpos($p, 'READ THE CONVERSATION ABOVE FIRST') !== false,   'and the model is told to read the conversation first');
is_(strpos($p, 'ANSWER FIRST') !== false,                        'a request for prices is answered first, then one question');
is_(strpos($p, 'ADDRESS A COLLEAGUE BY NAME') !== false,         '"Hi Bhavin" and "we just talked" are handled, not deflected');
is_(strpos($p, 'their name, company, role, email address') !== false, 'a typed detail is progress, not a question');
is_(strpos($p, 'Put it in the LEAD line.') !== false,             'and is recorded on the lead when lead capture is on');
is_(strpos($p, 'record quote_requested and the email in the LEAD line') !== false, 'as is an email given for a quotation');
is_(strpos($p, 'customer_name, company, email, users_devices') !== false, 'email is a key the LEAD line may carry');
is_(strpos($p, 'Never one you inferred') !== false,               'and only one the customer actually typed');
is_(strpos($p, 'QUALIFY BEFORE YOU RECOMMEND') !== false,          'the qualification rules are still there — they now follow the prices, not replace them');

$noLead = new DishNetAiBrain(['openai_api_key' => 'x', 'ai_provider' => 'openai', 'ai_qualification' => '1']);
$q = $noLead->promptPreview(['channel' => 'sales', 'message' => 'hi', 'identity_state' => 'unknown'] + $plans);
is_(strpos($q, $MARK) !== false && strpos($q, 'LEAD line') === false, 'with lead capture off the posture stays and the LEAD line is not mentioned');

$anon = $brain->promptPreview(['channel' => 'sales', 'transport' => 'web', 'message' => 'price?', 'identity_state' => 'anonymous'] + $plans);
is_(strpos($anon, $MARK) !== false,                              'a website visitor is a prospect too');

echo "\n   …and not where it must not be\n";
$cust = $brain->promptPreview(['channel' => 'sales', 'message' => 'my line is slow', 'identity_state' => 'identified',
                               'customer' => ['id' => 7, 'name' => 'Ours Customer']] + $plans);
is_(strpos($cust, $MARK) === false,                              'an identified customer on the sales number is not a prospect');
is_(strpos($cust, 'THIS IS AN EXISTING DISHNET CUSTOMER') !== false, '— service mode, as before');
$amb = $brain->promptPreview(['channel' => 'sales', 'message' => 'price?', 'identity_state' => 'ambiguous', 'identity_ambiguous' => true] + $plans);
is_(strpos($amb, $MARK) === false,                               'an ambiguous number is not treated as a prospect');
is_(strpos($amb, 'matches MORE THAN ONE customer') !== false,    '— it asks for a name and reveals nothing, as before');
$sup = $brain->promptPreview(['channel' => 'support', 'message' => 'price?', 'identity_state' => 'unknown']);
is_(strpos($sup, $MARK) === false,                               'the support number does not sell unless told to');
$supAll = (new DishNetAiBrain($on + ['ai_sales_on_all_numbers' => '1']))->promptPreview(['channel' => 'support', 'message' => 'price?', 'identity_state' => 'unknown']);
is_(strpos($supAll, $MARK) !== false,                            '…and does when ai_sales_on_all_numbers is on');
$over = (new DishNetAiBrain($on + ['bot_instructions_mode' => 'override', 'bot_custom_instructions' => 'Be brief.']))
    ->promptPreview(['channel' => 'sales', 'message' => 'price?', 'identity_state' => 'unknown'] + $plans);
is_(strpos($over, $MARK) === false && strpos($over, 'ABSOLUTE RULES') !== false, 'an operator override replaces the wording, never the absolute rules — the posture is wording');

echo "\n   …and a customer just created in billing, with nothing active yet, is a sign-up in progress\n";
$onb = $brain->promptPreview(['channel' => 'sales', 'message' => 'Browsing and streaming', 'identity_state' => 'identified',
                              'customer' => ['id' => 13, 'name' => 'Julius Newcomer', 'is_lead' => false, 'has_service' => false]] + $plans);
is_(strpos($onb, 'NO ACTIVE SERVICE YET') !== false,             'no live service → the sign-up posture');
is_(strpos($onb, 'Carry the sale through') !== false,             '— carry the sale through, do not restart it');
is_(strpos($onb, 'EXISTING DISHNET CUSTOMER') === false,          '— and not service mode');
is_(strpos($onb, 'none active yet') !== false,                    '— DATA says so too');
is_(strpos($onb, $MARK) === false,                                '— and not the stranger posture either');
$sub = $brain->promptPreview(['channel' => 'sales', 'message' => 'my line is slow', 'identity_state' => 'identified',
                              'customer' => ['id' => 14, 'name' => 'Grace Subscriber', 'is_lead' => false, 'has_service' => true]] + $plans);
is_(strpos($sub, 'EXISTING DISHNET CUSTOMER') !== false && strpos($sub, 'NO ACTIVE SERVICE YET') === false, 'a live service → service mode, as before');
is_(strpos($cust, 'EXISTING DISHNET CUSTOMER') !== false && strpos($cust, 'NO ACTIVE SERVICE YET') === false, 'not looked up → service mode, as before');
require_once $root . '/lib/BrainContext.php';
$bc = BrainContext::build('identified', ['channel' => 'sales', 'transport' => 'whatsapp', 'message' => 'hi',
        'customer' => ['id' => 13, 'name' => 'Julius Newcomer', 'is_lead' => false, 'has_service' => false, '_raw' => ['secret' => 'x']]]);
t('BrainContext carries has_service when established',            $bc['customer'] ?? null, ['name' => 'Julius Newcomer', 'is_lead' => false, 'has_service' => false]);
$bc2 = BrainContext::build('identified', ['channel' => 'sales', 'transport' => 'whatsapp', 'message' => 'hi',
        'customer' => ['id' => 13, 'name' => 'Julius Newcomer', 'is_lead' => false]]);
t('and not when nobody looked',                                    array_keys($bc2['customer'] ?? []), ['name', 'is_lead']);

echo "\n   …and a product named like a plan is the plan\n";
require_once $root . '/lib/DishNetTools.php';
t('two spellings of one plan compare equal',
  DishNetTools::catalogueKey('Starlink Residential Lite ( up to 100 Mbps)'), DishNetTools::catalogueKey('Residential Lite (up to 100 Mbps)'));
is_(DishNetTools::catalogueKey('Starlink Mini Kit') !== DishNetTools::catalogueKey('Starlink Standard Kit'), 'different things stay different');

// ═════════════════════════════════════════════════
echo "\n2. The lead: a typed email lands on the record, validated\n";
// ═════════════════════════════════════════════════
$tmp = sys_get_temp_dir() . '/prospect_' . getmypid();
exec('rm -rf ' . escapeshellarg($tmp)); @mkdir($tmp, 0777, true);
register_shutdown_function(function () use ($tmp) { exec('rm -rf ' . escapeshellarg($tmp)); });
$store = SqliteStore::create($tmp);
$leads = new AiLeadService($store, ['ai_lead_capture' => '1'], $store->getPdo());
$r = $leads->capture([
    'requirement' => 'internet for the office and for home', 'customer_type' => 'business',
    'customer_name' => 'Hari', 'company' => 'Bidco', 'email' => 'Kris.Chinna@bul.co.ug ',
    'quote_requested' => 'true', 'ai_summary' => 'Wants a basic quote for business and home.',
], '256700111222', 41, 'whatsapp_ai');
t('the lead is created',                                          [$r['ok'], $r['action']], [true, 'created']);
$lead = ($store->load('leads.json') ?? [])[0] ?? [];
t('with the email, normalised',                                  $lead['email'] ?? null, 'kris.chinna@bul.co.ug');
t('and the request for a quote makes it high priority',         [$lead['quote_requested'] ?? null, $lead['priority'] ?? null], [true, 'high']);
$r2 = $leads->capture(['email' => 'kris at bul dot co'], '256700111222', 41, 'whatsapp_ai');
t('a second message with a mangled address changes nothing',   [$r2['action'], (($store->load('leads.json') ?? [])[0]['email'] ?? null)], ['skipped', 'kris.chinna@bul.co.ug']);
$r3 = (new AiLeadService($store, ['ai_lead_capture' => '1'], $store->getPdo()))->capture([
    'requirement' => 'a quote', 'quote_requested' => 'true', 'email' => 'not-an-address'], '256700333444', 42);
$second = ($store->load('leads.json') ?? [])[1] ?? [];
t('an address that is not one is stored as nothing, not as text',
  [$r3['action'], array_key_exists('email', $second) ? $second['email'] : 'MISSING'], ['created', null]);

// ═════════════════════════════════════════════════
echo "\n3. The worker, for real: the second message arrives with the first exchange\n";
// ═════════════════════════════════════════════════
require_once $root . '/lib/EventBus.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/workers/WorkerBase.php';
require_once $root . '/workers/AiReplyWorker.php';

// The fake uCRM that knows clients 7 and 21 and nobody else.
$http = function (string $url): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_PROXY => '']);
    $r = curl_exec($ch); curl_close($ch);
    return $r === false ? null : (string)$r;
};
$router = $root . '/tests/fixtures/fake_ucrm_shadow.php';
$srv = null; $port = 0;
foreach (range(0, 9) as $slot) {
    $cand = 9800 + ((getmypid() + $slot * 11) % 80);
    @unlink(sys_get_temp_dir() . '/fake_ucrm_shadow_' . $cand . '.json');
    $p = proc_open(sprintf('exec php -S 127.0.0.1:%d %s', $cand, escapeshellarg($router)),
                   [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    $ours = false;
    for ($i = 0; $i < 40; $i++) {
        $got = $http("http://127.0.0.1:{$cand}/__test/state");
        if ($got !== null) { $ours = strpos($got, 'FAKE-UCRM-SHADOW') !== false; break; }
        usleep(100000);
    }
    if ($ours) { $srv = $p; $port = $cand; break; }
    if (is_resource($p)) { proc_terminate($p); proc_close($p); }
}
if ($srv === null) { echo "  FAIL could not start the fake uCRM\n"; $fail++; }
else {
    register_shutdown_function(function () use (&$srv) { if (is_resource($srv)) { proc_terminate($srv); proc_close($srv); } });
    $wdir = $tmp . '/worker'; @mkdir($wdir, 0777, true);
    putenv('DN_DATA_DIR=' . $wdir);
    $wstore = SqliteStore::create($wdir);
    // Only client 7 is in the local index; the prospect's number matches nobody.
    $pdo = $wstore->getPdo();
    $pdo->exec('DELETE FROM client_search_index');
    $pdo->prepare('INSERT INTO client_search_index (id, name, phone, phone_norm) VALUES (7, ?, ?, ?)')
        ->execute(['Ours Customer', '+256701998877', '256701998877']);
    $worker = new AiReplyWorker($wstore, [
        'crm_base_url' => "http://127.0.0.1:{$port}", 'crm_auth_token' => 'SHADOWKEY',
        'claude_api_key' => 'never-called', 'evo_api_url' => 'http://127.0.0.1:1', 'evo_api_key' => 'unused',
    ]);
    $convSvc = new ConversationService($wdir, $pdo);
    $build = function (string $channel, string $phone, string $msg, int $cid) use ($worker): array {
        $m = new ReflectionMethod(AiReplyWorker::class, 'buildContext');
        $m->setAccessible(true);
        ob_start();
        $ctx = $m->invoke($worker, $channel, $phone, $msg, [], $cid);
        return ['ctx' => $ctx, 'log' => (string)ob_get_clean()];
    };

    $PROSPECT = '256700123456';
    $cid = (int)($convSvc->ensureConversation($PROSPECT, 'sales')['id'] ?? 0);
    is_($cid > 0, 'a sales conversation exists for a number nobody in billing has');

    // Turn 1: "Hi Bhavin" — the worker resolves identity (unknown), opens the epoch.
    $one = $build('sales', $PROSPECT, 'Hi Bhavin', $cid);
    t('turn 1: the prospect is unknown to billing',               $one['ctx']['identity_state'] ?? null, 'unknown');
    t('turn 1: no history yet',                                    count($one['ctx']['history'] ?? []), 0);
    is_(strpos($one['log'], 'identity=unknown history=0') !== false, 'and the log says so, without content', $one['log']);
    $hwNames   = array_map(fn($h) => $h['name'], $one['ctx']['products']['hardware'] ?? []);
    $planNames = array_map(fn($p) => $p['name'], $one['ctx']['products']['products'] ?? []);
    t('the catalogue carries the plans as monthly',                 $planNames, ['Starlink Residential Lite ( up to 100 Mbps)', 'Residential (up to 400 Mbps)']);
    t('and the one-time items without the plan mirrors',            $hwNames, ['Starlink Mini Kit', 'Professional Installation']);
    is_(strpos($one['log'], '2 plan mirror(s) dropped from hardware') !== false, 'and the log says two mirrors were dropped', $one['log']);

    // What the worker stores after a real turn: the inbound, then its reply.
    $convSvc->storeMessage($cid, ['direction' => 'in',  'role' => 'customer',  'body' => 'Hi Bhavin']);
    $convSvc->storeMessage($cid, ['direction' => 'out', 'role' => 'assistant', 'body' => 'Hi — Bhavin is tied up, I am the DishNet assistant covering the chat. What can I set up for you?', 'agent_name' => 'DishNet AI']);
    $convSvc->storeMessage($cid, ['direction' => 'in',  'role' => 'customer',  'body' => 'This is Hari From Bidco']);
    $convSvc->storeMessage($cid, ['direction' => 'out', 'role' => 'assistant', 'body' => 'Thanks Hari. Home, business, or both?', 'agent_name' => 'DishNet AI']);

    // Turn 3: the email address. THE POINT — the brain must see the exchange above.
    $three = $build('sales', $PROSPECT, 'Kris.chinna@bul.co.ug this is my mail id', $cid);
    $hist = $three['ctx']['history'] ?? [];
    t('turn 3: the brain is handed the four earlier turns',        count($hist), 4);
    t('…in order, customer and dishnet alternating',               array_map(fn($h) => $h['role'], $hist), ['customer', 'dishnet', 'customer', 'dishnet']);
    is_(strpos($hist[2]['text'] ?? '', 'Hari From Bidco') !== false, '…including who they said they were');
    is_(strpos($three['log'], 'identity=unknown history=4') !== false, 'and the log records history=4', $three['log']);
    is_(strpos($three['log'], 'Bidco') === false && strpos($three['log'], 'bul.co.ug') === false, 'the log carries none of what was said');

    // A customer we DO know, on the same sales number, keeps working as before.
    $OURS = '256701998877';
    $cid7 = (int)($convSvc->ensureConversation($OURS, 'sales')['id'] ?? 0);
    $c1 = $build('sales', $OURS, 'hello', $cid7);
    t('an identified customer is identified',                      $c1['ctx']['identity_state'] ?? null, 'identified');
    $convSvc->storeMessage($cid7, ['direction' => 'in', 'role' => 'customer', 'body' => 'hello']);
    $convSvc->storeMessage($cid7, ['direction' => 'out', 'role' => 'assistant', 'body' => 'Hello! How is the line?', 'agent_name' => 'DishNet AI']);
    $c2 = $build('sales', $OURS, 'fine', $cid7);
    t('and still gets their own history',                          count($c2['ctx']['history'] ?? []), 2);

    // The sign-up moment, through the worker: client 13 exists in billing with no
    // service; client 14 has one active. The prompt gets the fact, the log the count.
    $pdo->prepare('INSERT INTO client_search_index (id, name, phone, phone_norm) VALUES (13, ?, ?, ?)')->execute(['Julius Newcomer', '+256776000555', '256776000555']);
    $pdo->prepare('INSERT INTO client_search_index (id, name, phone, phone_norm) VALUES (14, ?, ?, ?)')->execute(['Grace Subscriber', '+256776000666', '256776000666']);
    $cid13 = (int)($convSvc->ensureConversation('256776000555', 'sales')['id'] ?? 0);
    $n = $build('sales', '256776000555', 'Browsing and streaming', $cid13);
    t('a just-created client is identified',                        $n['ctx']['identity_state'] ?? null, 'identified');
    t('…and known to have no live service',                         $n['ctx']['customer']['has_service'] ?? 'MISSING', false);
    is_(strpos($n['log'], '0 live service(s)') !== false,           'and the log counts it, without content', $n['log']);
    $cid14 = (int)($convSvc->ensureConversation('256776000666', 'sales')['id'] ?? 0);
    $g = $build('sales', '256776000666', 'my line is slow', $cid14);
    t('a subscriber is known to have one',                          $g['ctx']['customer']['has_service'] ?? 'MISSING', true);

    // The number leaves billing (client deleted, or the CRM lookup fails): the
    // customer's turns must not follow it into the unknown epoch.
    $pdo->exec('DELETE FROM client_search_index');
    $gone = $build('sales', $OURS, 'are you there?', $cid7);
    is_(($gone['ctx']['identity_state'] ?? '') !== 'identified',    'once the number is no longer in billing it is not identified');
    t('and nothing of the customer\'s conversation is replayed',    count($gone['ctx']['history'] ?? []), 0);

    putenv('DN_DATA_DIR');
}

// ═════════════════════════════════════════════════
echo "\n4. The knowledge seed no longer teaches old plan names\n";
// ═════════════════════════════════════════════════
// "DishNet Home" reached a customer on 15 Sep from the PLAN_SERVICE_MAP row,
// which told the model to present a DishNet marketing name first and mapped
// the old names onto the Starlink ones. The uCRM plans were renamed; the row
// was not. Plan names come from PLANS, exactly as they appear there.
$seed = json_decode((string)file_get_contents($root . '/tools/knowledge_seed.json'), true);
$map  = null;
foreach ((array)($seed['items'] ?? []) as $it) { if (($it['item_key'] ?? '') === 'PLAN_SERVICE_MAP') { $map = $it; break; } }
is_($map !== null,                                                       'the PLAN_SERVICE_MAP row is still seeded');
is_(strpos((string)($map['answer'] ?? ''), 'exactly as they appear') !== false, 'and says plan names are used exactly as PLANS names them');
is_(strpos((string)($map['answer'] ?? ''), 'present the DishNet plan name first') === false, 'and no longer tells the model to present a marketing name first');
is_(strpos((string)($map['answer'] ?? ''), 'DishNet Home = ') === false,  'and carries no old-name mapping to present');
$evalq = (string)file_get_contents($root . '/tools/ai_eval_questions.json');
is_(strpos($evalq, 'DishNet Home') === false,                           'the eval questions name live plans, not old ones');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
