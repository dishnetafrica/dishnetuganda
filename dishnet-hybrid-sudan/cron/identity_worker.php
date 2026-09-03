<?php
declare(strict_types=1);

/**
 * identity_worker.php — drain the customer-identity queue once.
 *
 * The webhook reserves addresses and marks pending_action; this runner does
 * the slow parts (mail server + uCRM write-back) with attempt² backoff.
 * Scheduled every 60s by cron/master.php. Safe to run concurrently with
 * anything: the queue rows themselves carry the idempotency.
 *
 * CLI only — refuses to run over HTTP.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$pluginRoot = dirname(__DIR__);
require_once $pluginRoot . '/lib/error_handler.php';
require_once $pluginRoot . '/lib/bootstrap_data.php';
require_once $pluginRoot . '/lib/StoreInterface.php';
require_once $pluginRoot . '/lib/JsonStore.php';
require_once $pluginRoot . '/lib/SqliteStore.php';
require_once $pluginRoot . '/lib/PluginConfig.php';
require_once $pluginRoot . '/lib/EventBus.php';
require_once $pluginRoot . '/lib/CrmApiClient.php';
require_once $pluginRoot . '/lib/MailProviderInterface.php';
require_once $pluginRoot . '/lib/StalwartProvider.php';
require_once $pluginRoot . '/lib/CustomerIdentityService.php';

$dataDir = getDataDir($pluginRoot);
$store   = SqliteStore::create($dataDir);
$config  = PluginConfig::load($pluginRoot, $dataDir);

if (empty($config['identity_enabled'])) exit(0);   // quiet: normal during setup

$pdo      = $store->getPdo();
$provider = new StalwartProvider($config);
if (!$provider->isConfigured()) {
    error_log('[identity_worker] identity_enabled but stalwart_api_url/token not set');
    exit(0);
}

$crm = CrmApiClient::fromUcrm($pluginRoot, $config);
if (!$crm->isConfigured()) $crm = null;

$svc = new CustomerIdentityService($pdo, $config, $provider, $crm, new EventBus($pdo));
$res = $svc->processPending(10);
if (($res['processed'] ?? 0) > 0 || ($res['failed'] ?? 0) > 0) {
    echo json_encode(['identity_worker' => $res]) . "\n";
}
