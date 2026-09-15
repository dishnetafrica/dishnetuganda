<?php
declare(strict_types=1);
/**
 * ████ FAKE uCRM (WEBHOOK ENDPOINTS) — TEST ONLY ████
 *
 * Serves /api/v2.1/webhooks/endpoints — GET list, POST create, PATCH, DELETE —
 * with the fields uCRM exposes on an endpoint: id, url, isActive, anyEvent,
 * eventTypes, verifySslCertificate. Also serves the plugin's own webhook route
 * and answers it exactly as webhook.php does: an empty POST gets 400 "Empty
 * body.", a GET gets 405 "POST required.". Anything under /dead/ answers uCRM's
 * 404 page, so a test can hand the registrar an address that does not reach.
 *
 * Every request is recorded, so a test can prove that a run which had nothing
 * to repair wrote nothing.
 *
 * Scenarios: default | no_anyevent (a PATCH carrying anyEvent is refused 422,
 * as an older uCRM without that field would refuse it).
 *
 *     php -S 127.0.0.1:PORT tests/fixtures/fake_ucrm_webhooks.php
 */
$stateFile = sys_get_temp_dir() . '/fake_ucrm_webhooks_' . ($_SERVER['SERVER_PORT'] ?? '0') . '.json';
$state = is_file($stateFile) ? (json_decode((string)file_get_contents($stateFile), true) ?: []) : [];
$state += ['endpoints' => [], 'seq' => 0, 'scenario' => 'default', 'requests' => []];

function fw_save(): void { file_put_contents($GLOBALS['stateFile'], json_encode($GLOBALS['state'])); }
function fw_out($data, int $http = 200): void
{
    http_response_code($http);
    header('Content-Type: application/json');
    fw_save();
    echo json_encode($data);
    exit;
}
function fw_html(int $http, string $body): void
{
    http_response_code($http);
    header('Content-Type: text/html');
    fw_save();
    echo $body;
    exit;
}
function fw_endpoint(int $id, array $e): array
{
    $events = array_values(array_map('strval', (array)($e['eventTypes'] ?? [])));
    return [
        'id'                   => $id,
        'url'                  => (string)($e['url'] ?? ''),
        'isActive'             => (bool)($e['isActive'] ?? true),
        'anyEvent'             => array_key_exists('anyEvent', $e) ? (bool)$e['anyEvent'] : ($events === []),
        'eventTypes'           => $events,
        'verifySslCertificate' => (bool)($e['verifySslCertificate'] ?? true),
    ];
}

$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$uri    = (string)($_SERVER['REQUEST_URI'] ?? '');
$path   = parse_url($uri, PHP_URL_PATH) ?: '';
$q      = [];
parse_str((string)parse_url($uri, PHP_URL_QUERY), $q);
$raw    = (string)file_get_contents('php://input');
$json   = json_decode($raw, true);

if ($path === '/__test/state')    fw_out(['marker' => 'FAKE-UCRM-WEBHOOKS', 'endpoints' => $state['endpoints'], 'scenario' => $state['scenario']]);
if ($path === '/__test/reset')    { $state = ['endpoints' => [], 'seq' => 0, 'scenario' => 'default', 'requests' => []]; fw_out(['reset' => true]); }
if ($path === '/__test/requests') fw_out(['requests' => $state['requests'], 'count' => count($state['requests'])]);
if ($path === '/__test/seed') {
    $state['endpoints'] = []; $state['seq'] = 0; $state['requests'] = [];
    foreach ((array)($json['endpoints'] ?? []) as $e) {
        $state['seq']++;
        $state['endpoints'][] = fw_endpoint($state['seq'], (array)$e);
    }
    if (isset($json['scenario'])) $state['scenario'] = (string)$json['scenario'];
    fw_out(['seeded' => count($state['endpoints']), 'scenario' => $state['scenario']]);
}

// Everything below is what the code under test does, and is recorded verbatim.
$state['requests'][] = ['method' => $method, 'path' => $path, 'query' => $q, 'body' => is_array($json) ? $json : $raw];

// ── the plugin's own webhook route, answered as webhook.php answers ──────────
if (preg_match('#^/(dead/)?(?:crm/)?_plugins/[^/]+/public\.php$#', $path, $m)) {
    if (($m[1] ?? '') === 'dead/') fw_html(404, '<html><body><h1>404 Not Found</h1>uCRM</body></html>');
    if (!in_array((string)($q['page'] ?? ''), ['crm_webhook', 'webhook'], true)) fw_html(200, '<html><body>plugin page</body></html>');
    if ($method !== 'POST') fw_out(['status' => 'error', 'message' => 'POST required.'], 405);
    if ($raw === '')        fw_out(['status' => 'error', 'message' => 'Empty body.'], 400);
    fw_out(['status' => 'ok', 'message' => 'received']);
}

// ── uCRM API: webhooks/endpoints ────────────────────────────────────────────
if (preg_match('#^/api/v[0-9.]+/webhooks/endpoints(?:/(\d+))?$#', $path, $m)) {
    $id = isset($m[1]) ? (int)$m[1] : 0;
    if ($id === 0) {
        if ($method === 'GET') fw_out(array_values($state['endpoints']));
        if ($method === 'POST') {
            if (!is_array($json) || empty($json['url'])) fw_out(['code' => 422, 'message' => 'url: This value should not be blank.'], 422);
            $state['seq']++;
            $e = fw_endpoint($state['seq'], $json);
            $state['endpoints'][] = $e;
            fw_out($e, 201);
        }
        fw_out(['code' => 405, 'message' => 'Method Not Allowed'], 405);
    }
    foreach ($state['endpoints'] as $i => $e) {
        if ((int)$e['id'] !== $id) continue;
        if ($method === 'GET')    fw_out($e);
        if ($method === 'DELETE') { array_splice($state['endpoints'], $i, 1); fw_out(['deleted' => $id]); }
        if ($method === 'PATCH') {
            if (!is_array($json)) fw_out(['code' => 400, 'message' => 'Invalid JSON'], 400);
            if ($state['scenario'] === 'no_anyevent' && array_key_exists('anyEvent', $json)) {
                fw_out(['code' => 422, 'message' => 'This form should not contain extra fields: anyEvent'], 422);
            }
            if (array_key_exists('url', $json))                  $e['url'] = (string)$json['url'];
            if (array_key_exists('isActive', $json))             $e['isActive'] = (bool)$json['isActive'];
            if (array_key_exists('verifySslCertificate', $json)) $e['verifySslCertificate'] = (bool)$json['verifySslCertificate'];
            if (array_key_exists('eventTypes', $json)) {
                $e['eventTypes'] = array_values(array_map('strval', (array)$json['eventTypes']));
                $e['anyEvent']   = $e['eventTypes'] === [];
            }
            if (array_key_exists('anyEvent', $json)) {
                $e['anyEvent'] = (bool)$json['anyEvent'];
                if ($e['anyEvent']) $e['eventTypes'] = [];
            }
            $state['endpoints'][$i] = $e;
            fw_out($e);
        }
    }
    fw_out(['code' => 404, 'message' => 'Not Found'], 404);
}

fw_html(404, '<html><body><h1>404 Not Found</h1>uCRM</body></html>');
