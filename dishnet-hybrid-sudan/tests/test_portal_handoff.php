<?php
declare(strict_types=1);
/**
 * test_portal_handoff.php — 5.18.73: the customer portal hands a customer over to the dishnet-data-report plugin's
 * client view only where the TENANT has that hand-off, and never without a token the other plugin can verify.
 *
 * Measured on the Uganda install, 4 Oct 2026: the portal's "Usage details" links minted a ten-minute hand-off token signed
 * with sha256(webhook_secret | crm_auth_token | constant) — both empty on that install — and sent the customer to the other
 * plugin, which rebuilds that secret, refuses an empty input, and answered its own 404 "Report Not Found" to every link.
 * Now:
 *   · TenantProfile::dataReportHandoff(): the explicit key portal_data_report_handoff (yes/no), else the profile's
 *     integrations.data_report_handoff (South Sudan true — the pre-5.18.73 behaviour; Uganda false), else true;
 *   · where it is off, the home card's fleet link is not drawn (the site list carries each site's GB), the single-service
 *     "See details" opens the in-app Usage view, and the site page's "Usage Details" tile is not drawn;
 *   · app_data_report_token answers 409 where the tenant has no hand-off, and 409 (audited data_report_handoff_unconfigured)
 *     where either signing input is empty — whatever the tenant says;
 *   · DishNet.openDataReport() never navigates without a token: a refusal is told to the customer, who stays in the app.
 * Also in this release, asserted here: the Account footer reads the installed version (not a hard-coded "v4.12.20"); the
 * biometric row is hidden outside the native app; the sign-in page allows pinch-zoom; the Service status screen prints no
 * invented uptime figure; the Connected-devices screen names an unavailable service instead of a JSON parser's words.
 * Driven over real HTTP on sandbox copies, Uganda and South Sudan; four weakened copies are each caught.
 */
$root = dirname(__DIR__);
require_once $root . '/tests/fixtures/staff_jobs_sandbox.php';   // sj_weakened_copy()
if (!getenv('DN_VAULT_FILE')) putenv('DN_VAULT_FILE=' . tempnam(sys_get_temp_dir(), 'dn-vault-'));
require_once $root . '/lib/StoreInterface.php'; require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/CustomerSession.php'; require_once $root . '/lib/LegalContent.php'; require_once $root . '/lib/TenantProfile.php';
$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d !== '' ? "\n       " . substr($d, 0, 600) : '') . "\n"; } }
$VERSION = (string)(json_decode((string)file_get_contents($root . '/manifest.json'), true)['information']['version'] ?? '?');
$KIT = 'KIT4M0HANDOFF01';
$sandboxes = [];
register_shutdown_function(function () use (&$sandboxes) { foreach ($sandboxes as $s) { if (is_resource($s['srv'])) { proc_terminate($s['srv']); proc_close($s['srv']); } exec('rm -rf ' . escapeshellarg($s['sb'])); } });

/** A copy of $pluginRoot served under /plugins/dishnet-hybrid-sudan/ (as uCRM serves it), one Starlink customer with one kit, one invoice. */
function sandbox(string $pluginRoot, string $profile, array $cfgExtra, string $tag): array {
    global $sandboxes, $KIT;
    $phone = $profile === 'uganda' ? '+256772123456' : '+211920000001';
    $sb = sys_get_temp_dir() . '/dn_ho_' . $tag . '_' . getmypid(); $plugins = "$sb/plugins"; $tmp = "$plugins/dishnet-hybrid-sudan"; $data = "$tmp/data";
    exec('rm -rf ' . escapeshellarg($sb)); @mkdir($data, 0700, true);
    exec(sprintf('cp -R %s/. %s/ 2>/dev/null', escapeshellarg($pluginRoot), escapeshellarg($tmp)));
    exec('rm -rf ' . escapeshellarg($data)); @mkdir($data, 0700, true);
    file_put_contents("$tmp/ucrm.json", json_encode(['pluginDataDir' => $data]));
    @mkdir("$plugins/.dishnet-starlink-finance-data", 0700, true);
    file_put_contents("$plugins/.dishnet-starlink-finance-data/sl_kits.json", json_encode([[
        'kit_number' => $KIT, 'serial_number' => 'SNHANDOFF01', 'service_line' => 'SL-HANDOFF-1', 'crm_client_id' => 7,
        'location_name' => 'Sandbox site', 'starlink_account_status' => 'active', 'plan_name' => 'Starlink Standard']]));
    $store = SqliteStore::create($data);
    $store->save('kyc_config.json', array_merge(['dry_run_mode' => true, 'data_dir' => $data, 'tenant_profile' => $profile, 'currency_symbol' => $profile === 'uganda' ? 'UGX' : 'USD',
        'currency_code' => $profile === 'uganda' ? 'UGX' : 'USD', 'wa_plugin_url' => 'http://127.0.0.1:1/', 'wa_app_key' => 'k', 'wa_auth_key' => 'a', 'app_jwt_ttl_days' => 2], $cfgExtra));
    $store->getPdo()->prepare("REPLACE INTO client_search_index (id, name, phone, phone_norm, service, updated_at) VALUES (?, ?, ?, ?, ?, datetime('now'))")
        ->execute([7, 'Sandbox Customer', $phone, substr(preg_replace('/[^0-9]/', '', $phone), -9), 'Starlink Standard']);
    $store->save('ucrm_clients_cache.json', [['id' => 7, 'firstName' => 'Sandbox', 'lastName' => 'Customer', 'clientType' => 1, 'isLead' => false, 'isArchived' => false,
        'contacts' => [['phone' => $phone, 'email' => 'sandbox@example.test', 'isBilling' => true, 'isContact' => true]], 'street1' => 'Main road', 'city' => 'Town']]);
    $store->save('ucrm_services_cache.json', [['id' => 41, 'clientId' => 7, 'name' => 'Starlink Standard', 'servicePlanId' => 3, 'price' => 100, 'status' => 1, 'currencyCode' => $profile === 'uganda' ? 'UGX' : 'USD']]);
    $store->save('ucrm_plans_cache.json', [['id' => 3, 'name' => 'Starlink Standard']]);
    $store->save('ucrm_invoices_cache.json', [['id' => 301, 'clientId' => 7, 'number' => 'INV-0301', 'status' => 1, 'total' => 100, 'amountPaid' => 0, 'amountToPay' => 100,
        'currencyCode' => $profile === 'uganda' ? 'UGX' : 'USD', 'dueDate' => '2026-10-10', 'createdDate' => '2026-10-01', 'items' => [['label' => 'Starlink Standard', 'total' => 100]]]]);
    unset($store);
    $nonce = bin2hex(random_bytes(8)); file_put_contents("$tmp/__nonce.txt", $nonce);
    $probe = function (string $url): ?string { $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2, CURLOPT_PROXY => '']);
        $r = curl_exec($ch); $c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); return ($r === false || $c !== 200) ? null : (string)$r; };
    $srv = null; $port = 0;
    foreach (range(0, 11) as $slot) {
        $cand = 9700 + ((getmypid() + $slot * 29 + crc32($tag) % 50) % 260);
        $p = proc_open(sprintf('exec php -S 127.0.0.1:%d -t %s', $cand, escapeshellarg($sb)), [1 => ['file', '/dev/null', 'w'], 2 => ['file', "$sb/server.log", 'a']], $pipes);
        $ours = false;
        for ($i = 0; $i < 60; $i++) { $got = $probe("http://127.0.0.1:{$cand}/plugins/dishnet-hybrid-sudan/__nonce.txt"); if ($got !== null) { $ours = trim($got) === $nonce; break; } usleep(100000); }
        if ($ours) { $srv = $p; $port = $cand; break; }
        proc_terminate($p); proc_close($p);
    }
    @unlink("$tmp/__nonce.txt");
    $s = ['sb' => $sb, 'tmp' => $tmp, 'data' => $data, 'port' => $port, 'srv' => $srv, 'profile' => $profile, 'phone' => $phone,
          'origin' => "http://127.0.0.1:$port", 'base' => "http://127.0.0.1:$port/plugins/dishnet-hybrid-sudan/public.php", 'jar' => "$sb/cookies.txt"];
    $sandboxes[] = $s;
    return $s;
}
function http(array $s, string $url, string $method = 'GET', ?array $body = null, array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30, CURLOPT_PROXY => '', CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers), CURLOPT_COOKIEJAR => $s['jar'], CURLOPT_COOKIEFILE => $s['jar']]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    if ($r === false) return ['code' => 0, 'body' => '', 'json' => null];
    $b = substr($r, $hs); $j = json_decode($b, true);
    return ['code' => $code, 'body' => $b, 'json' => is_array($j) ? $j : null];
}
/** Sign customer 7 in with the dry-run code and accept the terms; the cookie jar then carries the session. */
function signIn(array $s): bool {
    file_put_contents($s['data'] . '/dry_run_notification_log.json', '[]');
    $r = http($s, $s['base'] . '?page=api&action=app_send_otp', 'POST', ['phone' => $s['phone']]);
    if ($r['code'] !== 200) return false;
    $log = json_decode((string)@file_get_contents($s['data'] . '/dry_run_notification_log.json'), true) ?: [];
    $code = preg_match('/\b(\d{6})\b/', json_encode(end($log)), $m) ? $m[1] : '';
    $v = http($s, $s['base'] . '?page=api&action=app_verify_otp', 'POST', ['phone' => $s['phone'], 'code' => $code]);
    if ($v['code'] !== 200) return false;
    $ver = dnLegalVersion(TenantProfile::load($s['profile']));
    $c = http($s, $s['base'] . '?page=api&action=app_record_consent', 'POST', ['phone' => $s['phone'], 'tos_version' => $ver['tos'], 'privacy_version' => $ver['privacy']], ['X-Requested-With: DishNet', 'Origin: ' . $s['origin']]);
    return $c['code'] === 200;
}
function view(array $s, string $v): string { return http($s, $s['base'] . '?page=customer_portal&view=' . $v)['body']; }
function token(array $s): array { return http($s, $s['base'] . '?page=api&action=app_data_report_token', 'GET', null, ['X-Requested-With: DishNet']); }
function audits(array $s, string $action): int {
    try { $st = SqliteStore::create($s['data'])->getPdo()->prepare("SELECT COUNT(*) FROM app_audit_log WHERE action = ?"); $st->execute([$action]); return (int)$st->fetchColumn(); }
    catch (\Throwable $e) { return -1; }
}
$LINK = 'onclick="DishNet.openDataReport(';
$BOTH = ['webhook_secret' => str_repeat('a', 32), 'crm_auth_token' => str_repeat('b', 64)];

// ═══════════════════════════════════════════════════════════════════════════
echo "A. Uganda, nothing configured: the profile says no — the customer stays in the app\n";
$u = sandbox($root, 'uganda', [], 'ugdef');
is_($u['port'] > 0 && signIn($u), 'uganda sandbox up and the customer signed in');
$home = view($u, 'home');
is_(substr_count($home, $LINK) === 0 && strpos($home, 'Usage details →') === false && strpos($home, 'View all sites →') !== false,
    'home: no Data Report link — "View all sites →" stands, "Usage details →" is not drawn', 'links ' . substr_count($home, $LINK));
$site = view($u, 'site_detail&kit=' . $KIT);
is_(substr_count($site, $LINK) === 0 && strpos($site, '>Usage Details<') === false && strpos($site, 'grid-template-columns:repeat(1,1fr)') !== false && strpos($site, '>Change WiFi<') !== false,
    'site page: no "Usage Details" tile, the grid is one column, "Change WiFi" stands');
$t = token($u);
is_($t['code'] === 409 && ($t['json']['message'] ?? $t['json']['error'] ?? '') === 'Usage details are not available here.', 'app_data_report_token → 409 "Usage details are not available here."', $t['code'] . ' ' . substr($t['body'], 0, 200));
is_(audits($u, 'data_report_handoff_unconfigured') === 0, '…the tenant-off refusal is not the "unconfigured" audit (nothing to configure where the tenant says no)');
$acct = view($u, 'account');
$foot = preg_match('/DishNet Africa[^<]{0,6}v([0-9][^<]*)<br>/u', $acct, $fm) ? $fm[1] : '';
is_($foot === $VERSION, "account footer reads the installed version (v$VERSION), not a hard-coded v4.12.20 (the page's own script comments still name old versions, as they always did)", 'footer version ' . json_encode($foot));
is_(strpos($acct, '<div id="bio-section" hidden>') !== false && strpos($acct, 'Require biometric at app open') !== false, 'the biometric row is rendered hidden (a native-app setting; the bridge reveals it)');
$st = view($u, 'service_status');
is_(strpos($st, '24h uptime') === false && strpos($st, '24 hours ago') === false && strpos($st, 'No outage reported') !== false && strpos($st, 'Report an outage') !== false,
    'service status: no invented uptime figure; "No outage reported" and "Report an outage" stand');
$login = http(['jar' => $u['sb'] . '/nocookie.txt'] + $u, $u['base'] . '?page=customer_login')['body'];   // no session cookie: a live session is redirected to the portal
is_(preg_match('/<meta name="viewport" content="([^"]+)"/', $login, $m) === 1 && strpos($m[1], 'user-scalable=no') === false && strpos($m[1], 'maximum-scale') === false && strpos($m[1], 'viewport-fit=cover') !== false,
    'the sign-in page allows pinch-zoom (no user-scalable=no / maximum-scale)', $m[1] ?? '?');
$src = (string)file_get_contents($root . '/tabs/customer_app/portal.php');
is_(strpos($src, "location.href = t ? url + '&token=' + encodeURIComponent(t) : url;") === false && strpos($src, ".catch(function () { location.href = url; });") === false && strpos($src, 'var notHere = function ()') !== false,
    'openDataReport() never navigates without a token (both old fallbacks gone; a refusal is told to the customer)');
is_(strpos($src, "(err instanceof SyntaxError)") !== false && strpos($src, 'This feature is not available right now. Contact support if it continues.') !== false, 'the Connected-devices screen names an unavailable service instead of the parser\'s words');
is_(strpos($src, "<?= \$portalDataReportHandoff ? 'DishNet.openDataReport()' : \"DishNet.goInternal('usage')\" ?>\">See details →") !== false, 'the single-service "See details →" opens the in-app Usage view where the hand-off is off');

echo "\nB. Uganda, the explicit key says yes, but the signing inputs are empty: refused and audited\n";
$u2 = sandbox($root, 'uganda', ['portal_data_report_handoff' => 'yes'], 'ugyes');
is_($u2['port'] > 0 && signIn($u2), 'sandbox up, signed in');
$home = view($u2, 'home');
is_(substr_count($home, $LINK) === 1, 'home: the fleet link IS drawn (the key says yes)', 'links ' . substr_count($home, $LINK));
$t = token($u2);
is_($t['code'] === 409 && audits($u2, 'data_report_handoff_unconfigured') === 1, 'app_data_report_token → 409, and ONE data_report_handoff_unconfigured audit row (webhook_secret and crm_auth_token both empty)', $t['code'] . ' audits ' . audits($u2, 'data_report_handoff_unconfigured'));

echo "\nC. Uganda, the key says yes AND both inputs are set: the hand-off works as South Sudan's always did\n";
$u3 = sandbox($root, 'uganda', ['portal_data_report_handoff' => 'yes'] + $BOTH, 'ugfull');
is_($u3['port'] > 0 && signIn($u3), 'sandbox up, signed in');
$home = view($u3, 'home'); $site = view($u3, 'site_detail&kit=' . $KIT);
is_(substr_count($home, $LINK) === 1 && strpos($home, 'Usage details →') !== false && substr_count($site, $LINK) === 1 && strpos($site, 'grid-template-columns:repeat(2,1fr)') !== false,
    'home card link and the site page\'s "Usage Details" tile are drawn (two-column grid)');
$t = token($u3); $tok = (string)($t['json']['data']['token'] ?? '');
$hdr = json_decode(base64_decode(strtr(explode('.', $tok)[0] ?? '', '-_', '+/')), true) ?: []; $cl = json_decode(base64_decode(strtr(explode('.', $tok)[1] ?? '', '-_', '+/')), true) ?: [];
is_($t['code'] === 200 && $tok !== '' && !isset($hdr['kid']) && ($cl['aud'] ?? '') === 'data-report' && (($cl['exp'] ?? 0) - ($cl['iat'] ?? 0)) === 600 && (int)($cl['sub'] ?? 0) === 7,
    'app_data_report_token → 200: the legacy-shape ten-minute token for client 7, aud data-report', $t['code'] . ' ' . substr($t['body'], 0, 160));
is_(audits($u3, 'data_report_handoff_unconfigured') === 0, '…no "unconfigured" audit');

echo "\nD. South Sudan — the profile says yes (the pre-5.18.73 behaviour); the mint still refuses empty inputs\n";
$s1 = sandbox($root, 'south-sudan', $BOTH, 'ssfull');
is_($s1['port'] > 0 && signIn($s1), 'South Sudan sandbox with both inputs: signed in');
$home = view($s1, 'home');
is_(substr_count($home, $LINK) === 1 && strpos($home, '<?php') === false, 'home: the fleet link is drawn as before, no PHP leaks');
$t = token($s1);
is_($t['code'] === 200 && !empty($t['json']['data']['token']), 'app_data_report_token → 200 (unchanged for a configured install)');
$s2 = sandbox($root, 'south-sudan', [], 'ssempty');
is_($s2['port'] > 0 && signIn($s2), 'South Sudan sandbox with EMPTY inputs: signed in');
$t = token($s2);
is_($t['code'] === 409 && audits($s2, 'data_report_handoff_unconfigured') === 1, 'app_data_report_token → 409 and audited — the tenant\'s yes does not mint a token the other plugin would refuse', (string)$t['code'] . ' audits ' . audits($s2, 'data_report_handoff_unconfigured'));

echo "\nE. The configuration tool accepts yes or no, nothing else\n";
$cfgDir = $u['data'];
$run = function (array $args) use ($root, $cfgDir): array { $o = []; $rc = 0; exec('DN_DATA_DIR=' . escapeshellarg($cfgDir) . ' php ' . escapeshellarg($root . '/tools/set_config.php') . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $o, $rc); return [$rc, implode("\n", $o)]; };
[$rc, $out] = $run(['--key', 'portal_data_report_handoff', '--value', 'maybe']);
is_($rc === 1 && strpos($out, 'is not yes or no, so nothing was saved') !== false, 'a value that is not yes/no is refused (exit 1)', "$rc $out");
[$rc, $out] = $run(['--key', 'portal_data_report_handoff', '--value', 'YES']);
$saved = SqliteStore::create($cfgDir)->load('kyc_config.json');
is_($rc === 0 && ($saved['portal_data_report_handoff'] ?? null) === 'yes', '"YES" is saved as yes', "$rc " . substr($out, 0, 200) . ' saved=' . json_encode($saved['portal_data_report_handoff'] ?? null));

echo "\nF. Weakened copies are caught\n";
$mutants = [
  ['the home card ignores the tenant', 'tabs/customer_app/portal.php', 'uganda', [],
   '<?php if ($portalDataReportHandoff): /* 5.18.73: the Data Report hand-off only where the tenant has it; the site list', '<?php if (true): /* 5.18.73: the Data Report hand-off only where the tenant has it; the site list',
   function (array $m) use ($LINK): bool { return substr_count(view($m, 'home'), $LINK) === 0; }],
  ['the mint ignores the tenant', 'includes/api/api_customer_app.php', 'uganda', $BOTH,
   "if (!ca_tenant(is_array(\$config ?? null) ? \$config : [])->dataReportHandoff()) \$er2('Usage details are not available here.', 409);", "if (false) \$er2('Usage details are not available here.', 409);",
   function (array $m): bool { return token($m)['code'] === 409; }],
  ['the mint ignores empty signing inputs', 'includes/api/api_customer_app.php', 'south-sudan', [],
   "if (trim((string)(\$config['webhook_secret'] ?? '')) === '' || trim((string)(\$config['crm_app_key'] ?? \$config['crm_auth_token'] ?? '')) === '') {", "if (false) {",
   function (array $m): bool { return token($m)['code'] === 409; }],
  ['the Uganda profile says yes', 'profiles/uganda.json', 'uganda', [],
   '"data_report_handoff": false', '"data_report_handoff": true',
   function (array $m) use ($LINK): bool { return substr_count(view($m, 'home'), $LINK) === 0; }],
];
foreach ($mutants as $i => [$label, $rel, $profile, $cfg, $old, $new, $stillRight]) {
    [$tmp, $n] = sj_weakened_copy($root, $rel, $old, $new);
    is_($n === 1, "mutant " . ($i + 1) . ": the anchor is unique in $rel", "count $n");
    if ($n === 1) {
        $m = sandbox($tmp, $profile, $cfg, 'mut' . $i);
        $ok = $m['port'] > 0 && signIn($m);
        is_($ok && $stillRight($m) === false, "mutant " . ($i + 1) . " is caught: $label", $ok ? '' : 'sandbox/sign-in failed');
    }
    exec('rm -rf ' . escapeshellarg($tmp));
}
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
