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
// ── The invoice e-mail path (5.18.6): client 15 has a billing e-mail; ────
// invoice 901 is one uCRM can render, 903 and 905 ones it cannot. The PDF bytes are
// a fixed string, so a test can prove the attachment is THE file, not a file.
if ($path === '/clients/15') {
    out(['id' => 15, 'firstName' => 'Irene', 'lastName' => 'Invoiced',
         'isLead' => false, 'isActive' => true, 'accountBalance' => 0.0, 'currencyCode' => 'UGX',
         'contacts' => [['phone' => '+256700000015', 'email' => 'irene@example.test', 'isBilling' => true]]]);
}
if ($path === '/clients/15/services') out([]);
$fwInvoice = function (int $id, string $num): array {
    return ['id' => $id, 'clientId' => 15, 'number' => $num, 'status' => 1,
            'total' => 329000.0, 'amountPaid' => 0.0, 'amountToPay' => 329000.0,
            'maturityDate' => '2026-09-20T00:00:00+0300', 'currencyCode' => 'UGX',
            'items' => [['label' => 'Site : Irene Invoiced (000015) : Service Plan Residential (up to 400 Mbps) : Period 1 Oct 2026 – 31 Oct 2026',
                         'total' => 329000.0]]];
};
if ($path === '/invoices/901') out($fwInvoice(901, 'INV-000901'));
if ($path === '/invoices/903') out($fwInvoice(903, 'INV-000903'));
if ($path === '/invoices/905') out($fwInvoice(905, 'INV-000905'));
if ($path === '/invoices/901/pdf') {
    // Raw bytes, not JSON — what uCRM's PDF endpoint returns. Long enough to
    // clear getRawContent()'s 100-byte floor.
    file_put_contents($GLOBALS['stateFile'], json_encode($GLOBALS['state']));
    header('Content-Type: application/pdf');
    echo "%PDF-1.4\n%FAKE-UCRM-INVOICE-901\n" . str_repeat("0 0 obj << /Type /Fake >> endobj\n", 6) . "%%EOF\n";
    exit;
}
if ($path === '/invoices/903/pdf' || $path === '/invoices/905/pdf') out(['message' => 'uCRM has no PDF for this one'], 404);

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

// ── Just created, nothing active yet: client 13 — a sign-up in progress. ──
// And client 14, a subscriber with one active service, so a test can tell
// the two postures apart.
if ($path === '/clients/13') {
    out(['id' => 13, 'firstName' => 'Julius', 'lastName' => 'Newcomer',
         'isLead' => false, 'isActive' => true, 'accountBalance' => 0.0, 'currencyCode' => 'ZCURRZ']);
}
if ($path === '/clients/13/services') out([]);
if ($path === '/clients/14') {
    out(['id' => 14, 'firstName' => 'Grace', 'lastName' => 'Subscriber',
         'isLead' => false, 'isActive' => true, 'accountBalance' => 0.0, 'currencyCode' => 'ZCURRZ']);
}
if ($path === '/clients/14/services') {
    out([['id' => 514, 'name' => 'Residential Lite', 'servicePlanName' => 'Residential Lite',
          'totalPrice' => 249000.0, 'status' => 1, 'activeTo' => '2027-01-01T00:00:00+0000']]);
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

// ── The catalogue, as the live install had it on 15 Sep ──────────────────
// Two plans, spelled one way as service plans and another as products: the
// operator mirrored each monthly plan into the Products tab so a quotation
// can carry it as a line. The tool must drop those mirrors from HARDWARE.
if ($path === '/service-plans') {
    out([['id' => 1, 'name' => 'Starlink Residential Lite ( up to 100 Mbps)', 'price' => 249000, 'isActive' => true,
          'periodMonths' => 1, 'downloadSpeed' => 100, 'uploadSpeed' => 20],
         ['id' => 2, 'name' => 'Residential (up to 400 Mbps)', 'price' => 329000, 'isActive' => true, 'periodMonths' => 1]]);
}
if ($path === '/products') {
    out([['id' => 10, 'name' => 'Starlink Mini Kit', 'price' => 2249000, 'unit' => 'pc'],
         ['id' => 11, 'name' => 'Professional Installation', 'price' => 150000, 'unit' => 'pc'],
         ['id' => 12, 'name' => 'Residential Lite (up to 100 Mbps)', 'price' => 249000, 'unit' => 'pc'],
         ['id' => 13, 'name' => 'Residential (up to 400 Mbps)', 'price' => 329000, 'unit' => 'pc']]);
}

// Identity resolution probes uCRM as a shortlist; an empty answer sends it
// back to the local index, which is where this test's identity comes from.
if ($path === '/clients') out([]);

out([], 404);
