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

$dataDir = getenv('DN_DATA_DIR') ?: getDataDir($root);

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
}
if (in_array('--all-off', $argv, true)) {
    $changes['customer_emails_enabled'] = '';
    foreach ($valid as $k) $changes['customer_email_' . $k] = '';
}

if (!$changes) {
    fwrite(STDERR, "Nothing to do. Use --show, --on <event>, --off <event>, --master on|off, --all-off\n");
    exit(1);
}

echo "\nBefore:\n"; show($config);
list($ok, $err) = PluginConfig::saveOverrides($dataDir, $changes);
if (!$ok) { fwrite(STDERR, "FAILED: {$err}\n"); exit(1); }

// Read back from disk: this is what the webhook will actually see.
$fresh = PluginConfig::load($root, $dataDir);
echo "After (read back from {$dataDir}/kyc_config.json):\n"; show($fresh);

$live = array_filter(CustomerEmailDispatcher::states($fresh), function ($s) { return $s['on']; });
if ($live && CustomerEmailDispatcher::masterEnabled($fresh)) {
    echo "  ⚠  " . count($live) . " event(s) will now email real customers on the next webhook.\n";
    echo "     Inspect the wording first: Admin → ✉️ Email Preview.\n";
    echo "     Stop everything with: php tools/set_customer_emails.php --all-off\n\n";
} else {
    echo "  No customer will receive a lifecycle email in this state.\n\n";
}
exit(0);
