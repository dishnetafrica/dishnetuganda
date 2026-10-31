<?php
/**
 * A php -S router for section C of tests/test_quote_from_ucrm.php (5.18.102 D14, docs/71 §R.4.8, §R.9.1 "C, Make the
 * quotation") — TEST ONLY. Each request names its case in the X-Qfu-Case header: a JSON file holding the plugin tree to
 * run (root), its data directory (a real SqliteStore the section seeded), the staff session, the faults to plant, and
 * where to write what the request left behind (result). Served only on 127.0.0.1, with PHP_CLI_SERVER_WORKERS above 1
 * so two presses can run at once.
 *
 *   entry 'make'   the Orders card's POST, as public.php hands it to includes/post/post_sync.php: the session's
 *                  RetailerAuth, csrfToken()/csrfCheck()/flash()/redirect() as public.php defines them, the request's
 *                  own headers (Sec-Fetch-Site, Origin, Referer, Host) and fields. No global CSRF gate stands in front,
 *                  so every refusal measured here is the handler's own (docs/71 §R.2.1: never left to the global gate).
 *   entry 'retry'  ?page=api&action=retry_quote, as includes/api_handlers.php runs includes/api/api_cron_debug.php: $me2
 *                  the API guard's record (the session's copy), $isAdmin its flag, $ok2/$er2 as that file defines them.
 *   entry 'token'  answers csrfToken() for the data directory (the day's form token).
 *
 * svc('crm') is CrmApiClient::fromUcrm() over the tree's ucrm.json, and svc('kyc') a KycService on it, exactly as
 * lib/Services.php builds them. A fault is planted only where a case names it (QfuCStore): findOne('retailers.json')
 * throwing (the handler's fresh re-read), load('ucrm_quote_links.json') throwing inside makeQuotation (a Throwable after
 * the claim), or the second withLock on kyc_applications.json throwing — the claim's removal where R-M4's guard answers
 * first (unreadable, found), else the removal of quote_unconfirmed_at (5.18.102 D14 review rounds 3 and 4) — or (5.18.102
 * D14 review, 'after_post' => <key>, or (round 5) a list of keys) an updateOne of kyc_applications.json carrying one of them
 * throwing — a Throwable after the POST — or (round 5, 'after_post_error' => <key>) the same throwing an \Error, not an \Exception.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
if ((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) === '/__c/ping') {
    header('Content-Type: application/json'); echo json_encode(['marker' => 'QFU-C-ROUTER', 'token' => (string)getenv('QFU_C_TOKEN')]); return true;
}
$__case = json_decode((string)@file_get_contents((string)($_SERVER['HTTP_X_QFU_CASE'] ?? '')), true);
if (!is_array($__case)) { http_response_code(500); echo json_encode(['status' => 'no case']); return true; }
ini_set('error_log', (string)$__case['errlog']);
ini_set('log_errors', '1');
ini_set('display_errors', '0');

$root    = (string)$__case['root'];
$dataDir = (string)$__case['data'];
$_PLUGIN_ROOT = $root;
foreach (['StoreInterface', 'SqliteStore', 'CrmApiClient', 'WalletService', 'CrmQueue', 'RetailerAuth', 'KycService'] as $__l) {
    require_once $root . '/lib/' . $__l . '.php';
}

final class QfuCStore implements StoreInterface
{
    private $s; private $faults; private $locks = 0;
    public function __construct(SqliteStore $s, array $faults) { $this->s = $s; $this->faults = $faults; }
    private function fault(string $what): void { throw new \RuntimeException('TEST fault: ' . $what); }
    public function load(string $file): array
    {
        if ($file === 'ucrm_quote_links.json' && !empty($this->faults['links_load'])) $this->fault('links load');
        return $this->s->load($file);
    }
    public function save(string $file, array $data): void { $this->s->save($file, $data); }
    public function append(string $file, array $record): array { return $this->s->append($file, $record); }
    public function findOne(string $file, string $key, $value): ?array
    {
        if ($file === 'retailers.json' && !empty($this->faults['find_retailer'])) $this->fault('retailer read');
        return $this->s->findOne($file, $key, $value);
    }
    public function findAll(string $file, string $key, $value): array { return $this->s->findAll($file, $key, $value); }
    public function updateOne(string $file, string $key, $value, array $updates): bool
    {
        // 5.18.102 D14 review (D14-MQ-THROW-AFTER-POST): a write of the application carrying a named key throws (round 5:
        // one key or several; 'after_post_error' throws an \Error, since the catch around postQuote takes every Throwable)
        if ($file === 'kyc_applications.json') {
            foreach ((array)($this->faults['after_post'] ?? []) as $__k) {
                if (is_string($__k) && array_key_exists($__k, $updates)) $this->fault('application write of ' . $__k);
            }
            // round 7: 'fatal_after_post' => <key> — a fatal error at that write (memory exhausted): no catch and no finally run
            $__x = $this->faults['fatal_after_post'] ?? null;
            if (is_string($__x) && array_key_exists($__x, $updates)) { ini_set('memory_limit', (string)(memory_get_usage(true) + 8 * 1024 * 1024)); $__big = str_repeat('x', 256 * 1024 * 1024); error_log('TEST: the fatal fault did not die'); }
            $__e = $this->faults['after_post_error'] ?? null;
            if (is_string($__e) && array_key_exists($__e, $updates)) throw new \Error('TEST error fault: application write of ' . $__e);
        }
        return $this->s->updateOne($file, $key, $value, $updates);
    }
    public function nextId(string $file): int { return $this->s->nextId($file); }
    public function appendWithId(string $file, array $record): array { return $this->s->appendWithId($file, $record); }
    public function path(string $file): string { return $this->s->path($file); }
    public function withLock(string $file, callable $fn)
    {
        // 5.18.102 D14 review round 9: 'lock_n' => <n>, the n-th withLock of the applications throwing — 3 is a refusing
        // press's claim removal (the claim, the key's removal, the claim's removal)
        if ($file === 'kyc_applications.json' && (++$this->locks === 2 && !empty($this->faults['second_lock'])
                || $this->locks === (int)($this->faults['lock_n'] ?? 0))) $this->fault('claim removal');
        return $this->s->withLock($file, $fn);
    }
    public function getPdo(): \PDO { return $this->s->getPdo(); }
    public function getDataDir(): string { return $this->s->getDataDir(); }
    public function __call($m, $a) { return $this->s->$m(...$a); }
}

$__real  = SqliteStore::create($dataDir);
$config  = $__real->load('kyc_config.json');
$store   = new QfuCStore($__real, (array)($__case['faults_handler'] ?? []));
$__kycStore = new QfuCStore($__real, (array)($__case['faults_kyc'] ?? []));

// public.php's helpers (public.php:76-110, :632, :637), on this data directory
function _csrfSecret(): string {
    global $dataDir;
    $f = rtrim($dataDir ?? sys_get_temp_dir(), '/') . '/csrf_secret.key';
    if (is_file($f)) { $s = trim(file_get_contents($f)); if (strlen($s) === 64) return $s; }
    $s = bin2hex(random_bytes(32));
    @file_put_contents($f, $s, LOCK_EX);
    return $s;
}
function csrfToken(): string { $day = date('Ymd'); return $day . ':' . substr(hash_hmac('sha256', 'csrf:' . $day, _csrfSecret()), 0, 40); }
function csrfField(): string { return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES) . '">'; }
function csrfCheck(): bool {
    $t = $_POST['_csrf'] ?? '';
    if (!$t) return false;
    $parts = explode(':', $t, 2);
    if (count($parts) !== 2) return false;
    [$day, $sig] = $parts;
    $today = date('Ymd'); $yday = date('Ymd', strtotime('-1 day'));
    if ($day !== $today && $day !== $yday) return false;
    return hash_equals(substr(hash_hmac('sha256', 'csrf:' . $day, _csrfSecret()), 0, 40), $sig);
}
function flash(string $msg, string $type = 'success'): void { $_SESSION['kyc_flash'] = ['msg' => $msg, 'type' => $type]; }
function redirect(string $url): void { @ob_end_clean(); header("Location: {$url}"); exit; }
// lib/Services.php's two entries this needs, built the same way
function svc(string $name)
{
    static $i = [];
    if (isset($i[$name])) return $i[$name];
    if ($name === 'crm') return $i[$name] = CrmApiClient::fromUcrm($GLOBALS['_PLUGIN_ROOT'], $GLOBALS['config']);
    if ($name === 'kyc') {
        $s = $GLOBALS['__kycStore'];
        return $i[$name] = new KycService(svc('crm'), $s, new WalletService($s, $s->getPdo()), new CrmQueue($s), $GLOBALS['dataDir']);
    }
    throw new \RuntimeException('svc: ' . $name);
}

$_SESSION = [];
if (is_array($__case['session'] ?? null)) {
    $__r = $__case['session'];
    $_SESSION['kyc_retailer'] = ['id' => $__r['id'] ?? 0, 'name' => $__r['name'] ?? '', 'email' => $__r['email'] ?? '',
        'is_admin' => $__r['is_admin'] ?? false, 'role' => $__r['role'] ?? 'sales', 'logged_in_at' => time() - 60,
        'cached_record' => $__r, 'cache_refreshed' => time() - (int)($__case['session_age'] ?? 5)];
}
$auth = new RetailerAuth($store);

$__result = ['end' => 'fell through'];
register_shutdown_function(function () use (&$__result, $__case) {
    $loc = '';
    foreach (headers_list() as $h) if (stripos($h, 'Location:') === 0) $loc = trim(substr($h, 9));
    $__result += ['code' => http_response_code(), 'location' => $loc, 'flash' => $_SESSION['kyc_flash'] ?? null,
                  'buffer' => (string)ob_get_contents()];
    file_put_contents((string)$__case['result'], json_encode($__result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));
});
ob_start();

$__entry = (string)($__case['entry'] ?? '');
if ($__entry === 'token') { $__result['end'] = 'token'; ob_end_clean(); echo csrfToken(); exit; }
if ($__entry === 'make') {
    $__result['end'] = 'handled';
    include $root . '/includes/post/post_sync.php';
    $__result['end'] = 'fell through';
    exit;
}
if ($__entry === 'retry') {
    $__result['end'] = 'handled';
    $act = 'retry_quote'; $met = 'GET';
    $me2 = $__case['me2'] ?? null;
    $isAdmin = !empty($me2['is_admin']);
    $crm = svc('crm');
    $ok2 = function ($d, $m = 'OK', $c = 200) { ob_end_clean(); http_response_code($c); echo json_encode(['status' => 'success', 'message' => $m, 'data' => $d]); exit; };
    $er2 = function ($m, $c = 400) { ob_end_clean(); http_response_code($c); echo json_encode(['status' => 'error', 'message' => $m]); exit; };
    include $root . '/includes/api/api_cron_debug.php';
    $__result['end'] = 'fell through';
    exit;
}
http_response_code(400);
echo 'unknown entry';
