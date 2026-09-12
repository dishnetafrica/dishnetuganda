<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * assign_kit.php — give a kit to a customer, by id, with everything checked.
 *
 *   php tools/assign_kit.php --kit KIT… --client 7
 *   php tools/assign_kit.php --kit KIT… --client 7 --service 500 --commit
 *
 * The one way ownership is recorded. It verifies before it writes:
 *
 *   · the kit exists in stock — a serial nobody received is not equipment,
 *     it is a typo, and assigning one would invent inventory
 *   · the uCRM client exists — a wrong id assigns a customer's dish to
 *     somebody else, and a client id is four keystrokes from another one
 *   · the service belongs to THAT client — the check that stops a kit being
 *     tied to a stranger's subscription
 *   · nobody else already holds the kit
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
require_once $root . '/lib/StockService.php';
require_once $root . '/lib/EquipmentAssignment.php';
require_once $root . '/lib/CrmApiClient.php';

$args = array_slice($argv, 1);
$val = function (string $f) use ($args): string {
    $i = array_search($f, $args, true);
    return ($i !== false && isset($args[$i + 1])) ? (string)$args[$i + 1] : '';
};
$KNOWN = ['--kit', '--client', '--service', '--account', '--service-line',
          '--terminal', '--router', '--note', '--commit'];
foreach ($args as $a) {
    if (strpos($a, '--') !== 0 || in_array($a, $KNOWN, true)) continue;
    fwrite(STDERR, "\n  Unknown option: {$a}\n  Known: " . implode(' ', $KNOWN) . "\n\n");
    exit(2);
}

$dataDir = cliDataDir($root);
$store   = SqliteStore::create($dataDir);
$pdo     = $store->getPdo();
$config  = PluginConfig::load($root, $dataDir);
$stock   = StockService::fromStore($store, $dataDir);
$ea      = new EquipmentAssignment($pdo);
$commit  = in_array('--commit', $args, true);
$actor   = ['id' => 0, 'name' => 'assign_kit'];

echo "\n  ASSIGN A KIT" . ($commit ? '' : '   (dry run — nothing written)') . "\n";
echo "  " . str_repeat('─', 68) . "\n\n";

// ── The kit ─────────────────────────────────────────────────────────────────
$serial = EquipmentAssignment::clean($val('--kit'));
if ($serial === '') { fwrite(STDERR, "  --kit <serial> is required.\n\n"); exit(2); }

$st = $pdo->prepare("SELECT u.*, c.title AS category FROM stock_units u
                     LEFT JOIN stock_categories c ON c.id = u.category_id
                     WHERE UPPER(u.serial_number) = ?");
$st->execute([$serial]);
$unit = $st->fetch(\PDO::FETCH_ASSOC) ?: null;

if (!$unit) {
    echo "  ✗ {$serial} is not in stock, so it cannot be assigned to anybody.\n\n";
    echo "  A serial that was never received is not equipment — it is a typo, and\n";
    echo "  assigning one would invent inventory that does not exist.\n\n";
    echo "  Get it onto the shelf first, whichever of these is true:\n\n";
    echo "    · It came on a Starlink order — import that account's orders, which\n";
    echo "      also books what it cost and clears it out of the suspense account:\n";
    echo "        php tools/import_starlink_orders.php --file <orders.json> --category N --commit\n\n";
    echo "    · It arrived another way — receive it on the Stock screen with its\n";
    echo "      supplier, cost and serial.\n\n";
    // Near misses, because a mistyped serial is the likeliest cause.
    $like = $pdo->prepare("SELECT serial_number, status FROM stock_units
                           WHERE serial_number LIKE ? LIMIT 5");
    $like->execute([substr($serial, 0, 6) . '%']);
    $near = $like->fetchAll(\PDO::FETCH_ASSOC);
    if ($near !== []) {
        echo "  Serials in stock that start the same way:\n";
        foreach ($near as $n) printf("    %-22s %s\n", $n['serial_number'], $n['status']);
        echo "\n";
    }
    exit(1);
}

printf("  KIT        %s  (unit #%d, %s, %s)\n", $serial, (int)$unit['id'],
       (string)($unit['category'] ?? '?'), (string)$unit['status']);

$held = $ea->activeForUnit((int)$unit['id']);
if ($held) {
    echo "\n  ✗ Already assigned to client #" . (int)$held['crm_client_id']
       . " (assignment #" . (int)$held['id'] . ").\n\n";
    echo "  Release it first — that keeps the history instead of overwriting it:\n";
    echo "    the Stock screen's Return, or a replacement if a new dish took over.\n\n";
    exit(1);
}

// ── The customer ────────────────────────────────────────────────────────────
$clientArg = trim($val('--client'));
if ($clientArg === '' || !ctype_digit($clientArg) || (int)$clientArg < 1) {
    fwrite(STDERR, "\n  --client needs the uCRM client NUMBER"
        . ($clientArg !== '' ? ", not '{$clientArg}'" : '') . ".\n");
    fwrite(STDERR, "  It is the number in the CRM url: /crm/client/7 → --client 7\n\n");
    exit(2);
}
$clientId = (int)$clientArg;

$crm = null; $client = null;
try { $crm = CrmApiClient::fromUcrm($root, $config); } catch (\Throwable $e) { /* */ }
if ($crm && $crm->isConfigured()) {
    $client = $crm->get('clients/' . $clientId);
}
if ($crm && $crm->isConfigured() && !is_array($client)) {
    echo "\n  ✗ uCRM has no client #{$clientId}.\n\n";
    echo "  A wrong id here puts one customer's dish on another customer's account,\n";
    echo "  and two client ids are four keystrokes apart. Check the CRM url.\n\n";
    exit(1);
}
$cname = '';
if (is_array($client)) {
    $cname = trim((string)($client['companyName'] ?? '')) !== ''
        ? (string)$client['companyName']
        : trim((string)($client['firstName'] ?? '') . ' ' . (string)($client['lastName'] ?? ''));
}
printf("  CUSTOMER   #%d%s\n", $clientId, $cname !== '' ? '  ' . $cname : '  (name unavailable — CRM not reachable)');

// ── The service ─────────────────────────────────────────────────────────────
$serviceArg = trim($val('--service'));
$serviceId  = 0;
$services   = [];
if ($crm && $crm->isConfigured()) {
    $r = $crm->get("clients/services?clientId={$clientId}&limit=100");
    if (is_array($r)) $services = $r;
}

if ($serviceArg !== '') {
    if (!ctype_digit($serviceArg) || (int)$serviceArg < 1) {
        fwrite(STDERR, "\n  --service needs a number, not '{$serviceArg}'.\n\n");
        exit(2);
    }
    $serviceId = (int)$serviceArg;
    // The check that matters: a service belonging to somebody else would tie
    // this kit to a stranger's subscription, and every suspension after that
    // would reach the wrong dish.
    if ($services !== []) {
        $ownsIt = false;
        foreach ($services as $s) {
            if ((int)($s['id'] ?? 0) === $serviceId) { $ownsIt = true; break; }
        }
        if (!$ownsIt) {
            echo "\n  ✗ uCRM service #{$serviceId} does not belong to client #{$clientId}.\n\n";
            echo "  Tying a kit to another customer's subscription means every suspension\n";
            echo "  after today reaches the wrong dish.\n\n";
            exit(1);
        }
    }
}

if ($serviceId === 0) {
    echo "  SERVICE    (none given)\n\n";
    if ($services === []) {
        echo "  This customer has no service in uCRM" .
             (($crm && $crm->isConfigured()) ? "" : " (or the CRM is unreachable)") . ".\n\n";
        echo "  A kit can be assigned without one, but per-service blocking will not\n";
        echo "  work: suspend one of two services and nothing says which dish to cut.\n";
        echo "  Create the service in uCRM first, then pass --service <id>.\n\n";
    } else {
        echo "  Services on this customer — pass one with --service:\n\n";
        foreach ($services as $s) {
            printf("    %-6s %-40s %s\n", (int)($s['id'] ?? 0),
                mb_substr((string)($s['name'] ?? ''), 0, 40),
                ['1' => 'active', '2' => 'postponed', '3' => 'suspended', '4' => 'suspended',
                 '5' => 'ended'][(string)($s['status'] ?? '')] ?? ('status ' . (string)($s['status'] ?? '?')));
        }
        echo "\n  Assigning without one is allowed, but say so deliberately.\n\n";
    }
} else {
    $sname = '';
    foreach ($services as $s) if ((int)($s['id'] ?? 0) === $serviceId) $sname = (string)($s['name'] ?? '');
    printf("  SERVICE    #%d%s\n", $serviceId, $sname !== '' ? '  ' . $sname : '');
}

// ── Starlink identifiers ────────────────────────────────────────────────────
$ids = [
    'starlink_account'      => $val('--account'),
    'starlink_service_line' => $val('--service-line'),
    'terminal_id'           => $val('--terminal'),
    'router_id'             => $val('--router'),
];
$given = array_filter($ids, static fn($v) => trim((string)$v) !== '');
echo "\n  STARLINK   ";
echo $given === []
    ? "none given — blocking will have nothing to act on until they are known.\n"
       . "             Run tools/binding_doctor.php --learn once the router map has them.\n"
    : implode(', ', array_map(static fn($k, $v) => $k . '=' . $v, array_keys($given), $given)) . "\n";

// ── Do it ───────────────────────────────────────────────────────────────────
echo "\n";
if (!$commit) {
    echo "  Nothing was written. To go ahead:\n\n";
    echo "    php tools/assign_kit.php --kit {$serial} --client {$clientId}"
       . ($serviceId > 0 ? " --service {$serviceId}" : '') . " --commit\n\n";
    exit(0);
}

$r = $stock->install((int)$unit['id'], array_merge([
    'crm_client_id'  => $clientId,
    'crm_service_id' => $serviceId,
    'client_name'    => $cname !== '' ? $cname : ('Client #' . $clientId),
    'note'           => trim($val('--note')) ?: 'assigned via assign_kit',
], $ids), 0, 'assign_kit');

$made = $ea->activeForUnit((int)$unit['id']);
printf("  ✓ assignment #%d — %s is now client #%d's, and only theirs.\n\n",
       (int)($made['id'] ?? 0), $serial, $clientId);
echo "  Check the round trip:\n";
echo "    php tools/binding_trace.php --kit {$serial}\n\n";
exit(0);
