<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * mail_doctor.php — answer, with evidence, the three questions the email audit
 * could not verify from the code alone:
 *
 *   1. Is DNS complete?  SPF / DMARC / Brevo DKIM / this server's own DKIM
 *   2. Does uCRM's own mailer still work?  (it owns the 3 uCRM notification
 *      templates; the plugin was switched to plugin-only mail)
 *   3. Can the overdue dunning cron still send?  (it uses raw SMTP of its own,
 *      NOT MailService, so the Brevo switch may have left it stranded)
 *
 * Read-only. Sends nothing, changes nothing.
 *
 *   docker exec ucrm php /data/.../tools/mail_doctor.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/MailService.php';
require_once $root . '/lib/EmailTemplate.php';

$dataDir = getenv('DN_DATA_DIR') ?: getDataDir($root);
$config  = PluginConfig::load($root, $dataDir);
try { $store = SqliteStore::create($dataDir); } catch (\Throwable $e) { $store = null; }

$pass = 0; $warn = 0; $fail = 0;
function ok(string $m)   { global $pass; $pass++; echo "  PASS  {$m}\n"; }
function nk(string $m)   { global $warn; $warn++; echo "  WARN  {$m}\n"; }
function no(string $m)   { global $fail; $fail++; echo "  FAIL  {$m}\n"; }
function info(string $m) { echo "  info  {$m}\n"; }
function line()          { echo str_repeat('─', 62) . "\n"; }

$brand  = EmailTemplate::brand($config);
$domain = preg_replace('/^www\./', '', $brand['website']);

echo "DishNet mail doctor — " . gmdate('Y-m-d H:i') . " UTC\n";
echo "Domain under test: {$domain}\n\n";

// ── 1. DNS ───────────────────────────────────────────────────────────────────
line(); echo "1) DNS — can our mail be trusted?\n";

/** Resolve TXT records without requiring dig/host to be installed. */
function txt(string $name): array {
    $out = [];
    $recs = @dns_get_record($name, DNS_TXT) ?: [];
    foreach ($recs as $r) {
        if (!empty($r['txt']))     $out[] = (string)$r['txt'];
        elseif (!empty($r['entries'])) $out[] = implode('', $r['entries']);
    }
    return $out;
}
function cname(string $name): array {
    $out = [];
    foreach ((@dns_get_record($name, DNS_CNAME) ?: []) as $r) {
        if (!empty($r['target'])) $out[] = (string)$r['target'];
    }
    return $out;
}

$mx = @dns_get_record($domain, DNS_MX) ?: [];
if ($mx) {
    usort($mx, fn($a, $b) => ($a['pri'] ?? 0) <=> ($b['pri'] ?? 0));
    $names = array_map(fn($r) => ($r['pri'] ?? '?') . ' ' . ($r['target'] ?? '?'), $mx);
    count($mx) === 1 ? ok('MX: ' . $names[0]) : nk('MX has ' . count($mx) . ' records: ' . implode(' | ', $names));
} else { no('MX: none found — inbound mail cannot be delivered'); }

$spf = array_values(array_filter(txt($domain), fn($t) => stripos($t, 'v=spf1') === 0));
if ($spf) {
    ok('SPF: ' . $spf[0]);
    if (stripos($spf[0], '-all') === false) nk('SPF does not end in -all (soft policy)');
} else { no('SPF: missing — receivers cannot verify who may send for this domain'); }

$dmarc = array_values(array_filter(txt('_dmarc.' . $domain), fn($t) => stripos($t, 'v=DMARC1') === 0));
$dmarc ? ok('DMARC: ' . $dmarc[0]) : no('DMARC: missing');

$brevo = 0;
foreach (['brevo1._domainkey.' . $domain, 'brevo2._domainkey.' . $domain] as $n) {
    if (cname($n)) $brevo++;
}
$brevo >= 2 ? ok("Relay DKIM (Brevo): {$brevo}/2 records published — platform email is signed")
            : nk("Relay DKIM (Brevo): only {$brevo}/2 records found");

// The mail server's OWN DKIM — used when the server sends directly (webmail).
$own = 0; $ownFound = [];
foreach (['default', 'mail', 'stalwart', 'dkim'] as $sel) {
    $n = $sel . '._domainkey.' . $domain;
    if (txt($n) || cname($n)) { $own++; $ownFound[] = $sel; }
}
$own ? ok('Server DKIM: found selector(s) ' . implode(', ', $ownFound))
     : nk('Server DKIM: NOT published — mail sent directly by the mail server '
        . '(e.g. replies from webmail) is unsigned. Platform email via the relay is unaffected.');

// ── 2. The plugin's own mail path ────────────────────────────────────────────
line(); echo "2) Plugin mail (quotations, OTP, the new customer emails)\n";
$mail = new MailService($dataDir);
$cfg  = $mail->getConfig();
if (!empty($cfg['host'])) {
    ok('SMTP resolved: ' . $cfg['user'] . ' via ' . $cfg['host'] . ':' . $cfg['port'] . ' ' . strtoupper((string)$cfg['enc']));
    info('From: ' . ($cfg['from'] ?? '(unset)'));
    $es = is_file($dataDir . '/email_settings.json')
        ? (json_decode((string)@file_get_contents($dataDir . '/email_settings.json'), true) ?: []) : [];
    info('Mode: ' . (!empty($es['use_ucrm_email']) ? 'uCRM mailer first, plugin SMTP fallback' : 'PLUGIN-ONLY'));
    info('Quotation emails by plugin: ' . (!empty($es['quote_email_via_plugin']) ? 'ON (PDF attached)' : 'off'));
} else {
    no('SMTP not configured: ' . $mail->lastError());
}

// ── 3. uCRM's own mailer (owns the 3 uCRM notification templates) ───────────
line(); echo "3) uCRM's own mailer — does it still send invoice/payment notices?\n";
$ucrmJson = null;
foreach ([$root . '/ucrm.json', dirname($root) . '/ucrm.json', $dataDir . '/ucrm.json'] as $p) {
    if (is_file($p)) { $ucrmJson = json_decode((string)@file_get_contents($p), true); break; }
}
$base = rtrim((string)($ucrmJson['ucrmPublicUrl'] ?? $ucrmJson['ucrmLocalUrl'] ?? ''), '/');
$key  = (string)($ucrmJson['pluginAppKey'] ?? '');
if ($base === '' || $key === '') {
    nk('Could not read ucrm.json — uCRM mailer state NOT VERIFIED');
} else {
    $ch = curl_init($base . '/api/v1.0/settings');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => ['x-auth-app-key: ' . $key, 'Accept: application/json']]);
    $raw = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $s = json_decode($raw, true);
    if ($code !== 200 || !is_array($s)) {
        nk("uCRM settings API answered HTTP {$code} — mailer state NOT VERIFIED");
    } else {
        $host = (string)($s['mailerHost'] ?? '');
        $tr   = (string)($s['mailerTransport'] ?? '');
        if ($host !== '') {
            ok('uCRM mailer configured: ' . $tr . ' ' . $host . ':' . (string)($s['mailerPort'] ?? '?')
               . ' as ' . (string)($s['mailerUsername'] ?? '(no auth)'));
            info('So uCRM CAN still send its own invoice/payment notification emails.');
        } else {
            no('uCRM mailer is NOT configured — the three uCRM notification templates '
               . '(new invoice, overdue, payment received) send NOTHING.');
            info('Either configure uCRM System → Settings → Mailer, or move those notices into the plugin.');
        }
    }
}

// ── 4. The overdue dunning cron's own SMTP ──────────────────────────────────
line(); echo "4) Overdue dunning cron — it uses its own SMTP, not MailService\n";
require_once $root . '/lib/OverdueDunningHelpers.php';
if (!function_exists('_getSmtpSettings')) {
    nk('_getSmtpSettings() not available — NOT VERIFIED');
} elseif ($store === null) {
    nk('data store unavailable — NOT VERIFIED');
} else {
    $sm = _getSmtpSettings($store, $config);
    if (!$sm || empty($sm['host'])) {
        no('Dunning cron has NO SMTP settings — every overdue email it tries to send fails silently.');
        info("It reads email_settings.json / kyc_config smtp_* keys, which the Brevo switch did populate,");
        info('so if this fails the keys are named differently than it expects.');
    } else {
        ok('Dunning SMTP resolved: ' . ($sm['user'] ?? '?') . ' via ' . $sm['host'] . ':' . ($sm['port'] ?? '?'));
        if (!empty($cfg['host']) && $sm['host'] !== $cfg['host']) {
            nk('It uses a DIFFERENT server than the plugin (' . $sm['host'] . ' vs ' . $cfg['host'] . ')');
        }
    }
    info('NOTE: its copy assumes a postpaid, already-suspended customer. Uganda sells prepaid —');
    info('see docs/UGANDA-EMAIL-LIFECYCLE-AUDIT.md §1.8. Content fix is separate from delivery.');
}

// ── 5. Branding of what we will actually send ───────────────────────────────
line(); echo "5) Branding of the customer emails\n";
require_once $root . '/lib/CustomerEmails.php';
$sudan = 0; $rendered = 0;
foreach (array_keys(CustomerEmails::CATALOGUE) as $k) {
    try {
        $m = CustomerEmails::render($k, $config, ['customer_name' => 'Test Customer', 'code' => '000000']);
        $rendered++;
        $sudan += preg_match_all('/\+211|South Sudan|Juba|dishnetafrica\.com/i', $m['html'] . $m['text']);
    } catch (\Throwable $e) { no("template {$k} failed to render: " . $e->getMessage()); }
}
info("Templates rendered: {$rendered}/" . count(CustomerEmails::CATALOGUE));
$sudan === 0 ? ok('No South Sudan references in any customer email')
             : no("{$sudan} South Sudan reference(s) still present — set the email_* keys in Configuration");
info('Company: ' . $brand['company_name'] . ' · ' . $brand['locality']);
info('Support: ' . $brand['support_phone'] . ' · ' . $brand['website']);
info('Legal:   ' . ($brand['legal_line'] !== '' ? $brand['legal_line'] : '(not set)'));
info('Badge:   ' . ($brand['badge_line'] !== '' ? $brand['badge_line'] : '(not set)'));

line();
printf("RESULT: %d pass · %d warn · %d fail\n", $pass, $warn, $fail);
exit($fail > 0 ? 1 : 0);
