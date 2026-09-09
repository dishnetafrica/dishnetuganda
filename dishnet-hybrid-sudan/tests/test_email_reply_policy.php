<?php
/**
 * test_email_reply_policy.php — what may be sent without a person, and the
 * queue that holds everything else.
 *
 * The asymmetry that governs these tests: sending a wrong price costs a phone
 * call, and conceding a refund costs money DishNet may have to pay. So the
 * policy is tested hardest on what it must REFUSE to automate.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/lib/EmailReplyPolicy.php';
require_once $root . '/lib/EmailDraftStore.php';

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

echo "\nCategories that can bind or cost the company are never automatic\n";
foreach (array_keys(EmailReplyPolicy::NEVER_AUTO) as $cat) {
    is_(EmailReplyPolicy::requiresHuman($cat), "{$cat} requires a human");
    // Even with every switch on and total confidence.
    $everythingOn = ['email_ai_auto_send' => 1, 'email_ai_auto_' . $cat => 1,
                     'email_ai_confidence_floor' => 0];
    is_(!EmailReplyPolicy::mayAutoSend($cat, $everythingOn, 1.0),
        "{$cat} stays manual even with every switch on and full confidence");
}

echo "\nAn invented category cannot unlock automatic sending\n";
is_(EmailReplyPolicy::requiresHuman('totally_made_up'),
    'an unknown category requires a human');
is_(!EmailReplyPolicy::mayAutoSend('totally_made_up',
      ['email_ai_auto_send' => 1, 'email_ai_auto_totally_made_up' => 1], 1.0),
    'and cannot be switched on by naming it in config');

echo "\nThe safe categories still start off\n";
is_(!EmailReplyPolicy::mayAutoSend('plans_pricing', [], 1.0),
    'nothing is automatic with no configuration at all');
is_(!EmailReplyPolicy::mayAutoSend('plans_pricing', ['email_ai_auto_plans_pricing' => 1], 1.0),
    'the per-category switch alone is not enough');
is_(!EmailReplyPolicy::mayAutoSend('plans_pricing', ['email_ai_auto_send' => 1], 1.0),
    'the master switch alone is not enough');
is_(EmailReplyPolicy::mayAutoSend('plans_pricing',
      ['email_ai_auto_send' => 1, 'email_ai_auto_plans_pricing' => 1], 0.95),
    'both together, with confidence, may send');
is_(!EmailReplyPolicy::mayAutoSend('plans_pricing',
      ['email_ai_auto_send' => 1, 'email_ai_auto_plans_pricing' => 1], 0.4),
    'but low confidence goes to a human instead');
is_(!EmailReplyPolicy::mayAutoSend('coverage',
      ['email_ai_auto_send' => 1, 'email_ai_auto_plans_pricing' => 1], 0.95),
    'and switching one category on does not switch its neighbours on');

echo "\nThe words themselves can force a human, whatever the category says\n";
foreach ([
    'I want a refund for last month'          => 'refund',
    'my lawyer will contact you'              => 'lawyer',
    'I am reporting this to the UCC'          => 'ucc',
    'this is a scam'                          => 'scam',
    'I want to cancel my subscription'        => 'cancel my',
    'I expect compensation for the outage'    => 'compensation',
] as $text => $expect) {
    $r = EmailReplyPolicy::scanForEscalation($text);
    is_($r['escalate'] && $r['matched'] === $expect,
        "\"{$text}\" escalates on \"{$expect}\"",
        'matched: ' . var_export($r['matched'], true));
}

echo "\nOrdinary messages are not escalated by accident\n";
foreach ([
    'I have an issue with my speed',                 // "issue" contains "sue"
    'Please send the invoice to accounts',
    'What time do you open on Saturday?',
    'The technician was excellent, thank you',
    'Can you succumb to my request for more speed', // "succumb" contains "ucc"
] as $text) {
    $r = EmailReplyPolicy::scanForEscalation($text);
    is_(!$r['escalate'], "\"{$text}\" is left alone",
        'wrongly matched: ' . var_export($r['matched'], true));
}

echo "\nThe draft queue keeps an answerable record\n";
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$store = new EmailDraftStore($pdo);

$id = $store->add([
    'message_id' => '<abc@example.com>', 'from_addr' => 'felix@x.com',
    'from_name' => 'Felix Orech', 'subject' => 'Starlink Mini pricing',
    'body_excerpt' => 'How much is the monthly package?',
    'category' => 'plans_pricing', 'confidence' => 0.93,
    'draft_subject' => 'Re: Starlink Mini pricing', 'draft_body' => 'Hello Felix, ...',
]);
is_($id > 0, 'a draft is stored');
is_($store->seen('<abc@example.com>'), 'and the message is remembered as seen');
is_($store->add(['message_id' => '<abc@example.com>', 'from_addr' => 'felix@x.com']) === 0,
    're-reading the mailbox does not create a second draft');

$row = $store->get($id);
is_(($row['status'] ?? '') === EmailDraftStore::PENDING, 'it waits as pending');
is_(count($store->listByStatus(EmailDraftStore::PENDING)) === 1, 'and appears in the pending list');

// An edited approval must record what actually went out, not what the AI wrote.
$store->decide($id, EmailDraftStore::SENT, 'bhavin', 'Hello Felix, the price is UGX 329,000.');
$row = $store->get($id);
is_(($row['status'] ?? '') === EmailDraftStore::SENT, 'a decision is recorded');
is_(($row['decided_by'] ?? '') === 'bhavin', 'with the person who made it');
is_(strpos((string)$row['sent_body'], 'UGX 329,000') !== false,
    'and the wording that actually went out, not the AI draft');
is_(trim((string)$row['decided_at']) !== '', 'and when');
is_((string)$row['draft_body'] === 'Hello Felix, ...',
    'while the original AI draft is still there to compare against');

is_(!$store->decide($id, 'nonsense_status', 'x'), 'an unknown status is refused');

echo "\nThe rate-limit count follows sends, not drafts\n";
is_($store->repliesSentTo('felix@x.com') === 1, 'one send to this address is counted');
$store->add(['message_id' => '<d2@x>', 'from_addr' => 'felix@x.com', 'draft_body' => 'x']);
is_($store->repliesSentTo('felix@x.com') === 1,
    'a pending draft is not counted — unapproved drafts are not a loop');
is_($store->repliesSentTo('someone.else@x.com') === 0, 'and other addresses are separate');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
