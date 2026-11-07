<?php
declare(strict_types=1);
/**
 * Section P of tests/test_voice_ack.php — VN-ON4, step 9's check (5.18.104, root docs/73 §H.3.20; ruling R18; VK6's,
 * VK8's, VK12's and VK14's VN-ON4 halves; T17's VN-ON4 copy, T22, T23, T25's VN-ON4 copy, T27's VN-ON4 copy). TEST ONLY.
 * Each turn is planted as the reply worker leaves it (section F drives the real worker), with the cause it recorded;
 * then the world moves (a number dropped from the list, a prune) and the check reads PASS on a fresh $ro; each control,
 * planted on a copy, reads FAIL. total_changes() on $ro stays 0 throughout.
 */
$VA_SECTIONS['P'] = ['title' => 'VN-ON4: each understood note one turn, judged as the reply worker could know it; the switch margin; failures handed over', 'run' => function (QfCtx $ctx): void {
    va_load($ctx);
    $evo = qf_fake_evo();
    $day = 86400;
    $now = va_noon();
    $world = function (int $sinceDays = 4) use ($ctx, $evo, $now, $day): array {
        $b = vm_book($ctx, va_cfg($evo, vm_rung('reply', 'sales') + ['ai_media_voice_reply_since' => UtcClock::stamp($now - $sinceDays * $day)]));
        $b['S'] = $now - $sinceDays * $day;
        return $b;
    };
    $on4 = function (array $b, array $over = []) use ($now) { return va_check($b, 'VN-ON4', $now, array_merge($b['cfg'], $over)); };
    // An understood note at R4 with its one turn, emitted a second after the row settled.
    $understood = function (array $b, string $phone, int $at, array $o = []): array {
        $n = vp_note($b, $phone, $at, ['rung' => 'reply', 'status' => 'understood', 'understanding' => 'VP transcript (test)',
                                      'body' => '[voice message] VP (test)'] + $o);
        vp_media_event($b, $n);
        $n['turn_at'] = $n['upd'] + 1;
        $n['turn'] = vp_turn($b, $n, $n['turn_at'], $o['turn'] ?? []);
        return $n;
    };

    $ctx->run('P01 a done turn not answered is a count by its recorded cause, never FAIL (VK12; T23)', function () use ($ctx, $world, $on4, $understood, $day) {
        $b = $world();
        $T = $b['S'] + $day;
        $n1 = $understood($b, '256771494001', $T);            vp_out($b, $n1['conv'], $T + 5, 'inbox');      // a colleague replied meanwhile
        $n2 = $understood($b, '256771494002', $T + 100);      vp_out($b, $n2['conv'], $T + 105, 'followup'); // an enquiry follow-up meanwhile
        $n3 = $understood($b, '256771494003', $T + 200);      vp_escalation($b, $n3['conv'], $n3['turn_at'] + 1, 'ai_reply_worker', 'the AI had no answer (test)');
        $n4 = $understood($b, '256771494004', $T + 300);      vp_out($b, $n4['conv'], $n4['turn_at'] + 1, 'answer');
        $c5 = vp_conv($b, '256771494005');
        vp_out($b, $c5, $T + 350, 'inbox');                                                                  // before the note: the pause
        $n5 = $understood($b, '256771494005', $T + 400, ['turn' => ['error' => 'parked: a colleague is active on this conversation']]);
        $r = $on4($b);
        $want = ['answered' => 1, 'meanwhile_row inbox_reply' => 1, 'meanwhile_row other' => 1, 'handed_over' => 1, 'parked_dropped' => 1];
        $ctx->t(vp_is($r, 'PASS') && array_intersect_key($r['counts'], $want) == $want, 'P01 VN-ON4 reads PASS and prints each cause', json_encode($r));
        $c = va_copy($ctx, $b);
        $c['pdo']->prepare("UPDATE events SET status = 'dead' WHERE id = ?")->execute([$n4['turn']]);
        $ctx->eq($on4($c)['status'], 'FAIL turn_dead', 'P01 control on a copy: the answered turn\'s event set dead reads FAIL');
        vp_escalation($c, $n4['conv'], $n4['turn_at'] + 5, 'ai_reply_worker', 'the AI could not answer (test)');
        $rc = $on4($c);
        $ctx->t(vp_is($rc, 'PASS') && ($rc['counts']['dead_handed_over'] ?? 0) === 1, 'P01 with an ai_reply_worker escalation after its processed_at: PASS, dead_handed_over 1', json_encode($rc));
    });

    $ctx->run('P02 the switch margin: a note stored inside it is not judged, nor one stored before since (VK8; T22)', function () use ($ctx, $world, $on4, $understood) {
        $b = $world();
        $S = $b['S'];
        $a = vp_note($b, '256771494011', $S + 30, ['rung' => 'handover', 'status' => 'understood', 'understanding' => 'VP transcript (test)']);
        vp_ack($b, $a, $S + 32);                                                          // a step-13 read before the save
        $old = $understood($b, '256771494012', $S - 3600, ['channel' => 'support']);       // answered on a number later dropped from the list
        vp_out($b, $old['conv'], $old['turn_at'] + 1, 'answer');
        $r = $on4($b);
        $ctx->t(vp_is($r, 'PASS') && ($r['counts']['inside_switch_margin'] ?? 0) === 1 && !isset($r['counts']['scope_answered_unlisted']),
                'P02 three days on, VN-ON4 reads PASS: the acknowledgement inside the margin counted, the older turn not judged', json_encode($r));
        $c = va_copy($ctx, $b);
        $c['pdo']->prepare('UPDATE wa_messages SET created_at = ? WHERE wa_message_id = ?')->execute([UtcClock::stamp($S + 90), $a['wa']]);
        $ctx->eq($on4($c)['status'], 'FAIL ack_on_reply_number', 'P02 control on a copy: the same acknowledgement for a note stored 90 s after since reads FAIL');
        $c = va_copy($ctx, $b);
        $late = $understood($c, '256771494013', $S + 7200, ['channel' => 'support']);
        vp_out($c, $late['conv'], $late['turn_at'] + 1, 'answer');
        $ctx->eq($on4($c)['status'], 'FAIL scope', 'P02 control on a copy: a turn answered on an unlisted number after the margin reads FAIL');
    });

    $ctx->run('P03 a failure handed over once decided; a dead letter switched_off and a retry still to come are counts (VK8, VK14; T25, T27)', function () use ($ctx, $world, $on4, $day) {
        $b = $world();
        $T = $b['S'] + $day;
        $h = vp_note($b, '256771494021', $T, ['rung' => 'reply', 'status' => 'failed', 'reason' => 'provider_error', 'attempts' => 3]);
        vp_media_event($b, $h, ['status' => 'dead', 'attempts' => 3]);
        vp_escalation($b, $h['conv'], $h['upd'] + 1);                                     // a control: handed over
        $f = vp_note($b, '256771494022', $T + 100, ['rung' => 'reply', 'status' => 'failed', 'reason' => 'provider_error']);
        $fe = vp_media_event($b, $f, ['status' => 'failed', 'attempts' => 1, 'max' => 3]);
        $d = vp_note($b, '256771494023', $T + 200, ['rung' => 'reply', 'status' => 'dead', 'reason' => 'switched_off', 'fetched' => null, 'attempts' => 3]);
        vp_media_event($b, $d, ['status' => 'dead', 'attempts' => 3]);
        $r = $on4($b, ['ai_enabled' => false]);
        $ctx->t(vp_is($r, 'PASS') && ($r['counts']['scope undecided ai_enabled_off'] ?? 0) === 1 && ($r['counts']['dead_switched_off'] ?? 0) === 1,
                'P03 VN-ON4 reads PASS: the Scope\'s undecided 1, dead_switched_off 1', json_encode($r));
        $c = va_copy($ctx, $b);
        $c['pdo']->prepare("UPDATE events SET status = 'done', processed_at = ? WHERE id = ?")->execute([UtcClock::stamp($T + 150), $fe]);
        $ctx->eq($on4($c)['status'], 'FAIL failure_not_handed_over', 'P03 control on a copy: the retrying note\'s event set done, no escalation, reads FAIL');
        $c = va_copy($ctx, $b);
        $c['pdo']->prepare("UPDATE wa_media SET failure_reason = 'fetch_failed' WHERE id = ?")->execute([$d['media']]);
        $ctx->eq($on4($c)['status'], 'FAIL failure_not_handed_over', 'P03 control on a copy: the dead letter\'s reason set to the fetch\'s own code reads FAIL');
    });

    $ctx->run('P04 the event window: an answered turn older than 29 days whose events were pruned is a count (VK6; T17)', function () use ($ctx, $world, $on4, $understood, $now, $day) {
        $b = $world(40);
        $old = $understood($b, '256771494031', $now - 35 * $day);   vp_out($b, $old['conv'], $old['turn_at'] + 1, 'answer');
        $new = $understood($b, '256771494032', $now - 2 * $day);    vp_out($b, $new['conv'], $new['turn_at'] + 1, 'answer');
        $b['pdo']->prepare('UPDATE events SET processed_at = created_at WHERE created_at < ?')->execute([UtcClock::stamp($now - 30 * $day)]);
        $pruned = (new EventBus($b['pdo']))->prune(30);
        $ctx->eq([$pruned >= 2, (int)vm_q($b, "SELECT COUNT(*) n FROM events WHERE event_type = 'ai.reply'")[0]['n']], [true, 1], 'P04 control: the live prune deleted the old turn\'s events');
        $r = $on4($b);
        $ctx->t(vp_is($r, 'PASS') && ($r['counts']['outside_event_window'] ?? 0) === 1 && ($r['counts']['answered'] ?? 0) === 1, 'P04 PASS, outside_event_window 1, answered 1', json_encode($r));
        $c = va_copy($ctx, $b);
        $c['pdo']->prepare('DELETE FROM events WHERE id = ?')->execute([$new['turn']]);
        $ctx->eq($on4($c)['status'], 'FAIL turns_mismatch', 'P04 control on a copy: the 2-day-old\'s turn deleted reads FAIL');
    });
}];
