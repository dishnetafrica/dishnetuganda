<?php
declare(strict_types=1);
/**
 * Section D of tests/test_voice_media.php — R2, the dry run, and what may reach the provider (5.18.104, root docs/73
 * §H.3.7, §H.3.8, §H.3.14, §H.3.21; R-15, S2-23, ruling R19). TEST ONLY. At R2 a note is transcribed and nothing is
 * kept but counts; a spoken STOP is recorded; the transcript is never logged; only honest Ogg/Opus within the limits
 * reaches OpenAI; voice switched off between the fetch and the call makes no call.
 */
$VM_SECTIONS['D'] = ['title' => 'R2: transcribed, nothing kept but counts — and only honest audio reaches the provider', 'run' => function (QfCtx $ctx): void {
    vm_load($ctx);
    vm_voiceack($ctx);
    $evo = qf_fake_evo();
    $oa  = vm_fake_openai();
    $ctx->t($evo !== '' && $oa !== '', 'D00 the fakes started');
    if ($evo === '' || $oa === '') return;
    $count = function (array $b, string $sql, array $p = []): int { return (int)(vm_q($b, $sql, $p)[0]['n'] ?? -1); };

    $ctx->run('D01 control: at R2 the note is transcribed and only counts stay (V11)', function () use ($ctx, $evo, $oa, $count) {
        qf_http('GET', $evo . '/__test/reset'); qf_http('GET', $oa . '/__test/reset');
        $b = vm_book($ctx, vm_cfg($evo, vm_rung('dry_run')));
        $ogg = ogg_opus(['seconds' => 4.0]);
        $v = vm_voice($b, '256771300201', 'sales', ['seconds' => 4]);
        vm_media($evo, $v['wa'], $ogg);
        vm_oa_next($oa, [['status' => 200, 'body' => ['text' => '  Hello,   I want   a kit  ']]]);
        $r = vm_run($b, $oa);
        $row = vm_row($b, $v['media']);
        $ctx->eq([$row['status'], $row['voice_rung'], $row['understanding'], $row['understanding_kind'], $row['provider'], $row['provider_model'],
                  (int)$row['transcript_chars'], (int)$row['local_seconds']],
                 ['understood', 'dry_run', null, null, 'openai', 'whisper-1', 19, 4], 'D01 the row: understood at dry_run, the counts, understanding NULL');
        $ctx->eq($count($b, "SELECT COUNT(*) n FROM wa_messages WHERE id = ? AND body = '[AUDIO]'", [$v['msg']]), 1, 'D01 the body is untouched');
        $ctx->eq([$count($b, "SELECT COUNT(*) n FROM events WHERE event_type = 'ai.reply'"), $count($b, "SELECT COUNT(*) n FROM events WHERE event_type = 'wa.escalation'"),
                  $count($b, "SELECT COUNT(*) n FROM wa_conversations WHERE state = 'needs_human'"), count(vm_texts($evo))], [0, 0, 0, 0],
                 'D01 no ai.reply, no hand-over, no needs_human, nothing sent');
        $calls = vm_oa_calls($oa);
        $ctx->eq(array_map(function ($c) { return [$c['auth'], $c['auth_sha'], $c['content_type'], $c['fields'], $c['model'], $c['file_name'], $c['file_type'], $c['file_size']]; }, $calls),
                 [['present', substr(hash('sha256', VM_KEY), 0, 12), 'multipart/form-data', ['model'], 'whisper-1', 'voice.ogg', 'audio/ogg', strlen($ogg)]],
                 'D01 one call: the stored key as bearer, model and file only (no language, no prompt, no response_format)');
        $vc = vm_q($b, 'SELECT * FROM wa_voice_calls WHERE media_id = ?', [$v['media']]);
        $day = (new \DateTime('now', new \DateTimeZone(QF_TZ)))->format('Y-m-d');
        $ctx->eq(array_map(function ($c) { return [$c['outcome'], (int)$c['http'], (int)$c['seconds_reserved'], (int)$c['seconds_billed'], (int)$c['cap_day_s'], $c['voice_rung'], $c['model'], $c['local_day'], (int)$c['attempt']]; }, $vc),
                 [['ok', 200, 4, 4, 7200, 'dry_run', 'whisper-1', $day, 1]], 'D01 the call reserved and settled: 4 s, the cap it applied, the rung, today in Kampala');
        $ctx->t(strpos($r['log'], 'I want') === false, 'D01 the transcript is not in the log (V23)', $r['log']);
    });

    $ctx->run('D02 a spoken STOP is recorded from R2 (V22; §H.3.14)', function () use ($ctx, $evo, $oa, $count) {
        qf_http('GET', $evo . '/__test/reset'); qf_http('GET', $oa . '/__test/reset');
        $b = vm_book($ctx, vm_cfg($evo, vm_rung('dry_run')));
        $v = vm_voice($b, '256771300202');
        vm_media($evo, $v['wa'], ogg_opus(['seconds' => 2.0]));
        vm_oa_next($oa, [['status' => 200, 'body' => ['text' => 'Stop.']]]);
        $r = vm_run($b, $oa);
        $oo = vm_q($b, 'SELECT phone, channel, scope, source, length(evidence) l FROM contact_optouts');
        $ctx->eq(array_map(function ($o) { return [$o['phone'], $o['channel'], $o['scope'], $o['source'], (int)$o['l'] <= 40]; }, $oo),
                 [['256771300202', '*', 'proactive', 'voice_keyword', true]], 'D02 one proactive opt-out, source voice_keyword, evidence at most 40 characters');
        $ctx->t(preg_match('/media #' . $v['media'] . ': opt-out recorded from the transcript \(matched "stop"\)/i', $r['log']) === 1
                && strpos($r['log'], '256771300202') === false, 'D02 the log names the media id and the word, never the phone', $r['log']);
        $b2 = vm_book($ctx, vm_cfg($evo, vm_rung('dry_run')));
        $v2 = vm_voice($b2, '256771300212');
        vm_media($evo, $v2['wa'], ogg_opus(['seconds' => 2.0]));
        vm_oa_next($oa, [['status' => 200, 'body' => ['text' => 'Please send me the price list.']]]);
        vm_run($b2, $oa);
        $ctx->eq($count($b2, 'SELECT COUNT(*) n FROM contact_optouts'), 0, 'D02 control: an ordinary sentence records nothing');
    });

    $ctx->run('D03 only honest Ogg/Opus within the limits reaches the provider (V19, V20, V21, V41)', function () use ($ctx, $evo, $oa) {
        $cases = [
            'D03 control: a well-formed 3 s note' => [ogg_opus(['seconds' => 3.0]), 'audio/ogg; codecs=opus', 3, null, 1],
            'D03 announced 5 s, 300 s of packets: too_long, from the packets (V19)'
                => [ogg_opus(['seconds' => 300, 'packet_bytes' => 1, 'packets_per_page' => 255]), 'audio/ogg; codecs=opus', 5, 'too_long', 0],
            'D03 3 s carrying 300 KB: invalid_audio (bitrate) (V20)'
                => [ogg_opus(['seconds' => 3.0, 'packet_bytes' => 2000, 'packets_per_page' => 1]), 'audio/ogg', 3, 'invalid_audio', 0],
            'D03 a Vorbis Ogg: unsupported_audio (V21)' => [ogg_opus(['head' => 'vorbis']), 'audio/ogg', 3, 'unsupported_audio', 0],
            'D03 the container claiming 2 h over 60 s of packets: invalid_audio (V41)'
                => [ogg_opus(['seconds' => 60, 'granule_seconds' => 7200]), 'audio/ogg', 60, 'invalid_audio', 0],
            'D03 an MP3: unsupported_audio' => ["ID3\x03\x00\x00\x00\x00\x00\x00" . str_repeat(OGG_OPUS_MARKER, 40), 'audio/mpeg', 3, 'unsupported_audio', 0],
        ];
        foreach ($cases as $label => [$bytes, $mime, $announced, $reason, $calls]) {
            qf_http('GET', $evo . '/__test/reset'); qf_http('GET', $oa . '/__test/reset');
            $b = vm_book($ctx, vm_cfg($evo, vm_rung('dry_run')));
            $v = vm_voice($b, '256771300203', 'sales', ['seconds' => $announced, 'length' => strlen($bytes)]);
            vm_media($evo, $v['wa'], $bytes, $mime);
            vm_run($b, $oa);
            $row = vm_row($b, $v['media']);
            $ctx->eq([count(vm_oa_calls($oa)), $reason === null ? $row['status'] : $row['failure_reason'],
                      (int)vm_q($b, "SELECT COUNT(*) n FROM events WHERE event_type = 'wa.escalation'")[0]['n']],
                     [$calls, $reason === null ? 'understood' : $reason, 0], $label);
        }
    });

    $ctx->run('D04 voice switched off between the fetch and the call: no call (ruling R19; V48)', function () use ($ctx, $evo, $oa) {
        foreach (['D04 control: nothing changes during the fetch, and the call is made' => false,
                  'D04 off voice-dry-run while the fetch is held: no call, switched_off, recorded only' => true] as $label => $off) {
            qf_http('GET', $evo . '/__test/reset'); qf_http('GET', $oa . '/__test/reset');
            $b = vm_book($ctx, vm_cfg($evo, vm_rung('dry_run')));
            $v = vm_voice($b, '256771300204');
            vm_media($evo, $v['wa'], ogg_opus(['seconds' => 2.0]), 'audio/ogg; codecs=opus', ['hold' => 2]);
            if ($off) {
                $next = $b['cfg']; unset($next['ai_media_voice'], $next['ai_transcription_provider']);
                $tmp = $b['data'] . '/next.json';
                file_put_contents($tmp, json_encode($next));
                exec('(sleep 1; cp ' . escapeshellarg($tmp) . ' ' . escapeshellarg($b['data'] . '/kyc_config.json') . ') > /dev/null 2>&1 &');
            }
            vm_run($b, $oa);
            $row = vm_row($b, $v['media']);
            $ctx->eq([count(vm_oa_calls($oa)), $off ? $row['failure_reason'] : $row['status'],
                      (int)vm_q($b, 'SELECT COUNT(*) n FROM wa_voice_calls')[0]['n'],
                      (int)vm_q($b, "SELECT COUNT(*) n FROM events WHERE event_type = 'wa.escalation'")[0]['n']],
                     $off ? [0, 'switched_off', 0, 0] : [1, 'understood', 1, 0], $label);
        }
    });

    $ctx->run('D05 the provider\'s answers at R2: a permanent one recorded, a retryable one retried up to the cap (V16)', function () use ($ctx, $evo, $oa) {
        qf_http('GET', $evo . '/__test/reset'); qf_http('GET', $oa . '/__test/reset');
        $b = vm_book($ctx, vm_cfg($evo, vm_rung('dry_run')));
        $v = vm_voice($b, '256771300205');
        vm_media($evo, $v['wa'], ogg_opus(['seconds' => 2.0]));
        vm_oa_next($oa, [['status' => 401, 'body' => ['error' => ['type' => 'invalid_request_error', 'code' => 'invalid_api_key']]]]);
        vm_run($b, $oa);
        $row = vm_row($b, $v['media']);
        $ctx->eq([$row['status'], $row['failure_reason'], count(vm_oa_calls($oa)), (int)vm_q($b, "SELECT COUNT(*) n FROM events WHERE event_type = 'wa.escalation'")[0]['n']],
                 ['failed', 'provider_auth', 1, 0], 'D05 control: a 401 is provider_auth, final, recorded only at R2');
        $b2 = vm_book($ctx, vm_cfg($evo, vm_rung('dry_run')));
        $v2 = vm_voice($b2, '256771300215');
        vm_media($evo, $v2['wa'], ogg_opus(['seconds' => 2.0]));
        qf_http('GET', $oa . '/__test/reset');
        qf_http('POST', $oa . '/__test/default', ['status' => 503, 'body' => ['error' => ['type' => 'server_error']]]);
        for ($i = 0; $i < 5; $i++) { vm_run($b2, $oa); vm_due($b2); }
        $calls = vm_q($b2, 'SELECT attempt, outcome, http FROM wa_voice_calls ORDER BY attempt');
        $ctx->eq([count(vm_oa_calls($oa)), array_map(function ($c) { return [(int)$c['attempt'], $c['outcome'], (int)$c['http']]; }, $calls),
                  vm_row($b2, $v2['media'])['failure_reason'], vm_q($b2, 'SELECT status FROM events WHERE id = ?', [$v2['event']])[0]['status'] ?? ''],
                 [3, [[1, 'failed', 503], [2, 'failed', 503], [3, 'failed', 503]], 'provider_error', 'done'],
                 'D05 a 503 every time: three calls, three reservations each settled, the third final');
        qf_http('GET', $oa . '/__test/reset');
    });
}];
