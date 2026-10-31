<?php
/**
 * TEST ONLY — the router section H (tests/quote_from_ucrm/H_kyc_form.php) serves its KYC form pages through, under
 * `php -S 127.0.0.1:<port> h_router.php`, for h_kyc_form_browser.js. The pages are the ones section H rendered from the
 * judged tree into the run's own temporary folder (QFU_H_PAGES), one per ?mode=. A POST is echoed back as JSON, so the
 * browser check can read what the form posted; ?page=api answers {} (the form's own lookups). Nothing else is served.
 */
$dir  = rtrim((string)getenv('QFU_H_PAGES'), '/');
$mode = preg_replace('/[^a-z_]/', '', (string)($_GET['mode'] ?? ''));
if (($_GET['page'] ?? '') === 'api') { header('Content-Type: application/json'); echo '{}'; return true; }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    header('Content-Type: application/json');
    echo json_encode(['posted' => $_POST]);
    return true;
}
$f = $dir . '/' . $mode . '.html';
if ($dir === '' || $mode === '' || !is_file($f)) { http_response_code(404); echo 'no such page'; return true; }
echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>kyc</title></head><body>'
    . file_get_contents($f) . '</body></html>';
return true;
