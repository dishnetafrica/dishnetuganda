<?php
declare(strict_types=1);
/**
 * ████ FAKE uCRM — TEST ONLY ████
 * Just enough of the uCRM API for quotation branding: organizations and
 * clients. Shaped from what the live instance actually returns (see
 * tools/org_probe.php output), including the fields nothing was reading —
 * bank details, TIN, logo.
 *
 * Extended for DPO Pay with invoices, payments and payment methods, so the
 * settlement path can be exercised through the REAL CrmApiClient — including
 * its own duplicate guard — and so a test can COUNT how many uCRM payments a
 * scenario actually created. That count is the whole point of the duplicate
 * and race cases.
 *
 * /__test/scenario?name=... reshapes it: uganda | two_orgs | no_phone |
 * no_org | unreachable | payments_down
 * /__test/payments returns every payment created, for counting.
 * /__test/reset clears them between cases.
 *
 *     php -S 127.0.0.1:9699 tests/fixtures/fake_ucrm_server.php
 */
$stateFile = sys_get_temp_dir() . '/fake_ucrm_' . md5(__FILE__ . ($_SERVER['SERVER_PORT'] ?? '')) . '.json';
$state = is_file($stateFile) ? (json_decode((string)file_get_contents($stateFile), true) ?: []) : [];
$state += ['scenario' => 'uganda', 'payments' => [], 'pay_seq' => 0,
           'clients' => [], 'client_seq' => 0, 'patches' => []];

function fu_out($data, int $http = 200): void
{
    http_response_code($http);
    header('Content-Type: application/json');
    echo json_encode($data);
    file_put_contents($GLOBALS['stateFile'], json_encode($GLOBALS['state']));
    exit;
}

$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';
parse_str((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY), $q);

if ($path === '/__test/scenario') {
    $state['scenario'] = (string)($q['name'] ?? 'uganda');
    fu_out(['scenario' => $state['scenario'], 'marker' => 'FAKE-UCRM-TEST']);
}
if ($path === '/__test/state') fu_out($state + ['marker' => 'FAKE-UCRM-TEST']);
if ($path === '/__test/payments') fu_out(['payments' => $state['payments'], 'count' => count($state['payments'])]);
if ($path === '/__test/reset') { $state['payments'] = []; $state['pay_seq'] = 0;
    $state['clients'] = []; $state['client_seq'] = 0; $state['patches'] = [];
    fu_out(['reset' => true]); }
if ($path === '/__test/clients') fu_out(['clients' => $state['clients'] ?? [],
    'count' => count($state['clients'] ?? []), 'patches' => $state['patches'] ?? []]);

// ── Invoices, payments and payment methods (DPO Pay) ────────────────────
//
// Invoice ids ARE the scenario here, so a test names the case it wants by the
// invoice it asks to pay.
if (preg_match('#^/invoices/(\d+)$#', $path, $m)) {
    $id = (int)$m[1];
    $inv = ['id' => $id, 'clientId' => 7, 'number' => 'INV-2026-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT),
            'total' => 299000.0, 'amountPaid' => 0.0, 'currencyCode' => 'UGX', 'status' => 1,
            'createdDate' => '2026-09-01', 'dueDate' => '2026-09-15',
            'items' => [['label' => 'DishNet Home - Monthly Internet Service']]];
    switch ($id) {
        case 126: $inv['amountPaid'] = 100000.0; $inv['status'] = 2; break;   // part-paid elsewhere
        case 127: $inv['amountPaid'] = 299000.0; $inv['status'] = 4; break;   // already settled
        case 128: $inv['status'] = 9; break;                                   // a blocked status
        case 129: $inv['clientId'] = 999; break;                               // someone else's
        case 130: $inv['currencyCode'] = 'KES'; break;                         // a currency we do not take
        case 131: $inv['currencyCode'] = ''; break;                            // no currency recorded
        case 999: fu_out(['error' => 'FAKE-UCRM-TEST: no such invoice'], 404);
    }
    fu_out($inv);
}
if ($path === '/payment-methods') {
    fu_out([['id' => '6efe0fa8-36b2-4dd1-b049-427bffc7d369', 'name' => 'Cash'],
            ['id' => '4145b5f5-3bbc-45e3-8fc5-9cda970c62fb', 'name' => 'Bank Transfer']]);
}
if ($path === '/payments' || strpos($path, '/payments?') === 0) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        // The one failure a settlement must survive without losing the money.
        if ($state['scenario'] === 'payments_down') {
            fu_out(['error' => 'FAKE-UCRM-TEST: payments unavailable'], 500);
        }
        $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
        $state['pay_seq']++;
        $p = ['id' => 7000 + $state['pay_seq'], 'clientId' => (int)($body['clientId'] ?? 0),
              'amount' => (float)($body['amount'] ?? 0), 'note' => (string)($body['note'] ?? ''),
              'methodId' => (string)($body['methodId'] ?? ''),
              'currencyCode' => (string)($body['currencyCode'] ?? ''),
              'createdDate' => gmdate('Y-m-d')];
        $state['payments'][] = $p;
        fu_out($p);
    }
    // createPaymentSafe scans this before creating. Returning what we hold is
    // what makes its duplicate guard real rather than simulated.
    fu_out($state['payments']);
}

$UG = [
    'id' => 1, 'name' => 'DishNet Africa Limited', 'selected' => true,
    'phone' => '+256705993348', 'email' => 'accounts@dishnetuganda.com',
    'website' => 'https://dishnetuganda.com/',
    'street1' => 'The Accacia Mall Office No TT06', 'street2' => '',
    'city' => 'Kampala', 'zipCode' => '', 'countryId' => 247,
    'registrationNumber' => 'REG-TEST-0001', 'taxId' => 'TIN-TEST-0001',
    'bankAccountName' => 'DISHNET AFRICA LIMITED',
    'bankAccountField1' => 'TEST-UGX-0000000001',
    'bankAccountField2' => 'TESTBANKSWIFT',
    'logoUrl' => 'https://example.invalid/logo.png',
];
$SS = ['id' => 7, 'name' => 'FTTH Project', 'selected' => false,
       'phone' => '+211920000000', 'email' => 'info@dishnetafrica.com',
       'street1' => 'Juba', 'city' => 'Juba', 'countryId' => 200];

$scenario = (string)$state['scenario'];
if ($scenario === 'unreachable') fu_out(['error' => 'FAKE-UCRM-TEST: down'], 500);

if ($path === '/organizations') {
    if ($scenario === 'no_org')   fu_out([]);
    // The trap: the wrong company first in the list. Taking organizations[0]
    // would put Juba's letterhead on a Kampala quote.
    if ($scenario === 'two_orgs') fu_out([$SS, $UG]);
    if ($scenario === 'no_phone') { $o = $UG; $o['phone'] = ''; $o['name'] = ''; fu_out([$o]); }
    if ($scenario === 'clients_no_country' || $scenario === 'fresh_install') {
        fu_out([['id' => 1, 'name' => 'DishNet Africa Limited',
                 'selected' => true, 'countryId' => 247]]);
    }
    // No clients AND two organizations: whichever is picked is a guess.
    if ($scenario === 'fresh_two_orgs') fu_out([$SS, $UG]);
    fu_out([$UG]);
}
// ── Lead sync (Phase 2): create, search and patch clients ───────────────
//
// Clients created through here are REMEMBERED, so a test can count them. The
// count is the point: idempotence means a retry must not add a second one.
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($path === '/clients' && $method === 'POST') {
    if ($scenario === 'crm_down') fu_out(['message' => 'FAKE: uCRM unavailable'], 503);
    $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
    // The real uCRM refuses unknown fields with a 422. 5.18.11 shipped a
    // payload carrying 'description' and every create failed; the fake was
    // made strict afterwards so a test catches that class of mistake here.
    $allowed = ['clientType','isLead','firstName','lastName','companyName','organizationId',
                'countryId','stateId','street1','street2','city','zipCode','note','username',
                'contacts','registrationNumber','taxId'];
    foreach (array_keys($body) as $k) {
        if (!in_array($k, $allowed, true)) {
            fu_out(['message' => 'FAKE: this field is not allowed: ' . $k], 422);
        }
    }
    if (($body['organizationId'] ?? null) === null) {
        fu_out(['message' => 'FAKE: organizationId is required'], 422);
    }
    $state['client_seq'] = (int)($state['client_seq'] ?? 0) + 1;
    $id = 500 + $state['client_seq'];
    $row = $body + ['id' => $id];
    $state['clients'][] = $row;
    fu_out($row, 201);
}

if (preg_match('#^/clients/(\d+)$#', $path, $m) && $method === 'PATCH') {
    $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $state['patches'][] = ['id' => (int)$m[1]] + $body;
    fu_out(['id' => (int)$m[1]] + $body);
}

// Search. 'dup_phone' puts two clients on one number — the case that must
// never be resolved by guessing.
if ($path === '/clients' || strpos($path, '/clients?') === 0) {
    if ($method === 'GET') {
        if ($scenario === 'crm_down') fu_out(['message' => 'FAKE: uCRM unavailable'], 503);
        $needle = (string)($q['phone'] ?? $q['search'] ?? '');
        // Only the SEARCH is down. The dangerous case: a lookup that fails
        // while the caller already knows its defaults, so nothing else stops
        // it concluding "nobody has this number" and creating a duplicate.
        if ($scenario === 'lookup_down' && $needle !== '') {
            fu_out(['message' => 'FAKE: search unavailable'], 503);
        }
        if ($needle === '') {
            // The sample read used to establish organizationId / countryId.
            // 'clients_no_country' is the live shape org_probe found: clients
            // that carry an organization but no country of their own.
            if ($scenario === 'clients_no_country') {
                fu_out([['id' => 9, 'organizationId' => 1], ['id' => 8, 'organizationId' => 1]]);
            }
            if ($scenario === 'fresh_install' || $scenario === 'fresh_two_orgs') fu_out([]);
            fu_out([
                ['id' => 9, 'organizationId' => 1, 'countryId' => 220],
                ['id' => 8, 'organizationId' => 1, 'countryId' => 220],
                ['id' => 7, 'organizationId' => 7, 'countryId' => 220],
            ]);
        }
        if ($scenario === 'dup_phone') {
            fu_out([
                ['id' => 41, 'contacts' => [['phone' => '+256700000001']]],
                ['id' => 42, 'contacts' => [['phone' => '0700000001']]],
            ]);
        }
        if ($scenario === 'known_phone') {
            fu_out([['id' => 77, 'contacts' => [['phone' => '+256700000001']]]]);
        }
        // A fuzzy hit that is NOT actually this number: the caller must
        // confirm the digits rather than trusting the endpoint.
        if ($scenario === 'fuzzy_miss') {
            fu_out([['id' => 88, 'contacts' => [['phone' => '+256799999999']]]]);
        }
        fu_out([]);
    }
}

if (preg_match('#^/clients/(\d+)$#', $path, $m)) {
    // client 9 belongs to the Uganda org; client 8 to the other one
    $id = (int)$m[1];
    fu_out(['id' => $id, 'organizationId' => $id === 8 ? 7 : 1]);
}
// The product catalogue the assistant quotes from.
//
// 'catalogue_five' and 'catalogue_three' are the two readings taken from the
// live Uganda uCRM roughly an hour apart: the two package products were in
// the first and not the second. Note that Standard Kit is 2,649,000 while
// Standard Package is 2,749,000 — the figure the Hardware screen was showing
// against the KIT.
if ($path === '/products' || strpos($path, '/products?') === 0) {
    $MINI_KIT  = ['id' => 1, 'name' => 'Starlink Mini Kit',        'price' => 2249000, 'taxable' => false];
    $STD_KIT   = ['id' => 2, 'name' => 'Starlink Standard Kit',    'price' => 2649000, 'taxable' => false];
    $INSTALL   = ['id' => 3, 'name' => 'Professional Installation','price' =>  150000, 'taxable' => false];
    $MINI_PKG  = ['id' => 4, 'name' => 'Starlink Mini Package',    'price' => 2399000, 'taxable' => false];
    $STD_PKG   = ['id' => 5, 'name' => 'Starlink Standard Package','price' => 2749000, 'taxable' => false];

    if ($scenario === 'catalogue_three') fu_out([$MINI_KIT, $STD_KIT, $INSTALL]);
    if ($scenario === 'catalogue_repriced') {
        $k = $STD_KIT; $k['price'] = 2749000;
        fu_out([$MINI_KIT, $k, $INSTALL]);
    }
    if ($scenario === 'catalogue_taxable') {
        foreach ([&$MINI_KIT, &$STD_KIT, &$INSTALL] as &$_p) { $_p['taxable'] = true; }
        unset($_p);
        fu_out([$MINI_KIT, $STD_KIT, $INSTALL]);
    }
    fu_out([$MINI_KIT, $STD_KIT, $INSTALL, $MINI_PKG, $STD_PKG]);
}

fu_out(['error' => 'FAKE-UCRM-TEST: path not simulated: ' . $path], 404);
