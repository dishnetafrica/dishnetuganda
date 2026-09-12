<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * crm_kit_label.php — show the kit on the uCRM service, South Sudan style.
 *
 *   php tools/crm_kit_label.php                 what uCRM says vs what we say
 *   php tools/crm_kit_label.php --commit        write the missing labels
 *   php tools/crm_kit_label.php --overwrite --commit   replace typed ones too
 *   php tools/crm_kit_label.php --plans         what each service actually sells
 *
 * South Sudan writes the kit serial into a uCRM service attribute and treats
 * it as THE binding — which is why a rename or a typo there could take a
 * customer offline. Uganda keeps the binding in equipment_assignments where
 * the database defends it.
 *
 * But South Sudan gets one real thing from that convention: an operator
 * looking at a service in uCRM can see which dish it is. This gives Uganda
 * that, one way only:
 *
 *     equipment_assignments  ──writes──▶  uCRM service attribute
 *                            ◀─never reads for a decision─
 *
 * A serial already on the service that differs from ours is REPORTED, not
 * overwritten. Somebody typed it, and destroying that is destroying the only
 * evidence of a disagreement worth looking at.
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
require_once $root . '/lib/ServicePlan.php';
require_once $root . '/lib/CrmKitAttribute.php';
require_once $root . '/lib/CrmApiClient.php';

$args  = array_slice($argv, 1);
$KNOWN = ['--commit', '--overwrite', '--plans'];
foreach ($args as $a) {
    if (strpos($a, '--') !== 0 || in_array($a, $KNOWN, true)) continue;
    fwrite(STDERR, "\n  Unknown option: {$a}\n  Known: " . implode(' ', $KNOWN) . "\n\n");
    exit(2);
}
$commit    = in_array('--commit', $args, true);
$overwrite = in_array('--overwrite', $args, true);
$plansOnly = in_array('--plans', $args, true);

$dataDir = cliDataDir($root);
$store   = SqliteStore::create($dataDir);
$config  = PluginConfig::load($root, $dataDir);
$ea      = new EquipmentAssignment($store->getPdo());

$crm = null;
try { $crm = CrmApiClient::fromUcrm($root, $config); } catch (\Throwable $e) { /* */ }
if (!$crm || !$crm->isConfigured()) {
    fwrite(STDERR, "\n  uCRM is not reachable from here, so there is nothing to compare against.\n\n");
    exit(1);
}

$live = $store->getPdo()->query(
    "SELECT * FROM equipment_assignments WHERE released_at IS NULL ORDER BY crm_client_id, id"
)->fetchAll(\PDO::FETCH_ASSOC);

$kit = new CrmKitAttribute($crm, $ea);

echo "\n  KIT LABEL ON THE uCRM SERVICE" . ($commit ? '' : '   (dry run — nothing written)') . "\n";
echo "  " . str_repeat('─', 72) . "\n\n";

if ($live === []) { echo "  No live assignments, so there is nothing to label.\n\n"; exit(0); }

// ── What each service sells ─────────────────────────────────────────────────
if ($plansOnly) {
    echo "  WHAT EACH SERVICE SELLS\n";
    printf("    %-5s %-8s %-20s %-34s %s\n", '#', 'CLIENT', 'KIT', 'PLAN (as the customer sees it)', 'ALLOWANCE');
    foreach ($live as $a) {
        $p = $kit->planFor($a);
        if ($p === null) {
            printf("    %-5s #%-7s %-20s %-34s %s\n", $a['id'], $a['crm_client_id'],
                   $a['kit_serial'], '—', 'no uCRM service on the assignment');
            continue;
        }
        printf("    %-5s #%-7s %-20s %-34s %s\n", $a['id'], $a['crm_client_id'],
            $a['kit_serial'], mb_substr($p['display'], 0, 34),
            $p['cap_gb'] > 0
                ? number_format($p['cap_gb'], 0) . ' GB'
                : ($p['unlimited'] ? 'unlimited' : 'UNKNOWN — no plan name on the service'));
        if ($p['display'] !== $p['raw']) {
            printf("    %-5s   uCRM says \"%s\", masked for customers\n", '', $p['raw']);
        }
    }
    echo "\n  A plan with no number in it is unlimited. A service with no plan name\n";
    echo "  at all is UNKNOWN — and must never be shown to a customer as unlimited,\n";
    echo "  which is the mistake on South Sudan's own customer page today.\n\n";
    exit(0);
}

// ── uCRM versus us ──────────────────────────────────────────────────────────
$rows = $kit->survey($live);
$label = ['match' => 'matches', 'missing' => 'not on the service yet',
          'differs' => 'DIFFERENT on the service', 'no_service' => 'no uCRM service'];
printf("    %-5s %-8s %-9s %-20s %s\n", '#', 'CLIENT', 'SERVICE', 'KIT', 'uCRM');
foreach ($rows as $r) {
    printf("    %-5s #%-7s %-9s %-20s %s\n", $r['assignment'], $r['client'],
        $r['service'] > 0 ? ('#' . $r['service']) : '—', $r['ours'], $label[$r['state']]);
    if ($r['state'] === 'differs') {
        printf("    %-5s   uCRM says %s\n", '', implode(', ', $r['theirs']));
    }
}

$attrId = $kit->attributeId();
echo "\n";
if ($attrId <= 0) {
    echo "  There is no service custom attribute for the kit serial in uCRM.\n\n";
    echo "  Create one to match South Sudan: System → Customisation → Custom\n";
    echo "  attributes → Service → Add, named \"" . CrmKitAttribute::PREFERRED_KEY . "\".\n\n";
    exit(1);
}

$w = $kit->write($rows, $attrId, $overwrite, $commit);
printf("    %-26s %d\n", $commit ? 'labels written' : 'labels to write', $w['written']);
printf("    %-26s %d\n", 'left alone', $w['skipped']);
if (!empty($w['error'])) { echo "\n  ✗ " . $w['error'] . "\n\n"; exit(1); }
if ($w['notes'] !== []) {
    echo "\n  NOTES\n";
    foreach ($w['notes'] as $n) echo "    · {$n}\n";
}

echo "\n";
if (!$commit) {
    echo "  Nothing was written. To go ahead:\n\n";
    echo "    php tools/crm_kit_label.php --commit\n\n";
    exit(0);
}
echo "  Done. The serial now shows on the service in uCRM — as a label.\n";
echo "  Nothing reads it back to decide who owns a kit.\n\n";
exit(0);
