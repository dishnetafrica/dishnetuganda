<?php
// Fake AI provider (OpenAI chat-completions shape) for the rehearsal. Answers by the customer's last message, and
// records every system prompt it is sent to $LOG_DIR/prompt-<n>.txt so the harness can see what the assistant was given.
$log  = getenv('FAKE_AI_LOG') ?: sys_get_temp_dir() . '/fake_ai';
@mkdir($log, 0777, true);
$req  = json_decode((string)file_get_contents('php://input'), true) ?: [];
$msgs = (array)($req['messages'] ?? []);
$sys  = ''; $last = '';
foreach ($msgs as $m) {
    if (($m['role'] ?? '') === 'system') $sys = (string)$m['content'];
    if (($m['role'] ?? '') === 'user')   $last = (string)$m['content'];
}
$n = count(glob($log . '/prompt-*.txt') ?: []) + 1;
file_put_contents(sprintf('%s/prompt-%02d.txt', $log, $n), "LAST: {$last}\n\n{$sys}");
if (stripos($last, 'unlimited business plans') !== false) {
    $reply = 'Yes — Business 500GB is our business plan with a data block for busy sites.';          // names Business only → note
} elseif (stripos($last, 'two outdoor access points') !== false) {
    $reply = 'Two Outdoor Access Points at UGX 450,000 each come to UGX 900,000, plus the MikroTik Router at UGX 380,000 — UGX 1,280,000.';  // a multiplied total → refused
} elseif (stripos($last, 'Business 500') !== false) {
    $reply = 'Business 500GB is UGX 285,000 a month. The 500 GB is priority data; after it the line drops to about 1 Mbps. For a busy site the Residential (up to 400 Mbps) plan at UGX 329,000 is the better fit.';
} else {
    $reply = 'For that I would recommend Residential (up to 400 Mbps) at UGX 329,000 a month — unlimited standard data.';
}
header('Content-Type: application/json');
echo json_encode(['choices' => [['message' => ['role' => 'assistant', 'content' => $reply]]],
                  'usage' => ['prompt_tokens' => strlen($sys) >> 2, 'completion_tokens' => strlen($reply) >> 2]]);
