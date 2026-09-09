<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * cron_status.php — which scheduled jobs are actually running?
 *
 *   php tools/cron_status.php          every job, most overdue first
 *   php tools/cron_status.php --all    include jobs that are on time
 *
 * Written after a Starlink session expired while the status screen showed
 * state ACTIVE and failures 0. Both were true: the keep-alive had never run,
 * so nothing had touched Starlink to discover otherwise. A job that is never
 * dispatched leaves no trace in its own logs — only here, as a last-run
 * timestamp that stopped moving.
 *
 * master.php stops dispatching when its execution budget is spent, so a slow
 * job starves every job registered after it. When one job is hours overdue
 * and the ones above it are current, that is what you are looking at.
 *
 * Reads only.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/bootstrap_data.php';

$dataDir  = getenv('DN_DATA_DIR') ?: getDataDir($root);
$schedule = SqliteStore::create($dataDir)->load('master_schedule.json') ?? [];

// The job list lives inside master.php's array. Including that file would run
// every job on this machine, so the registration lines are read as text — the
// same reason the ordering test reads them rather than importing them.
$src = (string)file_get_contents($root . '/cron/master.php');
preg_match_all("/'([a-z0-9_]+)'\s*=>\s*\['interval'\s*=>\s*(\d+)/i", $src, $m, PREG_SET_ORDER);

if ($m === []) { echo "\n  Could not read the job list from cron/master.php\n\n"; exit(1); }

$now  = time();
$all  = in_array('--all', array_slice($argv, 1), true);
$rows = [];
$order = 0;

foreach ($m as $j) {
    $name     = $j[1];
    $interval = (int)$j[2];
    $lastRun  = (int)($schedule[$name]['last_run'] ?? 0);
    $lastAt   = (string)($schedule[$name]['last_run_at'] ?? 'never');

    // last_run = 1 is master.php's seed for "tracked but never run".
    $elapsed  = ($lastRun > 1) ? $now - $lastRun : null;
    $overdue  = ($elapsed === null) ? PHP_INT_MAX : $elapsed - $interval;

    $rows[] = ['name' => $name, 'interval' => $interval, 'at' => $lastAt,
               'elapsed' => $elapsed, 'overdue' => $overdue, 'order' => $order++];
}

/** Most overdue first; registration order breaks ties, since that is the thing
 *  that decides who gets starved when the budget runs out. */
usort($rows, function ($a, $b) {
    return $b['overdue'] <=> $a['overdue'] ?: $a['order'] <=> $b['order'];
});

$ago = function (?int $s): string {
    if ($s === null) return '—';
    if ($s < 90)     return $s . 's';
    if ($s < 5400)   return (int)round($s / 60) . 'm';
    if ($s < 172800) return (int)round($s / 3600) . 'h';
    return (int)round($s / 86400) . 'd';
};

echo "\n  SCHEDULED JOBS — #1 is dispatched first each cycle\n";
echo "  " . str_repeat('─', 72) . "\n";
printf("  %-3s %-20s %9s %9s  %-19s %s\n", '#', 'job', 'every', 'last run', 'at', 'state');

$shown = 0;
foreach ($rows as $r) {
    $late = $r['overdue'] > $r['interval'];          // missed a whole cycle
    if (!$all && !$late) continue;
    $shown++;
    printf("  %-3d %-20s %8ds %9s  %-19s %s\n",
        $r['order'] + 1, $r['name'], $r['interval'], $ago($r['elapsed']), $r['at'],
        $r['elapsed'] === null ? 'NEVER RUN' : ($late ? 'OVERDUE' : ''));
}

if ($shown === 0) {
    echo "\n  Every job is running on schedule.\n";
    echo "  Run with --all to see them.\n\n";
    exit(0);
}

echo "\n  A job that is overdue while the ones registered above it are current\n";
echo "  is being starved by the execution budget, not failing on its own.\n";
echo "  If EVERYTHING is overdue, master.php itself is not being called.\n\n";
exit(0);
