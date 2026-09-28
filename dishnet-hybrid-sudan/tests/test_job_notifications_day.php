<?php
declare(strict_types=1);
/**
 * test_job_notifications_day.php — 5.18.52 (docs/44 J4, §16.12, §16.14): one day of job traffic on Uganda, on 5.18.51
 * as installed (commit 240f2f9, taken from Git) and on this tree. What changes is exactly what release B changes;
 * everything else is 5.18.51's, byte for byte.
 *
 * This file was test_job_notifications_off.php, release A's proof of M6 ("no job WhatsApp on Uganda"). Release B
 * reverses M6 on purpose (the operator's "approved", docs/44 §16.12), so its assertions are rewritten to the new truth,
 * not deleted: the control below still proves 5.18.51 sends none of the four, so their presence here is release B.
 *
 * The day (fixtures/staff_jobs_scenario.php): ＋ New Job, Bulk Dispatch, Reschedule of a job the plugin never saw, a
 * job created in uCRM (job.add), Accept, two tasks, Complete.
 *   1. the control: 5.18.51, as Uganda, sends none of the four assignment messages and the old "✅ Job Accepted";
 *   2. this tree: message 1 on each of the four paths — Reschedule's job is one the plugin was never told about, so its
 *      first message is message 1 at its next change (§5.5) — and message 2 on Accept; every answer says so; and since
 *      §16.16 each of the five also by e-mail, the same text, to the engineer's staff account;
 *   3. every other message, the Message Log's other rows, the customer's "installation scheduled" e-mail and the
 *      webhook log are 5.18.51's, byte for byte, but for the one job line;
 *   4. uCRM receives the same writes in the same order; only ＋ New Job and Bulk Dispatch now create their jobs Open
 *      (status 0), so the engineer's Accept button shows;
 *   5. the ＋ New Job screen says a message is sent; the dispatch cron stays off;
 *   6. South Sudan: the same day on 5.18.51 and on this tree says exactly the same things, and the two new tables stay
 *      empty;
 *   7. weakened copies of the code each fail this test.
 *
 * What this proves is what the plugin hands to WhatsApp and to the mail server. It is not proof of delivery to a phone or
 * an inbox.
 *
 *   php test_job_notifications_day.php [--root=DIR] [--no-mutants]
 */
$opt  = getopt('', ['root:', 'no-mutants']);
$root = isset($opt['root']) ? rtrim((string)$opt['root'], '/') : dirname(__DIR__);
$withMutants = !isset($opt['no-mutants']);
require_once __DIR__ . '/fixtures/staff_jobs_scenario.php';

$pass = 0; $fail = 0; $skip = 0;
function is_(bool $c, string $m, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; }
    else    { $fail++; echo "  FAIL {$m}" . ($d !== '' ? "\n       {$d}" : '') . "\n"; }
}
function skip_(string $m): void { global $skip; $skip++; echo "  skip {$m}\n"; }

$ui = function (SjSandbox $s): array {
    $s->login('admin', 'admin@example.test', 'sj-password-1');
    return ['scheduling' => $s->page('admin', 'page=dashboard&tab=scheduling'), 'bulk' => $s->page('admin', 'page=dashboard&tab=bulk_dispatch'),
            'tables' => array_map(function ($t) use ($s) { try { return (int)$s->q("SELECT COUNT(*) AS n FROM {$t}")[0]['n']; } catch (\Throwable $e) { return null; } },
                                  ['job_notify_state', 'job_notify_events'])];
};
/** Release B's messages: message 1 and message 2 — every one says "This is DishNet Africa." */
$isB      = function (array $t): bool { return strpos($t['text'], ". This is DishNet Africa.\n") !== false; };
$isOldAcc = function (array $t): bool { return strpos($t['text'], '✅ *Job Accepted*') === 0; };
$other    = function (array $run) use ($isB, $isOldAcc): array { return array_values(array_filter($run['texts'], function ($t) use ($isB, $isOldAcc) { return !$isB($t) && !$isOldAcc($t); })); };
$B_EVENTS = ['job_assigned', 'job_rescheduled', 'job_unassigned', 'job_cancelled', 'ops_job_accepted_self'];
$logOther = function (array $run) use ($B_EVENTS): array { return array_values(array_filter($run['log'], function ($l) use ($B_EVENTS) { return !in_array($l['event'], $B_EVENTS, true); })); };
$whOther  = function (array $run): array { return array_values(array_filter($run['whlog'], function ($l) { return strpos($l['message'], 'Job #911 ') !== 0; })); };
$writes   = function (array $run, bool $status): array {
    $out = [];
    foreach ($run['crm'] as $q) {
        if ($q['method'] === 'GET') continue;
        $b = is_array($q['body']) ? $q['body'] : [];
        if (!$status && $q['method'] === 'POST' && $q['path'] === '/scheduling/jobs') unset($b['status']);
        $out[] = [$q['method'], $q['path'], $b];
    }
    return $out;
};
$created  = function (array $run): array {
    return array_values(array_map(function ($q) { return $q['body']['status'] ?? null; },
        array_filter($run['crm'], function ($q) { return $q['method'] === 'POST' && $q['path'] === '/scheduling/jobs'; })));
};
$data = function (array $run, string $step): array { return is_array($run['answers'][$step][1]['data'] ?? null) ? $run['answers'][$step][1]['data'] : []; };

[$base, $why] = sj_baseline_tree(SJ_BASELINE_51);
if ($base !== null) {
    is_(preg_match('/"version":\s*"5\.18\.51"/', (string)@file_get_contents($base . '/manifest.json')) === 1, 'the baseline is 5.18.51, from Git (' . SJ_BASELINE_51 . ')');
} else {
    skip_("the 5.18.51 comparison: {$why}");
}

// ═════════════════════════════════════════════════════════════════════════════
$ug    = ['tenant_profile' => 'uganda', 'timezone' => 'Africa/Kampala'];
$new   = SjScenario::run($root, $ug, 'sjday-ug', $ui);
$old   = $base !== null ? SjScenario::run($base, $ug, 'sjday-ug-old') : null;
$every = array_map(function ($r) { return $r[0]; }, $new['answers']);
is_(array_values(array_unique($every)) === [200], 'the day ran: all eight steps answered 200 on this tree', json_encode($every));

echo "\n1. The control: 5.18.51 sends no job-assignment message, and the old \"Job Accepted\"\n";
if ($old) {
    is_(array_filter($old['texts'], $isB) === [] && !array_filter($old['texts'], function ($t) { return strpos($t['text'], 'New Job Assigned') !== false || strpos($t['text'], 'Rescheduled*') !== false; }),
        'no assignment message on any of the four paths (M6, as installed)', json_encode(array_column($old['texts'], 'number')));
    $oa = array_values(array_filter($old['texts'], $isOldAcc));
    is_(count($oa) === 1 && $oa[0]['number'] === '256700000111', 'and "✅ Job Accepted" to the engineer on Accept');
    is_(($data($old, 'create')['whatsapp'] ?? '') === 'not_sent', 'its ＋ New Job answered "not_sent"');
} else {
    skip_('the 5.18.51 control');
}

echo "\n2. This tree: message 1 on the four paths, message 2 on Accept\n";
$b = array_values(array_filter($new['texts'], $isB));
is_(count($b) === 5 && count(array_unique(array_column($b, 'number'))) === 1 && $b[0]['number'] === '256700000111', 'five release-B messages, all to the engineer', json_encode(array_column($b, 'number')));
$m1 = function (int $i, string $title, string $date, int $job, string $addr) use ($b): bool {
    return ($b[$i]['text'] ?? '') === "Hi Sandbox Tech. This is DishNet Africa.\n\nNew Job Has Been Assigned to You\n\n{$title}\n📅 Date: {$date}\n"
        . "👤 Client: Sandbox Customer (ID:15)\n📞 Mobile: +256700000915\n📍 Address: {$addr}\n\n---\nPlease click the link below to accept this job:\n\n"
        . "✅ ACCEPT JOB:\n<crm>/crm/_plugins/plugin/public.php?page=dashboard&tab=scheduling&job={$job}\n\n"
        . "Once you accept, we will send you the completion link.\n\nFor any questions, just reach out here.\n📞 +256 705 993 348\n🌐 dishnetuganda.com";
};
is_($m1(0, 'Sandbox installation — Sandbox Customer', '07.10.2026 09:00 am', 950, 'Plot 9 Sandbox Road Kampala'), '＋ New Job: message 1', json_encode($b[0]['text'] ?? '', JSON_UNESCAPED_UNICODE));
is_($m1(1, 'Sandbox batch — Sandbox Customer', '09.10.2026 09:00 am', 951, 'Plot 9 Sandbox Road Kampala'), 'Bulk Dispatch: message 1', json_encode($b[1]['text'] ?? '', JSON_UNESCAPED_UNICODE));
is_($m1(2, 'Router check', '08.10.2026 11:00 am', 905, 'Plot 9 Sandbox Road, Kampala'),
    'Reschedule of a job the plugin was never told about: its first message is message 1, at the new time', json_encode($b[2]['text'] ?? '', JSON_UNESCAPED_UNICODE));
is_($m1(3, 'Starlink installation', '05.10.2026 09:00 am', 911, 'Plot 9 Sandbox Road, Kampala'), 'a job created in uCRM (job.add): message 1', json_encode($b[3]['text'] ?? '', JSON_UNESCAPED_UNICODE));
is_(($b[4]['text'] ?? '') === "Hi Sandbox Tech. This is DishNet Africa.\n\nThank you for accepting the job! ✅\n\nFiber installation\n📅 Date: 05.10.2026 09:00 am\n"
    . "👤 Client: Sandbox Customer (ID:15)\n\n✅ JOB COMPLETED:\n<crm>/crm/_plugins/plugin/public.php?page=dashboard&tab=scheduling&job=901\n"
    . "Press Complete there when the work is finished. The same page lets you reschedule or add a comment.\n\n📞 +256 705 993 348",
    'Accept: message 2, in place of the old "✅ Job Accepted"', json_encode($b[4]['text'] ?? '', JSON_UNESCAPED_UNICODE));
is_(array_filter($new['texts'], $isOldAcc) === [], 'the old "✅ Job Accepted" is not sent');
$evs = array_column($new['log'], 'event');
is_(array_values(array_intersect($evs, $B_EVENTS)) === ['job_assigned', 'job_assigned', 'job_assigned', 'job_assigned', 'ops_job_accepted_self'],
    'the Message Log records the four and message 2, under the event names 5.18.49 used for them', json_encode($evs));
foreach (['create' => '＋ New Job', 'bulk' => 'Bulk Dispatch', 'reschedule' => 'Reschedule'] as $step => $label) {
    $d = $data($new, $step);
    is_(($d['whatsapp'] ?? null) === 'sent'
        && ($d['whatsapp_note'] ?? null) === 'WhatsApp sent to the engineer, with the link to accept the job. The same message went to the engineer\'s e-mail.',
        "{$label} answers whatsapp: sent, in words, the e-mail included", json_encode($d, JSON_UNESCAPED_UNICODE));
}
is_(($data($new, 'create')['jobs'][0]['notified'] ?? null) === true, '＋ New Job marks the engineer "notified" — the 📱 in its result — because it went');
is_(($data($new, 'accept')['whatsapp_note'] ?? '') === 'WhatsApp with the completion link sent to the engineer on the job. The same message went to the engineer\'s e-mail.',
    'and Accept\'s answer says message 2 went, by both');
$wh = array_column($new['whlog'], 'message');
is_(in_array('Job #911 (assigned) — WhatsApp sent to staff account #3; e-mail handed to the mail server', $wh, true),
    'the job created in uCRM: the webhook log says "WhatsApp sent to staff account #3; e-mail handed to the mail server"', json_encode($wh, JSON_UNESCAPED_UNICODE));
// §16.16: the same five by e-mail — the text, byte for byte, to the engineer's staff account.
$techMail = array_values(array_filter($new['mail'], function ($m) { return $m['to'] === ['tech@example.test']; }));
$parsed   = array_map(function ($m) { return SjSandbox::parseMail($m['data']); }, $techMail);
is_(count($techMail) === 5 && array_column($parsed, 'text') === array_column($b, 'text'),
    'five e-mails to the engineer\'s staff account, each the same text as its WhatsApp, in the same order', json_encode(array_column($parsed, 'subject')));
is_(array_column($parsed, 'subject') === ['New job assigned to you: Job #950', 'New job assigned to you: Job #951', 'New job assigned to you: Job #905',
                                          'New job assigned to you: Job #911', 'Job #901 accepted: your completion link'],
    'each under its own subject', json_encode(array_column($parsed, 'subject')));
is_(array_unique(array_column($techMail, 'from')) === ['accounts@example.test'] && array_unique(array_column($parsed, 'reply_to')) === ['support@example.test'],
    'from the plugin\'s sender, replies to the tenant\'s reply address — as the customer\'s e-mail', json_encode([array_column($techMail, 'from'), array_column($parsed, 'reply_to')]));
if ($old) is_(in_array('Job #911 — WhatsApp skipped: job notifications are not switched on yet', array_column($old['whlog'], 'message'), true), 'control: 5.18.51 wrote "WhatsApp skipped" there');

echo "\n3. Everything else is 5.18.51's, byte for byte\n";
$names = ['🔔 *Job Accepted*' => 'accepted, to the support leader', 'Great job completing Task 1' => 'a task done', 'All 2 tasks completed' => 'all tasks done',
          '*Job Completed Successfully!*' => 'completed, to the engineer', 'completed by *Sandbox Tech*' => 'completed, to the admin', '*New Invoice Needed*' => 'the invoice request, to the accountant'];
$seen = [];
foreach ($other($new) as $t) foreach ($names as $needle => $label) if (strpos($t['text'], $needle) !== false) $seen[] = $label;
is_(count($other($new)) === 6 && $seen === array_values($names), 'this tree sends the other six: ' . implode('; ', array_values($names)), json_encode($seen, JSON_UNESCAPED_UNICODE));
if ($old) {
    is_($other($old) === $other($new), 'each is the same text to the same number, in the same order, as 5.18.51', json_encode(['5.18.51' => $other($old), 'this' => $other($new)], JSON_UNESCAPED_UNICODE));
    is_($logOther($old) === $logOther($new), 'the Message Log\'s other rows are the same, row for row');
    $customerMail = array_values(array_filter($new['mail'], function ($m) { return $m['to'] !== ['tech@example.test']; }));
    is_(count($customerMail) === 1 && $old['mail'] === $customerMail, 'the customer\'s "installation scheduled" e-mail: the same message, byte for byte (T4.11) — the engineer\'s five aside',
        count($old['mail']) . ' vs ' . count($customerMail));
    is_($whOther($old) === $whOther($new), 'the webhook log is the same, line for line, but for the one job line', json_encode([$whOther($old), $whOther($new)], JSON_UNESCAPED_UNICODE));
} else {
    skip_('the byte-for-byte comparison with 5.18.51');
}

echo "\n4. uCRM receives the same writes\n";
is_($created($new) === [0, 0], '＋ New Job and Bulk Dispatch create their jobs Open (status 0): the Accept button shows for them', json_encode($created($new)));
if ($old) {
    is_($created($old) === [1, 1], 'control: 5.18.51 created them In progress (status 1), where no Accept button is drawn', json_encode($created($old)));
    is_($writes($old, false) === $writes($new, false), 'otherwise the same writes, in the same order, dates included', json_encode([$writes($old, false), $writes($new, false)], JSON_UNESCAPED_UNICODE));
} else {
    skip_('the uCRM comparison with 5.18.51');
}

echo "\n5. The screens, and the cron\n";
$sch = (string)($new['extra']['scheduling'] ?? '');
is_(strpos($sch, 'The engineer gets a WhatsApp message and the same by e-mail') !== false && strpos($sch, 'No WhatsApp message is sent for jobs yet') === false,
    '＋ New Job says the engineer gets a WhatsApp message and the same by e-mail', strlen($sch) . ' bytes');
is_(strpos($sch, 'Notify assigned engineers immediately') === false && strpos($sch, '<input type="checkbox" id="njNotifyWa" style="display:none;" disabled>') !== false,
    'and still has no box to tick (D4): the message does not depend on one');
$master = (string)file_get_contents($root . '/cron/master.php');
is_(preg_match("/^\\s*'job_assign'\\s*=>/m", $master) === 0 && strpos($master, "// 'job_assign' => ['interval' => 300, 'script' => __DIR__ . '/job_assignment_notify.php'],") !== false,
    'cron/master.php still has job_assign commented out (path A stays off)');
is_(($new['extra']['tables'] ?? null) === [5, 5], 'the notifier\'s tables hold the day: five jobs known (Accept records the one it had never seen), five history rows (four message 1, one message 2)', json_encode($new['extra']['tables'] ?? null));

echo "\n6. South Sudan: the same day says the same things on 5.18.51 and on this tree\n";
$ss    = ['timezone' => 'Africa/Juba'];
$ssNew = SjScenario::run($root, $ss, 'sjday-ss', $ui);
$ssOld = $base !== null ? SjScenario::run($base, $ss, 'sjday-ss-old') : null;
is_(count($ssNew['texts']) === 11 && array_filter($ssNew['texts'], $isB) === [], 'South Sudan still sends its eleven, none of them release B\'s', count($ssNew['texts']) . ' texts');
is_(($ssNew['extra']['tables'] ?? null) === [0, 0], 'the two new tables stay empty there');
if ($ssOld) {
    is_($ssOld['texts'] === $ssNew['texts'], 'every message is 5.18.51\'s: same text, same number, same order', json_encode([$ssOld['texts'], $ssNew['texts']], JSON_UNESCAPED_UNICODE));
    is_($ssOld['log'] === $ssNew['log'], 'the Message Log is the same');
    is_($ssOld['whlog'] === $ssNew['whlog'], 'the webhook log is the same');
    is_($ssOld['mail'] === $ssNew['mail'] && count($ssNew['mail']) === 1, 'the customer e-mail is the same');
    is_($ssOld['crm'] === $ssNew['crm'], 'uCRM receives exactly the same requests — reads, writes and dates — in the same order');
    is_($ssOld['answers'] === $ssNew['answers'], 'and every answer is the same');
} else {
    skip_('the South Sudan comparison with 5.18.51');
}

// ── 7. Weakened copies ───────────────────────────────────────────────────────
if ($withMutants) {
    echo "\n7. Weakened copies of the code must each fail this test\n";
    $MUTANTS = [
        ['lib/JobMessages.php', '$m .= "New Job Has Been Assigned to You\n\n";', '$m .= "New Job Assigned to You\n\n";', 'message 1 reworded'],
        ['includes/api/api_scheduling.php', "\$msgEng .= \"Open My Jobs tab for details.\\n— DishNET Africa\";", "\$msgEng .= \"Open My Jobs for details.\\n— DishNET Africa\";",
         'South Sudan\'s "Job Accepted" reworded'],
        ['includes/api/api_scheduling.php', "\$msgLead .= \"\\nJob #{\$jobId} status → *In Progress*\\n— DishNET NOC\";", "\$msgLead .= \"\\nJob #{\$jobId} is now *In Progress*\\n— DishNET NOC\";",
         'the leader\'s "Job Accepted" reworded'],
        ['webhook.php', "            \$user = \$crm->get(\"users/{\$assignedUserId}\") ?? [];", "            \$user = [];", 'the customer e-mail\'s technician lookup dropped'],
        ['includes/api/api_scheduling.php', "            if (\$_sjUganda) {   // 5.18.52 (J4): the job notifier, from uCRM's answer", "            if (false) {   // 5.18.52 (J4): the job notifier, from uCRM's answer",
         'Bulk Dispatch not telling the engineer'],
        ['includes/api/api_scheduling.php', "        if (\$_sjUganda) {\n            \$_sjR = \$sjNotifier()->observe(\$jobId, 'reschedule',", "        if (false) {\n            \$_sjR = \$sjNotifier()->observe(\$jobId, 'reschedule',",
         'Reschedule not observed (its answer then reads an unset outcome)'],
        ['lib/JobNotifier.php', 'JobMessages::html($text), str_replace("\n", "\r\n", $text),', 'JobMessages::html($text), \'\',',
         'the e-mail\'s text part left to be made from its HTML'],
    ];
    foreach ($MUTANTS as [$rel, $old_, $new_, $label]) {
        [$tmp, $n] = sj_weakened_copy($root, $rel, $old_, $new_);
        if ($n !== 1) { is_(false, "weakened copy \"{$label}\": its anchor occurs once in {$rel}", "found {$n} times"); exec('rm -rf ' . escapeshellarg($tmp)); continue; }
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --root=' . escapeshellarg($tmp) . ' --no-mutants 2>&1', $out, $rc);
        $fails = array_values(array_filter($out, function ($l) { return strpos($l, '  FAIL ') === 0; }));
        is_($rc !== 0 && $fails !== [], "caught: {$label}", 'exit ' . $rc . ', ' . count($fails) . ' failure(s)');
        if ($fails) echo '         first: ' . trim(substr($fails[0], 7, 110)) . "\n";
        exec('rm -rf ' . escapeshellarg($tmp));
    }
}

printf("\n%d passed, %d failed, %d skipped\n", $pass, $fail, $skip);
exit($fail === 0 ? 0 : 1);
