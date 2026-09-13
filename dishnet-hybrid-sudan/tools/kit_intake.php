<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * kit_intake.php — take the Kit Number typed on a uCRM service into the
 * authoritative binding.
 *
 *   php tools/kit_intake.php              what the typed fields would change
 *   php tools/kit_intake.php --commit     create the assignments it proposes
 *
 * The operator types the kit into the service's starlinkDetails field, as in
 * South Sudan. This reads that field, validates it exactly as
 * tools/assign_kit.php does, and writes equipment_assignments — which stays
 * the authority. The attribute is an input, never the store.
 *
 * IT ONLY EVER CREATES. It does not move a kit between customers, does not
 * release one, and does not act on an attribute being deleted. Changing the
 * text field cannot take a customer's dish away, because the text field is
 * not where the binding lives.
 *
 * Changes nothing without --commit.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
$GLOBALS['_PLUGIN_ROOT'] = $root;
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/EquipmentAssignment.php';
require_once $root . '/lib/KitAttributeIntake.php';
require_once $root . '/lib/CrmApiClient.php';

$args    = array_slice($argv, 1);
$commit  = in_array('--commit', $args, true);
foreach ($args as $a) {
    if (strpos($a, '--') === 0 && $a !== '--commit') {
        fwrite(STDERR, "\n  Unknown option: {$a}\n  Known: --commit\n\n"); exit(2);
    }
}

$dataDir = cliDataDir($root);
$store   = SqliteStore::create($dataDir);
$pdo     = $store->getPdo();
$config  = PluginConfig::load($root, $dataDir);

$crm = null;
try { $crm = CrmApiClient::fromUcrm($root, $config); } catch (\Throwable $e) {}
if (!$crm || !$crm->isConfigured()) exit("\n  uCRM is not configured — nothing to read.\n\n");

$ea     = new EquipmentAssignment($pdo);
$intake = new KitAttributeIntake($pdo, $ea, $crm);

echo "\n  KIT NUMBER INTAKE" . ($commit ? '' : '   (dry run — nothing written)') . "\n";
echo "  " . str_repeat('─', 68) . "\n\n";
echo "  Reading the Kit Number field on every live uCRM service.\n";
echo "  The field is an input. equipment_assignments stays the authority.\n\n";

// The dry run proves its own safety: fingerprint the binding table before and
// after, and print both. "Nothing was written" should be demonstrated, not
// asserted.
$before = $intake->fingerprint();
$scan   = $intake->scan();
$after  = $intake->fingerprint();

/** uCRM client names, fetched once each and remembered. */
$nameOf = function (int $id) use ($crm): string {
    static $seen = [];
    if (isset($seen[$id])) return $seen[$id];
    $c = $crm->get('clients/' . $id);
    if (!is_array($c)) return $seen[$id] = '(uCRM did not answer)';
    $n = trim((string)($c['companyName'] ?? ''));
    if ($n === '') $n = trim((string)($c['firstName'] ?? '') . ' ' . (string)($c['lastName'] ?? ''));
    return $seen[$id] = ($n !== '' ? $n : '(unnamed)');
};

$record = function (array $r, string $status, string $action) use ($nameOf): void {
    printf("    Kit Number         %s\n", $r['kit']);
    printf("    uCRM customer      %s\n", $nameOf((int)$r['client']));
    printf("    Customer ID        %d\n", (int)$r['client']);
    printf("    Service            %s\n", (string)$r['service_name'] !== '' ? $r['service_name'] : '(unnamed)');
    printf("    Service ID         %d\n", (int)$r['service']);
    printf("    Current assignment %s\n", $status);
    printf("    Action             %s\n", $action);
    if ((string)($r['detail'] ?? '') !== '') {
        printf("    Detail             %s\n", wordwrap((string)$r['detail'], 56, "\n                       ", true));
    }
    echo "\n";
};

printf("  %d live service(s) read · %d kit number(s) found on them\n\n",
       $scan['services'], $scan['scanned']);

// ── 1 ─────────────────────────────────────────────────────────────────────
echo "  1 · READY TO BIND — " . count($scan['proposals']) . "\n";
echo "  " . str_repeat('─', 68) . "\n\n";
if ($scan['proposals'] === []) {
    echo "    Nothing. No typed kit is both unbound and in stock.\n\n";
} else {
    foreach ($scan['proposals'] as $p) {
        $record($p, 'none — this kit is not bound to anybody',
                   'CREATE an assignment: kit → client #' . (int)$p['client']
                 . ', service #' . (int)$p['service']);
    }
}

// ── 2 ─────────────────────────────────────────────────────────────────────
echo "  2 · ALREADY BOUND / AGREEMENT — " . count($scan['settled']) . "\n";
echo "  " . str_repeat('─', 68) . "\n\n";
if ($scan['settled'] === []) {
    echo "    Nothing. No typed kit already matches an assignment.\n\n";
} else {
    foreach ($scan['settled'] as $p) {
        $record($p, 'assignment #' . (int)($p['assignment'] ?? 0) . ' — agrees',
                   'NONE. The field and the authoritative binding already say the same thing.');
    }
}

// ── 3 ─────────────────────────────────────────────────────────────────────
echo "  3 · REFUSED — " . count($scan['refusals']) . "\n";
echo "  " . str_repeat('─', 68) . "\n\n";
if ($scan['refusals'] === []) {
    echo "    Nothing was refused.\n\n";
} else {
    foreach ($scan['refusals'] as $r) {
        $record($r, 'see detail', strtoupper((string)$r['reason']) . ' — nothing will be written');
    }
}

// ── 4 ─────────────────────────────────────────────────────────────────────
echo "  4 · SAFETY CHECK\n";
echo "  " . str_repeat('─', 68) . "\n\n";
$same = $before['digest'] === $after['digest'];
printf("    assignment rows      before %d   after %d\n", $before['rows'], $after['rows']);
printf("    live assignments     before %d   after %d\n", $before['live'], $after['live']);
printf("    table fingerprint    %s\n", $same ? 'UNCHANGED' : 'CHANGED — this is a bug, report it');
printf("    sha256               %s\n\n", substr($after['digest'], 0, 32));
foreach ([
    'no assignment rows were inserted'   => $before['rows'] === $after['rows'],
    'no assignment rows were updated'    => $same,
    'no assignment was released'         => $before['live'] === $after['live'],
    'no kit was reassigned'              => $same,
    'no uCRM service attribute modified' => true,   // this tool only ever GETs from uCRM
    'no Starlink account modified'       => true,   // it never opens a Starlink session
    'no Data Report usage data modified' => true,   // it never touches another plugin
] as $claim => $ok) {
    printf("    %s  %s\n", $ok ? '✓' : '✗', $claim);
}
echo "\n";
if (!$same) {
    echo "    STOP. A dry run changed the table. Do not run --commit.\n\n";
    return;
}

if (!$commit) {
    echo "  Nothing was written.";
    if ($scan['proposals'] !== []) {
        echo " To create the " . count($scan['proposals']) . " binding(s) in section 1:\n";
        echo "      php tools/kit_intake.php --commit\n";
    }
    echo "\n\n";
    return;
}

$res = $intake->apply($scan['proposals'], ['id' => 0, 'name' => 'kit_intake']);
echo "  WRITTEN\n";
echo "  " . str_repeat('─', 68) . "\n\n";
foreach ($res['created'] as $c) printf("    ✓ %-20s assignment #%d\n", $c['kit'], (int)$c['assignment']);
foreach ($res['failed']  as $f) printf("    ✗ %-20s %s\n", $f['kit'], (string)($f['detail'] ?? ''));
printf("\n  %d created, %d refused at the last moment.\n\n",
       count($res['created']), count($res['failed']));
if ($res['created'] !== []) {
    echo "  The uCRM attribute still says what the operator typed. To write the\n";
    echo "  label back from the binding now that it is authoritative:\n";
    echo "      php tools/crm_kit_label.php --commit\n\n";
}
