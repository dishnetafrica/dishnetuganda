<?php
/**
 * test_price_check_plain.php — 5.18.46 (docs/41): the price check reads amounts written without separators, and a reply
 * with an unfilled template slot is refused — where the hardware module is on (Uganda). South Sudan: exactly as before.
 *
 * The deploy of 5.18.45 on 27 Sep 2026 asked the live assistant eleven questions (docs/40 §16). Two setup totals were
 * wrong and reached the draft a customer would get — B1 "TOTAL for the setup: 1993500 UGX" and B2 "TOTAL: 1999500 UGX",
 * where the five lines add up to 1,897,500 — and A1 ended "TOTAL FOR SETUP: [Sum of setup costs]". The check refused
 * none of them: it read only amounts written with separators ("1,999,500"), and the prompt prints every price without
 * them ("— price 700000"), which the model often copies.
 *
 * Pinned here, with the replies exactly as the model wrote them and the live price list of that morning:
 *   1. with Uganda's options the two wrong totals and the placeholder are refused — and with the options off (the
 *      5.18.45 check, and South Sudan's) all three pass, which is the hole;
 *   2. what the model got right still passes: the correct totals written the same way, a doubled access point, the
 *      router prices, the formatted replies;
 *   3. the shapes: a currency before the digits, never the digits inside a kit serial, an invoice, a link or a date;
 *      a year is not money; a markdown link is not a placeholder;
 *   4. the options come from the hardware module alone, and both callers and the AI check tool pass them — the worker's
 *      guard proved by calling it;
 *   5. weakened copies of the code each fail this test.
 *
 * Against another copy of the plugin:   php test_price_check_plain.php --root=DIR [--no-mutants]
 */
declare(strict_types=1);

$opt  = getopt('', ['root:', 'no-mutants']);
$root = isset($opt['root']) ? rtrim((string)$opt['root'], '/') : dirname(__DIR__);
$withMutants = !isset($opt['no-mutants']);

foreach (['bootstrap_data', 'StoreInterface', 'JsonStore', 'SqliteStore', 'KnowledgeSeeder', 'KnowledgeBase',
          'ConversationService', 'BrainContext', 'PlanCatalogue', 'PlanFenceGuard', 'NetworkEquipment', 'ShopCatalogue',
          'PaymentOptions', 'ReplyPrivacyGuard', 'DishNetAiBrain', 'EventBus'] as $lib) {
    require_once "{$root}/lib/{$lib}.php";
}
require_once "{$root}/workers/WorkerBase.php";
require_once "{$root}/workers/AiReplyWorker.php";

$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; } else { $fail++; echo "  FAIL {$m}\n"; if ($d !== '') echo "       {$d}\n"; }
}

// The price list as uCRM returned it on 27 Sep 2026 — deploy-5.18.45's stage AI printed it (docs/40 §16).
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
    ['name' => 'MikroTik L009 Series', 'price' => 700000],
    ['name' => 'Ruijie Reyee RG-RAP6262(G)', 'price' => 700000],
    ['name' => 'D-Link D.LINK CAT 6, OUTDOOR WATERPROOF CABLE', 'price' => 378000],
    ['name' => 'RJ45 Cat6 Pass-Through Connector (PACK OF 10)', 'price' => 19500],
    ['name' => 'ICT Consultancy Charges', 'price' => 100000],
];
$ACC = [
    ['name' => 'Router Mini', 'price' => 301000],
    ['name' => 'Router 3 | Starlink V4 or V5, Mini', 'price' => 827000],
];
$LIVE = ['products' => $PLANS, 'hardware' => $HW, 'accessories' => $ACC];
$UG = ['ai_provider' => 'openai', 'openai_api_key' => 'x', 'ai_currency' => 'UGX', 'ai_qualification' => '1', 'ai_hardware_expert' => '1',
       'ai_sales_on_all_numbers' => '1', 'ai_lead_capture' => '1', 'pay_airtel_merchant' => '4428146'];
$SS = ['ai_provider' => 'openai', 'openai_api_key' => 'x'];

function salesCtx(array $cfg, array $cat, string $msg): array {
    return BrainContext::build('unknown', ['customer' => null, 'channel' => 'sales', 'transport' => 'whatsapp', 'medium' => '',
        'products' => AiReplyWorker::salesCatalogue($cat, $cfg), 'message' => $msg, 'history' => []]);
}
/** The guard as the worker calls it for this reply: this install's price list and prompt, and $opts. */
function judge(string $reply, string $msg, array $cfg, array $opts): array {
    global $LIVE;
    $ctx = salesCtx($cfg, $LIVE, $msg);
    $prompt = (new DishNetAiBrain($cfg))->promptPreview($ctx);
    return ReplyPrivacyGuard::check($reply, ['values' => AiReplyWorker::permittedAmounts($ctx, $prompt, $cfg), 'prompt' => $prompt,
                                             'public' => DishNetAiBrain::operatorText($cfg)] + $opts);
}

// ── The replies, exactly as the model wrote them on 27 Sep 2026 (deploy-5.18.45.sh, stage AI) ─────────────────────
$Q_B1 = 'I have a wifi hotspot business and I want to cover other areas around my place, about 200 metres. What equipment do I need and how much?';
$B1 = "To cover a larger area like that, you'll need a setup that includes a router and outdoor access points, as one kit alone won't be sufficient. Here's a suggested starting setup:\n\n"
    . "- MikroTik L009 Series — price 700000 UGX (this will run your local network)\n"
    . "- Ruijie Reyee RG-RAP6262(G) — price 700000 UGX (one outdoor Wi-Fi access point to carry the Wi-Fi)\n"
    . "- D-Link D.LINK CAT 6, OUTDOOR WATERPROOF CABLE — price 378000 UGX (to connect the router to the access point)\n"
    . "- RJ45 Cat6 Pass-Through Connector (PACK OF 10) — price 19500 UGX (for the cable connections)\n"
    . "- ICT Consultancy Charges — price 100000 UGX (for designing and setting up the network)\n\n"
    . "**TOTAL for the setup:** 1993500 UGX\n\n"
    . "You will need to confirm the number of access points during a site survey, as this setup is just a starting point. It's also important to mention that for your hotspot business, the higher-capacity Residential plan would suit you best since it comes with unlimited data. \n\n"
    . "Would you like to proceed with arranging a site survey?";
$Q_B1b = 'OK. What would two outdoor access points and the MikroTik cost together?';
$B1b = "Here's the breakdown for two outdoor access points along with the MikroTik:\n\n"
     . "- MikroTik L009 Series — price 700000 UGX\n"
     . "- 2 × Ruijie Reyee RG-RAP6262(G) — 2 × 700000 UGX = 1400000 UGX\n\n"
     . "TOTAL: 700000 UGX + 1400000 UGX = 2100000 UGX\n\n"
     . "This setup will enable you to cover your desired area effectively. Would you like to proceed with this setup?";
$Q_B2 = 'I already have Starlink at home. How do I get the WiFi to my other building across the compound?';
$B2 = "To get Wi-Fi to another building across your compound, you'll need a starting setup that includes:\n\n"
    . "- MikroTik L009 Series — price 700000 UGX (router to run the local network)\n"
    . "- Ruijie Reyee RG-RAP6262(G) — price 700000 UGX (outdoor access point to carry Wi-Fi to the other building)\n"
    . "- D-Link D.LINK CAT 6, OUTDOOR WATERPROOF CABLE — price 378000 UGX (cable to connect the router and access point)\n"
    . "- RJ45 Cat6 Pass-Through Connector (PACK OF 10) — price 19500 UGX (connectors for the network cable)\n"
    . "- ICT Consultancy Charges — price 100000 UGX (to design and set up the network)\n\n"
    . "TOTAL: 1999500 UGX\n\n"
    . "You’ll still need to confirm the number of access points, cable length, and installation through a site survey. Would you like to proceed with that?";
$Q_A1 = 'About 50 people at a time. I need unlimited internet, which package do I take?';
$A1 = "For around 50 people at a time, the higher-capacity **Residential** plan is the ideal choice since it offers unlimited data. You will also need to set up a sufficient network to handle that many users.\n\n"
    . "Here's a starting setup for your Wi-Fi business:\n\n"
    . "- **Starlink Standard Kit** — 2,649,000 UGX (one-time)\n"
    . "- **Professional Installation** — 150,000 UGX (one-time)\n"
    . "- **MikroTik L009 Series** (router for managing the local network) — 700,000 UGX (one-time)\n"
    . "- **Ruijie Reyee RG-RAP6262(G)** (outdoor access point) — 700,000 UGX (one-time)\n"
    . "- **D-Link D.LINK CAT 6, OUTDOOR WATERPROOF CABLE** — 378,000 UGX (one-time)\n"
    . "- **ICT Consultancy Charges** — 100,000 UGX (one-time)\n\n"
    . "The total for this setup will be provided below, and then the monthly plan:\n\n"
    . "TOTAL FOR SETUP: [Sum of setup costs]\n"
    . "**Monthly Plan**: Residential (unlimited data) — 329,000 UGX\n\n"
    . "Let me know if you'd like to finalize anything or if you need a site survey to confirm the best setup!";
$Q_B3 = 'How much is an outdoor access point and a MikroTik router?';
$B3 = "The prices are as follows:\n\n"
    . "- Ruijie Reyee RG-RAP6262(G) outdoor access point — 700,000 UGX (one-time)\n"
    . "- MikroTik L009 Series router — 700,000 UGX (one-time)\n\n"
    . "Would you like to know more about using them or how they fit your needs?";
$Q_B4 = 'The WiFi does not reach the upper floors of my house. It has 3 floors. What do I need and how much?';
$B4 = "To extend the Wi-Fi coverage to the upper floors of your house, you will need additional Starlink routers to create a mesh network. Each router fits your existing kit and helps distribute the signal more effectively.\n\n"
    . "Here's the cost breakdown for your setup:\n\n"
    . "- Starlink Router Mini (works with Mini kit) — 301000 UGX one-time\n"
    . "- Starlink Router 3 (works with Standard kit) — 827000 UGX one-time\n\n"
    . "Please let me know which kit you currently have (Mini or Standard), and I'll provide the total cost based on your choice!";
$Q_C1 = 'How much will I pay to get Starlink installed at my home?';
$C1 = "Here's what it takes to get connected:\n\n"
    . "- Starlink Mini Kit — 2,249,000 UGX\n"
    . "- Professional Installation — 150,000 UGX\n\n"
    . "**TOTAL TO GET CONNECTED:** 2,399,000 UGX  \n"
    . "Then choose the Residential plan:  \n"
    . "- Starlink Residential (up to 400 Mbps) — 329,000 UGX per month\n\n"
    . "Your quotation confirms any regulatory charge and the tax treatment. Would you like to proceed with this?";

$ON  = ReplyPrivacyGuard::optionsFor($UG);
$OFF = ReplyPrivacyGuard::optionsFor($SS);

// ════════════════════════════════════════════════════════════════════════════
echo "\n1. The three replies that should never have been sent — refused in Uganda, passed before\n";
foreach ([['B1 "TOTAL for the setup: 1993500 UGX"', $B1, $Q_B1, ['foreign:amount']],
          ['B2 "TOTAL: 1999500 UGX"', $B2, $Q_B2, ['foreign:amount']],
          ['A1 "TOTAL FOR SETUP: [Sum of setup costs]"', $A1, $Q_A1, ['placeholder']]] as [$label, $reply, $q, $cats]) {
    $on = judge($reply, $q, $UG, $ON);
    is_(empty($on['safe']) && $on['categories'] === $cats && $on['reply'] === ReplyPrivacyGuard::SAFE_FALLBACK,
        "{$label}: refused as " . implode(',', $cats) . ' — the customer gets the fallback, staff take it', json_encode($on['categories']));
    $off = judge($reply, $q, $UG, $OFF);
    is_(!empty($off['safe']), "control — {$label}: the check as it was lets it through", json_encode($off['categories']));
}
$amounts = ReplyPrivacyGuard::amountsIn($B1, true);
is_(in_array('1993500', $amounts, true) && in_array('700000', $amounts, true), 'B1: the amounts it reads include the total and each price, as written');
is_(ReplyPrivacyGuard::amountsIn($B1, false) === [], 'control: without the option it reads no amount in B1 at all');
$fixedB1 = str_replace('1993500', '1897500', $B1);
$fixedB2 = str_replace('1999500', '1897500', $B2);
is_(!empty(judge($fixedB1, $Q_B1, $UG, $ON)['safe']) && !empty(judge($fixedB2, $Q_B2, $UG, $ON)['safe']),
    'the same replies with the right total, 1897500 written the same way, pass');

// ════════════════════════════════════════════════════════════════════════════
echo "\n2. What the model got right still passes\n";
foreach ([['B1, second turn: 2 × 700000 = 1400000, TOTAL 2100000', $B1b, $Q_B1b],
          ['B3: the two prices, formatted', $B3, $Q_B3],
          ['B4: the two Starlink routers, 301000 and 827000', $B4, $Q_B4],
          ['C1: the home total, formatted', $C1, $Q_C1]] as [$label, $reply, $q]) {
    $r = judge($reply, $q, $UG, $ON);
    is_(!empty($r['safe']), $label, json_encode($r['categories']));
}
// The payment answer as set in 5.18.31 (tests/test_airtel_money.php): the merchant ID reaches the prompt through it.
$PAYFACT = 'Pay by Airtel Money or by bank transfer. Airtel Money: dial *185*9#, enter Merchant ID 4428146, the amount '
         . 'and your Airtel Money PIN — it is free of charge for the customer.';
$payReply = 'Pay by Airtel Money: dial *185*9# and enter Merchant ID 4428146.';
$r = judge($payReply, 'How do I pay?', ['ai_fact_payment' => $PAYFACT] + $UG, $ON);
is_(!empty($r['safe']), 'the Airtel Money merchant ID from the payment answer passes: it is in the prompt', json_encode($r['categories']));
$r = judge($payReply, 'How do I pay?', $UG, $ON);
is_(empty($r['safe']) && $r['categories'] === ['foreign:amount'],
    'control: a seven-digit number the assistant was never given is refused — that is the rule, not the merchant ID', json_encode($r['categories']));
$r = judge('Thank you — your order 000117 is with our team.', 'Where is my order 000117?', $UG, $ON);
is_(!empty($r['safe']), 'a number the customer wrote themselves passes (their own words are allowed)', json_encode($r['categories']));
$r = judge('Your Mini kit and installation come to UGX2399000 in total.', $Q_C1, $UG, $ON);
is_(!empty($r['safe']), 'a right total written straight after the currency passes', json_encode($r['categories']));
$r = judge('Your Mini kit and installation come to UGX2399500 in total.', $Q_C1, $UG, $ON);
is_(empty($r['safe']) && $r['categories'] === ['foreign:amount'], 'and a wrong one written so is refused', json_encode($r['categories']));

// ════════════════════════════════════════════════════════════════════════════
echo "\n3. The shapes: money, and what is not money\n";
foreach ([['UGX 1999500', ['1999500']], ['UGX1999500', ['1999500']], ['USh 1999500', ['1999500']], ['Shs1999500', ['1999500']],
          ['SSP150000', ['150000']], ['USD99500', ['99500']], ['$150000', ['150000']], ['TOTAL: 1999500.50 UGX', ['1999500.50']],
          ['1,999,500', ['1,999,500']], ['2 × 700000 = 1400000', ['700000', '1400000']]] as [$t, $want]) {
    is_(ReplyPrivacyGuard::amountsIn($t, true) === $want, "\"{$t}\" is read as " . implode(' and ', $want), json_encode(ReplyPrivacyGuard::amountsIn($t, true)));
}
foreach (['your kit KIT304012345', 'invoice INV-2026-000114', 'https://wa.me/256705993348', 'on 2026-09-27', 'in 2026',
          'order 12/34567', 'up to 400 Mbps and 128 devices'] as $t) {
    is_(ReplyPrivacyGuard::amountsIn($t, true) === [], "\"{$t}\" holds no amount", json_encode(ReplyPrivacyGuard::amountsIn($t, true)));
}
$slot = fn(string $t): bool => in_array('placeholder', (array)ReplyPrivacyGuard::check($t, ['placeholders' => true])['categories'], true);
foreach (['TOTAL: [Sum of setup costs]', 'Dear [Customer Name],', 'TOTAL: [TOTAL]', 'That comes to [insert amount] UGX.', 'Price: [price]'] as $t) {
    is_($slot($t), "\"{$t}\" is an unfilled slot");
}
foreach (['[Pay here](https://dishnetuganda.com/pay)', '[See the price list](https://dishnetuganda.com/pricing)',
          '[Enter your number](https://dishnetuganda.com/login)', 'see [1]', 'Router 3 [Gen 3]', 'the [Mini] kit', 'a [2 × 700000] line'] as $t) {
    is_(!$slot($t), "\"{$t}\" is not");
}
is_(!in_array('placeholder', (array)ReplyPrivacyGuard::check('TOTAL: [Sum of setup costs]', [])['categories'], true),
    'control: without the option no slot is looked for');

// ════════════════════════════════════════════════════════════════════════════
echo "\n4. Where the options come from, and who passes them\n";
is_($ON === ['plain_amounts' => true, 'placeholders' => true], 'the hardware module on (Uganda): both');
foreach ([['South Sudan', $SS], ['the module off', ['ai_hardware_expert' => '0'] + $UG], ['no configuration', []]] as [$l, $c]) {
    is_(ReplyPrivacyGuard::optionsFor($c) === ['plain_amounts' => false, 'placeholders' => false], "{$l}: neither");
}
// South Sudan's check, byte for byte what it was: the same verdict on every reply above, options or none.
$same = true;
foreach ([[$B1, $Q_B1], [$B2, $Q_B2], [$A1, $Q_A1], [$B1b, $Q_B1b], [$B3, $Q_B3], [$B4, $Q_B4], [$C1, $Q_C1]] as [$reply, $q]) {
    if (judge($reply, $q, $SS, $OFF) !== judge($reply, $q, $SS, [])) $same = false;
}
is_($same, 'South Sudan: every verdict identical to the check without options');

// The worker's own guard, called: Uganda refuses B2 and hands it to staff; the module off lets it through.
$worker = (new ReflectionClass(AiReplyWorker::class))->newInstanceWithoutConstructor();
$events = new class { public $rows = []; public function append(string $f, array $r): array { $this->rows[] = [$f, $r]; return $r; } };
foreach (['config' => $UG, 'store' => $events] as $prop => $val) {
    $p = new ReflectionProperty(WorkerBase::class, $prop); $p->setAccessible(true); $p->setValue($worker, $val);
}
$guard = new ReflectionMethod(AiReplyWorker::class, 'guardReply'); $guard->setAccessible(true);
$ctxB2 = salesCtx($UG, $LIVE, $Q_B2) + ['conversation_id' => 7];
ob_start();
$out = $guard->invoke($worker, ['reply' => $B2, 'escalate' => false], $ctxB2, (new DishNetAiBrain($UG))->promptPreview($ctxB2));
$log = (string)ob_get_clean();
is_(($out['reply'] ?? '') === ReplyPrivacyGuard::SAFE_FALLBACK && !empty($out['escalate'])
    && strpos((string)($out['escalate_reason'] ?? ''), 'foreign:amount') !== false,
    'the worker (Uganda): B2 becomes the fallback and is handed over, "foreign:amount"', json_encode($out));
is_(count($events->rows) === 1 && $events->rows[0][0] === 'ai_security_events.json'
    && strpos(json_encode($events->rows[0][1]), '1999500') === false,
    'one security event is stored, and it holds no text of the reply', json_encode($events->rows));
is_(strpos($log, 'reply BLOCKED by guard — foreign:amount') !== false, 'and the worker log says why', trim($log));
$p = new ReflectionProperty(WorkerBase::class, 'config'); $p->setAccessible(true); $p->setValue($worker, ['ai_hardware_expert' => '0'] + $UG);
ob_start();
$out = $guard->invoke($worker, ['reply' => $B2, 'escalate' => false], $ctxB2, (new DishNetAiBrain($UG))->promptPreview($ctxB2));
ob_end_clean();
is_(($out['reply'] ?? '') === $B2, 'control — the worker with the module off sends B2 as the model wrote it');

$src = fn(string $rel): string => (string)file_get_contents("{$root}/{$rel}");
is_(substr_count($src('workers/AiReplyWorker.php'), '\ReplyPrivacyGuard::optionsFor((array)($this->config ?? []))') === 1,
    'the Evolution worker passes the options');
is_(substr_count($src('lib/WaAutoReplyService.php'), '\ReplyPrivacyGuard::optionsFor((array)($this->config ?? []))') === 1,
    'and so does the auto-reply service, from the same switch');
$chk = (string)@file_get_contents(dirname($root) . '/scripts/lib/ai_check.php');
if ($chk !== '') {
    is_(substr_count($chk, '+ $guardOpts') === 2 && strpos($chk, "method_exists('ReplyPrivacyGuard', 'optionsFor')") !== false,
        'the AI check tool judges with the same options, and still reads an older plugin');
}

// ════════════════════════════════════════════════════════════════════════════
if ($withMutants) {
    echo "\n5. Weakened copies of the code must each fail this test\n";
    /** A copy of the plugin to weaken: lib/ and workers/ copied, the rest linked. */
    $sandbox = function () use ($root): string {
        $tmp = sys_get_temp_dir() . '/dn-t46-m-' . getmypid() . '-' . bin2hex(random_bytes(3));
        mkdir($tmp, 0700, true);
        exec('cp -r ' . escapeshellarg("{$root}/lib") . ' ' . escapeshellarg("{$root}/workers") . ' ' . escapeshellarg("{$root}/manifest.json")
            . ' ' . escapeshellarg($tmp));
        foreach (['migrations', 'tools', 'assets', 'profiles', 'tests'] as $d) {
            if (is_dir("{$root}/{$d}")) symlink("{$root}/{$d}", "{$tmp}/{$d}");
        }
        return $tmp;
    };
    $MUTANTS = [
        ['lib/ReplyPrivacyGuard.php', 'if ($plain && preg_match_all(self::PLAIN_AMOUNT, $text, $m2) > 0)', 'if (false && preg_match_all(self::PLAIN_AMOUNT, $text, $m2) > 0)',
         'amounts without separators not read'],
        ['lib/ReplyPrivacyGuard.php', "foreach (self::amountsIn(\$text, !empty(\$permitted['plain_amounts'])) as \$amount) {",
         'foreach (self::amountsIn($text, true) as $amount) {', 'read everywhere, South Sudan included'],
        ['lib/ReplyPrivacyGuard.php', "if (!empty(\$permitted['placeholders']) && preg_match(self::PLACEHOLDER, \$text) === 1) \$cats[] = 'placeholder';",
         '', 'an unfilled slot not refused'],
        ['lib/ReplyPrivacyGuard.php', "'/(?:(?<![A-Za-z0-9.,\\/\\-])|(?<=UGX)", "'/(?:(?<![0-9.,])|(?<=UGX)", 'the digits of a serial or an invoice read as money'],
        ['lib/ReplyPrivacyGuard.php', '\d{5,}(?:\.\d{1,2})?(?!\d)/i', '\d{4,}(?:\.\d{1,2})?(?!\d)/i', 'a year read as money'],
        ['lib/ReplyPrivacyGuard.php', "\\](?!\\()/i';", "\\]/i';", 'a markdown link read as a slot'],
        ['lib/ReplyPrivacyGuard.php', "\$on = filter_var(\$config['ai_hardware_expert'] ?? false, FILTER_VALIDATE_BOOLEAN);", '$on = true;',
         'the options on for every install'],
        ['workers/AiReplyWorker.php', '] + \ReplyPrivacyGuard::optionsFor((array)($this->config ?? [])));', ']);', 'the worker not passing the options'],
    ];
    foreach ($MUTANTS as [$rel, $old, $new, $label]) {
        $tmp  = $sandbox();
        $file = "{$tmp}/{$rel}";
        $s    = (string)file_get_contents($file);
        $n    = substr_count($s, $old);
        if ($n !== 1) { is_(false, "weakened copy \"{$label}\": its anchor occurs once in {$rel}", "found {$n} times"); exec('rm -rf ' . escapeshellarg($tmp)); continue; }
        file_put_contents($file, str_replace($old, $new, $s));
        $out = [];
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
