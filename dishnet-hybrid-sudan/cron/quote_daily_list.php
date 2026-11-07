<?php
declare(strict_types=1);

/**
 * quote_daily_list.php — 5.18.104 (Uganda, root docs/73 §H.2.11): the salespeople's daily list of their quotations.
 *
 * master.php's `quote_daily_list` line runs it every 240 s, only on Uganda (its 'uganda' key, StaffJobsGate) and only
 * while quote_daily_list_enabled is on (its 'flag'); run by hand from the CLI it asks the same itself, with tracking on
 * too (QuoteDailyList::on). It bounds itself against master's cycle: 21 s of its own, cut to what $_m_deadline leaves
 * (JobBudget), and when nothing is left it writes "deferred: master budget" and runs on a later cycle (§H.2.13.4).
 *
 * Each run does one of two things (lib/QuoteDailyList.php run()):
 *   - at the first run at or after 11:00 Kampala, Monday to Saturday, the once-a-day sweep: every owner still due today
 *     is written `missed`, with its last code, and nothing is sent;
 *   - otherwise, from 08:00 to 11:00 Kampala, Monday to Saturday, at most one list: the next owner still due, built under
 *     the store's lock, guarded (no 9-digit run outside its refs), claimed on the ledger on a fresh read of the files,
 *     sent from the sales number by AlertService, settled from the raw answer, recorded in quote_list_sends.
 *
 * Its output is one line of counts: no number, no name, no text (§H.2.14 M). A test that includes this file may set
 * $_qf_test_now, the run's clock (unix time); nothing else sets it.
 */
if (PHP_SAPI !== 'cli') return;

$pluginRoot = dirname(__DIR__);
require_once $pluginRoot . '/lib/error_handler.php';
require_once $pluginRoot . '/lib/bootstrap_data.php';
require_once $pluginRoot . '/lib/StoreInterface.php';
require_once $pluginRoot . '/lib/JsonStore.php';
require_once $pluginRoot . '/lib/SqliteStore.php';
require_once $pluginRoot . '/lib/PluginConfig.php';
require_once $pluginRoot . '/lib/StaffJobsGate.php';
require_once $pluginRoot . '/lib/JobBudget.php';
require_once $pluginRoot . '/lib/QuoteDailyList.php';

$dataDir = getDataDir($pluginRoot);
$store   = SqliteStore::create($dataDir);
$config  = PluginConfig::load($pluginRoot, $dataDir);

if (!QuoteDailyList::on($config, $dataDir)) return;   // quiet: South Sudan, and Uganda with the list or tracking off

$qlTest  = isset($_qf_test_now) ? (int)$_qf_test_now : null;
$qlClock = function () use ($qlTest): int { return $qlTest ?? time(); };
$qlStart = $qlClock();
$qlUntil = $qlStart + JobBudget::seconds(QuoteDailyList::OWN_S, isset($_m_deadline) ? (int)$_m_deadline : null, $qlStart);
if ($qlUntil <= $qlStart) {
    echo "quote_daily_list: deferred: master budget\n";
    return;
}

$qlCounts = QuoteDailyList::run($store->getPdo(), $config, $dataDir, $store, $pluginRoot, $qlClock, $qlUntil);
$qlLine = [];
foreach ($qlCounts as $qlK => $qlV) $qlLine[] = $qlK . '=' . (int)$qlV;
echo 'quote_daily_list: ' . implode(' ', $qlLine) . "\n";
