<?php
declare(strict_types=1);
/**
 * test_equipment_assignment.php — Customer A's kit is never Customer B's.
 *
 * Ownership used to have three answers depending on who asked. The strong one
 * was stock_units.crm_client_id. The second was a file from a plugin that is
 * not installed. The third — used by the code that decides whether a paying
 * customer keeps their internet — was a regular expression over a uCRM service
 * NAME. Rename a service and a customer stopped being blockable; mistype a
 * serial into a service title and a different customer's dish went dark.
 *
 * The eleven scenarios below are the ones that were asked for, and they are
 * the ones that matter:
 *
 *   A→Kit A, B→Kit B, and each resolves back to itself
 *   a second live assignment on one kit is refused
 *   a released kit has no owner until it is properly reassigned
 *   suspending service A blocks kit A and leaves kit B alone
 *
 * Plus the guarantees the DATABASE enforces, because application checks are
 * only as good as the next tool somebody writes at 2am.
 */
require_once dirname(__DIR__) . '/lib/StoreInterface.php';
require_once dirname(__DIR__) . '/lib/JsonStore.php';
require_once dirname(__DIR__) . '/lib/SqliteStore.php';
require_once dirname(__DIR__) . '/lib/StockService.php';
require_once dirname(__DIR__) . '/lib/EquipmentAssignment.php';
require_once dirname(__DIR__) . '/lib/MigrationRunner.php';
require_once dirname(__DIR__) . '/lib/FinAudit.php';

$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; } }

$root = dirname(__DIR__);
$tmp  = sys_get_temp_dir() . '/dn_bind_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);
$store = SqliteStore::create($tmp);
$pdo   = $store->getPdo();
$stock = StockService::fromStore($store, $tmp);
$stock->ensureTables();
$ea    = new EquipmentAssignment($pdo);
$staff = ['id' => 7, 'name' => 'Bhavin Madlani'];

// The migration is the schema under test: the guarantees live in it.
foreach (MigrationRunner::splitStatements(
        (string)file_get_contents($root . '/migrations/068_equipment_assignments.sql')) as $s) {
    $pdo->exec($s);
}

$cat = (int)($stock->saveCategory(['title' => 'Starlink Standard Kit', 'sku' => 'SL-STD',
    'service_type' => 'starlink', 'track_mode' => 'serial'])['id'] ?? 0);
$mkUnit = function (string $serial) use ($stock, $cat, $staff): int {
    return (int)$stock->createUnit(['category_id' => $cat, 'serial_number' => $serial,
        'purchase_cost' => 1477778], $staff['id'], $staff['name'])['id'];
};
$kitA = $mkUnit('KITAAAAAAAA0001');
$kitB = $mkUnit('KITBBBBBBBB0002');

// ── 1 & 2: two customers, two kits ──────────────────────────────────────────
echo "Customer A gets Kit A, Customer B gets Kit B\n";
$A = $ea->assign(['unit_id' => $kitA, 'crm_client_id' => 101, 'crm_service_id' => 500,
    'starlink_account' => 'ACC-DF-1', 'starlink_service_line' => 'SL-1',
    'terminal_id' => 'ut-1', 'router_id' => 'r-1'], $staff);
$B = $ea->assign(['unit_id' => $kitB, 'crm_client_id' => 202, 'crm_service_id' => 501,
    'starlink_account' => 'ACC-DF-2', 'starlink_service_line' => 'SL-2',
    'terminal_id' => 'ut-2', 'router_id' => 'Router-r-2'], $staff);
t('A is assigned', $A['ok'], true);
t('B is assigned', $B['ok'], true);
t('the serial is copied from the unit, not typed',
    $A['assignment']['kit_serial'], 'KITAAAAAAAA0001');
t('the Router- prefix is normalised away so one router is one string',
    $B['assignment']['router_id'], 'R-2');

// ── 5 & 6: CRM → kit ────────────────────────────────────────────────────────
echo "\nCRM → kit\n";
t('customer A holds one kit',  $ea->kitSerialsForClient(101), ['KITAAAAAAAA0001']);
t('customer B holds the other', $ea->kitSerialsForClient(202), ['KITBBBBBBBB0002']);
t('service 500 runs on kit A',  $ea->kitSerialForService(500), 'KITAAAAAAAA0001');
t('service 501 runs on kit B',  $ea->kitSerialForService(501), 'KITBBBBBBBB0002');
t('a customer who has nothing gets nothing', $ea->kitSerialsForClient(999), []);
t('and a service that has nothing gets nothing', $ea->kitSerialForService(999), '');

// ── 3 & 4: kit → CRM ────────────────────────────────────────────────────────
echo "\nKit → CRM\n";
$rA = $ea->resolve(['kit_serial' => 'KITAAAAAAAA0001']);
t('kit A resolves',            $rA['assigned'], true);
t('to customer A',             $rA['crm_client_id'], 101);
t('and names the service',     $rA['crm_service_id'], 500);
t('and the assignment',        $rA['assignment_id'], (int)$A['id']);
t('kit B resolves to customer B',
    $ea->resolve(['kit_serial' => 'KITBBBBBBBB0002'])['crm_client_id'], 202);
t('a lower-case serial still matches — identifiers are normalised once',
    $ea->resolve(['kit_serial' => 'kitaaaaaaaa0001'])['crm_client_id'], 101);
t('by terminal',   $ea->resolve(['terminal_id' => 'UT-1'])['crm_client_id'], 101);
t('by router',     $ea->resolve(['router_id' => 'r-1'])['crm_client_id'], 101);
t('by router with the prefix the data plugin uses',
    $ea->resolve(['router_id' => 'Router-r-2'])['crm_client_id'], 202);
t('by service line', $ea->resolve(['service_line' => 'SL-2'])['crm_client_id'], 202);
t('matched_on says which key answered', $ea->resolve(['router_id' => 'r-1'])['matched_on'], 'router_id');

echo "\nAnd it never guesses\n";
$none = $ea->resolve(['kit_serial' => 'KITNOTOURSXX999']);
t('an unknown kit is not assigned', $none['assigned'], false);
is_(strpos((string)$none['reason'], 'No live equipment assignment') !== false,
    'and says so plainly', (string)$none['reason']);
t('nothing at all resolves to nothing', $ea->resolve([])['assigned'], false);
// A kit serial belonging to A with a router belonging to B is a contradiction.
// Picking one would be guessing with extra steps.
$mixed = $ea->resolve(['kit_serial' => 'KITAAAAAAAA0001', 'router_id' => 'r-2']);
t('contradictory identifiers resolve to nothing', $mixed['assigned'], false);
is_(strpos((string)$mixed['reason'], 'disagree') !== false,
    'and it says they disagree rather than choosing', (string)$mixed['reason']);
// The old behaviour, gone: a service NAME is not an identifier.
t('a customer name is not an identifier',
    $ea->resolve(['kit_serial' => 'Family Shoppers'])['assigned'], false);
t('nor is a service title with a kit in it',
    $ea->resolve(['kit_serial' => 'Starlink Business KITAAAAAAAA0001'])['assigned'], false);

// ── 7: duplicate assignment refused ─────────────────────────────────────────
echo "\nA kit can only have one owner\n";
$dup = $ea->assign(['unit_id' => $kitA, 'crm_client_id' => 202], $staff);
t('a second live assignment is refused', $dup['ok'], false);
is_(strpos((string)$dup['error'], 'already assigned to client #101') !== false,
    'and it names who has it', (string)$dup['error']);
t('kit A still belongs to A', $ea->resolve(['kit_serial' => 'KITAAAAAAAA0001'])['crm_client_id'], 101);

$dupSvc = $ea->assign(['unit_id' => $mkUnit('KITCCCCCCCC0003'), 'crm_client_id' => 101,
    'crm_service_id' => 500], $staff);
t('one uCRM service cannot run two kits', $dupSvc['ok'], false);
is_(strpos((string)$dupSvc['error'], 'already runs on unit') !== false, 'and says which');

$kitD = $mkUnit('KITDDDDDDDD0004');
$dupRouter = $ea->assign(['unit_id' => $kitD, 'crm_client_id' => 303, 'router_id' => 'r-1'], $staff);
t('a router cannot serve two customers', $dupRouter['ok'], false);
is_(strpos((string)$dupRouter['error'], 'already live on assignment') !== false,
    'and it names the clash', (string)$dupRouter['error']);

echo "\nA zero is not a customer\n";
// The exact defect this class was built to close.
$zero = $ea->assign(['unit_id' => $kitD, 'crm_client_id' => 0], $staff);
t('an assignment with no client id is refused', $zero['ok'], false);
is_(strpos((string)$zero['error'], 'not an identity') !== false,
    'because a name is not an identity', (string)$zero['error']);

// ── The database enforces it too ────────────────────────────────────────────
echo "\nThe database enforces it, not just the code\n";
$raw = "INSERT INTO equipment_assignments
        (unit_id, crm_client_id, assigned_at, created_at, kit_serial)
        VALUES ({$kitA}, 909, datetime('now'), datetime('now'), 'KITXXXX')";
$threw = false;
try { $pdo->exec($raw); } catch (\Throwable $e) { $threw = true; }
is_($threw, 'a raw INSERT bypassing the service still cannot double-assign a unit');

$live = $ea->activeForUnit($kitA);
$threw = false;
try {
    $pdo->exec("DELETE FROM equipment_assignments WHERE id = " . (int)$live['id']);
} catch (\Throwable $e) { $threw = true; }
is_($threw, 'and an assignment cannot be deleted');
t('it is still there', (int)$pdo->query("SELECT COUNT(*) FROM equipment_assignments
    WHERE id = " . (int)$live['id'])->fetchColumn(), 1);

// ── 8 & 9: release, then reassign ───────────────────────────────────────────
echo "\nRelease Kit A, and only then may B have it\n";
$rel = $ea->release($kitA, ['reason' => 'customer moved away'], $staff);
t('it releases', $rel['ok'], true);
t('kit A now has no owner', $ea->resolve(['kit_serial' => 'KITAAAAAAAA0001'])['assigned'], false);
t('customer A holds nothing', $ea->kitSerialsForClient(101), []);
t('and the unit row no longer claims a customer',
    $pdo->query("SELECT crm_client_id FROM stock_units WHERE id = {$kitA}")->fetchColumn(), null);

$hist = $ea->historyForUnit($kitA);
t('the history survives', count($hist), 1);
t('with who had it',      (int)$hist[0]['crm_client_id'], 101);
is_((string)$hist[0]['released_at'] !== '', 'and when they stopped');
t('and why',              $hist[0]['released_reason'], 'customer moved away');

$threw = false;
try {
    $pdo->exec("UPDATE equipment_assignments SET crm_client_id = 202 WHERE id = " . (int)$hist[0]['id']);
} catch (\Throwable $e) { $threw = true; }
is_($threw, 'a released assignment is history and cannot be rewritten');
t('it still says client 101',
    (int)$pdo->query("SELECT crm_client_id FROM equipment_assignments
        WHERE id = " . (int)$hist[0]['id'])->fetchColumn(), 101);

$now = $ea->assign(['unit_id' => $kitA, 'crm_client_id' => 202, 'crm_service_id' => 777,
    'router_id' => 'r-1'], $staff);
t('now B may have it',   $now['ok'], true);
t('and it resolves to B', $ea->resolve(['kit_serial' => 'KITAAAAAAAA0001'])['crm_client_id'], 202);
t('while A keeps the history, not the kit', count($ea->historyForClient(101)), 1);
t('and A currently holds nothing',          $ea->forClient(101), []);

// ── Replacement keeps the chain ─────────────────────────────────────────────
echo "\nReplacing a kit keeps the chain\n";
$kitE = $mkUnit('KITEEEEEEEE0005');
$rep = $ea->replaceUnit($kitB, $kitE, ['reason' => 'dish damaged in a storm'], $staff);
t('it replaces', $rep['ok'], true);
$old = $ea->get((int)$rep['released']);
t('the old assignment is released',       $old['released_reason'], 'dish damaged in a storm');
t('and points at what took over',         (int)$old['replaced_by_unit_id'], $kitE);
$new = $ea->get((int)$rep['id']);
t('the new one has the same customer',    (int)$new['crm_client_id'], 202);
t('and the same service',                 (int)$new['crm_service_id'], 501);
t('and inherits the Starlink account',    $new['starlink_account'], 'ACC-DF-2');
t('the new kit resolves to that customer',
    $ea->resolve(['kit_serial' => 'KITEEEEEEEE0005'])['crm_client_id'], 202);
t('the old kit resolves to nobody',
    $ea->resolve(['kit_serial' => 'KITBBBBBBBB0002'])['assigned'], false);

// ── 10 & 11: suspend one service, not the other ─────────────────────────────
echo "\nSuspending one service leaves the other alone\n";
// One customer, two services, two kits — the case stock_units alone could
// never answer, because it only ever knew the customer.
$tmp2 = sys_get_temp_dir() . '/dn_bind2_' . bin2hex(random_bytes(4));
@mkdir($tmp2, 0777, true);
$s2 = SqliteStore::create($tmp2); $p2 = $s2->getPdo();
$st2 = StockService::fromStore($s2, $tmp2); $st2->ensureTables();
foreach (MigrationRunner::splitStatements(
        (string)file_get_contents($root . '/migrations/068_equipment_assignments.sql')) as $s) {
    $p2->exec($s);
}
$e2 = new EquipmentAssignment($p2);
$c2 = (int)($st2->saveCategory(['title' => 'Kit', 'sku' => 'K', 'service_type' => 'starlink',
    'track_mode' => 'serial'])['id'] ?? 0);
$one = (int)$st2->createUnit(['category_id' => $c2, 'serial_number' => 'KITONE0001'], 1, 'x')['id'];
$two = (int)$st2->createUnit(['category_id' => $c2, 'serial_number' => 'KITTWO0002'], 1, 'x')['id'];
$e2->assign(['unit_id' => $one, 'crm_client_id' => 123, 'crm_service_id' => 500,
             'router_id' => 'router-one'], $staff);
$e2->assign(['unit_id' => $two, 'crm_client_id' => 123, 'crm_service_id' => 501,
             'router_id' => 'router-two'], $staff);
t('the customer holds two kits', count($e2->forClient(123)), 2);
t('service 500 is the first',  $e2->kitSerialForService(500), 'KITONE0001');
t('service 501 is the second', $e2->kitSerialForService(501), 'KITTWO0002');
is_($e2->kitSerialForService(500) !== $e2->kitSerialForService(501),
    'and suspending one can never touch the other');
$resolved500 = $e2->resolve(['router_id' => 'router-one']);
$resolved501 = $e2->resolve(['router_id' => 'router-two']);
t('each router resolves to its own service', $resolved500['crm_service_id'], 500);
t('and the other to its own',                $resolved501['crm_service_id'], 501);
t('while both belong to the same customer',
    [$resolved500['crm_client_id'], $resolved501['crm_client_id']], [123, 123]);
exec('rm -rf ' . escapeshellarg($tmp2));

// ── install() refuses a nameless customer ───────────────────────────────────
echo "\nInstalling needs an id, not a name\n";
$kitF = $mkUnit('KITFFFFFFFF0006');
$threw = '';
try {
    $stock->install($kitF, ['crm_client_id' => 0, 'client_name' => 'Family Shoppers'], 7, 'Bhavin');
} catch (\Throwable $e) { $threw = $e->getMessage(); }
is_(strpos($threw, 'uCRM client id') !== false,
    'a zero client id is refused at install', $threw);
t('and the unit stays on the shelf',
    $pdo->query("SELECT status FROM stock_units WHERE id = {$kitF}")->fetchColumn(), 'in_stock');
t('with no assignment invented for it', $ea->activeForUnit($kitF), null);

$u = $stock->install($kitF, ['crm_client_id' => 404, 'crm_service_id' => 900,
    'client_name' => 'Real Customer Ltd'], 7, 'Bhavin');
t('a real id installs',              $u['status'], 'installed');
t('and creates the assignment',      (int)$ea->activeForUnit($kitF)['crm_client_id'], 404);
t('mirrored onto the unit',          (int)$u['crm_client_id'], 404);
t('service included',                (int)$u['crm_service_id'], 900);
t('and the kit resolves back',
    $ea->resolve(['kit_serial' => 'KITFFFFFFFF0006'])['crm_client_id'], 404);

$back = $stock->returnUnit($kitF, 7, 'Bhavin', 'good', 'end of contract');
t('returning releases it',    $back['status'], 'returned');
t('the unit forgets the customer', $back['crm_client_id'], null);
t('the assignment remembers',  count($ea->historyForUnit($kitF)), 1);
t('and the kit resolves to nobody',
    $ea->resolve(['kit_serial' => 'KITFFFFFFFF0006'])['assigned'], false);

// ── The regex is gone from production ───────────────────────────────────────
echo "\nThe service-name regex is gone\n";
$block = (string)file_get_contents($root . '/lib/StarlinkBlockService.php');
is_(strpos($block, "clients/{\$clientId}/services") === false,
    'the blocking path no longer reads service names at all');
is_(!preg_match('/preg_match_all\(\s*\$regex/', $block),
    'and runs no regex over them');
is_(strpos($block, 'EquipmentAssignment') !== false,
    'it asks the assignment instead');
$hits = [];
foreach (glob($root . '/lib/*.php') as $f) {
    $src = (string)file_get_contents($f);
    if (preg_match('/KIT\[A-Z0-9\]\{?\d*,?\}?/', $src) && strpos($src, 'EquipmentAssignment') === false) {
        $hits[] = basename($f);
    }
}
is_($hits === [], 'and no other library still mines a KIT serial out of text',
    implode(', ', $hits));

// ── Audit ───────────────────────────────────────────────────────────────────
echo "\nEvery assignment leaves a trail\n";
$h = FinAudit::history($pdo, 'equipment_assignment', (int)$hist[0]['id']);
is_(count($h) >= 2, 'created and released are both recorded', 'got ' . count($h));
t('by whoever did it', $h[0]['actor_name'], 'Bhavin Madlani');

// ── The tools ───────────────────────────────────────────────────────────────
echo "\nThe tools\n";
foreach (['binding_doctor', 'binding_trace'] as $tool) {
    is_(is_file($root . "/tools/{$tool}.php"), "tools/{$tool}.php exists");
}
$env = 'DN_DATA_DIR=' . escapeshellarg($tmp) . ' ';
$o = []; exec($env . 'php ' . escapeshellarg($root . '/tools/binding_doctor.php') . ' 2>&1', $o, $c);
$txt = implode("\n", $o);
t('the doctor runs clean', $c, 0);
is_(strpos($txt, 'the database enforces one live owner') !== false,
    'and confirms the database guarantees', $txt);
is_(strpos($txt, 'LIVE ASSIGNMENTS') !== false, 'and lists what is bound');

$o2 = []; exec($env . 'php ' . escapeshellarg($root . '/tools/binding_trace.php')
    . ' --kit KITAAAAAAAA0001 2>&1', $o2, $c2);
$txt2 = implode("\n", $o2);
t('the trace closes the round trip', $c2, 0);
is_(strpos($txt2, 'Round trip closed on the same customer') !== false, 'and says so', $txt2);
is_(strpos($txt2, '#202') !== false, 'on the customer that owns it now');

$o3 = []; exec($env . 'php ' . escapeshellarg($root . '/tools/binding_trace.php')
    . ' --kit KITNOTOURSXX999 2>&1', $o3, $c3);
$txt3 = implode("\n", $o3);
t('an unassigned kit fails rather than guessing', $c3, 1);
is_(strpos($txt3, 'not assigned to a CRM customer') !== false, 'saying exactly that', $txt3);

$o4 = []; exec($env . 'php ' . escapeshellarg($root . '/tools/binding_trace.php') . ' --client X 2>&1', $o4, $c4);
t('a non-numeric client is refused, not read as zero', $c4, 2);

echo "\nThe Starlink account survives being installed\n";
// It is recorded when the kit is received — which of DishNet's four accounts
// supplied it. Installing used to write the assignment's empty value straight
// over it, so the answer was lost at exactly the moment it started to matter.
$kitH = (int)$stock->createUnit(['category_id' => $cat, 'serial_number' => 'KITHHHHHHHH0008',
    'starlink_account' => 'ACC-DF-15757047-82765-60'], 7, 'Bhavin')['id'];
t('received with its account',
    $pdo->query("SELECT starlink_account FROM stock_units WHERE id={$kitH}")->fetchColumn(),
    'ACC-DF-15757047-82765-60');
$stock->install($kitH, ['crm_client_id' => 55, 'client_name' => 'Someone'], 7, 'Bhavin');
t('the assignment inherits it rather than asking again',
    $ea->activeForUnit($kitH)['starlink_account'], 'ACC-DF-15757047-82765-60');
t('and the unit still has it',
    $pdo->query("SELECT starlink_account FROM stock_units WHERE id={$kitH}")->fetchColumn(),
    'ACC-DF-15757047-82765-60');
// An account given at install still wins over the one on the unit.
$kitI = (int)$stock->createUnit(['category_id' => $cat, 'serial_number' => 'KITIIIIIIII0009',
    'starlink_account' => 'ACC-OLD'], 7, 'Bhavin')['id'];
$stock->install($kitI, ['crm_client_id' => 56, 'client_name' => 'Other',
    'starlink_account' => 'ACC-NEW'], 7, 'Bhavin');
t('an account given at install wins', $ea->activeForUnit($kitI)['starlink_account'], 'ACC-NEW');

echo "\nAssigning a kit from the command line\n";
$assign = $root . '/tools/assign_kit.php';
is_(is_file($assign), 'tools/assign_kit.php exists');
$run = function (string $flags) use ($env, $assign): array {
    $o = []; $c = 0;
    exec($env . 'php ' . escapeshellarg($assign) . ' ' . $flags . ' 2>&1', $o, $c);
    return [implode("\n", $o), $c];
};

// A serial nobody received is a typo, not equipment. Assigning one would
// invent inventory — and then a customer would appear to own a dish that
// does not exist anywhere.
[$tN, $cN] = $run('--kit KITNEVERRECEIVED --client 7');
t('a serial that is not in stock is refused', $cN, 1);
is_(strpos($tN, 'is not in stock') !== false, 'and says so', $tN);
is_(strpos($tN, 'invent inventory') !== false, 'and why that matters');

// A mistyped serial is the likeliest cause, so near misses are offered.
[$tM, $cM] = $run('--kit KITAAAAXXXXXXX --client 7');
is_(strpos($tM, 'start the same way') !== false, 'near-miss serials are listed', $tM);
is_(strpos($tM, 'KITAAAAAAAA0001') !== false, 'naming the one it probably meant');

$kitG = $mkUnit('KITGGGGGGGG0007');
[$tX, $cX] = $run('--kit KITGGGGGGGG0007 --client X');
t('a non-numeric client is refused, not read as zero', $cX, 2);
is_(strpos($tX, 'uCRM client NUMBER') !== false, 'and it says where to find the number', $tX);

[$tD, $cD] = $run('--kit KITGGGGGGGG0007 --client 7');
t('a dry run exits clean', $cD, 0);
is_(strpos($tD, 'dry run — nothing written') !== false, 'and writes nothing', $tD);
t('truly nothing', $ea->activeForUnit($kitG), null);

[$tC, $cC] = $run('--kit KITGGGGGGGG0007 --client 7 --service 42 --commit');
t('committing assigns it', $cC, 0);
$mine = $ea->activeForUnit($kitG);
t('to that customer',  (int)$mine['crm_client_id'], 7);
t('on that service',   (int)$mine['crm_service_id'], 42);
t('and the kit resolves back to them',
    $ea->resolve(['kit_serial' => 'KITGGGGGGGG0007'])['crm_client_id'], 7);
t('the unit is installed',
    $pdo->query("SELECT status FROM stock_units WHERE id={$kitG}")->fetchColumn(), 'installed');

// The second assignment is the dangerous one: silently re-pointing a live kit
// is how a customer loses their connection with nothing recording why.
[$tR, $cR] = $run('--kit KITGGGGGGGG0007 --client 999 --commit');
t('a kit already held is refused', $cR, 1);
is_(strpos($tR, 'Already assigned to client #7') !== false, 'naming who has it', $tR);
t('and it still belongs to 7',
    $ea->resolve(['kit_serial' => 'KITGGGGGGGG0007'])['crm_client_id'], 7);

[$tU, $cU] = $run('--wat');
t('an unknown option is refused', $cU, 2);

exec('rm -rf ' . escapeshellarg($tmp));
echo "\n  {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
