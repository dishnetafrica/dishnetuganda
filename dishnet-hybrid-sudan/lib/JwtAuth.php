<?php
declare(strict_types=1);

/**
 * JwtAuth — Zero-dependency JWT (HMAC-SHA256) for the DishNet Hybrid plugin.
 *
 * Two ways to build one:
 *
 *   JwtAuth::fromConfig($config)     LEGACY. The key is derived from
 *                                    webhook_secret | crm_auth_token | a constant,
 *                                    which on an install where both are empty is a
 *                                    constant anyone can read in this file. Kept
 *                                    only for api/v2/router.php (staff tokens, not
 *                                    routed by public.php) and for the data-report
 *                                    hand-off token (see app_data_report_link).
 *                                    NEVER for customer sessions since Phase 2.
 *
 *   JwtAuth::forCustomers($config)   Phase 2 of the customer-login audit (§E.1).
 *                                    The key is one of customer_jwt_keys, chosen
 *                                    by customer_jwt_active_kid; the token carries
 *                                    the key id in its header and iss/aud in its
 *                                    claims, and verify() requires all three, so a
 *                                    token signed with the legacy derivation — or
 *                                    with an unknown key id — is refused outright.
 *
 * PHP 7.4 compatible. Zero external dependencies.
 */
class JwtAuth
{
    /** The audience every customer-portal token names. */
    public const CUSTOMER_AUDIENCE = 'customer-portal';

    private string $secret;
    private int $ttl;
    /** Customer mode: every key that may still verify, by key id. Empty in legacy mode. */
    private array $keys = [];
    /** Customer mode: the key id new tokens are signed with. */
    private string $kid = '';
    private string $iss = '';
    private string $aud = '';

    /**
     * @param string $secret  HMAC signing secret (min 32 chars recommended)
     * @param int    $ttl     Token lifetime in seconds (default 86400 = 24h)
     */
    public function __construct(string $secret, int $ttl = 86400)
    {
        if (strlen($secret) < 16) {
            throw new \InvalidArgumentException('JWT secret must be at least 16 characters');
        }
        $this->secret = $secret;
        $this->ttl    = $ttl;
    }

    /**
     * LEGACY. Derives the key from webhook_secret + crm_auth_token + a constant.
     * Not for customer sessions — see the class comment.
     */
    public static function fromConfig(array $config): self
    {
        $ttl = (int)($config['jwt_ttl_seconds'] ?? 86400);
        return new self(self::legacySecret($config), max(3600, $ttl));
    }

    /** The legacy key derivation, in one place. See the class comment for what it is still used for. */
    public static function legacySecret(array $config): string
    {
        $parts = [
            $config['webhook_secret'] ?? '',
            $config['crm_app_key'] ?? $config['crm_auth_token'] ?? '',
            'DishNet-Hybrid-JWT-v2-2026',
        ];
        return hash('sha256', implode('|', $parts));
    }

    /**
     * Customer-portal tokens: the dedicated, generated, vaulted key set.
     *
     * @param int|null $ttlSeconds overrides app_jwt_ttl_days (used by tests and the
     *                             short-lived hand-off token)
     * @throws \RuntimeException when no customer key has been provisioned yet
     */
    public static function forCustomers(array $config, ?int $ttlSeconds = null): self
    {
        require_once __DIR__ . '/CustomerJwtKeys.php';
        $keys = CustomerJwtKeys::keys($config);
        $kid  = CustomerJwtKeys::activeKid($config);
        if ($kid === '' || !isset($keys[$kid])) {
            throw new \RuntimeException('customer JWT key not provisioned');
        }
        $j = new self($keys[$kid], $ttlSeconds ?? CustomerJwtKeys::ttlSeconds($config));
        $j->keys = $keys;
        $j->kid  = $kid;
        $j->iss  = self::customerIssuer();
        $j->aud  = self::CUSTOMER_AUDIENCE;
        return $j;
    }

    /** The issuer a customer token names: this plugin, by its directory name. */
    public static function customerIssuer(): string
    {
        return 'dishnet-hybrid:' . basename(dirname(__DIR__));
    }

    /** The key id new tokens are signed with ('' in legacy mode). */
    public function kid(): string
    {
        return $this->kid;
    }

    /**
     * Issue a signed JWT.
     *
     * @param array $claims Must include 'sub' (subject/user ID).
     *                      Automatically adds: iat, exp, jti — and, in customer
     *                      mode, iss and aud, with kid in the header.
     * @return string Base64url-encoded JWT
     */
    public function issue(array $claims): string
    {
        if (empty($claims['sub'])) {
            throw new \InvalidArgumentException('JWT claims must include "sub"');
        }

        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        if ($this->kid !== '') {
            $header['kid'] = $this->kid;
            $claims['iss'] = $claims['iss'] ?? $this->iss;
            $claims['aud'] = $claims['aud'] ?? $this->aud;
        }
        $claims['iat'] = $claims['iat'] ?? time();
        $claims['exp'] = $claims['exp'] ?? (time() + $this->ttl);
        $claims['jti'] = $claims['jti'] ?? bin2hex(random_bytes(12));

        $segments = [
            $this->base64url(json_encode($header)),
            $this->base64url(json_encode($claims)),
        ];
        $signingInput = implode('.', $segments);
        $signature = hash_hmac('sha256', $signingInput, $this->secret, true);
        $segments[] = $this->base64url($signature);

        return implode('.', $segments);
    }

    /**
     * Verify and decode a JWT.
     *
     * @param string $token The JWT string
     * @return array Decoded claims
     * @throws \RuntimeException On invalid signature, expired token, or malformed JWT
     */
    public function verify(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new \RuntimeException('Malformed JWT: expected 3 segments');
        }

        [$headerB64, $claimsB64, $sigB64] = $parts;

        // The header decides nothing about the algorithm: HS256 is the only one
        // this class has ever produced, and any other value is refused.
        $header = json_decode($this->base64urlDecode($headerB64), true);
        if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256') {
            throw new \RuntimeException('Unsupported JWT header');
        }

        // Which key. Customer mode: the header MUST name a key we hold; a token
        // without a key id is a legacy-derived token and is refused (E3-a).
        $key = $this->secret;
        if ($this->keys !== []) {
            $kid = (string)($header['kid'] ?? '');
            if ($kid === '' || !isset($this->keys[$kid])) {
                throw new \RuntimeException('Unknown JWT key id');
            }
            $key = $this->keys[$kid];
        }

        // Verify signature
        $signingInput = $headerB64 . '.' . $claimsB64;
        $expectedSig  = hash_hmac('sha256', $signingInput, $key, true);
        $actualSig    = $this->base64urlDecode($sigB64);

        if (!hash_equals($expectedSig, $actualSig)) {
            throw new \RuntimeException('Invalid JWT signature');
        }

        // Decode claims
        $claims = json_decode($this->base64urlDecode($claimsB64), true);
        if (!is_array($claims)) {
            throw new \RuntimeException('Malformed JWT claims');
        }

        // Customer mode: the token must be ours (issuer) and for the portal (audience).
        if ($this->keys !== []) {
            if (($claims['iss'] ?? '') !== $this->iss || ($claims['aud'] ?? '') !== $this->aud) {
                throw new \RuntimeException('JWT issuer or audience mismatch');
            }
        }

        // Check expiry (5 second leeway for clock skew)
        if (isset($claims['exp']) && $claims['exp'] < (time() - 5)) {
            throw new \RuntimeException('JWT expired');
        }

        return $claims;
    }

    /**
     * Extract claims WITHOUT verifying signature (for logging/debugging only).
     */
    public function decode(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;
        return json_decode($this->base64urlDecode($parts[1]), true);
    }

    /**
     * Refresh a token (issue new one with same claims, fresh exp).
     */
    public function refresh(string $token): string
    {
        $claims = $this->verify($token); // throws if invalid
        unset($claims['iat'], $claims['exp'], $claims['jti']);
        return $this->issue($claims);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════════════

    private function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64urlDecode(string $data): string
    {
        $padded = str_pad(strtr($data, '-_', '+/'), strlen($data) + (4 - strlen($data) % 4) % 4, '=');
        $decoded = base64_decode($padded, true);
        if ($decoded === false) {
            throw new \RuntimeException('Invalid base64url encoding');
        }
        return $decoded;
    }
}
