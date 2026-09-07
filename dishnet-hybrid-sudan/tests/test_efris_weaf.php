<?php
declare(strict_types=1);
/**
 * WEAF gateway battery — the swappable transport against the FAKE WEAF
 * server, whose envelope and response shapes are copied verbatim from the
 * vendor's Postman collection. Proves: gateway selection, the translator's
 * field-level fidelity to the vendor sample, the full invoice/TIN/goods/
 * stock/credit-note flows through the UNCHANGED EfrisService pipeline, and
 * that disabled/production still refuse everything.
 */
require_once dirname(__DIR__) . '/lib/StoreInterface.php';
require_once dirname(__DIR__) . '/lib/SqliteStore.php';
require_once dirname(__DIR__) . '/lib/PluginConfig.php';
require_once dirname(__DIR__) . '/lib/EfrisService.php';
require_once dirname(__DIR__) . '/lib/WeafEfrisClient.php';

$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }

class FakeCrm extends CrmApiClient
{
    private array $fx;
    public function __construct(array $fx) { parent::__construct('http://fake.local', 'test-key'); $this->fx = $fx; }
    public function get(string $path): ?array { return $this->fx[$path] ?? null; }
    public function isConfigured(): bool { return true; }
}

// ── Boot the fake WEAF server ───────────────────────────────────────────────
array_map('unlink', glob(sys_get_temp_dir() . '/fake_weaf_state_*.json') ?: []);
$router = dirname(__DIR__) . '/tests/fixtures/fake_weaf_server.php';
$probe = function (int $port): ?string {
    $ch = curl_init("http://127.0.0.1:{$port}/api/v1/auth/validate-token");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 2,
        CURLOPT_PROXY => '', CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['token' => 'TEST-WEAF-TOKEN'])]);
    $r = curl_exec($ch); curl_close($ch);
    return $r === false ? null : (string)$r;
};
$srv = null; $port = 0;
foreach (range(0, 9) as $slot) {
    $cand = 9410 + ((getmypid() + $slot * 19) % 80);
    $p = proc_open(sprintf('exec php -S 127.0.0.1:%d %s', $cand, escapeshellarg($router)),
                   [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    $ours = false;
    for ($i = 0; $i < 40; $i++) {
        $got = $probe($cand);
        if ($got !== null) { $ours = strpos($got, 'FAKE-WEAF-TEST') !== false; break; }
        usleep(100000);
    }
    if ($ours) { $srv = $p; $port = $cand; break; }
    proc_terminate($p); proc_close($p);
}
if ($port === 0) { fwrite(STDERR, "could not start the fake WEAF server\n"); exit(1); }

$tmp = sys_get_temp_dir() . '/efris_weaf_test_' . getmypid();
@mkdir($tmp, 0777, true);
$store = SqliteStore::create($tmp);
// The operator's tax map, as configured in the admin tab on a real install —
// the WEAF translator refuses any line whose category it cannot resolve.
$store->save('efris_tax_map.json', [['tax' => 'vat 18%', 'category' => 'standard']]);

$CFG = [
    'efris_environment'   => 'test',
    'efris_gateway'       => 'weaf',
    'efris_weaf_base_url' => "http://127.0.0.1:{$port}",
    'efris_weaf_token'    => 'TEST-WEAF-TOKEN',
    'efris_tin'           => '1015264035',
    'efris_device_no'     => 'VIA-WEAF',
    'efris_legal_name'    => 'DishNet Africa Limited',
    'efris_business_name' => 'DishNet Uganda',
    'efris_address'       => 'Kampala',
];

echo "Gateway selection\n";
t('efris_gateway=weaf selects the WEAF transport',
  EfrisClient::forConfig($CFG) instanceof WeafEfrisClient, true);
t('default stays the Phase-1 envelope client',
  EfrisClient::forConfig(['efris_environment' => 'test', 'efris_test_api_url' => 'http://x']) instanceof WeafEfrisClient, false);

echo "\nEnvironment gates\n";
$c = new WeafEfrisClient(['efris_environment' => 'production'] + $CFG);
t('production refuses', $c->isUsable(), false);
t('and names the accreditation condition', strpos($c->refusalReason(), 'accreditation') !== false, true);
t('disabled refuses', (new WeafEfrisClient(['efris_environment' => 'disabled'] + $CFG))->isUsable(), false);
$noTok = $CFG; unset($noTok['efris_weaf_token']);
t('missing token refuses with the fix',
  strpos((new WeafEfrisClient($noTok))->refusalReason(), 'efris_weaf_token') !== false, true);

echo "\nTranslator fidelity to the vendor sample\n";
$client = new WeafEfrisClient($CFG);
$model = [
    'seller'  => ['legal_name' => 'DishNet Africa Limited', 'business_name' => 'DishNet Uganda',
                  'address' => 'Kampala', 'tin' => '1015264035', 'device_no' => 'VIA-WEAF'],
    'invoice' => ['ucrm_id' => 900, 'number' => 'DN-0001', 'issued_date' => '2026-09-07T09:00:00',
                  'currency' => 'UGX'],
    'buyer'   => ['type' => 'business', 'type_code' => 0, 'name' => 'WEAF COMPANY UGANDA LIMITED',
                  'tin' => '1017196396', 'address' => 'Kampala Road', 'email' => 'w@r.com',
                  'phone' => '0756508361', 'nin' => '', 'brn' => ''],
    'items'   => [['label' => 'Sample Deemed Item', 'qty' => 1.0, 'unit_price' => 2000000.0,
                   'line_total' => 2000000.0, 'discount' => 0.0, 'tax_category' => 'standard',
                   'tax' => ['amount' => null]]],
];
[$payload, $err] = $client->translateInvoice($model);
t('translates without error', $err, '');
$d = $payload['data'];
t('buyerType is the numeric EFRIS code as a string', $d['buyerDetails']['buyerType'], '0');
t('buyer TIN carried', $d['buyerDetails']['buyerTin'], '1017196396');
t('issuedDate in the vendor d/m/Y format', $d['sellerDetails']['issuedDate'], '07/09/2026 09:00:00');
t('sellers reference is the uCRM number', $d['sellerDetails']['referenceNo'], 'DN-0001');
t('item joins by name (itemCode = label)', $d['itemsBought'][0]['itemCode'], 'Sample Deemed Item');
t('taxRule STANDARD', $d['itemsBought'][0]['taxRule'], 'STANDARD');
t('net derived at the standard rate when uCRM gave no per-item tax',
  $d['itemsBought'][0]['netAmount'], round(2000000 / 1.18, 2));
t('no-discount flag is 2', $d['itemsBought'][0]['discountFlag'], 2);
$zr = $model; $zr['items'][0]['tax_category'] = 'zero_rated';
t('non-standard tax rule is refused until the dictionary is confirmed',
  strpos($client->translateInvoice($zr)[1], 'taxRule') !== false, true);

echo "\nFull pipeline through the WEAF fake\n";
$CLIENT7 = ['id' => 7, 'firstName' => '', 'lastName' => '', 'companyName' => 'WEAF COMPANY UGANDA LIMITED',
    'clientType' => 2, 'street1' => 'Kampala Road', 'city' => 'Kampala',
    'contacts' => [['phone' => '0756508361', 'email' => 'w@r.com']],
    'attributes' => [['key' => 'efrisTin', 'value' => '1017196396']]];
$mkInv = fn(int $id, string $no) => ['id' => $id, 'number' => $no, 'status' => 1, 'clientId' => 7,
    'currencyCode' => 'UGX', 'createdDate' => '2026-09-07T09:00:00+0300', 'total' => 2000000.0,
    'items' => [['label' => 'Sample Deemed Item', 'quantity' => 1, 'price' => 2000000.0, 'total' => 2000000.0,
                 'taxes' => [['id' => 1, 'name' => 'VAT 18%', 'rate' => 18, 'totalValue' => 305084.75]]]]];
$fx = ['clients/7' => $CLIENT7, 'invoices/301' => $mkInv(301, 'DN-W-1'), 'invoices/302' => $mkInv(302, 'DN-W-2')];
$fx['invoices/303'] = $mkInv(303, 'DN-W-3');
$fx['clients/8'] = ['id' => 8, 'companyName' => 'Ghost Ltd', 'clientType' => 2, 'contacts' => [],
    'attributes' => [['key' => 'efrisTin', 'value' => '9990000004']]];
$fx['invoices/303']['clientId'] = 8;
$fx['credit-notes/601'] = ['id' => 601, 'number' => 'CN-W-1', 'invoiceId' => 301, 'clientId' => 7,
    'currencyCode' => 'UGX', 'total' => 2000000.0, 'createdDate' => '2026-09-07T10:00:00+0300',
    'items' => $fx['invoices/301']['items']];
$crm = new FakeCrm($fx);
$svc = new EfrisService($store, $CFG, $tmp, $crm, new WeafEfrisClient($CFG, 5));
$gs  = $svc->goodsService();

$r = $svc->submitInvoice(301, 'test');
t('invoice fiscalises via WEAF', $r['status'], 'FISCALISED');
t('FDN is the WEAF invoiceNo', strpos((string)$r['tx']['fdn'], 'TESTWEAF-') === 0, true);
t('antifake code stored as the verification code', strpos((string)$r['tx']['verification_code'], 'TESTAF-') === 0, true);
t('URA validation QR stored verbatim',
  strpos((string)$r['tx']['qr_data'], 'efristest.ura.go.ug') !== false, true);
$r2 = $svc->submitInvoice(301, 'test');
t('duplicate stays idempotent through the gateway', $r2['duplicate'] ?? false, true);

$tv = $svc->queryTin('1017196396');
t('TIN lookup via WEAF search-taxpayer', $tv['ok'] && strpos((string)$tv['taxpayer']['name'], 'FAKE WEAF TAXPAYER') === 0, true);
t('unknown TIN (999…) fails', $svc->queryTin('9990000004')['ok'], false);
$r = $svc->submitInvoice(303, 'test');
t('B2B invoice with an unknown TIN is stopped before sending',
  $r['status'] === 'ERROR' && strpos($r['message'], 'TIN validation failed') !== false, true);

$gk = $gs->save(['name' => 'Starlink Mini Kit', 'goods_code' => 'SL-MINI', 'commodity_code' => '43222609',
                 'unit' => 'each', 'unit_price' => 1500000, 'vat_category' => 'standard', 'stocked' => 1]);
$reg = $gs->register((int)$gk['id']);
t('product registers via WEAF', $reg['ok'], true);
t('registration recorded with the gateway ack reference',
  strpos((string)$reg['reference'], 'WEAF-ACK-') === 0, true);
$gu = $gs->save(['name' => 'USD Widget', 'goods_code' => 'UW', 'commodity_code' => '1']);
$gs->registry()->update((int)$gu['id'], ['currency' => 'USD']);
t('non-UGX product refused until the currency dictionary is confirmed',
  strpos((string)$gs->register((int)$gu['id'])['error'], 'UGX only') !== false, true);

$si = $gs->adjustStock((int)$gk['id'], 'increase', 10, 'purchase', 'first shipment', 't');
t('stock-in via WEAF', $si['ok'] && (float)$si['stock_qty'] === 10.0, true);
$sd = $gs->adjustStock((int)$gk['id'], 'decrease', 3, 'damaged', '', 't');
t('stock-out via WEAF', $sd['ok'] && (float)$sd['stock_qty'] === 7.0, true);
$gs->registry()->update((int)$gk['id'], ['stock_qty' => 999.0]);
t('the WEAF server itself refuses an over-draw',
  strpos((string)$gs->adjustStock((int)$gk['id'], 'decrease', 500, 'oops', '', 't')['error'], 'insufficient') !== false, true);

$cn = $svc->submitCreditNote(601, 'Customer refund — service cancelled');
t('credit note application accepted', $cn['status'], 'FISCALISED');
t('CN reference stored from data.referenceNo', strpos((string)$cn['tx']['fdn'], 'TESTCN-') === 0, true);
t('original invoice now CREDITED', $svc->transactions()->find(301)['status'], 'CREDITED');
$cc = $svc->cancelCreditNote(601, 'testing');
t('CN cancellation is refused — WEAF has no T114',
  !$cc['ok'] && strpos((string)$cc['message'], 'does not expose') !== false, true);

if (is_resource($srv)) { proc_terminate($srv); proc_close($srv); }
exec('rm -rf ' . escapeshellarg($tmp));
array_map('unlink', glob(sys_get_temp_dir() . '/fake_weaf_state_*.json') ?: []);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
