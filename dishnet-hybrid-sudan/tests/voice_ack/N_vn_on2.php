<?php
declare(strict_types=1);
/**
 * Section N of tests/test_voice_ack.php — VN-ON2, step 7's check (5.18.104, root docs/73 §H.3.20; ruling R18; VK4's and
 * VK11's VN-ON2 halves; T15's VN-ON2 copy, T20; VN-K1). TEST ONLY. The calls are planted as the media worker records
 * them (B5's tests drive the real worker against the fake OpenAI server), at stated moments; the setting is then
 * narrowed and the check run on a fresh $ro. total_changes() on $ro stays 0 throughout.
 */
$VA_SECTIONS['N'] = ['title' => 'VN-ON2: Opus accepted, each call within its own cap, the dry run said nothing, the key in no log', 'run' => function (QfCtx $ctx): void {
    va_load($ctx);
    $evo = qf_fake_evo();
    $day = 86400;
    $S = va_noon() - 3 * $day;
    $world = function () use ($ctx, $evo, $S, $day): array {
        $b = vm_book($ctx, va_cfg($evo, vm_rung('dry_run') + ['ai_media_voice_since' => UtcClock::stamp($S), 'ai_media_voice_daily_minutes' => '120']));
        $b['notes'] = vp_notes($b, 10, $S + $day - 3600, 'dry_run');           // ten calls of 10 min on one Kampala day: 100 of 120 minutes
        foreach ($b['notes'] as $n) vp_call($b, $n, $n['at'] + 2, ['reserved' => 600, 'billed' => 600, 'cap' => 7200]);
        return $b;
    };
    $on2 = function (array $b, array $over = []) use ($S, $day) { return va_check($b, 'VN-ON2', $S + 2 * $day + 7200, array_merge($b['cfg'], $over)); };

    $ctx->run('N01 each call judged by the cap its own reservation applied, the setting narrowed afterwards (VK11; T20)', function () use ($ctx, $world, $on2) {
        $b = $world();
        $ctx->eq((int)vm_q($b, 'SELECT SUM(seconds_billed) s FROM wa_voice_calls')[0]['s'], 6000, 'N01 control: the day used 100 minutes');
        $r = $on2($b, ['ai_media_voice_daily_minutes' => '30']);
        $ctx->t(vp_is($r, 'PASS') && ($r['counts']['ok'] ?? 0) === 10, 'N01 VN-ON2 reads PASS after the cap was set to 30', json_encode($r));
        $c = va_copy($ctx, $b) + ['notes' => $b['notes']];
        $n = vp_note($c, '256771492001', $c['notes'][9]['at'] + 600, ['rung' => 'dry_run']);
        vp_call($c, $n, $n['at'] + 2, ['reserved' => 600, 'cap' => 6000]);
        $ctx->eq($on2($c, ['ai_media_voice_daily_minutes' => '30'])['status'], 'FAIL over_cap', 'N01 control on a copy: a call whose day passes its own cap_day_s reads FAIL');
    });

    $ctx->run('N02 R2\'s own rung: a later rung\'s hand-overs are not R2\'s (VK4; T15)', function () use ($ctx, $world, $on2, $S) {
        $b = $world();
        $h = vp_note($b, '256771492011', $S + 100000, ['rung' => 'handover', 'status' => 'understood', 'understanding' => 'VP transcript (test)']);
        vp_call($b, $h, $h['at'] + 2, ['rung' => 'handover']);
        vp_escalation($b, $h['conv'], $h['upd'] + 1);
        $r = $on2($b);
        $ctx->t(vp_is($r, 'PASS') && ($r['counts']['own_rung_rows'] ?? 0) === 10, 'N02 VN-ON2 reads PASS with step 8 at work', json_encode($r));
        foreach (['an R2 row handed over' => function ($c) { vp_escalation($c, $c['notes'][2]['conv'], $c['notes'][2]['upd'] + 1); },
                  'an R2 row with its understanding kept' => function ($c) { $c['pdo']->prepare('UPDATE wa_media SET understanding = ? WHERE id = ?')->execute(['x', $c['notes'][3]['media']]); },
                  'an R2 row\'s body changed' => function ($c) { $c['pdo']->prepare('UPDATE wa_messages SET body = ? WHERE wa_message_id = ?')->execute(['[voice message] x', $c['notes'][4]['wa']]); }] as $label => $plant) {
            $c = va_copy($ctx, $b) + ['notes' => $b['notes']];
            $plant($c);
            $ctx->eq($on2($c)['status'], 'FAIL r2_acted', "N02 control on a copy: {$label} reads FAIL");
        }
    });

    $ctx->run('N03 Opus accepted; a provider refusal and a low ok rate are FAIL', function () use ($ctx, $world, $on2) {
        $b = $world();
        $ctx->t(vp_is($on2($b), 'PASS'), 'N03 control: ten Opus calls accepted read PASS');
        $c = va_copy($ctx, $b);
        $c['pdo']->exec("UPDATE wa_media SET mimetype = 'audio/mpeg', fetched_mimetype = 'audio/mpeg'");
        $ctx->eq($on2($c)['status'], 'FAIL opus_rejected', 'N03 no Ogg call accepted: FAIL opus_rejected');
        $c = va_copy($ctx, $b);
        $c['pdo']->exec("UPDATE wa_voice_calls SET outcome = 'failed', reason = 'provider_auth' WHERE id = (SELECT MAX(id) FROM wa_voice_calls)");
        $ctx->eq($on2($c)['status'], 'FAIL provider_auth', 'N03 one call refused for the key: FAIL provider_auth');
        $c = va_copy($ctx, $b);
        $c['pdo']->exec("UPDATE wa_voice_calls SET outcome = 'failed', reason = 'provider_error' WHERE id IN (SELECT id FROM wa_voice_calls ORDER BY id DESC LIMIT 3)");
        $ctx->eq($on2($c)['status'], 'FAIL ok_rate', 'N03 seven of ten ok: FAIL ok_rate');
    });

    $ctx->run('N04 the stored key in no log file (VN-K1), nor in the installed tab (VN-K2), and never printed', function () use ($ctx, $world, $on2) {
        $b = $world();
        file_put_contents($b['data'] . '/media_worker.log', "media: 10 calls, 0 failed\n");
        $r = $on2($b);
        $ctx->t(vp_is($r, 'PASS') && !isset($r['counts']['key_in_tab']), 'N04 a log without the key, the installed tab printing none (VN-K2): PASS', json_encode($r));
        $c = va_copy($ctx, $b);
        file_put_contents($c['data'] . '/media_worker.log', "media: header Authorization: Bearer " . VM_KEY . "\n");
        $rc = $on2($c);
        $ctx->eq([$rc['status'], strpos(json_encode($rc), VM_KEY)], ['FAIL key_in_log', false], 'N04 control on a copy: the key in a log reads FAIL, and the result does not print it');
    });
}];
