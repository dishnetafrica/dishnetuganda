<?php
declare(strict_types=1);
namespace Dn\Delivery\RouterOs;

/**
 * RouterOS REST client, spoken over the WireGuard tunnel.
 *
 * docs/30 Artifact 3b: REST is the steady-state management interface; scripting
 * and /import remain the bootstrap and recovery mechanism, because they work
 * before REST is configured and after a reset, which REST does not.
 *
 * TLS: REST needs www-ssl, and over the tunnel the transport is already
 * authenticated and encrypted, so a self-signed per-device certificate is
 * sufficient and avoids a public PKI for every router. That means peer
 * verification is deliberately off for tunnel addresses — and ONLY for tunnel
 * addresses. The constructor refuses a non-tunnel host so this cannot quietly
 * become "TLS verification is off everywhere".
 *
 * REQUIRES VERIFICATION (docs/30 Artifact 3b): that RouterOS serves REST on a
 * self-signed certificate without further configuration. Not yet confirmed on
 * a device.
 */
final class RestClient
{
    public function __construct(
        private string $host,
        private string $username,
        private string $password,
        private int $timeout = 10,
        private ?\Closure $transport = null,   // seam for tests
    ) {
        if ($this->transport === null && !self::isTunnelHost($host)) {
            throw new \InvalidArgumentException(
                "refusing to talk to {$host}: management is reachable over the tunnel only");
        }
    }

    /** 10.66.0.0/16 is the management network (docs/36). */
    public static function isTunnelHost(string $host): bool
    {
        $h = preg_replace('#^https?://#', '', $host);
        $h = explode(':', $h)[0];
        return (bool) preg_match('/^10\.66\.\d{1,3}\.\d{1,3}$/', $h);
    }

    public function get(string $path): array   { return $this->call('GET', $path); }
    public function post(string $path, array $body): array { return $this->call('POST', $path, $body); }
    public function patch(string $path, array $body): array { return $this->call('PATCH', $path, $body); }

    /** @return array{status:int,body:mixed} */
    private function call(string $method, string $path, ?array $body = null): array
    {
        $url = 'https://' . $this->host . '/rest/' . ltrim($path, '/');

        if ($this->transport !== null) {
            return ($this->transport)($method, $url, $body, $this->username, $this->password);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_USERPWD        => $this->username . ':' . $this->password,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            // See the class comment: self-signed per-device certificate over
            // an already-authenticated tunnel, and the host is constrained to
            // 10.66.0.0/16 above.
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            // The message must not carry the URL: it contains the tunnel
            // address, and this text ends up in mt_intents.last_error.
            throw new \RuntimeException('router unreachable: ' . $err);
        }
        return ['status' => $status, 'body' => json_decode((string) $raw, true)];
    }
}
