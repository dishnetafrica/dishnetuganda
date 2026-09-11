<?php
declare(strict_types=1);
/**
 * efris_uat_drill.php — walk the five UAT interfaces against the CONFIGURED
 * test endpoint, in checklist order, printing one PASS/FAIL line per step.
 *
 * Runs only in efris_environment=test (disabled and production refuse, as
 * everywhere). Against the local fake server this proves the plumbing; when
 * URA issues sandbox credentials and the Phase-2 crypto connector lands,
 * the same drill exercises the real test environment.
 *
 * It registers a throwaway item named "UAT Drill Item <timestamp>" and moves
 * 5 units of stock in and 2 out — real registry rows, visible (and
 * deletable) in the EFRIS admin tab. Invoices/credit notes are NOT drilled
 * here because they need real uCRM records; use the admin tab for those.
 *
 *   php tools/efris_uat_drill.php
 *   php tools/efris_uat_drill.php --tin=1000000001   # also check one TIN
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/EfrisService.php';

$dataDir = cliDataDir($root);
$store   = SqliteStore::create($dataDir);
$config  = PluginConfig::load($root, $dataDir);
$svc     = new EfrisService($store, $config, $dataDir);
$gs      = $svc->goodsService();

$tin = null;
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--tin=(\d+)$/', $a, $m)) $tin = $m[1];
}

echo "══ EFRIS UAT drill — environment: " . $svc->environment() . "\n";
echo "   data dir: {$dataDir}\n\n";
if ($svc->environment() !== EfrisClient::ENV_TEST) {
    fwrite(STDERR, "ABORT: the drill runs only with efris_environment=test.\n");
    exit(1);
}

$ok = 0; $bad = 0;
$step = function (string $label, bool $pass, string $note = '') use (&$ok, &$bad): void {
    $pass ? $ok++ : $bad++;
    printf("  %s  %s%s\n", $pass ? 'PASS' : 'FAIL', $label, $note !== '' ? " — {$note}" : '');
};

// T101 — reachability
$client = new EfrisClient($config);
$r = $client->ping();
$step('T101 server time (endpoint reachable)', $r['ok'], $r['ok'] ? '' : $r['error']);
if (!$r['ok']) { echo "\nEndpoint unreachable — fix efris_test_api_url first.\n"; exit(1); }

// T130 — register a throwaway item
$name = 'UAT Drill Item ' . gmdate('YmdHis');
$sv = $gs->save(['name' => $name, 'goods_code' => 'DRILL-' . gmdate('His'),
                 'commodity_code' => '43222609', 'unit' => 'each',
                 'vat_category' => 'standard', 'stocked' => 1]);
$reg = $sv['ok'] ? $gs->register((int)$sv['id']) : ['ok' => false, 'error' => $sv['error'] ?? '?'];
$step('T130 goods registration', (bool)$reg['ok'],
      $reg['ok'] ? 'ref ' . $reg['reference'] : (string)($reg['error'] ?? ''));

// T131 — stock in 5, out 2
if ($reg['ok']) {
    $si = $gs->adjustStock((int)$sv['id'], 'increase', 5, 'UAT drill stock-in', '', 'drill');
    $step('T131 stock increase (+5)', (bool)$si['ok'], $si['ok'] ? 'on hand ' . $si['stock_qty'] : (string)$si['error']);
    $so = $gs->adjustStock((int)$sv['id'], 'decrease', 2, 'UAT drill adjustment', '', 'drill');
    $step('T131 stock decrease (−2)', (bool)$so['ok'], $so['ok'] ? 'on hand ' . $so['stock_qty'] : (string)$so['error']);
    $over = $gs->adjustStock((int)$sv['id'], 'decrease', 999, 'UAT drill over-draw', '', 'drill');
    $step('T131 over-draw is refused', !$over['ok'], (string)($over['error'] ?? ''));
}

// T119 — TIN validation
if ($tin !== null) {
    $tv = $svc->queryTin($tin);
    $step("T119 TIN {$tin}", (bool)$tv['ok'],
          $tv['ok'] ? (string)($tv['taxpayer']['name'] ?? '') . ($tv['cached'] ? ' (cached)' : '') : (string)$tv['error']);
} else {
    echo "  skip  T119 — pass --tin=<10 digits> to check one\n";
}

echo "\nInvoice (T109), credit note (T110) and cancellation (T114) need real\n"
   . "uCRM records — drive those from the EFRIS admin tab, where every\n"
   . "response is stored on the transaction row.\n";
printf("\n%d passed, %d failed\n", $ok, $bad);
exit($bad ? 1 : 0);
