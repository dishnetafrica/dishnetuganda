<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * starlink_accounts_list.php — every Starlink account we can see, and where
 * we saw it.
 *
 *   php tools/starlink_accounts_list.php
 *
 * dishnet-starlink-finance's Accounts screen is empty, and it cannot fill
 * itself: it builds kits from uCRM, and uCRM carries no Starlink account
 * number anywhere. The numbers exist in three places on this box, none of
 * them uCRM, and they do not necessarily agree — so this prints each account
 * with the source that knows it rather than a single merged list that hides
 * which source is behind a number.
 *
 * Sources, in the order they are trusted:
 *
 *   equipment_assignments.starlink_account
 *       ours, and the authoritative binding. An account here is one we have
 *       deliberately recorded against a customer's kit.
 *
 *   dishnet-data-report/sl_svc_cache.json
 *       that plugin's cache of Starlink's own service lines, each carrying
 *       account_number. This is where the fleet's full account list lives;
 *       we read it and never write it.
 *
 *   dishnet-starlink-finance/sl_accounts.json
 *       what Finance already holds, so the report can say what is missing
 *       there rather than what exists in total.
 *
 * Read-only. It writes nothing, anywhere.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
$GLOBALS['_PLUGIN_ROOT'] = $root;
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/EquipmentAssignment.php';
require_once $root . '/lib/SiblingPlugin.php';
require_once $root . '/lib/StarlinkServiceState.php';

$dataDir = cliDataDir($root);
$store   = SqliteStore::create($dataDir);
$ea      = new EquipmentAssignment($store->getPdo());

/** account => ['lines' => [...], 'kits' => [...], 'states' => [...]] */
$acct = [];
$remember = static function (string $a) use (&$acct): void {
    $a = trim($a);
    if ($a === '' || isset($acct[$a])) return;
    $acct[$a] = ['lines' => [], 'kits' => [], 'states' => []];
};

// ── ours ────────────────────────────────────────────────────────────────
$ourLines = 0;
foreach ($ea->liveAssignments() as $a) {
    $n = trim((string)($a['starlink_account'] ?? ''));
    if ($n === '') continue;
    $remember($n);
    $kit = trim((string)($a['kit_serial'] ?? ''));
    if ($kit !== '') $acct[$n]['kits'][] = $kit;
    $ourLines++;
}

// ── the data plugin's cache of Starlink's own lines ─────────────────────
$state = new StarlinkServiceState();
$rows  = $state->rows();
$cacheLines = 0;
if (is_array($rows)) {
    foreach ($rows as $line => $rec) {
        $n = trim((string)($rec['account_number'] ?? ''));
        $cacheLines++;
        if ($n === '') continue;
        $remember($n);
        $acct[$n]['lines'][] = (string)$line;
        $st = trim((string)($rec['service_line_status'] ?? $rec['status'] ?? ''));
        if ($st !== '') $acct[$n]['states'][$st] = ($acct[$n]['states'][$st] ?? 0) + 1;
    }
}

// ── what Finance already holds ──────────────────────────────────────────
$finance = SiblingPlugin::readJson('dishnet-starlink-finance', 'sl_accounts.json');
$inFinance = [];
if (is_array($finance)) {
    foreach ($finance as $f) {
        if (!is_array($f)) continue;
        $n = trim((string)($f['account_number'] ?? ''));
        if ($n !== '') $inFinance[$n] = true;
    }
}

echo "\n", str_repeat('=', 74), "\n";
echo "  STARLINK ACCOUNTS WE CAN SEE\n";
echo str_repeat('=', 74), "\n\n";

printf("  equipment_assignments  %d live binding(s) carrying an account\n", $ourLines);
if ($rows === null) {
    echo "  sl_svc_cache.json      NOT READABLE — the data plugin has written no\n";
    echo "                         service cache here, so the fleet's other accounts\n";
    echo "                         are not visible from this box at all.\n";
} else {
    printf("  sl_svc_cache.json      %d service line(s) cached by the data plugin\n", $cacheLines);
}
if ($finance === null) {
    echo "  sl_accounts.json       Finance holds no accounts file yet\n";
} else {
    printf("  sl_accounts.json       Finance holds %d account(s)\n", count($inFinance));
}

if ($acct === []) {
    echo "\n  No account number is visible from any source.\n";
    echo "  Nothing here is empty because the fleet is empty — it is empty because\n";
    echo "  no source on this box carries an account number. Sync the data plugin's\n";
    echo "  Starlink session, or record the account on the assignment.\n\n";
    exit(1);
}

ksort($acct);
echo "\n", str_repeat('-', 74), "\n";
printf("  %-28s %5s %5s  %-10s %s\n", 'ACCOUNT', 'LINES', 'KITS', 'IN FINANCE', 'STATES');
echo str_repeat('-', 74), "\n";

$missing = [];
foreach ($acct as $n => $d) {
    $states = [];
    foreach ($d['states'] as $s => $c) $states[] = $s . '×' . $c;
    $have = isset($inFinance[$n]);
    if (!$have) $missing[] = $n;
    printf("  %-28s %5d %5d  %-10s %s\n",
           $n, count($d['lines']), count($d['kits']),
           $have ? 'yes' : 'NO',
           $states === [] ? '' : implode(' ', $states));
}
echo str_repeat('-', 74), "\n";
printf("  %d account(s) visible, %d not yet in Finance\n", count($acct), count($missing));

foreach ($acct as $n => $d) {
    if ($d['kits'] === []) continue;
    echo "\n  ", $n, "\n";
    foreach ($d['kits'] as $k) echo "      kit  ", $k, "\n";
}

if ($missing !== []) {
    echo "\n  Missing from Finance:\n";
    foreach ($missing as $n) echo "      ", $n, "\n";
    echo "\n  Finance owns its accounts file, so these go in through its own\n";
    echo "  Starlink → Accounts screen — \"+ Add Account\", or the CSV import it\n";
    echo "  offers there. Nothing here writes them.\n";
}
echo "\n";
