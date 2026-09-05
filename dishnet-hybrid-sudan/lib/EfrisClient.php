<?php
declare(strict_types=1);

/**
 * EfrisClient — HTTP transport to an EFRIS endpoint.
 *
 * ██ PHASE 1: TEST TRANSPORT ONLY ██
 *
 * This client speaks the envelope shape documented in URA's own sample
 * client (github.com/ura-sw): globalInfo{interfaceCode, tin, deviceNo,
 * requestId} + data{content, signature, dataDescription} — but in Phase 1
 * the content is plain base64 JSON and the signature is a literal
 * TEST marker. The REAL protocol (T104 daily AES session key, AES-encrypted
 * content, RSA-SHA1 PKCS1 signature, gzip handling) is Phase 2 work,
 * transcribed from the official URA specification once URA issues
 * DishNet's credentials. Until then:
 *
 *   - environment 'production' REFUSES every call, loudly;
 *   - environment 'disabled' refuses too;
 *   - environment 'test' talks only to the configured TEST url
 *     (the local fake server, later the URA sandbox via the Phase-2 crypto).
 *
 * No interface code, field name or enumeration beyond the verified envelope
 * is invented here.
 */
class EfrisClient
{
    public const ENV_DISABLED   = 'disabled';
    public const ENV_TEST       = 'test';
    public const ENV_PRODUCTION = 'production';

    public const IC_SERVER_TIME = 'T101';
    public const IC_INVOICE     = 'T109';

    private array  $config;
    private string $env;
    private string $baseUrl = '';
    private string $refuse  = '';
    private int    $timeout;
    private array  $lastHttp = [];

    public function __construct(array $config, int $timeout = 20)
    {
        $this->config  = $config;
        $this->timeout = $timeout;
        $env = strtolower(trim((string)($config['efris_environment'] ?? '')));
        if (!in_array($env, [self::ENV_DISABLED, self::ENV_TEST, self::ENV_PRODUCTION], true)) {
            $env = self::ENV_DISABLED;
        }
        $this->env = $env;

        if ($env === self::ENV_DISABLED) {
            $this->refuse = 'EFRIS is disabled (efris_environment=disabled).';
        } elseif ($env === self::ENV_PRODUCTION) {
            $this->refuse = 'PRODUCTION EFRIS is not implemented in this build: the Phase-2 connector '
                          . 'requires the official URA specification and credentials. Nothing was sent.';
        } else {
            $this->baseUrl = rtrim(trim((string)($config['efris_test_api_url'] ?? '')), '/');
            if ($this->baseUrl === '') {
                $this->refuse = 'efris_test_api_url is not set — point it at the local fake EFRIS server.';
            }
        }
    }

    public function environment(): string { return $this->env; }
    public function isUsable(): bool      { return $this->refuse === ''; }
    public function refusalReason(): string { return $this->refuse; }

    /** T101: server time — the reachability probe. */
    public function ping(): array
    {
        return $this->call(self::IC_SERVER_TIME, []);
    }

    /** T109: submit one invoice payload (the internal model, Phase 1). */
    public function submitInvoice(array $payload): array
    {
        return $this->call(self::IC_INVOICE, $payload);
    }

    /**
     * @return array{ok:bool, error:string, http:int, envelope:?array, content:?array, raw:string, request_id:string}
     */
    public function call(string $interfaceCode, array $payload): array
    {
        $requestId = bin2hex(random_bytes(16));
        if (!$this->isUsable()) {
            return ['ok' => false, 'error' => $this->refuse, 'http' => 0,
                    'envelope' => null, 'content' => null, 'raw' => '', 'request_id' => $requestId];
        }

        $envelope = [
            'globalInfo' => [
                'interfaceCode' => $interfaceCode,
                'tin'           => trim((string)($this->config['efris_tin'] ?? '')),
                'deviceNo'      => trim((string)($this->config['efris_device_no'] ?? '')),
                'requestId'     => $requestId,
                'requestTime'   => gmdate('Y-m-d H:i:s'),
            ],
            'data' => [
                'content'   => base64_encode(json_encode($payload)),
                // Literal marker: this transport carries no real signature.
                'signature' => 'TEST-SIGNATURE-PHASE1',
                'dataDescription' => ['codeType' => '0', 'encryptCode' => '0', 'zipCode' => '0'],
            ],
        ];

        $ch = curl_init($this->baseUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($envelope),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_PROXY          => '',   // local fake server must not go through any proxy
        ]);
        $raw  = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        $this->lastHttp = ['code' => $http, 'error' => $err];

        if ($raw === false) {
            return ['ok' => false, 'error' => 'Connection failed: ' . $err, 'http' => 0,
                    'envelope' => null, 'content' => null, 'raw' => '', 'request_id' => $requestId];
        }

        $resp = json_decode((string)$raw, true);
        if (!is_array($resp)) {
            return ['ok' => false, 'error' => 'Malformed response (not JSON)', 'http' => $http,
                    'envelope' => null, 'content' => null,
                    'raw' => mb_substr((string)$raw, 0, 2000), 'request_id' => $requestId];
        }

        // Response content mirrors the request encoding in Phase 1: base64 JSON.
        $content = null;
        $b64 = $resp['data']['content'] ?? null;
        if (is_string($b64) && $b64 !== '') {
            $decoded = json_decode((string)base64_decode($b64, true), true);
            if (is_array($decoded)) $content = $decoded;
        }

        $rc  = (string)($resp['returnStateInfo']['returnCode'] ?? '');
        $msg = (string)($resp['returnStateInfo']['returnMessage'] ?? '');
        $ok  = $http < 400 && $rc === '00';

        return ['ok' => $ok,
                'error' => $ok ? '' : ($msg !== '' ? $msg : ('HTTP ' . $http . ', returnCode ' . ($rc === '' ? '?' : $rc))),
                'http' => $http, 'envelope' => $resp, 'content' => $content,
                'raw' => mb_substr((string)$raw, 0, 4000), 'request_id' => $requestId];
    }
}
