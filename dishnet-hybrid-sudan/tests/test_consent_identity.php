<?php
declare(strict_types=1);
/**
 * test_consent_identity.php — 5.18.41 (docs/38 A1.2): consent belongs to the
 * customer, not to the sign-in identifier.
 *
 * Measured on the Uganda install (docs/37 §D.15, §I.5): the same customer
 * accepted the terms by phone and was sent to the consent step again on the
 * e-mail route, because app_tos_consent was looked up by identifier only
 * although every row already carries crm_client_id.
 *
 * Now CustomerSession::hasCurrentConsent($pdo, $identifier, $clientId, $UG) is true
 * for a row at the current versions that names the identifier OR the customer.
 * Why that is not a bypass: a row is written only by app_record_consent, which
 * needs a session the OTP just proved for that very customer (5.18.37), so
 * sharing by customer id shares consent only between identities that have each
 * proved they are that customer. A row for another customer never admits this
 * one; a version bump asks every route again; with no client id the rule is the
 * old one, so the multi-account identifier case is unchanged.
 *
 * Proved on the function directly, then over real HTTP through the portal and
 * the login page: the phone route consents, the e-mail route of the SAME
 * customer (a session issued by the same issuer the login uses) passes; another
 * customer does not; a version bump re-asks both.
 */
$pass = 0; $fail = 0;
function t(string $n, $got, $want): void { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d !== '' ? "\n       $d" : '') . "\n"; } }
$root = dirname(__DIR__);
if (!getenv('DN_VAULT_FILE')) putenv('DN_VAULT_FILE=' . tempnam(sys_get_temp_dir(), 'dn-vault-'));
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/JwtAuth.php';
require_once $root . '/lib/CustomerJwtKeys.php';
require_once $root . '/lib/CustomerSession.php';
require_once $root . '/lib/LegalContent.php';
require_once $root . '/lib/ClientSearchIndex.php';
require_once $root . '/lib/TenantProfile.php';
// 5.18.42 (docs/38 A2): the versions are the TENANT's, so the check takes the profile. The HTTP sandbox below is
// the uganda profile; part 1 judges the same rows under both profiles.
$UG = TenantProfile::load('uganda'); $SS = TenantProfile::load('south-sudan');
$ver = dnLegalVersion($UG);
/** The served copy is read through opcache (revalidated every 2 s): poll a condition for up to 5 s. */
function untilServed(callable $cond): bool { for ($i = 0; $i < 25; $i++) { if ($cond()) return true; usleep(200000); } return (bool)$cond(); }

echo "\n1. The rule, on the function\n";
$pdo = new \PDO('sqlite::memory:');
$pdo->exec("CREATE TABLE app_tos_consent (phone TEXT NOT NULL, tos_version TEXT NOT NULL, privacy_version TEXT NOT NULL, accepted_at INTEGER NOT NULL, ip TEXT, crm_client_id INTEGER, PRIMARY KEY (phone, tos_version, privacy_version))");
$ins = $pdo->prepare("INSERT INTO app_tos_consent VALUES (?,?,?,?,?,?)");
$ins->execute(['+256772123456', $ver['tos'], $ver['privacy'], time(), '127.0.0.1', 7]);   // customer 7 accepted by phone
$ins->execute(['old.customer@example.test', '0.9', '0.9', time() - 86400, '127.0.0.1', 8]); // customer 8 accepted an OLD version
$ins->execute(['legacy@example.test', $ver['tos'], $ver['privacy'], time(), '127.0.0.1', null]); // a pre-5.18.37 row with no client id
t('the phone identifier that accepted → true (no client id given: the old rule)', CustomerSession::hasCurrentConsent($pdo, '+256772123456', 0, $UG), true);
t('the e-mail identifier of the SAME customer → true by client id', CustomerSession::hasCurrentConsent($pdo, 'mail.customer@example.test', 7, $UG), true);
t('…and false without the client id (the old rule, unchanged)', CustomerSession::hasCurrentConsent($pdo, 'mail.customer@example.test', 0, $UG), false);
t('another customer\'s identifier with ITS id → false: customer 7\'s row does not admit customer 8', CustomerSession::hasCurrentConsent($pdo, 'other@example.test', 8, $UG), false);
t('customer 8 accepted an old version → false (a version bump re-asks)', CustomerSession::hasCurrentConsent($pdo, 'old.customer@example.test', 8, $UG), false);
t('a row without a client id still counts for its identifier', CustomerSession::hasCurrentConsent($pdo, 'legacy@example.test', 9, $UG), true);
t('…and never for a customer id alone', CustomerSession::hasCurrentConsent($pdo, 'nobody@example.test', 9, $UG), false);
t('an empty identifier and no client id → false', CustomerSession::hasCurrentConsent($pdo, '', 0, $UG), false);
t('an empty identifier with a client id that accepted → true', CustomerSession::hasCurrentConsent($pdo, '', 7, $UG), true);
t('a zero client id is never matched (rows without a client id hold NULL, not 0)', CustomerSession::hasCurrentConsent($pdo, 'x', 0, $UG), false);
$pdo->exec("UPDATE app_tos_consent SET crm_client_id = 0 WHERE phone = 'legacy@example.test'");
t('…even if a row held 0', CustomerSession::hasCurrentConsent($pdo, 'x', 0, $UG), false);

echo "\n1b. The versions are the tenant's (5.18.42, docs/38 A2): the same rows, judged under each profile\n";
$vSS = dnLegalVersion($SS);
t('the profiles carry different versions (uganda 1.1, south-sudan 1.0)', [$ver['tos'], $ver['privacy'], $vSS['tos'], $vSS['privacy']], ['1.1', '1.1', '1.0', '1.0']);
t('customer 7 accepted uganda\'s current version → true under the uganda profile', CustomerSession::hasCurrentConsent($pdo, '+256772123456', 7, $UG), true);
t('…the same row judged under south-sudan (1.0) → false: a row never satisfies another tenant\'s version', CustomerSession::hasCurrentConsent($pdo, '+256772123456', 7, $SS), false);
$ins->execute(['+211927000555', $vSS['tos'], $vSS['privacy'], time(), '127.0.0.1', 11]);   // a South Sudan customer at 1.0
t('a 1.0 row → true under south-sudan (nobody there is asked again)', CustomerSession::hasCurrentConsent($pdo, '+211927000555', 11, $SS), true);
t('…and false under uganda (1.1): every Uganda customer is asked once more', CustomerSession::hasCurrentConsent($pdo, '+211927000555', 11, $UG), false);

echo "\n2. Over HTTP: the phone route consents, the e-mail route of the same customer passes, another customer does not\n";
// The copy keeps the plugin's directory NAME: a customer token's issuer is derived from it, and the test
// issues e-mail-route tokens in-process with the same issuer the server uses.
$sandbox = sys_get_temp_dir() . '/dn_ci_' . getmypid(); $tmp = $sandbox . '/dishnet-hybrid-sudan'; $data = $tmp . '/data';
exec('rm -rf ' . escapeshellarg($sandbox)); @mkdir($data, 0700, true);
register_shutdown_function(function () use ($sandbox) { exec('rm -rf ' . escapeshellarg($sandbox)); });
exec(sprintf('cp -R %s/. %s/ 2>/dev/null', escapeshellarg($root), escapeshellarg($tmp)));
exec('rm -rf ' . escapeshellarg($data)); @mkdir($data, 0700, true);
file_put_contents($tmp . '/ucrm.json', json_encode(['pluginDataDir' => $data]));
$PHONE = '+256772123456'; $EMAIL = 'mail.customer@example.test'; $PHONE8 = '+256772999888';
$store = SqliteStore::create($data);
$store->save('kyc_config.json', ['dry_run_mode' => true, 'data_dir' => $data, 'tenant_profile' => 'uganda',
    'wa_plugin_url' => 'http://127.0.0.1:1/', 'wa_app_key' => 'k', 'wa_auth_key' => 'a', 'app_jwt_ttl_days' => 2]);
ClientSearchIndex::upsertClient($store, ['id' => 7, 'firstName' => 'Test', 'lastName' => 'Customer', 'isLead' => false, 'isArchived' => false, 'isActive' => true, 'clientType' => 1, 'contacts' => [['phone' => $PHONE, 'email' => $EMAIL]]]);
ClientSearchIndex::upsertClient($store, ['id' => 8, 'firstName' => 'Other', 'lastName' => 'Customer', 'isLead' => false, 'isArchived' => false, 'isActive' => true, 'clientType' => 1, 'contacts' => [['phone' => $PHONE8]]]);
$store->save('ucrm_clients_cache.json', [['id' => 7, 'firstName' => 'Test', 'lastName' => 'Customer', 'contacts' => [['phone' => $PHONE, 'email' => $EMAIL]]], ['id' => 8, 'firstName' => 'Other', 'lastName' => 'Customer', 'contacts' => [['phone' => $PHONE8]]]]);
unset($store);
$nonce = bin2hex(random_bytes(8)); file_put_contents($tmp . '/__nonce.txt', $nonce);
$probe = function (string $url): ?string { $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2, CURLOPT_PROXY => '']);
    $r = curl_exec($ch); $c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); return ($r === false || $c !== 200) ? null : (string)$r; };
$srv = null; $port = 0;
foreach (range(0, 9) as $slot) {
    $cand = 9400 + ((getmypid() + $slot * 43) % 240);
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
$dryCode = function () use ($data): string {
    $log = json_decode((string)@file_get_contents($data . '/dry_run_notification_log.json'), true) ?: [];
    return preg_match('/\b(\d{6})\b/', json_encode(end($log)), $m) ? $m[1] : '';
};
$cookieOf = function (array $r): string { foreach ($r['headers']['set-cookie'] ?? [] as $c) if (strpos($c, CustomerSession::COOKIE . '=') === 0) return (string)preg_replace('/^[^=]+=([^;]*).*$/', '$1', $c); return ''; };
$signInByPhone = function (string $phone) use ($api, $dryCode, $cookieOf): array {
    $s = $api('POST', 'app_send_otp', ['phone' => $phone]);
    $v = $api('POST', 'app_verify_otp', ['phone' => $phone, 'code' => $dryCode()]);
    return ['verify' => $v, 'cookie' => $cookieOf($v)];
};
/** A session for another identity of a customer, issued by the same issuer the login uses (the e-mail route has no dry-run capture). */
$mint = function (int $sub, string $identifier, string $mode) use ($data): string {
    $cfg = SqliteStore::create($data)->load('kyc_config.json');
    $jwt = JwtAuth::forCustomers($cfg, 7200);
    $token = $jwt->issue(['sub' => $sub, 'kind' => 'app', 'phone' => $identifier, 'login_mode' => $mode, 'name' => 'Test Customer',
        'accounts' => [['id' => $sub, 'name' => 'Test Customer', 'status' => '', 'plans' => '']]]);
    CustomerSession::record(SqliteStore::create($data)->getPdo(), $jwt->decode($token) ?? [], $jwt->kid(), '127.0.0.1', 'test');
    return $token;
};
$portal = function (string $jwt) use ($http): array { return $http('GET', '?page=customer_portal&view=home', null, ['Cookie: ' . CustomerSession::COOKIE . '=' . $jwt]); };
$loginPage = function (string $jwt) use ($http): array { return $http('GET', '?page=customer_login', null, ['Cookie: ' . CustomerSession::COOKIE . '=' . $jwt]); };
$toConsent = function (array $r): bool { return $r['code'] === 302 && strpos($r['headers']['location'][0] ?? '', 'step=consent') !== false; };

$in = $signInByPhone($PHONE);
t('phone sign-in: 200', $in['verify']['code'], 200);
t('…needs_consent true for a first-time customer', $in['verify']['json']['data']['needs_consent'] ?? null, true);
$jwtPhone = $in['cookie'];
is_($toConsent($portal($jwtPhone)), 'the portal sends the phone route to the consent step before any consent');
$jwtMail = $mint(7, $EMAIL, 'email');
is_($toConsent($portal($jwtMail)), '…and the e-mail route of the same customer too (no consent anywhere yet)');
t('the login page starts the e-mail session on the consent step (200, no redirect to the portal)', $loginPage($jwtMail)['code'], 200);

$r = $api('POST', 'app_record_consent', ['tos_version' => $ver['tos'], 'privacy_version' => $ver['privacy']],
    ['Cookie: ' . CustomerSession::COOKIE . '=' . $jwtPhone, 'X-Requested-With: DishNet', "Origin: http://127.0.0.1:{$port}"]);
t('the phone route accepts the terms (200)', [$r['code'], $r['json']['data']['accepted'] ?? null], [200, true]);
$rows = SqliteStore::create($data)->getPdo()->query("SELECT phone, crm_client_id FROM app_tos_consent")->fetchAll(\PDO::FETCH_ASSOC);
t('one row: the phone identifier, customer 7', $rows, [['phone' => $PHONE, 'crm_client_id' => 7]]);
t('the portal opens for the phone route (200)', $portal($jwtPhone)['code'], 200);
t('THE FIX: the portal opens for the e-mail route of the same customer without asking again (200)', $portal($jwtMail)['code'], 200);
$l = $loginPage($jwtMail);
is_($l['code'] === 302 && strpos($l['headers']['location'][0] ?? '', 'customer_portal') !== false, 'the login page sends the e-mail session straight to the portal (302)', "{$l['code']} " . ($l['headers']['location'][0] ?? ''));
$again = $signInByPhone($PHONE);
t('a fresh phone sign-in reports needs_consent false', $again['verify']['json']['data']['needs_consent'] ?? null, false);
$rows = SqliteStore::create($data)->getPdo()->query("SELECT COUNT(*) FROM app_tos_consent")->fetchColumn();
t('still one consent row — nothing was written for the e-mail route', (int)$rows, 1);

echo "\n3. Another customer is not admitted by customer 7's row\n";
$jwt8 = $mint(8, $PHONE8, 'phone');
is_($toConsent($portal($jwt8)), 'customer 8\'s session is sent to the consent step');
$in8 = $signInByPhone($PHONE8);
t('…and a real sign-in for customer 8 reports needs_consent true', $in8['verify']['json']['data']['needs_consent'] ?? null, true);

echo "\n4. A version bump re-asks every route (the control: the shared row is version-bound)\n";
// 5.18.42 (docs/38 A2): the version is the TENANT's, in its profile — bumping the served copy's uganda profile
// is what a wording change does in production, and it must re-ask both routes of this Uganda customer.
$lc = $tmp . '/profiles/uganda.json'; $src = (string)file_get_contents($lc);
t('the sandbox copy\'s uganda profile carries the current Terms version once', substr_count($src, '"tos": "' . $ver['tos'] . '"'), 1);
file_put_contents($lc, str_replace('"tos": "' . $ver['tos'] . '"', '"tos": "9.9"', $src));
untilServed(function () use ($toConsent, $portal, $jwtPhone): bool { return $toConsent($portal($jwtPhone)); });
$bp = $portal($jwtPhone); $bm = $portal($jwtMail);
is_($toConsent($bp) && $toConsent($bm), 'with the Terms version bumped in the tenant\'s profile, both routes are asked again', "phone {$bp['code']} " . ($bp['headers']['location'][0] ?? '') . " | mail {$bm['code']} " . ($bm['headers']['location'][0] ?? ''));
$lv = $api('GET', 'app_legal_version', null);
t('…and app_legal_version reports the bumped tenant version to the login page', $lv['json']['data']['tos_version'] ?? null, '9.9');
file_put_contents($lc, $src);
untilServed(function () use ($portal, $jwtMail): bool { return $portal($jwtMail)['code'] === 200; });
t('restored: the e-mail route passes again', $portal($jwtMail)['code'], 200);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
