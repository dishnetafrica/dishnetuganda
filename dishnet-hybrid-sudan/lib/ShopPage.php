<?php
declare(strict_types=1);

require_once __DIR__ . '/CustomerContact.php';

/**
 * ShopPage — the public accessories page, rendered from resolved catalogue
 * items (content from assets/shop/catalogue.json, prices from uCRM).
 *
 * Pure: takes arrays, returns HTML. No database, no network, so the whole
 * page is testable and the route (shop.php) stays a thin bootstrap. Every
 * card shows a price that came from uCRM this request or from the same
 * ten-minute cache the website's price feed uses; an item without one is
 * not on the page at all. Ordering is a WhatsApp message to the sales
 * number with the product named — Part 2 adds a cart and payment.
 */
final class ShopPage
{
    /** Display order of accessory groups. Unknown categories go last, alphabetically. */
    const CATEGORY_ORDER = ['Kit', 'Router', 'Mount', 'Adapter', 'Cable', 'Power', 'Case'];

    /**
     * @param array $items  ShopCatalogue::resolve()['items']
     * @param array $config plugin config (contact_sales_wa, stock_statement, ai_currency, shop_title…)
     * @param array $opts   img_base (default '?page=shop_img'), updated_at, currency, vat_note,
     *                      unavailable (bool: uCRM unreachable and no cache), self (page URL)
     */
    public static function render(array $items, array $config, array $opts = []): string
    {
        $e        = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $currency = trim((string)($opts['currency'] ?? ($config['ai_currency'] ?? ($config['currency_symbol'] ?? 'UGX')))) ?: 'UGX';
        $imgBase  = (string)($opts['img_base'] ?? '?page=shop_img');
        $wa       = preg_replace('/[^0-9]/', '', CustomerContact::salesWa($config)) ?? '';
        $stock    = trim((string)($config['stock_statement'] ?? ''));
        $vatNote  = trim((string)($opts['vat_note'] ?? 'All prices include VAT'));
        $title    = trim((string)($config['shop_title'] ?? 'DishNet Uganda — Starlink Shop'));
        $updated  = trim((string)($opts['updated_at'] ?? ''));
        $unavail  = !empty($opts['unavailable']);

        // Group: kits first, then accessories by category order.
        $groups = [];
        foreach ($items as $it) {
            $cat = ($it['kind'] ?? '') === 'kit' ? 'Kit' : (trim((string)($it['category'] ?? '')) ?: 'Other');
            $groups[$cat][] = $it;
        }
        uksort($groups, function (string $a, string $b): int {
            $ia = array_search($a, self::CATEGORY_ORDER, true); $ib = array_search($b, self::CATEGORY_ORDER, true);
            $ia = $ia === false ? 99 : $ia; $ib = $ib === false ? 99 : $ib;
            return $ia <=> $ib ?: strcmp($a, $b);
        });
        $groupTitle = ['Kit' => 'Starlink kits', 'Router' => 'Routers and Wi-Fi', 'Mount' => 'Mounts',
                       'Adapter' => 'Adapters', 'Cable' => 'Cables', 'Power' => 'Power', 'Case' => 'Cases'];

        $cards = '';
        foreach ($groups as $cat => $list) {
            $cards .= '<h2 class="group">' . $e($groupTitle[$cat] ?? $cat) . "</h2>\n<div class=\"grid\">\n";
            foreach ($list as $it) {
                $name  = (string)$it['name'];
                $price = self::money((float)$it['price'], $currency);
                $img   = $imgBase . '&s=' . rawurlencode((string)$it['slug']);
                $msg   = "Hello DishNet, I'd like to order: {$name} — {$price}. Please confirm availability and delivery.";
                $order = $wa !== '' ? 'https://wa.me/' . $wa . '?text=' . rawurlencode($msg) : '';
                $cards .= '<article class="card" data-slug="' . $e($it['slug']) . '">'
                    . '<img src="' . $e($img . '&w=480') . '" srcset="' . $e($img . '&w=240') . ' 240w, ' . $e($img . '&w=480') . ' 480w"'
                    . ' sizes="(max-width: 600px) 46vw, 260px" width="260" height="260" loading="lazy" alt="' . $e($name) . '">'
                    . '<div class="body"><h3>' . $e($name) . '</h3>'
                    . ($it['fits'] !== '' ? '<p class="fits">Fits: ' . $e($it['fits']) . '</p>' : '')
                    . ($it['specs'] !== '' ? '<details><summary>Details</summary><p>' . $e($it['specs']) . '</p></details>' : '')
                    . '<div class="price">' . $e($price) . '</div>'
                    . ($order !== '' ? '<a class="btn" href="' . $e($order) . '" target="_blank" rel="noopener">Order on WhatsApp</a>' : '')
                    . "</div></article>\n";
            }
            $cards .= "</div>\n";
        }

        $notice = '';
        if ($unavail) {
            $notice = '<div class="notice">Prices are unavailable at the moment. Please chat with sales'
                . ($wa !== '' ? ' on <a href="https://wa.me/' . $e($wa) . '">WhatsApp</a>' : '')
                . ' and we will quote you directly.</div>';
        } elseif ($items === []) {
            $notice = '<div class="notice">No products are listed yet.</div>';
        }

        $count = count($items);
        return '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>' . $e($title) . '</title>
<meta name="description" content="Genuine Starlink kits, mounts, routers and cables from DishNet Uganda. Prices in ' . $e($currency) . ', VAT included.">
<meta name="theme-color" content="#141414">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@700;800&family=Barlow:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--red:#D41C1C;--dark:#141414;--gray:#6B6B6B;--gray-light:#EBEBEB;--off-white:#F5F5F5;--display:"Barlow Condensed","Impact",sans-serif;--sans:"Barlow",-apple-system,sans-serif}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--sans);background:var(--off-white);color:var(--dark);line-height:1.5;-webkit-font-smoothing:antialiased}
.topbar{background:var(--dark);color:#fff;padding:14px 20px;display:flex;align-items:center;justify-content:space-between;gap:12px}
.brand{font-family:var(--display);font-weight:800;font-size:22px;letter-spacing:.5px}.brand span{color:var(--red)}
.topbar a{color:#fff;text-decoration:none;font-weight:600;font-size:14px;border:1px solid rgba(255,255,255,.35);padding:8px 12px;border-radius:8px}
main{max-width:1100px;margin:0 auto;padding:20px 16px 48px}
.intro h1{font-family:var(--display);font-weight:800;font-size:38px;line-height:1.05;margin-bottom:8px}
.intro p{color:var(--gray);max-width:720px}.intro .stock{color:var(--dark);font-weight:600;margin-top:6px}
.notice{background:#fff;border-left:4px solid var(--red);padding:14px 16px;margin:18px 0;border-radius:8px}
h2.group{font-family:var(--display);font-weight:700;font-size:26px;margin:28px 0 12px}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:16px}
.card{background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.08);display:flex;flex-direction:column}
.card img{width:100%;height:auto;aspect-ratio:1/1;object-fit:contain;background:#fff;display:block}
.card .body{padding:14px 14px 16px;display:flex;flex-direction:column;gap:8px;flex:1}
.card h3{font-size:17px;line-height:1.25;font-weight:700}
.fits{font-size:13px;color:var(--gray)}
details{font-size:13px;color:var(--gray)}summary{cursor:pointer;font-weight:600;color:var(--dark)}details p{margin-top:6px}
.price{font-family:var(--display);font-weight:800;font-size:24px;margin-top:auto;padding-top:6px}
.btn{display:inline-block;background:var(--red);color:#fff;text-decoration:none;font-weight:700;padding:10px 14px;border-radius:8px;text-align:center}
footer{max-width:1100px;margin:0 auto;padding:16px;color:var(--gray);font-size:13px;border-top:1px solid var(--gray-light)}
@media (max-width:600px){.grid{grid-template-columns:repeat(2,1fr);gap:10px}.intro h1{font-size:30px}.card h3{font-size:15px}.price{font-size:20px}}
</style>
</head>
<body>
<header class="topbar"><div class="brand">DishNet <span>Shop</span></div>' . ($wa !== '' ? '<a href="https://wa.me/' . $e($wa) . '" target="_blank" rel="noopener">Chat with sales</a>' : '') . '</header>
<main>
<section class="intro"><h1>Starlink accessories</h1>
<p>Genuine Starlink kits, mounts, routers and cables, priced in ' . $e($currency) . '. ' . $e($vatNote) . '. Order on WhatsApp and we confirm availability and delivery before you pay.</p>'
. ($stock !== '' ? '<p class="stock">' . $e($stock) . '</p>' : '') . '
</section>
' . $notice . $cards . '
</main>
<footer>' . $e($count) . ' product' . ($count === 1 ? '' : 's') . '. Prices from our billing system' . ($updated !== '' ? ', updated ' . $e(substr($updated, 0, 16)) . ' UTC' : '') . '. ' . $e($vatNote) . '. &copy; DishNet Africa.</footer>
</body>
</html>
';
    }

    /** "UGX 301,000" — whole shillings, never a decimal the customer did not see on the store. */
    public static function money(float $v, string $currency): string
    {
        $s = abs($v - round($v)) < 0.005 ? number_format($v, 0, '.', ',') : number_format($v, 2, '.', ',');
        return trim($currency . ' ' . $s);
    }
}
