<?php
declare(strict_types=1);
/**
 * Audit findings R4 (WAN interface) and R2/R3 (RouterOS limits) — docs/57 §1.
 *
 * R4 is the dangerous kind of wrong: it produced numbers. The assertions below
 * are built so that a return to guessing FAILS them, rather than passing for
 * the reason the original test passed — that test fed a fake an interface
 * named ether1 and then checked the code found ether1.
 */
require __DIR__ . '/bootstrap.php';
use Dn\Db\Database;
use Dn\Delivery\RouterOs\RestClient;
use Dn\Devices\DeviceRegistry;
use Dn\Jobs\UplinkSampler;
use Dn\Policy\PlanValidator;
use Dn\Policy\RouterOsLimitBook;
use Dn\Policy\RouterOsLimits;
use Dn\Tenancy\TenantContext;

putenv('DNB_SECRET_KEY=test-key-for-suite-only');

$owner = Database::owner();
$app   = Database::app();     $ctx  = new TenantContext($app);
$workDb = Database::worker(); $ctxW = new TenantContext($workDb);
$adminDb = Database::admin(); $ctxA = new TenantContext($adminDb);

$ids = seed_two_customers($owner);
$A = $ids['A'];

$mk = function (string $serial, string $ip) use ($ctxA, $A): array {
    $d = $ctxA->runUnscoped(fn($x) => (new DeviceRegistry($x))->register(
        $serial, 'hAP ax2', '7.14.3', 'pk-' . $serial, $ip, 'tech:t'));
    $ctxA->runUnscoped(fn($x) => (new DeviceRegistry($x))->assign($d['id'], $A['customer'], $A['site'], $serial));
    $ctxA->run($A['customer'], fn($x) => (new DeviceRegistry($x))->setCredentials($d['id'], 'u', 'p'));
    foreach (['shipped', 'connected', 'provisioned'] as $st) {
        $ctxA->runUnscoped(fn($x) => (new DeviceRegistry($x))->transition($d['id'], $st));
    }
    return $d;
};

/** A router offering a juicy ether1 that is NOT the uplink. */
$interfaces = [
    ['name' => 'ether1',       'rx-bits-per-second' => 500_000_000, 'tx-bits-per-second' => 400_000_000],
    ['name' => 'bridge-lan',   'rx-bits-per-second' => 900_000_000, 'tx-bits-per-second' => 900_000_000],
    ['name' => 'sfp-sfpplus1', 'rx-bits-per-second' => 42_000_000,  'tx-bits-per-second' => 7_000_000],
];
$factory = function (array $d) use (&$interfaces) {
    $t = fn(string $m, string $u, ?array $b, string $usr, string $p)
        => ['status' => 200, 'body' => $interfaces];
    return new RestClient($d['tunnel_ip'], 'u', 'p', 5, \Closure::fromCallable($t));
};
$samples = fn(string $id) => (int) $owner->one(
    'SELECT count(*) AS n FROM mt_uplink_samples WHERE device_id = ?', [$id])['n'];

// ===========================================================================
t('R4 ADVERSARIAL — a device with no established WAN cannot produce WAN telemetry');
// The attack this test exists for: a router that offers a perfectly plausible
// ether1. Before the fix the sampler took it and stored 500/400 Mbps as this
// customer's uplink. Nothing errored, and the graph looked convincing.
$unset = $mk('WAN-UNSET-1', '10.70.0.11');
is_($owner->one('SELECT wan_interface FROM mt_devices WHERE id = ?', [$unset['id']])['wan_interface'],
    null, 'the device has no WAN interface established');

$out = (new UplinkSampler($workDb, $factory, $ctxW))->runOnce();
is_($samples($unset['id']), 0, 'NO sample was written for it');
is_($out['no_wan'] >= 1, true, 'and it is counted as unmeasurable, not as sampled');
is_($out['sampled'], 0, 'nothing at all was sampled');

// The specific lie the old code told, asserted directly.
$any = $owner->query('SELECT rx_bps, tx_bps FROM mt_uplink_samples');
is_(in_array(500_000_000, array_map(fn($r) => (int) $r['rx_bps'], $any), true), false,
    "ether1's rate appears nowhere in the samples table");

t('R4 — no measurement is not a zero');
is_((int) $owner->one('SELECT count(*) AS n FROM mt_uplink_samples
                        WHERE rx_bps = 0 AND tx_bps = 0')['n'],
    0, 'a zero row was not written in place of the missing measurement');

t('R4 — once established, the sampler follows the FACT, not the name ether1');
$ctxA->runUnscoped(fn($x) => (new DeviceRegistry($x))
    ->setWanInterface($unset['id'], 'sfp-sfpplus1', 'tech:field'));
$out = (new UplinkSampler($workDb, $factory, $ctxW))->runOnce();
is_($out['sampled'], 1, 'now it samples');
$s = $owner->one('SELECT * FROM mt_uplink_samples WHERE device_id = ? ORDER BY at DESC LIMIT 1',
    [$unset['id']]);
is_((int) $s['rx_bps'], 42_000_000, 'the ESTABLISHED interface rate is stored');
is_((int) $s['tx_bps'], 7_000_000,  'in both directions');
is_((int) $s['rx_bps'] === 500_000_000, false, 'and emphatically not ether1');

t('R4 — an established interface the device no longer reports records nothing');
// A rename on the device, or a re-cable. Substituting another interface here
// would be the original bug with an extra step; the right answer is a gap.
$before = $samples($unset['id']);
$interfaces = [
    ['name' => 'ether1',     'rx-bits-per-second' => 500_000_000, 'tx-bits-per-second' => 400_000_000],
    ['name' => 'bridge-lan', 'rx-bits-per-second' => 900_000_000, 'tx-bits-per-second' => 900_000_000],
];
$out = (new UplinkSampler($workDb, $factory, $ctxW))->runOnce();
is_($out['wan_absent'], 1, 'counted as wan_absent — a device that changed under us');
is_($samples($unset['id']), $before, 'and no sample was written');
is_($out['sampled'], 0, 'nothing fell back to ether1 or to the busiest interface');

t('R4 — an interface present but reporting no rate records nothing either');
// R7 is unverified: whether a real unit returns these keys at all is unknown.
// Defaulting a missing key to 0 would store "the link was idle".
$before = $samples($unset['id']);
$interfaces = [['name' => 'sfp-sfpplus1', 'running' => 'true']];   // no rate keys
$out = (new UplinkSampler($workDb, $factory, $ctxW))->runOnce();
is_($out['wan_absent'], 1, 'no usable measurement');
is_($samples($unset['id']), $before, 'and still no row');

t('R4 — the fact carries its provenance, and cannot be set without one');
$row = $owner->one('SELECT * FROM mt_devices WHERE id = ?', [$unset['id']]);
is_($row['wan_interface_set_by'], 'tech:field', 'who established it is recorded');
is_($row['wan_interface_set_at'] !== null, true, 'and when');
foreach ([['', 'tech'], ['   ', 'tech'], ['ether1', ''], ['ether1', null]] as [$iface, $by]) {
    throws_(fn() => $ctxA->runUnscoped(fn($x) => $x->one(
        'SELECT * FROM mt_device_set_wan(?,?,?)', [$unset['id'], $iface, $by])),
        '', 'refused: interface=' . var_export($iface, true) . ' by=' . var_export($by, true));
}
// The database refuses a WAN with no author even if something bypasses the function.
throws_(fn() => $owner->exec(
    "UPDATE mt_devices SET wan_interface = 'ether9', wan_interface_set_by = NULL,
            wan_interface_set_at = NULL WHERE id = '{$unset['id']}'"),
    'mt_devices_wan_provenance', 'and the CHECK constraint refuses it directly');

t('R4 — establishing the WAN is an admin operation, not a customer one');
throws_(fn() => $ctx->run($A['customer'], fn($db) => $db->one(
    'SELECT * FROM mt_device_set_wan(?,?,?)', [$unset['id'], 'ether1', 'attacker'])),
    'permission denied',
    'the request role cannot re-point telemetry at an interface of its choosing');

t('R4 — a missing WAN does not touch enforcement or service state');
// Rule 5 of the remediation brief. An unmeasurable device is a reporting gap,
// never a reason to change what the customer is allowed to do.
$stateBefore = $owner->one('SELECT state FROM mt_devices WHERE id = ?', [$unset['id']])['state'];
$noWan = $mk('WAN-UNSET-2', '10.70.0.12');
(new UplinkSampler($workDb, $factory, $ctxW))->runOnce();
is_($owner->one('SELECT state FROM mt_devices WHERE id = ?', [$noWan['id']])['state'],
    $stateBefore, 'the device state is untouched by being unmeasurable');
is_((int) $owner->one('SELECT count(*) AS n FROM mt_intents')['n'], 0,
    'and no intent was queued off the back of it');

// ===========================================================================
t('R2/R3 — the rate and shared-user ceilings are no longer stated as facts');
is_(defined('Dn\\Policy\\PlanValidator::MAX_RATE_BPS'), false,
    'MAX_RATE_BPS is gone — a const was the wrong shape for an unmeasured number');
is_(defined('Dn\\Policy\\PlanValidator::MAX_DEVICES'), false, 'so is MAX_DEVICES');
is_(defined('Dn\\Policy\\PlanValidator::MAX_SESSION_S'), true,
    'MAX_SESSION_S stays a const — RFC 2865 is a protocol fact, not a device fact');
is_(defined('Dn\\Policy\\PlanValidator::MAX_DATA_BYTES'), true, 'as does MAX_DATA_BYTES');

t('R2/R3 — nothing ships claiming to be verified');
$book = new RouterOsLimitBook();
is_($book->entries(), [], 'the shipped limit book is empty — no model has been measured');
is_($book->for()->verified, false, 'the fallback announces itself unverified');
is_($book->for('hAP ax2', '7.14.3')->verified, false, 'and so does a lookup for a real model');
is_(str_contains($book->for()->provenance, 'never measured'),
    true, 'its provenance says so in words');

t('R2/R3 — the validator enforces the limits it is GIVEN');
// The point of parameterising: a different device produces a different answer,
// which a const could never express.
$tight = new RouterOsLimits(1_000_000, 4, 'test fixture', true);
$v     = new PlanValidator($tight);
$plan  = ['name' => 'p', 'duration_s' => 3600, 'rate_down_bps' => 2_000_000,
          'rate_up_bps' => 500_000, 'devices_per_voucher' => 8, 'mode' => 'elapsed',
          'price_minor' => 1000, 'currency' => 'UGX'];
$e = $v->check($plan);
is_(count(array_filter($e, fn($m) => str_contains($m, 'download rate'))), 1,
    'a rate above the given limit is refused');
is_(count(array_filter($e, fn($m) => str_contains($m, 'devices per voucher'))), 1,
    'as are too many shared users');

$loose = new PlanValidator(new RouterOsLimits(10_000_000, 16, 'test fixture', true));
is_($loose->check($plan), [], 'and the same plan is fine on a device that allows it');

t('R2/R3 — an unverified refusal says that it is unverified');
$prov = new PlanValidator();     // no limits given: the provisional bound
$tooBig = $plan;
$tooBig['rate_down_bps'] = RouterOsLimits::unverified()->maxRateBps + 1;
$msg = implode(' | ', $prov->check($tooBig));
is_(str_contains($msg, 'never measured'), true,
    'the message names the provenance, so nobody mistakes a guard rail for a device limit');

t('R2/R3 — the provisional bound cannot act as a commercial ceiling (F8/F9)');
// DishNet does not ration the customer's uplink. A bound low enough to bite a
// real hotspot plan would do exactly that, quietly.
$u = RouterOsLimits::unverified();
is_($u->maxRateBps >= 4_000_000_000, true, 'the rate bound is far above any hotspot plan');
is_($u->maxSharedUsers >= 65535, true, 'as is the shared-user bound');
$normal = ['name' => 'Day pass', 'duration_s' => 86400, 'rate_down_bps' => 20_000_000,
           'rate_up_bps' => 5_000_000, 'devices_per_voucher' => 3, 'mode' => 'elapsed',
           'price_minor' => 2000, 'currency' => 'UGX'];
is_((new PlanValidator())->check($normal), [], 'an ordinary 20 Mbps plan passes untouched');

exit(t_summary());
