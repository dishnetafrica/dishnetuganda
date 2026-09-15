<?php
declare(strict_types=1);
/**
 * test_invoice_email_attachment.php — the invoice e-mail carries the invoice.
 *
 * Before 5.18.6 the invoice.add webhook sent an e-mail whose template said
 * "A PDF copy is attached for your records" and attached nothing: the
 * dispatcher accepted attachments, the caller passed none. It also handed the
 * template a 'plan' key the template never read. Nobody had seen either,
 * because the invoice switch had never been turned on.
 *
 * This drives the REAL handler: webhook.php served by PHP's built-in server,
 * the plugin's own uCRM client pointed at a fake uCRM, its own MailService
 * pointed at a fake SMTP relay that writes down what it was told. The
 * assertions read what actually crossed the wire. WhatsApp runs in dry-run
 * mode and is read from its log.
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

$tmp = sys_get_temp_dir() . '/inv_email_' . getmypid();
exec('rm -rf ' . escapeshellarg($tmp)); @mkdir($tmp, 0777, true);
$dataDir = $tmp . '/data'; @mkdir($dataDir, 0777, true);
$procs = [];
register_shutdown_function(function () use (&$procs, $tmp) {
    foreach ($procs as $p) { if (is_resource($p)) { proc_terminate($p); proc_close($p); } }
    exec('rm -rf ' . escapeshellarg($tmp));
});

$http = function (string $url, ?string $body = null, array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 3,
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
    9900, 7, 80);
if ($crmPort === 0) { echo "  FAIL could not start the fake uCRM\n"; exit(1); }
$crmReqs = function () use ($http, $crmPort): array { [, $b] = $http("http://127.0.0.1:{$crmPort}/__test/requests"); return (array)((json_decode((string)$b, true) ?: [])['requests'] ?? []); };
$crmReset = function () use ($http, $crmPort): void { $http("http://127.0.0.1:{$crmPort}/__test/reset"); };
$pdfHits = function (int $id) use ($crmReqs): int { return count(array_filter($crmReqs(), fn($u) => strpos((string)$u, "/invoices/{$id}/pdf") !== false)); };

// The bytes uCRM serves for invoice 901 — fetched once, so the attachment can
// be compared with the file rather than merely found.
[$c0, $expectedPdf] = $http("http://127.0.0.1:{$crmPort}/invoices/901/pdf");
is_($c0 === 200 && is_string($expectedPdf) && strncmp($expectedPdf, '%PDF', 4) === 0 && strlen($expectedPdf) >= 100,
    'the fake uCRM serves a PDF for invoice 901');
$crmReset();

// ── The fake relay ─────────────────────────────────────────────────────────
$transcript = $tmp . '/smtp.json';
$smtpPort = $start(sprintf('exec php %s {PORT} %s', escapeshellarg($root . '/tests/fixtures/fake_smtp_server.php'), escapeshellarg($transcript)),
    function (int $port) use ($transcript): bool {
        if (!is_file($transcript)) return false;
        $s = @fsockopen('127.0.0.1', $port, $e1, $e2, 2); if (!$s) return false;
        $g = fgets($s, 256); @fclose($s); return strpos((string)$g, 'fake.smtp.test') !== false;
    }, 10000, 13, 60);
if ($smtpPort === 0) { echo "  FAIL could not start the fake SMTP relay\n"; exit(1); }
// Only conversations that carried a message: the readiness probe above opens
// a connection and hangs up, which the relay writes down as an empty session.
$sessions = function () use ($transcript): array {
    $all = json_decode((string)@file_get_contents($transcript), true) ?: [];
    return array_values(array_filter($all, fn($x) => trim((string)($x['data'] ?? '')) !== ''));
};

// ── The plugin's configuration for this run ────────────────────────────────
// The store first, the files second — the order a real install has. A store
// created in a directory that already holds JSON files imports them and
// renames them *.migrated; MailService reads email_settings.json as a FILE,
// so written first it would vanish on the handler's first request.
SqliteStore::create($dataDir);
file_put_contents($dataDir . '/email_settings.json', json_encode([
    'use_ucrm_email' => false, 'smtp_host' => '127.0.0.1', 'smtp_port' => $smtpPort,
    'smtp_user' => '', 'smtp_pass' => '', 'smtp_enc' => '', 'smtp_from' => 'accounts@dishnetuganda.com',
], JSON_PRETTY_PRINT));
$writeCfg = function (bool $invoiceOn) use ($dataDir, $crmPort): void {
    file_put_contents($dataDir . '/kyc_config.json', json_encode([
        'customer_emails_enabled' => '1',
        'customer_email_invoice'  => $invoiceOn ? '1' : '',
        'crm_base_url'   => "http://127.0.0.1:{$crmPort}", 'crm_auth_token' => 'SHADOWKEY',
        'crm_public_url' => 'https://crm.example.test',
        'dry_run_mode'   => true, 'data_dir' => $dataDir,
        'wa_plugin_url'  => 'http://127.0.0.1:1/', 'wa_app_key' => 'k', 'wa_auth_key' => 'a',
        'webhook_secret' => 'testsecret',
        'email_company_name' => 'DishNet Africa Limited', 'email_locality' => 'Kampala, Uganda',
        'email_support_phone' => '+256 705 993 348', 'email_currency' => 'UGX',
        'email_reply_to' => 'accounts@dishnetuganda.com', 'email_website' => 'dishnetuganda.com',
    ], JSON_PRETTY_PRINT));
};
$writeCfg(false);

// ── The plugin itself, served the way uCRM serves it ───────────────────────
// public.php's crm_webhook route sets $dataDir / $store / $config and then
// requires webhook.php, whose bootstrap is guarded by !isset() for exactly
// that. This router is that route with the data directory chosen by the
// test: DN_DATA_DIR is honoured from the command line only, never by a web
// request, so the environment cannot carry it into php -S.
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
    10100, 11, 80, $env);
if ($webPort === 0) { echo "  FAIL could not start the plugin under php -S\n"; exit(1); }

$fire = function (int $invoiceId) use ($http, $webPort): array {
    $payload = json_encode(['changeType' => 'invoice.add', 'entity' => 'invoice', 'entityId' => $invoiceId,
                            'uuid' => 'test-' . $invoiceId, 'extraData' => ['entity' => ['id' => $invoiceId]]]);
    return $http("http://127.0.0.1:{$webPort}/webhook.php", $payload,
                 ['Content-Type: application/json', 'X-Ucrm-Key: testsecret']);
};
$waLog  = function () use ($dataDir): string { return (string)@file_get_contents($dataDir . '/dry_run_notification_log.json'); };
$whLog  = function () use ($dataDir): string { return (string)@file_get_contents($dataDir . '/webhook_log.json'); };
$noBlankFact = function (string $text): bool { return preg_match('/^(?!How to pay:)[^\r\n:]{1,40}:[ \t]*\r?$/m', $text) === 0; };

// ═════════════════════════════════════════════════
echo "\n1. Switch off: WhatsApp goes out, no e-mail, and uCRM's PDF is asked for once\n";
// ═════════════════════════════════════════════════
[$code, $body] = $fire(903);
t('the webhook is processed',                         $code === 200 && strpos((string)$body, 'invoice.add processed') !== false, true);
t('no e-mail crossed the wire',                       count($sessions()), 0);
is_(strpos($waLog(), 'ops_invoice_created') !== false, 'the WhatsApp invoice notice was sent (dry-run log)');
is_(strpos($whLog(), 'PDF send: FAILED') !== false,    'uCRM had no PDF for 903, and the log says so');
t('uCRM was asked for the PDF exactly once',          $pdfHits(903), 1);

// ═════════════════════════════════════════════════
echo "\n2. Switch on: the e-mail carries the same PDF uCRM rendered for WhatsApp\n";
// ═════════════════════════════════════════════════
$writeCfg(true); $crmReset();
[$code, $body] = $fire(901);
t('the webhook is processed',                         $code === 200 && strpos((string)$body, 'invoice.add processed') !== false, true);
$s = $sessions();
t('exactly one e-mail crossed the wire',              count($s), 1);
$m = $s[0] ?? []; $data = (string)($m['data'] ?? '');
is_(in_array('irene@example.test', (array)($m['rcpt_to'] ?? []), true), 'to the billing contact', json_encode($m['rcpt_to'] ?? null));
t('envelope sender is the accounts mailbox',          $m['mail_from'] ?? null, 'accounts@dishnetuganda.com');
t('one attachment, named after the invoice',
  substr_count($data, 'Content-Disposition: attachment') === 1
  && strpos($data, 'Content-Disposition: attachment; filename="Invoice-INV-000901.pdf"') !== false, true);
is_(strpos($data, 'Content-Type: application/pdf; name="Invoice-INV-000901.pdf"') !== false, 'declared as application/pdf');
// The attachment IS the file uCRM served — decoded and compared byte for byte.
$pos = strpos($data, 'Content-Disposition: attachment; filename="Invoice-INV-000901.pdf"');
$bodyStart = $pos !== false ? strpos($data, "\r\n\r\n", $pos) : false;
$bodyEnd   = $bodyStart !== false ? strpos($data, "\r\n--", $bodyStart + 4) : false;
$b64 = ($bodyStart !== false && $bodyEnd !== false) ? substr($data, $bodyStart + 4, $bodyEnd - $bodyStart - 4) : '';
$got = base64_decode(preg_replace('/\s+/', '', $b64) ?? '', true);
t('the attachment decodes to exactly the bytes uCRM served', $got === $expectedPdf, true);
// The words, as the customer reads them.
is_(strpos($data, 'Dear Irene,') !== false,                                  'greets the customer by first name');
is_(strpos($data, 'is ready (PDF attached).') !== false,                      'the text part says the PDF is attached — because it is');
is_(strpos($data, 'A PDF copy is attached for your records.') !== false,     'so does the HTML part');
is_(strpos($data, 'Invoice: INV-000901') !== false,                          'invoice number');
is_(strpos($data, 'Plan: Residential (up to 400 Mbps)') !== false,           'the plan, read from the uCRM line item');
is_(strpos($data, 'Service period: 1 Oct 2026 – 31 Oct 2026') !== false,     'the period, read from the same line');
is_(strpos($data, 'Amount due: UGX 329,000') !== false,                       'the amount in the configured currency');
is_(strpos($data, 'Due date: 20 September 2026') !== false,                   'the due date written out');
is_($noBlankFact($data),                                                      'no fact label without a value anywhere in the message');
is_(strpos($data, '+211') === false && stripos($data, 'South Sudan') === false, 'no Sudan contact details');
t('uCRM rendered the PDF once for both channels',     $pdfHits(901), 1);
is_(strpos($waLog(), 'INV-000901.pdf') !== false,     'the WhatsApp document went out too (dry-run log)');
try {
    $pdo  = SqliteStore::create($dataDir)->getPdo();
    $rows = $pdo->query("SELECT template, status, recipient FROM customer_email_log ORDER BY created_at")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) { $rows = []; }   // a missing table is a failed assertion below, not a crash
t('the send is remembered in customer_email_log',      count($rows), 1);
t('...as a delivered invoice e-mail to that address',  [$rows[0]['template'] ?? '', $rows[0]['status'] ?? '', $rows[0]['recipient'] ?? ''], ['invoice', 'sent', 'irene@example.test']);

// ═════════════════════════════════════════════════
echo "\n3. uCRM retries the webhook: nothing is sent or fetched again\n";
// ═════════════════════════════════════════════════
[$code, $body] = $fire(901);
is_($code === 200 && strpos((string)$body, 'already notified') !== false, 'the replay is recognised', (string)$body);
t('still exactly one e-mail',                          count($sessions()), 1);
t('still exactly one PDF render',                      $pdfHits(901), 1);

// ═════════════════════════════════════════════════
echo "\n4. uCRM has no PDF: the facts still go out, the claim does not\n";
// ═════════════════════════════════════════════════
[$code, $body] = $fire(905);
t('the webhook is processed',                          $code === 200 && strpos((string)$body, 'invoice.add processed') !== false, true);
$s = $sessions();
t('a second e-mail crossed the wire',                  count($s), 2);
$data2 = (string)($s[1]['data'] ?? '');
is_(strpos($data2, 'Content-Disposition: attachment') === false, 'nothing is attached');
is_(strpos($data2, 'Invoice: INV-000905') !== false && strpos($data2, 'Amount due: UGX 329,000') !== false, 'the facts are there');
is_(strpos($data2, 'is ready.') !== false && strpos($data2, 'PDF attached') === false, 'the text part does not claim a PDF');
is_(strpos($data2, 'PDF copy is attached') === false,             'nor does the HTML part');
is_($noBlankFact($data2),                                         'and still no fact label without a value');

// ═════════════════════════════════════════════════
echo "\n5. The wiring, read from the code\n";
// ═════════════════════════════════════════════════
$wh = codeNC($root . '/webhook.php');
is_(strpos($wh, "string \$changeType = '', array \$attachments = []): void") !== false, 'whCustomerEmail() accepts attachments');
$a = strpos($wh, "case 'invoice.add':"); $b = strpos($wh, "case 'payment.add':");
$blk = ($a !== false && $b !== false && $b > $a) ? substr($wh, $a, $b - $a) : '';
is_($blk !== '',                                                    'the invoice.add handler is where it was');
is_(substr_count($blk, 'whInvoicePdfBytes($crm, $invoiceId)') === 1, 'it fetches the PDF once');
is_(strpos($blk, "'plan_name'") !== false && strpos($blk, "'period'") !== false, 'it passes the keys the template reads');
is_(strpos($blk, "'plan' ") === false && strpos($blk, "'plan'=>") === false,    'the unread key is gone');
is_(strpos($blk, "'pdf_attached'   => \$invoicePdf !== ''") !== false,           'it tells the template whether a PDF is attached');
is_(strpos($blk, '"Invoice-{$invoNum}.pdf"') !== false,                          'the attachment is named after the invoice');
is_(substr_count($wh, 'getRawContent("invoices/{$invoiceId}/pdf")') === 1,      'one place fetches an invoice PDF');
$ce = codeNC($root . '/lib/CustomerEmails.php');
is_(substr_count($ce, "!empty(\$d['pdf_attached'])") === 2,                      'both PDF-bearing templates read pdf_attached');
foreach (['lib/QuotationService.php', 'tools/quote_email_send.php'] as $f) {
    is_(strpos(codeNC($root . '/' . $f), "'pdf_attached'") !== false, "$f says whether it attached the quotation");
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
