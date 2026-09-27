<?php
declare(strict_types=1);
/**
 * tests/test_customer_pwa.php — 5.18.40
 *
 * The customer portal can be added to a phone's home screen: it has its own
 * web-app manifest (?page=customer_manifest), both customer pages link it, and
 * the portal offers an "Install the DishNet app" row. Deliberately no service
 * worker for customers — a cache of signed-in pages on a shared phone is a data
 * exposure — and that decision is pinned here too.
 *
 * The plugin is served the way uCRM serves it, under /crm/_plugins/<slug>/, so
 * the manifest's start_url and scope are what a real phone would see.
 */
$pass = 0; $fail = 0;
function is_(bool $ok, string $what, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   $what\n"; }
    else     { $fail++; echo "  FAIL $what" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
function t(string $what, $got, $want): void { is_($got === $want, $what, 'got ' . var_export($got, true) . ', want ' . var_export($want, true)); }
if (!getenv('DN_VAULT_FILE')) putenv('DN_VAULT_FILE=' . tempnam(sys_get_temp_dir(), 'dn-vault-'));

$root    = dirname(__DIR__);
$sandbox = sys_get_temp_dir() . '/dn_pwa_' . getmypid();
$slug    = 'dishnet-hybrid-sudan';
$plugin  = "$sandbox/crm/_plugins/$slug";
$data    = "$sandbox/plugin-data";
exec('rm -rf ' . escapeshellarg($sandbox)); @mkdir($plugin, 0700, true); @mkdir($data, 0700, true);
register_shutdown_function(function () use ($sandbox) { exec('rm -rf ' . escapeshellarg($sandbox)); });
exec(sprintf('cp -R %s/. %s/ 2>/dev/null', escapeshellarg($root), escapeshellarg($plugin)));
exec('rm -rf ' . escapeshellarg("$plugin/data"));
file_put_contents("$plugin/ucrm.json", json_encode(['pluginDataDir' => $data, 'ucrmPublicUrl' => 'http://127.0.0.1/crm']));

$port = 0;
for ($try = 0; $try < 30; $try++) {
    $p = random_int(21000, 29000);
    $s = @stream_socket_server("tcp://127.0.0.1:$p", $errno, $errstr);
    if ($s) { fclose($s); $port = $p; break; }
}
is_($port > 0, 'a free port was found');
$srv = proc_open(sprintf('php -S 127.0.0.1:%d -t %s', $port, escapeshellarg($sandbox)), [1 => ['file', "$sandbox/server.log", 'a'], 2 => ['file', "$sandbox/server.log", 'a']], $pipes);
register_shutdown_function(function () use ($srv) { if (is_resource($srv)) { proc_terminate($srv); proc_close($srv); } });
$base = "http://127.0.0.1:$port/crm/_plugins/$slug/public.php";
$http = function (string $path, array $headers = []): array {
    // follow_location off: a 302 must be seen as a 302, not as the page it leads to
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 15, 'header' => implode("\r\n", $headers)]]);
    $body = @file_get_contents($path, false, $ctx);
    $status = 0; $ct = ''; $hdrs = $http_response_header ?? [];
    foreach ($hdrs as $h) { if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $status = (int)$m[1]; if (stripos($h, 'Content-Type:') === 0) $ct = trim(substr($h, 13)); }
    return [$status, (string)$body, $ct, $hdrs];
};
for ($i = 0; $i < 50; $i++) { [$st] = $http("$base?page=customer_login"); if ($st > 0) break; usleep(100000); }
is_($st > 0, 'the sandbox server answers', "status $st");

echo "\nThe manifest\n";
[$st, $body, $ct] = $http("$base?page=customer_manifest");
t('GET ?page=customer_manifest → 200 without any login', $st, 200);
is_(stripos($ct, 'application/manifest+json') === 0, 'served as application/manifest+json', $ct);
$m = json_decode($body, true);
is_(is_array($m), 'the body is JSON', substr($body, 0, 120));
$m = is_array($m) ? $m : [];
t('name', $m['name'] ?? null, 'DishNet');
t('short_name', $m['short_name'] ?? null, 'DishNet');
t('display standalone', $m['display'] ?? null, 'standalone');
t('start_url is the customer sign-in page under the plugin path', $m['start_url'] ?? null, "/crm/_plugins/$slug/public.php?page=customer_login");
t('scope is the plugin directory', $m['scope'] ?? null, "/crm/_plugins/$slug/");
is_(strpos((string)($m['start_url'] ?? ''), (string)($m['scope'] ?? '#')) === 0, 'start_url lies inside scope (an installability rule)');
t('id equals start_url', $m['id'] ?? null, $m['start_url'] ?? '?');
t('theme colour matches the pages', $m['theme_color'] ?? null, '#141414');
$icons = $m['icons'] ?? [];
$sizes = array_map(fn($i) => $i['sizes'] ?? '', is_array($icons) ? $icons : []);
is_(in_array('192x192', $sizes, true) && in_array('512x512', $sizes, true), 'icons declare 192x192 and 512x512', implode(',', $sizes));
foreach (is_array($icons) ? $icons : [] as $ic) {
    $src = (string)($ic['src'] ?? '');
    [$s2, $b2, $c2] = $http("http://127.0.0.1:$port" . $src);
    is_($s2 === 200 && stripos($c2, 'image/') === 0 && strlen($b2) > 100, "icon {$ic['sizes']} answers 200 as an image", "status $s2, type $c2");
}
is_(!preg_match('/secret|token|password|key/i', $body), 'the manifest carries no credential-shaped word');

echo "\nThe pages\n";
[$st, $login] = $http("$base?page=customer_login");
t('the sign-in page answers 200', $st, 200);
is_(strpos($login, '<link rel="manifest" href="?page=customer_manifest">') !== false, 'the sign-in page links the customer manifest');
is_(strpos($login, 'apple-mobile-web-app-capable') !== false, 'the sign-in page keeps the iOS web-app meta tag');
is_(strpos($login, 'serviceWorker') === false, 'the sign-in page registers no service worker (by decision)');
$portalSrc = (string)file_get_contents("$root/tabs/customer_app/portal.php");
is_(strpos($portalSrc, '<link rel="manifest" href="?page=customer_manifest">') !== false, 'the portal links the customer manifest');
is_(strpos($portalSrc, 'serviceWorker') === false, 'the portal registers no service worker (by decision)');
is_(strpos($portalSrc, 'Install the DishNet app') !== false && strpos($portalSrc, 'beforeinstallprompt') !== false && strpos($portalSrc, 'installApp()') !== false,
    'the portal offers an Install row driven by beforeinstallprompt, with an iOS hint');
is_(strpos($portalSrc, "window.Android || (window.matchMedia") !== false, 'the row stays hidden inside the Android wrapper and in a standalone window');
[$st, , , $ph] = $http("$base?page=customer_portal&view=home");
t('the portal without a session answers 302', $st, 302);
$locs = array_values(array_filter($ph, fn($h) => stripos($h, 'Location:') === 0));
is_($locs !== [] && strpos($locs[0], 'page=customer_login') !== false, '…to the sign-in page', implode(' | ', $locs));
[$st, $staff] = $http("$base?page=app_manifest");
$sm = json_decode($staff, true);
t('the staff manifest is unchanged: start_url', $sm['start_url'] ?? null, "/crm/_plugins/$slug/public.php?page=login");

echo "\nThe control: the served page is what the assertion reads\n";
$lw = "$plugin/tabs/customer_app/login_web.php"; $src = (string)file_get_contents($lw);
t('the copy carries the link once', substr_count($src, '<link rel="manifest" href="?page=customer_manifest">'), 1);
file_put_contents($lw, str_replace('<link rel="manifest" href="?page=customer_manifest">', '', $src));
[$st, $login2] = $http("$base?page=customer_login");
is_($st === 200 && strpos($login2, 'customer_manifest') === false, 'with the link removed from the copy, the served page no longer carries it');

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
