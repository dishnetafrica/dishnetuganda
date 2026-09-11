<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * report.php — the management figures, and what each one rests on.
 *
 *   php tools/report.php
 *   php tools/report.php --from 2026-09-01 --to 2026-09-30
 *   php tools/report.php --json
 *
 * Every number here comes from a transactional record: an invoice uCRM
 * issued, a payment recorded against one, a delivery received into stock.
 * None is estimated and none is carried over from a previous screen.
 *
 * Figures print with their basis, and an incomplete one prints the reason.
 * That is the whole point: the failure running through this system is a zero
 * that means "I could not ask" dressed as a zero that means "there is
 * nothing", and on a management screen the difference is decisions.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/ReportingService.php';

$args = array_slice($argv, 1);
$val = function (string $f) use ($args): string {
    $i = array_search($f, $args, true);
    return ($i !== false && isset($args[$i + 1])) ? (string)$args[$i + 1] : '';
};
foreach ($args as $a) {
    if (strpos($a, '--') !== 0) continue;
    if (!in_array($a, ['--from', '--to', '--json'], true)) {
        fwrite(STDERR, "\n  Unknown option: {$a}\n  Known: --from <date> --to <date> --json\n\n");
        exit(2);
    }
}
foreach (['--from', '--to'] as $f) {
    $v = $val($f);
    if ($v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        fwrite(STDERR, "\n  {$f} must be a date like 2026-09-01\n\n");
        exit(2);
    }
}

$dataDir = cliDataDir($root);
$store   = SqliteStore::create($dataDir);
$config  = PluginConfig::load($root, $dataDir);
$rep     = new ReportingService($store, $dataDir, $store->getPdo(), $config);
$s       = $rep->summary(['from' => $val('--from'), 'to' => $val('--to')]);

if (in_array('--json', $args, true)) {
    echo json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    exit(0);
}

/**
 * Pad to a width in CHARACTERS, not bytes.
 *
 * printf's %-22s counts bytes, so a label of '—' — three bytes for one
 * character — came out two columns narrower than the rest, and the whole
 * INVENTORY block sat shifted against the blocks above it.
 */
$pad = function (string $s, int $w): string {
    $len = function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
    return $s . str_repeat(' ', max(0, $w - $len));
};

/** One figure: the number, then the ground it stands on. */
$fig = function (string $label, array $f) use ($pad): void {
    $amount = ($f['currency'] !== '' ? $f['currency'] . ' ' : '') . number_format($f['value'], 0);
    echo '    ' . $pad($label, 22) . ' ' . str_pad($amount, 18, ' ', STR_PAD_LEFT)
         . '   ' . $f['basis'] . "\n";
    if (!$f['complete'] || $f['caveat'] !== '') {
        // Indented under the number it qualifies, so it cannot be read as a
        // separate line and skipped.
        echo '    ' . $pad('', 22) . ' ' . str_pad('', 18) . '   ⚠ '
             . ($f['caveat'] ?: 'this figure is incomplete') . "\n";
    }
};
$block = function (string $title, array $figures) use ($fig): void {
    echo "  " . $title . "\n";
    foreach ($figures as $cur => $f) {
        if (!is_array($f) || !isset($f['value'])) continue;
        $fig($cur === 'NONE' ? '—' : $cur, $f);
    }
    echo "\n";
};

echo "\n  DISHNET — MANAGEMENT SUMMARY\n";
printf("  %s → %s\n", $s['period']['from'], $s['period']['to']);
echo "  " . str_repeat('─', 72) . "\n\n";

$block('SALES (invoiced)',        $s['sales']);
$block('RECEIVABLE (owed to us)', $s['receivable']);
$block('PAYMENTS RECEIVED',       $s['payments']);
$block('PURCHASES',               $s['purchases']);
$block('PAYABLE (we owe)',        $s['payable']);

echo "  INVENTORY\n";
$fig('on the shelf', $s['inventory']['value']);
$fig('at customers', $s['equipment']['value']);
echo '    ' . $pad('equipment out', 22) . ' '
     . str_pad((string)$s['equipment']['count'], 18, ' ', STR_PAD_LEFT) . "   unit(s) installed\n";
echo "\n";

echo "  GROSS MARGIN\n";
if (!empty($s['margin']['available'])) {
    $fig('margin', $s['margin']['figure']);
} else {
    echo "    not available — " . $s['margin']['reason'] . "\n";
}
echo "\n";

if ($s['by_product'] !== []) {
    echo "  SALES BY PRODUCT\n";
    foreach (array_slice($s['by_product'], 0, 12) as $r) {
        printf("    %-40s %6s × %14s\n", substr($r['label'], 0, 40),
               rtrim(rtrim(number_format($r['qty'], 2, '.', ''), '0'), '.'),
               $r['currency'] . ' ' . number_format($r['total'], 0));
    }
    echo "\n";
}

echo "  " . str_repeat('─', 72) . "\n";
if ($s['warnings'] === []) {
    echo "  Every figure above rests on a complete record.\n\n";
    exit(0);
}
echo "  READ THESE BEFORE ACTING ON THE NUMBERS\n\n";
foreach ($s['warnings'] as $w) echo "    · " . $w . "\n";
echo "\n";
exit(0);
