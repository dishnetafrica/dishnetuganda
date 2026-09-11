<?php
declare(strict_types=1);
chdir(dirname(__DIR__));
/**
 * hardware_diff.php — why the equipment screen and uCRM disagree.
 *
 *   php tools/hardware_diff.php                     diagnose, change nothing
 *   php tools/hardware_diff.php --adopt-ucrm        show what adopting would do
 *   php tools/hardware_diff.php --adopt-ucrm --yes  set the screen to the uCRM price
 *   ... --only "Starlink Standard Kit"              just that one row
 *
 * --adopt-ucrm writes ONLY to the plugin's own table. It never writes to
 * uCRM, so it can never change what a customer is quoted — it makes the
 * screen tell the truth about what is already being quoted. That is the one
 * direction that is safe to automate. Going the other way (publishing a
 * screen price to uCRM) changes what customers are charged, so it stays a
 * deliberate act: open the row and save it.
 *
 * It does not touch buy price or margin, and it will not adopt for a row
 * with no uCRM product behind it — there is nothing to adopt from.
 *
 * It changes NOTHING on either side. It keeps one small snapshot file of its
 * own, so that the next run can tell you what moved since the last one — a
 * product that disappeared from uCRM is invisible to a tool with no memory.
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

$argv  = $argv ?? [];
$adopt = in_array('--adopt-ucrm', $argv, true);
$apply = $adopt && in_array('--yes', $argv, true);
$onlyAt = array_search('--only', $argv, true);
$only   = $onlyAt !== false ? trim((string)($argv[$onlyAt + 1] ?? '')) : '';

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/CrmApiClient.php';
require_once $root . '/lib/MediaLibrary.php';

$dataDir = cliDataDir($root);
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

$problems = [];

// ── What moved in uCRM since the last run ───────────────────────────────────
//
// Two runs of this tool an hour apart reported a different number of uCRM
// products, and nothing said so — it took comparing two old terminal windows
// to notice. A product quietly leaving uCRM is exactly the failure this tool
// exists to catch: the assistant stops being able to quote it, and the screen
// still shows it as though it were on sale.
$snapFile = rtrim($dataDir, '/') . '/hardware_diff_last.json';
$now = [];
foreach ($remote as $r) {
    if (!is_array($r) || empty($r['id'])) continue;
    $now[(string)(int)$r['id']] = [
        'name'  => (string)($r['name'] ?? ''),
        'price' => isset($r['price']) ? (float)$r['price'] : null,
    ];
}
$prev = null;
if (is_file($snapFile)) {
    $raw  = json_decode((string)@file_get_contents($snapFile), true);
    if (is_array($raw) && isset($raw['products']) && is_array($raw['products'])) $prev = $raw;
}

if ($prev !== null) {
    $was     = (array)$prev['products'];
    $gone    = array_diff_key($was, $now);
    $added   = array_diff_key($now, $was);
    $moved   = [];
    foreach ($now as $id => $p) {
        if (!isset($was[$id])) continue;
        $before = $was[$id]['price'] ?? null;
        if ($before === null || $p['price'] === null) continue;
        if (abs((float)$before - (float)$p['price']) >= 0.005) $moved[$id] = [$was[$id], $p];
    }
    if ($gone || $added || $moved) {
        echo "\n  CHANGED IN uCRM SINCE " . (string)($prev['checked_at'] ?? 'the last check') . "\n\n";
        foreach ($gone as $id => $p) {
            printf("    REMOVED  %-30s %12s   #%s\n", mb_substr((string)$p['name'], 0, 30), $fmt($p['price'] ?? null), $id);
            echo "             the assistant could quote this before and cannot now\n";
            $problems[] = (string)$p['name'] . ' has been removed from uCRM since the last check';
        }
        foreach ($added as $id => $p) {
            printf("    ADDED    %-30s %12s   #%s\n", mb_substr((string)$p['name'], 0, 30), $fmt($p['price'] ?? null), $id);
        }
        foreach ($moved as $id => $pair) {
            printf("    REPRICED %-30s %12s → %s   #%s\n", mb_substr((string)$pair[1]['name'], 0, 30),
                   $fmt($pair[0]['price'] ?? null), $fmt($pair[1]['price'] ?? null), $id);
            echo "             every quote from now on uses the new figure\n";
            $problems[] = (string)$pair[1]['name'] . ' was repriced in uCRM since the last check';
        }
    }
} else {
    echo "\n  No previous check to compare against. This run becomes the baseline,\n";
    echo "  so the next one can tell you what moved.\n";
}
@file_put_contents($snapFile, json_encode(
    ['checked_at' => gmdate('Y-m-d H:i') . ' UTC', 'products' => $now],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "\n  THE ASSISTANT QUOTES FROM uCRM. THE SCREEN IS A SEPARATE LIST.\n\n";
printf("  %-30s %12s  %12s  %s\n", 'EQUIPMENT', 'SCREEN', 'uCRM', 'STATE');
echo "  " . str_repeat('-', 76) . "\n";

$matchedIds = [];
$adoptable  = [];
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

        // A screen price that is exactly some OTHER product's price is not
        // drift. It is one row priced from a different product — a package
        // price typed onto the kit row, most often. Drift is a number nobody
        // recognises; this is a number that belongs somewhere else.
        foreach ($remote as $other) {
            if (!is_array($other) || (int)($other['id'] ?? 0) === (int)($r['id'] ?? 0)) continue;
            if (!isset($other['price'])) continue;
            if (abs((float)$other['price'] - $sell) >= 0.005) continue;
            echo "  " . str_repeat(' ', 32) . '↑ ' . $fmt($sell) . ' is exactly the uCRM price of "'
               . (string)($other['name'] ?? '?') . '" (#' . (string)($other['id'] ?? '?') . ")\n";
            echo "  " . str_repeat(' ', 32) . "  so this row is priced from that product, not drift\n";
            $problems[] = $title . ' on the screen carries the price of ' . (string)($other['name'] ?? '?');
            break;
        }

        $problems[] = $title . ': screen ' . $fmt($sell) . ' vs uCRM ' . $fmt($rp)
                    . ' — customers hear ' . $fmt($rp);
        $adoptable[] = ['id' => (int)($h['id'] ?? 0), 'title' => $title,
                        'from' => $sell, 'to' => $rp, 'ucrm_name' => (string)($r['name'] ?? '')];
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

// ── Adopting the uCRM price onto the screen ─────────────────────────────────
//
// Only ever in this direction. uCRM is what the customer is quoted, so
// copying it onto the screen changes nothing a customer sees — it stops the
// screen from lying to staff about a price that is already live. Publishing
// the other way would change what people are charged, and that stays a
// deliberate act on the Hardware screen.
if ($adopt) {
    $todo = $adoptable;
    if ($only !== '') {
        $want = $norm($only);
        $todo = array_values(array_filter($todo, fn($a) => $norm($a['title']) === $want));
        if (!$todo) {
            echo "\n  --only \"" . $only . "\" matches no row that disagrees.\n";
            echo "  Run without --only to see which rows do.\n\n";
            exit(1);
        }
    }
    if (!$todo) {
        echo "\n  Nothing to adopt: no row disagrees with uCRM.\n\n";
        exit(0);
    }

    echo "\n  " . ($apply ? 'ADOPTING THE uCRM PRICE' : 'WOULD ADOPT THE uCRM PRICE') . "\n\n";
    foreach ($todo as $a) {
        printf("    %-30s %12s → %s\n", mb_substr($a['title'], 0, 30), $fmt($a['from']), $fmt($a['to']));
        echo "    " . str_repeat(' ', 32) . 'from uCRM "' . $a['ucrm_name'] . "\"\n";
    }

    if (!$apply) {
        echo "\n    Nothing was changed. uCRM is not touched either way — this only\n";
        echo "    corrects the screen to the price customers are already quoted.\n";
        echo "\n    Add --yes to apply.\n\n";
        exit(1);
    }

    // Under a lock: somebody may be saving this very row on the Hardware
    // screen, and a read-modify-write outside one would silently drop it.
    $byRowId = [];
    foreach ($todo as $a) { if ($a['id'] > 0) $byRowId[$a['id']] = $a['to']; }

    $changed = 0; $missed = [];
    $store->withLock('kyc_devices.json', function (array $records) use ($byRowId, &$changed, &$missed) {
        $seen = [];
        foreach ($records as $i => $row) {
            if (!is_array($row)) continue;
            $rid = (int)($row['id'] ?? 0);
            if (!isset($byRowId[$rid])) continue;
            $seen[$rid] = true;
            $new = $byRowId[$rid];
            if ($new === null) continue;
            if (abs((float)($row['sell_price'] ?? 0) - (float)$new) < 0.005) continue;
            $records[$i]['sell_price'] = (float)$new;   // price only; cost and margin are ours
            $changed++;
        }
        foreach ($byRowId as $rid => $_) { if (empty($seen[$rid])) $missed[] = $rid; }
        return ['records' => $records, 'result' => true];
    });

    if ($missed) {
        echo "\n    " . count($missed) . " row(s) were not there when the write ran and were\n";
        echo "    left alone. Re-run to see the current state.\n";
    }
    printf("\n    %d row(s) updated on the screen. uCRM was not written to.\n", $changed);
    echo "    Re-run without --adopt-ucrm to confirm they now agree.\n\n";
    exit(0);
}

if ($problems) {
    echo "\n  NEEDS A DECISION\n\n";
    foreach ($problems as $p) echo "    - " . $p . "\n";
    echo "\n  To make uCRM match the screen, open the row on the Hardware screen and\n";
    echo "  save it — that pushes its price and changes what customers are quoted.\n";
    echo "  To make the screen match uCRM, which changes nothing a customer sees:\n\n";
    echo "    php tools/hardware_diff.php --adopt-ucrm\n\n";
    echo "  Decide which number is right first.\n\n";
    exit(1);
}
echo "\n  The screen and uCRM agree.\n\n";
exit(0);
