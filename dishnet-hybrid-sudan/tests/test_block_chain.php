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

echo "\nA reserved dish is a hold, not an assignment\n";
// It used to resolve, on the argument that a kit sold and held should still be
// blockable. It should not, and for a plain reason: a reserved kit is in the
// warehouse. There is no router at anybody's premises to act on and no live
// service to suspend, so "blocking" it changed the WiFi on nothing. Ownership
// now means an assignment, and a reservation is deliberately not one.
$u2 = (int)$pdo->query("SELECT id FROM stock_units WHERE serial_number='KIT999999999ZZ9'")->fetchColumn();
$stock->reserve($u2, ['crm_client_id' => 5500, 'client_name' => 'Held Co'], 7, 'Bhavin');
t('a held kit is not blockable', $ref->invoke($svc, 5500), []);
// But the hold is still real and the customer still sees it.
t('while the hold itself survives on the unit',
    (int)$pdo->query("SELECT crm_client_id FROM stock_units WHERE id={$u2}")->fetchColumn(), 5500);
t('and it is still reserved',
    $pdo->query("SELECT status FROM stock_units WHERE id={$u2}")->fetchColumn(), 'reserved');

echo "\nA released dish stops counting\n";
$stock->release($u2, 7, 'Bhavin', 'sale fell through');
t('it is nobody\'s again', $ref->invoke($svc, 5500), []);
t('and the hold is gone from the unit too',
    $pdo->query("SELECT crm_client_id FROM stock_units WHERE id={$u2}")->fetchColumn(), null);

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

// ── An empty router map is a diagnosis, not a number ────────────────────────
// A dead Starlink session does not make discovery fail. It runs, succeeds,
// and writes an EMPTY map — so "0 routers mapped" looks the same whether no
// account can log in, the account has no service lines, or the dishes are on
// customer-supplied routers. Three different problems, one number.
echo "\nAn empty map, and why it is empty\n";
$plugins = sys_get_temp_dir() . '/dn_bd_' . bin2hex(random_bytes(4));
$hyb  = $plugins . '/dishnet-hybrid-sudan';
$drD  = $plugins . '/dishnet-data-report/data';
$hdat = $plugins . '/.dishnet-hybrid-sudan-data';
@mkdir($hyb, 0777, true); @mkdir($drD, 0777, true); @mkdir($hdat, 0777, true);

$doctor = function () use ($root, $hyb, $hdat): array {
    $out = []; $rc = 0;
    exec(sprintf('DN_PLUGIN_ROOT=%s DN_DATA_DIR=%s php %s 2>&1',
         escapeshellarg($hyb), escapeshellarg($hdat),
         escapeshellarg($root . '/tools/block_doctor.php')), $out, $rc);
    return [implode("\n", $out), $rc];
};

// A dead session, and the empty map that follows from it.
file_put_contents($drD . '/dr_accounts.json', json_encode([
    'ACC-1' => ['session_alive' => false, 'needs_manual_reimport' => true],
]));
file_put_contents($drD . '/wifi_router_map.json', json_encode([]));
[$o, $rc] = $doctor();
is_(strpos($o, '0 alive, 1 dead') !== false, 'it counts the dead sessions', substr($o, 0, 600));
is_(strpos($o, 'need a fresh login') !== false, 'and names the account that needs a new cookie');
is_(strpos($o, 'EMPTY — and no Starlink session is alive') !== false,
    'an empty map is reported as empty, not as "not written"');
is_(strpos($o, 'cannot list routers it cannot ask about') !== false,
    'AND BLAMES THE DEAD SESSION — the number alone explains nothing');

// Same empty map, live session, no service lines: a different conclusion.
file_put_contents($drD . '/dr_accounts.json', json_encode([
    'ACC-1' => ['session_alive' => true],
]));
[$o, $rc] = $doctor();
is_(strpos($o, '1 alive, 0 dead') !== false, 'a live session is recognised');
is_(strpos($o, 'no service lines either') !== false,
    'and the same empty map now means something else entirely');
is_(strpos($o, 'customer-supplied routers') !== false,
    'including the case that can never be blocked this way');

// And the case this operation is actually in: the account is fine, the
// service lines exist, and the dishes are still on order from Starlink.
// Nothing is broken — a doctor that calls this a failure is crying wolf on
// the day the business is simply waiting for a delivery.
file_put_contents($drD . '/sl_svc_cache.json', json_encode(
    array_fill_keys(array_map(fn($i) => 'SL-' . $i, range(1, 10)), ['kit_number' => ''])));
[$o, $rc] = $doctor();
is_(strpos($o, '10 service line(s) exist, no router online yet') !== false,
    'ten service lines and no routers is reported as exactly that', substr($o, 0, 900));
is_(strpos($o, 'Nothing is broken') !== false,
    'AND NOT AS A FAULT — the dishes are on order, there is nothing to fix');
is_(strpos($o, 'until its dish is shipped') !== false || strpos($o, 'once its dish is shipped') !== false,
    'saying what has to happen before a router can appear');

// An un-onboarded account alongside a working one is worth a word, not a failure.
file_put_contents($drD . '/dr_accounts.json', json_encode([
    'ACC-1' => ['session_alive' => true],
    'ACC-2' => ['session_alive' => false],
    'ACC-3' => ['session_alive' => false],
]));
[$o, $rc] = $doctor();
is_(strpos($o, '1 alive, 2 dead') !== false, 'accounts without a cookie are counted');
is_(strpos($o, 'cannot be
         seen or blocked until one is imported') !== false
    || strpos($o, 'until one is imported') !== false,
    'and what that costs is said plainly');

// A map that was never written at all is the third state.
@unlink($drD . '/wifi_router_map.json');
[$o, $rc] = $doctor();
is_(strpos($o, 'discovery cron has not completed a run') !== false,
    'and a missing map is told apart from an empty one');

exec('rm -rf ' . escapeshellarg($plugins));

// ── The gateway URL goes through the same override as every other link ──────
// uCRM writes the address it was CONFIGURED with, and behind this install's
// reverse proxy that is crm.dishnetuganda.com:8443 — the port the proxy
// forwards TO, which nothing outside reaches. The override was added for the
// links customers were sent; the block gateway resolved its own URL and never
// learned about it, so it pointed at the dead port too.
echo "\nThe gateway URL, and the port nobody can reach\n";
require_once $root . '/lib/crm_url.php';
file_put_contents($tmp . '/ucrm.json', json_encode([
    'ucrmPublicUrl' => 'https://crm.dishnetuganda.com:8443/crm/',
    'pluginAppKey'  => 'x',
]));

$bridgeUrl = function (array $cfg) use ($tmp): string {
    // The bridge's own resolution, reproduced — asserting on what it produces
    // rather than on the source that produces it.
    $u = json_decode((string)file_get_contents($tmp . '/ucrm.json'), true);
    $base = preg_replace('#/api/v\d+\.\d+/?$#', '', (string)$u['ucrmPublicUrl']);
    $base = rtrim($base, '/');
    if (substr($base, -4) === '/crm') $base = substr($base, 0, -4);
    return dn_with_override(rtrim($base, '/') . '/crm/_plugins/dishnet-data-report/public.php', $cfg);
};

$bad = $bridgeUrl([]);
is_(strpos($bad, ':8443') !== false,
    'without the override it still points at the proxy port', $bad);

$good = $bridgeUrl(['crm_public_url' => 'https://crm.dishnetuganda.com']);
is_(strpos($good, ':8443') === false, 'the override takes the port off', $good);
t('and keeps the path that reaches the plugin', $good,
  'https://crm.dishnetuganda.com/crm/_plugins/dishnet-data-report/public.php');

// One setting, every link — including this one.
is_(strpos((string)file_get_contents($root . '/lib/StarlinkBlockBridge.php'), 'dn_with_override(') !== false,
    'the bridge resolves through that override rather than its own way');
is_(strpos((string)file_get_contents($root . '/tools/block_doctor.php'), 'dn_with_override(') !== false,
    'and so does the doctor, so the two cannot report different URLs');

exec('rm -rf ' . escapeshellarg($tmp));
echo "\n  {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
