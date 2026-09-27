<?php
declare(strict_types=1);
/**
 * CustomerJwtKeys — the customer-portal signing keys (Phase 2, plan §E.1–E.2).
 *
 * Three configuration values, all of them vault keys so a re-install restores
 * them instead of silently minting a new key and signing every customer out:
 *
 *   customer_jwt_keys        JSON object  {"k1": "<64 hex>", "k2": …}   SECRET
 *   customer_jwt_active_kid  the key id new tokens are signed with
 *   customer_jwt_key_dates   JSON object  {"k1": <unix time it became active>, …}
 *
 * Rotation adds a key and makes it active; the previous keys keep verifying
 * until every token they signed has expired (app_jwt_ttl_days), and prune()
 * removes them then. Nobody is signed out by a rotation.
 *
 * ensure() follows PdfLinkToken::ensureSecret(): the store copy of
 * kyc_config.json is the working copy, the vault is consulted before anything
 * is generated and written after, and '' / false is returned rather than a key
 * nobody stored. Nothing here ever prints a secret.
 */
final class CustomerJwtKeys
{
    public const KEYS_KEY    = 'customer_jwt_keys';
    public const ACTIVE_KEY  = 'customer_jwt_active_kid';
    public const DATES_KEY   = 'customer_jwt_key_dates';
    public const TTL_DAYS_KEY = 'app_jwt_ttl_days';
    public const DEFAULT_TTL_DAYS = 30;
    public const SECRET_HEX  = 64;       // 32 random bytes
    public const FIRST_KID   = 'k1';

    /** kid => 64-hex secret. Accepts the stored JSON string or an already decoded array. */
    public static function keys(array $config): array
    {
        $raw = $config[self::KEYS_KEY] ?? null;
        if (is_string($raw)) $raw = json_decode(trim($raw), true);
        if (!is_array($raw)) return [];
        $out = [];
        foreach ($raw as $kid => $secret) {
            if (self::validKid((string)$kid) && is_string($secret) && preg_match('/^[0-9a-f]{64}$/', $secret)) {
                $out[(string)$kid] = $secret;
            }
        }
        return $out;
    }

    public static function activeKid(array $config): string
    {
        $k = trim((string)($config[self::ACTIVE_KEY] ?? ''));
        return self::validKid($k) ? $k : '';
    }

    /** kid => unix time the key became active. */
    public static function dates(array $config): array
    {
        $raw = $config[self::DATES_KEY] ?? null;
        if (is_string($raw)) $raw = json_decode(trim($raw), true);
        if (!is_array($raw)) return [];
        $out = [];
        foreach ($raw as $kid => $t) if (self::validKid((string)$kid)) $out[(string)$kid] = (int)$t;
        return $out;
    }

    public static function ttlSeconds(array $config): int
    {
        $days = (int)($config[self::TTL_DAYS_KEY] ?? self::DEFAULT_TTL_DAYS);
        if ($days < 1) $days = self::DEFAULT_TTL_DAYS;
        return $days * 86400;
    }

    /** True when an active key exists in this configuration. */
    public static function provisioned(array $config): bool
    {
        $kid = self::activeKid($config);
        return $kid !== '' && isset(self::keys($config)[$kid]);
    }

    /**
     * Make sure an active key exists: config → store → vault → generate k1.
     * Updates $config in place. Returns false when nothing could be stored.
     */
    public static function ensure($store, array &$config): bool
    {
        if (self::provisioned($config)) return true;
        if (!is_object($store) || !method_exists($store, 'load') || !method_exists($store, 'save')) return false;
        try {
            $cur = $store->load('kyc_config.json');
            if (!is_array($cur)) $cur = [];
            $root    = dirname(__DIR__);
            $dataDir = method_exists($store, 'getDataDir') ? (string)$store->getDataDir() : '';
            if (!self::provisioned($cur) && $dataDir !== '') {
                // A re-install loses the store but not the vault: restore before
                // generating, or every signed-in customer is signed out.
                try {
                    require_once __DIR__ . '/ConfigVault.php';
                    $back = ConfigVault::fill($root, $dataDir, [], [self::KEYS_KEY, self::ACTIVE_KEY, self::DATES_KEY]);
                    foreach ([self::KEYS_KEY, self::ACTIVE_KEY, self::DATES_KEY] as $k) {
                        if (isset($back[$k]) && $back[$k] !== '') $cur[$k] = $back[$k];
                    }
                } catch (\Throwable $e) { /* the store copy decides */ }
            }
            if (self::provisioned($cur)) {
                foreach ([self::KEYS_KEY, self::ACTIVE_KEY, self::DATES_KEY] as $k) if (isset($cur[$k])) $config[$k] = $cur[$k];
                if (($store->load('kyc_config.json')[self::KEYS_KEY] ?? null) !== ($cur[self::KEYS_KEY] ?? null)) {
                    self::persist($store, $config, self::keys($cur), self::activeKid($cur), self::dates($cur), false);
                }
                return true;
            }
            $keys = [self::FIRST_KID => bin2hex(random_bytes(self::SECRET_HEX / 2))];
            return self::persist($store, $config, $keys, self::FIRST_KID, [self::FIRST_KID => time()], true);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Add a key, make it active, keep the old ones verifying. Returns the new kid or ''. */
    public static function rotate($store, array &$config): string
    {
        if (!self::ensure($store, $config)) return '';
        $keys = self::keys($config); $dates = self::dates($config);
        $n = 0;
        foreach (array_keys($keys) as $kid) $n = max($n, (int)substr($kid, 1));
        $new = 'k' . ($n + 1);
        $keys[$new] = bin2hex(random_bytes(self::SECRET_HEX / 2));
        $dates[$new] = time();
        return self::persist($store, $config, $keys, $new, $dates, true) ? $new : '';
    }

    /**
     * Remove every retired key whose tokens have all expired: a key retired at
     * T (the moment its successor became active) is dropped once T + TTL < now.
     * Returns the kids removed.
     */
    public static function prune($store, array &$config, ?int $now = null): array
    {
        $now = $now ?? time();
        $keys = self::keys($config); $dates = self::dates($config); $active = self::activeKid($config);
        if ($active === '' || !isset($keys[$active])) return [];
        $ttl = self::ttlSeconds($config);
        // Activation order decides who retired whom.
        $order = array_keys($keys);
        usort($order, function ($a, $b) use ($dates) { return ($dates[$a] ?? 0) <=> ($dates[$b] ?? 0); });
        $removed = [];
        foreach ($order as $i => $kid) {
            if ($kid === $active) continue;
            $successor = $order[$i + 1] ?? null;
            $retiredAt = $successor !== null ? ($dates[$successor] ?? 0) : 0;
            if ($retiredAt > 0 && $retiredAt + $ttl < $now) { unset($keys[$kid], $dates[$kid]); $removed[] = $kid; }
        }
        if ($removed !== []) self::persist($store, $config, $keys, $active, $dates, true);
        return $removed;
    }

    /** What a tool may print: key ids, the active one, activation dates. Never a secret. */
    public static function describe(array $config): array
    {
        $dates = self::dates($config); $out = [];
        foreach (array_keys(self::keys($config)) as $kid) {
            $out[] = ['kid' => $kid, 'active' => $kid === self::activeKid($config),
                      'since' => isset($dates[$kid]) ? gmdate('Y-m-d H:i:s', $dates[$kid]) . ' UTC' : 'unknown'];
        }
        return $out;
    }

    private static function validKid(string $kid): bool
    {
        return (bool)preg_match('/^k[1-9][0-9]{0,5}$/', $kid);
    }

    /** Write all three values to the store copy (and the vault when asked), then into $config. */
    private static function persist($store, array &$config, array $keys, string $active, array $dates, bool $vault): bool
    {
        $cur = $store->load('kyc_config.json');
        if (!is_array($cur)) $cur = [];
        $vals = [
            self::KEYS_KEY   => json_encode($keys),
            self::ACTIVE_KEY => $active,
            self::DATES_KEY  => json_encode($dates),
        ];
        foreach ($vals as $k => $v) $cur[$k] = $v;
        $store->save('kyc_config.json', $cur);
        $again = $store->load('kyc_config.json');
        if (!is_array($again) || self::activeKid($again) !== $active || !isset(self::keys($again)[$active])) return false;
        foreach ($vals as $k => $v) $config[$k] = $v;
        if ($vault) {
            $dataDir = method_exists($store, 'getDataDir') ? (string)$store->getDataDir() : '';
            if ($dataDir !== '') {
                try { require_once __DIR__ . '/ConfigVault.php'; ConfigVault::store(dirname(__DIR__), $dataDir, $vals); } catch (\Throwable $e) {}
            }
        }
        return true;
    }
}
