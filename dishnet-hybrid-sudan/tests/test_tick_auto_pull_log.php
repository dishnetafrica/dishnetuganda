<?php
declare(strict_types=1);
/**
 * tests/test_tick_auto_pull_log.php — 5.18.39
 *
 * The five-minute tick (main.php, run by uCRM) died on its own log line. Under
 * strict types, str_pad($autoPullHour, …) throws a TypeError because the hour is
 * an int, and every tick except the pull hour takes that branch — so the Uganda
 * install logged "[DishNet UNCAUGHT] str_pad() … main.php:456" about 276 times a
 * day (Phase 1 closure, --otp-history) and never wrote its final "total
 * execution" line. 5.18.39 casts the hour to a string on both lines.
 *
 * This test runs the REAL main.php in a throwaway copy:
 *   - cron/master.php is removed from the copy — the dispatcher and its jobs
 *     have their own suites; here only the tail of the tick is under test;
 *   - the child's clock is set (php -d date.timezone) to an hour that takes the
 *     "scheduled for" branch: not 03, the default pull hour, and below 07, the
 *     daily report — so nothing in the run needs a network or a configuration.
 * It asserts the tick reaches its last line, then proves the assertion has teeth
 * by putting the old expression back and watching the tick die where it did.
 */
$pass = 0; $fail = 0;
function is_(bool $ok, string $what, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   $what\n"; }
    else     { $fail++; echo "  FAIL $what" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
function t(string $what, $got, $want): void { is_($got === $want, $what, 'got ' . var_export($got, true) . ', want ' . var_export($want, true)); }

if (!getenv('DN_VAULT_FILE')) putenv('DN_VAULT_FILE=' . tempnam(sys_get_temp_dir(), 'dn-vault-'));

$src    = dirname(__DIR__);
$root   = sys_get_temp_dir() . '/dn_tick_' . getmypid();
$plugin = $root . '/plugin';
$hbFile = $root . '/.' . basename($plugin) . '-data/heartbeat.log';   // getDataDir(): the sibling data directory
exec('rm -rf ' . escapeshellarg($root)); @mkdir($root, 0700, true);
exec('cp -r ' . escapeshellarg($src) . ' ' . escapeshellarg($plugin));
exec('rm -rf ' . escapeshellarg($plugin . '/data'));
$master = $plugin . '/cron/master.php';
is_(is_file($master), 'the copy carries the master cron dispatcher (removed below, on purpose)');
@unlink($master);
is_(!is_file($master), 'cron/master.php removed from the copy: the tail of the tick is what runs here');

echo "\nA clock that takes the 'scheduled for' branch\n";
// The default pull hour is 3 and the daily report fires from 7: run the child at 04:xx.
$u = (int)gmdate('G'); $o = ((4 - $u) % 24 + 24) % 24; if ($o > 12) $o -= 24;
$tz = $o === 0 ? 'UTC' : ($o > 0 ? "Etc/GMT-$o" : 'Etc/GMT+' . (-$o));     // Etc/GMT-N is UTC+N
t("the chosen zone ($tz) reads 04 now", (int)(new DateTime('now', new DateTimeZone($tz)))->format('G'), 4);

$run = function () use ($plugin, $tz): array {
    $cmd = 'timeout 90 php -d date.timezone=' . escapeshellarg($tz) . ' main.php';
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $plugin);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($p), $out, $err];
};

echo "\nThe tick as shipped\n";
[$code, $out, $err] = $run();
$hb = is_file($hbFile) ? (string)file_get_contents($hbFile) : '';
is_($hb !== '', 'the tick wrote its heartbeat log at the sibling data directory', $hbFile);
is_(strpos($err, 'UNCAUGHT') === false, 'nothing uncaught on stderr', trim(substr($err, 0, 300)));
t('exit status 0', $code, 0);
is_(strpos($hb, 'Heartbeat complete.') !== false, 'the heartbeat ran');
is_(preg_match('/UCRM auto-pull: scheduled for \d{4}-\d{2}-\d{2} \d{2}:00\./', $hb) === 1,
    "the 'scheduled for' branch ran and printed a two-digit hour", trim(substr($hb, -400)));
is_(strpos($hb, ' 03:00.') !== false, 'the default pull hour 3 is padded to 03', trim(substr($hb, -200)));
is_(strpos($hb, 'main.php total execution:') !== false, 'the tick reached its last line');

echo "\nThe control: the pre-5.18.39 expression put back\n";
$main = $plugin . '/main.php'; $s = (string)file_get_contents($main);
$fixed = "str_pad((string)\$autoPullHour, 2, '0', STR_PAD_LEFT)"; $bare = "str_pad(\$autoPullHour, 2, '0', STR_PAD_LEFT)";
t('the fixed expression occurs twice in the copy', substr_count($s, $fixed), 2);
file_put_contents($main, str_replace($fixed, $bare, $s));
@unlink($hbFile);
[$code2, $out2, $err2] = $run();
$hb2 = is_file($hbFile) ? (string)file_get_contents($hbFile) : '';
is_(strpos($err2, '[DishNet UNCAUGHT] str_pad(): Argument #1 ($string) must be of type string, int given') !== false,
    'the old expression dies with the production error', trim(substr($err2, 0, 300)));
is_(strpos($err2, 'main.php:456') !== false, '…at main.php:456, the line the production log names', trim(substr($err2, 0, 300)));
t("…yet the exit status is 0: the plugin's handler swallows it, which is why only the container log ever showed the crash", $code2, 0);
is_(strpos($hb2, 'Heartbeat complete.') !== false, 'the heartbeat still ran before the crash');
is_(strpos($hb2, 'scheduled for') === false && strpos($hb2, 'total execution') === false,
    'and neither the schedule line nor the last line is written — the tick died between them');

echo "\nThe repository pin\n";
$repo = (string)file_get_contents($src . '/main.php');
t('main.php casts the hour on both lines', substr_count($repo, $fixed), 2);
t('and no bare str_pad($autoPullHour remains', substr_count($repo, $bare), 0);

exec('rm -rf ' . escapeshellarg($root));
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
