<?php
declare(strict_types=1);
chdir(__DIR__);
require_once __DIR__ . '/lib/error_handler.php';

/**
 * prices.php — the public price feed for the website.
 *
 * GET only, no authentication, and safe to be so: it serves exactly the
 * catalogue a customer may see (names, customer prices, public descriptions)
 * through PublicPriceFeed, which structurally cannot include costs or
 * margins. Source of truth is uCRM, cached for 10 minutes so the website's
 * traffic never leans on the CRM.
 *
 *   https://crm.dishnetuganda.com/crm/_plugins/dishnet-hybrid-sudan/prices.php
 *
 * CORS: only the origins in config['site_origins'] (default the Uganda site)
 * may read it from a browser.
 */
require_once __DIR__ . '/lib/bootstrap_data.php';
require_once __DIR__ . '/lib/StoreInterface.php';
require_once __DIR__ . '/lib/JsonStore.php';
require_once __DIR__ . '/lib/SqliteStore.php';
require_once __DIR__ . '/lib/PluginConfig.php';
require_once __DIR__ . '/lib/CrmApiClient.php';
require_once __DIR__ . '/lib/PublicPriceFeed.php';

$dataDir = getDataDir(__DIR__);
$config  = PluginConfig::load(__DIR__, $dataDir);

$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = PublicPriceFeed::allowedOrigins($config);
if ($origin !== '' && in_array($origin, $allowed, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    exit(json_encode(['error' => 'GET only']));
}

// ── 10-minute file cache ─────────────────────────────────────────────────
$cacheFile = $dataDir . '/public_prices_cache.json';
$cached = @file_get_contents($cacheFile);
if ($cached !== false && (time() - (int)@filemtime($cacheFile)) < 600) {
    exit($cached);
}

$crm = CrmApiClient::fromUcrm(__DIR__, $config);
if (!$crm->isConfigured()) {
    // Serve stale cache over an error — a price list that is 20 minutes old
    // beats a broken pricing section.
    if ($cached !== false) exit($cached);
    http_response_code(503);
    exit(json_encode(['error' => 'catalogue unavailable']));
}

$plans    = $crm->get('service-plans') ?? [];
$products = $crm->get('products') ?? [];
if (!$plans && !$products && $cached !== false) exit($cached);

$payload = json_encode(PublicPriceFeed::build($plans, $products, $config), JSON_UNESCAPED_UNICODE);
@file_put_contents($cacheFile, $payload);
echo $payload;
