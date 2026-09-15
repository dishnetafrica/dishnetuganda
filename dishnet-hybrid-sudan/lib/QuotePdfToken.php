<?php
declare(strict_types=1);
/**
 * QuotePdfToken — the one place a quotation PDF link is signed and checked.
 *
 * A quotation PDF reaches WhatsApp through a public, unauthenticated endpoint
 * (serve_quote_pdf): the plugin sends Evolution a URL, and Evolution fetches
 * it server-side within seconds. The customer never sees the URL. The only
 * thing between the file and anyone else who finds that URL — in a log, a
 * proxy, a screenshot — is the token in it.
 *
 * Until 5.18.0 the endpoint accepted two tokens: a daily-rotating HMAC, and a
 * permanent one stored beside the PDF in a .meta file. The permanent one meant
 * every quotation URL that had ever been written to webhook_log.json stayed
 * fetchable indefinitely. It is gone. What remains:
 *
 *     token = HMAC-SHA256( file_name . day , webhook_secret )
 *     day   = gmdate('Ymd')
 *
 * The validator accepts today's and yesterday's day, so a link lives between
 * 24 and 48 hours — long enough for Evolution's fetch and for an admin retry
 * the same or the next day — and then dies. The day is taken in UTC on both
 * sides on purpose: a cron process left on the container's default clock and
 * a web process on the install's timezone must still agree on it. It is the
 * same construction the generators and the endpoint have always used, so a
 * link minted before the upgrade still verifies after it.
 *
 * Every generator calls mint(); the endpoint calls verify(); a retried
 * document send goes through refreshUrl() so a stale link is re-signed rather
 * than re-sent to be refused. No caller computes the HMAC itself —
 * tests/test_quote_pdf_token.php pins that against the source.
 *
 * The secret is `quote_pdf_secret`, a key of its own in the store copy of
 * kyc_config.json, generated once by ensureSecret() the first time an entry
 * point (public.php, webhook.php, the quote cron) finds none. It is shared
 * with nothing: `webhook_secret`, the obvious candidate, also derives the
 * customer app's JWT key, acts as the debug_key bearer for diagnostic
 * actions and authenticates the n8n customer-context API, so setting it to
 * protect PDF links would log every customer out and open two other doors.
 *
 * Until the key exists, secret() falls back to what every caller used
 * before 5.18.1 — `webhook_secret`, else a published default — so nothing
 * changes until the very first boot after the upgrade writes the key, and a
 * link minted seconds before that boot is re-signed by the retry path
 * rather than lost. hasRealSecret() exists so a doctor can say which state
 * an install is in.
 */
final class QuotePdfToken
{
    /** The endpoint these tokens are for. Other serve_* endpoints have their own rules. */
    public const ACTION = 'serve_quote_pdf';

    /** Days accepted besides today. 1 = today and yesterday. */
    public const GRACE_DAYS = 1;

    /** What every caller used when webhook_secret was unset. Kept for continuity, not chosen. */
    public const LEGACY_DEFAULT_SECRET = 'dishnet';

    /** The store key holding this install's own quotation-link secret. */
    public const SECRET_KEY = 'quote_pdf_secret';

    /** Hex characters in a generated secret (16 random bytes). */
    public const SECRET_LENGTH = 32;

    /**
     * The signing secret: the install's own key when it exists, otherwise
     * exactly what every caller resolved before 5.18.1.
     */
    public static function secret(array $config): string
    {
        $own = trim((string)($config[self::SECRET_KEY] ?? ''));
        if ($own !== '') return $own;
        return (string)($config['webhook_secret'] ?? self::LEGACY_DEFAULT_SECRET);
    }

    /** True once the install signs with a key of its own. */
    public static function hasOwnSecret(array $config): bool
    {
        return trim((string)($config[self::SECRET_KEY] ?? '')) !== '';
    }

    /** False when the install signs with the published default or nothing. */
    public static function hasRealSecret(array $config): bool
    {
        if (self::hasOwnSecret($config)) return true;
        $s = trim((string)($config['webhook_secret'] ?? ''));
        return $s !== '' && $s !== self::LEGACY_DEFAULT_SECRET;
    }

    /**
     * Make sure this install has a quotation-link secret, generating and
     * storing one the first time. Called at the top of every entry point
     * that mints or checks a link, with the store copy of the config.
     *
     * Reads the store fresh rather than trusting $config: a caller holding a
     * merged or stale view must not write a second secret over the one
     * another process just stored. After writing, the store is read back and
     * whatever it holds is adopted, so two first boots racing each other end
     * up agreeing. Persistence failure leaves $config untouched — the
     * fallback then applies here exactly as it does in every other process,
     * and a boot never dies for the sake of a PDF link.
     *
     * @param object $store anything with load(string) and save(string, array)
     * @return string the secret in force, or '' when none could be stored
     */
    public static function ensureSecret($store, array &$config): string
    {
        $have = trim((string)($config[self::SECRET_KEY] ?? ''));
        if ($have !== '') return $have;
        if (!is_object($store) || !method_exists($store, 'load') || !method_exists($store, 'save')) return '';
        try {
            $cur = $store->load('kyc_config.json');
            if (!is_array($cur)) $cur = [];
            $s = trim((string)($cur[self::SECRET_KEY] ?? ''));
            if ($s === '') {
                $cur[self::SECRET_KEY] = bin2hex(random_bytes(self::SECRET_LENGTH / 2));
                $store->save('kyc_config.json', $cur);
                $again = $store->load('kyc_config.json');
                $s = trim((string)((is_array($again) ? $again : $cur)[self::SECRET_KEY] ?? ''));
            }
            if ($s === '') return '';
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

    /** Today's token for a quotation PDF. A path is reduced to its file name first. */
    public static function mint(string $file, array $config, ?int $now = null): string
    {
        return self::forDay(basename($file), self::secret($config), self::day($now));
    }

    /**
     * True when $token is today's or yesterday's token for $file. Every
     * candidate is compared, so the time taken does not say which day matched.
     */
    public static function verify(string $file, string $token, array $config, ?int $now = null): bool
    {
        $file = basename($file);
        if ($file === '' || $token === '') return false;
        $secret = self::secret($config);
        $now    = $now ?? time();
        $ok     = false;
        for ($d = 0; $d <= self::GRACE_DAYS; $d++) {
            $want = self::forDay($file, $secret, self::day($now - $d * 86400));
            if (hash_equals($want, $token)) $ok = true;
        }
        return $ok;
    }

    /**
     * Re-sign a quotation PDF URL with today's token. Every other URL — a
     * receipt, a delivery note, a temporary invoice PDF, anything without a
     * file — comes back byte-for-byte as it was.
     */
    public static function refreshUrl(string $url, array $config, ?int $now = null): string
    {
        $qPos = strpos($url, '?');
        if ($qPos === false) return $url;
        $base  = substr($url, 0, $qPos);
        $rest  = substr($url, $qPos + 1);
        $frag  = '';
        $hPos  = strpos($rest, '#');
        if ($hPos !== false) { $frag = substr($rest, $hPos); $rest = substr($rest, 0, $hPos); }

        $q = [];
        parse_str($rest, $q);
        if (($q['action'] ?? '') !== self::ACTION) return $url;
        $file = basename(trim((string)($q['file'] ?? '')));
        if ($file === '') return $url;

        $q['file']  = $file;
        $q['token'] = self::mint($file, $config, $now);
        return $base . '?' . http_build_query($q) . $frag;
    }

    private static function forDay(string $file, string $secret, string $day): string
    {
        return hash_hmac('sha256', $file . $day, $secret);
    }
}
