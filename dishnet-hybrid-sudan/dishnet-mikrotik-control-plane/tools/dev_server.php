<?php
/**
 * Development server only: serves the panel's static files and routes
 * /api/v1/admin/* into the plugin's entry point, so the two can be looked at
 * on one origin. Not part of the plugin, not a deployment artifact.
 *
 *   php -S 127.0.0.1:8080 tools/dev_server.php
 *
 * The packaged equivalent is plugin/bin/serve.php, which ships inside the
 * tarball. This file stays because it serves the repository layout directly.
 *
 * It previously had no containment check, and `GET /../src/Db/Database.php`
 * returned 200 with the source — measured, not theorised. Source disclosure is
 * a credential disclosure here, because migration 001 still carries the
 * development role passwords as SQL literals. Hence realpath containment.
 */
$root = dirname(__DIR__);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (str_starts_with($path, '/api/')) {
    require $root . '/plugin/public/api.php';
    return true;
}

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

$types = ['html' => 'text/html; charset=utf-8', 'js' => 'text/javascript; charset=utf-8',
          'css' => 'text/css; charset=utf-8', 'svg' => 'image/svg+xml',
          'json' => 'application/json', 'ico' => 'image/x-icon'];
$ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('X-Content-Type-Options: nosniff');
readfile($target);
return true;
