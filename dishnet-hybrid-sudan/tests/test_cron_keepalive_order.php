<?php
/**
 * test_cron_keepalive_order.php — the keep-alive must be dispatched first.
 *
 * This pins a scheduling fact that cost a live session to learn.
 *
 * starlink_alive was registered directly after inbound_mail, which reads a
 * mailbox and calls an LLM to draft replies. Both carried interval 300, so
 * they fell due on the same cycle every time, and master.php stops
 * dispatching once its budget is spent:
 *
 *     if (time() > $_m_deadline) { ... break; }
 *
 * So on every cycle where the mail run was slow, the keep-alive was never
 * reached. The session expired with failures 0 and state ACTIVE, because
 * nothing had touched it to discover otherwise — the most misleading shape a
 * failure can take, since every number on the status screen looked healthy.
 *
 * These assertions read the real schedule, not a copy of it.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

$src = (string)file_get_contents($root . '/cron/master.php');

// The array is read as text on purpose: including master.php would run every
// job on this machine. The registration line is what we are asserting about.
$posOf = function (string $job) use ($src): int {
    $at = strpos($src, "'" . $job . "'" . ' ');
    if ($at === false) $at = strpos($src, "'" . $job . "'=>");
    if ($at === false) $at = strpos($src, "'" . $job . "'");
    return $at === false ? -1 : $at;
};

echo "\nThe keep-alive is dispatched before anything that can spend the budget\n";

$alive = $posOf('starlink_alive');
$mail  = $posOf('inbound_mail');
$slMail= $posOf('starlink_mail');

is_($alive > 0, 'starlink_alive is registered at all');
is_($mail  > 0, 'inbound_mail is registered at all');
is_($alive < $mail,  'it comes before inbound_mail (the LLM draft run)',
    'starlink_alive at ' . $alive . ', inbound_mail at ' . $mail);
is_($alive < $slMail, 'it comes before starlink_mail too');

// Everything registered before it must be cheap enough not to matter. The
// simplest way to keep that true is for nothing to be registered before it.
$head = substr($src, (int)strpos($src, '$_m_jobs = ['), $alive - (int)strpos($src, '$_m_jobs = ['));
is_(strpos($head, "=> ['interval'") === false,
    'nothing at all is dispatched ahead of it',
    'a job was registered before starlink_alive');

echo "\nIts interval does not alias with the UCRM heartbeat\n";

// master.php is driven on a ~300s heartbeat. An interval of exactly 300 lands
// the elapsed check on the boundary, so one late cycle defers the job a
// further five minutes — too long for a token that lives minutes.
if (preg_match("/'starlink_alive'\s*=>\s*\['interval'\s*=>\s*(\d+)/", $src, $m)) {
    $iv = (int)$m[1];
    is_($iv < 300, 'interval is under the 300s heartbeat, so it cannot be skipped',
        'interval is ' . $iv);
    is_($iv >= 120, 'but not so short that it hammers Starlink', 'interval is ' . $iv);
} else {
    bad('the interval could be read from the schedule');
}

echo "\nThe budget guard that caused this is still the reason it matters\n";
is_(strpos($src, 'BUDGET EXCEEDED') !== false && strpos($src, 'break;') !== false,
    'master.php still stops dispatching when the budget is spent',
    'if this guard is gone, the ordering rule above needs revisiting');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
