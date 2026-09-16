<?php
declare(strict_types=1);
chdir(__DIR__);
require_once __DIR__ . '/lib/error_handler.php';

/**
 * shop.php — the public accessories shop, reached as public.php?page=shop.
 *
 * GET only, no authentication, and safe to be so: it shows the catalogue a
 * customer may see (assets/shop/catalogue.json) priced from the same
 * PublicPriceFeed shape the website's price feed serves — customer prices,
 * nothing internal — through the same ten-minute cache, so the shop and the
 * website can never disagree and neither leans on uCRM per visit. An item
 * without a uCRM product is hidden. `?format=json` returns the resolved
 * items for the website to embed, under the same CORS rule as prices.php.
 */
require_once __DIR__ . '/lib/bootstrap_data.php';
require_once __DIR__ . '/lib/StoreInterface.php';
require_once __DIR__ . '/lib/JsonStore.php';
require_once __DIR__ . '/lib/SqliteStore.php';
require_once __DIR__ . '/lib/PluginConfig.php';
require_once __DIR__ . '/lib/CrmApiClient.php';
require_once __DIR__ . '/lib/PublicPriceFeed.php';
require_once __DIR__ . '/lib/ShopCatalogue.php';
require_once __DIR__ . '/lib/ShopPage.php';

$dataDir = getDataDir(__DIR__);
$config  = PluginConfig::load(__DIR__, $dataDir);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    exit('GET only');
}

// ── The live catalogue, through the price feed's ten-minute cache ────────
$cacheFile = $dataDir . '/public_prices_cache.json';
$cached    = @file_get_contents($cacheFile);
$feed      = null;
if ($cached !== false && (time() - (int)@filemtime($cacheFile)) < 600) {
    $feed = json_decode($cached, true);
}
if (!is_array($feed)) {
    $crm = CrmApiClient::fromUcrm(__DIR__, $config);
    if ($crm->isConfigured()) {
        $plans    = $crm->get('service-plans') ?? [];
        $products = $crm->get('products') ?? [];
        if ($plans || $products) {
            $feed = PublicPriceFeed::build($plans, $products, $config);
            @file_put_contents($cacheFile, json_encode($feed, JSON_UNESCAPED_UNICODE));
        }
    }
    // Stale beats broken: a twenty-minute-old price list over an empty shop.
    if (!is_array($feed) && $cached !== false) $feed = json_decode($cached, true);
}

// Both lists: the feed now separates accessories from kits (so the website's
// kits grid does not show twenty mounts), and the shop wants both.
$priced = [];
if (is_array($feed)) {
    foreach (['hardware', 'accessories'] as $k) {
        foreach ((array)($feed[$k] ?? []) as $row) $priced[] = $row;
    }
}
$catalogue = ShopCatalogue::load(__DIR__);
$resolved  = ShopCatalogue::resolve($catalogue, $priced);

if (($_GET['format'] ?? '') === 'json') {
    $origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && in_array($origin, PublicPriceFeed::allowedOrigins($config), true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=300');
    $out = [];
    foreach ($resolved['items'] as $it) {
        $out[] = ['slug' => $it['slug'], 'kind' => $it['kind'], 'name' => $it['name'], 'category' => $it['category'],
                  'fits' => $it['fits'], 'specs' => $it['specs'], 'price' => $it['price'],
                  'image' => '?page=shop_img&s=' . rawurlencode($it['slug']) . '&w=480'];
    }
    exit(json_encode(['v' => 1, 'currency' => (string)($feed['currency'] ?? 'UGX'), 'vat_note' => (string)($feed['vat_note'] ?? ''),
                      'items' => $out, 'updated_at' => (string)($feed['updated_at'] ?? '')], JSON_UNESCAPED_UNICODE));
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=300');
echo ShopPage::render($resolved['items'], $config, [
    'img_base'    => '?page=shop_img',
    'currency'    => (string)($feed['currency'] ?? ''),
    'vat_note'    => (string)($feed['vat_note'] ?? 'All prices include VAT'),
    'updated_at'  => (string)($feed['updated_at'] ?? ''),
    'unavailable' => !is_array($feed),
]);
