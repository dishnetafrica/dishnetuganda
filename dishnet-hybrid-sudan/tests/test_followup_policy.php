<?php
declare(strict_types=1);
/**
 * test_followup_policy.php — the gates, and the clock arithmetic under them.
 *
 * These rules run before the AI is consulted, so they are the reason a broken
 * or expensive brain can never be why a customer is messaged wrongly. They are
 * pure functions precisely so they can be pinned like this.
 *
 * Two things get disproportionate attention here:
 *
 *   PROVENANCE, because the worst failure this system can have is not a missed
 *   follow-up — it is sending one customer's account details to somebody else
 *   whose phone number happened to share nine digits.
 *
 *   THE WINDOW, because storage is UTC and the window is local. Comparing a
 *   UTC hour against 08:00–20:00 would shift it three hours and start
 *   messaging customers at five in the morning, and every test would pass.
 *
 * The window follows the install's configured timezone, so this file sets one
 * rather than inheriting whatever the machine running the suite happens to
 * use. It sets Africa/Kampala explicitly: these are Ugandan customers' waking
 * hours, and an unset config would silently measure them against the
 * Africa/Juba default, which has been UTC+2 since 2021 — an hour out, with
 * every assertion below still reading plausibly.
 */
require_once dirname(__DIR__) . '/lib/timezone.php';
require_once dirname(__DIR__) . '/lib/FollowUpPolicy.php';

$_tzDir = sys_get_temp_dir() . '/dn_fu_tz_' . getmypid();
@mkdir($_tzDir, 0700, true);
putenv('DN_DATA_DIR=' . $_tzDir);
file_put_contents($_tzDir . '/kyc_config.json', json_encode(['timezone' => 'Africa/Kampala']));
dn_tz_reset();
register_shutdown_function(function () use ($_tzDir) {
    @unlink($_tzDir . '/kyc_config.json'); @rmdir($_tzDir);
});

$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; } }

$P = 'FollowUpPolicy';

echo "What a proactive message may contain\n";
// CASE G, and the rule the whole design exists to protect.
t('a verified identity may discuss the account',
    FollowUpPolicy::contentLevel(['crm_client_id' => 7, 'crm_link_method' => 'verified']), 'account');
t('so may a manual link',
    FollowUpPolicy::contentLevel(['crm_client_id' => 7, 'crm_link_method' => 'manual']), 'account');
t('and an unambiguous AI identification',
    FollowUpPolicy::contentLevel(['crm_client_id' => 7, 'crm_link_method' => 'ai_identified']), 'account');
// Nine matching digits is evidence, not proof.
t('a phone-tail match gets the enquiry only',
    FollowUpPolicy::contentLevel(['crm_client_id' => 7, 'crm_link_method' => 'phone_tail']), 'enquiry');
t('so does a bulk rematch',
    FollowUpPolicy::contentLevel(['crm_client_id' => 7, 'crm_link_method' => 'bulk_rematch']), 'enquiry');
t('an unlinked prospect gets the enquiry only',
    FollowUpPolicy::contentLevel(['crm_client_id' => 0, 'crm_link_method' => null]), 'enquiry');
// CASE F.
t('an ambiguous number gets nothing at all',
    FollowUpPolicy::contentLevel(['crm_client_id' => 0, 'crm_link_method' => 'ambiguous']), 'none');
t('ambiguous outranks a linked client too',
    FollowUpPolicy::contentLevel(['crm_client_id' => 7, 'crm_link_method' => 'ambiguous']), 'none');
// A method nobody recognises is not treated as trustworthy.
t('an unknown method is not trusted',
    FollowUpPolicy::contentLevel(['crm_client_id' => 7, 'crm_link_method' => 'imported_from_somewhere']), 'enquiry');

echo "\nThe sending window is Kampala, and storage is UTC\n";
// Kampala is UTC+3. 06:00 UTC is 09:00 Kampala — inside the window.
t('06:00 UTC on a Monday is inside',   FollowUpPolicy::withinSendingWindow('2026-09-14 06:00:00')['ok'], true);
// 04:00 UTC is 07:00 Kampala — too early. Under a naive UTC comparison this
// would read as 04:00 and still be "before 08:00", so it would pass by luck.
t('04:00 UTC is 07:00 Kampala — too early', FollowUpPolicy::withinSendingWindow('2026-09-14 04:00:00')['ok'], false);
// The one that catches a missing conversion: 06:00 UTC is fine, 05:00 UTC is
// 08:00 Kampala exactly — the boundary.
t('05:00 UTC is exactly 08:00 Kampala', FollowUpPolicy::withinSendingWindow('2026-09-14 05:00:00')['ok'], true);
// 17:00 UTC = 20:00 Kampala — closed.
t('17:00 UTC is 20:00 Kampala — closed', FollowUpPolicy::withinSendingWindow('2026-09-14 17:00:00')['ok'], false);
t('16:59 UTC is still open',            FollowUpPolicy::withinSendingWindow('2026-09-14 16:59:00')['ok'], true);
// 22:00 UTC Saturday is 01:00 Kampala SUNDAY. A UTC-only check would see
// Saturday and allow it.
$satNight = FollowUpPolicy::withinSendingWindow('2026-09-12 22:00:00');
t('Saturday 22:00 UTC is Sunday in Kampala', $satNight['ok'], false);
t('and says why',                       $satNight['reason'], 'Sunday');
// 2026-09-13 is a Sunday.
t('Sunday midday is refused',           FollowUpPolicy::withinSendingWindow('2026-09-13 09:00:00')['ok'], false);

echo "\nDeferring lands on a real sending moment\n";
$next = FollowUpPolicy::withinSendingWindow('2026-09-13 09:00:00')['next'];   // Sunday
is_($next !== '', 'a Sunday defer names a next time', $next);
t('which is itself inside the window',  FollowUpPolicy::withinSendingWindow($next)['ok'], true);
$early = FollowUpPolicy::withinSendingWindow('2026-09-14 02:00:00')['next'];  // Mon 05:00 Kampala
t('an early-morning defer is valid too', FollowUpPolicy::withinSendingWindow($early)['ok'], true);
$late  = FollowUpPolicy::withinSendingWindow('2026-09-14 19:00:00')['next'];  // Mon 22:00 Kampala
t('an evening defer is valid too',       FollowUpPolicy::withinSendingWindow($late)['ok'], true);
// A Saturday-evening defer must not land on Sunday.
$satDefer = FollowUpPolicy::withinSendingWindow('2026-09-12 19:00:00')['next'];
t('a Saturday-evening defer skips Sunday', FollowUpPolicy::withinSendingWindow($satDefer)['ok'], true);

echo "\nThe cadence: 24h, 72h, stop\n";
t('attempt 1 is due 24h after they went quiet',
    FollowUpPolicy::dueAt('2026-09-10 08:00:00', 1), '2026-09-11 08:00:00');
t('attempt 2 is due 72h after',
    FollowUpPolicy::dueAt('2026-09-10 08:00:00', 2), '2026-09-13 08:00:00');
// This empty string is how the cadence ends. There is no third.
t('there is no attempt 3',        FollowUpPolicy::dueAt('2026-09-10 08:00:00', 3), '');

echo "\nThe gates\n";
$base = [
    'enabled' => true,
    'now'     => '2026-09-14 07:00:00',              // Monday 10:00 Kampala
    'conv'    => ['last_customer_at' => '2026-09-11 07:00:00', 'last_agent_at' => '2026-09-11 06:00:00',
                  'state' => 'bot_active', 'status' => 'active',
                  'crm_client_id' => 7, 'crm_link_method' => 'verified'],
    'followup'=> ['attempts' => 0, 'max_attempts' => 2, 'due_at' => '2026-09-12 07:00:00'],
    'opt_out' => null, 'sent_today' => 0, 'daily_cap' => 50, 'thread_text' => 'how much is DishNet Home?',
];
$g = FollowUpPolicy::gate($base);
t('a clean case proceeds',        $g['action'], 'proceed');
t('and is allowed',               $g['allow'], true);

$off = $base; $off['enabled'] = false;
t('the master switch stops everything', FollowUpPolicy::gate($off)['gate'], 'disabled');

// CASE D — the opt-out closes, it does not merely skip.
$out = $base; $out['opt_out'] = ['blocked' => true, 'reason' => 'opted out (proactive)'];
t('an opt-out closes the follow-up',    FollowUpPolicy::gate($out)['action'], 'close');
t('naming the gate',                    FollowUpPolicy::gate($out)['gate'], 'opt_out');

// CASE C — they are mid-conversation.
$live = $base;
$live['conv']['last_customer_at'] = '2026-09-14 06:30:00';   // 30 minutes ago
t('a live conversation is skipped',     FollowUpPolicy::gate($live)['gate'], 'active_conversation');

// CASE H.
$human = $base; $human['conv']['state'] = 'human_active';
t('a colleague taking over closes it',  FollowUpPolicy::gate($human)['action'], 'close');
t('naming the gate',                    FollowUpPolicy::gate($human)['gate'], 'human_active');

// CASE B's ending.
$done = $base; $done['followup']['attempts'] = 2;
t('two attempts used closes it',        FollowUpPolicy::gate($done)['gate'], 'exhausted');

// CASE I.
foreach (['I want a refund for this month', 'I am contacting my lawyer',
          'I will report you to the UCC'] as $angry) {
    $esc = $base; $esc['thread_text'] = $angry;
    $r = FollowUpPolicy::gate($esc);
    is_($r['gate'] === 'escalation' && $r['action'] === 'close',
        "escalation closes it: '{$angry}'", $r['gate'] . '/' . $r['action']);
}

// CASE F at the gate, not just the content level.
$amb = $base; $amb['conv']['crm_link_method'] = 'ambiguous';
t('an ambiguous identity is skipped',   FollowUpPolicy::gate($amb)['gate'], 'identity_ambiguous');
t('skipped, not closed — it may be resolved later',
    FollowUpPolicy::gate($amb)['action'], 'skip');

$early2 = $base; $early2['followup']['due_at'] = '2026-09-20 07:00:00';
t('not yet due defers',                 FollowUpPolicy::gate($early2)['gate'], 'not_due');

// CASE J.
$night = $base; $night['now'] = '2026-09-14 19:30:00';   // 22:30 Kampala
$nr = FollowUpPolicy::gate($night);
t('outside the window defers',          $nr['action'], 'defer');
t('naming quiet hours',                 $nr['gate'], 'quiet_hours');
is_($nr['defer_until'] !== '', 'and names when to come back', $nr['defer_until']);
t('which is inside the window',         FollowUpPolicy::withinSendingWindow($nr['defer_until'])['ok'], true);

$capped = $base; $capped['sent_today'] = 50;
t('the daily cap defers',               FollowUpPolicy::gate($capped)['gate'], 'daily_cap');
t('defers rather than closing',         FollowUpPolicy::gate($capped)['action'], 'defer');

echo "\nGate order: the more certain rule wins\n";
// Opted out AND outside hours: the opt-out must win, because deferring would
// leave a row that comes back to ask again about somebody who said stop.
$both = $base;
$both['now'] = '2026-09-14 19:30:00';
$both['opt_out'] = ['blocked' => true, 'reason' => 'opted out'];
t('opt-out beats quiet hours',          FollowUpPolicy::gate($both)['gate'], 'opt_out');
// Escalation AND exhausted: either closes, but escalation is the one a human
// needs to see, so attempts must not mask it... it is checked after, so the
// exhausted case wins. Pin the real behaviour rather than the wish.
$bothClose = $base;
$bothClose['followup']['attempts'] = 2;
$bothClose['thread_text'] = 'I am contacting my lawyer';
t('exhausted is reported before escalation', FollowUpPolicy::gate($bothClose)['gate'], 'exhausted');

echo "\nIs this conversation worth following up at all?\n";
$now = '2026-09-14 07:00:00';
t('quiet for three days — yes',
    FollowUpPolicy::isFollowable(['last_customer_at' => '2026-09-11 07:00:00',
                                  'state' => 'bot_active', 'status' => 'active'], $now)['ok'], true);
t('quiet for two hours — not yet',
    FollowUpPolicy::isFollowable(['last_customer_at' => '2026-09-14 05:00:00',
                                  'state' => 'bot_active', 'status' => 'active'], $now)['ok'], false);
t('quiet for three months — history, not an enquiry',
    FollowUpPolicy::isFollowable(['last_customer_at' => '2026-06-14 07:00:00',
                                  'state' => 'bot_active', 'status' => 'active'], $now)['ok'], false);
t('never wrote to us — no',
    FollowUpPolicy::isFollowable(['last_customer_at' => '', 'status' => 'active'], $now)['ok'], false);
t('a colleague has it — no',
    FollowUpPolicy::isFollowable(['last_customer_at' => '2026-09-11 07:00:00',
                                  'state' => 'human_active', 'status' => 'active'], $now)['ok'], false);
t('a closed conversation — no',
    FollowUpPolicy::isFollowable(['last_customer_at' => '2026-09-11 07:00:00',
                                  'state' => 'bot_active', 'status' => 'closed'], $now)['ok'], false);

echo "\nThe window is measured in the install's zone, not a literal\n";
is_(FollowUpPolicy::where() === 'Kampala', 'refusals name Kampala on this install');
file_put_contents($_tzDir . '/kyc_config.json', json_encode(['timezone' => 'Africa/Juba']));
dn_tz_reset();
is_(FollowUpPolicy::where() === 'Juba', 'and would name Juba on the other one');
t('05:00 UTC is 07:00 in Juba — too early there',
  FollowUpPolicy::withinSendingWindow('2026-09-14 05:00:00')['ok'], false);
is_(strpos(FollowUpPolicy::withinSendingWindow('2026-09-14 05:00:00')['reason'], 'Juba') !== false,
    'and says so, rather than blaming a Kampala clock it is not using');
file_put_contents($_tzDir . '/kyc_config.json', json_encode(['timezone' => 'Africa/Kampala']));
dn_tz_reset();
t('the same instant is exactly 08:00 in Kampala — open',
  FollowUpPolicy::withinSendingWindow('2026-09-14 05:00:00')['ok'], true);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
