<?php
declare(strict_types=1);
/**
 * staff_jobs_scenario.php — TEST ONLY. One day of job traffic, run the same way against any plugin tree, so that
 * 5.18.50 can be compared with 5.18.49 message for message (docs/44 M6 and D2, release A).
 *
 * The baseline is 5.18.49 itself, taken from Git (commit e076632) rather than described: a comparison against a copy
 * of what the test author believes 5.18.49 did would only prove the author consistent. The fakes are this
 * repository's, copied beside the old tree, so both sides run against the same uCRM, WhatsApp and SMTP.
 *
 * The day, in order, as the same accounts on both trees:
 *   1. the admin creates a job in ＋ New Job, "Notify via WhatsApp" ticked      — a job-assignment path (M6)
 *   2. the support leader sends a Bulk Dispatch batch                            — a job-assignment path (M6)
 *   3. the technician reschedules a job                                         — a job-assignment path (M6)
 *   4. uCRM reports a job created in uCRM (job.add), an installation with a date — a job-assignment path (M6),
 *      and the customer's "installation scheduled" e-mail
 *   5. the technician accepts a job                                             — accept messages (unchanged)
 *   6. and 7. ticks its two tasks                                               — task-progress messages (unchanged)
 *   8. completes it: an installation, so the accountant is asked for an invoice — completion messages (unchanged)
 *
 * Every person, number, e-mail and job is fictitious; nothing leaves the machine.
 */
require_once __DIR__ . '/staff_jobs_sandbox.php';

/** 5.18.49, the release 5.18.50 replaced — South Sudan's reference for release A. */
const SJ_BASELINE = 'e076632';

/** 5.18.51, the release 5.18.52 replaces (docs/44 §16.13, §16.14): the plugin commit installed before release B. */
const SJ_BASELINE_51 = '240f2f9';

/** The events a job-assignment message is logged under, in 5.18.49: the four paths M6 switches off on Uganda. */
const SJ_ASSIGNMENT_EVENTS = ['ops_scheduling_job_assigned', 'ops_scheduling_rescheduled', 'job_assigned'];

/**
 * A released plugin tree, from Git — 5.18.49 unless another commit is named — with this repository's fakes beside it:
 * [dir, reason]. dir is null when Git cannot supply it (no git, or a checkout without that commit) — a caller reports
 * that as a skip, never as a pass.
 */
function sj_baseline_tree(string $commit = SJ_BASELINE): array
{
    $tmp = sys_get_temp_dir() . '/sj-base-' . getmypid() . '-' . bin2hex(random_bytes(3));
    mkdir($tmp, 0700, true);
    // From the top of the repository: run inside a subdirectory, git archive keeps only that subdirectory.
    $top = trim((string)shell_exec('git -C ' . escapeshellarg(__DIR__) . ' rev-parse --show-toplevel 2>/dev/null'));
    $out = [];
    if ($top !== '') {
        exec('git -C ' . escapeshellarg($top) . ' archive --prefix=plugin/ ' . escapeshellarg($commit . ':dishnet-hybrid-sudan')
            . ' | tar -x -C ' . escapeshellarg($tmp) . ' 2>&1', $out, $rc);
    }
    $dir = $tmp . '/plugin';
    if (!is_file($dir . '/manifest.json')) {
        exec('rm -rf ' . escapeshellarg($tmp));
        return [null, 'git could not supply ' . $commit . ': ' . trim(implode(' ', $out))];
    }
    @mkdir($dir . '/tests/fixtures', 0700, true);
    foreach (['fake_ucrm_staff_jobs.php', 'fake_evo_server.php', 'fake_smtp_server.php', 'staff_jobs_sandbox.php'] as $f) {
        copy(__DIR__ . '/' . $f, $dir . '/tests/fixtures/' . $f);
    }
    register_shutdown_function(function () use ($tmp) { exec('rm -rf ' . escapeshellarg($tmp)); });
    return [$dir, ''];
}

final class SjScenario
{
    public const NOTE = 'No WhatsApp was sent: job notifications are not switched on yet. The engineer sees the job in My Jobs.';

    public static function users(): array
    {
        return [
            '1000' => ['id' => 1000, 'username' => 'sb-admin', 'firstName' => 'Sandbox', 'lastName' => 'Admin', 'email' => 'admin@example.test', 'isActive' => true],
            '1099' => ['id' => 1099, 'username' => 'sb-tech', 'firstName' => 'Sandbox', 'lastName' => 'Tech', 'email' => 'tech@example.test',
                       'phone' => '+256700000111', 'isActive' => true],
        ];
    }

    public static function crm(): array
    {
        $job = function (int $id, string $title, string $date, array $extra = []): array {
            return array_merge(['id' => $id, 'title' => $title, 'description' => '', 'clientId' => 15,
                'client' => ['id' => 15, 'firstName' => 'Sandbox', 'lastName' => 'Customer'], 'assignedUserId' => 1099,
                'date' => $date, 'duration' => 60, 'status' => 0, 'address' => 'Plot 9 Sandbox Road, Kampala'], $extra);
        };
        return [
            'users'   => self::users(),
            'clients' => ['15' => ['id' => 15, 'firstName' => 'Sandbox', 'lastName' => 'Customer', 'street1' => 'Plot 9 Sandbox Road',
                                   'street2' => 'Kampala', 'city' => 'Kampala', 'note' => '', 'isLead' => false,
                                   'contacts' => [['email' => 'customer@example.test', 'phone' => '+256700000915', 'isBilling' => true]]]],
            'jobs'    => [
                '901' => $job(901, 'Fiber installation', '2026-10-05T09:00:00+0300'),
                '905' => $job(905, 'Router check', '2026-10-06T10:00:00+0300'),
                '911' => $job(911, 'Starlink installation', '2026-10-05T09:00:00+0300',
                              ['timeFrom' => '2026-10-05T09:00:00+0300', 'timeTo' => '2026-10-05T12:00:00+0300']),
            ],
            'tasks'   => [['id' => 7001, 'jobId' => 901, 'label' => 'Mount the dish', 'closed' => false],
                          ['id' => 7002, 'jobId' => 901, 'label' => 'Test the link', 'closed' => false]],
        ];
    }

    /** The accounts, identical on both trees. The technician's uCRM link is verified; 5.18.49 reads its id alone. */
    /** A real 32×32 JPEG (947 bytes), for the three site photos the Uganda day takes before completing (5.18.66). */
    public const JPEG_B64 = '/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBxdWFsaXR5ID0gNjAK/9sAQwANCQoLCggNCwoLDg4NDxMgFRMSEhMnHB4XIC4pMTAuKS0sMzpKPjM2RjcsLUBXQUZMTlJTUjI+WmFaUGBKUVJP/9sAQwEODg4TERMmFRUmTzUtNU9PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09P/8AAEQgAIAAgAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8AzadFFJPII4Y3kduiopJP4CiGJ55khiXc8jBVGcZJ4FelaTpcGlWvkwjc7cySEcuf8PQV69asqS8zzKVJ1H5Hm89vPbOEuIZImIyFdSpx681HXqV/ZQahatb3KbkbkEdVPqPevNdQtHsL6a1kOTG2M+o6g/iMGlQrqrp1HVounr0I7aZra5inQAtE4cA9Mg5r06wvYNQtVuLZ9yNwQeqn0PvXltWLK/u7CQyWk7xE9QOh+oPB60V6HtVpuFGr7N67Hp1xPFawPPcOEjQZZj2rzTVbz+0NTnugu0SN8oxj5QMDPvgCi+1O+1DH2u4eQL0XgL35wOM8nmqlLD4f2er3HWre00Wx/9k=';

    public static function staff(SjSandbox $s): void
    {
        $link = function (int $id, string $email): array {
            return ['ucrm_user_id' => $id, 'ucrm_link' => ['user_id' => $id, 'email' => $email, 'verified_at' => '2026-09-27T00:00:00Z', 'verified_by' => 1]];
        };
        $s->staff('admin', ['name' => 'Sandbox Admin', 'email' => 'admin@example.test', 'role' => 'admin', 'is_admin' => true, 'phone' => '+256700000110'] + $link(1000, 'admin@example.test'));
        $s->staff('lead',  ['name' => 'Sandbox Leader', 'email' => 'lead@example.test', 'role' => 'support_leader', 'phone' => '+256700000112']);
        $s->staff('tech',  ['name' => 'Sandbox Tech', 'email' => 'tech@example.test', 'role' => 'support', 'phone' => '+256700000111'] + $link(1099, 'tech@example.test'));
        $s->staff('acct',  ['name' => 'Sandbox Accountant', 'email' => 'acct@example.test', 'role' => 'accountant', 'phone' => '+256700000113']);
    }

    /**
     * The day, on the plugin tree at $root, configured by $cfg. $extra($s) runs after the day, before the servers stop,
     * for a caller's own captures. Returns what was said and done, in order.
     */
    public static function run(string $root, array $cfg, string $tag, ?callable $extra = null): array
    {
        // The zone comes from the caller: a test file may pin one, a fixture may not (tests/test_timezone.php).
        if (!isset($cfg['timezone']) || !is_string($cfg['timezone']) || $cfg['timezone'] === '') {
            throw new InvalidArgumentException('SjScenario::run(): the caller names the timezone');
        }
        $cfg += ['whatsapp_admin_phone' => '+256700000100',
                 'customer_emails_enabled' => '1', 'customer_email_install_scheduled' => '1',
                 'email_company_name' => 'DishNet Sandbox Limited', 'email_locality' => 'Kampala, Uganda',
                 'email_support_phone' => '+256 700 000 100', 'email_reply_to' => 'support@example.test', 'email_website' => 'example.test'];
        $s = SjSandbox::start($root, $cfg, $tag);
        [, $transcript] = $s->mailRelay();   // for the customer's e-mail, and on Uganda since 5.18.52 the engineer's
        $s->seedCrm(self::crm());
        self::staff($s);

        $a = [];
        $a['create']     = $s->api('admin', 'POST', 'create_job', ['title' => 'Sandbox installation', 'date' => '2026-10-07', 'time' => '09:00',
                                'engineer_ids' => [1099], 'crm_client_id' => 15, 'tasks' => ['Mount the dish'], 'notify_wa' => 1]);
        $a['bulk']       = $s->api('lead', 'POST', 'bulk_create_jobs', ['job_title' => 'Sandbox batch', 'job_date' => '2026-10-09', 'job_time' => '09:00',
                                'tasks' => ['Splice'], 'customers' => [['crm_id' => 15, 'assignee_id' => 1099]]]);
        $a['reschedule'] = $s->api('tech', 'POST', 'scheduling_reschedule', ['job_id' => 905, 'new_date' => '2026-10-08 11:00', 'comment' => 'Customer asked']);
        $a['webhook']    = $s->fire('job.add', 'job', 911, 'sj-scenario-911');
        $a['accept']     = $s->api('tech', 'POST', 'scheduling_job_update', ['job_id' => 901, 'status' => 'open', 'notify_accept' => 1]);
        $a['tick1']      = $s->api('tech', 'POST', 'scheduling_task_update', ['task_id' => 7001, 'job_id' => 901, 'done' => true]);
        $a['tick2']      = $s->api('tech', 'POST', 'scheduling_task_update', ['task_id' => 7002, 'job_id' => 901, 'done' => true]);
        if (($cfg['tenant_profile'] ?? '') === 'uganda') {
            // 5.18.66: on Uganda an installation is completed with its kit, cable and router photos and the technician's
            // location — here the reason there is no fix, so every message stays 5.18.51's byte for byte and uCRM
            // receives the same writes (tests/test_job_photos.php proves the fix itself). South Sudan's day is unchanged.
            foreach (['kit', 'cable', 'model'] as $label) {
                $a['photo_' . $label] = $s->upload('tech', 'job_photo_upload', ['job_id' => '901', 'label' => $label], 'photo', base64_decode(self::JPEG_B64), 'photo.jpg');
            }
            $a['complete'] = $s->api('tech', 'POST', 'scheduling_complete', ['job_id' => 901, 'comment' => 'Sandbox done', 'gps_missing_reason' => 'Sandbox: no GPS fix']);
        } else {
            $a['complete'] = $s->api('tech', 'POST', 'scheduling_complete', ['job_id' => 901, 'comment' => 'Sandbox done']);
        }

        // The sandbox's own addresses — its ports are chosen per run — are the same place on both trees.
        $here = function (string $x) use ($s): string {
            return str_replace([$s->crm, $s->evo, preg_replace('#/public\.php$#', '', $s->base)], ['<crm>', '<evo>', '<plugin>'], $x);
        };
        $out = [
            'answers' => array_map(function ($r) { return [$r[0], $r[2]]; }, $a),
            'texts'   => array_map(function ($t) use ($here) { return ['number' => $t['number'], 'text' => self::norm($here($t['text']))]; }, $s->texts()),
            'log'     => [],
            'whlog'   => [],
            'mail'    => [],
            'crm'     => array_values(array_filter($s->crmDump()['requests'] ?? [], function ($r) { return strpos((string)$r['path'], '/__test') !== 0; })),
            'extra'   => null,
        ];
        try {
            foreach ($s->q('SELECT event, phone, preview, success FROM notification_audit_log ORDER BY id') as $r) {
                $out['log'][] = ['event' => (string)$r['event'], 'phone' => (string)$r['phone'], 'preview' => self::norm($here((string)$r['preview'])), 'success' => (int)$r['success']];
            }
        } catch (\Throwable $e) { /* no send at all: no table */ }
        foreach (array_reverse(json_decode($s->webhookLog(), true) ?: []) as $l) {
            $out['whlog'][] = ['event' => (string)($l['event'] ?? ''), 'message' => $here((string)($l['message'] ?? ''))];
        }
        foreach (json_decode((string)@file_get_contents($transcript), true) ?: [] as $m) {
            if (trim((string)($m['data'] ?? '')) === '') continue;
            $out['mail'][] = ['from' => $m['mail_from'], 'to' => $m['rcpt_to'], 'data' => self::normMail($here((string)$m['data']))];
        }
        if ($extra) $out['extra'] = $extra($s);
        $s->stop();
        return $out;
    }

    /**
     * The only thing that differs between two runs of the same day: the clock, in the completion message ("Completed:")
     * and the accountant's ("Date:"). A date the day chose — a rescheduled visit, say — is left as it is.
     */
    public static function norm(string $t): string
    {
        return (string)preg_replace('/\b(Completed|Date): \d{2} [A-Z][a-z]{2} \d{4}, \d{2}:\d{2} [ap]m\b/', '$1: <now>', $t);
    }

    /** A message's date, id and MIME boundaries are its own on every send. */
    public static function normMail(string $d): string
    {
        $d = str_replace("\r\n", "\n", $d);
        if (preg_match_all('/boundary="?([^";\s]+)"?/i', $d, $m)) {
            foreach (array_unique($m[1]) as $b) $d = str_replace($b, '<boundary>', $d);
        }
        $d = (string)preg_replace('/^(Date|Message-I[dD]):.*$/m', '$1: <x>', $d);
        return (string)preg_replace('/\b\d{2} [A-Z][a-z]{2} \d{4}, \d{2}:\d{2}\b/', '<now>', $d);
    }

    /** True when the message is one of the four job-assignment messages of 5.18.49. */
    public static function isAssignment(array $t): bool
    {
        return strpos($t['text'], 'New Job Assigned') !== false || preg_match('/\*Job #\d+ Rescheduled\*/', $t['text']) === 1;
    }
}
