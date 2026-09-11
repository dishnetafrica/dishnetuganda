<?php
declare(strict_types=1);
/**
 * test_portal_account.php — the customer sees their own account, and only theirs.
 *
 * The portal could answer "who am I" and "do I owe anything". It could not
 * answer what equipment is on the roof, what had ever been paid, or when the
 * service started: those lived in four other places and it could reach one.
 *
 * Three properties are being held here, and the first two are the ones that
 * would matter at three in the morning.
 *
 *   THE CLIENT ID COMES FROM THE TOKEN. Every one of these endpoints
 *   resolves the customer from the authenticated claims. An id read off the
 *   query string would be every customer's account behind one valid login,
 *   which is the failure the brief asks about by name.
 *
 *   COST NEVER CROSSES. What a kit cost DishNet is the margin on this
 *   customer's own installation.
 *
 *   AND THE SUDAN DEFAULT IS GONE. app_me answered 'Juba' as the service
 *   location for any customer without an address — not a blank, a different
 *   country, presented as fact.
 */
require_once dirname(__DIR__) . '/lib/StoreInterface.php';
require_once dirname(__DIR__) . '/lib/JsonStore.php';
require_once dirname(__DIR__) . '/lib/SqliteStore.php';
require_once dirname(__DIR__) . '/lib/CustomerAccountService.php';

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; } }

$api = (string)file_get_contents($root . '/includes/api/api_customer_app.php');

/** The body of one `if ($act === 'x' ...)` handler. */
function handler(string $api, string $act): string {
    $p = strpos($api, "\$act === '{$act}'");
    if ($p === false) return '';
    // To the next top-level ACTION banner, which is how this file separates them.
    $end = strpos($api, "\n// ═", $p);
    return substr($api, $p, ($end === false ? 3000 : $end - $p));
}

// ── The endpoints exist ─────────────────────────────────────────────────────
echo "\nThe portal can ask the three questions it could not ask\n";
foreach (['app_account' => 'the whole account',
          'app_equipment' => 'what is installed at their premises',
          'app_payments' => 'everything they have ever paid'] as $act => $what) {
    is_(strpos($api, "\$act === '{$act}'") !== false, "{$act} — {$what}");
}

// ── Scoped to the token ─────────────────────────────────────────────────────
echo "\nThe customer is taken from the token, never from the request\n";
foreach (['app_account', 'app_equipment', 'app_payments'] as $act) {
    $h = handler($api, $act);
    is_($h !== '', "{$act} has a handler");
    is_(strpos($h, 'ca_require_auth(') !== false, "{$act} requires a valid token");
    is_(strpos($h, 'ca_resolve_active_client_id($claims') !== false,
        "{$act} resolves the client from the claims");
    // The whole point. A client id off the query string is every customer's
    // account behind one login.
    is_(!preg_match('/\$_GET\s*\[\s*[\'"](client_?id|id)[\'"]\s*\]/i', $h),
        "{$act} does not read a client id from the query string");
    is_(!preg_match('/\$body\s*\[\s*[\'"]client_?id[\'"]\s*\]/i', $h),
        "{$act} does not read one from the body either");
}

// ── The payload is the customer's view, not the staff one ───────────────────
echo "\nIt serves forCustomer(), not account()\n";
foreach (['app_account', 'app_equipment', 'app_payments'] as $act) {
    $h = handler($api, $act);
    is_(strpos($h, '->forCustomer(') !== false, "{$act} builds the customer's view");
    is_(!preg_match('/->account\s*\(/', $h),
        "{$act} never serves the staff view, which carries our cost");
}

// ── And prove it on real data, not on the source ────────────────────────────
echo "\nWhat actually comes back\n";
$tmp = sys_get_temp_dir() . '/dn_portal_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);
$store = SqliteStore::create($tmp);
$pdo   = $store->getPdo();
$store->save('ucrm_clients_cache.json', [
    ['id' => 4021, 'companyName' => 'Family Shoppers Ltd', 'userIdent' => 'DN-4021',
     'isActive' => true, 'street1' => 'Plot 14, Ntinda Road', 'city' => 'Kampala',
     'contacts' => [['email' => 'grace@familyshoppers.co.ug', 'phone' => '+256772000111']]],
    ['id' => 9999, 'firstName' => 'Somebody', 'lastName' => 'Else', 'isActive' => true,
     'contacts' => [['email' => 'else@example.com', 'phone' => '+256700000000']]],
]);
$store->save('ucrm_invoices_cache.json', [
    ['id' => 5001, 'clientId' => 4021, 'number' => 'INV-5001', 'total' => 2649000,
     'amountPaid' => 2649000, 'status' => 4, 'currencyCode' => 'UGX', 'createdDate' => '2026-09-11'],
    ['id' => 7777, 'clientId' => 9999, 'number' => 'INV-7777', 'total' => 50000,
     'amountPaid' => 50000, 'status' => 4, 'currencyCode' => 'UGX', 'createdDate' => '2026-09-01'],
]);
$store->save('ucrm_invoice_payments_cache.json', [
    '5001' => ['payments' => [['id' => 900, 'amount' => 2649000, 'method' => 'Mobile Money',
                              'createdDate' => '2026-09-11']]],
    '7777' => ['payments' => [['id' => 901, 'amount' => 50000, 'method' => 'Cash',
                              'createdDate' => '2026-09-01', 'note' => 'NOT THEIRS']]],
]);
$pdo->exec("INSERT INTO stock_categories (title, sku, service_type, track_mode, created_at)
            VALUES ('Starlink Standard Kit','SL-STD','starlink','serial','2026-09-11')");
$cat = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO stock_units (category_id, serial_number, status, location_type,
              crm_client_id, purchase_cost, purchase_ref, created_at, updated_at)
            VALUES ({$cat}, 'KIT-FS-1', 'installed', 'customer', 4021, 2144704, 'SL-2026-0041',
                    '2026-09-11 10:00:00', '2026-09-11 10:00:00')");
$pdo->exec("INSERT INTO stock_units (category_id, serial_number, status, location_type,
              crm_client_id, purchase_cost, created_at, updated_at)
            VALUES ({$cat}, 'KIT-OTHER', 'installed', 'customer', 9999, 2144704,
                    '2026-09-01 10:00:00', '2026-09-01 10:00:00')");

$svc = new CustomerAccountService($store, null, $tmp, $pdo);
$me  = $svc->forCustomer(4021);
$json = json_encode($me);

t('their account reference', $me['account_ref'], 'DN-4021');
t('their address, which is in Kampala', $me['address'], 'Plot 14, Ntinda Road, Kampala');
is_(strpos($json, 'Juba') === false,
    "NOT 'Juba' — the Sudan default the portal used to show as a service location");
t('their own kit', count($me['equipment']), 1);
t('by serial', $me['equipment'][0]['serial'], 'KIT-FS-1');
is_(strpos($json, 'KIT-OTHER') === false, "and not the other customer's kit");
t('their payment', count($me['payments']), 1);
is_(strpos($json, 'NOT THEIRS') === false, "nor the other customer's receipt");
t('their invoice', count($me['invoices']), 1);
is_(strpos($json, 'INV-7777') === false, "nor the other customer's invoice");
is_(strpos($json, '2144704') === false, 'and never what the kit cost us');
is_(strpos($json, 'SL-2026-0041') === false, 'nor which supplier it came from');

exec('rm -rf ' . escapeshellarg($tmp));
echo "\n  {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
