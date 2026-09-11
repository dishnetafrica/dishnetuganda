<?php
declare(strict_types=1);
/**
 * test_customer_account.php — is this customer actually set up?
 *
 * Everything a customer account needs was already being recorded. The
 * profile in uCRM, the invoices in one cache, the payments in another keyed
 * by invoice, the equipment in stock_units, the portal mailbox in
 * customer_identities, the signup in kyc_applications. Six places, six
 * shapes, nothing that put them together — so the question in the title
 * could only be answered by opening six screens and remembering the last.
 *
 * The two things these assertions are really protecting:
 *
 *   WHAT A KIT COST IS NOT THE CUSTOMER'S BUSINESS. It is the margin on
 *   their own installation. forCustomer() rebuilds from an explicit list
 *   rather than deleting known-bad keys, so a field added next year is
 *   invisible until somebody decides otherwise — and this asserts on the
 *   returned structure, not on the promise.
 *
 *   ONE CUSTOMER'S DATA, NOT ANOTHER'S. Every reader here filters by client
 *   id, including the payment cache, which is keyed by invoice and therefore
 *   holds every customer's receipts in one file.
 */
require_once dirname(__DIR__) . '/lib/StoreInterface.php';
require_once dirname(__DIR__) . '/lib/JsonStore.php';
require_once dirname(__DIR__) . '/lib/SqliteStore.php';
require_once dirname(__DIR__) . '/lib/StockService.php';
require_once dirname(__DIR__) . '/lib/PurchaseService.php';
require_once dirname(__DIR__) . '/lib/CustomerAccountService.php';

$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; } }

$tmp = sys_get_temp_dir() . '/dn_acct_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);
$store = SqliteStore::create($tmp);
$pdo   = $store->getPdo();

// ── A customer onboarded today, and a second one to leak into ───────────────
$store->save('ucrm_clients_cache.json', [
    ['id' => 4021, 'companyName' => 'Family Shoppers Ltd', 'userIdent' => 'DN-4021',
     'isLead' => false, 'isActive' => true, 'clientType' => 2,
     'street1' => 'Plot 14, Ntinda Road', 'city' => 'Kampala',
     'registrationDate' => '2026-09-11',
     'contacts' => [
        ['name' => 'Grace Nakato', 'email' => 'grace@familyshoppers.co.ug',
         'phone' => '+256772000111', 'types' => [['name' => 'Billing']]],
     ]],
    ['id' => 9999, 'firstName' => 'Other', 'lastName' => 'Customer',
     'isActive' => true, 'contacts' => [['email' => 'other@example.com', 'phone' => '+256700000000']]],
]);
$store->save('ucrm_services_cache.json', [
    ['id' => 88, 'clientId' => 4021, 'name' => 'Starlink Residential 50Mbps',
     'status' => 1, 'price' => 400000, 'currencyCode' => 'UGX', 'activeFrom' => '2026-09-11'],
    ['id' => 99, 'clientId' => 9999, 'name' => 'Someone else plan', 'status' => 1,
     'price' => 1, 'currencyCode' => 'UGX'],
]);
$store->save('ucrm_invoices_cache.json', [
    ['id' => 5001, 'clientId' => 4021, 'number' => 'INV-5001', 'total' => 2649000,
     'amountPaid' => 2649000, 'status' => 4, 'currencyCode' => 'UGX',
     'createdDate' => '2026-09-11', 'dueDate' => '2026-09-11',
     'items' => [['label' => 'Starlink Standard Kit']]],
    // Dated in the past on purpose: uCRM still says status 2 (unpaid) because
    // its overdue sweep has not run, and the due date is what actually makes
    // it overdue. Reading only the status field is how a customer sees
    // "pending" on a bill that is three weeks late.
    ['id' => 5002, 'clientId' => 4021, 'number' => 'INV-5002', 'total' => 400000,
     'amountPaid' => 0, 'status' => 2, 'currencyCode' => 'UGX',
     'createdDate' => '2026-09-11', 'dueDate' => '2026-09-01',
     'items' => [['label' => 'Starlink Residential 50Mbps — September']]],
    ['id' => 7777, 'clientId' => 9999, 'number' => 'INV-7777', 'total' => 999999,
     'amountPaid' => 0, 'status' => 2, 'currencyCode' => 'UGX', 'createdDate' => '2026-09-01'],
]);
$store->save('ucrm_invoice_payments_cache.json', [
    '5001' => ['payments' => [['id' => 900, 'amount' => 2649000, 'method' => 'Mobile Money',
                              'createdDate' => '2026-09-11', 'note' => 'MTN ref 88213']]],
    '7777' => ['payments' => [['id' => 901, 'amount' => 999999, 'method' => 'Cash',
                              'createdDate' => '2026-09-01', 'note' => "SOMEBODY ELSE'S MONEY"]]],
]);
$store->save('kyc_applications.json', [
    ['id' => 33, 'crm_client_id' => 4021, 'created_at' => '2026-09-10 09:14:00',
     'retailer_name' => 'Richard'],
]);

// Equipment, received properly so it carries a real cost.
$stock = StockService::fromStore($store, $tmp);
$stock->ensureTables();
$pur = new PurchaseService($pdo, $tmp, $stock);
$kit = (int)($stock->saveCategory(['title' => 'Starlink Standard Kit', 'sku' => 'SL-STD',
    'service_type' => 'starlink', 'track_mode' => 'serial'])['id'] ?? 0);
$pur->receive(['supplier' => 'Starlink Uganda', 'invoice_number' => 'SL-1', 'idem_key' => 'a1'],
    [['category_id' => $kit, 'quantity' => 1, 'unit_cost' => 2144704, 'serials' => ['KIT-FS-1']]],
    ['id' => 7, 'name' => 'Bhavin']);
$uid = (int)$pdo->query("SELECT id FROM stock_units WHERE serial_number='KIT-FS-1'")->fetchColumn();
$stock->install($uid, ['crm_client_id' => 4021, 'client_name' => 'Family Shoppers Ltd',
                       'crm_service_id' => 88, 'job_id' => 301], 7, 'Richard');

// The real table, created by migration 063 — not a stand-in, so a column
// that gains a NOT NULL is caught here rather than in production.
$pdo->exec("INSERT INTO customer_identities (client_id, email, local_part, status, created_at, updated_at)
            VALUES (4021, 'familyshoppers@dishnetuganda.com', 'familyshoppers', 'provisioned',
                    '2026-09-11T09:20:00Z', '2026-09-11T09:20:00Z')");

$svc = new CustomerAccountService($store, null, $tmp, $pdo);

// ── The whole account ───────────────────────────────────────────────────────
echo "\nThe account, assembled\n";
$a = $svc->account(4021);
is_($a !== null, 'the account exists');
t('the id a customer quotes on the phone', $a['account_ref'], 'DN-4021');
t('the name',    $a['name'], 'Family Shoppers Ltd');
t('a company',   $a['is_company'], true);
t('not a lead',  $a['is_lead'], false);
t('active',      $a['status'], 'active');
t('the email',   $a['email'], 'grace@familyshoppers.co.ug');
t('the phone',   $a['phone'], '+256772000111');
t('the service address', $a['address'], 'Plot 14, Ntinda Road, Kampala');

echo "\nService and onboarding\n";
t('one service', count($a['services']), 1);
t('active',      $a['services'][0]['status'], 'active');
t('at its price', $a['services'][0]['price'], 400000.0);
t('in shillings', $a['services'][0]['currency'], 'UGX');
t('signed up on the application',  $a['onboarding']['application_id'], 33);
t('by the person who sold it',     $a['onboarding']['sold_by'], 'Richard');
t('installed against the job',     $a['onboarding']['install_job_id'], 301);
t('by the engineer who did it',    $a['onboarding']['installed_by'], 'Richard');

echo "\nBilling, from the invoices uCRM issued\n";
t('two invoices',      count($a['invoices']), 2);
// Same issue date — the id breaks the tie, so the ordering is stable rather
// than dependent on which row the store happened to return first.
t('newest first',      $a['invoices'][0]['number'], 'INV-5002');
t('invoiced in total', $a['billing']['invoiced'], 3049000.0);
t('paid',              $a['billing']['paid'], 2649000.0);
t('outstanding',       $a['billing']['outstanding'], 400000.0);
t('in shillings',      $a['billing']['currency'], 'UGX');
t('the kit invoice reads as paid',  $a['invoices'][1]['status'], 'paid');
t('and the unpaid one as overdue on its due date, not on uCRM\'s status field',
  $a['invoices'][0]['status'], 'overdue');

echo "\nPayment history — which the portal had no way to show\n";
t('one payment',  count($a['payments']), 1);
t('its amount',   $a['payments'][0]['amount'], 2649000.0);
t('how they paid',$a['payments'][0]['method'], 'Mobile Money');
t('against which invoice', $a['payments'][0]['invoice'], 'INV-5001');
is_(strpos(json_encode($a['payments']), "SOMEBODY ELSE'S MONEY") === false,
    "THE OTHER CUSTOMER'S RECEIPT IS NOT IN HERE — the payment cache is keyed "
    . 'by invoice and holds everyone');

echo "\nEquipment\n";
t('one kit assigned', count($a['equipment']), 1);
t('by serial',        $a['equipment'][0]['serial'], 'KIT-FS-1');
t('installed',        $a['equipment'][0]['status'], 'installed');
t('against their service', $a['equipment'][0]['crm_service_id'], 88);
t('and staff can see what it cost', $a['equipment'][0]['purchase_cost'], 2144704.0);

echo "\nPortal\n";
t('the mailbox',  $a['portal']['identity_email'], 'familyshoppers@dishnetuganda.com');
t('provisioned',  $a['portal']['identity_status'], 'provisioned');
t('and they can be sent a code two ways', $a['portal']['can_login_by'], ['whatsapp', 'email']);

echo "\nNothing is missing\n";
t('no gaps on a complete account', $a['gaps'], []);

// ── The customer's view ─────────────────────────────────────────────────────
echo "\nWhat the customer may see\n";
$c = $svc->forCustomer(4021);
$json = json_encode($c);
t('their own account',    $c['account_ref'], 'DN-4021');
t('their equipment',      count($c['equipment']), 1);
t('by serial, so they can read it off the dish', $c['equipment'][0]['serial'], 'KIT-FS-1');
t('what they owe',        $c['billing']['outstanding'], 400000.0);
t('and what they have paid', $c['billing']['paid_total'], 2649000.0);
t('their invoices',       count($c['invoices']), 2);
t('their payment history',count($c['payments']), 1);

is_(strpos($json, '2144704') === false,
    'WHAT THE KIT COST US IS NOT IN IT — that is the margin on their own install');
is_(!array_key_exists('purchase_cost', $c['equipment'][0]), 'no cost field at all');
is_(!array_key_exists('purchase_ref', $c['equipment'][0]), 'nor our supplier invoice number');
is_(!array_key_exists('gaps', $c), 'nor our own notes on what is wrong with their account');
is_(!array_key_exists('contacts', $c), 'nor the raw contact records');
is_(strpos($json, 'SL-1') === false, 'nor the supplier reference');

// ── A thin account says what is thin about it ───────────────────────────────
echo "\nAn account that is not ready says why\n";
$store->save('ucrm_clients_cache.json', array_merge($store->load('ucrm_clients_cache.json'), [
    ['id' => 6100, 'firstName' => 'Walk', 'lastName' => 'In', 'isLead' => true,
     'isActive' => true, 'clientType' => 1, 'contacts' => []],
]));
$thin = $svc->account(6100);
is_($thin !== null, 'the account is found');
$g = implode(' | ', $thin['gaps']);
is_(strpos($g, 'No email') !== false,   'no email is named');
is_(strpos($g, 'No phone') !== false,   'no phone is named');
is_(strpos($g, 'No service') !== false, 'no service is named');
is_(strpos($g, 'No equipment') !== false, 'no equipment is named');
is_(strpos($g, 'lead') !== false,       'still-a-lead is named');
is_(strpos($g, 'log in') !== false,     'and that they cannot log in at all');
t('nothing invented for them — no billing', $thin['billing']['invoiced'], 0.0);
t('no currency guessed either',            $thin['billing']['currency'], '');

echo "\nAn unknown customer\n";
is_($svc->account(123456) === null, 'is null, not an empty account');
is_($svc->forCustomer(123456) === null, 'and null for the portal too');
is_($svc->account(0) === null, 'and id zero is refused');

exec('rm -rf ' . escapeshellarg($tmp));
echo "\n  {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
