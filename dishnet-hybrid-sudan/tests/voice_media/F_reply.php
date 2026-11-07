<?php
declare(strict_types=1);
/**
 * Section F of tests/test_voice_media.php — R4 on a listed number: the body becomes the labelled transcript and ONE
 * ai.reply turn with origin voice is queued; any other number stays at R3 (5.18.104, root docs/73 §H.3.7's table,
 * §H.3.12). TEST ONLY. The reply worker's side (F20, R-13, the brain's voice block) is stage B6's.
 */
$VM_SECTIONS['F'] = ['title' => 'R4: the labelled transcript becomes the customer\'s turn, once, on a listed number only', 'run' => function (QfCtx $ctx): void {
    vm_load($ctx);
    vm_voiceack($ctx);
    $evo = qf_fake_evo();
    $oa  = vm_fake_openai();
    $ctx->t($evo !== '' && $oa !== '', 'F00 the fakes started');
    if ($evo === '' || $oa === '') return;

    $ctx->run('F01 control: on a listed number the turn is queued and the body labelled', function () use ($ctx, $evo, $oa) {
        qf_http('GET', $evo . '/__test/reset'); qf_http('GET', $oa . '/__test/reset');
        $b = vm_book($ctx, vm_cfg($evo, vm_rung('reply', 'sales')));
        $v = vm_voice($b, '256771300401', 'sales', ['seconds' => 2]);
        vm_media($evo, $v['wa'], ogg_opus(['seconds' => 2.0]));
        vm_oa_next($oa, [['status' => 200, 'body' => ['text' => 'How much is the kit?']]]);
        vm_run($b, $oa);
        $row = vm_row($b, $v['media']);
        $ctx->eq([$row['status'], $row['voice_rung'], $row['understanding']], ['understood', 'reply', 'How much is the kit?'], 'F01 the row: understood at reply');
        $m = vm_q($b, 'SELECT body, metadata FROM wa_messages WHERE id = ?', [$v['msg']])[0] ?? [];
        $md = json_decode((string)($m['metadata'] ?? ''), true);
        $ctx->eq([(string)($m['body'] ?? ''), $md['voice']['media_id'] ?? null, $md['voice']['provider'] ?? null],
                 ['[voice message, transcribed] How much is the kit?', $v['media'], 'openai'], 'F01 the body is the labelled transcript, the metadata names the note');
        $ev = vm_q($b, "SELECT payload, priority, created_by, entity_id FROM events WHERE event_type = 'ai.reply'");
        $p = json_decode((string)($ev[0]['payload'] ?? ''), true) ?: [];
        $ctx->eq([count($ev), (int)($ev[0]['priority'] ?? 0), $ev[0]['created_by'] ?? '', (int)($ev[0]['entity_id'] ?? 0), $p['origin'] ?? '', $p['message'] ?? '',
                  $p['channel'] ?? '', $p['customer_phone'] ?? '', $p['wa_message_id'] ?? '', $p['voice'] ?? null],
                 [1, 3, 'media_worker', $v['conv'], 'voice', '[voice message, transcribed] How much is the kit?', 'sales', '256771300401', $v['wa'],
                  ['media_id' => $v['media'], 'seconds' => 2, 'chars' => 20]], 'F01 one ai.reply, origin voice, the shape the webhook queues');
        $ctx->eq((int)vm_q($b, "SELECT COUNT(*) n FROM events WHERE event_type = 'wa.escalation'")[0]['n'], 0, 'F01 no hand-over');
    });

    $ctx->run('F02 R4 on, this number not listed: R3 (V6)', function () use ($ctx, $evo, $oa) {
        qf_http('GET', $evo . '/__test/reset'); qf_http('GET', $oa . '/__test/reset');
        $b = vm_book($ctx, vm_cfg($evo, vm_rung('reply', 'support')));
        $v = vm_voice($b, '256771300402');
        vm_media($evo, $v['wa'], ogg_opus(['seconds' => 2.0]));
        vm_run($b, $oa);
        $ctx->eq([vm_row($b, $v['media'])['voice_rung'], (int)vm_q($b, "SELECT COUNT(*) n FROM events WHERE event_type = 'ai.reply'")[0]['n'],
                  (int)vm_q($b, "SELECT COUNT(*) n FROM events WHERE event_type = 'wa.escalation'")[0]['n'],
                  (int)vm_q($b, "SELECT COUNT(*) n FROM wa_messages WHERE id = ? AND body = '[AUDIO]'", [$v['msg']])[0]['n']],
                 ['handover', 0, 1, 1], 'F02 handed over at R3, nothing queued, the body untouched');
    });

    $ctx->run('F03 complete() once: a repeat finds the row understood and queues nothing (§H.3.7 step 7)', function () use ($ctx, $evo) {
        $b = vm_book($ctx, vm_cfg($evo, vm_rung('reply')));
        $v = vm_voice($b, '256771300403');
        $svc = new VoiceTranscription($b['pdo'], $b['store'], $b['cfg'], null, function () {}, $b['data']);
        $row = vm_row($b, $v['media']);
        $one = $svc->complete($row, 'first', ['seconds' => 1], 'reply');
        $two = $svc->complete($row, 'second', ['seconds' => 1], 'reply');
        $ctx->eq([$one['outcome'], $two['outcome'], (int)vm_q($b, "SELECT COUNT(*) n FROM events WHERE event_type = 'ai.reply'")[0]['n'],
                  vm_row($b, $v['media'])['understanding']], ['understood', 'already_understood', 1, 'first'], 'F03 one turn, the first transcript kept');
        $three = $svc->complete($row, 'third', ['seconds' => 1], 'fetch_only');
        $ctx->eq([$three['outcome'], $three['reason']], ['failed', 'switched_off'], 'F03 a rung that does not transcribe is refused');
    });
}];
