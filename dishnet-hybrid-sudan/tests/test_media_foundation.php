<?php
declare(strict_types=1);
/**
 * test_media_foundation.php — Batch 1 of the AI communication layer (docs/55 §9): the media foundation, dark.
 *
 * What a customer's voice note, photo or document becomes when ai_media_enabled is ON — and that NOTHING changes when
 * it is OFF, which is how it ships:
 *
 *   InboundMedia   one normalised shape for the five media envelopes Evolution sends (and null for text);
 *   wa_media       one row per WhatsApp message id (UNIQUE), written by the webhook only with the flag on;
 *   MediaPolicy    the limits and the allow-list, every number in one place;
 *   MediaFetcher   Evolution's getBase64FromMediaMessage into memory — key present, kind supported, announced size
 *                  within the limit BEFORE any call; then the type against the allow-list, a strict decode, the actual
 *                  size, a sha256 — each refusal a fixed code with a retryable flag;
 *   MediaWorker    its own lock and runner: fetches once per message however many events name it, skips when the flag
 *                  is off, retries a transient failure through the EventBus, hands a dead one to a person, sends nothing.
 *
 * Two scenarios return FACTS (no asserting inside), so a weakened copy can run them too:
 *   core     in-process against a fake Evolution — normalisation, policy, store, fetcher, worker, locks, retention;
 *   webhook  the real plugin under php -S (SjSandbox) — the flag OFF and ON, a duplicate delivery, a caption still
 *            answered as text, an unsupported kind, and run_media_worker.php over the CLI.
 * Six weakened copies are each caught by re-running the scenario that guards them (this file re-enters itself).
 *
 * Driver mode: php tests/test_media_foundation.php --driver=core|webhook  (env DN_T_EVO_PORT for core, DN_T_FAST=1 to
 * skip the one real timeout) → one JSON line.
 */
$root = dirname(__DIR__);
date_default_timezone_set('UTC');
$driver = null;
foreach ($argv ?? [] as $a) { if (strpos($a, '--driver') === 0) $driver = substr($a, 9) !== false && substr($a, 9) !== '' ? substr($a, 9) : 'core'; }
$fast = getenv('DN_T_FAST') === '1';

require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/ConversationService.php';
require_once $root . '/lib/EventBus.php';
require_once $root . '/lib/EvolutionApiService.php';
require_once $root . '/lib/MediaPolicy.php';
require_once $root . '/lib/MediaBlob.php';
require_once $root . '/lib/InboundMedia.php';
require_once $root . '/lib/MediaFetcher.php';
require_once $root . '/workers/WorkerBase.php';
require_once $root . '/workers/MediaWorker.php';
require_once $root . '/tests/fixtures/staff_jobs_sandbox.php';   // SjSandbox, sj_weakened_copy()

// ── helpers ──────────────────────────────────────────────────────────────────────────────────────
function mf_hit(int $port, string $path, $json = null, int $timeout = 40): string
{
    $ch = curl_init("http://127.0.0.1:{$port}{$path}");
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*'];
    if ($json !== null) { $o[CURLOPT_POST] = true; $o[CURLOPT_POSTFIELDS] = json_encode($json); $o[CURLOPT_HTTPHEADER] = ['Content-Type: application/json']; }
    curl_setopt_array($ch, $o);
    $r = curl_exec($ch); curl_close($ch);
    return $r === false ? '' : (string)$r;
}
function mf_state(int $port): array { return json_decode(mf_hit($port, '/__test/state'), true) ?: []; }
function mf_boot(string $router, int $base, string $sig): array
{
    foreach (range(0, 11) as $slot) {
        $cand = $base + ((getmypid() + $slot * 13) % 70);
        $p = proc_open(sprintf('exec php -S 127.0.0.1:%d %s', $cand, escapeshellarg($router)), [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 40; $i++) {
            $r = mf_hit($cand, '/__test/state', null, 3);
            if ($r !== '') { if (strpos($r, $sig) !== false) return [$p, $cand]; break; }
            usleep(100000);
        }
        if (is_resource($p)) { proc_terminate($p); proc_close($p); }
    }
    return [null, 0];
}
/** The deterministic payload the fake builds for `bytes` — the same bytes, so the test can know the sha256 it expects. */
function mf_payload(int $n): string { return substr(str_repeat('DN-MEDIA-TEST-PAYLOAD/', (int)ceil($n / 22)), 0, $n); }
/** An Evolution messages.upsert envelope for one kind of message. */
function mf_envelope(string $kind, array $o = []): array
{
    $id  = $o['id'] ?? ('MF-' . strtoupper($kind) . '-' . bin2hex(random_bytes(4)));
    $jid = ($o['phone'] ?? '256772000311') . '@s.whatsapp.net';
    $cap = (string)($o['caption'] ?? '');
    $parts = [
        'audio'    => ['audioMessage'    => ['url' => 'https://mmg.whatsapp.net/v/t62.7117-24/x.enc', 'mimetype' => 'audio/ogg; codecs=opus',
                                             'fileLength' => '12345', 'seconds' => 7, 'ptt' => true, 'mediaKey' => 'TESTKEY=']],
        'image'    => ['imageMessage'    => ['url' => 'https://mmg.whatsapp.net/v/t62.7118-24/y.enc', 'mimetype' => 'image/jpeg',
                                             'fileLength' => '20480', 'height' => 1280, 'width' => 960] + ($cap !== '' ? ['caption' => $cap] : [])],
        'document' => ['documentMessage' => ['url' => 'https://mmg.whatsapp.net/v/t62.7119-24/z.enc', 'mimetype' => 'application/pdf',
                                             'fileName' => 'quote.pdf', 'fileLength' => '44000'] + ($cap !== '' ? ['caption' => $cap] : [])],
        'video'    => ['videoMessage'    => ['mimetype' => 'video/mp4', 'seconds' => 12, 'fileLength' => '900000']],
        'sticker'  => ['stickerMessage'  => ['mimetype' => 'image/webp', 'fileLength' => '5000']],
        'text'     => ['conversation'    => (string)($o['text'] ?? 'Hello, I need internet for my shop')],
    ];
    $message = $parts[$kind];
    return ['event' => 'messages.upsert', 'instance' => $o['instance'] ?? 'sj-sales', 'data' => [
        'key' => ['id' => $id, 'fromMe' => false, 'remoteJid' => $jid],
        'pushName' => 'Media Tester', 'messageType' => array_keys($message)[0], 'messageTimestamp' => time(), 'message' => $message]];
}
/** Files under $dir (the database itself excluded) holding any of the needles: what a retention scan must find NOTHING of. */
function mf_scan(string $dir, array $needles): array
{
    $hits = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $b = $f->getFilename();
        if (strpos($b, 'plugin.sqlite3') === 0) continue;            // the record: sha256 and size live there by design
        $c = (string)@file_get_contents($f->getPathname());
        foreach ($needles as $n) if ($n !== '' && strpos($c, $n) !== false) { $hits[] = substr($f->getPathname(), strlen($dir)); break; }
    }
    return $hits;
}
function mf_row(PDO $pdo, string $waId): array
{
    $st = $pdo->prepare('SELECT * FROM wa_media WHERE wa_message_id = ?'); $st->execute([$waId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($r) ? $r : [];
}
function mf_rowById(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT * FROM wa_media WHERE id = ?'); $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($r) ? $r : [];
}
function mf_brief(array $r): array
{
    return ['status' => $r['status'] ?? null, 'reason' => $r['failure_reason'] ?? null, 'bytes' => isset($r['fetched_bytes']) ? (int)$r['fetched_bytes'] : null,
            'sha' => $r['sha256'] ?? null, 'mime' => $r['fetched_mimetype'] ?? null, 'attempts' => (int)($r['attempts'] ?? 0)];
}

// ── Scenario 1: core, in-process against a fake Evolution ────────────────────────────────────────
function mf_core(string $root, int $evoPort, bool $fast): array
{
    $tmp = sys_get_temp_dir() . '/dn_mf_' . bin2hex(random_bytes(4));
    @mkdir($tmp, 0700, true);
    mf_hit($evoPort, '/__test/reset');
    $store = SqliteStore::create($tmp);
    $pdo   = $store->getPdo();
    $svc   = new ConversationService($tmp, $pdo);
    $bus   = new EventBus($pdo);
    $cfg = ['evo_api_url' => "http://127.0.0.1:{$evoPort}", 'evo_api_key' => 'TESTKEY', 'evo_instance_sales' => 'dishnet_ug',
            'evo_instance_support' => 'dishnet_ug', 'ai_enabled' => '1', 'ai_media_enabled' => '1',
            'ai_media_max_bytes' => 65536, 'ai_media_timeout_s' => 3, 'data_dir' => $tmp];
    $f = ['tmp' => $tmp];
    $calls = function () use ($evoPort): array { return mf_state($evoPort)['media_fetch_calls'] ?? []; };
    $media = function (array $spec) use ($evoPort): void { mf_hit($evoPort, '/__test/media', $spec); };
    $brief = function (array $r): array { return ['ok' => !empty($r['ok']), 'reason' => $r['reason'] ?? null, 'retryable' => $r['retryable'] ?? null]; };

    // ── N. One shape for every envelope ─────────────────────────────────────────────────────────
    $a = InboundMedia::fromEvoMessage(mf_envelope('audio', ['id' => 'MF-N-AUDIO'])['data']);
    $f['n_audio']   = $a;
    $f['n_image']   = InboundMedia::fromEvoMessage(mf_envelope('image', ['id' => 'MF-N-IMG', 'caption' => 'Here is my roof'])['data']);
    $f['n_doc']     = InboundMedia::fromEvoMessage(mf_envelope('document', ['id' => 'MF-N-DOC'])['data']);
    $f['n_video']   = InboundMedia::fromEvoMessage(mf_envelope('video', ['id' => 'MF-N-VID'])['data']);
    $f['n_sticker'] = InboundMedia::fromEvoMessage(mf_envelope('sticker')['data']);
    $f['n_text']    = InboundMedia::fromEvoMessage(mf_envelope('text')['data']);
    $noKey = mf_envelope('audio')['data']; unset($noKey['key']['id']);
    $f['n_nokey']   = InboundMedia::fromEvoMessage($noKey);
    $wrapped = mf_envelope('document', ['id' => 'MF-N-WRAP', 'caption' => 'Our site plan'])['data'];
    $wrapped['message'] = ['documentWithCaptionMessage' => ['message' => $wrapped['message']]];
    $f['n_wrapped'] = InboundMedia::fromEvoMessage($wrapped);
    $f['n_placeholder'] = InboundMedia::placeholder('audio');

    // ── P. The policy: every number in one place ────────────────────────────────────────────────
    $f['p_default_max']     = MediaPolicy::maxBytes([]);
    $f['p_default_timeout'] = MediaPolicy::timeoutSeconds([]);
    $f['p_max_override']    = MediaPolicy::maxBytes(['ai_media_max_bytes' => '1048576']);
    $f['p_max_clamp']       = [MediaPolicy::maxBytes(['ai_media_max_bytes' => 10]), MediaPolicy::maxBytes(['ai_media_max_bytes' => PHP_INT_MAX])];
    $f['p_timeout_clamp']   = [MediaPolicy::timeoutSeconds(['ai_media_timeout_s' => 0]), MediaPolicy::timeoutSeconds(['ai_media_timeout_s' => 999])];
    $f['p_junk']            = [MediaPolicy::maxBytes(['ai_media_max_bytes' => 'lots']), MediaPolicy::timeoutSeconds(['ai_media_timeout_s' => ''])];
    $en = [];
    foreach (['1', 'true', 'yes', 'on', true, '0', '', 'no', 'false', false, null, 'maybe'] as $i => $v) $en[] = MediaPolicy::enabled(['ai_media_enabled' => $v]);
    $f['p_enabled'] = $en;
    $f['p_enabled_unset'] = MediaPolicy::enabled([]);
    $f['p_base_mime'] = MediaPolicy::baseMime(' Audio/OGG; codecs=opus ');
    $f['p_allowed'] = [
        'audio ogg opus' => MediaPolicy::allowed('audio', 'audio/ogg; codecs=opus'), 'audio evil' => MediaPolicy::allowed('audio', 'audio/x-evil'),
        'image jpeg' => MediaPolicy::allowed('image', 'image/jpeg'), 'image svg' => MediaPolicy::allowed('image', 'image/svg+xml'),
        'doc pdf' => MediaPolicy::allowed('document', 'application/pdf'), 'doc exe' => MediaPolicy::allowed('document', 'application/x-msdownload'),
        'video mp4' => MediaPolicy::allowed('video', 'video/mp4'), 'audio as image' => MediaPolicy::allowed('image', 'audio/ogg'),
        'empty' => MediaPolicy::allowed('audio', ''),
    ];
    $f['p_kinds'] = array_map([MediaPolicy::class, 'kindSupported'], ['audio' => 'audio', 'image' => 'image', 'document' => 'document', 'video' => 'video', 'sticker' => 'sticker']);

    // ── S. The record: one row per message id ───────────────────────────────────────────────────
    $f['s_table']  = (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'wa_media'")->fetchColumn();
    $f['s_unique'] = (bool)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = 'idx_wa_media_wa_message_id' AND sql LIKE 'CREATE UNIQUE%'")->fetchColumn();
    $conv = $svc->ensureConversation('256772000311', 'sales', 'Media Tester', 'test');
    $cid  = (int)$conv['id'];
    $f['s_conv_state_before'] = (string)($svc->getConversation($cid)['state'] ?? '');
    $msgRowId = $svc->storeMessage($cid, ['direction' => 'in', 'role' => 'customer', 'body' => '[AUDIO]', 'media_type' => 'audio', 'wa_message_id' => 'MF-N-AUDIO']);
    $id1 = InboundMedia::record($pdo, $cid, $a, 'dishnet_ug', 'sales', 'MF-N-AUDIO');
    try { $f['s_dup'] = InboundMedia::record($pdo, $cid, $a, 'dishnet_ug', 'sales', 'MF-N-AUDIO'); }
    catch (\Throwable $e) { $f['s_dup'] = 'threw ' . get_class($e); }
    $f['s_first_id'] = $id1;
    $f['s_rows']     = (int)$pdo->query('SELECT COUNT(*) FROM wa_media')->fetchColumn();
    $rowAudio        = mf_rowById($pdo, (int)$id1);
    $f['s_row'] = ['status' => $rowAudio['status'] ?? null, 'kind' => $rowAudio['kind'] ?? null, 'mimetype' => $rowAudio['mimetype'] ?? null,
                   'declared' => (int)($rowAudio['declared_bytes'] ?? 0), 'seconds' => (int)($rowAudio['seconds'] ?? 0), 'jid' => $rowAudio['remote_jid'] ?? null,
                   'from_me' => (int)($rowAudio['from_me'] ?? -1), 'channel' => $rowAudio['channel'] ?? null, 'instance' => $rowAudio['instance'] ?? null,
                   'message_id' => (int)($rowAudio['message_id'] ?? 0), 'message_row' => (int)$msgRowId, 'conversation' => (int)($rowAudio['conversation_id'] ?? 0), 'cid' => $cid];
    $vid = InboundMedia::record($pdo, $cid, $f['n_video'], 'dishnet_ug', 'sales', 'MF-N-VID');
    $f['s_video'] = mf_brief(mf_rowById($pdo, (int)$vid));
    $f['s_blank'] = InboundMedia::record($pdo, $cid, $a, 'dishnet_ug', 'sales', '');

    // ── F. The fetcher against the fake ─────────────────────────────────────────────────────────
    $evo     = new EvolutionApiService($cfg, MediaPolicy::timeoutSeconds($cfg));
    $fetcher = new MediaFetcher($evo, $cfg);
    $media(['bytes' => 2048, 'mimetype' => 'audio/ogg; codecs=opus']);
    $r = $fetcher->fetch($rowAudio);
    $blob = $r['blob'] ?? null;
    $f['f_ok'] = ['ok' => !empty($r['ok']), 'size' => $blob ? $blob->size : null, 'sha' => $blob ? $blob->sha256 : null, 'mime' => $blob ? $blob->mimetype : null,
                  'kind' => $blob ? $blob->kind : null, 'describe_has_bytes' => $blob ? strpos($blob->describe(), 'DN-MEDIA') !== false : null];
    $f['f_sha_expected'] = hash('sha256', mf_payload(2048));
    if ($blob) { $blob->wipe(); $f['f_wiped'] = [$blob->bytes === '', $blob->sha256 === $f['f_sha_expected'], $blob->size]; }
    $c = $calls(); $last = $c ? end($c) : [];
    $f['f_call'] = ['n' => count($c), 'id' => $last['id'] ?? null, 'jid' => $last['remoteJid'] ?? null, 'fromMe' => $last['fromMe'] ?? null,
                    'mp4' => $last['convertToMp4'] ?? 'absent', 'instance' => $last['instance'] ?? null];
    $media(['bytes' => 2048, 'mimetype' => 'audio/x-evil']);
    $f['f_bad_mime'] = $brief($fetcher->fetch($rowAudio));
    $n0 = count($calls());
    $f['f_declared'] = $brief($fetcher->fetch(array_merge($rowAudio, ['declared_bytes' => 20000000]))) + ['calls_added' => count($calls()) - $n0];
    $media(['bytes' => 70000, 'mimetype' => 'audio/ogg; codecs=opus']);
    $n0 = count($calls());
    $f['f_actual'] = $brief($fetcher->fetch(array_merge($rowAudio, ['declared_bytes' => null]))) + ['calls_added' => count($calls()) - $n0];
    $media(['fail' => 500]);
    $f['f_500'] = $brief($fetcher->fetch($rowAudio));
    $media(['fail' => 404]);
    $f['f_404'] = $brief($fetcher->fetch($rowAudio));
    $media(['raw' => '{"base64":"***not base64***","mimetype":"audio/ogg"}']);
    $f['f_malformed'] = $brief($fetcher->fetch($rowAudio));
    $media(['raw' => 'oops not json']);
    $f['f_notjson'] = $brief($fetcher->fetch($rowAudio));
    $media(['bytes' => 2048, 'mimetype' => 'audio/ogg; codecs=opus']);
    $n0 = count($calls());
    $f['f_noid']  = $brief($fetcher->fetch(array_merge($rowAudio, ['wa_message_id' => '']))) + ['calls_added' => count($calls()) - $n0];
    $f['f_nojid'] = $brief($fetcher->fetch(array_merge($rowAudio, ['remote_jid' => ''])));
    $f['f_video'] = $brief($fetcher->fetch(array_merge($rowAudio, ['kind' => 'video']))) + ['calls_added' => count($calls()) - $n0];
    // A timeout as curl reports it (the generic path) and as the Uganda path reports it — classified without a socket.
    $timedOut = function (string $error) { return new class($error) {
        private $e; public function __construct(string $e) { $this->e = $e; }
        public function getBase64FromMediaMessage(string $c, array $k): array { return ['ok' => false, 'http' => 0, 'data' => [], 'error' => $this->e]; }
    }; };
    $f['f_timeout_double'] = $brief((new MediaFetcher($timedOut('Connection failed: Operation timed out after 3001 milliseconds with 0 bytes received'), $cfg))->fetch($rowAudio));
    $f['f_timeout_ug']     = $brief((new MediaFetcher($timedOut('May have been sent — no answer from Evolution: Operation timed out after 3000 milliseconds'), $cfg))->fetch($rowAudio));
    $f['f_noconn']         = $brief((new MediaFetcher($timedOut('Connection failed: Failed to connect to 127.0.0.1 port 1: Connection refused'), $cfg))->fetch($rowAudio));

    // ── K. The worker ───────────────────────────────────────────────────────────────────────────
    mf_hit($evoPort, '/__test/reset');
    $emit = function (int $mediaId, int $conv, string $waId, string $kind) use ($bus): int {
        return $bus->emit('ai.media', 'conversation', $conv, ['media_id' => $mediaId, 'conversation_id' => $conv, 'channel' => 'sales',
            'whatsapp_instance' => 'dishnet_ug', 'wa_message_id' => $waId, 'kind' => $kind, 'has_caption' => false, 'received_at' => gmdate('c')], 3, 'test');
    };
    $svc->storeMessage($cid, ['direction' => 'in', 'role' => 'customer', 'body' => 'Here is my roof', 'media_type' => 'image', 'wa_message_id' => 'MF-N-IMG']);
    $svc->storeMessage($cid, ['direction' => 'in', 'role' => 'customer', 'body' => '[DOCUMENT]', 'media_type' => 'document', 'wa_message_id' => 'MF-N-DOC']);
    $idImg = InboundMedia::record($pdo, $cid, $f['n_image'], 'dishnet_ug', 'sales', 'MF-N-IMG');
    $idDoc = InboundMedia::record($pdo, $cid, $f['n_doc'],   'dishnet_ug', 'sales', 'MF-N-DOC');
    $emit((int)$id1, $cid, 'MF-N-AUDIO', 'audio'); $emit((int)$idImg, $cid, 'MF-N-IMG', 'image'); $emit((int)$idDoc, $cid, 'MF-N-DOC', 'document');
    $media(['for_id' => 'MF-N-AUDIO', 'bytes' => 4096, 'mimetype' => 'audio/ogg; codecs=opus']);
    $media(['for_id' => 'MF-N-IMG',   'bytes' => 3000, 'mimetype' => 'image/jpeg']);
    $media(['for_id' => 'MF-N-DOC',   'bytes' => 5000, 'mimetype' => 'application/pdf', 'fileName' => 'quote.pdf']);
    $run = function (array $c, &$log) use ($store) {
        $w = new MediaWorker($store, $c, 30, 10);
        ob_start(); try { $r = $w->run(); } finally { $log .= (string)ob_get_clean(); }
        return $r;
    };
    $log = '';
    $f['k_run1'] = $run($cfg, $log);
    $f['k_rows'] = ['audio' => mf_brief(mf_rowById($pdo, (int)$id1)), 'image' => mf_brief(mf_rowById($pdo, (int)$idImg)), 'doc' => mf_brief(mf_rowById($pdo, (int)$idDoc))];
    $f['k_sha']  = ['audio' => hash('sha256', mf_payload(4096)), 'image' => hash('sha256', mf_payload(3000)), 'doc' => hash('sha256', mf_payload(5000))];
    $f['k_events_done'] = (int)$pdo->query("SELECT COUNT(*) FROM events WHERE event_type = 'ai.media' AND status = 'done'")->fetchColumn();
    $f['k_calls'] = count($calls());
    $b64Needle = base64_encode('DN-MEDIA-TEST-PAYLOAD');   // the start of every served payload's base64
    $f['k_log'] = ['has_b64' => strpos($log, $b64Needle) !== false, 'has_payload' => strpos($log, 'DN-MEDIA-TEST') !== false,
                   'has_jid' => strpos($log, '@s.whatsapp.net') !== false, 'has_sha' => strpos($log, 'sha256=') !== false,
                   'has_fetched' => preg_match('/media #\d+ \(conversation \d+\): fetched audio audio\/ogg 4096 bytes/', $log) === 1];
    $f['k_disk'] = mf_scan($tmp, [$b64Needle, 'DN-MEDIA-TEST-PAYLOAD', $f['k_sha']['audio']]);
    // a duplicate event names a fetched row: nothing is fetched again
    $emit((int)$id1, $cid, 'MF-N-AUDIO', 'audio');
    $log2 = '';
    $f['k_dup'] = $run($cfg, $log2) + ['calls' => count($calls()), 'log_dup' => strpos($log2, 'duplicate event, nothing fetched') !== false,
                                        'row' => mf_brief(mf_rowById($pdo, (int)$id1))];
    // the flag off at the worker: skipped, acknowledged, nothing fetched
    $svc->storeMessage($cid, ['direction' => 'in', 'role' => 'customer', 'body' => '[AUDIO]', 'media_type' => 'audio', 'wa_message_id' => 'MF-K-OFF']);
    $idOff = InboundMedia::record($pdo, $cid, InboundMedia::fromEvoMessage(mf_envelope('audio', ['id' => 'MF-K-OFF'])['data']), 'dishnet_ug', 'sales', 'MF-K-OFF');
    $evOff = $emit((int)$idOff, $cid, 'MF-K-OFF', 'audio');
    $media(['for_id' => 'MF-K-OFF', 'bytes' => 1000, 'mimetype' => 'audio/ogg']);
    $n0 = count($calls()); $log3 = '';
    $f['k_off'] = $run(array_merge($cfg, ['ai_media_enabled' => '0']), $log3) + ['row' => mf_brief(mf_rowById($pdo, (int)$idOff)), 'calls_added' => count($calls()) - $n0,
        'event' => (string)$pdo->query("SELECT status FROM events WHERE id = {$evOff}")->fetchColumn(), 'log_off' => strpos($log3, 'ai_media_enabled is off') !== false];
    // a transient failure is retried through the EventBus, and the second attempt succeeds
    $svc->storeMessage($cid, ['direction' => 'in', 'role' => 'customer', 'body' => '[AUDIO]', 'media_type' => 'audio', 'wa_message_id' => 'MF-K-RETRY']);
    $idRe = InboundMedia::record($pdo, $cid, InboundMedia::fromEvoMessage(mf_envelope('audio', ['id' => 'MF-K-RETRY'])['data']), 'dishnet_ug', 'sales', 'MF-K-RETRY');
    $evRe = $emit((int)$idRe, $cid, 'MF-K-RETRY', 'audio');
    $media(['for_id' => 'MF-K-RETRY', 'fail' => 500]);
    $log4 = '';
    $r4 = $run($cfg, $log4);
    $ev = $pdo->query("SELECT status, attempts, error FROM events WHERE id = {$evRe}")->fetch(PDO::FETCH_ASSOC);
    $f['k_retry_1'] = ['run' => $r4, 'row' => mf_brief(mf_rowById($pdo, (int)$idRe)), 'event' => $ev,
                       'error_has_jid' => strpos((string)($ev['error'] ?? ''), '@s.whatsapp.net') !== false];
    $pdo->exec("UPDATE events SET next_retry_at = datetime('now', '-1 second') WHERE id = {$evRe}");
    $media(['for_id' => 'MF-K-RETRY', 'bytes' => 1500, 'mimetype' => 'audio/ogg; codecs=opus']);
    $log5 = '';
    $r5 = $run($cfg, $log5);
    $f['k_retry_2'] = ['run' => $r5, 'row' => mf_brief(mf_rowById($pdo, (int)$idRe)), 'event' => $pdo->query("SELECT status, attempts FROM events WHERE id = {$evRe}")->fetch(PDO::FETCH_ASSOC),
                       'sha_expected' => hash('sha256', mf_payload(1500))];
    // the queue gives up: dead row, the conversation handed to a person, nothing sent
    $conv2 = $svc->ensureConversation('256772000322', 'sales', 'Dead Letter', 'test');
    $cid2  = (int)$conv2['id'];
    $svc->storeMessage($cid2, ['direction' => 'in', 'role' => 'customer', 'body' => '[AUDIO]', 'media_type' => 'audio', 'wa_message_id' => 'MF-K-DEAD']);
    $idDead = InboundMedia::record($pdo, $cid2, InboundMedia::fromEvoMessage(mf_envelope('audio', ['id' => 'MF-K-DEAD', 'phone' => '256772000322'])['data']), 'dishnet_ug', 'sales', 'MF-K-DEAD');
    $evDead = $emit((int)$idDead, $cid2, 'MF-K-DEAD', 'audio');
    $pdo->exec("UPDATE events SET max_attempts = 1 WHERE id = {$evDead}");
    $media(['for_id' => 'MF-K-DEAD', 'fail' => 503]);
    $log6 = '';
    $r6 = $run($cfg, $log6);
    $f['k_dead'] = ['run' => $r6, 'row' => mf_brief(mf_rowById($pdo, (int)$idDead)),
                    'event' => (string)$pdo->query("SELECT status FROM events WHERE id = {$evDead}")->fetchColumn(),
                    'conv2_state' => (string)($svc->getConversation($cid2)['state'] ?? ''), 'conv1_state' => (string)($svc->getConversation($cid)['state'] ?? ''),
                    'log_handed' => strpos($log6, 'handed to a person') !== false];
    // locks: the media worker's own, and the reply worker's is another file
    $lockM = fopen($tmp . '/MediaWorker.lock', 'c+'); flock($lockM, LOCK_EX);
    $log7 = '';
    $f['k_lock_media'] = $run($cfg, $log7);
    flock($lockM, LOCK_UN); fclose($lockM);
    $lockA = fopen($tmp . '/AiReplyWorker.lock', 'c+'); flock($lockA, LOCK_EX);
    $emit((int)$id1, $cid, 'MF-N-AUDIO', 'audio');   // one more duplicate, so the run has an event to process
    $log8 = '';
    $f['k_lock_ai_held'] = $run($cfg, $log8);
    flock($lockA, LOCK_UN); fclose($lockA);
    $f['k_lock_files'] = [is_file($tmp . '/MediaWorker.lock'), is_file($tmp . '/AiReplyWorker.lock')];
    $f['k_text_sent'] = count(mf_state($evoPort)['text_calls'] ?? []);
    $f['k_media_sent'] = count(mf_state($evoPort)['media_calls'] ?? []);

    // ── F10. One real timeout, last, because the fake is busy afterwards ────────────────────────
    if (!$fast) {
        $media(['for_id' => 'MF-N-AUDIO', 'bytes' => 1000, 'mimetype' => 'audio/ogg; codecs=opus', 'hold' => 5]);
        $t0 = microtime(true);
        $f['f_timeout_real'] = $brief($fetcher->fetch($rowAudio)) + ['elapsed' => round(microtime(true) - $t0, 1)];
        $media(['for_id' => 'MF-N-AUDIO', 'bytes' => 10]);
        mf_state($evoPort);   // waits until the fake has finished sleeping for the attempts it was given
    }
    exec('rm -rf ' . escapeshellarg($tmp));
    return $f;
}

// ── Scenario 2: the webhook, the real plugin under php -S ────────────────────────────────────────
function mf_webhook(string $root, bool $fast): array
{
    $s = SjSandbox::start($root, ['ai_enabled' => '1', 'ai_media_enabled' => '0', 'tenant_profile' => 'uganda', 'ai_currency' => 'UGX',
        'ai_provider' => 'openai', 'openai_api_key' => 'test-key-never-called', 'ai_media_max_bytes' => 65536, 'ai_media_timeout_s' => 3], 'mf');
    $pdo = $s->store()->getPdo();
    $f = ['sb' => $s->sb];
    $post  = function (array $env) use ($s): array { return $s->http('POST', "{$s->base}?page=evo_webhook", $env, ['Content-Type: application/json', 'X-DishNet-Token: ' . $s->evoKey]); };
    $flag  = function (array $ov) use ($s): void { $cfg = array_merge($s->cfg, $ov); $s->store()->save('kyc_config.json', $cfg); file_put_contents($s->data . '/kyc_config.json', json_encode($cfg)); };
    $count = function (string $sql) use ($pdo): int { return (int)$pdo->query($sql)->fetchColumn(); };
    $msg   = function (string $waId) use ($pdo): array { $st = $pdo->prepare('SELECT id, body, media_type FROM wa_messages WHERE wa_message_id = ?'); $st->execute([$waId]); $r = $st->fetch(PDO::FETCH_ASSOC); return is_array($r) ? $r : []; };
    $ans   = function (array $r): array { return ['http' => $r[0], 'outcome' => $r[2]['outcome'] ?? null, 'queued' => $r[2]['queued'] ?? null, 'skipped' => $r[2]['skipped'] ?? null, 'media_queued' => $r[2]['media_queued'] ?? 'absent']; };
    $evoPort = (int)parse_url($s->evo, PHP_URL_PORT);

    // W1 — the flag OFF: the message is stored as before, and nothing else happens
    $r = $post(mf_envelope('audio', ['id' => 'MF-W-OFF']));
    $f['w1'] = $ans($r) + ['msg' => $msg('MF-W-OFF'), 'wa_media' => $count('SELECT COUNT(*) FROM wa_media'), 'events' => $count("SELECT COUNT(*) FROM events WHERE event_type = 'ai.media'")];
    // W2 — ON: the row and the event
    $flag(['ai_media_enabled' => '1']);
    $r = $post(mf_envelope('audio', ['id' => 'MF-W-AUDIO']));
    $row = mf_row($pdo, 'MF-W-AUDIO');
    $evt = $pdo->query("SELECT payload, status FROM events WHERE event_type = 'ai.media' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $p0  = json_decode((string)($evt[0]['payload'] ?? ''), true) ?: [];
    $f['w2'] = $ans($r) + ['row' => ['status' => $row['status'] ?? null, 'kind' => $row['kind'] ?? null, 'mimetype' => $row['mimetype'] ?? null, 'declared' => (int)($row['declared_bytes'] ?? 0),
                                     'seconds' => (int)($row['seconds'] ?? 0), 'jid' => $row['remote_jid'] ?? null, 'from_me' => (int)($row['from_me'] ?? -1), 'channel' => $row['channel'] ?? null,
                                     'instance' => $row['instance'] ?? null, 'message_id' => (int)($row['message_id'] ?? 0), 'attempts' => (int)($row['attempts'] ?? -1)],
                           'msg' => $msg('MF-W-AUDIO'), 'events' => count($evt), 'payload_media_id' => (int)($p0['media_id'] ?? 0), 'row_id' => (int)($row['id'] ?? 0),
                           'payload_keys' => array_keys($p0), 'payload_conv' => (int)($p0['conversation_id'] ?? 0), 'row_conv' => (int)($row['conversation_id'] ?? 0)];
    // W3 — the same delivery again
    $r = $post(mf_envelope('audio', ['id' => 'MF-W-AUDIO']));
    $f['w3'] = $ans($r) + ['wa_media' => $count('SELECT COUNT(*) FROM wa_media'), 'events' => $count("SELECT COUNT(*) FROM events WHERE event_type = 'ai.media'")];
    // W4 — a captioned photo: answered as text exactly as before, AND recorded
    $before = $count("SELECT COUNT(*) FROM events WHERE event_type = 'ai.reply'");
    $r = $post(mf_envelope('image', ['id' => 'MF-W-IMG', 'caption' => 'Here is my roof']));
    $rp = $pdo->query("SELECT payload FROM events WHERE event_type = 'ai.reply' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $rp = json_decode((string)$rp, true) ?: [];
    $rowI = mf_row($pdo, 'MF-W-IMG');
    $f['w4'] = $ans($r) + ['ai_reply_added' => $count("SELECT COUNT(*) FROM events WHERE event_type = 'ai.reply'") - $before, 'reply_message' => $rp['message'] ?? null,
                           'reply_phone' => $rp['customer_phone'] ?? null, 'row' => ['kind' => $rowI['kind'] ?? null, 'caption' => $rowI['caption'] ?? null, 'status' => $rowI['status'] ?? null], 'msg' => $msg('MF-W-IMG')];
    // W5 — a document
    $r = $post(mf_envelope('document', ['id' => 'MF-W-DOC']));
    $rowD = mf_row($pdo, 'MF-W-DOC');
    $f['w5'] = $ans($r) + ['row' => ['kind' => $rowD['kind'] ?? null, 'file_name' => $rowD['file_name'] ?? null, 'status' => $rowD['status'] ?? null, 'mimetype' => $rowD['mimetype'] ?? null], 'msg' => $msg('MF-W-DOC')];
    // W6 — a video: recorded as unsupported, no event, never fetched
    $ev0 = $count("SELECT COUNT(*) FROM events WHERE event_type = 'ai.media'");
    $r = $post(mf_envelope('video', ['id' => 'MF-W-VID']));
    $rowV = mf_row($pdo, 'MF-W-VID');
    $f['w6'] = $ans($r) + ['row' => mf_brief($rowV), 'events_added' => $count("SELECT COUNT(*) FROM events WHERE event_type = 'ai.media'") - $ev0, 'msg' => $msg('MF-W-VID')];
    // W7 — text only: the text path, no record
    $n0 = $count('SELECT COUNT(*) FROM wa_media');
    $r = $post(mf_envelope('text', ['id' => 'MF-W-TEXT']));
    $f['w7'] = $ans($r) + ['wa_media_added' => $count('SELECT COUNT(*) FROM wa_media') - $n0];
    // W8 — OFF again: a second audio is stored and nothing is recorded
    $flag(['ai_media_enabled' => '0']);
    $n0 = $count('SELECT COUNT(*) FROM wa_media'); $ev0 = $count("SELECT COUNT(*) FROM events WHERE event_type = 'ai.media'");
    $r = $post(mf_envelope('audio', ['id' => 'MF-W-OFF2']));
    $f['w8'] = $ans($r) + ['wa_media_added' => $count('SELECT COUNT(*) FROM wa_media') - $n0, 'events_added' => $count("SELECT COUNT(*) FROM events WHERE event_type = 'ai.media'") - $ev0, 'msg' => $msg('MF-W-OFF2')];

    // CS — tools/cron_status.php: the ai_media job exists only while the flag is on (the flag is OFF here, after W8)
    $csJobs = function () use ($s): array {
        [$rc, $out] = $s->run('tools/cron_status.php', ['--all']);
        preg_match_all('/^\s+(\d+)\s+([a-z0-9_]+)\s+\d+s\*?\s/m', $out, $m, PREG_SET_ORDER);
        $jobs = [];
        foreach ($m as $r) $jobs[(int)$r[1]] = $r[2];
        ksort($jobs);
        return ['rc' => $rc, 'jobs' => array_values($jobs), 'head' => substr($out, 0, 300)];
    };
    $f['cs_off'] = $csJobs();
    $flag(['ai_media_enabled' => '1']);
    $f['cs_on'] = $csJobs();

    // R — run_media_worker.php over the CLI, inside the sandbox's plugin directory
    foreach ([['MF-W-AUDIO', 2222, 'audio/ogg; codecs=opus', ''], ['MF-W-IMG', 3333, 'image/jpeg', ''], ['MF-W-DOC', 4444, 'application/pdf', 'quote.pdf']] as [$id, $n, $mime, $name]) {
        $s->http('POST', "{$s->evo}/__test/media", ['for_id' => $id, 'bytes' => $n, 'mimetype' => $mime, 'fileName' => $name]);
    }
    // stdout only: the runner must print nothing (its trace goes to ai_platform.log). stderr carries the plugin's own
    // error_log lines — ConfigVault announcing a restored vault on this fresh sandbox, for one — which are not the runner's.
    $runner = function () use ($s): string {
        return (string)shell_exec('cd ' . escapeshellarg($s->plug) . ' && DN_DATA_DIR=' . escapeshellarg($s->data) . ' DN_VAULT_FILE=' . escapeshellarg($s->vault) . ' php run_media_worker.php 2>/dev/null');
    };
    $out1 = $runner();
    $logFile = $s->data . '/ai_platform.log';
    $log  = (string)@file_get_contents($logFile);
    $b64Needle = base64_encode('DN-MEDIA-TEST-PAYLOAD');
    $f['r1'] = ['out' => trim($out1), 'rows' => ['audio' => mf_brief(mf_row($pdo, 'MF-W-AUDIO')), 'image' => mf_brief(mf_row($pdo, 'MF-W-IMG')), 'doc' => mf_brief(mf_row($pdo, 'MF-W-DOC'))],
                'sha' => ['audio' => hash('sha256', mf_payload(2222)), 'image' => hash('sha256', mf_payload(3333)), 'doc' => hash('sha256', mf_payload(4444))],
                'events_done' => $count("SELECT COUNT(*) FROM events WHERE event_type = 'ai.media' AND status = 'done'"),
                'events_pending' => $count("SELECT COUNT(*) FROM events WHERE event_type = 'ai.media' AND status IN ('pending', 'failed', 'processing')"),
                'fetch_calls' => count(mf_state($evoPort)['media_fetch_calls'] ?? []),
                'log' => ['worker_line' => strpos($log, '[MediaWorker]') !== false, 'summary' => preg_match('/media worker: processed=3 failed=0 deferred=0/', $log) === 1,
                          'has_b64' => strpos($log, $b64Needle) !== false, 'has_payload' => strpos($log, 'DN-MEDIA-TEST') !== false, 'has_jid' => strpos($log, '@s.whatsapp.net') !== false],
                'disk' => mf_scan($s->sb, [$b64Needle, 'DN-MEDIA-TEST-PAYLOAD', hash('sha256', mf_payload(2222))])];
    // R2 — the flag OFF and nothing pending: the runner returns after one read and writes nothing
    $flag(['ai_media_enabled' => '0']);
    $size0 = (int)@filesize($logFile);
    $out2  = $runner();
    clearstatcache();
    $f['r2'] = ['out' => trim($out2), 'log_grew' => (int)@filesize($logFile) > $size0, 'fetch_calls' => count(mf_state($evoPort)['media_fetch_calls'] ?? [])];
    // R3 — ai_enabled OFF: the runner does nothing even with the media flag on and work pending
    $flag(['ai_media_enabled' => '1', 'ai_enabled' => '0']);
    $r = $post(mf_envelope('audio', ['id' => 'MF-W-AIOFF']));
    $s->http('POST', "{$s->evo}/__test/media", ['for_id' => 'MF-W-AIOFF', 'bytes' => 100, 'mimetype' => 'audio/ogg']);
    $out3 = $runner();
    $f['r3'] = ['media_queued' => $r[2]['media_queued'] ?? null, 'out' => trim($out3), 'row' => mf_brief(mf_row($pdo, 'MF-W-AIOFF')),
                'fetch_calls' => count(mf_state($evoPort)['media_fetch_calls'] ?? [])];
    $f['texts'] = count($s->texts());
    $f['media_sends'] = count(mf_state($evoPort)['media_calls'] ?? []);
    $s->stop();
    return $f;
}

// ── Driver mode: one JSON line, nothing else ─────────────────────────────────────────────────────
if ($driver !== null) {
    if ($driver === 'webhook') {
        $facts = mf_webhook($root, $fast);
    } else {
        $port = (int)getenv('DN_T_EVO_PORT');
        $srv = null;
        if ($port <= 0) { [$srv, $port] = mf_boot($root . '/tests/fixtures/fake_evo_server.php', 9870, 'FAKE-EVO-TEST'); }
        $facts = mf_core($root, $port, $fast);
        if (is_resource($srv)) { proc_terminate($srv); proc_close($srv); }
    }
    echo json_encode($facts), "\n";
    exit(0);
}

// ── Main ─────────────────────────────────────────────────────────────────────────────────────────
$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d !== '' ? "\n       " . substr($d, 0, 900) : '') . "\n"; } }
function j($v): string { return json_encode($v, JSON_UNESCAPED_SLASHES); }

array_map('unlink', glob(sys_get_temp_dir() . '/fake_evo_state_*.json') ?: []);
[$evoSrv, $evoPort] = mf_boot($root . '/tests/fixtures/fake_evo_server.php', 9860, 'FAKE-EVO-TEST');
if (!$evoPort) { echo "FAIL could not start the fake Evolution server\n0 passed, 1 failed\n"; exit(1); }

echo "A. Normalisation — one shape for every envelope (core scenario)\n";
$c = mf_core($root, $evoPort, false);
$a = $c['n_audio'];
is_(($a['kind'] ?? '') === 'audio' && ($a['mimetype'] ?? '') === 'audio/ogg; codecs=opus' && !empty($a['ptt']) && ($a['seconds'] ?? 0) === 7 && ($a['declared_bytes'] ?? 0) === 12345,
    'a voice note: kind audio, the announced type, ptt, seconds, announced size', j($a));
is_(($a['key']['id'] ?? '') === 'MF-N-AUDIO' && ($a['key']['remote_jid'] ?? '') === '256772000311@s.whatsapp.net' && ($a['key']['from_me'] ?? true) === false && ($a['message_type'] ?? '') === 'audioMessage',
    'its key — id, remoteJid, fromMe — travels whole, with the envelope key named', j($a['key'] ?? null));
is_(($c['n_image']['kind'] ?? '') === 'image' && ($c['n_image']['caption'] ?? '') === 'Here is my roof' && ($c['n_image']['mimetype'] ?? '') === 'image/jpeg' && ($c['n_image']['file_name'] ?? 'x') === '',
    'a captioned photo: kind image, the caption kept, no file name', j($c['n_image']));
is_(($c['n_doc']['kind'] ?? '') === 'document' && ($c['n_doc']['file_name'] ?? '') === 'quote.pdf' && ($c['n_doc']['mimetype'] ?? '') === 'application/pdf' && ($c['n_doc']['declared_bytes'] ?? 0) === 44000,
    'a document: kind document, its file name and type and size', j($c['n_doc']));
is_(($c['n_video']['kind'] ?? '') === 'video' && ($c['n_sticker']['kind'] ?? '') === 'sticker', 'video and sticker are named, so they can be recorded as unsupported', j([$c['n_video']['kind'] ?? null, $c['n_sticker']['kind'] ?? null]));
is_($c['n_text'] === null, 'a text message is not media: null');
is_(is_array($c['n_nokey']) && ($c['n_nokey']['key']['id'] ?? 'x') === '', 'a missing key id normalises to an empty id (the fetcher refuses it; nothing guesses)', j($c['n_nokey']['key'] ?? null));
is_(($c['n_wrapped']['kind'] ?? '') === 'document' && ($c['n_wrapped']['caption'] ?? '') === 'Our site plan', 'a documentWithCaptionMessage wrapper is unwrapped', j($c['n_wrapped']));
is_($c['n_placeholder'] === '[AUDIO]', 'the placeholder body is the one the conversation store always wrote');

echo "\nB. The policy — every number in one place, the flag OFF unless set\n";
is_($c['p_default_max'] === 15 * 1024 * 1024 && $c['p_default_timeout'] === 20, 'defaults: 15 MiB, 20 s', j([$c['p_default_max'], $c['p_default_timeout']]));
is_($c['p_max_override'] === 1048576, 'ai_media_max_bytes is read', j($c['p_max_override']));
is_($c['p_max_clamp'] === [65536, 64 * 1024 * 1024] && $c['p_timeout_clamp'] === [3, 60], 'both limits are clamped to their range', j([$c['p_max_clamp'], $c['p_timeout_clamp']]));
is_($c['p_junk'] === [15 * 1024 * 1024, 20], 'a value that is not a number means the default', j($c['p_junk']));
is_($c['p_enabled'] === [true, true, true, true, true, false, false, false, false, false, false, false] && $c['p_enabled_unset'] === false,
    'enabled(): 1/true/yes/on only; unset, empty, 0, no, false and anything else are OFF', j($c['p_enabled']));
is_($c['p_base_mime'] === 'audio/ogg', 'baseMime strips the codecs parameter and case', j($c['p_base_mime']));
is_($c['p_allowed'] === ['audio ogg opus' => true, 'audio evil' => false, 'image jpeg' => true, 'image svg' => false, 'doc pdf' => true, 'doc exe' => false,
                          'video mp4' => false, 'audio as image' => false, 'empty' => false],
    'the allow-list is per kind: an audio type is not allowed for an image, SVG and executables never', j($c['p_allowed']));
is_($c['p_kinds'] === ['audio' => true, 'image' => true, 'document' => true, 'video' => false, 'sticker' => false], 'Batch 1 handles audio, image and document only', j($c['p_kinds']));

echo "\nC. The record — one wa_media row per message id\n";
is_($c['s_table'] && $c['s_unique'], 'migration 085: the table exists with a UNIQUE index on wa_message_id', j([$c['s_table'], $c['s_unique']]));
is_(is_int($c['s_first_id']) && $c['s_first_id'] > 0 && $c['s_dup'] === null && $c['s_rows'] === 1, 'the first record returns the id; the same message again returns null and adds no row', j([$c['s_first_id'], $c['s_dup'], $c['s_rows']]));
$sr = $c['s_row'];
is_($sr['status'] === 'pending' && $sr['kind'] === 'audio' && $sr['mimetype'] === 'audio/ogg; codecs=opus' && $sr['declared'] === 12345 && $sr['seconds'] === 7
    && $sr['jid'] === '256772000311@s.whatsapp.net' && $sr['from_me'] === 0 && $sr['channel'] === 'sales' && $sr['instance'] === 'dishnet_ug' && $sr['conversation'] === $sr['cid'],
    'the row carries what the fetch needs and what the webhook announced', j($sr));
is_($sr['message_id'] > 0 && $sr['message_id'] === $sr['message_row'], 'and points at the stored placeholder message', j([$sr['message_id'], $sr['message_row']]));
is_($c['s_video']['status'] === 'unsupported' && $c['s_video']['reason'] === 'unsupported_kind', 'a video is recorded as unsupported at once', j($c['s_video']));
is_($c['s_blank'] === null, 'no message id, no row');

echo "\nD. The fetcher — cheap refusals before any call, strict validation after\n";
is_($c['f_ok']['ok'] === true && $c['f_ok']['size'] === 2048 && $c['f_ok']['sha'] === $c['f_sha_expected'] && $c['f_ok']['mime'] === 'audio/ogg; codecs=opus' && $c['f_ok']['kind'] === 'audio',
    'a valid voice note: 2048 bytes in memory, the sha256 of exactly the bytes served, the type Evolution reported', j($c['f_ok']));
is_($c['f_ok']['describe_has_bytes'] === false && ($c['f_wiped'] ?? null) === [true, true, 2048], 'describe() never carries content; wipe() forgets the bytes and keeps hash and size', j([$c['f_ok']['describe_has_bytes'], $c['f_wiped'] ?? null]));
$fc = $c['f_call'];
is_($fc['n'] === 1 && $fc['id'] === 'MF-N-AUDIO' && $fc['jid'] === '256772000311@s.whatsapp.net' && $fc['fromMe'] === false && $fc['mp4'] === false && $fc['instance'] === 'dishnet_ug',
    'the call is getBase64FromMediaMessage on the right instance with the recorded key, convertToMp4 false', j($fc));
is_($c['f_bad_mime'] === ['ok' => false, 'reason' => 'unsupported_mime', 'retryable' => false], 'a type outside the allow-list is refused, not retried', j($c['f_bad_mime']));
is_($c['f_declared']['reason'] === 'too_large' && $c['f_declared']['retryable'] === false && $c['f_declared']['calls_added'] === 0, 'an announced size over the limit is refused BEFORE any call', j($c['f_declared']));
is_($c['f_actual']['reason'] === 'too_large' && $c['f_actual']['retryable'] === false && $c['f_actual']['calls_added'] === 1, 'an actual size over the limit is refused after the fetch', j($c['f_actual']));
is_($c['f_500'] === ['ok' => false, 'reason' => 'fetch_failed', 'retryable' => true], 'HTTP 500 from Evolution: fetch_failed, retried', j($c['f_500']));
is_($c['f_404'] === ['ok' => false, 'reason' => 'fetch_failed', 'retryable' => false], 'HTTP 404: fetch_failed, not retried', j($c['f_404']));
is_($c['f_malformed'] === ['ok' => false, 'reason' => 'malformed', 'retryable' => true] && $c['f_notjson'] === ['ok' => false, 'reason' => 'malformed', 'retryable' => true],
    'a payload that is not base64, or an answer that is not JSON, is malformed and retried', j([$c['f_malformed'], $c['f_notjson']]));
is_($c['f_noid']['reason'] === 'missing_identifier' && $c['f_noid']['calls_added'] === 0 && $c['f_nojid']['reason'] === 'missing_identifier', 'no message id or no remoteJid: missing_identifier, no call', j([$c['f_noid'], $c['f_nojid']]));
is_($c['f_video']['reason'] === 'unsupported_kind' && $c['f_video']['calls_added'] === 0, 'a video kind: unsupported_kind, no call', j($c['f_video']));
is_($c['f_timeout_double'] === ['ok' => false, 'reason' => 'timeout', 'retryable' => true] && $c['f_timeout_ug'] === ['ok' => false, 'reason' => 'timeout', 'retryable' => true],
    'a timeout is classified as timeout (retried) whichever request path reported it', j([$c['f_timeout_double'], $c['f_timeout_ug']]));
is_($c['f_noconn'] === ['ok' => false, 'reason' => 'fetch_failed', 'retryable' => true], 'no connection: fetch_failed, retried', j($c['f_noconn']));
$tr = $c['f_timeout_real'] ?? [];
is_(($tr['reason'] ?? '') === 'timeout' && ($tr['retryable'] ?? null) === true && ($tr['elapsed'] ?? 99) < 20, 'a real Evolution that does not answer: timeout within the configured seconds (' . ($tr['elapsed'] ?? '?') . ' s)', j($tr));

echo "\nE. The worker — fetched once, flag honoured, retried, dead, nothing sent, nothing kept\n";
$k = $c['k_rows'];
is_(($c['k_run1']['processed'] ?? 0) === 3 && ($c['k_run1']['failed'] ?? -1) === 0, 'one run processes the three pending media events', j($c['k_run1']));
is_($k['audio']['status'] === 'fetched' && $k['audio']['bytes'] === 4096 && $k['audio']['sha'] === $c['k_sha']['audio'] && $k['audio']['mime'] === 'audio/ogg; codecs=opus' && $k['audio']['attempts'] === 1,
    'the voice note: fetched, 4096 bytes, its sha256, one attempt', j($k['audio']));
is_($k['image']['status'] === 'fetched' && $k['image']['bytes'] === 3000 && $k['image']['sha'] === $c['k_sha']['image'] && $k['image']['mime'] === 'image/jpeg', 'the photo: fetched as image/jpeg', j($k['image']));
is_($k['doc']['status'] === 'fetched' && $k['doc']['bytes'] === 5000 && $k['doc']['sha'] === $c['k_sha']['doc'] && $k['doc']['mime'] === 'application/pdf', 'the document: fetched as application/pdf', j($k['doc']));
is_($c['k_events_done'] === 3 && $c['k_calls'] === 3, 'three events done, three fetches', j([$c['k_events_done'], $c['k_calls']]));
is_($c['k_log'] === ['has_b64' => false, 'has_payload' => false, 'has_jid' => false, 'has_sha' => true, 'has_fetched' => true],
    'the worker log names kind, type, size and a hash prefix — never the bytes, never the base64, never the JID', j($c['k_log']));
is_($c['k_disk'] === [], 'NO file anywhere in the data directory holds the media, its base64 or its hash — the database row is the only record', j($c['k_disk']));
is_(($c['k_dup']['processed'] ?? 0) === 1 && $c['k_dup']['calls'] === 3 && $c['k_dup']['log_dup'] === true && $c['k_dup']['row']['attempts'] === 1,
    'a second event for a fetched message is acknowledged with no second fetch', j($c['k_dup']));
$ko = $c['k_off'];
is_(($ko['processed'] ?? 0) === 1 && $ko['row']['status'] === 'skipped' && $ko['row']['reason'] === 'media_disabled' && $ko['calls_added'] === 0 && $ko['event'] === 'done' && $ko['log_off'] === true,
    'ai_media_enabled OFF at the worker: the row is skipped, the event acknowledged, nothing fetched', j($ko));
$k1 = $c['k_retry_1'];
is_(($k1['run']['failed'] ?? 0) === 1 && $k1['row']['status'] === 'failed' && $k1['row']['reason'] === 'fetch_failed' && ($k1['event']['status'] ?? '') === 'failed' && (int)($k1['event']['attempts'] ?? 0) === 1 && $k1['error_has_jid'] === false,
    'a 500 from Evolution: the row says failed/fetch_failed, the event is failed with one attempt, and its error names no JID', j($k1));
$k2 = $c['k_retry_2'];
is_(($k2['run']['processed'] ?? 0) === 1 && $k2['row']['status'] === 'fetched' && $k2['row']['bytes'] === 1500 && $k2['row']['sha'] === $k2['sha_expected'] && $k2['row']['attempts'] === 2 && ($k2['event']['status'] ?? '') === 'done',
    'when the retry is due and Evolution answers, the second attempt fetches it and the event is done', j($k2));
$kd = $c['k_dead'];
is_(($kd['run']['failed'] ?? 0) === 1 && $kd['row']['status'] === 'dead' && $kd['event'] === 'dead' && $kd['log_handed'] === true, 'the last attempt gone: the row and the event are dead', j($kd));
is_($kd['conv2_state'] === 'needs_human' && $kd['conv1_state'] === $c['s_conv_state_before'], 'that conversation is handed to a person; the other conversation is untouched', j([$kd['conv2_state'], $kd['conv1_state'], $c['s_conv_state_before']]));
is_(($c['k_lock_media']['skipped'] ?? false) === true, 'with MediaWorker.lock held, the media worker does not run', j($c['k_lock_media']));
is_(($c['k_lock_ai_held']['processed'] ?? 0) === 1 && $c['k_lock_files'] === [true, true], 'with AiReplyWorker.lock held, the media worker still runs — two workers, two locks', j([$c['k_lock_ai_held'], $c['k_lock_files']]));
is_($c['k_text_sent'] === 0 && $c['k_media_sent'] === 0, 'nothing was sent to any customer in the whole scenario', j([$c['k_text_sent'], $c['k_media_sent']]));

echo "\nF. The webhook — the real plugin under php -S\n";
$w = mf_webhook($root, false);
$w1 = $w['w1'];
is_($w1['http'] === 200 && $w1['outcome'] === 'accepted' && $w1['media_queued'] === 0 && $w1['skipped'] === 1 && ($w1['msg']['body'] ?? '') === '[AUDIO]' && ($w1['msg']['media_type'] ?? '') === 'audio',
    'flag OFF: a voice note is accepted and stored as [AUDIO], as before', j($w1));
is_($w1['wa_media'] === 0 && $w1['events'] === 0, 'flag OFF: no wa_media row, no ai.media event — nothing changes', j([$w1['wa_media'], $w1['events']]));
$w2 = $w['w2'];
is_($w2['http'] === 200 && $w2['media_queued'] === 1 && $w2['queued'] === 0 && $w2['skipped'] === 1, 'flag ON: a voice note answers media_queued 1 (the text queue untouched)', j($w2));
is_($w2['row']['status'] === 'pending' && $w2['row']['kind'] === 'audio' && $w2['row']['mimetype'] === 'audio/ogg; codecs=opus' && $w2['row']['declared'] === 12345 && $w2['row']['seconds'] === 7
    && $w2['row']['jid'] === '256772000311@s.whatsapp.net' && $w2['row']['from_me'] === 0 && $w2['row']['channel'] === 'sales' && $w2['row']['instance'] === 'sj-sales' && $w2['row']['attempts'] === 0,
    'the row: pending, the key, the announced facts, channel and instance as the webhook mapped them', j($w2['row']));
is_($w2['row']['message_id'] === (int)($w2['msg']['id'] ?? -1) && ($w2['msg']['body'] ?? '') === '[AUDIO]', 'it points at the stored placeholder message', j([$w2['row']['message_id'], $w2['msg']]));
is_($w2['events'] === 1 && $w2['payload_media_id'] === $w2['row_id'] && $w2['payload_conv'] === $w2['row_conv'], 'one ai.media event naming the row and its conversation', j([$w2['events'], $w2['payload_media_id'], $w2['row_id']]));
is_(!in_array('customer_phone', $w2['payload_keys'], true) && !in_array('remote_jid', $w2['payload_keys'], true) && in_array('media_id', $w2['payload_keys'], true),
    'the event payload carries ids and kind, not the phone or the JID (they are on the row)', j($w2['payload_keys']));
$w3 = $w['w3'];
is_($w3['http'] === 200 && $w3['media_queued'] === 0 && $w3['wa_media'] === 1 && $w3['events'] === 1, 'the same delivery again: accepted, nothing recorded twice, no second event', j($w3));
$w4 = $w['w4'];
is_($w4['queued'] === 1 && $w4['ai_reply_added'] === 1 && $w4['reply_message'] === 'Here is my roof' && $w4['reply_phone'] === '256772000311', 'a captioned photo still queues ai.reply with the caption — the text path is unchanged', j($w4));
is_($w4['media_queued'] === 1 && $w4['row']['kind'] === 'image' && $w4['row']['caption'] === 'Here is my roof' && $w4['row']['status'] === 'pending' && ($w4['msg']['body'] ?? '') === 'Here is my roof',
    'and records the photo for the media worker, caption kept', j([$w4['row'], $w4['msg']]));
$w5 = $w['w5'];
is_($w5['media_queued'] === 1 && $w5['row']['kind'] === 'document' && $w5['row']['file_name'] === 'quote.pdf' && $w5['row']['mimetype'] === 'application/pdf' && ($w5['msg']['body'] ?? '') === '[DOCUMENT]',
    'a document: recorded with its file name, stored as [DOCUMENT]', j($w5));
$w6 = $w['w6'];
is_($w6['media_queued'] === 0 && $w6['row']['status'] === 'unsupported' && $w6['row']['reason'] === 'unsupported_kind' && $w6['events_added'] === 0 && ($w6['msg']['body'] ?? '') === '[VIDEO]',
    'a video: stored as [VIDEO], recorded as unsupported, no event', j($w6));
is_($w['w7']['queued'] === 1 && $w['w7']['media_queued'] === 0 && $w['w7']['wa_media_added'] === 0, 'a text message: the text path, no record', j($w['w7']));
$w8 = $w['w8'];
is_($w8['media_queued'] === 0 && $w8['wa_media_added'] === 0 && $w8['events_added'] === 0 && ($w8['msg']['body'] ?? '') === '[AUDIO]', 'flag back OFF: stored as before, nothing recorded', j($w8));

echo "\nG. run_media_worker.php over the CLI\n";
$r1 = $w['r1'];
is_($r1['out'] === '' && $r1['rows']['audio']['status'] === 'fetched' && $r1['rows']['audio']['bytes'] === 2222 && $r1['rows']['audio']['sha'] === $r1['sha']['audio']
    && $r1['rows']['image']['status'] === 'fetched' && $r1['rows']['image']['sha'] === $r1['sha']['image'] && $r1['rows']['doc']['status'] === 'fetched' && $r1['rows']['doc']['sha'] === $r1['sha']['doc'],
    'the runner fetches the three recorded media silently; each row carries the sha256 of the bytes served', j($r1['rows']));
is_($r1['events_done'] === 3 && $r1['events_pending'] === 0 && $r1['fetch_calls'] === 3, 'three events done, three fetches, none left', j([$r1['events_done'], $r1['events_pending'], $r1['fetch_calls']]));
is_($r1['log'] === ['worker_line' => true, 'summary' => true, 'has_b64' => false, 'has_payload' => false, 'has_jid' => false], 'ai_platform.log carries the [MediaWorker] trace and the summary, no bytes, no JID', j($r1['log']));
is_($r1['disk'] === [], 'no file under the sandbox holds the media, its base64 or its hash', j($r1['disk']));
is_($w['r2']['out'] === '' && $w['r2']['log_grew'] === false && $w['r2']['fetch_calls'] === 3, 'flag OFF with nothing pending: the runner returns after one read, writes nothing, fetches nothing', j($w['r2']));
$r3 = $w['r3'];
is_($r3['media_queued'] === 1 && $r3['out'] === '' && $r3['row']['status'] === 'pending' && $r3['fetch_calls'] === 3, 'ai_enabled OFF: the webhook still records, the runner does nothing', j($r3));
is_($w['texts'] === 0 && $w['media_sends'] === 0, 'no WhatsApp message of any kind left the sandbox', j([$w['texts'], $w['media_sends']]));
$co = $w['cs_off']; $cn = $w['cs_on'];
is_($co['rc'] === 0 && in_array('ai_reply', $co['jobs'], true) && !in_array('ai_media', $co['jobs'], true),
    'cron_status with the flag OFF lists the schedule as it was — ai_reply yes, ai_media no (the job exists only while its flag is on)', j($co));
is_($cn['rc'] === 0 && in_array('ai_media', $cn['jobs'], true) && in_array('ai_reply', $cn['jobs'], true), 'with the flag ON the ai_media job is listed beside ai_reply', j($cn));

echo "\nH. Wiring that must stay as it is\n";
$ep = (string)file_get_contents($root . '/cron/event_processor.php');
// 5.18.86: on Uganda the processor no longer claims a worker's types at all; everywhere it still releases one that
// reaches it — proved by behaviour in test_event_processor_protected.php; this line only pins that ai.media stays on
// the list in both countries.
is_(strpos($ep, "\$_epWorkerOwned = \$_epUg ? ['ai.reply', 'ai.media', 'crm.lead.sync'] : ['ai.reply', 'ai.media'];") !== false
    && strpos($ep, "consume(20, '', [], \$_epWorkerOwned)") !== false
    && strpos($ep, 'in_array($type, $_epWorkerOwned, true)') !== false,
    'event_processor never acknowledges ai.media as unknown: not claimed on Uganda, released elsewhere');
$ma = (string)file_get_contents($root . '/cron/master.php');
is_(preg_match("/^\s*'ai_media'\s*=>\s*\['interval'\s*=>\s*60,\s*'flag'\s*=>\s*'ai_media_enabled',\s*'script'\s*=>\s*dirname\(__DIR__\) \. '\/run_media_worker\.php'\],/m", $ma) === 1,
    'master.php runs run_media_worker.php every 60 s as its own job, registered with flag ai_media_enabled');
is_(strpos($ma, "if (isset(\$_m_job['flag'])) {") !== false && strpos($ma, "['1', 'true', 'on', 'yes']") !== false, 'master.php does not dispatch a flagged job whose flag is off');
$rw = (string)file_get_contents($root . '/run_worker.php'); $rm = (string)file_get_contents($root . '/run_media_worker.php');
is_(strpos($rw, 'MediaWorker') === false && strpos($rm, 'AiReplyWorker') === false && strpos($rm, 'UcrmLeadWorker') === false, 'the two runners share no worker: separate processes, separate locks');
is_(preg_match('/if \(PHP_SAPI !== \'cli\'\) return;/', $rm) === 1 && strpos($rm, 'exit') === false, 'the media runner is CLI only and never exit()s (master.php includes it)');
$hook = (string)file_get_contents($root . '/evo_webhook.php');
is_(strpos($hook, "if (\$media !== null && MediaPolicy::enabled(\$config)) {") !== false, 'the webhook records media only behind ai_media_enabled');
is_(strpos($hook, "if (\$mediaQueued > 0) \$spawn[] = __DIR__ . '/run_media_worker.php';") !== false && strpos($hook, "if (\$queued > 0)      \$spawn[] = __DIR__ . '/run_worker.php';") !== false,
    'each runner is spawned only when it has work');
$evoSrc = (string)file_get_contents($root . '/lib/EvolutionApiService.php');
is_(strpos($evoSrc, "'base64'   => false,") !== false, 'the webhook registration still asks Evolution for NO base64 in the payload');
is_(strpos($evoSrc, "'/chat/getBase64FromMediaMessage/' . rawurlencode(\$instance)") !== false && strpos($evoSrc, "'convertToMp4' => false,") !== false, 'the fetch is Evolution\'s getBase64FromMediaMessage, convertToMp4 false');
$sc = (string)file_get_contents($root . '/tools/set_config.php');
is_(strpos($sc, "'ai_media_enabled' => ['bool',") !== false && strpos($sc, "'ai_media_max_bytes' => ['number',") !== false && strpos($sc, "'ai_media_timeout_s' => ['number',") !== false, 'set_config.php manages the three media settings');
$scOut = shell_exec('php ' . escapeshellarg($root . '/tools/set_config.php') . ' --key ai_media_timeout_s --value 2 2>&1; echo "rc=$?"');
is_(strpos((string)$scOut, 'between 3 and 60') !== false && strpos((string)$scOut, 'rc=1') !== false, 'a limit outside its range is refused by the tool, naming the range', (string)$scOut);
is_(json_decode((string)file_get_contents($root . '/manifest.json'), true)['information']['version'] === '5.18.89', 'manifest version is 5.18.81');
is_(is_file($root . '/migrations/085_wa_media.sql') && strpos((string)file_get_contents($root . '/migrations/085_wa_media.sql'), 'CREATE TABLE IF NOT EXISTS wa_media') !== false, 'migration 085 creates wa_media additively');

echo "\nI. Weakened copies — each caught by the scenario that guards it\n";
$mutants = [
    ['core', 'lib/MediaPolicy.php', "        return in_array(\$base, self::ALLOWED[\$kind] ?? [], true);", "        return true;",
     'the allow-list always says yes', function (array $m): bool { return ($m['f_bad_mime']['ok'] ?? null) === true; }],
    ['core', 'lib/MediaFetcher.php', "        if (is_numeric(\$declared) && (int)\$declared > \$max) {", "        if (false) {",
     'the announced-size check removed', function (array $m): bool { return ($m['f_declared']['calls_added'] ?? 0) === 1; }],
    ['core', 'workers/MediaWorker.php', "        if (!MediaPolicy::enabled(\$this->config)) {", "        if (false) {",
     'the worker ignores ai_media_enabled', function (array $m): bool { return ($m['k_off']['row']['status'] ?? '') === 'fetched' && ($m['k_off']['calls_added'] ?? 0) === 1; }],
    ['core', 'lib/MediaFetcher.php', "        if (strlen(\$bytes) > \$max) return self::refuse('too_large', sprintf('%d bytes, limit %d', strlen(\$bytes), \$max));",
     "        @file_put_contents((string)(\$this->config['data_dir'] ?? sys_get_temp_dir()) . '/media_leak.bin', \$bytes);\n        if (strlen(\$bytes) > \$max) return self::refuse('too_large', sprintf('%d bytes, limit %d', strlen(\$bytes), \$max));",
     'the fetcher keeps the bytes on disk', function (array $m): bool { return ($m['k_disk'] ?? []) !== []; }],
    ['core', 'lib/InboundMedia.php', "INSERT OR IGNORE INTO wa_media", "INSERT INTO wa_media",
     'the record no longer ignores a duplicate', function (array $m): bool { return is_string($m['s_dup'] ?? null) && strpos($m['s_dup'], 'threw') === 0; }],
    ['webhook', 'evo_webhook.php', "    if (\$media !== null && MediaPolicy::enabled(\$config)) {", "    if (\$media !== null) {",
     'the webhook records media with the flag OFF', function (array $m): bool { return ($m['w1']['wa_media'] ?? 0) === 1 && ($m['w1']['media_queued'] ?? 0) === 1; }],
];
foreach ($mutants as [$scn, $rel, $old, $new, $what, $flipped]) {
    [$copy, $n] = sj_weakened_copy($root, $rel, $old, $new);
    is_($n === 1, "copy — the anchor in {$rel} is unique, so the copy is weakened ({$what})", 'occurrences: ' . $n);
    $out = (string)shell_exec('DN_T_FAST=1 DN_T_EVO_PORT=' . (int)$evoPort . ' php ' . escapeshellarg($copy . '/tests/test_media_foundation.php') . ' --driver=' . $scn . ' 2>/dev/null');
    $m = json_decode((string)strrchr("\n" . trim($out), "\n"), true) ?: [];
    is_($n === 1 && $m !== [] && $flipped($m), "caught — {$what}", $m === [] ? 'driver output: ' . substr($out, 0, 400) : j(array_intersect_key($m, ['f_bad_mime' => 1, 'f_declared' => 1, 'k_off' => 1, 'k_disk' => 1, 's_dup' => 1, 'w1' => 1])));
    exec('rm -rf ' . escapeshellarg($copy));
}
// the control on the controls: the unweakened tree, run the same way, flips nothing
$out = (string)shell_exec('DN_T_FAST=1 DN_T_EVO_PORT=' . (int)$evoPort . ' php ' . escapeshellarg($root . '/tests/test_media_foundation.php') . ' --driver=core 2>/dev/null');
$m = json_decode((string)strrchr("\n" . trim($out), "\n"), true) ?: [];
$flips = 0; foreach ($mutants as [$scn, $rel, $old, $new, $what, $flipped]) { if ($scn === 'core' && $m !== [] && $flipped($m)) $flips++; }
is_($m !== [] && $flips === 0, 'control: the real tree, driven the same way, trips none of the five core catches', j(['facts' => count($m), 'flips' => $flips]));

if (is_resource($evoSrv)) { proc_terminate($evoSrv); proc_close($evoSrv); }
array_map('unlink', glob(sys_get_temp_dir() . '/fake_evo_state_*.json') ?: []);
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
