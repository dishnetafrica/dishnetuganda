<?php
declare(strict_types=1);
/**
 * Section K of tests/test_voice_ack.php — VoiceSteps' call surface (5.18.104, root docs/73 §H.3.5, §H.3.20; rulings R6,
 * R13): the preconditions of rows 2 (Part V's half), 5 and 7, none for rows 8 and 9; every check's vocabulary and its
 * NOT_YET rules; a check that reads only through $ro and changes nothing there. TEST ONLY.
 */
$VA_SECTIONS['K'] = ['title' => 'VoiceSteps: the preconditions, the one vocabulary, too_soon and no_traffic, nothing written through $ro', 'run' => function (QfCtx $ctx): void {
    va_load($ctx);
    $evo = qf_fake_evo();
    $b = vm_book($ctx, va_cfg($evo));
    $pre = function (array $cfg, string $step) use ($b): array {
        $ro = va_ro($b);
        $r = VoiceSteps::preconditions($ro, $cfg, $b['data'], $step);
        $r['_changes'] = (int)$ro->query('SELECT total_changes()')->fetchColumn();
        return $r;
    };

    $ctx->run('K01 row 2, Part V\'s half: ai_enabled on and the limits readable', function () use ($ctx, $b, $pre) {
        $ctx->eq([$pre($b['cfg'], 'ack')['ok'], $pre(['ai_enabled' => false] + $b['cfg'], 'ack')['code'],
                  $pre(['wa_voice_ack_hour_from' => '22'] + $b['cfg'], 'ack')['code'], $pre($b['cfg'], 'ack')['_changes']],
                 [true, 'ai_disabled', 'limits_invalid', 0], 'K01 ok; ai_disabled; limits_invalid (VoiceAckLimits::of() null); nothing written');
    });

    $ctx->run('K02 row 5: the 090 row; row 7: the privacy date, the key, the model; rows 8 and 9: none', function () use ($ctx, $b, $pre, $evo) {
        $c = $b['cfg'] + vm_rung('dry_run');
        $ctx->eq([$pre($c, 'voice-measure')['ok'], $pre($c, 'voice-dry-run')['ok'], $pre($c, 'voice-staff')['ok'], $pre($c, 'voice-reply')['ok']],
                 [true, true, true, true], 'K02 control: each admits on a book with 090 and a configuration that has what it needs');
        $ctx->eq([$pre(['ai_media_voice_privacy_line' => '2026-12-31'] + $c, 'voice-dry-run')['code'], $pre(['ai_media_voice_privacy_line' => ''] + $c, 'voice-dry-run')['code'],
                  $pre(['openai_api_key' => ''] + $c, 'voice-dry-run')['code'], $pre(['ai_media_openai_stt_model' => 'whisper-9'] + $c, 'voice-dry-run')['code']],
                 ['privacy_line_missing', 'privacy_line_missing', 'key_not_set', 'model_not_allowed'], 'K02 a future or empty date, no key, an off-list model: each refused');
        $lines = implode(' ', $pre($c, 'voice-dry-run')['lines']);
        $ctx->t(strpos($lines, VM_KEY) === false && strpos($lines, 'set') !== false, 'K02 the key is "set", never printed', $lines);
        $b2 = vm_book($ctx, va_cfg($evo));
        $b2['pdo']->exec("DELETE FROM _schema_checks WHERE migration = '090_wa_voice.sql'");
        $ro = va_ro($b2);
        $ctx->eq(VoiceSteps::preconditions($ro, $c, $b2['data'], 'voice-measure')['code'], 'schema_check_missing:090_wa_voice.sql', 'K02 no 090 row: refused');
        $ctx->eq($pre($c, 'agent-cc')['code'], 'unknown_step', 'K02 a step that is not Part V\'s: refused');
    });

    $ctx->run('K03 the vocabulary: since unreadable, too_soon, no_traffic — and nothing written through $ro (ruling R6, R13)', function () use ($ctx, $b) {
        $now = va_noon();
        $got = [];
        foreach (['VA-ON1' => ['wa_voice_ack_since', 86400], 'VN-ON1' => ['ai_media_since', 172800], 'VN-ON2' => ['ai_media_voice_since', 172800],
                  'VN-ON3' => ['ai_media_voice_handover_since', 604800], 'VN-ON4' => ['ai_media_voice_reply_since', 259200]] as $id => [$k, $min]) {
            $unset = va_check($b, $id, $now, [$k => null] + $b['cfg']);
            $soon = va_check($b, $id, $now, [$k => UtcClock::stamp($now - $min + 60)] + $b['cfg']);
            $quiet = va_check($b, $id, $now, [$k => UtcClock::stamp($now - $min - 60)] + $b['cfg']);
            $got[$id] = [$unset['status'], $soon['status'], $quiet['status'], $unset['_changes'] + $soon['_changes'] + $quiet['_changes']];
        }
        $ctx->eq($got, ['VA-ON1' => ['FAIL since_unreadable', 'NOT_YET too_soon', 'NOT_YET no_traffic', 0],
                        'VN-ON1' => ['FAIL since_unreadable', 'NOT_YET too_soon', 'NOT_YET no_traffic', 0],
                        'VN-ON2' => ['FAIL since_unreadable', 'NOT_YET too_soon', 'NOT_YET no_traffic', 0],
                        'VN-ON3' => ['FAIL since_unreadable', 'NOT_YET too_soon', 'PASS', 0],
                        'VN-ON4' => ['FAIL since_unreadable', 'NOT_YET too_soon', 'PASS', 0]],
                 'K03 each check: unset FAIL, before its minimum NOT_YET too_soon, after it on an empty book no_traffic (VN-ON3/4 have none: PASS)');
        $ctx->eq(va_check($b, 'VN-ON9', $now)['status'], 'FAIL unknown_check', 'K03 an unknown id: FAIL unknown_check');
    });
}];
