<?php
/**
 * test_plan_catalogue.php — the model is given nothing else to offer.
 *
 * Every case in section one is a real exchange from 19 September 2026, the
 * morning the fence shipped. The fence worked — the priority-data fact was
 * attached to both replies — and the messages contradicted themselves,
 * because a correct footnote under a wrong recommendation is still a wrong
 * recommendation. The operator's verdict on the transcript was one line:
 * "its supposed to tell residential 400 Mbps is good for your case".
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

require_once $root . '/lib/PlanCatalogue.php';

$CAT = [
    ['name' => 'Starlink Residential Lite', 'price' => 249000],
    ['name' => 'Starlink Residential',      'price' => 329000],
    ['name' => 'Starlink Business 50',      'price' => 175000],
    ['name' => 'Starlink Business 500',     'price' => 285000],
    ['name' => 'Starlink Business 1TB',     'price' => 469000],
];
$names = fn(array $r): array => array_map(fn($p) => $p['name'], $r['products']);
$said  = fn(string $msg, array $hist = []): array
       => ['message' => $msg, 'history' => $hist, 'channel' => 'sales'];

echo "\nThe transcript that prompted this, line by line\n";
foreach ([
    'What are the available data plans for Starlink?' => false,
    'I want for business'                             => false,
    'I want business hotspot WiFi'                    => false,
    'Which one is better for business'                => false,
] as $msg => $expect) {
    $r = PlanCatalogue::forConversation($CAT, $said($msg));
    $hasBiz = (bool)preg_grep('/Business/', $names($r));
    is_($hasBiz === $expect, '"' . $msg . '" → Business ' . ($expect ? 'shown' : 'withheld'));
}
$r = PlanCatalogue::forConversation($CAT, $said('I want business hotspot WiFi'));
is_($names($r) === ['Starlink Residential Lite', 'Starlink Residential'],
    'the hotspot customer sees the two Residential plans and nothing else',
    'got: ' . implode(', ', $names($r)));
is_($r['filtered'] === 3, 'and the three Business plans were dropped');

echo "\nA real requirement puts them back\n";
foreach (['I need to view my cameras remotely'   => 'cameras from elsewhere',
          'we need a VPN into the office'         => 'VPN',
          'I want to host a server'               => 'a server',
          'we have two locations to link'         => 'two sites',
          'do you do static IP?'                  => 'static IP',
          'I need remote access to my machines'   => 'remote access',
          'connect our office in Jinja'           => 'linking offices'] as $msg => $why) {
    $r = PlanCatalogue::forConversation($CAT, $said($msg));
    is_(count($r['products']) === 5, $why . ' → the full catalogue');
    is_($r['filtered'] === 0, '  nothing withheld for: ' . $why);
}

echo "\nAsking for one by name is itself the reason\n";
foreach (['how much is Business 50?', 'tell me about Starlink Business 500',
          'business 1TB price'] as $msg) {
    is_(count(PlanCatalogue::forConversation($CAT, $said($msg))['products']) === 5,
        '"' . $msg . '" → quoted, because they asked');
}

echo "\nThe distinctions that make this correct rather than merely strict\n";
// A public IP is for reaching your network from outside. None of these is that.
foreach (['I have CCTV at the shop'          => 'cameras recording on site need no public IP',
          'we run a hotspot for 50 people'   => 'user count is not a public-IP requirement',
          'it is for my business in Kampala' => 'trading is not a requirement',
          'business hours are 8 to 6'        => 'not even a plan question'] as $msg => $why) {
    is_(PlanCatalogue::needsBusiness($said($msg))['yes'] === false, $why);
}
// ...but cameras plus watching them from elsewhere is.
is_(PlanCatalogue::needsBusiness($said('I want to see my CCTV from my phone'))['yes'] === true,
    'cameras PLUS viewing them from elsewhere is a requirement');

echo "\nOnly what the customer said counts as evidence\n";
// If our own replies counted, the assistant could justify its last wrong
// recommendation with the wrong recommendation.
$ours = [['role' => 'assistant', 'body' => 'You need a public IP and a VPN for that.']];
is_(PlanCatalogue::needsBusiness($said('ok', $ours))['yes'] === false,
    'the assistant suggesting a VPN is not the customer needing one');
$theirs = [['role' => 'customer', 'body' => 'we need a VPN']];
is_(PlanCatalogue::needsBusiness($said('ok', $theirs))['yes'] === true,
    'the customer saying it earlier still counts');

echo "\nThe shape the LIVE worker actually writes\n";
// AiReplyWorker builds history as ['role'=>'customer'|'dishnet','text'=>...].
// The first version of customerText() read 'body' and would have seen none of
// it: correct in this test file, blind in production. That is the third time
// today a fix passed its own test and would have failed live, so the worker's
// source is read here rather than its shape assumed.
$worker = (string)file_get_contents($root . '/workers/AiReplyWorker.php');
is_(strpos($worker, "'role' => \$inbound ? 'customer' : 'dishnet'") !== false,
    'the worker still labels inbound rows "customer"',
    'if this moved, the filter may be reading the wrong key again');
is_(preg_match("/'role' => \\\$inbound \\? 'customer' : 'dishnet',\s*'text' =>/", $worker) === 1,
    'and still carries the message under "text"');

$live = ['message' => 'so which one do you recommend?', 'channel' => 'sales', 'history' => [
    ['role' => 'customer', 'text' => 'hi, do you cover Ntinda?'],
    ['role' => 'dishnet',  'text' => 'Yes we do. What do you need it for?'],
    ['role' => 'customer', 'text' => 'we need a VPN into the office'],
]];
is_(PlanCatalogue::needsBusiness($live)['yes'] === true,
    'a requirement stated three turns ago is still remembered',
    'this is the case the body/text mix-up would have broken');
$liveNo = ['message' => 'which one?', 'channel' => 'sales', 'history' => [
    ['role' => 'customer', 'text' => 'I want for business'],
    ['role' => 'dishnet',  'text' => 'For business we have Business 50, 500 and 1TB.'],
]];
is_(PlanCatalogue::needsBusiness($liveNo)['yes'] === false,
    'and our own reply naming the plans is still not a reason',
    'the assistant must not bootstrap its own wrong recommendation');

echo "\nIt fails towards showing, never towards silence\n";
$bizOnly = [['name' => 'Starlink Business 50', 'price' => 175000]];
is_(count(PlanCatalogue::forConversation($bizOnly, $said('hello'))['products']) === 1,
    'a catalogue with only Business plans is left alone',
    'an empty PLANS list would stop the assistant quoting any price at all');
is_(PlanCatalogue::forConversation([], $said('hello'))['products'] === [],
    'an empty catalogue stays empty');

echo "\nWhen they are withheld, the assistant is told how to answer\n";
$rule = PlanCatalogue::ASK_RULE;
is_(strpos($rule, 'Do not name one') !== false, 'it may not name a Business plan');
is_(strpos($rule, 'NOTHING to do with how many people') !== false,
    'and the invented claim is named and forbidden',
    'the live reply said a public IP was "suitable for multiple users"');
is_(strpos($rule, 'ask what they need it for') !== false,
    'but it may still offer to check — it must not claim we have none');

echo "\nThe brain cuts the catalogue before it prints it\n";
$brain = (string)file_get_contents($root . '/lib/DishNetAiBrain.php');
is_(strpos($brain, 'PlanCatalogue::forConversation') !== false, 'the filter runs');
is_(strpos($brain, 'PlanCatalogue::forConversation')
    < strpos($brain, 'PLANS (live from our system'), 'before the list is written',
    'filtering after printing would print the plans it meant to withhold');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
