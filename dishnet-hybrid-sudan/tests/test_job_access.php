<?php
declare(strict_types=1);
/**
 * test_job_access.php — 5.18.50 (docs/44 J6, D7 and M7, release A): on Uganda, who may create a job and who may act on
 * one is decided on the server, from the account as stored now and the job as uCRM holds it.
 *
 * Before 5.18.50 every job action checked nothing, or had an empty "ownership" block, and My Jobs matched a job to an
 * account through any stored uCRM id — or through ftth_crm_client_id when there was none. Proved here, through the
 * real API, against a fake uCRM and the repository's fake WhatsApp server:
 *   1. My Jobs: a verified link gets its own jobs, live and from the cache; an old stored id, a link whose e-mail no
 *      longer matches, and ftth_crm_client_id get "needs mapping" — the South Sudan control shows the fallbacks those
 *      replaced still working there;
 *   2. the matrix (T6.1): every account type against detail, status, task, complete, reschedule, comment, signature,
 *      survey and reading a survey, then against create and the two engineer lists — each refusal a 403 with no job
 *      data in it and nothing written, to uCRM, to the store or to WhatsApp;
 *   3. M7: the stored id alone, even one equal to the job's assignee, opens nothing; the link does, and only while its
 *      e-mail is the account's own;
 *   4. a job with no assignee, and an answer with no assignee field (V4), are a leader's or an admin's only;
 *   5. T6.2: a task of another job, sent with this job's id, is refused, and a task needs its job;
 *   6. every engineer chosen for ＋ New Job or Bulk Dispatch has a verified link — checked before the first job is made;
 *   7. a job is completed by its assignee;
 *   8. an account deactivated or demoted while its web session is open is stopped at its next click;
 *   9. weakened copies of the code each fail this test.
 *
 *   php test_job_access.php [--root=DIR] [--no-mutants]
 */
$opt  = getopt('', ['root:', 'no-mutants']);
$root = isset($opt['root']) ? rtrim((string)$opt['root'], '/') : dirname(__DIR__);
$withMutants = !isset($opt['no-mutants']);
require_once __DIR__ . '/fixtures/staff_jobs_sandbox.php';

$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; }
    else    { $fail++; echo "  FAIL {$m}" . ($d !== '' ? "\n       {$d}" : '') . "\n"; }
}

// The refusals, word for word: a changed message is a changed answer.
const NOT_YOURS   = 'This job is not assigned to you.';
const NO_ASSIGNEE = 'This job\'s assignee could not be read from uCRM, so only a support leader or an admin may act on it.';
const INACTIVE    = 'Your account is not active.';
const NO_CREATE   = 'Your role cannot create jobs.';

$link = function (int $id, string $email): array {
    return ['ucrm_user_id' => $id, 'ucrm_link' => ['user_id' => $id, 'email' => $email, 'verified_at' => '2026-09-27T00:00:00Z', 'verified_by' => 1]];
};
$users = [
    '1000' => ['id' => 1000, 'username' => 'sb-admin', 'email' => 'admin@example.test', 'isActive' => true],
    '1099' => ['id' => 1099, 'username' => 'sb-tech',  'email' => 'tech@example.test',  'isActive' => true],
    '1100' => ['id' => 1100, 'username' => 'sb-other', 'email' => 'other@example.test', 'isActive' => true],
    '1101' => ['id' => 1101, 'username' => 'sb-eng',   'email' => 'eng@example.test',   'isActive' => true],
    '1105' => ['id' => 1105, 'username' => 'sb-old',   'email' => 'old@example.test',   'isActive' => true],
];
// Canary values: none of them may appear in any refusal.
$clients = ['15' => ['id' => 15, 'firstName' => 'Canary', 'lastName' => 'Customer', 'street1' => 'Plot 9 Canary Road',
                     'street2' => 'Kampala', 'city' => 'Kampala', 'note' => 'CANARY-CLIENT-NOTE', 'isLead' => false,
                     'contacts' => [['phone' => '+256700000915', 'email' => 'canary-client@example.test']]]];
$job = function (int $id, string $title, $assignee, string $date) {
    $j = ['id' => $id, 'title' => $title, 'description' => 'CANARY-DESCRIPTION', 'clientId' => 15,
          'client' => ['id' => 15, 'firstName' => 'Canary', 'lastName' => 'Customer'],
          'date' => $date, 'duration' => 60, 'status' => 0, 'address' => 'Plot 9 Canary Road', 'gpsLat' => null, 'gpsLon' => null];
    if ($assignee !== 'absent') $j['assignedUserId'] = $assignee;
    return $j;
};
$jobs = [
    '901' => $job(901, 'CANARY-JOB-901 Router replacement', 1099, '2026-10-05T09:00:00+0300'),
    '902' => $job(902, 'CANARY-JOB-902 Dish realignment', 1100, '2026-10-05T11:00:00+0300'),
    '903' => $job(903, 'CANARY-JOB-903 Not yet assigned', null, '2026-10-05T12:00:00+0300'),
    '904' => $job(904, 'CANARY-JOB-904 Answer without the field', 'absent', '2026-10-05T13:00:00+0300'),
    '905' => $job(905, 'CANARY-JOB-905 Site check', 1099, '2026-10-06T10:00:00+0300'),
];
$tasks = [['id' => 7001, 'jobId' => 901, 'label' => 'Mount the dish', 'closed' => false],
          ['id' => 7002, 'jobId' => 901, 'label' => 'Test the link', 'closed' => false],
          ['id' => 7003, 'jobId' => 902, 'label' => 'Realign', 'closed' => false]];
$TASK_OF = [901 => 7001, 902 => 7003, 903 => 7001, 904 => 7001, 905 => 7001];

$accounts = function (SjSandbox $s) use ($link): void {
    $s->staff('admin',    ['name' => 'Sandbox Admin', 'email' => 'admin@example.test', 'role' => 'admin', 'is_admin' => true] + $link(1000, 'admin@example.test'));
    $s->staff('lead',     ['name' => 'Sandbox Leader', 'email' => 'lead@example.test', 'role' => 'support_leader']);
    $s->staff('tech',     ['name' => 'Sandbox Tech', 'email' => 'tech@example.test', 'role' => 'support'] + $link(1099, 'tech@example.test'));
    $s->staff('other',    ['name' => 'Sandbox Other', 'email' => 'other@example.test', 'role' => 'support'] + $link(1100, 'other@example.test'));
    $s->staff('eng',      ['name' => 'Sandbox Engineer', 'email' => 'eng@example.test', 'role' => 'support_engineer'] + $link(1101, 'eng@example.test'));
    $s->staff('acct',     ['name' => 'Sandbox Accountant', 'email' => 'acct@example.test', 'role' => 'accountant']);
    $s->staff('facct',    ['name' => 'Sandbox Field Accountant', 'email' => 'facct@example.test', 'role' => 'field_accountant']);
    $s->staff('sales',    ['name' => 'Sandbox Sales', 'email' => 'sales@example.test', 'role' => 'sales']);
    $s->staff('retailer', ['name' => 'Sandbox Retailer', 'email' => 'retailer@example.test', 'role' => 'retailer']);
    $s->staff('agent',    ['name' => 'Sandbox Agent', 'email' => 'agent@example.test', 'role' => 'field_agent']);
    $s->staff('coll',     ['name' => 'Sandbox Collector', 'email' => 'coll@example.test', 'role' => 'collection']);
    // M7: the stored id is exactly the job's assignee, but it was not saved through the verified picker.
    $s->staff('stale',    ['name' => 'Sandbox Stale', 'email' => 'stale@example.test', 'role' => 'support', 'ucrm_user_id' => 1099]);
    // A link made for another e-mail: the account's e-mail changed after it was verified.
    $s->staff('moved',    ['name' => 'Sandbox Moved', 'email' => 'moved-now@example.test', 'role' => 'support'] + $link(1099, 'moved-then@example.test'));
    // The fallback 5.18.49 used when there was no uCRM user id at all.
    $s->staff('ftth',     ['name' => 'Sandbox FTTH', 'email' => 'ftth@example.test', 'role' => 'support', 'ftth_crm_client_id' => 1099]);
    // Its stored id and e-mail both match uCRM user 1105 — still not verified through the picker.
    $s->staff('old',      ['name' => 'Sandbox Old', 'email' => 'old@example.test', 'role' => 'support', 'ucrm_user_id' => 1105]);
    $s->staff('gone',     ['name' => 'Sandbox Gone', 'email' => 'gone@example.test', 'role' => 'support_leader']);
    $s->staff('demoted',  ['name' => 'Sandbox Demoted', 'email' => 'demoted@example.test', 'role' => 'support_leader']);
};

// ── Helpers ──────────────────────────────────────────────────────────────────
$clean = function (string $body): bool {
    foreach (['canary', 'plot 9', '700000915', 'assigneduserid', 'sandbox survey note', 'sandbox signer', 'data:image'] as $c) {
        if (stripos($body, $c) !== false) return false;
    }
    return true;
};
/** Everything a job action could change: uCRM writes, the four job stores, and WhatsApp sends. */
$snap = function (SjSandbox $s): array {
    $w = array_values(array_filter($s->crmDump()['requests'] ?? [], function ($r) { return $r['method'] !== 'GET'; }));
    $st = [];
    foreach (['job_completions', 'job_signatures', 'site_surveys', 'job_invoice_queue'] as $t) {
        try { $st[$t] = md5(json_encode($s->q("SELECT * FROM [{$t}]"))); } catch (\Throwable $e) { $st[$t] = 'absent'; }
    }
    return ['uCRM writes' => count($w), 'store' => $st, 'WhatsApp' => count($s->texts())];
};
$msg  = function (array $r): string { return (string)($r[2]['message'] ?? ''); };
$ids  = function (array $r): array {
    $j = $r[2]['data']['jobs'] ?? null;
    return is_array($j) ? array_map(function ($x) { return (int)($x['id'] ?? 0); }, $j) : [];
};

// ═════════════════════════════════════════════════════════════════════════════
$s = SjSandbox::start($root, ['tenant_profile' => 'uganda', 'timezone' => 'Africa/Kampala'], 'sjaccess');
$s->seedCrm(['users' => $users, 'clients' => $clients, 'jobs' => $jobs, 'tasks' => $tasks]);
$accounts($s);

// ── 1. My Jobs ───────────────────────────────────────────────────────────────
echo "\n1. My Jobs: the verified link, and nothing else\n";
$r = $s->api('tech', 'GET', 'scheduling_jobs', null, '&refresh=1');
is_($r[0] === 200 && $ids($r) === [901, 905] && ($r[2]['data']['needs_mapping'] ?? null) === false && ($r[2]['data']['from_cache'] ?? null) === false,
    'a verified link: its own two jobs, read live from uCRM', $r[0] . ' ' . json_encode($ids($r)));
$r = $s->api('tech', 'GET', 'scheduling_jobs');
is_($r[0] === 200 && $ids($r) === [901, 905] && ($r[2]['data']['from_cache'] ?? null) === true,
    'and the same two from the cache that read warmed', $r[0] . ' ' . json_encode($ids($r)) . ' from_cache=' . json_encode($r[2]['data']['from_cache'] ?? null));
$r = $s->api('other', 'GET', 'scheduling_jobs');
is_($r[0] === 200 && $ids($r) === [902], 'another engineer: only the job assigned to them', json_encode($ids($r)));
foreach ([['stale', 'an id stored without the verified picker — even one equal to the jobs\' assignee'],
          ['moved', 'a link made for an e-mail the account no longer has'],
          ['ftth',  'ftth_crm_client_id, the fallback 5.18.49 used when there was no uCRM id']] as [$who, $label]) {
    foreach (['', '&refresh=1'] as $qs) {
        $r = $s->api($who, 'GET', 'scheduling_jobs', null, $qs);
        is_($r[0] === 200 && ($r[2]['data']['needs_mapping'] ?? null) === true && $ids($r) === [],
            "{$label}: \"needs mapping\", no job" . ($qs ? ' (live)' : ' (cache)'), $r[0] . ' ' . substr($r[1], 0, 160));
    }
}
$r = $s->api('admin', 'GET', 'scheduling_jobs', null, '&refresh=1');
is_($r[0] === 200 && $ids($r) === [] && ($r[2]['data']['needs_mapping'] ?? null) === false, 'an administrator linked to a user with no job: an empty list, not "needs mapping"', $r[1]);

echo "\n   South Sudan, unchanged: the fallbacks M7 replaced still work there\n";
$ss = SjSandbox::start($root, [], 'sjaccess-ss');
$ss->seedCrm(['users' => $users, 'clients' => $clients, 'jobs' => $jobs, 'tasks' => $tasks]);
$ss->staff('stale', ['name' => 'Sandbox Stale', 'email' => 'stale@example.test', 'role' => 'support', 'ucrm_user_id' => 1099]);
$ss->staff('ftth',  ['name' => 'Sandbox FTTH', 'email' => 'ftth@example.test', 'role' => 'support', 'ftth_crm_client_id' => 1099]);
foreach (['stale' => 'the stored id', 'ftth' => 'ftth_crm_client_id'] as $who => $label) {
    $r = $ss->api($who, 'GET', 'scheduling_jobs', null, '&refresh=1');
    is_($r[0] === 200 && $ids($r) === [901, 905], "South Sudan: {$label} still finds the two jobs (5.18.49's behaviour, D2)", $r[0] . ' ' . json_encode($ids($r)));
}
$r = $ss->api('stale', 'GET', 'scheduling_job_detail', null, '&job_id=902');
is_($r[0] === 200 && (int)($r[2]['data']['job']['id'] ?? 0) === 902, 'South Sudan: a job detail is still not checked (D2 — the same hole, left as it was)', (string)$r[0]);
$ss->stop();

// ── 2. The matrix ────────────────────────────────────────────────────────────
echo "\n2. The matrix: every account type against every action on job 901 (T6.1)\n";
$ACTS = [
    'detail'     => function (string $w, int $j) use ($s) { return $s->api($w, 'GET', 'scheduling_job_detail', null, "&job_id={$j}"); },
    'status'     => function (string $w, int $j) use ($s) { return $s->api($w, 'POST', 'scheduling_job_update', ['job_id' => $j, 'status' => 'open']); },
    'task'       => function (string $w, int $j) use ($s, $TASK_OF) { return $s->api($w, 'POST', 'scheduling_task_update', ['task_id' => $TASK_OF[$j], 'job_id' => $j, 'done' => false]); },
    'complete'   => function (string $w, int $j) use ($s) { return $s->api($w, 'POST', 'scheduling_complete', ['job_id' => $j, 'comment' => '']); },
    'reschedule' => function (string $w, int $j) use ($s) { return $s->api($w, 'POST', 'scheduling_reschedule', ['job_id' => $j, 'new_date' => '2026-10-08 11:00', 'comment' => '']); },
    'comment'    => function (string $w, int $j) use ($s) { return $s->api($w, 'POST', 'scheduling_add_comment', ['job_id' => $j, 'comment' => 'Sandbox comment']); },
    'signature'  => function (string $w, int $j) use ($s) { return $s->api($w, 'POST', 'save_job_signature', ['job_id' => $j, 'signature' => 'data:image/png;base64,U0FOREJPWA==', 'signer_name' => 'Sandbox Signer', 'crm_client_id' => 15]); },
    'survey'     => function (string $w, int $j) use ($s) { return $s->api($w, 'POST', 'save_survey_result', ['job_id' => $j, 'client_id' => 15, 'feasibility' => 'feasible', 'general_notes' => 'Sandbox survey note']); },
    'get_survey' => function (string $w, int $j) use ($s) { return $s->api($w, 'GET', 'get_survey', null, "&job_id={$j}"); },
];
/** What an allowed call answers. Completing job 901 stops at its two open tasks — past the access check. */
$allowedOk = function (string $act, array $r) use ($msg): bool {
    if ($act === 'complete') return $r[0] === 422 && strpos($msg($r), 'tasks still open') !== false;
    return $r[0] === 200;
};
$MAY_ACT = ['admin', 'lead', 'tech'];
$WHO = ['admin', 'lead', 'tech', 'other', 'eng', 'acct', 'facct', 'sales', 'retailer', 'agent', 'coll', 'stale', 'moved', 'ftth', 'old'];
$sawData = false;
foreach ($ACTS as $act => $call) {
    $okN = 0; $refN = 0; $bad = [];
    foreach ($WHO as $who) {
        $before = $snap($s);
        $r = $call($who, 901);
        $after = $snap($s);
        if (in_array($who, $MAY_ACT, true)) {
            if ($allowedOk($act, $r)) $okN++; else $bad[] = "{$who} was refused: {$r[0]} " . substr($r[1], 0, 120);
            if ($act === 'detail' && !$clean($r[1])) $sawData = true;
        } else {
            if ($r[0] === 403 && $msg($r) === NOT_YOURS && $clean($r[1]) && $after === $before) $refN++;
            else $bad[] = "{$who}: {$r[0]} " . substr($r[1], 0, 120) . ($after !== $before ? ' — and something was written: ' . json_encode([$before, $after]) : '');
        }
    }
    is_($bad === [] && $okN === count($MAY_ACT) && $refN === count($WHO) - count($MAY_ACT),
        sprintf('%-10s the admin, the leader and the assignee may; the %d others get 403 "%s", no job data, nothing written',
            $act, count($WHO) - count($MAY_ACT), NOT_YOURS), implode("\n       ", $bad));
}
is_($sawData, 'control: an allowed detail does carry the job and its customer — so the "no job data" check can see them');

// What each allowed action wrote, so the refusals above are measured against a real effect.
$d = $s->crmDump();
$count = function (string $m, string $re) use ($d): int {
    return count(array_filter($d['requests'] ?? [], function ($q) use ($m, $re) { return $q['method'] === $m && preg_match($re, (string)$q['path']); }));
};
is_($count('PATCH', '#^/scheduling/jobs/901$#') === 6 && $count('POST', '#^/scheduling/jobs/901/job-comments$#') === 6
    && $count('PATCH', '#^/scheduling/job-tasks/7001$#') === 3 && $count('POST', '#^/clients/15/client-logs$#') === 3,
    'control: the three allowed accounts\' writes reached uCRM (3 status + 3 reschedule PATCHes, 3 + 3 comments, 3 ticks, 3 signature logs)',
    json_encode(['job PATCH' => $count('PATCH', '#^/scheduling/jobs/901$#'), 'comments' => $count('POST', '#^/scheduling/jobs/901/job-comments$#'),
                 'task PATCH' => $count('PATCH', '#^/scheduling/job-tasks/7001$#'), 'client logs' => $count('POST', '#^/clients/15/client-logs$#')]));
$r = $s->api('tech', 'GET', 'get_survey', null, '&job_id=901');
is_($r[0] === 200 && ($r[2]['data']['survey']['general_notes'] ?? '') === 'Sandbox survey note', 'control: the saved survey is there for the assignee to read', substr($r[1], 0, 160));

echo "\n   Creating a job and the engineer lists: by role (D7)\n";
$MAY_CREATE = ['admin', 'lead', 'tech', 'other', 'eng', 'stale', 'moved', 'ftth', 'old'];
$CREATE_WHO = ['admin', 'lead', 'tech', 'other', 'eng', 'stale', 'moved', 'ftth', 'old', 'acct', 'facct', 'sales', 'retailer', 'agent', 'coll'];
$badC = []; $okC = 0; $refC = 0;
foreach ($CREATE_WHO as $who) {
    $n0 = count($s->crmReqs('POST', '#^/scheduling/jobs$#'));
    $r = $s->api($who, 'POST', 'create_job', ['title' => 'Sandbox job by ' . $who, 'date' => '2026-10-07', 'time' => '09:00', 'engineer_ids' => [1099], 'notify_wa' => 1]);
    $made = count($s->crmReqs('POST', '#^/scheduling/jobs$#')) - $n0;
    if (in_array($who, $MAY_CREATE, true)) {
        if ($r[0] === 200 && (int)($r[2]['data']['created'] ?? 0) === 1 && $made === 1) $okC++; else $badC[] = "{$who}: {$r[0]} made {$made} " . substr($r[1], 0, 120);
    } else {
        if ($r[0] === 403 && $msg($r) === NO_CREATE && $made === 0) $refC++; else $badC[] = "{$who}: {$r[0]} made {$made} " . substr($r[1], 0, 120);
    }
}
is_($badC === [] && $okC === count($MAY_CREATE) && $refC === 6,
    '＋ New Job: admin, support leader, support and support engineer may (D7); accountant, field accountant, sales, retailer, field agent and collector get 403, and no job is made',
    implode("\n       ", $badC));
foreach (['support_engineers' => 'New Job\'s engineer list', 'get_support_staff' => 'Bulk Dispatch\'s engineer list'] as $act => $label) {
    $bad = [];
    foreach ($CREATE_WHO as $who) {
        $r = $s->api($who, 'GET', $act);
        $want = in_array($who, $MAY_CREATE, true) ? 200 : 403;
        if ($r[0] !== $want || ($want === 403 && ($msg($r) !== NO_CREATE || stripos($r[1], 'Sandbox Tech') !== false))) $bad[] = "{$who}: {$r[0]} " . substr($r[1], 0, 100);
    }
    is_($bad === [], "{$label}: the same rule, and a refusal names nobody", implode("\n       ", $bad));
}

// ── 3. M7 ────────────────────────────────────────────────────────────────────
echo "\n3. M7: the verified link opens a job; a stored id does not\n";
$r = $s->api('stale', 'GET', 'scheduling_job_detail', null, '&job_id=901');
is_($r[0] === 403 && $msg($r) === NOT_YOURS, 'a stored id equal to the job\'s assignee, never verified: 403', $r[0] . ' ' . $msg($r));
$s->update($s->ids['stale'], $link(1099, 'stale@example.test'));
$r = $s->api('stale', 'GET', 'scheduling_job_detail', null, '&job_id=901');
is_($r[0] === 200 && (int)($r[2]['data']['job']['id'] ?? 0) === 901, 'control: the same account, once linked through the picker, may', (string)$r[0]);
$s->update($s->ids['stale'], ['ucrm_link' => null]);
$r = $s->api('stale', 'GET', 'scheduling_job_detail', null, '&job_id=901');
is_($r[0] === 403, 'and with the link removed again, may not', (string)$r[0]);
$s->update($s->ids['moved'], ['email' => 'moved-then@example.test']);
$r = $s->api('moved', 'GET', 'scheduling_job_detail', null, '&job_id=901');
is_($r[0] === 200, 'control: the moved account, its e-mail put back to the one verified, may', (string)$r[0]);
$s->update($s->ids['moved'], ['email' => 'moved-now@example.test']);
$r = $s->api('moved', 'GET', 'scheduling_job_detail', null, '&job_id=901');
is_($r[0] === 403 && $msg($r) === NOT_YOURS, 'and its e-mail changed again, may not: the link is re-verified or it does not count', $r[0] . ' ' . $msg($r));

// ── 4. No assignee ───────────────────────────────────────────────────────────
echo "\n4. A job with no assignee, and an answer without the field (V4)\n";
foreach ([903 => ['assignedUserId is null', NOT_YOURS], 904 => ['the answer has no assignedUserId field', NO_ASSIGNEE]] as $jid => [$label, $want]) {
    foreach (['tech', 'acct', 'stale'] as $who) {
        $before = $snap($s);
        $r = $s->api($who, 'GET', 'scheduling_job_detail', null, "&job_id={$jid}");
        $r2 = $s->api($who, 'POST', 'scheduling_job_update', ['job_id' => $jid, 'status' => 'open']);
        is_($r[0] === 403 && $msg($r) === $want && $r2[0] === 403 && $msg($r2) === $want && $clean($r[1]) && $snap($s) === $before,
            "job {$jid} ({$label}): {$who} gets 403 \"{$want}\" and nothing is written", $r[0] . ' ' . $msg($r) . ' / ' . $r2[0] . ' ' . $msg($r2));
    }
    foreach (['lead', 'admin'] as $who) {
        $r = $s->api($who, 'GET', 'scheduling_job_detail', null, "&job_id={$jid}");
        is_($r[0] === 200 && (int)($r[2]['data']['job']['id'] ?? 0) === $jid, "job {$jid}: the {$who} may", (string)$r[0]);
    }
}

// ── 5. T6.2 ──────────────────────────────────────────────────────────────────
echo "\n5. A task belongs to its job (T6.2)\n";
$t = function (int $task) use ($s): int { return count($s->crmReqs('PATCH', '#^/scheduling/job-tasks/' . $task . '$#')); };
$n0 = $t(7003);
$r = $s->api('tech', 'POST', 'scheduling_task_update', ['task_id' => 7003, 'job_id' => 901, 'done' => true]);
is_($r[0] === 403 && $msg($r) === 'That task is not one of this job\'s tasks.' && $t(7003) === $n0,
    'another job\'s task, sent with the assignee\'s own job id: 403, and uCRM is not touched', $r[0] . ' ' . $msg($r));
$n0 = $t(7001);
$r = $s->api('tech', 'POST', 'scheduling_task_update', ['task_id' => 7001, 'done' => true]);
is_($r[0] === 422 && $msg($r) === 'job_id required.' && $t(7001) === $n0, 'a task id with no job: 422, and uCRM is not touched', $r[0] . ' ' . $msg($r));
$r = $s->api('tech', 'POST', 'scheduling_task_update', ['task_id' => 7003, 'job_id' => 902, 'done' => true]);
is_($r[0] === 403 && $msg($r) === NOT_YOURS && $t(7003) === 0, 'the same task with its own job, which is not theirs: 403', $r[0] . ' ' . $msg($r));
$r = $s->api('other', 'POST', 'scheduling_task_update', ['task_id' => 7003, 'job_id' => 902, 'done' => false]);
is_($r[0] === 200 && $t(7003) === 1, 'control: its assignee may tick it', $r[0] . ' ' . $msg($r));

// ── 6. The engineers chosen ──────────────────────────────────────────────────
echo "\n6. Every engineer chosen has a verified link — checked before the first job is made\n";
$posts = function () use ($s): int { return count($s->crmReqs('POST', '#^/scheduling/jobs$#')); };
$create = function (array $eng) use ($s): array {
    return $s->api('admin', 'POST', 'create_job', ['title' => 'Sandbox engineer check', 'date' => '2026-10-07', 'time' => '09:00', 'engineer_ids' => $eng, 'notify_wa' => 0]);
};
foreach ([[[1105], 'uCRM user 1105, whose stored id and e-mail both match but were never verified'],
          [[1100, 1105], 'a verified engineer and an unverified one together: not even the first job'],
          [[4242], 'a uCRM user no account is linked to']] as [$eng, $label]) {
    $n0 = $posts();
    $r = $create($eng);
    is_($r[0] === 422 && strpos($msg($r), 'is not an engineer who can take jobs') !== false && $posts() === $n0, "{$label}: 422, no job", $r[0] . ' ' . $msg($r));
}
$n0 = $posts();
$r = $create([1100, 1101]);
is_($r[0] === 200 && (int)($r[2]['data']['created'] ?? 0) === 2 && $posts() === $n0 + 2, 'control: two verified engineers, one a support engineer (D7): two jobs', $r[0] . ' ' . substr($r[1], 0, 120));
$s->update($s->ids['other'], ['is_active' => false]);
$n0 = $posts(); $r = $create([1100]);
is_($r[0] === 422 && $posts() === $n0, 'an engineer whose account was deactivated: 422, no job', $r[0] . ' ' . $msg($r));
$s->update($s->ids['other'], ['is_active' => true]);
$s->seedCrm(['users' => array_replace($users, ['1101' => array_merge($users['1101'], ['isActive' => false])])]);
$n0 = $posts(); $r = $create([1101]);
is_($r[0] === 422 && $posts() === $n0, 'an engineer whose uCRM user was made inactive in uCRM: 422, no job', $r[0] . ' ' . $msg($r));
$s->seedCrm(['users' => array_replace($users, ['1101' => array_merge($users['1101'], ['email' => 'someone-else@example.test'])])]);
$n0 = $posts(); $r = $create([1101]);
is_($r[0] === 422 && $posts() === $n0, 'an engineer whose uCRM e-mail is no longer the account\'s (D9): 422, no job', $r[0] . ' ' . $msg($r));
$s->seedCrm(['users' => $users]);
$s->usersDown(true);
$n0 = $posts(); $r = $create([1099]);
is_($r[0] === 503 && strpos($msg($r), 'no job was created') !== false && $posts() === $n0, 'uCRM not answering: 503, and no job is made on an unchecked engineer', $r[0] . ' ' . $msg($r));
$s->usersDown(false);

$bulk = function (string $who, int $assignee) use ($s): array {
    return $s->api($who, 'POST', 'bulk_create_jobs', ['job_title' => 'Sandbox batch', 'job_date' => '2026-10-09', 'job_time' => '09:00',
        'customers' => [['crm_id' => 15, 'assignee_id' => $assignee]]]);
};
foreach (['tech', 'eng', 'stale', 'acct'] as $who) {
    $n0 = $posts(); $r = $bulk($who, 1099);
    is_($r[0] === 403 && $msg($r) === 'Support Leader or Admin access required.' && $posts() === $n0, "Bulk Dispatch by {$who}: 403, as in 5.18.49, and no job", $r[0] . ' ' . $msg($r));
}
$n0 = $posts(); $r = $bulk('lead', 1105);
is_($r[0] === 422 && strpos($msg($r), 'Row 1: uCRM user #1105') !== false && $posts() === $n0, 'Bulk Dispatch by the leader to an unverified user: 422 naming the row, no job', $r[0] . ' ' . $msg($r));
$n0 = $posts(); $r = $bulk('lead', 1099);
is_($r[0] === 200 && $posts() === $n0 + 1, 'control: the leader to a verified engineer — one job', $r[0] . ' ' . substr($r[1], 0, 120));

// ── 7. Completion ────────────────────────────────────────────────────────────
echo "\n7. A job is completed by its assignee\n";
$before = $snap($s);
$r = $s->api('other', 'POST', 'scheduling_complete', ['job_id' => 905, 'comment' => 'Sandbox note']);
is_($r[0] === 403 && $msg($r) === NOT_YOURS && $snap($s) === $before, 'another engineer: 403, nothing stored, uCRM not written', $r[0] . ' ' . $msg($r));
// 5.18.66: on Uganda a completion carries where the technician was (a fix, or a reason there is none) — test_job_photos.php.
$r = $s->api('tech', 'POST', 'scheduling_complete', ['job_id' => 905, 'comment' => 'Sandbox note', 'lat' => 0.3476, 'lon' => 32.5825, 'accuracy' => 12]);
$closed = array_values(array_filter($s->crmReqs('PATCH', '#^/scheduling/jobs/905$#'), function ($q) { return (int)($q['body']['status'] ?? -1) === 2; }));
$stored = array_map(function ($row) { return json_decode((string)($row['data'] ?? '{}'), true); }, $s->q('SELECT * FROM [job_completions]'));
is_($r[0] === 200 && !empty($r[2]['data']['completed']) && count($closed) === 1 && in_array(905, array_map(function ($x) { return (int)($x['job_id'] ?? 0); }, $stored), true),
    'its assignee: completed — closed in uCRM and recorded', $r[0] . ' ' . substr($r[1], 0, 120));

// ── 8. A web session that outlives the account ──────────────────────────────
echo "\n8. An account deactivated or demoted with its web session open\n";
$viaSession = function (string $who, string $method, string $action, ?array $body = null, string $qs = '') use ($s): array {
    return $s->http($method, "{$s->base}?page=api&action={$action}{$qs}", $body, ['Content-Type: application/json'], $s->jars[$who]);
};
$s->login('gone', 'gone@example.test', 'sj-password-1');
$r = $viaSession('gone', 'GET', 'scheduling_job_detail', null, '&job_id=902');
is_($r[0] === 200 && (int)($r[2]['data']['job']['id'] ?? 0) === 902, 'control: through the open web session, with no token, a leader may read a job', (string)$r[0]);
$s->update($s->ids['gone'], ['is_active' => false]);
$c0 = count($s->crmReqs('POST', '#^/scheduling/jobs/902/job-comments$#'));
$r  = $viaSession('gone', 'GET', 'scheduling_job_detail', null, '&job_id=902');
$r2 = $viaSession('gone', 'POST', 'scheduling_add_comment', ['job_id' => 902, 'comment' => 'Sandbox comment']);
$r3 = $viaSession('gone', 'GET', 'scheduling_jobs', null, '&refresh=1');
is_($r[0] === 403 && $msg($r) === INACTIVE && $clean($r[1]), 'deactivated: the next read is 403 "' . INACTIVE . '", with no job data', $r[0] . ' ' . substr($r[1], 0, 120));
is_($r2[0] === 403 && $msg($r2) === INACTIVE && count($s->crmReqs('POST', '#^/scheduling/jobs/902/job-comments$#')) === $c0, 'the next write too, and nothing reaches uCRM', $r2[0] . ' ' . $msg($r2));
is_($r3[0] === 403 && $msg($r3) === INACTIVE, 'and My Jobs', $r3[0] . ' ' . $msg($r3));
$r = $s->api('gone', 'GET', 'scheduling_job_detail', null, '&job_id=902');
is_($r[0] === 401, 'its Bearer token was already refused before 5.18.50 (the token lookup reads is_active): 401', (string)$r[0]);

$s->login('demoted', 'demoted@example.test', 'sj-password-1');
$r = $viaSession('demoted', 'GET', 'scheduling_job_detail', null, '&job_id=902');
is_($r[0] === 200, 'control: a support leader\'s open session may read another engineer\'s job', (string)$r[0]);
$s->update($s->ids['demoted'], ['role' => 'sales']);
$r  = $viaSession('demoted', 'GET', 'scheduling_job_detail', null, '&job_id=902');
$n0 = $posts();
$r2 = $viaSession('demoted', 'POST', 'create_job', ['title' => 'Sandbox after demotion', 'date' => '2026-10-07', 'time' => '09:00', 'engineer_ids' => [1099], 'notify_wa' => 0]);
is_($r[0] === 403 && $msg($r) === NOT_YOURS, 'moved to sales with the session open: the next read is refused, by the role stored now', $r[0] . ' ' . $msg($r));
is_($r2[0] === 403 && $msg($r2) === NO_CREATE && $posts() === $n0, 'and it can no longer create a job', $r2[0] . ' ' . $msg($r2));

$s->stop();

// ── 9. Weakened copies ───────────────────────────────────────────────────────
if ($withMutants) {
    echo "\n9. Weakened copies of the code must each fail this test\n";
    $MUTANTS = [
        ['lib/JobAccess.php', "        return (is_array(\$row) && StaffDirectory::isActive(\$row)) ? \$row : null;", "        return \$me2;",
         'the cached session record used instead of the account as stored now'],
        ['lib/JobAccess.php', "        if (\$assignee === null || \$assignee <= 0) return false;\n        \$mine = StaffDirectory::linkedUcrmUser(\$row);\n        return \$mine > 0 && \$mine === \$assignee;",
         "        \$mine = StaffDirectory::linkedUcrmUser(\$row);\n        return \$mine === (int)\$assignee;", 'uCRM user 0 treated as a match'],
        ['includes/api/api_scheduling.php',
         "        if (\$_sjUganda) {   // J6, checked before uCRM is written\n            \$_sjMe = \$sjCaller();\n            if (!\$job) \$er2('Job not found.', 404);\n            \$sjMayAct(\$_sjMe, \$job);\n        }\n        \$result = \$crm->patch(\"scheduling/jobs/{\$jobId}\", ['status' => \$statusInt]);",
         "        \$result = \$crm->patch(\"scheduling/jobs/{\$jobId}\", ['status' => \$statusInt]);\n        if (\$_sjUganda) {   // J6, checked before uCRM is written\n            \$_sjMe = \$sjCaller();\n            if (!\$job) \$er2('Job not found.', 404);\n            \$sjMayAct(\$_sjMe, \$job);\n        }",
         'the status check placed after the uCRM write'],
        ['lib/JobAccess.php', "        \$mine = StaffDirectory::linkedUcrmUser(\$row);", "        \$mine = (int)(\$row['ucrm_user_id'] ?? 0);",
         'acting on a job through a stored, unverified id (M7)'],
        ['includes/api/api_scheduling.php', "            \$myAdminId  = StaffDirectory::linkedUcrmUser(\$_sjMe);", "            \$myAdminId  = (int)(\$_sjMe['ucrm_user_id'] ?? 0);",
         'My Jobs through a stored, unverified id (M7)'],
        ['includes/api/api_scheduling.php', "            \$myClientId = 0;", "            \$myClientId = (int)(\$me2['ftth_crm_client_id'] ?? 0);",
         'My Jobs falling back to ftth_crm_client_id (the J2 correction)'],
        ['includes/api/api_scheduling.php', "        if (\$_sjUganda) \$sjMayAct(\$sjCaller(), \$job);   // J6: the assignee, a support leader or an admin", '',
         'the job detail unchecked'],
        ['includes/api/api_scheduling.php', "            if (!in_array(\$taskId, \$_sjTaskIds, true)) \$er2('That task is not one of this job\\'s tasks.', 403);", '',
         'a task of another job accepted (T6.2)'],
        ['includes/api/api_scheduling.php', "        if (\$_sjUganda) \$sjMayAct(\$sjCaller(), \$job);   // J6, before anything is stored or sent", '',
         'completion unchecked'],
        ['includes/api/api_crm_misc.php', "        \$_cmJobGuard(\$jobId);   // J6 on Uganda\n        \$surveys = \$store->load", "        \$surveys = \$store->load",
         'a survey readable by anyone'],
        ['includes/api/api_crm_misc.php', "        if (!\$jobId || !\$sigData) \$er2('job_id and signature required.', 422);\n        \$_cmJobGuard(\$jobId);   // J6 on Uganda",
         "        if (!\$jobId || !\$sigData) \$er2('job_id and signature required.', 422);", 'a signature saved by anyone'],
        ['lib/JobAccess.php', "    public const CREATE_ROLES = ['support_leader', 'support', 'support_engineer'];",
         "    public const CREATE_ROLES = ['support_leader', 'support', 'support_engineer', 'accountant'];", 'job creation widened to another role'],
    ];
    foreach ($MUTANTS as [$rel, $old, $new, $label]) {
        [$tmp, $n] = sj_weakened_copy($root, $rel, $old, $new);
        if ($n !== 1) { is_(false, "weakened copy \"{$label}\": its anchor occurs once in {$rel}", "found {$n} times"); exec('rm -rf ' . escapeshellarg($tmp)); continue; }
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --root=' . escapeshellarg($tmp) . ' --no-mutants 2>&1', $out, $rc);
        $fails = array_values(array_filter($out, function ($l) { return strpos($l, '  FAIL ') === 0; }));
        is_($rc !== 0 && $fails !== [], "caught: {$label}", 'exit ' . $rc . ', ' . count($fails) . ' failure(s)');
        if ($fails) echo '         first: ' . trim(substr($fails[0], 7, 110)) . "\n";
        exec('rm -rf ' . escapeshellarg($tmp));
    }
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
