<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * usage_collect.php — collect Starlink usage now, and say what happened to each kit.
 *
 *   php tools/usage_collect.php           show what would be collected and why
 *   php tools/usage_collect.php --save    collect and write it
 *
 * The cron does this hourly and logs one line. This is the same collection
 * with its working shown: every live assignment, whether it was read, and when
 * it was not, which of the several reasons applies — no session for that
 * account, no service line recorded, a dead cookie, or a line Starlink has no
 * billing cycle for yet. Those are different problems and only one of them is
 * fixed by importing a cookie.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/EquipmentAssignment.php';
require_once $root . '/lib/StarlinkSessionStore.php';
require_once $root . '/lib/StarlinkUsage.php';
require_once $root . '/lib/KitSlMap.php';
require_once $root . '/lib/StarlinkLineDiscovery.php';

$save     = in_array('--save', array_slice($argv, 1), true);
$discover = in_array('--discover', array_slice($argv, 1), true);
$dataDir = cliDataDir($root);
$config  = PluginConfig::load($root, $dataDir);
$session = new StarlinkSessionStore($root, $dataDir);

echo "\n  STARLINK USAGE COLLECTION — " . gmdate('Y-m-d H:i') . " UTC\n";
echo "  " . str_repeat('─', 70) . "\n\n";

$held = $session->accounts();
printf("    sessions held    %s\n", $held === [] ? 'NONE' : implode(', ', $held));
if ($held === []) {
    echo "\n  No Starlink session imported. Switch to each account on starlink.com,\n";
    echo "  copy the cookie header, and import — each import adds an account:\n\n";
    echo "    docker exec -it ucrm php tools/starlink_session.php --import\n\n";
    exit(1);
}

$ea   = EquipmentAssignment::fromStore(SqliteStore::create($dataDir));
$live = $ea->liveAssignments();
printf("    live assignments %d\n", count($live));
if ($live === []) {
    echo "\n  Nothing is bound to a customer, so there is nothing to ask about.\n\n";
    exit(0);
}

// Gap-fill exactly as the cron does, so what this shows is what the cron will
// do rather than a different thing that looks similar. The cron asks Starlink
// every run; here that costs a request per account, so it is opt-in and the
// stored answer is used otherwise.
if ($discover) {
    $d = (new StarlinkLineDiscovery($session, $config))->discover();
    foreach ($d['report'] as $r) {
        printf("    %-28s %s\n", $r['account'] . ($r['status'] === 'no_terminal'
            ? ' ' . substr((string)($r['line'] ?? ''), 0, 14) : ''), $r['why']);
    }
    foreach ($d['ambiguous'] as $l => $ks) {
        printf("    ⚠ %s has %d terminals (%s) — left for a person, not guessed\n",
               $l, count($ks), implode(', ', $ks));
    }
    if ($d['pairs'] !== []) {
        $w = StarlinkLineDiscovery::save($dataDir, $d['pairs']);
        printf("    discovered       %d pair(s)%s\n", count($d['pairs']),
               empty($w['ok']) ? ' — NOT saved: ' . $w['why'] : ', saved');
    } else {
        echo "    discovered       nothing — see the lines above for why\n";
    }
}

$map = new KitSlMap($dataDir);
$map->overlay(StarlinkLineDiscovery::load($dataDir));
printf("    from Starlink    %s\n", $map->discoveredCount() > 0
    ? $map->discoveredCount() . ' pair(s)'
      . (StarlinkLineDiscovery::discoveredAt($dataDir) !== ''
         ? ', asked ' . StarlinkLineDiscovery::discoveredAt($dataDir) : '')
    : 'none stored — run with --discover to ask');
printf("    manual map       %s\n", $map->count() > 0
    ? $map->count() . ' pair(s)' . ($map->savedAt() !== '' ? ', saved ' . $map->savedAt() : '')
    : 'empty — Admin → Starlink Sessions → KIT → Service Line map');
if ($map->count() > 0 || $map->discoveredCount() > 0) {
    $gap  = $map->apply($live, new StarlinkServiceState());
    $live = $gap['assignments'];
    if ($gap['filled_lines'] || $gap['filled_accounts']) {
        printf("    gap-filled       %d service line(s) (%d typed, %d from Starlink), %d account(s)\n",
               $gap['filled_lines'], $gap['filled_typed'], $gap['filled_discovered'],
               $gap['filled_accounts']);
    }
    foreach ($gap['conflicts'] as $c) {
        printf("    ⚠ %s: typed map says %s, Starlink reports %s — using the typed one\n",
               $c['kit'], $c['typed'], $c['discovered']);
    }
    foreach ($gap['disagreements'] as $d) {
        printf("    ⚠ %s: map says %s, install record says %s — keeping the install record\n",
               $d['kit'], $d['map'], $d['assignment']);
    }
    if ($gap['unused'] !== []) {
        printf("    %d mapped kit(s) no live assignment claims: %s\n",
               count($gap['unused']), implode(', ', array_slice($gap['unused'], 0, 6))
             . (count($gap['unused']) > 6 ? ' …' : ''));
    }
}
echo "\n";

$res = (new StarlinkUsage($session, $config))->collect($live);

$icon = ['collected' => '✔', 'failed' => '✘', 'no_session' => '⚠',
         'skipped' => '·', 'no_cycles' => '·'];
foreach ($res['report'] as $r) {
    printf("    %s %-20s %-26s %s\n", $icon[$r['status']] ?? '?',
        $r['kit'], $r['line'] !== '' ? $r['line'] : '(no line)', $r['why']);
}

if ($res['accounts'] !== []) {
    echo "\n    per account:\n";
    foreach ($res['accounts'] as $acct => $outcome) printf("      %-28s %s\n", $acct, $outcome);
}

$rows = $res['rows'];
printf("\n    %d row(s) collected\n", count($rows));

if (!$save) {
    echo "\n  Nothing written. Add --save to write them where the Fleet screen reads:\n";
    echo "    php tools/usage_collect.php --save\n";
    echo "  Add --discover to ask Starlink which kit is on which line first.\n\n";
    exit($rows === [] ? 1 : 0);
}

$w = (new StarlinkUsage($session, $config))->save($dataDir, $rows);
if (empty($w['ok'])) {
    echo "\n  NOT WRITTEN: " . $w['why'] . "\n\n";
    exit(1);
}
echo "\n  ✔ {$w['written']} row(s) written to {$w['path']}\n";
echo "  The Fleet screen reads this in preference to the data-report plugin's.\n\n";
exit(0);
