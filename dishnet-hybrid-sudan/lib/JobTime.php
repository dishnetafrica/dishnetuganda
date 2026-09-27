<?php
/**
 * JobTime — a job's day and hour, sent to uCRM in the plugin's own zone (docs/44 J5, release A).
 *
 * 5.18.49 sent the typed time as UTC ("…T09:00:00.000Z", 12:00 in Kampala) from ＋ New Job and Bulk Dispatch, and
 * Reschedule sent "2026-10-06 11:00" with no zone at all. On Uganda every time now carries Kampala's offset in the
 * form uCRM writes its own timestamps (measured +0300, docs/44 §13.1): 2026-10-06T09:00:00+0300. A malformed date or
 * time is refused instead of passed through. South Sudan keeps the 5.18.49 strings; its callers never reach here.
 */
final class JobTime
{
    /** "2026-10-06" + "09:00" → "2026-10-06T09:00:00+0300" in $tz; null when either part is malformed or not a real day. */
    public static function toUcrm(string $date, string $time, \DateTimeZone $tz): ?string
    {
        $date = trim($date); $time = trim($time);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return null;
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) return null;
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time, $tz);
        if ($dt === false || $dt->format('Y-m-d H:i') !== $date . ' ' . $time) return null;   // 2026-02-30 is refused
        return $dt->format('Y-m-d\TH:i:sO');
    }

    /**
     * Reschedule's "2026-10-06 11:00" or "2026-10-06T11:00" → toUcrm(); null when malformed. A browser that adds
     * seconds ("…T11:00:00") is read to the minute, as the field shows it.
     */
    public static function fromLocal(string $dateTime, \DateTimeZone $tz): ?string
    {
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})(?::\d{2})?$/', trim($dateTime), $m)) return null;
        return self::toUcrm($m[1], $m[2], $tz);
    }
}
