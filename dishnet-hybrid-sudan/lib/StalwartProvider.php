<?php
declare(strict_types=1);

require_once __DIR__ . '/MailProviderInterface.php';

/**
 * StalwartProvider — talks to the Stalwart mail server's management REST API
 * over HTTPS (the same listener that serves JMAP and the admin console).
 *
 * Config (kyc_config.json in pluginDataDir — both keys are SECRET_KEYS):
 *   stalwart_api_url    https://mail.dishnetuganda.com   (no trailing slash)
 *   stalwart_api_token  bearer token of a Stalwart admin API key
 *
 * Endpoint paths default to Stalwart's /api/principal management API but are
 * overridable via config, because Stalwart is a fast-moving project:
 *   stalwart_principal_path   default '/api/principal'
 * Before enabling in production, run  php tools/test_stalwart_api.php  on the
 * server — it exercises create/suspend/reset against a throwaway address and
 * reports the exact behaviour of the deployed Stalwart version.
 *
 * Fails closed: unconfigured means every call returns ok=false. The API token
 * is never logged and never included in an error message.
 */
class StalwartProvider implements MailProviderInterface
{
    private string $baseUrl;
    private string $token;
    private string $principalPath;
    /** @var callable|null test seam: fn(method, url, headers, body) => [status, json] */
    private $http;

    public function __construct(array $config, ?callable $httpOverride = null)
    {
        $this->baseUrl       = rtrim(trim((string)($config['stalwart_api_url'] ?? '')), '/');
        $this->token         = trim((string)($config['stalwart_api_token'] ?? ''));
        $this->principalPath = '/' . ltrim((string)($config['stalwart_principal_path'] ?? '/api/principal'), '/');
        $this->http          = $httpOverride;
    }

    public function name(): string { return 'stalwart'; }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && str_starts_with($this->baseUrl, 'https://') && $this->token !== '';
    }

    public function ensureMailbox(string $email, string $displayName, int $quotaMb = 250): array
    {
        if (!$this->isConfigured()) return $this->err('stalwart not configured');
        $local = strstr($email, '@', true) ?: $email;

        // Idempotency: if the principal already exists, that is success.
        [$code] = $this->call('GET', $this->principalPath . '/' . rawurlencode($local));
        if ($code === 200) return $this->ok($local);

        [$code, $resp] = $this->call('POST', $this->principalPath, [
            'type'        => 'individual',
            'name'        => $local,
            'description' => $displayName,
            'emails'      => [$email],
            'quota'       => $quotaMb > 0 ? $quotaMb * 1024 * 1024 : 0,
            // Random discarded password: the account exists but nobody can log
            // in until resetPassword() issues a real one to the customer.
            'secrets'     => [self::randomSecret()],
        ]);
        if ($code >= 200 && $code < 300) return $this->ok($local);
        // A concurrent create that lost the race is still success.
        if ($code === 409) return $this->ok($local);
        return $this->err("create failed (HTTP {$code})" . $this->apiError($resp));
    }

    public function suspendMailbox(string $email): array
    {
        return $this->patchPrincipal($email, [
            ['action' => 'set', 'field' => 'description', 'value' => 'SUSPENDED — DishNet retention hold'],
            // Removing all secrets disables login while keeping every message.
            ['action' => 'set', 'field' => 'secrets', 'value' => []],
        ], 'suspend');
    }

    public function unsuspendMailbox(string $email): array
    {
        // Login is re-enabled the moment a password exists again.
        $r = $this->resetPassword($email);
        return $r['ok'] ? $this->ok('reactivated') : $r;
    }

    public function resetPassword(string $email): array
    {
        $password = self::randomPassword();
        $r = $this->patchPrincipal($email, [
            ['action' => 'set', 'field' => 'secrets', 'value' => [$password]],
        ], 'password reset');
        return $r['ok'] ? $this->ok($password) : $r;
    }

    // ── internals ────────────────────────────────────────────────────────

    private function patchPrincipal(string $email, array $ops, string $what): array
    {
        if (!$this->isConfigured()) return $this->err('stalwart not configured');
        $local = strstr($email, '@', true) ?: $email;
        [$code, $resp] = $this->call('PATCH', $this->principalPath . '/' . rawurlencode($local), $ops);
        if ($code >= 200 && $code < 300) return $this->ok(true);
        return $this->err("{$what} failed (HTTP {$code})" . $this->apiError($resp));
    }

    /** @return array{0:int,1:?array} [http status, decoded json|null] */
    private function call(string $method, string $path, ?array $body = null): array
    {
        $url     = $this->baseUrl . $path;
        $headers = ['Authorization: Bearer ' . $this->token, 'Content-Type: application/json'];
        if ($this->http) return ($this->http)($method, $url, $headers, $body);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($raw === false) return [0, null];
        $json = json_decode((string)$raw, true);
        return [$code, is_array($json) ? $json : null];
    }

    private function apiError(?array $resp): string
    {
        // Only the provider's own error text — never the request, never the token.
        $msg = (string)($resp['error'] ?? $resp['details'] ?? '');
        return $msg !== '' ? ': ' . substr($msg, 0, 200) : '';
    }

    private static function randomSecret(): string   { return bin2hex(random_bytes(24)); }

    private static function randomPassword(): string
    {
        // Readable enough to relay over WhatsApp, strong enough to matter.
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $out = '';
        for ($i = 0; $i < 14; $i++) $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        return $out;
    }

    private function ok($data): array          { return ['ok' => true,  'data' => $data, 'error' => '']; }
    private function err(string $m): array     { return ['ok' => false, 'data' => null,  'error' => $m]; }
}
