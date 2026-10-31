<?php
/**
 * TEST ONLY — section L's request runner (tests/quote_from_ucrm/L_links.php; 5.18.102 D14, docs/71 §R.9.1 "New section
 * L"). One request per process:
 *
 *   php l_run.php <case.json>
 *
 * The case names the plugin tree to run (root: a copy of the judged tree), its data directory (a real SqliteStore the
 * section seeded), the zone the process runs in (tz: the runner's QFU_TZ, never named here), the fake uCRM (crm), and
 * where to write what the request left behind (result). Entries:
 *
 *   post   a POST as public.php hands it on: the session's RetailerAuth (a staff record cached in the session, as
 *          RetailerAuth keeps it, refreshed session_age seconds ago), csrfToken()/csrfCheck() as the judged tree's
 *          public.php defines them (read from that file, never re-typed), flash() as public.php's, redirect() recording
 *          the address (public.php's sends a Location header, which a CLI process cannot show), then the global CSRF
 *          gate as the judged tree's includes/post_handlers.php writes it (read from that file), then
 *          includes/post/post_sync.php. The request's own headers (Sec-Fetch-Site, Origin, Referer, Host) and fields.
 *   page   a dashboard tab, tabs/sales/<file>, drawn for $_GET as the dashboard includes it (h() and csrfField() as
 *          public.php defines them).
 *   form   the KYC form, tabs/sales/kyc_form.php.
 *   retry  ?page=api&action=retry_quote, as includes/api_handlers.php runs includes/api/api_cron_debug.php: $me2 the
 *          API guard's record, $isAdmin its flag.
 *   token  csrfToken() for the data directory (the day's form token).
 *
 * $crm is the plugin's client on the fake uCRM (fixtures/fake_ucrm_kyc.php). A hook (case.hook, PHP) runs on a SECOND
 * connection to plugin.sqlite3 when uCRM is first asked for its products — another writer committing while uCRM
 * answers that read (docs/71 §R.9.1 L-race, L-row-changed, L-rule8-crosswise). A fault is planted only where a case
 * names it (QfuLStore): findOne('retailers.json') throwing (the handlers' fresh re-read), or withLock on the link store
 * throwing (a lock that fails). Every person, number and document is fictitious.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$__case = json_decode((string)@file_get_contents((string)($argv[1] ?? '')), true);
if (!is_array($__case)) { fwrite(STDERR, "l_run: no case\n"); exit(2); }
ini_set('error_log', (string)$__case['errlog']);
if (is_string($__case['tz'] ?? null) && $__case['tz'] !== '') date_default_timezone_set($__case['tz']);

$root    = (string)$__case['root'];
$dataDir = (string)$__case['data'];
$_PLUGIN_ROOT = $root;
$GLOBALS['_PLUGIN_ROOT'] = $root;
foreach (['StoreInterface', 'JsonStore', 'SqliteStore', 'CrmApiClient', 'WalletService', 'CrmQueue', 'RetailerAuth', 'KycService',
          'currency', 'crm_url'] as $__l) {
    if (is_file($root . '/lib/' . $__l . '.php')) require_once $root . '/lib/' . $__l . '.php';
}

final class QfuLStore implements StoreInterface
{
    private $s; private $faults;
    public function __construct(SqliteStore $s, array $faults) { $this->s = $s; $this->faults = $faults; }
    private function fault(string $what): void { throw new \RuntimeException('TEST fault: ' . $what); }
    public function load(string $file): array { return $this->s->load($file); }
    public function save(string $file, array $data): void { $this->s->save($file, $data); }
    public function append(string $file, array $record): array { return $this->s->append($file, $record); }
    public function findOne(string $file, string $key, $value): ?array
    {
        if ($file === 'retailers.json' && !empty($this->faults['find_retailer'])) $this->fault('retailer read');
        return $this->s->findOne($file, $key, $value);
    }
    public function findAll(string $file, string $key, $value): array { return $this->s->findAll($file, $key, $value); }
    public function updateOne(string $file, string $key, $value, array $updates): bool { return $this->s->updateOne($file, $key, $value, $updates); }
    public function nextId(string $file): int { return $this->s->nextId($file); }
    public function appendWithId(string $file, array $record): array { return $this->s->appendWithId($file, $record); }
    public function path(string $file): string { return $this->s->path($file); }
    public function withLock(string $file, callable $fn)
    {
        if ($file === 'ucrm_quote_links.json' && !empty($this->faults['links_lock'])) $this->fault('the link store\'s lock');
        return $this->s->withLock($file, $fn);
    }
    public function getPdo(): \PDO { return $this->s->getPdo(); }
    public function getDataDir(): string { return $this->s->getDataDir(); }
    public function __call($m, $a) { return $this->s->$m(...$a); }
}

/** The plugin's client on the fake uCRM; the case's hook runs once, on another connection, when products are first read. */
class QfuLCrm extends CrmApiClient
{
    public static $hook = null;
    public static $fired = [];
    public function get(string $path): ?array
    {
        if (self::$hook !== null && self::$fired === [] && strpos($path, 'products') === 0) {
            self::$fired[] = ['path' => $path, 'at' => microtime(true)];
            $pdo2 = new \PDO('sqlite:' . $GLOBALS['dataDir'] . '/plugin.sqlite3', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $pdo2->exec('PRAGMA busy_timeout = 5000');
            try {
                eval(self::$hook);
                self::$fired[0]['ok'] = true;
            } catch (\Throwable $e) {
                self::$fired[0]['ok'] = false;
                self::$fired[0]['error'] = get_class($e) . ': ' . $e->getMessage();
            }
            $pdo2 = null;
        }
        return parent::get($path);
    }
}

$__real = SqliteStore::create($dataDir);
$store  = new QfuLStore($__real, (array)($__case['faults'] ?? []));
$config = $__real->load('kyc_config.json');
if (!is_array($config)) $config = [];
$config = (array)($__case['cfg'] ?? []) + $config;
$crm = new QfuLCrm((string)$__case['crm'], 'FAKE-TEST-KEY', 'X-Auth-App-Key');
if (is_string($__case['hook'] ?? null) && $__case['hook'] !== '') QfuLCrm::$hook = (string)$__case['hook'];

// public.php's own helpers: the CSRF functions read from the judged tree's public.php, never re-typed
$__pub = (string)@file_get_contents($root . '/public.php');
$__a = strpos($__pub, 'function _csrfSecret(): string {');
$__b = strpos($__pub, 'function csrfCheck(): bool {');
$__c = $__b === false ? false : strpos($__pub, "\n}\n", $__b);
$__helpers = ($__a !== false && $__b !== false && $__c !== false && $__a < $__b) ? substr($__pub, $__a, $__c + 3 - $__a) : '';
if ($__helpers === '' || strpos($__helpers, 'function csrfToken(): string {') === false) {
    file_put_contents((string)$__case['result'], json_encode(['end' => 'no csrf helpers in public.php']));
    exit(3);
}
eval($__helpers);
if (!function_exists('h')) { function h($s): string { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); } }
function flash(string $msg, string $type = 'success'): void { $_SESSION['kyc_flash'] = ['msg' => $msg, 'type' => $type]; }
function redirect(string $url): void { $GLOBALS['__qfu_location'] = $url; @ob_end_clean(); exit; }
// lib/Services.php's two entries this needs, built on this request's store and client
function svc(string $name)
{
    static $i = [];
    if (isset($i[$name])) return $i[$name];
    if ($name === 'crm') return $i[$name] = $GLOBALS['crm'];
    if ($name === 'kyc') {
        $s = $GLOBALS['store'];
        return $i[$name] = new KycService($GLOBALS['crm'], $s, new WalletService($s, $s->getPdo()), new CrmQueue($s), $GLOBALS['dataDir']);
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
$_SERVER['HTTP_HOST'] = (string)($__case['host'] ?? 'crm.example.test');
foreach ((array)($__case['headers'] ?? []) as $__k => $__v) $_SERVER[$__k] = (string)$__v;
$GLOBALS['__qfu_location'] = null;

$__result = ['end' => 'exit'];
register_shutdown_function(function () use (&$__result, $__case) {
    $__result += ['code' => http_response_code(), 'location' => $GLOBALS['__qfu_location'], 'flash' => $_SESSION['kyc_flash'] ?? null,
                  'buffer' => (string)@ob_get_contents(), 'hook' => QfuLCrm::$fired];
    file_put_contents((string)$__case['result'], json_encode($__result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));
});
ob_start();

$__entry = (string)($__case['entry'] ?? '');
if ($__entry === 'token') { $__result['end'] = 'token'; $__result['token'] = csrfToken(); exit; }

if ($__entry === 'post') {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = (array)($__case['fields'] ?? []);
    $_GET = [];
    // the global CSRF gate, as includes/post_handlers.php writes it (read from the judged tree's file)
    $__ph = (string)@file_get_contents($root . '/includes/post_handlers.php');
    $__g0 = strpos($__ph, "if (\$_SERVER['REQUEST_METHOD'] === 'POST') {\n    \$postAction = \$_POST['action'] ?? '';");
    $__g1 = $__g0 === false ? false : strpos($__ph, "\n}\n", $__g0);
    $__gate = ($__g0 !== false && $__g1 !== false) ? substr($__ph, $__g0, $__g1 + 2 - $__g0) : '';
    if ($__gate === '' || strpos($__gate, '$cbAction') === false || strpos($__gate, 'csrfCheck()') === false) {
        $__result['end'] = 'no global gate in post_handlers.php';
        exit;
    }
    $__result['end'] = 'gate';
    eval($__gate);
    $__result['end'] = 'handled';
    include $root . '/includes/post/post_sync.php';
    $__result['end'] = 'fell through';
    exit;
}

if ($__entry === 'page' || $__entry === 'form') {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = (array)($__case['get'] ?? []);
    $isAdmin  = !empty($__case['admin']);
    $retailer = ['id' => 1, 'name' => 'Test Admin', 'role' => $isAdmin ? 'admin' : 'sales', 'api_token' => 'TEST-TOKEN'];
    $myApps   = $store->load('kyc_applications.json');
    $devices  = $store->load('kyc_devices.json');
    $myWallet = ['balance' => 10000000.0];
    $file = $__entry === 'form' ? 'kyc_form.php' : basename((string)$__case['file']);
    $__result['end'] = 'drawing';
    include $root . '/tabs/sales/' . $file;
    $__result['end'] = 'drawn';
    $__result['html'] = (string)ob_get_contents();
    ob_clean();
    exit;
}

if ($__entry === 'retry') {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = ['page' => 'api', 'action' => 'retry_quote', 'app_id' => (string)($__case['app_id'] ?? '')];
    $act = 'retry_quote'; $met = 'GET';
    $me2 = $__case['me2'] ?? null;
    $isAdmin = !empty($me2['is_admin']);
    $ok2 = function ($d, $m = 'OK', $c = 200) use (&$__result) { $__result['answer'] = ['status' => 'success', 'message' => $m, 'data' => $d]; @ob_end_clean(); http_response_code($c); exit; };
    $er2 = function ($m, $c = 400) use (&$__result) { $__result['answer'] = ['status' => 'error', 'message' => $m]; @ob_end_clean(); http_response_code($c); exit; };
    $__result['end'] = 'handled';
    include $root . '/includes/api/api_cron_debug.php';
    $__result['end'] = 'fell through';
    exit;
}
$__result['end'] = 'unknown entry';
