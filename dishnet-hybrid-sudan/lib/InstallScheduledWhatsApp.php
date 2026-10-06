<?php
declare(strict_types=1);
/**
 * InstallScheduledWhatsApp — 5.18.84: the customer's WhatsApp when an installation job is booked (Uganda).
 *
 * uCRM's job.add told the customer by e-mail only (webhook.php → the install_scheduled lifecycle e-mail), so a customer
 * with no e-mail address in uCRM heard nothing when their installation was booked, while the technician was told at once.
 * This is the WhatsApp beside that e-mail:
 *   - Uganda only (StaffJobsGate), and only while customer_wa_install_scheduled is on: absent means OFF;
 *   - the e-mail's three conditions, decided by the caller in the same place: the job belongs to a client, its title names
 *     an installation, it has a date;
 *   - to the client's first uCRM contact number (InstallAuth::clientPhone, the rule the authorisation request uses): an
 *     e-mail address is not needed;
 *   - once per job: claimed in notification_dedup (CustomerEmailDispatcher::claimOnce) before the send, because uCRM
 *     redelivers webhooks. A send that fails goes to the plugin's failure queue for a person to retry, as every other
 *     failed WhatsApp does; the claim stays, so a redelivery never sends it a second time;
 *   - a Starlink installation job under Customer Installation Authorisation says that a separate WhatsApp with a secure
 *     link follows: the request staff send from the job page once the charges are set (docs/64).
 *
 * Every value comes from uCRM's job and client and the tenant profile, never from anything a browser sent. A value uCRM
 * does not have gives no line at all. text() is a pure function, so a test can pin every byte.
 *
 * PHP 7.4 compatible.
 */
final class InstallScheduledWhatsApp
{
    public const FLAG  = 'customer_wa_install_scheduled';
    public const EVENT = 'ops_installation_scheduled';

    public static function flagOn(array $config): bool
    {
        $v = $config[self::FLAG] ?? null;
        if (is_bool($v)) return $v;
        return in_array(strtolower(trim((string)$v)), ['1', 'true', 'on', 'yes'], true);
    }

    /** Uganda AND the switch: the only condition under which a customer is sent anything here. */
    public static function enabled(array $config, ?string $dataDir): bool
    {
        if (!self::flagOn($config)) return false;
        if (!class_exists('StaffJobsGate')) require_once __DIR__ . '/StaffJobsGate.php';
        return StaffJobsGate::applies($config, $dataDir);
    }

    /** The notification_dedup key: one WhatsApp per job, whichever delivery of job.add arrives first. */
    public static function claimKey(int $jobId): string
    {
        return 'WAINSTALL' . $jobId;
    }

    /**
     * The values the message shows. $technician is the assignee's name as uCRM holds it, '' when the job has none.
     * Times are the install's own zone (dn_tz), as the technician's job message reads them.
     */
    public static function fields(int $jobId, array $job, array $client, string $technician, array $config, ?string $dataDir): array
    {
        self::load();
        $tz = function_exists('dn_tz_obj') ? dn_tz_obj($config) : new \DateTimeZone('UTC');
        $at = self::local((string)($job['date'] ?? ''), $tz);
        $from = self::local((string)($job['timeFrom'] ?? ''), $tz);
        $to   = self::local((string)($job['timeTo'] ?? ''), $tz);
        if ($from !== null) {
            $time = $from->format('g:i A') . ($to !== null ? ' – ' . $to->format('g:i A') : '');
        } else {
            $time = ($at !== null && $at->format('H:i') !== '00:00') ? $at->format('g:i A') : '';
        }
        $parts = preg_split('/\s+[—–-]\s+/u', trim((string)($job['title'] ?? '')), 2);
        $first = trim((string)($client['firstName'] ?? ''));
        $brand = '';
        try {
            $brand = trim(TenantProfile::current($config, $dataDir)->tradingName());
        } catch (\Throwable $e) {
            $brand = '';
        }
        return [
            'brand'      => $brand !== '' ? $brand : 'DishNet',
            'name'       => $first !== '' ? $first : (InstallAuth::clientName($client) !== '' ? InstallAuth::clientName($client) : 'Customer'),
            'job_id'     => $jobId,
            'job_type'   => trim((string)($parts[0] ?? '')),
            'date'       => $at !== null ? $at->format('l j F Y') : '',
            'time'       => $time,
            'location'   => trim((string)preg_replace('/\s+/u', ' ', trim((string)($job['address'] ?? '')) !== '' ? (string)$job['address'] : InstallAuth::clientAddress($client))),
            'technician' => $technician,
            'starlink_auth' => InstallAuth::enabled($config, $dataDir) && InstallAuth::inScope($job, $config),
            'support_wa' => (string)preg_replace('/\D+/', '', CustomerContact::supportWa($config)),
        ];
    }

    /** The message: WhatsApp's own emphasis, one line per value uCRM has. */
    public static function text(array $f): string
    {
        $m  = '🔧 *Installation Scheduled — ' . self::s($f, 'brand') . "*\n\n";
        $m .= 'Dear ' . self::s($f, 'name') . ",\n\n";
        $m .= "Your installation has been scheduled. ✅\n\n";
        if (self::s($f, 'job_type') !== '')   $m .= '📋 *' . self::s($f, 'job_type') . "*\n";
        if (self::s($f, 'date') !== '')       $m .= '📅 Date: *' . self::s($f, 'date') . "*\n";
        if (self::s($f, 'time') !== '')       $m .= '⏰ Time: *' . self::s($f, 'time') . "*\n";
        if (self::s($f, 'location') !== '')   $m .= '📍 Location: ' . self::s($f, 'location') . "\n";
        if (self::s($f, 'technician') !== '') $m .= '👷 Technician: *' . self::s($f, 'technician') . "*\n";
        $m .= '🔖 Job: #' . (int)($f['job_id'] ?? 0) . "\n\n";
        if (!empty($f['starlink_auth'])) {
            $m .= "📝 Before the installation, you will receive a separate WhatsApp from us with a secure link to review and accept the installation terms and charges.\n\n";
        }
        $m .= "Our technician will contact you before arriving. Please make sure someone is available at the site.\n\n";
        if (self::s($f, 'support_wa') !== '') $m .= '🛠 Support: wa.me/' . self::s($f, 'support_wa') . "\n";
        $m .= '— ' . self::s($f, 'brand') . ' Support';
        return $m;
    }

    /**
     * The send, once per job. $store holds the plugin's database (the claim), $notify is NotificationService.
     * Returns ['outcome' => sent|failed|not_sent|no_phone|already_sent|off|error, 'detail' => one line for the webhook log]
     * — never the customer's number or the message.
     */
    public static function send(int $jobId, array $job, array $client, string $technician, array $config, ?string $dataDir, $store, $notify): array
    {
        self::load();
        if (!self::enabled($config, $dataDir)) return self::out('off', 'switched off');
        if ($jobId <= 0) return self::out('error', 'not sent: the job has no id');
        $phone = InstallAuth::clientPhone($client);
        if ($phone === '') return self::out('no_phone', 'not sent: the client has no phone number in uCRM');
        $pdo = (is_object($store) && method_exists($store, 'getPdo')) ? $store->getPdo() : null;
        if (!$pdo instanceof \PDO) return self::out('error', 'not sent: the plugin database is not available to record it');
        if (!CustomerEmailDispatcher::claimOnce($pdo, self::claimKey($jobId))) {
            return self::out('already_sent', 'not sent again: this job\'s message was already sent, or tried once');
        }

        $notify->sendVia('support', $phone, self::text(self::fields($jobId, $job, $client, $technician, $config, $dataDir)), self::EVENT, ['job_id' => $jobId]);
        $r = method_exists($notify, 'lastSendResult') ? (array)$notify->lastSendResult() : [];
        if (!empty($r['success'])) return self::out('sent', 'sent to the client\'s uCRM number');
        if (($r['http_code'] ?? null) !== null || ($r['error'] ?? null) !== null) {
            return self::out('failed', 'the send failed' . (($r['http_code'] ?? null) !== null ? ' (HTTP ' . (int)$r['http_code'] . ')' : '')
                . '; it is in the failure queue for a manual retry, and is not sent again for this job');
        }
        return self::out('not_sent', 'not sent by the WhatsApp layer (dry run, an unusable number, an opt-out, or no transport — the Message Log says which); not tried again for this job');
    }

    // ── internals ────────────────────────────────────────────────────────────

    private static function load(): void
    {
        if (!class_exists('InstallAuth')) require_once __DIR__ . '/InstallAuth.php';
        if (!class_exists('CustomerEmailDispatcher')) require_once __DIR__ . '/CustomerEmailDispatcher.php';
        if (!class_exists('CustomerContact')) require_once __DIR__ . '/CustomerContact.php';
        if (!class_exists('TenantProfile')) require_once __DIR__ . '/TenantProfile.php';
        if (!function_exists('dn_tz_obj')) require_once __DIR__ . '/timezone.php';
    }

    /** uCRM's time in the install's zone; null when there is none or it cannot be read. */
    private static function local(string $iso, \DateTimeZone $tz): ?\DateTime
    {
        $iso = trim($iso);
        if ($iso === '') return null;
        try {
            return (new \DateTime($iso))->setTimezone($tz);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** One line of plain text: inner line breaks and runs of spaces folded, WhatsApp's emphasis marks removed. */
    private static function s(array $f, string $k): string
    {
        $v = (string)($f[$k] ?? '');
        $v = (string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v);
        $v = str_replace(['*', '_', '~', '`'], '', $v);
        return trim((string)preg_replace('/\s+/u', ' ', $v));
    }

    private static function out(string $outcome, string $detail): array
    {
        return ['outcome' => $outcome, 'detail' => $detail];
    }
}
