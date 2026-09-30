<?php
declare(strict_types=1);
/**
 * notify_reminders_side.php — one pass of Uganda's reminder run (InvoiceReminders, then WinBack) from the plugin tree
 * under test, against a harness data directory, on a given day. TEST ONLY.
 *
 *   php notify_reminders_side.php <pluginRoot> <dataDir> <Y-m-d> [winback]   → prints the result as JSON
 */
[$_, $root, $dataDir, $day] = array_pad($argv, 4, '');
$withWinback = ($argv[4] ?? '') === 'winback';
putenv('DN_DATA_DIR=' . $dataDir);   // the zone is read from this directory's kyc_config.json, as on the server
require_once $root . '/lib/timezone.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/CrmApiClient.php';
require_once $root . '/lib/NotificationService.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/currency.php';
require_once $root . '/lib/InvoiceReminders.php';
require_once $root . '/lib/WinBack.php';
$store  = SqliteStore::create($dataDir);
$config = (array)($store->load('kyc_config.json') ?? []) + PluginConfig::load($root, $dataDir);
dn_tz_apply();
$GLOBALS['dataDir'] = $dataDir;
$crm    = CrmApiClient::fromUcrm($root, $config);
$notify = new NotificationService($store, $config);
$today  = new DateTimeImmutable($day . ' 00:00:00', dn_tz_obj());
$lines  = [];
$log    = function (string $m) use (&$lines) { $lines[] = $m; };
$out    = ['reminders' => (new InvoiceReminders($crm, $notify, $config, $log))->run($today)];
if ($withWinback) $out['winback'] = (new WinBack($crm, $notify, $store, $log))->run($today);
$out['log'] = $lines;
echo json_encode($out);
