<?php
declare(strict_types=1);
require __DIR__ . '/../src/autoload.php';

use Dn\Db\Database;
use Dn\Delivery\NullDelivery;
use Dn\Intents\IntentQueue;
use Dn\Jobs\IntentWorker;
use Dn\Tenancy\TenantContext;

$db = Database::app();
$q  = new IntentQueue($db);
$w  = new IntentWorker($db, new TenantContext($db), $q, new NullDelivery(),
                       gethostname() . ':' . getmypid());

$once = in_array('--once', $argv, true);
do {
    $expired = $q->expireOverdue();
    $r = $w->runOnce();
    if ($r['claimed'] || $expired) {
        fwrite(STDOUT, json_encode($r + ['expired' => $expired]) . "\n");
    }
    if (!$once) { sleep(5); }
} while (!$once);
