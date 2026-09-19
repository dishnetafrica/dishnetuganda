<?php
declare(strict_types=1);

/**
 * UtcClock — read a stored UTC stamp as UTC, whatever the process clock says.
 *
 * Storage is UTC everywhere in this plugin: wa_messages.sent_at, the
 * wa_conversations last_*_at columns, wa_conversations.last_human_reply_at,
 * events.next_retry_at. The readers were not. strtotime() on a bare
 * 'Y-m-d H:i:s' applies the process time zone, and the same code runs under
 * two of them: spawned from the webhook, the AI worker is a CLI process on
 * UTC; run by cron/master.php it inherits Africa/Kampala from dn_tz_apply().
 *
 * So a hand-over stamp written at 19:00 UTC read as 16:00 UTC on the
 * scheduled path — three hours in the past — and a pause that should have
 * held did not. In the watchdog, a customer who had waited two minutes read
 * as having waited three hours, and staff were paged for nothing. Every
 * reader of a stored stamp goes through here.
 */
final class UtcClock
{
    /**
     * Unix time for a stored stamp, or 0 when it is empty or unreadable.
     *
     * 'Y-m-d H:i:s' and 'Y-m-d' are read as UTC. A stamp that names its own
     * zone — ISO 8601 with an offset or 'Z', or a trailing UTC/GMT — is
     * honoured as written.
     *
     * @param mixed $stamp
     */
    public static function parse($stamp): int
    {
        $s = trim((string)$stamp);
        if ($s === '') return 0;
        $t = preg_match('/(Z|[+-]\d{2}:?\d{2}|UTC|GMT)$/i', $s)
           ? strtotime($s)
           : strtotime($s . ' UTC');
        return $t === false ? 0 : $t;
    }

    /** The storage form of a unix time: 'Y-m-d H:i:s' in UTC. */
    public static function stamp(?int $time = null): string
    {
        return gmdate('Y-m-d H:i:s', $time ?? time());
    }
}
