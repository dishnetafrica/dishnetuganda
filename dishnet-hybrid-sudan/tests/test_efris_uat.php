<?php
declare(strict_types=1);
/**
 * URA UAT-readiness battery — the five interfaces the System-to-System
 * checklist demands beyond plain invoicing, against the FAKE EFRIS server:
 *
 *   Q1  T130 goods/services registration (+ write-through to the invoice map)
 *   Q2  T131 stock increase/decrease with guards and an audit log
 *   Q5  B2C / B2B / B2G buyer typing (numeric EFRIS codes)
 *   Q6  T119 buyer-TIN validation gating B2B/B2G submissions
 *   Q7  stock mirror after a fiscalised sale of a stocked item
 *   Q8  T110 credit note against a fiscalised invoice
 *   Q9  T114 credit note cancellation
 *
 * Everything TEST- prefixed; production/disabled refuse every new call.
 */
require_once dirname(__DIR__) . '/lib/StoreInterface.php';
require_once dirname(__DIR__) . '/lib/SqliteStore.php';
require_once dirname(__DIR__) . '/lib/PluginConfig.php';
require_once dirname(__DIR__) . '/lib/EfrisService.php';

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

function mkClient(int $id, array $over = []): array
{
    return $over + [
        'id' => $id, 'firstName' => 'Test', 'lastName' => 'Buyer ' . $id, 'companyName' => '',
        'street1' => 'Plot 1', 'city' => 'Kampala', 'clientType' => 1,
        'contacts' => [['phone' => '25670000000' . $id, 'email' => "b{$id}@example.com"]],
        'attributes' => [],
    ];
}
function mkInvoice(int $id, string $number, int $clientId, array $items = []): array
{
    return [
        'id' => $id, 'number' => $number, 'status' => 1, 'clientId' => $clientId,
        'currencyCode' => 'UGX', 'createdDate' => '2026-09-07T09:00:00+0300',
        'maturityDate' => '2026-09-21', 'total' => 388220.0,
        'items' => $items ?: [['label' => 'DishNet Home', 'quantity' => 1, 'price' => 329000.0,
                     'total' => 329000.0,
                     'taxes' => [['id' => 1, 'name' => 'VAT 18%', 'rate' => 18, 'totalValue' => 59220.0]]]],
    ];
}

// ── Boot the fake EFRIS server ──────────────────────────────────────────────
array_map('unlink', glob(sys_get_temp_dir() . '/fake_efris_state_*.json') ?: []);
$router = dirname(__DIR__) . '/tests/fixtures/fake_efris_server.php';
$probe = function (int $port): ?string {
    $ch = curl_init("http://127.0.0.1:{$port}/");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 2,
        CURLOPT_PROXY => '', CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['globalInfo' => ['interfaceCode' => 'T101'],
                                           'data' => ['content' => base64_encode('{}')]])]);
    $r = curl_exec($ch); curl_close($ch);
    return $r === false ? null : (string)$r;
};
$srv = null; $port = 0;
foreach (range(0, 9) as $slot) {
    $cand = 9310 + ((getmypid() + $slot * 17) % 80);
    $p = proc_open(sprintf('exec php -S 127.0.0.1:%d %s', $cand, escapeshellarg($router)),
                   [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    $ours = false;
    for ($i = 0; $i < 40; $i++) {
        $got = $probe($cand);
        if ($got !== null) { $ours = strpos($got, 'TEST-SERVER-SIGNATURE') !== false; break; }
        usleep(100000);
    }
    if ($ours) { $srv = $p; $port = $cand; break; }
    proc_terminate($p); proc_close($p);
}
if ($port === 0) { fwrite(STDERR, "could not start the fake EFRIS server\n"); exit(1); }

$tmp = sys_get_temp_dir() . '/efris_uat_test_' . getmypid();
@mkdir($tmp, 0777, true);
$store = SqliteStore::create($tmp);

$CFG = [
    'efris_environment'  => 'test',
    'efris_test_api_url' => "http://127.0.0.1:{$port}",
    'efris_tin'          => '1059140632',
    'efris_device_no'    => 'DEV-TEST-1',
    'efris_legal_name'   => 'DishNet Africa Limited',
];

$fx = [];
$fx['clients/7']  = mkClient(7, ['companyName' => 'Kampala Traders Ltd', 'clientType' => 2,
    'attributes' => [['key' => 'efrisTin', 'value' => '1000000001']]]);
$fx['clients/8']  = mkClient(8, ['companyName' => 'Bad TIN Ltd', 'clientType' => 2,
    'attributes' => [['key' => 'efrisTin', 'value' => '9990000002']]]);
$fx['clients/9']  = mkClient(9, ['companyName' => 'Ministry of Works', 'clientType' => 2,
    'attributes' => [['key' => 'efrisTin', 'value' => '1000000009'],
                     ['key' => 'efrisBuyerType', 'value' => 'government']]]);
$fx['clients/10'] = mkClient(10);   // plain individual, no TIN — B2C
$fx['clients/11'] = mkClient(11, ['companyName' => 'No TIN Traders', 'clientType' => 2]);

$kitItems = [['label' => 'Starlink Mini Kit', 'quantity' => 2, 'price' => 1500000.0, 'total' => 3000000.0,
              'taxes' => [['id' => 1, 'name' => 'VAT 18%', 'rate' => 18, 'totalValue' => 540000.0]]]];
$fx['invoices/201'] = mkInvoice(201, 'INV-B2B-1', 7);
$fx['invoices/202'] = mkInvoice(202, 'INV-BADTIN-1', 8);
$fx['invoices/203'] = mkInvoice(203, 'INV-B2G-1', 9);
$fx['invoices/204'] = mkInvoice(204, 'INV-B2C-1', 10);
$fx['invoices/205'] = mkInvoice(205, 'INV-NOTIN-1', 11);
$fx['invoices/206'] = mkInvoice(206, 'INV-KIT-1', 10, $kitItems);
$fx['credit-notes/501'] = ['id' => 501, 'number' => 'CN-1', 'invoiceId' => 201, 'clientId' => 7,
    'currencyCode' => 'UGX', 'total' => 388220.0, 'createdDate' => '2026-09-07T10:00:00+0300',
    'items' => $fx['invoices/201']['items']];
$fx['credit-notes/502'] = ['id' => 502, 'number' => 'CN-2-REJECT', 'invoiceId' => 201, 'clientId' => 7,
    'currencyCode' => 'UGX', 'total' => 999999.0, 'createdDate' => '2026-09-07T10:00:00+0300',
    'items' => $fx['invoices/201']['items']];
$fx['invoices/207'] = mkInvoice(207, 'INV-NEVER-1', 10);   // exists in uCRM, never fiscalised
$fx['credit-notes/503'] = ['id' => 503, 'number' => 'CN-3', 'invoiceId' => 207, 'clientId' => 10,
    'currencyCode' => 'UGX', 'total' => 100.0, 'createdDate' => '2026-09-07T10:00:00+0300',
    'items' => $fx['invoices/207']['items']];

$crm = new FakeCrm($fx);
$mk = fn(array $cfg) => new EfrisService($store, $cfg, $tmp, $crm, new EfrisClient($cfg, 3));
$svc = $mk($CFG);
$gs  = $svc->goodsService();

echo "Q1 — goods/services registry and T130 registration\n";
t('empty name refused', $gs->save(['name' => ''])['ok'], false);
t('bad VAT category refused', $gs->save(['name' => 'X', 'vat_category' => 'vatty'])['ok'], false);
$rs = $gs->save(['name' => 'DishNet Home', 'goods_code' => 'DN-HOME', 'commodity_code' => '83252502',
                 'unit' => 'month', 'vat_category' => 'standard']);
t('service item saved', $rs['ok'], true);
$rk = $gs->save(['name' => 'Starlink Mini Kit', 'goods_code' => 'SL-MINI', 'commodity_code' => '43222609',
                 'unit' => 'each', 'unit_price' => 1500000, 'vat_category' => 'standard', 'stocked' => 1]);
t('stocked hardware item saved', $rk['ok'], true);
$map = [];
foreach ((array)$store->load('efris_commodity_map.json') as $r) $map[strtolower($r['item'])] = $r['code'];
t('registry writes through to the invoice commodity map', $map['starlink mini kit'] ?? '', '43222609');
$noCode = $gs->save(['name' => 'Unmapped Thing']);
t('registering without a commodity code is refused locally',
  strpos((string)$gs->register((int)$noCode['id'])['error'], 'commodity code') !== false, true);
$reg = $gs->register((int)$rk['id']);
t('T130 registers the kit', $reg['ok'], true);
t('EFRIS goods reference stored verbatim', strpos((string)$reg['reference'], 'TEST-GOODS-') === 0, true);
t('row status REGISTERED', $gs->registry()->get((int)$rk['id'])['status'], 'REGISTERED');
$reg2 = $gs->register((int)$rk['id']);
t('re-registering is idempotent (server answers duplicate)', $reg2['ok'], true);
$rj = $gs->save(['name' => 'REJECT Widget', 'goods_code' => 'RJ-1', 'commodity_code' => '000']);
$rjr = $gs->register((int)$rj['id']);
t('URA-style rejection lands as ERROR with the message',
  !$rjr['ok'] && $gs->registry()->get((int)$rj['id'])['status'] === 'ERROR'
  && strpos((string)$rjr['error'], 'TEST rejection') !== false, true);

echo "\nQ2 — T131 stock maintenance\n";
$kitId = (int)$rk['id'];
t('stock on a non-stocked item refused',
  strpos((string)$gs->adjustStock((int)$rs['id'], 'increase', 5, 'purchase', '', 't')['error'], 'not a stocked item') !== false, true);
t('zero qty refused', $gs->adjustStock($kitId, 'increase', 0, 'purchase', '', 't')['ok'], false);
t('missing reason refused', $gs->adjustStock($kitId, 'increase', 5, '', '', 't')['ok'], false);
$si = $gs->adjustStock($kitId, 'increase', 10, 'purchase', 'first shipment', 'tester');
t('stock-in of 10 kits', $si['ok'] && (float)$si['stock_qty'] === 10.0, true);
$sd = $gs->adjustStock($kitId, 'decrease', 3, 'damaged', 'water damage', 'tester');
t('stock-out of 3', $sd['ok'] && (float)$sd['stock_qty'] === 7.0, true);
t('decreasing more than on hand refused locally',
  strpos((string)$gs->adjustStock($kitId, 'decrease', 100, 'oops', '', 't')['error'], 'on hand') !== false, true);
$gs->registry()->update($kitId, ['stock_qty' => 999.0]);   // force past the local guard
$srv2 = $gs->adjustStock($kitId, 'decrease', 500, 'oops', '', 't');
t('the fake server itself also refuses insufficient stock',
  strpos((string)$srv2['error'], 'insufficient') !== false, true);
$gs->registry()->update($kitId, ['stock_qty' => 7.0]);     // restore the true mirror
t('movements are logged', count($gs->registry()->stockLog($kitId)) >= 3, true);

echo "\nQ5/Q6 — buyer typing and T119 TIN validation\n";
t('malformed TIN refused before any network', $svc->queryTin('123')['ok'], false);
$tv = $svc->queryTin('1000000001');
t('valid TIN found', $tv['ok'] && strpos((string)$tv['taxpayer']['name'], 'TEST TAXPAYER') === 0, true);
t('second lookup served from cache', $svc->queryTin('1000000001')['cached'], true);
t('unregistered TIN (999…) fails validation', $svc->queryTin('9990000001')['ok'], false);
$p = $svc->preview(203);
t('government buyer typed B2G with EFRIS code 3',
  $p['model']['buyer']['type'] === 'government' && $p['model']['buyer']['type_code'] === 3, true);
t('B2C buyer carries EFRIS code 1', $svc->preview(204)['model']['buyer']['type_code'], 1);
t('B2B without any TIN is blocked at validation',
  strpos(implode(' ', $svc->preview(205)['errors']), 'Buyer TIN is required') !== false, true);
$r = $svc->submitInvoice(202, 'test');
t('B2B submit with an unregistered TIN is stopped by T119',
  $r['status'] === 'ERROR' && strpos($r['message'], 'TIN validation failed') !== false, true);
$r = $svc->submitInvoice(201, 'test');
t('B2B submit with a valid TIN fiscalises', $r['status'], 'FISCALISED');
$r = $svc->submitInvoice(203, 'test');
t('B2G submit fiscalises', $r['status'], 'FISCALISED');
$r = $svc->submitInvoice(204, 'test');
t('B2C submit needs no TIN and fiscalises', $r['status'], 'FISCALISED');

echo "\nQ7 — stock updates after a fiscalised sale\n";
$r = $svc->submitInvoice(206, 'test');
t('kit invoice fiscalises', $r['status'], 'FISCALISED');
t('stock mirror decremented 7 − 2 = 5', (float)$gs->registry()->get($kitId)['stock_qty'], 5.0);
$logTop = $gs->registry()->stockLog($kitId)[0];
t('the sale is logged as a movement', $logTop['reason'] === 'sale' && (float)$logTop['qty'] === 2.0, true);

echo "\nQ8 — T110 credit notes\n";
t('a reason is mandatory', $svc->submitCreditNote(501, '')['ok'], false);
$r = $svc->submitCreditNote(503, 'test refund');
t('credit note against an UNfiscalised invoice refused',
  strpos($r['message'], 'not fiscalised') !== false, true);
$r = $svc->submitCreditNote(501, 'Customer overcharged — service downgraded');
t('credit note fiscalises', $r['status'], 'FISCALISED');
t('credit note gets its own TEST FDN', strpos((string)$r['tx']['fdn'], 'TEST-CN-FDN-') === 0, true);
t('the CN row is linked to the original invoice', (int)$r['tx']['linked_invoice_id'], 201);
t('the original invoice is now CREDITED', $svc->transactions()->find(201)['status'], 'CREDITED');
$cnFdn = (string)$r['tx']['fdn'];
$r2 = $svc->submitCreditNote(501, 'again');
t('duplicate application returns the stored record', ($r2['duplicate'] ?? false) && $r2['tx']['fdn'] === $cnFdn, true);
$r = $svc->submitCreditNote(502, 'too much');
t('URA-style CN rejection lands as REJECTED', $r['status'], 'REJECTED');

echo "\nQ9 — T114 credit note cancellation\n";
t('cancel needs a reason', $svc->cancelCreditNote(501, '')['ok'], false);
t('cancelling an unknown CN refused',
  strpos((string)$svc->cancelCreditNote(777, 'why')['message'], 'only a fiscalised credit note') !== false, true);
$r = $svc->cancelCreditNote(501, 'issued in error');
t('cancellation acknowledged', $r['status'], 'CANCELLED');
t('CN row now CANCELLED', $svc->transactions()->find(501, EfrisStore::KIND_CREDIT_NOTE)['status'], 'CANCELLED');
t('original invoice restored to FISCALISED', $svc->transactions()->find(201)['status'], 'FISCALISED');
$r = $svc->cancelCreditNote(501, 'again');
t('double cancel refused', strpos((string)$r['message'], 'already cancelled') !== false, true);

echo "\nEnvironment gates cover every new interface\n";
foreach (['production' => 'REFUSED', 'disabled' => 'DISABLED'] as $envName => $want) {
    $s2 = $mk(['efris_environment' => $envName] + $CFG);
    t("{$envName}: credit note refuses", $s2->submitCreditNote(501, 'x')['status'], $want);
    t("{$envName}: CN cancel refuses",   $s2->cancelCreditNote(501, 'x')['status'], $want);
    t("{$envName}: TIN query refuses even with a warm cache",
      $s2->queryTin('1000000001')['ok'], false);
    $g2 = $s2->goodsService();
    $gid = (int)$g2->registry()->findByName('Starlink Mini Kit')['id'];
    t("{$envName}: T130 refuses",  $g2->register($gid)['ok'], false);
    t("{$envName}: T131 refuses",  $g2->adjustStock($gid, 'increase', 1, 'x', '', 't')['ok'], false);
}

if (is_resource($srv)) { proc_terminate($srv); proc_close($srv); }
exec('rm -rf ' . escapeshellarg($tmp));
array_map('unlink', glob(sys_get_temp_dir() . '/fake_efris_state_*.json') ?: []);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
