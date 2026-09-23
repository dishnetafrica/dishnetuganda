<?php
declare(strict_types=1);
namespace Dn\Admin;

use Dn\Http\Request;

/**
 * Did this request arrive over TLS? (docs/114 §G.6, §H)
 *
 * PHP knows for certain only when it terminated TLS itself. Behind a reverse
 * proxy the answer is a header, and a header is trusted only from the address
 * that DN_TRUSTED_PROXY names — never from an arbitrary client that happens to
 * send X-Forwarded-Proto: https. With no trusted proxy configured, the header
 * is ignored entirely, so a misconfigured deployment fails closed: no Secure
 * cookie can be issued, and the real provider refuses to sign anyone in.
 */
final class TransportPolicy
{
    /** @param list<string> $trustedProxies */
    public function __construct(private readonly array $trustedProxies) {}

    public static function fromEnvironment(): self
    {
        $raw = getenv('DN_TRUSTED_PROXY') ?: '';
        $list = array_values(array_filter(array_map('trim', explode(',', $raw)), fn($v) => $v !== ''));
        return new self($list);
    }

    public function isTls(Request $req): bool
    {
        if ($req->https) { return true; }
        if ($this->trustedProxies !== [] && in_array($req->ip, $this->trustedProxies, true)) {
            return strtolower(trim($req->header('X-Forwarded-Proto') ?? '')) === 'https';
        }
        return false;
    }
}
