<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * import_starlink_invoices.php — book Starlink invoice PDFs as supplier bills.
 *
 *   php tools/import_starlink_invoices.php --dir /path/to/pdfs
 *   php tools/import_starlink_invoices.php --dir /path/to/pdfs --commit
 *   php tools/import_starlink_invoices.php --file one.pdf --text
 *
 * Changes nothing without --commit.
 *
 * Needs pdftotext (poppler-utils) in the container. It is not in the uCRM
 * image and does not survive a rebuild — see docs/UGANDA-PROVISIONING-ORDER.md.
 *
 * Every invoice is checked against its own arithmetic and a document that
 * does not reconcile is reported and NOT booked. A bill entered from a
 * misread PDF is a wrong number that looks like a fact, which is worse than
 * a missing one.
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
require_once $root . '/lib/PurchaseService.php';
require_once $root . '/lib/StarlinkInvoiceImport.php';

$args   = array_slice($argv, 1);
$val    = static function (string $f) use ($args): string {
    $i = array_search($f, $args, true);
    return $i === false ? '' : (string)($args[$i + 1] ?? '');
};
$commit   = in_array('--commit', $args, true);
$showText = in_array('--text', $args, true);
$dir      = trim($val('--dir'));
$one      = trim($val('--file'));
$supplier = trim($val('--supplier'));

foreach ($args as $a) {
    if (strpos($a, '--') !== 0) continue;
    if (!in_array($a, ['--commit', '--text', '--dir', '--file', '--supplier'], true)) {
        fwrite(STDERR, "\n  Unknown option: {$a}\n"
             . "  Known: --dir <path>, --file <pdf>, --supplier <name>, --text, --commit\n\n");
        exit(2);
    }
}

$bin = trim((string)@shell_exec('command -v pdftotext 2>/dev/null'));
if ($bin === '') {
    fwrite(STDERR, "\n  pdftotext is not installed, so no PDF can be read.\n"
                 . "      docker exec -u root ucrm apk add --no-cache poppler-utils\n"
                 . "  It does not survive a container rebuild.\n\n");
    exit(1);
}

$files = [];
if ($one !== '') {
    if (!is_file($one)) { fwrite(STDERR, "\n  No such file: {$one}\n\n"); exit(1); }
    $files = [$one];
} elseif ($dir !== '') {
    if (!is_dir($dir)) { fwrite(STDERR, "\n  No such directory: {$dir}\n\n"); exit(1); }
    foreach ((array)glob(rtrim($dir, '/') . '/*.[pP][dD][fF]') as $f) $files[] = (string)$f;
    sort($files);
} else {
    fwrite(STDERR, "\n  Give --dir <folder of PDFs> or --file <one.pdf>\n\n"); exit(2);
}
if ($files === []) { fwrite(STDERR, "\n  No PDF files found.\n\n"); exit(1); }

$dataDir = cliDataDir($root);
$store   = SqliteStore::create($dataDir);
$pdo     = $store->getPdo();
$config  = PluginConfig::load($root, $dataDir);
$imp     = new StarlinkInvoiceImport(new PurchaseService($pdo, $dataDir));
$actor   = ['id' => 0, 'name' => 'starlink invoice import'];

echo "\n", str_repeat('=', 78), "\n";
echo "  STARLINK INVOICES → PURCHASES", $commit ? '' : '   (dry run — nothing written)', "\n";
echo str_repeat('=', 78), "\n";

$ok = []; $refused = []; $booked = 0; $dupes = 0;
$feeByAccount = [];   // account => [date => fee] — does the Regulatory Fee repeat?

foreach ($files as $f) {
    $text = (string)@shell_exec(escapeshellcmd($bin) . ' -layout ' . escapeshellarg($f) . ' - 2>/dev/null');
    if ($showText) { echo "\n----- ", basename($f), " -----\n", $text, "\n"; continue; }

    $r = StarlinkInvoiceImport::parse($text);
    if (!$r['ok']) {
        $refused[] = [basename($f), '', $r['error']];
        continue;
    }
    $inv = $r['invoice'];
    $bad = StarlinkInvoiceImport::verify($inv);
    if ($bad !== []) {
        $refused[] = [basename($f), $inv['invoice_number'], implode('; ', $bad)];
        continue;
    }

    $ok[] = $inv;
    foreach ($inv['lines'] as $l) {
        if (stripos($l['description'], 'Regulatory') === false) continue;
        $feeByAccount[$inv['account']][$inv['date']] = $l['unit_price'];
    }
}

if ($showText) exit(0);

// ── What was read ───────────────────────────────────────────────────────────
printf("\n  %-26s %-11s %-26s %12s %12s\n", 'INVOICE', 'DATE', 'ACCOUNT', 'SUBTOTAL', 'TOTAL');
echo '  ', str_repeat('-', 92), "\n";
$grandNet = 0.0; $grandTax = 0.0; $grandTotal = 0.0;
foreach ($ok as $inv) {
    printf("  %-26s %-11s %-26s %12s %12s\n",
           $inv['invoice_number'], $inv['date'], $inv['account'],
           number_format($inv['subtotal']), number_format($inv['total']));
    $grandNet   += $inv['subtotal'];
    $grandTax   += $inv['vat'];
    $grandTotal += $inv['total'];
}
echo '  ', str_repeat('-', 92), "\n";
printf("  %d invoice(s)   net %s   VAT %s   gross %s %s\n",
       count($ok), number_format($grandNet), number_format($grandTax),
       number_format($grandTotal), $ok === [] ? '' : $ok[0]['currency']);

// ── What each item actually costs ───────────────────────────────────────────
$byItem = [];
foreach ($ok as $inv) {
    foreach ($inv['lines'] as $l) {
        $k = $l['description'];
        if (!isset($byItem[$k])) $byItem[$k] = ['qty' => 0.0, 'net' => 0.0, 'min' => null, 'max' => null];
        $byItem[$k]['qty'] += $l['quantity'];
        $byItem[$k]['net'] += $l['unit_price'] * $l['quantity'];
        $byItem[$k]['min']  = $byItem[$k]['min'] === null ? $l['unit_price'] : min($byItem[$k]['min'], $l['unit_price']);
        $byItem[$k]['max']  = $byItem[$k]['max'] === null ? $l['unit_price'] : max($byItem[$k]['max'], $l['unit_price']);
    }
}
if ($byItem !== []) {
    ksort($byItem);
    echo "\n  WHAT EACH ITEM COSTS (ex-VAT, from the invoices themselves)\n";
    echo '  ', str_repeat('-', 92), "\n";
    printf("  %-52s %6s %14s %14s\n", 'ITEM', 'QTY', 'UNIT', 'TOTAL');
    foreach ($byItem as $k => $v) {
        $unit = $v['min'] === $v['max']
            ? number_format((float)$v['min'])
            : number_format((float)$v['min']) . '–' . number_format((float)$v['max']);
        printf("  %-52s %6s %14s %14s\n", substr($k, 0, 52), rtrim(rtrim(number_format($v['qty'], 2, '.', ''), '0'), '.'),
               $unit, number_format($v['net']));
    }
}

// ── The question the figures can answer ─────────────────────────────────────
if ($feeByAccount !== []) {
    echo "\n  REGULATORY FEE — does it recur?\n";
    echo '  ', str_repeat('-', 92), "\n";
    $repeats = false;
    foreach ($feeByAccount as $acct => $byDate) {
        ksort($byDate);
        $months = [];
        foreach ($byDate as $d => $amt) $months[substr((string)$d, 0, 7)] = true;
        if (count($months) > 1) $repeats = true;
        printf("  %-26s %d charge(s) across %d month(s): %s\n",
               $acct, count($byDate), count($months), implode(', ', array_keys($byDate)));
    }
    echo "\n  ", $repeats
        ? "It appears in more than one month for an account — treat it as RECURRING\n  and check that against the plan price before selling more lines."
        : "Every charge seen so far falls in ONE month, so these invoices cannot\n  say whether it recurs. Next month's service invoice settles it.", "\n";
}

// ── Refusals ────────────────────────────────────────────────────────────────
if ($refused !== []) {
    echo "\n  NOT BOOKED — these did not reconcile with themselves\n";
    echo '  ', str_repeat('-', 92), "\n";
    foreach ($refused as [$file, $num, $why]) {
        printf("  %s%s\n      %s\n", $file, $num !== '' ? "  ({$num})" : '', $why);
    }
}

// ── Booking ─────────────────────────────────────────────────────────────────
if ($commit) {
    echo "\n  BOOKING\n";
    echo '  ', str_repeat('-', 92), "\n";
    foreach ($ok as $inv) {
        $res = $imp->book($inv, $actor, $supplier !== '' ? ['supplier' => $supplier] : []);
        if (!($res['ok'] ?? false)) {
            printf("  FAILED  %-26s %s\n", $inv['invoice_number'], (string)($res['error'] ?? 'unknown'));
            continue;
        }
        if ($res['duplicate'] ?? false) { $dupes++; printf("  already %-26s purchase #%d\n", $inv['invoice_number'], (int)$res['id']); }
        else { $booked++; printf("  booked  %-26s purchase #%d\n", $inv['invoice_number'], (int)$res['id']); }
    }
    printf("\n  %d booked, %d already present, %d refused\n", $booked, $dupes, count($refused));
} else {
    printf("\n  %d would be booked, %d refused. Add --commit to write them.\n", count($ok), count($refused));
}
echo "\n";
