<?php
/**
 * test_shop_part1.php — the accessories shop, part 1: one string ties it together.
 *
 * The uCRM product name is the key. The shop's photo and specs, the sync
 * tool, the assistant's ACCESSORIES block and the quotation all match on it,
 * and the price is read from uCRM every time — the content file holds none.
 * This drives the real sync tool against the fake uCRM, the real page
 * renderer, the real image route library, the real catalogue lookup inside
 * DishNetTools, and the real prompt.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

date_default_timezone_set('UTC');
$tmp = sys_get_temp_dir() . '/dn_shop_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);

require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/ShopCatalogue.php';
require_once $root . '/lib/ShopImages.php';
require_once $root . '/lib/ShopPage.php';
require_once $root . '/lib/DishNetTools.php';
require_once $root . '/lib/DishNetAiBrain.php';
require_once $root . '/lib/EventBus.php';
require_once $root . '/workers/WorkerBase.php';
require_once $root . '/workers/AiReplyWorker.php';

// ═══════════════════════════════════════════════════════════════════════════
echo "\n1. The content file: names, photos, and not one price\n";
$cat = ShopCatalogue::load($root);
$acc = array_values(array_filter($cat['items'], fn($i) => $i['kind'] === 'accessory'));
$kits = array_values(array_filter($cat['items'], fn($i) => $i['kind'] === 'kit'));
is_(count($acc) === 20 && count($kits) === 2, '20 accessories and 2 kits', count($acc) . '/' . count($kits));
$names = array_map(fn($i) => $i['name'], $cat['items']);
is_(count($names) === count(array_unique($names)), 'every name is unique');
$missingImg = [];
foreach ($cat['items'] as $i) if (!is_file($root . '/assets/shop/' . $i['image'])) $missingImg[] = $i['slug'];
is_($missingImg === [], 'every item has its photo on disk', implode(',', $missingImg));
$rawCat = json_decode((string)file_get_contents($root . '/assets/shop/catalogue.json'), true);
$hasPriceKey = false;
$walk = function ($n) use (&$walk, &$hasPriceKey) { if (!is_array($n)) return; foreach ($n as $k => $v) { if (is_string($k) && preg_match('/price|ugx|cost/i', $k)) $hasPriceKey = true; $walk($v); } };
$walk($rawCat['items'] ?? []);
is_(!$hasPriceKey, 'no price-shaped key anywhere in the content file');
$seed = array_map('str_getcsv', file($root . '/assets/shop/seed-2026-09-16.csv', FILE_IGNORE_NEW_LINES));
array_shift($seed);
$accKeys = array_map(fn($i) => ShopCatalogue::nameKey($i['name']), $acc);
$seedOk = count($seed) === 20 && count(array_filter($seed, fn($r) => in_array(ShopCatalogue::nameKey($r[0]), $accKeys, true) && is_numeric($r[1]) && (float)$r[1] > 0)) === 20;
is_($seedOk, 'the seed names exactly the 20 accessories with a positive price each');
$runtime = ['lib/ShopCatalogue.php', 'lib/ShopPage.php', 'lib/ShopImages.php', 'lib/DishNetTools.php', 'lib/DishNetAiBrain.php', 'workers/AiReplyWorker.php', 'shop.php', 'shop_img.php'];
$reads = array_filter($runtime, fn($f) => strpos((string)file_get_contents($root . '/' . $f), 'seed-2026') !== false);
is_($reads === [], 'no runtime file reads the seed', implode(',', $reads));

echo "\n2. Resolving content against uCRM: shown with the uCRM price, or hidden\n";
$ucrm = [
    ['id' => 10, 'name' => 'Mini Kit',                           'price' => 2249000],   // an alias of the kit's match list
    ['id' => 50, 'name' => 'Wall Mount | Mini',                  'price' => 301000],
    ['id' => 51, 'name' => ' router 3 | Starlink V4 or V5,  Mini ', 'price' => 827000], // spacing and case differ
    ['id' => 52, 'name' => 'Something Else',                     'price' => 5],
    ['id' => 53, 'name' => 'Pivot Mount | Mini',                 'price' => null],      // no price → hidden
];
$r = ShopCatalogue::resolve($cat, $ucrm);
$shown = array_column($r['items'], 'name');
is_($shown === ['Router 3 | Starlink V4 or V5, Mini', 'Wall Mount | Mini', 'Starlink Mini Kit'],
    'three shown, in catalogue order, matched by exact name whatever the spacing or case', json_encode($shown));
is_((float)$r['items'][1]['price'] === 301000.0 && (float)$r['items'][2]['price'] === 2249000.0, 'each with its uCRM price');
is_(count($r['missing']) === 19 && in_array('Pivot Mount | Mini', $r['missing'], true),
    'the 19 without a priced uCRM product are hidden, the unpriced one among them');

echo "\n3. The page\n";
$cfg  = ['contact_sales_wa' => '256705993348', 'stock_statement' => 'Standard and Mini kits are in stock in Kampala.', 'ai_currency' => 'UGX'];
$html = ShopPage::render($r['items'], $cfg, ['updated_at' => '2026-09-16T10:00:00+00:00', 'vat_note' => 'All prices VAT inclusive']);
is_(strpos($html, 'UGX 301,000') !== false && strpos($html, 'UGX 2,249,000') !== false, 'prices are printed as whole shillings with the currency');
is_(preg_match('~href="https://wa\.me/256705993348\?text=[^"]*Wall%20Mount%20%7C%20Mini[^"]*UGX%20301%2C000~', $html) === 1,
    'the order button opens the sales number with the product and price named');
is_(strpos($html, 'shop_img&amp;s=wall-mount-mini&amp;w=480') !== false && strpos($html, 'w=240 240w') !== false,
    'photos come through the image route with two sizes');
is_(strpos($html, 'Standard and Mini kits are in stock in Kampala.') !== false, 'the operator\'s stock line is shown');
is_(strpos($html, 'All prices VAT inclusive') !== false, 'the VAT note is shown');
is_(preg_match('/<h2 class="group">Starlink kits<\/h2>.*<h2 class="group">Routers and Wi-Fi<\/h2>.*<h2 class="group">Mounts<\/h2>/s', $html) === 1,
    'kits first, then routers, then mounts');
is_(substr_count($html, '<article class="card"') === 3, 'exactly one card per shown item');
$evil = ShopPage::render([['slug' => 'x', 'kind' => 'accessory', 'name' => 'Bad <script>alert(1)</script>', 'category' => 'Mount', 'fits' => '', 'specs' => '', 'image' => 'x.png', 'price' => 1000]], $cfg);
is_(strpos($evil, '<script>alert') === false && strpos($evil, '&lt;script&gt;') !== false, 'names are escaped');
$down = ShopPage::render([], $cfg, ['unavailable' => true]);
is_(strpos($down, 'Prices are unavailable') !== false && substr_count($down, '<article') === 0, 'uCRM down and no cache: a notice, no cards, never a stale price');

echo "\n4. Photos: looked up by slug, never by path; sized copies cached\n";
$src = ShopImages::resolve($root, $cat, 'wall-mount-mini');
is_($src !== null && basename($src['path']) === 'wall-mount-mini.jpg' && $src['mime'] === 'image/jpeg', 'a known slug resolves to its file');
is_(ShopImages::resolve($root, $cat, '../../manifest') === null && ShopImages::resolve($root, $cat, 'no-such-thing') === null, 'traversal and unknown slugs resolve to nothing');
// A catalogue file that has been tampered with: the image name climbs out of
// assets/shop to a real PNG elsewhere. The lookup by slug passes, the file
// exists, the type is an image — the path check alone must refuse it.
copy($root . '/assets/shop/router-mini.png', $tmp . '/outside.png');
$shopDir = realpath($root . '/assets/shop');
$climb   = str_repeat('../', count(array_filter(explode('/', $shopDir)))) . ltrim($tmp, '/') . '/outside.png';
is_(realpath($shopDir . '/' . $climb) === realpath($tmp . '/outside.png'), 'the crafted relative path really reaches the file outside');
$tampered = ['items' => [['slug' => 'evil', 'kind' => 'accessory', 'name' => 'Evil', 'match' => ['Evil'], 'category' => '', 'fits' => '', 'specs' => '', 'image' => $climb]]];
is_(ShopImages::resolve($root, $tampered, 'evil') === null, 'an image path that leaves assets/shop is refused even when the catalogue names it');
$v1 = ShopImages::variant($src, $tmp, 'wall-mount-mini', 240);
$gi = @getimagesize($v1['path']);
is_($v1['mime'] === 'image/webp' && $gi && $gi[0] === 240 && $gi[1] === 240, 'a 240 variant is a 240×240 WebP', json_encode([$v1, $gi]));
is_(filesize($v1['path']) < filesize($src['path']) / 3, 'and far smaller than the original');
$m1 = filemtime($v1['path']); clearstatcache(); usleep(20000);
$v2 = ShopImages::variant($src, $tmp, 'wall-mount-mini', 240);
is_($v2['path'] === $v1['path'] && filemtime($v2['path']) === $m1, 'the second request is served from the cache');
$v0 = ShopImages::variant($src, $tmp, 'wall-mount-mini', 999);
is_($v0['path'] === $src['path'], 'a size we do not make gets the original');

echo "\n5. The routes exist where the replies to the website are made\n";
$pub = (string)file_get_contents($root . '/public.php');
is_(strpos($pub, "if (\$page === 'shop') {") !== false && strpos($pub, "require __DIR__ . '/shop.php';") !== false, 'public.php routes ?page=shop');
is_(strpos($pub, "if (\$page === 'shop_img') {") !== false && strpos($pub, "require __DIR__ . '/shop_img.php';") !== false, 'and ?page=shop_img');

// ═══════════════════════════════════════════════════════════════════════════
echo "\n6. The sync tool against a fake uCRM\n";
$hit = function (int $port, string $p) {
    $ch = curl_init("http://127.0.0.1:{$port}{$p}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_PROXY => '']);
    $r = curl_exec($ch); curl_close($ch);
    return $r === false ? null : (string)$r;
};
$srv = null; $port = 0;
foreach (range(0, 9) as $slot) {
    $cand = 9930 + ((getmypid() + $slot * 31) % 60);
    $p = proc_open(sprintf('exec php -S 127.0.0.1:%d %s', $cand, escapeshellarg($root . '/tests/fixtures/fake_ucrm_shadow.php')),
                   [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    $ours = false;
    for ($i = 0; $i < 40; $i++) {
        $got = $hit($cand, '/__test/state');
        if ($got !== null) { $ours = strpos($got, 'FAKE-UCRM-SHADOW') !== false; break; }
        usleep(100000);
    }
    if ($ours) { $srv = $p; $port = $cand; break; }
    proc_terminate($p); proc_close($p);
}
if ($port === 0) { fwrite(STDERR, "could not start the fake uCRM\n"); exit(1); }
$hit($port, '/__test/clear_products');
array_map('unlink', glob(sys_get_temp_dir() . '/dishnet_catalogue_*.json') ?: []);

// The tool reads config from the data dir: point it at the fake uCRM.
file_put_contents($tmp . '/config.json', json_encode(['crm_base_url' => "http://127.0.0.1:{$port}", 'crm_auth_token' => 'TESTKEY']));
$run = function (string $args) use ($root, $tmp): array {
    exec('DN_DATA_DIR=' . escapeshellarg($tmp) . ' php ' . escapeshellarg($root . '/tools/shop_products_sync.php') . ' ' . $args . ' 2>&1', $out, $rc);
    return [$rc, implode("\n", $out)];
};
$products = fn() => json_decode((string)$hit($port, '/products'), true) ?: [];

[$rc, $out] = $run('');
is_($rc === 0 && substr_count($out, 'would CREATE') === 20 && strpos($out, 'dry run') !== false,
    'dry run: 20 would be created, nothing changed', $out);
is_(strpos($out, 'tax setting copied from: Starlink Mini Kit') !== false && strpos($out, 'taxable yes') !== false
    && strpos($out, 'no taxId on it') !== false,
    'the tax setting is taken from the kit already in uCRM: taxable, no taxId');
is_(preg_match('/uCRM product names:\n(  - .*\n){4}/', $out) === 1 && strpos($out, '  - Professional Installation') !== false,
    'the dry run prints the exact product names uCRM holds');
is_(count($products()) === 4, 'and uCRM still has its 4 products');

[$rc, $out] = $run('--apply');
is_($rc === 3 && strpos($out, 'REFUSED') !== false && count($products()) === 4,
    '--apply without --prices-include-tax is refused and creates nothing', $out);

[$rc, $out] = $run('--apply --prices-include-tax');
is_($rc === 0 && substr_count($out, 'created  id') === 20 && strpos($out, 'created 20 · failed 0') !== false,
    '--apply --prices-include-tax creates the 20', $out);
$live = $products();
is_(count($live) === 24, 'uCRM now has 24 products');
$byName = []; foreach ($live as $p) $byName[$p['name']] = $p;
is_(isset($byName['Wall Mount | Mini']) && (float)$byName['Wall Mount | Mini']['price'] === 301000.0
    && ($byName['Wall Mount | Mini']['taxable'] ?? null) === true && ($byName['Wall Mount | Mini']['taxId'] ?? null) === null
    && ($byName['Wall Mount | Mini']['unit'] ?? '') === 'pc' && !array_key_exists('description', $byName['Wall Mount | Mini']),
    'exact name, seed price, taxable like the kit, no taxId, unit pc, and only uCRM\'s own fields', json_encode($byName['Wall Mount | Mini'] ?? null));
is_(isset($byName['Ridgeline Mount | Standard 4 or 4 X']) && (float)$byName['Ridgeline Mount | Standard 4 or 4 X']['price'] === 1881000.0,
    'the dearest item carries its shelf price');

[$rc, $out] = $run('');
is_($rc === 0 && substr_count($out, ' EXISTS') === 20 && strpos($out, 'would CREATE') === false && strpos($out, 'nothing to create') !== false,
    'a second run finds all 20 and creates nothing (idempotent by exact name)', $out);

// Drift: a seed that disagrees with uCRM is reported and left alone.
$driftSeed = $tmp . '/seed_drift.csv';
$rows = file($root . '/assets/shop/seed-2026-09-16.csv', FILE_IGNORE_NEW_LINES);
$rows[1] = str_replace(',301000', ',999000', $rows[1]) === $rows[1] ? preg_replace('/,\d+$/', ',999000', $rows[1]) : str_replace(',301000', ',999000', $rows[1]);
file_put_contents($driftSeed, implode("\n", $rows) . "\n");
[$rc, $out] = $run('--seed ' . escapeshellarg($driftSeed) . ' --apply --prices-include-tax');
is_($rc === 0 && substr_count($out, 'DRIFT') === 1 && strpos($out, '(left alone)') !== false && count($products()) === 24,
    'a seed price that differs from uCRM is reported as drift and never written', $out);

echo "\n7. The assistant: accessories apart from the kit, and a tax fact it may state\n";
array_map('unlink', glob(sys_get_temp_dir() . '/dishnet_catalogue_*.json') ?: []);
putenv('DN_DATA_DIR=' . $tmp);
$store = SqliteStore::create($tmp);
$tools = new DishNetTools($store, ['crm_base_url' => "http://127.0.0.1:{$port}", 'crm_auth_token' => 'TESTKEY', 'catalogue_cache_seconds' => 0], $root);
$gp = $tools->getProducts();
is_(!empty($gp['ok']) && (int)$gp['data']['hardware_count'] === 2 && (int)$gp['data']['accessory_count'] === 20 && (int)$gp['data']['hardware_plan_mirrors'] === 2,
    'getProducts: 2 hardware (kit, installation), 20 accessories, 2 mirrors dropped', json_encode(array_intersect_key($gp['data'] ?? [], ['hardware_count' => 1, 'accessory_count' => 1, 'hardware_plan_mirrors' => 1])));
$brain = new DishNetAiBrain(['ai_provider' => 'openai', 'openai_api_key' => 'k', 'ai_currency' => 'UGX']);
$ctx = ['channel' => 'sales', 'message' => 'what does a wall mount cost?', 'identity_state' => 'unknown', 'products' => $gp['data']];
$prompt = $brain->promptPreview($ctx);
is_(strpos($prompt, 'ACCESSORIES (optional extras') !== false && strpos($prompt, '- Wall Mount | Mini — price 301000 one-time') !== false,
    'the prompt lists accessories under their own heading, at uCRM prices');
$hwBlock = substr($prompt, strpos($prompt, 'HARDWARE (one-time items'), strpos($prompt, 'ACCESSORIES (optional extras') - strpos($prompt, 'HARDWARE (one-time items'));
is_(strpos($hwBlock, 'Starlink Mini Kit') !== false && strpos($hwBlock, 'Professional Installation') !== false && strpos($hwBlock, 'Wall Mount') === false,
    'HARDWARE keeps the kit and the installation only');
is_(strpos($prompt, 'Never add an accessory into TOTAL TO GET CONNECTED unless the customer chose it') !== false, 'and the total rule names accessories as extras');
is_(strpos($prompt, '- PRICES:') === false, 'no tax fact when none is set');
$brain2 = new DishNetAiBrain(['ai_provider' => 'openai', 'openai_api_key' => 'k', 'ai_fact_prices' => 'All our listed prices include VAT.']);
$p2 = $brain2->promptPreview(['channel' => 'sales', 'message' => 'x', 'identity_state' => 'unknown']);
is_(strpos($p2, '- PRICES: All our listed prices include VAT. This is a stated fact you may repeat; it does not permit you to calculate a tax amount or rate.') !== false,
    'the operator\'s VAT statement is a business fact, with the no-arithmetic fence attached');

echo "\n8. The price guard permits kit + installation + a chosen accessory\n";
$w = new AiReplyWorker($store, ['ai_provider' => 'openai', 'openai_api_key' => 'k', 'evo_api_url' => 'http://127.0.0.1:1', 'evo_api_key' => 'x', 'evo_instance_sales' => 'i'], 20, 5);
$m = new ReflectionMethod(AiReplyWorker::class, 'permittedValues'); $m->setAccessible(true);
$vals = $m->invoke($w, ['products' => ['hardware' => [['price' => 2249000], ['price' => 150000]], 'accessories' => [['price' => 301000], ['price' => 377000]]], 'message' => 'x'], '');
is_(in_array('2700000', $vals, true) && in_array('3077000', $vals, true) && in_array('2399000', $vals, true),
    'kit + installation (+ one or two accessories) are permitted sums');
is_(in_array('678000', $vals, true), 'two accessories alone are a permitted sum as well');
is_(!in_array('3078000', $vals, true), 'an off-by-a-thousand total is not');

// ── Clean up ────────────────────────────────────────────────────────────────
if ($srv) { proc_terminate($srv); proc_close($srv); }
array_map('unlink', glob(sys_get_temp_dir() . '/fake_ucrm_shadow_*.json') ?: []);
array_map('unlink', glob(sys_get_temp_dir() . '/dishnet_catalogue_*.json') ?: []);
putenv('DN_DATA_DIR');
exec('rm -rf ' . escapeshellarg($tmp));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
