<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * quote_email_doctor.php — why did (or didn't) the customer get our quotation?
 *
 *   php tools/quote_email_doctor.php
 *
 * There are two mailers in play and it is easy to watch the wrong one. uCRM
 * has its own, whose failures show up in uCRM's Email Log; the plugin has its
 * own, which goes out through the relay and never touches uCRM. A resend from
 * uCRM's log exercises uCRM's mailer only — it cannot make the plugin send.
 *
 * This reports the plugin's half: is the switch on, is the webhook registered,
 * did quote.add arrive, and what did our sender do with it.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/CrmApiClient.php';
require_once $root . '/lib/CustomerEmailDispatcher.php';
require_once $root . '/lib/MailService.php';

$dataDir = cliDataDir($root);
$config  = PluginConfig::load($root, $dataDir);

function line(): void { echo str_repeat('─', 66) . "\n"; }
$pass = 0; $warn = 0; $failn = 0;
function ok(string $m): void { global $pass;  $pass++;  echo "  ok    {$m}\n"; }
function wr(string $m): void { global $warn;  $warn++;  echo "  warn  {$m}\n"; }
function no(string $m): void { global $failn; $failn++; echo "  FAIL  {$m}\n"; }

line();
echo "1) Is the plugin allowed to send a quotation at all?\n";
$master = CustomerEmailDispatcher::masterEnabled($config);
$quote  = CustomerEmailDispatcher::enabled('quotation', $config);
$master ? ok('the master switch is on') : no('the master switch is OFF — nothing sends');
$quote  ? ok('the quotation event is on')
        : no('customer_email_quotation is OFF — run: php tools/set_customer_emails.php --master on --on quotation');

$es = is_file($dataDir . '/email_settings.json')
    ? (json_decode((string)@file_get_contents($dataDir . '/email_settings.json'), true) ?: []) : [];
!empty($es['quote_email_via_plugin'])
    ? ok('quotes created in the DishNet app are sent by the plugin')
    : wr('quote_email_via_plugin is off — app-created quotes go to uCRM instead');

$mail = (new MailService($dataDir))->getConfig();
$mail ? ok('the plugin mailer is configured (' . (string)($mail['host'] ?? '?') . ')')
      : no('the plugin mailer is NOT configured — Settings → System → Email');

line();
echo "2) Is uCRM actually telling us when a quote is created?\n";
// getWebhooks() asks an endpoint this uCRM answers 404 to, so "no webhooks"
// from the API means nothing at all here — it said that while an endpoint was
// registered and delivering. The webhook log is the evidence that counts: if
// events arrived, the endpoint exists and works, whatever the API claims.
$crm = CrmApiClient::fromUcrm($root, $config);
$logFile = $dataDir . '/webhook_log.json';
$log = is_file($logFile) ? (json_decode((string)@file_get_contents($logFile), true) ?: []) : [];

$lastAt = ''; $events = 0;
foreach ($log as $e) {
    $at = (string)($e['received_at'] ?? $e['at'] ?? $e['time'] ?? '');
    if ($at > $lastAt) $lastAt = $at;
    $events++;
}
if ($events > 0) {
    ok("uCRM has delivered {$events} webhook event(s)"
       . ($lastAt !== '' ? '; the most recent at ' . substr($lastAt, 0, 19) : ''));
} else {
    no('no webhook event has EVER reached the plugin');
    echo "        Register the endpoint: php tools/webhook_setup.php\n";
}

// Report the API's answer as information, not as a verdict.
$hooks = $crm ? $crm->get('webhooks/endpoints') : null;
if ($hooks === null) {
    echo "        (the API cannot list endpoints on this uCRM — it answers 404 —\n";
    echo "         so registration can only be seen in uCRM → System → Webhooks)\n";
}

line();
echo "3) What happened the last few times a quote was created\n";
$logFile = $dataDir . '/webhook_log.json';
if (!is_file($logFile)) {
    wr('no webhook log yet — the plugin has not been called');
} else {
    $log = json_decode((string)@file_get_contents($logFile), true) ?: [];
    $rows = [];
    foreach (array_reverse($log) as $e) {
        $msg = (string)($e['message'] ?? $e['msg'] ?? '');
        $ev  = (string)($e['event'] ?? '');
        if (stripos($ev, 'quote') === false && stripos($msg, 'quot') === false) continue;
        $rows[] = sprintf('    %-20s %-14s %s',
            substr((string)($e['received_at'] ?? $e['at'] ?? ''), 0, 19), $ev, substr($msg, 0, 90));
        if (count($rows) >= 12) break;
    }
    if (!$rows) {
        wr('the webhook log holds nothing about quotes — quote.add has not arrived');
        echo "        Create a NEW quote (a resend from uCRM's Email Log is not a\n";
        echo "        creation and does not fire this webhook).\n";
    } else {
        echo implode("\n", $rows) . "\n";
        $sent = false;
        foreach ($rows as $r) if (stripos($r, 'Quotation email sent') !== false) $sent = true;
        $sent ? ok('the plugin has sent at least one quotation email')
              : wr('no "Quotation email sent" line yet — read the lines above for why');
    }
}

line();
echo "4) The other mailer, for contrast\n";
echo "  uCRM has its own mailer and its own Email Log. It is failing on\n";
echo "  ssl://mail.dishnetuganda.com:465 because the container cannot reach the\n";
echo "  mail server by its public name — the same wall the Sent-folder archive\n";
echo "  hit, fixed there by talking to the stalwart container directly.\n\n";
echo "  Leaving it broken is a defensible choice: uCRM auto-sends its own\n";
echo "  \"New quote\" email, so a working uCRM mailer means the customer gets\n";
echo "  TWO quotations with different branding. Repair it only if you find\n";
echo "  something uCRM must send that the plugin does not cover.\n";

line();
printf("RESULT: %d pass · %d warn · %d fail\n", $pass, $warn, $failn);
exit($failn ? 1 : 0);
