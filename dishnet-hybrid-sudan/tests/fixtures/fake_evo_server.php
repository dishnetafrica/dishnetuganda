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
$state += ['webhooks' => [], 'set_calls' => 0, 'media_calls' => [], 'text_calls' => [], 'fail_next' => 0, 'fail_status' => 500, 'hold_dir' => '', 'media_fetch_calls' => [], 'media_next' => null, 'media_next_by_id' => [], 'instances' => null, 'connect_calls' => []];

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
    $state = ['webhooks' => [], 'set_calls' => 0, 'media_calls' => [], 'text_calls' => [], 'fail_next' => 0, 'fail_status' => 500, 'hold_dir' => '', 'media_fetch_calls' => [], 'media_next' => null, 'media_next_by_id' => [], 'instances' => null, 'connect_calls' => []];
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
    // Optional HTTP status for the forced failure (default 500). A gateway 502/504
    // lets a test exercise the "may have been sent" (uncertain) outcome; callers
    // that pass no code keep the original 500 behaviour exactly.
    $state['fail_status'] = (int)($_GET['code'] ?? 500) ?: 500;
    fe2_out(['fail_next' => $state['fail_next'], 'fail_status' => $state['fail_status'], 'marker' => 'FAKE-EVO-TEST']);
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
    if (($state['fail_next'] ?? 0) > 0) { $state['fail_next']--; fe2_out(['error' => 'FAKE-EVO-FAILURE (test control)'], (int)($state['fail_status'] ?? 500) ?: 500); }
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
// Batch 1 of the AI communication layer (docs/55 §9): what the next media fetch answers. Set once, it stands until
// changed or reset. POST JSON (or GET params): base64 | bytes (a deterministic payload of that many bytes is built here,
// so a test never ships a large body), mimetype, fileName, hold (seconds to wait before answering — a slow Evolution),
// fail (an HTTP status to answer with instead), raw (a non-JSON body to answer with instead).
if ($path === '/__test/media') {
    $in = $body ?: $_GET;
    if (isset($in['bytes']) && (int)$in['bytes'] > 0) {
        $n = (int)$in['bytes'];
        $in['base64'] = base64_encode(substr(str_repeat('DN-MEDIA-TEST-PAYLOAD/', (int)ceil($n / 22)), 0, $n));
    }
    $next = [
        'base64'   => (string)($in['base64'] ?? ''),
        'mimetype' => (string)($in['mimetype'] ?? 'audio/ogg; codecs=opus'),
        'fileName' => (string)($in['fileName'] ?? ''),
        'hold'     => (int)($in['hold'] ?? 0),
        'fail'     => (int)($in['fail'] ?? 0),
        'raw'      => (string)($in['raw'] ?? ''),
    ];
    // for_id: the answer for ONE message id (several media in one worker run, each with its own type); else the default.
    if ((string)($in['for_id'] ?? '') !== '') $state['media_next_by_id'][(string)$in['for_id']] = $next;
    else                                       $state['media_next'] = $next;
    fe2_out(['media_next' => array_merge($next, ['base64' => strlen($next['base64']) . ' chars']), 'for_id' => (string)($in['for_id'] ?? ''), 'marker' => 'FAKE-EVO-TEST']);
}
if (preg_match('#^/chat/getBase64FromMediaMessage/(.+)$#', $path, $m)) {
    // Recorded first — a test asserts how many fetches happened and with what key, whatever the answer.
    $k = isset($body['message']['key']) && is_array($body['message']['key']) ? $body['message']['key'] : [];
    $state['media_fetch_calls'][] = [
        'instance'     => $m[1],
        'id'           => (string)($k['id'] ?? ''),
        'remoteJid'    => (string)($k['remoteJid'] ?? ''),
        'fromMe'       => !empty($k['fromMe']),
        'convertToMp4' => $body['convertToMp4'] ?? null,
    ];
    $mid  = (string)($k['id'] ?? '');
    $next = is_array($state['media_next_by_id'][$mid] ?? null) ? $state['media_next_by_id'][$mid]
          : (is_array($state['media_next'] ?? null) ? $state['media_next'] : []);
    if (($next['hold'] ?? 0) > 0) {
        file_put_contents($stateFile, json_encode($state));   // the call is on record before the wait
        sleep((int)$next['hold']);
    }
    if (($next['fail'] ?? 0) > 0) fe2_out(['status' => (int)$next['fail'], 'error' => 'FAKE-EVO-MEDIA-FAILURE (test control)'], (int)$next['fail']);
    if (($next['raw'] ?? '') !== '') {
        http_response_code(200);
        header('Content-Type: application/json');
        echo $next['raw'];
        file_put_contents($stateFile, json_encode($state));
        exit;
    }
    $b64 = (string)($next['base64'] ?? '');
    if ($b64 === '') fe2_out(['status' => 404, 'error' => 'FAKE-EVO-TEST: no media set for this fetch (/__test/media)'], 404);
    fe2_out([
        'mediaType' => explode('/', (string)$next['mimetype'])[0],
        'fileName'  => (string)$next['fileName'],
        'size'      => ['fileLength' => (string)strlen((string)base64_decode($b64, true))],
        'mimetype'  => (string)$next['mimetype'],
        'base64'    => $b64,
        'buffer'    => null,
    ]);
}
fe2_out(['error' => 'FAKE-EVO-TEST: path not simulated: ' . $path], 404);
