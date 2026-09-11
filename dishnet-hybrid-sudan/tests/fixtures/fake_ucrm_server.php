<?php
declare(strict_types=1);
/**
 * ████ FAKE uCRM — TEST ONLY ████
 * Just enough of the uCRM API for quotation branding: organizations and
 * clients. Shaped from what the live instance actually returns (see
 * tools/org_probe.php output), including the fields nothing was reading —
 * bank details, TIN, logo.
 *
 * /__test/scenario?name=... reshapes it: uganda | two_orgs | no_phone |
 * no_org | unreachable
 *
 *     php -S 127.0.0.1:9699 tests/fixtures/fake_ucrm_server.php
 */
$stateFile = sys_get_temp_dir() . '/fake_ucrm_' . md5(__FILE__ . ($_SERVER['SERVER_PORT'] ?? '')) . '.json';
$state = is_file($stateFile) ? (json_decode((string)file_get_contents($stateFile), true) ?: []) : [];
$state += ['scenario' => 'uganda'];

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
    fu_out([$UG]);
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
