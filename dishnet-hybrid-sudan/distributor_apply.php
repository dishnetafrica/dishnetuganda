<?php
declare(strict_types=1);
chdir(__DIR__);
require_once __DIR__ . '/lib/error_handler.php';
$GLOBALS['_DISHNET_ERROR_FORMAT'] = 'json';

/**
 * distributor_apply.php — public intake for the "Become a DishNet Distributor"
 * website page (dishnet-web-uganda/site/become-a-distributor.html).
 *
 *   POST /public.php?page=distributor_apply
 *   form-encoded: payload=<JSON of the application>, hp=<honeypot>, t=<ms since render>
 *   -> { "ok":true, "ref":"DNP-00042" }   or   { "ok":false, "reason":"..." }
 *
 * Cross-origin by construction (the site is dishnetuganda.com; this lives on
 * crm.dishnetuganda.com), modelled on web_chat.php. Deliberately NOT here:
 *
 *   - No uCRM. It writes ONLY its own table (dist_partner_applications, 077).
 *     It creates no client, no partner, no service, no account, no portal
 *     access. Appointing a partner is a separate DishNet staff action.
 *   - No authentication (public form) — so an origin allow-list, a per-IP rate
 *     limit, a honeypot and a minimum fill-time stand in for it.
 *   - No secret of any kind reaches the browser.
 */

if (!function_exists('str_contains')) {
    function str_contains(string $h, string $n): bool { return $n === '' || strpos($h, $n) !== false; }
}

ob_start();
set_error_handler(function (int $no, string $msg, string $file, int $line) {
    error_log(sprintf('[distributor_apply] %s in %s:%d', $msg, basename($file), $line));
    return true;
}, E_ALL & ~E_ERROR & ~E_PARSE);

require_once __DIR__ . '/lib/bootstrap_data.php';
$dataDir = getDataDir(__DIR__);
if (!is_dir($dataDir)) @mkdir($dataDir, 0755, true);

require_once __DIR__ . '/lib/StoreInterface.php';
require_once __DIR__ . '/lib/SqliteStore.php';
require_once __DIR__ . '/lib/PluginConfig.php';
require_once __DIR__ . '/lib/PublicPriceFeed.php';
require_once __DIR__ . '/lib/DistributorApplicationService.php';

$GLOBALS['_dpaDataDir'] = $dataDir;
$store  = SqliteStore::create($dataDir);
$config = PluginConfig::load(__DIR__, $dataDir);
try {
    $stored = $store->load('kyc_config.json');
    if (is_array($stored)) {
        foreach ($stored as $k => $v) {
            if ($v === null || $v === '') continue;
            if (!array_key_exists($k, $config) || $config[$k] === '' || $config[$k] === null) $config[$k] = $v;
        }
    }
} catch (\Throwable $e) { /* files alone are fine */ }

// ── CORS ────────────────────────────────────────────────────────────────────
// Shares the site origin allow-list with the price feed: default
// dishnetuganda.com + www, overridable via $config['site_origins']. Never '*'.
$allowed  = PublicPriceFeed::allowedOrigins($config);
$origin   = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
$originOk = $origin !== '' && in_array($origin, $allowed, true);

if ($originOk) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    if ($originOk) {
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 86400');
    }
    http_response_code($originOk ? 204 : 403);
    exit;
}

function dpa_out(array $body, int $code = 200): void
{
    $stray = '';
    while (ob_get_level() > 0) { $stray .= (string)ob_get_clean(); }
    if (trim($stray) !== '') {
        $dir = $GLOBALS['_dpaDataDir'] ?? '';
        if ($dir !== '') {
            @file_put_contents($dir . '/distributor_apply.log', sprintf(
                "[%s] stray output suppressed — %s\n",
                gmdate('c'), trim(preg_replace('/\s+/', ' ', strip_tags($stray)))
            ), FILE_APPEND);
        }
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    dpa_out(['ok' => false, 'reason' => 'method'], 405);
}
if ($origin !== '' && !$originOk) {
    dpa_out(['ok' => false, 'reason' => 'origin'], 403);
}

// ── Honeypot + minimum fill-time (cheap bot filters; a real applicant trips
// neither). A filled honeypot or an impossibly fast submit is refused with
// ok:false so the website falls back to its WhatsApp hand-off (a human never
// loses their application), while a bot simply goes away. ──
if (trim((string)($_POST['hp'] ?? '')) !== '') {
    dpa_out(['ok' => false, 'reason' => 'rejected'], 200);
}
$elapsed = (int)($_POST['t'] ?? 0);
if ($elapsed > 0 && $elapsed < 2500) {
    dpa_out(['ok' => false, 'reason' => 'too_fast'], 200);
}

// ── Payload (form-encoded, CORS-safelisted so no preflight) ──
$rawPayload = (string)($_POST['payload'] ?? '');
if ($rawPayload === '' || strlen($rawPayload) > 20000) {
    dpa_out(['ok' => false, 'reason' => 'bad_payload'], 400);
}
$payload = json_decode($rawPayload, true);
if (!is_array($payload)) {
    dpa_out(['ok' => false, 'reason' => 'bad_payload'], 400);
}

// ── Client IP (Traefik is the socket peer; trust only the hop we set) ──
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
$fwd = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
if ($fwd !== '') {
    $first = trim(explode(',', $fwd)[0]);
    if (filter_var($first, FILTER_VALIDATE_IP)) $ip = $first;
}

// ── Per-IP rate limit (8/hour per IP, 400/day global). Best-effort; a limiter
// fault must never block a legitimate applicant. ──
if (dpa_rate_limited($store, $ip)) {
    dpa_out(['ok' => false, 'reason' => 'rate_limited'], 429);
}
function dpa_rate_limited($store, string $ip): bool
{
    try {
        $file = 'distributor_apply_usage.json';
        $d = $store->load($file);
        $events = (is_array($d) && isset($d['events']) && is_array($d['events'])) ? $d['events'] : [];
        $now = time();
        $events = array_values(array_filter($events, fn($e) => is_array($e) && ($now - (int)($e['ts'] ?? 0)) < 86400));
        $hour = 0;
        foreach ($events as $e) {
            if (($now - (int)($e['ts'] ?? 0)) < 3600 && (string)($e['ip'] ?? '') === $ip) $hour++;
        }
        if ($hour >= 8 || count($events) >= 400) return true;
        $events[] = ['ts' => $now, 'ip' => $ip];
        $store->save($file, ['events' => $events]);
        return false;
    } catch (\Throwable $e) {
        return false;
    }
}

// ── Validate + sanitise server-side (never trust the browser) ──
$norm = DistributorApplicationService::normalise($payload);
if (!$norm['ok']) {
    dpa_out(['ok' => false, 'reason' => 'invalid', 'fields' => $norm['errors']], 422);
}

// ── Store (own table only; no uCRM, no partner, no account) ──
try {
    $svc = DistributorApplicationService::fromStore($store, $dataDir);
    $res = $svc->create($norm['clean'], [
        'ip'         => $ip,
        'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
        'raw_json'   => $rawPayload,
    ]);
} catch (\Throwable $e) {
    @file_put_contents($dataDir . '/distributor_apply.log', sprintf(
        "[%s] store failed — %s in %s:%d\n",
        gmdate('c'), $e->getMessage(), basename($e->getFile()), $e->getLine()), FILE_APPEND);
    dpa_out(['ok' => false, 'reason' => 'store_failed'], 500);
}

dpa_out(['ok' => true, 'ref' => $res['ref']]);
