<?php
declare(strict_types=1);
/**
 * test_receipts_once.php — 5.18.54, docs/46 rows 2-4 (D-2, D3a, D3b, D3c).
 *
 * One WhatsApp receipt per payment, whoever sends it first; and the e-mail, the receipt PDF and the delivery note are
 * not lost when somebody else sent the WhatsApp.
 *
 *   - the retailer app receipts a payment, then payment.add arrives (D-2): one WhatsApp, and the e-mail and the PDF
 *     still go;
 *   - payment.add first, then the app: the app sends nothing;
 *   - a collection receipted before uCRM had it (COL-…), posted later by the retry job (D3b): one WhatsApp;
 *   - a staff collection that sent the text and queued the PDF (D3c): payment.add sends the e-mail, queues no PDF;
 *   - payment.add twice: the second does nothing;
 *   - South Sudan: 5.18.53 unchanged (the app's note file, and a second receipt from the webhook);
 *   - weakened copies, each caught.
 *
 * The app's and the staff collections' claims are made with the very calls those files make (ReceiptOnce, and the
 * NotificationService they send with); a static check below pins that the files make them in that order.
 */
$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d !== '' ? "\n       $d" : '') . "\n"; } }

$root = dirname(__DIR__);
if (!getenv('DN_VAULT_FILE')) putenv('DN_VAULT_FILE=' . tempnam(sys_get_temp_dir(), 'dn-vault-'));
require_once __DIR__ . '/fixtures/notify_harness.php';
/** Each tenant's zone, named here: a test file may pin one, a fixture may not (tests/test_timezone.php). */
function nh_zone(string $tenant): string { return $tenant === 'uganda' ? 'Africa/Kampala' : 'Africa/Juba'; }

const REF_APP = 'PWA-12-7-20260930101010';
const REF_COL = 'PWA-12-7-20260930111111';

$payment = function (int $id, string $ref) {
    return ['id' => $id, 'clientId' => 7, 'amount' => 90000.0, 'currencyCode' => 'UGX',
            'methodId' => 'd8c1eae9-d41d-479f-aeaf-38497975d7b3', 'methodName' => 'Mobile Money',
            'note' => "Collected by Test Agent via DishNet PWA | Ref: {$ref}", 'invoiceIds' => []];
};
$seed = [
    'clients'  => ['7' => ['id' => 7, 'firstName' => 'Test', 'lastName' => 'Payer', 'isLead' => false,
                           'contacts' => [['phone' => '256700000007', 'email' => 'payer@example.test', 'isBilling' => true]]]],
    'payments' => ['9101' => $payment(9101, REF_APP), '9102' => $payment(9102, REF_COL), '9103' => $payment(9103, 'PAY-3-7-20260930121212'),
                   '9104' => $payment(9104, 'PWA-12-7-20260930131313')],
    'invoices' => [],
];
$emailCfg = ['customer_emails_enabled' => '1', 'customer_email_payment_received' => '1',
             'email_company_name' => 'DishNet Test Ltd', 'email_reply_to' => 'accounts@example.test'];

/** What the app / a staff collection does, run from the plugin tree under test (tests/fixtures/notify_side.php). */
$side = function (string $pluginRoot, NotifyHarness $h, string $op, array $args): string {
    $cmd = 'php ' . escapeshellarg(__DIR__ . '/fixtures/notify_side.php') . ' ' . escapeshellarg($pluginRoot) . ' '
         . escapeshellarg($h->dataDir) . ' ' . escapeshellarg($op);
    foreach ($args as $a) $cmd .= ' ' . escapeshellarg((string)$a);
    return trim((string)shell_exec($cmd . ' 2>/dev/null'));
};
$receipts = fn(array $texts) => count(array_filter($texts, fn($t) => strpos($t['text'], 'Payment Received') !== false));
$queued = function (NotifyHarness $h, int $pid): int {
    $q = json_decode((string)@file_get_contents($h->dataDir . '/receipt_pdf_queue.json'), true);
    if (!is_array($q)) {
        // SqliteStore keeps *.json documents in the database; read it through the store.
        require_once dirname(__DIR__) . '/lib/StoreInterface.php';
        require_once dirname(__DIR__) . '/lib/SqliteStore.php';
        $s = SqliteStore::create($h->dataDir);
        $q = $s->load('receipt_pdf_queue.json') ?? [];
    }
    return count(array_filter((array)$q, fn($r) => (int)($r['payment_id'] ?? 0) === $pid));
};

$scenarios = function (string $pluginRoot, string $tenant) use ($seed, $emailCfg, $side, $receipts, $queued): array {
    $out = [];
    // A. the app first, then payment.add
    $h = NotifyHarness::start($pluginRoot, $tenant, $emailCfg + ['timezone' => nh_zone($tenant)], 'rcpt'); $h->withSmtp(); $h->seedCrm($seed);
    $side($pluginRoot, $h, 'app', [9101, REF_APP, 'CRM-PAY-9101']);
    $h->fire('payment.add', 'payment', 9101); $h->settle(2.0);
    $out['A'] = ['texts' => $receipts($h->evoTexts()), 'mails' => count($h->smtpMessages()), 'pdf' => $queued($h, 9101)];
    // B. payment.add twice, then the app
    $h->fire('payment.add', 'payment', 9104); $h->settle(1.5);
    $h->fire('payment.add', 'payment', 9104); $h->settle(1.5);
    $appSent = $side($pluginRoot, $h, 'app', [9104, 'PWA-12-7-20260930131313', 'CRM-PAY-9104']) === 'sent';
    $out['B'] = ['app' => $appSent, 'texts' => $receipts($h->evoTexts()) - $out['A']['texts'],
                 'mails' => count($h->smtpMessages()) - $out['A']['mails'], 'pdf' => $queued($h, 9104),
                 'log' => substr_count($h->webhookLog(), 'Payment notification SKIPPED')];
    $h->stop();

    // C. a collection receipted before uCRM had it, posted later
    $h = NotifyHarness::start($pluginRoot, $tenant, $emailCfg + ['timezone' => nh_zone($tenant)], 'rcpt'); $h->withSmtp(); $h->seedCrm($seed);
    $side($pluginRoot, $h, 'app', [0, REF_COL, 'COL-55']);
    $h->fire('payment.add', 'payment', 9102); $h->settle(2.0);
    $out['C'] = ['texts' => $receipts($h->evoTexts()), 'mails' => count($h->smtpMessages()), 'pdf' => $queued($h, 9102)];

    // D. a staff collection that sent the text and queued the PDF, which the cron has already sent
    $before = $receipts($h->evoTexts()); $mailsBefore = count($h->smtpMessages());
    $side($pluginRoot, $h, 'staff', [9103, 'PAY-3-7-20260930121212']);
    $h->fire('payment.add', 'payment', 9103); $h->settle(2.0);
    $out['D'] = ['texts' => $receipts($h->evoTexts()) - $before, 'mails' => count($h->smtpMessages()) - $mailsBefore,
                 'pdf' => $queued($h, 9103), 'stderr' => $h->stderr()];
    $h->stop();
    return $out;
};

// ── 1. Uganda ─────────────────────────────────────────────────────────────────
echo "\n1. Uganda: one WhatsApp receipt per payment; the e-mail and the PDF are not lost\n";
$u = $scenarios($root, 'uganda');
is_($u['A']['texts'] === 1, 'A. the app receipts, then payment.add: one WhatsApp receipt, not two (D-2)', (string)$u['A']['texts']);
is_($u['A']['mails'] === 1, 'A. the e-mail receipt still goes, once', (string)$u['A']['mails']);
is_($u['A']['pdf'] === 1, 'A. the receipt PDF is queued, once', (string)$u['A']['pdf']);
is_($u['B']['texts'] === 1 && $u['B']['app'] === false, 'B. payment.add first: one WhatsApp, and the app sends nothing after it');
is_($u['B']['mails'] === 1 && $u['B']['pdf'] === 1 && $u['B']['log'] === 1,
    'B. payment.add twice: the second sends nothing — no second e-mail, no second PDF', json_encode($u['B']));
is_($u['C']['texts'] === 1, 'C. a collection receipted before uCRM had it, posted later: one WhatsApp (D3b)', (string)$u['C']['texts']);
is_($u['C']['mails'] === 1 && $u['C']['pdf'] === 1, 'C. and its e-mail and PDF go once, when uCRM has the payment');
is_($u['D']['texts'] === 1, 'D. a staff collection sent the WhatsApp: payment.add sends no second', (string)$u['D']['texts']);
is_($u['D']['mails'] === 1, 'D. but the e-mail still goes (D3c)', (string)$u['D']['mails']);
is_($u['D']['pdf'] === 0, 'D. and no second PDF, although the first has already left the queue', (string)$u['D']['pdf']);
is_(stripos($u['D']['stderr'], 'Uncaught') === false && stripos($u['D']['stderr'], 'Fatal') === false, 'no uncaught error');

// ── 2. South Sudan ────────────────────────────────────────────────────────────
echo "\n2. South Sudan: 5.18.53 unchanged (docs/46 §E)\n";
$s = $scenarios($root, 'south-sudan');
is_($s['A']['texts'] === 2, 'A. the app\'s receipt, then the webhook\'s: two, as before', (string)$s['A']['texts']);
is_($s['C']['texts'] === 2, 'C. the collection\'s receipt, then the webhook\'s: two, as before', (string)$s['C']['texts']);
is_($s['D']['texts'] === 1 && $s['D']['mails'] === 0, 'D. a staff collection first: the webhook skips the e-mail too, as before', json_encode($s['D']));

// ── 3. The files make the calls this test makes ───────────────────────────────
echo "\n3. The app and the staff collections claim before they send\n";
$src = (string)file_get_contents($root . '/includes/api/api_retailer.php');
$i = strpos($src, 'ReceiptOnce::claimCollection($notify,');
$j = $i === false ? false : strpos($src, '$notify->paymentReceived(', $i);
is_($i !== false && $j !== false && $j - $i < 400, 'the retailer app claims (ReceiptOnce) right before it sends, on Uganda');
foreach (['includes/post/post_field.php', 'includes/post/post_sales.php'] as $f) {
    $s2 = (string)file_get_contents($root . '/' . $f);
    is_(strpos($s2, 'ReceiptOnce::pdfKey(') !== false && strpos($s2, 'NotifyGate::RECEIPT_ONCE') !== false,
        "{$f}: claims the PDF when it queues one, on Uganda");
}
is_(strpos((string)file_get_contents($root . '/includes/post/post_field.php'), 'ReceiptOnce::refKey(') !== false,
    'the field collection claims its reference as well');

// ── 4. Weakened copies ────────────────────────────────────────────────────────
echo "\n4. Weakened copies, each caught\n";
$mut = [
    'payment.add always sends the WhatsApp' => ['webhook.php' => [['$_rcptSendWa  = $_rcptWaFirst && !$_rcptByRef;', '$_rcptSendWa  = true;']]],
    'the reference is not honoured'          => ['webhook.php' => [["\$_rcptByRef   = \$_rcptRef !== '' && \$notify->dedupCheck(ReceiptOnce::refKey(\$_rcptRef));", '$_rcptByRef   = false;']]],
    'the work shares the text\'s guard (5.18.53)' => ['webhook.php' => [['!$notify->dedupMark(ReceiptOnce::workKey((int)$paymentId))', '!$_rcptWaFirst']]],
    'the PDF has no guard of its own'        => ['webhook.php' => [['if ($_rcptUg && !$_alreadyQueued && !$notify->dedupMark(ReceiptOnce::pdfKey((int)$paymentId))) $_alreadyQueued = true;', '']]],
    'the app claims nothing'                 => ['lib/ReceiptOnce.php' => [['        return $send;' . "\n    }", '        return true;' . "\n    }"]]],
];
foreach ($mut as $name => $patch) {
    $m = $scenarios(NotifyHarness::weakened($root, $patch, 'rcpt_wk'), 'uganda');
    $ok = $m['A']['texts'] === 1 && $m['A']['mails'] === 1 && $m['A']['pdf'] === 1
       && $m['B']['texts'] === 1 && $m['B']['app'] === false
       && $m['C']['texts'] === 1 && $m['D']['texts'] === 1 && $m['D']['mails'] === 1 && $m['D']['pdf'] === 0;
    is_(!$ok, "caught: {$name}");
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
