<?php
declare(strict_types=1);
chdir(__DIR__);
require_once __DIR__ . '/lib/error_handler.php';
$GLOBALS['_DISHNET_ERROR_FORMAT'] = 'json'; // webhooks always return JSON

/**
 * wa_webhook.php — WhatsApp Incoming Message Receiver
 *
 * Configure your WA Sender (wa-whatsappsender) plugin to POST incoming
 * messages to this URL:
 *
 *   https://yourserver.com/_plugins/dishnet-hybrid-telecom/wa_webhook.php
 *
 * ── In WASender Settings → Webhook:
 *      Webhook URL    : https://yourserver.com/_plugins/dishnet-hybrid-telecom/wa_webhook.php
 *      Webhook Secret : (paste wa_webhook_secret from DishNet Settings)
 *
 * ── Expected POST payload (WASender format):
 *   {
 *     "app_key":  "...",
 *     "sender":   "211912345678",          ← customer phone
 *     "message":  "Hello, I need help",
 *     "type":     "text",
 *     "name":     "John Doe",              ← optional display name
 *     "timestamp": 1712345678
 *   }
 *
 * Also accepts alternative field names used by some WASender versions:
 *   "from", "phone", "contact", "body", "text"
 */

// ── Bootstrap ─────────────────────────────────────────────────────────────
require_once __DIR__ . '/lib/bootstrap_data.php';
$dataDir = getDataDir(__DIR__);
if (!is_dir($dataDir)) @mkdir($dataDir, 0755, true);

require_once __DIR__ . '/lib/SqliteStore.php';
require_once __DIR__ . '/lib/JsonStore.php';
require_once __DIR__ . '/lib/NotificationService.php';
require_once __DIR__ . '/lib/WaBotService.php';

$store  = SqliteStore::create($dataDir);
$config = $store->load('kyc_config.json') ?? [];

// ── Response helper ────────────────────────────────────────────────────────
function waResp(int $code, string $msg, array $data = []): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['status' => $code < 300 ? 'ok' : 'error', 'message' => $msg] + $data);
    exit;
}

function waLog(string $event, string $msg, array $data = []): void {
    global $dataDir;
    $logFile = $dataDir . '/wa_webhook_log.json';
    $log = [];
    if (file_exists($logFile)) {
        $raw = json_decode(file_get_contents($logFile), true);
        $log = is_array($raw) ? $raw : [];
    }
    $maxId = empty($log) ? 0 : max(array_map(fn($r) => (int)($r['id'] ?? 0), $log));
    array_unshift($log, [
        'id'          => $maxId + 1,
        'event'       => $event,
        'message'     => $msg,
        'data'        => $data,
        'received_at' => date('Y-m-d H:i:s'),
        'ip'          => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);
    $log = array_slice($log, 0, 500);
    file_put_contents($logFile, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// ── Only accept POST ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    waResp(405, 'POST required.');
}

// ── Verify webhook secret ─────────────────────────────────────────────────
$rawBody = file_get_contents('php://input');
if (empty($rawBody)) waResp(400, 'Empty body.');

$secret = trim($config['wa_webhook_secret'] ?? '');
if ($secret !== '') {
    $receivedSig = $_SERVER['HTTP_X_WA_SECRET']
                ?? $_SERVER['HTTP_X_WEBHOOK_SECRET']
                ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    // Strip "Bearer " prefix if present
    $receivedSig = preg_replace('/^Bearer\s+/i', '', trim($receivedSig));

    if (!hash_equals($secret, $receivedSig)) {
        // Also allow secret as query param for easy testing
        $qSecret = trim($_GET['secret'] ?? '');
        if (!hash_equals($secret, $qSecret)) {
            waLog('auth_failed', 'Invalid webhook secret');
            waResp(401, 'Unauthorized.');
        }
    }
}

// ── Parse payload ─────────────────────────────────────────────────────────
$payload = json_decode($rawBody, true);
if (!is_array($payload)) waResp(400, 'Invalid JSON.');

// Normalise field names — WASender uses different keys in different versions
$phone   = $payload['sender']   ?? $payload['from']    ?? $payload['phone']
        ?? $payload['contact']  ?? $payload['msisdn']  ?? '';
$text    = $payload['message']  ?? $payload['body']    ?? $payload['text']
        ?? $payload['content']  ?? '';
$name    = $payload['name']     ?? $payload['display_name'] ?? $payload['contact_name'] ?? null;
$type    = $payload['type']     ?? 'text';

// ── Pusher dedup: if message came from dishnet_wa_pusher, track pkId ─────────
// Fix #3: Uses SQLite INSERT OR IGNORE instead of JSON file (was race condition).
$pusherPkId  = (int)($payload['pkId'] ?? 0);
$isPusherMsg = ($payload['_source'] ?? '') === 'dishnet_pusher';

if ($isPusherMsg && $pusherPkId > 0) {
    $wpdo = $store->getPdo();

    // Ensure table exists (migration 054 may not have run yet)
    try {
        $wpdo->exec("CREATE TABLE IF NOT EXISTS wa_pusher_processed (
            pkid INTEGER PRIMARY KEY,
            processed_at TEXT NOT NULL DEFAULT (datetime('now'))
        )");
        $wpdo->exec("CREATE TABLE IF NOT EXISTS wa_sync_cursor (
            id INTEGER PRIMARY KEY DEFAULT 1,
            last_pkid INTEGER NOT NULL DEFAULT 0,
            total_synced INTEGER NOT NULL DEFAULT 0,
            last_sync_at TEXT,
            last_batch_size INTEGER NOT NULL DEFAULT 0
        )");
        $wpdo->exec("INSERT OR IGNORE INTO wa_sync_cursor (id, last_pkid) VALUES (1, 0)");
    } catch (\Throwable $_e) {}

    // Atomic dedup — INSERT OR IGNORE is atomic in SQLite; rowCount=0 means duplicate
    try {
        $wstmt = $wpdo->prepare("INSERT OR IGNORE INTO wa_pusher_processed (pkid) VALUES (?)");
        $wstmt->execute([$pusherPkId]);
        $wInserted = $wstmt->rowCount();
    } catch (\Throwable $_e) {
        $wInserted = 1; // on error assume new — safer to process than skip
    }

    if ($wInserted === 0) {
        waResp(409, 'Already processed.', ['pkId' => $pusherPkId]);
    }

    // Prune old entries (keep last 1000) — prevents unbounded table growth
    try {
        $wpdo->exec("DELETE FROM wa_pusher_processed WHERE pkid NOT IN
            (SELECT pkid FROM wa_pusher_processed ORDER BY pkid DESC LIMIT 1000)");
    } catch (\Throwable $_e) {}

    // Advance wa_sync_cursor so cron_wa_sync skips this pkId
    try {
        $wCursor = (int)$wpdo->query("SELECT last_pkid FROM wa_sync_cursor WHERE id=1")->fetchColumn();
        if ($pusherPkId > $wCursor) {
            $wpdo->prepare("UPDATE wa_sync_cursor SET last_pkid=?, last_sync_at=datetime('now') WHERE id=1")
                 ->execute([$pusherPkId]);
        }
    } catch (\Throwable $_e) {}
}


// ── Media payload logger — capture exact payload for non-text messages ──────
// This logs the FULL payload to SQLite so we can see exactly what WASender
// sends for images, audio, video — then build proper handling from real data.
if (!in_array(strtolower($type), ['text', 'chat', 'conversation', ''])) {
    try {
        $store->getPdo()->exec("CREATE TABLE IF NOT EXISTS wa_media_debug (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            type TEXT, phone TEXT, payload TEXT,
            created_at TEXT DEFAULT (datetime('now'))
        )");
        $store->getPdo()->prepare(
            "INSERT INTO wa_media_debug (type, phone, payload, created_at) VALUES (?,?,?,datetime('now'))"
        )->execute([
            strtolower($type),
            $phone,
            // Log everything EXCEPT actual base64 data to keep size small
            json_encode(array_map(function($v) {
                if (is_string($v) && strlen($v) > 200) return '[' . strlen($v) . ' bytes]';
                return $v;
            }, $payload)),
        ]);
    } catch (Throwable $e) {}
}

// ── One pipeline, whichever transport delivered ────────────────────────────
//
// What used to be here decided for itself what to store, whether to reply and
// what to do about media — and had drifted from cron_wa_sync.php in ways
// nobody chose. Its media branch hardcoded '[TYPE received]' as the body and
// DISCARDED the caption, so a photo captioned "is this installed right?"
// arrived as a photo with no question attached. The cron path kept captions
// but never acknowledged anything.
//
// Neither difference was intended, and a security fix applied to one would
// have missed the other. Now this file receives, normalises, and hands over.
// Identity, authorization, the model and the output guard all live behind
// WaMessageProcessor, so there is no weaker path for an attacker to choose.
require_once __DIR__ . '/lib/WaInbound.php';
require_once __DIR__ . '/lib/WaMessageProcessor.php';
require_once __DIR__ . '/lib/ConversationService.php';
require_once __DIR__ . '/lib/WaAutoReplyService.php';
require_once __DIR__ . '/lib/NotificationService.php';

$inbound = WaInbound::normalise($payload, 'webhook', $waChannel ?? 'support');

if (!$inbound['usable']) {
    waLog('skipped', 'Not usable: ' . $inbound['why'], ['phone' => $inbound['phone']]);
    waResp(200, 'Ignored — ' . $inbound['why']);
}

try {
    $_wConv   = new ConversationService($dataDir, $store->getPdo());
    $_wNotify = new NotificationService($store, $config);
    $_wAuto   = new WaAutoReplyService($store, $store->getPdo(), $_wNotify, $config, $_wConv);
    $_wProc   = new WaMessageProcessor($_wConv, $_wAuto, $_wNotify, $config);

    $res = $_wProc->process($inbound);

    waLog('message_received', 'Processed via WaMessageProcessor (' . $inbound['channel'] . ')', [
        'phone'     => $inbound['phone'],
        'modality'  => $res['modality'],
        'action'    => $res['action'],
        'replied'   => $res['replied'],
        'duplicate' => $res['duplicate'],
    ]);
    waResp(200, 'Message processed.', [
        'channel'   => $inbound['channel'],
        'modality'  => $res['modality'],
        'action'    => $res['action'],
        'replied'   => $res['replied'],
        'duplicate' => $res['duplicate'],
    ]);
} catch (Throwable $e) {
    waLog('error', 'Exception: ' . $e->getMessage(),
          ['phone' => $inbound['phone'], 'trace' => substr($e->getTraceAsString(), 0, 300)]);
    waResp(500, 'Internal error: ' . $e->getMessage());
}
