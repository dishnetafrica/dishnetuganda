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
is_(isset($states['quotation']),
    'quotation IS switchable — the quote.add path needs a switch of its own');
is_(!isset($states['login_code']), 'login_code is not switched here (the portal owns it)');
is_(count($states) === 8, 'eight events are switchable', 'got ' . count($states));

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

echo "\nA switch set on disk is seen even when the caller's array is stale\n";
// webhook.php hydrates $config from the SqliteStore copy, which never learns
// keys written to the FILE by tools/set_customer_emails. The dispatcher must
// read the file itself or an operator turns a switch on and the webhook still
// sees it off — silently, because a switched-off event is not an error.
$cfgDir = sys_get_temp_dir() . '/ced_cfg_' . bin2hex(random_bytes(4));
@mkdir($cfgDir, 0777, true);
file_put_contents($cfgDir . '/kyc_config.json', json_encode([
    'customer_emails_enabled' => 1, 'customer_email_quotation' => 1,
]));
$probe = <<<'SUB'
$root = __ROOT__; $GLOBALS['dataDir'] = __DIR__CFG__;
require_once $root . '/lib/CustomerEmailDispatcher.php';
// A deliberately EMPTY caller array — the stale store copy.
echo CustomerEmailDispatcher::enabled('quotation', []) ? 'ON' : 'OFF';
SUB;
$code = str_replace(['__ROOT__', '__DIR__CFG__'],
                    [var_export($root, true), var_export($cfgDir, true)], $probe);
$out = [];
exec('php -r ' . escapeshellarg($code) . ' 2>&1', $out);
is_(trim(implode('', $out)) === 'ON',
    'the switch on disk wins over an empty caller array',
    'got: ' . trim(implode('', $out)));

$srcD = (string)file_get_contents($root . '/lib/CustomerEmailDispatcher.php');
is_(strpos($srcD, 'kyc_config.json') !== false,
    'the dispatcher reads the config file rather than trusting its argument');
$wh2 = (string)file_get_contents($root . '/webhook.php');
is_(strpos($wh2, 'Quotation email skipped: the quotation switch is off') !== false,
    'and a decline is logged, so a silent skip cannot happen again');

@unlink($cfgDir . '/kyc_config.json'); @rmdir($cfgDir);

echo "\nA webhook-sent email carries the install's own brand, not the defaults\n";
// The switches were fixed to read from disk; the RENDERING was not, so a
// webhook-sent quotation went out as "DishNet Africa Ltd." — the Sudan
// default — while the same email from the CLI said "DishNet Africa Limited".
$brandDir = sys_get_temp_dir() . '/ced_brand_' . bin2hex(random_bytes(4));
@mkdir($brandDir, 0777, true);
file_put_contents($brandDir . '/kyc_config.json', json_encode([
    'customer_emails_enabled' => 1, 'customer_email_quotation' => 1,
    'email_company_name' => 'DishNet Africa Limited', 'email_currency' => 'UGX',
]));
$probe = <<<'SUB'
$root = __ROOT__; $GLOBALS['dataDir'] = __CFG__;
require_once $root . '/lib/CustomerEmailDispatcher.php';
require_once $root . '/lib/CustomerEmails.php';
// An EMPTY caller array — the stale store copy the webhook actually holds.
$eff = CustomerEmailDispatcher::effectiveConfig([]);
$o = CustomerEmails::quotation($eff, ['name' => 'Secure Solutions',
        'quote_number' => '000008', 'total' => 2749000]);
echo (strpos($o['html'], 'DishNet Africa Limited') !== false ? 'BRAND_OK' : 'BRAND_STALE');
echo (strpos($o['html'], 'DishNet Africa Ltd.') === false ? ' NO_SUDAN' : ' SUDAN_LEAK');
SUB;
$code = str_replace(['__ROOT__', '__CFG__'],
                    [var_export($root, true), var_export($brandDir, true)], $probe);
$out = []; exec('php -r ' . escapeshellarg($code) . ' 2>&1', $out);
$got = trim(implode('', $out));
is_(strpos($got, 'BRAND_OK') !== false,
    'the registered company name is used, not the Sudan default', 'got: ' . $got);
is_(strpos($got, 'NO_SUDAN') !== false, 'and no Sudan default leaks through');

$srcD = (string)file_get_contents($root . '/lib/CustomerEmailDispatcher.php');
is_(strpos($srcD, 'CustomerEmails::render($key, self::effectiveConfig($this->config)') !== false,
    'render() is handed the on-disk config, not the caller\'s array');

$wh4 = (string)file_get_contents($root . '/webhook.php');
is_(strpos($wh4, '$quote, $quoteNum, (float)$amount);') !== false,
    'the webhook passes the quote it already resolved instead of re-fetching');
is_(strpos($wh4, '$crm->get("billing/quotes/{$quoteId}") ?: [];') === false,
    'and the second fetch that returned nothing is gone');

@unlink($brandDir . '/kyc_config.json'); @rmdir($brandDir);

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
