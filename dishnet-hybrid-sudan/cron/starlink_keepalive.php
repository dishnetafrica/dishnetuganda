<?php
declare(strict_types=1);

/**
 * starlink_keepalive.php — keep the Starlink session alive by using it.
 *
 * Scheduled every 5 minutes. This is not a data sync and not telemetry: it is
 * the reason a session survives at all.
 *
 * ─── Why this exists ─────────────────────────────────────────────────────
 * Starlink's access token expires in minutes. Imported by hand and left
 * alone, a session was accepted, then rejected on the next command a few
 * minutes later — and no endpoint we could find mints a new token, so the
 * obvious reading was that somebody must re-import constantly. Which nobody
 * would.
 *
 * The reading was wrong. The South Sudan installation has run for months on
 * the same mechanism, and its cron_session.php — titled "Per-Account Cookie
 * Keep-Alive", every 300 seconds — is what makes that possible. A session
 * that is USED stays alive. One that is left alone does not.
 *
 * I had read that cron as telemetry polling and recommended deferring it as
 * low value at this scale. It is load-bearing. This is the correction.
 *
 * ─── What it does ────────────────────────────────────────────────────────
 * One cheap request, and the outcome recorded. It uses the light service-line
 * endpoint rather than the rich one because the rich one intermittently 500s,
 * and a keep-alive that reports failure when Starlink hiccups teaches people
 * to ignore it.
 *
 * It never sends, never writes business data, and stops touching a session
 * that is already expired or dead — hammering a dead cookie is how an account
 * gets noticed.
 *
 * CLI only.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$pluginRoot = dirname(__DIR__);
require_once $pluginRoot . '/lib/error_handler.php';
require_once $pluginRoot . '/lib/bootstrap_data.php';
require_once $pluginRoot . '/lib/PluginConfig.php';
require_once $pluginRoot . '/lib/StarlinkSessionStore.php';
require_once $pluginRoot . '/lib/StarlinkPortalConnector.php';

$dataDir = getDataDir($pluginRoot);
$config  = PluginConfig::load($pluginRoot, $dataDir);
$store   = new StarlinkSessionStore($pluginRoot, $dataDir);

if ($store->cookie() === '') exit(0);            // nothing imported here

$status = $store->status();

// A session already declared expired or dead cannot be revived by asking
// again. Leave it for a person and say so once, not every five minutes.
if (in_array($status['state'], [StarlinkSessionStore::STATE_EXPIRED,
                                StarlinkSessionStore::STATE_DEAD], true)) {
    if (($status['last_checked_at'] ?? '') !== ''
        && strtotime((string)$status['last_checked_at']) < time() - 3600) {
        error_log('[starlink_keepalive] session is ' . $status['state']
                . ' — a person must re-import: php tools/starlink_session.php --import');
        $store->markExpired((string)$status['last_error']);   // refresh the timestamp
    }
    exit(0);
}

$conn = new StarlinkPortalConnector($store, $config);
$r    = $conn->get(StarlinkPortalConnector::LINES_LIGHT_PATH);

if (!empty($r['ok'])) {
    // markOk() already ran inside the request. Nothing to say — a keep-alive
    // that logs every success drowns the one line that matters.
    exit(0);
}

// A 5xx is Starlink's weather and costs the session nothing; the connector
// has already decided that. Anything else is worth a line, because a session
// that has stopped working is a sync that has stopped running.
if ((int)$r['code'] < 500) {
    error_log('[starlink_keepalive] session no longer working: ' . (string)$r['error']);
}
exit(0);
