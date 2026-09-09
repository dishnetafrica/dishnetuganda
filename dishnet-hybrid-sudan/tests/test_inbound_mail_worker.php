<?php
/**
 * test_inbound_mail_worker.php — the whole path, on the real message.
 *
 * The Subterra email goes in at the top: read from a fake mailbox, filtered,
 * classified, matched against a fake uCRM, drafted by a fake brain, stored.
 * Nothing here can send, and one of the assertions is exactly that — the
 * worker holds no sender and offers no way to reach one.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
foreach (['EmailReplyPolicy', 'EmailIntentClassifier', 'EmailCustomerMatcher',
          'InboundMailFilter', 'EmailDraftStore', 'InboundMailWorker'] as $c) {
    require_once $root . '/lib/' . $c . '.php';
}

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

// ── Doubles ──────────────────────────────────────────────────────────────
class FakeMailbox
{
    public $emails;
    public function __construct(array $e) { $this->emails = $e; }
    public function fetch(string $since = '', int $limit = 25): array
    {
        return ['ok' => true, 'error' => '', 'emails' => $this->emails, 'newest' => '2026-09-09T09:02:00Z'];
    }
}

class FakeCrm
{
    public $queries = [];
    public function get(string $path)
    {
        $this->queries[] = $path;
        if (strpos($path, rawurlencode('felix@subterra.co.ug')) !== false
            || strpos($path, rawurlencode('@subterra.co.ug')) !== false
            || strpos($path, rawurlencode('Subterra Limited')) !== false) {
            return [[
                'id' => 4021, 'companyName' => 'Subterra Limited',
                'email' => 'accounts@subterra.co.ug',
                'contacts' => [['email' => 'felix@subterra.co.ug', 'name' => 'Felix Orech']],
            ]];
        }
        return [];
    }
}

class FakeBrain
{
    public $calls = [];
    public function isConfigured(): bool { return true; }
    public function reply(array $ctx): array
    {
        $this->calls[] = $ctx;
        if (!empty($ctx['classify_only'])) return ['reply' => 'plans_pricing'];
        return ['reply' => "Dear Felix,\n\nThank you for the purchase order."];
    }
}

$felix = [
    'message_id'  => '<CAF-felix-001@mail.gmail.com>',
    'thread_id'   => 'T-9001',
    'from'        => 'felix@subterra.co.ug',
    'from_name'   => 'Felix Orech',
    'to'          => ['accounts@dishnetuganda.com'],
    'subject'     => 'Re: Quotation 000001 — Starlink Internet for Subterra Limited',
    'received_at' => '2026-09-09T09:02:00Z',
    'attachments' => ['DISHNET PO 090926 INTERNET SERVICE.pdf'],
    'headers'     => ['message-id' => '<CAF-felix-001@mail.gmail.com>'],
    'body'        => "Hello Dishnet team,\n\nI have attached our PO for the Starlink "
                   . "Internet Service.\nPlease let us know when you will install it.\n\n"
                   . "Regards\n\nFelix\n\nOn Tue, Sep 8, 2026 at 6:29 PM accounts@dishnetuganda.com\n"
                   . "<accounts@dishnetuganda.com> wrote:\n> Dear Felix,\n> Business 1TB — UGX 1,645,440\n",
];

$config = ['email_reply_to' => 'accounts@dishnetuganda.com'];

function newWorker(array $emails, $crm, $brain, array $config): array
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $store = new EmailDraftStore($pdo);
    return [new InboundMailWorker(new FakeMailbox($emails), $store, $crm, $brain, $config), $store];
}

echo "\nThe Subterra email, all the way through\n";
$crm   = new FakeCrm();
$brain = new FakeBrain();
[$worker, $store] = newWorker([$felix], $crm, $brain, $config);
$run = $worker->run();

is_($run['ok'] === true, 'the run completes', $run['error']);
is_($run['read'] === 1 && $run['drafted'] === 1 && $run['ignored'] === 0,
    'one email read, one drafted, none ignored',
    json_encode(['read'=>$run['read'],'drafted'=>$run['drafted'],'ignored'=>$run['ignored']]));

$item = $run['items'][0];
is_($item['category'] === 'order_po', 'it is a purchase order', 'got: ' . $item['category']);
is_($item['requires_human'] === true, 'and a person must approve the reply');
is_($item['client_id'] === 4021, 'Subterra Limited is identified from the sender',
    'got client: ' . $item['client_id']);
is_($item['match'] === EmailCustomerMatcher::EXACT,
    'by an exact address match on a company contact, not a guess',
    'got: ' . $item['match']);

echo "\nEverything lands as pending, never as sent\n";
$pending = $store->listByStatus(EmailDraftStore::PENDING);
is_(count($pending) === 1, 'the draft is waiting for a human');
is_($pending[0]['status'] === EmailDraftStore::PENDING, 'with status pending');
is_((int)$pending[0]['crm_client_id'] === 4021, 'against the right account');
is_(strpos((string)$pending[0]['draft_subject'], 'Re: Quotation 000001') === 0,
    'threaded onto the same subject', 'got: ' . $pending[0]['draft_subject']);
is_(trim((string)$pending[0]['draft_body']) !== '', 'with a body for the reviewer to read');
$counts = $store->counts();
is_((int)($counts[EmailDraftStore::SENT] ?? 0) === 0, 'and nothing at all is sent',
    json_encode($counts));
is_((int)($counts[EmailDraftStore::APPROVED] ?? 0) === 0, 'nothing is pre-approved either',
    json_encode($counts));

echo "\nThe model is told not to promise what it cannot know\n";
$drafting = array_values(array_filter($brain->calls, function ($c) { return empty($c['classify_only']); }));
is_(count($drafting) === 1, 'the brain was asked for exactly one draft');
is_(isset($drafting[0]['constraint']), 'a constraint was attached to the request');
is_(strpos((string)($drafting[0]['constraint'] ?? ''), 'date') !== false,
    'naming dates specifically — the thing this customer asked for');

echo "\nOur own quoted email never reaches the model\n";
is_(strpos((string)($drafting[0]['message'] ?? ''), '1,645,440') === false,
    'our prices are not fed back in as though the customer wrote them');
is_(strpos((string)($drafting[0]['message'] ?? ''), 'attached our PO') !== false,
    'what Felix wrote is what the model reads');

echo "\nA hard signal spends no model call on classification\n";
$classifying = array_values(array_filter($brain->calls, function ($c) { return !empty($c['classify_only']); }));
is_(count($classifying) === 0, 'the PO was recognised from the words alone');

echo "\nOur own mail coming back is refused, not answered\n";
$loop = $felix;
$loop['message_id'] = '<loop-1@dishnetuganda.com>';
$loop['from']       = 'accounts@dishnetuganda.com';
[$w2, $s2] = newWorker([$loop], new FakeCrm(), new FakeBrain(), $config);
$r2 = $w2->run();
is_($r2['ignored'] === 1 && $r2['drafted'] === 0, 'a message from our own address is ignored');
is_(count($s2->listByStatus(EmailDraftStore::IGNORED)) === 1,
    'and is still recorded, so the filter is not a place mail disappears');

echo "\nRe-reading the mailbox costs nothing\n";
[$w3, $s3] = newWorker([$felix, $felix], new FakeCrm(), new FakeBrain(), $config);
$r3 = $w3->run();
is_($r3['drafted'] === 1, 'the same message twice produces one draft',
    'drafted: ' . $r3['drafted']);
is_(count($s3->listByStatus(EmailDraftStore::PENDING)) === 1, 'and one row');

echo "\nWith no brain configured, the work still happens safely\n";
class NoBrain { public function isConfigured(): bool { return false; }
                public function reply(array $c): array { return ['reply' => 'should never run']; } }
[$w4, $s4] = newWorker([$felix], new FakeCrm(), new NoBrain(), $config);
$r4 = $w4->run();
is_($r4['drafted'] === 1, 'the email is still read, classified and filed');
$p4 = $s4->listByStatus(EmailDraftStore::PENDING)[0];
is_((string)$p4['category'] === 'order_po', 'still correctly categorised without any model');
is_(trim((string)$p4['draft_body']) === '', 'with an empty draft rather than an invented one');

echo "\nThe worker cannot send, by construction\n";
$src = (string)file_get_contents($root . '/lib/InboundMailWorker.php');
is_(strpos($src, 'MailService') === false, 'it does not name the mailer');
is_(preg_match('/->\s*send\s*\(/', $src) === 0, 'it calls nothing named send()');
is_(strpos($src, EmailDraftStore::class . '::SENT') === false
    && strpos($src, "'sent'") === false, 'and never writes a sent status');

echo "\nThe mailbox password cannot be written to disk in the clear\n";
require_once $root . '/lib/PluginConfig.php';
is_(in_array('email_ai_mailbox_pw', PluginConfig::SECRET_KEYS, true),
    'it is a secret, so saveOverrides refuses it');
$r = PluginConfig::saveOverrides(sys_get_temp_dir(), ['email_ai_mailbox_pw' => 'hunter2']);
is_(($r[0] ?? null) === false, 'and an attempt to save it fails', json_encode($r));
is_(strpos((string)($r[1] ?? ''), 'Configuration screen') !== false,
    'pointing at the encrypted route instead', (string)($r[1] ?? ''));
$setter = (string)file_get_contents($root . '/tools/set_inbound_mail.php');
is_(strpos($setter, 'stty') === false,
    'and the setter no longer offers to read a password at all');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
