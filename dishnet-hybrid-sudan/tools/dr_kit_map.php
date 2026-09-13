<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * dr_kit_map.php — the KIT → service line map, in the format data-report wants.
 *
 *   php tools/dr_kit_map.php            what we can map, and what we cannot
 *   php tools/dr_kit_map.php --paste    just the KIT=SL lines, nothing else
 *
 * dishnet-data-report cannot resolve a service line from a kit serial on its
 * own for every dish — Mini dishes in particular — so it has a box in its Sync
 * Status screen where a person types the pairs:
 *
 *     KIT4M03465049J8J=SL-DF-8603545-59125-61
 *
 * South Sudan has been fed by hand that way for years. Uganda never was, which
 * is why that plugin reports "Stale KITs 0 / 0" here and its sync has nothing
 * to collect: no kit, no telemetry call, an empty sl_usage.json on every run.
 *
 * Typing them again by hand would be a second source of truth for something we
 * already know exactly. equipment_assignments holds the serial and the service
 * line because both are captured at installation, against a uCRM client id —
 * so this prints what we already know, in their format, and a person pastes it.
 *
 * It reads our database and writes nothing, here or there.
 *
 * ── WHAT IT WILL NOT DO ─────────────────────────────────────────────────
 *
 * It never invents a pairing. A kit with no service line recorded is listed as
 * unmappable with the reason, because a guessed pair sends one customer's
 * telemetry to another customer's row — and that figure would then be used to
 * bill them.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/EquipmentAssignment.php';
require_once $root . '/lib/StarlinkServiceState.php';

$args  = array_slice($argv, 1);
$paste = in_array('--paste', $args, true);

$dataDir = cliDataDir($root);
$ea      = EquipmentAssignment::fromStore(SqliteStore::create($dataDir));

$mappable = [];
$cannot   = [];
foreach ($ea->liveAssignments() as $a) {
    $kit  = EquipmentAssignment::clean((string)($a['kit_serial'] ?? ''));
    $line = StarlinkServiceState::normalise((string)($a['starlink_service_line'] ?? ''));
    $acct = StarlinkServiceState::normalise((string)($a['starlink_account'] ?? ''));
    $cid  = (int)($a['crm_client_id'] ?? 0);

    if ($kit === '') {
        $cannot[] = ['kit' => '(no serial)', 'client' => $cid,
                     'why' => 'the assignment carries no kit serial'];
        continue;
    }
    if ($line === '') {
        $cannot[] = ['kit' => $kit, 'client' => $cid,
                     'why' => 'no Starlink service line was recorded at installation'];
        continue;
    }
    $mappable[] = ['kit' => $kit, 'line' => $line, 'account' => $acct, 'client' => $cid];
}

// --paste: the lines and nothing else, so the output can go straight into
// their box without a person editing headings out of it.
if ($paste) {
    foreach ($mappable as $m) echo $m['kit'] . '=' . $m['line'] . "\n";
    exit($mappable === [] ? 1 : 0);
}

echo "\n  KIT → SERVICE LINE MAP for dishnet-data-report\n";
echo "  " . str_repeat('─', 68) . "\n\n";

if ($mappable === []) {
    echo "  Nothing to map. No live assignment carries both a kit serial and a\n";
    echo "  Starlink service line.\n\n";
} else {
    printf("  %d pair(s) — paste these into Sync Status → Manual KIT → Service Line Map:\n\n",
        count($mappable));
    foreach ($mappable as $m) echo '    ' . $m['kit'] . '=' . $m['line'] . "\n";
    echo "\n  Which account each one is on, because a cookie that cannot see the\n";
    echo "  account cannot fetch its telemetry however correct the pairing is:\n\n";
    $byAcct = [];
    foreach ($mappable as $m) $byAcct[$m['account'] !== '' ? $m['account'] : '(not recorded)'][] = $m;
    foreach ($byAcct as $acct => $rows) {
        printf("    %-28s %d kit(s)\n", $acct, count($rows));
        foreach ($rows as $m) printf("      %-22s client #%d\n", $m['kit'], $m['client']);
    }
    echo "\n";
}

if ($cannot !== []) {
    printf("  %d assignment(s) CANNOT be mapped, and are not guessed at:\n\n", count($cannot));
    foreach ($cannot as $c) printf("    %-22s client #%-6d %s\n", $c['kit'], $c['client'], $c['why']);
    echo "\n  A guessed pairing sends one customer's telemetry to another customer's\n";
    echo "  row, and that figure gets used to bill them. Record the service line\n";
    echo "  against the assignment instead — tools/binding_doctor.php --learn, or\n";
    echo "  the installation screen — and run this again.\n\n";
}

echo "  Nothing was written. This reads equipment_assignments and prints it.\n\n";
exit(0);
