<?php
/**
 * FAKE uCRM for the jobs-facts rehearsal — loopback only, canary data only.
 * The answers come from FAKE_UCRM_SEED (written by seed.php); every request is
 * appended to FAKE_UCRM_LOG so the rehearsal can prove the report only reads.
 */
$seed = json_decode((string)@file_get_contents((string)getenv('FAKE_UCRM_SEED')), true) ?: [];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
$path = preg_replace('#^/api/v[0-9.]+#', '', $path);
if ($path === '/__ping') { echo 'FAKE-UCRM-JOBS-FACTS'; exit; }
file_put_contents((string)getenv('FAKE_UCRM_LOG'), $method . ' ' . $path . "\n", FILE_APPEND);
function send($d, int $c = 200): void { http_response_code($c); header('Content-Type: application/json'); echo json_encode($d); exit; }
if ($method !== 'GET') send(['code' => 405, 'message' => 'the rehearsal fake answers GET only'], 405);
$admins = (array)($seed['admins'] ?? []);
if ($path === '/users/admins') send(array_values($admins));
if (preg_match('#^/users/admins/(\d+)$#', $path, $m)) { foreach ($admins as $a) if ((int)$a['id'] === (int)$m[1]) send($a); send(['code' => 404], 404); }
if (preg_match('#^/users/(\d+)$#', $path)) send(['code' => 404, 'message' => 'No route found'], 404);
if ($path === '/scheduling/jobs') send(array_values((array)($seed['jobs'] ?? [])));
if (preg_match('#^/scheduling/jobs/(\d+)$#', $path, $m)) { foreach ((array)($seed['jobs'] ?? []) as $j) if ((int)$j['id'] === (int)$m[1]) send($j + ['attachments' => [], 'tasks' => []]); send(['code' => 404], 404); }
if ($path === '/webhooks/endpoints') send(array_values((array)($seed['endpoints'] ?? [])));
if ($path === '/organizations') send([['id' => 1, 'name' => 'Canary Organisation']]);
send(['code' => 404, 'message' => 'not simulated: ' . $path], 404);
