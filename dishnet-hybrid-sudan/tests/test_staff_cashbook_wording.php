<?php
declare(strict_types=1);
/**
 * test_staff_cashbook_wording.php — 5.18.72: on a book without SSP (Uganda) the Staff Cashbooks tiles say what the money is.
 *
 * The operator, 4 Oct 2026, on the technician's Staff Cashbooks page (UGX 339,672.00 held: 650,000 received, 310,328 out):
 * "fix the collected and handover wording for uganda". The tiles read "UGX collected" and "Needs handover" — South Sudan's
 * collections wording, where field staff collect payments and hand them over to accounts. On Uganda the staff member's
 * inflow is advances (and any collections) to be spent on approved expenses and accounted for. Now, on a book without SSP:
 *   · the first tile reads "<base> received" with "Advances & collections" beneath it — the figure is unchanged (every
 *     base-bag IN row, as before);
 *   · the "Cash with staff" tile's line reads "Still to account for" while a balance is held, "All settled ✓" otherwise;
 *   · "Handed over · Given to accounts", the hero, its pills and every figure are unchanged.
 * South Sudan's page is the same bytes as before: "USD collected" / "SSP collected", "Needs handover" — asserted on the
 * exact markup, whitespace included, so a stray indent in the PHP branch would fail here.
 *
 * Driven through the real pages on sandboxed plugins: the Staff Advance goes in through the real Add Entry wizard, so the
 * balance reaches the page through the 5.18.68 chain, exactly as the technician's did.
 */
$root = dirname(__DIR__);
require_once $root . '/tests/fixtures/staff_jobs_sandbox.php';
$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d !== '' ? "\n       " . substr($d, 0, 700) : '') . "\n"; } }
$UG = ['tenant_profile' => 'uganda', 'timezone' => 'Africa/Kampala', 'currency_symbol' => 'UGX', 'currency_code' => 'UGX',
       'cashbook_base_currency' => 'UGX', 'cashbook_currencies' => 'UGX,USD'];
$SS = ['tenant_profile' => 'south-sudan'];
$today = date('Y-m-d');

$people = function (SjSandbox $s): void {
    $s->staff('acct',  ['name' => 'Sandbox Accountant', 'email' => 'acct@example.test',  'role' => 'accountant']);
    $s->staff('tech',  ['name' => 'Sandbox Tech',       'email' => 'tech@example.test',  'role' => 'support', 'phone' => '+256700000011']);
    $s->staff('tech2', ['name' => 'Sandbox Other',      'email' => 'other@example.test', 'role' => 'support', 'phone' => '+256700000012']);
    $s->login('acct', 'acct@example.test', 'sj-password-1');
};
$wizard = function (SjSandbox $s, array $f) use ($today): array {
    $base = ['cb_action' => 'add_entry', 'direction' => 'out', 'project' => 'dishnet', 'date' => $today, 'ssp_rate' => '', 'category' => 'Staff Advance',
             'category_raw' => 'Staff Advance', 'person' => 'Sandbox Tech', 'description' => 'for materials', 'validation_ref' => '', 'validation_status' => 'na',
             'inv_ref' => '', 'rcpt_ref' => '', 'pay_month' => '', 'exchange_type' => '', 'exch_ssp_amount' => ''];
    return $s->form('acct', $f + $base, 'page=dashboard&tab=cashbook', 'page=dashboard&tab=cashbook');
};
$staffPage = function (SjSandbox $s, int $id, string $cur = ''): string {
    return $s->page('acct', 'page=dashboard&tab=staff_cashbooks&sc_staff=' . $id . ($cur !== '' ? '&sc_cur=' . $cur : ''));
};
// the first tile, byte for byte (4-space indent, its own line, the closing </div> of the tile)
$tileUG = function (string $amount): string {
    return "\n    <div class=\"cb3-stat-lbl\">UGX received</div>\n    <div class=\"cb3-stat-val g\">UGX {$amount}</div>\n    <div class=\"cb3-stat-sub\">Advances &amp; collections</div>\n  </div>\n";
};

// ═══════════════════════════════════════════════════════════════════════════
echo "A. Uganda — a technician holding an advance: received · Advances & collections; Still to account for\n";
$s = SjSandbox::start($root, $UG, 'sjwrd');
$people($s); $tech = $s->ids['tech']; $tech2 = $s->ids['tech2'];
$r = $wizard($s, ['currency' => 'UGX', 'amount' => '150000']);
is_($r[0] === 302, 'a UGX 150,000 Staff Advance to the technician through the wizard (302)', $r[0] . ' ' . substr((string)$r[1], 0, 200));
$pg = $staffPage($s, $tech);
is_(strpos($pg, $tileUG('150,000.00')) !== false, 'the first tile reads "UGX received · UGX 150,000.00 · Advances & collections", byte for byte', 'len ' . strlen($pg));
is_(stripos($pg, 'collected</div>') === false, 'no tile says "collected" on Uganda');
is_(substr_count($pg, 'Still to account for') === 1 && strpos($pg, 'Needs handover') === false, 'the Cash with staff line reads "Still to account for" — once — and "Needs handover" nowhere');
is_(preg_match('/💰 Cash with staff<\/div>\s*<div class="cb3-stat-val" style="color:#dc2626;">UGX 150,000\.00<\/div>\s*<div class="cb3-stat-sub">Still to account for<\/div>/u', $pg) === 1,
    'the Cash with staff tile: UGX 150,000.00 in red, "Still to account for" beneath it');
is_(strpos($pg, '<div class="cb3-stat-lbl">Handed over</div>') !== false && strpos($pg, '<div class="cb3-stat-sub">Given to accounts</div>') !== false, '"Handed over · Given to accounts" is unchanged');
is_(strpos($pg, '⚠ Cash still with staff') !== false, 'the hero line "⚠ Cash still with staff" is unchanged');
is_(preg_match('/▲ UGX 150,000\.00 received<\/span>/u', $pg) === 1 && preg_match('/▼ UGX 0\.00 out<\/span>/u', $pg) === 1, 'the hero pills "▲ UGX 150,000.00 received" / "▼ UGX 0.00 out" are unchanged');
is_(strpos($pg, '<div class="cb3-stat-lbl">💳 Wallet balance</div>') !== false && strpos($pg, '<div class="cb3-stat-sub">Prepaid credit</div>') !== false, 'the Wallet tile is unchanged');
$pg2 = $staffPage($s, $tech2);
is_(strpos($pg2, $tileUG('0.00')) !== false, 'a staff member holding nothing: the first tile reads "UGX received · UGX 0.00 · Advances & collections"');
is_(strpos($pg2, '<div class="cb3-stat-sub">All settled ✓</div>') !== false && strpos($pg2, 'Still to account for') === false && strpos($pg2, 'Cash settled') !== false,
    '…"All settled ✓" and "Cash settled", and no "Still to account for"');

echo "\nB. South Sudan — base USD: the page is the 5.18.71 one, byte for byte where this release touched the file\n";
$m = SjSandbox::start($root, $SS, 'sjwrds');
$people($m); $techS = $m->ids['tech'];
$m->form('acct', ['action' => 'cashbook_set_rate', 'rate' => '5000'], 'page=dashboard&tab=cashbook', 'page=dashboard&tab=cashbook');
$r = $wizard($m, ['currency' => 'USD', 'amount' => '50']);
is_($r[0] === 302, 'a USD 50 Staff Advance to the technician through the wizard (302)', $r[0] . ' ' . substr((string)$r[1], 0, 200));
$pg = $staffPage($m, $techS);
is_(preg_match('/\n    <div class="cb3-stat-lbl">USD collected<\/div>\n    <div class="cb3-stat-val g">[^<]*50\.00<\/div>\n  <\/div>\n/', $pg) === 1,
    'the first tile reads "USD collected", the figure, no sub-line — the same bytes and indentation as before', 'len ' . strlen($pg));
is_(preg_match('/💰 Cash with staff<\/div>\s*<div class="cb3-stat-val" style="color:#dc2626;">[^<]*50\.00<\/div>\s*<div class="cb3-stat-sub">Needs handover<\/div>/u', $pg) === 1,
    'the Cash with staff tile still reads "Needs handover"');
is_(strpos($pg, '<div class="cb3-stat-lbl">USD received</div>') === false && strpos($pg, 'Still to account for') === false && strpos($pg, 'Advances &amp; collections') === false,
    'none of the Uganda wording appears');
is_(strpos($pg, '<div class="cb3-stat-sub">Given to accounts</div>') !== false && strpos($pg, '⚠ Cash still with staff') !== false, '"Given to accounts" and the hero line are there (unchanged)');
$pgS = $staffPage($m, $techS, 'ssp');
is_(strpos($pgS, '<div class="cb3-stat-lbl">SSP collected</div>') !== false && strpos($pgS, 'Still to account for') === false, 'the SSP tab reads "SSP collected" (unchanged)');
$m->stop();

echo "\nC. Weakened copies are caught\n";
$mutants = [
  ['the first tile says "collected" again on Uganda', $UG, 'tabs/accounts/staff_cashbooks.php',
   '<div class="cb3-stat-lbl"><?=$scBaseCode?> received</div>', '<div class="cb3-stat-lbl"><?=$scBaseCode?> collected</div>',
   function (SjSandbox $mm) use ($wizard, $staffPage): bool { $wizard($mm, ['currency' => 'UGX', 'amount' => '150000']); $pg = $staffPage($mm, $mm->ids['tech']);
       return strpos($pg, '<div class="cb3-stat-lbl">UGX received</div>') !== false && stripos($pg, 'collected</div>') === false; }],
  ['the Cash with staff line says "Needs handover" again on Uganda', $UG, 'tabs/accounts/staff_cashbooks.php',
   "(\$scSSP?'Needs handover':'Still to account for')", "'Needs handover'",
   function (SjSandbox $mm) use ($wizard, $staffPage): bool { $wizard($mm, ['currency' => 'UGX', 'amount' => '150000']); $pg = $staffPage($mm, $mm->ids['tech']);
       return strpos($pg, 'Still to account for') !== false && strpos($pg, 'Needs handover') === false; }],
  ['the South Sudan branch is lost — its tile would read "received"', $SS, 'tabs/accounts/staff_cashbooks.php',
   '<?php if ($scSSP): ?>', '<?php if (false): ?>',
   function (SjSandbox $mm) use ($wizard, $staffPage): bool { $wizard($mm, ['currency' => 'USD', 'amount' => '50']); $pg = $staffPage($mm, $mm->ids['tech']);
       return strpos($pg, '<div class="cb3-stat-lbl">USD collected</div>') !== false && strpos($pg, 'Needs handover') !== false; }],
];
foreach ($mutants as $i => [$label, $cfg, $rel, $old, $new, $check]) {
    [$tmp, $n] = sj_weakened_copy($root, $rel, $old, $new);
    is_($n === 1, "mutant " . ($i + 1) . ": the anchor is unique in $rel", "count $n");
    if ($n === 1) {
        $mm = SjSandbox::start($tmp, $cfg, 'sjwrdm' . $i); $people($mm);
        is_($check($mm) === false, "mutant " . ($i + 1) . " is caught: $label");
        $mm->stop();
    }
    exec('rm -rf ' . escapeshellarg($tmp));
}
$s->stop();
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
