<?php
declare(strict_types=1);
/**
 * ████ A STUB EVOLUTION — TEST ONLY ████ (5.18.104, root docs/73 §H.4.16 G)
 *
 * The answers the repository's fake Evolution cannot give, for EvolutionOfferGateway's classification: a 200 with no
 * message id, an HTTP 400, an HTTP 504 (a gateway in front of Evolution that broke off: "may have been sent"). Every send
 * is recorded, so a test can tell a refusal before any POST from a POST that was answered.
 *
 *     php -S 127.0.0.1:<port> tests/wa_offers/evo_stub.php
 *     GET /__mode?m=ok|noid|http400|http500|http504     GET /__calls     GET /__marker
 */
$state = sys_get_temp_dir() . '/wo_evo_stub_' . (int)($_SERVER['SERVER_PORT'] ?? 0) . '.json';
$s = is_file($state) ? (json_decode((string)file_get_contents($state), true) ?: []) : [];
$s += ['mode' => 'ok', 'calls' => []];
$path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
$out = function ($data, int $code = 200) use (&$s, $state): void {
    file_put_contents($state, json_encode($s));
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
};
if ($path === '/__marker') $out(['marker' => 'WO-EVO-STUB']);
if ($path === '/__mode') { $s['mode'] = (string)($_GET['m'] ?? 'ok'); $s['calls'] = []; $out(['mode' => $s['mode']]); }
if ($path === '/__calls') $out($s['calls']);
if (preg_match('#^/message/send(Text|Media)/(.+)$#', $path, $m)) {
    $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $s['calls'][] = ['kind' => strtolower($m[1]), 'instance' => rawurldecode($m[2]), 'number' => (string)($body['number'] ?? '')];
    $n = count($s['calls']);
    if ($s['mode'] === 'noid') $out(['status' => 'PENDING']);
    if ($s['mode'] === 'http400') $out(['message' => 'WO-STUB-REJECTED instance ' . rawurldecode($m[2])], 400);
    if ($s['mode'] === 'http504') $out(['message' => 'WO-STUB-GATEWAY-TIMEOUT'], 504);
    if ($s['mode'] === 'http500') $out(['message' => 'WO-STUB-SERVER-ERROR instance ' . rawurldecode($m[2])], 500);
    $out(['key' => ['id' => 'WO-STUB-MSG-' . $n], 'status' => 'PENDING']);
}
$out(['error' => 'WO-STUB: no such path'], 404);
