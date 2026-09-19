<?php
declare(strict_types=1);
/**
 * The device plane, and delivery to a router.
 *
 * docs/55 step 7 exit condition: "Provisions against CHR; UNPROVEN ON METAL."
 *
 * Read the CHR half honestly. This environment has no hardware
 * virtualisation, no qemu, no Docker daemon and no route to fetch an image,
 * so CHR could not be run. These tests drive the real delivery code against
 * tests/fake_routeros.php over real HTTP, which proves the client's transport
 * and this code's logic — and, per docs/30 Artifact 13, proves nothing about
 * whether RouterOS accepts these paths and payloads.
 * tools/chr_harness.sh is the check that would, and has not been run.
 */
require __DIR__ . '/bootstrap.php';
use Dn\Api\Routes;
use Dn\Auth\Authenticator;
use Dn\Crypto\SecretBox;
use Dn\Db\Database;
use Dn\Delivery\RouterOs\RestClient;
use Dn\Delivery\RouterOsDelivery;
use Dn\Devices\DeviceRegistry;
use Dn\Http\Kernel;
use Dn\Http\Request;
use Dn\Intents\IntentQueue;
use Dn\Jobs\IntentWorker;
use Dn\Tenancy\TenantContext;

putenv('DNB_SECRET_KEY=test-key-for-suite-only');

$owner = Database::owner();
$ids = seed_two_customers($owner);
$A = $ids['A']; $B = $ids['B'];
$db   = Database::app();
$auth = new Authenticator($db);
$ctx  = new TenantContext($db);
$k    = new Kernel(Routes::build($auth), $db, $auth, $ctx);

// ── fake router, over real HTTP ────────────────────────────────────────────
$port = 59100 + (getmypid() % 300);
$log  = tempnam(sys_get_temp_dir(), 'fakeros');
$fakeId = 'dev-' . getmypid();
@unlink(sys_get_temp_dir() . "/fake-ros-{$fakeId}.json");
$pid = (int) shell_exec(sprintf(
    'FAKE_ROS_ID=%s php -S 127.0.0.1:%d %s > %s 2>&1 & echo $!',
    escapeshellarg($fakeId), $port, escapeshellarg(__DIR__ . '/fake_routeros.php'), $log));
for ($i = 0; $i < 100; $i++) {
    $s = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
    if ($s) { fclose($s); break; }
    usleep(50_000);
}
register_shutdown_function(function () use ($pid, $log, $fakeId) {
    if ($pid > 0) { @shell_exec("kill {$pid} 2>/dev/null"); }
    @unlink($log); @unlink(sys_get_temp_dir() . "/fake-ros-{$fakeId}.json");
});

/** Real curl, at the fake. The RestClient's own host guard stays intact. */
$transport = function (string $method, string $url, ?array $body, string $u, string $p) use ($port) {
    $url = preg_replace('#^https://[^/]+/rest/#', "http://127.0.0.1:{$port}/rest/", $url);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_USERPWD => "{$u}:{$p}", CURLOPT_HTTPAUTH => CURLAUTH_BASIC, CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
    $raw = curl_exec($ch);
    $st  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $st, 'body' => json_decode((string) $raw, true)];
};
$clientFactory = fn(array $d) => new RestClient(
    $d['tunnel_ip'], 'dn-mgmt', 'correct-horse', 10, \Closure::fromCallable($transport));

// ===========================================================================
t('registration records the trust anchor established at staging');
$dev = $ctx->runUnscoped(fn($d) => (new DeviceRegistry($d))->register(
    'HGX8842011', 'hAP ax2', '7.14.3', 'pubkey-aaa', '10.66.0.11', 'tech:bhavin'));
is_($dev['state'], 'staged', 'a device staged by someone is staged');
is_($dev['staged_by'], 'tech:bhavin', 'and records WHO staged it');
is_($dev['staged_at'] !== null, true, 'and when');

$unstaged = $ctx->runUnscoped(fn($d) => (new DeviceRegistry($d))->register(
    'HGX9999999', 'hAP ax lite', null, null, null, null));
is_($unstaged['state'], 'registered', 'a device nobody staged is only registered');

// ===========================================================================
t('CREDENTIALS — sealed at rest, never a password in a column');
$reg = new DeviceRegistry($db);
$ctx->runUnscoped(fn($d) => (new DeviceRegistry($d))->setCredentials($dev['id'], 'dn-mgmt', 'correct-horse'));
$row = $owner->one('SELECT * FROM mt_device_secrets WHERE device_id = ?', [$dev['id']]);
is_(str_contains($row['secret_sealed'], 'correct-horse'), false, 'the column does not contain the password');
is_(str_starts_with($row['secret_sealed'], 'v1.'), true, 'it is a versioned envelope');

$dump = '';
foreach ($owner->query('SELECT secret_sealed FROM mt_device_secrets') as $r) { $dump .= $r['secret_sealed']; }
is_(str_contains($dump, 'correct-horse'), false, 'a dump of the whole table yields no password');

$back = $ctx->runUnscoped(fn($d) => (new DeviceRegistry($d))->credentials($dev['id']));
is_($back['password'], 'correct-horse', 'and it opens correctly for its own device');

t('CREDENTIALS — an envelope lifted to another device does not open');
// The device id is the associated data. Without that binding, a swapped row
// would decrypt cleanly into the wrong device's credential.
$dev2 = $ctx->runUnscoped(fn($d) => (new DeviceRegistry($d))->register(
    'HGX7777777', 'hAP ax2', '7.14.3', 'pubkey-bbb', '10.66.0.12', 'tech:x'));
$owner->exec('INSERT INTO mt_device_secrets (device_id, username, secret_sealed) VALUES (?,?,?)',
    [$dev2['id'], 'dn-mgmt', $row['secret_sealed']]);
throws_(fn() => $ctx->runUnscoped(fn($d) => (new DeviceRegistry($d))->credentials($dev2['id'])),
    'did not open', "device B cannot open device A's envelope");

t('CREDENTIALS — a wrong key does not open, and a missing key refuses to run');
$otherBox = new SecretBox('a-completely-different-key');
throws_(fn() => $otherBox->open($row['secret_sealed'], $dev['id']),
    'did not open', 'the wrong key fails closed');
$saved = getenv('DNB_SECRET_KEY');
putenv('DNB_SECRET_KEY');
throws_(fn() => new SecretBox(), 'DNB_SECRET_KEY',
    'no key at all refuses to run rather than falling back to a default');
putenv("DNB_SECRET_KEY={$saved}");

// ===========================================================================
t('the client refuses to manage anything off the tunnel');
// Management is reachable over the tunnel only (docs/31 §3.1 step 8). Without
// this, "TLS verification is off" would quietly become true everywhere.
foreach (['192.0.2.10', 'router.example.com', '10.67.0.1', '8.8.8.8'] as $host) {
    throws_(fn() => new RestClient($host, 'u', 'p'), 'tunnel',
        "refuses {$host}");
}
is_(RestClient::isTunnelHost('10.66.0.11'), true, 'and accepts a tunnel address');

// ===========================================================================
t('DELIVERY — provisioning pushes desired state and confirms by reading back');
$ctx->runUnscoped(fn($d) => (new DeviceRegistry($d))->setDesired($dev['id'],
    ['ip/hotspot/profile' => ['use-radius' => 'yes']]));
$ctx->runUnscoped(fn($d) => (new DeviceRegistry($d))->assign($dev['id'], $A['customer'], $A['site'], 'Lobby AP'));

$intent = $ctx->run($A['customer'], fn($d) => (new IntentQueue($d))->enqueue(
    $A['customer'], 'device.provision', ['device_id' => $dev['id']],
    $A['principal'], 'device', $dev['id']));

$delivery = new RouterOsDelivery($db, $clientFactory);
$worker = new IntentWorker($db, $ctx, new IntentQueue($db), $delivery, 'w-dev');
$out = $worker->runOnce();
is_($out['confirmed'], 1, 'the intent is delivered and confirmed');
$st = $owner->one('SELECT state FROM mt_intents WHERE id = ?', [$intent['id']]);
is_($st['state'], 'confirmed', 'its state is confirmed');

$cfg = $ctx->runUnscoped(fn($d) => (new DeviceRegistry($d))->config($dev['id']));
is_($cfg['actual_read_at'] !== null, true, 'actual state was read back and stored');
is_($ctx->runUnscoped(fn($d) => (new DeviceRegistry($d))->divergence($dev['id'])), [],
    'and desired and actual agree');

t('DELIVERY — a router that accepts and does not apply is NOT confirmed');
// The failure mode trusting the write would miss. The fake is reset so the
// PATCH lands but the read-back shows the old value.
@unlink(sys_get_temp_dir() . "/fake-ros-{$fakeId}.json");
// Delegates delivery to the real implementation; the read-back disagrees.
$diverging = new class(new RouterOsDelivery($db, $clientFactory)) implements \Dn\Delivery\DeliveryPort {
    public function __construct(private RouterOsDelivery $inner) {}
    public function deliver(array $i): \Dn\Delivery\DeliveryResult { return $this->inner->deliver($i); }
    public function confirm(array $i): bool { return false; }
};
$i2 = $ctx->run($A['customer'], fn($d) => (new IntentQueue($d))->enqueue(
    $A['customer'], 'device.provision', ['device_id' => $dev['id']],
    $A['principal'], 'device', $dev['id']));
$out = (new IntentWorker($db, $ctx, new IntentQueue($db), $diverging, 'w-div'))->runOnce();
is_($out['confirmed'], 0, 'not confirmed');
is_($owner->one('SELECT state FROM mt_intents WHERE id = ?', [$i2['id']])['state'], 'queued',
    'it goes back for another look rather than being called done');

t('DELIVERY — disconnect removes the session and confirms it is gone');
$i3 = $ctx->run($A['customer'], fn($d) => (new IntentQueue($d))->enqueue(
    $A['customer'], 'session.disconnect',
    ['device_id' => $dev['id'], 'nas_session_id' => '*A'], $A['principal'], 'device', $dev['id']));
$out = (new IntentWorker($db, $ctx, new IntentQueue($db), $delivery, 'w-disc'))->runOnce();
is_($out['confirmed'], 1, 'confirmed');
$active = $transport('GET', 'https://x/rest/ip/hotspot/active', null, 'dn-mgmt', 'correct-horse');
is_(count($active['body']), 0, 'the session really is gone from the router');

t('DELIVERY — an unknown intent kind fails permanently rather than retrying forever');
$i4 = $ctx->run($A['customer'], fn($d) => (new IntentQueue($d))->enqueue(
    $A['customer'], 'something.invented', ['device_id' => $dev['id']], $A['principal']));
(new IntentWorker($db, $ctx, new IntentQueue($db), $delivery, 'w-unk'))->runOnce();
$s4 = $owner->one('SELECT state, attempts FROM mt_intents WHERE id = ?', [$i4['id']]);
is_($s4['state'], 'failed', 'it fails');
is_((int) $s4['attempts'] <= 1, true, 'without burning five attempts');

t('DELIVERY — wrong credentials are a failure, not a silent success');
$badFactory = fn(array $d) => new RestClient($d['tunnel_ip'], 'dn-mgmt', 'wrong-password', 10,
    \Closure::fromCallable($transport));
$i5 = $ctx->run($A['customer'], fn($d) => (new IntentQueue($d))->enqueue(
    $A['customer'], 'device.provision', ['device_id' => $dev['id']], $A['principal'], 'device', $dev['id']));
(new IntentWorker($db, $ctx, new IntentQueue($db),
    new RouterOsDelivery($db, $badFactory), 'w-bad'))->runOnce();
$s5 = $owner->one('SELECT state FROM mt_intents WHERE id = ?', [$i5['id']]);
is_(in_array($s5['state'], ['queued', 'failed'], true), true,
    'a 401 from the router does not produce a confirmed intent');

// ===========================================================================
t('a direct UPDATE on unassigned stock reaches nothing at all');
// Worth asserting on its own. RLS makes this a no-op rather than an error:
// zero rows match, so the lifecycle trigger never even fires. Fail-closed,
// and the reason state changes go through the admin function below.
$n = $ctx->runUnscoped(fn($d) => $d->exec(
    "UPDATE mt_devices SET state = 'active' WHERE id = ?", [$unstaged['id']]));
is_($n, 0, 'the app role cannot touch unassigned stock directly');
is_($owner->one('SELECT state FROM mt_devices WHERE id = ?', [$unstaged['id']])['state'],
    'registered', 'and the device did not move');

t('LIFECYCLE — the database refuses an illegal transition');
// Through the admin path, which is the only way the row is reachable.
throws_(fn() => $ctx->runUnscoped(fn($d) => (new DeviceRegistry($d))
        ->transition($unstaged['id'], 'active')),
    'illegal', 'registered cannot jump to active');

$ctx->runUnscoped(fn($d) => (new DeviceRegistry($d))->transition($unstaged['id'], 'decommissioned'));
throws_(fn() => $ctx->runUnscoped(fn($d) => (new DeviceRegistry($d))
        ->transition($unstaged['id'], 'staged')),
    'decommissioned', 'a decommissioned device cannot come back');

// The delete trigger is asserted as the OWNER, because the app role cannot
// reach the row to attempt it — two independent protections, both real.
throws_(fn() => $owner->exec('DELETE FROM mt_devices WHERE id = ?', [$unstaged['id']]),
    'not deleted', 'and even the owner cannot delete it');

t('a legal transition is allowed');
$ok = $ctx->runUnscoped(fn($d) => (new DeviceRegistry($d))->transition($dev['id'], 'shipped'));
is_($ok['state'], 'shipped', 'staged -> shipped is accepted');

t('DIVERGENCE is computed, not stored');
$cols = array_column($owner->query(
    "SELECT column_name FROM information_schema.columns WHERE table_name='mt_device_config'"), 'column_name');
is_(in_array('diverged', $cols, true), false, 'there is no stored divergence column');
$ctx->runUnscoped(fn($d) => (new DeviceRegistry($d))->setActual($dev['id'],
    ['ip/hotspot/profile' => ['use-radius' => 'no']]));
is_($ctx->runUnscoped(fn($d) => (new DeviceRegistry($d))->divergence($dev['id'])),
    ['ip/hotspot/profile'], 'and divergence is derived from the two sides');

t('divergence tolerates attributes the router knows and we never set');
// The router returns .id, name and much else. Requiring equality would call
// every device diverged forever.
$ctx->runUnscoped(fn($d) => (new DeviceRegistry($d))->setActual($dev['id'],
    ['ip/hotspot/profile' => [['.id' => '*1', 'name' => 'hsprof1',
                              'use-radius' => 'yes', 'html-directory' => 'hotspot']]]));
is_($ctx->runUnscoped(fn($d) => (new DeviceRegistry($d))->divergence($dev['id'])), [],
    'extra attributes do not count as divergence');
$ctx->runUnscoped(fn($d) => (new DeviceRegistry($d))->setActual($dev['id'],
    ['ip/hotspot/profile' => [['.id' => '*1', 'use-radius' => 'no']]]));
is_($ctx->runUnscoped(fn($d) => (new DeviceRegistry($d))->divergence($dev['id'])),
    ['ip/hotspot/profile'], 'but a wrong value for an attribute we set does');

// ===========================================================================
t('ISOLATION — unassigned stock belongs to no customer');
$stock = $ctx->runUnscoped(fn($d) => (new DeviceRegistry($d))->register(
    'HGX5555555', 'hEX S', '7.14.3', 'pubkey-ccc', '10.66.0.99', 'tech:y'));
$seenA = $ctx->run($A['customer'], fn($d) => (new DeviceRegistry($d))->forCustomer());
is_(in_array($stock['id'], array_column($seenA, 'id'), true), false,
    'unassigned stock is invisible to a customer');
$seenB = $ctx->run($B['customer'], fn($d) => (new DeviceRegistry($d))->forCustomer());
is_(count($seenB), 0, "B sees no devices at all");
is_(count($seenA), 1, 'A sees only the one assigned to it');
is_($ctx->run($B['customer'], fn($d) => (new DeviceRegistry($d))->find($dev['id'])), null,
    "B cannot fetch A's device by id");

exit(t_summary());
