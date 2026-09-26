<?php
declare(strict_types=1);
/**
 * test_canonical_host.php — 5.18.41 (docs/38 A1.3): the customer pages answer on
 * ONE public address.
 *
 * Measured on the Uganda install (docs/37 §I.2, §J.2): the same plugin answers on
 * https://crm.dishnetuganda.com (Traefik, a trusted certificate) and on :8443
 * (UISP's listener, a self-signed certificate for "localhost"). A customer who
 * reaches the portal on :8443 sees a warning, and every relative link — the
 * invoice PDF included — inherits that origin. The generated links were fixed in
 * 5.18.34 (crm_public_url); the page's own origin was not.
 *
 * CanonicalHost::target() decides from arrays, so the rule is proved here
 * without a server; then the wiring is proved over real HTTP: a GET for a
 * customer page arriving with an explicit other port in Host is answered 302 to
 * the public address with the same path and query; page=api, POST, the native
 * wrapper's marker, the staff pages and another host name are never redirected;
 * with NO override (the South Sudan install) nothing is redirected at all; and
 * a request on the public address is never redirected, so no loop is possible.
 *
 * The scan in test_links_without_port.php looks for $_SERVER['HTTP_HOST'] read
 * outside a link resolver; CanonicalHost reads the array it is handed, so it
 * needs no exception there — and it builds no link, it compares.
 */
$pass = 0; $fail = 0;
function t(string $n, $got, $want): void { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d !== '' ? "\n       $d" : '') . "\n"; } }
$root = dirname(__DIR__);
if (!getenv('DN_VAULT_FILE')) putenv('DN_VAULT_FILE=' . tempnam(sys_get_temp_dir(), 'dn-vault-'));
require_once $root . '/lib/CanonicalHost.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';
/** The served copy is read through opcache (revalidated every 2 s): poll a condition for up to 5 s. */
function untilServed(callable $cond): bool { for ($i = 0; $i < 25; $i++) { if ($cond()) return true; usleep(200000); } return (bool)$cond(); }

echo "\n1. The rule, on arrays\n";
$cfg = ['crm_public_url' => 'https://crm.example'];
$srv = function (array $o = []): array { return array_merge(['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'crm.example:8443', 'REQUEST_URI' => '/crm/_plugins/dishnet-hybrid-sudan/public.php?page=customer_login&x=1'], $o); };
t('a GET for the sign-in page on :8443 → 302 target on the public address, same path and query',
  CanonicalHost::target($cfg, 'customer_login', $srv()), 'https://crm.example/crm/_plugins/dishnet-hybrid-sudan/public.php?page=customer_login&x=1');
foreach (['customer_portal', 'terms', 'privacy', 'customer_manifest'] as $pg) t("…$pg too", CanonicalHost::target($cfg, $pg, $srv()) !== null, true);
t('HEAD too', CanonicalHost::target($cfg, 'customer_login', $srv(['REQUEST_METHOD' => 'HEAD'])) !== null, true);
t('the public host with no port → nothing (a browser omits :443)', CanonicalHost::target($cfg, 'customer_login', $srv(['HTTP_HOST' => 'crm.example'])), null);
t('the public host with :443 → nothing', CanonicalHost::target($cfg, 'customer_login', $srv(['HTTP_HOST' => 'crm.example:443'])), null);
t('mixed case in Host → still the same host', CanonicalHost::target($cfg, 'customer_login', $srv(['HTTP_HOST' => 'CRM.Example:8443'])) !== null, true);
t('another host name on :8443 → nothing (not ours to redirect)', CanonicalHost::target($cfg, 'customer_login', $srv(['HTTP_HOST' => 'other.example:8443'])), null);
t('an alias without a port → nothing', CanonicalHost::target($cfg, 'customer_login', $srv(['HTTP_HOST' => 'www.crm.example'])), null);
t('page=api → never', CanonicalHost::target($cfg, 'api', $srv(['REQUEST_URI' => '/x/public.php?page=api&action=app_me'])), null);
t('the staff sign-in page → never', CanonicalHost::target($cfg, 'login', $srv()), null);
t('an unknown page → never', CanonicalHost::target($cfg, 'wa_webhook', $srv()), null);
t('POST → never', CanonicalHost::target($cfg, 'customer_login', $srv(['REQUEST_METHOD' => 'POST'])), null);
t('the native wrapper\'s marker → never', CanonicalHost::target($cfg, 'customer_portal', $srv(['HTTP_X_DISHNET_CLIENT' => 'android'])), null);
t('no override (the South Sudan install) → never', CanonicalHost::target([], 'customer_login', $srv()), null);
t('an unusable override (no scheme) → never', CanonicalHost::target(['crm_public_url' => 'crm.example'], 'customer_login', $srv()), null);
t('no Host header → never', CanonicalHost::target($cfg, 'customer_login', $srv(['HTTP_HOST' => ''])), null);
t('a Host that is not a host → never', CanonicalHost::target($cfg, 'customer_login', $srv(['HTTP_HOST' => 'crm.example:8443/evil'])), null);
$c2 = ['crm_public_url' => 'http://127.0.0.1:9812'];
t('an override with its own port: a request on another explicit port → the override', CanonicalHost::target($c2, 'customer_login', $srv(['HTTP_HOST' => '127.0.0.1:8443', 'REQUEST_URI' => '/public.php?page=customer_login'])), 'http://127.0.0.1:9812/public.php?page=customer_login');
t('…a request on the override\'s own port → nothing', CanonicalHost::target($c2, 'customer_login', $srv(['HTTP_HOST' => '127.0.0.1:9812'])), null);
t('…a request with no explicit port → nothing (only an explicit, different port is the :8443 shape)', CanonicalHost::target($c2, 'customer_login', $srv(['HTTP_HOST' => '127.0.0.1'])), null);
t('a REQUEST_URI without a leading slash is normalised', CanonicalHost::target($cfg, 'terms', $srv(['REQUEST_URI' => 'public.php?page=terms'])), 'https://crm.example/public.php?page=terms');
t('an IPv6 literal in Host is tolerated', CanonicalHost::target(['crm_public_url' => 'https://[::1]'], 'terms', $srv(['HTTP_HOST' => '[::1]:8443', 'REQUEST_URI' => '/p?page=terms'])), 'https://[::1]/p?page=terms');
t('the pages list is exactly the customer pages', CanonicalHost::PAGES, ['customer_login', 'customer_portal', 'terms', 'privacy', 'customer_manifest']);
// The property that makes a loop impossible: whatever the scheme detection says, a request whose Host carries
// no explicit port — the shape every request on the public origin has — is never redirected.
$never = true;
foreach ([[], ['HTTPS' => 'on'], ['HTTPS' => 'off'], ['HTTP_X_FORWARDED_PROTO' => 'https'], ['HTTP_X_FORWARDED_PROTO' => 'http'], ['SERVER_PORT' => '443'], ['SERVER_PORT' => '80'], ['SERVER_PORT' => '8443']] as $env) {
    if (CanonicalHost::target($cfg, 'customer_portal', $srv(array_merge(['HTTP_HOST' => 'crm.example'], $env))) !== null) $never = false;
}
t('a request on the public host with no explicit port is never redirected, under every scheme/port hint', $never, true);

echo "\n2. Over HTTP — first WITHOUT an override (the South Sudan install): nothing is redirected\n";
$sandbox = sys_get_temp_dir() . '/dn_ch_' . getmypid(); $tmp = $sandbox . '/plugin'; $data = $tmp . '/data';
exec('rm -rf ' . escapeshellarg($sandbox)); @mkdir($data, 0700, true);
register_shutdown_function(function () use ($sandbox) { exec('rm -rf ' . escapeshellarg($sandbox)); });
exec(sprintf('cp -R %s/. %s/ 2>/dev/null', escapeshellarg($root), escapeshellarg($tmp)));
exec('rm -rf ' . escapeshellarg($data)); @mkdir($data, 0700, true);
file_put_contents($tmp . '/ucrm.json', json_encode(['pluginDataDir' => $data]));
$store = SqliteStore::create($data);
$store->save('kyc_config.json', ['dry_run_mode' => true, 'data_dir' => $data, 'tenant_profile' => 'south-sudan', 'app_jwt_ttl_days' => 2]);
unset($store);
$nonce = bin2hex(random_bytes(8)); file_put_contents($tmp . '/__nonce.txt', $nonce);
$probe = function (string $url): ?string { $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2, CURLOPT_PROXY => '']);
    $r = curl_exec($ch); $c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); return ($r === false || $c !== 200) ? null : (string)$r; };
$srvp = null; $port = 0;
foreach (range(0, 9) as $slot) {
    $cand = 9650 + ((getmypid() + $slot * 47) % 200);
    $p = proc_open(sprintf('exec php -S 127.0.0.1:%d -t %s', $cand, escapeshellarg($tmp)), [1 => ['file', '/dev/null', 'w'], 2 => ['file', $sandbox . '/server.log', 'a']], $pipes);
    $ours = false;
    for ($i = 0; $i < 60; $i++) { $got = $probe("http://127.0.0.1:{$cand}/__nonce.txt"); if ($got !== null) { $ours = trim($got) === $nonce; break; } usleep(100000); }
    if ($ours) { $srvp = $p; $port = $cand; break; }
    proc_terminate($p); proc_close($p);
}
@unlink($tmp . '/__nonce.txt');
if ($srvp === null) { echo "  FAIL could not start the plugin under php -S\n"; printf("\n%d passed, %d failed\n", $pass, $fail + 1); exit(1); }
register_shutdown_function(function () use (&$srvp) { if (is_resource($srvp)) { proc_terminate($srvp); proc_close($srvp); } });
$base = "http://127.0.0.1:{$port}/public.php";
/** @return array{code:int,location:string,body:string,redirects:int} */
$req = function (string $method, string $query, array $headers = [], bool $follow = false) use ($base): array {
    $ch = curl_init($base . $query);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30, CURLOPT_PROXY => '', CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers), CURLOPT_FOLLOWLOCATION => $follow, CURLOPT_MAXREDIRS => 5]);
    if ($method === 'POST') curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');
    $r = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE); $n = (int)curl_getinfo($ch, CURLINFO_REDIRECT_COUNT); curl_close($ch);
    $hdr = substr($r, 0, $hs); $loc = preg_match('/^Location:\s*(.+)$/mi', $hdr, $m) ? trim($m[1]) : '';
    $cc = preg_match('/^Cache-Control:\s*(.+)$/mi', $hdr, $m2) ? trim($m2[1]) : '';
    return ['code' => $code, 'location' => $loc, 'body' => substr($r, $hs), 'redirects' => $n, 'cache' => $cc];
};
$ODD = ['Host: 127.0.0.1:8443'];
$r = $req('GET', '?page=customer_login', $ODD);
t('no override: the sign-in page on an odd port answers 200, no redirect', [$r['code'], $r['location']], [200, '']);
$r = $req('GET', '?page=customer_portal&view=home', $ODD);
is_($r['code'] === 302 && strpos($r['location'], 'page=customer_login') !== false && strpos($r['location'], '127.0.0.1') === false,
    'no override: the portal without a session still sends to the sign-in page (its own relative 302), not to any address', "{$r['code']} {$r['location']}");

echo "\n3. Over HTTP — WITH the override, as Uganda runs\n";
$store = SqliteStore::create($data);
$c = $store->load('kyc_config.json'); $c['crm_public_url'] = "http://127.0.0.1:{$port}"; $store->save('kyc_config.json', $c); unset($store);
$r = $req('GET', '?page=customer_login&next=home', $ODD);
t('the sign-in page on :8443 → 302', $r['code'], 302);
t('…to the public address, same path and query', $r['location'], "http://127.0.0.1:{$port}/public.php?page=customer_login&next=home");
t('…Cache-Control: no-store (a 302 is not to be cached)', $r['cache'], 'no-store');
is_(trim($r['body']) === '', '…with an empty body', substr($r['body'], 0, 80));
// Followed the way a browser follows: the next request goes to the Location with ITS host, not with the
// odd Host header re-sent (curl re-sends a custom Host on every hop, which is not what a browser does).
$loc = $r['location'];
$r2 = $req('GET', substr($loc, strlen($base)), ["Host: 127.0.0.1:{$port}"], true);
t('following the Location as a browser would lands on the public address: 200, and it redirects no further', [$r2['code'], $r2['redirects']], [200, 0]);
$r = $req('GET', '?page=customer_login', ["Host: 127.0.0.1:{$port}"], true);
t('a request already on the public address: 200, zero redirects (no loop)', [$r['code'], $r['redirects']], [200, 0]);
$r = $req('GET', '?page=customer_login', ['Host: 127.0.0.1'], true);
t('…and with no explicit port: 200, zero redirects', [$r['code'], $r['redirects']], [200, 0]);
foreach (['customer_portal&view=home', 'terms', 'privacy', 'customer_manifest'] as $q) {
    $r = $req('GET', "?page=$q", $ODD);
    t("?page=$q on :8443 → 302 to the public address", [$r['code'], strpos($r['location'], "http://127.0.0.1:{$port}/public.php?page=$q") === 0], [302, true]);
}
$r = $req('GET', '?page=api&action=app_legal_version', $ODD);
is_($r['code'] === 200 && strpos($r['body'], 'tos_version') !== false, 'page=api on :8443: answered, never redirected', "{$r['code']} {$r['location']}");
$r = $req('POST', '?page=api&action=app_send_otp', $ODD);
is_($r['code'] !== 302 && $r['location'] === '', 'a POST on :8443 is never redirected', "{$r['code']} {$r['location']}");
$r = $req('GET', '?page=customer_login', array_merge($ODD, ['X-DishNet-Client: android']));
t('the native wrapper on :8443: 200, no redirect', [$r['code'], $r['location']], [200, '']);
$r = $req('GET', '?page=login', $ODD);
is_($r['code'] !== 302 || strpos($r['location'], "http://127.0.0.1:{$port}") !== 0, 'the staff sign-in page is not the customer pages\' business', "{$r['code']} {$r['location']}");
$r = $req('GET', '?page=customer_login', ['Host: other.example:8443']);
t('another host name on :8443: 200, no redirect', [$r['code'], $r['location']], [200, '']);
$r = $req('GET', '?page=terms', ['Host: 127.0.0.1:8080']);
t(':8080 (UISP sends it to :8443 itself; if it ever reached us) → 302 to the public address', [$r['code'], $r['location']], [302, "http://127.0.0.1:{$port}/public.php?page=terms"]);

echo "\n4. The control on the control: the redirect comes from CanonicalHost and from nowhere else\n";
$pub = $tmp . '/public.php'; $src = (string)file_get_contents($pub);
t('the copy calls CanonicalHost::enforce once', substr_count($src, 'CanonicalHost::enforce('), 1);
file_put_contents($pub, str_replace('CanonicalHost::enforce(', '// CanonicalHost::enforce(', $src));
untilServed(function () use ($req, $ODD): bool { return $req('GET', '?page=customer_login', $ODD)['code'] === 200; });
$r = $req('GET', '?page=customer_login', $ODD);
t('with the call commented out in the copy, :8443 answers 200 again', [$r['code'], $r['location']], [200, '']);
file_put_contents($pub, $src);
untilServed(function () use ($req, $ODD): bool { return $req('GET', '?page=customer_login', $ODD)['code'] === 302; });
$r = $req('GET', '?page=customer_login', $ODD);
t('restored: 302 again', $r['code'], 302);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
