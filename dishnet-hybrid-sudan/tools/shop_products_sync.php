<?php
declare(strict_types=1);
chdir(dirname(__DIR__));
/**
 * shop_products_sync.php — put the accessories catalogue into uCRM Products.
 *
 *   php tools/shop_products_sync.php                              report: exists / would create / drift
 *   php tools/shop_products_sync.php --apply --prices-include-tax  create the missing products
 *   php tools/shop_products_sync.php --tax-like "Starlink Mini Kit"  copy the tax setting from that product
 *   php tools/shop_products_sync.php --seed path/to/seed.csv        another seed file
 *
 * uCRM is the only place a price lives. This tool exists so that twenty
 * products are created with their EXACT names — the shop's photo and specs,
 * the assistant's catalogue line and the quotation all match on that
 * string, and one typo hides an item — and it is deliberately narrow:
 *
 *   - it CREATES products that do not exist yet, by exact name;
 *   - it NEVER changes an existing product. A seed price that differs from
 *     uCRM is reported as drift and left alone: uCRM is right by definition;
 *   - it refuses to apply without --prices-include-tax, the operator's
 *     confirmation that uCRM's pricing mode enters prices with tax included
 *     and that the seed prices are VAT-inclusive customer prices. It never
 *     divides by a rate to work out a net price;
 *   - the tax setting is copied from an existing product (--tax-like, or the
 *     first kit the catalogue recognises), so a new product carries the same
 *     VAT the kits do. Nothing here invents a tax figure.
 *
 * The seed (assets/shop/seed-2026-09-16.csv) is read by this tool only.
 * Nothing at runtime — not the shop, not the assistant — reads it.
 *
 * Exit: 0 done · 1 a creation failed · 2 uCRM not configured · 3 refused.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/CrmApiClient.php';
require_once $root . '/lib/ShopCatalogue.php';

$opt     = getopt('', ['apply', 'prices-include-tax', 'seed:', 'tax-like:']);
$apply   = isset($opt['apply']);
$taxOk   = isset($opt['prices-include-tax']);
$seed    = (string)($opt['seed'] ?? ($root . '/assets/shop/seed-2026-09-16.csv'));
$taxLike = trim((string)($opt['tax-like'] ?? ''));

$dataDir = cliDataDir($root);
$store   = SqliteStore::create($dataDir);
$config  = (array)($store->load('kyc_config.json') ?: []) + PluginConfig::load($root, $dataDir);

$crm = CrmApiClient::fromUcrm($root, $config);
if (!$crm->isConfigured()) {
    fwrite(STDERR, "shop_products_sync: uCRM is not configured (no crm_base_url / ucrm.json) — nothing to compare against\n");
    exit(2);
}

// ── Seed: exact name → VAT-inclusive shelf price ────────────────────────
if (!is_file($seed)) { fwrite(STDERR, "shop_products_sync: seed file not found: {$seed}\n"); exit(2); }
$seedPrices = [];
$fh = fopen($seed, 'r');
$header = fgetcsv($fh);
while (($row = fgetcsv($fh)) !== false) {
    if (count($row) < 2) continue;
    $name = trim((string)$row[0]); $price = trim((string)$row[1]);
    if ($name === '' || !is_numeric($price)) continue;
    $seedPrices[ShopCatalogue::nameKey($name)] = ['name' => $name, 'price' => (float)$price];
}
fclose($fh);

// ── The catalogue's accessories, and what uCRM has ──────────────────────
$catalogue = ShopCatalogue::load($root);
$live      = $crm->get('products') ?? [];
$liveByKey = [];
foreach ($live as $p) {
    if (!is_array($p)) continue;
    $k = ShopCatalogue::nameKey((string)($p['name'] ?? ''));
    if ($k !== '' && !isset($liveByKey[$k])) $liveByKey[$k] = $p;
}

// Reference product for the tax setting.
$ref = null;
if ($taxLike !== '') {
    $ref = $liveByKey[ShopCatalogue::nameKey($taxLike)] ?? null;
    if ($ref === null) { fwrite(STDERR, "shop_products_sync: --tax-like product not found in uCRM: {$taxLike}\n"); exit(2); }
} else {
    foreach ($catalogue['items'] as $it) {
        if ($it['kind'] !== 'kit') continue;
        foreach ($it['match'] as $m) {
            if (isset($liveByKey[ShopCatalogue::nameKey($m)])) { $ref = $liveByKey[ShopCatalogue::nameKey($m)]; break 2; }
        }
    }
    if ($ref === null && $live) $ref = is_array($live[0]) ? $live[0] : null;
}
$taxId = ($ref !== null && array_key_exists('taxId', $ref) && $ref['taxId'] !== null) ? (int)$ref['taxId'] : null;

printf("shop_products_sync — data dir %s\n", $dataDir);
printf("uCRM products: %d · catalogue accessories: %d · seed rows: %d\n",
    count($live), count(array_filter($catalogue['items'], fn($i) => $i['kind'] === 'accessory')), count($seedPrices));
if ($ref !== null) {
    printf("tax setting copied from: %s (fields: %s)%s\n", (string)($ref['name'] ?? '?'),
        implode(', ', array_keys($ref)), $taxId === null ? ' — no taxId on it; new products get uCRM\'s default tax' : " — taxId {$taxId}");
}
echo "\n";
printf("%-58s %14s  %-8s %s\n", 'product', 'seed price', 'uCRM', 'action');

$toCreate = []; $exists = 0; $drift = 0; $noSeed = 0;
foreach ($catalogue['items'] as $it) {
    if ($it['kind'] !== 'accessory') continue;
    $k    = ShopCatalogue::nameKey($it['name']);
    $sp   = $seedPrices[$k]['price'] ?? null;
    $liv  = $liveByKey[$k] ?? null;
    if ($liv !== null) {
        $exists++;
        $lp = isset($liv['price']) ? (float)$liv['price'] : null;
        $d  = ($sp !== null && $lp !== null && abs($lp - $sp) > 0.005);
        if ($d) $drift++;
        printf("%-58s %14s  %-8s %s\n", mb_substr($it['name'], 0, 58), $sp === null ? '—' : number_format($sp, 0, '.', ','),
            'id ' . (int)($liv['id'] ?? 0), $d ? 'EXISTS, DRIFT: uCRM has ' . number_format((float)$lp, 0, '.', ',') . ' (left alone)' : 'EXISTS');
        continue;
    }
    if ($sp === null) {
        $noSeed++;
        printf("%-58s %14s  %-8s %s\n", mb_substr($it['name'], 0, 58), '—', '—', 'NO SEED PRICE — skipped');
        continue;
    }
    $toCreate[] = ['item' => $it, 'price' => $sp];
    printf("%-58s %14s  %-8s %s\n", mb_substr($it['name'], 0, 58), number_format($sp, 0, '.', ','), '—', $apply ? 'CREATE' : 'would CREATE');
}
echo "\n";
printf("exists %d · drift %d · to create %d · without seed price %d\n", $exists, $drift, count($toCreate), $noSeed);

if (!$apply) {
    if ($toCreate) echo "dry run — nothing changed. Re-run with --apply --prices-include-tax to create the missing products.\n";
    else echo "nothing to create.\n";
    exit(0);
}
if (!$taxOk) {
    fwrite(STDERR, "\nREFUSED: --apply needs --prices-include-tax.\n"
        . "That flag is your statement that uCRM's pricing mode enters prices WITH tax and that the seed\n"
        . "prices are VAT-inclusive customer prices. If uCRM enters prices WITHOUT tax, typing the shelf\n"
        . "price would make the invoice come out higher: fix the seed to net prices first. This tool never\n"
        . "divides by a rate to guess a net price.\n");
    exit(3);
}

$failed = 0;
foreach ($toCreate as $c) {
    $it = $c['item'];
    $payload = ['name' => $it['name'], 'price' => $c['price'], 'unit' => 'pc'];
    if ($it['fits'] !== '') $payload['description'] = 'Fits: ' . mb_substr($it['fits'], 0, 200);
    if ($taxId !== null) $payload['taxId'] = $taxId;
    $res = $crm->post('products', $payload);
    if (is_array($res) && !empty($res['id'])) {
        printf("created  id %-5d %s\n", (int)$res['id'], $it['name']);
    } else {
        $failed++;
        $err = $crm->getLastError();
        printf("FAILED   %s — %s\n", $it['name'], json_encode($err, JSON_UNESCAPED_UNICODE));
    }
}
echo "\n";
printf("created %d · failed %d\n", count($toCreate) - $failed, $failed);
if ($failed === 0 && $toCreate) {
    echo "Re-run without --apply to confirm every item now reads EXISTS. The shop and the assistant pick\n"
       . "the new products up within a minute (assistant) and ten minutes (shop cache).\n";
}
exit($failed > 0 ? 1 : 0);
