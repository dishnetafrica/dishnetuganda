<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantProfile.php';
/**
 * CustomerSession — how a signed-in customer is recognised (Phase 2 of the
 * customer-login audit, plan §E.4–E.5).
 *
 * The token lives in an HttpOnly cookie the server sets, or in an
 * Authorization: Bearer header (the native WebView, tests, the deploy script).
 * It is never accepted from a URL. Every token has a row in customer_sessions;
 * a token whose row is missing, revoked or expired is refused, so logout and
 * "sign out everywhere" act on the row, not on the token.
 *
 * A cookie can be sent by a browser the customer did not intend: for anything
 * but GET/HEAD a cookie-authenticated request must carry X-Requested-With:
 * DishNet (a header no cross-origin page can add without a CORS preflight that
 * the API refuses) and must not announce itself as cross-site. GET is allowed
 * from anywhere because a link to the portal is a cross-site navigation.
 */
final class CustomerSession
{
    public const COOKIE         = 'dn_customer_session';
    /** The cookie the login page used to set from JavaScript. Cleared, never read. */
    public const LEGACY_COOKIE  = 'dn_customer_token';
    public const REQUESTED_WITH = 'DishNet';
    public const TABLE          = 'customer_sessions';

    public static function ensureTable(\PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS " . self::TABLE . " (
            jti TEXT PRIMARY KEY, client_id INTEGER NOT NULL, identifier TEXT NOT NULL DEFAULT '',
            login_mode TEXT NOT NULL DEFAULT '', kid TEXT NOT NULL DEFAULT '', issued_at INTEGER NOT NULL,
            expires_at INTEGER NOT NULL, revoked_at INTEGER, revoked_by TEXT,
            ip TEXT NOT NULL DEFAULT '', ua TEXT NOT NULL DEFAULT '')");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_customer_sessions_client ON " . self::TABLE . "(client_id, revoked_at)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_customer_sessions_expires ON " . self::TABLE . "(expires_at)");
    }

    /** The same rule public.php applies: HTTPS on the request or announced by the proxy. */
    public static function isHttps(?array $server = null): bool
    {
        $s = $server ?? $_SERVER;
        return (!empty($s['HTTPS']) && $s['HTTPS'] !== 'off')
            || (($s['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || (($s['HTTP_X_FORWARDED_SSL'] ?? '') === 'on')
            || ((int)($s['SERVER_PORT'] ?? 80) === 443);
    }

    /** The plugin's own path (…/crm/_plugins/<dir>/), so the cookie never reaches another plugin. */
    public static function cookiePath(?array $server = null): string
    {
        $s = $server ?? $_SERVER;
        $script = (string)($s['SCRIPT_NAME'] ?? '/');
        $dir = str_replace('\\', '/', dirname($script));
        return rtrim($dir, '/') . '/';
    }

    public static function setCookie(string $token, int $maxAge): void
    {
        setcookie(self::COOKIE, $token, [
            'expires' => time() + max(60, $maxAge), 'path' => self::cookiePath(),
            'secure' => self::isHttps(), 'httponly' => true, 'samesite' => 'Lax',
        ]);
        self::clearLegacyCookie();
    }

    public static function clearCookie(): void
    {
        setcookie(self::COOKIE, '', ['expires' => 1, 'path' => self::cookiePath(), 'secure' => self::isHttps(), 'httponly' => true, 'samesite' => 'Lax']);
        self::clearLegacyCookie();
    }

    private static function clearLegacyCookie(): void
    {
        if (isset($_COOKIE[self::LEGACY_COOKIE])) {
            setcookie(self::LEGACY_COOKIE, '', ['expires' => 1, 'path' => '/', 'samesite' => 'Lax']);
        }
    }

    /** Where the token came from: ['token' => …, 'via' => 'bearer'|'cookie'|'']. Never the URL. */
    public static function fromRequest(): array
    {
        $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if ($hdr === '' && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) $hdr = (string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        if (preg_match('/^Bearer\s+(.+)$/i', (string)$hdr, $m)) return ['token' => trim($m[1]), 'via' => 'bearer'];
        $c = trim((string)($_COOKIE[self::COOKIE] ?? ''));
        if ($c !== '') return ['token' => $c, 'via' => 'cookie'];
        return ['token' => '', 'via' => ''];
    }

    /** A native client announces itself; only then may a token travel in a JSON body. */
    public static function nativeClient(): bool
    {
        return trim((string)($_SERVER['HTTP_X_DISHNET_CLIENT'] ?? '')) !== '';
    }

    /** True when the browser says this request was not made by our own page. */
    public static function crossSite(): bool
    {
        $sfs = strtolower(trim((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
        if ($sfs === 'cross-site') return true;
        $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
        if ($origin !== '' && strtolower($origin) !== 'null') {
            $host = strtolower((string)parse_url($origin, PHP_URL_HOST));
            $port = parse_url($origin, PHP_URL_PORT);
            $originHost = $host . ($port ? ':' . $port : '');
            $reqHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
            if ($originHost !== $reqHost && $host !== $reqHost) return true;
        }
        return false;
    }

    /** May a cookie authenticate this request? GET/HEAD always; anything else only from our own page. */
    public static function cookieUseAllowed(string $method): bool
    {
        $m = strtoupper($method);
        if ($m === 'GET' || $m === 'HEAD') return true;
        if (trim((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) !== self::REQUESTED_WITH) return false;
        return !self::crossSite();
    }

    public static function record(\PDO $pdo, array $claims, string $kid, string $ip, string $ua): void
    {
        self::ensureTable($pdo);
        $pdo->prepare("INSERT OR REPLACE INTO " . self::TABLE . " (jti, client_id, identifier, login_mode, kid, issued_at, expires_at, revoked_at, revoked_by, ip, ua)
                       VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?, ?)")
            ->execute([(string)$claims['jti'], (int)$claims['sub'], (string)($claims['phone'] ?? ''), (string)($claims['login_mode'] ?? ''),
                       $kid, (int)($claims['iat'] ?? time()), (int)($claims['exp'] ?? 0), substr($ip, 0, 64), substr($ua, 0, 200)]);
    }

    public static function isLive(\PDO $pdo, string $jti, ?int $now = null): bool
    {
        if ($jti === '') return false;
        $st = $pdo->prepare("SELECT 1 FROM " . self::TABLE . " WHERE jti = ? AND revoked_at IS NULL AND expires_at > ? LIMIT 1");
        $st->execute([$jti, $now ?? time()]);
        return (bool)$st->fetchColumn();
    }

    public static function revoke(\PDO $pdo, string $jti, string $by): int
    {
        $st = $pdo->prepare("UPDATE " . self::TABLE . " SET revoked_at = ?, revoked_by = ? WHERE jti = ? AND revoked_at IS NULL");
        $st->execute([time(), substr($by, 0, 64), $jti]);
        return $st->rowCount();
    }

    /** "Sign out everywhere": every live session of one customer. Returns how many. */
    public static function revokeAll(\PDO $pdo, int $clientId, string $by): int
    {
        $st = $pdo->prepare("UPDATE " . self::TABLE . " SET revoked_at = ?, revoked_by = ? WHERE client_id = ? AND revoked_at IS NULL AND expires_at > ?");
        $st->execute([time(), substr($by, 0, 64), $clientId, time()]);
        return $st->rowCount();
    }

    /** Rows whose tokens expired more than a day ago carry no information a check still needs. */
    public static function purge(\PDO $pdo, ?int $now = null): void
    {
        try { $pdo->prepare("DELETE FROM " . self::TABLE . " WHERE expires_at < ?")->execute([($now ?? time()) - 86400]); } catch (\Throwable $e) {}
    }

    /**
     * The signed-in customer of this request, or a CustomerSessionException
     * whose reason() is one of: missing, cross_site, invalid, kind, revoked.
     * @return array{claims: array, via: string}
     */
    public static function authenticate(array $config, \PDO $pdo): array
    {
        $src = self::fromRequest();
        if ($src['token'] === '') throw new CustomerSessionException('missing');
        if ($src['via'] === 'cookie' && !self::cookieUseAllowed((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'))) {
            throw new CustomerSessionException('cross_site');
        }
        try {
            require_once __DIR__ . '/JwtAuth.php';
            $claims = JwtAuth::forCustomers($config)->verify($src['token']);
        } catch (\Throwable $e) {
            throw new CustomerSessionException('invalid');
        }
        if (($claims['kind'] ?? '') !== 'app') throw new CustomerSessionException('kind');
        self::ensureTable($pdo);
        if (!self::isLive($pdo, (string)($claims['jti'] ?? ''))) throw new CustomerSessionException('revoked');
        return ['claims' => $claims, 'via' => $src['via']];
    }

    /**
     * Has this identifier (the canonical number or the lower-cased e-mail the
     * token names) accepted the current terms and privacy versions? One
     * implementation for the API, the login page and the portal.
     */
    /**
     * Has this customer accepted the CURRENT terms and privacy versions?
     *
     * 5.18.41 (docs/38 A1.2): a consent row counts when it names this sign-in
     * identifier (the phone or the e-mail the OTP went to — the `phone` column
     * holds either) OR when it names this customer (`crm_client_id`). Rows are
     * written only by app_record_consent, which needs a session the OTP just
     * proved for that very customer, so sharing by customer id shares consent
     * only between identities that have each proved they are that customer. The
     * e-mail route of a customer who accepted by phone is therefore not asked
     * again; a row for another customer never admits this one; and with
     * $clientId = 0 the rule is exactly the pre-5.18.41 one (by identifier).
     *
     * 5.18.42 (docs/38 A2): "current" means the TENANT's versions — the profile
     * the request is served under is a required parameter, so a caller cannot
     * compare a Uganda customer's row against another tenant's version by
     * leaving it out (that would ask them again on every sign-in, forever).
     */
    public static function hasCurrentConsent(\PDO $pdo, string $identifier, int $clientId, TenantProfile $tp): bool
    {
        $identifier = trim($identifier);
        if ($identifier === '' && $clientId <= 0) return false;
        require_once dirname(__DIR__) . '/lib/LegalContent.php';
        $ver = dnLegalVersion($tp);
        try {
            // Two statements rather than one with a "? > 0" clause: PDO binds an int as TEXT unless told,
            // and SQLite orders TEXT above INTEGER, so '0' > 0 is true — the guard would have matched a
            // row holding 0. The client id is bound as an integer and only when there is one.
            if ($clientId > 0) {
                $st = $pdo->prepare("SELECT 1 FROM app_tos_consent WHERE tos_version = ? AND privacy_version = ? AND (phone = ? OR crm_client_id = ?) LIMIT 1");
                $st->bindValue(1, $ver['tos']); $st->bindValue(2, $ver['privacy']); $st->bindValue(3, $identifier); $st->bindValue(4, $clientId, \PDO::PARAM_INT);
                $st->execute();
            } else {
                $st = $pdo->prepare("SELECT 1 FROM app_tos_consent WHERE tos_version = ? AND privacy_version = ? AND phone = ? LIMIT 1");
                $st->execute([$ver['tos'], $ver['privacy'], $identifier]);
            }
            return (bool)$st->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** The login page's question: is there a live session in the cookie? Never throws. */
    public static function liveClaimsFromCookie(array $config, \PDO $pdo): ?array
    {
        $c = trim((string)($_COOKIE[self::COOKIE] ?? ''));
        if ($c === '') return null;
        try {
            require_once __DIR__ . '/JwtAuth.php';
            $claims = JwtAuth::forCustomers($config)->verify($c);
            if (($claims['kind'] ?? '') !== 'app') return null;
            self::ensureTable($pdo);
            return self::isLive($pdo, (string)($claims['jti'] ?? '')) ? $claims : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}

final class CustomerSessionException extends \RuntimeException
{
    private string $reason;
    public function __construct(string $reason) { parent::__construct('customer session: ' . $reason); $this->reason = $reason; }
    public function reason(): string { return $this->reason; }
}
