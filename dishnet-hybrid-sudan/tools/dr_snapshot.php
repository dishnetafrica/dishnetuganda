<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * dr_snapshot.php — copy dishnet-data-report's data somewhere an upgrade cannot reach.
 *
 *   php tools/dr_snapshot.php              what is there, and what a snapshot would take
 *   php tools/dr_snapshot.php --save       take one
 *   php tools/dr_snapshot.php --list       snapshots already taken
 *   php tools/dr_snapshot.php --restore <name>   put one back
 *
 * dishnet-data-report keeps everything it knows in <plugin>/data — its
 * Starlink accounts, its router map, and the record of which customers are
 * currently blocked. uCRM DELETES that directory when the plugin is
 * upgraded. This plugin lost its own database to exactly that, repeatedly,
 * before anyone connected the two events.
 *
 * Its own backup writes to <plugin>/data/backups, inside the directory being
 * deleted, and its file list leaves out the two that matter most:
 *
 *   wifi_test_block_state.json   WHO IS CURRENTLY BLOCKED. Blocking changes
 *                                a customer's SSID and password. Lose this
 *                                while people are blocked and they stay cut
 *                                off, with nothing recording which routers
 *                                to go and fix.
 *   wifi_router_map.json         which router is behind which dish.
 *
 * So this writes into THIS plugin's data directory, which survives an
 * upgrade of either plugin, and takes those two as well. Run it before
 * upgrading data-report. It reads that plugin and writes only here.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

// Two different things, and conflating them broke this once already:
//   $root  — where this code is, which is what require_once needs
//   the plugin root — where this plugin sits among its siblings, which is
//                     what SiblingPlugin resolves the others against
// They are the same on a real install and differ when the tool is run
// against a staged directory, which is how it gets tested.
$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/SiblingPlugin.php';
require_once $root . '/lib/SecureFile.php';
require_once $root . '/lib/DrSnapshot.php';

$GLOBALS['_PLUGIN_ROOT'] = ((string)getenv('DN_PLUGIN_ROOT')) ?: $root;

const DR_PLUGIN = DrSnapshot::PLUGIN;

/**
 * What to take, and why. The list itself lives in lib/DrSnapshot.php so that
 * cron/dr_snapshot.php — which runs daily and unattended — cannot end up
 * taking a different set of files from the one a person sees here.
 */
const SNAPSHOT_FILES = DrSnapshot::FILES;

/**
 * What to take, and why it is worth taking. Ordered by how badly it hurts to
 * lose — the two the plugin's own backup omits are at the top.
 */
const SNAPSHOT_FILES = [
    'wifi_test_block_state.json' => 'WHO IS CURRENTLY BLOCKED — cannot be reconstructed',
    'wifi_router_map.json'       => 'dish → router map — a rediscovery cycle to rebuild',
    'dr_accounts.json'           => 'Starlink accounts and their session cookies — needs a re-login',
    'dr_kit_registry.json'       => 'Starlink-derived kit liveness',
    'sl_svc_cache.json'          => 'service line → kit resolution',
    'sl_sync_settings.json'      => 'sync configuration',
    'dr_plan_cache.json'         => 'plan cache',
    'backup_settings.json'       => 'its own backup configuration, including Drive credentials',
    'crm_kit_authority.json'     => 'kit ownership decisions',
    'sl_usage.json'              => 'usage history shown in the customer portal',
];

$args = array_slice($argv, 1);
$has  = function (string $f) use ($args): bool { return in_array($f, $args, true); };
$val  = function (string $f) use ($args): string {
    $i = array_search($f, $args, true);
    return ($i !== false && isset($args[$i + 1])) ? (string)$args[$i + 1] : '';
};
foreach ($args as $a) {
    if (strpos($a, '--') !== 0) continue;
    if (!in_array($a, ['--save', '--list', '--restore', '--yes'], true)) {
        fwrite(STDERR, "\n  Unknown option: {$a}\n");
        fwrite(STDERR, "  Known: --save, --list, --restore <name>\n\n");
        exit(2);
    }
}

$dataDir  = cliDataDir($root);
$snapRoot = DrSnapshot::root($dataDir);
$drData   = SiblingPlugin::dataDir(DR_PLUGIN);

echo "\n  DATA REPORT SNAPSHOT — " . gmdate('Y-m-d H:i') . " UTC\n\n";
printf("    %-18s %s\n", 'reading', $drData ?? '(plugin not installed)');
printf("    %-18s %s\n", 'writing to', $snapRoot);
echo "\n";

// ── --list ──────────────────────────────────────────────────────────────────
if ($has('--list')) {
    $snaps = is_dir($snapRoot) ? array_values(array_diff((array)scandir($snapRoot), ['.', '..'])) : [];
    if ($snaps === []) { echo "  No snapshots taken yet.\n\n"; exit(0); }
    sort($snaps);
    foreach ($snaps as $s) {
        $files = glob($snapRoot . '/' . $s . '/*.json') ?: [];
        printf("    %-22s %d file(s)\n", $s, count($files));
    }
    echo "\n";
    exit(0);
}

// ── --restore ───────────────────────────────────────────────────────────────
if ($has('--restore')) {
    $name = trim($val('--restore'));
    // A name is a directory name. Anything with a slash in it writes outside
    // the snapshot directory, and this tool writes into another plugin.
    if ($name === '' || strpbrk($name, '/\\') !== false || strpos($name, '..') !== false) {
        fwrite(STDERR, "  --restore needs a snapshot name from --list.\n\n");
        exit(2);
    }
    $from = $snapRoot . '/' . $name;
    if (!is_dir($from)) { fwrite(STDERR, "  No snapshot called '{$name}'.\n\n"); exit(1); }
    if ($drData === null) {
        fwrite(STDERR, "  " . DR_PLUGIN . " is not installed — nowhere to restore to.\n\n");
        exit(1);
    }

    $files = glob($from . '/*.json') ?: [];
    echo "  This will OVERWRITE " . count($files) . " file(s) in {$drData}:\n\n";
    foreach ($files as $f) {
        $t = $drData . '/' . basename($f);
        printf("    %-32s %s\n", basename($f), is_file($t) ? '(replacing an existing file)' : '(new)');
    }
    if (!$has('--yes')) {
        echo "\n  Add --yes to go ahead.\n\n";
        exit(1);
    }
    $done = 0;
    foreach ($files as $f) {
        $t = $drData . '/' . basename($f);
        // Keep what is being replaced. A restore of the wrong snapshot is
        // itself a way to lose the current state.
        if (is_file($t)) @copy($t, $t . '.before-restore.' . gmdate('Ymd-His'));
        if (@copy($f, $t)) { SecureFile::adopt($t, $drData); $done++; }
        else echo "    FAILED to write " . basename($t) . "\n";
    }
    echo "\n  {$done} file(s) restored.\n\n";
    exit($done === count($files) ? 0 : 1);
}

// ── survey / --save ─────────────────────────────────────────────────────────
if ($drData === null) {
    echo "  " . DR_PLUGIN . " is not installed. Nothing to snapshot.\n\n";
    exit(1);
}

$present = []; $absent = [];
foreach (SNAPSHOT_FILES as $file => $why) {
    $p = $drData . '/' . $file;
    if (is_file($p)) $present[$file] = ['path' => $p, 'size' => (int)@filesize($p),
                                        'age' => time() - (int)@filemtime($p), 'why' => $why];
    else $absent[$file] = $why;
}

echo "  WOULD BE TAKEN\n";
foreach ($present as $file => $i) {
    printf("    %-30s %8s  %5.1f days old   %s\n", $file,
           number_format($i['size']), $i['age'] / 86400, $i['why']);
}
if ($present === []) echo "    (nothing — none of the expected files exist yet)\n";
if ($absent !== []) {
    echo "\n  NOT PRESENT\n";
    foreach ($absent as $file => $why) printf("    %-30s %s\n", $file, $why);
}

// The one that decides whether an upgrade is safe at all.
$blocked = 0;
if (isset($present['wifi_test_block_state.json'])) {
    $st = json_decode((string)@file_get_contents($present['wifi_test_block_state.json']['path']), true);
    if (is_array($st)) $blocked = count($st);
}
echo "\n";
if ($blocked > 0) {
    echo "  ⚠  {$blocked} router(s) are BLOCKED right now.\n";
    echo "     Upgrading data-report while that is true deletes the only record of\n";
    echo "     it. Those customers' SSID and password have been changed, and after\n";
    echo "     the upgrade nothing knows which routers to restore. Take a snapshot\n";
    echo "     first, and keep it until they are unblocked.\n\n";
}

if (!$has('--save')) {
    echo "  Nothing written. Take one with:\n\n";
    echo "    php tools/dr_snapshot.php --save\n\n";
    exit(0);
}

// A person asking for a snapshot gets one, even if it duplicates the last:
// they are usually about to upgrade, and want to see it taken.
$r = DrSnapshot::take($snapRoot, $drData, $dataDir, false);
if ($r['status'] !== 'saved' && $r['name'] === '') {
    fwrite(STDERR, "  Could not take a snapshot: {$r['reason']}\n\n");
    exit(1);
}
$name  = $r['name'];
$saved = $r['saved'];
if ($saved < $r['total']) echo "    FAILED to copy " . ($r['total'] - $saved) . " file(s)\n";

echo "  ✔ {$saved} file(s) saved to {$name}\n";
$pruned = DrSnapshot::prune($snapRoot);
if ($pruned) {
    echo "  · " . count($pruned) . " older snapshot(s) removed, keeping the newest "
       . DrSnapshot::KEEP . "\n";
}
echo "\n  Restore after an upgrade with:\n";
echo "    php tools/dr_snapshot.php --restore {$name} --yes\n\n";
exit($saved === $r['total'] ? 0 : 1);
