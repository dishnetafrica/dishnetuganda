<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * quote_email_send.php — send (or rehearse) the quotation email for one quote,
 * printing every decision on the way.
 *
 *   php tools/quote_email_send.php --quote 5 --dry-run
 *   php tools/quote_email_send.php --quote 5
 *   php tools/quote_email_send.php --quote 5 --to someone@else.com
 *
 * The webhook path answers 200 and moves on; whatever it decided is only
 * visible afterwards in a log, and a decision not to send is not an error, so
 * it can be quiet. This runs the same steps in the foreground and says what
 * each one found — which switch, which address, which PDF, which SMTP answer.
 *
 * --dry-run does everything except hand the message to SMTP.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/CrmApiClient.php';
require_once $root . '/lib/CustomerEmailDispatcher.php';
require_once $root . '/lib/CustomerEmails.php';
require_once $root . '/lib/EmailTemplate.php';
require_once $root . '/lib/MailService.php';
require_once $root . '/lib/QuotePdfSource.php';

$opt     = getopt('', ['quote:', 'to:', 'dry-run', 'force', 'clear-claim']);
$quoteId = (int)($opt['quote'] ?? 0);
$dry     = isset($opt['dry-run']);
$force   = isset($opt['force']);
$toOverride = trim((string)($opt['to'] ?? ''));

if ($quoteId <= 0) { fwrite(STDERR, "Usage: php tools/quote_email_send.php --quote <id> [--dry-run] [--to addr] [--force]\n"); exit(1); }

$dataDir = cliDataDir($root);
$GLOBALS['dataDir'] = $dataDir;
$config  = PluginConfig::load($root, $dataDir);

function step(string $m): void { echo "  ->   {$m}\n"; }
function ok(string $m): void   { echo "  ok   {$m}\n"; }
function no(string $m): void   { echo "  FAIL {$m}\n"; }
function wr(string $m): void   { echo "  warn {$m}\n"; }
function line(): void { echo str_repeat('-', 66) . "\n"; }

line();
echo "Quotation email for quote #{$quoteId}" . ($dry ? '  (DRY RUN)' : '') . "\n";
line();

// 1. Switches — read the way the dispatcher reads them, from disk.
$eff = CustomerEmailDispatcher::effectiveConfig($config);
$on  = CustomerEmailDispatcher::enabled('quotation', $config);
step('master switch:            ' . (!empty($eff['customer_emails_enabled']) ? 'on' : 'OFF'));
step('customer_email_quotation: ' . (!empty($eff['customer_email_quotation']) ? 'on' : 'OFF'));
if (!$on && !$force) {
    no('a switch is off — nothing would be sent. Use --force to test anyway.');
    exit(1);
}

// 1b. The once-only claim. The webhook takes it before doing the work so two
//     paths cannot both send; if a run failed while holding it, the quotation
//     becomes permanently unsendable and the only symptom is silence.
require_once $root . '/lib/SqliteStore.php';
$claimPdo = null;
try { $claimPdo = SqliteStore::create($dataDir)->getPdo(); } catch (\Throwable $e) {}
if ($claimPdo) {
    $held = false;
    try {
        $st = $claimPdo->prepare('SELECT sent_at FROM notification_dedup WHERE dedup_key = ?');
        $st->execute(["QEMAIL{$quoteId}"]);
        $held = (string)($st->fetchColumn() ?: '');
    } catch (\Throwable $e) {}
    if ($held) {
        if (isset($opt['clear-claim'])) {
            CustomerEmailDispatcher::releaseClaim($claimPdo, "QEMAIL{$quoteId}");
            ok("claim QEMAIL{$quoteId} (taken {$held}) cleared — the webhook may send again");
        } else {
            no("a claim on QEMAIL{$quoteId} is held from {$held}");
            echo "       The webhook already took this quote and did not give it back, so\n";
            echo "       it will refuse to send again. This tool ignores the claim, but the\n";
            echo "       webhook will not. Clear it with --clear-claim.\n";
        }
    } else {
        step("no claim held on QEMAIL{$quoteId}");
    }
}

// 2. uCRM and the quote.
$crm = CrmApiClient::fromUcrm($root, $config);
if (!$crm || !$crm->isConfigured()) { no('no uCRM API credentials'); exit(1); }
$quote = $crm->get("billing/quotes/{$quoteId}") ?: $crm->get("quotes/{$quoteId}");
if (!is_array($quote) || !$quote) { no("quote #{$quoteId} could not be fetched"); exit(1); }
$number   = (string)($quote['number'] ?? $quoteId);
$clientId = (int)($quote['clientId'] ?? 0);
ok("quote {$number}, client #{$clientId}, "
   . (string)($quote['currencyCode'] ?? '') . ' ' . number_format((float)($quote['total'] ?? 0)));

// 3. The recipient — billing contact first, then any contact.
$client = $clientId > 0 ? ($crm->get("clients/{$clientId}") ?: []) : [];
$name   = trim((string)(($client['firstName'] ?? '') . ' ' . ($client['lastName'] ?? '')));
if ($name === '') $name = (string)($client['companyName'] ?? '');
$email  = '';
foreach (($client['contacts'] ?? []) as $c) {
    if (!empty($c['isBilling']) && !empty($c['email'])) { $email = (string)$c['email']; break; }
}
if ($email === '') {
    foreach (($client['contacts'] ?? []) as $c) {
        if (!empty($c['email'])) { $email = (string)$c['email']; break; }
    }
}
if ($toOverride !== '') { $email = $toOverride; step("recipient overridden with --to"); }

if ($email === '') {
    no("client #{$clientId} (" . ($name ?: 'no name') . ') has NO email address in uCRM');
    echo "       Contacts on record:\n";
    foreach (($client['contacts'] ?? []) as $c) {
        printf("         name=%-20s email=%-28s phone=%s\n",
            (string)($c['name'] ?? ''), (string)($c['email'] ?? '(none)'), (string)($c['phone'] ?? ''));
    }
    if (!($client['contacts'] ?? [])) echo "         (none at all)\n";
    echo "       This is why nothing was sent. Add an email to the client in uCRM.\n";
    exit(1);
}
ok("recipient: {$name} <{$email}>");

// 4. The PDF.
[$pdf, $src] = QuotePdfSource::fetch($crm, $dataDir, $config, $quoteId, $client, $quote);
if ($pdf === '') {
    no('no PDF — uCRM served none and the plugin could not render one');
} elseif ($src === 'ucrm') {
    ok('PDF: ' . strlen($pdf) . ' bytes rendered by uCRM from the quote template'
       . (isset($quote['quoteTemplateId']) ? ' (id ' . $quote['quoteTemplateId'] . ')' : '')
       . ' — this is the document you designed');
} else {
    wr('PDF: ' . strlen($pdf) . ' bytes rendered by the PLUGIN, not by uCRM.');
    echo "       The customer will NOT see your uCRM quote template. That happens\n";
    echo "       when uCRM will not serve the PDF — most often because the quote is\n";
    echo "       still a draft, since uCRM renders nothing for a draft.\n";
}

// 5. The message.
$built = CustomerEmails::quotation($config, [
    'name' => $name, 'first_name' => (string)($client['firstName'] ?? ''),
    'quote_number' => $number,
    'total' => (float)($quote['total'] ?? 0), 'amount' => (float)($quote['total'] ?? 0),
]);
ok('subject: ' . (string)$built['subject']);
step('html ' . strlen((string)$built['html']) . ' bytes, text ' . strlen((string)$built['text']) . ' bytes');

// 6. The mailer.
$mail = new MailService($dataDir);
$cfg  = $mail->getConfig();
if (!$cfg) { no('the plugin mailer is not configured (Settings -> System -> Email)'); exit(1); }
ok('mailer: ' . (string)($cfg['host'] ?? '?') . ':' . (string)($cfg['port'] ?? '?')
   . ' as ' . (string)($cfg['user'] ?? '?'));

if ($dry) { line(); echo "DRY RUN — stopping before SMTP. Rerun without --dry-run to send.\n"; exit(0); }

// 7. Send, and print every step the mailer reports.
line();
echo "Sending\n";
$atts = $pdf !== '' ? [['name' => "Quotation-{$number}.pdf", 'mime' => 'application/pdf', 'content' => $pdf]] : [];
$res  = $mail->send($email, $name, (string)$built['subject'], (string)$built['html'],
                    (string)$built['text'], ['Reply-To' => EmailTemplate::replyTo($config)], $atts);

foreach ((array)($res['log'] ?? []) as $s) {
    printf("  %-4s %-12s %s\n", !empty($s['ok']) ? 'ok' : 'FAIL',
           (string)($s['step'] ?? ''), (string)($s['msg'] ?? ''));
}
line();
if (!empty($res['ok'])) {
    echo "RESULT: sent to {$email}\n";
    exit(0);
}
echo 'RESULT: FAILED — ' . (string)($res['error'] ?? 'no reason given') . "\n";
exit(1);
