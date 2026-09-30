<?php
declare(strict_types=1);
/**
 * ████ FAKE uCRM — staff users and scheduling jobs — TEST ONLY ████
 *
 * For the 5.18.50 staff and job tests (docs/44, release A). Serves the endpoints the plugin's staff and job code
 * calls, records every request, and keeps its state in the file named by FAKE_UCRM_STATE. Every person, e-mail, phone
 * and job here is fictitious.
 *
 *   users/admins, users/admins/{id}, users/{id}          — staff users (isActive, e-mail), 404 for an unknown id
 *   scheduling/jobs (GET, POST), scheduling/jobs/{id}    — jobs (GET, PATCH)
 *   scheduling/jobs/{id}/job-tasks (GET, POST), scheduling/job-tasks/{id} (PATCH), scheduling/jobs/{id}/job-comments
 *   clients/{id}, clients/{id}/client-logs, clients/{id} (PATCH)
 *   billing/credit-notes (POST), credit-notes/{id} and billing/credit-notes/{id} (GET) — credit notes (5.18.54)
 *   clients/services/{id} (GET) — one service, seeded under "services" (5.18.54)
 *
 * Test controls: /__test/state (marker), /__test/dump, /__test/seed (POST, merges keys), /__test/users_down (POST
 * {"down":true}) makes every users endpoint answer 502 — "uCRM could not be reached".
 *
 * 5.18.52 (docs/44 J4), each off unless set: /__test/jobs_down {"down":true} — GET and PATCH of one job answer 502;
 * /__test/jobs_partial {"partial":true} — a job read answers without assignedUserId (V4); /__test/post_override
 * {"fields":{…}} — uCRM stores these values on the next jobs it creates, whatever was posted (T4.13);
 * /__test/delete_job {"id":N} — the job is gone, as if deleted in uCRM's own screen.
 */
$stateFile = (string)getenv('FAKE_UCRM_STATE');
$state = is_file($stateFile) ? (json_decode((string)file_get_contents($stateFile), true) ?: []) : [];
$state += ['jobs' => [], 'users' => [], 'clients' => [], 'tasks' => [], 'comments' => [], 'logs' => [],
           'requests' => [], 'next_job' => 950, 'next_task' => 7000, 'users_down' => false,
           'jobs_down' => false, 'jobs_partial' => false, 'post_override' => []];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri    = (string)($_SERVER['REQUEST_URI'] ?? '');
$path   = parse_url($uri, PHP_URL_PATH) ?: '';
$path   = (string)preg_replace('#^/api/v[0-9.]+#', '', $path);
$raw    = (string)file_get_contents('php://input');
$body   = json_decode($raw, true) ?: [];

function fu_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    file_put_contents($GLOBALS['stateFile'], json_encode($GLOBALS['state']));
    exit;
}

if ($path === '/__test/state') { echo 'FAKE-UCRM-STAFF-JOBS'; exit; }
if ($path === '/__test/dump')  { header('Content-Type: application/json'); echo json_encode($state); exit; }
if ($path === '/__test/seed' && $method === 'POST') { $state = array_merge($state, $body); fu_out(['seeded' => true]); }
if ($path === '/__test/users_down' && $method === 'POST') { $state['users_down'] = !empty($body['down']); fu_out(['ok' => true]); }
if ($path === '/__test/jobs_down' && $method === 'POST') { $state['jobs_down'] = !empty($body['down']); fu_out(['ok' => true]); }
if ($path === '/__test/jobs_partial' && $method === 'POST') { $state['jobs_partial'] = !empty($body['partial']); fu_out(['ok' => true]); }
if ($path === '/__test/post_override' && $method === 'POST') { $state['post_override'] = (array)($body['fields'] ?? []); fu_out(['ok' => true]); }
if ($path === '/__test/delete_job' && $method === 'POST') { unset($state['jobs'][(string)(int)($body['id'] ?? 0)]); fu_out(['ok' => true]); }

$state['requests'][] = ['method' => $method, 'path' => $path, 'query' => (string)parse_url($uri, PHP_URL_QUERY), 'body' => $body];

// ── Staff users ──────────────────────────────────────────────────────────────
if ($method === 'GET' && ($path === '/users/admins' || preg_match('#^/users/(?:admins/)?\d+$#', $path))) {
    if (!empty($state['users_down'])) fu_out(['code' => 502, 'message' => 'Bad gateway (test control)'], 502);
    if ($path === '/users/admins') fu_out(array_values($state['users']));
    preg_match('#(\d+)$#', $path, $m);
    $u = $state['users'][$m[1]] ?? null;
    fu_out($u ?? ['code' => 404, 'message' => 'Not found'], $u ? 200 : 404);
}

// ── Scheduling ───────────────────────────────────────────────────────────────
if ($method === 'GET' && preg_match('#^/scheduling/jobs/(\d+)/job-tasks$#', $path, $m)) {
    fu_out(array_values(array_filter($state['tasks'], function ($t) use ($m) { return (int)$t['jobId'] === (int)$m[1]; })));
}
if ($method === 'POST' && preg_match('#^/scheduling/jobs/(\d+)/job-tasks$#', $path, $m)) {
    $t = ['id' => $state['next_task']++, 'jobId' => (int)$m[1], 'label' => (string)($body['name'] ?? $body['label'] ?? ''), 'closed' => false];
    $state['tasks'][] = $t;
    fu_out($t, 201);
}
if ($method === 'PATCH' && preg_match('#^/scheduling/job-tasks/(\d+)$#', $path, $m)) {
    $r = null;
    foreach ($state['tasks'] as &$t) {
        if ((int)$t['id'] === (int)$m[1]) { $t['closed'] = (bool)($body['closed'] ?? false); $r = $t; }
    }
    unset($t);
    fu_out($r ?? ['code' => 404], $r ? 200 : 404);
}
if ($method === 'GET' && preg_match('#^/scheduling/jobs/(\d+)/job-comments$#', $path, $m)) {
    fu_out(array_values(array_filter($state['comments'], function ($c) use ($m) { return (int)$c['jobId'] === (int)$m[1]; })));
}
if ($method === 'POST' && preg_match('#^/scheduling/jobs/(\d+)/job-comments$#', $path, $m)) {
    $c = ['id' => count($state['comments']) + 1, 'jobId' => (int)$m[1], 'message' => (string)($body['message'] ?? '')];
    $state['comments'][] = $c;
    fu_out($c, 201);
}
if ($method === 'GET' && preg_match('#^/scheduling/jobs/(\d+)$#', $path, $m)) {
    if (!empty($state['jobs_down'])) fu_out(['code' => 502, 'message' => 'Bad gateway (test control)'], 502);
    $j = $state['jobs'][$m[1]] ?? null;
    if ($j && !empty($state['jobs_partial'])) unset($j['assignedUserId']);
    fu_out($j ?? ['code' => 404, 'message' => 'Not found'], $j ? 200 : 404);
}
if ($method === 'PATCH' && preg_match('#^/scheduling/jobs/(\d+)$#', $path, $m)) {
    if (!empty($state['jobs_down'])) fu_out(['code' => 502, 'message' => 'Bad gateway (test control)'], 502);
    if (!isset($state['jobs'][$m[1]])) fu_out(['code' => 404], 404);
    $state['jobs'][$m[1]] = array_merge($state['jobs'][$m[1]], $body);
    fu_out($state['jobs'][$m[1]]);
}
if ($method === 'GET' && $path === '/scheduling/jobs') {
    // Like uCRM, an assignee filter is honoured when asked for; everything else is returned as is.
    parse_str((string)parse_url($uri, PHP_URL_QUERY), $qs);
    $jobs = array_values($state['jobs']);
    if (isset($qs['assignedUserId'])) {
        $want = (int)$qs['assignedUserId'];
        $jobs = array_values(array_filter($jobs, function ($j) use ($want) { return (int)($j['assignedUserId'] ?? 0) === $want; }));
    }
    fu_out($jobs);
}
if ($method === 'POST' && $path === '/scheduling/jobs') {
    $id = $state['next_job']++;
    $j  = ['id' => $id, 'title' => '', 'description' => '', 'clientId' => null, 'assignedUserId' => null,
           'date' => null, 'duration' => 60, 'status' => 0, 'address' => null, 'gpsLat' => null, 'gpsLon' => null];
    $j = array_merge($j, array_intersect_key($body, $j), (array)($state['post_override'] ?? []));
    $state['jobs'][(string)$id] = $j;
    fu_out($j, 201);
}

// ── Clients ──────────────────────────────────────────────────────────────────
if ($method === 'GET' && preg_match('#^/clients/(\d+)$#', $path, $m)) {
    $c = $state['clients'][$m[1]] ?? null;
    fu_out($c ?? ['code' => 404], $c ? 200 : 404);
}
if ($method === 'PATCH' && preg_match('#^/clients/(\d+)$#', $path, $m)) {
    if (!isset($state['clients'][$m[1]])) fu_out(['code' => 404], 404);
    $state['clients'][$m[1]] = array_merge($state['clients'][$m[1]], $body);
    fu_out($state['clients'][$m[1]]);
}
if ($method === 'POST' && preg_match('#^/clients/(\d+)/client-logs$#', $path, $m)) {
    $l = ['id' => count($state['logs']) + 1, 'clientId' => (int)$m[1], 'message' => (string)($body['message'] ?? '')];
    $state['logs'][] = $l;
    fu_out($l, 201);
}
if ($method === 'GET' && preg_match('#^/clients/(\d+)/services$#', $path)) fu_out([]);
// 5.18.54 (docs/46 row 13): one service, as service.add reads it back; 404 unless seeded under "services".
if ($method === 'GET' && preg_match('#^/clients/services/(\d+)$#', $path, $m)) {
    $sv = $state['services'][$m[1]] ?? null;
    $sv === null ? fu_out(['code' => 404, 'message' => 'Service not found.'], 404) : fu_out($sv);
}
if ($method === 'GET' && $path === '/clients') fu_out(array_values($state['clients']));
// 5.18.54 (docs/46 row 11): invoice lists and uCRM's settings document, for the notification controls. Unseeded, the
// invoice list is empty, as before.
if ($method === 'GET' && ($path === '/invoices' || $path === '/billing/invoices')) fu_out(array_values((array)($state['invoices'] ?? [])));
if ($method === 'GET' && preg_match('#^/(?:billing/)?invoices/(\d+)$#', $path, $m)) {
    $inv = $state['invoices'][$m[1]] ?? null;
    $inv === null ? fu_out(['code' => 404, 'message' => 'Invoice not found.'], 404) : fu_out($inv);
}
if ($path === '/options') {
    if ($method === 'PATCH') { $state['options'] = array_merge((array)($state['options'] ?? []), $body); fu_out($state['options']); }
    fu_out((array)($state['options'] ?? []));
}
// 5.18.54 (docs/46 row 12): credit notes, as the staff screen creates them and the webhook reads them back.
if ($method === 'POST' && ($path === '/billing/credit-notes' || $path === '/billing/credit-notes/add')) {
    $id = (int)($state['next_cn'] ?? 4400);
    $state['next_cn'] = $id + 1;
    $total = 0.0;
    foreach ((array)($body['items'] ?? []) as $it) $total += (float)($it['price'] ?? 0) * (float)($it['quantity'] ?? 1);
    $cn = ['id' => $id, 'number' => 'CN-' . $id, 'clientId' => (int)($body['clientId'] ?? 0), 'total' => $total, 'note' => (string)($body['note'] ?? '')];
    $state['credit_notes'][(string)$id] = $cn;
    fu_out($cn, 201);
}
if ($method === 'GET' && preg_match('#^/(?:billing/)?credit-notes/(\d+)$#', $path, $m)) {
    $cn = $state['credit_notes'][$m[1]] ?? null;
    $cn === null ? fu_out(['code' => 404, 'message' => 'Credit note not found.'], 404) : fu_out($cn);
}
fu_out(['code' => 404, 'message' => 'FAKE-UCRM-STAFF-JOBS: not simulated: ' . $method . ' ' . $path], 404);
