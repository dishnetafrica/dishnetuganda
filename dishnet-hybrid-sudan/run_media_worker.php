<?php
declare(strict_types=1);
chdir(__DIR__);
require_once __DIR__ . '/lib/error_handler.php';

/**
 * run_media_worker.php — fetch and validate the media customers sent, once (Batch 1, docs/55 §9).
 *
 * Spawned in the background by evo_webhook.php when a voice note, photo or document is recorded, and run every
 * minute by cron/master.php as the guaranteed path. Deliberately NOT part of run_worker.php: the text reply and the
 * media fetch run in separate processes under separate locks, so a slow download can never delay an answer.
 *
 * With ai_media_enabled off there is normally nothing to do (the webhook records nothing), and this returns after one
 * indexed read. The one exception is an event queued while the flag was on: MediaWorker marks its row skipped and
 * acknowledges it, fetching nothing.
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
require_once __DIR__ . '/lib/MediaPolicy.php';
require_once __DIR__ . '/workers/WorkerBase.php';
require_once __DIR__ . '/workers/MediaWorker.php';

$store  = SqliteStore::create($dataDir);
$config = PluginConfig::load(__DIR__, $dataDir);

// Batch 4 (docs/58 §4): a document, its base64 and its inflated parts are in memory at once. Where the CLI's limit is lower
// than 256M it is raised to that; an unlimited CLI (-1) is left alone. The extractor also refuses a file that would not fit
// whatever the limit is, rather than fail half-way.
$mediaMemoryLimit = DocumentExtraction::memoryLimitBytes();
if ($mediaMemoryLimit > 0 && $mediaMemoryLimit < 256 * 1024 * 1024) @ini_set('memory_limit', '256M');
unset($mediaMemoryLimit);

if (!PluginConfig::toBool($config['ai_enabled'] ?? false)) {
    return;
}
if (!MediaPolicy::enabled($config)) {
    try {
        $pending = (int)$store->getPdo()
            ->query("SELECT COUNT(*) FROM events WHERE event_type = 'ai.media' AND status IN ('pending', 'failed')")
            ->fetchColumn();
    } catch (\Throwable $e) {
        $pending = 0;
    }
    if ($pending === 0) return;
}

// WorkerBase::log() writes to stdout, which the spawn discards. Capture it into ai_platform.log beside the reply
// worker's trace; the lines carry [MediaWorker], and never the media itself.
ob_start();
try {
    $result = (new MediaWorker($store, $config, 45, 5))->run();
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
        '[' . gmdate('Y-m-d H:i:s') . '] media worker crashed: ' . $e->getMessage() . PHP_EOL,
        FILE_APPEND
    );
    return;
}
return;
