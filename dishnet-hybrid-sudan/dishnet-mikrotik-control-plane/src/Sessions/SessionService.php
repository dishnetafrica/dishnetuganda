<?php
declare(strict_types=1);
namespace Dn\Sessions;

use Dn\Db\Database;

/** Customer-scoped. Call inside TenantContext::run(). */
final class SessionService
{
    public function __construct(private Database $db) {}

    /** @return list<array> */
    public function live(int $limit = 200): array
    {
        return $this->db->query(
            "SELECT * FROM mt_sessions WHERE state = 'open'
              ORDER BY started_at DESC LIMIT ?", [$limit]);
    }

    /** @return list<array> */
    public function all(int $limit = 200): array
    {
        return $this->db->query(
            'SELECT * FROM mt_sessions ORDER BY started_at DESC LIMIT ?', [$limit]);
    }

    public function find(string $id): ?array
    {
        return $this->db->one('SELECT * FROM mt_sessions WHERE id = ?', [$id]);
    }

    /**
     * Usage for the customer's own reporting.
     *
     * Deliberately not a per-guest breakdown: the customer needs to know how
     * much their Wi-Fi is being used, not what any individual did with it.
     */
    /**
     * Ask for a session to be disconnected (migration 024, A-1/T3, B-2).
     *
     * Disconnecting reaches a router, so it is an intent like everything else
     * that does -- and the intent and its audit row are now one operation
     * inside mt_session_disconnect_request().
     *
     * DELIBERATELY NOT REPLAY SAFE. There is still no idempotency key and no
     * state guard, so a retry enqueues a second intent exactly as it does
     * today. That is the open blocker docs/108 records for F6-B; fixing it
     * inside a privilege remediation would be a silent behaviour change.
     *
     * @return string|null the intent id, or null when the session is not this
     *         customer's -- which the route answers as 404.
     */
    public function requestDisconnect(string $id, ?string $actor,
                                      ?string $source = null): ?string
    {
        $row = $this->db->one(
            'SELECT mt_session_disconnect_request(?,?,?) AS intent_id',
            [$id, $actor, $source]);
        return $row['intent_id'] ?? null;
    }

    public function usage(): array
    {
        return $this->db->one(
            "SELECT count(*) FILTER (WHERE state = 'open')  AS open_now,
                    count(*)                                 AS sessions_total,
                    COALESCE(sum(bytes_in), 0)               AS bytes_in,
                    COALESCE(sum(bytes_out), 0)              AS bytes_out
               FROM mt_sessions") ?? [];
    }
}
