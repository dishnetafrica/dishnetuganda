<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * kits.php — which kit went to which customer.
 *
 *   php tools/kits.php                        Starlink units, and who holds them
 *   php tools/kits.php --serial UT01234567    one unit, and everywhere it has been
 *   php tools/kits.php --client 2             what one customer holds
 *   php tools/kits.php --receive UT01234567 --by bhavin --account ops@dishnetuganda.com
 *   php tools/kits.php --assign UT01234567 --client 2 --by bhavin
 *
 * This reads and writes StockService, which already owns serial-numbered
 * equipment, its movements and its location — including `customer`.
 *
 * An earlier version of this tool had its own kit table. That was a second
 * store for something the plugin already tracked, and two tables both
 * claiming to say which kit a customer has will disagree eventually — in
 * front of the customer. It was retired before it held anything real.
 *
 * A kit must be RECEIVED before it can be assigned. StockService refuses to
 * install a unit that is not in stock, which is the right discipline: hardware
 * nobody has physically taken delivery of should not be handed to a customer
 * on paper.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/StockService.php';

$dataDir = cliDataDir($root);
$pdo = new PDO('sqlite:' . $dataDir . '/plugin.sqlite3');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$stock = new StockService($pdo, $dataDir);
$stock->ensureTables();

$args  = array_slice($argv, 1);
$value = function (string $f) use ($args) {
    $i = array_search($f, $args, true);
    return ($i !== false && isset($args[$i + 1])) ? (string)$args[$i + 1] : '';
};
$has = function (string $f) use ($args) { return in_array($f, $args, true); };

/** One unit by serial, however it was spelled. */
function findBySerial(PDO $pdo, string $serial): ?array
{
    $norm = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $serial) ?? '');
    if ($norm === '') return null;
    $st = $pdo->prepare(
        "SELECT * FROM stock_units
          WHERE UPPER(REPLACE(REPLACE(REPLACE(serial_number,'-',''),' ',''),'_','')) = ?");
    $st->execute([$norm]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function head(): void
{
    printf("\n  %-18s %-11s %-7s %-10s %-26s %s\n",
        'SERIAL', 'STATUS', 'CLIENT', 'STARLINK', 'FROM ACCOUNT', 'SERVICE LINE');
    printf("  %s\n", str_repeat('-', 96));
}
function row(array $u): void
{
    // Serials and service-line identifiers print WHOLE. Truncating one turned
    // SL-DF-15784590-46113-10 into "-1" in an earlier table and corrupted six
    // rows out of ten.
    printf("  %-18s %-11s %-7s %-10s %-26s %s\n",
        (string)($u['serial_number'] ?? ''),
        (string)($u['status'] ?? ''),
        ((int)($u['crm_client_id'] ?? 0) ?: '—'),
        substr((string)($u['starlink_status'] ?? ''), 0, 10) ?: '—',
        (string)($u['starlink_account'] ?? '') ?: '—',
        (string)($u['starlink_service_line'] ?? '') ?: '—');
}

// ── receive: a kit arrives and becomes stock ─────────────────────────────
if ($has('--receive')) {
    $serial = $value('--receive');
    if (findBySerial($pdo, $serial) !== null) {
        echo "\n  That serial is already on the books. php tools/kits.php --serial {$serial}\n\n";
        exit(1);
    }
    $cats = $stock->getCategories(true);
    $cat  = null;
    foreach ($cats as $c) {
        if (strtolower((string)($c['service_type'] ?? '')) === 'starlink') { $cat = $c; break; }
    }
    if ($cat === null) {
        echo "\n  No Starlink category exists in stock yet. Create one in the Stock tab\n";
        echo "  first — a unit has to be a kind of thing before it can be a thing.\n\n";
        exit(1);
    }
    try {
        $r = $stock->createUnit([
            'category_id'    => (int)$cat['id'],
            'serial_number'  => strtoupper(trim($serial)),
            'status'         => 'in_stock',
            'location_type'  => 'warehouse',
            // Which Starlink account this kit came from. DishNet buys through
            // more than one, and "which of our accounts supplied the kit this
            // customer is using" is not answerable afterwards unless it is
            // recorded at the moment of delivery. The column already existed;
            // nothing was filling it.
            'starlink_account'=> $value('--account'),
            'starlink_status'=> $value('--starlink-status'),
            'notes'          => $value('--note'),
        ], 0, $value('--by') ?: 'cli');
        echo "\n  Received into stock as unit " . (int)($r['unit_id'] ?? $r['id'] ?? 0) . ".\n";
        if (trim((string)$value('--account')) === '') {
            echo "  No --account given, so which Starlink account supplied this kit\n";
            echo "  is not recorded. That is answerable now and guesswork later.\n";
        }
        echo "  Assign it when it reaches a customer:\n";
        echo "    php tools/kits.php --assign {$serial} --client <id> --by <name>\n\n";
        exit(0);
    } catch (\Throwable $e) {
        echo "\n  " . $e->getMessage() . "\n\n";
        exit(1);
    }
}

// ── assign: a customer physically receives it ────────────────────────────
if ($has('--assign')) {
    $u = findBySerial($pdo, $value('--assign'));
    if ($u === null) {
        echo "\n  No unit with that serial. Receive it into stock first:\n";
        echo "    php tools/kits.php --receive " . $value('--assign') . " --by <name>\n\n";
        exit(1);
    }
    $client = (int)$value('--client');
    if ($client <= 0) { echo "\n  Give --client <uCRM client id>.\n\n"; exit(1); }
    try {
        $stock->install((int)$u['id'], [
            'crm_client_id' => $client,
            'client_name'   => $value('--client-name') ?: ('Client ' . $client),
        ], 0, $value('--by') ?: 'cli');
        echo "\n  Recorded: " . $u['serial_number'] . " is with client {$client}.\n";
        echo "  The move is in its movement history.\n\n";
        exit(0);
    } catch (\Throwable $e) {
        echo "\n  " . $e->getMessage() . "\n\n";
        exit(1);
    }
}

// ── one serial, and everywhere it has been ───────────────────────────────
if ($value('--serial') !== '') {
    $u = findBySerial($pdo, $value('--serial'));
    if ($u === null) { echo "\n  No unit with that serial.\n\n"; exit(1); }
    head(); row($u);
    echo "\n  EVERYWHERE IT HAS BEEN\n";
    foreach ($stock->getMovements(['unit_id' => (int)$u['id']], 100) as $m) {
        printf("    %-19s %-10s %-14s → %-14s %s\n",
            substr((string)$m['created_at'], 0, 19),
            (string)$m['movement_type'],
            substr((string)$m['from_location_name'] ?: (string)$m['from_location_type'], 0, 14),
            substr((string)$m['to_location_name'] ?: (string)$m['to_location_type'], 0, 14),
            (string)$m['performed_by_name']);
    }
    echo "\n";
    exit(0);
}

// ── what one customer holds ──────────────────────────────────────────────
if ($value('--client') !== '') {
    $st = $pdo->prepare('SELECT * FROM stock_units WHERE crm_client_id = ? ORDER BY updated_at DESC');
    $st->execute([(int)$value('--client')]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($rows === []) { echo "\n  This customer holds no equipment we know of.\n\n"; exit(1); }
    head();
    foreach ($rows as $u) row($u);
    echo "\n";
    exit(0);
}

// ── the overview ─────────────────────────────────────────────────────────
$rows = $pdo->query(
    "SELECT u.* FROM stock_units u
       LEFT JOIN stock_categories c ON c.id = u.category_id
      WHERE LOWER(COALESCE(c.service_type,'')) = 'starlink'
         OR COALESCE(u.starlink_status,'') <> ''
         OR COALESCE(u.starlink_service_line,'') <> ''
      ORDER BY u.updated_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC) ?: [];

echo "\n  STARLINK EQUIPMENT\n";

// How many, and how complete. A list you have to count by eye does not answer
// "how many kits do we have", and the blanks matter as much as the total:
// a kit with no account recorded cannot be traced to the invoice that bought
// it, and one with no customer is either in the warehouse or lost track of.
$total    = count($rows);
$withCust = 0; $withLine = 0; $withAcct = 0;
$byStatus = [];
foreach ($rows as $u) {
    if ((int)($u['crm_client_id'] ?? 0) > 0)                    $withCust++;
    if (trim((string)($u['starlink_service_line'] ?? '')) !== '') $withLine++;
    if (trim((string)($u['starlink_account'] ?? '')) !== '')      $withAcct++;
    $st = (string)($u['status'] ?? 'unknown');
    $byStatus[$st] = ($byStatus[$st] ?? 0) + 1;
}

if ($total > 0) {
    $parts = [];
    foreach ($byStatus as $st => $n) $parts[] = $n . ' ' . $st;
    echo "\n  " . $total . " unit" . ($total === 1 ? '' : 's')
       . ($parts ? '  (' . implode(', ', $parts) . ')' : '') . "\n";
    printf("  %d with a customer · %d with a service line · %d with a supplying account\n",
        $withCust, $withLine, $withAcct);
    if ($withAcct < $total) {
        echo "  " . ($total - $withAcct) . " do not record which Starlink account supplied them.\n";
        echo "  Kits received before that was captured; --account fills it going forward.\n";
    }
}

// Everything else in stock, so "how much equipment do we have" is answerable
// from here too rather than looking Starlink-only and reading as the whole.
$others = (int)$pdo->query(
    "SELECT COUNT(*) FROM stock_units u
       LEFT JOIN stock_categories c ON c.id = u.category_id
      WHERE LOWER(COALESCE(c.service_type,'')) <> 'starlink'
        AND COALESCE(u.starlink_status,'') = ''
        AND COALESCE(u.starlink_service_line,'') = ''")->fetchColumn();
if ($others > 0) {
    echo "  " . $others . " further unit" . ($others === 1 ? '' : 's')
       . " in stock are not Starlink — see the Stock tab.\n";
}

if ($rows === []) {
    echo "\n  None recorded yet. A kit becomes visible here when it is received:\n\n";
    echo "    php tools/kits.php --receive <serial> --by <name>\n\n";
    echo "  Phase 4 will also bring serials in from the Starlink service-line\n";
    echo "  sync, so kits appear before anyone types them.\n\n";
    exit(0);
}
head();
foreach ($rows as $u) row($u);
echo "\n  php tools/kits.php --serial <serial>   one unit, and everywhere it has been\n";
echo "  php tools/kits.php --client <id>      what one customer holds\n\n";
exit(0);
