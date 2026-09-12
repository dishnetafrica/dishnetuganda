<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * fleet_doctor.php — is the Starlink Fleet screen ready, and if not, why?
 *
 *   php tools/fleet_doctor.php
 *
 * Read-only. This is the South Sudan "data report" equivalent: which customer
 * holds which kit and how much data it has used. It runs the SAME
 * StarlinkFleet::build() the screen runs, so what it prints is what an
 * administrator sees — not a second opinion that can drift from the page.
 *
 * The screen needs two things that arrive from different places, and the
 * common failure is having one without the other:
 *
 *   1. Live equipment assignments, from OUR database. Without these the
 *      screen has no customers to list, however much telemetry exists.
 *   2. sl_usage.json, written by the SIBLING dishnet-data-report plugin.
 *      Without it every row is present but silent.
 *
 * A check that cannot be established says UNKNOWN. It never reports a pass it
 * did not prove.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/EquipmentAssignment.php';
require_once $root . '/lib/KitUsage.php';
require_once $root . '/lib/StarlinkFleet.php';

$dataDir = getDataDir($root);
$store   = SqliteStore::create($dataDir);
$line    = str_repeat('─', 72);

echo "\n  STARLINK FLEET / DATA REPORT (read-only)\n  {$line}\n";
printf("  %-24s %s\n\n", 'data directory', $dataDir);

// ── 1. Do we have the table at all? ────────────────────────────────────
try {
    $pdo = $store->getPdo();
    $has = (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='equipment_assignments'")->fetch();
} catch (\Throwable $e) { $has = false; }
if (!$has) {
    echo "  ✗ equipment_assignments does not exist — the migrations have not run.\n";
    echo "      php tools/schema_doctor.php --repair\n\n";
    exit(1);
}

$ea = new EquipmentAssignment($pdo);
$live = $ea->liveAssignments();

echo "  1. WHO HOLDS A KIT (our database)\n  {$line}\n";
printf("    %-28s %d\n", 'live assignments', count($live));
if ($live === []) {
    echo "\n    The screen will be EMPTY — not broken, just nothing assigned yet.\n";
    echo "    A kit becomes visible here when it is installed to a uCRM client:\n";
    echo "    Stock → issue the unit to a customer, which writes the assignment.\n\n";
} else {
    $withSerial = $withTerminal = $withService = 0;
    foreach ($live as $a) {
        if (trim((string)$a['kit_serial'])  !== '') $withSerial++;
        if (trim((string)$a['terminal_id']) !== '') $withTerminal++;
        if ($a['crm_service_id'] !== null)          $withService++;
    }
    printf("    %-28s %d of %d\n", 'with a kit serial',  $withSerial,  count($live));
    printf("    %-28s %d of %d\n", 'with a terminal id', $withTerminal, count($live));
    printf("    %-28s %d of %d\n", 'bound to a service', $withService, count($live));
    if ($withSerial < count($live)) {
        echo "\n    ⚠ Usage is joined on the KIT SERIAL. An assignment without one can\n";
        echo "      never show data, however healthy the telemetry pipeline is.\n";
    }
    echo "\n";
}

// ── 2. Is the sibling plugin feeding us? ───────────────────────────────
$usage = new KitUsage($ea);
$rows  = $usage->rows();
echo "  2. TELEMETRY (sibling dishnet-data-report plugin)\n  {$line}\n";
if ($rows === null) {
    echo "    ✗ sl_usage.json NOT FOUND — the data-report plugin has never\n";
    echo "      written usage on this server. Every row will read 'no telemetry'.\n";
    echo "      This is the piece South Sudan has and Uganda does not.\n\n";
} else {
    $kits = [];
    foreach ($rows as $r) { $k = trim((string)($r['kit_number'] ?? '')); if ($k !== '') $kits[$k] = true; }
    printf("    %-28s %d\n", 'usage rows', count($rows));
    printf("    %-28s %d\n\n", 'distinct kits reported', count($kits));
}

// ── 3. What the screen will actually render ────────────────────────────
$fleet = new StarlinkFleet($ea, $usage, $store, null);
$built = $fleet->build();
$s     = $built['summary'] ?? [];
echo "  3. WHAT THE SCREEN SHOWS\n  {$line}\n";
printf("    %-28s %d\n", 'rows', count($built['rows'] ?? []));
foreach (['usage_known' => 'kits reporting data',
          'usage_silent' => 'kits silent',
          'over_cap' => 'over their allowance'] as $k => $label) {
    if (array_key_exists($k, $s)) printf("    %-28s %d\n", $label, (int)$s[$k]);
}
$t = $built['telemetry'] ?? [];
printf("    %-28s %s\n", 'telemetry available', !empty($t['available']) ? 'yes' : 'no');
if (!empty($t['reason'])) echo "    reason: " . (string)$t['reason'] . "\n";

echo "\n  {$line}\n";
$ready = $live !== [] && $rows !== null;
if ($ready) {
    echo "  ✓ The Fleet screen has customers AND telemetry — it is ready.\n\n";
} elseif ($live === [] && $rows === null) {
    echo "  The screen is built and reachable (Admin → Starlink Fleet) but has\n";
    echo "  neither assignments nor telemetry yet, so it will render empty.\n\n";
} elseif ($live === []) {
    echo "  Telemetry is arriving, but no kit is assigned to a customer yet, so\n";
    echo "  there is nothing to attribute it to.\n\n";
} else {
    echo "  Customers hold kits, but no telemetry has been collected here — the\n";
    echo "  rows will list every customer and say so, rather than showing 0 GB.\n\n";
}
