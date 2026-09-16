<?php
declare(strict_types=1);

/**
 * ShopCatalogue — the accessories shop's content, and nothing that prices it.
 *
 * assets/shop/catalogue.json holds, per item: a slug, the exact product name
 * as it must appear in uCRM Products, the fit line, the spec text and the
 * photo file. It holds NO price. resolve() marries each item to the live
 * uCRM product with the same name and takes the price from there; an item
 * with no uCRM product is hidden rather than shown at a stale figure. The
 * same names split the assistant's HARDWARE list into kit-and-installation
 * versus ACCESSORIES, so the shop, the assistant and the quotation all hang
 * off one string: the uCRM product name.
 *
 * Kits carry a `match` list of acceptable uCRM spellings because the kit
 * products already existed before this file did.
 */
final class ShopCatalogue
{
    const FILE = 'assets/shop/catalogue.json';
    const DIR  = 'assets/shop';

    /** @return array{version:int, items:array<int,array>} */
    public static function load(string $pluginRoot): array
    {
        $file = rtrim($pluginRoot, '/') . '/' . self::FILE;
        $raw  = is_file($file) ? (string)@file_get_contents($file) : '';
        $d    = json_decode($raw, true);
        $items = [];
        foreach ((array)($d['items'] ?? []) as $it) {
            if (!is_array($it)) continue;
            $name = trim((string)($it['name'] ?? ''));
            $slug = trim((string)($it['slug'] ?? ''));
            if ($name === '' || !preg_match('/^[a-z0-9][a-z0-9-]{0,80}$/', $slug)) continue;
            $match = array_values(array_filter(array_map(
                fn($m) => trim((string)$m), (array)($it['match'] ?? [$name])), 'strlen'));
            if ($match === []) $match = [$name];
            $items[] = [
                'slug'     => $slug,
                'kind'     => (string)($it['kind'] ?? 'accessory') === 'kit' ? 'kit' : 'accessory',
                'name'     => $name,
                'match'    => $match,
                'category' => trim((string)($it['category'] ?? '')),
                'fits'     => trim((string)($it['fits'] ?? '')),
                'specs'    => trim((string)($it['specs'] ?? '')),
                'image'    => basename(trim((string)($it['image'] ?? ''))),
            ];
        }
        return ['version' => (int)($d['version'] ?? 0), 'items' => $items];
    }

    /** Exact-name key: trimmed, lower-cased, inner whitespace collapsed. */
    public static function nameKey(string $name): string
    {
        return mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', $name)));
    }

    /**
     * The accessory names as keys, for the split of the assistant's list.
     * Kits are not accessories.
     *
     * @return array<string,true>
     */
    public static function accessoryNameKeys(string $pluginRoot): array
    {
        $out = [];
        foreach (self::load($pluginRoot)['items'] as $it) {
            if ($it['kind'] !== 'accessory') continue;
            foreach ($it['match'] as $m) $out[self::nameKey($m)] = true;
        }
        return $out;
    }

    public static function bySlug(array $catalogue, string $slug): ?array
    {
        foreach ((array)($catalogue['items'] ?? []) as $it) {
            if (($it['slug'] ?? '') === $slug) return $it;
        }
        return null;
    }

    /**
     * Marry the content to the live uCRM products.
     *
     * @param array $catalogue    load() result
     * @param array $ucrmProducts rows with at least name and price (uCRM rows,
     *                            PublicPriceFeed hardware rows, or DishNetTools
     *                            hardware rows all fit)
     * @return array{items:array<int,array>, missing:array<int,string>}
     *         items: catalogue entries with 'price', 'ucrm_name' and 'ucrm_id'
     *         added, in catalogue order; missing: catalogue names with no
     *         uCRM product, which the shop therefore does not show.
     */
    public static function resolve(array $catalogue, array $ucrmProducts): array
    {
        $byKey = [];
        foreach ($ucrmProducts as $p) {
            if (!is_array($p)) continue;
            $name = trim((string)($p['name'] ?? ''));
            if ($name === '' || !isset($p['price']) || $p['price'] === null || !is_numeric($p['price'])) continue;
            $k = self::nameKey($name);
            if (!isset($byKey[$k])) $byKey[$k] = ['name' => $name, 'price' => (float)$p['price'], 'id' => (int)($p['id'] ?? 0)];
        }
        $items = []; $missing = [];
        foreach ((array)($catalogue['items'] ?? []) as $it) {
            $hit = null;
            foreach ($it['match'] as $m) {
                $k = self::nameKey($m);
                if (isset($byKey[$k])) { $hit = $byKey[$k]; break; }
            }
            if ($hit === null) { $missing[] = $it['name']; continue; }
            $items[] = $it + ['price' => $hit['price'], 'ucrm_name' => $hit['name'], 'ucrm_id' => $hit['id']];
        }
        return ['items' => $items, 'missing' => $missing];
    }
}
