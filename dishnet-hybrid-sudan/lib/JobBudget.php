<?php
declare(strict_types=1);

/**
 * JobBudget — a job's wall-clock budget against master's deadline (5.18.104, root docs/73 §H.2.13.3). Pure.
 *
 * cron/master.php stops dispatching once time() passes $_m_deadline, but never stops a job already running, and its
 * per-job set_time_limit counts CPU time, not waiting. So each 5.18.104 job asks here before it starts a piece of work
 * that waits on the network, and never carries a cycle past master_budget_seconds. Run by hand, the deadline is null.
 * PHP 7.4.
 */
final class JobBudget
{
    /** Seconds this run may spend: its own budget, cut to what master's cycle has left; 0 = do not start. */
    public static function seconds(int $own, ?int $masterDeadline, int $now): int
    { return max(0, min($own, $masterDeadline === null ? $own : $masterDeadline - $now)); }

    /** May work that can take $inflight seconds start now and still end by $until? */
    public static function mayStart(int $until, int $inflight, ?int $now = null): bool
    { return ($now === null ? time() : $now) + $inflight <= $until; }
}
