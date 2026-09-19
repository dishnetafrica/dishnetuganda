<?php
declare(strict_types=1);
/**
 * dpo_push.php — DPO's server-to-server notification.
 *
 * URL: public.php?page=dpo_push
 *
 * ── THIS ENDPOINT IS A DOORBELL ─────────────────────────────────────────
 *
 * DPO's push carries NO signature — no HMAC, no shared secret, nothing to
 * check it with. Their own WooCommerce plugin nonetheless settles an order
 * straight off <Result>000</Result> in the POST body. We will not, because
 * this URL is public: anyone who learned a CompanyRef could otherwise post a
 * success and clear an invoice for free.
 *
 * So this handler reads ONE thing out of the body — which payment DPO is
 * talking about — answers OK, and then asks DPO what actually happened.
 * The push says WHEN to look. verifyToken says WHAT IS TRUE.
 *
 * OK is sent before verification because DPO expects that acknowledgement and
 * a slow verify must not make them retry. It means "received", never
 * "accepted".
 */

require_once __DIR__ . '/lib/error_handler.php';
require_once __DIR__ . '/lib/bootstrap_data.php';
require_once __DIR__ . '/lib/StoreInterface.php';
require_once __DIR__ . '/lib/SqliteStore.php';
require_once __DIR__ . '/lib/PluginConfig.php';
require_once __DIR__ . '/lib/CrmApiClient.php';
require_once __DIR__ . '/lib/DpoBootstrap.php';

$root    = __DIR__;
$dataDir = getDataDir($root);
$config  = PluginConfig::load($root, $dataDir);
$raw     = (string)file_get_contents('php://input');

// Acknowledge first, then work. The connection is closed so DPO is never
// waiting on our uCRM round-trip.
header('Content-Type: text/plain; charset=utf-8');
echo 'OK';
if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
else { @ob_flush(); @flush(); }

if (strpos($raw, '<API3G>') === false) {
    error_log('[dpo_push] body was not a DPO notification — ignored');
    return;
}

$prev = libxml_use_internal_errors(true);
try { $xml = new SimpleXMLElement($raw, LIBXML_NONET); } catch (\Throwable $e) { $xml = null; }
libxml_clear_errors();
libxml_use_internal_errors($prev);
if ($xml === null) { error_log('[dpo_push] unparseable body — ignored'); return; }

// Only the identity is taken from the push. Result and ResultExplanation are
// present in the body and are deliberately NOT read: believing them is the
// whole vulnerability.
$ref   = trim((string)($xml->CompanyRef ?? ''));
$token = trim((string)($xml->TransactionToken ?? ''));

try {
    $store = SqliteStore::create($dataDir);
    $pdo   = $store->getPdo();
    require_once __DIR__ . '/lib/MigrationRunner.php';
    (new MigrationRunner($pdo, __DIR__ . '/migrations'))->run();

    $ps = new DpoPaymentStore($pdo);
    $row = $ref !== '' ? $ps->byReference($ref) : null;
    if ($row === null && $token !== '') $row = $ps->byToken($token);

    if ($row === null) {
        // Could be a probe, could be another merchant's traffic misrouted.
        // Either way there is nothing to settle and nothing to say.
        error_log('[dpo_push] no payment matches ref=' . $ref . ' token=' . $token);
        return;
    }

    $ps->update((string)$row['reference'], ['callback_at' => gmdate('Y-m-d H:i:s')]);
    $ps->event((int)$row['id'], 'callback_received',
               'DPO pushed a notification; verifying with DPO before anything is settled');

    $crm = CrmApiClient::fromUcrm($root, $config);
    // logActivity() is defined by public.php / main.php, which this endpoint
    // does not go through. Fall back to the error log rather than fataling on
    // an undefined function while holding a customer's payment.
    $svc = DpoBootstrap::service($store, $config, $crm,
        function (string $e, string $d) use ($dataDir) {
            if (function_exists('logActivity')) { logActivity($dataDir, $e, 'DPO Pay', $d); }
            else { error_log('[dpo] ' . $e . ' — ' . $d); }
        });

    $r = $svc->verifyAndSettle((string)$row['reference']);
    error_log('[dpo_push] ' . $row['reference'] . ' → ' . $r['status'] . ' (' . $r['code'] . ')');
} catch (\Throwable $e) {
    error_log('[dpo_push] ' . $e->getMessage());
}
