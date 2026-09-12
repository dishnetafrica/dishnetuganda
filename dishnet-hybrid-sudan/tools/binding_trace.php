<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * binding_trace.php — walk the ownership chain and come back to where you began.
 *
 *   php tools/binding_trace.php --client 123
 *   php tools/binding_trace.php --kit KIT409033426KFR
 *   php tools/binding_trace.php --router abc123
 *   php tools/binding_trace.php --service 500
 *
 * Starts from whichever end you have and walks to the other, then walks back.
 * The test that matters is the last line: the client id you finish on must be
 * the one you started from. If it is not, the binding is broken and this says
 * so rather than printing a plausible-looking chain.
 *
 * Read-only.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
$GLOBALS['_PLUGIN_ROOT'] = $root;
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/SiblingPlugin.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/EquipmentAssignment.php';
require_once $root . '/lib/CrmApiClient.php';

$args = array_slice($argv, 1);
$val = function (string $f) use ($args): string {
    $i = array_search($f, $args, true);
    return ($i !== false && isset($args[$i + 1])) ? (string)$args[$i + 1] : '';
};
$KNOWN = ['--client', '--kit', '--router', '--terminal', '--service'];
foreach ($args as $a) {
    if (strpos($a, '--') !== 0 || in_array($a, $KNOWN, true)) continue;
    fwrite(STDERR, "\n  Unknown option: {$a}\n  Known: " . implode(' ', $KNOWN) . "\n\n");
    exit(2);
}

$dataDir = cliDataDir($root);
$store   = SqliteStore::create($dataDir);
$pdo     = $store->getPdo();
$config  = PluginConfig::load($root, $dataDir);
$ea      = new EquipmentAssignment($pdo);

$clientArg  = trim($val('--client'));
$serviceArg = trim($val('--service'));
$kit        = trim($val('--kit'));
$router     = trim($val('--router'));
$terminal   = trim($val('--terminal'));

if ($clientArg === '' && $serviceArg === '' && $kit === '' && $router === '' && $terminal === '') {
    echo "\n  Give it somewhere to start:\n\n";
    echo "    --client 123        --service 500\n";
    echo "    --kit KIT…          --router …         --terminal …\n\n";
    exit(1);
}

// ── Find the assignment, from whichever end we were given ───────────────────
$startedFrom = '';
$assignment  = null;

if ($clientArg !== '') {
    if (!ctype_digit($clientArg)) { fwrite(STDERR, "\n  --client needs a number.\n\n"); exit(2); }
    $startedFrom = 'uCRM client #' . (int)$clientArg;
    $all = $ea->forClient((int)$clientArg);
    $assignment = $all[0] ?? null;
    $extra = count($all) > 1 ? (count($all) - 1) : 0;
} elseif ($serviceArg !== '') {
    if (!ctype_digit($serviceArg)) { fwrite(STDERR, "\n  --service needs a number.\n\n"); exit(2); }
    $startedFrom = 'uCRM service #' . (int)$serviceArg;
    $assignment = $ea->forService((int)$serviceArg);
    $extra = 0;
} else {
    $r = $ea->resolve(['kit_serial' => $kit, 'router_id' => $router, 'terminal_id' => $terminal]);
    $startedFrom = $kit !== '' ? ('kit ' . strtoupper($kit))
                 : ($router !== '' ? ('router ' . $router) : ('terminal ' . $terminal));
    $assignment = !empty($r['assigned']) ? $ea->get((int)$r['assignment_id']) : null;
    $extra = 0;
    if (empty($r['assigned'])) {
        echo "\n  START  {$startedFrom}\n";
        echo "  " . str_repeat('─', 68) . "\n\n";
        echo "  ✗ Kit is not assigned to a CRM customer.\n";
        echo "    " . (string)($r['reason'] ?? '') . "\n\n";
        echo "  Nothing is guessed from names. Assign it and this chain resolves:\n";
        echo "    the Stock screen, or php tools/binding_doctor.php\n\n";
        exit(1);
    }
}

echo "\n  START  {$startedFrom}\n";
echo "  " . str_repeat('─', 68) . "\n\n";

if (!$assignment) {
    echo "  ✗ No Starlink equipment assignment exists for this customer.\n\n";
    echo "  That is the whole answer — no name matching, no service-title regex,\n";
    echo "  no best guess. Assign the equipment and this chain resolves.\n\n";
    exit(1);
}

$clientId  = (int)$assignment['crm_client_id'];
$serviceId = $assignment['crm_service_id'] === null ? 0 : (int)$assignment['crm_service_id'];

// ── The chain ───────────────────────────────────────────────────────────────
$name = '';
try {
    $crm = CrmApiClient::fromUcrm($root, $config);
    if ($crm) {
        $c = $crm->get('clients/' . $clientId);
        if (is_array($c)) {
            $name = trim(($c['companyName'] ?? '') !== ''
                ? (string)$c['companyName']
                : trim((string)($c['firstName'] ?? '') . ' ' . (string)($c['lastName'] ?? '')));
        }
    }
} catch (\Throwable $e) { /* offline — the ids are the point, not the name */ }

$unit = null;
try {
    $st = $pdo->prepare("SELECT u.*, c.title AS category FROM stock_units u
                         LEFT JOIN stock_categories c ON c.id = u.category_id WHERE u.id = ?");
    $st->execute([(int)$assignment['unit_id']]);
    $unit = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
} catch (\Throwable $e) { /* */ }

$row = function (string $label, string $value, string $note = ''): void {
    printf("  %-14s %s%s\n", $label, $value, $note !== '' ? ('   ' . $note) : '');
};
$arrow = function (): void { echo "        ↓\n"; };

$row('CRM CLIENT', '#' . $clientId . ($name !== '' ? '  ' . $name : ''));
$arrow();
$row('SERVICE', $serviceId > 0 ? ('#' . $serviceId) : '(none recorded)',
     $serviceId > 0 ? '' : '← per-service blocking cannot work without this');
$arrow();
$row('ASSIGNMENT', '#' . (int)$assignment['id'],
     'since ' . substr((string)$assignment['assigned_at'], 0, 10));
$arrow();
$row('STOCK UNIT', '#' . (int)$assignment['unit_id'] . '  '
    . ((string)$assignment['kit_serial'] !== '' ? (string)$assignment['kit_serial'] : '(no serial)'),
    $unit ? ((string)$unit['category'] . ', ' . (string)$unit['status']) : '(unit row missing)');
$arrow();
echo "  STARLINK IDENTIFIERS\n";
foreach ([
    'account'      => 'starlink_account',
    'service line' => 'starlink_service_line',
    'terminal_id'  => 'terminal_id',
    'router_id'    => 'router_id',
] as $label => $col) {
    $v = trim((string)$assignment[$col]);
    printf("      %-13s %s\n", $label, $v !== '' ? $v : '—');
}
$arrow();

// ── The data plugin's own view of that router ───────────────────────────────
$map = SiblingPlugin::readJsonOrEmpty('dishnet-data-report', 'wifi_router_map.json');
$rid = EquipmentAssignment::routerId((string)$assignment['router_id']);
$seen = null;
if ($rid !== '' && $map !== []) {
    foreach ($map as $k => $info) {
        if (!is_array($info)) continue;
        if (EquipmentAssignment::routerId((string)($info['router_id'] ?? $k)) === $rid) { $seen = $info; break; }
    }
}
if ($seen) {
    $row('DATA PLUGIN', 'Router-' . $rid,
        'kit ' . (string)($seen['kit_serial'] ?? '?') . ' on ' . (string)($seen['account_number'] ?? '?'));
} elseif ($rid !== '') {
    $row('DATA PLUGIN', 'Router-' . $rid, '← not in wifi_router_map.json yet');
} else {
    $row('DATA PLUGIN', '(no router on the assignment)',
         '← blocking has nothing to act on');
}
$arrow();

// ── And back ────────────────────────────────────────────────────────────────
// The only test that counts: hand the Starlink identifiers back and see who
// comes out. Same customer, or the binding is broken.
$back = $ea->resolve([
    'kit_serial'            => (string)$assignment['kit_serial'],
    'terminal_id'           => (string)$assignment['terminal_id'],
    'router_id'             => (string)$assignment['router_id'],
    'starlink_service_line' => (string)$assignment['starlink_service_line'],
]);

if (empty($back['assigned'])) {
    $row('CRM CLIENT', '✗ did not resolve back', (string)($back['reason'] ?? ''));
    echo "\n  ✗ THE CHAIN DOES NOT CLOSE.\n\n";
    exit(1);
}
$row('CRM CLIENT', '#' . (int)$back['crm_client_id'] . ($name !== '' ? '  ' . $name : ''),
     'matched on ' . (string)$back['matched_on']);

echo "\n  " . str_repeat('─', 68) . "\n";
if ((int)$back['crm_client_id'] === $clientId
    && (int)$back['assignment_id'] === (int)$assignment['id']) {
    echo "  ✓ Round trip closed on the same customer, by exact id.\n";
    echo "    CRM → kit and kit → CRM both resolve deterministically.\n";
} else {
    echo "  ✗ Round trip came back on client #" . (int)$back['crm_client_id']
       . ", started on #{$clientId}.\n";
    echo "    This binding is NOT safe.\n";
    echo "\n";
    exit(1);
}
if (!empty($extra)) {
    echo "    (this customer holds {$extra} more kit(s); trace each with --kit)\n";
}
echo "\n";
exit(0);
