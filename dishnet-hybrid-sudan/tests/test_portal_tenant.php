<?php
declare(strict_types=1);
/**
 * test_portal_tenant.php — 5.18.41 (docs/38 A1.1): the signed-in portal and the
 * public legal pages speak the TENANT's identity, rendered for real.
 *
 * Measured on the Uganda install (docs/37 §J.1, §I.5): the Support tab showed
 * 23 South Sudan literals and no Uganda contact, every page's script dialled
 * +211, the home page defaulted to Juba, the Terms page footer named Juba, and
 * the invoice screen printed a South Sudan bank and " USD" after a shilling
 * amount. The portal never read TenantProfile. Now portal_data.php loads it once
 * and every view reads variables; each fallback literal is what the view printed
 * before, so the south-sudan profile — the install that configures nothing —
 * renders what it rendered, with ONE deliberate difference (docs/38 decision
 * A-1): "Call us" shows and dials the same number, the profile's support phone.
 *
 * Proved here: what the Uganda run must NOT carry, page by page, and what it
 * must; the south-sudan run as the control; a scan of the sources for any
 * literal that is not a fallback argument; and the control on the control — a
 * literal planted into the sandbox copy IS seen on the served page.
 *
 * The identity, jurisdiction and regulator sentences INSIDE the legal documents
 * are change set A2's (approved wording pending). They are pinned here as still
 * South Sudan's on every install, so that A2 flips them deliberately.
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
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/CustomerSession.php';
require_once $root . '/lib/LegalContent.php';
require_once $root . '/lib/TenantProfile.php';

/** A copy of the plugin under php -S with one customer, one service, one unpaid invoice, the given profile. */
function sandbox(string $profile, string $phone, string $currency, string $symbol): array {
    global $root;
    $sb = sys_get_temp_dir() . '/dn_pt_' . $profile . '_' . getmypid(); $tmp = "$sb/plugin"; $data = "$tmp/data";
    exec('rm -rf ' . escapeshellarg($sb)); @mkdir($data, 0700, true);
    exec(sprintf('cp -R %s/. %s/ 2>/dev/null', escapeshellarg($root), escapeshellarg($tmp)));
    exec('rm -rf ' . escapeshellarg($data)); @mkdir($data, 0700, true);
    file_put_contents("$tmp/ucrm.json", json_encode(['pluginDataDir' => $data]));
    $store = SqliteStore::create($data);
    $store->save('kyc_config.json', ['dry_run_mode' => true, 'data_dir' => $data, 'tenant_profile' => $profile, 'currency_symbol' => $symbol,
        'wa_plugin_url' => 'http://127.0.0.1:1/', 'wa_app_key' => 'k', 'wa_auth_key' => 'a', 'app_jwt_ttl_days' => 2]);
    $store->getPdo()->prepare("REPLACE INTO client_search_index (id, name, phone, phone_norm, service, updated_at) VALUES (?, ?, ?, ?, ?, datetime('now'))")
        ->execute([7, 'Test Customer', $phone, substr(preg_replace('/[^0-9]/', '', $phone), -9), 'Starlink Standard']);
    $store->save('ucrm_clients_cache.json', [['id' => 7, 'firstName' => 'Test', 'lastName' => 'Customer', 'clientType' => 1, 'isLead' => false, 'isArchived' => false, 'contacts' => [['phone' => $phone]]]]);
    $store->save('ucrm_services_cache.json', [['id' => 41, 'clientId' => 7, 'name' => 'Starlink Standard', 'servicePlanId' => 3, 'price' => 50000, 'status' => 1, 'currencyCode' => $currency]]);
    $store->save('ucrm_plans_cache.json', [['id' => 3, 'name' => 'Starlink Standard']]);
    $store->save('ucrm_invoices_cache.json', [['id' => 301, 'clientId' => 7, 'number' => 'INV-0301', 'status' => 1, 'total' => 50000, 'amountPaid' => 0, 'amountToPay' => 50000,
        'currencyCode' => $currency, 'dueDate' => '2026-09-15', 'createdDate' => '2026-09-01', 'items' => [['label' => 'Starlink Standard', 'total' => 50000]]]]);
    unset($store);
    $nonce = bin2hex(random_bytes(8)); file_put_contents($tmp . '/__nonce.txt', $nonce);
    $probe = function (string $url): ?string { $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2, CURLOPT_PROXY => '']);
        $r = curl_exec($ch); $c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); return ($r === false || $c !== 200) ? null : (string)$r; };
    $srv = null; $port = 0;
    foreach (range(0, 9) as $slot) {
        $cand = 9100 + ((getmypid() + $slot * 37 + ($profile === 'uganda' ? 0 : 7)) % 240);
        $p = proc_open(sprintf('exec php -S 127.0.0.1:%d -t %s', $cand, escapeshellarg($tmp)), [1 => ['file', '/dev/null', 'w'], 2 => ['file', $sb . '/server.log', 'a']], $pipes);
        $ours = false;
        for ($i = 0; $i < 60; $i++) { $got = $probe("http://127.0.0.1:{$cand}/__nonce.txt"); if ($got !== null) { $ours = trim($got) === $nonce; break; } usleep(100000); }
        if ($ours) { $srv = $p; $port = $cand; break; }
        proc_terminate($p); proc_close($p);
    }
    @unlink($tmp . '/__nonce.txt');
    return ['sb' => $sb, 'tmp' => $tmp, 'data' => $data, 'port' => $port, 'srv' => $srv];
}
$procs = [];
register_shutdown_function(function () use (&$procs) { foreach ($procs as $s) { if (is_resource($s['srv'])) { proc_terminate($s['srv']); proc_close($s['srv']); } exec('rm -rf ' . escapeshellarg($s['sb'])); } });

/** HTTP with headers kept. */
function http(string $base, string $method, string $query, ?array $body = null, array $headers = []): array {
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
}
/** Sign customer 7 in (dry-run code read from the log), accept the terms, return the Cookie header. */
function signInWithConsent(array $s, string $phone): string {
    $base = "http://127.0.0.1:{$s['port']}/public.php";
    $send = http($base, 'POST', '?page=api&action=app_send_otp', ['phone' => $phone]);
    is_($send['code'] === 200, "[{$s['profile']}] app_send_otp 200", "got {$send['code']} " . substr($send['body'], 0, 160));
    $log = json_decode((string)@file_get_contents($s['data'] . '/dry_run_notification_log.json'), true) ?: [];
    $code = preg_match('/\b(\d{6})\b/', json_encode(end($log)), $m) ? $m[1] : '';
    $v = http($base, 'POST', '?page=api&action=app_verify_otp', ['phone' => $phone, 'code' => $code]);
    is_($v['code'] === 200, "[{$s['profile']}] app_verify_otp 200", "got {$v['code']} " . substr($v['body'], 0, 160));
    $sc = null; foreach ($v['headers']['set-cookie'] ?? [] as $c) if (strpos($c, CustomerSession::COOKIE . '=') === 0) $sc = $c;
    $jwt = $sc === null ? '' : (string)preg_replace('/^[^=]+=([^;]*).*$/', '$1', $sc);
    $C = 'Cookie: ' . CustomerSession::COOKIE . '=' . $jwt;
    $ver = dnLegalVersion();
    $r = http($base, 'POST', '?page=api&action=app_record_consent', ['tos_version' => $ver['tos'], 'privacy_version' => $ver['privacy']], [$C, 'X-Requested-With: DishNet', "Origin: http://127.0.0.1:{$s['port']}"]);
    is_($r['code'] === 200, "[{$s['profile']}] consent recorded (200)", "got {$r['code']} " . substr($r['body'], 0, 160));
    return $C;
}
function none(string $tag, string $body, array $needles): void {
    $hits = [];
    foreach ($needles as $n) { $c = substr_count($body, $n); if ($c > 0) $hits[] = "$n ×$c"; }
    is_($hits === [], "$tag carries none of: " . implode(' · ', $needles), 'found ' . implode(', ', $hits));
}
function has(string $tag, string $body, array $needles): void {
    $missing = [];
    foreach ($needles as $n) if (strpos($body, $n) === false) $missing[] = $n;
    is_($missing === [], "$tag carries: " . implode(' · ', $needles), 'missing ' . implode(', ', $missing));
}

/** The served copy is read through opcache (revalidated every 2 s): poll a condition for up to 5 s. */
function untilServed(callable $cond): bool { for ($i = 0; $i < 25; $i++) { if ($cond()) return true; usleep(200000); } return (bool)$cond(); }
$VIEWS = ['home', 'account', 'support', 'service_status', 'invoices', 'invoice_detail&inv_id=301', 'wifi_change', 'devices', 'usage', 'sites'];

// ─────────────────────────────────────────────────────────────────────────────
echo "\n1. The uganda profile: nothing of South Sudan on any signed-in page; the Uganda contacts present\n";
$ug = sandbox('uganda', '+256772123456', 'UGX', 'UGX'); $ug['profile'] = 'uganda'; $procs[] = $ug;
is_($ug['port'] > 0, 'uganda sandbox up');
$base = "http://127.0.0.1:{$ug['port']}/public.php";
$C = signInWithConsent($ug, '+256772123456');
$SUDAN = ['+211', '211921443', '211923400', 'dishnetafrica.com', 'Juba', 'South Sudan', 'Stanbic'];
$pages = [];
foreach ($VIEWS as $v) {
    $p = http($base, 'GET', "?page=customer_portal&view=$v", null, [$C]);
    is_($p['code'] === 200, "[uganda] view=$v renders (200)", "got {$p['code']}");
    $pages[$v] = $p['body'];
    none("[uganda] view=$v", $p['body'], $SUDAN);
}
none('[uganda] the money on home / account / invoices / invoice_detail', $pages['home'] . $pages['account'] . $pages['invoices'] . $pages['invoice_detail&inv_id=301'], [' USD']);
has('[uganda] the Support tab', $pages['support'], ['+256 705 993 348', "DishNet.openPhone('+256705993348')", 'accounts@dishnetuganda.com', "DishNet.openEmail('accounts@dishnetuganda.com')"]);
has('[uganda] every page emits the tenant\'s WhatsApp once, and the object\'s own buttons dial it', $pages['home'], ['supportWa: "+256705993348"', 'DishNet.openWhatsApp(DishNet.supportWa,']);
is_(substr_count($pages['support'], 'DishNet.openWhatsApp(DishNet.supportWa,') >= 3, '[uganda] the Support tab\'s WhatsApp rows dial the emitted number', 'rows: ' . substr_count($pages['support'], 'DishNet.openWhatsApp(DishNet.supportWa,'));
$literalCalls = 0; foreach ($pages as $b) $literalCalls += preg_match_all('/openWhatsApp\(\\?[\'"]\+?\d/', $b);
is_($literalCalls === 0, '[uganda] no page dials a literal number in any openWhatsApp call', "literal calls: $literalCalls");
has('[uganda] the status page names the tenant\'s locality', $pages['service_status'], ['Kampala, Uganda · Updated just now']);
none('[uganda] the status page shows no fibre or LTE card (the profile sells starlink only)', $pages['service_status'], ['>Fiber<', '4G LTE', 'metro areas']);
has('[uganda] the status page keeps the Starlink card', $pages['service_status'], ['>Starlink<']);
has('[uganda] the home page defaults the location to the tenant\'s city', $pages['home'], ['Kampala']);
has('[uganda] the invoice screen: a payment reference, no other tenant\'s bank', $pages['invoice_detail&inv_id=301'], ['Payment reference', 'INV-0301', 'UGX 50,000']);
none('[uganda] the invoice screen', $pages['invoice_detail&inv_id=301'], ['Bank transfer', '<b>DishNet Africa Ltd</b>', 'Equity Bank']);
is_(strpos($pages['invoice_detail&inv_id=301'], 'notifyPayment(') !== false && strpos($pages['home'], '+ "UGX " + Math.round(amount) + "*\\n\\n"') !== false,
    '[uganda] the payment notification prefixes UGX and adds no currency code after the amount', substr((string)strstr($pages['home'], 'Math.round(amount)'), 0, 80));

echo "\n2. The uganda profile: the public pages\n";
$login = http($base, 'GET', '?page=customer_login');
none('[uganda] the sign-in page', $login['body'], $SUDAN);
$terms = http($base, 'GET', '?page=terms'); $priv = http($base, 'GET', '?page=privacy');
is_($terms['code'] === 200 && $priv['code'] === 200, '[uganda] terms and privacy render (200)', "{$terms['code']} {$priv['code']}");
foreach (['terms' => $terms['body'], 'privacy' => $priv['body']] as $k => $b) {
    none("[uganda] $k: the contact lines and the footer", $b, ['+211', '211921443', 'dishnetafrica.com', 'Juba, South Sudan', 'wa.me/211']);
    has("[uganda] $k: the tenant's entity, locality and contacts", $b, ['DishNet Africa Limited &middot; Kampala, Uganda', 'wa.me/256705993348', 'WhatsApp +256 705 993 348', 'mailto:accounts@dishnetuganda.com', 'accounts@dishnetuganda.com']);
}
has('[uganda] terms: the A2-pending sentences are STILL South Sudan\'s (pinned, so A2 flips them deliberately)', $terms['body'], ['registered in South Sudan', 'laws of the Republic of South Sudan', 'courts of Juba']);
has('[uganda] privacy: the A2-pending regulator sentence is STILL South Sudan\'s (pinned)', $priv['body'], ['South Sudan regulatory authorities']);
has('[uganda] terms: the contact section reads the tenant', $terms['body'], ['Reach us on WhatsApp at +256 705 993 348 or email accounts@dishnetuganda.com']);
has('[uganda] privacy: the rights and contact sections read the tenant', $priv['body'], ['contact accounts@dishnetuganda.com with your', 'WhatsApp +256 705 993 348 or email accounts@dishnetuganda.com']);

echo "\n3. The control on the control: a literal planted into the sandbox copy IS seen on the served page\n";
$pp = $ug['tmp'] . '/tabs/customer_app/portal.php'; $src = (string)file_get_contents($pp);
is_(substr_count($src, '<div class="scr-title">Support</div>') === 1, 'the anchor appears once in the copy');
file_put_contents($pp, str_replace('<div class="scr-title">Support</div>', '<div class="scr-title">Support</div><span>+211 900 000 000</span>', $src));
untilServed(function () use ($base, $C): bool { return strpos(http($base, 'GET', '?page=customer_portal&view=support', null, [$C])['body'], '+211') !== false; });
$p = http($base, 'GET', '?page=customer_portal&view=support', null, [$C]);
is_($p['code'] === 200 && substr_count($p['body'], '+211') === 1, 'with a +211 planted in the copy, the served Support tab carries exactly that one', 'count ' . substr_count($p['body'], '+211'));
file_put_contents($pp, $src);
untilServed(function () use ($base, $C): bool { return strpos(http($base, 'GET', '?page=customer_portal&view=support', null, [$C])['body'], '+211') === false; });

// ─────────────────────────────────────────────────────────────────────────────
echo "\n4. The south-sudan profile (an install that configures nothing): what it rendered before\n";
$ss = sandbox('south-sudan', '+211927000123', 'USD', '$'); $ss['profile'] = 'south-sudan'; $procs[] = $ss;
is_($ss['port'] > 0, 'south-sudan sandbox up');
$base2 = "http://127.0.0.1:{$ss['port']}/public.php";
$C2 = signInWithConsent($ss, '+211927000123');
$sp = [];
foreach ($VIEWS as $v) { $p = http($base2, 'GET', "?page=customer_portal&view=$v", null, [$C2]); is_($p['code'] === 200, "[south-sudan] view=$v renders (200)", "got {$p['code']}"); $sp[$v] = $p['body']; }
has('[south-sudan] the Support tab: the WhatsApp number, the e-mail, and "Call us" showing AND dialling the profile\'s support phone (decision A-1)', $sp['support'],
    ['supportWa: "+211921443002"', 'info@dishnetafrica.com', "DishNet.openEmail('info@dishnetafrica.com')", '+211 921 443 006', "DishNet.openPhone('+211921443006')"]);
none('[south-sudan] the Support tab no longer shows one number and dials another', $sp['support'], ['+211 921 443 005', "openPhone('+211921443002')"]);
has('[south-sudan] the status page', $sp['service_status'], ['Juba, South Sudan · Updated just now', '>Fiber<', 'Juba metro areas', '4G LTE', 'Juba, Yei, Wau', '>Starlink<']);
has('[south-sudan] the home page defaults to Juba', $sp['home'], ['Juba']);
has('[south-sudan] the invoice screen: the bank-transfer instructions the code always printed, with the code after a dollar amount', $sp['invoice_detail&inv_id=301'],
    ['Bank transfer', '<b>DishNet Africa Ltd</b>', 'Stanbic Bank / Equity Bank', 'INV-0301', '$ 50,000 USD']);
none('[south-sudan] nothing of Uganda', implode('', $sp), ['+256', '256705993348', 'dishnetuganda.com', 'Kampala']);
$t2 = http($base2, 'GET', '?page=terms');
has('[south-sudan] terms: the footer and contacts exactly as before', $t2['body'], ['DishNet Africa Ltd. &middot; Juba, South Sudan', 'wa.me/211921443002', 'WhatsApp +211 921 443 002', 'mailto:info@dishnetafrica.com',
    'Reach us on WhatsApp at +211 921 443 002 or email info@dishnetafrica.com', 'laws of the Republic of South Sudan']);
none('[south-sudan] terms: nothing of Uganda', $t2['body'], ['+256', 'dishnetuganda.com', 'Kampala']);

// ─────────────────────────────────────────────────────────────────────────────
echo "\n5. The sources: every remaining South Sudan literal is a fallback argument, or a named A2 exception\n";
$FILES = ['tabs/customer_app/portal.php', 'tabs/customer_app/portal_data.php', 'tabs/customer_app/legal_page.php', 'tabs/customer_app/login_web.php', 'lib/LegalContent.php'];
$NEEDLES = ['+211', '211921443', '211923400', 'dishnetafrica.com', 'Juba', 'South Sudan', 'Stanbic'];
// A line is a fallback when the literal is the second argument of a profile read, or the ?: literal after an accessor.
$FALLBACK = '/->(text|login|contact)\(\s*\'[^\']*\'\s*,\s*\'[^\']*\'\s*\)|->(email|locality)\(\)\s*\?:\s*\'[^\']*\'/';
// Change set A2 (docs/38): the identity, jurisdiction and regulator sentences — approved Uganda wording pending.
$A2 = [
    'registered in South Sudan, providing Starlink, fibre, and LTE internet services to' => 'A2: the identity sentence',
    'customers in Juba and across the region.'                                          => 'A2: the identity sentence (service area)',
    'These Terms are governed by the laws of the Republic of South Sudan.'             => 'A2: governing law',
    'to the courts of Juba.'                                                            => 'A2: the forum',
    'processors and our accountants — for invoicing and tax records. South Sudan'      => 'A2: the regulator sentence',
];
function scanLiterals(string $code, array $needles, string $fallback, array $exceptions): array {
    $hits = [];
    foreach (explode("\n", $code) as $i => $line) {
        $found = false; foreach ($needles as $n) if (strpos($line, $n) !== false) { $found = true; break; }
        if (!$found) continue;
        if (preg_match($fallback, $line)) continue;
        $exc = false; foreach (array_keys($exceptions) as $k) if (strpos($line, $k) !== false) { $exc = true; break; }
        if ($exc) continue;
        $hits[] = ($i + 1) . ': ' . trim($line);
    }
    return $hits;
}
$seenExc = [];
foreach ($FILES as $rel) {
    $code = codeNC("$root/$rel");
    foreach (array_keys($A2) as $k) if (strpos($code, $k) !== false) $seenExc[$k] = true;
    $hits = scanLiterals($code, $NEEDLES, $FALLBACK, $A2);
    is_($hits === [], "$rel: no South Sudan literal outside a fallback argument" . ($rel === 'lib/LegalContent.php' ? ' or an A2 exception' : ''), implode("\n       ", array_slice($hits, 0, 8)));
}
is_(count($seenExc) === count($A2), 'every A2 exception still exists in the sources (the list cannot rot)', 'missing: ' . implode(' | ', array_diff(array_keys($A2), array_keys($seenExc))));
is_(count(scanLiterals("\$x = 'call +211 921 443 002 now';\n", $NEEDLES, $FALLBACK, $A2)) === 1, 'control: a planted literal is a hit');
is_(count(scanLiterals("\$x = \$tp->text('office.city', 'Juba');\n", $NEEDLES, $FALLBACK, $A2)) === 0, 'control: a fallback argument is not');
is_(count(scanLiterals("\$x = \$tp->email() ?: 'info@dishnetafrica.com';\n", $NEEDLES, $FALLBACK, $A2)) === 0, 'control: an accessor with a literal after ?: is not');
// Found while building 5.18.41: a closing PHP tag inside a line comment ends PHP mode and prints the rest of
// the file as HTML — the portal's own source appeared on the home page. A template one-liner (an if whose
// tag opens, carries a trailing comment and closes on the same line) is legitimate; a line comment inside a
// multi-line PHP block that is followed by the closing tag is the defect. Pinned so it cannot come back.
// (This comment itself spells neither tag out: the first draft did, and ended the test file's PHP mode.)
function commentClosesPhp(string $file): array {
    $hits = []; $openLine = 0; $toks = token_get_all((string)file_get_contents($file));
    foreach ($toks as $i => $tok) {
        if (!is_array($tok)) continue;
        if ($tok[0] === T_OPEN_TAG || $tok[0] === T_OPEN_TAG_WITH_ECHO) { $openLine = $tok[2]; continue; }
        if ($tok[0] !== T_COMMENT || !preg_match('/^(\/\/|#[^\[])/', $tok[1])) continue;
        $next = $toks[$i + 1] ?? null;
        if (is_array($next) && $next[0] === T_CLOSE_TAG && $tok[2] !== $openLine) $hits[] = basename($file) . ':' . $tok[2];
    }
    return $hits;
}
$closers = [];
foreach (array_merge($FILES, ['lib/CanonicalHost.php', 'lib/TenantProfile.php', 'lib/CustomerSession.php']) as $rel) $closers = array_merge($closers, commentClosesPhp("$root/$rel"));
is_($closers === [], 'no line comment inside a PHP block carries a closing PHP tag', implode(', ', $closers));
$planted = sys_get_temp_dir() . '/dn_pt_planted_' . getmypid() . '.php';
file_put_contents($planted, "<?php\n\$a = 1;\n// see onclick=\"x('<?= y() ?>')\" here\nfunction z() {}\n");
is_(commentClosesPhp($planted) === [basename($planted) . ':3'], 'control: the defect that was found is detected', json_encode(commentClosesPhp($planted)));
file_put_contents($planted, "<html><?php if (1): // a template one-liner ?>x<?php endif; ?></html>\n");
is_(commentClosesPhp($planted) === [], 'control: a template one-liner with a comment is not flagged');
@unlink($planted);

echo "\n6. TenantProfile: the accessors the portal relies on\n";
$ugp = TenantProfile::load('uganda'); $ssp = TenantProfile::load('south-sudan');
is_(TenantProfile::formatWa('256705993348') === '+256 705 993 348' && TenantProfile::formatWa('211921443002') === '+211 921 443 002', 'formatWa groups a twelve-digit number as the tenants write theirs');
is_(TenantProfile::formatWa('+1 415 555 0100') === '+14155550100' && TenantProfile::formatWa('') === '', 'formatWa: other shapes are "+" and the digits; empty stays empty');
is_($ugp->contact('support_wa', 'x') === '256705993348' && $ssp->contact('support_wa', 'x') === '211921443002' && $ugp->contact('nope', 'lit') === 'lit', 'contact(): the profile value, else the literal');
is_($ugp->products() === ['starlink'] && $ugp->sells('starlink') && !$ugp->sells('fibre') && !$ugp->sells('lte'), 'uganda sells starlink only');
is_($ssp->sells('starlink') && $ssp->sells('fibre') && $ssp->sells('lte'), 'south-sudan sells all three');
is_(TenantProfile::load('uganda')->text('payment_instructions.bank', '') === '' && $ssp->text('payment_instructions.bank', '') === 'Stanbic Bank / Equity Bank', 'bank details: only where the profile answers (uganda: none, never the other tenant\'s)');

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
