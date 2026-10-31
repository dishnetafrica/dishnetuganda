<?php
// 5.18.102's rehearsal: the rows its catalogue checks read — the plans and kits the KYC form offers (subscription_plans,
// kyc_devices), the retailer app's packages (kyc_packages) and the applications its notes, QL-LK1's window and QL-RB2
// and QL-RB3 count (kyc_applications) — added to the store through the INSTALLED plugin's own writer,
// SqliteStore::appendWithId(), as the plan, kit and KYC pages add theirs. Synthetic: every row marked "_rh102": true,
// every name a marked secret of the rehearsal's (RH102SECRET-…), every price a figure the sweeps look for:
//   php rh102-seed.php <plugin root> <data dir> <rows as JSON: {"subscription_plans.json": [...], …}> [time zone]
// A created_at, submitted_at, updated_at, quote_refused_at or quote_unconfirmed_at given as "rel:<strtotime phrase>" is
// written as that time in the zone given — the plugin stamps them with a local, zone-less date('Y-m-d H:i:s') (the
// server's own zone, Africa/Kampala) — UTC when none is given, so that "the last 90 days", the plan and kit dates and
// the window's own times hold whatever day the rehearsal runs; a quote_refused_ts or quote_make_claim_ts given as
// "rel:<phrase>" is written as that epoch, as recordNoQuote and Make the quotation write theirs (time()). The link store
// is never written here: links go through the release's own writer, rh102-links.php. Refuses any other collection, any
// row without the marker, an unknown zone and a run without a vault of its own. Prints how many rows were added as
// JSON, nothing else; never a row, a name or a price.
declare(strict_types=1);
[$_, $root, $data, $rows, $zone] = $argv + [null, '', '', '', 'UTC'];
if ((string)getenv('DN_VAULT_FILE') === '') { fwrite(STDERR, "refusing: DN_VAULT_FILE must point into the sandbox\n"); exit(2); }
if (!is_file($root . '/lib/SqliteStore.php') || !is_file($data . '/plugin.sqlite3')) { fwrite(STDERR, "refusing: no installed plugin or no store\n"); exit(2); }
if (!@date_default_timezone_set((string)$zone)) { fwrite(STDERR, "refusing: an unknown time zone\n"); exit(2); }
$all = json_decode((string)$rows, true);
if (!is_array($all) || $all === []) { fwrite(STDERR, "refusing: no rows given\n"); exit(2); }
$files = ['subscription_plans.json', 'kyc_devices.json', 'kyc_packages.json', 'kyc_applications.json'];
foreach ($all as $file => $list) {
    if (!in_array($file, $files, true) || !is_array($list)) { fwrite(STDERR, "refusing: only the catalogue checks' collections\n"); exit(2); }
    foreach ($list as $r) {
        if (!is_array($r) || ($r['_rh102'] ?? null) !== true) { fwrite(STDERR, "refusing: a rehearsal row carries \"_rh102\": true\n"); exit(2); }
    }
}
foreach (['bootstrap_data', 'StoreInterface', 'JsonStore', 'SqliteStore'] as $l) require_once $root . '/lib/' . $l . '.php';
$GLOBALS['dataDir'] = $data;   // as the plugin's own bootstrap sets it
$s = SqliteStore::create($data);
$n = 0;
try {
    foreach ($all as $file => $list) {
        foreach ($list as $r) {
            foreach (['created_at', 'submitted_at', 'updated_at', 'quote_refused_at', 'quote_unconfirmed_at', 'quote_refused_ts', 'quote_make_claim_ts'] as $k) {
                if (is_string($r[$k] ?? null) && strpos($r[$k], 'rel:') === 0) {
                    $t = strtotime(substr($r[$k], 4));
                    if ($t === false) { fwrite(STDERR, "refusing: a relative time strtotime cannot read\n"); exit(2); }
                    $r[$k] = substr($k, -3) === '_ts' ? $t : date('Y-m-d H:i:s', $t);
                }
            }
            $s->appendWithId($file, $r);
            $n++;
        }
    }
} catch (Throwable $e) { fwrite(STDERR, 'refused after ' . $n . ': ' . get_class($e) . "\n"); exit(3); }
echo json_encode(['rows' => $n]), "\n";
