<?php
declare(strict_types=1);
/**
 * test_customer_otp_transport.php — the sign-in code goes out through the
 * transport this install has (Phase 2 of the customer-login audit, plan §E.6;
 * decisions D-11, D-12, D-13).
 *
 * A copy of the plugin runs under php -S against a fake Evolution. WASender is
 * NOT configured — the shape of the Uganda install, which until Phase 2 was
 * refused with "WhatsApp sender is not configured". Proves: the code leaves
 * through Evolution to the CANONICAL +256 number (typed nationally), signs the
 * customer in, and rests nowhere in clear (pending table hashed, no
 * conversation row, no retry-queue row); a transport failure is audited and
 * still answered uniformly; a number the rule cannot canonicalise matches
 * nobody; an install with no transport at all is still refused; a WASender-only
 * install (Sudan) still passes the gate.
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
if (!getenv('DN_VAULT_FILE')) putenv('DN_VAULT_FILE=' . tempnam(sys_get_temp_dir(), 'dn-vault-'));
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/CustomerSession.php';

$sandbox = sys_get_temp_dir() . '/dn_otpx_' . getmypid(); $tmp = $sandbox . '/plugin'; $data = $tmp . '/data';
exec('rm -rf ' . escapeshellarg($sandbox)); @mkdir($data, 0700, true);
register_shutdown_function(function () use ($sandbox) { exec('rm -rf ' . escapeshellarg($sandbox)); });

echo "\n0. A fake Evolution and a copy of the plugin with Evolution only (the Uganda shape)\n";
$get = function (string $url): array { $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_PROXY => '']);
    $r = curl_exec($ch); $c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); return [$r === false ? 0 : $c, is_string($r) ? json_decode($r, true) : null]; };
$evo = null; $evoPort = 0;
foreach (range(0, 9) as $slot) {
    $cand = 11000 + ((getmypid() + $slot * 29) % 200);
    $p = proc_open(sprintf('exec php -S 127.0.0.1:%d %s', $cand, escapeshellarg($root . '/tests/fixtures/fake_evo_server.php')), [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    $ours = false;
    for ($i = 0; $i < 40; $i++) { [$c, $b] = $get("http://127.0.0.1:{$cand}/__test/state"); if ($c !== 0) { $ours = is_array($b) && ($b['marker'] ?? '') === 'FAKE-EVO-TEST'; break; } usleep(100000); }
    if ($ours) { $evo = $p; $evoPort = $cand; break; }
    if (is_resource($p)) { proc_terminate($p); proc_close($p); }
}
if ($evo === null) { echo "  FAIL could not start the fake Evolution\n"; exit(1); }
register_shutdown_function(function () use (&$evo) { if (is_resource($evo)) { proc_terminate($evo); proc_close($evo); } });
$get("http://127.0.0.1:{$evoPort}/__test/reset");
$evoState = function () use ($get, $evoPort): array { return $get("http://127.0.0.1:{$evoPort}/__test/state")[1] ?? []; };

exec(sprintf('cp -R %s/. %s/ 2>/dev/null', escapeshellarg($root), escapeshellarg($tmp)));
exec('rm -rf ' . escapeshellarg($data)); @mkdir($data, 0700, true);
file_put_contents($tmp . '/ucrm.json', json_encode(['pluginDataDir' => $data]));
$EVO_CFG = ['dry_run_mode' => false, 'data_dir' => $data, 'tenant_profile' => 'uganda',
    'evo_api_url' => "http://127.0.0.1:{$evoPort}", 'evo_api_key' => 'k', 'evo_instance_support' => 'ug-support',
    'app_otp_limit_per_hour' => 20];
$store = SqliteStore::create($data);
$store->save('kyc_config.json', $EVO_CFG);
// The customer's number as uCRM often holds it: national. The index keys the last nine digits.
$store->getPdo()->prepare("REPLACE INTO client_search_index (id, name, phone, phone_norm, service, updated_at) VALUES (?, ?, ?, ?, ?, datetime('now'))")
    ->execute([7, 'Test Customer', '0772123456', '772123456', 'Starlink Standard']);
$ADMIN_TOKEN = 'TEST-ADMIN-' . bin2hex(random_bytes(12));
$store->appendWithId('retailers.json', ['name' => 'Test Admin', 'email' => 'admin@example.test', 'is_active' => true, 'is_admin' => true,
    'api_token' => $ADMIN_TOKEN, 'token_issued_at' => time(), 'password' => password_hash('x', PASSWORD_BCRYPT, ['cost' => 4])]);
unset($store);

$nonce = bin2hex(random_bytes(8)); file_put_contents($tmp . '/__nonce.txt', $nonce);
$srv = null; $port = 0;
foreach (range(0, 9) as $slot) {
    $cand = 9400 + ((getmypid() + $slot * 37) % 240);
    $p = proc_open(sprintf('exec php -S 127.0.0.1:%d -t %s', $cand, escapeshellarg($tmp)), [1 => ['file', '/dev/null', 'w'], 2 => ['file', $sandbox . '/server.log', 'a']], $pipes);
    $ours = false;
    for ($i = 0; $i < 60; $i++) { [$c, $b] = $get("http://127.0.0.1:{$cand}/__nonce.txt"); if ($c !== 0) { $ours = $c === 200; break; } usleep(100000); }
    if ($ours) { $raw = (string)file_get_contents("http://127.0.0.1:{$cand}/__nonce.txt"); if (trim($raw) === $nonce) { $srv = $p; $port = $cand; break; } }
    if (is_resource($p)) { proc_terminate($p); proc_close($p); }
}
@unlink($tmp . '/__nonce.txt');
if ($srv === null) { echo "  FAIL could not start the plugin under php -S\n"; exit(1); }
register_shutdown_function(function () use (&$srv) { if (is_resource($srv)) { proc_terminate($srv); proc_close($srv); } });
$base = "http://127.0.0.1:{$port}/public.php";
is_(true, "plugin on 127.0.0.1:{$port}, fake Evolution on 127.0.0.1:{$evoPort}");
$api = function (string $method, string $action, ?array $body = null, array $headers = []) use ($base): array {
    $ch = curl_init($base . '?page=api&action=' . $action);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 60, CURLOPT_PROXY => '', CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers)]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    if ($r === false) return ['code' => 0, 'json' => null, 'raw' => '', 'hdr' => ''];
    $j = json_decode(substr($r, $hs), true);
    return ['code' => $code, 'json' => is_array($j) ? $j : null, 'raw' => substr($r, $hs), 'hdr' => substr($r, 0, $hs)];
};
$pdo = function () use ($data): \PDO { return SqliteStore::create($data)->getPdo(); };
$count = function (string $sql, array $p = []) use ($pdo): int { try { $st = $pdo()->prepare($sql); $st->execute($p); return (int)$st->fetchColumn(); } catch (\Throwable $e) { return -1; } };
$audit = function (string $action, ?string $ident = null) use ($count): int { return $ident === null ? $count("SELECT COUNT(*) FROM app_audit_log WHERE action = ?", [$action]) : $count("SELECT COUNT(*) FROM app_audit_log WHERE action = ? AND phone = ?", [$action, $ident]); };
$setCfg = function (array $cfg) use ($data): void { $s = SqliteStore::create($data); $s->save('kyc_config.json', $cfg); };

echo "\n1. The code leaves through Evolution to the canonical +256 number\n";
$r = $api('POST', 'app_send_otp', ['phone' => '0772123456']);
t('app_send_otp for a nationally typed number: 200 Code sent via WhatsApp.', [$r['code'], $r['json']['message'] ?? null], [200, 'Code sent via WhatsApp.']);
$st = $evoState();
t('the fake Evolution received exactly one text', count($st['text_calls'] ?? []), 1);
$call = $st['text_calls'][0] ?? [];
t('…to 256772123456 — the tenant\'s dial code, not South Sudan\'s', $call['number'] ?? null, '256772123456');
t('…on the support instance', $call['instance'] ?? null, 'ug-support');
is_(preg_match('/\b(\d{6})\b/', (string)($call['text'] ?? ''), $m) === 1, '…carrying a six-digit code');
$code = $m[1] ?? '';
t('the pending row is keyed by the canonical identifier', $count("SELECT COUNT(*) FROM app_otp_pending WHERE phone = '+256772123456'"), 1);
$stored = (string)$pdo()->query("SELECT code FROM app_otp_pending WHERE phone = '+256772123456'")->fetchColumn();
is_($stored !== $code && preg_match('/^[0-9a-f]{64}$/', $stored) === 1, 'the stored code is an HMAC, not the code');
t('audit: otp_sent for the canonical identifier', $audit('otp_sent', '+256772123456'), 1);
$row = $pdo()->query("SELECT event, phone, preview, success FROM notification_audit_log ORDER BY id DESC LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
t('the notification log row: app_otp, success, text withheld', [$row['event'] ?? null, $row['phone'] ?? null, $row['preview'] ?? null, (int)($row['success'] ?? 0)], ['app_otp', '256772123456', '[one-time login code — text withheld]', 1]);
t('no conversation row carries the code (wa_messages)', max(0, $count("SELECT COUNT(*) FROM wa_messages WHERE body LIKE ?", ["%{$code}%"])), 0);
t('no retry-queue row exists', max(0, $count("SELECT COUNT(*) FROM notification_queue")), 0);
is_(strpos((string)file_get_contents($sandbox . '/server.log'), $code) === false, 'the code is not in the server log');

echo "\n2. The code that left signs the customer in\n";
$r = $api('POST', 'app_verify_otp', ['phone' => '0772123456', 'code' => $code]);
t('app_verify_otp: 200', $r['code'], 200);
is_(stripos($r['hdr'], 'Set-Cookie: ' . CustomerSession::COOKIE . '=') !== false, '…and the session cookie is set');
t('audit: login_success for the canonical identifier', $audit('login_success', '+256772123456'), 1);

echo "\n3. A transport failure is audited and answered uniformly; nothing is queued\n";
$get("http://127.0.0.1:{$evoPort}/__test/fail_next?n=5");
$before = count($evoState()['text_calls'] ?? []);
$r = $api('POST', 'app_send_otp', ['phone' => '+256 772 123 456']);
t('the answer is still the uniform 200', [$r['code'], $r['json']['message'] ?? null], [200, 'Code sent via WhatsApp.']);
t('audit: otp_send_failed', $audit('otp_send_failed', '+256772123456'), 1);
t('nothing was queued for a later retry (a stale code must never be re-sent)', max(0, $count("SELECT COUNT(*) FROM notification_queue")), 0);
t('the fake recorded no successful text for it', count($evoState()['text_calls'] ?? []), $before);
$get("http://127.0.0.1:{$evoPort}/__test/fail_next?n=0");

echo "\n4. A number the rule cannot canonicalise matches nobody and is addressed nowhere\n";
$before = count($evoState()['text_calls'] ?? []);
$r = $api('POST', 'app_send_otp', ['phone' => '12345678']);
t('eight digits, no trunk 0: the uniform 200', [$r['code'], $r['json']['message'] ?? null], [200, 'Code sent via WhatsApp.']);
t('audit: otp_no_account with reason not_a_number', $count("SELECT COUNT(*) FROM app_audit_log WHERE action = 'otp_no_account' AND details LIKE '%not_a_number%'"), 1);
t('no text left', count($evoState()['text_calls'] ?? []), $before);

echo "\n5. The staff lookup names the transport in use and the account's eligibility\n";
$r = $api('GET', 'staff_login_lookup&phone=' . rawurlencode('0772123456'), null, ['Authorization: Bearer ' . $ADMIN_TOKEN]);
t('staff_login_lookup: 200', $r['code'], 200);
t('…identifier canonical', $r['json']['data']['identifier'] ?? null, '+256772123456');
t('…transport in use: evolution', $r['json']['data']['transport']['in_use'] ?? null, 'evolution');
t('…wasender_configured false, evolution_configured true', [$r['json']['data']['transport']['wasender_configured'] ?? null, $r['json']['data']['transport']['evolution_configured'] ?? null], [false, true]);
t('…the account is eligible (flags unknown → allowed)', $r['json']['data']['accounts'][0]['eligible'] ?? null, true);

echo "\n6. No transport at all is still refused — before any lookup, the same for every number\n";
$setCfg(['dry_run_mode' => false, 'data_dir' => $data, 'tenant_profile' => 'uganda', 'app_otp_limit_per_hour' => 20]);
$r = $api('POST', 'app_send_otp', ['phone' => '0772123456']);
t('known number: 500 WhatsApp sender is not configured', [$r['code'], $r['json']['message'] ?? null], [500, 'WhatsApp sender is not configured on server. Contact admin.']);
$r2 = $api('POST', 'app_send_otp', ['phone' => '0700000999']);
t('unknown number: the identical 500', [$r2['code'], $r2['json']['message'] ?? null], [$r['code'], $r['json']['message'] ?? null]);
t('audit: otp_wa_not_configured with transport none', $count("SELECT COUNT(*) FROM app_audit_log WHERE action = 'otp_wa_not_configured' AND details LIKE '%\"transport\":\"none\"%'"), 2);

echo "\n7. A WASender-only install (the Sudan shape) still passes the gate\n";
$setCfg(['dry_run_mode' => true, 'data_dir' => $data, 'wa_plugin_url' => 'http://127.0.0.1:1/', 'wa_app_key' => 'k', 'wa_auth_key' => 'a', 'app_otp_limit_per_hour' => 20]);
@unlink($data . '/dry_run_notification_log.json');
$r = $api('POST', 'app_send_otp', ['phone' => '0772123456']);
t('200 Code sent via WhatsApp.', [$r['code'], $r['json']['message'] ?? null], [200, 'Code sent via WhatsApp.']);
$dry = json_decode((string)@file_get_contents($data . '/dry_run_notification_log.json'), true) ?: [];
t('…the dry-run log took the send (the WASender path, dry)', count($dry), 1);
is_(strpos(json_encode(end($dry)), '211772123456') !== false, '…addressed with the South Sudan code, as that install\'s profile says (no selector, no currency → south-sudan)');
$r = $api('GET', 'staff_login_lookup&phone=' . rawurlencode('0772123456'), null, ['Authorization: Bearer ' . $ADMIN_TOKEN]);
t('staff lookup: transport in use = wasender', $r['json']['data']['transport']['in_use'] ?? null, 'wasender');

echo "\n8. Source pins\n";
$app = codeNC($root . '/includes/api/api_customer_app.php');
is_(!preg_match("/'\+?211'/", $app), 'no South Sudan dial-code literal is left in the customer API');
is_(strpos($app, 'phoneTransport(NotificationService::SUPPORT)') !== false, 'the send path asks the notifier which transport it would use');
is_(strpos($app, 'ReflectionObject') === false, '…and reads the result through the public getter, not reflection');
is_(strpos($app, "wa_plugin_url']) && !empty(\$config['wa_app_key']) && !empty(\$config['wa_auth_key']);\n        if (!\$senderEnabled") === false, 'the WASender-only gate is gone');
$ns = codeNC($root . '/lib/NotificationService.php');
t("sendVia() keeps a login code out of the retry queue and the conversation store", substr_count($ns, "\$event !== 'app_otp'"), 2);
is_(strpos($ns, 'public function phoneTransport(') !== false && strpos($ns, 'public function lastSendResult(') !== false, 'the notifier exposes phoneTransport() and lastSendResult()');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
