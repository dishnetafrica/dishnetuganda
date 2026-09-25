<?php
declare(strict_types=1);
/**
 * test_otp_log_privacy.php — a login code is never written into a log a
 * person can read (5.18.37, Phase 1 of the customer-login audit; P-1).
 *
 * NotificationService writes every send into notification_audit_log with a
 * 70-character `preview` of the message. The login message begins
 * "🔐 *DishNet Login Code*  Your code: *123456*", so the code sat in the first
 * line of every preview — and app_debug_log served that column to anyone who
 * typed a phone number. The action is gone (test_customer_login_security);
 * this test pins the other half: the preview for an `app_otp` send is a fixed
 * notice, and the staff delivery log carries no preview column at all.
 *
 * The send goes through a fake Evolution on loopback, so the live path — the
 * one that writes the log — is the path exercised. No real number, no real
 * code: the digits below are invented and are asserted ABSENT from the log.
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
require_once $root . '/lib/currency.php';
require_once $root . '/lib/CustomerContact.php';
require_once $root . '/lib/NotificationService.php';

$tmp = sys_get_temp_dir() . '/dn_otplog_' . getmypid();
exec('rm -rf ' . escapeshellarg($tmp)); @mkdir($tmp, 0700, true);
register_shutdown_function(function () use ($tmp) { exec('rm -rf ' . escapeshellarg($tmp)); });

// ── the fake Evolution ───────────────────────────────────────────────────────
$http = function (string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_PROXY => '']);
    $r = curl_exec($ch); $c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$r === false ? 0 : $c, is_string($r) ? json_decode($r, true) : null];
};
$srv = null; $port = 0;
foreach (range(0, 9) as $slot) {
    $cand = 10900 + ((getmypid() + $slot * 23) % 200);
    $p = proc_open(sprintf('exec php -S 127.0.0.1:%d %s', $cand, escapeshellarg($root . '/tests/fixtures/fake_evo_server.php')),
                   [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    $ours = false;
    for ($i = 0; $i < 40; $i++) {
        [$c, $b] = $http("http://127.0.0.1:{$cand}/__test/state");
        if ($c !== 0) { $ours = is_array($b); break; }
        usleep(100000);
    }
    if ($ours) { $srv = $p; $port = $cand; break; }
    if (is_resource($p)) { proc_terminate($p); proc_close($p); }
}
if ($srv === null) { echo "  FAIL could not start the fake Evolution\n"; exit(1); }
register_shutdown_function(function () use (&$srv) { if (is_resource($srv)) { proc_terminate($srv); proc_close($srv); } });
$http("http://127.0.0.1:{$port}/__test/reset");

$store = SqliteStore::create($tmp);
$svc = new NotificationService($store, [
    'dry_run_mode' => false, 'data_dir' => $tmp,
    'evo_api_url' => "http://127.0.0.1:{$port}", 'evo_api_key' => 'k', 'evo_instance_support' => 'ug-support',
]);
$rows = function () use ($store): array {
    try { return $store->getPdo()->query("SELECT event, phone, preview, success FROM notification_audit_log ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC); }
    catch (\Throwable $e) { return []; }
};

// ═════════════════════════════════════════════════
echo "\n1. The login message is sent, and its log line withholds the text\n";
// ═════════════════════════════════════════════════
$code = '482193';   // invented
$msg  = "🔐 *DishNet Login Code*\n\nYour code: *{$code}*\n\nValid for 15 minutes. If you did not request this, ignore.";
$svc->sendVia(NotificationService::SUPPORT, '+256772123456', $msg, 'app_otp', ['crm_client_id' => 7, 'name' => 'Test']);
[, $state] = $http("http://127.0.0.1:{$port}/__test/state");
$calls = $state['text_calls'] ?? [];
t('one message reached the (fake) Evolution',               count($calls), 1);
is_(strpos((string)($calls[0]['text'] ?? ''), $code) !== false, '…and it carries the code — the customer gets it');
$log = $rows();
t('one audit row was written',                              count($log), 1);
t('…for event app_otp',                                     $log[0]['event'] ?? null, 'app_otp');
t('…marked successful',                                     (int)($log[0]['success'] ?? 0), 1);
t('…with the fixed notice as its preview',                  $log[0]['preview'] ?? null, '[one-time login code — text withheld]');
is_(strpos((string)json_encode($log), $code) === false,     '…the code appears nowhere in the audit row');
is_(preg_match('/\d{6}/', (string)($log[0]['preview'] ?? '')) === 0, '…no six-digit run in the preview at all');

// ═════════════════════════════════════════════════
echo "\n2. Control: any other event keeps its preview\n";
// ═════════════════════════════════════════════════
$svc->sendVia(NotificationService::SUPPORT, '+256772123456', "Your invoice INV-000901 is ready. Total: UGX 299,000.", 'event_invoice_add', []);
$log = $rows();
t('two rows now',                                           count($log), 2);
is_(strpos((string)($log[1]['preview'] ?? ''), 'INV-000901') !== false, 'the invoice notice keeps its 70-character preview');
t('…cut at 70 characters',                                  mb_strlen((string)($log[1]['preview'] ?? '')) <= 70, true);

// ═════════════════════════════════════════════════
echo "\n3. The source: the rule lives in NotificationService; the staff log has no preview\n";
// ═════════════════════════════════════════════════
$ns = codeNC($root . '/lib/NotificationService.php');
is_(strpos($ns, "\$event === 'app_otp' ? '[one-time login code — text withheld]'") !== false, 'writeLog\'s preview is the notice for app_otp');
$sup = codeNC($root . '/includes/api/api_customer_support.php');
$at  = strpos($sup, "if (\$act === 'staff_otp_log'"); $blk = substr($sup, (int)$at, 3000);
is_($at !== false && strpos($blk, 'preview') === false, 'staff_otp_log selects no preview column');
is_(strpos($blk, 'SELECT sender, event, phone, success, http_code, error, sent_at') !== false, '…its column list is explicit, so a `SELECT *` cannot creep back');
$app = codeNC($root . '/includes/api/api_customer_app.php');
is_(strpos($app, "'app_debug_log'") === false, 'app_debug_log, which served the preview column to anyone, is gone');
is_(strpos($app, "'app_otp'") !== false, '…and the login send is still tagged app_otp, so the rule applies to it');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
