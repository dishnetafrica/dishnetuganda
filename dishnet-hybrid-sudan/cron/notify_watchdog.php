<?php
// Note: No strict_types - included from master.php
/**
 * cron/notify_watchdog.php — Uganda's watchdog for notification jobs and the failure queue (5.18.54, docs/46 row 32).
 *
 * master.php registers this job only where NotifyGate::WATCHDOG applies, second in its list (after the Starlink
 * keep-alive), so that a job which spends the time budget cannot starve it. What it checks, and how often it may say so,
 * is lib/NotifyWatchdog.php's to say.
 *
 * Or by hand: php cron/notify_watchdog.php — then another master run may hold a job at -1 legitimately.
 */
require_once dirname(__DIR__) . '/lib/timezone.php'; dn_tz_apply();
chdir(dirname(__DIR__));
require_once dirname(__DIR__) . '/lib/StoreInterface.php';
require_once dirname(__DIR__) . '/lib/JsonStore.php';
require_once dirname(__DIR__) . '/lib/SqliteStore.php';
require_once dirname(__DIR__) . '/lib/NotificationService.php';
require_once dirname(__DIR__) . '/lib/PluginConfig.php';
require_once dirname(__DIR__) . '/lib/NotifyGate.php';
require_once dirname(__DIR__) . '/lib/NotifyWatchdog.php';
require_once dirname(__DIR__) . '/lib/bootstrap_data.php';

$dataDir  = getDataDir(dirname(__DIR__));
$store    = SqliteStore::create($dataDir);
$dbConfig = (array)($store->load('kyc_config.json') ?? []);          // what most scheduled jobs read, alone
$files    = PluginConfig::load(dirname(__DIR__), $dataDir);           // what the webhook reads
$config   = $dbConfig + $files;

if (!NotifyGate::applies(NotifyGate::WATCHDOG, $config, $dataDir)) {
    log_msg_notify_watchdog('Not this install — nothing to do.');
    return;
}
try {
    $schedule = (array)($store->load('master_schedule.json') ?? []);
    $r = NotifyWatchdog::run(new NotificationService($store, $config), $store->getPdo(), $schedule, time(), 'log_msg_notify_watchdog',
                             $dbConfig, $files);
    log_msg_notify_watchdog('Done — ' . ($r['held'] ? 'holding: ' . implode(', ', $r['held']) : 'all well')
        . ($r['cooling'] ? '; already alerted: ' . implode(', ', $r['cooling']) : ''));
} catch (\Throwable $e) {
    log_msg_notify_watchdog('ERROR: ' . $e->getMessage());
}

// Unique name: master.php includes every scheduled script into one process (see staff_jobs_summary.php).
function log_msg_notify_watchdog(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] [notify_watchdog] ' . $msg . "\n";
}
