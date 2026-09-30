<?php
// Note: No strict_types - included from master.php
/**
 * cron/customer_reminders.php — Uganda's payment reminders and win-back, in the daytime (5.18.54, docs/46 rows 5-8
 * and 20).
 *
 * master.php registers this job only where NotifyGate::REMINDERS applies, and runs it once a day at the first cycle
 * between reminder_hour (default 9) and reminder_until_hour (default 17), local time: a cycle missed at 09:00 is
 * made up later that day, once. The 02:00 maintenance job skips the same tasks where the fix applies, so there is
 * one path. South Sudan's schedule does not change.
 *
 * Or by hand: php cron/customer_reminders.php
 */
require_once dirname(__DIR__) . '/lib/timezone.php'; dn_tz_apply();
chdir(dirname(__DIR__));
require_once dirname(__DIR__) . '/lib/StoreInterface.php';
require_once dirname(__DIR__) . '/lib/JsonStore.php';
require_once dirname(__DIR__) . '/lib/SqliteStore.php';
require_once dirname(__DIR__) . '/lib/CrmApiClient.php';
require_once dirname(__DIR__) . '/lib/NotificationService.php';
require_once dirname(__DIR__) . '/lib/PluginConfig.php';
require_once dirname(__DIR__) . '/lib/NotifyGate.php';
require_once dirname(__DIR__) . '/lib/InvoiceReminders.php';
require_once dirname(__DIR__) . '/lib/WinBack.php';
require_once dirname(__DIR__) . '/lib/currency.php';
require_once dirname(__DIR__) . '/lib/bootstrap_data.php';

$dataDir = getDataDir(dirname(__DIR__));
$store   = SqliteStore::create($dataDir);
$config  = (array)($store->load('kyc_config.json') ?? []) + PluginConfig::load(dirname(__DIR__), $dataDir);

if (!NotifyGate::applies(NotifyGate::REMINDERS, $config, $dataDir)) {
    log_msg_customer_reminders('Not this install (the reminders stay in the 02:00 maintenance job) — nothing to do.');
    return;
}
$crm = CrmApiClient::fromUcrm(dirname(__DIR__), $config);
if (!$crm->isConfigured()) {
    log_msg_customer_reminders('SKIP — uCRM is not configured.');
    return;
}
$notify = new NotificationService($store, $config);
$today  = new DateTimeImmutable('today', dn_tz_obj());

log_msg_customer_reminders('Payment reminders for ' . $today->format('Y-m-d') . ' (' . dn_tz() . ')');
try {
    $r = (new InvoiceReminders($crm, $notify, $config, 'log_msg_customer_reminders'))->run($today);
    log_msg_customer_reminders(sprintf('Reminders done — before due: 7d=%d 3d=%d 1d=%d; after due: 1d=%d 3d=%d 5d=%d 7d=%d; '
        . 'suppressed (prepaid)=%d, skipped=%d, errors=%d, invoices read=%d',
        $r['pre']['d7'], $r['pre']['d3'], $r['pre']['d1'],
        $r['overdue']['d1'], $r['overdue']['d3'], $r['overdue']['d5'], $r['overdue']['d7'],
        $r['suppressed_prepaid'], $r['skipped'], $r['errors'], $r['fetched']));
} catch (\Throwable $e) {
    log_msg_customer_reminders('Reminders ERROR: ' . $e->getMessage());
}
try {
    $w = (new WinBack($crm, $notify, $store, 'log_msg_customer_reminders'))->run($today);
    log_msg_customer_reminders("Win-back done — sent={$w['sent']}, skipped={$w['skipped']}");
} catch (\Throwable $e) {
    log_msg_customer_reminders('Win-back ERROR: ' . $e->getMessage());
}

// Unique name: master.php includes every scheduled script into one process (see staff_jobs_summary.php).
function log_msg_customer_reminders(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] [customer_reminders] ' . $msg . "\n";
}
