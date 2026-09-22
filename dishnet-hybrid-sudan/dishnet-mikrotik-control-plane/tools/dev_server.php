<?php
/**
 * Development server only: serves the panel's static files and routes
 * /api/v1/admin/* into the plugin's entry point, so the two can be looked at
 * on one origin. Not part of the plugin, not a deployment artifact.
 *
 *   php -S 127.0.0.1:8080 tools/dev_server.php
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
if (str_starts_with($path, '/api/')) {
    require __DIR__ . '/../plugin/public/api.php';
    return true;
}
$file = __DIR__ . '/../panel' . ($path === '/' ? '/index.html' : $path);
if (is_file($file)) {
    $ext = pathinfo($file, PATHINFO_EXTENSION);
    header('Content-Type: ' . ['html' => 'text/html', 'js' => 'text/javascript',
                               'css' => 'text/css'][$ext] ?? 'application/octet-stream');
    readfile($file);
    return true;
}
http_response_code(404);
return true;
