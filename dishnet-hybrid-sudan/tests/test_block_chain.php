<?php
declare(strict_types=1);
/**
 * test_block_chain.php — can a non-paying customer's WiFi actually be cut off?
 *
 * Blocking is a chain and every link of it lives somewhere else:
 *
 *   uCRM says suspended
 *     → which dish does this customer have?
 *       → which router is behind that dish?     (dishnet-data-report)
 *         → speak gRPC to the router            (dishnet-data-report)
 *
 * Hybrid does not speak gRPC and never has. Every router call goes out over
 * HTTP to dishnet-data-report — which, despite its name, is not a reporting
 * plugin but the gateway to the dish. On this server it is not installed, so
 * nothing can block anybody, and the only evidence was a webhook log line.
 *
 * The link this change adds is the first one. Before it, "which dish does
 * this customer have" had two answers, both inferences: a file belonging to
 * a plugin that is not installed, and a regular expression run over a
 * service title somebody typed by hand. The authoritative answer was already
 * being written by the install — a unit in stock_units, against a client —
 * and nothing was reading it.
 */
require_once dirname(__DIR__) . '/lib/StoreInterface.php';
require_once dirname(__DIR__) . '/lib/JsonStore.php';
require_once dirname(__DIR__) . '/lib/SqliteStore.php';
require_once dirname(__DIR__) . '/lib/StockService.php';
require_once dirname(__DIR__) . '/lib/PurchaseService.php';
require_once dirname(__DIR__) . '/lib/StarlinkBlockService.php';

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; } }

$tmp = sys_get_temp_dir() . '/dn_block_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);
$store = SqliteStore::create($tmp);
$pdo   = $store->getPdo();
$stock = StockService::fromStore($store, $tmp);
$stock->ensureTables();
$pur   = new PurchaseService($pdo, $tmp, $stock);
$staff = ['id' => 7, 'name' => 'Bhavin'];

// A kit received and installed — the ordinary flow, nothing special for this.
$cat = (int)($stock->saveCategory(['title' => 'Starlink Standard Kit', 'sku' => 'SL-STD',
    'service_type' => 'starlink', 'track_mode' => 'serial'])['id'] ?? 0);
$pur->receive(['supplier' => 'Starlink Uganda', 'invoice_number' => 'SL-1', 'idem_key' => 'b1'],
    [['category_id' => $cat, 'quantity' => 2, 'unit_cost' => 2144704,
      'serials' => ['KIT401723651PG7', 'KIT999999999ZZ9']]], $staff);
$u1 = (int)$pdo->query("SELECT id FROM stock_units WHERE serial_number='KIT401723651PG7'")->fetchColumn();
$stock->install($u1, ['crm_client_id' => 4021, 'client_name' => 'Family Shoppers'], 7, 'Richard');

// getClientKitSerials is private — the block service is the public surface,
// so reach it the way the code does rather than changing visibility for a test.
$svc = new StarlinkBlockService($pdo, $store, [], $tmp, null);
$ref = new ReflectionMethod(StarlinkBlockService::class, 'getClientKitSerials');
$ref->setAccessible(true);

echo "\nWhich dish belongs to this customer\n";
$kits = $ref->invoke($svc, 4021);
t('the installed kit is found', $kits, ['KIT401723651PG7']);
is_(!in_array('KIT999999999ZZ9', $kits, true),
    "and the one still on the shelf is not — blocking it would cut off nobody, "
    . 'or somebody else');
t('a customer with no dish gets nothing', $ref->invoke($svc, 9999), []);

echo "\nA reserved dish counts too\n";
// Reserved means sold and held for them. If they never pay for the install,
// the block has to find it.
$u2 = (int)$pdo->query("SELECT id FROM stock_units WHERE serial_number='KIT999999999ZZ9'")->fetchColumn();
$stock->reserve($u2, ['crm_client_id' => 5500, 'client_name' => 'Held Co'], 7, 'Bhavin');
t('a held kit resolves for its customer', $ref->invoke($svc, 5500), ['KIT999999999ZZ9']);

echo "\nA released dish stops counting\n";
$stock->release($u2, 7, 'Bhavin', 'sale fell through');
t('it is nobody\'s again', $ref->invoke($svc, 5500), []);

echo "\nWithout stock tables at all it does not throw\n";
$bare = sys_get_temp_dir() . '/dn_block_bare_' . bin2hex(random_bytes(4));
@mkdir($bare, 0777, true);
$bpdo = new PDO('sqlite:' . $bare . '/empty.sqlite3');
$bpdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$bsvc = new StarlinkBlockService($bpdo, $store, [], $bare, null);
$bref = new ReflectionMethod(StarlinkBlockService::class, 'getClientKitSerials');
$bref->setAccessible(true);
t('an install with no stock tables answers empty, not an error',
  $bref->invoke($bsvc, 4021), []);
exec('rm -rf ' . escapeshellarg($bare));

// ── The doctor ──────────────────────────────────────────────────────────────
echo "\nThe doctor walks the whole chain\n";
$out = []; $rc = 0;
exec(sprintf('DN_DATA_DIR=%s php %s --client 4021 2>&1',
     escapeshellarg($tmp), escapeshellarg($root . '/tools/block_doctor.php')), $out, $rc);
$r = implode("\n", $out);

is_(strpos($r, 'THE ROUTER GATEWAY') !== false, 'it checks the gateway first', substr($r, 0, 200));
is_(strpos($r, 'NOT INSTALLED') !== false,
    'and reports dishnet-data-report missing, which is the whole blocker here');
is_(strpos($r, 'does not speak gRPC') !== false,
    'explaining why that stops everything rather than just naming it');
is_(strpos($r, 'CUSTOMER → DISH') !== false, 'it checks the dish');
is_(strpos($r, 'KIT401723651PG7') !== false, 'naming the kit on record for that client');
is_(strpos($r, 'DISH → ROUTER') !== false, 'and the router lookup');
is_(strpos($r, 'THE VIP GUARD') !== false, 'and who must never be blocked');
is_(strpos($r, 'not in the Sudan CRM') !== false,
    'flagging that the VIP tag id came from the other country');
is_($rc === 1, 'and it exits non-zero while the chain is broken');
is_(strpos($r, 'would NOT block anybody') !== false,
    'saying plainly what that means today', substr($r, -200));

// It blocks nobody. A doctor that could cut off a customer is not a doctor.
is_(strpos($r, 'dr_wifi_test_block') === false, 'it never calls the block endpoint');
$src = (string)file_get_contents($root . '/tools/block_doctor.php');
is_(strpos($src, 'dr_wifi_test_block') === false && strpos($src, 'drPost') === false,
    'and contains no code that could');

exec('rm -rf ' . escapeshellarg($tmp));
echo "\n  {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
