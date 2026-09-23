<?php
declare(strict_types=1);
/**
 * One-origin router: the Admin panel's static files plus the plugin API.
 *
 *   php -S 127.0.0.1:8099 plugin/bin/serve.php
 *
 * This ships INSIDE the package because tools/ does not: a tarball whose only
 * way to be served lives in a directory it excludes is not installable.
 *
 * What this is for: an isolated installation test, and a single-administrator
 * install behind something else that terminates TLS. PHP's built-in server is
 * single-threaded and does not belong on a public interface. The alternative
 * is any web server that can serve panel/ as static files and route
 * /api/v1/admin/* to plugin/public/api.php — the API is an ordinary PHP front
 * controller with no rewrite requirements beyond that.
 *
 * NOTE: Request::fromGlobals() reads the raw REQUEST_URI path, so the API is
 * bound to the absolute path /api/v1/admin/... . Mounting it under a prefix
 * requires the proxy to strip that prefix before PHP sees it.
 */

$root = dirname(__DIR__, 2);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (str_starts_with($path, '/api/')) {
    require $root . '/plugin/public/api.php';
    return true;
}

// Static panel files. The realpath containment check is the point of this
// branch: without it, a path containing .. reaches any file the process can
// read, which on this layout includes src/ and the migrations.
$panel  = realpath($root . '/panel');
$target = realpath($panel . ($path === '/' ? '/index.html' : $path));

if ($panel === false || $target === false
    || !str_starts_with($target, $panel . DIRECTORY_SEPARATOR)
    || !is_file($target)) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo "not found\n";
    return true;
}

$types = [
    'html' => 'text/html; charset=utf-8',
    'js'   => 'text/javascript; charset=utf-8',
    'css'  => 'text/css; charset=utf-8',
    'svg'  => 'image/svg+xml',
    'json' => 'application/json',
    'ico'  => 'image/x-icon',
];
$ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('X-Content-Type-Options: nosniff');
readfile($target);
return true;
