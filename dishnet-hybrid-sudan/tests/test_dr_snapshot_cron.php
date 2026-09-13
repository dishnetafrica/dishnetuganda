<?php
declare(strict_types=1);
/**
 * test_dr_snapshot_cron.php — the copy that has to happen without being asked.
 *
 * tools/dr_snapshot.php could always save dishnet-data-report's data
 * somewhere an upgrade cannot reach. It never ran, because running it
 * required somebody to know an upgrade was coming. uCRM does not announce
 * one, so the only copy that is worth anything is the one taken yesterday.
 *
 * What these insist on:
 *
 *   · a day where nothing changed does not cost a second copy of the bytes
 *   · a day where something did changed does
 *   · snapshots are pruned, because a full disk on this box stops uCRM
 *     writing invoices — a worse failure than the one this prevents
 *   · the cron does not exit(), which would abort every cron scheduled
 *     after it in the same master.php tick
 *   · it is actually in the schedule, because a cron nobody dispatches is
 *     exactly as useful as the tool nobody remembered to run
 */
require_once dirname(__DIR__) . '/lib/SiblingPlugin.php';
require_once dirname(__DIR__) . '/lib/DrSnapshot.php';

$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; } }

$base    = sys_get_temp_dir() . '/dn_dsc_' . bin2hex(random_bytes(4));
$plugins = $base . '/plugins';
$drData  = $plugins . '/dishnet-data-report/data';
$data    = $base . '/data';
@mkdir($drData, 0777, true);
@mkdir($plugins . '/dishnet-hybrid-sudan', 0777, true);
@mkdir($data, 0777, true);
putenv('DN_PLUGIN_ROOT=' . $plugins . '/dishnet-hybrid-sudan');
$GLOBALS['_PLUGIN_ROOT'] = $plugins . '/dishnet-hybrid-sudan';
SiblingPlugin::reset();

file_put_contents($drData . '/wifi_test_block_state.json', json_encode(['Router-118' => ['blocked' => true]]));
file_put_contents($drData . '/wifi_router_map.json',       json_encode(['KIT1' => 'Router-118']));
file_put_contents($drData . '/sl_svc_cache.json',          json_encode(['SL-A-1' => ['service_line' => 'SL-A-1']]));

$snapRoot = DrSnapshot::root($data);
$live     = static fn() => SiblingPlugin::dataDir(DrSnapshot::PLUGIN);

echo "\nThe first snapshot\n";
$r = DrSnapshot::take($snapRoot, $live(), $data, true);
t('it saves',             $r['status'], 'saved');
t('all three files',      $r['saved'], 3);
t('one snapshot on disk', count(DrSnapshot::snapshots($snapRoot)), 1);
is_(strpos((string)realpath($snapRoot), (string)realpath($plugins . '/dishnet-data-report')) !== 0,
    'and it is NOT inside the directory uCRM deletes');

echo "\nA day where nothing changed costs nothing\n";
$r = DrSnapshot::take($snapRoot, $live(), $data, true);
t('it declines',                 $r['status'], 'unchanged');
t('naming the one it matches',   $r['name'], DrSnapshot::snapshots($snapRoot)[0]);
t('and takes no second copy',    count(DrSnapshot::snapshots($snapRoot)), 1);

echo "\nA day where something moved does not\n";
sleep(1);   // the name is a UTC timestamp to the second
file_put_contents($drData . '/wifi_test_block_state.json',
    json_encode(['Router-118' => ['blocked' => true], 'Router-204' => ['blocked' => true]]));
$r = DrSnapshot::take($snapRoot, $live(), $data, true);
t('it saves again',      $r['status'], 'saved');
t('two now held',        count(DrSnapshot::snapshots($snapRoot)), 2);
$newest = DrSnapshot::latest($snapRoot);
$saved  = json_decode((string)file_get_contents($newest . '/wifi_test_block_state.json'), true);
is_(isset($saved['Router-204']), 'and the newest holds the change, not the old state');

echo "\nA person asking for one gets one, identical or not\n";
sleep(1);
$r = DrSnapshot::take($snapRoot, $live(), $data, false);
t('taken',        $r['status'], 'saved');
t('three held',   count(DrSnapshot::snapshots($snapRoot)), 3);

echo "\nPruning, because a full disk is the worse failure\n";
for ($i = 0; $i < 6; $i++) @mkdir($snapRoot . '/2020010' . $i . '-000000', 0750, true);
t('nine on disk',        count(DrSnapshot::snapshots($snapRoot)), 9);
$gone = DrSnapshot::prune($snapRoot, 4);
t('five removed',        count($gone), 5);
t('four kept',           count(DrSnapshot::snapshots($snapRoot)), 4);
is_(!in_array('20200100-000000', DrSnapshot::snapshots($snapRoot), true),
    'and the oldest are the ones that went');
is_(is_file(DrSnapshot::latest($snapRoot) . '/wifi_test_block_state.json'),
    'while the newest is untouched and complete');
t('pruning again is a no-op', DrSnapshot::prune($snapRoot, 4), []);
t('keep is never zero — one is always held',
    count(DrSnapshot::snapshots($snapRoot)) - count(DrSnapshot::prune($snapRoot, 0)), 1);

echo "\nNothing to take is said, not guessed at\n";
$empty = $base . '/empty';
@mkdir($empty, 0777, true);
$r = DrSnapshot::take($snapRoot, $empty, $data, true);
t('an empty directory',   $r['status'], 'nothing_to_take');
$r = DrSnapshot::take($snapRoot, null, $data, true);
t('and no plugin at all',  $r['status'], 'not_installed');
is_(strpos($r['reason'], 'not installed') !== false, 'each with its own words');

echo "\nA deliberate uninstall needs the WHOLE directory, not the curated ten\n";
// The two that decide whether a reinstall is recoverable are both outside
// FILES: .enc_salt, which is what makes the stored cookies readable, and the
// auto-block database, whose loss leaves paying customers cut off.
file_put_contents($drData . '/.enc_salt', 'saltysalt');
file_put_contents($drData . '/auto_block.sqlite3', 'SQLITEBYTES');
@mkdir($drData . '/backups', 0777, true);
file_put_contents($drData . '/backups/older.zip', 'zipbytes');
file_put_contents($drData . '/sync_log.json', json_encode(['ran' => true]));

$curated = DrSnapshot::take($snapRoot, $live(), $data, false);
$curatedNames = array_map('basename', (array)glob($snapRoot . '/' . $curated['name'] . '/*'));
is_(!in_array('.enc_salt', $curatedNames, true),
    'the curated snapshot does NOT hold .enc_salt — which is the whole point');

$full = DrSnapshot::takeFull($snapRoot, $live(), $data);
t('the full copy succeeds', $full['status'], 'saved');
is_(substr($full['name'], -5) === '-full', 'and is named so it cannot be confused for the other kind');
$got = [];
foreach ((array)glob($snapRoot . '/' . $full['name'] . '/{,.}*', GLOB_BRACE) as $f) {
    $b = basename($f);
    if ($b === '.' || $b === '..') continue;
    $got[$b] = true;
}
is_(isset($got['.enc_salt']),          'it takes the dotfile the curated list omits');
is_(isset($got['auto_block.sqlite3']), 'and the sqlite database');
is_(isset($got['sync_log.json']),      'and files no curated list ever named');
is_(is_file($snapRoot . '/' . $full['name'] . '/backups/older.zip'),
    'and recurses into subdirectories');
t('the salt survives byte for byte',
    file_get_contents($snapRoot . '/' . $full['name'] . '/.enc_salt'), 'saltysalt');
is_($full['bytes'] > 0, 'and it reports how much it took');

$r = DrSnapshot::takeFull($snapRoot, null, $data);
t('with no plugin it says so', $r['status'], 'not_installed');

echo "\nThe cron itself\n";
$cron = dirname(__DIR__) . '/cron/dr_snapshot.php';
$src  = (string)file_get_contents($cron);
is_(!preg_match('/^\s*exit\s*[(;]/m', $src),
    'never calls exit() — master.php INCLUDES it, and exit kills the rest of the tick');
is_(strpos($src, 'return;') !== false, 'it returns instead');

// The cron resolves its own data directory through getDataDir(), which is
// NOT the temp one used above — that is the point of the single-root fix, so
// the test follows the cron rather than telling it where to write.
$runCron = static function () use ($cron, $plugins): string {
    return (string)shell_exec('DN_PLUGIN_ROOT='
        . escapeshellarg($plugins . '/dishnet-hybrid-sudan')
        . ' php ' . escapeshellarg($cron) . ' 2>&1');
};

$out = $runCron();
is_(strpos($out, 'Fatal') === false && stripos($out, 'warning') === false,
    'it runs clean as a standalone script', $out);
is_(strpos($out, '[dr_snapshot] saved') !== false, 'and takes the first snapshot', $out);

$out = $runCron();
is_(trim($out) === '', 'then says nothing on a day the data has not changed', $out);

sleep(1);
file_put_contents($drData . '/wifi_router_map.json', json_encode(['KIT1' => 'Router-999']));
$out = $runCron();
is_(strpos($out, '[dr_snapshot] saved') !== false, 'but reports the day it does', $out);

// It wrote outside the plugin uCRM replaces, and outside data-report entirely.
$cronSnaps = glob($plugins . '/.dishnet-hybrid-sudan-data/dr_snapshots/*') ?: [];
is_(count($cronSnaps) >= 2, 'its snapshots are in this plugin\'s own data directory',
    implode(', ', $cronSnaps));
is_(strpos((string)realpath($cronSnaps[0] ?? ''), (string)realpath($plugins . '/dishnet-data-report')) !== 0,
    'not inside the one being snapshotted');

echo "\nAnd it is actually dispatched\n";
$master = (string)file_get_contents(dirname(__DIR__) . '/cron/master.php');
is_(strpos($master, "'dr_snapshot'") !== false, 'master.php has a job for it');
is_((bool)preg_match("/'dr_snapshot'\s*=>\s*\['interval'\s*=>\s*86400/", $master),
    'scheduled daily');
is_(strpos($master, "/dr_snapshot.php'") !== false, 'pointing at the script that exists');

exec('rm -rf ' . escapeshellarg($base));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
