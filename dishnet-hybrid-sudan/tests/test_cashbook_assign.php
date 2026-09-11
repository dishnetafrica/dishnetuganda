<?php
declare(strict_types=1);
/**
 * test_cashbook_assign.php — money that left, from nowhere in particular.
 *
 * Every expense in the Uganda book was entered without an account: 7,413,000
 * of site rent, legal fees, a vehicle and airtime, none of it taken out of any
 * cash box or bank account. The currency total stayed right the whole time,
 * because a row with no account still counts in the position — which is
 * exactly why nobody noticed that not one account balance was true.
 *
 * What this pins down:
 *
 *   A MOVE CHANGES WHERE, NEVER WHAT. The amount, date and direction are
 *   untouched, and every move is on the audit trail with what it was before.
 *
 *   CURRENCY IS NOT NEGOTIABLE. A UGX row cannot be put in a USD account,
 *   including by a bulk assignment that would otherwise skip the rule.
 *
 *   A VOIDED ROW IS HISTORY. Moving one would rewrite what the book said at
 *   the time, which is the one thing the audit trail exists to prevent.
 */
require_once dirname(__DIR__) . '/lib/StoreInterface.php';
require_once dirname(__DIR__) . '/lib/JsonStore.php';
require_once dirname(__DIR__) . '/lib/SqliteStore.php';
require_once dirname(__DIR__) . '/lib/CashbookService.php';
require_once dirname(__DIR__) . '/lib/FinAudit.php';

$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; } }

$root = dirname(__DIR__);
$tmp  = sys_get_temp_dir() . '/dn_assign_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);
$store = SqliteStore::create($tmp);
$pdo   = $store->getPdo();
$cb    = new CashbookService($store, $tmp);
$actor = ['id' => 3, 'name' => 'Bhavin Madlani'];

$cash = (int)($cb->addAccount('Cash – Uganda', 'UGX', 'cash')['id'] ?? 0);
$bank = (int)($cb->addAccount('Ecobank Uganda – UGX', 'UGX', 'bank')['id'] ?? 0);
$usd  = (int)($cb->addAccount('Ecobank Uganda – USD', 'USD', 'bank')['id'] ?? 0);
$shut = (int)($cb->addAccount('Old Wallet', 'UGX', 'momo')['id'] ?? 0);
$cb->setAccountActive($shut, false);

// The Uganda book's own shape: expenses with no account at all.
$mk = function (array $d) use ($cb, $pdo): array {
    $sr = $cb->addEntryRaw($d + ['project' => 'dishnet', 'status' => 'approved']);
    $st = $pdo->prepare("SELECT * FROM cb_ledger WHERE sr = ?");
    $st->execute([$sr]);
    return $st->fetch(PDO::FETCH_ASSOC);
};
$rent  = $mk(['date' => '2026-06-23', 'direction' => 'out', 'amount' => 1200000, 'currency' => 'UGX',
              'category' => 'Site Expense', 'description' => 'Site rent June']);
$legal = $mk(['date' => '2026-06-20', 'direction' => 'out', 'amount' => 2193000, 'currency' => 'UGX',
              'category' => 'Legal Fees', 'description' => 'Company registration']);
$dolla = $mk(['date' => '2026-07-01', 'direction' => 'out', 'amount' => 40, 'currency' => 'USD',
              'category' => 'Misc Expense', 'description' => 'A dollar cost']);
$void  = $mk(['date' => '2026-07-02', 'direction' => 'out', 'amount' => 5000, 'currency' => 'UGX',
              'category' => 'Airtime', 'description' => 'Entered twice']);
$pdo->prepare("UPDATE cb_ledger SET status = 'voided' WHERE id = ?")->execute([(int)$void['id']]);

echo "What has no home\n";
$orphans = $cb->unassignedRows();
t('three active rows have no account', count($orphans), 3);
is_(!in_array((int)$void['id'], array_column($orphans, 'id'), true),
    'and a voided row is not among them');
t('narrowed to one currency', count($cb->unassignedRows('USD')), 1);

echo "\nThe total was right all along, which is why nobody looked\n";
$pos = $cb->currencyPositions();
t('the UGX position counts the homeless rows', $pos['UGX']['unassigned'], -3393000.0);
t('while every account reads zero', $cb->accountBalance($cash), 0.0);

echo "\nMoving one\n";
$r = $cb->assignAccount((int)$rent['id'], $cash, $actor);
t('it moves',        $r['ok'], true);
t('from nowhere',    $r['from'], 0);
t('to the cash box', $r['to'], $cash);
t('and the cash box is lighter for it', $cb->accountBalance($cash), -1200000.0);
$after = $pdo->query("SELECT * FROM cb_ledger WHERE id = " . (int)$rent['id'])->fetch(PDO::FETCH_ASSOC);
t('the amount is untouched',    (float)$after['amount'], 1200000.0);
t('the date is untouched',      $after['date'], '2026-06-23');
t('the direction is untouched', $after['direction'], 'out');
t('the category is untouched',  $after['category'], 'Site Expense');

echo "\nAnd it is on the record\n";
$h = FinAudit::history($pdo, 'cb_ledger', (int)$rent['id']);
t('one audit line',          count($h), 1);
t('an update',               $h[0]['action'], 'update');
t('by whoever did it',       $h[0]['actor_name'], 'Bhavin Madlani');
t('saying what it was',      json_decode((string)$h[0]['before_json'], true)['account_id'], 0);
t('and what it became',      json_decode((string)$h[0]['after_json'], true)['account_id'], $cash);
is_(strpos((string)$h[0]['reason'], 'was unassigned') !== false,
    'and that it had no account before', (string)$h[0]['reason']);

echo "\nMoving it again, to somewhere else\n";
$r2 = $cb->assignAccount((int)$rent['id'], $bank, $actor, 'paid from the bank after all');
t('it moves again',      $r2['ok'], true);
t('from the cash box',   $r2['from'], $cash);
t('the cash box is whole again', $cb->accountBalance($cash), 0.0);
t('and the bank carries it',     $cb->accountBalance($bank), -1200000.0);
t('two audit lines now', count(FinAudit::history($pdo, 'cb_ledger', (int)$rent['id'])), 2);
$r3 = $cb->assignAccount((int)$rent['id'], $bank, $actor);
t('moving it where it already is does nothing', $r3['ok'], true);
t('and writes no audit line for it',
    count(FinAudit::history($pdo, 'cb_ledger', (int)$rent['id'])), 2);

echo "\nWhat it refuses\n";
$x = $cb->assignAccount((int)$legal['id'], $usd, $actor);
t('a UGX row into a USD account', $x['ok'], false);
is_(strpos((string)$x['error'], 'books USD') !== false, 'because money does not change currency by being filed', (string)$x['error']);
$x = $cb->assignAccount((int)$legal['id'], $shut, $actor);
t('an account that is closed', $x['ok'], false);
is_(strpos((string)$x['error'], 'not an active account') !== false, 'and says so');
$x = $cb->assignAccount((int)$void['id'], $cash, $actor);
t('a voided row', $x['ok'], false);
is_(strpos((string)$x['error'], 'voided') !== false, 'because that is rewriting history');
$x = $cb->assignAccount(999999, $cash, $actor);
t('a row that does not exist', $x['ok'], false);
$x = $cb->assignAccount((int)$legal['id'], 999999, $actor);
t('an account that does not exist', $x['ok'], false);
t('and none of those changed anything',
    $cb->accountBalance($cash), 0.0);

// ── The tool ────────────────────────────────────────────────────────────────
echo "\nThe tool\n";
$tool = $root . '/tools/cashbook_assign_accounts.php';
is_(is_file($tool), 'tools/cashbook_assign_accounts.php exists');
$env = 'DN_DATA_DIR=' . escapeshellarg($tmp) . ' ';
$run = function (string $flags) use ($env, $tool): array {
    $o = []; $c = 0;
    exec($env . 'php ' . escapeshellarg($tool) . ' ' . $flags . ' 2>&1', $o, $c);
    return [implode("\n", $o), $c];
};

[$txt, $code] = $run('');
t('a bare run exits clean', $code, 0);
is_(strpos($txt, 'ROWS WITHOUT AN ACCOUNT') !== false, 'and lists what has no home', $txt);
is_(strpos($txt, 'Legal Fees') !== false, 'naming them');
is_(strpos($txt, 'ACCOUNTS') !== false, 'and the accounts to choose from');

[$txtD, $codeD] = $run('--assign ' . $legal['sr'] . '=' . $cash);
t('a dry run exits clean', $codeD, 0);
is_(strpos($txtD, 'unassigned → Cash – Uganda') !== false, 'and shows the move', $txtD);
t('but writes nothing', $cb->accountBalance($cash), 0.0);

[$txtC, $codeC] = $run('--assign ' . $legal['sr'] . '=' . $cash . ' --commit');
t('committing works', $codeC, 0);
t('and the row lands', $cb->accountBalance($cash), -2193000.0);

echo "\nWhat the tool refuses\n";
[$t1, $c1] = $run('--assign NOPE=1');
t('a row that is not there', $c1, 2);
is_(strpos($t1, 'no such ledger row') !== false, 'and says which', $t1);
[$t2, $c2] = $run('--assign ' . $legal['sr'] . '=nonsense');
t('a malformed pair', $c2, 2);
is_(strpos($t2, 'expected something like') !== false, 'and shows the shape it wants');
[$t3, $c3] = $run('--assign ' . $legal['sr'] . '=999');
t('an account that is not there', $c3, 2);
[$t4, $c4] = $run('--all-to X');
t('a non-numeric account is refused, not ignored', $c4, 2);
[$t5, $c5] = $run('--wat');
t('an unknown option too', $c5, 2);

echo "\nAll of one currency at once\n";
// Two more homeless UGX rows, and the USD one still sitting there. A bulk
// assignment must take the first two and leave the third alone — money does
// not change currency by being filed.
$air = $mk(['date' => '2026-09-07', 'direction' => 'out', 'amount' => 20000, 'currency' => 'UGX',
            'category' => 'Airtime', 'description' => 'Airtime']);
$car = $mk(['date' => '2026-06-16', 'direction' => 'out', 'amount' => 150000, 'currency' => 'UGX',
            'category' => 'Vehicle', 'description' => 'Vehicle']);
t('three homeless rows again', count($cb->unassignedRows()), 3);

[$t6, $c6] = $run('--all-to ' . $cash);
t('a dry run of the bulk move exits clean', $c6, 0);
is_((bool)preg_match('/rows to move\s+2/', $t6), 'and would take only the two UGX rows', $t6);
t('having written nothing', count($cb->unassignedRows()), 3);

[$t6b, $c6b] = $run('--all-to ' . $cash . ' --commit');
t('committing it works', $c6b, 0);
$left = $cb->unassignedRows();
t('only the USD row is left behind', count($left), 1);
t('and it is the USD one', strtoupper((string)$left[0]['currency']), 'USD');
t('the cash box carries the legal fee and both new rows',
    $cb->accountBalance($cash), -2363000.0);

// A UGX account cannot take it, and says so rather than doing nothing quietly.
[$t7, $c7] = $run('--all-to ' . $cash . ' --commit');
t('a second sweep has nothing to do', $c7, 1);
is_(strpos($t7, 'cannot take any of them') !== false, 'and says why', $t7);

[$t8, $c8] = $run('--all-to ' . $usd . ' --commit');
t('the USD account takes the USD row', $cb->accountBalance($usd), -40.0);
t('nothing is homeless now', $cb->unassignedRows(), []);
$pos2 = $cb->currencyPositions();
t('the position no longer has an unassigned column', $pos2['UGX']['unassigned'], 0.0);
t('and the UGX total has not moved a shilling', $pos2['UGX']['total'], -3563000.0);

[$t9, $c9] = $run('');
is_(strpos($t9, 'Every active row is in an account') !== false, 'and it says so', $t9);

exec('rm -rf ' . escapeshellarg($tmp));
echo "\n  {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
