<?php
declare(strict_types=1);
/**
 * The WhatsApp webhook guard: silent while the webhook is healthy,
 * re-registers it the moment Evolution loses it, and is scheduled in
 * master.php — the durable answer to "the AI stopped replying".
 */
$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }

$root = dirname(__DIR__);

// ── Boot the fake Evolution server ──────────────────────────────────────────
array_map('unlink', glob(sys_get_temp_dir() . '/fake_evo_state_*.json') ?: []);
$router = $root . '/tests/fixtures/fake_evo_server.php';
$hit = function (int $port, string $p) {
    $ch = curl_init("http://127.0.0.1:{$port}{$p}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2, CURLOPT_PROXY => '']);
    $r = curl_exec($ch); curl_close($ch);
    return $r === false ? null : (string)$r;
};
$srv = null; $port = 0;
foreach (range(0, 9) as $slot) {
    $cand = 9510 + ((getmypid() + $slot * 23) % 80);
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

// ── A configured install ────────────────────────────────────────────────────
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';
$tmp = sys_get_temp_dir() . '/wa_guard_test_' . getmypid();
@mkdir($tmp, 0777, true);
$st = SqliteStore::create($tmp);
$secret = str_repeat('s3cr3t01', 5);   // 40 chars
file_put_contents($tmp . '/kyc_config.json', json_encode([
    'evo_api_url'        => "http://127.0.0.1:{$port}",
    'evo_api_key'        => 'TESTKEY',
    'evo_instance_sales' => 'dishnet_ug',
    'plugin_public_url'  => 'https://crm.test.example/crm/system/plugins/7',
    'evo_webhook_secret' => $secret,
    'whatsapp_admin_phone' => '256700000009',
]));

$run = function () use ($root, $tmp): string {
    $cmd = 'DN_DATA_DIR=' . escapeshellarg($tmp) . ' php ' . escapeshellarg($root . '/cron/wa_webhook_guard.php') . ' 2>&1';
    return (string)shell_exec($cmd);
};
$evoState = fn() => json_decode((string)$hit($port, '/__test/state'), true) ?: [];

echo "First run — the webhook is missing, the guard restores it\n";
$out = $run();
t('guard noticed and re-registered', strpos($out, 're-registering') !== false, true);
t('and verified the restore', strpos($out, 'restored and verified') !== false, true);
$s = $evoState();
t('Evolution now holds our public.php route', strpos((string)($s['webhooks']['dishnet_ug']['url'] ?? ''), 'page=evo_webhook') !== false, true);
t('with the current token', strpos((string)($s['webhooks']['dishnet_ug']['url'] ?? ''), $secret) !== false, true);
t('exactly one set call so far', (int)($s['set_calls'] ?? 0), 1);

echo "\nSecond run — healthy webhook, the guard stays silent\n";
$out = $run();
t('no re-registration chatter', strpos($out, 're-registering'), false);
t('no extra set call', (int)($evoState()['set_calls'] ?? 0), 1);

echo "\nEvolution loses the webhook again — the guard heals it again\n";
$hit($port, '/__test/clear_webhook');
$out = $run();
t('healed on the next pass', strpos($out, 'restored and verified') !== false, true);
t('set calls now 2', (int)($evoState()['set_calls'] ?? 0), 2);

echo "\nScheduling\n";
$master = (string)file_get_contents($root . '/cron/master.php');
t('the guard is on the master schedule', strpos($master, "'wa_webhook_guard'") !== false, true);
t('at a sane interval (600s)',
  (bool)preg_match("/'wa_webhook_guard'[^\\n]*'interval' => 600/", $master), true);

if (is_resource($srv)) { proc_terminate($srv); proc_close($srv); }
exec('rm -rf ' . escapeshellarg($tmp));
array_map('unlink', glob(sys_get_temp_dir() . '/fake_evo_state_*.json') ?: []);
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
