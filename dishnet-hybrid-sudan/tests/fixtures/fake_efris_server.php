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
$state += ['seq' => 0, 'seen' => []];

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
    @file_put_contents($GLOBALS['stateFile'], json_encode($state));
    fe_reply($fiscal, '00', 'SUCCESS (TEST)');
}

fe_reply([], '96', "TEST server: interface code '{$code}' not simulated");
