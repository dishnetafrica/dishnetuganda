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
 * 5.18.42 (docs/38 A2, approved 26 September 2026): the legal documents are
 * templates over the profile — identity, products, fees, forum and regulator per
 * tenant, and the version too (Uganda 1.1, South Sudan 1.0). Pinned here: the
 * approved Uganda sentences, the South Sudan documents byte for byte against the
 * pre-A2 rendering (a golden hash), and that lib/LegalContent.php carries no
 * tenant's wording of its own. Also 5.18.42 (docs/38 §7.5): the invoice screen
 * prints each tax or levy on its own line, under uCRM's own name.
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
function sandbox(string $profile, string $phone, string $currency, string $symbol, array $invoiceExtra = []): array {
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
    $store->save('ucrm_invoices_cache.json', [array_merge(['id' => 301, 'clientId' => 7, 'number' => 'INV-0301', 'status' => 1, 'total' => 50000, 'amountPaid' => 0, 'amountToPay' => 50000,
        'currencyCode' => $currency, 'dueDate' => '2026-09-15', 'createdDate' => '2026-09-01', 'items' => [['label' => 'Starlink Standard', 'total' => 50000]]], $invoiceExtra)]);
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
    $ver = dnLegalVersion(TenantProfile::load($s['profile']));   // 5.18.42: the tenant's versions
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
// The invoice carries the totals block in uCRM's own shape (tests/test_efris_mapper.php, the live install verbatim):
// subtotal, taxes[] = [{name, totalValue}], totalTaxAmount, totalDiscount — two taxes, named as an operator would name them.
$UG_TAXES = ['subtotal' => 41666.67, 'totalTaxAmount' => 8333.33, 'totalDiscount' => -0.0,
    'taxes' => [['id' => 1, 'name' => 'VAT 18%', 'totalValue' => 7500.00], ['id' => 2, 'name' => 'UCC levy 2%', 'totalValue' => 833.33]]];
$ug = sandbox('uganda', '+256772123456', 'UGX', 'UGX', $UG_TAXES); $ug['profile'] = 'uganda'; $procs[] = $ug;
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
echo "\n1b. The invoice screen prints each tax or levy on its own line, under uCRM's own name (5.18.42, docs/38 §7.5)\n";
$invPage = $pages['invoice_detail&inv_id=301'];
has('[uganda] the totals block: before tax, VAT and the UCC levy as separate lines, the total', $invPage,
    ['>Before tax<', 'UGX 41,666.67', '>VAT 18%<', 'UGX 7,500.00', '>UCC levy 2%<', 'UGX 833.33', '>Total<', 'UGX 50,000.00', 'Each tax or levy is listed on its own line']);
is_(substr_count($invPage, 'class="inv-tax-line"') === 2, '[uganda] exactly two tax lines — one per tax uCRM stated', 'lines: ' . substr_count($invPage, 'class="inv-tax-line"'));
is_(strpos($invPage, '>Tax<') === false && strpos($invPage, '>Subtotal<') === false, '[uganda] no lumped "Tax" line and no bare "Subtotal" label');
is_(strpos($invPage, '>Discount<') === false, '[uganda] a -0.0 discount (the live shape) prints no discount line');
$apiInv = http($base, 'GET', '?page=api&action=app_invoice&id=301', null, [$C]);
$apiTaxes = $apiInv['json']['data']['taxes'] ?? null;
is_($apiInv['code'] === 200 && is_array($apiTaxes) && count($apiTaxes) === 2 && $apiTaxes[0]['name'] === 'VAT 18%' && (float)$apiTaxes[0]['amount'] === 7500.0
    && $apiTaxes[1]['name'] === 'UCC levy 2%' && (float)$apiTaxes[1]['amount'] === 833.33 && (float)($apiInv['json']['data']['tax'] ?? -1) === 8333.33
    && (float)($apiInv['json']['data']['subtotal'] ?? -1) === 41666.67 && (float)($apiInv['json']['data']['discount'] ?? -1) === 0.0,
    '[uganda] the app API (app_invoice) returns the same lines: taxes[] by name, tax = uCRM\'s total, subtotal, discount 0', substr($apiInv['body'], 0, 300));
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
echo "\n2b. Change set A2 (docs/38 §7.3, approved): the Uganda documents say Uganda — and nothing of the other tenant's law, fees or products\n";
has('[uganda] terms: identity (point 1), governing law (2), the forum (3, conservative), products (8)', $terms['body'], [
    'DishNet Africa Limited (&quot;DishNet&quot;, &quot;we&quot;, &quot;us&quot;) is an IT solutions provider and UCC-authorised Starlink installer registered in Uganda (Reg. No. 80046255496181), providing Starlink internet services to customers in Kampala and across Uganda.',
    'These Terms are governed by the laws of the Republic of Uganda.', 'submitted to the courts of Uganda.', 'upstream providers (SpaceX/Starlink)', 'hardware (Starlink dishes and routers)', 'violate Starlink&#039;s acceptable use policy']);
none('[uganda] terms: no South Sudan fee, court, product or regulator (points 3, 7, 8)', $terms['body'], ['USD 25', 'USD 150', '5% of', '6-month', '120-day', 'Cheques', 'fibre', 'LTE', 'courts of Juba', 'registered in South Sudan', 'Republic of South Sudan']);
has('[uganda] terms: with no confirmed Uganda figure the Billing and Transfer sections state none and point to the invoice (point 7, conservative)', $terms['body'],
    ['Late-payment and reconnection charges, where they apply, are those stated on your quotation or invoice.', 'Any minimum service period, transfer fee and processing time are confirmed to you in writing before a transfer.']);
has('[uganda] privacy: the regulator sentence (point 4), both sign-in channels (9), the entity', $priv['body'],
    ['The Uganda Communications Commission and other Ugandan authorities — if required by law', 'six-digit code to your WhatsApp number or your e-mail address', 'Login codes by WhatsApp or e-mail', 'how DishNet Africa Limited handles information']);
none('[uganda] privacy: no fibre/LTE sharing clause (point 10), no South Sudan regulator', $priv['body'], ['Splynx', 'Fibre and LTE partners', 'South Sudan regulatory authorities', 'DishNet Africa Ltd.']);
has('[uganda] the documents carry version 1.1 (point 6: Uganda customers accept once more; South Sudan is not asked)', $terms['body'] . $priv['body'], ['v1.1']);
$lv = http($base, 'GET', '?page=api&action=app_legal_version');
is_(($lv['json']['data']['tos_version'] ?? '') === '1.1' && ($lv['json']['data']['privacy_version'] ?? '') === '1.1' && ($lv['json']['data']['dated'] ?? '') === '26 September 2026',
    '[uganda] app_legal_version answers the tenant\'s versions and date (1.1 / 1.1 / 26 September 2026)', substr($lv['body'], 0, 200));
has('[uganda] the sign-in page\'s consent step shows the tenant\'s version and date', $login['body'], ['v1.1', 'Effective <strong>26 September 2026']);
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
echo "\n4b. South Sudan renders its documents BYTE FOR BYTE as before A2, at version 1.0 (nobody there is asked again)\n";
// The golden is the sha256 of [dnTermsContent, dnPrivacyContent] for the south-sudan profile as the pre-5.18.42 code rendered
// them (computed from commit a2ea19f before the template was written). A changed word anywhere changes the hash.
$ssTp = TenantProfile::load('south-sudan');
$golden = hash('sha256', json_encode([dnTermsContent($ssTp), dnPrivacyContent($ssTp)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
is_($golden === 'b2f4ff3b34bb1fcabc11bc87beb9f66cbafee22605c3a9e69d42ebcb1ead4637', '[south-sudan] the Terms and Privacy documents are byte-identical to the pre-A2 rendering (golden sha256)', $golden);
has('[south-sudan] terms: the sentences A2 templated read exactly as before', $t2['body'], ['is a telecommunications company registered in South Sudan, providing Starlink, fibre, and LTE internet services to customers in Juba and across the region.',
    'submitted to the courts of Juba.', 'Reconnection after suspension costs USD 25.', 'subject to a USD 150 transfer fee and a 120-day lead time', 'v1.0']);
$p2 = http($base2, 'GET', '?page=privacy');
has('[south-sudan] privacy: the sharing and sign-in sentences exactly as before', $p2['body'], ['Fibre and LTE partners (e.g. Splynx-managed operators) — for service activation and support. Payment processors', 'South Sudan regulatory authorities — if required by law', 'WhatsApp and login codes', 'only your phone number so we can route the code to you.']);
$lv2 = http($base2, 'GET', '?page=api&action=app_legal_version');
is_(($lv2['json']['data']['tos_version'] ?? '') === '1.0' && ($lv2['json']['data']['dated'] ?? '') === '18 April 2026', '[south-sudan] app_legal_version still answers 1.0 / 18 April 2026', substr($lv2['body'], 0, 200));
has('[south-sudan] the invoice screen without a tax line: the total only, no "Before tax" row and no tax note (the control)', $sp['invoice_detail&inv_id=301'], ['>Total<', '$ 50,000.00']);
none('[south-sudan] the invoice screen without a tax line', $sp['invoice_detail&inv_id=301'], ['>Before tax<', 'inv-tax-line', 'Each tax or levy']);

// ─────────────────────────────────────────────────────────────────────────────
echo "\n5. The sources: every remaining South Sudan literal is a fallback argument; lib/LegalContent.php carries none at all\n";
$FILES = ['tabs/customer_app/portal.php', 'tabs/customer_app/portal_data.php', 'tabs/customer_app/legal_page.php', 'tabs/customer_app/login_web.php', 'lib/LegalContent.php'];
$NEEDLES = ['+211', '211921443', '211923400', 'dishnetafrica.com', 'Juba', 'South Sudan', 'Stanbic'];
// A line is a fallback when the literal is the second argument of a profile read, or the ?: literal after an accessor.
$FALLBACK = '/->(text|login|contact)\(\s*\'[^\']*\'\s*,\s*\'[^\']*\'\s*\)|->(email|locality)\(\)\s*\?:\s*\'[^\']*\'/';
// 5.18.42: change set A2 is built — the legal documents are templates over the profile, and the exception list
// that once named their South Sudan sentences is empty. LegalContent.php is scanned with NO fallback allowance
// either: its sentences fall back to neutral, fact-derived forms, never to a tenant's wording.
$A2 = [];
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
foreach ($FILES as $rel) {
    $code = codeNC("$root/$rel");
    $hits = scanLiterals($code, $NEEDLES, $FALLBACK, $A2);
    is_($hits === [], "$rel: no South Sudan literal outside a fallback argument", implode("\n       ", array_slice($hits, 0, 8)));
}
// The contact fallbacks (A1.1: the WhatsApp number, the e-mail) stay fallback arguments; the WORDING needles may not
// appear in LegalContent.php in any form — the documents' sentences come from the profile or fall back to neutral forms.
$wordingHits = scanLiterals(codeNC("$root/lib/LegalContent.php"), ['Juba', 'South Sudan', 'Stanbic', 'Splynx', 'USD 25', 'USD 150'], '/(?!)/', []);
is_($wordingHits === [], 'lib/LegalContent.php carries no South Sudan wording at all (not even as a fallback argument)', implode("\n       ", array_slice($wordingHits, 0, 8)));
// The Uganda wording lives in the profile, so the code must not carry it either: a Uganda sentence in
// LegalContent.php would be the same defect with the tenants swapped.
is_(scanLiterals(codeNC("$root/lib/LegalContent.php"), ['Uganda', 'Kampala', '80046255496181', 'UCC'], '/(?!)/', []) === [], 'lib/LegalContent.php carries no Uganda wording either');
is_(scanLiterals("\$x = 'registered in Uganda';\n", ['Uganda'], '/(?!)/', []) !== [], 'control: a planted Uganda literal would be a hit');
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
