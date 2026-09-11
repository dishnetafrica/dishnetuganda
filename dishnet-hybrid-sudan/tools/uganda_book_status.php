<?php
declare(strict_types=1);
/**
 * uganda_book_status.php — READ-ONLY snapshot of the cashbook: every active
 * ledger row, the per-currency positions, and the year's P&L. Writes nothing,
 * needs no --confirm; run it any time to see what the book says.
 *
 *   php tools/uganda_book_status.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/CashbookService.php';
require_once $root . '/lib/currency.php';

$dataDir = cliDataDir($root);
$store   = SqliteStore::create($dataDir);
$cb      = new CashbookService($store, $dataDir);
$pdo     = $store->getPdo();

echo "══ Book status (read-only) — " . date('Y-m-d H:i') . "\n";
echo "   data dir: {$dataDir} · book base: " . $cb->bookBase() . "\n\n";

echo "── Active ledger rows (oldest first)\n";
$rows = $pdo->query(
    "SELECT sr, date, direction, amount, currency, category, account_id, validation_ref, description
     FROM cb_ledger WHERE status NOT IN ('voided','voided_reconcile') ORDER BY date, id"
)->fetchAll(PDO::FETCH_ASSOC);
$acctNames = [0 => '—'];
foreach ($cb->accounts() as $a) $acctNames[(int)$a['id']] = $a['name'];
foreach ($rows as $r) {
    printf("  %-6s %s %-3s %4s %14s  %-18s %-24s %s\n",
        $r['sr'], $r['date'], strtoupper($r['direction']), $r['currency'],
        number_format((float)$r['amount'], 0),
        mb_substr($r['category'], 0, 18),
        mb_substr($acctNames[(int)$r['account_id']] ?? ('#' . $r['account_id']), 0, 24),
        $r['validation_ref'] !== '' ? $r['validation_ref'] : '');
}
$voided = (int)$pdo->query("SELECT COUNT(*) FROM cb_ledger WHERE status IN ('voided','voided_reconcile')")->fetchColumn();
echo "  (" . count($rows) . " active row(s); {$voided} voided row(s) kept as audit trail)\n\n";

echo "── Positions\n";
foreach ($cb->currencyPositions() as $pos) {
    echo "  {$pos['currency']} POSITION (cash): {$pos['currency']} " . number_format($pos['total'], 2) . "\n";
    foreach ($pos['accounts'] as $a) {
        if (!(float)$a['balance'] && empty($a['active'])) continue;
        echo "    {$a['name']} ({$a['kind']}): {$pos['currency']} " . number_format((float)$a['balance'], 2) . "\n";
    }
    if (abs($pos['unassigned']) > 0.004) {
        echo "    unassigned rows: {$pos['currency']} " . number_format($pos['unassigned'], 2) . "\n";
    }
    foreach ($pos['counterparts'] ?? [] as $a) {
        if (!(float)$a['balance'] && empty($a['active'])) continue;
        $kindLbl = $a['kind'] === 'equity' ? 'capital — not a debt' : $a['kind'];
        echo "    [{$kindLbl}] {$a['name']}: {$pos['currency']} " . number_format((float)$a['balance'], 2) . "\n";
    }
}

$pl = $cb->plByPeriod('dishnet', date('Y') . '-01-01', date('Y') . '-12-31');
echo "\n── " . date('Y') . " P&L\n";
foreach ($pl as $cur => $p) {
    $rev = (float)($p['revenue_total'] ?? 0);
    $exp = (float)($p['expense_total'] ?? 0);
    if (!$rev && !$exp) continue;
    echo "  {$cur}: revenue " . number_format($rev, 0) . " · expenses " . number_format($exp, 0)
       . " · net " . number_format((float)($p['net'] ?? ($rev - $exp)), 0) . "\n";
    foreach (($p['expenses'] ?? []) as $cat => $amt) {
        echo "      {$cat}: " . number_format((float)$amt, 0) . "\n";
    }
}
echo "\nRead-only — nothing was changed.\n";
