<?php
declare(strict_types=1);
/**
 * test_job_messages.php — 5.18.52 (docs/44 J4, §16.12, §16.14): the job messages, the decision table, the log lines
 * and the sign-in return, as pure functions. No server, no network.
 *
 *   1. the two approved messages, byte for byte — and the copies here are the approved record's own: when the
 *      repository's docs/44 is at hand, its two code blocks are read and must equal them;
 *   2. the four other messages, byte for byte, as built (§16.7's bodies with message 1's greeting and footer);
 *   3. the rules around them: Kampala time, a line only when uCRM has the value (T4.13), one value cannot add a line,
 *      plain text;
 *   4. §5.2's table (JobNotifier::decide): every change, and every non-change;
 *   5. uCRM's answer: a missing assignee field is never "unassigned"; times to UTC;
 *   6. the webhook-log lines against WA Events' own rule (J7), and the badge's; no number, no text in them;
 *   7. the notes the staff screens show;
 *   8. the sign-in return: only a job number is kept, it returns once, within half an hour, to a fixed address;
 *   9. migration 075 on an empty database: two tables and an index, nothing else touched; 076 adds the e-mail's two
 *      columns and nothing else;
 *  10. the notifier's source: support number, CLASS_STAFF, no uCRM user record, nothing from a webhook body; the e-mail
 *      to the staff account's own address, after the claim;
 *  11. the e-mail copy (§16.16): its subject for each message, and an HTML part that is the text and nothing else.
 *
 *   php test_job_messages.php [--root=DIR]
 */
$opt  = getopt('', ['root:']);
$root = isset($opt['root']) ? rtrim((string)$opt['root'], '/') : dirname(__DIR__);
require_once $root . '/lib/JobMessages.php';
require_once $root . '/lib/JobNotifier.php';
require_once $root . '/lib/JobReturn.php';

$pass = 0; $fail = 0; $skip = 0;
function is_(bool $c, string $m, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; }
    else    { $fail++; echo "  FAIL {$m}" . ($d !== '' ? "\n       {$d}" : '') . "\n"; }
}
function skip_(string $m): void { global $skip; $skip++; echo "  skip {$m}\n"; }

$kla = new DateTimeZone('Africa/Kampala');
// The made-up job of docs/44 §16.12. Kampala 09:00 on 6 October is 06:00 UTC.
$f = [
    'name' => 'Grace', 'brand' => 'DishNet Africa', 'job_id' => 950, 'title' => 'Starlink installation',
    'when' => JobMessages::when('2026-10-06 06:00', $kla), 'was' => '',
    'client_name' => 'Test Client', 'client_id' => 1234, 'client_phone' => '+256 700 000 000',
    'address' => 'Plot 1 Test Road, Kampala', 'link' => '<link to Job #950 in the staff app>',
    'support' => '<Uganda support number>', 'website' => 'dishnetuganda.com',
];

// ── 1. The two approved messages ─────────────────────────────────────────────
echo "\n1. The two approved messages (docs/44 §16.12), byte for byte\n";
$APPROVED_1 = "Hi Grace. This is DishNet Africa.\n\nNew Job Has Been Assigned to You\n\nStarlink installation\n📅 Date: 06.10.2026 09:00 am\n"
    . "👤 Client: Test Client (ID:1234)\n📞 Mobile: +256 700 000 000\n📍 Address: Plot 1 Test Road, Kampala\n\n---\n"
    . "Please click the link below to accept this job:\n\n✅ ACCEPT JOB:\n<link to Job #950 in the staff app>\n\n"
    . "Once you accept, we will send you the completion link.\n\nFor any questions, just reach out here.\n📞 <Uganda support number>\n🌐 dishnetuganda.com";
$APPROVED_2 = "Hi Grace. This is DishNet Africa.\n\nThank you for accepting the job! ✅\n\nStarlink installation\n📅 Date: 06.10.2026 09:00 am\n"
    . "👤 Client: Test Client (ID:1234)\n\n✅ JOB COMPLETED:\n<link to Job #950>\n"
    . "Press Complete there when the work is finished. The same page lets you reschedule or add a comment.\n\n📞 <Uganda support number>";
is_(JobMessages::assigned($f) === $APPROVED_1, 'message 1 (ACCEPT JOB) is the approved text', json_encode(JobMessages::assigned($f), JSON_UNESCAPED_UNICODE));
is_(JobMessages::accepted(['link' => '<link to Job #950>'] + $f) === $APPROVED_2, 'message 2 (JOB COMPLETED) is the approved text',
    json_encode(JobMessages::accepted(['link' => '<link to Job #950>'] + $f), JSON_UNESCAPED_UNICODE));

$doc = dirname($root) . '/docs/44-j1-j8-implementation-specification-2026-09-27.md';
$src = is_file($doc) ? (string)file_get_contents($doc) : '';
$a = strpos($src, '### 16.12 '); $b = strpos($src, '### 16.13 ');
if ($a !== false && $b !== false && ($c = strpos($src, '**Approved — the two messages**', $a)) !== false && $c < $b) {
    preg_match_all('/```\n(.*?)\n```/s', substr($src, $c, $b - $c), $m);
    is_(count($m[1]) >= 2 && $m[1][0] === $APPROVED_1, 'and this copy of message 1 is docs/44 §16.12\'s own code block', json_encode($m[1][0] ?? null, JSON_UNESCAPED_UNICODE));
    is_(count($m[1]) >= 2 && $m[1][1] === $APPROVED_2, 'and this copy of message 2 is docs/44 §16.12\'s own code block', json_encode($m[1][1] ?? null, JSON_UNESCAPED_UNICODE));
} else {
    skip_('the approved record itself: docs/44 is not beside this plugin tree (' . $doc . ')');
}

// ── 2. The other four ────────────────────────────────────────────────────────
echo "\n2. The other four, byte for byte, as built\n";
$FOOT = "For any questions, just reach out here.\n📞 <Uganda support number>\n🌐 dishnetuganda.com";
is_(JobMessages::reassignedAway(['name' => 'Peter'] + $f) === "Hi Peter. This is DishNet Africa.\n\n↩️ Job #950 is no longer assigned to you\n\n"
    . "Starlink installation\n📅 Date: 06.10.2026 09:00 am\nIt has been given to a colleague. Please do not go.\n\n" . $FOOT,
    'no longer assigned, after a reassignment (D3)', json_encode(JobMessages::reassignedAway(['name' => 'Peter'] + $f), JSON_UNESCAPED_UNICODE));
$nt = ['when' => JobMessages::when('2026-10-07 08:00', $kla), 'was' => JobMessages::when('2026-10-06 06:00', $kla)] + $f;
is_(JobMessages::newTime($nt) === "Hi Grace. This is DishNet Africa.\n\n📅 Job #950 has a new time\n\nStarlink installation\n"
    . "Now: 07.10.2026 11:00 am\nWas: 06.10.2026 09:00 am\n\n" . $FOOT, 'a new time', json_encode(JobMessages::newTime($nt), JSON_UNESCAPED_UNICODE));
is_(JobMessages::removed($f) === "Hi Grace. This is DishNet Africa.\n\n↩️ Job #950 is no longer assigned to you\n\nStarlink installation\n"
    . "📅 Date: 06.10.2026 09:00 am\nPlease do not go.\n\n" . $FOOT, 'removed, the job staying with nobody (D3)', json_encode(JobMessages::removed($f), JSON_UNESCAPED_UNICODE));
$cx = ['was' => JobMessages::when('2026-10-06 06:00', $kla), 'when' => ''] + $f;
is_(JobMessages::cancelled($cx) === "Hi Grace. This is DishNet Africa.\n\n❌ Job #950 has been cancelled\n\nStarlink installation\n"
    . "📅 Was: 06.10.2026 09:00 am\nPlease do not go.\n\n" . $FOOT, 'cancelled, the job deleted in uCRM (D3)', json_encode(JobMessages::cancelled($cx), JSON_UNESCAPED_UNICODE));

// ── 3. The rules around them ─────────────────────────────────────────────────
echo "\n3. The rules around them\n";
is_(JobMessages::when('2026-10-06 11:30', $kla) === '06.10.2026 02:30 pm', 'Kampala time, dd.mm.yyyy hh:mm pm');
is_(JobMessages::when('2026-10-05 21:00', $kla) === '06.10.2026 12:00 am', 'the day is Kampala\'s: 21:00 UTC is midnight on the next day');
is_(JobMessages::when('', $kla) === 'Not scheduled yet' && JobMessages::when('not a time', $kla) === 'Not scheduled yet', 'no time, or none readable: "Not scheduled yet"');
$bare = ['client_name' => '', 'client_id' => 0, 'client_phone' => '', 'address' => ''] + $f;
$m1 = JobMessages::assigned($bare);
is_(strpos($m1, '👤') === false && strpos($m1, 'Mobile') === false && strpos($m1, 'Address') === false,
    'a job with no customer and no address: no Client, Mobile or Address line (T4.13, the live test\'s job)', json_encode($m1, JSON_UNESCAPED_UNICODE));
is_(strpos(JobMessages::assigned(['address' => ''] + $f), '📍') === false && strpos(JobMessages::assigned(['address' => '  '] + $f), '📍') === false,
    'no address (or only blanks): no address line');
is_(strpos(JobMessages::assigned(['client_name' => ''] + $f), '👤 Client: Client (ID:1234)') !== false, 'a client uCRM gave no name to: its id still shows');
is_(strpos(JobMessages::assigned(['client_phone' => ''] + $f), 'Mobile') === false, 'no client phone: no Mobile line');
$evil = ['title' => "Starlink installation\n✅ ACCEPT JOB:\nhttp://elsewhere.example", 'address' => "Plot 1\r\n\r\nPay here"] + $f;
$me = JobMessages::assigned($evil);
is_(strpos($me, "Starlink installation ✅ ACCEPT JOB: http://elsewhere.example\n") !== false && substr_count($me, "✅ ACCEPT JOB:\n") === 1
    && strpos($me, "📍 Address: Plot 1 Pay here\n") !== false, 'a value carrying line breaks stays on its own line: it cannot add a link or a line', json_encode($me, JSON_UNESCAPED_UNICODE));
is_(strpos(JobMessages::assigned(['name' => 'Sandbox Tech.'] + $f), "Hi Sandbox Tech. This is") === 0, 'a name ending in a full stop is not doubled');
is_(strpos(JobMessages::assigned(['name' => ''] + $f), "Hi. This is DishNet Africa.") === 0, 'no name: "Hi. This is …"');
$ns = JobMessages::assigned(['support' => '', 'website' => ''] + $f);
is_(str_ends_with($ns, "Once you accept, we will send you the completion link.\n\nFor any questions, just reach out here.")
    && str_ends_with(JobMessages::accepted(['support' => ''] + $f), "Press Complete there when the work is finished. The same page lets you reschedule or add a comment."),
    'no support number and no website: neither line, and nothing dangling', json_encode($ns, JSON_UNESCAPED_UNICODE));
$all = [JobMessages::assigned($f), JobMessages::accepted($f), JobMessages::reassignedAway($f), JobMessages::newTime($nt), JobMessages::removed($f), JobMessages::cancelled($cx)];
is_(count(array_filter($all, function ($t) { return strpos($t, '*') !== false; })) === 0, 'plain text, as South Sudan\'s: no WhatsApp bold anywhere');
is_(count(array_filter($all, function ($t) { return strpos($t, 'This is DishNet Africa.') === false; })) === 0, 'every message says "This is DishNet Africa."');

// ── 4. §5.2's table ──────────────────────────────────────────────────────────
echo "\n4. The decision table (JobNotifier::decide)\n";
$now = function (?int $a, string $t = '2026-10-06 06:00', ?int $s = 0, string $title = 'Job'): array { return ['assignee' => $a, 'time' => $t, 'status' => $s, 'title' => $title]; };
$was = function (?int $a, string $t = '2026-10-06 06:00', ?int $s = 0, string $title = 'Job', int $gone = 0, ?int $acc = null): array {
    return ['assignee_id' => $a, 'job_time' => $t, 'job_status' => $s, 'title' => $title, 'gone' => $gone, 'accepted_by' => $acc];
};
$kinds = function (array $p): string { return implode(',', array_map(function ($m) { return $m['kind'] . '→' . $m['to']; }, $p['messages'])); };
$row = function (?array $w, ?array $n) use ($kinds): string { $p = JobNotifier::decide($w, $n); return ($p['event'] ?? '-') . '|' . $kinds($p) . '|' . ($p['state'] === null ? 'nostate' : 'state'); };
$CASES = [
    ['never seen, deleted',                        null,                          null,                         '-||nostate'],
    ['never told, an assignee',                    null,                          $now(1099),                   'assigned|assigned→1099|state'],
    ['never told, nobody assigned',                null,                          $now(null),                   '-||state'],
    ['never told, already closed',                 null,                          $now(1099, '2026-10-06 06:00', 2), 'closed||state'],
    ['the same again (a redelivered event)',       $was(1099),                    $now(1099),                   '-||nostate'],
    ['only the title',                             $was(1099),                    $now(1099, '2026-10-06 06:00', 0, 'New'), '-||state'],
    ['only accepted (status 0 → 1)',               $was(1099),                    $now(1099, '2026-10-06 06:00', 1), '-||state'],
    ['another engineer',                           $was(1099),                    $now(1100),                   'reassigned|assigned→1100,reassigned_away→1099|state'],
    ['another engineer and another time',          $was(1099),                    $now(1100, '2026-10-07 08:00'), 'reassigned|assigned→1100,reassigned_away→1099|state'],
    ['the engineer removed',                       $was(1099),                    $now(null),                   'unassigned|removed→1099|state'],
    ['an engineer where there was none',           $was(null),                    $now(1099),                   'assigned|assigned→1099|state'],
    ['another time',                               $was(1099),                    $now(1099, '2026-10-07 08:00'), 'rescheduled|new_time→1099|state'],
    ['a time taken away',                          $was(1099),                    $now(1099, ''),               'rescheduled|new_time→1099|state'],
    ['another time, nobody assigned',              $was(null),                    $now(null, '2026-10-07 08:00'), '-||state'],
    ['closed',                                     $was(1099, '2026-10-06 06:00', 1), $now(1099, '2026-10-06 06:00', 2), 'closed||state'],
    ['closed, seen again',                         $was(1099, '2026-10-06 06:00', 2), $now(1099, '2026-10-06 06:00', 2), '-||nostate'],
    ['closed, and moved while closed',             $was(1099, '2026-10-06 06:00', 2), $now(1100, '2026-10-07 08:00', 2), '-||state'],
    ['deleted',                                    $was(1099),                    null,                         'cancelled|cancelled→1099|state'],
    ['deleted, seen again',                        $was(1099, '2026-10-06 06:00', 0, 'Job', 1), null,             '-||nostate'],
    ['deleted after it was closed',                $was(1099, '2026-10-06 06:00', 2), null,                     'cancelled||state'],
    ['deleted, nobody assigned',                   $was(null),                    null,                         'cancelled||state'],
    ['back after a deletion (a new start)',        $was(1099, '2026-10-06 06:00', 0, 'Job', 1), $now(1099),       'assigned|assigned→1099|state'],
];
foreach ($CASES as [$label, $w, $n, $want]) is_($row($w, $n) === $want, "{$label}: {$want}", $row($w, $n));
$st = function (?array $w, ?array $n) { return JobNotifier::decide($w, $n)['state']; };
$abA = $st(null, $now(1099)); $abB = $st($abA + ['assignee_id' => 1099, 'job_time' => $abA['time'], 'job_status' => 0, 'title' => 'Job', 'gone' => 0], $now(1100));
$p3 = JobNotifier::decide(['assignee_id' => 1100, 'job_time' => $abB['time'], 'job_status' => 0, 'title' => 'Job', 'gone' => 0, 'accepted_by' => null], $now(1099));
is_($p3['event'] === 'reassigned' && $kinds($p3) === 'assigned→1099,reassigned_away→1100',
    'A → B → A: the third change messages A again — the state is compared with the last one told, so no "already sent" key blocks it');
is_(($st($was(1099, '2026-10-06 06:00', 1, 'Job', 0, 1099), $now(1099, '2026-10-07 08:00', 1))['accepted_by'] ?? null) === 1099,
    'the completion-link claim stays with the same engineer through a new time');
$cl = $st($was(1099, '2026-10-06 06:00', 1, 'Job', 0, 1099), $now(1100, '2026-10-06 06:00', 1));
is_(is_array($cl) && array_key_exists('accepted_by', $cl) && $cl['accepted_by'] === null, 'and is cleared when the job goes to another engineer', json_encode($cl));
$cp = JobNotifier::decide($was(1099, '2026-10-06 06:00', 0, 'Old title'), null);
is_(($cp['messages'][0]['title'] ?? '') === 'Old title' && ($cp['messages'][0]['was'] ?? '') === '2026-10-06 06:00',
    'the "cancelled" notice carries the title and time last told: uCRM\'s job is gone by then');
$rp = JobNotifier::decide($was(1099), $now(1099, '2026-10-07 08:00'));
is_(($rp['messages'][0]['was'] ?? '') === '2026-10-06 06:00' && $rp['from_time'] === '2026-10-06 06:00' && $rp['to_time'] === '2026-10-07 08:00',
    '"Was" is the time last told, not what a browser said');

// ── 5. uCRM's answer ─────────────────────────────────────────────────────────
echo "\n5. uCRM's answer\n";
is_(!JobNotifier::complete(['id' => 1, 'date' => null, 'status' => 0]), 'an answer without assignedUserId is not used (V4): it could only read as "unassigned"');
is_(JobNotifier::complete(['id' => 1, 'assignedUserId' => null, 'date' => null, 'status' => 0]), 'an answer that says nobody (null) is used');
is_(!JobNotifier::complete(['id' => 1, 'assignedUserId' => 5, 'status' => 0]) && !JobNotifier::complete(['id' => 1, 'assignedUserId' => 5, 'date' => null]),
    'nor one without the time or the status');
is_(JobNotifier::utc('2026-10-07T09:00:00+0300') === '2026-10-07 06:00' && JobNotifier::utc('2026-10-07T06:00:00.000Z') === '2026-10-07 06:00',
    'uCRM\'s time is kept as UTC, whatever offset it carries');
is_(JobNotifier::utc(null) === '' && JobNotifier::utc('') === '' && JobNotifier::utc('nonsense') === '', 'no time, or an unreadable one: empty');

// ── 6. The webhook log, as WA Events and its badge read it (J7) ──────────────
echo "\n6. The webhook-log lines, by WA Events' own rule\n";
$fq = (string)@file_get_contents($root . '/tabs/engage/failed_queue.php');
if (preg_match('/function fq_classify_webhook\(array \$entry\): string \{.*?\n\}/s', $fq, $fm)) {
    eval($fm[0]);
    $nav = (string)@file_get_contents($root . '/includes/navigation.php');
    $badgeRule = "if (str_contains(\$_msg,'sent ') || str_contains(\$_msg,'sent to') || str_contains(\$_msg,'notification ')) \$_waCrmDelivered++;\n"
               . "                elseif (str_contains(\$_msg,'failed') || str_contains(\$_msg,'error')) \$_waCrmFailed++;";
    is_(strpos($nav, $badgeRule) !== false, 'the badge\'s rule is the one read here (navigation.php)');
    $badge = function (string $m): string {
        $m = strtolower($m);
        if (str_contains($m, 'sent ') || str_contains($m, 'sent to') || str_contains($m, 'notification ')) return 'sent';
        if (str_contains($m, 'failed') || str_contains($m, 'error')) return 'failed';
        return '';
    };
    $msg = function (string $o, string $d, string $k = 'assigned'): array { return ['message' => $k, 'outcome' => $o, 'detail' => $d, 'staff_id' => 3]; };
    $LINES = [
        ['sent',        ['event' => 'assigned', 'outcome' => 'sent', 'messages' => [$msg('sent', 'staff account #3')]],                         'sent',    'sent'],
        ['failed',      ['event' => 'assigned', 'outcome' => 'failed', 'messages' => [$msg('failed', 'staff account #3')]],                     'failed',  'failed'],
        ['no account',  ['event' => 'assigned', 'outcome' => 'no_staff_account', 'messages' => [$msg('no_staff_account', 'no staff account is linked to uCRM user #1099')]], 'skipped', ''],
        ['two accounts',['event' => 'assigned', 'outcome' => 'ambiguous_staff_account', 'messages' => [$msg('ambiguous_staff_account', '2 staff accounts are linked to uCRM user #1099')]], 'skipped', ''],
        ['no number',   ['event' => 'assigned', 'outcome' => 'no_usable_number', 'messages' => [$msg('no_usable_number', 'staff account #3 has no usable number')]], 'skipped', ''],
        ['recorded',    ['event' => 'closed', 'outcome' => 'recorded', 'detail' => 'closed in uCRM', 'messages' => []],                        'info',    ''],
        ['no change',   ['event' => null, 'outcome' => 'no_change', 'detail' => 'nothing to send', 'messages' => []],                          'info',    ''],
        ['unverified',  ['event' => null, 'outcome' => 'unverified', 'detail' => 'uCRM did not answer for the job', 'messages' => []],         'info',    ''],
    ];
    foreach ($LINES as [$label, $r, $wantFq, $wantBadge]) {
        $l = JobNotifier::logLines(950, $r);
        is_(count($l) === 1 && fq_classify_webhook(['message' => $l[0], 'event' => 'job.add']) === $wantFq && $badge($l[0]) === $wantBadge,
            "{$label}: \"{$l[0]}\" — WA Events files it {$wantFq}" . ($wantBadge !== '' ? ", the badge counts it {$wantBadge}" : ', the badge does not count it'));
    }
    $two = JobNotifier::logLines(950, ['event' => 'reassigned', 'outcome' => 'sent', 'messages' => [$msg('sent', 'staff account #3'), $msg('no_staff_account', 'no staff account is linked to uCRM user #1000', 'reassigned_away')]]);
    is_($two === ['Job #950 (reassigned: new engineer) — WhatsApp sent to staff account #3',
                  'Job #950 (reassigned: previous engineer) — WhatsApp skipped: no staff account is linked to uCRM user #1000'],
        'a reassignment writes one line per message, each filed on its own', json_encode($two, JSON_UNESCAPED_UNICODE));
    // Since §16.16 a line also says what became of the e-mail copy, in words neither rule looks for: the line is still
    // filed, and counted, by its WhatsApp alone.
    $withMail = function (string $o, string $d, string $email) use ($msg): array {
        return ['event' => 'assigned', 'outcome' => $o, 'messages' => [$msg($o, $d) + ['email' => $email, 'email_detail' => 'staff account #3']]];
    };
    $EMAIL = [
        ['sent, e-mail sent',          $withMail('sent', 'staff account #3', 'sent'),                                  'sent',    'sent',   '; e-mail handed to the mail server'],
        ['sent, e-mail refused',       $withMail('sent', 'staff account #3', 'failed'),                                'sent',    'sent',   '; e-mail not taken by the mail server'],
        ['sent, no address',           $withMail('sent', 'staff account #3', 'no_email'),                              'sent',    'sent',   '; no e-mail: the staff account has no usable address'],
        ['sent, no mail server',       $withMail('sent', 'staff account #3', 'not_configured'),                        'sent',    'sent',   '; no e-mail: the plugin has no mail server set up'],
        ['failed, e-mail sent',        $withMail('failed', 'staff account #3', 'sent'),                                'failed',  'failed', '; e-mail handed to the mail server'],
        ['no number, e-mail sent',     $withMail('no_usable_number', 'staff account #3 has no usable number', 'sent'), 'skipped', '',       '; e-mail handed to the mail server'],
        ['no number, e-mail refused',  $withMail('no_usable_number', 'staff account #3 has no usable number', 'failed'), 'skipped', '',     '; e-mail not taken by the mail server'],
    ];
    foreach ($EMAIL as [$label, $r, $wantFq, $wantBadge, $clause]) {
        $l = JobNotifier::logLines(950, $r);
        is_(count($l) === 1 && substr($l[0], -strlen($clause)) === $clause && fq_classify_webhook(['message' => $l[0], 'event' => 'job.add']) === $wantFq && $badge($l[0]) === $wantBadge,
            "{$label}: \"{$l[0]}\" — still filed {$wantFq}" . ($wantBadge !== '' ? ", counted {$wantBadge}" : ', not counted'));
    }
    is_(fq_classify_webhook(['message' => 'Job #950 (assigned) — WhatsApp sent to staff account #3; e-mail failed', 'event' => 'job.add']) === 'failed'
        && $badge('Job #950 (assigned) — WhatsApp skipped: staff account #3 has no usable number; e-mail sent to staff account #3') === 'sent',
        'control: had the e-mail\'s words been "failed" or "sent to", the WhatsApp that went would be filed failed and the one skipped counted delivered');
    $allLines = [];
    foreach (array_merge($LINES, $EMAIL) as $x) $allLines = array_merge($allLines, JobNotifier::logLines(950, $x[1]));
    is_(!preg_grep('/\d{9,}/', $allLines) && !preg_grep('/Hi |ACCEPT JOB|This is/', $allLines) && !preg_grep('/@/', $allLines),
        'no line carries a phone number, an e-mail address or a message\'s text');
} else {
    is_(false, 'fq_classify_webhook() is found in tabs/engage/failed_queue.php');
}

// ── 7. The notes the staff screens show ──────────────────────────────────────
echo "\n7. The notes on the staff screens\n";
$NOTES = [
    [['outcome' => 'sent', 'messages' => [['message' => 'assigned']]],          'WhatsApp sent to the engineer, with the link to accept the job.'],
    [['outcome' => 'sent', 'messages' => [['message' => 'new_time']]],          'WhatsApp with the new time sent to the engineer on the job.'],
    [['outcome' => 'sent', 'messages' => [['message' => 'accepted']]],          'WhatsApp with the completion link sent to the engineer on the job.'],
    [['outcome' => 'failed'],                                                   'The WhatsApp message to the engineer failed. WA Events and the failure queue show it.'],
    [['outcome' => 'no_staff_account'],                                         'No WhatsApp was sent: no active staff account is linked to the job\'s uCRM user.'],
    [['outcome' => 'ambiguous_staff_account'],                                  'No WhatsApp was sent: more than one staff account is linked to the job\'s uCRM user.'],
    [['outcome' => 'no_usable_number'],                                         'No WhatsApp was sent: the engineer\'s staff account has no usable phone number.'],
    [['outcome' => 'sending'],                                                  'uCRM\'s own notice of this change is sending the WhatsApp message. WA Events shows the result.'],
    [['outcome' => 'no_change', 'detail' => 'nothing to send', 'assignee' => null], 'No WhatsApp was sent: nobody is assigned to the job.'],
    [['outcome' => 'no_change', 'detail' => 'nothing to send', 'assignee' => 1099], 'No WhatsApp was sent: nothing the engineer needs to hear about changed.'],
    [['outcome' => 'no_change', 'detail' => 'the job was not waiting to be accepted', 'assignee' => 1099], 'No WhatsApp was sent: the job was not waiting to be accepted.'],
    [['outcome' => 'unverified', 'detail' => 'x'],                              'No WhatsApp was sent: the job could not be read back from uCRM. Its next change catches up.'],
];
foreach ($NOTES as [$r, $want]) is_(JobNotifier::note($r) === $want, "{$r['outcome']}: \"{$want}\"", JobNotifier::note($r));
// Since §16.16 the note goes on to say what became of the e-mail copy; a message with no e-mail recorded says nothing more.
$E = function (string $o, ?string $email, string $k = 'assigned'): array { return ['outcome' => $o, 'messages' => [['message' => $k, 'email' => $email]]]; };
$MAILNOTES = [
    [$E('sent', 'sent'),                         'WhatsApp sent to the engineer, with the link to accept the job. The same message went to the engineer\'s e-mail.'],
    [$E('sent', 'sent', 'new_time'),             'WhatsApp with the new time sent to the engineer on the job. The same message went to the engineer\'s e-mail.'],
    [$E('sent', 'failed'),                       'WhatsApp sent to the engineer, with the link to accept the job. The e-mail copy was not taken by the mail server.'],
    [$E('no_usable_number', 'sent'),             'No WhatsApp was sent: the engineer\'s staff account has no usable phone number. The same message went to the engineer\'s e-mail.'],
    [$E('failed', 'no_email'),                   'The WhatsApp message to the engineer failed. WA Events and the failure queue show it. No e-mail copy: the engineer\'s staff account has no usable e-mail address.'],
    [$E('sent', 'not_configured', 'accepted'),   'WhatsApp with the completion link sent to the engineer on the job. No e-mail copy: no mail server is set up for the plugin.'],
    [$E('no_staff_account', null),               'No WhatsApp was sent: no active staff account is linked to the job\'s uCRM user.'],
];
foreach ($MAILNOTES as [$r, $want]) is_(JobNotifier::note($r) === $want, "{$r['outcome']}, e-mail " . ($r['messages'][0]['email'] ?? 'none') . ": \"{$want}\"", JobNotifier::note($r));
$sent = ['outcome' => 'sent', 'messages' => [['message' => 'assigned']]];
is_(JobNotifier::notes([]) === '' && JobNotifier::notes([951 => $sent]) === JobNotifier::note($sent), 'no job: no note; one job: its note');
is_(JobNotifier::notes([951 => $sent, 952 => $sent, 953 => $sent]) === 'All 3 jobs: WhatsApp sent to the engineer, with the link to accept the job.',
    'a batch where every message went: one sentence', JobNotifier::notes([951 => $sent, 952 => $sent, 953 => $sent]));
is_(JobNotifier::notes([951 => $sent, 952 => ['outcome' => 'no_staff_account'], 953 => $sent])
    === 'Jobs #951, #953: WhatsApp sent to the engineer, with the link to accept the job. Job #952: No WhatsApp was sent: no active staff account is linked to the job\'s uCRM user.',
    'a mixed batch: one sentence per outcome, naming its jobs');
is_(JobNotifier::summary([951 => $sent, 952 => ['outcome' => 'sending']]) === 'sent' && JobNotifier::summary([951 => $sent, 952 => ['outcome' => 'failed']]) === 'partly_sent'
    && JobNotifier::summary([951 => ['outcome' => 'failed']]) === 'not_sent' && JobNotifier::summary([]) === 'not_sent', 'the answer\'s summary: sent, partly_sent, not_sent');

// ── 8. The sign-in return ────────────────────────────────────────────────────
echo "\n8. The link survives the sign-in, and can send nobody anywhere else\n";
$t0 = 1790000000;
$ses = []; JobReturn::remember(['page' => 'dashboard', 'tab' => 'scheduling', 'job' => '950'], $ses, $t0);
is_(($ses[JobReturn::KEY] ?? null) === ['job' => 950, 'at' => $t0], 'a signed-out tap on a job link keeps the job number, and nothing else');
is_(JobReturn::take($ses, $t0 + 60) === '?page=dashboard&tab=scheduling&job=950' && !isset($ses[JobReturn::KEY]), 'sign-in returns to that job\'s page, and forgets it');
is_(JobReturn::take($ses, $t0 + 61) === null, 'once: the next sign-in goes to the dashboard as before');
$ses = []; JobReturn::remember(['page' => 'dashboard', 'tab' => 'scheduling', 'job' => '950'], $ses, $t0);
is_(JobReturn::take($ses, $t0 + JobReturn::TTL) !== null, 'within half an hour it returns');
$ses = []; JobReturn::remember(['page' => 'dashboard', 'tab' => 'scheduling', 'job' => '950'], $ses, $t0);
is_(JobReturn::take($ses, $t0 + JobReturn::TTL + 1) === null && !isset($ses[JobReturn::KEY]), 'after half an hour it does not, and is forgotten');
$ses = []; JobReturn::remember(['page' => 'dashboard', 'tab' => 'scheduling', 'job' => '950'], $ses, $t0);
is_(JobReturn::take($ses, $t0 - 5) === null, 'a record from the future is not trusted');
$JUNK = ['0', '-1', '01', '950abc', '//evil.example', 'https://evil.example/', "950\r\nLocation: https://evil.example", '950?x=1', '950&next=//evil', ' 950',
         '12345678901', '9.5', '1e3', ''];
$kept = [];
foreach ($JUNK as $j) { $ses = []; JobReturn::remember(['page' => 'dashboard', 'tab' => 'scheduling', 'job' => $j], $ses, $t0); if ($ses !== []) $kept[] = $j; }
is_($kept === [], 'anything but a plain job number is ignored: ' . count($JUNK) . ' shapes, an open-redirect attempt among them', json_encode($kept));
$ses = []; JobReturn::remember(['page' => 'dashboard', 'tab' => 'scheduling', 'job' => ['950']], $ses, $t0);
is_($ses === [], 'an array where the number goes is ignored');
foreach ([['page' => 'dashboard', 'tab' => 'retailers', 'job' => '950'], ['page' => 'login', 'tab' => 'scheduling', 'job' => '950'], ['page' => 'dashboard', 'job' => '950']] as $g) {
    $ses = []; JobReturn::remember($g, $ses, $t0);
    is_($ses === [], 'only the job page is remembered: ' . http_build_query($g));
}
foreach ([['job' => 950, 'at' => $t0], ['job' => '950', 'at' => (string)$t0], ['job' => '//evil', 'at' => $t0], ['job' => 0, 'at' => $t0], 'string', ['at' => $t0]] as $planted) {
    $ses = [JobReturn::KEY => $planted];
    $u = JobReturn::take($ses, $t0 + 1);
    is_($u === null || preg_match('/^\?page=dashboard&tab=scheduling&job=[1-9][0-9]*$/', $u) === 1, 'whatever the session holds, the address is the fixed one or none: ' . json_encode($planted), (string)$u);
}

// ── 9. Migration 075 ─────────────────────────────────────────────────────────
echo "\n9. Migration 075 on an empty database\n";
$sql = (string)file_get_contents($root . '/migrations/075_job_notifications.sql');
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec($sql);
$objs = $pdo->query("SELECT type || ':' || name FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
is_($objs === ['index:idx_job_notify_events_job', 'table:job_notify_events', 'table:job_notify_state'], 'it creates two tables and one index', json_encode($objs));
$cols = function (string $t) use ($pdo): array { return array_column($pdo->query("PRAGMA table_info({$t})")->fetchAll(PDO::FETCH_ASSOC), 'name'); };
is_($cols('job_notify_state') === ['job_id', 'assignee_id', 'job_time', 'job_status', 'title', 'gone', 'accepted_by', 'version', 'updated_at'], 'job_notify_state\'s columns', json_encode($cols('job_notify_state')));
is_($cols('job_notify_events') === ['id', 'job_id', 'event', 'message', 'from_assignee_id', 'to_assignee_id', 'from_time', 'to_time', 'source', 'staff_id', 'outcome', 'detail', 'created_at'],
    'job_notify_events\' columns: no phone and no message text', json_encode($cols('job_notify_events')));
$pdo->exec($sql);
is_(true, 'and it runs again without error (CREATE … IF NOT EXISTS)');
is_(preg_match('/\b(DROP|ALTER|DELETE|UPDATE|INSERT)\b/i', (string)preg_replace('/--[^\n]*/', '', $sql)) === 0, 'it touches nothing that exists: no DROP, ALTER, DELETE, UPDATE or INSERT');
$sql76 = (string)@file_get_contents($root . '/migrations/076_job_notify_email.sql');
$pdo->exec($sql76);
is_(array_slice($cols('job_notify_events'), -2) === ['email_outcome', 'email_detail'] && count($cols('job_notify_events')) === 15
    && $cols('job_notify_state') === ['job_id', 'assignee_id', 'job_time', 'job_status', 'title', 'gone', 'accepted_by', 'version', 'updated_at'],
    'migration 076 adds the e-mail\'s two columns to job_notify_events, and nothing to job_notify_state', json_encode($cols('job_notify_events')));
$again = '';
try { $pdo->exec($sql76); } catch (\Throwable $e) { $again = $e->getMessage(); }
is_(stripos($again, 'duplicate column') !== false && stripos((string)@file_get_contents($root . '/lib/MigrationRunner.php'), "stripos(\$err, 'duplicate column')") !== false,
    'run again, it meets its own columns: "duplicate column", which the migration runner treats as done', $again);
$stmts76 = array_values(array_filter(array_map('trim', explode(';', (string)preg_replace('/--[^\n]*/', '', $sql76)))));
is_(count($stmts76) === 2 && count(preg_grep('/^ALTER TABLE job_notify_events ADD COLUMN email_(outcome|detail) TEXT$/', $stmts76)) === 2,
    'and it is exactly two ADD COLUMNs on job_notify_events: nothing dropped, rewritten or filled', json_encode($stmts76));

// ── 10. The notifier's source ────────────────────────────────────────────────
echo "\n10. What the notifier may and may not do\n";
$jn = (string)file_get_contents($root . '/lib/JobNotifier.php');
$code = (string)preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $jn);
is_(substr_count($code, "->sendVia('support', \$phone, \$text, \$log, [], ContactOptOut::CLASS_STAFF)") === 1 && substr_count($code, '->sendVia(') === 1,
    'one send, on the support number, as CLASS_STAFF: a colleague\'s old STOP never silences job dispatch');
is_(strpos($code, 'users/') === false, 'it never reads a uCRM user record: those have no phone (measured), and the e-mail goes to the staff account, not to uCRM\'s user');
is_(substr_count($code, '$mail->send(') === 1 && strpos($code, '$to = StaffDirectory::email($row);') !== false
    && strpos($code, '$mail->send(') > strpos($code, "\$pdo->exec('COMMIT');"),
    'one e-mail send, to the staff account\'s own address, placed after the claim like the WhatsApp');
is_(strpos($code, "BEGIN IMMEDIATE") !== false && strpos($code, 'sendVia') > strpos($code, "\$pdo->exec('COMMIT');"),
    'the claim (BEGIN IMMEDIATE … COMMIT) comes before the send in the source');
$wh = (string)@file_get_contents($root . '/webhook.php');
is_(preg_match("/case 'job\\.delete':\\s*case 'JOB_DELETE': \\{.*?whJobNotify\\(\\\$jobId, null,/s", $wh) === 1,
    'job.edit and job.delete hand the notifier nothing but the id: it asks uCRM, never the posted body (R3)');
is_(substr_count($wh, 'whJobNotify($jobId, $job,') === 2 && strpos($wh, '$job = whVerified(\'job\', $jobId, whFetchFirst($crm, ["scheduling/jobs/{$jobId}"]));') !== false,
    'job.add hands it the job uCRM answered to the webhook\'s own read, never $entity');

// ── 11. The e-mail copy ──────────────────────────────────────────────────────
echo "\n11. The e-mail copy (§16.16): subject and HTML part\n";
$SUBJECTS = ['assigned' => 'New job assigned to you: Job #950', 'accepted' => 'Job #950 accepted: your completion link',
             'reassigned_away' => 'Job #950 is no longer assigned to you', 'removed' => 'Job #950 is no longer assigned to you',
             'new_time' => 'Job #950 has a new time', 'cancelled' => 'Job #950 has been cancelled'];
$got = [];
foreach (array_keys($SUBJECTS) as $k) $got[$k] = JobMessages::subject($k, $f);
is_($got === $SUBJECTS, 'each message\'s subject is its own headline with the job\'s number', json_encode($got));
is_(!preg_grep('/[^\x20-\x7E]/', $got) && !preg_grep('/Grace|Test Client|Plot 1|\+256/', $got), 'plain ASCII, and no person, customer, address or number in any subject');
$text = JobMessages::assigned(['link' => 'https://crm.example/crm/_plugins/dishnet/public.php?page=dashboard&tab=scheduling&job=950', 'title' => 'Survey <b>&</b> "quote"'] + $f);
$html = JobMessages::html($text);
is_(strpos($html, '<a href="https://crm.example/crm/_plugins/dishnet/public.php?page=dashboard&amp;tab=scheduling&amp;job=950">') !== false,
    'the ACCEPT JOB link is a link in the HTML part', $html);
is_(strpos($html, 'Survey &lt;b&gt;&amp;&lt;/b&gt; &quot;quote&quot;') !== false && strpos($html, '<b>') === false, 'what uCRM holds is shown, never read as HTML');
is_(html_entity_decode(strip_tags(str_replace("<br>\r\n", "\n", $html)), ENT_QUOTES, 'UTF-8') === $text,
    'strip the markup and the HTML part is the text, byte for byte: it adds nothing the text does not say');
$js = JobMessages::html("javascript:alert(1)\nftp://x.example/a\nhttps://ok.example/p");
is_(substr_count($js, '<a href=') === 1 && strpos($js, '<a href="https://ok.example/p">') !== false, 'only http and https addresses become links');

printf("\n%d passed, %d failed, %d skipped\n", $pass, $fail, $skip);
exit($fail === 0 ? 0 : 1);
