<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use Dn\Admin\AdminSession;
use Dn\Admin\Capability;
use Dn\Admin\DenyAllIdentity;
use Dn\Admin\DevSessionIdentity;
use Dn\Admin\StaffRole;
use Dn\Api\AdminRoutes;
use Dn\Http\Request;
use Dn\Runtime\Bindings;

/**
 * The Admin login boundary.
 *
 * What is being built is the LOGIN UI and its authentication boundary, not a
 * production authentication system. The distinction is the whole subject of
 * this file, and the assertions are arranged so that losing it breaks them:
 *
 *   * production still authenticates NOBODY (DenyAllIdentity, W-4);
 *   * the development identity requires an actual login, expires, and can be
 *     signed out of;
 *   * a production deployment cannot reach the development identity by any
 *     path, including by asking nicely;
 *   * the capability model is unchanged — 401 unauthenticated, 403 authorised
 *     but incapable;
 *   * nothing secret is in the panel bundle.
 */

putenv('DNB_SECRET_KEY=login-suite-key-only');
$sessions = new AdminSession('login-suite-key-only');

/** A request, optionally carrying a session cookie. */
$req = static fn(string $method, string $path, string $cookie = '', array $body = []): Request =>
    new Request($method, $path, $cookie === '' ? [] : ['Cookie' => AdminSession::COOKIE . '=' . $cookie],
                $body);

/** Route a request through a freshly built Admin API and return the Response. */
$call = static function (Request $r, $identity, $issuer = null) {
    $router = AdminRoutes::build($identity, Bindings::defaults(), null, $issuer);
    $m = $router->match($r->method, $r->path);
    if ($m === null) { return null; }
    return $m[0]($r->withParams($m[1]), null, null);
};

// ===========================================================================
t('production authenticates nobody, and says so without offering a form');

$deny = new DenyAllIdentity();
$r = $call($req('GET', '/api/v1/admin/session'), $deny);
is_($r->status, 401, 'GET /session is 401 with the production identity');
is_($r->body['can_authenticate'], false,
    'and reports that NOBODY can authenticate here — state 7, not a login form');
is_($r->body['roles'], [], 'it offers no roles to sign in as');
is_($r->body['provider'], 'deny-all', 'naming the provider that is actually bound');

$r = $call($req('POST', '/api/v1/admin/session', '', ['role' => 'admin']), $deny);
is_($r->status, 501, 'POST /session is 501, not 401 — a configuration fact, not a bad password');
is_($r->body['error'], 'production_authentication_unavailable', 'and names it exactly');

t('an estate route is 401 for an unauthenticated caller');
$r = $call($req('GET', '/api/v1/admin/health'), $deny);
is_($r->status, 401, 'GET /health — 401');
$r = $call($req('GET', '/api/v1/admin/routers'), $deny);
is_($r->status, 401, 'GET /routers — 401');

// ===========================================================================
t('PRODUCTION CANNOT REACH THE DEVELOPMENT IDENTITY — the gates, by execution');

putenv('DN_DEV_STAFF_IDENTITY');           // unset: a production process
throws_(fn() => new DevSessionIdentity($sessions), 'development-only',
    'without the environment gate the development identity REFUSES to construct');

putenv('DN_DEV_STAFF_IDENTITY=yes');       // close, and wrong
throws_(fn() => new DevSessionIdentity($sessions), 'development-only',
    'a nearly-right value is still refused — the gate is an exact string');

putenv('DN_DEV_STAFF_IDENTITY=yes-development-only');
// Read from the constants, not from a guessed variable name: a security gate
// asserted against the wrong environment variable proves nothing, and would
// have passed here by never firing.
putenv(Bindings::REAL_GATE_ENV . '=' . Bindings::REAL_GATE_VALUE);
is_(Bindings::realBindingsAllowed(), true,
    'CONTROL: the real-bindings gate is genuinely open for the next assertion');
throws_(fn() => new DevSessionIdentity($sessions), 'real bindings',
    'and a process authorized for REAL bindings is refused even with the dev gate set');
putenv(Bindings::REAL_GATE_ENV);
is_(Bindings::realBindingsAllowed(), false, 'CONTROL: and closed again afterwards');

// The decisive one: it THROWS rather than degrading. A silent downgrade to
// DenyAllIdentity is how a caller ends up believing it authenticated somebody.
$threw = false;
putenv('DN_DEV_STAFF_IDENTITY');
try { new DevSessionIdentity($sessions); } catch (\RuntimeException) { $threw = true; }
is_($threw, true, 'it throws rather than quietly returning a deny-all identity');

$src = (string) file_get_contents(__DIR__ . '/../plugin/public/api.php');
is_(str_contains($src, 'DevSessionIdentity'), true,
    'CONTROL: the entry point does construct it — so the gates above are load-bearing');
is_(preg_match('/catch[^}]*DenyAllIdentity/s', $src), 0,
    'and the entry point never catches a failed dev identity to fall back to deny-all');

// ===========================================================================
t('with the gate set, a login is required — and it works');

putenv('DN_DEV_STAFF_IDENTITY=yes-development-only');
$dev = new DevSessionIdentity($sessions);

$r = $call($req('GET', '/api/v1/admin/session'), $dev, $dev);
is_($r->status, 401, 'before logging in, even the development identity is 401');
is_($r->body['can_authenticate'], true, 'but it reports that somebody CAN — state 1, a form');
is_($r->body['roles'], ['admin', 'noc', 'sales', 'support'], 'offering the four staff roles');

$r = $call($req('GET', '/api/v1/admin/health'), $dev, $dev);
is_($r->status, 401, 'and an estate route is 401 too — the session is what authenticates');

$r = $call($req('POST', '/api/v1/admin/session', '', ['role' => 'nonsense']), $dev, $dev);
is_($r->status, 400, 'an unknown role is 400 — state 2, refused');

$r = $call($req('POST', '/api/v1/admin/session', '', ['role' => 'admin']), $dev, $dev);
is_($r->status, 200, 'a known role signs in');
is_($r->body['identity']['role'], 'admin', 'as that role');
is_($r->body['identity']['provider'], 'DEVELOPMENT-ONLY',
    'and the provider is unmistakable in the response the panel renders');
$cookie = $r->headers['Set-Cookie'] ?? '';
is_(str_contains($cookie, 'HttpOnly'), true, 'the session cookie is HttpOnly — no script can read it');
is_(str_contains($cookie, 'SameSite=Strict'), true, 'and SameSite=Strict');

preg_match('/' . AdminSession::COOKIE . '=([^;]+)/', $cookie, $mm);
$token = $mm[1] ?? '';
is_($token !== '', true, 'CONTROL: a token was actually issued');

$r = $call($req('GET', '/api/v1/admin/health', $token), $dev, $dev);
is_($r->status, 200, 'carrying it, the estate route answers — state 4, authenticated');
is_($r->body['identity']['provider'], 'DEVELOPMENT-ONLY', 'health names the development provider');

// ===========================================================================
t('an expired session is 401 — state 5, not an error and not a silent renewal');

$old = $sessions->mint('dev', StaffRole::Admin, time() - AdminSession::TTL_SECONDS - 60);
is_($sessions->verify($old), null, 'the token verifies as expired');
$r = $call($req('GET', '/api/v1/admin/session', $old), $dev, $dev);
is_($r->status, 401, 'and the API answers 401 for it');
$r = $call($req('GET', '/api/v1/admin/health', $old), $dev, $dev);
is_($r->status, 401, 'on an estate route too');
is_($sessions->verify($sessions->mint('dev', StaffRole::Admin)) !== null, true,
    'CONTROL: a fresh token minted by the same key DOES verify — expiry is what failed above');

t('a forged or altered token is refused, in constant time');
is_($sessions->verify($token . 'x'), null, 'a tampered signature is refused');
is_($sessions->verify(explode('.', $token)[0] . '.' . strrev(explode('.', $token)[1])), null,
    'a reversed signature is refused');
$fake = new AdminSession('a-different-key-entirely');
is_($sessions->verify($fake->mint('dev', StaffRole::Admin)), null,
    'a token signed with another key is refused — the signature is the boundary');
is_($fake->verify($fake->mint('dev', StaffRole::Admin)) !== null, true,
    'CONTROL: that other key does mint tokens IT can verify');

// ===========================================================================
t('insufficient capability is 403 — state 6, and it is NOT a login prompt');

$r = $call($req('POST', '/api/v1/admin/session', '', ['role' => 'support']), $dev, $dev);
preg_match('/' . AdminSession::COOKIE . '=([^;]+)/', $r->headers['Set-Cookie'] ?? '', $mm);
$supportToken = $mm[1] ?? '';
is_($supportToken !== '', true, 'CONTROL: signed in as support');

$r = $call($req('GET', '/api/v1/admin/routers', $supportToken), $dev, $dev);
is_(in_array($r->status, [200, 501], true), true,
    'support CAN reach a route its role carries (routers.read)');

$r = $call($req('POST', '/api/v1/admin/routers', $supportToken, []), $dev, $dev);
is_($r->status, 403, 'but a route it lacks the capability for is 403, not 401');
is_($r->body['capability'], Capability::ROUTERS_REGISTER, 'naming the capability it lacks');
is_(StaffRole::Support->can(Capability::ROUTERS_REGISTER), false,
    'CONTROL: the role genuinely lacks it — the 403 is the model, not a coincidence');
is_(StaffRole::Admin->can(Capability::ROUTERS_REGISTER), true,
    'CONTROL: and admin genuinely carries it, so the check can come out either way');

t('logging out clears the cookie and returns to unauthenticated');
$r = $call($req('DELETE', '/api/v1/admin/session', $token), $dev, $dev);
is_($r->status, 204, 'logout is 204');
is_(str_contains($r->headers['Set-Cookie'] ?? '', 'Max-Age=0'), true, 'and expires the cookie');
$r = $call($req('DELETE', '/api/v1/admin/session'), $dev, $dev);
is_($r->status, 204, 'logging out when not signed in is also 204 — it reveals nothing');

t('logout CLEARS A COOKIE; it does not revoke — asserted, because it matters');

// Found by driving the real HTTP server rather than the router: after DELETE
// /session, a client that keeps sending the old cookie is still admitted. The
// session is stateless, so there is no row to revoke and the only bound on a
// captured token is its expiry. A browser honouring Set-Cookie stops sending
// it, which is the UX; that is NOT the same as invalidation.
//
// Acceptable for a DEVELOPMENT identity with a one-hour token. NOT acceptable
// for a production provider, which needs real revocation — asserted here so
// the gap is visible in the suite instead of being discovered later.
is_($sessions->verify($token) !== null, true,
    'the token still verifies after logout — there is no server-side revocation');
$r = $call($req('GET', '/api/v1/admin/health', $token), $dev, $dev);
is_($r->status, 200, 'and a client that replays it is still admitted, until it expires');
is_(AdminSession::TTL_SECONDS <= 3600, true,
    'which is why the TTL is an hour, not a working day — expiry is the only bound');

// ===========================================================================
t('the panel bundle carries no credential, key or token');

/**
 * Comments are stripped first. Without that, "there is no password because
 * there is no credential store" — a line written to explain the very rule
 * being checked — fails the check for the word "password". bootstrap.php
 * carries strip_php_comments() for exactly this reason; this is its JS
 * equivalent, and the lesson is the same: a guard must scan CODE, not prose.
 */
$stripJs = static function (string $code): string {
    $code = preg_replace('!/\*.*?\*/!s', ' ', $code) ?? $code;
    return preg_replace('!^\s*//.*$!m', ' ', $code) ?? $code;
};
$js = '';
foreach (['login.js', 'app.js', 'api.js'] as $f) {
    $js .= $stripJs((string) file_get_contents(__DIR__ . '/../panel/' . $f));
}
$html = (string) file_get_contents(__DIR__ . '/../panel/index.html');
$rawGate = (string) file_get_contents(__DIR__ . '/../panel/login.js');
is_(str_contains($rawGate, 'SEVEN STATES'), true,
    'CONTROL: the raw file does contain comment-only text...');
is_(str_contains($js, 'SEVEN STATES'), false,
    '...and the stripper removed it, so what follows scans code rather than prose');

foreach (['DNB_SECRET_KEY', 'DNB_APP_PASS', 'DNB_ADMINAPI_PASS', 'PGPASSWORD',
          'hash_hmac', 'login-suite-key'] as $needle) {
    is_(stripos($js . $html, $needle), false, "the panel contains no '{$needle}'");
}
// NOT a bare search for "password". The login screen legitimately SAYS there
// is no password, and a guard that failed on its own honest copy would be
// telling the author to stop explaining the rule. What must not appear is a
// credential-SHAPED thing: an assignment, a field, a stored value.
foreach (['/password\s*[:=]/i', '/passwd/i', '/secret\s*[:=]/i', '/\btoken\s*[:=]\s*["\']/i'] as $re) {
    is_(preg_match($re, $js . $html), 0, "the panel assigns nothing matching {$re}");
}
is_(preg_match('/password\s*[:=]/i', 'password: "hunter2"'), 1,
    'CONTROL: that pattern DOES match a credential-shaped assignment');
is_(str_contains($js, 'document.cookie'), false,
    'and never touches document.cookie — the session cookie is HttpOnly by design');
is_(str_contains($js, 'DEVELOPMENT-ONLY'), true,
    'CONTROL: the string search does find a string that IS there');

t('the login screen exposes no estate or infrastructure detail');
$gateCode = $stripJs((string) file_get_contents(__DIR__ . '/../panel/login.js'));
foreach (['customer_id', 'tunnel_ip', 'wg_pubkey', 'radius', 'dnb_', 'mt_'] as $needle) {
    is_(stripos($gateCode, $needle), false, "the login gate mentions no '{$needle}'");
}
is_(str_contains($gateCode, 'can_authenticate'), true,
    'CONTROL: the gate CODE is genuinely being scanned — it does contain can_authenticate');

t('production authentication is NOT claimed to be complete');
$manifest = json_decode((string) file_get_contents(__DIR__ . '/../plugin/plugin.json'), true);
is_(str_contains((string) ($manifest['api']['session']['production'] ?? ''), '501'), true,
    'the manifest records that production answers 501, not that login works');
is_(isset($manifest['gates']['W-4']) || str_contains(json_encode($manifest), 'W-4'), true,
    'and W-4 is still named as the open gate it is');

exit(t_summary());
