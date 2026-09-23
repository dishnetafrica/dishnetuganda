<?php
declare(strict_types=1);
require __DIR__ . '/../src/autoload.php';

use Dn\Db\Database;
use Dn\Intents\IntentQueue;
use Dn\Jobs\IntentWorker;
use Dn\Runtime\Bindings;
use Dn\Tenancy\TenantContext;

// The WORKER role: it may call the cross-customer claim primitive, which the
// request-path role may not (docs/57 §10).
$db = Database::worker();
$q  = new IntentQueue($db);

// The delivery binding comes from the environment and NEVER falls back
// (docs/118 D-7): DN_DELIVERY unset → nothing is delivered; 'simulated' → an
// in-memory router that says so; 'routeros' → the real adapter, which needs
// the F6-B gate and throws here, before any work is claimed, without it.
$bindings = Bindings::fromEnvironment();
$delivery = $bindings->delivery();

// The binding's name travels in the worker id, so every claim and every
// intent.confirmed / intent.failed audit row says which world produced it.
$workerId = gethostname() . ':' . getmypid() . ':' . $delivery->bindingName();
fwrite(STDERR, json_encode(['worker' => $workerId, 'bindings' => $bindings->describe()]) . "\n");

$w = new IntentWorker($db, new TenantContext($db), $q, $delivery, $workerId);

$once = in_array('--once', $argv, true);
do {
    $expired = $q->expireOverdue();
    $r = $w->runOnce();
    if ($r['claimed'] || $expired) {
        fwrite(STDOUT, json_encode($r + ['expired' => $expired]) . "\n");
    }
    if (!$once) { sleep(5); }
} while (!$once);
