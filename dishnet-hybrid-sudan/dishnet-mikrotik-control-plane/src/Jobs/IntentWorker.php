<?php
declare(strict_types=1);
namespace Dn\Jobs;

use Dn\Audit\AuditLog;
use Dn\Db\Database;
use Dn\Delivery\DeliveryPort;
use Dn\Intents\IntentQueue;
use Dn\Intents\IntentState;
use Dn\Tenancy\TenantContext;
use Throwable;

/**
 * The only thing that moves an intent toward a router.
 *
 * runOnce() claims a batch, then handles each intent INSIDE that intent's own
 * customer context — so a worker serving every customer still never reads or
 * writes outside one customer at a time.
 *
 * Crash safety: every claim carries a lease. If this process dies at any
 * point, the lease lapses and another worker picks the intent up. The cost is
 * that an intent whose command was sent but not recorded will be sent again,
 * which is why delivery must be idempotent at the far end (migration 008).
 */
final class IntentWorker
{
    public function __construct(
        private Database $db,
        private TenantContext $ctx,
        private IntentQueue $queue,
        private DeliveryPort $delivery,
        private string $workerId,
        private string $lease = '5 minutes',
    ) {}

    /** @return array{claimed:int,confirmed:int,retrying:int,failed:int} */
    public function runOnce(int $limit = 10): array
    {
        $claimed = $this->queue->claim($this->workerId, $this->lease, $limit);
        $out = ['claimed' => count($claimed), 'confirmed' => 0, 'retrying' => 0, 'failed' => 0];

        foreach ($claimed as $intent) {
            $result = $this->ctx->run($intent['customer_id'], function (Database $db) use ($intent) {
                return $this->handle($db, new IntentQueue($db), new AuditLog($db), $intent);
            });
            if (isset($out[$result])) { $out[$result]++; }
        }
        return $out;
    }

    private function handle(Database $db, IntentQueue $q, AuditLog $audit, array $intent): string
    {
        $id = $intent['id'];
        try {
            $res = $this->delivery->deliver($db, $intent);

            if (!$res->accepted) {
                if (!$res->retryable) {
                    // A malformed request will fail identically forever.
                    // Burning five attempts on it delays everything behind it.
                    $q->recordFailure($id, $res->error ?? 'permanent failure');
                    $q->find($id) && $this->forceFail($db, $q, $id, $res->error ?? 'permanent failure');
                    $audit->record($intent['customer_id'], $this->workerId, 'system',
                        'intent.failed', 'intent', $id, null, ['reason' => $res->error]);
                    return 'failed';
                }
                $state = $q->recordFailure($id, $res->error ?? 'delivery not accepted');
                return $state === IntentState::FAILED ? 'failed' : 'retrying';
            }

            $q->markSent($id);

            // Confirmation is a separate READ of actual state, never the
            // delivery call's own return value.
            if ($this->delivery->confirm($db, $intent)) {
                $q->markConfirmed($id);
                $audit->record($intent['customer_id'], $this->workerId, 'system',
                    'intent.confirmed', 'intent', $id);
                return 'confirmed';
            }

            // Sent but not yet verifiable. Leave it for a later pass rather
            // than calling it done or calling it broken.
            $state = $q->recordFailure($id, 'sent, not yet confirmed');
            return $state === IntentState::FAILED ? 'failed' : 'retrying';

        } catch (Throwable $e) {
            $state = $q->recordFailure($id, substr($e->getMessage(), 0, 500));
            return $state === IntentState::FAILED ? 'failed' : 'retrying';
        }
    }

    /** Permanent failures skip the remaining attempts. */
    private function forceFail(Database $db, IntentQueue $q, string $id, string $why): void
    {
        $row = $q->find($id);
        if ($row === null || IntentState::isTerminal($row['state'])) { return; }
        $db->exec(
            "UPDATE mt_intents SET state = 'failed', failed_at = now(), last_error = ?,
                    claimed_by = NULL, lease_expires_at = NULL
              WHERE id = ? AND state <> 'failed'", [$why, $id]);
    }
}
