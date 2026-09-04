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
