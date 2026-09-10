<?php
/**
 * test_lte_optout.php — Uganda can decline South Sudan's LTE bridge.
 *
 * The three LTE crons poll a BlueCard feed belonging to the South Sudan
 * operation. Uganda has neither BlueCard nor LTE, and could not say so:
 * cron_lte_sync.php treats an unset lte_feed_url as "use the hardcoded
 * dishnetss.com URL" and syncs anyway. The lte_sync_enabled flag had been
 * deleted as no longer required.
 *
 * It cost more than wasted requests. Several feed calls at
 * CURLOPT_TIMEOUT => 60 exceed the 120s master.php allows that job, and the
 * time-limit fatal is E_ERROR — uncatchable — so lte_sync ended the cron
 * cycle and every job behind it.
 *
 * The gate is an OPT-OUT. These assertions exist to keep it one: if absence
 * ever came to mean "off", the Sudan installation would silently stop syncing
 * its own live LTE business, which is a far worse failure than the one being
 * fixed.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

$crons = ['cron_lte_sync.php', 'cron_lte_usage.php', 'cron_lte.php'];

echo "\nAll three LTE crons can be turned off\n";
foreach ($crons as $f) {
    $s = (string)file_get_contents($root . '/' . $f);
    is_(strpos($s, "array_key_exists('lte_sync_enabled'") !== false,
        $f . ' consults the setting');
}

echo "\nAbsence means ON, so the Sudan installation is unchanged\n";
// This is the whole contract. The gate must ask whether the key EXISTS before
// reading it — a bare truthiness check would make every install without the
// key stop syncing, Sudan included.
foreach ($crons as $f) {
    $s = (string)file_get_contents($root . '/' . $f);
    $has = strpos($s, "array_key_exists('lte_sync_enabled'");
    $neg = strpos($s, "!filter_var(\$config['lte_sync_enabled']");
    is_($has !== false && $neg !== false && $neg > $has,
        $f . ' returns only on an explicit false, never on a missing key',
        'an unset key must not disable a live Sudan sync');
}

echo "\nThe gate runs before the hardcoded Sudan fallback\n";
$sync = (string)file_get_contents($root . '/cron_lte_sync.php');
$gate = strpos($sync, "array_key_exists('lte_sync_enabled'");
$fall = strpos($sync, 'dishnetss.com/lte_feed.php');
is_($gate !== false && $fall !== false && $gate < $fall,
    'checking after the fallback would defeat the point');

// lte_sync holds a lock before its config load; returning past it would leave
// the lock file held and block every later run.
is_(strpos($sync, "flock(\$lockFp, LOCK_UN); fclose(\$lockFp); return;") !== false,
    'and lte_sync releases its lock on the way out');

echo "\nThe operator can see and change it\n";
$tool = $root . '/tools/set_lte_sync.php';
is_(is_file($tool), 'tools/set_lte_sync.php exists');
$t = is_file($tool) ? (string)file_get_contents($tool) : '';
is_(strpos($t, '--off') !== false && strpos($t, '--on') !== false,
    'with both directions, because turning it back on must be as easy');
is_(strpos($t, 'not set — the default') !== false,
    'and it says that unset means ON rather than leaving it ambiguous');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
