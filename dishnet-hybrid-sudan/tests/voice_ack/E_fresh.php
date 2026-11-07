<?php
declare(strict_types=1);
/**
 * Section E of tests/test_voice_ack.php — a reply worker started before an `on` or an `off` decides on a fresh read
 * (5.18.104, root docs/73 §H.3.12 R-13, §H.3.15 step 13, §H.3.5 row 2's and row 5's off-hooks; B13-03, rulings R7, R14,
 * R19; VK7, VK8, A35, T14's third form, V30, V45). TEST ONLY. The switch tool is stage B9's: here the keys an `on` or
 * `off` writes are written to the configuration file as the tool would leave them, and the step's off-hook is called
 * as the ladder calls it, on a connection of its own ($rw).
 */
$VA_SECTIONS['E'] = ['title' => 'A worker started before an on or an off: P5\'s step 13, R-13, and the off-hooks\' drains', 'run' => function (QfCtx $ctx): void {
    va_load($ctx);
    $evo = qf_fake_evo();
    $ctx->t($evo !== '', 'E00 the fake Evolution started');
    if ($evo === '') return;
    $fresh = function (array $over = []) use ($ctx, $evo): array {
        qf_http('GET', $evo . '/__test/reset');
        return vm_book($ctx, va_cfg($evo, $over));
    };
    $offAck = ['wa_voice_ack_enabled' => null, 'wa_voice_ack_since' => null];
    $offReply = ['ai_media_voice_reply' => null, 'ai_media_voice_reply_channels' => null, 'ai_media_voice_reply_approved' => null,
                 'ai_media_voice_reply_since' => null];

    $ctx->run('E01 off ack during the hook\'s drain: a worker built before it records switched_off and sends nothing (VK7, T14c)', function () use ($ctx, $evo, $fresh, $offAck) {
        $b = $fresh();
        $n = va_note($b, '256771400501');
        $stale = $b['cfg'];
        vm_setcfg($b, $offAck);
        $h = va_spawn($ctx, $b, $stale, 1.5);
        VoiceSteps::$drainBoundS = 40; VoiceSteps::$drainPollUs = 200000;
        $rw = va_rw($b);
        $hook = VoiceSteps::offHook($rw, $b['cfg'], $b['data'], 'ack', 'switch');
        $j = va_join($h);
        $ctx->eq([$hook['ack_events_at_start'] ?? null, ($hook['waited_s'] ?? 0) >= 1, $hook['ack_events_left'] ?? null], [1, true, 0],
                 'E01 the hook waited for the worker and left no acknowledgement event', json_encode([$hook, $j]));
        $ctx->eq([va_outcome($b, $n['wa']), count(va_ledger($b, $n['wa'])), vm_texts($evo)], ['skipped:switched_off', 0, []],
                 'E01 the worker built before the off: switched_off at step 13, no ledger row, nothing sent');
        $b2 = $fresh();
        $n2 = va_note($b2, '256771400511');
        va_run($b2);
        $ctx->eq(va_outcome($b2, $n2['wa']), 'sent', 'E01 control: the same worker run before the off sends');
    });

    $ctx->run('E02 off sending, P5\'s keys still set: the worker built before it records ledger_off and sends nothing (VK7, T14c)', function () use ($ctx, $evo, $fresh) {
        $b = $fresh();
        $n = va_note($b, '256771400502');
        $stale = $b['cfg'];
        vm_setcfg($b, ['wa_send_ledger' => null, 'wa_send_ledger_since' => null]);
        va_run($b, null, ['cfg' => $stale]);
        $ctx->eq([va_outcome($b, $n['wa']), count(va_ledger($b, $n['wa'])), vm_texts($evo)], ['skipped:ledger_off', 0, []],
                 'E02 skipped:ledger_off: the ledger P5 built from its fresh read answers ledger_off');
    });

    $ctx->run('E03 on voice-reply listing the number after the worker started: p4_answers, nothing sent (VK8, A35)', function () use ($ctx, $evo, $fresh) {
        $b = $fresh(vm_rung('handover'));
        $n = va_note($b, '256771400503');
        $stale = $b['cfg'];
        vm_setcfg($b, vm_rung('reply', 'sales') + ['ai_media_voice_reply_since' => UtcClock::stamp(va_noon() - 60)]);
        va_run($b, null, ['cfg' => $stale]);
        $ctx->eq([va_outcome($b, $n['wa']), count(va_ledger($b, $n['wa'])), vm_texts($evo)], ['skipped:p4_answers', 0, []],
                 'E03 p4_answers on the fresh read; no claim, nothing sent');
        $b = $fresh(vm_rung('handover'));
        $n = va_note($b, '256771400513');
        $stale = $b['cfg'];
        vm_setcfg($b, vm_rung('reply', 'support'));
        va_run($b, null, ['cfg' => $stale]);
        $ctx->eq(va_outcome($b, $n['wa']), 'sent', 'E03 control: on voice-reply listing another number, the acknowledgement goes');
    });

    $ctx->run('E04 off voice-reply after the worker started: the voice turn goes to a person, the brain is not asked (VK7, V30)', function () use ($ctx, $evo, $fresh, $offReply) {
        $b = $fresh(vm_rung('reply', 'sales'));
        $t = va_voice_turn($b, '256771400504');
        $stale = $b['cfg'];
        vm_setcfg($b, $offReply);
        $brain = new VaBrain($b['cfg']);
        va_run($b, null, ['cfg' => $stale, 'brain' => $brain]);
        $esc = vm_q($b, "SELECT payload, created_by FROM events WHERE event_type = 'wa.escalation'");
        $ctx->eq([count($brain->contexts), array_map(function ($e) { return [json_decode($e['payload'], true)['reason'] ?? '', $e['created_by']]; }, $esc),
                  array_column(va_sent_to($evo, '256771400504'), 2)],
                 [0, [['a voice note arrived while the assistant answered voice notes; that is off now — read its transcript in the Inbox', 'ai_reply_worker']],
                  ['VM-HOLDING-LINE: a colleague will reply shortly.']],
                 'E04 handed to a person (R-13), the holding line may go, no AI answer');
        $b = $fresh(vm_rung('reply', 'sales'));
        $t = va_voice_turn($b, '256771400514');
        $brain = new VaBrain($b['cfg']);
        va_run($b, null, ['brain' => $brain]);
        $ctx->eq([count($brain->contexts), array_column(va_sent_to($evo, '256771400514'), 2)], [1, [$brain->canned]],
                 'E04 control: R4 still on, the brain is asked once and its answer sent');
    });

    $ctx->run('E05 off voice-measure: pending notes settled switched_off, a note in flight untouched, the voice turns drained (V45; ruling R14)', function () use ($ctx, $evo, $fresh, $offReply) {
        $b = $fresh(vm_rung('reply', 'sales'));
        $pending = va_note($b, '256771400505', null, ['wa_media' => true]);
        $flying = va_note($b, '256771400515', null, ['wa_media' => true]);
        $b['pdo']->prepare("UPDATE wa_media SET status = 'fetching', attempts = 1 WHERE id = ?")->execute([$flying['media']]);
        $b['pdo']->exec("DELETE FROM events WHERE event_type = 'ai.voice_ack'");
        $t = va_voice_turn($b, '256771400525');
        $stale = $b['cfg'];
        vm_setcfg($b, $offReply + ['ai_media_enabled' => null, 'ai_media_since' => null, 'ai_media_voice' => null, 'ai_media_voice_handover' => null]);
        $h = va_spawn($ctx, $b, $stale, 1.5, null, 'VA-FAKE-ANSWER: never sent');
        VoiceSteps::$drainBoundS = 40; VoiceSteps::$drainPollUs = 200000;
        $hook = VoiceSteps::offHook(va_rw($b), $b['cfg'], $b['data'], 'voice-measure', 'switch');
        $j = va_join($h);
        $ctx->eq([$hook['settled'] ?? null, $hook['voice_turns_left'] ?? null, vm_row($b, $pending['media'])['status'] ?? null,
                  vm_row($b, $pending['media'])['failure_reason'] ?? null, vm_row($b, $flying['media'])['status'] ?? null],
                 [1, 0, 'skipped', 'switched_off', 'fetching'], 'E05 the pending row settled switched_off, the one in flight untouched, no voice turn left', json_encode([$hook, $j]));
        $ctx->eq([$j['asked'] ?? 'x', (int)vm_q($b, "SELECT COUNT(*) n FROM events WHERE event_type = 'wa.escalation' AND payload LIKE '%that is off now%'")[0]['n']],
                 [0, 1], 'E05 the drained turn was handed to a person on the worker\'s fresh read, the brain not asked');
        $ctx->eq((int)vm_q($b, "SELECT COUNT(*) n FROM events WHERE event_type = 'ai.media' AND status = 'pending'")[0]['n'], 2,
                 'E05 the ai.media events are not drained (ruling R14): pending, counted');
    });
}];
