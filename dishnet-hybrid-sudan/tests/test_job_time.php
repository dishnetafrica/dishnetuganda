<?php
declare(strict_types=1);
/**
 * test_job_time.php — 5.18.50 (docs/44 J5, release A): on Uganda a job's day and hour go to uCRM in Kampala's time.
 *
 * ＋ New Job and Bulk Dispatch sent the typed hour as UTC ("…T09:00:00.000Z" — noon in Kampala) and Reschedule sent
 * "2026-10-06 11:00" with no zone at all. Proved here against a fake uCRM:
 *   1. JobTime: Kampala's offset in uCRM's own form, the zone taken from the install, near-midnight on the right day,
 *      and every malformed date or time refused;
 *   2. on Uganda, the three paths send that form, and a malformed request is answered 422 with nothing sent to uCRM —
 *      Bulk Dispatch checks every row before it creates the first job;
 *   3. on South Sudan the three strings are byte for byte 5.18.49's;
 *   4. weakened copies of the code each fail this test.
 *
 *   php test_job_time.php [--root=DIR] [--no-mutants]
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

// ── 1. JobTime ────────────────────────────────────────────────────────────────
echo "\n1. JobTime\n";
require_once "{$root}/lib/JobTime.php";
$kla = new DateTimeZone('Africa/Kampala');
is_(JobTime::toUcrm('2026-10-06', '09:00', $kla) === '2026-10-06T09:00:00+0300', '09:00 on 6 October → 2026-10-06T09:00:00+0300');
is_(JobTime::toUcrm('2026-10-06', '00:30', $kla) === '2026-10-06T00:30:00+0300', 'half past midnight stays on the same day');
is_(JobTime::toUcrm('2026-10-06', '23:59', $kla) === '2026-10-06T23:59:00+0300', 'and a minute before midnight too');
is_(JobTime::toUcrm('2026-10-06', '09:00', new DateTimeZone('Africa/Juba')) === '2026-10-06T09:00:00+0200',
    'the zone is the install\'s, not written in (Juba is +0200)');
foreach ([['2026-02-30', '09:00', 'a day that does not exist'], ['2026-10-6', '09:00', 'a date without its zero'],
          ['06/10/2026', '09:00', 'a date in another order'], ['2026-10-06', '9:00', 'an hour without its zero'],
          ['2026-10-06', '24:00', 'hour 24'], ['2026-10-06', '09:60', 'minute 60'], ['2026-10-06', '9am', 'words'],
          ['', '09:00', 'no date'], ['2026-10-06', '', 'no time']] as [$d, $t, $label]) {
    is_(JobTime::toUcrm($d, $t, $kla) === null, "refused: {$label}");
}
is_(JobTime::fromLocal('2026-10-08 11:00', $kla) === '2026-10-08T11:00:00+0300', 'Reschedule\'s "2026-10-08 11:00"');
is_(JobTime::fromLocal('2026-10-08T11:00', $kla) === '2026-10-08T11:00:00+0300', 'the same with a T');
is_(JobTime::fromLocal('2026-10-08T11:00:00', $kla) === '2026-10-08T11:00:00+0300', 'and with the seconds a browser may add');
is_(JobTime::fromLocal('2026-10-08', $kla) === null && JobTime::fromLocal('tomorrow 11:00', $kla) === null, 'a date alone, or words, refused');

// ── 2. On Uganda, through the API ─────────────────────────────────────────────
echo "\n2. On Uganda, through ＋ New Job, Bulk Dispatch and Reschedule\n";
$users = ['1000' => ['id' => 1000, 'username' => 'sb-admin', 'email' => 'admin@example.test', 'isActive' => true],
          '1099' => ['id' => 1099, 'username' => 'sb-tech', 'email' => 'tech@example.test', 'isActive' => true]];
$clients = ['15' => ['id' => 15, 'firstName' => 'Test', 'lastName' => 'Client', 'street1' => 'Plot 1 Test Road', 'street2' => 'Kampala'],
            '16' => ['id' => 16, 'firstName' => 'Second', 'lastName' => 'Client', 'street1' => 'Plot 2 Test Road', 'street2' => 'Kampala']];
$link = function (int $id, string $email): array {
    return ['ucrm_user_id' => $id, 'ucrm_link' => ['user_id' => $id, 'email' => $email, 'verified_at' => '2026-09-27T00:00:00Z', 'verified_by' => 1]];
};
$ug = SjSandbox::start($root, ['tenant_profile' => 'uganda', 'timezone' => 'Africa/Kampala'], 'sjtime');
$ug->seedCrm(['users' => $users, 'clients' => $clients,
              'jobs' => ['901' => ['id' => 901, 'title' => 'Router replacement', 'assignedUserId' => 1099, 'date' => '2026-10-05T09:00:00+0300', 'status' => 0]]]);
$ug->staff('admin', ['name' => 'Sandbox Admin', 'email' => 'admin@example.test', 'role' => 'admin', 'is_admin' => true] + $link(1000, 'admin@example.test'));
$ug->staff('tech',  ['name' => 'Sandbox Tech', 'email' => 'tech@example.test', 'role' => 'support'] + $link(1099, 'tech@example.test'));
$posts = function (SjSandbox $s): array { return $s->crmReqs('POST', '#^/scheduling/jobs$#'); };
$patch = function (SjSandbox $s, int $id): array { return $s->crmReqs('PATCH', '#^/scheduling/jobs/' . $id . '$#'); };

$r = $ug->api('admin', 'POST', 'create_job', ['title' => 'Installation', 'date' => '2026-10-06', 'time' => '09:00', 'engineer_ids' => [1099], 'notify_wa' => 0]);
$p = $posts($ug);
is_($r[0] === 200 && count($p) === 1 && ($p[0]['body']['date'] ?? '') === '2026-10-06T09:00:00+0300',
    '＋ New Job at 09:00 reaches uCRM as 2026-10-06T09:00:00+0300', $r[1] . ' ' . json_encode($p[0]['body'] ?? null));
$r = $ug->api('admin', 'POST', 'create_job', ['title' => 'Late visit', 'date' => '2026-10-07', 'time' => '00:30', 'engineer_ids' => [1099], 'notify_wa' => 0]);
$p = $posts($ug);
is_(($p[1]['body']['date'] ?? '') === '2026-10-07T00:30:00+0300', 'and 00:30 stays on its own day', json_encode($p[1]['body'] ?? null));
foreach ([['2026-02-30', '09:00'], ['2026-10-06', '9am']] as [$d, $t]) {
    $n0 = count($posts($ug));
    $r = $ug->api('admin', 'POST', 'create_job', ['title' => 'Bad', 'date' => $d, 'time' => $t, 'engineer_ids' => [1099], 'notify_wa' => 0]);
    is_($r[0] === 422 && count($posts($ug)) === $n0, "＋ New Job with \"{$d} {$t}\": 422, and nothing sent to uCRM", $r[0] . ' ' . substr($r[1], 0, 120));
}

$n0 = count($posts($ug));
$r = $ug->api('admin', 'POST', 'bulk_create_jobs', ['job_title' => 'Fiber install', 'job_date' => '2026-10-09', 'job_time' => '09:00',
    'customers' => [['crm_id' => 15, 'assignee_id' => 1099, 'job_time' => '14:30'], ['crm_id' => 16, 'assignee_id' => 1099]]]);
$p = array_slice($posts($ug), $n0);
is_($r[0] === 200 && count($p) === 2 && ($p[0]['body']['date'] ?? '') === '2026-10-09T14:30:00+0300' && ($p[1]['body']['date'] ?? '') === '2026-10-09T09:00:00+0300',
    'Bulk Dispatch: each row\'s hour, in Kampala time', $r[1]);
$n0 = count($posts($ug));
$r = $ug->api('admin', 'POST', 'bulk_create_jobs', ['job_title' => 'Fiber install', 'job_date' => '2026-10-09', 'job_time' => '09:00',
    'customers' => [['crm_id' => 15, 'assignee_id' => 1099], ['crm_id' => 16, 'assignee_id' => 1099, 'job_time' => '25:00']]]);
is_($r[0] === 422 && strpos((string)($r[2]['message'] ?? ''), 'Row 2') !== false && count($posts($ug)) === $n0,
    'a malformed hour in row 2: 422 naming the row, and not even row 1 is created', $r[0] . ' ' . ($r[2]['message'] ?? ''));

$n0 = count($patch($ug, 901));
$r = $ug->api('tech', 'POST', 'scheduling_reschedule', ['job_id' => 901, 'new_date' => '2026-10-08 11:00', 'comment' => '']);
$pt = array_slice($patch($ug, 901), $n0);
is_($r[0] === 200 && count($pt) === 1 && ($pt[0]['body']['date'] ?? '') === '2026-10-08T11:00:00+0300',
    'Reschedule to "2026-10-08 11:00" reaches uCRM as 2026-10-08T11:00:00+0300', $r[1] . ' ' . json_encode($pt));
$n0 = count($patch($ug, 901));
$r = $ug->api('tech', 'POST', 'scheduling_reschedule', ['job_id' => 901, 'new_date' => '2026-10-08', 'comment' => '']);
is_($r[0] === 422 && count($patch($ug, 901)) === $n0, 'Reschedule with a date and no hour: 422, and uCRM is not touched', $r[0] . ' ' . substr($r[1], 0, 120));
$ug->stop();

// ── 3. South Sudan keeps its strings ─────────────────────────────────────────
echo "\n3. South Sudan: the three strings of 5.18.49, byte for byte\n";
$ss = SjSandbox::start($root, [], 'sjtime-ss');
$ss->seedCrm(['users' => $users, 'clients' => $clients,
              'jobs' => ['901' => ['id' => 901, 'title' => 'Router replacement', 'assignedUserId' => 1099, 'date' => '2026-10-05T09:00:00+0300', 'status' => 0]]]);
$ss->staff('admin', ['name' => 'Sandbox Admin', 'email' => 'ss-admin@example.test', 'role' => 'admin', 'is_admin' => true, 'ucrm_user_id' => 1000]);
$ss->staff('tech',  ['name' => 'Sandbox Tech', 'email' => 'ss-tech@example.test', 'role' => 'support', 'ucrm_user_id' => 1099]);
$ss->api('admin', 'POST', 'create_job', ['title' => 'Installation', 'date' => '2026-10-06', 'time' => '09:00', 'engineer_ids' => [1099], 'notify_wa' => 0]);
$ss->api('admin', 'POST', 'bulk_create_jobs', ['job_title' => 'Fiber install', 'job_date' => '2026-10-09', 'job_time' => '09:00',
    'customers' => [['crm_id' => 15, 'assignee_id' => 1099, 'job_time' => '14:30']]]);
$ss->api('tech', 'POST', 'scheduling_reschedule', ['job_id' => 901, 'new_date' => '2026-10-08 11:00', 'comment' => '']);
$p = $posts($ss); $pt = $patch($ss, 901);
is_(($p[0]['body']['date'] ?? '') === '2026-10-06T09:00:00.000Z', '＋ New Job: 2026-10-06T09:00:00.000Z', json_encode($p[0]['body']['date'] ?? null));
is_(($p[1]['body']['date'] ?? '') === '2026-10-09T14:30:00.000Z', 'Bulk Dispatch: 2026-10-09T14:30:00.000Z', json_encode($p[1]['body']['date'] ?? null));
is_(($pt[0]['body']['date'] ?? '') === '2026-10-08 11:00', 'Reschedule: 2026-10-08 11:00', json_encode($pt[0]['body']['date'] ?? null));
$r = $ss->api('admin', 'POST', 'create_job', ['title' => 'Bad', 'date' => '2026-02-30', 'time' => '09:00', 'engineer_ids' => [1099], 'notify_wa' => 0]);
is_($r[0] === 200, 'and South Sudan still accepts what 5.18.49 accepted (D2)', (string)$r[0]);
$ss->stop();

// ── 4. Weakened copies ───────────────────────────────────────────────────────
if ($withMutants) {
    echo "\n4. Weakened copies of the code must each fail this test\n";
    $MUTANTS = [
        ['lib/JobTime.php', "return \$dt->format('Y-m-d\\TH:i:sO');", "return \$dt->format('Y-m-d\\TH:i:s') . '.000Z';", 'a Z suffix left in place'],
        ['lib/JobTime.php', "if (\$dt === false || \$dt->format('Y-m-d H:i') !== \$date . ' ' . \$time) return null;",
         "if (\$dt === false) return null;", 'an impossible day let through'],
        ['includes/api/api_scheduling.php', "'date'           => \$_sjUganda ? \$_sjWhen : (\$date . 'T' . \$time . ':00.000Z'),",
         "'date'           => \$date . 'T' . \$time . ':00.000Z',", '＋ New Job still sending UTC'],
        ['includes/api/api_scheduling.php', "\$patchResult = \$crm->patch(\"scheduling/jobs/{\$jobId}\", ['date' => \$_sjUganda ? \$_sjWhen : \$newDate]);",
         "\$patchResult = \$crm->patch(\"scheduling/jobs/{\$jobId}\", ['date' => \$newDate]);", 'Reschedule sending no zone'],
        ['includes/api/api_scheduling.php', "                if (JobTime::toUcrm(\$jobDate, trim(\$_sjC['job_time'] ?? \$jobTime), \$_sjTz) === null) {",
         "                if (false) {", 'Bulk Dispatch creating before it checks every row'],
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
