<?php
declare(strict_types=1);

/**
 * WorkerBase — Abstract base for production queue workers.
 *
 * Provides:
 *   - Atomic dequeue from EventBus or job_queue
 *   - Retry with exponential backoff
 *   - Dead-letter handling + admin alerting
 *   - Single-instance locking (flock)
 *   - Execution time limits
 *   - Structured logging
 *
 * Subclasses implement:
 *   - getEventTypes(): array   — which event types to consume
 *   - handle(array $event): void — process a single event
 *
 * PHP 7.4 compatible.
 */
abstract class WorkerBase
{
    protected \PDO $pdo;
    protected EventBus $bus;
    protected $store;
    protected array $config;

    /** Lock file path for single-instance enforcement */
    private string $lockFile;
    /** @var resource|false */
    private $lockFp = false;

    private int $startTime;
    private int $maxRunSeconds;
    private int $batchSize;

    public function __construct($store, array $config, int $maxRunSeconds = 55, int $batchSize = 20)
    {
        $this->store = $store;
        $this->config = $config;
        $this->pdo = $store->getPdo();
        $this->bus = new EventBus($this->pdo);
        $this->maxRunSeconds = $maxRunSeconds;
        $this->batchSize = $batchSize;
        $this->startTime = time();

        // Lock file named after the worker class
        $dataDir = dirname($this->pdo->query("PRAGMA database_list")->fetch()['file'] ?? '/tmp');
        $this->lockFile = $dataDir . '/' . static::class . '.lock';
    }

    /**
     * Event types this worker handles.
     * @return string[] e.g. ['ticket.status_changed', 'ticket.assigned']
     */
    abstract protected function getEventTypes(): array;

    /**
     * Process a single event. Throw on failure (triggers retry).
     * @param array $event Full event row from events table
     */
    abstract protected function handle(array $event): void;

    /**
     * Run the worker: acquire lock, consume events, process, release.
     * @return array Summary of processing results
     */
    public function run(): array
    {
        // Single-instance lock
        if (!$this->acquireLock()) {
            return ['skipped' => true, 'reason' => 'another instance running'];
        }

        $processed = 0;
        $failed    = 0;
        $types     = $this->getEventTypes();

        try {
            while (!$this->isTimedOut()) {
                $events = $this->consumeFiltered($types, $this->batchSize);
                if (empty($events)) break;

                foreach ($events as $event) {
                    if ($this->isTimedOut()) break;

                    $eid = (int)$event['id'];
                    try {
                        // Decode payload
                        if (is_string($event['payload'] ?? null)) {
                            $event['_payload'] = json_decode($event['payload'], true) ?: [];
                        } else {
                            $event['_payload'] = [];
                        }

                        $this->handle($event);
                        $this->bus->ack($eid);
                        $processed++;
                    } catch (\Throwable $e) {
                        $this->bus->fail($eid, $e->getMessage());
                        $failed++;
                        $this->log("ERROR", "Event #{$eid} ({$event['event_type']}): " . $e->getMessage());
                    }
                }
            }
        } finally {
            $this->releaseLock();
        }

        return ['processed' => $processed, 'failed' => $failed, 'worker' => static::class];
    }

    /**
     * Consume events of the types this worker handles.
     *
     * The type filter is applied in SQL now, not only here. Filtering locally
     * meant claiming 20 events of any type and discarding most of them, so a
     * backlog of unhandled types at the head of the queue starved this worker
     * entirely. The local pass below stays as a second line of defence.
     */
    protected function consumeFiltered(array $types, int $limit): array
    {
        // Ask for our own types. Without this the batch is filled by whatever
        // is oldest — a queue of another type starves this worker completely,
        // and it did.
        $events = $this->bus->consume($limit, '', $types);
        if (empty($types) || in_array('*', $types)) return $events;

        $matched = [];
        $unmatched = [];
        foreach ($events as $e) {
            if (in_array($e['event_type'] ?? '', $types)) {
                $matched[] = $e;
            } else {
                $unmatched[] = $e;
            }
        }

        // Release unmatched events back (reset to pending so other workers can claim)
        foreach ($unmatched as $e) {
            try {
                $this->pdo->prepare("
                    UPDATE events SET status='pending', locked_by=NULL, locked_at=NULL WHERE id=?
                ")->execute([$e['id']]);
            } catch (\Throwable $ignore) {}
        }

        return $matched;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════════════

    protected function isTimedOut(): bool
    {
        return (time() - $this->startTime) >= $this->maxRunSeconds;
    }

    protected function log(string $level, string $message): void
    {
        $ts = date('Y-m-d H:i:s');
        $worker = static::class;
        echo "[{$ts}] [{$worker}] [{$level}] {$message}\n";
    }

    private function acquireLock(): bool
    {
        $this->lockFp = @fopen($this->lockFile, 'w+');
        if (!$this->lockFp) return false;
        if (!flock($this->lockFp, LOCK_EX | LOCK_NB)) {
            fclose($this->lockFp);
            $this->lockFp = false;
            return false;
        }
        fwrite($this->lockFp, (string)getmypid());
        return true;
    }

    private function releaseLock(): void
    {
        if ($this->lockFp) {
            flock($this->lockFp, LOCK_UN);
            fclose($this->lockFp);
            $this->lockFp = false;
        }
    }
}
