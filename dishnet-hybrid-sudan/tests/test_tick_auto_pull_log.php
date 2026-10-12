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
 * 5.18.93 retires the auto-pull altogether (tests/test_main_auto_pull_retired.php),
 * and the schedule line with it. What this test still guards is the 5.18.39 promise:
 * on an hour outside the pull hour the tick reaches its last line.
 *
 * This test runs the REAL main.php in a throwaway copy:
 *   - cron/master.php is removed from the copy — the dispatcher and its jobs
 *     have their own suites; here only the tail of the tick is under test;
 *   - the child's clock is set (php -d date.timezone) to 04:xx, the hour that took
 *     the "scheduled for" branch: not 03, the default pull hour, and below 07, the
 *     daily report — so nothing in the run needs a network or a configuration.
 * It asserts the tick reaches its last line and prints no auto-pull line, then
 * proves the assertion has teeth by planting 5.18.38's own lines where the block
 * stood — the hour read as an int (line 441) and the str_pad of it (line 456),
 * then the schedule line — and watching the tick die on 456, as it did.
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

echo "\nA clock at 04:xx, the hour that took the 'scheduled for' branch\n";
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
t('no auto-pull line of any kind: the block is retired (5.18.93)', substr_count($hb, 'auto-pull'), 0);
is_(strpos($hb, 'main.php total execution:') !== false, 'the tick reached its last line', trim(substr($hb, -300)));

echo "\nThe control: 5.18.38's lines planted where the block stood\n";
$main = $plugin . '/main.php'; $s = (string)file_get_contents($main);
$hdr  = "// AUTO-PULL UCRM CLIENTS CACHE — RETIRED (5.18.93)\n";
t('the copy carries the retired marker once', substr_count($s, $hdr), 1);
// 5.18.38's lines 441 and 456 — 5.18.92's, without the (string) cast 5.18.39 added on 456 and 457 (docs/07, 5.18.39) — and
// the schedule line after them, as the tick ran them at 04:xx: the hour an int, under strict types, died on 456.
$bare  = "\$autoPullHour    = (int)(\$kycConfig['ucrm_auto_pull_hour'] ?? 3);\n"
       . "    \$nextPull = date('Y-m-d') . ' ' . str_pad(\$autoPullHour, 2, '0', STR_PAD_LEFT) . ':00';\n"
       . "    mainLog(\"UCRM auto-pull: scheduled for {\$nextPull}.\");\n";
$i = strpos($s, $hdr);
if ($i !== false) {
    file_put_contents($main, substr($s, 0, $i) . $hdr . $bare . substr($s, $i + strlen($hdr)));
    $line = 0; foreach (file($main) as $n => $l) if (strpos($l, 'str_pad($autoPullHour') !== false) { $line = $n + 1; break; }
    is_($line > 0, "the planted line is in the copy (line $line)");
    @unlink($hbFile);
    [$code2, $out2, $err2] = $run();
    $hb2 = is_file($hbFile) ? (string)file_get_contents($hbFile) : '';
    is_(strpos($err2, '[DishNet UNCAUGHT] str_pad(): Argument #1 ($string) must be of type string, int given') !== false,
        'the 5.18.38 line dies with the production error', trim(substr($err2, 0, 300)));
    is_($line > 0 && strpos($err2, "main.php:$line") !== false, '…at the planted line', trim(substr($err2, 0, 300)));
    t("…yet the exit status is 0: the plugin's handler swallows it, which is why only the container log ever showed the crash", $code2, 0);
    is_(strpos($hb2, 'Heartbeat complete.') !== false, 'the heartbeat still ran before the crash');
    is_(strpos($hb2, 'scheduled for') === false && strpos($hb2, 'total execution') === false,
        'and neither the schedule line nor the last line is written — the tick died between them');
}

echo "\nThe repository pin\n";
$repo = (string)file_get_contents($src . '/main.php');
t('main.php carries the retired marker once', substr_count($repo, $hdr), 1);
t('and no str_pad($autoPullHour of either form remains', substr_count($repo, 'str_pad($autoPullHour') + substr_count($repo, 'str_pad((string)$autoPullHour'), 0);

exec('rm -rf ' . escapeshellarg($root));
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
