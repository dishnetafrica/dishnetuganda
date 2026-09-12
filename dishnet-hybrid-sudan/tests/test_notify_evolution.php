<?php
declare(strict_types=1);
/**
 * test_notify_evolution.php — the 169 call sites actually reach a phone.
 *
 * NotificationService is the single choke point for almost every outbound
 * message in this plugin: OTP logins, invoice notices, lead alerts, cash
 * declarations, technician dispatch. It opened with
 *
 *     if (!$this->enabled || empty($toPhone)) return;
 *
 * where `enabled` means WASender alone — wa_plugin_url AND wa_app_key AND
 * wa_auth_key. The Uganda install runs Evolution and sets none of them, so
 * every one of those sends returned at that line having done nothing. No
 * message, no exception, no log row, no queue entry. The doctor built for
 * this found it stated plainly: "everything else — NOTHING IS SENT, silently".
 *
 * These tests run against a fake Evolution server, so "it sent" means a
 * request actually arrived — not that a mock was satisfied.
 */
$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; } }

$root = dirname(__DIR__);
foreach (['StoreInterface','JsonStore','SqliteStore','currency','CustomerContact',
          'ContactOptOut','EvolutionApiService','EvoWebhookGuard','NotificationService'] as $c) {
    require_once $root . '/lib/' . $c . '.php';
}

// ── a fake Evolution, so a send is observable ──────────────────────────
$router = $root . '/tests/fixtures/fake_evo_server.php';
$hit = function (int $port, string $p) {
    $ch = curl_init("http://127.0.0.1:{$port}{$p}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2, CURLOPT_PROXY => '']);
    $r = curl_exec($ch); curl_close($ch);
    return $r === false ? null : (string)$r;
};
$srv = null; $port = 0;
foreach (range(0, 9) as $slot) {
    $cand = 9820 + ((getmypid() + $slot * 17) % 70);
    $p = proc_open(sprintf('exec php -S 127.0.0.1:%d %s', $cand, escapeshellarg($router)),
                   [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    $ours = false;
    for ($i = 0; $i < 40; $i++) {
        $got = $hit($cand, '/__test/state');
        if ($got !== null) { $ours = strpos($got, 'FAKE-EVO-TEST') !== false; break; }
        usleep(100000);
    }
    if ($ours) { $srv = $p; $port = $cand; break; }
    proc_terminate($p); proc_close($p);
}
if ($port === 0) { fwrite(STDERR, "could not start the fake Evolution server\n"); exit(1); }
$state = function () use ($hit, $port) { return json_decode((string)$hit($port, '/__test/state'), true) ?: []; };
register_shutdown_function(function () use (&$srv) {
    if ($srv) { @proc_terminate($srv); @proc_close($srv); }
});

$mkStore = function () {
    $d = sys_get_temp_dir() . '/dn_notify_' . bin2hex(random_bytes(4));
    @mkdir($d, 0700, true);
    return [SqliteStore::create($d), $d];
};
$evoCfg = [
    'evo_api_url'          => "http://127.0.0.1:{$port}",
    'evo_api_key'          => 'test-key',
    'evo_instance_support' => 'ug-support',
    'evo_instance_account' => 'ug-account',
];

echo "\nAn Evolution-only install sends — it used to return in silence\n";
[$store, $dir] = $mkStore();
$before = count($state()['text_calls'] ?? []);
$n = new NotificationService($store, $evoCfg);
$n->sendRaw('256700000001', 'dispatch test', 'ops_test');
$after = $state()['text_calls'] ?? [];
is_(count($after) === $before + 1, 'sendRaw reached the Evolution server',
    'sent count ' . $before . ' → ' . count($after));
$last = $after ? $after[count($after)-1] : [];
is_(strpos(json_encode($last), 'dispatch test') !== false, 'carrying the message body');
is_(strpos(json_encode($last), '256700000001') !== false, 'to the number given');

echo "\nThe sender channel decides the instance\n";
$before = count($state()['text_calls'] ?? []);
$n->sendVia(NotificationService::ACCOUNTS, '256700000002', 'accounts test', 'billing_test');
$sent = $state()['text_calls'] ?? [];
is_(count($sent) === $before + 1, 'an accounts-channel send also goes out');
$acc = $sent ? $sent[count($sent)-1] : [];
is_(strpos(json_encode($acc), 'ug-account') !== false,
    'on the ACCOUNT instance, not support', json_encode($acc));

echo "\nNo Evolution and no WASender: still silent, but now deliberately\n";
[$store2, $dir2] = $mkStore();
$before = count($state()['text_calls'] ?? []);
$n2 = new NotificationService($store2, []);
$n2->sendRaw('256700000003', 'should not send', 'ops_test');
is_(count($state()['text_calls'] ?? []) === $before, 'nothing was sent');
is_(true, 'and nothing threw — an unconfigured install must not fatal');

echo "\nA channel with no instance falls back rather than erroring\n";
[$store3, $dir3] = $mkStore();
$before = count($state()['text_calls'] ?? []);
$n3 = new NotificationService($store3, [
    'evo_api_url' => "http://127.0.0.1:{$port}", 'evo_api_key' => 'k',
    'evo_instance_sales' => 'ug-sales',       // support/account deliberately absent
]);
$n3->sendRaw('256700000004', 'no support instance', 'ops_test');
is_(count($state()['text_calls'] ?? []) === $before,
    'a support send with no support instance does not go out on sales',
    'it must never silently use a different number');

echo "\nwa_force_accounts routes the channel, not just the app key\n";
[$store4, $dir4] = $mkStore();
$before = count($state()['text_calls'] ?? []);
$n4 = new NotificationService($store4, $evoCfg + ['wa_force_accounts' => true]);
$n4->sendRaw('256700000005', 'forced', 'ops_test');
$forced = $state()['text_calls'] ?? [];
$f = $forced ? $forced[count($forced)-1] : [];
is_(count($forced) === $before + 1, 'the send still goes out');
is_(strpos(json_encode($f), 'ug-account') !== false,
    'and a SUPPORT send is routed to the accounts instance', json_encode($f));

echo "\nThe other senders were not touched\n";
$src = file_get_contents($root . '/lib/NotificationService.php');
is_(substr_count($src, 'sendViaEvolution(') === 2,
    'sendViaEvolution is defined once and called once — sendDocument and '
  . 'sendImage still take the WASender path', substr_count($src, 'sendViaEvolution(') . ' occurrence(s)');
is_(strpos($src, 'if (!$this->enabled && !$this->evoAvailable($sender)) return;') !== false,
    'the guard consults both transports before giving up');

foreach ([$dir, $dir2, $dir3, $dir4] as $d) { @array_map('unlink', glob($d . '/*') ?: []); @rmdir($d); }
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
