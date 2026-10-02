<?php
declare(strict_types=1);

require_once __DIR__ . '/CustomerSession.php';

/**
 * StaffApiCsrf — the same-site guard for the staff JSON API (PD-8, docs/54).
 *
 * The two staff JSON surfaces —
 *   • public.php?page=api        (includes/api_handlers.php), and
 *   • public.php?page=stock_api  (includes/routes.php)
 * authenticate a request by EITHER:
 *   • a Bearer api_token  — the mobile app, n8n, Evolution, every server
 *     integration (docs/54 found every non-browser caller uses this); or
 *   • the browser session cookie  — $_SESSION['kyc_retailer'], whose cookie is
 *     deliberately SameSite=None so the uCRM iframe can carry it.
 *
 * A SameSite=None cookie is attached by the browser on CROSS-SITE requests too,
 * so a malicious page a signed-in staff member visits can drive a state-changing
 * request to the API on their behalf (CSRF). docs/54 measured the gap: the JSON
 * path never ran csrfCheck(), and CORS is `Access-Control-Allow-Origin: *`.
 * `*` (without Allow-Credentials) blocks only READING a cross-origin response; a
 * "simple" cross-site request — a form / FormData / text-plain POST — needs no
 * CORS preflight, so the write still lands. That is the live risk this closes.
 *
 * This guard refuses exactly one thing: a COOKIE-authenticated, state-changing
 * request that does not come from our own page. It changes NOTHING for Bearer
 * integrations, and NOTHING for GET/HEAD reads.
 *
 * What "our own page" means is delegated to CustomerSession::crossSite() — the
 * customer portal's own established rule, reused verbatim so there is a single
 * definition in the codebase. crossSite() returns true (cross-site) when:
 *   • Sec-Fetch-Site: cross-site                         (modern browsers set this), or
 *   • an Origin is present and its host(:port) ≠ the request's own Host.
 * It returns false (same-site, allowed) when:
 *   • neither signal is present — a browser that tells us nothing. A cross-origin
 *     fetch() or form submission ALWAYS carries an Origin, so the real CSRF vector
 *     is still caught; this only fails open for a SAME-origin caller that strips
 *     both headers (the daily csrfToken covers the form-POST surface separately).
 *   • Origin is exactly "null" — treated as "no Origin signal", not as a host.
 *
 * Legitimate origins — there is NO static allow-list. Each Origin is compared to
 * the request's OWN Host, so every hostname the API is legitimately reached on
 * validates against itself: the Traefik hostname when the Host is the Traefik
 * hostname, the UISP :8443 origin when the Host is that origin, 127.0.0.1:<port>
 * in tests. The guard therefore does NOT assume the Traefik hostname and the
 * :8443 origin are interchangeable — a request whose Host is one and whose Origin
 * is the other is cross-site and refused, which is the correct, safe outcome.
 *
 * It never requires X-Requested-With (unlike CustomerSession::cookieUseAllowed()):
 * the staff browser UI does not send that header, so demanding it would break the
 * panel. ONLY crossSite() is reused.
 */
final class StaffApiCsrf
{
    /** The error message returned (with HTTP 403) when a cookie request is cross-site. */
    public const BLOCKED = 'cross_site';

    /**
     * Decide whether a request must be refused as cross-site CSRF.
     *
     * @param bool   $authedViaCookie The SERVER-SIDE authentication OUTCOME: true
     *                                only when the browser session cookie was what
     *                                authenticated this request; false when a Bearer
     *                                token did. It is NEVER inferred from the mere
     *                                presence or absence of an Authorization header —
     *                                an empty or invalid Bearer that falls through to
     *                                the cookie is, correctly, a cookie auth.
     * @param string $method          The HTTP request method.
     * @return bool                   true ⇒ the caller must answer 403 self::BLOCKED.
     */
    public static function mustBlock(bool $authedViaCookie, string $method): bool
    {
        $m = strtoupper(trim($method));
        // Safe/idempotent methods never mutate; OPTIONS is a CORS preflight. A
        // cross-site GET is an ordinary cross-site navigation (a link), not a write.
        if ($m === 'GET' || $m === 'HEAD' || $m === 'OPTIONS') return false;
        // Bearer / api_token integrations carry no browser cookie, so they are not
        // exposed to CSRF and must keep working from any origin.
        if (!$authedViaCookie) return false;
        // A cookie-authenticated mutation is honoured only from our own page.
        return CustomerSession::crossSite();
    }
}
