<?php
declare(strict_types=1);
/**
 * test_job_notifications_off.php — 5.18.50 (docs/44 M6, release A): on Uganda no job-assignment WhatsApp is sent from
 * any of the four paths, and every other job message is what 5.18.49 sent, byte for byte.
 *
 * The same day of job traffic (fixtures/staff_jobs_scenario.php) runs as Uganda on 5.18.49 — taken from Git, commit
 * e076632 — and on this tree, and what each said is compared:
 *   1. the control: 5.18.49 sends all four assignment messages on this day — ＋ New Job, Bulk Dispatch, Reschedule and
 *      the job created in uCRM — so their absence below is M6, not a day that never reached them;
 *   2. this tree sends none of them and logs none in the Message Log; the three staff-app answers say so in words, and
 *      the webhook log says "WhatsApp skipped" where 5.18.49 said "notification sent";
 *   3. every other message — accepted, the leader's copy, task progress, all tasks done, completed, the admin's copy,
 *      the accountant's invoice request — is the same text to the same number in the same order, the Message Log
 *      agrees, and the customer's "installation scheduled" e-mail is byte for byte the same;
 *   4. the same writes reach uCRM in the same order; only their dates now carry Kampala's offset (J5);
 *   5. the ＋ New Job and Bulk Dispatch screens: no "Notify via WhatsApp" box, a plain statement instead;
 *   6. the job dispatch cron stays switched off;
 *   7. South Sudan: the same day on both trees says exactly the same things, the four assignment messages included;
 *   8. weakened copies of the code each fail this test.
 *
 * What this proves is what the plugin hands to WhatsApp. It is not proof of delivery to a phone.
 *
 *   php test_job_notifications_off.php [--root=DIR] [--no-mutants]
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
    return ['scheduling' => $s->page('admin', 'page=dashboard&tab=scheduling'), 'bulk' => $s->page('admin', 'page=dashboard&tab=bulk_dispatch')];
};
$assign   = function (array $run): array { return array_values(array_filter($run['texts'], ['SjScenario', 'isAssignment'])); };
$other    = function (array $run): array { return array_values(array_filter($run['texts'], function ($t) { return !SjScenario::isAssignment($t); })); };
$events   = function (array $run): array { return array_column($run['log'], 'event'); };
$logOther = function (array $run): array { return array_values(array_filter($run['log'], function ($l) { return !in_array($l['event'], SJ_ASSIGNMENT_EVENTS, true); })); };
$whOther  = function (array $run): array {
    return array_values(array_filter($run['whlog'], function ($l) { return strpos($l['message'], 'Job #911 notification sent') === false && strpos($l['message'], 'Job #911 — WhatsApp skipped') === false; }));
};
$writes   = function (array $run, bool $withDates): array {
    $out = [];
    foreach ($run['crm'] as $q) {
        if ($q['method'] === 'GET') continue;
        $b = is_array($q['body']) ? $q['body'] : [];
        if (!$withDates) unset($b['date']);
        $out[] = [$q['method'], $q['path'], $b];
    }
    return $out;
};
$data = function (array $run, string $step): array { return is_array($run['answers'][$step][1]['data'] ?? null) ? $run['answers'][$step][1]['data'] : []; };

[$base, $why] = sj_baseline_tree();
if ($base !== null) {
    $manifest = (string)@file_get_contents($base . '/manifest.json');
    is_(preg_match('/"version":\s*"5\.18\.49"/', $manifest) === 1, 'the baseline is 5.18.49, from Git (' . SJ_BASELINE . ')');
} else {
    skip_("the 5.18.49 comparison: {$why}");
}

// ═════════════════════════════════════════════════════════════════════════════
$ug    = ['tenant_profile' => 'uganda', 'timezone' => 'Africa/Kampala'];
$new   = SjScenario::run($root, $ug, 'sjm6-ug', $ui);
$old   = $base !== null ? SjScenario::run($base, $ug, 'sjm6-ug-old') : null;
$every = array_map(function ($r) { return $r[0]; }, $new['answers']);
is_(array_values(array_unique($every)) === [200], 'the day ran: all eight steps answered 200 on this tree', json_encode($every));

echo "\n1. The control: 5.18.49, as Uganda, sends the four job-assignment messages on this day\n";
if ($old) {
    $oa = $assign($old);
    is_(count($oa) === 4 && count(array_unique(array_column($oa, 'number'))) === 1 && $oa[0]['number'] === '256700000111',
        'four assignment messages, all to the technician', json_encode(array_column($oa, 'number')));
    is_(strpos($oa[0]['text'], 'New Job Assigned to You') !== false && strpos($oa[0]['text'], 'Sandbox installation') !== false, '  ＋ New Job', $oa[0]['text'] ?? '');
    is_(strpos($oa[1]['text'], 'New Job Assigned to You') !== false && strpos($oa[1]['text'], 'Sandbox batch') !== false, '  Bulk Dispatch', $oa[1]['text'] ?? '');
    is_(strpos($oa[2]['text'], '*Job #905 Rescheduled*') !== false, '  Reschedule', $oa[2]['text'] ?? '');
    is_(strpos($oa[3]['text'], '🔧 *New Job Assigned*') !== false && strpos($oa[3]['text'], 'Starlink installation') !== false, '  a job created in uCRM (job.add)', $oa[3]['text'] ?? '');
    is_(array_values(array_intersect($events($old), SJ_ASSIGNMENT_EVENTS)) === ['ops_scheduling_job_assigned', 'ops_scheduling_job_assigned', 'ops_scheduling_rescheduled', 'job_assigned'],
        'and the Message Log records the four', json_encode($events($old)));
    is_(!empty($data($old, 'create')['jobs'][0]['notified']) && !isset($data($old, 'create')['whatsapp']), 'and ＋ New Job answered "notified"');
} else {
    skip_('the 5.18.49 control');
}

echo "\n2. This tree, as Uganda: none of the four, and each answer says so\n";
is_($assign($new) === [], 'no job-assignment message reaches WhatsApp', json_encode($assign($new), JSON_UNESCAPED_UNICODE));
is_(array_intersect($events($new), SJ_ASSIGNMENT_EVENTS) === [], 'and the Message Log records none', json_encode($events($new)));
foreach (['create' => '＋ New Job', 'bulk' => 'Bulk Dispatch', 'reschedule' => 'Reschedule'] as $step => $label) {
    $d = $data($new, $step);
    is_(($d['whatsapp'] ?? null) === 'not_sent' && ($d['whatsapp_note'] ?? null) === SjScenario::NOTE,
        "{$label} answers whatsapp: not_sent, with \"" . SjScenario::NOTE . '"', json_encode($d, JSON_UNESCAPED_UNICODE));
}
is_(($data($new, 'create')['jobs'][0]['notified'] ?? null) === false, '＋ New Job does not mark the engineer "notified" (the 📱 in its result)', json_encode($data($new, 'create')['jobs'] ?? null));
is_((int)($data($new, 'create')['created'] ?? 0) === 1 && (int)($data($new, 'bulk')['created'] ?? 0) === 1 && !empty($data($new, 'reschedule')['rescheduled']),
    'and the jobs are still created and rescheduled');
$wh = array_column($new['whlog'], 'message');
is_(in_array('Job #911 — WhatsApp skipped: job notifications are not switched on yet', $wh, true), 'the job created in uCRM: the webhook log says "WhatsApp skipped"', json_encode($wh, JSON_UNESCAPED_UNICODE));
is_(!preg_grep('/Job #911 notification sent/', $wh), 'and never "notification sent"');
if ($old) is_(in_array('Job #911 notification sent to Sandbox Tech', array_column($old['whlog'], 'message'), true), 'control: 5.18.49 wrote "notification sent" there');

echo "\n3. Every other message is 5.18.49's, byte for byte\n";
$names = ['✅ *Job Accepted*' => 'accepted, to the engineer', '🔔 *Job Accepted*' => 'accepted, to the support leader', 'Great job completing Task 1' => 'a task done',
          'All 2 tasks completed' => 'all tasks done', '*Job Completed Successfully!*' => 'completed, to the engineer',
          'completed by *Sandbox Tech*' => 'completed, to the admin', '*New Invoice Needed*' => 'the invoice request, to the accountant'];
$seen = [];
foreach ($other($new) as $t) foreach ($names as $needle => $label) if (strpos($t['text'], $needle) !== false) $seen[] = $label;
is_(count($other($new)) === 7 && $seen === array_values($names), 'this tree sends the seven: ' . implode('; ', array_values($names)), json_encode($seen, JSON_UNESCAPED_UNICODE));
if ($old) {
    is_($other($old) === $other($new), 'each is the same text to the same number, in the same order, as 5.18.49',
        json_encode(['5.18.49' => $other($old), 'this' => $other($new)], JSON_UNESCAPED_UNICODE));
    is_($logOther($old) === $new['log'], 'the Message Log rows are the same, event for event');
    is_(count($new['mail']) === 1 && $old['mail'] === $new['mail'], 'the customer\'s "installation scheduled" e-mail: the same message, byte for byte (its date, id and boundaries aside)',
        count($old['mail']) . ' vs ' . count($new['mail']));
    is_($whOther($old) === $whOther($new), 'the webhook log is the same, line for line, but for the one WhatsApp line', json_encode([$whOther($old), $whOther($new)], JSON_UNESCAPED_UNICODE));
} else {
    skip_('the byte-for-byte comparison with 5.18.49');
}

echo "\n4. uCRM receives the same writes\n";
if ($old) {
    is_($writes($old, false) === $writes($new, false), 'the same writes, in the same order (dates aside)', json_encode([$writes($old, false), $writes($new, false)], JSON_UNESCAPED_UNICODE));
    $dates = function (array $run) use ($writes): array {
        return array_values(array_filter(array_map(function ($w) { return $w[2]['date'] ?? null; }, $writes($run, true))));
    };
    is_($dates($old) === ['2026-10-07T09:00:00.000Z', '2026-10-09T09:00:00.000Z', '2026-10-08 11:00']
        && $dates($new) === ['2026-10-07T09:00:00+0300', '2026-10-09T09:00:00+0300', '2026-10-08T11:00:00+0300'],
        'and the three dates now carry Kampala\'s offset (J5), where 5.18.49 sent UTC or no zone', json_encode([$dates($old), $dates($new)]));
} else {
    skip_('the uCRM comparison with 5.18.49');
}

echo "\n5. The screens say it\n";
$sch = (string)($new['extra']['scheduling'] ?? '');
is_(strpos($sch, 'No WhatsApp message is sent for jobs yet') !== false, '＋ New Job says "No WhatsApp message is sent for jobs yet"', strlen($sch) . ' bytes');
is_(strpos($sch, 'Notify assigned engineers immediately') === false && strpos($sch, '<input type="checkbox" id="njNotifyWa" style="display:none;" disabled>') !== false,
    'and has no "Notify via WhatsApp" box to tick');
is_(strpos($sch, "notify_wa:     0,") !== false && strpos($sch, "document.getElementById('njNotifyWa').checked ? 1 : 0") === false, 'its form sends notify_wa 0');
is_(strpos($sch, 'data.whatsapp_note') !== false && strpos($sch, 'd.data.whatsapp_note') !== false, 'the New Job and Reschedule results show the answer\'s note');
$bulk = (string)($new['extra']['bulk'] ?? '');
is_(strpos($bulk, 'res.whatsapp_note') !== false, 'Bulk Dispatch\'s result shows it too', strlen($bulk) . ' bytes');

echo "\n6. The job dispatch cron stays off\n";
$master = (string)file_get_contents($root . '/cron/master.php');
is_(preg_match("/^\\s*'job_assign'\\s*=>/m", $master) === 0 && strpos($master, "// 'job_assign' => ['interval' => 300, 'script' => __DIR__ . '/job_assignment_notify.php'],") !== false,
    'cron/master.php still has job_assign commented out');

echo "\n7. South Sudan: the same day says the same things on both trees\n";
$ss    = ['timezone' => 'Africa/Juba'];
$ssNew = SjScenario::run($root, $ss, 'sjm6-ss', $ui);
$ssOld = $base !== null ? SjScenario::run($base, $ss, 'sjm6-ss-old') : null;
is_(count($assign($ssNew)) === 4 && count($ssNew['texts']) === 11, 'South Sudan still sends all eleven, the four assignment messages among them', count($ssNew['texts']) . ' texts');
$ssSch = (string)($ssNew['extra']['scheduling'] ?? '');
is_(strpos($ssSch, 'Notify assigned engineers immediately') !== false && strpos($ssSch, 'No WhatsApp message is sent for jobs yet') === false,
    'and its New Job screen keeps the "Notify via WhatsApp" box');
if ($ssOld) {
    is_($ssOld['texts'] === $ssNew['texts'], 'every message is 5.18.49\'s: same text, same number, same order', json_encode([$ssOld['texts'], $ssNew['texts']], JSON_UNESCAPED_UNICODE));
    is_($ssOld['log'] === $ssNew['log'], 'the Message Log is the same');
    is_($ssOld['whlog'] === $ssNew['whlog'], 'the webhook log is the same');
    is_($ssOld['mail'] === $ssNew['mail'] && count($ssNew['mail']) === 1, 'the customer e-mail is the same');
    is_($ssOld['crm'] === $ssNew['crm'], 'uCRM receives exactly the same requests — reads, writes and dates — in the same order');
    is_($ssOld['answers'] === $ssNew['answers'], 'and every answer is the same');
} else {
    skip_('the South Sudan comparison with 5.18.49');
}

// ── 8. Weakened copies ───────────────────────────────────────────────────────
if ($withMutants) {
    echo "\n8. Weakened copies of the code must each fail this test\n";
    $MUTANTS = [
        ['includes/api/api_scheduling.php', "if (!\$_sjUganda && \$notifyWa && \$eng && !empty(\$eng['phone'])) {", "if (\$notifyWa && \$eng && !empty(\$eng['phone'])) {",
         '＋ New Job sending on Uganda'],
        ['includes/api/api_scheduling.php', "if (\$custAssignee && !\$_sjUganda) {", "if (\$custAssignee) {", 'Bulk Dispatch sending on Uganda'],
        ['includes/api/api_scheduling.php', "if (!\$_sjUganda && !empty(\$techPhone)) {", "if (!empty(\$techPhone)) {", 'Reschedule sending on Uganda'],
        ['webhook.php', "            if (\$_whNoJobWa) {", "            if (false) {", 'a job created in uCRM sending on Uganda'],
        ['includes/api/api_scheduling.php', "'notified'     => (!\$_sjUganda && \$notifyWa && \$eng && !empty(\$eng['phone'])),",
         "'notified'     => (\$notifyWa && \$eng && !empty(\$eng['phone'])),", '＋ New Job claiming "notified"'],
        ['includes/api/api_scheduling.php', "\$msgEng .= \"Open My Jobs tab for details.\\n— DishNET Africa\";", "\$msgEng .= \"Open My Jobs for details.\\n— DishNET Africa\";",
         'an unchanged message reworded'],
        ['tabs/support/scheduling.php', "<?php if (\$_sjUganda): ?>\n    <input type=\"checkbox\" id=\"njNotifyWa\" style=\"display:none;\" disabled>",
         "<?php if (false): ?>\n    <input type=\"checkbox\" id=\"njNotifyWa\" style=\"display:none;\" disabled>", 'the New Job screen offering the box again'],
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
