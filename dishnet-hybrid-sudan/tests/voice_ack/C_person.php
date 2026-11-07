<?php
declare(strict_types=1);
/**
 * Section C of tests/test_voice_ack.php — "a person took over" for P5 (5.18.104, root docs/73 §H.3.15 steps 8–10;
 * contract C7; rulings R3, R4; A10, A11, A26, A32). TEST ONLY. Part F's HumanTakeover::since with since = the note's
 * received_at − 30 min, never humanRepliedSince; W1's content-free marks count; the plugin's own rows never do.
 */
$VA_SECTIONS['C'] = ['title' => 'P5 and a person: the pause, C7 (Part F\'s signature, received_at − 30 min), W1\'s marks, a hand-over line', 'run' => function (QfCtx $ctx): void {
    va_load($ctx);
    $evo = qf_fake_evo();
    $ctx->t($evo !== '', 'C00 the fake Evolution started');
    if ($evo === '') return;
    $fresh = function (array $over = []) use ($ctx, $evo): array {
        qf_http('GET', $evo . '/__test/reset');
        return vm_book($ctx, va_cfg($evo, $over));
    };
    $conv = function (array $b, string $phone): int {
        return (int)(new ConversationService($b['data'], $b['pdo']))->ensureConversation($phone, 'sales')['id'];
    };
    $mark = function (array $b, string $phone, int $t, array $cfg = null) {
        $real = time();
        $env = vm_envelope($phone, ['from_me' => true, 'id' => 'VA-COLLEAGUE-' . bin2hex(random_bytes(4))]);
        WaSeenMarks::note($b['pdo'], $cfg ?? $b['cfg'], $b['data'], 'out', $phone, 'sales', 'Sales-UG', (string)$env['key']['id'], $env);
        va_rebase($b, $real, $t);
    };

    $ctx->run('C01 a colleague\'s pause running: human_active; with the pause set to 0 the same note is acknowledged (A10)', function () use ($ctx, $evo, $fresh, $conv) {
        foreach ([[[], '256771400301', 'skipped:human_active', 0], [['wa_human_cooldown_minutes' => '0'], '256771400311', 'sent', 1]] as [$over, $p, $want, $n]) {
            $b = $fresh($over);
            $c = $conv($b, $p);
            $b['pdo']->prepare("UPDATE wa_conversations SET state = 'human_active', last_human_reply_at = ? WHERE id = ?")
                     ->execute([UtcClock::stamp(va_noon() - 300), $c]);
            $note = va_note($b, $p);
            va_run($b);
            $ctx->eq([va_outcome($b, $note['wa']), count(va_sent_to($evo, $p))], [$want, $n], "C01 pause " . ($over === [] ? 'running' : 'off') . ": {$want}");
        }
    });

    $ctx->run('C02 the plugin\'s own automated row 5 min before the note is no person: the acknowledgement goes (A11; R104-03)', function () use ($ctx, $evo, $fresh, $conv) {
        $b = $fresh();
        $c = $conv($b, '256771400302');
        // An enquiry follow-up as cron/followup_send.php stores it: role agent, 'DishNet' (the label humanRepliedSince
        // misreads as a colleague), its id a claim of the ledger's.
        va_put($b, 'wa_messages', ['conversation_id' => $c, 'direction' => 'out', 'role' => 'agent', 'body' => 'VA follow-up (test)',
            'agent_name' => 'DishNet', 'wa_message_id' => 'VA-FOLLOWUP-302', 'sent_at' => UtcClock::stamp(va_noon() - 300),
            'created_at' => UtcClock::stamp(va_noon() - 300)]);
        $n = va_note($b, '256771400302');
        va_run($b);
        $ctx->eq([va_outcome($b, $n['wa']), count(va_sent_to($evo, '256771400302'))], ['sent', 1], 'C02 sent: C7 is not humanRepliedSince\'s plain reading');
    });

    $ctx->run('C03 a colleague\'s captionless voice note from the handset 2 min before (a W1 mark): person_active (A26)', function () use ($ctx, $evo, $fresh, $mark) {
        $b = $fresh();
        $mark($b, '256771400303', va_noon() - 120);
        $ctx->eq((int)vm_q($b, "SELECT COUNT(*) n FROM wa_seen_marks WHERE phone = '256771400303' AND direction = 'out'")[0]['n'], 1,
                 'C03 control: W1 wrote its mark with the ledger on (step 2 alone)');
        $n = va_note($b, '256771400303');
        va_run($b);
        $ctx->eq([va_outcome($b, $n['wa']), count(va_sent_to($evo, '256771400303'))], ['skipped:person_active', 0], 'C03 person_active, nothing sent');
    });

    $ctx->run('C04 since = received_at − 30 min: a mark 10 min before counts, one 40 min before does not (A32; ruling R4)', function () use ($ctx, $evo, $fresh, $mark) {
        $b = $fresh();
        $mark($b, '256771400304', va_noon() - 600);
        $n = va_note($b, '256771400304');
        va_run($b);
        $b2 = $fresh();
        $mark($b2, '256771400314', va_noon() - 2400);
        $n2 = va_note($b2, '256771400314');
        va_run($b2);
        $ctx->eq([va_outcome($b, $n['wa']), va_outcome($b2, $n2['wa'])], ['skipped:person_active', 'sent'], 'C04 10 min before: person_active; 40 min before: sent');
    });

    $ctx->run('C05 an Inbox reply 3 min before the note: person_active', function () use ($ctx, $evo, $fresh, $conv) {
        $b = $fresh();
        $c = $conv($b, '256771400305');
        va_put($b, 'wa_messages', ['conversation_id' => $c, 'direction' => 'out', 'role' => 'agent', 'body' => 'VA colleague reply (test)',
            'agent_name' => 'Vee Colleague', 'event_key' => 'wa_staff_reply', 'wa_message_id' => 'VA-INBOX-305',
            'sent_at' => UtcClock::stamp(va_noon() - 180), 'created_at' => UtcClock::stamp(va_noon() - 180)]);
        $n = va_note($b, '256771400305');
        va_run($b);
        $ctx->eq(va_outcome($b, $n['wa']), 'skipped:person_active', 'C05 person_active');
    });

    $ctx->run('C06 a hand-over holding line stored after the note: handed_over (step 10)', function () use ($ctx, $evo, $fresh, $conv) {
        $b = $fresh();
        $n = va_note($b, '256771400306');
        $cs = new ConversationService($b['data'], $b['pdo']);
        $real = time();
        $cs->storeMessage($n['conv'], ['direction' => 'out', 'role' => 'assistant', 'body' => 'VA-HOLDING-LINE', 'agent_name' => 'DishNet AI',
            'wa_message_id' => 'VA-HOLD-306', 'metadata' => json_encode(['channel' => 'sales', 'handover' => true])]);
        va_rebase($b, $real, va_noon() + 5);
        va_run($b, va_noon() + 10);
        $ctx->eq([va_outcome($b, $n['wa']), count(va_sent_to($evo, '256771400306'))], ['skipped:handed_over', 0], 'C06 handed_over, nothing sent');
    });
}];
