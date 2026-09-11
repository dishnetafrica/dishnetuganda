<?php
/**
 * test_hardware_diff.php — "why its showing different in ucrm and plugin".
 *
 * There are two hardware lists. The Hardware screen (kyc_devices.json) is
 * what staff look at. uCRM products is what the assistant quotes from. A
 * customer on WhatsApp is told whatever uCRM holds, so a screen that shows
 * a different number is not cosmetic — it is the whole company believing a
 * price that nobody is being quoted.
 *
 * Two failures this pins down, both taken from the live Uganda catalogue:
 *
 *   1. The screen showed 2,749,000 for the Standard KIT. uCRM held 2,649,000
 *      for the kit and 2,749,000 for the PACKAGE. That is not drift — drift
 *      is a number nobody recognises. It is one row carrying another
 *      product's price, and saying so is the difference between "these
 *      disagree" and knowing which figure came from where.
 *
 *   2. Two runs an hour apart saw five products and then three: both package
 *      products had left uCRM. Nothing reported it, because the tool had no
 *      memory. It took comparing two old terminal windows to notice, and a
 *      product leaving uCRM silently means the assistant stops being able to
 *      quote something the screen still advertises.
 */
declare(strict_types=1);

$root = dirname(__DIR__);

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   $m\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

$tmp = sys_get_temp_dir() . '/dn_hwdiff_' . bin2hex(random_bytes(4));
@mkdir($tmp . '/data', 0777, true);

// ── Fake uCRM ───────────────────────────────────────────────────────────────
$router = $root . '/tests/fixtures/fake_ucrm_server.php';
$hit = function (int $port, string $p) {
    $ch = curl_init("http://127.0.0.1:{$port}{$p}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_PROXY => '']);
    $r = curl_exec($ch); curl_close($ch);
    return $r === false ? null : (string)$r;
};
$srv = null; $port = 0;
foreach (range(0, 9) as $slot) {
    $cand = 9880 + ((getmypid() + $slot * 17) % 70);
    $p = proc_open(sprintf('exec php -S 127.0.0.1:%d %s', $cand, escapeshellarg($router)),
                   [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    $ours = false;
    for ($i = 0; $i < 40; $i++) {
        $got = $hit($cand, '/__test/state');
        if ($got !== null) { $ours = strpos($got, 'FAKE-UCRM-TEST') !== false; break; }
        usleep(100000);
    }
    if ($ours) { $srv = $p; $port = $cand; break; }
    proc_terminate($p); proc_close($p);
}
if ($port === 0) { fwrite(STDERR, "could not start the fake uCRM server\n"); exit(1); }
$scenario = function (string $name) use ($hit, $port) { $hit($port, '/__test/scenario?name=' . $name); };

// ── The plugin's own hardware table ─────────────────────────────────────────
// Exactly the four rows the Uganda screen carries, including the Standard
// Kit priced at the PACKAGE figure, which is the bug being diagnosed.
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';

$dataDir = $tmp . '/data';
$store   = SqliteStore::create($dataDir);
$store->save('kyc_devices.json', [
    ['id' => 11, 'title' => 'Starlink Mini Kit',         'sell_price' => 2249000, 'type' => 'starlink', 'ucrm_product_id' => 1],
    ['id' => 12, 'title' => 'Starlink Standard Kit',     'sell_price' => 2749000, 'buy_price' => 2144704, 'type' => 'starlink', 'ucrm_product_id' => 2],
    ['id' => 13, 'title' => 'Professional Installation', 'sell_price' =>  150000, 'type' => 'starlink', 'ucrm_product_id' => 3],
    ['id' => 14, 'title' => 'Starlink Mini Package',     'sell_price' => 2399000, 'type' => 'starlink', 'ucrm_product_id' => 0],
]);
file_put_contents($dataDir . '/config.json', json_encode([
    'crm_base_url' => "http://127.0.0.1:{$port}", 'crm_auth_token' => 'TESTKEY',
]));

// Read what is actually persisted, not what this process has cached. The
// tool writes from its OWN process, so a cached blob here would report the
// value from before it ran and every assertion below would be vacuous.
$onDisk = function (int $id) use ($dataDir) {
    $pdo = new PDO('sqlite:' . $dataDir . '/plugin.sqlite3');
    foreach ($pdo->query('SELECT data FROM kyc_devices') as $row) {
        $r = json_decode((string)$row['data'], true);
        if (is_array($r) && (int)($r['id'] ?? 0) === $id) return $r;
    }
    return null;
};
$rowCount = function () use ($dataDir) {
    $pdo = new PDO('sqlite:' . $dataDir . '/plugin.sqlite3');
    return (int)$pdo->query('SELECT COUNT(*) FROM kyc_devices')->fetchColumn();
};

$run = function (string $args = '') use ($root, $dataDir) {
    $cmd = 'DN_DATA_DIR=' . escapeshellarg($dataDir) . ' php '
         . escapeshellarg($root . '/tools/hardware_diff.php') . ' ' . $args . ' 2>&1';
    return (string)shell_exec($cmd);
};

// ── A row priced from a different product ───────────────────────────────────
echo "\nA price that belongs to another product is named, not called drift\n";

$scenario('catalogue_five');
$out = $run();

is_(strpos($out, 'DISAGREE') !== false, 'the Standard Kit row is flagged');
is_(strpos($out, 'customers are told 2,649,000') !== false,
    'and it says which figure the customer actually hears',
    'the screen number is the one staff trust, and it is not the quoted one');
is_(strpos($out, 'is exactly the uCRM price of "Starlink Standard Package"') !== false,
    'the screen price is traced to the product it came from',
    "2,749,000 is the PACKAGE price sitting on the KIT row — knowing that is the fix");
is_(strpos($out, 'Starlink Mini Kit') !== false && strpos($out, 'agree') !== false,
    'a row that matches is reported as agreeing');

// A baseline run has nothing to compare against and must say so rather than
// invent a change.
is_(strpos($out, 'No previous check to compare against') !== false,
    'the first run says it is only a baseline',
    'claiming nothing changed on a first run is a claim it cannot support');
is_(strpos($out, 'REMOVED') === false, 'and reports nothing as removed');

// ── A product leaving uCRM ──────────────────────────────────────────────────
echo "\nA product that disappears from uCRM is caught on the next run\n";

$scenario('catalogue_three');
$out2 = $run();

is_(strpos($out2, 'CHANGED IN uCRM SINCE') !== false, 'the run compares against the last one');
is_(strpos($out2, 'REMOVED') !== false && strpos($out2, 'Starlink Mini Package') !== false,
    'the Mini Package is reported as removed',
    'five products became three and the earlier tool said nothing at all');
is_(substr_count($out2, 'REMOVED') === 2,
    'both packages are reported, not just the first');
is_(strpos($out2, 'could quote this before and cannot now') !== false,
    'and it says what that costs',
    'a removed product is a quote the assistant can no longer give');

// The screen still lists the Mini Package, so it must also show as unquotable.
is_(strpos($out2, 'NOT IN uCRM') !== false,
    'the screen row with no uCRM product behind it is flagged');
is_(strpos($out2, 'the assistant cannot quote this at all') !== false,
    'and named as unquotable');

// ── A price changing in uCRM ────────────────────────────────────────────────
echo "\nA price changed directly in uCRM is caught too\n";

$scenario('catalogue_repriced');
$out3 = $run();
is_(strpos($out3, 'REPRICED') !== false, 'a changed uCRM price is reported');
is_(strpos($out3, '2,649,000 → 2,749,000') !== false,
    'with both figures',
    'saying a price moved without saying to what is not actionable');
is_(strpos($out3, 'REMOVED') === false,
    'and nothing is re-reported as removed a second time',
    'the packages went missing in the PREVIOUS run, not this one');

// Now that uCRM agrees with the screen, the Standard Kit row must stop being
// flagged — otherwise the tool cries wolf and gets ignored.
is_(strpos($out3, 'Starlink Standard Kit') !== false
    && preg_match('/Starlink Standard Kit\s+2,749,000\s+2,749,000\s+agree/', $out3) === 1,
    'and the row that now matches reads as agreeing',
    'a tool that still complains after the fix is one nobody reads');

// ── The tax flag, which is what undoes the VAT work ─────────────────────────
echo "\nThe taxable flag is reported on whatever uCRM currently holds\n";

$scenario('catalogue_taxable');
$out4 = $run();
is_(strpos($out4, '0 of 3 product(s) are NOT marked taxable') !== false,
    'products marked taxable in uCRM are counted as such',
    'the count must follow uCRM, not a fixed expectation');
is_(strpos($out4, 'pushes taxable = false') !== false,
    'and it still warns that saving a row undoes it',
    'this is the thing that will silently revert the VAT setup');

// ── It changes nothing on either side ───────────────────────────────────────
echo "\nIt is a diagnosis, not an edit\n";

is_((float)$onDisk(12)['sell_price'] === 2749000.0,
    'the screen price is left exactly as it was',
    'a tool that quietly "fixes" a price removes the decision from the people who own it');
is_($rowCount() === 4, 'and no row is added or removed');

$src = (string)file_get_contents($root . '/tools/hardware_diff.php');
is_(preg_match('/->(post|patch|put|delete)\s*\(/i', $src) !== 1,
    'and it never writes to uCRM',
    'read-only is the property that makes it safe to run on production');

// Its own snapshot is the one thing it does write, and it must live in the
// data dir — beside the plugin, so a uCRM upgrade does not erase the history.
is_(is_file($dataDir . '/hardware_diff_last.json'),
    'the snapshot it keeps is in the data directory');

// ── Adopting the uCRM price ─────────────────────────────────────────────────
//
// The Standard Kit was the real decision: the screen said 2,749,000, uCRM
// said 2,649,000, and 2,649,000 was the right number. uCRM already held it,
// so the fix is one-directional — correct the screen, touch nothing a
// customer sees. That is the only direction safe to automate, and the tool
// must not be able to go the other way.
echo "\nAdopting the uCRM price corrects the screen and nothing else\n";

$scenario('catalogue_five');

$dry = $run('--adopt-ucrm');
is_(strpos($dry, 'WOULD ADOPT') !== false, 'the flag alone only shows what it would do');
is_(strpos($dry, '2,749,000 → 2,649,000') !== false, 'naming both figures');
is_(strpos($dry, 'Add --yes to apply') !== false, 'and says how to apply it');

is_((float)$onDisk(12)['sell_price'] === 2749000.0,
    'and a dry run changes nothing',
    'a tool whose preview writes is a tool nobody can preview with');

$done = $run('--adopt-ucrm --yes');
is_(strpos($done, '1 row(s) updated') !== false, '--yes applies it');
is_(strpos($done, 'uCRM was not written to') !== false, 'and says uCRM was left alone');

is_((float)$onDisk(12)['sell_price'] === 2649000.0,
    'the screen now shows the price customers are actually quoted',
    'this was the whole point: the screen was telling staff 2,749,000');
is_((float)$onDisk(12)['buy_price'] === 2144704.0,
    'and the cost is untouched',
    'cost and margin are the plugin\'s own — uCRM has nowhere to hold them');
is_($rowCount() === 4, 'no row is added or lost');
is_((float)$onDisk(11)['sell_price'] === 2249000.0, 'a row that already agreed is left as it was');

// And having adopted, the plain run is clean on that row.
$after = $run();
is_(preg_match('/Starlink Standard Kit\s+2,649,000\s+2,649,000\s+agree/', $after) === 1,
    're-running shows them agreeing',
    'the fix must actually close the finding it was offered for');

is_(strpos($run('--adopt-ucrm'), 'Nothing to adopt') !== false,
    'and there is then nothing left to adopt');

// --only scopes to one row, because one decision is not a blanket policy.
echo "\n--only scopes the change to a single row\n";
$store->save('kyc_devices.json', [
    ['id' => 11, 'title' => 'Starlink Mini Kit',         'sell_price' => 9999999, 'type' => 'starlink', 'ucrm_product_id' => 1],
    ['id' => 12, 'title' => 'Starlink Standard Kit',     'sell_price' => 2749000, 'type' => 'starlink', 'ucrm_product_id' => 2],
    ['id' => 13, 'title' => 'Professional Installation', 'sell_price' =>  150000, 'type' => 'starlink', 'ucrm_product_id' => 3],
]);
$one = $run('--adopt-ucrm --yes --only ' . escapeshellarg('Starlink Standard Kit'));
is_(strpos($one, '1 row(s) updated') !== false, 'only one row is written');
is_((float)$onDisk(12)['sell_price'] === 2649000.0, 'the named row is corrected');
is_((float)$onDisk(11)['sell_price'] === 9999999.0,
    'and the other disagreeing row is deliberately left alone',
    'scoping that silently widens is worse than no scoping');

is_(strpos($run('--adopt-ucrm --only ' . escapeshellarg('No Such Product')), 'matches no row') !== false,
    'a name that matches nothing is refused rather than treated as all rows',
    'a typo must never become "adopt everything"');

proc_terminate($srv); proc_close($srv);
exec('rm -rf ' . escapeshellarg($tmp));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
