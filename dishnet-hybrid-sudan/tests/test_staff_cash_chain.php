<?php
declare(strict_types=1);
/**
 * test_staff_cash_chain.php — 5.18.68: money the office hands a staff member reaches THEIR
 * ledger in the BOOK's base currency, and the Cashbook page's hero is what the ledger says
 * is in hand.
 *
 * Before 5.18.68 the chain Add Entry → auto-link → cash_ins.json → staff_ledger → Staff
 * Cashbooks existed only for the literal 'USD' bag: a UGX Staff Advance on the Uganda
 * install was written to the staff's register with amount 0, labelled USD, and every
 * reader dropped UGX rows — the operator could give Elisha UGX 650,000 and see nothing
 * under Elisha. The fix makes every "is this the USD bag?" question "is this the BOOK's
 * base bag?" (dn_book_base), so on South Sudan — base USD — nothing changes.
 *
 * Driven through the REAL web wizard (cb_action=add_entry) and the real field-expense
 * forms on a sandboxed plugin (fake uCRM, fake Evolution), under a Uganda configuration
 * and under a South Sudan one. South Sudan's records are compared with a golden captured
 * from the 5.18.67 tree through the same forms (scratchpad ss_capture.php, 4 Oct 2026):
 * byte for byte the same rows, texts and amounts.
 */
$root = dirname(__DIR__);
require_once $root . '/tests/fixtures/staff_jobs_sandbox.php';
$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d !== '' ? "\n       " . substr($d, 0, 600) : '') . "\n"; } }

$UG = ['tenant_profile' => 'uganda', 'timezone' => 'Africa/Kampala', 'currency_symbol' => 'UGX', 'currency_code' => 'UGX',
       'cashbook_base_currency' => 'UGX', 'cashbook_currencies' => 'UGX,USD'];
$SS = ['tenant_profile' => 'south-sudan'];
$today = date('Y-m-d');

// ── helpers ──────────────────────────────────────────────────────────────────
$people = function (SjSandbox $s, string $acct, string $tech, string $phone): void {
    $s->staff('acct', ['name' => $acct, 'email' => 'acct@example.test', 'role' => 'accountant']);
    $s->staff('tech', ['name' => $tech, 'email' => 'tech@example.test', 'role' => 'support', 'phone' => $phone]);
    $s->login('acct', 'acct@example.test', 'sj-password-1');
    $s->login('tech', 'tech@example.test', 'sj-password-1');
};
$wizard = function (SjSandbox $s, array $f) use ($today): array {
    $base = ['cb_action' => 'add_entry', 'direction' => 'out', 'project' => 'dishnet', 'date' => $today, 'ssp_rate' => '',
             'category' => 'Staff Advance', 'category_raw' => 'Staff Advance', 'person' => '', 'description' => '',
             'validation_ref' => '', 'validation_status' => 'na', 'inv_ref' => '', 'rcpt_ref' => '', 'pay_month' => '',
             'exchange_type' => '', 'exch_ssp_amount' => ''];
    return $s->form('acct', $f + $base, 'page=dashboard&tab=cashbook', 'page=dashboard&tab=cashbook');
};
$cins = function (SjSandbox $s): array {
    $out = [];
    try { $rows = $s->q("SELECT id, data FROM cash_ins ORDER BY id"); } catch (Throwable $e) { return []; } // the store creates the table on the first save
    foreach ($rows as $r) { $d = json_decode((string)$r['data'], true) ?: []; $d['_id'] = (int)$r['id']; $out[] = $d; }
    return $out;
};
$ledger = function (SjSandbox $s, int $staffId): array {
    return $s->q("SELECT direction, currency, amount, ssp_amount, category, subcategory, description, status, source_type, idempotency_key
                  FROM staff_ledger WHERE staff_id = ? ORDER BY id", [$staffId]);
};
$cb = function (SjSandbox $s): array {
    return $s->q("SELECT sr, direction, amount, currency, ssp_amount, ssp_rate, category, person, status, source FROM cb_ledger ORDER BY id");
};
$lastText = function (SjSandbox $s): array { $t = $s->texts(); return $t ? end($t) : []; };
$money = function ($v): string { return number_format((float)$v, 2); };

// ═══════════════════════════════════════════════════════════════════════════
echo "A. Uganda — a UGX Staff Advance reaches the technician's ledger\n";
$s = SjSandbox::start($root, $UG, 'sjcash');
$people($s, 'Sandbox Accountant', 'Sandbox Tech', '+256700000011');
$tech = $s->ids['tech'];

// the till first holds UGX 500,000 (a receipt), so cash in hand stays positive
$r = $wizard($s, ['direction' => 'in', 'currency' => 'UGX', 'amount' => '500000', 'category' => 'Receipt', 'category_raw' => 'Receipt', 'description' => 'opening float']);
is_($r[0] === 302, 'a UGX receipt through the wizard is accepted (302)', $r[0] . ' ' . substr($r[1], 0, 200));
is_(count($cins($s)) === 0, 'a receipt with no person creates no staff cash-in (control)');

$r = $wizard($s, ['currency' => 'UGX', 'amount' => '150000', 'person' => 'Sandbox Tech', 'description' => 'for materials']);
is_($r[0] === 302, 'a UGX 150,000 Staff Advance to Sandbox Tech is accepted (302)', $r[0] . ' ' . substr($r[1], 0, 200));
$rows = $cb($s);
$adv = $rows[1] ?? [];
is_(($adv['direction'] ?? '') === 'out' && (float)($adv['amount'] ?? 0) === 150000.0 && ($adv['currency'] ?? '') === 'UGX'
    && ($adv['category'] ?? '') === 'Staff Advance' && ($adv['person'] ?? '') === 'Sandbox Tech',
    'the main cashbook holds the OUT row: UGX 150,000, Staff Advance, Sandbox Tech', json_encode($adv));
$ci = $cins($s);
is_(count($ci) === 1, 'exactly one cash-in was created for the staff member', json_encode($ci));
$c0 = $ci[0] ?? [];
is_((float)($c0['amount'] ?? -1) === 150000.0, 'the cash-in carries the amount — UGX 150,000, not 0 (the 5.18.67 defect)', json_encode($c0));
is_(($c0['currency'] ?? '') === 'UGX' && ($c0['category'] ?? '') === 'USD Received' && ($c0['cb_ref'] ?? '') === ($adv['sr'] ?? '?')
    && (int)($c0['collector_id'] ?? 0) === $tech && ($c0['status'] ?? '') === 'approved' && ($c0['approved_by'] ?? '') === 'auto (cashbook link)',
    'currency UGX, the base-bag category, the cashbook SR as cb_ref, the right collector, approved by the link', json_encode($c0));
$l = $ledger($s, $tech);
is_(count($l) === 1 && ($l[0]['direction'] ?? '') === 'in' && ($l[0]['currency'] ?? '') === 'UGX' && (float)($l[0]['amount'] ?? 0) === 150000.0
    && ($l[0]['subcategory'] ?? '') === 'USD Received' && ($l[0]['idempotency_key'] ?? '') === 'CIN-' . ($c0['_id'] ?? 0),
    'the staff ledger gained one IN row: UGX 150,000, labelled UGX, keyed CIN-<cash-in id>', json_encode($l));
$t = $lastText($s);
is_(($t['number'] ?? '') !== '' && strpos((string)($t['text'] ?? ''), 'UGX 150,000.00') !== false && strpos((string)($t['text'] ?? ''), 'Staff Advance') !== false
    && strpos((string)($t['text'] ?? ''), $adv['sr'] ?? '?') !== false,
    'the technician is told on WhatsApp: UGX 150,000.00, the category and the SR', json_encode($t));

echo "\nB. Where the money now shows\n";
$pg = $s->page('acct', 'page=dashboard&tab=staff_cashbooks&sc_staff=' . $tech);
is_(strpos($pg, 'UGX Cashbook') !== false && strpos($pg, '150,000.00') !== false,
    "the accountant's Staff Cashbooks page for Sandbox Tech reads UGX 150,000.00", 'len ' . strlen($pg));
is_(strpos($pg, 'for materials') !== false, '…and its ledger lists the advance (the UGX row is in the base tab)');
is_(strpos($pg, 'SSP Cashbook') === false, '…and shows no SSP tab (5.18.57 holds)');
$pg = $s->page('acct', 'page=dashboard&tab=staff_cashbooks');
is_(strpos($pg, 'Sandbox Tech') !== false && strpos($pg, '150,000.00') !== false, 'the Staff Cashbooks landing tiles show Sandbox Tech with 150,000.00');
$pg = $s->page('tech', 'page=dashboard&tab=my_account');
is_(preg_match('/class="mc-hero-amt"[^>]*>UGX 150,000\.00</', $pg) === 1, "the technician's own My Cash hero reads UGX 150,000.00", 'len ' . strlen($pg));
is_(strpos($pg, 'USD Cash') === false && strpos($pg, 'USD Cashbook') === false && strpos($pg, 'USD balance') === false, '…and never says "USD" for the base bag');
$pg = $s->page('tech', 'page=dashboard&tab=my_account&v=expense');
is_(preg_match('/name="currency" value="UGX"[^>]*checked/', $pg) === 1 && preg_match('/name="currency" value="USD"/', $pg) === 0,
    "the technician's expense form offers UGX as the base currency, not a USD value wearing a UGX label");

echo "\nC. The technician spends from it: a field expense, approved\n";
$r = $s->form('tech', ['fe_action' => 'submit_expense', 'amount' => '40000', 'currency' => 'UGX', 'category' => 'transport',
                       'description' => 'boda to the site', 'expense_date' => $today], 'page=dashboard&tab=field_expenses', 'page=dashboard&tab=field_expenses');
is_($r[0] === 302, 'the technician submits a UGX 40,000 transport expense (302)', $r[0] . ' ' . substr($r[1], 0, 200));
$exp = $s->q("SELECT id, staff_id, amount, currency, status FROM staff_expenses ORDER BY id DESC LIMIT 1")[0] ?? [];
is_((int)($exp['staff_id'] ?? 0) === $tech && (float)($exp['amount'] ?? 0) === 40000.0 && ($exp['currency'] ?? '') === 'UGX' && ($exp['status'] ?? '') === 'pending',
    'the expense is pending, UGX 40,000, on the technician', json_encode($exp));
$r = $s->form('acct', ['exp_action' => 'approve', 'expense_id' => (string)($exp['id'] ?? 0)], 'page=dashboard&tab=expense_approvals', 'page=dashboard&tab=expense_approvals');
is_(in_array($r[0], [200, 302], true), 'the accountant approves it', $r[0] . ' ' . substr($r[1], 0, 200));
$rows = $cb($s);
$last = end($rows);
is_(($last['direction'] ?? '') === 'out' && (float)($last['amount'] ?? 0) === 40000.0 && ($last['currency'] ?? '') === 'UGX' && ($last['source'] ?? '') === 'expense_sync',
    'the main cashbook gained the expense (expense_sync, UGX 40,000)', json_encode($last));
$l = $ledger($s, $tech);
$outRows = array_values(array_filter($l, fn($x) => $x['direction'] === 'out'));
is_(count($outRows) === 1 && ($outRows[0]['currency'] ?? '') === 'UGX' && (float)($outRows[0]['amount'] ?? 0) === 40000.0,
    'the staff ledger gained one OUT row: UGX 40,000', json_encode($l));
$pg = $s->page('acct', 'page=dashboard&tab=staff_cashbooks&sc_staff=' . $tech);
is_(strpos($pg, '110,000.00') !== false, "Staff Cashbooks now reads UGX 110,000.00 in hand (150,000 − 40,000)");
$pg = $s->page('tech', 'page=dashboard&tab=my_account');
is_(strpos($pg, '110,000.00') !== false, "and so does the technician's own page");

echo "\nD. US dollars handed out on the Uganda book are their own bag\n";
$r = $wizard($s, ['currency' => 'USD', 'amount' => '100', 'person' => 'Sandbox Tech', 'description' => 'for the border']);
is_($r[0] === 302, 'a USD 100 Staff Advance is accepted (302)');
$ci = $cins($s);
$cu = end($ci);
is_((float)($cu['amount'] ?? 0) === 100.0 && ($cu['currency'] ?? '') === 'USD' && ($cu['category'] ?? '') === 'USD Received',
    'its cash-in is USD 100 in currency USD', json_encode($cu));
$l = $ledger($s, $tech);
$lu = end($l);
is_(($lu['currency'] ?? '') === 'USD' && (float)($lu['amount'] ?? 0) === 100.0 && $lu['direction'] === 'in', 'the staff ledger row is labelled USD', json_encode($lu));
$pg = $s->page('acct', 'page=dashboard&tab=staff_cashbooks&sc_staff=' . $tech);
is_(strpos($pg, '110,000.00') !== false && strpos($pg, '110,100.00') === false, 'the UGX figure is still 110,000.00 — the dollars did not leak into the shilling bag');
$t = $lastText($s);
is_(strpos((string)($t['text'] ?? ''), 'USD 100.00') !== false && strpos((string)($t['text'] ?? ''), 'UGX 100') === false,
    'the WhatsApp text names the dollars as USD 100.00, not as shillings', json_encode($t));

echo "\nE. Personal pay stays out of the field register (unchanged rule)\n";
$n0 = count($cins($s));
$r = $wizard($s, ['currency' => 'UGX', 'amount' => '20000', 'person' => 'Sandbox Tech', 'category' => 'Transport Allowance', 'category_raw' => 'Transport Allowance', 'description' => 'own transport']);
is_($r[0] === 302 && count($cins($s)) === $n0, 'a Transport Allowance to the technician creates no cash-in — it is their own money');

echo "\nF. The Cashbook page: what the ledger says is in hand, per currency\n";
$pg = $s->page('acct', 'page=dashboard&tab=cashbook');
is_(strpos($pg, 'UGX CASH IN HAND') !== false, 'the hero reads "UGX CASH IN HAND"', 'len ' . strlen($pg));
is_(strpos($pg, 'UGX 290,000.00') !== false, 'UGX 290,000.00 = 500,000 − 150,000 − 40,000 − 20,000, the running balance the Balance column reaches');
is_(strpos($pg, 'USD CASH IN HAND') !== false && strpos($pg, 'USD -100.00') !== false, 'the USD stream has its own card: USD -100.00 (the dollars handed out)');
is_(strpos($pg, 'POSITION') === false && strpos($pg, 'unassigned rows') === false && strpos($pg, 'accounts yet') === false,
    'no POSITION card, no "unassigned rows", no account count — the account model is not shown');
is_(strpos($pg, 'Opening Balances') === false, 'the Opening Balances tab is not offered on the Uganda strip');
is_(strpos($pg, 'Cashbook') !== false && strpos($pg, 'Revenue Ledger') !== false, "…while the strip's other tabs are still there (control)");

echo "\nG. South Sudan — byte for byte the 5.18.67 chain (golden through the same forms)\n";
$ss = SjSandbox::start($root, $SS, 'sjcashss');
$people($ss, 'Capture Accountant', 'Capture Tech', '+211920000001');
$ssTech = $ss->ids['tech'];
$ss->form('acct', ['action' => 'cashbook_set_rate', 'rate' => '5000'], 'page=dashboard&tab=cashbook', 'page=dashboard&tab=cashbook');
$w = function (array $f) use ($ss) {
    $base = ['cb_action' => 'add_entry', 'direction' => 'out', 'project' => 'dishnet', 'date' => '2026-10-01', 'ssp_rate' => '',
             'category' => 'Staff Advance', 'category_raw' => 'Staff Advance', 'person' => 'Capture Tech', 'description' => 'golden',
             'validation_ref' => '', 'validation_status' => 'na', 'inv_ref' => '', 'rcpt_ref' => '', 'pay_month' => '', 'exchange_type' => '', 'exch_ssp_amount' => ''];
    return $ss->form('acct', $f + $base, 'page=dashboard&tab=cashbook', 'page=dashboard&tab=cashbook');
};
$r1 = $w(['currency' => 'USD', 'amount' => '50']);
$r2 = $w(['currency' => 'SSP', 'amount' => '100000']);
is_($r1[0] === 302 && $r2[0] === 302, 'a USD 50 and an SSP 100,000 Staff Advance are accepted');
$golden_cins = [
  ['amount' => 50.0, 'approved_by' => 'auto (cashbook link)', 'category' => 'USD Received', 'cb_ref' => 'CB-1', 'collector_id' => $ssTech, 'collector_name' => 'Capture Tech',
   'currency' => 'USD', 'description' => 'From Capture Accountant — Staff Advance (golden)', 'rate' => 0.0, 'ssp_amount' => 0.0, 'status' => 'approved', 'usd_given' => 0.0],
  ['amount' => 0.0, 'approved_by' => 'auto (cashbook link)', 'category' => 'SSP Received', 'cb_ref' => 'CB-2', 'collector_id' => $ssTech, 'collector_name' => 'Capture Tech',
   'currency' => 'SSP', 'description' => 'From Capture Accountant — Staff Advance (golden)', 'rate' => 0.0, 'ssp_amount' => 100000.0, 'status' => 'approved', 'usd_given' => 0.0],
];
$norm = function (array $d) { $o = []; foreach (['amount','approved_by','category','cb_ref','collector_id','collector_name','currency','description','rate','ssp_amount','status','usd_given'] as $k) {
    $v = $d[$k] ?? null; $o[$k] = in_array($k, ['amount','rate','ssp_amount','usd_given'], true) ? (float)$v : (in_array($k, ['collector_id'], true) ? (int)$v : (string)$v); } return $o; };
$got = array_map($norm, $cins($ss));
is_($got === $golden_cins, 'cash_ins.json: the two rows are exactly the 5.18.67 golden (USD carries amount, SSP carries ssp_amount with amount 0)', json_encode($got));
$golden_ledger = [
  ['direction' => 'in', 'currency' => 'USD', 'amount' => 50.0, 'ssp_amount' => 0.0, 'category' => 'collection', 'subcategory' => 'USD Received',
   'description' => 'From Capture Accountant — Staff Advance (golden)', 'status' => 'active', 'source_type' => 'cash_ins', 'idempotency_key' => 'CIN-1'],
  ['direction' => 'in', 'currency' => 'SSP', 'amount' => 100000.0, 'ssp_amount' => 100000.0, 'category' => 'collection', 'subcategory' => 'SSP Received',
   'description' => 'From Capture Accountant — Staff Advance (golden)', 'status' => 'active', 'source_type' => 'cash_ins', 'idempotency_key' => 'CIN-2'],
];
$gl = array_map(function ($r) { foreach (['amount','ssp_amount'] as $k) $r[$k] = (float)$r[$k]; foreach (['direction','currency','category','subcategory','description','status','source_type','idempotency_key'] as $k) $r[$k] = (string)$r[$k]; return $r; }, $ledger($ss, $ssTech));
is_($gl === $golden_ledger, 'staff_ledger: the two rows are exactly the golden (USD 50 labelled USD; SSP 100,000 labelled SSP)', json_encode($gl));
$cbss = $cb($ss);
is_(count($cbss) === 2 && (float)$cbss[0]['amount'] === 50.0 && $cbss[0]['currency'] === 'USD' && (float)$cbss[1]['amount'] === 20.0 && $cbss[1]['currency'] === 'SSP'
    && (float)$cbss[1]['ssp_amount'] === 100000.0 && (float)$cbss[1]['ssp_rate'] === 5000.0,
    'cb_ledger: USD 50; SSP 100,000 @ 5,000 stored as amount 20 with ssp_amount 100,000 (golden)', json_encode($cbss));
$texts = array_map(fn($t) => preg_replace('/⏰ [^\n]+/u', '⏰ <time>', (string)($t['text'] ?? '')), $ss->texts());
$golden_texts = [
  "💰 *Cash Received*\n\nHi Capture Tech,\n\nYou have received *UGX 50.00*\n📂 Category: Staff Advance\n👤 From: Capture Accountant\n⏰ <time>\n🔖 Ref: CB-1\n\nThis has been added to your Field Register. ✅",
  "💰 *Cash Received*\n\nHi Capture Tech,\n\nYou have received *100,000 SSP*\n📂 Category: Staff Advance\n👤 From: Capture Accountant\n⏰ <time>\n🔖 Ref: CB-2\n\nThis has been added to your Field Register. ✅",
];
is_($texts === $golden_texts, 'the two WhatsApp texts are byte for byte the golden (the sandbox\'s South Sudan profile has no currency symbol set, so the base shows as the display default — as it did on 5.18.67)', json_encode($texts));
$pg = $ss->page('acct', 'page=dashboard&tab=cashbook');
is_(strpos($pg, 'USD BALANCE') !== false && strpos($pg, 'SSP BALANCE') !== false && strpos($pg, 'CASH IN HAND') === false,
    "South Sudan's Cashbook page keeps its USD BALANCE and SSP BALANCE cards and has no CASH IN HAND card");
is_(strpos($pg, 'Opening Balances') !== false, "South Sudan's accounts strip still offers Opening Balances (unchanged)");
$pg = $ss->page('acct', 'page=dashboard&tab=staff_cashbooks&sc_staff=' . $ssTech);
is_(strpos($pg, 'USD Cashbook') !== false && strpos($pg, 'SSP Cashbook') !== false && strpos($pg, '50.00') !== false,
    "South Sudan's Staff Cashbooks page shows the USD and SSP tabs and the USD 50 (unchanged)");
[$rc, $out] = $ss->run($ss->plug . '/tools/backfill_staff_cash_ins.php');
is_($rc === 2 && strpos($out, 'REFUSED') !== false && strpos($out, 'South Sudan') !== false, 'the backfill tool refuses to run on the South Sudan book (exit 2)', $rc . ' ' . substr($out, 0, 300));
$ss->stop();

echo "\nH. The backfill tool completes the chain for advances given before 5.18.68\n";
// Simulate 5.18.67's output for the UGX 150,000 advance: its cash-in carries amount 0 and no ledger row followed.
file_put_contents($s->plug . '/__plant.php', '<?php
$root = __DIR__; require_once $root . "/lib/StoreInterface.php"; require_once $root . "/lib/JsonStore.php"; require_once $root . "/lib/SqliteStore.php";
$store = SqliteStore::create((string)getenv("DN_DATA_DIR")); [$mode, $sr] = [$argv[1], $argv[2]]; $id = 0;
$ci = $store->load("cash_ins.json") ?: []; $keep = [];
foreach ($ci as $c) { if (($c["cb_ref"] ?? "") === $sr) { $id = (int)($c["id"] ?? 0); if ($mode === "remove") continue; $c["amount"] = 0; } $keep[] = $c; }
$store->save("cash_ins.json", array_values($keep));
if ($id) $store->getPdo()->exec("DELETE FROM staff_ledger WHERE idempotency_key = \'CIN-" . $id . "\'");
echo "planted $mode $sr (cash-in #$id)";
');
$rows = $cb($s);
$srAdv = ''; foreach ($rows as $r) { if ($r['category'] === 'Staff Advance' && $r['currency'] === 'UGX' && (float)$r['amount'] === 150000.0) $srAdv = $r['sr']; }
[$rc, $out] = $s->run($s->plug . '/__plant.php', ['zero', $srAdv]);
is_($rc === 0 && strpos($out, 'planted zero') !== false, "planted: the cash-in for $srAdv zeroed and its ledger row removed (5.18.67's state)", $out);
$ci = $cins($s); $ghost = null; foreach ($ci as $c) if (($c['cb_ref'] ?? '') === $srAdv) $ghost = $c;
is_($ghost !== null && (float)$ghost['amount'] === 0.0 && count(array_filter($ledger($s, $tech), fn($x) => $x['idempotency_key'] === 'CIN-' . $ghost['_id'])) === 0, 'control: the ghost is in place (amount 0, no CIN row)');
// an advance to a person who is not yet a staff member: the live link finds nobody; later the person is added
$r = $wizard($s, ['currency' => 'UGX', 'amount' => '30000', 'person' => 'Late Hire', 'description' => 'airtime float']);
$rows = $cb($s); $srLate = end($rows)['sr'];
is_($r[0] === 302 && count($cins($s)) === count($ci), "an advance to 'Late Hire' (no such staff yet) creates no cash-in");
$s->staff('late', ['name' => 'Late Hire', 'email' => 'late@example.test', 'role' => 'support', 'phone' => '+256700000012']);
// an advance whose name fits two staff members, with its cash-in removed: the tool must not guess
$r = $wizard($s, ['currency' => 'UGX', 'amount' => '5000', 'person' => 'Sandbox', 'description' => 'ambiguous']);
$rows = $cb($s); $srAmb = end($rows)['sr'];
[$rc, $out] = $s->run($s->plug . '/__plant.php', ['remove', $srAmb]);
is_($rc === 0, 'planted: the ambiguous advance has no cash-in', $out);
$before = [json_encode($cins($s)), count($s->q("SELECT id FROM staff_ledger"))];
$textsBeforeI = count($s->texts());
[$rc, $out] = $s->run($s->plug . '/tools/backfill_staff_cash_ins.php');
is_($rc === 0 && strpos($out, 'DRY RUN — nothing was changed') !== false, 'dry run exits 0 and says nothing was changed', $rc . ' ' . substr($out, 0, 400));
is_(preg_match('/' . preg_quote($srAdv, '/') . '\s.*FIX/', $out) === 1, "…plans to FIX $srAdv (amount 0)", $out);
is_(preg_match('/' . preg_quote($srLate, '/') . '\s.*CREATE/', $out) === 1, "…plans to CREATE the cash-in for $srLate (Late Hire now exists)", $out);
is_(preg_match('/' . preg_quote($srAmb, '/') . '\s.*SKIP — ambiguous/', $out) === 1, "…SKIPs $srAmb as ambiguous — two names fit, nobody is guessed", $out);
is_(strpos($out, 'USD 100.00') === false, '…and the USD advance is not on the list at all (another bag)', $out);
is_([json_encode($cins($s)), count($s->q("SELECT id FROM staff_ledger"))] === $before, 'the dry run wrote nothing: cash-ins and ledger identical');
[$rc, $out] = $s->run($s->plug . '/tools/backfill_staff_cash_ins.php', ['--apply', '--yes']);
is_($rc === 0 && strpos($out, 'written:') !== false, 'apply exits 0', $rc . ' ' . substr($out, 0, 500));
$ci = $cins($s); $fixed = null; $made = null; foreach ($ci as $c) { if (($c['cb_ref'] ?? '') === $srAdv) $fixed = $c; if (($c['cb_ref'] ?? '') === $srLate) $made = $c; }
is_($fixed !== null && (float)$fixed['amount'] === 150000.0 && $fixed['currency'] === 'UGX' && ($fixed['backfilled_by'] ?? '') === 'backfill 5.18.68', "the ghost cash-in for $srAdv carries UGX 150,000 again", json_encode($fixed));
$l = $ledger($s, $tech);
is_(count(array_filter($l, fn($x) => $x['idempotency_key'] === 'CIN-' . $fixed['_id'] && $x['currency'] === 'UGX' && (float)$x['amount'] === 150000.0)) === 1, 'its ledger row is back: UGX 150,000, CIN-<id>', json_encode($l));
$late = $s->ids['late'];
is_($made !== null && (float)$made['amount'] === 30000.0 && (int)$made['collector_id'] === $late && ($made['approved_by'] ?? '') === 'backfill 5.18.68' && substr((string)$made['created_at'], 0, 10) === $today,
    "a cash-in was created for Late Hire: UGX 30,000, dated the day the money went out", json_encode($made));
$ll = $ledger($s, $late);
is_(count($ll) === 1 && $ll[0]['currency'] === 'UGX' && (float)$ll[0]['amount'] === 30000.0 && $ll[0]['direction'] === 'in', "Late Hire's ledger has the one IN row", json_encode($ll));
is_(count(array_filter($cins($s), fn($c) => ($c['cb_ref'] ?? '') === $srAmb)) === 0, 'the ambiguous advance still has no cash-in — the tool guessed nobody');
is_(strpos($out, 'Late Hire (#' . $late . '): UGX 30,000.00') !== false && strpos($out, 'Sandbox Tech (#' . $tech . '): UGX 110,000.00') !== false, 'the apply summary states each staff member\'s UGX in hand', $out);
$pg = $s->page('acct', 'page=dashboard&tab=staff_cashbooks&sc_staff=' . $late);
is_(strpos($pg, '30,000.00') !== false, "Staff Cashbooks for Late Hire now reads UGX 30,000.00");
[$rc, $out] = $s->run($s->plug . '/tools/backfill_staff_cash_ins.php');
is_($rc === 0 && strpos($out, '0 to create, 0 to fix') !== false && substr_count($out, 'already linked') >= 2, 'run again, everything reads SKIP (already linked) — idempotent', substr($out, 0, 600));
$al = $s->q("SELECT data FROM activity_log ORDER BY id DESC LIMIT 1")[0]['data'] ?? '';
is_(strpos($al, 'cashbook_backfill') !== false, 'the backfill is in the activity log');
is_(count($s->texts()) === $textsBeforeI, 'the tool sent no WhatsApp message');

echo "\nI. Weakened copies are caught\n";
$mutants = [
  ['the wizard auto-link writes the amount only for literal USD again (the 5.18.67 defect)', 'includes/post/post_cashbook.php',
   "\n                        'amount'        => \$cbCurrency !== 'SSP' ? \$cbAmount : 0,", "\n                        'amount'        => \$cbCurrency === 'USD' ? \$cbAmount : 0,",
   function (SjSandbox $m, int $techId) use ($cins): bool { $c = $cins($m); return (float)($c[0]['amount'] ?? -1) === 150000.0; }],
  ['onCashIn labels the base-bag row USD again', 'lib/StaffLedgerWriter.php',
   "                \$currency  = strtoupper(trim((string)(\$ci['currency'] ?? '')));\n                if (\$currency === '' || \$currency === 'SSP') \$currency = 'USD';", "                \$currency  = 'USD';",
   function (SjSandbox $m, int $techId) use ($ledger): bool { $l = $ledger($m, $techId); return ($l[0]['currency'] ?? '') === 'UGX'; }],
  ['the Staff Cashbooks page filters the base tab on literal USD again', 'tabs/accounts/staff_cashbooks.php',
   "\$uL=array_values(array_filter(\$sc_ledger,fn(\$r)=>\$r['cur']===\$scBaseCode));", "\$uL=array_values(array_filter(\$sc_ledger,fn(\$r)=>\$r['cur']==='USD'));",
   function (SjSandbox $m, int $techId): bool { $pg = $m->page('acct', 'page=dashboard&tab=staff_cashbooks&sc_staff=' . $techId); return strpos($pg, 'for materials') !== false; }],
  ['the position service compares against literal USD again — the landing tiles read nothing for a UGX holder', 'lib/StaffCashPositionService.php',
   "        \$this->base          = function_exists('dn_book_base') ? dn_book_base(null) : 'USD';", "        \$this->base          = 'USD';",
   function (SjSandbox $m, int $techId): bool { $pg = $m->page('acct', 'page=dashboard&tab=staff_cashbooks'); return strpos($pg, '150,000.00') !== false; }],
];
foreach ($mutants as $i => [$label, $rel, $old, $new, $check]) {
    [$tmp, $n] = sj_weakened_copy($root, $rel, $old, $new);
    is_($n === 1, "mutant " . ($i + 1) . ": the anchor is unique in $rel", "count $n");
    if ($n === 1) {
        $m = SjSandbox::start($tmp, $UG, 'sjcashm' . $i);
        $people($m, 'Sandbox Accountant', 'Sandbox Tech', '+256700000011');
        $mt = $m->ids['tech'];
        $mw = function (array $f) use ($m, $today) {
            $base = ['cb_action' => 'add_entry', 'direction' => 'out', 'project' => 'dishnet', 'date' => $today, 'ssp_rate' => '', 'category' => 'Staff Advance',
                     'category_raw' => 'Staff Advance', 'person' => 'Sandbox Tech', 'description' => 'for materials', 'validation_ref' => '', 'validation_status' => 'na',
                     'inv_ref' => '', 'rcpt_ref' => '', 'pay_month' => '', 'exchange_type' => '', 'exch_ssp_amount' => ''];
            return $m->form('acct', $f + $base, 'page=dashboard&tab=cashbook', 'page=dashboard&tab=cashbook');
        };
        $mw(['currency' => 'UGX', 'amount' => '150000']);
        is_($check($m, $mt) === false, "mutant " . ($i + 1) . " is caught: $label");
        $m->stop();
    }
    exec('rm -rf ' . escapeshellarg($tmp));
}
$s->stop();

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
