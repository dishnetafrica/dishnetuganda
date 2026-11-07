<?php
declare(strict_types=1);
/**
 * Section M of tests/test_voice_ack.php — VN-ON1, step 5's check (5.18.104, root docs/73 §H.3.20; ruling R18; VK4's,
 * VK5's and VK8's lowered-rung VN-ON1 halves; T15's VN-ON1 copy, T16, T28). TEST ONLY. Each world is planted row by row
 * as the media worker leaves it (the rows B5's tests drive through the real worker), at stated moments, and judged on a
 * fresh $ro: a correct act with the world moved after it reads PASS; each control, planted on a copy, reads FAIL.
 * total_changes() on $ro stays 0 throughout.
 */
$VA_SECTIONS['M'] = ['title' => 'VN-ON1: the fetch rate, R1 did nothing to anyone, its own rung\'s calls, the run time a note', 'run' => function (QfCtx $ctx): void {
    va_load($ctx);
    $evo = qf_fake_evo();
    $day = 86400;
    $S = va_noon() - 3 * $day;
    $world = function () use ($ctx, $evo, $S): array {
        $b = vm_book($ctx, va_cfg($evo, vm_rung('fetch_only') + ['ai_media_since' => UtcClock::stamp($S)]));
        $b['notes'] = vp_notes($b, 20, $S + 3600, 'fetch_only');
        return $b;
    };
    $on1 = function (array $b, array $over = []) use ($S, $day) { return va_check($b, 'VN-ON1', $S + 2 * $day + 7200, array_merge($b['cfg'], $over)); };

    $ctx->run('M01 the run time is a note, never judged (VK5; T16)', function () use ($ctx, $world, $on1) {
        $b = $world();
        $got = [];
        foreach ([61000, -1, null] as $ms) {
            $b['store']->save('master_schedule.json', $ms === null ? ['customer_reminders' => ['last_run' => 1]] : ['ai_media' => ['last_run' => 1, 'duration_ms' => $ms]]);
            $r = $on1($b);
            $got[] = [$r['status'], $r['_changes'], implode(' ', preg_grep('/^ai_media last run/', $r['notes']))];
        }
        $ctx->eq($got, [['PASS', 0, 'ai_media last run: duration_ms 61000'], ['PASS', 0, 'ai_media last run: duration_ms -1'],
                        ['PASS', 0, 'ai_media last run: no ai_media entry']], 'M01 PASS each time; the value printed, or that there is none');
        $r = $on1($b);
        $ctx->eq([$r['counts']['fetch_ok'] ?? 0, $r['counts']['own_rung_rows'] ?? 0, $r['counts']['audio_rows'] ?? 0], [20, 20, 20], 'M01 control: 20 rows fetched, all at R1');
    });

    $ctx->run('M02 R1\'s own rung: calls, hand-overs and turns at later rungs are not R1\'s (VK4; T15)', function () use ($ctx, $world, $on1, $S) {
        $b = $world();
        // Step 7 then 8 working since: dry-run calls, a hand-over with its escalation, a reply turn with its changed body.
        foreach (vp_notes($b, 4, $S + 50000, 'dry_run', 600, ['phone_base' => 100]) as $n) vp_call($b, $n, $n['at'] + 2, ['rung' => 'dry_run']);
        $h = vp_note($b, '256771491001', $S + 60000, ['rung' => 'handover', 'status' => 'understood', 'understanding' => 'VP transcript (test)']);
        vp_call($b, $h, $h['at'] + 2, ['rung' => 'handover']);
        vp_escalation($b, $h['conv'], $h['upd'] + 1);
        $r4 = vp_note($b, '256771491002', $S + 61000, ['rung' => 'reply', 'status' => 'understood', 'understanding' => 'VP transcript (test)', 'body' => '[voice message] VP (test)']);
        vp_turn($b, $r4, $r4['upd'] + 1);
        $r = $on1($b);
        $ctx->t(vp_is($r, 'PASS') && !isset($r['counts']['r1_calls']), 'M02 VN-ON1 reads PASS with the later rungs at work', json_encode($r));
        foreach (['a call recording rung fetch_only' => function ($c) { vp_call($c, $c['notes'][3], $c['notes'][3]['at'] + 5, ['rung' => 'fetch_only']); },
                  'an R1 row\'s body changed' => function ($c) { $c['pdo']->prepare('UPDATE wa_messages SET body = ? WHERE wa_message_id = ?')->execute(['[voice message] x', $c['notes'][4]['wa']]); },
                  'an R1 row handed over' => function ($c) { vp_escalation($c, $c['notes'][5]['conv'], $c['notes'][5]['upd'] + 1); },
                  'an R1 row given a turn' => function ($c) { vp_turn($c, $c['notes'][6], $c['notes'][6]['upd'] + 1); }] as $label => $plant) {
            $c = va_copy($ctx, $b) + ['notes' => $b['notes']];
            $plant($c);
            $ctx->eq(substr($on1($c)['status'], 0, 7), 'FAIL r1', "M02 control on a copy: {$label} reads FAIL");
        }
    });

    $ctx->run('M03 the rung lowered: an R1 note whose first call recorded dry_run is not an R1 call (VK8; T28)', function () use ($ctx, $world, $on1) {
        $b = $world();
        $n = $b['notes'][7];
        vp_call($b, $n, $n['at'] + 2, ['rung' => 'dry_run', 'outcome' => 'failed', 'reason' => 'provider_error']);   // the 503, then off voice-dry-run
        $ctx->eq(vm_q($b, 'SELECT voice_rung, status FROM wa_media WHERE id = ?', [$n['media']])[0], ['voice_rung' => 'fetch_only', 'status' => 'fetched'],
                 'M03 control: the retry settled the note fetched at rung fetch_only');
        $r = $on1($b);
        $ctx->t(vp_is($r, 'PASS'), 'M03 two days on, VN-ON1 reads PASS', json_encode($r));
        $c = va_copy($ctx, $b);
        vp_call($c, $n + [], $n['at'] + 20, ['rung' => 'fetch_only', 'attempt' => 2]);
        $ctx->eq($on1($c)['status'], 'FAIL r1_called', 'M03 control on a copy: a call recording rung fetch_only reads FAIL');
    });

    $ctx->run('M04 the fetch rate: 90% of the settled fetches, a retry still to come not counted', function () use ($ctx, $world, $on1, $S) {
        $b = $world();
        $f = vp_notes($b, 2, $S + 70000, 'fetch_only', 600, ['phone_base' => 200, 'status' => 'failed', 'reason' => 'fetch_failed', 'attempts' => 3, 'fetched' => null]);
        $retry = vp_notes($b, 3, $S + 72000, 'fetch_only', 600, ['phone_base' => 300, 'status' => 'failed', 'reason' => 'timeout', 'attempts' => 1, 'fetched' => null]);
        $r = $on1($b);
        $ctx->t(vp_is($r, 'PASS') && ($r['counts']['fetch_failed'] ?? -1) === 2, 'M04 20 of 22 (91%) reads PASS; the three still to retry are not counted', json_encode($r['counts']));
        $c = va_copy($ctx, $b);
        $c['pdo']->exec("UPDATE wa_media SET attempts = 3 WHERE failure_reason = 'timeout'");
        $rc = $on1($c);
        $ctx->eq([$rc['status'], (bool)preg_grep('/stop P4 here/', $rc['notes'])], ['FAIL fetch_unavailable', true], 'M04 control on a copy: the retries spent, 20 of 25 reads FAIL with its note');
    });

    $ctx->run('M05 an ai.media event left processing past its lease is FAIL; one inside it is not', function () use ($ctx, $world, $on1, $S, $day) {
        $b = $world();
        $now = $S + 2 * $day + 7200;
        $n = $b['notes'][9];
        vp_event($b, 'ai.media', 'media', $n['media'], $now - 30, ['status' => 'processing', 'locked_at' => UtcClock::stamp($now - 30), 'by' => 'evo_webhook']);
        $ctx->t(vp_is($on1($b), 'PASS'), 'M05 a lease 30 s old: PASS');
        $c = va_copy($ctx, $b);
        $c['pdo']->exec("UPDATE events SET locked_at = '" . UtcClock::stamp($now - 600) . "' WHERE status = 'processing'");
        $ctx->eq($on1($c)['status'], 'FAIL left_processing', 'M05 control on a copy: a lease 10 min old reads FAIL');
    });
}];
