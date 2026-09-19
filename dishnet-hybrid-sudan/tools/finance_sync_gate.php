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
$fix     = in_array('--fix', $args, true);
$fixPlan = in_array('--fix-plan', $args, true);
foreach ($args as $a) {
    if (strpos($a, '--') === 0 && $a !== '--fix' && $a !== '--fix-plan') {
        fwrite(STDERR, "\n  Unknown option: {$a}\n  Known: --fix, --fix-plan\n\n"); exit(2);
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
 * The plan name Finance judges, reproduced exactly:
 *
 *     $planName = $svc['servicePlanName'] ?? $svc['name'] ?? '';
 *
 * Note ??, not ||. When servicePlanName is present the service name is never
 * consulted, even if servicePlanName is an empty string. Checking both fields
 * with an OR -- which this tool did at first -- reports a service ready that
 * Finance will skip.
 */
function financePlanName(array $svc): string {
    return (string)($svc['servicePlanName'] ?? $svc['name'] ?? '');
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
$mode = [];
if ($fix)     $mode[] = 'FIX NOTE';
if ($fixPlan) $mode[] = 'RENAME PLAN';
echo "  FINANCE SYNC GATE  —  ",
     $mode === [] ? 'REPORT ONLY  (--fix writes the kit number, --fix-plan renames the plan)'
                  : implode(' + ', $mode), "\n";
echo str_repeat('=', 74), "\n";

$blocked = 0; $written = 0; $ready = 0;
$planDone = [];   // plan ids renamed in this run — two kits on one plan rename it once

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
    $planName = financePlanName($svc);
    $svcName  = (string)($svc['name'] ?? '');
    $note     = (string)($svc['note'] ?? '');
    $noteText = financeNoteText($svc);
    $found    = financeKit($noteText);

    $gActive   = ($status === 1 || $status === 3);
    $gStarlink = financeStarlink($planName);
    $gKit      = ($found === $kit);

    printf("    service #%-6d status %d       %s\n", $sid, $status,
           $gActive ? 'ACTIVE — passes' : 'NOT ACTIVE — Finance skips it (needs 1 or 3)');
    printf("    service name     %s\n", $svcName === '' ? '(none)' : $svcName);
    printf("    plan Finance judges  %s\n", $planName === '' ? '(none)' : $planName);
    printf("    \"starlink\" in it?      %s\n",
           $gStarlink ? 'yes — passes' : 'NO — Finance skips this service');
    if (!$gStarlink && financeStarlink($svcName)) {
        echo "    (the service NAME does contain it, but Finance reads\n";
        echo "     servicePlanName ?? name — so the name is never reached.)\n";
    }
    printf("    kit number findable?   %s\n",
           $gKit ? 'yes — passes' : ($found === '' ? 'NO — no KIT… in any field Finance scans'
                                                   : 'NO — it finds ' . $found . ', not this kit'));

    if ($gActive && $gStarlink && $gKit) {
        echo "    READY    Finance's sync will create this kit.\n";
        $ready++; continue;
    }

    if (!$gStarlink) {
        $planId  = (int)($svc['servicePlanId'] ?? 0);
        $newPlan = 'Starlink ' . ($planName !== '' ? $planName : 'Residential');

        echo "\n    Finance only looks at services whose plan name contains \"starlink\".\n";
        echo "    This one does not, so it is invisible to the sync however the kit\n";
        echo "    number is recorded.\n\n";
        echo "      plan #", ($planId > 0 ? (string)$planId : '?'), "  now    \"", $planName, "\"\n";
        echo "                 would become \"", $newPlan, "\"\n\n";
        echo "    This renames the PLAN in uCRM. The plan name appears on invoices and\n";
        echo "    in the client zone for every service on it, not only this one. Only\n";
        echo "    the name is sent — no price, no billing period, nothing else.\n";

        if (!$fixPlan) {
            echo "    (not changed — pass --fix-plan to rename it)\n";
            $blocked++;
        } elseif ($planId <= 0) {
            echo "    BLOCKED  uCRM did not say which plan this service is on.\n";
            $blocked++;
        } elseif (isset($planDone[$planId])) {
            echo "    already renamed earlier in this run\n";
            $gStarlink = true;
        } else {
            $pres = $crm->patch('service-plans/' . $planId, ['name' => $newPlan]);
            // uCRM accepting the PATCH is not the same as the name having
            // changed, so judge the reply by Finance's own test.
            $got  = is_array($pres) ? (string)($pres['name'] ?? '') : '';
            if ($got !== '' && financeStarlink($got)) {
                echo "    RENAMED  the plan is now \"", $got, "\" — Finance will see it.\n";
                $planDone[$planId] = true;
                $written++;
                $gStarlink = true;
            } else {
                echo "    FAILED   uCRM did not rename the plan",
                     $got !== '' ? " — it still reads \"" . $got . "\".\n" : ".\n";
                $blocked++;
            }
        }
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
                    $gKit = true;
                } else {
                    echo "    UNSURE   uCRM accepted the change but the kit is still not\n";
                    echo "             findable in what it returned. Nothing else was done.\n";
                    $blocked++;
                }
            }
        }
    }

    // Re-judged after the remedies, because a gate this run just cleared is
    // the whole point of running with a flag. Only a kit that passes all
    // three now is one Finance will actually create.
    if ($gActive && $gStarlink && $gKit) {
        echo "    NOW READY  all three gates pass — Finance's sync will create this kit.\n";
        $ready++;
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
