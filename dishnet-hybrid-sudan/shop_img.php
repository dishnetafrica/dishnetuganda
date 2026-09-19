<?php
declare(strict_types=1);
chdir(__DIR__);
require_once __DIR__ . '/lib/error_handler.php';

/**
 * shop_img.php — a product photo, reached as public.php?page=shop_img&s=<slug>&w=<240|480>.
 *
 * The slug is looked up in the catalogue and never used as a path. Sized
 * WebP copies are cached in the data directory; the original is served when
 * no size is asked for or GD is unavailable. Public, cacheable for a week.
 */
require_once __DIR__ . '/lib/bootstrap_data.php';
require_once __DIR__ . '/lib/ShopCatalogue.php';
require_once __DIR__ . '/lib/ShopImages.php';

$slug  = (string)($_GET['s'] ?? '');
$width = (int)($_GET['w'] ?? 0);
$src   = ShopImages::resolve(__DIR__, ShopCatalogue::load(__DIR__), $slug);
if ($src === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Not found');
}
$file = ShopImages::variant($src, getDataDir(__DIR__), $slug, $width);
$size = (int)@filesize($file['path']);
$etag = '"' . md5($file['path'] . '|' . (int)@filemtime($file['path']) . '|' . $size) . '"';
header('Cache-Control: public, max-age=604800');
header('ETag: ' . $etag);
if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}
header('Content-Type: ' . $file['mime']);
header('Content-Length: ' . $size);
header('X-Content-Type-Options: nosniff');
readfile($file['path']);
