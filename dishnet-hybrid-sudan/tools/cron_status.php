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

// master.php stamps last_run_at after setting its own timezone. Read the same
// one rather than assuming: without this the headline rendered in UTC against
// Juba-stamped rows and reported the same event two hours apart.
$masterSrc = (string)file_get_contents($root . '/cron/master.php');
if (preg_match("/date_default_timezone_set\(\s*'([^']+)'\s*\)/", $masterSrc, $tzm)) {
    date_default_timezone_set($tzm[1]);
}

$dataDir  = cliDataDir($root);
$schedule = SqliteStore::create($dataDir)->load('master_schedule.json') ?? [];

// The job list lives inside master.php's array. Including that file would run
// every job on this machine, so the registration lines are read as text — the
// same reason the ordering test reads them rather than importing them.
$src = $masterSrc;

// Line by line, skipping comments. A regex over the whole file also matched
// the job registrations that are commented OUT — job_assign and wa_bot are
// both disabled — and reported them as real jobs, which shifted every
// dispatch position after them. A tool built to expose misleading output
// should not produce it.
$m = [];
foreach (explode("\n", $src) as $line) {
    $t = ltrim($line);
    if ($t === '' || $t[0] === '#') continue;
    if (strpos($t, '//') === 0 || strpos($t, '*') === 0) continue;
    if (preg_match("/'([a-z0-9_]+)'\s*=>\s*\['interval'\s*=>\s*(\d+)/i", $line, $one)) {
        // Hour-gated jobs are not late when they have not run — master.php
        // skips them until their hour comes round. Listing them as NEVER RUN
        // put eight healthy daily jobs in a report about broken ones.
        $one['hour'] = preg_match("/'run_hour'\s*=>\s*(\d+)/", $line, $h) ? (int)$h[1] : null;
        $one['dow']  = preg_match("/'run_dow'\s*=>\s*(\d+)/",  $line, $d) ? (int)$d[1] : null;
        $m[] = $one;
    }
}

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

    // master.php writes duration_ms = -1 when it claims the slot and replaces
    // it on completion. Still -1 means the job started and never came back —
    // it ended the process. That job is the reason everything below it is
    // stale, and naming it is the whole point of this tool.
    $dur   = array_key_exists('duration_ms', (array)($schedule[$name] ?? []))
           ? (int)$schedule[$name]['duration_ms'] : 0;
    $died  = ($dur < 0);

    $rows[] = ['name' => $name, 'interval' => $interval, 'at' => $lastAt,
               'elapsed' => $elapsed, 'overdue' => $overdue, 'died' => $died,
               'hour' => $j['hour'], 'dow' => $j['dow'], 'order' => $order++];
}

/** Most overdue first; registration order breaks ties, since that is the thing
 *  that decides who gets starved when the budget runs out. */
usort($rows, function ($a, $b) {
    return $b['overdue'] <=> $a['overdue'] ?: $a['order'] <=> $b['order'];
});

$dayName = function (int $d): string {
    $n = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    return $n[$d] ?? (string)$d;
};

$ago = function (?int $s): string {
    if ($s === null) return '—';
    if ($s < 90)     return $s . 's';
    if ($s < 5400)   return (int)round($s / 60) . 'm';
    if ($s < 172800) return (int)round($s / 3600) . 'h';
    return (int)round($s / 86400) . 'd';
};

// The single most useful number: when did ANY job last run? master.php saves
// the schedule after each job, so the newest timestamp here is the last time
// a cycle got at least that far. If it is days old, nothing is running.
$newest = 0;
foreach ($rows as $r) { if ($r['elapsed'] !== null) $newest = max($newest, $now - $r['elapsed']); }

echo "\n  SCHEDULED JOBS — #1 is dispatched first each cycle\n";
if ($newest === 0) {
    echo "  Nothing has ever run.\n";
} else {
    echo "  Last dispatch of any job: " . date('Y-m-d H:i:s', $newest)
       . "  (" . $ago($now - $newest) . " ago, " . date_default_timezone_get() . ")\n";
}
echo "  " . str_repeat('─', 72) . "\n";
printf("  %-3s %-20s %9s %9s  %-19s %s\n", '#', 'job', 'every', 'last run', 'at', 'state');

$shown = 0;
foreach ($rows as $r) {
    $daily = ($r['hour'] !== null);
    $late  = !$daily && $r['overdue'] > $r['interval'];   // missed a whole cycle
    if (!$all && !$late && !$r['died']) continue;
    $shown++;
    printf("  %-3d %-20s %8ds %9s  %-19s %s\n",
        $r['order'] + 1, $r['name'], $r['interval'], $ago($r['elapsed']), $r['at'],
        $r['died'] ? 'DID NOT FINISH'
          : ($daily ? 'waits ' . sprintf('%02d:00', $r['hour'])
                    . ($r['dow'] !== null ? ' ' . $dayName($r['dow']) : '')
                    : ($r['elapsed'] === null ? 'NEVER RUN' : ($late ? 'OVERDUE' : ''))));
}

$waiting = count(array_filter($rows, function ($r) { return $r['hour'] !== null; }));
if (!$all && $waiting > 0) {
    echo "\n  " . $waiting . " daily job(s) waiting for their hour — not late. --all to see them.\n";
}

if ($shown === 0) {
    echo "\n  Every job is running on schedule.\n";
    echo "  Run with --all to see them.\n\n";
    exit(0);
}

$died = array_filter($rows, function ($r) { return $r['died']; });
if ($died !== []) {
    echo "\n  DID NOT FINISH means the job started and never returned: it ended\n";
    echo "  master.php's process, so nothing after it ran that cycle. Look there\n";
    echo "  first — everything stale below it is a symptom, not a cause.\n";
}

echo "\n  If job #1 is current and everything below it is stale, the cycle is\n";
echo "  dying inside a job rather than being starved: master.php includes job\n";
echo "  scripts in its own process, so a top-level exit() in one of them ends\n";
echo "  the whole run — and the try/catch around the include cannot catch it.\n";
echo "  A job overdue while several above it are current is budget starvation.\n";
echo "  If EVERYTHING is stale, master.php itself is not being called.\n\n";
exit(0);
