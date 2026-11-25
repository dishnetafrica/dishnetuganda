<?php
/**
 * ucrm.php — a fake uCRM API for the values-5.18.107 harness (php -S … ucrm.php). It logs every request's method and
 * path to $UCRM_LOG, and answers the four GETs the reader may make; anything else is 405, still logged.
 */
$log = getenv('UCRM_LOG') ?: '/dev/null';
$m = $_SERVER['REQUEST_METHOD'] ?? '?';
$uri = $_SERVER['REQUEST_URI'] ?? '';
file_put_contents($log, $m . ' ' . $uri . ' key=' . ($_SERVER['HTTP_X_AUTH_APP_KEY'] ?? '-') . "\n", FILE_APPEND);
header('Content-Type: application/json');
$path = preg_replace('#^/api/v2\.1/#', '', (string)parse_url($uri, PHP_URL_PATH));
if ($m !== 'GET') { http_response_code(405); echo '{"code":405}'; return true; }
$plan = function (int $id, string $name, float $price, bool $active = true): array {
    return ['id' => $id, 'name' => $name, 'isActive' => $active, 'organizationId' => 1, 'periods' => [['period' => 1, 'price' => $price, 'enabled' => true], ['period' => 12, 'price' => $price * 11, 'enabled' => true]]];
};
$prod = function (int $id, string $name, float $price, array $x = []): array {
    return ['id' => $id, 'name' => $name, 'price' => $price, 'taxable' => false, 'taxId' => null, 'organizationId' => 1, 'unit' => 'piece', 'invoiceLabel' => $name] + $x;
};
switch ($path) {
    case 'service-plans':
        echo json_encode([$plan(1, 'Residential (up to 350 Mbps)', 350000), $plan(2, 'Residential Lite (up to 100 Mbps)', 249000), $plan(3, 'Business 50', 900000, false)]);
        break;
    case 'products':
        echo json_encode([
            $prod(11, 'Starlink Standard Kit', 2350000), $prod(12, 'Starlink Mini Kit + Mini Router', 1500000), $prod(13, 'Installation', 200000),
            $prod(14, 'Pivot Mount | Standard 4 and 4 X, Enterprise', 150000), $prod(15, 'Pivot Mount | Mini', 120000),
            $prod(16, 'Residential Lite (up to 100 Mbps)', 249000), $prod(18, 'Router Mini 0000123456', 300000),
            $prod(17, 'Starlink Standard Kit ', 2400000, ['taxable' => true]),
        ]);
        break;
    case 'invoices':
        echo json_encode([
            ['id' => 900, 'number' => '000182', 'invoiceNumber' => '000182', 'createdDate' => '2026-11-24T10:00:00+0300', 'organizationId' => 1, 'total' => 2900000, 'clientFirstName' => 'PLANT_CLIENT'],
            ['id' => 899, 'number' => '000181', 'invoiceNumber' => '000181', 'createdDate' => '2026-11-20T09:00:00+0300', 'organizationId' => 1, 'total' => 1, 'clientFirstName' => 'PLANT_CLIENT'],
        ]);
        break;
    case 'organizations':
        echo json_encode([['id' => 1, 'selected' => true, 'name' => 'PLANT_ORGNAME', 'phone' => '+256000000001', 'email' => 'PLANT@example.invalid',
            'invoicePrefix' => '', 'invoiceNumberLength' => 6, 'invoiceInitNumber' => 1, 'proformaInvoicePrefix' => 'PRO', 'bankAccountField1' => '9988776655']]);
        break;
    default:
        http_response_code(404); echo '{"code":404}';
}
return true;
