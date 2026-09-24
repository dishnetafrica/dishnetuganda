<?php
declare(strict_types=1);
/**
 * The operator app's server: its screens and the operator-plane API, and
 * nothing else (docs/127 §H).
 *
 *   php -S 127.0.0.1:8098 plugin/bin/serve-app.php
 *
 * The Admin panel has its own server, plugin/bin/serve.php, run as a SEPARATE
 * process on its own host name (H-1). Each answers 404 for the other's
 * surface, so this process never needs — and must never be given — an Admin
 * credential. What it uses: DNB_DSN, DNB_APP_PASS, DNB_TOKEN_PEPPER and
 * DNB_SECRET_KEY (the last to seal sign-in codes for the SMS outbox).
 *
 *   /                           public/app/index.html
 *   /app/… and /pwa/…           static files under public/app and public/pwa,
 *                               realpath-contained, by extension allow-list.
 *                               A .php file is NEVER served as a file (H-2).
 *   /api/v1/auth/…              public/index.php — the sign-in routes
 *   /api/v1/me and /api/v1/me/… public/index.php — the operator plane
 *
 * Everything else is 404: the Admin API, /internal/* (RADIUS accounting, which
 * no public host may reach), index.php itself, any other path.
 *
 * Every answer carries the same headers (H-3). The page holds a bearer token,
 * so the script policy is 'self' only: no inline script, no inline handler, no
 * inline style and no third-party origin can run or load here.
 */

$root = dirname(__DIR__, 2);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; "
     . "img-src 'self' data:; connect-src 'self'; manifest-src 'self'; base-uri 'none'; "
     . "form-action 'none'; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cross-Origin-Opener-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');

$notFound = static function (): bool {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "not found\n";
    return true;
};

// ── the operator-plane API: two prefixes and nothing else ─────────────────
if ($path === '/api/v1/me' || str_starts_with($path, '/api/v1/me/') || str_starts_with($path, '/api/v1/auth/')) {
    require $root . '/public/index.php';
    return true;
}
if (str_starts_with($path, '/api/') || str_starts_with($path, '/internal/')) {
    return $notFound();
}

// ── static files: two directories, a fixed set of types ───────────────────
$types = [
    'html' => 'text/html; charset=utf-8',
    'js'   => 'text/javascript; charset=utf-8',
    'css'  => 'text/css; charset=utf-8',
    'svg'  => 'image/svg+xml',
    'png'  => 'image/png',
    'ico'  => 'image/x-icon',
    'json' => 'application/json',
    'webmanifest' => 'application/manifest+json',
];
$file = $path === '/' ? '/app/index.html' : $path;
$base = null;
foreach (['/app/', '/pwa/'] as $prefix) {
    if (str_starts_with($file, $prefix)) { $base = realpath($root . '/public' . rtrim($prefix, '/')); break; }
}
$target = $base === null ? false : realpath($root . '/public' . $file);
$ext    = strtolower(pathinfo((string) $target, PATHINFO_EXTENSION));

// Contained under ITS directory — not merely under public/, which holds
// index.php — a regular file, and one of the types above. Anything else is 404.
if ($base === false || $base === null || $target === false
    || !str_starts_with($target, $base . DIRECTORY_SEPARATOR)
    || !is_file($target) || !isset($types[$ext])) {
    return $notFound();
}

header('Content-Type: ' . $types[$ext]);
header('Cache-Control: no-cache');
readfile($target);
return true;
