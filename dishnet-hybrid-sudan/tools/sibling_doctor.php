<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * sibling_doctor.php — what the other plugins are actually giving us.
 *
 *   php tools/sibling_doctor.php
 *
 * Two other plugins hold data this one depends on: dishnet-starlink-finance
 * has the KIT register, dishnet-data-report has the router map, the usage
 * figures and the block state. Neither is in this repository, so nothing in
 * the test suite can touch them.
 *
 * Until now every read was hand-rolled, guessed between two candidate paths,
 * and fell through to zero without a word. So a dashboard showing "0 routers
 * matched" meant one of: there are no routers, the plugin was renamed, its
 * format changed, or it was upgraded and its data directory deleted — and
 * nothing distinguished them.
 *
 * This asks each file directly and reports which of those it is. It reads
 * nothing into the plugin and changes nothing.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
$GLOBALS['_PLUGIN_ROOT'] = $root;
require_once $root . '/lib/SiblingPlugin.php';

/**
 * The files this plugin actually reads, and what stops working without each.
 * Kept here rather than discovered, so a file that is never read again shows
 * up as a line nobody can explain instead of quietly disappearing.
 */
$EXPECTED = [
    'dishnet-starlink-finance' => [
        'sl_kits.json'      => 'the KIT register — which dish belongs to which customer',
        'sl_accounts.json'  => 'Starlink account billing days (optional — the name varies)',
    ],
    'dishnet-data-report' => [
        'wifi_router_map.json'      => 'KIT to router mapping — without it nothing can be blocked',
        'wifi_test_block_state.json'=> 'which routers are currently blocked',
        'sl_usage.json'             => 'data usage shown in the customer portal',
        'sl_svc_cache.json'         => 'service line to KIT resolution',
        'dr_kit_registry.json'      => 'Starlink-derived liveness for the portal',
    ],
];

echo "\n  SIBLING PLUGIN DOCTOR — " . gmdate('Y-m-d H:i') . " UTC\n\n";
printf("    %-22s %s\n", 'this plugin', $root);
printf("    %-22s %s\n", 'plugins directory', SiblingPlugin::pluginsDir());
echo "\n";

$problems = 0;
$warnings = 0;

foreach ($EXPECTED as $plugin => $files) {
    $installed = SiblingPlugin::installed($plugin);
    $dir       = SiblingPlugin::dataDir($plugin);

    echo "  " . $plugin . "\n";
    if (!$installed && $dir === null) {
        echo "    NOT INSTALLED — every figure that comes from it reads as zero.\n\n";
        $problems += count($files);
        continue;
    }
    printf("    %-14s %s\n", 'data', $dir ?? '(none)');
    if ($dir !== null && substr($dir, -5) === '/data') {
        // The directory uCRM replaces on upgrade. This plugin lost its own
        // database to exactly that, repeatedly, before anyone connected the
        // two events.
        echo "    WARNING: that is the directory uCRM DELETES when this plugin is\n";
        echo "             upgraded. The next upgrade takes this data with it.\n";
        $warnings++;
    }

    foreach ($files as $file => $why) {
        $path = SiblingPlugin::path($plugin, $file);
        if ($path === null) {
            printf("      %-28s MISSING   %s\n", $file, $why);
            $problems++;
            continue;
        }
        $data = SiblingPlugin::readJson($plugin, $file);
        if ($data === null) {
            printf("      %-28s UNREADABLE\n", $file);
            $problems++;
            continue;
        }
        $age  = time() - (int)@filemtime($path);
        $hours = $age / 3600;
        // Stale is its own failure and reads exactly like fresh data. A
        // router map from last month blocks the wrong customers.
        $note = $hours > 48 ? sprintf('STALE — %.0f days old', $hours / 24)
              : ($hours > 6 ? sprintf('%.0f hours old', $hours)
                            : sprintf('%.0f min old', $age / 60));
        if ($hours > 48) $warnings++;
        printf("      %-28s %-7d rows   %s\n", $file, count($data), $note);
    }
    echo "\n";
}

echo "  " . str_repeat('─', 72) . "\n";
$misses = SiblingPlugin::misses();
if ($problems === 0 && $warnings === 0) {
    echo "  Everything this plugin reads from the other two is present and fresh.\n\n";
    exit(0);
}
if ($problems > 0) {
    echo "  {$problems} file(s) could not be read. Anything computed from them is\n";
    echo "  currently reporting zero, and reporting it as though it were a fact:\n\n";
    foreach ($misses as $key => $m) echo "    · {$key} — {$m['why']}\n";
    echo "\n";
}
if ($warnings > 0) {
    echo "  {$warnings} warning(s) above — stale data, or data in a directory that\n";
    echo "  the next upgrade of that plugin will delete.\n\n";
}
exit($problems > 0 ? 1 : 0);
