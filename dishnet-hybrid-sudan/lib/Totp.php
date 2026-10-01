<?php
declare(strict_types=1);

/**
 * Totp — RFC 6238 time-based one-time passwords (and the RFC 4226 HOTP under it),
 * pure PHP, zero dependencies (WS-A P4c, docs/50, docs/47 §10.3 — the second
 * factor for the distributor portal). Domain B has its own in-PostgreSQL TOTP
 * (migration 026); that is a separate service, so this is built from scratch for
 * this plugin, with its own known-answer tests.
 *
 * Defaults: SHA-1, 30-second step, 6 digits — what Google Authenticator, Authy,
 * 1Password and the rest generate. verify() accepts a ±1-step window to tolerate
 * clock skew; callers that need replay resistance track the last accepted step.
 */
final class Totp
{
    public const DIGITS = 6;
    public const STEP   = 30;
    public const ALGO   = 'sha1';
    private const B32    = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A fresh base32 secret (default 160 bits = 32 base32 chars), A-Z2-7 only. */
    public static function generateSecret(int $bytes = 20): string
    {
        $raw = random_bytes(max(10, $bytes));
        return self::base32encode($raw);
    }

    /** The otpauth:// URI an authenticator app imports (label + issuer, no secret logging). */
    public static function provisioningUri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer . ':' . $account);
        $q = http_build_query([
            'secret' => $secret, 'issuer' => $issuer,
            'algorithm' => strtoupper(self::ALGO), 'digits' => self::DIGITS, 'period' => self::STEP,
        ]);
        return 'otpauth://totp/' . $label . '?' . $q;
    }

    /** The code for a given unix time (default now). '' if the secret is unusable. */
    public static function at(string $secret, ?int $time = null): string
    {
        $key = self::base32decode($secret);
        if ($key === '') return '';
        $counter = intdiv((int)($time ?? time()), self::STEP);
        return self::hotp($key, $counter);
    }

    /**
     * Does $code match the secret within ±$window steps of $time? Constant-time
     * compare per candidate. A blank secret or a non-numeric code never matches.
     * @return bool
     */
    public static function verify(string $secret, string $code, ?int $time = null, int $window = 1): bool
    {
        $code = trim($code);
        if ($secret === '' || !preg_match('/^\d{' . self::DIGITS . '}$/', $code)) return false;
        $key = self::base32decode($secret);
        if ($key === '') return false;
        $now = intdiv((int)($time ?? time()), self::STEP);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::hotp($key, $now + $i), $code)) return true;
        }
        return false;
    }

    /**
     * Which absolute step within ±$window did $code match, or null. Lets a caller
     * reject replay by refusing a step it already accepted.
     */
    public static function matchStep(string $secret, string $code, ?int $time = null, int $window = 1): ?int
    {
        $code = trim($code);
        if ($secret === '' || !preg_match('/^\d{' . self::DIGITS . '}$/', $code)) return null;
        $key = self::base32decode($secret);
        if ($key === '') return null;
        $now = intdiv((int)($time ?? time()), self::STEP);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::hotp($key, $now + $i), $code)) return $now + $i;
        }
        return null;
    }

    /** RFC 4226 HOTP: dynamic-truncated HMAC, zero-padded to DIGITS. */
    private static function hotp(string $key, int $counter): string
    {
        $bin = pack('N', 0) . pack('N', $counter); // 8-byte big-endian counter
        $hash = hash_hmac(self::ALGO, $bin, $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $part = (ord($hash[$offset]) & 0x7F) << 24
              | (ord($hash[$offset + 1]) & 0xFF) << 16
              | (ord($hash[$offset + 2]) & 0xFF) << 8
              | (ord($hash[$offset + 3]) & 0xFF);
        $otp = $part % (10 ** self::DIGITS);
        return str_pad((string)$otp, self::DIGITS, '0', STR_PAD_LEFT);
    }

    public static function base32encode(string $data): string
    {
        if ($data === '') return '';
        $bits = '';
        foreach (str_split($data) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::B32[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }
        return $out;
    }

    /** Tolerant base32 decode: ignores spaces, lower-case and '=' padding. '' on a bad char. */
    public static function base32decode(string $b32): string
    {
        $b32 = strtoupper(preg_replace('/[\s=]+/', '', $b32) ?? '');
        if ($b32 === '') return '';
        $bits = '';
        $len = strlen($b32);
        for ($i = 0; $i < $len; $i++) {
            $v = strpos(self::B32, $b32[$i]);
            if ($v === false) return '';
            $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) $out .= chr(bindec($byte));
        }
        return $out;
    }
}
