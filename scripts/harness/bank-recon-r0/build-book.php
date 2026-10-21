<?php
/**
 * build-book.php — synthetic books for the R0 rehearsal, written through the plugin's own code (a COPY of lib/,
 * migrations/ and tools/staff_records_currency.php in the sandbox, never the checkout's), so the rows look exactly as the
 * plugin writes them. Where a writer is a page or an endpoint, its exact SQL is reproduced, and the statement text is
 * asserted to occur verbatim in that file of the checkout (read only). Facts the plugin cannot write (a void minute before
 * 4 October, a Latin-1 byte, a truncated JSON row, a '{}' snapshot) are written by SQL in the sandbox and listed in
 * "sql_planted".
 *
 *   DN_VAULT_FILE=<sandbox>/vault.json php build-book.php <sandbox plugin root> <data dir> <variant>   > ids.json
 *
 * Variants: ug · ug-novoid · ug-mixed · kyc-first · bare · proper · ss · i2 · big (the specification's §5.1).
 * Every name, description, person, reference and reason is fictitious and carries RH-MARK (or is RH-Client-<n>, a
 * phone-shaped 0772 123 456, an account-number-shaped 7000000001, BANK-REF001, RH-MID-0001), so the rehearsal can prove
 * none of them reaches R0's output. Every amount has at least 7 digits before the point and non-zero cents.
 * Prints JSON: the ids the rehearsal needs, and "truth" — the headline figures the plants decide by construction.
 */
declare(strict_types=1);
[$_, $root, $data, $variant] = $argv + [null, '', '', ''];
if ((string)getenv('DN_VAULT_FILE') === '') { fwrite(STDERR, "refusing: DN_VAULT_FILE must point into the sandbox\n"); exit(2); }
$CHECKOUT = dirname(__DIR__, 3) . '/dishnet-hybrid-sudan';
if (!is_file("$root/lib/CashbookService.php") || realpath($root) === realpath($CHECKOUT)) {
    fwrite(STDERR, "refusing: <sandbox plugin root> must be a copy outside the checkout\n"); exit(2);
}
$VARIANTS = ['ug', 'ug-novoid', 'ug-mixed', 'kyc-first', 'bare', 'proper', 'ss', 'i2', 'big'];
if (!in_array($variant, $VARIANTS, true)) { fwrite(STDERR, "unknown variant $variant\n"); exit(2); }
function die3(string $m): void { fwrite(STDERR, "fixture step failed: $m\n"); exit(3); }
/** The statement text must occur verbatim in that file of the checkout (read only). */
function verbatim(string $file, string $text): void {
    $s = (string)@file_get_contents($file);
    if ($s === '' || substr_count($s, $text) < 1) die3('not verbatim in ' . basename($file) . ': ' . substr($text, 0, 80));
}
$GLOBALS['dataDir'] = $data;
if (!is_dir($data)) mkdir($data, 0755, true);
$sqlPlanted = [];

// kyc-first: cb_ledger is created FIRST by the KYC writer's own CREATE TABLE (lib/KycService.php:1863-1871), taken out
// of the sandbox copy of that file and asserted to be exactly one statement there.
if ($variant === 'kyc-first') {
    $k = (string)file_get_contents("$root/lib/KycService.php");
    if (!preg_match_all('/\$pdo->exec\("(CREATE TABLE IF NOT EXISTS cb_ledger \(\s*id INTEGER PRIMARY KEY AUTOINCREMENT,\s*project TEXT DEFAULT \'dishnet\',\s*date TEXT, direction TEXT.*?created_at TEXT\s*\))"\);/s', $k, $m) || count($m[1]) !== 1) {
        die3('KycService.php: the cb_ledger CREATE TABLE was not found exactly once');
    }
    $p = new PDO('sqlite:' . $data . '/plugin.sqlite3'); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $p->exec($m[1][0]); $p = null;
    $sqlPlanted[] = 'cb_ledger created by the KYC writer\'s CREATE TABLE before any other writer';
}

require_once "$root/lib/SqliteStore.php";
require_once "$root/lib/CashbookService.php";
require_once "$root/lib/FinAudit.php";
require_once "$root/lib/StaffLedgerService.php";
require_once "$root/lib/StaffLedgerWriter.php";
$UG = ['tenant_profile' => 'uganda', 'cashbook_base_currency' => 'UGX', 'cashbook_currencies' => 'UGX,USD'];
$store = SqliteStore::create($data);
$put   = static function (string $f, array $v): void { file_put_contents($f, json_encode($v, JSON_PRETTY_PRINT)); chmod($f, 0600); };
if ($variant !== 'ss') { $store->save('kyc_config.json', $UG); $put("$data/kyc_config.json", $UG); }
$cb  = new CashbookService($store, $data);
$pdo = $store->getPdo();
FinAudit::ensureTable($pdo);
$sl  = new StaffLedgerService($pdo);
$ids = []; $truth = [];
$must = static function ($r, string $what) { if (!is_array($r) || empty($r['ok'])) die3($what . ' ' . json_encode($r)); return $r; };
$admin = ['name' => 'RH-MARK Actor 1'];
// unique amounts: >= 7 digits before the point, cents never 00 (the recorded adjustment is whole shillings)
$n = 0;
$A = static function () use (&$n): float { $n++; return round(2000000 + $n * 10007 + (11 + $n % 87) / 100, 2); };
$row = static function (array $d) use ($cb, $pdo): int { $cb->addEntryRaw($d); return (int)$pdo->query('SELECT MAX(id) FROM cb_ledger')->fetchColumn(); };
$q1 = static function (string $sql, array $p = []) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($p); return $s; };
/**
 * A row cannot be voided before it exists. addEntry and addEntryRaw stamp "now" as created_at, so a void minute planted in
 * the past would come before the row's creation, which no real book holds: the creation time is moved back to the row's
 * own date at 08:00, or to the minute's day at 00:00 when that is still later (SQL, in the sandbox).
 */
$bornBy = static function (array $rowIds, string $minute) use ($q1, &$sqlPlanted): void {
    foreach ($rowIds as $x) {
        $r = $q1('SELECT created_at, date FROM cb_ledger WHERE id=?', [$x])->fetch(PDO::FETCH_ASSOC) ?: [];
        $made = str_replace('T', ' ', substr((string)($r['created_at'] ?? ''), 0, 16));
        if ($made === '' || strcmp($made, $minute) <= 0) continue;
        $new = substr((string)($r['date'] ?? ''), 0, 10) . ' 08:00';
        if (!preg_match('/^\d{4}-\d{2}-\d{2} 08:00$/', $new) || strcmp($new, $minute) > 0) $new = substr($minute, 0, 10) . ' 00:00';
        $q1('UPDATE cb_ledger SET created_at=? WHERE id=?', [$new . ':00', $x]);
        $sqlPlanted[] = "creation time of row $x moved back to $new, before its planted void minute $minute";
    }
};
/** Move a voidEntry stamp's minute and its fin_audit void record's time (SQL, in the sandbox). */
$shift = static function (int $id, string $minute, bool $pairByRef = false) use ($pdo, $q1, $bornBy, &$sqlPlanted): void {
    $ids = [$id];
    if ($pairByRef) {
        $r = $q1('SELECT validation_ref, source FROM cb_ledger WHERE id=?', [$id])->fetch(PDO::FETCH_ASSOC);
        $ids = array_map('intval', $q1('SELECT id FROM cb_ledger WHERE validation_ref=? AND source=?', [$r['validation_ref'], $r['source']])->fetchAll(PDO::FETCH_COLUMN));
    }
    foreach ($ids as $x) {
        $d = (string)$q1('SELECT description FROM cb_ledger WHERE id=?', [$x])->fetchColumn();
        $d2 = preg_replace('/ (\d{4}-\d{2}-\d{2} \d{2}:\d{2})\]$/', ' ' . $minute . ']', $d, 1, $c);
        if ($c !== 1) die3("shift: no actor+minute stamp at the end of row $x");
        $q1('UPDATE cb_ledger SET description=? WHERE id=?', [$d2, $x]);
        $q1("UPDATE fin_audit SET created_at=? WHERE record_type='cb_ledger' AND record_id=? AND action='void'", [$minute . ':00', $x]);
    }
    $sqlPlanted[] = "void minute of row(s) " . implode(',', $ids) . " set to $minute";
    $bornBy($ids, $minute);
};
// The payment.delete branch of webhook.php, as it writes (webhook.php:3557-3598): the original found by its payment id
// with no status filter, a live reversal whose description copies the first 80 bytes of the original's — stamps
// included —, then the original marked. Each statement and the description's expression asserted verbatim.
$WH = "$CHECKOUT/webhook.php";
$REV_FIND = "SELECT id, sr, amount, currency, person, description, direction, project
                 FROM cb_ledger WHERE crm_payment_id = ? AND direction = 'in' LIMIT 1";
$REV_MARK = "UPDATE cb_ledger SET description = description || ' [REVERSED — CRM deleted]',
                            validation_status = 'na'
                     WHERE id = ?";
verbatim($WH, $REV_FIND); verbatim($WH, $REV_MARK);
verbatim($WH, "'description'       => 'AUTO-REVERSAL: CRM Payment #' . \$paymentId . ' deleted — '
                                         . substr(\$originalDesc, 0, 80),");
$rev = static function (int $paymentId, string $when) use ($q1, $pdo, $cb, $REV_FIND, $REV_MARK): int {
    $existing = $q1($REV_FIND, [$paymentId])->fetchAll(PDO::FETCH_ASSOC);
    if (!$existing) die3("reversal of payment $paymentId: no original (the webhook writes none then)");
    $orig = $existing[0]; $originalDesc = $orig['description'] ?? '';
    $cb->addEntryRaw(['project' => $orig['project'] ?? 'dishnet', 'date' => substr($when, 0, 10), 'direction' => 'out', 'amount' => (float)$orig['amount'],
        'currency' => (string)($orig['currency'] ?? 'UGX'), 'category' => 'Refund', 'txn_type' => 'REFUND', 'category_raw' => 'CRM Payment Deleted',
        'person' => $orig['person'] ?? '', 'description' => 'AUTO-REVERSAL: CRM Payment #' . $paymentId . ' deleted — ' . substr($originalDesc, 0, 80),
        'validation_ref' => 'REV-CRM-PAY-' . $paymentId, 'validation_status' => 'done', 'status' => 'approved', 'approved_by' => 'System',
        'crm_payment_id' => $paymentId, 'crm_client_id' => 0, 'source' => 'crm_webhook_reversal', 'created_at' => $when]);
    $id = (int)$pdo->query('SELECT MAX(id) FROM cb_ledger')->fetchColumn();
    $q1($REV_MARK, [(int)$orig['id']]);
    return $id;
};
$descOf = static function (int $id) use ($q1): string { return (string)$q1('SELECT description FROM cb_ledger WHERE id=?', [$id])->fetchColumn(); };
// the CRM pull's receipt text (lib/CashbookService.php:2543) and the webhook's (webhook.php:1223), asserted verbatim
verbatim("$CHECKOUT/lib/CashbookService.php", "\$desc = \$methodLabel.' from '.\$customerName.(\$clientId?' (CRM #'.\$clientId.')':'');");
verbatim($WH, '$desc   = "Payment received from {$name} (CRM #{$clientId}){$invStr}";');
/** A receipt as the CRM pull writes it (lib/CashbookService.php:2545-2565): created_at keeps uCRM's createdDate, "T" and all. */
$pull = static function (int $p, int $cli, float $amt, string $d, string $name, string $createdDate) use ($row): int {
    return $row(['sr' => 'CRM-' . $p, 'project' => 'dishnet', 'date' => $d, 'direction' => 'in', 'amount' => $amt, 'currency' => 'UGX', 'category' => 'Receipt',
        'category_raw' => 'Receipt', 'person' => '', 'description' => 'Cash from ' . $name . ($cli ? ' (CRM #' . $cli . ')' : ''), 'validation_ref' => 'PAY-' . $p,
        'validation_status' => 'na', 'status' => 'approved', 'approved_by' => 'CRM Pull', 'crm_payment_id' => $p, 'crm_client_id' => $cli,
        'source' => 'crm_api_sync', 'created_at' => substr($createdDate, 0, 19)]);
};

// ════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
if ($variant === 'ss') {
    $put("$data/kyc_config.json", ['tenant_profile' => 'south-sudan']);
    $cash = (int)$must($cb->addAccount('RH-MARK Cash Juba', 'USD', 'cash'), 'cash')['id'];
    // §1.11 with SSP: ranked by ssp_amount, not amount (the two orders are opposite on purpose)
    $ssp = [[1200000.11, 9800000.22], [1900000.33, 2100000.44], [1500000.55, 5400000.66], [1700000.77, 3300000.88],
            [1100000.19, 7700000.29], [1800000.39, 1300000.49]];
    foreach ($ssp as $k => [$usd, $s]) $ids['ssp'][] = $row(['direction' => $k % 2 ? 'out' : 'in', 'amount' => $usd, 'currency' => 'SSP', 'ssp_amount' => $s,
        'ssp_rate' => 1300, 'category' => 'Receipt', 'description' => 'RH-MARK ssp ' . $k, 'date' => '2026-09-0' . ($k + 1), 'status' => 'approved']);
    $v = $row(['direction' => 'in', 'amount' => $A(), 'currency' => 'USD', 'category' => 'Receipt', 'description' => 'RH-MARK usd', 'date' => '2026-09-07', 'status' => 'approved']);
    $must($cb->voidEntry($v, 'RH-MARK reason 1', 'RH-MARK Actor 1'), 'ss void'); $shift($v, '2026-09-30 08:00');
    $row(['direction' => 'out', 'amount' => $A(), 'currency' => 'SSP', 'category' => 'Transport', 'description' => 'RH-MARK ssp, no ssp_amount', 'date' => '2026-09-05', 'status' => 'approved']);
    $row(['direction' => 'in', 'amount' => $A(), 'currency' => 'USD', 'category' => 'Receipt', 'description' => 'RH-MARK on cash', 'date' => '2026-09-06', 'status' => 'approved', 'account_id' => $cash]);
    $truth[] = ['^                  SSP  1  id ' . $ids['ssp'][0] . '  2026-09-01  in  approved$', 'SSP ranked by ssp_amount: the largest ssp_amount first'];
    $truth[] = ['^                  SSP  2  id ' . $ids['ssp'][4] . '  2026-09-05  in  approved$', 'SSP rank 2 by ssp_amount'];
    echo json_encode(['variant' => $variant, 'ids' => $ids, 'truth' => $truth, 'sql_planted' => $sqlPlanted], JSON_PRETTY_PRINT), "\n";
    exit(0);
}

// ════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
// i2: only the I2 shapes of §1.3 (docs/69:394, :562), so every figure of the void-time lines is decided by construction
// here, not by expect.php: A a webhook receipt voided, then reversed (the stamp copied cut short, the reversal live);
// B a short pull receipt voided, then reversed (the whole stamp copied) and its reversal voided after 4 Oct; P1 a live
// hand entry holding pasted text with a stamp; P2 the same, voided, its own stamp edited away; T a pull receipt whose
// created_at keeps uCRM's "T", voided the same afternoon; C an ordinary void after 4 Oct (the control).
if ($variant === 'i2') {
    $oA = $row(['sr' => 'CRM-801', 'direction' => 'in', 'amount' => $A(), 'currency' => 'UGX', 'category' => 'Receipt', 'category_raw' => 'Receipt',
        'description' => 'Payment received from RH-Client-7 (CRM #5021)', 'date' => '2026-09-18', 'validation_ref' => 'PAY-801', 'crm_payment_id' => 801,
        'crm_client_id' => 5021, 'source' => 'crm_webhook', 'status' => 'approved', 'approved_by' => 'CRM', 'created_at' => '2026-09-18 09:00:00']);
    $must($cb->voidEntry($oA, 'RH-MARK reason 1', 'RH-MARK Actor 1'), 'i2 void A'); $shift($oA, '2026-09-20 10:00');
    $rA = $rev(801, '2026-10-10 08:00:00');
    if (strpos($descOf($rA), '[VOIDED:') === false || preg_match('/ \d{4}-\d{2}-\d{2} \d{2}:\d{2}\]/', $descOf($rA))) die3('i2 A: the reversal does not hold a stamp cut short');
    $oB = $pull(802, 0, $A(), '2026-09-18', 'RH-Client-8', '2026-09-18T09:00:00+0300');
    $must($cb->voidEntry($oB, 'RH-MARK', 'RH-MARK'), 'i2 void B'); $shift($oB, '2026-09-20 11:00');
    $rB = $rev(802, '2026-10-10 09:30:00');
    if (strpos($descOf($rB), ' [VOIDED: RH-MARK — by RH-MARK 2026-09-20 11:00]') === false) die3('i2 B: the reversal does not hold the whole copied stamp');
    $must($cb->voidEntry($rB, 'RH-MARK reason 2', 'RH-MARK Actor 2'), 'i2 void rev B'); $shift($rB, '2026-10-12 09:00');
    $p1 = (int)$must($cb->addEntry(['direction' => 'out', 'amount' => $A(), 'currency' => 'UGX', 'category' => 'Transport',
        'description' => 'RH-MARK re-entered [VOIDED: RH-MARK — by RH-MARK 2026-09-21 11:00]', 'date' => '2026-10-02', 'created_at' => '2026-10-02 12:00:00'], $admin), 'i2 P1')['id'];
    $p2 = (int)$must($cb->addEntry(['direction' => 'out', 'amount' => $A(), 'currency' => 'UGX', 'category' => 'Transport',
        'description' => 'RH-MARK copied in [VOIDED: RH-MARK — by RH-MARK 2026-09-22 11:00]', 'date' => '2026-10-02', 'created_at' => '2026-10-02 13:00:00'], $admin), 'i2 P2')['id'];
    $must($cb->voidEntry($p2, 'RH-MARK reason 3', 'RH-MARK Actor 3'), 'i2 void P2'); $shift($p2, '2026-10-03 09:00');
    $must($cb->updateEntry($p2, ['description' => 'RH-MARK copied in [VOIDED: RH-MARK — by RH-MARK 2026-09-22 11:00]'], $admin), 'i2 edit P2');
    $t = $pull(803, 5023, $A(), '2026-09-18', 'RH-Client-9', '2026-09-18T15:00:00+0300');
    $must($cb->voidEntry($t, 'RH-MARK reason 4', 'RH-MARK Actor 4'), 'i2 void T'); $shift($t, '2026-09-18 16:30');
    $c = (int)$must($cb->addEntry(['direction' => 'out', 'amount' => $A(), 'currency' => 'UGX', 'category' => 'Transport', 'description' => 'RH-MARK control',
        'date' => '2026-10-01', 'created_at' => '2026-10-01 10:00:00'], $admin), 'i2 C')['id'];
    $must($cb->voidEntry($c, 'RH-MARK reason 5', 'RH-MARK Actor 5'), 'i2 void C'); $shift($c, '2026-10-05 10:00');
    $ids['i2'] = compact('oA', 'rA', 'oB', 'rB', 'p1', 'p2', 't', 'c');
    // the truth, row by row: voided A B rB P2 T C; void minutes A 09-20, B 09-20, rB 10-12 (its own; the copied 09-20 is
    // older than rB's creation on 10-10), P2 10-03 (fin_audit: its only stamp is pasted, older than the row), T 09-18 16:30
    // (its own, after the 15:00 of its "T" creation), C 10-05 — so before 4 (A B P2 T), after 2 (rB C); A's and rB's
    // marks are a reversal's, P1's and P2's are pasted: no live row carries a mark of its own, no row two
    $truth[] = ['^  voided rows     6 of 8 rows: voided 6 · voided_reconcile 0$', 'i2: six voided rows of eight'];
    $truth[] = ['^  void time       from a stamp 5 · from fin_audit 1 · from the JSON record 0 .* · unknown 0 \(counted as before 04 Oct\); rows with two or more void marks 0$', 'i2: rB, T and C timed by their own stamps, P2 by fin_audit; no row carries two marks of its own'];
    $truth[] = ['^                  vs 04 Oct   before 4 · on 04 Oct 0 · after 2 · unknown 0$', 'i2: the reversal voided on 12 Oct reads after 4 Oct, not its copied 20 Sep'];
    $truth[] = ['^  evidence        fin_audit void, before_json readable: actor-minute 5 · reconcile 0 · tool 0 · plain 0 · no stamp 1$', 'i2: P2 has no stamp of its own; T\'s stamp is its own'];
    $truth[] = ['^                  void undone fin_audit void records whose row is now live 0: ids none; live rows carrying a \[VOIDED: mark 0: actor-minute 0 · reconcile 0 · tool 0 · plain 0$', 'i2: the live reversal and the pasted live row are not live marked rows'];
    $truth[] = ['^                  copied text reversal rows carrying a \[VOIDED: mark 2 \(live 1 · voided 1\), left out of both mark counts above; rows with a stamp minute before their own creation, not taken as theirs: reversal rows 1 · other rows 2$', 'i2: two reversals with copied marks; rB, P1 and P2 hold a stamp older than the row'];
    $truth[] = ['^  reversals       2 row\(s\) for 2 payment\(s\): every original voided 2 · some voided 0 · originals live 0 · original deleted \(fin_audit\) 0 · no original 0$', 'i2: both originals voided'];
    $truth[] = ['^                  payments reversed twice or more 0; originals marked reversed and voided 2$', 'i2: both originals marked and voided'];
    echo json_encode(['variant' => $variant, 'ids' => $ids, 'truth' => $truth, 'sql_planted' => $sqlPlanted], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
    exit(0);
}

// ── 1. accounts ────────────────────────────────────────────────────────────────────────────────────────────────────
$bankU = (int)$must($cb->addAccount('RH-MARK Ecobank UGX 7000000001', 'UGX', 'bank'), 'bank UGX')['id'];
$bankD = (int)$must($cb->addAccount('RH-MARK Ecobank USD', 'USD', 'bank'), 'bank USD')['id'];
$cash  = (int)$must($cb->addAccount('RH-MARK Cash – Uganda', 'UGX', 'cash'), 'cash')['id'];
$momo  = (int)$must($cb->addAccount('RH-MARK MoMo 0772 123 456', 'UGX', 'momo'), 'momo')['id'];
$dirA  = (int)$must($cb->addAccount('RH-MARK Director', 'UGX', 'director'), 'director')['id'];
$inv   = (int)$must($cb->addAccount('RH-MARK Stock', 'UGX', 'inventory'), 'inventory')['id'];
$pdo->exec("INSERT INTO cb_accounts (name, currency, kind) VALUES ('RH-MARK Odd', 'UGX', 'RH-MARK kind')");
$oddAcc = (int)$pdo->lastInsertId(); $sqlPlanted[] = 'an account of kind RH-MARK kind';
$ids += compact('bankU', 'bankD', 'cash', 'momo', 'dirA', 'inv', 'oddAcc');

// ── 2. single voids with FinAudit ──────────────────────────────────────────────────────────────────────────────────
$sv = [];
foreach ([['2026-08-14', '2026-09-10 10:15'], ['2026-09-03', '2026-10-04 09:05'], ['2026-09-08', '2026-10-09 16:40']] as $k => [$d, $min]) {
    $r = $must($cb->addEntry(['direction' => 'out', 'amount' => $A(), 'currency' => 'UGX', 'category' => 'Transport', 'description' => "RH-MARK single $k",
        'person' => 'RH-MARK Person 1', 'date' => $d], $admin), "single $k");
    $must($cb->voidEntry((int)$r['id'], 'RH-MARK reason 2', 'RH-MARK Actor 2'), "void single $k");
    $shift((int)$r['id'], $min); $sv[] = (int)$r['id'];
}
$ids['single'] = $sv;

// ── 3. two-leg entries: a voided transfer, a live and a voided funding pair, a live exchange, a bank-import pair ────
$t = $must($cb->recordAccountTransfer($cash, $bankU, $amt = $A(), $amt, '2026-09-09', 'RH-MARK banked', '', 'RH-MARK Actor 1'), 'transfer');
$tid = (int)$q1('SELECT id FROM cb_ledger WHERE validation_ref=? AND direction=?', [$t['ref'], 'out'])->fetchColumn();
$must($cb->voidEntry($tid, 'RH-MARK reason 3', 'RH-MARK Actor 3'), 'void transfer'); $shift($tid, '2026-09-25 12:00', true);
$must($cb->recordFunding($cash, $dirA, $A(), '2026-08-05', 'RH-MARK funding live', 'FUND-RH-1', 'RH-MARK Actor 1'), 'funding live');
$must($cb->recordFunding($cash, $dirA, $A(), '2026-08-06', 'RH-MARK funding voided', 'FUND-RH-2', 'RH-MARK Actor 1'), 'funding voided');
$fid = (int)$q1("SELECT MIN(id) FROM cb_ledger WHERE validation_ref='FUND-RH-2'")->fetchColumn();
$must($cb->voidEntry($fid, 'RH-MARK reason 4', 'RH-MARK Actor 4'), 'void funding'); $shift($fid, '2026-09-26 13:30', true);
$must($cb->recordCashExchange(1000011.11, 1.5, 'usd_to_base', '2026-09-10', 'RH-MARK exchange', 'dishnet', 'RH-MARK Person 2', 'RH-MARK Actor 1'), 'exchange');
$must($cb->recordAccountTransfer($bankU, $inv, $amt = $A(), $amt, '2026-09-10', 'RH-MARK card purchase', 'same', 'RH-MARK Actor 1', 'BANK-REF001', 'bank_import'), 'import transfer');
$truth[] = ['two-leg     entries 5 \(funding 2, other pair sources 2, opposite directions elsewhere 1\)', 'five two-leg entries (2 funding, a transfer and an exchange, a bank-import pair)'];
$truth[] = ['T2 reference     .*; pair legs excluded 5 group\(s\)$', 'the five leg-pairs are excluded from T2'];

// ── 4. a Staff Cashbooks expense void (tabs/accounts/staff_cashbooks.php:97, :121) ─────────────────────────────────
$SC = "$CHECKOUT/tabs/accounts/staff_cashbooks.php";
$e = $store->appendWithId('cash_expenses.json', ['retailer_id' => 7, 'retailer_name' => 'RH-MARK Actor 5', 'amount' => $A(), 'currency' => 'UGX',
    'description' => 'RH-MARK expense', 'status' => 'approved', 'created_at' => '2026-09-15 09:00:00']);
$eRow = $row(['direction' => 'out', 'amount' => (float)$e['amount'], 'currency' => 'UGX', 'category' => 'Transport', 'description' => 'RH-MARK field expense',
    'person' => 'RH-MARK Actor 5', 'date' => '2026-09-15', 'validation_ref' => 'EXP-' . $e['id'], 'status' => 'approved', 'source' => 'field_expense']);
$exp = $store->load('cash_expenses.json');
foreach ($exp as &$x) if ((int)$x['id'] === (int)$e['id']) { $x['prev_status'] = $x['status']; $x['status'] = 'voided'; $x['voided_by'] = 'RH-MARK Actor 5'; $x['voided_at'] = '2026-09-18 08:20:00'; $x['void_reason'] = 'RH-MARK reason 5'; }
unset($x); $store->save('cash_expenses.json', $exp);
$SQL121 = "UPDATE cb_ledger SET status = 'voided', description = description || ' [VOIDED: ' || ? || ']' WHERE validation_ref = ? AND status != 'voided'";
verbatim($SC, $SQL121);
$q1($SQL121, ['RH-MARK reason 5', 'EXP-' . $e['id']]);
$ids['expRow'] = $eRow;

// ── 5. a Staff Cashbooks collection void (staff_cashbooks.php:282-295) ──────────────────────────────────────────────
$SQL295 = "UPDATE cb_ledger SET status='voided', description=description||' [VOIDED: '||?||']' WHERE validation_ref=? AND status!='voided'";
verbatim($SC, $SQL295);
$voidCol = static function (int $colId, string $at, string $reason, string $by) use ($store, $q1, $SQL295): void {
    $cols = $store->load('payment_collections.json'); $ref = null;
    foreach ($cols as &$c) {
        if ((int)($c['id'] ?? 0) !== $colId) continue;
        $c['prev_status'] = $c['status'] ?? 'approved'; $c['status'] = 'voided'; $c['voided_by'] = $by; $c['voided_at'] = $at; $c['void_reason'] = $reason;
        $c['audit_log'] = $c['audit_log'] ?? []; $c['audit_log'][] = ['action' => 'void', 'by' => $by, 'at' => $at, 'reason' => $reason];
        $ref = ($c['crm_payment_id'] ?? null) ? 'PAY-' . $c['crm_payment_id'] : 'COL-' . $colId;
    }
    unset($c); $store->save('payment_collections.json', $cols);
    if ($ref !== null) $q1($SQL295, [$reason, $ref]);
};
$col = static function (array $f) use ($store): array { return $store->appendWithId('payment_collections.json', $f + ['retailer_id' => 9, 'retailer_name' => 'RH-MARK Actor 6',
    'customer_name' => 'RH-Client-1 RH-MARK', 'note' => 'RH-MARK note', 'commission' => 0, 'comm_rate' => 0]); };
$c5 = $col(['crm_customer_id' => '5021', 'amount' => $amt = $A(), 'currency' => 'UGX', 'method' => 'Cash', 'created_at' => '2026-09-16 10:00:00']);
$r5 = $row(['sr' => 'COL-' . $c5['id'], 'direction' => 'in', 'amount' => $amt, 'currency' => 'UGX', 'category' => 'Receipt', 'description' => 'RH-MARK collected, RH-Client-1',
    'person' => 'RH-MARK Actor 6', 'date' => '2026-09-16', 'validation_ref' => 'COL-' . $c5['id'], 'crm_client_id' => 5021, 'source' => 'collect_payment']);
$voidCol((int)$c5['id'], '2026-09-17 15:45:00', 'RH-MARK reason 6', 'RH-MARK Actor 6');
$ids['scCol'] = [(int)$c5['id'], $r5];

// ── 6. Manual Entry collections stamped USD: one with its COL- row, one with none (the records tool voids both) ────
$m1 = $col(['crm_customer_id' => '', 'amount' => $amt = $A(), 'currency' => 'USD', 'method' => 'Cash', 'source' => 'manual_adjustment', 'created_at' => '2026-09-20 09:00:00']);
$mr = $row(['sr' => 'COL-' . $m1['id'], 'direction' => 'in', 'amount' => $amt, 'currency' => 'USD', 'category' => 'Receipt', 'description' => 'RH-MARK manual entry',
    'person' => 'RH-MARK Actor 7', 'date' => '2026-09-20', 'validation_ref' => 'COL-' . $m1['id'], 'source' => 'manual_adjustment']);
$m2 = $col(['crm_customer_id' => '', 'amount' => $A(), 'currency' => 'USD', 'method' => 'Cash', 'source' => 'manual_adjustment', 'created_at' => '2026-09-21 09:00:00']);
$ids['manual'] = [(int)$m1['id'], $mr, (int)$m2['id']];

// ── 7. handover rows: one reconcile-voided; one voided by voidEntry (before 4 Oct) and later reconcile-voided ───────
$CD = "$CHECKOUT/includes/api/api_cron_debug.php";
$RECON_PHP = "\"UPDATE cb_ledger SET status = 'voided_reconcile',\n                    description = description || ' [VOIDED: handover double-count reconcile \" . date('Y-m-d H:i') . \"]'\n                 WHERE id IN ({\$placeholders})\"";
verbatim($CD, $RECON_PHP);
$reconcile = static function (array $rowIds, string $minute) use ($pdo, $bornBy): void {
    $placeholders = implode(',', array_fill(0, count($rowIds), '?'));
    $pdo->prepare("UPDATE cb_ledger SET status = 'voided_reconcile',
                    description = description || ' [VOIDED: handover double-count reconcile " . $minute . "]'
                 WHERE id IN ({$placeholders})")->execute($rowIds);
    $bornBy($rowIds, $minute);
};
$h1 = $row(['direction' => 'in', 'amount' => $A(), 'currency' => 'UGX', 'category' => 'Receipt', 'category_raw' => 'Cash Handover', 'description' => 'RH-MARK handover',
    'person' => 'RH-MARK Actor 8', 'date' => '2026-09-22', 'validation_ref' => 'HOV-1', 'source' => 'handover']);
$h2 = $row(['direction' => 'in', 'amount' => $A(), 'currency' => 'UGX', 'category' => 'Receipt', 'category_raw' => 'Cash Handover', 'description' => 'RH-MARK handover 2',
    'person' => 'RH-MARK Actor 8', 'date' => '2026-09-23', 'validation_ref' => 'HOV-2', 'source' => 'handover']);
$must($cb->voidEntry($h2, 'RH-MARK reason 7', 'RH-MARK Actor 8'), 'void handover 2'); $shift($h2, '2026-09-24 07:10');
$reconcile([$h1, $h2], '2026-10-06 09:30');
$ids['handover'] = [$h1, $h2];
$truth[] = ['rows with two or more void marks [1-9]', 'a row voided twice (voidEntry, then reconcile) carries two marks'];

// ── 8. stamps erased, a void edited afterwards, a void timed by fin_audit only, a same-minute update ───────────────
$c8 = $col(['crm_customer_id' => '5022', 'amount' => $amt = $A(), 'currency' => 'UGX', 'method' => 'Cash', 'created_at' => '2026-09-26 10:00:00']);
$r8 = $row(['sr' => 'COL-' . $c8['id'], 'direction' => 'in', 'amount' => $amt, 'currency' => 'UGX', 'category' => 'Receipt', 'description' => 'RH-MARK to be rewritten',
    'person' => 'RH-MARK Actor 6', 'date' => '2026-09-26', 'validation_ref' => 'COL-' . $c8['id'], 'crm_client_id' => 5022, 'source' => 'collect_payment']);
$voidCol((int)$c8['id'], '2026-09-28 11:00:00', 'RH-MARK reason 8', 'RH-MARK Actor 6');
$must($cb->updateEntry($r8, ['description' => 'RH-MARK rewritten, no stamp'], $admin), 'rewrite 8a');
$r8b = $row(['direction' => 'out', 'amount' => $A(), 'currency' => 'UGX', 'category' => 'Transport', 'description' => 'RH-MARK no record', 'date' => '2026-09-27',
    'validation_ref' => 'EXP-99999', 'status' => 'approved', 'source' => 'field_expense']);
$q1($SQL295, ['RH-MARK reason 9', 'EXP-99999']);
$must($cb->updateEntry($r8b, ['description' => 'RH-MARK rewritten too'], $admin), 'rewrite 8b');
$r8c = (int)$must($cb->addEntry(['direction' => 'out', 'amount' => $A(), 'currency' => 'UGX', 'category' => 'Transport', 'description' => 'RH-MARK edited after', 'date' => '2026-09-11'], $admin), '8c')['id'];
$must($cb->voidEntry($r8c, 'RH-MARK reason 10', 'RH-MARK Actor 9'), 'void 8c'); $shift($r8c, '2026-09-29 10:00');
$d8c = (string)$q1('SELECT description FROM cb_ledger WHERE id=?', [$r8c])->fetchColumn();
$must($cb->updateEntry($r8c, ['description' => $d8c . ' RH-MARK note'], $admin), 'edit 8c');
$r8d = (int)$must($cb->addEntry(['direction' => 'out', 'amount' => $A(), 'currency' => 'UGX', 'category' => 'Transport', 'description' => 'RH-MARK fin_audit only', 'date' => '2026-09-12'], $admin), '8d')['id'];
$must($cb->voidEntry($r8d, 'RH-MARK reason 11', 'RH-MARK Actor 9'), 'void 8d');
$must($cb->updateEntry($r8d, ['description' => 'RH-MARK rewritten, the stamp gone'], $admin), 'rewrite 8d');
$q1("UPDATE fin_audit SET created_at='2026-10-08 14:20:00' WHERE record_type='cb_ledger' AND record_id=? AND action='void'", [$r8d]);
$sqlPlanted[] = "fin_audit void time of row $r8d set to 2026-10-08 14:20";
// an update in the void's own minute, to a pair-voided row (the transfer of plant 3)
$must($cb->updateEntry($tid, ['person' => 'RH-MARK Person 9'], $admin), 'same-minute update');
$q1("UPDATE fin_audit SET created_at='2026-09-25 12:00:30' WHERE id=(SELECT MAX(id) FROM fin_audit WHERE record_id=? AND action='update')", [$tid]);
$sqlPlanted[] = "the update of row $tid set to 2026-09-25 12:00:30, the void's own minute";
$ids['p8'] = [$r8, $r8b, $r8c, $r8d];

// ── 9. deletes; a void undone ──────────────────────────────────────────────────────────────────────────────────────
$d9a = (int)$must($cb->addEntry(['direction' => 'out', 'amount' => $A(), 'currency' => 'UGX', 'category' => 'Purchase', 'description' => 'RH-MARK deleted voided', 'date' => '2026-09-04'], $admin), '9a')['id'];
$must($cb->voidEntry($d9a, 'RH-MARK reason 12', 'RH-MARK Actor 9'), 'void 9a'); $shift($d9a, '2026-09-05 09:00');
$must($cb->deleteEntry($d9a, $admin, 'RH-MARK reason 13'), 'delete 9a');
$d9b = (int)$must($cb->addEntry(['direction' => 'in', 'amount' => $A(), 'currency' => 'UGX', 'category' => 'Receipt', 'description' => 'RH-MARK deleted live', 'date' => '2026-09-05'], $admin), '9b')['id'];
$must($cb->deleteEntry($d9b, $admin, 'RH-MARK reason 14'), 'delete 9b');
$u9 = (int)$must($cb->addEntry(['direction' => 'out', 'amount' => $A(), 'currency' => 'UGX', 'category' => 'Purchase', 'description' => 'RH-MARK undone', 'date' => '2026-09-06'], $admin), '9c')['id'];
$must($cb->voidEntry($u9, 'RH-MARK reason 15', 'RH-MARK Actor 9'), 'void 9c'); $shift($u9, '2026-09-07 09:00');
$must($cb->approveEntry($u9, $admin), 'approve 9c');
$ids['p9'] = [$d9a, $d9b, $u9];
$truth[] = ['void undone fin_audit void records whose row is now live 1: ids ' . $u9 . ';', 'the voided-then-approved row is the one void undone'];

// ── 10. twins ──────────────────────────────────────────────────────────────────────────────────────────────────────
$wh = static function (int $p, int $cli, float $amt, string $d, string $src = 'crm_webhook', string $ref = '') use ($row): int {
    return $row(['sr' => 'CRM-' . $p, 'direction' => 'in', 'amount' => $amt, 'currency' => 'UGX', 'category' => 'Receipt', 'category_raw' => 'Receipt',
        'description' => "RH-MARK payment from RH-Client-$cli", 'date' => $d, 'validation_ref' => $ref !== '' ? $ref : "PAY-$p", 'crm_payment_id' => $p,
        'crm_client_id' => $cli, 'source' => $src, 'approved_by' => 'CRM', 'created_at' => "$d 08:00:00"]);
};
$pwa = static function (int $c, int $cli, float $amt, string $d, int $p = 0) use ($row): int {   // includes/api/api_retailer.php:184-202
    return $row(['sr' => 'COL-' . $c, 'direction' => 'in', 'amount' => $amt, 'currency' => 'UGX', 'category' => 'Receipt', 'category_raw' => 'Receipt',
        'person' => 'RH-MARK Actor 6', 'description' => "RH-MARK cash from RH-Client-$cli", 'date' => $d, 'validation_ref' => $p ? "PAY-$p" : 'COL-' . $c,
        'crm_payment_id' => $p, 'crm_client_id' => $cli, 'source' => 'collect_payment', 'created_at' => "$d 09:00:00"]);
};
$retry = static function (array $f) use ($store): array { return $store->appendWithId('crm_payment_retry.json', $f + ['customer_name' => 'RH-Client-9 RH-MARK',
    'payload' => ['clientId' => 5099, 'note' => 'RH-MARK'], 'error' => 'RH-MARK error', 'attempts' => 1, 'created_at' => '2026-09-01 10:00:00']); };
$setCol = static function (int $id, array $u) use ($store): void { $store->updateOne('payment_collections.json', 'id', $id, $u); };
// T1: a webhook and a gap fill of payment 901, one voided
$a = $A(); $t1a = $wh(901, 5001, $a, '2026-09-02'); $t1b = $wh(901, 5001, $a, '2026-09-03', 'crm_gap_fill');
$must($cb->voidEntry($t1b, 'RH-MARK reason 16', 'RH-MARK Actor 1'), 'void t1b');
// T2: PAY-902 on a PWA row and a webhook row; CRM-PAY-903 against PAY-903
$a = $A(); $c902 = $col(['crm_customer_id' => '5002', 'amount' => $a, 'currency' => 'UGX', 'method' => 'Cash', 'crm_payment_id' => 902, 'created_at' => '2026-09-04 09:00:00']);
$pwa((int)$c902['id'], 5002, $a, '2026-09-04', 902); $wh(902, 5002, $a, '2026-09-04');
$a = $A(); $wh(903, 5003, $a, '2026-09-05', 'crm_webhook', 'CRM-PAY-903'); $wh(903, 5003, $a, '2026-09-05', 'crm_api_sync');
// T3 through the collection (collection A): auto-heal set crm_payment_id 904 (cron_sync.php:380-385); COL-A push failure + webhook PAY-904
$a = $A(); $cA = $col(['crm_customer_id' => '5004', 'amount' => $a, 'currency' => 'UGX', 'method' => 'Cash', 'created_at' => '2026-09-06 09:00:00']);
$fA = $pwa((int)$cA['id'], 5004, $a, '2026-09-06'); $setCol((int)$cA['id'], ['crm_synced' => true, 'crm_payment_id' => 904, 'crm_auto_matched' => true]);
$wA = $wh(904, 5004, $a, '2026-09-07');
// T3 through a retry item (collection B): item {collection_id: B, crm_id: 905}
$a = $A(); $cB = $col(['crm_customer_id' => '5005', 'amount' => $a, 'currency' => 'UGX', 'method' => 'Cash', 'created_at' => '2026-09-08 09:00:00']);
$fB = $pwa((int)$cB['id'], 5005, $a, '2026-09-08'); $retry(['collection_id' => (int)$cB['id'], 'crm_id' => 905, 'status' => 'synced']); $wh(905, 5005, $a, '2026-09-08');
// (a) unlinked: a push failure whose retry item has no collection id (api_retailer.php:165-171), and the payment posted
$a = $A(); $cU = $col(['crm_customer_id' => '5007', 'amount' => $a, 'currency' => 'UGX', 'method' => 'Cash', 'created_at' => '2026-09-09 09:00:00']);
$fU = $pwa((int)$cU['id'], 5007, $a, '2026-09-09'); $retry(['crm_client_id' => 5007, 'status' => 'pending', 'payment_ref' => 'RH-MID-0001']); $wh(931, 5007, $a, '2026-09-11');
// (b) collection 12-like: the failure row and the catch-up row (sr COL-c, ref PAY-906), collection crm_payment_id 906
$a = $A(); $cC = $col(['crm_customer_id' => '5008', 'amount' => $a, 'currency' => 'UGX', 'method' => 'Cash', 'created_at' => '2026-09-10 09:00:00']);
$fC = $pwa((int)$cC['id'], 5008, $a, '2026-09-10'); $setCol((int)$cC['id'], ['crm_synced' => true, 'crm_payment_id' => 906, 'synced_by' => 'catchup_sync']);
$row(['sr' => 'COL-' . $cC['id'], 'direction' => 'in', 'amount' => $a, 'currency' => 'UGX', 'category' => 'Receipt', 'category_raw' => 'Receipt', 'person' => 'RH-MARK Actor 6',
    'description' => 'RH-MARK caught up, RH-Client-8', 'date' => '2026-09-10', 'validation_ref' => 'PAY-906', 'crm_payment_id' => 906, 'crm_client_id' => 5008, 'source' => 'catchup_sync']);
// (c) collection 13-like: a PWA success (sr COL-c, ref PAY-907) and the pull fallback (crm_sync, ref COL-c, pay 907)
$a = $A(); $cD = $col(['crm_customer_id' => '5009', 'amount' => $a, 'currency' => 'UGX', 'method' => 'Cash', 'crm_payment_id' => 907, 'created_at' => '2026-09-12 09:00:00']);
$pwa((int)$cD['id'], 5009, $a, '2026-09-12', 907);
$row(['sr' => 'COL-' . $cD['id'], 'direction' => 'in', 'amount' => $a, 'currency' => 'UGX', 'category' => 'Receipt', 'category_raw' => 'Receipt', 'person' => 'RH-MARK Actor 6',
    'description' => 'RH-MARK local sync', 'date' => '2026-09-12', 'validation_ref' => 'COL-' . $cD['id'], 'crm_payment_id' => 907, 'crm_client_id' => 5009, 'source' => 'crm_sync']);
// (d) payment 908: a gap fill and the API pull
$a = $A(); $wh(908, 5010, $a, '2026-09-13', 'crm_gap_fill'); $wh(908, 5010, $a, '2026-09-13', 'crm_api_sync');
// (e) a KYC sale (lib/KycService.php:1877-1894) and the webhook PAY-909 the next day; the KYC collection record
$KY = "$CHECKOUT/lib/KycService.php";
$KYC_INSERT = "INSERT INTO cb_ledger (project, date, direction, amount, currency, category, category_raw,
                    person, description, validation_ref, validation_status, status, approved_by,
                    crm_client_id, source, created_at)
                 VALUES (?, ?, 'in', ?, ?, 'Receipt', 'KYC Cash Sale',
                    ?, ?, ?, 'pending', 'approved', 'Auto-KYC',
                    ?, 'kyc_cash_sale', ?)";
verbatim($KY, $KYC_INSERT);
$kyc = static function (string $d, float $amt, string $crm) use ($q1, $KYC_INSERT, $pdo): int {
    $q1($KYC_INSERT, ['dishnet', $d, $amt, 'UGX', 'RH-MARK Actor 10', 'Cash collected for Starlink registration: RH-Client-55 RH-MARK', "CRM#{$crm}", $crm, "$d 10:00:00"]);
    return (int)$pdo->lastInsertId();
};
$a = $A(); $k55 = $kyc('2026-09-14', $a, '5055'); $w909 = $wh(909, 5055, $a, '2026-09-15');
$col(['crm_customer_id' => '5055', 'amount' => $a, 'method' => 'Cash', 'source' => 'kyc_cash_sale', 'kyc_app_id' => 77, 'crm_payment_id' => 909, 'crm_synced' => true, 'created_at' => '2026-09-14 10:00:00']);
// a client-less KYC sale whose application id equals client 5060, beside client 5060's own payment of the same amount and day
$a = $A(); $k60 = $kyc('2026-09-16', $a, '5060'); $wh(960, 5060, $a, '2026-09-16');
$col(['crm_customer_id' => '', 'amount' => $a, 'method' => 'Cash', 'source' => 'kyc_cash_sale', 'kyc_app_id' => 5060, 'crm_payment_id' => null, 'crm_synced' => false, 'created_at' => '2026-09-16 10:00:00']);
$truth[] = ['KYC rows    whose CRM# is an application id 1 \(client id set to 0\); ambiguous, kept as a client 0$', 'one KYC row carries an application id'];
$truth[] = ['; linked through the KYC collection record 1$', 'T7: one pair linked through the KYC collection record'];
$truth[] = ['^                  \(e\) KYC row beside a uCRM receipt: same day 0 · ±7 d 1 ', '(e): the KYC row and the next-day webhook, never the application-id row'];
// (f) collection with crm_payment_id 910 and a retry item {collection_id, crm_payment_id: 911}; ledger PAY-910 and PAY-911
$a = $A(); $cF = $col(['crm_customer_id' => '5012', 'amount' => $a, 'currency' => 'UGX', 'method' => 'Cash', 'crm_payment_id' => 910, 'created_at' => '2026-09-17 09:00:00']);
$retry(['collection_id' => (int)$cF['id'], 'crm_payment_id' => 911, 'status' => 'synced']);
$wh(910, 5012, $a, '2026-09-17'); $wh(911, 5012, $a, '2026-09-18');
$truth[] = ['^                  \(f\) two uCRM payments for one collection: 1 collection\(s\); ledger candidates ±7 d 1 pair\(s\)$', '(f): one collection, one ledger pair'];
$truth[] = ['^                  \(b\) catch-up beside the COL- row: 1 collection\(s\);', '(b): the catch-up beside its COL- row'];
$truth[] = ['^  I1 families     \(a\) push failure, then the payment posted: 2 collection\(s\) \(through the collection 1, through a retry item 1\);', '(a): collections A and B; the catch-up collection is (b) only'];
$truth[] = ['^                  \(a\) unlinked: push-failure rows with a PAY- partner by client\+amount ±7 d 1 ', '(a) unlinked: the PWA retry item with no collection id'];
$truth[] = ['^                  \(c\) pull fallback beside another row of the collection: 1 row\(s\)', '(c): the pull fallback'];
$truth[] = ['^                  \(d\) pull beside a gap-filled or catch-up receipt: 1 payment\(s\);', '(d): payment 908'];
// T4: client pairs 0, 3, exactly 7 and 8 days apart
foreach ([[5013, 0], [5014, 3], [5015, 7], [5016, 8]] as [$cl, $gap]) {
    $a = $A();
    foreach (['2026-09-01', date('Y-m-d', strtotime("2026-09-01 +$gap days"))] as $k => $d) {
        $row(['direction' => 'in', 'amount' => $a, 'currency' => 'UGX', 'category' => 'Receipt', 'description' => "RH-MARK client pair $k", 'date' => $d,
            'crm_client_id' => $cl, 'source' => 'manual', 'status' => 'approved']);
    }
}
$ids['t4clients'] = ['7' => 5015, '8' => 5016];
// T5: two hand rows, no client, one voided, the texts equal once the stamp and punctuation go; and two with other texts
$a = $A();
$t5a = (int)$must($cb->addEntry(['direction' => 'in', 'amount' => $a, 'currency' => 'UGX', 'category' => 'Receipt', 'description' => 'RH-MARK cash from shop, Kampala!', 'date' => '2026-09-19'], $admin), 't5a')['id'];
$t5b = (int)$must($cb->addEntry(['direction' => 'in', 'amount' => $a, 'currency' => 'UGX', 'category' => 'Receipt', 'description' => 'rh-mark cash   from shop kampala', 'date' => '2026-09-19'], $admin), 't5b')['id'];
$must($cb->voidEntry($t5b, 'RH-MARK reason 17', 'RH-MARK Actor 1'), 'void t5b');
$a = $A();
$must($cb->addEntry(['direction' => 'out', 'amount' => $a, 'currency' => 'UGX', 'category' => 'Transport', 'description' => 'RH-MARK boda one', 'date' => '2026-09-19'], $admin), 't5c');
$must($cb->addEntry(['direction' => 'out', 'amount' => $a, 'currency' => 'UGX', 'category' => 'Transport', 'description' => 'RH-MARK lorry two', 'date' => '2026-09-21'], $admin), 't5d');
// T6: a row equal to the sum of two others of one person; one equal to the sum of three; a same-amount twin beside a zero row
$x = $A(); $y = $A();
foreach ([$x + $y, $x, $y] as $k => $v) $row(['direction' => 'out', 'amount' => round($v, 2), 'currency' => 'UGX', 'category' => 'Transport', 'description' => "RH-MARK part $k",
    'person' => 'RH-MARK Person 6', 'date' => '2026-09-22', 'status' => 'approved']);
$x = $A(); $y = $A(); $z = $A();
foreach ([$x + $y + $z, $x, $y, $z] as $k => $v) $row(['direction' => 'out', 'amount' => round($v, 2), 'currency' => 'UGX', 'category' => 'Transport', 'description' => "RH-MARK piece $k",
    'person' => 'RH-MARK Person 7', 'date' => '2026-09-2' . ($k + 3), 'status' => 'approved']);
$a = $A();
foreach ([$a, $a, 0.0] as $k => $v) $row(['direction' => 'out', 'amount' => $v, 'currency' => 'UGX', 'category' => 'Transport', 'description' => "RH-MARK same $k",
    'person' => 'RH-MARK Person 8', 'date' => '2026-09-23', 'status' => 'approved']);
$truth[] = ['^                  T6 sum           same day: rows matched 1 · voided 0 · not examined 0 \| ±7 d: rows matched 2 · voided 0 · not examined 0$', 'T6: one sum of two (same day), and one sum of three within the week'];

// ── 11. Receipt rows by their collection's method ────────────────────────────────────────────────────────────────
$methods = ['Cash', 'Mobile Money', 'Bank Transfer', '', '6efe0fa8-36b2-4dd1-b049-427bffc7d369', 'RH-MARK pay', '3', 2, 'cheque'];
$mrows = [];
foreach ($methods as $k => $m) {
    $a = $A(); $f = ['crm_customer_id' => (string)(5030 + $k), 'amount' => $a, 'currency' => 'UGX', 'created_at' => '2026-09-24 09:00:00'];
    if ($m !== '') $f['method'] = $m;
    if ($k === 0) $f['status'] = 1;   // a status that is a JSON number
    $cm = $col($f);
    $mrows[] = $row(['sr' => 'COL-' . $cm['id'], 'direction' => 'in', 'amount' => $a, 'currency' => 'UGX', 'category' => 'Receipt', 'description' => "RH-MARK receipt $k",
        'person' => 'RH-MARK Actor 6', 'date' => '2026-09-24', 'crm_client_id' => 5030 + $k, 'source' => 'catchup_sync', 'status' => 'approved']);
}
$must($cb->voidEntry($mrows[1], 'RH-MARK reason 18', 'RH-MARK Actor 1'), 'void receipt');
$a = $A(); $direct = ['id' => 1790000000 + 951, 'retailer_id' => 0, 'retailer_name' => 'Direct UCRM Entry', 'customer_name' => 'RH-Client-51 RH-MARK',
    'crm_customer_id' => 5051, 'amount' => $a, 'currency' => 'UGX', 'method' => 'Cash', 'crm_synced' => true, 'crm_payment_id' => 951, 'source' => 'crm_direct', 'created_at' => '2026-09-24 08:00:00'];
$cols = $store->load('payment_collections.json'); $cols[] = $direct; $store->save('payment_collections.json', $cols);
$wh(951, 5051, $a, '2026-09-24', 'crm_api_sync');
$truth[] = ['linked only to a pull-made record \(crm_direct\) 1, not classed$', 'the crm_api_sync row linked only to a pull-made record'];
$truth[] = ['^                  by class    cash [0-9]+ \(voided [0-9]+\) · uuid cash 1 \(voided 0\) · mobile money 1 \(voided 1\) · bank 2 \(voided 0\) · cheque 1 \(voided 0\) · uuid other 0 \(voided 0\) · blank 1 \(voided 0\) · \(other\) 1 \(voided 0\)$', 'method classes: Bank Transfer and "3" are bank; the Cash UUID its own class; a free text (other)'];

// ── 12. reversals ────────────────────────────────────────────────────────────────────────────────────────────────
// written by $rev, the webhook's own branch (above); 920 in the I2 order (docs/69:394): voided first, then its payment
// deleted — so its live reversal copies the void stamp, cut short by the 80 bytes (a mark that is not the reversal's own)
$a = $A(); $o920 = $wh(920, 5040, $a, '2026-09-25');
$must($cb->voidEntry($o920, 'RH-MARK reason 19', 'RH-MARK Actor 1'), 'void 920'); $shift($o920, '2026-09-26 10:00');
$r920 = $rev(920, '2026-09-27 09:00:00');
$d920 = $descOf($r920);
if (strpos($d920, '[VOIDED:') === false || preg_match('/ \d{4}-\d{2}-\d{2} \d{2}:\d{2}\]/', $d920)) die3('920: the reversal does not hold a stamp cut short');
$a = $A(); $wh(921, 5041, $a, '2026-09-25'); $rev(921, '2026-09-26 09:00:00');
$a = $A(); $o922 = $wh(922, 5042, $a, '2026-09-25'); $rev(922, '2026-09-26 09:00:00'); $must($cb->deleteEntry($o922, $admin, 'RH-MARK reason 20'), 'delete 922');
// 923: a reversal whose original is gone with no fin_audit record (the webhook writes a reversal only beside an original,
// webhook.php:3565): written by addEntryRaw, the same writer, with a fixed text
$a = $A(); $row(['direction' => 'out', 'amount' => $a, 'currency' => 'UGX', 'category' => 'Refund', 'txn_type' => 'REFUND', 'category_raw' => 'CRM Payment Deleted',
    'description' => 'AUTO-REVERSAL: CRM Payment #923 deleted — RH-MARK', 'date' => '2026-09-26', 'validation_ref' => 'REV-CRM-PAY-923', 'validation_status' => 'done',
    'status' => 'approved', 'approved_by' => 'System', 'crm_payment_id' => 923, 'crm_client_id' => 0, 'source' => 'crm_webhook_reversal', 'created_at' => '2026-09-26 09:00:00']);
$a = $A(); $wh(924, 5044, $a, '2026-09-25'); $rev(924, '2026-09-26 09:00:00'); $rev(924, '2026-09-27 09:00:00');
$ids['rev'] = ['r920' => $r920];

// ── 13. rows dated 2026-09-12 ────────────────────────────────────────────────────────────────────────────────────
$a12 = (int)$must($cb->addEntry(['direction' => 'out', 'amount' => $A(), 'currency' => 'UGX', 'category' => 'Misc Expense', 'description' => 'RH-MARK ADJUSTMENT voided', 'date' => '2026-09-12'], $admin), '12a')['id'];
$must($cb->voidEntry($a12, 'RH-MARK reason 21', 'RH-MARK Actor 1'), 'void 12a'); $shift($a12, '2026-09-30 17:00');
$b12 = $row(['direction' => 'out', 'amount' => $A(), 'currency' => 'UGX', 'category' => 'Adjustment', 'description' => 'RH-MARK on the bank', 'date' => '2026-09-12', 'status' => 'approved', 'account_id' => $bankU]);
$c12 = $row(['direction' => 'out', 'amount' => $A(), 'currency' => 'UGX', 'category' => 'Misc Expense', 'description' => 'RH-MARK ADJUSTMENT ENTRY', 'date' => '2026-09-12', 'status' => 'approved']);
$must($cb->updateEntry($c12, ['description' => 'RH-MARK corrected wording'], $admin), 'reword 12c');
$d12 = (int)$must($cb->addEntry(['direction' => 'in', 'amount' => $A(), 'currency' => 'UGX', 'category' => 'Receipt', 'description' => 'RH-MARK deleted on the 12th', 'date' => '2026-09-12'], $admin), '12d')['id'];
$must($cb->deleteEntry($d12, $admin, 'RH-MARK reason 22'), 'delete 12d');
$e12 = (int)$must($cb->addEntry(['direction' => 'in', 'amount' => $A(), 'currency' => 'UGX', 'category' => 'Receipt', 'description' => 'RH-MARK moved away', 'date' => '2026-09-12'], $admin), '12e')['id'];
$must($cb->updateEntry($e12, ['date' => '2026-09-14'], $admin), 'move away');
$f12 = (int)$must($cb->addEntry(['direction' => 'in', 'amount' => $A(), 'currency' => 'UGX', 'category' => 'Receipt', 'description' => 'RH-MARK moved in', 'date' => '2026-09-15'], $admin), '12f')['id'];
$must($cb->updateEntry($f12, ['date' => '2026-09-12'], $admin), 'move in');
$g12 = $row(['direction' => 'in', 'amount' => $A(), 'currency' => 'UGX', 'category' => 'Receipt', 'description' => 'RH-MARK odd status', 'date' => '2026-09-12', 'status' => 'approved']);
$q1("UPDATE cb_ledger SET status='RH-MARK status' WHERE id=?", [$g12]); $sqlPlanted[] = "status RH-MARK status on row $g12";
$h12 = $row(['direction' => 'in', 'amount' => $A(), 'currency' => 'UGX', 'category' => 'Receipt', 'description' => 'RH-MARK odd account', 'date' => '2026-09-12', 'status' => 'approved', 'account_id' => $oddAcc]);
$ids['d12'] = compact('a12', 'b12', 'c12', 'd12', 'e12', 'f12', 'g12', 'h12');
$truth[] = ['^                  date edited: id ' . $e12 . ' away from 2026-09-12 \(fin_audit update [0-9]+\)$', 'the date moved away from the 12th'];
$truth[] = ['^                  date edited: id ' . $f12 . ' into 2026-09-12 \(fin_audit update [0-9]+\)$', 'the date moved into the 12th'];
$truth[] = ['^                  id ' . $c12 . '  approved  unassigned  p0 rule: live yes · unassigned yes · named no  fin_audit: update [0-9]+ · void – · delete –$', 'the reworded row is no longer "named" — visible without its wording'];
$truth[] = ['^                  id ' . $g12 . '  \(other\)  ', 'a free-text status reads (other)'];
$truth[] = ['^                  id ' . $h12 . '  approved  \(other\)  ', 'a free-text account kind reads (other)'];

// ── 14. adjustment candidates (the fictitious figure 7340000.37) ─────────────────────────────────────────────────
$ADJ = 7340000.37;
$cand = [];
$cand[] = $row(['direction' => 'out', 'amount' => $ADJ, 'currency' => 'UGX', 'category' => 'Misc Expense', 'description' => 'RH-MARK candidate live, 0772 123 456', 'date' => '2026-09-13', 'status' => 'approved', 'created_at' => '2026-09-13 18:05:00']);
$cv = (int)$must($cb->addEntry(['direction' => 'out', 'amount' => $ADJ, 'currency' => 'UGX', 'category' => 'Misc Expense', 'description' => 'RH-MARK candidate voided BANK-REF001', 'date' => '2026-09-12', 'created_at' => '2026-09-12 20:00:00'], $admin), 'cand voided')['id'];
$must($cb->voidEntry($cv, 'RH-MARK reason 23', 'RH-MARK Actor 1'), 'void cand'); $shift($cv, '2026-10-01 08:00'); $cand[] = $cv;
$cand[] = $row(['direction' => 'out', 'amount' => $ADJ, 'currency' => 'UGX', 'category' => 'Bank Charges', 'description' => 'RH-MARK candidate on the bank', 'date' => '2026-09-11', 'status' => 'approved', 'account_id' => $bankU, 'created_at' => '2026-09-11 07:00:00']);
$cand[] = $row(['direction' => 'out', 'amount' => $ADJ, 'currency' => '', 'category' => 'Misc Expense', 'description' => 'RH-MARK candidate, no creation time', 'date' => '2026-09-12', 'status' => 'approved', 'created_at' => '']);
$cdel = $row(['direction' => 'out', 'amount' => $ADJ, 'currency' => 'UGX', 'category' => 'Misc Expense', 'description' => 'RH-MARK candidate deleted', 'date' => '2026-09-12', 'status' => 'approved', 'created_at' => '2026-09-12 21:00:00']);
$must($cb->deleteEntry($cdel, $admin, 'RH-MARK reason 24'), 'delete cand');
$cedit = $row(['direction' => 'out', 'amount' => $ADJ, 'currency' => 'UGX', 'category' => 'Misc Expense', 'description' => 'RH-MARK candidate edited', 'date' => '2026-09-12', 'status' => 'approved', 'created_at' => '2026-09-12 22:00:00']);
$must($cb->updateEntry($cedit, ['amount' => $A()], $admin), 'edit cand');
$cusd = $row(['direction' => 'out', 'amount' => $ADJ, 'currency' => 'USD', 'category' => 'Misc Expense', 'description' => 'RH-MARK same amount USD', 'date' => '2026-09-12', 'status' => 'approved']);
$cin  = $row(['direction' => 'in', 'amount' => $ADJ, 'currency' => 'UGX', 'category' => 'Receipt', 'description' => 'RH-MARK same amount in', 'date' => '2026-09-12', 'status' => 'approved']);
$ids['cand'] = ['rows' => $cand, 'deleted' => $cdel, 'edited' => $cedit, 'usd' => $cusd, 'in' => $cin];
// the reviewed tool's hash matches no planted amount: no candidate on any book
$truth[] = ['^  adj\. candidates .*any account: 0$', 'the reviewed tool lists no candidate (no planted amount is the recorded figure)', 'tool'];
$truth[] = ['^                  not candidates, same amount and direction, another currency 0$', 'the reviewed tool: no other-currency row', 'tool'];
$truth[] = ['^  adj\. candidates .*any account: ' . ($variant === 'kyc-first' ? 5 : 4) . '$', 'the candidate copy lists the planted candidates (kyc-first: also its NULL-currency one)', 'cand'];
$truth[] = ['^  deleted         6 cb_ledger row\(s\) deleted: voided when deleted 1 · live 5 · before_json unreadable 0$', 'five rows deleted by deleteEntry (one of them voided) and a snapshot whose status is a JSON number', 'ug'];
$truth[] = ['^                  deleted id 990003  \(other\)  unassigned  fin_audit delete [0-9]+$', 'a snapshot status that is a JSON number reads (other)', 'ug'];
$truth[] = ['^                  id ' . $cand[3] . '  2026-09-12  approved  unassigned$', 'the blank-currency candidate', 'cand'];
$truth[] = ['^                  id ' . $cand[2] . '  2026-09-11  approved  bank$', 'the candidate on the bank account', 'cand'];
$truth[] = ['^                  id ' . $cand[1] . '  2026-09-12  voided  unassigned$', 'the voided candidate', 'cand'];
$truth[] = ['^                  deleted \(fin_audit only\): id ' . $cdel . '  2026-09-12  approved  unassigned$', 'the deleted candidate, from fin_audit', 'cand'];
$truth[] = ['^                  edited away \(fin_audit before had it\): id ' . $cedit . '  2026-09-12  approved  unassigned$', 'the candidate whose amount was edited away', 'cand'];
$truth[] = ['^                  not candidates, same amount and direction, another currency 1: id ' . $cusd . '  2026-09-12  USD  approved  unassigned$', 'the USD row is listed, not a candidate', 'cand'];
$truth[] = ['^                  not candidates, same amount, direction in 1: id ' . $cin . '  2026-09-12  UGX  approved  unassigned$', 'the IN row is listed, not a candidate', 'cand'];
$truth[] = ['^                              candidate ' . $cand[3] . ': creation unknown$', 'a candidate without a creation time is never "at or after"', 'cand'];
$truth[] = ['^                  vs candidate ' . $cdel . ' \(deleted\)  before its creation ', 'the deleted candidate is compared through its fin_audit snapshot', 'cand'];

// ── 15. unassigned rows for §1.11: the voided and the rejected rank 1–3 ──────────────────────────────────────────
$big = [9900000.91, 9800000.92, 9700000.93, 9600000.94, 9600000.94, 9500000.95, 9400000.96];
$top = [];
foreach ($big as $k => $v) $top[] = $row(['direction' => $k === 4 ? 'in' : 'out', 'amount' => $v, 'currency' => 'UGX', 'category' => 'Purchase', 'description' => "RH-MARK big $k",
    'date' => '2026-09-0' . ($k + 1), 'status' => 'approved', 'created_at' => '2026-09-01 10:00:00']);
$must($cb->voidEntry($top[0], 'RH-MARK reason 25', 'RH-MARK Actor 1'), 'void big 0');
$must($cb->rejectEntry($top[1], 'RH-MARK reason 26', $admin), 'reject big 1');
$must($cb->voidEntry($top[2], 'RH-MARK reason 27', 'RH-MARK Actor 1'), 'void big 2');
$q1("UPDATE cb_ledger SET status='RH-MARK status' WHERE id=?", [$top[5]]); $sqlPlanted[] = "status RH-MARK status on row {$top[5]}";
foreach ([9300000.97, 9200000.98, 9100000.99] as $k => $v) $row(['direction' => 'in', 'amount' => $v, 'currency' => 'USD', 'category' => 'Receipt', 'description' => "RH-MARK usd $k", 'date' => '2026-09-02', 'status' => 'approved']);
$row(['direction' => 'in', 'amount' => $A(), 'currency' => '', 'category' => 'Receipt', 'description' => 'RH-MARK blank currency', 'date' => '2026-09-03', 'status' => 'approved']);
$row(['direction' => 'out', 'amount' => $A(), 'currency' => 'ugx', 'category' => 'Purchase', 'description' => 'RH-MARK lowercase', 'date' => '2026-09-04', 'status' => 'approved']);
$row(['direction' => 'out', 'amount' => $A(), 'currency' => 'RH-MARK cur', 'category' => 'Purchase', 'description' => 'RH-MARK free-text currency', 'date' => '2026-09-05', 'status' => 'approved']);
$ids['top'] = $top;
$truth[] = ['^                  UGX  1  id ' . $top[0] . '  2026-09-01  out  voided$', 'top unassigned: the voided row ranks 1 (any status)'];
$truth[] = ['^                  UGX  2  id ' . $top[1] . '  2026-09-02  out  rejected$', 'top unassigned: the rejected row ranks 2'];
$truth[] = ['^                  UGX  4  id ' . $top[3] . '  2026-09-04  out  approved$', 'top unassigned: the tie is decided by id (4)'];
$truth[] = ['^                  UGX  5  id ' . $top[4] . '  2026-09-05  in  approved$', 'top unassigned: the tie is decided by id (5)'];
$truth[] = ['^                  USD  3  id [0-9]+  2026-09-02  in  approved$', 'top unassigned: three USD rows, fewer than five printed'];
$truth[] = ['^                  \(other\)  1  id [0-9]+  2026-09-05  out  approved$', 'top unassigned: a free-text currency groups as (other)'];
$truth[] = ['^                  ugx  1  id [0-9]+  2026-09-04  out  approved$', 'top unassigned: a lowercase code keeps its case'];

// ── 16. cash-ins 1–6 (3–5 as docs/07 records them), CIN-5 planted, CIN-6 written by onCashIn ─────────────────────
$cins = [['other', 'UGX', 'USD Received', '2026-09-20'], ['other', 'UGX', 'Collection', '2026-09-21'], ['rec', 'USD', 'Collection', '2026-09-25'],
         ['rec', 'USD', 'Collection', '2026-09-25'], ['rec', 'USD', 'Collection', '2026-09-25'], ['base', 'UGX', 'USD Received', '2026-09-26']];
foreach ($cins as $k => [$kind, $cur, $cat, $d]) {
    $ci = $store->appendWithId('cash_ins.json', ['collector_id' => 11, 'collector_name' => 'RH-MARK Actor 11', 'amount' => $A(), 'currency' => $cur, 'category' => $cat,
        'description' => 'RH-MARK cash-in ' . ($k + 1), 'status' => 'approved', 'direction' => 'in', 'created_at' => "$d 10:00:00"]);
    if ($k + 1 === 6) StaffLedgerWriter::onCashIn($pdo, $ci);
}
$sl->record(['staff_id' => 11, 'staff_name' => 'RH-MARK Actor 11', 'direction' => 'in', 'currency' => 'USD', 'amount' => 1100000.55, 'category' => 'collection',
    'subcategory' => 'Collection', 'description' => 'RH-MARK cash-in 5', 'status' => 'active', 'source_type' => 'cash_ins', 'source_id' => '5', 'idempotency_key' => 'CIN-5', 'event_date' => '2026-09-25']);
if ((int)$q1("SELECT COUNT(*) FROM staff_ledger WHERE idempotency_key IN ('CIN-5','CIN-6')")->fetchColumn() !== 2) die3('CIN-5 / CIN-6 not written');

// ── 17. equipment ────────────────────────────────────────────────────────────────────────────────────────────────
foreach ([[1, '', null], [2, 'ACC-RH-1', null], [3, 'ACC-RH-1', null], [4, '', '2026-09-30 10:00:00']] as [$u, $acc, $rel]) {
    $q1("INSERT INTO equipment_assignments (unit_id, crm_client_id, starlink_account, assigned_at, released_at, created_at) VALUES (?, ?, ?, '2026-09-01 10:00:00', ?, '2026-09-01 10:00:00')", [$u, 5070 + $u, $acc, $rel]);
}
$sqlPlanted[] = 'four equipment assignments (three live, one released)';
$truth[] = ['^  equipment       live assignments 3: starlink_account empty 1; non-empty accounts held by two or more live assignments 1$', 'equipment: three live, one empty, one shared account'];

// ── 18/19. free text where a code belongs; shapes P0 never met ──────────────────────────────────────────────────
$s18 = $row(['direction' => 'out', 'amount' => $A(), 'currency' => 'UGX', 'category' => 'Transport', 'description' => 'RH-MARK odd source', 'date' => '2026-09-14', 'status' => 'approved', 'source' => 'RH-MARK src']);
$must($cb->voidEntry($s18, 'RH-MARK reason 28', 'RH-MARK Actor 1'), 'void s18');
$l19 = $row(['direction' => 'out', 'amount' => $A(), 'currency' => 'UGX', 'category' => 'Transport', 'description' => 'x', 'date' => '2026-09-15', 'validation_ref' => 'BANK-RH-19', 'status' => 'approved']);
$q1("UPDATE cb_ledger SET description = CAST(X'52482D4D41524B206361663F20E9' AS TEXT) || ' [VOIDED: RH-MARK reason 29]', status='voided' WHERE id=?", [$l19]);
$sqlPlanted[] = "row $l19: a Latin-1 byte in its description, plain-stamped";
$c19 = $row(['direction' => 'out', 'amount' => $A(), 'currency' => 'UGX', 'category' => 'Transport', 'description' => 'RH-MARK case', 'date' => '2026-09-16', 'validation_ref' => 'COL-88888', 'status' => 'approved']);
$q1($SQL295, ['stamped USD BY THE MANUAL ENTRY FORM on a UGX book (5.18.70)', 'COL-88888']);
$sqlPlanted[] = "row $c19: the tool's mark in other ASCII case, plain-stamped (not a tool stamp)";
$q1("INSERT INTO fin_audit (record_type, record_id, action, actor_name, reason, before_json, after_json, changed_keys, created_at)
     VALUES ('cb_ledger', ?, 'update', 'RH-MARK Actor 1', 'RH-MARK reason 30', ?, ?, 'amount', '2026-09-16 12:00:00')",
    [$c19, json_encode(['id' => (string)$c19, 'amount' => '1500000.25', 'direction' => 'out', 'currency' => 'UGX', 'date' => '2026-09-16', 'status' => 'approved']),
     json_encode(['id' => (string)$c19, 'amount' => '1600000.35', 'direction' => 'out', 'currency' => 'UGX', 'date' => '2026-09-16', 'status' => 'approved'])]);
$sqlPlanted[] = "a fin_audit update of row $c19 with numbers stored as strings";
$q1("INSERT INTO fin_audit (record_type, record_id, action, actor_name, reason, before_json, after_json, changed_keys, created_at)
     VALUES ('cb_ledger', 990003, 'delete', 'RH-MARK Actor 1', 'RH-MARK reason 34', ?, NULL, '', '2026-09-29 09:00:00')",
    [json_encode(['id' => 990003, 'status' => 1, 'direction' => 'out', 'currency' => 'UGX', 'date' => '2026-09-12', 'amount' => 1700000.17, 'account_id' => 0])]);
$sqlPlanted[] = 'a fin_audit delete of a 12 Sep row whose before_json status is a JSON number';
$ids['p19'] = [$s18, $l19, $c19];

// ── 20. stamps that are not the row's own (§1.3): a reversal copying a whole stamp, pasted text, uCRM's "T" ─────────
// 925 (I2 again): a short pull receipt, so the 80 bytes the reversal copies hold the WHOLE stamp; its reversal is then
// voided itself. Its void time is its own stamp's minute (after 4 Oct), never the copied one (before its creation)
$a = $A(); $o925 = $pull(925, 0, $a, '2026-09-19', 'RH-Client-45', '2026-09-19T08:00:00+0300');
$must($cb->voidEntry($o925, 'RH-MARK', 'RH-MARK'), 'void 925'); $shift($o925, '2026-09-20 10:00');
$r925 = $rev(925, '2026-10-10 09:00:00');
if (strpos($descOf($r925), ' [VOIDED: RH-MARK — by RH-MARK 2026-09-20 10:00]') === false) die3('925: the reversal does not hold the whole copied stamp');
$must($cb->voidEntry($r925, 'RH-MARK reason 35', 'RH-MARK Actor 1'), 'void rev 925'); $shift($r925, '2026-10-12 09:00');
// a hand entry holding a voided row's pasted text, left live: its pasted stamp is older than the row
$p20a = (int)$must($cb->addEntry(['direction' => 'out', 'amount' => $A(), 'currency' => 'UGX', 'category' => 'Transport',
    'description' => 'RH-MARK re-entered [VOIDED: RH-MARK — by RH-MARK 2026-09-21 11:00]', 'date' => '2026-10-02', 'created_at' => '2026-10-02 12:00:00'], $admin), 'pasted live')['id'];
// the same, voided, and its own stamp then edited away: no stamp of its own is left, its void time comes from fin_audit
$p20b = (int)$must($cb->addEntry(['direction' => 'out', 'amount' => $A(), 'currency' => 'UGX', 'category' => 'Transport',
    'description' => 'RH-MARK copied in [VOIDED: RH-MARK — by RH-MARK 2026-09-22 11:00]', 'date' => '2026-10-02', 'created_at' => '2026-10-02 13:00:00'], $admin), 'pasted voided')['id'];
$must($cb->voidEntry($p20b, 'RH-MARK reason 36', 'RH-MARK Actor 1'), 'void pasted'); $shift($p20b, '2026-10-03 09:00');
$must($cb->updateEntry($p20b, ['description' => 'RH-MARK copied in [VOIDED: RH-MARK — by RH-MARK 2026-09-22 11:00]'], $admin), 'edit pasted');
// a pull receipt whose created_at keeps uCRM's "T" (lib/CashbookService.php:2564), voided later the same day: its own stamp
$t20 = $pull(926, 5046, $A(), '2026-09-18', 'RH-Client-46', '2026-09-18T15:00:00+0300');
$must($cb->voidEntry($t20, 'RH-MARK reason 37', 'RH-MARK Actor 1'), 'void pull T'); $shift($t20, '2026-09-18 16:30');
$ids['own'] = compact('o925', 'r925', 'p20a', 'p20b', 't20');
$truth[] = ['^  reversals       7 row\(s\) for 6 payment\(s\): every original voided 2 · some voided 0 · originals live 2 · original deleted \(fin_audit\) 1 · no original 1$', 'reversals: voided (twice), live (twice), deleted, none'];
$truth[] = ['^                  payments reversed twice or more 1; originals marked reversed and voided 2$', 'payment 924 reversed twice; 920 and 925 marked and voided'];
$truth[] = ['^                  copied text reversal rows carrying a \[VOIDED: mark 2 \(live 1 · voided 1\), left out of both mark counts above; rows with a stamp minute before their own creation, not taken as theirs: reversal rows 1 · other rows 2$',
            'the two reversals holding copied marks are counted on their own; 925\'s reversal and the two pasted rows hold a stamp older than the row', 'ug'];

// ── kyc-first: rows with NULL description, person, currency, date, category and direction ──────────────────────
if ($variant === 'kyc-first') {
    $pdo->exec("INSERT INTO cb_ledger (project, date, direction, amount, currency, category, person, description, validation_ref, status, source, created_at, crm_client_id)
                VALUES ('dishnet', NULL, NULL, 3100000.31, NULL, NULL, NULL, NULL, NULL, 'approved', NULL, NULL, NULL)");
    $pdo->exec("INSERT INTO cb_ledger (project, date, direction, amount, currency, category, person, description, validation_ref, status, source, created_at)
                VALUES ('dishnet', '2026-09-20', NULL, 3200000.32, 'UGX', 'Receipt', NULL, NULL, 'PAY-777', 'voided', 'crm_webhook', '2026-09-20 10:00:00')");
    $pdo->exec("INSERT INTO cb_ledger (project, date, direction, amount, currency, category, person, description, validation_ref, status, source, created_at)
                VALUES ('dishnet', '2026-09-20', 'in', 3200000.32, NULL, NULL, NULL, NULL, 'PAY-777', 'approved', 'crm_webhook', '2026-09-20 10:00:00')");
    $pdo->exec("INSERT INTO cb_ledger (project, date, direction, amount, currency, category, person, description, validation_ref, status, source, created_at)
                VALUES ('dishnet', '2026-09-21', 'out', 7340000.37, NULL, NULL, NULL, 'RH-MARK null-currency candidate', NULL, 'approved', NULL, NULL)");
    $kyc('2026-09-22', 3300000.33, '5080');
    $sqlPlanted[] = 'rows with NULL date, direction, currency, category, person, description, reference, source, created_at';
}

// ── 6 (end). the records tool, the real one, on the sandbox (tools/staff_records_currency.php --void --yes) ───────
$store = null; $cb = null; $sl = null; $pdo = null; gc_collect_cycles();
$runTool = static function () use ($root, $data): void {
    $cmd = 'DN_DATA_DIR=' . escapeshellarg($data) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/tools/staff_records_currency.php") . ' --void --yes 2>&1';
    exec($cmd, $out, $rc);
    if ($rc !== 0 || strpos(implode("\n", $out), 'Done.') === false) die3('the records tool did not finish: rc ' . $rc);
};
if ($variant === 'ug-mixed') {   // cash-in 4 voided by the Staff Cashbooks page's void_cash_in (staff_cashbooks.php:250-254, :262)
    $st = SqliteStore::create($data); $p2 = $st->getPdo();
    $ci = $st->load('cash_ins.json');
    foreach ($ci as &$x) if ((int)$x['id'] === 4) { $x['prev_status'] = $x['status']; $x['status'] = 'voided'; $x['voided_by'] = 'RH-MARK Actor 12'; $x['voided_at'] = '2026-09-27 10:00:00'; $x['void_reason'] = 'RH-MARK reason 31'; }
    unset($x); $st->save('cash_ins.json', $ci);
    StaffLedgerWriter::onCashInVoided($p2, 4, 'RH-MARK Actor 12');
    $st = null; $p2 = null;
}
if ($variant !== 'ug-novoid') $runTool();
$voidTyped = ['ug-novoid' => 'no sign of it — none of the three is voided', 'ug-mixed' => 'mixed — read the three lines'][$variant] ?? 'yes — all three voided by the 5.18.70 tool';
$truth[] = ['^                  VOID typed   ' . preg_quote($voidTyped, '/') . '$', 'VOID typed: ' . $voidTyped, 'cashins'];
if ($variant === 'ug-novoid') {
    $truth[] = ['^  records tool    cb_ledger rows with the 5.18.70 tool\'s reason stamp 0 ', 'no tool stamp without the tool run'];
} else {
    $truth[] = ['^  records tool    cb_ledger rows with the 5.18.70 tool\'s reason stamp 1 \(voided 1, voided_reconcile 0, live 0\): ids ' . $mr . '; with the 5.18.69 form 0 \(expected 0\)$', 'the tool stamped exactly its own row; the other-case mark is not counted'];
    $truth[] = ['^                  payment_collections voided by the 5.18.70 tool 2: ledger row tool-stamped 1 · ledger row voided, other stamp 0 · ledger row live 0 · no ledger row 1;', 'two Manual Entry collections, one with no ledger row'];
    $truth[] = ['^                  cash_ins voided by the tool ' . ($variant === 'ug-mixed' ? 2 : 3) . '; staff_ledger rows voided by the tool 1$', 'cash-ins voided by the tool; CIN-5 voided by it'];
}

// ── variant reshaping, after the plugin has written (SQL, in the sandbox) ───────────────────────────────────────
$p = new PDO('sqlite:' . $data . '/plugin.sqlite3'); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($variant === 'bare') {
    foreach (['fin_audit', 'staff_ledger', 'equipment_assignments', 'cash_ins', 'cash_expenses', 'crm_payment_retry', 'cb_accounts'] as $t) $p->exec("DROP TABLE IF EXISTS $t");
    $sqlPlanted[] = 'seven tables dropped';
    $truth = array_values(array_filter($truth, static fn($t) => ($t[2] ?? '') === 'tool' || strpos($t[0], 'KYC rows') !== false
        || (($t[2] ?? '') === 'cand' && strpos($t[1], 'the candidate copy lists') === 0)));
    $truth[] = ['^  tables          cb_ledger [0-9]+ · cb_accounts absent · fin_audit absent · staff_ledger absent · equipment_assignments absent$', 'the dropped tables read absent'];
    $truth[] = ['^  equipment       absent \(equipment_assignments is not in this book\)$', 'equipment: absent, not 0'];
    $truth[] = ['^  fin_audit       absent \(fin_audit is not in this book\)$', 'fin_audit: absent, not 0'];
    $truth[] = ['^                  absent \(cash_ins is not in this book\)$', 'the cash-ins: absent'];
}
if ($variant === 'proper') {
    $rows = $p->query('SELECT id, data FROM cash_ins ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $p->exec('DROP TABLE cash_ins');
    $p->exec('CREATE TABLE cash_ins (id INTEGER PRIMARY KEY, status TEXT, voided_by TEXT, voided_at TEXT, created_at TEXT, category TEXT, currency TEXT)');
    $ins = $p->prepare('INSERT INTO cash_ins (id, status, voided_by, voided_at, created_at, category, currency) VALUES (?,?,?,?,?,?,?)');
    foreach ($rows as $r) { $d = json_decode((string)$r['data'], true) ?: []; $ins->execute([(int)$r['id'], $d['status'] ?? null, $d['voided_by'] ?? null, $d['voided_at'] ?? null, $d['created_at'] ?? null, $d['category'] ?? null, $d['currency'] ?? null]); }
    $p->exec("INSERT INTO cash_expenses (id, data) VALUES ((SELECT MAX(id) + 1 FROM cash_expenses), '{\"id\": 5, \"status\": \"vo')");
    $p->exec("INSERT INTO crm_payment_retry (id, data) VALUES ((SELECT MAX(id) + 1 FROM crm_payment_retry), '{\"collection_id\": 3, \"crm_id\": 99')");
    $p->exec("INSERT INTO fin_audit (record_type, record_id, action, actor_name, reason, before_json, after_json, changed_keys, created_at)
              VALUES ('cb_ledger', 990001, 'delete', 'RH-MARK Actor 1', 'RH-MARK reason 32', '{\"id\": 990001, \"status\": \"vo', NULL, '', '2026-09-30 10:00:00')");
    $p->exec("INSERT INTO fin_audit (record_type, record_id, action, actor_name, reason, before_json, after_json, changed_keys, created_at)
              VALUES ('cb_ledger', 990002, 'delete', 'RH-MARK Actor 1', 'RH-MARK reason 33', '{}', NULL, '', '2026-09-30 11:00:00')");
    $sqlPlanted[] = 'cash_ins rebuilt as a proper table without audit_log; truncated JSON in cash_expenses and crm_payment_retry; an invalid and a {} fin_audit snapshot';
    $truth[] = ['^  stores          payment_collections json [0-9]+ \(not valid JSON 0\) · cash_ins proper 6 · cash_expenses json 2 \(not valid JSON 1\) · crm_payment_retry json [0-9]+ \(not valid JSON 1\)$', 'the proper cash_ins and the truncated rows'];
    $truth[] = ['^  columns         missing: cash_ins\.audit_log$', 'the proper cash_ins has no audit_log column'];
    $truth[] = ['relabelled unknown \(column audit_log missing\)', 'relabelled reads unknown, never no', 'cashins'];
    $truth[] = ['^  deleted         8 cb_ledger row\(s\) deleted: voided when deleted 1 · live 5 · before_json unreadable 2$', 'the invalid and the {} snapshot are both unreadable'];
}
$p = null;
if ($variant === 'big') {
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/grow-r0.php') . ' ' . escapeshellarg($data . '/plugin.sqlite3') . ' 30000 2>&1', $o2, $rc);
    if ($rc !== 0) die3('grow-r0 failed');
    $truth = [];
}
echo json_encode(['variant' => $variant, 'ids' => $ids, 'truth' => $truth, 'sql_planted' => $sqlPlanted], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
