<?php
/**
 * build-book.php — a synthetic Cashbook for the P0 rehearsal, written through the plugin's own code (a COPY of lib/ and
 * migrations/ in the sandbox, never the checkout's), so the rows look exactly as the plugin writes them.
 *
 *   DN_VAULT_FILE=<sandbox>/vault.json php build-book.php <sandbox plugin root> <data dir> <variant>
 *
 * Variants (the settings, then the rows):
 *   ug         kyc_config.json and the store's settings row both UGX / UGX,USD — the live book's shape;
 *   two-adj    ug, with a second adjustment-like row on 12 Sep;
 *   ss         nothing configured: USD / USD,SSP, with SSP rows (South Sudan's shape);
 *   cfg-diff   the settings row UGX, no settings file: cashInHand's base is USD while the pages say UGX;
 *   cfg-vault  data/config.json UGX, kyc_config.json EMPTY strings, the vault UGX: the vault fills the base (UGX);
 *   cfg-novault  the same files, no vault: the empty value wins the merge, so the base is USD (the files alone say UGX).
 * Every name, description and person is fictitious and carries RH-MARK, so the rehearsal can prove none of them reaches
 * P0's output. Prints the ids the rehearsal needs as JSON.
 */
declare(strict_types=1);
[$_, $root, $data, $variant] = $argv + [null, '', '', ''];
if ((string)getenv('DN_VAULT_FILE') === '') { fwrite(STDERR, "refusing: DN_VAULT_FILE must point into the sandbox\n"); exit(2); }
if (!is_file("$root/lib/CashbookService.php") || realpath($root) === realpath(dirname(__DIR__, 3) . '/dishnet-hybrid-sudan')) {
    fwrite(STDERR, "refusing: <sandbox plugin root> must be a copy outside the checkout\n"); exit(2);
}
$GLOBALS['dataDir'] = $data;
require_once "$root/lib/SqliteStore.php";
require_once "$root/lib/CashbookService.php";
$UG    = ['tenant_profile' => 'uganda', 'cashbook_base_currency' => 'UGX', 'cashbook_currencies' => 'UGX,USD'];
$EMPTY = ['tenant_profile' => 'uganda', 'cashbook_base_currency' => '', 'cashbook_currencies' => ''];
$store = SqliteStore::create($data);
$put   = static function (string $f, array $v): void { file_put_contents($f, json_encode($v, JSON_PRETTY_PRINT)); chmod($f, 0600); };
switch ($variant) {
    case 'ug': case 'two-adj': $store->save('kyc_config.json', $UG); $put("$data/kyc_config.json", $UG); break;
    case 'cfg-diff':  $store->save('kyc_config.json', $UG); break;
    case 'cfg-vault': $put((string)getenv('DN_VAULT_FILE'), ['config' => ['cashbook_base_currency' => 'UGX', 'cashbook_currencies' => 'UGX,USD']]);
                      // no break
    case 'cfg-novault': $put("$data/config.json", $UG); $put("$data/kyc_config.json", $EMPTY); break;
    case 'ss': break;
    default: fwrite(STDERR, "unknown variant $variant\n"); exit(2);
}
$cb    = new CashbookService($store, $data);
$pdo   = $store->getPdo();
$ids   = [];
$must  = static function ($r, string $what) { if (!is_array($r) || empty($r['ok'])) { fwrite(STDERR, "fixture step failed: $what " . json_encode($r) . "\n"); exit(3); } return $r; };
$admin = ['name' => 'RH-MARK Approver'];

if ($variant === 'ss') {
    $cash = $must($cb->addAccount('Cash – Juba', 'USD', 'cash'), 'cash')['id'];
    $bank = $must($cb->addAccount('Bank USD', 'USD', 'bank'), 'bank')['id'];
    $must($cb->recordOpeningBalance((int)$cash, 400.0, '2026-08-01', 'RH-MARK opening', 'OB-1', 'RH-MARK Admin'), 'cash opening');
    $must($cb->recordOpeningBalance((int)$bank, 9000.0, '2026-08-01', 'RH-MARK opening', 'OB-2', 'RH-MARK Admin'), 'bank opening');
    $must($cb->addEntry(['direction' => 'in', 'amount' => 120, 'currency' => 'USD', 'category' => 'Receipt', 'description' => 'RH-MARK sale', 'person' => 'RH-MARK Buyer', 'date' => '2026-09-02'], $admin), 'usd in');
    $cb->addEntryRaw(['direction' => 'in', 'amount' => 10, 'currency' => 'SSP', 'ssp_amount' => 13000, 'ssp_rate' => 1300, 'category' => 'Receipt', 'description' => 'RH-MARK ssp', 'date' => '2026-09-03', 'status' => 'approved']);
    $cb->addEntryRaw(['direction' => 'out', 'amount' => 2, 'currency' => 'SSP', 'ssp_amount' => 2600, 'ssp_rate' => 1300, 'category' => 'Transport', 'description' => 'RH-MARK ssp out', 'date' => '2026-09-04', 'status' => 'approved']);
    $cb->addEntryRaw(['direction' => 'out', 'amount' => 3, 'currency' => 'SSP', 'category' => 'Transport', 'description' => 'RH-MARK ssp, no ssp_amount', 'date' => '2026-09-05', 'status' => 'approved']);
    $cb->addEntryRaw(['direction' => 'out', 'amount' => 75, 'currency' => '', 'category' => 'Purchase', 'description' => 'RH-MARK blank currency', 'date' => '2026-09-06', 'status' => 'approved']);
    $cb->addEntryRaw(['direction' => 'in', 'amount' => 30, 'currency' => 'USD', 'category' => 'Receipt', 'project' => '4g', 'description' => 'RH-MARK 4g', 'date' => '2026-09-07', 'status' => 'approved']);
    echo json_encode(['variant' => 'ss']), "\n";
    exit(0);
}

// ── Uganda ─────────────────────────────────────────────────────────────────────────────────────────────────────────
$bankU = (int)$must($cb->addAccount('Ecobank UGX', 'UGX', 'bank'), 'bank UGX')['id'];
$bankD = (int)$must($cb->addAccount('Ecobank USD', 'USD', 'bank'), 'bank USD')['id'];
$cash  = (int)$must($cb->addAccount('Cash – Uganda', 'UGX', 'cash'), 'cash')['id'];
$momo  = (int)$must($cb->addAccount('MoMo 0700123456 RH', 'UGX', 'momo'), 'momo')['id'];          // a 10-digit run: masked
$dir   = (int)$must($cb->addAccount('RH-MARK Director Loan', 'UGX', 'director'), 'director')['id']; // withheld
$inv   = (int)$must($cb->addAccount('RH-MARK Stock', 'UGX', 'inventory'), 'inventory')['id'];      // withheld
$bank2 = (int)$must($cb->addAccount('Bank 7000000001', 'UGX', 'bank'), 'bank 2')['id'];             // masked
$ids += ['bankU' => $bankU, 'bankD' => $bankD, 'cash' => $cash, 'momo' => $momo, 'dir' => $dir, 'inv' => $inv, 'bank2' => $bank2];

$must($cb->recordOpeningBalance($cash, 500000.0, '2026-08-01', 'RH-MARK opening', 'OB-C', 'RH-MARK Admin'), 'cash opening');
$must($cb->recordOpeningBalance($momo, 80000.0, '2026-08-01', 'RH-MARK opening', 'OB-M', 'RH-MARK Admin'), 'momo opening');
$must($cb->recordOpeningBalance($bankU, 2000000.0, '2026-08-01', 'RH-MARK opening', 'OB-B', 'RH-MARK Admin'), 'bank opening');
$must($cb->recordOpeningBalance($bankD, 50.0, '2026-08-01', 'RH-MARK opening', 'OB-D', 'RH-MARK Admin'), 'bank usd opening');
$must($cb->recordFunding($cash, $dir, 250000.0, '2026-08-05', 'RH-MARK funding', 'FUND-1', 'RH-MARK Admin'), 'funding');

// unassigned rows, as Add Entry writes them
$must($cb->addEntry(['direction' => 'in', 'amount' => 1000000, 'currency' => 'UGX', 'category' => 'Receipt', 'description' => 'RH-MARK receipt', 'person' => 'RH-MARK Person', 'date' => '2026-09-01'], $admin), 'receipt');
$must($cb->addEntry(['direction' => 'out', 'amount' => 200000, 'currency' => 'UGX', 'category' => 'Transport', 'description' => 'RH-MARK transport', 'person' => 'RH-MARK Driver', 'date' => '2026-09-02'], $admin), 'transport');
$must($cb->addEntry(['direction' => 'out', 'amount' => 50000, 'currency' => 'UGX', 'category' => 'Purchase', 'description' => 'RH-MARK pending', 'date' => '2026-09-03'], [], false), 'pending');
$rej = $must($cb->addEntry(['direction' => 'out', 'amount' => 30000, 'currency' => 'UGX', 'category' => 'Purchase', 'description' => 'RH-MARK rejected', 'date' => '2026-09-04'], [], false), 'to reject');
$must($cb->rejectEntry((int)$rej['id'], 'RH-MARK reason', ['name' => 'RH-MARK Admin']), 'reject');
$vd = $must($cb->addEntry(['direction' => 'in', 'amount' => 999999, 'currency' => 'UGX', 'category' => 'Receipt', 'description' => 'RH-MARK voided', 'date' => '2026-09-05'], $admin), 'to void');
$must($cb->voidEntry((int)$vd['id'], 'RH-MARK reason', 'RH-MARK Admin'), 'void');
$must($cb->addEntry(['direction' => 'in', 'amount' => 40000, 'currency' => 'UGX', 'category' => 'Receipt', 'project' => '4g', 'description' => 'RH-MARK 4g', 'date' => '2026-09-06'], $admin), '4g');
$must($cb->addEntry(['direction' => 'in', 'amount' => 100, 'currency' => 'USD', 'category' => 'Receipt', 'description' => 'RH-MARK usd', 'date' => '2026-09-07'], $admin), 'usd');
$cb->addEntryRaw(['direction' => 'in', 'amount' => 10000, 'currency' => '', 'category' => 'Receipt', 'description' => 'RH-MARK blank currency', 'date' => '2026-09-08', 'status' => 'approved']);
$cb->addEntryRaw(['direction' => 'out', 'amount' => 7000, 'currency' => 'ugx', 'category' => 'Purchase', 'description' => 'RH-MARK lowercase', 'date' => '2026-09-09', 'status' => 'approved']);
$cb->addEntryRaw(['direction' => 'out', 'amount' => 9, 'currency' => 'KES', 'category' => 'Purchase', 'description' => 'RH-MARK other currency', 'date' => '2026-09-09', 'status' => 'approved']);

// the 12 Sep adjustment: untyped, unassigned, as the live one is described (docs/07:3540)
$cb->addEntryRaw(['direction' => 'out', 'amount' => 123456, 'currency' => 'UGX', 'category' => 'Adjustment', 'description' => 'ADJUSTMENT ENTRY RH-MARK', 'person' => 'RH-MARK Person', 'date' => '2026-09-12', 'status' => 'approved', 'approved_by' => 'RH-MARK Admin']);
$ids['adj'] = (int)$pdo->query("SELECT id FROM cb_ledger WHERE description = 'ADJUSTMENT ENTRY RH-MARK'")->fetchColumn();
if ($variant === 'two-adj') {
    $cb->addEntryRaw(['direction' => 'in', 'amount' => 1, 'currency' => 'UGX', 'category' => 'Adjustment', 'description' => 'second ADJUSTMENT RH-MARK', 'date' => '2026-09-12', 'status' => 'approved']);
}
// the earlier bank import's shape: a transfer pair bank → inventory, and a one-legged bank charge
$must($cb->recordAccountTransfer($bankU, $inv, 300000.0, 300000.0, '2026-09-10', 'RH-MARK card purchase', 'same', 'RH-MARK Admin', 'BANK-REF001', 'bank_import'), 'import transfer');
$cb->addEntryRaw(['direction' => 'out', 'amount' => 5000, 'currency' => 'UGX', 'category' => 'Bank Charges', 'description' => 'RH-MARK charge', 'date' => '2026-09-10', 'status' => 'approved', 'account_id' => $bankU, 'source' => 'bank_import', 'validation_ref' => 'BANK-0123456789ab']);
// cash banked with the bank leg missing, and a cash expense
$cb->addEntryRaw(['direction' => 'out', 'amount' => 60000, 'currency' => 'UGX', 'category' => 'Bank Transfer', 'description' => 'RH-MARK banked', 'date' => '2026-09-11', 'status' => 'approved', 'account_id' => $cash, 'txn_type' => 'TRANSFER']);
$cb->addEntryRaw(['direction' => 'out', 'amount' => 15000, 'currency' => 'UGX', 'category' => 'Transport', 'description' => 'RH-MARK cash expense', 'date' => '2026-09-11', 'status' => 'approved', 'account_id' => $cash, 'txn_type' => 'EXPENSE']);
$cb->addEntryRaw(['direction' => 'out', 'amount' => 20000, 'currency' => 'UGX', 'category' => 'Purchase', 'description' => 'RH-MARK pending cash', 'date' => '2026-09-11', 'status' => 'pending_approval', 'account_id' => $cash, 'txn_type' => 'EXPENSE']);
// a row naming an account that does not exist (the plugin's own writer refuses one; an old book can hold one)
$pdo->exec("INSERT INTO cb_ledger (sr, project, date, direction, amount, currency, category, description, status, account_id, created_at, updated_at)
            VALUES ('ORPH-1', 'dishnet', '2026-09-11', 'in', 3333, 'UGX', 'Receipt', 'RH-MARK orphan', 'approved', 999, '2026-09-11', '2026-09-11')");
echo json_encode(['variant' => $variant] + $ids), "\n";
