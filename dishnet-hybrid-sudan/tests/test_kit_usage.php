<?php
declare(strict_types=1);
/**
 * test_kit_usage.php — whose usage is this, and is it usage or is it silence?
 *
 * The data-report plugin collects usage and cannot say who owns it: there is
 * no crm_client_id anywhere in that plugin, which is why South Sudan answers
 * the question by parsing uCRM service names. equipment_assignments knows the
 * customer and the serial, so the join is an exact match on a serial the
 * assignment itself supplied.
 *
 * The distinction this file exists to protect:
 *
 *   ZERO IS NOT UNKNOWN. A customer who used nothing and a customer whose
 *   telemetry was never collected both produce no rows. Showing 0 GB to the
 *   second is the same family of lie as showing "unlimited" to a capped
 *   customer — it looks like an answer.
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

$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; } }

// A staged plugins directory, so the sibling read is the real one.
$base = sys_get_temp_dir() . '/dn_usage_' . bin2hex(random_bytes(4));
$plugins = $base . '/plugins';
@mkdir($plugins . '/dishnet-data-report/data', 0777, true);
@mkdir($base . '/data', 0777, true);
putenv('DN_PLUGIN_ROOT=' . $plugins . '/dishnet-hybrid-sudan');

file_put_contents($plugins . '/dishnet-data-report/data/sl_usage.json', json_encode([
    ['service_line' => 'SL-1', 'kit_number' => 'KIT404246364BX6', 'cycle_key' => '2026-07',
     'cycle_label' => 'Jul 2026', 'total_gb' => 120.5, 'other_data_gb' => 120.5],
    ['service_line' => 'SL-1', 'kit_number' => 'KIT404246364BX6', 'cycle_key' => '2026-09',
     'cycle_label' => 'Sep 2026', 'total_gb' => 410.0, 'other_data_gb' => 410.0],
    ['service_line' => 'SL-1', 'kit_number' => 'KIT404246364BX6', 'cycle_key' => '2026-08',
     'cycle_label' => 'Aug 2026', 'total_gb' => 300.0],
    // Somebody else's kit, in the same file.
    ['service_line' => 'SL-9', 'kit_number' => 'KITSOMEONEELSE9', 'cycle_key' => '2026-09',
     'total_gb' => 999.0],
]));

$store = SqliteStore::create($base . '/data');
$pdo   = $store->getPdo();
$stock = StockService::fromStore($store, $base . '/data');
$stock->ensureTables();
foreach (MigrationRunner::splitStatements((string)file_get_contents(
        dirname(__DIR__) . '/migrations/068_equipment_assignments.sql')) as $q) $pdo->exec($q);
$ea  = new EquipmentAssignment($pdo);
$cat = (int)($stock->saveCategory(['title' => 'Kit', 'sku' => 'K', 'service_type' => 'starlink',
    'track_mode' => 'serial'])['id'] ?? 0);
$u1  = (int)$stock->createUnit(['category_id' => $cat, 'serial_number' => 'KIT404246364BX6'], 7, 'b')['id'];
$u2  = (int)$stock->createUnit(['category_id' => $cat, 'serial_number' => 'KITNOTELEMETRY1'], 7, 'b')['id'];
$stock->install($u1, ['crm_client_id' => 7, 'crm_service_id' => 1, 'client_name' => 'African skies Ltd'], 7, 'b');
$stock->install($u2, ['crm_client_id' => 7, 'crm_service_id' => 2, 'client_name' => 'African skies Ltd'], 7, 'b');

$usage = new KitUsage($ea);

echo "One customer's usage, and nobody else's\n";
$k = $usage->forKit('KIT404246364BX6');
t('it is available',      $k['available'], true);
t('three cycles',         count($k['cycles']), 3);
t('oldest first',         $k['cycles'][0]['cycle_key'], '2026-07');
t('newest last',          $k['cycles'][2]['cycle_key'], '2026-09');
// json_encode drops a round float's fraction, so the stored figure comes back
// as an int. against() casts; the raw row is compared as it really is.
t('the current cycle is the newest',
    (float)$usage->currentCycle('KIT404246364BX6')['total_gb'], 410.0);
is_(count(array_filter($k['cycles'],
        static fn($c) => ($c['kit_number'] ?? '') !== 'KIT404246364BX6')) === 0,
    "and another customer's kit is never mixed in");
t('a lower-case serial still matches', $usage->forKit('kit404246364bx6')['available'], true);

echo "\nZero is not the same as unknown\n";
$none = $usage->forKit('KITNOTELEMETRY1');
t('a kit with no rows is not available', $none['available'], false);
t('and reports no cycles',               $none['cycles'], []);
is_(strpos($none['reason'], 'no telemetry has been collected') !== false,
    'saying telemetry has not been collected — not that usage is zero', $none['reason']);
$blank = $usage->forKit('');
is_(strpos($blank['reason'], 'no kit serial') !== false, 'an empty serial says so too');

echo "\nUsage measured against what was sold\n";
$capped = ServicePlan::fromService(['invoiceLabel' => 'Services Plan : DishNet Business 6TB']);
$a = $usage->against('KIT404246364BX6', $capped);
t('it is known',          $a['known'], true);
t('used this cycle',      $a['used_gb'], 410.0);
t('out of 6TB',           $a['cap_gb'], 6144.0);
t('which is 6.7%',        $a['pct'], 6.7);
t('leaving 5,734 GB',     $a['remaining_gb'], 5734.0);
t('on the labelled cycle', $a['cycle'], 'Sep 2026');

$unl = $usage->against('KIT404246364BX6',
    ServicePlan::fromService(['servicePlanName' => 'DishNet Unlimited Business']));
t('an unlimited plan is known',  $unl['known'], true);
t('and says so',                 $unl['unlimited'], true);
t('with no percentage to show',  $unl['pct'], null);

echo "\nReal usage, unknown allowance — the live Uganda case\n";
// African skies' service is named "Starlink Residential": real usage, and an
// allowance nobody can state. Neither a cap nor "unlimited" may be implied.
$ugPlan = ServicePlan::fromService(['name' => 'Site : KIT404246364BX6  Service Plan: Starlink Residential']);
$ug = $usage->against('KIT404246364BX6', $ugPlan);
t('the usage is still reported', $ug['used_gb'], 410.0);
t('but it is not known',         $ug['known'], false);
t('not unlimited',               $ug['unlimited'], false);
t('and no percentage is invented', $ug['pct'], null);
is_(strpos($ug['reason'], 'names no allowance') !== false,
    'and it says the service names no allowance', $ug['reason']);

$noPlan = $usage->against('KIT404246364BX6', null);
t('no plan at all behaves the same', $noPlan['known'], false);
t('while still reporting the usage', $noPlan['used_gb'], 410.0);

echo "\nThe whole chain, from a client id\n";
$plans = [1 => $capped, 2 => null];
$rows = $usage->forClient(7, static function (array $a) use ($plans) {
    return $plans[(int)$a['crm_service_id']] ?? null;
});
t('both kits come back', count($rows), 2);
t('the first is the one with telemetry', $rows[0]['kit_serial'], 'KIT404246364BX6');
t('and it is known',      $rows[0]['usage']['known'], true);
t('at 6.7%',              $rows[0]['usage']['pct'], 6.7);
t('the second has no telemetry', $rows[1]['usage']['used_gb'], null);
is_(strpos($rows[1]['usage']['reason'], 'no telemetry') !== false,
    'and says why rather than showing zero');
t('a customer with nothing gets nothing', $usage->forClient(999), []);

echo "\nWith the data plugin absent\n";
// Not installed is not "used nothing". A portal that shows 0 GB because a
// sibling plugin is missing is inventing a fact.
putenv('DN_PLUGIN_ROOT=' . $base . '/nowhere/dishnet-hybrid-sudan');
SiblingPlugin::reset();
$blind = new KitUsage($ea);
t('there are no rows to read', $blind->rows(), null);
$b = $blind->forKit('KIT404246364BX6');
t('nothing is available', $b['available'], false);
is_(strpos($b['reason'], 'written no usage file') !== false,
    'and it says the source is missing, not that usage is zero', $b['reason']);
t('and no usage figure is produced', $blind->against('KIT404246364BX6', $capped)['used_gb'], null);

putenv('DN_PLUGIN_ROOT');
SiblingPlugin::reset();
exec('rm -rf ' . escapeshellarg($base));
echo "\n  {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
