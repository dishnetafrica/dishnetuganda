<?php
declare(strict_types=1);

/**
 * ████████ FAKE WEAF EFRIS GATEWAY — TEST ONLY ████████
 *
 * Simulates weafcompany.com's EFRIS REST gateway with the exact envelope and
 * response shapes from their official Postman collection (WEAF_EFRIS_WEB_API):
 * {status:{returnCode,returnMessage}, data:{...}}, bearer auth, TIN in the
 * path. Every fiscal value is TESTWEAF-/TESTAF-/TESTCN- prefixed so nothing
 * can be mistaken for a real document.
 *
 *     php -S 127.0.0.1:9499 tests/fixtures/fake_weaf_server.php
 *
 * Valid bearer: TEST-WEAF-TOKEN · configured TIN: 1015264035
 * Triggers: itemCode/goodsName containing REJECT → processing error;
 *           search-taxpayer TIN starting 999 → 404 not found;
 *           decrease beyond the stock the server has seen → 400.
 */

header('X-Fake-Weaf: TEST-ONLY');

$stateFile = sys_get_temp_dir() . '/fake_weaf_state_' . md5(__FILE__ . ($_SERVER['SERVER_PORT'] ?? '')) . '.json';
$state = is_file($stateFile) ? (json_decode((string)file_get_contents($stateFile), true) ?: []) : [];
$state += ['seq' => 0, 'invoices' => [], 'stock' => [], 'cn_seq' => 0];

function fw_reply(int $http, string $code, string $msg, $data = []): void
{
    http_response_code($http);
    echo json_encode(['status' => ['returnCode' => $code, 'returnMessage' => $msg], 'data' => $data]);
    file_put_contents($GLOBALS['stateFile'], json_encode($GLOBALS['state']));
    exit;
}

$path   = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';
$body   = json_decode((string)file_get_contents('php://input'), true) ?: [];
$auth   = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');

if ($path === '/api/v1/auth/validate-token') {
    if (($body['token'] ?? '') === 'TEST-WEAF-TOKEN') {
        fw_reply(200, '00', 'SUCCESS', ['token_valid' => true, 'server' => 'FAKE-WEAF-TEST',
            'companies' => [['tin' => '1015264035', 'business_name' => 'FAKE WEAF SANDBOX CO']]]);
    }
    fw_reply(401, '04', 'INVALID_OR_EXPIRED_TOKEN', null);
}

if (!preg_match('#^/api/([0-9]{10})/([a-z-]+)$#', $path, $m)) {
    fw_reply(404, '04', 'TEST fake WEAF: unknown path ' . $path, null);
}
[$_, $tin, $endpoint] = $m;

if ($auth !== 'Bearer TEST-WEAF-TOKEN') {
    fw_reply(401, '02', 'AUTHORIZATION_HEADER_MISSING', ['message' => 'Authorization header is required']);
}
if ($tin !== '1015264035') {
    fw_reply(403, '03', "Company with TIN [{$tin}] is not found in your account. Please add this company to your account first.",
        ['tin' => $tin, 'status' => 'not_configured']);
}

switch ($endpoint) {
    case 'search-taxpayer':
        $q = (string)($body['tin'] ?? '');
        if (strpos($q, '999') === 0) {
            fw_reply(404, '04', 'Taxpayer not found', "Taxpayer with TIN {$q} not found");
        }
        fw_reply(200, '00', 'SUCCESS', ['taxpayerName' => 'FAKE WEAF TAXPAYER ' . substr($q, -4)]);
        // no break — fw_reply exits

    case 'register-product':
        $products = $body['products'] ?? null;
        if (!is_array($products) || !$products) {
            fw_reply(400, '400', 'Products array is required in request body.', 'Products array is required in request body.');
        }
        $name = (string)($products[0]['goodsName'] ?? '');
        if (stripos($name, 'REJECT') !== false) {
            fw_reply(500, '500', 'An error occurred while processing the request.', 'An error occurred while processing the request.');
        }
        $state['stock'][(string)$products[0]['goodsCode']] = $state['stock'][(string)$products[0]['goodsCode']] ?? 0.0;
        fw_reply(200, '0', 'SUCCESS', []);

    case 'increase-stock':
        $it = $body['stockInItem'][0] ?? [];
        $gc = (string)($it['itemCode'] ?? '');
        if ($gc === '' || (float)($it['quantity'] ?? 0) <= 0) fw_reply(400, '01', 'Invalid request data');
        $state['stock'][$gc] = (float)($state['stock'][$gc] ?? 0) + (float)$it['quantity'];
        fw_reply(200, '00', 'Stock increased successfully', []);

    case 'decrease-stock':
        $it = $body['stockInItem'][0] ?? [];
        $gc = (string)($it['itemCode'] ?? '');
        $q  = (float)($it['quantity'] ?? 0);
        if ($gc === '' || $q <= 0) fw_reply(400, '01', 'Invalid request data');
        $have = (float)($state['stock'][$gc] ?? 0);
        if ($q > $have) fw_reply(400, '01', "Invalid request data — insufficient stock, {$have} on hand (TEST)");
        $state['stock'][$gc] = $have - $q;
        fw_reply(200, '00', 'Stock decreased successfully', []);

    case 'generate-fiscal-invoice':
        $data = $body['data'] ?? null;
        if (!is_array($data)) {
            fw_reply(400, '400', 'Data object is required in request body.', 'Data object is required in request body.');
        }
        $items = $data['itemsBought'] ?? [];
        foreach ($items as $it) {
            if (stripos((string)($it['itemCode'] ?? ''), 'REJECT') !== false) {
                fw_reply(500, '500', 'An error occurred while processing the request.', 'An error occurred while processing the request.');
            }
        }
        $state['seq']++;
        $n  = str_pad((string)$state['seq'], 6, '0', STR_PAD_LEFT);
        $no = "TESTWEAF-{$n}";
        $af = "TESTAF-{$n}";
        $state['invoices'][] = $no;
        $goods = [];
        $gross = 0.0;
        foreach ($items as $it) {
            $gross += (float)($it['total'] ?? 0);
            $goods[] = ['item' => $it['itemCode'] ?? '', 'itemCode' => $it['itemCode'] ?? '',
                        'qty' => (string)($it['quantity'] ?? ''), 'unitPrice' => (string)($it['unitPrice'] ?? ''),
                        'total' => (string)($it['total'] ?? ''), 'tax' => ''];
        }
        fw_reply(200, '00', 'SUCCESS', [
            'basicInformation' => [
                'antifakeCode' => $af,
                'invoiceNo'    => $no,
                'issuedDate'   => gmdate('d/m/Y H:i:s'),
                'operator'     => (string)($data['basicInformation']['operator'] ?? ''),
            ],
            'summary' => [
                'qrCode'      => "https://efristest.ura.go.ug/site_new/#/invoiceValidation?invoiceNo={$no}&antiFakeCode={$af}",
                'grossAmount' => (string)$gross,
                'netAmount'   => (string)round($gross / 1.18, 2),
                'taxAmount'   => (string)round($gross - $gross / 1.18, 2),
            ],
            'sellerDetails' => ['tin' => $tin],
            'buyerDetails'  => $data['buyerDetails'] ?? [],
            'goodsDetails'  => $goods,
        ]);

    case 'apply-for-creditnote':
        $gi = $body['generalInfo'] ?? null;
        if (!is_array($gi)) {
            fw_reply(400, '400', 'generalInfo is required in request body.', 'generalInfo is required in request body.');
        }
        if (!in_array((string)($gi['oriInvoiceNo'] ?? ''), $state['invoices'], true)) {
            fw_reply(500, '500', 'An error occurred while processing the request.', 'Original invoice not found (TEST)');
        }
        if (stripos((string)($gi['sellersReferenceNo'] ?? ''), 'REJECT') !== false) {
            fw_reply(500, '500', 'An error occurred while processing the request.', 'Credit amount exceeds the original (TEST)');
        }
        $state['cn_seq']++;
        fw_reply(200, '00', 'SUCCESS', ['referenceNo' => 'TESTCN-' . str_pad((string)$state['cn_seq'], 6, '0', STR_PAD_LEFT)]);
}

fw_reply(404, '04', "TEST fake WEAF: endpoint '{$endpoint}' not simulated", null);
