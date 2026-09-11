<?php
/**
 * test_store_cache.php — a write must be visible to the next read.
 *
 * load() caches five blobs for the life of the process. The invalidation was
 * three separate function-scoped statics all named $_loadCache — one each in
 * load(), save() and updateOne(). A function-scoped static belongs to that
 * function alone, so save() and updateOne() were clearing their own
 * permanently-empty arrays while load()'s cache was never cleared by
 * anything. append(), appendWithId() and withLock() did not even try.
 *
 * So inside one process, writing a price and reading it back gave the price
 * from before the write. A web request mostly hides this behind the redirect
 * that follows a save. A worker or a cron does not: it lives for thousands of
 * records, and every read after its first write was stale for the rest of the
 * run.
 *
 * kyc_devices.json is one of the five, which is how this surfaced — a tool
 * corrected a hardware price, the write succeeded, and reading it back in the
 * same process returned the old number.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   $m\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

$tmp = sys_get_temp_dir() . '/dn_cache_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);
$store = SqliteStore::create($tmp);

$price = function (array $rows, int $id) {
    foreach ($rows as $r) { if ((int)($r['id'] ?? 0) === $id) return (float)($r['sell_price'] ?? -1); }
    return -1.0;
};

// kyc_devices.json is cached. Every assertion below would pass trivially on an
// uncached blob, so the cached one is the one worth testing.
echo "\nsave() is visible to the next load()\n";

$store->save('kyc_devices.json', [['id' => 1, 'title' => 'Starlink Standard Kit', 'sell_price' => 2749000]]);
is_($price((array)$store->load('kyc_devices.json'), 1) === 2749000.0, 'the first read is what was written');

$store->save('kyc_devices.json', [['id' => 1, 'title' => 'Starlink Standard Kit', 'sell_price' => 2649000]]);
is_($price((array)$store->load('kyc_devices.json'), 1) === 2649000.0,
    'and a second save is visible immediately',
    'this returned 2,749,000 — the price from before the write');

echo "\nupdateOne() is visible to the next load()\n";
$store->updateOne('kyc_devices.json', 'id', 1, ['sell_price' => 2599000]);
is_($price((array)$store->load('kyc_devices.json'), 1) === 2599000.0,
    'the updated field is read back',
    'a cron that updates then re-reads would loop on stale data');

echo "\nappend() and appendWithId() are visible to the next load()\n";
$store->append('kyc_devices.json', ['id' => 2, 'title' => 'Starlink Mini Kit', 'sell_price' => 2249000]);
$after = (array)$store->load('kyc_devices.json');
is_(count($after) === 2, 'an appended row is there', 'count was ' . count($after));
is_($price($after, 2) === 2249000.0, 'with its values');

$store->appendWithId('kyc_devices.json', ['title' => 'Professional Installation', 'sell_price' => 150000]);
is_(count((array)$store->load('kyc_devices.json')) === 3, 'and appendWithId too');

echo "\nwithLock() is visible to the next load()\n";
$store->withLock('kyc_devices.json', function (array $records) {
    foreach ($records as $i => $r) {
        if ((int)($r['id'] ?? 0) === 1) $records[$i]['sell_price'] = 2649000;
    }
    return ['records' => $records, 'result' => true];
});
is_($price((array)$store->load('kyc_devices.json'), 1) === 2649000.0,
    'a locked read-modify-write is read back',
    'withLock never invalidated at all — not on entry, not on commit');

// The callback itself may call load(), which would repopulate the cache from
// the snapshot it is reading INSIDE the transaction. The post-commit clear is
// what makes that safe.
$store->withLock('kyc_devices.json', function (array $records) use ($store) {
    $store->load('kyc_devices.json');          // repopulates mid-transaction
    foreach ($records as $i => $r) {
        if ((int)($r['id'] ?? 0) === 1) $records[$i]['sell_price'] = 2500000;
    }
    return ['records' => $records, 'result' => true];
});
is_($price((array)$store->load('kyc_devices.json'), 1) === 2500000.0,
    'even when the callback reads during the transaction',
    'clearing only on entry leaves the pre-commit snapshot cached');

echo "\nTwo stores on different directories are not one cache\n";
$other = sys_get_temp_dir() . '/dn_cache_b_' . bin2hex(random_bytes(4));
@mkdir($other, 0777, true);
$store2 = SqliteStore::create($other);
$store2->save('kyc_devices.json', [['id' => 1, 'title' => 'Elsewhere', 'sell_price' => 111]]);
is_($price((array)$store2->load('kyc_devices.json'), 1) === 111.0, 'the second store reads its own data');
is_($price((array)$store->load('kyc_devices.json'), 1) === 2500000.0,
    'and the first is unaffected',
    'a cache keyed on file name alone would serve one directory\'s data for the other');

// The bug was a name collision between statics, so the name must not come back.
$src = (string)file_get_contents($root . '/lib/SqliteStore.php');
is_(strpos($src, 'static $_loadCache') === false,
    'there is no function-scoped cache static left',
    'that is the exact shape of the original bug');
is_(substr_count($src, 'private static array $loadCache') === 1,
    'and exactly one cache belongs to the class');


// ── Keyed caches keep their keys ────────────────────────────────────────────
//
// save() auto-detects an assoc array and stores it as one flat row. load()
// only takes the flat path for a table in $FLAT_TABLES — for any other table
// array_values() strips the keys, so the caller gets a list where it saved a
// dictionary. ucrm_invoice_payments_cache is keyed by invoice id and was
// losing them: $cache[$invoiceId] never matched, the portal's receipt cache
// never hit, and every request went back to uCRM. It looked like it worked.
echo "\nKeyed caches survive a round trip\n";
$kt = sys_get_temp_dir() . '/dn_keyed_' . bin2hex(random_bytes(4));
@mkdir($kt, 0777, true);
$ks = SqliteStore::create($kt);

$ks->save('ucrm_invoice_payments_cache.json', [
    '5001' => ['payments' => [['id' => 900, 'amount' => 2649000]]],
    '5002' => ['payments' => []],
]);
$back = $ks->load('ucrm_invoice_payments_cache.json');
// PHP casts a numeric string key to an int on the way into an array, so the
// keys read back as 5001 rather than '5001'. Either form indexes the same
// slot — what matters is that they are invoice ids and not 0 and 1.
is_(array_map('strval', array_keys($back)) === ['5001', '5002'],
    'the invoice ids come back as keys, not positions',
    'got: ' . json_encode(array_keys($back)));
is_((float)($back['5001']['payments'][0]['amount'] ?? 0) === 2649000.0,
    'and a receipt is reachable by its invoice id, looked up as a string');

// Every table declared flat must survive the same trip, so the list and the
// reader cannot drift apart.
foreach (['kyc_config', 'email_settings', 'hotspot_config'] as $tbl) {
    $ks->save($tbl . '.json', ['a-key' => ['v' => 1], 'b-key' => ['v' => 2]]);
    $r = $ks->load($tbl . '.json');
    is_(array_keys($r) === ['a-key', 'b-key'], "{$tbl} keeps its keys",
        'got: ' . json_encode(array_keys($r)));
}

// A record array is still a record array — the flat path must not swallow it.
$ks->save('retailers.json', [['id' => 1, 'name' => 'One'], ['id' => 2, 'name' => 'Two']]);
$rl = $ks->load('retailers.json');
is_(count($rl) === 2 && ($rl[0]['name'] ?? '') === 'One',
    'and a list of records still reads back as a list');

exec('rm -rf ' . escapeshellarg($kt));


exec('rm -rf ' . escapeshellarg($tmp) . ' ' . escapeshellarg($other));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);