# Facts for rh107-seed.php and rh107q.py (27 Nov; read-only research at the pin 771a3c1, the script at d790edf)

1. NS-G1: places k = <data>/kyc_config.json, p = <plugin>/data/config.json, d = <data>/config.json (judged), s = store row
   kyc_config id 0 `data` JSON (reported only). Switches quoted_no_prices_enabled, connection_quote_enabled,
   ai_burst_merge_enabled; off = absent, null, '', '0', 0, false, 'false', 'off', 'no'. Text keys (presence only, k s p d):
   quoted_reply_text, quoted_alert_cooldown_hours, connection_quote_transport, connection_quote_tax_line,
   connection_quote_closing, no_ai_alert_cooldown_hours, ai_burst_merge_window_minutes. Store row: SqliteStore
   load/save('kyc_config.json') — table kyc_config, one row id 0; save() replaces it; CACHEABLE (forget() on save).
2. Settings text: bot_custom_instructions; SA reads PluginConfig::read (p, d, k; later wins; trimmed) and the store row
   (txtrow). PluginConfig::saveOverrides refuses only SECRET_KEYS and admin_token; writes kyc_config.json (0600) then
   mirrors the whole file into the row (best effort). The AI setup screen uses saveAiSettings (file only).
3. Burst drain (tools/sales_followup.php:197): scope event_type='ai.reply', entity_type='conversation', entity_id>0,
   created_by='evo_webhook'; typed = payload.origin != 'voice'. Control: typed scope rows created in the last 86400 s.
   Marked: instr(error,'superseded by #')>0. (b) marked typed row pending/processing/failed. (a) each mark m != id whose
   named event is pending/processing/failed. Mark = BurstMerge::markAside($eid, $newer) ('superseded by #N'); claim()
   marks and sets done. Plant: ConversationService::ensureConversation(phone,'sales',name,'webhook') then
   EventBus::emit('ai.reply','conversation',cid,[channel, whatsapp_instance, customer_phone, message, push_name,
   wa_message_id, remote_jid, received_at, location], 3, 'evo_webhook') twice; markAside(A,B) gives (a)=1 and (b)=1;
   claim([A],B) gives (a) without (b). Retrying named event: EventBus::fail(B, note) (failed, attempts 1/5,
   next_retry_at +10 s). Answered by the installed run_worker.php (needs ai_enabled; 104's cron104_up precedent with
   fake_openai_server.php).
4. master_schedule: store row master_schedule id 0, {"job": {"last_run": epoch, "last_run_at": local, "duration_ms":
   int}}. ME-CA1's four: followup_close followup_scan followup_run followup_send. ME-CA2's seven: event_processor
   ai_reply wa_watchdog customer_reminders quote_chase ai_media offers_send. (L,-1): duration -1. master_budget_seconds
   lives only in the store row kyc_config (default 300); set_config refuses it ("not one of the settings this tool
   manages").
5. wa_no_ai (092): id, phone (10-15 digits, GLOB '[1-9]*'), reason (<=200), added_by (non-empty), added_at, active
   (0/1), lifted_by, lifted_at; unique partial index on active phone; triggers wna_never_deleted, wna_identity_fixed,
   wna_lift_once. NoAiList::add refuses NA_SENTINEL_1 on Uganda (9 digits after 256 starting 0): plant by raw INSERT
   (fixture). NA-CA1's pass: p1=none any=[01] active=N scan=1 p2=listed,none p3=PDOException gate=*.
6. _migrations: id, filename UNIQUE, checksum = md5(raw file bytes), applied_at DEFAULT datetime('now') (UTC),
   duration_ms; no status column; the row is written even for a partial run. Log lines ([date('Y-m-d H:i:s')] local
   zone): 'OK: {file} ({n} stmts, {ms}ms)'; 'PARTIAL: {file} — …'; 'WARNING: {file} has been modified after being
   applied (checksum mismatch). Skipping.'; trim over 262144 bytes keeps the second half from a line boundary, line 1
   '[date] --- earlier entries trimmed ---'.
