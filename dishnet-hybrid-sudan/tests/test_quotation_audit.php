<?php
declare(strict_types=1);
/**
 * test_quotation_audit.php — a quotation email leaves a record of itself.
 *
 * The webhook path — every quote typed into uCRM's own screen — sent the
 * customer a quotation and remembered it only as a QEMAIL row in
 * notification_dedup, a table whose job is deduplication. Nobody could say
 * afterwards who it went to, from which address, or whether it arrived. The
 * app path writes quote_mail.log; the webhook path wrote nothing.
 *
 * Now the webhook hands the dispatcher the quote id as its dedupe key, so the
 * send lands in customer_email_log like every other lifecycle email — with a
 * sender column added, because "who sent it" belongs on the audit row and not
 * only in the relay's log. And because a 'sent' row there blocks a resend by
 * design, the resend tool has to clear it alongside the claim it already
 * cleared, or its promise "the webhook may send again" becomes false.
 */
$pass = 0; $fail = 0;
function is_(bool $c, string $m): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m\n"; } }
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }

$root = dirname(__DIR__);
if (!getenv('DN_VAULT_FILE')) putenv('DN_VAULT_FILE=' . tempnam(sys_get_temp_dir(), 'dn-vault-'));
require_once $root . '/lib/CustomerEmailDispatcher.php';

$tmp = sys_get_temp_dir() . '/qaudit_' . getmypid();
exec('rm -rf ' . escapeshellarg($tmp)); @mkdir($tmp, 0777, true);
// effectiveConfig() overlays the files in $GLOBALS['dataDir']; with none
// there, the caller's switches stand.
$GLOBALS['dataDir'] = $tmp;

// A mailer that is configured — so the dispatcher gets past its checks and
// into claim/send/settle — and a port that is definitely closed, so the
// send fails fast and honestly. The audit row must exist either way.
$sock = stream_socket_server('tcp://127.0.0.1:0');
$closed = (int)parse_url(stream_socket_get_name($sock, false), PHP_URL_PORT);
fclose($sock);
file_put_contents($tmp . '/email_settings.json', json_encode([
    'smtp_host' => '127.0.0.1', 'smtp_port' => $closed, 'smtp_user' => 'relay-user',
    'smtp_pass' => 'p', 'smtp_enc' => 'none', 'smtp_from' => 'accounts@dishnetuganda.com',
]));

$on  = ['customer_emails_enabled' => 1, 'customer_email_quotation' => 1];
$pdo = new PDO('sqlite:' . $tmp . '/plugin.sqlite3');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ════════════════════════════════════════════════════════════════════════
echo "\n1. A quotation send is recorded — even one that failed\n";
// ════════════════════════════════════════════════════════════════════════
$d = new CustomerEmailDispatcher($tmp, $on, null, $pdo);
$r = $d->send('quotation', 'billing@customer.test', 'Secure Solutions',
              ['quote_number' => '000016', 'total' => 2749000, 'amount' => 2749000], '16');
t('the send did not succeed (nothing is listening)', $r['sent'], false);
is_(strpos($r['reason'], 'TCP connect failed') === 0, 'for the honest reason: ' . $r['reason']);

$row = $pdo->query("SELECT * FROM customer_email_log WHERE dedupe_key = 'quotation:16'")->fetch(PDO::FETCH_ASSOC);
is_(is_array($row), 'a customer_email_log row exists for quotation:16');
t('template',   $row['template']  ?? null, 'quotation');
t('recipient',  $row['recipient'] ?? null, 'billing@customer.test');
t('status',     $row['status']    ?? null, 'failed');
is_(($row['error'] ?? '') !== '', 'the failure reason is on the row');
t('sender — the address the header would have carried', $row['sender'] ?? null, 'accounts@dishnetuganda.com');
is_(preg_match('/^\d{4}-\d{2}-\d{2} /', (string)($row['created_at'] ?? '')) === 1, 'and a timestamp');

echo "\n2. A failure does not block a retry; a success would\n";
$r2 = $d->send('quotation', 'billing@customer.test', 'Secure Solutions', ['quote_number' => '000016'], '16');
is_($r2['reason'] !== 'already sent', 'after a failure the same quotation may be sent again');
$pdo->exec("UPDATE customer_email_log SET status = 'sent' WHERE dedupe_key = 'quotation:16'");
$r3 = $d->send('quotation', 'billing@customer.test', 'Secure Solutions', ['quote_number' => '000016'], '16');
t('after a success it is refused as a repeat', $r3['reason'], 'already sent');

// ════════════════════════════════════════════════════════════════════════
echo "\n3. forget() makes a deliberate resend possible again\n";
// ════════════════════════════════════════════════════════════════════════
t('forgetting a row that exists reports true',  CustomerEmailDispatcher::forget($pdo, 'quotation', '16'), true);
t('the row is gone', (int)$pdo->query("SELECT COUNT(*) FROM customer_email_log WHERE dedupe_key = 'quotation:16'")->fetchColumn(), 0);
t('forgetting it again reports false',          CustomerEmailDispatcher::forget($pdo, 'quotation', '16'), false);
$r4 = $d->send('quotation', 'billing@customer.test', 'Secure Solutions', ['quote_number' => '000016'], '16');
is_($r4['reason'] !== 'already sent', 'and the quotation may be sent again');
t('forgetting one quotation does not touch another',
  CustomerEmailDispatcher::forget($pdo, 'quotation', '99'), false);

// ════════════════════════════════════════════════════════════════════════
echo "\n4. A table created before the sender column gains it, additively\n";
// ════════════════════════════════════════════════════════════════════════
// In a SUBPROCESS, deliberately. table() guards itself with a static — one
// check per process, which is right for production where a process holds one
// database — so after section 1 ran against the fresh database, a second
// dispatcher in this process would skip the check entirely and the ALTER
// would never be reached. That is not a defect in the upgrade; it is the
// reason the upgrade has to be exercised the way production exercises it:
// first use, new process.
$old = new PDO('sqlite:' . $tmp . '/old.sqlite3');
$old->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$old->exec("CREATE TABLE customer_email_log (
    dedupe_key TEXT PRIMARY KEY, template TEXT NOT NULL, recipient TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'claimed', error TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT (datetime('now')))");
$old->exec("INSERT INTO customer_email_log (dedupe_key, template, recipient, status) VALUES ('invoice:INV-1','invoice','a@b.test','sent')");
$cols = fn(PDO $p) => array_column($p->query('PRAGMA table_info(customer_email_log)')->fetchAll(PDO::FETCH_ASSOC), 'name');
is_(!in_array('sender', $cols($old), true), 'the old table has no sender column');
$old = null;   // release the handle before another process writes

$sub = <<<'SUB'
$root = __ROOT__; $tmp = __TMP__;
$GLOBALS['dataDir'] = $tmp;
require_once $root . '/lib/CustomerEmailDispatcher.php';
$pdo = new PDO('sqlite:' . $tmp . '/old.sqlite3');
$d = new CustomerEmailDispatcher($tmp, ['customer_emails_enabled' => 1, 'customer_email_quotation' => 1], null, $pdo);
$r = $d->send('quotation', 'c@d.test', 'C', ['quote_number' => '000017'], '17');
echo $r['reason'];
SUB;
$code = str_replace(['__ROOT__', '__TMP__'], [var_export($root, true), var_export($tmp, true)], $sub);
$out = []; exec('DN_VAULT_FILE=' . escapeshellarg((string)getenv('DN_VAULT_FILE'))
                . ' php -r ' . escapeshellarg($code) . ' 2>&1', $out);
is_(strpos(implode('', $out), 'TCP connect failed') === 0,
    'a fresh process used the old table once: ' . trim(implode(' ', $out)));

$old = new PDO('sqlite:' . $tmp . '/old.sqlite3');
$old->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
is_(in_array('sender', $cols($old), true), 'after that one use the column exists');
$kept = $old->query("SELECT status FROM customer_email_log WHERE dedupe_key = 'invoice:INV-1'")->fetchColumn();
t('and the row that was already there is untouched', $kept, 'sent');
t('the new row carries a sender',
  $old->query("SELECT sender FROM customer_email_log WHERE dedupe_key = 'quotation:17'")->fetchColumn(),
  'accounts@dishnetuganda.com');

// ════════════════════════════════════════════════════════════════════════
echo "\n5. The webhook and the resend tool are wired to this\n";
// ════════════════════════════════════════════════════════════════════════
$wh = (string)file_get_contents($root . '/webhook.php');
is_(strpos($wh, "], (string)\$quoteId, \$atts);") !== false,
    'whQuotationEmail hands the dispatcher the quote id as its dedupe key');
is_(strpos($wh, "], '', \$atts);") === false,
    'and no longer passes an empty one');
$tool = (string)file_get_contents($root . '/tools/quote_email_send.php');
is_(strpos($tool, "CustomerEmailDispatcher::forget(\$claimPdo, 'quotation', (string)\$quoteId)") !== false,
    '--clear-claim also forgets the customer_email_log row');
is_(strpos($tool, "customer_email_log has quotation:") !== false,
    'and the tool reports that row alongside the claim');

exec('rm -rf ' . escapeshellarg($tmp));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
