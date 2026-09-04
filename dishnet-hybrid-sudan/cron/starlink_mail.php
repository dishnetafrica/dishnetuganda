<?php
declare(strict_types=1);

/**
 * starlink_mail.php — poll the Starlink intake mailbox once.
 *
 * Reads starlink@<domain> over JMAP, classifies each new message, stores it
 * exactly once in starlink_events, and routes the outcome (uCRM timeline,
 * customer WhatsApp, or staff alert). Scheduled every 300s by cron/master.php.
 * The worker takes a flock so overlapping runs are impossible.
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
require_once $pluginRoot . '/lib/EvolutionApiService.php';
require_once $pluginRoot . '/lib/AlertService.php';
require_once $pluginRoot . '/lib/MailProviderInterface.php';
require_once $pluginRoot . '/lib/StalwartProvider.php';
require_once $pluginRoot . '/lib/CustomerIdentityService.php';
require_once $pluginRoot . '/lib/StarlinkMailClassifier.php';
require_once $pluginRoot . '/workers/StarlinkMailWorker.php';

$dataDir = getDataDir($pluginRoot);
$store   = SqliteStore::create($dataDir);
$config  = PluginConfig::load($pluginRoot, $dataDir);

if (empty($config['starlink_mail_enabled'])) exit(0);   // quiet: normal during setup

$pdo = $store->getPdo();

$crm = CrmApiClient::fromUcrm($pluginRoot, $config);
if (!$crm->isConfigured()) $crm = null;

$evo      = new EvolutionApiService($config);
$alerts   = new AlertService($store, $config, $evo);
$identity = new CustomerIdentityService($pdo, $config, new StalwartProvider($config), $crm, new EventBus($pdo));

$worker = new StarlinkMailWorker($pdo, $config, $identity, $evo, $alerts, $crm);
$res    = $worker->run(25);
if (!isset($res['skipped'])) {
    echo json_encode(['starlink_mail' => $res]) . "\n";
}
