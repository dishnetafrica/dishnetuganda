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
            $name = trim((string)($p['name'] ?? ''));
            if ($name === '' || ($p['price'] ?? null) === null || !$keep($name)) continue;
            $plans[] = [
                'name'        => $name,
                'price'       => round((float)$p['price'], 2),
                'period'      => 'month',
                'speed'       => (string)($p['downloadSpeed'] ?? $p['download_speed'] ?? ''),
                'description' => mb_substr(trim((string)($p['invoiceLabel'] ?? '')), 0, 200),
            ];
        }
        usort($plans, fn($a, $b) => $a['price'] <=> $b['price']);

        $hardware = [];
        foreach ($products as $h) {
            $name = trim((string)($h['name'] ?? ''));
            if ($name === '' || ($h['price'] ?? null) === null || !$keep($name)) continue;
            $hardware[] = [
                'name'        => $name,
                'price'       => round((float)$h['price'], 2),
                'description' => mb_substr(trim((string)($h['description'] ?? '')), 0, 200),
            ];
        }
        usort($hardware, fn($a, $b) => $a['price'] <=> $b['price']);

        return [
            'v'          => 1,
            'currency'   => trim((string)(($config['currency_symbol'] ?? '') ?: 'UGX')),
            'vat_note'   => 'All prices VAT inclusive',
            'plans'      => $plans,
            'hardware'   => $hardware,
            'updated_at' => gmdate('c'),
        ];
    }

    /** The origins allowed to read the feed from a browser. */
    public static function allowedOrigins(array $config): array
    {
        $o = (array)($config['site_origins'] ?? []);
        return $o ?: ['https://dishnetuganda.com', 'https://www.dishnetuganda.com'];
    }
}
