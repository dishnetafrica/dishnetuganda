<?php
/**
 * cron/kyc_crm_sync.php — put KYC customers who are saved in the plugin but not
 * in uCRM into uCRM. Runs every 5 minutes via master.php.
 *
 * The work is lib/KycCrmSync.php, which the Orders screen's "Retry now" uses
 * too. This file only opens the store and the uCRM client the way every other
 * cron job does.
 *
 * Until 5.18.28 this job never did anything: it looked for data/data.db, which
 * nothing creates (the database is plugin.sqlite3 in getDataDir()), then called
 * SqliteStore's private constructor, then read four settings no screen writes.
 * It returned at the first of those in about 2 ms, every five minutes, and
 * master.php logged a normal run (measured on the Uganda install, 25 Sep 2026).
 *
 * Uses return; not exit() — required by master.php (v4.10.4 rule). Included in
 * master.php's scope, so it sets $dataDir to the same value master.php uses.
 */
chdir(dirname(__DIR__));
require_once __DIR__ . '/../lib/StoreInterface.php';
require_once __DIR__ . '/../lib/JsonStore.php';
require_once __DIR__ . '/../lib/SqliteStore.php';
require_once __DIR__ . '/../lib/CrmApiClient.php';
require_once __DIR__ . '/../lib/bootstrap_data.php';
require_once __DIR__ . '/../lib/KycCrmSync.php';

$dataDir = getDataDir(dirname(__DIR__));
$store   = SqliteStore::create($dataDir);
$config  = $store->load('kyc_config.json') ?? [];
$crm     = CrmApiClient::fromUcrm(dirname(__DIR__), $config);

if (!$crm->isConfigured()) {
    echo "[kyc_crm_sync] CRM not configured — skipping.\n";
    return;
}

$kycSync = (new KycCrmSync($store, $crm, $config))->runDue();
if ($kycSync['due'] + $kycSync['gave_up'] + $kycSync['review'] > 0) {
    echo '[kyc_crm_sync] due ' . $kycSync['due'] . ', created ' . $kycSync['synced']
       . ', still failing ' . $kycSync['failed'] . ', for a person to check ' . $kycSync['review']
       . ', gave up ' . $kycSync['gave_up'] . ', busy ' . $kycSync['busy'] . "\n";
}
return;
