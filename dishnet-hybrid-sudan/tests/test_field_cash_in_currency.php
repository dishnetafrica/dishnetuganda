<?php
declare(strict_types=1);
/**
 * test_field_cash_in_currency.php — 5.18.70: the Field Register page speaks the book's base, and the records tool
 * repairs the cash-ins its base pill mislabelled.
 *
 * Found on production after 5.18.69: the three "USD" rows on a technician's staff cashbook were cash_ins, not Manual
 * Entry collections. They came from the Field Register (tabs/sales/wallet.php), whose base pill is labelled with the
 * book's base but whose JavaScript submitted the literal 'USD' into every form's hidden currency field — and
 * dn_entry_currency passes 'USD' through on a book that lists UGX,USD. The same literal drove that page's filter, its
 * sums, a label and its CSV export. Now:
 *   1. the page tells its JavaScript the base (var _fr3Base) and every submission for the base pill sends it; the
 *      filter whitelist, the sums, the pending label, the filter button and the export read the base too;
 *   2. tools/staff_records_currency.php lists cash-ins stamped in a non-base currency beside the Manual Entry
 *      collections, voids them the page's own way (void_cash_in + onCashInVoided) or relabels them (record and live
 *      staff_ledger row), says "no candidate" when nothing is in scope, and prints 0 for an empty table.
 *
 * Driven through the real forms on sandboxed plugins (fake uCRM, fake Evolution), under a Uganda configuration and under
 * a South Sudan one (where base = USD, so the page submits 'USD' exactly as before).
 */
$root = dirname(__DIR__);
require_once $root . '/tests/fixtures/staff_jobs_sandbox.php';
$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d !== '' ? "\n       " . substr($d, 0, 700) : '') . "\n"; } }
$UG = ['tenant_profile' => 'uganda', 'timezone' => 'Africa/Kampala', 'currency_symbol' => 'UGX', 'currency_code' => 'UGX',
       'cashbook_base_currency' => 'UGX', 'cashbook_currencies' => 'UGX,USD'];
$SS = ['tenant_profile' => 'south-sudan'];

$people = function (SjSandbox $s): void {
    $s->staff('acct', ['name' => 'Sandbox Accountant', 'email' => 'acct@example.test', 'role' => 'accountant']);
    $s->staff('tech', ['name' => 'Sandbox Tech', 'email' => 'tech@example.test', 'role' => 'support', 'phone' => '+256700000011']);
    $s->login('acct', 'acct@example.test', 'sj-password-1');
    $s->login('tech', 'tech@example.test', 'sj-password-1');
};
// the Field Register's cash-in form, posted with the fields its JavaScript fills (fr3FormIn)
$cashIn = function (SjSandbox $s, string $cur, string $cat, string $amount, string $desc, string $ssp = '0'): array {
    return $s->form('tech', ['action' => 'log_cash_in', 'currency' => $cur, 'category' => $cat, 'ssp_amount' => $ssp, 'usd_given' => '0', 'rate' => '0',
                             'amount' => $amount, 'description' => $desc], 'page=dashboard&tab=wallet', 'page=dashboard&tab=wallet');
};
$cins = function (SjSandbox $s): array {
    try { $rows = $s->q("SELECT id, data FROM cash_ins ORDER BY id"); } catch (Throwable $e) { return []; }
    $o = []; foreach ($rows as $r) { $d = json_decode((string)$r['data'], true) ?: []; $d['_id'] = (int)$r['id']; $o[] = $d; } return $o;
};
$ledger = function (SjSandbox $s, string $key): array {
    try { $r = $s->q("SELECT idempotency_key, currency, status, amount FROM staff_ledger WHERE idempotency_key = ?", [$key]); } catch (Throwable $e) { return []; }
    return $r[0] ?? [];
};
// what the page's JavaScript submits for the base pill, read off the served page — so the test posts what a browser would
$pillSubmits = function (string $html): string { return preg_match('/var _fr3Base = "([A-Z]{3})";/', $html, $m) ? $m[1] : ''; };
// what the Field Register's base pill wrote until 5.18.70: a cash-in stamped 'USD' on a UGX book, approved on the spot
$plant = function (SjSandbox $s): void {
    file_put_contents($s->plug . '/__plant_cin.php', '<?php
$root = __DIR__; foreach (["bootstrap_data", "StoreInterface", "JsonStore", "SqliteStore", "StaffLedgerWriter", "StaffLedgerService"] as $l) require_once $root . "/lib/$l.php";
$store = SqliteStore::create((string)getenv("DN_DATA_DIR"));
$rec = $store->appendWithId("cash_ins.json", ["collector_id" => (int)$argv[1], "collector_name" => "Sandbox Tech", "amount" => (float)$argv[2], "currency" => $argv[3],
    "ssp_amount" => 0, "usd_given" => 0, "rate" => 0, "category" => $argv[4], "description" => $argv[5], "status" => "approved", "approved_by" => "auto",
    "approved_at" => "2026-09-25 10:00:00", "created_at" => "2026-09-25 10:00:00"]);
if (($argv[6] ?? "") === "ledger") StaffLedgerWriter::onCashIn($store->getPdo(), $rec);
echo "planted #" . (int)($rec["id"] ?? 0);
');
};
$plantOne = function (SjSandbox $s, int $tech, string $amount, string $cat, string $desc, bool $withLedger = false) use ($plant): int {
    $plant($s);
    [$rc, $out] = $s->run($s->plug . '/__plant_cin.php', array_merge([(string)$tech, $amount, 'USD', $cat, $desc], $withLedger ? ['ledger'] : []));
    if ($rc !== 0 || strpos($out, 'planted #') !== 0) throw new RuntimeException("plant failed: $rc $out");
    return (int)substr($out, 9);
};
$TOOL = '/tools/staff_records_currency.php';
$Q = 'page=dashboard&tab=wallet';

// ═══════════════════════════════════════════════════════════════════════════
echo "A. Uganda — the Field Register page tells its forms the book's base\n";
$s = SjSandbox::start($root, $UG, 'sjfci');
$people($s); $tech = $s->ids['tech'];
$pg = $s->page('tech', $Q);
is_($pillSubmits($pg) === 'UGX', 'the page tells its JavaScript the base is UGX (var _fr3Base = "UGX")', substr($pg, 0, 300));
is_(substr_count($pg, "fr3fInCurrency').value  = _fr3Base;") === 2 && strpos($pg, "fr3fInCurrency').value  = 'USD';") === false,
    'both cash-in submissions send the base; neither sends the literal USD');
is_(substr_count($pg, "isSsp ? 'SSP' : _fr3Base;") === 5 && strpos($pg, "isSsp ? 'SSP' : 'USD';") === false,
    'the five expense submissions send the base when the pill is not SSP');
is_(substr_count($pg, "_fr3Curr === 'SSP' ? 'SSP' : _fr3Base;") === 2, 'the handover and advance submissions send the base when the pill is not SSP');
is_(strpos($pg, '&fr_curr=UGX') !== false && strpos($pg, '&fr_curr=USD') === false, 'the base filter button links fr_curr=UGX, not fr_curr=USD');
is_(strpos($pg, 'fr3-cpill-lbl">💵 UGX<') !== false, 'control: the base pill is labelled UGX, as before');

echo "\nB. A cash-in through the form, as the page now submits it, lands in the UGX bag\n";
$r = $cashIn($s, $pillSubmits($pg), 'Collection', '20000', 'cash from office for cable');
is_($r[0] === 302, 'the technician records UGX 20,000 received, as the page now submits it (302)', $r[0] . ' ' . substr((string)$r[1], 0, 200));
$c = $cins($s); $c0 = $c[0] ?? [];
is_(count($c) === 1 && ($c0['currency'] ?? '') === 'UGX' && (float)($c0['amount'] ?? 0) === 20000.0 && ($c0['category'] ?? '') === 'Collection' && ($c0['status'] ?? '') === 'approved',
    'the cash-in is stamped UGX, Collection, approved on the spot', json_encode($c0));
$pg = $s->page('tech', $Q);
is_(preg_match('/Collections<\/span><span>UGX\s?20,000\.00/u', $pg) === 1, "the wallet's Collections line counts it: UGX 20,000.00");
$pgA = $s->page('acct', 'page=dashboard&tab=staff_cashbooks&sc_staff=' . $tech);
is_(preg_match('/UGX Collected.{0,800}?UGX 20,000\.00/si', $pgA) === 1, 'the Staff Cashbooks UGX tile counts it: UGX 20,000.00', 'len ' . strlen($pgA));
$pgF = $s->page('tech', $Q . '&fr_curr=UGX');
is_(strpos($pgF, 'cash from office for cable') !== false, 'the UGX filter keeps the row in view');

echo "\nC. Production's shape: cash-ins the old pill stamped USD — the LIST names them\n";
$g1 = $plantOne($s, $tech, '200000', 'Collection', 'Outdoor ethernet cable roll 305 m');
$g2 = $plantOne($s, $tech, '50000', 'Collection', 'Advance for other expenses');
$g3 = $plantOne($s, $tech, '50000', 'Collection', '6th street installation allowance (advance )');
$g4 = $plantOne($s, $tech, '30000', 'USD Received', 'cash in with a ledger row', true);
is_($g1 > 0 && $g2 > 0 && $g3 > 0 && $g4 > 0, "planted four USD-stamped cash-ins (#$g1 #$g2 #$g3 Collection, #$g4 USD Received)");
$L = $ledger($s, 'CIN-' . $g4);
is_(($L['currency'] ?? '') === 'USD' && ($L['status'] ?? '') === 'active', "control: the USD Received plant has a live staff_ledger row CIN-$g4 stamped USD", json_encode($L));
$pg = $s->page('tech', $Q);
is_(preg_match('/Collections<\/span><span>UGX\s?320,000\.00/u', $pg) === 1,
    "control: the wallet's Collections line counts the three mislabelled rows too — UGX 320,000.00 (20,000 + 300,000): the label lied and the figure followed");
$pgA = $s->page('acct', 'page=dashboard&tab=staff_cashbooks&sc_staff=' . $tech);
is_(preg_match('/UGX Collected.{0,800}?UGX 20,000\.00/si', $pgA) === 1, 'control: the Staff Cashbooks UGX tile does not count them (still 20,000.00) — the two screens disagreed by 300,000');
$s->store()->load('cash_expenses.json');   // a table that exists and is empty
$before = json_encode($cins($s));
[$rc, $out] = $s->run($s->plug . $TOOL);
is_($rc === 0 && strpos($out, 'LIST — nothing was changed') !== false, 'LIST exits 0 and says nothing was changed', $rc . ' ' . substr($out, 0, 500));
is_(preg_match('/cash_ins\s+UGX 1 · USD 4 ◄ not the base/', $out) === 1, '…the census reads cash_ins UGX 1 · USD 4 ◄ not the base', $out);
is_(preg_match('/\n    cash_expenses\s+0\n/', $out) === 1, '…a table that exists but is empty reads 0', $out);
is_(strpos($out, 'cash-ins stamped in a currency other than UGX: 4 (4 not yet voided)') !== false, '…four candidates, none voided yet', $out);
is_(preg_match('/cash-in #' . $g1 . '\s+2026-09-25\s+Sandbox Tech\s+Collection\s+USD\s+200,000\.00\s+approved\s+Outdoor ethernet cable roll 305 m/', $out) === 1,
    "…and lists cash-in #$g1 with its category, stamp, amount, status and description", $out);
is_(preg_match('/cash-in #' . $g4 . '\s+2026-09-25\s+Sandbox Tech\s+USD Received\s+USD\s+30,000\.00\s+approved/', $out) === 1, "…and cash-in #$g4 (USD Received)", $out);
is_(json_encode($cins($s)) === $before, 'LIST wrote nothing');

echo "\nD. VOID — the page's own void_cash_in, record for record, and the ledger row with it\n";
[$rc, $out] = $s->run($s->plug . $TOOL, ['--void', '--yes']);
is_($rc === 0 && strpos($out, "cash-in #$g1 voided (USD 200,000.00, Collection, Sandbox Tech)") !== false && strpos($out, "cash-in #$g4 voided (USD 30,000.00, USD Received, Sandbox Tech)") !== false,
    'VOID exits 0 and names each row', $rc . ' ' . substr($out, 0, 600));
$byId = []; foreach ($cins($s) as $x) $byId[(int)($x['id'] ?? $x['_id'])] = $x;
$v = $byId[$g1] ?? [];
is_(($v['status'] ?? '') === 'voided' && ($v['prev_status'] ?? '') === 'approved' && ($v['voided_by'] ?? '') === 'staff_records_currency 5.18.70' && !empty($v['voided_at'])
    && strpos((string)($v['void_reason'] ?? ''), "Field Register's base pill") !== false && ($v['currency'] ?? '') === 'USD',
    "cash-in #$g1 is voided the page's way (prev_status, voided_by/at, void_reason), its stamp untouched, nothing deleted", json_encode($v));
is_(count($byId) === 5 && ($byId[$c0['id'] ?? 0]['status'] ?? '') === 'approved' && ($byId[$g2]['status'] ?? '') === 'voided' && ($byId[$g3]['status'] ?? '') === 'voided' && ($byId[$g4]['status'] ?? '') === 'voided',
    'all five cash-ins are still there: the UGX one approved, the four USD ones voided');
$L = $ledger($s, 'CIN-' . $g4);
is_(($L['status'] ?? '') === 'voided', "the USD Received row's staff_ledger entry CIN-$g4 is voided too (onCashInVoided, as the page does)", json_encode($L));
$al = implode("\n", array_column($s->q("SELECT data FROM activity_log ORDER BY id DESC LIMIT 6"), 'data'));
is_(substr_count($al, '"void_cash_in"') === 4 && strpos($al, '"staff_records_currency"') !== false, "four void_cash_in activity lines (the page's shape) and the tool's own summary", substr($al, 0, 400));
$pg = $s->page('tech', $Q);
is_(preg_match('/Collections<\/span><span>UGX\s?20,000\.00/u', $pg) === 1, "the wallet's Collections line is back to UGX 20,000.00");
$pgA = $s->page('acct', 'page=dashboard&tab=staff_cashbooks&sc_staff=' . $tech);
is_(preg_match('/UGX Collected.{0,800}?UGX 20,000\.00/si', $pgA) === 1, 'the Staff Cashbooks UGX tile is unchanged (20,000.00) — the two screens agree again');
[$rc, $out] = $s->run($s->plug . $TOOL);
is_($rc === 0 && strpos($out, 'cash-ins stamped in a currency other than UGX: 4 (0 not yet voided)') !== false, 'LIST now reads 4 (0 not yet voided)', $out);
[$rc, $out] = $s->run($s->plug . $TOOL, ['--void', '--yes']);
is_($rc === 0 && strpos($out, 'Nothing to VOID: every candidate is already voided.') !== false, 'a second VOID has nothing left and says so', $rc . ' ' . substr($out, -300));
$s->stop();

echo "\nE. RELABEL — the record and its live ledger row; and the wording when nothing is in scope\n";
$s = SjSandbox::start($root, $UG, 'sjfcr');
$people($s); $tech = $s->ids['tech'];
[$rc, $out] = $s->run($s->plug . $TOOL, ['--void', '--yes']);
is_($rc === 0 && strpos($out, 'Nothing to VOID: no Manual Entry collection and no cash-in is stamped in a currency other than UGX.') !== false,
    'with nothing in scope, VOID says so — not "already voided" — and exits 0', $rc . ' ' . substr($out, -300));
$h1 = $plantOne($s, $tech, '70000', 'Collection', 'the only record of this money');
$h2 = $plantOne($s, $tech, '30000', 'USD Received', 'the only record, with a ledger row', true);
$pgA = $s->page('acct', 'page=dashboard&tab=staff_cashbooks&sc_staff=' . $tech);
is_(preg_match('/UGX Collected.{0,800}?UGX 100,000\.00/si', $pgA) !== 1, 'control: stamped USD, neither counts in the UGX tile');
[$rc, $out] = $s->run($s->plug . $TOOL, ['--relabel', '--yes']);
is_($rc === 0 && strpos($out, "cash-in #$h1 relabelled USD → UGX (70,000.00, Collection, Sandbox Tech)") !== false && strpos($out, "staff_ledger CIN-$h2: currency → UGX") !== false,
    'RELABEL exits 0, names each row and the ledger row it relabelled', $rc . ' ' . substr($out, -500));
$byId = []; foreach ($cins($s) as $x) $byId[(int)($x['id'] ?? $x['_id'])] = $x;
$w = $byId[$h1] ?? []; $lg = is_array($w['audit_log'] ?? null) ? end($w['audit_log']) : [];
is_(($w['currency'] ?? '') === 'UGX' && ($w['status'] ?? '') === 'approved' && ($lg['action'] ?? '') === 'relabel' && ($lg['from'] ?? '') === 'USD' && ($lg['to'] ?? '') === 'UGX',
    "cash-in #$h1 now reads UGX, still approved, with a relabel entry in its audit_log", json_encode($w));
$L = $ledger($s, 'CIN-' . $h2);
is_(($L['currency'] ?? '') === 'UGX' && ($L['status'] ?? '') === 'active', "the staff_ledger row CIN-$h2 reads UGX and is still live", json_encode($L));
$pgA = $s->page('acct', 'page=dashboard&tab=staff_cashbooks&sc_staff=' . $tech);
is_(preg_match('/UGX Collected.{0,800}?UGX 100,000\.00/si', $pgA) === 1, 'both now count in the UGX tile: UGX 100,000.00 (70,000 + 30,000)');
$s->stop();

echo "\nF. South Sudan — base USD: the page submits USD exactly as before; the tool refuses\n";
$m = SjSandbox::start($root, $SS, 'sjfcs');
$people($m); $techS = $m->ids['tech'];
$pg = $m->page('tech', $Q);
is_($pillSubmits($pg) === 'USD', 'the page tells its JavaScript the base is USD — the pill submits USD, as before');
is_(strpos($pg, '&fr_curr=USD') !== false, '…and the filter button still links fr_curr=USD');
$r = $cashIn($m, $pillSubmits($pg), 'Collection', '20', 'golden'); $c = $cins($m);
is_($r[0] === 302 && count($c) === 1 && ($c[0]['currency'] ?? '') === 'USD' && (float)($c[0]['amount'] ?? 0) === 20.0, 'a Collection cash-in is stamped USD (unchanged)', json_encode($c[0] ?? []));
$r = $cashIn($m, 'SSP', 'SSP Received', '0', 'golden ssp', '50000'); $c = $cins($m);
is_($r[0] === 302 && count($c) === 2 && ($c[1]['currency'] ?? '') === 'SSP' && (float)($c[1]['ssp_amount'] ?? 0) === 50000.0, 'an SSP Received cash-in is stamped SSP (unchanged)', json_encode($c[1] ?? []));
[$rc, $out] = $m->run($m->plug . $TOOL);
is_($rc === 2 && strpos($out, 'REFUSED') !== false, 'the tool refuses the South Sudan book (exit 2)', $rc . ' ' . substr($out, 0, 300));
$m->stop();

echo "\nG. Weakened copies are caught\n";
$mutants = [
  ['the Collection submission sends the literal USD again', 'tabs/sales/wallet.php',
   "    document.getElementById('fr3fInCurrency').value  = _fr3Base;\n    document.getElementById('fr3fInCategory').value  = 'Collection';\n",
   "    document.getElementById('fr3fInCurrency').value  = 'USD';\n    document.getElementById('fr3fInCategory').value  = 'Collection';\n",
   function (SjSandbox $m, int $t) use ($Q): bool { $pg = $m->page('tech', $Q); return strpos($pg, "fr3fInCurrency').value  = 'USD';") === false; }],
  ['the tool no longer looks at cash-ins', 'tools/staff_records_currency.php',
   "\$cins = \$hasTable('cash_ins') ? (\$store->load('cash_ins.json') ?: []) : [];\n", "\$cins = [];\n",
   function (SjSandbox $m, int $t) use ($plantOne, $TOOL): bool { $plantOne($m, $t, '50000', 'Collection', 'x'); [$rc, $out] = $m->run($m->plug . $TOOL); return strpos($out, 'cash-in #') !== false; }],
  ['VOID leaves the staff_ledger row live', 'tools/staff_records_currency.php',
   "StaffLedgerWriter::onCashInVoided(\$pdo, \$id, \$by);", "/* mutant: the ledger row is left live */",
   function (SjSandbox $m, int $t) use ($plantOne, $TOOL, $ledger): bool { $id = $plantOne($m, $t, '30000', 'USD Received', 'y', true); $m->run($m->plug . $TOOL, ['--void', '--yes']); return ($ledger($m, 'CIN-' . $id)['status'] ?? '') === 'voided'; }],
];
foreach ($mutants as $i => [$label, $rel, $old, $new, $check]) {
    [$tmp, $n] = sj_weakened_copy($root, $rel, $old, $new);
    is_($n === 1, "mutant " . ($i + 1) . ": the anchor is unique in $rel", "count $n");
    if ($n === 1) {
        $mm = SjSandbox::start($tmp, $UG, 'sjfcm' . $i); $people($mm);
        is_($check($mm, $mm->ids['tech']) === false, "mutant " . ($i + 1) . " is caught: $label");
        $mm->stop();
    }
    exec('rm -rf ' . escapeshellarg($tmp));
}
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
