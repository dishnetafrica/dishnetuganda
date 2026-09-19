<?php
declare(strict_types=1);

/**
 * PublicPriceFeed — the ONLY shape of pricing data that may leave the plugin
 * without authentication. Fed to the website's pricing sections.
 *
 * Contains customer prices and public descriptions, nothing else: no costs,
 * no margins, no supplier terms, no customer data. Names/prices come straight
 * from uCRM, so the website can never disagree with an invoice or the
 * WhatsApp AI. Items can be withheld from the public feed by name via
 * config['public_price_exclude'] (e.g. Flex plans until the Starlink leasing
 * confirmation arrives).
 */
class PublicPriceFeed
{
    /**
     * @param array $servicePlans uCRM service-plans rows (name, price, downloadSpeed?)
     * @param array $products     uCRM products rows (name, price, description?)
     * @param array $config       plugin config (currency_symbol, public_price_exclude)
     */
    public static function build(array $servicePlans, array $products, array $config): array
    {
        $exclude = array_map('strtolower', array_map('trim', (array)($config['public_price_exclude'] ?? [])));
        $keep = fn(string $name): bool => !in_array(strtolower(trim($name)), $exclude, true);

        $plans = [];
        foreach ($servicePlans as $p) {
            $name  = trim((string)($p['name'] ?? ''));
            $price = self::planPrice($p);
            if ($name === '' || $price === null || !$keep($name)) continue;
            $plans[] = [
                'name'        => $name,
                'price'       => round($price, 2),
                'period'      => 'month',
                'speed'       => (string)($p['downloadSpeed'] ?? $p['download_speed'] ?? ''),
                'description' => mb_substr(trim((string)($p['invoiceLabel'] ?? '')), 0, 200),
            ];
        }
        usort($plans, fn($a, $b) => $a['price'] <=> $b['price']);

        // Three lists, not one. Everything in uCRM Products used to arrive as
        // 'hardware', which the website renders as kit cards — so the twenty
        // accessories added on 16 Sep turned the kits page into twenty mounts
        // and cables each labelled "includes delivery, installation and your
        // first month", and the two plan mirrors had been sitting there as
        // kits for longer than that. The assistant already made this exact
        // split (DishNetTools::getProducts); the public feed now makes it
        // too, from the same shop catalogue and the same plan-name rule.
        require_once __DIR__ . '/ShopCatalogue.php';
        $accessoryKeys = ShopCatalogue::accessoryNameKeys(dirname(__DIR__));
        $planKeys = [];
        foreach ($servicePlans as $p) {
            $k = self::planKey((string)($p['name'] ?? ''));
            if ($k !== '') $planKeys[$k] = true;
        }

        $hardware = $accessories = [];
        foreach ($products as $h) {
            $name = trim((string)($h['name'] ?? ''));
            if ($name === '' || ($h['price'] ?? null) === null || !$keep($name)) continue;
            // A product spelled like a plan IS the plan, mirrored into
            // Products so a quotation can carry it as a line. It is a monthly
            // charge, so it is not a one-time item on anyone's page.
            $pk = self::planKey($name);
            if ($pk !== '' && isset($planKeys[$pk])) continue;
            $row = [
                'name'        => $name,
                'price'       => round((float)$h['price'], 2),
                'description' => mb_substr(trim((string)($h['description'] ?? '')), 0, 200),
            ];
            if (isset($accessoryKeys[ShopCatalogue::nameKey($name)])) {
                $row['slug'] = ShopCatalogue::slugForName(dirname(__DIR__), $name);
                $accessories[] = $row;
            } else {
                $hardware[] = $row;
            }
        }
        $byPrice = fn($a, $b) => $a['price'] <=> $b['price'];
        usort($hardware, $byPrice);
        usort($accessories, $byPrice);

        return [
            'v'           => 1,
            'currency'    => trim((string)(($config['currency_symbol'] ?? '') ?: 'UGX')),
            'vat_note'    => 'All prices VAT inclusive',
            'plans'       => $plans,
            'hardware'    => $hardware,
            'accessories' => $accessories,
            'updated_at'  => gmdate('c'),
        ];
    }

    /**
     * uCRM keeps a service plan's price inside its periods array, not at the
     * top level. Prefer the enabled 1-month period (the price the card was
     * built on); otherwise the cheapest enabled period; a plain price field
     * (tests, future API shapes) still works.
     */
    public static function planPrice(array $p): ?float
    {
        if (isset($p['price']) && is_numeric($p['price'])) return (float)$p['price'];
        $best = null;
        foreach ((array)($p['periods'] ?? []) as $per) {
            if (empty($per['enabled']) || !isset($per['price']) || !is_numeric($per['price'])) continue;
            if ((int)($per['period'] ?? 0) === 1) return (float)$per['price'];
            $best = $best === null ? (float)$per['price'] : min($best, (float)$per['price']);
        }
        return $best;
    }

    /**
     * The comparison key for "this product is really that plan".
     *
     * Same rule the assistant uses (DishNetTools::catalogueKey): letters and
     * digits only, with the word Starlink dropped, so "Starlink Residential
     * Lite ( up to 100 Mbps)" and "Residential Lite (up to 100 Mbps)" are one
     * thing spelled twice.
     */
    public static function planKey(string $name): string
    {
        $k = mb_strtolower(trim($name));
        $k = preg_replace('/\bstarlink\b/u', '', $k) ?? $k;
        return preg_replace('/[^a-z0-9]+/u', '', $k) ?? '';
    }

    /** The origins allowed to read the feed from a browser. */
    public static function allowedOrigins(array $config): array
    {
        $o = (array)($config['site_origins'] ?? []);
        return $o ?: ['https://dishnetuganda.com', 'https://www.dishnetuganda.com'];
    }
}
