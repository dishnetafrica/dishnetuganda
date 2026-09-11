<?php
/**
 * test_inbound_mail_filter.php — the loop guard.
 *
 * Two failures matter here and they are not symmetric. Ignoring a real
 * customer costs one missed reply, which a human notices and fixes. Answering
 * a machine can start a loop that burns a sending reputation built over weeks.
 * So these tests are heavier on the second, and the filter is written to fail
 * towards silence.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/lib/InboundMailFilter.php';

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }

$OURS = ['accounts@dishnetuganda.com', 'support@dishnetuganda.com', 'noreply@dishnetuganda.com'];

function ignored(array $h, string $from, string $subj = 'Hello'): array {
    return InboundMailFilter::assess($h, $from, $subj, ['accounts@dishnetuganda.com',
        'support@dishnetuganda.com', 'noreply@dishnetuganda.com']);
}
function mustIgnore(string $what, array $h, string $from, string $subj = 'Hello'): void {
    $r = ignored($h, $from, $subj);
    $r['ignore'] ? ok("{$what}  ({$r['reason']})")
                 : bad("{$what} — WAS NOT IGNORED", "from={$from} subject={$subj}");
}
function mustAnswer(string $what, array $h, string $from, string $subj = 'Hello'): void {
    $r = ignored($h, $from, $subj);
    !$r['ignore'] ? ok($what) : bad("{$what} — was ignored", 'reason: ' . $r['reason']);
}

echo "\nOur own mail must never be answered\n";
mustIgnore('a message from our own accounts@',  [], 'accounts@dishnetuganda.com');
mustIgnore('with a display name around it',     [], 'DishNet <ACCOUNTS@dishnetuganda.com>');
mustIgnore('anything carrying our automation header', ['X-DishNet-Auto' => '1'], 'someone@example.com');

echo "\nBounces and delivery reports\n";
mustIgnore('a null Return-Path',            ['Return-Path' => '<>'], 'MAILER-DAEMON@x.com');
mustIgnore('an empty Return-Path',          ['Return-Path' => ''],   'x@y.com');
mustIgnore('a multipart/report body',       ['Content-Type' => 'multipart/report; report-type=delivery-status; boundary=x'], 'x@y.com');
mustIgnore('a delivery-status part',        ['Content-Type' => 'message/delivery-status'], 'x@y.com');
mustIgnore('an Undeliverable subject',      [], 'postmaster@x.com', 'Undeliverable: Your invoice');
mustIgnore('a failure notice',              [], 'a@b.com', 'Failure notice');

echo "\nAutoresponders — the classic loop partner\n";
mustIgnore('Auto-Submitted: auto-replied',  ['Auto-Submitted' => 'auto-replied'], 'person@x.com');
mustIgnore('Auto-Submitted: auto-generated',['Auto-Submitted' => 'auto-generated'], 'person@x.com');
mustIgnore('X-Auto-Response-Suppress',      ['X-Auto-Response-Suppress' => 'All'], 'person@x.com');
mustIgnore('an Out of Office subject',      [], 'person@x.com', 'Out of Office: Re: your quote');
mustIgnore('Automatic reply',               [], 'person@x.com', 'Automatic reply: Invoice');
mustIgnore('and one hidden behind Re:',     [], 'person@x.com', 'Re: Re: Out of office');
mustIgnore('a German out-of-office',        [], 'person@x.com', 'Abwesenheitsnotiz: Urlaub');
mustIgnore('a read receipt',                [], 'person@x.com', 'Read: Your invoice');

echo "\nBulk mail and mailing lists\n";
mustIgnore('a List-Id',            ['List-Id' => '<news.example.com>'], 'news@example.com');
mustIgnore('a List-Unsubscribe',   ['List-Unsubscribe' => '<https://x/u>'], 'a@b.com');
mustIgnore('Precedence: bulk',     ['Precedence' => 'bulk'], 'a@b.com');
mustIgnore('a Mailchimp campaign', ['X-Mailchimp-Id' => 'abc'], 'a@b.com');

echo "\nSenders that do not want a reply\n";
mustIgnore('no-reply@',      [], 'no-reply@bank.co.ug');
mustIgnore('noreply@',       [], 'noreply@bank.co.ug');
mustIgnore('donotreply@',    [], 'donotreply@bank.co.ug');
mustIgnore('MAILER-DAEMON@', [], 'MAILER-DAEMON@relay.example');
mustIgnore('postmaster@',    [], 'postmaster@relay.example');
mustIgnore('a suffixed bounce address', [], 'bounces+abc123@sendgrid.net');
mustIgnore('a suffixed no-reply',       [], 'no-reply-4821@service.com');

echo "\nReal customers must still get through — the other failure\n";
mustAnswer('an ordinary customer question', [], 'felix.orech@gmail.com', 'How much is Starlink Mini?');
mustAnswer('a reply on a thread',           ['In-Reply-To' => '<abc@x>'], 'felix@company.co.ug', 'Re: Quotation Q-2026-0141');
mustAnswer('Auto-Submitted: no is a human', ['Auto-Submitted' => 'no'], 'felix@x.com', 'Question');
mustAnswer('a normal Return-Path',          ['Return-Path' => '<felix@x.com>'], 'felix@x.com');
mustAnswer('a name containing a robot word',[], 'antonio@example.com', 'Pricing');
mustAnswer('someone at a company called Newsroom', [], 'james@newsroom.co.ug', 'Internet for our office');
mustAnswer('a customer asking ABOUT an out-of-office', [], 'felix@x.com', 'My out of office is not working');
mustAnswer('a customer whose surname is Post', [], 'mary.postman@x.com', 'Upgrade please');

echo "\nA message we could not reply to anyway\n";
mustIgnore('no sender at all',        [], '');
mustIgnore('a malformed sender',      [], 'not-an-address');

echo "\nThe rate limit, for when a rule above misses\n";
$r = InboundMailFilter::rateLimit(0);
!$r['ignore'] ? ok('a first reply is allowed') : bad('a first reply was blocked');
$r = InboundMailFilter::rateLimit(2);
!$r['ignore'] ? ok('a third reply is still allowed') : bad('a third reply was blocked');
$r = InboundMailFilter::rateLimit(3);
$r['ignore'] ? ok("a fourth stops  ({$r['reason']})") : bad('a fourth reply was NOT blocked');
$r = InboundMailFilter::rateLimit(99);
$r['ignore'] ? ok('and so does a hundredth') : bad('a runaway was not blocked');

echo "\nAddress parsing\n";
InboundMailFilter::addressOf('Felix Orech <felix@x.com>') === 'felix@x.com'
    ? ok('a display name is stripped') : bad('display name not stripped');
InboundMailFilter::addressOf('felix@x.com') === 'felix@x.com'
    ? ok('a bare address is left alone') : bad('bare address mangled');

echo "\nSupplier mail is not customer mail\n";
// Starlink writes to the customer mailbox too. Read as an enquiry, an order
// confirmation becomes a drafted reply to our own supplier — and there is
// already a pipeline that reads these properly, pulling out the kit and the
// order reference and filing them against a customer.
foreach (['no-reply@starlink.com', 'orders@email.starlink.com', 'billing@spacex.com'] as $a) {
    mustIgnore('supplier ' . $a, [], $a, 'Your Starlink order has shipped');
}
$sup = ignored([], 'no-reply@starlink.com', 'Your Starlink order has shipped');
strpos((string)$sup['reason'], 'starlink_mail pipeline') !== false
    ? ok('the reason points at the pipeline that does handle it')
    : bad('the reason should name the starlink_mail pipeline', (string)$sup['reason']);

echo "\nBut only the real supplier domains\n";
InboundMailFilter::isSupplier('felix@notstarlink.com') === false
    ? ok('a domain that merely contains the name is a customer')
    : bad('notstarlink.com must not count as the supplier');
InboundMailFilter::isSupplier('a@starlink.com.attacker.net') === false
    ? ok('a lookalike suffix is not a subdomain')
    : bad('starlink.com.attacker.net must not count as the supplier');
InboundMailFilter::isSupplier('orders@email.starlink.com') === true
    ? ok('while a real subdomain is')
    : bad('email.starlink.com should be recognised');
mustAnswer('a customer writing ABOUT Starlink is still a customer',
    [], 'customer@gmail.com', 'I want Starlink');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
