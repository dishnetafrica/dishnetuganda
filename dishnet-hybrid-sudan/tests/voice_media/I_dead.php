<?php
declare(strict_types=1);
/**
 * Section I of tests/test_voice_media.php — when the automatic path gives up: the queue's dead letter (onDead) and the
 * lost-worker guard, each deciding who is told on the rung of a read of its own, never the worker's start-time
 * configuration (5.18.104, root docs/73 §H.3.6 change 5; ruling R19, Q17-V01). TEST ONLY.
 */
$VM_SECTIONS['I'] = ['title' => 'Dead letters and lost workers: told at R3/R4 only, on a read of their own', 'run' => function (QfCtx $ctx): void {
    vm_load($ctx);
    vm_voiceack($ctx);
    $evo = qf_fake_evo();
    $oa  = vm_fake_openai();
    $ctx->t($evo !== '' && $oa !== '', 'I00 the fakes started');
    if ($evo === '' || $oa === '') return;
    $esc = function (array $b): int { return (int)vm_q($b, "SELECT COUNT(*) n FROM events WHERE event_type = 'wa.escalation'")[0]['n']; };
    // A note whose fetch fails (retryable) on its event's last attempt, run by a worker built on $start, the file at $now.
    $dead = function (string $phone, array $start, array $now) use ($ctx, $evo, $oa): array {
        qf_http('GET', $evo . '/__test/reset');
        $b = vm_book($ctx, vm_cfg($evo, $start));
        $v = vm_voice($b, $phone);
        $b['pdo']->exec('UPDATE events SET max_attempts = 1 WHERE id = ' . $v['event']);
        vm_media($evo, $v['wa'], 'x', 'audio/ogg', ['fail' => 503]);
        $stale = $b['cfg'];
        vm_setcfg($b, $now);
        vm_run($b, $oa, ['cfg' => $stale]);
        return [$b, $v];
    };
    // A note whose row was claimed as often as its event allows (a worker lost each time).
    $lost = function (string $phone, array $start, array $now, string $rowRung) use ($ctx, $evo, $oa): array {
        qf_http('GET', $evo . '/__test/reset');
        $b = vm_book($ctx, vm_cfg($evo, $start));
        $v = vm_voice($b, $phone);
        $b['pdo']->prepare("UPDATE wa_media SET attempts = 5, status = 'fetching', voice_rung = ? WHERE id = ?")->execute([$rowRung !== '' ? $rowRung : null, $v['media']]);
        $stale = $b['cfg'];
        vm_setcfg($b, $now);
        vm_run($b, $oa, ['cfg' => $stale]);
        return [$b, $v];
    };

    $ctx->run('I01 the dead letter decides on its own read (V48)', function () use ($ctx, $dead, $esc, $evo) {
        [$b, $v] = $dead('256771300701', vm_rung('dry_run'), vm_rung('handover'));
        $r = vm_row($b, $v['media']);
        $ctx->eq([$r['status'], $r['voice_rung'], $esc($b), count(vm_texts($evo))], ['dead', 'handover', 1, 2],
                 'I01 control: built at R2, on voice-staff before it ran: dead at rung handover, handed over (alert and holding line)');
        [$b, $v] = $dead('256771300711', vm_rung('handover'), ['ai_media_voice_handover' => null]);
        $r = vm_row($b, $v['media']);
        $ctx->eq([$r['status'], $r['voice_rung'], $esc($b), count(vm_texts($evo)), vm_q($b, 'SELECT state FROM wa_conversations WHERE id = ?', [$v['conv']])[0]['state']],
                 ['dead', 'dry_run', 0, 0, 'new'], 'I01 built at R3, off voice-staff before it ran: dead, no alert, no holding line, not given rung handover');
    });

    $ctx->run('I02 the lost-worker guard decides on the event\'s rung (V48)', function () use ($ctx, $lost, $esc, $evo) {
        [$b, $v] = $lost('256771300702', vm_rung('handover'), [], '');
        $r = vm_row($b, $v['media']);
        $ctx->eq([$r['status'], $r['voice_rung'], $r['failure_reason'], $esc($b)], ['dead', 'handover', 'worker_lost', 1], 'I02 control: at R3 the row is dead at handover and a person told');
        [$b, $v] = $lost('256771300712', vm_rung('handover'), ['ai_media_voice_handover' => null], 'dry_run');
        $r = vm_row($b, $v['media']);
        $ctx->eq([$r['status'], $r['failure_reason'], $esc($b), count(vm_texts($evo))], ['dead', 'worker_lost', 0, 0],
                 'I02 built at R3, off voice-staff before it ran: dead, worker_lost, nobody told');
        [$b, $v] = $lost('256771300722', vm_rung('dry_run'), [], 'handover');
        $r = vm_row($b, $v['media']);
        $ctx->eq([$r['status'], $r['voice_rung'], $r['failure_reason'], $esc($b)], ['dead', 'handover', 'switched_off', 0],
                 'I02 a row last acted on at R3 dying at R2: recorded switched_off, its rung kept, nobody told');
    });

    $ctx->run('I03 a dead letter with no row left does nothing', function () use ($ctx, $evo) {
        $b = vm_book($ctx, vm_cfg($evo, vm_rung('handover')));
        $w = new MediaWorker($b['store'], $b['cfg'], 90, 1);
        $m = new \ReflectionMethod(MediaWorker::class, 'onDead'); $m->setAccessible(true);
        ob_start();
        try { $m->invoke($w, ['payload' => json_encode(['media_id' => 9999])], new \RuntimeException('x')); $ok = true; }
        catch (\Throwable $e) { $ok = false; }
        finally { $log = (string)ob_get_clean(); }
        $ctx->t($ok && strpos($log, 'no row left') !== false && (int)vm_q($b, 'SELECT COUNT(*) n FROM events')[0]['n'] === 0,
                'I03 logged, no throw, nothing emitted', $log);
    });
}];
