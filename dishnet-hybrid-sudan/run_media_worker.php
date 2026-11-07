<?php
declare(strict_types=1);
chdir(__DIR__);
require_once __DIR__ . '/lib/error_handler.php';

/**
 * run_media_worker.php — fetch, measure and transcribe the voice notes customers sent, once (Batch 1, docs/55 §9;
 * 5.18.104, root docs/73 §H.3.6: ported from the development tree and trimmed).
 *
 * Spawned in the background by evo_webhook.php (`--spawned`) when a voice note is recorded, and included by
 * cron/master.php as the `ai_media` job, the safety net. Deliberately NOT part of run_worker.php: the text reply and
 * the media fetch run in separate processes under separate locks, so a slow download can never delay an answer.
 *
 * Uganda only (StaffJobsGate); then the assistant (ai_enabled); then the media switch. With the switch off there is
 * normally nothing to do (the webhook records nothing); an event queued while it was on is settled skipped by the
 * worker, fetching nothing. Batch 1 in both modes: an event claimed and left unstarted would sit `processing` until the
 * queue released it. Spawned, a run has 90 s, enough for one voice note at the maximum need (55 s) and a second started
 * by 35 s. Under master it has at most 60 s and never runs past master's own deadline ($_m_deadline, in scope because
 * master includes its jobs); it does not start below 10 s.
 *
 * CLI only — refuses to run over HTTP.
 */

if (PHP_SAPI !== 'cli') return;   // a web request is not ours to serve

require_once __DIR__ . '/lib/bootstrap_data.php';
$dataDir = getDataDir(__DIR__);

require_once __DIR__ . '/lib/StoreInterface.php';
require_once __DIR__ . '/lib/SqliteStore.php';
require_once __DIR__ . '/lib/PluginConfig.php';
require_once __DIR__ . '/lib/EventBus.php';
require_once __DIR__ . '/lib/StaffJobsGate.php';
require_once __DIR__ . '/lib/MediaPolicy.php';
require_once __DIR__ . '/workers/WorkerBase.php';
require_once __DIR__ . '/workers/MediaWorker.php';

$store  = SqliteStore::create($dataDir);
$config = PluginConfig::load(__DIR__, $dataDir);

if (!StaffJobsGate::applies($config, $dataDir)) return;
if (!PluginConfig::toBool($config['ai_enabled'] ?? false)) {
    return;
}
if (!MediaPolicy::enabled($config, $dataDir)) {
    try {
        $pending = (int)$store->getPdo()
            ->query("SELECT COUNT(*) FROM events WHERE event_type = 'ai.media' AND status IN ('pending', 'failed')")
            ->fetchColumn();
    } catch (\Throwable $e) {
        $pending = 0;
    }
    if ($pending === 0) return;
}

$_rmwSpawned  = !isset($_m_deadline) && in_array('--spawned', (array)($argv ?? []), true);
$_rmwDeadline = isset($_m_deadline) ? (int)$_m_deadline : null;
if ($_rmwSpawned) {
    $_rmwWorker = new MediaWorker($store, $config, 90, 1);
} else {
    $_rmwBudget = $_rmwDeadline !== null ? max(0, min(60, $_rmwDeadline - time())) : 60;
    if ($_rmwBudget < 10) return;
    $_rmwWorker = new MediaWorker($store, $config, $_rmwBudget, 1, $_rmwDeadline);
}

// WorkerBase::log() writes to stdout, which the spawn discards. Capture it into ai_platform.log beside the reply
// worker's trace; the lines carry [MediaWorker], ids, codes and sizes, and never the media or a transcript.
ob_start();
try {
    $result = $_rmwWorker->run();
    $trace  = ob_get_clean();
    if ($trace !== '' || !empty($result['processed']) || !empty($result['failed']) || !empty($result['deferred'])) {
        @file_put_contents(
            $dataDir . '/ai_platform.log',
            $trace . sprintf("[%s] media worker: processed=%d failed=%d deferred=%d\n",
                gmdate('Y-m-d H:i:s'), $result['processed'] ?? 0, $result['failed'] ?? 0, $result['deferred'] ?? 0),
            FILE_APPEND
        );
    }
} catch (\Throwable $e) {
    $trace = ob_get_clean();
    if ($trace !== '') @file_put_contents($dataDir . '/ai_platform.log', $trace, FILE_APPEND);
    @file_put_contents(
        $dataDir . '/ai_platform.log',
        '[' . gmdate('Y-m-d H:i:s') . '] media worker crashed: ' . get_class($e) . PHP_EOL,
        FILE_APPEND
    );
    return;
}
return;
