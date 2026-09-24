<?php
/**
 * G-C — the MikroTik router control-plane boundary (docs/118).
 *
 * What is proved here, and what is not.
 *
 * PROVED, by execution against tests/fake_routeros.php over real HTTP and
 * against the database: adapter selection and the F6-B gate; that the
 * destination of a delivery is derived from the device record and never from
 * a request; the lifecycle gate; the identity check; cross-tenant isolation;
 * the intent lifecycle, idempotency and lease; success, failure, timeout and
 * malformed-response handling; that no credential, address or key leaves the
 * boundary; the audit of every act and the silence of every refusal; that the
 * simulated router says it is one and moves nothing in the registry; and the
 * two Admin-plane router writes with the staff subject as actor.
 *
 * NOT PROVED, and not claimed: anything about a MikroTik. The fake speaks the
 * REST shape (docs/30 Artifact 13 rule 2: "a fake MikroTik would pass while
 * the real one rejects the command"). Every hardware-dependent assumption is
 * labelled in docs/118 §C and none is HARDWARE VERIFIED. B1 (push vs poll)
 * is decided nowhere in this file.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/admin_identity_double.php';

use Dn\Admin\RouterAdmin;
use Dn\Admin\RouterRefused;
use Dn\Admin\StaffIdentity;
use Dn\Admin\StaffRole;
use Dn\Api\AdminRoutes;
use Dn\Api\Routes;
use Dn\Auth\Authenticator;
use Dn\Db\Database;
use Dn\Delivery\DeliveryPort;
use Dn\Delivery\DeliveryTarget;
use Dn\Delivery\RouterOs\RestClient;
use Dn\Delivery\RouterOsDelivery;
use Dn\Delivery\SimulatedRouterOs;
use Dn\Devices\DeviceRegistry;
use Dn\Devices\TunnelAddress;
use Dn\Http\Request;
use Dn\Http\Router;
use Dn\Http\Serializer\AdminProjection;
use Dn\Intents\IntentQueue;
use Dn\Jobs\IntentWorker;
use Dn\Network\SignalReport;
use Dn\Runtime\Bindings;
use Dn\Tenancy\TenantContext;

putenv('DNB_SECRET_KEY=test-key-for-suite-only');

$ins    = Database::inspector();       // BYPASSRLS fixture identity: reads state, proves nothing
$aw     = Database::adminWrite();      // the Admin write identity the routes use
$app    = Database::app();
$workDb = Database::worker();
$ctxW   = new TenantContext($workDb);
$ids    = seed_two_customers($ins);
$A = $ids['A']; $B = $ids['B'];
$ABSENT = '00000000-0000-4000-8000-000000000000';

// ── two fake routers over real HTTP: one that answers, one that sleeps ─────
$fakeId  = 'gc-' . getmypid();
$port    = 59400 + (getmypid() % 300);
$portSlow = $port + 300;
$startFake = static function (int $p, string $id, array $env): array {
    @unlink(sys_get_temp_dir() . "/fake-ros-{$id}.json");
    $log = tempnam(sys_get_temp_dir(), 'fakeros');
    $envs = '';
    foreach ($env + ['FAKE_ROS_ID' => $id] as $k => $v) { $envs .= $k . '=' . escapeshellarg((string) $v) . ' '; }
    $pid = (int) shell_exec(sprintf('%s php -S 127.0.0.1:%d %s > %s 2>&1 & echo $!',
        $envs, $p, escapeshellarg(__DIR__ . '/fake_routeros.php'), $log));
    for ($i = 0; $i < 100; $i++) {
        $s = @fsockopen('127.0.0.1', $p, $e1, $e2, 0.2);
        if ($s) { fclose($s); break; }
        usleep(50_000);
    }
    return [$pid, $log];
};
[$pid, $log]   = $startFake($port, $fakeId, []);
[$pidS, $logS] = $startFake($portSlow, $fakeId . '-slow', ['FAKE_ROS_SLOW' => 3]);
register_shutdown_function(static function () use ($pid, $pidS, $log, $logS, $fakeId) {
    foreach ([$pid, $pidS] as $p) { if ($p > 0) { @shell_exec("kill {$p} 2>/dev/null"); } }
    @unlink($log); @unlink($logS);
    @unlink(sys_get_temp_dir() . "/fake-ros-{$fakeId}.json");
    @unlink(sys_get_temp_dir() . "/fake-ros-{$fakeId}-slow.json");
});
$resetFake = static fn() => @unlink(sys_get_temp_dir() . "/fake-ros-{$fakeId}.json");

/** Every call the adapter makes, as "METHOD host path" — the evidence that a refusal opened nothing. */
$calls = new \ArrayObject();
$makeTransport = static function (int $p, int $timeout) use ($calls): \Closure {
    return function (string $method, string $url, ?array $body, string $u, string $pw) use ($p, $timeout, $calls): array {
        $host = parse_url($url, PHP_URL_HOST);
        $path = preg_replace('#^https://[^/]+/rest/#', '', $url);
        $calls[] = "{$method} {$host} {$path}";
        $ch = curl_init("http://127.0.0.1:{$p}/rest/{$path}");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_USERPWD => "{$u}:{$pw}", CURLOPT_HTTPAUTH => CURLAUTH_BASIC, CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
        if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
        $raw = curl_exec($ch);
        $st  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) { throw new \RuntimeException('router unreachable: ' . $err); }
        return ['status' => $st, 'raw' => (string) $raw];   // raw: the client decodes and flags malformed itself
    };
};
$transport = $makeTransport($port, 5);
$factoryWith = static fn(\Closure $t) => static fn(array $d) => new RestClient($d['tunnel_ip'], 'dn-mgmt', 'correct-horse', 10, $t);
$factory  = $factoryWith($transport);
$delivery = new RouterOsDelivery($factory);
/** A transport that answers one path differently and delegates the rest. */
$override = static function (\Closure $inner, string $method, string $pathSuffix, array $answer): \Closure {
    return static function (string $m, string $url, ?array $b, string $u, string $p) use ($inner, $method, $pathSuffix, $answer) {
        if ($m === $method && str_ends_with((string) parse_url($url, PHP_URL_PATH), $pathSuffix)) {
            return $answer;
        }
        return $inner($m, $url, $b, $u, $p);
    };
};

$enqueue = static fn(string $customer, string $kind, array $payload, ?string $device = null, ?string $key = null): array =>
    (new IntentQueue($ins))->enqueue($customer, $kind, $payload, null, $device ? 'device' : null, $device, $key);
$run    = static fn(DeliveryPort $d, string $worker = 'w-gc:routeros'): array =>
    (new IntentWorker($workDb, $ctxW, new IntentQueue($workDb), $d, $worker))->runOnce();
$intent = static fn(string $id): array => $ins->one('SELECT * FROM mt_intents WHERE id = ?', [$id]);
$device = static fn(string $id): array => $ins->one('SELECT * FROM mt_devices WHERE id = ?', [$id]);
$audit  = static fn(): int => (int) $ins->one('SELECT count(*)::int AS c FROM mt_audit_log')['c'];
$lastAudit = static fn(string $action): ?array => $ins->one(
    'SELECT * FROM mt_audit_log WHERE action = ? ORDER BY at DESC, id DESC LIMIT 1', [$action]);
/** A retryable intent left queued would be re-claimed after its backoff; park it so later sections count only their own work. */
$park = static function (string $id) use ($ins): void {
    $ins->exec("UPDATE mt_intents SET state = 'failed', failed_at = now(), last_error = coalesce(last_error, '') || ' [parked by test]' WHERE id = ? AND state = 'queued'", [$id]);
};
$wgKey = static fn(): string => rtrim(base64_encode(random_bytes(32)), '=') . '=';
$secretNeedles = ['correct-horse', 'dn-mgmt', '10.66.0.', 'https://', '127.0.0.1'];

// ── the estate: registered by the Admin façade, exactly as the routes do ──
$ra  = RouterAdmin::on($aw);
$reg = new DeviceRegistry($aw);
$key1 = $wgKey();
$dev1 = $ra->register('HGX8842011', 'hAP ax2', '7.14.3', $key1, '10.66.0.11', 'noc-user');
$reg->setCredentials($dev1['id'], 'dn-mgmt', 'correct-horse', 'noc-user');
$ra->assign($dev1['id'], $A['customer'], $A['site'], 'Lobby AP', 'noc-user');
$reg->transition($dev1['id'], 'connected', 'noc-user');
$reg->setDesired($dev1['id'], ['ip/hotspot/profile' => ['use-radius' => 'yes']], 'noc-user');

// ===========================================================================
t('1. ADAPTER SELECTION — three bindings, chosen explicitly, never by fallback');
$d0 = Bindings::defaults();
is_([$d0->delivery()->bindingName(), $d0->delivery()->isSimulated()], ['null', true],
    'defaults(): the null delivery — not a router, and it says so');
is_([$d0->describe()['delivery_binding'], $d0->describe()['delivery_simulated'], $d0->describe()['phase']],
    ['null', true, 'F6-A'], '/health reports the binding, its simulated flag and the phase');
putenv('DN_DELIVERY');
is_(Bindings::fromEnvironment()->delivery()->bindingName(), 'null', 'DN_DELIVERY unset → the null delivery');
putenv('DN_DELIVERY=simulated');
$simD = Bindings::fromEnvironment()->delivery();
is_([$simD->bindingName(), $simD->isSimulated(), $simD instanceof SimulatedRouterOs], ['simulated-routeros', true, true],
    'DN_DELIVERY=simulated → the in-memory router, flagged simulated');
putenv('DN_DELIVERY=routeros');
throws_(fn() => Bindings::fromEnvironment(), 'not authorized', 'DN_DELIVERY=routeros WITHOUT the F6-B gate throws');
throws_(fn() => Bindings::fromEnvironment(), Bindings::REAL_GATE_ENV, 'and names the variable that would open it');
putenv('DN_DELIVERY=magic');
throws_(fn() => Bindings::fromEnvironment(), 'not a delivery binding', 'an unknown mode throws — there is no fallback to anything');
// The gate, opened deliberately and from the constants (the G-B lesson: a
// gate asserted against a guessed value proves nothing).
putenv(Bindings::REAL_GATE_ENV . '=' . Bindings::REAL_GATE_VALUE);
is_(Bindings::realBindingsAllowed(), true, 'CONTROL: the gate is genuinely open');
putenv('DN_DELIVERY=routeros');
$realD = Bindings::fromEnvironment()->delivery();
is_([$realD->bindingName(), $realD->isSimulated()], ['routeros', false],
    'routeros WITH the gate constructs the real adapter (construction opens no connection)');
is_(Bindings::fromEnvironment()->describe()['phase'], 'F6-B', 'and the process would report F6-B');
putenv('DN_DELIVERY=simulated');
throws_(fn() => Bindings::fromEnvironment(), 'refuses to run in a process authorized', 'a simulator refuses to exist in a gated process');
throws_(fn() => new SimulatedRouterOs(), 'refuses', 'even when constructed directly');
putenv(Bindings::REAL_GATE_ENV); putenv('DN_DELIVERY');
is_(Bindings::realBindingsAllowed(), false, 'the gate is closed again for the rest of this file');
is_(Bindings::DELIVERY_MODES, ['null', 'simulated', 'routeros'], 'exactly three modes exist');

t('1b. THE GATE IS AT THE SOCKET — no path opens a connection to a router without F6-B');
$bare = new RestClient('10.66.0.11', 'dn-mgmt', 'correct-horse');      // real transport, tunnel host: constructs
throws_(fn() => $bare->identity(), 'F6-B binding and is not authorized', 'a real-transport client refuses to call out');
throws_(fn() => $bare->resource(), Bindings::REAL_GATE_ENV, 'and names the gate');
throws_(fn() => new RestClient('192.0.2.10', 'u', 'p'), 'tunnel', 'CONTROL: the tunnel-host rule still refuses a public address first');
$before = $audit(); $n = count($calls);
$i = $enqueue($A['customer'], 'device.provision', ['device_id' => $dev1['id']], $dev1['id']);
$out = $run(new RouterOsDelivery());                                    // no client factory = the real path
is_($out['retrying'], 1, 'the real adapter without the gate: the intent is left to retry, not delivered, not confirmed');
$row = $intent($i['id']);
is_($row['state'], 'queued', 'it stays queued');
is_(str_contains((string) $row['last_error'], 'not authorized') && str_contains((string) $row['last_error'], 'F6-B'), true,
    'and last_error says the F6-B binding is not authorized');
foreach ($secretNeedles as $s) { is_(str_contains((string) $row['last_error'], $s), false, "last_error carries no '{$s}'"); }
is_(count($calls) - $n, 0, 'no request was made anywhere');
is_($audit() - $before, 0, 'a retryable refusal writes no audit row');
$park($i['id']);

// ===========================================================================
t('2. THE DESTINATION IS DERIVED — a payload may say WHICH device, never where or as whom');
$resetFake(); $n = count($calls); $before = $audit();
$i = $enqueue($A['customer'], 'device.provision', ['device_id' => $dev1['id']], $dev1['id']);
$out = $run($delivery);
is_($out['confirmed'], 1, 'a well-formed intent for a connected device is delivered and confirmed');
is_($intent($i['id'])['state'], 'confirmed', 'its state is confirmed');
$made = array_slice((array) $calls, $n);
is_($made[0] ?? null, 'GET 10.66.0.11 system/routerboard', 'the FIRST call is the identity read, at the address the REGISTRY holds for the device');
is_(in_array('PATCH 10.66.0.11 ip/hotspot/profile', $made, true), true, 'then the desired state is written to that same address');
is_(count(array_unique(array_map(static fn($c) => explode(' ', $c)[1], $made))), 1, 'every call went to one host: the device row\'s tunnel address');
is_($audit() - $before, 1, 'one audit row: intent.confirmed');
$a = $lastAudit('intent.confirmed');
is_([$a['actor'], $a['actor_kind'], $a['customer_id']], ['w-gc:routeros', 'system', $A['customer']], 'written by the worker, as system, for A');

foreach ([
    ['host' => '10.66.0.99'], ['endpoint' => '10.66.0.99'], ['address' => '10.66.0.99'], ['tunnel_ip' => '10.66.0.99'],
    ['ip' => '10.66.0.99'], ['url' => 'https://10.66.0.99/rest'], ['port' => 8443],
    ['serial' => 'HGX0000000'], ['username' => 'admin'], ['password' => 'x'], ['secret' => 'x'], ['wg_pubkey' => 'x'],
] as $forged) {
    $k = array_key_first($forged);
    $n = count($calls); $before = $audit();
    $i = $enqueue($A['customer'], 'device.provision', ['device_id' => $dev1['id']] + $forged, $dev1['id']);
    $out = $run($delivery);
    $row = $intent($i['id']);
    is_([$out['failed'], $row['state']], [1, 'failed'], "a payload carrying '{$k}' fails permanently");
    is_(str_contains((string) $row['last_error'], 'payload names a destination or credential'), true, '  with the reason recorded');
    is_(count($calls) - $n, 0, '  and NOTHING was contacted — the refusal precedes any connection');
    is_($audit() - $before, 1, '  one intent.failed audit row');
}
foreach ([
    [['device_id' => 'not-a-uuid'], 'device identity is malformed'],
    [['device_id' => $ABSENT], 'device not found'],
    [[], 'device.provision names no device'],
] as [$payload, $reason]) {
    $n = count($calls);
    $i = $enqueue($A['customer'], 'device.provision', $payload);
    $out = $run($delivery);
    is_([$out['failed'], $intent($i['id'])['state']], [1, 'failed'], "'{$reason}' fails closed, permanently");
    is_(str_contains((string) $intent($i['id'])['last_error'], $reason), true, '  naming the reason');
    is_(count($calls) - $n, 0, '  without contacting anything');
}
is_(DeliveryTarget::FORBIDDEN_KEYS, ['host', 'endpoint', 'address', 'tunnel_ip', 'ip', 'url', 'port',
    'serial', 'username', 'password', 'secret', 'wg_pubkey'], 'the forbidden-key list is what this section exercised');

// ===========================================================================
t('3. CROSS-TENANT — a router of another operator cannot be selected, and looks like no router at all');
$n = count($calls);
$iB = $enqueue($B['customer'], 'device.provision', ['device_id' => $dev1['id']], $dev1['id']);
$out = $run($delivery);
is_([$out['failed'], $intent($iB['id'])['state']], [1, 'failed'], "B's intent naming A's device fails");
is_(str_contains((string) $intent($iB['id'])['last_error'], 'device not found'), true, 'as "device not found" — indistinguishable from an id that does not exist');
is_(count($calls) - $n, 0, 'and nothing was contacted');
is_([$device($dev1['id'])['customer_id'], $device($dev1['id'])['state']], [$A['customer'], 'connected'], "A's device is untouched");
$patterns = Routes::build(new Authenticator($app))->patterns();
is_(array_values(array_filter($patterns, static fn($p) => stripos($p, 'router') !== false || stripos($p, 'device') !== false)), [],
    'the operator plane has NO router route at all: a router capability (op.routers.read) grants no RouterOS authority, because there is nothing it could reach');

// ===========================================================================
t('4. LIFECYCLE — the adapter delivers only to a device the registry records as connected, and moves no device itself');
$key2 = $wgKey();
$dev2 = $ra->register('HGX7777777', 'hEX S', '7.14.3', $key2, '10.66.0.12', 'noc-user');
$reg->setCredentials($dev2['id'], 'dn-mgmt', 'correct-horse', 'noc-user');
$ra->assign($dev2['id'], $A['customer'], $A['site'], 'Bar AP', 'noc-user');
$reg->setDesired($dev2['id'], ['ip/hotspot/profile' => ['use-radius' => 'yes']], 'noc-user');
is_($device($dev2['id'])['state'], 'staged', 'a registered-and-assigned device is still only staged');
$n = count($calls);
$i = $enqueue($A['customer'], 'device.provision', ['device_id' => $dev2['id']], $dev2['id']);
$out = $run($delivery);
$row = $intent($i['id']);
is_([$out['retrying'], $row['state'], (int) $row['attempts']], [1, 'queued', 1], 'delivery to a staged device is deferred, not attempted');
is_(str_contains((string) $row['last_error'], 'recorded as staged, not as connected'), true, 'with the reason');
is_(count($calls) - $n, 0, 'nothing contacted');
$park($i['id']);
$dev3 = $ra->register('HGX3333333', 'hAP lite', '7.14.3', $wgKey(), '10.66.0.13', 'noc-user');
$ra->assign($dev3['id'], $A['customer'], $A['site'], 'Old AP', 'noc-user');
$reg->transition($dev3['id'], 'decommissioned', 'noc-user');
$n = count($calls);
$i = $enqueue($A['customer'], 'device.provision', ['device_id' => $dev3['id']], $dev3['id']);
$out = $run($delivery);
is_([$out['failed'], $intent($i['id'])['state']], [1, 'failed'], 'a decommissioned device is a permanent refusal');
is_(count($calls) - $n, 0, 'nothing contacted');
is_($device($dev1['id'])['state'], 'connected', "dev1 was delivered to and confirmed in §2 and is STILL 'connected' — delivery never moves a device to provisioned or active");
is_(DeliveryTarget::DELIVERABLE_STATES, ['connected', 'provisioned', 'active', 'diverged'], 'the deliverable states are the four the trigger reaches after a tunnel');

// ===========================================================================
t('5. IDENTITY — the router behind the tunnel address must report the registered serial (H8, VERSION/MODEL DEPENDENT)');
$reg->transition($dev2['id'], 'connected', 'noc-user');
$n = count($calls); $before = $audit();
$i = $enqueue($A['customer'], 'device.provision', ['device_id' => $dev2['id']], $dev2['id']);
$out = $run($delivery);                     // the fake reports HGX8842011; dev2 is HGX7777777
$row = $intent($i['id']);
is_([$out['failed'], $row['state']], [1, 'failed'], 'a serial mismatch is a permanent refusal');
is_(str_contains((string) $row['last_error'], 'router identity mismatch'), true, 'naming the mismatch — and not either serial');
is_(str_contains((string) $row['last_error'], 'HGX'), false, '  neither serial appears in the error');
is_(array_slice((array) $calls, $n), ['GET 10.66.0.12 system/routerboard'], 'exactly ONE call was made — the identity read — and no write followed');
is_($audit() - $before, 1, 'one intent.failed row');

$noBoard = $override($transport, 'GET', 'system/routerboard', ['status' => 404, 'raw' => json_encode(['error' => 404, 'message' => 'no such command prefix'])]);
$i = $enqueue($A['customer'], 'device.provision', ['device_id' => $dev1['id']], $dev1['id']);
$out = $run(new RouterOsDelivery($factoryWith($noBoard)));
is_([$out['failed'], str_contains((string) $intent($i['id'])['last_error'], 'reports no serial')], [1, true],
    'a router with no RouterBOARD serial (a CHR) is refused by default — fail closed');
$i = $enqueue($A['customer'], 'device.provision', ['device_id' => $dev1['id']], $dev1['id']);
$out = $run(new RouterOsDelivery($factoryWith($noBoard), requireSerial: false));
is_($out['confirmed'], 1, 'only an explicit requireSerial: false (the CHR harness) proceeds without one');
$probe = ($factory)($dev1);
is_($probe->resource()['body']['version'] ?? null, '7.14.3 (stable)', 'the version/model read (H9, DOCUMENTED) returns what the fake was told — proving the read, not RouterOS');
is_($probe->identity()['body']['name'] ?? null, 'MikroTik', 'the identity name read (H10, DOCUMENTED) likewise');
is_($probe->routerboard()['body']['serial-number'] ?? null, 'HGX8842011', 'the serial read (H8) likewise — NOT HARDWARE VERIFIED');

// ===========================================================================
t('6. INTENTS — Queued → Sent → Confirmed / Failed; idempotent; leased; retries never duplicate a destructive act');
$k = 'gc-key-' . bin2hex(random_bytes(4));
$ia = $enqueue($A['customer'], 'device.provision', ['device_id' => $dev1['id']], $dev1['id'], $k);
$ib = $enqueue($A['customer'], 'device.provision', ['device_id' => $dev1['id']], $dev1['id'], $k);
is_($ia['id'], $ib['id'], 'the same idempotency key returns the same intent');
is_((int) $ins->one('SELECT count(*)::int AS c FROM mt_intents WHERE idempotency_key = ?', [$k])['c'], 1, 'and one row exists');
$out = $run($delivery);
$row = $intent($ia['id']);
is_([$row['state'], $row['sent_at'] !== null, $row['confirmed_at'] !== null, (int) $row['attempts'], $row['claimed_by']],
    ['confirmed', true, true, 1, null], 'queued → sent → confirmed, one attempt, lease released');

$ic = $enqueue($A['customer'], 'device.provision', ['device_id' => $dev1['id']], $dev1['id']);
$claimed = (new IntentQueue($workDb))->claim('w-other', '5 minutes', 10);
is_(in_array($ic['id'], array_column($claimed, 'id'), true), true, 'another worker claims the intent with a lease');
$out = $run($delivery);
is_($out['claimed'], 0, "a second worker claims NOTHING while the lease is held — it cannot act on another worker's intent");
$ins->exec("UPDATE mt_intents SET lease_expires_at = now() - interval '1 second' WHERE id = ?", [$ic['id']]);
$out = $run($delivery);
is_([$out['claimed'], $intent($ic['id'])['state']], [1, 'confirmed'], 'once the lease lapses the work returns and completes');

$five = $override($transport, 'PATCH', 'ip/hotspot/profile', ['status' => 500, 'raw' => json_encode(['error' => 500, 'message' => 'internal'])]);
$i = $enqueue($A['customer'], 'device.provision', ['device_id' => $dev1['id']], $dev1['id']);
$out = $run(new RouterOsDelivery($factoryWith($five)));
$row = $intent($i['id']);
is_([$out['retrying'], $row['state'], (int) $row['attempts'], strtotime($row['next_attempt_at']) > time()], [1, 'queued', 1, true],
    'a 5xx is retryable: back to queued, one attempt spent, next attempt in the future');
is_(str_contains((string) $row['last_error'], 'router returned 500'), true, 'with the status recorded');
$park($i['id']);
$four = $override($transport, 'PATCH', 'ip/hotspot/profile', ['status' => 400, 'raw' => json_encode(['error' => 400, 'message' => 'bad'])]);
$i = $enqueue($A['customer'], 'device.provision', ['device_id' => $dev1['id']], $dev1['id']);
$out = $run(new RouterOsDelivery($factoryWith($four)));
is_([$out['failed'], $intent($i['id'])['state'], (int) $intent($i['id'])['attempts'] <= 1], [1, 'failed', true],
    'a 4xx is permanent: failed at once, not after five identical rejections');

$resetFake();
$i1 = $enqueue($A['customer'], 'session.disconnect', ['device_id' => $dev1['id'], 'nas_session_id' => '*A'], $dev1['id']);
$out = $run($delivery);
is_([$out['confirmed'], $intent($i1['id'])['state']], [1, 'confirmed'], 'a disconnect removes the session and is confirmed by reading it back');
$i2 = $enqueue($A['customer'], 'session.disconnect', ['device_id' => $dev1['id'], 'nas_session_id' => '*A'], $dev1['id']);
$out = $run($delivery);
is_([$out['confirmed'], $intent($i2['id'])['state']], [1, 'confirmed'], 'a retried disconnect of a session that is already gone confirms without a second destructive act');
$active = $transport('GET', 'https://10.66.0.11/rest/ip/hotspot/active', null, 'dn-mgmt', 'correct-horse');
is_(json_decode($active['raw'], true), [], 'the router holds no session — removed once, not twice');
$i = $enqueue($A['customer'], 'session.disconnect', ['device_id' => $dev1['id']], $dev1['id']);
$out = $run($delivery);
is_([$out['failed'], str_contains((string) $intent($i['id'])['last_error'], 'names no session')], [1, true],
    'a disconnect naming no session fails closed');

// ===========================================================================
t('7. TIMEOUT — a router that does not answer in time is retryable, and the error names no address or credential');
$slow = $factoryWith($makeTransport($portSlow, 1));
$i = $enqueue($A['customer'], 'device.provision', ['device_id' => $dev1['id']], $dev1['id']);
$t0 = microtime(true);
$out = $run(new RouterOsDelivery($slow));
$took = microtime(true) - $t0;
$row = $intent($i['id']);
is_([$out['retrying'], $row['state']], [1, 'queued'], 'a timeout is a transport failure: the intent is left to retry');
is_(str_contains((string) $row['last_error'], 'router unreachable'), true, 'recorded as unreachable');
foreach ($secretNeedles as $s) { is_(str_contains((string) $row['last_error'], $s), false, "  and last_error carries no '{$s}'"); }
is_($took < 3.0, true, sprintf('the client gave up on its own timeout (%.1fs), not the router\'s (3s sleep)', $took));
$park($i['id']);

// ===========================================================================
t('8. MALFORMED — a success status with a body that is not JSON is never read as a state');
$garbage = ['status' => 200, 'raw' => 'this is not json {{'];
$c = new RestClient('10.66.0.11', 'u', 'p', 10, static fn() => $garbage);
$r = $c->get('anything');
is_([$r['status'], $r['body'], $r['malformed']], [200, null, true], 'the client flags a non-JSON 200 as malformed, body null');
$c2 = new RestClient('10.66.0.11', 'u', 'p', 10, static fn() => ['status' => 204, 'raw' => '']);
is_($c2->get('x')['malformed'], false, 'an empty body is not malformed (a 204 has none)');
$c3 = new RestClient('10.66.0.11', 'u', 'p', 10, static fn() => ['status' => 200, 'raw' => '{"name":"MikroTik"}']);
is_([$c3->get('x')['malformed'], $c3->get('x')['body']['name']], [false, 'MikroTik'], 'CONTROL: valid JSON decodes');

$badId = $override($transport, 'GET', 'system/routerboard', $garbage);
$i = $enqueue($A['customer'], 'device.provision', ['device_id' => $dev1['id']], $dev1['id']);
$out = $run(new RouterOsDelivery($factoryWith($badId)));
is_([$out['retrying'], str_contains((string) $intent($i['id'])['last_error'], 'identity could not be read')], [1, true],
    'a malformed identity answer: retryable, nothing written');
$park($i['id']);
$badPatch = $override($transport, 'PATCH', 'ip/hotspot/profile', $garbage);
$i = $enqueue($A['customer'], 'device.provision', ['device_id' => $dev1['id']], $dev1['id']);
$out = $run(new RouterOsDelivery($factoryWith($badPatch)));
is_([$out['retrying'], str_contains((string) $intent($i['id'])['last_error'], 'malformed response')], [1, true],
    'a malformed answer to a write: retryable, not confirmed');
$park($i['id']);
$badRead = $override($transport, 'GET', 'ip/hotspot/profile', $garbage);
$i = $enqueue($A['customer'], 'device.provision', ['device_id' => $dev1['id']], $dev1['id']);
$out = $run(new RouterOsDelivery($factoryWith($badRead)));
is_([$out['confirmed'], $intent($i['id'])['state']], [0, 'queued'], 'a malformed read-back is NOT a confirmation: the intent waits for another look');
is_(str_contains((string) $intent($i['id'])['last_error'], 'sent, not yet confirmed'), true, 'recorded as sent, not yet confirmed');
$park($i['id']);

// ===========================================================================
t('9. WITHHELD — no credential, address or key leaves the boundary');
$errors = array_column($ins->query('SELECT last_error FROM mt_intents WHERE last_error IS NOT NULL'), 'last_error');
is_(count($errors) >= 10, true, 'CONTROL: many failure messages were recorded in this run (' . count($errors) . ')');
foreach ($secretNeedles + [5 => $key1, 6 => $key2] as $s) {
    is_(count(array_filter($errors, static fn($e) => str_contains((string) $e, $s))), 0, "no last_error anywhere carries '" . substr($s, 0, 12) . "'");
}
$proj = AdminProjection::router($device($dev1['id']));
is_(array_key_exists('wg_pubkey', $proj), false, 'the Admin router projection withholds the WireGuard public key');
is_(str_contains(json_encode($proj), $key1), false, 'and the key value appears nowhere in it');
is_(in_array('tunnel_ip', array_keys($proj), true), true, 'CONTROL: the tunnel address IS an Admin-readable field (staff manage it)');
$cols = $ins->one("SELECT array_to_string(proargnames, ',') AS n FROM pg_proc WHERE proname = 'mt_admin_routers'")['n'];
is_(str_contains($cols, 'wg_pubkey') || str_contains($cols, 'secret'), false, 'mt_admin_routers() cannot return the key or a secret at all');
$dump = implode('', array_column($ins->query('SELECT secret_sealed FROM mt_device_secrets'), 'secret_sealed'));
is_(str_contains($dump, 'correct-horse'), false, 'the credential store holds envelopes, never the password');
foreach ($ins->query("SELECT detail::text AS d FROM mt_audit_log WHERE action LIKE 'device.%'") as $r) {
    if (str_contains($r['d'], $key1) || str_contains($r['d'], $key2) || str_contains($r['d'], '10.66.0.')) { bad('an audit detail carries a key or address: ' . $r['d']); }
}
ok('no device audit detail carries a WireGuard key or a tunnel address');

// ===========================================================================
t('10. AUDIT — every act names the staff subject or the worker; every refusal writes nothing');
$regd = $ins->query("SELECT * FROM mt_audit_log WHERE action = 'device.registered' AND target_id = ?", [$dev1['id']]);
is_(count($regd), 1, 'registration wrote exactly one audit row');
is_([$regd[0]['actor'], $regd[0]['actor_kind'], $regd[0]['source'], $regd[0]['customer_id']], ['noc-user', 'staff', 'admin', null],
    'actor = the staff subject, actor_kind = staff, source = admin, no operator yet');
$d = json_decode($regd[0]['detail'], true);
is_([$d['serial'], $d['model'], $d['state']], ['HGX8842011', 'hAP ax2', 'staged'], 'detail: serial, model, state — and nothing secret');
$asg = $ins->one("SELECT * FROM mt_audit_log WHERE action = 'device.assigned' AND target_id = ?", [$dev1['id']]);
is_([$asg['actor'], $asg['actor_kind'], $asg['customer_id'], json_decode($asg['detail'], true)['to_customer']],
    ['noc-user', 'staff', $A['customer'], $A['customer']], 'assignment: the staff subject, for the target operator');
$before = $audit();
throws_(fn() => $ra->register('HGX8842011', 'hAP ax2', null, null, null, 'noc-user'), 'already registered', 'a duplicate serial is refused');
throws_(fn() => $ra->register('HGX0000001', 'hAP ax2', null, $key1, null, 'noc-user'), 'already registered', 'a duplicate WireGuard key is refused');
throws_(fn() => $ra->register('HGX0000002', 'hAP ax2', null, null, '10.66.0.11', 'noc-user'), 'already registered', 'a duplicate tunnel address is refused');
throws_(fn() => $ra->assign($dev1['id'], $A['customer'], $B['site'], 'x', 'noc-user'), 'belongs to another operator', "a site of another operator is refused by the W-2 constraint");
throws_(fn() => $ra->assign($dev1['id'], $ABSENT, null, 'x', 'noc-user'), 'not found', 'an unknown operator is refused by the foreign key');
throws_(fn() => $ra->register('HGX0000003', 'hAP', null, null, null, '  '), 'requires the identity', 'an empty actor is refused before the database is asked');
is_($audit() - $before, 0, 'six refusals, zero audit rows');
is_([$device($dev1['id'])['customer_id'], $device($dev1['id'])['site_id']], [$A['customer'], $A['site']], 'and the device is exactly where it was');
is_($ra->assign($ABSENT, $A['customer'], null, 'x', 'noc-user'), null, 'assigning a device that does not exist returns null — not an error, not a row');
$conf = $ins->query("SELECT actor, actor_kind FROM mt_audit_log WHERE action = 'intent.confirmed'");
is_(count($conf) >= 5, true, 'CONTROL: several confirmations were audited');
is_(count(array_filter($conf, static fn($r) => $r['actor'] !== 'w-gc:routeros' || $r['actor_kind'] !== 'system')), 0,
    "every confirmation names the worker — with its binding in the name — as actor_kind 'system'");
$fails = $ins->query("SELECT detail FROM mt_audit_log WHERE action = 'intent.failed'");
is_(count($fails) >= 15, true, 'CONTROL: the permanent failures above were each audited (' . count($fails) . ')');
is_(count(array_filter($fails, static fn($r) => !isset(json_decode($r['detail'], true)['reason']))), 0, 'and every one carries its reason');

// ===========================================================================
t('11. SIMULATOR — answers deterministically, says so everywhere, and moves nothing in the registry');
$sim = new SimulatedRouterOs();
$cfgBefore = $ins->one('SELECT actual, actual_read_at FROM mt_device_config WHERE device_id = ?', [$dev1['id']]);
$stateBefore = $device($dev1['id'])['state'];
$i = $enqueue($A['customer'], 'device.provision', ['device_id' => $dev1['id']], $dev1['id']);
$res = $ctxW->run($A['customer'], fn(Database $db) => $sim->deliver($db, $intent($i['id'])));
is_([$res->accepted, $res->simulated], [true, true], 'the simulated router accepts and TAGS the result simulated');
$out = $run($sim, 'w-gc:simulated-routeros');
is_([$out['confirmed'], $intent($i['id'])['state']], [1, 'confirmed'], 'through the worker it confirms — from its own memory');
is_($sim->applied($dev1['id']), ['ip/hotspot/profile' => ['use-radius' => 'yes']], 'what it believes it holds is the desired state');
is_($device($dev1['id'])['state'], $stateBefore, "the device's state is UNCHANGED ({$stateBefore}) — a simulator answering does not make a router connected, provisioned or active");
is_($ins->one('SELECT actual, actual_read_at FROM mt_device_config WHERE device_id = ?', [$dev1['id']]), $cfgBefore,
    'and the registry\'s actual-state record is untouched: the simulator writes NOTHING to the database');
is_($device($dev1['id'])['last_seen_at'], null, 'last_seen_at is still never written by anything');
is_($lastAudit('intent.confirmed')['actor'], 'w-gc:simulated-routeros', 'the audit actor carries the simulated binding, so the row can never read as a router answering');
$i = $enqueue($A['customer'], 'device.provision', ['device_id' => $dev1['id'], 'host' => '10.66.0.99'], $dev1['id']);
$res = $ctxW->run($A['customer'], fn(Database $db) => $sim->deliver($db, $intent($i['id'])));
is_([$res->accepted, $res->retryable, $res->simulated], [false, false, true], 'the simulator refuses a forged destination exactly as the real adapter does — one resolver, two adapters');
$park($i['id']);
$iB = $enqueue($B['customer'], 'device.provision', ['device_id' => $dev2['id']], $dev2['id']);
$res = $ctxW->run($B['customer'], fn(Database $db) => $sim->deliver($db, $intent($iB['id'])));
is_([$res->accepted, $res->error], [false, 'device not found'], "and cannot see another operator's device either");
$park($iB['id']);
$sim->seedSession($dev1['id'], '*S1');
$i = $enqueue($A['customer'], 'session.disconnect', ['device_id' => $dev1['id'], 'nas_session_id' => '*S1'], $dev1['id']);
$out = $run($sim, 'w-gc:simulated-routeros');
is_([$out['confirmed'], $intent($i['id'])['state']], [1, 'confirmed'], 'a simulated disconnect confirms from memory');
$sim->refuseNextWrite($dev1['id']);
$i = $enqueue($A['customer'], 'device.provision', ['device_id' => $dev1['id']], $dev1['id']);
$out = $run($sim, 'w-gc:simulated-routeros');
is_([$out['retrying'], $intent($i['id'])['state']], [1, 'queued'], 'and it can be told to fail like a router, retryably');
$park($i['id']);
$sd = Bindings::simulated()->describe();
is_([$sd['delivery_binding'], $sd['delivery_simulated'], $sd['publisher_simulated'], $sd['phase']],
    ['simulated-routeros', true, true, 'F6-A'], 'Bindings::simulated() reports both doubles as simulated and the phase as F6-A');
is_(count(SignalReport::actions()), 4, 'the four router actions are still declared server-side');
foreach (SignalReport::actions() as $act) { is_(($act['reason'] ?? '') !== '', true, "action {$act['key']} is inert, with its reason"); }

// ===========================================================================
t('12. ADMIN PLANE — register and assign are bound; the actor is the subject; the action is bound since 028 (docs/121)');
$hit = static function (Router $r, string $m, string $p, array $body = []) {
    $mm = $r->match($m, $p);
    if ($mm === null) { bad("no route {$m} {$p}"); return new \Dn\Http\Response(404); }
    return ($mm[0])(new Request($m, $p, [], $body, $mm[1], '127.0.0.1'));
};
$as = static fn(string $subject, StaffRole $role, ?RouterAdmin $routers) => AdminRoutes::build(
    new FixedStaff(new StaffIdentity($subject, $role, 'test')), Bindings::defaults(), null, null, null, null, $routers);
$noc = $as('noc-user', StaffRole::Noc, $ra);
$key4 = $wgKey();
$before = $audit();
$res = $hit($noc, 'POST', '/api/v1/admin/routers',
    ['serial' => 'HGX5555555', 'model' => 'hAP ax3', 'ros_version' => '7.15.2', 'wg_pubkey' => $key4, 'tunnel_ip' => '10.66.0.14']);
is_($res->status, 201, 'NOC registers a router over the Admin API');
$rt = $res->body['router'];
is_([$rt['serial'], $rt['model'], $rt['state'], $rt['staged_by'], $rt['tunnel_ip'], $rt['customer_id']],
    ['HGX5555555', 'hAP ax3', 'staged', 'noc-user', '10.66.0.14', null], 'the row: staged by the SUBJECT, owned by nobody');
is_(array_key_exists('wg_pubkey', $rt), false, 'the response withholds the WireGuard key');
is_($ins->one('SELECT wg_pubkey FROM mt_devices WHERE id = ?', [$rt['id']])['wg_pubkey'], $key4, 'CONTROL: the key was stored');
is_($audit() - $before, 1, 'one audit row');
is_($lastAudit('device.registered')['actor'], 'noc-user', 'naming the subject');
foreach ([
    [['serial' => 'HGX5555556', 'model' => 'x', 'staged_by' => 'someone-else'], 'staged_by'],
    [['serial' => 'HGX5555556', 'model' => 'x', 'actor' => 'someone-else'], 'actor'],
    [['serial' => 'HGX5555556', 'model' => 'x', 'customer_id' => $A['customer']], 'customer_id'],
    [['serial' => 'HGX5555556', 'model' => 'x', 'state' => 'active'], 'state'],
] as [$body, $field]) {
    $res = $hit($noc, 'POST', '/api/v1/admin/routers', $body);
    is_([$res->status, str_contains($res->body['error'], "{$field} is not accepted")], [400, true], "a body carrying '{$field}' is REFUSED, not ignored");
}
foreach ([
    [['model' => 'x'], 'serial'], [['serial' => 'ab', 'model' => 'x'], 'serial'], [['serial' => 'HGX5555556'], 'model'],
    [['serial' => 'HGX5555556', 'model' => 'x', 'wg_pubkey' => 'short'], 'wg_pubkey'],
    [['serial' => 'HGX5555556', 'model' => 'x', 'tunnel_ip' => '192.0.2.10'], 'tunnel_ip'],
    [['serial' => 'HGX5555556', 'model' => 'x', 'tunnel_ip' => '10.66.0.14/32'], 'tunnel_ip'],
    [['serial' => 'HGX5555556', 'model' => 'x', 'tunnel_ip' => 'https://10.66.0.14'], 'tunnel_ip'],
    [['serial' => 'HGX5555556', 'model' => 'x', 'tunnel_ip' => '10.67.0.1'], 'tunnel_ip'],
] as [$body, $field]) {
    $res = $hit($noc, 'POST', '/api/v1/admin/routers', $body);
    is_([$res->status, str_contains($res->body['error'], $field)], [400, true], "an invalid {$field} is 400");
}
is_(TunnelAddress::isRegistrable('10.66.255.254') && !TunnelAddress::isRegistrable('10.66.0.14/32') && !TunnelAddress::isManagement('10.67.0.1')
    && TunnelAddress::isManagement('https://10.66.0.14:443/rest'), true, 'the management-network rule: one bare address in 10.66/16 to register; scheme and port tolerated when talking');
$before = $audit();
$res = $hit($noc, 'POST', '/api/v1/admin/routers', ['serial' => 'HGX5555555', 'model' => 'again']);
is_([$res->status, $res->body['error'], str_contains($res->body['detail'], 'already registered')], [409, 'refused', true], 'a duplicate serial is 409 refused');
is_($audit() - $before, 0, 'and audits nothing');

$before = $audit();
$res = $hit($noc, 'POST', "/api/v1/admin/routers/{$rt['id']}/assign", ['customer_id' => $A['customer'], 'site_id' => $A['site'], 'name' => 'Terrace AP']);
is_($res->status, 200, 'NOC assigns the router to an explicit target operator and site');
is_([$res->body['router']['customer_id'], $res->body['router']['site_id'], $res->body['router']['name']], [$A['customer'], $A['site'], 'Terrace AP'], 'the row shows the assignment');
is_([$audit() - $before, $lastAudit('device.assigned')['actor'], $lastAudit('device.assigned')['customer_id']], [1, 'noc-user', $A['customer']],
    'one audit row, the subject as actor, for the target operator');
$before = $audit();
$res = $hit($noc, 'POST', "/api/v1/admin/routers/{$rt['id']}/assign", ['customer_id' => $A['customer'], 'site_id' => $B['site']]);
is_([$res->status, str_contains($res->body['detail'] ?? '', 'another operator')], [409, true], "a site of another operator: 409 — the W-2 constraint, below the function");
is_($audit() - $before, 0, 'and no audit row');
is_($hit($noc, 'POST', "/api/v1/admin/routers/{$ABSENT}/assign", ['customer_id' => $A['customer']])->status, 404, 'an unknown device is 404');
is_($hit($noc, 'POST', '/api/v1/admin/routers/not-a-uuid/assign', ['customer_id' => $A['customer']])->status, 404, 'a malformed id is 404');
is_($hit($noc, 'POST', "/api/v1/admin/routers/{$rt['id']}/assign", [])->status, 400, 'no target operator is 400');
is_($hit($noc, 'POST', "/api/v1/admin/routers/{$rt['id']}/assign", ['customer_id' => $A['customer'], 'actor' => 'x'])->status, 400, 'an actor in the body is refused');
is_($hit($noc, 'POST', "/api/v1/admin/routers/{$rt['id']}/assign", ['customer_id' => $ABSENT])->status, 409, 'an unknown operator is 409 refused (the foreign key)');

foreach ([['sales', StaffRole::Sales], ['support', StaffRole::Support]] as [$name, $role]) {
    $r = $as($name, $role, $ra);
    $res = $hit($r, 'POST', '/api/v1/admin/routers', ['serial' => 'HGX6666666', 'model' => 'x']);
    is_([$res->status, $res->body['capability']], [403, 'routers.register'], "{$name} cannot register (403 naming routers.register)");
    $res = $hit($r, 'POST', "/api/v1/admin/routers/{$rt['id']}/assign", ['customer_id' => $A['customer']]);
    is_([$res->status, $res->body['capability']], [403, 'routers.assign'], "{$name} cannot assign");
}
$admin = $as('admin-user', StaffRole::Admin, $ra);
is_($hit($admin, 'POST', '/api/v1/admin/routers', ['serial' => 'HGX6666666', 'model' => 'hAP'])->status, 201, 'Admin can register too');
$unwired = $as('noc-user', StaffRole::Noc, null);
$res = $hit($unwired, 'POST', '/api/v1/admin/routers', ['serial' => 'HGX6666667', 'model' => 'x']);
is_([$res->status, $res->body['error']], [501, 'router_writes_unavailable'], 'a process without an Admin write connection says so with 501');
// Since migration 028 (docs/121) the ACTION route is bound. This router is
// assigned but still recorded `staged`, so the function refuses to queue a job
// the worker could only fail later: 409 with the reason, nothing queued. The
// full proof of the route lives in tests/test_router_lifecycle_provision.php.
$res = $hit($noc, 'POST', "/api/v1/admin/routers/{$rt['id']}/actions", ['action' => 'push_config', 'idempotency_key' => 'gc-act-' . bin2hex(random_bytes(4))]);
is_([$res->status, $res->body['error'], str_contains($res->body['detail'], 'recorded as staged')], [409, 'refused', true],
    'the ACTION route is bound (028): a router recorded staged is refused with the reason, not queued to fail later');
is_((int) $ins->one("SELECT count(*)::int AS c FROM mt_intents WHERE target_id = ?", [$rt['id']])['c'], 0, 'and it queued nothing');
is_($hit($as('sales', StaffRole::Sales, $ra), 'POST', "/api/v1/admin/routers/{$rt['id']}/actions", [])->status, 403, 'sales cannot reach the action');
foreach (StaffRole::cases() as $role) {
    is_([$role->can('routers.register'), $role->can('routers.assign')],
        in_array($role, [StaffRole::Admin, StaffRole::Noc], true) ? [true, true] : [false, false],
        "matrix: {$role->value} " . (in_array($role, [StaffRole::Admin, StaffRole::Noc], true) ? 'can' : 'cannot') . ' register and assign');
}
$src = strip_php_comments(file_get_contents(__DIR__ . '/../src/Api/AdminRoutes.php'));
is_(preg_match('/\$req->body\[[\'"](staged_by|actor)[\'"]\]/', $src), 0, 'AdminRoutes never reads an actor or staged_by from a body');
is_(substr_count($src, '$s->subject') >= 2, true, 'and hands the SUBJECT to both router writes');
is_(str_contains($src, 'mt_device_'), false, 'the route file names no SQL function — RouterAdmin does, on dnb_adminwrite');
$fa = strip_php_comments(file_get_contents(__DIR__ . '/../src/Admin/RouterAdmin.php'));
is_(str_contains($fa, 'mt_device_register') && str_contains($fa, 'mt_device_assign') && str_contains($fa, 'mt_device_set_state')
    && str_contains($fa, 'mt_device_provision_request') && !str_contains($fa, 'mt_intents') && !str_contains($fa, 'RestClient'), true,
    'RouterAdmin reaches exactly the four router functions (docs/121): never a table, never a router');
foreach (['src/Api/AdminRoutes.php', 'src/Admin/RouterAdmin.php'] as $f) {
    $b = strip_php_comments(file_get_contents(__DIR__ . '/../' . $f));
    is_(str_contains($b, 'Dn\\Delivery') || str_contains($b, 'DeliveryPort') || str_contains($b, 'IntentWorker'), false,
        "{$f} cannot reach the delivery boundary (F2)");
}

// ===========================================================================
t('13. REPOSITORY STATE — migrations end at 031 (sign-in, docs/127) after 028 (docs/121), 029 (O-1, docs/124) and 030 (onboarding, docs/125); the gate variable is declared; nothing claims hardware');
$files = array_map('basename', glob(__DIR__ . '/../migrations/*.sql')); sort($files);
is_([substr(end($files), 0, 3), count(array_filter($files, fn($f) => str_starts_with($f, '028')))], ['031', 1], 'the last migration is 031 (sign-in, docs/127), after one 028 (docs/121); G-C itself added none');
is_((int) $ins->one('SELECT count(*)::int AS n FROM mt_migrations')['n'], 31, 'the ledger records 31');
$client = file_get_contents(__DIR__ . '/../src/Delivery/RouterOs/RestClient.php');
is_(str_contains($client, 'requireRealBindingsAllowed'), true, 'RestClient checks the F6-B gate before its real transport');
is_(str_contains($client, 'HARDWARE VERIFIED'), true, 'and says in its header that nothing in it is HARDWARE VERIFIED');
foreach ([__DIR__ . '/../src/Delivery/RouterOsDelivery.php', __DIR__ . '/../src/Delivery/SimulatedRouterOs.php',
          __DIR__ . '/../src/Delivery/DeliveryTarget.php', __DIR__ . '/../src/Runtime/Bindings.php'] as $f) {
    $b = strip_php_comments(file_get_contents($f));
    is_(preg_match('/hardware[- ]verified|activation proven|works on (a )?real/i', $b), 0, basename($f) . ' claims no hardware result in code');
}
is_(str_contains(file_get_contents(__DIR__ . '/../../docs/118-G-C-ROUTER-CONTROL-PLANE-BOUNDARY.md'), 'NOTHING in this document is HARDWARE VERIFIED'), true,
    'docs/118 opens by withholding the label');

exit(t_summary());
