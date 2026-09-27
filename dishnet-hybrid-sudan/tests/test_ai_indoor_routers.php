<?php
/**
 * test_ai_indoor_routers.php — 5.18.45 (docs/40 §14): more floors inside one building are covered with Starlink
 * routers, the outdoor access point is for outside, and a Business plan's priority block ends at about 1 Mbps.
 *
 * The operator, 27 Sep 2026, after 5.18.44 went live: "Ruijie Reyee RG-RAP6262(G) … this is out door and for indoor if
 * some one want to cover more floor we have to suggest starlink routers". The Starlink routers are in uCRM as
 * accessories, and the shop catalogue already says which accessories are routers and what each one fits.
 *
 * And asked which of two approved texts was right about a Business plan once its priority block is used up, the
 * operator answered "Drops to ~1 Mbps". BUSINESS_PLANS said "unlimited standard data continues", and on 27 Sep the
 * assistant repeated it to a customer (docs/40 §13). The note appended to Business replies always said 1 Mbps.
 *
 * Pinned here, each against the code it guards:
 *   1. NetworkEquipment::starlinkRouters takes the routers from the shop catalogue (category Router, exact names) and
 *      guesses nothing from a name: a mount named after a router is a mount;
 *   2. the Uganda prompt marks them in ACCESSORIES with what each fits, carries the rule for more floors, and says the
 *      network equipment is for outdoors;
 *   3. nowhere else: South Sudan and the module off unchanged, and a Uganda price list with no Starlink router
 *      byte-identical to 5.18.44;
 *   4. the price check allows the totals a floors design produces (1 to 5 of one router, with any of the kit and the
 *      installation), still refuses a wrong figure, and lets through no round figure 5.18.44 refused;
 *   5. BUSINESS_PLANS says what the operator approved, reaches the assistant whole, and the deploy's one-row correction
 *      works for it;
 *   6. weakened copies of the code each fail this test.
 *
 * Against another copy of the plugin:   php test_ai_indoor_routers.php --root=DIR [--no-mutants]
 */
declare(strict_types=1);

$opt  = getopt('', ['root:', 'no-mutants']);
$root = isset($opt['root']) ? rtrim((string)$opt['root'], '/') : dirname(__DIR__);
$withMutants = !isset($opt['no-mutants']);

foreach (['bootstrap_data', 'StoreInterface', 'JsonStore', 'SqliteStore', 'KnowledgeSeeder', 'KnowledgeBase',
          'ConversationService', 'BrainContext', 'PlanCatalogue', 'PlanFenceGuard', 'NetworkEquipment', 'ShopCatalogue',
          'ReplyPrivacyGuard', 'DishNetAiBrain', 'EventBus'] as $lib) {
    require_once "{$root}/lib/{$lib}.php";
}
require_once "{$root}/workers/WorkerBase.php";
require_once "{$root}/workers/AiReplyWorker.php";

$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; }
    else    { $fail++; echo "  FAIL {$m}" . ($d !== '' ? "\n       {$d}" : '') . "\n"; }
}
function has(string $m, string $hay, string $needle): void  { is_(strpos($hay, $needle) !== false, $m, 'missing: ' . $needle); }
function none(string $m, string $hay, string $needle): void { is_(strpos($hay, $needle) === false, $m, 'present: ' . $needle); }
/** The part of a prompt from $header to the next blank line. */
function block(string $p, string $header): string {
    $i = strpos($p, $header);
    if ($i === false) return '';
    $j = strpos($p, "\n\n", $i);
    return $j === false ? substr($p, $i) : substr($p, $i, $j - $i);
}

// ── Inputs ──────────────────────────────────────────────────────────────────
// The plans and one-time products as uCRM returned them on 27 Sep 2026 (docs/40 §10); the two Starlink routers at
// their live prices; a mount named after a router, and another mount (illustrative price).
$PLANS = [
    ['name' => 'Starlink Residential Lite ( up to 100 Mbps)', 'price' => 249000, 'period_months' => 1],
    ['name' => 'Residential (up to 400 Mbps)', 'price' => 329000, 'period_months' => 1],
    ['name' => 'Starlink Business 50 GB', 'price' => 175000, 'period_months' => 1],
    ['name' => 'Starlink Business 500 GB', 'price' => 285000, 'period_months' => 1],
    ['name' => 'Starlink Business 1TB', 'price' => 469000, 'period_months' => 1],
];
$HW = [
    ['name' => 'Starlink Mini Kit + Mini Router', 'price' => 2249000],
    ['name' => 'Starlink Standard Kit', 'price' => 2649000],
    ['name' => 'Professional Installation', 'price' => 150000],
    ['name' => 'ICT Consultancy Charges', 'price' => 100000],
    ['name' => 'Ruijie Reyee RG-RAP6262(G)', 'price' => 1100000],
    ['name' => 'D-Link D.LINK CAT 6, OUTDOOR WATERPROOF CABLE', 'price' => 378000],
    ['name' => 'MikroTik L009 Series', 'price' => 700000],
    ['name' => 'RJ45 Cat6 Pass-Through Connector (PACK OF 100)', 'price' => 19500],
];
$ACC = [
    ['name' => 'Router 3 Mount', 'price' => 226000],
    ['name' => 'Router Mini', 'price' => 301000],
    ['name' => 'Router 3 | Starlink V4 or V5, Mini', 'price' => 827000],
    ['name' => 'Wall Mount | Mini', 'price' => 95000],
];
$LIVE = ['products' => $PLANS, 'hardware' => $HW, 'accessories' => $ACC];

/** Uganda's switches, with a fixed knowledge block so a knowledge-row change cannot move a prompt fingerprint. */
$UG = ['ai_provider' => 'openai', 'openai_api_key' => 'x', 'ai_currency' => 'UGX', 'ai_qualification' => '1', 'ai_hardware_expert' => '1',
       'ai_sales_on_all_numbers' => '1', 'ai_lead_capture' => '1',
       'knowledge_block' => "APPROVED KNOWLEDGE — answer these topics from here, exactly and only:\n- [FIXED] A fixed row: the same text in every version."];
$SS = ['ai_provider' => 'openai', 'openai_api_key' => 'x'];

/** The sales number's context, built the way AiReplyWorker::buildContext builds it. */
function salesCtx(array $cfg, array $cat, string $msg): array {
    return BrainContext::build('unknown', ['customer' => null, 'channel' => 'sales', 'transport' => 'whatsapp', 'medium' => '',
        'products' => AiReplyWorker::salesCatalogue($cat, $cfg), 'message' => $msg, 'history' => []]);
}
function salesPrompt(array $cfg, array $cat, string $msg): string { return (new DishNetAiBrain($cfg))->promptPreview(salesCtx($cfg, $cat, $msg)); }

// ════════════════════════════════════════════════════════════════════════════
echo "\n1. Which accessories are Starlink routers: the shop catalogue says, by exact name\n";
NetworkEquipment::reset();
$r = NetworkEquipment::starlinkRouters($root, $ACC);
is_(array_column($r, 'name') === ['Router Mini', 'Router 3 | Starlink V4 or V5, Mini'], 'exactly the two routers, in the order uCRM listed them',
    json_encode(array_column($r, 'name')));
is_(($r[0]['fits'] ?? '') === 'Standard 4, Standard 4 X, Mini, Gen 2 kits (not Gen 1)', 'Router Mini carries the fit line the shop shows');
is_(($r[1]['fits'] ?? '') === 'Standard 4, Standard 4 X, Mini, Gen 2 and Gen 3 kits', 'and so does Router 3');
is_(($r[0]['price'] ?? null) === 301000 && ($r[1]['price'] ?? null) === 827000, 'the prices are the live rows\'');
is_(NetworkEquipment::starlinkRouters($root, [['name' => 'Router 3 Mount', 'price' => 226000]]) === [], 'a mount named after a router is not a router');
is_(NetworkEquipment::starlinkRouters($root, [['name' => 'Starlink Router Gen 3 Pro', 'price' => 900000], ['name' => 'Mesh Router', 'price' => 1]]) === [],
    'a router-like name the catalogue does not hold is not guessed at');
is_(count(NetworkEquipment::starlinkRouters($root, [['name' => '  router   MINI ', 'price' => 301000]])) === 1,
    'a name is matched as the shop matches it, case and spacing aside');
is_(NetworkEquipment::starlinkRouters($root, []) === [], 'no accessories, no routers');

// ════════════════════════════════════════════════════════════════════════════
echo "\n2. The Uganda prompt: the routers marked, the rule for more floors, the network equipment outdoors\n";
$p = salesPrompt($UG, $LIVE, 'The WiFi does not reach the upper floors of my house');
has('Router Mini is marked a Starlink router, with what it fits', $p,
    "- Router Mini — price 301000 one-time — Starlink router: Wi-Fi inside the building, working with the other Starlink routers as a mesh; fits Standard 4, Standard 4 X, Mini, Gen 2 kits (not Gen 1)\n");
has('and Router 3', $p,
    "- Router 3 | Starlink V4 or V5, Mini — price 827000 one-time — Starlink router: Wi-Fi inside the building, working with the other Starlink routers as a mesh; fits Standard 4, Standard 4 X, Mini, Gen 2 and Gen 3 kits\n");
has('the mount named after a router is listed plainly', $p, "- Router 3 Mount — price 226000 one-time\n");
has('and so is the other mount', $p, "- Wall Mount | Mini — price 95000 one-time\n");
is_(substr_count($p, '— Starlink router:') === 2, 'exactly two lines are marked');
$floors = block($p, 'MORE FLOORS OR ROOMS INSIDE ONE BUILDING — STARLINK ROUTERS.');
is_($floors !== '', 'the rule for more floors is there');
has('the answer is Starlink routers as a mesh', $floors, 'the answer is more Starlink routers working together as a mesh');
has('the items it points at are the marked ones', $floors, 'the items marked Starlink router in ACCESSORIES');
has('not the outdoor access point, not the MikroTik', $floors, 'Not the outdoor access point and not the MikroTik');
has('the routers that fit their kit, each with its price', $floors, 'Offer the Starlink routers that fit their kit, each with its price');
has('with the kit unknown: the routers and the question in one reply', $floors,
    'name the routers with what each fits and, in the same reply, ask which kit they have');
has('one for each floor beyond the main router\'s', $floors, 'price one Starlink router for each floor beyond the one their main router is on');
has('as quantity × price = amount, then a TOTAL', $floors, 'written as quantity × price = amount, then a clearly labelled TOTAL');
has('otherwise one, and the price of each more', $floors, 'Otherwise price ONE and say what each additional one costs');
has('the survey confirms', $floors, 'the site survey confirms how many routers are needed and where they go');
has('no coverage figure', $floors, 'Never state an area, a distance or a number of users that a router covers');
has('the kit first for someone without Starlink', $floors, 'the kit and the installation from HARDWARE come first');
has('and the survey booked through a hand-over', $floors, 'take the location and emit <<ESCALATE');
has('the network equipment is said to be for outdoors', block($p, 'COVERING A BIGGER AREA OR ANOTHER BUILDING'),
    '- This is for OUTDOORS and other buildings. More floors or rooms inside one building are covered with Starlink routers (MORE FLOORS OR ROOMS, below), never with the outdoor access point.');
$iAcc = strpos($p, "\nACCESSORIES ("); $iOut = strpos($p, 'This is for OUTDOORS'); $iRule = strpos($p, 'MORE FLOORS OR ROOMS INSIDE');
is_($iAcc !== false && $iOut !== false && $iRule !== false && $iOut < $iAcc && $iAcc < $iRule,
    'the outdoor line comes first, then the list, then the rule it calls "below"');
$w = (new DishNetAiBrain($UG))->promptPreview(BrainContext::build('anonymous', ['channel' => 'sales', 'transport' => 'web', 'medium' => '',
    'message' => 'upper floors?', 'products' => BrainContext::catalogue($LIVE, $UG), 'history' => []]));
has('the website chat is given the same rule', $w, 'MORE FLOORS OR ROOMS INSIDE ONE BUILDING — STARLINK ROUTERS.');
$s = (new DishNetAiBrain($UG))->promptPreview(['channel' => 'support', 'message' => 'upper floors?', 'history' => [], 'customer' => null, 'products' => $LIVE]);
has('and so is the support number', $s, 'MORE FLOORS OR ROOMS INSIDE ONE BUILDING — STARLINK ROUTERS.');
$m = salesPrompt($UG, ['products' => $PLANS, 'hardware' => $HW, 'accessories' => [['name' => 'Router 3 Mount', 'price' => 226000]]], 'upper floors?');
none('with only a mount listed there is no rule for more floors', $m, 'MORE FLOORS OR ROOMS');
none('and no outdoor line pointing at one', $m, 'This is for OUTDOORS');

// ════════════════════════════════════════════════════════════════════════════
echo "\n3. Nowhere else: South Sudan, the module off, and a Uganda price list with no Starlink router\n";
foreach (['sales', 'support', 'account'] as $ch) {
    $q = (new DishNetAiBrain($SS))->promptPreview(['channel' => $ch, 'message' => 'upper floors?', 'history' => [], 'customer' => null, 'products' => $LIVE]);
    is_(strpos($q, '— Starlink router:') === false && strpos($q, 'MORE FLOORS OR ROOMS') === false && strpos($q, 'This is for OUTDOORS') === false,
        "South Sudan's {$ch} number, the routers in its list: nothing marked, no rule, no outdoor line");
}
$off = salesPrompt(['ai_hardware_expert' => '0'] + $UG, $LIVE, 'upper floors?');
is_(strpos($off, '— Starlink router:') === false && strpos($off, 'MORE FLOORS OR ROOMS') === false, 'Uganda with the hardware module off: nothing marked, no rule');
$golden = json_decode((string)file_get_contents(__DIR__ . '/fixtures/ai_prompt_golden_uganda_no_router.json'), true);
$G = (array)($golden['fingerprints'] ?? []);
is_(count($G) === 26 && ($golden['plugin'] ?? '') === '5.18.44', 'the golden holds 26 fingerprints from 5.18.44');
$GCATS = [
    'mounts' => ['products' => $PLANS, 'hardware' => $HW, 'accessories' => [['name' => 'Router 3 Mount', 'price' => 226000], ['name' => 'Wall Mount | Mini', 'price' => 95000],
                 ['name' => 'Pipe Adapter | Standard 4 or 4 X', 'price' => 120000], ['name' => 'Travel Kit | Mini', 'price' => 180000]]],
    'none'   => ['products' => $PLANS, 'hardware' => $HW],
];
$GMSGS = ['my wifi does not reach the upper floors', 'I want the wifi to reach my other building in the compound', 'Is it unlimited?', 'How much is a wall mount?'];
/** The fingerprints of gen_golden (docs/40 §14): prompts on three paths, and the price-check list. */
$fingerprints = function (array $cats) use ($UG, $GMSGS): array {
    $b = new DishNetAiBrain($UG); $out = [];
    foreach ($cats as $cn => $cat) {
        foreach ($GMSGS as $mi => $msg) {
            $out["{$cn}-m{$mi}-sales-contract"] = sha1($b->promptPreview(salesCtx($UG, $cat, $msg)));
            $out["{$cn}-m{$mi}-support-legacy"] = sha1($b->promptPreview(['channel' => 'support', 'message' => $msg, 'history' => [], 'customer' => null, 'products' => $cat]));
            $out["{$cn}-m{$mi}-web-contract"] = sha1($b->promptPreview(BrainContext::build('anonymous', ['channel' => 'sales', 'transport' => 'web', 'medium' => '',
                'message' => $msg, 'products' => BrainContext::catalogue($cat, $UG), 'history' => []])));
        }
        $ctx = salesCtx($UG, $cat, $GMSGS[0]);
        $v = AiReplyWorker::permittedAmounts($ctx, $b->promptPreview($ctx), $UG); sort($v, SORT_STRING);
        $out["{$cn}-price-check"] = sha1(implode("\n", array_values(array_unique($v))));
    }
    return $out;
};
$got = $fingerprints($GCATS);
$same = 0; $diff = [];
foreach ($G as $k => $h) { if (($got[$k] ?? null) === $h) $same++; else $diff[] = $k; }
is_($same === 26 && $diff === [], 'no Starlink router listed: all 24 prompts and both price-check lists are 5.18.44\'s, byte for byte',
    "{$same} identical; differ: " . implode(', ', array_slice($diff, 0, 8)));
// Control on the golden: the same inputs with a Starlink router among the mounts move the fingerprints.
$withRouter = $GCATS; $withRouter['mounts']['accessories'][] = ['name' => 'Router Mini', 'price' => 301000];
$moved = $fingerprints(['mounts' => $withRouter['mounts']]);
is_(($moved['mounts-m0-sales-contract'] ?? '') !== ($G['mounts-m0-sales-contract'] ?? '') && ($moved['mounts-price-check'] ?? '') !== ($G['mounts-price-check'] ?? ''),
    'control: with a Starlink router added, the prompt and the price-check list both move');

// ════════════════════════════════════════════════════════════════════════════
echo "\n4. The price check: the totals a floors design produces, and no more\n";
$ctx  = salesCtx($UG, $LIVE, 'The WiFi does not reach the upper floors of my house');
$pr   = (new DishNetAiBrain($UG))->promptPreview($ctx);
$vals = AiReplyWorker::permittedAmounts($ctx, $pr, $UG);
$sent = fn(string $reply): bool => (bool)ReplyPrivacyGuard::check($reply, ['values' => $vals, 'prompt' => $pr])['safe'];
foreach ([2, 3, 4, 5] as $q) is_($sent("{$q} × Router Mini — UGX 301,000 each = UGX " . number_format(301000 * $q)), "{$q} × Router Mini is sent");
is_($sent('2 × Router 3 | Starlink V4 or V5, Mini — UGX 827,000 each = UGX 1,654,000'), '2 × Router 3 is sent');
is_($sent("Starlink Standard Kit — UGX 2,649,000\nProfessional Installation — UGX 150,000\n2 × Router Mini — UGX 301,000 each = UGX 602,000\nTOTAL: UGX 3,401,000"),
    'the kit, the installation and two routers, with its total, is sent');
is_($sent("Starlink Mini Kit + Mini Router — UGX 2,249,000\nProfessional Installation — UGX 150,000\n3 × Router 3 | Starlink V4 or V5, Mini — UGX 827,000 each = UGX 2,481,000\nTOTAL: UGX 4,880,000"),
    'the Mini kit, the installation and three Router 3s is sent');
is_(!$sent('6 × Router Mini = UGX 1,806,000'), 'six of one router is refused: five is the most a design is priced with');
is_(!$sent('2 × Router Mini = UGX 612,000'), 'a wrong multiple is refused');
is_(!$sent('TOTAL: UGX 3,411,000'), 'a total that is not the sum is refused');
$ctxOff = salesCtx(['ai_hardware_expert' => '0'] + $UG, $LIVE, 'x');
$prOff  = (new DishNetAiBrain(['ai_hardware_expert' => '0'] + $UG))->promptPreview($ctxOff);
$valsOff = AiReplyWorker::permittedAmounts($ctxOff, $prOff, ['ai_hardware_expert' => '0'] + $UG);
is_(!ReplyPrivacyGuard::check('2 × Router Mini = UGX 602,000', ['values' => $valsOff, 'prompt' => $prOff])['safe'],
    'with the module off, two routers are refused, as they were');
// Round figures, 100,000 to 10,000,000 in steps of 50,000: a design's totals must not let through one that 5.18.44
// refused. The list is 5.18.44's with these same inputs, measured from a4abe5e (docs/40 §14).
$ROUND_44 = [100000,150000,250000,350000,500000,700000,800000,850000,950000,1050000,1100000,1200000,1250000,1300000,1350000,1400000,1450000,
    1500000,1550000,1750000,1800000,1900000,1950000,2050000,2100000,2200000,2300000,2350000,2400000,2450000,2500000,2550000,2650000,2700000,
    2800000,2850000,2900000,2950000,3000000,3050000,3100000,3150000,3200000,3300000,3400000,3450000,3550000,3650000,3750000,3800000,3900000,
    4000000,4050000,4100000,4150000,4200000,4250000,4300000,4400000,4500000,4550000,4650000,5000000,5100000,5200000,5250000,5350000,5500000,
    5600000,5650000,5750000,6200000,6300000,6350000,6450000,9500000,9700000,10000000];
$round = [];
for ($a = 100000; $a <= 10000000; $a += 50000) if ($sent('TOTAL: UGX ' . number_format($a))) $round[] = $a;
is_($round === $ROUND_44, 'the round figures let through are exactly 5.18.44\'s (' . count($ROUND_44) . ' of 199): the routers add none',
    'now ' . count($round) . '; new: ' . implode(', ', array_diff($round, $ROUND_44)) . '; gone: ' . implode(', ', array_diff($ROUND_44, $round)));

// ════════════════════════════════════════════════════════════════════════════
echo "\n5. BUSINESS_PLANS says what the operator approved: about 1 Mbps once the priority block is used\n";
$SEED = json_decode((string)file_get_contents("{$root}/tools/knowledge_seed.json"), true);
$seedBy = [];
foreach ((array)$SEED['items'] as $it) $seedBy[(string)$it['item_key']] = $it;
$bp = $seedBy['BUSINESS_PLANS'] ?? ['answer' => '', 'wa_answer' => ''];
has('the answer: about 1 Mbps until more is bought', $bp['answer'], 'when that block is used up, the connection drops to about 1 Mbps until more is bought');
has('and for the small block', $bp['answer'], 'a busy site can use it in days or hours, and then the connection drops to about 1 Mbps');
has('the short form says it too', $bp['wa_answer'], 'after it, about 1 Mbps until more is bought');
foreach (['standard data continues', 'behaves like standard data', 'then unlimited standard data'] as $w) {
    none("no \"{$w}\"", $bp['answer'] . ' ' . $bp['wa_answer'], $w);
}
has('the Residential plans stay the unlimited ones', $bp['answer'], 'higher-capacity Residential plan with its unlimited standard data');
has('it now agrees with the note appended to Business replies', PlanFenceGuard::DEFAULT_NOTE, 'drops to about 1 Mbps until more');
is_(mb_strlen($bp['answer']) <= KnowledgeBase::ANSWER_LIMIT, 'the answer fits the 1,000-character cut (' . mb_strlen($bp['answer']) . ')');
is_(mb_strlen($bp['wa_answer']) <= 300, 'the short form fits the 300-character cut (' . mb_strlen($bp['wa_answer']) . ')');
$kbDir = sys_get_temp_dir() . '/dn-t45-kb-' . getmypid() . '-' . bin2hex(random_bytes(3));
@mkdir($kbDir, 0700, true);
register_shutdown_function(function () use ($kbDir) { exec('rm -rf ' . escapeshellarg($kbDir)); });
$kbStore = SqliteStore::create($kbDir);
KnowledgeSeeder::apply($kbStore->getPdo(), array_values($seedBy), false);
$kb = KnowledgeBase::promptBlock($kbStore->getPdo(), KnowledgeBase::answerLimit($UG));
has('the whole answer reaches the assistant', $kb, $bp['answer']);
has('and the whole short form', $kb, '(short form for chat: ' . $bp['wa_answer'] . ')');

echo "\n5b. The deploy's correction: seed_knowledge.php --refresh-seeded --only=BUSINESS_PLANS\n";
// Executed against a throwaway plugin root whose ucrm.json names a throwaway data directory. lib/ and tools/ are real
// copies (-L): a linked tools/ resolves to the real plugin, and the tool then opens the real plugin's database.
$kr = sys_get_temp_dir() . '/dn-t45-kr-' . getmypid() . '-' . bin2hex(random_bytes(3));
mkdir("{$kr}/p", 0700, true); mkdir("{$kr}/data", 0700, true);
exec('cp -rL ' . escapeshellarg("{$root}/lib") . ' ' . escapeshellarg("{$root}/tools") . ' ' . escapeshellarg("{$kr}/p/"));
symlink(realpath("{$root}/migrations"), "{$kr}/p/migrations");
file_put_contents("{$kr}/p/ucrm.json", json_encode(['pluginDataDir' => "{$kr}/data"]));
register_shutdown_function(function () use ($kr) { exec('rm -rf ' . escapeshellarg($kr)); });
/** The install as 5.18.44 left it: BUSINESS_PLANS with its old text, still as seeded (or a person's); one row drifted; one a person's. */
$seedLive = function (string $bpBy = 'seed') use ($kr, $seedBy): void {
    foreach (['', '-wal', '-shm'] as $x) @unlink("{$kr}/data/plugin.sqlite3{$x}");
    $st = SqliteStore::create("{$kr}/data"); $pdo = $st->getPdo();
    KnowledgeSeeder::apply($pdo, array_values($seedBy), false);
    $pdo->prepare("UPDATE knowledge_items SET answer = ?, wa_answer = ?, updated_by = ? WHERE item_key = 'BUSINESS_PLANS'")
        ->execute(['OLD TEXT: after that block is used, unlimited standard data continues.', 'OLD SHORT: then unlimited standard data.', $bpBy]);
    $pdo->prepare("UPDATE knowledge_items SET answer = 'DRIFTED, still as seeded' WHERE item_key = 'MANY_USERS_HOTSPOT'")->execute();
    $pdo->prepare("UPDATE knowledge_items SET answer = 'Written by a person', updated_by = 'admin' WHERE item_key = 'PUBLIC_IP'")->execute();
    $pdo = null; $st = null;
};
$rows = function () use ($kr): array {
    $pdo = new PDO('sqlite:' . "{$kr}/data/plugin.sqlite3");
    $r = $pdo->query("SELECT item_key, answer, wa_answer, updated_by FROM knowledge_items")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $pdo = null;
    $by = []; foreach ($r as $x) $by[$x['item_key']] = $x;
    return $by;
};
$tool = function (string $args) use ($kr): array {
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("{$kr}/p/tools/seed_knowledge.php") . ' ' . $args . ' 2>&1', $o, $rc);
    return [$rc, implode("\n", $o)];
};
$seedLive();
$before = $rows();
[$rc, $o] = $tool('--refresh-seeded --only=BUSINESS_PLANS --dry-run');
is_($rc === 0 && strpos($o, 'would correct: BUSINESS_PLANS') !== false, 'the dry run names the one row it would correct', $o);
none('and no other', $o, 'would correct: MANY_USERS_HOTSPOT');
is_($rows() === $before, 'and nothing was written');
[$rc, $o] = $tool('--refresh-seeded --only=BUSINESS_PLANS');
$after = $rows();
is_($rc === 0 && strpos($o, 'corrected: BUSINESS_PLANS') !== false, 'the real run corrects it', $o);
is_(($after['BUSINESS_PLANS']['answer'] ?? '') === (string)$bp['answer'] && ($after['BUSINESS_PLANS']['wa_answer'] ?? '') === (string)$bp['wa_answer'],
    'the answer and the short form are the seed\'s, word for word');
is_(($after['MANY_USERS_HOTSPOT']['answer'] ?? '') === 'DRIFTED, still as seeded', 'a row it was not told about is left alone, drifted or not');
is_(($after['PUBLIC_IP']['answer'] ?? '') === 'Written by a person', 'and a row a person wrote is never touched');
[$rc, $o] = $tool('--refresh-seeded --only=BUSINESS_PLANS --dry-run');
is_($rc === 0 && strpos($o, '0 would correct') !== false, 'run again, it has nothing to do', $o);
$seedLive('admin');
$before = $rows();
[$rc, $o] = $tool('--refresh-seeded --only=BUSINESS_PLANS');
is_(strpos($o, 'Edited by hand') !== false && $rows() === $before, 'BUSINESS_PLANS edited by a person is reported and left exactly as it is', $o);

// ════════════════════════════════════════════════════════════════════════════
if ($withMutants) {
    echo "\n6. Weakened copies of the code must each fail this test\n";
    /** A copy of the plugin to weaken: lib/ and workers/ copied, the rest linked; $rel is copied too. */
    $sandbox = function (string $rel) use ($root): string {
        $tmp = sys_get_temp_dir() . '/dn-t45-m-' . getmypid() . '-' . bin2hex(random_bytes(3));
        mkdir($tmp, 0700, true);
        exec('cp -r ' . escapeshellarg("{$root}/lib") . ' ' . escapeshellarg("{$root}/workers") . ' ' . escapeshellarg("{$root}/manifest.json")
            . ' ' . escapeshellarg($tmp));
        foreach (['migrations', 'tools', 'assets', 'profiles', 'tests'] as $d) {
            if (!is_dir("{$root}/{$d}")) continue;
            if (strpos($rel, "{$d}/") !== 0) { symlink("{$root}/{$d}", "{$tmp}/{$d}"); continue; }
            // Real directories down to the file, everything beside them linked.
            $src = "{$root}/{$d}"; $dst = "{$tmp}/{$d}"; $parts = explode('/', substr($rel, strlen($d) + 1));
            while (true) {
                mkdir($dst, 0700, true);
                $head = array_shift($parts);
                foreach (scandir($src) ?: [] as $e) {
                    if ($e === '.' || $e === '..' || $e === $head) continue;
                    symlink("{$src}/{$e}", "{$dst}/{$e}");
                }
                if (!$parts) { copy("{$src}/{$head}", "{$dst}/{$head}"); break; }
                $src .= "/{$head}"; $dst .= "/{$head}";
            }
        }
        return $tmp;
    };
    $MUTANTS = [
        ['lib/NetworkEquipment.php', "if (\$it['kind'] !== 'accessory' || strcasecmp(\$it['category'], 'Router') !== 0) continue;",
         "if (\$it['kind'] !== 'accessory') continue;", 'every accessory taken for a router'],
        ['lib/NetworkEquipment.php', "if (\$k !== '' && isset(\$fits[\$k])) \$out[] = ", "if (false) \$out[] = ", 'no accessory ever a router'],
        ['lib/DishNetAiBrain.php', "if (is_array(\$ctx['products']['accessories'] ?? null) && \$this->networkDesign()) {",
         "if (is_array(\$ctx['products']['accessories'] ?? null)) {", 'the routers on every install'],
        ['lib/DishNetAiBrain.php', 'if ($network) $d .= $this->networkBlock($network, $routers !== []);',
         'if ($network) $d .= $this->networkBlock($network, true);', 'the outdoor line whether or not a router is listed'],
        ['lib/DishNetAiBrain.php', 'if ($routers) $d .= $this->moreFloorsBlock();', '', 'no rule for more floors'],
        ['lib/DishNetAiBrain.php', "if (\$k !== '' && isset(\$routerFits[\$k])) {", 'if (false) {', 'the routers not marked in the list'],
        ['lib/DishNetAiBrain.php', '"- If the customer says how many floors, price one Starlink router for each floor beyond "',
         '"- If the customer says how many floors, price one Starlink router for each floor "', 'the main router\'s floor priced too'],
        ['workers/AiReplyWorker.php', "foreach (\\NetworkEquipment::starlinkRouters(dirname(__DIR__), (array)(\$ctx['products']['accessories'] ?? [])) as \$r) {",
         'foreach ([] as $r) {', 'no router totals in the price check'],
        ['workers/AiReplyWorker.php', 'for ($q = 1; $q <= 5; $q++) $values[] = self::money($sum + $q * (float)$r[\'price\']);',
         'for ($q = 1; $q <= 9; $q++) $values[] = self::money($sum + $q * (float)$r[\'price\']);', 'more than five of one router allowed'],
        ['workers/AiReplyWorker.php', '$kits = array_slice($kitRows, 0, 6);', '$kits = [];', 'the routers never with the kit and the installation'],
        ['tools/knowledge_seed.json', 'when that block is used up, the connection drops to about 1 Mbps until more is bought.',
         'after that block is used, unlimited standard data continues.', 'BUSINESS_PLANS back to "unlimited standard data continues"'],
        ['tools/knowledge_seed.json', '(50GB/500GB/1TB); after it, about 1 Mbps until more is bought.',
         '(50GB/500GB/1TB), then unlimited standard data.', 'its short form back to "then unlimited standard data"'],
    ];
    foreach ($MUTANTS as [$rel, $old, $new, $label]) {
        $tmp = $sandbox($rel);
        $file = "{$tmp}/{$rel}";
        $src  = (string)file_get_contents($file);
        $n    = substr_count($src, $old);
        if ($n !== 1) { is_(false, "weakened copy \"{$label}\": its anchor occurs once in {$rel}", "found {$n} times"); exec('rm -rf ' . escapeshellarg($tmp)); continue; }
        file_put_contents($file, str_replace($old, $new, $src));
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --root=' . escapeshellarg($tmp) . ' --no-mutants 2>&1', $out, $rc);
        $fails = array_values(array_filter($out, fn($l) => strpos($l, '  FAIL ') === 0));
        is_($rc !== 0 && $fails !== [], "caught: {$label}", 'exit ' . $rc . ', ' . count($fails) . ' failure(s)');
        if ($fails) echo '         first: ' . trim(substr($fails[0], 7, 110)) . "\n";
        $out = [];
        exec('rm -rf ' . escapeshellarg($tmp));
    }
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
