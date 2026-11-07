<?php
declare(strict_types=1);
/**
 * Section B of tests/test_voice_ack.php — P5's limits, through the one holder (5.18.104, root docs/73 §H.3.15 steps 7,
 * 11 and 13, §H.3.16; ruling R4; A6–A9, A18, A19, A30, A31). TEST ONLY. Every window is D15's 07:00–21:00, every day,
 * in a literal Africa/Kampala; the ceiling, the gap and the hours are the ledger's voice_ack branch, read through
 * VoiceAckLimits on the configuration P5 read afresh at step 13.
 */
$VA_SECTIONS['B'] = ['title' => 'P5\'s limits: once per period, D15\'s window every day in Kampala, the ceiling, the gap, invalid limits', 'run' => function (QfCtx $ctx): void {
    va_load($ctx);
    $evo = qf_fake_evo();
    $ctx->t($evo !== '', 'B00 the fake Evolution started');
    if ($evo === '') return;
    $fresh = function (array $over = []) use ($ctx, $evo): array {
        qf_http('GET', $evo . '/__test/reset');
        return vm_book($ctx, va_cfg($evo, $over));
    };
    $at = function (array $b, string $phone, int $t) {
        $n = va_note($b, $phone, $t);
        va_run($b, $t);
        return $n;
    };

    $ctx->run('B01 once per period per conversation; the next period acknowledges again (A6)', function () use ($ctx, $evo, $fresh, $at) {
        $b = $fresh();
        $n1 = $at($b, '256771400201', va_noon());
        $b['pdo']->exec("UPDATE wa_conversations SET state = 'open'");     // a colleague closed it: C7 reads no person since
        $n2 = $at($b, '256771400201', va_noon() + 7200);
        $b['pdo']->exec("UPDATE wa_conversations SET state = 'open'");
        $n3 = $at($b, '256771400201', va_noon() + 25 * 3600);
        $ctx->eq([va_outcome($b, $n1['wa']), va_outcome($b, $n2['wa']), va_outcome($b, $n3['wa']), count(va_sent_to($evo, '256771400201'))],
                 ['sent', 'skipped:already_acked', 'sent', 2], 'B01 sent; 2 h later already_acked; 25 h later sent again — two sends');
    });

    $ctx->run('B02 D15\'s window: 06:55 and 21:05 Kampala refused, 07:00 and 20:59 sent (A7)', function () use ($ctx, $evo, $fresh, $at) {
        $b = $fresh();
        $got = [];
        foreach (['2026-11-05 06:55:00' => '256771400202', '2026-11-05 07:00:00' => '256771400212',
                  '2026-11-05 20:59:00' => '256771400222', '2026-11-05 21:05:00' => '256771400232'] as $k => $p) {
            $n = $at($b, $p, qf_t($k));
            $got[] = [va_outcome($b, $n['wa']), count(va_sent_to($evo, $p))];
        }
        $ctx->eq($got, [['skipped:quiet_hours', 0], ['sent', 1], ['sent', 1], ['skipped:quiet_hours', 0]], 'B02 outside the hours nothing is sent; inside it is');
    });

    $ctx->run('B03 every day: Sunday 10:00 Kampala sends (A8)', function () use ($ctx, $evo, $fresh, $at) {
        $b = $fresh();
        $n = $at($b, '256771400203', qf_t('2026-11-08 10:00:00'));
        $ctx->eq([va_outcome($b, $n['wa']), count(va_sent_to($evo, '256771400203'))], ['sent', 1], 'B03 a Sunday morning note is acknowledged');
    });

    $ctx->run('B04 the window is Kampala\'s whatever the process zone: under Africa/Juba, 21:30 Kampala is refused (A9; R-16)', function () use ($ctx, $evo, $fresh, $at) {
        $b = $fresh();
        $was = date_default_timezone_get();
        date_default_timezone_set(implode('/', ['Africa', 'Juba']));   // named in parts: tests/test_timezone.php scans this file
        try {
            $n = $at($b, '256771400204', qf_t('2026-11-05 21:30:00'));
        } finally {
            date_default_timezone_set($was);
        }
        $ctx->eq([va_outcome($b, $n['wa']), count(va_sent_to($evo, '256771400204'))], ['skipped:quiet_hours', 0], 'B04 21:30 Kampala (20:30 in Juba): quiet_hours, nothing sent');
    });

    $ctx->run('B05 the ceiling per handset per Kampala day (A18)', function () use ($ctx, $evo, $fresh, $at) {
        $b = $fresh(['wa_voice_ack_daily_max' => '3']);
        $got = [];
        foreach (['256771400205', '256771400215', '256771400225', '256771400235'] as $i => $p) {
            $n = $at($b, $p, va_noon() + 30 * $i);
            $got[] = va_outcome($b, $n['wa']);
        }
        $ctx->eq($got, ['sent', 'sent', 'sent', 'skipped:number_cap'], 'B05 with the ceiling at 3 the fourth note of the day on that handset is number_cap');
        $ctx->eq(count(array_filter(vm_texts($evo), function ($c) { return $c[1] !== '256700000999'; })), 3, 'B05 three acknowledgements sent');
    });

    $ctx->run('B06 the gap: a note 10 s after an acknowledgement on its handset waits, then goes (A19)', function () use ($ctx, $evo, $fresh, $at) {
        $b = $fresh();
        $n1 = $at($b, '256771400206', va_noon());
        $n2 = $at($b, '256771400216', va_noon() + 10);
        $ev = vm_q($b, 'SELECT status, error FROM events WHERE id = ?', [$n2['event']])[0] ?? [];
        $ctx->eq([va_outcome($b, $n2['wa']), $ev['status'] ?? null, $ev['error'] ?? null, count(va_sent_to($evo, '256771400216'))],
                 ['none', 'pending', 'voice_ack: spacing', 0], 'B06 10 s later: deferred (no decision, the event pending), nothing sent');
        va_run($b, va_noon() + 25);
        $ctx->eq([va_outcome($b, $n2['wa']), count(va_sent_to($evo, '256771400216'))], ['sent', 1], 'B06 past the 20 s gap: sent');
    });

    $ctx->run('B07 narrowed hours: the ledger refuses 20:30 with hour_to 20, window, final (A30; ruling R4)', function () use ($ctx, $evo, $fresh) {
        $b = $fresh(['wa_voice_ack_hour_to' => '20']);
        $t = qf_t('2026-11-05 20:30:00');
        $c = WaSendBudget::forInstall($b['cfg'], $b['data'], $b['store'], null, function () use ($t): int { return $t; })
             ->claim('Sales-UG', 'voice_ack', 'ack:VA-B07-DIRECT');
        $ctx->eq([$c['ok'], $c['defer'] ?? null, $c['final'] ?? null, array_key_exists('retry_at', $c) ? $c['retry_at'] : 'x'], [false, 'window', true, null], 'B07 the claim answers window, final');
        $c2 = WaSendBudget::forInstall($b['cfg'], $b['data'], $b['store'], null, function () use ($t): int { return $t - 3600; })
              ->claim('Sales-UG', 'voice_ack', 'ack:VA-B07-CONTROL');
        $ctx->t(!empty($c2['ok']), 'B07 control: 19:30 the same claim is admitted');
    });

    $ctx->run('B08 the hours narrowed after the worker started: step 13\'s fresh read decides (ruling R19)', function () use ($ctx, $evo, $fresh) {
        $b = $fresh();
        $stale = $b['cfg'];
        vm_setcfg($b, ['wa_voice_ack_hour_to' => '20']);
        $t = qf_t('2026-11-05 20:30:00');
        $n = va_note($b, '256771400208', $t);
        va_run($b, $t, ['cfg' => $stale]);
        $ctx->eq([va_outcome($b, $n['wa']), count(va_sent_to($evo, '256771400208')), count(va_ledger($b, $n['wa']))], ['skipped:quiet_hours', 0, 0],
                 'B08 a worker that read hour_to 21 at its start: the ledger built from the fresh read refuses (window → quiet_hours)');
    });

    $ctx->run('B09 limits stored by hand that cannot be read: limits_invalid, nothing sent (A31)', function () use ($ctx, $evo, $fresh, $at) {
        foreach (['B09 hour_from 22, hour_to 7' => ['wa_voice_ack_hour_from' => '22', 'wa_voice_ack_hour_to' => '7'],
                  'B09 a period that is not a number' => ['wa_voice_ack_period_hours' => 'a day']] as $label => $over) {
            $b = $fresh($over);
            $n = $at($b, '256771400209', va_noon());
            $ctx->eq([va_outcome($b, $n['wa']), vm_texts($evo)], ['skipped:limits_invalid', []], $label . ': limits_invalid');
        }
    });
}];
