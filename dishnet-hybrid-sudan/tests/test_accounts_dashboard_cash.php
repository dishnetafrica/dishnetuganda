<?php
declare(strict_types=1);
/**
 * test_accounts_dashboard_cash.php — 5.18.71: the accounts dashboard (the admin's landing page) shows CASH IN HAND
 * on a book without SSP, and lists money held by staff.
 *
 * The operator's words (5.18.68): "I don't want working capital and bank amount to be shown in the plugin in Uganda".
 * 5.18.68 gave the Cashbook page a CASH IN HAND card; the landing page's hero still drew currencyPositions() — the
 * Phase-C account model: bank balances, the unassigned stream, a negative "position" — beside the Cashbook's figure.
 * Now, on a book without SSP:
 *   1. the hero reads "Cash in hand — per currency": CashbookService::cashInHand() per currency (the Cashbook card's own
 *      figure) and the base currency per project in the chips — the shape South Sudan's hero has;
 *   2. Money Locations' office figure is the base cash in hand (getBothBalances() reads the literal USD stream), and the
 *      block lists money HELD BY STAFF — each person's base balance exactly as Staff Cashbooks shows it — so a technician
 *      holding an advance no longer reads as "all cash is in office".
 * South Sudan's branch is untouched: "Total Cash Position", the project chips, the exposure rows.
 *
 * Driven through the real pages on sandboxed plugins (fake uCRM, fake Evolution): the receipt and the Staff Advance go in
 * through the real Add Entry wizard, so the advance reaches the technician's ledger through the 5.18.68 chain.
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
    $s->staff('acct', ['name' => 'Sandbox Accountant', 'email' => 'acct@example.test', 'role' => 'accountant']);
    $s->staff('tech', ['name' => 'Sandbox Tech', 'email' => 'tech@example.test', 'role' => 'support', 'phone' => '+256700000011']);
    $s->login('acct', 'acct@example.test', 'sj-password-1');
};
$wizard = function (SjSandbox $s, array $f) use ($today): array {
    $base = ['cb_action' => 'add_entry', 'direction' => 'out', 'project' => 'dishnet', 'date' => $today, 'ssp_rate' => '', 'category' => 'Staff Advance',
             'category_raw' => 'Staff Advance', 'person' => '', 'description' => '', 'validation_ref' => '', 'validation_status' => 'na',
             'inv_ref' => '', 'rcpt_ref' => '', 'pay_month' => '', 'exchange_type' => '', 'exch_ssp_amount' => ''];
    return $s->form('acct', $f + $base, 'page=dashboard&tab=cashbook', 'page=dashboard&tab=cashbook');
};
$DASH = 'page=dashboard&tab=accounts_dashboard';
$heroVal = function (string $html): string {   // the first big figure of the hero
    return preg_match('/<div class="d2-bal-val">([^<]+)<\/div>/', $html, $m) ? trim($m[1]) : '';
};
$chip = function (string $html, string $label): string {
    // the Uganda branch escapes the label (htmlspecialchars), the South Sudan branch writes it raw: accept either
    $lbl = '(?:' . preg_quote(htmlspecialchars($label), '/') . '|' . preg_quote($label, '/') . ')';
    return preg_match('/d2-bal-chip-lbl">' . $lbl . '<\/span><span class="d2-bal-chip-val">([^<]+)<\/span>/', $html, $m) ? trim($m[1]) : '';
};
$office = function (string $html): string {
    return preg_match('/Office \(Rupesh\)<\/div>\s*<div[^>]*>([^<]+)<\/div>/', $html, $m) ? trim($m[1]) : '';
};

// ═══════════════════════════════════════════════════════════════════════════
echo "A. Uganda — an empty book: the hero reads cash in hand, zero, and no account position\n";
$s = SjSandbox::start($root, $UG, 'sjdash');
$people($s); $tech = $s->ids['tech'];
$pg = $s->page('acct', $DASH);
is_(strpos($pg, 'Cash in hand — per currency') !== false, 'the hero is labelled "Cash in hand — per currency"', 'len ' . strlen($pg));
is_(strpos($pg, 'Cash Position — per currency') === false && strpos($pg, 'Total Cash Position') === false, 'neither the account position nor the South Sudan total is drawn');
is_($heroVal($pg) === 'UGX 0.00', 'the big figure is UGX 0.00 on an empty book', $heroVal($pg));
is_(preg_match('/d2-bal-val" style="[^"]*">USD 0\.00<\/div>/', $pg) === 1, 'the second currency, USD, reads 0.00 beneath it');
is_($chip($pg, 'Fiber & Starlink') === 'UGX 0.00' && $chip($pg, 'DishNet 4G') === 'UGX 0.00' && $chip($pg, 'BlueCARD') === 'UGX 0.00', 'the three project chips read UGX 0.00', json_encode([$chip($pg, 'Fiber & Starlink'), $chip($pg, 'DishNet 4G'), $chip($pg, 'BlueCARD')]));
is_(strpos($pg, 'All cash is in office — nothing held by staff.') !== false, 'Money Locations: nothing held by staff');
is_($office($pg) === 'UGX 0.00', 'the office holds UGX 0.00', $office($pg));

echo "\nB. A receipt and a Staff Advance through the wizard: the hero follows the ledger, the staff member is listed\n";
$r = $wizard($s, ['direction' => 'in', 'currency' => 'UGX', 'amount' => '1000000', 'category' => 'Receipt', 'category_raw' => 'Receipt', 'description' => 'opening float']);
is_($r[0] === 302, 'a UGX 1,000,000 receipt through the wizard (302)', $r[0] . ' ' . substr((string)$r[1], 0, 200));
$r = $wizard($s, ['currency' => 'UGX', 'amount' => '150000', 'person' => 'Sandbox Tech', 'description' => 'for materials']);
is_($r[0] === 302, 'a UGX 150,000 Staff Advance to the technician through the wizard (302)');
$pg = $s->page('acct', $DASH);
is_($heroVal($pg) === 'UGX 850,000.00', 'the hero reads UGX 850,000.00 — the ledger\'s running balance, as the Cashbook card shows it', $heroVal($pg));
is_($chip($pg, 'Fiber & Starlink') === 'UGX 850,000.00' && $chip($pg, 'DishNet 4G') === 'UGX 0.00' && $chip($pg, 'BlueCARD') === 'UGX 0.00', 'the project chips: Fiber & Starlink UGX 850,000.00, the others 0.00',
    json_encode([$chip($pg, 'Fiber & Starlink'), $chip($pg, 'DishNet 4G'), $chip($pg, 'BlueCARD')]));
is_(preg_match('/d2-bal-val" style="[^"]*">USD 0\.00<\/div>/', $pg) === 1, 'the USD line is untouched by UGX movements');
is_($office($pg) === 'UGX 850,000.00', 'Money Locations: the office holds UGX 850,000.00 (the base cash in hand, not the USD stream)', $office($pg));
is_(preg_match('/Sandbox Tech<\/div>.{0,900}?UGX 150,000\.00/s', $pg) === 1, 'Money Locations lists the technician holding UGX 150,000.00 — the advance, as Staff Cashbooks shows it');
is_(preg_match('/With staff<\/span><span[^>]*>UGX 150,000\.00/', $pg) === 1 && strpos($pg, 'UGX 150,000 with staff') !== false, 'the "With staff" total and the section badge read UGX 150,000');
is_(strpos($pg, 'All cash is in office') === false, 'the "all cash is in office" line is gone');
is_(preg_match('/Field Cash<\/div>\s*<div[^>]*>UGX 0<\/div>\s*<div[^>]*>0 collectors/', $pg) === 1, 'control: the Field Cash KPI (collections exposure) still reads UGX 0, 0 collectors — an advance is not a collection');
$pgC = $s->page('acct', 'page=dashboard&tab=cashbook');
is_(preg_match('/UGX CASH IN HAND.{0,400}?UGX 850,000\.00/s', $pgC) === 1, 'control: the Cashbook page\'s card reads the same UGX 850,000.00');

echo "\nC. The staff member spends: the held figure follows Staff Cashbooks, the office figure follows the ledger\n";
$s->login('tech', 'tech@example.test', 'sj-password-1');
$r = $s->form('tech', ['action' => 'log_expense', 'currency' => 'UGX', 'category' => 'Materials', 'expense_type' => 'Materials', 'amount' => '40000', 'ssp_amount' => '0', 'description' => 'cable ties', 'ref' => ''],
              'page=dashboard&tab=wallet', 'page=dashboard&tab=wallet');
is_(in_array($r[0], [200, 302], true), 'the technician logs a UGX 40,000 expense on the Field Register (' . $r[0] . ')', substr((string)$r[1], 0, 200));
$pg = $s->page('acct', $DASH);
$held = preg_match('/Sandbox Tech<\/div>.{0,900}?UGX ([0-9,]+\.[0-9]{2})/s', $pg, $m) ? $m[1] : '';
is_(in_array($held, ['110,000.00', '150,000.00'], true), "the technician's held figure reads UGX {$held}: 110,000.00 once the expense counts (approved on the spot), 150,000.00 while it is pending", substr($pg, strpos($pg, 'Sandbox Tech') ?: 0, 300));
is_($heroVal($pg) === 'UGX 850,000.00', 'the hero is unchanged — a staff expense is the staff member\'s money moving, not the office\'s');

echo "\nD. South Sudan — base USD: the hero and Money Locations are the 5.18.70 ones, byte for byte in shape\n";
$m = SjSandbox::start($root, $SS, 'sjdashs');
$people($m);
$pg = $m->page('acct', $DASH);
is_(strpos($pg, 'Total Cash Position') !== false && strpos($pg, 'Cash in hand — per currency') === false, 'the hero reads "Total Cash Position" (unchanged)');
is_($chip($pg, 'Fiber & Starlink') !== '' && $chip($pg, 'DishNet 4G') !== '' && $chip($pg, 'BlueCARD') !== '', 'the three project chips are there (unchanged)');
is_(strpos($pg, 'All cash is in office — no field holdings.') !== false && strpos($pg, 'nothing held by staff') === false, 'Money Locations is the exposure list with its own empty line (unchanged)');
$m->stop();

echo "\nE. Weakened copies are caught\n";
$mutants = [
  ['the hero draws the account position again', 'tabs/accounts/accounts_dashboard.php',
   '<div class="d2-bal-lbl">Cash in hand — per currency</div>', '<div class="d2-bal-lbl">Cash Position — per currency</div>',
   function (SjSandbox $mm, int $t) use ($DASH): bool { $pg = $mm->page('acct', $DASH); return strpos($pg, 'Cash Position — per currency') === false; }],
  ['the office figure reads the literal USD stream again', 'tabs/accounts/accounts_dashboard.php',
   "\$_cpOffice = round(max(0, (\$_dashSSP ? \$cbTotalBal : (\$_dashCih[\$_dashBase] ?? 0.0)) - \$_cpFieldTotal), 2);", "\$_cpOffice = round(max(0, \$cbTotalBal - \$_cpFieldTotal), 2);",
   function (SjSandbox $mm, int $t) use ($DASH, $wizard, $office): bool { $wizard($mm, ['direction' => 'in', 'currency' => 'UGX', 'amount' => '1000000', 'category' => 'Receipt', 'category_raw' => 'Receipt', 'description' => 'float']); return $office($mm->page('acct', $DASH)) === 'UGX 1,000,000.00'; }],
  ['money held by staff is no longer listed', 'tabs/accounts/accounts_dashboard.php',
   "        \$_held = \$_adJsonSvc->getUSDBalance(\$_hid);\n", "        \$_held = 0.0;\n",
   function (SjSandbox $mm, int $t) use ($DASH, $wizard): bool { $wizard($mm, ['direction' => 'in', 'currency' => 'UGX', 'amount' => '1000000', 'category' => 'Receipt', 'category_raw' => 'Receipt', 'description' => 'float']); $wizard($mm, ['currency' => 'UGX', 'amount' => '150000', 'person' => 'Sandbox Tech', 'description' => 'adv']); return preg_match('/Sandbox Tech<\/div>.{0,900}?UGX 150,000\.00/s', $mm->page('acct', $DASH)) === 1; }],
];
foreach ($mutants as $i => [$label, $rel, $old, $new, $check]) {
    [$tmp, $n] = sj_weakened_copy($root, $rel, $old, $new);
    is_($n === 1, "mutant " . ($i + 1) . ": the anchor is unique in $rel", "count $n");
    if ($n === 1) {
        $mm = SjSandbox::start($tmp, $UG, 'sjdashm' . $i); $people($mm);
        is_($check($mm, $mm->ids['tech']) === false, "mutant " . ($i + 1) . " is caught: $label");
        $mm->stop();
    }
    exec('rm -rf ' . escapeshellarg($tmp));
}
$s->stop();
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
