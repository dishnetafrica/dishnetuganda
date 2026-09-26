<?php
declare(strict_types=1);
/**
 * test_customer_login_security.php — the customer sign-in door, after Phase 1
 * of the customer-login audit (5.18.37).
 *
 * What the audit found on the pre-auth customer-app surface, and what this
 * test now pins:
 *
 *   P-3  app_debug_lookup told anyone whether a phone or e-mail belonged to a
 *        customer — with another customer's index row as a "sample" — and
 *        app_debug_send sent a WhatsApp message to any registered number.
 *   P-1  app_debug_log listed every message ever sent to a number, with the
 *        first 70 characters of its text: the login code among them.
 *   P-2  app_debug_schema listed the tables and sample rows of every plugin.
 *   P-9  app_debug_list, reachable with ANY customer's token, listed every
 *        customer's uploaded debug reports.
 *   P-10 app_send_otp answered differently for a known and an unknown
 *        identifier (a `debug` block, a "no account" message), so the sign-in
 *        door doubled as a customer directory.
 *   P-8  app_record_consent recorded a "consent" for any identifier typed into
 *        the body, with no proof the caller owned it.
 *
 * Every step below runs over real HTTP against a copy of the plugin, with
 * WhatsApp in dry-run mode: nothing leaves the machine. The phone numbers and
 * names are invented; no code is ever printed.
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
require_once $root . '/lib/JwtAuth.php';
require_once $root . '/lib/LegalContent.php';
require_once $root . '/lib/CustomerJwtKeys.php';
require_once $root . '/lib/CustomerSession.php';

$KNOWN_PHONE   = '+256772123456';         // in the index
$UNKNOWN_PHONE = '+256700000999';         // not in the index
$KNOWN_EMAIL   = 'customer@example.test';
$UNKNOWN_EMAIL = 'nobody@example.test';

// ═════════════════════════════════════════════════
echo "\n0. The sandbox: a copy of the plugin, WhatsApp in dry-run, one customer in the index\n";
// ═════════════════════════════════════════════════
$sandbox = sys_get_temp_dir() . '/dn_login_' . getmypid();
$tmp     = $sandbox . '/plugin';
$data    = $tmp . '/data';
exec('rm -rf ' . escapeshellarg($sandbox)); @mkdir($data, 0700, true);
register_shutdown_function(function () use ($sandbox) { exec('rm -rf ' . escapeshellarg($sandbox)); });
exec(sprintf('cp -R %s/. %s/ 2>/dev/null', escapeshellarg($root), escapeshellarg($tmp)));
exec('rm -rf ' . escapeshellarg($data)); @mkdir($data, 0700, true);
file_put_contents($tmp . '/ucrm.json', json_encode(['pluginDataDir' => $data]));

$IP_LIMIT = 6;
$store = SqliteStore::create($data);
$store->save('kyc_config.json', [
    'dry_run_mode' => true, 'data_dir' => $data,
    'wa_plugin_url' => 'http://127.0.0.1:1/', 'wa_app_key' => 'k', 'wa_auth_key' => 'a',
    'app_otp_ip_limit_per_hour' => $IP_LIMIT,
]);
// Since migration 054 the customer index is a STRUCTURED table (id, name, phone,
// phone_norm, service, updated_at); SqliteStore::save('client_search_index.json')
// is silently skipped for it and load() reads the table. Seed it the way
// cron_sync.php does. Note the table carries no e-mail column — see §1.
$store->getPdo()->prepare("REPLACE INTO client_search_index (id, name, phone, phone_norm, service, updated_at)
                           VALUES (?, ?, ?, ?, ?, datetime('now'))")
    ->execute([7, 'Test Customer', $KNOWN_PHONE, substr(preg_replace('/[^0-9]/', '', $KNOWN_PHONE), -9), 'Starlink Standard']);
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
    $cand = 9950 + ((getmypid() + $slot * 43) % 250);
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
$call = function (string $method, string $action, ?array $body = null, array $headers = []) use ($base): array {
    $ch = curl_init($base . '?page=api&action=' . $action);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_PROXY => '',
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers)]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $raw = is_string($r) ? $r : '';
    $j = json_decode($raw, true);
    return ['code' => $r === false ? 0 : $code, 'json' => is_array($j) ? $j : null, 'raw' => $raw];
};
$dryLog = function () use ($data): array { return json_decode((string)@file_get_contents($data . '/dry_run_notification_log.json'), true) ?: []; };
$pdo    = function () use ($data): \PDO { $s = SqliteStore::create($data); return $s->getPdo(); };
$audit  = function (string $action) use ($pdo): int {
    try { $st = $pdo()->prepare("SELECT COUNT(*) FROM app_audit_log WHERE action = ?"); $st->execute([$action]); return (int)$st->fetchColumn(); }
    catch (\Throwable $e) { return -1; }
};
is_(true, 'server up on 127.0.0.1:' . $port);

// ═════════════════════════════════════════════════
echo "\n1. P-10 — a known and an unknown identifier get the same answer\n";
// ═════════════════════════════════════════════════
$known = $call('POST', 'app_send_otp', ['phone' => $KNOWN_PHONE]);
t('known phone: 200',                                   $known['code'], 200);
t('…"Code sent via WhatsApp."',                         $known['json']['message'] ?? null, 'Code sent via WhatsApp.');
$unknown = $call('POST', 'app_send_otp', ['phone' => $UNKNOWN_PHONE]);
t('unknown phone: 200 as well',                         $unknown['code'], 200);
t('…the same message',                                  $unknown['json']['message'] ?? null, 'Code sent via WhatsApp.');
$strip = function (?array $j): array { $d = $j['data'] ?? []; unset($d['server_time']); return $d; };
t('the two bodies are identical apart from the clock', $strip($unknown['json']), $strip($known['json']));
t('…and carry exactly the five neutral fields',        array_keys($strip($known['json'])), ['expires_in', 'dry_run', 'channel', 'mode']);
foreach (['client_id', 'debug', 'code', 'otp', 'found', 'exists', 'name', 'account', 'sample', 'matched'] as $leak) {
    is_(strpos($known['raw'], '"' . $leak . '"') === false && strpos($unknown['raw'], '"' . $leak . '"') === false, "no `$leak` field in either answer");
}
is_(strpos($unknown['raw'], 'No DishNet account') === false && strpos($known['raw'], 'Dry run') === false,
    'neither the old "No DishNet account" text nor the "Dry run — OTP logged" text appears');

// The server did the two different things behind the one answer.
$log = $dryLog();
t('exactly one WhatsApp message was (dry-run) sent — to the known customer', count($log), 1);
is_(preg_match('/\b\d{6}\b/', (string)($log[0]['message'] ?? '')) === 1, '…and it carries a six-digit code (the real path ran)');
t('the audit trail says otp_sent once',                $audit('otp_sent'), 1);
t('…and otp_no_account once — recorded for staff, hidden from the caller', $audit('otp_no_account'), 1);
$st = $pdo()->query("SELECT COUNT(*) FROM app_otp_pending");
t('one code is pending, for the known identifier only', (int)$st->fetchColumn(), 1);

$kEmail = $call('POST', 'app_send_otp', ['email' => $KNOWN_EMAIL]);
$uEmail = $call('POST', 'app_send_otp', ['email' => $UNKNOWN_EMAIL]);
t('known e-mail: 200 "Code sent via Email."',          [$kEmail['code'], $kEmail['json']['message'] ?? null], [200, 'Code sent via Email.']);
t('unknown e-mail: the same',                          [$uEmail['code'], $uEmail['json']['message'] ?? null], [200, 'Code sent via Email.']);
t('…identical bodies apart from the clock',            $strip($uEmail['json']), $strip($kEmail['json']));
// Recorded, not changed here (outside Phase 1's scope): the structured index has no
// e-mail column, so on this schema the e-mail lookup matches nobody — the KNOWN
// e-mail is audited as otp_no_account_email too. The caller cannot tell (above);
// staff can (the audit rows). The Phase 1 report carries it as a finding.
t('…on this schema both are audited as otp_no_account_email (recorded finding, see the Phase 1 report)', $audit('otp_no_account_email'), 2);

// ═════════════════════════════════════════════════
echo "\n2. A per-address limit throttles an enumeration run, known or unknown alike\n";
// ═════════════════════════════════════════════════
// Four requests so far from this address. The limit is {$IP_LIMIT} an hour.
$codes = []; $limitMsg = null;
for ($i = 0; $i < $IP_LIMIT + 2; $i++) {
    $r = $call('POST', 'app_send_otp', ['phone' => '+25670000' . str_pad((string)(100 + $i), 4, '0', STR_PAD_LEFT)]);
    $codes[] = $r['code'];
    if ($r['code'] === 429) { $limitMsg = $r['json']['message'] ?? null; break; }
}
$firstLimit = array_search(429, $codes, true);
t('the address is cut off after exactly ' . $IP_LIMIT . ' requests in the hour', $firstLimit, $IP_LIMIT - 4);
is_($firstLimit !== false && count(array_unique(array_slice($codes, 0, (int)$firstLimit))) === 1 && $codes[0] === 200,
    '…every request before it was answered 200 — unknown numbers, uniformly', json_encode($codes));
t('…with the rate-limit message', $limitMsg, 'Too many requests. Try again in 1 hour.');
t('…recorded as otp_rate_limit', $audit('otp_rate_limit') >= 1, true);
t('and the known customer is throttled by the same rule (the address, not the number)',
  $call('POST', 'app_send_otp', ['phone' => $KNOWN_PHONE])['code'], 429);

// ═════════════════════════════════════════════════
echo "\n3. P-1 / P-2 / P-3 / P-9 — the debug actions are gone or staff-only\n";
// ═════════════════════════════════════════════════
// Phase 2: a customer token is signed with the dedicated key set and is accepted
// only while its customer_sessions row is live — so the test mints one the way
// app_verify_otp does: forCustomers() + a recorded session.
$storeNow = SqliteStore::create($data);
$cfgNow   = $storeNow->load('kyc_config.json') ?? [];
CustomerJwtKeys::ensure($storeNow, $cfgNow);
$jwt      = JwtAuth::forCustomers($cfgNow, 3600);
// The issuer names the plugin DIRECTORY; the server runs from the sandbox copy, so the test names that copy.
$sandboxIss = 'dishnet-hybrid:' . basename($tmp);
$custTok  = $jwt->issue(['sub' => 7, 'kind' => 'app', 'phone' => $KNOWN_PHONE, 'accounts' => [7], 'login_mode' => 'phone', 'iss' => $sandboxIss]);
CustomerSession::record($storeNow->getPdo(), $jwt->decode($custTok) ?? [], $jwt->kid(), '127.0.0.1', 'test');
unset($storeNow);
$asCustomer = ["Authorization: Bearer {$custTok}"];
foreach (['app_debug_lookup' => ['phone' => $KNOWN_PHONE], 'app_debug_log' => ['phone' => $KNOWN_PHONE],
          'app_debug_schema' => [], 'app_debug_send' => ['phone' => $KNOWN_PHONE, 'message' => 'hi']] as $gone => $body) {
    $r = $call('POST', $gone, $body);
    t("$gone anonymously: 401", [$r['code'], $r['json']['message'] ?? null], [401, 'Unauthorized.']);
    $r = $call('POST', $gone, $body, $asCustomer);
    t("$gone with a customer's token: 401 (a customer token is not a staff token)", $r['code'], 401);
    $r = $call('GET', $gone . '&phone=' . rawurlencode($KNOWN_PHONE));
    t("$gone by GET: 401", $r['code'], 401);
}
$r = $call('GET', 'app_debug_list', null, $asCustomer);
t('app_debug_list with a customer\'s token: 401 — it is staff-only now', $r['code'], 401);
$r = $call('GET', 'app_debug_list');
t('…and anonymously', $r['code'], 401);
$src = codeNC($root . '/includes/api/api_customer_app.php');
foreach (['app_debug_lookup', 'app_debug_log', 'app_debug_schema', 'app_debug_send', 'app_debug_list'] as $gone) {
    is_(strpos($src, "'{$gone}'") === false, "$gone no longer exists in the customer-app file");
}
is_(strpos($src, 'No DishNet account with that') === false, 'the "no account" message is gone from the source');
$sendBlk = substr($src, strpos($src, "if (\$act === 'app_send_otp')"), strpos($src, "if (\$act === 'app_verify_otp')") - strpos($src, "if (\$act === 'app_send_otp')"));
is_(strpos($sendBlk, "'debug'") === false && strpos($sendBlk, "'client_id'") === false, 'app_send_otp builds no debug or client_id field');
is_(strpos($sendBlk, "'ip:'") !== false && strpos($sendBlk, 'app_otp_ip_limit_per_hour') !== false, '…and keys a per-address counter in app_otp_rate (no new table)');
is_(strpos($sendBlk, 'otp_no_account') !== false && strpos($sendBlk, '$uniform()') !== false, '…and answers an unknown identifier with the uniform body after auditing it');
$web = (string)file_get_contents($root . '/tabs/customer_app/login_web.php');
is_(strpos($web, 'Dry run — OTP logged') === false && strpos($web, 'Dry run') === false, 'the login page no longer shows a dry-run hint to the visitor');

// ═════════════════════════════════════════════════
echo "\n4. P-8 — consent is recorded for the identity the token proves, never a typed one\n";
// ═════════════════════════════════════════════════
require_once $root . '/lib/TenantProfile.php';
$ver = dnLegalVersion(TenantProfile::load('south-sudan'));   // the sandbox configures no tenant → south-sudan (5.18.42: versions are the tenant's)
$r = $call('POST', 'app_record_consent', ['phone' => $KNOWN_PHONE, 'tos_version' => $ver['tos'], 'privacy_version' => $ver['privacy']]);
t('no token: 401', [$r['code'], $r['json']['message'] ?? null], [401, 'Not signed in.']);
$r = $call('POST', 'app_record_consent', ['phone' => $KNOWN_PHONE, 'tos_version' => $ver['tos'], 'privacy_version' => $ver['privacy']],
           ['Authorization: Bearer not-a-token']);
t('a made-up token: 401', $r['code'], 401);
$staffLike = $jwt->issue(['sub' => 7, 'kind' => 'retailer', 'phone' => $KNOWN_PHONE, 'iss' => $sandboxIss]);   // right key, wrong kind
$r = $call('POST', 'app_record_consent', ['tos_version' => $ver['tos'], 'privacy_version' => $ver['privacy']], ["Authorization: Bearer {$staffLike}"]);
t('a token of another kind: 401', [$r['code'], $r['json']['message'] ?? null], [401, 'Wrong token type.']);
try { $pdo()->exec("DELETE FROM app_tos_consent"); } catch (\Throwable $e) {}
// The body names ANOTHER number; the token names the customer's own.
$r = $call('POST', 'app_record_consent', ['phone' => $UNKNOWN_PHONE, 'email' => $UNKNOWN_EMAIL, 'tos_version' => $ver['tos'], 'privacy_version' => $ver['privacy']], $asCustomer);
t('the customer\'s own token: 200', $r['code'], 200);
$rows = $pdo()->query("SELECT phone, crm_client_id FROM app_tos_consent")->fetchAll(\PDO::FETCH_ASSOC);
t('exactly one consent row', count($rows), 1);
t('…for the token\'s identity, not the body\'s', [$rows[0]['phone'] ?? null, (int)($rows[0]['crm_client_id'] ?? 0)], [$KNOWN_PHONE, 7]);
$r = $call('POST', 'app_record_consent', ['tos_version' => '0.9', 'privacy_version' => $ver['privacy']], $asCustomer);
t('a stale document version is still refused (409)', $r['code'], 409);
$consentBlk = substr($src, strpos($src, "if (\$act === 'app_record_consent'"), 2600);
is_(strpos($consentBlk, 'ca_require_auth(') !== false && strpos($consentBlk, 'ca_find_clients_by_phone') === false && strpos($consentBlk, 'ca_find_clients_by_email') === false,
    'the handler authenticates and performs no lookup by a typed identifier');
// Phase 2: the session is an HttpOnly cookie the server set at verification; the
// consent call rides on it with the header the API requires for a cookie POST.
is_(strpos($web, "'Authorization': 'Bearer ' + pendingToken") === false, 'the login page no longer handles a token at all');
is_(strpos($web, "'X-Requested-With': 'DishNet'") !== false && strpos($web, "credentials: 'same-origin'") !== false, 'the consent call rides on the session cookie with the page-marker header');
is_(strpos($web, "document.cookie = 'dn_customer_token=' +") === false && strpos($web, "&token=' + encodeURIComponent(token)") === false, 'the page writes no cookie and puts no token in the redirect');
$legacyTok = JwtAuth::fromConfig($cfgNow)->issue(['sub' => 7, 'kind' => 'app', 'phone' => $KNOWN_PHONE, 'accounts' => [7]]);
$r = $call('POST', 'app_record_consent', ['tos_version' => $ver['tos'], 'privacy_version' => $ver['privacy']], ["Authorization: Bearer {$legacyTok}"]);
t('a token signed the pre-Phase-2 way is refused (E3-a)', [$r['code'], $r['json']['message'] ?? null], [401, 'Invalid or expired token.']);
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
