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

    private $crm;
    private $store;
    private $notify;
    private $config;
    private $tenant;
    private $tz;

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

        // ── The messages, after the commit: never while the lock is held ────
        $assignee = $now['assignee'] ?? null;
        if ($plan['event'] === null) return self::result(null, 'no_change', 'nothing to send', $assignee);
        if ($plan['messages'] === []) {
            $this->event($jobId, $plan['event'], null, $plan, $source, null, 'recorded', $plan['why']);
            return self::result($plan['event'], 'recorded', $plan['why'], $assignee);
        }
        $client = null; $sent = [];
        foreach ($plan['messages'] as $m) {
            if ($m['kind'] === 'assigned' && $client === null) $client = $this->client((int)($job['clientId'] ?? 0));
            $out = $this->deliver((int)$m['to'], $m['kind'], $this->fields($jobId, $m, (array)$job, $client ?? []));
            $this->event($jobId, $plan['event'], $m['kind'], $plan, $source, $out['staff_id'], $out['outcome'], $out['detail']);
            $sent[] = ['message' => $m['kind'], 'outcome' => $out['outcome'], 'detail' => $out['detail'], 'staff_id' => $out['staff_id']];
        }
        return self::result($plan['event'], $sent[0]['outcome'], $sent[0]['detail'], $assignee, $sent);
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
        $this->event($jobId, 'accepted', 'accepted', $plan, 'accept', $out['staff_id'], $out['outcome'], $out['detail']);
        return self::result('accepted', $out['outcome'], $out['detail'], $assignee,
            [['message' => 'accepted', 'outcome' => $out['outcome'], 'detail' => $out['detail'], 'staff_id' => $out['staff_id']]]);
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
            $st = $this->store->getPdo()->prepare('SELECT event, message, outcome, detail, staff_id, to_assignee_id, source
                FROM job_notify_events WHERE job_id = ? AND message = ? AND id > ? ORDER BY id DESC LIMIT 1');
            $st->execute([$jobId, $message, $afterId]);
            $row = $st->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_array($row)) return null;
        $staff = $row['staff_id'] === null ? null : (int)$row['staff_id'];
        $to    = $row['to_assignee_id'] === null ? null : (int)$row['to_assignee_id'];
        return self::result((string)$row['event'], (string)$row['outcome'], (string)$row['detail'], $to,
            [['message' => (string)$row['message'], 'outcome' => (string)$row['outcome'], 'detail' => (string)$row['detail'], 'staff_id' => $staff]])
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

    /** The staff account behind a uCRM user, then the message to its number. */
    private function deliver(int $ucrmUserId, string $kind, array $fields): array
    {
        $hits = StaffDirectory::byUcrmUser((array)($this->store->load('retailers.json') ?? []), $ucrmUserId);
        if (count($hits) === 0) {
            return ['outcome' => 'no_staff_account', 'staff_id' => null, 'detail' => "no staff account is linked to uCRM user #{$ucrmUserId}"];
        }
        if (count($hits) > 1) {
            return ['outcome' => 'ambiguous_staff_account', 'staff_id' => null, 'detail' => count($hits) . " staff accounts are linked to uCRM user #{$ucrmUserId}"];
        }
        $row = $hits[0];
        $id  = (int)($row['id'] ?? 0);
        $phone = StaffDirectory::phoneOf($row, $this->tenant);
        if ($phone === null) return ['outcome' => 'no_usable_number', 'staff_id' => $id, 'detail' => "staff account #{$id} has no usable number"];
        $fields['name'] = trim((string)($row['name'] ?? ''));
        switch ($kind) {
            case 'assigned':        $text = JobMessages::assigned($fields);       $log = self::LOG_ASSIGNED;    break;
            case 'accepted':        $text = JobMessages::accepted($fields);       $log = self::LOG_ACCEPTED;    break;
            case 'reassigned_away': $text = JobMessages::reassignedAway($fields); $log = self::LOG_UNASSIGNED;  break;
            case 'removed':         $text = JobMessages::removed($fields);        $log = self::LOG_UNASSIGNED;  break;
            case 'new_time':        $text = JobMessages::newTime($fields);        $log = self::LOG_RESCHEDULED; break;
            case 'cancelled':       $text = JobMessages::cancelled($fields);      $log = self::LOG_CANCELLED;   break;
            default: return ['outcome' => 'failed', 'staff_id' => $id, 'detail' => "staff account #{$id}: no such message"];
        }
        $this->notify->sendVia('support', $phone, $text, $log, [], ContactOptOut::CLASS_STAFF);
        $r = $this->notify->lastSendResult();
        if (!empty($r['success'])) return ['outcome' => 'sent', 'staff_id' => $id, 'detail' => "staff account #{$id}"];
        $why = ($r['http_code'] === null && $r['error'] === null) ? ' (no WhatsApp transport took it)' : '';
        return ['outcome' => 'failed', 'staff_id' => $id, 'detail' => "staff account #{$id}{$why}"];
    }

    private function event(int $jobId, string $event, ?string $message, array $plan, string $source, ?int $staffId, string $outcome, string $detail): void
    {
        try {
            $this->store->getPdo()->prepare('INSERT INTO job_notify_events
                    (job_id, event, message, from_assignee_id, to_assignee_id, from_time, to_time, source, staff_id, outcome, detail)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$jobId, $event, $message, $plan['from'], $plan['to'], $plan['from_time'], $plan['to_time'],
                           $source, $staffId, $outcome, $detail]);
        } catch (\Throwable $e) {
            // The Message Log still holds the send; a missing history row must not undo it.
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
                case 'sent':   $out[] = "Job #{$jobId} ({$what}) — WhatsApp sent to {$m['detail']}"; break;
                case 'failed': $out[] = "Job #{$jobId} ({$what}) — WhatsApp failed for {$m['detail']}"; break;
                default:       $out[] = "Job #{$jobId} ({$what}) — WhatsApp skipped: {$m['detail']}";
            }
        }
        if ($out !== []) return $out;
        switch ($r['outcome'] ?? '') {
            case 'recorded':  return ["Job #{$jobId} (" . ($r['event'] ?? '') . ") — recorded, no message: {$r['detail']}"];
            case 'no_change': return ["Job #{$jobId} — no new assignment, time or cancellation: nothing to send"];
            default:          return ["Job #{$jobId} — could not be checked with uCRM: {$r['detail']}"];
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

    /** One sentence for the person who pressed the button: what happened to this job's WhatsApp message. */
    public static function note(array $r): string
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
