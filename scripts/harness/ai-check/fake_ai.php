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
    $reply = 'Two Outdoor Access Points at UGX 450,000 each come to UGX 900,000, plus the MikroTik Router at UGX 380,000 — UGX 1,280,000.';  // a multiplied total: refused by 5.18.43, a permitted total since 5.18.44
} elseif (stripos($last, 'How much is an outdoor access point and a MikroTik') !== false) {
    $reply = 'The outdoor access point is UGX 1,234,000 and the MikroTik Router is UGX 380,000.';    // an invented price → refused
} elseif (stripos($last, 'upper floors') !== false) {
    $reply = "For the two upper floors: 2 × Router Mini — UGX 435,000 each = UGX 870,000\nTOTAL: UGX 870,000\nThe site survey confirms how many routers are needed and where they go.";   // two of one Starlink router: refused by 5.18.44, a permitted total since 5.18.45
} elseif (stripos($last, 'installed at my home') !== false) {
    $reply = "Starlink Standard Kit — UGX 2,649,000\nProfessional Installation — UGX 150,000\nTOTAL TO GET CONNECTED: UGX 2,799,000\nThen Residential (up to 400 Mbps) at UGX 329,000 a month.";   // a total the price check allows
} elseif (stripos($last, 'other building') !== false) {
    $reply = "To reach your other building:\n- MikroTik Router — price 380000 UGX\n- Outdoor Access Point — price 450000 UGX\nTOTAL: 881500 UGX\nThe site survey confirms the rest.";   // a wrong total, written without commas as the live model wrote B1 and B2 on 27 Sep: read and refused since 5.18.46
} elseif (stripos($last, '50 people') !== false) {
    $reply = "Here is your setup:\n- Starlink Standard Kit — UGX 2,649,000\n- Outdoor Access Point — UGX 450,000\nTOTAL FOR SETUP: [Sum of setup costs]\nThen Residential (up to 400 Mbps) at UGX 329,000 a month.";   // an unfilled slot, as the live model wrote A1 on 27 Sep: refused since 5.18.46
} elseif (stripos($last, 'trading centre') !== false) {
    $reply = "For a WiFi business, here is a starting setup:\n\n- Starlink Standard Kit — UGX 2,649,000\n- MikroTik Router — UGX 380,000\n- Outdoor Access Point — UGX 450,000\n\nTotal: UGX 3,629,000\n\nThen Residential (up to 400 Mbps) at UGX 329,000 a month.";   // a total 150,000 over its lines — the installation, never listed — as the live model wrote A1 at 09:00 on 27 Sep: 3,629,000 is itself a sum of our prices, so only 5.18.47's totals rule refuses it
} elseif (stripos($last, 'Business 500') !== false) {
    $reply = 'Business 500GB is UGX 285,000 a month. The 500 GB is priority data; after it the line drops to about 1 Mbps. For a busy site the Residential (up to 400 Mbps) plan at UGX 329,000 is the better fit.';
} else {
    $reply = 'For that I would recommend Residential (up to 400 Mbps) at UGX 329,000 a month — unlimited standard data.';
}
header('Content-Type: application/json');
echo json_encode(['choices' => [['message' => ['role' => 'assistant', 'content' => $reply]]],
                  'usage' => ['prompt_tokens' => strlen($sys) >> 2, 'completion_tokens' => strlen($reply) >> 2]]);
