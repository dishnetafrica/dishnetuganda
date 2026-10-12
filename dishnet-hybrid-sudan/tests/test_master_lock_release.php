<?php
/**
 * test_master_lock_release.php — cron/master.php releases its own lock once, whatever its jobs do, and its shutdown
 * handler never fails.
 *
 * Two defects, one fixed in each of two releases:
 *
 *   5.18.51 — master closed its lock at the normal end of a run, and its shutdown handler then unlocked the same handle
 *             again: a TypeError on PHP 8, which @ does not silence, at cron/master.php:83 after every completed run
 *             (docs/44 §16.11). The handler is guarded with is_resource(), false for a closed handle.
 *
 *   5.18.92 — master includes every job in its own scope, and twelve of its jobs assign $lockFp and $lockFile — the
 *             names master's own lock had — for their own locks, and close them on their way out (cron_wa_sync.php
 *             and cron_sync.php every minute). Master's normal-end release then unlocked that job's closed handle:
 *
 *                 PHP Fatal error:  Uncaught TypeError: flock(): supplied resource is not a valid stream resource
 *                                   in /data/ucrm/data/plugins/dishnet-hybrid-sudan/cron/master.php:405
 *
 *             main.php catches it; the admin pages' piggyback run, which includes master.php from a shutdown function
 *             with nothing to catch it (public.php), does not — 7 lines on the server from 08 to 10 Oct, under
 *             5.18.89, 5.18.90 and 5.18.91 alike, and every shutdown function after it skipped (docs/07, 5.18.92).
 *             Master's lock is now $_m_lockFp and $_m_lockFile, prefixed as its store and config already are.
 *
 * The lock code is taken from cron/master.php itself — the lock section and the normal-end release, as written — and
 * a job's from cron_wa_sync.php itself, and run in child PHP processes, so this proves the files, not copies typed in:
 *   1. a completed run: no fatal, nothing on stderr, the lock free at the normal end, a later shutdown function runs;
 *   2. a run whose job takes and closes a lock of its own (cron_wa_sync.php's code), run directly and as the piggyback
 *      runs it: no fatal, master's own lock released at the normal end, every later shutdown function runs;
 *   3. a job that leaves its own handle open: the same;
 *   4. a run that dies before the normal release (a job's fatal error that nothing can catch): the handler still
 *      releases, cleanly;
 *   5. a run that finds the lock held: it returns at once, and nothing else runs;
 *   6. controls: 5.18.91's lock, with that job, fails exactly as the server did, directly and under the piggyback;
 *      5.18.50's handler fails as docs/44 recorded;
 *   7. the scope: master.php names no bare $lockFp or $lockFile; no job master runs names $_m_lockFp or $_m_lockFile,
 *      while at least ten of them assign $lockFp (the hazard is real, and the scan reads the files);
 *   8. weakened copies of the fix and of the guard, each caught.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d = ''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if ($d !== '') echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d = ''): void { $c ? ok($m) : bad($m, $d); }

$master = (string)file_get_contents($root . '/cron/master.php');
$waSync = (string)file_get_contents($root . '/cron_wa_sync.php');
$tmp = sys_get_temp_dir() . '/dn-master-lock-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);

// ── The two pieces of master.php under test, exactly as written ──────────────
$n1 = preg_match_all("/\\\$_m_lockFp = fopen\\(\\\$_m_lockFile, 'w\\+'\\);.*?\\nregister_shutdown_function\\(function\\(\\) use \\(\\\$_m_lockFp, \\\$_m_lockFile\\) \\{.*?\\n\\}\\);/s", $master, $m1);
$n2 = preg_match_all("/\\n\\/\\/ ── Release lock[^\\n]*\\n(?:\\/\\/[^\\n]*\\n)*(if \\(is_resource\\(\\\$_m_lockFp\\)\\) \\{\\n    flock\\(\\\$_m_lockFp, LOCK_UN\\);\\n    fclose\\(\\\$_m_lockFp\\);\\n\\})\\n/", $master, $m2);
// ── A job's own lock, exactly as cron_wa_sync.php (every minute) writes it ───
$n3 = preg_match_all("/\\n(\\\$lockFile = \\\$dataDir \\. '\\/cron_wa_sync\\.lock';\\n\\\$lockFp   = fopen\\(\\\$lockFile, 'w\\+'\\);\\nif \\(!flock\\(\\\$lockFp, LOCK_EX \\| LOCK_NB\\)\\) \\{\\n    fclose\\(\\\$lockFp\\);\\n    return;\\n\\})\\n/", $waSync, $m3);
$n4 = substr_count($waSync, "\nflock(\$lockFp, LOCK_UN);\nfclose(\$lockFp);\n");

echo "\nThe lock code, read from cron/master.php and cron_wa_sync.php\n";
is_($n1 === 1, 'master\'s lock section (open, take, register the shutdown handler), by its own names, is found exactly once', "found {$n1}");
is_($n2 === 1, 'master\'s normal-end release of its own handle (is_resource, unlock, close) is found exactly once', "found {$n2}");
is_($n3 === 1, 'cron_wa_sync.php takes its own lock into $lockFp and $lockFile, exactly once', "found {$n3}");
is_($n4 >= 1, 'and releases it at its end by unlocking and closing $lockFp', "found {$n4}");
if ($n1 !== 1 || $n2 !== 1 || $n3 !== 1 || $n4 < 1) { echo "\n{$pass} passed, {$fail} failed\n"; exit(1); }
$LOCK    = $m1[0][0];
$RELEASE = $m2[1][0];
$JOBLOCK = $m3[1][0];
is_(strpos($LOCK, 'if (is_resource($_m_lockFp)) {') !== false, 'the handler releases only a handle that is still open (is_resource)');

/** A job file, run by master with a bare include in master's scope, as cron/master.php runs every job. */
function job_source(string $kind, string $jobLock): string
{
    switch ($kind) {
        case 'none':  return "<?php\necho \"JOB-RAN\\n\";\n";
        case 'wa':    return "<?php\n" . $jobLock . "\necho \"JOB-RAN\\n\";\nflock(\$lockFp, LOCK_UN);\nfclose(\$lockFp);\n";
        case 'open':  return "<?php\n" . $jobLock . "\necho \"JOB-RAN\\n\";\n";
        // A fatal no try/catch can catch, as a job's memory exhaustion or time limit is (master's comment on claiming the slot).
        case 'crash': return "<?php\necho \"JOB-RAN\\n\";\nini_set('memory_limit', '16M');\n\$dn_test_job_that_crashes = str_repeat('x', 64 * 1024 * 1024);\n";
    }
    throw new RuntimeException($kind);
}

/** Master as it runs: take the lock, include the job in this scope, release at the end, then report the lock. */
function master_model(string $lock, string $release): string
{
    return "<?php\nerror_reporting(E_ALL);\nini_set('display_errors', 'stderr');\n"
         . "\$dataDir = (string)getenv('DN_T_DIR');\n\$_m_lockFile_t = (string)getenv('DN_T_LOCK');\n"
         . "\$_m_lockFile = \$_m_lockFile_t;\n\$lockFile = \$_m_lockFile_t;\n"
         . $lock . "\n"
         . "// What a store opened by a job registers after the handler: SqliteStore's WAL checkpoint.\n"
         . "register_shutdown_function(function () { echo \"LATER-SHUTDOWN-RAN\\n\"; });\n"
         . "try {\n    include (string)getenv('DN_T_JOB');\n} catch (\\Throwable \$e) {\n    echo 'MASTER-CAUGHT ' . \$e->getMessage() . \"\\n\";\n}\n"
         . $release . "\n"
         . "echo \"END-REACHED\\n\";\n"
         . "\$_t_h = fopen(\$_m_lockFile_t, 'c');\n"
         . "\$_t_free = \$_t_h !== false && flock(\$_t_h, LOCK_EX | LOCK_NB);\n"
         . "if (\$_t_free) flock(\$_t_h, LOCK_UN);\nif (\$_t_h !== false) fclose(\$_t_h);\n"
         . "echo \$_t_free ? \"LOCK-FREE-AT-END\\n\" : \"LOCK-HELD-AT-END\\n\";\n";
}

/** The admin pages' piggyback: public.php includes master.php from a shutdown function, with no try/catch. */
function piggyback_entry(): string
{
    return "<?php\nerror_reporting(E_ALL);\nini_set('display_errors', 'stderr');\n"
         . "register_shutdown_function(function () {\n    @include (string)getenv('DN_T_MASTER');\n});\n"
         . "echo \"PAGE-SENT\\n\";\n";
}

/** @return array{0:int,1:string,2:string} exit code, stdout, stderr */
function run_case(string $dir, string $name, string $masterSrc, string $jobKind, string $jobLock, string $lockFile, bool $piggyback): array
{
    $m = $dir . '/' . $name . '-master.php';
    $j = $dir . '/' . $name . '-job.php';
    file_put_contents($m, $masterSrc);
    file_put_contents($j, job_source($jobKind, $jobLock));
    $script = $m;
    if ($piggyback) { $script = $dir . '/' . $name . '-entry.php'; file_put_contents($script, piggyback_entry()); }
    $env = ['DN_T_DIR' => $dir, 'DN_T_LOCK' => $lockFile, 'DN_T_JOB' => $j, 'DN_T_MASTER' => $m, 'PATH' => (string)getenv('PATH')];
    $p = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir, $env);
    $out = (string)stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]); fclose($pipes[2]);
    return [proc_close($p), $out, $err];
}

function lock_is_free(string $lockFile): bool
{
    $h = fopen($lockFile, 'c');
    $free = $h !== false && flock($h, LOCK_EX | LOCK_NB);
    if ($h !== false) { if ($free) flock($h, LOCK_UN); fclose($h); }
    return $free;
}

$FLOCK_FATAL = 'flock(): supplied resource is not a valid stream resource';
$current = master_model($LOCK, $RELEASE);
$seq = 0;
/** One clean run: exit 0, nothing on stderr, the job ran, the end reached, the lock free there and after. */
function clean_run(string $label, string $masterSrc, string $jobKind, bool $piggyback): void
{
    global $tmp, $JOBLOCK, $seq, $FLOCK_FATAL;
    $seq++;
    $lf = $tmp . "/cron_master_{$seq}.lock";
    [$rc, $out, $err] = run_case($tmp, "c{$seq}", $masterSrc, $jobKind, $JOBLOCK, $lf, $piggyback);
    $how = $piggyback ? 'under the piggyback' : 'directly';
    is_($rc === 0, "{$label}, {$how}: the process exits 0", "exit {$rc}; stderr: " . trim($err));
    is_(trim($err) === '', "{$label}, {$how}: nothing on stderr — no fatal, no warning", trim($err));
    is_(strpos($err, $FLOCK_FATAL) === false, "{$label}, {$how}: no \"{$FLOCK_FATAL}\"");
    is_(strpos($out, 'JOB-RAN') !== false && strpos($out, 'END-REACHED') !== false, "{$label}, {$how}: the job ran and master's normal end was reached", trim($out));
    is_(strpos($out, 'LOCK-FREE-AT-END') !== false, "{$label}, {$how}: master's own lock is released at its normal end, not left to the shutdown", trim($out));
    is_(strpos($out, 'LATER-SHUTDOWN-RAN') !== false, "{$label}, {$how}: a shutdown function registered after master's handler still runs");
    is_(lock_is_free($lf), "{$label}, {$how}: the lock is free afterwards");
}

// ── 1–3. Completed runs ──────────────────────────────────────────────────────
echo "\n1. A run whose job touches no lock\n";
clean_run('no lock in the job', $current, 'none', false);
clean_run('no lock in the job', $current, 'none', true);

echo "\n2. A run whose job takes and closes its own lock, as cron_wa_sync.php does every minute (the server's case)\n";
clean_run('cron_wa_sync.php\'s lock', $current, 'wa', false);
clean_run('cron_wa_sync.php\'s lock', $current, 'wa', true);

echo "\n3. A run whose job leaves its own handle open\n";
clean_run('a job handle left open', $current, 'open', false);
clean_run('a job handle left open', $current, 'open', true);

// ── 4. A run that dies before the normal release ─────────────────────────────
echo "\n4. A run that dies before the normal release: the handler releases, cleanly\n";
$lf4 = $tmp . '/cron_master_crash.lock';
[$rc, $out, $err] = run_case($tmp, 'crash', $current, 'crash', $JOBLOCK, $lf4, false);
is_($rc !== 0, 'the process ends in the job\'s own fatal error (exit ' . $rc . ')');
is_(strpos($err, 'Allowed memory size') !== false && strpos($out, 'MASTER-CAUGHT') === false, 'the fatal reported is the job\'s, and nothing caught it', trim($err));
is_(strpos($out, 'END-REACHED') === false, 'the normal end was never reached');
is_(strpos($err, $FLOCK_FATAL) === false, 'the handler, which released the lock, did not fail', trim($err));
is_(strpos($out, 'LATER-SHUTDOWN-RAN') !== false, 'the later shutdown function still runs');
is_(lock_is_free($lf4), 'the lock is free afterwards');

// ── 5. A run that finds the lock held ────────────────────────────────────────
echo "\n5. A run that finds the lock held: it returns at once\n";
$lf5 = $tmp . '/cron_master_held.lock';
$holder = fopen($lf5, 'c');
is_($holder !== false && flock($holder, LOCK_EX | LOCK_NB), 'control: this test holds the lock, as a running master would');
[$rc, $out, $err] = run_case($tmp, 'held', $current, 'wa', $JOBLOCK, $lf5, false);
is_($rc === 0 && trim($err) === '', 'the second run exits 0, silently', "exit {$rc}; stderr: " . trim($err));
is_(strpos($out, 'JOB-RAN') === false && strpos($out, 'LATER-SHUTDOWN-RAN') === false, 'it ran no job and registered nothing');
is_(!lock_is_free($lf5), 'the first run\'s lock is untouched');
if ($holder !== false) { flock($holder, LOCK_UN); fclose($holder); }

// ── 6. Controls ──────────────────────────────────────────────────────────────
echo "\n6. Controls: the code before the fix fails exactly as the server did\n";
// 5.18.91's lock: the same code by the old names, and its unguarded release.
$OLD_LOCK    = str_replace(['$_m_lockFp', '$_m_lockFile'], ['$lockFp', '$lockFile'], $LOCK);
$OLD_RELEASE = "flock(\$lockFp, LOCK_UN);\nfclose(\$lockFp);";
$old91 = master_model($OLD_LOCK, $OLD_RELEASE);
is_(strpos($OLD_LOCK, '$_m_') === false && substr_count($OLD_LOCK, '$lockFp') >= 7, 'control: 5.18.91\'s lock is rebuilt by its old names');
[$rc, $out, $err] = run_case($tmp, 'old91', $old91, 'wa', $JOBLOCK, $tmp . '/cron_master_old91.lock', false);
is_(strpos($out, 'MASTER-CAUGHT') === false && strpos($err, 'Uncaught TypeError: ' . $FLOCK_FATAL) !== false && $rc !== 0,
    'directly: 5.18.91\'s release, after cron_wa_sync.php\'s lock, ends in the server\'s line "Uncaught TypeError: ' . $FLOCK_FATAL . '"', "exit {$rc}; " . trim($err));
is_(strpos($out, 'JOB-RAN') !== false && strpos($out, 'END-REACHED') === false, 'directly: after the job had run — the fatal is the release itself');
[$rc, $out, $err] = run_case($tmp, 'old91p', $old91, 'wa', $JOBLOCK, $tmp . '/cron_master_old91p.lock', true);
is_(strpos($err, 'Uncaught TypeError: ' . $FLOCK_FATAL) !== false, 'under the piggyback: the same fatal, as php-fpm logged it on the server', trim($err));
is_(strpos($out, 'LATER-SHUTDOWN-RAN') === false, 'under the piggyback: and the shutdown function registered after it was skipped');
[$rc, $out, $err] = run_case($tmp, 'old91n', $old91, 'none', $JOBLOCK, $tmp . '/cron_master_old91n.lock', true);
is_($rc === 0 && trim($err) === '' && strpos($out, 'LATER-SHUTDOWN-RAN') !== false,
    'control on the control: 5.18.91\'s lock with a job that takes no lock runs clean — the job\'s lock is what breaks it', "exit {$rc}; " . trim($err));

// 5.18.50's handler: unguarded, and run after master closed its own lock.
$OLD_HANDLER = "register_shutdown_function(function() use (\$lockFp, \$lockFile) {\n"
             . "    @flock(\$lockFp, LOCK_UN);\n"
             . "    @fclose(\$lockFp);\n"
             . "    @touch(\$lockFile); // reset mtime\n"
             . "});";
$old50 = preg_replace("/register_shutdown_function\\(function\\(\\) use \\(\\\$lockFp, \\\$lockFile\\) \\{.*?\\n\\}\\);/s", $OLD_HANDLER, $OLD_LOCK, -1, $nOld);
is_($nOld === 1, 'the 5.18.50 handler replaces 5.18.51\'s exactly once', "replaced {$nOld}");
[$rc, $out, $err] = run_case($tmp, 'old50', master_model((string)$old50, $OLD_RELEASE), 'none', $JOBLOCK, $tmp . '/cron_master_old50.lock', false);
is_(strpos($err, 'Uncaught TypeError: ' . $FLOCK_FATAL) !== false, '5.18.50\'s handler ends in its fatal at shutdown (docs/44 §16.11)', trim($err));
is_(strpos($out, 'END-REACHED') !== false && strpos($out, 'LATER-SHUTDOWN-RAN') === false, 'after the normal end was reached, and the later shutdown function was skipped');

// ── 7. The scope ─────────────────────────────────────────────────────────────
echo "\n7. The scope master shares with its jobs\n";
$code = implode("\n", array_filter(explode("\n", $master), static fn ($l) => strpos(ltrim($l), '//') !== 0));
is_(preg_match_all('/\$lockFp\b|\$lockFile\b/', $code) === 0, 'master.php names no bare $lockFp or $lockFile outside its comments — a job\'s can never meet its own');
// The jobs master dispatches: its list, without the ones commented out of it.
preg_match_all("/'script'\\s*=>\\s*(__DIR__|dirname\\(__DIR__\\))\\s*\\.\\s*'([^']+)'/", $code, $jm, PREG_SET_ORDER);
$jobs = [];
foreach ($jm as $j) { $jobs[] = ($j[1] === '__DIR__' ? $root . '/cron' : $root) . $j[2]; }
$jobs = array_values(array_unique($jobs));
$exist = array_values(array_filter($jobs, 'is_file'));
is_(count($exist) >= 40, 'the scan reads master\'s job list: ' . count($exist) . ' job scripts found on disk', implode(', ', array_diff($jobs, $exist)));
$prefixed = []; $assigning = [];
foreach ($exist as $f) {
    $src = (string)file_get_contents($f);
    if (preg_match('/\$_m_lockFp\b|\$_m_lockFile\b/', $src)) $prefixed[] = basename($f);
    if (preg_match('/^\$lockFp\s*=/m', $src)) $assigning[] = basename($f);
}
is_($prefixed === [], 'no job master runs names $_m_lockFp or $_m_lockFile', implode(', ', $prefixed));
is_(count($assigning) >= 10, 'control: ' . count($assigning) . ' of them assign $lockFp at their top level — the hazard the prefix removes is real: ' . implode(', ', $assigning));
is_(in_array('cron_wa_sync.php', $assigning, true) && in_array('cron_sync.php', $assigning, true), 'control: among them the two that run every minute, cron_wa_sync.php and cron_sync.php');

// ── 8. Weakened copies ───────────────────────────────────────────────────────
echo "\n8. Weakened copies of the fix and of the guard, each caught by the server's case (2) or a completed run (1)\n";
$weak = [
    'the release by the jobs\' name, unguarded (5.18.91\'s)' => [$LOCK, "flock(\$lockFp, LOCK_UN);\nfclose(\$lockFp);", 'wa'],
    'the release by the jobs\' name, guarded'               => [$LOCK, str_replace('$_m_lockFp', '$lockFp', $RELEASE), 'wa'],
    'the lock by the jobs\' name, the release by master\'s'  => [str_replace('$_m_lockFp', '$lockFp', $LOCK), $RELEASE, 'none'],
    'the handler capturing the jobs\' name'                  => [str_replace('use ($_m_lockFp, $_m_lockFile)', 'use ($lockFp, $_m_lockFile)', $LOCK), $RELEASE, 'none'],
    'the handler with no is_resource guard'                  => [str_replace('if (is_resource($_m_lockFp)) {', 'if (true) {', $LOCK), $RELEASE, 'none'],
    'the handler guarded by truthiness'                      => [str_replace('if (is_resource($_m_lockFp)) {', 'if ($_m_lockFp) {', $LOCK), $RELEASE, 'none'],
];
foreach ($weak as $label => [$wLock, $wRelease, $kind]) {
    if ($wLock === $LOCK && $wRelease === $RELEASE) { bad("weakened copy \"{$label}\": it differs from the fix"); continue; }
    $seq++;
    $lf = $tmp . "/cron_master_w{$seq}.lock";
    [$rc, $out, $err] = run_case($tmp, "w{$seq}", master_model($wLock, $wRelease), $kind, $JOBLOCK, $lf, true);
    $caught = $rc !== 0 || trim($err) !== '' || strpos($out, 'LOCK-FREE-AT-END') === false || strpos($out, 'LATER-SHUTDOWN-RAN') === false;
    is_($caught, "weakened copy caught: {$label}", "exit {$rc}; out: " . trim(str_replace("\n", ' ', $out)) . '; err: ' . trim($err));
}

// ── cleanup ──────────────────────────────────────────────────────────────────
foreach ((array)glob($tmp . '/*') as $f) @unlink((string)$f);
@rmdir($tmp);
is_(!is_dir($tmp), 'the test left nothing behind');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
