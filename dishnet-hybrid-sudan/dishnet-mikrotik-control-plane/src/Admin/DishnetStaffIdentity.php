<?php
declare(strict_types=1);
namespace Dn\Admin;

use Dn\Db\Database;
use Dn\Http\Request;
use Dn\Http\Response;

/**
 * THE DishNet Staff identity provider (docs/114 G-B, migration 026).
 *
 * What this class does NOT do is the security model:
 *
 *   * it never sees a password hash or a TOTP secret — bcrypt and TOTP are
 *     verified inside mt_staff_login(), by pgcrypto, in one transaction with
 *     the lockout counters and the audit row;
 *   * it never decides who is locked out — the decaying lockout is inside the
 *     function, so a second caller could not bypass it;
 *   * it never stores the cookie token — only HMAC(token, K) reaches the
 *     database, and K is derived under a label from DNB_TOKEN_PEPPER;
 *   * it never says WHY a login failed — the function returns the empty set
 *     for every failing path after spending a bcrypt, and this class turns
 *     every empty set into one byte-identical 401;
 *   * it never takes a role, a customer or an operator from the request —
 *     role comes back from the session row, live, on every request.
 *
 * Connections. Authentication runs as dnb_staffauth: EXECUTE on login, resolve
 * and logout, zero table privileges, and — measured in docs/114 §M — unable
 * to reach any of dnb_adminwrite's seven write functions. Self-service (the
 * three acts on the caller's OWN credential, identified by its own session
 * token) runs on the Admin write connection, opened lazily and only then.
 *
 * Transport. A session is issued only over TLS as TransportPolicy sees it:
 * PHP terminated TLS itself, or a CONFIGURED proxy asserted it. A plain-HTTP
 * login is refused with 403 insecure_transport rather than issued without the
 * Secure flag (docs/114 §N R-3) — a cookie without Secure is a session that
 * can be replayed by anyone on the path.
 */
final class DishnetStaffIdentity implements StaffSessionPort
{
    public const PROVIDER = 'dishnet';
    private const TOKEN_RX = '/^[0-9a-f]{64}$/';

    /**
     * @param \Closure(): Database $writer the Admin write connection, opened on first self-service use
     */
    public function __construct(
        private readonly Database $auth,
        private readonly \Closure $writer,
        private readonly StaffToken $tokens,
        private readonly TransportPolicy $transport,
        private readonly bool $requireSecondFactor = true,
    ) {}

    public function providerName(): string { return self::PROVIDER; }

    public function requiresSecondFactor(): bool { return $this->requireSecondFactor; }

    // ── AdminIdentityPort ───────────────────────────────────────────────────

    public function identify(Request $req): ?StaffIdentity
    {
        $token = StaffToken::fromRequest($req);
        if ($token === '' || !preg_match(self::TOKEN_RX, $token)) { return null; }
        // status and role are re-read live inside the function: disabling a
        // person is enforced on the very next request, whatever the row says.
        $row = $this->auth->one('SELECT * FROM mt_staff_session_resolve(?)', [$this->tokens->hash($token)]);
        return $row === null ? null : $this->identityOf($row);
    }

    // ── StaffSessionPort ────────────────────────────────────────────────────

    public function loginSurface(): array
    {
        return [
            'mode'          => 'credentials',
            'roles'         => [],                // a role is never chosen by the browser
            'second_factor' => $this->requireSecondFactor ? 'required' : 'optional',
        ];
    }

    public function login(Request $req): Response
    {
        if (!$this->transport->isTls($req)) {
            return new Response(403, [
                'error'  => 'insecure_transport',
                'detail' => 'a staff session is issued only over TLS; terminate TLS in front of '
                          . 'this process and name that proxy in DN_TRUSTED_PROXY',
            ]);
        }
        foreach (['username', 'password', 'code'] as $k) {
            if (isset($req->body[$k]) && !is_string($req->body[$k])) {
                return Response::badRequest('credential fields must be strings');
            }
        }
        // Missing fields are NOT 400: an empty username is an unknown username,
        // and an unknown username must be indistinguishable from a wrong password
        // (docs/114 §N R-8). The function spends a bcrypt on it like any other.
        $username = (string) ($req->body['username'] ?? '');
        $password = (string) ($req->body['password'] ?? '');
        $code     = trim((string) ($req->body['code'] ?? ''));

        $token = StaffToken::mint();
        $row = $this->auth->one('SELECT * FROM mt_staff_login(?,?,?,?,?)', [
            $username, $password, $code === '' ? null : $code,
            $this->tokens->hash($token), $req->ip === '' ? null : $req->ip,
        ]);
        if ($row === null) { return self::refused(); }

        $who = $this->identityOf($row);
        $ttl = max(1, (int) strtotime((string) $row['expires_at']) - time());
        return new Response(200, ['identity' => $who->describe() + ['expires_in' => $ttl]],
            ['Set-Cookie' => StaffToken::setCookie($token, $ttl, true)]);
    }

    public function logout(Request $req): Response
    {
        $token = StaffToken::fromRequest($req);
        if ($token !== '' && preg_match(self::TOKEN_RX, $token)) {
            // Revokes the row. A second call finds nothing and audits nothing.
            $this->auth->one('SELECT mt_staff_logout(?) AS r', [$this->tokens->hash($token)]);
        }
        // Always 204: a logout that reported "you were not signed in" would
        // answer a question about somebody else's cookie.
        return new Response(204, [], ['Set-Cookie' => StaffToken::clearCookie($this->transport->isTls($req))]);
    }

    public function changePassword(Request $req): Response
    {
        [$who, $hash, $fail] = $this->caller($req);
        if ($fail !== null) { return $fail; }
        if ($who->secondFactorPending) { return self::secondFactorRequired(); }
        foreach (['current', 'replacement'] as $k) {
            if (!is_string($req->body[$k] ?? null)) { return Response::badRequest('current and replacement are required'); }
        }
        try {
            $ok = (bool) $this->write()->one('SELECT mt_staff_change_password(?,?,?) AS r',
                [$hash, $req->body['current'], $req->body['replacement']])['r'];
        } catch (\PDOException $e) {
            return self::refusal($e);
        }
        // A wrong current password is the one failure here, and it is the same
        // answer a wrong login gets.
        return $ok ? Response::ok(['changed' => true]) : self::refused();
    }

    public function totpEnrol(Request $req): Response
    {
        [$who, $hash, $fail] = $this->caller($req);
        if ($fail !== null) { return $fail; }
        try {
            // hex, not bytea: pdo_pgsql hands a bytea back as a stream.
            $hex = $this->write()->one('SELECT encode(mt_staff_totp_enrol(?), \'hex\') AS k', [$hash])['k'] ?? null;
        } catch (\PDOException $e) {
            return self::refusal($e);
        }
        if ($hex === null) { return Response::unauthorized(); }
        $b32    = self::base32(hex2bin((string) $hex));
        $issuer = 'DishNet Admin';
        $label  = rawurlencode($issuer) . ':' . rawurlencode($who->subject);
        return Response::ok(['enrolment' => [
            'issuer'    => $issuer,
            'account'   => $who->subject,
            'algorithm' => 'SHA1', 'digits' => 6, 'period' => 30,
            'key'       => $b32,
            'uri'       => "otpauth://totp/{$label}?secret={$b32}&issuer=" . rawurlencode($issuer)
                         . '&algorithm=SHA1&digits=6&period=30',
            'next'      => 'POST /session/totp/confirm with the code your authenticator shows',
        ]]);
    }

    public function totpConfirm(Request $req): Response
    {
        [$who, $hash, $fail] = $this->caller($req);
        if ($fail !== null) { return $fail; }
        $code = trim((string) ($req->body['code'] ?? ''));
        if (!preg_match('/^[0-9]{6}$/', $code)) { return Response::badRequest('code must be six digits'); }
        $ok = (bool) $this->write()->one('SELECT mt_staff_totp_confirm(?,?) AS r', [$hash, $code])['r'];
        if (!$ok) { return new Response(400, ['error' => 'code_rejected']); }
        // The session that enrolled is now a two-factor session: re-read it.
        $now = $this->identify($req);
        return Response::ok(['confirmed' => true, 'identity' => $now?->describe()]);
    }

    // ── internals ───────────────────────────────────────────────────────────

    private function identityOf(array $row): StaffIdentity
    {
        return new StaffIdentity(
            (string) $row['staff_username'],
            StaffRole::from((string) $row['staff_role']),
            self::PROVIDER,
            $this->requireSecondFactor && (string) $row['factor'] !== 'password+totp',
            (bool) $row['totp_enrolled'],
        );
    }

    /** @return array{0:?StaffIdentity,1:string,2:?Response} */
    private function caller(Request $req): array
    {
        $token = StaffToken::fromRequest($req);
        if ($token === '' || !preg_match(self::TOKEN_RX, $token)) { return [null, '', Response::unauthorized()]; }
        $who = $this->identify($req);
        if ($who === null) { return [null, '', Response::unauthorized()]; }
        return [$who, $this->tokens->hash($token), null];
    }

    private function write(): Database { return ($this->writer)(); }

    /** ONE failure shape for every credential failure. Built fresh, compared byte-for-byte by the suite. */
    private static function refused(): Response
    {
        return new Response(401, ['error' => 'invalid_credentials']);
    }

    private static function secondFactorRequired(): Response
    {
        return new Response(403, ['error' => 'second_factor_required']);
    }

    private static function refusal(\PDOException $e): Response
    {
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());
        if ($state === '23514') {
            $why = preg_match('/ERROR:\s+(.+?)(\r?\n|$)/', $e->getMessage(), $m) ? trim($m[1]) : 'refused';
            return new Response(409, ['error' => 'refused', 'detail' => $why]);
        }
        throw $e;
    }

    /** RFC 4648 base32, no padding — what every authenticator app expects. */
    private static function base32(string $bytes): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($bytes) as $c) { $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT); }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= $alphabet[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }
        return $out;
    }
}
