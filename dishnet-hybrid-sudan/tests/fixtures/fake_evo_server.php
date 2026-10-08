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
$state += ['webhooks' => [], 'set_calls' => 0, 'media_calls' => [], 'text_calls' => [], 'fail_next' => 0, 'hold_dir' => '', 'instances' => null, 'connect_calls' => [], 'fail_instances' => [], 'failed_calls' => [], 'presence_calls' => []];

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
// Phase 2 test controls: start from nothing, and make the next N text sends fail.
if ($path === '/__test/reset') {
    $state = ['webhooks' => [], 'set_calls' => 0, 'media_calls' => [], 'text_calls' => [], 'fail_next' => 0, 'hold_dir' => '', 'instances' => null, 'connect_calls' => [], 'fail_instances' => [], 'failed_calls' => [], 'presence_calls' => []];
    fe2_out(['reset' => true, 'marker' => 'FAKE-EVO-TEST']);
}
// 5.18.53: hold the next text send until the test releases it — a WhatsApp as slow to answer as the test needs, so that
// another request can run meanwhile. The test names a directory of its own: the held send writes "held" there, then
// waits for "release" (60 s at most) before it is recorded and answered.
if ($path === '/__test/hold') {
    $state['hold_dir'] = (string)($_GET['dir'] ?? '');
    fe2_out(['hold' => $state['hold_dir'], 'marker' => 'FAKE-EVO-TEST']);
}
if ($path === '/__test/fail_next') {
    $state['fail_next'] = max(0, (int)($_GET['n'] ?? 1));
    fe2_out(['fail_next' => $state['fail_next'], 'marker' => 'FAKE-EVO-TEST']);
}
// The salesperson pilot (docs/65 §AC): one instance that cannot send — a phone switched off, a number logged out — while
// every other instance still can. ?name=X[&code=N] makes every text and media send on X fail with HTTP N (default 500)
// until ?name=X&off=1, or ?clear=1, lifts it. A refused send is recorded in failed_calls (instance, kind, number), never
// in text_calls or media_calls, so a test can tell "tried on the right number and refused" from "sent somewhere else".
if ($path === '/__test/fail_instance') {
    if (!empty($_GET['clear'])) {
        $state['fail_instances'] = [];
    } else {
        $name = (string)($_GET['name'] ?? '');
        if ($name !== '') {
            if (!empty($_GET['off'])) unset($state['fail_instances'][$name]);
            else                      $state['fail_instances'][$name] = (int)($_GET['code'] ?? 500) ?: 500;
        }
    }
    fe2_out(['fail_instances' => $state['fail_instances'], 'marker' => 'FAKE-EVO-TEST']);
}
// 5.18.89 (salesperson numbers): the instance list a test needs — POST a JSON list of rows as fetchInstances answers them
// (name, connectionStatus, ownerJid, profileName); an empty list is "no instances". ?default=1 goes back to the one
// default instance below.
if ($path === '/__test/instances') {
    $state['instances'] = !empty($_GET['default']) ? null : array_values($body);
    fe2_out(['instances' => $state['instances'], 'marker' => 'FAKE-EVO-TEST']);
}
if ($path === '/instance/fetchInstances') {
    if (is_array($state['instances'] ?? null)) fe2_out($state['instances']);
    fe2_out([[
        'name' => 'dishnet_ug', 'connectionStatus' => 'open',
        'ownerJid' => '256705993348@s.whatsapp.net', 'profileName' => 'FAKE EVO TEST',
    ]]);
}
// 5.18.89: pairing — a QR (base64 of a fixed test string) and a pairing code, and the call recorded.
if (preg_match('#^/instance/connect/(.+)$#', $path, $m)) {
    $state['connect_calls'][] = rawurldecode($m[1]);
    fe2_out(['base64' => base64_encode('FAKE-QR-' . rawurldecode($m[1])), 'pairingCode' => 'FAKECODE']);
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
if (preg_match('#^/message/sendText/(.+)$#', $path, $m)) {
    if (($state['hold_dir'] ?? '') !== '') {
        $dir = $state['hold_dir'];
        $state['hold_dir'] = '';                                  // this send only
        file_put_contents($stateFile, json_encode($state));
        file_put_contents($dir . '/held', '1');
        for ($i = 0; $i < 600 && !is_file($dir . '/release'); $i++) usleep(100000);
        $state = json_decode((string)file_get_contents($stateFile), true) ?: $state;
    }
    if (isset($state['fail_instances'][$m[1]])) {
        $state['failed_calls'][] = ['instance' => $m[1], 'kind' => 'text', 'number' => (string)($body['number'] ?? '')];
        fe2_out(['error' => 'FAKE-EVO-INSTANCE-DOWN (test control)'], (int)$state['fail_instances'][$m[1]]);
    }
    if (($state['fail_next'] ?? 0) > 0) { $state['fail_next']--; fe2_out(['error' => 'FAKE-EVO-FAILURE (test control)'], 500); }
    // Recorded, not just answered. A test could previously only see that a
    // send returned ok, which is the same thing production logs showed while
    // customers sat in silence — "it returned ok" is not "it said something".
    $state['text_calls'][] = [
        'instance' => $m[1],
        'number'   => (string)($body['number'] ?? ''),
        'text'     => (string)($body['text'] ?? ''),
    ];
    fe2_out(['key' => ['id' => 'FAKE-EVO-MSG-' . count($state['text_calls'])], 'status' => 'PENDING']);
}
if (preg_match('#^/message/sendMedia/(.+)$#', $path, $m)) {
    if (isset($state['fail_instances'][$m[1]])) {
        $state['failed_calls'][] = ['instance' => $m[1], 'kind' => 'media:' . (string)($body['mediatype'] ?? ''), 'number' => (string)($body['number'] ?? '')];
        fe2_out(['error' => 'FAKE-EVO-INSTANCE-DOWN (test control)'], (int)$state['fail_instances'][$m[1]]);
    }
    // Record enough to assert on without persisting a whole base64 image.
    $media = (string)($body['media'] ?? '');
    $state['media_calls'][] = [
        'instance'     => $m[1],
        'number'       => (string)($body['number'] ?? ''),
        'mediatype'    => (string)($body['mediatype'] ?? ''),
        'caption'      => (string)($body['caption'] ?? ''),
        'media_len'    => strlen($media),
        'media_prefix' => substr($media, 0, 48),
    ];
    // Unique like the real thing: the worker now records this id on the row
    // and a constant would make every second media send dedupe to nothing.
    fe2_out(['key' => ['id' => 'FAKE-EVO-MEDIA-' . count($state['media_calls'])], 'status' => 'PENDING']);
}
// 5.18.90 (docs/65 §AD): the typing indicator is recorded — a test can see that none went to one of DishNet's own
// numbers — and answered exactly as before (this fake never simulated it).
if (preg_match('#^/chat/sendPresence/(.+)$#', $path, $m)) {
    $state['presence_calls'][] = ['instance' => $m[1], 'number' => (string)($body['number'] ?? '')];
}
fe2_out(['error' => 'FAKE-EVO-TEST: path not simulated: ' . $path], 404);
