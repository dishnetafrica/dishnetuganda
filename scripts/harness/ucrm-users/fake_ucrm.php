<?php
/**
 * FAKE uCRM for the ucrm-users rehearsal — loopback only, canary data only.
 * Answers come from FAKE_UCRM_SEED (written by seed.php); every request is appended to
 * FAKE_UCRM_LOG so the rehearsal can prove the report only reads.
 */
$seed = json_decode((string)@file_get_contents((string)getenv('FAKE_UCRM_SEED')), true) ?: [];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
$path = preg_replace('#^/api/v[0-9.]+#', '', $path);
if ($path === '/__ping') { echo 'FAKE-UCRM-USERS-FACTS'; exit; }
file_put_contents((string)getenv('FAKE_UCRM_LOG'), $method . ' ' . $path . "\n", FILE_APPEND);
function send($d, int $c = 200): void { http_response_code($c); header('Content-Type: application/json'); echo json_encode($d); exit; }
if ($method !== 'GET') send(['code' => 405, 'message' => 'the rehearsal fake answers GET only'], 405);
$list = (array)($seed['list'] ?? []);
$detail = (array)($seed['detail'] ?? []);
if ($path === '/users/admins') send(array_values($list));
if (preg_match('#^/users/admins/(\d+)$#', $path, $m)) { if (isset($detail[$m[1]])) send($detail[$m[1]]); send(['code' => 404, 'message' => 'Not found'], 404); }
if (preg_match('#^/users/(\d+)$#', $path, $m)) { if (in_array((int)$m[1], (array)($seed['users_route_ok'] ?? []), true)) send($detail[$m[1]] ?? ['id' => (int)$m[1]]); send(['code' => 404, 'message' => 'No route found'], 404); }
foreach (['/clients' => 'clients', '/invoices' => 'invoices', '/payments' => 'payments'] as $p => $k) if ($path === $p) send(array_values((array)($seed[$k] ?? [])));
send(['code' => 404, 'message' => 'not simulated: ' . $path], 404);
