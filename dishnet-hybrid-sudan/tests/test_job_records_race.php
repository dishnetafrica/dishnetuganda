<?php
declare(strict_types=1);
/**
 * test_job_records_race.php — 5.18.53 (docs/44 §16.28, §16.29): a job message's records survive another process
 * writing while the message is being sent, and a record that cannot be saved is said in the plugin log.
 *
 * On 28 September every Accept on the server sent message 2 and saved none of its records. The claim's read in
 * JobNotifier::accepted() was left open, so SQLite (WAL) kept its read snapshot past the COMMIT. uCRM's notice of the
 * Accept's own status change then made the webhook write the job's record while message 2 was still with WhatsApp, and
 * every later write of the Accept failed at once, each failure swallowed.
 *
 * Here the race is not left to timing: the fake WhatsApp holds the send until the test releases it, and meanwhile the
 * other process writes — through the plugin's own webhook, as uCRM makes it do. The plugin's server answers four
 * requests at once, as PHP-FPM does.
 *   1. the Accept, through the staff app's API, with uCRM's job.edit during message 2 (28 September's trail)
 *   2. the webhook's own send (observe()), with another uCRM notice during it
 *   3. the same Accept on 5.18.52 exactly (Git, 7ad465e): message 2 goes, nothing is saved, nothing is said; and on
 *      5.18.53 with the fix taken out: nothing is saved, and the plugin log names each lost record
 *   4. each of the five records that cannot be saved gets one line in the plugin log — the record, its table and the
 *      error, on the plugin's clock, never a number, an address or a message's text — and the message still goes; a
 *      duplicate echo stays silent; the log is uCRM's, beside the code, wherever the data directory is
 *   5. South Sudan: the same traffic sends what it always sent; its only difference is that line, when a record fails
 *   6. weakened copies of the code each fail this test
 *
 * Every person, number, e-mail and job is fictitious; nothing leaves the machine.
 *
 *   php test_job_records_race.php [--root=DIR] [--no-mutants] [--no-baseline]
 */
$opt  = getopt('', ['root:', 'no-mutants', 'no-baseline']);
$root = isset($opt['root']) ? rtrim((string)$opt['root'], '/') : dirname(__DIR__);
$withMutants  = !isset($opt['no-mutants']);
$withBaseline = !isset($opt['no-baseline']);
require_once __DIR__ . '/fixtures/staff_jobs_scenario.php';

$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; }
    else    { $fail++; echo "  FAIL {$m}" . ($d !== '' ? "\n       {$d}" : '') . "\n"; }
}

const TECH  = '256700000111';   // Sandbox Tech, uCRM user 1099
const TECH2 = '256700000114';   // Sandbox Tech Two, uCRM user 1100
const WHEN  = '2026-10-07T09:00:00+0300';
/** What a refusing trigger answers: with a number and an address in it, which the plugin log must not repeat. */
const REFUSED = 'refused by the test: 256700000111 tech@example.test';
const LINE_RE = '/^\[(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)\] \[records\] not saved: (.+?) \(([a-z_]+)\), (.+?) — (.+)$/u';

/** A Uganda sandbox of $tree whose plugin answers four requests at once; two engineers with verified uCRM links. */
$start = function (string $tree, string $tag): SjSandbox {
    $s = SjSandbox::start($tree, ['tenant_profile' => 'uganda', 'timezone' => 'Africa/Kampala', 'contact_support_phone' => '+256 700 000 100'],
        $tag, ['workers' => 4]);
    file_put_contents($s->plug . '/ucrm.json', json_encode(['pluginDataDir' => $s->data, 'pluginPublicUrl' => $s->base]));
    $crm = SjScenario::crm();
    $crm['users']['1100'] = ['id' => 1100, 'username' => 'sb-tech2', 'firstName' => 'Sandbox', 'lastName' => 'Tech Two', 'email' => 'tech2@example.test', 'isActive' => true];
    $s->seedCrm($crm);
    SjScenario::staff($s);
    $s->staff('tech2', ['name' => 'Sandbox Tech Two', 'email' => 'tech2@example.test', 'role' => 'support', 'phone' => '+256700000114',
        'ucrm_user_id' => 1100, 'ucrm_link' => ['user_id' => 1100, 'email' => 'tech2@example.test', 'verified_at' => '2026-09-27T00:00:00Z', 'verified_by' => 1]]);
    $s->mailRelay(900);
    return $s;
};
$setJob = function (SjSandbox $s, int $id, array $fields): void {
    $jobs = (array)($s->crmDump()['jobs'] ?? []);
    $jobs[(string)$id] = array_merge($jobs[(string)$id] ?? ['id' => $id, 'title' => "Sandbox job {$id}", 'description' => '', 'clientId' => 15,
        'assignedUserId' => 1099, 'date' => WHEN, 'duration' => 60, 'status' => 0, 'address' => 'Plot 9 Sandbox Road Kampala'], $fields);
    $s->seedCrm(['jobs' => $jobs]);
};
$n = function (SjSandbox $s, string $sql, array $p = []): int {
    $r = $s->q($sql, $p);
    return (int)(array_values($r[0] ?? [0])[0] ?? 0);
};
$ver = function (SjSandbox $s, int $job): int {
    return (int)($s->q('SELECT version FROM job_notify_state WHERE job_id = ?', [$job])[0]['version'] ?? -1);
};
$history = function (SjSandbox $s, int $job, int $after = 0): array {
    return array_map(function ($r) { return $r['event'] . '/' . ($r['message'] ?? '-') . '/' . $r['outcome']; },
        $s->q('SELECT event, message, outcome FROM job_notify_events WHERE job_id = ? AND id > ? ORDER BY id', [$job, $after]));
};
$maxId = function (SjSandbox $s, string $table) use ($n): int { return $n($s, "SELECT COALESCE(MAX(id), 0) FROM {$table}"); };
/** The plugin log's "not saved" lines, from line $from on. */
$lines = function (SjSandbox $s, int $from = 0): array {
    $f = $s->plug . '/data/plugin.log';
    $all = is_file($f) ? array_values(array_filter(explode("\n", (string)file_get_contents($f)), function ($l) { return strpos($l, '] [records] ') !== false; })) : [];
    return array_slice($all, $from);
};
/** The message the fake WhatsApp took at 0-based index $i is FAKE-EVO-MSG-($i + 1): its echo claim and its Inbox row carry that id. */
$sent = function (SjSandbox $s, int $from, string $to, string $needle): array {
    $out = [];
    foreach (array_slice($s->texts(), $from, null, true) as $i => $t) {
        if ($t['number'] === $to && strpos($t['text'], $needle) !== false) $out[] = 'FAKE-EVO-MSG-' . ($i + 1);
    }
    return $out;
};
$echoed = function (SjSandbox $s, string $id) use ($n): int { return $n($s, 'SELECT COUNT(*) FROM evo_webhook_seen WHERE message_id = ?', [$id]); };
$inbox  = function (SjSandbox $s, string $id) use ($n): int { return $n($s, "SELECT COUNT(*) FROM wa_messages WHERE wa_message_id = ? AND direction = 'out'", [$id]); };
$exec = function (SjSandbox $s, string $sql): void {
    $pdo = new PDO('sqlite:' . $s->data . '/plugin.sqlite3');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec($sql);
};
$refuse = function (SjSandbox $s, array $tables) use ($exec): void {
    foreach ($tables as $t) $exec($s, "CREATE TRIGGER refuse_{$t} BEFORE INSERT ON {$t} BEGIN SELECT RAISE(ABORT, '" . REFUSED . "'); END");
};
$allow = function (SjSandbox $s, array $tables) use ($exec): void {
    foreach ($tables as $t) $exec($s, "DROP TRIGGER IF EXISTS refuse_{$t}");
};

// ── One request sent while the test goes on: a child process, so the test can act while it waits ────────────────────
$async = function (SjSandbox $s, string $url, array $body, array $headers): array {
    $f = $s->sb . '/async_request.php';
    if (!is_file($f)) file_put_contents($f, '<?php
[$_, $url, $body, $headers] = $argv;
$ch = curl_init($url);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_PROXY => "",
    CURLOPT_NOPROXY => "*", CURLOPT_CUSTOMREQUEST => "POST", CURLOPT_HTTPHEADER => json_decode($headers, true), CURLOPT_POSTFIELDS => $body]);
$r = curl_exec($ch);
echo json_encode(["code" => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), "body" => (string)$r]);
');
    $env = array_filter(getenv(), function ($k) { return stripos((string)$k, 'proxy') === false; }, ARRAY_FILTER_USE_KEY);
    $p = proc_open([PHP_BINARY, $f, $url, json_encode($body), json_encode($headers)],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);
    return [$p, $pipes[1]];
};
$running = function (array $h): bool { return (bool)proc_get_status($h[0])['running']; };
$finish = function (array $h): array {
    [$p, $out] = $h;
    $o = (string)stream_get_contents($out); fclose($out); proc_close($p);
    $r = json_decode($o, true) ?: [];
    return [(int)($r['code'] ?? 0), json_decode((string)($r['body'] ?? ''), true), (string)($r['body'] ?? '')];
};
$waitFor = function (string $f, float $secs = 30.0): bool {
    for ($end = microtime(true) + $secs; microtime(true) < $end; usleep(20000)) if (is_file($f)) return true;
    return false;
};
/** The fake WhatsApp holds its next text send; the returned directory gets "held" when it does, and "release" frees it. */
$hold = function (SjSandbox $s, string $name): string {
    $d = $s->sb . '/gate-' . $name;
    @mkdir($d, 0700, true);
    $s->http('GET', "{$s->evo}/__test/hold?dir=" . rawurlencode($d));
    return $d;
};
$webhookAsync = function (SjSandbox $s, string $changeType, int $id, string $uuid) use ($async): array {
    return $async($s, "{$s->base}?page=crm_webhook", ['changeType' => $changeType, 'entity' => 'job', 'entityId' => $id, 'uuid' => $uuid,
        'extraData' => ['entity' => ['id' => $id]]], ['Content-Type: application/json', 'X-Ucrm-Key: ' . $s->whKey]);
};

/**
 * The Accept of 28 September, on the plugin tree $tree: message 1 first (so the job's record exists), then the staff
 * app's Accept, held at WhatsApp while uCRM's notice of its status change goes through the webhook. Returns the sandbox
 * (still running) and what was measured.
 */
$acceptRace = function (string $tree, string $tag) use ($start, $setJob, $n, $ver, $history, $lines, $sent, $echoed, $inbox, $async, $running,
                                                        $finish, $waitFor, $hold): array {
    $s = $start($tree, $tag);
    $job = 1201;
    $setJob($s, $job, ['title' => 'Sandbox relocation', 'assignedUserId' => 1099, 'status' => 0]);
    $s->fire('job.add', 'job', $job, $tag . '-add');
    $m = ['message1' => count($sent($s, 0, TECH, 'New Job Has Been Assigned to You')), 'v0' => $ver($s, $job), 'lines0' => count($lines($s))];
    $t0 = count($s->texts());
    $gate = $hold($s, 'accept');
    $h = $async($s, "{$s->base}?page=api&action=scheduling_job_update", ['job_id' => $job, 'status' => 'open', 'notify_accept' => 1],
        ['Content-Type: application/json', 'Authorization: Bearer ' . $s->tok['tech']]);
    $m['held'] = $waitFor($gate . '/held');
    $m['v_held'] = $ver($s, $job);
    $m['webhook'] = $s->fire('job.edit', 'job', $job, $tag . '-edit');
    $m['still_sending'] = $running($h);
    $m['v_after'] = $ver($s, $job);
    touch($gate . '/release');
    $m['answer'] = $finish($h);
    $m['ids'] = $sent($s, $t0, TECH, 'Thank you for accepting the job!');
    $id = $m['ids'][0] ?? '-';
    $m['log'] = $n($s, "SELECT COUNT(*) FROM notification_audit_log WHERE event = 'ops_job_accepted_self' AND success = 1 AND phone = ?", [TECH]);
    $m['history'] = $history($s, $job);
    $m['echo'] = $echoed($s, $id);
    $m['inbox'] = $inbox($s, $id);
    $m['lines'] = array_slice($lines($s), $m['lines0']);
    return [$s, $m];
};

// ═════════════════════════════════════════════════════════════════════════════
echo "\n1. The Accept, with uCRM's notice of it arriving while message 2 is with WhatsApp\n";
[$s, $m] = $acceptRace($root, 'jrr-ug');
is_($m['message1'] === 1 && $m['v0'] === 1, 'message 1 went first, through the notifier: the job\'s record exists at version 1, as on the server', json_encode([$m['message1'], $m['v0']]));
is_($m['held'], 'the Accept reached WhatsApp, and WhatsApp holds message 2');
is_($m['v_held'] === 2, 'the Accept\'s claim was committed before the send (version 2)', 'version ' . $m['v_held']);
is_($m['webhook'][0] === 200 && $m['still_sending'] && $m['v_after'] === 3,
    'meanwhile uCRM\'s job.edit went through the webhook and wrote the job\'s record (version 3), with message 2 still unanswered',
    json_encode([$m['webhook'][0], $m['webhook'][1], $m['still_sending'], $m['v_after']]));
is_($m['answer'][0] === 200 && ($m['answer'][1]['data']['whatsapp'] ?? '') === 'sent' && count($m['ids']) === 1,
    'released: the staff app answers "sent", and the engineer got message 2 once', json_encode([$m['answer'][0], $m['answer'][1]['data']['whatsapp'] ?? null, $m['ids']]));
is_($m['log'] === 1, 'its Message Log row is saved (ops_job_accepted_self, sent)', 'rows ' . $m['log']);
is_($m['history'] === ['assigned/assigned/sent', 'accepted/accepted/sent'], 'its history row is saved: accepted, sent', json_encode($m['history']));
is_($m['echo'] === 1, 'its echo claim is saved, so its echo is not mistaken for a colleague typing', 'rows ' . $m['echo']);
is_($m['inbox'] === 1, 'its conversation-store row is saved: the Inbox shows it', 'rows ' . $m['inbox']);
is_($m['lines'] === [], 'and the plugin log has no "not saved" line', json_encode($m['lines'], JSON_UNESCAPED_UNICODE));

// ═════════════════════════════════════════════════════════════════════════════
echo "\n2. The webhook's own send, with another uCRM notice arriving while it is with WhatsApp\n";
$jx = 1301; $jy = 1302;
$setJob($s, $jx, ['title' => 'Sandbox survey', 'assignedUserId' => 1099]);
$s->fire('job.add', 'job', $jx, 'jrr-x-add');
$setJob($s, $jx, ['assignedUserId' => 1100]);
$t0 = count($s->texts()); $l0 = count($lines($s)); $e0 = $maxId($s, 'job_notify_events');
$v0 = $ver($s, $jx);
$gate = $hold($s, 'observe');
$h = $webhookAsync($s, 'job.edit', $jx, 'jrr-x-edit');
$held = $waitFor($gate . '/held');
$vHeld = $ver($s, $jx);
$setJob($s, $jy, ['title' => 'Sandbox site visit', 'assignedUserId' => null]);
$wy = $s->fire('job.add', 'job', $jy, 'jrr-y-add');
$still = $running($h);
$yRow = $ver($s, $jy);
touch($gate . '/release');
$wx = $finish($h);
is_($held && $vHeld === $v0 + 1, 'uCRM\'s job.edit (the job given to Tech Two) was claimed, then held at WhatsApp', json_encode([$held, $v0, $vHeld]));
is_($wy[0] === 200 && $still && $yRow === 1, 'meanwhile uCRM\'s job.add for another job went through the webhook and wrote that job\'s record, with nothing to send',
    json_encode([$wy[0], $still, $yRow]));
$new = $sent($s, $t0, TECH2, 'New Job Has Been Assigned to You');
$away = $sent($s, $t0, TECH, 'no longer assigned');
is_($wx[0] === 200 && count($new) === 1 && count($away) === 1, 'released: message 1 to Tech Two, and "no longer assigned" to Tech', json_encode([$wx[0], $new, $away]));
is_($n($s, "SELECT COUNT(*) FROM notification_audit_log WHERE event = 'job_assigned' AND success = 1 AND phone = ?", [TECH2]) === 1
    && $n($s, "SELECT COUNT(*) FROM notification_audit_log WHERE event = 'job_unassigned' AND success = 1 AND phone = ?", [TECH]) === 1,
    'both Message Log rows are saved');
is_($history($s, $jx, $e0) === ['reassigned/assigned/sent', 'reassigned/reassigned_away/sent'], 'both history rows are saved', json_encode($history($s, $jx, $e0)));
is_($echoed($s, $new[0] ?? '-') === 1 && $echoed($s, $away[0] ?? '-') === 1 && $inbox($s, $new[0] ?? '-') === 1 && $inbox($s, $away[0] ?? '-') === 1,
    'both echo claims and both conversation-store rows are saved');
is_(array_slice($lines($s), $l0) === [], 'and the plugin log has no "not saved" line', json_encode(array_slice($lines($s), $l0), JSON_UNESCAPED_UNICODE));

// ═════════════════════════════════════════════════════════════════════════════
if ($withBaseline) {
    echo "\n3. The same Accept without the fix\n";
    [$tree52, $why] = sj_baseline_tree('7ad465e');
    if ($tree52 === null) {
        is_(false, '5.18.52 (7ad465e) from Git', $why);
    } else {
        [$b, $bm] = $acceptRace($tree52, 'jrr-52');
        $b->stop();
        is_($bm['held'] && $bm['v_held'] === 2 && $bm['webhook'][0] === 200 && $bm['still_sending'] && $bm['v_after'] === 3,
            '5.18.52 exactly (7ad465e): the same race — the claim, then uCRM\'s notice written while message 2 is held', json_encode([$bm['held'], $bm['v_held'], $bm['still_sending'], $bm['v_after']]));
        is_(($bm['answer'][1]['data']['whatsapp'] ?? '') === 'sent' && count($bm['ids']) === 1, 'message 2 goes, and the staff app says "sent"',
            json_encode([$bm['answer'][1]['data']['whatsapp'] ?? null, $bm['ids']]));
        is_($bm['log'] === 0 && $bm['history'] === ['assigned/assigned/sent'] && $bm['echo'] === 0 && $bm['inbox'] === 0,
            'and none of its four records is saved: no Message Log row, no history row, no echo claim, no Inbox row — the server\'s trail',
            json_encode([$bm['log'], $bm['history'], $bm['echo'], $bm['inbox']]));
        is_($bm['lines'] === [] && !is_file($b->plug . '/data/plugin.log'), 'and nothing says so: 5.18.52 writes nothing to the plugin log');
    }
    $old = "            \$st->closeCursor();\n            if (is_array(\$row) && (int)(\$row['accepted_by'] ?? 0) === \$assignee) {";
    [$tmp, $k] = sj_weakened_copy($root, 'lib/JobNotifier.php', $old, "            if (is_array(\$row) && (int)(\$row['accepted_by'] ?? 0) === \$assignee) {");
    if ($k !== 1) {
        is_(false, '5.18.53 with the fix taken out of accepted(): the anchor occurs once', "found {$k} times");
    } else {
        [$w, $wm] = $acceptRace($tmp, 'jrr-nofix');
        $w->stop();
        $named = array_map(function ($l) { return preg_match(LINE_RE, $l, $x) ? $x[3] . ' | ' . $x[4] . ' | ' . $x[5] : '?' . $l; }, $wm['lines']);
        is_(($wm['answer'][1]['data']['whatsapp'] ?? '') === 'sent' && count($wm['ids']) === 1 && $wm['log'] === 0 && $wm['echo'] === 0 && $wm['inbox'] === 0
            && $wm['history'] === ['assigned/assigned/sent'],
            '5.18.53 with only the fix taken out: message 2 goes and its records are lost again', json_encode([$wm['log'], $wm['echo'], $wm['inbox'], $wm['history']]));
        is_($named === ['evo_webhook_seen | event notify.support | SQLSTATE[HY000]: General error: 5 database is locked',
                        'notification_audit_log | event ops_job_accepted_self | SQLSTATE[HY000]: General error: 5 database is locked',
                        'wa_messages | event ops_job_accepted_self | SQLSTATE[HY000]: General error: 5 database is locked',
                        'job_notify_events | job #1201, accepted/accepted | SQLSTATE[HY000]: General error: 5 database is locked'],
            'but not in silence: the plugin log names each lost record — the echo claim, the Message Log row, the Inbox row, the history row — and the error',
            json_encode($named, JSON_UNESCAPED_UNICODE));
        exec('rm -rf ' . escapeshellarg($tmp));
    }
}

// ═════════════════════════════════════════════════════════════════════════════
echo "\n4. A record that cannot be saved: one line in the plugin log, and the message still goes\n";
$tables = ['notification_audit_log', 'job_notify_events', 'wa_messages', 'evo_webhook_seen', 'notification_queue'];
$refuse($s, $tables);
$t0 = count($s->texts()); $l0 = count($lines($s));
$setJob($s, $jx, ['assignedUserId' => 1099]);
$r = $s->fire('job.edit', 'job', $jx, 'jrr-x-back');
$back = $sent($s, $t0, TECH, 'New Job Has Been Assigned to You'); $gone = $sent($s, $t0, TECH2, 'no longer assigned');
is_($r[0] === 200 && strpos($r[1], 'job.edit processed.') !== false && count($back) === 1 && count($gone) === 1,
    'every record refused: the job given back to Tech still sends both messages, and the webhook answers as always', json_encode([$r[0], $r[1], $back, $gone]));
$got = array_slice($lines($s), $l0);
$parsed = array_map(function ($l) { return preg_match(LINE_RE, $l, $x) ? $x[2] . ' (' . $x[3] . '), ' . $x[4] . ' — ' . $x[5] : '?' . $l; }, $got);
$want = [];
foreach (['job_assigned' => 'reassigned/assigned', 'job_unassigned' => 'reassigned/reassigned_away'] as $ev => $hist) {
    $want[] = 'the echo claim (evo_webhook_seen), event notify.support';
    $want[] = "the Message Log row (notification_audit_log), event {$ev}";
    $want[] = "the conversation-store row (wa_messages), event {$ev}";
    $want[] = "the job history row (job_notify_events), job #{$jx}, {$hist}";
}
$want = array_map(function ($w) { return $w . ' — SQLSTATE[23000]: Integrity constraint violation: 19 refused by the test: <number> <e-mail>'; }, $want);
is_($parsed === $want, 'one line per lost record, in the order they were lost: the record, its table, the event or the job, and the error',
    json_encode($parsed, JSON_UNESCAPED_UNICODE));
if (isset($got[1])) echo '         e.g. ', $got[1], "\n";
$private = implode("\n", $got);
is_($got !== [] && strpos($private, TECH) === false && strpos($private, TECH2) === false && strpos($private, '@') === false
    && strpos($private, 'Sandbox') === false && strpos($private, 'Hi ') === false && strpos($private, 'Plot 9') === false,
    'no line carries a number, an address, a name or a message\'s text — the error\'s own number and address are masked');
$clockOk = $got !== [];
foreach ($got as $l) {
    if (!preg_match(LINE_RE, $l, $x)) { $clockOk = false; continue; }
    $t = DateTime::createFromFormat('Y-m-d H:i:s', $x[1], new DateTimeZone('Africa/Kampala'));
    if (!$t || abs($t->getTimestamp() - time()) > 120) $clockOk = false;
}
is_($clockOk, 'each line carries the plugin\'s clock (Kampala), as the master cron\'s own lines do', $got[0] ?? '');

// The failure queue: a send that fails keeps its text there for a retry — and when that row cannot be saved either.
$l0 = count($lines($s));
$s->http('GET', "{$s->evo}/__test/fail_next?n=1");
$setJob($s, $jx, ['date' => '2026-10-08T11:00:00+0300']);
$r = $s->fire('job.edit', 'job', $jx, 'jrr-x-time');
$parsed = array_map(function ($l) { return preg_match(LINE_RE, $l, $x) ? $x[2] . ' (' . $x[3] . '), ' . $x[4] : '?' . $l; }, array_slice($lines($s), $l0));
is_($r[0] === 200 && $parsed === ['the Message Log row (notification_audit_log), event job_rescheduled', 'the failure-queue row (notification_queue), event job_rescheduled',
        "the job history row (job_notify_events), job #{$jx}, rescheduled/new_time"],
    'a send that fails, with its failure-queue row refused too: a line for that row as well', json_encode($parsed, JSON_UNESCAPED_UNICODE));

$allow($s, $tables);
$l0 = count($lines($s)); $t0 = count($s->texts());
$setJob($s, $jx, ['date' => '2026-10-09T11:00:00+0300']);
$s->fire('job.edit', 'job', $jx, 'jrr-x-time2');
is_(count($sent($s, $t0, TECH, 'has a new time')) === 1 && array_slice($lines($s), $l0) === [],
    'control: the triggers removed, the next change sends and saves, and writes no line — the lines came from the refusals alone',
    json_encode(array_slice($lines($s), $l0), JSON_UNESCAPED_UNICODE));

// Two things a line must never be: a duplicate echo is the guard doing its job, and the log is uCRM's, wherever the data is.
$child = function (string $code, array $env = []) use ($s): string {
    $e = array_filter(getenv(), function ($k) { return stripos((string)$k, 'proxy') === false; }, ARRAY_FILTER_USE_KEY);
    $p = proc_open([PHP_BINARY, '-r', $code, $s->plug, $s->data], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + $e);
    $o = (string)stream_get_contents($pipes[1]); $err = (string)stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    return trim($o . ($err !== '' ? ' STDERR ' . $err : ''));
};
$l0 = count($lines($s));
$dup = $child('[$_, $plug, $data] = $argv; require $plug . "/lib/EvoWebhookGuard.php";
$pdo = new PDO("sqlite:" . $data . "/plugin.sqlite3"); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $pdo->exec("PRAGMA busy_timeout = 5000");
$g = new EvoWebhookGuard($pdo, ["evo_webhook_secret" => "sj"]);
echo json_encode([$g->claim("SJ-DUP-1", "sj-support", "notify.support"), $g->claim("SJ-DUP-1", "sj-support", "notify.support")]);');
is_($dup === '[true,false]' && array_slice($lines($s), $l0) === [], 'the same message claimed twice: the second is a duplicate, and a duplicate writes no line',
    $dup . ' ' . json_encode(array_slice($lines($s), $l0)));
$elsewhere = $s->sb . '/elsewhere';
@mkdir($elsewhere, 0700, true);
$path = $child('[$_, $plug] = $argv; require $plug . "/lib/PluginLog.php"; echo PluginLog::path();', ['DN_DATA_DIR' => $elsewhere]);
is_($path === $s->plug . '/data/plugin.log', 'the log is uCRM\'s for this plugin, data/plugin.log beside the code — not the data directory, which on the server is elsewhere',
    $path);
$s->stop();

// ═════════════════════════════════════════════════════════════════════════════
echo "\n5. South Sudan\n";
$ss = SjSandbox::start($root, ['timezone' => 'Africa/Juba'], 'jrr-ss');
$ss->seedCrm(SjScenario::crm());
SjScenario::staff($ss);
$job = ['title' => 'Sandbox installation', 'date' => '2026-10-07', 'time' => '09:00', 'engineer_ids' => [1099], 'crm_client_id' => 15, 'notify_wa' => 1];
$r = $ss->api('admin', 'POST', 'create_job', $job);
$st = $ss->texts();
is_($r[0] === 200 && count($st) === 1 && $st[0]['number'] === TECH && strpos($st[0]['text'], '🔧 *New Job Assigned to You*') !== false,
    '＋ New Job sends South Sudan\'s own message, as before', json_encode(array_column($st, 'number')));
is_($lines($ss) === [] && $ss->q('SELECT COUNT(*) AS n FROM job_notify_state')[0]['n'] === 0 && $ss->q('SELECT COUNT(*) AS n FROM job_notify_events')[0]['n'] === 0,
    'no line in the plugin log, and the notifier\'s tables stay empty');
$refuse($ss, ['notification_audit_log']);
$r = $ss->api('admin', 'POST', 'create_job', $job);
$sl = array_map(function ($l) { return preg_match(LINE_RE, $l, $x) ? $x[2] . ' (' . $x[3] . ')' : '?' . $l; }, $lines($ss));
is_($r[0] === 200 && count($ss->texts()) === 2 && $sl === ['the Message Log row (notification_audit_log)'],
    'its only difference: a record that cannot be saved now has its line there too — and the message still goes', json_encode([$r[0], count($ss->texts()), $sl], JSON_UNESCAPED_UNICODE));
$ss->stop();

// ── 6. Weakened copies ───────────────────────────────────────────────────────
if ($withMutants) {
    echo "\n6. Weakened copies of the code must each fail this test\n";
    $MUTANTS = [
        ['accepted() leaves the claim\'s read open', [['lib/JobNotifier.php', "            \$st->closeCursor();\n            if (is_array(\$row) && (int)(\$row['accepted_by'] ?? 0) === \$assignee) {",
            "            if (is_array(\$row) && (int)(\$row['accepted_by'] ?? 0) === \$assignee) {"]]],
        ['observe() leaves the state\'s read open', [['lib/JobNotifier.php', "            \$st->closeCursor();   // 5.18.53: the read ends before the COMMIT, as in accepted()\n", '']]],
        ['the Message Log row lost in silence', [['lib/NotificationService.php', "            self::notSaved('the Message Log row', 'notification_audit_log', (string)(\$entry['event'] ?? ''), \$e);\n", '']]],
        ['the failure-queue row lost in silence', [['lib/NotificationService.php', "            self::notSaved('the failure-queue row', 'notification_queue', \$event, \$e);\n", '']]],
        ['the conversation-store row lost in silence', [['lib/NotificationService.php', "                self::notSaved('the conversation-store row', 'wa_messages', \$event, \$e);\n", '']]],
        ['the echo claim lost in silence', [['lib/EvoWebhookGuard.php', "                \\PluginLog::notSaved('the echo claim', 'evo_webhook_seen', 'event ' . \$event, \$e);\n", '']]],
        ['the history row lost in silence', [['lib/JobNotifier.php',
            "            PluginLog::notSaved('the job history row', 'job_notify_events', \"job #{\$jobId}, {\$event}\" . (\$message !== null ? \"/{\$message}\" : ''), \$failed);\n", '']]],
        ['a duplicate echo reported as lost', [['lib/EvoWebhookGuard.php', "if (!preg_match('/(UNIQUE|PRIMARY KEY) constraint failed/', \$e->getMessage()) && is_file(", 'if (is_file(']]],
        ['the error written unmasked', [['lib/PluginLog.php', "        \$text = self::mask(\$text);\n", ''],
            ['lib/PluginLog.php', "' — ' . self::mask(\$e->getMessage()));", "' — ' . \$e->getMessage());"]]],
        ['the line written to the data directory', [['lib/PluginLog.php', "        return dirname(__DIR__) . '/data/plugin.log';",
            "        return (getenv('DN_DATA_DIR') ?: dirname(__DIR__) . '/data') . '/plugin.log';"]]],
        ['the line only in PHP\'s error log', [['lib/PluginLog.php', '        if (is_dir($dir) && (is_file($path)', '        if (false && is_dir($dir) && (is_file($path)']]],
        ['a lost record stops the send', [['lib/NotificationService.php', "            self::notSaved('the Message Log row', 'notification_audit_log', (string)(\$entry['event'] ?? ''), \$e);\n",
            "            self::notSaved('the Message Log row', 'notification_audit_log', (string)(\$entry['event'] ?? ''), \$e);\n            throw \$e;\n"]]],
    ];
    foreach ($MUTANTS as [$label, $edits]) {
        [$tmp, $k] = sj_weakened_copy($root, $edits[0][0], $edits[0][1], $edits[0][2]);
        $bad = $k !== 1 ? "{$edits[0][0]}: found {$k} times" : '';
        foreach (array_slice($edits, 1) as [$rel, $old, $new]) {
            $src = (string)@file_get_contents($tmp . '/' . $rel);
            $c = substr_count($src, $old);
            if ($c !== 1) { $bad .= " {$rel}: found {$c} times"; continue; }
            file_put_contents($tmp . '/' . $rel, str_replace($old, $new, $src));
        }
        if ($bad !== '') { is_(false, "weakened copy \"{$label}\": each anchor occurs once", $bad); exec('rm -rf ' . escapeshellarg($tmp)); continue; }
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --root=' . escapeshellarg($tmp) . ' --no-mutants --no-baseline 2>&1', $out, $rc);
        $fails = array_values(array_filter($out, function ($l) { return strpos($l, '  FAIL ') === 0; }));
        is_($rc !== 0 && $fails !== [], "caught: {$label}", 'exit ' . $rc . ', ' . count($fails) . ' failure(s)');
        if ($fails) echo '         first: ' . trim(substr($fails[0], 7, 110)) . "\n";
        exec('rm -rf ' . escapeshellarg($tmp));
    }
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
