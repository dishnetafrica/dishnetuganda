<?php
/**
 * test_followup_cadence.php — the wait between OUR messages, not theirs.
 *
 * ── The bug this pins ───────────────────────────────────────────────────
 *
 * SCHEDULE says attempt 1 at 24 hours and attempt 2 at 72, both measured from
 * the customer's last message. That is right for attempt 1: the wait IS their
 * silence. For attempt 2 it silently assumes attempt 1 went out on time.
 *
 * In September 2026 it did not. followup_run read claude_api_key on an OpenAI
 * install, so for five days it returned at its guard and drafted nothing while
 * the queue filled. When it was fixed, every queued row had its attempt-2 date
 * already in the past — 110 of 276 open follow-ups — so attempt 2 was due the
 * moment attempt 1 was sent. fu83 was sent at 05:15:26 on the 19th and armed
 * attempt 2 for 12:25:33 on the 18th: seventeen hours before we wrote.
 *
 * Nobody received two messages, because auto-send was switched off first. The
 * arithmetic below is what makes that a decision rather than a near miss.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }
function eq(string $got, string $want, string $m): void {
    is_($got === $want, $m, 'got ' . ($got === '' ? '(empty)' : $got) . ', wanted ' . $want);
}

require_once $root . '/lib/EmailReplyPolicy.php';
require_once $root . '/lib/FollowUpPolicy.php';

echo "\nfu83, exactly as it happened\n";
$quiet = '2026-09-15 12:25:33';   // their last message
$sent  = '2026-09-19 05:15:26';   // when attempt 1 actually went out, 5 days late
eq(FollowUpPolicy::dueAt($quiet, 2), '2026-09-18 12:25:33',
   'the old rule armed attempt 2 for the 18th');
is_(FollowUpPolicy::dueAt($quiet, 2) < $sent,
    'which was already in the past when we sent attempt 1',
    'this is the bug: the next attempt was overdue before the first one landed');
eq(FollowUpPolicy::nextDueAfterSend($quiet, $sent, 2), '2026-09-21 05:15:26',
   'the fix arms it for 48 hours after we actually wrote');

echo "\nA punctual send is unaffected in substance\n";
// Quiet at noon, attempt 1 goes at 12:05 the next day: the customer anchor and
// the send floor land within minutes, and the later of the two wins.
$q2 = '2026-09-15 12:00:00';
$s2 = '2026-09-16 12:05:00';
eq(FollowUpPolicy::nextDueAfterSend($q2, $s2, 2), '2026-09-18 12:05:00',
   'attempt 2 still falls on the 18th');
is_(FollowUpPolicy::nextDueAfterSend($q2, $s2, 2) >= FollowUpPolicy::dueAt($q2, 2),
    'and is never earlier than the schedule intended');

echo "\nThe floor is the gap the schedule already describes\n";
// 72 - 24 = 48. The fix invents no new interval; it reuses the one that was
// always implied between attempt 1 and attempt 2.
$gap = (int)(FollowUpPolicy::SCHEDULE[2] ?? 0) - (int)(FollowUpPolicy::SCHEDULE[1] ?? 0);
is_($gap === 48, 'the intended gap between attempts is 48 hours');
$late = FollowUpPolicy::nextDueAfterSend('2026-01-01 00:00:00', '2026-06-01 09:00:00', 2);
eq($late, '2026-06-03 09:00:00', 'however late the send, the next one is 48 hours after it');

echo "\nThe cadence still ends by arithmetic\n";
eq(FollowUpPolicy::nextDueAfterSend($quiet, $sent, 3), '',
   'there is no attempt 3, so nothing is armed',
   'the row is closed as exhausted on the next look — that must not change');
eq(FollowUpPolicy::nextDueAfterSend($quiet, $sent, 9), '', 'nor an attempt 9');

echo "\nA clock it cannot read must never shorten the wait\n";
foreach (['', 'not a date', '0000-00-00 00:00:00'] as $bad) {
    $r = FollowUpPolicy::nextDueAfterSend($quiet, $bad, 2);
    is_($r === '' || $r >= FollowUpPolicy::dueAt($quiet, 2),
        'an unreadable send time falls back, it does not bring the next attempt forward',
        'input was ' . var_export($bad, true) . ', got ' . var_export($r, true));
}

echo "\nThe service arms the next attempt through the fix, not around it\n";
$svc = (string)file_get_contents($root . '/lib/FollowUpService.php');
is_(strpos($svc, 'FollowUpPolicy::nextDueAfterSend') !== false,
    'recordSend calls nextDueAfterSend');
is_(!preg_match('/\$next\s*=\s*FollowUpPolicy::dueAt\(/', $svc),
    'and no longer arms it from the customer anchor alone',
    'if dueAt comes back here the 110-row problem comes back with it');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
