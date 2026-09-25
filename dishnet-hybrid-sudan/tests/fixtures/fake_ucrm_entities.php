<?php
/**
 * fake_ucrm_entities.php — just enough uCRM for webhook.php's entity re-reads.
 *
 * Since 5.18.37 every webhook handler re-reads its entity from uCRM by id and
 * refuses to act on a body uCRM does not know. This fixture serves exactly
 * those reads for a seeded estate, logs every request so a test can prove
 * what was and was not fetched, and answers 404 for anything unknown — which
 * is what a forged id gets from the real uCRM.
 *
 *   php -S 127.0.0.1:<port> tests/fixtures/fake_ucrm_entities.php
 *
 *   GET /__test/requests      every request seen so far
 *   GET /__test/reset         forget them
 *   GET /__test/unreachable?on=1|0   answer 503 to every entity read (an outage)
 *
 * Seeded: client 7 (phone +256772123456), payment 501 on client 7 for
 * invoice 301 (UGX 50,000), invoice 301, service 41, quote 21, ticket 11.
 * Payment 777 is a deleted one: 404, like the real thing.
 */
$stateFile = sys_get_temp_dir() . '/fake_ucrm_entities_' . ($_SERVER['SERVER_PORT'] ?? '0') . '.json';
$state = is_file($stateFile) ? (json_decode((string)file_get_contents($stateFile), true) ?: []) : [];
$state += ['requests' => [], 'unreachable' => false];
function fe_save(): void { file_put_contents($GLOBALS['stateFile'], json_encode($GLOBALS['state'])); }
function fe_out($data, int $http = 200): void {
    http_response_code($http); header('Content-Type: application/json'); echo json_encode($data); exit;
}
$uri    = (string)($_SERVER['REQUEST_URI'] ?? '');
$path   = parse_url($uri, PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = preg_replace('#^/api/v[0-9.]+#', '', $path);   // the client sends /api/v2.1/…

if ($path === '/__test/requests') fe_out(['requests' => $state['requests'], 'count' => count($state['requests'])]);
if ($path === '/__test/reset')    { $state['requests'] = []; $state['unreachable'] = false; fe_save(); fe_out(['reset' => true]); }
if ($path === '/__test/unreachable') { $state['unreachable'] = (($_GET['on'] ?? '1') === '1'); fe_save(); fe_out(['unreachable' => $state['unreachable']]); }

$state['requests'][] = ['method' => $method, 'path' => $path, 'at' => microtime(true)];
fe_save();
if ($state['unreachable']) fe_out(['error' => 'FAKE-UCRM-ENTITIES: outage'], 503);

$client7 = ['id' => 7, 'firstName' => 'Test', 'lastName' => 'Customer', 'clientType' => 1, 'isLead' => false, 'isArchived' => false,
            'organizationId' => 1, 'accountBalance' => -50000,
            'contacts' => [['id' => 70, 'name' => 'Test Customer', 'phone' => '+256772123456', 'email' => 'customer@example.test', 'isBilling' => true]]];
$invoice301 = ['id' => 301, 'clientId' => 7, 'number' => 'INV-0301', 'status' => 1, 'total' => 50000, 'amountPaid' => 0,
               'currencyCode' => 'UGX', 'dueDate' => gmdate('c', time() + 7 * 86400), 'createdDate' => gmdate('c')];
$payment501 = ['id' => 501, 'clientId' => 7, 'amount' => 50000, 'currencyCode' => 'UGX', 'methodId' => '6efe0fa8-36b2-4dd1-b049-427bffc7d369',
               'method' => 2, 'note' => 'fake payment', 'createdDate' => gmdate('c'), 'paymentCovers' => [['invoiceId' => 301, 'amount' => 50000]]];
$service41  = ['id' => 41, 'clientId' => 7, 'status' => 1, 'name' => 'Starlink Standard', 'servicePlanId' => 3, 'price' => 250000];
$quote21    = ['id' => 21, 'clientId' => 7, 'number' => 'Q-0021', 'status' => 1, 'total' => 1200000, 'currencyCode' => 'UGX'];
$ticket11   = ['id' => 11, 'clientId' => 7, 'subject' => 'Fake ticket', 'status' => 0];

$routes = [
    '#^/clients/7$#'                     => $client7,
    '#^/invoices/301$#'                  => $invoice301,
    '#^/billing/invoices/301$#'          => $invoice301,
    '#^/payments/501$#'                  => $payment501,
    '#^/billing/payments/501$#'          => $payment501,
    '#^/clients/services/41$#'           => $service41,
    '#^/quotes/21$#'                     => $quote21,
    '#^/billing/quotes/21$#'             => $quote21,
    '#^/ticketing/tickets/11$#'          => $ticket11,
    '#^/clients/7/invoices$#'            => [$invoice301],
    '#^/clients/7/payments$#'            => [$payment501],
    '#^/payment-methods$#'               => [['id' => '6efe0fa8-36b2-4dd1-b049-427bffc7d369', 'name' => 'Cash', 'type' => 'cash']],
];
if ($method === 'GET') {
    foreach ($routes as $re => $body) {
        if (preg_match($re, $path)) fe_out($body);
    }
    if (preg_match('#^/(?:billing/)?invoices(?:\?|$)#', $path)) fe_out([$invoice301]);
    if (preg_match('#^/payments/\d+/pdf$#', $path))  fe_out(['error' => 'no pdf in the fake'], 404);
    fe_out(['error' => 'FAKE-UCRM-ENTITIES: not found ' . $path], 404);
}
// Writes are recorded above and acknowledged; nothing is stored.
fe_out(['ok' => true, 'method' => $method, 'path' => $path]);
