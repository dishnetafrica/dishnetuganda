<?php
declare(strict_types=1);
/**
 * ████ FAKE WHATSML FEED — TEST ONLY ████  5.18.105 (root docs/74 §H.1.13, §B.2 item 5)
 *
 * The one endpoint cron_wa_sync.php polls (wa_feed_url): message_feed.php?secret=…&since=…&limit=…, answered as the
 * WhatsML feed answers it — {"ok":true,"messages":[rows newer than since]}. For LN-25 in tests/test_log_mask.php
 * (section A). New, under tests/log_mask/.
 *
 *   GET  /message_feed.php      the seeded rows whose pkId is above `since`, at most `limit`; a wrong secret is 403
 *                               {"ok":false}. Every poll is recorded (since, limit, whether the secret matched).
 *   POST /__test/seed           {"secret":"…","rows":[…]} — replaces both
 *   GET  /__test/state          the marker, the seed and the polls
 *
 * A row carries pkId, sessionId, remoteJid, id, message (the Baileys message, a JSON string), fromMe, pushName and
 * messageTimestamp, as the feed stores them. State lives in a temp file per port. Every number and name is fictitious.
 *
 *     php -S 127.0.0.1:PORT tests/log_mask/fake_whatsml_feed.php
 */
$stateFile = sys_get_temp_dir() . '/lm_fake_whatsml_feed_' . ($_SERVER['SERVER_PORT'] ?? '0') . '.json';
$state = is_file($stateFile) ? (json_decode((string)file_get_contents($stateFile), true) ?: []) : [];
$state += ['secret' => '', 'rows' => [], 'polls' => []];

function lmwf_out($data, int $http = 200): void
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

if ($path === '/__test/state') lmwf_out(['marker' => 'LM-FAKE-WHATSML-FEED'] + $state);
if ($path === '/__test/seed' && $method === 'POST') {
    $state = ['secret' => (string)($body['secret'] ?? ''), 'rows' => array_values((array)($body['rows'] ?? [])), 'polls' => []];
    lmwf_out(['seeded' => true, 'marker' => 'LM-FAKE-WHATSML-FEED']);
}
if ($path === '/message_feed.php') {
    $since = (int)($_GET['since'] ?? 0);
    $limit = max(1, (int)($_GET['limit'] ?? 200));
    $ok    = hash_equals($state['secret'], (string)($_GET['secret'] ?? ''));
    $state['polls'][] = ['since' => $since, 'limit' => $limit, 'secret_ok' => $ok];
    if (!$ok) lmwf_out(['ok' => false, 'error' => 'LM-FAKE-WHATSML-FEED: wrong secret (test control)'], 403);
    $rows = array_values(array_filter($state['rows'], function ($r) use ($since) { return (int)($r['pkId'] ?? 0) > $since; }));
    lmwf_out(['ok' => true, 'messages' => array_slice($rows, 0, $limit)]);
}
lmwf_out(['ok' => false, 'error' => 'LM-FAKE-WHATSML-FEED: path not simulated: ' . $method . ' ' . $path], 404);
