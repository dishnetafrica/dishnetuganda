<?php
/**
 * test_draft_mail_copy.php — the approval inbox is the mail client.
 *
 * A reply waiting in Drafts can be read on a phone, edited in the window
 * everyone already uses, and sent with the button they already know. Nothing
 * new to learn and no second place to check — which is the difference between
 * an approval queue that gets used and one that does not.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
foreach (['EmailTemplate', 'MailService', 'SentCopy', 'DraftMailCopy', 'EmailDraftStore'] as $c) {
    require_once $root . '/lib/' . $c . '.php';
}

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

$config = [
    'email_company_name' => 'DishNet Africa Limited',
    'email_reply_to'     => 'accounts@dishnetuganda.com',
];
$draft = [
    'id'            => 2,
    'message_id'    => '<CAF-felix-001@mail.gmail.com>',
    'from_addr'     => 'orechfelix4@gmail.com',
    'subject'       => 'Re: Quotation 000001 — Starlink Internet for Subterra Limited',
    'draft_subject' => 'Re: Quotation 000001 — Starlink Internet for Subterra Limited',
    'draft_body'    => "Dear Subterra Limited,\n\nThank you for the purchase order.",
    'escalation'    => 'human approval required',
];

echo "\nThe message it builds\n";
// Reach the composed message without a mail server: SentCopy refuses when
// disabled, so build the same MIME the placer would hand it.
$mailer = new MailService($root);
[$h, $b] = $mailer->composeMime(
    DraftMailCopy::header($config['email_company_name'], $config['email_reply_to']),
    $draft['from_addr'], $draft['draft_subject'],
    DraftMailCopy::html($draft['draft_body']), $draft['draft_body'],
    ['In-Reply-To' => $draft['message_id'], 'References' => $draft['message_id'],
     'X-DishNet-Draft' => 'assistant']
);
$raw = $h . "\r\n" . $b;

is_(strpos($raw, 'To: orechfelix4@gmail.com') !== false, 'it is addressed to the customer');
is_(strpos($raw, 'accounts@dishnetuganda.com') !== false, 'and comes from the Uganda mailbox');
is_(strpos($raw, 'In-Reply-To: <CAF-felix-001@mail.gmail.com>') !== false,
    'In-Reply-To carries their Message-ID, so it threads under their email');
is_(strpos($raw, 'References: <CAF-felix-001@mail.gmail.com>') !== false,
    'as does References, which is what older clients read');
is_(strpos($raw, 'X-DishNet-Draft: assistant') !== false,
    'and it says plainly that an assistant wrote it');
is_(strpos($raw, 'Thank you for the purchase order') !== false, 'the body is the draft');

echo "\nWhat a reviewer edits is what the customer gets\n";
$html = DraftMailCopy::html("Line one\nLine two & <b>not bold</b>");
is_(strpos($html, '&lt;b&gt;') !== false, 'the body is escaped, never interpreted as markup');
is_(strpos($html, 'white-space:pre-wrap') !== false, 'line breaks survive without <br> surgery');
is_(strpos($html, '<table') === false,
    'it is not wrapped in the branded shell — a reviewer editing inside one '
  . 'breaks it without seeing that they have');

echo "\nIt is filed as a draft, not as read mail\n";
$src = (string)file_get_contents($root . '/lib/DraftMailCopy.php');
is_(strpos($src, "'special' => '\\\\Drafts'") !== false,
    'the Drafts folder is found by its special-use flag, not by name');
is_(strpos($src, "'flags'   => '\\\\Draft'") !== false, 'and appended with the \\Draft flag');
is_(strpos($src, '\\\\Seen') === false,
    'never \\Seen — a draft that arrives pre-read is a draft nobody notices');

echo "\nIt cannot send\n";
is_(preg_match('/->\s*send\s*\(/', $src) === 0, 'it calls nothing named send()');
is_(strpos($src, 'does not send, and it cannot') !== false,
    'and the file says so where the next person will read it');

echo "\nAn empty draft is not filed\n";
$r = DraftMailCopy::place(['sent_copy_enabled' => true], ['draft_body' => '  ',
    'from_addr' => 'a@b.c'], $config);
is_(empty($r['ok']), 'nothing is placed');
is_(strpos((string)$r['error'], 'empty') !== false,
    'because a blank reply in front of a person hints at nothing', (string)$r['error']);

$r2 = DraftMailCopy::place(['sent_copy_enabled' => true], ['draft_body' => 'hi',
    'from_addr' => ''], $config);
is_(empty($r2['ok']) && strpos((string)$r2['error'], 'recipient') !== false,
    'nor is one with nobody to send it to', (string)$r2['error']);

echo "\nThe same draft is never filed twice\n";
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$store = new EmailDraftStore($pdo);
$id = $store->add(['message_id' => '<a@b>', 'from_addr' => 'x@y.z',
                   'draft_body' => 'hello', 'status' => EmailDraftStore::PENDING]);
is_(count($store->unplaced()) === 1, 'a fresh draft is waiting to be filed');
is_($store->markPlaced($id, 'Drafts') === true, 'filing it is recorded');
is_($store->markPlaced($id, 'Drafts') === false, 'and recording it again does nothing');
is_(count($store->unplaced()) === 0, 'so it is not offered a second time');

echo "\nAn empty draft is never offered for filing\n";
$store->add(['message_id' => '<c@d>', 'from_addr' => 'x@y.z',
             'draft_body' => '', 'status' => EmailDraftStore::PENDING]);
is_(count($store->unplaced()) === 0, 'one the assistant refused to write stays out of the mailbox');

echo "\nSent mail is unaffected by the folder becoming a parameter\n";
$sentSrc = (string)file_get_contents($root . '/lib/SentCopy.php');
is_(strpos($sentSrc, "\$specialFlag  = (string)(\$opts['special'] ?? '\\\\Sent')") !== false,
    'the default special-use flag is still \\Sent');
is_(strpos($sentSrc, "\$appendFlags  = (string)(\$opts['flags']   ?? '\\\\Seen')") !== false,
    'and the default append flag is still \\Seen');
$r3 = SentCopy::append([], 'raw');
is_((string)$r3['error'] === 'disabled', 'and an unconfigured call behaves exactly as before');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
