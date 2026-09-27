<?php
/** FAKE Evolution for the ucrm-users rehearsal: webhook settings only; every request is logged to FAKE_EVO_LOG. */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
if ($path === '/__ping') { echo 'FAKE-EVO-USERS-FACTS'; exit; }
file_put_contents((string)getenv('FAKE_EVO_LOG'), $method . ' ' . $path . "\n", FILE_APPEND);
header('Content-Type: application/json');
if ($method === 'GET' && $path === '/webhook/find/canary-support-inst') {
    echo json_encode(['enabled' => true, 'url' => 'https://canary-host.example/crm/_plugins/dishnet-hybrid-sudan/evo_webhook.php?token=CANARYWEBHOOKTOKEN0123456789abcdef0123',
                      'events' => ['MESSAGES_UPSERT', 'MESSAGES_UPDATE', 'CONNECTION_UPDATE'], 'webhookByEvents' => false, 'webhookBase64' => false]); exit;
}
if ($method === 'GET' && $path === '/webhook/find/canary-account-inst') {
    echo json_encode(['webhook' => ['enabled' => true, 'url' => 'https://canary-elsewhere.example/hook?secret=CANARYOTHERSECRET0123456789abcdef', 'events' => ['MESSAGES_UPSERT']]]); exit;
}
http_response_code(404); echo json_encode(['status' => 404, 'error' => 'Not Found', 'response' => ['message' => ['The canary-sales-inst instance does not exist']]]);
