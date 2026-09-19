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
