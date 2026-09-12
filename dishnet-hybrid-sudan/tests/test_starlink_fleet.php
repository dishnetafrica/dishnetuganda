<?php
declare(strict_types=1);
/**
 * test_starlink_fleet.php — the fleet list, and the three ways it can fail to know.
 *
 * The screen is only worth having if its gaps are legible. There are three
 * distinct ones that a careless renderer collapses into "0 GB":
 *
 *   1. no usage file at all         — the data plugin has never run here
 *   2. a file, but nothing for me   — it ran; this kit is silent
 *   3. usage, but no stated allowance — the figure is real, the % is not
 *
 * And two things this list must never do: invent a customer name, or let a
 * kit onto the list because something other than equipment_assignments said
 * it belonged to somebody.
 */
require_once dirname(__DIR__) . '/lib/StoreInterface.php';
require_once dirname(__DIR__) . '/lib/JsonStore.php';
require_once dirname(__DIR__) . '/lib/SqliteStore.php';
require_once dirname(__DIR__) . '/lib/StockService.php';
require_once dirname(__DIR__) . '/lib/MigrationRunner.php';
require_once dirname(__DIR__) . '/lib/EquipmentAssignment.php';
require_once dirname(__DIR__) . '/lib/ServicePlan.php';
require_once dirname(__DIR__) . '/lib/SiblingPlugin.php';
require_once dirname(__DIR__) . '/lib/KitUsage.php';
require_once dirname(__DIR__) . '/lib/CrmKitAttribute.php';
require_once dirname(__DIR__) . '/lib/StarlinkFleet.php';

$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; } }

/** A uCRM stand-in: services by id, so plan and label resolution are real. */
final class FleetFakeCrm
{
    public array $services;
    public function __construct(array $s) { $this->services = $s; }
    public function get(string $path) {
        if (preg_match('#^clients/services/(\d+)$#', $path, $m)) {
            return $this->services[(int)$m[1]] ?? null;
        }
        return null;
    }
    public function patch(string $p, array $b) { return []; }
}

function fleetEnv(array $usageRows = null): array
{
    $base    = sys_get_temp_dir() . '/dn_fleet_' . bin2hex(random_bytes(4));
    $plugins = $base . '/plugins';
    @mkdir($plugins . '/dishnet-data-report/data', 0777, true);
    @mkdir($base . '/data', 0777, true);
    putenv('DN_PLUGIN_ROOT=' . $plugins . '/dishnet-hybrid-sudan');
    // The sibling resolver caches directory lookups for the life of the
    // process — right for a request, wrong for a test that stands up several
    // plugin trees in a row. Without this, the second environment answers
    // with the first one's usage file.
    SiblingPlugin::reset();
    if ($usageRows !== null) {
        file_put_contents($plugins . '/dishnet-data-report/data/sl_usage.json',
            json_encode($usageRows));
    }

    $store = SqliteStore::create($base . '/data');
    $pdo   = $store->getPdo();
    $stock = StockService::fromStore($store, $base . '/data');
    $stock->ensureTables();
    foreach (MigrationRunner::splitStatements((string)file_get_contents(
            dirname(__DIR__) . '/migrations/068_equipment_assignments.sql')) as $q) $pdo->exec($q);

    $ea  = new EquipmentAssignment($pdo);
    $cat = (int)($stock->saveCategory(['title' => 'Kit', 'sku' => 'K',
        'service_type' => 'starlink', 'track_mode' => 'serial'])['id'] ?? 0);
    return ['base' => $base, 'store' => $store, 'pdo' => $pdo,
            'stock' => $stock, 'ea' => $ea, 'cat' => $cat];
}

// ── The fleet as it really is on the Uganda box ──────────────────────────
$e = fleetEnv([
    ['kit_number' => 'KIT404246364BX6', 'cycle_key' => '2026-09',
     'cycle_label' => 'Sep 2026', 'total_gb' => 22.0],
]);
$u1 = (int)$e['stock']->createUnit(['category_id' => $e['cat'], 'serial_number' => 'KIT404246364BX6'], 7, 'b')['id'];
$u2 = (int)$e['stock']->createUnit(['category_id' => $e['cat'], 'serial_number' => 'KITSILENT00002'], 7, 'b')['id'];
$e['stock']->install($u1, ['crm_client_id' => 7, 'crm_service_id' => 1,
    'client_name' => 'African skies Ltd', 'starlink_service_line' => 'SL-DF-16046613-35504-0'], 7, 'b');
$e['stock']->install($u2, ['crm_client_id' => 9, 'crm_service_id' => 2, 'client_name' => 'Someone Else'], 7, 'b');

$crm = new FleetFakeCrm([
    // The real service name from uCRM — a speed tier, naming no allowance.
    1 => ['id' => 1, 'name' => 'Site : KIT404246364BX6  Service Plan: Starlink Residential',
          'attributes' => [['key' => 'starlinkDetails', 'value' => 'KIT404246364BX6']]],
    2 => ['id' => 2, 'invoiceLabel' => 'Services Plan : DishNet Business 6TB', 'attributes' => []],
]);
$kitAttr = new CrmKitAttribute($crm, $e['ea']);

echo "The fleet, one row per kit in the field\n";
$f = (new StarlinkFleet($e['ea'], new KitUsage($e['ea']), $e['store'], $kitAttr))->build();
t('two kits are out',        $f['summary']['kits'], 2);
t('held by two customers',   $f['summary']['customers'], 2);
t('ordered by customer',     array_column($f['rows'], 'client_id'), [7, 9]);
t('the first is our kit',    $f['rows'][0]['kit_serial'], 'KIT404246364BX6');
t('bound to client 7',       $f['rows'][0]['client_id'], 7);
t('on service 1',            $f['rows'][0]['service_id'], 1);
t('carrying its service line', $f['rows'][0]['service_line'], 'SL-DF-16046613-35504-0');

echo "\nA reading, with nothing to measure it against\n";
$r0 = $f['rows'][0];
t('22 GB is reported',       $r0['usage']['used_gb'], 22.0);
t('on the labelled cycle',   $r0['usage']['cycle'], 'Sep 2026');
// "Starlink Residential" names no size. The usage is real; the percentage
// is unknowable. Showing 100% — or 0% — would both be inventions.
t('but no percentage',       $r0['usage']['pct'], null);
t('and it is not unlimited', $r0['usage']['unlimited'], false);
t('the allowance is unknown', $r0['plan']['known'], false);
is_(strpos($r0['usage']['reason'], 'names no allowance') !== false,
    'and the row says exactly why', $r0['usage']['reason']);
t('counted as allowance unknown', $f['summary']['allowance_unknown'], 1);

echo "\nSilence is not zero\n";
$r1 = $f['rows'][1];
t('the silent kit has no figure', $r1['usage']['used_gb'], null);
is_(strpos($r1['usage']['reason'], 'no telemetry has been collected') !== false,
    'it reports uncollected telemetry, not 0 GB', $r1['usage']['reason']);
t('and is counted as silent',     $f['summary']['usage_silent'], 1);
t('while the other has a reading', $f['summary']['usage_known'], 1);
// The silent kit IS on a 6TB plan — a known allowance with no usage. Those
// are independent facts and the summary keeps them apart.
t('its allowance is known',       $r1['plan']['known'], true);
t('6TB',                          $r1['plan']['cap_gb'], 6144.0);

echo "\nThe label uCRM carries, compared not trusted\n";
t('service 1 carries our serial', $f['rows'][0]['label'], 'match');
t('service 2 carries none',       $f['rows'][1]['label'], 'missing');
t('summary counts the match',     $f['summary']['label_match'], 1);
t('and the gap',                  $f['summary']['label_missing'], 1);

echo "\nNo usage file at all is its own answer\n";
$e2 = fleetEnv(null);   // nothing written by the data plugin
$u3 = (int)$e2['stock']->createUnit(['category_id' => $e2['cat'], 'serial_number' => 'KITNOFILE000001'], 7, 'b')['id'];
$e2['stock']->install($u3, ['crm_client_id' => 7, 'crm_service_id' => 1, 'client_name' => 'X'], 7, 'b');
$f2 = (new StarlinkFleet($e2['ea'], new KitUsage($e2['ea']), $e2['store'], null))->build();
t('telemetry is unavailable',  $f2['telemetry']['available'], false);
is_(strpos($f2['telemetry']['reason'], 'no usage file') !== false,
    'and the screen says the source is missing', $f2['telemetry']['reason']);
is_(strpos($f2['telemetry']['reason'], 'not the same as a fleet at zero') !== false,
    'spelling out that it is not a fleet at zero');
t('the kit still lists',       $f2['summary']['kits'], 1);
t('with no figure',            $f2['rows'][0]['usage']['used_gb'], null);

echo "\nWithout uCRM the list still stands, and admits what it cannot see\n";
t('the plan is null, not guessed', $f2['rows'][0]['plan'], null);
t('the label is "unknown", not "missing"', $f2['rows'][0]['label'], 'unknown');
// 'missing' would accuse an operator of not labelling a service nobody read.
t('and unknown is not counted as a gap', $f2['summary']['label_missing'], 0);

echo "\nA name is for the eye, never for the join\n";
$e3 = fleetEnv([]);
$u4 = (int)$e3['stock']->createUnit(['category_id' => $e3['cat'], 'serial_number' => 'KITNONAME000001'], 7, 'b')['id'];
$e3['stock']->install($u4, ['crm_client_id' => 4242, 'crm_service_id' => 1, 'client_name' => 'Typed By Hand'], 7, 'b');
$f3 = (new StarlinkFleet($e3['ea'], new KitUsage($e3['ea']), $e3['store'], null))->build();
// Nothing in the clients cache for 4242. The row shows an empty name so the
// screen can fall back to the id — it does not borrow the name a person typed
// onto the stock unit, which is not the customer's identity.
t('an unknown client gets no name', $f3['rows'][0]['client_name'], '');
t('but keeps its id',               $f3['rows'][0]['client_id'], 4242);

echo "\nA released kit leaves the fleet\n";
$e4 = fleetEnv([]);
$u5 = (int)$e4['stock']->createUnit(['category_id' => $e4['cat'], 'serial_number' => 'KITRETURNED0001'], 7, 'b')['id'];
$e4['stock']->install($u5, ['crm_client_id' => 7, 'crm_service_id' => 1, 'client_name' => 'X'], 7, 'b');
t('it is on the list',  (new StarlinkFleet($e4['ea'], new KitUsage($e4['ea']), $e4['store'], null))->build()['summary']['kits'], 1);
$e4['ea']->release($u5, ['reason' => 'returned'], ['id' => 7, 'name' => 'b']);
$f4 = (new StarlinkFleet($e4['ea'], new KitUsage($e4['ea']), $e4['store'], null))->build();
t('and gone once released', $f4['summary']['kits'], 0);
// Gone from the fleet, still in the history — release is not deletion.
t('but its history survives', count($e4['ea']->historyForUnit($u5)), 1);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
