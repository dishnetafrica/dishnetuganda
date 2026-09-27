<?php
declare(strict_types=1);
/**
 * FAKE uCRM (scheduling) — for scripts/harness/jobs-trace/trace.php only; loopback, fictitious data.
 * Serves the few endpoints the plugin's job code calls and records every request.
 * State lives in the file named by FAKE_UCRM_STATE.
 */
$stateFile = (string)getenv('FAKE_UCRM_STATE');
$state = is_file($stateFile) ? (json_decode((string)file_get_contents($stateFile), true) ?: []) : [];
$state += ['jobs' => [], 'users' => [], 'clients' => [], 'tasks' => [], 'comments' => [], 'requests' => [], 'next_job' => 950, 'next_task' => 7000];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri    = (string)($_SERVER['REQUEST_URI'] ?? '');
$path   = parse_url($uri, PHP_URL_PATH) ?: '';
$path   = preg_replace('#^/api/v[0-9.]+#', '', $path);
$raw    = (string)file_get_contents('php://input');
$body   = json_decode($raw, true) ?: [];

function out($data, int $code = 200): void {
    global $state, $stateFile;
    http_response_code($code); header('Content-Type: application/json');
    echo json_encode($data);
    file_put_contents($stateFile, json_encode($state));
    exit;
}

if ($path === '/__test/state')    { echo 'FAKE-UCRM-JOBS'; exit; }
if ($path === '/__test/dump')     { header('Content-Type: application/json'); echo json_encode($state); exit; }
if ($path === '/__test/seed' && $method === 'POST') { $state = array_merge($state, $body); out(['seeded' => true]); }

$state['requests'][] = ['method' => $method, 'path' => $path, 'query' => (string)parse_url($uri, PHP_URL_QUERY), 'body' => $body];

if ($method === 'GET' && preg_match('#^/scheduling/jobs/(\d+)/job-tasks$#', $path, $m)) {
    out(array_values(array_filter($state['tasks'], fn($t) => (int)$t['jobId'] === (int)$m[1])));
}
if ($method === 'POST' && preg_match('#^/scheduling/jobs/(\d+)/job-tasks$#', $path, $m)) {
    $t = ['id' => $state['next_task']++, 'jobId' => (int)$m[1], 'label' => (string)($body['name'] ?? $body['label'] ?? ''), 'closed' => false];
    $state['tasks'][] = $t; out($t, 201);
}
if ($method === 'PATCH' && preg_match('#^/scheduling/job-tasks/(\d+)$#', $path, $m)) {
    foreach ($state['tasks'] as &$t) if ((int)$t['id'] === (int)$m[1]) { $t['closed'] = (bool)($body['closed'] ?? false); $r = $t; }
    unset($t); out($r ?? ['code' => 404], isset($r) ? 200 : 404);
}
if ($method === 'POST' && preg_match('#^/scheduling/jobs/(\d+)/job-comments$#', $path, $m)) {
    $c = ['id' => count($state['comments']) + 1, 'jobId' => (int)$m[1], 'message' => (string)($body['message'] ?? '')];
    $state['comments'][] = $c; out($c, 201);
}
if ($method === 'GET' && preg_match('#^/scheduling/jobs/(\d+)$#', $path, $m)) {
    $j = $state['jobs'][$m[1]] ?? null;
    out($j ?? ['code' => 404, 'message' => 'Not found'], $j ? 200 : 404);
}
if ($method === 'PATCH' && preg_match('#^/scheduling/jobs/(\d+)$#', $path, $m)) {
    if (!isset($state['jobs'][$m[1]])) out(['code' => 404], 404);
    $state['jobs'][$m[1]] = array_merge($state['jobs'][$m[1]], $body);
    out($state['jobs'][$m[1]]);
}
if ($method === 'GET' && $path === '/scheduling/jobs') {
    out(array_values($state['jobs']));     // like uCRM: assignee filters are not honoured here
}
if ($method === 'POST' && $path === '/scheduling/jobs') {
    $id = $state['next_job']++;
    $j  = ['id' => $id, 'title' => '', 'description' => '', 'clientId' => null, 'assignedUserId' => null,
           'date' => null, 'duration' => 60, 'status' => 0, 'address' => null, 'gpsLat' => null, 'gpsLon' => null];
    $j = array_merge($j, array_intersect_key($body, $j));
    $state['jobs'][(string)$id] = $j;
    out($j, 201);
}
if ($method === 'GET' && preg_match('#^/users/(?:admins/)?(\d+)$#', $path, $m)) {
    $u = $state['users'][$m[1]] ?? null;
    out($u ?? ['code' => 404, 'message' => 'Not found'], $u ? 200 : 404);
}
if ($method === 'GET' && preg_match('#^/clients/(\d+)$#', $path, $m)) {
    $c = $state['clients'][$m[1]] ?? null;
    out($c ?? ['code' => 404], $c ? 200 : 404);
}
if ($method === 'GET' && preg_match('#^/clients/(\d+)/services$#', $path)) out([]);
if ($method === 'GET' && $path === '/invoices') out([]);
if ($method === 'GET' && $path === '/clients') out(array_values($state['clients']));
out(['code' => 404, 'message' => 'FAKE-UCRM-JOBS: not simulated: ' . $method . ' ' . $path], 404);
