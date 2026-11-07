<?php
declare(strict_types=1);
/**
 * Section O of tests/test_voice_ack.php — VN-ON3, step 8's check (5.18.104, root docs/73 §H.3.20; ruling R18; VK4's,
 * VK6's, VK8's, VK11's and VK14's VN-ON3 halves; T15's VN-ON3 copy, T17's VN-ON3 copy, T21, T24, T25's VN-ON3 copy,
 * T27's VN-ON3 copy). TEST ONLY. Each world is planted as the media and reply workers leave it, at stated moments; the
 * world then moves (an acknowledgement stored after a row was decided, ai_enabled turned off, a prune) and the check
 * reads PASS on a fresh $ro; each control, planted on a copy, reads FAIL. total_changes() on $ro stays 0 throughout.
 */
$VA_SECTIONS['O'] = ['title' => 'VN-ON3: every voice note handed over once decided, bodies untouched, R-19\'s holding line judged by what the worker could read', 'run' => function (QfCtx $ctx): void {
    va_load($ctx);
    $evo = qf_fake_evo();
    $day = 86400;
    $now = va_noon();
    $world = function (int $sinceDays = 8, array $over = []) use ($ctx, $evo, $now, $day): array {
        return vm_book($ctx, va_cfg($evo, vm_rung('handover') + ['ai_media_voice_handover_since' => UtcClock::stamp($now - $sinceDays * $day)] + $over));
    };
    $on3 = function (array $b, array $over = []) use ($now) { return va_check($b, 'VN-ON3', $now, array_merge($b['cfg'], $over)); };
    // A note handed over by R3: its row, its done ai.media event, its media_worker escalation a second after it settled.
    $handed = function (array $b, string $phone, int $at, array $o = []): array {
        $n = vp_note($b, $phone, $at, $o + ['rung' => 'handover', 'status' => 'understood', 'understanding' => 'VP transcript (test)']);
        vp_media_event($b, $n);
        $n['esc'] = vp_escalation($b, $n['conv'], $n['upd'] + 1);
        return $n;
    };

    $ctx->run('O01 a holding line judged against the acknowledgements the worker could read (VK11; T21)', function () use ($ctx, $world, $on3, $handed, $now, $day) {
        $b = $world();
        $T0 = $now - 7 * $day;
        $n1 = $handed($b, '256771493001', $T0);
        vp_ack($b, $n1, $T0 + 2);                                                        // P5 acknowledged it; then off ack
        $n2 = $handed($b, '256771493001', $T0 + 300);                                    // 5 min later R3 hands the next note over, no line
        $r = $on3($b);
        $ctx->t(vp_is($r, 'PASS') && !isset($r['counts']['holding_lines_judged']), 'O01 control: both handed over, no holding line, PASS', json_encode($r));
        $c = va_copy($ctx, $b);
        vp_ack($c, $n2, $n2['upd'] + 30);                                                // an acknowledgement stored after the row was decided
        vp_out($c, $n2['conv'], $T0 + 700, 'line');                                      // a line after its event, > 10 min after the first ack
        $rc = $on3($c);
        $ctx->t(vp_is($rc, 'PASS') && ($rc['counts']['ack_after_row_decided'] ?? 0) === 1 && ($rc['counts']['holding_lines_judged'] ?? 0) === 1,
                'O01 seven days on, on a copy: PASS, the later acknowledgement printed 1', json_encode($rc));
        $c2 = va_copy($ctx, $b);
        vp_ack($c2, $n2, $n2['upd'] - 60);                                               // the same acknowledgement a minute before the row's updated_at
        vp_out($c2, $n2['conv'], $T0 + 700, 'line');
        $ctx->eq($on3($c2)['status'], 'FAIL holding_line_after_ack', 'O01 control on a copy: the acknowledgement a minute before the row was decided reads FAIL');
    });

    $ctx->run('O02 the interleaving: a line the reply worker\'s own hand-over may have sent is ambiguous, never FAIL (VK11; T24)', function () use ($ctx, $world, $on3, $now, $day) {
        $b = $world();
        $U0 = $now - 3 * $day;
        $n = vp_note($b, '256771493011', $U0 + 30, ['rung' => 'handover', 'status' => 'understood', 'understanding' => 'VP transcript (test)', 'settle_s' => 60]);
        vp_media_event($b, $n);
        vp_ack($b, $n, $U0);                                                             // the note acknowledged
        $ai = vp_escalation($b, $n['conv'], $U0 + 60, 'ai_reply_worker', 'the AI had no answer (test)');   // the typed message handed over, its line held
        vp_escalation($b, $n['conv'], $n['upd'] + 1);                                    // R3 settles the note and escalates, holding its own line
        $line = vp_out($b, $n['conv'], $U0 + 95, 'line');                                // the AI's line, stored after that event
        $r = $on3($b);
        $ctx->t(vp_is($r, 'PASS') && ($r['counts']['ambiguous_line ai_reply_worker'] ?? 0) === 1, 'O02 VN-ON3 reads PASS and prints ambiguous_line 1', json_encode($r));
        $c = va_copy($ctx, $b);
        $c['pdo']->prepare('UPDATE events SET created_at = ? WHERE id = ?')->execute([UtcClock::stamp($U0 + 95 - 301), $ai]);
        $ctx->eq($on3($c)['status'], 'FAIL holding_line_after_ack', 'O02 control on a copy: the AI\'s escalation 301 s before its line reads FAIL');
    });

    $ctx->run('O03 a note not yet decided, and one skipped media_disabled, are counts, split by ai_enabled (VK14; T27)', function () use ($ctx, $world, $on3, $handed, $now, $day) {
        $b = $world();
        $handed($b, '256771493021', $now - 6 * $day);                                    // a control: one handed over
        $r3 = vp_note($b, '256771493022', $now - 5 * $day, ['rung' => 'handover', 'status' => 'failed', 'reason' => 'provider_error', 'fetched' => 4096]);
        $e3 = vp_media_event($b, $r3, ['status' => 'failed', 'attempts' => 1, 'max' => 3, 'error' => 'provider_error']);   // its retry not yet due
        $r4 = vp_note($b, '256771493023', $now - 5 * $day + 60, ['rung' => 'handover', 'status' => 'skipped', 'reason' => 'media_disabled']);
        vp_media_event($b, $r4);                                                         // the media switch off on the retry's fresh read
        $r = $on3($b, ['ai_enabled' => false]);
        $ctx->t(vp_is($r, 'PASS') && ($r['counts']['undecided ai_enabled_off'] ?? 0) === 1 && ($r['counts']['media_disabled'] ?? 0) === 1,
                'O03 seven days on, ai_enabled off: PASS, undecided 1 under ai_enabled off, media_disabled 1', json_encode($r));
        $r5 = vp_note($b, '256771493024', $now - 600, ['rung' => 'handover', 'status' => 'fetching', 'fetched' => null]);
        vp_media_event($b, $r5, ['status' => 'processing', 'locked_at' => UtcClock::stamp($now - 30)]);   // its fetch held by the server
        $r2 = $on3($b, ['ai_enabled' => true]);
        $ctx->t(vp_is($r2, 'PASS') && ($r2['counts']['undecided ai_enabled_on'] ?? 0) === 2, 'O03 ai_enabled on, a fetch held: PASS, both undecided under ai_enabled on', json_encode($r2));
        $c = va_copy($ctx, $b);
        $c['pdo']->prepare("UPDATE events SET status = 'done', processed_at = ? WHERE id = ?")->execute([UtcClock::stamp($now - 5 * $day + 30), $e3]);
        $ctx->eq($on3($c, ['ai_enabled' => false])['status'], 'FAIL not_handed_over', 'O03 control on a copy: its event set done before its retry, no escalation, reads FAIL');
    });

    $ctx->run('O04 a dead letter recorded switched_off is a count, never FAIL (VK8; T25)', function () use ($ctx, $world, $on3, $now, $day) {
        $b = $world();
        $n = vp_note($b, '256771493031', $now - 4 * $day, ['rung' => 'handover', 'status' => 'dead', 'reason' => 'switched_off', 'fetched' => null, 'attempts' => 3]);
        vp_media_event($b, $n, ['status' => 'dead', 'attempts' => 3]);
        $r = $on3($b);
        $ctx->t(vp_is($r, 'PASS') && ($r['counts']['dead_switched_off'] ?? 0) === 1, 'O04 VN-ON3 reads PASS and prints dead_switched_off 1', json_encode($r));
        $c = va_copy($ctx, $b);
        $c['pdo']->prepare("UPDATE wa_media SET failure_reason = 'fetch_failed' WHERE id = ?")->execute([$n['media']]);
        $ctx->eq($on3($c)['status'], 'FAIL not_handed_over', 'O04 control on a copy: the fetch\'s own code reads FAIL');
    });

    $ctx->run('O05 the event window: a hand-over older than 29 days whose events were pruned is a count (VK6; T17)', function () use ($ctx, $world, $on3, $handed, $now, $day) {
        $b = $world(40);
        $old = $handed($b, '256771493041', $now - 35 * $day);
        $new = $handed($b, '256771493042', $now - 2 * $day);
        $b['pdo']->exec("UPDATE events SET processed_at = created_at");
        $pruned = (new EventBus($b['pdo']))->prune(30);
        $ctx->eq([$pruned >= 2, (int)vm_q($b, "SELECT COUNT(*) n FROM events WHERE entity_id IN (?, ?) AND event_type = 'wa.escalation'", [$old['conv'], $new['conv']])[0]['n']],
                 [true, 1], 'O05 control: the live prune deleted the old row\'s events');
        $r = $on3($b);
        $ctx->t(vp_is($r, 'PASS') && ($r['counts']['outside_event_window'] ?? 0) === 1, 'O05 PASS, outside_event_window 1', json_encode($r));
        $c = va_copy($ctx, $b);
        $c['pdo']->prepare('DELETE FROM events WHERE id = ?')->execute([$new['esc']]);
        $ctx->eq($on3($c)['status'], 'FAIL not_handed_over', 'O05 control on a copy: the 2-day-old\'s escalation deleted reads FAIL');
    });

    $ctx->run('O06 R3\'s own rung: R4\'s changed bodies and turns are not R3\'s (VK4; T15)', function () use ($ctx, $world, $on3, $handed, $now, $day) {
        $b = $world();
        $h = $handed($b, '256771493051', $now - 3 * $day);
        $r4 = vp_note($b, '256771493052', $now - 2 * $day, ['rung' => 'reply', 'status' => 'understood', 'understanding' => 'VP transcript (test)', 'body' => '[voice message] VP (test)']);
        vp_media_event($b, $r4);
        vp_turn($b, $r4, $r4['upd'] + 1);
        $r = $on3($b);
        $ctx->t(vp_is($r, 'PASS') && ($r['counts']['own_rung_rows'] ?? 0) === 1, 'O06 VN-ON3 reads PASS with step 9 at work', json_encode($r));
        foreach (['an R3 row\'s body changed' => function ($c) use ($h) { $c['pdo']->prepare('UPDATE wa_messages SET body = ? WHERE wa_message_id = ?')->execute(['[voice message] x', $h['wa']]); },
                  'an R3 row given a turn' => function ($c) use ($h) { vp_turn($c, $h, $h['upd'] + 2); }] as $label => $plant) {
            $c = va_copy($ctx, $b);
            $plant($c);
            $ctx->eq($on3($c)['status'], 'FAIL r3_acted', "O06 control on a copy: {$label} reads FAIL");
        }
    });
}];
