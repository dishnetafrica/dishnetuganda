<?php
declare(strict_types=1);
/**
 * cash_in_hand.php — 5.18.71. READ-ONLY. Prints what the ledger itself says is in hand, per currency and per project —
 * the figure the Cashbook page's card and (since 5.18.71) the accounts dashboard's hero show on a book without SSP:
 * CashbookService::cashInHand(), the running balance of each currency's stream (rows not voided), accounts and the
 * unassigned split playing no part. For the operator to read beside the screen, and for a deploy's after-check.
 *
 *   php tools/cash_in_hand.php          Changes nothing. Exit 0.
 */
chdir(dirname(__DIR__));
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/currency.php';
require_once $root . '/lib/TenantProfile.php';
require_once $root . '/lib/CashbookService.php';

$dataDir = cliDataDir($root);
$GLOBALS['dataDir'] = $dataDir;
$store   = SqliteStore::create($dataDir);
$config  = dn_book_effective_config();
$tenant  = TenantProfile::current($config, $dataDir)->id();
$base    = dn_book_base($config);
$cb      = new CashbookService($store, $dataDir);
$hasLedger = (bool)$store->getPdo()->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'cb_ledger'")->fetchColumn();

echo "\n== cash in hand (5.18.71) — read-only ==\n";
echo "  data dir   {$dataDir}\n  tenant     {$tenant}\n  base       {$base}\n  currencies " . implode(', ', dn_book_currencies($config)) . "\n";
if (!$hasLedger) { echo "\n  no cashbook ledger yet (cb_ledger absent) — nothing to show.\n\n"; exit(0); }
echo "\n  the ledger's running balance per currency (rows not voided; accounts and the unassigned split play no part):\n";
foreach (dn_book_currencies($config) as $cur) {
    printf("    %-4s CASH IN HAND  %18s\n", $cur, number_format($cb->cashInHand($cur), 2));
}
echo "\n  the base currency per project (the dashboard hero's chips):\n";
foreach (['dishnet' => 'Fiber & Starlink', '4g' => 'DishNet 4G', 'bluecard' => 'BlueCARD'] as $proj => $lbl) {
    printf("    %-18s %-4s %18s\n", $lbl, $base, number_format($cb->cashInHand($base, $proj), 2));
}
echo "\n  READ-ONLY — nothing was changed.\n\n";
exit(0);
