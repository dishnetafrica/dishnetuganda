<?php
declare(strict_types=1);
/**
 * test_webhook_registrar.php — the Setup Webhook button repairs, never rewrites.
 *
 * Three places registered the plugin's uCRM webhook endpoint, each with its
 * own address and its own fixed event list. The Settings button's list had
 * eight events and no quote.add: on the live endpoint, which delivers every
 * event, one click would have narrowed it and quotations would have stopped,
 * silently. It also replaced the address with the public one from ucrm.json,
 * which uCRM calls from inside its container and may not reach, and it
 * generated webhook_secret — the customer app's JWT key — as a side line.
 *
 * lib/WebhookRegistrar.php is now the one policy for the button, the
 * webhook_register debug action and tools/webhook_setup.php. This file proves
 * it three ways: plan() decided in isolation against an injected reachability
 * answer; the real CrmApiClient against a fake uCRM over HTTP, whose request
 * log shows what was written and, more to the point, what was not; and the
 * button's own handler booted under PHP's built-in server.
 */
$pass = 0; $fail = 0;
function t(string $n, $got, $want): void { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d !== '' ? "\n       $d" : '') . "\n"; } }
function codeNC(string $f): string {
    $o = '';
    foreach (token_get_all((string)file_get_contents($f)) as $k) {
        if (is_array($k)) { if (in_array($k[0], [T_COMMENT, T_DOC_COMMENT], true)) continue; $o .= $k[1]; }
        else $o .= $k;
    }
    return $o;
}

$root = dirname(__DIR__);
$name = basename($root);
if (!getenv('DN_VAULT_FILE')) putenv('DN_VAULT_FILE=' . tempnam(sys_get_temp_dir(), 'dn-vault-'));
require_once $root . '/lib/CrmApiClient.php';
require_once $root . '/lib/WebhookRegistrar.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';

$tmp = sys_get_temp_dir() . '/whreg_' . getmypid();
exec('rm -rf ' . escapeshellarg($tmp)); @mkdir($tmp, 0777, true);
register_shutdown_function(function () use ($tmp) { exec('rm -rf ' . escapeshellarg($tmp)); });

// ── the fake uCRM ────────────────────────────────────────────────────────────
array_map('unlink', glob(sys_get_temp_dir() . '/fake_ucrm_webhooks_*.json') ?: []);
$http = function (string $method, string $url, $json = null): array {
    $ch = curl_init($url);
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_CUSTOMREQUEST => $method,
          CURLOPT_HTTPHEADER => ['Content-Type: application/json']];
    if ($json !== null) $o[CURLOPT_POSTFIELDS] = is_string($json) ? $json : json_encode($json);
    curl_setopt_array($ch, $o);
    $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['code' => $r === false ? 0 : $code, 'json' => is_string($r) ? json_decode($r, true) : null, 'raw' => is_string($r) ? $r : ''];
};
$fixture = $root . '/tests/fixtures/fake_ucrm_webhooks.php';
$srv = null; $port = 0;
foreach (range(0, 9) as $slot) {
    $cand = 9600 + ((getmypid() + $slot * 13) % 80);
    $p = proc_open(sprintf('exec php -S 127.0.0.1:%d %s', $cand, escapeshellarg($fixture)),
                   [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    $ours = false;
    for ($i = 0; $i < 40; $i++) {
        $got = $http('GET', "http://127.0.0.1:{$cand}/__test/state");
        if ($got['code'] !== 0) { $ours = ($got['json']['marker'] ?? '') === 'FAKE-UCRM-WEBHOOKS'; break; }
        usleep(100000);
    }
    if ($ours) { $srv = $p; $port = $cand; break; }
    if (is_resource($p)) { proc_terminate($p); proc_close($p); }
}
if ($srv === null) { echo "  FAIL could not start the fake uCRM\n"; exit(1); }
register_shutdown_function(function () use (&$srv) { if (is_resource($srv)) { proc_terminate($srv); proc_close($srv); } });

$host     = "http://127.0.0.1:{$port}";
$api      = "{$host}/api/v2.1";
$liveBase = "{$host}/_plugins/{$name}";
$deadBase = "{$host}/dead/_plugins/{$name}";
$live     = $liveBase . '/' . WebhookRegistrar::ROUTE;
$dead     = $deadBase . '/' . WebhookRegistrar::ROUTE;
$cfgLive  = ['plugin_public_url' => $liveBase];
$cfgDead  = ['plugin_public_url' => $deadBase];
$seed     = function (array $endpoints, string $scenario = 'default') use ($http, $host): void {
    $http('POST', "{$host}/__test/seed", ['endpoints' => $endpoints, 'scenario' => $scenario]);
};
$state    = function () use ($http, $host): array { return $http('GET', "{$host}/__test/state")['json'] ?? []; };
$requests = function () use ($http, $host): array { return $http('GET', "{$host}/__test/requests")['json']['requests'] ?? []; };
$writes   = function (array $reqs): array {
    return array_values(array_filter($reqs, function ($r) {
        return in_array($r['method'], ['POST', 'PATCH', 'DELETE'], true) && strpos($r['path'], '/webhooks/endpoints') !== false;
    }));
};
$oldButtonList = ['client.add', 'client.edit', 'invoice.add', 'payment.add', 'service.add', 'service.suspend', 'service.suspend_cancel', 'service.end'];
$crm = new CrmApiClient($api, 'test-key');

// ═════════════════════════════════════════════════
echo "\n1. Decisions, in isolation\n";
// ═════════════════════════════════════════════════
$reach = function (array $map) { return function (string $url, bool $verify) use ($map): bool { return (bool)($map[$url] ?? false); }; };
t('candidates: the public base first, then the plugin under the API root', WebhookRegistrar::candidates($cfgDead, $name, $api), [$dead, $live]);
t('…deduplicated when they coincide',                          WebhookRegistrar::candidates($cfgLive, $name, $api), [$live]);
t('…and none when nothing is known',                          WebhookRegistrar::candidates([], $name, ''), []);
t('routesToPlugin: page=crm_webhook',                          WebhookRegistrar::routesToPlugin($live, $name), true);
t('routesToPlugin: page=webhook (also routed)',                WebhookRegistrar::routesToPlugin($liveBase . '/' . WebhookRegistrar::ROUTE_LEGACY, $name), true);
t('routesToPlugin: another page of ours is not the webhook',   WebhookRegistrar::routesToPlugin($liveBase . '/public.php?page=api', $name), false);
t('routesToPlugin: webhook.php by its own path is not served by uCRM', WebhookRegistrar::routesToPlugin($liveBase . '/webhook.php', $name), false);
t('routesToPlugin: another plugin',                            WebhookRegistrar::routesToPlugin("{$host}/_plugins/other/public.php?page=crm_webhook", $name), false);
t('missingEvents: any event → nothing missing',                WebhookRegistrar::missingEvents(['anyEvent' => true, 'eventTypes' => []]), []);
t('missingEvents: an empty list is uCRM\'s any event',          WebhookRegistrar::missingEvents(['eventTypes' => []]), []);
$miss = WebhookRegistrar::missingEvents(['anyEvent' => false, 'eventTypes' => $oldButtonList]);
is_(in_array('quote.add', $miss, true), 'missingEvents: the old button\'s eight-event list omits quote.add', implode(',', $miss));
t('…and 17 events in all',                                     count($miss), count(WebhookRegistrar::REQUIRED_EVENTS) - 8);
t('missingEvents: the full required list → nothing missing',   WebhookRegistrar::missingEvents(['eventTypes' => WebhookRegistrar::REQUIRED_EVENTS]), []);

$p = WebhookRegistrar::plan([], $cfgLive, $name, $reach([$live => true]), $api);
t('no endpoint, plugin reachable → create at the reachable address', [$p['action'], $p['url'], $p['changes'], $p['verify_ssl']], ['create', $live, [], true]);
$p = WebhookRegistrar::plan([], $cfgDead, $name, $reach([$live => true]), $api);
t('no endpoint, public base dead → create at the API-root address instead', [$p['action'], $p['url']], ['create', $live]);
$p = WebhookRegistrar::plan([], $cfgDead, $name, $reach([]), $api);
t('no endpoint, nothing reachable → unreachable, no changes',  [$p['action'], $p['changes']], ['unreachable', []]);
$good = ['id' => 4, 'url' => $live, 'isActive' => true, 'anyEvent' => true, 'eventTypes' => []];
$p = WebhookRegistrar::plan([$good], $cfgLive, $name, $reach([$live => true]), $api);
t('ours, reachable, active, any event → keep, nothing changes', [$p['action'], $p['changes'], $p['url']], ['keep', [], $live]);
t('…and only the registered address was probed',               array_keys($p['probed']), [$live]);
$odd = ['id' => 5, 'url' => "{$liveBase}/public.php?page=webhook", 'isActive' => true, 'anyEvent' => true, 'eventTypes' => []];
$p = WebhookRegistrar::plan([$odd], $cfgLive, $name, $reach([$odd['url'] => true]), $api);
t('a reachable address is kept even when it is not the one we would register', [$p['action'], $p['url']], ['keep', $odd['url']]);
$narrow = $good + []; $narrow['anyEvent'] = false; $narrow['eventTypes'] = $oldButtonList;
$p = WebhookRegistrar::plan([$narrow], $cfgLive, $name, $reach([$live => true]), $api);
t('a narrowed list → widen; the address is left alone',        [$p['action'], array_keys($p['changes']), $p['url']], ['update', ['events'], $live]);
is_(in_array('quote.add', $p['missing_events'], true), 'and the plan names quote.add among the missing');
$idle = $good; $idle['isActive'] = false;
$p = WebhookRegistrar::plan([$idle], $cfgLive, $name, $reach([$live => true]), $api);
t('inactive → activate, nothing else',                          $p['changes'], ['isActive' => true]);
$lost = $good; $lost['url'] = $dead;
$p = WebhookRegistrar::plan([$lost], $cfgLive, $name, $reach([$live => true]), $api);
t('an unreachable address → replaced by one that is reached',  [$p['action'], $p['changes']], ['update', ['url' => $live]]);
$p = WebhookRegistrar::plan([$lost], $cfgDead, $name, $reach([]), $api);
t('unreachable and no alternative → left exactly as it is',     [$p['action'], $p['changes'], $p['url']], ['unreachable', [], $dead]);
$p = WebhookRegistrar::plan([$idle, ['id' => 9, 'url' => $live, 'isActive' => true, 'eventTypes' => []]], $cfgLive, $name, $reach([$live => true]), $api);
t('two of ours → the active one is the endpoint',               [$p['endpoint']['id'], $p['ours_count'], $p['action']], [9, 2, 'keep']);
$p = WebhookRegistrar::plan([['id' => 1, 'url' => "{$host}/_plugins/other/public.php?page=webhook", 'isActive' => true]], $cfgLive, $name, $reach([$live => true]), $api);
t('another plugin\'s endpoint is not ours → create ours',       [$p['action'], $p['ours_count']], ['create', 0]);
$p = WebhookRegistrar::plan([], $cfgLive, $name, function (string $u, bool $verify): bool { return !$verify; }, $api);
t('reached only without certificate verification → registered that way', [$p['action'], $p['verify_ssl']], ['create', false]);

// ═════════════════════════════════════════════════
echo "\n2. Against a uCRM, for real: what is written, and what is not\n";
// ═════════════════════════════════════════════════
$seed([]);
$r = WebhookRegistrar::run($crm, $cfgLive, $name);
t('empty uCRM → created',                                       [$r['success'], $r['action'], $r['webhook_id']], [true, 'create', 1]);
$st = $state();
t('…listed back: our address, active, every event, certificate verified',
  [count($st['endpoints']), $st['endpoints'][0]['url'], $st['endpoints'][0]['isActive'], $st['endpoints'][0]['anyEvent'], $st['endpoints'][0]['eventTypes'], $st['endpoints'][0]['verifySslCertificate']],
  [1, $live, true, true, [], true]);
$w = $writes($requests());
t('…with exactly one write',                                    count($w), 1);
is_(!array_key_exists('eventTypes', (array)($w[0]['body'] ?? [])) && !array_key_exists('anyEvent', (array)($w[0]['body'] ?? [])), 'whose body names no event list at all (uCRM\'s any event)', json_encode($w[0]['body'] ?? null));
$before = count($requests());
$r = WebhookRegistrar::run($crm, $cfgLive, $name);
t('run again → keep',                                           [$r['success'], $r['action']], [true, 'keep']);
t('…and NOT ONE write reached uCRM',                             count($writes(array_slice($requests(), $before))), 0);
is_(strpos($r['message'], 'nothing changed') !== false,        'and it says so: ' . $r['message']);

$seed([['url' => $live, 'isActive' => true, 'anyEvent' => false, 'eventTypes' => $oldButtonList]]);
$r = WebhookRegistrar::run($crm, $cfgLive, $name);
$st = $state(); $w = $writes($requests());
t('THE POINT — the old button\'s narrowed list is widened to every event', [$r['success'], $r['action'], $st['endpoints'][0]['anyEvent'], $st['endpoints'][0]['eventTypes']], [true, 'update', true, []]);
t('…by one PATCH asking for any event',                         [count($w), $w[0]['method'] ?? null, $w[0]['body'] ?? null], [1, 'PATCH', ['anyEvent' => true]]);
t('…the address untouched',                                     $st['endpoints'][0]['url'], $live);

$seed([['url' => $live, 'isActive' => true, 'anyEvent' => false, 'eventTypes' => $oldButtonList]], 'no_anyevent');
$r = WebhookRegistrar::run($crm, $cfgLive, $name);
$st = $state(); $w = $writes($requests());
t('a uCRM without anyEvent refuses that PATCH → the missing names are added instead', [$r['success'], count($w), $w[0]['body'] ?? null, array_keys((array)($w[1]['body'] ?? []))], [true, 2, ['anyEvent' => true], ['eventTypes']]);
is_(array_diff(WebhookRegistrar::REQUIRED_EVENTS, $st['endpoints'][0]['eventTypes']) === [], 'every required event is now delivered');
is_(array_diff($oldButtonList, $st['endpoints'][0]['eventTypes']) === [],                      'and nothing that was delivered before was removed');

$seed([['url' => $live, 'isActive' => false, 'anyEvent' => true]]);
$r = WebhookRegistrar::run($crm, $cfgLive, $name);
$st = $state(); $w = $writes($requests());
t('inactive → activated by one PATCH, nothing else in it',      [$r['success'], $st['endpoints'][0]['isActive'], count($w), $w[0]['body'] ?? null], [true, true, 1, ['isActive' => true]]);

$seed([['url' => $dead, 'isActive' => true, 'anyEvent' => true, 'verifySslCertificate' => false]]);
$r = WebhookRegistrar::run($crm, $cfgLive, $name);
$st = $state(); $w = $writes($requests());
t('an address uCRM cannot reach → replaced with one it can, certificate setting per its probe',
  [$r['success'], $st['endpoints'][0]['url'], count($w), $w[0]['body'] ?? null], [true, $live, 1, ['url' => $live, 'verifySslCertificate' => true]]);

$foreign = ['url' => "{$host}/_plugins/other-plugin/public.php?page=webhook", 'isActive' => true, 'anyEvent' => false, 'eventTypes' => ['client.add']];
$seed([$foreign]);
$r = WebhookRegistrar::run($crm, $cfgLive, $name);
$st = $state();
t('another plugin\'s endpoint is left alone and ours is created beside it',
  [$r['action'], count($st['endpoints']), $st['endpoints'][0]['url'], $st['endpoints'][0]['eventTypes'], $st['endpoints'][1]['url']],
  ['create', 2, $foreign['url'], ['client.add'], $live]);

$seed([]);
$deadCrm = new CrmApiClient("{$host}/dead/api/v2.1", 'test-key');
$r = WebhookRegistrar::run($deadCrm, $cfgDead, $name);
t('nothing reachable anywhere → no success, no write',          [$r['success'], $r['action'], count($writes($requests()))], [false, 'unreachable', 0]);

// ═════════════════════════════════════════════════
echo "\n3. The Settings button itself, under PHP's built-in server\n";
// ═════════════════════════════════════════════════
$bdir = $tmp . '/button'; @mkdir($bdir, 0777, true);
SqliteStore::create($bdir)->save('kyc_config.json', ['plugin_public_url' => $liveBase, 'crm_base_url' => $api, 'crm_auth_token' => 'test-key']);
$router = $tmp . '/button_router.php';
file_put_contents($router, '<?php
ob_start();
$__root = ' . var_export($root, true) . ';
require_once $__root . "/lib/StoreInterface.php"; require_once $__root . "/lib/SqliteStore.php"; require_once $__root . "/lib/CrmApiClient.php";
$dataDir = ' . var_export($bdir, true) . ';
$store   = SqliteStore::create($dataDir);
$config  = $store->load("kyc_config.json");
$crm     = new CrmApiClient(' . var_export($api, true) . ', "test-key");
$isAdmin = true; $isAdminDebug = true; $currentUser = ["username" => "tester"]; $retailer = ["name" => "Tester"];
$act = $_GET["action"] ?? ""; $met = $_SERVER["REQUEST_METHOD"]; $body = [];
$ok2 = function($d,$m="OK",$c=200){ while (ob_get_level() > 0) ob_end_clean(); http_response_code($c); echo json_encode(["status"=>"success","message"=>$m,"data"=>$d]); exit; };
$er2 = function($m,$c=400){ while (ob_get_level() > 0) ob_end_clean(); http_response_code($c); echo json_encode(["status"=>"error","message"=>$m]); exit; };
if ($act === "ping") { while (ob_get_level() > 0) ob_end_clean(); echo "BUTTON-TEST-SERVER"; exit; }
require $__root . "/includes/api/api_notifications.php";
while (ob_get_level() > 0) ob_end_clean(); http_response_code(404); echo "unhandled";
');
$bsrv = null; $bport = 0;
foreach (range(0, 9) as $slot) {
    $cand = 9700 + ((getmypid() + $slot * 7) % 80);
    $p = proc_open(sprintf('exec php -S 127.0.0.1:%d %s', $cand, escapeshellarg($router)),
                   [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    $ours = false;
    for ($i = 0; $i < 40; $i++) {
        $got = $http('GET', "http://127.0.0.1:{$cand}/?page=api&action=ping");
        if ($got['code'] !== 0) { $ours = strpos($got['raw'], 'BUTTON-TEST-SERVER') !== false; break; }
        usleep(100000);
    }
    if ($ours) { $bsrv = $p; $bport = $cand; break; }
    if (is_resource($p)) { proc_terminate($p); proc_close($p); }
}
if ($bsrv === null) { echo "  FAIL could not start the button server\n"; $fail++; }
else {
    register_shutdown_function(function () use (&$bsrv) { if (is_resource($bsrv)) { proc_terminate($bsrv); proc_close($bsrv); } });
    $seed([]);
    $r = $http('POST', "http://127.0.0.1:{$bport}/?page=api&action=webhook_setup", '{}');
    t('the button answers success',                              [$r['code'], $r['json']['status'] ?? null, $r['json']['data']['action'] ?? null], [200, 'success', 'create']);
    $st = $state();
    t('…and uCRM now has our endpoint, for every event',         [count($st['endpoints']), $st['endpoints'][0]['url'], $st['endpoints'][0]['anyEvent']], [1, $live, true]);
    $cfgAfter = SqliteStore::create($bdir)->load('kyc_config.json');
    t('…webhook_secret was NOT generated — the customer app\'s JWT key is untouched', array_key_exists('webhook_secret', $cfgAfter), false);
    t('…what is registered is remembered for display',           [$cfgAfter['webhook_id'] ?? null, $cfgAfter['webhook_url'] ?? null, $cfgAfter['webhook_auto_setup_done'] ?? null], [1, $live, true]);
    $before = count($requests());
    $r = $http('POST', "http://127.0.0.1:{$bport}/?page=api&action=webhook_setup", '{}');
    t('pressing it again changes nothing',                       [$r['json']['data']['action'] ?? null, count($writes(array_slice($requests(), $before)))], ['keep', 0]);
    $seed([['url' => $live, 'isActive' => true, 'anyEvent' => false, 'eventTypes' => $oldButtonList]]);
    $r = $http('POST', "http://127.0.0.1:{$bport}/?page=api&action=webhook_setup", '{}');
    $st = $state();
    t('on a narrowed endpoint the button widens, and never narrows', [$r['json']['data']['action'] ?? null, $st['endpoints'][0]['anyEvent']], ['update', true]);
}

// ═════════════════════════════════════════════════
echo "\n4. One policy — nothing else registers, narrows, or generates\n";
// ═════════════════════════════════════════════════
$crmSrc = codeNC($root . '/lib/CrmApiClient.php');
is_(strpos($crmSrc, 'function autoSetupWebhook') === false,       'CrmApiClient::autoSetupWebhook is gone');
is_(strpos($crmSrc, "'service.suspend_cancel'") === false,        'and its fixed eight-event list with it');
$api1 = codeNC($root . '/includes/api/api_notifications.php');
$blk  = substr($api1, strpos($api1, "if (\$act === 'webhook_setup'"), strpos($api1, "if (\$act === 'webhook_status'") - strpos($api1, "if (\$act === 'webhook_setup'"));
is_(strpos($blk, 'WebhookRegistrar::run(') !== false,             'the button runs the registrar');
is_(strpos($blk, 'random_bytes') === false && strpos($blk, "webhook_secret") === false, 'and neither generates nor mentions webhook_secret');
$api2 = codeNC($root . '/includes/api/api_cron_debug.php');
$blk2 = substr($api2, strpos($api2, "if (\$act === 'webhook_register')"), strpos($api2, "if (\$act === 'retry_quote')") - strpos($api2, "if (\$act === 'webhook_register')"));
is_(strpos($blk2, 'WebhookRegistrar::run(') !== false && strpos($blk2, 'createWebhook(') === false, 'the webhook_register debug action runs the registrar, not its own list');
$set = codeNC($root . '/tabs/admin/settings.php');
is_(strpos($set, 'WebhookRegistrar::routesToPlugin(') !== false && strpos($set, 'WebhookRegistrar::missingEvents(') !== false, 'the Settings tab judges route and events through the registrar');
$setRaw = (string)file_get_contents($root . '/tabs/admin/settings.php');
is_(strpos($setRaw, 'to generate one') === false && strpos($setRaw, 'auto-generated on first setup') === false, 'and no longer tells anyone to click the button for a secret');
is_(strpos($set, ". '?page=webhook'") === false,                   'nor calls a working crm_webhook address wrong');
$tool = codeNC($root . '/tools/webhook_setup.php');
is_(strpos($tool, 'WebhookRegistrar::plan(') !== false && strpos($tool, 'WebhookRegistrar::apply(') !== false && strpos($tool, '$working') === false, 'tools/webhook_setup.php decides and acts through the registrar');
$wh = codeNC($root . '/webhook.php');
$unhandled = array_values(array_filter(WebhookRegistrar::REQUIRED_EVENTS, function ($e) use ($wh) { return strpos($wh, "case '{$e}'") === false; }));
t('every required event has a case in webhook.php',              $unhandled, []);
$others = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $fi) {
    $p = $fi->getPathname();
    if (substr($p, -4) !== '.php' || strpos($p, '/tests/') !== false || strpos($p, '/.git/') !== false) continue;
    if (in_array(basename($p), ['CrmApiClient.php', 'WebhookRegistrar.php'], true)) continue;
    if (strpos(codeNC($p), '->createWebhook(') !== false || strpos(codeNC($p), '->updateWebhook(') !== false) $others[] = substr($p, strlen($root) + 1);
}
t('no other file creates or updates an endpoint on its own',      $others, []);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
