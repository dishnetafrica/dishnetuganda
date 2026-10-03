<?php
declare(strict_types=1);
/**
 * partner_api.php — the distributor portal's API entry (WS-A P4d, docs/50,
 * docs/47 §10.3). uCRM serves ONLY public.php from a plugin directory, so this
 * is reached as public.php?page=partner_api&action=…; public.php requires it
 * behind its own early-exit route.
 *
 * It self-bootstraps (like webhook.php / distributor_apply.php), GATES the whole
 * surface on Uganda + distributors_enabled (off/other tenant ⇒ 404, as if the
 * page did not exist), builds a plain request array and hands it to the pure
 * PartnerApi dispatcher, then applies the returned status, cookie and JSON. The
 * dispatcher is deny-by-default and never falls through to the staff API.
 *
 * The pilot SENDS NOTHING: the login code is produced but handed to a null
 * delivery seam (no live transport). A real sender is wired later (P4f), behind
 * the flag.
 */

require_once __DIR__ . '/lib/error_handler.php';
require_once __DIR__ . '/lib/bootstrap_data.php';
$dataDir = getDataDir(__DIR__);

require_once __DIR__ . '/lib/StoreInterface.php';
require_once __DIR__ . '/lib/SqliteStore.php';
require_once __DIR__ . '/lib/PluginConfig.php';
require_once __DIR__ . '/lib/StaffJobsGate.php';
require_once __DIR__ . '/lib/TenantProfile.php';
require_once __DIR__ . '/lib/PhoneNumber.php';
require_once __DIR__ . '/lib/Totp.php';
require_once __DIR__ . '/lib/PartnerContext.php';
require_once __DIR__ . '/lib/PartnerSession.php';
require_once __DIR__ . '/lib/PartnerAccounts.php';
require_once __DIR__ . '/lib/PartnerAuth.php';
require_once __DIR__ . '/lib/DistributorPortalData.php';
require_once __DIR__ . '/lib/PartnerApi.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$store  = SqliteStore::create($dataDir);
$config = PluginConfig::load(__DIR__, $dataDir);

// The gate: the surface does not exist unless the tenant is Uganda AND the pilot
// flag is on. Exactly the gate the Distributors tab and sidebar link carry.
if (!(StaffJobsGate::applies(is_array($config) ? $config : [], $dataDir) && !empty($config['distributors_enabled']))) {
    http_response_code(404);
    echo json_encode(['error' => 'not_found']);
    exit;
}

$tp  = TenantProfile::current($config, $dataDir);
$pdo = $store->getPdo();

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$body = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $j = json_decode((string)$raw, true);
    if (is_array($j)) $body = $j;
}
// a GET id for me.link travels in the query; copied into a reserved server key.
$server = $_SERVER;
if (isset($_GET['id'])) $server['__query_id'] = (int)$_GET['id'];

$req = [
    'action' => (string)($_GET['action'] ?? ''),
    'method' => $method,
    'body'   => $body,
    'server' => $server,
    'cookie' => $_COOKIE,
    'ip'     => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
    'ua'     => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
];

// Pilot: NOTHING is sent. The delivery seam is null, so no code leaves the
// server from this live entry — whatever the flag, whatever the config.
//
// The sender exists (lib/PartnerOtpSender.php, WS-A P4, docs/53): it delivers the
// code over the EXISTING Evolution support instance to the account's OWN verified
// contact, resolved server-side (never from the request). Wiring it here —
//     $deliver = [PartnerOtpSender::fromConfig($pdo, $config), 'send'];
// turns on real OTP sending and is a SEPARATE, explicitly-approved step (a real
// send is its own gate; the portal flag stays off meanwhile). It is left unwired
// deliberately so no accidental send is possible from the live path.
$deliver = null;

$resp = PartnerApi::handle($pdo, $config, $tp, $req, $deliver);

$cookie = $resp['cookie'] ?? null;
if (is_array($cookie)) {
    if (($cookie['action'] ?? '') === 'set')   PartnerSession::setCookie((string)$cookie['token'], $cookie['maxage'] ?? null);
    elseif (($cookie['action'] ?? '') === 'clear') PartnerSession::clearCookie();
}
http_response_code((int)($resp['code'] ?? 500));
echo json_encode($resp['body'] ?? ['error' => 'server_error']);
exit;
