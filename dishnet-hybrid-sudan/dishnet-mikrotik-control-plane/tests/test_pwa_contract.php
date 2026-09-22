<?php
/**
 * F6-A P1 — the Customer PWA as a real client.
 *
 * Two halves. The server half asserts the API behaves as the client assumes.
 * The client half reads public/pwa/*.js as text and asserts the rules that
 * cannot be enforced by types: no customer id anywhere, 202 is never success,
 * a missing capability renders as unavailable rather than empty, and no screen
 * invents delivery behaviour that is not hardware verified.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
use Dn\Api\Routes;
use Dn\Auth\Authenticator;
use Dn\Db\Database;
use Dn\Http\Kernel;
use Dn\Http\Request;
use Dn\Tenancy\TenantContext;

$owner = Database::inspector();
$ids = seed_two_customers($owner);
$A = $ids['A']; $B = $ids['B'];

$db   = Database::app();
$auth = new Authenticator($db);
$k    = new Kernel(Routes::build($auth), $db, $auth, new TenantContext($db));
$call = fn(string $m, string $p, array $body = [], string $tok = '')
    => $k->handle(new Request($m, $p, $tok ? ['Authorization' => "Bearer {$tok}"] : [], $body));

putenv('DNB_EXPOSE_OTP=1');
$signIn = function (string $phone) use ($call, $owner): string {
    $owner->exec('DELETE FROM mt_auth_codes WHERE phone = ?', [$phone]);
    $c = $call('POST', '/api/v1/auth/request-code', ['phone' => $phone])->body['dev_code'];
    return $call('POST', '/api/v1/auth/verify', ['phone' => $phone, 'code' => $c])->body['token'];
};
$tokA = $signIn('+256700001001');
$tokB = $signIn('+256700001002');

// ===========================================================================
t('SITE FILTER — a site id filters authorized scope, it never widens it');
// Named key, not a generic envelope — see the note in api.js.
$aSite = $call('GET', '/api/v1/me/sites', [], $tokA)->body['sites'][0]['id'] ?? null;
is_(is_string($aSite), true, 'A has a site of its own');
is_($call('GET', "/api/v1/me/sites/{$aSite}", [], $tokA)->status, 200, 'A reads A\'s site');
is_($call('GET', "/api/v1/me/sites/{$aSite}", [], $tokB)->status, 404,
    'B asking for A\'s site gets 404 — the id filtered nothing into scope');
foreach (['../..', '00000000-0000-0000-0000-000000000000', 'null', '%'] as $junk) {
    is_($call('GET', '/api/v1/me/sites/' . rawurlencode($junk), [], $tokA)->status, 404,
        "a junk site id is 404, never a hint: {$junk}");
}

t('ARBITRARY CUSTOMER ID — supplying one changes nothing');
// The body is the obvious place a client would try. The server derives the
// customer from the token and never reads this.
$mine = $call('GET', '/api/v1/me', [], $tokA)->body['customer']['id'];
$spoof = $call('POST', '/api/v1/me/vouchers',
    ['plan_id' => '00000000-0000-0000-0000-000000000000', 'count' => 1,
     'customer_id' => $B['customer'], 'tenant_id' => $B['customer']], $tokA);
is_($spoof->status, 404, 'a spoofed customer_id does not make another customer\'s plan reachable');
is_($call('GET', '/api/v1/me', [], $tokA)->body['customer']['id'], $mine,
    'and /me still resolves to the token\'s own customer');

// ===========================================================================
t('QUEUED — voucher creation answers 202 and is never a synchronous router call');
$planA = $call('GET', '/api/v1/me/plans', [], $tokA)->body['plans'][0] ?? null;
if ($planA !== null) {
    $r = $call('POST', '/api/v1/me/vouchers', ['plan_id' => $planA['id'], 'count' => 2], $tokA);
    is_($r->status, 202, 'POST /me/vouchers is 202 Accepted, not 200 OK');
    is_(isset($r->body['intent_id']), true, 'and it returns the intent that carries the work');
    is_(isset($r->body['vouchers']), true, 'the codes exist immediately');
    // The client must classify 202 as QUEUED. Asserted on the client below.
}

t('LOGOUT — invalidates the session immediately');
$tokC = $signIn('+256700001001');
is_($call('GET', '/api/v1/me', [], $tokC)->status, 200, 'the fresh token works');
is_($call('POST', '/api/v1/auth/logout', [], $tokC)->status, 204, 'logout is 204');
is_($call('GET', '/api/v1/me', [], $tokC)->status, 401, 'and the token is dead at once');
foreach (['/api/v1/me/sites', '/api/v1/me/vouchers', '/api/v1/me/sessions'] as $p) {
    is_($call('GET', $p, [], $tokC)->status, 401, "no /me route survives logout: {$p}");
}

t('EXPIRED / UNKNOWN SESSION — cannot reach /me/*');
foreach (['', 'not-a-token', str_repeat('a', 64)] as $bad) {
    is_($call('GET', '/api/v1/me', [], $bad)->status, 401, 'an invalid token is 401');
}
$owner->exec("UPDATE mt_auth_sessions SET expires_at = now() - interval '1 hour'");
is_($call('GET', '/api/v1/me', [], $tokA)->status, 401, 'an EXPIRED session is refused');
is_($call('GET', '/api/v1/me/vouchers', [], $tokA)->status, 401, 'on every route, not just /me');

// ===========================================================================
t('NO ROUTER INTERNALS — none of the withheld fields appears in any /me JSON');
$tokA2 = $signIn('+256700001001');
$blob = '';
foreach (['/api/v1/me', '/api/v1/me/services', '/api/v1/me/sites', '/api/v1/me/plans',
          '/api/v1/me/vouchers', '/api/v1/me/sessions', '/api/v1/me/usage',
          '/api/v1/me/uplink', '/api/v1/me/intents', '/api/v1/me/entitlements'] as $p) {
    $blob .= json_encode($call('GET', $p, [], $tokA2)->body);
}
foreach (['serial', 'wg_pubkey', 'wgIp', 'tunnel_ip', 'endpoint', 'ros_version',
          'secret_sealed', 'radius_ref', 'claimed_by', 'staged_by', 'last_error'] as $f) {
    is_(str_contains($blob, $f), false, "no /me response carries '{$f}'");
}

t('GENERATED CREDENTIALS — no /me response exposes an AAA secret');
foreach (['radcheck', 'Cleartext-Password', 'radius_username', 'aaa_secret'] as $f) {
    is_(str_contains($blob, $f), false, "no /me response carries '{$f}'");
}

// ===========================================================================
// The client half. These read the shipped files, so a future edit that breaks
// a rule fails here rather than in a browser nobody was watching.
// ===========================================================================
$pwa = __DIR__ . '/../public/pwa';
$api = file_get_contents($pwa . '/api.js');
$sto = file_get_contents($pwa . '/store.js');
$both = $api . "\n" . $sto;

t('CLIENT — holds a token and no customer identity whatsoever');
foreach (['customer_id', 'customerId', 'tenant_id', 'tenantId', 'TOKEN_TO_CUST'] as $n) {
    is_(str_contains($both, $n), false, "the client never mentions {$n}");
}
$stripJs0 = static function (string $src): string {
    $src = preg_replace('#/\*.*?\*/#s', ' ', $src);
    return preg_replace('#(^|[^:])//.*$#m', '$1', $src);
};
is_(str_contains($api, 'sessionStorage'), true, 'the token lives in sessionStorage');
is_(str_contains($stripJs0($api), 'localStorage'), false,
    'and no CODE touches localStorage — a shared device keeps no session behind');

t('CLIENT — 202 is queued, and queued is not success');
is_(preg_match('/status\s*===\s*202\)\s*return\s*\{\s*state:\s*State\.QUEUED/', $api), 1,
    '202 maps to QUEUED explicitly');
is_(str_contains($api, 'QUEUED:      \'queued\''), true, 'QUEUED is its own state');
foreach (['OK', 'EMPTY', 'QUEUED', 'UNAVAILABLE', 'OFFLINE', 'FAILED', 'LOADING'] as $st) {
    is_(str_contains($api, $st . ':'), true, "the client distinguishes {$st}");
}

t('CLIENT — a missing capability is UNAVAILABLE, never EMPTY');
foreach (['accessPoints', 'billing', 'support'] as $gap) {
    is_(preg_match('/' . $gap . ':\s*State\.UNAVAILABLE/', $sto), 1,
        "{$gap} is marked unavailable, so it cannot render as 'you have none'");
}
is_(str_contains($sto, 'MISSING_CONTRACTS'), true, 'and the gaps are named rather than implied');

t('CLIENT — B1-neutral: no screen claims how or when a router is reached');
/* Checked against CODE, not comments — the same reason the PHP guards call
 * strip_php_comments(). A comment explaining the rule necessarily uses the
 * words the rule forbids; what matters is that no STRING a user can see does.
 * This guard caught exactly that during authoring, which is the point of it. */
$stripJs = static function (string $src): string {
    $src = preg_replace('#/\*.*?\*/#s', ' ', $src);      // block comments
    return preg_replace('#(^|[^:])//.*$#m', '$1', $src);   // line comments, not ://
};
$code = $stripJs($api) . "\n" . $stripJs($sto);
foreach (['check-in', 'check in', 'checkin', 'poll', 'will connect', 'next contact',
          'router will', 'heartbeat'] as $w) {
    is_(stripos($code, $w) === false, true, "no client CODE says '{$w}'");
}
is_(stripos($stripJs('/* poll */ const x=1;'), 'poll') === false, true,
    'and the stripper really removes comments, rather than the guard passing for free');

t('CLIENT — no delivery or AAA concept leaks into the customer client');
foreach (['DeliveryPort', 'radcheck', 'RouterOs', 'wireguard', 'WireGuard', 'tunnel_ip'] as $n) {
    is_(str_contains($both, $n), false, "the client never references {$n}");
}

exit(t_summary());
