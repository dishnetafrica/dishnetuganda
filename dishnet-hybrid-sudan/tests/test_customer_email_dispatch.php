<?php
/**
 * test_customer_email_dispatch.php — the switches, the dedupe and the refusal
 * to throw.
 *
 * These emails fire from webhooks that have already recorded a payment or
 * created an invoice. Three properties have to hold or wiring them was a bad
 * idea: nothing sends unless somebody turned it on, nothing sends twice when
 * a webhook retries, and nothing here can break the webhook it rides on.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/lib/CustomerEmailDispatcher.php';

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

echo "\nNothing sends unless both switches are on\n";
is_(!CustomerEmailDispatcher::masterEnabled([]),
    'the master switch is off when nothing is configured');
is_(!CustomerEmailDispatcher::enabled('invoice', []),
    'an event is off when nothing is configured');
is_(!CustomerEmailDispatcher::enabled('invoice', ['customer_email_invoice' => 1]),
    'an event alone is not enough — the master still gates it');
is_(!CustomerEmailDispatcher::enabled('invoice', ['customer_emails_enabled' => 1]),
    'the master alone is not enough — the event must be named');
is_(CustomerEmailDispatcher::enabled('invoice',
      ['customer_emails_enabled' => 1, 'customer_email_invoice' => 1]),
    'both together send');
is_(!CustomerEmailDispatcher::enabled('welcome',
      ['customer_emails_enabled' => 1, 'customer_email_invoice' => 1]),
    'and turning one event on does not turn its neighbours on');

echo "\nThe two templates with no switch are not listed as switchable\n";
$states = CustomerEmailDispatcher::states(['customer_emails_enabled' => 1]);
is_(!isset($states['quotation']), 'quotation is not switched here (QuotationService owns it)');
is_(!isset($states['login_code']), 'login_code is not switched here (the portal owns it)');
is_(count($states) === 7, 'seven events are switchable', 'got ' . count($states));

echo "\nA send that is switched off does nothing at all\n";
$tmp = sys_get_temp_dir() . '/ced_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);
$d = new CustomerEmailDispatcher($tmp, []);
$r = $d->send('invoice', 'someone@example.com', 'Someone', ['invoice_number' => 'INV-1']);
is_($r['sent'] === false && $r['reason'] === 'switched off',
    'it reports the refusal rather than pretending to send');

echo "\nIt never throws, whatever it is handed\n";
$on = ['customer_emails_enabled' => 1, 'customer_email_invoice' => 1,
       'customer_email_welcome' => 1];
$d2 = new CustomerEmailDispatcher($tmp, $on);
foreach ([
    ['a template that does not exist', 'no_such_template', 'x@y.z'],
    ['an empty address',               'invoice',          ''],
    ['a malformed address',            'invoice',          'not-an-email'],
    ['an array with no client id',     'invoice',          []],
    ['a client id with no CRM behind it', 'invoice',       ['client_id' => 42]],
] as [$what, $key, $to]) {
    try {
        $res = $d2->send($key, $to, 'Name', []);
        is_(is_array($res) && $res['sent'] === false,
            "{$what}: returns a result instead of throwing");
    } catch (\Throwable $e) {
        bad("{$what}: threw " . get_class($e), $e->getMessage());
    }
}

echo "\nThe same event is not sent twice\n";
// A real PDO, so the dedupe table is exercised rather than skipped.
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$d3 = new CustomerEmailDispatcher($tmp, $on, null, $pdo);
// Mail is unconfigured here, so the send stops at that check — but the dedupe
// key is claimed first, which is exactly the ordering under test.
$first  = $d3->send('invoice', 'a@b.co', 'A', [], 'INV-777');
is_($first['reason'] !== 'already sent', 'the first attempt is not treated as a repeat');
is_($first['reason'] === 'plugin mail is not configured',
    'it stops at the mail check, and claims no dedupe key it cannot use',
    'reason was: ' . $first['reason']);
$rows = (int)$pdo->query("SELECT COUNT(*) FROM customer_email_log")->fetchColumn();
is_($rows === 0, 'an unsendable event leaves no row behind to block the retry', "rows={$rows}");

// Now set the states directly, which is the only honest way to test the
// read side without a live SMTP server.
$ins = $pdo->prepare('INSERT OR REPLACE INTO customer_email_log
                      (dedupe_key, template, recipient, status, created_at)
                      VALUES (?,?,?,?,?)');

$ins->execute(['invoice:INV-A', 'invoice', 'a@b.co', 'sent',   gmdate('Y-m-d H:i:s')]);
$r = $d3->send('invoice', 'a@b.co', 'A', [], 'INV-A');
is_($r['reason'] === 'already sent', 'a SENT event is never sent again', 'reason: ' . $r['reason']);

$ins->execute(['invoice:INV-B', 'invoice', 'a@b.co', 'failed', gmdate('Y-m-d H:i:s')]);
$r = $d3->send('invoice', 'a@b.co', 'A', [], 'INV-B');
is_($r['reason'] !== 'already sent',
    'a FAILED event may be retried — one bad SMTP minute must not cost a receipt');

$ins->execute(['invoice:INV-C', 'invoice', 'a@b.co', 'claimed', gmdate('Y-m-d H:i:s')]);
$r = $d3->send('invoice', 'a@b.co', 'A', [], 'INV-C');
is_($r['reason'] === 'already sent',
    'a send still in flight blocks a webhook retry racing it');

$ins->execute(['invoice:INV-D', 'invoice', 'a@b.co', 'claimed', gmdate('Y-m-d H:i:s', time() - 7200)]);
$r = $d3->send('invoice', 'a@b.co', 'A', [], 'INV-D');
is_($r['reason'] !== 'already sent',
    'a claim abandoned hours ago is treated as a crash, not a permanent block');

is_($d3->send('invoice', 'a@b.co', 'A', [])['reason'] !== 'already sent',
    'with no dedupe key given, nothing is ever considered a repeat');

echo "\nThe wiring is where the events actually happen\n";
$wh = (string)file_get_contents($root . '/webhook.php');
foreach ([
    'invoice'           => 'invoice.add',
    'payment_received'  => 'payment.add',
    'welcome'           => 'service.add',
    'service_paused'    => 'service.suspend',
    'service_resumed'   => 'service.activate',
    'support_received'  => 'ticket.add',
    'install_scheduled' => 'job.add',
] as $key => $event) {
    is_(strpos($wh, "whCustomerEmail('{$key}'") !== false,
        "{$key} is wired ({$event})");
}
is_(substr_count($wh, 'whCustomerEmail(') === 8,
    'seven call sites and one definition, no strays',
    'found ' . substr_count($wh, 'whCustomerEmail('));

@array_map('unlink', glob($tmp . '/*') ?: []); @rmdir($tmp);
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
