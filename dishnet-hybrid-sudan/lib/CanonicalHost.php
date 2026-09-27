<?php
declare(strict_types=1);
/**
 * CanonicalHost — one public address for the customer pages (5.18.41, docs/38 A1.3).
 *
 * The plugin answers on whatever host and port a request arrives on. On the
 * Uganda host that is two origins for the same pages: the public address
 * (Traefik, a trusted certificate) and UISP's own listener on :8443, whose
 * certificate is self-signed for "localhost". A customer who reaches the portal
 * there sees a browser warning, and every relative link — the invoice PDF
 * included — inherits that origin (docs/37 §J.2, docs/39 §7).
 *
 * The GENERATED links have been corrected by `crm_public_url` since 5.18.34
 * (lib/crm_url.php). This corrects the page's OWN origin: a GET or HEAD for one
 * of the customer PAGES that arrives on the public host with an explicit,
 * different port is answered 302 to the public address, same path and query.
 *
 * Deliberately narrow, and loop-proof by construction:
 *   - no `crm_public_url` (the South Sudan install) → nothing happens, ever;
 *   - the customer PAGES only (PAGES below), never `page=api`: once the page is
 *     on the public origin every relative API call follows it, and an API call
 *     is never redirected;
 *   - GET and HEAD only; a POST is never redirected;
 *   - a request carrying the native wrapper's marker (X-DishNet-Client) is
 *     never redirected — the wrapper keeps the address it was built with;
 *   - the SAME host name, with an EXPLICIT port that differs from the public
 *     one. That is the shape ":8443" (and ":8080", which UISP itself sends to
 *     :8443) has, and the shape the public origin never has: a browser omits
 *     :443, and the public origin — reached through Traefik and UISP's proxy —
 *     presents exactly the public host without a port (measured: the cookie
 *     POSTs' same-origin check on HTTP_HOST passed there, docs/37 §I.5). So a
 *     request on the public origin can never match this rule, whatever the
 *     scheme detection says, and a loop is impossible. A Host that names
 *     another host (an alias, a proxy that rewrote it) is left alone.
 *   - HTTP_HOST alone decides. X-Forwarded-Host is trusted nowhere else in this
 *     plugin, so it is not consulted here either.
 *   - 302, not 301: the address is proven stable first; a cached 301 cannot be
 *     taken back.
 *
 * `target()` is pure — it decides from the arrays it is handed — so the rule is
 * tested without a web server; `enforce()` is the one call site's wrapper.
 */
final class CanonicalHost
{
    /** The pages a customer reaches by address. The staff pages and the API are never redirected. */
    public const PAGES = ['customer_login', 'customer_portal', 'terms', 'privacy', 'customer_manifest'];

    /** The wrapper's marker header, as PHP names it (CustomerSession::nativeClient reads the same one). */
    public const NATIVE_MARKER = 'HTTP_X_DISHNET_CLIENT';

    /**
     * The redirect target for this request, or null when nothing is to be done.
     *
     * @param array $config  the plugin configuration (crm_public_url, or the installed public URL behind it)
     * @param string $page   the ?page= value being served
     * @param array $server  $_SERVER, or a test's stand-in
     */
    public static function target(array $config, string $page, array $server): ?string
    {
        if (!in_array($page, self::PAGES, true)) return null;
        $method = strtoupper(trim((string)($server['REQUEST_METHOD'] ?? 'GET')));
        if ($method !== 'GET' && $method !== 'HEAD') return null;
        if (trim((string)($server[self::NATIVE_MARKER] ?? '')) !== '') return null;

        require_once __DIR__ . '/crm_url.php';
        $over = dn_public_override($config);                 // the same rule every generated link follows
        if ($over === '') return null;
        $o = parse_url($over);
        if (!is_array($o) || empty($o['host'])) return null;
        $overScheme = strtolower((string)($o['scheme'] ?? 'https'));
        $overHost   = strtolower((string)$o['host']);
        $overPort   = isset($o['port']) ? (int)$o['port'] : ($overScheme === 'https' ? 443 : 80);

        $hostHdr = strtolower(trim((string)($server['HTTP_HOST'] ?? '')));
        if ($hostHdr === '' || preg_match('/^(\[[^\]]+\]|[^:\/\s]+)(?::(\d{1,5}))?$/', $hostHdr, $m) !== 1) return null;
        $reqHost = $m[1];
        $reqPort = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : 0;   // 0: no explicit port in the Host header
        if ($reqHost !== $overHost) return null;                      // another name: not ours to redirect
        if ($reqPort === 0 || $reqPort === $overPort) return null;    // no explicit port, or already the public one

        $uri = (string)($server['REQUEST_URI'] ?? '');
        if ($uri === '' || $uri[0] !== '/') $uri = '/' . ltrim($uri, '/');
        return $over . $uri;
    }

    /** Answer the redirect and stop, when target() says so; otherwise return and let the page render. */
    public static function enforce(array $config, string $page): void
    {
        $to = self::target($config, $page, $_SERVER);
        if ($to === null) return;
        while (ob_get_level() > 0) ob_end_clean();
        header('Cache-Control: no-store');
        header('Location: ' . $to, true, 302);
        exit;
    }
}
