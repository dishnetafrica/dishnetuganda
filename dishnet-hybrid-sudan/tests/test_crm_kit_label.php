<?php
declare(strict_types=1);
/**
 * test_crm_kit_label.php — the label goes one way.
 *
 * South Sudan writes the kit serial into a uCRM service attribute and then
 * READS it to decide who owns the dish. That is why renaming a service, or
 * mistyping a serial into one, could take a customer offline there.
 *
 * Uganda keeps the binding in equipment_assignments and writes the serial to
 * uCRM as a LABEL, so an operator looking at a service can see which dish it
 * is without opening a terminal. The direction is the whole point:
 *
 *     equipment_assignments  ──writes──▶  uCRM service attribute
 *                            ◀─never reads for a decision─
 *
 * And a serial already there that differs from ours is reported, never
 * overwritten by default: a person typed that, and quietly replacing it
 * destroys the only evidence of a disagreement worth investigating.
 */
require_once dirname(__DIR__) . '/lib/StoreInterface.php';
require_once dirname(__DIR__) . '/lib/JsonStore.php';
require_once dirname(__DIR__) . '/lib/SqliteStore.php';
require_once dirname(__DIR__) . '/lib/EquipmentAssignment.php';
require_once dirname(__DIR__) . '/lib/ServicePlan.php';
require_once dirname(__DIR__) . '/lib/CrmKitAttribute.php';

$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; } }

/** A uCRM that records every write, so the direction can be proven. */
final class FakeCrmForLabels {
    public array $services = [];
    public array $attributes = [];
    public array $patched = [];
    public function get(string $path) {
        if ($path === 'custom-attributes') return $this->attributes;
        if (preg_match('#^clients/services/(\d+)$#', $path, $m)) {
            return $this->services[(int)$m[1]] ?? null;
        }
        return null;
    }
    public function patch(string $path, array $p = []) {
        $this->patched[] = ['path' => $path, 'body' => $p];
        return ['ok' => true];
    }
    public function isConfigured(): bool { return true; }
}

$tmp = sys_get_temp_dir() . '/dn_label_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);
$store = SqliteStore::create($tmp);
$ea    = new EquipmentAssignment($store->getPdo());

$mk = function (int $id, int $client, int $service, string $kit): array {
    return ['id' => $id, 'crm_client_id' => $client, 'crm_service_id' => $service,
            'kit_serial' => $kit, 'released_at' => null];
};

$crm = new FakeCrmForLabels();
$crm->attributes = [
    ['id' => 3, 'key' => 'ipAddress',       'attributeType' => 'service'],
    ['id' => 7, 'key' => 'starlinkDetails', 'attributeType' => 'service'],
];
$crm->services = [
    // already labelled, correctly
    1 => ['id' => 1, 'attributes' => [['key' => 'starlinkDetails', 'value' => 'KITAAAA0001']]],
    // no label yet
    2 => ['id' => 2, 'attributes' => []],
    // labelled with somebody else's serial — a person typed that
    3 => ['id' => 3, 'attributes' => [['key' => 'starlinkDetails', 'value' => 'KITWRONG9999']]],
];
$kit = new CrmKitAttribute($crm, $ea);

echo "What uCRM says versus what we say\n";
$rows = $kit->survey([
    $mk(1, 101, 1, 'KITAAAA0001'),
    $mk(2, 102, 2, 'KITBBBB0002'),
    $mk(3, 103, 3, 'KITCCCC0003'),
    ['id' => 4, 'crm_client_id' => 104, 'crm_service_id' => null,
     'kit_serial' => 'KITDDDD0004', 'released_at' => null],
]);
t('the matching one matches',       $rows[0]['state'], 'match');
t('the unlabelled one is missing',  $rows[1]['state'], 'missing');
t('the wrong one differs',          $rows[2]['state'], 'differs');
t('and it says what uCRM holds',    $rows[2]['theirs'], ['KITWRONG9999']);
t('an assignment with no service has nothing to label', $rows[3]['state'], 'no_service');

echo "\nFinding where to write\n";
t('the kit attribute is found among the others', $kit->attributeId(), 7);

echo "\nA dry run writes nothing\n";
$dry = $kit->write($rows, 7, false, false);
t('one label would be written', $dry['written'], 1);
t('three left alone',           $dry['skipped'], 3);
t('and uCRM was not touched',   $crm->patched, []);

echo "\nCommitting writes only what is missing\n";
$w = $kit->write($rows, 7, false, true);
t('one label written',   $w['written'], 1);
t('one PATCH sent',      count($crm->patched), 1);
t('to the right service', $crm->patched[0]['path'], 'clients/services/2');
t('with the right attribute and value',
    $crm->patched[0]['body'],
    ['attributes' => [['customAttributeId' => 7, 'value' => 'KITBBBB0002']]]);
is_(count(array_filter($crm->patched,
        static fn($p) => strpos($p['path'], '/3') !== false)) === 0,
    'and the one a person typed was NOT overwritten');
is_(count(array_filter($w['notes'],
        static fn($n) => strpos($n, 'KITWRONG9999') !== false)) === 1,
    'it is reported instead', implode(' | ', $w['notes']));
is_(count(array_filter($w['notes'],
        static fn($n) => strpos($n, 'no uCRM service') !== false)) === 1,
    'as is the assignment with no service to label');

echo "\n--overwrite replaces it, deliberately\n";
$crm->patched = [];
$o = $kit->write($rows, 7, true, true);
t('now two are written', $o['written'], 2);
$paths = array_column($crm->patched, 'path');
is_(in_array('clients/services/3', $paths, true), 'including the one that differed');

echo "\nWithout the attribute in uCRM it refuses and says how to make it\n";
$bare = new FakeCrmForLabels();
$k2   = new CrmKitAttribute($bare, $ea);
t('no attribute is found', $k2->attributeId(), 0);
$r = $k2->write($rows, 0, false, true);
t('and writing is refused', $r['ok'], false);
is_(strpos((string)$r['error'], 'starlinkDetails') !== false,
    'naming the attribute to create', (string)$r['error']);
is_(strpos((string)$r['error'], 'Custom attributes') !== false, 'and where to create it');

echo "\nNothing here ever reads uCRM to decide ownership\n";
// The guard that keeps the direction one-way. If a future edit makes the
// label authoritative, this fails.
$src = (string)file_get_contents(dirname(__DIR__) . '/lib/CrmKitAttribute.php');
is_(strpos($src, 'kitsOnService') !== false, 'it reads the attribute (to compare)');
is_(!preg_match('/crm_client_id\s*=\s*.*kitsOnService/s', $src),
    'but never assigns ownership from it');
foreach (['lib/StarlinkBlockService.php', 'lib/StarlinkBlockBridge.php'] as $f) {
    $b = (string)file_get_contents(dirname(__DIR__) . '/' . $f);
    is_(strpos($b, 'ServicePlan::kitsOnService') === false
        && strpos($b, 'starlinkDetails') === false,
        basename($f) . ' does not resolve kits from the uCRM attribute');
}

echo "\nThe plan comes from the service the assignment names\n";
// A fresh object: the one above cached service #2 before this label existed,
// which is the cache doing exactly its job.
$crm->services[2]['invoiceLabel'] = 'Site : Kampala KITBBBB0002 Services Plan : DishNet Business 6TB';
$kitP = new CrmKitAttribute($crm, $ea);
$plan = $kitP->planFor($mk(2, 102, 2, 'KITBBBB0002'));
t('the plan is read',    $plan['display'], 'DishNet Business 6TB');
t('with its allowance',  $plan['cap_gb'], 6144.0);
t('and it is known',     $plan['known'], true);
t('an assignment with no service has no plan',
    $kitP->planFor(['id' => 9, 'crm_client_id' => 1, 'crm_service_id' => null, 'kit_serial' => 'K']), null);

echo "\nThe cache is per-instance\n";
// A `static` inside the method would let one object's lookups answer for
// another's — the bug this codebase has now had three times.
$other = new FakeCrmForLabels();
$other->services = [2 => ['id' => 2, 'invoiceLabel' => 'Services Plan : DishNet 500GB']];
$k3 = new CrmKitAttribute($other, $ea);
t('a second object sees its own uCRM', $k3->planFor($mk(2, 1, 2, 'K'))['cap_gb'], 500.0);
t('and the first still sees its own',  $kitP->planFor($mk(2, 1, 2, 'K'))['cap_gb'], 6144.0);

echo "\nThe tool says the same thing its code does\n";
// The first version printed "A plan with no number in it is unlimited" as a
// footer directly beneath a line reporting UNKNOWN for exactly such a plan.
// Prose that contradicts the behaviour above it is worse than no prose: it
// teaches the reader the opposite of what the tool did.
$toolSrc = (string)file_get_contents(dirname(__DIR__) . '/tools/crm_kit_label.php');
is_(strpos($toolSrc, 'A plan with no number in it is unlimited') === false,
    'it no longer states the South Sudan rule it deliberately does not follow');
is_(strpos($toolSrc, 'UNLIMITED IS CLAIMED, NOT INFERRED') !== false,
    'it states the rule it actually applies');
// And the reason given for UNKNOWN has to name the real problem.
is_(strpos($toolSrc, 'PLANS AND USAGE') !== false,
    '--plans gets a header about plans, not about writing labels');
is_(strpos($toolSrc, 'the service names no plan at all') !== false
    && strpos($toolSrc, 'names no allowance') !== false,
    'an uninformative plan name is reported as such, not as a missing one');

exec('rm -rf ' . escapeshellarg($tmp));
echo "\n  {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
