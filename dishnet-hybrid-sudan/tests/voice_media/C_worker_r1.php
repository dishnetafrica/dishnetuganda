<?php
declare(strict_types=1);
/**
 * Section C of tests/test_voice_media.php — the media worker at R1, voice-measure (5.18.104, root docs/73 §H.3.6):
 * fetched into memory, measured, recorded, wiped; idempotent; the switch, the rung and the before-switch-on rule each
 * decided on the event's own fresh read (ruling R19); R1 tells nobody anything; the attempt cap. TEST ONLY.
 */
$VM_SECTIONS['C'] = ['title' => 'The worker at R1: fetched, measured, recorded, wiped — and nobody told', 'run' => function (QfCtx $ctx): void {
    vm_load($ctx);
    vm_voiceack($ctx);
    require_once $ctx->tree() . '/lib/ChannelContext.php';
    require_once __DIR__ . '/owner_evo.php';
    $evo = qf_fake_evo();
    $oa  = vm_fake_openai();
    $ctx->t($evo !== '' && $oa !== '', 'C00 the fake Evolution and the fake OpenAI started');
    if ($evo === '' || $oa === '') return;
    $ogg = ogg_opus(['seconds' => 3.0]);
    $esc = function (array $b, int $conv): int {
        return (int)vm_q($b, "SELECT COUNT(*) n FROM events WHERE event_type = 'wa.escalation' AND entity_id = ?", [$conv])[0]['n'];
    };
    $state = function (array $b, int $conv): string {
        return (string)(vm_q($b, 'SELECT state FROM wa_conversations WHERE id = ?', [$conv])[0]['state'] ?? '');
    };

    $ctx->run('C01 control: a voice note at R1 is fetched, measured and recorded, and nothing else happens', function () use ($ctx, $evo, $oa, $ogg, $esc, $state) {
        qf_http('GET', $evo . '/__test/reset');
        $b = vm_book($ctx, vm_cfg($evo));
        $v = vm_voice($b, '256771300101');
        vm_media($evo, $v['wa'], $ogg);
        $r = vm_run($b, $oa);
        $row = vm_row($b, $v['media']);
        $ctx->eq([$row['status'], $row['voice_rung'], (int)$row['local_seconds'], (int)$row['fetched_bytes'], $row['sha256'], (int)$row['attempts'], $row['ptt']],
                 ['fetched', 'fetch_only', 3, strlen($ogg), hash('sha256', $ogg), 1, 1], 'C01 the row: fetched at fetch_only, 3 s counted from the packets, size, hash, one attempt, ptt');
        $calls = (array)(vm_evo_state($evo)['media_fetch_calls'] ?? []);
        $ctx->eq(array_map(function ($c) { return [$c['instance'], $c['remoteJid'], $c['fromMe'], $c['convertToMp4']]; }, $calls),
                 [['Sales-UG', '256771300101@s.whatsapp.net', false, false]], 'C01 one fetch, by the message key, as sent (no conversion)');
        $ctx->eq([count(vm_texts($evo)), $esc($b, $v['conv']), $state($b, $v['conv']) === 'needs_human', count(vm_oa_calls($oa))], [0, 0, false, 0],
                 'C01 nothing sent, no escalation, no needs_human, no OpenAI call');
        $ctx->eq(vm_q($b, 'SELECT status FROM events WHERE id = ?', [$v['event']])[0]['status'] ?? '', 'done', 'C01 the event is acknowledged');
        $ctx->eq(vm_files_with($ctx->tmp, OGG_OPUS_MARKER), [], 'C01 the audio is nowhere on disk (the book, the logs, the temp folder)');
        $ctx->t(strpos($r['log'], OGG_OPUS_MARKER) === false && strpos($r['log'], '256771300101') === false,
                'C01 the log names no audio and no phone', $r['log']);
        $ctx->eq((int)vm_q($b, "SELECT COUNT(*) n FROM wa_messages WHERE conversation_id = ? AND body = '[AUDIO]'", [$v['conv']])[0]['n'], 1,
                 'C01 the stored message is still [AUDIO]');
    });

    $ctx->run('C02 one message is fetched once, however many events name it', function () use ($ctx, $evo, $oa, $ogg) {
        qf_http('GET', $evo . '/__test/reset');
        $b = vm_book($ctx, vm_cfg($evo));
        $v = vm_voice($b, '256771300102');
        vm_media($evo, $v['wa'], $ogg);
        vm_run($b, $oa);
        (new EventBus($b['pdo']))->emit('ai.media', 'media', $v['media'], ['media_id' => $v['media']], 3, 'evo_webhook');
        vm_run($b, $oa);
        $ctx->eq([count((array)(vm_evo_state($evo)['media_fetch_calls'] ?? [])), vm_row($b, $v['media'])['status']], [1, 'fetched'],
                 'C02 control and case: a second event for a fetched row fetches nothing');
    });

    $ctx->run('C03 the switch is read per event, on a fresh read (ruling R19)', function () use ($ctx, $evo, $oa, $ogg) {
        qf_http('GET', $evo . '/__test/reset');
        $b = vm_book($ctx, vm_cfg($evo));
        $v = vm_voice($b, '256771300103');
        vm_media($evo, $v['wa'], $ogg);
        $stale = $b['cfg'];
        vm_setcfg($b, ['ai_media_enabled' => null]);
        vm_run($b, $oa, ['cfg' => $stale]);
        $row = vm_row($b, $v['media']);
        $ctx->eq([$row['status'], $row['failure_reason'], count((array)(vm_evo_state($evo)['media_fetch_calls'] ?? []))], ['skipped', 'media_disabled', 0],
                 'C03 switched off after the worker started: skipped, nothing fetched');
        $b2 = vm_book($ctx, vm_cfg($evo, ['ai_media_enabled' => null]));
        $v2 = vm_voice($b2, '256771300113');
        vm_media($evo, $v2['wa'], $ogg);
        $stale2 = $b2['cfg'];
        vm_setcfg($b2, ['ai_media_enabled' => '1']);
        vm_run($b2, $oa, ['cfg' => $stale2]);
        $ctx->eq(vm_row($b2, $v2['media'])['status'], 'fetched', 'C03 control: switched on after the worker started: fetched');
    });

    $ctx->run('C04 a note recorded before the latest switch-on is never fetched (§H.3.6 change 2)', function () use ($ctx, $evo, $oa, $ogg) {
        qf_http('GET', $evo . '/__test/reset');
        $b = vm_book($ctx, vm_cfg($evo));
        $old = vm_voice($b, '256771300104');
        $new = vm_voice($b, '256771300114');
        $b['pdo']->exec("UPDATE wa_media SET created_at = datetime('now', '-2 hours') WHERE id = " . $old['media']);
        vm_setcfg($b, ['ai_media_since' => gmdate('Y-m-d H:i:s', time() - 3600)]);
        vm_media($evo, $old['wa'], $ogg);
        vm_media($evo, $new['wa'], $ogg);
        vm_run($b, $oa);
        $o = vm_row($b, $old['media']); $n = vm_row($b, $new['media']);
        $ctx->eq([$o['status'], $o['failure_reason'], $n['status']], ['skipped', 'before_switch_on', 'fetched'],
                 'C04 the earlier on-period\'s note is skipped; the control, recorded after the switch-on, is fetched');
        $ctx->eq(count((array)(vm_evo_state($evo)['media_fetch_calls'] ?? [])), 1, 'C04 one fetch only');
    });

    $ctx->run('C05 R1 tells nobody: a fetch failed for good, and a retryable one up to the attempt cap (V15, V16)', function () use ($ctx, $evo, $oa, $esc, $state) {
        qf_http('GET', $evo . '/__test/reset');
        $b = vm_book($ctx, vm_cfg($evo));
        $gone = vm_voice($b, '256771300105');           // nothing set at the fake for it: 404, permanent
        vm_run($b, $oa);
        $g = vm_row($b, $gone['media']);
        $ctx->eq([$g['status'], $g['failure_reason'], $esc($b, $gone['conv']), $state($b, $gone['conv']) === 'needs_human', count(vm_texts($evo))],
                 ['failed', 'fetch_failed', 0, false, 0], 'C05 a 404: failed, recorded only — no escalation, no needs_human, nothing sent');
        $busy = vm_voice($b, '256771300115');
        vm_media($evo, $busy['wa'], 'x', 'audio/ogg', ['fail' => 503]);
        $tries = [];
        for ($i = 0; $i < 4; $i++) {
            vm_run($b, $oa); vm_due($b);
            $r = vm_row($b, $busy['media']);
            $tries[] = [$r['status'], (int)$r['attempts']];
        }
        $ev = vm_q($b, 'SELECT status FROM events WHERE id = ?', [$busy['event']])[0]['status'] ?? '';
        $ctx->eq($tries, [['failed', 1], ['failed', 2], ['failed', 3], ['failed', 3]], 'C05 a 503: retried, and the third claim is final');
        $ctx->eq([$ev, count((array)(vm_evo_state($evo)['media_fetch_calls'] ?? [])) - 1], ['done', 3], 'C05 at most three fetches; the event acknowledged, not dead');
        $ctx->eq([$esc($b, $busy['conv']), $state($b, $busy['conv']) === 'needs_human', count(vm_texts($evo))], [0, false, 0], 'C05 and nobody told');
    });

    $ctx->run('C06 a dead letter at R1 or R2 tells nobody; at R3 a person is told (V15; §H.3.6 change 5)', function () use ($ctx, $evo, $oa, $esc, $state) {
        qf_http('GET', $evo . '/__test/reset');
        $b = vm_book($ctx, vm_cfg($evo));
        $v = vm_voice($b, '256771300106');
        $b['pdo']->exec('UPDATE events SET max_attempts = 1 WHERE id = ' . $v['event']);
        vm_media($evo, $v['wa'], 'x', 'audio/ogg', ['fail' => 503]);
        vm_run($b, $oa);
        $r = vm_row($b, $v['media']);
        $ctx->eq([vm_q($b, 'SELECT status FROM events WHERE id = ?', [$v['event']])[0]['status'] ?? '', $r['status'], $esc($b, $v['conv']),
                  $state($b, $v['conv']) === 'needs_human', count(vm_texts($evo))], ['dead', 'dead', 0, false, 0],
                 'C06 R1: the event dead, the row dead, nobody told, no needs_human');
        $b3 = vm_book($ctx, vm_cfg($evo, vm_rung('handover')));
        $v3 = vm_voice($b3, '256771300116');
        $b3['pdo']->exec('UPDATE events SET max_attempts = 1 WHERE id = ' . $v3['event']);
        vm_media($evo, $v3['wa'], 'x', 'audio/ogg', ['fail' => 503]);
        vm_run($b3, $oa);
        $r3 = vm_row($b3, $v3['media']);
        $ctx->eq([$r3['status'], $r3['voice_rung'], $esc($b3, $v3['conv']), $state($b3, $v3['conv'])], ['dead', 'handover', 1, 'needs_human'],
                 'C06 control, R3: the row dead at rung handover, one escalation, needs_human');
    });

    $ctx->run('C07 the rung is the event\'s fresh read, not the worker\'s (ruling R19; V48)', function () use ($ctx, $evo, $oa, $ogg, $esc) {
        qf_http('GET', $evo . '/__test/reset');
        $b = vm_book($ctx, vm_cfg($evo, vm_rung('dry_run')));
        $v = vm_voice($b, '256771300107');
        vm_media($evo, $v['wa'], $ogg);
        $stale = $b['cfg'];
        vm_setcfg($b, vm_rung('handover'));
        vm_run($b, $oa, ['cfg' => $stale]);
        $r = vm_row($b, $v['media']);
        $ctx->eq([$r['status'], $r['voice_rung'], $esc($b, $v['conv'])], ['understood', 'handover', 1],
                 'C07 constructed at R2, on voice-staff before the run: the note is handled at R3 and handed over');
        $b2 = vm_book($ctx, vm_cfg($evo, vm_rung('handover')));
        $v2 = vm_voice($b2, '256771300117');
        vm_media($evo, $v2['wa'], $ogg);
        $stale2 = $b2['cfg'];
        vm_setcfg($b2, ['ai_media_voice_handover' => null]);
        vm_run($b2, $oa, ['cfg' => $stale2]);
        $r2 = vm_row($b2, $v2['media']);
        $ctx->eq([$r2['status'], $r2['voice_rung'], $esc($b2, $v2['conv']), $r2['understanding']], ['understood', 'dry_run', 0, null],
                 'C07 control: constructed at R3, off voice-staff before the run: recorded at R2, nobody told, nothing kept');
    });
    $ctx->run('C08 a route the registry refuses: R1 records it, R3 tells a person with no holding line (V15)', function () use ($ctx, $evo, $oa, $esc) {
        $paused = function (array $b) {
            return function (MediaWorker $w) use ($b) {
                $e = new VmOwnerEvo($b['cfg'] + ['_data_dir' => $b['data']]);
                $e->ctxRow = ['channel_id' => 'sales', 'role' => 'sales', 'owner_type' => 'department', 'handover_to' => 'department', 'status' => 'paused'];
                $p = new \ReflectionProperty(MediaWorker::class, 'evoClient'); $p->setAccessible(true); $p->setValue($w, $e);
            };
        };
        qf_http('GET', $evo . '/__test/reset');
        $b = vm_book($ctx, vm_cfg($evo));
        $v = vm_voice($b, '256771300108');
        vm_run($b, $oa, ['before' => $paused($b)]);
        $r = vm_row($b, $v['media']);
        $ctx->eq([$r['status'], $r['failure_reason'], $esc($b, $v['conv']), count(vm_texts($evo)), count((array)(vm_evo_state($evo)['media_fetch_calls'] ?? []))],
                 ['skipped', 'channel_refused', 0, 0, 0], 'C08 R1: skipped channel_refused, nothing fetched, nobody told');
        qf_http('GET', $evo . '/__test/reset');
        $b3 = vm_book($ctx, vm_cfg($evo, vm_rung('handover')));
        $v3 = vm_voice($b3, '256771300118');
        vm_run($b3, $oa, ['before' => $paused($b3)]);
        $texts = vm_texts($evo);
        $ctx->eq([vm_row($b3, $v3['media'])['failure_reason'], $esc($b3, $v3['conv']), count(array_filter($texts, function ($t) { return $t[1] === '256771300118'; }))],
                 ['channel_refused', 1, 0], 'C08 control, R3: handed over, and no holding line from a number in doubt');
    });
}];
