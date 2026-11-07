<?php
declare(strict_types=1);
/**
 * TEST ONLY — a reply worker in its own process (5.18.104, root docs/73 §H.3.24 VK7, VK8): constructed on the
 * configuration in --cfg (a JSON file: the read a worker started before an `on` or `off` took), it waits --delay
 * seconds, then runs once, as run_worker.php runs it, against the book in --data. P5 reads the Kampala moment --clock
 * (advancing with the real clock), and a fake brain answers --canned. Prints one JSON line: the run's summary and log.
 * Fictitious numbers only; nothing leaves 127.0.0.1.
 */
$o = getopt('', ['tree:', 'data:', 'cfg:', 'delay:', 'clock:', 'canned:']);
$tree = (string)$o['tree']; $data = (string)$o['data'];
date_default_timezone_set('UTC');
putenv('DN_DATA_DIR=' . $data);
foreach (['lib/bootstrap_data.php', 'lib/StoreInterface.php', 'lib/SqliteStore.php', 'lib/EventBus.php', 'lib/UtcClock.php', 'lib/PluginConfig.php',
          'lib/ConversationService.php', 'lib/EvolutionApiService.php', 'lib/DishNetAiBrain.php', 'lib/VoiceAck.php',
          'workers/WorkerBase.php', 'workers/AiReplyWorker.php'] as $f) require_once $tree . '/' . $f;
require_once __DIR__ . '/fakes.php';
$cfg = json_decode((string)file_get_contents((string)$o['cfg']), true) ?: [];
$store = SqliteStore::create($data);
$w = new AiReplyWorker($store, $cfg, 45, 10);
if (isset($o['canned'])) {
    $b = new VaBrain($cfg); $b->canned = (string)$o['canned'];
    $p = new ReflectionProperty(AiReplyWorker::class, 'brain'); $p->setAccessible(true); $p->setValue($w, $b);
}
usleep((int)(1000000 * (float)($o['delay'] ?? 0)));
$t = (int)($o['clock'] ?? time());
$real = time();
VoiceAck::useClock(function () use ($t, $real): int { return $t + (time() - $real); });
$store->getPdo()->exec("UPDATE events SET next_retry_at = datetime('now', '-1 second') WHERE status IN ('pending', 'failed')");
ob_start();
$r = $w->run();
$log = (string)ob_get_clean();
echo json_encode(['run' => $r, 'log' => $log, 'asked' => isset($b) ? count($b->contexts) : null]), "\n";
