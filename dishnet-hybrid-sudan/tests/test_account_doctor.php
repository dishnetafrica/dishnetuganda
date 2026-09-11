<?php
declare(strict_types=1);
/**
 * test_account_doctor.php — tracing one real customer through the whole system.
 *
 * Onboarding touches six systems: uCRM for the client and the service, the
 * plugin for the application, the warehouse for the kit, the mail server for
 * a mailbox, the portal for a way in. Each has a screen. None answered "did
 * all of it happen", so checking meant opening six and remembering the first
 * by the time you reached the last.
 *
 * The property these assertions really hold is that the report does not
 * contradict itself. An earlier draft printed GAP against a missing KYC
 * application and a missing mailbox — neither of which is a fault — while
 * the summary at the bottom counted one problem. Three marks disagreeing
 * with a total is how a reader learns to stop reading the marks, so every
 * GAP now corresponds to exactly one line in the summary, and that is
 * asserted against the rendered output rather than promised in a comment.
 */
require_once dirname(__DIR__) . '/lib/StoreInterface.php';
require_once dirname(__DIR__) . '/lib/JsonStore.php';
require_once dirname(__DIR__) . '/lib/SqliteStore.php';

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; } }

$tmp = sys_get_temp_dir() . '/dn_acctdoc_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);
$store = SqliteStore::create($tmp);
$pdo   = $store->getPdo();

$run = function (string $args) use ($root, $tmp): array {
    $out = []; $rc = 0;
    exec(sprintf('DN_DATA_DIR=%s php %s %s 2>&1',
         escapeshellarg($tmp), escapeshellarg($root . '/tools/account_doctor.php'), $args), $out, $rc);
    return [implode("\n", $out), $rc];
};
/** GAP marks in the report, and the count the summary claims. */
$marksVsSummary = function (string $report): array {
    preg_match_all('/^\s+GAP\s/m', $report, $m);
    $gaps = count($m[0]);
    $claimed = preg_match('/(\d+) thing\(s\) to fix/', $report, $c) ? (int)$c[1]
             : (strpos($report, 'Nothing missing') !== false ? 0 : -1);
    return [$gaps, $claimed];
};

// ── A customer with nothing but a name ──────────────────────────────────────
echo "\nA customer created and then forgotten about\n";
$store->save('ucrm_clients_cache.json', [
    ['id' => 6100, 'firstName' => 'Walk', 'lastName' => 'In', 'isActive' => true,
     'clientType' => 2, 'contacts' => []],
]);
[$o, $rc] = $run('--client 6100');
is_($rc === 1, 'the doctor reports problems', substr($o, 0, 300));
is_(strpos($o, 'Walk In') !== false, 'naming the customer');
[$marks, $claimed] = $marksVsSummary($o);
is_($marks > 0, 'with gaps marked in the body', "marks={$marks}");
is_($marks === $claimed,
    'AND THE SUMMARY COUNTS EXACTLY THOSE — a report that disagrees with '
    . 'itself teaches you to stop reading it', "marked {$marks}, summary said {$claimed}");
is_(strpos($o, 'No service on the account') !== false, 'no service is named in the summary');
is_(strpos($o, 'log in') !== false, 'and that they cannot log in at all');

// ── The same customer, fully onboarded ──────────────────────────────────────
echo "\nThe same customer, done properly\n";
$store->save('ucrm_clients_cache.json', [
    ['id' => 4021, 'companyName' => 'Family Shoppers Ltd', 'userIdent' => 'DN-4021',
     'isActive' => true, 'clientType' => 2, 'street1' => 'Plot 14, Ntinda Road',
     'city' => 'Kampala', 'registrationDate' => '2026-09-11',
     'contacts' => [['email' => 'grace@familyshoppers.co.ug', 'phone' => '+256772000111']]],
]);
$store->save('ucrm_services_cache.json', [
    ['id' => 88, 'clientId' => 4021, 'name' => 'Starlink Residential 50Mbps',
     'status' => 1, 'price' => 400000, 'currencyCode' => 'UGX', 'activeFrom' => '2026-09-11'],
]);
$store->save('ucrm_invoices_cache.json', [
    ['id' => 5001, 'clientId' => 4021, 'number' => 'INV-5001', 'total' => 2649000,
     'amountPaid' => 2649000, 'status' => 4, 'currencyCode' => 'UGX',
     'createdDate' => '2026-09-11', 'dueDate' => '2026-09-11'],
]);
$store->save('ucrm_invoice_payments_cache.json', [
    '5001' => ['payments' => [['id' => 900, 'amount' => 2649000, 'method' => 'Mobile Money',
                              'createdDate' => '2026-09-11']]],
]);
$pdo->exec("INSERT INTO stock_categories (title, sku, service_type, track_mode, created_at)
            VALUES ('Starlink Standard Kit','SL-STD','starlink','serial','2026-09-11')");
$cat = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO stock_units (category_id, serial_number, status, location_type,
              crm_client_id, crm_service_id, purchase_cost, created_at, updated_at)
            VALUES ({$cat},'KIT-FS-1','installed','customer',4021,88,2144704,
                    '2026-09-11 10:00:00','2026-09-11 10:00:00')");

[$o, $rc] = $run('--client 4021');
is_($rc === 0, 'a complete account exits clean', substr($o, 0, 400));
is_(strpos($o, 'Nothing missing') !== false, 'and says nothing is missing');
[$marks, $claimed] = $marksVsSummary($o);
is_($marks === 0, 'with no gaps marked anywhere in the body', "marks={$marks}");

echo "\nWhat it shows\n";
is_(strpos($o, 'DN-4021') !== false, 'the account reference a customer quotes');
is_(strpos($o, 'Starlink Residential 50Mbps') !== false, 'the service');
is_(strpos($o, 'KIT-FS-1') !== false, 'the kit on the roof, by serial');
is_(strpos($o, 'UGX 2,649,000') !== false, 'what they paid, in shillings');
is_(strpos($o, 'Plot 14, Ntinda Road, Kampala') !== false, 'and where they are');
is_(strpos($o, 'Juba') === false, 'and nowhere does it say Juba');

// Staff tool, so the cost IS shown — it is the customer view that hides it.
is_(strpos($o, '2,144,704') !== false,
    'staff see what the kit cost, which is the whole point of a staff tool');

// ── Finding by name ─────────────────────────────────────────────────────────
echo "\nFinding a customer without knowing their id\n";
[$o, $rc] = $run('--find "Family Shoppers"');
is_($rc === 0 && strpos($o, 'DN-4021') !== false, 'a unique name resolves straight to them', substr($o, 0, 200));
[$o, $rc] = $run('--find "Nobody At All"');
is_($rc === 1 && stripos($o, 'No customer matching') !== false, 'and an unknown one says so', $o);

echo "\nMachine-readable\n";
[$o, $rc] = $run('--client 4021 --json');
$j = json_decode($o, true);
is_(is_array($j) && ($j['account_ref'] ?? '') === 'DN-4021', '--json returns the account', substr($o, 0, 200));
is_(($j['gaps'] ?? null) === [], 'with its gaps list empty');

echo "\nRefusals\n";
[$o, $rc] = $run('--client 999999');
is_($rc === 1 && strpos($o, 'No client') !== false, 'an unknown id is reported, not invented', $o);
[$o, $rc] = $run('--clientt 4021');
is_($rc === 2 && strpos($o, 'Unknown option') !== false, 'a typed flag is named', $o);
[$o, $rc] = $run('');
is_($rc === 2 && strpos($o, 'usage') !== false, 'and no argument prints the usage', $o);

exec('rm -rf ' . escapeshellarg($tmp));
echo "\n  {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
