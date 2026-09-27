<?php
/**
 * JobMessages — the job WhatsApp messages of release B on Uganda, as approved on 27 September 2026 (docs/44 §16.12).
 *
 * South Sudan's layout: a greeting with the technician's name, the job, an ACCEPT JOB link to the job page, and after
 * Accept a second message with the completion link. Plain text, as South Sudan's is.
 *
 * Every value comes from uCRM's job, uCRM's client, the staff directory and the tenant profile — never from what a
 * browser sent (T4.13). A value uCRM does not have gives no line at all. These are pure functions: JobNotifier
 * gathers the values, these only arrange them, so a test can pin every byte.
 */
final class JobMessages
{
    /** Kampala's day and hour of a UTC 'Y-m-d H:i' time, as South Sudan writes it: 06.10.2026 09:00 am. */
    public static function when(string $utc, \DateTimeZone $tz): string
    {
        if ($utc === '') return 'Not scheduled yet';
        try {
            $d = new \DateTime($utc, new \DateTimeZone('UTC'));
        } catch (\Throwable $e) {
            return 'Not scheduled yet';
        }
        return $d->setTimezone($tz)->format('d.m.Y h:i a');
    }

    /** Message 1: a job assigned to you, or reassigned to you. */
    public static function assigned(array $f): string
    {
        $m  = self::greeting($f) . "\n\n";
        $m .= "New Job Has Been Assigned to You\n\n";
        $m .= self::s($f, 'title') . "\n";
        $m .= "📅 Date: " . self::s($f, 'when') . "\n";
        $m .= self::clientLine($f);
        if (self::s($f, 'client_phone') !== '') $m .= "📞 Mobile: " . self::s($f, 'client_phone') . "\n";
        if (self::s($f, 'address') !== '')      $m .= "📍 Address: " . self::s($f, 'address') . "\n";
        $m .= "\n---\n";
        $m .= "Please click the link below to accept this job:\n\n";
        $m .= "✅ ACCEPT JOB:\n" . self::s($f, 'link') . "\n\n";
        $m .= "Once you accept, we will send you the completion link.\n\n";
        return $m . self::footer($f);
    }

    /** Message 2: after the technician presses Accept. It replaces the engineer's "Job Accepted" on Uganda. */
    public static function accepted(array $f): string
    {
        $m  = self::greeting($f) . "\n\n";
        $m .= "Thank you for accepting the job! ✅\n\n";
        $m .= self::s($f, 'title') . "\n";
        $m .= "📅 Date: " . self::s($f, 'when') . "\n";
        $m .= self::clientLine($f);
        $m .= "\n✅ JOB COMPLETED:\n" . self::s($f, 'link') . "\n";
        $m .= "Press Complete there when the work is finished. The same page lets you reschedule or add a comment.\n";
        if (self::s($f, 'support') !== '') $m .= "\n📞 " . self::s($f, 'support') . "\n";
        return rtrim($m, "\n");
    }

    /** D3: to the technician a job was taken from and given to a colleague. */
    public static function reassignedAway(array $f): string
    {
        return self::greeting($f) . "\n\n"
             . "↩️ Job #" . self::s($f, 'job_id') . " is no longer assigned to you\n\n"
             . self::s($f, 'title') . "\n"
             . "📅 Date: " . self::s($f, 'when') . "\n"
             . "It has been given to a colleague. Please do not go.\n\n"
             . self::footer($f);
    }

    /** A new day or hour, to the technician who has the job. */
    public static function newTime(array $f): string
    {
        return self::greeting($f) . "\n\n"
             . "📅 Job #" . self::s($f, 'job_id') . " has a new time\n\n"
             . self::s($f, 'title') . "\n"
             . "Now: " . self::s($f, 'when') . "\n"
             . "Was: " . self::s($f, 'was') . "\n\n"
             . self::footer($f);
    }

    /** D3: the job stays, with nobody assigned. */
    public static function removed(array $f): string
    {
        return self::greeting($f) . "\n\n"
             . "↩️ Job #" . self::s($f, 'job_id') . " is no longer assigned to you\n\n"
             . self::s($f, 'title') . "\n"
             . "📅 Date: " . self::s($f, 'when') . "\n"
             . "Please do not go.\n\n"
             . self::footer($f);
    }

    /** D3: the job was deleted in uCRM. Its title and time are the last ones the plugin was told. */
    public static function cancelled(array $f): string
    {
        return self::greeting($f) . "\n\n"
             . "❌ Job #" . self::s($f, 'job_id') . " has been cancelled\n\n"
             . self::s($f, 'title') . "\n"
             . "📅 Was: " . self::s($f, 'was') . "\n"
             . "Please do not go.\n\n"
             . self::footer($f);
    }

    private static function greeting(array $f): string
    {
        $name = rtrim(self::s($f, 'name'), ". ");
        return 'Hi' . ($name !== '' ? ' ' . $name : '') . '. This is ' . self::s($f, 'brand') . '.';
    }

    private static function clientLine(array $f): string
    {
        $name = self::s($f, 'client_name');
        $id   = (int)($f['client_id'] ?? 0);
        if ($name === '' && $id <= 0) return '';
        return "👤 Client: " . ($name !== '' ? $name : 'Client') . ($id > 0 ? " (ID:{$id})" : '') . "\n";
    }

    private static function footer(array $f): string
    {
        $m = "For any questions, just reach out here.";
        if (self::s($f, 'support') !== '') $m .= "\n📞 " . self::s($f, 'support');
        if (self::s($f, 'website') !== '') $m .= "\n🌐 " . self::s($f, 'website');
        return $m;
    }

    /** One line of plain text: trimmed, inner line breaks folded, so a value can never add a line of its own. */
    private static function s(array $f, string $k): string
    {
        return trim((string)preg_replace('/\s*[\r\n]+\s*/', ' ', (string)($f[$k] ?? '')));
    }
}
