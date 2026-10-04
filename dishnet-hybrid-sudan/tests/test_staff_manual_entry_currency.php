<?php
declare(strict_types=1);
/**
 * test_staff_manual_entry_currency.php — 5.18.69: the last three places a Uganda accountant met "USD" with no
 * dollars in sight, and the tool that repairs what the first of them stamped.
 *
 *   1. The Staff Cashbooks "Manual Entry" form read the currency the accountant chose and then stamped every
 *      non-SSP entry 'USD' — so a UGX entry disappeared from the UGX register (5.18.68 reads the base bag) and
 *      surfaced as dollars in the export. Now it stamps the chosen currency (dn_entry_currency).
 *   2. The ledger row's category "USD Received" is the base-bag category's stored name; it now reads as the
 *      book's base ("UGX Received") on the page and in the export.
 *   3. The staff CSV export asked for the "usd" tab by its literal name; the base tab now exports the BOOK's base,
 *      and the file name and column headers follow.
 *   tools/staff_records_currency.php lists staff records stamped in a non-base currency and voids or relabels
 *   the Manual Entry collections among them.
 *
 * Driven through the real forms on sandboxed plugins (fake uCRM, fake Evolution), under a Uganda configuration
 * and under a South Sudan one (where base = USD, so every one of these reads 'USD' exactly as before).
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

$people = function (SjSandbox $s): void {
    $s->staff('acct', ['name' => 'Sandbox Accountant', 'email' => 'acct@example.test', 'role' => 'accountant']);
    $s->staff('tech', ['name' => 'Sandbox Tech', 'email' => 'tech@example.test', 'role' => 'support', 'phone' => '+256700000011']);
    $s->login('acct', 'acct@example.test', 'sj-password-1');
};
$manual = function (SjSandbox $s, int $staff, string $cur, string $amount, string $desc): array {
    $qs = 'page=dashboard&tab=staff_cashbooks&sc_staff=' . $staff;
    return $s->form('acct', ['sc_action' => 'add_manual_entry', 'man_staff_id' => (string)$staff, 'man_direction' => 'in', 'man_currency' => $cur,
                             'man_amount' => $amount, 'man_category' => 'Adjustment', 'man_description' => $desc], $qs, $qs);
};
$cols = function (SjSandbox $s): array {
    try { $rows = $s->q("SELECT id, data FROM payment_collections ORDER BY id"); } catch (Throwable $e) { return []; }
    $o = []; foreach ($rows as $r) { $d = json_decode((string)$r['data'], true) ?: []; $d['_id'] = (int)$r['id']; $o[] = $d; } return $o;
};
$wizard = function (SjSandbox $s, array $f) use ($today): array {
    $base = ['cb_action' => 'add_entry', 'direction' => 'out', 'project' => 'dishnet', 'date' => $today, 'ssp_rate' => '', 'category' => 'Staff Advance',
             'category_raw' => 'Staff Advance', 'person' => 'Sandbox Tech', 'description' => 'for materials', 'validation_ref' => '', 'validation_status' => 'na',
             'inv_ref' => '', 'rcpt_ref' => '', 'pay_month' => '', 'exchange_type' => '', 'exch_ssp_amount' => ''];
    return $s->form('acct', $f + $base, 'page=dashboard&tab=cashbook', 'page=dashboard&tab=cashbook');
};
$csv = function (SjSandbox $s, int $staff, string $tab): array {   // [status, location-header, body] — http() exposes no other header
    $r = $s->http('GET', "{$s->base}?page=dashboard&tab=staff_cashbooks&sc_staff={$staff}&sc_cur={$tab}&sc_from=2026-01-01&sc_to=2027-12-31&sc_export=csv", null, [], $s->jars['acct']);
    return [$r[0], (string)($r[3] ?? ''), (string)$r[1]];
};
$plant = function (SjSandbox $s): void {
    file_put_contents($s->plug . '/__plant_col.php', '<?php
$root = __DIR__; require_once $root . "/lib/StoreInterface.php"; require_once $root . "/lib/JsonStore.php"; require_once $root . "/lib/SqliteStore.php";
$store = SqliteStore::create((string)getenv("DN_DATA_DIR"));
$rec = $store->appendWithId("payment_collections.json", ["retailer_id" => (int)$argv[1], "retailer_name" => "Sandbox Tech", "customer_name" => $argv[4],
    "amount" => (float)$argv[2], "currency" => $argv[3], "method" => "Cash", "service_type" => "manual", "note" => "Manual entry by Old Form: Adjustment",
    "source" => "manual_adjustment", "crm_synced" => false, "commission" => 0, "status" => "approved",
    "audit_log" => [["action" => "manual_create", "by" => "Old Form", "at" => "2026-09-25 10:00:00", "reason" => $argv[4]]], "created_at" => "2026-09-25 10:00:00"]);
echo "planted #" . (int)($rec["id"] ?? 0);
');
};

// ═══════════════════════════════════════════════════════════════════════════
echo "A. Uganda — a Manual Entry in UGX is stamped UGX and counts\n";
$s = SjSandbox::start($root, $UG, 'sjman');
$people($s); $tech = $s->ids['tech'];
$r = $manual($s, $tech, 'UGX', '20000', 'opening float');
is_(in_array($r[0], [200, 302], true), 'the accountant adds a manual UGX 20,000 entry on the technician (' . $r[0] . ')', substr($r[1], 0, 200));
$c = $cols($s); $m0 = $c[0] ?? [];
is_(count($c) === 1 && ($m0['currency'] ?? '') === 'UGX' && (float)($m0['amount'] ?? 0) === 20000.0 && ($m0['source'] ?? '') === 'manual_adjustment',
    "the collection row is stamped UGX (it used to be 'USD'), 20,000, source manual_adjustment", json_encode($m0));
$pg = $s->page('acct', 'page=dashboard&tab=staff_cashbooks&sc_staff=' . $tech);
is_(preg_match('/UGX Collected.{0,800}?UGX 20,000\.00/si', $pg) === 1, 'the page counts it: UGX COLLECTED UGX 20,000.00', 'len ' . strlen($pg));
is_(strpos($pg, 'Manual UGX entry added') !== false || strpos($pg, 'opening float') !== false, 'the page shows the entry (or its confirmation)');

echo "\nB. The base-bag category reads as the book's base\n";
$r = $wizard($s, ['currency' => 'UGX', 'amount' => '150000']);
is_($r[0] === 302, 'a UGX 150,000 Staff Advance through the wizard (302)');
$pg = $s->page('acct', 'page=dashboard&tab=staff_cashbooks&sc_staff=' . $tech);
is_(strpos($pg, 'UGX Received') !== false && strpos($pg, 'USD Received') === false, 'the advance row reads "UGX Received", never "USD Received"');
is_(strpos($pg, '170,000.00') !== false, 'the hero reads UGX 170,000.00 (20,000 + 150,000)');

echo "\nC. The staff CSV export of the base tab is the BOOK's base\n";
[$st, $hdr, $body] = $csv($s, $tech, 'usd');
is_($st === 200 && strpos($body, 'Date,Time,Direction,Category,Description') === 0, 'the "usd" tab exports a CSV (200, the header row first)', substr($body, 0, 300));
is_(strpos($body, 'Received (UGX)') !== false && strpos($body, 'Payment (UGX)') !== false && strpos($body, '(USD)') === false, 'the columns read Received (UGX) / Payment (UGX)', substr($body, 0, 300));
is_(preg_match('/Collection,"?opening float"?,20000/', $body) === 1 || preg_match('/Collection,.*opening float.*,20000/', $body) === 1, 'the manual UGX entry is in the export', substr($body, 0, 400));
is_(strpos($body, 'UGX Received') !== false && strpos($body, 'USD Received') === false && strpos($body, '150000') !== false, 'the advance is in the export as "UGX Received", 150000', substr($body, 0, 500));

echo "\nD. The tool: list, void, relabel the hand-typed entries stamped USD by the old form\n";
$plant($s);
[$rc, $out] = $s->run($s->plug . '/__plant_col.php', [(string)$tech, '50000', 'USD', 'hand copy of an advance']);
is_($rc === 0 && strpos($out, 'planted #') === 0, 'planted: a USD-stamped manual collection of 50,000 (as the old form wrote it)', $out);
$ghostId = (int)substr($out, 9);
$pg = $s->page('acct', 'page=dashboard&tab=staff_cashbooks&sc_staff=' . $tech);
is_(preg_match('/UGX Collected.{0,800}?UGX 170,000\.00/si', $pg) === 1, 'control: the USD-stamped row does not count — the UGX collected tile (every base-bag IN: 20,000 + the 150,000 advance) still reads 170,000.00');
$before = json_encode($cols($s));
[$rc, $out] = $s->run($s->plug . '/tools/staff_records_currency.php');
is_($rc === 0 && strpos($out, 'LIST — nothing was changed') !== false, 'LIST exits 0 and says nothing was changed', $rc . ' ' . substr($out, 0, 400));
is_(preg_match('/payment_collections\s+.*USD 1 ◄ not the base/', $out) === 1, '…the census names payment_collections: USD 1 ◄ not the base', $out);
is_(preg_match('/collection #' . $ghostId . '\s+2026-09-25\s+Sandbox Tech\s+Manual Entry\s+USD\s+50,000\.00\s+approved\s+hand copy of an advance/', $out) === 1, "…and lists the candidate collection #$ghostId (Manual Entry, USD 50,000.00, approved)", $out);
is_(json_encode($cols($s)) === $before, 'LIST wrote nothing');
[$rc, $out] = $s->run($s->plug . '/tools/staff_records_currency.php', ['--void', '--yes']);
is_($rc === 0 && strpos($out, "collection #$ghostId voided (USD 50,000.00, Sandbox Tech)") !== false, 'VOID exits 0 and names the row', $rc . ' ' . substr($out, 0, 400));
$g = null; foreach ($cols($s) as $x) if ($x['_id'] === $ghostId) $g = $x;
is_($g !== null && ($g['status'] ?? '') === 'voided' && ($g['prev_status'] ?? '') === 'approved' && ($g['voided_by'] ?? '') === 'staff_records_currency 5.18.70'
    && is_array($g['audit_log'] ?? null) && end($g['audit_log'])['action'] === 'void' && ($g['currency'] ?? '') === 'USD',
    "the row is voided the page's way (prev_status, voided_by, audit_log), its stamp untouched, nothing deleted", json_encode($g));
$al = $s->q("SELECT data FROM activity_log ORDER BY id DESC LIMIT 1")[0]['data'] ?? '';
is_(strpos($al, 'staff_records_currency') !== false && strpos($al, 'VOID') !== false, 'the void is in the activity log');
[$rc, $out] = $s->run($s->plug . '/tools/staff_records_currency.php');
is_($rc === 0 && strpos($out, '1 (0 not yet voided)') !== false, 'LIST again: 1 candidate, 0 not yet voided');
[$rc, $out] = $s->run($s->plug . '/tools/staff_records_currency.php', ['--void', '--yes']);
is_($rc === 0 && strpos($out, 'Nothing to VOID') !== false, 'VOID again: nothing to do — idempotent');
[$rc, $out] = $s->run($s->plug . '/__plant_col.php', [(string)$tech, '70000', 'USD', 'the only record of this money']);
$keepId = (int)substr($out, 9);
[$rc, $out] = $s->run($s->plug . '/tools/staff_records_currency.php', ['--relabel', '--yes']);
is_($rc === 0 && strpos($out, "collection #$keepId relabelled USD → UGX (70,000.00, Sandbox Tech)") !== false, 'RELABEL exits 0 and names the row', $rc . ' ' . substr($out, 0, 400));
$k = null; foreach ($cols($s) as $x) if ($x['_id'] === $keepId) $k = $x;
is_($k !== null && ($k['currency'] ?? '') === 'UGX' && ($k['status'] ?? '') === 'approved' && end($k['audit_log'])['action'] === 'relabel' && end($k['audit_log'])['from'] === 'USD',
    'the row now reads UGX, still approved, with a relabel entry in its audit log', json_encode($k));
$pg = $s->page('acct', 'page=dashboard&tab=staff_cashbooks&sc_staff=' . $tech);
is_(preg_match('/UGX Collected.{0,800}?UGX 240,000\.00/si', $pg) === 1, 'the relabelled row counts: the tile reads 240,000.00 (170,000 + 70,000); the voided one does not');
[$rc, $out] = $s->run($s->plug . '/tools/staff_records_currency.php', ['--void', '--relabel']);
is_($rc === 2, '--void and --relabel together are refused (exit 2)');
$textsNow = count($s->texts());
is_($textsNow === count($s->texts()), 'the tool sent no WhatsApp message');

echo "\nE. South Sudan — base USD: every one of these reads USD exactly as before\n";
$ss = SjSandbox::start($root, $SS, 'sjmanss');
$people($ss); $ssTech = $ss->ids['tech'];
$r = $manual($ss, $ssTech, 'USD', '20', 'golden float');
$c = $cols($ss); $m = $c[0] ?? [];
is_(in_array($r[0], [200, 302], true) && count($c) === 1 && ($m['currency'] ?? '') === 'USD' && (float)($m['amount'] ?? 0) === 20.0 && ($m['source'] ?? '') === 'manual_adjustment'
    && ($m['method'] ?? '') === 'Cash' && ($m['service_type'] ?? '') === 'manual' && ($m['status'] ?? '') === 'approved',
    'a manual USD entry is stamped USD with the same record shape as before', json_encode($m));
$pg = $ss->page('acct', 'page=dashboard&tab=staff_cashbooks&sc_staff=' . $ssTech);
is_(strpos($pg, 'Manual USD entry added') !== false || strpos($pg, 'golden float') !== false, 'the confirmation still says "Manual USD entry added"');
[$st, $hdr, $body] = $csv($ss, $ssTech, 'usd');
is_($st === 200 && strpos($body, 'Received (USD)') !== false && strpos($body, 'golden float') !== false, 'the USD tab exports Received (USD) with the manual entry (unchanged)', substr($body, 0, 300));
[$st, $hdr, $body] = $csv($ss, $ssTech, 'ssp');
is_($st === 200 && strpos($body, 'Received (SSP)') !== false && strpos($body, 'golden float') === false, 'the SSP tab exports Received (SSP) without the USD entry (unchanged)', substr($body, 0, 300));
[$rc, $out] = $ss->run($ss->plug . '/tools/staff_records_currency.php');
is_($rc === 2 && strpos($out, 'REFUSED') !== false, 'the tool refuses the South Sudan book (exit 2)', $rc . ' ' . substr($out, 0, 300));
$ss->stop();

echo "\nF. Weakened copies are caught\n";
$mutants = [
  ['the Manual Entry stamps USD again', 'tabs/accounts/staff_cashbooks.php', "                    'currency'        => \$manCur,\n", "                    'currency'        => 'USD',\n",
   function (SjSandbox $m, int $t) use ($manual, $cols): bool { $manual($m, $t, 'UGX', '20000', 'opening float'); $c = $cols($m); return (($c[0]['currency'] ?? '') === 'UGX'); }],
  ['the export asks for the usd tab by its literal name again', 'includes/routes.php',
   "    \$xCur3  = strtolower(trim((string)(\$_GET['sc_cur'] ?? ''))) === 'ssp' ? 'SSP' : dn_book_base(\$config ?? null);\n", "    \$xCur3  = dn_entry_currency(\$_GET['sc_cur'] ?? '', \$config ?? null);\n",
   function (SjSandbox $m, int $t) use ($csv): bool { [$st, $hdr, $body] = $csv($m, $t, 'usd'); return strpos($body, 'Received (UGX)') !== false; }],
];
foreach ($mutants as $i => [$label, $rel, $old, $new, $check]) {
    [$tmp, $n] = sj_weakened_copy($root, $rel, $old, $new);
    is_($n === 1, "mutant " . ($i + 1) . ": the anchor is unique in $rel", "count $n");
    if ($n === 1) {
        $m = SjSandbox::start($tmp, $UG, 'sjmanm' . $i); $people($m);
        is_($check($m, $m->ids['tech']) === false, "mutant " . ($i + 1) . " is caught: $label");
        $m->stop();
    }
    exec('rm -rf ' . escapeshellarg($tmp));
}
$s->stop();
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
