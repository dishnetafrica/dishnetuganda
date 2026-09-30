<?php
// Note: No strict_types - included from master.php
/**
 * cron/notify_retry.php — Uganda's automatic retry of failed customer WhatsApps (5.18.54, docs/46 row 30).
 *
 * master.php registers this job only where NotifyGate::RETRIES applies, at every cycle (about five minutes). Which rows
 * are retried, how often and for how long is lib/NotificationRetry.php's to say: receipts, welcomes and quotations that
 * WhatsApp refused or that never left, at most three times; never a message that may already have reached the customer.
 *
 * Or by hand: php cron/notify_retry.php
 */
require_once dirname(__DIR__) . '/lib/timezone.php'; dn_tz_apply();
chdir(dirname(__DIR__));
require_once dirname(__DIR__) . '/lib/StoreInterface.php';
require_once dirname(__DIR__) . '/lib/JsonStore.php';
require_once dirname(__DIR__) . '/lib/SqliteStore.php';
require_once dirname(__DIR__) . '/lib/NotificationService.php';
require_once dirname(__DIR__) . '/lib/PluginConfig.php';
require_once dirname(__DIR__) . '/lib/NotifyGate.php';
require_once dirname(__DIR__) . '/lib/NotificationRetry.php';
require_once dirname(__DIR__) . '/lib/bootstrap_data.php';

$dataDir = getDataDir(dirname(__DIR__));
$store   = SqliteStore::create($dataDir);
$config  = (array)($store->load('kyc_config.json') ?? []) + PluginConfig::load(dirname(__DIR__), $dataDir);

if (!NotifyGate::applies(NotifyGate::RETRIES, $config, $dataDir)) {
    log_msg_notify_retry('Not this install (failed messages wait for a person in the Failed Queue) — nothing to do.');
    return;
}
try {
    $pdo = $store->getPdo();
    $has = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'notification_queue'")->fetchColumn();
    if ($has === 0) {
        log_msg_notify_retry('No failed message has been queued yet — nothing to do.');
        return;
    }
    $r = NotificationRetry::run(new NotificationService($store, $config), $pdo, time(), 'log_msg_notify_retry');
    log_msg_notify_retry(sprintf('Done — due=%d tried=%d sent=%d failed=%d exhausted=%d unfinished=%d busy=%d deferred=%d',
        $r['due'], $r['tried'], $r['sent'], $r['failed'], $r['exhausted'], $r['unfinished'], $r['busy'], $r['deferred']));
} catch (\Throwable $e) {
    log_msg_notify_retry('ERROR: ' . $e->getMessage());
}

// Unique name: master.php includes every scheduled script into one process (see staff_jobs_summary.php).
function log_msg_notify_retry(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] [notify_retry] ' . $msg . "\n";
}
