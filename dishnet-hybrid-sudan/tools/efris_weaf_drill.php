<?php
declare(strict_types=1);
/**
 * efris_weaf_drill.php — exercise the WEAF EFRIS gateway SANDBOX end to end
 * from this server (the vendor's shared dev TIN, their stated test purpose).
 *
 * Reads efris_* configuration from the plugin data dir; every value can be
 * overridden per run. Runs ONLY with efris_environment=test, and the client
 * itself refuses production.
 *
 *   # 1. generate a bearer token from the credentials the vendor provided:
 *   php tools/efris_weaf_drill.php --login='email@example.com:password'
 *
 *   # 2. reachability + TIN lookup (uses config, or --token=... --tin=...):
 *   php tools/efris_weaf_drill.php --token=XXXX
 *
 *   # 3. fiscalise ONE dummy invoice in the sandbox (their pre-registered
 *   #    "Sample Deemed Item", their sample buyer) and print the URA-test
 *   #    invoice number, antifake code and validation QR:
 *   php tools/efris_weaf_drill.php --token=XXXX --invoice
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/WeafEfrisClient.php';

$dataDir = getenv('DN_DATA_DIR') ?: getDataDir($root);
$config  = PluginConfig::load($root, $dataDir);

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) $args[$m[1]] = $m[2] ?? '1';
}
$config['efris_gateway'] = 'weaf';
if (!empty($args['token'])) $config['efris_weaf_token'] = (string)$args['token'];
if (!empty($args['tin']))   $config['efris_tin']        = (string)$args['tin'];
if (!empty($args['base']))  $config['efris_weaf_base_url'] = (string)$args['base'];
if (trim((string)($config['efris_tin'] ?? '')) === '') $config['efris_tin'] = '1015264035'; // vendor shared dev TIN
$base = rtrim((string)($config['efris_weaf_base_url'] ?? 'https://weafcompany.com'), '/');

echo "══ WEAF EFRIS sandbox drill\n";
echo "   base: {$base} · TIN: {$config['efris_tin']} · environment: "
   . (string)($config['efris_environment'] ?? '') . "\n\n";

if (strtolower(trim((string)($config['efris_environment'] ?? ''))) !== 'test') {
    fwrite(STDERR, "ABORT: the drill runs only with efris_environment=test.\n");
    exit(1);
}

// ── Token generation (prints it; never stores it) ───────────────────────────
if (!empty($args['login'])) {
    [$u, $p] = array_pad(explode(':', (string)$args['login'], 2), 2, '');
    if ($u === '' || $p === '') { fwrite(STDERR, "--login needs email:password\n"); exit(2); }
    $ch = curl_init($base . '/api/v1/auth/generate-token');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['username' => $u, 'password' => $p,
            'expiry_days' => 30, 'token_name' => 'DishNet uCRM sandbox'])]);
    $raw = curl_exec($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $j = json_decode((string)$raw, true) ?: [];
    $tok = (string)($j['data']['token'] ?? '');
    if ($tok !== '') {
        echo "TOKEN GENERATED (valid " . (string)($j['data']['expires_in_days'] ?? '?') . " days):\n\n  {$tok}\n\n";
        echo "Add it to Configuration as efris_weaf_token, then re-run the drill with --invoice.\n";
        foreach ((array)($j['data']['companies'] ?? []) as $co) {
            echo "  account company: " . (string)($co['business_name'] ?? '') . " (TIN " . (string)($co['tin'] ?? '') . ")\n";
        }
        exit(0);
    }
    echo "Token generation failed (HTTP {$http}): " . substr((string)$raw, 0, 500) . "\n";
    exit(1);
}

$client = new WeafEfrisClient($config, 30);
if (!$client->isUsable()) { fwrite(STDERR, 'ABORT: ' . $client->refusalReason() . "\n"); exit(1); }

$ok = 0; $bad = 0;
$step = function (string $label, bool $pass, string $note = '') use (&$ok, &$bad): void {
    $pass ? $ok++ : $bad++;
    printf("  %s  %s%s\n", $pass ? 'PASS' : 'FAIL', $label, $note !== '' ? " — {$note}" : '');
};

$r = $client->ping();
$step('validate-token (reachability + auth)', $r['ok'], $r['ok'] ? '' : $r['error']);
if (!$r['ok']) { echo "\nFix the token first (--login generates one).\n"; exit(1); }

$lookupTin = (string)($args['check-tin'] ?? '1017196396');   // vendor sample buyer
$tv = $client->queryTin($lookupTin);
$step("search-taxpayer {$lookupTin}", $tv['ok'],
      $tv['ok'] ? (string)($tv['content']['taxpayerName'] ?? '') : $tv['error']);

if (!empty($args['invoice'])) {
    $model = [
        'seller'  => ['legal_name' => 'DishNet Africa Limited', 'business_name' => 'DishNet Uganda',
                      'address' => 'Kampala', 'tin' => (string)$config['efris_tin'], 'device_no' => 'VIA-WEAF'],
        'invoice' => ['ucrm_id' => 0, 'number' => 'DN-DRILL-' . gmdate('YmdHis'),
                      'issued_date' => gmdate('Y-m-d\TH:i:s'), 'currency' => 'UGX'],
        'buyer'   => ['type' => 'business', 'type_code' => 0,
                      'name' => 'WEAF COMPANY UGANDA LIMITED', 'tin' => '1017196396',
                      'address' => 'Kampala Road', 'email' => '', 'phone' => '', 'nin' => '', 'brn' => ''],
        'items'   => [['label' => 'Sample Deemed Item', 'qty' => 1.0, 'unit_price' => 2000000.0,
                       'line_total' => 2000000.0, 'discount' => 0.0, 'tax_category' => 'standard',
                       'tax' => ['amount' => null]]],
    ];
    $r = $client->submitInvoice($model);
    $step('generate-fiscal-invoice (dummy, sandbox)', $r['ok'], $r['ok'] ? '' : $r['error']);
    if ($r['ok']) {
        $c = (array)$r['content'];
        echo "\n  ── URA TEST fiscal document ──\n";
        echo "  invoiceNo (FDN): " . $c['fdn'] . "\n";
        echo "  antifake code:   " . $c['verificationCode'] . "\n";
        echo "  validation QR:   " . $c['qrCode'] . "\n";
        echo "  gross/net/tax:   " . $c['gross'] . ' / ' . $c['net'] . ' / ' . $c['tax'] . "\n";
    }
} else {
    echo "  skip  generate-fiscal-invoice — add --invoice to fiscalise one dummy sandbox invoice\n";
}

printf("\n%d passed, %d failed\n", $ok, $bad);
exit($bad ? 1 : 0);
