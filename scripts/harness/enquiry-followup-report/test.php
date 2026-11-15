<?php
declare(strict_types=1);
/**
 * test.php REPORT BUILD NOW — runs the report on copies of build.php's book and checks every line it prints.
 *
 * Exact lines, derived by hand from build.php's rows (S0-S11, B1-B49, X1-X7, C-a…C-i, the ledger rows, the book's
 * settings row, the opt-outs, the reopened C-k); every date in them is derived from NOW. Eighteen runs on the same book:
 *   R2  root2 + data    the run limit 2, the cap 3, salesperson numbers followed up, the ledger recording
 *   R1  root1 + data1   the run limit 1, the cap 1, salesperson numbers HELD, a floor 4 days back, the ledger claiming,
 *                       its daily and hourly caps 2
 *   R3  root2 + data3   hostile values: followup_enabled 'off' (still on to the jobs), the cap 0, a floor that is not a
 *                       date (the scan then opens nothing), the run limit -1 (every due row)
 *   R4  root2 + data    NOW 14 days earlier: one row due, waiting for a person, fewer than the run takes; nothing judged
 *                       in the 24 h before it
 *   R5  root2 + data4   the run limit 0, an empty scan limit, wa_send_ledger 'yes' (off: only 'on' is on)
 *   R6  root2 + data    NOW two days later, the run's last start a day before it: a row it would judge was due then,
 *                       and nothing was judged since
 *   R7  root1 + data1   a Monday at 18:00 Kampala: inside 08:00-20:00, outside the ledger's own 09:00-18:00
 *   R8-R14 R6 on a copy with one thing changed. Nothing is said when the run's last pass explains it: its last start
 *                       before the front row's last decision (R8), before it was due (R9), before it was opened (R10); a
 *                       policy refusal (a paused number) logged since, every due row taken (R11); a follow-up ahead of
 *                       it sent since (R12); a
 *                       gate answer just after a start 30 h back (R13). It is said when the only answers came 3 h before a
 *                       start 10 minutes back (R14)
 *   R15-R17 R6 with public.php's last admin-page run of master 5 s before the run's last start (that cycle stamped it, and
 *                       the job does nothing there: NOT ESTABLISHED, R15), an hour before it (R16), a minute after it (R17)
 *   R18 R2 on a copy where the sender's two places hold other numbers' shapes (no country code; 15 digits not 256) and S4
 *                       failed three ways since its approval, and once before it (section J)
 * Then: no PLANT, no phone number, no name and no secret in any output, and nothing that needed the last digit mask (the
 * report's own filters must hold first); the copy unchanged; a copy only another user can read (root's, 0444) reads
 * exactly as R2, with a control that that user's write is refused; and "changes on the copy: 0". Exit 0 only when all pass.
 */
[$report, $build, $nowIso] = array_slice($argv, 1, 3) + [null, null, null];
if (!$report || !$build || !$nowIso) { fwrite(STDERR, "usage: test.php REPORT BUILD NOW\n"); exit(2); }
if (!is_file("$build/data/plugin.sqlite3") || !is_file($report)) { fwrite(STDERR, "no book under BUILD, or no REPORT — run build.php first\n"); exit(2); }
$pass = 0; $fail = 0;
$ok = function (bool $c, string $m, string $d = '') use (&$pass, &$fail): void {
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d !== '' ? "\n       " . str_replace("\n", "\n       ", $d) : '') . "\n"; }
};
$NOW = strtotime($nowIso . ' UTC');
$md = function (float $h) use ($NOW): string { return gmdate('m-d', (int)round($NOW + $h * 3600)); };
$klaTs = $NOW + 3 * 3600;                                          // Africa/Kampala is UTC+3, no daylight saving
$klaDay = gmdate('Y-m-d', $klaTs); $klaLabel = gmdate('D H:i', $klaTs);
$klaIn = gmdate('w', $klaTs) !== '0' && (int)gmdate('G', $klaTs) >= 8 && (int)gmdate('G', $klaTs) < 20;

$tmp = sys_get_temp_dir() . '/efr-test-' . getmypid() . '-' . bin2hex(random_bytes(3));
mkdir($tmp, 0711);
register_shutdown_function(function () use ($tmp): void { exec('rm -rf ' . escapeshellarg($tmp)); });
// the read-only run: the book copy is root's and 0444, so another user cannot write it (root ignores file modes, so
// when this runs as root that run drops to uid 65534, which owns only the folder — where SQLite may keep its WAL index);
// everything the report reads is copied where that user can read it
$asRoot = trim((string)shell_exec('id -u')) === '0';
$copyIn = function (string $from, string $to): void {
    mkdir($to, 0755, true);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) {
        $t = $to . '/' . substr($f->getPathname(), strlen($from) + 1);
        if ($f->isDir()) { @mkdir($t, 0755, true); continue; }
        if (substr($f->getFilename(), -5) !== '.json' && substr($f->getFilename(), -4) !== '.php') continue;   // never the book
        copy($f->getPathname(), $t); chmod($t, 0644);
    }
};
$run = function (string $root, string $tag, bool $readOnly = false, string $data = 'data', ?string $now = null, ?callable $alter = null) use ($report, $build, $nowIso, $tmp, $asRoot, $copyIn): array {
    $dir = "$tmp/$tag"; mkdir($dir, 0700);
    copy("$build/data/plugin.sqlite3", "$dir/copy.sqlite3");
    if ($alter !== null) {   // R8-R11: one change to this run's copy, before the run (and before the copy's hash)
        $p = new PDO('sqlite:' . "$dir/copy.sqlite3", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); $alter($p); $p = null;
    }
    $before = hash_file('sha256', "$dir/copy.sqlite3");
    [$rep, $rootDir, $dataDir, $vault, $prefix] = [$report, "$build/$root", "$build/$data", "$build/vault.json", ''];
    if ($readOnly) {
        $in = "$tmp/$tag-in";
        $copyIn("$build/$root", "$in/$root"); $copyIn("$build/$data", "$in/$data");
        copy($report, "$in/report.php"); chmod("$in/report.php", 0644); copy($vault, "$in/vault.json"); chmod("$in/vault.json", 0644);
        [$rep, $rootDir, $dataDir, $vault] = ["$in/report.php", "$in/$root", "$in/$data", "$in/vault.json"];
        chmod("$dir/copy.sqlite3", 0444);
        if ($asRoot) { chown($dir, 65534); chgrp($dir, 65534); $prefix = 'setpriv --reuid=65534 --regid=65534 --clear-groups '; }
        else chmod($dir, 0555);
    }
    $cmd = $prefix . 'env DN_VAULT_FILE=' . escapeshellarg($vault) . ' ' . escapeshellarg(PHP_BINARY) . ' -d log_errors=0 -d display_errors=stderr '
         . escapeshellarg($rep) . ' ' . escapeshellarg("--db=$dir/copy.sqlite3") . ' ' . escapeshellarg("--root=$rootDir")
         . ' ' . escapeshellarg("--data=$dataDir") . ' ' . escapeshellarg('--now=' . ($now ?? $nowIso)) . ' 2>&1';
    $o = []; exec($cmd, $o, $rc);
    $after = hash_file('sha256', "$dir/copy.sqlite3");
    $side = [];
    foreach (glob("$dir/copy.sqlite3-*") ?: [] as $f) {
        if (substr($f, -4) === '-shm') continue;       // the WAL index a reader of a WAL book makes; it holds no row
        $side[basename($f)] = filesize($f);
    }
    if ($readOnly) { if ($asRoot) chown($dir, 0); chmod($dir, 0700); chmod("$dir/copy.sqlite3", 0600); }
    return ['out' => implode("\n", $o), 'rc' => $rc, 'same' => $before === $after, 'side' => $side];
};
$has = function (string $out, string $line): bool { return in_array($line, explode("\n", $out), true); };
$expect = function (array $r, string $tag, array $lines) use ($ok, $has): void {
    foreach ($lines as $w) $ok($has($r['out'], $w), "$tag prints: " . trim($w), "not found; the report printed:\n" . $r['out']);
};
$absent = function (array $r, string $tag, string $needle, string $why) use ($ok): void {
    $ok(strpos($r['out'], $needle) === false, "$tag does not print \"" . trim($needle) . "\" ($why)");
};

$JAM = 'JAMMED — every place the run takes holds a draft waiting for a person, so it judges no other follow-up until they are decided (Approve or Reject on the Follow-ups screen)';
$NOTJAM = 'not jammed — every due one waits for a person, but fewer are due than the run takes, so a newly due one is still judged';
$windowLine = '  now ' . $klaLabel . ' Kampala: ' . ($klaIn ? 'INSIDE' : 'OUTSIDE') . ' the sending window (08:00-20:00, Mon-Sat)' . ($klaIn ? '' : ' — every approved follow-up waits for it');
$windowLineClaim = '  now ' . $klaLabel . ' Kampala: ' . ($klaIn ? 'INSIDE' : 'OUTSIDE') . ' the sending window (08:00-20:00, Mon-Sat; while the ledger claims, also its own 09:00-18:00)' . ($klaIn ? '' : ' — every approved follow-up waits for it');   // data1: 9-18

// ── R2 ─────────────────────────────────────────────────────────────────────────────────────────────────────────
echo "-- R2: followup_run_limit 2, followup_daily_cap 3, salesperson numbers followed up, the ledger recording\n";
$r2 = $run('root2', 'r2');
$ok($r2['rc'] === 0, 'R2 exits 0', "rc={$r2['rc']}\n{$r2['out']}");
$expect($r2, 'R2', [
    '== Summary ==',
    '  the follow-up jobs run · automatic approval on · salesperson numbers not held',
    '  quiet conversations with no open follow-up (1-30 days): 14 — never followed up this quiet spell 4 · opened AGAIN by every scan 4 · on a held salesperson number 0 · before followup_not_before 0 · older than followup_max_age_hours 1',
    '  reopened by the scan for the same quiet spell, 7 days: 2 of 23 opened · the gate\'s answers spent on them: 2 of 7',
    '  open 9, due now 5 · the run\'s front: partly blocked',
    '  the Follow-ups screen: 49 of its 50 cards are on a closed follow-up · the front\'s waiting drafts not on the screen: 1',
    '  approved and waiting to send: 3 (failed at least once: 1) · ' . ($klaIn ? 'inside' : 'OUTSIDE') . ' the sending window now',
    '  the last enquiry follow-up sent: 60 min ago · last 7 days: 2',
    '  the assistant, 7 days: SEND 6 · DO_NOT_SEND 5, of which AI failures 3 · closed not_interested after an AI failure in 14 days: 3',
    '  limits: followup_daily_cap 3 (after 3 sends on a number today, UTC, the run judges none of its follow-ups) · followup_run_limit 2 (judged per run, every 10 min) · the ledger record',
    '== A. The settings, as each follow-up job reads them ==',
    '  followup_enabled: the four follow-up jobs RUN · followup_auto_send on — a SEND draft is approved by the run itself when the customer is enquiry-level, the message carries no escalation word, no person is awaited on the chat and the number may send (otherwise a person decides)',
    '  salesperson numbers: followed up (wa_followups_on_owned_numbers on)',
    '  followup_daily_cap 3 — once this many were SENT on a number today (UTC) the run judges none of its follow-ups (it puts each back for the next run); the sender itself has no cap · followup_run_limit 2 — follow-ups judged per run (every 10 min)',
    '  followup_scan_limit 25 (default) — opened per scan (every 10 min) · followup_max_age_hours 336 (default) — older quiet conversations are never opened · followup_not_before 2026-09-12 11:11:02',
    '  the send ledger: on, RECORD — each enquiry send is written after it goes; not paced',
    '  the book\'s own settings row (master and the Follow-ups screen read it): master_budget_seconds 240 · followup_enabled off — DIFFERS from what the jobs read: the screen says follow-ups are off',
    '== B. The follow-up jobs in master\'s schedule (UTC) ==',
    '  master: the newest job start 1 min ago · jobs whose last start did not finish (duration -1): 1 · budget 240 s a cycle',
    '  followup_close: last start 5 min ago · took 120 ms',
    '  followup_scan: last start 15 min ago · took 300 ms',
    '  followup_run: last start 10 min ago · DID NOT FINISH',
    '  followup_send: last start 2 h ago · took 1500 ms',
    '== C. Quiet conversations with no open follow-up (the customer\'s last message 1-30 days ago), as the scan sees them ==',
    '  14 in all — eligible_not_opened 4 · reopened_each_scan 4 · older_than_max_age 1 · quotation_chased 1 · opted_out 2 · person_has_it 1 · colleague 1',
    '  eligible but not opened, by number: sales (dept) 1 · sales-001 (seller) 1 · support (dept) 2',
    '  eligible but not opened, by time since the customer\'s last message: 1-3d:2 3-7d:1 7-14d:1 14-30d:0',
    '  followed up and closed during this quiet spell, so the scan opens them AGAIN (due at once, at the front of the run): by how the last one closed: other 1 · escalated 1 · exhausted 1 · human_closed 1',
    '  (by these rules; the scan also leaves out a colleague\'s or DishNet\'s own number, a number that may send nothing automated, and several customers on one number)',
    '== D. Open enquiry follow-ups ==',
    '  open 9 · due now 5 · not yet due 4',
    '  open by number: other 1 · sales (dept) 4 · sales-001 (seller) 1 · sales-002 (seller, paused) 1 · support (dept) 2',
    '  open, by time since opened: <1d:0 1-3d:7 3-7d:0 >7d:2',
    '  open, by time since the customer\'s last message: <3d:4 3-7d:3 7-14d:0 >14d:2',
    '  due now, by time since due_at was last set (the run moves it overnight, at the cap and on a WAIT — not the customer\'s wait): <1h:1 1h-1d:0 1-3d:1 >3d:3',
    '  attempts 0: 7 · attempts 1: 2',
    '  open with a draft waiting for a person: 3 · open with an approved draft not yet sent: 3 (one that is due is judged again on each pass)',
    '== E. The run\'s front (what followup_run.php takes each run) and the Follow-ups screen ==',
    '  the first 2 due (followup_run_limit 2): 1 wait for a person · 1 hold an approved, unsent draft — partly blocked',
    '  the first 5 due: 3 wait for a person',
    '  the Follow-ups screen shows the 50 oldest waiting drafts of every kind, open or closed: 61 wait in all · on its 50 cards: 49 on a CLOSED follow-up (nothing to send — Reject clears each), 1 quotation drafts · of the front\'s 1 waiting drafts: 1 beyond those 50 — NOT ON THE SCREEN',
    '  waiting drafts on a CLOSED follow-up, every kind: 57 (the oldest written 16 days ago) — DO_NOT_SEND 54 · ESCALATE_TO_HUMAN 1 · other 1 · SEND 1',
    '== F. Drafts (the messages the assistant wrote) ==',
    '  all time, by status: approved 4 · pending 60 · sent 2',
    '  approved, not sent: 3 on an OPEN follow-up (the real queue the sender reads) · 1 never sent because the follow-up closed first — by reason: human_closed 1',
    '  the real queue, by time since approval: <1h:1 1h-1d:1 1-7d:0 >7d:1',
    '  the real queue by number: sales-001 (seller) 1 · sales-002 (seller, paused) 1 · support (dept) 1',
    '  the real queue, approved by: auto:2 staff:1 other:0',
    '  the real queue, failed at least once since approval: 1 (a failed send stays approved and goes again first) · the sender\'s 10 places (the 10 oldest approved, a paused number\'s left out): 2 taken, 1 failed before, 2 numbers, 0 closed when reached (number disabled or retired) · on a paused number, taking no place: 1',
    '  in the real queue, the sender will CLOSE these, not send them: replied:0 human_closed:2 cancelled:0 (replied: the customer wrote after approval; human_closed: a person has the chat; cancelled: no conversation)',
    $windowLine,
    '  waiting for a person (pending, follow-up open): <1d:0 1-7d:2 >7d:1 verdict_send:2 verdict_other:1 no_message:1 (no_message: nothing to approve — Reject clears it)',
    '== G. Enquiry follow-ups sent, and the send ledger ==',
    '  the last one sent 60 min ago · in the last 24 h: 1 · in the last 7 days: 2',
    '  per UTC day, 14 days: ' . $md(-72) . ':1 ' . $md(-1) . ':1',
    '  per number, 7 days: sales (dept) 1 · support (dept) 1',
    '  today (UTC) per number, against followup_daily_cap 3: support (dept) 1 · numbers at the cap: 0',
    '  the ledger, enquiry rows in 7 days: claim sent 4 · record sent 1',
    '  the ledger today (Kampala day ' . $klaDay . '), proactive automated messages per number against wa_send_daily_cap: a department number (or a moved one) #1 2/60 · sales-001 (seller) 2/60 · at the daily cap: 0 · at the hourly cap now: 0',
    '== H. What the jobs did, 7 days ==',
    '  events: created 9 · drafted 9 · evaluated 7 · approved 6 · failed 3 · sent 2 · other 1 · closed 1',
    '  the gate\'s answers ("evaluated"): defer at daily_cap 2 · proceed 2 · close at human_active 1 · other 1 · close at escalation 1',
    '  the assistant\'s verdicts (drafts written; a WAIT writes none): SEND 6 · DO_NOT_SEND 5 (ai_unreachable:1 ai_no_answer:1 ai_bad_answer:1 identity_uncertain:1 declined:1) · ESCALATE_TO_HUMAN 1 · WAIT 1 · other 1 — an AI failure closes the follow-up as not_interested',
    '  per UTC day, opened/judged/drafted/sent: ' . $md(-72 + 0.5) . ':0/1/0/0 ' . $md(-48 + 0.5) . ':0/1/0/0 ' . $md(-1) . ':9/5/9/2',
    '  opened in 7 days: 23, of which REOPENED for the same quiet spell after an earlier one closed: 2 — by how the earlier one closed: other 1 · not_interested 1 · opened 3 or more times for one quiet spell: 1 · the gate\'s answers spent on reopened ones: 2 of 7',
    '== I. Opened and closed, 14 days ==',
    '  opened per UTC day: ' . $md(-8 * 24) . ':1 ' . $md(-5 * 24) . ':1 ' . $md(-4 * 24) . ':1 ' . $md(-72) . ':11 ' . $md(-48) . ':10',
    '  closed, by reason: not_interested 8 · escalated 2 · other 3 · exhausted 1 · human_closed 1 · replied 1 · vetoed 1',
    '  of those closed not_interested, after an AI failure (the assistant unreachable, silent or unreadable): 3 — those customers were not declined by anyone',
    // J: the 10 oldest approved (a paused number's left out): S3, failed three times since its approval 12 days back (the
    // latest a Bad Request), and S4 on sales-001, never; S11 on the paused sales-002 is in the queue, not in the places
    '== J. Why the sender\'s front fails (its 10 places: the oldest approved drafts, tried first on every pass) ==',
    '  the sender\'s 10 places: 2 taken · failed since approval, each: fewest 0, most 3 · in all 3 · the first failure 57 min ago, the latest 51 min ago',
    '  their latest failure, by kind: HTTP 400 Bad Request 1 · never failed 1',
    '  their numbers, by shape: Ugandan (256 and nine digits) 2',
    '  their customers\' last message: <3d:1 3-7d:0 7-30d:1 >30d:0',
    '  failed sends, 7 days, by kind (every enquiry draft): HTTP 400 Bad Request 1 · HTTP 500 the number not connected, as Evolution said 1 · other 1',
    '  the real queue (3), by the customer\'s last message: <3d:2 3-7d:0 7-14d:1 14-30d:0 >30d:0 · by number shape: Ugandan (256 and nine digits) 3',
    '  (a failure keeps only Evolution\'s short message and its code: a 400 Bad Request is, among other cases, its answer for a number not on WhatsApp)',
    '  the sender\'s 10 places: 2 oldest approved drafts, failed 3 times since approval · their latest failure, most often: HTTP 400 Bad Request (1)',
    '  changes on the copy: 0',
]);
$ok($r2['same'], 'R2: the copy is byte for byte unchanged');
$ok(array_sum($r2['side']) === 0, 'R2: nothing written beside the copy (no -journal; a -wal, if any, is empty)', json_encode($r2['side']));

// ── R1 ─────────────────────────────────────────────────────────────────────────────────────────────────────────
echo "-- R1: followup_run_limit 1, followup_daily_cap 1, salesperson numbers HELD, a floor 4 days back, the ledger claiming at caps of 2\n";
$r1 = $run('root1', 'r1', false, 'data1');
$ok($r1['rc'] === 0, 'R1 exits 0', "rc={$r1['rc']}\n{$r1['out']}");
$expect($r1, 'R1', [
    // the ledger claims (quote_chase_enabled on): the sender tries only the oldest per number, and J says so
    '== J. Why the sender\'s front fails (its 10 places: the oldest approved drafts, tried first on every pass — while the ledger claims, only the oldest per number is tried) ==',
    '  the follow-up jobs run · automatic approval on · salesperson numbers HELD (never followed up automatically)',
    '  quiet conversations with no open follow-up (1-30 days): 14 — never followed up this quiet spell 2 · opened AGAIN by every scan 2 · on a held salesperson number 1 · before followup_not_before 3 · older than followup_max_age_hours 1',
    '  open 9, due now 5 · the run\'s front: JAMMED — every place the run takes holds a draft waiting for a person, so it judges no other follow-up; a place frees only when its follow-up is sent (that moves it on) or closed — Reject alone leaves it at the front, and the scan opens a closed one again unless the customer opted out (Not interested records one)',
    '  limits: followup_daily_cap 1 (after 1 sends on a number today, UTC, the run judges none of its follow-ups) · followup_run_limit 1 (judged per run, every 10 min) · the ledger claim (2 proactive a number a day; at the cap today: 2)',
    '  salesperson numbers: HELD — wa_followups_on_owned_numbers is off, so the scan never opens a follow-up on a salesperson\'s own number',
    '  followup_daily_cap 1 — once this many were SENT on a number today (UTC) the run judges none of its follow-ups (it puts each back for the next run); the sender itself has no cap · followup_run_limit 1 — follow-ups judged per run (every 10 min)',
    '  followup_scan_limit 25 (default) — opened per scan (every 10 min) · followup_max_age_hours 336 (default) — older quiet conversations are never opened · followup_not_before ' . gmdate('Y-m-d H:i:s', $NOW - 4 * 86400) . '',
    '  the send ledger: on, CLAIM — each enquiry send asks first: per number at most 2 proactive automated messages a Kampala day (quotes and staff lists reserve their share first) and 2 an hour, spaced, one per customer a day, inside 09:00-18:00',
    '  14 in all — eligible_not_opened 2 · reopened_each_scan 2 · salesperson_number_held 1 · before_not_before 3 · older_than_max_age 1 · quotation_chased 1 · opted_out 2 · person_has_it 1 · colleague 1',
    '  eligible but not opened, by number: sales (dept) 1 · support (dept) 1',
    '  eligible but not opened, by time since the customer\'s last message: 1-3d:2 3-7d:0 7-14d:0 14-30d:0',
    '  followed up and closed during this quiet spell, so the scan opens them AGAIN (due at once, at the front of the run): by how the last one closed: other 1 · exhausted 1',
    '  held on a salesperson\'s own number, by number: sales-001 (seller) 1',
    '  the first 1 due (followup_run_limit 1): 1 wait for a person · 0 hold an approved, unsent draft — JAMMED — every place the run takes holds a draft waiting for a person, so it judges no other follow-up; a place frees only when its follow-up is sent (that moves it on) or closed — Reject alone leaves it at the front, and the scan opens a closed one again unless the customer opted out (Not interested records one)',
    '  the real queue, failed at least once since approval: 1 (a failed send stays approved and goes again first) · the sender\'s 10 places (the 10 oldest approved, a paused number\'s left out): 2 taken, 1 failed before, 2 numbers, 0 closed when reached (number disabled or retired) · on a paused number, taking no place: 1 (while the ledger claims, only the oldest per number is tried each run)',
    $windowLineClaim,
    '  today (UTC) per number, against followup_daily_cap 1: support (dept) 1 · numbers at the cap: 1',
    '  the ledger today (Kampala day ' . $klaDay . '), proactive automated messages per number against wa_send_daily_cap: a department number (or a moved one) #1 2/2 · sales-001 (seller) 2/2 · at the daily cap: 2 · at the hourly cap now: 1',
]);

// ── R3 ─────────────────────────────────────────────────────────────────────────────────────────────────────────
echo "-- R3: hostile values — followup_enabled 'off', followup_auto_send 'no', the cap 0, an unreadable floor, followup_run_limit -1\n";
$r3 = $run('root2', 'r3', false, 'data3');
$ok($r3['rc'] === 0, 'R3 exits 0', "rc={$r3['rc']}\n{$r3['out']}");
$expect($r3, 'R3', [
    '  the follow-up jobs run · automatic approval off · salesperson numbers not held',
    '  quiet conversations with no open follow-up (1-30 days): 14 — never followed up this quiet spell 0 · opened AGAIN by every scan 0 · on a held salesperson number 0 · before followup_not_before 11 · older than followup_max_age_hours 1',
    '  the Follow-ups screen: 49 of its 50 cards are on a closed follow-up · the front\'s waiting drafts not on the screen: 2',
    '  limits: followup_daily_cap 0 (no cap) · followup_run_limit -1 (every due one per run, every 10 min) · the ledger record',
    '  followup_enabled: the four follow-up jobs RUN (it is stored as a word that looks like off, which the jobs read as on) · followup_auto_send off — a SEND draft is approved only by a person',
    '  followup_daily_cap 0 — no cap · followup_run_limit -1 — below 0: the run takes EVERY due follow-up each pass (every 10 min)',
    '  followup_scan_limit 25 (default) — opened per scan (every 10 min) · followup_max_age_hours 336 (default) — older quiet conversations are never opened · followup_not_before set, not a plain date (the scan compares it as text) — later than any quiet conversation can be, so the scan opens NO conversation at all',
    '  14 in all — before_not_before 11 · older_than_max_age 1 · person_has_it 1 · colleague 1',
    '  eligible but not opened, by number: none',
    '  eligible but not opened, by time since the customer\'s last message: 1-3d:0 3-7d:0 7-14d:0 14-30d:0',
    '  followed up and closed during this quiet spell, so the scan opens them AGAIN (due at once, at the front of the run): by how the last one closed: none',
    '  the first 5 due (followup_run_limit -1: every due one): 2 wait for a person · 1 hold an approved, unsent draft · 2 the run closes first (the conversation gone, a colleague\'s, or a held salesperson number) — partly blocked',
    '  the Follow-ups screen shows the 50 oldest waiting drafts of every kind, open or closed: 61 wait in all · on its 50 cards: 49 on a CLOSED follow-up (nothing to send — Reject clears each), 1 quotation drafts · of the front\'s 2 waiting drafts: 2 beyond those 50 — NOT ON THE SCREEN',
    '  today (UTC) per number, against followup_daily_cap 0: support (dept) 1 · numbers at the cap: none (no cap)',
]);

// ── R4 ─────────────────────────────────────────────────────────────────────────────────────────────────────────
echo "-- R4: NOW 14 days earlier — one row due, its draft waiting for a person, fewer than the run takes\n";
$r4 = $run('root2', 'r4', false, 'data', gmdate('Y-m-d H:i:s', $NOW - 14 * 86400));
$ok($r4['rc'] === 0, 'R4 exits 0', "rc={$r4['rc']}\n{$r4['out']}");
$expect($r4, 'R4', [
    '  open 9, due now 1 · the run\'s front: not jammed — every due one waits for a person, but the run takes more than are due, so a newly due one is still judged',
    '  1 in all — eligible_not_opened 1',
    '  the first 1 due (followup_run_limit 2): 1 wait for a person · 0 hold an approved, unsent draft — not jammed — every due one waits for a person, but the run takes more than are due, so a newly due one is still judged',
    '  the first 1 due: 1 wait for a person',
]);

// ── R5 ─────────────────────────────────────────────────────────────────────────────────────────────────────────
echo "-- R5: followup_run_limit 0, an empty followup_scan_limit, wa_send_ledger 'yes' (not exactly 'on')\n";
$r5 = $run('root2', 'r5', false, 'data4');
$ok($r5['rc'] === 0, 'R5 exits 0', "rc={$r5['rc']}\n{$r5['out']}");
$expect($r5, 'R5', [
    '  open 9, due now 5 · the run\'s front: NOTHING JUDGED — followup_run_limit is 0, so the run takes no follow-up',
    '  the Follow-ups screen: 49 of its 50 cards are on a closed follow-up · the front\'s waiting drafts not on the screen: 0',
    '  limits: followup_daily_cap 3 (after 3 sends on a number today, UTC, the run judges none of its follow-ups) · followup_run_limit 0 (none per run, every 10 min) · the ledger off',
    '  followup_daily_cap 3 — once this many were SENT on a number today (UTC) the run judges none of its follow-ups (it puts each back for the next run); the sender itself has no cap · followup_run_limit 0 — the run takes NO follow-up',
    '  followup_scan_limit 0 (set but empty: the job reads 0) — opened per scan (every 10 min) · followup_max_age_hours 336 (default) — older quiet conversations are never opened · followup_not_before set, not a plain date (the scan compares it as text)',
    '  the send ledger: off — enquiry sends are neither recorded nor paced',
    '  the first 0 due (followup_run_limit 0): 0 wait for a person · 0 hold an approved, unsent draft — NOTHING JUDGED — followup_run_limit is 0, so the run takes no follow-up',
    '  the Follow-ups screen shows the 50 oldest waiting drafts of every kind, open or closed: 61 wait in all · on its 50 cards: 49 on a CLOSED follow-up (nothing to send — Reject clears each), 1 quotation drafts · of the front\'s 0 waiting drafts: 0 beyond those 50 — all on the screen',
]);

// ── R6, R7: the same book at other times ───────────────────────────────────────────────────────────────────────
echo "-- R7: R1's settings on a Monday at 18:00 Kampala — inside 08:00-20:00, outside the ledger's own 09:00-18:00\n";
$r7 = $run('root1', 'r7', false, 'data1', gmdate('Y-m-d H:i:s', $NOW + 27 * 3600));
$ok($r7['rc'] === 0, 'R7 exits 0', "rc={$r7['rc']}\n{$r7['out']}");
$expect($r7, 'R7', [
    '  now Mon 18:00 Kampala: OUTSIDE the sending window (08:00-20:00, Mon-Sat; while the ledger claims, also its own 09:00-18:00) — every approved follow-up waits for it',
]);
$JUDGED = 'THE RUN JUDGED NOTHING';
// R8-R11: R6's book with one change each. R6's front (the run limit 2) is S0, whose draft waits for a person, and S3, opened
// 15 days, due 13 days and decided (approved) 12 days before NOW — so its staleness turns on the run's last start alone.
$ts = function (float $days) use ($NOW): string { return gmdate('Y-m-d H:i:s', (int)round($NOW + $days * 86400)); };
$s3 = "(SELECT id FROM followups WHERE closed_at IS NULL AND due_at = '" . $ts(-13) . "')";
$one = function (PDO $p, string $sql, array $a = []): void {   // each change must touch exactly one row, or the run proves nothing
    $st = $p->prepare($sql); $st->execute($a); if ($st->rowCount() !== 1) throw new RuntimeException('changed ' . $st->rowCount() . ' rows: ' . $sql);
};
$lastStart = function (PDO $p, string $at) use ($one): void {
    $d = json_decode((string)$p->query('SELECT data FROM master_schedule WHERE id = 0')->fetchColumn(), true);
    $d['followup_run']['last_run'] = strtotime($at . ' UTC');
    $one($p, 'UPDATE master_schedule SET data = ? WHERE id = 0', [json_encode($d)]);
};
// R8-R10 move the run's start back days; every event logged after it is moved to the day before it, so none of them is
// what silences the line (the pass's answers, and the sends and closes that free a place ahead, are those events)
$quiet = function (PDO $p, string $at): void {
    $st = $p->prepare("UPDATE followup_events SET created_at = datetime(?, '-1 day') WHERE created_at >= ?"); $st->execute([$at, $at]);
    if ($st->rowCount() < 1) throw new RuntimeException('no event after ' . $at);
};
$r6at = gmdate('Y-m-d H:i:s', $NOW + 2 * 86400);
$r6start = function (PDO $p) use ($lastStart, $ts): void { $lastStart($p, $ts(1)); };   // a day after the book's last event
echo "-- R6: NOW two days later, the run's last start a day before it — S3 was due then, and nothing was judged since\n";
$r6 = $run('root2', 'r6', false, 'data', $r6at, $r6start);
$ok($r6['rc'] === 0, 'R6 exits 0', "rc={$r6['rc']}\n{$r6['out']}");
$r6line = '  THE RUN JUDGED NOTHING at its last start (24 h ago), though 1 of the follow-ups it takes were then due, in its reach and awaiting no person — its log says why (a missing AI key ends it before any judging)';
$expect($r6, 'R6', [$r6line]);
echo "-- R8: R6, the run's last start between S3 falling due and a person's last decision on it\n";
$r8 = $run('root2', 'r8', false, 'data', $r6at, function (PDO $p) use ($lastStart, $quiet, $ts) { $quiet($p, $ts(-12.5)); $lastStart($p, $ts(-12.5)); });
$ok($r8['rc'] === 0, 'R8 exits 0', "rc={$r8['rc']}\n{$r8['out']}");
$absent($r8, 'R8', $JUDGED, 'a person decided on it after the run last started');
echo "-- R9: R6, S3 decided before the run's last start, which was before it fell due\n";
$r9 = $run('root2', 'r9', false, 'data', $r6at, function (PDO $p) use ($lastStart, $quiet, $ts, $one, $s3) {
    $one($p, "UPDATE followup_drafts SET decided_at = ? WHERE followup_id = $s3", [$ts(-14.5)]); $quiet($p, $ts(-14)); $lastStart($p, $ts(-14));
});
$ok($r9['rc'] === 0, 'R9 exits 0', "rc={$r9['rc']}\n{$r9['out']}");
$absent($r9, 'R9', $JUDGED, 'it fell due after the run last started');
echo "-- R10: R6, S3 opened after the run's last start (due and decided before it)\n";
$r10 = $run('root2', 'r10', false, 'data', $r6at, function (PDO $p) use ($lastStart, $quiet, $ts, $one, $s3) {
    $one($p, "UPDATE followups SET opened_at = ? WHERE id = $s3", [$ts(-11)]); $quiet($p, $ts(-11.5)); $lastStart($p, $ts(-11.5));
});
$ok($r10['rc'] === 0, 'R10 exits 0', "rc={$r10['rc']}\n{$r10['out']}");
$absent($r10, 'R10', $JUDGED, 'it was opened after the run last started');
// R11 reads data3's settings (every due row taken), so a place freed ahead cannot be what silences it: a policy refusal is
// itself a 'skipped' or 'closed' event, which under a positive limit also takes a place from the run's reach
echo "-- R11: R6 with every due row taken (data3), and a policy refusal (a paused number) logged an hour before\n";
$r11 = $run('root2', 'r11', false, 'data3', $r6at, function (PDO $p) use ($one, $s3, $ts, $r6start) {
    $r6start($p);
    $one($p, "INSERT INTO followup_events (followup_id, conversation_id, event, detail, actor, created_at)
              VALUES ($s3, NULL, 'skipped', 'nothing automated on channel support now (channel_paused)', 'policy', ?)", [$ts(2 - 1 / 24)]);
});
$ok($r11['rc'] === 0, 'R11 exits 0', "rc={$r11['rc']}\n{$r11['out']}");
$absent($r11, 'R11', $JUDGED, 'the run took a row since its last start and a paused number put it off');
$s0 = "(SELECT id FROM followups WHERE closed_at IS NULL AND due_at = '" . $ts(-16) . "')";
echo "-- R12: R6, with the follow-up ahead of S3 sent two hours before (a place freed after the run's last start)\n";
$r12 = $run('root2', 'r12', false, 'data', $r6at, function (PDO $p) use ($one, $s0, $ts, $r6start) {
    $r6start($p);
    $one($p, "INSERT INTO followup_events (followup_id, conversation_id, event, detail, actor, created_at)
              VALUES ($s0, NULL, 'sent', 'sent', 'sender', ?)", [$ts(2 - 2 / 24)]);
});
$ok($r12['rc'] === 0, 'R12 exits 0', "rc={$r12['rc']}\n{$r12['out']}");
$absent($r12, 'R12', $JUDGED, 'S3 may have been beyond the run\'s reach at its last start: a follow-up ahead of it left since');
echo "-- R13: R6, the run's last start 30 h back, the gate answering a minute after it\n";
$r13 = $run('root2', 'r13', false, 'data', $r6at, function (PDO $p) use ($one, $s3, $ts, $lastStart) {
    $lastStart($p, $ts(2 - 30 / 24));
    $one($p, "INSERT INTO followup_events (followup_id, conversation_id, event, detail, actor, created_at)
              VALUES ($s3, NULL, 'evaluated', 'skip at identity_ambiguous', 'system', ?)", [$ts(2 - 30 / 24 + 1 / 1440)]);
});
$ok($r13['rc'] === 0, 'R13 exits 0', "rc={$r13['rc']}\n{$r13['out']}");
$absent($r13, 'R13', $JUDGED, 'its last pass did judge, though more than 24 h ago');
echo "-- R14: R6, the run's last start 10 minutes back, the gate's last answer 3 h back\n";
$r14 = $run('root2', 'r14', false, 'data', $r6at, function (PDO $p) use ($one, $s3, $ts, $lastStart) {
    $lastStart($p, $ts(2 - 10 / 1440));
    $one($p, "INSERT INTO followup_events (followup_id, conversation_id, event, detail, actor, created_at)
              VALUES ($s3, NULL, 'evaluated', 'proceed — ok', 'system', ?)", [$ts(2 - 3 / 24)]);
});
$ok($r14['rc'] === 0, 'R14 exits 0', "rc={$r14['rc']}\n{$r14['out']}");
$expect($r14, 'R14', [
    '  THE RUN JUDGED NOTHING at its last start (10 min ago), though 1 of the follow-ups it takes were then due, in its reach and awaiting no person — its log says why (a missing AI key ends it before any judging)',
]);
$absent($r2, 'R2', $JUDGED, 'the gate answered after the run\'s last start');
$absent($r4, 'R4', $JUDGED, 'the only due row waits for a person: that is the jam, not a silent run');
$absent($r5, 'R5', $JUDGED, 'followup_run_limit 0 takes nothing');

echo "-- R15: R6, with an admin page's run of master 5 s before the run's last start (that cycle stamped it)\n";
$r15 = $run('root2', 'r15', false, 'data5', $r6at, $r6start);
$ok($r15['rc'] === 0, 'R15 exits 0', "rc={$r15['rc']}\n{$r15['out']}");
$expect($r15, 'R15', [
    '  followup_run: last start 24 h ago · DID NOT FINISH — an admin page\'s cycle (public.php), where the job does nothing: its last real pass is not recorded',
    '  whether the run\'s last pass judged anything is NOT ESTABLISHED: its last recorded start was an admin page\'s cycle, where it does nothing — run this again after its next pass',
]);
$absent($r15, 'R15', $JUDGED, 'the last recorded start was an admin page\'s cycle, in which the job does nothing');
echo "-- R16: R6, with an admin page's run of master an hour before the run's last start\n";
$r16 = $run('root2', 'r16', false, 'data6', $r6at, $r6start);
$ok($r16['rc'] === 0, 'R16 exits 0', "rc={$r16['rc']}\n{$r16['out']}");
$expect($r16, 'R16', [$r6line]);
$absent($r16, 'R16', 'an admin page', 'that cycle was an hour before the stamp');
echo "-- R17: R6, with an admin page's run of master a minute after the run's last start\n";
$r17 = $run('root2', 'r17', false, 'data7', $r6at, $r6start);
$ok($r17['rc'] === 0, 'R17 exits 0', "rc={$r17['rc']}\n{$r17['out']}");
$expect($r17, 'R17', [$r6line]);
$absent($r17, 'R17', 'an admin page', 'that cycle came after the stamp');

echo "-- R18: R2, the sender's places on other number shapes, S4 failing three ways since its approval and once before it\n";
$s4 = "(SELECT id FROM followups WHERE closed_at IS NULL AND channel = 'sales-001' AND due_at = '" . $ts(0.75) . "')";
// digits only, as FollowUpService::open stores a phone (ContactOptOut::normalise); never printed — the privacy checks below
$r18 = $run('root2', 'r18', false, 'data', null, function (PDO $p) use ($one, $s3, $s4, $ts) {
    $one($p, "UPDATE followups SET phone = '0700000103' WHERE id = $s3");           // no country code
    $one($p, "UPDATE followups SET phone = '120363000000017' WHERE id = $s4");      // fifteen digits, not 256 (a hidden id's shape)
    $ins = "INSERT INTO followup_events (followup_id, conversation_id, event, detail, actor, created_at) VALUES ($s4, NULL, 'failed', ?, 'sender', ?)";
    $one($p, $ins, ['the approved draft has no message', $ts(-3 / 24)]);                                           // before S4's approval (2 h back)
    $one($p, $ins, ['May have been sent — no answer from Evolution: timed out', $ts(-1.5 / 24)]);
    $one($p, $ins, ['HTTP 503 [HTTP 503 on POST /message/sendText/PLANTINSTZQX]', $ts(-1.4 / 24)]);
    $one($p, $ins, ['Array [HTTP 400 on POST /message/sendText/PLANTINSTZQX]', $ts(-1.3 / 24)]);   // a list Evolution sent, as requestUg keeps it
});
$ok($r18['rc'] === 0, 'R18 exits 0', "rc={$r18['rc']}\n{$r18['out']}");
$expect($r18, 'R18', [
    '  the sender\'s 10 places: 2 taken · failed since approval, each: fewest 3, most 3 · in all 6 · the first failure 1.5 h ago, the latest 51 min ago',
    '  their latest failure, by kind: HTTP 400 Bad Request 1 · HTTP 400 a list from Evolution (its contents not kept) 1',
    '  their numbers, by shape: 10-15 digits, not 256 (another country, or not a phone number) 1 · starting with 0 (no country code, or not a phone number) 1',
    '  failed sends, 7 days, by kind (every enquiry draft): HTTP 400 Bad Request 1 · HTTP 400 a list from Evolution (its contents not kept) 1 · HTTP 500 the number not connected, as Evolution said 1 · HTTP 503 no message from Evolution 1 · no answer from Evolution 1 · other 1 · the draft has no message 1',
    '  the real queue (3), by the customer\'s last message: <3d:2 3-7d:0 7-14d:1 14-30d:0 >30d:0 · by number shape: 10-15 digits, not 256 (another country, or not a phone number) 1 · Ugandan (256 and nine digits) 1 · starting with 0 (no country code, or not a phone number) 1',
    '  the sender\'s 10 places: 2 oldest approved drafts, failed 6 times since approval · their latest failure, most often: HTTP 400 Bad Request (1) and HTTP 400 a list from Evolution (its contents not kept) (1)',
]);

// ── RO: read by another user, who cannot write the copy ────────────────────────────────────────────────────────
echo "-- RO: the same copy, 0444 and root's, read by another user (uid 65534 when this runs as root)\n";
$ro = $run('root2', 'ro', true);
$ok($ro['rc'] === 0 && $ro['out'] === $r2['out'], 'RO: a copy the reader cannot write reads exactly as R2', "rc={$ro['rc']}\n{$ro['out']}");
$ok($ro['same'], 'RO: unchanged');
$ok(array_sum($ro['side']) === 0, 'RO: nothing written beside the copy', json_encode($ro['side']));
// the control on that run: the same user, the same copy, a write — it must be refused, or the run proves nothing
$cdir = "$tmp/roctl"; mkdir($cdir, 0700); copy("$build/data/plugin.sqlite3", "$cdir/copy.sqlite3"); chmod("$cdir/copy.sqlite3", 0444);
if ($asRoot) { chown($cdir, 65534); chgrp($cdir, 65534); }
$probe = "$tmp/roctl-probe.php";
file_put_contents($probe, '<?php try { $p = new PDO("sqlite:" . $argv[1], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); $p->exec("CREATE TABLE zz_probe (x)"); echo "written"; } catch (Throwable $e) { echo "refused"; }');
chmod($probe, 0644);
$ctl = trim((string)shell_exec(($asRoot ? 'setpriv --reuid=65534 --regid=65534 --clear-groups ' : '') . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probe) . ' ' . escapeshellarg("$cdir/copy.sqlite3") . ' 2>&1'));
$ok($ctl === 'refused', 'RO control: that user writing that copy is refused', $ctl);
if ($asRoot) chown($cdir, 0);

// ── privacy, over every output ─────────────────────────────────────────────────────────────────────────────────
echo "-- privacy\n";
foreach (['R2' => $r2, 'R1' => $r1, 'R3' => $r3, 'R4' => $r4, 'R5' => $r5, 'R6' => $r6, 'R7' => $r7, 'R8' => $r8, 'R9' => $r9, 'R10' => $r10, 'R11' => $r11, 'R12' => $r12, 'R13' => $r13, 'R14' => $r14, 'R15' => $r15, 'R16' => $r16, 'R17' => $r17, 'R18' => $r18] as $tag => $r) {
    $text = $r['out'];
    $ok(stripos($text, 'PLANT') === false, "$tag: no PLANT string (names, instances, handsets, staff, messages, reasons, secrets, odd channel, event and reason)");
    $ok(preg_match('/[0-9]{9,}/', $text) !== 1, "$tag: no run of nine or more digits");
    $ok(strpos($text, '<digits>') === false, "$tag: nothing needed the last mask — the report's own filters held");
    $ok(stripos($text, 'Nakato') === false && stripos($text, 'Okello') === false && stripos($text, 'Kato') === false, "$tag: no name");
    $ok(strpos($text, 'api_key') === false && strpos($text, 'i:') === false, "$tag: no key name and no handset");
}

// ── controls: the planted values are really in the book and the settings, so their absence above is the report's doing
echo "-- controls on the book\n";
$db = new PDO('sqlite:' . "$build/data/plugin.sqlite3", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$c = function (string $sql) use ($db): int { return (int)$db->query($sql)->fetchColumn(); };
$ok($c("SELECT COUNT(*) FROM followups WHERE channel LIKE 'PLANT-CHAN%'") === 1, 'the odd channel is in the book');
$ok($c("SELECT COUNT(*) FROM wa_channels WHERE evo_instance = 'PLANTINSTZQX'") === 1, "the seller's instance is in the book");
$ok($c("SELECT COUNT(*) FROM followup_drafts WHERE decided_by LIKE 'staff:PLANT%'") === 1, 'a staff name is in the book');
$ok($c("SELECT COUNT(*) FROM followups WHERE kind = 'quote' AND closed_at IS NULL") === 1, 'a quote row is in the book (and counted nowhere above but the screen)');
$ok($c("SELECT COUNT(*) FROM followup_events WHERE event LIKE 'PLANT%'") === 1, 'an odd event name is in the book');
$ok($c("SELECT COUNT(*) FROM followup_drafts WHERE reason LIKE '%PLANT%256771234567%'") >= 50, 'the drafts\' reasons carry names and numbers');
$ok($c("SELECT COUNT(*) FROM wa_auto_sends WHERE handset LIKE 'i:plant%'") === 6, 'the ledger rows carry planted handsets');
$ok($c("SELECT COUNT(*) FROM kyc_config WHERE id = 0 AND data LIKE '%PLANT-STORE-SECRET%'") === 1, "the book's settings row carries a secret");
$ok($c("SELECT COUNT(*) FROM contact_optouts WHERE active = 1") === 2, 'the two opt-outs are in the book');
$ok($c("SELECT COUNT(*) FROM followup_drafts WHERE verdict LIKE 'PLANT%'") === 1, 'an odd verdict is in the book');
$db = null;
$ok(strpos((string)file_get_contents("$build/data/kyc_config.json"), 'PLANT-SECRET') !== false, 'a secret is in kyc_config.json');
$ok(strpos((string)file_get_contents("$build/data3/kyc_config.json"), 'PLANT 256771234567') !== false, 'the unreadable floor is in data3');
$ok(strpos((string)file_get_contents("$build/vault.json"), 'PLANT-VAULT-SECRET') !== false, 'a secret is in the vault');

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
