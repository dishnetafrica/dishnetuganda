<?php
declare(strict_types=1);
/**
 * Section VK of tests/test_voice_switches.php — Part V's rows as the switch tool runs them (5.18.104, root docs/73 §H.3.5,
 * §H.3.24 VK1–VK4, T2, T4–T7, T9, T14; rulings R5, R6, R7, R10, R13, R15), B9-fix1. TEST ONLY.
 */

/** A Uganda book for Part V's rows: step 1 run, the assistant on, row 7's needs (the date, a fictitious key), an admin with a phone. */
function vs_book(QfCtx $ctx, array $extra = []): array
{
    $b = sft_book($ctx, $extra + ['sf_report_ran_at' => '2026-11-01', 'ai_enabled' => true, 'openai_api_key' => VM_KEY,
                                  'ai_media_voice_privacy_line' => VM_PRIVACY, 'evo_api_url' => 'http://127.0.0.1:9', 'evo_api_key' => 'vs-evo-key',
                                  'ai_media_voice_daily_minutes' => '120', 'claude_api_key' => 'vs-test-key-never-called'], 'vs');
    sft_admins($b);
    return $b;
}

/** Time passing between rungs: the stamps the tool wrote moved $s seconds back in the data folder's kyc_config.json; the new stamps. */
function vs_back(array $b, array $keys, int $s): array
{
    $k = sft_kyc($b);
    $out = [];
    foreach ($keys as $key) {
        $t = UtcClock::parse((string)($k[$key] ?? ''));
        if ($t > 0) { $k[$key] = UtcClock::stamp($t - $s); $out[$key] = $t - $s; }
    }
    sft_files($b, $k);
    return $out;
}

/** The configuration the workers read now (the files), with the data folder. */
function vs_cfg(QfCtx $ctx, array $b): array
{
    return PluginConfig::read($ctx->tree(), $b['data']) + ['_data_dir' => $b['data']];
}

/** Part V's rung predicates on a configuration, in one row. */
function vs_rungs(array $c, string $data): array
{
    return [MediaPolicy::enabled($c, $data), MediaPolicy::voiceEnabled($c, $data), MediaPolicy::voiceHandoverEnabled($c, $data),
            MediaPolicy::voiceReplyEnabled($c, $data, 'sales'), MediaPolicy::voiceReplyEnabled($c, $data, 'support'),
            MediaPolicy::voiceReplyEnabled($c, $data, 'account'), VoiceAck::enabled($c, $data)];
}

$SECTIONS['VK'] = ['title' => 'Part V\'s rows through the switch tool (§H.3.5, §H.3.24 VK1–VK4; rulings R5, R6, R7, R10, R13)', 'run' => function (QfCtx $ctx) {
    $day = 86400;
    $today = SalesFollowupLadder::today(time());

    $ctx->run('VK00 positive control: the tool runs on a Part V book, show prints Part V\'s rows and the key as set, never its value (T4)', function () use ($ctx) {
        $b = vs_book($ctx);
        [$rc, $out] = sft_run($ctx, $b, ['show']);
        $ctx->eq([$rc, strpos($out, '5 voice-measure: off') !== false, strpos($out, 'openai key: set') !== false], [0, true, true], 'VK00 show: row 5 off, the key "set"', $out);
        $ctx->t(strpos($out, VM_KEY) === false && strpos($out, substr(VM_KEY, 3, 12)) === false, 'VK00 show prints no part of the OpenAI key (T4)', $out);
    });

    $ctx->run('VK1 Part V\'s slice of the one key scan (TL14): every key its files read is declared; its share of the pre-existing keys carries no 5.18.104 prefix', function () use ($ctx) {
        $base = sft_base($ctx);
        $ctx->t($base !== '' && !is_file($base . '/lib/VoiceAck.php'), 'VK1 control: Git supplies 221c9dc');
        if ($base === '') return;
        $vfiles = [];
        foreach (qf_declared('V') as $p => $row) if (substr($p, -4) === '.php' && strpos($p, 'tests/') !== 0 && is_file($ctx->tree() . '/' . $p)) $vfiles[] = $p;
        $ctx->t(in_array('lib/VoiceAck.php', $vfiles, true) && in_array('evo_webhook.php', $vfiles, true) && in_array('workers/MediaWorker.php', $vfiles, true),
                'VK1 control: the slice holds Part V\'s new files and its changed ones (' . count($vfiles) . ' PHP files)');
        $reads = array_values(array_filter(sft_key_reads($ctx->tree(), $base), function ($r) use ($vfiles) { return in_array($r[0], $vfiles, true); }));
        $ctx->t(count($reads) > 10, 'VK1 control: the scan reads Part V\'s files (' . count($reads) . ' reads)');
        $declared = array_merge(SalesFollowupLadder::allKeys(), SalesFollowupLadder::settingKeys(), SalesFollowupLadder::preexistingKeys());
        $bad = [];
        foreach ($reads as [$f, $k]) if (!in_array($k, $declared, true)) $bad[] = $f . ': ' . $k;
        $ctx->eq(array_values(array_unique($bad)), [], 'VK1 every key Part V\'s files read is in allKeys(), settingKeys() or preexistingKeys()');
        $share = ['ai_enabled', 'openai_api_key', 'ai_handover_message', 'wa_human_cooldown_minutes', 'wa_handover_copy_central'];
        $ctx->eq([array_values(array_diff($share, SalesFollowupLadder::preexistingKeys())),
                  array_values(array_filter($share, function ($k) { return preg_match('/^(ai_media_|ai_transcription_|wa_voice_ack_|sf_)/', $k) === 1; }))],
                 [[], []], 'VK1 Part V\'s share of preexistingKeys() is there, and none of it starts with a 5.18.104 prefix');
        foreach (['lib/VoiceAck.php' => "\n// planted (test)\nfunction vk1_planted(array \$config) { return \$config['vk1_planted_key']; }\n", 'evo_webhook.php' => "\n// planted (test)\n\$vk1Planted = \$config['vk1_planted_key'] ?? null;\n"] as $plant => $text) {
            $t = $ctx->tree() . '/' . $plant;
            $src = (string)file_get_contents($t);
            file_put_contents($t, $src . $text);
            $found = array_filter(sft_key_reads($ctx->tree(), $base), function ($r) use ($plant) { return $r[0] === $plant && $r[1] === 'vk1_planted_key'; });
            file_put_contents($t, $src);
            $ctx->eq(count($found), 1, 'VK1 control: a key read planted in ' . $plant . ' is found (T9)');
        }
    });

    $ctx->run('VK2 off all through the tool on every Part V switch on and every setting set: each rung predicate and VoiceAck::enabled read false; the settings kept', function () use ($ctx, $day, $today) {
        $on = vm_rung('reply') + ['ai_media_voice_reply_approved' => $today, 'wa_voice_ack_enabled' => '1', 'wa_voice_ack_since' => UtcClock::stamp(time() - $day),
               'wa_send_ledger' => 'on', 'wa_send_ledger_since' => UtcClock::stamp(time() - $day), 'ai_media_since' => UtcClock::stamp(time() - $day),
               'ai_media_voice_since' => UtcClock::stamp(time() - $day), 'ai_media_voice_handover_since' => UtcClock::stamp(time() - $day),
               'ai_media_voice_reply_since' => UtcClock::stamp(time() - $day)];
        $sets = ['ai_media_max_bytes' => '2097152', 'ai_media_timeout_s' => '10', 'ai_media_voice_timeout_s' => '20', 'ai_media_voice_max_seconds' => '120',
                 'ai_media_voice_conversation_daily_max' => '5', 'wa_voice_ack_gap_s' => '30', 'wa_voice_ack_daily_max' => '40'];
        $b = vs_book($ctx, $on + $sets);
        $ctx->eq(vs_rungs(vs_cfg($ctx, $b), $b['data']), [true, true, true, true, true, true, true], 'VK2 control: before, every rung and P5 read on');
        [$rc, $out] = sft_run($ctx, $b, ['off', 'all']);
        $c = vs_cfg($ctx, $b);
        $ctx->eq([$rc, vs_rungs($c, $b['data'])], [0, [false, false, false, false, false, false, false]], 'VK2 after off all: MediaPolicy::enabled, voiceEnabled, voiceHandoverEnabled, voiceReplyEnabled (every channel) and VoiceAck::enabled all false', $out);
        $ctx->eq(array_intersect_key(sft_kyc($b), $sets), $sets, 'VK2 Part V\'s settings kept as set (ruling R5)');
    });

    $ctx->run('VK2b off voice-measure through the tool clears 5, 7, 8 and 9; step 5\'s hook after its keys, settling a pending audio row (T5, T14)', function () use ($ctx, $day, $today) {
        $on = vm_rung('reply') + ['ai_media_voice_reply_approved' => $today, 'wa_send_ledger' => 'on', 'wa_send_ledger_since' => UtcClock::stamp(time() - $day),
               'ai_media_since' => UtcClock::stamp(time() - $day), 'ai_media_voice_since' => UtcClock::stamp(time() - $day),
               'ai_media_voice_handover_since' => UtcClock::stamp(time() - $day), 'ai_media_voice_reply_since' => UtcClock::stamp(time() - $day)];
        $b = vs_book($ctx, $on);
        vp_note($b, '256771498801', time() - 600, ['status' => 'pending', 'fetched' => null]);
        [$rc, $out] = sft_run($ctx, $b, ['off', 'voice-measure']);
        $left = array_values(array_intersect(array_merge(SalesFollowupLadder::rowKeys('voice-measure'), SalesFollowupLadder::rowKeys('voice-dry-run'),
                                                         SalesFollowupLadder::rowKeys('voice-staff'), SalesFollowupLadder::rowKeys('voice-reply')), array_keys(sft_kyc($b))));
        $ctx->eq([$rc, $left, vs_rungs(vs_cfg($ctx, $b), $b['data'])[0]], [0, [], false], 'VK2b off voice-measure: every key of rows 5, 7, 8 and 9 unset (T5)', $out);
        $lines = array_values(array_filter(array_map('trim', explode("\n", $out)), function ($l) { return preg_match('/^(OFF |hook )/', $l); }));
        $tags = array_map(function ($l) { return preg_replace('/^(OFF|hook) ([a-z\-]+).*$/', '$1 $2', $l); }, $lines);
        $ctx->eq($tags, ['OFF voice-reply', 'OFF voice-staff', 'OFF voice-dry-run', 'OFF voice-measure', 'hook voice-measure'],
                 'VK2b the one sequence, 9, 8, 7, 5, and step 5\'s hook AFTER its keys clear (T14\'s step-5 form)', $out);
        $ctx->eq((string)$b['pdo']->query("SELECT status FROM wa_media WHERE remote_jid = '256771498801@s.whatsapp.net'")->fetchColumn(), 'skipped',
                 'VK2b the pending audio row settled by the hook');
    });

    $ctx->run('VK3 VK4 the ladder climbed through the tool: stale reply keys never arm R4 (S2-11); working rungs admit voice-staff, voice-reply, cold and offers-test; T2 T6 T7', function () use ($ctx, $day, $today) {
        $b = vs_book($ctx);
        $on = function (string $step, array $more = [], string $stdin = '') use ($ctx, $b): array {
            [$rc, $out] = sft_run($ctx, $b, array_merge(['on', $step], $more), $stdin);
            $ctx->eq($rc, 0, 'VK climb: on ' . $step . ' admitted', $out);
            return [$rc, $out];
        };
        $acc = ['--accept-not-yet', 'a quiet first week (test)'];
        [$rc, $out] = sft_run($ctx, $b, ['off', 'all']);
        $k = sft_kyc($b); $k['ai_media_voice_reply_channels'] = 'all'; $k['ai_media_voice_reply_approved'] = $today; sft_files($b, $k);   // stale, as S2-11 has them
        $on('report', ['--ran', '2026-11-01']);                                           // off all cleared the record keys too
        $on('ack');           vs_back($b, ['wa_send_ledger_since', 'wa_voice_ack_since'], 4 * $day);
        $on('tracking');      vs_back($b, ['quote_tracking_since'], 4 * $day);
        $on('list', $acc);    vs_back($b, ['quote_daily_list_since'], 4 * $day);
        $on('voice-measure');
        $s5 = vs_back($b, ['ai_media_since'], 2 * $day + 7200)['ai_media_since'];
        vp_notes($b, 20, $s5 + 3600, 'fetch_only');                                       // R1's rows, as the media worker leaves them
        [$rc, $out] = sft_run($ctx, $b, ['--check', 'voice-measure']);
        $ctx->eq([$rc, strpos($out, 'voice-measure: PASS') === 0, strpos($out, 'ro_changed') === false], [0, true, true], 'VK4 step 5 working: --check reads VN-ON1 PASS, $ro unchanged (T7)', $out);
        $on('chase', [], "yes\n"); vs_back($b, ['quote_chase_not_before'], 4 * $day);
        // T2: on voice-dry-run without the date, with a future date, without the key: refused, nothing saved
        $k0 = sft_kyc($b);
        foreach ([['privacy_line_missing', ['ai_media_voice_privacy_line' => null]], ['privacy_line_missing', ['ai_media_voice_privacy_line' => '2099-01-01']],
                  ['key_not_set', ['openai_api_key' => null]]] as [$code, $over]) {
            $k = $k0; foreach ($over as $kk => $vv) { if ($vv === null) unset($k[$kk]); else $k[$kk] = $vv; }
            sft_files($b, $k);
            [$rc, $out] = sft_run($ctx, $b, ['on', 'voice-dry-run']);
            $ctx->eq([$rc, strpos($out, 'REFUSED ' . $code) !== false, isset(sft_kyc($b)['ai_media_voice'])], [2, true, false], 'VK4 on voice-dry-run ' . json_encode($over) . ': REFUSED ' . $code . ', nothing saved (T2)', $out);
        }
        sft_files($b, $k0);
        $on('voice-dry-run');
        $s7 = vs_back($b, ['ai_media_voice_since'], 2 * $day + 7200)['ai_media_voice_since'];
        foreach (vp_notes($b, 10, $s7 + $day - 3600, 'dry_run', 600, ['phone_base' => 100]) as $n) vp_call($b, $n, $n['at'] + 2, ['reserved' => 600, 'billed' => 600, 'cap' => 7200]);
        [$rc, $out] = sft_run($ctx, $b, ['--check', 'voice-dry-run']);
        $ctx->eq(strpos($out, 'voice-dry-run: PASS'), 0, 'VK4 step 7 working: VN-ON2 PASS', $out);
        $on('voice-staff');
        // VK3 (S2-11): steps 2-8 on, the stale reply keys present: R4 never armed, a voice note is handed over, not answered
        $c = vs_cfg($ctx, $b);
        $ctx->eq([$c['ai_media_voice_reply_channels'] ?? null, MediaPolicy::voiceHandoverEnabled($c, $b['data'])], ['all', true], 'VK3 control: the stale channel list still there, R3 on');
        $ctx->eq([MediaPolicy::voiceReplyEnabled($c, $b['data'], 'sales'), MediaPolicy::voiceReplyEnabled($c, $b['data'], 'support'),
                  MediaPolicy::voiceMode($c, $b['data'], 'sales'), MediaPolicy::voiceMode($c, $b['data'], 'support')], [false, false, 'handover', 'handover'],
                 'VK3 steps 2-8 on through the tool over stale reply keys: voiceReplyEnabled false, every voice note handed over, not answered (S2-11)');
        $s8 = vs_back($b, ['ai_media_voice_handover_since'], 8 * $day)['ai_media_voice_handover_since'];
        foreach (['256771498301', '256771498302'] as $i => $ph) {
            $n = vp_note($b, $ph, $s8 + $day + 300 * $i, ['rung' => 'handover', 'status' => 'understood', 'understanding' => 'VS transcript (test)']);
            vp_media_event($b, $n);
            vp_escalation($b, $n['conv'], $n['upd'] + 1);
        }
        [$rc, $out] = sft_run($ctx, $b, ['--check', 'voice-staff']);
        $ctx->eq(strpos($out, 'voice-staff: PASS'), 0, 'VK4 step 8 working: VN-ON3 PASS', $out);
        // T6: on voice-reply without --owner-approved: refused before anything else, nothing saved
        [$rc, $out] = sft_run($ctx, $b, ['on', 'voice-reply', '--channels', 'all']);
        $ctx->eq([$rc, strpos($out, 'REFUSED option_missing') !== false, isset(sft_kyc($b)['ai_media_voice_reply'])], [2, true, false], 'VK4 on voice-reply without --owner-approved: REFUSED option_missing, nothing saved (T6)', $out);
        $on('voice-reply', ['--channels', 'all', '--owner-approved', $today]);
        $c = vs_cfg($ctx, $b);
        $ctx->eq([MediaPolicy::voiceReplyEnabled($c, $b['data'], 'sales'), MediaPolicy::voiceMode($c, $b['data'], 'sales')], [true, 'reply'], 'VK4 step 9 on: R4 armed by its own switch');
        foreach (['voice-measure' => 'VN-ON1', 'voice-dry-run' => 'VN-ON2', 'voice-staff' => 'VN-ON3'] as $s => $id) {
            [$rc, $out] = sft_run($ctx, $b, ['--check', $s]);
            $ctx->eq(strpos($out, $s . ': PASS'), 0, 'VK4 with step 9 on: ' . $id . ' still PASS (its own rung judged; Part F\'s rule 3)', $out);
        }
        $on('cold', $acc, "support's assistant answers quotation questions only (test)\n");
        $on('offers-test', $acc);
        [$rc, $out] = sft_run($ctx, $b, ['show']);
        $ctx->t(strpos($out, VM_KEY) === false, 'VK4 show after the climb prints no key (T4)', $out);
    });
}];
