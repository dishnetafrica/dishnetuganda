<?php
declare(strict_types=1);
/**
 * quote_company_journey.php — TEST ONLY (tests/test_quote_company.php, 5.18.99).
 *
 * Two things in one file:
 *
 *   1. A small library (functions qcj_*) that tests/test_quote_company.php includes: the fake servers, plugin tree
 *      copies, a "book" (a data directory with its configuration and store seeded, a vault, ucrm.json), probes that run
 *      a piece of PHP against a tree in a fresh process, and an HTTP-served copy of a tree for the paths that only
 *      exist behind public.php.
 *
 *   2. The journey (run directly): php quote_company_journey.php --root=<tree> --scenario=<name> --fake=<url>
 *      --evo=<url> --dir=<work dir> --timezone=<zone>. It runs J1–J14 on that tree, every step in its own PHP process, and prints one
 *      JSON document: the fake uCRM's request log, every step's result, every flash, every WhatsApp text the fake
 *      Evolution received, the PDF HTML the fake PDF service was handed, the rendered pages of J14 and a digest of
 *      the store tables. tests/test_quote_company.php §S runs it on ce7ac388's tree and on this one and compares.
 *
 * The journey loads ENTRY-POINT files only — lib/Services.php and the includes/post/*.php handlers (with stubs for
 * flash(), redirect() and $auth), the libraries those require themselves, cron_quote_wa.php, cron/kyc_crm_sync.php and
 * public.php over HTTP. It never requires lib/*.php by glob and never lib/QuoteOrganization.php directly, so a changed
 * file that forgets its own require_once fails here instead of being covered by another file's.
 *
 * Every person, number, address, organization and amount is invented (TEST names, example.test, +2567000001xx).
 * Nothing leaves the machine: the plugin's uCRM, WhatsApp and PDF service are the fakes, and proxies are cleared.
 */

const QCJ_SKIP = ['tests', 'docs', 'prototype', 'dishnet-mikrotik-control-plane', 'data', '.git', 'scripts'];
const QCJ_APP_KEY = 'QC-APP-KEY-TEST';
const QCJ_TOKEN   = 'QC-ADMIN-TOKEN-TEST';

// ══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
// HTTP and servers
// ══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════

/** @return array{0:int,1:string,2:mixed,3:string} status, body, decoded JSON, Location */
function qcj_http(string $method, string $url, $body = null, array $headers = [], ?string $jar = null, int $timeout = 90): array
{
    $ch = curl_init($url);
    $loc = '';
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$loc) {
            if (stripos($h, 'Location:') === 0) $loc = trim(substr($h, 9));
            return strlen($h);
        }]);
    if ($jar !== null) { curl_setopt($ch, CURLOPT_COOKIEJAR, $jar); curl_setopt($ch, CURLOPT_COOKIEFILE, $jar); }
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
    $r = curl_exec($ch);
    $c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$r === false ? 0 : $c, (string)$r, json_decode((string)$r, true), $loc];
}

/** The environment every process started here gets: no proxy, ever. */
function qcj_env(array $extra = []): array
{
    $env = array_filter(getenv(), function ($k) { return stripos((string)$k, 'proxy') === false; }, ARRAY_FILTER_USE_KEY);
    return array_merge($env, $extra);
}

/**
 * Start a server on a free port near $base, proved to be ours by $isUp. Returns [proc, port], or [null, 0].
 * $cmd holds {PORT}.
 */
function qcj_serve(string $cmd, callable $isUp, int $base, array $env = []): array
{
    foreach (range(0, 14) as $slot) {
        $port = $base + ((getmypid() * 7 + $slot * 17 + random_int(0, 9)) % 400);
        $p = proc_open(str_replace('{PORT}', (string)$port, $cmd),
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes, null, qcj_env($env));
        if (!is_resource($p)) continue;
        $up = false;
        for ($i = 0; $i < 80 && !$up; $i++) {
            usleep(100000);
            $st = proc_get_status($p);
            if (!$st['running']) break;
            $up = $isUp($port) && proc_get_status($p)['running'];
        }
        if ($up) return [$p, $port];
        proc_terminate($p); proc_close($p);
    }
    return [null, 0];
}

function qcj_stop($proc): void
{
    if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
}

/** The fake uCRM (fixtures/fake_ucrm_quote_org.php): [proc, base url without the API prefix]. */
function qcj_boot_fake(string $fixtures): array
{
    $token = bin2hex(random_bytes(8));
    [$p, $port] = qcj_serve(sprintf('exec php -S 127.0.0.1:{PORT} %s', escapeshellarg($fixtures . '/fake_ucrm_quote_org.php')),
        function (int $port) use ($token): bool {
            $r = qcj_http('GET', "http://127.0.0.1:{$port}/__test/ping", null, [], null, 2)[2];
            return is_array($r) && ($r['marker'] ?? '') === 'FAKE-UCRM-QO' && ($r['token'] ?? '') === $token;
        }, 13000, ['FAKE_UCRM_QO_TOKEN' => $token]);
    if ($p === null) return [null, ''];
    $url = "http://127.0.0.1:{$port}";
    register_shutdown_function(function () use ($port) { @unlink(sys_get_temp_dir() . '/fake_ucrm_qo_' . $port . '.json'); });
    qcj_http('GET', "{$url}/__test/reset");
    return [$p, $url];
}

/** The repository's fake Evolution server: [proc, url]. */
function qcj_boot_evo(string $fixtures): array
{
    [$p, $port] = qcj_serve(sprintf('exec php -S 127.0.0.1:{PORT} %s', escapeshellarg($fixtures . '/fake_evo_server.php')),
        function (int $port): bool {
            return strpos(qcj_http('GET', "http://127.0.0.1:{$port}/__test/state", null, [], null, 2)[1], 'FAKE-EVO-TEST') !== false;
        }, 13500);
    if ($p === null) return [null, ''];
    $url = "http://127.0.0.1:{$port}";
    qcj_http('GET', "{$url}/__test/reset");
    return [$p, $url];
}

/** The fake uCRM's controls. */
function qcj_fake(string $fake, string $path, ?array $body = null): array
{
    $r = qcj_http($body === null ? 'GET' : 'POST', $fake . $path, $body === null ? null : json_encode($body),
        ['Content-Type: application/json']);
    return is_array($r[2]) ? $r[2] : [];
}
function qcj_fake_log(string $fake): array { return qcj_fake($fake, '/__test/log'); }
function qcj_fake_state(string $fake): array { return qcj_fake($fake, '/__test/state'); }
/** The requests of the fake's log, from position $from, as "METHOD path" (query appended when there is one). */
function qcj_reqs(array $log, int $from = 0): array
{
    return array_map(function ($e) {
        return $e['method'] . ' ' . $e['path'] . (($e['query'] ?? '') !== '' ? '?' . $e['query'] : '');
    }, array_slice($log, $from));
}

function qcj_evo_texts(string $evo): array
{
    return (array)(qcj_http('GET', "{$evo}/__test/state")[2]['text_calls'] ?? []);
}

// ══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
// Trees and books
// ══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════

/** A writable copy of the plugin at $src (no tests, docs, data or Git), at $dst. */
function qcj_copy_tree(string $src, string $dst): void
{
    @mkdir($dst, 0700, true);
    foreach (scandir($src) ?: [] as $e) {
        if ($e === '.' || $e === '..' || in_array($e, QCJ_SKIP, true)) continue;
        exec('cp -R ' . escapeshellarg(rtrim($src, '/') . '/' . $e) . ' ' . escapeshellarg($dst . '/') . ' 2>/dev/null');
    }
    @mkdir($dst . '/data', 0700, true);
}

/** The invented organizations a scenario's uCRM holds. */
function qcj_orgs(array $ids): array
{
    $names = [1 => 'TEST Co One', 2 => 'TEST Co Two', 3 => 'TEST Co Three', 5 => 'TEST Co Five', 7 => 'TEST Co Seven'];
    $out = [];
    foreach ($ids as $id) {
        $out[] = ['id' => $id, 'name' => $names[$id] ?? ('TEST Co ' . $id), 'selected' => false, 'currencyCode' => 'UGX',
                  'street1' => 'Plot ' . $id . ' Test Road', 'street2' => '', 'city' => 'Testville ' . $id,
                  'phone' => '+2567000001' . str_pad((string)$id, 2, '0', STR_PAD_LEFT), 'email' => 'org' . $id . '@example.test',
                  'website' => 'org' . $id . '.example.test', 'taxId' => 'TEST-TIN-' . $id, 'registrationNumber' => 'TEST-REG-' . $id];
    }
    return $out;
}

/** An invented uCRM client. */
function qcj_client(int $id, ?int $org, string $last = 'Customer'): array
{
    $c = ['id' => $id, 'firstName' => 'TEST', 'lastName' => $last, 'clientType' => 1, 'isLead' => false,
          'street1' => 'Plot ' . $id . ' Client Lane', 'city' => 'Testville',
          'contacts' => [['name' => 'TEST ' . $last, 'email' => 'client' . $id . '@example.test',
                          'phone' => '+2567000001' . str_pad((string)($id % 100), 2, '0', STR_PAD_LEFT), 'isBilling' => true]],
          'attributes' => []];
    if ($org !== null) $c['organizationId'] = $org;
    return $c;
}

/**
 * The scenarios. tenant is the kyc_config tenant_profile; set is the explicit ucrm_quote_organization_id (null = unset);
 * orgs are the organizations uCRM lists, corg the organization the seeded clients are filed under.
 */
function qcj_scenario(string $name): array
{
    $S = [
        'ug'          => ['tenant' => 'uganda',      'set' => null, 'orgs' => [1, 2], 'corg' => 1, 'selected' => 2],
        'ug2'         => ['tenant' => 'uganda',      'set' => null, 'orgs' => [1, 2], 'corg' => 2, 'selected' => 2],
        'ss'          => ['tenant' => 'south-sudan', 'set' => null, 'orgs' => [2, 7], 'corg' => 2, 'selected' => 7],
        'ss_keyset'   => ['tenant' => 'south-sudan', 'set' => '1',  'orgs' => [2, 7], 'corg' => 2, 'selected' => 7],
        'ug_off'      => ['tenant' => 'uganda',      'set' => '0',  'orgs' => [1, 2], 'corg' => 2, 'selected' => 2],
        'ug_off_word' => ['tenant' => 'uganda',      'set' => 'off', 'orgs' => [1, 2], 'corg' => 2, 'selected' => 2],
    ];
    if (!isset($S[$name])) throw new \InvalidArgumentException('no scenario ' . $name);
    return $S[$name] + ['name' => $name];
}

/**
 * A book: a data directory with its configuration (store row and file), a vault, the plans and kit the forms quote,
 * the staff, and ucrm.json in the tree pointing the plugin at the fake uCRM with the plugin's own key.
 *
 * $o: tree, dir, fake, evo, tenant ('uganda'|'south-sudan'|''), timezone, cfg (merged last), vault (keys for the vault
 * only). The zone comes from the caller: a test file may pin one, a fixture may not (tests/test_timezone.php).
 * @return array ctx: tree, data, vault, errlog, fake, evo, api (the fake's API base), agent, admin (staff ids)
 */
function qcj_book(array $o): array
{
    $tree = rtrim($o['tree'], '/');
    $dir  = rtrim($o['dir'], '/');
    $data = $dir . '/data';
    @mkdir($data, 0700, true);
    $vault = $dir . '/vault.json';
    file_put_contents($vault, json_encode(['config' => (array)($o['vault'] ?? [])]));
    $api = rtrim($o['fake'], '/') . '/api/v2.1';
    file_put_contents($tree . '/ucrm.json', json_encode(['ucrmLocalUrl' => rtrim($o['fake'], '/'), 'pluginAppKey' => QCJ_APP_KEY,
        'pluginDataDir' => $data, 'pluginPublicUrl' => 'http://plugin.example.test/public.php']));
    $tenant = (string)($o['tenant'] ?? '');
    if (!isset($o['timezone']) || !is_string($o['timezone']) || $o['timezone'] === '') {
        throw new InvalidArgumentException('qcj_book(): the caller names the timezone');
    }
    $cfg = ['timezone' => $o['timezone']];
    if ($tenant !== '') {
        $cfg['tenant_profile'] = $tenant;
        $cfg['currency_code']  = $tenant === 'uganda' ? 'UGX' : 'USD';
    }
    $cfg += [
        'data_dir' => $data, 'dry_run_mode' => false,
        'evo_api_url' => (string)($o['evo'] ?? 'http://127.0.0.1:9'), 'evo_api_key' => 'qc-evo-key',
        'evo_instance_support' => 'qc-support', 'evo_instance_account' => 'qc-account', 'evo_instance_sales' => 'qc-sales',
        'whatsapp_admin_phone' => '+256700000190', 'webhook_secret' => 'qc-webhook-secret',
        'customer_emails_enabled' => '0', 'pdf_service_url' => rtrim($o['fake'], '/') . '/__pdf',
    ];
    $cfg = array_merge($cfg, (array)($o['cfg'] ?? []));
    foreach ($cfg as $k => $v) if ($v === null) unset($cfg[$k]);

    $env = ['DN_DATA_DIR' => $data, 'DN_VAULT_FILE' => $vault];
    $seed = qcj_php_raw($tree, $env, $dir . '/seed_errors.log', '
        require_once "$TREE/lib/StoreInterface.php"; require_once "$TREE/lib/JsonStore.php"; require_once "$TREE/lib/SqliteStore.php";
        $s = SqliteStore::create($DATA);
        $s->save("kyc_config.json", $ARGS["cfg"]);
        file_put_contents($DATA . "/kyc_config.json", json_encode($ARGS["cfg"]));
        file_put_contents($DATA . "/webhook_secret", $ARGS["cfg"]["webhook_secret"]);
        // An admin\'s first page runs the piggyback cron after the page; marked as just run, it does not run here.
        file_put_contents($DATA . "/piggyback_last_run.txt", (string)(time() + 10 * 365 * 86400));
        $s->save("subscription_plans.json", [["id" => 1, "name" => "TEST Plan", "customer_price" => 100, "ucrm_product_id" => 12, "is_active" => true]]);
        $s->save("kyc_devices.json", [["id" => 3, "title" => "TEST Kit", "price" => "1500", "ucrm_product_id" => 55]]);
        $pw = password_hash("qc-password-1", PASSWORD_BCRYPT, ["cost" => 4]);
        $admin = $s->appendWithId("retailers.json", ["name" => "TEST Admin", "email" => "admin@example.test", "phone" => "+256700000191",
            "role" => "admin", "is_admin" => true, "is_active" => true, "api_token" => $ARGS["tok_admin"], "token_issued_at" => time(), "password" => $pw]);
        $agent = $s->appendWithId("retailers.json", ["name" => "TEST Agent", "email" => "agent@example.test", "phone" => "+256700000192",
            "role" => "sales", "is_admin" => false, "is_active" => true, "modules" => [], "api_token" => $ARGS["tok_agent"], "token_issued_at" => time(), "password" => $pw]);
        qcj_out(["admin" => (int)$admin["id"], "agent" => (int)$agent["id"]]);
    ', ['cfg' => $cfg, 'tok_admin' => 'QC-TOK-ADMIN-TEST-0001', 'tok_agent' => 'QC-TOK-AGENT-TEST-0002']);
    if (!isset($seed['out']['agent'])) throw new \RuntimeException('the book could not be seeded: ' . substr((string)$seed['raw'], 0, 600));
    return ['tree' => $tree, 'dir' => $dir, 'data' => $data, 'vault' => $vault, 'errlog' => $dir . '/php_errors.log',
            'fake' => rtrim($o['fake'], '/'), 'evo' => (string)($o['evo'] ?? ''), 'api' => $api, 'env' => $env, 'cfg' => $cfg,
            'agent' => $seed['out']['agent'], 'admin' => $seed['out']['admin'],
            'tok_admin' => $seed['args']['tok_admin'], 'tok_agent' => $seed['args']['tok_agent']];
}

/**
 * Point the tree's ucrm.json at this book again. getDataDir() reads pluginDataDir from it before DN_DATA_DIR, so a cron
 * or the served plugin must see the book it is run for when one tree serves several books in turn.
 */
function qcj_point(array $ctx): void
{
    file_put_contents($ctx['tree'] . '/ucrm.json', json_encode(['ucrmLocalUrl' => $ctx['fake'], 'pluginAppKey' => QCJ_APP_KEY,
        'pluginDataDir' => $ctx['data'], 'pluginPublicUrl' => 'http://plugin.example.test/public.php']));
}

// ══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
// Probes: PHP in a fresh process
// ══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════

/**
 * Run $code (PHP, no opening tag) in a fresh process. $TREE, $DATA and $ARGS are set, qcj_out($x) records the answer.
 * @return array{out:mixed,raw:string,rc:int,args:array}
 */
function qcj_php_raw(string $tree, array $env, string $errlog, string $code, array $args = []): array
{
    static $n = 0;
    $n++;
    $dir = sys_get_temp_dir() . '/qcj_probe_' . getmypid();
    @mkdir($dir, 0700, true);
    $f = $dir . '/p' . $n . '_' . bin2hex(random_bytes(3)) . '.php';
    $argFile = $f . '.json';
    file_put_contents($argFile, json_encode($args));
    file_put_contents($f, '<?php
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set("display_errors", "stderr");
ini_set("log_errors", "1");
ini_set("error_log", ' . var_export($errlog, true) . ');
$TREE = ' . var_export(rtrim($tree, '/'), true) . ';
$DATA = ' . var_export((string)($env['DN_DATA_DIR'] ?? ''), true) . ';
$ARGS = json_decode((string)file_get_contents(' . var_export($argFile, true) . '), true);
function qcj_out($x): void { echo "\n__QCJ__" . json_encode($x, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n"; }
' . $code . "\n");
    $cmd = 'cd ' . escapeshellarg($tree) . ' && exec ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($f) . ' 2>&1';
    $p = proc_open($cmd, [1 => ['pipe', 'w']], $pipes, null, qcj_env($env));
    $raw = (string)stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $rc = proc_close($p);
    @unlink($f); @unlink($argFile);
    $out = null;
    $pos = strrpos($raw, "\n__QCJ__");
    if ($pos !== false) {
        $line = substr($raw, $pos + 8);
        $nl = strpos($line, "\n");
        $out = json_decode($nl === false ? $line : substr($line, 0, $nl), true);
    }
    return ['out' => $out, 'raw' => $raw, 'rc' => $rc, 'args' => $args];
}

/** A probe against a book. */
function qcj_php(array $ctx, string $code, array $args = []): array
{
    return qcj_php_raw($ctx['tree'], $ctx['env'], $ctx['errlog'], $code, $args);
}

/**
 * The prelude a POST handler expects from public.php: the store, the configuration, the lazy services (lib/Services.php),
 * and stubs for flash(), redirect() and $auth. redirect() throws QcjRedirect, so the handler stops there as it would.
 */
const QCJ_POST_PRELUDE = '
require_once "$TREE/lib/currency.php";
require_once "$TREE/lib/StoreInterface.php"; require_once "$TREE/lib/JsonStore.php"; require_once "$TREE/lib/SqliteStore.php";
require_once "$TREE/lib/Services.php";
$dataDir = $DATA; $_PLUGIN_ROOT = $TREE;
$store  = SqliteStore::create($dataDir);
$config = $store->load("kyc_config.json") ?: [];
$rbac   = null;
final class QcjRedirect extends \Exception {}
$GLOBALS["__flash"] = [];
function flash(string $m, string $t = "success"): void { $GLOBALS["__flash"][] = ["msg" => $m, "type" => $t]; }
function redirect(string $u): void { throw new QcjRedirect($u); }
if (!function_exists("logActivity")) { function logActivity(...$a): void {} }
final class QcjAuth {
    private $who; public function __construct(array $who) { $this->who = $who; }
    public function requireLogin(): array { return $this->who; }
    public function requireAdmin(): array { if (empty($this->who["is_admin"])) throw new QcjRedirect("denied"); return $this->who; }
}
$__who = $store->findOne("retailers.json", "id", (int)$ARGS["who"]) ?? [];
if (isset($ARGS["who_extra"]) && is_array($ARGS["who_extra"])) $__who = array_merge($__who, $ARGS["who_extra"]);
$auth = new QcjAuth($__who);
$wallet = svc("wallet"); $crm = svc("crm"); $queue = svc("queue"); $notify = svc("notify"); $kyc = svc("kyc");
$_SERVER["REQUEST_METHOD"] = "POST";
$_POST  = $ARGS["post"];
$_FILES = [];
$__to = null;
';

/** One POST handler include (includes/post/<file>), as public.php runs it: the flashes it left and where it sent the browser. */
function qcj_post(array $ctx, string $file, array $post, int $who, array $whoExtra = []): array
{
    $r = qcj_php($ctx, QCJ_POST_PRELUDE . '
try { include $TREE . "/includes/post/" . $ARGS["file"]; } catch (QcjRedirect $e) { $__to = $e->getMessage(); }
qcj_out(["flash" => $GLOBALS["__flash"], "to" => $__to]);
', ['file' => $file, 'post' => $post, 'who' => $who, 'who_extra' => $whoExtra]);
    return (is_array($r['out']) ? $r['out'] : ['flash' => [], 'to' => null]) + ['_raw' => $r['raw']];
}

/** A plugin script (a cron), run the way cron/master.php runs it: from the tree, with the book's data directory. */
function qcj_script(array $ctx, string $script): array
{
    qcj_point($ctx);
    $cmd = 'cd ' . escapeshellarg($ctx['tree']) . ' && exec ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1';
    $p = proc_open($cmd, [1 => ['pipe', 'w']], $pipes, null, qcj_env($ctx['env']));
    $out = (string)stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    return [proc_close($p), $out];
}

/** The store's records, table by table: [table => [records]]. Read straight from SQLite (no plugin code). */
function qcj_tables(string $data, array $tables): array
{
    $out = [];
    $db = $data . '/plugin.sqlite3';
    if (!is_file($db)) return $out;
    $pdo = new \PDO('sqlite:' . $db);
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $have = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(\PDO::FETCH_COLUMN);
    foreach ($tables as $t) {
        if (!in_array($t, $have, true)) { $out[$t] = null; continue; }
        $rows = $pdo->query('SELECT * FROM "' . $t . '" ORDER BY rowid')->fetchAll(\PDO::FETCH_ASSOC);
        $recs = [];
        foreach ($rows as $r) {
            if (isset($r['data']) && is_string($r['data']) && ($d = json_decode($r['data'], true)) !== null && is_array($d)) {
                $d = ['__id' => $r['id'] ?? null] + $d;
                $recs[] = $d;
            } else {
                $recs[] = $r;
            }
        }
        $out[$t] = $recs;
    }
    return $out;
}

// ══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
// The plugin over HTTP
// ══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════

/** Serve $ctx's tree on 127.0.0.1 (exec disabled), proved by a nonce: [proc, base url of public.php]. */
function qcj_serve_plugin(array $ctx): array
{
    qcj_point($ctx);
    $nonce = bin2hex(random_bytes(8));
    file_put_contents($ctx['tree'] . '/__nonce.txt', $nonce);
    [$p, $port] = qcj_serve(sprintf('exec php -d disable_functions=exec -S 127.0.0.1:{PORT} -t %s', escapeshellarg($ctx['tree'])),
        function (int $port) use ($nonce): bool {
            return trim(qcj_http('GET', "http://127.0.0.1:{$port}/__nonce.txt", null, [], null, 2)[1]) === $nonce;
        }, 14000, $ctx['env']);
    @unlink($ctx['tree'] . '/__nonce.txt');
    if ($p === null) return [null, ''];
    return [$p, "http://127.0.0.1:{$port}/public.php"];
}

/** The staff API as a signed-in account (Bearer token). */
function qcj_api(string $base, string $token, string $method, string $action, ?array $body = null, string $qs = ''): array
{
    return qcj_http($method, "{$base}?page=api&action={$action}{$qs}", $body === null ? null : json_encode($body),
        ['Content-Type: application/json', 'Authorization: Bearer ' . $token]);
}

/** Sign in to the panel as $email: the cookie jar. */
function qcj_login(string $base, string $jar, string $email): bool
{
    qcj_http('GET', "{$base}?page=login", null, [], $jar);
    $r = qcj_http('POST', "{$base}?page=login", http_build_query(['action' => 'do_login', 'identifier' => $email,
        'password' => 'qc-password-1']), ['Content-Type: application/x-www-form-urlencoded'], $jar);
    return strpos($r[3], 'page=dashboard') !== false;
}

// ══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
// The journey J1–J14 (§S)
// ══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════

/** The fake uCRM's state for a §S scenario: its organizations, two existing clients, two uCRM-made quotes. */
function qcj_journey_fake_state(array $sc): array
{
    $orgs = qcj_orgs($sc['orgs']);
    foreach ($orgs as $i => $o) $orgs[$i]['selected'] = ((int)$o['id'] === (int)$sc['selected']);
    $c = $sc['corg'];
    $clients = [qcj_client(15, $c, 'Existing'), qcj_client(16, $c, 'Lead'), qcj_client(17, $c, 'Manual'),
                qcj_client(18, $c, 'Retry'), qcj_client(19, $c, 'Lte'), qcj_client(20, $c, 'Kycapp')];
    $quote = function (int $id, int $client) {
        return ['id' => $id, 'number' => 'Q-' . $id, 'clientId' => $client, 'status' => 1, 'createdDate' => '2026-10-20T08:00:00+0300',
                'items' => [['label' => 'TEST Kit', 'quantity' => 1, 'price' => 1500.0, 'total' => 1500.0, 'unit' => 'piece']],
                'total' => 1500.0, 'notes' => 'TEST note {{FOOTER_LOCALITY}} kept literally'];
    };
    return ['organizations' => $orgs, 'clients' => $clients, 'quotes' => ['3401' => $quote(3401, 15), '3402' => $quote(3402, 20)],
            'client_seq' => 900, 'quote_seq' => 76, 'billing_seq' => 3499, 'log' => [], 'pdf_html' => []];
}

/** J1–J14 on one tree. $work is a fresh directory; the fake uCRM is reset to the scenario first. $timezone: the caller's. */
function qcj_journey(string $treeSrc, string $scenario, string $fake, string $evo, string $work, string $timezone): array
{
    $sc = qcj_scenario($scenario);
    $tree = $work . '/plugin';
    qcj_copy_tree($treeSrc, $tree);
    qcj_fake($fake, '/__test/reset');
    qcj_fake($fake, '/__test/set', qcj_journey_fake_state($sc));
    qcj_http('GET', "{$evo}/__test/reset");
    $cfg = ['kyc_quote_send_via_crm' => '1'];
    if ($sc['set'] !== null) $cfg['ucrm_quote_organization_id'] = $sc['set'];
    $ctx = qcj_book(['tree' => $tree, 'dir' => $work, 'fake' => $fake, 'evo' => $evo, 'tenant' => $sc['tenant'], 'timezone' => $timezone,
                     'cfg' => $cfg]);
    $out = ['scenario' => $scenario, 'steps' => [], 'flashes' => []];
    $flash = function (string $step, array $r) use (&$out) {
        foreach ((array)($r['flash'] ?? []) as $f) $out['flashes'][] = ['step' => $step] + (array)$f;
    };
    $agent = (int)$ctx['agent']; $admin = (int)$ctx['admin'];

    // J1 — KYC new customer, credit then cash, through the post_kyc.php kyc_submit include
    qcj_php($ctx, '
        require_once "$TREE/lib/StoreInterface.php"; require_once "$TREE/lib/JsonStore.php"; require_once "$TREE/lib/SqliteStore.php";
        require_once "$TREE/lib/WalletService.php";
        $s = SqliteStore::create($DATA);
        (new WalletService($s, $s->getPdo()))->credit((int)$ARGS["agent"], 1000000.0, "TEST top-up", "test", "qc-topup-1");
        qcj_out(true);', ['agent' => $agent]);
    $kyc = function (string $last, string $phone, string $type, array $extra = []): array {
        return ['action' => 'kyc_submit', 'customer_type' => 'StarLink', 'connectivity_type' => 'New Connection',
                'firstname' => 'TEST', 'lastname' => $last, 'mobile' => $phone, 'email' => '', 'address_1' => 'TEST address',
                'package_choice' => '1', 'device_id' => '3', 'kitQty' => '1', 'sales_type' => $type, 'priority' => 'Low'] + $extra;
    };
    $r = qcj_post($ctx, 'post_kyc.php', $kyc('Credit', '+256700000121', 'Credit'), $agent);  $flash('J1-credit', $r); $out['steps']['J1-credit'] = $r['to'];
    $r = qcj_post($ctx, 'post_kyc.php', $kyc('Cash', '+256700000122', 'Cash'), $agent);      $flash('J1-cash', $r);   $out['steps']['J1-cash'] = $r['to'];
    // … and one that uCRM refuses: saved here, not in uCRM, shown in amber (the warning flash)
    qcj_fake($fake, '/__test/set', ['clients_refuse' => true]);
    $r = qcj_post($ctx, 'post_kyc.php', $kyc('Pending', '+256700000123', 'Credit'), $agent); $flash('J1-pending', $r); $out['steps']['J1-pending'] = $r['to'];
    qcj_fake($fake, '/__test/set', ['clients_refuse' => false]);

    // J2 — KYC existing customer (client 15), through the same include
    $r = qcj_post($ctx, 'post_kyc.php', $kyc('Existing', '+256700000115', 'Credit', ['customer_id' => '15']), $agent);
    $flash('J2', $r); $out['steps']['J2'] = $r['to'];

    // J3 — the retry: an admin's syncOne through the kyc_crm_retry include, and the cron's runDue
    qcj_php($ctx, '
        require_once "$TREE/lib/StoreInterface.php"; require_once "$TREE/lib/JsonStore.php"; require_once "$TREE/lib/SqliteStore.php";
        $s = SqliteStore::create($DATA);
        $items = [["label" => "TEST Plan", "quantity" => 1, "price" => 100.0, "unit" => "month", "productId" => 12]];
        foreach ([[801, "+256700000131", "Retryone"], [802, "+256700000132", "Retrytwo"]] as [$id, $ph, $ln]) {
            $s->append("kyc_applications.json", ["id" => $id, "firstname" => "TEST", "lastname" => $ln, "mobile" => $ph,
                "customer_type" => "StarLink", "connectivity_type" => "New Connection", "sales_type" => "Credit", "priority" => "Low",
                "status" => "new", "crm_client_id" => null, "crm_sync_status" => "pending", "crm_sync_attempts" => 0,
                "username" => "TESTRETRY" . $id, "quote_items" => $items, "quote_ref" => "QREF-" . $id, "submitted_at" => date("Y-m-d H:i:s"),
                "crm_sync_payload" => json_encode(["clientType" => 1, "isLead" => true, "firstName" => "TEST", "lastName" => $ln,
                    "organizationId" => 2, "username" => "TESTRETRY" . $id, "contacts" => [["name" => "TEST", "email" => "", "phone" => $ph]]])]);
        }
        qcj_out(true);');
    $r = qcj_post($ctx, 'post_kyc.php', ['action' => 'kyc_crm_retry', 'app_id' => '801'], $admin); $flash('J3-retry', $r); $out['steps']['J3-retry'] = $r['to'];
    [$rc, $o] = qcj_script($ctx, 'cron/kyc_crm_sync.php');
    $lines = array_values(array_filter(explode("\n", trim($o)), function ($l) { return strpos($l, '[kyc_crm_sync] due ') === 0; }));
    $out['steps']['J3-rundue'] = [$rc, $lines];   // the cron's summary line only: its other lines echo uCRM's answer, clock included

    // J4 — a lead quote (client 16): the box ticked, then unticked; J5 — a manual quote (client 17), ticked and unticked
    qcj_php($ctx, '
        require_once "$TREE/lib/StoreInterface.php"; require_once "$TREE/lib/JsonStore.php"; require_once "$TREE/lib/SqliteStore.php";
        $s = SqliteStore::create($DATA);
        $s->save("leads.json", [["id" => 1, "customer_name" => "TEST Lead", "phone" => "+256700000116", "crm_client_id" => 16,
            "service_type" => "starlink", "status" => "open", "notes" => "", "retailer_id" => (int)$ARGS["agent"]]]);
        qcj_out(true);', ['agent' => $agent]);
    $items = json_encode([['label' => 'TEST Kit', 'quantity' => 1, 'price' => 1500, 'unit' => 'piece']]);
    $lead = function (string $tick) use ($items) { return ['action' => 'send_lead_quote', 'lead_id' => '1', 'quote_items_json' => $items, 'create_crm_quote' => $tick]; };
    $man = function (string $tick) use ($items) { return ['action' => 'send_manual_quote', 'mq_crm_client_id' => '17', 'mq_customer_name' => '',
        'mq_customer_phone' => '', 'mq_create_crm' => $tick, 'mq_note' => '', 'mq_items_json' => $items]; };
    foreach (['J4' => $lead('1'), 'J4u' => $lead('0'), 'J5' => $man('1'), 'J5u' => $man('0')] as $k => $post) {
        $r = qcj_post($ctx, 'post_leads.php', $post, $agent); $flash($k, $r); $out['steps'][$k] = $r['to'];
    }
    // J6 — the lead quote with uCRM refusing the create (the silent plugin-only path where the gate is off); J6m — the
    // manual quote, box ticked, the same refusal (sendManualQuote's own failed-create branch, gated separately)
    qcj_fake($fake, '/__test/set', ['billing_refuse' => true]);
    $r = qcj_post($ctx, 'post_leads.php', $lead('1'), $agent); $flash('J6', $r); $out['steps']['J6'] = $r['to'];
    $r = qcj_post($ctx, 'post_leads.php', $man('1'), $agent);  $flash('J6m', $r); $out['steps']['J6m'] = $r['to'];
    qcj_fake($fake, '/__test/set', ['billing_refuse' => false]);

    // J7 — a Cash Proforma
    $r = qcj_post($ctx, 'post_leads.php', ['action' => 'send_cash_proforma', 'cq_customer_name' => 'TEST Walkin',
        'cq_customer_phone' => '+256700000171', 'cq_amount_paid' => '500', 'cq_items_json' => $items], $agent);
    $flash('J7', $r); $out['steps']['J7'] = $r['to'];

    // J8 — an AI lead into uCRM (UcrmLeadSync, ai_crm_lead_sync on)
    $r = qcj_php($ctx, '
        require_once "$TREE/lib/StoreInterface.php"; require_once "$TREE/lib/JsonStore.php"; require_once "$TREE/lib/SqliteStore.php";
        require_once "$TREE/lib/CrmApiClient.php"; require_once "$TREE/lib/LeadMatcher.php"; require_once "$TREE/lib/UcrmLeadSync.php";
        $s = SqliteStore::create($DATA);
        $leads = $s->load("leads.json") ?? [];
        $leads[] = ["id" => 2, "customer_name" => "TEST Ailead", "phone" => "+256700000181", "service_type" => "starlink", "status" => "open"];
        $s->save("leads.json", $leads);
        $cfg = ($s->load("kyc_config.json") ?: []) + ["ai_crm_lead_sync" => "1"];
        $crm = CrmApiClient::fromUcrm($TREE, $cfg);
        qcj_out((new UcrmLeadSync($s, $cfg, $crm, $s->getPdo()))->syncLead(2));');
    $out['steps']['J8'] = $r['out'];

    // J9 — the fallback quote PDF: uCRM serves none, the plugin draws its own (the HTML is captured by the fake service)
    $r = qcj_php($ctx, '
        require_once "$TREE/lib/StoreInterface.php"; require_once "$TREE/lib/JsonStore.php"; require_once "$TREE/lib/SqliteStore.php";
        require_once "$TREE/lib/CrmApiClient.php"; require_once "$TREE/lib/QuotePdfSource.php";
        $s = SqliteStore::create($DATA);
        $cfg = $s->load("kyc_config.json") ?: [];
        $crm = CrmApiClient::fromUcrm($TREE, $cfg);
        $client = $crm->get("clients/15") ?: [];
        [$pdf, $src] = QuotePdfSource::fetch($crm, $DATA, $cfg, 3401, $client);
        qcj_out(["bytes" => strlen($pdf), "source" => $src]);');
    $out['steps']['J9'] = $r['out'];

    // J10 — the preview message (no client)
    $r = qcj_php($ctx, '
        require_once "$TREE/lib/StoreInterface.php"; require_once "$TREE/lib/JsonStore.php"; require_once "$TREE/lib/SqliteStore.php";
        require_once "$TREE/lib/currency.php"; require_once "$TREE/lib/QuotationService.php";
        $s = SqliteStore::create($DATA);
        $q = new QuotationService($s, $DATA, $s->load("kyc_config.json") ?: []);
        qcj_out($q->buildProformaMessage("PREVIEW-001", "TEST Preview", [["label" => "TEST Kit", "quantity" => 1, "price" => 1500, "unit" => "piece"]], 1500.0, ["type" => "Quotation", "agent" => "TEST Agent"]));');
    $out['steps']['J10'] = $r['out'];

    // J11 — cron_quote_wa.php: Flow A (a KYC application whose quote is waiting) and Flow B (uCRM-made quotes)
    qcj_php($ctx, '
        require_once "$TREE/lib/StoreInterface.php"; require_once "$TREE/lib/JsonStore.php"; require_once "$TREE/lib/SqliteStore.php";
        $s = SqliteStore::create($DATA);
        $s->append("kyc_applications.json", ["id" => 803, "firstname" => "TEST", "lastname" => "Kycapp", "mobile" => "+256700000120",
            "crm_client_id" => "20", "crm_sync_status" => "synced", "status" => "synced", "quote_id" => 3402, "quote_ref" => "Q-3402",
            "quote_created" => true, "wa_quote_pending" => true, "wa_quote_phone" => "+256700000120",
            "wa_quote_deferred_at" => date("Y-m-d H:i:s", time() - 3600), "retailer_id" => (int)$ARGS["agent"], "retailer_name" => "TEST Agent"]);
        $st = $s->load("quote_wa_state.json") ?? [];
        $st["sent_ids"] = [3402];   // Flow B: Q-3402 is Flow A\'s; Q-3401 is a uCRM-made quote for client 15
        $s->save("quote_wa_state.json", $st);
        qcj_out(true);', ['agent' => $agent]);
    [$rc, $o] = qcj_script($ctx, 'cron_quote_wa.php');
    $out['steps']['J11'] = $rc;

    // J12–J14 over HTTP on a served copy of the tree
    [$srv, $base] = qcj_serve_plugin($ctx);
    $out['served'] = $srv !== null;
    $out['served_base'] = $base;   // a run's own address; the comparison takes it out of the pages
    if ($srv !== null) {
        // J12 — the debug retry_quote, as an admin, for an application with a uCRM client and no quote
        qcj_php($ctx, '
            require_once "$TREE/lib/StoreInterface.php"; require_once "$TREE/lib/JsonStore.php"; require_once "$TREE/lib/SqliteStore.php";
            $s = SqliteStore::create($DATA);
            $s->append("kyc_applications.json", ["id" => 804, "firstname" => "TEST", "lastname" => "Retry", "mobile" => "+256700000118",
                "crm_client_id" => "18", "crm_sync_status" => "synced", "status" => "synced", "package_choice" => 1, "quote_created" => false,
                "retailer_name" => "TEST Agent"]);
            $c = $s->load("kyc_config.json") ?: []; $c["crm_auth_token"] = $ARGS["tok"]; $s->save("kyc_config.json", $c);
            qcj_out(true);', ['tok' => QCJ_TOKEN]);
        $r = qcj_api($base, $ctx['tok_admin'], 'GET', 'retry_quote', null, '&app_id=804');
        $out['steps']['J12'] = [$r[0], $r[2]];
        qcj_php($ctx, '
            require_once "$TREE/lib/StoreInterface.php"; require_once "$TREE/lib/JsonStore.php"; require_once "$TREE/lib/SqliteStore.php";
            $s = SqliteStore::create($DATA);
            $c = $s->load("kyc_config.json") ?: []; unset($c["crm_auth_token"]); $s->save("kyc_config.json", $c);
            qcj_out(true);');
        // J13 — lte_create_subscriber with a uCRM id: the create accepted, then refused (the WhatsApp-only fallback)
        $lte = function (string $name, string $phone) {
            return ['name' => $name, 'phone' => $phone, 'ucrm_id' => 19, 'amount_paid' => 300];
        };
        $r = qcj_api($base, $ctx['tok_admin'], 'POST', 'lte_create_subscriber', $lte('TEST Lteone', '+256700000141'));
        $out['steps']['J13-accepted'] = [$r[0], $r[2]['status'] ?? null, $r[2]['data']['_quote_error'] ?? null, $r[2]['data']['_quote_ref'] ?? null];
        qcj_fake($fake, '/__test/set', ['billing_refuse' => true]);
        $r = qcj_api($base, $ctx['tok_admin'], 'POST', 'lte_create_subscriber', $lte('TEST Ltetwo', '+256700000142'));
        $out['steps']['J13-refused'] = [$r[0], $r[2]['status'] ?? null, $r[2]['data']['_quote_error'] ?? null, $r[2]['data']['_quote_ref'] ?? null];
        qcj_fake($fake, '/__test/set', ['billing_refuse' => false]);
        // J14 — the three changed tabs, rendered for an admin
        $jar = $work . '/jar.txt';
        $out['login'] = qcj_login($base, $jar, 'admin@example.test');
        $out['pages'] = [];
        foreach (['applications' => 'page=dashboard&tab=applications', 'kyc_form' => 'page=dashboard&tab=form',
                  'lte_dashboard' => 'page=dashboard&tab=lte_dashboard'] as $k => $qs) {
            $out['pages'][$k] = qcj_http('GET', "{$base}?{$qs}", null, [], $jar)[1];
        }
        qcj_stop($srv);
    }

    // What was said and done
    $fs = qcj_fake_state($fake);
    $out['fake_log'] = $fs['log'] ?? [];
    $out['pdf_html'] = $fs['pdf_html'] ?? [];
    $out['texts'] = array_map(function ($t) { return ['number' => (string)($t['number'] ?? ''), 'text' => (string)($t['text'] ?? '')]; },
        qcj_evo_texts($evo));
    $out['tables'] = qcj_tables($ctx['data'], ['kyc_applications', 'leads', 'quotes_log', 'wa_sent_quotes', 'payment_collections',
        'passbook', 'lte_subscribers']);
    // The wallets: each account's balance (WalletService keeps it on the retailers row; the row's token and password
    // hash are the book's own and are not compared).
    $out['tables']['wallets'] = array_map(function ($r) { return ['id' => $r['__id'] ?? null, 'wallet' => $r['wallet'] ?? null]; },
        (array)(qcj_tables($ctx['data'], ['retailers'])['retailers'] ?? []));
    return $out;
}

// ── run directly: the journey on one tree, one JSON document on stdout ──────────────────────────────────────────────
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $o = getopt('', ['root:', 'scenario:', 'fake:', 'evo:', 'dir:', 'timezone:']);
    foreach (['root', 'scenario', 'fake', 'evo', 'dir', 'timezone'] as $k) {
        if (empty($o[$k])) { fwrite(STDERR, "quote_company_journey.php: --{$k} is required\n"); exit(2); }
    }
    @mkdir((string)$o['dir'], 0700, true);
    $doc = qcj_journey(rtrim((string)$o['root'], '/'), (string)$o['scenario'], (string)$o['fake'], (string)$o['evo'], rtrim((string)$o['dir'], '/'),
        (string)$o['timezone']);
    echo json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRETTY_PRINT), "\n";
    exit(0);
}
