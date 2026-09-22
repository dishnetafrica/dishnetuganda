<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
use Dn\Api\Routes;
use Dn\Auth\Authenticator;
use Dn\Db\Database;
use Dn\Http\Kernel;
use Dn\Http\Request;
use Dn\Http\Serializer\Projection;
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

// ---------------------------------------------------------------------------
t('the PWA renders from real data');
$me = $call('GET', '/api/v1/me', [], $tokA);
is_($me->status, 200, 'GET /me');
is_($me->body['customer']['name'], 'Riverside Hotel', 'customer name');
is_($me->body['principal']['kind'], 'owner', 'principal role');

$sv = $call('GET', '/api/v1/me/services', [], $tokA);
is_(count($sv->body['services']), 1, 'one service');
is_($sv->body['services'][0]['kind'], 'mikrotik_hotspot', 'the MikroTik service');

$si = $call('GET', '/api/v1/me/sites', [], $tokA);
is_(count($si->body['sites']), 1, 'one site');
is_($si->body['sites'][0]['name'], 'Riverside Hotel lobby', 'site name');

$en = $call('GET', '/api/v1/me/entitlements', [], $tokA);
is_($en->body['entitlements'][0]['key'], 'max_routers', 'entitlement is readable');
is_($en->body['entitlements'][0]['int_value'], 2, 'with its value');

// ---------------------------------------------------------------------------
t('CROSS-CUSTOMER — A supplying B\'s ids gets 404, never 403');
$r = $call('GET', '/api/v1/me/sites/' . $B['site'], [], $tokA);
is_($r->status, 404, "A requesting B's site id is 404");
is_($r->body, ['error' => 'not_found'], 'and the body says only not_found');

$absent = '00000000-0000-4000-8000-000000000000';
$r2 = $call('GET', '/api/v1/me/sites/' . $absent, [], $tokA);
is_($r2->status, $r->status, "a nonexistent id gives the SAME status as B's real id");
is_($r2->body, $r->body, 'and the same body — existence is never confirmed');

$mine = $call('GET', '/api/v1/me/sites/' . $A['site'], [], $tokA);
is_($mine->status, 200, "A's own site id still works");

t('CROSS-CUSTOMER — B sees B, and only B');
$si = $call('GET', '/api/v1/me/sites', [], $tokB);
is_(count($si->body['sites']), 1, 'B sees exactly one site');
is_($si->body['sites'][0]['name'], 'Kabale Hostel lobby', "and it is B's");
$me = $call('GET', '/api/v1/me', [], $tokB);
is_($me->body['customer']['name'], 'Kabale Hostel', 'B resolves to B');

t('no 403 is reachable anywhere in the surface');
$statuses = [];
foreach (['/api/v1/me', '/api/v1/me/services', '/api/v1/me/sites',
          '/api/v1/me/entitlements', '/api/v1/me/sites/' . $B['site'],
          '/api/v1/me/sites/not-a-uuid', '/api/v1/nope'] as $p) {
    $statuses[] = $call('GET', $p, [], $tokA)->status;
}
is_(in_array(403, $statuses, true), false, 'no route returns 403');

// ---------------------------------------------------------------------------
t('PROJECTION — responses carry only allowlisted fields');
$checks = [
    ['/api/v1/me',              fn($b) => $b['customer'],       'customer'],
    ['/api/v1/me',              fn($b) => $b['principal'],      'principal'],
    ['/api/v1/me/services',     fn($b) => $b['services'][0],    'service'],
    ['/api/v1/me/sites',        fn($b) => $b['sites'][0],       'site'],
    ['/api/v1/me/entitlements', fn($b) => $b['entitlements'][0],'entitlement'],
];
foreach ($checks as [$path, $extract, $name]) {
    $obj   = $extract($call('GET', $path, [], $tokA)->body);
    $extra = array_diff(array_keys($obj), Projection::fieldsFor($name));
    is_(array_values($extra), [], "{$name}: no field outside the allowlist");
}

t('PROJECTION — named sensitive columns are absent');
$body = json_encode($call('GET', '/api/v1/me', [], $tokA)->body);
foreach (['credential_hash', 'token_hash', 'ucrm_client_id', 'last_login_at'] as $f) {
    is_(str_contains($body, $f), false, "/me does not expose {$f}");
}
$row = $owner->one('SELECT ucrm_client_id FROM mt_customers WHERE id = ?', [$A['customer']]);
is_(str_contains($body, (string) $row['ucrm_client_id']), false,
    'not even the ucrm_client_id VALUE leaks');

t('PROJECTION — a column added later is withheld by default');
// The allowlist must fail closed. A denylist would expose this the moment a
// migration lands and nobody remembers to update it.
$owner->exec('ALTER TABLE mt_sites ADD COLUMN internal_note text');
$owner->exec("UPDATE mt_sites SET internal_note = 'SECRET-OPERATIONAL-VALUE'");
$body = json_encode($call('GET', '/api/v1/me/sites', [], $tokA)->body);
is_(str_contains($body, 'internal_note'), false, 'the new column name does not appear');
is_(str_contains($body, 'SECRET-OPERATIONAL-VALUE'), false, 'and neither does its value');
$owner->exec('ALTER TABLE mt_sites DROP COLUMN internal_note');

t('PROJECTION — the access-point allowlist withholds the docs/45 §4 fields');
// Not wired until step 7; asserted now so the rule is in force before the
// fields exist to leak.
$ap = Projection::fieldsFor('accessPoint');
foreach (['serial','wg_ip','wgIp','endpoint','ros_version','tunnel_ip',
          'desired','actual','prov'] as $f) {
    is_(in_array($f, $ap, true), false, "access-point projection excludes {$f}");
}

// ---------------------------------------------------------------------------
t('GET /me/intents — a customer sees its own queued work and no more');
$qa = new \Dn\Intents\IntentQueue($db);
$ctx = new TenantContext($db);
$ia = (new \Dn\Intents\IntentQueue($owner))
        ->enqueue($A['customer'], 'voucher.create', ['secret_count' => 99], $A['principal']);
$ib = (new \Dn\Intents\IntentQueue($owner))
        ->enqueue($B['customer'], 'voucher.create', [], $B['principal']);

$r = $call('GET', '/api/v1/me/intents', [], $tokA);
is_($r->status, 200, 'the route responds');
is_(count($r->body['intents']), 1, 'A sees exactly one intent');
is_($r->body['intents'][0]['id'], $ia['id'], "and it is A's");
is_($r->body['intents'][0]['state'], 'queued', 'with its state');

$body = json_encode($r->body);
foreach (['payload','secret_count','claimed_by','lease_expires_at','attempts',
          'last_error','next_attempt_at','max_attempts'] as $f) {
    is_(str_contains($body, $f), false, "/me/intents withholds {$f}");
}
$extra = array_diff(array_keys($r->body['intents'][0]), Projection::fieldsFor('intent'));
is_(array_values($extra), [], 'no field outside the intent allowlist');

$rb = $call('GET', '/api/v1/me/intents', [], $tokB);
is_(count($rb->body['intents']), 1, 'B sees exactly one');
is_($rb->body['intents'][0]['id'], $ib['id'], "and it is B's");

t('unauthenticated access to every /me route is refused');
foreach (['/api/v1/me', '/api/v1/me/services', '/api/v1/me/sites',
          '/api/v1/me/entitlements', '/api/v1/me/intents',
          '/api/v1/me/sites/' . $A['site']] as $p) {
    is_($call('GET', $p)->status, 401, "{$p} requires a token");
}

t('an unknown route is 404, not a hint');
$r = $call('GET', '/api/v1/admin/customers', [], $tokA);
is_($r->status, 404, 'an admin route not built yet is simply not found');
is_($r->body, ['error' => 'not_found'], 'with no hint that it might exist later');

exit(t_summary());
