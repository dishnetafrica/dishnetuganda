<?php
/**
 * The operator app, served — docs/127 §H (phase 3).
 *
 * PROVED here, by execution over real HTTP:
 *   - plugin/bin/serve-app.php, started with ONLY the four variables the
 *     manifest names, serves the app's files and passes exactly the sign-in
 *     routes and /api/v1/me… to public/index.php (H-1);
 *   - it answers its own 404 for the Admin API, /internal/*, index.php, any
 *     .php file, traversal in every spelling tried, a symlink out of its
 *     directory, a file of any other type, and every other path (H-2);
 *   - every answer carries the same security headers, its 404s and the API's
 *     answers included (H-3);
 *   - serve.php answers 404 for the app's whole surface while its own Admin API
 *     answers: two processes, each blind to the other's (H-1);
 *   - a person signs in with the code the WORKER sent — nothing in any answer
 *     carries it — creates a plan in UGX, is refused another's capability as
 *     403, and signs out (H-4, H-5, H-6, H-9);
 *   - the manifest's app section is what is served, route for route (H-12).
 *
 * PROVED by reading the bundle: no inline script, handler or style and no
 * third-party URL (H-3); one connection, in api.js; the token in sessionStorage
 * and nowhere else (H-4); none of the prototype's mock data; no
 * Idempotency-Key (H-8); the server's own numbers and the review's words (H-7).
 *
 * NOT PROVED, and not claimed: how a real browser renders it (the headless
 * Chromium run is recorded in docs/127 §I), that an SMS reaches a phone,
 * anything on staging, a router, or production.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/admin_identity_double.php';

use Dn\Admin\OnboardingAdmin;
use Dn\Admin\StaffIdentity;
use Dn\Admin\StaffRole;
use Dn\Api\AdminRoutes;
use Dn\Api\Routes;
use Dn\Auth\Authenticator;
use Dn\Auth\OpCapability;
use Dn\Db\Database;
use Dn\Jobs\SmsWorker;
use Dn\Notify\SmsResult;
use Dn\Notify\SmsSender;

/** Records every message; the "phone" of this suite. */
final class AppSuiteSms implements SmsSender
{
    /** @var list<array{to:string,message:string}> */
    public array $sent = [];
    public function bindingName(): string { return 'recording-test-double'; }
    public function isConfigured(): bool  { return true; }
    public function send(string $to, string $message): SmsResult
    {
        $this->sent[] = ['to' => $to, 'message' => $message];
        return SmsResult::sent('APP-' . count($this->sent));
    }
}

$root = dirname(__DIR__);
$man  = json_decode((string) file_get_contents($root . '/plugin/plugin.json'), true, 512, JSON_THROW_ON_ERROR);
$app  = $man['app'] ?? [];
$ins  = Database::inspector();      // BYPASSRLS fixture identity: sets up state, proves nothing by itself
$ids  = seed_two_customers($ins);
$A    = $ids['A'];

$stripJs = static function (string $s): string {
    $s = (string) preg_replace('#/\*.*?\*/#s', '', $s);
    return (string) preg_replace('#(^|[^:])//.*$#m', '$1', $s);
};
is_(trim($stripJs("/* hidden */ kept // trailing")), 'kept',
    'META: the JS comment stripper removes both comment forms before any guard reads code');

/** The manifest's allow-list, as a predicate: the one exact path, or under a prefix. */
$passes = static function (string $path) use ($app): bool {
    if (in_array($path, $app['api']['exact'] ?? [], true)) { return true; }
    foreach ($app['api']['prefixes'] ?? [] as $p) { if (str_starts_with($path, $p)) { return true; } }
    return false;
};
$routesOf = static function (object $router): array {
    $p = new ReflectionProperty($router, 'routes');
    $p->setAccessible(true);
    return array_map(static fn(array $r): array => [$r[0], $r[1]], $p->getValue($router));
};

/** A free loopback port, then a server started with EXACTLY $env, from the package root. */
$start = static function (string $routerScript, array $env) use ($root): array {
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $port  = (int) substr((string) stream_socket_get_name($probe, false), strrpos((string) stream_socket_get_name($probe, false), ':') + 1);
    fclose($probe);
    $log   = (string) tempnam(sys_get_temp_dir(), 'dnb-app-srv');
    $vars  = '';
    foreach ($env as $k => $v) { $vars .= ' ' . $k . '=' . escapeshellarg((string) $v); }
    // The working directory is the PACKAGE ROOT, as in a deployment: were the
    // router ever to fall through to PHP's own file server, src/ and the
    // migrations would be one request away. That is what the 404s below test.
    $pid = (int) shell_exec('cd ' . escapeshellarg($root) . ' && env -i' . $vars . ' ' . escapeshellarg(PHP_BINARY)
        . " -S 127.0.0.1:{$port} " . escapeshellarg($root . '/' . $routerScript)
        . ' > ' . escapeshellarg($log) . ' 2>&1 & echo $!');
    $up = false;
    for ($i = 0; $i < 100 && !$up; $i++) {
        $s = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
        if ($s) { fclose($s); $up = true; } else { usleep(50_000); }
    }
    register_shutdown_function(static function () use ($pid, $log) {
        if ($pid > 0) { @shell_exec("kill {$pid} 2>/dev/null"); }
        @unlink($log);
    });
    return ['port' => $port, 'pid' => $pid, 'log' => $log, 'up' => $up];
};

/** One request, sent byte for byte: no client library normalises the path first. */
$raw = static function (int $port, string $method, string $path, array $headers = [], string $body = ''): array {
    $s = @fsockopen('127.0.0.1', $port, $en, $es, 5);
    if (!$s) { return ['status' => 0, 'headers' => [], 'body' => '', 'json' => null]; }
    stream_set_timeout($s, 15);
    $req = "{$method} {$path} HTTP/1.0\r\nHost: 127.0.0.1:{$port}\r\n";
    foreach ($headers as $k => $v) { $req .= "{$k}: {$v}\r\n"; }
    if ($method !== 'GET') { $req .= 'Content-Length: ' . strlen($body) . "\r\n"; }
    fwrite($s, $req . "\r\n" . $body);
    $resp = (string) stream_get_contents($s);
    fclose($s);
    [$head, $b] = array_pad(explode("\r\n\r\n", $resp, 2), 2, '');
    $lines  = explode("\r\n", $head);
    $status = preg_match('#^HTTP/\S+\s+(\d{3})#', $lines[0] ?? '', $m) ? (int) $m[1] : 0;
    $h = [];
    foreach (array_slice($lines, 1) as $l) {
        if (str_contains($l, ':')) { [$k, $v] = explode(':', $l, 2); $h[strtolower(trim($k))] = trim($v); }
    }
    return ['status' => $status, 'headers' => $h, 'body' => $b, 'json' => json_decode($b, true)];
};

$appEnv = [];
foreach ($app['config'] ?? [] as $k) { $appEnv[$k] = getenv($k) ?: ''; }

// ===========================================================================
t('1. THE MANIFEST — the app section says what is served');
is_($app['entrypoint'] ?? null, 'plugin/bin/serve-app.php', 'the app entry point is serve-app.php');
is_(is_file($root . '/' . ($app['entrypoint'] ?? '-')), true, 'and it exists');
is_($app['front_controller'] ?? null, 'public/index.php', 'the front controller is public/index.php');
is_(is_file($root . '/public/index.php'), true, 'and it exists');
is_($app['config'] ?? null, ['DNB_DSN', 'DNB_APP_PASS', 'DNB_TOKEN_PEPPER', 'DNB_SECRET_KEY'],
    'the app needs exactly four variables — no Admin, worker, staff or RADIUS credential (H-1)');
is_(array_filter($appEnv, static fn($v) => $v === ''), [], 'and this run has all four to give it');
is_($app['api']['exact'] ?? null, ['/api/v1/me'], 'one exact API path');
is_($app['api']['prefixes'] ?? null, ['/api/v1/auth/', '/api/v1/me/'], 'and two API prefixes');
is_($app['static'] ?? null, ['/' => 'public/app/index.html', '/app/' => 'public/app/', '/pwa/' => 'public/pwa/'],
    'static files come from public/app and public/pwa only');
$srv = (string) file_get_contents($root . '/plugin/bin/serve-app.php');
preg_match('/\$types\s*=\s*\[(.*?)\];/s', $srv, $tm);
preg_match_all("/'([a-z]+)'\s*=>/", $tm[1] ?? '', $tk);
$declaredTypes = $app['static_types'] ?? []; $servedTypes = $tk[1];
sort($declaredTypes); sort($servedTypes);
is_($servedTypes, $declaredTypes, 'the declared static types are exactly the ones serve-app.php serves');
is_(in_array('php', $servedTypes, true), false, 'and php is not one of them');

// Route for route: what the manifest lets through is what the front controller
// serves, minus /internal/*. Compared against the router the code builds.
$served  = $routesOf(Routes::build(new Authenticator(Database::app())));
$outside = []; $internal = [];
foreach ($served as [$method, $pattern]) {
    if (str_starts_with($pattern, '/internal/')) { $internal[] = "{$method} {$pattern}"; continue; }
    if (!$passes($pattern)) { $outside[] = "{$method} {$pattern}"; }
}
is_($outside, [], 'every customer-API route the front controller serves is inside the allow-list');
is_($internal, ['POST /internal/radius/accounting'], 'the one route outside it is RADIUS accounting');
is_($passes('/internal/radius/accounting'), false, 'and the allow-list does not let it through');
$adminRoutes = $routesOf(AdminRoutes::build(new FixedStaff(new StaffIdentity('s', StaffRole::Admin, 't')),
    \Dn\Runtime\Bindings::defaults(), static fn(string $fn, array $a = []): array => []));
is_(count($adminRoutes) > 20, true, 'control: the Admin router has its routes (' . count($adminRoutes) . ')');
is_(array_values(array_filter($adminRoutes, static fn($r) => $passes($r[1]))), [],
    'no Admin route falls inside the app allow-list');
is_(array_values(array_filter($adminRoutes, static fn($r) => !str_starts_with($r[1], '/api/v1/admin/')))
    , [], 'every Admin route is under /api/v1/admin/, which the app refuses');
$pkgContains = implode(' | ', $man['package']['contains'] ?? []);
$pkgExcludes = implode(' | ', $man['package']['excludes'] ?? []);
is_(str_contains($pkgContains, 'public/'), true, 'the package declares public/ shipped (H-11, the operator\'s approval)');
is_(str_contains($pkgExcludes, 'public/'), false, 'and no longer excluded');
foreach (['tests/', 'tools/', 'docs/'] as $d) {
    is_(str_contains($pkgExcludes, $d), true, "{$d} stays excluded");
}

// ===========================================================================
t('2. THE APP PROCESS — started with those four variables and nothing else');
$srvApp = $start('plugin/bin/serve-app.php', $appEnv);
is_($srvApp['up'], true, 'serve-app.php is listening on ' . $srvApp['port']);
if (!$srvApp['up']) { echo (string) @file_get_contents($srvApp['log']); exit(t_summary()); }
$environ = (string) @file_get_contents('/proc/' . $srvApp['pid'] . '/environ');
$names   = array_values(array_filter(array_map(static fn($kv) => explode('=', $kv, 2)[0], explode("\0", $environ))));
sort($names);
$want = $app['config']; sort($want);
is_($names, $want, 'the process environment holds exactly the four names — no Admin credential exists in it');
$P = $srvApp['port'];
$get = static fn(string $path, array $h = []) => $raw($P, 'GET', $path, $h);
$api = static function (string $method, string $path, ?array $body = null, string $token = '') use ($raw, $P): array {
    $h = ['Accept' => 'application/json'];
    if ($body !== null) { $h['Content-Type'] = 'application/json'; }
    if ($token !== '') { $h['Authorization'] = 'Bearer ' . $token; }
    return $raw($P, $method, $path, $h, $body === null ? '' : (string) json_encode($body));
};

// ===========================================================================
t('3. STATIC FILES — the app, from its two directories');
$files = ['/'                => ['public/app/index.html', 'text/html; charset=utf-8'],
          '/app/index.html'  => ['public/app/index.html', 'text/html; charset=utf-8'],
          '/app/app.js'      => ['public/app/app.js',     'text/javascript; charset=utf-8'],
          '/app/app.css'     => ['public/app/app.css',    'text/css; charset=utf-8'],
          '/app/icon.svg'    => ['public/app/icon.svg',   'image/svg+xml'],
          '/pwa/api.js'      => ['public/pwa/api.js',     'text/javascript; charset=utf-8'],
          '/pwa/store.js'    => ['public/pwa/store.js',   'text/javascript; charset=utf-8']];
foreach ($files as $path => [$file, $type]) {
    $r = $get($path);
    is_([$r['status'], $r['headers']['content-type'] ?? '', $r['body'] === (string) file_get_contents($root . '/' . $file)],
        [200, $type, true], "{$path} → 200 {$type}, the file byte for byte");
}
is_($get('/app/app.js')['headers']['cache-control'] ?? '', 'no-cache', 'static files revalidate (no-cache), so a redeploy is seen');

// ===========================================================================
t('4. REFUSALS — the app host answers its own 404 for everything else (H-2)');
$probeDir = $root . '/public/app';
$tag      = 'probe-' . getmypid();
$canary   = 'CANARY-' . bin2hex(random_bytes(6));
$made     = [
    "{$probeDir}/{$tag}.php"      => "<?php echo 'EXECUTED-{$canary}'; // SOURCE-{$canary}\n",
    "{$probeDir}/{$tag}.txt"      => "TEXT-{$canary}\n",
    "{$probeDir}/{$tag}.json"     => "{\"control\":\"{$canary}\"}\n",
    "{$root}/public/{$tag}.json"  => "{\"outside\":\"{$canary}\"}\n",
];
$cleanup = static function () use ($made, $probeDir, $tag) {
    foreach (array_keys($made) as $f) { @unlink($f); }
    @unlink("{$probeDir}/{$tag}-link.js");
};
register_shutdown_function($cleanup);
foreach ($made as $f => $content) { file_put_contents($f, $content); }
symlink('../index.php', "{$probeDir}/{$tag}-link.js");

// The control first: a new file of an allowed type IS served, so every refusal
// below is about the path or the type — not about the file being new.
$c = $get("/app/{$tag}.json");
is_([$c['status'], str_contains($c['body'], $canary)], [200, true],
    'control: a new .json file under public/app is served (the refusals below are not about newness)');

$own404 = static fn(array $r): bool => $r['status'] === 404 && $r['body'] === "not found\n"
    && str_starts_with($r['headers']['content-type'] ?? '', 'text/plain');
$refused = [
    ['GET',  '/index.php',                             'the front controller, by name'],
    ['GET',  '/public/index.php',                      'the front controller, by its package path'],
    ['GET',  '/app/../index.php',                      'traversal out of /app/ to index.php'],
    ['GET',  '/pwa/../index.php',                      'traversal out of /pwa/ to index.php'],
    ['GET',  '/app/../../src/autoload.php',            'traversal to src/'],
    ['GET',  '/app/%2e%2e/index.php',                  'encoded traversal'],
    ['GET',  '/app/..%2findex.php',                    'encoded slash traversal'],
    ['GET',  "/app/../{$tag}.json",                    'a file of an allowed type in public/ but outside /app/'],
    ['GET',  "/app/{$tag}.php",                        'a .php file inside public/app — neither run nor printed'],
    ['GET',  "/app/{$tag}.txt",                        'a file of a type not on the list'],
    ['GET',  "/app/{$tag}-link.js",                    'a .js symlink that resolves out of public/app'],
    ['GET',  '/app/',                                  'the directory itself'],
    ['GET',  '/app',                                   'the directory without a slash'],
    ['GET',  '/app/missing.js',                        'a file that does not exist'],
    ['GET',  '/favicon.ico',                           'a path outside both directories'],
    ['GET',  '/src/autoload.php',                      'src/'],
    ['GET',  '/migrations/032_sign_in_codes_by_sms.sql', 'a migration'],
    ['GET',  '/plugin/plugin.json',                    'the manifest'],
    ['GET',  '/plugin/.env.example',                   'the environment template'],
    ['GET',  '/panel/index.html',                      'the Admin panel'],
    ['GET',  '/tests/bootstrap.php',                   'the test harness'],
    ['GET',  '/api/v1/admin/session',                  'the Admin API'],
    ['POST', '/api/v1/admin/session',                  'the Admin API, by POST'],
    ['GET',  '/api/v1/admin/routers',                  'an Admin estate route'],
    ['POST', '/internal/radius/accounting',            'RADIUS accounting'],
    ['GET',  '/internal/radius/accounting',            'RADIUS accounting, by GET'],
    ['GET',  '/api/v1/meta',                           'a path that only STARTS with /api/v1/me'],
    ['POST', '/api/v1/authx/request-code',             'a path that only starts with /api/v1/auth'],
    ['POST', '/api/v1/auth',                           '/api/v1/auth without its slash'],
    ['GET',  '/api/v1/health',                         'any other API path'],
];
foreach ($refused as [$method, $path, $why]) {
    $r = $raw($P, $method, $path, $method === 'POST' ? ['Content-Type' => 'application/json', 'X-Internal-Token' => 'guess'] : [], $method === 'POST' ? '{}' : '');
    $leak = str_contains($r['body'], $canary) || str_contains($r['body'], '<?php') || str_contains($r['body'], 'Database::');
    is_([$own404($r), $leak], [true, false], "{$method} {$path} → the app host's own 404 ({$why})");
}
$cleanup();
is_(glob($probeDir . '/probe-*') ?: [], [], 'the probe files are gone');

// ===========================================================================
t('5. EVERY ANSWER CARRIES THE HEADERS (H-3)');
$sample = [
    'a page'         => $get('/'),
    'a script'       => $get('/app/app.js'),
    'its own 404'    => $get('/nothing-here'),
    'the Admin 404'  => $get('/api/v1/admin/session'),
    'an API 401'     => $api('GET', '/api/v1/me'),
    'an API 400'     => $api('POST', '/api/v1/auth/request-code', []),
];
foreach ($sample as $label => $r) {
    $got = [];
    foreach ($app['headers'] ?? [] as $name => $value) { $got[$name] = $r['headers'][strtolower($name)] ?? null; }
    is_($got, $app['headers'], "{$label} ({$r['status']}) carries every declared header, exactly");
}
is_(count($app['headers'] ?? []), 5, 'five headers are declared');
is_(str_contains($app['headers']['Content-Security-Policy'] ?? '', "script-src 'self';"), true,
    "the script policy is 'self' alone — no 'unsafe-inline', no other origin");
is_(preg_match("/unsafe|https?:|\\*/", $app['headers']['Content-Security-Policy'] ?? ''), 0,
    'and nothing in the policy widens it: no unsafe-*, no scheme, no wildcard');

// ===========================================================================
t('6. EVERY OPERATOR ROUTE REACHES THE FRONT CONTROLLER');
// Each route the front controller serves, requested with no token: the answer
// must come from the Kernel (JSON), never the app host's own text/plain 404.
$dummy = '00000000-0000-4000-8000-000000000000';
$reachedAll = [];
foreach ($served as [$method, $pattern]) {
    if (str_starts_with($pattern, '/internal/')) { continue; }
    $path = (string) preg_replace('/\{[a-z_]+\}/', $dummy, $pattern);
    $r = $api($method, $path, $method === 'GET' ? null : []);
    if (!str_starts_with($r['headers']['content-type'] ?? '', 'application/json') || $own404($r)) {
        $reachedAll[] = "{$method} {$pattern} → {$r['status']}";
    }
}
is_($reachedAll, [], 'all ' . (count($served) - 1) . ' operator routes are answered by the Kernel, not refused by the host');

// ===========================================================================
t('7. SIGN-IN OVER THE WIRE — the code travels only by the worker');
$ownerPhone = $ins->one("SELECT phone FROM mt_principals WHERE customer_id = ? AND kind = 'owner'", [$A['customer']])['phone'];
$spaced     = substr($ownerPhone, 0, 4) . ' ' . substr($ownerPhone, 4, 3) . ' ' . substr($ownerPhone, 7, 3) . ' ' . substr($ownerPhone, 10);
$unknown    = '+256799000777';
$staffPhone = '+256700009901';
// Fixture actions by the fixture identity: close what other suites left queued,
// and clear this suite's numbers, so every count below is this suite's own.
$ins->exec("UPDATE mt_auth_sms_outbox SET state = 'expired', sealed = NULL, lease_until = NULL, settled_at = now()
             WHERE state IN ('queued','sending')");
$ins->exec('DELETE FROM mt_auth_codes WHERE phone IN (?,?,?)', [$ownerPhone, $unknown, $staffPhone]);

$r1 = $api('POST', '/api/v1/auth/request-code', ['phone' => $spaced]);
$r2 = $api('POST', '/api/v1/auth/request-code', ['phone' => $unknown]);
is_([$r1['status'], $r1['json']], [202, ['status' => 'sent']],
    "a spaced number ({$spaced}) → 202 {status: sent}, and no code in the answer (DNB_EXPOSE_OTP is not in the process)");
is_([$r2['status'], $r2['body']], [202, $r1['body']], 'an unknown number → the byte-identical answer');
$phoneSms = new AppSuiteSms();
$ran = (new SmsWorker(Database::worker(), $phoneSms))->runOnce();
is_([count($phoneSms->sent), $phoneSms->sent[0]['to'] ?? null], [1, $ownerPhone],
    'the worker sent ONE message, to the canonical number — the unknown number got nothing');
$code = preg_match('/code: (\d{6})\./', $phoneSms->sent[0]['message'] ?? '', $cm) ? $cm[1] : '';
is_(strlen($code), 6, 'and the message carries a 6-digit code');
$wrong = $api('POST', '/api/v1/auth/verify', ['phone' => $spaced, 'code' => $code === '000000' ? '111111' : '000000']);
is_($wrong['status'], 401, 'a wrong code → 401');
$ok = $api('POST', '/api/v1/auth/verify', ['phone' => $spaced, 'code' => $code]);
$tok = (string) ($ok['json']['token'] ?? '');
is_([$ok['status'], strlen($tok)], [200, 64], 'the code the phone received → 200 and a token');
$me = $api('GET', '/api/v1/me', null, $tok);
is_([$me['status'], $me['json']['customer']['name'] ?? null, $me['json']['principal']['kind'] ?? null],
    [200, 'Riverside Hotel', 'owner'], '/api/v1/me answers for that operator, as its owner');

// ===========================================================================
t('8. A PLAN IN UGX, AND A ROLE THAT DOES NOT INCLUDE IT (H-5, H-9)');
$plan = ['name' => 'Day pass', 'duration_s' => 86400, 'rate_down_bps' => 10_000_000, 'rate_up_bps' => 3_000_000,
         'devices_per_voucher' => 2, 'data_cap_bytes' => null, 'mode' => 'elapsed', 'price_minor' => 5000, 'currency' => 'UGX'];
$created = $api('POST', '/api/v1/me/plans', $plan, $tok);
is_([$created['status'], $created['json']['plan']['price_minor'] ?? null, $created['json']['plan']['currency'] ?? null],
    [201, 5000, 'UGX'], 'the owner creates a plan: 201, 5000 in whole shillings (UGX has no minor unit)');

$onb = new OnboardingAdmin(static fn(): Database => Database::adminWrite());
$staffId = $onb->addPrincipal($A['customer'], 'staff', 'Front desk', $staffPhone, OpCapability::PRESETS['seller'], 'test:app');
is_(is_string($staffId), true, 'fixture: a Seller is created on the Admin plane (no op.plans.write)');
$api('POST', '/api/v1/auth/request-code', ['phone' => $staffPhone]);
$phoneSms2 = new AppSuiteSms();
(new SmsWorker(Database::worker(), $phoneSms2))->runOnce();
$code2 = preg_match('/code: (\d{6})\./', $phoneSms2->sent[0]['message'] ?? '', $cm2) ? $cm2[1] : '';
$tok2  = (string) ($api('POST', '/api/v1/auth/verify', ['phone' => $staffPhone, 'code' => $code2])['json']['token'] ?? '');
is_(strlen($tok2), 64, 'the Seller signs in the same way');
$read = $api('GET', '/api/v1/me/plans', null, $tok2);
is_([$read['status'], count($read['json']['plans'] ?? [])], [200, 1], 'control: the Seller can read plans (op.plans.read)');
$deny = $api('POST', '/api/v1/me/plans', ['name' => 'Seller plan'] + $plan, $tok2);
is_([$deny['status'], $deny['json']], [403, ['error' => 'forbidden', 'capability' => 'op.plans.write']],
    'creating one is 403 {forbidden, op.plans.write} — what api.js classifies as FORBIDDEN, not failed');

// ===========================================================================
t('9. SIGN-OUT, AND WHAT THE APP HOST NEVER REACHES');
is_($api('POST', '/api/v1/auth/logout', [], $tok)['status'], 204, 'sign-out → 204');
is_($api('GET', '/api/v1/me', null, $tok)['status'], 401, 'the same token is refused afterwards');
$acct = $raw($P, 'POST', '/internal/radius/accounting', ['Content-Type' => 'application/json',
                                                      'X-Internal-Token' => 'guess'], '{"username":"x"}');
is_($own404($acct), true, 'RADIUS accounting is the host\'s own 404 — the front controller never saw it');

// ===========================================================================
t('10. THE ADMIN PROCESS REFUSES THE APP — two processes, each blind to the other (H-1)');
$srvAdm = $start('plugin/bin/serve.php', ['DNB_DSN' => getenv('DNB_DSN') ?: '',
    'DNB_ADMINAPI_PASS' => getenv('DNB_ADMINAPI_PASS') ?: '', 'DNB_ADMINWRITE_PASS' => getenv('DNB_ADMINWRITE_PASS') ?: '']);
is_($srvAdm['up'], true, 'serve.php is listening on ' . $srvAdm['port']);
$Q = $srvAdm['port'];
is_($raw($Q, 'GET', '/api/v1/admin/session')['status'], 401, 'control: its Admin API answers (401, deny-all)');
is_($raw($Q, 'GET', '/')['status'], 200, 'control: its panel answers');
foreach ([['GET', '/api/v1/me'], ['POST', '/api/v1/auth/request-code'], ['GET', '/app/index.html'],
          ['GET', '/app/app.js'], ['GET', '/pwa/api.js'], ['POST', '/internal/radius/accounting']] as [$m, $p]) {
    $r = $raw($Q, $m, $p, ['Content-Type' => 'application/json'], $m === 'POST' ? '{"phone":"' . $ownerPhone . '"}' : '');
    is_($r['status'], 404, "the Admin host: {$m} {$p} → 404");
}
$appSrc = strip_php_comments($srv);
foreach (['plugin/public/api.php', 'AdminRoutes', 'adminApi', 'adminWrite', 'staffauth', 'DNB_ADMIN'] as $needle) {
    is_(str_contains($appSrc, $needle), false, "serve-app.php's code never names {$needle}");
}
is_(substr_count($appSrc, 'require '), 1, 'it requires exactly one file');
is_(str_contains($appSrc, "require \$root . '/public/index.php'"), true, 'and that file is the customer front controller');
is_(str_contains(strip_php_comments((string) file_get_contents($root . '/plugin/bin/serve.php')), 'public/index.php'), false,
    "serve.php's code never names the customer front controller");

// ===========================================================================
t('11. THE BUNDLE — no inline code, no other origin, one connection (H-3, H-4)');
$bundle = [];
foreach (['public/app/index.html', 'public/app/app.js', 'public/app/app.css', 'public/app/icon.svg',
          'public/pwa/api.js', 'public/pwa/store.js'] as $f) {
    $bundle[$f] = (string) file_get_contents($root . '/' . $f);
}
$appFiles = array_map('basename', glob($root . '/public/app/*') ?: []); sort($appFiles);
is_($appFiles, ['app.css', 'app.js', 'icon.svg', 'index.html'], 'public/app holds exactly the page, its script, its styles and its icon');
$html = $bundle['public/app/index.html'];
preg_match_all('#<script\b([^>]*)>(.*?)</script>#is', $html, $sc, PREG_SET_ORDER);
is_(count($sc), 1, 'index.html has one script tag');
is_([trim($sc[0][2] ?? 'x'), (bool) preg_match('#src="/app/app\.js"#', $sc[0][1] ?? '')], ['', true],
    'and it is empty, loading /app/app.js');
is_(preg_match('#<style\b|\sstyle\s*=|\son[a-z]+\s*=#i', $html), 0, 'index.html: no style block, style attribute or handler attribute');
preg_match_all('#\s(?:src|href)="([^"]*)"#', $html, $refs);
is_(array_values(array_filter($refs[1], static fn($u) => !str_starts_with($u, '/app/'))), [],
    'every src and href in the page is under /app/ (' . count($refs[1]) . ' of them)');

$urls = [];
foreach ($bundle as $f => $s) {
    $scan = $f === 'public/app/icon.svg' ? str_replace('xmlns="http://www.w3.org/2000/svg"', '', $s, $ns) : $s;
    if (preg_match_all('#\b(?:https?:)?//[a-z0-9.-]+\.[a-z]{2,}#i', $scan, $u)) { $urls[$f] = $u[0]; }
}
is_($ns ?? 0, 1, 'the one http:// string in the bundle is the SVG namespace, which is a name, not a fetch — excepted once');
is_($urls, [], 'no file in the bundle names another origin');
$css = $bundle['public/app/app.css'];
is_(preg_match('/@import|@font-face|url\(/i', $css), 0, 'the stylesheet imports and loads nothing');

$jsCode = [];
foreach (['public/app/app.js', 'public/pwa/api.js', 'public/pwa/store.js'] as $f) { $jsCode[$f] = $stripJs($bundle[$f]); }
$js = $jsCode['public/app/app.js'];
is_(preg_match('#\son(?:click|load|error|submit|input|change|focus|blur|key\w+|mouse\w+|touch\w+|pointer\w+)\s*=#i', $js), 0,
    'app.js writes no inline handler attribute');
is_(preg_match('#\sstyle\s*=|<style\b|<script\b|javascript:#i', $js), 0, 'app.js writes no style attribute, style block, script tag or javascript: URL');
preg_match_all("#^\s*import\b[^;]*?from\s+'([^']+)'#m", $js, $imp);
is_($imp[1], ['/pwa/api.js', '/pwa/store.js'], 'app.js imports the data layer and nothing else');
foreach ($jsCode as $f => $c) {
    $hits = [];
    foreach (['fetch(', 'XMLHttpRequest', 'WebSocket', 'EventSource', 'sendBeacon', 'serviceWorker', 'import(',
              'localStorage', 'indexedDB', 'document.cookie', 'caches.', 'eval(', 'Function('] as $n) {
        if ($f === 'public/pwa/api.js' && $n === 'fetch(') { continue; }
        if (str_contains($c, $n)) { $hits[] = $n; }
    }
    is_($hits, [], "{$f}: no other connection, storage or evaluation");
}
is_(substr_count($jsCode['public/pwa/api.js'], 'fetch('), 1, 'api.js makes the one fetch — every request goes through it');
is_(array_keys(array_filter($jsCode, static fn($c) => str_contains($c, 'sessionStorage'))), ['public/pwa/api.js'],
    'the token is kept in sessionStorage, by api.js alone (H-4)');
preg_match_all("#headers\\['([A-Za-z-]+)'\\]|'(Accept)':#", $jsCode['public/pwa/api.js'], $hn);
$hdrs = array_values(array_unique(array_filter(array_merge($hn[1], $hn[2])))); sort($hdrs);
is_($hdrs, ['Accept', 'Authorization', 'Content-Type'], 'api.js sets three request headers and no other');
foreach ($jsCode as $f => $c) {
    is_(stripos($c, 'idempotency'), false, "{$f}: no Idempotency-Key — a replay would make an unpublished second batch (H-8)");
}

// ===========================================================================
t('12. NO MOCK DATA, AND THE REVIEW\'S WORDS (H-6, H-7)');
$mock = [];
foreach (['CUSTOMERS', 'INVOICES', 'ROUTERS', 'SERVICES', 'SITES', 'PLANS', 'VOUCHERS', 'SESSIONS',
          'TOKEN_TO_CUST', 'WHO_TOKEN', 'doTamper', 'openChain', 'openGuest', 'setWho', 'routerForCustomer'] as $n) {
    foreach (['public/app/app.js', 'public/pwa/store.js'] as $f) {
        if (preg_match('/\b' . $n . '\b/', $jsCode[$f])) { $mock[] = "{$f}: {$n}"; }
    }
}
is_($mock, [], "none of the prototype's mock constants or reviewer tools survives");
$proto = (string) @file_get_contents($root . '/../prototype/dishnet-customer-pwa-prototype.html');
is_(str_contains($proto, 'const TOKEN_TO_CUST'), true, 'control: the prototype does carry them, so the check has something to find');

preg_match("/const CODE_MINUTES = (\d+);/", $js, $cmn);
preg_match("/const LIMIT_MINUTES = (\d+);/", $js, $lmn);
is_((int) ($cmn[1] ?? 0), Authenticator::CODE_TTL_MINUTES, 'the screen\'s code lifetime is Authenticator::CODE_TTL_MINUTES');
$issue = (string) ($ins->one("SELECT prosrc FROM pg_proc WHERE oid = 'mt_auth_issue_code(text,text,interval,text)'::regprocedure")['prosrc'] ?? '');
is_(preg_match("/interval '(\d+) minutes'/", $issue, $im), 1, 'control: the issue function states its rate window');
is_((int) ($lmn[1] ?? 0), (int) ($im[1] ?? -1), 'the screen\'s wait is the rate window mt_auth_issue_code enforces');

$words = $bundle['public/app/app.js'];
foreach (['can sign in, a code is on its way by SMS'         => 'a code is on its way only IF the number can sign in',
          'is not switched on yet, so guests can\'t use them' => 'vouchers are recorded, the Wi-Fi login is not on',
          'No device has been reported'                       => 'devices: what was reported, not who is there',
          'Access-point status isn\'t available'              => 'access points: unavailable',
          'Billing isn\'t available'                          => 'billing: unavailable',
          'Support isn\'t available'                          => 'support: unavailable',
          'Queued.'                                           => 'a 202 is queued'] as $needle => $why) {
    is_(str_contains($words, $needle), true, "says it: {$why}");
}
$claims = [];
foreach (['code sent', 'we sent', 'has been sent', 'was sent', 'nobody is connected', 'no one is connected', '0 of 0',
          'check-in', 'checks in', 'polling', 'next time', 'when your router', 'router will', 'within a minute',
          'in a few minutes', 'is live', 'now active'] as $n) {
    if (stripos($words, $n) !== false) { $claims[] = $n; }
}
is_($claims, [], 'claims nothing the platform cannot show: no "sent", no "nobody", no router timing (B1)');
is_(preg_match('/res\.status === 403\) return \{ state: State\.FORBIDDEN/', $jsCode['public/pwa/api.js']), 1,
    'api.js classifies 403 as FORBIDDEN');
is_(substr_count($js, 'State.FORBIDDEN') >= 2, true, 'and the screens render it — a card for a read, a message for a write');

// ===========================================================================
t('13. THE DOCTOR REQUIRES THE APP\'S FILES');
$manObj  = \Dn\Plugin\Manifest::load($root . '/plugin/plugin.json');
$filesOf = static function (string $at) use ($manObj): array {
    $doc = new \Dn\Plugin\Doctor($manObj, $at, true);
    $m   = new ReflectionMethod($doc, 'files');
    $m->setAccessible(true);
    foreach ($m->invoke($doc) as $row) { if ($row['id'] === 'files.present') { return $row; } }
    return [];
};
$here = $filesOf($root);
is_([$here['state'] ?? null, $here['detail'] ?? null], [\Dn\Plugin\Doctor::OK, '15 required paths present'],
    'on this tree: OK, fifteen required paths present');
$empty = sys_get_temp_dir() . '/dnb-doc-empty-' . getmypid();
@mkdir($empty);
$none = $filesOf($empty);
@rmdir($empty);
is_($none['state'] ?? null, \Dn\Plugin\Doctor::BLOCKER, 'control: on an empty tree, BLOCKER');
foreach (['plugin/bin/serve-app.php', 'public/index.php', 'public/app/index.html', 'public/app/app.js', 'public/pwa/api.js'] as $f) {
    is_(str_contains($none['detail'] ?? '', $f), true, "and the refusal names {$f}");
}

// ===========================================================================
t('14. REPOSITORY STATE');
$doc = (string) @file_get_contents($root . '/../docs/127-OPERATOR-SIGN-IN-END-TO-END.md');
is_(str_contains($doc, '## H. Phase 3'), true, 'docs/127 carries the phase-3 review');
is_(str_contains($doc, '## I. Phase 3 — build record'), true, 'and the phase-3 build record');

exit(t_summary());
