<?php
/**
 * A php -S router for section C of tests/test_quote_from_ucrm.php (5.18.102 D14, docs/71 §R.9.1 "C, Make the
 * quotation") — TEST ONLY. It stands between the plugin's uCRM clients and the fake uCRM (fixtures/fake_ucrm_kyc.php,
 * QFU_C_UPSTREAM) and forwards every request unchanged, so the fake's own log stays the record of what reached uCRM. Four
 * controls, each a file in QFU_C_CTL, change one answer and nothing else:
 *   hold       the FIRST quote POST (…/clients/N/quotes) after the file appears writes 'arrived' and waits, without
 *              forwarding, until the file is removed (40 s at most): the fake uCRM "holding" a POST, for a double press.
 *              Later POSTs pass straight through, so a second quotation is never hidden by the hold itself.
 *   list_down  GET …/billing/quotes (the list) answers 503 and is not forwarded: uCRM's quotations unreadable.
 *   quote_id   the quote POST's answer carries this file's text as its id (uCRM's id is a string it supplies, F-8).
 *   post_lost  the quote POST IS forwarded, so the fake makes the quotation, and its answer is then lost: a 504 with an
 *              HTML body, as a proxy in front of uCRM or a timeout would give (5.18.102 D14 review round 2, FX-flows-2).
 *   hold_client  the first GET …/clients/N (retry_quote's company check) waits, after touching `arrived_client`, until the
 *              file is removed (40 s at most): another request writes meanwhile (5.18.102 D14 review round 4, CK4-F3).
 * Served only on 127.0.0.1; every person, number and document in the requests is fictitious.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$up  = rtrim((string)getenv('QFU_C_UPSTREAM'), '/');
$ctl = rtrim((string)getenv('QFU_C_CTL'), '/');
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$uri    = (string)($_SERVER['REQUEST_URI'] ?? '/');
$path   = (string)parse_url($uri, PHP_URL_PATH);
$body   = (string)file_get_contents('php://input');

if ($path === '/__c/ping') { header('Content-Type: application/json'); echo json_encode(['marker' => 'QFU-C-PROXY', 'token' => (string)getenv('QFU_C_TOKEN')]); return true; }

if ($method === 'GET' && preg_match('#^/api/v2\.1/billing/quotes$#', $path) && is_file($ctl . '/list_down')) {
    http_response_code(503); header('Content-Type: application/json'); echo json_encode(['code' => 503, 'message' => 'Service Unavailable']); return true;
}
if ($method === 'GET' && preg_match('#^/api/v2\.1/clients/\d+$#', $path) && is_file($ctl . '/hold_client') && @fopen($ctl . '/arrived_client', 'x') !== false) {
    for ($i = 0; $i < 400 && is_file($ctl . '/hold_client'); $i++) { usleep(100000); clearstatcache(); }
}
$isQuotePost = $method === 'POST' && preg_match('#^/api/v2\.1/clients/\d+/quotes$#', $path);
if ($isQuotePost && is_file($ctl . '/hold') && @fopen($ctl . '/arrived', 'x') !== false) {
    for ($i = 0; $i < 400 && is_file($ctl . '/hold'); $i++) { usleep(100000); clearstatcache(); }   // the stat cache would keep it
}

$h = [];
foreach (['HTTP_X_AUTH_APP_KEY' => 'X-Auth-App-Key', 'HTTP_X_AUTH_TOKEN' => 'X-Auth-Token', 'CONTENT_TYPE' => 'Content-Type'] as $k => $name) {
    if (isset($_SERVER[$k])) $h[] = $name . ': ' . $_SERVER[$k];
}
$ch = curl_init($up . $uri);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_PROXY => '',
                        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h]);
if ($body !== '') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
$out  = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($out === false) { http_response_code(502); echo json_encode(['code' => 502, 'message' => 'Bad Gateway']); return true; }
if ($isQuotePost && is_file($ctl . '/post_lost')) {   // the quotation made upstream, its answer lost in front of uCRM
    http_response_code(504); header('Content-Type: text/html'); echo '<html><body>504 Gateway Time-out</body></html>'; return true;
}
if ($isQuotePost && is_file($ctl . '/quote_id')) {
    $j = json_decode((string)$out, true);
    if (is_array($j) && isset($j['id'])) { $j['id'] = (string)file_get_contents($ctl . '/quote_id'); $out = json_encode($j); }
}
http_response_code($code);
header('Content-Type: application/json');
echo $out;
return true;
