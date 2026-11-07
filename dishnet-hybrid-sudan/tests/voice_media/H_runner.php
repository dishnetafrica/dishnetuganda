<?php
declare(strict_types=1);
/**
 * Section H of tests/test_voice_media.php — run_media_worker.php and the run's own budget (5.18.104, root docs/73 §H.3.6
 * "Its own constructor and budget", change 3, "run_media_worker.php"; F2, K2-25). TEST ONLY. Each case runs the real
 * runner in its own process. Under master it never runs past master's deadline and starts a note only when the note can
 * finish before it, so no attempt is spent on a note it cannot finish; batch 1, so nothing is left `processing`; off
 * Uganda, with the assistant off, or with the switch off it does nothing but settle what an earlier on-period queued.
 */
$VM_SECTIONS['H'] = ['title' => 'The runner: Uganda only, never past master\'s deadline, one note at a time', 'run' => function (QfCtx $ctx): void {
    vm_load($ctx);
    $evo = qf_fake_evo();
    $ctx->t($evo !== '', 'H00 the fake Evolution started');
    if ($evo === '') return;
    $ogg = ogg_opus(['seconds' => 2.0]);
    $fetches = function () use ($evo): int { return count((array)(vm_evo_state($evo)['media_fetch_calls'] ?? [])); };
    $evRow = function (array $b, int $id): array { return vm_q($b, 'SELECT status, attempts FROM events WHERE id = ?', [$id])[0] ?? []; };

    $ctx->run('H01 control: under master with 120 s left a note at R1 is fetched', function () use ($ctx, $evo, $ogg, $fetches) {
        qf_http('GET', $evo . '/__test/reset');
        $b = vm_book($ctx, vm_cfg($evo));
        $v = vm_voice($b, '256771300601');
        vm_media($evo, $v['wa'], $ogg);
        [$rc, $out] = vm_cli($ctx, $b, [], 120);
        $ctx->eq([$rc, vm_row($b, $v['media'])['status'], $fetches()], [0, 'fetched', 1], 'H01 fetched, once', $out);
    });

    $ctx->run('H02 under master with 20 s left no note is started and no attempt is spent (V37, V42)', function () use ($ctx, $evo, $ogg, $fetches, $evRow) {
        qf_http('GET', $evo . '/__test/reset');
        $b = vm_book($ctx, vm_cfg($evo));
        $v = vm_voice($b, '256771300602');
        vm_media($evo, $v['wa'], $ogg);
        [$rc, $out] = vm_cli($ctx, $b, [], 20);
        $row = vm_row($b, $v['media']);
        $ctx->eq([$rc, $row['status'], (int)$row['attempts'], $fetches(), $evRow($b, $v['event'])['status'] ?? ''], [0, 'pending', 0, 0, 'pending'],
                 'H02 the note waits for a run that can finish it: not fetched, the row\'s attempts untouched, the event deferred', $out);
        $ctx->eq((int)($evRow($b, $v['event'])['attempts'] ?? -1), 0, 'H02 and the queue\'s attempt count is untouched too (a defer, not a failure)');
        $b5 = vm_book($ctx, vm_cfg($evo));
        $v5 = vm_voice($b5, '256771300622');
        $ev5 = function () use ($b5, $v5): array { return vm_q($b5, 'SELECT * FROM events WHERE id = ?', [$v5['event']])[0] ?? []; };
        $before = $ev5();
        [$rc2] = vm_cli($ctx, $b5, [], 5);
        $ctx->eq([$rc2, vm_row($b5, $v5['media'])['status'], $fetches(), $ev5()], [0, 'pending', 0, $before],
                 'H02 with 5 s left the runner does not start at all: the event never claimed');
    });

    $ctx->run('H03 one note at a time under master: nothing left processing (V43)', function () use ($ctx, $evo, $ogg) {
        qf_http('GET', $evo . '/__test/reset');
        $b = vm_book($ctx, vm_cfg($evo));
        $one = vm_voice($b, '256771300603'); $two = vm_voice($b, '256771300613');
        vm_media($evo, $one['wa'], $ogg); vm_media($evo, $two['wa'], $ogg);
        [$rc] = vm_cli($ctx, $b, [], 15);
        $ctx->eq([$rc, (int)vm_q($b, "SELECT COUNT(*) n FROM events WHERE event_type = 'ai.media' AND status = 'processing'")[0]['n'],
                  (int)vm_q($b, "SELECT COUNT(*) n FROM events WHERE event_type = 'ai.media' AND status = 'pending'")[0]['n']], [0, 0, 2],
                 'H03 with 15 s left both events are back in the queue, neither left processing');
        vm_due($b);
        [$rc2] = vm_cli($ctx, $b, [], 120);
        $ctx->eq([$rc2, vm_row($b, $one['media'])['status'], vm_row($b, $two['media'])['status']], [0, 'fetched', 'fetched'], 'H03 control: with time, once due, both are fetched');
    });

    $ctx->run('H04 spawned by the webhook it runs on its own budget', function () use ($ctx, $evo, $ogg) {
        qf_http('GET', $evo . '/__test/reset');
        $b = vm_book($ctx, vm_cfg($evo));
        $v = vm_voice($b, '256771300604');
        vm_media($evo, $v['wa'], $ogg);
        [$rc, $out] = vm_cli($ctx, $b, ['--spawned']);
        $ctx->eq([$rc, vm_row($b, $v['media'])['status']], [0, 'fetched'], 'H04 fetched', $out);
        $log = (string)@file_get_contents($b['data'] . '/ai_platform.log');
        $ctx->t(strpos($log, 'media worker: processed=1') !== false && strpos($log, OGG_OPUS_MARKER) === false && strpos($log, '256771300604') === false,
                'H04 its trace in ai_platform.log: counts, ids and codes — no audio, no phone', $log);
    });

    $ctx->run('H05 the runner\'s gates: South Sudan, the assistant off, the switch off', function () use ($ctx, $evo, $ogg, $fetches) {
        foreach (['H05 South Sudan, every key on: nothing at all' => ['tenant_profile' => 'south-sudan'] + vm_rung('handover'),
                  'H05 the assistant off: nothing at all' => ['ai_enabled' => false]] as $label => $over) {
            qf_http('GET', $evo . '/__test/reset');
            $b = vm_book($ctx, vm_cfg($evo, $over));
            $v = vm_voice($b, '256771300605');
            vm_media($evo, $v['wa'], $ogg);
            [$rc] = vm_cli($ctx, $b, ['--spawned']);
            $ctx->eq([$rc, vm_row($b, $v['media'])['status'], $fetches(), vm_q($b, 'SELECT status FROM events WHERE id = ?', [$v['event']])[0]['status'] ?? ''],
                     [0, 'pending', 0, 'pending'], $label);
        }
        qf_http('GET', $evo . '/__test/reset');
        $b = vm_book($ctx, vm_cfg($evo, ['ai_media_enabled' => null]));
        $v = vm_voice($b, '256771300615');
        [$rc] = vm_cli($ctx, $b, ['--spawned']);
        $row = vm_row($b, $v['media']);
        $ctx->eq([$rc, $row['status'], $row['failure_reason'], $fetches()], [0, 'skipped', 'media_disabled', 0],
                 'H05 the switch off with an event an earlier on-period queued: the row settled skipped, nothing fetched');
    });
}];
