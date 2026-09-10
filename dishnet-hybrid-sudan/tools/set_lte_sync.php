<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * set_lte_sync.php — should this installation sync South Sudan's LTE bridge?
 *
 *   php tools/set_lte_sync.php          what it is set to now
 *   php tools/set_lte_sync.php --off    stop syncing BlueCard here
 *   php tools/set_lte_sync.php --on     resume
 *
 * The three LTE crons — lte_sync, lte_usage, lte_cron — poll a BlueCard feed
 * that belongs to the South Sudan operation. Uganda has neither. Until now
 * there was no way to say so: with lte_feed_url unset, cron_lte_sync.php falls
 * back to a hardcoded dishnetss.com URL and syncs regardless.
 *
 * That is not merely wasted work. Several feed calls at CURLOPT_TIMEOUT => 60
 * exceed the 120-second limit master.php gives that job, and the resulting
 * fatal is uncatchable — it ended the whole cron cycle, taking every job
 * behind lte_sync with it.
 *
 * The setting is an OPT-OUT. Absent means sync, exactly as before, so the
 * Sudan installation behaves identically whether or not this key exists.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/PluginConfig.php';

$dataDir = getenv('DN_DATA_DIR') ?: getDataDir($root);
$config  = PluginConfig::load($root, $dataDir);
$args    = array_slice($argv, 1);

$state = function (array $c): string {
    if (!array_key_exists('lte_sync_enabled', $c)) return 'ON (not set — the default)';
    return filter_var($c['lte_sync_enabled'], FILTER_VALIDATE_BOOLEAN) ? 'ON' : 'OFF';
};

if (!in_array('--off', $args, true) && !in_array('--on', $args, true)) {
    echo "\n  LTE / BlueCard sync   " . $state($config) . "\n";
    echo "  feed                  " . ((string)($config['lte_feed_url'] ?? '') ?: 'not set (falls back to the built-in Sudan URL)') . "\n";
    echo "\n  Jobs affected: lte_sync, lte_usage, lte_cron\n";
    echo "\n    php tools/set_lte_sync.php --off\n";
    echo "    php tools/set_lte_sync.php --on\n\n";
    exit(0);
}

$want = in_array('--on', $args, true);
list($ok, $err) = PluginConfig::saveOverrides($dataDir, ['lte_sync_enabled' => $want ? '1' : '0']);
if (!$ok) { echo "\n  Could not save: " . (string)$err . "\n\n"; exit(1); }

$fresh = PluginConfig::load($root, $dataDir);
echo "\n  LTE / BlueCard sync is now " . $state($fresh) . "\n";
echo $want
    ? "  The three LTE jobs will poll the BlueCard feed again.\n\n"
    : "  lte_sync, lte_usage and lte_cron now return immediately here.\n\n";
exit(0);
