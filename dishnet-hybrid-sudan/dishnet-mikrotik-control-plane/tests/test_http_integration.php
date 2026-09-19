<?php
declare(strict_types=1);
/**
 * Over the wire, against a real server.
 *
 * Every other suite calls the Kernel directly. That proves the logic and
 * proves nothing about Request::fromGlobals(), header parsing, REQUEST_URI
 * routing, status codes actually reaching the socket, or Response::send().
 * A bug in any of those passes every in-process test and fails in production.
 *
 * This is the same lesson as "a fake MikroTik would pass while the real one
 * rejects the command" (docs/30 Artifact 13), applied to our own front door.
 */
require __DIR__ . '/bootstrap.php';
use Dn\Db\Database;

$owner = Database::owner();
$ids   = seed_two_customers($owner);
$A = $ids['A']; $B = $ids['B'];

$port = 58080 + (getmypid() % 500);
$root = dirname(__DIR__);
$env  = 'DNB_DSN=' . escapeshellarg(getenv('DNB_DSN') ?: '')
      . ' DNB_EXPOSE_OTP=1';
$log  = tempnam(sys_get_temp_dir(), 'dnb-srv');
$cmd  = "{$env} php -S 127.0.0.1:{$port} -t " . escapeshellarg($root . '/public')
      . ' ' . escapeshellarg($root . '/public/index.php') . " > {$log} 2>&1 & echo $!";
$pid  = (int) shell_exec($cmd);

// wait for the socket rather than sleeping a guessed amount
$up = false;
for ($i = 0; $i < 100; $i++) {
    $s = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
    if ($s) { fclose($s); $up = true; break; }
    usleep(50_000);
}
register_shutdown_function(static function () use ($pid, $log) {
    if ($pid > 0) { @shell_exec("kill {$pid} 2>/dev/null"); }
    @unlink($log);
});

t('the server actually starts');
is_($up, true, "php -S is listening on {$port}");
if (!$up) { echo file_get_contents($log); exit(t_summary()); }

/** @return array{status:int,headers:array,body:array} */
$http = function (string $method, string $path, array $body = [], string $token = '') use ($port): array {
    $hdr = ["Content-Type: application/json"];
    if ($token !== '') { $hdr[] = "Authorization: Bearer {$token}"; }
    $ctx = stream_context_create(['http' => [
        'method'        => $method,
        'header'        => implode("\r\n", $hdr),
        'content'       => $body ? json_encode($body) : '',
        'ignore_errors' => true,      // so 4xx bodies are readable
        'timeout'       => 5,
    ]]);
    $raw = @file_get_contents("http://127.0.0.1:{$port}{$path}", false, $ctx);
    $status = 0; $headers = [];
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $status = (int) $m[1]; continue; }
        if (str_contains($h, ':')) {
            [$k, $v] = explode(':', $h, 2);
            $headers[strtolower(trim($k))] = trim($v);
        }
    }
    return ['status' => $status, 'headers' => $headers,
            'body' => $raw === false || $raw === '' ? [] : (json_decode($raw, true) ?? [])];
};

// ---------------------------------------------------------------------------
t('the whole sign-in flow works over HTTP');
$r = $http('POST', '/api/v1/auth/request-code', ['phone' => '+256700001001']);
is_($r['status'], 202, 'request-code returns a real 202');
$code = $r['body']['dev_code'] ?? '';
is_(strlen($code), 6, 'and a code came back through the socket');

$r = $http('POST', '/api/v1/auth/verify', ['phone' => '+256700001001', 'code' => $code]);
is_($r['status'], 200, 'verify returns 200');
$tok = $r['body']['token'] ?? '';
is_(strlen($tok), 64, 'a token arrived');

t('the Authorization header survives the real request cycle');
// This is the assertion an in-process test cannot make: fromGlobals() has to
// reconstruct the header from $_SERVER['HTTP_AUTHORIZATION'].
$r = $http('GET', '/api/v1/me', [], $tok);
is_($r['status'], 200, 'a bearer token sent as a real header authenticates');
is_($r['body']['customer']['name'], 'Riverside Hotel', 'and resolves to the right customer');

$r = $http('GET', '/api/v1/me');
is_($r['status'], 401, 'no header means a real 401');

t('status codes reach the socket, not just the object');
is_($http('GET', '/api/v1/me/sites/' . $B['site'], [], $tok)['status'], 404, "B's site id is a real 404");
is_($http('GET', '/api/v1/me/sites/' . $A['site'], [], $tok)['status'], 200, "A's own site is 200");
is_($http('GET', '/api/v1/nope', [], $tok)['status'], 404, 'an unknown path is a real 404');
is_($http('POST', '/api/v1/auth/verify', ['phone' => 'x'], '')['status'], 400, 'a bad request is a real 400');

t('response headers are what a browser needs');
$r = $http('GET', '/api/v1/me', [], $tok);
is_(str_contains($r['headers']['content-type'] ?? '', 'application/json'), true, 'Content-Type is JSON');
is_($r['headers']['cache-control'] ?? '', 'no-store',
    'Cache-Control: no-store — customer data must not sit in a shared cache');

t('logout works over HTTP and the token really stops');
is_($http('POST', '/api/v1/auth/logout', [], $tok)['status'], 204, 'logout returns 204');
is_($http('GET', '/api/v1/me', [], $tok)['status'], 401, 'the token is dead afterwards');

t('no PHP notice, warning or stack trace ever reaches the response body');
$probe = [
    $http('GET', '/api/v1/me/sites/../../etc/passwd', [], 'bad'),
    $http('GET', '/api/v1/me/sites/%00', [], 'bad'),
    $http('POST', '/api/v1/auth/verify', [], ''),
    $http('GET', '/api/v1/me', [], 'not-a-real-token'),
];
$leaked = [];
foreach ($probe as $p) {
    $j = json_encode($p['body']);
    foreach (['Warning', 'Notice', 'Fatal', 'Stack trace', 'SQLSTATE',
              '/home/', 'mt_', 'PDO'] as $needle) {
        if (str_contains($j, $needle)) { $leaked[] = "{$needle} in " . substr($j, 0, 60); }
    }
}
is_($leaked, [], 'no diagnostic detail in any error response');

t('and none reached the server log as an unhandled error either');
$serverLog = file_get_contents($log) ?: '';
is_(str_contains($serverLog, 'Uncaught'), false, 'no uncaught exception in the server log');

exit(t_summary());
