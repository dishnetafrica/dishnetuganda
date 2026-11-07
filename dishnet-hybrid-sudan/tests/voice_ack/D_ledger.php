<?php
declare(strict_types=1);
/**
 * Section D of tests/test_voice_ack.php — P5 in the shared ledger and at the send (5.18.104, root docs/73 §H.3.15
 * steps 13–15, §H.3.16, §H.3.14; rulings R2, R18; A14, A17, A23, A25, A27–A29; F15). TEST ONLY. Every answer of the
 * ledger is mapped by code and an answer not in the table sends nothing; a defer writes no decision; an uncertain send
 * is never sent again; the echo is claimed before the settle and the store; a reply's class means a proactive STOP does
 * not block it and an `all` one does.
 */
$VA_SECTIONS['D'] = ['title' => 'P5 in the ledger and at the send: the spacing it keeps, defers, unknown answers, uncertain sends, the echo, the opt-outs', 'run' => function (QfCtx $ctx): void {
    va_load($ctx);
    $evo = qf_fake_evo();
    $ctx->t($evo !== '', 'D00 the fake Evolution started');
    if ($evo === '') return;
    $fresh = function (array $over = []) use ($ctx, $evo): array {
        qf_http('GET', $evo . '/__test/reset');
        qf_http('GET', $evo . '/__test/fail_instance?clear=1');
        return vm_book($ctx, va_cfg($evo, $over));
    };
    $ledgerAt = function (array $b, int $t): WaSendBudget {
        return WaSendBudget::forInstall($b['cfg'], $b['data'], $b['store'], null, function () use ($t): int { return $t; });
    };
    $state = function () use ($evo): array { return vm_evo_state($evo); };

    $ctx->run('D01 an acknowledgement keeps a proactive send\'s spacing: a list claimed 30 s after it waits (A17; R-03)', function () use ($ctx, $fresh, $ledgerAt) {
        $b = $fresh(['alert_whatsapp' => null]);   // no alert: its record row would keep the same spacing (§H.2.6.2)
        $n = va_note($b, '256771400401');
        va_run($b);
        $c = $ledgerAt($b, va_noon() + 30)->claim('Sales-UG', 'staff_list', 'list:VA-D01:1');
        $ctx->eq([va_outcome($b, $n['wa']), $c['ok'], $c['defer'] ?? null, $c['retry_at'] ?? null], ['sent', false, 'spacing', va_noon() + 60],
                 'D01 the acknowledgement claimed its handset: the list 30 s later is deferred spacing until its gap after it');
    });

    $ctx->run('D02 a claim in flight on the handset, or on the note\'s own ref, defers — never a final skip — then the note goes (A27)', function () use ($ctx, $evo, $fresh, $ledgerAt) {
        $b = $fresh();
        $l = $ledgerAt($b, va_noon() - 10);
        $busy = $l->claim('Sales-UG', 'staff_list', 'list:VA-D02:1');
        $n = va_note($b, '256771400402');
        va_run($b);
        $ev = vm_q($b, 'SELECT status, error FROM events WHERE id = ?', [$n['event']])[0] ?? [];
        $ctx->eq([!empty($busy['ok']), va_outcome($b, $n['wa']), $ev['status'] ?? null, $ev['error'] ?? null, count(va_sent_to($evo, '256771400402'))],
                 [true, 'none', 'pending', 'voice_ack: handset_busy', 0], 'D02 a list in flight: deferred handset_busy, no decision, nothing sent');
        $l->settle((int)$busy['claim'], 'sent', ['wa_message_id' => 'VA-LIST-D02']);
        va_run($b, va_noon() + 40);
        $ctx->eq([va_outcome($b, $n['wa']), count(va_sent_to($evo, '256771400402'))], ['sent', 1], 'D02 once it settled: sent');

        $b = $fresh();
        $n = va_note($b, '256771400412');
        $own = $ledgerAt($b, va_noon() - 5)->claim('Sales-UG', 'voice_ack', 'ack:' . $n['wa']);
        va_run($b);
        $ev = vm_q($b, 'SELECT status, error FROM events WHERE id = ?', [$n['event']])[0] ?? [];
        $ctx->eq([!empty($own['ok']), va_outcome($b, $n['wa']), $ev['status'] ?? null, $ev['error'] ?? null, count(va_sent_to($evo, '256771400412'))],
                 [true, 'none', 'pending', 'voice_ack: claim in flight', 0], 'D02 its own ref in flight: deferred in_flight, no decision, nothing sent');
        va_run($b, va_noon() + 601);
        $ctx->eq([va_outcome($b, $n['wa']), count(va_sent_to($evo, '256771400412'))], ['skipped:stale', 0], 'D02 past 600 s: stale, never sent');
    });

    $ctx->run('D03 an answer not in the table sends nothing: tries_exhausted is ledger_unknown (A25)', function () use ($ctx, $evo, $fresh, $ledgerAt) {
        $b = $fresh();
        $n = va_note($b, '256771400403');
        foreach ([300, 200, 100] as $ago) {
            $l = $ledgerAt($b, va_noon() - $ago);
            $c = $l->claim('Sales-UG', 'voice_ack', 'ack:' . $n['wa']);
            if (!empty($c['ok'])) $l->settle((int)$c['claim'], 'failed', ['maybe_sent' => false]);
        }
        $r = va_run($b);
        $ctx->eq([count(va_ledger($b, $n['wa'])), va_outcome($b, $n['wa']), count(va_sent_to($evo, '256771400403'))], [3, 'skipped:ledger_unknown', 0],
                 'D03 three not_sent tries planted: the claim answers tries_exhausted; ledger_unknown, nothing sent');
        $ctx->t(strpos($r['log'], 'the ledger answered tries_exhausted') !== false, 'D03 the code is in the log', $r['log']);
    });

    $ctx->run('D04 a send that may have gone: uncertain, counted, a person told, and never sent again (A23, A29)', function () use ($ctx, $evo, $fresh) {
        $b = $fresh();
        qf_http('GET', $evo . '/__test/fail_instance?name=Sales-UG&code=504');
        $n = va_note($b, '256771400404');
        va_run($b);
        $l = va_ledger($b, $n['wa']);
        $tries = count(array_filter((array)($state = vm_evo_state($evo))['failed_calls'] ?? [], function ($c) { return $c['number'] === '256771400404'; }));
        $ctx->eq([va_outcome($b, $n['wa']), $l[0]['outcome'] ?? null, (int)vm_q($b, "SELECT COUNT(*) n FROM events WHERE event_type = 'wa.escalation' AND created_by = 'voice_ack'")[0]['n'], $tries],
                 ['uncertain', 'uncertain', 1, 1], 'D04 a 504: decision uncertain, ledger uncertain, a person told, one attempt');
        qf_http('GET', $evo . '/__test/fail_instance?clear=1');
        $b['pdo']->exec("UPDATE wa_conversations SET state = 'open'");      // the hand-over answered: C7 reads no person since
        (new EventBus($b['pdo']))->emit('ai.voice_ack', 'conversation', $n['conv'], ['channel' => 'sales', 'whatsapp_instance' => 'Sales-UG',
            'customer_phone' => '256771400404', 'wa_message_id' => $n['wa'], 'received_at' => gmdate('c', va_noon())], 3, 'evo_webhook');
        $b['pdo']->prepare("UPDATE events SET status = 'pending' WHERE id = ?")->execute([$n['event']]);
        va_run($b, va_noon() + 60);
        $tries2 = count(array_filter((array)(vm_evo_state($evo)['failed_calls'] ?? []), function ($c) { return $c['number'] === '256771400404'; }));
        $ctx->eq([count(va_sent_to($evo, '256771400404')), $tries2, va_outcome($b, $n['wa']), count(va_ledger($b, $n['wa']))], [0, 1, 'uncertain', 1],
                 'D04 the event again and a duplicate: nothing sent, the decision and the ledger as they were');
    });

    $ctx->run('D05 a send that certainly failed: failed, not_sent, nobody told, not sent again', function () use ($ctx, $evo, $fresh) {
        $b = $fresh();
        qf_http('GET', $evo . '/__test/fail_instance?name=Sales-UG&code=400');
        $n = va_note($b, '256771400405');
        va_run($b);
        $ctx->eq([va_outcome($b, $n['wa']), va_ledger($b, $n['wa'])[0]['outcome'] ?? null,
                  (int)vm_q($b, "SELECT COUNT(*) n FROM events WHERE event_type = 'wa.escalation'")[0]['n']], ['failed', 'not_sent', 0],
                 'D05 a 400: decision failed, ledger not_sent, no hand-over');
    });

    $ctx->run('D06 the settle carries the sent id: the echo that beat the claim is not a person to C7 (A28; K2-08)', function () use ($ctx, $evo, $fresh) {
        $b = $fresh();
        $n = va_note($b, '256771400406');
        va_run($b);
        $sentId = (string)(va_ledger($b, $n['wa'])[0]['wa_message_id'] ?? '');
        $b['pdo']->exec("UPDATE wa_conversations SET state = 'open'");      // the hand-over answered: only the echo is judged
        va_put($b, 'wa_messages', ['conversation_id' => $n['conv'], 'direction' => 'out', 'role' => 'agent', 'body' => 'VA echo (test)', 'agent_name' => 'Team',
            'wa_message_id' => $sentId . '-ECHO', 'sent_at' => UtcClock::stamp(va_noon() + 2), 'created_at' => UtcClock::stamp(va_noon() + 2)]);
        $b['pdo']->prepare('UPDATE wa_messages SET wa_message_id = ? WHERE wa_message_id = ?')->execute(['VA-OWN-ROW-' . $sentId, $sentId]);
        $b['pdo']->prepare('UPDATE wa_messages SET wa_message_id = ? WHERE wa_message_id = ?')->execute([$sentId, $sentId . '-ECHO']);
        $ctx->eq([$sentId !== '', HumanTakeover::since($b['pdo'], '256771400406', [$n['conv']], UtcClock::stamp(va_noon() - 60))], [true, null],
                 'D06 a Team row carrying the acknowledgement\'s own id is ours by the ledger: no person');
    });

    $ctx->run('D07 the echo is claimed before the settle: an echo arriving at the settle is dropped (A14; R-05)', function () use ($ctx, $evo, $fresh) {
        $b = $fresh();
        $seen = [];
        $b['pdo']->sqliteCreateFunction('va_echo', function ($id) use ($b, &$seen) {
            try {
                $st = $b['pdo']->prepare('SELECT COUNT(*) FROM evo_webhook_seen WHERE message_id = ?');
                $st->execute([(string)$id]);
                $seen[(string)$id] = (int)$st->fetchColumn() > 0;
            } catch (\Throwable $e) {
                $seen[(string)$id] = false;                                   // no claim table yet: nothing claimed
            }
            return 1;
        }, 1);
        $b['pdo']->exec("CREATE TEMP TRIGGER va_echo_at_settle AFTER UPDATE OF outcome ON main.wa_auto_sends
                         WHEN NEW.kind = 'voice_ack' AND NEW.outcome = 'sent' BEGIN SELECT va_echo(NEW.wa_message_id); END");
        $n = va_note($b, '256771400407');
        va_run($b);
        $id = (string)(va_ledger($b, $n['wa'])[0]['wa_message_id'] ?? '');
        $ctx->eq([va_outcome($b, $n['wa']), $id !== '', $seen[$id] ?? 'not asked'], ['sent', true, true],
                 'D07 when the ledger is settled the echo\'s id is already claimed, so the webhook would drop it');
        // What the webhook would have stored for an unclaimed echo: a Team row with the acknowledgement's own id.
        if (($seen[$id] ?? false) !== true) {
            va_put($b, 'wa_messages', ['conversation_id' => $n['conv'], 'direction' => 'out', 'role' => 'agent', 'body' => 'echo', 'agent_name' => 'Team',
                'wa_message_id' => $id . '-T', 'created_at' => UtcClock::stamp(va_noon())]);
        }
        $b['cfg']['wa_voice_ack_since'] = UtcClock::stamp(va_noon() - 86400 * 2);
        $c = va_check($b, 'VA-ON1', va_noon() + 86400 * 2);
        $ctx->eq([$c['status'], $c['_changes']], ['PASS', 0], 'D07 VA-ON1 reads PASS over it');
    });

    $ctx->run('D08 STOP: a proactive opt-out does not block the reply class; an `all` one does (F15)', function () use ($ctx, $evo, $fresh) {
        $b = $fresh();
        ContactOptOut::fromStore($b['store'])->add('256771400408', ['channel' => '*', 'scope' => ContactOptOut::SCOPE_PROACTIVE,
            'reason' => 'customer_request', 'source' => 'voice_keyword', 'evidence' => 'stop']);
        $n = va_note($b, '256771400408');
        va_run($b);
        $ctx->eq([va_outcome($b, $n['wa']), count(va_sent_to($evo, '256771400408'))], ['sent', 1], 'D08 a proactive (voice) STOP: the acknowledgement, a reply, is sent');
        $b = $fresh();
        ContactOptOut::fromStore($b['store'])->add('256771400418', ['channel' => '*', 'scope' => 'all', 'reason' => 'customer_request',
            'source' => 'admin', 'evidence' => 'never message me']);
        $n = va_note($b, '256771400418');
        va_run($b);
        $ctx->eq([va_outcome($b, $n['wa']), va_ledger($b, $n['wa'])[0]['outcome'] ?? null, count(va_sent_to($evo, '256771400418')),
                  (int)vm_q($b, "SELECT COUNT(*) n FROM events WHERE event_type = 'wa.escalation'")[0]['n']],
                 ['skipped:opted_out', 'not_sent', 0, 0], 'D08 an `all` opt-out: suppressed at the send — opted_out, not_sent, nobody told');
    });

    $ctx->run('D09 a policy refusal at the send: policy_at_send, not_sent, nothing sent', function () use ($ctx, $evo, $fresh) {
        $b = $fresh();
        $n = va_note($b, '256771400409');
        va_run($b, null, ['before' => function ($w) use ($b) {
            $e = new VaEvo($b['cfg']); $e->reg = false;
            $e->sendAnswer = ['ok' => false, 'http' => 0, 'data' => [], 'error' => EvolutionApiService::POLICY_REFUSED . 'channel_paused',
                              'policy_refused' => true, 'policy_reason' => 'channel_paused'];
            va_use_evo($w, $e);
        }]);
        $ctx->eq([va_outcome($b, $n['wa']), va_ledger($b, $n['wa'])[0]['outcome'] ?? null, count(va_sent_to($evo, '256771400409'))],
                 ['skipped:policy_at_send', 'not_sent', 0], 'D09 skipped:policy_at_send beside not_sent');
    });
}];
