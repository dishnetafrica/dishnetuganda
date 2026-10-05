<?php
declare(strict_types=1);
/**
 * test_voice_media.php — Batch 2 of the AI communication layer (docs/55 §9, docs/56): VOICE, dark.
 *
 * A customer's voice note, fetched by the Batch 1 media worker, becomes the customer's turn through the EXISTING
 * assistant — only while ai_media_enabled AND ai_media_voice are on, and they are on nowhere. What this proves:
 *
 *   1  a valid voice note → wa_media row → fetch → transcript on the row and on the stored message
 *   2  the transcript enters the existing brain exactly once (one ai.reply event, one brain call)
 *   3  it is clearly marked as voice-originated: the label on the message, the VOICE MESSAGE block in the prompt,
 *      the `voice` key in the sales contract, and the label in later history
 *   4  a duplicate event does not transcribe twice (and the understood guard holds even when called directly)
 *   5  non-audio media is ignored by the voice path
 *   6  a voice note too long, unreadable, or too large is rejected — and handed to a person
 *   7  a provider timeout or error is retried through the EventBus; the retry fetches again and succeeds
 *   8  a provider that is missing, or that fails for good, hands the conversation to a person: needs_human, the
 *      wa.escalation event, the staff alert, the operator's holding line — never an invented answer
 *   9  STOP in the transcript records the opt-out exactly as typed STOP does, and is still acknowledged
 *  10  a transcript cannot bypass ReplyPrivacyGuard: a blocked reply gives the safe fallback, audited as origin voice
 *  11  no audio, no base64 and no transcript on disk or in any log
 *  12  ai_media_voice OFF → fetched only, zero transcription, zero customer reply
 *  13  ai_media_enabled OFF wins over ai_media_voice
 *  14  eight weakened copies, each caught by re-running the scenario that guards it
 *
 * Two scenarios return FACTS (no asserting inside): `core`, in-process against the fake Evolution with the deterministic
 * FakeTranscriber injected and a fake brain (the real marker parser) on the reply worker; `cli`, the real plugin under
 * php -S (SjSandbox) with run_media_worker.php over the CLI and the fake provider selected through its test-only
 * environment. Driver mode: php tests/test_voice_media.php --driver=core|cli (env DN_T_EVO_PORT, DN_T_UCRM_PORT).
 */
$root = dirname(__DIR__);
date_default_timezone_set('UTC');
$driver = null;
foreach ($argv ?? [] as $a) { if (strpos($a, '--driver') === 0) $driver = substr($a, 9) !== false && substr($a, 9) !== '' ? substr($a, 9) : 'core'; }

require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/ConversationService.php';
require_once $root . '/lib/EventBus.php';
require_once $root . '/lib/UtcClock.php';
require_once $root . '/lib/AlertService.php';
require_once $root . '/lib/EvoWebhookGuard.php';
require_once $root . '/lib/EvolutionApiService.php';
require_once $root . '/lib/WaLocation.php';
require_once $root . '/lib/LeadMatcher.php';
require_once $root . '/lib/AiLeadService.php';
require_once $root . '/lib/CrmApiClient.php';
require_once $root . '/lib/BrainContext.php';
require_once $root . '/lib/ReplyPrivacyGuard.php';
require_once $root . '/lib/DishNetAiBrain.php';
require_once $root . '/lib/ContactOptOut.php';
require_once $root . '/lib/MediaPolicy.php';
require_once $root . '/lib/MediaBlob.php';
require_once $root . '/lib/InboundMedia.php';
require_once $root . '/lib/MediaFetcher.php';
require_once $root . '/lib/Transcriber.php';
require_once $root . '/lib/VoiceTranscription.php';
require_once $root . '/lib/Handover.php';
require_once $root . '/workers/WorkerBase.php';
require_once $root . '/workers/MediaWorker.php';
require_once $root . '/workers/AiReplyWorker.php';
require_once $root . '/tests/fixtures/staff_jobs_sandbox.php';   // SjSandbox, sj_weakened_copy()

/** A brain that never leaves the process, but parses its canned answer with the REAL marker parser (Batch 0's). */
class VmFakeBrain extends DishNetAiBrain
{
    public string $canned = '';
    public ?array $lastContext = null;
    public int $calls = 0;
    public function isConfigured(): bool { return true; }
    public function reply(array $context): array
    {
        $this->calls++;
        $this->lastContext = $context;
        $m = new ReflectionMethod(DishNetAiBrain::class, 'parseMarkers');
        $m->setAccessible(true);
        $out = $m->invoke($this, $this->canned);
        return is_array($out) ? $out : ['reply' => $this->canned];
    }
    public function getLastUsage(): array { return []; }
}

// ── helpers ──────────────────────────────────────────────────────────────────────────────────────
function vm_hit(int $port, string $path, $json = null, int $timeout = 40): string
{
    $ch = curl_init("http://127.0.0.1:{$port}{$path}");
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*'];
    if ($json !== null) { $o[CURLOPT_POST] = true; $o[CURLOPT_POSTFIELDS] = json_encode($json); $o[CURLOPT_HTTPHEADER] = ['Content-Type: application/json']; }
    curl_setopt_array($ch, $o);
    $r = curl_exec($ch); curl_close($ch);
    return $r === false ? '' : (string)$r;
}
function vm_state(int $port): array { return json_decode(vm_hit($port, '/__test/state'), true) ?: []; }
function vm_boot(string $router, int $base, string $sig): array
{
    foreach (range(0, 11) as $slot) {
        $cand = $base + ((getmypid() + $slot * 13) % 70);
        $p = proc_open(sprintf('exec php -S 127.0.0.1:%d %s', $cand, escapeshellarg($router)), [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 40; $i++) {
            $r = vm_hit($cand, '/__test/state', null, 3);
            if ($r !== '') { if (strpos($r, $sig) !== false) return [$p, $cand]; break; }
            usleep(100000);
        }
        if (is_resource($p)) { proc_terminate($p); proc_close($p); }
    }
    return [null, 0];
}
/** The deterministic payload the fake Evolution builds for `bytes` — so the test knows the sha256 the provider is keyed on. */
function vm_payload(int $n): string { return substr(str_repeat('DN-MEDIA-TEST-PAYLOAD/', (int)ceil($n / 22)), 0, $n); }
function vm_sha(int $n): string { return hash('sha256', vm_payload($n)); }
/** An Evolution messages.upsert envelope: a voice note, or a photo, or text. */
function vm_envelope(string $kind, string $id, string $phone, array $o = []): array
{
    $jid = $phone . '@s.whatsapp.net';
    if ($kind === 'audio') {
        $message = ['audioMessage' => ['url' => 'https://mmg.whatsapp.net/v/t62.7117-24/x.enc', 'mimetype' => 'audio/ogg; codecs=opus',
                    'fileLength' => (string)($o['bytes'] ?? 12345), 'seconds' => $o['seconds'] ?? 7, 'ptt' => true, 'mediaKey' => 'TESTKEY=']];
    } elseif ($kind === 'image') {
        $message = ['imageMessage' => ['url' => 'https://mmg.whatsapp.net/v/t62.7118-24/y.enc', 'mimetype' => 'image/jpeg', 'fileLength' => '20480']];
    } else {
        $message = ['conversation' => (string)($o['text'] ?? 'Hello')];
    }
    return ['event' => 'messages.upsert', 'instance' => $o['instance'] ?? 'sj-sales', 'data' => [
        'key' => ['id' => $id, 'fromMe' => false, 'remoteJid' => $jid],
        'pushName' => 'Voice Tester', 'messageType' => array_keys($message)[0], 'messageTimestamp' => time(), 'message' => $message]];
}
/** Files under $dir (the database itself excluded) holding any of the needles. */
function vm_scan(string $dir, array $needles): array
{
    $hits = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        if (strpos($f->getFilename(), 'plugin.sqlite3') === 0) continue;
        $c = (string)@file_get_contents($f->getPathname());
        foreach ($needles as $n) if ($n !== '' && strpos($c, $n) !== false) { $hits[] = substr($f->getPathname(), strlen($dir)) . ' has ' . substr($n, 0, 12); break; }
    }
    return $hits;
}
function vm_row(PDO $pdo, string $waId): array
{
    $st = $pdo->prepare('SELECT * FROM wa_media WHERE wa_message_id = ?'); $st->execute([$waId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($r) ? $r : [];
}
/** A file's PHP code with every comment removed — the comments may name what the code must not depend on. */
function vm_codeOf(string $file): string
{
    $out = '';
    foreach (token_get_all((string)file_get_contents($file)) as $k) {
        if (is_array($k)) { if (in_array($k[0], [T_COMMENT, T_DOC_COMMENT], true)) continue; $out .= $k[1]; }
        else $out .= $k;
    }
    return $out;
}
function vm_brief(array $r): array
{
    return ['status' => $r['status'] ?? null, 'reason' => $r['failure_reason'] ?? null, 'attempts' => (int)($r['attempts'] ?? 0),
            'kind' => $r['understanding_kind'] ?? null, 'text' => $r['understanding'] ?? null, 'bytes' => isset($r['fetched_bytes']) ? (int)$r['fetched_bytes'] : null];
}

// ── Scenario 1: core, in-process ─────────────────────────────────────────────────────────────────
function vm_core(string $root, int $evoPort, int $ucrmPort): array
{
    $tmp = sys_get_temp_dir() . '/dn_vm_' . bin2hex(random_bytes(4));
    @mkdir($tmp, 0700, true);
    putenv('DN_DATA_DIR=' . $tmp);
    vm_hit($evoPort, '/__test/reset');
    vm_hit($ucrmPort, '/__test/reset');
    vm_hit($ucrmPort, '/__test/scenario?name=fresh_install');
    $store = SqliteStore::create($tmp);
    $pdo   = $store->getPdo();
    $svc   = new ConversationService($tmp, $pdo);
    $bus   = new EventBus($pdo);
    $HOLD  = 'Let me get a colleague to help you with that.';
    $cfg = ['evo_api_url' => "http://127.0.0.1:{$evoPort}", 'evo_api_key' => 'TESTKEY', 'evo_instance_sales' => 'dishnet_ug',
            'evo_instance_support' => 'dishnet_ug', 'ai_enabled' => '1', 'ai_media_enabled' => '1', 'ai_media_voice' => '1',
            'ai_media_max_bytes' => 65536, 'ai_media_timeout_s' => 3, 'ai_media_voice_max_seconds' => 120, 'ai_media_voice_timeout_s' => 5,
            'ai_provider' => 'openai', 'openai_api_key' => 'test-key-never-called',
            'crm_base_url' => "http://127.0.0.1:{$ucrmPort}", 'crm_auth_token' => 'test-key',
            'alert_whatsapp' => '256700000999', 'ai_handover_message' => $HOLD, 'wa_human_cooldown_minutes' => 30, 'data_dir' => $tmp];
    $f = ['tmp' => $tmp, 'hold' => $HOLD];
    $logAll = '';
    $texts  = function () use ($evoPort): array { return vm_state($evoPort)['text_calls'] ?? []; };
    $fetches = function () use ($evoPort): int { return count(vm_state($evoPort)['media_fetch_calls'] ?? []); };
    $serve = function (string $waId, int $bytes, string $mime = 'audio/ogg; codecs=opus') use ($evoPort): void {
        vm_hit($evoPort, '/__test/media', ['for_id' => $waId, 'bytes' => $bytes, 'mimetype' => $mime]);
    };
    $count = function (string $sql) use ($pdo): int { return (int)$pdo->query($sql)->fetchColumn(); };
    $replies = function () use ($count): int { return $count("SELECT COUNT(*) FROM events WHERE event_type = 'ai.reply'"); };
    $lastReply = function () use ($pdo): array {
        $r = $pdo->query("SELECT id, payload, created_by, status FROM events WHERE event_type = 'ai.reply' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
        $r['p'] = json_decode((string)($r['payload'] ?? ''), true) ?: [];
        return $r;
    };
    $conv = function (string $phone) use ($svc): int { return (int)$svc->ensureConversation($phone, 'sales', 'Voice Tester', 'test')['id']; };
    $state = function (int $cid) use ($svc): string { return (string)($svc->getConversation($cid)['state'] ?? ''); };
    /** A voice note as the webhook records it: the placeholder message, the wa_media row, the ai.media event. */
    $voiceNote = function (string $waId, string $phone, int $seconds = 7) use ($svc, $pdo, $bus, $conv): array {
        $cid = $conv($phone);
        $env = vm_envelope('audio', $waId, $phone, ['seconds' => $seconds]);
        $msgId = $svc->storeMessage($cid, ['direction' => 'in', 'role' => 'customer', 'body' => '[AUDIO]', 'media_type' => 'audio', 'wa_message_id' => $waId]);
        $mediaId = InboundMedia::record($pdo, $cid, InboundMedia::fromEvoMessage($env['data']), 'dishnet_ug', 'sales', $waId);
        $evt = $bus->emit('ai.media', 'conversation', $cid, ['media_id' => $mediaId, 'conversation_id' => $cid, 'channel' => 'sales',
            'whatsapp_instance' => 'dishnet_ug', 'wa_message_id' => $waId, 'kind' => 'audio', 'has_caption' => false, 'received_at' => gmdate('c')], 3, 'test');
        return ['cid' => $cid, 'media_id' => (int)$mediaId, 'msg_id' => (int)$msgId, 'event' => $evt];
    };
    $fake = new FakeTranscriber([]);
    $script = function (string $sha, array $entry) use (&$fake): void { $rp = new ReflectionProperty(FakeTranscriber::class, 'script'); $rp->setAccessible(true); $s = $rp->getValue($fake); $s[$sha] = $entry; $rp->setValue($fake, $s); };
    $mkMedia = function (array $ov = [], $transcriber = 'fake') use ($store, $cfg, &$fake): MediaWorker {
        $w = new MediaWorker($store, array_merge($cfg, $ov), 30, 10);
        $w->useTranscriber($transcriber === 'fake' ? $fake : $transcriber);
        return $w;
    };
    $run = function ($worker) use (&$logAll) { ob_start(); try { $r = $worker->run(); } finally { $out = (string)ob_get_clean(); $logAll .= $out; } return ['r' => $r, 'log' => $out]; };
    $mkReply = function (string $canned) use ($store, $cfg): array {
        $w = new AiReplyWorker($store, $cfg, 30, 10);
        $b = new VmFakeBrain($cfg); $b->canned = $canned;
        $rp = new ReflectionProperty(AiReplyWorker::class, 'brain'); $rp->setAccessible(true); $rp->setValue($w, $b);
        return [$w, $b];
    };

    // ── 1–3. A valid voice note: the row, the message, the one event, the brain ──────────────────
    // The conversation opens with a typed greeting the assistant answers, as conversations do; a conversation's very
    // first message is stored before its identity epoch exists and is never replayed (getMessagesForAi), typed or
    // spoken alike — so the voice note is the second customer turn, where later history can show it.
    $cid1 = $conv('256772000411');
    $svc->storeMessage($cid1, ['direction' => 'in', 'role' => 'customer', 'body' => 'Hello', 'wa_message_id' => 'VM-0-TEXT']);
    $bus->emit('ai.reply', 'conversation', $cid1, ['channel' => 'sales', 'whatsapp_instance' => 'dishnet_ug', 'customer_phone' => '256772000411',
        'message' => 'Hello', 'push_name' => 'Voice Tester', 'wa_message_id' => 'VM-0-TEXT', 'remote_jid' => '256772000411@s.whatsapp.net', 'received_at' => gmdate('c')], 3, 'test');
    [$rw0] = $mkReply('Hello! How can I help you today?');
    $run($rw0);
    $texts0 = count($texts()); $rc0 = $replies();   // the greeting's own ai.reply event is already on the queue (done)
    $T1 = 'Hello, I want internet for my shop in Gulu';
    $n1 = $voiceNote('VM-1', '256772000411', 7);
    $serve('VM-1', 4096);
    $script(vm_sha(4096), ['text' => $T1, 'language' => 'en']);
    $r1 = $run($mkMedia());
    $row1 = vm_row($pdo, 'VM-1');
    $msg1 = $pdo->query("SELECT body, media_type, metadata FROM wa_messages WHERE id = {$n1['msg_id']}")->fetch(PDO::FETCH_ASSOC) ?: [];
    $ev1  = $lastReply();
    $f['t1'] = ['run' => $r1['r'], 'row' => vm_brief($row1), 'msg' => ['body' => $msg1['body'] ?? null, 'media_type' => $msg1['media_type'] ?? null,
                'meta_voice' => (json_decode((string)($msg1['metadata'] ?? ''), true) ?: [])['voice'] ?? null],
                'replies_added' => $replies() - $rc0, 'event' => ['created_by' => $ev1['created_by'] ?? null, 'status' => $ev1['status'] ?? null,
                'message' => $ev1['p']['message'] ?? null, 'origin' => $ev1['p']['origin'] ?? null, 'voice' => $ev1['p']['voice'] ?? null,
                'phone' => $ev1['p']['customer_phone'] ?? null, 'channel' => $ev1['p']['channel'] ?? null, 'instance' => $ev1['p']['whatsapp_instance'] ?? null,
                'wa_message_id' => $ev1['p']['wa_message_id'] ?? null, 'remote_jid' => $ev1['p']['remote_jid'] ?? null, 'push_name' => $ev1['p']['push_name'] ?? null,
                'received_at_ok' => UtcClock::parse((string)($ev1['p']['received_at'] ?? '')) > 0, 'location' => array_key_exists('location', $ev1['p']) ? $ev1['p']['location'] : 'absent'],
                'fake_calls' => count($fake->calls), 'fetches' => $fetches(), 'media_event' => (string)$pdo->query("SELECT status FROM events WHERE id = {$n1['event']}")->fetchColumn(),
                'log_transcribed' => preg_match('/media #\d+ \(conversation \d+\): voice note transcribed — \d+ characters, ai\.reply #\d+ queued/', $r1['log']) === 1,
                'label' => VoiceTranscription::LABEL, 'expected_message' => VoiceTranscription::LABEL . ' ' . $T1];
    // the fetch call carried the key; the fake transcriber saw only a hash prefix and a size
    $f['t1_fake_call'] = $fake->calls[0] ?? null;

    // ── 4. A duplicate event, and the understood guard called directly ────────────────────────────
    $bus->emit('ai.media', 'conversation', $n1['cid'], ['media_id' => $n1['media_id'], 'conversation_id' => $n1['cid'], 'channel' => 'sales',
        'whatsapp_instance' => 'dishnet_ug', 'wa_message_id' => 'VM-1', 'kind' => 'audio', 'has_caption' => false, 'received_at' => gmdate('c')], 3, 'test');
    $r4 = $run($mkMedia());
    $direct = (new VoiceTranscription($pdo, $store, $cfg, $fake, function () {}))->complete(vm_row($pdo, 'VM-1'), 'a second transcript', ['seconds' => 7, 'provider' => 'fake']);
    $f['t4'] = ['run' => $r4['r'], 'fake_calls' => count($fake->calls), 'fetches' => $fetches(), 'replies_added' => $replies() - $rc0, 'log_dup' => strpos($r4['log'], 'duplicate event, nothing fetched') !== false,
                'direct' => $direct['outcome'] ?? null, 'row_text' => vm_row($pdo, 'VM-1')['understanding'] ?? null];

    // ── 3. The brain: the labelled message, the voice key, the prompt block; later history ────────
    [$rw, $brain] = $mkReply('Thank you for your message! Which part of Gulu is the shop in, and roughly how many people would use the internet?');
    $r3 = $run($rw);
    $lc = $brain->lastContext ?? [];
    $prompt = (new DishNetAiBrain($cfg))->promptPreview($lc);
    $textsAfter3 = $texts();
    $f['t3'] = ['run' => $r3['r'], 'brain_calls' => $brain->calls, 'texts_before' => $texts0, 'ctx_voice' => $lc['voice'] ?? 'absent', 'ctx_message_labelled' => strpos((string)($lc['message'] ?? ''), VoiceTranscription::LABEL . ' ') === 0,
                'ctx_message_has_text' => strpos((string)($lc['message'] ?? ''), $T1) !== false, 'ctx_is_contract' => BrainContext::isContract($lc),
                'prompt_block' => strpos($prompt, 'VOICE MESSAGE JUST RECEIVED (7 s)') !== false, 'prompt_unconfirmed' => strpos($prompt, 'AUTOMATIC TRANSCRIPT') !== false && strpos($prompt, 'UNCONFIRMED') !== false,
                'prompt_without_voice_has_block' => strpos((new DishNetAiBrain($cfg))->promptPreview(array_diff_key($lc, ['voice' => 1])), 'VOICE MESSAGE JUST RECEIVED') !== false,
                'texts' => count($textsAfter3), 'reply_sent' => (string)(end($textsAfter3)['text'] ?? ''), 'reply_to' => (string)(end($textsAfter3)['number'] ?? ''),
                'stored_out' => $count("SELECT COUNT(*) FROM wa_messages WHERE conversation_id = {$n1['cid']} AND direction = 'out'")];
    $f['t3']['texts_added'] = $f['t3']['texts'] - $texts0;
    // a typed follow-up: the transcript appears in the model's history, labelled
    $bus->emit('ai.reply', 'conversation', $n1['cid'], ['channel' => 'sales', 'whatsapp_instance' => 'dishnet_ug', 'customer_phone' => '256772000411',
        'message' => 'Near the main market, about ten people', 'push_name' => 'Voice Tester', 'wa_message_id' => 'VM-1-TEXT', 'remote_jid' => '256772000411@s.whatsapp.net', 'received_at' => gmdate('c')], 3, 'test');
    $svc->storeMessage($n1['cid'], ['direction' => 'in', 'role' => 'customer', 'body' => 'Near the main market, about ten people', 'wa_message_id' => 'VM-1-TEXT']);
    [$rw2, $brain2] = $mkReply('Thank you. A Standard kit would suit ten people.');
    $run($rw2);
    $hist = (array)($brain2->lastContext['history'] ?? []);
    $f['t3_history'] = ['turns' => count($hist), 'has_labelled_transcript' => count(array_filter($hist, function ($h) use ($T1) {
                            return strpos((string)($h['text'] ?? ''), VoiceTranscription::LABEL) !== false && strpos((string)($h['text'] ?? ''), $T1) !== false; })) === 1,
                        'typed_turn_has_voice' => array_key_exists('voice', $brain2->lastContext ?? [])];

    // ── 5. A photo is not the voice path's business ───────────────────────────────────────────────
    $cid5 = $conv('256772000455');
    $env5 = vm_envelope('image', 'VM-IMG', '256772000455');
    $svc->storeMessage($cid5, ['direction' => 'in', 'role' => 'customer', 'body' => '[IMAGE]', 'media_type' => 'image', 'wa_message_id' => 'VM-IMG']);
    $idImg = InboundMedia::record($pdo, $cid5, InboundMedia::fromEvoMessage($env5['data']), 'dishnet_ug', 'sales', 'VM-IMG');
    $bus->emit('ai.media', 'conversation', $cid5, ['media_id' => $idImg, 'conversation_id' => $cid5, 'channel' => 'sales', 'whatsapp_instance' => 'dishnet_ug',
        'wa_message_id' => 'VM-IMG', 'kind' => 'image', 'has_caption' => false, 'received_at' => gmdate('c')], 3, 'test');
    $serve('VM-IMG', 3000, 'image/jpeg');
    $fc = count($fake->calls); $rc = $replies();
    $run($mkMedia());
    $f['t5'] = ['row' => vm_brief(vm_row($pdo, 'VM-IMG')), 'fake_calls_added' => count($fake->calls) - $fc, 'replies_added' => $replies() - $rc];

    // ── 6. Too long, unreadable, too large: rejected and handed to a person ──────────────────────
    $esc0 = $count("SELECT COUNT(*) FROM events WHERE event_type = 'wa.escalation'");
    $n6 = $voiceNote('VM-LONG', '256772000466', 999);
    $serve('VM-LONG', 1000);
    $fc = count($fake->calls); $rc = $replies();
    $r6 = $run($mkMedia());
    $t6 = $texts();
    $f['t6_long'] = ['run' => $r6['r'], 'row' => vm_brief(vm_row($pdo, 'VM-LONG')), 'fake_calls_added' => count($fake->calls) - $fc, 'replies_added' => $replies() - $rc,
                     'state' => $state($n6['cid']), 'escalations_added' => $count("SELECT COUNT(*) FROM events WHERE event_type = 'wa.escalation'") - $esc0,
                     'escalation_by' => (string)$pdo->query("SELECT created_by FROM events WHERE event_type = 'wa.escalation' ORDER BY id DESC LIMIT 1")->fetchColumn(),
                     'alert' => (string)(json_encode(array_values(array_filter($t6, function ($c) { return ($c['number'] ?? '') === '256700000999'; })))),
                     'holding_to_customer' => count(array_filter($t6, function ($c) use ($HOLD) { return ($c['number'] ?? '') === '256772000466' && trim((string)$c['text']) === $HOLD; })),
                     'stored_holding' => $count("SELECT COUNT(*) FROM wa_messages WHERE conversation_id = {$n6['cid']} AND direction = 'out' AND body = " . $pdo->quote($HOLD)),
                     'log' => strpos($r6['log'], 'handed to a person, nothing answered') !== false && strpos($r6['log'], 'too_long') !== false];
    $n6b = $voiceNote('VM-BAD', '256772000467', 5);
    $serve('VM-BAD', 1100);
    $script(vm_sha(1100), ['fail' => 'invalid_audio']);
    $r6b = $run($mkMedia());
    $f['t6_bad'] = ['row' => vm_brief(vm_row($pdo, 'VM-BAD')), 'state' => $state($n6b['cid']), 'replies' => $replies(),
                    'holding' => count(array_filter($texts(), function ($c) use ($HOLD) { return ($c['number'] ?? '') === '256772000467' && trim((string)$c['text']) === $HOLD; }))];
    $n6c = $voiceNote('VM-BIG', '256772000468', 5);
    $serve('VM-BIG', 70000);   // over ai_media_max_bytes (65536): the Batch 1 fetch refusal
    $fc = count($fake->calls);
    $r6c = $run($mkMedia());
    $f['t6_big'] = ['row' => vm_brief(vm_row($pdo, 'VM-BIG')), 'fake_calls_added' => count($fake->calls) - $fc, 'state' => $state($n6c['cid']),
                    'holding' => count(array_filter($texts(), function ($c) use ($HOLD) { return ($c['number'] ?? '') === '256772000468' && trim((string)$c['text']) === $HOLD; }))];

    // ── 7. A timeout is retried; the retry fetches again and succeeds ────────────────────────────
    $n7 = $voiceNote('VM-TO', '256772000477', 9);
    $serve('VM-TO', 1200);
    $script(vm_sha(1200), ['fail' => 'timeout']);
    $fc = count($fake->calls); $ff = $fetches();
    $r7 = $run($mkMedia());
    $ev7 = $pdo->query("SELECT status, attempts, error FROM events WHERE id = {$n7['event']}")->fetch(PDO::FETCH_ASSOC) ?: [];
    $f['t7_first'] = ['run' => $r7['r'], 'row' => vm_brief(vm_row($pdo, 'VM-TO')), 'event' => $ev7, 'fake_calls_added' => count($fake->calls) - $fc, 'fetches_added' => $fetches() - $ff,
                      'state' => $state($n7['cid']), 'replies' => $replies(), 'error_has_jid' => strpos((string)($ev7['error'] ?? ''), '@s.whatsapp.net') !== false];
    $pdo->exec("UPDATE events SET next_retry_at = datetime('now', '-1 second') WHERE id = {$n7['event']}");
    $script(vm_sha(1200), ['text' => 'My router light is red since this morning']);
    $fc = count($fake->calls); $ff = $fetches();
    $r7b = $run($mkMedia());
    $f['t7_retry'] = ['run' => $r7b['r'], 'row' => vm_brief(vm_row($pdo, 'VM-TO')), 'event' => $pdo->query("SELECT status, attempts FROM events WHERE id = {$n7['event']}")->fetch(PDO::FETCH_ASSOC),
                      'fake_calls_added' => count($fake->calls) - $fc, 'fetches_added' => $fetches() - $ff, 'reply_message' => $lastReply()['p']['message'] ?? null];
    $n7c = $voiceNote('VM-PE', '256772000478', 4);
    $serve('VM-PE', 1300);
    $script(vm_sha(1300), ['fail' => 'provider_error']);
    $run($mkMedia());
    $f['t7_error'] = ['row' => vm_brief(vm_row($pdo, 'VM-PE')), 'event' => $pdo->query("SELECT status, attempts FROM events WHERE id = {$n7c['event']}")->fetch(PDO::FETCH_ASSOC)];

    // ── 8. No provider, and the queue giving up: a person, never a guess ──────────────────────────
    $n8 = $voiceNote('VM-NOP', '256772000488', 6);
    $serve('VM-NOP', 1400);
    $fc = count($fake->calls); $rc = $replies();
    $r8 = $run($mkMedia([], null));   // no transcriber configured
    $f['t8_none'] = ['run' => $r8['r'], 'row' => vm_brief(vm_row($pdo, 'VM-NOP')), 'state' => $state($n8['cid']), 'replies_added' => $replies() - $rc, 'fake_calls_added' => count($fake->calls) - $fc,
                     'holding' => count(array_filter($texts(), function ($c) use ($HOLD) { return ($c['number'] ?? '') === '256772000488' && trim((string)$c['text']) === $HOLD; })),
                     'alerted' => count(array_filter($texts(), function ($c) { return ($c['number'] ?? '') === '256700000999' && strpos((string)$c['text'], 'could not be transcribed (provider_missing)') !== false; })) >= 1];
    $f['t8_factory_none'] = TranscriberFactory::fromConfig(['ai_transcription_provider' => '']) === null && TranscriberFactory::fromConfig(['ai_transcription_provider' => 'none']) === null
                           && TranscriberFactory::fromConfig(['ai_transcription_provider' => 'whisper-not-integrated']) === null;
    putenv('DN_FAKE_TRANSCRIBER_FILE');   // make sure the test environment does not leak into this check
    $f['t8_factory_fake_refused'] = TranscriberFactory::fromConfig(['ai_transcription_provider' => 'fake']) === null;
    $n8b = $voiceNote('VM-DEAD', '256772000489', 6);
    $serve('VM-DEAD', 1500);
    $script(vm_sha(1500), ['fail' => 'timeout']);
    $pdo->exec("UPDATE events SET max_attempts = 1 WHERE id = {$n8b['event']}");
    $rc = $replies();
    $r8b = $run($mkMedia());
    $f['t8_dead'] = ['run' => $r8b['r'], 'row' => vm_brief(vm_row($pdo, 'VM-DEAD')), 'event' => (string)$pdo->query("SELECT status FROM events WHERE id = {$n8b['event']}")->fetchColumn(),
                     'state' => $state($n8b['cid']), 'replies_added' => $replies() - $rc,
                     'holding' => count(array_filter($texts(), function ($c) use ($HOLD) { return ($c['number'] ?? '') === '256772000489' && trim((string)$c['text']) === $HOLD; })),
                     'log' => strpos($r8b['log'], 'given up after every attempt') !== false];

    // ── 9. STOP in the transcript ─────────────────────────────────────────────────────────────────
    $n9 = $voiceNote('VM-STOP', '256772000499', 2);
    $serve('VM-STOP', 1600);
    $script(vm_sha(1600), ['text' => 'stop']);
    $oo0 = $count('SELECT COUNT(*) FROM contact_optouts'); $rc = $replies();
    $r9 = $run($mkMedia());
    $oo = $pdo->query("SELECT phone, scope, source, evidence, active FROM contact_optouts ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
    $f['t9'] = ['optouts_added' => $count('SELECT COUNT(*) FROM contact_optouts') - $oo0, 'optout' => $oo, 'replies_added' => $replies() - $rc,
                'reply_message' => $lastReply()['p']['message'] ?? null, 'log' => strpos($r9['log'], 'opt-out recorded from the transcript (matched "stop")') !== false,
                'blocks_proactive' => (ContactOptOut::fromStore($store))->blocks('256772000499', 'sales')['blocked'] ?? null];
    [$rw9] = $mkReply('Understood — we will not message you first. You are always welcome to write to us.');
    $run($rw9);
    $n9b = $voiceNote('VM-NOSTOP', '256772000498', 12);
    $serve('VM-NOSTOP', 1700);
    $script(vm_sha(1700), ['text' => 'Please do not stop the installation, I will be at the shop tomorrow morning from nine']);
    $oo0 = $count('SELECT COUNT(*) FROM contact_optouts');
    $run($mkMedia());
    $f['t9_long'] = ['optouts_added' => $count('SELECT COUNT(*) FROM contact_optouts') - $oo0, 'row' => vm_brief(vm_row($pdo, 'VM-NOSTOP'))['status']];
    [$rw9b] = $mkReply('Noted — the installation goes ahead as planned.');
    $run($rw9b);

    // ── 10. The guard holds against a reply the transcript induced ───────────────────────────────
    $n10 = $voiceNote('VM-GUARD', '256772000410', 5);
    $serve('VM-GUARD', 1800);
    $script(vm_sha(1800), ['text' => 'Can you do a discount on the kit']);
    $run($mkMedia());
    $audit0 = count($store->load('ai_security_events.json') ?? []);
    [$rw10] = $mkReply('Between us, our cost on the Standard kit is far lower than the price, so there is room to negotiate.');
    $run($rw10);
    $audit = $store->load('ai_security_events.json') ?? [];
    $last  = is_array($audit) && $audit ? end($audit) : [];
    $t10 = array_values(array_filter($texts(), function ($c) { return ($c['number'] ?? '') === '256772000410'; }));
    $f['t10'] = ['audit_added' => count($audit) - $audit0, 'audit_conv' => (int)($last['conversation_id'] ?? -1), 'audit_modality' => $last['modality'] ?? 'absent', 'expected_conv' => $n10['cid'],
                 'sent_to_customer' => array_map(function ($c) { return trim((string)$c['text']); }, $t10),
                 'leaked' => count(array_filter($t10, function ($c) { return stripos((string)$c['text'], 'our cost') !== false; })),
                 'fallback' => count(array_filter($t10, function ($c) { return trim((string)$c['text']) === trim(\ReplyPrivacyGuard::SAFE_FALLBACK); })), 'state' => $state($n10['cid'])];

    // ── 12. ai_media_voice OFF, media ON: fetched, nothing more ──────────────────────────────────
    $n12 = $voiceNote('VM-OFF', '256772000412', 6);
    $serve('VM-OFF', 1900);
    $script(vm_sha(1900), ['text' => 'this must never be transcribed while the flag is off']);
    $fc = count($fake->calls); $rc = $replies(); $tx = count($texts());
    $r12 = $run($mkMedia(['ai_media_voice' => '0']));
    $f['t12'] = ['run' => $r12['r'], 'row' => vm_brief(vm_row($pdo, 'VM-OFF')), 'fake_calls_added' => count($fake->calls) - $fc, 'replies_added' => $replies() - $rc,
                 'texts_added' => count($texts()) - $tx, 'state' => $state($n12['cid']), 'msg_body' => (string)$pdo->query("SELECT body FROM wa_messages WHERE id = {$n12['msg_id']}")->fetchColumn()];

    // ── 13. ai_media_enabled OFF wins over ai_media_voice ────────────────────────────────────────
    $f['t13_policy'] = ['voice_alone' => MediaPolicy::voiceEnabled(['ai_media_voice' => '1']), 'both' => MediaPolicy::voiceEnabled(['ai_media_enabled' => '1', 'ai_media_voice' => '1']),
                        'media_alone' => MediaPolicy::voiceEnabled(['ai_media_enabled' => '1']), 'none' => MediaPolicy::voiceEnabled([])];
    $n13 = $voiceNote('VM-MOFF', '256772000413', 6);
    $serve('VM-MOFF', 2000);
    $script(vm_sha(2000), ['text' => 'nor this']);
    $fc = count($fake->calls); $rc = $replies(); $ff = $fetches();
    $r13 = $run($mkMedia(['ai_media_enabled' => '0', 'ai_media_voice' => '1']));
    $f['t13'] = ['run' => $r13['r'], 'row' => vm_brief(vm_row($pdo, 'VM-MOFF')), 'fake_calls_added' => count($fake->calls) - $fc, 'replies_added' => $replies() - $rc, 'fetches_added' => $fetches() - $ff];

    // ── 11. Nothing on disk, nothing in any log ──────────────────────────────────────────────────
    $b64Needle = base64_encode('DN-MEDIA-TEST-PAYLOAD');
    $f['t11'] = ['disk' => vm_scan($tmp, [$b64Needle, 'DN-MEDIA-TEST-PAYLOAD', vm_sha(4096)]),
                 'log_has_b64' => strpos($logAll, $b64Needle) !== false, 'log_has_payload' => strpos($logAll, 'DN-MEDIA-TEST') !== false,
                 'log_has_transcript' => strpos($logAll, 'Gulu') !== false || strpos($logAll, 'discount on the kit') !== false || strpos($logAll, 'router light') !== false,
                 'log_has_jid' => strpos($logAll, '@s.whatsapp.net') !== false,
                 'fake_saw_bytes' => count(array_filter($fake->calls, function ($c) { return isset($c['text']) || isset($c['bytes_raw']) || strlen(json_encode($c)) > 200; }))];
    // the transcript itself is stored only on the row and the message — never under another name in the data directory
    $f['t11_transcript_files'] = vm_scan($tmp, [$T1]);
    $f['texts_total'] = count($texts());
    exec('rm -rf ' . escapeshellarg($tmp));
    return $f;
}

// ── Scenario 2: the CLI runner in the real plugin tree, the fake provider through its test-only environment ──
function vm_cli(string $root): array
{
    $HOLD = 'Let me get a colleague to help you with that.';
    $s = SjSandbox::start($root, ['ai_enabled' => '1', 'ai_media_enabled' => '1', 'ai_media_voice' => '1', 'ai_transcription_provider' => 'fake',
        'tenant_profile' => 'uganda', 'ai_currency' => 'UGX', 'ai_provider' => 'openai', 'openai_api_key' => 'test-key-never-called',
        'ai_media_max_bytes' => 65536, 'ai_media_timeout_s' => 3, 'alert_whatsapp' => '256700000999', 'ai_handover_message' => $HOLD], 'vm');
    $pdo = $s->store()->getPdo();
    $f = [];
    $evoPort = (int)parse_url($s->evo, PHP_URL_PORT);
    $post  = function (array $env) use ($s): array { return $s->http('POST', "{$s->base}?page=evo_webhook", $env, ['Content-Type: application/json', 'X-DishNet-Token: ' . $s->evoKey]); };
    $flag  = function (array $ov) use ($s): void { $cfg = array_merge($s->cfg, $ov); $s->store()->save('kyc_config.json', $cfg); file_put_contents($s->data . '/kyc_config.json', json_encode($cfg)); };
    $count = function (string $sql) use ($pdo): int { return (int)$pdo->query($sql)->fetchColumn(); };
    $scriptFile = $s->sb . '/fake_transcriber.json';
    $callLog    = $s->sb . '/fake_transcriber.log';
    file_put_contents($scriptFile, json_encode([vm_sha(2222) => ['text' => 'Good morning, how much is the Starlink kit'], vm_sha(3333) => ['text' => 'never']]));
    $runner = function (bool $withEnv) use ($s, $scriptFile, $callLog): string {
        $env = $withEnv ? 'DN_FAKE_TRANSCRIBER_FILE=' . escapeshellarg($scriptFile) . ' DN_FAKE_TRANSCRIBER_LOG=' . escapeshellarg($callLog) . ' ' : '';
        return (string)shell_exec('cd ' . escapeshellarg($s->plug) . ' && DN_DATA_DIR=' . escapeshellarg($s->data) . ' DN_VAULT_FILE=' . escapeshellarg($s->vault) . ' ' . $env . 'php run_media_worker.php 2>/dev/null');
    };
    $serve = function (string $waId, int $bytes) use ($s): void { $s->http('POST', "{$s->evo}/__test/media", ['for_id' => $waId, 'bytes' => $bytes, 'mimetype' => 'audio/ogg; codecs=opus']); };

    // C1 — both flags on, the fake provider through its environment: the runner transcribes and queues the one event
    $r = $post(vm_envelope('audio', 'VM-W-1', '256772000511', ['seconds' => 7]));
    $serve('VM-W-1', 2222);
    $out1 = $runner(true);
    $row = vm_row($pdo, 'VM-W-1');
    $ev  = $pdo->query("SELECT payload, created_by FROM events WHERE event_type = 'ai.reply' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
    $p   = json_decode((string)($ev['payload'] ?? ''), true) ?: [];
    $log = (string)@file_get_contents($s->data . '/ai_platform.log');
    $f['c1'] = ['webhook' => ['http' => $r[0], 'media_queued' => $r[2]['media_queued'] ?? null], 'out' => trim($out1), 'row' => vm_brief($row),
                'replies' => $count("SELECT COUNT(*) FROM events WHERE event_type = 'ai.reply'"), 'event_by' => $ev['created_by'] ?? null,
                'message' => $p['message'] ?? null, 'origin' => $p['origin'] ?? null, 'voice_seconds' => $p['voice']['seconds'] ?? null, 'phone' => $p['customer_phone'] ?? null,
                'msg_body' => (string)$pdo->query("SELECT body FROM wa_messages WHERE wa_message_id = 'VM-W-1'")->fetchColumn(),
                'fake_log_lines' => count(array_filter(explode("\n", (string)@file_get_contents($callLog)))), 'fake_log' => trim((string)@file_get_contents($callLog)),
                'log_has_transcript' => strpos($log, 'Starlink kit') !== false, 'log_has_b64' => strpos($log, base64_encode('DN-MEDIA-TEST-PAYLOAD')) !== false,
                'log_transcribed' => strpos($log, 'voice note transcribed') !== false, 'texts' => count($s->texts())];
    // C2 — voice OFF: fetched only
    $flag(['ai_media_voice' => '0']);
    $r = $post(vm_envelope('audio', 'VM-W-2', '256772000512', ['seconds' => 5]));
    $serve('VM-W-2', 3333);
    $runner(true);
    $f['c2'] = ['media_queued' => $r[2]['media_queued'] ?? null, 'row' => vm_brief(vm_row($pdo, 'VM-W-2')), 'replies' => $count("SELECT COUNT(*) FROM events WHERE event_type = 'ai.reply'"),
                'fake_log_lines' => count(array_filter(explode("\n", (string)@file_get_contents($callLog)))), 'texts' => count($s->texts())];
    // C3 — media OFF, voice ON: the webhook records nothing (Batch 1), so there is nothing to transcribe
    $flag(['ai_media_enabled' => '0', 'ai_media_voice' => '1']);
    $r = $post(vm_envelope('audio', 'VM-W-3', '256772000513', ['seconds' => 5]));
    $runner(true);
    $f['c3'] = ['media_queued' => $r[2]['media_queued'] ?? null, 'row' => vm_row($pdo, 'VM-W-3'), 'msg_body' => (string)$pdo->query("SELECT body FROM wa_messages WHERE wa_message_id = 'VM-W-3'")->fetchColumn(),
                'replies' => $count("SELECT COUNT(*) FROM events WHERE event_type = 'ai.reply'"), 'fake_log_lines' => count(array_filter(explode("\n", (string)@file_get_contents($callLog))))];
    // C4 — both on, provider "fake" but no environment: fails closed → provider_missing → a person, the holding line
    $flag(['ai_media_enabled' => '1', 'ai_media_voice' => '1']);
    $r = $post(vm_envelope('audio', 'VM-W-4', '256772000514', ['seconds' => 5]));
    $serve('VM-W-4', 2222);
    $runner(false);
    $t4 = $s->texts();
    $f['c4'] = ['media_queued' => $r[2]['media_queued'] ?? null, 'row' => vm_brief(vm_row($pdo, 'VM-W-4')), 'replies' => $count("SELECT COUNT(*) FROM events WHERE event_type = 'ai.reply'"),
                'state' => (string)$pdo->query("SELECT state FROM wa_conversations WHERE phone = '256772000514'")->fetchColumn(),
                'holding' => count(array_filter($t4, function ($c) use ($HOLD) { return ($c['number'] ?? '') === '256772000514' && trim((string)$c['text']) === $HOLD; })),
                'alert' => count(array_filter($t4, function ($c) { return ($c['number'] ?? '') === '256700000999' && strpos((string)$c['text'], 'provider_missing') !== false; })),
                'fake_log_lines' => count(array_filter(explode("\n", (string)@file_get_contents($callLog))))];
    $f['disk'] = vm_scan($s->sb, [base64_encode('DN-MEDIA-TEST-PAYLOAD'), 'DN-MEDIA-TEST-PAYLOAD']);
    $f['cs_jobs'] = (function () use ($s): array { [$rc, $out] = $s->run('tools/cron_status.php', ['--all']); return ['rc' => $rc, 'ai_media' => strpos($out, 'ai_media') !== false]; })();
    $s->stop();
    return $f;
}

// ── Driver mode: one JSON line ───────────────────────────────────────────────────────────────────
if ($driver !== null) {
    if ($driver === 'cli') {
        $facts = vm_cli($root);
    } else {
        $evo = (int)getenv('DN_T_EVO_PORT'); $ucrm = (int)getenv('DN_T_UCRM_PORT');
        $srvE = $srvU = null;
        if ($evo <= 0)  { [$srvE, $evo]  = vm_boot($root . '/tests/fixtures/fake_evo_server.php', 9880, 'FAKE-EVO-TEST'); }
        if ($ucrm <= 0) { [$srvU, $ucrm] = vm_boot($root . '/tests/fixtures/fake_ucrm_server.php', 9940, 'FAKE-UCRM-TEST'); }
        $facts = vm_core($root, $evo, $ucrm);
        foreach ([$srvE, $srvU] as $p) if (is_resource($p)) { proc_terminate($p); proc_close($p); }
    }
    echo json_encode($facts), "\n";
    exit(0);
}

// ── Main ─────────────────────────────────────────────────────────────────────────────────────────
$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d !== '' ? "\n       " . substr($d, 0, 1000) : '') . "\n"; } }
function j($v): string { return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }

array_map('unlink', glob(sys_get_temp_dir() . '/fake_evo_state_*.json') ?: []);
array_map('unlink', glob(sys_get_temp_dir() . '/fake_ucrm_*.json') ?: []);
[$evoSrv, $evoPort]   = vm_boot($root . '/tests/fixtures/fake_evo_server.php', 9875, 'FAKE-EVO-TEST');
[$ucrmSrv, $ucrmPort] = vm_boot($root . '/tests/fixtures/fake_ucrm_server.php', 9935, 'FAKE-UCRM-TEST');
if (!$evoPort || !$ucrmPort) { echo "FAIL could not start the fake servers\n0 passed, 1 failed\n"; exit(1); }

$c = vm_core($root, $evoPort, $ucrmPort);
$L = VoiceTranscription::LABEL;

echo "1. A valid voice note → row → fetch → transcript\n";
$t1 = $c['t1'];
is_(($t1['run']['processed'] ?? 0) === 1 && ($t1['run']['failed'] ?? -1) === 0 && $t1['media_event'] === 'done', 'the media event is processed and done', j($t1['run']));
is_($t1['row']['status'] === 'understood' && $t1['row']['kind'] === 'transcript' && $t1['row']['text'] === 'Hello, I want internet for my shop in Gulu' && $t1['row']['bytes'] === 4096 && $t1['row']['attempts'] === 1,
    'the row: understood, the transcript, kind transcript, one attempt', j($t1['row']));
is_($t1['msg']['body'] === $t1['expected_message'] && $t1['msg']['media_type'] === 'audio' && (int)($t1['msg']['meta_voice']['media_id'] ?? 0) > 0 && ($t1['msg']['meta_voice']['seconds'] ?? 0) === 7,
    'the stored [AUDIO] message now says what was said, labelled, with the voice metadata', j($t1['msg']));
is_($t1['fetches'] === 1 && $t1['fake_calls'] === 1, 'one fetch, one transcription', j([$t1['fetches'], $t1['fake_calls']]));
is_(is_array($c['t1_fake_call']) && !isset($c['t1_fake_call']['text']) && strlen((string)($c['t1_fake_call']['sha'] ?? '')) === 12 && ($c['t1_fake_call']['bytes'] ?? 0) === 4096 && ($c['t1_fake_call']['timeout'] ?? 0) === 5 && ($c['t1_fake_call']['seconds'] ?? 0) === 7,
    'the provider was given the bytes, the announced duration and the configured time budget; it recorded a hash prefix and a size', j($c['t1_fake_call']));
is_($t1['log_transcribed'], 'the log says a voice note was transcribed, in characters, with the event number');

echo "\n2. The transcript enters the existing brain exactly once\n";
$ev = $t1['event'];
is_($t1['replies_added'] === 1 && $ev['created_by'] === 'media_worker' && $ev['status'] === 'pending', 'exactly one ai.reply event for the voice note, queued by the media worker', j([$t1['replies_added'], $ev['created_by'], $ev['status']]));
is_($ev['phone'] === '256772000411' && $ev['channel'] === 'sales' && $ev['instance'] === 'dishnet_ug' && $ev['wa_message_id'] === 'VM-1' && $ev['remote_jid'] === '256772000411@s.whatsapp.net' && $ev['push_name'] === 'Voice Tester' && $ev['received_at_ok'] && $ev['location'] === null,
    'the event has the shape the webhook gives a typed message', j($ev));
is_(($c['t3']['brain_calls'] ?? 0) === 1 && ($c['t3']['run']['processed'] ?? 0) === 1, 'the reply worker processed it with one brain call', j([$c['t3']['brain_calls'], $c['t3']['run']]));

echo "\n3. Clearly marked as voice-originated\n";
is_($ev['message'] === $L . ' Hello, I want internet for my shop in Gulu' && $ev['origin'] === 'voice' && ($ev['voice']['seconds'] ?? null) === 7 && (int)($ev['voice']['media_id'] ?? 0) > 0 && ($ev['voice']['chars'] ?? 0) === 42,
    'the message is labelled, origin voice, with the duration, the row id and the length', j([$ev['message'], $ev['origin'], $ev['voice']]));
$t3 = $c['t3'];
is_($t3['ctx_is_contract'] && $t3['ctx_voice'] === ['seconds' => 7] && $t3['ctx_message_labelled'] && $t3['ctx_message_has_text'], 'the sales contract carries voice = {seconds: 7} and the labelled message', j([$t3['ctx_is_contract'], $t3['ctx_voice'], $t3['ctx_message_labelled']]));
is_($t3['prompt_block'] && $t3['prompt_unconfirmed'] && $t3['prompt_without_voice_has_block'] === false, 'the prompt carries the VOICE MESSAGE block (7 s), and not for a typed turn', j([$t3['prompt_block'], $t3['prompt_unconfirmed'], $t3['prompt_without_voice_has_block']]));
is_($t3['texts_added'] === 1 && $t3['reply_to'] === '256772000411' && strpos($t3['reply_sent'], 'Which part of Gulu') !== false && $t3['stored_out'] === 2, 'the brain\'s reply went to the customer once and was stored (after the greeting\'s)', j([$t3['texts_added'], $t3['reply_to'], $t3['reply_sent'], $t3['stored_out']]));
is_($c['t3_history']['has_labelled_transcript'] && $c['t3_history']['typed_turn_has_voice'] === false, 'a later typed turn sees the labelled transcript in history, and carries no voice key itself', j($c['t3_history']));

echo "\n4. A duplicate event does not transcribe twice\n";
$t4 = $c['t4'];
is_(($t4['run']['processed'] ?? 0) === 1 && $t4['fake_calls'] === 1 && $t4['fetches'] === 1 && $t4['replies_added'] === 1 && $t4['log_dup'], 'the duplicate is acknowledged: no second fetch, no second transcription, no second event', j($t4));
is_($t4['direct'] === 'already_understood' && $t4['row_text'] === 'Hello, I want internet for my shop in Gulu', 'the understood guard holds even when completion is called directly', j([$t4['direct'], $t4['row_text']]));

echo "\n5. Non-audio media is ignored by the voice path\n";
is_($c['t5']['row']['status'] === 'fetched' && $c['t5']['fake_calls_added'] === 0 && $c['t5']['replies_added'] === 0, 'a photo is fetched (Batch 1) and never transcribed or answered', j($c['t5']));

echo "\n6. Oversized or invalid audio is rejected — and a person takes over\n";
$t6 = $c['t6_long'];
is_($t6['row']['status'] === 'failed' && $t6['row']['reason'] === 'too_long' && $t6['fake_calls_added'] === 0 && $t6['replies_added'] === 0, 'a 999 s note: too_long before any provider call, no ai.reply', j($t6['row']));
is_($t6['state'] === 'needs_human' && $t6['escalations_added'] === 1 && $t6['escalation_by'] === 'media_worker' && $t6['holding_to_customer'] === 1 && $t6['stored_holding'] === 1 && strpos($t6['alert'], 'could not be transcribed (too_long)') !== false,
    'handed over: needs_human, one wa.escalation by the media worker, the staff alert, the holding line once (stored)', j($t6));
is_($t6['log'], 'the log says handed to a person, nothing answered, with the reason');
is_($c['t6_bad']['row']['status'] === 'failed' && $c['t6_bad']['row']['reason'] === 'invalid_audio' && $c['t6_bad']['state'] === 'needs_human' && $c['t6_bad']['holding'] === 1, 'unreadable audio: invalid_audio, handed over', j($c['t6_bad']));
is_($c['t6_big']['row']['status'] === 'failed' && $c['t6_big']['row']['reason'] === 'too_large' && $c['t6_big']['fake_calls_added'] === 0 && $c['t6_big']['state'] === 'needs_human' && $c['t6_big']['holding'] === 1, 'too large to fetch (Batch 1): no provider call, handed over', j($c['t6_big']));

echo "\n7. A provider timeout or error is retried; the retry fetches again and succeeds\n";
$t7 = $c['t7_first'];
is_($t7['row']['status'] === 'failed' && $t7['row']['reason'] === 'timeout' && ($t7['event']['status'] ?? '') === 'failed' && (int)($t7['event']['attempts'] ?? 0) === 1 && $t7['fake_calls_added'] === 1 && $t7['fetches_added'] === 1 && $t7['error_has_jid'] === false,
    'a timeout: row failed/timeout, the event failed with one attempt, its error names no JID', j($t7));
is_($t7['state'] !== 'needs_human' && ($t7['run']['failed'] ?? 0) === 1, 'not handed over yet — it will be retried', j([$t7['state'], $t7['run']]));
$t7b = $c['t7_retry'];
is_($t7b['row']['status'] === 'understood' && $t7b['row']['attempts'] === 2 && ($t7b['event']['status'] ?? '') === 'done' && $t7b['fetches_added'] === 1 && $t7b['fake_calls_added'] === 1 && $t7b['reply_message'] === $L . ' My router light is red since this morning',
    'the due retry fetches again, transcribes, and queues the one event', j($t7b));
is_($c['t7_error']['row']['status'] === 'failed' && $c['t7_error']['row']['reason'] === 'provider_error' && ($c['t7_error']['event']['status'] ?? '') === 'failed', 'a provider error is a retryable failure too', j($c['t7_error']));

echo "\n8. Provider failure → safe hand-over, never a guess\n";
$t8 = $c['t8_none'];
is_($t8['row']['status'] === 'failed' && $t8['row']['reason'] === 'provider_missing' && $t8['fake_calls_added'] === 0 && $t8['replies_added'] === 0, 'no provider configured: provider_missing, no call, no ai.reply', j($t8['row']));
is_($t8['state'] === 'needs_human' && $t8['holding'] === 1 && $t8['alerted'] === true, 'the customer gets the operator\'s holding line, a person is alerted', j($t8));
is_($c['t8_factory_none'] === true && $c['t8_factory_fake_refused'] === true, 'the factory yields no provider for none, an unknown name, or "fake" without its test environment');
$t8b = $c['t8_dead'];
is_($t8b['row']['status'] === 'dead' && $t8b['event'] === 'dead' && $t8b['state'] === 'needs_human' && $t8b['holding'] === 1 && $t8b['replies_added'] === 0 && $t8b['log'], 'the queue giving up: dead, handed over with the holding line, no ai.reply', j($t8b));

echo "\n9. STOP in the transcript\n";
$t9 = $c['t9'];
is_($t9['optouts_added'] === 1 && ($t9['optout']['phone'] ?? '') === '256772000499' && ($t9['optout']['source'] ?? '') === 'voice_keyword' && ($t9['optout']['evidence'] ?? '') === 'stop' && ($t9['optout']['scope'] ?? '') === 'proactive' && (int)($t9['optout']['active'] ?? 0) === 1,
    'a spoken "stop" records the opt-out: proactive scope, source voice_keyword, the transcript as evidence', j($t9['optout']));
is_($t9['blocks_proactive'] === true && $t9['replies_added'] === 1 && $t9['reply_message'] === $L . ' stop' && $t9['log'], 'proactive messages are now blocked, and the STOP is still acknowledged through the AI', j($t9));
is_($c['t9_long']['optouts_added'] === 0 && $c['t9_long']['row'] === 'understood', 'a sentence that merely contains "stop" is not an opt-out (the webhook\'s rule)', j($c['t9_long']));

echo "\n10. The transcript cannot bypass ReplyPrivacyGuard\n";
$t10 = $c['t10'];
is_($t10['audit_added'] === 1 && $t10['audit_conv'] === $t10['expected_conv'] && $t10['audit_modality'] === 'voice', 'the blocked reply is audited against the real conversation, modality voice', j($t10));
is_($t10['leaked'] === 0 && $t10['fallback'] === 1 && $t10['state'] === 'needs_human', 'the customer got the safe fallback and never the blocked text; a person takes over', j($t10['sent_to_customer']));

echo "\n11. Nothing on disk, nothing in any log\n";
$t11 = $c['t11'];
is_($t11['disk'] === [] && $c['t11_transcript_files'] === [], 'no file under the data directory holds the audio, its base64, its hash or the transcript', j([$t11['disk'], $c['t11_transcript_files']]));
is_($t11['log_has_b64'] === false && $t11['log_has_payload'] === false && $t11['log_has_transcript'] === false && $t11['log_has_jid'] === false && $t11['fake_saw_bytes'] === 0,
    'the worker logs carry no base64, no bytes, no transcript, no JID; the provider recorded hash prefixes only', j($t11));

echo "\n12. ai_media_voice OFF: fetched, nothing more\n";
$t12 = $c['t12'];
is_($t12['row']['status'] === 'fetched' && $t12['fake_calls_added'] === 0 && $t12['replies_added'] === 0 && $t12['texts_added'] === 0 && $t12['state'] !== 'needs_human' && $t12['msg_body'] === '[AUDIO]',
    'with the voice flag off a voice note is fetched (Batch 1) and nothing else happens: no transcription, no event, no message, no hand-over', j($t12));

echo "\n13. ai_media_enabled OFF wins\n";
is_($c['t13_policy'] === ['voice_alone' => false, 'both' => true, 'media_alone' => false, 'none' => false], 'voiceEnabled() needs both flags', j($c['t13_policy']));
is_($c['t13']['row']['status'] === 'skipped' && $c['t13']['row']['reason'] === 'media_disabled' && $c['t13']['fake_calls_added'] === 0 && $c['t13']['replies_added'] === 0 && $c['t13']['fetches_added'] === 0,
    'media off, voice on: skipped, nothing fetched, nothing transcribed, nothing queued', j($c['t13']));

echo "\nC. The CLI runner in the real plugin tree\n";
$w = vm_cli($root);
$c1 = $w['c1'];
is_(($c1['webhook']['media_queued'] ?? 0) === 1 && $c1['out'] === '' && $c1['row']['status'] === 'understood' && $c1['row']['text'] === 'Good morning, how much is the Starlink kit',
    'the webhook records the voice note; run_media_worker.php fetches and transcribes it through the fake provider', j([$c1['webhook'], $c1['out'], $c1['row']]));
is_($c1['replies'] === 1 && $c1['event_by'] === 'media_worker' && $c1['message'] === $L . ' Good morning, how much is the Starlink kit' && $c1['origin'] === 'voice' && $c1['voice_seconds'] === 7 && $c1['phone'] === '256772000511' && $c1['msg_body'] === $c1['message'],
    'one labelled ai.reply event, origin voice; the stored message says the same', j($c1));
is_($c1['fake_log_lines'] === 1 && strpos($c1['fake_log'], '"sha":"') !== false && strpos($c1['fake_log'], 'Starlink') === false && $c1['log_has_transcript'] === false && $c1['log_has_b64'] === false && $c1['log_transcribed'] && $c1['texts'] === 0,
    'the provider log holds one call with a hash prefix; ai_platform.log holds no transcript and no base64; nothing was sent', j([$c1['fake_log'], $c1['log_has_transcript'], $c1['texts']]));
is_($w['c2']['row']['status'] === 'fetched' && $w['c2']['replies'] === 1 && $w['c2']['fake_log_lines'] === 1 && $w['c2']['texts'] === 0, 'voice OFF: fetched only, no new event, no provider call', j($w['c2']));
is_(($w['c3']['media_queued'] ?? -1) === 0 && $w['c3']['row'] === [] && $w['c3']['msg_body'] === '[AUDIO]' && $w['c3']['replies'] === 1 && $w['c3']['fake_log_lines'] === 1, 'media OFF with voice ON: nothing recorded, nothing to transcribe — media off wins', j($w['c3']));
$c4 = $w['c4'];
is_($c4['row']['status'] === 'failed' && $c4['row']['reason'] === 'provider_missing' && $c4['replies'] === 1 && $c4['state'] === 'needs_human' && $c4['holding'] === 1 && $c4['alert'] === 1 && $c4['fake_log_lines'] === 1,
    'provider "fake" without its test environment fails closed: provider_missing, a person, the holding line — no event', j($c4));
is_($w['disk'] === [], 'no file under the sandbox holds the audio or its base64', j($w['disk']));
is_(($w['cs_jobs']['rc'] ?? 1) === 0 && $w['cs_jobs']['ai_media'] === true, 'cron_status lists the ai_media job while the media flag is on (unchanged from Batch 1)', j($w['cs_jobs']));

echo "\nD. Wiring\n";
$mw = (string)file_get_contents($root . '/workers/MediaWorker.php'); $aw = (string)file_get_contents($root . '/workers/AiReplyWorker.php');
is_(strpos($aw, '\Handover::escalate($this->pdo, $this->bus, $this->store, (array)$this->config, $this->evo, $this->convSvc,') !== false && strpos($mw, "function (string \$level, string \$message): void { \$this->log(\$level, \$message); }, 'media_worker');") !== false,
    'both workers hand over through lib/Handover.php — one path');
is_(strpos($aw, 'private function alreadySaid(') === false && strpos((string)file_get_contents($root . '/lib/Handover.php'), 'public static function alreadySaid(') !== false, 'the holding-line dedupe moved with it');
$mwCode = vm_codeOf($root . '/workers/MediaWorker.php'); $vtCode = vm_codeOf($root . '/lib/VoiceTranscription.php');
is_(strpos($mwCode, 'DishNetAiBrain') === false && strpos($mwCode, 'ReplyPrivacyGuard') === false && strpos($vtCode, 'DishNetAiBrain') === false && strpos($vtCode, 'ReplyPrivacyGuard') === false,
    'neither the media worker nor the voice service knows the brain or the guard: the transcript joins the ai.reply queue and nothing else');
is_(strpos($mwCode, 'ai_media_voice') === false && strpos($mwCode, 'MediaPolicy::voiceEnabled($this->config)') !== false, 'the worker reads the voice flag through the policy only');
is_(is_file($root . '/docs/../../docs/56-voice-transcription-provider-boundary-2026-10-05.md') || is_file(dirname($root) . '/docs/56-voice-transcription-provider-boundary-2026-10-05.md'), 'the provider boundary is documented (docs/56) before any provider exists');
is_(count(glob($root . '/migrations/08[6-9]_*.sql') ?: []) === 0, 'no migration: wa_media already carries understanding and understanding_kind');
$sc = (string)file_get_contents($root . '/tools/set_config.php');
is_(strpos($sc, "'ai_media_voice' => ['bool',") !== false && strpos($sc, "'ai_media_voice_max_seconds' => ['number',") !== false && strpos($sc, "'ai_transcription_provider' => ['text',") !== false, 'set_config.php manages the voice settings');
$scOut = shell_exec('php ' . escapeshellarg($root . '/tools/set_config.php') . ' --key ai_transcription_provider --value fake 2>&1; echo "rc=$?"');
is_(strpos((string)$scOut, 'none is the only value today') !== false && strpos((string)$scOut, 'rc=1') !== false, 'the tool refuses a provider that does not exist, the fake included', (string)$scOut);
is_(json_decode((string)file_get_contents($root . '/manifest.json'), true)['information']['version'] === '5.18.79', 'manifest version is 5.18.79');

echo "\nE. Weakened copies — each caught by the scenario that guards it\n";
$mutants = [
    ['core', 'lib/MediaPolicy.php', "        return self::enabled(\$config) && self::flag(\$config['ai_media_voice'] ?? null);", "        return self::flag(\$config['ai_media_voice'] ?? null);",
     'ai_media_voice alone turns voice on (media off no longer wins)', function (array $m): bool { return ($m['t13_policy']['voice_alone'] ?? null) === true; }],
    ['core', 'workers/MediaWorker.php', "        return (string)(\$row['kind'] ?? '') === 'audio' && MediaPolicy::voiceEnabled(\$this->config);", "        return (string)(\$row['kind'] ?? '') === 'audio';",
     'the worker ignores the voice flag', function (array $m): bool { return ($m['t12']['fake_calls_added'] ?? 0) >= 1 || ($m['t12']['replies_added'] ?? 0) >= 1; }],
    ['core', 'workers/MediaWorker.php', "    public const SETTLED = ['fetched', 'understood', 'unsupported', 'skipped', 'dead'];", "    public const SETTLED = ['fetched', 'unsupported', 'skipped', 'dead'];",
     'an understood note is not settled (a duplicate transcribes again)', function (array $m): bool { return ($m['t4']['fake_calls'] ?? 0) >= 2; }],
    ['core', 'lib/VoiceTranscription.php', "            \$stop = ContactOptOut::detect(\$transcript);", "            \$stop = ['stop' => false, 'matched' => ''];",
     'STOP is no longer detected on the transcript', function (array $m): bool { return ($m['t9']['optouts_added'] ?? -1) === 0; }],
    ['core', 'lib/VoiceTranscription.php', "        return self::LABEL . ' ' . \$transcript;", "        return \$transcript;",
     'the transcript is no longer labelled', function (array $m): bool { return ($m['t3']['ctx_message_labelled'] ?? true) === false; }],
    ['core', 'workers/MediaWorker.php', "        \$this->handover(\$row, 'a voice message could not be transcribed (' . \$reason . ') — listen to it in WhatsApp');", "        // handover removed",
     'a permanent failure no longer hands over', function (array $m): bool { return ($m['t8_none']['state'] ?? '') !== 'needs_human'; }],
    ['core', 'lib/VoiceTranscription.php', "            // The one ai.reply event: the shape evo_webhook.php queues for a typed message, plus where it came from.", "            (\$this->log)('info', 'transcript: ' . \$transcript);",
     'the transcript is logged', function (array $m): bool { return ($m['t11']['log_has_transcript'] ?? false) === true; }],
    ['core', 'lib/DishNetAiBrain.php', "        \$voice = \$ctx['voice'] ?? null;\n        if (is_array(\$voice)) {", "        \$voice = \$ctx['voice'] ?? null;\n        if (false) {",
     'the prompt no longer says the message was spoken', function (array $m): bool { return ($m['t3']['prompt_block'] ?? true) === false; }],
];
foreach ($mutants as [$scn, $rel, $old, $new, $what, $flipped]) {
    [$copy, $n] = sj_weakened_copy($root, $rel, $old, $new);
    is_($n === 1, "copy — the anchor in {$rel} is unique, so the copy is weakened ({$what})", 'occurrences: ' . $n);
    $out = (string)shell_exec('DN_T_EVO_PORT=' . (int)$evoPort . ' DN_T_UCRM_PORT=' . (int)$ucrmPort . ' php ' . escapeshellarg($copy . '/tests/test_voice_media.php') . ' --driver=' . $scn . ' 2>/dev/null');
    $m = json_decode((string)strrchr("\n" . trim($out), "\n"), true) ?: [];
    is_($n === 1 && $m !== [] && $flipped($m), "caught — {$what}", $m === [] ? 'driver output: ' . substr($out, 0, 400) : j(array_intersect_key($m, ['t13_policy' => 1, 't12' => 1, 't4' => 1, 't9' => 1, 't3' => 1, 't8_none' => 1, 't11' => 1])));
    exec('rm -rf ' . escapeshellarg($copy));
}
$out = (string)shell_exec('DN_T_EVO_PORT=' . (int)$evoPort . ' DN_T_UCRM_PORT=' . (int)$ucrmPort . ' php ' . escapeshellarg($root . '/tests/test_voice_media.php') . ' --driver=core 2>/dev/null');
$m = json_decode((string)strrchr("\n" . trim($out), "\n"), true) ?: [];
$flips = 0; foreach ($mutants as [$scn, $rel, $old, $new, $what, $flipped]) { if ($m !== [] && $flipped($m)) $flips++; }
is_($m !== [] && $flips === 0, 'control: the real tree, driven the same way, trips none of the eight catches', j(['facts' => count($m), 'flips' => $flips]));

foreach ([$evoSrv, $ucrmSrv] as $p) if (is_resource($p)) { proc_terminate($p); proc_close($p); }
array_map('unlink', glob(sys_get_temp_dir() . '/fake_evo_state_*.json') ?: []);
array_map('unlink', glob(sys_get_temp_dir() . '/fake_ucrm_*.json') ?: []);
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
