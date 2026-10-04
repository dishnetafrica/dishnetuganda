<?php
declare(strict_types=1);
/**
 * staff_records_currency.php — 5.18.69. On a book whose base currency is not USD (Uganda, UGX), staff cash records
 * stamped 'USD' by the old literal-USD code paths are shillings wearing the wrong label: they vanish from the UGX
 * register (5.18.68 reads the base bag) and surface as dollars in an export. This tool shows them, and repairs the
 * ones the Manual Entry form stamped.
 *
 *   php tools/staff_records_currency.php                    LIST (default): counts per table and currency, and every
 *                                                           Manual Entry collection stamped in a non-base currency.
 *                                                           Changes nothing.
 *   php tools/staff_records_currency.php --void [--yes]     VOID those Manual Entry collections — for hand copies of
 *                                                           main-cashbook advances that the 5.18.68 backfill now links
 *                                                           (counting both would double the money). Typed VOID.
 *   php tools/staff_records_currency.php --relabel [--yes]  RELABEL them to the base currency instead — for hand
 *                                                           entries that are the only record of that money. Typed RELABEL.
 *
 * A void is the Staff Cashbooks page's own void, record for record (prev_status, voided_by/at, void_reason, audit_log,
 * and the matching cb_ledger row by its COL-/PAY- reference when one exists). Nothing is deleted. No message is sent.
 * It refuses to run anywhere but a Uganda book (tenant 'uganda', base currency other than USD).
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

$args  = array_slice($argv, 1);
$KNOWN = ['--void', '--relabel', '--yes'];
foreach ($args as $a) {
    if (!in_array($a, $KNOWN, true)) { fwrite(STDERR, "\n  Unknown option: {$a}\n  Known: " . implode(' ', $KNOWN) . "\n\n"); exit(2); }
}
$doVoid = in_array('--void', $args, true); $doRelabel = in_array('--relabel', $args, true); $yes = in_array('--yes', $args, true);
if ($doVoid && $doRelabel) { fwrite(STDERR, "\n  --void and --relabel exclude each other.\n\n"); exit(2); }
$mode = $doVoid ? 'VOID' : ($doRelabel ? 'RELABEL' : 'LIST');

$dataDir = cliDataDir($root);
$GLOBALS['dataDir'] = $dataDir;
$store   = SqliteStore::create($dataDir);
$pdo     = $store->getPdo();
$config  = dn_book_effective_config();
$tenant  = TenantProfile::current($config, $dataDir)->id();
$base    = dn_book_base($config);
$hasTable = function (string $t) use ($pdo): bool { return (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = " . $pdo->quote($t))->fetchColumn(); };

echo "\n== staff records by currency (5.18.69) — {$mode} ==\n";
echo "  data dir   {$dataDir}\n  tenant     {$tenant}\n  base       {$base}\n";
if ($tenant !== 'uganda' || $base === 'USD') {
    echo "\n  REFUSED: this tool is for the Uganda book (tenant 'uganda', base currency other than USD).\n"
       . "  On South Sudan the base IS USD; there is nothing mislabelled to show.\n\n";
    exit(2);
}

// ── counts: every staff cash record, by table and currency ───────────────────────
echo "\n  staff cash records by currency (every row, voided ones included):\n";
$counts = [];
foreach (['payment_collections', 'cash_ins', 'cash_expenses', 'cash_handovers'] as $t) {
    if (!$hasTable($t)) { $counts[$t] = ['(no table yet)' => 0]; continue; }
    foreach ($store->load($t . '.json') ?: [] as $r) { $c = strtoupper(trim((string)($r['currency'] ?? ''))) ?: '(blank)'; $counts[$t][$c] = ($counts[$t][$c] ?? 0) + 1; }
}
foreach (['staff_expenses', 'cash_advances', 'staff_transfers', 'staff_ledger'] as $t) {
    if (!$hasTable($t)) { $counts[$t] = ['(no table yet)' => 0]; continue; }
    foreach ($pdo->query("SELECT COALESCE(NULLIF(UPPER(TRIM(currency)),''),'(blank)') AS c, COUNT(*) AS n FROM [$t] GROUP BY c") as $r) $counts[$t][(string)$r['c']] = (int)$r['n'];
}
foreach ($counts as $t => $byCur) {
    ksort($byCur);
    $parts = []; foreach ($byCur as $c => $n) $parts[] = "{$c} {$n}" . ($c !== $base && $c !== 'SSP' && $n > 0 && $c !== '(no table yet)' ? ' ◄ not the base' : '');
    printf("    %-20s %s\n", $t, implode(' · ', $parts) ?: '0');
}

// ── the Manual Entry collections stamped in a non-base currency ──────────────────
$cols = $hasTable('payment_collections') ? ($store->load('payment_collections.json') ?: []) : [];
$cands = [];
foreach ($cols as $idx => $c) {
    if ((string)($c['source'] ?? '') !== 'manual_adjustment') continue;
    $cur = strtoupper(trim((string)($c['currency'] ?? '')));
    if ($cur === '' || $cur === $base || $cur === 'SSP') continue;
    $cands[] = ['idx' => $idx, 'id' => (int)($c['id'] ?? 0), 'staff' => (string)($c['retailer_name'] ?? ''), 'staff_id' => (int)($c['retailer_id'] ?? 0),
                'date' => substr((string)($c['collected_at'] ?? $c['created_at'] ?? ''), 0, 10), 'amount' => round((float)($c['amount'] ?? 0), 2), 'cur' => $cur,
                'desc' => (string)($c['customer_name'] ?? ''), 'status' => (string)($c['status'] ?? 'approved')];
}
$open = array_values(array_filter($cands, fn($x) => $x['status'] !== 'voided'));
echo "\n  Manual Entry collections stamped in a currency other than {$base}: " . count($cands) . " (" . count($open) . " not yet voided)\n";
if ($cands) {
    printf("    %-5s %-10s %-22s %-6s %14s  %-10s %s\n", 'id', 'date', 'staff member', 'stamp', 'amount', 'status', 'description');
    foreach ($cands as $x) printf("    #%-4d %-10s %-22s %-6s %14s  %-10s %s\n", $x['id'], $x['date'], mb_substr($x['staff'], 0, 22), $x['cur'], number_format($x['amount'], 2), $x['status'], mb_substr($x['desc'], 0, 50));
}

if ($mode === 'LIST') {
    echo "\n  LIST — nothing was changed. To void these: --void. To relabel them to {$base}: --relabel.\n\n";
    exit(0);
}
if (!$open) { echo "\n  Nothing to {$mode}: every candidate is already voided.\n\n"; exit(0); }
if (!$yes) {
    echo "\n  Type {$mode} to " . strtolower($mode) . " the " . count($open) . " row(s) above, anything else to stop: ";
    $tty = @fopen('/dev/tty', 'r'); $answer = $tty ? trim((string)fgets($tty)) : '';
    if ($tty) fclose($tty);
    if ($answer !== $mode) { echo "\n  Not confirmed. Nothing was changed.\n\n"; exit(1); }
}

$by   = 'staff_records_currency 5.18.69';
$done = [];
foreach ($open as $x) {
    $idx = $x['idx']; $c = &$cols[$idx];
    if ($mode === 'VOID') {
        $reason = "stamped {$x['cur']} by the Manual Entry form on a {$base} book; a hand entry made while the staff advance link wrote 0 — the 5.18.68 backfill links the advance itself (5.18.69)";
        $c['prev_status'] = $c['status'] ?? 'approved';
        $c['status']      = 'voided';
        $c['voided_by']   = $by;
        $c['voided_at']   = date('Y-m-d H:i:s');
        $c['void_reason'] = $reason;
        $c['audit_log']   = $c['audit_log'] ?? [];
        $c['audit_log'][] = ['action' => 'void', 'by' => $by, 'at' => date('Y-m-d H:i:s'), 'reason' => $reason];
        // the page's void also voids the cashbook row that carries this collection's reference, when one exists
        $ref = ($c['crm_payment_id'] ?? null) ? 'PAY-' . $c['crm_payment_id'] : 'COL-' . $x['id'];
        try {
            $st = $pdo->prepare("UPDATE cb_ledger SET status='voided', description=description||' [VOIDED: '||?||']' WHERE validation_ref=? AND status!='voided'");
            $st->execute([$reason, $ref]); $n = $st->rowCount();
        } catch (Throwable $e) { $n = 0; }
        $done[] = "collection #{$x['id']} voided ({$x['cur']} " . number_format($x['amount'], 2) . ", {$x['staff']})" . ($n ? " + cashbook row {$ref}" : '');
    } else {
        $c['currency']    = $base;
        $c['audit_log']   = $c['audit_log'] ?? [];
        $c['audit_log'][] = ['action' => 'relabel', 'by' => $by, 'at' => date('Y-m-d H:i:s'), 'from' => $x['cur'], 'to' => $base,
                             'reason' => "stamped {$x['cur']} by the Manual Entry form on a {$base} book (5.18.69)"];
        $done[] = "collection #{$x['id']} relabelled {$x['cur']} → {$base} (" . number_format($x['amount'], 2) . ", {$x['staff']})";
    }
    unset($c);
}
$store->save('payment_collections.json', $cols);
$store->appendWithId('activity_log.json', ['event' => 'staff_records_currency', 'actor' => $by, 'action' => $mode,
    'detail' => count($done) . ' Manual Entry collection(s) ' . strtolower($mode) . 'ed: ' . implode('; ', array_map(fn($d) => substr($d, 0, 60), $done)), 'created_at' => date('Y-m-d H:i:s')]);
echo "\n  written:\n"; foreach ($done as $d) echo "    {$d}\n";
echo "\n  Done. Run the tool again (no option) to see the list read voided / {$base}.\n\n";
exit(0);
