<?php
declare(strict_types=1);
namespace Dn\Delivery\RouterOs;

use Dn\Devices\TunnelAddress;
use Dn\Runtime\Bindings;

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
 * THE GATE IS AT THE SOCKET (docs/118 D-7). The real transport — curl to a
 * router — runs only when Bindings::requireRealBindingsAllowed() passes, i.e.
 * DN_ALLOW_REAL_BINDINGS holds the exact F6-B value. An injected transport (a
 * test's fake) never opens a socket and is not gated. There is therefore no
 * path in this codebase that reaches a router without that variable, whoever
 * constructs the client.
 *
 * Evidence labels (docs/118 §C): H1 self-signed REST — VERSION/MODEL
 * DEPENDENT; H9 system/resource, H10 system/identity — DOCUMENTED; H8
 * system/routerboard serial-number — VERSION/MODEL DEPENDENT; H11 timeouts
 * over the tunnel — UNRESOLVED. Nothing here is HARDWARE VERIFIED.
 */
final class RestClient
{
    /** H11 — UNRESOLVED on the tunnel: MTU and CGNAT behaviour are docs/31 Tests B and C. */
    public const CONNECT_TIMEOUT = 5;
    public const TIMEOUT         = 10;

    public function __construct(
        private string $host,
        private string $username,
        private string $password,
        private int $timeout = self::TIMEOUT,
        private ?\Closure $transport = null,   // seam for tests: never opens a socket
        private int $connectTimeout = self::CONNECT_TIMEOUT,
    ) {
        if ($this->transport === null && !self::isTunnelHost($host)) {
            throw new \InvalidArgumentException(
                "refusing to talk to {$host}: management is reachable over the tunnel only");
        }
    }

    /** 10.66.0.0/16 is the management network (docs/36) — one rule, in Dn\Devices\TunnelAddress. */
    public static function isTunnelHost(string $host): bool
    {
        return TunnelAddress::isManagement($host);
    }

    public function get(string $path): array   { return $this->call('GET', $path); }
    public function post(string $path, array $body): array { return $this->call('POST', $path, $body); }
    public function patch(string $path, array $body): array { return $this->call('PATCH', $path, $body); }

    /** system/identity → {name}. H10, DOCUMENTED (docs/31 §3.1 step 3 sets it to DN-<serial-tail>). */
    public function identity(): array { return $this->call('GET', 'system/identity'); }

    /** system/resource → {version, board-name, architecture-name, …}. H9, DOCUMENTED (docs/31 §1.4). */
    public function resource(): array { return $this->call('GET', 'system/resource'); }

    /**
     * system/routerboard → {serial-number, model, firmware, …}. H8, VERSION/MODEL
     * DEPENDENT: a CHR has no RouterBOARD and answers without a serial
     * (docs/30 Artifact 13 rule 3).
     */
    public function routerboard(): array { return $this->call('GET', 'system/routerboard'); }

    /**
     * @return array{status:int,body:mixed,malformed:bool}
     *   `malformed` is true when the router answered a success status with a
     *   body that is not JSON. The adapter treats that as "not confirmed",
     *   never as a value — a half-answer must not read as a state.
     */
    private function call(string $method, string $path, ?array $body = null): array
    {
        $url = 'https://' . $this->host . '/rest/' . ltrim($path, '/');

        if ($this->transport !== null) {
            $r = ($this->transport)($method, $url, $body, $this->username, $this->password);
            return self::normalise($r);
        }

        // The one place a socket to a router can be opened. F6-B, or nothing.
        Bindings::requireRealBindingsAllowed('RouterOS REST to a router');

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
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
            // The message must not carry the URL, the address or a credential:
            // it ends up in mt_intents.last_error, which is operational text.
            throw new \RuntimeException('router unreachable: ' . $this->scrub($err));
        }
        return self::normalise(['status' => $status, 'raw' => (string) $raw]);
    }

    /**
     * A transport hands back either a decoded body (the historic seam shape)
     * or the raw text; the raw form is what lets a test send a malformed
     * answer through the same code the real path uses.
     */
    private static function normalise(array $r): array
    {
        $status = (int) ($r['status'] ?? 0);
        if (array_key_exists('raw', $r)) {
            $raw = (string) $r['raw'];
            if ($raw === '') { return ['status' => $status, 'body' => null, 'malformed' => false]; }
            $decoded = json_decode($raw, true);
            $bad = $decoded === null && json_last_error() !== JSON_ERROR_NONE;
            return ['status' => $status, 'body' => $bad ? null : $decoded, 'malformed' => $bad];
        }
        return ['status' => $status, 'body' => $r['body'] ?? null, 'malformed' => (bool) ($r['malformed'] ?? false)];
    }

    /** Remove the host and both credentials from a transport error before it is recorded anywhere. */
    private function scrub(string $text): string
    {
        foreach ([$this->password, $this->username, $this->host] as $secret) {
            if ($secret !== '') { $text = str_replace($secret, '[redacted]', $text); }
        }
        return $text;
    }
}
