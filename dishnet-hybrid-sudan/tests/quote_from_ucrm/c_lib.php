<?php
declare(strict_types=1);
/**
 * Shared by section C of tests/test_quote_from_ucrm.php (5.18.102 D14, docs/71 §R.4.8, §R.9.1 "C, Make the
 * quotation"). TEST ONLY.
 *
 * qfu_c_servers() starts, once per judged tree, two php -S processes from this checkout, each with four workers:
 *   - c_proxy.php, between the plugin's uCRM clients and the fake uCRM (fixtures/fake_ucrm_kyc.php): it forwards every
 *     request, so the fake's log is still the record of what reached uCRM, and holds, fails or re-answers one request
 *     on a control file (qfu_c_ctl());
 *   - c_router.php, which runs the judged tree's own handlers: the Orders card's POST (includes/post/post_sync.php,
 *     kyc_make_quote) and the debug retry_quote (includes/api/api_cron_debug.php), on a data directory the section
 *     seeded (qfu_c_seed()).
 * Both prove they are this run's (a token only this process knows) before they are used. The tree they run is one copy
 * of the judged tree's lib/, profiles/, includes/ and tabs/, with a ucrm.json naming the proxy, so svc('crm') is the
 * plugin's own client, read the way lib/Services.php reads it.
 *
 * Every person, number, address and document is fictitious (Test names, example.test, +256 700 00x xxx); nothing leaves
 * the machine.
 */

/** The accounts the seeded store holds: an active admin (id 1), and an agent who is not one (id 7). */
const QFU_C_ADMIN = ['id' => 1, 'name' => 'Test Admin', 'email' => 'admin@example.test', 'phone' => '+256700009001',
                     'role' => 'admin', 'is_admin' => true, 'is_active' => true];
const QFU_C_AGENT = ['id' => 7, 'name' => 'Test Agent', 'email' => 'agent@example.test', 'phone' => '+256700009007',
                     'role' => 'sales', 'is_admin' => false, 'is_active' => true];
/** The handler's flashes (docs/71 §R.4.8), fixed text plus the application's id. */
const QFU_C_FLASH_SECURITY = 'The security check failed, so nothing was changed. Open the Orders screen again and retry.';
const QFU_C_FLASH_EXTRA    = 'The request carried more than the application\'s number, so nothing was changed. Open the Orders screen again and retry.';

/** @return array{router:string,proxy:string,ctl:string,tree:string,port:int} the servers and the tree, once per judged tree */
function qfu_c_servers(QfuCtx $c): array
{
    static $memo = [];
    if (isset($memo[$c->root])) return $memo[$c->root];
    $kyc = $c->fake('kyc');
    $ctl = $c->dir('c-ctl');
    $tree = $c->tree(['includes', 'tabs']);
    $start = function (string $file, int $base, array $env) use ($c): array {
        $token = bin2hex(random_bytes(8));
        foreach (range(0, 14) as $slot) {
            $port = $base + (getmypid() * 11 + $slot * 31) % 390;
            if (QfuCtx::http("http://127.0.0.1:{$port}/__c/ping", null, 2) !== null) continue;
            $p = proc_open(sprintf('exec php -S 127.0.0.1:%d %s', $port, escapeshellarg(__DIR__ . '/' . $file)),
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null,
                ['PHP_CLI_SERVER_WORKERS' => '4', 'QFU_C_TOKEN' => $token] + $env + qfu_c_env());
            for ($i = 0; $i < 50; $i++) {
                usleep(100000);
                $r = QfuCtx::http("http://127.0.0.1:{$port}/__c/ping", null, 2);
                if (is_array($r) && ($r['token'] ?? '') === $token) {
                    register_shutdown_function(function () use ($p) { qfu_c_stop($p); });
                    return [$port, $p];
                }
            }
            qfu_c_stop($p);
        }
        return [0, null];
    };
    [$pp] = $start('c_proxy.php', 13100, ['QFU_C_UPSTREAM' => "http://127.0.0.1:{$kyc}", 'QFU_C_CTL' => $ctl]);
    [$rp] = $start('c_router.php', 13600, []);
    file_put_contents($tree . '/ucrm.json', json_encode(['ucrmLocalUrl' => "http://127.0.0.1:{$pp}", 'pluginAppKey' => 'FAKE-PLUGIN-KEY',
                                                        'ucrmPublicUrl' => "http://127.0.0.1:{$pp}"]));
    return $memo[$c->root] = ['router' => $rp ? "http://127.0.0.1:{$rp}" : '', 'proxy' => $pp ? "http://127.0.0.1:{$pp}" : '',
                              'ctl' => $ctl, 'tree' => $tree, 'port' => $rp];
}

/** The environment the servers run in: the caller's, without any proxy setting (nothing may leave the machine). */
function qfu_c_env(): array
{
    return array_filter(getenv(), function ($k) { return stripos((string)$k, 'proxy') === false; }, ARRAY_FILTER_USE_KEY);
}

/** Stop a php -S started here, and the workers it forked (they share its process group only by inheritance). */
function qfu_c_stop($p): void
{
    if (!is_resource($p)) return;
    $st = proc_get_status($p);
    if (!empty($st['pid'])) exec('pkill -TERM -P ' . (int)$st['pid'] . ' 2>/dev/null');
    proc_terminate($p);
    proc_close($p);
}

/** Set (true) or clear (false) one of the proxy's controls: hold, list_down, quote_id (a string: its text). */
function qfu_c_ctl(array $srv, string $what, $on): void
{
    $f = $srv['ctl'] . '/' . $what;
    if ($on === false) { @unlink($f); if ($what === 'hold') @unlink($srv['ctl'] . '/arrived'); return; }
    file_put_contents($f, is_string($on) ? $on : '1');
}

/**
 * A data directory for the router: the default rows and links (or $o's), kyc_config.json, the two staff accounts (or
 * $o['retailers']), the day's form secret, and $apps. Returns the directory.
 */
function qfu_c_seed(QfuCtx $c, array $apps, array $o = []): string
{
    $ret = $o['retailers'] ?? [QFU_C_ADMIN, QFU_C_AGENT];
    unset($o['retailers']);
    $r = $c->child('
$s->save("retailers.json", json_decode(' . var_export(json_encode($ret), true) . ', true));
file_put_contents($dir . "/csrf_secret.key", str_repeat("ab", 32));
return ["ok" => true];
', ['apps' => $apps] + $o);
    if (empty($r['value']['ok'])) throw new \RuntimeException('qfu_c_seed: ' . substr($r['out'] . $r['errlog'], 0, 600));
    return $r['dir'];
}

/** A copy of a data directory (the store closed), for the same application quoted by another entry point. */
function qfu_c_copy(QfuCtx $c, string $dir): string
{
    $to = $c->dir('c-copy');
    exec('cp -R ' . escapeshellarg($dir) . '/. ' . escapeshellarg($to));
    return $to;
}

/** Every application row, by id, read straight from the store's table (the store holds no lock between requests). */
function qfu_c_apps(string $dir): array
{
    $out = [];
    try {
        $pdo = new \PDO('sqlite:' . $dir . '/plugin.sqlite3');
        foreach ($pdo->query('SELECT id, data FROM kyc_applications ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[(int)$row['id']] = json_decode((string)$row['data'], true);
        }
    } catch (\Throwable $e) {
        return ['__error' => $e->getMessage()];
    }
    return $out;
}

/** One application's row, or [] when it is not there. */
function qfu_c_app(string $dir, int $id): array
{
    $a = qfu_c_apps($dir);
    return is_array($a[$id] ?? null) ? $a[$id] : [];
}

/** Write one application's row as given (a claim aged by hand, say). */
function qfu_c_put(string $dir, array $app): void
{
    $pdo = new \PDO('sqlite:' . $dir . '/plugin.sqlite3');
    $pdo->prepare('UPDATE kyc_applications SET data = ? WHERE id = ?')->execute([json_encode($app, JSON_UNESCAPED_UNICODE), (int)$app['id']]);
}

/** Replace a whole collection of the store (the link records, the retailers), as SqliteStore keeps a record table. */
function qfu_c_table(QfuCtx $c, string $dir, string $file, array $rows): void
{
    $c->child('$s->save(' . var_export($file, true) . ', json_decode(' . var_export(json_encode($rows), true) . ', true)); return true;',
        ['dir' => $dir, 'tree' => qfu_c_servers($c)['tree']]);
}

/**
 * Write one case file and send one request to the router. $case: entry (make|retry|token), data, session (a staff
 * record or null), session_age, me2, faults_handler, faults_kyc. $req: method, fields (a POST's, form-encoded; a value
 * given as null is not sent), query, headers (null: the Orders screen's own — Sec-Fetch-Site same-origin and an Origin
 * naming the router's host). Returns code, body, location, the router's record of the request, its flash, and the PHP log.
 */
function qfu_c_req(QfuCtx $c, array $srv, array $case, array $req = []): array
{
    $cd = $c->dir('c-case');
    $case += ['root' => $srv['tree'], 'result' => $cd . '/result.json', 'errlog' => $cd . '/php_errors.log'];
    file_put_contents($cd . '/case.json', json_encode($case));
    $h = $req['headers'] ?? ['Sec-Fetch-Site: same-origin', 'Origin: ' . $srv['router']];
    $h[] = 'X-Qfu-Case: ' . $cd . '/case.json';
    $url = $srv['router'] . '/public.php' . (isset($req['query']) ? '?' . $req['query'] : '');
    $ch = curl_init($url);
    $hdr = new \stdClass();
    $hdr->loc = '';
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_PROXY => '',
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use ($hdr) { if (stripos($line, 'Location:') === 0) $hdr->loc = trim(substr($line, 9)); return strlen($line); }]);
    if (isset($req['fields'])) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, qfu_c_form($req['fields']));
        $h[] = 'Content-Type: application/x-www-form-urlencoded';
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
    if (!empty($req['async'])) return ['handle' => $ch, 'case' => $case, 'hdr' => $hdr];
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return qfu_c_answer($case, $code, $body === false ? '' : (string)$body, $hdr->loc);
}

function qfu_c_answer(array $case, int $code, string $body, string $loc): array
{
    $res = json_decode((string)@file_get_contents($case['result']), true) ?: [];
    $flash = is_array($res['flash'] ?? null) ? (string)($res['flash']['msg'] ?? '') : '';
    return ['code' => $code, 'body' => $body, 'json' => json_decode($body, true), 'location' => $loc, 'result' => $res,
            'flash' => $flash, 'flash_type' => is_array($res['flash'] ?? null) ? (string)($res['flash']['type'] ?? '') : '',
            'errlog' => (string)@file_get_contents($case['errlog'])];
}

/** A form body: nested arrays as PHP reads them (items[0][productId]=…); a null value is not sent. */
function qfu_c_form(array $fields): string
{
    return http_build_query(array_filter($fields, function ($v) { return $v !== null; }));
}

/** The day's form token for a data directory, as csrfToken() makes it there. */
function qfu_c_token(QfuCtx $c, array $srv, string $dir): string
{
    return trim(qfu_c_req($c, $srv, ['entry' => 'token', 'data' => $dir])['body']);
}

/**
 * Press Make the quotation: action, _csrf and app_id, as the Orders card's form posts them, and anything $more adds
 * (a key given as null removes one). $o: session (default the admin), session_age, faults_handler, faults_kyc, headers.
 */
function qfu_c_press(QfuCtx $c, array $srv, string $dir, int $appId, array $more = [], array $o = []): array
{
    $fields = array_merge(['action' => 'kyc_make_quote', '_csrf' => qfu_c_token($c, $srv, $dir), 'app_id' => (string)$appId], $more);
    $case = ['entry' => 'make', 'data' => $dir, 'session' => array_key_exists('session', $o) ? $o['session'] : QFU_C_ADMIN,
             'session_age' => $o['session_age'] ?? 5, 'faults_handler' => $o['faults_handler'] ?? [], 'faults_kyc' => $o['faults_kyc'] ?? []];
    return qfu_c_req($c, $srv, $case, ['fields' => $fields, 'headers' => $o['headers'] ?? null, 'async' => $o['async'] ?? false]);
}

/** retry_quote for an application, as an admin's API request: $o me2 (the API guard's record), faults. */
function qfu_c_retry(QfuCtx $c, array $srv, string $dir, int $appId, array $o = []): array
{
    $case = ['entry' => 'retry', 'data' => $dir, 'me2' => array_key_exists('me2', $o) ? $o['me2'] : QFU_C_ADMIN,
             'faults_handler' => $o['faults_handler'] ?? [], 'faults_kyc' => $o['faults_kyc'] ?? []];
    return qfu_c_req($c, $srv, $case, ['query' => 'page=api&action=retry_quote&app_id=' . $appId, 'headers' => [], 'async' => $o['async'] ?? false]);
}

/** Wait for an async request (qfu_c_press/qfu_c_retry with 'async'), driving it until $until() holds or it ends. */
function qfu_c_start(array $a): array
{
    $m = curl_multi_init();
    curl_multi_add_handle($m, $a['handle']);
    curl_multi_exec($m, $running);
    return $a + ['multi' => $m];
}
function qfu_c_until(array $a, callable $until, int $tenths = 300): bool
{
    for ($i = 0; $i < $tenths; $i++) {
        clearstatcache();
        curl_multi_exec($a['multi'], $running);
        if ($until()) return true;
        if (!$running) return (bool)$until();
        usleep(100000);
    }
    return false;
}
function qfu_c_finish(array $a): array
{
    do { curl_multi_exec($a['multi'], $running); if ($running) curl_multi_select($a['multi'], 0.2); } while ($running);
    $body = (string)curl_multi_getcontent($a['handle']);
    $code = (int)curl_getinfo($a['handle'], CURLINFO_HTTP_CODE);
    $loc  = (string)$a['hdr']->loc;
    curl_multi_remove_handle($a['multi'], $a['handle']);
    curl_close($a['handle']);
    curl_multi_close($a['multi']);
    return qfu_c_answer($a['case'], $code, $body, $loc);
}

/**
 * Render the Orders card (tabs/sales/applications.php) of the router's tree for $apps, as the dashboard includes it:
 * $config the book's, $isAdmin as given, h()/csrfField() as public.php defines them. Returns the HTML.
 */
function qfu_c_card(QfuCtx $c, array $srv, array $apps, array $cfg, bool $isAdmin): string
{
    $r = $c->child('
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
if (!function_exists("h")) { function h($s) { return htmlspecialchars((string)($s ?? ""), ENT_QUOTES, "UTF-8"); } }
if (!function_exists("csrfToken")) { function csrfToken() { return "TEST-CSRF"; } }
if (!function_exists("csrfField")) { function csrfField() { return "<input type=\"hidden\" name=\"_csrf\" value=\"TEST-CSRF\">"; } }
require_once $root . "/lib/currency.php";
$config = $s->load("kyc_config.json"); $dataDir = $dir; $isAdmin = ' . ($isAdmin ? 'true' : 'false') . ';
$_SERVER["HTTP_HOST"] = "crm.example.test"; $_GET = [];
$myApps = json_decode(' . var_export(json_encode($apps), true) . ', true);
ob_start(); include $root . "/tabs/sales/applications.php"; $html = ob_get_clean();
return ["html" => $html];
', ['tree' => $srv['tree'], 'cfg' => $cfg, 'links' => null, 'tenant' => (string)($cfg['tenant_profile'] ?? 'uganda')]);
    return (string)($r['value']['html'] ?? ('NO OUTPUT ' . substr($r['errlog'], 0, 1500) . ' … ' . substr($r['out'], -1500)));
}

/** The Make the quotation forms in a rendered page: each one's posted fields (name => value) and its button. */
function qfu_c_buttons(string $html): array
{
    $out = [];
    if (!preg_match_all('#<form\b[^>]*>(?:(?!</form>).)*name="action" value="kyc_make_quote"(?:(?!</form>).)*</form>#s', $html, $m)) return [];
    foreach ($m[0] as $form) {
        preg_match_all('#<input\b[^>]*\bname="([^"]+)"[^>]*\bvalue="([^"]*)"#', $form, $in, PREG_SET_ORDER);
        $fields = [];
        foreach ($in as $x) $fields[$x[1]] = html_entity_decode($x[2], ENT_QUOTES);
        preg_match('#<button\b([^>]*)>(.*?)</button>#s', $form, $b);
        $out[] = ['form' => $form, 'fields' => $fields, 'button_attrs' => $b[1] ?? '', 'label' => trim(strip_tags($b[2] ?? ''))];
    }
    return $out;
}

/** The record with the times that differ from run to run named, for comparing two writes (keys kept). */
function qfu_c_untimed($v)
{
    if (is_array($v)) { foreach ($v as $k => $x) $v[$k] = qfu_c_untimed($x); return $v; }
    if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/', $v)) return 'TS';
    return $v;
}

/** What one request changed on a record: [key => [before, after]] for every key added, removed or changed. */
function qfu_c_delta(array $before, array $after): array
{
    $d = [];
    foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $k) {
        $b = array_key_exists($k, $before) ? $before[$k] : '<absent>';
        $a = array_key_exists($k, $after) ? $after[$k] : '<absent>';
        if ($b !== $a) $d[$k] = [$b, $a];
    }
    ksort($d);
    return $d;
}
