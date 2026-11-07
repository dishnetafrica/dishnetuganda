<?php
declare(strict_types=1);
/**
 * Section A of tests/test_voice_media.php — the rungs' runtime predicates and the limits (5.18.104, root docs/73 §H.3.5
 * "Runtime predicates", R-14, S2-11). TEST ONLY. Each rung requires every rung below it, whoever set the keys; every
 * predicate is false off Uganda; a limit planted out of range is clamped into `set`'s range.
 */
$VM_SECTIONS['A'] = ['title' => 'The rungs: each requires every rung below it, and nothing is on off Uganda', 'run' => function (QfCtx $ctx): void {
    vm_load($ctx);
    $dir = $ctx->dir('policy');
    $ug  = ['tenant_profile' => 'uganda', 'ai_enabled' => true];
    $ss  = ['tenant_profile' => 'south-sudan', 'ai_enabled' => true];
    $mode = function (array $c, string $ch = 'sales') use ($dir): string { return MediaPolicy::voiceMode($c, $dir, $ch); };
    $all = function (array $c, string $ch = 'sales') use ($dir): array {
        return [MediaPolicy::enabled($c, $dir), MediaPolicy::voiceEnabled($c, $dir), MediaPolicy::voiceHandoverEnabled($c, $dir),
                MediaPolicy::voiceReplyEnabled($c, $dir, $ch)];
    };

    $ctx->run('A01 control: every rung on, on Uganda, reads reply on a listed number', function () use ($ctx, $ug, $all, $mode) {
        $c = $ug + vm_rung('reply', 'sales,support');
        $ctx->eq($all($c), [true, true, true, true], 'A01 enabled, voiceEnabled, voiceHandoverEnabled, voiceReplyEnabled');
        $ctx->eq([$mode($c), $mode($c, 'support'), $mode($c, 'account')], ['reply', 'reply', 'handover'], 'A01 the word per number: a number not listed is at R3');
        $ctx->eq($mode($ug + vm_rung('fetch_only')), 'fetch_only', 'A01 R1 alone reads fetch_only');
        $ctx->eq($mode($ug + vm_rung('dry_run')), 'dry_run', 'A01 R2 reads dry_run');
    });

    $ctx->run('A02 off Uganda nothing is on, whatever the keys say (V1)', function () use ($ctx, $ss, $all, $mode) {
        $c = $ss + vm_rung('reply');
        $ctx->eq($all($c), [false, false, false, false], 'A02 South Sudan, every key on: every predicate false');
        $ctx->eq($mode($c), 'off', 'A02 and the word is off');
        $ctx->eq(MediaPolicy::enabled(['ai_enabled' => true, 'ai_media_enabled' => '1'], null), false, 'A02 no profile at all: false (StaffJobsGate)');
    });

    $ctx->run('A03 the assistant and the media switch come first', function () use ($ctx, $ug, $all) {
        $c = $ug + vm_rung('reply');
        $ctx->eq($all(['ai_enabled' => false] + $c), [false, false, false, false], 'A03 ai_enabled off: every rung false');
        $ctx->eq($all(['ai_media_enabled' => '0'] + $c), [false, false, false, false], 'A03 ai_media_enabled off: every rung false (V2)');
        foreach (['1', 'true', 'on', 'yes', true] as $v) {
            $ctx->eq(MediaPolicy::enabled(['ai_media_enabled' => $v] + $ug, null), true, 'A03 ai_media_enabled ' . json_encode($v) . ' reads on');
        }
        foreach (['0', 'off', 'no', '', 'maybe', false] as $v) {
            $ctx->eq(MediaPolicy::enabled(['ai_media_enabled' => $v] + $ug, null), false, 'A03 ai_media_enabled ' . json_encode($v) . ' reads off');
        }
    });

    $ctx->run('A04 R2 needs the provider, the published privacy line and a stored key (R-14; V3, V4)', function () use ($ctx, $ug, $dir) {
        $c = $ug + vm_rung('dry_run');
        $ctx->eq(MediaPolicy::voiceEnabled($c, $dir), true, 'A04 control: R2 on');
        $ctx->eq(MediaPolicy::voiceEnabled(['ai_transcription_provider' => null] + $c, $dir), false, 'A04 no provider: off');
        $ctx->eq(MediaPolicy::voiceEnabled(['ai_transcription_provider' => 'fake'] + $c, $dir), false, 'A04 provider fake: off');
        $ctx->eq(MediaPolicy::voiceEnabled(['ai_media_voice_privacy_line' => ''] + $c, $dir), false, 'A04 no privacy line: off (V3)');
        $ctx->eq(MediaPolicy::voiceEnabled(['ai_media_voice_privacy_line' => '2099-01-01'] + $c, $dir), false, 'A04 a future privacy line: off');
        $ctx->eq(MediaPolicy::voiceEnabled(['ai_media_voice_privacy_line' => '2026-02-30'] + $c, $dir), false, 'A04 an impossible date: off');
        $ctx->eq(MediaPolicy::voiceEnabled(['ai_media_voice_privacy_line' => '20/10/2026'] + $c, $dir), false, 'A04 a date in another form: off');
        $ctx->eq(MediaPolicy::voiceEnabled(['openai_api_key' => '  '] + $c, $dir), false, 'A04 a blank key: off (V4)');
        $ctx->eq(MediaPolicy::keySet(['openai_api_key' => VM_KEY]), true, 'A04 keySet is a boolean');
        $ctx->eq(MediaPolicy::voiceEnabled(['ai_media_voice' => '0'] + $c, $dir), false, 'A04 ai_media_voice off: off');
    });

    $ctx->run('A05 R3 needs R2; R4 needs R3, its own switch, the approval and the number (S2-11; V5, V6)', function () use ($ctx, $ug, $dir) {
        $c = $ug + vm_rung('reply', 'sales');
        $ctx->eq(MediaPolicy::voiceHandoverEnabled(['ai_media_voice' => '0'] + $c, $dir), false, 'A05 R3 without R2: off');
        $ctx->eq(MediaPolicy::voiceReplyEnabled(['ai_media_voice_handover' => '0'] + $c, $dir, 'sales'), false, 'A05 R4 without R3: off (V5)');
        $ctx->eq(MediaPolicy::voiceReplyEnabled(['ai_media_voice_reply' => null] + $c, $dir, 'sales'), false,
                 'A05 R4 without its own switch, the list and the approval still set: off (S2-11, T8)');
        $ctx->eq(MediaPolicy::voiceReplyEnabled(['ai_media_voice_reply_approved' => ''] + $c, $dir, 'sales'), false, 'A05 no approval date: off');
        $ctx->eq(MediaPolicy::voiceReplyEnabled(['ai_media_voice_reply_approved' => '2099-01-01'] + $c, $dir, 'sales'), false, 'A05 a future approval: off');
        $ctx->eq(MediaPolicy::voiceReplyEnabled($c, $dir, 'support'), false, 'A05 an unlisted number: off (V6)');
        $ctx->eq(MediaPolicy::voiceReplyEnabled(['ai_media_voice_reply_channels' => ''] + $c, $dir, 'sales'), false, 'A05 an empty list: off');
        $ctx->eq(MediaPolicy::voiceReplyEnabled(['ai_media_voice_reply_channels' => 'all'] + $c, $dir, 'support'), true, 'A05 the list `all`: on for any number');
        $ctx->eq(MediaPolicy::voiceReplyEnabled(['ai_media_voice_reply_channels' => ' Sales , sales-003 '] + $c, $dir, 'sales-003'), true, 'A05 a list, spaced and cased: on for a listed id');
    });

    $ctx->run('A06 every limit is clamped into set\'s range; an unreadable value is the default (§H.3.5)', function () use ($ctx) {
        $ctx->eq([MediaPolicy::maxBytes([]), MediaPolicy::timeoutSeconds([]), MediaPolicy::voiceTimeoutSeconds([]), MediaPolicy::voiceMaxSeconds([]),
                  MediaPolicy::voiceDailyMinutes([]), MediaPolicy::voiceConversationDailyMax([]), MediaPolicy::sttModel([])],
                 [2097152, 20, 30, 120, 120, 10, 'whisper-1'], 'A06 the defaults');
        $ctx->eq([MediaPolicy::maxBytes(['ai_media_max_bytes' => 99999999]), MediaPolicy::maxBytes(['ai_media_max_bytes' => 1]),
                  MediaPolicy::timeoutSeconds(['ai_media_timeout_s' => 60]), MediaPolicy::voiceTimeoutSeconds(['ai_media_voice_timeout_s' => 120]),
                  MediaPolicy::voiceMaxSeconds(['ai_media_voice_max_seconds' => 900]), MediaPolicy::voiceDailyMinutes(['ai_media_voice_daily_minutes' => 9000]),
                  MediaPolicy::voiceConversationDailyMax(['ai_media_voice_conversation_daily_max' => 0])],
                 [8388608, 65536, 25, 30, 300, 600, 1], 'A06 planted out of range: clamped (fetch + provider stay within 55 s)');
        $ctx->eq([MediaPolicy::voiceDailyMinutes(['ai_media_voice_daily_minutes' => '0']), MediaPolicy::voiceMaxSeconds(['ai_media_voice_max_seconds' => '-5'])],
                 [0, 120], 'A06 0 minutes is a real value (no call at all); a signed value is unreadable');
        $ctx->eq([MediaPolicy::sttModel(['ai_media_openai_stt_model' => 'gpt-4o-transcribe']), MediaPolicy::sttModel(['ai_media_openai_stt_model' => 'whisper-2'])],
                 ['gpt-4o-transcribe', ''], 'A06 the model: on the allow-list, or nothing');
        $ctx->eq([MediaPolicy::allowed('audio', 'audio/ogg; codecs=opus'), MediaPolicy::allowed('audio', 'audio/mpeg'),
                  MediaPolicy::allowed('image', 'image/jpeg'), MediaPolicy::kindSupported('document')],
                 [true, true, false, false], 'A06 audio only: photos and documents are not handled in this release');
    });
}];
