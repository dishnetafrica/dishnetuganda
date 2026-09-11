<?php
declare(strict_types=1);
/**
 * test_purchase_accounting.php — what we bought, what it cost, what we owe.
 *
 * A purchase used to be a header and nothing else: supplier, one total, a
 * payment method, and a cb_ledger_id that nothing ever filled in. The items
 * typed into the receiving form were used to create the stock and then
 * discarded, so the database could say a router existed and could not say
 * what it cost — minutes after somebody had entered the cost. There was no
 * VAT, no line, and no payable: a bill was 'received' and that was the last
 * the system thought about it.
 *
 * These assertions cover the three failures that follow from that, all of
 * them named in the audit brief:
 *
 *   "Purchase cost is lost"  — every unit now carries its line's unit cost,
 *   and bulk quantities carry a weighted average, so inventory value is a
 *   fact rather than an estimate.
 *
 *   "Do not create duplicate financial records" — a receive with the same
 *   idempotency key is the same delivery, and a half-failed delivery leaves
 *   nothing at all rather than a purchase that cannot be finished.
 *
 *   Supplier accounts payable — which did not exist in any form.
 */
require_once dirname(__DIR__) . '/lib/StoreInterface.php';
require_once dirname(__DIR__) . '/lib/JsonStore.php';
require_once dirname(__DIR__) . '/lib/SqliteStore.php';
require_once dirname(__DIR__) . '/lib/StockService.php';
require_once dirname(__DIR__) . '/lib/PurchaseService.php';

$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; } }

$tmp = sys_get_temp_dir() . '/dn_purchase_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);
$store = SqliteStore::create($tmp);
$pdo   = $store->getPdo();
$stock = StockService::fromStore($store, $tmp);
$stock->ensureTables();
$pur   = new PurchaseService($pdo, $tmp, $stock);
$bhavin = ['id' => 7, 'name' => 'Bhavin Madlani'];

// Two categories: a dish with serial numbers, and cable sold by the metre.
$kit = (int)($stock->saveCategory(['title' => 'Starlink Standard Kit', 'sku' => 'SL-STD',
    'service_type' => 'starlink', 'track_mode' => 'serial', 'buy_price' => 2144704])['id'] ?? 0);
$cable = (int)($stock->saveCategory(['title' => 'CAT6 Cable (m)', 'sku' => 'CAT6',
    'service_type' => 'general', 'track_mode' => 'quantity', 'buy_price' => 2000])['id'] ?? 0);
is_($kit > 0 && $cable > 0, 'the two stock categories exist');

// ── A delivery ──────────────────────────────────────────────────────────────
echo "\nReceiving a delivery\n";
$r = $pur->receive([
    'supplier'       => 'Starlink Uganda',
    'invoice_number' => 'SL-2026-0041',
    'purchase_date'  => '2026-09-11',
    'currency'       => 'UGX',
    'payment_method' => 'credit',
    'due_date'       => '2026-10-11',
    'idem_key'       => 'recv-0041',
], [
    ['category_id' => $kit,   'quantity' => 2, 'unit_cost' => 2144704, 'tax_rate' => 18,
     'serials' => ['KIT-AAA-001', 'KIT-AAA-002']],
    ['category_id' => $cable, 'quantity' => 100, 'unit_cost' => 2000, 'tax_rate' => 18],
], $bhavin);
t('the delivery is recorded', $r['ok'], true);
$pid = (int)$r['id'];

// 2 × 2,144,704 = 4,289,408   +   100 × 2,000 = 200,000   →  4,489,408 net
// VAT at 18%: 772,093.44 + 36,000 = 808,093.44
t('the net of the lines', $r['totals']['subtotal'], 4489408.0);
t('the VAT on them',      $r['totals']['tax_total'], 808093.44);
t('and the gross',        $r['totals']['lines_total'], 5297501.44);
t('no variance when the supplier total was not given separately', $r['variance'], 0.0);

$d = $pur->detail($pid);
t('both lines are stored', count($d['items']), 2);
t('a line remembers its unit cost', (float)$d['items'][0]['unit_cost'], 2144704.0);
t('and its tax',                    (float)$d['items'][0]['tax_amount'], 772093.44);
t('and the serials that came in',   $d['items'][0]['serials'], ['KIT-AAA-001', 'KIT-AAA-002']);

// ── Cost reaches the stock ──────────────────────────────────────────────────
echo "\nThe cost follows the goods onto the shelf\n";
t('two units were created', (int)$r['units_created'], 2);
$u = $pdo->query("SELECT * FROM stock_units WHERE serial_number='KIT-AAA-001'")->fetch(PDO::FETCH_ASSOC);
is_(is_array($u), 'the first kit is in stock');
t('carrying the cost from its line, not the catalogue price',
  (float)($u['purchase_cost'] ?? 0), 2144704.0);
t('and the supplier invoice it arrived on', $u['purchase_ref'] ?? '', 'SL-2026-0041');
t('in stock, not assigned to anyone', $u['status'] ?? '', 'in_stock');

$q = $pdo->query("SELECT qty_on_hand, avg_cost FROM stock_quantities WHERE category_id={$cable}")->fetch(PDO::FETCH_ASSOC);
t('100 metres of cable on hand', (int)($q['qty_on_hand'] ?? 0), 100);
t('at what it cost — bulk stock used to be worth zero', (float)($q['avg_cost'] ?? 0), 2000.0);

// A second delivery at a different price moves the average, not the price.
$pur->receive(['supplier' => 'Kampala Cables', 'invoice_number' => 'KC-9', 'idem_key' => 'recv-kc9'],
              [['category_id' => $cable, 'quantity' => 100, 'unit_cost' => 3000]], $bhavin);
$q = $pdo->query("SELECT qty_on_hand, avg_cost FROM stock_quantities WHERE category_id={$cable}")->fetch(PDO::FETCH_ASSOC);
t('200 metres now', (int)$q['qty_on_hand'], 200);
t('averaged across both deliveries', (float)$q['avg_cost'], 2500.0);

// ── Inventory value ─────────────────────────────────────────────────────────
echo "\nWhat the shelf is worth\n";
$v = $pur->inventoryValue();
t('serialised units at what each cost', $v['serial'], 4289408.0);
t('bulk at the weighted average',       $v['bulk'],   500000.0);
t('and the two add up',                 $v['total'],  4789408.0);
t('nothing is uncosted',                $v['uncosted_units'], 0);

// ── The supplier is owed ────────────────────────────────────────────────────
echo "\nAccounts payable\n";
$bal = $pur->supplierBalances();
$byName = [];
foreach ($bal as $b) $byName[$b['supplier']] = $b;
is_(isset($byName['Starlink Uganda']), 'Starlink Uganda is owed money');
t('the whole bill is outstanding', (float)$byName['Starlink Uganda']['outstanding'], 5297501.44);

$pay = $pur->recordPayment($pid, ['amount' => 3000000, 'method' => 'bank',
                                  'reference' => 'FT26091100123', 'paid_on' => '2026-09-11'], $bhavin);
t('a part payment is accepted', $pay['ok'], true);
t('and what is left is right',  $pay['outstanding'], 2297501.44);
t('the bill is not closed yet', $pay['status'], 'received');

$over = $pur->recordPayment($pid, ['amount' => 9000000], $bhavin);
t('paying more than the bill is refused', $over['ok'], false);
is_(strpos((string)$over['error'], 'outstanding') !== false,
    'and says so in terms of what is left', (string)$over['error']);

$final = $pur->recordPayment($pid, ['amount' => 2297501.44], $bhavin);
t('settling the rest closes the bill', $final['status'], 'paid');
t('with nothing outstanding', $final['outstanding'], 0.0);
$bal = $pur->supplierBalances();
$names = array_column($bal, 'supplier');
is_(!in_array('Starlink Uganda', $names, true),
    'and they drop off the payables report');

// ── One delivery, not two ───────────────────────────────────────────────────
echo "\nA second tap is not a second delivery\n";
$again = $pur->receive(['supplier' => 'Starlink Uganda', 'invoice_number' => 'SL-2026-0041',
                        'idem_key' => 'recv-0041'],
                       [['category_id' => $kit, 'quantity' => 1, 'unit_cost' => 2144704,
                         'serials' => ['KIT-AAA-003']]], $bhavin);
t('it succeeds', $again['ok'], true);
t('naming itself a duplicate', $again['duplicate'], true);
t('and returns the original purchase', (int)$again['id'], $pid);
t('no third kit was created',
  (int)$pdo->query("SELECT COUNT(*) FROM stock_units WHERE serial_number='KIT-AAA-003'")->fetchColumn(), 0);

// ── A delivery that half-fails leaves nothing ───────────────────────────────
echo "\nAll of it, or none of it\n";
$before = (int)$pdo->query("SELECT COUNT(*) FROM stock_purchases")->fetchColumn();
$bad = $pur->receive(['supplier' => 'Starlink Uganda', 'invoice_number' => 'SL-42'], [
    ['category_id' => $kit, 'quantity' => 2, 'unit_cost' => 2144704,
     // The second serial is already on the shelf — the old code committed the
     // header and the first unit before discovering that.
     'serials' => ['KIT-BBB-001', 'KIT-AAA-001']],
], $bhavin);
t('the delivery is refused', $bad['ok'], false);
t('and no purchase header was left behind',
  (int)$pdo->query("SELECT COUNT(*) FROM stock_purchases")->fetchColumn(), $before);
t('nor the unit that did succeed',
  (int)$pdo->query("SELECT COUNT(*) FROM stock_units WHERE serial_number='KIT-BBB-001'")->fetchColumn(), 0);

// ── Refusals that protect the numbers ───────────────────────────────────────
echo "\nWhat it will not accept\n";
t('a purchase with no supplier', $pur->receive([], [['category_id' => $kit, 'quantity' => 1]], $bhavin)['ok'], false);
t('a purchase with no lines',    $pur->receive(['supplier' => 'X'], [], $bhavin)['ok'], false);
$z = $pur->receive(['supplier' => 'X'], [['category_id' => $kit, 'quantity' => 0, 'unit_cost' => 5]], $bhavin);
is_(strpos((string)$z['error'], 'more than zero') !== false, 'a zero quantity', (string)$z['error']);
$neg = $pur->receive(['supplier' => 'X'], [['category_id' => $kit, 'quantity' => 1, 'unit_cost' => -5]], $bhavin);
is_(strpos((string)$neg['error'], 'negative') !== false, 'a negative cost', (string)$neg['error']);
$bt = $pur->receive(['supplier' => 'X'], [['category_id' => $kit, 'quantity' => 1, 'unit_cost' => 5, 'tax_rate' => 180]], $bhavin);
is_(strpos((string)$bt['error'], 'percentage') !== false, 'a tax rate of 180 — a rate typed as an amount', (string)$bt['error']);
$mism = $pur->receive(['supplier' => 'X'], [['category_id' => $kit, 'quantity' => 3, 'unit_cost' => 5,
                                             'serials' => ['S1', 'S2']]], $bhavin);
is_(strpos((string)$mism['error'], 'serial') !== false,
    'three kits with two serials — phantom stock either way', (string)$mism['error']);

// ── The supplier's total is kept when it disagrees ──────────────────────────
echo "\nWhen the invoice and the lines disagree\n";
$var = $pur->receive([
    'supplier' => 'Kampala Cables', 'invoice_number' => 'KC-11',
    'total_cost' => 250000,   // what they billed
], [['category_id' => $cable, 'quantity' => 100, 'unit_cost' => 2000]], $bhavin);   // 200,000
t('the delivery is still recorded', $var['ok'], true);
t('the supplier total is what is stored as the bill', $var['totals']['billed'], 250000.0);
t('the lines are stored as they were entered',        $var['totals']['lines_total'], 200000.0);
t('and the difference is reported, not swallowed',    $var['variance'], 50000.0);
t('the payable follows the supplier, not our arithmetic',
  (float)$pur->totalsFor((int)$var['id'])['outstanding'], 250000.0);

// ── Audited ─────────────────────────────────────────────────────────────────
echo "\nEvery purchase leaves a trail\n";
require_once dirname(__DIR__) . '/lib/FinAudit.php';
$h = FinAudit::history($pdo, 'stock_purchase', $pid);
is_(count($h) >= 3, 'the receipt and both payments are recorded', 'got ' . count($h));
t('the first event is the receipt', $h[0]['action'] ?? '', 'create');
t('by the person who received it',  $h[0]['actor_name'] ?? '', 'Bhavin Madlani');
is_(strpos((string)($h[1]['reason'] ?? ''), 'supplier payment') !== false,
    'and a payment says what it was');

exec('rm -rf ' . escapeshellarg($tmp));
echo "\n  {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
