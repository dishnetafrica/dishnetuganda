<?php
declare(strict_types=1);
/**
 * ████ FAKE EVOLUTION API — TEST ONLY ████
 * Just enough of Evolution v2 for the webhook guard: one instance
 * ('dishnet_ug', connected), webhook find/set with persistent state, and a
 * test control to break the webhook again. Everything is TEST data.
 *
 *     php -S 127.0.0.1:9599 tests/fixtures/fake_evo_server.php
 */

$stateFile = sys_get_temp_dir() . '/fake_evo_state_' . md5(__FILE__ . ($_SERVER['SERVER_PORT'] ?? '')) . '.json';
$state = is_file($stateFile) ? (json_decode((string)file_get_contents($stateFile), true) ?: []) : [];
$state += ['webhooks' => [], 'set_calls' => 0];

function fe2_out($data, int $http = 200): void
{
    http_response_code($http);
    header('Content-Type: application/json');
    echo json_encode($data);
    file_put_contents($GLOBALS['stateFile'], json_encode($GLOBALS['state']));
    exit;
}

$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';
$body = json_decode((string)file_get_contents('php://input'), true) ?: [];

if ($path === '/__test/clear_webhook') {
    $state['webhooks'] = [];
    fe2_out(['cleared' => true, 'marker' => 'FAKE-EVO-TEST']);
}
if ($path === '/__test/state') {
    fe2_out($state + ['marker' => 'FAKE-EVO-TEST']);
}
if ($path === '/instance/fetchInstances') {
    fe2_out([[
        'name' => 'dishnet_ug', 'connectionStatus' => 'open',
        'ownerJid' => '256705993348@s.whatsapp.net', 'profileName' => 'FAKE EVO TEST',
    ]]);
}
if (preg_match('#^/webhook/find/(.+)$#', $path, $m)) {
    fe2_out($state['webhooks'][$m[1]] ?? new stdClass());
}
if (preg_match('#^/webhook/set/(.+)$#', $path, $m)) {
    $wh = isset($body['webhook']) && is_array($body['webhook']) ? $body['webhook'] : $body;
    $state['webhooks'][$m[1]] = ['url' => (string)($wh['url'] ?? ''), 'enabled' => true,
                                 'events' => $wh['events'] ?? []];
    $state['set_calls']++;
    fe2_out(['webhook' => $state['webhooks'][$m[1]]]);
}
if (preg_match('#^/message/sendText/#', $path)) {
    fe2_out(['key' => ['id' => 'FAKE-EVO-MSG'], 'status' => 'PENDING']);
}
fe2_out(['error' => 'FAKE-EVO-TEST: path not simulated: ' . $path], 404);
