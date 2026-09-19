<?php
declare(strict_types=1);
namespace Dn\Intents;

use Dn\Db\Database;

/**
 * The only path from a request to a router.
 *
 * enqueue() runs inside a customer's tenant context. claim() cannot — a worker
 * serves every customer and does not know whose row it will get until it has
 * it — so claim() goes through a SECURITY DEFINER function and the worker then
 * ENTERS the claimed intent's context for everything after. Nothing operates
 * outside a customer context except the claim itself.
 */
final class IntentQueue
{
    public function __construct(private Database $db) {}

    /**
     * Create an intent. Customer-scoped: call inside TenantContext::run().
     *
     * An idempotency key makes this safe to call twice for one request: the
     * second call returns the first intent rather than queueing a second.
     */
    public function enqueue(
        string $customerId, string $kind, array $payload = [],
        ?string $actorPrincipalId = null, ?string $targetType = null,
        ?string $targetId = null, ?string $idempotencyKey = null,
        string $actorKind = 'principal'
    ): array {
        if ($idempotencyKey !== null) {
            $existing = $this->db->one(
                'SELECT * FROM mt_intents WHERE idempotency_key = ?', [$idempotencyKey]);
            if ($existing !== null) { return $existing; }
        }

        return $this->db->one(
            'INSERT INTO mt_intents
               (customer_id, actor_principal_id, actor_kind, kind,
                target_type, target_id, payload, idempotency_key)
             VALUES (?,?,?,?,?,?,?::jsonb,?) RETURNING *',
            [$customerId, $actorPrincipalId, $actorKind, $kind, $targetType, $targetId,
             json_encode($payload, JSON_THROW_ON_ERROR), $idempotencyKey]
        );
    }

    /** Customer-scoped. A customer sees its own queued work and nobody else's. */
    public function forCustomer(int $limit = 50): array
    {
        return $this->db->query(
            'SELECT * FROM mt_intents ORDER BY created_at DESC LIMIT ?', [$limit]);
    }

    public function find(string $id): ?array
    {
        return $this->db->one('SELECT * FROM mt_intents WHERE id = ?', [$id]);
    }

    /** NOT customer-scoped — see the class comment. */
    public function claim(string $worker, string $lease = '5 minutes', int $limit = 10): array
    {
        return $this->db->query(
            'SELECT * FROM mt_intent_claim(?, ?::interval, ?)', [$worker, $lease, $limit]);
    }

    /** Customer-scoped. Call inside the claimed intent's context. */
    public function markSent(string $id): void
    {
        $this->db->exec(
            "UPDATE mt_intents SET state = 'sent', sent_at = now(), attempts = attempts + 1
              WHERE id = ? AND state = 'queued'", [$id]);
    }

    public function markConfirmed(string $id): void
    {
        $this->db->exec(
            "UPDATE mt_intents
                SET state = 'confirmed', confirmed_at = now(),
                    claimed_by = NULL, lease_expires_at = NULL
              WHERE id = ? AND state = 'sent'", [$id]);
    }

    /**
     * Record a failed attempt.
     *
     * Retries while attempts remain, with exponential backoff; fails
     * permanently when they do not. The backoff is why next_attempt_at exists:
     * a queue that retries a broken thing immediately is a queue that spends
     * itself on one row.
     */
    public function recordFailure(string $id, string $error): string
    {
        $row = $this->db->one('SELECT attempts, max_attempts, state FROM mt_intents WHERE id = ?', [$id]);
        if ($row === null) { return 'gone'; }

        $attempts = ((int) $row['attempts']) + 1;
        if ($attempts >= (int) $row['max_attempts']) {
            $this->db->exec(
                "UPDATE mt_intents
                    SET state = 'failed', failed_at = now(), attempts = ?,
                        last_error = ?, claimed_by = NULL, lease_expires_at = NULL
                  WHERE id = ?", [$attempts, $error, $id]);
            return IntentState::FAILED;
        }

        $backoff = min(3600, 2 ** $attempts * 15);   // 30s, 60s, 120s … capped at 1h
        $this->db->exec(
            "UPDATE mt_intents
                SET state = 'queued', attempts = ?, last_error = ?,
                    next_attempt_at = now() + (? || ' seconds')::interval,
                    claimed_by = NULL, lease_expires_at = NULL
              WHERE id = ?", [$attempts, $error, (string) $backoff, $id]);
        return IntentState::QUEUED;
    }

    /** Release a lease without counting an attempt (shutdown, not failure). */
    public function release(string $id): void
    {
        $this->db->exec(
            'UPDATE mt_intents SET claimed_by = NULL, lease_expires_at = NULL WHERE id = ?', [$id]);
    }

    /** Not customer-scoped. */
    public function expireOverdue(): int
    {
        return (int) $this->db->one('SELECT mt_intent_expire_overdue() AS n')['n'];
    }
}
