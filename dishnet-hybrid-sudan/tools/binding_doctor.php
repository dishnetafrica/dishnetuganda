<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * binding_doctor.php — who owns which kit, and where that is still a guess.
 *
 *   php tools/binding_doctor.php              what is bound, what is weak
 *   php tools/binding_doctor.php --repair     backfill only what is certain
 *   php tools/binding_doctor.php --learn      fill in Starlink ids from the router map
 *
 * A weak binding is not a cosmetic problem. A unit installed at a customer
 * with no client id is invisible to blocking, to the portal and to billing;
 * a customer whose kit is known only from a service name loses their dish the
 * day somebody renames the service.
 *
 * --repair creates assignments from stock_units rows that already carry a real
 * crm_client_id. It never invents one from a name, and it never invents a
 * Starlink identifier. What cannot be established deterministically is listed
 * for a human, not guessed at.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
$GLOBALS['_PLUGIN_ROOT'] = $root;
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/SiblingPlugin.php';
require_once $root . '/lib/StockService.php';
require_once $root . '/lib/EquipmentAssignment.php';

$args = array_slice($argv, 1);
foreach ($args as $a) {
    if (strpos($a, '--') !== 0 || in_array($a, ['--repair', '--learn'], true)) continue;
    fwrite(STDERR, "\n  Unknown option: {$a}\n  Known: --repair --learn\n\n");
    exit(2);
}
$repair = in_array('--repair', $args, true);
$learn  = in_array('--learn',  $args, true);

$dataDir = cliDataDir($root);
$store   = SqliteStore::create($dataDir);
$pdo     = $store->getPdo();
$ea      = new EquipmentAssignment($pdo);
$actor   = ['id' => 0, 'name' => 'binding doctor'];

$has = function (string $t) use ($pdo): bool {
    try {
        return (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='" . $t . "'")->fetchColumn();
    } catch (\Throwable $e) { return false; }
};

echo "\n  EQUIPMENT BINDING" . ($repair || $learn ? '' : '   (read-only)') . "\n";
echo "  " . str_repeat('─', 72) . "\n\n";

if (!$has('equipment_assignments')) {
    echo "  ✗ equipment_assignments does not exist. Run the migrations first:\n";
    echo "      php tools/schema_doctor.php --repair\n\n";
    exit(1);
}
if (!$has('stock_units')) { echo "  No stock tables on this install.\n\n"; exit(0); }

// ── The database's own guarantees ───────────────────────────────────────────
$want = ['idx_ea_live_unit', 'idx_ea_live_service', 'idx_ea_live_kit',
         'idx_ea_live_terminal', 'idx_ea_live_router', 'idx_ea_live_sl'];
$wantTrig = ['ea_released_is_history', 'ea_never_deleted'];
$haveIdx = $pdo->query("SELECT name FROM sqlite_master WHERE type='index'")->fetchAll(\PDO::FETCH_COLUMN);
$haveTrg = $pdo->query("SELECT name FROM sqlite_master WHERE type='trigger'")->fetchAll(\PDO::FETCH_COLUMN);
$missing = array_merge(array_diff($want, $haveIdx), array_diff($wantTrig, $haveTrg));
if ($missing === []) {
    echo "  ✓ the database enforces one live owner per unit, per service and per\n";
    echo "    Starlink identifier, and released assignments cannot be edited.\n\n";
} else {
    echo "  ✗ these guarantees are MISSING — the rules are only in application code:\n";
    foreach ($missing as $m) echo "      {$m}\n";
    echo "    Run: php tools/schema_doctor.php --repair\n\n";
}

// ── What is bound ───────────────────────────────────────────────────────────
$live = $pdo->query(
    "SELECT a.*, u.serial_number, u.status AS unit_status, c.title AS category
     FROM equipment_assignments a
     LEFT JOIN stock_units u ON u.id = a.unit_id
     LEFT JOIN stock_categories c ON c.id = u.category_id
     WHERE a.released_at IS NULL ORDER BY a.crm_client_id, a.id")->fetchAll(\PDO::FETCH_ASSOC);

echo "  LIVE ASSIGNMENTS (" . count($live) . ")\n";
if ($live === []) {
    echo "    none — no customer currently holds any equipment on this install.\n";
} else {
    printf("    %-5s %-8s %-9s %-20s %-10s %s\n", '#', 'CLIENT', 'SERVICE', 'KIT', 'ROUTER', 'STATE');
    foreach ($live as $a) {
        printf("    %-5s #%-7s %-9s %-20s %-10s %s\n",
            $a['id'], $a['crm_client_id'],
            $a['crm_service_id'] === null ? '—' : ('#' . $a['crm_service_id']),
            $a['kit_serial'] !== '' ? $a['kit_serial'] : '(no serial)',
            $a['router_id'] !== '' ? $a['router_id'] : '—',
            $a['unit_status'] ?? '(unit missing)');
    }
}
echo "\n";

// ── What is weak ────────────────────────────────────────────────────────────
$weak = [];

// Units that look installed but have no assignment behind them.
foreach ($pdo->query(
    "SELECT u.*, c.title AS category FROM stock_units u
     LEFT JOIN stock_categories c ON c.id = u.category_id
     WHERE u.status = 'installed'
       AND NOT EXISTS (SELECT 1 FROM equipment_assignments a
                       WHERE a.unit_id = u.id AND a.released_at IS NULL)")->fetchAll(\PDO::FETCH_ASSOC) as $u) {
    $cid = (int)($u['crm_client_id'] ?? 0);
    $weak[] = [
        'unit'   => $u,
        'kind'   => $cid > 0 ? 'repairable' : 'needs_a_human',
        'why'    => $cid > 0
            ? "installed at client #{$cid} with no assignment — can be backfilled"
            : 'installed with NO client id at all, only the name "'
              . trim((string)$u['location_name']) . '" — invisible to blocking, portal and billing',
    ];
}

// Assignments with no service, which makes per-service suspension impossible.
foreach ($live as $a) {
    if ($a['crm_service_id'] !== null) continue;
    $weak[] = ['unit' => ['id' => $a['unit_id'], 'serial_number' => $a['kit_serial']],
               'kind' => 'needs_a_human',
               'why'  => "assignment #{$a['id']} has no uCRM service id — suspending one of two "
                       . "services cannot tell which kit to block"];
}
// Assignments with no Starlink identifiers at all: we know who owns it and
// cannot act on the hardware.
foreach ($live as $a) {
    if ($a['router_id'] !== '' || $a['terminal_id'] !== '' || $a['starlink_service_line'] !== '') continue;
    $weak[] = ['unit' => ['id' => $a['unit_id'], 'serial_number' => $a['kit_serial']],
               'kind' => 'learnable',
               'why'  => "assignment #{$a['id']} has no router, terminal or service line — "
                       . "blocking has nothing to act on"];
}

echo "  WEAK OR MISSING (" . count($weak) . ")\n";
if ($weak === []) {
    echo "    none — every installed unit has an assignment, a service and a way to reach it.\n";
} else {
    foreach ($weak as $w) {
        $mark = ['repairable' => '·', 'learnable' => '~', 'needs_a_human' => '!'][$w['kind']] ?? '·';
        printf("    %s unit #%-5s %-20s %s\n", $mark, $w['unit']['id'],
            (string)($w['unit']['serial_number'] ?? ''), $w['why']);
    }
    echo "\n      ·  can be repaired automatically   (--repair)\n";
    echo "      ~  can be learned from the router map (--learn)\n";
    echo "      !  needs a person to say what is true\n";
}
echo "\n";

// ── Repair ──────────────────────────────────────────────────────────────────
if ($repair) {
    $made = 0; $failed = [];
    foreach ($weak as $w) {
        if ($w['kind'] !== 'repairable') continue;
        $u = $w['unit'];
        $r = $ea->assign([
            'unit_id'        => (int)$u['id'],
            'crm_client_id'  => (int)$u['crm_client_id'],
            // Only what stock_units already holds. A service id it does not
            // have is NOT invented — an assignment with the wrong service
            // would suspend the wrong kit, which is the failure being fixed.
            'crm_service_id' => (int)($u['crm_service_id'] ?? 0),
            'starlink_account' => (string)($u['starlink_account'] ?? ''),
            'note'           => 'backfilled from stock_units by binding_doctor',
        ], $actor);
        if (!empty($r['ok'])) $made++; else $failed[] = 'unit #' . $u['id'] . ': ' . $r['error'];
    }
    echo "  REPAIR\n";
    printf("    %-28s %d\n", 'assignments created', $made);
    foreach ($failed as $f) echo "    ✗ {$f}\n";
    echo "\n";
}

// ── Learn Starlink identifiers ──────────────────────────────────────────────
if ($learn) {
    $map = SiblingPlugin::readJsonOrEmpty('dishnet-data-report', 'wifi_router_map.json');
    echo "  LEARN FROM THE ROUTER MAP\n";
    if ($map === []) {
        echo "    wifi_router_map.json is empty or unreadable — nothing to learn from.\n";
        echo "    Run the data plugin's cron once the account cookies are imported.\n\n";
    } else {
        $added = 0;
        foreach ($live as $a) {
            $kit = strtoupper(trim((string)$a['kit_serial']));
            if ($kit === '') continue;
            foreach ($map as $rid => $info) {
                if (!is_array($info)) continue;
                // Exact serial comparison. Not a substring, not a prefix.
                if (strcasecmp((string)($info['kit_serial'] ?? ''), $kit) !== 0) continue;
                $r = $ea->addIdentifiers((int)$a['id'], [
                    'router_id'             => (string)($info['router_id'] ?? $rid),
                    'terminal_id'           => (string)($info['terminal_id'] ?? ''),
                    'starlink_service_line' => (string)($info['service_line'] ?? ''),
                    'starlink_account'      => (string)($info['account_number'] ?? ''),
                ], $actor);
                if (!empty($r['ok']) && $r['added'] !== []) {
                    $added++;
                    printf("    assignment #%-4s %s ← %s\n", $a['id'], $kit,
                           implode(', ', array_keys($r['added'])));
                } elseif (empty($r['ok'])) {
                    printf("    ✗ assignment #%-4s %s\n", $a['id'], $r['error']);
                }
                break;
            }
        }
        printf("\n    %-28s %d\n\n", 'assignments completed', $added);
    }
}

if (!$repair && !$learn) {
    echo "  Nothing was written.\n";
    if (count(array_filter($weak, static fn($w) => $w['kind'] === 'repairable')) > 0) {
        echo "    php tools/binding_doctor.php --repair\n";
    }
    if (count(array_filter($weak, static fn($w) => $w['kind'] === 'learnable')) > 0) {
        echo "    php tools/binding_doctor.php --learn\n";
    }
    echo "\n";
}
exit(0);
