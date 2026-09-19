<?php
declare(strict_types=1);
namespace Dn\Policy;

use Dn\Db\Database;

/**
 * Turns a plan's commercial values into the enforcement profile that realises
 * them — and deduplicates.
 *
 * The customer never names a profile, never sees one, and never learns the
 * concept exists. They express what they are selling; the platform works out
 * how to enforce it. That is the whole reason the two tables are separate
 * (docs/42 §8.2): a price change cannot reach enforcement, and the technical
 * layer stays DishNet's.
 *
 * Two customers selling the same shape of access share one profile row. They
 * cannot observe that, because no customer-facing response carries a profile
 * id — the plan projection omits it.
 */
final class ProfileResolver
{
    public function __construct(private Database $db) {}

    public function resolve(
        int $rateDown, int $rateUp, int $sessionTimeout, int $sharedUsers, ?int $dataCap
    ): string {
        $found = $this->db->one(
            'SELECT id FROM mt_profiles
              WHERE rate_down_bps = ? AND rate_up_bps = ? AND session_timeout_s = ?
                AND shared_users = ? AND data_cap_bytes IS NOT DISTINCT FROM ?',
            [$rateDown, $rateUp, $sessionTimeout, $sharedUsers, $dataCap]
        );
        if ($found !== null) { return $found['id']; }

        // Two requests can race here. The unique constraint on the technical
        // tuple decides it, and the loser re-reads rather than failing: this
        // is a lookup, not a write the caller asked for.
        try {
            // Savepointed: without it the losing side of the race cannot
            // re-read, because the failed insert has aborted the transaction.
            $new = $this->db->attempt(fn($db) => $db->one(
                'INSERT INTO mt_profiles
                   (rate_down_bps, rate_up_bps, session_timeout_s, shared_users, data_cap_bytes)
                 VALUES (?,?,?,?,?) RETURNING id',
                [$rateDown, $rateUp, $sessionTimeout, $sharedUsers, $dataCap]
            ));
            return $new['id'];
        } catch (\PDOException $e) {
            if (($e->errorInfo[0] ?? '') !== '23505') { throw $e; }
            return $this->db->one(
                'SELECT id FROM mt_profiles
                  WHERE rate_down_bps = ? AND rate_up_bps = ? AND session_timeout_s = ?
                    AND shared_users = ? AND data_cap_bytes IS NOT DISTINCT FROM ?',
                [$rateDown, $rateUp, $sessionTimeout, $sharedUsers, $dataCap]
            )['id'];
        }
    }
}
