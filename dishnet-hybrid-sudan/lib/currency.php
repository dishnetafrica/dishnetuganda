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

    /**
     * An amount with its symbol attached, ready to drop into a message.
     *
     * dn_cur() always appends a space, which reads correctly for an
     * alphabetic code ("UGX 1,645,440.00") and wrongly for a sigil
     * ("$ 1,234.00"). Message copy across the plugin wrote "$" tight against
     * the digits, so this keeps that spacing for a sigil and adds the space
     * only for a letter code. Every existing dollar rendering therefore comes
     * out byte-identical while Uganda reads properly.
     *
     * Takes a number or an already-formatted string, because callers pass both.
     */
    function dn_money($v, ?array $config = null, ?int $dp = 2): string
    {
        $sym = rtrim(dn_cur($config));
        // $dp === null means "print the number exactly as handed over", for
        // callers whose spacing and decimals are already what they want.
        $num = ($dp !== null && is_numeric($v)) ? number_format((float)$v, $dp) : (string)$v;
        return $sym . (preg_match('/[A-Za-z]$/', $sym) ? ' ' : '') . $num;
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
    /**
     * The effective per-install configuration, read straight from the config
     * FILES (uCRM's config.json + the operator-override kyc_config.json).
     *
     * Exists because two backends answer to the name kyc_config.json: the
     * override writer (PluginConfig::saveOverrides) writes the FILE, while
     * some page contexts hydrate $config from the SqliteStore copy — which
     * never learns new keys. Pages passing such a partial $config would
     * silently fall back to Sudan defaults; this reader makes dn_book_*
     * self-sufficient. Pure reads, cached per request, no side effects.
     */
    function dn_book_effective_config(): array
    {
        static $cfg = null;
        if ($cfg !== null) return $cfg;
        $cfg = [];
        $root = dirname(__DIR__);
        $dataDir = $GLOBALS['dataDir'] ?? ($root . '/data');
        foreach ([$root . '/data/config.json', $dataDir . '/config.json',
                  $dataDir . '/kyc_config.json'] as $p) {
            if (!is_file($p)) continue;
            $d = json_decode((string)@file_get_contents($p), true);
            if (is_array($d)) $cfg = array_merge($cfg, $d);
        }
        return $cfg;
    }

    /** The operating (base) currency of the cashbook ledger. */
    function dn_book_base(?array $config): string
    {
        $v = $config['cashbook_base_currency']
          ?? (dn_book_effective_config()['cashbook_base_currency'] ?? '');
        $c = strtoupper(trim((string)$v));
        return preg_match('/^[A-Z]{3}$/', $c) ? $c : 'USD';
    }

    /** Selectable ledger currencies, base guaranteed first. */
    function dn_book_currencies(?array $config): array
    {
        $base = dn_book_base($config);
        $rawV = $config['cashbook_currencies']
             ?? (dn_book_effective_config()['cashbook_currencies'] ?? '');
        $raw  = strtoupper(trim((string)$rawV));
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

    /**
     * Normalise an operator-entered ledger currency against the install's
     * selectable list. A selectable code passes through unchanged; anything
     * else falls to $default when that is itself selectable, else to the
     * book base. This replaces every hard-coded ['USD','SSP'] whitelist —
     * the exact pattern that silently rewrote a Uganda operator's UGX entry
     * to USD. Sudan identity: with nothing configured the list is USD,SSP
     * and the base is USD, so USD/SSP pass through and garbage still lands
     * on USD, byte-for-byte the old behaviour.
     */
    function dn_entry_currency($posted, ?array $config, string $default = ''): string
    {
        $list = dn_book_currencies($config);
        $c = strtoupper(trim((string)$posted));
        if (in_array($c, $list, true)) return $c;
        $d = strtoupper(trim($default));
        if ($d !== '' && in_array($d, $list, true)) return $d;
        return $list[0];
    }

    /** SSP flows (exchange legs, registers, backfills) exist only where SSP is bookable. */
    function dn_ssp_selectable(?array $config): bool
    {
        return in_array('SSP', dn_book_currencies($config), true);
    }

    /**
     * The currencyCode for a payment/quote the plugin CREATES inside uCRM:
     * the currency the money was actually recorded in, else the book base.
     * Deliberately NOT dn_code() — the display default is UGX, which would
     * mint UGX payments on an unconfigured Sudan install; dn_book_base()
     * defaults to USD there. No guessing beyond that one honest fallback.
     */
    function dn_payload_currency($recorded, ?array $config): string
    {
        $c = strtoupper(trim((string)$recorded));
        return preg_match('/^[A-Z]{3}$/', $c) ? $c : dn_book_base($config);
    }
}
require_once __DIR__ . '/crm_url.php';
