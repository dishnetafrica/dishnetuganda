<?php
declare(strict_types=1);
/**
 * notify_alert_probe.php — TEST ONLY. One administrator alert, raised by the plugin tree under test (a weakened copy
 * included) inside the data directory the caller names (DN_DATA_DIR, DN_VAULT_FILE), so a test can read what the alert
 * left behind: the Message Log, the plugin log, the fake WhatsApp's record.
 *
 *   php notify_alert_probe.php <pluginRoot> admin <event>   NotificationService::sendAdmin(), as every cron raises one
 *   php notify_alert_probe.php <pluginRoot> alert <key>     AlertService::notify(), with no cooldown
 *
 * PROBE_CFG, a JSON object, is laid over the stored settings for this run only (an alert number set or cleared), so
 * the sandbox's own settings are never rewritten. Prints {"result": …} as JSON. Every number and text is fictitious;
 * nothing leaves the machine.
 */
[$_, $root, $what, $arg] = array_pad($argv, 4, '');
$root = rtrim($root, '/');
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/NotificationService.php';
require_once $root . '/lib/AlertService.php';

$store  = SqliteStore::create(getDataDir($root));
$config = $store->load('kyc_config.json') ?? [];
$over   = json_decode((string)getenv('PROBE_CFG'), true);
if (is_array($over)) $config = array_merge($config, $over);

$result = null;
if ($what === 'admin') {
    (new NotificationService($store, $config))->sendAdmin('Probe: an administrator alert (test only).', $arg);
    $result = 'raised';
} elseif ($what === 'alert') {
    $result = (new AlertService($store, $config))->notify($arg, 'Probe: an operations alert (test only).', 0);
} else {
    fwrite(STDERR, "usage: notify_alert_probe.php <pluginRoot> admin|alert <name>\n");
    exit(2);
}
echo json_encode(['result' => $result]);
