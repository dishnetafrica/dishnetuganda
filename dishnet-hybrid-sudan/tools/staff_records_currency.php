<?php
declare(strict_types=1);
/**
 * staff_records_currency.php — 5.18.70 (5.18.69's tool, extended to cash-ins). On a book whose base currency is not
 * USD (Uganda, UGX), staff cash records stamped 'USD' by the old literal-USD code paths are shillings wearing the wrong
 * label: they vanish from the UGX register (5.18.68 reads the base bag) and surface as dollars in an export or in a
 * wallet's "Collections". This tool shows them, and repairs the ones a form stamped:
 *   · Manual Entry collections (payment_collections, source manual_adjustment) — the Staff Cashbooks form, fixed in 5.18.69;
 *   · cash-ins (cash_ins, any category) — the Field Register's base pill, which submitted the literal 'USD' under a
 *     UGX label until 5.18.70.
 *
 *   php tools/staff_records_currency.php                    LIST (default): every staff cash record by table and currency,
 *                                                           and every record above stamped in a non-base currency. Changes
 *                                                           nothing.
 *   php tools/staff_records_currency.php --void [--yes]     VOID them — for hand copies of money the main cashbook already
 *                                                           records (the 5.18.68 backfill links the advances themselves;
 *                                                           counting both would double the money). Typed VOID.
 *   php tools/staff_records_currency.php --relabel [--yes]  RELABEL them to the base currency instead — for entries that are
 *                                                           the only record of that money. Typed RELABEL.
 *
 * A void is the page's own void, record for record: for a collection the Staff Cashbooks "Void Collection" (prev_status,
 * voided_by/at, void_reason, audit_log, the matching cb_ledger row by its COL-/PAY- reference); for a cash-in the page's
 * "void_cash_in" (prev_status, voided_by/at, void_reason, the activity line, StaffLedgerWriter::onCashInVoided). A relabel
 * sets the record's currency to the base and, for a cash-in that already has a staff_ledger row, that row's currency too.
 * Nothing is deleted. No message is sent. It refuses to run anywhere but a Uganda book (tenant 'uganda', base ≠ USD).
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
require_once $root . '/lib/StaffLedgerWriter.php';
require_once $root . '/lib/StaffLedgerService.php';

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

echo "\n== staff records by currency (5.18.70) — {$mode} ==\n";
echo "  data dir   {$dataDir}\n  tenant     {$tenant}\n  base       {$base}\n";
if ($tenant !== 'uganda' || $base === 'USD') {
    echo "\n  REFUSED: this tool is for the Uganda book (tenant 'uganda', base currency other than USD).\n"
       . "  On South Sudan the base IS USD; there is nothing mislabelled to show.\n\n";
    exit(2);
}

// ── counts: every staff cash record, by table and currency (a table that exists but is empty reads 0) ───────────────
echo "\n  staff cash records by currency (every row, voided ones included):\n";
$counts = [];
foreach (['payment_collections', 'cash_ins', 'cash_expenses', 'cash_handovers'] as $t) {
    $counts[$t] = [];
    if (!$hasTable($t)) { $counts[$t] = ['(no table yet)' => 0]; continue; }
    foreach ($store->load($t . '.json') ?: [] as $r) { $c = strtoupper(trim((string)($r['currency'] ?? ''))) ?: '(blank)'; $counts[$t][$c] = ($counts[$t][$c] ?? 0) + 1; }
}
foreach (['staff_expenses', 'cash_advances', 'staff_transfers', 'staff_ledger'] as $t) {
    $counts[$t] = [];
    if (!$hasTable($t)) { $counts[$t] = ['(no table yet)' => 0]; continue; }
    foreach ($pdo->query("SELECT COALESCE(NULLIF(UPPER(TRIM(currency)),''),'(blank)') AS c, COUNT(*) AS n FROM [$t] GROUP BY c") as $r) $counts[$t][(string)$r['c']] = (int)$r['n'];
}
foreach ($counts as $t => $byCur) {
    ksort($byCur);
    $parts = []; foreach ($byCur as $c => $n) $parts[] = "{$c} {$n}" . ($c !== $base && $c !== 'SSP' && $n > 0 && $c !== '(no table yet)' ? ' ◄ not the base' : '');
    printf("    %-20s %s\n", $t, $parts ? implode(' · ', $parts) : '0');
}

// ── the records this tool repairs: Manual Entry collections and cash-ins stamped in a non-base currency ──────────────
$cols = $hasTable('payment_collections') ? ($store->load('payment_collections.json') ?: []) : [];
$cins = $hasTable('cash_ins') ? ($store->load('cash_ins.json') ?: []) : [];
$cands = [];
foreach ($cols as $idx => $c) {
    if ((string)($c['source'] ?? '') !== 'manual_adjustment') continue;
    $cur = strtoupper(trim((string)($c['currency'] ?? '')));
    if ($cur === '' || $cur === $base || $cur === 'SSP') continue;
    $cands[] = ['table' => 'payment_collections', 'label' => 'collection', 'idx' => $idx, 'id' => (int)($c['id'] ?? 0), 'staff' => (string)($c['retailer_name'] ?? ''),
                'date' => substr((string)($c['collected_at'] ?? $c['created_at'] ?? ''), 0, 10), 'amount' => round((float)($c['amount'] ?? 0), 2), 'cur' => $cur,
                'cat' => 'Manual Entry', 'desc' => (string)($c['customer_name'] ?? ''), 'status' => (string)($c['status'] ?? 'approved'), 'dir' => 'in'];
}
foreach ($cins as $idx => $i) {
    $cur = strtoupper(trim((string)($i['currency'] ?? '')));
    if ($cur === '' || $cur === $base || $cur === 'SSP') continue;
    $cands[] = ['table' => 'cash_ins', 'label' => 'cash-in', 'idx' => $idx, 'id' => (int)($i['id'] ?? 0), 'staff' => (string)($i['collector_name'] ?? ''),
                'date' => substr((string)($i['created_at'] ?? ''), 0, 10), 'amount' => round((float)($i['amount'] ?? 0), 2), 'cur' => $cur,
                'cat' => (string)($i['category'] ?? ''), 'desc' => (string)($i['description'] ?? ''), 'status' => (string)($i['status'] ?? 'approved'),
                'dir' => strtolower((string)($i['direction'] ?? 'in'))];
}
usort($cands, fn($a, $b) => [$a['date'], $a['table'], $a['id']] <=> [$b['date'], $b['table'], $b['id']]);
$open = array_values(array_filter($cands, fn($x) => $x['status'] !== 'voided'));
echo "\n  Records this tool repairs — Manual Entry collections and cash-ins stamped in a currency other than {$base}: " . count($cands) . " (" . count($open) . " not yet voided)\n";
if ($cands) {
    printf("    %-15s %-10s %-22s %-16s %-6s %14s  %-10s %s\n", 'source', 'date', 'staff member', 'category', 'stamp', 'amount', 'status', 'description');
    foreach ($cands as $x) printf("    %-15s %-10s %-22s %-16s %-6s %14s  %-10s %s\n", $x['label'] . ' #' . $x['id'], $x['date'], mb_substr($x['staff'], 0, 22), mb_substr($x['cat'], 0, 16), $x['cur'],
                                  number_format($x['amount'], 2), $x['status'], mb_substr($x['desc'], 0, 50));
}

if ($mode === 'LIST') {
    echo "\n  LIST — nothing was changed. To void these: --void. To relabel them to {$base}: --relabel.\n\n";
    exit(0);
}
if (!$cands) { echo "\n  Nothing to {$mode}: no Manual Entry collection and no cash-in is stamped in a currency other than {$base}.\n\n"; exit(0); }
if (!$open)  { echo "\n  Nothing to {$mode}: every candidate is already voided.\n\n"; exit(0); }
if (!$yes) {
    echo "\n  Type {$mode} to " . strtolower($mode) . " the " . count($open) . " row(s) above, anything else to stop: ";
    $tty = @fopen('/dev/tty', 'r'); $answer = $tty ? trim((string)fgets($tty)) : '';
    if ($tty) fclose($tty);
    if ($answer !== $mode) { echo "\n  Not confirmed. Nothing was changed.\n\n"; exit(1); }
}

$by   = 'staff_records_currency 5.18.70';
$now  = date('Y-m-d H:i:s');
$done = []; $activity = []; $ledgerAfter = []; $colsChanged = false; $cinsChanged = false;
foreach ($open as $x) {
    $idx = $x['idx'];
    if ($x['table'] === 'payment_collections') {
        $c = &$cols[$idx];
        if ($mode === 'VOID') {
            $reason = "stamped {$x['cur']} by the Manual Entry form on a {$base} book; a hand entry made while the staff advance link wrote 0 — the 5.18.68 backfill links the advance itself (5.18.70)";
            $c['prev_status'] = $c['status'] ?? 'approved';
            $c['status']      = 'voided';
            $c['voided_by']   = $by;
            $c['voided_at']   = $now;
            $c['void_reason'] = $reason;
            $c['audit_log']   = $c['audit_log'] ?? [];
            $c['audit_log'][] = ['action' => 'void', 'by' => $by, 'at' => $now, 'reason' => $reason];
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
            $c['audit_log'][] = ['action' => 'relabel', 'by' => $by, 'at' => $now, 'from' => $x['cur'], 'to' => $base,
                                 'reason' => "stamped {$x['cur']} by the Manual Entry form on a {$base} book (5.18.70)"];
            $done[] = "collection #{$x['id']} relabelled {$x['cur']} → {$base} (" . number_format($x['amount'], 2) . ", {$x['staff']})";
        }
        unset($c); $colsChanged = true;
    } else {
        $c = &$cins[$idx];
        if ($mode === 'VOID') {
            $reason = "stamped {$x['cur']} by the Field Register's base pill on a {$base} book (the pill read {$base} and submitted 'USD' until 5.18.70); a hand entry of money the main cashbook records — voided so it is not counted twice (5.18.70)";
            // the Staff Cashbooks page's own void_cash_in, field for field
            $c['prev_status'] = $c['status'] ?? 'approved';
            $c['status']      = 'voided';
            $c['voided_by']   = $by;
            $c['voided_at']   = $now;
            $c['void_reason'] = $reason;
            $activity[] = ['action' => 'void_cash_in', 'title' => "Voided cash_in #{$x['id']} for " . ($c['collector_name'] ?? '?') . ": {$reason}", 'detail' => $by, 'time' => $now, 'date' => date('Y-m-d')];
            $ledgerAfter[] = ['void', $x['id'], $x['dir']];
            $done[] = "cash-in #{$x['id']} voided ({$x['cur']} " . number_format($x['amount'], 2) . ", {$x['cat']}, {$x['staff']})";
        } else {
            $c['currency']    = $base;
            $c['audit_log']   = $c['audit_log'] ?? [];
            $c['audit_log'][] = ['action' => 'relabel', 'by' => $by, 'at' => $now, 'from' => $x['cur'], 'to' => $base,
                                 'reason' => "stamped {$x['cur']} by the Field Register's base pill on a {$base} book (5.18.70)"];
            $ledgerAfter[] = ['relabel', $x['id'], $x['dir']];
            $done[] = "cash-in #{$x['id']} relabelled {$x['cur']} → {$base} (" . number_format($x['amount'], 2) . ", {$x['cat']}, {$x['staff']})";
        }
        unset($c); $cinsChanged = true;
    }
}
if ($colsChanged) $store->save('payment_collections.json', $cols);
if ($cinsChanged) $store->save('cash_ins.json', $cins);
// the staff_ledger side, after the records are saved (the page's order): void by the cash-in's keys, or relabel the live row
$ledgerNotes = [];
foreach ($ledgerAfter as [$what, $id, $dir]) {
    if ($what === 'void') {
        StaffLedgerWriter::onCashInVoided($pdo, $id, $by);                                   // CIN-<id>, as the page does
        if ($dir === 'out') { try { (new StaffLedgerService($pdo))->voidByKey('CINO-' . $id, $by, 'Cash in voided'); } catch (Throwable $e) {} }
    } else {
        try {
            $st = $pdo->prepare("UPDATE staff_ledger SET currency = ?, updated_at = datetime('now') WHERE idempotency_key IN (?, ?) AND status != 'voided'");
            $st->execute([$base, 'CIN-' . $id, 'CINO-' . $id]);
            if ($st->rowCount() > 0) $ledgerNotes[] = "staff_ledger CIN-{$id}: currency → {$base}";
        } catch (Throwable $e) { $ledgerNotes[] = "staff_ledger CIN-{$id}: not updated (" . $e->getMessage() . ")"; }
    }
}
foreach ($activity as $a) $store->appendWithId('activity_log.json', $a);
$store->appendWithId('activity_log.json', ['event' => 'staff_records_currency', 'actor' => $by, 'action' => $mode,
    'detail' => count($done) . ' record(s) ' . strtolower($mode) . 'ed: ' . implode('; ', array_map(fn($d) => substr($d, 0, 60), $done)), 'created_at' => $now]);
echo "\n  written:\n"; foreach ($done as $d) echo "    {$d}\n"; foreach ($ledgerNotes as $d) echo "    {$d}\n";
echo "\n  Done. Run the tool again (no option) to see the list read voided / {$base}.\n\n";
exit(0);
