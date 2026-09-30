<?php
declare(strict_types=1);
/**
 * notify_text_probe.php — TEST ONLY (5.18.54, docs/46 rows 21 and 39). What the plugin tree under test says, inside the
 * data directory the caller names (DN_DATA_DIR, DN_VAULT_FILE): the dunning ladder's WhatsApp for every stage, and the
 * app pushes for an invoice and a payment, captured before they would reach FCM. Prints JSON. Nothing leaves the machine.
 *
 *   php notify_text_probe.php <pluginRoot>
 */
[$_, $root] = array_pad($argv, 2, '');
$root = rtrim($root, '/');
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/currency.php';
$GLOBALS['dataDir'] = getDataDir($root);
$store  = SqliteStore::create($GLOBALS['dataDir']);
$config = $store->load('kyc_config.json') ?? [];

// The push sender, captured instead of called: FcmPush defines its own only where none exists yet.
$GLOBALS['__pushes'] = [];
function fcm_send_push($pdo, array $config, int $clientId, string $event, string $title, string $body, array $data = []): array
{
    $GLOBALS['__pushes'][$event] = $body;
    return ['sent' => 0, 'failed' => 0, 'errors' => []];
}
require_once $root . '/lib/FcmPush.php';
require_once $root . '/lib/OverdueDunningHelpers.php';

$ladder = [];
foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9] as $stage) {
    $ladder[$stage] = _buildWhatsApp($stage, 'Test', 'INV-9001', 'UGX 1,600,000', '2026-09-01', 30, 'https://example.test/pay');
}
fcm_push_invoice_created($store->getPdo(), $config, 15, 'INV-9001', 1600000.0);
fcm_push_payment_received($store->getPdo(), $config, 15, 250000.0);
echo json_encode(['ladder' => $ladder, 'push' => $GLOBALS['__pushes']], JSON_UNESCAPED_UNICODE);
