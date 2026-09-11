<?php
declare(strict_types=1);
/**
 * price_check.php — does uCRM still agree with what we printed?
 *
 *   docker exec ucrm php /data/ucrm/data/plugins/dishnet-hybrid-sudan/tools/price_check.php
 *   ... price_check.php --json          machine-readable, for a cron or a check
 *
 * The AI quotes from uCRM, always, and that is the correct design — change a
 * price there and every channel is right within a minute. But the flyer in a
 * customer's hand does not update, and neither does the website. The failure
 * that follows is not a system error: the assistant quotes 329,000, the
 * customer is holding a flyer that says something else, and one of them is
 * wrong in front of a person who is about to pay.
 *
 * So this compares the two and says which. It NEVER writes to uCRM and never
 * feeds a price to anything — the published figures live in
 * published_prices.json purely as the comparison side, and no runtime code
 * reads that file.
 *
 * Exit codes: 0 agree · 1 drift found · 2 could not check (uCRM unreachable).
 *
 * CLI only.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/DishNetTools.php';

$asJson = in_array('--json', $argv, true);

$dataDir = getDataDir($root);
$config  = PluginConfig::load($root, $dataDir);
$store   = SqliteStore::create($dataDir);
$tools   = new DishNetTools($store, $config, $root);

$live = $tools->getProducts();
if (empty($live['ok'])) {
    $msg = 'uCRM catalogue unavailable: ' . (string)($live['error'] ?? 'unknown');
    echo $asJson ? json_encode(['ok' => false, 'error' => $msg]) . "\n" : "{$msg}\n";
    exit(2);
}

$published = json_decode((string)file_get_contents(__DIR__ . '/published_prices.json'), true) ?: [];
$cur = (string)($published['currency'] ?? '');

/** Find the one live item whose name contains the published match string. */
$find = function (array $items, string $match): array {
    $hits = [];
    foreach ($items as $it) {
        if (stripos((string)($it['name'] ?? ''), $match) !== false) $hits[] = $it;
    }
    return $hits;
};

$rows = [];
foreach ([['plans', (array)($live['data']['products'] ?? [])],
          ['hardware', (array)($live['data']['hardware'] ?? [])]] as [$group, $items]) {
    foreach ((array)($published[$group] ?? []) as $p) {
        $match = (string)($p['match'] ?? '');
        $hits  = $find($items, $match);
        $want  = $p['published'] ?? null;

        if (!$hits) {
            $rows[] = ['group' => $group, 'match' => $match, 'state' => 'NOT IN UCRM',
                       'published' => $want, 'live' => null,
                       'detail' => 'we publish this but uCRM has no matching item'];
            continue;
        }
        if (count($hits) > 1) {
            $rows[] = ['group' => $group, 'match' => $match, 'state' => 'AMBIGUOUS',
                       'published' => $want, 'live' => null,
                       'detail' => count($hits) . ' uCRM items match: '
                                 . implode(' | ', array_map(fn($h) => (string)$h['name'], $hits))];
            continue;
        }
        $item = $hits[0];
        $got  = $item['price'] ?? null;

        if ($want === null) {
            // Deliberately unpublished (Business is quoted on the day). Not a
            // fault either way — just report what uCRM would quote.
            $rows[] = ['group' => $group, 'match' => $match, 'state' => 'NOT PUBLISHED',
                       'published' => null, 'live' => $got, 'name' => (string)$item['name'],
                       'detail' => 'quoted on the day; uCRM would quote '
                                 . ($got === null ? 'nothing (no price set)' : (string)$got)];
            continue;
        }
        if ($got === null) {
            $rows[] = ['group' => $group, 'match' => $match, 'state' => 'NO PRICE IN UCRM',
                       'published' => $want, 'live' => null, 'name' => (string)$item['name'],
                       'detail' => 'we publish a price; uCRM has none, so the AI will not quote it'];
            continue;
        }
        $same = abs((float)$got - (float)$want) < 0.005;
        $rows[] = ['group' => $group, 'match' => $match,
                   'state' => $same ? 'AGREES' : 'DRIFT',
                   'published' => $want, 'live' => $got, 'name' => (string)$item['name'],
                   'detail' => $same ? '' : 'the flyer and uCRM disagree'];
    }
}

// Anything uCRM sells that we never listed here. Not an error — but a plan the
// AI can quote and nobody has checked against print is worth seeing.
$unlisted = [];
foreach ([['plans', (array)($live['data']['products'] ?? [])],
          ['hardware', (array)($live['data']['hardware'] ?? [])]] as [$group, $items]) {
    foreach ($items as $it) {
        $name = (string)($it['name'] ?? '');
        $seen = false;
        foreach ((array)($published[$group] ?? []) as $p) {
            if (stripos($name, (string)($p['match'] ?? '')) !== false) { $seen = true; break; }
        }
        if (!$seen) $unlisted[] = ['group' => $group, 'name' => $name, 'price' => $it['price'] ?? null];
    }
}

$bad = array_values(array_filter($rows, fn($r) => in_array(
    $r['state'], ['DRIFT', 'NOT IN UCRM', 'AMBIGUOUS', 'NO PRICE IN UCRM'], true)));

if ($asJson) {
    echo json_encode(['ok' => !$bad, 'currency' => $cur, 'rows' => $rows,
                      'unlisted' => $unlisted, 'drift' => count($bad)],
                     JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit($bad ? 1 : 0);
}

$fmt = fn($v) => $v === null ? '—' : rtrim(rtrim(number_format((float)$v, 2, '.', ','), '0'), '.');

printf("\nPublished (%s) vs uCRM — %s\n\n", (string)($published['source'] ?? 'our material'), $cur);
printf("  %-16s %-16s %14s %14s  %s\n", 'GROUP', 'ITEM', 'PUBLISHED', 'UCRM', 'STATE');
foreach ($rows as $r) {
    printf("  %-16s %-16s %14s %14s  %s\n", $r['group'], $r['match'],
           $fmt($r['published']), $fmt($r['live']), $r['state']);
    if (!empty($r['detail'])) printf("  %-16s %s\n", '', $r['detail']);
}

if ($unlisted) {
    echo "\nIn uCRM but not in our published material (the AI can quote these):\n";
    foreach ($unlisted as $u) printf("  - [%s] %s — %s\n", $u['group'], $u['name'], $fmt($u['price']));
}

// ── Packages against the parts they bundle ──────────────────────────────────
// The catalogue carries both. A package that does not equal its parts is not
// necessarily wrong — a bundle discount is a real thing — but nobody can tell
// from the assistant's side, and a customer holding both figures will ask. It
// surfaces here as a report instead of as an argument at the counter.
$hw = (array)($live['data']['hardware'] ?? []);
$named = function (array $items, array $words) {
    foreach ($items as $it) {
        $n = mb_strtolower((string)($it['name'] ?? ''));
        foreach ($words as $w) if (strpos($n, $w) === false) { continue 2; }
        return $it;
    }
    return null;
};
$install = $named($hw, ['install']);
$packages = [];
foreach ($hw as $it) {
    $n = mb_strtolower((string)($it['name'] ?? ''));
    if (strpos($n, 'package') !== false || strpos($n, 'bundle') !== false) $packages[] = $it;
}
if ($packages && $install && ($install['price'] ?? null) !== null) {
    echo "\nPackages vs the parts they bundle\n\n";
    foreach ($packages as $pk) {
        $pkName  = (string)$pk['name'];
        $pkPrice = $pk['price'] ?? null;
        // The kit this package is built on: same leading words, minus "package".
        $stem = trim(str_ireplace(['package', 'bundle'], '', $pkName));
        $kit  = null;
        foreach ($hw as $it) {
            $n = (string)($it['name'] ?? '');
            if ($n === $pkName) continue;
            if (stripos($n, 'package') !== false || stripos($n, 'bundle') !== false) continue;
            if ($stem !== '' && stripos($n, $stem) !== false) { $kit = $it; break; }
        }
        if (!$kit || ($kit['price'] ?? null) === null || $pkPrice === null) {
            printf("  %-30s %14s   (no matching kit to compare)\n", $pkName, $fmt($pkPrice));
            continue;
        }
        $parts = (float)$kit['price'] + (float)$install['price'];
        $delta = (float)$pkPrice - $parts;
        printf("  %-30s %14s\n", $pkName, $fmt($pkPrice));
        printf("  %-30s %14s   %s + %s\n", '  parts separately', $fmt($parts),
               (string)$kit['name'], (string)$install['name']);
        if (abs($delta) < 0.005) {
            printf("  %-30s %14s   identical\n", '', '');
        } else {
            printf("  %-30s %14s   %s buying the parts — confirm this is intended\n", '',
                   $fmt(abs($delta)), $delta < 0 ? 'CHEAPER than' : 'DEARER than');
            $bad[] = $pkName . ' does not equal its parts (' . $fmt(abs($delta)) . ' apart)';
        }
    }
    echo "\n  A package already includes its installation. Quoting a package AND an\n";
    echo "  installation charges the customer twice for the same work.\n";
}

if ($bad) {
    printf("\n%d item(s) need attention. uCRM is what customers are quoted, so either\n"
         . "uCRM is wrong and should be corrected, or the printed material is out of date.\n",
           count($bad));
    exit(1);
}
echo "\nEverything we publish matches uCRM.\n";
exit(0);
