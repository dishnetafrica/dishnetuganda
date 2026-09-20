<?php
declare(strict_types=1);
/**
 * The policy plane.
 *
 * docs/55 step 4 exit condition: A CUSTOMER CREATES A PLAN; VALIDITY != CEILING.
 * The first section is that condition; everything else supports it.
 */
require __DIR__ . '/bootstrap.php';
use Dn\Api\Routes;
use Dn\Auth\Authenticator;
use Dn\Db\Database;
use Dn\Http\Kernel;
use Dn\Http\Request;
use Dn\Http\Serializer\Projection;
use Dn\Policy\PlanRepository;
use Dn\Policy\PlanValidator;
use Dn\Policy\RouterOsLimits;
use Dn\Tenancy\TenantContext;

$owner = Database::owner();
$ids = seed_two_customers($owner);
$A = $ids['A']; $B = $ids['B'];
$db   = Database::app();
$auth = new Authenticator($db);
$ctx  = new TenantContext($db);
$k    = new Kernel(Routes::build($auth), $db, $auth, $ctx);
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

$mbps = fn(int $n): int => $n * 1_000_000;
$plan = fn(array $over = []): array => array_merge([
    'name' => 'Day Pass', 'duration_s' => 86400,
    'rate_down_bps' => 10_000_000, 'rate_up_bps' => 3_000_000,
    'devices_per_voucher' => 2, 'mode' => 'elapsed',
    'price_minor' => 800000, 'currency' => 'UGX',
], $over);

// ===========================================================================
t('F9 — DishNet sold no bandwidth, so no rate is refused as "too much"');
// The headline. Riverside is behind its own Starlink. A 20 Mbps VIP plan is
// their commercial decision and the platform accepts it.
foreach ([5, 8, 10, 20, 100, 1000] as $m) {
    $r = $call('POST', '/api/v1/me/plans',
        $plan(['name' => "Plan {$m}M", 'rate_down_bps' => $mbps($m)]), $tokA);
    is_($r->status, 201, "a {$m} Mbps plan is created");
}

t('F9 — the customer sets the price, including free and including a lot');
foreach ([0, 1, 800000, 999999999999] as $p) {
    $r = $call('POST', '/api/v1/me/plans',
        $plan(['name' => "Price {$p}", 'price_minor' => $p]), $tokA);
    is_($r->status, 201, "price {$p} is accepted");
}

t('F9 — no validation message ever speaks of buying, allowance or permission');
// If a ceiling ever creeps back in, it will announce itself in the wording
// before it shows up anywhere else.
$v = new PlanValidator();
// The bound the validator applies when nothing has been measured — which is
// every model today. Naming it here rather than repeating a literal keeps the
// test honest about WHAT it is asserting: that the validator enforces the
// bound it was given, not that the bound is correct. Nothing in this suite can
// establish the latter; only a physical unit can (docs/57 R2, R3).
$prov = RouterOsLimits::unverified();
$all = [];
foreach ([
    [], ['duration_s' => 0], ['rate_down_bps' => -1], ['devices_per_voucher' => 0],
    ['price_minor' => -5], ['currency' => 'pounds'], ['mode' => 'whatever'],
    ['name' => ''], ['rate_up_bps' => $prov->maxRateBps + 1],
    ['duration_s' => PlanValidator::MAX_SESSION_S + 1],
] as $bad) {
    $all = array_merge($all, $v->check($plan($bad)));
}
$forbidden = [];
foreach ($all as $msg) {
    foreach (['bought','buy','purchase','entitle','allowance you','your plan allows',
              'not permitted','upgrade','quota','subscription'] as $w) {
        if (stripos($msg, $w) !== false) { $forbidden[] = "{$w}: {$msg}"; }
    }
}
is_($forbidden, [], 'no message implies a commercial limit');
is_(count($all) > 5, true, 'and there really were messages to check (' . count($all) . ')');

// ===========================================================================
t('VALIDITY — what the protocol cannot carry is refused, and says why');
$cases = [
    ['rate_down_bps' => $prov->maxRateBps + 1, 'expect' => 'rate limit attribute'],
    ['duration_s'    => PlanValidator::MAX_SESSION_S + 1, 'expect' => 'session timeout attribute'],
    ['devices_per_voucher' => $prov->maxSharedUsers + 1, 'expect' => 'router can track'],
];
foreach ($cases as $c) {
    $expect = $c['expect']; unset($c['expect']);
    $r = $call('POST', '/api/v1/me/plans', $plan(['name' => 'X' . uniqid()] + $c), $tokA);
    is_($r->status, 422, 'rejected as unprocessable');
    $joined = implode(' | ', $r->body['reasons'] ?? []);
    is_(str_contains($joined, $expect), true, "and the reason names the protocol: {$expect}");
}

t('VALIDITY — nonsense values are refused');
foreach ([['duration_s' => 0], ['rate_down_bps' => 0], ['devices_per_voucher' => 0],
          ['price_minor' => -1], ['currency' => 'UG'], ['mode' => 'nope'], ['name' => '']] as $bad) {
    $r = $call('POST', '/api/v1/me/plans', $plan($bad + ['name' => 'Y' . uniqid()]), $tokA);
    is_($r->status, 422, 'refused: ' . json_encode($bad));
}

// ===========================================================================
t('PROFILES — derived, deduplicated, and never named to a customer');
clear_table($owner, 'mt_plans'); clear_table($owner, 'mt_profiles');
$p1 = $call('POST', '/api/v1/me/plans', $plan(['name' => 'Same shape 1']), $tokA)->body['plan'];
$p2 = $call('POST', '/api/v1/me/plans', $plan(['name' => 'Same shape 2']), $tokA)->body['plan'];
$nProfiles = (int) $owner->one('SELECT count(*) AS n FROM mt_profiles')['n'];
is_($nProfiles, 1, 'two plans of the same technical shape share one profile');

$p3 = $call('POST', '/api/v1/me/plans',
    $plan(['name' => 'Different', 'rate_down_bps' => $mbps(50)]), $tokA)->body['plan'];
is_((int) $owner->one('SELECT count(*) AS n FROM mt_profiles')['n'], 2, 'a different shape makes a second');

t('PROFILES — two customers sharing a profile cannot observe each other');
$pb = $call('POST', '/api/v1/me/plans', $plan(['name' => 'B same shape']), $tokB)->body['plan'];
is_((int) $owner->one('SELECT count(*) AS n FROM mt_profiles')['n'], 2,
    "B's identical plan reuses the existing profile rather than making a third");
$body = json_encode([$p1, $pb]);
is_(str_contains($body, 'profile_id'), false, 'no response carries a profile id');
$extra = array_diff(array_keys($p1), Projection::fieldsFor('plan'));
is_(array_values($extra), [], 'no field outside the plan allowlist');

t('PROFILES — the app role cannot enumerate other shapes it did not create');
// mt_profiles has no customer column, so the protection is that nothing
// exposes it. Assert no route returns one.
$paths = ['/api/v1/me/plans', '/api/v1/me', '/api/v1/me/services', '/api/v1/me/sites'];
$leak = false;
foreach ($paths as $path) {
    if (str_contains(json_encode($call('GET', $path, [], $tokA)->body), 'profile')) { $leak = true; }
}
is_($leak, false, 'no customer route mentions profiles at all');

// ===========================================================================
t('ISOLATION — plans do not cross customers');
$listA = $call('GET', '/api/v1/me/plans', [], $tokA)->body['plans'];
$listB = $call('GET', '/api/v1/me/plans', [], $tokB)->body['plans'];
is_(count($listB), 1, 'B sees only its own plan');
$idsA = array_column($listA, 'id');
is_(in_array($pb['id'], $idsA, true), false, "B's plan is absent from A's list");

$r = $call('PATCH', '/api/v1/me/plans/' . $pb['id'], ['price_minor' => 1], $tokA);
is_($r->status, 404, "A cannot PATCH B's plan");
$unchanged = $owner->one('SELECT price_minor FROM mt_plans WHERE id = ?', [$pb['id']]);
is_((int) $unchanged['price_minor'], 800000, "and B's price is genuinely unchanged");
is_($call('POST', '/api/v1/me/plans/' . $pb['id'] . '/retire', [], $tokA)->status, 404,
    "A cannot retire B's plan");

t('ISOLATION — two customers may use the same plan name');
// Uniqueness is per customer. A global unique index would leak the existence
// of another customer's plan through a 409.
$r = $call('POST', '/api/v1/me/plans', $plan(['name' => 'Same shape 1']), $tokB);
is_($r->status, 201, "B may name a plan what A already named one");

t('a customer cannot reuse its OWN plan name');
$r = $call('POST', '/api/v1/me/plans', $plan(['name' => 'Same shape 1']), $tokB);
is_($r->status, 409, 'a duplicate name for the same customer is a conflict');

// ===========================================================================
t('UPDATE — changing what is sold moves enforcement with it');
$before = $owner->one('SELECT profile_id FROM mt_plans WHERE id = ?', [$p1['id']]);
$r = $call('PATCH', '/api/v1/me/plans/' . $p1['id'], ['rate_down_bps' => $mbps(77)], $tokA);
is_($r->status, 200, 'the plan updates');
is_((int) $r->body['plan']['rate_down_bps'], $mbps(77), 'the new rate is stored');
$after = $owner->one('SELECT profile_id FROM mt_plans WHERE id = ?', [$p1['id']]);
is_($before['profile_id'] !== $after['profile_id'], true, 'and it now points at a different profile');

t('UPDATE — a change that would be unexpressible is refused');
$r = $call('PATCH', '/api/v1/me/plans/' . $p1['id'],
    ['duration_s' => PlanValidator::MAX_SESSION_S + 1], $tokA);
is_($r->status, 422, 'refused');
$still = $owner->one('SELECT duration_s FROM mt_plans WHERE id = ?', [$p1['id']]);
is_((int) $still['duration_s'], 86400, 'and the plan is untouched');

// ===========================================================================
t('RETIRE, never delete — a plan is a revenue record');
$r = $call('POST', '/api/v1/me/plans/' . $p3['id'] . '/retire', [], $tokA);
is_($r->status, 200, 'retire succeeds');
is_($r->body['plan']['active'], false, 'and the plan is inactive');
is_($owner->one('SELECT id FROM mt_plans WHERE id = ?', [$p3['id']]) !== null, true,
    'the row still exists');

throws_(fn() => $ctx->run($A['customer'], fn($d) => $d->exec(
    'DELETE FROM mt_plans WHERE id = ?', [$p3['id']])),
    'retired, not deleted', 'the database refuses a DELETE outright');

t('a retired plan still appears, marked inactive');
$list = $call('GET', '/api/v1/me/plans', [], $tokA)->body['plans'];
$found = array_values(array_filter($list, fn($x) => $x['id'] === $p3['id']));
is_(count($found), 1, 'it is still listed');
is_($found[0]['active'], false, 'as inactive');

t('audit records who did what');
$rows = $owner->query("SELECT action FROM mt_audit_log WHERE action LIKE 'plan.%' ORDER BY at");
$actions = array_unique(array_column($rows, 'action'));
sort($actions);
is_($actions, ['plan.created', 'plan.retired', 'plan.updated'], 'all three are audited');

exit(t_summary());
