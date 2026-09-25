<?php
declare(strict_types=1);

require_once __DIR__ . '/ConfigVault.php';

/**
 * PdfLinkToken — the token in a public receipt or delivery-note PDF link.
 *
 * Until 5.18.37 those links were signed with
 *
 *     hash_hmac('sha256', $file . date('Ymd'), $config['webhook_secret'] ?? 'dishnet')
 *
 * and the receipt file names are predictable (DishNet-Receipt-<id>.pdf). On an
 * install where webhook_secret was never set — the Uganda install, as recorded
 * on 15 September 2026 — every such link could be recomputed by anyone who
 * knew a receipt number. The temp invoice/quote PDFs were minted the same way,
 * although serve_temp_pdf only ever compared the stored .meta token.
 *
 * This class gives PDF links a key of their own, generated once (the same way
 * quote_pdf_secret was in 5.18.1) and never derived from anything else:
 *
 *   - mint()/verify(): a daily token, today or yesterday (UTC), like
 *     QuotePdfToken — a link lives between 24 and 48 hours;
 *   - random(): an unguessable token for files whose .meta is the only check;
 *   - legacyVerify(): the old scheme, accepted ONLY when webhook_secret is a
 *     real value, so links sent in the 48 hours before the upgrade still open
 *     on installs that had a secret — and never on the 'dishnet' default.
 *
 * The secret is `pdf_link_secret`, in the store copy of kyc_config.json and in
 * the config vault. It is never printed anywhere.
 *
 * PHP 7.4 compatible.
 */
final class PdfLinkToken
{
    public const SECRET_KEY    = 'pdf_link_secret';
    public const SECRET_LENGTH = 64;   // hex characters = 32 random bytes
    /** The literal the old scheme fell back to. Never accepted as a key. */
    public const LEGACY_DEFAULT_SECRET = 'dishnet';

    /** The configured secret, or '' when none has been generated yet. */
    public static function secret(array $config): string
    {
        return trim((string)($config[self::SECRET_KEY] ?? ''));
    }

    /**
     * Generate the secret once into the store copy of kyc_config.json.
     * Mirrors QuotePdfToken::ensureSecret(): returns '' when the store is not
     * one it can write, so a caller never mints with a key nobody stored.
     */
    public static function ensureSecret($store, array &$config): string
    {
        $have = self::secret($config);
        if ($have !== '') return $have;
        if (!is_object($store) || !method_exists($store, 'load') || !method_exists($store, 'save')) return '';
        try {
            $cur = $store->load('kyc_config.json');
            if (!is_array($cur)) $cur = [];
            $s = trim((string)($cur[self::SECRET_KEY] ?? ''));
            $root    = dirname(__DIR__);
            $dataDir = method_exists($store, 'getDataDir') ? (string)$store->getDataDir() : '';
            if ($s === '' && $dataDir !== '') {
                // A re-install loses the store but not the vault: restore
                // before generating, or every receipt link a customer holds
                // would die with the new key.
                try {
                    $back = ConfigVault::fill($root, $dataDir, [], [self::SECRET_KEY]);
                    $s = trim((string)($back[self::SECRET_KEY] ?? ''));
                } catch (\Throwable $e) { $s = ''; }
            }
            $generated = false;
            if ($s === '') {
                $s = bin2hex(random_bytes(self::SECRET_LENGTH / 2));
                $generated = true;
            }
            if (($cur[self::SECRET_KEY] ?? '') !== $s) {
                $cur[self::SECRET_KEY] = $s;
                $store->save('kyc_config.json', $cur);
                $again = $store->load('kyc_config.json');
                $s = trim((string)((is_array($again) ? $again : $cur)[self::SECRET_KEY] ?? ''));
            }
            if ($s === '') return '';
            if ($generated && $dataDir !== '') {
                // Vaulted so it survives a re-install; refused silently if the
                // vault is unwritable — the store copy still serves.
                try { ConfigVault::store($root, $dataDir, [self::SECRET_KEY => $s]); } catch (\Throwable $e) {}
            }
            $config[self::SECRET_KEY] = $s;
            return $s;
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** The UTC day a token is minted for. $now exists for tests. */
    public static function day(?int $now = null): string
    {
        return gmdate('Ymd', $now ?? time());
    }

    /** Today's token for a file, or '' when no secret exists (never a token under a guessable key). */
    public static function mint(string $file, array $config, ?int $now = null): string
    {
        $secret = self::secret($config);
        if ($secret === '') return '';
        return self::forDay(basename($file), $secret, self::day($now));
    }

    /**
     * True when $token is today's or yesterday's token for $file. Every
     * candidate is compared, so the time taken does not say which day matched.
     */
    public static function verify(string $file, string $token, array $config, ?int $now = null): bool
    {
        $secret = self::secret($config);
        if ($secret === '' || $token === '') return false;
        $file = basename($file);
        $t    = $now ?? time();
        $ok   = false;
        foreach ([0, 1] as $back) {
            $cand = self::forDay($file, $secret, self::day($t - $back * 86400));
            if (hash_equals($cand, $token)) $ok = true;
        }
        return $ok;
    }

    /**
     * The pre-5.18.37 scheme, for links already in customers' hands when the
     * upgrade lands. Accepted only while webhook_secret is a real value: the
     * 'dishnet' default was the weakness, so a token under it proves nothing.
     */
    public static function legacyVerify(string $file, string $token, array $config, ?int $now = null): bool
    {
        $secret = trim((string)($config['webhook_secret'] ?? ''));
        if ($secret === '' || $secret === self::LEGACY_DEFAULT_SECRET || $token === '') return false;
        $file = basename($file);
        $t    = $now ?? time();
        $ok   = false;
        foreach ([0, 1] as $back) {
            // The old code used the LOCAL day, so the comparison must too.
            $cand = hash_hmac('sha256', $file . date('Ymd', $t - $back * 86400), $secret);
            if (hash_equals($cand, $token)) $ok = true;
        }
        return $ok;
    }

    /** An unguessable one-off token for files whose .meta record is the only check. */
    public static function random(): string
    {
        return bin2hex(random_bytes(16));
    }

    private static function forDay(string $file, string $secret, string $day): string
    {
        return hash_hmac('sha256', $file . '|' . $day, $secret);
    }
}
