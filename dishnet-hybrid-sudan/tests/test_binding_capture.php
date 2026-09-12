<?php
declare(strict_types=1);
/**
 * test_binding_capture.php — the identifiers get captured, and land right.
 *
 * equipment_assignments has had columns and unique partial indexes for
 * starlink_account, starlink_service_line, terminal_id and router_id since
 * migration 068, and StockService::install() has always accepted all four.
 * No screen ever sent one. So every binding was created with a kit serial and
 * nothing else, which meant usage could only ever join on the serial — the
 * least durable identifier of the set, since a warranty swap changes it and
 * leaves the dish's own terminal ID untouched.
 *
 * The install screens now collect them. What these tests pin is the part that
 * cannot be seen by looking at a form: that a value typed into the wrong box
 * is refused rather than stored, and that a completed binding resolves from
 * any identifier back to exactly one customer.
 */
$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; } }

$root = dirname(__DIR__);
foreach (['StoreInterface','JsonStore','SqliteStore','StockService','EquipmentAssignment',
          'MigrationRunner','FinAudit'] as $c) require_once $root . '/lib/' . $c . '.php';

$tmp = sys_get_temp_dir() . '/dn_bindcap_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);
$store = SqliteStore::create($tmp);
$pdo   = $store->getPdo();
$stock = StockService::fromStore($store, $tmp);
$stock->ensureTables();
foreach (MigrationRunner::splitStatements(
        (string)file_get_contents($root . '/migrations/068_equipment_assignments.sql')) as $s) $pdo->exec($s);
$ea    = new EquipmentAssignment($pdo);
$staff = ['id' => 7, 'name' => 'Tester'];
$cat   = (int)($stock->saveCategory(['title' => 'Starlink Standard Kit', 'sku' => 'SL-STD',
          'service_type' => 'starlink', 'track_mode' => 'serial'])['id'] ?? 0);
$mkUnit = function (string $serial) use ($stock, $cat, $staff): int {
    return (int)$stock->createUnit(['category_id' => $cat, 'serial_number' => $serial,
        'purchase_cost' => 1], $staff['id'], $staff['name'])['id'];
};

// Real shapes, from DishNet's own Starlink account.
$KIT  = 'KIT404246364BX6';
$TERM = 'ut01301694-01e07c1c-59d52912';
$LINE = 'SL-DF-16046613-35504-0';
$ACC  = 'ACC-DF-15973474-59163-60';

echo "\nEach identifier is recognised by shape\n";
is_(EquipmentAssignment::shapeOf($KIT)  === 'kit_serial',            'a kit serial');
is_(EquipmentAssignment::shapeOf($TERM) === 'terminal_id',           'a terminal ID');
is_(EquipmentAssignment::shapeOf($LINE) === 'starlink_service_line', 'a service line');
is_(EquipmentAssignment::shapeOf($ACC)  === 'starlink_account',      'an account number');
is_(EquipmentAssignment::shapeOf('a-router-id-we-do-not-know') === '',
    'and anything unfamiliar is UNKNOWN, not wrong',
    'Starlink can change formats; a technician holding a real kit must never be blocked');
is_(EquipmentAssignment::shapeOf('') === '', 'empty is unknown too');

echo "\nA value in the wrong box is refused — the indexes cannot catch this\n";
$wrong = EquipmentAssignment::misplaced(['terminal_id' => $LINE]);
is_(count($wrong) === 1, 'a service line typed as a terminal ID is caught');
is_(strpos($wrong[0], 'service line') !== false, 'and the message names what it looks like', $wrong[0] ?? '');
is_(EquipmentAssignment::misplaced(['kit_serial' => $KIT, 'terminal_id' => $TERM,
    'starlink_service_line' => $LINE, 'starlink_account' => $ACC]) === [],
    'a correctly-filled set passes');
is_(EquipmentAssignment::misplaced(['terminal_id' => 'something-unrecognised']) === [],
    'an unrecognised value is allowed through — unknown is not wrong');

echo "\nassign() refuses it rather than storing a binding that matches nothing\n";
$u1 = $mkUnit($KIT);
$bad = $ea->assign(['unit_id' => $u1, 'crm_client_id' => 101,
                    'terminal_id' => $LINE], $staff);
is_(empty($bad['ok']), 'the assignment is rejected');
is_(strpos((string)($bad['error'] ?? ''), 'looks like a') !== false,
    'with a message a technician can act on', (string)($bad['error'] ?? ''));
is_($ea->activeForUnit($u1) === null, 'and nothing was written');

echo "\nA complete binding resolves from ANY identifier to one customer\n";
$ok = $ea->assign(['unit_id' => $u1, 'crm_client_id' => 101, 'crm_service_id' => 555,
                   'terminal_id' => $TERM, 'router_id' => 'Router-ABC123',
                   'starlink_service_line' => $LINE, 'starlink_account' => $ACC], $staff);
is_(!empty($ok['ok']), 'the assignment is created', (string)($ok['error'] ?? ''));
foreach ([['terminal_id', $TERM], ['starlink_service_line', $LINE], ['router_id', 'ABC123']] as [$k, $v]) {
    $r = $ea->resolve([$k => $v]);
    is_(!empty($r['assigned']) && (int)$r['crm_client_id'] === 101, "resolves by {$k}");
}
$r = $ea->resolve(['router_id' => 'Router-ABC123']);
is_(!empty($r['assigned']), 'and the Router- prefix is accepted either way');

echo "\nThe service binding now actually engages\n";
$a = $ea->activeForUnit($u1);
is_((int)($a['crm_service_id'] ?? 0) === 555, 'crm_service_id is stored, not NULL');
$u2 = $mkUnit('KIT999999999ZZ9');
$dup = $ea->assign(['unit_id' => $u2, 'crm_client_id' => 102, 'crm_service_id' => 555], $staff);
is_(empty($dup['ok']), 'a second kit on the same service is refused');
is_(strpos((string)($dup['error'] ?? ''), '555') !== false, 'naming the service', (string)($dup['error'] ?? ''));

echo "\naddIdentifiers completes a partial binding, with the same guard\n";
$u3 = $mkUnit('KIT111111111AA1');
$p  = $ea->assign(['unit_id' => $u3, 'crm_client_id' => 103], $staff);
$aid = (int)($p['assignment']['id'] ?? $p['id'] ?? 0);
if ($aid === 0) { $row = $ea->activeForUnit($u3); $aid = (int)($row['id'] ?? 0); }
is_($aid > 0, 'a partial binding is allowed — an identifier we lack is empty');
$late = $ea->addIdentifiers($aid, ['terminal_id' => 'ut99999999-aaaa-bbbb'], $staff);
is_(!empty($late['ok']), 'the terminal ID can be added later', (string)($late['error'] ?? ''));
$wrongLate = $ea->addIdentifiers($aid, ['starlink_service_line' => 'ut77777777-cccc-dddd'], $staff);
is_(empty($wrongLate['ok']), 'and a wrong-box value is refused here too');

echo "\nEvery install screen sends what the schema has always had room for\n";
foreach (['tabs/admin/stock_inout.php', 'tabs/support/stock_hub.php',
          'tabs/support/my_equipment.php'] as $f) {
    $src = file_get_contents($root . '/' . $f);
    $n = basename($f);
    foreach (['crm_service_id', 'terminal_id:', 'router_id:', 'starlink_service_line:'] as $field) {
        is_(strpos($src, $field) !== false, "{$n} sends {$field}");
    }
}

@array_map('unlink', glob($tmp . '/*') ?: []); @rmdir($tmp);
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
