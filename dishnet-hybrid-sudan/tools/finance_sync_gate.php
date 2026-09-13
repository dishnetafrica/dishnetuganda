<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * finance_sync_gate.php — make a bound kit visible to Finance's own sync.
 *
 *   php tools/finance_sync_gate.php          report only
 *   php tools/finance_sync_gate.php --fix    write the kit number into uCRM
 *
 * ── WHY ─────────────────────────────────────────────────────────────────
 *
 * dishnet-starlink-finance already knows how to create kits: its
 * syncStarlinkServices() reads uCRM, extracts kit numbers from services, and
 * creates any kit it finds that it does not hold — with billing, address and
 * client mapping filled from uCRM. It is Finance's own code writing Finance's
 * own file, which is the only way a kit should get in there.
 *
 * It skips our services because of how it looks:
 *
 *     $noteText   = name . note . invoiceLabel . street1 . street2
 *                 . servicePlanName . addressGpsLat . contractLengthType
 *     $kitNum     = preg_match('/\b(KIT[A-Z0-9]{8,})\b/i', $noteText)
 *     $isStarlink = stripos($planName, 'tarlink') !== false
 *     $isActive   = status == 1 || status == 3
 *
 * The kit number is not in that list of fields — it is in the service's
 * starlinkDetails custom attribute, which Finance never reads. So the fix is
 * not to put a row in Finance's file. It is to put the kit number where uCRM
 * services already carry it in South Sudan, and let Finance find it.
 *
 * ── WHAT IT WRITES ──────────────────────────────────────────────────────
 *
 * One field, on uCRM, which is the source of truth we own: the service note,
 * appended to, never replaced. The kit number is the one already bound in
 * equipment_assignments — nothing is invented and nothing is chosen here.
 *
 * The plan-name gate is NOT fixed here. Renaming a service plan changes what
 * every customer on it sees on their invoice, which is a pricing and branding
 * decision, not a data repair. It is reported with the exact remedy.
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
require_once $root . '/lib/CrmApiClient.php';

$args = array_slice($argv, 1);
$fix  = in_array('--fix', $args, true);
foreach ($args as $a) {
    if (strpos($a, '--') === 0 && $a !== '--fix') {
        fwrite(STDERR, "\n  Unknown option: {$a}\n  Known: --fix\n\n"); exit(2);
    }
}

$dataDir = cliDataDir($root);
$store   = SqliteStore::create($dataDir);
$config  = PluginConfig::load($root, $dataDir);
$ea      = new EquipmentAssignment($store->getPdo());

$crm = null;
try { $crm = CrmApiClient::fromUcrm($root, $config); } catch (\Throwable $e) {}
if (!$crm || !$crm->isConfigured()) {
    fwrite(STDERR, "\n  uCRM is not reachable, so nothing here can be checked, let alone changed.\n\n");
    exit(1);
}

/** Finance's own field list and regex, reproduced exactly. */
function financeNoteText(array $svc): string {
    return ($svc['name'] ?? '') . ' ' . ($svc['note'] ?? '') . ' ' . ($svc['invoiceLabel'] ?? '')
         . ' ' . ($svc['street1'] ?? '') . ' ' . ($svc['street2'] ?? '')
         . ' ' . ($svc['servicePlanName'] ?? '') . ' ' . ($svc['addressGpsLat'] ?? '')
         . ' ' . ($svc['contractLengthType'] ?? '');
}
function financeKit(string $noteText): string {
    return preg_match('/\b(KIT[A-Z0-9]{8,})\b/i', $noteText, $m) ? strtoupper($m[1]) : '';
}
function financeStarlink(string $planName): bool {
    return stripos($planName, 'tarlink') !== false;
}
/**
 * Whether Finance's regex could ever match this kit number.
 *
 * KIT[A-Z0-9]{8,} needs eight characters after the letters KIT. A shorter kit
 * number cannot be found by Finance wherever it is written, so writing it into
 * the note would look like a fix and achieve nothing.
 */
function financeTooShort(string $kit): bool {
    return preg_match('/^KIT[A-Z0-9]{8,}$/i', $kit) !== 1;
}

$live = $ea->liveAssignments();
if ($live === []) {
    fwrite(STDERR, "\n  No live assignments, so there is no kit for Finance to find.\n\n");
    exit(1);
}

echo "\n", str_repeat('=', 74), "\n";
echo "  FINANCE SYNC GATE", $fix ? "  —  FIX" : "  —  REPORT ONLY (add --fix to write)", "\n";
echo str_repeat('=', 74), "\n";

$blocked = 0; $written = 0; $ready = 0;

foreach ($live as $a) {
    $kit  = strtoupper(trim((string)$a['kit_serial']));
    $sid  = (int)($a['crm_service_id'] ?? 0);
    $cid  = (int)$a['crm_client_id'];
    if ($kit === '') continue;

    echo "\n  ", $kit, "   assignment #", (int)$a['id'], "   client #", $cid, "\n";
    echo "  ", str_repeat('-', 70), "\n";

    if ($sid <= 0) {
        echo "    BLOCKED  the assignment records no uCRM service, and Finance reads services.\n";
        echo "             Bind the kit to a service first.\n";
        $blocked++; continue;
    }

    $svc = $crm->get('clients/services/' . $sid);
    if (!is_array($svc) || $svc === []) {
        echo "    BLOCKED  uCRM returned nothing for service #", $sid, ".\n";
        $blocked++; continue;
    }

    $status   = (int)($svc['status'] ?? 0);
    $planName = (string)($svc['servicePlanName'] ?? '');
    $svcName  = (string)($svc['name'] ?? '');
    $note     = (string)($svc['note'] ?? '');
    $noteText = financeNoteText($svc);
    $found    = financeKit($noteText);

    $gActive   = ($status === 1 || $status === 3);
    $gStarlink = financeStarlink($planName) || financeStarlink($svcName);
    $gKit      = ($found === $kit);

    printf("    service #%-6d status %d       %s\n", $sid, $status,
           $gActive ? 'ACTIVE — passes' : 'NOT ACTIVE — Finance skips it (needs 1 or 3)');
    printf("    servicePlanName  %s\n", $planName === '' ? '(none)' : $planName);
    printf("    service name     %s\n", $svcName === '' ? '(none)' : $svcName);
    printf("    \"starlink\" in either?  %s\n",
           $gStarlink ? 'yes — passes' : 'NO — Finance skips it');
    printf("    kit number findable?   %s\n",
           $gKit ? 'yes — passes' : ($found === '' ? 'NO — no KIT… in any field Finance scans'
                                                   : 'NO — it finds ' . $found . ', not this kit'));

    if ($gActive && $gStarlink && $gKit) {
        echo "    READY    Finance's sync will create this kit.\n";
        $ready++; continue;
    }

    if (!$gStarlink) {
        echo "\n    Finance only looks at services whose plan name contains \"starlink\".\n";
        echo "    This one does not, so it is invisible to the sync however the kit\n";
        echo "    number is recorded. Renaming a plan changes what every customer on\n";
        echo "    it sees on their invoice, so it is not something to change from here.\n";
        echo "    Remedy: in uCRM, name the service plan so it contains Starlink —\n";
        echo "    e.g. \"Starlink ", ($planName !== '' ? $planName : 'Residential'), "\".\n";
        $blocked++;
    }

    if (!$gKit && financeTooShort($kit)) {
        echo "\n    BLOCKED  Finance matches KIT followed by at least 8 characters.\n";
        echo "             \"", $kit, "\" is shorter than that, so it cannot be found\n";
        echo "             wherever it is written. Writing it into the note would\n";
        echo "             look like a fix and achieve nothing, so nothing was written.\n";
        $blocked++;
    } elseif (!$gKit) {
        $newNote = trim($note === '' ? $kit : ($note . "\n" . $kit));
        echo "\n    Service note now  : ", ($note === '' ? '(empty)' : str_replace("\n", ' / ', $note)), "\n";
        echo "    Service note after: ", str_replace("\n", ' / ', $newNote), "\n";
        if (!$fix) {
            echo "    (report only — nothing written)\n";
        } else {
            $res = $crm->patch('clients/services/' . $sid, ['note' => $newNote]);
            if (!is_array($res)) {
                echo "    FAILED   uCRM did not accept the note change.\n";
                $blocked++;
            } else {
                $after = financeKit(financeNoteText($res));
                if ($after === $kit) {
                    echo "    WRITTEN  uCRM now carries the kit number where Finance looks.\n";
                    $written++;
                } else {
                    echo "    UNSURE   uCRM accepted the change but the kit is still not\n";
                    echo "             findable in what it returned. Nothing else was done.\n";
                    $blocked++;
                }
            }
        }
    }
}

echo "\n", str_repeat('=', 74), "\n";
printf("  ready %d    written %d    blocked %d\n", $ready, $written, $blocked);
echo str_repeat('=', 74), "\n";

if ($blocked > 0) {
    echo "\n  Blocked kits stay invisible to Finance until the remedy above is done.\n";
}
if ($ready > 0 || $written > 0) {
    echo "\n  Next: open Finance and press its own sync (Sync Starlink Services /\n";
    echo "  Auto Sync). Finance reads uCRM, finds the kit, and creates it itself —\n";
    echo "  with billing, address and client mapping from uCRM.\n";
}
echo "\n";
