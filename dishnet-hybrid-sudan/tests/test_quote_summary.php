<?php
/**
 * test_quote_summary.php — 5.18.46, the WhatsApp quotation summary a customer can read (docs/41).
 *
 * The operator, 27 Sep 2026, about order 000114 — Starlink Mini Kit 2,249,000, Residential Lite (up to 100 Mbps)
 * 249,000, Professional Installation 150,000: "here Monthly give wrong in message … it happen when we carete new
 * quoation", and then: "i will go with your recommandation think as customer if you get that message what you
 * understood".
 *
 * What the summary said: "💰 Hardware: UGX 2,399,000 / 💰 Monthly: UGX 249,000 / 🏷️ Total: UGX 2,648,000". The sums
 * were right, but a customer cannot tell from it whether the 249,000 is inside the 2,648,000 or on top of it, nor what
 * they pay after this — and the split came from words in each line's name: whatever lacked "kit", "router", "cable",
 * "installation"… was "Monthly". The outdoor access point, connectors and consultancy the assistant now quotes (5.18.44)
 * all lack them; a plan whose name holds "month" has "ont" in it and was "Hardware".
 *
 * In Uganda the split now comes from uCRM itself: a quotation carries a plan as its Products mirror, spelled like the
 * plan (PublicPriceFeed::planKey — the rule the price feed and the assistant already use), so a line is the monthly plan
 * exactly when it is spelled like one of uCRM's service plans, and every other line is one-time. The summary says
 * "One-time", "First month" and what the plan costs after that, from uCRM's plan. When uCRM cannot list its plans the
 * summary lists the items and the total, and makes no split it cannot stand behind.
 *
 * South Sudan: byte for byte the summary it sent before (goldens taken from the code before this change), and not one
 * request more to its uCRM.
 *
 * Everything runs through the REAL webhook.php under php -S, with a fake uCRM and a fake Evolution API that keeps
 * every message whole. QUOTE_GOLDEN_WRITE=1 (re)writes the South Sudan goldens — only ever from the code before.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0; $fail = 0;

require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';

function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

$tmp = sys_get_temp_dir() . '/dn_quotesum_' . getmypid();
exec('rm -rf ' . escapeshellarg($tmp));
@mkdir($tmp, 0777, true);
ini_set('error_log', $tmp . '/php_errors.log');
// Run alone, the webhook would write its vault beside the plugin (ConfigVault::path); tests/run.sh gives every test its
// own, and so does this, so a standalone run leaves nothing behind and reads nothing a previous run left.
if ((string)getenv('DN_VAULT_FILE') === '') putenv('DN_VAULT_FILE=' . $tmp . '/vault.json');
$procs = [];
register_shutdown_function(function () use (&$procs, $tmp) {
    foreach ($procs as $p) { if (is_resource($p)) { proc_terminate($p); proc_close($p); } }
    exec('rm -rf ' . escapeshellarg($tmp));
});

$http = function (string $url, ?string $body = null, array $headers = [], int $timeout = 10): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 3,
                            CURLOPT_HTTPHEADER => $headers, CURLOPT_PROXY => '']);
    if ($body !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
    $out = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, $out === false ? null : (string)$out];
};
$start = function (string $cmd, callable $isUp, int $base, int $step, int $span, ?array $env = null) use (&$procs): int {
    foreach (range(0, 9) as $slot) {
        $port = $base + ((getmypid() + $slot * $step) % $span);
        if ($isUp($port, true)) continue;                              // somebody else's server
        $p = proc_open(str_replace('{PORT}', (string)$port, $cmd),
                       [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);
        $ok = false;
        for ($i = 0; $i < 50 && !$ok; $i++) { usleep(100000); $ok = $isUp($port, false); }
        if ($ok) { $procs[] = $p; return $port; }
        if (is_resource($p)) { proc_terminate($p); proc_close($p); }
    }
    return 0;
};
$token = bin2hex(random_bytes(8));
$crmPort = $start(sprintf('exec php -S 127.0.0.1:{PORT} %s', escapeshellarg($root . '/tests/fixtures/fake_ucrm_kyc.php')),
    function (int $port, bool $probe) use ($http, $token): bool {
        [, $b] = $http("http://127.0.0.1:{$port}/__test/ping", null, [], 2);
        if ($b === null) return false;
        $j = json_decode($b, true);
        return $probe ? true : (($j['marker'] ?? '') === 'FAKE-UCRM-KYC' && ($j['token'] ?? '') === $token);
    }, 12100, 17, 90, ['FAKE_UCRM_KYC_TOKEN' => $token] + getenv());
$evoFixture = $root . '/tests/fixtures/fake_evo_server.php';
$evoPort = $start(sprintf('exec php -S 127.0.0.1:{PORT} %s', escapeshellarg($evoFixture)),
    function (int $port, bool $probe) use ($http, $evoFixture): bool {
        if (!$probe) @unlink(sys_get_temp_dir() . '/fake_evo_state_' . md5($evoFixture . $port) . '.json');
        [, $b] = $http("http://127.0.0.1:{$port}/__test/state", null, [], 2);
        return $b !== null && ($probe || strpos($b, 'FAKE-EVO-TEST') !== false);
    }, 12300, 19, 90, getenv());
if ($crmPort === 0 || $evoPort === 0) { bad('the fake uCRM and the fake Evolution API started'); printf("\n%d passed, %d failed\n", $pass, $fail); exit(1); }
$evoState = sys_get_temp_dir() . '/fake_evo_state_' . md5($evoFixture . $evoPort) . '.json';
@unlink($evoState);
register_shutdown_function(function () use ($crmPort, $evoState) {
    @unlink(sys_get_temp_dir() . '/fake_ucrm_kyc_' . $crmPort . '.json');
    @unlink($evoState);
});
$BASE = "http://127.0.0.1:{$crmPort}/api/v2.1";
$ctl = function (string $path, ?array $body = null) use ($http, $crmPort): array {
    [, $b] = $http("http://127.0.0.1:{$crmPort}{$path}", $body === null ? null : json_encode($body),
                   $body === null ? [] : ['Content-Type: application/json']);
    return json_decode((string)$b, true) ?? [];
};

// Each run gets its own data directory (SqliteStore keeps per-path state), and the router reads which one — and which
// webhook.php: the real one, or a weakened copy beside it (section J) — from these pointers on every request.
file_put_contents($tmp . '/webhookfile', $root . '/webhook.php');
file_put_contents($tmp . '/router.php', '<?php
declare(strict_types=1);
$root = ' . var_export($root, true) . ';
require_once $root . "/lib/timezone.php"; dn_tz_apply();
require_once $root . "/lib/StoreInterface.php";
require_once $root . "/lib/SqliteStore.php";
$dataDir = trim((string)file_get_contents(' . var_export($tmp . '/datadir', true) . '));
$store   = SqliteStore::create($dataDir);
$config  = $store->load("kyc_config.json") ?? [];
require trim((string)file_get_contents(' . var_export($tmp . '/webhookfile', true) . '));
');
$first = $tmp . '/data0';
@mkdir($first, 0777, true);
file_put_contents($tmp . '/datadir', $first);
SqliteStore::create($first);
$webPort = $start(sprintf('exec php -S 127.0.0.1:{PORT} %s', escapeshellarg($tmp . '/router.php')),
    function (int $port, bool $probe) use ($http): bool {
        [, $b] = $http("http://127.0.0.1:{$port}/webhook.php", null, [], 2);
        return $b !== null && ($probe || strpos($b, 'POST required') !== false);
    }, 12500, 23, 90, getenv());
if ($webPort === 0) { bad('the plugin started under php -S'); printf("\n%d passed, %d failed\n", $pass, $fail); exit(1); }

/**
 * One quotation, sent the way uCRM sends quote.add.
 * @return array{0:string,1:int,2:int} the WhatsApp text the customer got (whole), how many texts, and how many
 *                                      times the webhook asked uCRM for its service plans
 */
function summary(array $cfg, array $items, int $n, array $crmSet = []): array {
    global $ctl, $http, $tmp, $BASE, $evoPort, $webPort, $evoState, $crmPort;
    $dir = $tmp . '/data' . $n;
    @mkdir($dir, 0777, true);
    file_put_contents($tmp . '/datadir', $dir);
    $phone = '+256 700 000 ' . (700 + $n);
    $s = SqliteStore::create($dir);
    $s->save('kyc_config.json', [
        'crm_base_url' => $BASE, 'crm_auth_token' => 'FAKE-TEST-KEY', 'data_dir' => $dir,
        'webhook_secret' => 'testsecret',
        'evo_api_url' => "http://127.0.0.1:{$evoPort}", 'evo_api_key' => 'k', 'evo_instance_support' => 'ug-support',
        'contact_sales_phone' => '+256 705 993 348', 'contact_shop_phone' => '+256 705 993 348',
    ] + $cfg);
    $ctl('/__test/reset?scenario=uganda');
    $ctl('/__test/set', ['clients' => [['id' => 555, 'firstName' => 'TEST', 'lastName' => 'CUSTOMER', 'street1' => 'Plot 9',
        'contacts' => [['phone' => $phone, 'email' => '']]]]] + $crmSet);
    $http("http://127.0.0.1:{$crmPort}/api/v2.1/clients/555/quotes", json_encode(['items' => $items]),
        ['Content-Type: application/json', 'X-Auth-App-Key: FAKE-TEST-KEY']);
    $before = count(array_filter($ctl('/__test/log'), fn($e) => ($e['path'] ?? '') === '/api/v2.1/service-plans'));
    $http("http://127.0.0.1:{$webPort}/webhook.php", json_encode(['changeType' => 'quote.add', 'entity' => 'quote',
        'entityId' => 77, 'uuid' => 'quotesum-' . $n . '-' . getmypid(), 'extraData' => ['entity' => ['id' => 77]]]),
        ['Content-Type: application/json', 'X-Ucrm-Key: testsecret'], 90);
    $http("http://127.0.0.1:{$webPort}/webhook.php", null, [], 90);    // php -S: waits for the handler to finish
    $after = count(array_filter($ctl('/__test/log'), fn($e) => ($e['path'] ?? '') === '/api/v2.1/service-plans'));
    $st = json_decode((string)@file_get_contents($evoState), true) ?: [];
    $digits = preg_replace('/\D/', '', $phone);
    $texts = array_values(array_filter((array)($st['text_calls'] ?? []), fn($c) => preg_replace('/\D/', '', (string)$c['number']) === $digits));
    return [(string)($texts[0]['text'] ?? ''), count($texts), $after - $before];
}
/** The lines of a summary that carry money: the items, any split, the total. */
function money_lines(string $t): string {
    return implode("\n", array_values(array_filter(explode("\n", $t), fn($l) => preg_match('/^(📦|💰|🏷️)/u', $l) === 1)));
}

// ── the quotations ────────────────────────────────────────────────────────
$Q_ORDER = [   // order 000114, as the operator pasted it on 27 Sep 2026
    ['label' => 'Starlink Mini Kit', 'quantity' => 1, 'price' => 2249000],
    ['label' => 'Residential Lite (up to 100 Mbps)', 'quantity' => 1, 'price' => 249000],
    ['label' => 'Professional Installation', 'quantity' => 1, 'price' => 150000],
];
$Q_AREA = [    // a bigger-area setup, from the products the assistant designs with since 5.18.44 (docs/40)
    ['label' => 'Starlink Standard Kit', 'quantity' => 1, 'price' => 2649000],
    ['label' => 'Professional Installation', 'quantity' => 1, 'price' => 150000],
    ['label' => 'MikroTik L009 Series', 'quantity' => 1, 'price' => 700000],
    ['label' => 'Ruijie Reyee RG-RAP6262(G)', 'quantity' => 2, 'price' => 700000],
    ['label' => 'D-Link D.LINK CAT 6, OUTDOOR WATERPROOF CABLE', 'quantity' => 1, 'price' => 378000],
    ['label' => 'RJ45 Cat6 Pass-Through Connector (PACK OF 10)', 'quantity' => 1, 'price' => 19500],
    ['label' => 'ICT Consultancy Charges', 'quantity' => 1, 'price' => 100000],
    ['label' => 'Residential (up to 400 Mbps)', 'quantity' => 1, 'price' => 329000],
];
$SS_USD = ['currency_code' => 'USD'];
$SS_NONE = [];                      // a South Sudan install that names no currency: the default profile
$UG = ['currency_code' => 'UGX'];
$GOLDEN = $root . '/tests/fixtures/quote_summary_golden_ss.json';

// ── A. South Sudan: the summary it always sent ────────────────────────────
echo "\nA. South Sudan — byte for byte the summary it sent before, and no request more\n";
$ss = [];
foreach ([['usd-order', $SS_USD, $Q_ORDER], ['usd-area', $SS_USD, $Q_AREA], ['none-order', $SS_NONE, $Q_ORDER],
          ['none-area', $SS_NONE, $Q_AREA]] as $i => [$key, $cfg, $items]) {
    [$text, $count, $plans] = summary($cfg, $items, 10 + $i);
    $ss[$key] = $text;
    is_($count === 1 && strpos($text, "📄 *Quotation & Order Summary*") === 0, "{$key}: one summary, sent whole");
    is_($plans === 0, "{$key}: the webhook asked uCRM for no service plans", "asked {$plans} time(s)");
}
if (getenv('QUOTE_GOLDEN_WRITE') === '1') {
    file_put_contents($GOLDEN, json_encode(['note' => 'The South Sudan quotation summaries, taken from webhook.php as it '
        . 'was before 5.18.46 (tests/test_quote_summary.php). Never rewrite this from a later build.',
        'taken_from' => trim((string)shell_exec('git -C ' . escapeshellarg($root) . ' log -1 --format=%h -- webhook.php')),
        'summaries' => $ss], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    echo "  wrote {$GOLDEN}\n";
    printf("\n%d passed, %d failed\n", $pass, $fail);
    exit($fail === 0 ? 0 : 1);
}
$gold = json_decode((string)@file_get_contents($GOLDEN), true);
is_(is_array($gold) && count((array)($gold['summaries'] ?? [])) === 4, 'the South Sudan goldens are there', $GOLDEN);
foreach ($ss as $key => $text) {
    $want = (string)($gold['summaries'][$key] ?? '');
    is_($want !== '' && $text === $want, "{$key}: byte-identical to the summary before 5.18.46",
        $text === $want ? '' : "got:\n" . money_lines($text) . "\nwant:\n" . money_lines($want));
}
// What the old split did with a bigger-area setup, kept for South Sudan — the reason Uganda's changed.
is_(strpos($ss['usd-area'], "💰 Monthly: USD 1,848,500\n") !== false,
    'control: South Sudan still counts the access points, connectors and consultancy as "Monthly" (1,848,500)',
    money_lines($ss['usd-area']));

// ── B. Uganda: order 000114 ───────────────────────────────────────────────
echo "\nB. Uganda — order 000114, as a customer reads it\n";
[$t, $count, $plans] = summary($UG, $Q_ORDER, 20);
$want = "📦 Starlink Mini Kit — UGX 2,249,000\n"
      . "📦 Residential Lite (up to 100 Mbps) — UGX 249,000\n"
      . "📦 Professional Installation — UGX 150,000\n"
      . "💰 One-time: UGX 2,399,000\n"
      . "💰 First month: UGX 249,000 (then UGX 249,000 per month)\n"
      . "🏷️ *Total: UGX 2,648,000*";
is_($count === 1 && money_lines($t) === $want, 'the items, then One-time 2,399,000, First month 249,000 — then 249,000 per month — and the Total',
    "got:\n" . money_lines($t));
is_(strpos($t, 'Hardware:') === false && strpos($t, 'Monthly:') === false, 'no "Hardware" and no "Monthly" line');
is_(strpos($t, "*Total: UGX 2,648,000*\n\n💳 Cash / Transfer / Card\n✅ Reply *YES* to proceed.") !== false,
    'the Total and the payment lines exactly as before', $t);
is_($plans === 1, 'the webhook asked uCRM for its service plans once', "asked {$plans} time(s)");

// Order 000117, the one the operator confirmed on 27 Sep ("its provided correct working"): the same numbers, said plainly.
[$t, , ] = summary($UG, [['label' => 'Starlink Mini Kit + Mini Router', 'quantity' => 1, 'price' => 2249000],
                         ['label' => 'Residential Lite (up to 100 Mbps)', 'quantity' => 1, 'price' => 249000],
                         ['label' => 'Professional Installation', 'quantity' => 1, 'price' => 150000]], 28);
is_(strpos($t, "📦 Starlink Mini Kit + Mini Router — UGX 2,249,000\n📦 Residential Lite (up to 100 Mbps) — UGX 249,000\n"
             . "📦 Professional Installation — UGX 150,000\n💰 One-time: UGX 2,399,000\n"
             . "💰 First month: UGX 249,000 (then UGX 249,000 per month)\n🏷️ *Total: UGX 2,648,000*") !== false,
    'order 000117 as the operator approved it: One-time 2,399,000, First month 249,000 — then 249,000 per month — Total 2,648,000',
    money_lines($t));

// ── C. Uganda: a bigger-area setup ────────────────────────────────────────
echo "\nC. Uganda — a bigger-area setup: everything but the plan is one-time\n";
[$t, , ] = summary($UG, $Q_AREA, 21);
is_(strpos($t, "💰 One-time: UGX 5,396,500\n") !== false,
    'One-time holds the kit, installation, MikroTik, both access points, cable, connectors and consultancy (5,396,500)', money_lines($t));
is_(strpos($t, "💰 First month: UGX 329,000 (then UGX 329,000 per month)\n") !== false, 'First month is the plan alone', money_lines($t));
is_(strpos($t, "🏷️ *Total: UGX 5,725,500*") !== false, 'and the Total is uCRM\'s', money_lines($t));

// ── D. Uganda: a plan whose name holds "month" ────────────────────────────
echo "\nD. Uganda — a plan whose name holds \"month\" (the old rule read \"ont\" in it as hardware)\n";
$MONTHLY_PLANS = ['service_plans' => [['id' => 9, 'name' => 'Starlink Home Monthly (up to 100 Mbps)',
    'periods' => [['period' => 1, 'price' => 249000, 'enabled' => true]]]]];
[$t, , ] = summary($UG, [['label' => 'Starlink Mini Kit', 'quantity' => 1, 'price' => 2249000],
                         ['label' => 'Home Monthly (up to 100 Mbps)', 'quantity' => 1, 'price' => 249000]], 22, $MONTHLY_PLANS);
is_(strpos($t, "💰 One-time: UGX 2,249,000\n💰 First month: UGX 249,000 (then UGX 249,000 per month)\n") !== false,
    'it is the monthly plan, found by uCRM\'s own plan list', money_lines($t));
is_(preg_match('/ont/i', 'Home Monthly (up to 100 Mbps)') === 1, 'control: the old word list matches "ont" inside "Monthly"');

// ── E. Uganda: months paid up front ───────────────────────────────────────
echo "\nE. Uganda — three months paid up front\n";
[$t, , ] = summary($UG, [['label' => 'Starlink Mini Kit', 'quantity' => 1, 'price' => 2249000],
                         ['label' => 'Residential Lite (up to 100 Mbps)', 'quantity' => 3, 'price' => 249000]], 23);
is_(strpos($t, "💰 One-time: UGX 2,249,000\n💰 First 3 months: UGX 747,000 (then UGX 249,000 per month)\n") !== false,
    'First 3 months: 747,000 — then 249,000 per month', money_lines($t));

// ── F. Uganda: a first month at another price ─────────────────────────────
echo "\nF. Uganda — a first month quoted at another price: what follows is uCRM's plan price\n";
[$t, , ] = summary($UG, [['label' => 'Starlink Mini Kit', 'quantity' => 1, 'price' => 2249000],
                         ['label' => 'Residential Lite (up to 100 Mbps)', 'quantity' => 1, 'price' => 200000]], 24);
is_(strpos($t, "💰 First month: UGX 200,000 (then UGX 249,000 per month)\n") !== false,
    'First month 200,000 as quoted — then 249,000 per month, from the plan', money_lines($t));

// ── G. Uganda: uCRM cannot list its plans ─────────────────────────────────
echo "\nG. Uganda — uCRM cannot list its plans: no split it cannot stand behind\n";
[$t, $count, $plans] = summary($UG, $Q_ORDER, 25, ['plans_down' => true]);
$wantG = $want = "📦 Starlink Mini Kit — UGX 2,249,000\n"
      . "📦 Residential Lite (up to 100 Mbps) — UGX 249,000\n"
      . "📦 Professional Installation — UGX 150,000\n"
      . "🏷️ *Total: UGX 2,648,000*";
is_($count === 1 && money_lines($t) === $want, 'the summary still goes: the items and the Total, no split', "got:\n" . money_lines($t));
is_($plans === 1, 'it asked once and did not retry', "asked {$plans} time(s)");

// ── H. Uganda: nothing to split ───────────────────────────────────────────
echo "\nH. Uganda — only one-time lines, or only the plan: no split, as before\n";
[$t, , ] = summary($UG, [['label' => 'Starlink Mini Kit', 'quantity' => 1, 'price' => 2249000],
                         ['label' => 'Professional Installation', 'quantity' => 1, 'price' => 150000]], 26);
is_(strpos($t, '💰') === false && strpos($t, "🏷️ *Total: UGX 2,399,000*") !== false, 'one-time lines only: the items and the Total', money_lines($t));
[$t, , ] = summary($UG, [['label' => 'Residential Lite (up to 100 Mbps)', 'quantity' => 1, 'price' => 249000]], 27);
is_(strpos($t, '💰') === false && strpos($t, "🏷️ *Total: UGX 249,000*") !== false, 'the plan only: the item and the Total', money_lines($t));

// ── J. weakened copies of webhook.php must each fail ──────────────────────
echo "\nJ. Weakened copies of webhook.php — each must be caught\n";
$MUTS = [];
register_shutdown_function(function () use (&$MUTS) { foreach ($MUTS as $m) @unlink($m); });
$weakened = function (string $label, string $old, string $new, callable $caught) use ($root, $tmp, &$MUTS): void {
    $src = (string)file_get_contents($root . '/webhook.php');
    if (substr_count($src, $old) !== 1) { bad("the weakened copy \"{$label}\" could not be made — its anchor is not unique"); return; }
    $m = $root . '/webhook.qsmut-' . getmypid() . '-' . count($MUTS) . '.php';   // beside the real one: __DIR__ includes work
    $MUTS[] = $m;
    file_put_contents($m, str_replace($old, $new, $src));
    file_put_contents($tmp . '/webhookfile', $m);
    $hit = false;
    try { $hit = (bool)$caught(); } finally { file_put_contents($tmp . '/webhookfile', $root . '/webhook.php'); @unlink($m); }
    is_($hit, "caught: {$label}");
};
$n = 60;
$weakened('the Uganda summary for South Sudan too',
    'return TenantProfile::current($config, $dataDir !== \'\' ? $dataDir : null)->id() === \'uganda\';', 'return true;',
    function () use (&$n, $SS_USD, $Q_ORDER, $gold): bool {
        [$t, , $plans] = summary($SS_USD, $Q_ORDER, $n++);
        return $t !== (string)$gold['summaries']['usd-order'] || $plans !== 0;
    });
$weakened('Uganda back on the word list',
    '                if ($ugPlans !== false) {', '                if (false) {',
    function () use (&$n, $UG, $Q_AREA): bool {
        [$t, , ] = summary($UG, $Q_AREA, $n++);
        return strpos($t, "💰 One-time: UGX 5,396,500\n") === false;
    });
$weakened('what follows the first month taken from the quote line',
    '$perMonth     += (float)($ugPlans[$pk] ?? 0);', '$perMonth     += (float)($_qi[\'price\'] ?? 0);',
    function () use (&$n, $UG): bool {
        [$t, , ] = summary($UG, [['label' => 'Starlink Mini Kit', 'quantity' => 1, 'price' => 2249000],
                                 ['label' => 'Residential Lite (up to 100 Mbps)', 'quantity' => 1, 'price' => 200000]], $n++);
        return strpos($t, '(then UGX 249,000 per month)') === false;
    });
$weakened('months paid up front called one month',
    '    if (count($q) === 1 && floor($q[0]) === $q[0]) return \'First \' . (int)$q[0] . \' months\';',
    '    if (count($q) === 1 && floor($q[0]) === $q[0]) return \'First month\';',
    function () use (&$n, $UG): bool {
        [$t, , ] = summary($UG, [['label' => 'Starlink Mini Kit', 'quantity' => 1, 'price' => 2249000],
                                 ['label' => 'Residential Lite (up to 100 Mbps)', 'quantity' => 3, 'price' => 249000]], $n++);
        return strpos($t, '💰 First 3 months: UGX 747,000') === false;
    });
$weakened('the word list again when uCRM cannot list its plans',
    '$ugPlans  = whQuoteIsUganda($config, (string)$dataDir) ? whQuotePlans($crm, $changeType) : false;',
    '$ugPlans  = whQuoteIsUganda($config, (string)$dataDir) ? (whQuotePlans($crm, $changeType) ?? false) : false;',
    function () use (&$n, $UG, $Q_ORDER, $wantG): bool {
        [$t, $count, ] = summary($UG, $Q_ORDER, $n++, ['plans_down' => true]);
        return !($count === 1 && money_lines($t) === $wantG);
    });
$weakened("South Sudan's uCRM asked for its plans",
    '$ugPlans  = whQuoteIsUganda($config, (string)$dataDir) ? whQuotePlans($crm, $changeType) : false;',
    '$_pl = whQuotePlans($crm, $changeType); $ugPlans  = whQuoteIsUganda($config, (string)$dataDir) ? $_pl : false;',
    function () use (&$n, $SS_USD, $Q_ORDER): bool {
        [, , $plans] = summary($SS_USD, $Q_ORDER, $n++);
        return $plans !== 0;
    });
is_(glob($root . '/webhook.qsmut-*.php') === [], 'no weakened copy is left beside webhook.php');

// ── I. the source ─────────────────────────────────────────────────────────
echo "\nI. The rule in the source\n";
$src = (string)file_get_contents($root . '/webhook.php');
is_(strpos($src, 'PublicPriceFeed::planKey(') !== false, 'Uganda finds the plan with the same rule as the price feed and the assistant');
is_(substr_count($src, "\"💰 Hardware: \"") === 1 && substr_count($src, "\"💰 Monthly: \"") === 1,
    'the old two lines remain, once — South Sudan\'s');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
