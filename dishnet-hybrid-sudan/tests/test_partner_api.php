<?php
declare(strict_types=1);
/**
 * test_partner_api.php — WS-A P4d (docs/50 §C, docs/47 §10.3, docs/49 §13).
 *
 * The distributor portal's ONE API dispatcher: deny-by-default, strictly
 * separate from the staff API, scope from the session, allow-listed answers.
 * Driven against a real temp SQLite through the pure PartnerApi::handle.
 *
 *   A deny-by-default — undeclared action 404; method + auth guards
 *   B CSRF — a write needs the custom header and a same-site Origin
 *   C the sign-in flow end to end (code → enrol → code+TOTP → session cookie)
 *   D session reads — scoped to the caller's partner; cross-partner id 404; allow-listed
 *   E a staff/foreign token is refused; logout revokes
 *   F wiring — the public.php route and the entry-file gate
 *   G manifest version
 */
$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/TenantProfile.php';
require_once $root . '/lib/PhoneNumber.php';
require_once $root . '/lib/Totp.php';
require_once $root . '/lib/DistributorRegistry.php';
require_once $root . '/lib/DistributorAttribution.php';
require_once $root . '/lib/PartnerContext.php';
require_once $root . '/lib/PartnerSession.php';
require_once $root . '/lib/PartnerAccounts.php';
require_once $root . '/lib/PartnerAuth.php';
require_once $root . '/lib/DistributorPortalData.php';
require_once $root . '/lib/PartnerApi.php';

$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d !== '' ? "\n       $d" : '') . "\n"; } }
function nc(string $f): string { $o=''; foreach (token_get_all((string)file_get_contents($f)) as $k){ if(is_array($k)){ if(in_array($k[0],[T_COMMENT,T_DOC_COMMENT],true)) continue; $o.=$k[1]; } else $o.=$k; } return $o; }

$UG  = TenantProfile::load('uganda');
$cfg = ['webhook_secret' => str_repeat('s', 40)];
$tmp = sys_get_temp_dir() . '/papi_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);
$store = SqliteStore::create($tmp);
$pdo = $store->getPdo();
$reg = DistributorRegistry::fromStore($store);
$att = DistributorAttribution::fromStore($store);
$acct = PartnerAccounts::fromStore($store);
$A = $reg->create(['legal_name' => 'Alpha Distributors Ltd', 'trading_name' => 'Alpha'], 'adm');
$B = $reg->create(['legal_name' => 'Beta Distributors Ltd', 'trading_name' => 'Beta'], 'adm');
$uA = $acct->create((int)$A['id'], ['role' => 'head_office', 'phone' => '+256700000001'], 'adm');
$att->link('ucrm_client', '5001', (int)$A['id'], 'manual', 'adm', 'A customer');
$att->link('lead', '7001', (int)$A['id'], 'manual', 'adm', 'A lead');
$att->link('ucrm_client', '6001', (int)$B['id'], 'manual', 'adm', 'B customer');
$aLinkId = (int)$att->activeLink('ucrm_client', '5001')['id'];
$bLinkId = (int)$att->activeLink('ucrm_client', '6001')['id'];

// Helpers to drive the pure dispatcher.
$H = function (string $action, string $method, array $body = [], array $server = [], array $cookie = [], $deliver = null) use ($pdo, $cfg, $UG) {
    return PartnerApi::handle($pdo, $cfg, $UG, ['action' => $action, 'method' => $method, 'body' => $body, 'server' => $server, 'cookie' => $cookie, 'ip' => '1.2.3.4', 'ua' => 'T'], $deliver);
};
$POSTH = ['HTTP_X_REQUESTED_WITH' => 'DishNet', 'HTTP_SEC_FETCH_SITE' => 'same-origin']; // a well-formed same-site write
$bearer = fn(string $t, array $extra = []) => ['HTTP_AUTHORIZATION' => 'Bearer ' . $t] + $extra;

echo "A. deny-by-default — undeclared action, method, auth\n";
is_($H('nope.anything', 'GET')['code'] === 404, 'an undeclared action is 404 (deny by default)');
is_($H('me.profile', 'GET')['code'] === 401, 'a session action with no token is 401');
is_($H('me.profile', 'POST', [], $POSTH)['code'] === 405, 'a wrong method is 405');
is_($H('api', 'GET')['code'] === 404 && $H('staff.list', 'GET')['code'] === 404, 'staff-style action names are not in the registry (no fall-through)');

echo "\nB. CSRF — a write needs the custom header and a same-site Origin\n";
is_($H('auth.request_code', 'POST', ['phone' => '0700000001'])['code'] === 403, 'a POST without the custom header is 403');
is_($H('auth.request_code', 'POST', ['phone' => '0700000001'], ['HTTP_X_REQUESTED_WITH' => 'DishNet', 'HTTP_SEC_FETCH_SITE' => 'cross-site'])['code'] === 403, 'a cross-site POST is 403');
is_($H('auth.request_code', 'POST', ['phone' => '0700000001'], $POSTH)['code'] === 200, 'a same-site POST with the header is accepted');

echo "\nC. the sign-in flow end to end\n";
$captured = null;
$deliver = function (array $d) use (&$captured) { $captured = $d; };
$H('auth.request_code', 'POST', ['phone' => '0700999999'], $POSTH, [], $deliver); // unknown
is_($captured === null, 'an unknown number delivers nothing (uniform)');
$r = $H('auth.request_code', 'POST', ['phone' => '0700000001'], $POSTH, [], $deliver);
is_($r['code'] === 200 && ($r['body']['status'] ?? '') === 'sent' && is_array($captured) && (int)$captured['user_id'] === (int)$uA['id'], 'a known number: uniform status=sent, code delivered to that account');
$code = (string)$captured['code'];
is_(($H('auth.sign_in', 'POST', ['phone' => '0700000001', 'code' => '000000'], $POSTH)['body']['reason'] ?? '') === 'invalid', 'a wrong code signs in with reason=invalid (401)');
$need = $H('auth.sign_in', 'POST', ['phone' => '0700000001', 'code' => $code], $POSTH);
is_(($need['body']['need'] ?? '') === 'totp_enrol', 'a correct code with no authenticator asks to enrol');
is_($H('auth.enrol_begin', 'POST', ['phone' => '0700000001', 'code' => '000000'], $POSTH)['code'] === 401, 'enrol_begin with a wrong code is refused');
$eb = $H('auth.enrol_begin', 'POST', ['phone' => '0700000001', 'code' => $code], $POSTH);
is_($eb['code'] === 200 && !empty($eb['body']['secret']), 'enrol_begin with the valid code returns a secret');
$secret = (string)$eb['body']['secret'];
$totp = Totp::at($secret, time());
is_(($H('auth.enrol_confirm', 'POST', ['phone' => '0700000001', 'code' => $code, 'totp' => $totp], $POSTH)['body']['ok'] ?? null) === true, 'enrol_confirm accepts the authenticator code');
$si = $H('auth.sign_in', 'POST', ['phone' => '0700000001', 'code' => $code, 'totp' => Totp::at($secret, time())], $POSTH);
is_($si['code'] === 200 && ($si['body']['ok'] ?? null) === true, 'code + TOTP signs in');
is_(is_array($si['cookie']) && ($si['cookie']['action'] ?? '') === 'set' && !empty($si['cookie']['token']), 'sign-in sets the session cookie');
$token = (string)$si['cookie']['token'];

echo "\nD. session reads — scoped, cross-partner id 404, allow-listed\n";
$prof = $H('me.profile', 'GET', [], $bearer($token));
is_($prof['code'] === 200 && ($prof['body']['profile']['trading_name'] ?? '') === 'Alpha', 'me.profile returns the caller\'s own profile');
is_(!array_key_exists('ucrm_client_id', $prof['body']['profile']), 'the profile withholds the billing linkage (allow-listed)');
$cust = $H('me.customers', 'GET', [], $bearer($token));
$ents = array_map(fn($l) => $l['entity_id'], $cust['body']['links']);
is_($cust['code'] === 200 && in_array('5001', $ents, true) && !in_array('6001', $ents, true), 'me.customers is scoped to A — B\'s 6001 is absent');
is_(!array_key_exists('assigned_by', $cust['body']['links'][0]), 'a link row withholds the staff identity (allow-listed)');
is_($H('me.link', 'GET', ['id' => $aLinkId], $bearer($token))['code'] === 200, 'me.link returns A\'s own link');
is_($H('me.link', 'GET', ['id' => $bLinkId], $bearer($token))['code'] === 404, 'me.link for B\'s link id is 404 (cross-partner, indistinguishable from absent)');
is_($H('me.link', 'GET', [], $bearer($token) + ['__query_id' => $aLinkId])['code'] === 200, 'me.link reads the id from the query seam too');

echo "\nE. a staff/foreign token is refused; logout revokes\n";
is_(($H('me.profile', 'GET', [], $bearer(str_repeat('a', 64)))['body']['error'] ?? '') === 'invalid', 'a random/foreign bearer token is refused');
$lo = $H('me.logout', 'POST', [], $bearer($token, $POSTH));
is_($lo['code'] === 200 && ($lo['cookie']['action'] ?? '') === 'clear', 'logout clears the cookie');
is_(($H('me.profile', 'GET', [], $bearer($token))['body']['error'] ?? '') === 'revoked', 'the logged-out token is then refused (revoked)');

echo "\nF. wiring — the public.php route and the entry-file gate\n";
$pub = nc($root . '/public.php');
is_(strpos($pub, "page === 'partner_api'") !== false && strpos($pub, "require __DIR__ . '/partner_api.php'") !== false, 'public.php routes ?page=partner_api to partner_api.php');
$entry = nc($root . '/partner_api.php');
is_(strpos($entry, 'StaffJobsGate::applies') !== false && strpos($entry, "distributors_enabled") !== false, 'the entry gates on Uganda + distributors_enabled');
is_(strpos($entry, "http_response_code(404)") !== false, 'a failed gate answers 404 (the surface does not exist)');
is_(strpos($entry, "'X-Content-Type-Options: nosniff'") !== false, 'the entry sets nosniff');
is_(count(PartnerApi::ACTIONS) === 8, 'the registry declares exactly the 8 pilot actions (deny-by-default surface)');

echo "\nG. manifest version\n";
$mani = json_decode((string)file_get_contents($root . '/manifest.json'), true);
is_(($mani['information']['version'] ?? '') === '5.18.65', 'manifest version is 5.18.65');

exec('rm -rf ' . escapeshellarg($tmp));
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
