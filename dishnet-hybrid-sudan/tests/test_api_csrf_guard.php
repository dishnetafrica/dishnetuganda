<?php
declare(strict_types=1);
/**
 * test_api_csrf_guard.php — the PD-8 same-site guard on the staff JSON API
 * (docs/54). Two parts:
 *
 *   1. UNIT — StaffApiCsrf::mustBlock() over the full matrix: safe methods,
 *      the Bearer exemption, same-origin pass, cross-site block (by Sec-Fetch-Site
 *      and by Origin≠Host), missing/malformed/"null" Origin, and the Traefik
 *      hostname vs the UISP :8443 origin proving the two are NOT treated as
 *      interchangeable (each origin validates against its own Host — no static
 *      allow-list). Three weakened copies are each shown to differ from the real
 *      guard on a distinguishing input, so a regression would be caught.
 *
 *   2. OVER HTTP — the guard as wired into BOTH surfaces (public.php?page=api and
 *      public.php?page=stock_api): a Bearer integration is never blocked, a GET
 *      is never blocked, and a cookie-authenticated cross-site POST is refused
 *      403 cross_site — including the FormData / form / text-plain "simple
 *      request" cases that need no CORS preflight (docs/54). The decision is made
 *      from the server-side auth OUTCOME: an invalid Bearer that falls through to
 *      the cookie is still a cookie auth (blocked), a valid Bearer alongside a
 *      cookie is a Bearer auth (allowed).
 *
 * No secret and no real customer appears here; nothing beyond loopback.
 */
$pass = 0; $fail = 0;
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
require_once $root . '/lib/StaffApiCsrf.php';   // pulls in CustomerSession + TenantProfile

/** Set the three request signals crossSite() reads; null ⇒ header absent. */
function setReq(?string $sfs, ?string $origin, string $host): void {
    unset($_SERVER['HTTP_SEC_FETCH_SITE'], $_SERVER['HTTP_ORIGIN']);
    if ($sfs !== null)    $_SERVER['HTTP_SEC_FETCH_SITE'] = $sfs;
    if ($origin !== null) $_SERVER['HTTP_ORIGIN'] = $origin;
    $_SERVER['HTTP_HOST'] = $host;
}

// ═════════════════════════════════════════════════
echo "\n1. Unit: StaffApiCsrf::mustBlock()\n";
// ═════════════════════════════════════════════════
$H = 'crm.example';

// A cookie-authenticated request, by signal:
setReq(null, null, $H);                 is_(StaffApiCsrf::mustBlock(true, 'POST') === false, 'cookie POST, no Origin + no Sec-Fetch-Site → allowed (browser announces nothing)');
setReq('same-origin', null, $H);        is_(StaffApiCsrf::mustBlock(true, 'POST') === false, 'cookie POST, Sec-Fetch-Site: same-origin → allowed');
setReq('same-site', null, $H);          is_(StaffApiCsrf::mustBlock(true, 'POST') === false, 'cookie POST, Sec-Fetch-Site: same-site → allowed');
setReq('cross-site', null, $H);         is_(StaffApiCsrf::mustBlock(true, 'POST') === true,  'cookie POST, Sec-Fetch-Site: cross-site → blocked');
setReq(null, "https://$H", $H);         is_(StaffApiCsrf::mustBlock(true, 'POST') === false, 'cookie POST, Origin == Host → allowed');
setReq(null, 'https://evil.example', $H); is_(StaffApiCsrf::mustBlock(true, 'POST') === true, 'cookie POST, Origin host ≠ Host → blocked');
setReq(null, 'null', $H);               is_(StaffApiCsrf::mustBlock(true, 'POST') === false, 'cookie POST, Origin: null (opaque) → allowed (no host signal)');
setReq(null, 'not-a-url', $H);          is_(StaffApiCsrf::mustBlock(true, 'POST') === true,  'cookie POST, malformed Origin (no parseable host) → blocked (fails safe)');

// Safe / idempotent methods are never blocked, even with hostile signals:
setReq('cross-site', 'https://evil.example', $H);
is_(StaffApiCsrf::mustBlock(true, 'GET')     === false, 'cookie GET cross-site → allowed (safe method)');
is_(StaffApiCsrf::mustBlock(true, 'HEAD')    === false, 'cookie HEAD cross-site → allowed (safe method)');
is_(StaffApiCsrf::mustBlock(true, 'OPTIONS') === false, 'cookie OPTIONS cross-site → allowed (preflight)');

// Bearer is exempt, even for a cross-site POST (integrations keep working):
setReq('cross-site', 'https://evil.example', $H);
is_(StaffApiCsrf::mustBlock(false, 'POST') === false, 'Bearer (not cookie) cross-site POST → allowed (not exposed to CSRF)');

// Method is case-insensitive:
setReq('cross-site', null, $H);
is_(StaffApiCsrf::mustBlock(true, 'post') === true,  'method is case-insensitive (post blocked)');
is_(StaffApiCsrf::mustBlock(true, 'Get')  === false, 'method is case-insensitive (Get allowed)');

// ── The Traefik hostname vs the UISP :8443 origin are NOT interchangeable ──
// Each Origin validates against the request's OWN Host; there is no allow-list.
echo "\n   Traefik hostname vs UISP :8443 origin (non-interchangeable):\n";
$traefik = 'crm.dishnetuganda.com'; $uisp = '1.2.3.4:8443';
setReq(null, "https://$traefik", $traefik);        is_(StaffApiCsrf::mustBlock(true, 'POST') === false, '   Host=Traefik, Origin=Traefik → allowed (own origin)');
setReq(null, "https://$uisp", $traefik);           is_(StaffApiCsrf::mustBlock(true, 'POST') === true,  '   Host=Traefik, Origin=:8443  → blocked (NOT interchangeable)');
setReq(null, "https://$uisp", $uisp);              is_(StaffApiCsrf::mustBlock(true, 'POST') === false, '   Host=:8443,   Origin=:8443  → allowed (own origin)');
setReq(null, "https://$traefik", $uisp);           is_(StaffApiCsrf::mustBlock(true, 'POST') === true,  '   Host=:8443,   Origin=Traefik → blocked (NOT interchangeable)');

// ── Weakened copies: each would diverge from the real guard on one input ──
echo "\n   Weakened copies are distinguishable (a regression would be caught):\n";
// mutant A — ignores the cookie outcome (always runs crossSite): would block Bearer.
$mutA = fn(bool $c, string $m): bool => in_array(strtoupper($m), ['GET','HEAD','OPTIONS'], true) ? false : CustomerSession::crossSite();
setReq('cross-site', 'https://evil.example', $H);
is_(StaffApiCsrf::mustBlock(false, 'POST') === false && $mutA(false, 'POST') === true,
    '   mutant A (ignores the cookie outcome) wrongly blocks a Bearer cross-site POST');
// mutant B — only Sec-Fetch-Site, drops the Origin comparison: misses an Origin-only cross-site.
$mutB = function (bool $c, string $m): bool {
    if (in_array(strtoupper($m), ['GET','HEAD','OPTIONS'], true)) return false;
    if (!$c) return false;
    return strtolower(trim((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''))) === 'cross-site';
};
setReq(null, 'https://evil.example', $H);
is_(StaffApiCsrf::mustBlock(true, 'POST') === true && $mutB(true, 'POST') === false,
    '   mutant B (only Sec-Fetch-Site) misses an Origin-only cross-site write');
// mutant C — guards safe methods too: would block a cross-site GET navigation.
$mutC = fn(bool $c, string $m): bool => !$c ? false : CustomerSession::crossSite();
setReq('cross-site', null, $H);
is_(StaffApiCsrf::mustBlock(true, 'GET') === false && $mutC(true, 'GET') === true,
    '   mutant C (guards GET too) would block cross-site navigations');

// ── Source wiring: the guard is on BOTH surfaces, after the auth outcome, and is tenant-blind ──
echo "\n   Source wiring and scope:\n";
$apiH   = codeNC($root . '/includes/api_handlers.php');
$routes = codeNC($root . '/includes/routes.php');
$guard  = codeNC($root . '/lib/StaffApiCsrf.php');
is_(strpos($apiH, 'StaffApiCsrf::mustBlock($authedViaCookie, $met)') !== false, 'api_handlers.php calls the guard with the cookie outcome');
is_(strpos($routes, 'StaffApiCsrf::mustBlock($authedViaCookie, $met)') !== false, 'routes.php (stock_api) calls the guard with the cookie outcome');
// the guard sits AFTER the staff auth, so the pre-auth customer-app actions never reach it
$anchor = strpos($apiH, '$me2 = $auth->tokenAuth();');
$call   = strpos($apiH, 'StaffApiCsrf::mustBlock');
is_($anchor !== false && $call !== false && $call > $anchor, 'the guard runs after the staff auth (pre-auth customer-app path is untouched)');
// api_handlers sets the flag from the SESSION branch, not from a header
is_(strpos($apiH, '$authedViaCookie = true;') !== false && strpos($apiH, '$authedViaCookie = false;') !== false,
    'api_handlers.php derives $authedViaCookie from the auth outcome (false, then true only in the session branch)');
is_(strpos($routes, '$authedViaCookie = true;') !== false && strpos($routes, '$authedViaCookie = false;') !== false,
    'routes.php derives $authedViaCookie from the auth outcome');
// the guard carries no tenant logic — so it cannot change Uganda vs South Sudan behaviour
foreach (['TenantProfile', 'uganda', 'south', 'tenant_profile'] as $needle) {
    is_(stripos($guard, $needle) === false, "the guard references no tenant marker ($needle) — identical for Uganda and South Sudan");
}
// CORS policy is NOT changed by this work package (docs/54 marks it a separate follow-up):
// the guard itself emits no header, and the pre-existing CORS line is left exactly as it was.
is_(strpos($guard, 'header(') === false && stripos($guard, 'Access-Control') === false,
    'the guard emits no header and no CORS directive (pure same-site logic)');
is_(strpos($apiH, 'Access-Control-Allow-Origin: *') !== false,
    'the pre-existing CORS `*` on page=api is left unchanged (CORS is a separate follow-up)');
is_(strpos($routes, 'Access-Control-Allow-Origin: *') !== false,
    'the pre-existing CORS `*` on stock_api is left unchanged (CORS is a separate follow-up)');

// ═════════════════════════════════════════════════
echo "\n2. Over HTTP: both surfaces, Bearer vs cookie, same-origin vs cross-site\n";
// ═════════════════════════════════════════════════
$sandbox = sys_get_temp_dir() . '/dn_csrf_' . getmypid();
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
$pw       = 'csrf-test-pw-' . bin2hex(random_bytes(6));
$store->appendWithId('retailers.json', ['name' => 'CSRF Admin', 'email' => 'admin@example.test', 'phone' => '+256700000001',
    'is_active' => true, 'is_admin' => true, 'role' => 'admin', 'api_token' => $adminTok, 'token_issued_at' => time(),
    'password' => password_hash($pw, PASSWORD_BCRYPT, ['cost' => 4])]);
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
    $cand = 9500 + ((getmypid() + $slot * 37) % 250);
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

/** @return array{code:int,headers:string,body:string,json:?array} */
$http = function (string $method, string $query, array $headers = [], ?string $rawBody = null) use ($base): array {
    $ch = curl_init($base . '?' . $query);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_PROXY => '', CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => false]);
    if ($rawBody !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $rawBody);
    $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    $raw = is_string($r) ? $r : '';
    $hdr = substr($raw, 0, $hs); $bodyStr = substr($raw, $hs);
    $j = json_decode($bodyStr, true);
    return ['code' => $r === false ? 0 : $code, 'headers' => $hdr, 'body' => $bodyStr, 'json' => is_array($j) ? $j : null];
};

// Sign in the staff member over HTTP (do_login is exempt from the page-POST CSRF
// gate and does NOT touch the JSON-API guard — a different page). Capture PHPSESSID
// from the Set-Cookie header and replay it manually, which also side-steps curl's
// refusal to send a Secure cookie over plain HTTP.
$lg = $http('POST', 'page=login', ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query(['action' => 'do_login', 'identifier' => 'admin@example.test', 'password' => $pw]));
preg_match_all('/Set-Cookie:\s*PHPSESSID=([^;\s]+)/i', $lg['headers'], $cm);
$sid = $cm[1][0] ?? '';
is_($sid !== '', 'obtained a staff session cookie via do_login', 'login code ' . $lg['code'] . ' headers: ' . substr($lg['headers'], 0, 300));
$cookieHdr = 'Cookie: PHPSESSID=' . $sid;

$evil = ['Origin: https://evil.example', 'Sec-Fetch-Site: cross-site'];
$same = ['Origin: http://127.0.0.1:' . $port, 'Sec-Fetch-Site: same-origin'];

foreach (['page=api' => ['no_such_action_ever', 'Unknown API action'],
          'page=stock_api' => ['no_such_stock_action', 'Unknown stock action']] as $pg => [$act, $unknownMsg]) {
    echo "\n   surface: $pg\n";
    // Bearer + cross-site POST → NOT blocked; reaches the dispatcher (integrations keep working).
    $r = $http('POST', "$pg&action=$act", array_merge(['Authorization: Bearer ' . $adminTok, 'Content-Type: application/json'], $evil), '{}');
    is_($r['code'] !== 403 && ($r['json']['message'] ?? '') !== 'cross_site',
        "   Bearer cross-site POST is NOT blocked", $r['code'] . ' ' . substr($r['body'], 0, 140));
    // cookie + cross-site POST → 403 cross_site.
    $r = $http('POST', "$pg&action=$act", array_merge([$cookieHdr, 'Content-Type: application/json'], $evil), '{}');
    is_($r['code'] === 403 && ($r['json']['message'] ?? '') === 'cross_site',
        "   cookie cross-site POST → 403 cross_site", $r['code'] . ' ' . substr($r['body'], 0, 160));
    // cookie + same-origin POST → NOT blocked; reaches the dispatcher (404, not 401 — the cookie authenticated AND the guard passed).
    $r = $http('POST', "$pg&action=$act", array_merge([$cookieHdr, 'Content-Type: application/json'], $same), '{}');
    is_($r['code'] === 404 && strpos((string)($r['json']['message'] ?? ''), $unknownMsg) !== false,
        "   cookie same-origin POST passes to the dispatcher (404 unknown, not 401/403)", $r['code'] . ' ' . substr($r['body'], 0, 160));
    // GET + cookie + cross-site → NOT blocked (safe method).
    $r = $http('GET', "$pg&action=$act", array_merge([$cookieHdr], $evil));
    is_($r['code'] !== 403 && ($r['json']['message'] ?? '') !== 'cross_site',
        "   GET cookie cross-site is NOT blocked (safe method)", $r['code'] . ' ' . substr($r['body'], 0, 140));
}

echo "\n   'simple request' content-types (no CORS preflight) are still blocked (docs/54):\n";
foreach (['application/x-www-form-urlencoded', 'multipart/form-data; boundary=----x', 'text/plain;charset=UTF-8'] as $ct) {
    $r = $http('POST', 'page=api&action=no_such_action_ever', array_merge([$cookieHdr, 'Content-Type: ' . $ct], $evil), 'x=1');
    is_($r['code'] === 403 && ($r['json']['message'] ?? '') === 'cross_site',
        "   cookie cross-site POST ($ct) → 403 cross_site", $r['code'] . ' ' . substr($r['body'], 0, 120));
}

echo "\n   the decision is the auth OUTCOME, not the header's presence:\n";
// invalid Bearer + valid cookie + cross-site POST → still a cookie auth → blocked.
$r = $http('POST', 'page=api&action=no_such_action_ever',
           array_merge([$cookieHdr, 'Authorization: Bearer not-a-real-token', 'Content-Type: application/json'], $evil), '{}');
is_($r['code'] === 403 && ($r['json']['message'] ?? '') === 'cross_site',
    "   invalid Bearer that falls through to the cookie is a cookie auth → blocked", $r['code'] . ' ' . substr($r['body'], 0, 140));
// valid Bearer alongside a cookie → Bearer auth → NOT blocked.
$r = $http('POST', 'page=api&action=no_such_action_ever',
           array_merge([$cookieHdr, 'Authorization: Bearer ' . $adminTok, 'Content-Type: application/json'], $evil), '{}');
is_($r['code'] !== 403 && ($r['json']['message'] ?? '') !== 'cross_site',
    "   valid Bearer alongside a cookie is a Bearer auth → NOT blocked", $r['code'] . ' ' . substr($r['body'], 0, 140));

echo "\n   missing / malformed Origin over HTTP:\n";
// cookie POST, neither Origin nor Sec-Fetch-Site → allowed (a browser that announces nothing).
$r = $http('POST', 'page=api&action=no_such_action_ever', [$cookieHdr, 'Content-Type: application/json'], '{}');
is_($r['code'] !== 403, '   cookie POST with no Origin and no Sec-Fetch-Site → not blocked', (string)$r['code'] . ' ' . substr($r['body'], 0, 120));
// cookie POST, Origin: null → allowed (opaque).
$r = $http('POST', 'page=api&action=no_such_action_ever', [$cookieHdr, 'Content-Type: application/json', 'Origin: null'], '{}');
is_($r['code'] !== 403, '   cookie POST with Origin: null → not blocked (opaque origin)', (string)$r['code']);
// cookie POST, malformed Origin (no host) → blocked (fails safe).
$r = $http('POST', 'page=api&action=no_such_action_ever', [$cookieHdr, 'Content-Type: application/json', 'Origin: not-a-url'], '{}');
is_($r['code'] === 403 && ($r['json']['message'] ?? '') === 'cross_site', '   cookie POST with a malformed Origin → 403 cross_site (fails safe)', (string)$r['code'] . ' ' . substr($r['body'], 0, 120));

// anonymous (no auth) cross-site POST → the 401 guard still fires first, never 403.
$r = $http('POST', 'page=api&action=no_such_action_ever', array_merge(['Content-Type: application/json'], $evil), '{}');
is_($r['code'] === 401, '   anonymous cross-site POST is 401 (the auth guard, not the CSRF guard)', (string)$r['code'] . ' ' . substr($r['body'], 0, 120));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
