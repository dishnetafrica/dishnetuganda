<?php
declare(strict_types=1);
/**
 * dpo_reconcile.php — the third leg.
 *
 * The browser return catches the customer who waits. DPO's push catches most
 * of the rest. This catches the one neither does: paid on a phone, then the
 * browser closed, and no push arrived or it arrived while we were down.
 * Without it, money reaches DPO and never reaches the invoice.
 *
 * ── IT RUNS WHETHER OR NOT DPO IS ENABLED ───────────────────────────────
 *
 * The kill switch stops NEW payments. Money already taken must still find its
 * way to an invoice — switching the feature off must never strand it.
 *
 * ── IT return;s, IT NEVER exit()s ───────────────────────────────────────
 *
 * master.php INCLUDES its crons. An exit() here would silently kill every
 * cron scheduled after it, and the symptom would be somebody else's job
 * quietly not running.
 */

$_dr_root    = dirname(__DIR__);
require_once $_dr_root . '/lib/bootstrap_data.php';
require_once $_dr_root . '/lib/StoreInterface.php';
require_once $_dr_root . '/lib/SqliteStore.php';
require_once $_dr_root . '/lib/PluginConfig.php';
require_once $_dr_root . '/lib/MigrationRunner.php';
require_once $_dr_root . '/lib/CrmApiClient.php';
require_once $_dr_root . '/lib/DpoBootstrap.php';

$_dr_dataDir = getDataDir($_dr_root);
$_dr_config  = PluginConfig::load($_dr_root, $_dr_dataDir);

try {
    $_dr_store = SqliteStore::create($_dr_dataDir);
    $_dr_pdo   = $_dr_store->getPdo();
    (new MigrationRunner($_dr_pdo, $_dr_root . '/migrations'))->run();

    // Nothing to reconcile before the table has ever been written to. This is
    // the normal state until the first payment, so it must be silent.
    $_dr_has = $_dr_pdo->query(
        "SELECT name FROM sqlite_master WHERE type='table' AND name='dpo_payments'")->fetch();
    if (!$_dr_has) return;

    $_dr_ps   = new DpoPaymentStore($_dr_pdo);
    $_dr_open = $_dr_ps->openOlderThan(120, 1);
    if ($_dr_open === []) return;          // no open attempts — no DPO calls, no log noise

    $_dr_crm = CrmApiClient::fromUcrm($_dr_root, $_dr_config);
    $_dr_svc = DpoBootstrap::service($_dr_store, $_dr_config, $_dr_crm,
        function (string $e, string $d) use ($_dr_dataDir) {
            if (function_exists('logActivity')) { logActivity($_dr_dataDir, $e, 'DPO Pay', $d); }
            else { error_log('[dpo] ' . $e . ' — ' . $d); }
        });

    // 120s grace so the customer's own return gets first refusal at the row,
    // and a cap so one run cannot sit on DPO for minutes.
    $_dr_out = $_dr_svc->reconcile(120, 25);

    if (($_dr_out['checked'] ?? 0) > 0) {
        error_log('[dpo_reconcile] ' . json_encode($_dr_out));
    }
    if (($_dr_out['quarantined'] ?? 0) > 0) {
        error_log('[dpo_reconcile] ' . $_dr_out['quarantined']
                . ' payment(s) quarantined — a person must look at these under '
                . 'Admin → DPO Payments');
    }
} catch (\Throwable $_dr_e) {
    error_log('[dpo_reconcile] ' . $_dr_e->getMessage());
}
