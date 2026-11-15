<?php
declare(strict_types=1);
/**
 * test.php REPORT BUILD NOW — runs the report on copies of build.php's book and checks every line it prints.
 *
 * Exact lines, derived by hand from build.php's rows (S0-S11, B1-B49, X1-X7, C-a…C-i, the ledger rows, the book's
 * settings row, the opt-out, the reopened C-k); every date in them is derived from NOW. Five runs on the same book:
 *   R2  root2 + data    the run limit 2, the cap 3, salesperson numbers followed up, the ledger recording
 *   R1  root1 + data1   the run limit 1, the cap 1, salesperson numbers HELD, a floor 4 days back, the ledger claiming,
 *                       its daily and hourly caps 2
 *   R3  root2 + data3   hostile values: followup_enabled 'off' (still on to the jobs), the cap 0, a floor that is not a
 *                       date (the scan then opens nothing), the run limit -1 (every due row)
 *   R4  root2 + data    NOW 14 days earlier: one row due, waiting for a person, fewer than the run takes; nothing judged
 *                       in the 24 h before it
 *   R5  root2 + data4   the run limit 0, an empty scan limit, wa_send_ledger 'yes' (off: only 'on' is on)
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
$run = function (string $root, string $tag, bool $readOnly = false, string $data = 'data', ?string $now = null) use ($report, $build, $nowIso, $tmp, $asRoot, $copyIn): array {
    $dir = "$tmp/$tag"; mkdir($dir, 0700);
    copy("$build/data/plugin.sqlite3", "$dir/copy.sqlite3");
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

$JAM = 'JAMMED — every place the run takes holds a draft waiting for a person, so it judges no other follow-up until they are decided (Approve or Reject on the Follow-ups screen)';
$NOTJAM = 'not jammed — every due one waits for a person, but fewer are due than the run takes, so a newly due one is still judged';
$windowLine = '  now ' . $klaLabel . ' Kampala: ' . ($klaIn ? 'INSIDE' : 'OUTSIDE') . ' the sending window (08:00-20:00, Mon-Sat)' . ($klaIn ? '' : ' — every approved follow-up waits for it');
$windowLineClaim = '  now ' . $klaLabel . ' Kampala: ' . ($klaIn ? 'INSIDE' : 'OUTSIDE') . ' the sending window (08:00-20:00, Mon-Sat; while the ledger claims, also its own 08:00-20:00)' . ($klaIn ? '' : ' — every approved follow-up waits for it');

// ── R2 ─────────────────────────────────────────────────────────────────────────────────────────────────────────
echo "-- R2: followup_run_limit 2, followup_daily_cap 3, salesperson numbers followed up, the ledger recording\n";
$r2 = $run('root2', 'r2');
$ok($r2['rc'] === 0, 'R2 exits 0', "rc={$r2['rc']}\n{$r2['out']}");
$expect($r2, 'R2', [
    '== Summary ==',
    '  the follow-up jobs run · automatic approval on · salesperson numbers not held',
    '  quiet conversations with no open follow-up (1-30 days): 11 — never followed up this quiet spell 4 · opened AGAIN by every scan 3 · on a held salesperson number 0 · before followup_not_before 0 · older than followup_max_age_hours 1',
    '  reopened by the scan for the same quiet spell, 7 days: 2 of 22 opened · the gate\'s answers spent on them: 2 of 7',
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
    '  11 in all — eligible_not_opened 4 · reopened_each_scan 3 · older_than_max_age 1 · opted_out 1 · person_has_it 1 · colleague 1',
    '  eligible but not opened, by number: sales (dept) 1 · sales-001 (seller) 1 · support (dept) 2',
    '  eligible but not opened, by time since the customer\'s last message: 1-3d:2 3-7d:1 7-14d:1 14-30d:0',
    '  followed up and closed during this quiet spell, so the scan opens them AGAIN (due at once, at the front of the run): by how the last one closed: escalated 1 · exhausted 1 · human_closed 1',
    '  (by these rules; the scan also leaves out an opted-out customer never followed up before, a colleague\'s or DishNet\'s own number, a number that may send nothing automated, and several customers on one number)',
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
    '  events: created 9 · drafted 9 · evaluated 7 · approved 6 · sent 2 · other 1 · closed 1 · failed 1',
    '  the gate\'s answers ("evaluated"): defer at daily_cap 2 · proceed 2 · close at human_active 1 · other 1 · close at escalation 1',
    '  the assistant\'s verdicts (drafts written; a WAIT writes none): SEND 6 · DO_NOT_SEND 5 (ai_unreachable:1 ai_no_answer:1 ai_bad_answer:1 identity_uncertain:1 declined:1) · ESCALATE_TO_HUMAN 1 · WAIT 1 · other 1 — an AI failure closes the follow-up as not_interested',
    '  per UTC day, opened/judged/drafted/sent: ' . $md(-72 + 0.5) . ':0/1/0/0 ' . $md(-48 + 0.5) . ':0/1/0/0 ' . $md(-1) . ':9/5/9/2',
    '  opened in 7 days: 22, of which REOPENED for the same quiet spell after an earlier one closed: 2 — by how the earlier one closed: not_interested 2 · opened 3 or more times for one quiet spell: 1 · the gate\'s answers spent on reopened ones: 2 of 7',
    '== I. Opened and closed, 14 days ==',
    '  opened per UTC day: ' . $md(-8 * 24) . ':1 ' . $md(-5 * 24) . ':1 ' . $md(-4 * 24) . ':1 ' . $md(-72) . ':11 ' . $md(-48) . ':9',
    '  closed, by reason: not_interested 9 · escalated 2 · exhausted 1 · human_closed 1 · other 1 · replied 1 · vetoed 1',
    '  of those closed not_interested, after an AI failure (the assistant unreachable, silent or unreadable): 3 — those customers were not declined by anyone',
    '  changes on the copy: 0',
]);
$ok($r2['same'], 'R2: the copy is byte for byte unchanged');
$ok(array_sum($r2['side']) === 0, 'R2: nothing written beside the copy (no -journal; a -wal, if any, is empty)', json_encode($r2['side']));

// ── R1 ─────────────────────────────────────────────────────────────────────────────────────────────────────────
echo "-- R1: followup_run_limit 1, followup_daily_cap 1, salesperson numbers HELD, a floor 4 days back, the ledger claiming at caps of 2\n";
$r1 = $run('root1', 'r1', false, 'data1');
$ok($r1['rc'] === 0, 'R1 exits 0', "rc={$r1['rc']}\n{$r1['out']}");
$expect($r1, 'R1', [
    '  the follow-up jobs run · automatic approval on · salesperson numbers HELD (never followed up automatically)',
    '  quiet conversations with no open follow-up (1-30 days): 11 — never followed up this quiet spell 2 · opened AGAIN by every scan 1 · on a held salesperson number 1 · before followup_not_before 3 · older than followup_max_age_hours 1',
    '  open 9, due now 5 · the run\'s front: JAMMED — every place the run takes holds a draft waiting for a person, so it judges no other follow-up until each is moved on: Approve (the sender sends it) or Not interested (an opt-out); Reject alone leaves it at the front, and a closed one is opened again by the scan',
    '  limits: followup_daily_cap 1 (after 1 sends on a number today, UTC, the run judges none of its follow-ups) · followup_run_limit 1 (judged per run, every 10 min) · the ledger claim (2 proactive a number a day; at the cap today: 2)',
    '  salesperson numbers: HELD — wa_followups_on_owned_numbers is off, so the scan never opens a follow-up on a salesperson\'s own number',
    '  followup_daily_cap 1 — once this many were SENT on a number today (UTC) the run judges none of its follow-ups (it puts each back for the next run); the sender itself has no cap · followup_run_limit 1 — follow-ups judged per run (every 10 min)',
    '  followup_scan_limit 25 (default) — opened per scan (every 10 min) · followup_max_age_hours 336 (default) — older quiet conversations are never opened · followup_not_before ' . gmdate('Y-m-d H:i:s', $NOW - 4 * 86400) . '',
    '  the send ledger: on, CLAIM — each enquiry send asks first: per number at most 2 proactive automated messages a Kampala day (quotes and staff lists reserve their share first) and 2 an hour, spaced, one per customer a day, inside 08:00-20:00',
    '  11 in all — eligible_not_opened 2 · reopened_each_scan 1 · salesperson_number_held 1 · before_not_before 3 · older_than_max_age 1 · opted_out 1 · person_has_it 1 · colleague 1',
    '  eligible but not opened, by number: sales (dept) 1 · support (dept) 1',
    '  eligible but not opened, by time since the customer\'s last message: 1-3d:2 3-7d:0 7-14d:0 14-30d:0',
    '  followed up and closed during this quiet spell, so the scan opens them AGAIN (due at once, at the front of the run): by how the last one closed: exhausted 1',
    '  held on a salesperson\'s own number, by number: sales-001 (seller) 1',
    '  the first 1 due (followup_run_limit 1): 1 wait for a person · 0 hold an approved, unsent draft — JAMMED — every place the run takes holds a draft waiting for a person, so it judges no other follow-up until each is moved on: Approve (the sender sends it) or Not interested (an opt-out); Reject alone leaves it at the front, and a closed one is opened again by the scan',
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
    '  quiet conversations with no open follow-up (1-30 days): 11 — never followed up this quiet spell 0 · opened AGAIN by every scan 0 · on a held salesperson number 0 · before followup_not_before 8 · older than followup_max_age_hours 1',
    '  the Follow-ups screen: 49 of its 50 cards are on a closed follow-up · the front\'s waiting drafts not on the screen: 3',
    '  limits: followup_daily_cap 0 (no cap) · followup_run_limit -1 (every due one per run, every 10 min) · the ledger record',
    '  followup_enabled: the four follow-up jobs RUN (it is stored as a word that looks like off, which the jobs read as on) · followup_auto_send off — a SEND draft is approved only by a person',
    '  followup_daily_cap 0 — no cap · followup_run_limit -1 — below 0: the run takes EVERY due follow-up each pass (every 10 min)',
    '  followup_scan_limit 25 (default) — opened per scan (every 10 min) · followup_max_age_hours 336 (default) — older quiet conversations are never opened · followup_not_before set (unreadable) — the scan compares it as text, so it opens NO conversation at all',
    '  11 in all — before_not_before 8 · older_than_max_age 1 · person_has_it 1 · colleague 1',
    '  eligible but not opened, by number: none',
    '  eligible but not opened, by time since the customer\'s last message: 1-3d:0 3-7d:0 7-14d:0 14-30d:0',
    '  followed up and closed during this quiet spell, so the scan opens them AGAIN (due at once, at the front of the run): by how the last one closed: none',
    '  the first 5 due (followup_run_limit -1: every due one): 3 wait for a person · 1 hold an approved, unsent draft · 1 the run closes first (the conversation gone, or a held salesperson number) — partly blocked',
    '  the Follow-ups screen shows the 50 oldest waiting drafts of every kind, open or closed: 61 wait in all · on its 50 cards: 49 on a CLOSED follow-up (nothing to send — Reject clears each), 1 quotation drafts · of the front\'s 3 waiting drafts: 3 beyond those 50 — NOT ON THE SCREEN',
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
    '  THE RUN JUDGED NOTHING in the last 24 h while 1 are due — its log says why (a missing AI key ends it before any judging)',
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
    '  followup_scan_limit 0 (set but empty: the job reads 0) — opened per scan (every 10 min) · followup_max_age_hours 336 (default) — older quiet conversations are never opened · followup_not_before 2026-09-12 11:11:02',
    '  the send ledger: off — enquiry sends are neither recorded nor paced',
    '  the first 0 due (followup_run_limit 0): 0 wait for a person · 0 hold an approved, unsent draft — NOTHING JUDGED — followup_run_limit is 0, so the run takes no follow-up',
    '  the Follow-ups screen shows the 50 oldest waiting drafts of every kind, open or closed: 61 wait in all · on its 50 cards: 49 on a CLOSED follow-up (nothing to send — Reject clears each), 1 quotation drafts · of the front\'s 0 waiting drafts: 0 beyond those 50 — all on the screen',
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
foreach (['R2' => $r2, 'R1' => $r1, 'R3' => $r3, 'R4' => $r4, 'R5' => $r5] as $tag => $r) {
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
$ok($c("SELECT COUNT(*) FROM contact_optouts WHERE phone = '256771555555' AND active = 1") === 1, 'the opt-out is in the book');
$ok($c("SELECT COUNT(*) FROM followup_drafts WHERE verdict LIKE 'PLANT%'") === 1, 'an odd verdict is in the book');
$db = null;
$ok(strpos((string)file_get_contents("$build/data/kyc_config.json"), 'PLANT-SECRET') !== false, 'a secret is in kyc_config.json');
$ok(strpos((string)file_get_contents("$build/data3/kyc_config.json"), 'PLANT 256771234567') !== false, 'the unreadable floor is in data3');
$ok(strpos((string)file_get_contents("$build/vault.json"), 'PLANT-VAULT-SECRET') !== false, 'a secret is in the vault');

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
