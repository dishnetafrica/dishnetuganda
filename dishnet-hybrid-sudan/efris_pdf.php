<?php
declare(strict_types=1);

/**
 * efris_pdf.php — serve one generated fiscal e-invoice PDF.
 * Routed via public.php?page=efris_pdf&file=…&token=…
 *
 * Same pattern as the quote-PDF serving: an HMAC token derived from the
 * filename, the webhook secret and the current day. Links are only ever
 * rendered inside the authenticated EFRIS admin tab; the token merely keeps
 * the URL unguessable and short-lived. Path traversal is impossible —
 * basename() plus a strict filename allowlist.
 */

require_once __DIR__ . '/lib/bootstrap_data.php';
require_once __DIR__ . '/lib/StoreInterface.php';
require_once __DIR__ . '/lib/SqliteStore.php';
require_once __DIR__ . '/lib/PluginConfig.php';

$dataDir = $GLOBALS['dataDir'] ?? getDataDir(__DIR__);
$config  = PluginConfig::load(__DIR__, $dataDir);

$file  = basename((string)($_GET['file'] ?? ''));
$token = (string)($_GET['token'] ?? '');

if (!preg_match('/^einv_[A-Za-z0-9_-]+\.pdf$/', $file)) {
    http_response_code(400);
    exit('Bad file name');
}
$secret = (string)($config['webhook_secret'] ?? ($config['evo_webhook_secret'] ?? 'dishnet'));
$want   = hash_hmac('sha256', $file . gmdate('Ymd'), $secret);
if (!hash_equals($want, $token)) {
    http_response_code(403);
    exit('Invalid or expired token');
}

$path = $dataDir . '/efris_pdf/' . $file;
if (!is_file($path)) {
    http_response_code(404);
    exit('Not found');
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $file . '"');
header('Content-Length: ' . (string)filesize($path));
header('Cache-Control: private, max-age=0');
readfile($path);
exit;
