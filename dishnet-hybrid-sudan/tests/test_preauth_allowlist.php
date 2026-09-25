<?php
declare(strict_types=1);
/**
 * test_preauth_allowlist.php — what the API answers with no login is a LIST,
 * and the list is pinned here (5.18.37, Phase 1 of the customer-login audit).
 *
 * Until 5.18.37 three whole staff API files — api_payments_admin.php,
 * api_products_admin.php and api_cron_debug.php — were included BEFORE the
 * staff guard in api_handlers.php, and seven staff diagnostics sat in the
 * public file. Voiding a payment, posting a payment to uCRM, downloading the
 * entire data directory, replaying queues, reading the CRM retry queue: every
 * one answered to anyone who knew the action name. Two hid behind a constant
 * key written in the source.
 *
 * This test:
 *   1. reads api_handlers.php and every file it includes before the guard,
 *      collects each `$act === '…'` it can reach, and compares that set to the
 *      allow-list below — one new pre-auth action, and this fails;
 *   2. over real HTTP, proves every moved action answers 401 to an anonymous
 *      caller, 403 to a signed-in non-administrator where it is admin-only,
 *      and 200 to an administrator — the capability still exists, behind the
 *      guard, which is the point;
 *   3. proves the removed actions are gone for everyone, and the constant key
 *      with them.
 *
 * Nothing here needs a network beyond loopback; no secret and no real
 * customer appears in this file.
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

// ═════════════════════════════════════════════════
echo "\n1. The pre-auth surface, read from the dispatcher\n";
// ═════════════════════════════════════════════════
/** Every action a caller can reach with no login. Change this list only on purpose. */
$ALLOW = [
    // staff login and the n8n/Evolution context read (key-gated on webhook_secret; denies when it is empty)
    'login', 'customer_context',
    // PDF links, each checked by its own token
    'serve_temp_pdf', 'serve_quote_pdf', 'serve_delivery_pdf', 'serve_receipt_pdf',
    // the customer app: sign-in, then everything behind the customer's own Bearer token
    'app_send_otp', 'app_verify_otp', 'app_logout', 'app_me', 'app_account', 'app_plan', 'app_usage',
    'app_invoices', 'app_invoice', 'app_invoice_pdf_download', 'app_invoice_receipts_list', 'app_invoice_send_whatsapp',
    'app_payments', 'app_payment_receipt_pdf', 'app_equipment', 'app_legal_version', 'app_record_consent',
    'app_register_fcm', 'app_unregister_fcm', 'app_debug_report', 'app_health_app',
    'app_site_diagnostics', 'app_site_refresh',
    'app_hotspot_prepare', 'app_hotspot_status', 'app_hotspot_resync', 'app_hotspot_rotate_password', 'app_hotspot_toggle_mode',
    'app_wifi_get', 'app_wifi_save',
    'app_devices_get_seen', 'app_devices_record_seen', 'app_devices_acknowledge',
    'app_device_blocklist_get', 'app_device_blocklist_toggle',
    'app_paid_access_list', 'app_paid_access_grant', 'app_paid_access_extend', 'app_paid_access_revoke',
    // paying an invoice from the portal (customer Bearer token)
    'dpo_initiate', 'dpo_status',
];
sort($ALLOW);

$handlers = codeNC($root . '/includes/api_handlers.php');
$guardAt  = strpos($handlers, '$me2 = $auth->tokenAuth();');
is_($guardAt !== false, 'the staff guard is where the dispatcher has always had it');
$before = substr($handlers, 0, (int)$guardAt);
$after  = substr($handlers, (int)$guardAt);

// The files included before the guard, then the files THEY include (one level —
// api_public.php pulls in api_customer_app.php and api_dpo.php).
$preFiles = [];
preg_match_all("#require __DIR__ \\. '/api/([A-Za-z0-9_]+\\.php)'#", $before, $m);
foreach ($m[1] as $f) $preFiles[] = $f;
t('exactly two files are included before the guard', $preFiles, ['api_public.php', 'api_public_files.php']);
$closure = [];
foreach ($preFiles as $f) {
    $closure[] = $f;
    preg_match_all("#require __DIR__ \\. '/([A-Za-z0-9_]+\\.php)'#", codeNC($root . '/includes/api/' . $f), $mm);
    foreach ($mm[1] as $g) $closure[] = $g;
}
sort($closure);
t('…and, with what they include, the pre-auth closure is exactly four files',
  $closure, ['api_customer_app.php', 'api_dpo.php', 'api_public.php', 'api_public_files.php']);

$found = [];
foreach ($closure as $f) {
    $src = codeNC($root . '/includes/api/' . $f);
    preg_match_all("#\\\$act === '([A-Za-z0-9_]+)'#", $src, $a);
    foreach ($a[1] as $name) $found[$name] = true;
    is_(preg_match('#in_array\(\s*\$act\b#', $src) === 0, "$f names its actions one by one (no in_array(\$act …) list a scan could miss)");
}
$found = array_keys($found); sort($found);
t('the reachable pre-auth actions are exactly the allow-list', $found, $ALLOW);
t('…' . count($ALLOW) . ' of them', count($found), count($ALLOW));
foreach (['app_debug_lookup', 'app_debug_log', 'app_debug_schema', 'app_debug_send', 'app_debug_list',
          'data_dir_info', 'crm_debug', 'handover_audit', 'payment_key_log', 'check_retailer_key', 'payment_push_log',
          'payment_catchup_log', 'backup_download', 'cron_trigger', 'test_payment_post', 'patch_ucrm_payment',
          'voidable_payments', 'quote_log', 'kyc_debug', 'sync_ucrm_products'] as $gone) {
    is_(!in_array($gone, $found, true), "$gone is not reachable before the guard");
}

// The three moved files are included AFTER the guard, and the four removed
// actions exist nowhere.
foreach (['api_payments_admin.php', 'api_products_admin.php', 'api_cron_debug.php',
          'api_staff_diagnostics.php', 'api_customer_support.php'] as $f) {
    is_(strpos($after, "require __DIR__ . '/api/{$f}'") !== false && strpos($before, "'/api/{$f}'") === false,
        "$f is included after the guard and not before it");
}
$allApi = '';
foreach (glob($root . '/includes/api/*.php') as $f) $allApi .= codeNC($f);
$allApi .= codeNC($root . '/includes/api_handlers.php') . codeNC($root . '/includes/routes.php');
foreach (['app_debug_lookup', 'app_debug_log', 'app_debug_schema', 'app_debug_send'] as $gone) {
    is_(strpos($allApi, "'{$gone}'") === false, "$gone exists in no API file at all");
}
is_(strpos(codeNC($root . '/includes/routes.php'), "\$page === 'crm_debug'") === false, 'the ?page=crm_debug page is gone from routes.php');
is_(is_file($root . '/tools/crm_debug.php') && strpos(codeNC($root . '/tools/crm_debug.php'), "PHP_SAPI !== 'cli'") !== false,
    '…replaced by tools/crm_debug.php, a CLI that refuses to run over HTTP');
$cron = codeNC($root . '/includes/api/api_cron_debug.php');
is_(strpos($cron, "\$_GET['key']") === false, 'api_cron_debug.php compares no key from the query string any more (the constant key is gone)');
is_(strpos($cron, "\$_cdAdminOnly") !== false && strpos($cron, "'backup_download'") !== false && strpos($cron, "'test_payment_post'") !== false,
    '…backup_download and test_payment_post are on its admin-only list');
is_(strpos($cron, "\$_GET['confirm']") !== false, 'test_payment_post needs an explicit confirm parameter');
is_(strpos($cron, 'createPaymentSafe(') !== false && strpos($cron, "'COL-' . \$colId") !== false,
    '…and posts through createPaymentSafe with the collection reference, so a repeat cannot post twice');

// ═════════════════════════════════════════════════
echo "\n2. Over HTTP: anonymous 401, non-admin 403, admin 200\n";
// ═════════════════════════════════════════════════
$sandbox = sys_get_temp_dir() . '/dn_preauth_' . getmypid();
$tmp     = $sandbox . '/plugin';
exec('rm -rf ' . escapeshellarg($sandbox)); @mkdir($tmp . '/data', 0700, true);
register_shutdown_function(function () use ($sandbox) { exec('rm -rf ' . escapeshellarg($sandbox)); });
exec(sprintf('cp -R %s/. %s/ 2>/dev/null', escapeshellarg($root), escapeshellarg($tmp)));
exec('rm -rf ' . escapeshellarg($tmp . '/data')); @mkdir($tmp . '/data', 0700, true);
file_put_contents($tmp . '/ucrm.json', json_encode(['pluginDataDir' => $tmp . '/data']));

require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';
$store = SqliteStore::create($tmp . '/data');
$store->save('kyc_config.json', ['dry_run_mode' => true, 'data_dir' => $tmp . '/data']);
$adminTok = 'TEST-ADMIN-' . bin2hex(random_bytes(20));
$staffTok = 'TEST-STAFF-' . bin2hex(random_bytes(20));
$store->appendWithId('retailers.json', ['name' => 'Test Admin', 'email' => 'admin@example.test', 'phone' => '+256700000001',
    'is_active' => true, 'is_admin' => true, 'api_token' => $adminTok, 'token_issued_at' => time(),
    'password' => password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT, ['cost' => 4])]);
$store->appendWithId('retailers.json', ['name' => 'Test Agent', 'email' => 'agent@example.test', 'phone' => '+256700000002',
    'is_active' => true, 'is_admin' => false, 'modules' => [], 'api_token' => $staffTok, 'token_issued_at' => time(),
    'password' => password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT, ['cost' => 4])]);
unset($store);

$nonce = bin2hex(random_bytes(8));
file_put_contents($tmp . '/__nonce.txt', $nonce);
$probe = function (string $url): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2, CURLOPT_PROXY => '']);
    $r = curl_exec($ch); $c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ($r === false || $c !== 200) ? null : (string)$r;
};
$srv = null; $port = 0;
foreach (range(0, 9) as $slot) {
    $cand = 9700 + ((getmypid() + $slot * 41) % 250);
    $p = proc_open(sprintf('exec php -S 127.0.0.1:%d -t %s', $cand, escapeshellarg($tmp)),
                   [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    $ours = false;
    for ($i = 0; $i < 60; $i++) {
        $got = $probe("http://127.0.0.1:{$cand}/__nonce.txt");
        if ($got !== null) { $ours = trim($got) === $nonce; break; }
        usleep(100000);
    }
    if ($ours) { $srv = $p; $port = $cand; break; }
    proc_terminate($p); proc_close($p);
}
@unlink($tmp . '/__nonce.txt');
if ($srv === null) { echo "  FAIL could not start the plugin under php -S\n"; printf("\n%d passed, %d failed\n", $pass, $fail + 1); exit(1); }
register_shutdown_function(function () use (&$srv) { if (is_resource($srv)) { proc_terminate($srv); proc_close($srv); } });
$base = "http://127.0.0.1:{$port}/public.php";

/** @return array{code:int,json:?array,raw:string} */
$call = function (string $method, string $query, ?array $body = null, array $headers = []) use ($base): array {
    $ch = curl_init($base . '?' . $query);
    $h  = array_merge(['Content-Type: application/json'], $headers);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_PROXY => '',
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $raw = is_string($r) ? $r : '';
    $j = json_decode($raw, true);
    return ['code' => $r === false ? 0 : $code, 'json' => is_array($j) ? $j : null, 'raw' => $raw];
};
$asAdmin = ["Authorization: Bearer {$adminTok}"];
$asStaff = ["Authorization: Bearer {$staffTok}"];

// The actions that moved behind the guard, with the method their screens use.
$moved = [
    // api_staff_diagnostics.php (were in api_public.php)
    ['GET', 'data_dir_info'], ['GET', 'crm_debug'], ['GET', 'handover_audit'], ['GET', 'payment_key_log'],
    ['GET', 'check_retailer_key&retailer_id=1'], ['GET', 'payment_push_log'], ['GET', 'payment_catchup_log'],
    // api_customer_support.php (replaces the app_debug_* actions)
    ['GET', 'staff_login_lookup&phone=%2B256772123456'], ['GET', 'staff_otp_log&phone=%2B256772123456'], ['GET', 'app_debug_list'],
    // api_cron_debug.php
    ['GET', 'backup_download'], ['GET', 'cron_trigger&job=nightly'], ['GET', 'test_payment_post&collection_id=1'],
    ['GET', 'debug_payment_sync'], ['GET', 'view_error_log'], ['GET', 'webhook_log'], ['GET', 'data_diagnostic'],
    ['GET', 'find_lost_data'], ['GET', 'cron_status'],
    // api_payments_admin.php
    ['GET', 'voidable_payments'], ['POST', 'patch_ucrm_payment'], ['GET', 'hq_debug_collections'], ['GET', 'check_duplicate_payments'],
    // api_products_admin.php
    ['GET', 'quote_log'], ['GET', 'kyc_debug'], ['POST', 'sync_ucrm_products'], ['GET', 'ucrm_quotes'],
];
foreach ($moved as [$method, $q]) {
    $r = $call($method, 'page=api&action=' . $q, $method === 'POST' ? [] : null);
    $name = explode('&', $q)[0];
    is_($r['code'] === 401 && ($r['json']['message'] ?? '') === 'Unauthorized.',
        "anonymous {$method} {$name}: 401 Unauthorized", $r['code'] . ' ' . substr($r['raw'], 0, 120));
}
// The removed customer-app debug actions: the guard for the anonymous, and no such action for the signed-in.
foreach (['app_debug_lookup', 'app_debug_log', 'app_debug_schema', 'app_debug_send'] as $gone) {
    $r = $call('POST', 'page=api&action=' . $gone, ['phone' => '+256772123456']);
    t("anonymous {$gone}: 401", [$r['code'], $r['json']['message'] ?? null], [401, 'Unauthorized.']);
    $r = $call('POST', 'page=api&action=' . $gone, ['phone' => '+256772123456'], $asAdmin);
    t("…and even an administrator finds no such action (404)", [$r['code'], $r['json']['message'] ?? null], [404, "Unknown API action: {$gone}"]);
}
// The constant key opens nothing: with or without any key value, anonymous is anonymous.
$r = $call('GET', 'page=api&action=backup_download&key=' . rawurlencode(str_repeat('x', 11)));
t('backup_download with a key parameter is still 401 — the key is not consulted', $r['code'], 401);
// The debug page is gone from the router.
$r = $call('GET', 'page=crm_debug&action=payment_methods');
is_(($r['json']['status'] ?? '') !== 'success' && strpos($r['raw'], 'payment_methods') === false,
    '?page=crm_debug answers no diagnostic to an anonymous caller');

// Positive controls: the guard admits staff, the admin-only gates admit administrators.
$r = $call('GET', 'page=api&action=cron_status', null, $asStaff);
t('signed-in agent: cron_status 200 (any staff member, as its screen expects)', $r['code'], 200);
foreach (['data_dir_info', 'backup_download', 'crm_debug', 'staff_login_lookup&phone=%2B256772123456', 'app_debug_list', 'voidable_payments', 'quote_log'] as $q) {
    $r = $call('GET', 'page=api&action=' . $q, null, $asStaff);
    t('signed-in agent: ' . explode('&', $q)[0] . ' is 403 Admin only', [$r['code'], $r['json']['message'] ?? null], [403, 'Admin only.']);
}
$r = $call('GET', 'page=api&action=data_dir_info', null, $asAdmin);
t('administrator: data_dir_info 200', $r['code'], 200);
is_(isset($r['json']['data']['data_dir']) || isset($r['json']['data']['actual_data_dir']) || $r['json']['status'] === 'success',
    '…with its report');
$r = $call('GET', 'page=api&action=staff_login_lookup&phone=%2B256772123456', null, $asAdmin);
t('administrator: staff_login_lookup 200', $r['code'], 200);
t('…no account for an unknown number, said plainly', $r['json']['data']['accounts'] ?? null, []);
is_(!isset($r['json']['data']['sample']) && strpos($r['raw'], '"code"') === false, '…and no other customer\'s row, no code');
$r = $call('GET', 'page=api&action=staff_otp_log&phone=%2B256772123456', null, $asAdmin);
t('administrator: staff_otp_log 200', $r['code'], 200);
is_(strpos($r['raw'], '"preview"') === false, '…and it carries no message preview column');
$r = $call('GET', 'page=api&action=app_debug_list', null, $asAdmin);
t('administrator: app_debug_list 200', [$r['code'], isset($r['json']['data']['reports'])], [200, true]);
$r = $call('GET', 'page=api&action=voidable_payments', null, $asAdmin);
is_($r['code'] !== 401 && $r['code'] !== 403, 'administrator: voidable_payments passes both gates', (string)$r['code']);
$r = $call('GET', 'page=api&action=test_payment_post&collection_id=1', null, $asAdmin);
t('administrator: test_payment_post without confirm is refused, before any lookup',
  [$r['code'], strpos((string)($r['json']['message'] ?? ''), 'confirm=') !== false], [400, true]);
$r = $call('GET', 'page=api&action=test_payment_post&collection_id=1&confirm=1', null, $asAdmin);
t('…with confirm, an unknown collection is 404 (nothing to post)', $r['code'], 404);

// Still reachable with no login, as the allow-list says — answered by the
// handler itself, not by the guard.
$r = $call('POST', 'page=api&action=login', ['email' => 'nobody@example.test', 'password' => 'wrong']);
t('login is pre-auth: a wrong password is 401 from the login handler', [$r['code'], $r['json']['message'] ?? null], [401, 'Invalid email or password.']);
$r = $call('GET', 'page=api&action=serve_receipt_pdf');
t('serve_receipt_pdf is pre-auth: no token is the handler\'s own 400', [$r['code'], $r['json']['message'] ?? null], [400, 'Missing file or token']);
$r = $call('GET', 'page=api&action=customer_context&phone=256772123456&key=anything');
t('customer_context is pre-auth but key-gated: with no webhook_secret configured it denies everyone', $r['code'], 401);
$r = $call('POST', 'page=api&action=app_send_otp', ['phone' => '']);
t('app_send_otp is pre-auth: an empty body is the handler\'s own 400', [$r['code'], $r['json']['message'] ?? null], [400, 'Either phone or email is required.']);
$r = $call('GET', 'page=api&action=no_such_action_ever');
t('an unknown action is stopped by the guard for the anonymous (401, not 404 — no enumeration by name)', $r['code'], 401);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
