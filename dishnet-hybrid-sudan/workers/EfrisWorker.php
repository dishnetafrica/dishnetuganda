<?php
declare(strict_types=1);

/**
 * EfrisWorker — consumes 'efris.submit' events and drives EfrisService.
 *
 * Same contract as every other worker: WorkerBase locks, batches and retries;
 * a thrown exception marks the event failed (retryable), a return means done.
 * Idempotency lives in EfrisStore's DB constraint, so replaying an event for
 * an already-fiscalised invoice is a harmless no-op by design.
 */
class EfrisWorker extends WorkerBase
{
    private EfrisService $efris;

    public function __construct($store, array $config, int $maxRun = 55, int $batch = 10)
    {
        parent::__construct($store, $config, $maxRun, $batch);
        $root = dirname(__DIR__);
        require_once $root . '/lib/EfrisService.php';
        $this->efris = new EfrisService($store, $config, getDataDir($root));
    }

    protected function getEventTypes(): array
    {
        return ['efris.submit'];
    }

    protected function handle(array $event): void
    {
        $p = $event['_payload'] ?? [];
        $invoiceId = (int)($p['invoice_id'] ?? $event['entity_id'] ?? 0);
        if ($invoiceId <= 0) {
            $this->log('warn', 'efris.submit without invoice_id — dropped');
            return;                       // not retryable: the payload cannot improve
        }
        $source = (string)($p['source'] ?? 'queue');

        $r = $this->efris->submitInvoice($invoiceId, $source);
        $this->log('info', "invoice {$invoiceId}: {$r['status']} — {$r['message']}");

        // Transport-level trouble is worth the queue's retry; everything else
        // (fiscalised, duplicate, rejected, validation error, disabled) is a
        // final answer for THIS event — the admin tab and scanner take over.
        if ($r['status'] === EfrisService::ST_ERROR
            && strpos($r['message'], 'Connection failed') !== false) {
            throw new \RuntimeException($r['message']);
        }
    }
}
