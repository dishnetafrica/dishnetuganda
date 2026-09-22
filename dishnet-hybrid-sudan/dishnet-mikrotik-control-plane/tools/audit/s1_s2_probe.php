<?php
/** Adversarial probe. Run before and after remediation. */
$base = '/home/user/dishnetuganda/dishnet-hybrid-sudan/dishnet-mikrotik-control-plane';
require "{$base}/src/autoload.php";
use Dn\Db\Database; use Dn\Tenancy\TenantContext; use Dn\Devices\DeviceRegistry; use Dn\Intents\IntentQueue;

$owner = Database::owner();
$admin = method_exists(Database::class,'admin') ? Database::admin() : Database::app();
$app   = Database::app();
$ctxA  = new TenantContext($admin);
$ctx   = new TenantContext($app);

$ids = [];
foreach (['P','Q'] as $kk) {
  $c = $owner->one('INSERT INTO mt_customers (name) VALUES (?) RETURNING id', ["Cust {$kk}"]);
  $d = $ctxA->runUnscoped(fn($x) => (new DeviceRegistry($x))->register(
      "SER-{$kk}-" . bin2hex(random_bytes(3)), 'hAP', '7.14',
      'pk-' . bin2hex(random_bytes(4)), '10.66.' . random_int(1,250) . '.' . ord($kk), 'tech'));
  $ctxA->runUnscoped(fn($x) => (new DeviceRegistry($x))->assign($d['id'], $c['id'], null, "AP {$kk}"));
  $ctxA->run($c['id'], fn($x) => (new DeviceRegistry($x))->setCredentials($d['id'], 'dn-mgmt', "pw-{$kk}"));
  $ids[$kk] = ['c' => $c['id'], 'd' => $d['id']];
}
$P = $ids['P']; $Q = $ids['Q'];

function attempt(string $label, callable $fn): void {
  try { $r = $fn(); echo sprintf("  %-46s %s\n", $label, $r); }
  catch (Throwable $e) { echo sprintf("  %-46s DENIED (%s)\n", $label, substr(trim($e->getMessage()), 0, 46)); }
}

echo "A1  read Q's secret row from P's context\n";
attempt('  SELECT mt_device_secrets WHERE device_id=Q', fn() =>
  $ctx->run($P['c'], fn($db) => $db->one('SELECT username FROM mt_device_secrets WHERE device_id = ?', [$Q['d']]))
    ? 'DISCLOSED' : 'no rows');

echo "A2  decrypt Q's credential from P's context\n";
attempt('  DeviceRegistry::credentials(Q_device)', fn() =>
  ($x = $ctx->run($P['c'], fn($db) => (new DeviceRegistry($db))->credentials($Q['d'])))
    ? "DISCLOSED password={$x['password']}" : 'null');

echo "A3  claim Q's intent from P's context\n";
$ctx->run($Q['c'], fn($db) => (new IntentQueue($db))->enqueue($Q['c'], 'secret.work', ['confidential' => 'Q-payload']));
attempt('  IntentQueue::claim() as the request role', function () use ($ctx, $P, $app) {
  $got = $ctx->run($P['c'], fn($db) => (new IntentQueue($db))->claim('attacker', '5 minutes', 10));
  return $got ? 'DISCLOSED ' . count($got) . ' intent(s): ' . $got[0]['payload'] : 'no rows';
});

echo "A4  steal Q's device by assigning it to P, then read the secret\n";
attempt('  mt_device_assign(Q_device -> P) as request role', function () use ($ctx, $P, $Q, $app) {
  $ctx->run($P['c'], fn($db) => $db->one('SELECT * FROM mt_device_assign(?,?,?,?)',
      [$Q['d'], $P['c'], null, 'stolen']));
  return 'ASSIGNED';
});
attempt('  then credentials(Q_device) as P', fn() =>
  ($x = $ctx->run($P['c'], fn($db) => (new DeviceRegistry($db))->credentials($Q['d'])))
    ? "DISCLOSED password={$x['password']}" : 'null');

echo "A5  legitimate paths that MUST keep working\n";
attempt('  P reads its OWN credential', fn() =>
  ($x = $ctx->run($P['c'], fn($db) => (new DeviceRegistry($db))->credentials($P['d'])))
    ? "ok password={$x['password']}" : 'BROKEN null');
