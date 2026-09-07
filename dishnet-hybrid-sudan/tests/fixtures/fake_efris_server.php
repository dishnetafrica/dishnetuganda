<?php
declare(strict_types=1);

/**
 * ████████████████ FAKE EFRIS SERVER — TEST ONLY ████████████████
 *
 * This is NOT the Uganda Revenue Authority. It exists so the plugin's EFRIS
 * layer can be exercised end-to-end without URA credentials. Every value it
 * returns is prefixed TEST- so nothing it produces can ever be mistaken for
 * a real fiscal document number or verification code.
 *
 * Run under php -S as a router:
 *     php -S 127.0.0.1:9099 tests/fixtures/fake_efris_server.php
 *
 * Behaviours are triggered by the invoice number inside the submitted
 * payload (data.content base64 JSON, invoice.number):
 *     contains REJECT    → business rejection (returnCode 99)
 *     contains AUTHFAIL  → authentication failure (returnCode 403)
 *     contains PENDINGX  → processing / not final (returnCode 01)
 *     contains MALFORMED → broken JSON body back
 *     contains TIMEOUT   → sleeps 8s (client timeout wins)
 *     anything else      → success with sequential TEST- fiscal values;
 *                          resubmitting the SAME number returns the SAME
 *                          TEST-FDN (server-side idempotency, like URA).
 */

header('X-Fake-Efris: TEST-ONLY');

$stateFile = sys_get_temp_dir() . '/fake_efris_state_' . md5(__FILE__ . ($_SERVER['SERVER_PORT'] ?? '')) . '.json';
$state = is_file($stateFile) ? (json_decode((string)file_get_contents($stateFile), true) ?: []) : [];
$state += ['seq' => 0, 'seen' => [], 'goods' => [], 'stock' => [], 'cns' => [], 'cn_cancelled' => []];

function fe_save(): void
{
    @file_put_contents($GLOBALS['stateFile'], json_encode($GLOBALS['state']));
}

function fe_reply(array $contentArr, string $code = '00', string $msg = 'SUCCESS'): void
{
    echo json_encode([
        'returnStateInfo' => ['returnCode' => $code, 'returnMessage' => $msg],
        'data' => [
            'content'   => base64_encode(json_encode($contentArr)),
            'signature' => 'TEST-SERVER-SIGNATURE',
            'dataDescription' => ['codeType' => '0', 'encryptCode' => '0', 'zipCode' => '0'],
        ],
        'globalInfo' => ['note' => 'FAKE EFRIS TEST SERVER — NOT URA'],
    ]);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$req = json_decode($raw, true);
if (!is_array($req)) {
    http_response_code(400);
    fe_reply([], '400', 'TEST server: request body is not JSON');
}

$code = (string)($req['globalInfo']['interfaceCode'] ?? '');
$payload = [];
$b64 = $req['data']['content'] ?? '';
if (is_string($b64) && $b64 !== '') {
    $payload = json_decode((string)base64_decode($b64, true), true) ?: [];
}

// T101 — server time (reachability probe)
if ($code === 'T101') {
    fe_reply(['currentTime' => gmdate('Y-m-d H:i:s'), 'server' => 'FAKE-EFRIS-TEST']);
}

// T109 — invoice upload
if ($code === 'T109') {
    $number = (string)($payload['invoice']['number'] ?? '');

    if (stripos($number, 'TIMEOUT') !== false)  { sleep(8); fe_reply(['late' => true]); }
    if (stripos($number, 'MALFORMED') !== false) { echo '{this is not json'; exit; }
    if (stripos($number, 'AUTHFAIL') !== false) {
        fe_reply([], '403', 'TEST authentication failure: device not recognised');
    }
    if (stripos($number, 'REJECT') !== false) {
        fe_reply([], '99', 'TEST rejection: buyer TIN failed validation');
    }
    if (stripos($number, 'PENDINGX') !== false) {
        fe_reply([], '01', 'TEST: still processing, query again later');
    }
    if ($number === '') {
        fe_reply([], '98', 'TEST rejection: no invoice number in payload');
    }

    // Success — idempotent per invoice number, like the real thing.
    if (isset($state['seen'][$number])) {
        $s = $state['seen'][$number];
        fe_reply($s + ['duplicate' => true], '00', 'SUCCESS (already fiscalised — TEST)');
    }
    $state['seq']++;
    $n = str_pad((string)$state['seq'], 6, '0', STR_PAD_LEFT);
    $fiscal = [
        'fdn'              => "TEST-FDN-{$n}",
        'verificationCode' => "TEST-VERIFICATION-{$n}",
        'qrCode'           => "TEST-QR|TEST-FDN-{$n}|" . gmdate('YmdHis'),
        'referenceNo'      => "TEST-REF-{$n}",
        'fiscalisedAt'     => gmdate('Y-m-d H:i:s'),
    ];
    $state['seen'][$number] = $fiscal;
    fe_save();
    fe_reply($fiscal, '00', 'SUCCESS (TEST)');
}

// T119 — query taxpayer by TIN. TINs starting 999 are "not registered".
if ($code === 'T119') {
    $tin = (string)($payload['tin'] ?? '');
    if (!preg_match('/^\d{10}$/', $tin)) {
        fe_reply([], '98', 'TEST: TIN must be 10 digits');
    }
    if (strpos($tin, '999') === 0) {
        fe_reply([], '99', "TEST: TIN {$tin} is not registered with URA");
    }
    fe_reply([
        'tin'          => $tin,
        'taxpayerName' => 'TEST TAXPAYER ' . substr($tin, -4),
        'status'       => 'REGISTERED',
    ], '00', 'SUCCESS (TEST)');
}

// T130 — goods/services registration. Idempotent per goods_code.
if ($code === 'T130') {
    $g = (array)($payload['goods'] ?? []);
    $name = (string)($g['name'] ?? '');
    $gc   = (string)($g['goods_code'] ?? '');
    if ($name === '' || $gc === '')          fe_reply([], '98', 'TEST: goods name and goods_code are required');
    if ((string)($g['commodity_code'] ?? '') === '') fe_reply([], '98', 'TEST: commodity_code is required');
    if (stripos($name, 'REJECT') !== false)  fe_reply([], '99', 'TEST rejection: commodity code not on the URA product list');
    if (isset($state['goods'][$gc])) {
        fe_reply($state['goods'][$gc] + ['duplicate' => true], '00', 'SUCCESS (already registered — TEST)');
    }
    $state['seq']++;
    $n = str_pad((string)$state['seq'], 6, '0', STR_PAD_LEFT);
    $reg = ['goodsReference' => "TEST-GOODS-{$n}", 'goodsCode' => $gc];
    $state['goods'][$gc] = $reg;
    $state['stock'][$gc] = 0.0;
    fe_save();
    fe_reply($reg, '00', 'SUCCESS (TEST)');
}

// T131 — stock maintenance. Requires the goods_code to be registered first.
if ($code === 'T131') {
    $s  = (array)($payload['stock'] ?? []);
    $gc = (string)($s['goods_code'] ?? '');
    $op = (string)($s['op'] ?? '');
    $q  = (float)($s['qty'] ?? 0);
    if ($gc === '' || !isset($state['goods'][$gc])) {
        fe_reply([], '99', 'TEST: goods_code is not registered — upload the item first (T130)');
    }
    if (!in_array($op, ['increase', 'decrease'], true) || $q <= 0) {
        fe_reply([], '98', 'TEST: stock op must be increase/decrease with a positive qty');
    }
    $cur = (float)($state['stock'][$gc] ?? 0);
    if ($op === 'decrease' && $q > $cur) {
        fe_reply([], '99', "TEST: insufficient stock — {$cur} on hand");
    }
    $state['stock'][$gc] = $op === 'increase' ? $cur + $q : $cur - $q;
    $state['seq']++;
    $n = str_pad((string)$state['seq'], 6, '0', STR_PAD_LEFT);
    fe_save();
    fe_reply(['stockReference' => "TEST-STOCK-{$n}", 'goodsCode' => $gc,
              'resultingQty' => $state['stock'][$gc]], '00', 'SUCCESS (TEST)');
}

// T110 — credit note application: the original FDN must exist here.
if ($code === 'T110') {
    $number  = (string)($payload['credit_note']['number'] ?? '');
    $origFdn = (string)($payload['original']['fdn'] ?? '');
    $reason  = (string)($payload['reason'] ?? '');
    $known = false;
    foreach ((array)$state['seen'] as $f) {
        if (($f['fdn'] ?? '') === $origFdn) { $known = true; break; }
    }
    if ($origFdn === '' || !$known)          fe_reply([], '99', 'TEST: original invoice FDN not found — nothing to credit');
    if ($reason === '')                       fe_reply([], '98', 'TEST: a credit note requires a reason');
    if (stripos($number, 'REJECT') !== false) fe_reply([], '99', 'TEST rejection: credit amount exceeds the original invoice');
    if (isset($state['cns'][$number])) {
        fe_reply($state['cns'][$number] + ['duplicate' => true], '00', 'SUCCESS (already applied — TEST)');
    }
    $state['seq']++;
    $n = str_pad((string)$state['seq'], 6, '0', STR_PAD_LEFT);
    $cn = [
        'fdn'              => "TEST-CN-FDN-{$n}",
        'verificationCode' => "TEST-CN-VERIFICATION-{$n}",
        'qrCode'           => "TEST-QR|TEST-CN-FDN-{$n}|" . gmdate('YmdHis'),
        'referenceNo'      => "TEST-CN-REF-{$n}",
        'fiscalisedAt'     => gmdate('Y-m-d H:i:s'),
    ];
    $state['cns'][$number] = $cn;
    fe_save();
    fe_reply($cn, '00', 'SUCCESS (TEST)');
}

// T114 — cancel a credit note application by its CN FDN.
if ($code === 'T114') {
    $cnFdn = (string)($payload['cancel']['credit_note_fdn'] ?? '');
    $known = false;
    foreach ((array)$state['cns'] as $c) {
        if (($c['fdn'] ?? '') === $cnFdn) { $known = true; break; }
    }
    if ($cnFdn === '' || !$known)               fe_reply([], '99', 'TEST: credit note FDN not found');
    if (in_array($cnFdn, (array)$state['cn_cancelled'], true)) {
        fe_reply([], '99', 'TEST: credit note is already cancelled');
    }
    if ((string)($payload['cancel']['reason'] ?? '') === '') {
        fe_reply([], '98', 'TEST: cancellation requires a reason');
    }
    $state['cn_cancelled'][] = $cnFdn;
    $state['seq']++;
    $n = str_pad((string)$state['seq'], 6, '0', STR_PAD_LEFT);
    fe_save();
    fe_reply(['referenceNo' => "TEST-CANCEL-{$n}", 'cancelledFdn' => $cnFdn], '00', 'SUCCESS (TEST)');
}

fe_reply([], '96', "TEST server: interface code '{$code}' not simulated");
