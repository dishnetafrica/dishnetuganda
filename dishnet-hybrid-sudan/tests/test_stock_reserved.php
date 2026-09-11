<?php
declare(strict_types=1);
/**
 * test_stock_reserved.php — the same dish promised twice.
 *
 * A unit had two honest states: on the shelf, or at a customer. The days
 * between selling a dish and mounting it had no state at all, so during them
 * the unit was indistinguishable from any other in the warehouse. Two jobs
 * booked a week apart could each be promised it, and the second install was
 * where anyone found out.
 *
 * The audit brief names both halves of that failure — "a customer has
 * equipment assigned but inventory still shows it as available", and
 * "equipment is sold but stock is not reduced". Reserved is the state
 * between, and these assertions hold it to three things: it takes a
 * customer, it comes off the available count, and it cannot be quietly
 * handed to somebody else.
 */
require_once dirname(__DIR__) . '/lib/StoreInterface.php';
require_once dirname(__DIR__) . '/lib/JsonStore.php';
require_once dirname(__DIR__) . '/lib/SqliteStore.php';
require_once dirname(__DIR__) . '/lib/StockService.php';
require_once dirname(__DIR__) . '/lib/PurchaseService.php';

$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; } }
/** @return string the exception message, or '' if it did not throw */
function threw(callable $fn): string {
    try { $fn(); return ''; } catch (\Throwable $e) { return $e->getMessage(); }
}

$tmp = sys_get_temp_dir() . '/dn_reserved_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);
$store = SqliteStore::create($tmp);
$pdo   = $store->getPdo();
$stock = StockService::fromStore($store, $tmp);
$stock->ensureTables();
$pur   = new PurchaseService($pdo, $tmp, $stock);
$staff = ['id' => 7, 'name' => 'Bhavin Madlani'];

$kit = (int)($stock->saveCategory(['title' => 'Starlink Standard Kit', 'sku' => 'SL-STD',
    'service_type' => 'starlink', 'track_mode' => 'serial', 'buy_price' => 2144704])['id'] ?? 0);
$pur->receive(['supplier' => 'Starlink Uganda', 'invoice_number' => 'SL-1', 'idem_key' => 'r1'],
    [['category_id' => $kit, 'quantity' => 2, 'unit_cost' => 2144704,
      'serials' => ['KIT-001', 'KIT-002']]], $staff);
$u1 = (int)$pdo->query("SELECT id FROM stock_units WHERE serial_number='KIT-001'")->fetchColumn();
$u2 = (int)$pdo->query("SELECT id FROM stock_units WHERE serial_number='KIT-002'")->fetchColumn();
is_($u1 > 0 && $u2 > 0, 'two kits are on the shelf');

// ── Reserving ───────────────────────────────────────────────────────────────
echo "\nHolding one for a customer\n";
$s0 = $stock->getDashboardStats();
t('both count as available to start', $s0['in_stock'], 2);

$r = $stock->reserve($u1, ['crm_client_id' => 4021, 'client_name' => 'Family Shoppers'], 7, 'Bhavin');
t('the unit is reserved', $r['status'], 'reserved');
t('for the customer it was sold to', (int)$r['crm_client_id'], 4021);
t('and it has not moved anywhere', $r['location_type'], 'warehouse');

$s1 = $stock->getDashboardStats();
t('available drops to one — the reserved one is spoken for', $s1['in_stock'], 1);
t('and is counted as reserved instead', $s1['reserved'], 1);
// Still ours, still in the warehouse, still worth what it cost.
t('but it still counts in stock value', $s1['stock_value'], 4289408.0);

$v = $pur->inventoryValue();
t('and in the inventory valuation', $v['serial'], 4289408.0);

// ── What it refuses ─────────────────────────────────────────────────────────
echo "\nWhat a reservation will not allow\n";
$e = threw(fn() => $stock->reserve($u1, ['crm_client_id' => 5099], 7, 'Bhavin'));
is_(strpos($e, '4021') !== false,
    'reserving it again names who already holds it', $e ?: '(it did not throw)');

$e = threw(fn() => $stock->reserve($u2, [], 7, 'Bhavin'));
is_(strpos($e, 'crm_client_id') !== false,
    'a reservation for nobody is refused — that is just stock', $e ?: '(it did not throw)');

// The one that matters: installing a held unit at a different customer.
$e = threw(fn() => $stock->install($u1, ['crm_client_id' => 5099, 'client_name' => 'Someone Else'], 7, 'Bhavin'));
is_(strpos($e, 'reserved for client #4021') !== false,
    "THE DISH CANNOT BE GIVEN TO SOMEBODY ELSE — the first customer's kit "
    . 'used to vanish with nothing recording it', $e ?: '(it did not throw)');

$e = threw(fn() => $stock->release($u2, 7, 'Bhavin'));
is_(strpos($e, 'not reserved') !== false,
    'releasing something that was never held is refused', $e ?: '(it did not throw)');

// ── Reserved → installed, for the right customer ────────────────────────────
echo "\nThe install it was being held for\n";
$done = $stock->install($u1, ['crm_client_id' => 4021, 'client_name' => 'Family Shoppers',
                              'crm_service_id' => 88, 'job_id' => 301], 7, 'Bhavin');
t('it installs', $done['status'], 'installed');
t('at the customer it was held for', (int)$done['crm_client_id'], 4021);
t('against their service', (int)$done['crm_service_id'], 88);
$s2 = $stock->getDashboardStats();
t('nothing is reserved any more', $s2['reserved'], 0);
t('one kit is installed', $s2['installed'], 1);
t('and one is still on the shelf', $s2['in_stock'], 1);

// ── Release puts it back ────────────────────────────────────────────────────
echo "\nWhen the sale falls through\n";
$stock->reserve($u2, ['crm_client_id' => 7777, 'client_name' => 'Cancelled Co'], 7, 'Bhavin');
t('held', $stock->getDashboardStats()['reserved'], 1);
$back = $stock->release($u2, 7, 'Bhavin', 'customer cancelled before install');
t('released back to the shelf', $back['status'], 'in_stock');
is_(($back['crm_client_id'] ?? null) === null, 'and is nobody\'s again');
t('available again', $stock->getDashboardStats()['in_stock'], 1);

// ── Every step is in the movement log ───────────────────────────────────────
echo "\nThe log says what happened to each unit\n";
$mv = $pdo->prepare("SELECT movement_type, note FROM stock_movements WHERE unit_id = ? ORDER BY id");
$mv->execute([$u1]);
$types = array_column($mv->fetchAll(PDO::FETCH_ASSOC), 'movement_type');
t('received, reserved, installed — in that order', $types, ['inbound', 'reserve', 'install']);

$mv->execute([$u2]);
$rows = $mv->fetchAll(PDO::FETCH_ASSOC);
t('and the other was received, reserved, released',
  array_column($rows, 'movement_type'), ['inbound', 'reserve', 'release']);
is_(strpos((string)end($rows)['note'], '7777') !== false,
    'the release records who lost it — the column holding that is cleared');
is_(strpos((string)end($rows)['note'], 'cancelled before install') !== false,
    'and why');

// ── The vocabulary agrees with itself ───────────────────────────────────────
echo "\nOne vocabulary\n";
is_(in_array('reserved', StockService::UNIT_STATUSES, true), 'reserved is a declared status');
foreach (['reserve', 'release'] as $m) {
    is_(in_array($m, StockService::MOVEMENT_TYPES, true), "{$m} is a declared movement");
}
$used = $pdo->query("SELECT DISTINCT movement_type FROM stock_movements")->fetchAll(PDO::FETCH_COLUMN);
$unknown = array_diff($used, StockService::MOVEMENT_TYPES);
is_($unknown === [], 'nothing wrote a movement type nobody declared',
    implode(',', $unknown));
$statuses = $pdo->query("SELECT DISTINCT status FROM stock_units")->fetchAll(PDO::FETCH_COLUMN);
is_(array_diff($statuses, StockService::UNIT_STATUSES) === [],
    'nor a unit status nobody declared');

exec('rm -rf ' . escapeshellarg($tmp));
echo "\n  {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
