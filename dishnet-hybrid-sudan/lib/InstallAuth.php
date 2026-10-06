<?php
declare(strict_types=1);
/**
 * InstallAuth — Customer Installation Authorisation for a Starlink installation job (5.18.82, docs/61 §3, docs/63).
 *
 * The job is uCRM's scheduling job; this is the one authorisation record a job can carry (migration 086), its token,
 * its append-only trail, and the server-side guard that keeps an installation from starting — or being completed —
 * without the customer's recorded acceptance. Everything here is reachable only where StaffJobsGate reads Uganda AND
 * install_auth_enabled is on (enabled()), and only for a job whose title is a Starlink installation (inScope(), D6).
 * With the flag off every method that could change a job's behaviour answers "does not apply", and the workflow is
 * byte for byte what it was.
 *
 * The rules this class keeps (docs/61 §3, the brief's phases 4, 5, 11, 15; hardened in 5.18.83, docs/64):
 *   - the raw token is never stored by this class: the row holds SHA-256(token); the page looks a token up by that hash
 *     and compares with hash_equals(). The link leaves the server only inside the request message, and the send layer
 *     keeps no copy of it (NotificationService::storable(), NEVER_QUEUED);
 *   - an acceptance is an UPDATE … WHERE status = 'pending' AND terms_hash = <the hash shown>, in one transaction with its
 *     event: a second click, a stale page or a tampered form changes nothing, and an accepted row is final — triggers in
 *     migration 086 refuse any change to it and any deletion;
 *   - the customer accepts SNAPSHOTS (price_snapshot, scope_snapshot) taken at the request; nothing on the page is live;
 *   - scope is the job's TYPE — the title before the customer's name — matched against install_auth_job_titles (default
 *     "Starlink Installation"); never every title that mentions Starlink, never the customer's name;
 *   - the guard fails closed: pending, declined, cancelled, expired, "no record" and an acceptance given for ANOTHER uCRM
 *     client all refuse a start and a completion. The one exemption (D3) is explicit: a job recorded in install_auth_exempt
 *     because it was in progress when the feature was switched on — never a job merely found "in progress" now;
 *   - a start or a close made in uCRM itself cannot be refused from here, so it is recorded: the job notifier reports it
 *     (observeUcrm()) as INSTALLATION_STARTED/COMPLETED_WITHOUT_ACCEPTANCE and the leaders are told;
 *   - every change leaves an event (install_auth_events) whose detail is a code, never a phone, an e-mail, a token or a
 *     message text; the trail is append-only by trigger.
 */
require_once __DIR__ . '/InstallationTerms.php';

final class InstallAuth
{
    public const FLAG             = 'install_auth_enabled';
    public const LINK_DAYS_KEY    = 'install_auth_link_days';
    public const LINK_DAYS_DEFAULT = 14;
    public const LINK_DAYS_MIN    = 1;
    public const LINK_DAYS_MAX    = 90;
    public const AFTER_DATE_DAYS  = 3;          // D7: or the scheduled date plus three days, whichever is later
    public const TOKEN_BYTES      = 32;

    /** 5.18.83 (docs/64): which uCRM job titles are a Starlink INSTALLATION — the type before " — <customer>". */
    public const TITLES_KEY       = 'install_auth_job_titles';
    public const DEFAULT_TITLES   = ['Starlink Installation'];
    /** 5.18.83: the feature's WhatsApp messages (the customer's request and confirmation, the technician's, the leaders'
     *  alerts) go only while this is on. Absent = OFF: each channel is a deliberate choice (docs/64 §E). */
    public const WHATSAPP_KEY     = 'install_auth_whatsapp';
    /** 5.18.83: a link is sent again for the same job no sooner than this (each resend replaces the link before it). */
    public const RESEND_COOLDOWN  = 120;
    /** 5.18.83: uCRM's page limit; an activation that reads this many in-progress jobs may have missed some, and refuses. */
    public const ACTIVATION_PAGE  = 500;

    public const STATUSES = ['pending', 'accepted', 'declined', 'cancelled', 'expired'];

    /** The event vocabulary — equal to migration 086's CHECK (a test asserts it). */
    public const EVENTS = [
        'INSTALLATION_TERMS_SENT', 'INSTALLATION_TERMS_VIEWED', 'INSTALLATION_ACCEPTED', 'INSTALLATION_DECLINED',
        'INSTALLATION_ACCEPTANCE_NOTIFICATION_SENT', 'INSTALLATION_TECHNICIAN_NOTIFIED', 'INSTALLATION_TECHNICIAN_NOTIFICATION_FAILED',
        'INSTALLATION_STARTED', 'INSTALLATION_COMPLETED', 'CUSTOMER_SIGNED_OFF', 'INSTALLATION_DISPUTED',
        'INSTALLATION_START_BLOCKED', 'INSTALLATION_REQUEST_CANCELLED', 'INSTALLATION_LINK_EXPIRED',
        'INSTALLATION_EXEMPTED', 'INSTALLATION_STARTED_WITHOUT_ACCEPTANCE', 'INSTALLATION_COMPLETED_WITHOUT_ACCEPTANCE',
    ];

    /** The brief's words, verbatim, for the two refusals and the idempotent answer. */
    public const REFUSAL_START    = 'Customer acceptance is required before installation can commence.';
    public const REFUSAL_COMPLETE = 'Customer acceptance is required before installation can be completed.';
    public const ALREADY          = 'Installation already authorised.';
    /** 5.18.83: added after the brief's words when the acceptance on record names another uCRM client. */
    public const OTHER_CLIENT     = 'The acceptance on record was given for another customer; this job now belongs to a different customer in uCRM.';

    /** The public page's rate limits: [requests, seconds] per link (the bucket is the link's own token hash). */
    public const RATE_PAGE = [60, 600];
    public const RATE_POST = [20, 600];

    public const MAX_AMOUNT = 1000000000.0;   // one billion, whatever the currency: a typo guard, not a price rule

    // ── Gate and scope ────────────────────────────────────────────────────────

    public static function flagOn(array $config): bool
    {
        $v = $config[self::FLAG] ?? null;
        if (is_bool($v)) return $v;
        return in_array(strtolower(trim((string)$v)), ['1', 'true', 'on', 'yes'], true);
    }

    /** Uganda AND the flag: the only condition under which anything here changes a job's behaviour. */
    public static function enabled(array $config, ?string $dataDir): bool
    {
        if (!self::flagOn($config)) return false;
        if (!class_exists('StaffJobsGate')) require_once __DIR__ . '/StaffJobsGate.php';
        return StaffJobsGate::applies($config, $dataDir);
    }

    /**
     * A Starlink installation job (D6), decided on the job's TYPE: the title up to the " — " the job screens put before
     * the customer's name (create_job, bulk_create_jobs), or the whole title when there is none, compared — case and
     * spacing aside — with each of install_auth_job_titles (default "Starlink Installation"). 5.18.82 took every title that
     * mentioned Starlink: a cable repair, a power-issue visit, a relocation, and any job whose CUSTOMER was named after
     * Starlink were all bound (docs/64 §A.3). The customer's name can no longer bring a job into scope or take it out.
     */
    public static function inScope(array $job, array $config = []): bool
    {
        $type = self::jobType((string)($job['title'] ?? ''));
        if ($type === '') return false;
        foreach (self::titles($config) as $t) {
            if ($type === self::norm($t)) return true;
        }
        return false;
    }

    /** The job's type: its title before " — " (or the whole title), lower-cased, spacing folded. */
    public static function jobType(string $title): string
    {
        $parts = preg_split('/\s+[—–-]\s+/u', $title, 2);
        return self::norm((string)($parts[0] ?? ''));
    }

    /** The installation titles in force: install_auth_job_titles as a comma- or newline-separated list, else the default. */
    public static function titles(array $config): array
    {
        $raw = $config[self::TITLES_KEY] ?? '';
        $list = is_array($raw) ? $raw : preg_split('/[,\n]+/', (string)$raw);
        $out = [];
        foreach ((array)$list as $t) {
            $t = trim((string)preg_replace('/\s+/u', ' ', (string)$t));
            if ($t !== '' && mb_strlen($t) <= 80) $out[] = $t;
        }
        return $out !== [] ? array_values(array_unique($out)) : self::DEFAULT_TITLES;
    }

    private static function norm(string $s): string
    {
        return mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', $s)));
    }

    public static function applies(array $config, ?string $dataDir, array $job): bool
    {
        return self::enabled($config, $dataDir) && self::inScope($job, $config);
    }

    /** 5.18.83: the feature's WhatsApp switch (absent = off). */
    public static function whatsappOn(array $config): bool
    {
        $v = $config[self::WHATSAPP_KEY] ?? null;
        if (is_bool($v)) return $v;
        return in_array(strtolower(trim((string)$v)), ['1', 'true', 'on', 'yes'], true);
    }

    /** 5.18.83: one of the two customer e-mails, under the customer e-mails master switch and its own key (absent = off). */
    public static function emailOn(string $kind, array $config): bool
    {
        if (!class_exists('CustomerEmailDispatcher')) require_once __DIR__ . '/CustomerEmailDispatcher.php';
        return CustomerEmailDispatcher::installAuthEnabled($kind, $config);
    }

    public static function linkDays(array $config): int
    {
        $v = trim((string)($config[self::LINK_DAYS_KEY] ?? ''));
        if ($v === '' || !preg_match('/^\d+$/', $v)) return self::LINK_DAYS_DEFAULT;
        return max(self::LINK_DAYS_MIN, min(self::LINK_DAYS_MAX, (int)$v));
    }

    // ── Reading ───────────────────────────────────────────────────────────────

    public static function find(\PDO $pdo, int $jobId): ?array
    {
        if ($jobId <= 0) return null;
        try {
            $st = $pdo->prepare('SELECT * FROM install_auth WHERE job_id = ?');
            $st->execute([$jobId]);
            $row = $st->fetch(\PDO::FETCH_ASSOC);
            $st->closeCursor();
            return is_array($row) ? $row : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** The row with its two snapshots decoded ('price', 'scope'); the token hash is left out. */
    public static function hydrate(array $row): array
    {
        $row['price'] = json_decode((string)($row['price_snapshot'] ?? ''), true) ?: [];
        $row['scope'] = json_decode((string)($row['scope_snapshot'] ?? ''), true) ?: [];
        unset($row['token_hash']);
        return $row;
    }

    /**
     * What a staff screen or the API may see of a record: no token hash, the contact details masked (the job page
     * already shows the customer's number; here only enough to say where the link went).
     */
    public static function summary(?array $row, \DateTimeZone $tz): ?array
    {
        if ($row === null) return null;
        $h = self::hydrate($row);
        return [
            'job_id'               => (int)$h['job_id'],
            'status'               => (string)$h['status'],
            'acceptance_reference' => (string)$h['acceptance_reference'],
            'terms_version'        => (string)$h['terms_version'],
            'terms_hash'           => (string)$h['terms_hash'],
            'price'                => $h['price'],
            'scope'                => $h['scope'],
            'customer_name'        => (string)$h['customer_name'],
            'customer_phone_masked' => self::maskPhone((string)$h['customer_phone']),
            'customer_email_masked' => self::maskEmail((string)$h['customer_email']),
            'has_phone'            => $h['customer_phone'] !== '',
            'has_email'            => $h['customer_email'] !== '',
            'requested_by_name'    => (string)$h['requested_by_name'],
            'requested_at'         => self::when((string)$h['requested_at'], $tz),
            'token_expires_at'     => self::when((string)$h['token_expires_at'], $tz),
            'viewed_at'            => $h['viewed_at'] !== null ? self::when((string)$h['viewed_at'], $tz) : null,
            'accepted_at'          => $h['accepted_at'] !== null ? self::when((string)$h['accepted_at'], $tz) : null,
            'accepted_by_name'     => $h['accepted_by_name'],
            'declined_at'          => $h['declined_at'] !== null ? self::when((string)$h['declined_at'], $tz) : null,
            'decline_reason'       => $h['decline_reason'],
            'cancelled_at'         => $h['cancelled_at'] !== null ? self::when((string)$h['cancelled_at'], $tz) : null,
            'cancel_reason'        => $h['cancel_reason'],
        ];
    }

    /** What travels with the job detail and the panel: the state, the record, the trail, the words the panel uses. */
    public static function detailExtras(\PDO $pdo, array $config, ?string $dataDir, array $job, \DateTimeZone $tz): array
    {
        $jobId = (int)($job['id'] ?? 0);
        $row   = self::find($pdo, $jobId);
        if ($row !== null) $row = self::expireIfDue($pdo, $row);
        $act   = self::lastActivation($pdo);
        $d     = self::decision($pdo, $config, $job);
        return [
            'applies'       => self::inScope($job, $config),
            'status'        => $row === null ? 'none' : (string)$row['status'],
            'record'        => self::summary($row, $tz),
            'events'        => self::events($pdo, $jobId, $tz),
            'terms_version' => InstallationTerms::VERSION,
            'refusal'       => self::REFUSAL_START,
            'link_days'     => self::linkDays($config),
            // 5.18.83 (docs/64): the server's own answer to "may this job start or be completed now?", the explicit D3
            // exemption, the client binding, when the feature was activated, and which channels would carry a request.
            'authorised'    => $d['allow'],
            'why'           => $d['why'],
            'exempt'        => $d['exempt'],
            'client_changed' => $d['why'] === 'other_client',
            'activated_at'  => $act !== null ? self::when((string)$act['activated_at'], $tz) : null,
            'channels'      => ['whatsapp' => self::whatsappOn($config), 'email' => self::emailOn('request', $config)],
        ];
    }

    public static function events(\PDO $pdo, int $jobId, ?\DateTimeZone $tz = null): array
    {
        try {
            $st = $pdo->prepare('SELECT id, event, actor_kind, actor_id, detail, created_at FROM install_auth_events WHERE job_id = ? ORDER BY id');
            $st->execute([$jobId]);
            $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['actor_id'] = $r['actor_id'] === null ? null : (int)$r['actor_id'];
            if ($tz !== null) $r['at'] = self::when((string)$r['created_at'], $tz);
        }
        unset($r);
        return $rows;
    }

    // ── The request (staff) ───────────────────────────────────────────────────

    /**
     * Validate the charges a staff member typed or confirmed (D4). Amounts are whole or decimal numbers, never negative,
     * never absurd; a non-zero "other" needs a label. Returns ['ok' => true, 'price' => [...]] or ['ok' => false, 'error'].
     */
    public static function validateCharges(array $in, string $currency): array
    {
        $out = ['currency' => $currency];
        foreach (['installation', 'transport', 'other'] as $k) {
            $raw = trim((string)($in[$k] ?? '0'));
            if ($raw === '') $raw = '0';
            $raw = str_replace([',', ' '], '', $raw);
            if (!preg_match('/^\d+(\.\d{1,2})?$/', $raw)) return ['ok' => false, 'error' => "The {$k} charge must be a number (0 or more)."];
            $v = (float)$raw;
            if ($v > self::MAX_AMOUNT) return ['ok' => false, 'error' => "The {$k} charge is too large to be a real charge."];
            $out[$k] = $v;
        }
        $label = trim((string)($in['other_label'] ?? ''));
        $label = (string)preg_replace('/\s+/', ' ', $label);
        if (mb_strlen($label) > 80) return ['ok' => false, 'error' => 'The label for other charges is too long (80 characters at most).'];
        if ($out['other'] > 0 && $label === '') return ['ok' => false, 'error' => 'Say what the other agreed charge is for.'];
        $out['other_label'] = $out['other'] > 0 ? $label : '';
        $out['total'] = round($out['installation'] + $out['transport'] + $out['other'], 2);
        return ['ok' => true, 'price' => $out];
    }

    /**
     * Create the pending record and its token, in one transaction. $job and $client are uCRM's own answers (never the
     * posted body); $in carries the staff member's charges and scope words; $by is the staff row. Returns
     * ['ok' => true, 'row' => …, 'token' => <raw>, 'superseded' => <old reference or ''>] or ['ok' => false, 'error', 'code'].
     */
    public static function request(\PDO $pdo, array $job, array $client, array $in, array $by, array $config, \DateTimeZone $tz, ?int $now = null): array
    {
        $now   = $now ?? time();
        $jobId = (int)($job['id'] ?? 0);
        if ($jobId <= 0) return self::fail('The job could not be read from uCRM.', 404);
        if (!self::inScope($job, $config)) return self::fail('Customer authorisation applies to Starlink installation jobs only.', 422);
        if (is_numeric($job['status'] ?? null) && (int)$job['status'] === 2) return self::fail('This job is closed.', 409);

        $currency = self::currency($config);
        $v = self::validateCharges($in, $currency);
        if (!$v['ok']) return self::fail($v['error'], 422);
        $price = $v['price'];

        $name  = self::clientName($client);
        if ($name === '') return self::fail('The customer has no name in uCRM, so the request cannot be addressed.', 422);
        $phone = self::clientPhone($client);
        $email = self::clientEmail($client);
        if ($phone === '' && $email === '') return self::fail('The customer has no phone number or e-mail address in uCRM, so the request cannot be sent. Add one in uCRM first.', 422);
        // 5.18.83 (docs/64 §E): a request no channel can carry is refused BEFORE a record exists — never a pending record
        // nobody was told about, which would bind the job while the customer never sees the link.
        $why = self::noChannelReason($config, $phone, $email);
        if ($why !== null) return self::fail($why, 422);

        $service   = self::oneLine((string)($in['service'] ?? ''), 160);
        $equipment = self::oneLine((string)($in['equipment'] ?? ''), 160);
        $location  = self::oneLine((string)($in['location'] ?? ''), 200);
        if ($location === '') $location = self::oneLine(trim((string)($job['address'] ?? '')) ?: self::clientAddress($client), 200);
        if ($service === '')   return self::fail('Say which service is being installed.', 422);
        if ($equipment === '') return self::fail('Say which Starlink kit or equipment is being installed.', 422);
        if ($location === '')  return self::fail('The installation location is missing: give it here or set the job\'s address in uCRM.', 422);

        $scheduled = trim((string)($job['date'] ?? ''));
        $schedTs   = $scheduled !== '' ? strtotime($scheduled) : false;
        $scope = [
            'job_id'          => $jobId,
            'title'           => self::oneLine((string)($job['title'] ?? ''), 200),
            'service'         => $service,
            'equipment'       => $equipment,
            'location'        => $location,
            'scheduled'       => $schedTs !== false ? gmdate('Y-m-d H:i:s', $schedTs) : '',
            'scheduled_label' => $schedTs !== false ? self::when(gmdate('Y-m-d H:i:s', $schedTs), $tz) : '',
            'duration_min'    => (int)($job['duration'] ?? 0),
        ];

        $version = InstallationTerms::VERSION;
        $hash    = (string)InstallationTerms::hash($version);
        $token   = bin2hex(random_bytes(self::TOKEN_BYTES));
        $expires = max($now + self::linkDays($config) * 86400, ($schedTs !== false ? $schedTs : 0) + self::AFTER_DATE_DAYS * 86400);
        $byId    = (int)($by['id'] ?? 0);
        $byName  = self::oneLine((string)($by['name'] ?? ''), 80);

        if ($pdo->inTransaction()) return self::fail('The authorisation record could not be locked.', 500);
        try {
            $pdo->exec('BEGIN IMMEDIATE');
        } catch (\Throwable $e) {
            return self::fail('The authorisation record could not be locked.', 500);
        }
        try {
            $existing = self::find($pdo, $jobId);
            if ($existing !== null && $existing['status'] === 'pending' && strtotime((string)$existing['token_expires_at'] . ' UTC') <= $now) {
                $existing['status'] = 'expired';   // decided inside the lock; the event follows the commit
                $expiredNow = true;
            } else {
                $expiredNow = false;
            }
            if ($existing !== null && $existing['status'] === 'accepted') {
                $pdo->exec('ROLLBACK');
                return self::fail(self::ALREADY, 409);
            }
            if ($existing !== null && $existing['status'] === 'pending') {
                $pdo->exec('ROLLBACK');
                return self::fail('A request is already pending for this job. Resend the link, or cancel the request first.', 409);
            }
            $seqSt = $pdo->query('SELECT COALESCE(MAX(seq), 0) + 1 FROM install_auth');
            $seq   = (int)$seqSt->fetchColumn();
            $seqSt->closeCursor();
            $ref   = self::reference($seq, $now, $tz);
            $cols  = [
                'job_id' => $jobId, 'crm_client_id' => (int)($client['id'] ?? $job['clientId'] ?? 0), 'seq' => $seq,
                'acceptance_reference' => $ref, 'status' => 'pending', 'terms_version' => $version, 'terms_hash' => $hash,
                'price_snapshot' => json_encode($price, JSON_UNESCAPED_UNICODE), 'scope_snapshot' => json_encode($scope, JSON_UNESCAPED_UNICODE),
                'customer_name' => $name, 'customer_phone' => $phone, 'customer_email' => $email,
                'requested_by' => $byId, 'requested_by_name' => $byName, 'requested_at' => gmdate('Y-m-d H:i:s', $now),
                'token_hash' => self::tokenHash($token), 'token_expires_at' => gmdate('Y-m-d H:i:s', $expires),
                'viewed_at' => null, 'accepted_at' => null, 'accepted_method' => null, 'accepted_by_name' => null,
                'declined_at' => null, 'decline_reason' => null, 'cancelled_at' => null, 'cancel_reason' => null,
                'updated_at' => gmdate('Y-m-d H:i:s', $now),
            ];
            if ($existing === null) {
                $cols['created_at'] = gmdate('Y-m-d H:i:s', $now);
                $pdo->prepare('INSERT INTO install_auth (' . implode(', ', array_keys($cols)) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
                    ->execute(array_values($cols));
            } else {
                // A declined, cancelled or expired request is superseded on the same row: a new reference, a new token.
                $set = [];
                foreach (array_keys($cols) as $k) { if ($k !== 'job_id') $set[] = "{$k} = ?"; }
                $vals = $cols; unset($vals['job_id']);
                $pdo->prepare('UPDATE install_auth SET ' . implode(', ', $set) . ' WHERE job_id = ?')->execute(array_merge(array_values($vals), [$jobId]));
            }
            $pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            try { $pdo->exec('ROLLBACK'); } catch (\Throwable $e2) { /* nothing to undo */ }
            return self::fail('The authorisation record could not be written.', 500);
        }
        if ($expiredNow && $existing !== null) {
            self::event($pdo, $jobId, 'INSTALLATION_LINK_EXPIRED', 'system', null, 'superseded');
        }
        $row = self::find($pdo, $jobId);
        return ['ok' => true, 'row' => $row, 'token' => $token,
                'superseded' => $existing !== null ? (string)$existing['acceptance_reference'] : ''];
    }

    /**
     * Why no channel can carry a request to this customer now, or null when at least one can: WhatsApp needs
     * install_auth_whatsapp on and a number; e-mail needs the customer e-mails master switch, its own key and an address.
     */
    public static function noChannelReason(array $config, string $phone, string $email): ?string
    {
        $wa = self::whatsappOn($config);
        $em = self::emailOn('request', $config);
        if (($wa && trim($phone) !== '') || ($em && trim($email) !== '')) return null;
        $parts = [];
        $parts[] = !$wa ? 'WhatsApp is switched off for authorisation requests (install_auth_whatsapp)' : 'the customer has no phone number in uCRM';
        $parts[] = !$em ? 'e-mail is switched off for authorisation requests (customer_emails_enabled / customer_email_install_auth_request)' : 'the customer has no e-mail address in uCRM';
        return 'The request cannot be sent: ' . implode('; ', $parts) . '. Nothing was recorded.';
    }

    /** A fresh token for a pending record (the link is resent); the reference, the snapshots and the terms stay. */
    public static function resend(\PDO $pdo, int $jobId, array $config, \DateTimeZone $tz, ?int $now = null): array
    {
        $now = $now ?? time();
        $row = self::find($pdo, $jobId);
        if ($row === null) return self::fail('There is no authorisation request for this job.', 404);
        $row = self::expireIfDue($pdo, $row, $now);
        if ($row['status'] === 'accepted') return self::fail(self::ALREADY, 409);
        if ($row['status'] !== 'pending') return self::fail('This request is ' . $row['status'] . '. Make a new request instead.', 409);
        // 5.18.83 (docs/64 §F): each resend replaces the link before it, so a burst of resends leaves the customer holding
        // links that no longer open. One send per job per RESEND_COOLDOWN seconds, counted from the pending record's last
        // write — its request or its last resend (a view does not touch updated_at).
        $last = strtotime((string)$row['updated_at'] . ' UTC');
        if ($last !== false && $now - $last < self::RESEND_COOLDOWN) {
            $wait = self::RESEND_COOLDOWN - ($now - $last);
            return self::fail('The link was sent ' . ($now - $last) . ' seconds ago. Wait ' . $wait . ' more seconds before sending it again: each new link replaces the one before it.', 429);
        }
        $why = self::noChannelReason($config, (string)$row['customer_phone'], (string)$row['customer_email']);
        if ($why !== null) return self::fail($why, 422);
        $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        $sched = (string)(json_decode((string)$row['scope_snapshot'], true)['scheduled'] ?? '');
        $schedTs = $sched !== '' ? strtotime($sched . ' UTC') : false;
        $expires = max($now + self::linkDays($config) * 86400, ($schedTs !== false ? $schedTs : 0) + self::AFTER_DATE_DAYS * 86400);
        try {
            $st = $pdo->prepare('UPDATE install_auth SET token_hash = ?, token_expires_at = ?, updated_at = ? WHERE job_id = ? AND status = \'pending\'');
            $st->execute([self::tokenHash($token), gmdate('Y-m-d H:i:s', $expires), gmdate('Y-m-d H:i:s', $now), $jobId]);
            if ($st->rowCount() !== 1) return self::fail('The request changed while the link was being renewed. Reload the job.', 409);
        } catch (\Throwable $e) {
            return self::fail('The link could not be renewed.', 500);
        }
        return ['ok' => true, 'row' => self::find($pdo, $jobId), 'token' => $token];
    }

    /** Staff cancel a pending request (or the system does, when uCRM deletes the job). The token stops working at once. */
    public static function cancel(\PDO $pdo, int $jobId, string $actorKind, ?int $actorId, string $reason, ?int $now = null): array
    {
        $now = $now ?? time();
        $row = self::find($pdo, $jobId);
        if ($row === null) return self::fail('There is no authorisation request for this job.', 404);
        if ($row['status'] === 'accepted') return self::fail('An accepted authorisation cannot be cancelled. Record a dispute instead.', 409);
        if ($row['status'] !== 'pending') return self::fail('This request is already ' . $row['status'] . '.', 409);
        $reason = self::oneLine($reason, 200);
        try {
            $st = $pdo->prepare('UPDATE install_auth SET status = \'cancelled\', cancelled_at = ?, cancel_reason = ?, updated_at = ? WHERE job_id = ? AND status = \'pending\'');
            $st->execute([gmdate('Y-m-d H:i:s', $now), $reason !== '' ? $reason : null, gmdate('Y-m-d H:i:s', $now), $jobId]);
            if ($st->rowCount() !== 1) return self::fail('The request changed meanwhile. Reload the job.', 409);
        } catch (\Throwable $e) {
            return self::fail('The request could not be cancelled.', 500);
        }
        self::event($pdo, $jobId, 'INSTALLATION_REQUEST_CANCELLED', $actorKind, $actorId, $actorKind === 'system' ? 'job_deleted' : 'staff');
        return ['ok' => true, 'row' => self::find($pdo, $jobId)];
    }

    /** uCRM deleted the job: a pending request is cancelled by the system. Returns whether one was. */
    public static function cancelForDeletedJob(\PDO $pdo, int $jobId): bool
    {
        $row = self::find($pdo, $jobId);
        if ($row === null || $row['status'] !== 'pending') return false;
        return !empty(self::cancel($pdo, $jobId, 'system', null, 'job_deleted')['ok']);
    }

    /** A staff member records that the customer disputes the installation: an event, nothing else changes. */
    public static function dispute(\PDO $pdo, int $jobId, int $actorId, string $reason): array
    {
        $row = self::find($pdo, $jobId);
        if ($row === null) return self::fail('There is no authorisation record for this job.', 404);
        $reason = self::oneLine($reason, 200);
        if (mb_strlen($reason) < 3) return self::fail('Say in a few words what is disputed.', 422);
        self::event($pdo, $jobId, 'INSTALLATION_DISPUTED', 'staff', $actorId, 'status:' . $row['status']);
        return ['ok' => true, 'row' => $row];
    }

    /** A pending record whose link has run out becomes expired, once, with its event. Returns the row as it now is. */
    public static function expireIfDue(\PDO $pdo, array $row, ?int $now = null): array
    {
        $now = $now ?? time();
        if (($row['status'] ?? '') !== 'pending') return $row;
        if (strtotime((string)$row['token_expires_at'] . ' UTC') > $now) return $row;
        try {
            $st = $pdo->prepare('UPDATE install_auth SET status = \'expired\', updated_at = ? WHERE job_id = ? AND status = \'pending\'');
            $st->execute([gmdate('Y-m-d H:i:s', $now), (int)$row['job_id']]);
            if ($st->rowCount() === 1) self::event($pdo, (int)$row['job_id'], 'INSTALLATION_LINK_EXPIRED', 'system', null, 'due');
        } catch (\Throwable $e) {
            // left pending in the database; the guard still refuses, because pending refuses
        }
        return self::find($pdo, (int)$row['job_id']) ?? $row;
    }

    // ── The customer's side (the page) ────────────────────────────────────────

    public static function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** The record a raw token names, or null. The lookup is by hash; the stored hash is then compared in constant time. */
    public static function byToken(\PDO $pdo, string $token): ?array
    {
        $token = trim($token);
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) return null;
        $h = self::tokenHash($token);
        try {
            $st = $pdo->prepare('SELECT * FROM install_auth WHERE token_hash = ?');
            $st->execute([$h]);
            $row = $st->fetch(\PDO::FETCH_ASSOC);
            $st->closeCursor();
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_array($row) || !hash_equals((string)$row['token_hash'], $h)) return null;
        return $row;
    }

    /** The page was opened with a valid link: recorded once per day. */
    public static function markViewed(\PDO $pdo, array $row, ?int $now = null): void
    {
        $now = $now ?? time();
        $today = gmdate('Y-m-d', $now);
        if (substr((string)($row['viewed_at'] ?? ''), 0, 10) === $today) return;
        try {
            $pdo->prepare('UPDATE install_auth SET viewed_at = ? WHERE job_id = ?')->execute([gmdate('Y-m-d H:i:s', $now), (int)$row['job_id']]);
        } catch (\Throwable $e) { return; }
        self::event($pdo, (int)$row['job_id'], 'INSTALLATION_TERMS_VIEWED', 'customer', null, $row['status']);
    }

    /**
     * The customer accepts. One statement decides: the row must still be pending, carry this token, show these terms
     * and not have expired. Returns outcome accepted | already | not_pending | terms_mismatch | expired | invalid.
     */
    public static function accept(\PDO $pdo, array $row, string $termsHash, string $name, ?int $now = null): array
    {
        $now   = $now ?? time();
        $jobId = (int)$row['job_id'];
        $name  = self::oneLine($name, 80);
        // 5.18.83 (docs/64 §F): the acceptance and its INSTALLATION_ACCEPTED event commit together or not at all — an
        // accepted row without its event, or an event without its row, can no longer be left behind.
        if ($pdo->inTransaction()) return ['outcome' => 'invalid', 'row' => $row];
        $n = 0;
        try {
            $pdo->exec('BEGIN IMMEDIATE');
            $st = $pdo->prepare('UPDATE install_auth SET status = \'accepted\', accepted_at = ?, accepted_method = \'web_link\', accepted_by_name = ?, updated_at = ?
                                 WHERE job_id = ? AND token_hash = ? AND status = \'pending\' AND terms_hash = ? AND token_expires_at > ?');
            $st->execute([gmdate('Y-m-d H:i:s', $now), $name !== '' ? $name : null, gmdate('Y-m-d H:i:s', $now),
                          $jobId, (string)$row['token_hash'], $termsHash, gmdate('Y-m-d H:i:s', $now)]);
            $n = $st->rowCount();
            if ($n === 1) {
                $pdo->prepare('INSERT INTO install_auth_events (job_id, event, actor_kind, actor_id, detail, created_at) VALUES (?, \'INSTALLATION_ACCEPTED\', \'customer\', NULL, \'web_link\', ?)')
                    ->execute([$jobId, gmdate('Y-m-d H:i:s', $now)]);
            }
            $pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            try { $pdo->exec('ROLLBACK'); } catch (\Throwable $e2) { /* nothing to undo */ }
            return ['outcome' => 'invalid', 'row' => $row];
        }
        $fresh = self::find($pdo, $jobId) ?? $row;
        if ($n === 1) {
            return ['outcome' => 'accepted', 'row' => $fresh];
        }
        if ($fresh['status'] === 'accepted') return ['outcome' => 'already', 'row' => $fresh];
        if ($fresh['status'] !== 'pending') return ['outcome' => 'not_pending', 'row' => $fresh];
        if (!hash_equals((string)$fresh['terms_hash'], $termsHash)) return ['outcome' => 'terms_mismatch', 'row' => $fresh];
        if (strtotime((string)$fresh['token_expires_at'] . ' UTC') <= $now) return ['outcome' => 'expired', 'row' => self::expireIfDue($pdo, $fresh, $now)];
        return ['outcome' => 'invalid', 'row' => $fresh];
    }

    /** The customer declines. Same shape as accept(); the reason is the customer's own words, optional. */
    public static function decline(\PDO $pdo, array $row, string $reason, ?int $now = null): array
    {
        $now    = $now ?? time();
        $jobId  = (int)$row['job_id'];
        $reason = self::oneLine($reason, 300);
        try {
            $st = $pdo->prepare('UPDATE install_auth SET status = \'declined\', declined_at = ?, decline_reason = ?, updated_at = ?
                                 WHERE job_id = ? AND token_hash = ? AND status = \'pending\' AND token_expires_at > ?');
            $st->execute([gmdate('Y-m-d H:i:s', $now), $reason !== '' ? $reason : null, gmdate('Y-m-d H:i:s', $now),
                          $jobId, (string)$row['token_hash'], gmdate('Y-m-d H:i:s', $now)]);
            $n = $st->rowCount();
        } catch (\Throwable $e) {
            return ['outcome' => 'invalid', 'row' => $row];
        }
        $fresh = self::find($pdo, $jobId) ?? $row;
        if ($n === 1) {
            self::event($pdo, $jobId, 'INSTALLATION_DECLINED', 'customer', null, $reason !== '' ? 'web_link;reason:yes' : 'web_link;reason:no');
            return ['outcome' => 'declined', 'row' => $fresh];
        }
        if ($fresh['status'] === 'accepted') return ['outcome' => 'already', 'row' => $fresh];
        if ($fresh['status'] !== 'pending') return ['outcome' => 'not_pending', 'row' => $fresh];
        if (strtotime((string)$fresh['token_expires_at'] . ' UTC') <= $now) return ['outcome' => 'expired', 'row' => self::expireIfDue($pdo, $fresh, $now)];
        return ['outcome' => 'invalid', 'row' => $fresh];
    }

    /**
     * A hashed bucket: allowed while fewer than $limit hits fall inside $window seconds. Old rows are purged.
     * 5.18.83 (docs/64 §F): the page's $subject is the link's own token hash, and the page calls this only for a link that
     * names a record — so an invalid link writes nothing, and no header a client controls (X-Forwarded-For) chooses the
     * bucket. 5.18.82 keyed it on the first X-Forwarded-For hop, which a client could change at every request.
     */
    public static function rateAllow(\PDO $pdo, string $bucket, string $subject, int $limit, int $window, ?int $now = null): bool
    {
        $now = $now ?? time();
        $key = $bucket . ':' . hash('sha256', 'dn-install-auth-rate|' . $bucket . '|' . $subject);
        try {
            $pdo->prepare('DELETE FROM install_auth_rate WHERE at < ?')->execute([$now - max($window, self::RATE_PAGE[1], self::RATE_POST[1])]);
            $st = $pdo->prepare('SELECT COUNT(*) FROM install_auth_rate WHERE rkey = ? AND at >= ?');
            $st->execute([$key, $now - $window]);
            $n = (int)$st->fetchColumn();
            $st->closeCursor();
            $pdo->prepare('INSERT INTO install_auth_rate (rkey, at) VALUES (?, ?)')->execute([$key, $now]);
            return $n < $limit;
        } catch (\Throwable $e) {
            return false;   // a ledger that cannot be read refuses: fail closed
        }
    }

    // ── The guard ─────────────────────────────────────────────────────────────

    /**
     * The one rule, as data (5.18.83, docs/64 §A): may this Starlink installation start or be completed now? Whatever the
     * job's status — a job uCRM reports "in progress" is no longer exempt for being so (5.18.82's D3 reading let a start
     * made in uCRM launder the guard):
     *   - an ACCEPTED record, given for the client uCRM names on the job now        → yes ('accepted');
     *   - an accepted record given for ANOTHER client                               → no  ('other_client');
     *   - a DECLINED record                                                         → no, exempt or not ('declined');
     *   - a job recorded in install_auth_exempt (in progress when switched on, D3)  → yes ('exempt');
     *   - no record, pending, cancelled, expired                                    → no  (that word).
     * Returns ['allow' => bool, 'why' => string, 'exempt' => bool, 'row' => ?array]. Reads, except that a pending record
     * whose link has run out becomes expired here, with its event, as everywhere else.
     */
    public static function decision(\PDO $pdo, array $config, array $job): array
    {
        $jobId  = (int)($job['id'] ?? 0);
        $row    = self::find($pdo, $jobId);
        if ($row !== null) $row = self::expireIfDue($pdo, $row);
        $exempt = self::isExempt($pdo, $jobId);
        $status = $row === null ? 'none' : (string)$row['status'];
        if ($status === 'accepted') {
            $given = (int)($row['crm_client_id'] ?? 0);
            $now   = self::jobClient($job);
            if ($given > 0 && $now > 0 && $given !== $now) {
                return ['allow' => $exempt, 'why' => $exempt ? 'exempt' : 'other_client', 'exempt' => $exempt, 'row' => $row];
            }
            return ['allow' => true, 'why' => 'accepted', 'exempt' => $exempt, 'row' => $row];
        }
        if ($status === 'declined') return ['allow' => false, 'why' => 'declined', 'exempt' => $exempt, 'row' => $row];
        if ($exempt)                return ['allow' => true, 'why' => 'exempt', 'exempt' => true, 'row' => $row];
        return ['allow' => false, 'why' => $status, 'exempt' => false, 'row' => $row];
    }

    /**
     * The job a check-in or check-out guard decides on (5.18.83, docs/64 §A.1): uCRM's answer; when uCRM cannot be read,
     * the plugin's own last record of the job (job_notify_state: its title and status) — so a job the plugin knows is not
     * a Starlink installation checks in and out as it always did during a uCRM outage, and a Starlink installation still
     * meets the guard. null when neither exists: the caller refuses rather than letting an unread job through.
     */
    public static function jobForGuard($crm, \PDO $pdo, int $jobId): ?array
    {
        if ($jobId <= 0) return null;
        if (is_object($crm) && method_exists($crm, 'isConfigured') && $crm->isConfigured()) {
            $job = $crm->get("scheduling/jobs/{$jobId}");
            if (is_array($job) && $job) return $job;
        }
        try {
            $st = $pdo->prepare('SELECT title, job_status, gone FROM job_notify_state WHERE job_id = ?');
            $st->execute([$jobId]);
            $row = $st->fetch(\PDO::FETCH_ASSOC);
            $st->closeCursor();
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_array($row) || trim((string)$row['title']) === '' || (int)($row['gone'] ?? 0) === 1) return null;
        return ['id' => $jobId, 'title' => (string)$row['title'],
                'status' => $row['job_status'] === null ? null : (int)$row['job_status'], '_from' => 'job_notify_state'];
    }

    /** The uCRM client a job belongs to, as uCRM answered it; 0 when the answer does not say. */
    public static function jobClient(array $job): int
    {
        return (int)($job['clientId'] ?? (is_array($job['client'] ?? null) ? ($job['client']['id'] ?? 0) : 0));
    }

    /**
     * May this job move to $target (uCRM status 1 = started, 2 = completed) now? null = yes; otherwise the refusal to
     * answer with, after an INSTALLATION_START_BLOCKED event. Called before anything is written anywhere, by every path in
     * this plugin that starts or completes a job: Accept Job / a status update, GPS check-in, GPS check-out and Complete.
     *   - not Uganda, flag off, or not a Starlink installation → yes, as before;
     *   - otherwise decision() decides, whatever status uCRM reports for the job now.
     */
    public static function guard(\PDO $pdo, array $config, ?string $dataDir, array $job, int $target, string $path, ?int $actorId): ?string
    {
        if (!self::applies($config, $dataDir, $job)) return null;
        if ($target !== 1 && $target !== 2) return null;
        $d = self::decision($pdo, $config, $job);
        if ($d['allow']) return null;
        $jobId = (int)($job['id'] ?? 0);
        $cur   = is_numeric($job['status'] ?? null) ? (int)$job['status'] : null;
        self::event($pdo, $jobId, 'INSTALLATION_START_BLOCKED', 'staff', $actorId, $path . ';status:' . $d['why'] . ';job:' . ($cur === null ? 'unknown' : (string)$cur) . '>' . $target);
        $msg = ($target === 2 && $cur === 1) ? self::REFUSAL_COMPLETE : self::REFUSAL_START;
        return $d['why'] === 'other_client' ? $msg . ' ' . self::OTHER_CLIENT : $msg;
    }

    /** A lifecycle event the existing actions record once they have done their work (STARTED, COMPLETED, SIGNED_OFF). */
    public static function recordLifecycle(\PDO $pdo, array $config, ?string $dataDir, array $job, string $event, string $detail, ?int $actorId): bool
    {
        if (!self::applies($config, $dataDir, $job)) return false;
        $jobId  = (int)($job['id'] ?? 0);
        $row    = self::find($pdo, $jobId);
        $exempt = $row === null && self::isExempt($pdo, $jobId);
        if ($row === null && !$exempt) return false;   // nothing to attach it to (the guard lets no such job through)
        self::event($pdo, $jobId, $event, 'staff', $actorId, $exempt ? $detail . ';exempt' : $detail);
        return true;
    }

    // ── Activation: D3 made explicit (5.18.83, docs/64 §A.4) ─────────────────

    /** The latest activation, or null when the feature was never switched on through its activation step. */
    public static function lastActivation(\PDO $pdo): ?array
    {
        try {
            $st = $pdo->query('SELECT * FROM install_auth_activations ORDER BY id DESC LIMIT 1');
            $row = $st ? $st->fetch(\PDO::FETCH_ASSOC) : false;
            if ($st) $st->closeCursor();
            return is_array($row) ? $row : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Whether the job was in progress when the feature was switched on (and so may be completed without an acceptance). */
    public static function isExempt(\PDO $pdo, int $jobId): bool
    {
        if ($jobId <= 0) return false;
        try {
            $st = $pdo->prepare('SELECT 1 FROM install_auth_exempt WHERE job_id = ?');
            $st->execute([$jobId]);
            $hit = $st->fetchColumn() !== false;
            $st->closeCursor();
            return $hit;
        } catch (\Throwable $e) {
            return false;   // unreadable: no exemption — the guard fails closed
        }
    }

    /**
     * The switch-on step (tools/set_config.php runs it before install_auth_enabled is saved on): read uCRM's in-progress
     * jobs NOW and record the Starlink installation jobs among them as exempt, with one INSTALLATION_EXEMPTED event each —
     * all in one transaction. A job already exempt keeps its first exemption. Refuses, recording nothing, when uCRM is not
     * configured, does not answer, or returns a full page (some jobs could be missing from it).
     * Returns ['ok' => true, 'activation_id', 'jobs_read', 'in_scope', 'exempted', 'job_ids'] or ['ok' => false, 'error', 'code'].
     */
    public static function activate(\PDO $pdo, $crm, array $config, string $by, ?int $now = null): array
    {
        $now = $now ?? time();
        if (!is_object($crm) || !method_exists($crm, 'isConfigured') || !$crm->isConfigured()) {
            return self::fail('uCRM is not configured, so the jobs in progress cannot be read. Nothing was switched on.', 503);
        }
        $list = $crm->get('scheduling/jobs?limit=' . self::ACTIVATION_PAGE . '&statuses[]=1');
        if (!is_array($list)) return self::fail('uCRM did not answer for the jobs in progress. Nothing was switched on; try again.', 502);
        if (count($list) >= self::ACTIVATION_PAGE) {
            return self::fail('uCRM returned ' . count($list) . ' jobs in progress, its page limit, so some could be missing. Nothing was switched on.', 409);
        }
        $scoped = []; $inProgress = 0;
        foreach ($list as $j) {
            if (!is_array($j) || (int)($j['id'] ?? 0) <= 0) continue;
            if (!is_numeric($j['status'] ?? null) || (int)$j['status'] !== 1) continue;   // uCRM may ignore the filter
            $inProgress++;
            if (self::inScope($j, $config)) $scoped[(int)$j['id']] = $j;
        }
        if ($pdo->inTransaction()) return self::fail('The activation could not be locked.', 500);
        try {
            $pdo->exec('BEGIN IMMEDIATE');
            $new = [];
            $chk = $pdo->prepare('SELECT 1 FROM install_auth_exempt WHERE job_id = ?');
            foreach ($scoped as $id => $j) {
                $chk->execute([$id]);
                $hit = $chk->fetchColumn() !== false;
                $chk->closeCursor();
                if (!$hit) $new[$id] = $j;
            }
            $at = gmdate('Y-m-d H:i:s', $now);
            $pdo->prepare('INSERT INTO install_auth_activations (activated_at, activated_by, jobs_read, exempted) VALUES (?, ?, ?, ?)')
                ->execute([$at, self::oneLine($by, 80), $inProgress, count($new)]);
            $actId = (int)$pdo->lastInsertId();
            $ins = $pdo->prepare('INSERT INTO install_auth_exempt (job_id, activation_id, crm_client_id, title, recorded_at) VALUES (?, ?, ?, ?, ?)');
            $evt = $pdo->prepare('INSERT INTO install_auth_events (job_id, event, actor_kind, actor_id, detail, created_at) VALUES (?, \'INSTALLATION_EXEMPTED\', \'system\', NULL, ?, ?)');
            foreach ($new as $id => $j) {
                $ins->execute([$id, $actId, self::jobClient($j), self::oneLine((string)($j['title'] ?? ''), 200), $at]);
                $evt->execute([$id, 'activation:' . $actId . ';status:1', $at]);
            }
            $pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            try { $pdo->exec('ROLLBACK'); } catch (\Throwable $e2) { /* nothing to undo */ }
            return self::fail('The activation could not be recorded. Nothing was switched on.', 500);
        }
        return ['ok' => true, 'activation_id' => $actId, 'jobs_read' => $inProgress, 'in_scope' => count($scoped),
                'exempted' => count($new), 'job_ids' => array_keys($new)];
    }

    // ── uCRM-side changes: recorded, never refused (5.18.83, docs/64 §A.2) ───

    /**
     * The job notifier saw uCRM's job as it is now; $was is the status it last recorded (null: never seen). A Starlink
     * installation that uCRM now shows started (1) or closed (2) although decision() would refuse it here — someone moved
     * it in uCRM's own screen, app or API — is recorded once as INSTALLATION_STARTED_WITHOUT_ACCEPTANCE or
     * INSTALLATION_COMPLETED_WITHOUT_ACCEPTANCE. Returns that event's name when it was recorded now, else null.
     *   - a job first seen already closed is not reported: it may have closed long before the feature existed;
     *   - a job first seen in progress IS reported: every job in progress at activation is exempt, so it started after.
     */
    public static function observeUcrm(\PDO $pdo, array $config, ?string $dataDir, ?int $was, array $job): ?string
    {
        try {
            if (!self::applies($config, $dataDir, $job)) return null;
            $now = is_numeric($job['status'] ?? null) ? (int)$job['status'] : null;
            if ($now !== 1 && $now !== 2) return null;
            if ($was === $now) return null;
            if ($was === null && $now === 2) return null;
            $d = self::decision($pdo, $config, $job);
            if ($d['allow']) return null;
            $jobId = (int)($job['id'] ?? 0);
            $event = $now === 1 ? 'INSTALLATION_STARTED_WITHOUT_ACCEPTANCE' : 'INSTALLATION_COMPLETED_WITHOUT_ACCEPTANCE';
            $st = $pdo->prepare('SELECT 1 FROM install_auth_events WHERE job_id = ? AND event = ?');
            $st->execute([$jobId, $event]);
            $seen = $st->fetchColumn() !== false;
            $st->closeCursor();
            if ($seen) return null;
            self::event($pdo, $jobId, $event, 'system', null, 'ucrm;status:' . $d['why'] . ';job:' . ($was === null ? 'unseen' : (string)$was) . '>' . $now);
            return $event;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ── Events ────────────────────────────────────────────────────────────────

    public static function event(\PDO $pdo, int $jobId, string $event, string $actorKind, ?int $actorId, string $detail = ''): void
    {
        if (!in_array($event, self::EVENTS, true)) throw new \InvalidArgumentException('unknown install auth event: ' . $event);
        try {
            $pdo->prepare('INSERT INTO install_auth_events (job_id, event, actor_kind, actor_id, detail, created_at) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$jobId, $event, $actorKind, $actorId, substr($detail, 0, 200), gmdate('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {
            if (is_file(__DIR__ . '/PluginLog.php')) {
                require_once __DIR__ . '/PluginLog.php';
                PluginLog::notSaved('the authorisation event', 'install_auth_events', "job #{$jobId}, {$event}", $e);
            }
        }
    }

    // ── Words and formats ─────────────────────────────────────────────────────

    /** "05 October 2026 22:14 EAT" from a UTC Y-m-d H:i:s, in the install's zone; '' for nothing. */
    public static function when(string $utc, \DateTimeZone $tz): string
    {
        if (trim($utc) === '') return '';
        try {
            $d = new \DateTime($utc, new \DateTimeZone('UTC'));
        } catch (\Throwable $e) {
            return '';
        }
        return $d->setTimezone($tz)->format('d F Y H:i T');
    }

    /** "UGX 350,000" — whole shillings, two decimals only when they are not zero. */
    public static function money($v, string $currency): string
    {
        $v = (float)$v;
        $s = (abs($v - round($v)) < 0.005) ? number_format($v, 0, '.', ',') : number_format($v, 2, '.', ',');
        return trim($currency . ' ' . $s);
    }

    public static function currency(array $config): string
    {
        $c = strtoupper(trim((string)($config['currency_code'] ?? $config['ai_currency'] ?? '')));
        if ($c !== '' && preg_match('/^[A-Z]{3}$/', $c)) return $c;
        try {
            if (!class_exists('TenantProfile')) require_once __DIR__ . '/TenantProfile.php';
            $t = TenantProfile::current($config)->currencyCode();
            if (preg_match('/^[A-Z]{3}$/', $t)) return $t;
        } catch (\Throwable $e) { /* fall through */ }
        return 'UGX';
    }

    public static function maskPhone(string $p): string
    {
        $d = (string)preg_replace('/\D+/', '', $p);
        if ($d === '') return '';
        return (strlen($d) > 3 ? str_repeat('•', max(3, strlen($d) - 3)) : '') . substr($d, -3);
    }

    public static function maskEmail(string $e): string
    {
        $e = trim($e);
        if ($e === '' || strpos($e, '@') === false) return '';
        [$l, $d] = explode('@', $e, 2);
        return substr($l, 0, 1) . '•••@' . $d;
    }

    public static function clientName(array $client): string
    {
        $n = trim(trim((string)($client['firstName'] ?? '')) . ' ' . trim((string)($client['lastName'] ?? '')));
        if ($n === '') $n = trim((string)($client['companyName'] ?? ''));
        return self::oneLine($n, 120);
    }

    /** uCRM contacts[0].phone (D10): the first contact's number, as uCRM holds it; '' when none. */
    public static function clientPhone(array $client): string
    {
        $c = $client['contacts'][0] ?? null;
        $p = is_array($c) ? trim((string)($c['phone'] ?? '')) : '';
        if ($p === '' && is_array($c) && !empty($c['phones'][0]['number'])) $p = trim((string)$c['phones'][0]['number']);
        return $p;
    }

    public static function clientEmail(array $client): string
    {
        $c = $client['contacts'][0] ?? null;
        $e = is_array($c) ? trim((string)($c['email'] ?? '')) : '';
        return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : '';
    }

    public static function clientAddress(array $client): string
    {
        return trim(trim((string)($client['street1'] ?? '')) . ' ' . trim((string)($client['street2'] ?? '')) . ' ' . trim((string)($client['city'] ?? '')));
    }

    /** First name only, for the customer's confirmation (the brief: no unnecessary technician information). */
    public static function firstName(string $name): string
    {
        $name = trim((string)preg_replace('/\s+/', ' ', $name));
        if ($name === '') return '';
        $parts = explode(' ', $name);
        return $parts[0];
    }

    public static function oneLine(string $s, int $max): string
    {
        $s = trim((string)preg_replace('/\s*[\r\n]+\s*/', ' ', $s));
        $s = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s);
        return mb_substr($s, 0, $max);
    }

    private static function reference(int $seq, int $now, \DateTimeZone $tz): string
    {
        $d = (new \DateTime('@' . $now))->setTimezone($tz)->format('Ymd');
        return sprintf('ACC-%s-%06d', $d, $seq);
    }

    private static function fail(string $error, int $code): array
    {
        return ['ok' => false, 'error' => $error, 'code' => $code];
    }
}
