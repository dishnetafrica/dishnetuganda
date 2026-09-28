<?php
declare(strict_types=1);
/**
 * test_job_notifier.php — 5.18.52 (docs/44 J4, §16.12, §16.14): on Uganda every job message comes from one place,
 * once per change, to the engineer uCRM has on the job — proved through the real public.php, webhook and staff API of
 * a sandboxed plugin, beside a fake uCRM and a fake WhatsApp.
 *
 *   1. ＋ New Job: message 1 once, whichever of the paths sees the job first (T4.1, both orders), however often uCRM
 *      redelivers (T4.2); uCRM's values, never the browser's (T4.13); the job created Open, so Accept shows
 *   2. two processes observing the same change at once: one message (T4.3), and a claim in progress blocks the other
 *   3. a reassignment, both ways (T4.4); a new time to the engineer, not to whoever pressed (T4.5)
 *   4. removed, deleted, closed (T4.6); nobody to tell (T4.7); a forged event (T4.8); uCRM unreachable or partial
 *      (T4.9); a failed send (T4.10)
 *   5. Accept: message 2 with the completion link, once per assignment, to the job's engineer; the leaders' copy
 *   6. the link in message 1 survives the sign-in; nothing else is remembered or followed
 *   7. Bulk Dispatch; the screens; J7's log lines; nothing personal in the history table
 *   8. South Sudan: the same traffic changes nothing there, and the two tables stay empty
 *   9. weakened copies of the code each fail this test
 *
 * Since §16.16 every message also goes by e-mail, the same text, to the staff account's own address. Each section says
 * what the relay took: one e-mail per message, whether or not the WhatsApp went, to nobody when there is nobody to
 * message; 4b is what happens when there is no address, no mail server, or a mail server that refuses.
 *
 * What this proves is what the plugin hands to WhatsApp and to the mail server. It is not proof of delivery to a phone
 * or an inbox. Every person, number, e-mail and job is fictitious; nothing leaves the machine.
 *
 *   php test_job_notifier.php [--root=DIR] [--no-mutants]
 */
$opt  = getopt('', ['root:', 'no-mutants']);
$root = isset($opt['root']) ? rtrim((string)$opt['root'], '/') : dirname(__DIR__);
$withMutants = !isset($opt['no-mutants']);
require_once __DIR__ . '/fixtures/staff_jobs_scenario.php';

$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; }
    else    { $fail++; echo "  FAIL {$m}" . ($d !== '' ? "\n       {$d}" : '') . "\n"; }
}

const TECH  = '256700000111';   // Sandbox Tech, uCRM user 1099
const TECH2 = '256700000114';   // Sandbox Tech Two, uCRM user 1100
const LEAD  = '256700000112';   // Sandbox Leader: no uCRM link
const WHEN  = '2026-10-07T09:00:00+0300';

/** The sandbox for this test: Uganda, two linked engineers, a leader, and the plugin's own public address. */
$start = function (string $tag, array $cfg = []) use ($root): SjSandbox {
    $s = SjSandbox::start($root, $cfg + ['tenant_profile' => 'uganda', 'timezone' => 'Africa/Kampala', 'contact_support_phone' => '+256 700 000 100',
        'email_reply_to' => 'jobs-reply@example.test'], $tag);
    file_put_contents($s->plug . '/ucrm.json', json_encode(['pluginDataDir' => $s->data, 'pluginPublicUrl' => $s->base]));
    $crm = SjScenario::crm();
    // uCRM's user record carries a number that is NOT the staff account's: a message to it would be the wrong person.
    $crm['users']['1099']['phone'] = '+256700009999';
    // …and an e-mail changed in uCRM since the link was saved: the job's e-mail goes to the staff account's, never to this one.
    $crm['users']['1100'] = ['id' => 1100, 'username' => 'sb-tech2', 'firstName' => 'Sandbox', 'lastName' => 'Tech Two', 'email' => 'tech2-in-ucrm@example.test', 'isActive' => true];
    $s->seedCrm($crm);
    SjScenario::staff($s);
    $s->staff('tech2', ['name' => 'Sandbox Tech Two', 'email' => 'tech2@example.test', 'role' => 'support', 'phone' => '+256700000114',
        'ucrm_user_id' => 1100, 'ucrm_link' => ['user_id' => 1100, 'email' => 'tech2@example.test', 'verified_at' => '2026-09-27T00:00:00Z', 'verified_by' => 1]]);
    $s->mailRelay(900);
    return $s;
};
/** The e-mails the relay took since $from, to $to (or to anyone). */
$mails = function (SjSandbox $s, ?string $to = null, int $from = 0): array {
    return array_values(array_filter(array_slice($s->mails(), $from), function ($m) use ($to) { return $to === null || $m['to'] === [$to]; }));
};
const TECH_MAIL  = 'tech@example.test';
const TECH2_MAIL = 'tech2@example.test';
$setJob = function (SjSandbox $s, int $id, array $fields): void {
    $jobs = (array)($s->crmDump()['jobs'] ?? []);
    $jobs[(string)$id] = array_merge($jobs[(string)$id] ?? ['id' => $id, 'title' => "Sandbox job {$id}", 'description' => '', 'clientId' => null,
        'assignedUserId' => 1099, 'date' => WHEN, 'duration' => 60, 'status' => 0, 'address' => null], $fields);
    $s->seedCrm(['jobs' => $jobs]);
};
$texts = function (SjSandbox $s, ?string $to = null, int $from = 0): array {
    return array_values(array_filter(array_slice($s->texts(), $from), function ($t) use ($to) { return $to === null || $t['number'] === $to; }));
};
$events = function (SjSandbox $s, int $job): array {
    return $s->q('SELECT event, message, from_assignee_id, to_assignee_id, source, staff_id, outcome, detail, email_outcome, email_detail FROM job_notify_events WHERE job_id = ? ORDER BY id', [$job]);
};
$state = function (SjSandbox $s, int $job): array { return $s->q('SELECT * FROM job_notify_state WHERE job_id = ?', [$job])[0] ?? []; };
$mlog = function (SjSandbox $s, string $event): array {
    try { return $s->q('SELECT event, phone, success FROM notification_audit_log WHERE event = ? ORDER BY id', [$event]); } catch (\Throwable $e) { return []; }
};
$whLines = function (SjSandbox $s, string $needle): array {
    $out = [];
    foreach (array_reverse(json_decode($s->webhookLog(), true) ?: []) as $l) if (strpos((string)$l['message'], $needle) !== false) $out[] = (string)$l['message'];
    return $out;
};
$edit = function (SjSandbox $s, int $id, string $uuid) { return $s->fire('job.edit', 'job', $id, $uuid); };
$link = function (SjSandbox $s, int $id): string { return $s->base . '?page=dashboard&tab=scheduling&job=' . $id; };

$s = $start('sjn-ug');

// ═════════════════════════════════════════════════════════════════════════════
echo "\n1. ＋ New Job: message 1, once\n";
$r = $s->api('admin', 'POST', 'create_job', ['title' => 'Sandbox installation', 'date' => '2026-10-07', 'time' => '09:00', 'engineer_ids' => [1099],
    'crm_client_id' => 15, 'tasks' => ['Mount the dish'], 'notify_wa' => 1]);
$j1 = (int)($r[2]['data']['jobs'][0]['job_id'] ?? 0);
$t = $texts($s);
is_($r[0] === 200 && $j1 > 0 && count($t) === 1 && $t[0]['number'] === TECH, 'one WhatsApp, to the engineer\'s staff number', json_encode(array_column($t, 'number')));
$want = "Hi Sandbox Tech. This is DishNet Africa.\n\nNew Job Has Been Assigned to You\n\nSandbox installation — Sandbox Customer\n📅 Date: 07.10.2026 09:00 am\n"
      . "👤 Client: Sandbox Customer (ID:15)\n📞 Mobile: +256700000915\n📍 Address: Plot 9 Sandbox Road Kampala\n\n---\n"
      . "Please click the link below to accept this job:\n\n✅ ACCEPT JOB:\n" . $link($s, $j1) . "\n\n"
      . "Once you accept, we will send you the completion link.\n\nFor any questions, just reach out here.\n📞 +256 700 000 100\n🌐 dishnetuganda.com";
is_(($t[0]['text'] ?? '') === $want, 'it is message 1, built from uCRM\'s job and client, with the job\'s own link', json_encode($t[0]['text'] ?? '', JSON_UNESCAPED_UNICODE));
is_(count($mlog($s, 'job_assigned')) === 1 && (int)$mlog($s, 'job_assigned')[0]['success'] === 1, 'the Message Log has one job_assigned row, sent');
$e = $events($s, $j1);
is_(count($e) === 1 && $e[0]['event'] === 'assigned' && $e[0]['message'] === 'assigned' && $e[0]['source'] === 'my_jobs' && $e[0]['outcome'] === 'sent'
    && (int)$e[0]['to_assignee_id'] === 1099 && (int)$e[0]['staff_id'] === $s->ids['tech'], 'one "assigned" event: from My Jobs, sent, to staff account ' . $s->ids['tech'], json_encode($e));
$d = $r[2]['data'] ?? [];
is_(($d['whatsapp'] ?? '') === 'sent' && ($d['jobs'][0]['notified'] ?? null) === true && ($d['jobs'][0]['whatsapp'] ?? '') === 'sent'
    && ($d['whatsapp_note'] ?? '') === 'WhatsApp sent to the engineer, with the link to accept the job. The same message went to the engineer\'s e-mail.',
    'the answer says both went, in words', json_encode($d, JSON_UNESCAPED_UNICODE));
$m = $mails($s);
is_(count($m) === 1 && $m[0]['to'] === [TECH_MAIL] && $m[0]['from'] === 'accounts@example.test' && $m[0]['text'] === $want,
    'one e-mail, to the engineer\'s staff account, from the plugin\'s sender: the same text as the WhatsApp, byte for byte (§16.16)', json_encode($m, JSON_UNESCAPED_UNICODE));
is_(($m[0]['subject'] ?? '') === "New job assigned to you: Job #{$j1}" && ($m[0]['reply_to'] ?? '') === 'jobs-reply@example.test'
    && strpos($m[0]['header_to'] ?? '', '"Sandbox Tech" <' . TECH_MAIL . '>') !== false,
    'its subject names the job; replies go to the reply address the other plugin e-mails use; it is addressed by name', json_encode([$m[0]['subject'] ?? '', $m[0]['reply_to'] ?? '', $m[0]['header_to'] ?? '']));
is_(strpos((string)($m[0]['html'] ?? ''), '<a href="' . htmlspecialchars($link($s, $j1), ENT_QUOTES) . '">') !== false, 'in its HTML part the ACCEPT JOB link is a link');
is_(($e[0]['email_outcome'] ?? null) === 'sent' && ($e[0]['email_detail'] ?? '') === 'staff account #' . $s->ids['tech'], 'the history row says the e-mail went too, to that account', json_encode($e[0] ?? null));
$post = $s->crmReqs('POST', '#^/scheduling/jobs$#');
is_(count($post) === 1 && ($post[0]['body']['status'] ?? null) === 0, 'the job is created Open (status 0), so the engineer\'s Accept button shows', json_encode($post[0]['body'] ?? null));
is_(count($texts($s, TECH)) === 1 && !array_filter($s->texts(), function ($x) { return strpos($x['text'], 'New Job Assigned') !== false; }),
    '"Notify via WhatsApp" ticked in the request changes nothing: the old ＋ New Job message is not sent (D4)');
foreach (['sjn-1a', 'sjn-1b', 'sjn-1c'] as $u) $s->fire('job.add', 'job', $j1, $u);
is_(count($texts($s)) === 1 && count($events($s, $j1)) === 1 && count($mails($s)) === 1, 'uCRM\'s job.add for it, delivered three times: still one message and one e-mail (T4.1, T4.2)');
is_(count($whLines($s, "Job #{$j1} — no new assignment, time or cancellation: nothing to send")) === 3, 'and the webhook log says why, each time');
is_((int)($state($s, $j1)['version'] ?? 0) === 1, 'nothing changed, so nothing was written: the state row is still at version 1');

echo "\n   uCRM's job.add first, then ＋ New Job\n";
$next = (int)($s->crmDump()['next_job'] ?? 0);
$setJob($s, $next, ['title' => 'Sandbox installation — Sandbox Customer', 'clientId' => 15, 'assignedUserId' => 1099, 'date' => WHEN, 'status' => 0,
    'address' => 'Plot 9 Sandbox Road Kampala']);
$n0 = count($s->texts()); $m0 = count($s->mails());
$s->fire('job.add', 'job', $next, 'sjn-first');
$r = $s->api('admin', 'POST', 'create_job', ['title' => 'Sandbox installation', 'date' => '2026-10-07', 'time' => '09:00', 'engineer_ids' => [1099], 'crm_client_id' => 15]);
$j2 = (int)($r[2]['data']['jobs'][0]['job_id'] ?? 0);
is_($j2 === $next && count($texts($s, null, $n0)) === 1, 'one message: the webhook sent it, ＋ New Job found nothing new (T4.1, the other order)', count($texts($s, null, $n0)) . ' messages');
$e = $events($s, $j2);
is_(count($e) === 1 && $e[0]['source'] === 'ucrm_webhook', 'one event, recorded by the path that saw it first', json_encode($e));
is_(($r[2]['data']['whatsapp'] ?? '') === 'sent' && ($r[2]['data']['jobs'][0]['notified'] ?? null) === true
    && ($r[2]['data']['whatsapp_note'] ?? '') === 'WhatsApp sent to the engineer, with the link to accept the job. The same message went to the engineer\'s e-mail.',
    'and ＋ New Job still answers "sent", e-mail included: it reads what the webhook recorded', json_encode($r[2]['data'] ?? null, JSON_UNESCAPED_UNICODE));
is_(count($mails($s, null, $m0)) === 1 && count($whLines($s, "Job #{$j2} (assigned) — WhatsApp sent to staff account #{$s->ids['tech']}; e-mail handed to the mail server")) === 1,
    'one e-mail too, and the webhook log line says what became of both', json_encode($whLines($s, "Job #{$j2} "), JSON_UNESCAPED_UNICODE));

echo "\n   uCRM's values, never the browser's (T4.13)\n";
$s->http('POST', "{$s->crm}/__test/post_override", ['fields' => ['title' => 'Title as uCRM stored it', 'address' => 'Address as uCRM stored it']]);
$n0 = count($s->texts()); $m0 = count($s->mails());
$r = $s->api('admin', 'POST', 'create_job', ['title' => 'Typed in the browser', 'date' => '2026-10-07', 'time' => '09:00', 'engineer_ids' => [1099], 'crm_client_id' => 15,
    'address' => 'Browser address', 'client_name' => 'Browser client']);
$s->http('POST', "{$s->crm}/__test/post_override", ['fields' => []]);
$m = $texts($s, null, $n0)[0]['text'] ?? '';
is_(strpos($m, "\nTitle as uCRM stored it\n") !== false && strpos($m, '📍 Address: Address as uCRM stored it') !== false
    && strpos($m, 'Typed in the browser') === false && strpos($m, 'Browser') === false, 'the title and address are uCRM\'s, exactly', json_encode($m, JSON_UNESCAPED_UNICODE));
is_(($mails($s, null, $m0)[0]['text'] ?? null) === $m, 'and so are the e-mail\'s: it is the same text');
$n0 = count($s->texts());
$r = $s->api('admin', 'POST', 'create_job', ['title' => 'TEST internal — please ignore', 'date' => '2026-10-08', 'time' => '10:00', 'engineer_ids' => [1099]]);
$m = $texts($s, null, $n0)[0]['text'] ?? '';
is_(strpos($m, 'TEST internal — please ignore') !== false && strpos($m, '👤') === false && strpos($m, 'Mobile') === false && strpos($m, 'Address') === false,
    'a job with no customer (the live test\'s): no Client, Mobile or Address line', json_encode($m, JSON_UNESCAPED_UNICODE));
is_(strpos($m, '08.10.2026 10:00 am') !== false, 'and the hour typed is the hour said: Kampala\'s (J5)');

// ═════════════════════════════════════════════════════════════════════════════
echo "\n2. Two processes, one change (T4.3)\n";
$child = $s->sb . '/observe_child.php';
file_put_contents($child, '<?php
[$_, $plug, $data, $jobId, $ready, $go] = $argv;
foreach (["StoreInterface", "SqliteStore", "CrmApiClient", "NotificationService", "JobNotifier"] as $c) require_once $plug . "/lib/" . $c . ".php";
$store  = SqliteStore::create($data);
$config = $store->load("kyc_config.json");
$crm    = CrmApiClient::fromUcrm($plug, $config);
$notify = new NotificationService($store, $config);
$n      = new JobNotifier($crm, $store, $notify, $config, $data);
touch($ready);
for ($i = 0; $i < 3000 && !is_file($go); $i++) usleep(2000);
echo json_encode($n->observe((int)$jobId, "ucrm_webhook"));
');
$run = function (int $job, string $ready, string $go) use ($s, $child) {
    $env = ['DN_DATA_DIR' => $s->data, 'DN_VAULT_FILE' => $s->vault, 'PATH' => (string)getenv('PATH')];
    $p = proc_open([PHP_BINARY, $child, $s->plug, $s->data, (string)$job, $ready, $go],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);
    return [$p, $pipes[1]];
};
$finish = function (array $h): array {
    [$p, $out] = $h;
    $o = stream_get_contents($out); fclose($out); proc_close($p);
    return json_decode((string)$o, true) ?: ['raw' => $o];
};
$waitFor = function (string $f): bool { for ($i = 0; $i < 1500; $i++) { if (is_file($f)) return true; usleep(2000); } return false; };

// A claim in progress, held by another connection: the observer must wait for it, then find nothing new.
foreach (['commit' => 0, 'rollback' => 1] as $how => $wantMsgs) {
    $jid = 980 + $wantMsgs;
    $setJob($s, $jid, ['assignedUserId' => 1099, 'date' => WHEN, 'status' => 0]);
    $ready = $s->sb . "/ready-{$how}"; $go = $s->sb . "/go-{$how}";
    $n0 = count($s->texts()); $m0 = count($s->mails());
    $h = $run($jid, $ready, $go);
    $waitFor($ready);
    $pdo = new PDO('sqlite:' . $s->data . '/plugin.sqlite3');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('BEGIN IMMEDIATE');
    $pdo->prepare("INSERT INTO job_notify_state (job_id, assignee_id, job_time, job_status, title, gone, version) VALUES (?, 1099, '2026-10-07 06:00', 0, 'x', 0, 1)")->execute([$jid]);
    touch($go);
    usleep(900000);
    $waiting = proc_get_status($h[0])['running'];
    $pdo->exec($how === 'commit' ? 'COMMIT' : 'ROLLBACK');
    $res = $finish($h);
    $sent = count($texts($s, null, $n0)) + count($mails($s, null, $m0));
    if ($how === 'commit') {
        is_($waiting && ($res['outcome'] ?? '') === 'no_change' && $sent === 0,
            'a claim in progress in another connection: the observer waits for it, then finds the change already claimed and sends nothing', json_encode([$waiting, $res['outcome'] ?? $res, $sent]));
    } else {
        is_($waiting && ($res['outcome'] ?? '') === 'sent' && $sent === 2,
            'control: the same, with the other claim rolled back — the observer, after waiting, sends the one message and its e-mail', json_encode([$waiting, $res['outcome'] ?? $res, $sent]));
    }
}
// Real races: two processes released together on each of eight new jobs.
$n0 = count($s->texts()); $m0 = count($s->mails());
$jobsRaced = range(990, 997);
$outcomes = [];
foreach ($jobsRaced as $jid) {
    $setJob($s, $jid, ['assignedUserId' => 1099, 'date' => WHEN, 'status' => 0]);
    $go = $s->sb . "/go-race-{$jid}";
    $hs = [$run($jid, $s->sb . "/r-{$jid}-a", $go), $run($jid, $s->sb . "/r-{$jid}-b", $go)];
    $waitFor($s->sb . "/r-{$jid}-a"); $waitFor($s->sb . "/r-{$jid}-b");
    touch($go);
    $outcomes[$jid] = array_map(function ($h) use ($finish) { return $finish($h)['outcome'] ?? '?'; }, $hs);
}
$perJob = array_count_values(array_map(function ($t) { preg_match('/job=(\d+)/', $t['text'], $m); return $m[1] ?? '?'; }, $texts($s, null, $n0)));
is_(count($texts($s, null, $n0)) === 8 && array_values(array_unique($perJob)) === [1],
    'eight jobs, each observed by two processes at the same moment: exactly one message per job', json_encode(['per_job' => $perJob, 'outcomes' => $outcomes]));
is_(count(array_filter($outcomes, function ($o) { sort($o); return $o === ['no_change', 'sent']; })) === 8, 'in every pair one process sent and the other found nothing new');
$mailPerJob = array_count_values(array_map(function ($m) { preg_match('/Job #(\d+)/', $m['subject'], $x); return $x[1] ?? '?'; }, $mails($s, null, $m0)));
ksort($mailPerJob);
is_(array_keys($mailPerJob) === $jobsRaced && array_values(array_unique($mailPerJob)) === [1], 'and exactly one e-mail per job', json_encode($mailPerJob));

// ═════════════════════════════════════════════════════════════════════════════
echo "\n3. A new engineer, a new time\n";
$jr = 1001;
$setJob($s, $jr, ['title' => 'Sandbox repair', 'assignedUserId' => 1099, 'date' => WHEN, 'status' => 0]);
$s->fire('job.add', 'job', $jr, 'sjn-r0');
$n0 = count($s->texts()); $m0 = count($s->mails());
$setJob($s, $jr, ['assignedUserId' => 1100]);
$edit($s, $jr, 'sjn-r1');
$t = $texts($s, null, $n0);
is_(count($t) === 2 && $t[0]['number'] === TECH2 && strpos($t[0]['text'], 'New Job Has Been Assigned to You') !== false
    && $t[1]['number'] === TECH && strpos($t[1]['text'], "↩️ Job #{$jr} is no longer assigned to you") !== false && strpos($t[1]['text'], 'It has been given to a colleague. Please do not go.') !== false,
    'A → B in uCRM\'s screen (job.edit): message 1 to B, "no longer assigned" to A (T4.4, D3)', json_encode($t, JSON_UNESCAPED_UNICODE));
$m = $mails($s, null, $m0);
is_(count($m) === 2 && $m[0]['to'] === [TECH2_MAIL] && $m[0]['subject'] === "New job assigned to you: Job #{$jr}" && $m[0]['text'] === $t[0]['text']
    && $m[1]['to'] === [TECH_MAIL] && $m[1]['subject'] === "Job #{$jr} is no longer assigned to you" && $m[1]['text'] === $t[1]['text'],
    'and the same two by e-mail — B\'s to the address on B\'s staff account, not the one uCRM now holds for B', json_encode(array_map(function ($x) { return [$x['to'], $x['subject']]; }, $m)));
$edit($s, $jr, 'sjn-r1');
is_(count($texts($s, null, $n0)) === 2 && count($mails($s, null, $m0)) === 2, 'the same job.edit again: nothing more');
$n1 = count($s->texts());
$setJob($s, $jr, ['assignedUserId' => 1099]);
$edit($s, $jr, 'sjn-r2');
$t = $texts($s, null, $n1);
is_(count($t) === 2 && $t[0]['number'] === TECH && strpos($t[0]['text'], 'New Job Has Been Assigned to You') !== false && $t[1]['number'] === TECH2,
    'B → A: message 1 to A again, "no longer assigned" to B — no "already sent" key blocks it', json_encode(array_column($t, 'number')));
$e = array_values(array_filter($events($s, $jr), function ($x) { return $x['event'] === 'reassigned'; }));
is_(count($e) === 4 && $e[0]['message'] === 'assigned' && $e[1]['message'] === 'reassigned_away', 'four "reassigned" rows: one per message', json_encode($e));
is_(count($whLines($s, "Job #{$jr} (reassigned: new engineer) — WhatsApp sent to staff account #")) === 2
    && count($whLines($s, "Job #{$jr} (reassigned: previous engineer) — WhatsApp sent to staff account #")) === 2, 'the webhook log names each message');

$n0 = count($s->texts()); $m0 = count($s->mails());
$r = $s->api('lead', 'POST', 'scheduling_reschedule', ['job_id' => $jr, 'new_date' => '2026-10-09 14:30', 'comment' => 'Customer asked']);
$t = $texts($s, null, $n0);
is_(count($t) === 1 && $t[0]['number'] === TECH && strpos($t[0]['text'], "📅 Job #{$jr} has a new time") !== false
    && strpos($t[0]['text'], "Now: 09.10.2026 02:30 pm\nWas: 07.10.2026 09:00 am") !== false,
    'Reschedule pressed by the leader: the new time goes to the engineer on the job, not to the leader (T4.5)', json_encode($t, JSON_UNESCAPED_UNICODE));
is_(count($texts($s, LEAD, $n0)) === 0 && count($mlog($s, 'job_rescheduled')) === 1, 'the leader gets nothing; the Message Log has one job_rescheduled');
$m = $mails($s, null, $m0);
is_(count($m) === 1 && $m[0]['to'] === [TECH_MAIL] && $m[0]['subject'] === "Job #{$jr} has a new time" && $m[0]['text'] === $t[0]['text'],
    'the new time by e-mail too, to the engineer, not to the leader', json_encode($m, JSON_UNESCAPED_UNICODE));
is_(($r[2]['data']['whatsapp'] ?? '') === 'sent'
    && ($r[2]['data']['whatsapp_note'] ?? '') === 'WhatsApp with the new time sent to the engineer on the job. The same message went to the engineer\'s e-mail.',
    'and the answer says so', json_encode($r[2]['data'] ?? null, JSON_UNESCAPED_UNICODE));
$n0 = count($s->texts()); $m0 = count($s->mails());
$r = $s->api('lead', 'POST', 'scheduling_reschedule', ['job_id' => $jr, 'new_date' => '2026-10-09 14:30', 'comment' => 'Same again']);
is_(count($texts($s, null, $n0)) === 0 && count($mails($s, null, $m0)) === 0 && ($r[2]['data']['whatsapp_note'] ?? '') === 'No WhatsApp was sent: nothing the engineer needs to hear about changed.',
    'the same time again: no message, no e-mail, and the answer says why', json_encode($r[2]['data'] ?? null, JSON_UNESCAPED_UNICODE));
$n0 = count($s->texts());
$setJob($s, $jr, ['date' => '2026-10-10T08:00:00+0300']);
$edit($s, $jr, 'sjn-r3'); $edit($s, $jr, 'sjn-r3');
$t = $texts($s, null, $n0);
is_(count($t) === 1 && strpos($t[0]['text'], "Now: 10.10.2026 08:00 am\nWas: 09.10.2026 02:30 pm") !== false, 'a new time set in uCRM\'s screen: one message, delivered twice or not', json_encode($t, JSON_UNESCAPED_UNICODE));

// ═════════════════════════════════════════════════════════════════════════════
echo "\n4. Removed, deleted, closed; nobody to tell; forged; unreachable; failed\n";
$jx = 1002;
$setJob($s, $jx, ['title' => 'Sandbox survey', 'assignedUserId' => 1099]);
$s->fire('job.add', 'job', $jx, 'sjn-x0');
$n0 = count($s->texts()); $m0 = count($s->mails());
$setJob($s, $jx, ['assignedUserId' => null]);
$edit($s, $jx, 'sjn-x1');
$t = $texts($s, null, $n0);
is_(count($t) === 1 && $t[0]['number'] === TECH && strpos($t[0]['text'], "↩️ Job #{$jx} is no longer assigned to you\n\nSandbox survey\n📅 Date: 07.10.2026 09:00 am\nPlease do not go.") !== false,
    'the engineer removed: one "no longer assigned" notice (T4.6, D3)', json_encode($t, JSON_UNESCAPED_UNICODE));
$m = $mails($s, null, $m0);
is_(count($m) === 1 && $m[0]['to'] === [TECH_MAIL] && $m[0]['subject'] === "Job #{$jx} is no longer assigned to you" && $m[0]['text'] === $t[0]['text'], 'and by e-mail');
$setJob($s, $jx, ['assignedUserId' => 1099]);
$edit($s, $jx, 'sjn-x2');
$n0 = count($s->texts()); $m0 = count($s->mails());
$s->http('POST', "{$s->crm}/__test/delete_job", ['id' => $jx]);
$s->fire('job.delete', 'job', $jx, 'sjn-x3'); $s->fire('job.delete', 'job', $jx, 'sjn-x3');
$t = $texts($s, null, $n0);
is_(count($t) === 1 && $t[0]['number'] === TECH && strpos($t[0]['text'], "❌ Job #{$jx} has been cancelled\n\nSandbox survey\n📅 Was: 07.10.2026 09:00 am\nPlease do not go.") !== false,
    'deleted in uCRM (404): one "cancelled" notice with the title and time last told, delivered twice or not', json_encode($t, JSON_UNESCAPED_UNICODE));
is_(($state($s, $jx)['gone'] ?? null) === 1 && count($mlog($s, 'job_cancelled')) === 1, 'the job is recorded gone; the Message Log has one job_cancelled');
$m = $mails($s, null, $m0);
is_(count($m) === 1 && $m[0]['to'] === [TECH_MAIL] && $m[0]['subject'] === "Job #{$jx} has been cancelled" && $m[0]['text'] === $t[0]['text'], 'and one e-mail, delivered twice or not');

$jc = 1003;
$setJob($s, $jc, ['assignedUserId' => 1099, 'status' => 1]);
$s->fire('job.add', 'job', $jc, 'sjn-c0');
$n0 = count($s->texts()); $m0 = count($s->mails());
$setJob($s, $jc, ['status' => 2]);
$edit($s, $jc, 'sjn-c1');
$ec = $events($s, $jc);
is_(count($texts($s, null, $n0)) === 0 && count($mails($s, null, $m0)) === 0 && end($ec)['event'] === 'closed' && end($ec)['outcome'] === 'recorded'
    && end($ec)['email_outcome'] === null, 'closed in uCRM: recorded, no message, no e-mail', json_encode($ec));
$n0 = count($s->texts());
$s->fire('job.delete', 'job', $jc, 'sjn-c2');
is_(count($texts($s, null, $n0)) === 0, 'a forged job.delete for a job that still exists: nothing (T4.8)');

$nob = [1004 => [1200, 'no_staff_account', 'no staff account is linked to uCRM user #1200'], 1005 => [1300, 'ambiguous_staff_account', '2 staff accounts are linked to uCRM user #1300'],
        1006 => [1400, 'no_usable_number', 'has no usable number'], 1007 => [1500, 'no_staff_account', 'no staff account is linked to uCRM user #1500'],
        1008 => [1600, 'no_staff_account', 'no staff account is linked to uCRM user #1600']];
$lnk = function (int $id, string $email): array { return ['ucrm_user_id' => $id, 'ucrm_link' => ['user_id' => $id, 'email' => $email, 'verified_at' => '2026-09-27T00:00:00Z', 'verified_by' => 1]]; };
$s->staff('twin1', ['name' => 'Twin One', 'email' => 'twin@example.test', 'role' => 'support', 'phone' => '+256700000121'] + $lnk(1300, 'twin@example.test'));
$s->staff('twin2', ['name' => 'Twin Two', 'email' => 'twin@example.test', 'role' => 'support', 'phone' => '+256700000122'] + $lnk(1300, 'twin@example.test'));
$s->staff('nonum', ['name' => 'No Number', 'email' => 'nonum@example.test', 'role' => 'support', 'phone' => 'call me'] + $lnk(1400, 'nonum@example.test'));
$s->staff('typed', ['name' => 'Typed Id', 'email' => 'typed@example.test', 'role' => 'support', 'phone' => '+256700000123', 'ucrm_user_id' => 1500]);
$s->staff('off',   ['name' => 'Switched Off', 'email' => 'off@example.test', 'role' => 'support', 'phone' => '+256700000124', 'is_active' => false] + $lnk(1600, 'off@example.test'));
$n0 = count($s->texts()); $m0 = count($s->mails());
foreach ($nob as $jid => [$uid, $outcome, $detail]) {
    $setJob($s, $jid, ['assignedUserId' => $uid]);
    $s->fire('job.add', 'job', $jid, "sjn-n{$jid}");
    $e = $events($s, $jid);
    $why = ['1004' => 'no staff account', '1005' => 'two staff accounts on one uCRM user', '1006' => 'a number that is not one',
            '1007' => 'an id typed, not verified (M7)', '1008' => 'a deactivated account'][(string)$jid];
    is_(count($e) === 1 && $e[0]['outcome'] === $outcome && strpos((string)$e[0]['detail'], $detail) !== false
        && count($whLines($s, "Job #{$jid} (assigned) — WhatsApp skipped: ")) === 1, "{$why}: no message, outcome {$outcome}, and the webhook log says \"skipped\" (T4.7)", json_encode($e));
}
is_(count($texts($s, null, $n0)) === 0, 'none of those five sent a WhatsApp message');
$m = $mails($s, null, $m0);
$mailed = [];
foreach (array_keys($nob) as $j) { $row = $events($s, $j)[0] ?? []; $mailed[$j] = array_key_exists('email_outcome', $row) ? $row['email_outcome'] : 'row missing'; }
is_(count($m) === 1 && $m[0]['to'] === ['nonum@example.test'] && $m[0]['subject'] === 'New job assigned to you: Job #1006'
    && $mailed === [1004 => null, 1005 => null, 1006 => 'sent', 1007 => null, 1008 => null],
    'one e-mail: the account with no usable number still gets the message by e-mail; nobody to message is nobody to e-mail', json_encode([$mailed, array_column($m, 'to')]));
is_(count($whLines($s, 'Job #1006 (assigned) — WhatsApp skipped: staff account #' . $s->ids['nonum'] . ' has no usable number; e-mail handed to the mail server')) === 1
    && count($whLines($s, 'Job #1004 (assigned) — WhatsApp skipped: no staff account is linked to uCRM user #1200')) === 1
    && count($whLines($s, 'Job #1004 (assigned) — WhatsApp skipped: no staff account is linked to uCRM user #1200;')) === 0,
    'the log line says so; where there was nobody, it says nothing about e-mail');

$jf = 1009;
$setJob($s, $jf, ['assignedUserId' => 1099]);
$s->fire('job.add', 'job', $jf, 'sjn-f0');
$n0 = count($s->texts()); $v0 = (int)($state($s, $jf)['version'] ?? 0); $m0 = count($s->mails());
$s->http('POST', "{$s->base}?page=crm_webhook", ['changeType' => 'edit', 'entity' => 'job', 'entityId' => $jf, 'uuid' => 'sjn-forged',
    'extraData' => ['entity' => ['id' => $jf, 'assignedUserId' => 1100, 'date' => '2026-12-01T09:00:00+0300', 'status' => 0, 'title' => 'forged']]],
    ['Content-Type: application/json', 'X-Ucrm-Key: ' . $s->whKey]);
is_(count($texts($s, null, $n0)) === 0 && (int)($state($s, $jf)['version'] ?? 0) === $v0, 'a job.edit whose body says the engineer changed, while uCRM says not: nothing sent, nothing written (T4.8)');

$n0 = count($s->texts());
$setJob($s, $jf, ['assignedUserId' => 1100]);
$s->http('POST', "{$s->crm}/__test/jobs_down", ['down' => true]);
$edit($s, $jf, 'sjn-f1');
$s->http('POST', "{$s->crm}/__test/jobs_down", ['down' => false]);
is_(count($texts($s, null, $n0)) === 0 && (int)($state($s, $jf)['assignee_id'] ?? 0) === 1099
    && count($whLines($s, "Job #{$jf} — could not be checked with uCRM: uCRM did not answer for the job")) === 1,
    'uCRM unreachable: no message, the state untouched, "could not be checked" logged (T4.9)');
$s->http('POST', "{$s->crm}/__test/jobs_partial", ['partial' => true]);
$edit($s, $jf, 'sjn-f2');
$s->http('POST', "{$s->crm}/__test/jobs_partial", ['partial' => false]);
is_(count($texts($s, null, $n0)) === 0 && (int)($state($s, $jf)['assignee_id'] ?? 0) === 1099,
    'an answer without the assignee field: no "no longer assigned" notice — a missing field is not a removal (V4)');
$edit($s, $jf, 'sjn-f3');
$t = $texts($s, null, $n0);
is_(count($t) === 2 && $t[0]['number'] === TECH2 && $t[1]['number'] === TECH, 'the next event catches up: the reassignment, told once (T4.9)', json_encode(array_column($t, 'number')));
is_(array_column($mails($s, null, $m0), 'to') === [[TECH2_MAIL], [TECH_MAIL]], 'no e-mail for the forged, the unreachable or the partial one; the two of the catch-up',
    json_encode(array_column($mails($s, null, $m0), 'to')));

$jz = 1010;
$setJob($s, $jz, ['assignedUserId' => 1099]);
$s->http('GET', "{$s->evo}/__test/fail_next?n=1");
$queued = function (SjSandbox $s): int { try { return count($s->q('SELECT id FROM notification_queue')); } catch (\Throwable $e) { return 0; } };
$n0 = count($s->texts()); $q0 = $queued($s); $m0 = count($s->mails());
$s->fire('job.add', 'job', $jz, 'sjn-z0');
$ml = array_values(array_filter($mlog($s, 'job_assigned'), function ($x) { return (int)$x['success'] === 0; }));
$e = $events($s, $jz);
is_(count($ml) === 1 && $queued($s) === $q0 + 1 && ($e[0]['outcome'] ?? '') === 'failed'
    && count($whLines($s, "Job #{$jz} (assigned) — WhatsApp failed for staff account #")) === 1,
    'a failed send: the Message Log says failed, the failure queue holds it, the event says failed, the log says "failed" (T4.10)', json_encode([$ml, $e]));
is_(count($mails($s, TECH_MAIL, $m0)) === 1 && ($e[0]['email_outcome'] ?? '') === 'sent', 'the WhatsApp failed; its e-mail copy went all the same', json_encode($e));
$s->fire('job.add', 'job', $jz, 'sjn-z0');
is_(count($texts($s, null, $n0)) === 0 && count($events($s, $jz)) === 1 && count($mails($s, null, $m0)) === 1, 'and uCRM redelivering the event sends neither again');

$s->q("INSERT INTO contact_optouts (phone, channel, scope, reason, source, evidence) VALUES ('256700000111', '*', 'all', 'staff', 'admin', 'STOP')");
$jo = 1011;
$setJob($s, $jo, ['assignedUserId' => 1099]);
$n0 = count($s->texts());
$s->fire('job.add', 'job', $jo, 'sjn-o0');
is_(count($texts($s, TECH, $n0)) === 1, 'an old "STOP" on the engineer\'s number does not silence job dispatch: CLASS_STAFF');
$s->q("UPDATE contact_optouts SET active = 0");

// ═════════════════════════════════════════════════════════════════════════════
echo "\n4b. The e-mail copy when it cannot go\n";
$s->staff('oddmail', ['name' => 'Odd Address', 'email' => 'not-an-address', 'role' => 'support', 'phone' => '+256700000125'] + $lnk(1700, 'not-an-address'));
$jb = 1014;
$setJob($s, $jb, ['assignedUserId' => 1700]);
$n0 = count($s->texts()); $m0 = count($s->mails());
$s->fire('job.add', 'job', $jb, 'sjn-b0');
$e = $events($s, $jb);
is_(count($texts($s, '256700000125', $n0)) === 1 && count($mails($s, null, $m0)) === 0 && ($e[0]['outcome'] ?? '') === 'sent' && ($e[0]['email_outcome'] ?? '') === 'no_email'
    && count($whLines($s, "Job #{$jb} (assigned) — WhatsApp sent to staff account #{$s->ids['oddmail']}; no e-mail: the staff account has no usable address")) === 1,
    'a staff account whose e-mail is not an address: the WhatsApp goes, no e-mail is tried, and the log says both', json_encode($e));

$es = json_decode((string)file_get_contents($s->data . '/email_settings.json'), true);
$probe = stream_socket_server('tcp://127.0.0.1:0');
$dead = (int)substr((string)strrchr((string)stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
file_put_contents($s->data . '/email_settings.json', json_encode(['smtp_port' => $dead] + $es));
$n0 = count($s->texts()); $m0 = count($s->mails());
$r = $s->api('lead', 'POST', 'bulk_create_jobs', ['job_title' => 'Sandbox mail-down batch', 'job_date' => '2026-10-10', 'job_time' => '09:00',
    'customers' => [['crm_id' => 15, 'assignee_id' => 1099], ['crm_id' => 15, 'assignee_id' => 1099], ['crm_id' => 15, 'assignee_id' => 1099]]]);
$d = $r[2]['data'] ?? [];
$ev = array_map(function ($j) use ($events, $s) { return $events($s, (int)$j)[0] ?? []; }, array_column($d['results'] ?? [], 'job_id'));
is_(count($ev) === 3 && count($texts($s, TECH, $n0)) === 3 && count($mails($s, null, $m0)) === 0 && array_column($ev, 'outcome') === ['sent', 'sent', 'sent']
    && array_column($ev, 'email_outcome') === ['failed', 'failed', 'failed'],
    'the mail server down during a Bulk Dispatch of three: three WhatsApps sent, three e-mails recorded as not taken', json_encode($ev));
$who = 'staff account #' . $s->ids['tech'];
is_(strpos((string)($ev[0]['email_detail'] ?? ''), "{$who}: TCP connect failed") === 0
    && ($ev[1]['email_detail'] ?? '') === "{$who}: not tried, the mail server did not take an earlier e-mail in this request" && ($ev[2]['email_detail'] ?? '') === ($ev[1]['email_detail'] ?? ''),
    'the first job waited for the mail server; the other two did not try again, and say why', json_encode(array_column($ev, 'email_detail')));
is_(($d['whatsapp'] ?? '') === 'sent' && ($d['whatsapp_note'] ?? '') === 'All 3 jobs: WhatsApp sent to the engineer, with the link to accept the job. The e-mail copy was not taken by the mail server.',
    'the WhatsApps stand as sent, and the answer says the e-mail did not go', json_encode($d, JSON_UNESCAPED_UNICODE));

unlink($s->data . '/email_settings.json');
$n0 = count($s->texts()); $m0 = count($s->mails());
$r = $s->api('admin', 'POST', 'create_job', ['title' => 'Sandbox no-mail job', 'date' => '2026-10-11', 'time' => '10:00', 'engineer_ids' => [1099]]);
$jm = (int)($r[2]['data']['jobs'][0]['job_id'] ?? 0);
$e = $events($s, $jm);
is_(count($texts($s, TECH, $n0)) === 1 && count($mails($s, null, $m0)) === 0 && ($e[0]['email_outcome'] ?? '') === 'not_configured'
    && ($r[2]['data']['whatsapp_note'] ?? '') === 'WhatsApp sent to the engineer, with the link to accept the job. No e-mail copy: no mail server is set up for the plugin.',
    'no mail server set up at all: the WhatsApp goes, and the answer says there was no e-mail to send', json_encode([$e, $r[2]['data'] ?? null], JSON_UNESCAPED_UNICODE));

file_put_contents($s->data . '/email_settings.json', json_encode($es));
$n0 = count($s->texts()); $m0 = count($s->mails());
$r = $s->api('admin', 'POST', 'create_job', ['title' => 'Sandbox mail-back job', 'date' => '2026-10-11', 'time' => '11:00', 'engineer_ids' => [1099]]);
is_(count($texts($s, TECH, $n0)) === 1 && count($mails($s, TECH_MAIL, $m0)) === 1, 'control: the mail server back, the next job\'s e-mail goes (each request tries afresh)');

// ═════════════════════════════════════════════════════════════════════════════
echo "\n5. Accept: message 2, the completion link\n";
$n0 = count($s->texts()); $m0 = count($s->mails());
$r = $s->api('tech', 'POST', 'scheduling_job_update', ['job_id' => $j1, 'status' => 'open', 'notify_accept' => 1]);
$t = $texts($s, null, $n0);
$want2 = "Hi Sandbox Tech. This is DishNet Africa.\n\nThank you for accepting the job! ✅\n\nSandbox installation — Sandbox Customer\n📅 Date: 07.10.2026 09:00 am\n"
       . "👤 Client: Sandbox Customer (ID:15)\n\n✅ JOB COMPLETED:\n" . $link($s, $j1) . "\n"
       . "Press Complete there when the work is finished. The same page lets you reschedule or add a comment.\n\n📞 +256 700 000 100";
$eng = array_values(array_filter($t, function ($x) { return $x['number'] === TECH; }));
is_(count($eng) === 1 && $eng[0]['text'] === $want2, 'the engineer gets message 2, exactly', json_encode($eng, JSON_UNESCAPED_UNICODE));
is_(!array_filter($t, function ($x) { return strpos($x['text'], '✅ *Job Accepted*') !== false; }), 'and not the old "✅ Job Accepted"');
$lead = array_values(array_filter($t, function ($x) { return $x['number'] === LEAD; }));
is_(count($lead) === 1 && $lead[0]['text'] === "🔔 *Job Accepted*\n\n*Sandbox Tech* has accepted:\n*Sandbox installation — Sandbox Customer*\n📅 Wed 07 Oct at 09:00\n\nJob #{$j1} status → *In Progress*\n— DishNET NOC",
    'the support leader\'s "🔔 Job Accepted" is unchanged', json_encode($lead, JSON_UNESCAPED_UNICODE));
is_(count($mlog($s, 'ops_job_accepted_self')) === 1 && ($r[2]['data']['whatsapp'] ?? '') === 'sent', 'the Message Log has it as ops_job_accepted_self; the answer says sent');
$m = $mails($s, null, $m0);
is_(count($m) === 1 && $m[0]['to'] === [TECH_MAIL] && $m[0]['subject'] === "Job #{$j1} accepted: your completion link" && $m[0]['text'] === $want2
    && ($r[2]['data']['whatsapp_note'] ?? '') === 'WhatsApp with the completion link sent to the engineer on the job. The same message went to the engineer\'s e-mail.',
    'message 2 by e-mail too, to the engineer only — the leader\'s notice is WhatsApp alone, as before', json_encode(array_map(function ($x) { return [$x['to'], $x['subject']]; }, $m)));
$n0 = count($s->texts()); $m0 = count($s->mails());
$r = $s->api('tech', 'POST', 'scheduling_job_update', ['job_id' => $j1, 'status' => 'open', 'notify_accept' => 1]);
is_(count($texts($s, TECH, $n0)) === 0 && count($mails($s, null, $m0)) === 0 && ($r[2]['data']['whatsapp_note'] ?? '') === 'No WhatsApp was sent: the job was not waiting to be accepted.',
    'Accept again, the job already in progress: no second message 2, and the answer says why', json_encode($r[2]['data'] ?? null, JSON_UNESCAPED_UNICODE));
$setJob($s, $j1, ['status' => 0]);
$n0 = count($s->texts()); $m0 = count($s->mails());
$s->api('tech', 'POST', 'scheduling_job_update', ['job_id' => $j1, 'status' => 'open', 'notify_accept' => 1]);
is_(count($texts($s, TECH, $n0)) === 0 && count($mails($s, null, $m0)) === 0, 'set back to Open and accepted again by the same engineer: still one message 2, and one e-mail, per assignment');

$ja = 1012;
$setJob($s, $ja, ['title' => 'Sandbox relocation', 'assignedUserId' => 1099, 'status' => 0]);
$n0 = count($s->texts()); $m0 = count($s->mails());
$s->api('lead', 'POST', 'scheduling_job_update', ['job_id' => $ja, 'status' => 'open', 'notify_accept' => 1]);
$t = $texts($s, null, $n0);
is_(count(array_filter($t, function ($x) { return $x['number'] === TECH && strpos($x['text'], 'Thank you for accepting the job!') !== false; })) === 1
    && !array_filter($t, function ($x) { return $x['number'] === LEAD && strpos($x['text'], 'Thank you') !== false; }),
    'a leader accepts an engineer\'s job: message 2 goes to the engineer on the job, not to the leader', json_encode(array_column($t, 'number')));
is_(array_column($mails($s, null, $m0), 'to') === [[TECH_MAIL]], 'and so does its e-mail', json_encode(array_column($mails($s, null, $m0), 'to')));
$n0 = count($s->texts());
$s->fire('job.add', 'job', $ja, 'sjn-a1');
is_(count($texts($s, null, $n0)) === 0, 'that job was never seen before the Accept: uCRM\'s job.add afterwards sends no message 1');
$setJob($s, $ja, ['assignedUserId' => 1100]);
$edit($s, $ja, 'sjn-a2');
$n0 = count($s->texts());
$s->api('tech2', 'POST', 'scheduling_job_update', ['job_id' => $ja, 'status' => 'open', 'notify_accept' => 1]);
is_(count($texts($s, TECH2, $n0)) === 0, 'given to another engineer while already in progress: pressing Accept sends no message 2 — the job was not waiting');
$ju = 1013;
$setJob($s, $ju, ['assignedUserId' => null, 'status' => 0]);
$n0 = count($s->texts());
$r = $s->api('admin', 'POST', 'scheduling_job_update', ['job_id' => $ju, 'status' => 'open', 'notify_accept' => 1]);
is_(count(array_filter($texts($s, null, $n0), function ($x) { return strpos($x['text'], 'Thank you') !== false; })) === 0
    && ($r[2]['data']['whatsapp_note'] ?? '') === 'No WhatsApp was sent: nobody is assigned.', 'an unassigned job accepted by an admin: no message 2, and the answer says why',
    json_encode($r[2]['data'] ?? null, JSON_UNESCAPED_UNICODE));

// ═════════════════════════════════════════════════════════════════════════════
echo "\n6. The link survives the sign-in\n";
$jar = $s->sb . '/jar_phone.txt';
$a = $s->http('GET', $link($s, $j1), null, [], $jar);
is_(in_array($a[0], [301, 302, 303], true) && strpos($a[3], 'page=login') !== false, 'opened signed out, the link goes to the sign-in page', $a[0] . ' ' . $a[3]);
$b = $s->http('POST', "{$s->base}?page=login", http_build_query(['action' => 'do_login', 'identifier' => 'tech@example.test', 'password' => 'sj-password-1']),
    ['Content-Type: application/x-www-form-urlencoded'], $jar);
is_($b[3] === "?page=dashboard&tab=scheduling&job={$j1}", 'signing in lands on that job, and the address has no host', json_encode($b[3]));
$pg = $s->http('GET', "{$s->base}{$b[3]}", null, [], $jar);
is_($pg[0] === 200 && strpos($pg[1], "var jobId   = {$j1};") !== false, 'it is the job\'s own page in My Jobs', $pg[0] . ', ' . strlen($pg[1]) . ' bytes');
$s->http('GET', "{$s->base}?page=logout", null, [], $jar);
$c = $s->http('POST', "{$s->base}?page=login", http_build_query(['action' => 'do_login', 'identifier' => 'tech@example.test', 'password' => 'sj-password-1']),
    ['Content-Type: application/x-www-form-urlencoded'], $jar);
is_($c[3] === '?page=dashboard&tab=form', 'used once: the next sign-in goes where it always did', json_encode($c[3]));
foreach (['job=' . rawurlencode("{$j1}\r\nLocation: https://evil.example"), 'job=' . rawurlencode('//evil.example'), 'job=' . $j1 . '&next=' . rawurlencode('https://evil.example')] as $i => $q) {
    $jj = $s->sb . "/jar_junk{$i}.txt";
    $s->http('GET', "{$s->base}?page=dashboard&tab=scheduling&{$q}", null, [], $jj);
    $d = $s->http('POST', "{$s->base}?page=login", http_build_query(['action' => 'do_login', 'identifier' => 'tech@example.test', 'password' => 'sj-password-1']),
        ['Content-Type: application/x-www-form-urlencoded'], $jj);
    $ok = $i === 2 ? $d[3] === "?page=dashboard&tab=scheduling&job={$j1}" : $d[3] === '?page=dashboard&tab=form';
    is_($ok && strpos($d[3], 'evil') === false, 'a crafted link (' . ['a header in the number', 'another host as the number', 'an extra address beside it'][$i] . '): nothing but the fixed job address, or the dashboard', json_encode($d[3]));
}

// ═════════════════════════════════════════════════════════════════════════════
echo "\n7. Bulk Dispatch, the screens, J7, the history table\n";
$n0 = count($s->texts()); $m0 = count($s->mails());
$r = $s->api('lead', 'POST', 'bulk_create_jobs', ['job_title' => 'Sandbox batch', 'job_date' => '2026-10-09', 'job_time' => '09:00', 'tasks' => ['Splice'],
    'customers' => [['crm_id' => 15, 'assignee_id' => 1099], ['crm_id' => 15]]]);
$d = $r[2]['data'] ?? [];
$ids = array_column($d['results'] ?? [], 'job_id');
is_(count($texts($s, null, $n0)) === 1 && $texts($s, null, $n0)[0]['number'] === TECH && strpos($texts($s, null, $n0)[0]['text'], 'New Job Has Been Assigned to You') !== false,
    'two jobs, one with an engineer: one message 1, to that engineer', json_encode(array_column($texts($s, null, $n0), 'number')));
is_(($d['whatsapp'] ?? '') === 'partly_sent' && ($d['whatsapp_note'] ?? '') === "Job #{$ids[0]}: WhatsApp sent to the engineer, with the link to accept the job. The same message went to the engineer's e-mail. Job #{$ids[1]}: No WhatsApp was sent: nobody is assigned to the job."
    && ($d['results'][0]['whatsapp'] ?? '') === 'sent', 'the answer says which job\'s message went and why the other\'s did not', json_encode($d, JSON_UNESCAPED_UNICODE));
is_(array_column($mails($s, null, $m0), 'to') === [[TECH_MAIL]] && ($mails($s, null, $m0)[0]['text'] ?? null) === ($texts($s, null, $n0)[0]['text'] ?? ''),
    'and one e-mail, the same text, to that engineer');
$bp = array_slice($s->crmReqs('POST', '#^/scheduling/jobs$#'), -2);
is_(($bp[0]['body']['status'] ?? null) === 0 && ($bp[1]['body']['status'] ?? null) === 0, 'Bulk Dispatch also creates its jobs Open');
$s->login('admin', 'admin@example.test', 'sj-password-1');
$sch = $s->page('admin', 'page=dashboard&tab=scheduling');
is_(strpos($sch, 'The engineer gets a WhatsApp message and the same by e-mail') !== false && strpos($sch, 'No WhatsApp message is sent for jobs yet') === false,
    '＋ New Job says the engineer gets a WhatsApp message and the same by e-mail, and no longer that none is sent');
is_(strpos($sch, "(data.whatsapp === 'sent' ? '📱 ' : '📵 ') + escHtml(data.whatsapp_note)") !== false, 'its result shows the answer\'s note, escaped, with 📱 only when it went');
is_(strpos($s->page('admin', 'page=dashboard&tab=bulk_dispatch'), "(res.whatsapp === 'sent' ? '📱 ' : '📵 ') + escHtml(res.whatsapp_note)") !== false, 'so does Bulk Dispatch\'s');
foreach (['sent' => 'WhatsApp sent to staff account #', 'failed' => 'WhatsApp failed for staff account #', 'skipped' => 'WhatsApp skipped: no staff account'] as $flt => $needle) {
    $ev = $s->page('admin', 'page=dashboard&tab=engage_failed_queue&fqsub=crm_events&ce_filter=' . $flt . '&ce_search=' . rawurlencode($needle));
    is_(strpos($ev, $needle) !== false, "WA Events, filtered to \"{$flt}\", lists the job line \"{$needle}…\" (J7)", strlen($ev) . ' bytes');
}
$all = $s->q('SELECT * FROM job_notify_events');
$flat = json_encode($all, JSON_UNESCAPED_UNICODE);
is_(count($all) > 20 && !preg_match('/\d{9,}/', $flat) && strpos($flat, 'Hi Sandbox') === false && strpos($flat, 'ACCEPT JOB') === false && strpos($flat, '@') === false,
    count($all) . ' history rows: no phone number, no e-mail address and no message text in any');

// Had migration 076 not applied, the history would still keep the message (§16.16): the row without the e-mail's part.
// The table as 075 alone makes it (DROP COLUMN would trip over an unrelated view of the sandbox's schema).
$s->q('DROP TABLE job_notify_events');
$pdo075 = new PDO('sqlite:' . $s->data . '/plugin.sqlite3');
$pdo075->exec((string)file_get_contents($root . '/migrations/075_job_notifications.sql'));
$pdo075 = null;
is_(!in_array('email_outcome', array_column($s->q('PRAGMA table_info(job_notify_events)'), 'name'), true), 'job_notify_events as 075 made it: no e-mail columns');
$jd = 1016;
$setJob($s, $jd, ['assignedUserId' => 1099]);
$n0 = count($s->texts()); $m0 = count($s->mails());
$s->fire('job.add', 'job', $jd, 'sjn-d0');
$e = $s->q('SELECT event, message, outcome FROM job_notify_events WHERE job_id = ?', [$jd]);
is_(count($texts($s, TECH, $n0)) === 1 && count($mails($s, TECH_MAIL, $m0)) === 1 && $e === [['event' => 'assigned', 'message' => 'assigned', 'outcome' => 'sent']],
    'without 076\'s two columns both still go, and the history still has the row', json_encode($e));
$s->stop();

// ═════════════════════════════════════════════════════════════════════════════
echo "\n8. South Sudan: none of this\n";
$ss = SjSandbox::start($root, ['timezone' => 'Africa/Juba'], 'sjn-ss');
$ss->mailRelay(300);
$ss->seedCrm(SjScenario::crm());
SjScenario::staff($ss);
$r = $ss->api('admin', 'POST', 'create_job', ['title' => 'Sandbox installation', 'date' => '2026-10-07', 'time' => '09:00', 'engineer_ids' => [1099], 'crm_client_id' => 15, 'notify_wa' => 1]);
$sj = (int)($r[2]['data']['jobs'][0]['job_id'] ?? 0);
$ss->fire('job.add', 'job', $sj, 'ss-1');
$jobs = (array)($ss->crmDump()['jobs'] ?? []); $jobs[(string)$sj]['assignedUserId'] = 1000; $ss->seedCrm(['jobs' => $jobs]);
$e1 = $ss->fire('job.edit', 'job', $sj, 'ss-2');
$e2 = $ss->fire('job.delete', 'job', $sj, 'ss-3');
$st = $ss->texts();
is_(count($st) === 2 && strpos($st[0]['text'], '🔧 *New Job Assigned to You*') !== false && strpos($st[1]['text'], '🔧 *New Job Assigned*') !== false,
    'South Sudan\'s ＋ New Job and its job.add each send their own message, as before — and job.edit and job.delete nothing', json_encode(array_column($st, 'number')));
is_(($ss->crmReqs('POST', '#^/scheduling/jobs$#')[0]['body']['status'] ?? null) === 1, 'and creates the job at status 1, as before');
is_($ss->mails() === [], 'and no e-mail to anyone: the engineer\'s e-mail copy is Uganda\'s alone');
is_($e1[1] === "{\"status\":\"ok\",\"message\":\"Event 'job.edit' received and logged (no action configured).\"}"
    && $e2[1] === "{\"status\":\"ok\",\"message\":\"Event 'job.delete' received and logged (no action configured).\"}", 'job.edit and job.delete answer exactly as the default did', $e1[1] . ' ' . $e2[1]);
$wl = array_column(array_reverse(json_decode($ss->webhookLog(), true) ?: []), 'message');
is_(count(array_keys($wl, 'Unhandled event type — logged only', true)) === 2, 'and log exactly the default\'s line', json_encode(array_slice($wl, -6), JSON_UNESCAPED_UNICODE));
is_($ss->q('SELECT COUNT(*) AS n FROM job_notify_state')[0]['n'] === 0 && $ss->q('SELECT COUNT(*) AS n FROM job_notify_events')[0]['n'] === 0,
    'the two tables exist and stay empty (the only difference South Sudan can observe)');
$jar = $ss->sb . '/jar_ss.txt';
$ss->http('GET', "{$ss->base}?page=dashboard&tab=scheduling&job={$sj}", null, [], $jar);
$d = $ss->http('POST', "{$ss->base}?page=login", http_build_query(['action' => 'do_login', 'identifier' => 'tech@example.test', 'password' => 'sj-password-1']),
    ['Content-Type: application/x-www-form-urlencoded'], $jar);
is_($d[3] === '?page=dashboard&tab=form', 'and sign-in goes where it always did there', json_encode($d[3]));
$ss->stop();

// ── 9. Weakened copies ───────────────────────────────────────────────────────
if ($withMutants) {
    echo "\n9. Weakened copies of the code must each fail this test\n";
    $MUTANTS = [
        ['no transaction: the state read without the lock', [
            ['lib/JobNotifier.php', "        try {\n            \$pdo->exec('BEGIN IMMEDIATE');\n        } catch (\\Throwable \$e) {\n            return self::result(null, 'unverified', 'the job state could not be locked');\n        }",
             "        // no transaction"],
            ['lib/JobNotifier.php', "            \$pdo->exec('COMMIT');\n        } catch (\\Throwable \$e) {\n            try { \$pdo->exec('ROLLBACK'); } catch (\\Throwable \$e2) { /* nothing to undo */ }\n            return self::result(null, 'unverified', 'the job state could not be read or written');",
             "        } catch (\\Throwable \$e) {\n            return self::result(null, 'unverified', 'the job state could not be read or written');"]]],
        ['no memory: every observation treated as the first', [['lib/JobNotifier.php', '$plan = self::decide($was, $now);', '$plan = self::decide(null, $now);']]],
        ['the state compared with the webhook body', [['webhook.php', "whJobNotify(\$jobId, null, is_array(\$config ?? null) ? \$config : [], (string)\$dataDir, \$crm, \$store, \$notify, \$changeType);\n        whResp(200, \"{\$changeType} processed.\");",
             "whJobNotify(\$jobId, is_array(\$entity) ? \$entity : null, is_array(\$config ?? null) ? \$config : [], (string)\$dataDir, \$crm, \$store, \$notify, \$changeType);\n        whResp(200, \"{\$changeType} processed.\");"]]],
        ['the uCRM user record\'s phone used', [['lib/JobNotifier.php', '$phone = StaffDirectory::phoneOf($row, $this->tenant);',
             '$phone = (string)(($this->crm->get("users/{$ucrmUserId}") ?? [])[\'phone\'] ?? \'\') ?: null;']]],
        ['CLASS_TRANSACTIONAL', [['lib/JobNotifier.php', '$this->notify->sendVia(\'support\', $phone, $text, $log, [], ContactOptOut::CLASS_STAFF);',
             '$this->notify->sendVia(\'support\', $phone, $text, $log, [], ContactOptOut::CLASS_TRANSACTIONAL);']]],
        ['the old ＋ New Job send left in', [['includes/api/api_scheduling.php', "if (!\$_sjUganda && \$notifyWa && \$eng && !empty(\$eng['phone'])) {", "if (\$notifyWa && \$eng && !empty(\$eng['phone'])) {"]]],
        ['no D3 notice after a reassignment', [['lib/JobNotifier.php', "                \$plan['messages'][] = ['kind' => 'reassigned_away', 'to' => \$wasA];\n", '']]],
        ['job.edit not handled on Uganda', [['webhook.php', "    case 'job.edit':\n    case 'JOB_EDIT':", "    case 'job.edit-off':\n    case 'JOB_EDIT':"]]],
        ['South Sudan\'s job.edit sent to the notifier', [['webhook.php', "        if (!StaffJobsGate::applies(is_array(\$config ?? null) ? \$config : [], \$dataDir ?? null)) {\n            whLog(\$changeType, \"Unhandled event type — logged only\"",
             "        if (false) {\n            whLog(\$changeType, \"Unhandled event type — logged only\""]]],
        ['message 2 without its claim', [['lib/JobNotifier.php', "if (is_array(\$row) && (int)(\$row['accepted_by'] ?? 0) === \$assignee) {", 'if (false) {']]],
        ['message 2 on any Accept', [['lib/JobNotifier.php', "if (!is_numeric(\$before['status']) || (int)\$before['status'] !== 0) {", 'if (false) {']]],
        ['the old "Job Accepted" kept on Uganda', [['includes/api/api_scheduling.php', '            } elseif ($engPhone) {', "            }\n            if (\$engPhone) {"]]],
        ['jobs created In progress again', [['includes/api/api_scheduling.php', "'status'         => \$_sjUganda ? 0 : 1, // Open", "'status'         => 1, // Open"]]],
        ['the sign-in return dropped', [['includes/post/post_auth.php', 'if ($_jobReturn !== null) redirect($_jobReturn);', 'if (false) redirect($_jobReturn);']]],
        ['the sign-in return on South Sudan too', [['public.php', "    if (StaffJobsGate::applies(is_array(\$config ?? null) ? \$config : [], \$dataDir)) {\n        require_once __DIR__ . '/lib/JobReturn.php';",
             "    if (true) {\n        require_once __DIR__ . '/lib/JobReturn.php';"],
            ['includes/post/post_auth.php', "        if (StaffJobsGate::applies(is_array(\$config ?? null) ? \$config : [], \$dataDir ?? null)) {\n            require_once dirname(__DIR__, 2) . '/lib/JobReturn.php';",
             "        if (true) {\n            require_once dirname(__DIR__, 2) . '/lib/JobReturn.php';"]]],
        ['no e-mail copy', [['lib/JobNotifier.php', '$mail = $this->email($row, $kind, $text, $fields);', "\$mail = ['outcome' => null, 'detail' => ''];"]]],
        ['the e-mail only when the WhatsApp went', [['lib/JobNotifier.php', '$mail = $this->email($row, $kind, $text, $fields);',
             "\$mail = \$wa['outcome'] === 'sent' ? \$this->email(\$row, \$kind, \$text, \$fields) : ['outcome' => null, 'detail' => ''];"]]],
        ['the e-mail to the address uCRM holds for the user', [['lib/JobNotifier.php', '$to = StaffDirectory::email($row);',
             '$to = (string)(($this->crm->get(\'users/\' . (int)($row[\'ucrm_user_id\'] ?? 0)) ?? [])[\'email\'] ?? \'\');']]],
        ['no stop after the mail server refused', [['lib/JobNotifier.php', "if (!preg_match('/^(RCPT TO|Invalid recipient)/', \$err)) \$this->mailDown = 'the mail server did not take an earlier e-mail in this request';", '']]],
        ['no Reply-To', [['lib/JobNotifier.php', "\$reply !== '' ? ['Reply-To' => \$reply] : []);", '[]);']]],
        ['the e-mail in WA Events\' words', [['lib/JobNotifier.php', "case 'sent':           return '; e-mail handed to the mail server';", "case 'sent':           return '; e-mail sent to the mail server';"]]],
        ['a partial answer trusted', [['lib/JobNotifier.php', "            } elseif ((int)(\$job['id'] ?? \$jobId) !== \$jobId || !self::complete(\$job)) {", "            } elseif (false) {"],
            ['lib/JobNotifier.php', "            'assignee' => (\$a = (int)\$job['assignedUserId']) > 0 ? \$a : null,", "            'assignee' => (\$a = (int)(\$job['assignedUserId'] ?? 0)) > 0 ? \$a : null,"]]],
    ];
    foreach ($MUTANTS as [$label, $edits]) {
        [$tmp, $n] = sj_weakened_copy($root, $edits[0][0], $edits[0][1], $edits[0][2]);
        $bad = $n !== 1 ? "{$edits[0][0]}: found {$n} times" : '';
        foreach (array_slice($edits, 1) as [$rel, $old, $new]) {
            $src = (string)@file_get_contents($tmp . '/' . $rel);
            $k = substr_count($src, $old);
            if ($k !== 1) { $bad .= " {$rel}: found {$k} times"; continue; }
            file_put_contents($tmp . '/' . $rel, str_replace($old, $new, $src));
        }
        if ($bad !== '') { is_(false, "weakened copy \"{$label}\": each anchor occurs once", $bad); exec('rm -rf ' . escapeshellarg($tmp)); continue; }
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
