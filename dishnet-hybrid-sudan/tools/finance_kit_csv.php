<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * finance_kit_csv.php — hand dishnet-starlink-finance our bindings, in its own
 * import format.
 *
 *   php tools/finance_kit_csv.php                 print the CSV
 *   php tools/finance_kit_csv.php --out <path>    write it to a file
 *
 * ── WHY A CSV AND NOT A WRITE ───────────────────────────────────────────
 *
 * dishnet-data-report's customer page lists a customer's kits by reading
 * dishnet-starlink-finance/data/sl_kits.json and matching crm_client_id. On a
 * box where Finance holds no kits, that page says "No subscriptions found"
 * however correct our own binding is.
 *
 * The temptation is to write that file ourselves. We do not. Both sibling
 * plugins state the same contract — a data file is OWNED by one plugin,
 * others may read it and must not write it — and a file written behind a
 * plugin's back is a file it will overwrite, disagree with, or trip over.
 *
 * Finance already has a supported way in: its Inventory screen imports a kit
 * CSV, keyed on kit_number, updating what it holds and inserting what it does
 * not. So this produces exactly that CSV from equipment_assignments, and a
 * person uploads it. Finance writes its own file, from data we generated.
 *
 * ── WHAT THIS IS NOT ────────────────────────────────────────────────────
 *
 * Not a second source of truth. equipment_assignments stays authoritative;
 * this is a projection of it in somebody else's format. Re-running it after
 * the binding changes and re-importing is the whole update mechanism — there
 * is nothing to keep in sync by hand.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
$GLOBALS['_PLUGIN_ROOT'] = $root;
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/EquipmentAssignment.php';
require_once $root . '/lib/CrmApiClient.php';

$args = array_slice($argv, 1);
$out  = '';
$i    = array_search('--out', $args, true);
if ($i !== false) { $out = (string)($args[$i + 1] ?? ''); }
foreach ($args as $n => $a) {
    if (strpos($a, '--') !== 0) continue;
    if ($a !== '--out') { fwrite(STDERR, "\n  Unknown option: {$a}\n  Known: --out <path>\n\n"); exit(2); }
}

$dataDir = cliDataDir($root);
$store   = SqliteStore::create($dataDir);
$config  = PluginConfig::load($root, $dataDir);
$ea      = new EquipmentAssignment($store->getPdo());

$crm = null;
try { $crm = CrmApiClient::fromUcrm($root, $config); } catch (\Throwable $e) {}
$reachable = $crm && $crm->isConfigured();

$live = $ea->liveAssignments();
if ($live === []) {
    fwrite(STDERR, "\n  No live assignments, so there is nothing to hand Finance.\n"
                 . "  Bind a kit first — tools/kit_intake.php, or tools/assign_kit.php.\n\n");
    exit(1);
}

/** uCRM client names and their service plans, once each. */
$clientName = [];
$servicePlan = [];
$look = function (int $clientId, int $serviceId) use ($crm, $reachable, &$clientName, &$servicePlan): void {
    if (!$reachable) return;
    if (!array_key_exists($clientId, $clientName)) {
        $c = $crm->get('clients/' . $clientId);
        $n = '';
        if (is_array($c)) {
            $n = trim((string)($c['companyName'] ?? ''));
            if ($n === '') $n = trim((string)($c['firstName'] ?? '') . ' ' . (string)($c['lastName'] ?? ''));
        }
        $clientName[$clientId] = $n;
    }
    if ($serviceId > 0 && !array_key_exists($serviceId, $servicePlan)) {
        $s = $crm->get('clients/services/' . $serviceId);
        $servicePlan[$serviceId] = is_array($s)
            ? trim((string)($s['servicePlanName'] ?? $s['servicePlan']['name'] ?? $s['name'] ?? '')) : '';
    }
};

// Finance's own column names, from its import_csv column map. Anything it does
// not recognise is ignored by it, so the set is deliberately the ones it maps.
$cols = ['kit_number', 'serial_number', 'status', 'location', 'customer', 'plan',
         'starlink_account_number', 'starlink_account_status',
         'assigned_client_id', 'assigned_name', 'crm_client_id', 'contact_number'];

$rows = [];
foreach ($live as $a) {
    $clientId  = (int)$a['crm_client_id'];
    $serviceId = (int)($a['crm_service_id'] ?? 0);
    $kit       = strtoupper(trim((string)$a['kit_serial']));
    if ($kit === '') continue;                       // a binding with no serial is not a kit row
    $look($clientId, $serviceId);

    $rows[] = [
        'kit_number'              => $kit,
        'serial_number'           => $kit,
        'status'                  => 'Deployed',
        'location'                => (string)($a['note'] ?? ''),
        'customer'                => $clientName[$clientId] ?? '',
        'plan'                    => $servicePlan[$serviceId] ?? '',
        'starlink_account_number' => (string)($a['starlink_account'] ?? ''),
        'starlink_account_status' => 'Active',
        // BOTH, because data-report reads crm_client_id ?? assigned_client_id
        // and a reader that finds neither shows the customer nothing.
        'assigned_client_id'      => (string)$clientId,
        'assigned_name'           => $clientName[$clientId] ?? '',
        'crm_client_id'           => (string)$clientId,
        'contact_number'          => '',
    ];
}

$fh = fopen('php://temp', 'r+');
fputcsv($fh, $cols);
foreach ($rows as $r) fputcsv($fh, array_map(static fn($c) => $r[$c] ?? '', $cols));
rewind($fh);
$csv = (string)stream_get_contents($fh);
fclose($fh);

if ($out !== '') {
    if (@file_put_contents($out, $csv) === false) {
        fwrite(STDERR, "\n  Could not write {$out}\n\n"); exit(1);
    }
    fwrite(STDERR, "\n  " . count($rows) . " kit(s) written to {$out}\n");
    if (!$reachable) {
        fwrite(STDERR, "  uCRM was unreachable, so customer and plan names are blank —\n"
                     . "  Finance matches on kit_number and crm_client_id, which are correct.\n");
    }
    fwrite(STDERR, "\n  Upload it in Finance → Inventory → the CSV box beside \"+ Add New KIT\".\n"
                 . "  Finance keys on kit_number, so re-importing updates rather than duplicates.\n\n");
    return;
}

echo $csv;
