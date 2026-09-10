<?php
/**
 * test_alert_cooldown_record.php — the handoff cooldown must actually be saved.
 *
 * Every time a conversation was handed to a human, three warnings appeared in
 * the AI trace:
 *
 *   Undefined array key "records" in lib/SqliteStore.php on line 766
 *   foreach() argument must be of type array|object, null given ... line 783
 *   Undefined array key "result" ... line 823
 *
 * withLock() runs a read-modify-write and expects the callback to return
 * ['records' => ..., 'result' => ...]. AlertService::recordSent returned the
 * bare array, so SqliteStore read a missing 'records' key, iterated null, and
 * wrote nothing.
 *
 * The warnings were the visible half. The real damage was silent: the cooldown
 * record was never stored, so the very thing that stops the same alert going
 * out again was a no-op. It looked like it worked because nothing threw.
 *
 * This asserts the round trip, not the return shape — a callback could satisfy
 * the contract and still save nothing.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/AlertService.php';

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

$tmp = sys_get_temp_dir() . '/dn_alert_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0755, true);

$store  = SqliteStore::create($tmp);
$alerts = new AlertService($store, []);

$record = new ReflectionMethod('AlertService', 'recordSent');  $record->setAccessible(true);
$last   = new ReflectionMethod('AlertService', 'lastSent');    $last->setAccessible(true);

echo "\nA recorded alert can be read back\n";
// Warnings become failures here: the bug announced itself in the trace for a
// whole day and was scrolled past, so this test refuses to scroll past them.
$warnings = [];
set_error_handler(function ($no, $str) use (&$warnings) { $warnings[] = $str; return true; });

$record->invoke($alerts, 'conv:109:handoff', 1789000000);
$got = (int)$last->invoke($alerts, 'conv:109:handoff');

restore_error_handler();

is_($got === 1789000000, 'the timestamp survives the write',
    'read back ' . $got . ' — a lost cooldown means the alert repeats');
is_($warnings === [], 'and it writes without a single warning',
    implode(' | ', $warnings));

echo "\nUpdating an existing key replaces it rather than duplicating\n";
$record->invoke($alerts, 'conv:109:handoff', 1789000900);
is_((int)$last->invoke($alerts, 'conv:109:handoff') === 1789000900,
    'the newer timestamp wins');
$rows = $store->load(AlertService::LOCK_FILE);
$mine = array_filter((array)$rows, function ($r) { return ($r['key'] ?? '') === 'conv:109:handoff'; });
is_(count($mine) === 1, 'and there is still exactly one row for that key',
    count($mine) . ' rows — duplicates would make lastSent() order-dependent');

echo "\nA second key does not disturb the first\n";
$record->invoke($alerts, 'conv:127:handoff', 1789001000);
is_((int)$last->invoke($alerts, 'conv:109:handoff') === 1789000900,
    'the earlier key is still readable');
is_((int)$last->invoke($alerts, 'conv:127:handoff') === 1789001000,
    'and so is the new one');

foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f);
@rmdir($tmp);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
