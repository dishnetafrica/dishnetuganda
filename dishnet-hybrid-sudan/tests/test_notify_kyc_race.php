<?php
/**
 * test_notify_kyc_race.php — 5.18.54, docs/46 row 19 (D8): the welcome and "Request Confirmed!" never both.
 *
 * The KYC form creates the customer in uCRM, then saves its application. uCRM raises client.add as soon as it has
 * made the client, and the webhook decides between its own welcome and the form's "Request Confirmed!" by looking for
 * that application — so when the event overtakes the form, the customer got both. Here the race is FORCED, not hoped
 * for: the fake uCRM (fixtures/fake_ucrm_kyc.php, webhook_on_create) delivers client.add to the real webhook before it
 * answers the create, exactly the order that produced the duplicate.
 *
 * Driven through the real code: includes/post/post_kyc.php → KycService, the retry job KycCrmSync, and webhook.php
 * under php -S; every WhatsApp read back from the dry-run log, in the order sent.
 *
 *   1. Uganda, the race: one message, the form's — the form marks the username before asking uCRM
 *   2. Uganda, no race: one message, as always (control)
 *   3. Uganda with kyc_messages_like_crm, the race: one message, the welcome — the switch's path is unchanged
 *   4. Uganda, the retry job and the race: no welcome (the form's message went when the form ran)
 *   5. South Sudan, the race: both, as in 5.18.53 (docs/46 §E)
 *   6. Weakened copies, each caught (skipped with --no-mutants)
 *
 * Every person, number and document is fictitious; nothing leaves the machine.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$withMutants = !in_array('--no-mutants', $argv, true);
$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; }
    else    { $fail++; echo "  FAIL {$m}" . ($d !== '' ? "\n       {$d}" : '') . "\n"; }
}
require_once __DIR__ . '/fixtures/staff_jobs_sandbox.php';   // sj_weakened_copy()

$tmp = sys_get_temp_dir() . '/dn_kyc_race_' . getmypid();
exec('rm -rf ' . escapeshellarg($tmp));
@mkdir($tmp, 0777, true);
ini_set('error_log', $tmp . '/php_errors.log');
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
$start = function (string $cmd, callable $isUp, int $base, int $step, int $span, array $env) use (&$procs): int {
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

// ── The fake uCRM, with workers: the webhook reads the new client back from it while the create waits ────────────────
$token = bin2hex(random_bytes(8));
$crmPort = $start(sprintf('exec php -S 127.0.0.1:{PORT} %s', escapeshellarg($root . '/tests/fixtures/fake_ucrm_kyc.php')),
    function (int $port, bool $probe) use ($http, $token): bool {
        [, $b] = $http("http://127.0.0.1:{$port}/__test/ping", null, [], 2);
        if ($b === null) return false;
        $j = json_decode($b, true);
        return $probe ? true : (($j['marker'] ?? '') === 'FAKE-UCRM-KYC' && ($j['token'] ?? '') === $token);
    }, 11300, 17, 90, ['FAKE_UCRM_KYC_TOKEN' => $token, 'PHP_CLI_SERVER_WORKERS' => '4'] + getenv());
if ($crmPort === 0) { is_(false, 'the fake uCRM started'); echo "\n{$pass} passed, {$fail} failed\n"; exit(1); }
$crmState = sys_get_temp_dir() . '/fake_ucrm_kyc_' . $crmPort . '.json';
register_shutdown_function(function () use ($crmState) { @unlink($crmState); });
$BASE = "http://127.0.0.1:{$crmPort}/api/v2.1";
$ctl = function (string $path, ?array $body = null) use ($http, $crmPort): array {
    [, $b] = $http("http://127.0.0.1:{$crmPort}{$path}", $body === null ? null : json_encode($body),
                   $body === null ? [] : ['Content-Type: application/json']);
    return json_decode((string)$b, true) ?? [];
};

/** A plugin tree's webhook under php -S, reading its data directory from a pointer file: [port, pointer]. */
$plugin = function (string $tree, string $tag) use ($tmp, $start, $http): array {
    $ptr = "{$tmp}/datadir_{$tag}";
    file_put_contents($ptr, $tmp);
    file_put_contents("{$tmp}/router_{$tag}.php", '<?php
declare(strict_types=1);
$root = ' . var_export($tree, true) . ';
require_once $root . "/lib/timezone.php"; dn_tz_apply();
require_once $root . "/lib/StoreInterface.php";
require_once $root . "/lib/SqliteStore.php";
$dataDir = trim((string)file_get_contents(' . var_export($ptr, true) . '));
$store   = SqliteStore::create($dataDir);
$config  = $store->load("kyc_config.json") ?? [];
require $root . "/webhook.php";
');
    $port = $start(sprintf('exec php -S 127.0.0.1:{PORT} %s', escapeshellarg("{$tmp}/router_{$tag}.php")),
        function (int $port, bool $probe) use ($http): bool {
            [, $b] = $http("http://127.0.0.1:{$port}/webhook.php", null, [], 2);
            return $b !== null && ($probe || strpos($b, 'POST required') !== false);
        }, 11500, 19, 90, getenv());
    return [$port, $ptr];
};

const AGENT = ['id' => 7, 'name' => 'Test Agent', 'phone' => '+256700000999', 'role' => 'sales', 'is_admin' => false];

/** A clean install on one tree: fake uCRM reset, empty store, the plan and kit the form sells, the settings. */
$world = function (string $tree, array $web, string $tenant, bool $likeCrm, bool $race) use ($ctl, $tmp, $BASE, $root): array {
    static $n = 0;
    [$port, $ptr] = $web;
    $ctl('/__test/reset?scenario=uganda');
    if ($race) $ctl('/__test/set', ['webhook_on_create' => "http://127.0.0.1:{$port}/webhook.php", 'webhook_on_create_key' => 'testsecret']);
    $dir = "{$tmp}/data" . (++$n);
    @mkdir($dir, 0777, true);
    file_put_contents($ptr, $dir);
    // Seeded with the repository's own store, loaded once in this process: a weakened copy changes the code under
    // test, never the store, and a second copy of the same classes could not be loaded here.
    require_once $root . '/lib/StoreInterface.php';
    require_once $root . '/lib/SqliteStore.php';
    $s = SqliteStore::create($dir);
    $s->save('subscription_plans.json', [['id' => 1, 'name' => 'Residential Standard (TEST)', 'customer_price' => 100, 'ucrm_product_id' => 12]]);
    $s->save('kyc_devices.json', [['id' => 3, 'title' => 'Standard Kit (TEST)', 'price' => '1500', 'ucrm_product_id' => 55]]);
    $s->save('kyc_config.json', [
        'tenant_profile' => $tenant, 'currency_code' => $tenant === 'uganda' ? 'UGX' : 'USD',
        'crm_base_url' => $BASE, 'crm_auth_token' => 'FAKE-TEST-KEY',
        'dry_run_mode' => true, 'data_dir' => $dir,
        'wa_plugin_url' => 'http://127.0.0.1:1/', 'wa_app_key' => 'k', 'wa_auth_key' => 'a', 'webhook_secret' => 'testsecret',
        'contact_escalation_phone' => '+256 705 993 348', 'contact_sales_phone' => '+256 705 993 348', 'contact_shop_phone' => '+256 705 993 348',
    ] + ($likeCrm ? ['kyc_messages_like_crm' => '1'] : []));
    return [$s, $dir];
};

/** The KYC form, submitted by an agent: post_kyc.php on $tree, with the real service and notifier, in its own process. */
$form = function (string $tree, string $dir, string $phone, string $last) use ($tmp, $BASE): array {
    $post = ['action' => 'kyc_submit', 'customer_type' => 'StarLink', 'connectivity_type' => 'New Connection',
             'firstname' => 'Test', 'lastname' => $last, 'mobile' => $phone, 'email' => '',
             'address_1' => 'Test address', 'package_choice' => '1', 'device_id' => '3', 'kitQty' => '1',
             'sales_type' => 'Credit', 'priority' => 'Low'];
    $f = "{$tmp}/form_run.php";
    file_put_contents($f, '<?php
error_reporting(E_ALL & ~E_DEPRECATED);
$root = ' . var_export($tree, true) . ';
foreach (["StoreInterface", "JsonStore", "SqliteStore", "CrmApiClient", "WalletService", "CrmQueue",
          "EfrisClientField", "UcrmClientTarget", "KycService", "NotificationService"] as $l) require_once "$root/lib/$l.php";
$GLOBALS["__flash"] = null;
function flash(string $m, string $t = "success"): void { $GLOBALS["__flash"] = ["msg" => $m, "type" => $t]; }
function redirect(string $u): void { echo json_encode(["flash" => $GLOBALS["__flash"], "to" => $u]); exit; }
function logActivity(...$a): void {}
final class StubAuth {
    public function requireLogin(): array { return json_decode(' . var_export(json_encode(AGENT), true) . ', true); }
    public function requireAdmin(): array { return $this->requireLogin(); }
}
$auth    = new StubAuth();
$dataDir = ' . var_export($dir, true) . ';
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
    $lines = explode("\n", trim(implode("\n", $o)));
    $j = json_decode((string)end($lines), true);
    return is_array($j) ? $j + ['_out' => implode("\n", $o)] : ['_out' => implode("\n", $o)];
};

/** The retry job on $tree, as the cron runs it: every pending application that is due. */
$retry = function (string $tree, string $dir) use ($tmp, $BASE): string {
    $f = "{$tmp}/retry_run.php";
    file_put_contents($f, '<?php
error_reporting(E_ALL & ~E_DEPRECATED);
$root = ' . var_export($tree, true) . ';
foreach (["StoreInterface", "JsonStore", "SqliteStore", "CrmApiClient", "WalletService", "CrmQueue",
          "EfrisClientField", "UcrmClientTarget", "KycService", "NotificationService", "KycCrmSync"] as $l) require_once "$root/lib/$l.php";
$store = SqliteStore::create(' . var_export($dir, true) . ');
echo json_encode((new KycCrmSync($store, new CrmApiClient(' . var_export($BASE, true) . ', "FAKE-TEST-KEY", "X-Auth-App-Key"),
    $store->load("kyc_config.json") ?? []))->runDue());
');
    exec('php ' . escapeshellarg($f) . ' 2>&1', $o);
    return implode("\n", $o);
};

$fire = function (int $port, string $type, int $id) use ($http): void {
    $http("http://127.0.0.1:{$port}/webhook.php", json_encode(['changeType' => $type, 'entity' => explode('.', $type)[0],
        'entityId' => $id, 'uuid' => 'test-' . $type . '-' . $id . '-' . bin2hex(random_bytes(3)), 'extraData' => ['entity' => ['id' => $id]]]),
        ['Content-Type: application/json', 'X-Ucrm-Key: testsecret'], 90);
};
/** Every WhatsApp to one number, in the order sent: the event names. */
$events = function (string $dir, string $phone): array {
    $digits = preg_replace('/\D/', '', $phone);
    $out = [];
    foreach (json_decode((string)@file_get_contents($dir . '/dry_run_notification_log.json'), true) ?: [] as $e) {
        if (preg_replace('/\D/', '', (string)($e['phone'] ?? '')) === $digits) $out[] = (string)($e['event'] ?? '');
    }
    return $out;
};
$whlog = function (string $dir): string { return (string)@file_get_contents($dir . '/webhook_log.json'); };
$raced = function () use ($crmState): array {
    $st = json_decode((string)@file_get_contents($crmState), true) ?: [];
    return array_map(function ($r) { return (int)($r['code'] ?? 0); }, (array)($st['race'] ?? []));
};
const MARKED = 'its application was still being saved';

// ── The cases, as functions of a tree, so a weakened copy runs the very same ones ─────────────────────────────────────

/** Uganda (unless told otherwise), the switch off, the race forced: what the customer is sent. */
$formRace = function (string $tree, array $web, string $tenant = 'uganda', bool $likeCrm = false) use ($world, $form, $events, $whlog, $raced): array {
    [, $dir] = $world($tree, $web, $tenant, $likeCrm, true);
    $P = '+256 700 000 7' . random_int(10, 99);
    $r = $form($tree, $dir, $P, 'Race');
    return ['flash' => (string)($r['flash']['type'] ?? ''), 'out' => substr((string)($r['_out'] ?? ''), -300),
            'events' => $events($dir, $P), 'race' => $raced(), 'marked' => strpos($whlog($dir), MARKED) !== false];
};
/** The retry job creates a pending application's customer, the race forced. */
$retryRace = function (string $tree, array $web) use ($world, $retry, $events, $whlog, $raced): array {
    [$s, $dir] = $world($tree, $web, 'uganda', false, true);
    $P = '+256 700 000 790';
    $s->save('kyc_applications.json', [['id' => 40, 'firstname' => 'Test', 'lastname' => 'Retried', 'mobile' => $P,
        'customer_type' => 'StarLink', 'connectivity_type' => 'New Connection', 'address_1' => 'Test address',
        'package_choice' => '1', 'offer_name' => 'Residential Standard (TEST)', 'offer_price' => 100,
        'sales_type' => 'Credit', 'is_lead' => true, 'username' => 'STAR0000040', 'status' => 'new',
        'crm_client_id' => null, 'crm_sync_status' => 'pending', 'created_at' => date('Y-m-d H:i:s'),
        'quote_items' => [['label' => 'Residential Standard (TEST)', 'quantity' => 1, 'price' => 100.0, 'unit' => 'month']]]]);
    $out = $retry($tree, $dir);
    $app = array_values(array_filter($s->load('kyc_applications.json') ?? [], function ($a) { return (int)($a['id'] ?? 0) === 40; }))[0] ?? [];
    return ['created' => (string)($app['crm_client_id'] ?? ''), 'out' => substr($out, -300), 'events' => $events($dir, $P),
            'race' => $raced(), 'marked' => strpos($whlog($dir), MARKED) !== false];
};

// ══════════════════════════════════════════════════════════════════════════════
$web = $plugin($root, 'real');
if ($web[0] === 0) { is_(false, 'the plugin started under php -S'); echo "\n{$pass} passed, {$fail} failed\n"; exit(1); }

echo "\n1. Uganda — the race forced: uCRM's client.add reaches the webhook before the form has saved its application\n";
$a = $formRace($root, $web);
is_($a['flash'] === 'success', 'the form creates the customer', $a['out']);
is_($a['race'] === [200], 'client.add was delivered, and answered, while uCRM\'s create was still waiting (the race happened)', json_encode($a['race']));
is_($a['events'] === ['ops_kyc_customer_welcome'], 'one message: the form\'s "Request Confirmed!" — no welcome beside it', json_encode($a['events']));
is_($a['marked'], 'the webhook log says the form\'s mark decided it');

echo "\n2. Uganda — no race: the form first, then client.add (control)\n";
[, $dir] = $world($root, $web, 'uganda', false, false);
$P = '+256 700 000 702';
$form($root, $dir, $P, 'Plain');
$fire($web[0], 'client.add', 901);
is_($events($dir, $P) === ['ops_kyc_customer_welcome'], 'one message, as always', json_encode($events($dir, $P)));
is_(strpos($whlog($dir), 'welcome already sent by plugin') !== false && strpos($whlog($dir), MARKED) === false,
    'found by its application, not by the mark');

echo "\n3. Uganda with kyc_messages_like_crm — the race forced: one message, the welcome, as the switch intends\n";
$c = $formRace($root, $web, 'uganda', true);
is_($c['race'] === [200] && $c['events'] === ['event_client_add'], 'the welcome only; the form sends none', json_encode($c));

echo "\n4. Uganda — the retry job, the race forced: no welcome\n";
$d = $retryRace($root, $web);
is_($d['created'] !== '' && $d['race'] === [200], 'the retry creates the customer, and client.add overtakes it', json_encode($d));
is_($d['events'] === [], 'no welcome: the form\'s message went when the form ran', json_encode($d['events']));
is_($d['marked'], 'decided by the retry job\'s mark');

echo "\n5. South Sudan — the race forced: both, as in 5.18.53 (docs/46 §E)\n";
$e = $formRace($root, $web, 'south-sudan');
is_($e['race'] === [200] && count($e['events']) === 2 && in_array('event_client_add', $e['events'], true)
    && in_array('ops_kyc_customer_welcome', $e['events'], true), 'the welcome and "Request Confirmed!", as before', json_encode($e));

// ══════════════════════════════════════════════════════════════════════════════
echo "\n6. Weakened copies, each caught\n";
// Each predicate names the defect itself, with a control that the race really ran, so a copy that merely crashed is
// never counted as caught.
$mutants = [
    'the form writes no mark' => ['lib/KycService.php',
        ["            self::markSignup(\$this->store, is_array(\$cfg ?? null) ? \$cfg : [], \$this->dataDir, \$username);   // D8, below\n", ''],
        'form', function (array $x) { return $x['race'] === [200] && in_array('event_client_add', $x['events'], true); }, 'the welcome went beside "Request Confirmed!"'],
    'the webhook ignores the mark' => ['webhook.php',
        ["                          && \$notify->dedupCheck('KYCNEW:' . trim((string)\$client['username']));", "                          && false;"],
        'form', function (array $x) { return $x['race'] === [200] && in_array('event_client_add', $x['events'], true); }, 'the welcome went beside "Request Confirmed!"'],
    'the retry job writes no mark' => ['lib/KycCrmSync.php',
        ["            KycService::markSignup(\$this->store, \$this->config,\n                method_exists(\$this->store, 'getDataDir') ? \$this->store->getDataDir() : null, \$username);\n", ''],
        'retry', function (array $x) { return $x['race'] === [200] && $x['events'] === ['event_client_add']; }, 'a welcome after the form\'s message'],
];
foreach ($withMutants ? $mutants : [] as $name => [$rel, [$old, $new], $case, $caught, $why]) {
    [$tree, $n] = sj_weakened_copy($root, $rel, $old, $new);
    if ($n !== 1) { is_(false, "caught: {$name}", "the anchor was not found exactly once in {$rel}"); exec('rm -rf ' . escapeshellarg($tree)); continue; }
    $wweb = $plugin($tree, 'wk' . md5($name));
    $x = $case === 'form' ? $formRace($tree, $wweb) : $retryRace($tree, $wweb);
    $ok = (bool)$caught($x);
    is_($ok, "caught: {$name}" . ($ok ? " ({$why})" : ''), (string)json_encode($x, JSON_INVALID_UTF8_SUBSTITUTE));
    exec('rm -rf ' . escapeshellarg($tree));
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
