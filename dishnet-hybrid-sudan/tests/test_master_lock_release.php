<?php
/**
 * test_master_lock_release.php — cron/master.php releases its lock once, and its shutdown handler never fails.
 *
 * master.php closes its lock at the normal end of a run (flock LOCK_UN, then fclose), and until 5.18.51 its shutdown
 * handler unlocked the same handle again. Unlocking a closed handle is a TypeError on PHP 8, which @ does not
 * silence, so every completed run ended in
 *
 *     PHP Fatal error:  Uncaught TypeError: flock(): supplied resource is not a valid stream resource
 *                       in /data/ucrm/data/plugins/dishnet-hybrid-sudan/cron/master.php:83
 *
 * — 4 to 9 times an hour on the server since at least 26 Sep — and every shutdown function registered after the
 * handler was skipped (docs/44 §16.11). 5.18.51 guards the handler with is_resource(), which is false for a closed
 * handle.
 *
 * The lock code is taken from cron/master.php itself — the lock section and the normal-end release, as written — and
 * run in a child PHP process, so this proves the file, not a copy typed into the test:
 *   1. a completed run: no fatal, a shutdown function registered later still runs, exit 0;
 *   2. a run that dies before the normal release (a job's fatal error): the handler still releases, cleanly;
 *   3. a run that finds the lock held: it returns at once, and nothing else runs;
 *   4. the control: 5.18.50's handler, run the same way, fails exactly as the server did;
 *   5. weakened copies of the guard each fail.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d = ''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if ($d !== '') echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d = ''): void { $c ? ok($m) : bad($m, $d); }

$master = (string)file_get_contents($root . '/cron/master.php');
$tmp = sys_get_temp_dir() . '/dn-master-lock-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);

// ── The two pieces of master.php under test, exactly as written ──────────────
$n1 = preg_match_all("/\\\$lockFp = fopen\\(\\\$lockFile, 'w\\+'\\);.*?\\nregister_shutdown_function\\(function\\(\\) use \\(\\\$lockFp, \\\$lockFile\\) \\{.*?\\n\\}\\);/s", $master, $m1);
$n2 = preg_match_all("/\\n\\/\\/ ── Release lock[^\\n]*\\n(flock\\(\\\$lockFp, LOCK_UN\\);\\nfclose\\(\\\$lockFp\\);)\\n/", $master, $m2);

echo "\nThe lock code, read from cron/master.php\n";
is_($n1 === 1, 'the lock section (open, take, register the shutdown handler) is found exactly once', "found {$n1}");
is_($n2 === 1, 'the normal-end release (unlock, then close) is still there, exactly once', "found {$n2}");
if ($n1 !== 1 || $n2 !== 1) { echo "\n{$pass} passed, {$fail} failed\n"; exit(1); }
$LOCK    = $m1[0][0];
$RELEASE = $m2[1][0];
is_(strpos($LOCK, 'if (is_resource($lockFp)) {') !== false, 'the handler releases only a handle that is still open (is_resource)');

/** The same code, run as master.php runs it: take the lock, run "the jobs", release at the end. */
function child_script(string $lock, string $release): string
{
    return "<?php\nerror_reporting(E_ALL);\nini_set('display_errors', 'stderr');\n"
         . "\$lockFile = \$argv[1];\n\$mode = \$argv[2];\n"
         . $lock . "\n"
         . "// What a store opened by a job registers after the handler: SqliteStore's WAL checkpoint.\n"
         . "register_shutdown_function(function () { echo \"LATER-SHUTDOWN-RAN\\n\"; });\n"
         . "echo \"JOBS-RAN\\n\";\n"
         . "if (\$mode === 'crash') { dn_test_job_that_crashes(); }\n"
         . $release . "\n"
         . "echo \"END-REACHED\\n\";\n";
}

/** @return array{0:int,1:string,2:string} exit code, stdout, stderr */
function run_child(string $dir, string $name, string $source, string $lockFile, string $mode): array
{
    $script = $dir . '/' . $name . '.php';
    file_put_contents($script, $source);
    $p = proc_open([PHP_BINARY, $script, $lockFile, $mode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
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
$current = child_script($LOCK, $RELEASE);

// ── 1. A completed run ───────────────────────────────────────────────────────
echo "\n1. A run that completes: released once, and nothing fails at shutdown\n";
$lf = $tmp . '/cron_master.lock';
[$rc, $out, $err] = run_child($tmp, 'normal', $current, $lf, 'normal');
is_($rc === 0, 'the process exits 0', "exit {$rc}; stderr: " . trim($err));
is_(strpos($out, 'JOBS-RAN') !== false && strpos($out, 'END-REACHED') !== false, 'the jobs ran and the normal end was reached');
is_(strpos($err, $FLOCK_FATAL) === false, 'no "' . $FLOCK_FATAL . '" at shutdown', trim($err));
is_(stripos($err, 'fatal') === false && stripos($err, 'TypeError') === false, 'no fatal error of any kind', trim($err));
is_(strpos($out, 'LATER-SHUTDOWN-RAN') !== false, 'a shutdown function registered after the handler still runs');
is_(lock_is_free($lf), 'the lock is free afterwards');

// ── 2. A run that dies before the normal release ─────────────────────────────
echo "\n2. A run that dies before the normal release: the handler releases, cleanly\n";
$lf2 = $tmp . '/cron_master_crash.lock';
[$rc, $out, $err] = run_child($tmp, 'crash', $current, $lf2, 'crash');
is_($rc !== 0, 'the process ends in the job\'s own fatal error (exit ' . $rc . ')');
is_(strpos($err, 'dn_test_job_that_crashes') !== false, 'the fatal reported is the job\'s', trim($err));
is_(strpos($out, 'END-REACHED') === false, 'the normal end was never reached');
is_(strpos($err, $FLOCK_FATAL) === false, 'the handler, which released the lock, did not fail', trim($err));
is_(strpos($out, 'LATER-SHUTDOWN-RAN') !== false, 'the later shutdown function still runs');
is_(lock_is_free($lf2), 'the lock is free afterwards');

// ── 3. A run that finds the lock held ────────────────────────────────────────
echo "\n3. A run that finds the lock held: it returns at once\n";
$lf3 = $tmp . '/cron_master_held.lock';
$holder = fopen($lf3, 'c');
is_($holder !== false && flock($holder, LOCK_EX | LOCK_NB), 'control: this test holds the lock, as a running master would');
[$rc, $out, $err] = run_child($tmp, 'held', $current, $lf3, 'normal');
is_($rc === 0 && trim($err) === '', 'the second run exits 0, silently', "exit {$rc}; stderr: " . trim($err));
is_(strpos($out, 'JOBS-RAN') === false && strpos($out, 'LATER-SHUTDOWN-RAN') === false, 'it ran no job and registered nothing');
is_(!lock_is_free($lf3), 'the first run\'s lock is untouched');
if ($holder !== false) { flock($holder, LOCK_UN); fclose($holder); }

// ── 4. The control: 5.18.50's handler fails as the server did ────────────────
echo "\n4. Control: 5.18.50's handler, run the same way\n";
$OLD_HANDLER = "register_shutdown_function(function() use (\$lockFp, \$lockFile) {\n"
             . "    @flock(\$lockFp, LOCK_UN);\n"
             . "    @fclose(\$lockFp);\n"
             . "    @touch(\$lockFile); // reset mtime\n"
             . "});";
$old = preg_replace("/register_shutdown_function\\(function\\(\\) use \\(\\\$lockFp, \\\$lockFile\\) \\{.*?\\n\\}\\);/s", $OLD_HANDLER, $LOCK, -1, $nOld);
is_($nOld === 1, 'the 5.18.50 handler replaces the current one exactly once', "replaced {$nOld}");
[$rc, $out, $err] = run_child($tmp, 'old', child_script((string)$old, $RELEASE), $tmp . '/cron_master_old.lock', 'normal');
is_(strpos($err, 'Uncaught TypeError: ' . $FLOCK_FATAL) !== false, 'it ends in the server\'s fatal line: "Uncaught TypeError: ' . $FLOCK_FATAL . '"', trim($err));
is_(strpos($out, 'END-REACHED') !== false, 'after the jobs had run and the normal end was reached — the fatal comes at shutdown');
is_(strpos($out, 'LATER-SHUTDOWN-RAN') === false, 'and the shutdown function registered after it was skipped');

// ── 5. Weakened copies of the guard ──────────────────────────────────────────
echo "\n5. Weakened copies of the guard, each caught\n";
$weakened = [
    'a truthiness test (a closed handle is still truthy)' => 'if ($lockFp) {',
    'a comparison with false'                             => 'if ($lockFp !== false) {',
    'no condition at all'                                 => 'if (true) {',
];
foreach ($weakened as $label => $guard) {
    $w = str_replace('if (is_resource($lockFp)) {', $guard, $LOCK, $nW);
    if ($nW !== 1) { bad("weakened copy \"{$label}\": its anchor occurs once", "replaced {$nW}"); continue; }
    [$rc, $out, $err] = run_child($tmp, 'weak', child_script($w, $RELEASE), $tmp . '/cron_master_weak.lock', 'normal');
    $caught = strpos($err, $FLOCK_FATAL) !== false || strpos($out, 'LATER-SHUTDOWN-RAN') === false || $rc !== 0;
    is_($caught, "weakened copy caught: {$label}", trim($err));
}

// ── cleanup ──────────────────────────────────────────────────────────────────
foreach ((array)glob($tmp . '/*') as $f) @unlink((string)$f);
@rmdir($tmp);
is_(!is_dir($tmp), 'the test left nothing behind');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
