<?php
/**
 * build.php — the sandbox install for scripts/values-5.18.107.{sh,php}: a plugin tree (5.18.106, given), its data
 * folder with the three settings files and a vault, a book built by the plugin's own migrations with fixture rows, a
 * flyer, a webhook log, a sync state and a plugin.log. Planted strings (PLANT_*) must never reach the reader's output.
 *
 *   php build.php <plugin folder> <data folder> <fake uCRM base URL>
 */
declare(strict_types=1);
[$P, $D, $URL] = [rtrim($argv[1], '/'), rtrim($argv[2], '/'), rtrim($argv[3], '/')];
@mkdir($D, 0755, true);
@mkdir($P . '/data', 0755, true);
require $P . '/lib/SqliteStore.php';

$w = function (string $f, $v): void { file_put_contents($f, json_encode($v, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)); };
$w($P . '/ucrm.json', ['ucrmLocalUrl' => $URL . '/', 'ucrmPublicUrl' => $URL . '/', 'pluginAppKey' => 'PLANT_APPKEY_q7', 'pluginDataDir' => $D]);
file_put_contents($P . '/.deployed-commit', "bf29cbd\n");
// the plugin's own config.json: uCRM writes a blank manifest field as "" (so the currency only comes from the vault)
$w($P . '/data/config.json', ['tenant_profile' => null, 'currency_code' => '', 'cashbook_base_currency' => 'SSP']);
file_put_contents($D . '/wa_flyer.jpg', str_repeat('x', 10240));

$store = SqliteStore::create($D);
$pdo = $store->getPdo();
// the settings files after the book: its first boot imports a data folder's kyc_config.json and config_vault.json
$w($D . '/config.json', [
    'ai_enabled' => true, 'ai_hardware_expert' => '1', 'ai_sales_on_all_numbers' => '0', 'ai_qualification' => '1',
    'bot_custom_instructions' => 'PLANT_CUSTOM topic rule text. ' . str_repeat('Answer briefly and in plain words. ', 6), 'ai_fact_payment' => 'Pay to account 1234567890 PLANT_PAY',
    'ai_fact_office' => 'Plot 5 PLANT_OFFICE, call +256 000 111 222', 'ai_fact_prices' => '', 'ai_fact_kit_taxes' => 'omit',
    'quote_company_phone' => '+256000333444', 'alert_whatsapp' => '+256 000 999 888', 'ai_handover_message' => 'Please hold PLANT_HOLD',
    'quote_tracking_enabled' => '1', 'quote_chase_enabled' => '0', 'wa_send_ledger' => 'on', 'timezone' => 'Africa/Kampala',
    'openai_api_key' => 'sk-PLANT_OPENAI', 'ai_media_enabled' => '1', 'ai_media_voice' => '1', 'ai_transcription_provider' => 'openai',
    'ai_media_voice_privacy_line' => '2026-11-01', 'ai_media_openai_stt_model' => 'whisper-1', 'ucrm_quote_organization_id' => '1',
    'wa_webhook_secret' => 'PLANT_WEBHOOK', 'wa_human_cooldown_minutes' => '60', 'alert_patience_minutes' => '',
    'wa_flyer_url' => 'https://example.invalid/PLANT_FLYERURL.jpg', 'wa_feed_secret' => 'PLANT_FEED',
]);
$w($D . '/kyc_config.json', ['stock_statement' => 'In stock PLANT_STOCK']);
$w($D . '/config_vault.json', ['config' => ['currency_code' => 'UGX', 'evolution_api_key' => 'PLANT_VAULTKEY']]);
// the admin screens' copy: differs from the files on purpose
$store->save('kyc_config.json', [
    'wa_bot_enabled' => false, 'bot_instructions_mode' => 'override', 'bot_custom_instructions' => 'PLANT_ROWCUSTOM other text',
    'ai_hardware_expert' => '1', 'quote_tracking_enabled' => '1', 'alert_whatsapp' => '0000999888', 'ucrm_quote_organization_id' => '1',
    'wa_webhook_secret' => '', 'openai_api_key' => 'sk-PLANT_ROWKEY', 'alert_patience_minutes' => '15', 'web_chat_enabled' => '1',
]);
$ins = function (string $t, array $r) use ($pdo): void {
    $pdo->prepare('INSERT INTO ' . $t . ' (' . implode(',', array_keys($r)) . ') VALUES (' . implode(',', array_fill(0, count($r), '?')) . ')')->execute(array_values($r));
};
$ins('knowledge_items', ['item_key' => 'OFFICE_LOCATION', 'kind' => 'fact', 'title' => 'Office PLANT_KBTITLE', 'answer' => 'Plot 5 PLANT_KBANSWER',
    'wa_answer' => 'TT06, +256 000 111 222 PLANT_KBWA', 'status' => 'approved', 'updated_by' => 'PLANT_STAFFNAME']);
$ins('knowledge_items', ['item_key' => 'office_location', 'title' => 'x', 'status' => 'disabled']);
$ins('knowledge_items', ['item_key' => 'HOURS', 'kind' => 'rule', 'title' => 'Hours']);
// migration 087 seeds sales, support and account; the fixture names the instance and adds the pilot's number
$pdo->exec("UPDATE wa_channels SET evo_instance = 'PLANT_INSTANCE', display_name = 'Sales PLANT_DISPLAY' WHERE channel_id = 'sales'");
$ins('wa_channels', ['channel_id' => 'sales-001', 'display_name' => 'Pilot', 'role' => 'sales', 'evo_instance' => 'PLANT_PILOTINSTANCE', 'business_number' => '256000000002', 'owner_type' => 'staff', 'owner_staff_id' => 4]);
// bursts: conversation 7 has two typed events 5 minutes apart (one burst), conversation 8 one, a voice note, and an old pair
$ev = function (int $conv, string $ago, string $by = 'evo_webhook', string $origin = '', string $status = 'done') use ($ins): void {
    $p = ['channel' => 'sales', 'customer_phone' => '256000555444', 'message' => 'PLANT_MSG', 'push_name' => 'PLANT_PUSH'];
    if ($origin !== '') $p['origin'] = $origin;
    $ins('events', ['event_type' => 'ai.reply', 'entity_type' => 'conversation', 'entity_id' => $conv, 'payload' => json_encode($p),
        'status' => $status, 'created_by' => $by, 'created_at' => gmdate('Y-m-d H:i:s', strtotime($ago)), 'error' => 'PLANT_ERROR']);
};
$ev(7, '-2 hours'); $ev(7, '-2 hours +5 minutes'); $ev(8, '-1 hour'); $ev(8, '-1 hour +2 minutes', 'media_worker', 'voice');
$ev(9, '-10 days'); $ev(9, '-10 days +1 minute'); $ev(10, '-3 hours', 'evo_webhook', '', 'pending'); $ev(10, '-3 hours +20 minutes');
$ins('wa_conversations', ['phone' => '256000555444', 'channel' => 'support', 'bot_replied_at' => gmdate('Y-m-d H:i:s', strtotime('-2 days'))]);
$ins('wa_messages', ['conversation_id' => 1, 'direction' => 'in', 'role' => 'customer', 'body' => 'PLANT_BODY', 'metadata' => json_encode(['source' => 'webhook'])]);
$ins('wa_messages', ['conversation_id' => 1, 'direction' => 'out', 'role' => 'bot', 'body' => 'PLANT_BODY2', 'agent_name' => 'Auto-Reply (support)']);
$ins('wa_media', ['conversation_id' => 1, 'wa_message_id' => 'PLANT_WAMID', 'instance' => 'PLANT_INSTANCE', 'channel' => 'sales', 'remote_jid' => '256000555444@s.whatsapp.net',
    'kind' => 'audio', 'status' => 'understood', 'understanding' => 'PLANT_TRANSCRIPT', 'voice_rung' => 'dry_run', 'provider' => 'openai', 'provider_model' => 'whisper-1']);
$ins('wa_voice_calls', ['media_id' => 1, 'conversation_id' => 1, 'attempt' => 1, 'model' => 'whisper-1', 'local_day' => gmdate('Y-m-d'), 'seconds_reserved' => 10,
    'cap_day_s' => 7200, 'voice_rung' => 'dry_run', 'outcome' => 'ok']);
$ins('support_tickets', ['subject' => 'PLANT_TICKET', 'phone' => '256000555444', 'source' => 'whatsapp_bot']);
$store->save('ucrm_quote_links.json', [['id' => 1, 'item' => 'install', 'row_id' => 0, 'product_id' => 13, 'organization_id' => 1, 'product_name' => 'Installation',
    'linked_at' => gmdate('Y-m-d H:i:s', strtotime('-20 days')), 'linked_by_name' => 'PLANT_LINKER', 'linked_by_id' => 4, 'ended_at_ts' => null, 'row_stamp' => '']]);

// the uCRM catalogue switch, turned on by a staff member (5.18.103's mode record)
$store->save('ucrm_catalogue.json', [['id' => 1, 'kind' => 'mode', 'on' => true, 'at' => gmdate('Y-m-d H:i:s', strtotime('-3 days')),
    'by_id' => 4, 'by_name' => 'PLANT_SWITCHER', 'ended_at_ts' => null]]);

$tz = new DateTimeZone('Africa/Kampala');
$w($D . '/wa_webhook_log.json', [
    ['id' => 'a', 'event' => 'message_received', 'message' => 'PLANT_WHLOG', 'data' => ['phone' => '256000555444'], 'received_at' => (new DateTime('-1 day', $tz))->format('Y-m-d H:i:s'), 'ip' => '10.0.0.9'],
    ['id' => 'b', 'event' => 'skipped', 'message' => 'x', 'data' => [], 'received_at' => (new DateTime('-40 days', $tz))->format('Y-m-d H:i:s'), 'ip' => '10.0.0.9'],
]);
$w($D . '/wa_sync_state.json', ['last_sync_at' => gmdate('Y-m-d H:i:s', strtotime('-1 hour')), 'last_batch_size' => 3, 'last_stored' => 2, 'last_errors' => 1,
    'total_synced' => 40, 'cursor' => 'PLANT_CURSOR']);
$lg = function (string $ago, string $t): string { return '[' . gmdate('Y-m-d H:i:s', strtotime($ago)) . '] ' . $t . "\n"; };
file_put_contents($P . '/data/plugin.log', $lg('-40 days', '=== WA Sync Start ===') . $lg('-2 days', '=== WA Sync Start ===') . "Fetched 3 new messages\n"
    . $lg('-2 days', 'ERROR pkId=5: Class "WaInbound" not found PLANT_LOGLINE') . $lg('-1 day', 'WA sync disabled.'));
echo "built\n";
