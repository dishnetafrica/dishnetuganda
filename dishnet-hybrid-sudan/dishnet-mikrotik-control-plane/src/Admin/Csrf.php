<?php
declare(strict_types=1);
namespace Dn\Admin;

use Dn\Http\Request;
use Dn\Http\Response;

/**
 * Cross-site request protection for a cookie-authenticated, mutating API
 * (docs/114 §C.1, §G.7; §N R-4).
 *
 * Three layers, each independent of the others:
 *   1. the cookie is SameSite=Strict, so a browser does not send it cross-site;
 *   2. a mutating request that carries an Origin must match the portal's — the
 *      configured DN_PORTAL_ORIGIN, or, when none is configured, the request's
 *      own Host;
 *   3. a body must be JSON. A cross-site HTML form can send only form or
 *      text/plain bodies, so this alone defeats the classic vector.
 *
 * A request with no Origin at all (curl, a test, another service) passes: CSRF
 * is a browser problem, and every browser sends Origin on a cross-site POST.
 */
final class Csrf
{
    public function __construct(private readonly ?string $portalOrigin) {}

    public static function fromEnvironment(): self
    {
        $o = trim(getenv('DN_PORTAL_ORIGIN') ?: '');
        return new self($o === '' ? null : rtrim(strtolower($o), '/'));
    }

    /** null when the request may proceed; otherwise the refusal to send. */
    public function check(Request $req): ?Response
    {
        if (in_array($req->method, ['GET', 'HEAD', 'OPTIONS'], true)) { return null; }

        if (strtolower(trim($req->header('Sec-Fetch-Site') ?? '')) === 'cross-site') {
            return new Response(403, ['error' => 'cross_origin']);
        }
        $origin = trim($req->header('Origin') ?? '');
        if ($origin !== '') {
            if ($origin === 'null' || !$this->originAllowed($origin, $req->header('Host') ?? '')) {
                return new Response(403, ['error' => 'cross_origin']);
            }
        }
        $ct = strtolower(trim($req->header('Content-Type') ?? ''));
        if ($ct !== '' && !str_starts_with($ct, 'application/json')) {
            return new Response(415, ['error' => 'unsupported_content_type',
                                      'detail' => 'mutating requests carry application/json']);
        }
        return null;
    }

    private function originAllowed(string $origin, string $host): bool
    {
        $origin = rtrim(strtolower($origin), '/');
        if ($this->portalOrigin !== null) { return $origin === $this->portalOrigin; }
        $oh = parse_url($origin, PHP_URL_HOST);
        $op = parse_url($origin, PHP_URL_PORT);
        if (!is_string($oh) || $oh === '') { return false; }
        $originHost = strtolower($oh) . ($op ? ':' . $op : '');
        return $originHost === strtolower(trim($host));
    }
}
