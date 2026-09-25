<?php
declare(strict_types=1);
namespace Dn\Admin;

use Dn\Http\Request;

/**
 * The staff session cookie (docs/114 §C.1, D-AUTH-2).
 *
 * The browser holds 256 random bits and nothing else. The database holds only
 * HMAC-SHA256(token, K), where K is derived from DNB_TOKEN_PEPPER under a label —
 * the same shape the customer plane's Authenticator uses, and the AdminSession
 * lesson: derive, never reuse a secret across purposes, and provision no new one.
 *
 * Nothing in this class can produce a hash from the database side, and nothing
 * can recover a token from a hash.
 */
final class StaffToken
{
    public const COOKIE = 'dnb_staff_session';
    private const LABEL = 'dnb:staff-session:v1';

    private string $key;

    public function __construct(?string $pepper = null)
    {
        $p = $pepper ?? (getenv('DNB_TOKEN_PEPPER') ?: '');
        if ($p === '') {
            throw new \RuntimeException(
                'DNB_TOKEN_PEPPER is not set, so a staff session cannot be stored. '
                . 'Refusing to run with an implicit or default key.');
        }
        $this->key = hash_hmac('sha256', self::LABEL, $p, true);
    }

    /** 32 random bytes — 256 bits of entropy, hex on the wire. */
    public static function mint(): string { return bin2hex(random_bytes(32)); }

    public function hash(string $token): string
    {
        return hash_hmac('sha256', $token, $this->key);
    }

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
     * HttpOnly so no script can read it; SameSite=Strict so it is never sent on a
     * cross-site request; Secure whenever the transport is TLS — and the real
     * provider refuses to issue a session at all when it is not (docs/114 §G.6).
     */
    public static function setCookie(string $token, int $maxAge, bool $secure): string
    {
        return self::COOKIE . '=' . $token
             . '; Path=/; HttpOnly; SameSite=Strict; Max-Age=' . $maxAge
             . ($secure ? '; Secure' : '');
    }

    public static function clearCookie(bool $secure): string
    {
        return self::setCookie('', 0, $secure);
    }
}
