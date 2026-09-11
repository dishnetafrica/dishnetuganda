#!/usr/bin/env php
<?php
// Note: No strict_types — included from master.php

/**
 * cron/wa_webhook_guard.php — the webhook that feeds the WhatsApp AI must
 * STAY registered, not just get registered once.
 *
 * The failure this guards against actually happened: Evolution lost the
 * webhook for the sales instance, inbound customer messages silently stopped
 * arriving, and the AI "stopped replying" for days until a human noticed.
 * The doctor (tools/wa_webhook_doctor.php) can fix it by hand; this cron is
 * the hand that never forgets.
 *
 * Every run (master.php schedules it):
 *   1. for each mapped channel instance, read Evolution's registered webhook;
 *   2. when it still holds our URL with the CURRENT token, and is enabled —
 *      stay silent;
 *   3. when it is missing, foreign, stale-tokened or disabled — re-register
 *      it, verify by read-back, and tell the admin it happened;
 *   4. when re-registration fails, or the instance is disconnected/missing —
 *      alert the admin with the exact next step, throttled so it nags,
 *      not spams.
 */

$_wg_root = dirname(__DIR__);
require_once $_wg_root . '/lib/bootstrap_data.php';
require_once $_wg_root . '/lib/StoreInterface.php';
require_once $_wg_root . '/lib/SqliteStore.php';
require_once $_wg_root . '/lib/PluginConfig.php';
require_once $_wg_root . '/lib/AlertService.php';
require_once $_wg_root . '/lib/EvolutionApiService.php';
require_once $_wg_root . '/lib/EvoWebhookGuard.php';
require_once $_wg_root . '/lib/wa_webhook_url.php';

$_wg_data   = getenv('DN_DATA_DIR') ?: getDataDir($_wg_root);
$_wg_store  = SqliteStore::create($_wg_data);
$_wg_config = PluginConfig::load($_wg_root, $_wg_data);
try {
    foreach (($_wg_store->load('kyc_config.json') ?: []) as $k => $v) {
        if ($v === null || $v === '') continue;
        if (!array_key_exists($k, $_wg_config) || $_wg_config[$k] === '' || $_wg_config[$k] === null) {
            $_wg_config[$k] = $v;
        }
    }
} catch (\Throwable $e) { /* files alone */ }

$_wg_evoUrl = rtrim(trim((string)($_wg_config['evo_api_url'] ?? '')), '/');
$_wg_evoKey = trim((string)($_wg_config['evo_api_key'] ?? ''));
if ($_wg_evoUrl === '' || $_wg_evoKey === '') {
    return; // WhatsApp stack not configured on this install — nothing to guard
}

$_wg_alerts = new AlertService($_wg_store, $_wg_config);
$_wg_log = function ($m) use ($_wg_data) {
    echo "wa_webhook_guard: {$m}\n";
    @file_put_contents($_wg_data . '/wa_guard.log',
        '[' . gmdate('Y-m-d H:i:s') . '] ' . $m . "\n", FILE_APPEND | LOCK_EX);
};

try {
    $_wg_secret = PluginConfig::isSet_($_wg_config, 'evo_webhook_secret')
        ? (string)$_wg_config['evo_webhook_secret']
        : EvoWebhookGuard::autoSecret($_wg_data);
    if ($_wg_secret === '') {
        $_wg_log('no webhook secret available — data dir not writable?');
        $_wg_alerts->notify('wg:nosecret',
            '⚠️ DishNet: WhatsApp webhook guard cannot obtain a webhook secret — the AI may stop receiving messages. Check the plugin data directory.', 360);
        return;
    }

    $_wg_url = wa_ai_webhook_url($_wg_config, $_wg_secret);
    if ($_wg_url === '') {
        $_wg_log('cannot compute the public webhook URL (plugin_public_url missing)');
        $_wg_alerts->notify('wg:nourl',
            '⚠️ DishNet: WhatsApp webhook guard cannot work out the plugin\'s public URL — set plugin_public_url in Configuration, then run tools/wa_webhook_doctor.php.', 360);
        return;
    }

    $_wg_evo  = new EvolutionApiService($_wg_config);
    $_wg_live = [];
    foreach ($_wg_evo->listInstances() as $i) {
        $_wg_live[(string)($i['name'] ?? '')] = $i;
    }
    if (!$_wg_live) {
        $_wg_log('Evolution returned no instances (server down or key rejected)');
        $_wg_alerts->notify('wg:evodown',
            '⚠️ DishNet: the Evolution WhatsApp server returned no instances — the AI cannot receive OR send. Check the Evolution service.', 180);
        return;
    }

    foreach (EvolutionApiService::CHANNELS as $_wg_chn) {
        $_wg_inst = $_wg_evo->instanceFor($_wg_chn);
        if ($_wg_inst === '') continue;

        $_wg_st = $_wg_live[$_wg_inst] ?? null;
        if ($_wg_st === null) {
            $_wg_log("{$_wg_chn}: mapped instance '{$_wg_inst}' does not exist on Evolution");
            $_wg_alerts->notify("wg:missing:{$_wg_inst}",
                "⚠️ DishNet: WhatsApp {$_wg_chn} points at instance '{$_wg_inst}' which no longer exists on Evolution — recreate it in Engage → WhatsApp AI.", 360);
            continue;
        }
        if (empty($_wg_st['connected'])) {
            $_wg_log("{$_wg_chn}: instance '{$_wg_inst}' is not connected (state=" . (string)($_wg_st['state'] ?? '?') . ')');
            $_wg_alerts->notify("wg:disconnected:{$_wg_inst}",
                "⚠️ DishNet: WhatsApp {$_wg_chn} number is DISCONNECTED — the AI cannot reply. Open Engage → WhatsApp AI and re-scan the QR for '{$_wg_inst}'.", 360);
            // fall through: register the webhook anyway so messages flow the
            // moment the number reconnects
        }

        // Healthy means EXACTLY our URL — host included. The live failure
        // this rule comes from: the webhook held the SUDAN domain with the
        // same path and token, so a substring check would have blessed it
        // while Uganda's messages went to the wrong country.
        $_wg_before = $_wg_evo->getWebhook($_wg_inst);
        $_wg_d      = is_array($_wg_before['data'] ?? null) ? $_wg_before['data'] : [];
        $_wg_regUrl = (string)($_wg_d['url'] ?? ($_wg_d['webhook']['url'] ?? ''));
        $_wg_disab  = (isset($_wg_d['enabled']) && $_wg_d['enabled'] === false)
                   || (isset($_wg_d['webhook']['enabled']) && $_wg_d['webhook']['enabled'] === false);
        if ($_wg_regUrl === $_wg_url && !$_wg_disab) continue;   // healthy — the normal, silent case

        $_wg_log("{$_wg_chn}: webhook for '{$_wg_inst}' is missing/stale — re-registering");
        $_wg_set = $_wg_evo->setWebhook($_wg_inst, $_wg_url);
        $_wg_ad  = (array)($_wg_evo->getWebhook($_wg_inst)['data'] ?? []);
        $_wg_afterUrl = (string)($_wg_ad['url'] ?? ($_wg_ad['webhook']['url'] ?? ''));
        $_wg_fixed = ($_wg_set['ok'] ?? false) && $_wg_afterUrl === $_wg_url;

        if ($_wg_fixed) {
            $_wg_log("{$_wg_chn}: webhook restored and verified for '{$_wg_inst}'");
            $_wg_alerts->notify("wg:restored:{$_wg_inst}",
                "✅ DishNet: the WhatsApp {$_wg_chn} webhook had been lost — the guard re-registered it automatically. The AI receives messages again.", 60);
        } else {
            $_wg_err = $_wg_evo->getLastError();
            $_wg_log("{$_wg_chn}: re-registration FAILED for '{$_wg_inst}' — "
                . (string)($_wg_err['detail'] ?? ($_wg_set['error'] ?? '?')));
            $_wg_alerts->notify("wg:failed:{$_wg_inst}",
                "🚨 DishNet: the WhatsApp {$_wg_chn} webhook is LOST and automatic re-registration FAILED — the AI is not receiving messages. Run: php tools/wa_webhook_doctor.php inside the ucrm container.", 120);
        }
    }
} catch (\Throwable $_wg_e) {
    $_wg_log('guard crashed: ' . $_wg_e->getMessage());
}
