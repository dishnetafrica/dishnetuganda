<?php
/**
 * test_price_check_totals.php — 5.18.47 (docs/41 §9): a total must add up to the lines listed with it, and a TOTAL
 * must carry a figure — where the hardware module is on (Uganda). South Sudan: exactly as before.
 *
 * On 27 Sep 2026, 09:00 UTC, the live assistant listed five items that add up to 4,527,000 and wrote "Total: UGX
 * 4,627,000", and in its next reply "TOTAL TO GET CONNECTED: (Add total of kit, …)". The price check sent both:
 * 4,627,000 is itself a sum of listed prices (docs/41 §8.2), and the slot was in round brackets. The operator chose
 * "Build the total check".
 *
 * Pinned here:
 *   1. the 30 live replies of 27 Sep (tests/fixtures/live_replies_2026-09-27.json), judged as the worker judges them
 *      with the price list of their run: exactly five refused — the three 5.18.46 refuses, each now naming the total
 *      too, and the two it sent at 09:00 — and the other twenty-five sent as written. With 5.18.46's options the two
 *      of 09:00 are sent: the control;
 *   2. the shapes: what adds up, what does not, and what is left alone because it cannot be read surely;
 *   3. where the option comes from and who passes it: South Sudan identical; the worker hands the 4,627,000 reply to
 *      a person; the check tool names the total;
 *   4. weakened copies of the code each fail this test.
 *
 * Against another copy of the plugin:   php test_price_check_totals.php --root=DIR [--no-mutants]
 */
declare(strict_types=1);

$opt  = getopt('', ['root:', 'no-mutants']);
$root = isset($opt['root']) ? rtrim((string)$opt['root'], '/') : dirname(__DIR__);
$withMutants = !isset($opt['no-mutants']);

foreach (['bootstrap_data', 'StoreInterface', 'JsonStore', 'SqliteStore', 'KnowledgeSeeder', 'KnowledgeBase',
          'ConversationService', 'BrainContext', 'PlanCatalogue', 'PlanFenceGuard', 'NetworkEquipment', 'ShopCatalogue',
          'PaymentOptions', 'ReplyPrivacyGuard', 'ReplyTotals', 'DishNetAiBrain', 'EventBus'] as $lib) {
    require_once "{$root}/lib/{$lib}.php";
}
require_once "{$root}/workers/WorkerBase.php";
require_once "{$root}/workers/AiReplyWorker.php";

$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; } else { $fail++; echo "  FAIL {$m}\n"; if ($d !== '') echo "       {$d}\n"; }
}

// The price list the three logs printed (docs/40 §16, docs/41 §8). The Ruijie was 1,100,000 at 06:1x; the eighteen
// accessories the logs do not print are at their docs/19 shelf prices, which the two they print still match.
$PLANS = [
    ['name' => 'Starlink Residential Lite ( up to 100 Mbps)', 'price' => 249000, 'period_months' => 1],
    ['name' => 'Residential (up to 400 Mbps)', 'price' => 329000, 'period_months' => 1],
    ['name' => 'Starlink Business 50 GB', 'price' => 175000, 'period_months' => 1],
    ['name' => 'Starlink Business 500 GB', 'price' => 285000, 'period_months' => 1],
    ['name' => 'Starlink Business 1TB', 'price' => 469000, 'period_months' => 1],
];
$HW = [
    ['name' => 'Starlink Mini Kit + Mini Router', 'price' => 2249000], ['name' => 'Starlink Standard Kit', 'price' => 2649000],
    ['name' => 'Professional Installation', 'price' => 150000], ['name' => 'MikroTik L009 Series', 'price' => 700000],
    ['name' => 'Ruijie Reyee RG-RAP6262(G)', 'price' => 700000],
    ['name' => 'D-Link D.LINK CAT 6, OUTDOOR WATERPROOF CABLE', 'price' => 378000],
    ['name' => 'RJ45 Cat6 Pass-Through Connector (PACK OF 10)', 'price' => 19500], ['name' => 'ICT Consultancy Charges', 'price' => 100000],
];
$ACC = [];
foreach ([['Router Mini', 301000], ['Roof Rack Mount | Standard 4 or 4 X', 451000], ['Standard 4 or 4 X to Standard Actuated Mount Adapter', 226000],
          ['X-Frame Base | Standard 4 or 4 X, Enterprise', 377000], ['Ridgeline Mount | Standard 4 or 4 X', 1881000], ['Router 3 Mount', 226000],
          ['Mobility Mount | Standard 4 or 4 X', 226000], ['Power Supply Mount | Standard 4 X', 151000],
          ['Pivot Mount | Standard 4 and 4 X, Enterprise', 377000], ['Wall Mount | Standard 4 or 4 X', 377000], ['Pipe Adapter | Standard 4 or 4 X', 226000],
          ['Router 3 | Starlink V4 or V5, Mini', 827000], ['Travel Kit | Mini', 301000], ['Car Adapter | Mini', 301000],
          ['Starlink Mini USB-C Cable 5m', 151000], ['Mobility Mount | Mini', 226000], ['Roof Rack Mount | Mini', 226000],
          ['Starlink Mini Ethernet Cable 15m', 151000], ['Pivot Mount | Mini', 377000], ['Wall Mount | Mini', 301000]] as [$n, $p]) {
    $ACC[] = ['name' => $n, 'price' => $p];
}
$UG = ['ai_provider' => 'openai', 'openai_api_key' => 'x', 'ai_currency' => 'UGX', 'ai_qualification' => '1', 'ai_hardware_expert' => '1',
       'ai_sales_on_all_numbers' => '1', 'ai_lead_capture' => '1', 'pay_airtel_merchant' => '4428146'];
$SS = ['ai_provider' => 'openai', 'openai_api_key' => 'x'];

$FIX = json_decode((string)file_get_contents("{$root}/tests/fixtures/live_replies_2026-09-27.json"), true);

function catalogueOf(string $run): array {
    global $PLANS, $HW, $ACC, $FIX;
    $hw = $HW;
    foreach ($hw as &$h) if (strpos($h['name'], 'Ruijie') === 0) $h['price'] = (int)$FIX['price_lists']['ruijie'][$run];
    unset($h);
    return ['products' => $PLANS, 'hardware' => $hw, 'accessories' => $ACC];
}
function ctxOf(array $cfg, array $cat, string $msg, array $history = []): array {
    return BrainContext::build('unknown', ['customer' => null, 'channel' => 'sales', 'transport' => 'whatsapp', 'medium' => '',
        'products' => AiReplyWorker::salesCatalogue($cat, $cfg), 'message' => $msg, 'history' => $history]);
}
/** The guard as the worker calls it: this install's price list and prompt, and $opts. */
function judgeLive(array $r, array $history, array $cfg, array $opts): array {
    $ctx = ctxOf($cfg, catalogueOf($r['run']), $r['customer'], $history);
    $prompt = (new DishNetAiBrain($cfg))->promptPreview($ctx);
    return ReplyPrivacyGuard::check($r['reply'], ['values' => AiReplyWorker::permittedAmounts($ctx, $prompt, $cfg), 'prompt' => $prompt,
                                                  'public' => DishNetAiBrain::operatorText($cfg)] + $opts);
}
$ON   = ReplyPrivacyGuard::optionsFor($UG);
$ON46 = array_intersect_key($ON, ['plain_amounts' => 1, 'placeholders' => 1]);   // the check as 5.18.46 made it
$OFF  = ReplyPrivacyGuard::optionsFor($SS);

// ════════════════════════════════════════════════════════════════════════════
echo "\n1. The thirty live replies of 27 Sep: five refused, twenty-five sent as written\n";
// The five that must not reach a customer, and why (the rest: sent, word for word).
$REFUSE = [
    '5.18.45 A1 2' => ['placeholder', 'total:missing'],          // "TOTAL FOR SETUP: [Sum of setup costs]"
    '5.18.45 B1 1' => ['foreign:amount', 'total:mismatch'],       // "1993500 UGX" for 1,897,500
    '5.18.45 B2 1' => ['foreign:amount', 'total:mismatch'],       // "1999500 UGX" for 1,897,500
    '5.18.46 A1 1' => ['total:mismatch'],                         // "Total: UGX 4,627,000" for 4,527,000
    '5.18.46 A1 2' => ['total:missing'],                          // "TOTAL TO GET CONNECTED: (Add total of …)"
];
$live = []; $prev = [];
foreach ((array)$FIX['replies'] as $r) {
    $key = "{$r['run']} {$r['question']} {$r['turn']}";
    $history = [];
    if ((int)$r['turn'] > 1 && isset($prev["{$r['run']} {$r['question']}"])) {
        $p = $prev["{$r['run']} {$r['question']}"];
        $history = [['role' => 'customer', 'text' => $p['customer']], ['role' => 'dishnet', 'text' => $p['sent']]];
    }
    $sent = $r['draft_not_printed'] ? ReplyPrivacyGuard::SAFE_FALLBACK : $r['reply'];
    $prev["{$r['run']} {$r['question']}"] = ['customer' => $r['customer'], 'sent' => $sent];
    if ($r['draft_not_printed']) continue;
    $live[$key] = [$r, $history];
}
is_(count($live) === 30, 'the fixture holds the thirty replies whose text the logs printed', (string)count($live));
$refused = 0; $sentAsWritten = 0; $n46 = 0;
foreach ($live as $key => [$r, $history]) {
    $v = judgeLive($r, $history, $UG, $ON);
    if (isset($REFUSE[$key])) {
        $refused++;
        is_(empty($v['safe']) && $v['categories'] === $REFUSE[$key] && $v['reply'] === ReplyPrivacyGuard::SAFE_FALLBACK,
            "{$key}: refused — " . implode(', ', $REFUSE[$key]), json_encode($v['categories']));
    } else {
        if (!empty($v['safe']) && $v['reply'] === $r['reply']) $sentAsWritten++;
        is_(!empty($v['safe']) && $v['reply'] === $r['reply'], "{$key}: sent as the model wrote it", json_encode($v['categories']));
    }
    if (empty(judgeLive($r, $history, $UG, $ON46)['safe'])) $n46++;
}
is_($refused === 5 && $sentAsWritten === 25, 'exactly five refused, and the other twenty-five sent word for word', "{$refused} / {$sentAsWritten}");
is_($n46 === 3, 'control: the check as 5.18.46 made it refuses three — the two of 09:00 went to the customer', (string)$n46);
foreach (['5.18.46 A1 1', '5.18.46 A1 2'] as $key) {
    is_(!empty(judgeLive($live[$key][0], $live[$key][1], $UG, $ON46)['safe']), "control: {$key} passes the 5.18.46 check, as it did live");
}
$f = ReplyTotals::findings($live['5.18.46 A1 1'][0]['reply']);
is_(count($f) === 1 && $f[0]['kind'] === 'mismatch' && $f[0]['stated'] === 4627000.0 && $f[0]['sum'] === 4527000.0,
    'the 4,627,000 reply: the total says 4,627,000, the five lines above it add up to 4,527,000', json_encode($f));
$f = ReplyTotals::findings($live['5.18.46 A1 2'][0]['reply']);
is_(count($f) === 1 && $f[0]['kind'] === 'missing' && strpos($f[0]['line'], 'TOTAL TO GET CONNECTED') === 0,
    'the "(Add total of …)" reply: a TOTAL with no figure', json_encode($f));
$f = ReplyTotals::findings($live['5.18.44 B2 1'][0]['reply']);
is_($f === [], '5.18.44 B2: "Total = 700,000 + … = 2,297,500", then "Total Setup Cost: 2,297,500" restated — both add up', json_encode($f));

// ════════════════════════════════════════════════════════════════════════════
echo "\n2. The shapes\n";
$kinds = fn(string $t): array => array_map(fn($x) => $x['kind'], ReplyTotals::findings($t));
$LIST = "- Starlink Mini Kit — 2,249,000 UGX\n- Professional Installation — 150,000 UGX\n\n";
$PLAN = "- Starlink Mini Kit — 2,249,000\n- Professional Installation — 150,000\n- Residential Lite — 249,000 per month\n\n";
$ADDS = [
    'a total under its lines'                         => $LIST . 'TOTAL: 2,399,000 UGX',
    'written without commas'                          => "- MikroTik — price 700000 UGX\n- Ruijie — price 700000 UGX\nTOTAL: 1400000 UGX",
    'the monthly plan listed, the total one-time'     => $PLAN . 'TOTAL TO GET CONNECTED: 2,399,000',
    'the monthly plan listed, and in the total'       => $PLAN . 'TOTAL with the first month: 2,648,000',
    'a worked sum in the total line'                  => "- MikroTik — 700,000\n- 2 × Ruijie — 2 × 700,000 = 1,400,000\n\nTOTAL: 700,000 + 1,400,000 = 2,100,000",
    'a label, then the figure on its own line'        => $LIST . "Your total for this setup would be:\n\n- **Total**: UGX 2,399,000",
    'a total said in words: "comes to"'               => $LIST . 'The total comes to 2,399,000 UGX.',
    'the list repeated before its total'              => "1. Kit — 2,249,000\n2. Installation — 150,000\n\nHere is the total:\n\n- Kit: 2,249,000\n- Installation: 150,000\n\n**TOTAL: 2,399,000**",
    'a summary restating the total over a part list'  => $LIST . "TOTAL: 2,399,000\n\nSo:\n- Kit: 2,249,000\n- Monthly: 249,000 per month\n- **Total one-time**: 2,399,000",
    'a subtotal, one more line, the total'            => $LIST . "Subtotal: 2,399,000\n- Router Mini — 301,000\nTotal: 2,700,000",
    'a quantity before the item'                      => "- 2 × Router 3 — 827,000 UGX each\n\nTOTAL: 1,654,000 UGX",
    'a quantity after the price'                      => "- Router 3 — 827,000 × 2\n\nTOTAL: 1,654,000",
    'a line priced "each = …"'                        => "- MikroTik — 700,000\n- 2 × Ruijie — 1,100,000 UGX each = 2,200,000 UGX\n\nTOTAL: 2,900,000 UGX",
    'an item with no price in the list'               => "- Kit — 2,249,000\n- Outdoor cable (length to be confirmed)\n- Installation — 150,000\n\nTOTAL: 2,399,000",
    '"Standard 4 or 4 X" is a name, not "× 4"'        => "- Wall Mount | Standard 4 or 4 X 377,000\n- Professional Installation — 150,000\n\nTOTAL: 527,000",
    'a second list with its own total'                => "Here is the kit:\n- Starlink Mini Kit — 2,249,000\nFor the upper floor you would add:\n- Router Mini — 301,000\nTOTAL for the router: 301,000",
    // A count not beside its price: the price may be for one or for all, so the line is read both ways.
    'a count before the item, the line\'s own total'   => "- 2 × Router 3 — 1,654,000 UGX\n\nTOTAL: 1,654,000 UGX",
    'a count after the name: "Router Mini x2"'        => "- Router Mini x2 — UGX 435,000\n\nTOTAL: UGX 870,000",
    // The check tool's floors reply (scripts/harness/ai-check/fake_ai.php), which is right: the count sits mid-line.
    // Found by the check tool's rehearsal, 27 Sep, before anything shipped: the first build read it as 435,000 = 870,000.
    'a count mid-line: "floors: 2 × Router Mini — 435,000 each = 870,000"' =>
        "For the two upper floors: 2 × Router Mini — UGX 435,000 each = UGX 870,000\nTOTAL: UGX 870,000\nThe site survey confirms how many routers are needed and where they go.",
    'a count mid-line in a list: for one, or for all?' => "- For the upper floors: 2 × Router Mini — UGX 435,000\nTOTAL: UGX 870,000",
];
foreach ($ADDS as $label => $t) is_($kinds($t) === [], "adds up: {$label}", json_encode(ReplyTotals::findings($t)));
$WRONG = [
    'a total 100,000 over its lines'                  => [$LIST . 'TOTAL: 2,499,000 UGX', ['mismatch']],
    'written without commas'                          => ["- MikroTik — price 700000 UGX\n- Ruijie — price 700000 UGX\nTOTAL: 1500000 UGX", ['mismatch']],
    'in words'                                        => [$LIST . 'The total comes to 2,499,000 UGX.', ['mismatch']],
    'neither with nor without the monthly plan'       => [$PLAN . 'TOTAL TO GET CONNECTED: 2,500,000', ['mismatch']],
    'a worked sum that is wrong'                      => ["You'll need two routers:\n2 x 827000 = 1554000 UGX", ['equation']],
    'a total whose own sum is wrong'                  => ["- MikroTik — 700,000\n- Ruijie — 1,400,000\n\nTOTAL: 700,000 + 1,400,000 = 2,000,000", ['equation', 'mismatch']],
    'a slot in round brackets'                        => [$LIST . 'TOTAL TO GET CONNECTED: (add the kit and the installation)', ['missing']],
    'a slot in square brackets'                       => [$LIST . 'TOTAL FOR SETUP: [Sum of setup costs]', ['missing']],
    'a label and then nothing'                        => [$LIST . "TOTAL TO GET CONNECTED:\n\nLet me know if you want to proceed.", ['missing']],
    'a count before the item, "each = …" worked wrong' => ["- 2 × Ruijie — 700,000 UGX each = 1,500,000 UGX", ['equation']],
    'a price "each" for two, the total for one'       => ["- 2 × Router 3 — 827,000 UGX each\n\nTOTAL: 827,000 UGX", ['mismatch']],
    'a count before the item, neither reading'        => ["- Kit — 2,649,000\n- 2 × Router 3 — 827,000\n\nTOTAL: 4,000,000", ['mismatch']],
    'a count mid-line, a sum wrong either way'        => ["For the two upper floors: 2 × Router Mini — UGX 435,000 each = UGX 900,000", ['equation']],
];
foreach ($WRONG as $label => [$t, $want]) is_($kinds($t) === $want, "refused: {$label} — " . implode(', ', $want), json_encode(ReplyTotals::findings($t)));
$ALONE = [
    '"Total users: 50" — not money'                   => $LIST . 'Total users: 50',
    '"Total coverage: …" — not money'                 => 'Total coverage: the whole compound, confirmed by the survey.',
    'a sentence that says "total …:"'                 => $LIST . 'The total will depend on the cable length: the survey confirms it.',
    'a promise of a total below'                      => $LIST . 'The total for this setup will be provided below, and then the monthly plan:',
    'a price "each", its quantity nowhere'            => "- Router 3 — 827,000 UGX each\n\nTOTAL: 1,654,000",
    'two prices on one line'                          => "- Standard Kit — 2,649,000 (or 2,249,000 for the Mini)\n- Installation — 150,000\n\nTOTAL: 2,799,000",
    'a total with no list above it'                   => 'TOTAL: 2,399,000 UGX',
    'a total inside a sentence'                       => $LIST . 'That brings everything to 2,499,000 UGX in total.',
    'an "=" that is not money: "1 TB = 1,000 GB"'     => 'Your block: 1 TB = 1,000 GB of priority data.',
    'two counts on one line: which is it?'            => "- Router Mini (x2), for 3 x floors — UGX 435,000\n\nTOTAL: UGX 1,305,000",
    'seven lines read two ways: too many readings'    => implode("\n", array_map(fn($n) => "- 2 × Item {$n} — 100,000", range(1, 7))) . "\n\nTOTAL: 50,000",
];
foreach ($ALONE as $label => $t) is_($kinds($t) === [], "left alone: {$label}", json_encode(ReplyTotals::findings($t)));
is_(array_filter(ReplyPrivacyGuard::check($LIST . 'TOTAL: 2,499,000 UGX', [])['categories'], fn($c) => strpos($c, 'total:') === 0) === [],
    'control: without the option no total is added up');

// ════════════════════════════════════════════════════════════════════════════
echo "\n3. Where the option comes from, and who passes it\n";
is_($ON['totals'] === true, 'the hardware module on (Uganda): the totals rule on');
foreach ([['South Sudan', $SS], ['the module off', ['ai_hardware_expert' => '0'] + $UG], ['no configuration', []]] as [$l, $c]) {
    is_(ReplyPrivacyGuard::optionsFor($c)['totals'] === false, "{$l}: off");
}
$same = true;
foreach ($live as $key => [$r, $history]) {
    if (judgeLive($r, $history, $SS, $OFF) !== judgeLive($r, $history, $SS, [])) { $same = false; echo "       differs: {$key}\n"; }
}
is_($same, 'South Sudan: all thirty verdicts identical to the check without options');

// The worker's own guard, called: Uganda refuses the 4,627,000 reply and hands it to a person; the module off sends it.
[$A1, $A1h] = $live['5.18.46 A1 1'];
$worker = (new ReflectionClass(AiReplyWorker::class))->newInstanceWithoutConstructor();
$events = new class { public $rows = []; public function append(string $f, array $r): array { $this->rows[] = [$f, $r]; return $r; } };
foreach (['config' => $UG, 'store' => $events] as $prop => $val) {
    $p = new ReflectionProperty(WorkerBase::class, $prop); $p->setAccessible(true); $p->setValue($worker, $val);
}
$guard = new ReflectionMethod(AiReplyWorker::class, 'guardReply'); $guard->setAccessible(true);
$ctxA1 = ctxOf($UG, catalogueOf('5.18.46'), $A1['customer']) + ['conversation_id' => 7];
ob_start();
$out = $guard->invoke($worker, ['reply' => $A1['reply'], 'escalate' => false], $ctxA1, (new DishNetAiBrain($UG))->promptPreview($ctxA1));
$log = (string)ob_get_clean();
is_(($out['reply'] ?? '') === ReplyPrivacyGuard::SAFE_FALLBACK && !empty($out['escalate'])
    && strpos((string)($out['escalate_reason'] ?? ''), 'total:mismatch') !== false,
    'the worker (Uganda): the 4,627,000 reply becomes the fallback and is handed over, "total:mismatch"', json_encode($out));
$stored = json_encode($events->rows);
is_(count($events->rows) === 1 && $events->rows[0][0] === 'ai_security_events.json'
    && strpos($stored, '4,627,000') === false && strpos($stored, '4627000') === false,
    'one security event is stored, and it holds no text of the reply', $stored);
is_(strpos($log, 'reply BLOCKED by guard — total:mismatch') !== false, 'and the worker log says why', trim($log));
$p = new ReflectionProperty(WorkerBase::class, 'config'); $p->setAccessible(true); $p->setValue($worker, ['ai_hardware_expert' => '0'] + $UG);
ob_start();
$out = $guard->invoke($worker, ['reply' => $A1['reply'], 'escalate' => false], $ctxA1, (new DishNetAiBrain($UG))->promptPreview($ctxA1));
ob_end_clean();
is_(($out['reply'] ?? '') === $A1['reply'], 'control — the worker with the module off sends it as the model wrote it');

$chk = (string)@file_get_contents(dirname($root) . '/scripts/lib/ai_check.php');
if ($chk !== '') {
    is_(strpos($chk, "'ReplyPrivacyGuard', 'ReplyTotals',") !== false && strpos($chk, "if (!empty(\$po['totals']))") !== false
        && strpos($chk, "its total says ") !== false && strpos($chk, "it gives a TOTAL with no figure") !== false,
        'the AI check tool loads the rule where the plugin has it, says it is on, and names a total it refused');
}

// ════════════════════════════════════════════════════════════════════════════
if ($withMutants) {
    echo "\n4. Weakened copies of the code must each fail this test\n";
    /** A copy of the plugin to weaken: lib/ and workers/ copied, the rest linked. */
    $sandbox = function () use ($root): string {
        $tmp = sys_get_temp_dir() . '/dn-t47-m-' . getmypid() . '-' . bin2hex(random_bytes(3));
        mkdir($tmp, 0700, true);
        exec('cp -r ' . escapeshellarg("{$root}/lib") . ' ' . escapeshellarg("{$root}/workers") . ' ' . escapeshellarg("{$root}/manifest.json")
            . ' ' . escapeshellarg($tmp));
        foreach (['migrations', 'tools', 'assets', 'profiles', 'tests'] as $d) {
            if (is_dir("{$root}/{$d}")) symlink("{$root}/{$d}", "{$tmp}/{$d}");
        }
        return $tmp;
    };
    $MUTANTS = [
        ['lib/ReplyPrivacyGuard.php', "if (!empty(\$permitted['totals'])) {", 'if (false) {', 'the rule never asked'],
        ['lib/ReplyPrivacyGuard.php', "'totals' => \$on];", "'totals' => false];", 'the option never on'],
        ['lib/ReplyPrivacyGuard.php', "'totals' => \$on];", "'totals' => true];", 'the option on for every install, South Sudan included'],
        ['lib/ReplyTotals.php', '$cands[] = $all; $cands[] = $once;', '$cands[] = $all;', 'the monthly plan always added in'],
        ['lib/ReplyTotals.php', 'break;                                        // prose: the list ends here', 'continue;', 'a sentence not ending the list'],
        ['lib/ReplyTotals.php', "foreach (\$spans as [\$s, \$o]) if (\$o > \$eq) return ['values' => [self::num(\$s)], 'monthly' => \$monthly];",
         "return ['values' => [self::num(\$spans[0][0])], 'monthly' => \$monthly];", 'a line read by its first amount, not what follows "="'],
        ['lib/ReplyTotals.php', "return ['missing' => true, 'value' => null]; // \"TOTAL: (Add total of …)\"",
         "return null; // \"TOTAL: (Add total of …)\"", 'a TOTAL with no figure let through'],
        ['lib/ReplyTotals.php', 'if ($restated) continue;', 'if (false) continue;', 'a restated total checked against a part list'],
        ['lib/ReplyTotals.php', "\\s*[×x]\\s*(?:UGX|USh|Shs)?\\s*\$/u', \$before", "\\s*[×xX]\\s*(?:UGX|USh|Shs)?\\s*\$/u', \$before", 'the X of "Standard 4 X" read as a quantity'],
        ['lib/ReplyTotals.php', 'if ($unit) return null;', '', 'a price "each" read as the line\'s total'],
        ['lib/ReplyTotals.php', 'if ($eq !== null && !self::near($eq[\'sums\'], $eq[\'stated\'])) {', 'if (false) {', 'a wrong written sum not checked'],
        ['lib/ReplyTotals.php', '$money = $words === \'\' || preg_match(self::MONEY_WORDS, $words) === 1;', '$money = true;', 'every "Total …:" read as money'],
        ['lib/ReplyTotals.php', 'return $unit ? [$k * $a, $a] : [$a, $k * $a];', 'return [$a];', 'a count mid-line read one way only, as the price for one'],
        ['lib/ReplyTotals.php', 'return $unit ? [$n * $a] : [$n * $a, $a];', 'return [$n * $a];', 'a count before the item read one way only, though no "each"'],
        ['lib/ReplyTotals.php', 'return $unit ? [$n * $a] : [$n * $a, $a];', 'return [$n * $a, $a];', '"each" ignored: a count before the item read both ways'],
        ['lib/ReplyTotals.php', 'if ($c > 1) return null;', '', 'two counts on one line read as one'],
        ['lib/ReplyTotals.php', '|(?<![A-Za-z\d])[×xX]\s*(\d{1,2})(?![\d.,])', '', 'a count after the name not seen'],
        ['lib/ReplyTotals.php', 'if (count($sums) > 64) return null;       // too many readings', '//', 'no limit on the readings of a list'],
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
        exec('rm -rf ' . escapeshellarg($tmp));
    }
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
