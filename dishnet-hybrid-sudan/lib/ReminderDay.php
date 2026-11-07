<?php
declare(strict_types=1);

/**
 * ReminderDay — whether today's payment reminders can still come after a send made now (5.18.104, root docs/73
 * §H.2.5.3 step 8b; A17; ruling R8). Pure: it reads only what it is given.
 *
 * On a day the reminders run, a claimed customer send (quote, enquiry, offer) waits until the reminder job has
 * finished, so that "one automated customer message per person per Kampala day" holds in both directions: a chase or an
 * offer never goes before a reminder the same person gets later that day. The one rule: the ledger's reminders_first
 * and Part O both call done().
 *
 * done() is true when any of these holds:
 *   - NotifyGate::REMINDERS does not apply (the reminders then run in the 02:00 maintenance job);
 *   - now, in a literal Africa/Kampala, is at or after reminder_until_hour, normalised exactly as cron/master.php does;
 *   - master_schedule.json's customer_reminders ran today at or after reminder_hour and finished (duration_ms >= 0;
 *     master writes -1 before the job and the real duration after it).
 * The seeded row (last_run 1) is not done; a crashed run (-1) holds sends until reminder_until_hour, never later.
 * PHP 7.4.
 */
final class ReminderDay
{
    /** The literal zone the reminder hours are judged in (master runs under dn_tz(), which is this on Uganda). */
    public const TZ = 'Africa/Kampala';

    /** True when today's payment reminders cannot still come after a send made now. */
    public static function done(array $schedule, array $config, ?string $dataDir, int $now): bool
    {
        try {
            if (!class_exists('NotifyGate')) require_once __DIR__ . '/NotifyGate.php';
            if (!\NotifyGate::applies(\NotifyGate::REMINDERS, $config, $dataDir)) return true;
        } catch (\Throwable $e) {
            return false;   // cannot tell: wait for the until-hour, never send before a reminder
        }
        [$from, $until] = self::hours($config);
        $local = (new \DateTime('@' . $now))->setTimezone(new \DateTimeZone(self::TZ));
        if ((int)$local->format('G') >= $until) return true;
        $row = $schedule['customer_reminders'] ?? null;
        if (!is_array($row)) return false;
        $opening = self::todayAt($now, $from);
        return (int)($row['last_run'] ?? 0) >= $opening && (int)($row['duration_ms'] ?? -1) >= 0;
    }

    /**
     * [reminder_hour, reminder_until_hour], normalised exactly as cron/master.php normalises them before it dispatches
     * customer_reminders (a test compares the two over a table of inputs).
     *
     * @return array{0:int,1:int}
     */
    public static function hours(array $config): array
    {
        $from  = (int)($config['reminder_hour'] ?? 9);
        $until = (int)($config['reminder_until_hour'] ?? 17);
        if ($from < 0 || $from > 23) $from = 9;
        if ($until <= $from || $until > 24) $until = min(24, $from + 8);
        return [$from, $until];
    }

    /** Unix time of $hour:00 today in a literal Africa/Kampala ($hour may be 24: the next midnight). */
    public static function todayAt(int $now, int $hour): int
    {
        $d = (new \DateTime('@' . $now))->setTimezone(new \DateTimeZone(self::TZ));
        $d->setTime(0, 0, 0);
        return $d->getTimestamp() + $hour * 3600;
    }
}
