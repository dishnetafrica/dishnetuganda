<?php
declare(strict_types=1);
/**
 * Section A of tests/test_voice_ack.php — P5's gates and its first steps (5.18.104, root docs/73 §H.3.15 steps 1–6, 12,
 * 14–16; A1–A5, A12, A13, A15, A16, A20–A22, A24, A34). TEST ONLY. The reply worker runs as run_worker.php runs it, in
 * process, at Thursday 12:00 Kampala; every send goes to the fake Evolution.
 */
$VA_SECTIONS['A'] = ['title' => 'P5: Uganda, its switch, R4\'s silence, our own and our colleagues\' numbers, the text, the persona, the hand-over', 'run' => function (QfCtx $ctx): void {
    va_load($ctx);
    $evo = qf_fake_evo();
    $ctx->t($evo !== '', 'A00 the fake Evolution started');
    if ($evo === '') return;
    $A = "Thanks for your voice note. We can't listen to voice notes automatically yet \u{2014} could you type your question in a short message? A colleague will also listen and reply.";
    $B = 'We got your voice note, thank you. To help you faster, please type your question here. A member of our team will also listen to it.';
    $fresh = function (array $over = []) use ($ctx, $evo): array {
        qf_http('GET', $evo . '/__test/reset');
        return vm_book($ctx, va_cfg($evo, $over));
    };

    $ctx->run('A01 control: a voice note on Uganda is acknowledged once, recorded, claimed, stored and handed to a person (A22, A24, A15)', function () use ($ctx, $evo, $fresh, $A) {
        $b = $fresh();
        $n = va_note($b, '256771400101');
        $r = va_run($b);
        $d = va_dec($b, $n['wa']);
        $ctx->eq([$d['outcome'] ?? null, $d['variant'] ?? null, (int)($d['persona'] ?? -1), $d['handset'] ?? null, $d['ledger_ref'] ?? null,
                  (int)($d['period_hours'] ?? 0), (int)($d['gap_s'] ?? 0), $d['local_day'] ?? null, $d['sent_at'] !== null],
                 ['sent', 'A', 0, 'i:sales-ug', 'ack:' . $n['wa'], 24, 20, '2026-11-05', true], 'A01 the decision: sent, variant A, no persona, the limits it applied');
        $l = va_ledger($b, $n['wa']);
        $ctx->eq(array_map(function ($x) { return [$x['handset'], $x['mode'], $x['outcome'], $x['wa_message_id'], $x['claimed_at'], (int)$x['win_from'], (int)$x['win_to'], (int)$x['cap_day'], $x['cap_hour'], (int)$x['proactive']]; }, $l),
                 [['i:sales-ug', 'claim', 'sent', 'FAKE-EVO-MSG-1', '2026-11-05 09:00:00', 7, 21, 25, null, 0]],
                 'A01 one ledger claim, kind voice_ack, settled sent with the sent id, the window and ceiling it applied');
        $ctx->eq(va_sent_to($evo, '256771400101'), [['Sales-UG', '256771400101', $A]], 'A01 the customer receives text A, byte for byte root docs/72 §H.1, once (A24)');
        $row = vm_q($b, "SELECT role, agent_name, wa_message_id, metadata FROM wa_messages WHERE direction = 'out' AND conversation_id = ?", [$n['conv']]);
        $md = json_decode((string)json_decode((string)($row[0]['metadata'] ?? '""')), true);
        $ctx->eq([count($row), $row[0]['role'] ?? null, $row[0]['agent_name'] ?? null, $row[0]['wa_message_id'] ?? null, $md],
                 [1, 'assistant', 'DishNet AI', 'FAKE-EVO-MSG-1', ['channel' => 'sales', 'voice_ack' => 'A', 'source' => 'voice_ack']],
                 'A01 stored as the assistant\'s, DishNet AI, with its id and its source (A15)');
        $esc = vm_q($b, "SELECT payload, created_by FROM events WHERE event_type = 'wa.escalation'");
        $ctx->eq([array_map(function ($e) { return [json_decode($e['payload'], true)['reason'] ?? '', $e['created_by']]; }, $esc),
                  vm_q($b, 'SELECT state FROM wa_conversations WHERE id = ?', [$n['conv']])[0]['state'] ?? '',
                  count(va_sent_to($evo, '256700000999'))],
                 [[['a voice note is waiting: the customer was asked to type; listen to it in WhatsApp', 'voice_ack']], 'needs_human', 1],
                 'A01 a person is told at once: one wa.escalation by voice_ack, needs_human, one alert to the central number, no holding line (A22)');
        $ctx->eq(vm_q($b, "SELECT status FROM events WHERE id = ?", [$n['event']])[0]['status'] ?? '', 'done', 'A01 the event is done');
        $m = new \ReflectionMethod(AiReplyWorker::class, 'getEventTypes'); $m->setAccessible(true);
        $ctx->eq($m->invoke($r['worker']), ['ai.reply', 'ai.voice_ack'], 'A01 on Uganda the reply worker consumes ai.voice_ack too');
        $ctx->t(strpos($r['log'], '256771400101') === false && strpos($r['log'], 'Thanks for your voice note') === false,
                'A01 the log names no phone and no text', $r['log']);
    });

    $ctx->run('A02 South Sudan: the worker never takes the event, nothing is decided or sent (A1)', function () use ($ctx, $evo, $fresh) {
        $b = $fresh(['tenant_profile' => 'south-sudan', 'timezone' => implode('/', ['Africa', 'Juba'])]);   // named in parts: tests/test_timezone.php scans this file
        StaffJobsGate::reset();
        $n = va_note($b, '211921400102');
        $r = va_run($b);
        $m = new \ReflectionMethod(AiReplyWorker::class, 'getEventTypes'); $m->setAccessible(true);
        $ctx->eq([$m->invoke($r['worker']), VoiceAck::enabled($b['cfg'], $b['data']), vm_q($b, 'SELECT status FROM events WHERE id = ?', [$n['event']])[0]['status'] ?? '',
                  va_dec($b, $n['wa']), vm_texts($evo)], [['ai.reply'], false, 'pending', [], []],
                 'A02 the 5.18.103 list, P5 off, the event untouched, no decision, no send');
        StaffJobsGate::reset();
    });

    $ctx->run('A03 the switch and the assistant: off is switched_off and nothing is sent (A2, A3)', function () use ($ctx, $evo, $fresh) {
        foreach (['wa_voice_ack_enabled' => null, 'ai_enabled' => false] as $k => $v) {
            $b = $fresh([$k => $v]);
            $n = va_note($b, '256771400103');
            va_run($b);
            $ctx->eq([va_outcome($b, $n['wa']), count(va_ledger($b, $n['wa'])), vm_texts($evo)], ['skipped:switched_off', 0, []],
                     "A03 {$k} off: skipped switched_off, no claim, no send");
        }
    });

    $ctx->run('A04 R4 answering this number silences P5 there (A4); R4 on another number leaves it alone (A5)', function () use ($ctx, $evo, $fresh, $A) {
        $b = $fresh(vm_rung('reply', 'sales'));
        $n = va_note($b, '256771400104');
        va_run($b);
        $ctx->eq([va_outcome($b, $n['wa']), vm_texts($evo)], ['skipped:p4_answers', []], 'A04 R4 on sales: p4_answers, nothing sent');
        $b = $fresh(vm_rung('reply', 'support'));
        $n = va_note($b, '256771400114');
        va_run($b);
        $ctx->eq([va_outcome($b, $n['wa']), va_sent_to($evo, '256771400114')[0][2] ?? null], ['sent', $A], 'A04 control: R4 on support only, a sales note is acknowledged (A5)');
    });

    $ctx->run('A05 a colleague\'s number and DishNet\'s own: never acknowledged (A13)', function () use ($ctx, $evo, $fresh) {
        $b = $fresh();
        $b['store']->save('retailers.json', [['id' => 7, 'name' => 'Vee Seller', 'phone' => '0771400105', 'role' => 'support', 'is_active' => true]]);
        $n = va_note($b, '256771400105');
        va_run($b);
        $ctx->eq([va_outcome($b, $n['wa']), vm_texts($evo)], ['skipped:staff_number', []], 'A05 a colleague\'s number: staff_number, nothing sent');
        $b = $fresh();
        $n = va_note($b, '256700000100');
        $rows = ['sales' => ['role' => 'sales', 'owner_type' => 'department', 'business_number' => '256700000100', 'status' => 'active']];
        va_run($b, null, ['before' => function ($w) use ($b, $rows) {
            $e = new VaEvo($b['cfg']); $e->rows = $rows; $e->usePolicy($rows, $b['cfg']); va_use_evo($w, $e);
        }]);
        $ctx->eq([va_outcome($b, $n['wa']), vm_texts($evo)], ['skipped:dishnet_number', []], 'A05 the sales department\'s own number: dishnet_number, nothing sent');
        $b = $fresh();
        $n = va_note($b, '256771400115');
        va_run($b, null, ['before' => function ($w) use ($b, $rows) {
            $e = new VaEvo($b['cfg']); $e->rows = $rows; $e->usePolicy($rows, $b['cfg']); va_use_evo($w, $e);
        }]);
        $ctx->eq(va_outcome($b, $n['wa']), 'sent', 'A05 control: a customer on that registry is acknowledged');
    });

    $ctx->run('A06 the registry\'s route and the automated-send policy refuse, recorded by reason (A12)', function () use ($ctx, $evo, $fresh) {
        $ok = ['sales' => ['role' => 'sales', 'owner_type' => 'department', 'business_number' => '256700000100', 'status' => 'active']];
        $go = function (array $policyRows, ?array $route) use ($fresh, $ok): array {
            $b = $fresh();
            $n = va_note($b, '256771400106');
            va_run($b, null, ['before' => function ($w) use ($b, $ok, $policyRows, $route) {
                $e = new VaEvo($b['cfg']); $e->rows = $ok; $e->route = $route; $e->usePolicy($policyRows, $b['cfg']); va_use_evo($w, $e);
            }]);
            return [$b, $n];
        };
        [$b, $n] = $go($ok, ['ok' => false, 'reason' => 'instance_mismatch', 'context' => null]);
        $ctx->eq([va_outcome($b, $n['wa']), vm_texts($evo)], ['skipped:route:instance_mismatch', []], 'A06 the route refused: route:instance_mismatch, nothing sent');
        [$b, $n] = $go(['sales' => ['status' => 'paused'] + $ok['sales']], null);
        $ctx->eq([va_outcome($b, $n['wa']), vm_texts($evo)], ['skipped:policy:channel_paused', []], 'A06 the policy refused: policy:channel_paused, nothing sent');
    });

    $ctx->run('A07 stale: past 600 s since received_at nothing is sent; inside it, it is (A16)', function () use ($ctx, $evo, $fresh) {
        $b = $fresh();
        $n = va_note($b, '256771400107', va_noon() - 601);
        va_run($b);
        $m = va_note($b, '256771400117', va_noon() - 590);
        va_run($b);
        $ctx->eq([va_outcome($b, $n['wa']), va_outcome($b, $m['wa']), count(va_sent_to($evo, '256771400107')), count(va_sent_to($evo, '256771400117'))],
                 ['skipped:stale', 'sent', 0, 1], 'A07 601 s: stale, not sent; 590 s: sent');
    });

    $ctx->run('A08 the variants alternate on a handset, each byte for byte (A20, A24)', function () use ($ctx, $evo, $fresh, $A, $B) {
        $b = $fresh();
        $got = [];
        foreach (['256771400108', '256771400118', '256771400128'] as $i => $p) {
            $n = va_note($b, $p, va_noon() + 60 * $i);
            va_run($b, va_noon() + 60 * $i);
            $got[] = [va_dec($b, $n['wa'])['variant'] ?? null, va_sent_to($evo, $p)[0][2] ?? null];
        }
        $ctx->eq($got, [['A', $A], ['B', $B], ['A', $A]], 'A08 A, then B, then A — the text each names');
    });

    $ctx->run('A09 the persona on a salesperson\'s own number: held until sf_persona_confirmed, then prefixed (A21)', function () use ($ctx, $evo, $fresh, $A) {
        $ownerRows = ['sales' => ['role' => 'sales', 'owner_type' => 'staff', 'owner_staff_id' => 7, 'status' => 'active', 'handover_to' => 'owner']];
        $go = function (array $over, string $name, string $phone) use ($fresh, $ownerRows): array {
            $b = $fresh($over);
            $b['store']->save('retailers.json', [['id' => 7, 'name' => $name, 'phone' => '0700000777', 'role' => 'sales', 'is_active' => true]]);
            $n = va_note($b, $phone);
            va_run($b, null, ['before' => function ($w) use ($b, $ownerRows) { $e = new VaEvo($b['cfg']); $e->rows = $ownerRows; va_use_evo($w, $e); }]);
            return [$b, $n];
        };
        [$b, $n] = $go([], 'Vee Seller', '256771400109');
        $ctx->eq([va_outcome($b, $n['wa']), count(va_sent_to($evo, '256771400109'))], ['skipped:persona_unconfirmed', 0], 'A09 no date: persona_unconfirmed, nothing sent');
        [$b, $n] = $go(['sf_persona_confirmed' => '2026-11-07'], 'Vee Seller', '256771400119');
        $ctx->eq(va_outcome($b, $n['wa']), 'skipped:persona_unconfirmed', 'A09 a date after today (Kampala): still held');
        [$b, $n] = $go(['sf_persona_confirmed' => '2026-11-04'], 'Vee Seller', '256771400129');
        $ctx->eq([va_outcome($b, $n['wa']), (int)(va_dec($b, $n['wa'])['persona'] ?? -1), va_sent_to($evo, '256771400129')[0][2] ?? null],
                 ['sent', 1, "Hello, this is Vee's assistant at DishNet. " . $A], 'A09 confirmed: the persona sentence, one space, then A; flag 1');
        $ctx->eq(count(va_sent_to($evo, '256700000777')), 1, 'A09 the line\'s owner is alerted too (handover_to owner)');
    });

    $ctx->run('A10 an owner with no usable first name: held before the date, then A or B unprefixed (A34)', function () use ($ctx, $evo, $fresh, $A) {
        $ownerRows = ['sales' => ['role' => 'sales', 'owner_type' => 'staff', 'owner_staff_id' => 7, 'status' => 'active']];
        $go = function (array $over, string $phone) use ($fresh, $ownerRows): array {
            $b = $fresh($over);
            $b['store']->save('retailers.json', [['id' => 7, 'name' => '7 7', 'phone' => '0700000777', 'role' => 'sales', 'is_active' => true]]);
            $n = va_note($b, $phone);
            va_run($b, null, ['before' => function ($w) use ($b, $ownerRows) { $e = new VaEvo($b['cfg']); $e->rows = $ownerRows; va_use_evo($w, $e); }]);
            return [$b, $n];
        };
        [$b, $n] = $go([], '256771400110');
        $ctx->eq([va_outcome($b, $n['wa']), count(va_sent_to($evo, '256771400110'))], ['skipped:persona_unconfirmed', 0], 'A10 no date: persona_unconfirmed, nothing sent');
        [$b, $n] = $go(['sf_persona_confirmed' => '2026-11-04'], '256771400120');
        $ctx->eq([va_outcome($b, $n['wa']), (int)(va_dec($b, $n['wa'])['persona'] ?? -1), va_sent_to($evo, '256771400120')[0][2] ?? null],
                 ['sent', 0, $A], 'A10 confirmed: A, unprefixed, byte for byte §H.1, persona flag 0');
    });

    $ctx->run('A11 a payload field missing: dropped, no row, nothing sent (step 1)', function () use ($ctx, $evo, $fresh) {
        $b = $fresh();
        $conv = (int)(new ConversationService($b['data'], $b['pdo']))->ensureConversation('256771400111', 'sales')['id'];
        (new EventBus($b['pdo']))->emit('ai.voice_ack', 'conversation', $conv, ['channel' => 'sales', 'customer_phone' => '256771400111',
            'received_at' => gmdate('c', va_noon())], 3, 'evo_webhook');
        va_run($b);
        $ctx->eq([(int)vm_q($b, 'SELECT COUNT(*) n FROM wa_voice_acks')[0]['n'], vm_texts($evo), vm_q($b, "SELECT status FROM events WHERE event_type = 'ai.voice_ack'")[0]['status'] ?? ''],
                 [0, [], 'done'], 'A11 no wa_message_id: no decision, no send, the event done');
    });
}];
