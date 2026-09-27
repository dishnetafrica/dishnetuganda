<?php
declare(strict_types=1);
/**
 * TenantProfile — one profile, many readers (Phase 2 of the customer-login
 * audit, plan §D).
 *
 * A single object answers every country-dependent question. The profiles
 * shipped with the plugin (profiles/*.json) hold the values; the selector
 * `tenant_profile` (south-sudan | uganda) picks one; when it is unset the
 * profile is derived from the currency exactly as PortalLocale always did
 * (UGX → uganda, SSP/USD → south-sudan) and otherwise south-sudan — so an
 * install that configures nothing gets profiles/south-sudan.json, which is
 * exactly the literals the code carried before, byte for byte.
 *
 * Resolution per field, everywhere a reader uses this class:
 *     explicit configuration key  →  profile file  →  the reader's own literal
 * Explicit keys keep winning (contact_*, email_*, overdue_email_*, timezone,
 * portal_dial_code, …), so nothing already configured on an install is lost.
 *
 * This file carries no dial code, zone or phone number of its own: a test
 * reads it to prove that. Values that the repository cannot answer for a
 * tenant are null in the profile and render as "not configured" — never as
 * the other tenant's value.
 */
final class TenantProfile
{
    public const SELECTOR_KEY = 'tenant_profile';
    public const IDS          = ['south-sudan', 'uganda'];
    public const DEFAULT_ID   = 'south-sudan';
    /** The keys a caller may pass to fill() so a vault-only currency still selects the profile. */
    public const SELECTOR_SOURCES = [self::SELECTOR_KEY, 'currency_code', 'cashbook_base_currency'];
    public const NOT_CONFIGURED = 'not configured';

    private string $id;
    private array $data;
    private array $config;
    /** @var array<string,self> */
    private static array $loaded = [];

    private function __construct(string $id, array $data, array $config)
    {
        $this->id = $id; $this->data = $data; $this->config = $config;
    }

    /**
     * The profile for this configuration. When a data directory is known the
     * vault is consulted for the selector sources, because public.php builds
     * $config from the store copy alone and currency_code is a vault key.
     */
    public static function current(array $config = [], ?string $dataDir = null): self
    {
        if ($dataDir !== null && $dataDir !== '') {
            // The vault read is memoised per request: the same data directory
            // and the same selector inputs give the same profile.
            static $memo = [];
            $sel = [];
            foreach (self::SELECTOR_SOURCES as $k) $sel[$k] = $config[$k] ?? null;
            $key = $dataDir . '|' . json_encode($sel);
            if (!isset($memo[$key])) {
                $filled = $config;
                try {
                    require_once __DIR__ . '/ConfigVault.php';
                    $filled = ConfigVault::fill(dirname(__DIR__), $dataDir, $config, self::SELECTOR_SOURCES);
                } catch (\Throwable $e) { /* the passed configuration decides */ }
                $memo[$key] = self::resolveId(is_array($filled) ? $filled : $config);
            }
            return self::load($memo[$key], $config);
        }
        return self::load(self::resolveId($config), $config);
    }

    /** Which profile a configuration selects, without loading it. */
    public static function resolveId(array $config): string
    {
        $sel = strtolower(trim((string)($config[self::SELECTOR_KEY] ?? '')));
        if (in_array($sel, self::IDS, true)) return $sel;
        $cur = strtoupper(trim((string)($config['currency_code'] ?? $config['cashbook_base_currency'] ?? '')));
        if ($cur === 'UGX') return 'uganda';
        if ($cur === 'SSP' || $cur === 'USD') return self::DEFAULT_ID;
        return self::DEFAULT_ID;
    }

    /** A named profile, with an optional configuration whose explicit keys win. */
    public static function load(string $id, array $config = []): self
    {
        if (!in_array($id, self::IDS, true)) $id = self::DEFAULT_ID;
        if (!isset(self::$loaded[$id])) {
            $file = dirname(__DIR__) . '/profiles/' . $id . '.json';
            $data = json_decode((string)@file_get_contents($file), true);
            if (!is_array($data)) throw new \RuntimeException("tenant profile {$id} is missing or unreadable");
            self::$loaded[$id] = $data;
        }
        return new self($id, self::$loaded[$id], $config);
    }

    public function id(): string { return $this->id; }

    /** Raw profile value by dotted path; null when the profile does not answer. */
    public function get(string $path, $default = null)
    {
        $node = $this->data;
        foreach (explode('.', $path) as $step) {
            if (!is_array($node) || !array_key_exists($step, $node)) return $default;
            $node = $node[$step];
        }
        return $node === null ? $default : $node;
    }

    /** A non-empty explicit configuration value, or null. */
    private function cfg(string $key): ?string
    {
        $v = $this->config[$key] ?? null;
        if ($v === null || is_array($v)) return null;
        $v = trim((string)$v);
        return $v === '' ? null : $v;
    }

    /** A profile string, or the reader's literal when the profile has none. */
    public function text(string $path, string $literal = ''): string
    {
        $v = $this->get($path);
        return is_string($v) && $v !== '' ? $v : $literal;
    }

    /** What a screen prints for a value the profile cannot answer. */
    public static function notConfigured(): string { return self::NOT_CONFIGURED; }

    // ── Country and phone ───────────────────────────────────────────────────

    /** Digits only. explicit portal_dial_code → contact_country_code → profile. '' when nothing answers. */
    public function dialCode(): string
    {
        foreach (['portal_dial_code', 'contact_country_code'] as $k) {
            $v = $this->cfg($k);
            if ($v !== null) { $d = (string)preg_replace('/\D+/', '', $v); if ($d !== '') return $d; }
        }
        return (string)preg_replace('/\D+/', '', (string)$this->get('country.dial_code', ''));
    }

    public function nsnLength(): int
    {
        $n = (int)$this->get('country.nsn_length', 9);
        return $n >= 6 ? $n : 9;
    }

    public function countryName(): string { return $this->cfg('portal_country_name') ?? $this->text('country.name'); }
    public function countryCode(): string { return $this->text('country.code'); }
    public function phoneExample(): string { return $this->text('country.example'); }

    // ── Time and money ──────────────────────────────────────────────────────

    public function timezone(): ?string { $v = $this->get('timezone'); return is_string($v) && $v !== '' ? $v : null; }
    public function currencyCode(): string { return $this->text('currency.code'); }

    // ── Identity and contacts ───────────────────────────────────────────────

    public function legalEntity(): string { return $this->text('legal_entity'); }
    public function tradingName(): string { return $this->text('trading_name'); }
    public function locality(): string    { return $this->text('locality'); }
    public function website(): string     { return $this->text('website'); }
    public function email(): string       { return $this->text('email'); }

    /** The CustomerContact keys this profile answers (contact_<name>), values only where the profile has one. */
    public function contactDefaults(): array
    {
        $out = [];
        foreach ((array)$this->get('contacts', []) as $k => $v) if (is_string($v) && $v !== '') $out['contact_' . $k] = $v;
        $dial = (string)preg_replace('/\D+/', '', (string)$this->get('country.dial_code', ''));
        if ($dial !== '') $out['contact_country_code'] = $dial;
        foreach (['pay_url', 'app_url'] as $k) { $v = $this->get($k); if (is_string($v) && $v !== '') $out['contact_' . $k] = $v; }
        return $out;
    }

    /** The EmailTemplate keys this profile answers (email_<name>). */
    public function emailBrandDefaults(): array
    {
        $out = [];
        foreach ((array)$this->get('email_brand', []) as $k => $v) if (is_string($v)) $out['email_' . $k] = $v;
        return $out;
    }

    /** The OverdueDunningHelpers keys this profile answers (overdue_email_<name>). */
    public function dunningDefaults(): array
    {
        $out = [];
        foreach ((array)$this->get('dunning', []) as $k => $v) if (is_string($v) && $v !== '') $out['overdue_email_' . $k] = $v;
        return $out;
    }

    /** The login page's own strings. */
    public function login(string $field, string $literal = ''): string { return $this->text('login.' . $field, $literal); }

    /** A value of the profile's `contacts` block, as stored (digits for *_wa, formatted for *_phone), or the reader's literal. */
    public function contact(string $name, string $literal = ''): string { return $this->text('contacts.' . $name, $literal); }

    /** The products this tenant sells, lower-cased; [] when the profile does not say. */
    public function products(): array
    {
        $list = $this->get('products');
        if (!is_array($list)) return [];
        $out = [];
        foreach ($list as $p) { $p = strtolower(trim((string)$p)); if ($p !== '') $out[] = $p; }
        return $out;
    }

    /** Does this tenant sell $product? A profile that lists nothing restricts nothing (the pre-profile behaviour). */
    public function sells(string $product): bool
    {
        $list = $this->products();
        return $list === [] || in_array(strtolower(trim($product)), $list, true);
    }

    /**
     * A WhatsApp number as a customer reads it: the dial code and three groups
     * ("+256 705 993 348", "+211 921 443 002"). Twelve digits is the shape both
     * tenants' numbers have; anything else is shown as "+" and the digits.
     */
    public static function formatWa(string $digits): string
    {
        $d = (string)preg_replace('/\D+/', '', $digits);
        if ($d === '') return '';
        if (strlen($d) === 12) return '+' . substr($d, 0, 3) . ' ' . substr($d, 3, 3) . ' ' . substr($d, 6, 3) . ' ' . substr($d, 9, 3);
        return '+' . $d;
    }

    /** Every value a diagnostic may print: no key here is secret. */
    public function describe(): array
    {
        return ['id' => $this->id, 'country' => $this->get('country'), 'timezone' => $this->timezone(),
                'currency' => $this->get('currency'), 'legal_entity' => $this->legalEntity(), 'locality' => $this->locality(),
                'website' => $this->website(), 'email' => $this->email(), 'contacts' => $this->get('contacts'),
                'pay_url' => $this->get('pay_url'), 'app_url' => $this->get('app_url'), 'products' => $this->get('products'),
                'jurisdiction' => $this->get('jurisdiction'), 'regulators' => $this->get('regulators'),
                'not_configured' => $this->nulls()];
    }

    /** The profile's unanswered questions (plan §D.6), as dotted paths. */
    public function nulls(): array
    {
        $out = [];
        $walk = function ($node, string $prefix) use (&$walk, &$out): void {
            foreach ((array)$node as $k => $v) {
                if ($k === '_readme') continue;
                $p = $prefix === '' ? (string)$k : $prefix . '.' . $k;
                if ($v === null) $out[] = $p;
                elseif (is_array($v) && $v !== [] && array_keys($v) !== range(0, count($v) - 1)) $walk($v, $p);
            }
        };
        $walk($this->data, '');
        return $out;
    }
}
