<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * efris_setup_attributes.php — create the uCRM CLIENT custom attributes that
 * EFRIS needs (TIN, BRN, NIN, Taxpayer Type), once, idempotently.
 *
 * uCRM custom attributes are the agreed home for per-customer tax identity:
 * they appear natively on the client form in uCRM (no second customer
 * database), and the plugin reads them from client.attributes — the same
 * mechanism the KYC module already uses.
 *
 * Run inside the ucrm container, from the plugin directory:
 *   php tools/efris_setup_attributes.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/error_handler.php';
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/CrmApiClient.php';

$dataDir = getDataDir($root);
$config  = PluginConfig::load($root, $dataDir);
$crm     = CrmApiClient::fromUcrm($root, $config);
if (!$crm->isConfigured()) exit("uCRM API not configured\n");

$wanted = ['EFRIS TIN', 'EFRIS BRN', 'EFRIS NIN', 'EFRIS Taxpayer Type'];

$existing = $crm->get('custom-attributes') ?? [];
$have = [];
foreach ((array)$existing as $a) {
    if (!is_array($a)) continue;
    $have[strtolower(trim((string)($a['name'] ?? '')))] = (string)($a['key'] ?? '');
}

foreach ($wanted as $name) {
    $lk = strtolower($name);
    if (isset($have[$lk])) {
        echo "  exists  {$name}  (key: {$have[$lk]})\n";
        continue;
    }
    $r = $crm->post('custom-attributes', ['name' => $name, 'attributeType' => 'client']);
    if (is_array($r) && !empty($r['key'])) {
        echo "  created {$name}  (key: {$r['key']})\n";
    } else {
        $e = $crm->getLastError();
        echo "  FAILED  {$name}: " . json_encode($e ?: $r) . "\n";
    }
}
echo "\nEdit the values per client in uCRM (client form → custom attributes).\n";
echo "The EFRIS mapper matches keys/names ending in tin/brn/nin or containing 'taxpayer type'.\n";
