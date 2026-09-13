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

$scan = $intake->scan();

printf("  %d service(s) read · %d kit number(s) found · %d already bound\n\n",
       $scan['services'], $scan['scanned'], $scan['settled']);

if ($scan['proposals'] === [] && $scan['refusals'] === []) {
    echo "  Nothing to do. Every kit typed on a service is already bound,\n";
    echo "  or no service carries one.\n\n";
    return;
}

if ($scan['proposals'] !== []) {
    echo "  READY TO BIND\n\n";
    foreach ($scan['proposals'] as $p) {
        printf("    %-20s → client #%-5d service #%-5d  %s\n",
               $p['kit'], $p['client'], $p['service'],
               mb_substr((string)$p['service_name'], 0, 30));
    }
    echo "\n";
}

if ($scan['refusals'] !== []) {
    echo "  NOT BOUND — each says why, and none of these is silent\n\n";
    foreach ($scan['refusals'] as $r) {
        printf("    %-20s  %s\n", $r['kit'], str_replace('_', ' ', (string)$r['reason']));
        printf("    %-20s  %s\n", '', wordwrap((string)($r['detail'] ?? ''), 62, "\n" . str_repeat(' ', 26), true));
        echo "\n";
    }
}

if (!$commit) {
    if ($scan['proposals'] !== []) {
        echo "  Nothing was written. To create the " . count($scan['proposals'])
           . " binding(s) above:\n";
        echo "      php tools/kit_intake.php --commit\n\n";
    }
    return;
}

$res = $intake->apply($scan['proposals'], ['id' => 0, 'name' => 'kit_intake']);

echo "  WRITTEN\n\n";
foreach ($res['created'] as $c) {
    printf("    ✓ %-20s assignment #%d\n", $c['kit'], (int)$c['assignment']);
}
foreach ($res['failed'] as $f) {
    printf("    ✗ %-20s %s\n", $f['kit'], (string)($f['detail'] ?? ''));
}
printf("\n  %d created, %d refused at the last moment.\n\n",
       count($res['created']), count($res['failed']));
if ($res['created'] !== []) {
    echo "  The uCRM attribute still says what the operator typed. To write the\n";
    echo "  label back from the binding now that it is authoritative:\n";
    echo "      php tools/crm_kit_label.php --commit\n\n";
}
