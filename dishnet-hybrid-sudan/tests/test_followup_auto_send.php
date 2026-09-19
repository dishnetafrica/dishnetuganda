<?php
/**
 * test_followup_auto_send.php — WhatsApp enquiry follow-ups may send
 * themselves. Nothing else may.
 *
 * ── The decision this encodes ───────────────────────────────────────────
 *
 * The operator's reasoning: WhatsApp is one-to-one, the message is short, and
 * it goes to somebody who wrote to us first about something they asked. Email
 * is different and keeps its own policy — EmailReplyPolicy splits categories
 * by what a wrong answer costs and holds thirteen of them back
 * unconditionally, not configurably. None of that changes here.
 *
 * ── Where the line is drawn, and why it is there ────────────────────────
 *
 * CONTENT_ACCOUNT means provenance is good enough to discuss a balance. That
 * is the right bar for ANSWERING somebody who just wrote. It is not the right
 * bar for a message we chose to send, unread, about their money: if the
 * identity is wrong, an enquiry follow-up wastes a message and an account
 * follow-up puts somebody else's balance on a stranger's phone. Different
 * mistakes, different gates.
 *
 * ── The safety property that must survive ───────────────────────────────
 *
 * Auto-send APPROVES a draft. It does not send one. followup_send.php still
 * does that, and still re-checks opt-out, human takeover, a reply arriving
 * and the sending window between approval and delivery. Section 6 asserts
 * that path was not bypassed, because bypassing it is the obvious shortcut
 * and it would silently drop four protections at once.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

require_once $root . '/lib/EmailReplyPolicy.php';
require_once $root . '/lib/FollowUpPolicy.php';

$SEND = ['verdict' => 'SEND', 'message' => 'Hi, are you still looking at a connection for the shop?'];
$ON   = ['followup_auto_send' => '1'];
$ENQ  = FollowUpPolicy::CONTENT_ENQUIRY;

echo "\n1. Off unless switched on — an upgrade changes nothing by itself\n";
$r = FollowUpPolicy::mayAutoSend($SEND, $ENQ, []);
is_($r['auto'] === false, 'absent config means no automatic send',
    'every install that upgrades into this must behave as it did yesterday');
is_(strpos($r['reason'], 'followup_auto_send') !== false, 'and the reason names the switch');
is_(FollowUpPolicy::mayAutoSend($SEND, $ENQ, ['followup_auto_send' => '0'])['auto'] === false,
    'explicitly off is off');

echo "\n2. On: an enquiry follow-up the assistant wants to send, sends\n";
$r = FollowUpPolicy::mayAutoSend($SEND, $ENQ, $ON);
is_($r['auto'] === true, 'this is the case the operator asked for', $r['reason']);

echo "\n3. Only the SEND verdict\n";
foreach (['DO_NOT_SEND', 'WAIT', 'ESCALATE_TO_HUMAN'] as $v) {
    $r = FollowUpPolicy::mayAutoSend(['verdict' => $v, 'message' => 'x'], $ENQ, $ON);
    is_($r['auto'] === false, "{$v} never sends itself");
}
is_(FollowUpPolicy::mayAutoSend(['verdict' => 'send', 'message' => 'x'], $ENQ, $ON)['auto'] === true,
    'lower-case send is still SEND');
is_(FollowUpPolicy::mayAutoSend(['message' => 'x'], $ENQ, $ON)['auto'] === false,
    'a missing verdict is not a SEND');

echo "\n4. An empty message is a bug, not a send\n";
foreach (['', '   ', "\n"] as $body) {
    is_(FollowUpPolicy::mayAutoSend(['verdict' => 'SEND', 'message' => $body], $ENQ, $ON)['auto'] === false,
        'blank body does not send');
}

echo "\n5. Account-level content still waits for a person\n";
// The important one. Provenance good enough to ANSWER a balance question is
// not provenance good enough to SEND somebody a message about their money.
$r = FollowUpPolicy::mayAutoSend($SEND, FollowUpPolicy::CONTENT_ACCOUNT, $ON);
is_($r['auto'] === false, 'CONTENT_ACCOUNT never auto-sends',
    'a wrong identity here puts another customer\'s balance on a stranger\'s phone');
is_(strpos($r['reason'], 'account') !== false, 'and says so');
is_(FollowUpPolicy::mayAutoSend($SEND, FollowUpPolicy::CONTENT_NONE, $ON)['auto'] === false,
    'CONTENT_NONE certainly not');

echo "\n6. A drafted message that mentions escalation words stays in the queue\n";
// The customer's own words already close the follow-up at gate 6. This is the
// other direction: the ASSISTANT writing about a refund.
foreach (['We can look at a refund for you', 'I will ask our lawyer',
          'sorry about your complaint'] as $body) {
    $r = FollowUpPolicy::mayAutoSend(['verdict' => 'SEND', 'message' => $body], $ENQ, $ON);
    is_($r['auto'] === false, 'held: "' . mb_substr($body, 0, 28) . '"', $r['reason']);
}

echo "\n7. Auto-send APPROVES — it does not send, and did not bypass the sender\n";
$run  = (string)file_get_contents($root . '/cron/followup_run.php');
$send = (string)file_get_contents($root . '/cron/followup_send.php');
is_(strpos($run, 'mayAutoSend(') !== false, 'followup_run consults the policy');
is_(preg_match('/approve\(\s*\(int\)\$r\[.id.\],\s*.auto./', $run) === 1,
    'and approves the draft as \'auto\'', 'so decided_by tells the two apart afterwards');
is_(strpos($run, 'sendText(') === false && strpos($run, 'EvolutionApiService') === false,
    'followup_run STILL has no way to reach Evolution');
foreach (['opted_out', 'human_closed', 'replied', 'withinSendingWindow'] as $recheck) {
    is_(strpos($send, $recheck) !== false,
        "followup_send still re-checks {$recheck} before delivering");
}
is_(strpos($send, 'approvedDrafts(') !== false,
    'and still sends only what is approved — auto or human, one path');

echo "\n8. Email is untouched\n";
// Thirteen categories a person must always see. If this list ever shrinks,
// somebody has quietly widened what a machine may answer.
is_(count(EmailReplyPolicy::NEVER_AUTO) >= 13,
    'EmailReplyPolicy::NEVER_AUTO still holds back 13+ categories',
    count(EmailReplyPolicy::NEVER_AUTO) . ' categories');
foreach (['refund', 'complaint', 'dispute', 'legal', 'order_po', 'technical_fault'] as $cat) {
    is_(EmailReplyPolicy::requiresHuman($cat), "email '{$cat}' still requires a human");
}
is_(EmailReplyPolicy::mayAutoSend('plans_pricing', $ON, 1.0) === false,
    'followup_auto_send does NOT unlock e-mail',
    'the two switches are separate and must stay separate');

echo "\n9. The switch is settable\n";
$cfg = (string)file_get_contents($root . '/tools/set_config.php');
is_(strpos($cfg, "'followup_auto_send'") !== false, 'set_config.php registers it');

echo "\nA conversation a person owes an answer may not answer itself\n";
// needs_human is what the assistant sets when it hands over and tells the
// customer a colleague will reply. The gate chain tests human_active — a
// colleague ALREADY TYPING — and never tested this one.
//
// c354: on the 15th the assistant said "let me confirm with our team and come
// back to you today" about registering a kit on a DRC address. Nobody did. On
// the 19th the first automatic message this plugin ever sent asked THAT
// customer whether THEY had any questions, while they were still waiting for
// the answer we promised.
$send = ['verdict' => 'SEND', 'message' => 'Following up on the kit you asked about.'];
$on   = ['followup_auto_send' => '1'];

foreach (['needs_human', 'human_active'] as $state) {
    $r = FollowUpPolicy::mayAutoSend($send, FollowUpPolicy::CONTENT_ENQUIRY, $on,
                                     ['state' => $state]);
    is_($r['auto'] === false, $state . ' may not send itself');
    is_(strpos($r['reason'], $state) !== false,
        'and the reason names it, so the queue explains itself', $r['reason']);
}
// Case must not decide a safety gate.
is_(FollowUpPolicy::mayAutoSend($send, FollowUpPolicy::CONTENT_ENQUIRY, $on,
        ['state' => 'NEEDS_HUMAN'])['auto'] === false,
    'the check is not defeated by capitals');

foreach (['new', 'active', ''] as $state) {
    is_(FollowUpPolicy::mayAutoSend($send, FollowUpPolicy::CONTENT_ENQUIRY, $on,
            ['state' => $state])['auto'] === true,
        'a conversation in "' . ($state ?: 'no state') . '" still sends itself',
        'the fix must not stop the 246 ordinary enquiries this was built for');
}
is_(FollowUpPolicy::mayAutoSend($send, FollowUpPolicy::CONTENT_ENQUIRY, $on)['auto'] === true,
    'and an absent conversation argument does not silently block everything');

echo "\nThe draft is still written — only the sending is withheld\n";
// The colleague picking this up wants the draft waiting. Blocking the draft
// would make the gap worse, not better.
$run = (string)file_get_contents($root . '/cron/followup_run.php');
is_(strpos($run, 'mayAutoSend($verdict, $level, $config, $conv)') !== false,
    'the cron passes the conversation into the decision');
is_(strpos($run, '$drafted++') < strpos($run, 'mayAutoSend'),
    'and drafts before it asks whether it may send',
    'if the order flips, a needs_human conversation loses its draft too');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
