<?php
declare(strict_types=1);
/**
 * The ONE source of truth for how this plugin renders money.
 *
 * Every screen, WhatsApp message, PDF and API payload asks these helpers
 * instead of hardcoding a symbol, so a single config key moves the whole
 * plugin between markets:
 *
 *   currency_symbol  what people see   ("UGX", "$", "KSh")   default UGX
 *   currency_code    what APIs get     ("UGX", "USD")        default derived
 *
 * Deliberately NOT covered: the Sudan-only dual-currency subsystems
 * (retailer app, LTE stack, SSP cash screens) where "$" genuinely means
 * US dollars next to SSP — those keep their literal symbols.
 */
if (!function_exists('dn_cur')) {
    /** Display prefix for amounts: configured symbol + a space, e.g. "UGX ". */
    function dn_cur(?array $config = null): string
    {
        $config = $config ?? [];
        $s = trim((string)(($config['currency_symbol'] ?? '') ?: 'UGX'));
        return htmlspecialchars($s, ENT_QUOTES) . ' ';
    }

    /** Currency CODE for API payloads (uCRM currencyCode etc.), e.g. "UGX". */
    function dn_code(?array $config = null): string
    {
        $config = $config ?? [];
        $c = strtoupper(trim((string)($config['currency_code'] ?? '')));
        if (preg_match('/^[A-Z]{3}$/', $c)) return $c;
        $s = strtoupper(trim((string)(($config['currency_symbol'] ?? '') ?: 'UGX')));
        if ($s === '$') return 'USD';
        return preg_match('/^[A-Z]{3}$/', $s) ? $s : 'UGX';
    }
}

/*
 * Ledger-data currency (the CASHBOOK layer) — distinct from the display
 * layer above on purpose. dn_cur/dn_code decide what a customer SEES;
 * dn_book_* decide what a ledger row IS. The defaults preserve the Sudan
 * installation byte-for-byte: base USD, currencies USD+SSP. The Uganda
 * install overrides via configuration (cashbook_base_currency=UGX,
 * cashbook_currencies=UGX,USD) — SSP then simply never renders in entry
 * forms, without touching the Sudan code paths.
 */
if (!function_exists('dn_book_base')) {
    /** The operating (base) currency of the cashbook ledger. */
    function dn_book_base(?array $config): string
    {
        $c = strtoupper(trim((string)($config['cashbook_base_currency'] ?? '')));
        return preg_match('/^[A-Z]{3}$/', $c) ? $c : 'USD';
    }

    /** Selectable ledger currencies, base guaranteed first. */
    function dn_book_currencies(?array $config): array
    {
        $base = dn_book_base($config);
        $raw  = strtoupper(trim((string)($config['cashbook_currencies'] ?? '')));
        $list = [];
        foreach ($raw === '' ? ['USD', 'SSP'] : explode(',', $raw) as $c) {
            $c = trim($c);
            if (preg_match('/^[A-Z]{3}$/', $c) && !in_array($c, $list, true)) $list[] = $c;
        }
        if (!$list) $list = [$base];
        // The base currency is always present and always first.
        $list = array_values(array_unique(array_merge([$base], array_diff($list, [$base]))));
        return $list;
    }

    /**
     * The currency a CRM payment must be booked in: the payment's OWN
     * currencyCode, never a hard-coded literal. Falls back to the book base
     * only when uCRM did not say (which, on both installs, matches reality).
     */
    function dn_payment_currency(array $payment, ?array $config): string
    {
        $c = strtoupper(trim((string)($payment['currencyCode'] ?? ($payment['currency'] ?? ''))));
        return preg_match('/^[A-Z]{3}$/', $c) ? $c : dn_book_base($config);
    }
}
require_once __DIR__ . '/crm_url.php';
