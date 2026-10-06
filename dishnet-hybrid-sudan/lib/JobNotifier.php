<?php
/**
 * JobNotifier — release B of J1–J8 on Uganda (docs/44 §5, §16.12, §16.14): the one component that decides and sends
 * every job WhatsApp message.
 *
 * Callers hand it a uCRM job id: ＋ New Job, Bulk Dispatch, Reschedule, and uCRM's job.add / job.edit / job.delete.
 * It compares uCRM's job NOW with job_notify_state — who was last told what — inside one BEGIN IMMEDIATE transaction,
 * writes the new state, commits, and only then sends. So each change sends exactly one message however many paths see
 * it and however often uCRM redelivers, and a lost webhook is caught at the job's next change of any kind.
 *
 *   never told, an assignee          → assigned     message 1 to the assignee (the ACCEPT JOB link)
 *   another assignee                 → reassigned   message 1 to the new one; D3 "no longer assigned" to the old one
 *   the assignee removed             → unassigned   D3 "no longer assigned"
 *   same assignee, another time      → rescheduled  "new time" to the assignee
 *   uCRM answers 404                 → cancelled    D3 "cancelled" to the last assignee
 *   closed (status 2)                → closed       recorded only
 *   anything else                    → nothing
 *
 * Accept is its own entry point, accepted(): message 2, the completion link, to the job's assignee, once per
 * assignment (the claim is job_notify_state.accepted_by).
 *
 * Only uCRM's answer counts: never a browser's value, never the webhook body (R3). A uCRM answer that does not carry
 * the assignee, time and status is not used — a missing field must never read as "unassigned" — and the job is read
 * again; if that answer is partial too, nothing changes. The assignee is uCRM's assignedUserId; the staff account is
 * the one active row VERIFIED-linked to it (M7); the number is J3's form of that row's phone. Messages go on the support
 * number as CLASS_STAFF. A failed send is not retried: the state has moved on, and the failure stands in the Message
 * Log, the failure queue and job_notify_events.
 *
 * Each message also goes by e-mail, the same text, to that staff account's own address — the one its verified link was
 * saved with — through the plugin's mail server (docs/44 §16.16). The e-mail goes whether or not the WhatsApp did, and
 * its outcome is recorded beside the WhatsApp's without changing it. Nobody to message means nobody to e-mail.
 */
final class JobNotifier
{
    /** Where a change was seen. */
    public const SOURCES = ['my_jobs', 'bulk', 'reschedule', 'ucrm_webhook'];

    /** The Message Log event of each message. */
    public const LOG_ASSIGNED    = 'job_assigned';
    public const LOG_RESCHEDULED = 'job_rescheduled';
    public const LOG_UNASSIGNED  = 'job_unassigned';
    public const LOG_CANCELLED   = 'job_cancelled';
    public const LOG_ACCEPTED    = 'ops_job_accepted_self';

    /** Outcomes of one message. */
    public const MESSAGE_OUTCOMES = ['sent', 'failed', 'no_staff_account', 'ambiguous_staff_account', 'no_usable_number'];

    /** Outcomes of its e-mail copy; null when there was no staff account to send it to. */
    public const EMAIL_OUTCOMES = ['sent', 'failed', 'no_email', 'not_configured'];

    private $crm;
    private $store;
    private $notify;
    private $config;
    private $tenant;
    private $tz;
    private $dataDir;
    /** @var MailService|null one per notifier, so uCRM's mail settings are read once per request */
    private $mailer = null;
    /** @var string|null why no more e-mail is tried in this request, once the mail server has refused one */
    private $mailDown = null;

    public function __construct($crm, $store, $notify, array $config, ?string $dataDir)
    {
        $lib = __DIR__;
        foreach (['TenantProfile', 'StaffDirectory', 'JobMessages', 'ContactOptOut', 'CustomerContact'] as $c) {
            if (!class_exists($c)) require_once "{$lib}/{$c}.php";
        }
        require_once "{$lib}/crm_url.php";
        require_once "{$lib}/timezone.php";
        $this->crm    = $crm;
        $this->store  = $store;
        $this->notify = $notify;
        $this->config = $config;
        $this->tenant = TenantProfile::current($config, $dataDir);
        $this->tz     = dn_tz_obj($config);
        $this->dataDir = $dataDir;
    }

    /**
     * Observe one job and send what its change calls for.
     *
     * $job is uCRM's own answer when the caller holds one (the POST or PATCH it just made, or the webhook's verified
     * GET). Without it, when it is another job's, or when it lacks the assignee, time or status, the job is read from
     * uCRM here. 404 means deleted.
     *
     * @return array{event: ?string, outcome: string, detail: string, sent: int, assignee: ?int, messages: array}
     *   outcome: the first message's (sent | failed | no_staff_account | ambiguous_staff_account | no_usable_number),
     *            or recorded (a change with nobody to tell), no_change, unverified (uCRM or the state not readable)
     */
    public function observe(int $jobId, string $source, ?array $job = null): array
    {
        if ($jobId <= 0) return self::result(null, 'unverified', 'no job id');
        if (!in_array($source, self::SOURCES, true)) return self::result(null, 'unverified', 'unknown source');

        if (!is_array($job) || (int)($job['id'] ?? 0) !== $jobId || !self::complete($job)) {
            $job = $this->crm->get("scheduling/jobs/{$jobId}");
            if (!is_array($job)) {
                $err = $this->crm->getLastError();
                if ((int)($err['http_code'] ?? 0) !== 404) return self::result(null, 'unverified', 'uCRM did not answer for the job');
                $job = null;   // deleted
            } elseif ((int)($job['id'] ?? $jobId) !== $jobId || !self::complete($job)) {
                return self::result(null, 'unverified', 'uCRM\'s answer does not carry the job\'s assignee, time and status');
            }
        }
        $now = $job === null ? null : [
            'assignee' => ($a = (int)$job['assignedUserId']) > 0 ? $a : null,
            'time'     => self::utc($job['date']),
            'status'   => is_numeric($job['status']) ? (int)$job['status'] : null,
            'title'    => trim((string)($job['title'] ?? '')),
        ];

        // ── The claim: compare and write in one transaction ─────────────────
        $pdo = $this->store->getPdo();
        if ($pdo->inTransaction()) return self::result(null, 'unverified', 'called inside a transaction');
        try {
            $pdo->exec('BEGIN IMMEDIATE');
        } catch (\Throwable $e) {
            return self::result(null, 'unverified', 'the job state could not be locked');
        }
        try {
            $st = $pdo->prepare('SELECT * FROM job_notify_state WHERE job_id = ?');
            $st->execute([$jobId]);
            $was  = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
            $st->closeCursor();   // 5.18.53: the read ends before the COMMIT, as in accepted()
            $plan = self::decide($was, $now);
            if ($plan['state'] !== null) {
                $s = $plan['state'];
                $pdo->prepare('INSERT INTO job_notify_state (job_id, assignee_id, job_time, job_status, title, gone, accepted_by, version, updated_at)
                               VALUES (?, ?, ?, ?, ?, ?, ?, 1, datetime(\'now\'))
                               ON CONFLICT(job_id) DO UPDATE SET assignee_id = excluded.assignee_id, job_time = excluded.job_time,
                                   job_status = excluded.job_status, title = excluded.title, gone = excluded.gone,
                                   accepted_by = excluded.accepted_by, version = job_notify_state.version + 1,
                                   updated_at = excluded.updated_at')
                    ->execute([$jobId, $s['assignee'], $s['time'], $s['status'], $s['title'], $s['gone'], $s['accepted_by']]);
            }
            $pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            try { $pdo->exec('ROLLBACK'); } catch (\Throwable $e2) { /* nothing to undo */ }
            return self::result(null, 'unverified', 'the job state could not be read or written');
        }

        // ── 5.18.83 (Uganda, install_auth_enabled; docs/64 §A.2): a Starlink installation uCRM now shows started or closed
        // without the customer's acceptance — moved in uCRM's own screen, app or API, where this plugin cannot refuse it —
        // is recorded in its authorisation trail and the leaders are told, once. Decided from the status this notifier held
        // BEFORE this change (read inside the claim above), so two deliveries of one change report it once.
        $iaUcrm = $job !== null ? $this->installAuthObserve($jobId, $was, (array)$job) : null;
        $withIa = function (array $r) use ($iaUcrm): array { if ($iaUcrm !== null) $r['install_auth_ucrm'] = $iaUcrm; return $r; };

        // ── The messages, after the commit: never while the lock is held ────
        $assignee = $now['assignee'] ?? null;
        if ($plan['event'] === null) return $withIa(self::result(null, 'no_change', 'nothing to send', $assignee));
        if ($plan['messages'] === []) {
            $this->event($jobId, $plan['event'], null, $plan, $source, null, 'recorded', $plan['why']);
            return $withIa(self::result($plan['event'], 'recorded', $plan['why'], $assignee));
        }
        $client = null; $sent = [];
        foreach ($plan['messages'] as $m) {
            if ($m['kind'] === 'assigned' && $client === null) $client = $this->client((int)($job['clientId'] ?? 0));
            $out = $this->deliver((int)$m['to'], $m['kind'], $this->fields($jobId, $m, (array)$job, $client ?? []));
            $this->event($jobId, $plan['event'], $m['kind'], $plan, $source, $out['staff_id'], $out['outcome'], $out['detail'], $out['email'], $out['email_detail']);
            $sent[] = ['message' => $m['kind'], 'outcome' => $out['outcome'], 'detail' => $out['detail'], 'staff_id' => $out['staff_id'],
                       'email' => $out['email'], 'email_detail' => $out['email_detail']];
        }
        $r = self::result($plan['event'], $sent[0]['outcome'], $sent[0]['detail'], $assignee, $sent);
        // 5.18.82 (Uganda, install_auth_enabled): a job reassigned AFTER the customer accepted tells the new engineer so
        // (docs/61 §3; the brief's phase 12). The acceptance record is read, never written, and nothing here can undo a
        // message above or the state already committed. 5.18.83 (docs/64 §C): also on 'assigned' — a job this notifier
        // first sees after a reassignment reads as a first assignment, and its engineer must be told all the same. The
        // engineer told at the acceptance is not told twice (one dedupe mark per acceptance and engineer).
        if (in_array($plan['event'], ['assigned', 'reassigned'], true) && $assignee !== null) {
            $ia = $this->installAuthReassigned($jobId, $assignee);
            if ($ia !== null) $r['install_auth'] = $ia;
        }
        return $withIa($r);
    }

    /** 5.18.83: the uCRM-side check (InstallAuth::observeUcrm) and the leaders' alert; null when nothing was recorded. */
    private function installAuthObserve(int $jobId, ?array $was, array $job): ?array
    {
        try {
            if (!is_file(__DIR__ . '/InstallAuth.php') || !is_file(__DIR__ . '/InstallAuthNotifier.php')) return null;
            require_once __DIR__ . '/InstallAuth.php';
            if (!InstallAuth::enabled($this->config, $this->dataDir)) return null;
            $wasStatus = ($was !== null && (int)($was['gone'] ?? 0) !== 1 && $was['job_status'] !== null) ? (int)$was['job_status'] : null;
            $event = InstallAuth::observeUcrm($this->store->getPdo(), $this->config, $this->dataDir, $wasStatus, $job);
            if ($event === null) return null;
            require_once __DIR__ . '/InstallAuthNotifier.php';
            $alert = (new InstallAuthNotifier($this->crm, $this->store, $this->notify, $this->config, $this->dataDir))
                ->alertLeaders($jobId, $event, ['title' => trim((string)($job['title'] ?? ''))]);
            return ['event' => $event, 'alert' => $alert];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** The "customer already confirmed" message to a job's new engineer; null when the feature or the record is absent. */
    private function installAuthReassigned(int $jobId, int $assignee): ?array
    {
        try {
            if (!is_file(__DIR__ . '/InstallAuth.php') || !is_file(__DIR__ . '/InstallAuthNotifier.php')) return null;
            require_once __DIR__ . '/InstallAuth.php';
            if (!InstallAuth::enabled($this->config, $this->dataDir)) return null;
            $row = InstallAuth::find($this->store->getPdo(), $jobId);
            if ($row === null || ($row['status'] ?? '') !== 'accepted') return null;
            require_once __DIR__ . '/InstallAuthNotifier.php';
            return (new InstallAuthNotifier($this->crm, $this->store, $this->notify, $this->config, $this->dataDir))->alreadyConfirmed($row, $assignee);
        } catch (\Throwable $e) {
            return ['outcome' => 'failed', 'detail' => 'the already-confirmed message could not be sent'];
        }
    }

    /**
     * Message 2, after Accept moved the job from Open (0) into progress. $before is uCRM's job as read before the
     * Accept; $after is uCRM's answer to it. The completion link goes to the job's assignee — who had message 1, which
     * promised it — once per assignment, however many presses race.
     */
    public function accepted(int $jobId, array $before, ?array $after = null): array
    {
        if ($jobId <= 0 || (int)($before['id'] ?? 0) !== $jobId || !self::complete($before)) {
            return self::result(null, 'unverified', 'uCRM\'s job before the Accept was not readable');
        }
        if (!is_numeric($before['status']) || (int)$before['status'] !== 0) {
            return self::result(null, 'no_change', 'the job was not waiting to be accepted');
        }
        $job = (is_array($after) && (int)($after['id'] ?? 0) === $jobId && self::complete($after)) ? $after : $before;
        $assignee = (int)$job['assignedUserId'];
        $plan = ['from' => $assignee > 0 ? $assignee : null, 'to' => $assignee > 0 ? $assignee : null,
                 'from_time' => null, 'to_time' => self::utc($job['date'])];
        if ($assignee <= 0) {
            $this->event($jobId, 'accepted', null, $plan, 'accept', null, 'recorded', 'nobody is assigned');
            return self::result('accepted', 'recorded', 'nobody is assigned', null);
        }

        $pdo = $this->store->getPdo();
        if ($pdo->inTransaction()) return self::result(null, 'unverified', 'called inside a transaction', $assignee);
        try {
            $pdo->exec('BEGIN IMMEDIATE');
        } catch (\Throwable $e) {
            return self::result(null, 'unverified', 'the job state could not be locked', $assignee);
        }
        try {
            $st = $pdo->prepare('SELECT accepted_by FROM job_notify_state WHERE job_id = ?');
            $st->execute([$jobId]);
            $row = $st->fetch(\PDO::FETCH_ASSOC);
            // 5.18.53 (docs/44 §16.28): the read ends here. Left open, it kept SQLite's read snapshot past the COMMIT
            // (WAL): once another process wrote — uCRM's notice of this very Accept, a second later — every later write
            // of this request failed at once, and message 2 went with none of its records saved.
            $st->closeCursor();
            if (is_array($row) && (int)($row['accepted_by'] ?? 0) === $assignee) {
                $pdo->exec('COMMIT');
                return self::result(null, 'no_change', 'the completion link was already sent for this assignment', $assignee);
            }
            if (is_array($row)) {
                // Only the claim. Who was last told what stays observe()'s, so a reassignment it has not seen yet is
                // still noticed, and the previous engineer still told.
                $pdo->prepare('UPDATE job_notify_state SET accepted_by = ?, version = version + 1, updated_at = datetime(\'now\') WHERE job_id = ?')
                    ->execute([$assignee, $jobId]);
            } else {
                // A job accepted before the plugin saw it: its assignee knows it, so no message 1 follows.
                $pdo->prepare('INSERT INTO job_notify_state (job_id, assignee_id, job_time, job_status, title, gone, accepted_by, version, updated_at)
                               VALUES (?, ?, ?, ?, ?, 0, ?, 1, datetime(\'now\'))')
                    ->execute([$jobId, $assignee, self::utc($job['date']),
                               is_numeric($job['status']) ? (int)$job['status'] : null, trim((string)($job['title'] ?? '')), $assignee]);
            }
            $pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            try { $pdo->exec('ROLLBACK'); } catch (\Throwable $e2) { /* nothing to undo */ }
            return self::result(null, 'unverified', 'the job state could not be read or written', $assignee);
        }

        $fields = $this->fields($jobId, ['kind' => 'accepted'], $job, $this->client((int)($job['clientId'] ?? 0)));
        $out = $this->deliver($assignee, 'accepted', $fields);
        $this->event($jobId, 'accepted', 'accepted', $plan, 'accept', $out['staff_id'], $out['outcome'], $out['detail'], $out['email'], $out['email_detail']);
        return self::result('accepted', $out['outcome'], $out['detail'], $assignee,
            [['message' => 'accepted', 'outcome' => $out['outcome'], 'detail' => $out['detail'], 'staff_id' => $out['staff_id'],
              'email' => $out['email'], 'email_detail' => $out['email_detail']]]);
    }

    /** The job page in the staff app: My Jobs → this job. Behind the staff sign-in; only the job number is carried. */
    public function jobLink(int $jobId): string
    {
        return dn_plugin_public($this->config) . '?page=dashboard&tab=scheduling&job=' . $jobId;
    }

    /** The newest event row of this job, 0 when none: a caller's mark before it changes the job. */
    public function lastEventId(int $jobId): int
    {
        try {
            $st = $this->store->getPdo()->prepare('SELECT MAX(id) FROM job_notify_events WHERE job_id = ?');
            $st->execute([$jobId]);
            return (int)$st->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * When observe() found nothing new because another path claimed the change first: that path's record of $message
     * for this job after $afterId, or null while it is still sending (or when nothing was ever sent).
     */
    public function recordedSince(int $jobId, string $message, int $afterId = 0): ?array
    {
        try {
            $st = $this->store->getPdo()->prepare('SELECT * FROM job_notify_events WHERE job_id = ? AND message = ? AND id > ? ORDER BY id DESC LIMIT 1');
            $st->execute([$jobId, $message, $afterId]);
            $row = $st->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_array($row)) return null;
        $staff = $row['staff_id'] === null ? null : (int)$row['staff_id'];
        $to    = $row['to_assignee_id'] === null ? null : (int)$row['to_assignee_id'];
        return self::result((string)$row['event'], (string)$row['outcome'], (string)$row['detail'], $to,
            [['message' => (string)$row['message'], 'outcome' => (string)$row['outcome'], 'detail' => (string)$row['detail'], 'staff_id' => $staff,
              'email' => ($row['email_outcome'] ?? null) === null ? null : (string)$row['email_outcome'], 'email_detail' => (string)($row['email_detail'] ?? '')]])
            + ['by' => (string)$row['source']];
    }

    /**
     * §5.2's table, as a pure function of the state row last written and uCRM's job now (null: deleted).
     *
     * @return array{event: ?string, why: string, messages: array, state: ?array, from: ?int, to: ?int, from_time: ?string, to_time: ?string}
     */
    public static function decide(?array $was, ?array $now): array
    {
        $plan = ['event' => null, 'why' => '', 'messages' => [], 'state' => null,
                 'from' => null, 'to' => null, 'from_time' => null, 'to_time' => null];
        $wasGone = $was !== null && (int)($was['gone'] ?? 0) === 1;
        $wasA    = ($was !== null && $was['assignee_id'] !== null) ? (int)$was['assignee_id'] : null;
        $wasT    = $was !== null ? (string)($was['job_time'] ?? '') : '';
        $wasS    = ($was !== null && $was['job_status'] !== null) ? (int)$was['job_status'] : null;
        $wasTi   = $was !== null ? (string)($was['title'] ?? '') : '';
        $wasAcc  = ($was !== null && ($was['accepted_by'] ?? null) !== null) ? (int)$was['accepted_by'] : null;

        if ($now === null) {                                   // uCRM answered 404
            if ($was === null || $wasGone) return $plan;       // never seen, or already recorded: nothing to say
            $plan['event'] = 'cancelled'; $plan['why'] = 'deleted in uCRM';
            $plan['from'] = $wasA; $plan['from_time'] = $wasT;
            $plan['state'] = ['assignee' => $wasA, 'time' => $wasT, 'status' => $wasS, 'title' => $wasTi, 'gone' => 1, 'accepted_by' => $wasAcc];
            if ($wasA !== null && $wasS !== 2) {
                $plan['messages'][] = ['kind' => 'cancelled', 'to' => $wasA, 'title' => $wasTi, 'was' => $wasT];
            }
            return $plan;
        }

        $a = $now['assignee']; $t = $now['time']; $s = $now['status'];
        $plan['state'] = ['assignee' => $a, 'time' => $t, 'status' => $s, 'title' => $now['title'], 'gone' => 0,
                          'accepted_by' => ($was !== null && !$wasGone && $a === $wasA) ? $wasAcc : null];
        $plan['from'] = $wasA; $plan['to'] = $a; $plan['from_time'] = $was !== null ? $wasT : null; $plan['to_time'] = $t;

        if ($was === null || $wasGone) {                         // never told (or told it was gone)
            $plan['from'] = null; $plan['from_time'] = null;
            if ($s === 2)        { $plan['event'] = 'closed';   $plan['why'] = 'closed in uCRM'; }
            elseif ($a !== null) { $plan['event'] = 'assigned'; $plan['messages'][] = ['kind' => 'assigned', 'to' => $a]; }
            return $plan;
        }
        if ($s === 2) {                                        // closed: recorded once, never messaged
            if ($wasS !== 2) { $plan['event'] = 'closed'; $plan['why'] = 'closed in uCRM'; }
            return self::unchanged($plan, $was, $now);
        }
        if ($a !== $wasA) {
            if ($a === null) {
                $plan['event'] = 'unassigned'; $plan['why'] = 'the assignee was removed';
                $plan['messages'][] = ['kind' => 'removed', 'to' => $wasA];
            } elseif ($wasA === null) {
                $plan['event'] = 'assigned';
                $plan['messages'][] = ['kind' => 'assigned', 'to' => $a];
            } else {
                $plan['event'] = 'reassigned';
                $plan['messages'][] = ['kind' => 'assigned', 'to' => $a];
                $plan['messages'][] = ['kind' => 'reassigned_away', 'to' => $wasA];
            }
            return $plan;
        }
        if ($t !== $wasT && $a !== null) {
            $plan['event'] = 'rescheduled';
            $plan['messages'][] = ['kind' => 'new_time', 'to' => $a, 'was' => $wasT];
            return $plan;
        }
        return self::unchanged($plan, $was, $now);
    }

    /** No message: keep the state row current when anything differs, write nothing when nothing does. */
    private static function unchanged(array $plan, array $was, array $now): array
    {
        $same = (($was['assignee_id'] === null ? null : (int)$was['assignee_id']) === $now['assignee'])
             && (string)$was['job_time'] === $now['time']
             && (($was['job_status'] === null ? null : (int)$was['job_status']) === $now['status'])
             && (string)$was['title'] === $now['title'];
        if ($same && $plan['event'] === null) $plan['state'] = null;
        return $plan;
    }

    /** A uCRM job answer the notifier may use: it carries the assignee, the time and the status (V4). */
    public static function complete(array $job): bool
    {
        return array_key_exists('assignedUserId', $job) && array_key_exists('date', $job) && array_key_exists('status', $job);
    }

    /** uCRM's job time as UTC 'Y-m-d H:i'; '' when the job has none or it cannot be read. */
    public static function utc($date): string
    {
        $date = trim((string)$date);
        if ($date === '') return '';
        try {
            return (new \DateTime($date))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i');
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** uCRM's client: name and first phone. Only the id when uCRM does not answer; nothing when there is no client. */
    private function client(int $clientId): array
    {
        if ($clientId <= 0) return [];
        $c = $this->crm->get("clients/{$clientId}");
        if (!is_array($c)) return ['id' => $clientId];
        $name = trim(trim((string)($c['firstName'] ?? '')) . ' ' . trim((string)($c['lastName'] ?? '')));
        if ($name === '') $name = trim((string)($c['companyName'] ?? ''));
        $phone = '';
        foreach ((array)($c['contacts'] ?? []) as $ct) {
            $p = trim((string)(is_array($ct) ? ($ct['phone'] ?? '') : ''));
            if ($p !== '') { $phone = $p; break; }
        }
        return ['id' => $clientId, 'name' => $name, 'phone' => $phone];
    }

    /** Every value a message may show: uCRM's job and client, the tenant profile, the plugin's last record of the job. */
    private function fields(int $jobId, array $m, array $job, array $client): array
    {
        $gone = ($m['kind'] ?? '') === 'cancelled';
        return [
            'brand'        => $this->tenant->tradingName(),
            'job_id'       => $jobId,
            'title'        => $gone ? (string)($m['title'] ?? '') : trim((string)($job['title'] ?? '')),
            'when'         => $gone ? '' : JobMessages::when(self::utc($job['date'] ?? null), $this->tz),
            'was'          => array_key_exists('was', $m) ? JobMessages::when((string)$m['was'], $this->tz) : '',
            'client_name'  => (string)($client['name'] ?? ''),
            'client_id'    => (int)($client['id'] ?? 0),
            'client_phone' => (string)($client['phone'] ?? ''),
            'address'      => $gone ? '' : trim((string)($job['address'] ?? '')),
            'link'         => $this->jobLink($jobId),
            'support'      => CustomerContact::support($this->config),
            'website'      => $this->tenant->website(),
        ];
    }

    /** The staff account behind a uCRM user, then the message to its number and, the same text, to its e-mail. */
    private function deliver(int $ucrmUserId, string $kind, array $fields): array
    {
        $none = ['email' => null, 'email_detail' => ''];
        $hits = StaffDirectory::byUcrmUser((array)($this->store->load('retailers.json') ?? []), $ucrmUserId);
        if (count($hits) === 0) {
            return ['outcome' => 'no_staff_account', 'staff_id' => null, 'detail' => "no staff account is linked to uCRM user #{$ucrmUserId}"] + $none;
        }
        if (count($hits) > 1) {
            return ['outcome' => 'ambiguous_staff_account', 'staff_id' => null, 'detail' => count($hits) . " staff accounts are linked to uCRM user #{$ucrmUserId}"] + $none;
        }
        $row = $hits[0];
        $id  = (int)($row['id'] ?? 0);
        $fields['name'] = trim((string)($row['name'] ?? ''));
        switch ($kind) {
            case 'assigned':        $text = JobMessages::assigned($fields);       $log = self::LOG_ASSIGNED;    break;
            case 'accepted':        $text = JobMessages::accepted($fields);       $log = self::LOG_ACCEPTED;    break;
            case 'reassigned_away': $text = JobMessages::reassignedAway($fields); $log = self::LOG_UNASSIGNED;  break;
            case 'removed':         $text = JobMessages::removed($fields);        $log = self::LOG_UNASSIGNED;  break;
            case 'new_time':        $text = JobMessages::newTime($fields);        $log = self::LOG_RESCHEDULED; break;
            case 'cancelled':       $text = JobMessages::cancelled($fields);      $log = self::LOG_CANCELLED;   break;
            default: return ['outcome' => 'failed', 'staff_id' => $id, 'detail' => "staff account #{$id}: no such message"] + $none;
        }
        $phone = StaffDirectory::phoneOf($row, $this->tenant);
        if ($phone === null) {
            $wa = ['outcome' => 'no_usable_number', 'staff_id' => $id, 'detail' => "staff account #{$id} has no usable number"];
        } else {
            $this->notify->sendVia('support', $phone, $text, $log, [], ContactOptOut::CLASS_STAFF);
            $r = $this->notify->lastSendResult();
            if (!empty($r['success'])) {
                $wa = ['outcome' => 'sent', 'staff_id' => $id, 'detail' => "staff account #{$id}"];
            } else {
                $why = ($r['http_code'] === null && $r['error'] === null) ? ' (no WhatsApp transport took it)' : '';
                $wa = ['outcome' => 'failed', 'staff_id' => $id, 'detail' => "staff account #{$id}{$why}"];
            }
        }
        $mail = $this->email($row, $kind, $text, $fields);
        return $wa + ['email' => $mail['outcome'], 'email_detail' => $mail['detail']];
    }

    /**
     * The same message by e-mail, to the staff account's own address — the one its verified uCRM link was saved with
     * (M7) — through the plugin's mail server, as every other plugin e-mail goes. Never throws, and never changes what
     * the WhatsApp did. After a refusal that is not about this one address, nothing more is tried in this request: a
     * mail server that does not answer would otherwise cost each job of a Bulk Dispatch its whole connection timeout.
     */
    private function email(array $row, string $kind, string $text, array $fields): array
    {
        $id = (int)($row['id'] ?? 0);
        $to = StaffDirectory::email($row);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['outcome' => 'no_email', 'detail' => "staff account #{$id} has no usable e-mail address"];
        }
        if ($this->mailDown !== null) return ['outcome' => 'failed', 'detail' => "staff account #{$id}: not tried, {$this->mailDown}"];
        try {
            $mail = $this->mailer();
            if ($mail === null || !$mail->getConfig()) {
                return ['outcome' => 'not_configured', 'detail' => 'no mail server is set up for the plugin'];
            }
            $reply = $this->replyTo();
            $name  = trim((string)preg_replace('/[\x00-\x1F"<>]+/', ' ', (string)($fields['name'] ?? '')));
            $r = $mail->send($to, $name, JobMessages::subject($kind, $fields), JobMessages::html($text), str_replace("\n", "\r\n", $text),
                $reply !== '' ? ['Reply-To' => $reply] : []);
        } catch (\Throwable $e) {
            $this->mailDown = 'an earlier e-mail in this request could not be sent';
            return ['outcome' => 'failed', 'detail' => "staff account #{$id}: the e-mail could not be sent"];
        }
        if (!empty($r['ok'])) return ['outcome' => 'sent', 'detail' => "staff account #{$id}"];
        $err = trim((string)preg_replace('/\S+@\S+/', '<address>', (string)($r['error'] ?? '')));
        if (!preg_match('/^(RCPT TO|Invalid recipient)/', $err)) $this->mailDown = 'the mail server did not take an earlier e-mail in this request';
        return ['outcome' => 'failed', 'detail' => "staff account #{$id}: " . ($err !== '' ? substr($err, 0, 120) : 'the mail server did not take it')];
    }

    /** The plugin's mail sender, made once; null without a data directory to read its settings from. */
    private function mailer(): ?MailService
    {
        if ($this->mailer === null) {
            if ($this->dataDir === null || $this->dataDir === '') return null;
            if (!class_exists('MailService')) require_once __DIR__ . '/MailService.php';
            $this->mailer = new MailService($this->dataDir);
        }
        return $this->mailer;
    }

    /** Where a reply goes: the tenant's e-mail reply address, as on every other plugin e-mail; '' for none. */
    private function replyTo(): string
    {
        $v = trim((string)($this->config['email_reply_to'] ?? ''));
        if ($v === '') $v = trim((string)($this->tenant->emailBrandDefaults()['email_reply_to'] ?? ''));
        return MailService::normalizeFrom($v);
    }

    private function event(int $jobId, string $event, ?string $message, array $plan, string $source, ?int $staffId, string $outcome, string $detail,
                           ?string $email = null, string $emailDetail = ''): void
    {
        $row = ['job_id' => $jobId, 'event' => $event, 'message' => $message, 'from_assignee_id' => $plan['from'], 'to_assignee_id' => $plan['to'],
                'from_time' => $plan['from_time'], 'to_time' => $plan['to_time'], 'source' => $source, 'staff_id' => $staffId,
                'outcome' => $outcome, 'detail' => $detail];
        // With the e-mail's two columns (migration 076); without them only if they are missing, so the history keeps the
        // WhatsApp whatever became of 076.
        $failed = null;
        foreach ([$row + ['email_outcome' => $email, 'email_detail' => $email === null ? null : $emailDetail], $row] as $r) {
            try {
                $this->store->getPdo()->prepare('INSERT INTO job_notify_events (' . implode(', ', array_keys($r)) . ') VALUES ('
                    . implode(', ', array_fill(0, count($r), '?')) . ')')->execute(array_values($r));
                return;
            } catch (\Throwable $e) {
                // A missing history row must not undo the send. Since 5.18.53 it is said in the plugin log: on
                // 28 September the Message Log row was lost with it, and nothing said so (docs/44 §16.28).
                $failed = $e;
            }
        }
        if ($failed !== null && is_file(__DIR__ . '/PluginLog.php')) {
            require_once __DIR__ . '/PluginLog.php';
            PluginLog::notSaved('the job history row', 'job_notify_events', "job #{$jobId}, {$event}" . ($message !== null ? "/{$message}" : ''), $failed);
        }
    }

    private static function result(?string $event, string $outcome, string $detail, ?int $assignee = null, array $messages = []): array
    {
        return ['event' => $event, 'outcome' => $outcome, 'detail' => $detail,
                'sent' => count(array_filter($messages, function ($m) { return $m['outcome'] === 'sent'; })),
                'assignee' => $assignee, 'messages' => $messages];
    }

    /** One webhook-log line per message, worded for WA Events' rules (J7): sent, failed, skipped or info. */
    public static function logLines(int $jobId, array $r): array
    {
        $out = [];
        foreach ((array)($r['messages'] ?? []) as $m) {
            $what = self::label((string)($r['event'] ?? ''), (string)$m['message']);
            switch ($m['outcome']) {
                case 'sent':   $line = "Job #{$jobId} ({$what}) — WhatsApp sent to {$m['detail']}"; break;
                case 'failed': $line = "Job #{$jobId} ({$what}) — WhatsApp failed for {$m['detail']}"; break;
                default:       $line = "Job #{$jobId} ({$what}) — WhatsApp skipped: {$m['detail']}";
            }
            $out[] = $line . self::emailClause($m['email'] ?? null);
        }
        // 5.18.83: a Starlink installation moved in uCRM without the customer's acceptance has a line of its own — worded
        // without the words WA Events files a WhatsApp line by.
        if (isset($r['install_auth_ucrm']['event'])) {
            $a = (array)($r['install_auth_ucrm']['alert'] ?? []);
            $out[] = "Job #{$jobId} — Customer Installation Authorisation: uCRM shows this Starlink installation "
                   . ($r['install_auth_ucrm']['event'] === 'INSTALLATION_COMPLETED_WITHOUT_ACCEPTANCE' ? 'closed' : 'started')
                   . " without the customer's acceptance; recorded, leaders alerted ("
                   . (int)($a['whatsapp'] ?? 0) . ' by WhatsApp, ' . (int)($a['email'] ?? 0) . ' by e-mail)';
        }
        if ($out !== [] && !empty($r['messages'])) return $out;
        switch ($r['outcome'] ?? '') {
            case 'recorded':  return array_merge(["Job #{$jobId} (" . ($r['event'] ?? '') . ") — recorded, no message: {$r['detail']}"], $out);
            case 'no_change': return array_merge(["Job #{$jobId} — no new assignment, time or cancellation: nothing to send"], $out);
            default:          return array_merge(["Job #{$jobId} — could not be checked with uCRM: {$r['detail']}"], $out);
        }
    }

    /**
     * The e-mail's part of a log line. Worded so that WA Events files the line by its WhatsApp alone, and its badge
     * counts only WhatsApp: none of the words their rules look for ("sent", "failed", "skipped", "error", …) is here.
     */
    private static function emailClause(?string $email): string
    {
        switch ($email) {
            case 'sent':           return '; e-mail handed to the mail server';
            case 'failed':         return '; e-mail not taken by the mail server';
            case 'no_email':       return '; no e-mail: the staff account has no usable address';
            case 'not_configured': return '; no e-mail: the plugin has no mail server set up';
            default:               return '';
        }
    }

    private static function label(string $event, string $message): string
    {
        switch ($message) {
            case 'assigned':        return $event === 'reassigned' ? 'reassigned: new engineer' : 'assigned';
            case 'reassigned_away': return 'reassigned: previous engineer';
            case 'new_time':        return 'rescheduled';
            case 'removed':         return 'unassigned';
            case 'cancelled':       return 'cancelled';
            case 'accepted':        return 'accepted: completion link';
            default:                return $event;
        }
    }

    /** For the person who pressed the button: what happened to this job's WhatsApp message, then to its e-mail copy. */
    public static function note(array $r): string
    {
        return self::waNote($r) . self::emailNote($r['messages'][0]['email'] ?? null);
    }

    private static function emailNote(?string $email): string
    {
        switch ($email) {
            case 'sent':           return ' The same message went to the engineer\'s e-mail.';
            case 'failed':         return ' The e-mail copy was not taken by the mail server.';
            case 'no_email':       return ' No e-mail copy: the engineer\'s staff account has no usable e-mail address.';
            case 'not_configured': return ' No e-mail copy: no mail server is set up for the plugin.';
            default:               return '';
        }
    }

    private static function waNote(array $r): string
    {
        $msg = (string)($r['messages'][0]['message'] ?? '');
        switch ($r['outcome'] ?? '') {
            case 'sent':
                if ($msg === 'new_time') return 'WhatsApp with the new time sent to the engineer on the job.';
                if ($msg === 'accepted') return 'WhatsApp with the completion link sent to the engineer on the job.';
                return 'WhatsApp sent to the engineer, with the link to accept the job.';
            case 'failed':                  return 'The WhatsApp message to the engineer failed. WA Events and the failure queue show it.';
            case 'no_staff_account':        return 'No WhatsApp was sent: no active staff account is linked to the job\'s uCRM user.';
            case 'ambiguous_staff_account': return 'No WhatsApp was sent: more than one staff account is linked to the job\'s uCRM user.';
            case 'no_usable_number':        return 'No WhatsApp was sent: the engineer\'s staff account has no usable phone number.';
            case 'sending':                 return 'uCRM\'s own notice of this change is sending the WhatsApp message. WA Events shows the result.';
            case 'recorded':                return 'No WhatsApp was sent: ' . ($r['detail'] ?? '') . '.';
            case 'no_change':
                $why = (string)($r['detail'] ?? '');
                if ($why !== '' && $why !== 'nothing to send') return 'No WhatsApp was sent: ' . $why . '.';
                if (($r['assignee'] ?? null) === null) return 'No WhatsApp was sent: nobody is assigned to the job.';
                return 'No WhatsApp was sent: nothing the engineer needs to hear about changed.';
            default:                        return 'No WhatsApp was sent: the job could not be read back from uCRM. Its next change catches up.';
        }
    }

    /** Several jobs' notes for one answer, keyed by job id: one sentence per distinct outcome, naming its jobs. */
    public static function notes(array $byJob): string
    {
        if ($byJob === []) return '';
        if (count($byJob) === 1) return self::note(reset($byJob));
        $groups = [];
        foreach ($byJob as $jobId => $r) $groups[self::note($r)][] = '#' . (int)$jobId;
        if (count($groups) === 1) return 'All ' . count($byJob) . ' jobs: ' . array_key_first($groups);
        $parts = [];
        foreach ($groups as $sentence => $ids) $parts[] = (count($ids) === 1 ? 'Job ' : 'Jobs ') . implode(', ', $ids) . ': ' . $sentence;
        return implode(' ', $parts);
    }

    /** sent when every job's message went, not_sent when none did, partly_sent otherwise. */
    public static function summary(array $byJob): string
    {
        $n  = count($byJob);
        $ok = count(array_filter($byJob, function ($r) { return in_array($r['outcome'] ?? '', ['sent', 'sending'], true); }));
        return ($n > 0 && $ok === $n) ? 'sent' : ($ok === 0 ? 'not_sent' : 'partly_sent');
    }
}
