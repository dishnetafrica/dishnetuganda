<?php
// A stand-in for the public edge, for the C-1 rehearsal (php -S router). It behaves like the measured
// host: the bare /crm answers 301 → :8443/crm/ UNTIL the Traefik file exists in CONF_DIR, then 302 →
// /crm/ on the public host (what Traefik does once it has taken the file). /crm/ answers 302 to UISP's
// sign-in, the portal sign-in 200, uCRM's login 200 — before and after.
$conf = getenv('FAKE_CONF_DIR') ?: sys_get_temp_dir();
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$taken = is_file($conf . '/dnb-crm-root.yml');
if ($path === '/crm') {
    if ($taken) { header('Location: https://' . $host . '/crm/', true, 302); exit; }
    header('Location: https://' . preg_replace('/:\d+$/', '', $host) . ':8443/crm/', true, 301); exit;
}
if ($path === '/crm/') { header('Location: /nms/login?returnurl=/crm/', true, 302); exit; }
if ($path === '/crm/login') { echo 'ucrm login'; exit; }
if ($path === '/crm/_plugins/dishnet-hybrid-sudan/public.php') { echo 'sign-in page'; exit; }
http_response_code(404); echo 'nope';
