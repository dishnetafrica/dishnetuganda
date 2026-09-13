<?php
declare(strict_types=1);
/**
 * test_kit_attribute_intake.php — the Kit Number typed on a service, taken in
 * safely.
 *
 * The operator experience is South Sudan's: type the kit into the service's
 * starlinkDetails field. The authority stays Uganda's: equipment_assignments,
 * where the database defends the binding.
 *
 * What these pin is the half that cannot be seen by looking at the field —
 * that editing a text box can create a binding but can never move one, never
 * release one, and never make a kit belong to two customers.
 */
$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; } }

$root = dirname(__DIR__);
foreach (['StoreInterface','JsonStore','SqliteStore','StockService','EquipmentAssignment',
          'MigrationRunner','FinAudit','KitAttributeIntake'] as $c) require_once $root . '/lib/' . $c . '.php';

const KIT_A = 'KIT404246364BX6';
const KIT_B = 'KIT999888777AA1';
const KIT_C = 'KIT111222333BB2';

/** A uCRM client stub that answers only what the intake asks of it. */
final class FakeCrm
{
    public array $services;
    public function __construct(array $services) { $this->services = $services; }
    public function get(string $path) {
        return strpos($path, 'clients/services') === 0 ? $this->services : null;
    }
    public function isConfigured(): bool { return true; }
}

function svc(int $id, int $client, array $o = []): array {
    return array_merge([
        'id' => $id, 'clientId' => $client, 'status' => 1,
        'name' => 'Starlink Internet Service', 'invoiceLabel' => '',
        'attributes' => [],
    ], $o);
}
function attr(string $key, string $value): array { return ['key' => $key, 'value' => $value]; }

/** A fresh install each time, so no case can lean on another. */
function box(): array {
    $root  = dirname(__DIR__);
    $tmp   = sys_get_temp_dir() . '/dn_intake_' . bin2hex(random_bytes(4));
    @mkdir($tmp, 0777, true);
    $store = SqliteStore::create($tmp);
    $pdo   = $store->getPdo();
    $stock = StockService::fromStore($store, $tmp);
    $stock->ensureTables();
    foreach (MigrationRunner::splitStatements(
            (string)file_get_contents($root . '/migrations/068_equipment_assignments.sql')) as $s) $pdo->exec($s);
    $staff = ['id' => 7, 'name' => 'Tester'];
    $cat   = (int)($stock->saveCategory(['title' => 'Starlink Standard Kit', 'sku' => 'SL-STD',
              'service_type' => 'starlink', 'track_mode' => 'serial'])['id'] ?? 0);
    $mk = function (string $serial) use ($stock, $cat, $staff): int {
        return (int)$stock->createUnit(['category_id' => $cat, 'serial_number' => $serial,
            'purchase_cost' => 1], $staff['id'], $staff['name'])['id'];
    };
    return [$pdo, new EquipmentAssignment($pdo), $mk, $staff];
}

// ── 10 · Reading the field ──────────────────────────────────────────────
echo "\nThe field is read exactly as dishnet-data-report reads it\n";
// If the two plugins disagreed about what the operator typed, one would bind
// a customer the other does not. These spellings are verbatim from its source.
foreach (['starlinkDetails', 'starlink_details', 'Starlink Details',
          'kitNumber', 'kit-no', 'KIT'] as $key) {
    t("accepts the key \"$key\"",
      KitAttributeIntake::kitsFromService(svc(1, 7, ['attributes' => [attr($key, KIT_A)]])), [KIT_A]);
}
t('ignores an unrelated attribute',
  KitAttributeIntake::kitsFromService(svc(1, 7, ['attributes' => [attr('routerSerial', KIT_A)]])), []);

echo "\nMultiple kits on one service, comma separated\n";
t('both are taken',
  KitAttributeIntake::kitsFromService(svc(1, 7, ['attributes' => [attr('starlinkDetails', KIT_A . ', ' . KIT_B)]])),
  [KIT_A, KIT_B]);
t('and a repeat is not counted twice',
  KitAttributeIntake::kitsFromService(svc(1, 7, ['attributes' => [attr('starlinkDetails', KIT_A . ',' . KIT_A)]])),
  [KIT_A]);

echo "\nAn empty attribute falls back to the service name and invoice label\n";
// Operators put the kit in the service name for years before the attribute
// existed, and those customers are still live.
t('from the service name',
  KitAttributeIntake::kitsFromService(svc(1, 7, ['name' => 'Site : ' . KIT_A . ' Starlink'])), [KIT_A]);
t('from the invoice label',
  KitAttributeIntake::kitsFromService(svc(1, 7, ['name' => 'Starlink', 'invoiceLabel' => 'Site ' . KIT_B])), [KIT_B]);
t('an empty attribute is not a value',
  KitAttributeIntake::kitsFromService(svc(1, 7, ['name' => 'Starlink', 'attributes' => [attr('starlinkDetails', '  ')]])), []);
t('and a service with nothing at all yields nothing',
  KitAttributeIntake::kitsFromService(svc(1, 7, ['name' => 'Starlink Residential'])), []);

echo "\nRubbish in the field is not a kit\n";
foreach (['KIT', 'KIT12', 'not a kit', 'SL-DF-16046613-35504-0', '404246364BX6'] as $junk) {
    t("\"$junk\" is refused",
      KitAttributeIntake::kitsFromService(svc(1, 7, ['attributes' => [attr('starlinkDetails', $junk)]])), []);
}
t('but a valid one beside rubbish still lands',
  KitAttributeIntake::kitsFromService(svc(1, 7, ['attributes' => [attr('starlinkDetails', 'junk, ' . KIT_A)]])),
  [KIT_A]);

// ── 1 · A valid new kit ─────────────────────────────────────────────────
echo "\nA valid kit on a service binds it\n";
[$pdo, $ea, $mk, $staff] = box();
$mk(KIT_A);
$in = new KitAttributeIntake($pdo, $ea, new FakeCrm([svc(1, 7, ['attributes' => [attr('starlinkDetails', KIT_A)]])]));
$s = $in->scan();
t('one proposal',       count($s['proposals']), 1);
t('nothing refused',    count($s['refusals']), 0);
t('and nothing written by a scan', $ea->liveAssignments(), []);
$r = $in->apply($s['proposals'], $staff);
t('applying creates it', count($r['created']), 1);
$live = $ea->liveAssignments();
t('one live assignment', count($live), 1);
t('to the right customer', (int)$live[0]['crm_client_id'], 7);
t('on the right service',  (int)$live[0]['crm_service_id'], 1);
t('with the right kit',    strtoupper((string)$live[0]['kit_serial']), KIT_A);

// ── 2 · The same kit again for the same customer ────────────────────────
echo "\nRunning it again changes nothing\n";
$s2 = $in->scan();
t('no proposal',      count($s2['proposals']), 0);
t('no refusal',       count($s2['refusals']), 0);
t('counted as settled', $s2['settled'], 1);
t('still one assignment', count($ea->liveAssignments()), 1);

// ── 3 · A kit already belonging to another customer ─────────────────────
echo "\nA kit held by another customer is never moved\n";
// THE protection. Somebody types a kit that is already someone else's — this
// must refuse, loudly, and leave both customers alone.
$in2 = new KitAttributeIntake($pdo, $ea, new FakeCrm([svc(2, 99, ['attributes' => [attr('starlinkDetails', KIT_A)]])]));
$s3 = $in2->scan();
t('refused',              count($s3['refusals']), 1);
t('named as held elsewhere', $s3['refusals'][0]['reason'], 'held_elsewhere');
is_(strpos((string)$s3['refusals'][0]['detail'], 'client #7') !== false,
    'and it says who actually holds it');
$still = $ea->liveAssignments();
t('the original binding is untouched', (int)$still[0]['crm_client_id'], 7);
t('and no second one was made',        count($still), 1);

// ── 7 · Changing the attribute to a different kit ───────────────────────
echo "\nEditing the field to a different kit does NOT move the assignment\n";
// Customer A has KIT_A on service 1. Somebody mistypes KIT_C into that same
// service. The dish must not move, and KIT_C must not quietly take over.
[$pdo2, $ea2, $mk2, $staff2] = box();
$mk2(KIT_A); $mk2(KIT_C);
$seed = new KitAttributeIntake($pdo2, $ea2, new FakeCrm([svc(1, 7, ['attributes' => [attr('starlinkDetails', KIT_A)]])]));
$seed->apply($seed->scan()['proposals'], $staff2);
t('customer 7 holds it', count($ea2->liveAssignments()), 1);

$typo = new KitAttributeIntake($pdo2, $ea2, new FakeCrm([svc(1, 7, ['attributes' => [attr('starlinkDetails', KIT_C)]])]));
$s4 = $typo->scan();
t('the new value is refused',   count($s4['refusals']), 1);
t('because the service is taken', $s4['refusals'][0]['reason'], 'service_taken');
is_(strpos((string)$s4['refusals'][0]['detail'], KIT_A) !== false,
    'and it names the kit already on that service');
$after = $ea2->liveAssignments();
t('the assignment still exists',  count($after), 1);
t('still the original kit',       strtoupper((string)$after[0]['kit_serial']), KIT_A);
t('still the original customer',  (int)$after[0]['crm_client_id'], 7);

// ── 8 · Deleting the attribute ──────────────────────────────────────────
echo "\nDeleting the field does NOT release the kit\n";
// The attribute is an input. Its absence is not an instruction.
$gone = new KitAttributeIntake($pdo2, $ea2, new FakeCrm([svc(1, 7, ['name' => 'Starlink Residential'])]));
$s5 = $gone->scan();
t('nothing found to act on', $s5['scanned'], 0);
t('nothing proposed',        count($s5['proposals']), 0);
t('nothing refused',         count($s5['refusals']), 0);
$kept = $ea2->liveAssignments();
t('the binding survives',    count($kept), 1);
t('unchanged',               strtoupper((string)$kept[0]['kit_serial']), KIT_A);

// ── 4 · An invalid kit ──────────────────────────────────────────────────
echo "\nA serial nobody received is a typo, not equipment\n";
[$pdo3, $ea3, $mk3, $staff3] = box();
$noStock = new KitAttributeIntake($pdo3, $ea3, new FakeCrm([svc(1, 7, ['attributes' => [attr('starlinkDetails', KIT_B)]])]));
$s6 = $noStock->scan();
t('refused',            count($s6['refusals']), 1);
t('for not being in stock', $s6['refusals'][0]['reason'], 'not_in_stock');
is_(strpos((string)$s6['refusals'][0]['detail'], 'invent') !== false,
    'and says why that matters');
t('nothing written',    count($ea3->liveAssignments()), 0);

// ── 5 · The same kit typed on two services ──────────────────────────────
echo "\nOne kit typed on two customers' services binds once, refuses once\n";
[$pdo4, $ea4, $mk4, $staff4] = box();
$mk4(KIT_A);
$two = new KitAttributeIntake($pdo4, $ea4, new FakeCrm([
    svc(1, 7,  ['attributes' => [attr('starlinkDetails', KIT_A)]]),
    svc(2, 99, ['attributes' => [attr('starlinkDetails', KIT_A)]]),
]));
$s7 = $two->scan();
// Both look bindable on a scan — neither exists yet. The database decides.
$r7 = $two->apply($s7['proposals'], $staff4);
t('exactly one was created', count($r7['created']), 1);
t('and the second was refused at the write', count($r7['failed']), 1);
t('one kit, one customer',   count($ea4->liveAssignments()), 1);

// ── 6 · A service that already runs another kit ─────────────────────────
echo "\nA second kit on one service is refused — suspension must stay unambiguous\n";
[$pdo5, $ea5, $mk5, $staff5] = box();
$u = $mk5(KIT_A); $mk5(KIT_B);
$ea5->assign(['unit_id' => $u, 'crm_client_id' => 7, 'crm_service_id' => 1], $staff5);
$second = new KitAttributeIntake($pdo5, $ea5, new FakeCrm([
    svc(1, 7, ['attributes' => [attr('starlinkDetails', KIT_A . ', ' . KIT_B)]])]));
$s8 = $second->scan();
t('the bound one is settled', $s8['settled'], 1);
t('the other is refused',     count($s8['refusals']), 1);
t('because the service is taken', $s8['refusals'][0]['reason'], 'service_taken');

// ── Services that should not be read at all ─────────────────────────────
echo "\nEnded services are not read\n";
[$pdo6, $ea6, $mk6, $staff6] = box();
$mk6(KIT_A);
$ended = new KitAttributeIntake($pdo6, $ea6, new FakeCrm([
    svc(1, 7, ['status' => 5, 'attributes' => [attr('starlinkDetails', KIT_A)]])]));
t('nothing scanned', $ended->scan()['scanned'], 0);
t('and nothing proposed', count($ended->scan()['proposals']), 0);

echo "\nThe intake never releases, never reassigns\n";
$src = (string)file_get_contents($root . '/lib/KitAttributeIntake.php');
is_(strpos($src, '->release(') === false,      'it cannot release an assignment');
is_(strpos($src, 'replaceUnit') === false,     'nor replace a unit');
is_(preg_match('/UPDATE\s+equipment_assignments/i', $src) === 0,
    'and it never updates the assignment table directly');
is_(strpos($src, '$this->ea->assign(') !== false,
    'every write goes through EquipmentAssignment::assign — the same door assign_kit.php uses');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
