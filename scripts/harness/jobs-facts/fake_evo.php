<?php
/** FAKE Evolution for the jobs-facts rehearsal: connection state only; every request is logged to FAKE_EVO_LOG. */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
if ($path === '/__ping') { echo 'FAKE-EVO-JOBS-FACTS'; exit; }
file_put_contents((string)getenv('FAKE_EVO_LOG'), $method . ' ' . $path . "\n", FILE_APPEND);
header('Content-Type: application/json');
if ($method === 'GET' && preg_match('#^/instance/connectionState/(.+)$#', $path, $m)) {
    echo json_encode(['instance' => ['instanceName' => $m[1], 'state' => strpos($m[1], 'support') !== false ? 'open' : 'close']]); exit;
}
http_response_code(404); echo json_encode(['error' => 'not simulated']);
