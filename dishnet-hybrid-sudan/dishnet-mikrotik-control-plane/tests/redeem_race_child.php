<?php
declare(strict_types=1);
/**
 * One contender in the redemption race. Spawned by test_vouchers.php.
 *
 * argv: <code> <start-unix-micros>
 *
 * Every child connects, then spins until the agreed instant before firing, so
 * they genuinely collide instead of arriving in a queue. Prints WON or LOST.
 */
require __DIR__ . '/../src/autoload.php';
use Dn\Db\Database;
use Dn\Vouchers\VoucherService;

[$code, $startAt] = [$argv[1], (float) $argv[2]];
$db = Database::app();            // connect BEFORE the start instant
$svc = new VoucherService($db);

while (microtime(true) < $startAt) { /* spin — sleeping would blur the moment */ }

try {
    $r = $svc->redeem($code);
    echo $r === null ? "LOST\n" : "WON {$r['voucher_id']}\n";
} catch (Throwable $e) {
    echo 'ERR ' . $e->getMessage() . "\n";
}
