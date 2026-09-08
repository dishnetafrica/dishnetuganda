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
    public function post(string $path, array $p = []): ?array { return ['id' => 7, 'number' => 'PF007']; }
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
                         array $extraHeaders = [], array $attachments = []): array {
        $this->sent[] = compact('toEmail', 'toName', 'subject', 'htmlBody', 'extraHeaders', 'attachments');
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
t('uCRM /send is the sender', $crm->patches, ['billing/quotes/7/send']);
t('not emailed by plugin', $r['emailed_by_plugin'], false);

echo "\nToggle ON — the plugin emails the PDF itself, uCRM mailer untouched\n";
file_put_contents($tmp . '/email_settings.json', json_encode(['quote_email_via_plugin' => true]));
$crm = new FakeCrm(); $crm->client = $goodClient; $crm->pdfPrimary = '%PDF-1.4 real-quote-bytes';
$mailer = new RecMailer();
$r = $mkSvc($crm, $mailer)->createCrmQuote(42, $items, 'QUO-2', $retailer);
t('emailed by plugin', $r['emailed_by_plugin'], true);
t('uCRM /send NOT called', $crm->patches, []);
t('billing contact preferred', $mailer->sent[0]['toEmail'] ?? '', 'billing@cust.test');
t('subject carries the uCRM number', strpos((string)($mailer->sent[0]['subject'] ?? ''), 'PF007') !== false, true);
$att = $mailer->sent[0]['attachments'][0] ?? [];
t('PDF attached under the quote number', $att['name'] ?? '', 'Quotation-PF007.pdf');
t('as application/pdf', $att['mime'] ?? '', 'application/pdf');
t('with the exact bytes uCRM served', $att['content'] ?? '', '%PDF-1.4 real-quote-bytes');
t('body invites reply-or-pay acceptance', strpos((string)($mailer->sent[0]['htmlBody'] ?? ''), 'either one confirms your order') !== false, true);
t('send is on the audit log', strpos((string)@file_get_contents($tmp . '/quote_mail.log'), 'PF007 -> billing@cust.test sent') !== false, true);

echo "\nPDF path fallback — second endpoint tried when the first is empty\n";
$crm = new FakeCrm(); $crm->client = $goodClient; $crm->pdfPrimary = null; $crm->pdfSecondary = '%PDF-1.7 alt';
$mailer = new RecMailer();
$r = $mkSvc($crm, $mailer)->createCrmQuote(42, $items, 'QUO-3', $retailer);
t('fallback endpoint delivered the PDF', $r['emailed_by_plugin'], true);
t('both endpoints were tried in order', $crm->rawPaths, ['billing/quotes/7/pdf', 'quotes/7/pdf']);

echo "\nFailures fall back to uCRM — a quote is never silently unemailed\n";
$crm = new FakeCrm(); $crm->client = ['firstName' => 'No', 'lastName' => 'Email', 'contacts' => []];
$crm->pdfPrimary = '%PDF-1.4 x';
$r = $mkSvc($crm, new RecMailer())->createCrmQuote(42, $items, 'QUO-4', $retailer);
t('no customer email: falls back to uCRM /send', $crm->patches, ['billing/quotes/7/send']);
t('and says why', strpos((string)$r['email_error'], 'email') !== false, true);

$crm = new FakeCrm(); $crm->client = $goodClient; $crm->pdfPrimary = '%PDF-1.4 x';
$mailer = new RecMailer(); $mailer->configured = false;
$r = $mkSvc($crm, $mailer)->createCrmQuote(42, $items, 'QUO-5', $retailer);
t('unconfigured plugin mail: falls back to uCRM /send', $crm->patches, ['billing/quotes/7/send']);
t('error names the settings screen', strpos((string)$r['email_error'], 'not configured') !== false, true);

$crm = new FakeCrm(); $crm->client = $goodClient; $crm->pdfPrimary = '%PDF-1.4 x';
$mailer = new RecMailer(); $mailer->sendOk = false;
$r = $mkSvc($crm, $mailer)->createCrmQuote(42, $items, 'QUO-6', $retailer);
t('SMTP refusal: falls back to uCRM /send', $crm->patches, ['billing/quotes/7/send']);
t('with the SMTP error surfaced', $r['email_error'], 'SMTP said no');

exec('rm -rf ' . escapeshellarg($tmp));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
