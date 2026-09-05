<?php
declare(strict_types=1);
/**
 * EfrisService against the FAKE EFRIS server (TEST ONLY, TEST- values):
 * fiscalise, duplicate-submit idempotency, rejection, auth failure, malformed
 * response, timeout, pending, validation blocking, and the two hard gates —
 * environment disabled and production both refuse to send anything.
 */
require_once dirname(__DIR__) . '/lib/StoreInterface.php';
require_once dirname(__DIR__) . '/lib/SqliteStore.php';
require_once dirname(__DIR__) . '/lib/PluginConfig.php';
require_once dirname(__DIR__) . '/lib/EfrisService.php';

$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }

/** uCRM stand-in: serves fixtures, no network. */
class FakeCrm extends CrmApiClient
{
    private array $fx;
    public function __construct(array $fx) { parent::__construct('http://fake.local', 'test-key'); $this->fx = $fx; }
    public function get(string $path): ?array { return $this->fx[$path] ?? null; }
    public function isConfigured(): bool { return true; }
}

$CLIENT = [
    'id' => 7, 'firstName' => 'Test', 'lastName' => 'Buyer', 'companyName' => 'Kampala Traders Ltd',
    'street1' => 'Plot 1', 'city' => 'Kampala',
    'contacts' => [['phone' => '256700000001', 'email' => 'b@example.com']],
    'attributes' => [['key' => 'efrisTin', 'value' => '1000000001']],
];
function fixInvoice(int $id, string $number): array
{
    return [
        'id' => $id, 'number' => $number, 'status' => 1, 'clientId' => 7,
        'currencyCode' => 'UGX', 'createdDate' => '2026-09-05T09:00:00+0300',
        'maturityDate' => '2026-09-19', 'total' => 388220.0,
        'items' => [['label' => 'DishNet Home', 'quantity' => 1, 'price' => 329000.0,
                     'total' => 329000.0,
                     'taxes' => [['id' => 1, 'name' => 'VAT 18%', 'rate' => 18, 'totalValue' => 59220.0]]]],
    ];
}

// ── Boot the fake EFRIS server on a port proven to be ours ──────────────────
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
    $cand = 9210 + ((getmypid() + $slot * 13) % 80);
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

$tmp = sys_get_temp_dir() . '/efris_svc_test_' . getmypid();
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
foreach ([[101, 'INV-OK-1'], [102, 'INV-REJECT-1'], [103, 'INV-AUTHFAIL-1'],
          [104, 'INV-MALFORMED-1'], [105, 'INV-TIMEOUT-1'], [106, 'INV-PENDINGX-1'],
          [108, 'INV-OK-2']] as [$id, $no]) {
    $fx["invoices/{$id}"] = fixInvoice($id, $no);
}
$draft = fixInvoice(107, ''); $draft['status'] = 0;
$fx['invoices/107'] = $draft;
$fx['clients/7'] = $CLIENT;
$crm = new FakeCrm($fx);
$mk = fn(array $cfg) => new EfrisService($store, $cfg, $tmp, $crm, new EfrisClient($cfg, 3));

echo "Fiscalisation happy path\n";
$svc = $mk($CFG);
$r = $svc->submitInvoice(101, 'test');
t('fiscalised', $r['status'], 'FISCALISED');
t('FDN is a clearly-fake TEST value', strpos((string)$r['tx']['fdn'], 'TEST-FDN-') === 0, true);
t('verification code stored', strpos((string)$r['tx']['verification_code'], 'TEST-VERIFICATION-') === 0, true);
t('qr data stored verbatim', strpos((string)$r['tx']['qr_data'], 'TEST-QR|') === 0, true);
t('environment recorded on the row', $r['tx']['environment'], 'test');
t('request payload stored for audit', json_decode((string)$r['tx']['request_payload'], true)['invoice']['number'], 'INV-OK-1');
t('response payload stored for audit', strpos((string)$r['tx']['response_payload'], 'TEST') !== false, true);

echo "\nDuplicate submission is idempotent\n";
$fdn1 = (string)$r['tx']['fdn'];
$r2 = $svc->submitInvoice(101, 'test');
t('duplicate detected', $r2['duplicate'] ?? false, true);
t('same stored FDN returned', (string)$r2['tx']['fdn'], $fdn1);
t('still exactly one row for the invoice', count($svc->transactions()->recent(['q' => 'INV-OK-1'])), 1);

echo "\nRejection, auth failure, malformed, timeout, pending\n";
$r = $svc->submitInvoice(102, 'test');
t('rejection → REJECTED', $r['status'], 'REJECTED');
t('URA-style message stored', strpos($r['message'], 'TEST rejection') !== false, true);
$rAgain = $svc->submitInvoice(102, 'test');
t('rejected invoice blocks blind resubmit', strpos($rAgain['message'], 'Retry') !== false, true);
$rRetry = $svc->submitInvoice(102, 'test', true);
t('explicit retry reaches the server again', $rRetry['status'], 'REJECTED');

$r = $svc->submitInvoice(103, 'test');
t('auth failure is not fiscalised', in_array($r['status'], ['REJECTED', 'ERROR'], true), true);
t('auth message preserved', stripos($r['message'], 'authentication') !== false, true);

$r = $svc->submitInvoice(104, 'test');
t('malformed response → ERROR', $r['status'], 'ERROR');
t('says malformed', stripos($r['message'], 'malformed') !== false, true);

$r = $svc->submitInvoice(106, 'test');
t('processing answer stays SUBMITTED', $r['status'], 'SUBMITTED');

// Last in this block on purpose: php -S serves one request at a time, and
// the TIMEOUT behaviour leaves the fake server sleeping — anything sent
// during the nap would time out too and fail for the wrong reason.
$r = $svc->submitInvoice(105, 'test');
t('timeout → ERROR', $r['status'], 'ERROR');
t('connection failure recorded', stripos($r['message'], 'Connection failed') !== false, true);
usleep(6500000); // let the fake server finish its 8s nap before the next section

echo "\nValidation blocks before any network call\n";
$r = $svc->submitInvoice(107, 'test');
t('draft blocked as ERROR', $r['status'], 'ERROR');
t('reason says draft', stripos($r['message'], 'DRAFT') !== false, true);
$r = $svc->submitInvoice(108, 'test');
t('sequence unaffected by blocked draft (no server call happened)',
  (string)$r['tx']['fdn'], 'TEST-FDN-000002');

echo "\nEnvironment gates\n";
$rd = $mk(['efris_environment' => 'disabled'] + $CFG)->submitInvoice(101, 'test');
t('disabled refuses', $rd['status'], 'DISABLED');
$rp = $mk(['efris_environment' => 'production'] + $CFG)->submitInvoice(101, 'test');
t('production refuses in this build', $rp['status'], 'REFUSED');
t('and says why', stripos($rp['message'], 'not implemented') !== false, true);

echo "\nEdit-after-fiscalisation flag\n";
t('fiscalised invoice can be flagged', $svc->flagAdjustmentNeeded(101, 'total changed'), true);
t('status now NEEDS_ADJUSTMENT', $svc->transactions()->find(101)['status'], 'NEEDS_ADJUSTMENT');
t('un-fiscalised invoice is not flaggable', $svc->flagAdjustmentNeeded(102, 'x'), false);

if (is_resource($srv)) { proc_terminate($srv); proc_close($srv); }
exec('rm -rf ' . escapeshellarg($tmp));
array_map('unlink', glob(sys_get_temp_dir() . '/fake_efris_state_*.json') ?: []);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
