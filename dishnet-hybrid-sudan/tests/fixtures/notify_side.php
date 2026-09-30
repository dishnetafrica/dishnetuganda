<?php
declare(strict_types=1);
/**
 * notify_side.php — what the retailer app and the staff collections do about a receipt, run from the plugin tree
 * under test (a weakened copy included), against a harness data directory. TEST ONLY.
 *
 *   php notify_side.php <pluginRoot> <dataDir> app   <crmPaymentId|0> <ref> <txnRef>   → prints sent|skipped
 *   php notify_side.php <pluginRoot> <dataDir> staff <crmPaymentId> <ref>              → prints sent|skipped
 *
 * "app" mirrors includes/api/api_retailer.php's receipt block, "staff" includes/post/post_field.php's; the test
 * pins those files to the same calls in the same order.
 */
[$_, $root, $dataDir, $op] = array_pad($argv, 4, '');
$args = array_slice($argv, 4);
putenv('DN_DATA_DIR=' . $dataDir);
require_once $root . '/lib/timezone.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/NotificationService.php';
require_once $root . '/lib/NotifyGate.php';
require_once $root . '/lib/ReceiptOnce.php';
dn_tz_apply();
$store  = SqliteStore::create($dataDir);
require_once $root . '/lib/PluginConfig.php';
$config = (array)($store->load('kyc_config.json') ?? []) + PluginConfig::load($root, $dataDir);   // as webhook.php builds it
$notify = new NotificationService($store, $config);
$ug     = NotifyGate::applies(NotifyGate::RECEIPT_ONCE, $config, $dataDir);

if ($op === 'app') {
    [$pid, $ref, $txnRef] = array_pad($args, 3, '');
    $pid = (int)$pid;
    if ($ug) {
        if (ReceiptOnce::claimCollection($notify, $pid ?: null, (string)$ref)) {
            $notify->paymentReceived('256700000007', 'Test Payer', 90000.0, $txnRef);
            echo 'sent';
        } else {
            echo 'skipped';
        }
    } else {
        $notify->paymentReceived('256700000007', 'Test Payer', 90000.0, $txnRef);   // 5.18.53: send, then a note file
        echo 'sent';
    }
    exit(0);
}
if ($op === 'staff') {
    [$pid, $ref] = array_pad($args, 2, '');
    $pid = (int)$pid;
    if (!$notify->dedupMark('PAY' . $pid)) { echo 'skipped'; exit(0); }
    if ($ug && $ref !== '') $notify->dedupMark(ReceiptOnce::refKey($ref));
    $notify->paymentReceived('256700000007', 'Test Payer', 90000.0, 'PAY-' . $pid);
    if ($ug) $notify->dedupMark(ReceiptOnce::pdfKey($pid));   // queued, and already sent by the cron
    echo 'sent';
    exit(0);
}
fwrite(STDERR, "unknown op\n");
exit(2);
