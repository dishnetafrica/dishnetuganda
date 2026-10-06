<?php
declare(strict_types=1);
/**
 * InstallAuthNotifier — the messages of Customer Installation Authorisation (5.18.82, docs/61 §3, docs/63), through the
 * send layers that already exist: NotificationService::sendVia() for WhatsApp (Evolution first, the Message Log, the
 * Failed Queue, opt-outs), CustomerEmailDispatcher for the customer's e-mail, MailService for the technician's copy — the
 * same shape JobNotifier gives every job message. Nothing here opens a transport of its own.
 *
 * Three messages, worded as the brief gives them (D8), the legal entity from the tenant profile:
 *   1. the customer's REQUEST — the job, the charges, the scope and the secure link (WhatsApp + e-mail);
 *   2. after acceptance, the TECHNICIAN uCRM names on the job NOW (never a cached assignee, never the request body) —
 *      WhatsApp (CLASS_STAFF) and an e-mail copy (D9), once per acceptance and assignee (a dedupe mark), then the
 *      CUSTOMER's confirmation with the technician's first name only, or the "DishNet will confirm the technician
 *      separately" line when nobody is assigned;
 *   3. on reassignment after acceptance, "CUSTOMER ALREADY CONFIRMED INSTALLATION" to the new engineer (JobNotifier
 *      calls alreadyConfirmed() from its reassigned decision).
 *
 * The notification is never the source of truth: every method here is called AFTER the record is committed, returns
 * outcomes, records them as events, and never throws into its caller — a failed send leaves the acceptance exactly as it
 * was (the brief's phase 6).
 */
require_once __DIR__ . '/InstallAuth.php';
require_once __DIR__ . '/StaffDirectory.php';
require_once __DIR__ . '/TenantProfile.php';
require_once __DIR__ . '/CustomerContact.php';
require_once __DIR__ . '/JobMessages.php';
require_once __DIR__ . '/crm_url.php';
require_once __DIR__ . '/timezone.php';
if (!class_exists('ContactOptOut')) require_once __DIR__ . '/ContactOptOut.php';

final class InstallAuthNotifier
{
    public const LOG_REQUEST   = 'ops_install_auth_request';
    public const LOG_CONFIRMED = 'ops_install_auth_confirmed';
    public const LOG_TECH      = 'ops_install_auth_technician';

    /** @var mixed */ private $crm;
    /** @var mixed */ private $store;
    /** @var mixed */ private $notify;
    /** @var array */ private $config;
    /** @var string|null */ private $dataDir;
    /** @var \DateTimeZone */ private $tz;
    /** @var TenantProfile */ private $tenant;
    /** @var MailService|null */ private $mailer = null;

    public function __construct($crm, $store, $notify, array $config, ?string $dataDir)
    {
        $this->crm = $crm; $this->store = $store; $this->notify = $notify;
        $this->config = $config; $this->dataDir = $dataDir;
        $this->tz = dn_tz_obj($config);
        $this->tenant = TenantProfile::current($config, $dataDir);
    }

    /** The secure page: public.php?page=install_auth&t=<token>, on the plugin's public address. Only the token is carried. */
    public function link(string $token): string
    {
        return dn_plugin_public($this->config) . '?page=install_auth&t=' . $token;
    }

    // ── 1. The request ───────────────────────────────────────────────────────

    /**
     * WhatsApp and e-mail to the customer, with the link. $mode is request | resend; $actorId the staff member.
     * Records INSTALLATION_TERMS_SENT with each channel's outcome. Returns ['whatsapp' => …, 'email' => …].
     */
    public function sendRequest(array $row, string $token, string $mode, ?int $actorId): array
    {
        $f  = $this->fields($row, ['link' => $this->link($token)]);
        $wa = $this->customerWhatsApp((string)$row['customer_phone'], self::requestText($f), self::LOG_REQUEST);
        $em = $this->customerEmail('request', $row, $f, (int)$row['job_id'] . ':' . substr(InstallAuth::tokenHash($token), 0, 16));
        InstallAuth::event($this->store->getPdo(), (int)$row['job_id'], 'INSTALLATION_TERMS_SENT', 'staff', $actorId, "{$mode};wa:{$wa};email:{$em}");
        return ['whatsapp' => $wa, 'email' => $em];
    }

    // ── 2. After acceptance ──────────────────────────────────────────────────

    /**
     * The acceptance is committed. Read uCRM's job NOW for its assignee (R2), tell that technician, then confirm to the
     * customer. Every outcome becomes an event; nothing here touches the record.
     */
    public function afterAcceptance(array $row): array
    {
        $pdo   = $this->store->getPdo();
        $jobId = (int)$row['job_id'];
        $job   = ($this->crm && method_exists($this->crm, 'isConfigured') && $this->crm->isConfigured()) ? $this->crm->get("scheduling/jobs/{$jobId}") : null;
        $assignee = is_array($job) ? (int)($job['assignedUserId'] ?? 0) : 0;
        if (!is_array($job) || !$job) {
            $tech = ['outcome' => 'ucrm_unreadable', 'detail' => 'ucrm_unreadable', 'first_name' => ''];
        } elseif ($assignee <= 0) {
            $tech = ['outcome' => 'no_assignee', 'detail' => 'no_assignee', 'first_name' => ''];
        } else {
            $tech = $this->technician($row, $assignee, self::technicianText($this->fields($row)), 'confirmed');
        }
        $told = in_array($tech['outcome'], ['sent', 'email_only'], true);
        InstallAuth::event($pdo, $jobId, $told ? 'INSTALLATION_TECHNICIAN_NOTIFIED' : 'INSTALLATION_TECHNICIAN_NOTIFICATION_FAILED', 'system', null, 'accepted;' . $tech['detail']);

        $f  = $this->fields($row, ['technician_first' => (string)($tech['first_name'] ?? '')]);
        $wa = $this->customerWhatsApp((string)$row['customer_phone'], self::confirmedText($f), self::LOG_CONFIRMED);
        $em = $this->customerEmail('confirmed', $row, $f, $jobId . ':' . (string)$row['acceptance_reference']);
        InstallAuth::event($pdo, $jobId, 'INSTALLATION_ACCEPTANCE_NOTIFICATION_SENT', 'system', null, "wa:{$wa};email:{$em}");
        return ['technician' => $tech, 'customer' => ['whatsapp' => $wa, 'email' => $em]];
    }

    // ── 3. Reassignment after acceptance ─────────────────────────────────────

    /** The new engineer learns the customer already confirmed. Once per acceptance and engineer; nothing on a repeat. */
    public function alreadyConfirmed(array $row, int $assignee): array
    {
        $r = $this->technician($row, $assignee, self::alreadyConfirmedText($this->fields($row)), 'reassigned');
        if ($r['outcome'] !== 'already_told') {
            $told = in_array($r['outcome'], ['sent', 'email_only'], true);
            InstallAuth::event($this->store->getPdo(), (int)$row['job_id'], $told ? 'INSTALLATION_TECHNICIAN_NOTIFIED' : 'INSTALLATION_TECHNICIAN_NOTIFICATION_FAILED',
                'system', null, 'reassigned;' . $r['detail']);
        }
        return $r;
    }

    // ── The texts: pure functions of their fields (the brief's words, D8) ────

    /** The brief's phase 14 text, with phase 2's details between the service and the charges. */
    public static function requestText(array $f): string
    {
        $m  = "Dear " . self::s($f, 'customer_name') . ",\n\n";
        $m .= "Your DishNet Starlink installation has been scheduled.\n\n";
        $m .= "Installation Job: " . self::s($f, 'job_id') . "\n";
        $m .= "Service: " . self::s($f, 'service') . "\n";
        if (self::s($f, 'equipment') !== '')       $m .= "Equipment: " . self::s($f, 'equipment') . "\n";
        if (self::s($f, 'location') !== '')        $m .= "Location: " . self::s($f, 'location') . "\n";
        if (self::s($f, 'scheduled_label') !== '') $m .= "Scheduled: " . self::s($f, 'scheduled_label') . "\n";
        $m .= "Installation Charges: " . self::s($f, 'installation') . "\n";
        if ((float)($f['transport_raw'] ?? 0) > 0) $m .= "Transport Charges: " . self::s($f, 'transport') . "\n";
        if ((float)($f['other_raw'] ?? 0) > 0)     $m .= self::s($f, 'other_label') . ": " . self::s($f, 'other') . "\n";
        $m .= "Total: " . self::s($f, 'total') . "\n\n";
        $m .= "Before our technician can commence the installation, please review and accept the Installation Terms:\n\n";
        $m .= self::s($f, 'link') . "\n\n";
        $m .= "You may accept or decline from the secure page.\n\n";
        $m .= self::s($f, 'legal');
        return $m;
    }

    /** The brief's phase 6 text, verbatim. */
    public static function technicianText(array $f): string
    {
        return "🟢 CUSTOMER CONFIRMED INSTALLATION\n\n"
             . "Installation Job: " . self::s($f, 'job_id') . "\n\n"
             . "Customer: " . self::s($f, 'customer_name') . "\n\n"
             . "Location: " . self::s($f, 'location') . "\n\n"
             . "Service: " . self::s($f, 'service') . "\n\n"
             . "The customer has reviewed and accepted the DishNet Installation Terms.\n\n"
             . "Accepted: " . self::s($f, 'accepted_label') . "\n\n"
             . "Acceptance Reference: " . self::s($f, 'reference') . "\n\n"
             . "You may proceed with the scheduled installation.\n\n"
             . "Do not perform additional chargeable work outside the approved job without obtaining customer approval.";
    }

    /** The brief's phase 12 heading, with the phase 6 body: the new engineer after a reassignment. */
    public static function alreadyConfirmedText(array $f): string
    {
        return "🟢 CUSTOMER ALREADY CONFIRMED INSTALLATION\n\n"
             . "Installation Job: " . self::s($f, 'job_id') . "\n\n"
             . "Customer: " . self::s($f, 'customer_name') . "\n\n"
             . "Location: " . self::s($f, 'location') . "\n\n"
             . "Service: " . self::s($f, 'service') . "\n\n"
             . "This job has been reassigned to you. The customer has already reviewed and accepted the DishNet Installation Terms.\n\n"
             . "Accepted: " . self::s($f, 'accepted_label') . "\n\n"
             . "Acceptance Reference: " . self::s($f, 'reference') . "\n\n"
             . "You may proceed with the scheduled installation.\n\n"
             . "Do not perform additional chargeable work outside the approved job without obtaining customer approval.";
    }

    /** The brief's phase 7 text, verbatim; the technician's first name only, or the line for nobody assigned. */
    public static function confirmedText(array $f): string
    {
        $m  = "✅ INSTALLATION AUTHORISED\n\n";
        $m .= "Dear " . self::s($f, 'customer_name') . ",\n\n";
        $m .= "Your DishNet Starlink Installation Job " . self::s($f, 'job_id') . " has been authorised.\n\n";
        $m .= "You have successfully accepted the DishNet Installation Terms.\n\n";
        if (self::s($f, 'technician_first') !== '') {
            $m .= "Our assigned technician has been notified.\n\n";
            $m .= "Technician:\n" . self::s($f, 'technician_first') . "\n\n";
        } else {
            $m .= "Your installation has been authorised. DishNet will assign/confirm the technician separately.\n\n";
        }
        $m .= "Installation:\n" . self::s($f, 'service') . "\n\n";
        $m .= "Acceptance Reference:\n" . self::s($f, 'reference') . "\n\n";
        $m .= self::s($f, 'legal');
        return $m;
    }

    /** The fields every text and e-mail draws on, from the record's own snapshots — never a live price or job. */
    public function fields(array $row, array $extra = []): array
    {
        $h = InstallAuth::hydrate($row);
        $p = (array)$h['price']; $s = (array)$h['scope'];
        $cur = (string)($p['currency'] ?? 'UGX');
        return array_merge([
            'legal'           => $this->legal(),
            'brand'           => $this->tenant->tradingName(),
            'customer_name'   => (string)$h['customer_name'],
            'job_id'          => (int)$h['job_id'],
            'service'         => (string)($s['service'] ?? ''),
            'equipment'       => (string)($s['equipment'] ?? ''),
            'location'        => (string)($s['location'] ?? ''),
            'scheduled_label' => (string)($s['scheduled_label'] ?? ''),
            'installation'    => InstallAuth::money($p['installation'] ?? 0, $cur),
            'transport'       => InstallAuth::money($p['transport'] ?? 0, $cur),
            'other'           => InstallAuth::money($p['other'] ?? 0, $cur),
            'other_label'     => (string)($p['other_label'] ?? 'Other agreed charges'),
            'total'           => InstallAuth::money($p['total'] ?? 0, $cur),
            'transport_raw'   => (float)($p['transport'] ?? 0),
            'other_raw'       => (float)($p['other'] ?? 0),
            'reference'       => (string)$h['acceptance_reference'],
            'terms_version'   => (string)$h['terms_version'],
            'accepted_label'  => $h['accepted_at'] !== null ? InstallAuth::when((string)$h['accepted_at'], $this->tz) : '',
            'expires_label'   => InstallAuth::when((string)$h['token_expires_at'], $this->tz),
            'technician_first' => '',
            'link'            => '',
        ], $extra);   // array_merge: the caller's link and technician win over the defaults ('+' would keep the empty ones)
    }

    // ── Delivery ─────────────────────────────────────────────────────────────

    /** The technician: the verified staff account linked to uCRM user $assignee; WhatsApp (CLASS_STAFF) and the e-mail copy. */
    private function technician(array $row, int $assignee, string $text, string $kind): array
    {
        $jobId = (int)$row['job_id'];
        $rows  = (array)($this->store->load('retailers.json') ?? []);
        $hits  = StaffDirectory::byUcrmUser($rows, $assignee);
        if (count($hits) === 0) return ['outcome' => 'no_staff_account', 'detail' => "no_staff_account;user:{$assignee}", 'first_name' => ''];
        if (count($hits) > 1)   return ['outcome' => 'ambiguous_staff_account', 'detail' => "ambiguous_staff_account;user:{$assignee}", 'first_name' => ''];
        $staff = $hits[0];
        $sid   = (int)($staff['id'] ?? 0);
        $first = InstallAuth::firstName((string)($staff['name'] ?? ''));
        // Once per acceptance and engineer, whichever path asks first (the acceptance, a reassignment, a retried request).
        if (!$this->notify->dedupMark('INSTAUTH' . $jobId . ':' . (string)$row['acceptance_reference'] . ':' . $assignee)) {
            return ['outcome' => 'already_told', 'detail' => "already_told;staff:{$sid}", 'first_name' => $first, 'staff_id' => $sid];
        }
        $phone = StaffDirectory::phoneOf($staff, $this->tenant);
        if ($phone === null) {
            $wa = 'no_usable_number';
        } else {
            $this->notify->sendVia('support', $phone, $text, self::LOG_TECH, [], ContactOptOut::CLASS_STAFF);
            $r  = $this->notify->lastSendResult();
            $wa = !empty($r['success']) ? 'sent' : 'failed';
        }
        $subject = $kind === 'reassigned'
            ? "Job #{$jobId}: customer already confirmed installation ({$row['acceptance_reference']})"
            : "Job #{$jobId}: customer confirmed installation ({$row['acceptance_reference']})";
        $em = $this->staffEmail($staff, $subject, $text);
        $outcome = $wa === 'sent' ? 'sent' : ($em === 'sent' ? 'email_only' : 'failed');
        return ['outcome' => $outcome, 'detail' => "wa:{$wa};email:{$em};staff:{$sid}", 'first_name' => $first, 'staff_id' => $sid, 'whatsapp' => $wa, 'email' => $em];
    }

    /** The same text by e-mail to the staff account's address, as every job message (docs/44 §16.16). */
    private function staffEmail(array $staff, string $subject, string $text): string
    {
        $to = StaffDirectory::email($staff);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return 'no_email';
        try {
            if ($this->mailer === null) {
                if ($this->dataDir === null || $this->dataDir === '') return 'not_configured';
                if (!class_exists('MailService')) require_once __DIR__ . '/MailService.php';
                $this->mailer = new MailService($this->dataDir);
            }
            if (!$this->mailer->getConfig()) return 'not_configured';
            $reply = trim((string)($this->config['email_reply_to'] ?? ''));
            if ($reply === '') $reply = trim((string)($this->tenant->emailBrandDefaults()['email_reply_to'] ?? ''));
            $reply = MailService::normalizeFrom($reply);
            $name  = trim((string)preg_replace('/[\x00-\x1F"<>]+/', ' ', (string)($staff['name'] ?? '')));
            $r = $this->mailer->send($to, $name, $subject, JobMessages::html($text), str_replace("\n", "\r\n", $text), $reply !== '' ? ['Reply-To' => $reply] : []);
            return !empty($r['ok']) ? 'sent' : 'failed';
        } catch (\Throwable $e) {
            return 'failed';
        }
    }

    /** The customer's WhatsApp: sent | failed | no_number. */
    private function customerWhatsApp(string $phone, string $text, string $log): string
    {
        if (trim($phone) === '') return 'no_number';
        try {
            $this->notify->sendVia('support', $phone, $text, $log, [], ContactOptOut::CLASS_TRANSACTIONAL);
            $r = $this->notify->lastSendResult();
            return !empty($r['success']) ? 'sent' : 'failed';
        } catch (\Throwable $e) {
            return 'failed';
        }
    }

    /** The customer's e-mail through the dispatcher: sent | failed | no_email | switched_off | not_configured | already_sent. */
    private function customerEmail(string $kind, array $row, array $f, string $dedupe): string
    {
        $email = trim((string)$row['customer_email']);
        if ($email === '') return 'no_email';
        try {
            if (!class_exists('CustomerEmailDispatcher')) require_once __DIR__ . '/CustomerEmailDispatcher.php';
            $pdo = method_exists($this->store, 'getPdo') ? $this->store->getPdo() : null;
            $d   = new CustomerEmailDispatcher((string)$this->dataDir, $this->config, $this->crm, $pdo);
            $r   = $d->sendInstallAuth($kind, $email, (string)$row['customer_name'], $f, $dedupe);
        } catch (\Throwable $e) {
            return 'failed';
        }
        if (!empty($r['sent'])) return 'sent';
        switch ((string)($r['reason'] ?? '')) {
            case 'switched off':                  return 'switched_off';
            case 'already sent':                  return 'already_sent';
            case 'plugin mail is not configured': return 'not_configured';
            case 'no usable email address':       return 'no_email';
            default:                              return 'failed';
        }
    }

    private function legal(): string
    {
        $l = trim($this->tenant->legalEntity());
        return ($l === '' || $l === TenantProfile::notConfigured()) ? 'DishNet Africa Limited' : $l;
    }

    /** One line of plain text: trimmed, inner line breaks folded, so a value can never add a line of its own. */
    private static function s(array $f, string $k): string
    {
        return trim((string)preg_replace('/\s*[\r\n]+\s*/', ' ', (string)($f[$k] ?? '')));
    }
}
