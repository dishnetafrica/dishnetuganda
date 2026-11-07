<?php
declare(strict_types=1);
/**
 * Section TL of tests/test_sales_followup_tool.php — the tool and the one ladder (root docs/73 §H.2.12, §H.2.16;
 * rulings R5, R6, R7, R11, R13). The engine's rules run in process with a checker that answers each check as told; the
 * commands run as the tool, a child process, as the data folder's owner, against a book of their own. TEST ONLY.
 */
$SECTIONS['TL'] = ['title' => 'The tool and the one ladder (§H.2.12; rulings R5, R6, R7, R11, R13)', 'run' => function (QfCtx $ctx) {
    $now = time();
    $L = 'SalesFollowupLadder';

    $ctx->run('TL00 positive control: the tool runs, show and doctor read a fresh 089+090+091 book, nothing written', function () use ($ctx) {
        $b = sft_book($ctx);
        [$rc, $out] = sft_run($ctx, $b, ['show']);
        $ctx->eq([$rc, strpos($out, '1b agent-cc (optional, P7): off') !== false, strpos($out, 'openai key: not set') !== false], [0, true, true],
                 'TL00 show answers 0, row 1b in its place, the OpenAI key "not set"', $out);
        [$rc, $out] = sft_run($ctx, $b, ['doctor']);
        $ctx->eq([$rc, substr_count($out, 'PASS 0'), strpos($out, '089 names: 38 of 38 present') !== false], [0, 3, true], 'TL00 doctor: three PASS rows, 089\'s 38 names', $out);
        $ctx->t(preg_match('/\d{9,}/', $out . sft_run($ctx, $b, ['show'])[1]) === 0, 'TL00 neither prints a run of 9 or more digits');
        $b['pdo']->prepare("INSERT INTO wa_auto_sends (handset, kind, ref, mode, proactive, phone, local_day, gap_s, claimed_at, outcome) VALUES ('i:dishnet-ug', 'reminder', 'n:TL00', 'record', 1, '256772500801', ?, 60, ?, 'sent')")
                 ->execute([WaSendBudget::localDay(time()), UtcClock::stamp(time())]);
        [$rc, $out] = sft_run($ctx, $b, ['show']);
        $ctx->eq([$rc, preg_match('/: table=1 proactive_today=1 .*record_proactive_today=1 /', $out), preg_match('/\d{9,}/', $out)], [0, 1, 0],
                 'TL00 show prints each handset\'s counts() read on $ro (a recorded reminder today counted), no number printed', $out);
        foreach (['089_quote_followups.sql', '090_wa_voice.sql', '091_wa_offers.sql'] as $f) {
            $b = sft_book($ctx);
            $b['pdo']->prepare('DELETE FROM _schema_checks WHERE migration = ?')->execute([$f]);
            [$rc, $out] = sft_run($ctx, $b, ['doctor']);
            $ctx->eq([$rc, strpos($out, 'FAIL schema_check_missing:' . $f) !== false, substr_count($out, 'PASS 0'), strpos($out, 'ro_changed') === false], [3, true, 2, true],
                     'TL00 doctor with ' . $f . '\'s _schema_checks row removed: FAIL schema_check_missing for it alone, exit 3, read-only', $out);
        }
    });

    // ── the engine's rules, in process ──────────────────────────────────────────────────────────────────────────
    $ctx->run('TL01 the order: every lower step on, skipped or optional (rule 2); row 1b needs step 1 only', function () use ($ctx, $now, $L) {
        $ok = $L::admit(sft_on_upto('voice-measure', $now), 'chase', null, $now, sft_checker([]));
        $ctx->eq([$ok['ok'], $ok['code']], [true, null], 'TL01 control: every lower step on and every check PASS admits on chase');
        foreach (['report', 'ack', 'tracking', 'list', 'voice-measure'] as $off) {
            $c = sft_on_upto('voice-measure', $now);
            foreach ($L::rowKeys($off) as $k) unset($c[$k]);
            if ($off === 'report') unset($c['sf_report_ran_at']);
            $r = $L::admit($c, 'chase', null, $now, sft_checker([]));
            $ctx->eq([$r['ok'], $r['code']], [false, 'order:' . $off], 'TL01 with step ' . $off . ' off, on chase is refused by the order');
        }
        $c = sft_on_upto('voice-measure', $now); unset($c['ai_media_enabled']); $c['sf_skip_voice-measure'] = '2026-11-01 00:00:00|r';
        $ctx->eq($L::admit($c, 'chase', null, $now, sft_checker([]))['ok'], true, 'TL01 a step recorded skipped passes the order');
        $c = sft_on_upto('voice-dry-run', $now);
        foreach ($L::rowKeys('voice-dry-run') as $k) unset($c[$k]);
        $c['sf_skip_voice-dry-run'] = 'x|r';
        $ctx->eq($L::admit($c, 'voice-staff', null, $now, sft_checker([]))['code'], 'order:voice-dry-run',
                 'TL01 a skipped voice step never serves a voice step (ruling R6)');
        $ctx->eq($L::admit(['sf_report_ran_at' => '2026-11-01'] + SFT_UG, 'agent-cc', null, $now, sft_checker([]))['ok'], true,
                 'TL01 on agent-cc with step 1 run: admitted (its Needs are none)');
        $ctx->eq($L::admit(SFT_UG, 'agent-cc', null, $now, sft_checker([]))['code'], 'order:report', 'TL01 on agent-cc before step 1: refused');
    });

    $ctx->run('TL02 the needs: on (not skipped), long enough, at PASS (rule 4)', function () use ($ctx, $now, $L) {
        $c = sft_on_upto('voice-measure', $now);
        $ctx->eq($L::admit($c, 'chase', null, $now, sft_checker(['Q1-ON' => 'NOT_YET no_traffic']))['code'], 'need_not_yet:tracking',
                 'TL02 chase\'s need Q1-ON at NOT_YET no_traffic, nothing accepted: refused');
        $ctx->eq($L::admit($c, 'chase', null, $now, sft_checker(['Q1-ON' => 'FAIL tracker_parity']))['code'], 'earlier_fail:tracking',
                 'TL02 chase with Q1-ON at FAIL: refused (rule 3 reads it first)');
        $c2 = $c; $c2['quote_tracking_since'] = UtcClock::stamp($now - 3600);
        $ctx->eq($L::admit($c2, 'chase', null, $now, sft_checker([]))['code'], 'need_too_soon:tracking', 'TL02 tracking on for less than 72 h: chase refused');
        $c3 = sft_on_upto('ack', $now); unset($c3['wa_send_ledger']);
        $ctx->eq($L::admit($c3, 'tracking', null, $now, sft_checker([]))['code'], 'need_off:sending', 'TL02 the ledger off: tracking refused');
        $ctx->eq($L::admit(sft_on_upto('ack', $now), 'tracking', null, $now, sft_checker(['L-ON' => 'NOT_YET too_soon']))['code'], 'need_not_pass:sending',
                 'TL02 L-ON NOT_YET too_soon: tracking refused (only no_traffic is ever accepted)');
    });

    $ctx->run('TL03 step 10 is optional for 11 and needs step 6 for at least 72 h', function () use ($ctx, $now, $L) {
        $c = sft_on_upto('voice-reply', $now);
        $ctx->eq($L::admit($c, 'offers-test', null, $now, sft_checker([]))['ok'], true, 'TL03 on offers-test with the optional step 10 off: admitted');
        $c = sft_on_upto('list', $now);
        $ctx->eq($L::admit($c, 'cold', null, $now, sft_checker([]))['ok'], false, 'TL03 on cold before step 6 is on: refused');
        $c = sft_on_upto('voice-reply', $now); $c['quote_chase_not_before'] = UtcClock::stamp($now - 600);
        $ctx->eq($L::admit($c, 'cold', null, $now, sft_checker([]))['code'], 'need_too_soon:chase', 'TL03 cold within 72 h of step 6: refused');
    });

    $ctx->run('TL12 a FAIL is never accepted; NOT_YET no_traffic is, after 72 h (rule 4)', function () use ($ctx, $now, $L) {
        $c = sft_on_upto('tracking', $now);
        $a = $L::admit($c, 'list', 'quiet first week (test)', $now, sft_checker(['Q1-ON' => 'NOT_YET no_traffic']));
        $ctx->eq([$a['ok'], array_keys($a['overrides'])], [true, ['sf_override_tracking']], 'TL12 control: NOT_YET no_traffic after 72 h accepted, the override recorded');
        $ctx->eq($L::admit($c, 'list', 'reason (test)', $now, sft_checker(['Q1-ON' => 'FAIL tracker_parity']))['ok'], false, 'TL12 a FAIL with --accept-not-yet: refused');
        // Rule 3 sees a need's FAIL first wherever the need is a step that is on; the ledger's need on step 3 reaches rule 4
        // alone when step 2 is recorded skipped (skip ack sets the ledger): there the FAIL is rule 4's to refuse.
        $s = sft_on_upto('report', $now);
        $s['sf_skip_ack'] = UtcClock::stamp($now - 864000) . '|no voice notes here (test)';
        $s['wa_send_ledger'] = 'on'; $s['wa_send_ledger_since'] = UtcClock::stamp($now - 864000);
        $ctx->eq($L::admit($s, 'tracking', 'quiet (test)', $now, sft_checker(['L-ON' => 'NOT_YET no_traffic']))['ok'], true,
                 'TL12 control: step 2 skipped, the ledger 10 days on, L-ON NOT_YET no_traffic accepted for step 3');
        $ctx->eq($L::admit($s, 'tracking', 'reason (test)', $now, sft_checker(['L-ON' => 'FAIL hook_silent']))['code'], 'need_fail:sending',
                 'TL12 the ledger\'s L-ON at FAIL with --accept-not-yet, step 2 skipped: refused by rule 4 (need_fail)');
        $c2 = $c; $c2['quote_tracking_since'] = UtcClock::stamp($now - 86400 * 2);
        $ctx->eq($L::admit($c2, 'list', 'reason (test)', $now, sft_checker(['Q1-ON' => 'NOT_YET no_traffic']))['code'], 'need_not_yet:tracking',
                 'TL12 NOT_YET no_traffic under 72 h on: refused even with a reason');
    });

    $ctx->run('TL16 an earlier step at FAIL blocks every later on (rule 3)', function () use ($ctx, $now, $L) {
        $c = sft_on_upto('voice-dry-run', $now);
        $ctx->eq($L::admit($c, 'voice-staff', null, $now, sft_checker([]))['ok'], true, 'TL16 control: admitted with every check PASS');
        $ctx->eq($L::admit($c, 'voice-staff', null, $now, sft_checker(['Q2-ON' => 'FAIL missed']))['code'], 'earlier_fail:list',
                 'TL16 step 4 at FAIL: voice-staff refused');
        $c['quote_agent_cc_enabled'] = '1';
        $ctx->eq($L::admit($c, 'voice-staff', null, $now, sft_checker(['AC-ON' => 'FAIL decision_missing']))['code'], 'earlier_fail:agent-cc',
                 'TL16 row 1b on at FAIL: every later on refused (its one cost)');
        $ctx->eq($L::admit($c, 'voice-staff', null, $now, sft_checker(['AC-ON' => 'NOT_YET no_traffic']))['ok'], true, 'TL16 row 1b at NOT_YET: admitted');
    });

    $ctx->run('TL17 the one vocabulary: an unknown status is never PASS; an off step\'s line is a note', function () use ($ctx, $now, $L) {
        foreach (['WAIT', 'WARN', 'PASS ', 'pass', 'OFF — not judged', 'NOT_YET later', 'FAIL', ''] as $s) {
            $ctx->eq($L::status($s)['kind'], trim($s) === 'PASS' ? 'PASS' : 'FAIL', 'TL17 status(' . json_encode($s) . ')');
        }
        $ctx->eq($L::status('NOT_YET no_test')['kind'], 'NOT_YET', 'TL17 NOT_YET no_test is a status');
        $c = sft_on_upto('tracking', $now);
        $ctx->eq($L::admit($c, 'list', null, $now, sft_checker(['Q1-ON' => 'WAIT']))['ok'], false, 'TL17 a need reading WAIT: refused');
        $ctx->eq($L::admit($c, 'list', null, $now, sft_checker(['L-ON' => 'WARN']))['code'], 'earlier_fail:ack', 'TL17 step 2 reading WARN counts as FAIL');
    });

    // B9-fix2 (root docs/73 §H.2.12.5 L-ON, D 2430; ruling R6; §H.2.5.7): a handset seen in the ledger but not in the
    // registry is one note line, by its index, never a status and never its instance name.
    $ctx->run('TL17b --check ack: a ledger row on a handset none of evo_instance_sales/support/account names prints the registry note; the status unchanged', function () use ($ctx, $now) {
        sft_log_reset($ctx);
        $b = sft_book($ctx, sft_on_upto('ack', $now));
        $T = time();
        $ctx->eq(sft_ledger($b, $T)->record('Sales-UG', 'reminder', 'n:TL17b-1', 'sent'), 'recorded', 'TL17b control: a row recorded on the sales line (registered) through the ledger');
        [$rc0, $out0] = sft_run($ctx, $b, ['--check', 'ack']);
        $lines0 = explode("\n", trim($out0));
        $ctx->eq([in_array('  L-ON: PASS', $lines0, true), strpos($out0, 'not in the registry')], [true, false],
                 'TL17b control: L-ON PASS over a registered line\'s row, and no registry note', $out0);
        $ctx->eq(sft_ledger($b, $T)->record('Rep-Line-7', 'reminder', 'n:TL17b-2', 'sent'), 'recorded', 'TL17b a row recorded on a line none of the three names, through the ledger');
        [$rc, $out] = sft_run($ctx, $b, ['--check', 'ack']);
        $lines = explode("\n", trim($out));
        $reg = preg_grep('/not in the registry/', $lines);
        $ok = count($reg) === 1 && preg_match('/^    note: a handset not in the registry: (h\d+) \(another line\)$/', (string)reset($reg), $mm) === 1;
        $ctx->t($ok, 'TL17b the registry note printed once, as a note line, the line by its index', $out);
        if ($ok) {
            $ctx->t(preg_grep('/^    note: ledger \S+ ' . preg_quote($mm[1], '/') . ' \(another line\) reminder=1$/', $lines) !== [],
                    'TL17b the note\'s index is the one the ledger line names it by', $out);
        }
        $ctx->eq([$rc, $lines[0] ?? '', in_array('  L-ON: PASS', $lines, true)], [$rc0, $lines0[0] ?? '', true],
                 'TL17b the step\'s status, L-ON\'s status and the exit code unchanged by the note', $out0 . "\n--\n" . $out);
        $ctx->eq(array_values(preg_grep('/registry/', preg_grep('/^    note: /', $lines, PREG_GREP_INVERT))), [], 'TL17b the note is never a status line', $out);
        $ctx->eq([stripos($out, 'rep-line'), preg_match('/#\d/', $out), preg_match('/\d{9,}/', $out)], [false, 0, 0],
                 'TL17b no instance name, no #<digits> and no number in the output', $out);
    });

    $ctx->run('TL23 a pass_only need is met by PASS alone: never an accepted NOT_YET, never a skip (ruling R6)', function () use ($ctx, $now, $L) {
        $c = sft_on_upto('chase', $now);
        $ctx->eq($L::admit($c, 'voice-dry-run', 'reason (test)', $now, sft_checker(['VN-ON1' => 'NOT_YET no_traffic']))['code'], 'need_not_pass:voice-measure',
                 'TL23 VN-ON1 NOT_YET no_traffic with --accept-not-yet after 72 h: on voice-dry-run refused');
        $ctx->eq($L::admit($c, 'voice-dry-run', null, $now, sft_checker([]))['ok'], true, 'TL23 control: VN-ON1 PASS admits it');
        $c2 = $c; unset($c2['ai_media_enabled']); $c2['sf_skip_voice-measure'] = 'x|r';
        $ctx->eq($L::admit($c2, 'voice-dry-run', null, $now, sft_checker([]))['ok'], false, 'TL23 voice-measure skipped: on voice-dry-run refused');
    });

    $ctx->run('TL24 offers-test\'s needs: the ledger with L-ON at PASS, tracking on; not VA-ON1, not step 10 (ruling R6)', function () use ($ctx, $now, $L) {
        $c = sft_on_upto('voice-reply', $now);
        $ctx->eq($L::admit($c, 'offers-test', null, $now, sft_checker(['VA-ON1' => 'NOT_YET no_traffic']))['ok'], true,
                 'TL24 VA-ON1 at NOT_YET and step 10 off: on offers-test admitted');
        $ctx->eq($L::admit($c, 'offers-test', 'reason (test)', $now, sft_checker(['L-ON' => 'NOT_YET no_traffic']))['ok'], false,
                 'TL24 L-ON at an accepted NOT_YET: refused (pass_only)');
        $c2 = $c; unset($c2['quote_tracking_enabled']); $c2['sf_skip_tracking'] = 'x|r';
        foreach (['list', 'chase', 'cold'] as $s) $c2['sf_skip_' . $s] = 'x|r';
        foreach (['quote_daily_list_enabled', 'quote_chase_enabled'] as $k) unset($c2[$k]);
        $ctx->eq($L::admit($c2, 'offers-test', null, $now, sft_checker([]))['ok'], false, 'TL24 tracking skipped: on offers-test refused');
        $ctx->eq($L::admit($c, 'offers-test', null, $now, sft_checker(['Q1-ON' => 'NOT_YET no_traffic']))['ok'], false,
                 'TL24 Q1-ON NOT_YET, nothing accepted: refused');
        $ctx->eq($L::admit($c, 'offers-test', 'reason (test)', $now, sft_checker(['Q1-ON' => 'NOT_YET no_traffic']))['ok'], true,
                 'TL24 Q1-ON NOT_YET no_traffic accepted after 72 h: admitted');
    });

    $ctx->run('TL30 step 2\'s one status, the worse of L-ON and VA-ON1; a Need on L-ON reads L-ON alone', function () use ($ctx, $now, $L) {
        $s = $L::stepStatus('ack', sft_checker(['VA-ON1' => 'FAIL coverage']));
        $ctx->eq([$s['status'], array_keys($s['parts'])], ['FAIL coverage', ['L-ON', 'VA-ON1']], 'TL30 VA-ON1 FAIL with L-ON PASS: step 2 reads FAIL coverage');
        $ctx->eq($L::stepStatus('ack', sft_checker(['L-ON' => 'FAIL hook_silent', 'VA-ON1' => 'FAIL coverage']))['status'], 'FAIL hook_silent',
                 'TL30 both FAIL: L-ON\'s code');
        $ctx->eq($L::stepStatus('ack', sft_checker(['VA-ON1' => 'NOT_YET no_traffic']))['status'], 'NOT_YET no_traffic', 'TL30 VA-ON1 NOT_YET: step 2 NOT_YET');
        $c = sft_on_upto('ack', $now);
        $ctx->eq($L::admit($c, 'tracking', null, $now, sft_checker(['VA-ON1' => 'FAIL coverage']))['code'], 'earlier_fail:ack', 'TL30 VA-ON1 FAIL refuses on tracking');
        $ctx->eq($L::admit($c, 'tracking', null, $now, sft_checker(['VA-ON1' => 'NOT_YET no_traffic']))['ok'], true,
                 'TL30 VA-ON1 NOT_YET with L-ON PASS admits on tracking, no --accept-not-yet');
    });

    $ctx->run('TL15 skip and its cascade; the keys and settings accessors; the one sequence (rulings R5, R6, R7)', function () use ($ctx, $L) {
        $ctx->eq($L::skipCascade('tracking'), ['list', 'chase', 'cold', 'offers-test', 'offers-live'], 'TL15 skip tracking cascades to 4, 6, 10, 11 and 12');
        $ctx->eq($L::skipCascade('voice-measure'), ['voice-dry-run', 'voice-staff', 'voice-reply'], 'TL15 skip voice-measure cascades to 7, 8, 9');
        $ctx->eq($L::skipCascade('offers-test'), ['offers-live'], 'TL15 skip offers-test cascades to 12');
        $ctx->eq($L::skipCascade('ack'), [], 'TL15 skip ack cascades to nothing (the ledger stays)');
        $ctx->eq(array_values(array_intersect($L::allKeys(), $L::settingKeys())), [], 'TL21 allKeys() and settingKeys() share no key');
        $ctx->t(in_array('sf_persona_confirmed', $L::settingKeys(), true) && in_array('quote_agent_cc_admins', $L::settingKeys(), true),
                'TL21 sf_persona_confirmed and quote_agent_cc_admins are settings');
        foreach (['wa_send_ledger', 'quote_agent_cc_enabled', 'quote_agent_cc_since', 'ai_media_voice_reply', 'ai_transcription_provider', 'offers_live_since', 'sf_chase_first_on'] as $k) {
            $ctx->t(in_array($k, $L::allKeys(), true), 'TL21 ' . $k . ' is in allKeys()');
        }
        $ctx->eq(array_map(function ($a) { return $a[1]; }, $L::offPlan('all')),
                 ['offers-live', 'offers-test', 'cold', 'chase', 'voice-reply', 'voice-staff', 'voice-dry-run', 'voice-measure', 'list', 'tracking', 'ack', 'sending', 'records'],
                 'TL26 off all: O (12, 11), F (10, 6), V (9, 8, 7, 5), 4, 3, 2, the ledger, then the record keys and P\'s two keys');
        $ctx->eq($L::offCascade('tracking'), ['offers-live', 'offers-test', 'cold', 'chase', 'list', 'tracking'], 'TL05 off tracking clears 4, 6, 10, 11, 12 in the one sequence');
        $ctx->eq($L::offCascade('ack'), ['ack'], 'TL05 off ack clears P5 only');
        $ctx->eq([$L::hookOf('offers-live'), $L::hookOf('offers-test'), $L::hookOf('voice-measure'), $L::hookOf('ack'), $L::hookOf('chase')],
                 [['O', 'before', 'always'], ['O', 'before', 'always'], ['V', 'after', 'always'], ['V', 'after', 'always'], ['F', 'before', 'rollback']],
                 'TL27 the hooks\' places: O before its keys, V after, F before and in the rollback only');
        $last = array_values(array_intersect($L::lastKeys(), ['quote_agent_cc_enabled', 'quote_agent_cc_since', 'sf_report_ran_at', 'sf_cold_statement']));
        sort($last);
        $ctx->eq($last, ['quote_agent_cc_enabled', 'quote_agent_cc_since', 'sf_cold_statement', 'sf_report_ran_at'], 'TL26 P\'s two keys go with the record keys, last (ruling R11)');
        $ctx->eq(array_values(array_diff(OfferSwitch::declaredKeys(), array_merge($L::allKeys(), $L::settingKeys()))), [],
                 'TL15 Part O\'s declaredKeys() are among the two accessors (P-O1-9)');
    });

    $ctx->run('TL28 set: declared settings only, by key and alias, each by its range; refused saves nothing (rulings R4, R5)', function () use ($ctx, $now, $L) {
        $v = $L::validateSet('ack-hours', ['7', '20'], SFT_UG, [], $now);
        $ctx->eq([$v['ok'], $v['changes']], [true, ['wa_voice_ack_hour_from' => '7', 'wa_voice_ack_hour_to' => '20']], 'TL28 control: set ack-hours 7 20 narrows');
        foreach ([['ack-hours', ['7', '22']], ['daily-cap', ['61']], ['window', ['7', '20']], ['window', ['9', '9']], ['gap', ['30', '20']], ['nosuch', ['1']],
                  ['wa_send_ledger', ['on']], ['timezone', ['UTC']], ['quote_agent_cc_enabled', ['1']], ['agent-cc-admins', ['2']],
                  ['ai_media_openai_stt_model', ['whisper-2']], ['persona-confirmed', ['2099-01-01']], ['offers_daily_max_per_handset', ['61']],
                  ['list-staff', ['99']], ['ai_media_voice_reply_channels', ['all']]] as [$k, $vals]) {
            $r = $L::validateSet($k, $vals, SFT_UG, [5], $now);
            $ctx->eq([$r['ok'], $r['changes']], [false, []], 'TL28 set ' . $k . ' ' . implode(' ', $vals) . ': refused, nothing saved');
        }
        foreach ([['agent-cc-admins', ['0']], ['agent-cc-admins', ['1']], ['daily-cap', ['60']], ['list-staff', ['5']], ['persona-confirmed', ['2026-11-01']],
                  ['offers_warm_days', ['7']], ['ai_media_voice_daily_minutes', ['0']]] as [$k, $vals]) {
            $ctx->eq($L::validateSet($k, $vals, SFT_UG, [5], $now)['ok'], true, 'TL28 set ' . $k . ' ' . implode(' ', $vals) . ': saved');
        }
        $ctx->eq($L::validateSet('offers_daily_max_per_handset', ['30'], ['wa_send_daily_cap' => '25'] + SFT_UG, [], $now)['ok'], false,
                 'TL28 the offers ceiling above the ledger\'s daily cap: refused');
    });

    $ctx->run('TL34 a row\'s options, validated by the tool before any precondition (A12-03)', function () use ($ctx, $now, $L) {
        $dept = ['sales', 'support', 'account'];
        $ok = $L::validateOptions('voice-reply', ['channels' => 'sales,support', 'owner-approved' => $L::today($now)], $dept, $now);
        $ctx->eq([$ok['ok'], $ok['keys']], [true, ['ai_media_voice_reply_channels' => 'sales,support', 'ai_media_voice_reply_approved' => $L::today($now)]],
                 'TL34 control: known channels and today\'s approval admitted');
        $ctx->eq($L::validateOptions('voice-reply', ['channels' => 'all', 'owner-approved' => '2026-11-01'], $dept, $now)['ok'], true, 'TL34 --channels all admitted');
        foreach ([[['channels' => 'nosuch', 'owner-approved' => '2026-11-01'], 'option_invalid'], [['channels' => 'all', 'owner-approved' => '2099-01-01'], 'option_invalid'],
                  [['channels' => 'all'], 'option_missing'], [['channels' => 'all', 'owner-approved' => '2026-11-01', 'quality-ok' => '1'], 'option_undeclared']] as [$o, $want]) {
            $ctx->eq($L::validateOptions('voice-reply', $o, $dept, $now)['code'], $want, 'TL34 voice-reply ' . json_encode($o) . ': ' . $want);
        }
        $ctx->eq($L::validateOptions('report', [], $dept, $now)['code'], 'option_missing', 'TL34 on report without --ran: refused');
        $ctx->eq($L::validateOptions('report', ['ran' => '2099-01-01'], $dept, $now)['code'], 'option_invalid', 'TL34 on report with a --ran after today: refused');
        $ctx->eq($L::validateOptions('tracking', ['ran' => '2026-11-01'], $dept, $now)['code'], 'option_undeclared', 'TL34 an option tracking does not declare: refused');
    });

    $ctx->run('TL35 row 1b reaches QuoteAgentCopy::preconditions($dataDir), its answer read for ok, code and lines (A12-04)', function () use ($ctx, $L) {
        $b = sft_book($ctx);
        $ro = sft_ro($b);
        file_put_contents($b['data'] . '/email_settings.json', json_encode(['smtp_host' => '127.0.0.1', 'smtp_from' => 'quotes@agents.example.test']));
        $p = $L::precondition($ro, $b['cfg'], $b['data'], 'agent-cc');
        $ctx->eq([$p['ok'], $p['code'], $p['lines']], [true, null, ['plugin mail: plugin SMTP']], 'TL35 plugin SMTP: ok, its one line');
        file_put_contents($b['data'] . '/email_settings.json', json_encode(['use_ucrm_email' => false]));
        $p = $L::precondition($ro, $b['cfg'], $b['data'], 'agent-cc');
        $ctx->eq([$p['ok'], $p['code']], [false, 'mail_not_configured'], 'TL35 neither: refused mail_not_configured');
        $ctx->eq(sft_changes($ro), 0, 'TL35 the precondition wrote nothing on $ro');
    });

    $ctx->run('TL40 the call surface (ruling R13): checks and preconditions on $ro, hooks on $rw, no holder opens the book', function () use ($ctx, $now, $L) {
        $b = sft_book($ctx, sft_on_upto('offers-live', $now));
        $ro = sft_ro($b);
        $decoy = $ctx->dir('decoy');
        $cfg = $b['cfg'];
        $ids = ['report', 'AC-ON', 'L-ON', 'VA-ON1', 'Q1-ON', 'Q2-ON', 'VN-ON1', 'Q3-ON', 'VN-ON2', 'VN-ON3', 'VN-ON4', 'Q5-ON', 'offers-test', 'offers-live'];
        $vocab = true; $threw = [];
        foreach ($ids as $id) {
            $r = $L::check($ro, $cfg, $decoy, $ctx->tree(), $id, $now);
            if ($L::status((string)($r['status'] ?? ''))['code'] === 'unknown_status' || !isset($r['counts'], $r['notes'])) $vocab = false;
            if (in_array((string)($r['status'] ?? ''), ['FAIL check_threw', 'FAIL unknown_check'], true)) $threw[] = $id;
        }
        $ctx->t($vocab, 'TL40 every check id reaches its callable and answers the one shape and vocabulary');
        $ctx->eq($threw, [], 'TL40 no check id answers FAIL check_threw or FAIL unknown_check (its dispatch runs)');
        // Each id against the callable ruling R13 names, called directly with the same arguments, on a book where the ids'
        // answers are pairwise different (the control): a dispatch to another holder or with its arguments swapped differs.
        $kb = sft_book($ctx, sft_on_upto('offers-live', $now, 864000, ['cold', 'agent-cc']));
        sft_reminders_done($kb, qf_t(QF_MONDAY . ' 09:00:00'));
        sft_chase($kb, '256772504001', 'Q-TL40', qf_t(QF_MONDAY . ' 10:00:00'));   // a chase after the customer spoke: Q3-ON judges it, Q5-ON (cold) does not
        $kro = sft_ro($kb);
        $kcfg = $kb['cfg'];
        $direct = [
            'AC-ON'       => function () use ($kro, $ctx, $kb, $now) { return QuoteAgentCopy::check($kro, $ctx->tree(), $kb['data'], $now); },
            'offers-test' => function () use ($kro, $kcfg, $kb, $now) { return OfferSwitch::checkTest($kro, $kcfg, $kb['data'], $now); },
            'offers-live' => function () use ($kro, $kcfg, $kb, $now) { return OfferSwitch::checkLive($kro, $kcfg, $kb['data'], $now); },
        ];
        foreach (['L-ON', 'Q1-ON', 'Q2-ON', 'Q3-ON', 'Q5-ON'] as $id) $direct[$id] = function () use ($kro, $kcfg, $kb, $id, $now) { return SalesFollowupChecks::check($kro, $kcfg, $kb['data'], $id, $now); };
        foreach (['VA-ON1', 'VN-ON1', 'VN-ON2', 'VN-ON3', 'VN-ON4'] as $id) $direct[$id] = function () use ($kro, $kcfg, $kb, $id, $now) { return VoiceSteps::check($kro, $kcfg, $kb['data'], $id, $now); };
        $want = []; $bad = [];
        foreach ($direct as $id => $fn) {
            $want[$id] = $fn();
            $got = $L::check($kro, $kcfg, $kb['data'], $ctx->tree(), $id, $now);
            if ($got !== $want[$id]) $bad[] = $id . ': ' . sft_st($got) . ' (its callable: ' . sft_st($want[$id]) . ')';
        }
        $dups = [];
        foreach ($want as $a => $wa) foreach ($want as $c => $wc) if ($a < $c && $wa === $wc) $dups[] = $a . '=' . $c;
        $ctx->eq($dups, [], 'TL40 control: on this book the thirteen callables answer pairwise differently');
        $ctx->eq($bad, [], 'TL40 every check id reaches the callable ruling R13 names, with its arguments in their order');
        $pbad = [];
        foreach ($L::steps() as $s) {
            $p = $L::precondition($ro, $cfg, $decoy, $s);
            if (in_array((string)$p['code'], ['precondition_threw', 'unknown_part', 'unknown_step'], true)) $pbad[] = $s . ': ' . $p['code'];
        }
        $ctx->eq($pbad, [], 'TL40 no row\'s precondition answers precondition_threw (each part\'s holder runs)');
        // Each row's own precondition against its part's holder called directly (rows of one part; the others none).
        $holder = [
            'F' => function (string $s) use ($kro, $kcfg, $kb) { return SalesFollowupChecks::preconditions($kro, $kcfg, $kb['data'], $s); },
            'V' => function (string $s) use ($kro, $kcfg, $kb) { return VoiceSteps::preconditions($kro, $kcfg, $kb['data'], $s); },
            'O' => function (string $s) use ($kro, $kcfg, $kb) { return OfferSwitch::preconditions($kro, $kcfg, $kb['data'], $s); },
            'P' => function (string $s) use ($kb) { return QuoteAgentCopy::preconditions($kb['data']); },
        ];
        $pbad = []; $seen = 0;
        foreach ($L::steps() as $s) {
            $pre = $L::row($s)['pre'];
            if (count($pre) > 1) continue;
            $d = $pre === [] ? ['ok' => true, 'code' => null, 'lines' => []] : $holder[$pre[0]]($s);
            $w = ['ok' => !empty($d['ok']), 'code' => !empty($d['ok']) ? null : ((string)($d['code'] ?? '') !== '' ? (string)$d['code'] : 'precondition'),
                  'lines' => array_map('strval', (array)($d['lines'] ?? []))];
            $g = $L::precondition($kro, $kcfg, $kb['data'], $s);
            if ($g !== $w) $pbad[] = $s . ': ' . json_encode($g['code']) . ' (its holder: ' . json_encode($w['code']) . ')';
            if ($pre !== []) $seen++;
        }
        $ctx->eq([$pbad, $seen], [[], 8], 'TL40 every one-part row\'s precondition is its holder\'s answer, its arguments in their order (8 rows)');
        $ctx->eq(sft_changes($kro), 0, 'TL40 … and the second book\'s $ro is unchanged');
        $ctx->eq([is_file($decoy . '/plugin.sqlite3'), sft_changes($ro)], [false, 0],
                 'TL40 given $ro and another data folder, no check or precondition opened a book there, and $ro is unchanged');
        $rw = $b['pdo'];
        foreach (['offers-live', 'offers-test', 'voice-measure', 'ack'] as $s) $L::runHook($rw, $cfg, $decoy, $s, 'switch');
        $ctx->eq(is_file($decoy . '/plugin.sqlite3'), false, 'TL40 the hooks wrote through $rw alone: no book opened in the other folder');
        $ctx->eq($L::runHook($rw, $cfg, $decoy, 'chase', 'switch'), null, 'TL40 row 6\'s hook runs in the rollback only');
        $ctx->eq($L::runHook($rw, $cfg, $decoy, 'chase', 'rollback'), ['closed' => 0, 'expired' => 0, 'remaining' => 0], 'TL40 … and reaches QuoteChase::offHook there');
    });

    // ── the commands, as the tool ───────────────────────────────────────────────────────────────────────────────
    $ctx->run('TL19 step 2: on ack on a fresh 089+090 book sets the ledger and P5, a P5 claim then answers ok; refused without either row', function () use ($ctx, $now) {
        $b = sft_book($ctx, ['sf_report_ran_at' => '2026-11-01']);
        [$rc, $out] = sft_run($ctx, $b, ['on', 'ack']);
        $k = sft_kyc($b);
        $ctx->eq([$rc, $k['wa_send_ledger'] ?? null, isset($k['wa_send_ledger_since'], $k['wa_voice_ack_since']), $k['wa_voice_ack_enabled'] ?? null],
                 [0, 'on', true, '1'], 'TL19 on ack: the ledger on with its since, P5 on with its since', $out);
        $ctx->t(strpos($out, 'the ledger starts recording every automated send') !== false, 'TL19 the interplay line printed');
        $T = qf_t('2026-11-02 10:00:00');
        $cfg = PluginConfig::read($ctx->tree(), $b['data']) + ['_data_dir' => $b['data']];
        $c = WaSendBudget::forInstall($cfg, $b['data'], $b['store'], null, function () use ($T): int { return $T; })->claim('DishNet-UG', 'voice_ack', 'ack:TL19');
        $ctx->eq(!empty($c['ok']), true, 'TL19 a P5 claim on the switched-on book answers ok (S2-12)', json_encode($c));
        foreach (['089_quote_followups.sql', '090_wa_voice.sql'] as $f) {
            $b = sft_book($ctx, ['sf_report_ran_at' => '2026-11-01']);
            $b['pdo']->prepare('DELETE FROM _schema_checks WHERE migration = ?')->execute([$f]);
            [$rc, $out] = sft_run($ctx, $b, ['on', 'ack']);
            $ctx->eq([$rc, strpos($out, 'schema_check_missing:' . $f) !== false, isset(sft_kyc($b)['wa_send_ledger'])], [2, true, false],
                     'TL19 without the ' . $f . ' row: refused, nothing saved', $out);
        }
    });

    $ctx->run('TL25 both option forms; TL06 on re-stamps its since; the writes go through saveOverrides (file and store row)', function () use ($ctx, $now) {
        foreach ([['--ran', '2026-11-01'], ['--ran=2026-11-01']] as $form) {
            $b = sft_book($ctx);
            [$rc, $out] = sft_run($ctx, $b, array_merge(['on', 'report'], $form));
            $ctx->eq([$rc, sft_kyc($b)['sf_report_ran_at'] ?? null], [0, '2026-11-01'], 'TL25 on report ' . implode(' ', $form) . ': saved', $out);
        }
        $b = sft_book($ctx, sft_on_upto('tracking', $now) + ['quote_tracking_since' => '2026-10-01 00:00:00']);
        [$rc, $out] = sft_run($ctx, $b, ['on', 'tracking']);
        $since = (string)(sft_kyc($b)['quote_tracking_since'] ?? '');
        $ctx->eq([$rc, abs(UtcClock::parse($since) - time()) < 120], [0, true], 'TL06 a second on tracking re-stamps quote_tracking_since', $out);
        $store = json_decode((string)$b['pdo']->query('SELECT data FROM [kyc_config] WHERE id = 0')->fetchColumn(), true);
        $ctx->eq((string)($store['quote_tracking_since'] ?? ''), $since, 'TL06 the store row holds the same stamp (saveOverrides mirrors it)');
    });

    $ctx->run('TL04 TL05 TL07 TL22 off: refuses nothing where the book exists, cascades, unsets every key, keeps the settings', function () use ($ctx, $now, $L) {
        $b = sft_book($ctx, sft_on_upto('offers-live', $now, 864000, ['cold', 'agent-cc']) + ['sf_persona_confirmed' => '2026-11-01', 'wa_send_daily_cap' => '40',
                                                                                         'quote_agent_cc_admins' => '0', 'offers_warm_days' => '20']);
        [$rc, $out] = sft_run($ctx, $b, ['off', 'tracking']);
        $k = sft_kyc($b);
        $ctx->eq([$rc, isset($k['quote_tracking_enabled']), isset($k['quote_chase_enabled']), isset($k['quote_chase_cold']), isset($k['offers_test_enabled']),
                  isset($k['offers_live_enabled']), isset($k['quote_daily_list_enabled']), isset($k['ai_media_enabled']), $k['sf_chase_first_on'] ?? 'kept'],
                 [0, false, false, false, false, false, false, true, 'kept'], 'TL05 off tracking: 3, 4, 6, 10, 11, 12 off; step 5 and the record keys kept', $out);
        $all = [];
        foreach ($L::allKeys() as $key) $all[$key] = $key === 'wa_send_ledger' ? 'on' : '1';
        $all['ai_transcription_provider'] = 'openai';
        sft_files($b, $all + sft_kyc($b));
        [$rc, $out] = sft_run($ctx, $b, ['off', 'all']);
        $k = sft_kyc($b);
        $left = array_values(array_intersect($L::allKeys(), array_keys($k)));
        $ctx->eq([$rc, $left], [0, []], 'TL07 off all: no key of allKeys() left in the overrides file (none written as none or 0)', $out);
        $store = json_decode((string)$b['pdo']->query('SELECT data FROM [kyc_config] WHERE id = 0')->fetchColumn(), true) ?: [];
        $ctx->eq(array_values(array_intersect($L::allKeys(), array_keys($store))), [], 'TL07 … nor in the store row');
        $ctx->eq(array_key_exists('ai_transcription_provider', $k), false, 'TL22 ai_transcription_provider unset, never written none');
        $ctx->eq([$k['sf_persona_confirmed'] ?? null, $k['wa_send_daily_cap'] ?? null, $k['quote_agent_cc_admins'] ?? null, $k['offers_warm_days'] ?? null],
                 ['2026-11-01', '40', '0', '20'], 'TL21 every setting kept as set, the persona date among them');
        $ctx->eq(array_values(array_filter($L::allKeys(), function ($key) use ($ctx, $b) { $c = sft_read($ctx, $b); return isset($c[$key]) && $c[$key] !== ''; })), [],
                 'TL07 and none reads set through PluginConfig::read');
        $ctx->t(preg_match('/\d{9,}/', $out) === 0, 'TL10 off all prints no run of 9 or more digits');
    });

    $ctx->run('TL07b the read-back: a key held by <data>/config.json fails key_outside_overrides, the file named and never the value', function () use ($ctx, $now) {
        $b = sft_book($ctx, sft_on_upto('ack', $now));
        file_put_contents($b['data'] . '/config.json', json_encode(['wa_voice_ack_enabled' => 'held-value-xyz']));
        [$rc, $out] = sft_run($ctx, $b, ['off', 'ack']);
        $ctx->eq([$rc, strpos($out, 'FAIL key_outside_overrides wa_voice_ack_enabled') !== false, strpos($out, '<data>/config.json') !== false,
                  strpos($out, 'held-value-xyz') !== false], [3, true, true, false], 'TL07b off ack: FAIL key_outside_overrides naming <data>/config.json, not the value', $out);
        [$rc, $out] = sft_run($ctx, $b, ['off', 'all', '--rollback']);
        $ctx->eq([$rc, strpos($out, 'FAIL key_outside_overrides') !== false], [3, true], 'TL07b the rollback\'s final assertion fails with it', $out);
        unlink($b['data'] . '/config.json');
        [$rc, $out] = sft_run($ctx, $b, ['off', 'all', '--rollback']);
        $ctx->eq([$rc, strpos($out, 'rollback: every 5.18.104 key is unset') !== false], [0, true], 'TL07b control: with the file gone the rollback reads clean', $out);
    });

    $ctx->run('TL20 TL26 TL27 off all --rollback: the one sequence, each hook in its place, the ledger last', function () use ($ctx, $now) {
        $b = sft_book($ctx, sft_on_upto('offers-live', $now, 864000, ['cold']));
        [$rc, $out] = sft_run($ctx, $b, ['off', 'all', '--rollback']);
        $lines = array_values(array_filter(array_map('trim', explode("\n", $out)), function ($l) { return preg_match('/^(OFF |hook )/', $l); }));
        $tags = array_map(function ($l) { return preg_replace('/^(OFF|hook) ([a-z\-]+).*$/', '$1 $2', $l); }, $lines);
        $want = ['hook offers-live', 'OFF offers-live', 'hook offers-test', 'OFF offers-test', 'OFF cold', 'hook chase', 'OFF chase', 'OFF voice-reply', 'OFF voice-staff',
                 'OFF voice-dry-run', 'OFF voice-measure', 'hook voice-measure', 'OFF list', 'OFF tracking', 'OFF ack', 'hook ack', 'OFF sending', 'OFF the'];
        $ctx->eq($tags, $want, 'TL26/TL27/TL20 the order: O before their keys, F (10, 6) with 6\'s hook before its keys, V (9, 8, 7, 5) with 5\'s after, 4, 3, 2 with its hook after, the ledger, the records', $out);
        $ctx->eq([$rc, strpos($out, 'rollback: every 5.18.104 key is unset') !== false], [0, true], 'TL26 the rollback reads clean');
    });

    $ctx->run('TL31 a throwing hook: before its keys it stops the sequence, keys set; after its keys it goes on; exit non-zero; no message printed', function () use ($ctx, $now) {
        $b = sft_book($ctx, sft_on_upto('offers-live', $now));
        $p = $b['pdo'];
        $p->exec("INSERT INTO wa_offers (title, wording_1, wording_2, stop_line, content_hash, state, live_slot, warm_days, recent_days, created_by_id, created_by, started_at)
                  VALUES ('TL31 (test)', 'An offer wording for the test, one.', 'An offer wording for the test, two.', 'Reply STOP', 'h', 'running', 1, 30, 7, 1, 'test', '2026-11-01 08:00:00')");
        $p->exec("CREATE TRIGGER tl31_refuse BEFORE UPDATE ON wa_offers BEGIN SELECT RAISE(ABORT, 'secret-detail-tl31'); END");
        [$rc, $out] = sft_run($ctx, $b, ['off', 'all']);
        $k = sft_kyc($b);
        $ctx->eq([$rc, strpos($out, 'FAIL off_hook offers-live') !== false, $k['offers_live_enabled'] ?? null, $k['quote_tracking_enabled'] ?? null,
                  strpos($out, 'secret-detail-tl31') !== false], [3, true, '1', '1', false],
                 'TL31 Part O\'s hook throws: FAIL off_hook offers-live, its keys and every later step stay set, exit 3, the message not printed', $out);
        $b = sft_book($ctx, sft_on_upto('chase', $now));
        $b['pdo']->exec("CREATE TRIGGER tl31_media BEFORE UPDATE ON wa_media BEGIN SELECT RAISE(ABORT, 'secret-detail-tl31v'); END");
        $b['pdo']->exec("INSERT INTO wa_media (conversation_id, wa_message_id, instance, channel, remote_jid, from_me, kind, status)
                         VALUES (1, 'WA-TL31', 'DishNet-UG', 'support', '256700000031@s.whatsapp.net', 0, 'audio', 'pending')");
        [$rc, $out] = sft_run($ctx, $b, ['off', 'all']);
        $k = sft_kyc($b);
        $ctx->eq([$rc, strpos($out, 'FAIL off_hook voice-measure') !== false, isset($k['ai_media_enabled']), isset($k['quote_tracking_enabled']), isset($k['wa_send_ledger']),
                  strpos($out, 'secret-detail-tl31v') !== false], [3, true, false, false, false, false],
                 'TL31 Part V\'s step-5 hook throws: its keys stay cleared, steps 4, 3, 2 and the ledger cleared after it, exit 3, no message', $out);
    });

    $ctx->run('TL08 TL11 TL09 the refusals: South Sudan, another uid, no book, an out-of-range setting — nothing saved', function () use ($ctx) {
        $b = sft_book($ctx, ['tenant_profile' => 'south-sudan']);
        $before = sft_kyc($b);
        foreach ([['on', 'report', '--ran', '2026-11-01'], ['set', 'daily-cap', '40'], ['skip', 'list', '--reason', 'x (test)'], ['off', 'all']] as $a) {
            [$rc, $out] = sft_run($ctx, $b, $a);
            $ctx->eq([$rc, strpos($out, 'REFUSED not_uganda') !== false], [2, true], 'TL08 South Sudan: ' . implode(' ', $a) . ' refused', $out);
        }
        $ctx->eq(sft_kyc($b), $before, 'TL08 … and nothing was saved');
        $ctx->eq(trim(sft_run($ctx, $b, ['show'])[1]), 'not this install', 'TL08 show prints "not this install" there');
        $b = sft_book($ctx);
        chown($b['data'], 65534);
        [$rc, $out] = sft_run($ctx, $b, ['on', 'report', '--ran', '2026-11-01']);
        $ctx->eq([$rc, strpos($out, 'REFUSED not_owner') !== false, isset(sft_kyc($b)['sf_report_ran_at'])], [2, true, false], 'TL11 another uid owns the data folder: refused', $out);
        chown($b['data'], 0);
        $b = sft_book($ctx);
        unlink($b['data'] . '/plugin.sqlite3');
        foreach ([['on', 'report', '--ran', '2026-11-01'], ['off', 'all'], ['set', 'daily-cap', '40']] as $a) {
            [$rc, $out] = sft_run($ctx, $b, $a);
            $ctx->eq([$rc, strpos($out, 'REFUSED no_book') !== false, is_file($b['data'] . '/plugin.sqlite3')], [2, true, false],
                     'TL04 with no plugin.sqlite3, ' . $a[0] . ' refuses and creates none', $out);
        }
        $b = sft_book($ctx);
        [$rc, $out] = sft_run($ctx, $b, ['set', 'daily-cap', '61']);
        $ctx->eq([$rc, isset(sft_kyc($b)['wa_send_daily_cap'])], [2, false], 'TL09 set daily-cap 61: refused, nothing saved', $out);
        [$rc, $out] = sft_run($ctx, $b, ['set', 'ack-hours', '7', '22']);
        $ctx->eq([$rc, isset(sft_kyc($b)['wa_voice_ack_hour_to'])], [2, false], 'TL28 set ack-hours 7 22: refused, nothing saved', $out);
        [$rc, $out] = sft_run($ctx, $b, ['set', 'window', '9', '18']);
        $ctx->eq([$rc, sft_kyc($b)['wa_send_hour_to'] ?? null], [0, '18'], 'TL28 control: set window 9 18 saved', $out);
    });

    $ctx->run('TL10 TL18 erase: the number from the terminal only, never printed; every phone column', function () use ($ctx, $now) {
        $b = sft_book($ctx);
        $p = $b['pdo'];
        $q = sft_quote($p, '256772500881', 'Q-TL18');
        $q2 = sft_quote($p, '256772500882', 'Q-TL18B');
        $p->exec("INSERT INTO wa_auto_sends (handset, kind, ref, mode, proactive, phone, local_day, gap_s, outcome) VALUES ('i:dishnet-ug', 'reminder', 'n:TL18', 'record', 1, '256772500881', '2026-11-02', 60, 'sent')");
        $p->exec("INSERT INTO wa_seen_marks (direction, phone, channel, instance, kind, wa_message_id) VALUES ('in', '256772500881', 'support', 'DishNet-UG', 'ephemeralMessage', 'WA-TL18M')");
        foreach ([['WA-TL18A', '256772500881@s.whatsapp.net', 999], ['WA-TL18B', 'lid-0001@lid', $q['conv']], ['WA-TL18C', '256772500882@s.whatsapp.net', $q2['conv']]] as [$id, $jid, $conv]) {
            $p->prepare("INSERT INTO wa_media (conversation_id, wa_message_id, instance, channel, remote_jid, from_me, kind, status) VALUES (?, ?, 'DishNet-UG', 'support', ?, 0, 'audio', 'skipped')")
              ->execute([$conv, $id, $jid]);
        }
        $p->exec("INSERT INTO wa_offers (title, wording_1, wording_2, stop_line, content_hash, created_by_id, created_by) VALUES ('An offer (test)', 'A first wording of the offer (test)', 'A second wording of the offer (test)', 'Reply STOP (test)', 'hash-tl18', 1, 'Admin Tester')");
        $oid = (int)$p->lastInsertId();
        foreach (['256772500881', '256772500882'] as $rank => $ph) {
            $p->prepare("INSERT INTO wa_offer_recipients (offer_id, phone, rank, conversation_id, channel, handset, last_in_at) VALUES (?, ?, ?, ?, 'support', 'i:dishnet-ug', '2026-11-01 10:00:00')")
              ->execute([$oid, $ph, $rank + 1, $rank === 0 ? $q['conv'] : $q2['conv']]);
        }
        [$rc, $out] = sft_run($ctx, $b, ['erase', '0772500881'], '');
        $ctx->eq([$rc, (string)$p->query("SELECT phone FROM quote_tracker WHERE quote_key = '{$q['key']}'")->fetchColumn()], [2, '256772500881'],
                 'TL18 a number in argv and none typed: refused, nothing erased', $out);
        [$rc, $out] = sft_run($ctx, $b, ['erase'], "0772500881\n");
        $one = function (string $sql) use ($p) { return $p->query($sql)->fetchColumn(); };
        $ctx->eq([$rc, (string)$one("SELECT phone FROM quote_tracker WHERE quote_key = '{$q['key']}'"), (string)$one("SELECT status FROM quote_tracker WHERE quote_key = '{$q['key']}'"),
                  $one("SELECT phone FROM wa_auto_sends WHERE ref = 'n:TL18'"), (string)$one("SELECT phone FROM wa_seen_marks WHERE wa_message_id = 'WA-TL18M'")],
                 [0, 'erased', 'opted_out', null, ''], 'TL18 typed: the tracker (opted_out), the ledger and the marks erased', $out);
        $ctx->eq([(string)$one("SELECT remote_jid FROM wa_media WHERE wa_message_id = 'WA-TL18A'"), (string)$one("SELECT remote_jid FROM wa_media WHERE wa_message_id = 'WA-TL18B'"),
                  (string)$one("SELECT remote_jid FROM wa_media WHERE wa_message_id = 'WA-TL18C'")], ['', '', '256772500882@s.whatsapp.net'],
                 'TL18 wa_media: the JID form and the conversation form blanked (ruling R16); another phone\'s row unchanged');
        $ctx->eq([$one("SELECT phone FROM wa_offer_recipients WHERE rank = 1"), (string)$one("SELECT phone FROM wa_offer_recipients WHERE rank = 2")], [null, '256772500882'],
                 'TL18 wa_offer_recipients: the phone erased (NULL), another phone\'s recipient unchanged');
        $ctx->t(strpos($out, '772500881') === false && preg_match('/\d{9,}/', $out) === 0, 'TL10 erase prints no number');
    });

    $ctx->run('TL13 TL29 on chase: W2 and the marks table, a typed yes; sf_chase_first_on written once and kept by off chase', function () use ($ctx, $now) {
        $cfg = sft_on_upto('voice-measure', $now);
        $b = sft_book($ctx, $cfg);
        [$rc, $out] = sft_run($ctx, $b, ['on', 'chase', '--accept-not-yet', 'quiet week (test)'], "no\n");
        $ctx->eq([$rc, strpos($out, 'REFUSED') !== false, isset(sft_kyc($b)['quote_chase_enabled'])], [2, true, false], 'TL13 without a typed yes: refused, nothing saved', $out);
        [$rc, $out] = sft_run($ctx, $b, ['on', 'chase', '--accept-not-yet', 'quiet week (test)'], "yes\n");
        $first = (string)(sft_kyc($b)['sf_chase_first_on'] ?? '');
        $ctx->eq([$rc, sft_kyc($b)['quote_chase_enabled'] ?? null, $first !== ''], [0, '1', true], 'TL13 control: with yes, on chase saved, sf_chase_first_on written', $out);
        $ctx->t(strpos($out, 'W2: installed') !== false && strpos($out, 'enquiry follow-ups now share each number') !== false, 'TL13 W2 checked, the interplay printed');
        sft_run($ctx, $b, ['off', 'chase']);
        $ctx->eq([isset(sft_kyc($b)['quote_chase_enabled']), (string)(sft_kyc($b)['sf_chase_first_on'] ?? '')], [false, $first], 'TL29 off chase keeps sf_chase_first_on');
        $tree = $ctx->tree();
        $src = (string)file_get_contents($tree . '/evo_webhook.php');
        $cut = str_replace("WaSeenMarks::note(\$pdo, \$config, \$dataDir, 'in', \$phone,", 'WaSeenMarks::note_x(', $src);
        file_put_contents($tree . '/evo_webhook.php', $cut);
        $b2 = sft_book($ctx, $cfg);
        [$rc, $out] = sft_run($ctx, $b2, ['on', 'chase', '--accept-not-yet', 'quiet week (test)'], "yes\n");
        file_put_contents($tree . '/evo_webhook.php', $src);
        $ctx->eq([$rc, strpos($out, 'REFUSED w2_missing') !== false], [2, true], 'TL13 without W2 installed: refused w2_missing', $out);
    });

    $ctx->run('TL15b TL01b skip through the tool: the cascade recorded, ack keeps the ledger, row 1b and step 1 refused', function () use ($ctx, $now) {
        $b = sft_book($ctx, sft_on_upto('report', $now));
        [$rc, $out] = sft_run($ctx, $b, ['skip', 'ack', '--reason', 'no voice yet (test)']);
        $k = sft_kyc($b);
        $ctx->eq([$rc, $k['wa_send_ledger'] ?? null, isset($k['wa_voice_ack_enabled']), isset($k['sf_skip_ack'])], [0, 'on', false, true],
                 'TL15b skip ack: the ledger comes on without P5', $out);
        $ctx->t(strpos($out, 'the ledger comes on without the voice acknowledgement') !== false, 'TL15b … and says so');
        [$rc, $out] = sft_run($ctx, $b, ['skip', 'tracking', '--reason=not tracking yet (test)']);
        $k = sft_kyc($b);
        $ctx->eq([$rc, isset($k['sf_skip_list'], $k['sf_skip_chase'], $k['sf_skip_cold'], $k['sf_skip_offers-test'], $k['sf_skip_offers-live'])], [0, true],
                 'TL15b skip tracking records 4, 6, 10, 11, 12 skipped', $out);
        foreach ([['agent-cc', 'REFUSED optional'], ['report', 'REFUSED not_skippable']] as [$s, $want]) {
            [$rc, $out] = sft_run($ctx, $b, ['skip', $s, '--reason', 'x (test)']);
            $ctx->eq([$rc, strpos($out, $want) !== false], [2, true], 'TL15b skip ' . $s . ': ' . $want, $out);
        }
        [$rc, $out] = sft_run($ctx, $b, ['skip', 'voice-measure', '--reason', 'call 0772500999 (test)']);
        $ctx->eq([$rc, strpos($out, 'reason_invalid') !== false], [2, true], 'TL10 a --reason with a run of 9 digits is refused', $out);
    });

    $ctx->run('TL36 --check: one line with no step; OFF — not judged for a step that is off; step 2 with each check beneath', function () use ($ctx, $now) {
        $b = sft_book($ctx);
        [$rc, $out] = sft_run($ctx, $b, ['--check']);
        $ctx->eq([$rc, trim($out)], [0, 'OFF — not judged'], 'TL36 nothing on: the note line, exit 0');
        [$rc, $out] = sft_run($ctx, $b, ['--check', 'agent-cc']);
        $ctx->eq(trim($out), 'agent-cc: OFF — not judged', 'TL36 --check agent-cc while off: OFF — not judged (quote_agent_copy F2)');
        $b = sft_book($ctx, sft_on_upto('ack', $now));
        [$rc, $out] = sft_run($ctx, $b, ['--check']);
        $ctx->eq([count(array_filter(explode("\n", trim($out)))), strpos($out, 'ack: ') === 0], [1, true], 'TL36 --check with step 2 the highest on: one line', $out);
        [$rc, $out] = sft_run($ctx, $b, ['--check', 'ack']);
        $ctx->t(strpos($out, '  L-ON: ') !== false && strpos($out, '  VA-ON1: ') !== false, 'TL36 --check ack: both checks beneath the one status', $out);
        [$rc, $out] = sft_run($ctx, $b, ['--check', 'list']);
        $ctx->eq(trim($out), 'list: OFF — not judged', 'TL36 an off step judges nothing');
    });

    $ctx->run('TL32 TL33 the locks: off chase waits for the quote pass, off sending for the offers sender; a lock past the wait is lock_busy', function () use ($ctx, $now) {
        foreach ([['chase', 'quote_send.lock', 'quote_chase_enabled'], ['sending', 'offers_send.lock', 'wa_send_ledger'], ['sending', 'quote_send.lock', 'wa_send_ledger']] as [$what, $file, $key]) {
            $b = sft_book($ctx, sft_on_upto('chase', $now));
            $hold = $b['data'] . '/' . $file;
            $proc = proc_open(['php', '-r', '$f = fopen($argv[1], "c"); flock($f, LOCK_EX); touch($argv[1] . ".held"); usleep((int)($argv[2] * 1000000));', $hold, '3'],
                              [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            for ($i = 0; $i < 100 && !is_file($hold . '.held'); $i++) usleep(50000);
            $t0 = microtime(true);
            [$rc, $out] = sft_run($ctx, $b, ['off', $what]);
            $waited = microtime(true) - $t0;
            proc_close($proc);
            $ctx->eq([$rc, $waited >= 2.0, isset(sft_kyc($b)[$key])], [0, true, false], 'TL3' . ($file === 'quote_send.lock' ? '2' : '3') . ' off ' . $what . ' waits for ' . $file . ' and then clears', $out);
            $b = sft_book($ctx, sft_on_upto('chase', $now));
            $hold = $b['data'] . '/' . $file;
            $proc = proc_open(['php', '-r', '$f = fopen($argv[1], "c"); flock($f, LOCK_EX); touch($argv[1] . ".held"); usleep((int)($argv[2] * 1000000));', $hold, '5'],
                              [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            for ($i = 0; $i < 100 && !is_file($hold . '.held'); $i++) usleep(50000);
            [$rc, $out] = sft_run($ctx, $b, ['off', $what], '', ['DN_SF_LOCK_WAIT_S' => '1']);
            proc_close($proc);
            $ctx->eq([$rc, strpos($out, 'FAIL lock_busy') !== false, isset(sft_kyc($b)[$key])], [3, true, true], 'TL32 a lock held past the wait: FAIL lock_busy, the keys stay set', $out);
        }
    });

    $ctx->run('TL29 Q1-ON\'s scope: with the chase on and chasing its two counts are a note; an earlier deployment\'s rows never count', function () use ($ctx) {
        $b = ck_book($ctx);
        sft_chase($b, '256772500901', 'Q-TL29', qf_t(QF_MONDAY . ' 10:00:00'));
        $b['pdo']->exec("UPDATE followups SET opened_at = '2026-11-02 06:30:00' WHERE kind = 'quote'");   // opened by that run, before its claim
        $J = qf_t('2026-11-03 12:00:00');
        $r = ck_check($ctx, $b, 'Q1-ON', $J);
        $ctx->eq([sft_st($r), $r['counts']['quote_claims'] ?? 0], ['PASS', 1], 'TL29 with sf_chase_first_on set and a chase claimed: Q1-ON PASS, its counts a note', sft_detail($r));
        $c = $b['cfg']; unset($c['sf_chase_first_on']);
        $ctx->eq(sft_st(ck_check($ctx, $b, 'Q1-ON', $J, $c)), 'FAIL chase_before_switch', 'TL29 control: without sf_chase_first_on those counts are judged');
        $c['quote_tracking_since'] = '2026-11-03 00:00:00';
        sft_quote($b['pdo'], '256772500902', 'Q-TL29B', ['sent_at' => '2026-11-03 05:00:00']);
        $r = ck_check($ctx, $b, 'Q1-ON', qf_t('2026-11-04 12:00:00'), $c);
        $ctx->eq(sft_st($r), 'PASS', 'TL29 a fresh on tracking after an earlier deployment\'s chases: their rows do not count', sft_detail($r));
        $L = sft_on_upto('voice-reply', time());
        $ctx->eq(SalesFollowupLadder::admit($L, 'offers-test', null, time(), function (string $id) use ($ctx, $b, $J): array {
            return in_array($id, ['Q1-ON'], true) ? ck_check($ctx, $b, 'Q1-ON', $J) : ['status' => 'PASS', 'counts' => [], 'notes' => []];
        })['ok'], true, 'TL29 with chases running, on offers-test is admitted (Q1-ON\'s need met)');
    });

    $ctx->run('TL10b show never prints the OpenAI key, only set or not set', function () use ($ctx) {
        $b = sft_book($ctx, ['openai_api_key' => 'sk-test-TL10-not-a-real-key']);
        [$rc, $out] = sft_run($ctx, $b, ['show']);
        $ctx->eq([$rc, strpos($out, 'sk-test-TL10') !== false], [0, false], 'TL10 show does not print the key\'s value', $out);
        $ctx->t(strpos($out, 'openai key: set') !== false || strpos($out, 'openai key: not set') !== false, 'TL10 … it prints set or not set');
    });

    $ctx->run('TL34b the tool validates the options before the order and the precondition', function () use ($ctx) {
        $b = sft_book($ctx);
        [$rc, $out] = sft_run($ctx, $b, ['on', 'voice-reply', '--channels', 'nosuch', '--owner-approved=2026-11-01']);
        $ctx->eq([$rc, strpos($out, 'REFUSED option_invalid') !== false], [2, true], 'TL34b on voice-reply with an unknown channel on a fresh book: option_invalid, before the order', $out);
        [$rc, $out] = sft_run($ctx, $b, ['on', 'voice-reply', '--channels', 'all', '--owner-approved=2026-11-01']);
        $ctx->eq([$rc, strpos($out, 'REFUSED order:') !== false], [2, true], 'TL34b control: valid options, then the order refuses', $out);
    });

    $ctx->run('TL14 the one key scan: every config read of 5.18.104\'s new and changed files is declared (§H.2.12.4)', function () use ($ctx) {
        $base = sft_base($ctx);
        $ctx->t($base !== '' && is_file($base . '/lib/MailService.php') && !is_file($base . '/lib/SalesFollowupLadder.php'), 'TL14 control: Git supplies 221c9dc');
        if ($base === '') return;
        $declared = array_merge(SalesFollowupLadder::allKeys(), SalesFollowupLadder::settingKeys(), SalesFollowupLadder::preexistingKeys());
        $reads = sft_key_reads($ctx->tree(), $base);
        $files = array_values(array_unique(array_column($reads, 0)));
        $ctx->t(count($reads) > 50 && in_array('lib/OfferSwitch.php', $files, true) && in_array('lib/QuoteAgentCopy.php', $files, true), 'TL14 control: the scan reads the parts\' files (' . count($reads) . ' reads)');
        // 5.18.104 (root docs/73 §H.2.14 N), stage B10: the files scanned are the merged declared list's, and that list's PHP
        // runtime files are exactly the ones Git sees new or changed — so no changed file escapes the scan undeclared
        $listed = [];
        foreach (array_keys(QF_DECLARED) as $p) if (substr($p, -4) === '.php' && strpos($p, 'tests/') !== 0 && is_file($ctx->root . '/' . $p)) $listed[] = $p;
        sort($listed);
        $ctx->eq(sft_git_php($ctx->root, $base), $listed, 'TL14 control: the merged declared list\'s ' . count($listed) . ' PHP runtime files are the ones Git sees new or changed');
        $bad = [];
        foreach ($reads as [$f, $k]) if (!in_array($k, $declared, true)) $bad[] = $f . ': ' . $k;
        $ctx->eq(array_values(array_unique($bad)), [], 'TL14 every key read is in allKeys(), settingKeys() or preexistingKeys()');
        foreach (['lib/EvolutionOfferGateway.php', 'lib/VoiceDuration.php', 'lib/QuoteAgentCopy.php'] as $plant) {
            $t = $ctx->tree() . '/' . $plant;
            $src = (string)file_get_contents($t);
            file_put_contents($t, $src . "\n// planted (test)\nfunction tl14_planted(array \$config) { return \$config['tl14_planted_key']; }\n");
            $found = array_filter(sft_key_reads($ctx->tree(), $base), function ($r) use ($plant) { return $r[0] === $plant && $r[1] === 'tl14_planted_key'; });
            file_put_contents($t, $src);
            $ctx->eq(count($found), 1, 'TL14 control: a key read planted in ' . $plant . ' is found');
        }
        // 5.18.104 (root docs/73 §H.5.10.1 IP-4), B9-fix1: a read whose key comes from a literal list is found too.
        $t = $ctx->tree() . '/tools/sales_followup.php';
        $src = (string)file_get_contents($t);
        file_put_contents($t, $src . "\n// planted (test)\nfunction tl14_list_planted(array \$config): array { \$o = []; foreach (['tl14_listed_a', 'tl14_listed_b'] as \$k) {\n"
                              . "    \$o[] = array_key_exists(\$k, \$config) ? \$config[\$k] : null; } return \$o; }\n");
        $found = array_filter(sft_key_reads($ctx->tree(), $base), function ($r) { return $r[0] === 'tools/sales_followup.php' && strpos($r[1], 'tl14_listed_') === 0; });
        file_put_contents($t, $src);
        $ctx->eq(count($found), 2, 'TL14 control: a key read from a literal list planted in tools/sales_followup.php is found, each key of the list');
    });

    // 5.18.104 (root docs/73 §H.2.12.4, §H.2.14 TL32, TL33; B11-02), B9-fix1: the lock row through the real senders
    sft_tl_locks($ctx);

    $ctx->run('TL41 on agent-cc prints the quotation paths through CustomerEmailDispatcher (master with quotation, cc), path 1 from email_settings.json (§H.5.10.3)', function () use ($ctx, $now) {
        $b = sft_book($ctx, ['sf_report_ran_at' => '2026-11-01', 'customer_emails_enabled' => '0', 'customer_email_quotation' => '1', 'email_cc_contacts' => '1']);
        file_put_contents($b['data'] . '/email_settings.json', json_encode(['smtp_host' => '127.0.0.1', 'smtp_from' => 'quotes@agents.example.test']));
        [$rc, $out] = sft_run($ctx, $b, ['on', 'agent-cc']);
        $ctx->eq([$rc, strpos($out, '  quote_email_via_plugin (email_settings.json): off') !== false, strpos($out, "  the webhook's quotation e-mail: off (customer e-mails off)") !== false,
                  strpos($out, '  email_cc_contacts: on') !== false, strpos($out, 'no plugin quotation e-mail is on') !== false],
                 [0, true, true, true, true], 'TL41 the master off with the quotation on: path 3 reads off, as the dispatcher reads it; both paths off said', $out);
        sft_run($ctx, $b, ['off', 'agent-cc']);
        sft_files($b, ['customer_emails_enabled' => '1'] + sft_kyc($b));
        file_put_contents($b['data'] . '/email_settings.json', json_encode(['smtp_host' => '127.0.0.1', 'smtp_from' => 'quotes@agents.example.test', 'quote_email_via_plugin' => true]));
        [$rc, $out] = sft_run($ctx, $b, ['on', 'agent-cc']);
        $ctx->eq([$rc, strpos($out, '  quote_email_via_plugin (email_settings.json): on') !== false, strpos($out, "  the webhook's quotation e-mail: on (customer e-mails on)") !== false,
                  strpos($out, 'no plugin quotation e-mail is on') !== false],
                 [0, true, true, false], 'TL41 control: both on, read where each lives; no "nothing to ride on" line', $out);
    });

    // ── B9-fix1 (root docs/73 §H.4.6, §H.2.12.1, §H.2.5.7; ruling R13) ───────────────────────────────────────────
    $ctx->run('TL37 on offers-test through the tool: the baseline preview at size all, by handset (role and index) and by reason; $ro unchanged, no store opened', function () use ($ctx, $now) {
        $b = sft_book($ctx, sft_on_upto('voice-reply', $now) + ['evo_api_url' => 'http://127.0.0.1:9', 'evo_api_key' => 'fake-key-tl37']);
        sft_admins($b);
        foreach ([['0772503701', 'sales', 3600], ['0772503702', 'sales', 7200], ['0772503703', 'support', 5400],      // eligible: 2 on sales, 1 on support
                  ['0772503704', 'sales', 3600], ['0700990107', 'sales', 3600], ['0211912503706', 'sales', 3600]] as [$ph, $ch, $ago]) {
            sft_warm($ctx, $b, $ph, $ch, $ago);
        }
        $b['pdo']->prepare("INSERT INTO contact_optouts (phone, reason, source, created_at) VALUES ('256772503704', 'customer_request', 'keyword', ?)")
                 ->execute([UtcClock::stamp(time() - 600)]);
        // First with the save made to fail (kyc_config.json a folder, the configuration in <data>/config.json): saveOverrides
        // is the one writer that opens the store, so a probe still absent after the refusal says nothing before it did.
        rename($b['data'] . '/kyc_config.json', $b['data'] . '/config.json');
        mkdir($b['data'] . '/kyc_config.json');
        touch($b['data'] . '/kyc_config.json/keep');
        sft_store_probe($ctx, true);
        try {
            [$rc, $out] = sft_run($ctx, $b, ['on', 'offers-test', '--accept-not-yet', 'a quiet first week (test)']);
        } finally {
            sft_store_probe($ctx, false);
            unlink($b['data'] . '/kyc_config.json/keep');
            rmdir($b['data'] . '/kyc_config.json');
            rename($b['data'] . '/config.json', $b['data'] . '/kyc_config.json');
        }
        $lines = function (string $out): array {
            return array_values(array_filter(array_map('rtrim', explode("\n", $out)), function ($l) {
                return preg_match('/^(baseline audience|  handset |  left out |  waiting )/', $l);
            }));
        };
        $want = ['baseline audience (size all): 3 eligible', '  handset h1 (sales): 2', '  handset h2 (support, account): 1',
                 '  left out colleague: 1', '  left out not_uganda_number: 1', '  left out opted_out: 1'];
        $ctx->eq([$rc, strpos($out, 'REFUSED save_failed') !== false], [2, true], 'TL37 control: the save made to fail, after the preview', $out);
        $ctx->eq($lines($out), $want, 'TL37 the preview: OfferAudience::build()\'s total, its by_handset counts by role and index, its left_out counts by reason', $out);
        $ctx->eq([strpos($out, 'ro_changed') === false, sft_store_opened($b)], [true, false],
                 'TL37 $ro unchanged at the end (total_changes() 0) and no store opened before the save (the preview, the checks, the preconditions)');
        $ctx->t(preg_match('/\d{9,}/', $out) === 0 && stripos($out, 'sales-ug') === false && stripos($out, 'dishnet-ug') === false,
                'TL37 the preview prints no number and no instance name', $out);
        [$rc, $out] = sft_run($ctx, $b, ['on', 'offers-test', '--accept-not-yet', 'a quiet first week (test)']);
        $ctx->eq([$rc, sft_kyc($b)['offers_test_enabled'] ?? null, $lines($out)], [0, '1', $want], 'TL37 the same on, saved: the same preview', $out);
        if (!class_exists('OfferAudience')) { foreach (['OfferGateway', 'EvolutionOfferGateway', 'OfferText', 'OfferAudience'] as $c) require_once $ctx->tree() . '/lib/' . $c . '.php'; }
        $ro = sft_ro($b);
        $rows = OfferAudience::storedRows($ro);
        $a = OfferAudience::build($ro, sft_read($ctx, $b), $b['data'], $rows['retailers'], $rows['kyc_config'], 'all', time());
        $ctx->eq([count($a['eligible']), array_sum($a['left_out'])], [3, 3], 'TL37 control: the same audience built in process has 3 eligible and 3 left out');
    });

    $ctx->run('TL38 --check offers-live through the tool: checkLive given $ro, the configuration, the data folder and the time (ruling R13); $ro unchanged, no store opened', function () use ($ctx, $now) {
        $b = sft_book($ctx, sft_on_upto('offers-live', $now) + ['evo_api_url' => 'http://127.0.0.1:9', 'evo_api_key' => 'fake-key-tl38']);
        sft_admins($b);
        sft_store_probe($ctx, true);
        try {
            [$rc, $out] = sft_run($ctx, $b, ['--check', 'offers-live']);
        } finally {
            sft_store_probe($ctx, false);
        }
        $ro = sft_ro($b);
        $want = OfferSwitch::checkLive($ro, sft_read($ctx, $b) + ['_data_dir' => $b['data']], $b['data'], time());
        $ctx->eq(preg_match('/^offers-live: (PASS|FAIL [a-z_]+|NOT_YET (too_soon|no_traffic|no_test))$/m', $out), 1, 'TL38 one status line in the one vocabulary', $out);
        $ctx->eq([strpos($out, "\n  offers-live: " . $want['status']) !== false, strpos($out, 'check_threw') === false, strpos($out, 'unknown_check') === false],
                 [true, true, true], 'TL38 the status is OfferSwitch::checkLive()\'s own, read in process on the same book (' . $want['status'] . ')', $out);
        $ctx->eq([strpos($out, 'ro_changed') === false, sft_store_opened($b), sft_changes($ro)], [true, false, 0], 'TL38 $ro unchanged and no store opened for the check');
    });

    $ctx->run('TL39 no staff id in the output: owners in --check list by index, list-staff in show and set as a count (§H.2.12.1, §H.2.8.2)', function () use ($ctx) {
        $b = ck_book($ctx);
        $p = $b['pdo'];
        $p->exec("INSERT OR REPLACE INTO quote_followup_state (k, v) VALUES ('list_swept', '2026-11-02')");
        ck_staff($b, 4817, 'Sellera Test', ['phone' => '0700994817']);
        ck_staff($b, 4818, 'Sellerb Test', ['phone' => '0700994818']);
        $k1 = sft_quote($p, '256772503901', 'Q-TL39A', ['owner' => 4817, 'owner_source' => 'kyc_sales_person', 'created_at' => '2026-11-01 08:00:00'])['key'];
        $T = qf_t(QF_MONDAY . ' 08:05:00');
        $L = sft_ledger($b, $T);
        $c = $L->claim('Sales-UG', 'staff_list', 'list:2026-11-02:staff:4817');
        $L->settle((int)($c['claim'] ?? 0), 'sent', ['wa_message_id' => 'WA-LIST-4817']);
        ck_list($p, '2026-11-02', 'staff:4817', 'sent', ['due' => [$k1]], UtcClock::stamp($T));
        ck_list($p, '2026-11-02', 'staff:4818', 'not_sent', ['due' => []], UtcClock::stamp($T), 'send_failed');
        ck_list($p, '2026-11-02', 'staff:4818', 'missed', [], UtcClock::stamp(qf_t(QF_MONDAY . ' 11:00:00')), 'not_sent');
        [$rc, $out] = sft_run($ctx, $b, ['--check', 'list']);
        $ctx->eq([$rc, strpos($out, 'list: FAIL missed') === 0, strpos($out, 'missed 2026-11-02 owner ') !== false, strpos($out, 'open now owner ') !== false],
                 [3, true, true, true], 'TL39 control: --check list judges the day and names both owners', $out);
        $ctx->t(preg_match('/#\d/', $out) === 0 && strpos($out, '4817') === false && strpos($out, '4818') === false,
                'TL39 --check list names the owners owner 1 and owner 2, never by a staff id', $out);
        $ctx->eq(preg_match_all('/owner [12]\b/', $out) >= 2, true, 'TL39 … each owner by its index within the run', $out);
        [$rc, $out] = sft_run($ctx, $b, ['set', 'list-staff', '4817,4818']);
        $ctx->eq([$rc, sft_kyc($b)['quote_daily_list_staff'] ?? null, strpos($out, 'SET quote_daily_list_staff = set: 2 accounts') !== false], [0, '4817,4818', true],
                 'TL39 set list-staff saves the ids and prints their count', $out);
        $ctx->t(strpos($out, '4817') === false && strpos($out, '4818') === false, 'TL39 set prints no staff id', $out);
        [$rc, $out] = sft_run($ctx, $b, ['show']);
        $ctx->eq([$rc, strpos($out, 'quote_daily_list_staff = set: 2 accounts') !== false, strpos($out, '4817') === false, strpos($out, '4818') === false],
                 [0, true, true, true], 'TL39 show prints quote_daily_list_staff as a count, no staff id', $out);
    });
}];
