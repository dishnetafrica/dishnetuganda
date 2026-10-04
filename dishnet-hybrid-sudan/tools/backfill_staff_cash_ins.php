<?php
declare(strict_types=1);
/**
 * backfill_staff_cash_ins.php — 5.18.68. Money the office handed a staff member through the
 * main cashbook BEFORE 5.18.68 never reached their register on a Uganda (UGX) book: the
 * auto-link wrote the cash-in with amount 0, so no staff_ledger row followed. This tool
 * finds every such advance and completes the chain the way the live auto-link now does —
 * through the same cash_ins.json record and StaffLedgerWriter::onCashIn(), idempotent on
 * the cashbook SR and on the CIN-<id> ledger key.
 *
 *   php tools/backfill_staff_cash_ins.php                 dry run: prints the plan, changes nothing
 *   php tools/backfill_staff_cash_ins.php --apply         asks you to type APPLY, then writes
 *   php tools/backfill_staff_cash_ins.php --apply --yes   writes without the question (tests)
 *
 * What it looks at: cb_ledger OUT rows, not voided, in the book's BASE currency, whose
 * category is a staff-advance category (Staff Advance, Commission, SSP Advance — never
 * Salary or an allowance, which are the staff member's own money) and which name a person.
 * What it does per row: no cash-in with cb_ref = SR → CREATE one; a cash-in with amount 0
 * → FIX its amount and currency; a cash-in with an amount → SKIP (already linked). A person
 * matching no active staff member, or more than one, is reported and SKIPPED — never guessed.
 *
 * It refuses to run anywhere but a Uganda book (tenant 'uganda', base currency not USD).
 * It sends no WhatsApp message, touches no uCRM record and never prints a phone number.
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
require_once $root . '/lib/StaffLedgerService.php';
require_once $root . '/lib/StaffLedgerWriter.php';
require_once $root . '/lib/StaffCashPositionService.php';

$args  = array_slice($argv, 1);
$KNOWN = ['--apply', '--yes'];
foreach ($args as $a) {
    if (!in_array($a, $KNOWN, true)) { fwrite(STDERR, "\n  Unknown option: {$a}\n  Known: " . implode(' ', $KNOWN) . "\n\n"); exit(2); }
}
$apply = in_array('--apply', $args, true);
$yes   = in_array('--yes', $args, true);

$dataDir = cliDataDir($root);
$GLOBALS['dataDir'] = $dataDir;                     // dn_book_*() read the effective config from here
$store   = SqliteStore::create($dataDir);
$pdo     = $store->getPdo();
$config  = dn_book_effective_config();
$tenant  = TenantProfile::current($config, $dataDir)->id();
$base    = dn_book_base($config);

echo "\n== staff cash-in backfill (5.18.68) — " . ($apply ? 'APPLY' : 'DRY RUN') . " ==\n";
echo "  data dir   {$dataDir}\n  tenant     {$tenant}\n  base       {$base}\n";
if ($tenant !== 'uganda' || $base === 'USD') {
    echo "\n  REFUSED: this tool is for the Uganda book (tenant 'uganda', base currency other than USD).\n"
       . "  South Sudan's chain never had the defect; nothing to do here.\n\n";
    exit(2);
}

// ── the rows to look at ──────────────────────────────────────────────────────
$STAFF_CATS = ['Staff Advance', 'Commission', 'SSP Advance'];   // the auto-link's $staffCats minus its $personalCats
$in  = implode(',', array_fill(0, count($STAFF_CATS), '?'));
$st  = $pdo->prepare(
    "SELECT id, sr, date, amount, currency, category, person, description, status
     FROM cb_ledger
     WHERE direction = 'out'
       AND status NOT IN ('voided','voided_reconcile','rejected')
       AND category IN ($in)
       AND TRIM(COALESCE(person,'')) <> ''
       AND (currency = ? OR currency IS NULL OR currency = '')
     ORDER BY date ASC, id ASC"
);
$st->execute(array_merge($STAFF_CATS, [$base]));
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$retailers = array_values(array_filter($store->load('retailers.json') ?: [], fn($r) => !empty($r['is_active'])));
$cashIns   = $store->load('cash_ins.json') ?: [];
$byRef     = [];
foreach ($cashIns as $idx => $ci) { $ref = (string)($ci['cb_ref'] ?? ''); if ($ref !== '') $byRef[$ref][] = $idx; }

// the auto-link's matching, made strict: exact name first, else every "contains" match; two names = ambiguous
$match = function (string $person) use ($retailers): array {
    $p = strtolower(trim($person)); $hits = [];
    foreach ($retailers as $r) { if (strtolower((string)($r['name'] ?? '')) === $p) return [$r]; }
    foreach ($retailers as $r) {
        $n = strtolower((string)($r['name'] ?? ''));
        if ($n !== '' && (strpos($n, $p) !== false || strpos($p, $n) !== false)) $hits[(int)$r['id']] = $r;
    }
    return array_values($hits);
};

$plan = []; $counts = ['CREATE' => 0, 'FIX' => 0, 'SKIP' => 0];
foreach ($rows as $r) {
    $sr = (string)$r['sr']; $amt = round((float)$r['amount'], 2); $cur = strtoupper(trim((string)($r['currency'] ?? ''))) ?: $base;
    $hits = $match((string)$r['person']);
    $item = ['sr' => $sr, 'date' => (string)$r['date'], 'category' => (string)$r['category'], 'person' => (string)$r['person'], 'amount' => $amt, 'currency' => $cur,
             'staff_id' => 0, 'staff_name' => '', 'action' => 'SKIP', 'why' => '', 'ci_idx' => null, 'row' => $r];
    if ($amt <= 0) { $item['why'] = 'amount is not positive'; }
    elseif (count($hits) === 0) { $item['why'] = 'no active staff member matches the name'; }
    elseif (count($hits) > 1) { $item['why'] = 'ambiguous — matches ' . implode(' / ', array_map(fn($h) => (string)$h['name'], $hits)); }
    else {
        $item['staff_id'] = (int)$hits[0]['id']; $item['staff_name'] = (string)$hits[0]['name'];
        $linked = $byRef[$sr] ?? [];
        if (!$linked) { $item['action'] = 'CREATE'; $item['why'] = 'no cash-in carries this SR'; }
        else {
            $idx = $linked[0]; $ci = $cashIns[$idx];
            if ((float)($ci['amount'] ?? 0) > 0) { $item['why'] = 'already linked — cash-in #' . (int)($ci['id'] ?? 0) . ' carries ' . number_format((float)$ci['amount'], 2); }
            elseif (strtoupper((string)($ci['category'] ?? '')) === 'SSP RECEIVED') { $item['why'] = 'the cash-in is an SSP record; not this tool\'s'; }
            else { $item['action'] = 'FIX'; $item['ci_idx'] = $idx; $item['why'] = 'cash-in #' . (int)($ci['id'] ?? 0) . ' carries amount 0 (the 5.18.67 defect)'; }
        }
    }
    $counts[$item['action']]++;
    $plan[] = $item;
}

echo "\n  " . count($rows) . " staff-advance OUT row(s) in {$base} name a person; " . count($cashIns) . " cash-in record(s) exist\n\n";
printf("  %-8s %-10s %-16s %-22s %-24s %14s  %s\n", 'SR', 'date', 'category', 'person (as typed)', 'staff member', 'amount', 'action');
foreach ($plan as $it) {
    printf("  %-8s %-10s %-16s %-22s %-24s %14s  %s%s\n", $it['sr'], $it['date'], mb_substr($it['category'], 0, 16), mb_substr($it['person'], 0, 22),
        $it['staff_id'] ? mb_substr($it['staff_name'], 0, 20) . " (#{$it['staff_id']})" : '—', $it['currency'] . ' ' . number_format($it['amount'], 2),
        $it['action'], $it['why'] !== '' ? ' — ' . $it['why'] : '');
}
echo "\n  plan: {$counts['CREATE']} to create, {$counts['FIX']} to fix, {$counts['SKIP']} skipped\n";

if (!$apply) {
    echo "\n  DRY RUN — nothing was changed. To write, run again with --apply.\n\n";
    exit(0);
}
if ($counts['CREATE'] + $counts['FIX'] === 0) { echo "\n  Nothing to apply.\n\n"; exit(0); }
if (!$yes) {
    echo "\n  Type APPLY to write the " . ($counts['CREATE'] + $counts['FIX']) . " row(s) above, anything else to stop: ";
    $tty = @fopen('/dev/tty', 'r'); $answer = $tty ? trim((string)fgets($tty)) : '';
    if ($tty) fclose($tty);
    if ($answer !== 'APPLY') { echo "\n  Not confirmed. Nothing was changed.\n\n"; exit(1); }
}

// ── apply ────────────────────────────────────────────────────────────────────
$done = []; $touched = [];
foreach ($plan as $it) {
    if ($it['action'] === 'FIX') {
        $idx = $it['ci_idx']; $cashIns[$idx]['amount'] = $it['amount']; $cashIns[$idx]['currency'] = $it['currency'];
        $cashIns[$idx]['backfilled_by'] = 'backfill 5.18.68'; $cashIns[$idx]['backfilled_at'] = date('Y-m-d H:i:s');
        $store->save('cash_ins.json', $cashIns);
        StaffLedgerWriter::onCashIn($pdo, $cashIns[$idx]);
        $done[] = "{$it['sr']}: cash-in #" . (int)($cashIns[$idx]['id'] ?? 0) . " fixed to {$it['currency']} " . number_format($it['amount'], 2) . " for {$it['staff_name']}";
        $touched[$it['staff_id']] = $it['staff_name'];
    } elseif ($it['action'] === 'CREATE') {
        $rec = $store->appendWithId('cash_ins.json', [
            'collector_id'   => $it['staff_id'],
            'collector_name' => $it['staff_name'],
            'amount'         => $it['amount'],
            'currency'       => $it['currency'],
            'ssp_amount'     => 0,
            'usd_given'      => 0,
            'rate'           => 0,
            'category'       => 'USD Received',          // the base-bag category (RULE 3: the stored value is the key readers filter on)
            'description'    => 'From Office — ' . $it['category'] . (trim((string)$it['row']['description']) !== '' ? ' (' . trim((string)$it['row']['description']) . ')' : ''),
            'status'         => 'approved',
            'approved_by'    => 'backfill 5.18.68',
            'approved_at'    => date('Y-m-d H:i:s'),
            'cb_ref'         => $it['sr'],
            'created_at'     => $it['date'] . ' 12:00:00',  // the ledger row dates to the day the money went out
        ]);
        $cashIns = $store->load('cash_ins.json') ?: [];
        StaffLedgerWriter::onCashIn($pdo, $rec);
        $done[] = "{$it['sr']}: cash-in #" . (int)($rec['id'] ?? 0) . " created, {$it['currency']} " . number_format($it['amount'], 2) . " for {$it['staff_name']}";
        $touched[$it['staff_id']] = $it['staff_name'];
    }
}
$store->appendWithId('activity_log.json', ['event' => 'cashbook_backfill', 'actor' => 'backfill 5.18.68', 'action' => 'BACKFILL',
    'detail' => count($done) . ' staff cash-in(s) completed: ' . implode('; ', array_map(fn($d) => substr($d, 0, 60), $done)), 'created_at' => date('Y-m-d H:i:s')]);

echo "\n  written:\n"; foreach ($done as $d) echo "    {$d}\n";
$pos = new StaffCashPositionService($store, $pdo);
echo "\n  {$base} in hand now, per staff member touched:\n";
foreach ($touched as $sid => $name) echo "    {$name} (#{$sid}): {$base} " . number_format($pos->getUSDBalance((int)$sid), 2) . "\n";
echo "\n  Done. Run the tool again to see every row read SKIP (already linked).\n\n";
exit(0);
