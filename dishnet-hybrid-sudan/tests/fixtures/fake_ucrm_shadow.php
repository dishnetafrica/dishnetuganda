<?php
declare(strict_types=1);
/**
 * ████ FAKE uCRM (SHADOW AUDIT) — TEST ONLY ████
 *
 * Serves exactly the endpoints the B3.4 shadow path and the legacy readers
 * touch, and RECORDS EVERY REQUEST. The request log is the evidence: it is
 * how "no extra uCRM read when the flag is off", "exactly these reads when it
 * is on" and "never another customer's record" stop being claims.
 *
 * Client 7 is ours. Client 21 exists and is somebody else's; every value on
 * it is a canary, so a test can prove it was never read AND never printed.
 *
 *     php -S 127.0.0.1:PORT tests/fixtures/fake_ucrm_shadow.php
 */
$stateFile = sys_get_temp_dir() . '/fake_ucrm_shadow_' . ($_SERVER['SERVER_PORT'] ?? '0') . '.json';
$state = is_file($stateFile) ? (json_decode((string)file_get_contents($stateFile), true) ?: []) : [];
$state += ['requests' => []];

function out($data, int $http = 200): void
{
    http_response_code($http);
    header('Content-Type: application/json');
    file_put_contents($GLOBALS['stateFile'], json_encode($GLOBALS['state']));
    echo json_encode($data);
    exit;
}

$uri  = (string)($_SERVER['REQUEST_URI'] ?? '');
$path = parse_url($uri, PHP_URL_PATH) ?: '';
parse_str((string)parse_url($uri, PHP_URL_QUERY), $q);

if ($path === '/__test/state')    out(['marker' => 'FAKE-UCRM-SHADOW']);
if ($path === '/__test/requests') out(['requests' => $state['requests'],
                                       'count' => count($state['requests'])]);
if ($path === '/__test/reset')    { $state['requests'] = []; out(['reset' => true]); }

// Everything else is a real read, and every real read is recorded verbatim.
$state['requests'][] = $uri;

// ── Ours: client 7 ──────────────────────────────────────────────────────
// Distinctive values throughout, so any of them appearing in a log is
// unmistakable rather than a coincidence.
if ($path === '/clients/7') {
    out(['id' => 7, 'firstName' => 'Ours', 'lastName' => 'Customer',
         'isLead' => false, 'isActive' => true,
         'accountBalance' => 987654.32, 'currencyCode' => 'ZCURRZ']);
}
if ($path === '/clients/7/services') {
    out([['id' => 501, 'name' => 'ZSVCNAMEZ', 'servicePlanName' => 'ZPLANNAMEZ',
          'totalPrice' => 777333.99, 'currencyCode' => 'ZCURRZ',
          // 42 is not in the confirmed uCRM status map, so the tool must say
          // 'unknown' and the comparison must report unmapped_status.
          'status' => 42, 'activeTo' => '2033-03-03T00:00:00+0000']]);
}
if ($path === '/invoices' || strpos($path, '/invoices') === 0) {
    $cid = (int)($q['clientId'] ?? 0);
    if ($cid === 21) {
        out([['id' => 9021, 'number' => 'INV-ZOTHERCUSTOMERZ', 'clientId' => 21,
              'total' => 8675309.0, 'amountPaid' => 0.0, 'amountToPay' => 8675309.0,
              'createdDate' => '2027-01-01T00:00:00+0000',
              'dueDate' => '2027-02-01T00:00:00+0000', 'currencyCode' => 'ZCURRZ']]);
    }
    if ($cid !== 7) out([]);
    // The legacy reader asks for the unpaid set first; the shadow asks for the
    // most recent five. Deliberately different invoices, which is the real
    // divergence B3.4 exists to measure.
    if (isset($q['statuses'])) {
        out([['id' => 9001, 'number' => 'INV-ZUNPAIDZ', 'invoiceNumber' => 'INV-ZUNPAIDZ',
              'clientId' => 7, 'total' => 222111.0, 'amountPaid' => 0.0,
              'amountToPay' => 222111.0,
              'createdDate' => '2030-01-01T00:00:00+0000',
              'dueDate' => '2030-02-01T00:00:00+0000', 'currencyCode' => 'ZCURRZ']]);
    }
    out([['id' => 9002, 'number' => 'INV-ZLATESTZ', 'invoiceNumber' => 'INV-ZLATESTZ',
          'clientId' => 7, 'total' => 555111.22, 'amountPaid' => 555111.22,
          'amountToPay' => 0.0,
          'createdDate' => '2031-06-01T00:00:00+0000',
          'dueDate' => '2031-07-04T00:00:00+0000', 'currencyCode' => 'ZCURRZ']]);
}
if ($path === '/payments' || strpos($path, '/payments') === 0) {
    $cid = (int)($q['clientId'] ?? 0);
    if ($cid !== 7) out([]);
    out([['id' => 8001, 'clientId' => 7, 'amount' => 333222.11,
          'createdDate' => '2029-02-14T00:00:00+0000', 'methodName' => 'ZPAYMETHODZ']]);
}

// ── Somebody else: client 21. Nothing may ever read this. ───────────────
if ($path === '/clients/21') {
    out(['id' => 21, 'firstName' => 'ZOTHERCUSTOMERZ', 'lastName' => 'Neighbour',
         'isLead' => false, 'isActive' => true,
         'accountBalance' => 8675309.0, 'currencyCode' => 'ZCURRZ']);
}
if ($path === '/clients/21/services') {
    out([['id' => 502, 'name' => 'ZOTHERCUSTOMERZ plan', 'servicePlanName' => 'ZOTHERPLANZ',
          'totalPrice' => 8675309.0, 'status' => 1,
          'activeTo' => '2035-05-05T00:00:00+0000']]);
}

// Identity resolution probes uCRM as a shortlist; an empty answer sends it
// back to the local index, which is where this test's identity comes from.
if ($path === '/clients') out([]);

out([], 404);
