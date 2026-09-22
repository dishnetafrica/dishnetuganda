<?php
declare(strict_types=1);
namespace Dn\Admin;

use Dn\Http\Request;

/**
 * A short-lived, signed staff session — the thing a login screen produces.
 *
 * It holds NO credential and NO password, and there is no credential store
 * anywhere behind it. That is deliberate: AdminIdentityPort exists so the
 * eventual provider (SSO, uCRM, a directory) can be chosen later, and a
 * password table written now would be the one thing that made that choice
 * harder rather than easier.
 *
 * STATELESS BY DESIGN. The token carries subject, role and expiry, and is
 * signed; nothing is written to the database. A logout therefore clears the
 * cookie rather than revoking a row, and the bound on damage is the expiry.
 * That is acceptable for the DEVELOPMENT identity this currently serves and is
 * NOT sufficient for a production provider, which will need real revocation —
 * recorded here so the limitation is visible rather than discovered later.
 *
 * THE SIGNING KEY IS DERIVED, NOT REUSED. DNB_SECRET_KEY is documented as the
 * "symmetric key for stored device credentials" (Plugin/Credentials). Signing
 * sessions with the same bytes would be key reuse across two unrelated
 * purposes, so the key here is HMAC-derived from it under a distinct label.
 * No new secret has to be provisioned, and none is in source control — the
 * master key is supplied at install time (B-1).
 */
final class AdminSession
{
    public const COOKIE = 'dnb_admin_session';

    /** Short on purpose: this is a development identity, not a workday. */
    public const TTL_SECONDS = 3600;

    private const LABEL = 'dnb:admin-session:v1';

    private string $key;

    public function __construct(?string $masterKey = null)
    {
        $master = $masterKey ?? (getenv('DNB_SECRET_KEY') ?: '');
        if ($master === '') {
            throw new \RuntimeException(
                'DNB_SECRET_KEY is not set, so an admin session cannot be signed. '
                . 'Refusing to run with an implicit or default key.');
        }
        $this->key = hash_hmac('sha256', self::LABEL, $master, true);
    }

    /** @return string the token a client stores in its cookie */
    public function mint(string $subject, StaffRole $role, ?int $now = null): string
    {
        $now     = $now ?? time();
        $payload = json_encode([
            'sub'  => $subject,
            'role' => $role->value,
            'iat'  => $now,
            'exp'  => $now + self::TTL_SECONDS,
        ], JSON_THROW_ON_ERROR);

        $b = self::b64($payload);
        return $b . '.' . self::b64(hash_hmac('sha256', $b, $this->key, true));
    }

    /**
     * @return array{sub:string,role:StaffRole,exp:int}|null null for a token
     *         that is malformed, wrongly signed, or expired. The caller gets
     *         ONE answer for all three: a client learning WHY its token failed
     *         learns something about the key.
     */
    public function verify(string $token, ?int $now = null): ?array
    {
        $now   = $now ?? time();
        $parts = explode('.', $token);
        if (count($parts) !== 2) { return null; }
        [$b, $sig] = $parts;

        $expected = self::b64(hash_hmac('sha256', $b, $this->key, true));
        // Constant time: a timing-variable compare leaks the signature one
        // byte at a time to anyone willing to measure.
        if (!hash_equals($expected, $sig)) { return null; }

        $raw = self::unb64($b);
        if ($raw === null) { return null; }
        try {
            $p = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) { return null; }
        if (!is_array($p) || !isset($p['sub'], $p['role'], $p['exp'])) { return null; }
        if (!is_int($p['exp']) || $p['exp'] <= $now) { return null; }

        $role = StaffRole::tryFrom((string) $p['role']);
        if ($role === null) { return null; }

        return ['sub' => (string) $p['sub'], 'role' => $role, 'exp' => $p['exp']];
    }

    /** The token this request carries, or '' — read from the Cookie header. */
    public static function fromRequest(Request $req): string
    {
        $raw = $req->header('Cookie') ?? '';
        foreach (explode(';', $raw) as $pair) {
            $kv = explode('=', trim($pair), 2);
            if (count($kv) === 2 && $kv[0] === self::COOKIE) { return trim($kv[1]); }
        }
        return '';
    }

    /**
     * HttpOnly so no script can read it — including a compromised panel bundle.
     * SameSite=Strict so it is not sent on a cross-site request at all.
     * Secure only when the request arrived over TLS, because a Secure cookie
     * on plain http is simply dropped and the developer sees a login that
     * silently never works.
     */
    public static function setCookie(string $token, bool $https, int $maxAge): string
    {
        return self::COOKIE . '=' . $token
             . '; Path=/; HttpOnly; SameSite=Strict; Max-Age=' . $maxAge
             . ($https ? '; Secure' : '');
    }

    public static function clearCookie(bool $https): string
    {
        return self::setCookie('', $https, 0);
    }

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function unb64(string $s): ?string
    {
        $out = base64_decode(strtr($s, '-_', '+/'), true);
        return $out === false ? null : $out;
    }
}
