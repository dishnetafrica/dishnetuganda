<?php
declare(strict_types=1);
/**
 * Section G of tests/test_voice_media.php — the cost bound, lib/VoiceBudget.php (5.18.104, root docs/73 §H.3.10;
 * rulings R18, R19). TEST ONLY. Every call reserved before it is made, in seconds counted from the packets; the day's
 * minutes and the conversation's notes capped; the cap and the rung each reservation applied recorded on its row; a
 * reservation counts whatever happens after it; settled once.
 */
$VM_SECTIONS['G'] = ['title' => 'The budget: reserved before the call, capped per day and per conversation, recorded as applied', 'run' => function (QfCtx $ctx): void {
    vm_load($ctx);
    vm_voiceack($ctx);
    $evo = qf_fake_evo();
    $oa  = vm_fake_openai();
    $ctx->t($evo !== '' && $oa !== '', 'G00 the fakes started');
    if ($evo === '' || $oa === '') return;
    $day = (new \DateTime('now', new \DateTimeZone(QF_TZ)))->format('Y-m-d');
    $plant = function (array $b, int $media, int $conv, int $seconds, string $outcome = 'ok') use ($day) {
        $b['pdo']->prepare("INSERT INTO wa_voice_calls (media_id, conversation_id, attempt, model, local_day, seconds_reserved, seconds_billed, cap_day_s, voice_rung, outcome)
                            VALUES (?, ?, 1, 'whisper-1', ?, ?, ?, 7200, 'dry_run', ?)")
                 ->execute([$media, $conv, $day, $seconds, $outcome === 'started' ? null : $seconds, $outcome]);
    };

    $ctx->run('G01 control: a reservation per call, recording the cap and the rung it applied; settled once', function () use ($ctx, $evo) {
        $b = vm_book($ctx, vm_cfg($evo, vm_rung('dry_run') + ['ai_media_voice_daily_minutes' => 30]));
        $vb = new VoiceBudget($b['pdo'], $b['cfg']);
        $r = $vb->reserve(11, 5, 1, 7, 'whisper-1', 'handover');
        $row = vm_q($b, 'SELECT * FROM wa_voice_calls WHERE id = ?', [(int)($r['id'] ?? 0)])[0] ?? [];
        $ctx->eq([$r['ok'] ?? null, (int)$row['seconds_reserved'], (int)$row['cap_day_s'], $row['voice_rung'], $row['outcome'], $row['local_day']],
                 [true, 7, 1800, 'handover', 'started', $vb->localDay()], 'G01 the started row: 7 s, the cap 30 min as applied, the rung');
        $ctx->eq([$vb->settle((int)$r['id'], 'ok', 200, 900, 6), $vb->settle((int)$r['id'], 'failed', 500, 1, 99)], [true, false], 'G01 settled once; a second settle changes nothing');
        $ctx->eq((int)vm_q($b, 'SELECT seconds_billed FROM wa_voice_calls WHERE id = ?', [(int)$r['id']])[0]['seconds_billed'], 6, 'G01 the billed seconds kept');
        $dup = $vb->reserve(11, 5, 1, 7, 'whisper-1', 'handover');
        $ctx->eq([$dup['ok'], $dup['reason'] ?? ''], [false, 'duplicate'], 'G01 the same note and attempt again: duplicate');
        $late = new VoiceBudget($b['pdo'], $b['cfg'], (new \DateTime('2026-11-02 22:30:00', new \DateTimeZone('UTC')))->getTimestamp());
        $ctx->eq($late->localDay(), '2026-11-03', 'G01 the day is Kampala\'s: 22:30 UTC is the next morning there');
    });

    $ctx->run('G02 the day\'s minutes (V17)', function () use ($ctx, $evo, $oa, $plant) {
        foreach (['G02 control: 50 s used of 60, a 3 s note fits' => [50, 1, 1], 'G02 58 s used of 60: a 3 s note is refused budget_daily' => [58, 1, 0],
                  'G02 a reservation with no settle (a crash) counts' => [-58, 1, 0], 'G02 0 minutes: no call at all' => [0, 0, 0]] as $label => [$used, $min, $calls]) {
            qf_http('GET', $evo . '/__test/reset'); qf_http('GET', $oa . '/__test/reset');
            $b = vm_book($ctx, vm_cfg($evo, vm_rung('dry_run') + ['ai_media_voice_daily_minutes' => $min]));
            if ($used !== 0) $plant($b, 900, 901, abs($used), $used < 0 ? 'started' : 'ok');
            $v = vm_voice($b, '256771300501', 'sales');
            vm_media($evo, $v['wa'], ogg_opus(['seconds' => 3.0]));
            vm_run($b, $oa);
            $row = vm_row($b, $v['media']);
            $ctx->eq([count(vm_oa_calls($oa)), $calls ? $row['status'] : $row['failure_reason'], (int)vm_q($b, "SELECT COUNT(*) n FROM events WHERE event_type = 'wa.escalation'")[0]['n']],
                     [$calls, $calls ? 'understood' : 'budget_daily', 0], $label . ' (recorded only at R2)');
        }
        qf_http('GET', $evo . '/__test/reset'); qf_http('GET', $oa . '/__test/reset');
        $b = vm_book($ctx, vm_cfg($evo, vm_rung('handover') + ['ai_media_voice_daily_minutes' => 1]));
        $plant($b, 900, 901, 60);
        $v = vm_voice($b, '256771300511');
        vm_media($evo, $v['wa'], ogg_opus(['seconds' => 3.0]));
        vm_run($b, $oa);
        $esc = vm_q($b, "SELECT payload FROM events WHERE event_type = 'wa.escalation'");
        $ctx->eq([count(vm_oa_calls($oa)), array_map(function ($e) { return json_decode($e['payload'], true)['reason'] ?? ''; }, $esc)],
                 [0, ['a voice message could not be transcribed (budget_daily) — listen to it in WhatsApp']], 'G02 at R3 a capped note goes to a person');
    });

    $ctx->run('G03 the conversation\'s notes per day (V18)', function () use ($ctx, $evo, $oa) {
        qf_http('GET', $evo . '/__test/reset'); qf_http('GET', $oa . '/__test/reset');
        $b = vm_book($ctx, vm_cfg($evo, vm_rung('dry_run') + ['ai_media_voice_conversation_daily_max' => 1]));
        $one = vm_voice($b, '256771300502');
        $two = vm_voice($b, '256771300502');
        $other = vm_voice($b, '256771300512');
        foreach ([$one, $two, $other] as $v) vm_media($evo, $v['wa'], ogg_opus(['seconds' => 2.0]));
        vm_run($b, $oa); vm_run($b, $oa); vm_run($b, $oa);
        $ctx->eq([vm_row($b, $one['media'])['status'], vm_row($b, $two['media'])['failure_reason'], vm_row($b, $other['media'])['status'], count(vm_oa_calls($oa))],
                 ['understood', 'budget_conversation', 'understood', 2],
                 'G03 control (the first note, another conversation\'s) and case (a second note the same day): refused budget_conversation');
        $vb = new VoiceBudget($b['pdo'], $b['cfg']);
        $ctx->eq($vb->reserve($one['media'], $one['conv'], 2, 2, 'whisper-1', 'dry_run')['ok'] ?? null, true, 'G03 the same note\'s retry is not a second note');
    });
}];
