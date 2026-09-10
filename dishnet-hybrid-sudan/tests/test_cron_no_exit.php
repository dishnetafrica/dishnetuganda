<?php
/**
 * test_cron_no_exit.php — a scheduled script may not call exit().
 *
 * cron/master.php runs jobs with `include`, in its own process:
 *
 *     try { include $_m_scriptPath; }
 *     catch (\Throwable $e) { master_log("ERROR {$_m_name}: " ...); }
 *
 * exit() is not a Throwable. It walks straight past that catch and ends the
 * master process, so every job registered after the offender never runs. The
 * isolation the comments promise — "one failing job never blocks the others"
 * — holds for exceptions and not for this.
 *
 * It fails silently and it fails backwards. The schedule is saved after each
 * job, so the offender never records its own timestamp: it reads as NEVER RUN
 * while being dispatched every single cycle, and the job before it looks
 * perfectly healthy. On this installation identity_worker sat at position two
 * and stopped the entire plugin for five days. Nothing logged an error.
 *
 * Two older crons carry the rule in their headers ("use return; not exit()"),
 * so it was known — it was just never enforced, and four newer scripts,
 * including one I wrote, broke it.
 *
 * return works in both directions: it hands control back to master.php when
 * included, and ends the script when run directly.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

$master = (string)file_get_contents($root . '/cron/master.php');

echo "\nThe rule exists because master.php shares its process with every job\n";
is_(strpos($master, 'include $_m_scriptPath') !== false,
    'jobs are included, not spawned — so exit() in one ends them all',
    'if master.php now runs jobs in subprocesses, this whole file can go');

/** Registrations that are commented out are not jobs. */
$jobs = [];
foreach (explode("\n", $master) as $line) {
    $t = ltrim($line);
    if ($t === '' || $t[0] === '#') continue;
    if (strpos($t, '//') === 0 || strpos($t, '*') === 0) continue;
    if (!preg_match("/'([a-z0-9_]+)'\s*=>\s*\['interval'.*?'script'\s*=>\s*(.+?)\]\s*,/", $line, $m)) continue;
    $expr = $m[2];
    $path = str_replace(["__DIR__ . '", "dirname(__DIR__) . '", "'"], ['/cron', '', ''], $expr);
    $jobs[$m[1]] = $root . '/' . ltrim(trim($path), '/');
}

is_(count($jobs) > 20, 'the job list parses (' . count($jobs) . ' scheduled)');

echo "\nNo scheduled script can end the cycle\n";

/**
 * Tokenised, not grepped. Two crons document the rule in a comment that says
 * the word exit(), and a regex counts those as violations — which is how a
 * check like this ends up ignored.
 */
$exitsIn = function (string $file): int {
    $n = 0;
    foreach (token_get_all((string)file_get_contents($file)) as $tok) {
        if (is_array($tok) && $tok[0] === T_EXIT) $n++;
    }
    return $n;
};

$missing = [];
$guilty  = [];
foreach ($jobs as $name => $file) {
    if (!is_file($file)) { $missing[] = $name; continue; }
    $n = $exitsIn($file);
    if ($n > 0) $guilty[] = $name . ' (' . $n . ' in ' . basename($file) . ')';
}

is_($missing === [], 'every scheduled script exists',
    'missing: ' . implode(', ', $missing));
is_($guilty === [], 'none of them calls exit() or die()',
    implode('; ', $guilty));

echo "\nAnd if one dies anyway, it costs a cycle rather than everything\n";
// exit() is not the only way to end a process. A set_time_limit fatal and
// memory exhaustion are both E_ERROR, uncatchable, and a scheduled script can
// hit either without anyone having written exit() anywhere. jobs_cache fetches
// every uCRM scheduling job since January under a 60s limit and stopped the
// cycle at position 21 the same morning the exit() bug was fixed.
//
// So master.php claims the slot before running the job. A job that never
// returns is already stamped, is not due again until its interval elapses,
// and the jobs behind it get their turn on the next cycle.
$runAt   = strpos($master, 'master_log("RUN {$_m_name}")');
$include = strpos($master, 'include $_m_scriptPath');
$preSave = strpos($master, "'duration_ms' => -1");

is_($preSave !== false, 'the slot is claimed with duration_ms = -1');
is_($preSave !== false && $runAt !== false && $include !== false
    && $preSave > $runAt && $preSave < $include,
    'and claimed BEFORE the job is included, which is the whole point',
    'claiming it afterwards is what let one job block eighteen others');

$saveCalls = substr_count($master, "save('master_schedule.json'");
is_($saveCalls >= 2, 'the real duration is still written when a job finishes',
    'found ' . $saveCalls . ' saves; expected a claim and a completion');

echo "\nThe keep-alive in particular, since it is dispatched first\n";
$ka = $root . '/cron/starlink_keepalive.php';
is_(is_file($ka) && $exitsIn($ka) === 0,
    'starlink_keepalive.php returns rather than exits',
    'at position one, an exit() here stops the whole plugin');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
