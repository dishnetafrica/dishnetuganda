<?php
declare(strict_types=1);
chdir(dirname(__DIR__));
/**
 * hardware_diff.php — why the equipment screen and uCRM disagree.
 *
 *   php tools/hardware_diff.php
 *
 * READ-ONLY. It writes nothing to either side.
 *
 * There are two hardware lists and they are not the same thing:
 *
 *   kyc_devices.json   the plugin's own table, what the Hardware screen shows,
 *                      and what carries buy price and margin.
 *   uCRM products      what the ASSISTANT reads. Every price a customer is
 *                      quoted on WhatsApp comes from here, never from the
 *                      screen.
 *
 * Saving a row on the Hardware screen pushes its sell price INTO uCRM. So the
 * screen is the writer and uCRM is the follower — but only at the moment
 * somebody saves. Edit a price directly in uCRM and the screen will not know;
 * edit it on the screen and uCRM does not change until that row is saved.
 * Between those two events the two lists disagree, and the customer is told
 * whatever uCRM holds.
 *
 * CLI only.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/CrmApiClient.php';
require_once $root . '/lib/MediaLibrary.php';

$dataDir = getenv('DN_DATA_DIR') ?: getDataDir($root);
$config  = PluginConfig::load($root, $dataDir);
$store   = SqliteStore::create($dataDir);
$crm     = CrmApiClient::fromUcrm($root, $config);
if (!$crm->isConfigured()) { echo "\n  uCRM is not configured for this plugin.\n\n"; exit(2); }

$local = [];
foreach ((array)($store->load('kyc_devices.json') ?? []) as $h) {
    if (is_array($h)) $local[] = $h;
}
try { $remote = (array)($crm->get('products?limit=200') ?? []); }
catch (\Throwable $e) { echo "\n  Could not read uCRM products: " . $e->getMessage() . "\n\n"; exit(2); }

$fmt  = fn($v) => $v === null ? '—' : number_format((float)$v, 0);
$norm = fn(string $s) => MediaLibrary::key($s);

$byId = $byName = [];
foreach ($remote as $r) {
    if (!is_array($r)) continue;
    if (!empty($r['id']))   $byId[(int)$r['id']] = $r;
    if (!empty($r['name'])) $byName[$norm((string)$r['name'])] = $r;
}

echo "\n  THE ASSISTANT QUOTES FROM uCRM. THE SCREEN IS A SEPARATE LIST.\n\n";
printf("  %-30s %12s  %12s  %s\n", 'EQUIPMENT', 'SCREEN', 'uCRM', 'STATE');
echo "  " . str_repeat('-', 76) . "\n";

$problems = [];
$matchedIds = [];
foreach ($local as $h) {
    $title = (string)($h['title'] ?? '');
    $sell  = isset($h['sell_price']) ? (float)$h['sell_price'] : null;
    $pid   = (int)($h['ucrm_product_id'] ?? 0);

    // Linked id first, because a row can be renamed without breaking the link.
    $r = $pid > 0 ? ($byId[$pid] ?? null) : null;
    $how = $r ? ('#' . $pid) : '';
    if ($r === null) { $r = $byName[$norm($title)] ?? null; $how = $r ? 'by name' : ''; }

    if ($r === null) {
        printf("  %-30s %12s  %12s  NOT IN uCRM\n", mb_substr($title, 0, 30), $fmt($sell), '—');
        echo "  " . str_repeat(' ', 32) . "the assistant cannot quote this at all\n";
        $problems[] = $title . ' is not in uCRM, so the assistant never quotes it';
        continue;
    }
    $matchedIds[(int)($r['id'] ?? 0)] = true;
    $rp = isset($r['price']) ? (float)$r['price'] : null;

    if ($rp !== null && $sell !== null && abs($rp - $sell) >= 0.005) {
        printf("  %-30s %12s  %12s  DISAGREE (%s)\n", mb_substr($title, 0, 30), $fmt($sell), $fmt($rp), $how);
        echo "  " . str_repeat(' ', 32) . 'uCRM name: ' . (string)$r['name'] . "\n";
        echo "  " . str_repeat(' ', 32) . 'customers are told ' . $fmt($rp) . ", the screen shows " . $fmt($sell) . "\n";
        $problems[] = $title . ': screen ' . $fmt($sell) . ' vs uCRM ' . $fmt($rp)
                    . ' — customers hear ' . $fmt($rp);
    } else {
        printf("  %-30s %12s  %12s  agree (%s)\n", mb_substr($title, 0, 30), $fmt($sell), $fmt($rp), $how);
    }
}

$orphans = [];
foreach ($remote as $r) {
    if (!is_array($r)) continue;
    if (empty($matchedIds[(int)($r['id'] ?? 0)])) $orphans[] = $r;
}
if ($orphans) {
    echo "\n  IN uCRM BUT NOT ON THE SCREEN\n\n";
    foreach ($orphans as $r) {
        printf("    %-30s %12s   #%s\n", mb_substr((string)($r['name'] ?? '?'), 0, 30),
               $fmt($r['price'] ?? null), (string)($r['id'] ?? '?'));
    }
    echo "\n    The assistant CAN quote these — they are in the catalogue it reads —\n";
    echo "    but they do not appear on the screen, so nobody here sees them.\n";
    $problems[] = count($orphans) . ' product(s) are quotable by the assistant but missing from the screen';
}

// Taxability, because the VAT work depends on it and this is what undoes it.
echo "\n  TAX FLAG ON THE uCRM PRODUCTS\n\n";
$nonTaxable = 0;
foreach ($remote as $r) {
    if (!is_array($r)) continue;
    $t = $r['taxable'] ?? null;
    if (empty($t)) $nonTaxable++;
}
printf("    %d of %d product(s) are NOT marked taxable.\n", $nonTaxable, count($remote));
echo "    Saving a row on the Hardware screen pushes taxable = false to uCRM\n";
echo "    (includes/post/post_sync.php). So marking products taxable in uCRM for\n";
echo "    VAT will be undone the next time somebody saves that row, unless the\n";
echo "    push is changed first.\n";
if ($nonTaxable > 0) $problems[] = $nonTaxable . ' product(s) not taxable, and the screen re-asserts that on every save';

echo "\n  WHY THEY DRIFT\n\n";
echo "    Saving a row pushes ITS price into uCRM. Nothing pulls the other way,\n";
echo "    and nothing syncs on a schedule. So:\n\n";
echo "      edit in uCRM   -> the screen keeps showing the old number\n";
echo "      edit on screen -> uCRM keeps the old number until that row is saved\n\n";
echo "    Whichever it is, the customer hears the uCRM figure.\n";

if ($problems) {
    echo "\n  NEEDS A DECISION\n\n";
    foreach ($problems as $p) echo "    - " . $p . "\n";
    echo "\n  To make uCRM match the screen, open the row on the Hardware screen and\n";
    echo "  save it — that pushes its price. To make the screen match uCRM, edit the\n";
    echo "  row to the uCRM figure. Decide which number is right first.\n\n";
    exit(1);
}
echo "\n  The screen and uCRM agree.\n\n";
exit(0);
