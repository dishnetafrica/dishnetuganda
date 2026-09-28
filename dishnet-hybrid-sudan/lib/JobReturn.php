<?php
/**
 * JobReturn — the job link in a WhatsApp message survives the staff sign-in, on Uganda (docs/44 §16.12).
 *
 * A signed-out tap on ?page=dashboard&tab=scheduling&job=N is sent to the sign-in page, which lost the job. Now the
 * dashboard remembers N in the session before it sends the visitor away, and a successful sign-in goes to that job's
 * page instead of the role's dashboard.
 *
 * Only a job number is kept, and the address it returns to is fixed here, so the link cannot send anyone anywhere
 * else. It is used once, and only within half an hour. Whether the person who signs in may see the job is still the
 * job page's own check (J6).
 */
final class JobReturn
{
    public const KEY = 'dn_job_return';
    public const TTL = 1800;

    /** Remember the job a signed-out visitor asked for. Anything but a plain job number is ignored. */
    public static function remember(array $get, array &$session, int $now): void
    {
        if (($get['page'] ?? '') !== 'dashboard' || ($get['tab'] ?? '') !== 'scheduling') return;
        $job = $get['job'] ?? '';
        if (!is_string($job) || !preg_match('/^[1-9][0-9]{0,9}$/', $job)) return;
        $session[self::KEY] = ['job' => (int)$job, 'at' => $now];
    }

    /** Where sign-in goes next: the remembered job's page, or null. Always forgets it. */
    public static function take(array &$session, int $now): ?string
    {
        $r = $session[self::KEY] ?? null;
        unset($session[self::KEY]);
        if (!is_array($r)) return null;
        $job = (int)($r['job'] ?? 0);
        $at  = (int)($r['at'] ?? 0);
        if ($job <= 0 || $at <= 0 || $now < $at || $now - $at > self::TTL) return null;
        return self::url($job);
    }

    public static function url(int $job): string
    {
        return '?page=dashboard&tab=scheduling&job=' . $job;
    }
}
