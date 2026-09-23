<?php
declare(strict_types=1);
namespace Dn\Http;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array  $headers = [],
        public readonly array  $body = [],
        public readonly array  $params = [],
        public readonly string $ip = '',
        /** True only when THIS process terminated TLS. A proxy's X-Forwarded-Proto is a
         *  header, trusted by TransportPolicy from a configured address and by nothing else. */
        public readonly bool   $https = false,
    ) {}

    public function header(string $name): ?string
    {
        foreach ($this->headers as $k => $v) {
            if (strcasecmp($k, $name) === 0) { return $v; }
        }
        return null;
    }

    public function bearer(): string
    {
        $h = $this->header('Authorization') ?? '';
        return preg_match('/^Bearer\s+(\S+)$/i', $h, $m) ? $m[1] : '';
    }

    public function withParams(array $p): self
    {
        return new self($this->method, $this->path, $this->headers, $this->body, $p, $this->ip, $this->https);
    }

    public static function fromGlobals(): self
    {
        $raw  = file_get_contents('php://input') ?: '';
        $body = $raw === '' ? [] : (json_decode($raw, true) ?: []);
        $h = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $h[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($k, 5)))))] = $v;
            }
        }
        return new self(
            $_SERVER['REQUEST_METHOD'] ?? 'GET',
            parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/',
            $h, is_array($body) ? $body : [], [], $_SERVER['REMOTE_ADDR'] ?? '',
            (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
                || (($_SERVER['REQUEST_SCHEME'] ?? '') === 'https')
        );
    }
}
