<?php
/**
 * test_kyc_crm_messages.php — 5.18.30, kyc_messages_like_crm.
 *
 * The operator asked that a customer the KYC form puts into uCRM receive on
 * WhatsApp exactly what a customer created in uCRM receives: the client.add
 * "🎉 Welcome to DishNet!", then the quote.add "📄 Quotation & Order Summary"
 * with the quotation PDF. Until now such a customer got the plugin's own
 * "Request Confirmed!" at once and cron_quote_wa's proforma three minutes
 * later — and one the retry job created got no greeting at all.
 *
 * This drives the REAL code end to end, in the order a sale runs:
 *   the KYC form      includes/post/post_kyc.php → KycService::process, against
 *                     the fake uCRM (fixtures/fake_ucrm_kyc.php), notifying
 *                     through a real NotificationService in dry-run mode
 *   uCRM's webhooks   the real webhook.php under php -S: client.add, quote.add
 *   the cron          the real cron_quote_wa.php, laid out as on the server
 * and reads every WhatsApp back from the dry-run log, in the order sent.
 * Unset, the switch changes nothing: that is pinned too — it is South Sudan.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/CrmApiClient.php';
require_once $root . '/lib/NotificationService.php';
require_once $root . '/lib/QuoteWaLedger.php';

$tmp = sys_get_temp_dir() . '/dn_kyc_msgs_' . getmypid();
exec('rm -rf ' . escapeshellarg($tmp));
@mkdir($tmp, 0777, true);
ini_set('error_log', $tmp . '/php_errors.log');   // the services' own logging, kept out of the report
$procs = [];
register_shutdown_function(function () use (&$procs, $tmp) {
    foreach ($procs as $p) { if (is_resource($p)) { proc_terminate($p); proc_close($p); } }
    exec('rm -rf ' . escapeshellarg($tmp));
});

// ── A. the switch ──────────────────────────────────────────────────────────
echo "\nA. kyc_messages_like_crm — off unless set, read as the settings tool shows it\n";
foreach ([[[], false], [['kyc_messages_like_crm' => ''], false], [['kyc_messages_like_crm' => '0'], false],
          [['kyc_messages_like_crm' => 'off'], false], [['kyc_messages_like_crm' => false], false],
          [['kyc_messages_like_crm' => 'maybe'], false],
          [['kyc_messages_like_crm' => '1'], true], [['kyc_messages_like_crm' => 'on'], true],
          [['kyc_messages_like_crm' => 'yes'], true], [['kyc_messages_like_crm' => true], true]] as [$cfg, $want]) {
    is_(NotificationService::kycLikeCrm($cfg) === $want,
        ($cfg === [] ? 'unset' : var_export($cfg['kyc_messages_like_crm'], true)) . ' → ' . ($want ? 'on' : 'off'));
}
$sc = (string)file_get_contents($root . '/tools/set_config.php');
is_(strpos($sc, "'kyc_messages_like_crm' => ['bool',") !== false, 'it can be switched with tools/set_config.php, as a yes/no');

// ── the fake uCRM ──────────────────────────────────────────────────────────
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
// Ours echoes a token only this process knows, so a server left behind on the
// port by another run cannot be mistaken for it.
$token = bin2hex(random_bytes(8));
$crmPort = $start(sprintf('exec php -S 127.0.0.1:{PORT} %s', escapeshellarg($root . '/tests/fixtures/fake_ucrm_kyc.php')),
    function (int $port, bool $probe) use ($http, $token): bool {
        [, $b] = $http("http://127.0.0.1:{$port}/__test/ping", null, [], 2);
        if ($b === null) return false;
        $j = json_decode($b, true);
        return $probe ? true : (($j['marker'] ?? '') === 'FAKE-UCRM-KYC' && ($j['token'] ?? '') === $token);
    }, 10300, 17, 90, ['FAKE_UCRM_KYC_TOKEN' => $token] + getenv());
if ($crmPort === 0) { bad('the fake uCRM started'); echo "\n{$pass} passed, {$fail} failed\n"; exit(1); }
register_shutdown_function(function () use ($crmPort) { @unlink(sys_get_temp_dir() . '/fake_ucrm_kyc_' . $crmPort . '.json'); });
$BASE = "http://127.0.0.1:{$crmPort}/api/v2.1";
$ctl = function (string $path, ?array $body = null) use ($http, $crmPort): array {
    [, $b] = $http("http://127.0.0.1:{$crmPort}{$path}", $body === null ? null : json_encode($body),
                   $body === null ? [] : ['Content-Type: application/json']);
    return json_decode((string)$b, true) ?? [];
};

// ── the plugin: data directory, webhook under php -S, cron laid out as live ──
// Each scenario gets a data directory of its own (SqliteStore keeps per-path
// state, so a directory is never reused); the webhook reads which one from a
// pointer file on every request, the cron from ucrm.json.
$plugins = $tmp . '/plugins';
$pr      = $plugins . '/dishnet-hybrid-sudan';
@mkdir($pr, 0777, true);
exec('cp -R ' . escapeshellarg($root . '/lib') . ' ' . escapeshellarg($pr . '/lib'));
copy($root . '/cron_quote_wa.php', $pr . '/cron_quote_wa.php');
$dataDir = '';
$useDataDir = function (string $dir) use ($tmp, $pr, &$dataDir): void {
    $dataDir = $dir;
    @mkdir($dir, 0777, true);
    file_put_contents($tmp . '/datadir', $dir);
    // uCRM's own pluginDataDir wins in getDataDir(): the cron reads what the webhook writes.
    file_put_contents($pr . '/ucrm.json', json_encode(['pluginDataDir' => $dir]));
};
$useDataDir($tmp . '/data0');

file_put_contents($tmp . '/router.php', '<?php
declare(strict_types=1);
$root = ' . var_export($root, true) . ';
require_once $root . "/lib/timezone.php"; dn_tz_apply();
require_once $root . "/lib/StoreInterface.php";
require_once $root . "/lib/SqliteStore.php";
$dataDir = trim((string)file_get_contents(' . var_export($tmp . '/datadir', true) . '));
$store   = SqliteStore::create($dataDir);
$config  = $store->load("kyc_config.json") ?? [];
require $root . "/webhook.php";
');
SqliteStore::create($dataDir);
$webPort = $start(sprintf('exec php -S 127.0.0.1:{PORT} %s', escapeshellarg($tmp . '/router.php')),
    function (int $port, bool $probe) use ($http): bool {
        [, $b] = $http("http://127.0.0.1:{$port}/webhook.php", null, [], 2);
        return $b !== null && ($probe || strpos($b, 'POST required') !== false);
    }, 10500, 19, 90, getenv());
if ($webPort === 0) { bad('the plugin started under php -S'); echo "\n{$pass} passed, {$fail} failed\n"; exit(1); }

$AGENT = ['id' => 7, 'name' => 'Test Agent', 'phone' => '+256700000999', 'role' => 'sales', 'is_admin' => false];

/** A clean install: fake uCRM reset, empty store, the plan and kit the form sells, the settings. */
function world(bool $likeCrm): SqliteStore {
    global $ctl, $dataDir, $BASE, $useDataDir, $tmp;
    static $n = 0;
    $ctl('/__test/reset?scenario=uganda');
    $useDataDir($tmp . '/data' . (++$n));
    $s = SqliteStore::create($dataDir);
    $s->save('subscription_plans.json', [['id' => 1, 'name' => 'Residential Standard (TEST)', 'customer_price' => 100,
        'ucrm_product_id' => 12]]);
    $s->save('kyc_devices.json', [['id' => 3, 'title' => 'Standard Kit (TEST)', 'price' => '1500', 'ucrm_product_id' => 55]]);
    $s->save('kyc_config.json', [
        'crm_base_url' => $BASE, 'crm_auth_token' => 'FAKE-TEST-KEY',
        'dry_run_mode' => true, 'data_dir' => $dataDir,
        'wa_plugin_url' => 'http://127.0.0.1:1/', 'wa_app_key' => 'k', 'wa_auth_key' => 'a',
        'webhook_secret' => 'testsecret', 'currency_code' => 'UGX',
        'contact_escalation_phone' => '+256 705 993 348', 'contact_sales_phone' => '+256 705 993 348',
        'contact_shop_phone' => '+256 705 993 348',
    ] + ($likeCrm ? ['kyc_messages_like_crm' => '1'] : []));
    return $s;
}

/** The KYC form, submitted by an agent: post_kyc.php with the real service and notifier. */
function form(string $phone, string $last): array {
    global $root, $tmp, $dataDir, $BASE, $AGENT;
    $post = ['action' => 'kyc_submit', 'customer_type' => 'StarLink', 'connectivity_type' => 'New Connection',
             'firstname' => 'Test', 'lastname' => $last, 'mobile' => $phone, 'email' => '',
             'address_1' => 'Test address', 'package_choice' => '1', 'device_id' => '3', 'kitQty' => '1',
             'sales_type' => 'Credit', 'priority' => 'Low'];
    $f = $tmp . '/form_run.php';
    file_put_contents($f, '<?php
error_reporting(E_ALL & ~E_DEPRECATED);
$root = ' . var_export($root, true) . ';
foreach (["StoreInterface", "JsonStore", "SqliteStore", "CrmApiClient", "WalletService", "CrmQueue",
          "EfrisClientField", "UcrmClientTarget", "KycService", "NotificationService"] as $l) require_once "$root/lib/$l.php";
$GLOBALS["__flash"] = null;
function flash(string $m, string $t = "success"): void { $GLOBALS["__flash"] = ["msg" => $m, "type" => $t]; }
function redirect(string $u): void { echo json_encode(["flash" => $GLOBALS["__flash"], "to" => $u]); exit; }
function logActivity(...$a): void {}
final class StubAuth {
    public function requireLogin(): array { return json_decode(' . var_export(json_encode($AGENT), true) . ', true); }
    public function requireAdmin(): array { return $this->requireLogin(); }
}
$auth    = new StubAuth();
$dataDir = ' . var_export($dataDir, true) . ';
$store   = SqliteStore::create($dataDir);
$config  = $store->load("kyc_config.json") ?? [];
$crm     = new CrmApiClient(' . var_export($BASE, true) . ', "FAKE-TEST-KEY", "X-Auth-App-Key");
$kyc     = new KycService($crm, $store, new WalletService($store, $store->getPdo()), new CrmQueue($store), $dataDir);
$notify  = new NotificationService($store, $config);
$_SERVER["REQUEST_METHOD"] = "POST";
$_POST   = json_decode(' . var_export(json_encode($post), true) . ', true);
$_FILES  = [];
include $root . "/includes/post/post_kyc.php";
echo json_encode(["fell_through" => true]);
');
    exec('php ' . escapeshellarg($f) . ' 2>&1', $o);
    $o = implode("\n", $o);
    $lines = explode("\n", trim($o));
    $j = json_decode((string)end($lines), true);
    return is_array($j) ? $j + ['_out' => $o] : ['_out' => $o];
}

/** What uCRM does after a create: POST the event to the plugin, then wait for its background work. */
function fire(string $type, int $id): array {
    global $http, $webPort;
    $payload = json_encode(['changeType' => $type, 'entity' => explode('.', $type)[0], 'entityId' => $id,
                            'uuid' => 'test-' . $type . '-' . $id . '-' . bin2hex(random_bytes(3)),
                            'extraData' => ['entity' => ['id' => $id]]]);
    $r = $http("http://127.0.0.1:{$webPort}/webhook.php", $payload,
               ['Content-Type: application/json', 'X-Ucrm-Key: testsecret'], 90);
    // php -S serves one request at a time: this returns only once the handler
    // has finished everything it does after answering uCRM (the PDF wait, the sends).
    $http("http://127.0.0.1:{$webPort}/webhook.php", null, [], 90);
    return $r;
}

/** cron_quote_wa.php as master.php runs it, on the live layout. */
function cron(): string {
    global $pr;
    exec('php ' . escapeshellarg($pr . '/cron_quote_wa.php') . ' 2>&1', $o, $code);
    return 'exit=' . $code . ' ' . implode("\n", $o);
}

/** Every WhatsApp to one number, in the order sent: [event, message]. */
function wa(string $phone): array {
    global $dataDir;
    $digits = preg_replace('/\D/', '', $phone);
    $log = json_decode((string)@file_get_contents($dataDir . '/dry_run_notification_log.json'), true) ?: [];
    $out = [];
    foreach ($log as $e) {
        if (preg_replace('/\D/', '', (string)($e['phone'] ?? '')) === $digits) {
            $out[] = ['event' => (string)($e['event'] ?? ''), 'message' => (string)($e['message'] ?? '')];
        }
    }
    return $out;
}
function events(array $msgs): array { return array_map(fn($m) => $m['event'], $msgs); }
function whlog(): string { global $dataDir; return (string)@file_get_contents($dataDir . '/webhook_log.json'); }
function qwalog(): string { global $dataDir; return (string)@file_get_contents($dataDir . '/quote_wa_log.json'); }
function app(SqliteStore $s, int $id): array { return $s->findOne('kyc_applications.json', 'id', $id) ?? []; }
function ledger(SqliteStore $s): array {
    try { return $s->getPdo()->query('SELECT quote_id, source FROM wa_sent_quotes ORDER BY quote_id')->fetchAll(PDO::FETCH_ASSOC); }
    catch (\Throwable $e) { return []; }
}
/** The cron's fallback waits three minutes after the form queued the quote; be three minutes later. */
function later(SqliteStore $s, int $id): void {
    $s->updateOne('kyc_applications.json', 'id', $id, ['wa_quote_deferred_at' => date('Y-m-d H:i:s', time() - 600)]);
}
function the_app(SqliteStore $s): array { $all = $s->load('kyc_applications.json') ?? []; return end($all) ?: []; }

// ── B. unset: nothing changes (South Sudan, and Uganda until it is switched on) ──
echo "\nB. Unset — the plugin's own messages, exactly as before\n";
$s = world(false);
$P = '+256 700 000 601';
$r = form($P, 'Plain');
$a = the_app($s);
is_(($r['flash']['type'] ?? '') === 'success' && ($a['crm_client_id'] ?? '') === '901' && (int)($a['quote_id'] ?? 0) === 77,
    'the form puts the customer into uCRM (#901) and quotes them (Q-77)', $r['_out']);
is_(events(wa($P)) === ['ops_kyc_customer_welcome'], 'the customer is sent "Request Confirmed!" at once',
    json_encode(events(wa($P))));
is_(strpos(wa($P)[0]['message'] ?? '', 'Request Confirmed') !== false, '…which is that message', wa($P)[0]['message'] ?? '');
is_(in_array('ops_kyc_crm_created', events(wa($AGENT['phone'])), true), 'the agent is told the account was created');
fire('client.add', 901);
is_(events(wa($P)) === ['ops_kyc_customer_welcome'], 'uCRM\'s client.add sends no welcome to a KYC customer',
    json_encode(events(wa($P))));
is_(strpos(whlog(), 'welcome already sent by plugin') !== false, '…and says why');
fire('quote.add', 77);
$a = app($s, (int)$a['id']);
is_(events(wa($P)) === ['ops_kyc_customer_welcome'], 'quote.add sends nothing itself', json_encode(events(wa($P))));
is_(!empty($a['wa_quote_pending']) && empty($a['wa_quote_sent']) && strpos(whlog(), 'DEFERRED to cron_quote_wa') !== false,
    '…it leaves the quotation to the cron, as before');
later($s, (int)$a['id']);
$c = cron();
$a = app($s, (int)$a['id']);
is_(events(wa($P)) === ['ops_kyc_customer_welcome', 'ops_quote_wa', 'ops_quote_pdf'],
    'the cron sends the proforma and its PDF', json_encode(events(wa($P))) . ' ' . $c);
is_(($a['wa_quote_sent_by'] ?? '') === 'cron_quote_wa' && ledger($s) === [['quote_id' => 77, 'source' => 'flow_a_kyc']],
    '…recorded as the cron\'s send, through the shared ledger', json_encode(ledger($s)));
$c = cron();
is_(count(wa($P)) === 3, 'a second cron run sends nothing more', $c);

// ── C. on: the messages a customer created in uCRM gets ─────────────────────
echo "\nC. On — \"Welcome to DishNet!\", then the Quotation & Order Summary and its PDF\n";
$s = world(true);
$P = '+256 700 000 602';
$r = form($P, 'Onboarded');
$a = the_app($s);
is_(($a['crm_client_id'] ?? '') === '901' && (int)($a['quote_id'] ?? 0) === 77 && !empty($a['wa_quote_pending']),
    'the form creates and quotes as before, and still queues the quote for the cron (the fallback)', $r['_out']);
is_(wa($P) === [], 'the form sends the customer nothing of its own', json_encode(wa($P)));
is_(in_array('ops_kyc_crm_created', events(wa($AGENT['phone'])), true), 'the agent is still told the account was created');
fire('client.add', 901);
$w = wa($P);
is_(events($w) === ['event_client_add'], 'client.add sends the customer the uCRM welcome', json_encode(events($w)));
is_(strpos($w[0]['message'] ?? '', "🎉 *Welcome to DishNet!*\n\nHi Test Onboarded,\n\nYour account has been created.") === 0,
    '…"Welcome to DishNet!", by name, the same text a uCRM-created customer gets', $w[0]['message'] ?? '');
is_(strpos(whlog(), 'Welcome sent to Test Onboarded') !== false && strpos(whlog(), 'kyc_messages_like_crm') !== false,
    '…and the webhook log says why it went to a KYC customer');
fire('quote.add', 77);
$w = wa($P);
$a = app($s, (int)$a['id']);
is_(events($w) === ['event_client_add', 'ops_quote_created', 'ops_quote_pdf'],
    'quote.add then sends the Quotation & Order Summary and the PDF — in that order, after the welcome',
    json_encode(events($w)) . ' ' . substr(whlog(), -800));
is_(strpos($w[1]['message'] ?? '', "📄 *Quotation & Order Summary*\n\nDear Test Onboarded,") === 0
    && strpos($w[1]['message'] ?? '', '*Order:* Q-77') !== false,
    '…the summary uCRM quotes get, for this customer and this quote', $w[1]['message'] ?? '');
is_(strpos($w[2]['message'] ?? '', '[DOC] DishNet-Quote-Q-77.pdf: Quote #Q-77 — UGX 1,600') === 0,
    '…and the quotation PDF, for the full amount', $w[2]['message'] ?? '');
is_(empty($a['wa_quote_pending']) && !empty($a['wa_quote_sent']) && ($a['wa_quote_sent_by'] ?? '') === 'webhook',
    'the application records the quotation as sent, by the webhook', json_encode(array_intersect_key($a,
        array_flip(['wa_quote_pending', 'wa_quote_sent', 'wa_quote_sent_by']))));
is_(ledger($s) === [['quote_id' => 77, 'source' => 'webhook_kyc']], 'and so does the shared ledger', json_encode(ledger($s)));
later($s, (int)$a['id']);
$c = cron();
is_(count(wa($P)) === 3, 'the cron sends nothing more — no proforma after the summary', json_encode(events(wa($P))) . ' ' . $c);
is_(strpos(qwalog(), 'FLOW A: Found 0 pending') !== false, '…it has nothing queued', substr(qwalog(), 0, 400));
$n = 0; foreach (wa($P) as $m) if (strpos($m['message'], 'Request Confirmed') !== false) $n++;
is_($n === 0, 'and "Request Confirmed!" was never sent');

// ── D. the cron's copy still says pending (the form wrote after the webhook) ──
echo "\nD. The ledger, not the flag, is what stops a second send\n";
$s->updateOne('kyc_applications.json', 'id', (int)$a['id'], ['wa_quote_pending' => true, 'wa_quote_sent' => false,
    'wa_quote_sent_by' => null, 'wa_quote_deferred_at' => date('Y-m-d H:i:s', time() - 600)]);
$c = cron();
is_(count(wa($P)) === 3, 'an application still queued finds the webhook\'s claim: nothing is sent', json_encode(events(wa($P))) . ' ' . $c);
is_(strpos(qwalog(), 'DEDUP BLOCK Flow A: quote #77') !== false && (app($s, (int)$a['id'])['wa_quote_sent_by'] ?? '') === 'dedup_block',
    '…the cron says so and settles the application', substr(qwalog(), 0, 400));

// ── E. uCRM's quote webhook arrives after the cron's fallback sent it ────────
echo "\nE. A late quote.add: the fallback went first, the webhook stands down\n";
$s = world(true);
$P = '+256 700 000 603';
form($P, 'Late');
$a = the_app($s);
fire('client.add', 901);
later($s, (int)$a['id']);
$c = cron();
is_(events(wa($P)) === ['event_client_add', 'ops_quote_wa', 'ops_quote_pdf'],
    'uCRM\'s webhook had not come: the cron\'s fallback sent the quotation', json_encode(events(wa($P))) . ' ' . $c);
fire('quote.add', 77);
is_(count(wa($P)) === 3, 'when it does come, nothing is sent a second time', json_encode(events(wa($P))));
is_(strpos(whlog(), 'Quote #Q-77 already sent by cron_quote_wa') !== false, '…and the webhook log says so');

// ── F. uCRM cannot produce the PDF: the summary alone, still sent once ──────
echo "\nF. No PDF from uCRM — the summary alone, still once\n";
$s = world(true);
$ctl('/__test/set', ['pdf_down' => true]);
$P = '+256 700 000 604';
form($P, 'Nopdf');
$a = the_app($s);
fire('client.add', 901);
fire('quote.add', 77);
is_(events(wa($P)) === ['event_client_add', 'ops_quote_created'], 'the summary goes without its PDF', json_encode(events(wa($P))));
is_((app($s, (int)$a['id'])['wa_quote_sent_by'] ?? '') === 'webhook' && ledger($s) === [['quote_id' => 77, 'source' => 'webhook_kyc']],
    '…recorded as sent, so the cron will not send a proforma after it');
later($s, (int)$a['id']);
cron();
is_(count(wa($P)) === 2, 'and it does not', json_encode(events(wa($P))));

// ── G. a customer created in uCRM: the same with the switch on or off ───────
echo "\nG. A customer created in uCRM — unchanged by the switch\n";
foreach ([false, true] as $on) {
    $s = world($on);
    $P = $on ? '+256 700 000 606' : '+256 700 000 605';
    $ctl('/__test/set', ['clients' => [['id' => 555, 'firstName' => 'Walk', 'lastName' => 'In', 'street1' => 'Plot 9',
        'contacts' => [['phone' => $P, 'email' => '']]]]]);
    $http("http://127.0.0.1:{$crmPort}/api/v2.1/clients/555/quotes", json_encode(['items' => [
        ['label' => 'Residential Standard (TEST)', 'quantity' => 1, 'price' => 100]]]),
        ['Content-Type: application/json', 'X-Auth-App-Key: FAKE-TEST-KEY']);
    fire('client.add', 555);
    fire('quote.add', 77);
    is_(events(wa($P)) === ['event_client_add', 'ops_quote_created', 'ops_quote_pdf'],
        ($on ? 'on' : 'off') . ': welcome, summary, PDF — as always', json_encode(events(wa($P))));
    is_(ledger($s) === [], ($on ? 'on' : 'off') . ': and no claim is taken for it — its path is not the switch\'s', json_encode(ledger($s)));
}

// ── H. the retry job creates a customer the form could not ──────────────────
echo "\nH. A customer the retry job puts into uCRM gets the welcome too\n";
$s = world(true);
$P = '+256 700 000 607';
$s->save('kyc_applications.json', [['id' => 40, 'firstname' => 'Test', 'lastname' => 'Retried', 'mobile' => $P,
    'customer_type' => 'StarLink', 'connectivity_type' => 'New Connection', 'address_1' => 'Test address',
    'package_choice' => '1', 'offer_name' => 'Residential Standard (TEST)', 'offer_price' => 100,
    'sales_type' => 'Credit', 'is_lead' => true, 'username' => 'STAR0000040', 'status' => 'new',
    'crm_client_id' => null, 'crm_sync_status' => 'pending', 'created_at' => date('Y-m-d H:i:s'),
    'quote_items' => [['label' => 'Residential Standard (TEST)', 'quantity' => 1, 'price' => 100.0, 'unit' => 'month']]]]);
require_once $root . '/lib/WalletService.php';
require_once $root . '/lib/CrmQueue.php';
require_once $root . '/lib/EfrisClientField.php';
require_once $root . '/lib/UcrmClientTarget.php';
require_once $root . '/lib/KycService.php';
require_once $root . '/lib/KycCrmSync.php';
(new KycCrmSync($s, new CrmApiClient($BASE, 'FAKE-TEST-KEY', 'X-Auth-App-Key'), $s->load('kyc_config.json') ?? []))->runDue();
$a = app($s, 40);
is_(($a['crm_client_id'] ?? '') === '901' && (int)($a['quote_id'] ?? 0) === 77, 'the retry creates and quotes the customer');
fire('client.add', 901);
fire('quote.add', 77);
is_(events(wa($P)) === ['event_client_add', 'ops_quote_created', 'ops_quote_pdf'],
    'welcome, summary, PDF — until now this customer got no greeting at all', json_encode(events(wa($P))));

// ── I. a customer with two applications: only the quoted one is marked ───────
echo "\nI. Two applications for one customer — only the one quoted is marked sent\n";
$s = world(true);
$P = '+256 700 000 608';
$ctl('/__test/set', ['clients' => [['id' => 777, 'firstName' => 'Two', 'lastName' => 'Sites',
    'contacts' => [['phone' => $P, 'email' => '']]]]]);
$http("http://127.0.0.1:{$crmPort}/api/v2.1/clients/777/quotes", json_encode(['items' => [
    ['label' => 'Standard Kit (TEST)', 'quantity' => 1, 'price' => 1500]]]),
    ['Content-Type: application/json', 'X-Auth-App-Key: FAKE-TEST-KEY']);
$first  = ['id' => 50, 'crm_client_id' => '777', 'mobile' => $P, 'quote_id' => 11, 'wa_quote_pending' => false];
$second = ['id' => 51, 'crm_client_id' => '777', 'mobile' => $P, 'quote_id' => 77, 'wa_quote_pending' => true,
           'is_additional_service' => true];
$s->save('kyc_applications.json', [$first, $second]);
fire('quote.add', 77);
is_(events(wa($P)) === ['ops_quote_created', 'ops_quote_pdf'], 'the second site\'s quotation goes out like any other',
    json_encode(events(wa($P))));
$one = app($s, 50); $two = app($s, 51);
is_(($two['wa_quote_sent_by'] ?? '') === 'webhook' && empty($two['wa_quote_pending']), 'its application is marked sent');
is_(!array_key_exists('wa_quote_sent', $one) && (int)($one['quote_id'] ?? 0) === 11,
    'the customer\'s first application is left exactly as it was', json_encode($one));

// ── J. the send record cannot be written: send nothing, leave it to the cron ──
echo "\nJ. The webhook cannot record the send — it sends nothing rather than risk it twice\n";
$s = world(true);
$P = '+256 700 000 609';
form($P, 'Norecord');
$a = the_app($s);
// A table of that name the ledger cannot write to: every INSERT fails.
$s->getPdo()->exec('CREATE TABLE wa_sent_quotes (quote_id INTEGER PRIMARY KEY)');
fire('client.add', 901);
fire('quote.add', 77);
is_(events(wa($P)) === ['event_client_add'], 'no summary is sent when the claim cannot be taken', json_encode(events(wa($P))));
is_(strpos(whlog(), 'its send record could not be written') !== false && strpos(whlog(), 'left to cron_quote_wa') !== false,
    '…and the webhook log says exactly that', substr(whlog(), -600));
$a = app($s, (int)$a['id']);
is_(!empty($a['wa_quote_pending']) && empty($a['wa_quote_sent']), 'the application stays queued for the cron');

// ── K. the dry-run log survives a cut through an emoji ──────────────────────
echo "\nK. The dry-run log keeps every entry, whatever the 200th byte is\n";
$dd = $tmp . '/dryrun';
@mkdir($dd, 0777, true);
$ns = new NotificationService(SqliteStore::create($dd), ['dry_run_mode' => true, 'data_dir' => $dd,
    'wa_plugin_url' => 'http://127.0.0.1:1/', 'wa_app_key' => 'k', 'wa_auth_key' => 'a']);
$ns->sendVia('support', '+256700000690', 'first', 'probe_first');
$ns->sendVia('support', '+256700000690', str_repeat('a', 199) . '📄 cut here', 'probe_cut');
$l = json_decode((string)@file_get_contents($dd . '/dry_run_notification_log.json'), true) ?: [];
is_(count($l) === 2 && ($l[0]['event'] ?? '') === 'probe_first',
    'a message cut inside an emoji is logged, and the entries before it are kept (it used to empty the file)',
    'entries: ' . count($l));
is_(strlen($l[1]['message'] ?? '') <= 203 && mb_check_encoding($l[1]['message'] ?? '', 'UTF-8'),
    '…cut at a character boundary, still at most 200 bytes');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
