<?php
declare(strict_types=1);
namespace Dn\Telemetry;

use Dn\Db\Database;

/**
 * Stored observations of a customer's own uplink.
 *
 * READ-ONLY IN EVERY DIRECTION THAT MATTERS. This class records samples and
 * reads them back for display. It exposes no method that answers a question
 * of the form "may this proceed", and nothing in Policy, Vouchers, Intents or
 * Delivery imports it — a guard asserts that. F13.
 */
final class UplinkRepository
{
    public function __construct(private Database $db) {}

    /** System-side. Returns false for a device belonging to no customer. */
    public function record(string $deviceId, int $rxBps, int $txBps, int $sessions): bool
    {
        return (bool) $this->db->one(
            'SELECT mt_uplink_record(?,?,?,?) AS ok',
            [$deviceId, $rxBps, $txBps, $sessions])['ok'];
    }

    /** Customer-scoped. @return list<array> */
    public function recent(string $window = '24 hours', int $limit = 500): array
    {
        return $this->db->query(
            "SELECT * FROM mt_uplink_samples
              WHERE at > now() - ?::interval
              ORDER BY at DESC LIMIT ?", [$window, $limit]);
    }

    /**
     * What the customer is shown.
     *
     * Absolute throughput and the peak observed — never a percentage.
     * A percentage needs a denominator, and DishNet does not own the
     * customer's link capacity. With Starlink there is not even a fixed
     * number to own: the available rate varies minute to minute. Printing
     * "82% utilised" against a number nobody measured would be an invention,
     * and an invention that looks like a limit.
     */
    public function summary(string $window = '24 hours'): array
    {
        $row = $this->db->one(
            "SELECT count(*)                          AS samples,
                    COALESCE(max(rx_bps), 0)          AS peak_rx_bps,
                    COALESCE(max(tx_bps), 0)          AS peak_tx_bps,
                    COALESCE(round(avg(rx_bps)), 0)   AS mean_rx_bps,
                    COALESCE(round(avg(tx_bps)), 0)   AS mean_tx_bps,
                    COALESCE(max(session_count), 0)   AS peak_sessions,
                    max(at)                           AS last_sample_at
               FROM mt_uplink_samples
              WHERE at > now() - ?::interval", [$window]) ?? [];

        return [
            'samples'        => (int) ($row['samples'] ?? 0),
            'peak_rx_bps'    => (int) ($row['peak_rx_bps'] ?? 0),
            'peak_tx_bps'    => (int) ($row['peak_tx_bps'] ?? 0),
            'mean_rx_bps'    => (int) ($row['mean_rx_bps'] ?? 0),
            'mean_tx_bps'    => (int) ($row['mean_tx_bps'] ?? 0),
            'peak_sessions'  => (int) ($row['peak_sessions'] ?? 0),
            'last_sample_at' => $row['last_sample_at'] ?? null,
        ];
    }

    /** Samples are not evidence of anything sold, so they may be pruned. */
    public function prune(string $keep = '30 days'): int
    {
        return (int) $this->db->one('SELECT mt_uplink_prune(?::interval) AS n', [$keep])['n'];
    }
}
