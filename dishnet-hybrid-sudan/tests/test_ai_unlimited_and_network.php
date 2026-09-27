<?php
/**
 * test_ai_unlimited_and_network.php — 5.18.44 (docs/40): a business that needs unlimited data is answered with the
 * Residential plan, and a customer who wants the Wi-Fi to reach further is given a design and a price.
 *
 * Measured in the live conversations of 22-27 Sep 2026 (docs/40 §10): "Is it unlimited?" was answered "The plans we
 * offer are not unlimited"; nearly every customer who said "business" was steered to a Business plan; a would-be
 * reseller was told "we don't have a reselling program"; the outdoor access point and the MikroTik the operator had
 * priced in uCRM were never quoted, and a quote with the MikroTik in it would have been refused by the price check —
 * it was the seventh one-time item, and a total could combine only the first six.
 *
 * The operator's decisions of 27 Sep: the data-allowance wording, "keep as it is"; and on another area, "lets ai to
 * desing and give price of accespoint if avaible in system".
 *
 * Pinned here, each against the code it guards:
 *   1. NetworkEquipment names the live products by what they are for, and nothing else;
 *   2. the NETWORK EQUIPMENT list and the rule to design and price with it;
 *   3. the data-allowance fact — only where the install qualifies and a Residential plan is listed;
 *   4. a business is answered with the Residential plans (the ask rule, the prospect rule, qualification);
 *   5. the sales number and the website chat see the accessories where the hardware module is on, and only there;
 *   6. the price check allows the totals a design produces, keeps every total it allowed before, and still refuses
 *      an invented figure;
 *   7. approved knowledge reaches the prompt whole where the install qualifies, and cut at 600 everywhere else;
 *   8. South Sudan: all 50 prompts and the price-check list byte-identical to 5.18.43;
 *   9. weakened copies of the code each fail this test.
 *
 * Against another copy of the plugin:   php test_ai_unlimited_and_network.php --root=DIR [--no-mutants]
 */
declare(strict_types=1);

$opt  = getopt('', ['root:', 'no-mutants']);
$root = isset($opt['root']) ? rtrim((string)$opt['root'], '/') : dirname(__DIR__);
$withMutants = !isset($opt['no-mutants']);

foreach (['bootstrap_data', 'StoreInterface', 'JsonStore', 'SqliteStore', 'KnowledgeSeeder', 'KnowledgeBase',
          'ConversationService', 'BrainContext', 'PlanCatalogue', 'NetworkEquipment', 'ReplyPrivacyGuard',
          'DishNetAiBrain', 'EventBus'] as $lib) {
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
// The one-time products and plans as uCRM returned them on 27 Sep 2026 (docs/40 §10). The accessories are illustrative.
$LIVE = [
    'products' => [
        ['name' => 'Starlink Residential Lite ( up to 100 Mbps)', 'price' => 249000, 'period_months' => 1],
        ['name' => 'Residential (up to 400 Mbps)', 'price' => 329000, 'period_months' => 1],
        ['name' => 'Starlink Business 50 GB', 'price' => 175000, 'period_months' => 1],
        ['name' => 'Starlink Business 500 GB', 'price' => 285000, 'period_months' => 1],
        ['name' => 'Starlink Business 1TB', 'price' => 469000, 'period_months' => 1],
    ],
    'hardware' => [
        ['name' => 'Starlink Mini Kit + Mini Router', 'price' => 2249000],
        ['name' => 'Starlink Standard Kit', 'price' => 2649000],
        ['name' => 'Professional Installation', 'price' => 150000],
        ['name' => 'ICT Consultancy Charges', 'price' => 100000],
        ['name' => 'Ruijie Reyee RG-RAP6262(G)', 'price' => 1100000],
        ['name' => 'D-Link D.LINK CAT 6, OUTDOOR WATERPROOF CABLE', 'price' => 378000],
        ['name' => 'MikroTik L009 Series', 'price' => 700000],
        ['name' => 'RJ45 Cat6 Pass-Through Connector (PACK OF 100)', 'price' => 19500],
    ],
    'accessories' => [
        ['name' => 'Router Mini', 'price' => 301000, 'sku' => 'RM-1', 'fit' => 'Mini'],
        ['name' => 'Router 3 Mount', 'price' => 226000],
    ],
];

// The approved knowledge, from the seed, as the worker builds it: cut at the limit the configuration sets.
$kbDir = sys_get_temp_dir() . '/dn-t44-kb-' . getmypid() . '-' . bin2hex(random_bytes(3));
@mkdir($kbDir, 0700, true);
$kbStore = SqliteStore::create($kbDir);
$kbPdo   = $kbStore->getPdo();
$SEED    = json_decode((string)file_get_contents("{$root}/tools/knowledge_seed.json"), true);
KnowledgeSeeder::apply($kbPdo, (array)$SEED['items'], false);
register_shutdown_function(function () use ($kbDir) { exec('rm -rf ' . escapeshellarg($kbDir)); });
$seedBy = [];
foreach ((array)$SEED['items'] as $it) $seedBy[(string)$it['item_key']] = $it;

/** Uganda: the switches it runs, and its knowledge base. */
function ugConfig(array $extra = []): array {
    global $kbPdo;
    $c = $extra + ['ai_provider' => 'openai', 'openai_api_key' => 'x', 'ai_currency' => 'UGX', 'ai_qualification' => '1',
                   'ai_hardware_expert' => '1', 'ai_sales_on_all_numbers' => '1', 'ai_lead_capture' => '1'];
    if (!array_key_exists('knowledge_block', $extra)) {
        $c['knowledge_block'] = KnowledgeBase::promptBlock($kbPdo, KnowledgeBase::answerLimit($c));
    }
    return $c;
}
/** The sales number's prompt, built the way AiReplyWorker::buildContext builds it. */
function salesPrompt(array $cfg, array $cat, string $msg, array $history = []): string {
    $ctx = BrainContext::build('unknown', ['customer' => null, 'channel' => 'sales', 'transport' => 'whatsapp',
        'medium' => '', 'products' => AiReplyWorker::salesCatalogue($cat, $cfg), 'message' => $msg, 'history' => $history]);
    return (new DishNetAiBrain($cfg))->promptPreview($ctx);
}
$ESC = 'emit <<' . DishNetAiBrain::MARKER_ESCALATE . ' reason>>';   // the brain's own hint

// ════════════════════════════════════════════════════════════════════════════
echo "\n1. Network equipment is named by what it is for — the live names, and nothing else\n";
NetworkEquipment::reset();
$roles = NetworkEquipment::roles($root);
is_(count($roles) === 6, 'six roles load from assets/shop/network.json', (string)count($roles));
foreach (['Ruijie Reyee RG-RAP6262(G)' => 'access_point', 'MikroTik L009 Series' => 'router',
          'D-Link D.LINK CAT 6, OUTDOOR WATERPROOF CABLE' => 'cable',
          'RJ45 Cat6 Pass-Through Connector (PACK OF 100)' => 'connectors',   // contains "cat6": the connectors come first
          'ICT Consultancy Charges' => 'consultancy', 'Outdoor Access Point' => 'access_point',
          'MikroTik Router' => 'router', 'TP-Link EAP225 Access Point' => 'access_point'] as $name => $key) {
    $r = NetworkEquipment::roleFor($root, $name);
    is_(($r['key'] ?? null) === $key, "\"{$name}\" → {$key}", (string)json_encode($r));
}
has('the outdoor access point is labelled as outdoor, for another area or building',
    (string)(NetworkEquipment::roleFor($root, 'Ruijie Reyee RG-RAP6262(G)')['label'] ?? ''), 'outdoor Wi-Fi access point');
foreach (['Starlink Mini Kit + Mini Router', 'Starlink Standard Kit', 'Professional Installation', 'Router Mini',
          'Router 3 Mount', 'Wall Mount | Mini', 'Mini Ethernet Cable 15m', 'Residential (up to 400 Mbps)'] as $name) {
    is_(NetworkEquipment::roleFor($root, $name) === null, "\"{$name}\" is not network equipment");
}
[$other, $net] = NetworkEquipment::split($root, $LIVE['hardware']);
is_(array_column($other, 'name') === ['Starlink Mini Kit + Mini Router', 'Starlink Standard Kit', 'Professional Installation'],
    'split() leaves the kits and the installation, in uCRM order', (string)json_encode(array_column($other, 'name')));
is_(array_column($net, 'role_key') === ['router', 'access_point', 'cable', 'connectors', 'consultancy'],
    'and lists the network equipment in the order a setup is built', (string)json_encode(array_column($net, 'role_key')));
$raw = (string)file_get_contents("{$root}/assets/shop/network.json");
$noNote = (string)preg_replace('/"_note"\s*:\s*"(?:[^"\\\\]|\\\\.)*"/', '', $raw);
is_(!preg_match('/\d{3}/', $noNote), 'network.json carries no figure: no price and no coverage (prices come from uCRM)');
// A malformed role is skipped, not half-used.
$bad = sys_get_temp_dir() . '/dn-t44-ne-' . getmypid();
@mkdir("{$bad}/assets/shop", 0700, true);
file_put_contents("{$bad}/assets/shop/network.json", json_encode(['roles' => [
    ['key' => 'Bad Key!', 'label' => 'x', 'match' => ['a']], ['key' => 'router', 'label' => '', 'match' => ['b']],
    ['key' => 'router', 'label' => 'ok', 'match' => []], ['key' => 'router', 'label' => 'kept', 'match' => ['  MikroTik  ']]]]));
$r = NetworkEquipment::roles($bad);
is_(count($r) === 1 && $r[0]['match'] === ['mikrotik'], 'a role with a bad key, no label or no match string is skipped; match strings are normalised',
    (string)json_encode($r));
exec('rm -rf ' . escapeshellarg($bad));

// ════════════════════════════════════════════════════════════════════════════
echo "\n2. NETWORK EQUIPMENT, and the rule to design and price with it (Uganda: ai_hardware_expert)\n";
$ug = ugConfig();
$p  = salesPrompt($ug, $LIVE, 'I want the wifi to reach my other building in the compound');
$hw = block($p, "\nHARDWARE (one-time items");
$ne = block($p, "\nNETWORK EQUIPMENT (one-time");
has('HARDWARE lists the Mini kit', $hw, '- Starlink Mini Kit + Mini Router — price 2249000 one-time');
has('and the installation', $hw, '- Professional Installation — price 150000 one-time');
foreach (['MikroTik L009', 'RG-RAP6262', 'CAT 6', 'RJ45', 'ICT Consultancy'] as $n) none("HARDWARE no longer lists {$n}", $hw, $n);
is_($ne !== '', 'NETWORK EQUIPMENT is listed');
is_(strpos($p, "\nNETWORK EQUIPMENT (one-time") > strpos($p, "\nHARDWARE (one-time items")
    && strpos($p, "\nNETWORK EQUIPMENT (one-time") < strpos($p, "\nACCESSORIES (optional extras"),
    'after HARDWARE and before ACCESSORIES');
$lines = array_values(array_filter(explode("\n", $ne), fn($l) => strpos($l, ' one-time — ') !== false));
is_(count($lines) === 5, 'five items, each saying what it is for', (string)count($lines));
is_(($lines[0] ?? '') === '- MikroTik L009 Series — price 700000 one-time — MikroTik router: runs the local network and manages the access points',
    'the router first, its price and what it is for', $lines[0] ?? '');
has('the access point, its price and what it is for', $ne,
    '- Ruijie Reyee RG-RAP6262(G) — price 1100000 one-time — outdoor Wi-Fi access point, mounted on a pole or a wall');
has('the cable, whose length the survey confirms', $ne, 'the site survey confirms the length');
has('the rule is there', $p, 'COVERING A BIGGER AREA OR ANOTHER BUILDING — DESIGN IT AND PRICE IT.');
has('design and price in the same reply, not only a site assessment', $p, 'Do not only say that it needs a site assessment.');
has('one line per item, then a TOTAL, the plan after it', $p, 'then a clearly labelled TOTAL for that setup');
has('the customer\'s number of access points is used, written as quantity × price', $p, 'quantity × price = amount');
has('otherwise ONE access point and the cost of each additional one', $p, 'price ONE access point and say what each additional one costs');
has('the survey confirms the count, the cable and the installation', $p, 'the site survey confirms the number of access points, the cable length and the installation');
has('no distance, area or number of users is ever stated', $p, 'Never state a distance, an area or a number of users');
has('kilometres go to a survey and a person', $p, 'point-to-point design, and ' . $ESC);
has('an item not listed is confirmed, never asked of the customer', $p, 'Never ask the customer what we charge.');
has('never part of a home total', $p, 'NETWORK EQUIPMENT is never part of an ordinary TOTAL TO GET CONNECTED');
has('going ahead hands over to book the survey', $p, 'take the location and ' . $ESC);
$np = salesPrompt($ug, ['products' => $LIVE['products'], 'hardware' => array_merge($LIVE['hardware'], [['name' => 'Outdoor Omni Access Point', 'price' => null]])], 'price?');
has('an item with no price says so', $np, '- Outdoor Omni Access Point — price not listed (say you will confirm) one-time');
$kitOnly = ['products' => $LIVE['products'], 'hardware' => array_slice($LIVE['hardware'], 0, 3)];
$q = salesPrompt($ug, $kitOnly, 'I want the wifi to reach my other building');
none('no network equipment in uCRM: no list', $q, 'NETWORK EQUIPMENT (one-time');
none('and no design rule', $q, 'COVERING A BIGGER AREA OR ANOTHER BUILDING — DESIGN IT AND PRICE IT.');
$off = salesPrompt(ugConfig(['ai_hardware_expert' => '0']), $LIVE, 'I want the wifi to reach my other building');
none('the hardware module off: no list', $off, "\nNETWORK EQUIPMENT (one-time");
none('and no design rule', $off, 'COVERING A BIGGER AREA OR ANOTHER BUILDING — DESIGN IT AND PRICE IT.');
has('and the MikroTik is a HARDWARE line, as in 5.18.43', block($off, "\nHARDWARE (one-time items"), '- MikroTik L009 Series — price 700000 one-time');
has('the many-users rule recommends the higher-capacity Residential plan', $p, 'recommend the higher-capacity Residential plan (unlimited data)');
has('and designs from NETWORK EQUIPMENT where it is listed', $p, 'Where NETWORK EQUIPMENT is in your data, design and price a starting setup');

// ════════════════════════════════════════════════════════════════════════════
echo "\n3. \"Both Residential plans are unlimited\" — stated where the install qualifies, and nowhere else\n";
is_(DishNetAiBrain::UNLIMITED_FACT === 'Both Residential plans (Residential Lite and Residential) are unlimited, with no data cap. '
    . 'Only the Business plans come with a block of priority data (50 GB, 500 GB or 1 TB).',
    'the wording the operator approved on 27 Sep ("keep as it is"), word for word');
$pb = salesPrompt($ug, $LIVE, 'Is it unlimited?');
$line = 'DATA ALLOWANCE (a stated fact — repeat it word for word when asked): ' . DishNetAiBrain::UNLIMITED_FACT;
has('Uganda: the fact is given', $pb, $line);
$plans = block($pb, "PLANS (live");
has('with the plans, right after them', $plans, "- Residential (up to 400 Mbps) — price 329000 per month\n" . $line);
has('their own wording replaces it', salesPrompt(ugConfig(['ai_fact_unlimited' => 'Both Residential plans have no data cap.']), $LIVE, 'unlimited?'),
    'DATA ALLOWANCE (a stated fact — repeat it word for word when asked): Both Residential plans have no data cap.');
none('"omit" switches it off', salesPrompt(ugConfig(['ai_fact_unlimited' => 'omit']), $LIVE, 'unlimited?'), 'DATA ALLOWANCE');
none('not where the install does not qualify', salesPrompt(ugConfig(['ai_qualification' => '0']), $LIVE, 'unlimited?'), 'DATA ALLOWANCE');
none('not without a knowledge base', salesPrompt(ugConfig(['knowledge_block' => '']), $LIVE, 'unlimited?'), 'DATA ALLOWANCE');
$bizOnly = ['products' => array_slice($LIVE['products'], 2), 'hardware' => $LIVE['hardware']];
none('not when no Residential plan is listed', salesPrompt($ug, $bizOnly, 'I need a public IP for my cameras, unlimited?'), 'DATA ALLOWANCE');
// The reply check: the model is told to repeat the fact word for word, so a reply that does is not a prompt leak.
$quote = 'Yes! ' . DishNetAiBrain::UNLIMITED_FACT;
$g = ReplyPrivacyGuard::check($quote, ['values' => [], 'prompt' => $pb, 'public' => DishNetAiBrain::operatorText($ug)]);
is_(!empty($g['safe']), 'a reply that repeats it word for word passes the reply check', (string)json_encode($g['categories'] ?? []));
$g = ReplyPrivacyGuard::check($quote, ['values' => [], 'prompt' => $pb, 'public' => []]);
is_(empty($g['safe']) && in_array('system_prompt', (array)$g['categories'], true),
    'control: without it in the operator\'s text, the same reply is refused as a quote of the prompt', (string)json_encode($g['categories'] ?? []));
is_(in_array(DishNetAiBrain::UNLIMITED_FACT, DishNetAiBrain::operatorText($ug), true), 'Uganda: the guard\'s list carries the default wording');
is_(!in_array(DishNetAiBrain::UNLIMITED_FACT, DishNetAiBrain::operatorText(ugConfig(['ai_fact_unlimited' => 'omit'])), true), '"omit": not in the list');
is_(!in_array(DishNetAiBrain::UNLIMITED_FACT, DishNetAiBrain::operatorText(ugConfig(['knowledge_block' => ''])), true),
    'no knowledge base: not in the list, as in the prompt');
foreach ([['ai_provider' => 'openai', 'openai_api_key' => 'x'], ['claude_api_key' => 'k', 'ai_sales_on_all_numbers' => '1']] as $i => $ss) {
    is_(DishNetAiBrain::operatorText($ss) === [], "South Sudan-shaped config {$i}: the guard's list is exactly what it was (empty)",
        (string)json_encode(DishNetAiBrain::operatorText($ss)));
}

// ════════════════════════════════════════════════════════════════════════════
echo "\n4. A business is answered with the Residential plans; a Business plan is for a public IP\n";
is_(PlanCatalogue::askRule(false) === PlanCatalogue::ASK_RULE, 'askRule(false) is ASK_RULE, byte for byte');
$rule = PlanCatalogue::askRule(true);
has('askRule(true): a business is answered with the Residential plans', $rule, 'A CUSTOMER WHO IS A BUSINESS');
has('recommend the higher-capacity one, with unlimited data', $rule, 'recommend the higher-capacity one, say it has unlimited data');
has('never "you need Business because you are a business"', $rule, 'Never tell a business it needs a Business plan because it is a business.');
has('a Business plan is for a public IP, asked about once', $rule, 'A Business plan is for one thing, a PUBLIC IP');
$pa = salesPrompt($ug, $LIVE, 'Do you have unlimited business plans?');
has('Uganda, "unlimited business plans?": the Residential-first rule', $pa, 'A CUSTOMER WHO IS A BUSINESS');
none('not the old rule', $pa, PlanCatalogue::ASK_RULE);
is_(!preg_match('/^- Starlink Business .* — price /m', block($pa, 'PLANS (live')), 'and no Business plan in PLANS');
$ss = salesPrompt(['ai_provider' => 'openai', 'openai_api_key' => 'x', 'ai_sales_on_all_numbers' => '1'], $LIVE, 'Do you have unlimited business plans?');
has('an install that does not qualify: ASK_RULE, word for word', $ss, PlanCatalogue::ASK_RULE);
none('and not the new rule', $ss, 'A CUSTOMER WHO IS A BUSINESS');
$pn = salesPrompt($ug, $LIVE, 'How much is Starlink Business 500 GB?');
none('a customer who names a Business plan is shown them, and no ask rule at all', $pn, 'A CUSTOMER WHO IS A BUSINESS');
has('prospect rule, Uganda: Residential prices first, Business for a public IP', $pa,
    'Where they asked for business pricing and it is not in PLANS, give the Residential prices, say the Business plans are for a public IP');
has('prospect rule, elsewhere: as before, word for word', $ss,
    'prices, both residential and business when they asked for both; where Business pricing is not in PLANS, say the team confirms that one and');
has('someone who wants to sell internet: the rule for it', $pa, 'SOMEONE WHO WANTS TO SELL INTERNET');
has('the higher-capacity Residential plan is the plan', $pa, 'The higher-capacity Residential plan (unlimited data) is the plan');
has('the setup is designed and priced only where the equipment is listed', $pa,
    'Where NETWORK EQUIPMENT is in your data, design and price the setup as COVERING A BIGGER AREA says; where it is not, take the site details for a site assessment.');
has('never "we have no reseller programme"', $pa, 'Never tell them we have no reseller or partner programme: you do not know that.');
has('partner or commission terms go to a person', $pa, 'If they ask about partner, reseller or commission terms, take their details and ' . $ESC);
none('an install that does not qualify has none of it', $ss, 'SOMEONE WHO WANTS TO SELL INTERNET');

// ════════════════════════════════════════════════════════════════════════════
echo "\n5. The accessories reach the sales number and the website chat where the module is on, and only there\n";
$ctx = BrainContext::build('unknown', ['channel' => 'sales', 'products' => ['products' => [], 'hardware' => [],
    'accessories' => [['name' => 'Router Mini', 'price' => '301000', 'sku' => 'RM-1', 'fit' => 'Mini']]]]);
is_(($ctx['products']['accessories'] ?? null) === [['name' => 'Router Mini', 'price' => 301000.0]],
    'BrainContext keeps name and price only', (string)json_encode($ctx['products']['accessories'] ?? null));
$ctx = BrainContext::build('unknown', ['channel' => 'sales', 'products' => ['products' => [['name' => 'P', 'price' => 1]], 'hardware' => []]]);
is_(!array_key_exists('accessories', (array)($ctx['products'] ?? [])), 'none passed: no accessories key at all');
$on  = BrainContext::catalogue($LIVE, ['ai_hardware_expert' => '1', 'stock_statement' => 'In stock in Kampala']);
$offc = BrainContext::catalogue($LIVE, ['stock_statement' => 'In stock in Kampala']);
is_(isset($on['accessories']) && $on['stock'] === 'In stock in Kampala', 'catalogue(): module on — the accessories stay, the stock line is added');
is_(!isset($offc['accessories']) && $offc['stock'] === 'In stock in Kampala', 'module off — the accessories go, as the contract always dropped them');
is_(AiReplyWorker::salesCatalogue($LIVE, $ug) === BrainContext::catalogue($LIVE, $ug)
    && AiReplyWorker::salesCatalogue($LIVE, []) === BrainContext::catalogue($LIVE, []),
    'the sales number applies exactly that rule');
has('Uganda: the sales prompt has ACCESSORIES', $p, "\nACCESSORIES (optional extras");
none('module off: it has none', $off, 'ACCESSORIES (optional extras');
// Weakest evidence, stated as such: the two callers are read, the rule itself is executed above and in section 8.
$wc = (string)file_get_contents("{$root}/web_chat.php");
has('web_chat.php builds its catalogue through BrainContext::catalogue', $wc, '$_catalogue = BrainContext::catalogue(');
none('and no longer sets the stock line itself', $wc, "\$_catalogue['stock'] =");
has('the WhatsApp worker builds the sales context through salesCatalogue', (string)file_get_contents("{$root}/workers/AiReplyWorker.php"),
    '$products = self::salesCatalogue((array)($ctx[\'products\'] ?? []), (array)$this->config);');

// ════════════════════════════════════════════════════════════════════════════
echo "\n6. The price check allows the totals a design produces — and nothing invented\n";
$sctx = BrainContext::build('unknown', ['customer' => null, 'channel' => 'sales', 'transport' => 'whatsapp', 'medium' => '',
    'products' => AiReplyWorker::salesCatalogue($LIVE, $ug), 'message' => 'what do I need to cover my compound?', 'history' => []]);
$vals = AiReplyWorker::permittedAmounts($sctx, $p, $ug);
$check = fn(string $reply, array $values) => ReplyPrivacyGuard::check($reply, ['values' => $values, 'prompt' => $p, 'public' => DishNetAiBrain::operatorText($ug)]);
$ok = [
    'one of each network item, with the MikroTik (the seventh item)' =>
        "MikroTik L009 Series — UGX 700,000\nRuijie Reyee RG-RAP6262(G) — UGX 1,100,000\nD-Link cable — UGX 378,000\nRJ45 connectors — UGX 19,500\nICT Consultancy Charges — UGX 100,000\nTOTAL: UGX 2,297,500\nEach additional access point: UGX 1,100,000.",
    'two access points, written as quantity × price' =>
        "MikroTik L009 Series — UGX 700,000\n2 × Ruijie Reyee RG-RAP6262(G) — 2 × UGX 1,100,000 = UGX 2,200,000\nD-Link cable — UGX 378,000\nRJ45 connectors — UGX 19,500\nTOTAL: UGX 3,297,500",
    'a full setup with the Standard kit and the installation' =>
        "Starlink Standard Kit — UGX 2,649,000\nProfessional Installation — UGX 150,000\nMikroTik — UGX 700,000\nAccess point — UGX 1,100,000\nCable — UGX 378,000\nConnectors — UGX 19,500\nICT Consultancy — UGX 100,000\nTOTAL: UGX 5,096,500",
    'a home total, as before' => "Starlink Standard Kit — UGX 2,649,000\nProfessional Installation — UGX 150,000\nTOTAL TO GET CONNECTED: UGX 2,799,000",
];
foreach ($ok as $what => $reply) {
    $g = $check($reply, $vals);
    is_(!empty($g['safe']), "sent: {$what}", (string)json_encode($g['categories'] ?? []));
}
$no = [
    'an invented access-point price' => 'The access point is UGX 1,234,000.',
    'three packs of connectors (only an access point is multiplied)' => '3 × UGX 19,500 = UGX 58,500',
    'a total that does not add up' => 'TOTAL: UGX 2,297,000',
    'six access points and the MikroTik' => '6 × UGX 1,100,000 plus the MikroTik: UGX 7,300,000',
];
foreach ($no as $what => $reply) {
    $g = $check($reply, $vals);
    is_(empty($g['safe']) && in_array('foreign:amount', (array)$g['categories'], true), "refused: {$what}", (string)json_encode($g));
}
// GAP, recorded and not fixed here (docs/40 §11, F-4): the guard also accepts any amount whose digits occur anywhere
// in the prompt's digits run together. 1,500,000 is no listed price and no combination, yet passes. It predates 5.18.44
// and changes both installs if fixed, so the fix is proposed separately; this line flips when it lands.
$g = $check('The access point is UGX 1,500,000.', $vals);
is_(!empty($g['safe']) && !in_array('1,500,000', $vals, true) && strpos((string)preg_replace('/\D/', '', $p), '1500000') !== false,
    'GAP (recorded): an amount found only inside the prompt\'s run-together digits still passes', (string)json_encode($g['categories'] ?? []));
$valsOff = AiReplyWorker::permittedAmounts($sctx, $p, ['ai_qualification' => '1']);
$g = $check($ok['one of each network item, with the MikroTik (the seventh item)'], $valsOff);
is_(empty($g['safe']), 'the hardware module off: the MikroTik total is refused, as in 5.18.43');
// Every total 5.18.43 allowed is still allowed, and with the module off the list is 5.18.43's, in its order.
$PERM_CTX = [
    'identity_state' => 'unknown', 'channel' => 'sales',
    'message' => 'How much for the kit and two access points? call me 0772 123456',
    'history' => [['role' => 'customer', 'text' => 'Hi, I need internet for my shop in Mukono'], ['role' => 'dishnet', 'text' => 'Welcome!']],
    'products' => [
        'products' => [['name' => 'Starlink Residential Lite ( up to 100 Mbps)', 'price' => 249000, 'period_months' => 1],
                       ['name' => 'Residential (up to 400 Mbps)', 'price' => 329000, 'period_months' => 1],
                       ['name' => 'Starlink Business 50 GB', 'price' => 175000, 'period_months' => 1],
                       ['name' => 'Starlink Business 500 GB', 'price' => 285000, 'period_months' => 1],
                       ['name' => 'Starlink Business 1TB', 'price' => 469000, 'period_months' => 1]],
        'hardware' => [['name' => 'Starlink Mini Kit + Mini Router', 'price' => 2249000], ['name' => 'Starlink Standard Kit', 'price' => 2649000],
                       ['name' => 'Professional Installation', 'price' => 150000], ['name' => 'ICT Consultancy Charges', 'price' => 100000],
                       ['name' => 'Ruijie Reyee RG-RAP6262(G)', 'price' => 1100000], ['name' => 'D-Link D.LINK CAT 6, OUTDOOR WATERPROOF CABLE', 'price' => 378000],
                       ['name' => 'MikroTik L009 Series', 'price' => 700000], ['name' => 'RJ45 Cat6 Pass-Through Connector (PACK OF 100)', 'price' => 19500]],
        'accessories' => [['name' => 'Router Mini', 'price' => 301000], ['name' => 'Router 3 Mount', 'price' => 226000], ['name' => 'Wall Mount | Mini', 'price' => 95000]],
        'stock' => '',
    ],
];
$PERM_PROMPT = "PLANS\n- Residential — price 329000 per month\nCall 0200 903 222 or pay 4428146.\n";
$off43 = AiReplyWorker::permittedAmounts($PERM_CTX, $PERM_PROMPT, ['ai_qualification' => '1']);
// sha1 of json_encode of the list 5.18.43's AiReplyWorker::permittedValues returned for these inputs (commit 04155df).
is_(sha1((string)json_encode($off43)) === 'a405ac494d27287e4c71851cb16d592aa1005987',
    'module off: the list is 5.18.43\'s, value for value and in its order (golden)', sha1((string)json_encode($off43)) . ' / ' . count($off43));
$on44 = AiReplyWorker::permittedAmounts($PERM_CTX, $PERM_PROMPT, ['ai_qualification' => '1', 'ai_hardware_expert' => '1']);
$missing = array_diff(array_unique($off43), $on44);
is_($missing === [], 'module on: every total the old check allowed is still allowed', implode(', ', array_slice($missing, 0, 5)));
is_(count($on44) > count($off43), 'and the new totals are added to them', count($off43) . ' → ' . count($on44));
// Bounded: thirty one-time items, ten of them access points.
$many = [];
for ($i = 1; $i <= 30; $i++) $many[] = ['name' => ($i <= 10 ? "Outdoor Access Point {$i}" : "Item {$i}"), 'price' => 10000 * $i + 7];
$accMany = [];
for ($i = 1; $i <= 24; $i++) $accMany[] = ['name' => "Mount {$i}", 'price' => 1000 * $i];
$t0 = microtime(true);
$big = AiReplyWorker::permittedAmounts(['products' => ['products' => [], 'hardware' => $many, 'accessories' => $accMany]], '', ['ai_hardware_expert' => '1']);
$ms = (int)round((microtime(true) - $t0) * 1000);
is_(count($big) < 100000 && $ms < 3000, 'thirty one-time items, ten access points, 24 accessories: bounded in size and time',
    count($big) . " values, {$ms} ms");

// ════════════════════════════════════════════════════════════════════════════
echo "\n7. Approved knowledge reaches the prompt whole where the install qualifies\n";
is_(KnowledgeBase::answerLimit($ug) === 1000, 'Uganda (ai_qualification): 1,000 characters');
is_(KnowledgeBase::answerLimit(['ai_hardware_expert' => '1']) === 600 && KnowledgeBase::answerLimit([]) === 600,
    'everywhere else: 600, as before');
$longest = ''; $max = 0;
foreach ((array)$SEED['items'] as $it) {
    if (($it['kind'] ?? '') === 'fact' && mb_strlen((string)$it['answer']) > $max) { $max = mb_strlen((string)$it['answer']); $longest = (string)$it['item_key']; }
}
is_($max <= KnowledgeBase::ANSWER_LIMIT, "no seeded answer is longer than the limit (longest: {$longest}, {$max})");
$kb1000 = KnowledgeBase::promptBlock($kbPdo, 1000);
$kbOld  = KnowledgeBase::promptBlock($kbPdo);
foreach (['BUSINESS_PLANS', 'MANY_USERS_HOTSPOT'] as $k) {
    has("{$k} reaches the prompt whole in Uganda", $kb1000, (string)$seedBy[$k]['answer'] . "\n");
    has("{$k} is cut at 600 elsewhere, exactly as before", $kbOld, mb_substr((string)$seedBy[$k]['answer'], 0, 600) . "\n");
}
is_($kbOld === KnowledgeBase::promptBlock($kbPdo, 600), 'the default limit is the old one');
has('the unlimited sentence of MANY_USERS_HOTSPOT reaches the Uganda prompt', $p, 'usually belongs on the higher-capacity Residential plan with unlimited standard data');
$kbLong = sys_get_temp_dir() . '/dn-t44-kbl-' . getmypid();
@mkdir($kbLong, 0700, true);
$ls = SqliteStore::create($kbLong);
KnowledgeSeeder::apply($ls->getPdo(), [['item_key' => 'LONG', 'kind' => 'fact', 'title' => 'Long', 'answer' => str_repeat('x', 1199) . 'Z']], false);
$lb = KnowledgeBase::promptBlock($ls->getPdo(), 1000);
is_(strpos($lb, '- [LONG] Long: ' . str_repeat('x', 1000)) !== false && strpos($lb, str_repeat('x', 1001)) === false && strpos($lb, 'Z') === false,
    'control: a longer answer is cut at exactly 1,000');
$ls = null; exec('rm -rf ' . escapeshellarg($kbLong));
$many = (string)$seedBy['MANY_USERS_HOTSPOT']['answer'];
has('MANY_USERS_HOTSPOT now designs and prices where the equipment is listed', $many, 'design a starting setup from it and price it');
has('then hands over to book the survey', $many, 'then take the location and hand over to book the survey');
has('and keeps the site assessment for where it is not', $many, 'Where it is not, take the location');
is_(mb_strlen((string)$seedBy['MANY_USERS_HOTSPOT']['wa_answer']) <= 300, 'its short form fits the 300-character cut');

// ════════════════════════════════════════════════════════════════════════════
echo "\n7b. The knowledge correction the deploy makes: seed_knowledge.php --refresh-seeded --only=MANY_USERS_HOTSPOT\n";
// The tool, executed, against a throwaway plugin root whose ucrm.json names a throwaway data directory. lib/ and tools/
// are real copies (-L): a linked tools/ resolves to the real plugin, and the tool then opens the real plugin's database
// — measured while building this.
$kr = sys_get_temp_dir() . '/dn-t44-kr-' . getmypid() . '-' . bin2hex(random_bytes(3));
mkdir("{$kr}/p", 0700, true); mkdir("{$kr}/data", 0700, true);
exec('cp -rL ' . escapeshellarg("{$root}/lib") . ' ' . escapeshellarg("{$root}/tools") . ' ' . escapeshellarg("{$kr}/p/"));
symlink(realpath("{$root}/migrations"), "{$kr}/p/migrations");
file_put_contents("{$kr}/p/ucrm.json", json_encode(['pluginDataDir' => "{$kr}/data"]));
register_shutdown_function(function () use ($kr) { exec('rm -rf ' . escapeshellarg($kr)); });
/** The install as 5.18.43 left it: every row as seeded, MANY_USERS_HOTSPOT with its old text, one other row drifted, one edited by hand. */
$seedLive = function (string $manyBy = 'seed') use ($kr, $seedBy): void {
    @unlink("{$kr}/data/plugin.sqlite3"); @unlink("{$kr}/data/plugin.sqlite3-wal"); @unlink("{$kr}/data/plugin.sqlite3-shm");
    $st = SqliteStore::create("{$kr}/data"); $pdo = $st->getPdo();
    KnowledgeSeeder::apply($pdo, array_values($seedBy), false);
    $pdo->prepare("UPDATE knowledge_items SET answer = ?, updated_by = ? WHERE item_key = 'MANY_USERS_HOTSPOT'")
        ->execute(['OLD TEXT: take the location and hand over for a site assessment.', $manyBy]);
    $pdo->prepare("UPDATE knowledge_items SET answer = 'DRIFTED, still as seeded' WHERE item_key = 'BUSINESS_PLANS'")->execute();
    $pdo->prepare("UPDATE knowledge_items SET answer = 'Written by a person', updated_by = 'admin' WHERE item_key = 'PUBLIC_IP'")->execute();
    $pdo = null; $st = null;
};
$rows = function () use ($kr): array {
    $pdo = new PDO('sqlite:' . "{$kr}/data/plugin.sqlite3");
    $r = $pdo->query("SELECT item_key, answer, updated_by FROM knowledge_items")->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
[$rc, $o] = $tool('--refresh-seeded --only=MANY_USERS_HOTSPOT --dry-run');
is_($rc === 0 && strpos($o, 'would correct: MANY_USERS_HOTSPOT') !== false, 'the dry run names the one row it would correct', $o);
none('and no other', $o, 'would correct: BUSINESS_PLANS');
has('it says nothing was written', $o, 'DRY RUN — nothing was written');
is_($rows() === $before, 'and nothing was written');
[$rc, $o] = $tool('--refresh-seeded --only=MANY_USERS_HOTSPOT');
$after = $rows();
is_($rc === 0 && strpos($o, 'corrected: MANY_USERS_HOTSPOT') !== false, 'the real run corrects it', $o);
is_(($after['MANY_USERS_HOTSPOT']['answer'] ?? '') === (string)$seedBy['MANY_USERS_HOTSPOT']['answer'], 'to the seed\'s text, word for word');
is_(($after['BUSINESS_PLANS']['answer'] ?? '') === 'DRIFTED, still as seeded', 'a row it was not told about is left alone, drifted or not');
is_(($after['PUBLIC_IP']['answer'] ?? '') === 'Written by a person', 'and a row a person wrote is never touched');
[$rc, $o] = $tool('--refresh-seeded --only=MANY_USERS_HOTSPOT --dry-run');
is_($rc === 0 && strpos($o, '0 would correct') !== false, 'run again, it has nothing to do', $o);
$seedLive('admin');
$before = $rows();
[$rc, $o] = $tool('--refresh-seeded --only=MANY_USERS_HOTSPOT');
is_(strpos($o, 'MANY_USERS_HOTSPOT') !== false && strpos($o, 'Edited by hand') !== false && $rows() === $before,
    'the row edited by a person is reported and left exactly as it is', $o);
[$rc, $o] = $tool('--refresh-seeded --only=NO_SUCH_ROW');
is_($rc === 2 && $rows() === $before, 'a row the seed does not have is refused, and nothing is written', "rc {$rc}: {$o}");

// ════════════════════════════════════════════════════════════════════════════
echo "\n8. South Sudan: every prompt byte-identical to 5.18.43\n";
$golden = json_decode((string)file_get_contents(__DIR__ . '/fixtures/ai_prompt_golden_south_sudan.json'), true);
$G = (array)($golden['fingerprints'] ?? []);
is_(count($G) === 50 && ($golden['plugin'] ?? '') === '5.18.43', 'the golden holds 50 fingerprints from 5.18.43');
$SS_CAT = [
    'products' => [['name' => 'Residential 100', 'price' => 65, 'period_months' => 1],
                   ['name' => 'Business 50GB', 'price' => 120, 'period_months' => 1],
                   ['name' => 'Priority 1TB', 'price' => 250, 'period_months' => 1]],
    'hardware' => [['name' => 'Starlink Standard Kit', 'price' => 600], ['name' => 'Installation', 'price' => 50],
                   ['name' => 'MikroTik hAP ac2', 'price' => 95], ['name' => 'Ruijie Reyee RG-RAP6262(G)', 'price' => 180],
                   ['name' => 'Cat6 outdoor cable 305m', 'price' => 120], ['name' => 'RJ45 connectors (100)', 'price' => 10],
                   ['name' => 'ICT Consultancy Charges', 'price' => 30]],
    'accessories' => [['name' => 'Router Mini', 'price' => 90]],
];
$SS_MSGS = ['I want internet for my business', 'we need a VPN into the office', 'how do I cover my other building?',
            'Is it unlimited?', 'I want to sell wifi to my neighbours'];
$SS_CFGS = [['ai_provider' => 'openai', 'openai_api_key' => 'x'],
            ['ai_provider' => 'openai', 'openai_api_key' => 'x', 'ai_sales_on_all_numbers' => '1']];
$same = 0; $diff = [];
foreach ($SS_CFGS as $ci => $cfg) {
    $b = new DishNetAiBrain($cfg);
    foreach ($SS_MSGS as $mi => $m) {
        $got = [];
        foreach (['sales', 'support', 'account'] as $ch) {
            $got["c{$ci}-m{$mi}-{$ch}-legacy"] = sha1($b->promptPreview(['channel' => $ch, 'message' => $m, 'history' => [], 'customer' => null, 'products' => $SS_CAT]));
        }
        $got["c{$ci}-m{$mi}-sales-contract"] = sha1($b->promptPreview(BrainContext::build('unknown', ['customer' => null, 'channel' => 'sales',
            'transport' => 'whatsapp', 'medium' => '', 'products' => AiReplyWorker::salesCatalogue($SS_CAT, $cfg), 'message' => $m, 'history' => []])));
        $got["c{$ci}-m{$mi}-web-contract"] = sha1($b->promptPreview(BrainContext::build('anonymous', ['channel' => 'sales', 'transport' => 'web',
            'medium' => '', 'message' => $m, 'products' => BrainContext::catalogue($SS_CAT, $cfg), 'history' => []])));
        foreach ($got as $k => $h) { if (($G[$k] ?? null) === $h) $same++; else $diff[] = $k; }
    }
}
is_($same === 50 && $diff === [], "all 50 prompts — three numbers in the legacy shape, the sales contract, the website chat — are 5.18.43's",
    "{$same} identical; differ: " . implode(', ', array_slice($diff, 0, 8)));
// Control on the golden: the website chat passing its catalogue to BrainContext directly, as it did before
// BrainContext::catalogue() existed, now shows the accessories — and the fingerprint moves.
$raw = $SS_CAT; $raw['stock'] = '';
$h = sha1((new DishNetAiBrain($SS_CFGS[0]))->promptPreview(BrainContext::build('anonymous', ['channel' => 'sales', 'transport' => 'web',
    'medium' => '', 'message' => $SS_MSGS[0], 'products' => $raw, 'history' => []])));
is_($h !== ($G['c0-m0-web-contract'] ?? ''), 'control: the website chat without the catalogue rule would not match the golden');

// ════════════════════════════════════════════════════════════════════════════
if ($withMutants) {
    echo "\n9. Weakened copies of the code must each fail this test\n";
    /** A copy of the plugin to weaken: lib/, workers/ and web_chat.php copied, the rest linked; $rel is copied too. */
    $sandbox = function (string $rel) use ($root): string {
        $tmp = sys_get_temp_dir() . '/dn-t44-m-' . getmypid() . '-' . bin2hex(random_bytes(3));
        mkdir($tmp, 0700, true);
        exec('cp -r ' . escapeshellarg("{$root}/lib") . ' ' . escapeshellarg("{$root}/workers") . ' ' . escapeshellarg("{$root}/web_chat.php")
            . ' ' . escapeshellarg("{$root}/manifest.json") . ' ' . escapeshellarg($tmp));
        foreach (['migrations', 'tools', 'assets', 'profiles'] as $d) {
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
        ['lib/DishNetAiBrain.php', 'if (is_array($hardware) && $hardware && $this->networkDesign()) {', 'if (false) {',
         'the network equipment never split out of HARDWARE'],
        ['lib/DishNetAiBrain.php', "private function networkDesign(): bool\n    {\n        return filter_var(", "private function networkDesign(): bool\n    {\n        return true || filter_var(",
         'the network list on every install'],
        ['lib/DishNetAiBrain.php', 'if (!$this->qualifies()) return \'\';', '', 'the data-allowance fact where the install does not qualify'],
        ['lib/DishNetAiBrain.php', 'if (!$residential) return \'\';', '', 'the fact with no Residential plan listed'],
        ['lib/DishNetAiBrain.php', 'if (filter_var($config[\'ai_qualification\'] ?? false, FILTER_VALIDATE_BOOLEAN)' . "\n"
            . '            && trim((string)($config[\'knowledge_block\'] ?? \'\')) !== \'\') {', 'if (true) {', 'the guard\'s copy of the fact on every install'],
        ['lib/DishNetAiBrain.php', '. "- NETWORK EQUIPMENT is never part of an ordinary TOTAL TO GET CONNECTED: add it only when "' . "\n"
            . '            . "they want a bigger area or another building covered, or ask for an item.\n"', '', 'the home-total guard dropped from the rule'],
        ['lib/PlanCatalogue.php', 'if (!$residentialFirst) return self::ASK_RULE;', '', 'the Residential-first rule on every install'],
        ['lib/KnowledgeBase.php', '? self::ANSWER_LIMIT : self::LEGACY_ANSWER_LIMIT;', '? self::ANSWER_LIMIT : self::ANSWER_LIMIT;',
         'the 1,000-character limit on every install'],
        ['lib/BrainContext.php', 'unset($products[\'accessories\']);', '', 'the accessories on every install'],
        ['lib/BrainContext.php', 'if ($acc) $out[\'products\'][\'accessories\'] = $acc;', '', 'the context contract dropping the accessories again'],
        ['workers/AiReplyWorker.php', 'return \BrainContext::catalogue($products, $config);',
         '$products[\'stock\'] = (string)($config[\'stock_statement\'] ?? \'\'); return $products;', 'the sales number skipping the catalogue rule'],
        ['workers/AiReplyWorker.php', 'if (filter_var($config[\'ai_hardware_expert\'] ?? false, FILTER_VALIDATE_BOOLEAN)) {' . "\n"
            . '            // Added to the totals above', 'if (true) {' . "\n" . '            // Added to the totals above', 'the wider price check on every install'],
        ['workers/AiReplyWorker.php', 'for ($q = 2; $q <= 5; $q++)', 'for ($q = 2; $q <= 1; $q++)', 'no second access point beside the rest'],
        ['workers/AiReplyWorker.php', 'array_slice(array_merge($kitRows, $netRows), 0, 10)', 'array_slice(array_merge($kitRows, $netRows), 0, 6)',
         'totals limited to the first six items again'],
        ['web_chat.php', '$_catalogue = BrainContext::catalogue($products[\'ok\'] ? $products[\'data\'] : [], $config);',
         '$_catalogue = $products[\'ok\'] ? $products[\'data\'] : []; $_catalogue[\'stock\'] = (string)($config[\'stock_statement\'] ?? \'\');',
         'the website chat skipping the catalogue rule'],
        ['assets/shop/network.json', '"match": ["rj45", "connector"]', '"match": ["rj45x", "connectorx"]', 'the connectors no longer matched before the cable'],
        ['tools/knowledge_seed.json', 'design a starting setup from it and price it', 'take the details', 'the knowledge row still handing over without a price'],
        ['tools/seed_knowledge.php', '$items = array_values(array_filter($items, fn($i) => in_array((string)($i[\'item_key\'] ?? \'\'), $only, true)));', '',
         'the correction not limited to the rows it names'],
        ['tools/seed_knowledge.php', 'if ($dry) $pdo->rollBack();', 'if ($dry) $pdo->commit();', 'a dry run that writes'],
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
