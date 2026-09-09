<?php
/**
 * test_kit_ownership.php — one owner for equipment, and only one.
 *
 * A KitRegister was added, then found to duplicate StockService inside the
 * same plugin: serial numbers, movements, customer location, supplier status.
 * Two tables both claiming to say which kit a customer has will disagree
 * eventually, and the disagreement gets discovered in front of the customer.
 *
 * These assertions are what stops it coming back.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/lib/StockService.php';
require_once $root . '/lib/StarlinkBlockBridge.php';

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

echo "\nThe duplicate store is gone\n";
is_(!is_file($root . '/lib/KitRegister.php'), 'KitRegister.php no longer exists');
is_(!class_exists('KitRegister'), 'and nothing defines the class');
$refs = [];
foreach (['workers/StarlinkMailWorker.php', 'tools/kits.php', 'cron/inbound_mail.php'] as $f) {
    $src = @file_get_contents($root . '/' . $f);
    if ($src !== false && strpos($src, 'KitRegister') !== false) $refs[] = $f;
}
is_($refs === [], 'and nothing still calls it', implode(', ', $refs));

echo "\nStockService holds what it held\n";
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$stock = new StockService($pdo, sys_get_temp_dir());
$stock->ensureTables();

$cols = [];
foreach ($pdo->query('PRAGMA table_info(stock_units)') as $r) $cols[] = (string)$r['name'];
foreach (['serial_number'  => 'the kit serial',
          'starlink_account' => "the supplier account it belongs to",
          'starlink_status'  => "the supplier's view of it, separate from ours",
          'status'           => 'our own physical status',
          'crm_client_id'    => 'who holds it'] as $c => $what) {
    is_(in_array($c, $cols, true), "stock_units already had {$c} — {$what}");
}
is_(in_array('starlink_service_line', $cols, true),
    'and now has starlink_service_line, the one field that was genuinely missing');

echo "\nThe migration reaches an install that already has the tables\n";
// ensureTables() returns early when the tables exist. A column added after
// that return works on fresh installs and on no existing one — which is
// every install that matters.
$pdo2 = new PDO('sqlite::memory:');
$pdo2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo2->exec("CREATE TABLE stock_categories (id INTEGER PRIMARY KEY, service_type TEXT)");
$pdo2->exec("CREATE TABLE stock_units (id INTEGER PRIMARY KEY, serial_number TEXT)");
$stock2 = new StockService($pdo2, sys_get_temp_dir());
$stock2->ensureTables();                       // takes the early-return path
$cols2 = [];
foreach ($pdo2->query('PRAGMA table_info(stock_units)') as $r) $cols2[] = (string)$r['name'];
is_(in_array('starlink_service_line', $cols2, true),
    'the column is added even when ensureTables returns early', implode(',', $cols2));

echo "\nEquipment is not created from an inbox\n";
$worker = (string)file_get_contents($root . '/workers/StarlinkMailWorker.php');
is_(strpos($worker, 'createUnit') === false,
    'supplier mail does not put hardware on the books');
is_(strpos($worker, 'not the same as holding') !== false,
    'and says why: a shipping notice is not a received kit');

echo "\nPrepaid does not run a postpaid suspension sweep\n";
is_(StarlinkBlockBridge::appliesTo(['billing_model' => 'prepaid']) === false,
    'a prepaid install never blocks hardware for arrears it cannot have');
is_(StarlinkBlockBridge::appliesTo(['billing_model' => 'postpaid']) === true,
    'a postpaid install still does');
is_(StarlinkBlockBridge::appliesTo([]) === true,
    'and absent config means postpaid — the Sudan install is unchanged');
is_(StarlinkBlockBridge::appliesTo(['billing_model' => ' PrePaid ']) === false,
    'the check is not fooled by case or spacing');

$hook = (string)file_get_contents($root . '/webhook.php');
is_(substr_count($hook, 'StarlinkBlockBridge::appliesTo($config)') === 2,
    'both webhook call sites consult it — suspend and restore',
    substr_count($hook, 'StarlinkBlockBridge::appliesTo($config)') . ' site(s)');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
