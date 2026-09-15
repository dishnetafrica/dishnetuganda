<?php
declare(strict_types=1);
/**
 * test_lifecycle_email_wiring.php — the five remaining lifecycle e-mails say
 * true things, with the facts filled in, on the events that mean them.
 *
 * Read before switching them on (15 Sep 2026), every one of the five had the
 * defect the invoice e-mail had: the webhook handed the template keys it did
 * not read. Two would have gone out with an empty subject ("paused —  to
 * resume", "We have your request — "). Two fired on events that do not mean
 * what the e-mail says: uCRM's service.activate announces a first activation
 * and a resumption alike, and job.add fires for a repair visit as much as for
 * an installation.
 *
 * This drives the REAL handler under php -S against the fake uCRM and the
 * fake SMTP relay, and reads what crossed the wire.
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

$tmp = sys_get_temp_dir() . '/lifecycle_email_' . getmypid();
exec('rm -rf ' . escapeshellarg($tmp)); @mkdir($tmp, 0777, true);
$dataDir = $tmp . '/data'; @mkdir($dataDir, 0777, true);
$procs = [];
register_shutdown_function(function () use (&$procs, $tmp) {
    foreach ($procs as $p) { if (is_resource($p)) { proc_terminate($p); proc_close($p); } }
    exec('rm -rf ' . escapeshellarg($tmp));
});

$http = function (string $url, ?string $body = null, array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_CONNECTTIMEOUT => 3,
                            CURLOPT_HTTPHEADER => $headers]);
    if ($body !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
    $out = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, $out === false ? null : (string)$out];
};
$start = function (string $cmd, callable $isUp, int $base, int $step, int $span, array $env = null) use (&$procs): int {
    foreach (range(0, 9) as $slot) {
        $port = $base + ((getmypid() + $slot * $step) % $span);
        $p = proc_open(str_replace('{PORT}', (string)$port, $cmd),
                       [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);
        $ok = false;
        for ($i = 0; $i < 50 && !$ok; $i++) { usleep(100000); $ok = $isUp($port); }
        if ($ok) { $procs[] = $p; return $port; }
        if (is_resource($p)) { proc_terminate($p); proc_close($p); }
    }
    return 0;
};

// ── The fake uCRM ──────────────────────────────────────────────────────────
array_map('unlink', glob(sys_get_temp_dir() . '/fake_ucrm_shadow_*.json') ?: []);
$crmPort = $start(sprintf('exec php -S 127.0.0.1:{PORT} %s', escapeshellarg($root . '/tests/fixtures/fake_ucrm_shadow.php')),
    function (int $port) use ($http): bool { [, $b] = $http("http://127.0.0.1:{$port}/__test/state"); return $b !== null && strpos($b, 'FAKE-UCRM-SHADOW') !== false; },
    9900, 9, 80);
if ($crmPort === 0) { echo "  FAIL could not start the fake uCRM\n"; exit(1); }
$crmReqs = function () use ($http, $crmPort): array { [, $b] = $http("http://127.0.0.1:{$crmPort}/__test/requests"); return (array)((json_decode((string)$b, true) ?: [])['requests'] ?? []); };

// ── The fake relay ─────────────────────────────────────────────────────────
$transcript = $tmp . '/smtp.json';
$smtpPort = $start(sprintf('exec php %s {PORT} %s', escapeshellarg($root . '/tests/fixtures/fake_smtp_server.php'), escapeshellarg($transcript)),
    function (int $port) use ($transcript): bool {
        if (!is_file($transcript)) return false;
        $s = @fsockopen('127.0.0.1', $port, $e1, $e2, 2); if (!$s) return false;
        $g = fgets($s, 256); @fclose($s); return strpos((string)$g, 'fake.smtp.test') !== false;
    }, 10000, 17, 60);
if ($smtpPort === 0) { echo "  FAIL could not start the fake SMTP relay\n"; exit(1); }
$sessions = function () use ($transcript): array {
    $all = json_decode((string)@file_get_contents($transcript), true) ?: [];
    return array_values(array_filter($all, fn($x) => trim((string)($x['data'] ?? '')) !== ''));
};

// ── The plugin's configuration for this run (store first, files second) ────
SqliteStore::create($dataDir);
file_put_contents($dataDir . '/email_settings.json', json_encode([
    'use_ucrm_email' => false, 'smtp_host' => '127.0.0.1', 'smtp_port' => $smtpPort,
    'smtp_user' => '', 'smtp_pass' => '', 'smtp_enc' => '', 'smtp_from' => 'accounts@dishnetuganda.com',
], JSON_PRETTY_PRINT));
file_put_contents($dataDir . '/kyc_config.json', json_encode([
    'customer_emails_enabled'        => '1',
    'customer_email_welcome'         => '1',
    'customer_email_service_paused'  => '1',
    'customer_email_service_resumed' => '1',
    'customer_email_install_scheduled' => '1',
    'customer_email_support_received'  => '1',
    'crm_base_url'   => "http://127.0.0.1:{$crmPort}", 'crm_auth_token' => 'SHADOWKEY',
    'crm_public_url' => 'https://crm.example.test',
    'contact_pay_url' => 'https://pay.example.test/now',
    'dry_run_mode'   => true, 'data_dir' => $dataDir,
    'wa_plugin_url'  => 'http://127.0.0.1:1/', 'wa_app_key' => 'k', 'wa_auth_key' => 'a',
    'webhook_secret' => 'testsecret',
    'email_company_name' => 'DishNet Africa Limited', 'email_locality' => 'Kampala, Uganda',
    'email_support_phone' => '+256 705 993 348', 'email_currency' => 'UGX',
    'email_reply_to' => 'accounts@dishnetuganda.com', 'email_website' => 'dishnetuganda.com',
], JSON_PRETTY_PRINT));

// ── The plugin, served the way public.php's crm_webhook route serves it ───
file_put_contents($tmp . '/router.php', '<?php
declare(strict_types=1);
$root = ' . var_export($root, true) . ';
require_once $root . "/lib/timezone.php"; dn_tz_apply();
require_once $root . "/lib/StoreInterface.php";
require_once $root . "/lib/SqliteStore.php";
$dataDir = ' . var_export($dataDir, true) . ';
$store   = SqliteStore::create($dataDir);
$config  = $store->load("kyc_config.json") ?? [];
require $root . "/webhook.php";
');
$env = array_merge(getenv(), ['DN_VAULT_FILE' => (string)getenv('DN_VAULT_FILE')]);
$webPort = $start(sprintf('exec php -S 127.0.0.1:{PORT} %s', escapeshellarg($tmp . '/router.php')),
    function (int $port) use ($http): bool { [, $b] = $http("http://127.0.0.1:{$port}/webhook.php"); return $b !== null && strpos($b, 'POST required') !== false; },
    10100, 19, 80, $env);
if ($webPort === 0) { echo "  FAIL could not start the plugin under php -S\n"; exit(1); }

$fire = function (string $changeType, string $entityType, int $entityId, array $entity = []) use ($http, $webPort): array {
    $payload = json_encode(['changeType' => $changeType, 'entity' => $entityType, 'entityId' => $entityId,
                            'uuid' => "test-{$changeType}-{$entityId}", 'extraData' => ['entity' => $entity + ['id' => $entityId]]]);
    return $http("http://127.0.0.1:{$webPort}/webhook.php", $payload, ['Content-Type: application/json', 'X-Ucrm-Key: testsecret']);
};
$processed = function (array $r, string $what): bool { return $r[0] === 200 && strpos((string)$r[1], $what) !== false; };
$whLog  = function () use ($dataDir): string { return (string)@file_get_contents($dataDir . '/webhook_log.json'); };
$noBlankFact = function (string $text): bool { return preg_match('/^(?!How to pay:)[^\r\n:]{1,40}:[ \t]*\r?$/m', $text) === 0; };
$kv = function (string $key) use ($dataDir): bool {
    try { $q = SqliteStore::create($dataDir)->getPdo()->prepare("SELECT value FROM plugin_kv WHERE key = ?"); $q->execute([$key]); return $q->fetchColumn() !== false; }
    catch (\Throwable $e) { return false; }
};
$last = function () use ($sessions): string { $s = $sessions(); return (string)(($s[count($s) - 1] ?? [])['data'] ?? ''); };

// ═════════════════════════════════════════════════
echo "\n1. A service created active: the welcome, with the account facts filled in\n";
// ═════════════════════════════════════════════════
$r = $fire('service.add', 'service', 601);
is_($r[0] === 200, 'service.add is processed', (string)$r[1]);
t('one e-mail crossed the wire',                       count($sessions()), 1);
$d = $last();
is_(strpos($d, 'Dear Irene,') !== false,                                     'greets by first name');
is_(strpos($d, 'Welcome to DishNet') !== false,                              'it is the welcome');
is_(strpos($d, 'Plan: Residential (up to 400 Mbps)') !== false,              'the plan, from servicePlanName');
is_(strpos($d, 'Monthly price: UGX 329,000') !== false,                      'the monthly price');
is_(strpos($d, 'Activated on: 14 September 2026') !== false,                 'the activation date, as uCRM wrote it');
is_(strpos($d, 'Account number: DN-UG-10015') !== false,                     'the account number (userIdent)');
is_(strpos($d, 'Service address: Plot 14, Nakawa, Kampala') !== false,       'the service address');
is_($noBlankFact($d),                                                         'no fact label without a value');

// ═════════════════════════════════════════════════
echo "\n2. A suspension: the paused e-mail names what resumes the service, and the pause is remembered\n";
// ═════════════════════════════════════════════════
$r = $fire('service.suspend', 'service', 602);
is_($r[0] === 200, 'service.suspend is processed', (string)$r[1]);
t('a second e-mail crossed the wire',                  count($sessions()), 2);
$d = $last();
is_(strpos($d, 'Dear Moses,') !== false,                                     'greets the paused customer by first name');
is_(strpos($d, 'Your service is paused') !== false,                          'it is the paused e-mail');
is_(strpos($d, 'Invoice: INV-000916') !== false,                             'the unpaid invoice number');
is_(strpos($d, 'Amount to resume: UGX 329,000') !== false,                   'the amount that resumes it');
is_(strpos($d, 'Payment reference: INV-000916') !== false,                   'the payment reference');
is_(strpos($d, 'UGX 329,000 resumes it') !== false,                          'the preheader carries the amount, so the subject did too');
is_(strpos($d, 'https://pay.example.test/now') !== false,                     'the configured pay link is offered');
is_(strpos($d, 'dishnetafrica.com/tutorials') === false,                     'never the Sudan default');
is_($noBlankFact($d),                                                         'no fact label without a value');
is_($kv('paused_svc_602'),                                                    'the pause is remembered against the service');
is_(count(array_filter($crmReqs(), fn($u) => strpos((string)$u, 'clientId=16') !== false && strpos((string)$u, 'statuses') !== false)) >= 1,
    'the unpaid invoices were read from uCRM');

// ═════════════════════════════════════════════════
echo "\n3. Activation after a pause, right after a payment: resumed, with the payment acknowledged\n";
// ═════════════════════════════════════════════════
file_put_contents($dataDir . '/recent_payment_16.marker', json_encode(['ts' => time()]));
$r = $fire('service.activate', 'service', 602);
is_($r[0] === 200, 'service.activate is processed', (string)$r[1]);
t('a third e-mail crossed the wire',                   count($sessions()), 3);
$d = $last();
is_(strpos($d, 'Your payment has been received and your internet is active again.') !== false, 'says the payment was received — one was');
is_(strpos($d, 'Plan: Residential (up to 400 Mbps)') !== false,              'names the plan');
is_(strpos($d, 'Dear Moses,') !== false,                                     'to the right customer');
is_(!$kv('paused_svc_602'),                                                   'the pause marker is cleared');
is_(strpos($whLog(), 'Service restored notification SKIPPED') !== false,     'WhatsApp stood aside for the receipt; the e-mail did not');

// ═════════════════════════════════════════════════
echo "\n4. Activation after a pause, no payment seen: active again, no payment claimed\n";
// ═════════════════════════════════════════════════
$r = $fire('service.suspend', 'service', 603);
is_($r[0] === 200, 'the second suspension is processed', (string)$r[1]);
t('no e-mail: one paused notice per customer per day',  count($sessions()), 3);
is_($kv('paused_svc_603'),                                                    'but the pause is still remembered');
$r = $fire('service.activate', 'service', 603);
t('the resumption e-mail goes out',                    count($sessions()), 4);
$d = $last();
is_(strpos($d, 'Your internet is active again.') !== false,                  'says the service is back');
is_(strpos($d, 'payment has been received') === false,                       'and does not claim a payment nobody saw');

// ═════════════════════════════════════════════════
echo "\n5. Activation of a service never paused: a first activation, so the welcome, not a resumption\n";
// ═════════════════════════════════════════════════
$r = $fire('service.activate', 'service', 604);
t('an e-mail goes out',                                count($sessions()), 5);
$d = $last();
is_(strpos($d, 'Welcome to DishNet') !== false,                              'it is the welcome');
is_(strpos($d, 'active again') === false && strpos($d, 'back online') === false, 'not a resumption');
is_(strpos($d, 'Account number: DN-UG-10015') !== false,                     'with the account facts');
$r = $fire('service.activate', 'service', 601);
t('activating the service that was created active sends nothing more', count($sessions()), 5);
$r = $fire('service.suspend_cancel', 'service', 604);
t('a cancelled pending suspension sends nothing',      count($sessions()), 5);

// ═════════════════════════════════════════════════
echo "\n6. Jobs: an installation with a date is announced; anything else is not\n";
// ═════════════════════════════════════════════════
$r = $fire('job.add', 'job', 701);
is_($r[0] === 200, 'job.add is processed', (string)$r[1]);
t('the installation e-mail goes out',                  count($sessions()), 6);
$d = $last();
is_(strpos($d, 'Dear Irene,') !== false,                                     'to the customer, by first name');
is_(strpos($d, 'installation is booked') !== false,                          'it is the installation e-mail');
is_(strpos($d, 'Date: Monday 5 October 2026') !== false,                      'the day the office picked, in uCRM\'s own offset');
is_(strpos($d, 'Time: 9:00 AM - 12:00 PM') !== false,                         'the time window, likewise');
is_(strpos($d, 'Address: Plot 14, Nakawa, Kampala') !== false,               'the address');
is_(strpos($d, 'Technician: Joseph Tech') !== false,                         'the technician by name');
is_(strpos($d, 'Contact:') === false,                                        'and not the technician\'s phone');
is_($noBlankFact($d),                                                         'no fact label without a value');
$r = $fire('job.add', 'job', 702);
t('a repair visit sends nothing',                      count($sessions()), 6);
is_(strpos($whLog(), 'not an installation job') !== false,                   'and the log says why');
$r = $fire('job.add', 'job', 703);
t('an installation without a date sends nothing',      count($sessions()), 6);
is_(strpos($whLog(), 'no date yet') !== false,                               'and the log says why');

// ═════════════════════════════════════════════════
echo "\n7. Tickets: a customer's ticket is acknowledged with its reference; a ticket with no client is not\n";
// ═════════════════════════════════════════════════
$r = $fire('ticket.add', 'ticket', 801, ['subject' => 'Slow in the evenings', 'clientId' => 15]);
is_($r[0] === 200, 'ticket.add is processed', (string)$r[1]);
t('the acknowledgement goes out',                      count($sessions()), 7);
$d = $last();
is_(strpos($d, 'Dear Irene,') !== false,                                     'by first name');
is_(strpos($d, 'Reference: #801') !== false,                                 'the ticket reference');
is_(strpos($d, 'Subject: Slow in the evenings') !== false,                   'the subject as typed');
is_(preg_match('/Logged: \d{1,2} [A-Z][a-z]+ 20\d\d, \d\d:\d\d/', $d) === 1,  'when it was logged');
is_(strpos($d, 'Account: DN-UG-10015') !== false,                            'the account number');
is_(strpos($d, 'We have your request') !== false,                            'the title');
$r = $fire('ticket.add', 'ticket', 802, ['subject' => 'Public enquiry']);
t('a ticket without a client sends nothing',           count($sessions()), 7);

// ═════════════════════════════════════════════════
echo "\n8. The audit log agrees with the wire\n";
// ═════════════════════════════════════════════════
try {
    $rows = SqliteStore::create($dataDir)->getPdo()->query("SELECT template, status FROM customer_email_log WHERE status='sent' ORDER BY created_at")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) { $rows = []; }
$byTpl = [];
foreach ($rows as $row) $byTpl[$row['template']] = ($byTpl[$row['template']] ?? 0) + 1;
ksort($byTpl);
t('seven delivered rows, by template',
  $byTpl, ['install_scheduled' => 1, 'service_paused' => 1, 'service_resumed' => 2, 'support_received' => 1, 'welcome' => 2]);

// ═════════════════════════════════════════════════
echo "\n9. The wiring, read from the code: every key a webhook passes is one its template reads\n";
// ═════════════════════════════════════════════════
$wh = codeNC($root . '/webhook.php');
$ce = codeNC($root . '/lib/CustomerEmails.php');
preg_match_all("/\\\$d\\['([a-z_]+)'\\]/", $ce, $rm);
$read = array_unique($rm[1]);
preg_match_all("/whCustomerEmail\\('([a-z_]+)',.*?\\[(.*?)\\],\\s*\"[A-Z]+/s", $wh, $calls, PREG_SET_ORDER);
is_(count($calls) >= 8, 'the lifecycle call sites are found', 'found ' . count($calls));
$unread = [];
foreach ($calls as $call) {
    preg_match_all("/'([a-z_]+)'\\s*=>/", $call[2], $km);
    foreach ($km[1] as $k) if (!in_array($k, $read, true)) $unread[] = $call[1] . '.' . $k;
}
t('no call passes a key its template never prints', $unread, []);
is_(substr_count($wh, 'whMarkPaused($store, (int)$serviceId, true)') === 1,  'a suspension is remembered once');
is_(substr_count($wh, 'whWasPaused($store, (int)$serviceId)') === 1,         'an activation asks whether it was');
is_(strpos($wh, "preg_match('/install|setup|set-up|mount/i'") !== false,     'only installation jobs reach the customer');
is_(substr_count($ce, "!empty(\$d['paid'])") === 1,                          'the resumed template reads paid');
is_(strpos($wh, "'plan' => \$svcName") === false,                            'the unread plan key is gone from every call');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
