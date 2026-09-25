<?php
declare(strict_types=1);
/**
 * A FAKE SMS provider speaking the Africa's Talking request shape. IT IS NOT
 * THE PROVIDER.
 *
 *   PROVES   our adapter's HTTP handling — method, path, headers, form body,
 *            status codes, answer parsing, timeouts — and the worker built on
 *            it, end to end, with a real socket.
 *   PROVES   nothing about whether the real provider accepts these requests or
 *            answers as documented. That is verified only by a real message
 *            from the operator's own account (docs/127 S-6).
 *
 * Driven by files in FAKE_SMS_DIR, so a test can change the answer between
 * requests without restarting the server:
 *   scenario.json   {"status": 201, "body": "...", "sleep": 0}; absent → a
 *                   201 accepting the message, as the provider documents it
 *   requests.jsonl  one line per request received: method, path, the apikey,
 *                   Accept and Content-Type headers, and the form fields
 */
$dir      = getenv('FAKE_SMS_DIR') ?: sys_get_temp_dir();
$scenario = json_decode((string) @file_get_contents($dir . '/scenario.json'), true) ?: [];

$fields = [];
parse_str((string) file_get_contents('php://input'), $fields);
file_put_contents($dir . '/requests.jsonl', json_encode([
    'method'       => $_SERVER['REQUEST_METHOD'] ?? '',
    'path'         => parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH),
    'apikey'       => $_SERVER['HTTP_APIKEY'] ?? null,
    'accept'       => $_SERVER['HTTP_ACCEPT'] ?? null,
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? ($_SERVER['HTTP_CONTENT_TYPE'] ?? null),
    'fields'       => $fields,
]) . "\n", FILE_APPEND | LOCK_EX);

if (($s = (int) ($scenario['sleep'] ?? 0)) > 0) { sleep($s); }

http_response_code((int) ($scenario['status'] ?? 201));
header('Content-Type: application/json');
echo array_key_exists('body', $scenario) ? (string) $scenario['body'] : json_encode(['SMSMessageData' => [
    'Message'    => 'Sent to 1/1 Total Cost: UGX 0.0000',
    'Recipients' => [[
        'statusCode' => 101, 'number' => (string) ($fields['to'] ?? ''), 'status' => 'Success',
        'cost' => 'UGX 0.0000', 'messageId' => 'ATXid_fake_' . bin2hex(random_bytes(4)),
    ]],
]]);
