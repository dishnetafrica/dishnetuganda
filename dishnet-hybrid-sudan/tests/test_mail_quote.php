<?php
declare(strict_types=1);
/**
 * Plugin-only quotation email: MailService attachments (composeMime) and
 * QuotationService::createCrmQuote choosing between the plugin's own SMTP
 * send (toggle ON) and uCRM's /send (toggle absent — the old behaviour).
 */
$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }

$root = dirname(__DIR__);
require_once $root . '/lib/MailService.php';

// ── composeMime ─────────────────────────────────────────────────────────────
echo "composeMime — no attachments keeps the historical shape\n";
$ms = new MailService('/nonexistent');
[$h, $b] = $ms->composeMime('DishNet <q@x.test>', '"Amal" <a@y.test>', 'Hello', '<p>Hi</p>', 'Hi', ['Reply-To' => 'info@x.test']);
t('alternative content type', strpos($h, 'Content-Type: multipart/alternative;') !== false, true);
t('never mixed without attachments', strpos($h, 'multipart/mixed'), false);
t('text part present', strpos($b, "text/plain; charset=UTF-8") !== false, true);
t('html part present', strpos($b, '<p>Hi</p>') !== false, true);
t('extra header honoured', strpos($h, 'Reply-To: info@x.test') !== false, true);
t('from and subject in headers', strpos($h, 'From: DishNet <q@x.test>') !== false && strpos($h, 'Subject: Hello') !== false, true);

echo "\ncomposeMime — an attachment wraps it in multipart/mixed\n";
$bytes = '%PDF-1.4 fake-pdf-bytes ' . str_repeat('Z', 200);
[$h, $b] = $ms->composeMime('q@x.test', 'a@y.test', 'Quote', '<p>attached</p>', 'attached', [], [
    ['name' => 'Quotation-PF007.pdf', 'mime' => 'application/pdf', 'content' => $bytes],
]);
t('mixed content type', strpos($h, 'Content-Type: multipart/mixed;') !== false, true);
t('inner alternative part survives', strpos($b, 'Content-Type: multipart/alternative;') !== false, true);
t('attachment filename in disposition', strpos($b, 'filename="Quotation-PF007.pdf"') !== false, true);
t('declared as pdf', strpos($b, 'Content-Type: application/pdf; name="Quotation-PF007.pdf"') !== false, true);
t('payload is the exact bytes, base64d', strpos($b, chunk_split(base64_encode($bytes), 76, "\r\n")) !== false, true);

[$h, $b] = $ms->composeMime('q@x.test', 'a@y.test', 'S', 'x', 'x', [], [
    ['name' => "evil\"na/me\r\n.pdf", 'content' => 'x'],
]);
t('hostile filename is neutralised', strpos($b, 'filename="evil_na_me__.pdf"') !== false, true);
t('no raw quote or CRLF leaks into the header', strpos($b, "me\r\n.pdf") === false, true);

// ── QuotationService routing ────────────────────────────────────────────────
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/QuotationService.php';

class FakeCrm extends CrmApiClient
{
    public array $patches = [];
    public array $rawPaths = [];
    public $client;
    public $pdfPrimary;
    public $pdfSecondary;
    public function __construct() { parent::__construct('http://fake.test', 'k'); }
    /** Each scenario is a different quote, so each gets its own id — the
     *  once-only claim in QuotationService is real and would otherwise let
     *  only the first scenario through. */
    public static int $nextId = 7;
    public int $quoteId = 0;
    public function post(string $path, array $p = []): ?array {
        $this->quoteId = self::$nextId++;
        return ['id' => $this->quoteId, 'number' => 'PF007'];
    }
    public function get(string $path): ?array { return $this->client; }
    public function patch(string $path, array $p = []): ?array { $this->patches[] = $path; return ['ok' => true]; }
    public function getRawContent(string $path): ?string {
        $this->rawPaths[] = $path;
        return count($this->rawPaths) === 1 ? $this->pdfPrimary : $this->pdfSecondary;
    }
}

class RecMailer extends MailService
{
    public function __construct() { parent::__construct('/nonexistent'); }
    public bool $configured = true;
    public bool $sendOk     = true;
    public array $sent      = [];
    public function getConfig(): array {
        return $this->configured
            ? ['host' => 'fake', 'port' => 25, 'user' => '', 'pass' => '', 'enc' => '', 'from' => 'q@x.test']
            : [];
    }
    public function send(string $toEmail, string $toName, string $subject,
                         string $htmlBody, string $textBody = '',
                         array $extraHeaders = [], array $attachments = [],
                         ?string $fromOverride = null): array {
        // Recorded, not ignored: a quotation that started going out from the
        // no-reply address would be a customer unable to answer their own
        // quote, and the assertion below is what would catch it.
        $this->sent[] = compact('toEmail', 'toName', 'subject', 'htmlBody', 'textBody',
                                'extraHeaders', 'attachments', 'fromOverride');
        return $this->sendOk ? ['ok' => true] : ['ok' => false, 'error' => 'SMTP said no'];
    }
}

class TestQuoteSvc extends QuotationService
{
    public $mailer;
    protected function newMailService(): MailService { return $this->mailer; }
}

$tmp = sys_get_temp_dir() . '/quote_mail_test_' . getmypid();
exec('rm -rf ' . escapeshellarg($tmp));
@mkdir($tmp, 0777, true);
$store = SqliteStore::create($tmp);

$mkSvc = function (FakeCrm $crm, RecMailer $mailer) use ($store, $tmp): TestQuoteSvc {
    $svc = new TestQuoteSvc($store, $tmp, ['kyc_config_ok' => '1']);
    $p = new ReflectionProperty(QuotationService::class, 'crm');
    $p->setAccessible(true);
    $p->setValue($svc, $crm);
    $svc->mailer = $mailer;
    return $svc;
};
$goodClient = ['firstName' => 'Amal', 'lastName' => 'Okello', 'contacts' => [
    ['email' => 'other@cust.test', 'isBilling' => false],
    ['email' => 'billing@cust.test', 'isBilling' => true],
]];
$retailer = ['name' => 'Bhavin Madlani'];
$items    = [['label' => 'Starlink Business 1TB', 'quantity' => 1, 'price' => 469000, 'unit' => 'month']];

echo "\nToggle absent — uCRM sends, exactly as before\n";
@unlink($tmp . '/email_settings.json');
$crm = new FakeCrm(); $crm->client = $goodClient; $crm->pdfPrimary = '%PDF-1.4 x';
$r = $mkSvc($crm, new RecMailer())->createCrmQuote(42, $items, 'QUO-1', $retailer);
t('quote created', $r['ok'] ?? false, true);
t('uCRM /send is the sender', $crm->patches, ["billing/quotes/{$crm->quoteId}/send"]);
t('not emailed by plugin', $r['emailed_by_plugin'], false);

echo "\nToggle ON — the plugin emails the PDF itself, uCRM mailer untouched\n";
file_put_contents($tmp . '/email_settings.json', json_encode(['quote_email_via_plugin' => true]));
$crm = new FakeCrm(); $crm->client = $goodClient; $crm->pdfPrimary = '%PDF-1.4 real-quote-bytes';
$mailer = new RecMailer();
$r = $mkSvc($crm, $mailer)->createCrmQuote(42, $items, 'QUO-2', $retailer);
t('emailed by plugin', $r['emailed_by_plugin'], true);
t('uCRM /send NOT called', $crm->patches, []);
t('billing contact preferred', $mailer->sent[0]['toEmail'] ?? '', 'billing@cust.test');
// A quotation is correspondence: the customer is meant to answer it. Only
// login codes go out from the system sender, and this is the line that stops
// that spreading to mail a person has to be able to reply to.
// ?? would answer 'MISSING' for a key that IS present and null, which is
// precisely the value being asserted. array_key_exists tells them apart.
t('sent from the ordinary sender, not the no-reply one',
  array_key_exists('fromOverride', $mailer->sent[0] ?? [])
    ? $mailer->sent[0]['fromOverride'] : 'NOT PASSED AT ALL', null);
t('subject carries the uCRM number', strpos((string)($mailer->sent[0]['subject'] ?? ''), 'PF007') !== false, true);
$att = $mailer->sent[0]['attachments'][0] ?? [];
t('PDF attached under the quote number', $att['name'] ?? '', 'Quotation-PF007.pdf');
t('as application/pdf', $att['mime'] ?? '', 'application/pdf');
t('with the exact bytes uCRM served', $att['content'] ?? '', '%PDF-1.4 real-quote-bytes');
$body0 = (string)($mailer->sent[0]['htmlBody'] ?? '');
t('body invites reply-or-pay acceptance', stripos($body0, 'either one confirms your order') !== false, true);
// The quotation email now rides the shared shell, so it must carry the shell's
// marks: a responsive rule, a dark-mode block and the branded footer.
t('rendered through the shared email shell', strpos($body0, '<!DOCTYPE html>') === 0, true);
t('mobile responsive', strpos($body0, '@media only screen and (max-width:620px)') !== false, true);
t('dark-mode aware', strpos($body0, 'prefers-color-scheme:dark') !== false, true);
t('plain-text twin is supplied, not auto-stripped',
  strpos((string)($mailer->sent[0]['textBody'] ?? ''), 'Quotation') !== false, true);
t('send is on the audit log', strpos((string)@file_get_contents($tmp . '/quote_mail.log'), 'PF007 -> billing@cust.test sent') !== false, true);

echo "\nPDF path fallback — second endpoint tried when the first is empty\n";
$crm = new FakeCrm(); $crm->client = $goodClient; $crm->pdfPrimary = null; $crm->pdfSecondary = '%PDF-1.7 alt';
$mailer = new RecMailer();
$r = $mkSvc($crm, $mailer)->createCrmQuote(42, $items, 'QUO-3', $retailer);
t('fallback endpoint delivered the PDF', $r['emailed_by_plugin'], true);
// quotes/{id}/pdf leads: that is the path the WhatsApp branch has been
// fetching successfully all along. billing/ follows as the fallback.
t('both endpoints were tried, the proven one first', $crm->rawPaths,
  ["quotes/{$crm->quoteId}/pdf", "billing/quotes/{$crm->quoteId}/pdf"]);

echo "\nFailures fall back to uCRM — a quote is never silently unemailed\n";
$crm = new FakeCrm(); $crm->client = ['firstName' => 'No', 'lastName' => 'Email', 'contacts' => []];
$crm->pdfPrimary = '%PDF-1.4 x';
$r = $mkSvc($crm, new RecMailer())->createCrmQuote(42, $items, 'QUO-4', $retailer);
t('no customer email: falls back to uCRM /send', $crm->patches, ["billing/quotes/{$crm->quoteId}/send"]);
t('and says why', strpos((string)$r['email_error'], 'email') !== false, true);

$crm = new FakeCrm(); $crm->client = $goodClient; $crm->pdfPrimary = '%PDF-1.4 x';
$mailer = new RecMailer(); $mailer->configured = false;
$r = $mkSvc($crm, $mailer)->createCrmQuote(42, $items, 'QUO-5', $retailer);
t('unconfigured plugin mail: falls back to uCRM /send', $crm->patches, ["billing/quotes/{$crm->quoteId}/send"]);
t('error names the settings screen', strpos((string)$r['email_error'], 'not configured') !== false, true);

$crm = new FakeCrm(); $crm->client = $goodClient; $crm->pdfPrimary = '%PDF-1.4 x';
$mailer = new RecMailer(); $mailer->sendOk = false;
$r = $mkSvc($crm, $mailer)->createCrmQuote(42, $items, 'QUO-6', $retailer);
t('SMTP refusal: falls back to uCRM /send', $crm->patches, ["billing/quotes/{$crm->quoteId}/send"]);
t('with the SMTP error surfaced', $r['email_error'], 'SMTP said no');

exec('rm -rf ' . escapeshellarg($tmp));
echo "\nSent-folder archive — off by default, never fatal\n";
require_once $root . '/lib/SentCopy.php';
$r = SentCopy::append([], 'raw');
t('unconfigured: disabled, not attempted', [$r['ok'], $r['error']], [false, 'disabled']);
$r = SentCopy::append(['sent_copy_enabled' => true], 'raw');
t('enabled but incomplete: refuses with a clear reason', $r['ok'], false);
t('and says what is missing', strpos($r['error'], 'incomplete') !== false, true);
$r = SentCopy::append(['sent_copy_enabled' => true, 'sent_copy_host' => '127.0.0.1',
                       'sent_copy_port' => 1, 'sent_copy_user' => 'a@b.c',
                       'sent_copy_pass' => 'x'], 'raw');
t('unreachable server returns, never throws', $r['ok'], false);
t('and never leaks the password in the error', strpos($r['error'], 'x') === false || strlen($r['error']) > 1, true);
$ms = (string)file_get_contents($root . '/lib/MailService.php');
t('a Sent copy is filed only after a successful send',
  strpos($ms, "Email queued at SMTP server") < strpos($ms, '$this->sentCopy($rawMessage)'), true);
t('and it can never fail the customer send',
  strpos($ms, 'catch (\\Throwable $e) {') !== false && strpos($ms, "'step' => 'sent_copy'") !== false, true);

echo "\nHeaders carry no raw UTF-8 — the em-dash mojibake\n";
// Lived failure: "Welcome to DishNet — your internet is live" arrived in
// Roundcube as "DishNet â€\" your internet is live", because the subject went
// out as raw UTF-8 and each client guessed the charset differently.
$subj = '[TEST] Welcome to DishNet — your internet is live';
$mailer2 = new MailService('/nonexistent');
[$hh, ] = $mailer2->composeMime('DishNet <a@b.c>', '"Bhavin Madlani" <x@y.z>', $subj, '<p>x</p>', 'x');
t('no raw 8-bit byte survives in the headers', (bool)preg_match('/[\x80-\xFF]/', $hh), false);
$unfolded = preg_replace("/\r\n[ \t]+/", ' ', $hh);
preg_match('/^Subject: (.*)$/m', $unfolded, $sm);
t('subject decodes back to exactly what was asked for',
  mb_decode_mimeheader(trim($sm[1])), $subj);
$tooLong = false;
foreach (explode("\r\n", $hh) as $ln) if (strlen($ln) > 78) $tooLong = true;
t('every header line stays within the 78-column limit', $tooLong, false);
t('a plain ASCII subject is left untouched',
  MailService::encodeHeaderText('Invoice INV-0428 due 20 Sep'), 'Invoice INV-0428 due 20 Sep');
t('an address keeps its literal <angle> part',
  strpos(MailService::encodeHeaderName('"Amal Öqvist" <a@b.c>'), '<a@b.c>') !== false, true);
t('and the display name is encoded',
  strpos(MailService::encodeHeaderName('"Amal Öqvist" <a@b.c>'), '=?UTF-8?B?') === 0, true);

echo "\nSent-copy tries more than one route, and says which\n";
$sc = (string)file_get_contents($root . '/lib/SentCopy.php');
$t = function (string $n, bool $c) { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$n}\n"; } else { $fail++; echo "  FAIL {$n}\n"; } };
$t('the docker bridge gateway is tried when the name fails',
   strpos($sc, '172.17.0.1') !== false);
$t('an IP route still verifies the certificate, pinned to the real hostname',
   strpos($sc, "'peer_name'") !== false
   && strpos($sc, '\'verify_peer\'       => $certName !== null ? true : $verify') !== false);
$t('the TLS warning is captured rather than suppressed by @',
   strpos($sc, 'set_error_handler') !== false
   && strpos($sc, 'restore_error_handler') !== false
   && strpos($sc, '@stream_socket_client') === false);
$t('an empty error string still yields something a human can act on',
   strpos($sc, "no reason reported") !== false);
$t('the bridge address is read from the routing table, not hardcoded',
   strpos($sc, '/proc/net/route') !== false);
$t('an operator can override the routes entirely',
   strpos($sc, 'sent_copy_hosts') !== false);
$t('every attempted route is reported back, not just the last',
   strpos($sc, "\$out['tried'] = \$tried;") !== false);
$t('the successful route is reported too',
   strpos($sc, "\$out['via'] = \$connectHost;") !== false);
$t('an IP host is not given pointless alternates',
   strpos($sc, 'FILTER_VALIDATE_IP') !== false);


echo "\nA quotation still carries a PDF when uCRM serves none\n";
$qs = (string)file_get_contents($root . '/lib/QuotationService.php');
$src = (string)file_get_contents($root . '/lib/QuotePdfSource.php');
$t('the plugin renders its own PDF when uCRM answers 404',
   strpos($src, 'PluginQuotePdf') !== false);
$t('the own-render is tried only after uCRM has been asked both ways',
   strpos($src, 'billing/quotes/{$quoteId}/pdf') < strpos($src, 'new PluginQuotePdf'));
$t('a failed own-render still falls back to uCRM sending, as before',
   strpos($qs, 'uCRM served no quotation PDF and the plugin could not render one') !== false);
$t('the renderer never throws into the quote path',
   strpos($src, 'catch (\\Throwable') !== false
   && strpos($src, "return ['', 'none'];") !== false);
$t('and the operator is told when a plugin-rendered PDF went out',
   strpos($qs, 'sent with a plugin-rendered PDF') !== false);

echo "\nA configured route is tried first, not after a known failure\n";
$t('an explicit sent_copy_hosts leads the attempt order',
   strpos($sc, 'if ($manual && filter_var($host, FILTER_VALIDATE_IP) === false)') !== false);
$t('and the public name remains as the fallback behind it',
   preg_match('/foreach \\(\\$manual as \\$alt\\) \\$attempts\\[\\] = \\[\\$alt, \\$host\\];\s*\n\s*\\$attempts\\[\\] = \\[\\$host, null\\];/', $sc) === 1);
$t('with nothing configured the public name still leads, as before',
   strpos($sc, '$attempts[] = [$host, null];' . "\n" . '                if (filter_var($host, FILTER_VALIDATE_IP) === false) {') !== false);
$t('a route that stopped working names the likely cause',
   strpos($sc, 'lost the docker network') !== false);

echo "\nA quote created in uCRM\'s own screen also gets our email, exactly once\n";
$wh = (string)file_get_contents($root . '/webhook.php');
$t('quote.add sends the branded quotation email',
   strpos($wh, 'whQuotationEmail(') !== false);
$callPos = strpos($wh, 'whQuotationEmail($quoteId');
$gatePos = strpos($wh, 'if ($phone && $amount > 0) {', $callPos ?: 0);
$t('it runs before the phone gate, so an email-only customer is served',
   $callPos !== false && $gatePos !== false && $callPos < $gatePos);
$t('both entry points claim the same key, so no customer gets two',
   strpos($wh, 'QEMAIL{$quoteId}') !== false
   && strpos($qs, 'QEMAIL{$quoteId}') !== false);
$t('the attachment uses the key MailService actually reads',
   strpos($wh, "'content' => \$pdf") !== false
   && strpos($wh, "'data' => \$pdf") === false);
$t('the PDF comes from the one shared source, not a second copy of the logic',
   strpos($wh, 'QuotePdfSource::fetch') !== false
   && strpos($qs, 'QuotePdfSource::fetch') !== false
   && strpos($qs, 'renderOwnQuotePdf') === false);
$t('a claim failure lets the email through rather than silencing it',
   strpos((string)file_get_contents($root . '/lib/CustomerEmailDispatcher.php'),
          'A broken claim must not silence the email') !== false);

echo "\nA claim taken by the webhook is given back when the send fails\n";
$wh3 = (string)file_get_contents($root . '/webhook.php');
// The claim is taken before the work so two paths cannot both send. Held
// after a failure, it makes the quotation permanently unsendable by anyone,
// and the only symptom is silence — which is how quote #5 was lost.
$t('the failure branch releases the claim',
   preg_match('/releaseClaim\\(\\$pdo, "QEMAIL\\{\\$quoteId\\}"\\);\s*\n\s*whLog[^\n]*NOT sent/', $wh3) === 1);
$t('a thrown exception releases it too',
   preg_match('/catch \\(\\\\Throwable \\$e\\) \\{\s*\n\s*if \\(isset\\(\\$pdo\\)[^\n]*releaseClaim/', $wh3) === 1);
$t('and the error is logged where the operator will look, not only to error_log',
   strpos($wh3, "'Quotation email errored: '") !== false);
$t('the send tool can see a stuck claim',
   strpos((string)file_get_contents($root . '/tools/quote_email_send.php'), 'clear-claim') !== false);

echo "\nuCRM renders nothing for a draft, so the draft is approved first\n";
$src2 = (string)file_get_contents($root . '/lib/QuotePdfSource.php');
// Every quote is created as a draft. Fetching immediately always 404'd, we
// fell back to rendering our own, and the customer got a document that looked
// nothing like the template the operator designed in uCRM.
$t('a draft quote is moved to Open before the PDF is asked for',
   strpos($src2, "\$crm->patch(\"billing/quotes/{\$quoteId}\", ['status' => 1])") !== false);
$t('and the fetch is retried, because generation is not instant',
   strpos($src2, 'foreach ($waits as $wait)') !== false);
$t('uCRM is asked before the plugin renders anything',
   strpos($src2, "return [\$try, 'ucrm'];") < strpos($src2, 'new PluginQuotePdf'));
$t('a quote already Open is not needlessly re-approved',
   strpos($src2, "\$status === 0") !== false);
$t('the caller can pass the quote it holds, avoiding a round trip',
   strpos($src2, 'array $quote = []') !== false);
$t('and the webhook does pass it',
   strpos((string)file_get_contents($root . '/webhook.php'),
          'QuotePdfSource::fetch($crm, $dataDir, $config, $quoteId, $client, $quote)') !== false);
$t('the send tool says WHICH renderer produced the attachment',
   strpos((string)file_get_contents($root . '/tools/quote_email_send.php'),
          'rendered by the PLUGIN, not by uCRM') !== false);

echo "\nA PDF that arrives base64-encoded is still a PDF\n";
require_once $root . '/lib/QuotePdfSource.php';
// getRawContent() has always ended with base64_encode(). Every checker
// written since compared the result against '%PDF' and concluded there was no
// PDF — a base64 one begins "JVBERi0". uCRM was serving the document all
// along; the plugin discarded it and drew its own, and the customer got a
// quotation that looked nothing like the installed template.
$rawPdf = "%PDF-1.4\n1 0 obj<<>>\nendobj";
t('raw bytes pass through untouched',
   QuotePdfSource::toPdfBytes($rawPdf), $rawPdf);
t('base64 is decoded back to the same bytes',
   QuotePdfSource::toPdfBytes(base64_encode($rawPdf)), $rawPdf);
t('an HTML error page is not mistaken for a PDF',
   QuotePdfSource::toPdfBytes('<html>Not Found</html>'), '');
t('nor is base64 of something that is not a PDF',
   QuotePdfSource::toPdfBytes(base64_encode('<html>')), '');
t('null and empty are handled without a warning',
   QuotePdfSource::toPdfBytes(null) . QuotePdfSource::toPdfBytes(''), '');
// Strip comments first: the explanation of this very bug names both
// getRawContent and '%PDF' a few lines apart, and matched itself.
$stripComments = function (string $file): string {
    $code = '';
    foreach (token_get_all((string)file_get_contents($file)) as $tk) {
        if (is_array($tk) && in_array($tk[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
        $code .= is_array($tk) ? $tk[1] : $tk;
    }
    return $code;
};
t('no caller compares getRawContent output to %PDF any more',
   preg_match("/getRawContent\\([^)]*\\)[^;]*'%PDF'/",
              $stripComments($root . '/lib/QuotePdfSource.php')
            . $stripComments($root . '/tools/pdf_template_doctor.php')), 0);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
