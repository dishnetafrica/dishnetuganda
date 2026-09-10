<?php
/**
 * test_ai_lead_capture.php — a pipeline nobody reads is worse than none.
 *
 * The team works in the WhatsApp inbox and will carry on doing so. This exists
 * so a genuine opportunity ALSO lands somewhere structured — not so every
 * message becomes a row.
 *
 * The expensive failure is not the missed lead. It is fifty rows saying
 * "asked how much Starlink costs", which trains a salesperson to stop opening
 * the list. So the model's judgement is only the first gate; the service
 * applies a deterministic floor on top and refuses anything without a stated
 * requirement.
 *
 * The other thing asserted here is that nothing is invented. Every field the
 * conversation did not establish stays null, enforced in code rather than
 * requested in a prompt, because this is the record somebody will act on.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

$tmp = sys_get_temp_dir() . '/dn_lead_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);

require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/ConversationService.php';
require_once $root . '/lib/LeadMatcher.php';
require_once $root . '/lib/AiLeadService.php';
require_once $root . '/lib/DishNetAiBrain.php';

$store = SqliteStore::create($tmp);
$pdo   = $store->getPdo();
$conv  = new ConversationService($tmp, $pdo);
$ON    = ['ai_lead_capture' => '1'];
$svc   = fn(array $c = []) => new AiLeadService($store, $c + $ON, $pdo);
$leads = fn() => $store->load('leads.json') ?? [];

// ── Gate 2: the floor ───────────────────────────────────────────────────────
echo "\nAn enquiry is not an opportunity\n";
foreach ([
    ['how much is Starlink',      []],
    ['do you install',            []],
    ['does it work in Kampala',   ['location' => 'Kampala']],
    ['a plan name and nothing else', ['recommended_plan' => 'Residential']],
] as [$what, $fields]) {
    $r = $svc()->capture($fields, '256772000001', 0);
    is_(!$r['ok'] && $r['action'] === 'skipped', 'no lead for: ' . $what, $r['reason']);
}
is_(count($leads()) === 0, 'nothing was written at all');

echo "\nA requirement alone is still not enough to act on\n";
$r = $svc()->capture(['requirement' => 'internet for my place'], '256772000002', 0);
is_(!$r['ok'], 'skipped', $r['reason']);
is_(strpos($r['reason'], 'nothing to act on') !== false, 'and says why', $r['reason']);

echo "\nA requirement plus something to act on becomes a lead\n";
$c1 = (int)$conv->ensureConversation('256772000010', 'sales', null, 'test')['id'];
$r = $svc()->capture([
    'requirement'   => 'Starlink for a 30-room hotel, guest wifi and CCTV',
    'location'      => 'Entebbe',
    'customer_type' => 'hotel',
    'customer_name' => 'Sarah',
    'public_ip_required' => 'yes',
    'ai_summary'    => 'Runs a 30-room hotel in Entebbe. Needs guest wifi and remote CCTV.',
], '256772000010', $c1);
is_($r['ok'] && $r['action'] === 'created', 'created', $r['reason']);
$all = $leads();
is_(count($all) === 1, 'exactly one row');
$lead = $all[0];
is_($lead['customer_name'] === 'Sarah', 'the name is kept');
is_($lead['priority'] === 'high', 'a public-IP requirement is high priority');
is_($lead['ai_qualified'] === true, 'and it is marked AI-qualified');

echo "\nWith no sales agent, the lead is unassigned — never invented\n";
// This install has no agents. Blocking on assignment would mean no record at
// all; inventing one would mean a lead assigned to somebody who does not exist.
is_($lead['assigned_to'] === null, 'assigned_to is null');
is_((int)$lead['retailer_id'] === 0, 'and no retailer is guessed');
is_($lead['status'] === 'open', 'the lead is open and visible');

echo "\nThe conversation and the lead point at each other\n";
is_((int)$lead['conversation_id'] === $c1, 'the lead names the conversation');
$row = $pdo->query("SELECT lead_id FROM wa_conversations WHERE id = {$c1}")->fetchColumn();
is_((int)$row === (int)$lead['id'], 'and the conversation names the lead');

echo "\nTwenty more messages do not make twenty more leads\n";
// The failure the brief called out by name.
for ($i = 0; $i < 20; $i++) {
    $svc()->capture(['requirement' => 'Starlink for a hotel', 'location' => 'Entebbe'],
                    '256772000010', $c1);
}
is_(count($leads()) === 1, 'still exactly one lead after twenty qualifying messages',
    'got ' . count($leads()));

echo "\nThe same handset written differently is the same person\n";
$svc()->capture(['requirement' => 'Starlink for a hotel', 'location' => 'Entebbe'],
                '+256 772 000 010', 0);
is_(count($leads()) === 1, '+256 772 000 010 matched 256772000010');

echo "\nThe floor gates creation, not enrichment\n";
// A later message adding one detail must not be thrown away because it did
// not restate the requirement. Gating updates too meant a lead could never
// learn anything after the message that created it.
$r = $svc()->capture(['existing_internet' => 'MTN fibre, unreliable'], '256772000010', $c1);
is_($r['ok'] && $r['action'] === 'updated', 'a bare detail updates an existing lead', $r['reason']);
$r = $svc()->capture([], '256772000010', $c1);
is_(!$r['ok'], 'but an empty emission is still nothing', $r['reason']);

echo "\nUpdates fill gaps and never overwrite what is already known\n";
$svc()->capture([
    'requirement'   => 'CHANGED REQUIREMENT',
    'customer_name' => 'Someone Else',
    'existing_internet' => 'MTN fibre, unreliable',
], '256772000010', $c1);
$lead = $leads()[0];
is_($lead['customer_name'] === 'Sarah', 'the known name survives', $lead['customer_name']);
is_(strpos((string)$lead['requirement'], '30-room hotel') !== false,
    'and so does the known requirement', (string)$lead['requirement']);
is_($lead['existing_internet'] === 'MTN fibre, unreliable', 'while a gap is filled');

echo "\nNothing the conversation did not establish is stored\n";
// "unknown" is what a model writes when it means null. Storing the word puts
// it in front of a salesperson as though the customer had said it.
$c2 = (int)$conv->ensureConversation('256772000020', 'sales', null, 'test')['id'];
$svc()->capture([
    'requirement'   => 'internet for my shop',
    'customer_type' => 'shop',
    'company'       => 'unknown',
    'users_devices' => '',
    'location'      => 'N/A',
], '256772000020', $c2);
$shop = array_values(array_filter($leads(), fn($l) => $l['phone'] === '256772000020'))[0];
is_($shop['company'] === null, '"unknown" is stored as null');
is_($shop['users_devices'] === null, 'an empty string is null');
is_($shop['location'] === null, 'and so is "N/A"');

echo "\nA closed lead is not resurrected by a new enquiry\n";
// Won business is done. The next enquiry is a new opportunity, not a reopening.
$rows = $leads();
foreach ($rows as $i => $l) if ($l['phone'] === '256772000020') $rows[$i]['status'] = 'won';
$store->save('leads.json', $rows);
$before = count($leads());
$svc()->capture(['requirement' => 'a second site', 'location' => 'Jinja'], '256772000020', 0);
is_(count($leads()) === $before + 1, 'a new lead is created alongside the won one');

echo "\nSwitched off, it writes nothing\n";
$off = new AiLeadService($store, [], $pdo);
$before = count($leads());
$r = $off->capture(['requirement' => 'x', 'location' => 'y'], '256772000099', 0);
is_(!$r['ok'] && $r['action'] === 'disabled', 'disabled without the flag');
is_(count($leads()) === $before, 'and nothing is written');

echo "\nEvery AI-made change is on the record\n";
$audit = $store->load('ai_crm_actions.json') ?? [];
is_(count($audit) >= 2, count($audit) . ' audit rows');
is_(($audit[0]['action'] ?? '') === 'lead_created', 'the first is the creation');
is_(!empty($audit[0]['conversation_id']), 'and names the conversation it came from');

// ── The marker ──────────────────────────────────────────────────────────────
echo "\nThe marker never reaches the customer\n";
$brain = new DishNetAiBrain(['claude_api_key' => 'k']);
$m = new ReflectionMethod(DishNetAiBrain::class, 'parseMarkers');
$m->setAccessible(true);
$out = $m->invoke($brain, 'Happy to help — which town are you in?' . "\n"
    . '<<LEAD {"requirement":"hotel wifi","location":"Entebbe"}>>');
is_(strpos($out['reply'], 'LEAD') === false, 'stripped from the reply', $out['reply']);
is_(strpos($out['reply'], '{') === false, 'and so is its JSON');
is_($out['reply'] === 'Happy to help — which town are you in?', 'the message is intact');
is_(($out['lead']['location'] ?? '') === 'Entebbe', 'while the data is parsed out');

echo "\nJSON containing an angle bracket does not leave half a marker behind\n";
// The generic marker strip is [^>]*, which a '>' inside JSON would defeat.
$out2 = $m->invoke($brain, 'Noted.' . "\n" . '<<LEAD {"requirement":"speed > 100mbps"}>>');
is_(strpos($out2['reply'], '>>') === false, 'nothing of the marker survives', $out2['reply']);
is_(($out2['lead']['requirement'] ?? '') === 'speed > 100mbps', 'and the value is intact');

echo "\nMalformed JSON costs a lead, never a mangled message\n";
$out3 = $m->invoke($brain, 'Sure.' . "\n" . '<<LEAD {broken>>');
is_(strpos($out3['reply'], 'LEAD') === false, 'still stripped');
is_(($out3['lead'] ?? null) === null, 'and nothing is guessed at');

echo "\nNo marker means no lead\n";
$out4 = $m->invoke($brain, 'Our Residential plan is a good fit.');
is_(($out4['lead'] ?? null) === null, 'absent, not an empty array');

echo "\nThe instruction only appears when the feature is on\n";
$ctx = ['channel' => 'sales', 'medium' => 'whatsapp', 'customer' => null,
        'history' => [], 'message' => 'hi'];
$offBrain = new DishNetAiBrain(['claude_api_key' => 'k', 'ai_qualification' => '1']);
is_(strpos($offBrain->promptPreview($ctx), 'RECORDING A SALES OPPORTUNITY') === false,
    'absent by default — South Sudan is untouched');
$onBrain = new DishNetAiBrain(['claude_api_key' => 'k', 'ai_qualification' => '1',
                               'ai_lead_capture' => '1']);
$p = $onBrain->promptPreview($ctx);
is_(strpos($p, 'RECORDING A SALES OPPORTUNITY') !== false, 'present when switched on');
is_(strpos($p, 'ONLY WHAT THEY ACTUALLY TOLD YOU') !== false, 'and forbids guessing');
is_(strpos($p, 'are enquiries, not') !== false, 'with the non-examples named');

exec('rm -rf ' . escapeshellarg($tmp));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
