<?php
/**
 * JobWindow — when a once-a-day job with a window is due (5.18.54, docs/46 row 6).
 *
 * master.php's run_hour jobs run only in that exact hour, so a cycle that does not fall in it skips the day. A job
 * with run_until runs at the first cycle between run_hour and run_until (local time, the process's zone), once a
 * day: it is due when the hour is inside the window and it has not run since the window opened today.
 */
final class JobWindow
{
    public static function due(int $now, int $lastRun, int $fromHour, int $untilHour): bool
    {
        $hour = (int)date('G', $now);
        if ($hour < $fromHour || $hour >= $untilHour) return false;
        $opened = strtotime(date('Y-m-d', $now) . sprintf(' %02d:00:00', $fromHour));
        return $lastRun < $opened;
    }
}
