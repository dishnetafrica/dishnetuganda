<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * crm_webhook_key.php — the uCRM webhook's own key (5.18.37).
 *
 * The plugin's webhook (public.php?page=crm_webhook) accepted a request with
 * no key header at all, because many uCRM versions send none. Since 5.18.37
 * every event is re-read from uCRM by id before anything is acted on, so a
 * forged body achieves nothing — and, optionally, the operator can add a key
 * of the webhook's own: once crm_webhook_key is set here AND configured in
 * uCRM (System → Webhooks → the plugin's endpoint → secret), a request that
 * does not carry it as X-Crm-Key is refused outright.
 *
 *   php tools/crm_webhook_key.php --status      set / not set — never the value
 *   php tools/crm_webhook_key.php --generate    generate a 64-hex key, store it,
 *                                               vault it, SHOW IT ONCE for pasting
 *                                               into uCRM
 *   php tools/crm_webhook_key.php --clear       remove it (the webhook accepts
 *                                               keyless requests again, as before)
 *
 * The key is shown once, on generation, because uCRM needs it typed in.
 * Paste it into uCRM and nowhere else — not into a chat, not into a ticket.
 * If this terminal is being copied somewhere, run --clear and --generate again
 * afterwards.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/error_handler.php';
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/ConfigVault.php';

const KEY = 'crm_webhook_key';

$args    = $argv ?? [];
$dataDir = cliDataDir($root);
$store   = SqliteStore::create($dataDir);
$cfg     = $store->load('kyc_config.json');
if (!is_array($cfg)) $cfg = [];
$cur = trim((string)($cfg[KEY] ?? ''));

if (in_array('--generate', $args, true)) {
    if ($cur !== '' && !in_array('--force', $args, true)) {
        echo "  A webhook key is already set. To replace it, add --force (uCRM must then be\n"
           . "  given the new key, or every webhook is refused until it is).\n";
        exit(1);
    }
    $new = bin2hex(random_bytes(32));
    $cfg[KEY] = $new;
    $store->save('kyc_config.json', $cfg);
    $back = $store->load('kyc_config.json');
    if (!is_array($back) || (string)($back[KEY] ?? '') !== $new) { fwrite(STDERR, "  the store did not keep the key\n"); exit(1); }
    try { ConfigVault::store($root, $dataDir, [KEY => $new]); } catch (\Throwable $e) { /* the store copy still serves */ }
    echo "\n  Webhook key generated and stored.\n\n"
       . "  Paste this into uCRM → System → Webhooks → the DishNet endpoint → secret,\n"
       . "  and nowhere else. It is shown once:\n\n"
       . "      {$new}\n\n"
       . "  Until uCRM sends it as X-Crm-Key, every uCRM webhook will be refused (401).\n"
       . "  If uCRM cannot be configured to send a secret, run --clear.\n\n";
    exit(0);
}

if (in_array('--clear', $args, true)) {
    unset($cfg[KEY]);
    $store->save('kyc_config.json', $cfg);
    try { ConfigVault::store($root, $dataDir, [KEY => '']); } catch (\Throwable $e) {}
    echo "  Webhook key cleared. Requests without a key are accepted again; every\n"
       . "  event is still re-read from uCRM before it is acted on.\n";
    exit(0);
}

echo "  crm_webhook_key: " . ($cur !== '' ? 'set (' . strlen($cur) . ' characters, value withheld)' : 'not set') . "\n";
echo "  Every webhook event is re-read from uCRM by id before it is acted on, key or no key.\n";
exit(0);
