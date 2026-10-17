<?php
declare(strict_types=1);
/**
 * cash_in_hand.php — 5.18.71; 5.18.96 (P3b, D2 = A). READ-ONLY. Prints what the ledger itself says is in hand, per
 * currency and per project — the figure the Cashbook page's card and (since 5.18.71) the accounts dashboard's hero show on
 * a book without SSP: CashbookService::cashInHand(). Since 5.18.96 that figure has two rules, and the "rule" line says
 * which one ran. Where the service's cashOnlyRuleInForce() holds (the Uganda tenant and no SSP, from the service's own
 * configuration) it is approved entries with no account or on cash and mobile-money accounts; on any other book it is
 * still the running balance of each currency's stream (rows not voided), accounts and the unassigned split playing no
 * part. The rule line follows that gate, never the tenant line, which is read through another configuration path: a
 * Uganda tenant with SSP prints "uganda" there and runs the older rule. For the operator to read beside the screen, and
 * for a deploy's after-check.
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
$ruleA   = $cb->cashOnlyRuleInForce();   // 5.18.96: the service's own gate decides the rule line, never $tenant
$hasLedger = (bool)$store->getPdo()->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'cb_ledger'")->fetchColumn();

echo "\n== cash in hand (5.18.96) — read-only ==\n";
echo "  data dir   {$dataDir}\n  tenant     {$tenant}\n  base       {$base}\n  currencies " . implode(', ', dn_book_currencies($config)) . "\n";
echo "  rule       " . ($ruleA ? 'approved entries with no account or on cash and mobile-money accounts (5.18.96)'
                         : 'every entry not voided, any account (as before 5.18.96)') . "\n";
if (!$hasLedger) { echo "\n  no cashbook ledger yet (cb_ledger absent) — nothing to show.\n\n"; exit(0); }
if ($ruleA) {
    echo "\n  the ledger's cash in hand per currency (approved entries with no account or on cash and mobile-money accounts):\n";
} else {
    echo "\n  the ledger's running balance per currency (rows not voided; accounts and the unassigned split play no part):\n";
}
foreach (dn_book_currencies($config) as $cur) {
    printf("    %-4s CASH IN HAND  %18s\n", $cur, number_format($cb->cashInHand($cur), 2));
}
echo "\n  the base currency per project (the dashboard hero's chips):\n";
foreach (['dishnet' => 'Fiber & Starlink', '4g' => 'DishNet 4G', 'bluecard' => 'BlueCARD'] as $proj => $lbl) {
    printf("    %-18s %-4s %18s\n", $lbl, $base, number_format($cb->cashInHand($base, $proj), 2));
}
echo "\n  READ-ONLY — nothing was changed.\n\n";
exit(0);
