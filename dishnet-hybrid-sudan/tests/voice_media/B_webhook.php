<?php
declare(strict_types=1);
/**
 * Section B of tests/test_voice_media.php — the webhook's step 8c (5.18.104, root docs/73 §H.3.4): on Uganda with R1 on, a
 * voice note is recorded for the media worker and its event queued — rows and events only, no Evolution or OpenAI call
 * from the webhook; audio only; the conversation found for a note the sync stored first, on this phone and channel only;
 * a failure logged by class and the batch going on; the answer's keys only those of the parts that are on; the media
 * worker spawned in its own process. TEST ONLY. Through the real plugin under php -S (SjSandbox, exec disabled), and for
 * the spawn through a second php -S over the same copy with exec allowed.
 */
$VM_SECTIONS['B'] = ['title' => 'The webhook\'s step 8c: a voice note recorded and queued, nothing else', 'run' => function (QfCtx $ctx): void {
    vm_load($ctx);
    require_once dirname(__DIR__) . '/fixtures/staff_jobs_sandbox.php';
    $s = SjSandbox::start($ctx->root, ['tenant_profile' => 'uganda', 'timezone' => QF_TZ, 'ai_enabled' => '1', 'ai_provider' => 'openai',
                                       'openai_api_key' => VM_KEY, 'ai_media_enabled' => '0'], 'vmw');
    $cfgNow = $s->cfg;
    $setCfg = function (array $ov) use ($s, &$cfgNow): void {
        foreach ($ov as $k => $v) { if ($v === null) unset($cfgNow[$k]); else $cfgNow[$k] = $v; }
        $s->store()->save('kyc_config.json', $cfgNow);
        file_put_contents($s->data . '/kyc_config.json', json_encode($cfgNow));
    };
    $post = function ($messages, string $instance = 'sj-sales', ?string $base = null) use ($s): array {
        $data = isset($messages['key']) ? $messages : array_values($messages);
        $r = $s->http('POST', ($base ?? $s->base) . '?page=evo_webhook', ['event' => 'messages.upsert', 'instance' => $instance, 'data' => $data],
            ['Content-Type: application/json', 'X-DishNet-Token: ' . $s->evoKey]);
        return is_array($r[2]) ? $r[2] : ['_code' => $r[0], '_body' => substr($r[1], 0, 300)];
    };
    $count = function (string $sql, array $p = []) use ($s): int { return (int)($s->q($sql, $p)[0]['n'] ?? -1); };
    $media = function (string $wa) use ($s): array { return $s->q('SELECT * FROM wa_media WHERE wa_message_id = ?', [$wa])[0] ?? []; };
    $base5103 = ['status', 'outcome', 'channel', 'queued', 'skipped'];

    try {
        $ctx->run('B01 control: dark on Uganda (R1 off), a voice note is stored as [AUDIO] and nothing else — 5.18.103\'s answer (V38)', function () use ($ctx, $post, $count, $base5103) {
            $env = vm_envelope('256771301001');
            $r = $post($env);
            $ctx->eq(array_keys($r), $base5103, 'B01 the answer\'s keys are 5.18.103\'s exactly');
            $ctx->eq([$count("SELECT COUNT(*) n FROM wa_messages WHERE wa_message_id = ? AND body = '[AUDIO]'", [$env['key']['id']]),
                      $count('SELECT COUNT(*) n FROM wa_media'), $count("SELECT COUNT(*) n FROM events WHERE event_type IN ('ai.media','ai.voice_ack')")],
                     [1, 0, 0], 'B01 the [AUDIO] row only: no wa_media row, no event');
        });

        $setCfg(['ai_media_enabled' => '1']);
        $ctx->run('B02 R1 on: the voice note is recorded and queued, and the answer gains media_queued only (V38, V47)', function () use ($ctx, $post, $count, $media, $base5103, $s) {
            $env = vm_envelope('256771301002', ['seconds' => 4, 'length' => 7000]);
            $r = $post($env);
            $ctx->eq([array_keys($r), $r['media_queued'] ?? null], [array_merge($base5103, ['media_queued']), 1], 'B02 the answer: 5.18.103\'s keys and media_queued 1');
            $m = $media($env['key']['id']);
            $msgId = (int)($s->q('SELECT id FROM wa_messages WHERE wa_message_id = ?', [$env['key']['id']])[0]['id'] ?? 0);
            $ctx->eq([$m['kind'] ?? null, (int)($m['ptt'] ?? -1), $m['status'] ?? null, $m['instance'] ?? null, $m['channel'] ?? null, $m['remote_jid'] ?? null,
                      (int)($m['message_id'] ?? 0), (int)($m['seconds'] ?? 0), (int)($m['declared_bytes'] ?? 0), (int)($m['from_me'] ?? -1)],
                     ['audio', 1, 'pending', 'sj-sales', 'sales', '256771301002@s.whatsapp.net', $msgId, 4, 7000, 0], 'B02 the wa_media row: audio, ptt, pending, the key, linked to the [AUDIO] row');
            $ev = $s->q("SELECT entity_type, entity_id, payload, priority, created_by FROM events WHERE event_type = 'ai.media'");
            $ctx->eq(array_map(function ($e) { return [$e['entity_type'], (int)$e['entity_id'], json_decode($e['payload'], true), (int)$e['priority'], $e['created_by']]; }, $ev),
                     [['media', (int)($m['id'] ?? 0), ['media_id' => (int)($m['id'] ?? 0)], 3, 'evo_webhook']], 'B02 one ai.media event naming the row, nothing else in it');
            $ctx->eq([$count("SELECT COUNT(*) n FROM events WHERE event_type IN ('ai.reply','ai.voice_ack')"), count($s->texts()),
                      count((array)($s->http('GET', "{$s->evo}/__test/state")[2]['media_fetch_calls'] ?? []))], [0, 0, 0],
                     'B02 no AI turn, no acknowledgement (P5 is a later stage), no send, and no fetch from the webhook');
        });

        $ctx->run('B03 audio only: forwarded audio is recorded; a photo and a document are not (C10; V7)', function () use ($ctx, $post, $media) {
            $fwd = vm_envelope('256771301003', ['ptt' => false]);
            $img = vm_envelope('256771301003', ['kind' => 'image']);
            $doc = vm_envelope('256771301003', ['kind' => 'document']);
            $r1 = $post($fwd); $r2 = $post($img); $r3 = $post($doc);
            $ctx->eq([(int)($media($fwd['key']['id'])['ptt'] ?? -1), $r1['media_queued'] ?? null], [0, 1], 'B03 a forwarded audio file (no ptt): recorded, ptt 0');
            $ctx->eq([$media($img['key']['id']), $media($doc['key']['id']), $r2['media_queued'] ?? null, $r3['media_queued'] ?? null], [[], [], 0, 0],
                     'B03 a photo and a document: never recorded');
        });

        $ctx->run('B04 a duplicate delivery records once', function () use ($ctx, $post, $count) {
            $env = vm_envelope('256771301004');
            $post($env); $r = $post($env);
            $ctx->eq([$count('SELECT COUNT(*) n FROM wa_media WHERE wa_message_id = ?', [$env['key']['id']]), $r['media_queued'] ?? null], [1, 0],
                     'B04 the second delivery records and queues nothing');
        });

        $ctx->run('B05 a note the sync stored first: found on this phone and channel only (R-18; V33)', function () use ($ctx, $post, $media, $s) {
            $cs = new ConversationService($s->data, $s->store()->getPdo());
            $same = vm_envelope('256771301005');
            $cs->importEvoMessage($same, 'sales');
            $r = $post($same);
            $ctx->eq([$r['media_queued'] ?? null, $media($same['key']['id'])['channel'] ?? null], [1, 'sales'], 'B05 control: stored by the sync under this channel: recorded');
            $other = vm_envelope('256771301015');
            $cs->importEvoMessage($other, 'support');
            $r2 = $post($other);
            $ctx->eq([$r2['media_queued'] ?? null, $media($other['key']['id'])], [0, []], 'B05 stored by the sync under another channel: not recorded');
        });

        $ctx->run('B06 a wrapped voice note is not stored, so not recorded (F28: counted, not unwrapped)', function () use ($ctx, $post, $media) {
            $env = vm_envelope('256771301006', ['wrap' => 'ephemeralMessage']);
            $r = $post($env);
            $ctx->eq([$r['outcome'] ?? null, $r['media_queued'] ?? null, $media($env['key']['id'])], ['accepted', 0, []], 'B06 nothing recorded, the answer accepted');
        });

        $ctx->run('B07 a broken wa_media aborts nothing: the batch goes on (V46)', function () use ($ctx, $post, $s) {
            $pdo = new \PDO('sqlite:' . $s->data . '/plugin.sqlite3');
            $pdo->exec('PRAGMA legacy_alter_table = ON');
            $pdo->exec('ALTER TABLE wa_media RENAME TO wa_media_vm_hidden');
            try {
                $r = $post([vm_envelope('256771301007'), vm_envelope('256771301017', ['kind' => 'text', 'text' => 'VM is the kit in stock?'])]);
            } finally {
                $pdo->exec('ALTER TABLE wa_media_vm_hidden RENAME TO wa_media');
            }
            $ctx->eq([$r['outcome'] ?? null, $r['queued'] ?? null, $r['media_queued'] ?? null], ['accepted', 1, 0],
                     'B07 the voice note\'s step failed; the text after it in the same batch is still queued for the assistant');
        });

        $ctx->run('B08 the media worker is spawned in its own process (§H.3.4 (e))', function () use ($ctx, $post, $media, $s) {
            $nonce = bin2hex(random_bytes(6));
            file_put_contents($s->plug . '/__vm_nonce.txt', $nonce);
            [$port, $proc] = qf_serve('-t ' . escapeshellarg($s->plug), function (int $p) use ($nonce): bool {
                return trim(qf_http('GET', "http://127.0.0.1:{$p}/__vm_nonce.txt", null, [], 2)[2]) === $nonce;
            }, 13900, ['DN_VAULT_FILE' => $s->vault, 'DN_DATA_DIR' => $s->data]);
            $ctx->t($port > 0, 'B08 a second server over the sandbox\'s copy, exec allowed');
            if ($port <= 0) return;
            $env = vm_envelope('256771301008');
            $s->http('POST', "{$s->evo}/__test/media", ['for_id' => $env['key']['id'], 'base64' => base64_encode(ogg_opus(['seconds' => 2.0])),
                                                      'mimetype' => 'audio/ogg; codecs=opus']);
            $r = $post($env, 'sj-sales', "http://127.0.0.1:{$port}/public.php");
            $st = '';
            for ($i = 0; $i < 60; $i++) { usleep(200000); $st = (string)($media($env['key']['id'])['status'] ?? ''); if ($st === 'fetched') break; }
            proc_terminate($proc); proc_close($proc);
            $ctx->eq([$r['media_queued'] ?? null, $st], [1, 'fetched'], 'B08 queued by the webhook, and fetched by the worker it spawned');
        });
    } finally {
        $s->stop();
    }
}];
