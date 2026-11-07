<?php
declare(strict_types=1);
/**
 * ████ FAKE OPENAI — TEST ONLY ████ (5.18.104, root docs/73 §H.3.9, §H.3.24)
 *
 * Just enough of POST /v1/audio/transcriptions for lib/OpenAiTranscriber.php, on loopback only:
 *
 *     php -S 127.0.0.1:<port> tests/fixtures/fake_openai_server.php
 *
 * Every call is recorded first — the bearer as `present` / `empty` / `missing` with a sha256 prefix (never the key
 * itself), the form fields and the file's name, type, size and sha256 prefix — and answered by the next scripted
 * answer, else 200 {"text": FAKE_OPENAI_TEXT}. Test controls:
 *
 *   GET  /__test/state   the record (marker FAKE-OPENAI-TEST)
 *   GET  /__test/reset
 *   POST /__test/next    a JSON list of answers, each {status, body (a JSON value), raw (a string body), hold (seconds),
 *                        echo_key_tail (a 401 that quotes the bearer's last 8 characters, as OpenAI's own text may)}
 *   POST /__test/default one answer, given to every call once the scripted ones are used
 *
 * Fictitious data only; it never forwards anything anywhere.
 */

$stateFile = sys_get_temp_dir() . '/fake_openai_state_' . md5(__FILE__ . ($_SERVER['SERVER_PORT'] ?? '')) . '.json';
$state = is_file($stateFile) ? (json_decode((string)file_get_contents($stateFile), true) ?: []) : [];
$state += ['calls' => [], 'next' => [], 'default' => null];

function fo_out($data, int $http = 200, bool $save = true): void
{
    if ($save) file_put_contents($GLOBALS['stateFile'], json_encode($GLOBALS['state']));
    http_response_code($http);
    header('Content-Type: application/json');
    echo is_string($data) ? $data : json_encode($data);
    exit;
}

$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';

if ($path === '/__test/state') fo_out($state + ['marker' => 'FAKE-OPENAI-TEST'], 200, false);
if ($path === '/__test/reset') { $state = ['calls' => [], 'next' => [], 'default' => null]; fo_out(['reset' => true, 'marker' => 'FAKE-OPENAI-TEST']); }
if ($path === '/__test/next') {
    $in = json_decode((string)file_get_contents('php://input'), true);
    $state['next'] = array_merge((array)$state['next'], is_array($in) ? array_values($in) : []);
    fo_out(['next' => count($state['next']), 'marker' => 'FAKE-OPENAI-TEST']);
}
if ($path === '/__test/default') {
    $in = json_decode((string)file_get_contents('php://input'), true);
    $state['default'] = is_array($in) ? $in : null;
    fo_out(['default' => $state['default'] !== null, 'marker' => 'FAKE-OPENAI-TEST']);
}
if ($path === '/redirected') {
    $state['calls'][] = ['path' => '/redirected'];
    fo_out(['text' => 'FOLLOWED A REDIRECT']);
}

if ($path === '/v1/audio/transcriptions' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    $bearer = stripos($auth, 'Bearer ') === 0 ? substr($auth, 7) : null;
    $f = $_FILES['file'] ?? null;
    $bytes = is_array($f) && is_file((string)($f['tmp_name'] ?? '')) ? (string)file_get_contents((string)$f['tmp_name']) : '';
    $state['calls'][] = [
        'path'         => $path,
        'auth'         => $bearer === null ? 'missing' : (trim($bearer) === '' ? 'empty' : 'present'),
        'auth_sha'     => $bearer === null ? '' : substr(hash('sha256', $bearer), 0, 12),
        'content_type' => strtok((string)($_SERVER['CONTENT_TYPE'] ?? ''), ';'),
        'fields'       => array_keys($_POST),
        'model'        => (string)($_POST['model'] ?? ''),
        'file_name'    => is_array($f) ? (string)($f['name'] ?? '') : '',
        'file_type'    => is_array($f) ? (string)($f['type'] ?? '') : '',
        'file_size'    => strlen($bytes),
        'file_sha'     => $bytes === '' ? '' : substr(hash('sha256', $bytes), 0, 12),
    ];
    $a = $state['next'] !== [] ? array_shift($state['next']) : ($state['default'] ?? ['status' => 200, 'body' => ['text' => 'FAKE_OPENAI_TEXT']]);
    file_put_contents($stateFile, json_encode($state));   // on record before any wait
    if ((int)($a['hold'] ?? 0) > 0) sleep((int)$a['hold']);
    $status = (int)($a['status'] ?? 200);
    if (!empty($a['redirect'])) {
        http_response_code(302);
        header('Location: /redirected');
        exit;
    }
    if (!empty($a['echo_key_tail'])) {
        fo_out(['error' => ['message' => 'Incorrect API key provided: sk-...' . substr((string)$bearer, -8) . '.', 'type' => 'invalid_request_error',
                            'code' => 'invalid_api_key']], 401, false);
    }
    if (array_key_exists('raw', $a)) fo_out((string)$a['raw'], $status, false);
    fo_out($a['body'] ?? new stdClass(), $status, false);
}

fo_out(['error' => 'FAKE-OPENAI-TEST: path not simulated: ' . $path], 404, false);
