<?php
declare(strict_types=1);
/**
 * Section E of tests/test_voice_media.php — R3, every voice note handed to a person (5.18.104, root docs/73 §H.3.6
 * change 7, §H.3.7, §H.3.13; F10, R-19, ruling R19). TEST ONLY. The transcript is kept for staff and the body stays
 * [AUDIO]; nothing is queued for the assistant; the hand-over goes through lib/Handover.php with the line's owner; the
 * holding line is held while P5 serves the number and within 10 min after an acknowledgement, decided on a read taken
 * immediately before it, never the worker's own.
 */
$VM_SECTIONS['E'] = ['title' => 'R3: handed to a person, the transcript for staff, the body untouched — and one customer message at most', 'run' => function (QfCtx $ctx): void {
    vm_load($ctx);
    vm_voiceack($ctx);
    require_once $ctx->tree() . '/lib/ChannelContext.php';
    require_once __DIR__ . '/owner_evo.php';
    $evo = qf_fake_evo();
    $oa  = vm_fake_openai();
    $ctx->t($evo !== '' && $oa !== '', 'E00 the fakes started');
    if ($evo === '' || $oa === '') return;
    $holding = 'VM-HOLDING-LINE: a colleague will reply shortly.';
    $sent = function (array $calls, string $to): array {
        return array_values(array_filter($calls, function ($c) use ($to) { return $c[1] === $to; }));
    };
    $r3 = function (string $phone, array $extra = [], array $o = []) use ($ctx, $evo, $oa): array {
        qf_http('GET', $evo . '/__test/reset'); qf_http('GET', $oa . '/__test/reset');
        $b = vm_book($ctx, vm_cfg($evo, vm_rung('handover') + $extra));
        $v = vm_voice($b, $phone);
        vm_media($evo, $v['wa'], $o['bytes'] ?? ogg_opus(['seconds' => 2.0]), $o['mime'] ?? 'audio/ogg; codecs=opus');
        if (isset($o['plant'])) ($o['plant'])($b, $v);
        if (isset($o['stale'])) { $stale = $b['cfg']; vm_setcfg($b, $o['stale']); $run = vm_run($b, $oa, ['cfg' => $stale]); }
        else $run = vm_run($b, $oa);
        return [$b, $v, $run];
    };

    $ctx->run('E01 control: at R3 the note is handed over, the transcript kept for staff, the body [AUDIO] (V12, V13)', function () use ($ctx, $r3, $evo, $sent, $holding) {
        vm_oa_next(vm_fake_openai(), []);
        [$b, $v, $run] = $r3('256771300301');
        $row = vm_row($b, $v['media']);
        $ctx->eq([$row['status'], $row['voice_rung'], $row['understanding'], $row['understanding_kind']], ['understood', 'handover', 'FAKE_OPENAI_TEXT', 'transcript'],
                 'E01 the row: understood at handover, the transcript kept, its kind');
        $ctx->eq((int)vm_q($b, "SELECT COUNT(*) n FROM wa_messages WHERE id = ? AND body = '[AUDIO]'", [$v['msg']])[0]['n'], 1, 'E01 the body stays [AUDIO] (F10, V12)');
        $ctx->eq((int)vm_q($b, "SELECT COUNT(*) n FROM events WHERE event_type = 'ai.reply'")[0]['n'], 0, 'E01 nothing queued for the assistant (V13)');
        $esc = vm_q($b, "SELECT payload, priority, created_by FROM events WHERE event_type = 'wa.escalation'");
        $ctx->eq(array_map(function ($e) { return [json_decode($e['payload'], true), (int)$e['priority'], $e['created_by']]; }, $esc),
                 [[['channel' => 'sales', 'phone' => '256771300301', 'reason' => 'a voice note: read its machine transcript in the Inbox, and listen to check it'], 2, 'media_worker']],
                 'E01 one wa.escalation, by the media worker, naming the transcript');
        $ctx->eq(vm_q($b, 'SELECT state FROM wa_conversations WHERE id = ?', [$v['conv']])[0]['state'] ?? '', 'needs_human', 'E01 needs_human');
        $texts = vm_texts($evo);
        $ctx->eq([count($sent($texts, '256700000999')), $sent($texts, '256771300301')], [1, [['Sales-UG', '256771300301', $holding]]],
                 'E01 one alert to the central number, and the holding line once to the customer');
        $ctx->eq((int)vm_q($b, "SELECT COUNT(*) n FROM wa_messages WHERE conversation_id = ? AND role = 'assistant' AND body = ?", [$v['conv'], $holding])[0]['n'], 1,
                 'E01 the holding line stored as the assistant\'s');
        $ctx->t(strpos($run['log'], 'FAKE_OPENAI_TEXT') === false, 'E01 the transcript is not in the log (V23)', $run['log']);
    });

    $ctx->run('E02 a permanent failure at R3 is a person, with its code (V14)', function () use ($ctx, $r3) {
        [$b, $v] = $r3('256771300302', [], ['bytes' => ogg_opus(['head' => 'vorbis'])]);
        $esc = vm_q($b, "SELECT payload FROM events WHERE event_type = 'wa.escalation'");
        $ctx->eq(array_map(function ($e) { return json_decode($e['payload'], true)['reason'] ?? ''; }, $esc),
                 ['a voice message could not be transcribed (unsupported_audio) — listen to it in WhatsApp'], 'E02 a Vorbis file: handed over, its code named');
        [$b2, $v2] = $r3('256771300312', [], ['plant' => function (array $b, array $v) { qf_http('POST', qf_fake_evo() . '/__test/media', ['for_id' => $v['wa'], 'fail' => 404, 'base64' => 'eA==']); }]);
        $esc2 = vm_q($b2, "SELECT payload FROM events WHERE event_type = 'wa.escalation'");
        $ctx->eq(array_map(function ($e) { return json_decode($e['payload'], true)['reason'] ?? ''; }, $esc2),
                 ['a voice message could not be fetched (fetch_failed) — look at it in WhatsApp'], 'E02 a fetch failed for good: handed over');
    });

    $ctx->run('E03 P5 serving the number holds the holding line (R-19; V32)', function () use ($ctx, $r3, $evo, $sent) {
        [$b, $v] = $r3('256771300303', ['wa_voice_ack_enabled' => '1']);
        $texts = vm_texts($evo);
        $ctx->eq([count($sent($texts, '256700000999')), count($sent($texts, '256771300303')),
                  (int)vm_q($b, "SELECT COUNT(*) n FROM events WHERE event_type = 'wa.escalation'")[0]['n']], [1, 0, 1],
                 'E03 P5 on: the alert and the escalation, no holding line');
    });

    $ctx->run('E04 an acknowledgement in the 10 min before holds it; an older one does not (V32)', function () use ($ctx, $r3, $evo, $sent) {
        $plant = function (int $minutes) {
            return function (array $b, array $v) use ($minutes) {
                $b['pdo']->prepare("INSERT INTO wa_voice_acks (wa_message_id, conversation_id, channel, handset, outcome, period_hours, gap_s, local_day, created_at)
                                    VALUES (?, ?, 'sales', 'i:sales-ug', 'sent', 24, 20, '2026-11-05', datetime('now', ?))")
                         ->execute(['VM-ACK-' . $v['wa'], $v['conv'], '-' . $minutes . ' minutes']);
            };
        };
        $r3('256771300304', [], ['plant' => $plant(5)]);
        $ctx->eq(count($sent(vm_texts($evo), '256771300304')), 0, 'E04 an acknowledgement 5 min ago (P5 off since): no holding line');
        $r3('256771300314', [], ['plant' => $plant(11)]);
        $ctx->eq(count($sent(vm_texts($evo), '256771300314')), 1, 'E04 control: one 11 min ago: the holding line goes');
    });

    $ctx->run('E05 the holding line is decided on a read taken before it, not the worker\'s (ruling R19; V48)', function () use ($ctx, $r3, $evo, $sent) {
        $r3('256771300305', [], ['stale' => ['wa_voice_ack_enabled' => '1']]);
        $ctx->eq(count($sent(vm_texts($evo), '256771300305')), 0, 'E05 constructed with P5 off, on ack before the run: no holding line');
        $r3('256771300315', ['wa_voice_ack_enabled' => '1'], ['stale' => ['wa_voice_ack_enabled' => null]]);
        $ctx->eq(count($sent(vm_texts($evo), '256771300315')), 1, 'E05 control: constructed with P5 on, off ack before the run: the holding line goes');
    });

    $ctx->run('E06 on a salesperson\'s own number the salesperson is alerted too (V31)', function () use ($ctx, $evo, $sent) {
        $go = function (string $phone, string $to) use ($ctx, $evo): array {
            qf_http('GET', $evo . '/__test/reset');
            $b = vm_book($ctx, vm_cfg($evo, vm_rung('handover')));
            $b['store']->save('retailers.json', [['id' => 7, 'name' => 'Vee Seller', 'phone' => '0700000777', 'role' => 'sales', 'is_active' => true]]);
            $v = vm_voice($b, $phone);
            $w = new MediaWorker($b['store'], $b['cfg'], 90, 1);
            $e = new VmOwnerEvo($b['cfg'] + ['_data_dir' => $b['data']]);
            $e->ctxRow = ['channel_id' => 'sales', 'role' => 'sales', 'owner_type' => 'staff', 'owner_staff_id' => 7, 'handover_to' => $to, 'status' => 'active'];
            $p = new \ReflectionProperty(MediaWorker::class, 'evoClient'); $p->setAccessible(true); $p->setValue($w, $e);
            $h = new \ReflectionMethod(MediaWorker::class, 'handover'); $h->setAccessible(true);
            ob_start(); try { $h->invoke($w, vm_row($b, $v['media']), 'a voice note: read its machine transcript in the Inbox, and listen to check it'); } finally { ob_end_clean(); }
            return vm_texts($evo);
        };
        $texts = $go('256771300306', 'owner');
        $ctx->eq([count($sent($texts, '256700000777')), count($sent($texts, '256700000999'))], [1, 1],
                 'E06 the line\'s owner and the central number each alerted once');
        $texts = $go('256771300316', 'department');
        $ctx->eq([count($sent($texts, '256700000777')), count($sent($texts, '256700000999'))], [0, 1],
                 'E06 control: a number that hands over to its department alerts the central number only');
    });
}];
