<?php
declare(strict_types=1);
/**
 * test_customer_session.php — the customer session over real HTTP (Phase 2 of
 * the customer-login audit, plan §E.4–E.5 and §I; decisions D-2…D-7).
 *
 * The plugin runs under php -S from a copy. A customer signs in with a code
 * (WhatsApp in dry-run, the code read from the dry-run log, as the P-10 test
 * does). Then, end to end: the session is an HttpOnly, SameSite=Lax cookie the
 * SERVER set, Secure behind an HTTPS proxy; the JSON carries no token for a
 * browser and does for a native client; the portal and the API accept the
 * cookie and ignore a token in the URL; a cookie may authenticate a POST only
 * from our own page; the consent step is enforced server-side; logout revokes
 * the session for the portal AND the API; a staff "sign out everywhere" ends
 * every session; the data-report hand-off token is not a session token.
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
require_once $root . '/lib/CustomerJwtKeys.php';
require_once $root . '/lib/CustomerSession.php';
require_once $root . '/lib/LegalContent.php';

$PHONE = '+256772123456';

echo "\n0. Sandbox: a copy of the plugin, WhatsApp in dry-run, one customer, the uganda profile\n";
$sandbox = sys_get_temp_dir() . '/dn_sess_' . getmypid(); $tmp = $sandbox . '/plugin'; $data = $tmp . '/data';
exec('rm -rf ' . escapeshellarg($sandbox)); @mkdir($data, 0700, true);
register_shutdown_function(function () use ($sandbox) { exec('rm -rf ' . escapeshellarg($sandbox)); });
exec(sprintf('cp -R %s/. %s/ 2>/dev/null', escapeshellarg($root), escapeshellarg($tmp)));
exec('rm -rf ' . escapeshellarg($data)); @mkdir($data, 0700, true);
file_put_contents($tmp . '/ucrm.json', json_encode(['pluginDataDir' => $data]));
$store = SqliteStore::create($data);
$store->save('kyc_config.json', ['dry_run_mode' => true, 'data_dir' => $data, 'tenant_profile' => 'uganda',
    'wa_plugin_url' => 'http://127.0.0.1:1/', 'wa_app_key' => 'k', 'wa_auth_key' => 'a', 'app_jwt_ttl_days' => 2]);
$store->getPdo()->prepare("REPLACE INTO client_search_index (id, name, phone, phone_norm, service, updated_at) VALUES (?, ?, ?, ?, ?, datetime('now'))")
    ->execute([7, 'Test Customer', $PHONE, substr(preg_replace('/[^0-9]/', '', $PHONE), -9), 'Starlink Standard']);
unset($store);

$nonce = bin2hex(random_bytes(8)); file_put_contents($tmp . '/__nonce.txt', $nonce);
$probe = function (string $url): ?string { $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2, CURLOPT_PROXY => '']);
    $r = curl_exec($ch); $c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); return ($r === false || $c !== 200) ? null : (string)$r; };
$srv = null; $port = 0;
foreach (range(0, 9) as $slot) {
    $cand = 9700 + ((getmypid() + $slot * 41) % 240);
    $p = proc_open(sprintf('exec php -S 127.0.0.1:%d -t %s', $cand, escapeshellarg($tmp)), [1 => ['file', '/dev/null', 'w'], 2 => ['file', $sandbox . '/server.log', 'a']], $pipes);
    $ours = false;
    for ($i = 0; $i < 60; $i++) { $got = $probe("http://127.0.0.1:{$cand}/__nonce.txt"); if ($got !== null) { $ours = trim($got) === $nonce; break; } usleep(100000); }
    if ($ours) { $srv = $p; $port = $cand; break; }
    proc_terminate($p); proc_close($p);
}
@unlink($tmp . '/__nonce.txt');
if ($srv === null) { echo "  FAIL could not start the plugin under php -S\n"; printf("\n%d passed, %d failed\n", $pass, $fail + 1); exit(1); }
register_shutdown_function(function () use (&$srv) { if (is_resource($srv)) { proc_terminate($srv); proc_close($srv); } });
$base = "http://127.0.0.1:{$port}/public.php";
is_(true, "server up on 127.0.0.1:{$port}");

/** HTTP with headers kept. @return array{code:int,headers:array<string,string[]>,body:string,json:?array} */
$http = function (string $method, string $query, ?array $body = null, array $headers = []) use ($base): array {
    $ch = curl_init($base . $query);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30, CURLOPT_PROXY => '',
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers)]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    if ($r === false) return ['code' => 0, 'headers' => [], 'body' => '', 'json' => null];
    $hdrRaw = substr($r, 0, $hs); $bodyStr = substr($r, $hs);
    $hdrs = [];
    foreach (preg_split('/\r?\n/', $hdrRaw) as $line) { if (strpos($line, ':') === false) continue; [$k, $v] = explode(':', $line, 2); $hdrs[strtolower(trim($k))][] = trim($v); }
    $j = json_decode($bodyStr, true);
    return ['code' => $code, 'headers' => $hdrs, 'body' => $bodyStr, 'json' => is_array($j) ? $j : null];
};
$api = function (string $method, string $action, ?array $body = null, array $headers = []) use ($http): array { return $http($method, '?page=api&action=' . $action, $body, $headers); };
$setCookie = function (array $r, string $name): ?string { foreach ($r['headers']['set-cookie'] ?? [] as $c) if (strpos($c, $name . '=') === 0) return $c; return null; };
$cookieVal = function (?string $sc): string { return $sc === null ? '' : (string)preg_replace('/^[^=]+=([^;]*).*$/', '$1', $sc); };
$pdo = function () use ($data): \PDO { return SqliteStore::create($data)->getPdo(); };
$dryCode = function () use ($data): string {
    $log = json_decode((string)@file_get_contents($data . '/dry_run_notification_log.json'), true) ?: [];
    $last = json_encode(end($log));
    return preg_match('/\b(\d{6})\b/', (string)$last, $m) ? $m[1] : '';
};
$signIn = function (array $extraHeaders = []) use ($api, $dryCode, $PHONE, $setCookie): array {
    $s = $api('POST', 'app_send_otp', ['phone' => $PHONE]);
    if ($s['code'] !== 200) return ['send' => $s];
    $code = $dryCode();
    // what rests in the table between the send and the verification
    $pending = (string)SqliteStore::create($GLOBALS['data'])->getPdo()->query("SELECT code FROM app_otp_pending")->fetchColumn();
    $v = $api('POST', 'app_verify_otp', ['phone' => $PHONE, 'code' => $code], $extraHeaders);
    return ['send' => $s, 'verify' => $v, 'code' => $code, 'pending' => $pending, 'cookie' => $setCookie($v, CustomerSession::COOKIE)];
};
require_once $root . '/lib/TenantProfile.php';
$ver = dnLegalVersion(TenantProfile::load('uganda'));   // the sandbox's profile (5.18.42: versions are the tenant's)

echo "\n1. Signing in from a browser: the server sets the session cookie; the JSON carries no token\n";
$in = $signIn();
t('app_send_otp: 200', $in['send']['code'], 200);
is_(preg_match('/^\d{6}$/', $in['code']) === 1, 'the dry-run log carries the code the test types back');
is_($in['pending'] !== $in['code'] && preg_match('/^[0-9a-f]{64}$/', $in['pending']) === 1, 'app_otp_pending holds a 64-hex HMAC, not the code (D-13)', $in['pending']);
t('app_verify_otp: 200', $in['verify']['code'], 200);
is_(array_key_exists('token', $in['verify']['json']['data'] ?? []) && $in['verify']['json']['data']['token'] === null, '…the JSON token is null for a browser');
t('…session = cookie', $in['verify']['json']['data']['session'] ?? null, 'cookie');
$sc = $in['cookie'];
is_($sc !== null, 'Set-Cookie: dn_customer_session is present', json_encode($in['verify']['headers']['set-cookie'] ?? []));
is_($sc !== null && stripos($sc, 'httponly') !== false, '…HttpOnly');
is_($sc !== null && stripos($sc, 'samesite=lax') !== false, '…SameSite=Lax');
is_($sc !== null && stripos($sc, 'path=/') !== false, '…Path is the plugin\'s own path');
is_($sc !== null && stripos($sc, 'secure') === false, '…not Secure over plain http (a Secure cookie would be dropped and the login would silently never work)');
$jwt1 = $cookieVal($sc);
$hdr = json_decode(base64_decode(strtr(explode('.', $jwt1)[0], '-_', '+/')), true);
t('the cookie is a token signed with kid k1', $hdr['kid'] ?? null, 'k1');
$cl = json_decode(base64_decode(strtr(explode('.', $jwt1)[1], '-_', '+/')), true);
t('…aud customer-portal', $cl['aud'] ?? null, 'customer-portal');
t('…login_mode phone', $cl['login_mode'] ?? null, 'phone');
t('…the identifier is the canonical +256 number (not +211)', $cl['phone'] ?? null, $PHONE);
is_((int)($cl['exp'] - $cl['iat']) === 2 * 86400, 'the lifetime follows app_jwt_ttl_days');
$row = $pdo()->query("SELECT client_id, kid, login_mode, identifier, (revoked_at IS NULL) AS live FROM customer_sessions WHERE jti = " . $pdo()->quote((string)$cl['jti']))->fetch(\PDO::FETCH_ASSOC);
t('a customer_sessions row was recorded, live', [(int)($row['client_id'] ?? 0), $row['kid'] ?? null, $row['login_mode'] ?? null, $row['identifier'] ?? null, (int)($row['live'] ?? 0)], [7, 'k1', 'phone', $PHONE, 1]);
$legacy = $setCookie($in['verify'], 'dn_customer_token');
is_($legacy === null, 'the old JavaScript cookie is not set by the server (it is cleared only when a browser presents it)');

echo "\n2. Behind an HTTPS proxy the cookie is Secure\n";
$in2 = $signIn(['X-Forwarded-Proto: https']);
is_($in2['cookie'] !== null && stripos($in2['cookie'], '; secure') !== false, 'Set-Cookie carries Secure when X-Forwarded-Proto is https', (string)$in2['cookie']);
$jwt2 = $cookieVal($in2['cookie']);

echo "\n3. Consent is enforced server-side before the portal opens\n";
$C = 'Cookie: ' . CustomerSession::COOKIE . '=' . $jwt1;
$p = $http('GET', '?page=customer_portal&view=home', null, [$C]);
t('the portal sends a customer without consent to the consent step (302)', $p['code'], 302);
is_(strpos($p['headers']['location'][0] ?? '', 'customer_login&step=consent') !== false, '…Location names the consent step');
is_(strpos($p['headers']['location'][0] ?? '', 'token=') === false, '…and carries no token');
$l = $http('GET', '?page=customer_login', null, [$C]);
t('the login page renders (200) for the live-but-unconsented session', $l['code'], 200);
is_(strpos($l['body'], '"consent" === \'consent\'') !== false, '…and starts on the consent step');
$r = $api('POST', 'app_record_consent', ['tos_version' => $ver['tos'], 'privacy_version' => $ver['privacy']], [$C]);
t('a cookie POST WITHOUT the page marker header is refused (403 cross_site)', [$r['code'], $r['json']['message'] ?? null], [403, 'Cross-site request refused.']);
$r = $api('POST', 'app_record_consent', ['tos_version' => $ver['tos'], 'privacy_version' => $ver['privacy']], [$C, 'X-Requested-With: DishNet', 'Origin: https://evil.example']);
t('…and with the header but a foreign Origin', $r['code'], 403);
$r = $api('POST', 'app_record_consent', ['tos_version' => $ver['tos'], 'privacy_version' => $ver['privacy']], [$C, 'X-Requested-With: DishNet', 'Sec-Fetch-Site: cross-site']);
t('…and with the header but Sec-Fetch-Site: cross-site', $r['code'], 403);
$r = $api('POST', 'app_record_consent', ['tos_version' => $ver['tos'], 'privacy_version' => $ver['privacy']], [$C, 'X-Requested-With: DishNet', "Origin: http://127.0.0.1:{$port}"]);
t('the page\'s own consent call (header + same origin): 200 accepted', [$r['code'], $r['json']['data']['accepted'] ?? null], [200, true]);
$rows = $pdo()->query("SELECT phone FROM app_tos_consent")->fetchAll(\PDO::FETCH_COLUMN);
t('one consent row, keyed by the canonical identifier', $rows, [$PHONE]);
$l = $http('GET', '?page=customer_login', null, [$C]);
t('the login page now sends the signed-in customer to the portal (302)', $l['code'], 302);
is_(($l['headers']['location'][0] ?? '') !== '' && strpos($l['headers']['location'][0], 'customer_portal') !== false && strpos($l['headers']['location'][0], 'token=') === false, '…without a token in the URL');

echo "\n4. The portal and the API on the cookie; a URL token is ignored\n";
$p = $http('GET', '?page=customer_portal&view=home', null, [$C]);
t('the portal page with the cookie: 200', $p['code'], 200);
is_(stripos($p['body'], 'DishNet') !== false, '…renders the portal');
t('…Referrer-Policy: same-origin', $p['headers']['referrer-policy'][0] ?? null, 'same-origin');
t('…Cache-Control: no-store', $p['headers']['cache-control'][0] ?? null, 'no-store');
is_(strpos($p['body'], "_token: ''") !== false, '…the page embeds no token (_token is empty)');
is_(strpos($p['body'], $jwt1) === false, '…and the session token appears nowhere in the HTML');
$p = $http('GET', '?page=customer_portal&view=home');
t('the portal without a cookie: 302 to the login page', [$p['code'], strpos($p['headers']['location'][0] ?? '', 'customer_login') !== false], [302, true]);
$p = $http('GET', '?page=customer_portal&view=home&token=' . rawurlencode($jwt1));
t('the portal with the token in the URL and no cookie: 302 — the URL token is ignored', $p['code'], 302);
$r = $api('GET', 'app_me', null, [$C]);
t('app_me with the cookie (GET): 200', $r['code'], 200);
$r = $api('GET', 'app_me&token=' . rawurlencode($jwt1));
t('app_me with ?token= only: 401 Not signed in', [$r['code'], $r['json']['message'] ?? null], [401, 'Not signed in.']);
$r = $api('GET', 'app_me', null, ['Authorization: Bearer ' . $jwt1]);
t('app_me with the same token as Bearer (a native WebView): 200', $r['code'], 200);
$r = $api('GET', 'app_me', null, ['Cookie: dn_customer_token=' . $jwt1]);
t('the OLD cookie name is not an authentication: 401', $r['code'], 401);
$r = $api('GET', 'app_data_report_token', null, [$C]);
t('app_data_report_token with the cookie: 200', $r['code'], 200);
$dr = (string)($r['json']['data']['token'] ?? '');
$drh = json_decode(base64_decode(strtr(explode('.', $dr)[0] ?? '', '-_', '+/')), true) ?: [];
$drc = json_decode(base64_decode(strtr(explode('.', $dr)[1] ?? '', '-_', '+/')), true) ?: [];
is_($dr !== '' && !isset($drh['kid']) && ($drc['aud'] ?? '') === 'data-report' && (($drc['exp'] ?? 0) - ($drc['iat'] ?? 0)) === 600, 'the hand-off token: legacy shape (no kid), aud data-report, ten minutes');
$cfgNow = SqliteStore::create($data)->load('kyc_config.json');
$ok = false; try { JwtAuth::fromConfig($cfgNow)->verify($dr); $ok = true; } catch (\Throwable $e) {}
is_($ok, '…it verifies under the legacy derivation the other plugin has always been given');
$ok = true; try { JwtAuth::forCustomers($cfgNow)->verify($dr); } catch (\Throwable $e) { $ok = false; }
is_(!$ok, '…and is NOT a session token: the customer verifier refuses it');
$r = $api('GET', 'app_me', null, ['Authorization: Bearer ' . $dr]);
t('…nor does the API accept it as a session', $r['code'], 401);

echo "\n5. Logout ends the session for the API AND the portal\n";
$r = $api('POST', 'app_logout', ['x' => 1], [$C]);
t('logout by cookie without the page marker: 403', $r['code'], 403);
$r = $api('POST', 'app_logout', ['x' => 1], [$C, 'X-Requested-With: DishNet']);
t('logout from the page: 200 Logged out.', [$r['code'], $r['json']['message'] ?? null], [200, 'Logged out.']);
$cleared = $setCookie($r, CustomerSession::COOKIE);
is_($cleared !== null && (stripos($cleared, 'expires=') !== false || stripos($cleared, 'max-age=0') !== false), 'the server clears the session cookie', (string)$cleared);
$r = $api('GET', 'app_me', null, [$C]);
t('the old cookie on the API: 401 Token revoked', [$r['code'], $r['json']['message'] ?? null], [401, 'Token revoked.']);
$r = $api('GET', 'app_me', null, ['Authorization: Bearer ' . $jwt1]);
t('…and as Bearer', $r['code'], 401);
$p = $http('GET', '?page=customer_portal&view=home', null, [$C]);
t('the old cookie on the portal: 401 page', $p['code'], 401);
is_(strpos($p['body'], 'Session ended') !== false, '…saying the session ended');
$row = $pdo()->query("SELECT revoked_at, revoked_by FROM customer_sessions WHERE jti = " . $pdo()->quote((string)$cl['jti']))->fetch(\PDO::FETCH_ASSOC);
is_(!empty($row['revoked_at']) && $row['revoked_by'] === 'customer', 'the session row is revoked, by the customer');
$C2 = 'Cookie: ' . CustomerSession::COOKIE . '=' . $jwt2;
$r = $api('GET', 'app_me', null, [$C2]);
t('the SECOND session (from §2) is untouched by the first one\'s logout', $r['code'], 200);

echo "\n6. A native client gets the token in the body as well\n";
$in3 = $signIn(['X-DishNet-Client: android']);
t('verify with X-DishNet-Client: 200', $in3['verify']['code'], 200);
$bodyTok = $in3['verify']['json']['data']['token'] ?? null;
is_(is_string($bodyTok) && $bodyTok !== '', 'the JSON carries the token for a native client');
t('…session = body+cookie', $in3['verify']['json']['data']['session'] ?? null, 'body+cookie');
$r = $api('GET', 'app_me', null, ['Authorization: Bearer ' . $bodyTok]);
t('…and Bearer works for it', $r['code'], 200);

echo "\n7. Staff: sign a customer out everywhere\n";
$live = (int)$pdo()->query("SELECT COUNT(*) FROM customer_sessions WHERE client_id = 7 AND revoked_at IS NULL")->fetchColumn();
t('two sessions are live (the proxy one and the native one)', $live, 2);
$n = CustomerSession::revokeAll($pdo(), 7, 'staff:test');
t('revokeAll() ends both', $n, 2);
$r = $api('GET', 'app_me', null, ['Authorization: Bearer ' . $bodyTok]);
t('the native token: 401 Token revoked', [$r['code'], $r['json']['message'] ?? null], [401, 'Token revoked.']);
$r = $api('GET', 'app_me', null, [$C2]);
t('the proxy session: 401 too', $r['code'], 401);
$supp = codeNC($root . '/includes/api/api_customer_support.php');
is_(strpos($supp, "'staff_revoke_customer_sessions'") !== false && strpos($supp, 'CustomerSession::revokeAll(') !== false, 'the staff action exists, admin-only, on the same helper');
is_(strpos((string)file_get_contents($root . '/tabs/admin/app_logins.php'), 'staff_revoke_customer_sessions') !== false, 'and the Customer App Logins tab offers it');

echo "\n8. A stale legacy cookie is cleared by the login page\n";
$l = $http('GET', '?page=customer_login', null, ['Cookie: dn_customer_token=' . $jwt1]);
t('the login page renders for a stale legacy cookie (200)', $l['code'], 200);
$clr = $setCookie($l, 'dn_customer_token');
is_($clr !== null && (stripos($clr, 'expires=') !== false || stripos($clr, 'max-age=0') !== false), 'and clears dn_customer_token', json_encode($l['headers']['set-cookie'] ?? []));
is_(strpos($l['body'], '"phone" === \'consent\'') !== false, '…starting on the phone step');

echo "\n9. Source pins: no token in a URL, no token in the page, no URL fallback\n";
$portal = codeNC($root . '/tabs/customer_app/portal.php');
is_(strpos($portal, "u.searchParams.set('token'") === false, "portal.php no longer puts the token in navigation URLs");
is_(strpos($portal, "'&token=' + encodeURIComponent(DishNet._token") === false, '…nor in the PDF fallback link');
is_(strpos($portal, "pe(\$token)") === false, '…nor embeds it in the page');
is_(strpos($portal, "'X-Requested-With'] = 'DishNet'") !== false, '…and apiFetch marks its requests as the page\'s own');
$pd = codeNC($root . '/tabs/customer_app/portal_data.php');
is_(strpos($pd, "\$_GET['token']") === false && strpos($pd, 'CustomerSession::authenticate(') !== false, 'portal_data.php authenticates through CustomerSession and reads no URL token');
$app = codeNC($root . '/includes/api/api_customer_app.php');
is_(strpos($app, "\$_GET['token']") === false, 'the customer API reads no URL token');
is_(strpos($app, 'JwtAuth::fromConfig(') === false || substr_count($app, 'JwtAuth::fromConfig(') === 0, 'the customer API never verifies with the legacy derivation');
is_(substr_count($app, 'JwtAuth::legacySecret(') === 1, '…the legacy derivation is used once: the data-report hand-off');
is_(is_file($root . '/migrations/073_customer_sessions.sql'), 'migration 073 creates customer_sessions');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
