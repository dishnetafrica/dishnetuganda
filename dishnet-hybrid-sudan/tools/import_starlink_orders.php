<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * import_starlink_orders.php — book Starlink orders as supplier purchases.
 *
 *   php tools/import_starlink_orders.php                       what it would do
 *   php tools/import_starlink_orders.php --file orders.json    from a saved API response
 *   php tools/import_starlink_orders.php --file orders.json --category 3 --commit
 *
 * A Starlink order is a supplier bill: kit and shipping lines, a tax figure,
 * and whether it has been paid. All of that was being fetched and thrown
 * away, so the first real purchase this business made was nowhere in its own
 * accounts.
 *
 * AN ORDER IS NOT STOCK. Four kits ordered is a commitment and a payable, not
 * four kits on a shelf — a warehouse that confuses the two promises a dish to
 * a customer three weeks before it lands. The bill is booked on order; the
 * unit appears only when a line reports delivered and names the serial that
 * arrived.
 *
 * Changes nothing without --commit.
 *
 * WHERE TO GET THE FILE: on the Starlink account page, open the browser's
 * network tab, find the orders/customer-account request and save its
 * RESPONSE — the JSON body alone. Not the request headers: those carry your
 * session cookie, which is a live login to your Starlink account and does not
 * belong in a file or a chat window.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
$GLOBALS['_PLUGIN_ROOT'] = $root;
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/StockService.php';
require_once $root . '/lib/StarlinkOrderImport.php';

$args = array_slice($argv, 1);
$val = function (string $f) use ($args): string {
    $i = array_search($f, $args, true);
    return ($i !== false && isset($args[$i + 1])) ? (string)$args[$i + 1] : '';
};
foreach ($args as $a) {
    if (strpos($a, '--') !== 0) continue;
    if (!in_array($a, ['--file', '--category', '--commit', '--supplier'], true)) {
        fwrite(STDERR, "\n  Unknown option: {$a}\n");
        fwrite(STDERR, "  Known: --file <json> --category <id> --supplier <name> --commit\n\n");
        exit(2);
    }
}

$dataDir = cliDataDir($root);
$store   = SqliteStore::create($dataDir);
$pdo     = $store->getPdo();
$config  = PluginConfig::load($root, $dataDir);
$stock   = StockService::fromStore($store, $dataDir);
$stock->ensureTables();

// ── Where the orders come from ──────────────────────────────────────────────
$file = trim($val('--file'));
$orders = []; $source = '';

if ($file !== '') {
    if (!is_file($file)) { fwrite(STDERR, "\n  No such file: {$file}\n\n"); exit(1); }
    $blob = (string)@file_get_contents($file);

    // Checked BEFORE the JSON is parsed. Somebody who saved the whole request
    // rather than the response has a file that is not valid JSON AND has a
    // live Starlink login in it — and "not valid JSON" is the less important
    // of those two facts by a long way.
    foreach (['Starlink.Com.Access', 'Starlink.Com.Sso', 'clientside-cookie'] as $marker) {
        if (stripos($blob, $marker) === false) continue;
        fwrite(STDERR, "\n  That file contains a Starlink SESSION COOKIE.\n\n");
        fwrite(STDERR, "  Save the response BODY only — the JSON that starts {\"content\": .\n");
        fwrite(STDERR, "  A cookie on disk is a live login to your Starlink account, and this\n");
        fwrite(STDERR, "  tool will not read one. Delete that file, and treat the session as\n");
        fwrite(STDERR, "  compromised: sign out of starlink.com everywhere and back in.\n\n");
        exit(1);
    }

    $raw = json_decode($blob, true);
    if (!is_array($raw)) {
        fwrite(STDERR, "\n  {$file} is not valid JSON.\n\n");
        fwrite(STDERR, "  It should be the RESPONSE body on its own — starting {\"content\": —\n");
        fwrite(STDERR, "  with no request headers above it.\n\n");
        exit(1);
    }
    $orders = StarlinkOrderImport::fromRawApi($raw);
    $source = $file . ' (full API response — serials included)';
} else {
    require_once $root . '/lib/SiblingPlugin.php';
    $dr = SiblingPlugin::readJson('dishnet-data-report', 'dr_orders.json');
    if ($dr === null) {
        echo "\n  No --file given, and dishnet-data-report has no dr_orders.json.\n\n";
        echo "  That plugin HAS a cron_orders.php which fetches exactly this, but it is\n";
        echo "  not registered in its manifest, so it has never run. Either run it once:\n\n";
        echo "    docker exec -u nginx ucrm php /data/ucrm/data/plugins/dishnet-data-report/cron_orders.php\n\n";
        echo "  or save the API response yourself and pass --file. The saved response is\n";
        echo "  better: that cron drops serial numbers and shipping lines.\n\n";
        exit(1);
    }
    $orders = StarlinkOrderImport::fromDrOrders($dr);
    $source = 'dr_orders.json (no serials, no shipping lines)';
}

if ($orders === []) { echo "\n  No orders found in {$source}.\n\n"; exit(1); }

$catId  = (int)$val('--category');
$commit = in_array('--commit', $args, true);
$imp    = new StarlinkOrderImport($pdo, $dataDir, $config);
$actor  = ['id' => 0, 'name' => 'order import'];

$r = $imp->import($orders, $actor, [
    'commit'      => $commit,
    'category_id' => $catId,
    'supplier'    => trim($val('--supplier')) ?: '',
]);

echo "\n  STARLINK ORDERS → PURCHASES" . ($commit ? '' : '   (dry run — nothing written)') . "\n";
printf("  source: %s\n", $source);
echo "  " . str_repeat('─', 72) . "\n\n";

foreach ($r['planned'] as $p) {
    printf("    %-26s %s %14s   tax %s (%s%%)   %s\n",
        $p['order'], $p['currency'], number_format($p['total'], 0),
        number_format($p['tax'], 0), rtrim(rtrim(number_format($p['rate'], 2), '0'), '.'),
        $p['paid'] ? 'paid' : 'UNPAID');
    printf("      %s · ordered %s · %s\n", $p['invoice'] ?: '(no invoice number)',
        $p['ordered_on'] ?: '?', $p['status']);
    if ($p['serials'] !== []) {
        printf("      delivered: %s\n", implode(', ', $p['serials']));
    }
    // The supplier's own arithmetic. A rate that is not 18 on a Uganda bill
    // is worth a look before it reaches a VAT return — and it is also how a
    // dropped line shows itself.
    if ($p['rate'] > 0 && abs($p['rate'] - 18.0) > 0.01) {
        printf("      tax works out at %.2f%% of the lines, not 18%%\n", $p['rate']);
    }
    echo "\n";
}

echo "\n";
printf("    %-22s %d\n", $commit ? 'purchases booked' : 'purchases to book', $r['purchases']);
printf("    %-22s %d\n", 'already booked', $r['skipped']);
printf("    %-22s %d\n", $commit ? 'units received' : 'units to receive', $r['units']);

if ($r['notes'] !== []) {
    echo "\n  NOTES\n";
    foreach ($r['notes'] as $n) echo "    · {$n}\n";
}
if ($r['errors'] !== []) {
    echo "\n  ERRORS\n";
    foreach ($r['errors'] as $e) echo "    ✗ {$e}\n";
}

echo "\n";
if (!$commit) {
    echo "  Nothing was written. To go ahead:\n\n";
    echo "    php tools/import_starlink_orders.php"
       . ($file !== '' ? ' --file ' . escapeshellarg($file) : '')
       . ($catId > 0 ? ' --category ' . $catId : ' --category <stock category id>')
       . " --commit\n\n";
    if ($catId <= 0) {
        // A delivered kit has to become something on the shelf, and making the
        // person go and look the number up somewhere else is how they end up
        // guessing it.
        $cats = [];
        foreach ($stock->getCategories(true) as $c) {
            if (($c['track_mode'] ?? '') === 'serial') $cats[] = $c;
        }
        if ($cats === []) {
            echo "  --category says which stock item a delivered kit becomes, and this\n";
            echo "  install has no serial-tracked stock category yet. Create one on the\n";
            echo "  Stock screen first (a Starlink kit is tracked by serial).\n\n";
        } else {
            echo "  --category says which stock item a delivered kit becomes:\n\n";
            foreach ($cats as $c) {
                printf("    %-4s %-36s %s\n", $c['id'], $c['title'], $c['sku'] ?? '');
            }
            echo "\n";
        }
    }
    exit(0);
}
if ($r['errors'] !== []) {
    echo "  Finished with errors — the orders listed above under ERRORS were NOT booked.\n\n";
    exit(1);
}
echo "  Done. Check it:  php tools/report.php\n\n";
exit(0);
