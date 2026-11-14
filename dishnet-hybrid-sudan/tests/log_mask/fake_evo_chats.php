<?php
declare(strict_types=1);
/**
 * ████ FAKE EVOLUTION (CHAT HISTORY) — TEST ONLY ████  5.18.105 (root docs/74 §H.1.12, §B.2 item 5)
 *
 * The two chat endpoints cron_evo_sync.php reads, and the connection state it asks first — nothing else. For LN-24 in
 * tests/test_log_mask.php (section A). The shared tests/fixtures/fake_evo_server.php is left alone (5.18.104 declared it
 * changed): this one is new and lives under tests/log_mask/.
 *
 *   GET  /instance/connectionState/{instance}   {"instance":{"instanceName":…, "state":"open"}}
 *   POST /chat/findChats/{instance}             the seeded chat list, as Evolution v2 answers it
 *   POST /chat/findMessages/{instance}          the seeded answer for the body's where.key.remoteJid; an empty page when
 *                                               none was seeded. Every call is recorded (instance, remoteJid, page).
 *   POST /__test/seed                           {"chats":[…], "messages":{"<jid>":<answer>}} — replaces both
 *   GET  /__test/state                          the marker, the seed and the calls
 *
 * State lives in a temp file per port. Every number and name is fictitious (+2567000001xx, "Test …").
 *
 *     php -S 127.0.0.1:PORT tests/log_mask/fake_evo_chats.php
 */
$stateFile = sys_get_temp_dir() . '/lm_fake_evo_chats_' . ($_SERVER['SERVER_PORT'] ?? '0') . '.json';
$state = is_file($stateFile) ? (json_decode((string)file_get_contents($stateFile), true) ?: []) : [];
$state += ['chats' => [], 'messages' => [], 'calls' => []];

function lmfc_out($data, int $http = 200): void
{
    http_response_code($http);
    header('Content-Type: application/json');
    file_put_contents($GLOBALS['stateFile'], json_encode($GLOBALS['state']));
    echo json_encode($data);
    exit;
}

$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path   = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';
$body   = json_decode((string)file_get_contents('php://input'), true);
$body   = is_array($body) ? $body : [];

if ($path === '/__test/state') lmfc_out(['marker' => 'LM-FAKE-EVO-CHATS'] + $state);
if ($path === '/__test/seed' && $method === 'POST') {
    $state = ['chats' => array_values((array)($body['chats'] ?? [])), 'messages' => (array)($body['messages'] ?? []), 'calls' => []];
    lmfc_out(['seeded' => true, 'marker' => 'LM-FAKE-EVO-CHATS']);
}
if (preg_match('#^/instance/connectionState/(.+)$#', $path, $m)) {
    lmfc_out(['instance' => ['instanceName' => rawurldecode($m[1]), 'state' => 'open']]);
}
if ($method === 'POST' && preg_match('#^/chat/findChats/(.+)$#', $path)) {
    lmfc_out($state['chats']);
}
if ($method === 'POST' && preg_match('#^/chat/findMessages/(.+)$#', $path, $m)) {
    $jid = (string)($body['where']['key']['remoteJid'] ?? '');
    $state['calls'][] = ['instance' => rawurldecode($m[1]), 'remoteJid' => $jid, 'page' => (int)($body['page'] ?? 0)];
    if (array_key_exists($jid, $state['messages'])) lmfc_out($state['messages'][$jid]);
    lmfc_out(['messages' => ['records' => [], 'pages' => 1]]);
}
lmfc_out(['error' => 'LM-FAKE-EVO-CHATS: path not simulated: ' . $method . ' ' . $path], 404);
