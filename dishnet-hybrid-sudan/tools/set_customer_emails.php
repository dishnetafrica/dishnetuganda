<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * set_customer_emails.php — decide which lifecycle emails may reach customers.
 *
 *   php tools/set_customer_emails.php --show
 *   php tools/set_customer_emails.php --on payment_received
 *   php tools/set_customer_emails.php --on payment_received --on welcome
 *   php tools/set_customer_emails.php --off invoice
 *   php tools/set_customer_emails.php --master on|off
 *   php tools/set_customer_emails.php --cc on|off            CC the client's other contacts
 *   php tools/set_customer_emails.php --reminder-email on|off  payment reminders by email (before-due)
 *   php tools/set_customer_emails.php --all-off          panic switch
 *
 * Two switches guard every send: the master and the event's own. Both must be
 * on. Everything starts off, so nothing is sent until somebody here decides it
 * should be — turn one event on, watch it in production, then turn on the next.
 *
 * --all-off is the thing to reach for if wrong copy goes out: one command
 * stops every lifecycle email without touching anything else.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/CustomerEmailDispatcher.php';

$dataDir = cliDataDir($root);

function show(array $config): void
{
    $master = CustomerEmailDispatcher::masterEnabled($config);
    echo "\n  MASTER SWITCH   " . ($master ? "ON" : "OFF  — nothing is sent, whatever is set below") . "\n\n";
    printf("  %-20s %-5s %s\n", 'EVENT', 'STATE', 'FIRES WHEN');
    printf("  %-20s %-5s %s\n", str_repeat('─', 20), '─────', str_repeat('─', 40));
    foreach (CustomerEmailDispatcher::states($config) as $key => $s) {
        printf("  %-20s %-5s %s\n", $key, $s['on'] ? 'ON' : 'off', $s['trigger']);
    }
    echo "\n  quotation has TWO paths and needs the right switch for each:\n";
    echo "    quote_email_via_plugin    quotes created in the DishNet app\n";
    echo "    customer_email_quotation  quotes typed into uCRM's own screen\n";
    echo "                              (listed above — turn it on there)\n";
    echo "\n  Not switched here:\n";
    echo "    login_code   sent by the portal the moment a customer asks for a\n";
    echo "                 code — gating it would lock people out\n\n";

    $cc  = CustomerEmailDispatcher::ccEnabled($config);
    $rem = !empty(CustomerEmailDispatcher::effectiveConfig($config)['reminder_email_enabled']);
    echo "  DELIVERY OPTIONS\n";
    printf("    %-32s %s\n", 'CC the client\'s other contacts', $cc ? 'ON' : 'off');
    echo  "                                     first contact = To, every other email in CC\n";
    printf("    %-32s %s\n", 'Payment reminders by email', $rem ? 'ON' : 'off');
    echo  "                                     before-due only, alongside WhatsApp; needs MASTER on\n\n";
}

$config = PluginConfig::load($root, $dataDir);
$valid  = array_keys(CustomerEmailDispatcher::states($config));

if (in_array('--show', $argv, true) || count($argv) === 1) {
    echo "\nLifecycle emails on this install:\n";
    show($config);
    echo "Turn one on with:  php tools/set_customer_emails.php --master on --on <event>\n";
    exit(0);
}

$changes = [];
foreach ($argv as $i => $a) {
    if (($a === '--on' || $a === '--off') && isset($argv[$i + 1])) {
        $key = trim($argv[$i + 1]);
        if (!in_array($key, $valid, true)) {
            fwrite(STDERR, "Unknown event: {$key}\nKnown: " . implode(', ', $valid) . "\n");
            exit(1);
        }
        $changes['customer_email_' . $key] = $a === '--on' ? '1' : '';
    }
    if ($a === '--master' && isset($argv[$i + 1])) {
        $v = strtolower(trim($argv[$i + 1]));
        if (!in_array($v, ['on', 'off'], true)) { fwrite(STDERR, "--master takes on or off\n"); exit(1); }
        $changes['customer_emails_enabled'] = $v === 'on' ? '1' : '';
    }
    if ($a === '--cc' && isset($argv[$i + 1])) {
        $v = strtolower(trim($argv[$i + 1]));
        if (!in_array($v, ['on', 'off'], true)) { fwrite(STDERR, "--cc takes on or off\n"); exit(1); }
        $changes['email_cc_contacts'] = $v === 'on' ? '1' : '';
    }
    if ($a === '--reminder-email' && isset($argv[$i + 1])) {
        $v = strtolower(trim($argv[$i + 1]));
        if (!in_array($v, ['on', 'off'], true)) { fwrite(STDERR, "--reminder-email takes on or off\n"); exit(1); }
        $changes['reminder_email_enabled'] = $v === 'on' ? '1' : '';
    }
}
if (in_array('--all-off', $argv, true)) {
    $changes['customer_emails_enabled'] = '';
    foreach ($valid as $k) $changes['customer_email_' . $k] = '';
    // The reminder e-mail is a lifecycle send too — the panic switch stops it.
    // (CC is a recipient shape, not a send, and master-off already stops all
    // sends, so --all-off leaves it untouched.)
    $changes['reminder_email_enabled'] = '';
}

if (!$changes) {
    fwrite(STDERR, "Nothing to do. Use --show, --on <event>, --off <event>, --master on|off, --cc on|off, --reminder-email on|off, --all-off\n");
    exit(1);
}

echo "\nBefore:\n"; show($config);
list($ok, $err) = PluginConfig::saveOverrides($dataDir, $changes);
if (!$ok) { fwrite(STDERR, "FAILED: {$err}\n"); exit(1); }

// Read back from disk: this is what the webhook will actually see. Then
// CHECK it against what was asked. On 15 Sep --all-off cleared every switch
// and this display still showed them ON, because the dispatcher's disk
// snapshot was cached from the "Before" display; the operator was left
// believing the stop had failed. A tool that changes what customers receive
// must not describe a state it has not verified.
$fresh  = PluginConfig::load($root, $dataDir);
$states = CustomerEmailDispatcher::states($fresh);
$wrong  = [];
foreach ($changes as $k => $v) {
    $wantOn = $v !== '';
    if ($k === 'email_cc_contacts' || $k === 'reminder_email_enabled') {
        $isOn = !empty(CustomerEmailDispatcher::effectiveConfig($fresh)[$k]);
        if ($isOn !== $wantOn) $wrong[] = "{$k} reads " . ($isOn ? 'ON' : 'off') . ", wanted " . ($wantOn ? 'ON' : 'off');
        continue;
    }
    if ($k === 'customer_emails_enabled') {
        $isOn = CustomerEmailDispatcher::masterEnabled($fresh);
        if ($isOn !== $wantOn) $wrong[] = "master reads " . ($isOn ? 'ON' : 'off') . ", wanted " . ($wantOn ? 'ON' : 'off');
        continue;
    }
    $ev   = substr($k, strlen('customer_email_'));
    // With the master off every event reads off, which is the state asked for.
    $isOn = !empty($states[$ev]['on']);
    if ($wantOn && !$isOn && CustomerEmailDispatcher::masterEnabled($fresh)) $wrong[] = "{$ev} reads off, wanted ON";
    if (!$wantOn && $isOn) $wrong[] = "{$ev} reads ON, wanted off";
}
echo "After (read back from {$dataDir}/kyc_config.json):\n"; show($fresh);
if ($wrong) {
    fwrite(STDERR, "FAILED — the file was written but the switches do not read back as asked:\n");
    foreach ($wrong as $w) fwrite(STDERR, "  {$w}\n");
    fwrite(STDERR, "Something else supplies the old value. Trace it: php tools/config_trace.php " . implode(' ', array_keys($changes)) . "\n");
    exit(1);
}

$live = array_filter(CustomerEmailDispatcher::states($fresh), function ($s) { return $s['on']; });
if ($live && CustomerEmailDispatcher::masterEnabled($fresh)) {
    echo "  ⚠  " . count($live) . " event(s) will now email real customers on the next webhook.\n";
    echo "     Inspect the wording first: Admin → ✉️ Email Preview.\n";
    echo "     Stop everything with: php tools/set_customer_emails.php --all-off\n\n";
} else {
    echo "  No customer will receive a lifecycle email in this state.\n\n";
}
exit(0);
