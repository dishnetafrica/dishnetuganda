<?php
declare(strict_types=1);

/**
 * quote_chase.php — 5.18.104 (Uganda, root docs/73 §H.2.9, §H.2.10): quote tracking, then the chase.
 *
 * master.php's `quote_chase` line runs it only on Uganda (its 'uganda' key, StaffJobsGate) and only while
 * quote_tracking_enabled is on (its 'flag'); run by hand from the CLI it asks the same itself. It bounds itself
 * against master's cycle: 20 s of its own, cut to what $_m_deadline leaves (JobBudget), and when nothing is left it
 * writes "deferred: master budget" and runs on a later cycle (§H.2.13.4).
 *
 *   1. Tracking (P2a, lib/QuoteTracker.php): it reads the quotation texts the senders stored, gives each quotation one
 *      tracker row with its owner, and sets the statuses its stops find. It sends nothing and calls no one. With the
 *      chase off this is all the job does (§H.2.10.1): rows are tracked, owners resolved and stops applied, so the
 *      daily list and the staff page read them, and nothing is opened or drafted.
 *   2. The chase (P2b, lib/QuoteChase.php): opening and drafting, only while QuoteChase::on(). It sends nothing; the
 *      quote pass of cron/followup_send.php sends what is drafted and approved here.
 *
 * Its output is counts and fixed codes only: no number, no name, no text (§H.2.14 M).
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
require_once $pluginRoot . '/lib/QuoteTracker.php';

$dataDir = getDataDir($pluginRoot);
$store   = SqliteStore::create($dataDir);
$config  = PluginConfig::load($pluginRoot, $dataDir);

if (!QuoteTracker::on($config, $dataDir)) return;   // quiet: South Sudan, and Uganda with tracking off

$qcStart = time();
$qcUntil = $qcStart + JobBudget::seconds(20, isset($_m_deadline) ? (int)$_m_deadline : null, $qcStart);
if ($qcUntil <= $qcStart) {
    echo "quote_chase: deferred: master budget\n";
    return;
}

// ── 1. Tracking (§H.2.9): one moment for the whole run, taken as it begins (ruling R18; Q16-F04) ────────────────────
$qcCounts = QuoteTracker::run($store->getPdo(), $config, $dataDir, $store, $qcStart, $qcUntil);
$qcLine = [];
foreach ($qcCounts as $qcK => $qcV) $qcLine[] = $qcK . '=' . (int)$qcV;
echo 'quote_chase: tracking ' . implode(' ', $qcLine) . "\n";

// ── 2. The chase (§H.2.10.3–§H.2.10.5), only while QuoteChase::on(): Uganda, quote_tracking_enabled, quote_chase_enabled,
// the ledger on and dn_tz() Kampala (§H.2.10.1). Opening first (holds, then one BEGIN IMMEDIATE per chase), then drafting
// (stale drafts expired, the uCRM status read at most 3 times, the template drafted and approved as quote_chase). Both
// work inside what remains of $qcUntil; a uCRM GET starts only with 5 s left (§H.2.13.4). It sends nothing: the quote
// pass of cron/followup_send.php does.
require_once $pluginRoot . '/lib/QuoteChase.php';
if (QuoteChase::on($config, $dataDir) && time() < $qcUntil) {
    $qcOpen  = QuoteChase::open($store->getPdo(), $config, $dataDir, $store, time(), $qcUntil);
    $qcDraft = QuoteChase::draftDue($store->getPdo(), $config, $dataDir, $store, time(), $qcUntil);
    $qcLine = [];
    foreach ($qcOpen as $qcK => $qcV) $qcLine[] = 'open_' . $qcK . '=' . (int)$qcV;
    foreach ($qcDraft as $qcK => $qcV) $qcLine[] = 'draft_' . $qcK . '=' . (int)$qcV;
    echo 'quote_chase: chase ' . implode(' ', $qcLine) . "\n";
}
