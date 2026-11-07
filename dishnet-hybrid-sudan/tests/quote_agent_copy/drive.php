<?php
declare(strict_types=1);
/**
 * drive.php — 5.18.104 Part P (root docs/73 §H.5.12): one run of the real senders in a process of its own, for
 * tests/test_quote_agent_copy.php. TEST ONLY.
 *
 *   php tests/quote_agent_copy/drive.php <spec.json>
 *
 * A process of its own because each run loads the code of ONE tree — the judged tree's copy, a stub tree (fault
 * injection), or 221c9dc from Git — and because CustomerEmailDispatcher and SqliteStore keep per-process statics.
 * The spec names the tree (root), the data folder, the configuration, the fake uCRM's answers and the steps:
 *
 *   app       QuotationService::createCrmQuote() through the TestQuoteSvc seam over newMailService() (a recording mailer,
 *             or the real MailService on the data folder's email_settings.json)
 *   webhook   webhook.php's own whQuotationEmail() (and whLog()), taken from the tree's file by its tokens and called
 *             directly, with the real dispatcher
 *   dispatch  CustomerEmailDispatcher::send() (after agentCopy() when the step names one), sendReminderDue(),
 *             sendInstallAuth()
 *   forget    CustomerEmailDispatcher::forget(), and releaseClaim() — what tools/quote_email_send.php --clear-claim does
 *
 * It writes one JSON result: each step's answer (or its uncaught throwable), the fake uCRM's requests, the recording
 * mailer's calls, the webhook log's lines, whether QuoteAgentCopy was loaded, and what the fake uCRM's POST saw.
 * PHP's error log goes to the spec's file. Every name, number and address is fictitious; nothing leaves 127.0.0.1.
 */
$spec = json_decode((string)file_get_contents((string)($argv[1] ?? '')), true);
if (!is_array($spec)) { fwrite(STDERR, "no spec\n"); exit(2); }
$ROOT = rtrim((string)$spec['root'], '/');
$DATA = rtrim((string)$spec['data'], '/');
date_default_timezone_set('UTC');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', (string)($spec['error_log'] ?? '/dev/null'));
error_reporting(E_ALL);
$GLOBALS['dataDir'] = $DATA;
$dataDir = $DATA;
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

foreach (['StoreInterface', 'SqliteStore', 'CrmApiClient', 'MailService', 'EmailRecipients', 'CustomerEmailDispatcher', 'QuotationService'] as $l) {
    require_once $ROOT . '/lib/' . $l . '.php';
}

/** The fake uCRM: answers from the spec, every request recorded (method, path, body). */
class QacCrm extends CrmApiClient
{
    public $log = [];
    public $spec;
    public $store;
    public $postSaw = [];
    private $clientReads = 0;
    private $posts = 0;
    public function __construct(array $spec) { parent::__construct('http://fake.test', 'k'); $this->spec = $spec; }
    public function get(string $path): ?array
    {
        $this->log[] = ['GET', $path, null];
        if (preg_match('#^clients/(\d+)$#', $path, $m)) {
            $this->clientReads++;
            if (!empty($this->spec['client_reads']) && isset($this->spec['client_reads'][$this->clientReads - 1])) {
                return $this->spec['client_reads'][$this->clientReads - 1];
            }
            return $this->spec['client'] ?? null;
        }
        if (preg_match('#^(billing/)?quotes/(\d+)$#', $path, $m)) {
            if (($this->spec['quote_get'] ?? '') === 'throw') throw new \RuntimeException('fake uCRM: read timed out (test)');
            return $this->spec['quote'] ?? ['id' => (int)$m[2], 'status' => 1];
        }
        return null;
    }
    public function post(string $path, array $p = []): ?array
    {
        $this->log[] = ['POST', $path, $p];
        if (($this->spec['post_hook'] ?? '') === 'read_makers' && $this->store !== null) {
            $this->postSaw = $this->store->findAll('quote_agent_makers.json', 'state', 'making');
        }
        $how = $this->spec['post'] ?? 'ok';
        if ($how === 'throw') throw new \RuntimeException('fake uCRM: connection reset (test)');
        if ($how === 'no_id') return ['message' => 'no id (test)'];
        $id = (int)($this->spec['quote_id'] ?? 7001) + $this->posts++;
        $r = ['id' => $id];
        if (!array_key_exists('number', $this->spec)) $r['number'] = 'QAC-' . $id;
        elseif ($this->spec['number'] !== null) $r['number'] = (string)$this->spec['number'];
        return $r;
    }
    public function patch(string $path, array $p = []): ?array { $this->log[] = ['PATCH', $path, $p]; return ['ok' => true]; }
    public function delete(string $path): ?array { $this->log[] = ['DELETE', $path, null]; return []; }
    public function getRawContent(string $path): ?string { $this->log[] = ['RAW', $path, null]; return $this->spec['pdf'] ?? '%PDF-1.4 qac-test-bytes'; }
}

/** The recording mailer (tests/test_mail_quote.php's RecMailer), with a log of the spec's choosing. */
class QacRecMailer extends MailService
{
    public $sent = [];
    public $ok = true;
    public $log = [];
    public function __construct() { parent::__construct('/nonexistent'); }
    public function getConfig(): array
    {
        return ['host' => 'fake', 'port' => 25, 'user' => '', 'pass' => '', 'enc' => '', 'from' => 'quotes@company.example.test'];
    }
    public function send(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody = '',
                         array $extraHeaders = [], array $attachments = [], ?string $fromOverride = null): array
    {
        $this->sent[] = compact('toEmail', 'toName', 'subject', 'htmlBody', 'textBody', 'extraHeaders', 'attachments', 'fromOverride');
        return $this->ok ? ['ok' => true, 'log' => $this->log] : ['ok' => false, 'error' => 'SMTP said no (test)', 'log' => $this->log];
    }
}

/** A store whose reads or writes of the named collections throw (quote_agent_copy B5, C8); everything else delegates. */
class QacFaultStore implements StoreInterface
{
    private $s; private $r; private $w;
    public function __construct($store, array $reads, array $writes) { $this->s = $store; $this->r = $reads; $this->w = $writes; }
    private function rd(string $f): void { if (in_array($f, $this->r, true)) throw new \RuntimeException('store read failed (test)'); }
    private function wr(string $f): void { if (in_array($f, $this->w, true)) throw new \RuntimeException('store write failed (test)'); }
    public function load(string $file): array { $this->rd($file); return $this->s->load($file); }
    public function save(string $file, array $data): void { $this->wr($file); $this->s->save($file, $data); }
    public function append(string $file, array $record): array { $this->wr($file); return $this->s->append($file, $record); }
    public function findOne(string $file, string $key, $value): ?array { $this->rd($file); return $this->s->findOne($file, $key, $value); }
    public function findAll(string $file, string $key, $value): array { $this->rd($file); return $this->s->findAll($file, $key, $value); }
    public function updateOne(string $file, string $key, $value, array $updates): bool { $this->wr($file); return $this->s->updateOne($file, $key, $value, $updates); }
    public function nextId(string $file): int { return $this->s->nextId($file); }
    public function appendWithId(string $file, array $record): array { $this->wr($file); return $this->s->appendWithId($file, $record); }
    public function path(string $file): string { return $this->s->path($file); }
    public function withLock(string $file, callable $fn) { return $this->s->withLock($file, $fn); }
    public function getPdo(): \PDO { return $this->s->getPdo(); }
    public function getDataDir(): string { return $this->s->getDataDir(); }
    public function __call($m, $a) { return $this->s->$m(...$a); }
}

class QacQuoteSvc extends QuotationService
{
    public $mailer;
    protected function newMailService(): MailService { return $this->mailer; }
}

/** One function of a PHP file, by its own tokens: from `function NAME` to its closing brace. */
function qac_function_source(string $src, string $name): string
{
    $tokens = token_get_all($src);
    $pos = 0; $start = -1; $depth = 0; $began = false;
    foreach ($tokens as $i => $tk) {
        $text = is_array($tk) ? $tk[1] : $tk;
        if ($start < 0) {
            if (is_array($tk) && $tk[0] === T_FUNCTION) {
                for ($j = $i + 1; isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE; $j++);
                if (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING && $tokens[$j][1] === $name) $start = $pos;
            }
        } else {
            if ($text === '{' || (is_array($tk) && in_array($tk[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) { $depth++; $began = true; }
            elseif ($text === '}') {
                $depth--;
                if ($began && $depth === 0) return substr($src, $start, $pos + 1 - $start);
            }
        }
        $pos += strlen($text);
    }
    return '';
}

$out = ['steps' => [], 'crm' => [], 'mail' => [], 'whlog' => [], 'qac_loaded' => false, 'post_saw' => []];
$crm = new QacCrm((array)($spec['crm'] ?? []));
$store = SqliteStore::create($DATA);
$crm->store = $store;
if (!empty($spec['store_faults'])) {
    $store = new QacFaultStore($store, (array)($spec['store_faults']['read'] ?? []), (array)($spec['store_faults']['write'] ?? []));
}
$config = (array)($spec['config'] ?? []);

$mailer = null;
if (($spec['mailer'] ?? 'rec') === 'rec') {
    $mailer = new QacRecMailer();
    $mailer->ok = (bool)($spec['rec_ok'] ?? true);
    $mailer->log = (array)($spec['rec_log'] ?? []);
} else {
    $mailer = new MailService($DATA);
}

$whLoaded = false;
foreach ((array)($spec['steps'] ?? []) as $st) {
    $r = ['ret' => null, 'exc' => null];
    try {
        switch ($st['do']) {
            case 'app':
                $svc = new QacQuoteSvc($store, $DATA, $config);
                $p = new ReflectionProperty(QuotationService::class, 'crm');
                $p->setAccessible(true);
                $p->setValue($svc, $crm);
                $svc->mailer = $mailer;
                $r['ret'] = $svc->createCrmQuote((int)$st['client_id'], (array)($st['items'] ?? [['label' => 'Test kit', 'quantity' => 1, 'price' => 1000, 'unit' => 'each']]),
                    (string)($st['ref'] ?? 'MAN-QAC-1'), (array)($st['retailer'] ?? []), (string)($st['note'] ?? ''), (string)($st['where'] ?? 'manual'));
                break;
            case 'webhook':
                if (!$whLoaded) {
                    $src = (string)file_get_contents($ROOT . '/webhook.php');
                    $code = "<?php\n" . qac_function_source($src, 'whLog') . "\n" . qac_function_source($src, 'whQuotationEmail') . "\n";
                    $f = $ROOT . '/.qac_wh_' . getmypid() . '.php';   // in the tree's root, so the function's __DIR__ is the tree's
                    file_put_contents($f, $code);
                    require $f;
                    @unlink($f);
                    $whLoaded = true;
                }
                $client = array_key_exists('client', $st) ? (array)$st['client'] : (array)($crm->spec['client'] ?? []);
                whQuotationEmail((int)$st['quote_id'], (int)$st['client_id'], (string)($st['name'] ?? 'Test Customer'), $client,
                    $config, $DATA, $crm, $store, 'quote.add', (array)($st['quote'] ?? ['id' => (int)$st['quote_id'], 'status' => 1]),
                    (string)($st['quote_num'] ?? ''), (float)($st['amount'] ?? 0));
                $r['ret'] = 'returned';
                break;
            case 'dispatch':
                $d = new CustomerEmailDispatcher($DATA, $config, $crm, $store->getPdo());
                if (($st['agent_copy'] ?? '') !== '') $d->agentCopy((string)$st['agent_copy']);
                foreach ((array)($st['sends'] ?? [[]]) as $s) {
                    $kind = $s['kind'] ?? 'send';
                    if ($kind === 'reminder') {
                        $r['ret'][] = $d->sendReminderDue((int)$s['client_id'], (string)($s['name'] ?? ''), (array)($s['data'] ?? []), (string)($s['dedupe'] ?? ''));
                    } elseif ($kind === 'install') {
                        $r['ret'][] = $d->sendInstallAuth((string)$s['type'], (string)$s['email'], (string)($s['name'] ?? ''), (array)($s['data'] ?? []), (string)($s['dedupe'] ?? ''));
                    } else {
                        $to = isset($s['client_id']) ? ['client_id' => (int)$s['client_id']] : (string)($s['to'] ?? '');
                        $r['ret'][] = $d->send((string)$s['key'], $to, (string)($s['name'] ?? ''), (array)($s['data'] ?? []),
                            (string)($s['dedupe'] ?? ''), (array)($s['atts'] ?? []));
                    }
                }
                break;
            case 'forget':
                CustomerEmailDispatcher::forget($store->getPdo(), 'quotation', (string)$st['quote_id']);
                CustomerEmailDispatcher::releaseClaim($store->getPdo(), 'QEMAIL' . (int)$st['quote_id']);
                $r['ret'] = 'forgotten';
                break;
        }
    } catch (\Throwable $e) {
        $r['exc'] = get_class($e) . ': ' . $e->getMessage();
    }
    $out['steps'][] = $r;
}

$out['crm'] = $crm->log;
$out['post_saw'] = $crm->postSaw;
if ($mailer instanceof QacRecMailer) $out['mail'] = $mailer->sent;
$wl = is_file($DATA . '/webhook_log.json') ? (json_decode((string)file_get_contents($DATA . '/webhook_log.json'), true) ?: []) : [];
foreach (array_reverse($wl) as $w) $out['whlog'][] = [(string)($w['event'] ?? ''), (string)($w['message'] ?? '')];
$out['qac_loaded'] = class_exists('QuoteAgentCopy', false);
file_put_contents((string)$spec['out'], json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
