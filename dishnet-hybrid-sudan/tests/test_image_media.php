<?php
declare(strict_types=1);
/**
 * test_image_media.php — Batch 3 of the AI communication layer (docs/55 §9, docs/57): IMAGES, dark.
 *
 * A customer's picture, fetched by the Batch 1 media worker, becomes the customer's turn through the EXISTING assistant
 * — or, when it looks like proof of a payment, becomes evidence for a person and nothing else. Only while
 * ai_media_enabled AND ai_media_image are on, and they are on nowhere. The eighteen areas the instruction names:
 *
 *   1  valid image → media row → fetch → understanding            10  STOP / opt-out behaviour intact
 *   2  the description enters the existing brain exactly once      11  ReplyPrivacyGuard intact
 *   3  image modality preserved (event, context, prompt, audit)    12  payment screenshot → evidence + escalation only
 *   4  duplicate event idempotent                                   13  no financial write from image understanding
 *   5  unsupported MIME rejected (announced, and behind a label)   14  no image bytes / base64 persisted
 *   6  oversized image rejected (announced, and by the header)     15  no sensitive image content logged
 *   7  malformed image rejected                                     16  ai_media_image OFF → zero provider calls, zero replies
 *   8  provider timeout / failure retried                          17  ai_media_enabled OFF overrides
 *   9  safe human hand-over on permanent failure                   18  weakened copies of the safety invariants caught
 *
 * Two scenarios return FACTS (no asserting inside): `core`, in-process against the fake Evolution and the fake uCRM with
 * the deterministic FakeImageDescriber injected and a fake brain (the real marker parser) on the reply worker; `cli`,
 * the real plugin under php -S (SjSandbox) with run_media_worker.php over the CLI and the fake provider selected through
 * its test-only environment. The pictures are real PNG headers built here, so the header checks run on genuine PNG
 * structure. Driver mode: php tests/test_image_media.php --driver=core|cli (env DN_T_EVO_PORT, DN_T_UCRM_PORT).
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
require_once $root . '/lib/ImageDescriber.php';
require_once $root . '/lib/PaymentEvidence.php';
require_once $root . '/lib/ImageUnderstanding.php';
require_once $root . '/lib/Handover.php';
require_once $root . '/workers/WorkerBase.php';
require_once $root . '/workers/MediaWorker.php';
require_once $root . '/workers/AiReplyWorker.php';
require_once $root . '/tests/fixtures/staff_jobs_sandbox.php';   // SjSandbox, sj_weakened_copy()

/** A brain that never leaves the process, but parses its canned answer with the REAL marker parser (Batch 0's). */
class ViFakeBrain extends DishNetAiBrain
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
function vi_hit(int $port, string $path, $json = null, int $timeout = 40): string
{
    $ch = curl_init("http://127.0.0.1:{$port}{$path}");
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*'];
    if ($json !== null) { $o[CURLOPT_POST] = true; $o[CURLOPT_POSTFIELDS] = json_encode($json); $o[CURLOPT_HTTPHEADER] = ['Content-Type: application/json']; }
    curl_setopt_array($ch, $o);
    $r = curl_exec($ch); curl_close($ch);
    return $r === false ? '' : (string)$r;
}
function vi_state(int $port): array { return json_decode(vi_hit($port, '/__test/state'), true) ?: []; }
function vi_boot(string $router, int $base, string $sig): array
{
    foreach (range(0, 11) as $slot) {
        $cand = $base + ((getmypid() + $slot * 13) % 70);
        $p = proc_open(sprintf('exec php -S 127.0.0.1:%d %s', $cand, escapeshellarg($router)), [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 40; $i++) {
            $r = vi_hit($cand, '/__test/state', null, 3);
            if ($r !== '') { if (strpos($r, $sig) !== false) return [$p, $cand]; break; }
            usleep(100000);
        }
        if (is_resource($p)) { proc_terminate($p); proc_close($p); }
    }
    return [null, 0];
}
/** A genuine PNG: signature, an IHDR chunk declaring the dimensions (with its CRC), IEND. Different sizes → different bytes. */
function vi_png(int $w, int $h): string
{
    $ihdr = 'IHDR' . pack('NN', $w, $h) . "\x08\x06\x00\x00\x00";
    return "\x89PNG\r\n\x1a\n" . pack('N', 13) . $ihdr . pack('N', crc32($ihdr)) . pack('N', 0) . 'IEND' . pack('N', crc32('IEND'));
}
/** A GIF header: an image, but not one of the allowed types. */
function vi_gif(int $w, int $h): string { return 'GIF89a' . pack('vv', $w, $h) . "\x00\x00\x00" . str_repeat("\x00", 16); }
function vi_sha(string $bytes): string { return hash('sha256', $bytes); }
/** An Evolution messages.upsert envelope: a photo (optionally captioned), a voice note, or text. */
function vi_envelope(string $kind, string $id, string $phone, array $o = []): array
{
    $jid = $phone . '@s.whatsapp.net';
    $cap = (string)($o['caption'] ?? '');
    if ($kind === 'image') {
        $message = ['imageMessage' => ['url' => 'https://mmg.whatsapp.net/v/t62.7118-24/y.enc', 'mimetype' => $o['mimetype'] ?? 'image/png',
                    'fileLength' => (string)($o['bytes'] ?? 20480), 'height' => 480, 'width' => 640] + ($cap !== '' ? ['caption' => $cap] : [])];
    } elseif ($kind === 'audio') {
        $message = ['audioMessage' => ['mimetype' => 'audio/ogg; codecs=opus', 'fileLength' => '12345', 'seconds' => 7, 'ptt' => true]];
    } else {
        $message = ['conversation' => (string)($o['text'] ?? 'Hello')];
    }
    return ['event' => 'messages.upsert', 'instance' => $o['instance'] ?? 'sj-sales', 'data' => [
        'key' => ['id' => $id, 'fromMe' => false, 'remoteJid' => $jid],
        'pushName' => 'Photo Tester', 'messageType' => array_keys($message)[0], 'messageTimestamp' => time(), 'message' => $message]];
}
/** Files under $dir (the database itself excluded) holding any of the needles. */
function vi_scan(string $dir, array $needles): array
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
/** A file's PHP code with every comment removed — the comments may name what the code must not depend on. */
function vi_codeOf(string $file): string
{
    $out = '';
    foreach (token_get_all((string)file_get_contents($file)) as $k) {
        if (is_array($k)) { if (in_array($k[0], [T_COMMENT, T_DOC_COMMENT], true)) continue; $out .= $k[1]; }
        else $out .= $k;
    }
    return $out;
}
function vi_row(PDO $pdo, string $waId): array
{
    $st = $pdo->prepare('SELECT * FROM wa_media WHERE wa_message_id = ?'); $st->execute([$waId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($r) ? $r : [];
}
function vi_brief(array $r): array
{
    return ['status' => $r['status'] ?? null, 'reason' => $r['failure_reason'] ?? null, 'attempts' => (int)($r['attempts'] ?? 0),
            'kind' => $r['understanding_kind'] ?? null, 'text' => $r['understanding'] ?? null, 'bytes' => isset($r['fetched_bytes']) ? (int)$r['fetched_bytes'] : null];
}
/** Every table that holds money, and how many rows each has — the thing a payment screenshot must never change. */
function vi_money(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND (name LIKE '%pay%' OR name LIKE '%invoice%' OR name LIKE '%cash%' OR name LIKE '%ledger%' OR name LIKE '%money%' OR name LIKE '%receipt%' OR name LIKE '%wallet%' OR name LIKE '%transaction%') ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $out[$t] = (int)$pdo->query("SELECT COUNT(*) FROM \"{$t}\"")->fetchColumn();
    }
    return $out;
}

// ── Scenario 1: core, in-process ─────────────────────────────────────────────────────────────────
function vi_core(string $root, int $evoPort, int $ucrmPort): array
{
    $tmp = sys_get_temp_dir() . '/dn_vi_' . bin2hex(random_bytes(4));
    @mkdir($tmp, 0700, true);
    putenv('DN_DATA_DIR=' . $tmp);
    vi_hit($evoPort, '/__test/reset');
    vi_hit($ucrmPort, '/__test/reset');
    vi_hit($ucrmPort, '/__test/scenario?name=fresh_install');
    $store = SqliteStore::create($tmp);
    $pdo   = $store->getPdo();
    $svc   = new ConversationService($tmp, $pdo);
    $bus   = new EventBus($pdo);
    $HOLD  = 'Let me get a colleague to help you with that.';
    $cfg = ['evo_api_url' => "http://127.0.0.1:{$evoPort}", 'evo_api_key' => 'TESTKEY', 'evo_instance_sales' => 'dishnet_ug',
            'evo_instance_support' => 'dishnet_ug', 'ai_enabled' => '1', 'ai_media_enabled' => '1', 'ai_media_image' => '1',
            'ai_media_max_bytes' => 65536, 'ai_media_timeout_s' => 3, 'ai_media_image_timeout_s' => 7,
            'ai_provider' => 'openai', 'openai_api_key' => 'test-key-never-called',
            'crm_base_url' => "http://127.0.0.1:{$ucrmPort}", 'crm_auth_token' => 'test-key',
            'alert_whatsapp' => '256700000999', 'ai_handover_message' => $HOLD, 'wa_human_cooldown_minutes' => 30, 'data_dir' => $tmp];
    $f = ['tmp' => $tmp, 'hold' => $HOLD];
    $logAll = '';
    $texts   = function () use ($evoPort): array { return vi_state($evoPort)['text_calls'] ?? []; };
    $fetches = function () use ($evoPort): int { return count(vi_state($evoPort)['media_fetch_calls'] ?? []); };
    $serve = function (string $waId, string $bytes, string $mime = 'image/png') use ($evoPort): string {
        vi_hit($evoPort, '/__test/media', ['for_id' => $waId, 'base64' => base64_encode($bytes), 'mimetype' => $mime]);
        return vi_sha($bytes);
    };
    $count = function (string $sql) use ($pdo): int { return (int)$pdo->query($sql)->fetchColumn(); };
    $replies = function () use ($count): int { return $count("SELECT COUNT(*) FROM events WHERE event_type = 'ai.reply'"); };
    $lastReply = function () use ($pdo): array {
        $r = $pdo->query("SELECT id, payload, created_by, status FROM events WHERE event_type = 'ai.reply' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
        $r['p'] = json_decode((string)($r['payload'] ?? ''), true) ?: [];
        return $r;
    };
    $conv  = function (string $phone) use ($svc): int { return (int)$svc->ensureConversation($phone, 'sales', 'Photo Tester', 'test')['id']; };
    $state = function (int $cid) use ($svc): string { return (string)($svc->getConversation($cid)['state'] ?? ''); };
    /** A picture as the webhook records it: the stored message (caption or placeholder), the wa_media row, the ai.media event. */
    $photo = function (string $waId, string $phone, string $caption = '', array $o = []) use ($svc, $pdo, $bus, $conv): array {
        $cid = $conv($phone);
        $env = vi_envelope('image', $waId, $phone, ['caption' => $caption] + $o);
        $msgId = $svc->storeMessage($cid, ['direction' => 'in', 'role' => 'customer', 'body' => $caption !== '' ? $caption : '[IMAGE]', 'media_type' => 'image', 'wa_message_id' => $waId]);
        $mediaId = InboundMedia::record($pdo, $cid, InboundMedia::fromEvoMessage($env['data']), 'dishnet_ug', 'sales', $waId);
        $evt = $bus->emit('ai.media', 'conversation', $cid, ['media_id' => $mediaId, 'conversation_id' => $cid, 'channel' => 'sales',
            'whatsapp_instance' => 'dishnet_ug', 'wa_message_id' => $waId, 'kind' => 'image', 'has_caption' => $caption !== '', 'received_at' => gmdate('c')], 3, 'test');
        return ['cid' => $cid, 'media_id' => (int)$mediaId, 'msg_id' => (int)$msgId, 'event' => $evt];
    };
    $fake = new FakeImageDescriber([]);
    $script = function (string $sha, array $entry) use (&$fake): void { $rp = new ReflectionProperty(FakeImageDescriber::class, 'script'); $rp->setAccessible(true); $s = $rp->getValue($fake); $s[$sha] = $entry; $rp->setValue($fake, $s); };
    $mkMedia = function (array $ov = [], $describer = 'fake') use ($store, $cfg, &$fake): MediaWorker {
        $w = new MediaWorker($store, array_merge($cfg, $ov), 30, 10);
        $w->useImageDescriber($describer === 'fake' ? $fake : $describer);
        return $w;
    };
    $run = function ($worker) use (&$logAll) { ob_start(); try { $r = $worker->run(); } finally { $out = (string)ob_get_clean(); $logAll .= $out; } return ['r' => $r, 'log' => $out]; };
    $mkReply = function (string $canned) use ($store, $cfg): array {
        $w = new AiReplyWorker($store, $cfg, 30, 10);
        $b = new ViFakeBrain($cfg); $b->canned = $canned;
        $rp = new ReflectionProperty(AiReplyWorker::class, 'brain'); $rp->setAccessible(true); $rp->setValue($w, $b);
        return [$w, $b];
    };
    $holdingTo = function (string $phone) use ($texts, $HOLD): int { return count(array_filter($texts(), function ($c) use ($phone, $HOLD) { return ($c['number'] ?? '') === $phone && trim((string)$c['text']) === $HOLD; })); };
    $alerts = function () use ($texts): array { return array_values(array_map(function ($c) { return (string)$c['text']; }, array_filter($texts(), function ($c) { return ($c['number'] ?? '') === '256700000999'; }))); };
    $L = ImageUnderstanding::LABEL; $LP = ImageUnderstanding::LABEL_PAYMENT; $LC = ImageUnderstanding::LABEL_CAPTION;

    // ── 1–3. A valid picture: the row, the message, the one event, the brain ─────────────────────
    // The conversation opens with a typed greeting (a conversation's first message is never replayed, so the picture
    // is the second customer turn, where later history can show it).
    $cid1 = $conv('256772000611');
    $svc->storeMessage($cid1, ['direction' => 'in', 'role' => 'customer', 'body' => 'Hello', 'wa_message_id' => 'VI-0-TEXT']);
    $bus->emit('ai.reply', 'conversation', $cid1, ['channel' => 'sales', 'whatsapp_instance' => 'dishnet_ug', 'customer_phone' => '256772000611',
        'message' => 'Hello', 'push_name' => 'Photo Tester', 'wa_message_id' => 'VI-0-TEXT', 'remote_jid' => '256772000611@s.whatsapp.net', 'received_at' => gmdate('c')], 3, 'test');
    [$rw0] = $mkReply('Hello! How can I help you today?');
    $run($rw0);
    $texts0 = count($texts()); $rc0 = $replies();
    $D1 = 'A tin-roofed single-storey shop with a clear view of the sky and a wooden pole beside it';
    $n1 = $photo('VI-1', '256772000611');
    $sha1 = $serve('VI-1', vi_png(640, 480));
    $script($sha1, ['description' => $D1, 'classification' => 'site_photo', 'signals' => ['roof', 'pole', 'open sky']]);
    $r1 = $run($mkMedia());
    $row1 = vi_row($pdo, 'VI-1');
    $msg1 = $pdo->query("SELECT body, media_type, metadata FROM wa_messages WHERE id = {$n1['msg_id']}")->fetch(PDO::FETCH_ASSOC) ?: [];
    $ev1  = $lastReply();
    $f['t1'] = ['run' => $r1['r'], 'row' => vi_brief($row1), 'msg' => ['body' => $msg1['body'] ?? null, 'media_type' => $msg1['media_type'] ?? null,
                'meta_image' => (json_decode((string)($msg1['metadata'] ?? ''), true) ?: [])['image'] ?? null],
                'replies_added' => $replies() - $rc0, 'event' => ['created_by' => $ev1['created_by'] ?? null, 'status' => $ev1['status'] ?? null,
                'message' => $ev1['p']['message'] ?? null, 'origin' => $ev1['p']['origin'] ?? null, 'image' => $ev1['p']['image'] ?? null,
                'phone' => $ev1['p']['customer_phone'] ?? null, 'channel' => $ev1['p']['channel'] ?? null, 'wa_message_id' => $ev1['p']['wa_message_id'] ?? null,
                'received_at_ok' => UtcClock::parse((string)($ev1['p']['received_at'] ?? '')) > 0, 'has_voice' => array_key_exists('voice', $ev1['p'])],
                'fake_calls' => count($fake->calls), 'fetches' => $fetches(), 'media_event' => (string)$pdo->query("SELECT status FROM events WHERE id = {$n1['event']}")->fetchColumn(),
                'log_described' => preg_match('/media #\d+ \(conversation \d+\): picture described \(site_photo\) — \d+ characters, ai\.reply #\d+ queued/', $r1['log']) === 1,
                'expected_message' => $L . ' ' . $D1];
    $f['t1_fake_call'] = $fake->calls[0] ?? null;

    // ── 4. A duplicate event, and the understood guard called directly ────────────────────────────
    $bus->emit('ai.media', 'conversation', $n1['cid'], ['media_id' => $n1['media_id'], 'conversation_id' => $n1['cid'], 'channel' => 'sales',
        'whatsapp_instance' => 'dishnet_ug', 'wa_message_id' => 'VI-1', 'kind' => 'image', 'has_caption' => false, 'received_at' => gmdate('c')], 3, 'test');
    $r4 = $run($mkMedia());
    $direct = (new ImageUnderstanding($pdo, $store, $cfg, $fake, function () {}))->complete(vi_row($pdo, 'VI-1'), 'a second description', ['classification' => 'general', 'payment' => false]);
    $f['t4'] = ['run' => $r4['r'], 'fake_calls' => count($fake->calls), 'fetches' => $fetches(), 'replies_added' => $replies() - $rc0, 'log_dup' => strpos($r4['log'], 'duplicate event, nothing fetched') !== false,
                'direct' => $direct['outcome'] ?? null, 'row_text' => vi_row($pdo, 'VI-1')['understanding'] ?? null];

    // ── 3. The brain: labelled message, image key, prompt block; later history ───────────────────
    [$rw, $brain] = $mkReply('Thank you for the photo! A roof like that with open sky is a good spot for the dish. Which town is the shop in?');
    $r3 = $run($rw);
    $lc = $brain->lastContext ?? [];
    $prompt = (new DishNetAiBrain($cfg))->promptPreview($lc);
    $t3texts = $texts();
    $f['t3'] = ['run' => $r3['r'], 'brain_calls' => $brain->calls, 'ctx_image' => $lc['image'] ?? 'absent', 'ctx_voice' => $lc['voice'] ?? 'absent',
                'ctx_message_labelled' => strpos((string)($lc['message'] ?? ''), $L . ' ') === 0, 'ctx_is_contract' => BrainContext::isContract($lc),
                'prompt_block' => strpos($prompt, 'IMAGE JUST RECEIVED (classified as site_photo)') !== false,
                'prompt_rules' => strpos($prompt, 'AUTOMATIC DESCRIPTION') !== false && strpos($prompt, 'UNCONFIRMED') !== false && strpos($prompt, 'do NOT confirm, accept or promise anything about payment') !== false,
                'prompt_without_image_has_block' => strpos((new DishNetAiBrain($cfg))->promptPreview(array_diff_key($lc, ['image' => 1])), 'IMAGE JUST RECEIVED') !== false,
                'texts_added' => count($t3texts) - $texts0, 'reply_to' => (string)(end($t3texts)['number'] ?? ''), 'reply_sent' => (string)(end($t3texts)['text'] ?? ''),
                'stored_out' => $count("SELECT COUNT(*) FROM wa_messages WHERE conversation_id = {$n1['cid']} AND direction = 'out'")];
    $bus->emit('ai.reply', 'conversation', $n1['cid'], ['channel' => 'sales', 'whatsapp_instance' => 'dishnet_ug', 'customer_phone' => '256772000611',
        'message' => 'It is in Lira town', 'push_name' => 'Photo Tester', 'wa_message_id' => 'VI-1-TEXT', 'remote_jid' => '256772000611@s.whatsapp.net', 'received_at' => gmdate('c')], 3, 'test');
    $svc->storeMessage($n1['cid'], ['direction' => 'in', 'role' => 'customer', 'body' => 'It is in Lira town', 'wa_message_id' => 'VI-1-TEXT']);
    [$rw2, $brain2] = $mkReply('Thank you. A colleague will confirm coverage for Lira.');
    $run($rw2);
    $hist = (array)($brain2->lastContext['history'] ?? []);
    $f['t3_history'] = ['turns' => count($hist), 'has_labelled_description' => count(array_filter($hist, function ($h) use ($L, $D1) {
                            return strpos((string)($h['text'] ?? ''), $L) !== false && strpos((string)($h['text'] ?? ''), substr($D1, 0, 40)) !== false; })) === 1,
                        'typed_turn_has_image' => array_key_exists('image', $brain2->lastContext ?? [])];

    // ── A captioned picture: the turn carries the description AND the customer's words, labelled apart ──
    $CAP = 'Here is my roof, can you install here?';
    $D2  = 'A corrugated iron roof seen from the ground, with a mango tree partly over it';
    $n2  = $photo('VI-CAP', '256772000622', $CAP);
    $sha2 = $serve('VI-CAP', vi_png(800, 600));
    $script($sha2, ['description' => $D2, 'classification' => 'site_photo', 'signals' => ['roof', 'tree']]);
    $run($mkMedia());
    $ev2 = $lastReply();
    $f['t_caption'] = ['message' => $ev2['p']['message'] ?? null, 'expected' => $L . ' ' . $D2 . "\n" . $LC . ' ' . $CAP, 'has_caption' => $ev2['p']['image']['has_caption'] ?? null, 'caption_len' => mb_strlen($CAP),
                       'hint_caption_chars' => (int)(end($fake->calls)['caption_chars'] ?? -1), 'msg_body' => (string)$pdo->query("SELECT body FROM wa_messages WHERE id = {$n2['msg_id']}")->fetchColumn()];
    [$rwc] = $mkReply('Yes, that roof looks suitable. We can arrange a site survey.');
    $run($rwc);

    // ── 5. Unsupported types: announced, and hidden behind an allowed label ──────────────────────
    $esc0 = $count("SELECT COUNT(*) FROM events WHERE event_type = 'wa.escalation'");
    $n5 = $photo('VI-SVG', '256772000655', '', ['mimetype' => 'image/svg+xml']);
    $serve('VI-SVG', vi_png(10, 10), 'image/svg+xml');
    $fc = count($fake->calls); $rc = $replies();
    $r5 = $run($mkMedia());
    $f['t5_svg'] = ['row' => vi_brief(vi_row($pdo, 'VI-SVG')), 'fake_calls_added' => count($fake->calls) - $fc, 'replies_added' => $replies() - $rc, 'state' => $state($n5['cid']), 'holding' => $holdingTo('256772000655')];
    $n5b = $photo('VI-GIF', '256772000656');
    $serve('VI-GIF', vi_gif(300, 200), 'image/png');   // Evolution says PNG; the bytes are a GIF
    $fc = count($fake->calls); $rc = $replies();
    $run($mkMedia());
    $f['t5_gif'] = ['row' => vi_brief(vi_row($pdo, 'VI-GIF')), 'fake_calls_added' => count($fake->calls) - $fc, 'replies_added' => $replies() - $rc, 'state' => $state($n5b['cid']), 'holding' => $holdingTo('256772000656')];

    // ── 6. Oversized: announced, and by the header ───────────────────────────────────────────────
    $n6 = $photo('VI-BIG', '256772000666', '', ['bytes' => 20000000]);
    $serve('VI-BIG', vi_png(10, 10));
    $fc = count($fake->calls); $ff = $fetches();
    $run($mkMedia());
    $f['t6_announced'] = ['row' => vi_brief(vi_row($pdo, 'VI-BIG')), 'fake_calls_added' => count($fake->calls) - $fc, 'fetches_added' => $fetches() - $ff, 'state' => $state($n6['cid']), 'holding' => $holdingTo('256772000666')];
    $n6b = $photo('VI-HUGE', '256772000667');
    $serve('VI-HUGE', vi_png(9000, 7000));   // a small file whose header declares 63 megapixels
    $fc = count($fake->calls); $rc = $replies();
    $run($mkMedia());
    $f['t6_header'] = ['row' => vi_brief(vi_row($pdo, 'VI-HUGE')), 'fake_calls_added' => count($fake->calls) - $fc, 'replies_added' => $replies() - $rc, 'state' => $state($n6b['cid']), 'holding' => $holdingTo('256772000667')];

    // ── 7. Malformed: not an image at all behind an image label ─────────────────────────────────
    $n7 = $photo('VI-JUNK', '256772000677');
    $serve('VI-JUNK', str_repeat('DN-MEDIA-TEST-PAYLOAD/', 100));
    $fc = count($fake->calls); $rc = $replies();
    $r7 = $run($mkMedia());
    $f['t7'] = ['row' => vi_brief(vi_row($pdo, 'VI-JUNK')), 'fake_calls_added' => count($fake->calls) - $fc, 'replies_added' => $replies() - $rc, 'state' => $state($n7['cid']), 'holding' => $holdingTo('256772000677'),
                'escalations_added' => $count("SELECT COUNT(*) FROM events WHERE event_type = 'wa.escalation'") - $esc0,
                'log' => strpos($r7['log'], 'handed to a person, nothing answered') !== false && strpos($r7['log'], 'malformed_image') !== false];

    // ── 8. A timeout is retried; the retry fetches again and succeeds; a provider error likewise ─
    $n8 = $photo('VI-TO', '256772000688');
    $sha8 = $serve('VI-TO', vi_png(320, 240));
    $script($sha8, ['fail' => 'timeout']);
    $fc = count($fake->calls); $ff = $fetches();
    $r8 = $run($mkMedia());
    $ev8 = $pdo->query("SELECT status, attempts, error FROM events WHERE id = {$n8['event']}")->fetch(PDO::FETCH_ASSOC) ?: [];
    $f['t8_first'] = ['run' => $r8['r'], 'row' => vi_brief(vi_row($pdo, 'VI-TO')), 'event' => $ev8, 'fake_calls_added' => count($fake->calls) - $fc, 'fetches_added' => $fetches() - $ff,
                      'state' => $state($n8['cid']), 'error_has_jid' => strpos((string)($ev8['error'] ?? ''), '@s.whatsapp.net') !== false];
    $pdo->exec("UPDATE events SET next_retry_at = datetime('now', '-1 second') WHERE id = {$n8['event']}");
    $script($sha8, ['description' => 'A router with a steady white light and one blinking orange light', 'classification' => 'equipment_photo', 'signals' => ['router', 'lights']]);
    $fc = count($fake->calls); $ff = $fetches(); $rc = $replies();
    $r8b = $run($mkMedia());
    $f['t8_retry'] = ['run' => $r8b['r'], 'row' => vi_brief(vi_row($pdo, 'VI-TO')), 'event' => $pdo->query("SELECT status, attempts FROM events WHERE id = {$n8['event']}")->fetch(PDO::FETCH_ASSOC),
                      'fake_calls_added' => count($fake->calls) - $fc, 'fetches_added' => $fetches() - $ff, 'replies_added' => $replies() - $rc, 'classification' => $lastReply()['p']['image']['classification'] ?? null];
    [$rw8] = $mkReply('An orange blinking light usually means the dish is still searching for the satellite.');
    $run($rw8);
    $n8c = $photo('VI-PE', '256772000689');
    $sha8c = $serve('VI-PE', vi_png(321, 240));
    $script($sha8c, ['fail' => 'provider_error']);
    $run($mkMedia());
    $f['t8_error'] = ['row' => vi_brief(vi_row($pdo, 'VI-PE')), 'event' => $pdo->query("SELECT status, attempts FROM events WHERE id = {$n8c['event']}")->fetch(PDO::FETCH_ASSOC)];

    // ── 9. No provider, and the queue giving up: a person, never a guess ─────────────────────────
    $n9 = $photo('VI-NOP', '256772000699');
    $serve('VI-NOP', vi_png(100, 100));
    $fc = count($fake->calls); $rc = $replies();
    $r9 = $run($mkMedia([], null));
    $f['t9_none'] = ['row' => vi_brief(vi_row($pdo, 'VI-NOP')), 'state' => $state($n9['cid']), 'replies_added' => $replies() - $rc, 'fake_calls_added' => count($fake->calls) - $fc,
                     'holding' => $holdingTo('256772000699'), 'alerted' => count(array_filter($alerts(), function ($t) { return strpos($t, 'could not be understood (provider_missing)') !== false; })) >= 1];
    putenv('DN_FAKE_IMAGE_FILE');
    $f['t9_factory'] = ImageDescriberFactory::fromConfig([]) === null && ImageDescriberFactory::fromConfig(['ai_image_provider' => 'none']) === null
                     && ImageDescriberFactory::fromConfig(['ai_image_provider' => 'gpt-vision-not-integrated']) === null && ImageDescriberFactory::fromConfig(['ai_image_provider' => 'fake']) === null;
    $n9b = $photo('VI-DEAD', '256772000698');
    $sha9b = $serve('VI-DEAD', vi_png(101, 100));
    $script($sha9b, ['fail' => 'timeout']);
    $pdo->exec("UPDATE events SET max_attempts = 1 WHERE id = {$n9b['event']}");
    $rc = $replies();
    $r9b = $run($mkMedia());
    $f['t9_dead'] = ['run' => $r9b['r'], 'row' => vi_brief(vi_row($pdo, 'VI-DEAD')), 'event' => (string)$pdo->query("SELECT status FROM events WHERE id = {$n9b['event']}")->fetchColumn(),
                     'state' => $state($n9b['cid']), 'replies_added' => $replies() - $rc, 'holding' => $holdingTo('256772000698'), 'log' => strpos($r9b['log'], 'photo given up after every attempt') !== false];

    // ── 12 / 13. A payment screenshot: evidence and a person — no AI turn, no financial write ───
    $moneyBefore = vi_money($pdo);
    $payBefore = (int)((json_decode(vi_hit($ucrmPort, '/__test/payments'), true) ?: [])['count'] ?? -1);
    $DP = 'MTN Mobile Money confirmation screen: UGX 150,000 sent to DishNet Africa, Transaction ID 7G4K2Q9, balance UGX 23,400';
    $n12 = $photo('VI-PAY', '256772000612', 'I have paid, see attached');
    $sha12 = $serve('VI-PAY', vi_png(1080, 1920));
    $script($sha12, ['description' => $DP, 'classification' => 'payment_proof', 'signals' => ['amount', 'transaction_id', 'momo']]);
    $esc0 = $count("SELECT COUNT(*) FROM events WHERE event_type = 'wa.escalation'"); $rc = $replies(); $alerts0 = count($alerts());
    $r12 = $run($mkMedia());
    $row12 = vi_row($pdo, 'VI-PAY');
    $esc = $pdo->query("SELECT payload, created_by FROM events WHERE event_type = 'wa.escalation' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
    $escP = json_decode((string)($esc['payload'] ?? ''), true) ?: [];
    $newAlerts = array_slice($alerts(), $alerts0);
    $f['t12'] = ['run' => $r12['r'], 'row' => vi_brief($row12), 'replies_added' => $replies() - $rc, 'state' => $state($n12['cid']),
                 'escalations_added' => $count("SELECT COUNT(*) FROM events WHERE event_type = 'wa.escalation'") - $esc0, 'escalation_by' => $esc['created_by'] ?? null,
                 'escalation_reason' => (string)($escP['reason'] ?? ''), 'alerts' => $newAlerts, 'holding' => $holdingTo('256772000612'),
                 'msg_body' => (string)$pdo->query("SELECT body FROM wa_messages WHERE id = {$n12['msg_id']}")->fetchColumn(),
                 'expected_body' => $LP . ' ' . $DP . "\n" . $LC . ' I have paid, see attached',
                 'meta' => (json_decode((string)$pdo->query("SELECT metadata FROM wa_messages WHERE id = {$n12['msg_id']}")->fetchColumn(), true) ?: [])['image'] ?? null,
                 'log' => strpos($r12['log'], 'payment evidence (payment_proof) — no AI turn; a person verifies') !== false,
                 'log_has_amount' => strpos($r12['log'], '150,000') !== false || strpos($r12['log'], '7G4K2Q9') !== false,
                 'alert_has_amount' => count(array_filter($newAlerts, function ($t) { return strpos($t, '150,000') !== false || strpos($t, '7G4K2Q9') !== false; })) > 0,
                 'alert_says_nothing_recorded' => count(array_filter($newAlerts, function ($t) { return strpos($t, 'nothing was recorded or marked paid') !== false; })) === 1];
    // by words alone: the provider called it a screenshot, the words say payment
    $DW = 'A phone screenshot of a bank app showing a transfer of UGX 420,000 marked successful, reference ABX991';
    $n12b = $photo('VI-PAYW', '256772000613');
    $sha12b = $serve('VI-PAYW', vi_png(1080, 1921));
    $script($sha12b, ['description' => $DW, 'classification' => 'screenshot', 'signals' => []]);
    $rc = $replies();
    $run($mkMedia());
    $f['t12_words'] = ['row' => vi_brief(vi_row($pdo, 'VI-PAYW')), 'replies_added' => $replies() - $rc, 'state' => $state($n12b['cid']), 'holding' => $holdingTo('256772000613')];
    // and a screenshot that is NOT a payment goes to the assistant
    $DS = 'A phone screenshot of a speed test showing 45 Mbps download and 12 Mbps upload';
    $n12c = $photo('VI-SPEED', '256772000614');
    $sha12c = $serve('VI-SPEED', vi_png(1080, 1922));
    $script($sha12c, ['description' => $DS, 'classification' => 'screenshot', 'signals' => ['speed test']]);
    $rc = $replies();
    $run($mkMedia());
    $f['t12_speed'] = ['row' => vi_brief(vi_row($pdo, 'VI-SPEED')), 'replies_added' => $replies() - $rc, 'state' => $state($n12c['cid'])];
    [$rws] = $mkReply('Those speeds look healthy for a Standard plan.');
    $run($rws);
    $f['t13'] = ['money_before' => $moneyBefore, 'money_after' => vi_money($pdo), 'ucrm_payments_before' => $payBefore,
                 'ucrm_payments_after' => (int)((json_decode(vi_hit($ucrmPort, '/__test/payments'), true) ?: [])['count'] ?? -1)];
    $f['t13_detector'] = ['class' => PaymentEvidence::looksLikePayment('payment_proof', 'anything', []),
                          'words' => PaymentEvidence::looksLikePayment('screenshot', $DW, []),
                          'speedtest' => PaymentEvidence::looksLikePayment('screenshot', $DS, []),
                          'shop_sign' => PaymentEvidence::looksLikePayment('site_photo', 'A shop front with an MTN Mobile Money sign and a red door', []),
                          'price_list' => PaymentEvidence::looksLikePayment('document_photo', 'A printed price list: Standard kit UGX 1,690,000', []),
                          'money_and_txn' => PaymentEvidence::looksLikePayment('general', 'Receipt total 85,000 paid in cash', []),
                          'signals_only' => PaymentEvidence::looksLikePayment('general', 'a slip', ['amount', 'transaction'])];

    // ── 10. STOP is the webhook's, read off the caption — a description is not the customer's words ──
    $oo0 = $count('SELECT COUNT(*) FROM contact_optouts');
    $n10 = $photo('VI-STOPDESC', '256772000610');
    $sha10 = $serve('VI-STOPDESC', vi_png(50, 50));
    $script($sha10, ['description' => 'stop', 'classification' => 'general', 'signals' => []]);
    $run($mkMedia());
    $f['t10'] = ['optouts_added' => $count('SELECT COUNT(*) FROM contact_optouts') - $oo0, 'row' => vi_brief(vi_row($pdo, 'VI-STOPDESC'))['status']];
    [$rw10] = $mkReply('Thank you for the picture.');
    $run($rw10);

    // ── 11. The guard holds against a reply the description induced ──────────────────────────────
    $n11 = $photo('VI-GUARD', '256772000615');
    $sha11 = $serve('VI-GUARD', vi_png(51, 50));
    $script($sha11, ['description' => 'A handwritten note asking for a discount on the kit', 'classification' => 'document_photo', 'signals' => []]);
    $run($mkMedia());
    $audit0 = count($store->load('ai_security_events.json') ?? []);
    [$rw11] = $mkReply('Between us, our cost on the Standard kit is far lower than the price, so there is room to negotiate.');
    $run($rw11);
    $audit = $store->load('ai_security_events.json') ?? [];
    $last  = is_array($audit) && $audit ? end($audit) : [];
    $t11 = array_values(array_filter($texts(), function ($c) { return ($c['number'] ?? '') === '256772000615'; }));
    $f['t11'] = ['audit_added' => count($audit) - $audit0, 'audit_conv' => (int)($last['conversation_id'] ?? -1), 'audit_modality' => $last['modality'] ?? 'absent', 'expected_conv' => $n11['cid'],
                 'leaked' => count(array_filter($t11, function ($c) { return stripos((string)$c['text'], 'our cost') !== false; })),
                 'fallback' => count(array_filter($t11, function ($c) { return trim((string)$c['text']) === trim(\ReplyPrivacyGuard::SAFE_FALLBACK); })), 'state' => $state($n11['cid'])];

    // ── 16. ai_media_image OFF, media ON: fetched, nothing more ─────────────────────────────────
    $n16 = $photo('VI-OFF', '256772000616', 'look at this');
    $sha16 = $serve('VI-OFF', vi_png(60, 60));
    $script($sha16, ['description' => 'this must never be described while the flag is off', 'classification' => 'general', 'signals' => []]);
    $fc = count($fake->calls); $rc = $replies(); $tx = count($texts());
    $r16 = $run($mkMedia(['ai_media_image' => '0']));
    $f['t16'] = ['run' => $r16['r'], 'row' => vi_brief(vi_row($pdo, 'VI-OFF')), 'fake_calls_added' => count($fake->calls) - $fc, 'replies_added' => $replies() - $rc,
                 'texts_added' => count($texts()) - $tx, 'state' => $state($n16['cid']), 'msg_body' => (string)$pdo->query("SELECT body FROM wa_messages WHERE id = {$n16['msg_id']}")->fetchColumn()];

    // ── 17. ai_media_enabled OFF wins over ai_media_image ───────────────────────────────────────
    $f['t17_policy'] = ['image_alone' => MediaPolicy::imageEnabled(['ai_media_image' => '1']), 'both' => MediaPolicy::imageEnabled(['ai_media_enabled' => '1', 'ai_media_image' => '1']),
                        'media_alone' => MediaPolicy::imageEnabled(['ai_media_enabled' => '1']), 'none' => MediaPolicy::imageEnabled([])];
    $n17 = $photo('VI-MOFF', '256772000617');
    $serve('VI-MOFF', vi_png(61, 60));
    $fc = count($fake->calls); $rc = $replies(); $ff = $fetches();
    $r17 = $run($mkMedia(['ai_media_enabled' => '0', 'ai_media_image' => '1']));
    $f['t17'] = ['run' => $r17['r'], 'row' => vi_brief(vi_row($pdo, 'VI-MOFF')), 'fake_calls_added' => count($fake->calls) - $fc, 'replies_added' => $replies() - $rc, 'fetches_added' => $fetches() - $ff];

    // ── 14 / 15. Nothing on disk, nothing in any log ─────────────────────────────────────────────
    $png1b64 = base64_encode(vi_png(640, 480));
    $f['t14'] = ['disk' => vi_scan($tmp, [$png1b64, $sha1, substr($D1, 0, 40), substr($DP, 0, 40), '7G4K2Q9']),
                 'png_bytes_on_disk' => vi_scan($tmp, [vi_png(640, 480)])];
    $f['t15'] = ['log_has_b64' => strpos($logAll, $png1b64) !== false, 'log_has_description' => strpos($logAll, substr($D1, 0, 40)) !== false || strpos($logAll, 'Lira') !== false,
                 'log_has_payment_details' => strpos($logAll, '150,000') !== false || strpos($logAll, '7G4K2Q9') !== false || strpos($logAll, 'ABX991') !== false,
                 'log_has_jid' => strpos($logAll, '@s.whatsapp.net') !== false,
                 'fake_saw_content' => count(array_filter($fake->calls, function ($c) { return isset($c['description']) || isset($c['bytes_raw']) || strlen(json_encode($c)) > 200; }))];
    $f['texts_total'] = count($texts());
    exec('rm -rf ' . escapeshellarg($tmp));
    return $f;
}

// ── Scenario 2: the CLI runner in the real plugin tree ───────────────────────────────────────────
function vi_cli(string $root): array
{
    $HOLD = 'Let me get a colleague to help you with that.';
    $s = SjSandbox::start($root, ['ai_enabled' => '1', 'ai_media_enabled' => '1', 'ai_media_image' => '1', 'ai_image_provider' => 'fake',
        'tenant_profile' => 'uganda', 'ai_currency' => 'UGX', 'ai_provider' => 'openai', 'openai_api_key' => 'test-key-never-called',
        'ai_media_max_bytes' => 65536, 'ai_media_timeout_s' => 3, 'alert_whatsapp' => '256700000999', 'ai_handover_message' => $HOLD], 'vi');
    $pdo = $s->store()->getPdo();
    $f = [];
    $L = ImageUnderstanding::LABEL; $LC = ImageUnderstanding::LABEL_CAPTION; $LP = ImageUnderstanding::LABEL_PAYMENT;
    $post  = function (array $env) use ($s): array { return $s->http('POST', "{$s->base}?page=evo_webhook", $env, ['Content-Type: application/json', 'X-DishNet-Token: ' . $s->evoKey]); };
    $flag  = function (array $ov) use ($s): void { $cfg = array_merge($s->cfg, $ov); $s->store()->save('kyc_config.json', $cfg); file_put_contents($s->data . '/kyc_config.json', json_encode($cfg)); };
    $count = function (string $sql) use ($pdo): int { return (int)$pdo->query($sql)->fetchColumn(); };
    $scriptFile = $s->sb . '/fake_image.json';
    $callLog    = $s->sb . '/fake_image.log';
    $pngA = vi_png(640, 481); $pngB = vi_png(640, 482); $pngP = vi_png(1080, 1923); $pngS = vi_png(64, 64);
    $DP = 'Airtel Money receipt: UGX 95,000 paid to DishNet, transaction 55QZ81';
    file_put_contents($scriptFile, json_encode([
        vi_sha($pngA) => ['description' => 'A roof with a clear view of the sky', 'classification' => 'site_photo', 'signals' => ['roof']],
        vi_sha($pngB) => ['description' => 'never', 'classification' => 'general', 'signals' => []],
        vi_sha($pngP) => ['description' => $DP, 'classification' => 'payment_proof', 'signals' => ['amount']],
        vi_sha($pngS) => ['description' => 'A blank wall', 'classification' => 'general', 'signals' => []],
    ]));
    $runner = function (bool $withEnv) use ($s, $scriptFile, $callLog): string {
        $env = $withEnv ? 'DN_FAKE_IMAGE_FILE=' . escapeshellarg($scriptFile) . ' DN_FAKE_IMAGE_LOG=' . escapeshellarg($callLog) . ' ' : '';
        return (string)shell_exec('cd ' . escapeshellarg($s->plug) . ' && DN_DATA_DIR=' . escapeshellarg($s->data) . ' DN_VAULT_FILE=' . escapeshellarg($s->vault) . ' ' . $env . 'php run_media_worker.php 2>/dev/null');
    };
    $serve = function (string $waId, string $bytes) use ($s): void { $s->http('POST', "{$s->evo}/__test/media", ['for_id' => $waId, 'base64' => base64_encode($bytes), 'mimetype' => 'image/png']); };
    $logLines = function () use ($callLog): int { return count(array_filter(explode("\n", (string)@file_get_contents($callLog)))); };
    $reqs = function () use ($s): array { return array_values(array_filter($s->crmDump()['requests'] ?? [], function ($r) { return ($r['method'] ?? 'GET') !== 'GET' && preg_match('#/(payments|invoices)#', (string)($r['path'] ?? '')); })); };

    // C1 — both flags on, a CAPTIONED photo: the webhook does not queue the caption as a text turn; the runner's image
    // turn carries both, once
    $r = $post(vi_envelope('image', 'VI-W-1', '256772000711', ['caption' => 'Here is my roof']));
    $serve('VI-W-1', $pngA);
    $out1 = $runner(true);
    $row = vi_row($pdo, 'VI-W-1');
    $ev  = $pdo->query("SELECT payload, created_by FROM events WHERE event_type = 'ai.reply' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
    $p   = json_decode((string)($ev['payload'] ?? ''), true) ?: [];
    $log = (string)@file_get_contents($s->data . '/ai_platform.log');
    $f['c1'] = ['webhook' => ['http' => $r[0], 'queued' => $r[2]['queued'] ?? null, 'media_queued' => $r[2]['media_queued'] ?? null], 'out' => trim($out1), 'row' => vi_brief($row),
                'replies' => $count("SELECT COUNT(*) FROM events WHERE event_type = 'ai.reply'"), 'event_by' => $ev['created_by'] ?? null,
                'message' => $p['message'] ?? null, 'expected' => $L . ' A roof with a clear view of the sky' . "\n" . $LC . ' Here is my roof', 'origin' => $p['origin'] ?? null,
                'classification' => $p['image']['classification'] ?? null, 'has_caption' => $p['image']['has_caption'] ?? null, 'phone' => $p['customer_phone'] ?? null,
                'msg_body' => (string)$pdo->query("SELECT body FROM wa_messages WHERE wa_message_id = 'VI-W-1'")->fetchColumn(),
                'fake_log_lines' => $logLines(), 'fake_log' => trim((string)@file_get_contents($callLog)),
                'log_has_description' => strpos($log, 'clear view of the sky') !== false, 'log_has_b64' => strpos($log, base64_encode($pngA)) !== false,
                'log_described' => strpos($log, 'picture described') !== false, 'texts' => count($s->texts())];
    // C2 — a caption "stop" with a photo: the webhook records the opt-out (8b) as for any typed text; the picture still makes its turn
    $r = $post(vi_envelope('image', 'VI-W-STOP', '256772000712', ['caption' => 'stop']));
    $serve('VI-W-STOP', $pngS);
    $runner(true);
    $oo = $pdo->query("SELECT phone, source, evidence FROM contact_optouts ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
    $f['c2'] = ['webhook_queued' => $r[2]['queued'] ?? null, 'optouts' => $count('SELECT COUNT(*) FROM contact_optouts'), 'optout' => $oo, 'row' => vi_brief(vi_row($pdo, 'VI-W-STOP')),
                'replies' => $count("SELECT COUNT(*) FROM events WHERE event_type = 'ai.reply'"), 'last_message' => json_decode((string)$pdo->query("SELECT payload FROM events WHERE event_type = 'ai.reply' ORDER BY id DESC LIMIT 1")->fetchColumn(), true)['message'] ?? null];
    // C3 — a payment screenshot through the real tree: evidence, hand-over, no event, no uCRM write
    $r = $post(vi_envelope('image', 'VI-W-PAY', '256772000713', ['caption' => 'paid']));
    $serve('VI-W-PAY', $pngP);
    $runner(true);
    $t3 = $s->texts();
    $f['c3'] = ['row' => vi_brief(vi_row($pdo, 'VI-W-PAY')), 'replies' => $count("SELECT COUNT(*) FROM events WHERE event_type = 'ai.reply'"),
                'state' => (string)$pdo->query("SELECT state FROM wa_conversations WHERE phone = '256772000713'")->fetchColumn(),
                'holding' => count(array_filter($t3, function ($c) use ($HOLD) { return ($c['number'] ?? '') === '256772000713' && trim((string)$c['text']) === $HOLD; })),
                'alert' => count(array_filter($t3, function ($c) { return ($c['number'] ?? '') === '256700000999' && strpos((string)$c['text'], 'nothing was recorded or marked paid') !== false; })),
                'alert_has_amount' => count(array_filter($t3, function ($c) { return ($c['number'] ?? '') === '256700000999' && (strpos((string)$c['text'], '95,000') !== false || strpos((string)$c['text'], '55QZ81') !== false); })),
                'msg_body_starts_payment' => strpos((string)$pdo->query("SELECT body FROM wa_messages WHERE wa_message_id = 'VI-W-PAY'")->fetchColumn(), $LP) === 0,
                'ucrm_money_writes' => count($reqs()), 'ai_log_has_amount' => strpos((string)@file_get_contents($s->data . '/ai_platform.log'), '95,000') !== false];
    // C4 — image OFF: the caption is answered as text (as always) and the picture is fetched only
    $flag(['ai_media_image' => '0']);
    $r = $post(vi_envelope('image', 'VI-W-2', '256772000714', ['caption' => 'and this one?']));
    $serve('VI-W-2', $pngB);
    $runner(true);
    $f['c4'] = ['webhook_queued' => $r[2]['queued'] ?? null, 'media_queued' => $r[2]['media_queued'] ?? null, 'row' => vi_brief(vi_row($pdo, 'VI-W-2')),
                'replies' => $count("SELECT COUNT(*) FROM events WHERE event_type = 'ai.reply'"), 'fake_log_lines' => $logLines(),
                'last_by' => (string)$pdo->query("SELECT created_by FROM events WHERE event_type = 'ai.reply' ORDER BY id DESC LIMIT 1")->fetchColumn()];
    // C5 — media OFF, image ON: nothing recorded (Batch 1), nothing to describe
    $flag(['ai_media_enabled' => '0', 'ai_media_image' => '1']);
    $r = $post(vi_envelope('image', 'VI-W-3', '256772000715'));
    $runner(true);
    $f['c5'] = ['media_queued' => $r[2]['media_queued'] ?? null, 'row' => vi_row($pdo, 'VI-W-3'), 'msg_body' => (string)$pdo->query("SELECT body FROM wa_messages WHERE wa_message_id = 'VI-W-3'")->fetchColumn(),
                'fake_log_lines' => $logLines()];
    // C6 — both on, provider "fake" without its environment: fails closed → provider_missing → a person
    $flag(['ai_media_enabled' => '1', 'ai_media_image' => '1']);
    $r = $post(vi_envelope('image', 'VI-W-4', '256772000716'));
    $serve('VI-W-4', $pngS);
    $runner(false);
    $t6 = $s->texts();
    $f['c6'] = ['row' => vi_brief(vi_row($pdo, 'VI-W-4')), 'state' => (string)$pdo->query("SELECT state FROM wa_conversations WHERE phone = '256772000716'")->fetchColumn(),
                'holding' => count(array_filter($t6, function ($c) use ($HOLD) { return ($c['number'] ?? '') === '256772000716' && trim((string)$c['text']) === $HOLD; })),
                'alert' => count(array_filter($t6, function ($c) { return ($c['number'] ?? '') === '256700000999' && strpos((string)$c['text'], 'provider_missing') !== false; })),
                'fake_log_lines' => $logLines(), 'replies' => $count("SELECT COUNT(*) FROM events WHERE event_type = 'ai.reply'")];
    $f['disk'] = array_merge(vi_scan($s->data, [base64_encode($pngA), base64_encode($pngP), '55QZ81']), vi_scan($s->plug, [base64_encode($pngA), base64_encode($pngP), '55QZ81']));
    $f['cs_jobs'] = (function () use ($s): array { [$rc, $out] = $s->run('tools/cron_status.php', ['--all']); return ['rc' => $rc, 'ai_media' => strpos($out, 'ai_media') !== false]; })();
    $s->stop();
    return $f;
}

// ── Driver mode: one JSON line ───────────────────────────────────────────────────────────────────
if ($driver !== null) {
    if ($driver === 'cli') {
        $facts = vi_cli($root);
    } else {
        $evo = (int)getenv('DN_T_EVO_PORT'); $ucrm = (int)getenv('DN_T_UCRM_PORT');
        $srvE = $srvU = null;
        if ($evo <= 0)  { [$srvE, $evo]  = vi_boot($root . '/tests/fixtures/fake_evo_server.php', 9890, 'FAKE-EVO-TEST'); }
        if ($ucrm <= 0) { [$srvU, $ucrm] = vi_boot($root . '/tests/fixtures/fake_ucrm_server.php', 9950, 'FAKE-UCRM-TEST'); }
        $facts = vi_core($root, $evo, $ucrm);
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
[$evoSrv, $evoPort]   = vi_boot($root . '/tests/fixtures/fake_evo_server.php', 9885, 'FAKE-EVO-TEST');
[$ucrmSrv, $ucrmPort] = vi_boot($root . '/tests/fixtures/fake_ucrm_server.php', 9945, 'FAKE-UCRM-TEST');
if (!$evoPort || !$ucrmPort) { echo "FAIL could not start the fake servers\n0 passed, 1 failed\n"; exit(1); }

$c = vi_core($root, $evoPort, $ucrmPort);
$L = ImageUnderstanding::LABEL; $LP = ImageUnderstanding::LABEL_PAYMENT;

echo "1. A valid picture → row → fetch → understanding\n";
$t1 = $c['t1'];
is_(($t1['run']['processed'] ?? 0) === 1 && ($t1['run']['failed'] ?? -1) === 0 && $t1['media_event'] === 'done', 'the media event is processed and done', j($t1['run']));
is_($t1['row']['status'] === 'understood' && $t1['row']['kind'] === 'description' && strpos((string)$t1['row']['text'], 'tin-roofed') === 2 && $t1['row']['attempts'] === 1,
    'the row: understood, kind description, the description, one attempt', j($t1['row']));
is_($t1['msg']['body'] === $t1['expected_message'] && $t1['msg']['media_type'] === 'image' && ($t1['msg']['meta_image']['classification'] ?? '') === 'site_photo' && ($t1['msg']['meta_image']['width'] ?? 0) === 640 && ($t1['msg']['meta_image']['payment_evidence'] ?? null) === false,
    'the stored [IMAGE] message now carries the labelled description and the image metadata', j($t1['msg']));
is_($t1['fetches'] === 1 && $t1['fake_calls'] === 1, 'one fetch, one provider call', j([$t1['fetches'], $t1['fake_calls']]));
is_(is_array($c['t1_fake_call']) && !isset($c['t1_fake_call']['description']) && strlen((string)($c['t1_fake_call']['sha'] ?? '')) === 12 && ($c['t1_fake_call']['width'] ?? 0) === 640 && ($c['t1_fake_call']['height'] ?? 0) === 480 && ($c['t1_fake_call']['timeout'] ?? 0) === 7 && ($c['t1_fake_call']['caption_chars'] ?? -1) === 0,
    'the provider was given the bytes, the header facts and the configured budget; it recorded a hash prefix and sizes', j($c['t1_fake_call']));
is_($t1['log_described'], 'the log says a picture was described, by class and length, with the event number');

echo "\n2. The description enters the existing brain exactly once\n";
$ev = $t1['event'];
is_($t1['replies_added'] === 1 && $ev['created_by'] === 'media_worker' && $ev['status'] === 'pending', 'exactly one ai.reply event for the picture, queued by the media worker', j([$t1['replies_added'], $ev['created_by'], $ev['status']]));
is_($ev['phone'] === '256772000611' && $ev['channel'] === 'sales' && $ev['wa_message_id'] === 'VI-1' && $ev['received_at_ok'] && $ev['has_voice'] === false, 'the event has the shape the webhook gives a typed message, and no voice key', j($ev));
is_(($c['t3']['brain_calls'] ?? 0) === 1 && ($c['t3']['run']['processed'] ?? 0) === 1, 'the reply worker processed it with one brain call', j([$c['t3']['brain_calls'], $c['t3']['run']]));

echo "\n3. The image modality is preserved — event, context, prompt, audit\n";
is_($ev['message'] === $t1['expected_message'] && $ev['origin'] === 'image' && ($ev['image']['classification'] ?? null) === 'site_photo' && ($ev['image']['width'] ?? 0) === 640 && ($ev['image']['height'] ?? 0) === 480 && ($ev['image']['has_caption'] ?? null) === false && (int)($ev['image']['media_id'] ?? 0) > 0,
    'the message is labelled, origin image, with classification, dimensions, caption flag and row id', j([$ev['message'], $ev['origin'], $ev['image']]));
$t3 = $c['t3'];
is_($t3['ctx_is_contract'] && $t3['ctx_image'] === ['classification' => 'site_photo'] && $t3['ctx_voice'] === 'absent' && $t3['ctx_message_labelled'], 'the sales contract carries image = {classification} and the labelled message, and no voice key', j([$t3['ctx_image'], $t3['ctx_voice'], $t3['ctx_message_labelled']]));
is_($t3['prompt_block'] && $t3['prompt_rules'] && $t3['prompt_without_image_has_block'] === false, 'the prompt carries the IMAGE block with the payment rule, and not for a typed turn', j([$t3['prompt_block'], $t3['prompt_rules'], $t3['prompt_without_image_has_block']]));
is_($t3['texts_added'] === 1 && $t3['reply_to'] === '256772000611' && strpos($t3['reply_sent'], 'Which town') !== false && $t3['stored_out'] === 2, 'the brain\'s reply went to the customer once and was stored', j([$t3['texts_added'], $t3['reply_to'], $t3['reply_sent'], $t3['stored_out']]));
is_($c['t3_history']['has_labelled_description'] && $c['t3_history']['typed_turn_has_image'] === false, 'a later typed turn sees the labelled description in history, and carries no image key', j($c['t3_history']));
$tc = $c['t_caption'];
is_($tc['message'] === $tc['expected'] && $tc['has_caption'] === true && $tc['hint_caption_chars'] === $tc['caption_len'] && $tc['msg_body'] === $tc['expected'],
    'a captioned picture: description and caption in one turn, each under its own label; the provider saw the caption length', j($tc));
is_($c['t11']['audit_modality'] === 'image', 'a blocked reply\'s audit event says modality image', j($c['t11']));

echo "\n4. A duplicate event is idempotent\n";
$t4 = $c['t4'];
is_(($t4['run']['processed'] ?? 0) === 1 && $t4['fake_calls'] === 1 && $t4['fetches'] === 1 && $t4['replies_added'] === 1 && $t4['log_dup'], 'the duplicate is acknowledged: no second fetch, no second description, no second event', j($t4));
is_($t4['direct'] === 'already_understood' && strpos((string)$t4['row_text'], 'tin-roofed') === 2, 'the understood guard holds even when completion is called directly', j([$t4['direct'], $t4['row_text']]));

echo "\n5. Unsupported MIME rejected\n";
is_($c['t5_svg']['row']['status'] === 'unsupported' && $c['t5_svg']['row']['reason'] === 'unsupported_mime' && $c['t5_svg']['fake_calls_added'] === 0 && $c['t5_svg']['replies_added'] === 0 && $c['t5_svg']['state'] === 'needs_human' && $c['t5_svg']['holding'] === 1,
    'an SVG announced: refused by the Batch 1 fetcher, no provider call, handed over', j($c['t5_svg']));
is_($c['t5_gif']['row']['status'] === 'failed' && $c['t5_gif']['row']['reason'] === 'unsupported_mime' && $c['t5_gif']['fake_calls_added'] === 0 && $c['t5_gif']['replies_added'] === 0 && $c['t5_gif']['state'] === 'needs_human' && $c['t5_gif']['holding'] === 1,
    'a GIF behind a PNG label: the header says so, refused before any provider, handed over', j($c['t5_gif']));

echo "\n6. Oversized image rejected\n";
is_($c['t6_announced']['row']['status'] === 'failed' && $c['t6_announced']['row']['reason'] === 'too_large' && $c['t6_announced']['fetches_added'] === 0 && $c['t6_announced']['fake_calls_added'] === 0 && $c['t6_announced']['state'] === 'needs_human' && $c['t6_announced']['holding'] === 1,
    'an announced 20 MB: refused before the fetch (Batch 1), handed over', j($c['t6_announced']));
is_($c['t6_header']['row']['status'] === 'failed' && $c['t6_header']['row']['reason'] === 'too_large_image' && $c['t6_header']['fake_calls_added'] === 0 && $c['t6_header']['replies_added'] === 0 && $c['t6_header']['state'] === 'needs_human' && $c['t6_header']['holding'] === 1,
    'a header declaring 9000×7000: refused before any provider, handed over', j($c['t6_header']));

echo "\n7. Malformed image rejected\n";
$t7 = $c['t7'];
is_($t7['row']['status'] === 'failed' && $t7['row']['reason'] === 'malformed_image' && $t7['fake_calls_added'] === 0 && $t7['replies_added'] === 0, 'bytes that are not an image: malformed_image, no provider call, no event', j($t7['row']));
is_($t7['state'] === 'needs_human' && $t7['holding'] === 1 && $t7['escalations_added'] === 5 && $t7['log'], 'handed over (the fifth hand-over of this run), with the holding line once', j($t7));

echo "\n8. Provider timeout / failure retried\n";
$t8 = $c['t8_first'];
is_($t8['row']['status'] === 'failed' && $t8['row']['reason'] === 'timeout' && ($t8['event']['status'] ?? '') === 'failed' && (int)($t8['event']['attempts'] ?? 0) === 1 && $t8['fake_calls_added'] === 1 && $t8['fetches_added'] === 1 && $t8['error_has_jid'] === false && $t8['state'] !== 'needs_human',
    'a timeout: row failed/timeout, event failed with one attempt, not handed over yet', j($t8));
$t8b = $c['t8_retry'];
is_($t8b['row']['status'] === 'understood' && $t8b['row']['attempts'] === 2 && ($t8b['event']['status'] ?? '') === 'done' && $t8b['fetches_added'] === 1 && $t8b['fake_calls_added'] === 1 && $t8b['replies_added'] === 1 && $t8b['classification'] === 'equipment_photo',
    'the due retry fetches again, describes, and queues the one event', j($t8b));
is_($c['t8_error']['row']['status'] === 'failed' && $c['t8_error']['row']['reason'] === 'provider_error' && ($c['t8_error']['event']['status'] ?? '') === 'failed', 'a provider error is a retryable failure too', j($c['t8_error']));

echo "\n9. Safe human hand-over on permanent failure\n";
$t9 = $c['t9_none'];
is_($t9['row']['status'] === 'failed' && $t9['row']['reason'] === 'provider_missing' && $t9['fake_calls_added'] === 0 && $t9['replies_added'] === 0 && $t9['state'] === 'needs_human' && $t9['holding'] === 1 && $t9['alerted'],
    'no provider: provider_missing, no call, no event, the holding line, a person alerted', j($t9));
is_($c['t9_factory'] === true, 'the factory yields no provider for none, unset, an unknown name, or "fake" without its test environment');
$t9b = $c['t9_dead'];
is_($t9b['row']['status'] === 'dead' && $t9b['event'] === 'dead' && $t9b['state'] === 'needs_human' && $t9b['holding'] === 1 && $t9b['replies_added'] === 0 && $t9b['log'], 'the queue giving up: dead, handed over with the holding line, no event', j($t9b));

echo "\n10. STOP / opt-out behaviour intact\n";
is_($c['t10']['optouts_added'] === 0 && $c['t10']['row'] === 'understood', 'a DESCRIPTION reading "stop" is not an opt-out — it is the provider\'s words, not the customer\'s (the caption path is the webhook\'s, proved under C)', j($c['t10']));

echo "\n11. ReplyPrivacyGuard intact\n";
$t11 = $c['t11'];
is_($t11['audit_added'] === 1 && $t11['audit_conv'] === $t11['expected_conv'] && $t11['leaked'] === 0 && $t11['fallback'] === 1 && $t11['state'] === 'needs_human',
    'a reply the description induced is blocked: the safe fallback sent, the blocked text never, audited against the real conversation, a person takes over', j($t11));

echo "\n12. A payment screenshot produces evidence and an escalation only\n";
$t12 = $c['t12'];
is_($t12['row']['status'] === 'understood' && $t12['row']['kind'] === 'payment_evidence' && strpos((string)$t12['row']['text'], 'MTN Mobile Money') === 0, 'the row: understood, kind payment_evidence, the description kept as the evidence record', j($t12['row']));
is_($t12['replies_added'] === 0 && ($t12['run']['processed'] ?? 0) === 1, 'NO ai.reply event — the brain never sees it', j([$t12['replies_added'], $t12['run']]));
is_($t12['state'] === 'needs_human' && $t12['escalations_added'] === 1 && $t12['escalation_by'] === 'media_worker' && strpos($t12['escalation_reason'], 'payment screenshot or receipt arrived') !== false && strpos($t12['escalation_reason'], 'nothing was recorded or marked paid') !== false,
    'handed to a person: needs_human, one wa.escalation by the media worker naming the payment rule', j([$t12['state'], $t12['escalations_added'], $t12['escalation_by'], $t12['escalation_reason']]));
is_($t12['alert_says_nothing_recorded'] && $t12['alert_has_amount'] === false && $t12['holding'] === 1, 'the staff alert says nothing was recorded — without the amount or the transaction id; the customer gets the holding line once', j([$t12['alerts'], $t12['holding']]));
is_($t12['msg_body'] === $t12['expected_body'] && ($t12['meta']['payment_evidence'] ?? null) === true && ($t12['meta']['classification'] ?? '') === 'payment_proof', 'the inbox shows the evidence under the payment label, with the caption, flagged as payment evidence', j([$t12['msg_body'], $t12['meta']]));
is_($t12['log'] && $t12['log_has_amount'] === false, 'the log says payment evidence, a person verifies — and never the amount or the reference', j([$t12['log'], $t12['log_has_amount']]));
is_($c['t12_words']['row']['kind'] === 'payment_evidence' && $c['t12_words']['replies_added'] === 0 && $c['t12_words']['state'] === 'needs_human' && $c['t12_words']['holding'] === 1,
    'a "screenshot" whose words say transfer, successful, UGX 420,000: routed as payment evidence by the words alone', j($c['t12_words']));
is_($c['t12_speed']['row']['kind'] === 'description' && $c['t12_speed']['replies_added'] === 1 && $c['t12_speed']['state'] !== 'needs_human', 'a speed-test screenshot is an ordinary picture: one event, no hand-over', j($c['t12_speed']));
is_($c['t13_detector'] === ['class' => true, 'words' => true, 'speedtest' => false, 'shop_sign' => false, 'price_list' => false, 'money_and_txn' => true, 'signals_only' => true],
    'the detector: the class alone, or money AND transaction words; a shop sign or a price list is not a payment', j($c['t13_detector']));

echo "\n13. No financial write from image understanding\n";
$t13 = $c['t13'];
is_($t13['money_before'] === $t13['money_after'] && count($t13['money_before']) >= 8, 'every money table has exactly the rows it had (' . count($t13['money_before']) . ' tables compared)', j($t13));
is_($t13['ucrm_payments_before'] === 0 && $t13['ucrm_payments_after'] === 0, 'the fake uCRM received no payment', j([$t13['ucrm_payments_before'], $t13['ucrm_payments_after']]));
$writers = ['CrmApiClient', 'createPayment', 'DpoPaymentService', 'CashbookService', 'markPaid', 'mark_paid', 'cb_ledger', 'dpo_payments', 'wallet_transactions', 'staff_ledger'];
$noWriter = true; $where = [];
foreach (['lib/ImageUnderstanding.php', 'lib/PaymentEvidence.php', 'lib/ImageDescriber.php', 'workers/MediaWorker.php', 'lib/Handover.php'] as $rel) {
    $code = vi_codeOf($root . '/' . $rel);
    foreach ($writers as $w) if (strpos($code, $w) !== false) { $noWriter = false; $where[] = "$rel has $w"; }
}
is_($noWriter, 'neither the image path nor the hand-over names any payment, ledger, cashbook or CRM writer', j($where));

echo "\n14. No image bytes / base64 persisted\n";
is_($c['t14']['disk'] === [] && $c['t14']['png_bytes_on_disk'] === [], 'no file under the data directory holds the picture, its base64, its hash, a description or a transaction id', j($c['t14']));

echo "\n15. No sensitive image content logged\n";
$t15 = $c['t15'];
is_($t15['log_has_b64'] === false && $t15['log_has_description'] === false && $t15['log_has_payment_details'] === false && $t15['log_has_jid'] === false && $t15['fake_saw_content'] === 0,
    'the worker logs carry no base64, no description, no amount or reference, no JID; the provider recorded hash prefixes and sizes only', j($t15));

echo "\n16. ai_media_image OFF: fetched, nothing more\n";
$t16 = $c['t16'];
is_($t16['row']['status'] === 'fetched' && $t16['fake_calls_added'] === 0 && $t16['replies_added'] === 0 && $t16['texts_added'] === 0 && $t16['state'] !== 'needs_human' && $t16['msg_body'] === 'look at this',
    'with the image flag off a picture is fetched (Batch 1) and nothing else happens; the caption stays the stored text', j($t16));

echo "\n17. ai_media_enabled OFF overrides\n";
is_($c['t17_policy'] === ['image_alone' => false, 'both' => true, 'media_alone' => false, 'none' => false], 'imageEnabled() needs both flags', j($c['t17_policy']));
is_($c['t17']['row']['status'] === 'skipped' && $c['t17']['row']['reason'] === 'media_disabled' && $c['t17']['fake_calls_added'] === 0 && $c['t17']['replies_added'] === 0 && $c['t17']['fetches_added'] === 0,
    'media off, image on: skipped, nothing fetched, nothing described, nothing queued', j($c['t17']));

echo "\nC. The CLI runner in the real plugin tree\n";
$w = vi_cli($root);
$c1 = $w['c1'];
is_(($c1['webhook']['queued'] ?? -1) === 0 && ($c1['webhook']['media_queued'] ?? 0) === 1 && $c1['out'] === '' && $c1['row']['status'] === 'understood' && $c1['row']['kind'] === 'description',
    'a captioned photo with the flag on: the webhook queues NO text turn, records the picture; the runner describes it', j([$c1['webhook'], $c1['out'], $c1['row']]));
is_($c1['replies'] === 1 && $c1['event_by'] === 'media_worker' && $c1['message'] === $c1['expected'] && $c1['origin'] === 'image' && $c1['classification'] === 'site_photo' && $c1['has_caption'] === true && $c1['phone'] === '256772000711' && $c1['msg_body'] === $c1['expected'],
    'ONE event carries the description and the caption under their labels — the customer is answered once', j($c1));
is_($c1['fake_log_lines'] === 1 && strpos($c1['fake_log'], '"sha":"') !== false && strpos($c1['fake_log'], 'roof') === false && $c1['log_has_description'] === false && $c1['log_has_b64'] === false && $c1['log_described'] && $c1['texts'] === 0,
    'the provider log holds one hash-prefix line; ai_platform.log holds no description and no base64; nothing was sent', j([$c1['fake_log'], $c1['texts']]));
$c2 = $w['c2'];
is_($c2['webhook_queued'] === 0 && $c2['optouts'] === 1 && ($c2['optout']['phone'] ?? '') === '256772000712' && ($c2['optout']['source'] ?? '') === 'keyword' && ($c2['optout']['evidence'] ?? '') === 'stop' && $c2['row']['status'] === 'understood' && $c2['replies'] === 2 && strpos((string)$c2['last_message'], 'stop') !== false,
    'a caption "stop" with a photo: the webhook records the opt-out exactly as for typed text, and the picture still makes its turn (acknowledged)', j($c2));
$c3 = $w['c3'];
is_($c3['row']['kind'] === 'payment_evidence' && $c3['replies'] === 2 && $c3['state'] === 'needs_human' && $c3['holding'] === 1 && $c3['alert'] === 1 && $c3['alert_has_amount'] === 0 && $c3['msg_body_starts_payment'] && $c3['ucrm_money_writes'] === 0 && $c3['ai_log_has_amount'] === false,
    'a payment screenshot through the real tree: evidence, hand-over, no event, no uCRM payment or invoice write, no amount in the alert or the log', j($c3));
$c4 = $w['c4'];
is_($c4['webhook_queued'] === 1 && $c4['media_queued'] === 1 && $c4['row']['status'] === 'fetched' && $c4['replies'] === 3 && $c4['last_by'] === 'evo_webhook' && $c4['fake_log_lines'] === 3,
    'image OFF: the caption is answered as text by the webhook as always; the picture is fetched only; no provider call', j($c4));
is_(($w['c5']['media_queued'] ?? -1) === 0 && $w['c5']['row'] === [] && $w['c5']['msg_body'] === '[IMAGE]' && $w['c5']['fake_log_lines'] === 3, 'media OFF with image ON: nothing recorded — media off wins', j($w['c5']));
$c6 = $w['c6'];
is_($c6['row']['status'] === 'failed' && $c6['row']['reason'] === 'provider_missing' && $c6['state'] === 'needs_human' && $c6['holding'] === 1 && $c6['alert'] === 1 && $c6['fake_log_lines'] === 3 && $c6['replies'] === 3,
    'provider "fake" without its environment fails closed: provider_missing, a person, the holding line, no event', j($c6));
is_($w['disk'] === [], 'no file under the sandbox holds a picture, its base64 or a transaction id', j($w['disk']));
is_(($w['cs_jobs']['rc'] ?? 1) === 0 && $w['cs_jobs']['ai_media'] === true, 'cron_status lists the ai_media job while the media flag is on (unchanged since Batch 1)', j($w['cs_jobs']));

echo "\nD. Wiring\n";
$mwCode = vi_codeOf($root . '/workers/MediaWorker.php'); $iuCode = vi_codeOf($root . '/lib/ImageUnderstanding.php');
is_(strpos($mwCode, 'DishNetAiBrain') === false && strpos($mwCode, 'ReplyPrivacyGuard') === false && strpos($iuCode, 'DishNetAiBrain') === false && strpos($iuCode, 'ReplyPrivacyGuard') === false,
    'neither the media worker nor the image service knows the brain or the guard: the description joins the ai.reply queue and nothing else');
is_(strpos($mwCode, 'ai_media_image') === false && strpos($mwCode, 'MediaPolicy::imageEnabled($this->config)') !== false, 'the worker reads the image flag through the policy only');
is_(strpos($iuCode, 'PaymentEvidence::looksLikePayment(') !== false && strpos($iuCode, "if (\$payment) {") !== false, 'the payment decision is this plugin\'s (PaymentEvidence) and is taken before any event is queued');
is_(strpos(vi_codeOf($root . '/evo_webhook.php'), "if (\$mediaEvent && (string)(\$media['kind'] ?? '') === 'image' && MediaPolicy::imageEnabled(\$config)) {") !== false, 'the webhook skips the caption\'s text turn only for a recorded picture with the image flag on');
is_(is_file(dirname($root) . '/docs/57-image-understanding-provider-boundary-2026-10-05.md'), 'the image provider boundary and the payment rule are documented (docs/57) before any provider exists');
is_(count(glob($root . '/migrations/08[7-9]_*.sql') ?: []) === 0 && is_file($root . '/migrations/086_install_authorisation.sql'), 'no migration: wa_media already carries understanding and understanding_kind — 086 is Customer Installation Authorisation (5.18.82), not this feature\'s');
$sc = (string)file_get_contents($root . '/tools/set_config.php');
is_(strpos($sc, "'ai_media_image' => ['bool',") !== false && strpos($sc, "'ai_media_image_timeout_s' => ['number',") !== false && strpos($sc, "'ai_image_provider' => ['text',") !== false, 'set_config.php manages the image settings');
$scOut = shell_exec('php ' . escapeshellarg($root . '/tools/set_config.php') . ' --key ai_image_provider --value fake 2>&1; echo "rc=$?"');
is_(strpos((string)$scOut, 'none is the only value today (docs/57)') !== false && strpos((string)$scOut, 'rc=1') !== false, 'the tool refuses a vision provider that does not exist, the fake included', (string)$scOut);
is_(json_decode((string)file_get_contents($root . '/manifest.json'), true)['information']['version'] === '5.18.85', 'manifest version is 5.18.81');

echo "\n18. Weakened copies — each caught by the scenario that guards it\n";
$mutants = [
    ['core', 'lib/MediaPolicy.php', "        return self::enabled(\$config) && self::flag(\$config['ai_media_image'] ?? null);", "        return self::flag(\$config['ai_media_image'] ?? null);",
     'ai_media_image alone turns images on (media off no longer wins)', function (array $m): bool { return ($m['t17_policy']['image_alone'] ?? null) === true; }],
    ['core', 'workers/MediaWorker.php', "        return (string)(\$row['kind'] ?? '') === 'image' && MediaPolicy::imageEnabled(\$this->config);", "        return (string)(\$row['kind'] ?? '') === 'image';",
     'the worker ignores the image flag', function (array $m): bool { return ($m['t16']['fake_calls_added'] ?? 0) >= 1 || ($m['t16']['replies_added'] ?? 0) >= 1; }],
    ['core', 'lib/PaymentEvidence.php', "        return preg_match(self::MONEY, \$t) === 1 && preg_match(self::TRANSACTION, \$t) === 1;", "        return false;",
     'the words no longer decide (a payment the provider mislabels reaches the brain)', function (array $m): bool { return ($m['t12_words']['replies_added'] ?? 0) >= 1 || ($m['t12_words']['row']['kind'] ?? '') === 'description'; }],
    ['core', 'lib/ImageUnderstanding.php', "            if (\$payment) {\n                // Evidence for a person.", "            if (false) {\n                // Evidence for a person.",
     'payment evidence is queued for the brain after all', function (array $m): bool { return ($m['t12']['replies_added'] ?? 0) >= 1; }],
    ['core', 'lib/ImageUnderstanding.php', "        \$out = (\$payment ? self::LABEL_PAYMENT : self::LABEL) . ' ' . \$description;", "        \$out = \$description;",
     'the description is no longer labelled', function (array $m): bool { return ($m['t3']['ctx_message_labelled'] ?? true) === false; }],
    ['core', 'lib/DishNetAiBrain.php', "        \$image = \$ctx['image'] ?? null;\n        if (is_array(\$image)) {", "        \$image = \$ctx['image'] ?? null;\n        if (false) {",
     'the prompt no longer says the message describes a picture', function (array $m): bool { return ($m['t3']['prompt_block'] ?? true) === false; }],
    ['core', 'lib/ImageUnderstanding.php', "        \$info = @getimagesizefromstring(\$image->bytes);", "        \$info = [0 => 100, 1 => 100, 'mime' => 'image/png'];",
     'the header is trusted instead of read (junk reaches the provider)', function (array $m): bool { return ($m['t7']['fake_calls_added'] ?? 0) >= 1; }],
    ['core', 'lib/ImageUnderstanding.php', "        if (\$w > MediaPolicy::IMAGE_MAX_SIDE_PX || \$h > MediaPolicy::IMAGE_MAX_SIDE_PX || \$w * \$h > MediaPolicy::IMAGE_MAX_PIXELS) {", "        if (false) {",
     'the dimension cap is gone', function (array $m): bool { return ($m['t6_header']['fake_calls_added'] ?? 0) >= 1; }],
    ['core', 'workers/MediaWorker.php', "        \$this->handover(\$row, 'a photo could not be understood (' . \$reason . ') — look at it in WhatsApp');", "        // handover removed",
     'a permanent failure no longer hands over', function (array $m): bool { return ($m['t9_none']['state'] ?? '') !== 'needs_human'; }],
    ['core', 'lib/ImageUnderstanding.php', "            // The one ai.reply event: the shape evo_webhook.php queues for a typed message, plus where it came from.", "            (\$this->log)('info', 'description: ' . \$description);",
     'the description is logged', function (array $m): bool { return ($m['t15']['log_has_description'] ?? false) === true; }],
];
foreach ($mutants as [$scn, $rel, $old, $new, $what, $flipped]) {
    [$copy, $n] = sj_weakened_copy($root, $rel, $old, $new);
    is_($n === 1, "copy — the anchor in {$rel} is unique, so the copy is weakened ({$what})", 'occurrences: ' . $n);
    $out = (string)shell_exec('DN_T_EVO_PORT=' . (int)$evoPort . ' DN_T_UCRM_PORT=' . (int)$ucrmPort . ' php ' . escapeshellarg($copy . '/tests/test_image_media.php') . ' --driver=' . $scn . ' 2>/dev/null');
    $m = json_decode((string)strrchr("\n" . trim($out), "\n"), true) ?: [];
    is_($n === 1 && $m !== [] && $flipped($m), "caught — {$what}", $m === [] ? 'driver output: ' . substr($out, 0, 400) : j(array_intersect_key($m, ['t17_policy' => 1, 't16' => 1, 't12_words' => 1, 't12' => 1, 't3' => 1, 't7' => 1, 't6_header' => 1, 't9_none' => 1, 't15' => 1])));
    exec('rm -rf ' . escapeshellarg($copy));
}
$out = (string)shell_exec('DN_T_EVO_PORT=' . (int)$evoPort . ' DN_T_UCRM_PORT=' . (int)$ucrmPort . ' php ' . escapeshellarg($root . '/tests/test_image_media.php') . ' --driver=core 2>/dev/null');
$m = json_decode((string)strrchr("\n" . trim($out), "\n"), true) ?: [];
$flips = 0; foreach ($mutants as [$scn, $rel, $old, $new, $what, $flipped]) { if ($m !== [] && $flipped($m)) $flips++; }
is_($m !== [] && $flips === 0, 'control: the real tree, driven the same way, trips none of the ten catches', j(['facts' => count($m), 'flips' => $flips]));

foreach ([$evoSrv, $ucrmSrv] as $p) if (is_resource($p)) { proc_terminate($p); proc_close($p); }
array_map('unlink', glob(sys_get_temp_dir() . '/fake_evo_state_*.json') ?: []);
array_map('unlink', glob(sys_get_temp_dir() . '/fake_ucrm_*.json') ?: []);
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
