<?php
declare(strict_types=1);

require_once __DIR__ . '/PartnerContext.php';

/**
 * PartnerSession — how a signed-in distributor is recognised on the partner
 * portal (WS-A P4b, docs/50 §B/§D, docs/47 §10.3). Its own session, separate
 * from the staff side and from the customer portal (CustomerSession).
 *
 * The token is an OPAQUE 256-bit random value. The server stores ONLY its HMAC
 * (token_hash) in dist_partner_sessions — the raw token lives only in an
 * HttpOnly cookie (or a Bearer header for the native client / tests), never in
 * the database and never in a URL. Every request:
 *   - re-reads the session row (a missing / revoked / expired row is refused), and
 *   - re-reads the LIVE partner-user row, so a disabled account or a changed
 *     role/outlet binds on the very next request; the returned PartnerContext's
 *     scope is always the user's current scope, never a stale snapshot.
 * Logout, disable and a role/outlet change all act on the row(s), so the token
 * itself never decides (docs/50 §B).
 *
 * The HMAC key is DERIVED from the plugin's existing webhook_secret under a
 * distinct label — no new secret, and not key-reuse with the JWT path (the
 * Domain-B lesson). A deployment with no secret fails closed: issue and
 * authenticate both refuse.
 *
 * Cookie: own name, HttpOnly, Secure (on HTTPS), SameSite=Strict. A non-GET
 * cookie-authenticated request must carry the custom header AND not be
 * cross-site (docs/47 §10.3) — a cross-origin page cannot forge it.
 */
final class PartnerSession
{
    public const COOKIE         = 'dn_partner_session';
    public const REQUESTED_WITH = 'DishNet';
    public const TABLE          = 'dist_partner_sessions';
    public const USERS          = 'dist_partner_users';
    public const TTL            = 28800; // 8 hours
    private const KEY_LABEL      = 'dn-partner-session-v1';

    // ── Key derivation (no new secret; fail closed) ──────────────────────────

    /** The HMAC key for token hashing, derived from webhook_secret under a label. */
    public static function sessionKey(array $config): string
    {
        $secret = (string)($config['webhook_secret'] ?? '');
        if (strlen($secret) < 16) {
            throw new \RuntimeException('partner session: no server secret (webhook_secret) configured');
        }
        return hash_hmac('sha256', self::KEY_LABEL, $secret);
    }

    /** The stored form of a token. The raw token is never persisted. */
    public static function hashToken(string $token, array $config): string
    {
        return hash_hmac('sha256', $token, self::sessionKey($config));
    }

    // ── Cookie / transport discipline (mirrors CustomerSession; own cookie) ──

    public static function isHttps(?array $server = null): bool
    {
        $s = $server ?? $_SERVER;
        return (!empty($s['HTTPS']) && $s['HTTPS'] !== 'off')
            || (($s['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || (($s['HTTP_X_FORWARDED_SSL'] ?? '') === 'on')
            || ((int)($s['SERVER_PORT'] ?? 80) === 443);
    }

    /** The plugin's own path, so the cookie never reaches another plugin. */
    public static function cookiePath(?array $server = null): string
    {
        $s = $server ?? $_SERVER;
        $dir = str_replace('\\', '/', dirname((string)($s['SCRIPT_NAME'] ?? '/')));
        return rtrim($dir, '/') . '/';
    }

    public static function setCookie(string $token, ?int $maxAge = null): void
    {
        setcookie(self::COOKIE, $token, [
            'expires'  => time() + max(60, $maxAge ?? self::TTL),
            'path'     => self::cookiePath(),
            'secure'   => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    public static function clearCookie(): void
    {
        setcookie(self::COOKIE, '', [
            'expires' => 1, 'path' => self::cookiePath(),
            'secure' => self::isHttps(), 'httponly' => true, 'samesite' => 'Strict',
        ]);
    }

    /** Where the token came from: ['token'=>…, 'via'=>'bearer'|'cookie'|'']. Never the URL. */
    public static function fromRequest(?array $server = null, ?array $cookie = null): array
    {
        $s = $server ?? $_SERVER;
        $c = $cookie ?? $_COOKIE;
        $hdr = (string)($s['HTTP_AUTHORIZATION'] ?? '');
        if ($hdr === '' && isset($s['REDIRECT_HTTP_AUTHORIZATION'])) $hdr = (string)$s['REDIRECT_HTTP_AUTHORIZATION'];
        if (preg_match('/^Bearer\s+(.+)$/i', $hdr, $m)) return ['token' => trim($m[1]), 'via' => 'bearer'];
        $ck = trim((string)($c[self::COOKIE] ?? ''));
        if ($ck !== '') return ['token' => $ck, 'via' => 'cookie'];
        return ['token' => '', 'via' => ''];
    }

    /** True when the browser says this request was not made by our own page. */
    public static function crossSite(?array $server = null): bool
    {
        $s = $server ?? $_SERVER;
        if (strtolower(trim((string)($s['HTTP_SEC_FETCH_SITE'] ?? ''))) === 'cross-site') return true;
        $origin = trim((string)($s['HTTP_ORIGIN'] ?? ''));
        if ($origin !== '' && strtolower($origin) !== 'null') {
            $host = strtolower((string)parse_url($origin, PHP_URL_HOST));
            $port = parse_url($origin, PHP_URL_PORT);
            $originHost = $host . ($port ? ':' . $port : '');
            $reqHost = strtolower((string)($s['HTTP_HOST'] ?? ''));
            if ($originHost !== $reqHost && $host !== $reqHost) return true;
        }
        return false;
    }

    /** May a cookie authenticate this request? GET/HEAD always; anything else only from our own page. */
    public static function cookieUseAllowed(string $method, ?array $server = null): bool
    {
        $s = $server ?? $_SERVER;
        $m = strtoupper($method);
        if ($m === 'GET' || $m === 'HEAD') return true;
        if (trim((string)($s['HTTP_X_REQUESTED_WITH'] ?? '')) !== self::REQUESTED_WITH) return false;
        return !self::crossSite($s);
    }

    // ── Issue / authenticate / revoke ────────────────────────────────────────

    /**
     * Issue a session for an ACTIVE partner user. The scope (partner_id, role,
     * outlet_scope) is DERIVED from the live user row, never from the caller, so
     * a session can never claim a partner the account does not belong to.
     * Returns the raw opaque token (set it in the cookie once; it is never stored).
     * @throws \RuntimeException if the user is missing or not active.
     */
    public static function issue(\PDO $pdo, array $config, int $userId, string $ip = '', string $ua = ''): string
    {
        $u = $pdo->prepare("SELECT id, partner_id, role, status, outlet_scope FROM " . self::USERS . " WHERE id = ?");
        $u->execute([$userId]);
        $user = $u->fetch(\PDO::FETCH_ASSOC);
        if (!$user) throw new \RuntimeException('partner session: no such user');
        if ((string)$user['status'] !== 'active') throw new \RuntimeException('partner session: user is not active');

        $token = bin2hex(random_bytes(32)); // 256-bit opaque
        $now = time();
        $pdo->prepare(
            "INSERT INTO " . self::TABLE . " (token_hash, user_id, partner_id, role, outlet_scope, issued_at, expires_at, ip, ua)
             VALUES (?,?,?,?,?,?,?,?,?)"
        )->execute([
            self::hashToken($token, $config), (int)$user['id'], (int)$user['partner_id'],
            (string)$user['role'], (string)$user['outlet_scope'], $now, $now + self::TTL,
            substr($ip, 0, 64), substr($ua, 0, 200),
        ]);
        return $token;
    }

    /**
     * The signed-in distributor of this request, as a PartnerContext, plus the
     * session row id and the raw-token hash (for logout). Scope is read from the
     * LIVE user row, so a disabled account or a changed role binds immediately.
     *
     * @return array{ctx:PartnerContext, via:string, user_id:int, session_id:int}
     * @throws PartnerSessionException reason(): missing|cross_site|invalid|expired|revoked|disabled
     */
    public static function authenticate(\PDO $pdo, array $config, string $method, ?array $server = null, ?array $cookie = null): array
    {
        $src = self::fromRequest($server, $cookie);
        if ($src['token'] === '') throw new PartnerSessionException('missing');
        if ($src['via'] === 'cookie' && !self::cookieUseAllowed($method, $server)) {
            throw new PartnerSessionException('cross_site');
        }
        $hash = self::hashToken($src['token'], $config);
        $st = $pdo->prepare("SELECT id, user_id, partner_id, role, outlet_scope, expires_at, revoked_at FROM " . self::TABLE . " WHERE token_hash = ?");
        $st->execute([$hash]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$row) throw new PartnerSessionException('invalid');
        if ($row['revoked_at'] !== null) throw new PartnerSessionException('revoked');
        if ((int)$row['expires_at'] <= time()) throw new PartnerSessionException('expired');

        // Re-read the live user: disable / role / outlet changes bind here, not at issue.
        $u = $pdo->prepare("SELECT id, partner_id, role, status, outlet_scope FROM " . self::USERS . " WHERE id = ?");
        $u->execute([(int)$row['user_id']]);
        $user = $u->fetch(\PDO::FETCH_ASSOC);
        if (!$user || (string)$user['status'] !== 'active') {
            // A disabled/removed account must not keep a live session; close it.
            self::revokeByHash($pdo, $hash, 'auto:disabled');
            throw new PartnerSessionException('disabled');
        }

        $ctx = new PartnerContext(
            (int)$user['partner_id'],
            in_array((string)$user['role'], PartnerContext::ROLES, true) ? (string)$user['role'] : 'read_delegate',
            self::parseOutlets((string)$user['outlet_scope'])
        );
        return ['ctx' => $ctx, 'via' => (string)$src['via'], 'user_id' => (int)$user['id'], 'session_id' => (int)$row['id']];
    }

    /** @return array<int,int> */
    private static function parseOutlets(string $csv): array
    {
        $out = [];
        foreach (explode(',', $csv) as $p) { $n = (int)trim($p); if ($n > 0) $out[$n] = $n; }
        return array_values($out);
    }

    public static function isLive(\PDO $pdo, string $tokenHash, ?int $now = null): bool
    {
        $st = $pdo->prepare("SELECT 1 FROM " . self::TABLE . " WHERE token_hash = ? AND revoked_at IS NULL AND expires_at > ? LIMIT 1");
        $st->execute([$tokenHash, $now ?? time()]);
        return (bool)$st->fetchColumn();
    }

    public static function revokeByHash(\PDO $pdo, string $tokenHash, string $by): int
    {
        $st = $pdo->prepare("UPDATE " . self::TABLE . " SET revoked_at = ?, revoked_by = ? WHERE token_hash = ? AND revoked_at IS NULL");
        $st->execute([time(), substr($by, 0, 64), $tokenHash]);
        return $st->rowCount();
    }

    /** Logout: revoke the session the raw token names. */
    public static function revokeByToken(\PDO $pdo, array $config, string $token, string $by = 'logout'): int
    {
        if ($token === '') return 0;
        return self::revokeByHash($pdo, self::hashToken($token, $config), $by);
    }

    /** Revoke every live session of one partner user (disable / role change / "sign out everywhere"). */
    public static function revokeAllForUser(\PDO $pdo, int $userId, string $by): int
    {
        $st = $pdo->prepare("UPDATE " . self::TABLE . " SET revoked_at = ?, revoked_by = ? WHERE user_id = ? AND revoked_at IS NULL AND expires_at > ?");
        $st->execute([time(), substr($by, 0, 64), $userId, time()]);
        return $st->rowCount();
    }

    /** Rows whose tokens expired more than a day ago carry nothing a check still needs. */
    public static function purge(\PDO $pdo, ?int $now = null): void
    {
        try { $pdo->prepare("DELETE FROM " . self::TABLE . " WHERE expires_at < ?")->execute([($now ?? time()) - 86400]); } catch (\Throwable $e) {}
    }
}

final class PartnerSessionException extends \RuntimeException
{
    private string $reason;
    public function __construct(string $reason) { parent::__construct('partner session: ' . $reason); $this->reason = $reason; }
    public function reason(): string { return $this->reason; }
}
