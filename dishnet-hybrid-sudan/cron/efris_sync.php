<?php
declare(strict_types=1);

/**
 * cron/efris_sync.php — the EFRIS safety net, every ~2 minutes via master.php.
 *
 * Two jobs, in order:
 *   1. DRAIN the efris.submit queue (EfrisWorker) — the guaranteed path for
 *      events enqueued by webhook.php or the admin tab.
 *   2. SCAN recent approved uCRM invoices with no EFRIS transaction and, when
 *      auto-submit is on, enqueue them. This exists because uCRM demonstrably
 *      does NOT fire webhooks for every auto-generated invoice on this
 *      install (cron_invoice_notify.php exists for the same reason).
 *
 * Runs ONLY in efris_environment=test during Phase 1; disabled and production
 * both exit without touching anything.
 */

// No SAPI guard, same as the other master-included cron scripts: master.php
// runs us from the web context after the response is flushed, and uCRM never
// serves cron/ files over HTTP anyway (only public.php is routable).

$_efRoot = dirname(__DIR__);
require_once $_efRoot . '/lib/bootstrap_data.php';
require_once $_efRoot . '/lib/StoreInterface.php';
require_once $_efRoot . '/lib/SqliteStore.php';
require_once $_efRoot . '/lib/PluginConfig.php';
require_once $_efRoot . '/lib/EventBus.php';
require_once $_efRoot . '/lib/CrmApiClient.php';
require_once $_efRoot . '/lib/EfrisClient.php';
require_once $_efRoot . '/lib/EfrisService.php';
require_once $_efRoot . '/workers/WorkerBase.php';
require_once $_efRoot . '/workers/EfrisWorker.php';

$_efDataDir = getDataDir($_efRoot);
$_efStore   = SqliteStore::create($_efDataDir);
$_efConfig  = PluginConfig::load($_efRoot, $_efDataDir);

$_efEnv = strtolower(trim((string)($_efConfig['efris_environment'] ?? '')));
if ($_efEnv !== EfrisClient::ENV_TEST) {
    return; // disabled (default) or production (Phase 2): nothing runs
}

$_efLog = function (string $m) use ($_efDataDir): void {
    @file_put_contents($_efDataDir . '/efris.log',
        '[' . gmdate('Y-m-d H:i:s') . '] [sync] ' . $m . "\n", FILE_APPEND | LOCK_EX);
};

// ── 1. Drain the queue ──────────────────────────────────────────────────────
try {
    $r = (new EfrisWorker($_efStore, $_efConfig, 45, 10))->run();
    if (!empty($r['processed']) || !empty($r['failed'])) {
        $_efLog("worker: processed={$r['processed']} failed={$r['failed']}");
    }
} catch (\Throwable $e) {
    $_efLog('worker crashed: ' . $e->getMessage());
}

// ── 2. Scan for invoices the webhooks missed ────────────────────────────────
if (!PluginConfig::toBool($_efConfig['efris_auto_submit'] ?? false)) {
    return;
}
try {
    $crm = CrmApiClient::fromUcrm($_efRoot, $_efConfig);
    if (!$crm->isConfigured()) return;

    $svc = new EfrisService($_efStore, $_efConfig, $_efDataDir, $crm);
    $tx  = $svc->transactions();
    $bus = new EventBus($_efStore->getPdo());

    // Recent window only: EFRIS is for new invoices, not for fiscalising
    // history in bulk by accident. Backfill, if ever wanted, is a deliberate
    // manual action in the admin tab, one invoice at a time.
    $since = gmdate('Y-m-d', time() - 3 * 86400);
    $invoices = $crm->get('invoices?createdDateFrom=' . $since . '&limit=100') ?? [];
    if (!is_array($invoices)) return;

    $queued = 0;
    foreach ($invoices as $inv) {
        if (!is_array($inv)) continue;
        $id = (int)($inv['id'] ?? 0);
        if ($id <= 0 || !$svc->eligible($inv)) continue;
        if ($tx->find($id) !== null) continue;          // already claimed/handled
        $bus->emit('efris.submit', 'invoice', $id,
            ['invoice_id' => $id, 'source' => 'scan'], 5, 'efris_sync');
        $queued++;
        if ($queued >= 20) break;                       // spread load across runs
    }
    if ($queued > 0) $_efLog("scan: queued {$queued} invoice(s) the webhooks missed");
} catch (\Throwable $e) {
    $_efLog('scan crashed: ' . $e->getMessage());
}
