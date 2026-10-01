<?php
declare(strict_types=1);

require_once __DIR__ . '/PhoneNumber.php';
require_once __DIR__ . '/TenantProfile.php';
require_once __DIR__ . '/Totp.php';

/**
 * PartnerAuth — distributor-portal sign-in (WS-A P4c, docs/50, docs/47 §10.3).
 * Two factors, the operator's choice (D-9b): a one-time login CODE to the
 * distributor's already-verified number, THEN a TOTP authenticator.
 *
 * This is the sign-in brain; it does not issue sessions (PartnerSession does,
 * once BOTH factors pass) and it does not SEND the code (it returns a delivery
 * descriptor the dispatcher hands to the messaging port — in dev nothing is
 * sent). It owns:
 *   - the code: generated server-side, stored only as HMAC(code, K) with K
 *     DERIVED from webhook_secret under a distinct label (no new secret; fail
 *     closed if none), 6 digits, short TTL, single-use;
 *   - the second factor: TOTP (lib/Totp.php), required for every sign-in,
 *     enrolled on first use and confirmed before it can satisfy a login;
 *   - anti-enumeration: an unknown phone is answered exactly like a known one
 *     (uniform "sent" / "invalid"); the code is written for a known active user
 *     only, and the attempt/rate counters live in the same DB, written with the
 *     attempt so a second caller cannot bypass them (docs/89 / docs/49 §8);
 *   - decaying rate limits per account and per address (never a hard permanent
 *     lock) — every number lives in one DEFAULTS table, overridable by config.
 *
 * Every number in one place; nothing is a magic literal elsewhere.
 */
final class PartnerAuth
{
    private const KEY_LABEL = 'dn-partner-otp-v1';

    /** All tunables, one place. Config keys (dist_portal_*) override each. */
    public const DEFAULTS = [
        'code_ttl'            => 600,    // a login code is valid 10 minutes
        'code_digits'         => 6,
        'code_max_attempts'   => 5,      // wrong tries against ONE code before it is dead
        'send_per_hour_user'  => 5,      // codes issued to one account per hour
        'send_per_hour_ip'    => 20,     // codes requested from one address per hour (enumeration blunt)
        'fail_threshold'      => 5,      // failed sign-ins in the window before the lock engages
        'fail_window'         => 86400,  // the window failures are counted over (24h)
        'lock_base'           => 900,    // first lock: 15 minutes, doubling per threshold block…
        'lock_cap'            => 86400,  // …never beyond 24h, and it decays as old failures age out
    ];

    public static function cfg(array $config, string $k): int
    {
        $v = $config['dist_portal_' . $k] ?? null;
        return ($v === null || $v === '') ? (int)self::DEFAULTS[$k] : max(0, (int)$v);
    }

    // ── Code hashing (no new secret; fail closed) ────────────────────────────

    public static function codeKey(array $config): string
    {
        $secret = (string)($config['webhook_secret'] ?? '');
        if (strlen($secret) < 16) throw new \RuntimeException('partner auth: no server secret (webhook_secret) configured');
        return hash_hmac('sha256', self::KEY_LABEL, $secret);
    }

    public static function hashCode(string $code, array $config): string
    {
        return hash_hmac('sha256', $code, self::codeKey($config));
    }

    /** The canonical international form of a sign-in number under the tenant, or ''. */
    public static function canonical(string $rawPhone, TenantProfile $tp): string
    {
        return (string)(PhoneNumber::international($rawPhone, $tp) ?? '');
    }

    private static function ipKey(string $ip): string
    {
        return 'ip:' . substr(hash('sha256', $ip), 0, 40); // hashed; no raw address stored
    }

    // ── Rate ledger (hashed, decaying) ───────────────────────────────────────

    private static function rateCount(\PDO $pdo, string $rkey, int $since): int
    {
        $st = $pdo->prepare("SELECT COUNT(*) FROM dist_partner_rate WHERE rkey = ? AND at > ?");
        $st->execute([$rkey, $since]);
        return (int)$st->fetchColumn();
    }

    private static function rateAdd(\PDO $pdo, string $rkey, int $at): void
    {
        $pdo->prepare("INSERT INTO dist_partner_rate (rkey, at) VALUES (?,?)")->execute([$rkey, $at]);
    }

    private static function rateClear(\PDO $pdo, string $rkey): void
    {
        $pdo->prepare("DELETE FROM dist_partner_rate WHERE rkey = ?")->execute([$rkey]);
    }

    private static function pruneRate(\PDO $pdo, int $now): void
    {
        try { $pdo->prepare("DELETE FROM dist_partner_rate WHERE at < ?")->execute([$now - 7 * 86400]); } catch (\Throwable $e) {}
    }

    /** The decaying lock for one account: ['locked'=>bool, 'retry_in'=>int]. */
    public static function lockStatus(\PDO $pdo, array $config, int $userId, ?int $now = null): array
    {
        $now = $now ?? time();
        $window = self::cfg($config, 'fail_window');
        $threshold = max(1, self::cfg($config, 'fail_threshold'));
        $rkey = 'fail:user:' . $userId;
        $fails = self::rateCount($pdo, $rkey, $now - $window);
        if ($fails < $threshold) return ['locked' => false, 'retry_in' => 0];
        $blocks = intdiv($fails, $threshold);
        $lock = min(self::cfg($config, 'lock_cap'), self::cfg($config, 'lock_base') * (2 ** max(0, $blocks - 1)));
        $st = $pdo->prepare("SELECT MAX(at) FROM dist_partner_rate WHERE rkey = ?");
        $st->execute([$rkey]);
        $lastFail = (int)$st->fetchColumn();
        $until = $lastFail + $lock;
        return $until > $now ? ['locked' => true, 'retry_in' => $until - $now] : ['locked' => false, 'retry_in' => 0];
    }

    private static function findActiveByPhone(\PDO $pdo, string $canonical): ?array
    {
        if ($canonical === '') return null;
        $st = $pdo->prepare("SELECT * FROM dist_partner_users WHERE phone = ? AND status = 'active'");
        $st->execute([$canonical]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    // ── Step 1: request a login code ─────────────────────────────────────────

    /**
     * Request a one-time login code for a phone. The public result is UNIFORM
     * (always status=sent) so a caller cannot tell a known number from an unknown
     * one. 'delivery' is non-null ONLY for an active account under the rate caps;
     * the dispatcher sends it to the verified number and answers {status:sent}.
     *
     * @return array{status:string, delivery:?array, throttled?:bool}
     */
    public static function requestLoginCode(\PDO $pdo, array $config, string $rawPhone, TenantProfile $tp, string $ip = '', ?int $now = null): array
    {
        $now = $now ?? time();
        self::pruneRate($pdo, $now);
        $canonical = self::canonical($rawPhone, $tp);
        $ipKey = self::ipKey($ip);
        $hourAgo = $now - 3600;

        // Per-address cap applies to EVERY request (known or not) — the enumeration brake.
        if (self::rateCount($pdo, 'send:' . $ipKey, $hourAgo) >= self::cfg($config, 'send_per_hour_ip')) {
            self::rateAdd($pdo, 'send:' . $ipKey, $now);
            return ['status' => 'sent', 'delivery' => null, 'throttled' => true];
        }
        self::rateAdd($pdo, 'send:' . $ipKey, $now);

        $user = self::findActiveByPhone($pdo, $canonical);
        if (!$user) {
            return ['status' => 'sent', 'delivery' => null]; // uniform: reveal nothing
        }
        $uid = (int)$user['id'];
        if (self::rateCount($pdo, 'send:user:' . $uid, $hourAgo) >= self::cfg($config, 'send_per_hour_user')) {
            return ['status' => 'sent', 'delivery' => null, 'throttled' => true];
        }

        $digits = max(4, min(8, self::cfg($config, 'code_digits')));
        $code = str_pad((string)random_int(0, (10 ** $digits) - 1), $digits, '0', STR_PAD_LEFT);
        $ttl = self::cfg($config, 'code_ttl');
        $pdo->prepare(
            "INSERT INTO dist_partner_otp (user_id, code_hash, purpose, expires_at, attempts, created_at)
             VALUES (?,?, 'login', ?, 0, ?)
             ON CONFLICT(user_id) DO UPDATE SET code_hash=excluded.code_hash, expires_at=excluded.expires_at, attempts=0, created_at=excluded.created_at"
        )->execute([$uid, self::hashCode($code, $config), $now + $ttl, $now]);
        self::rateAdd($pdo, 'send:user:' . $uid, $now);

        return [
            'status'   => 'sent',
            'delivery' => ['user_id' => $uid, 'phone' => $canonical, 'code' => $code, 'ttl' => $ttl],
        ];
    }

    // ── Step 2: sign in (both factors) ───────────────────────────────────────

    /**
     * The two-factor gate. Returns ok only when the login code AND a confirmed
     * TOTP both pass; then the code is consumed. Intermediate states tell the
     * dispatcher what the client must still do, WITHOUT consuming the code:
     *   need='totp_enrol' — code good, no confirmed authenticator yet
     *   need='totp'       — code good, authenticator enrolled, TOTP not supplied/next step
     * Every refusal is the uniform reason='invalid' (or 'locked'); a wrong code
     * or wrong TOTP counts toward the decaying lock.
     *
     * @return array{ok:bool, user_id?:int, need?:string, reason?:string, retry_in?:int}
     */
    public static function signIn(\PDO $pdo, array $config, string $rawPhone, string $code, string $totpCode, TenantProfile $tp, string $ip = '', ?int $now = null): array
    {
        $now = $now ?? time();
        self::pruneRate($pdo, $now);
        $canonical = self::canonical($rawPhone, $tp);
        $user = self::findActiveByPhone($pdo, $canonical);
        if (!$user) {
            self::rateAdd($pdo, 'fail:' . self::ipKey($ip), $now); // count blind probes by address
            return ['ok' => false, 'reason' => 'invalid'];
        }
        $uid = (int)$user['id'];

        $lock = self::lockStatus($pdo, $config, $uid, $now);
        if ($lock['locked']) return ['ok' => false, 'reason' => 'locked', 'retry_in' => $lock['retry_in']];

        $otp = self::pending($pdo, $uid);
        $codeOk = $otp
            && (int)$otp['expires_at'] > $now
            && (int)$otp['attempts'] < self::cfg($config, 'code_max_attempts')
            && hash_equals((string)$otp['code_hash'], self::hashCode($code, $config));
        if (!$codeOk) {
            if ($otp) $pdo->prepare("UPDATE dist_partner_otp SET attempts = attempts + 1 WHERE user_id = ?")->execute([$uid]);
            self::rateAdd($pdo, 'fail:user:' . $uid, $now);
            return ['ok' => false, 'reason' => 'invalid'];
        }

        // Code is correct. Require the second factor — never consume the code until both pass.
        $enrolled = (string)$user['totp_secret'] !== '' && (int)$user['totp_confirmed'] === 1;
        if (!$enrolled) return ['ok' => false, 'need' => 'totp_enrol', 'user_id' => $uid];
        if (trim($totpCode) === '') return ['ok' => false, 'need' => 'totp', 'user_id' => $uid];
        if (!Totp::verify((string)$user['totp_secret'], $totpCode, $now)) {
            self::rateAdd($pdo, 'fail:user:' . $uid, $now);
            return ['ok' => false, 'reason' => 'invalid'];
        }

        // Both factors good: consume the code, clear the fail counter.
        $pdo->prepare("DELETE FROM dist_partner_otp WHERE user_id = ?")->execute([$uid]);
        self::rateClear($pdo, 'fail:user:' . $uid);
        return ['ok' => true, 'user_id' => $uid];
    }

    private static function pending(\PDO $pdo, int $uid): ?array
    {
        $st = $pdo->prepare("SELECT * FROM dist_partner_otp WHERE user_id = ?");
        $st->execute([$uid]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    // ── TOTP enrolment (dispatcher calls these only after the code is verified) ─

    public static function isEnrolled(array $user): bool
    {
        return (string)($user['totp_secret'] ?? '') !== '' && (int)($user['totp_confirmed'] ?? 0) === 1;
    }

    /**
     * Begin enrolment: mint a secret and return it ONCE (with an otpauth URI) for
     * the authenticator app. Refused once already confirmed — resetting a
     * confirmed authenticator is a separate, deliberate action, never a silent
     * re-enrol. @return array{secret:string, uri:string}
     */
    public static function beginEnrol(\PDO $pdo, int $userId, string $issuer = 'DishNet Distributor'): array
    {
        $u = $pdo->prepare("SELECT id, phone, display_name, totp_confirmed FROM dist_partner_users WHERE id = ?");
        $u->execute([$userId]);
        $user = $u->fetch(\PDO::FETCH_ASSOC);
        if (!$user) throw new \RuntimeException('partner auth: no such user');
        if ((int)$user['totp_confirmed'] === 1) throw new \RuntimeException('partner auth: authenticator already enrolled');
        $secret = Totp::generateSecret();
        $pdo->prepare("UPDATE dist_partner_users SET totp_secret = ?, totp_confirmed = 0, updated_at = datetime('now') WHERE id = ?")
            ->execute([$secret, $userId]);
        $acct = (string)($user['phone'] !== '' ? $user['phone'] : ('user-' . $userId));
        return ['secret' => $secret, 'uri' => Totp::provisioningUri($secret, $acct, $issuer)];
    }

    /**
     * Confirm enrolment by verifying one authenticator code against the pending
     * secret. On success the secret becomes usable for sign-in. Idempotent once
     * confirmed. @return bool
     */
    public static function confirmEnrol(\PDO $pdo, int $userId, string $totpCode, ?int $now = null): bool
    {
        $u = $pdo->prepare("SELECT totp_secret, totp_confirmed FROM dist_partner_users WHERE id = ?");
        $u->execute([$userId]);
        $user = $u->fetch(\PDO::FETCH_ASSOC);
        if (!$user) return false;
        if ((int)$user['totp_confirmed'] === 1) return true;
        if ((string)$user['totp_secret'] === '' || !Totp::verify((string)$user['totp_secret'], $totpCode, $now)) return false;
        $pdo->prepare("UPDATE dist_partner_users SET totp_confirmed = 1, updated_at = datetime('now') WHERE id = ?")->execute([$userId]);
        return true;
    }
}
