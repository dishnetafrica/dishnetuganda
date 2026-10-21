<?php
declare(strict_types=1);

/**
 * efris_pdf.php — serve one generated fiscal e-invoice PDF.
 * Routed via public.php?page=efris_pdf&file=…&token=…
 *
 * The link carries PdfLinkToken's day token for the filename (5.18.95), under
 * the install's own pdf_link_secret, the key receipt and delivery links use.
 * Links are only ever rendered inside the authenticated EFRIS admin tab; the
 * token keeps the URL unguessable and short-lived. It used to fall back to a
 * key written in the (public) source when webhook_secret was unset; there is
 * no fallback now: without a secret no token verifies. Path traversal is
 * impossible — basename() plus a strict filename allowlist.
 */

require_once __DIR__ . '/lib/bootstrap_data.php';
require_once __DIR__ . '/lib/StoreInterface.php';
require_once __DIR__ . '/lib/SqliteStore.php';
require_once __DIR__ . '/lib/PluginConfig.php';
require_once __DIR__ . '/lib/PdfLinkToken.php';

$dataDir = $GLOBALS['dataDir'] ?? getDataDir(__DIR__);
$config  = PluginConfig::load(__DIR__, $dataDir);

$file  = basename((string)($_GET['file'] ?? ''));
$token = (string)($_GET['token'] ?? '');

if (!preg_match('/^einv_[A-Za-z0-9_-]+\.pdf$/', $file)) {
    http_response_code(400);
    exit('Bad file name');
}
// The key is read from the store copy of kyc_config.json, where PdfLinkToken::ensureSecret() keeps it and where the
// EFRIS tab mints with it (tabs/admin/efris.php), so the two sides always use the same key.
$linkStore = (isset($store) && is_object($store)) ? $store : SqliteStore::create($dataDir);
$linkCfg   = (array)($linkStore->load('kyc_config.json') ?: []);

// A bad or expired token and a missing file get the same answer.
$path = $dataDir . '/efris_pdf/' . $file;
if (!PdfLinkToken::verify($file, $token, $linkCfg) || !is_file($path)) {
    http_response_code(404);
    exit('Not found');
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $file . '"');
header('Content-Length: ' . (string)filesize($path));
header('Cache-Control: private, max-age=0');
readfile($path);
exit;
