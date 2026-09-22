<?php
declare(strict_types=1);
/**
 * Uplink telemetry.
 *
 * docs/55 step 8, and F13: MEASURE AND INFORM, NEVER GATE.
 *
 * The section that matters is SATURATION CHANGES NOTHING. A structural
 * argument — "no code reads the samples to decide anything" — is worth
 * making, but it is an argument. The behavioural proof is to fill the
 * readings with a link that is flat on its back and show that every customer
 * operation still succeeds, identically.
 */
require __DIR__ . '/bootstrap.php';
use Dn\Api\Routes;
use Dn\Auth\Authenticator;
use Dn\Db\Database;
use Dn\Delivery\RouterOs\RestClient;
use Dn\Devices\DeviceRegistry;
use Dn\Http\Kernel;
use Dn\Http\Request;
use Dn\Jobs\UplinkSampler;
use Dn\Telemetry\UplinkRepository;
use Dn\Tenancy\TenantContext;
use Dn\Vouchers\VoucherService;

putenv('DNB_SECRET_KEY=test-key-for-suite-only');
putenv('DNB_EXPOSE_OTP=1');

$owner = Database::inspector();
$ids = seed_two_customers($owner);
$A = $ids['A']; $B = $ids['B'];
$db   = Database::app();
$auth = new Authenticator($db);
$ctx  = new TenantContext($db);
$k    = new Kernel(Routes::build($auth), $db, $auth, $ctx);
$adminDb = Database::admin();  $ctxA = new TenantContext($adminDb);
$workDb  = Database::worker(); $ctxW = new TenantContext($workDb);
$call = fn(string $m, string $p, array $body = [], string $tok = '')
    => $k->handle(new Request($m, $p, $tok ? ['Authorization' => "Bearer {$tok}"] : [], $body));
$signIn = function (string $phone) use ($call, $owner): string {
    $owner->exec('DELETE FROM mt_auth_codes WHERE phone = ?', [$phone]);
    $c = $call('POST', '/api/v1/auth/request-code', ['phone' => $phone])->body['dev_code'];
    return $call('POST', '/api/v1/auth/verify', ['phone' => $phone, 'code' => $c])->body['token'];
};
$tokA = $signIn('+256700001001');
$tokB = $signIn('+256700001002');

// devices
$devA = $ctxA->runUnscoped(fn($d) => (new DeviceRegistry($d))->register(
    'HGX-T-0001', 'hAP ax2', '7.14.3', 'pk-a', '10.66.0.21', 'tech:t'));
$ctxA->runUnscoped(fn($d) => (new DeviceRegistry($d))->assign($devA['id'], $A['customer'], $A['site'], 'Lobby AP', 'test:staff'));
$ctxA->run($A['customer'], fn($d) => (new DeviceRegistry($d))->setCredentials($devA['id'], 'u', 'p', 'test:staff'));
$ctxA->runUnscoped(fn($d) => (new DeviceRegistry($d))->transition($devA['id'], 'shipped', 'test:staff'));
$ctxA->runUnscoped(fn($d) => (new DeviceRegistry($d))->transition($devA['id'], 'connected', 'test:staff'));
$ctxA->runUnscoped(fn($d) => (new DeviceRegistry($d))->transition($devA['id'], 'provisioned', 'test:staff'));
// R4: which interface carries the uplink is a staging fact, not a constant.
// Deliberately NOT ether1 — the fake below offers an ether1 carrying different
// numbers, so if the sampler ever goes back to guessing, these assertions fail
// instead of passing for the wrong reason.
$ctxA->runUnscoped(fn($d) => (new DeviceRegistry($d))
    ->setWanInterface($devA['id'], 'sfp-sfpplus1', 'tech:t'));

$stock = $ctxA->runUnscoped(fn($d) => (new DeviceRegistry($d))->register(
    'HGX-T-9999', 'hEX S', '7.14.3', 'pk-s', '10.66.0.99', 'tech:t'));

/** A fake router whose throughput the test chooses. */
$rx = 78_000_000; $tx = 12_000_000; $reachable = true;
$factory = function (array $d) use (&$rx, &$tx, &$reachable) {
    $t = function (string $m, string $u, ?array $b, string $usr, string $p) use (&$rx, &$tx, &$reachable) {
        if (!$reachable) { throw new \RuntimeException('router unreachable: timeout'); }
        return ['status' => 200, 'body' => [
            // The decoy. A router where ether1 is NOT the uplink is the whole
            // of finding R4, so the fake is one.
            ['name' => 'ether1',       'rx-bits-per-second' => 999, 'tx-bits-per-second' => 999],
            ['name' => 'bridge',       'rx-bits-per-second' => 5,   'tx-bits-per-second' => 5],
            ['name' => 'sfp-sfpplus1', 'rx-bits-per-second' => $rx, 'tx-bits-per-second' => $tx],
        ]];
    };
    return new RestClient($d['tunnel_ip'], 'u', 'p', 5, \Closure::fromCallable($t));
};

// ===========================================================================
t('sampling records what the link is actually doing');
$out = (new UplinkSampler($workDb, $factory, $ctxW))->runOnce();
is_($out['sampled'], 1, 'one device sampled');
$s = $owner->one('SELECT * FROM mt_uplink_samples ORDER BY at DESC LIMIT 1');
is_((int) $s['rx_bps'], 78_000_000, 'the WAN interface rate is recorded');
is_((int) $s['tx_bps'], 12_000_000, 'in both directions');
is_($s['customer_id'], $A['customer'], 'attributed to the device owner');

t('an unreachable router records NOTHING, not a zero');
// Zero throughput and no measurement are different facts. Recording a zero
// would draw a graph showing an idle link when DishNet simply could not see
// it — and that graph would be read as evidence.
$before = (int) $owner->one('SELECT count(*) AS n FROM mt_uplink_samples')['n'];
$reachable = false;
$out = (new UplinkSampler($workDb, $factory, $ctxW))->runOnce();
$reachable = true;
is_($out['unreachable'], 1, 'counted as unreachable');
is_((int) $owner->one('SELECT count(*) AS n FROM mt_uplink_samples')['n'], $before,
    'and no sample was written');

t('unassigned stock produces no telemetry');
is_($ctxW->runUnscoped(fn($d) => (new UplinkRepository($d))->record($stock['id'], 1, 1, 0)), false,
    'a device belonging to nobody has no one to show a sample to');

// ===========================================================================
t('SATURATION CHANGES NOTHING — the behavioural proof of F13');
// Everything a customer can do, measured on a healthy link, then measured
// again with the readings pinned at a link that is completely saturated.
$exercise = function (string $tok, string $label) use ($call, $ctx, $A) {
    $plan = $call('POST', '/api/v1/me/plans', [
        'name' => "Plan {$label}", 'duration_s' => 86400,
        'rate_down_bps' => 20_000_000, 'rate_up_bps' => 5_000_000,
        'devices_per_voucher' => 4, 'mode' => 'elapsed',
        'price_minor' => 1500000, 'currency' => 'UGX'], $tok);
    $v = $call('POST', '/api/v1/me/vouchers',
        ['plan_id' => $plan->body['plan']['id'] ?? '', 'count' => 5], $tok);
    $code = $v->body['vouchers'][0]['code'] ?? '';
    $redeem = $ctx->runUnscoped(fn($d) => (new VoucherService($d))->redeem($code));
    return [
        'plan_status'    => $plan->status,
        'plan_rate'      => (int) ($plan->body['plan']['rate_down_bps'] ?? 0),
        'plan_price'     => (int) ($plan->body['plan']['price_minor'] ?? 0),
        'voucher_status' => $v->status,
        'voucher_count'  => count($v->body['vouchers'] ?? []),
        'redeemed'       => $redeem !== null,
    ];
};

$healthy = $exercise($tokA, 'healthy');
is_($healthy['plan_status'], 201, 'baseline: a 20 Mbps plan is created');
is_($healthy['voucher_status'], 202, 'baseline: vouchers issue');
is_($healthy['redeemed'], true, 'baseline: a guest can redeem');

// Now pin the uplink at saturation and hammer in samples.
$rx = 950_000_000; $tx = 400_000_000;
for ($i = 0; $i < 40; $i++) {
    $owner->exec('INSERT INTO mt_uplink_samples (device_id, customer_id, at, rx_bps, tx_bps, session_count)
                  VALUES (?,?, now() - (? || \' seconds\')::interval, ?,?,?)',
                 [$devA['id'], $A['customer'], (string) ($i * 30), $rx, $tx, 500]);
}
(new UplinkSampler($workDb, $factory, $ctxW))->runOnce();

$saturated = $exercise($tokA, 'saturated');
is_($saturated['plan_status'],    $healthy['plan_status'],    'a saturated link does not block plan creation');
is_($saturated['plan_rate'],      $healthy['plan_rate'],      'and does not lower the rate the customer chose');
is_($saturated['plan_price'],     $healthy['plan_price'],     'and does not touch their price');
is_($saturated['voucher_status'], $healthy['voucher_status'], 'vouchers still issue');
is_($saturated['voucher_count'],  $healthy['voucher_count'],  'the same number of them');
is_($saturated['redeemed'],       $healthy['redeemed'],       'and a guest can still get online');

t('a customer with a saturated link may still make their plans FASTER');
// The case a throttling design would refuse outright.
$r = $call('POST', '/api/v1/me/plans', [
    'name' => 'VIP under load', 'duration_s' => 86400,
    'rate_down_bps' => 100_000_000, 'rate_up_bps' => 50_000_000,
    'devices_per_voucher' => 8, 'mode' => 'elapsed',
    'price_minor' => 5000000, 'currency' => 'UGX'], $tokA);
is_($r->status, 201, 'a 100 Mbps plan is created while the link reads saturated');

// ===========================================================================
t('STRUCTURAL — nothing that decides anything can see the telemetry');
$root = dirname(__DIR__);
$deciders = [];
foreach (['Policy', 'Vouchers', 'Intents', 'Delivery', 'Auth'] as $dir) {
    foreach (glob("{$root}/src/{$dir}/*.php") as $f) {
        $body = file_get_contents($f);
        foreach (['UplinkRepository', 'mt_uplink_samples', 'Dn\\Telemetry'] as $n) {
            if (str_contains($body, $n)) { $deciders[] = basename($f) . " -> {$n}"; }
        }
    }
}
is_($deciders, [], 'no policy, voucher, intent, delivery or auth file references telemetry');

$repo = file_get_contents($root . '/src/Telemetry/UplinkRepository.php');
foreach (['throw', 'deny', 'refuse', 'exceed', 'throttle', 'shape', 'limit_reached'] as $verb) {
    is_(preg_match('/\b' . $verb . '\s*\(/i', $repo) === 0, true,
        "the repository has no {$verb}() — it cannot refuse anything");
}

// ===========================================================================
t('the customer is shown absolutes, never an invented percentage');
$u = $call('GET', '/api/v1/me/uplink', [], $tokA)->body;
is_(isset($u['uplink']['peak_rx_bps'], $u['uplink']['mean_rx_bps']), true, 'peak and mean are reported');
$json = json_encode($u);
foreach (['utilisation', 'utilization', 'percent', 'capacity', 'allowance', 'limit'] as $w) {
    is_(stripos($json, $w) === false, true, "the response says nothing about {$w}");
}
is_($u['uplink']['peak_rx_bps'] >= 950_000_000, true, 'the peak observed is reported honestly');

t('the route reads stored samples, never a live router');
// A slow or dead router must make this page stale, not make it hang.
$reachable = false;
$r = $call('GET', '/api/v1/me/uplink', [], $tokA);
$reachable = true;
is_($r->status, 200, 'the page still answers with the router unreachable');
is_($r->body['uplink']['samples'] > 0, true, 'from what was already stored');

t('ISOLATION — a customer sees only its own link');
$bUplink = $call('GET', '/api/v1/me/uplink', [], $tokB)->body['uplink'];
is_($bUplink['samples'], 0, 'B has no samples of its own');
is_($bUplink['peak_rx_bps'], 0, "and cannot see A's");
$bRows = $ctx->run($B['customer'], fn($d) => (new UplinkRepository($d))->recent());
is_(count($bRows), 0, "B's direct read returns nothing");

t('samples may be pruned — they are the one thing here that is not evidence');
$n = $ctxW->runUnscoped(fn($d) => $d->one("SELECT mt_uplink_prune('0 seconds'::interval) AS n")['n']);
is_((int) $n > 0, true, 'pruning removes old samples');
is_((int) $owner->one('SELECT count(*) AS n FROM mt_uplink_samples')['n'], 0, 'and they are gone');

t('after pruning, the customer view degrades to empty rather than to a guess');
$u = $call('GET', '/api/v1/me/uplink', [], $tokA)->body['uplink'];
is_($u['samples'], 0, 'no samples');
is_($u['peak_rx_bps'], 0, 'and no invented figure to fill the gap');
is_($u['last_sample_at'], null, 'the last-seen time is simply absent');

exit(t_summary());
