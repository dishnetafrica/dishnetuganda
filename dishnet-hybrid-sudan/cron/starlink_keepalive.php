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
if (PHP_SAPI !== 'cli') return;   // a web request is not ours to serve

$pluginRoot = dirname(__DIR__);
require_once $pluginRoot . '/lib/error_handler.php';
require_once $pluginRoot . '/lib/bootstrap_data.php';
require_once $pluginRoot . '/lib/PluginConfig.php';
require_once $pluginRoot . '/lib/StarlinkSessionStore.php';
require_once $pluginRoot . '/lib/StarlinkPortalConnector.php';
require_once $pluginRoot . '/lib/StarlinkUsage.php';

$dataDir = getDataDir($pluginRoot);
$config  = PluginConfig::load($pluginRoot, $dataDir);
$store   = new StarlinkSessionStore($pluginRoot, $dataDir);

// EVERY account, not just the selected one. Uganda has several Starlink
// accounts; a keep-alive that touches only the active session lets the others
// expire, and the account with the customers on it is not always the one
// somebody was last looking at. The active account is restored at the end, so
// this cron changes no operator's selection.
$accounts = $store->accounts();
if ($accounts === []) return;                    // nothing imported here

$restore = $store->active();

foreach ($accounts as $_ka_acct) {
    if (!$store->useAccount($_ka_acct)) continue;
    if ($store->cookie() === '') continue;        // held, but never imported

    $status = $store->status();

    // ── An expired session gets one cheap attempt an hour ────────────────
    //
    // This used to log and skip, on the assumption that an expired session
    // "cannot be revived by asking again". That was never measured, and it had
    // a consequence nobody intended: ONE missed heartbeat marked a session
    // expired permanently, and no amount of later ticks would touch it again.
    // A person had to notice and paste. With a heartbeat that was slower than
    // the token's life, that happened constantly.
    //
    // South Sudan runs the same mechanism on pasted cookies and syncs every two
    // hours without anyone re-pasting, so sessions there plainly survive. An
    // attempt costs one request; being wrong costs a working session and a
    // person's afternoon. So try, at a rate that could not be mistaken for
    // hammering, and let the result decide rather than the label.
    if (in_array($status['state'], [StarlinkSessionStore::STATE_EXPIRED,
                                    StarlinkSessionStore::STATE_DEAD], true)) {
        $_ka_last = (string)($status['last_checked_at'] ?? '');
        if ($_ka_last !== '' && strtotime($_ka_last) >= time() - 3600) continue;

        $_ka_try = (new StarlinkPortalConnector($store, $config))
            ->raw('GET', StarlinkPortalConnector::LINES_LIGHT_PATH);
        $_ka_body = ltrim((string)($_ka_try['body'] ?? ''));
        if ((int)$_ka_try['code'] === 200 && $_ka_body !== ''
            && ($_ka_body[0] === '{' || $_ka_body[0] === '[')) {
            // It answers. The verdict was wrong, or the session recovered.
            $store->markOk();
            error_log('[starlink_keepalive] ' . $_ka_acct
                    . ' answered again after being marked ' . $status['state'] . ' — revived');
            continue;
        }

        error_log('[starlink_keepalive] ' . $_ka_acct . ' is ' . $status['state']
                . ' and still not answering (HTTP ' . (int)$_ka_try['code'] . ') — paste a fresh '
                . 'cookie under Admin → Starlink Sessions');
        $store->markExpired((string)$status['last_error']);   // refresh the timestamp
        continue;
    }

    // A fresh connector per account: it caches nothing across accounts, and
    // reusing one would have it answer for the session it was built with.
    $conn = new StarlinkPortalConnector($store, $config);
    $r    = $conn->get(StarlinkPortalConnector::LINES_LIGHT_PATH);

    if (!empty($r['ok'])) {
        // ── The second auth layer ────────────────────────────────────────
        //
        // Starlink authorises telemetryagg.* separately from the account
        // endpoints. A cookie can pass the one this call just used and be
        // rejected by the other — which is exactly what was happening here:
        // starlink_session.php reported "session accepted YES" while every
        // usage call answered 401 token_expired, minutes after an import.
        //
        // dishnet-data-report learned this the expensive way. Its v2.7.23 note
        // says the Sessions tab showed 42 of 42 sessions alive while a sync
        // took 279 telemetry 401s, and its heartbeat has probed both layers
        // ever since.
        //
        // Using a layer is what keeps it alive, and the response carries
        // rotated tokens that get() merges and stores. So keeping only the
        // account layer warm let the telemetry one expire on its own — and a
        // usage collector that runs hourly would find it dead every time.
        $_ka_lines = [];
        array_walk_recursive((array)($r['data'] ?? []), static function ($v) use (&$_ka_lines) {
            $t = strtoupper(trim((string)$v));
            if (preg_match('/^SL-[0-9A-Z]+(-[0-9A-Z]+)+$/', $t)) $_ka_lines[] = $t;
        });
        if ($_ka_lines !== []) {
            sort($_ka_lines);
            $_ka_tel = $conn->get(sprintf(StarlinkUsage::PATH,
                rawurlencode($_ka_acct), rawurlencode($_ka_lines[0])));
            if (empty($_ka_tel['ok']) && (int)$_ka_tel['code'] < 500) {
                error_log('[starlink_keepalive] ' . $_ka_acct
                        . ' passes the account layer but NOT telemetry: '
                        . (string)$_ka_tel['error']
                        . ' — usage collection will fail until a cookie is re-imported');
            }
        }
        continue;   // markOk() already ran inside the request
    }

    // A 5xx is Starlink's weather and costs the session nothing; the connector
    // has already decided that. Anything else is worth a line, because a
    // session that has stopped working is a sync that has stopped running.
    if ((int)$r['code'] < 500) {
        error_log('[starlink_keepalive] ' . $_ka_acct
                . ' session no longer working: ' . (string)$r['error']);
    }
}

// Put the selection back. An operator who switched to an account to look at
// it should not find the cron has moved them somewhere else.
if ($restore !== '') $store->useAccount($restore);
return;
