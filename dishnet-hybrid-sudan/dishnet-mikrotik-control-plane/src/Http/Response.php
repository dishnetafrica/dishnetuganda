<?php
declare(strict_types=1);
namespace Dn\Http;

final class Response
{
    public function __construct(
        public readonly int   $status,
        public readonly array $body = [],
        public readonly array $headers = [],
    ) {}

    public static function ok(array $body): self       { return new self(200, $body); }
    public static function accepted(array $b): self    { return new self(202, $b); }
    public static function noContent(): self           { return new self(204); }

    /**
     * The ONLY failure shape for anything the caller may not have.
     *
     * Never 403. A 403 confirms the resource exists, which is exactly the
     * inference the isolation model forbids (docs/55 §D). Not-found and
     * not-yours must be indistinguishable, so they are the same response.
     */
    public static function notFound(): self
    {
        return new self(404, ['error' => 'not_found']);
    }

    public static function badRequest(string $why): self { return new self(400, ['error' => $why]); }
    public static function unauthorized(): self          { return new self(401, ['error' => 'unauthenticated']); }
    public static function conflict(string $why): self   { return new self(409, ['error' => $why]); }

    public function send(): void
    {
        http_response_code($this->status);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        foreach ($this->headers as $k => $v) { header("{$k}: {$v}"); }
        if ($this->status !== 204) {
            echo json_encode($this->body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
    }
}
