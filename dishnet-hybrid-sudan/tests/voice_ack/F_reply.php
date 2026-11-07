<?php
declare(strict_types=1);
/**
 * Section F of tests/test_voice_ack.php — R4: the assistant answers a voice note's transcript in text (5.18.104, root
 * docs/73 §H.3.12, §H.3.13; F20; V28, V29, V34's wrapper; (d)–(f)), and P5's events never become a reply's hand-over.
 * TEST ONLY. The reply worker runs as run_worker.php runs it, in real time, with a fake brain; every send goes to the
 * fake Evolution.
 */
$VA_SECTIONS['F'] = ['title' => 'R4 in the reply worker: the voice context and block, F20\'s two rules, the audit\'s modality, the hand-over wrapper', 'run' => function (QfCtx $ctx): void {
    va_load($ctx);
    $evo = qf_fake_evo();
    $ctx->t($evo !== '', 'F00 the fake Evolution started');
    if ($evo === '') return;
    $fresh = function (array $over = []) use ($ctx, $evo): array {
        qf_http('GET', $evo . '/__test/reset');
        return vm_book($ctx, va_cfg($evo, vm_rung('reply', 'sales') + $over));
    };

    $ctx->run('F01 control: a voice turn on a listed number is answered, the brain told it is a transcript ((d), (e))', function () use ($ctx, $evo, $fresh) {
        $b = $fresh();
        $t = va_voice_turn($b, '256771400601', 'sales', ['seconds' => 4]);
        $brain = new VaBrain($b['cfg']);
        $r = va_run($b, null, ['brain' => $brain]);
        $c = $brain->contexts[0] ?? [];
        $prompt = $c !== [] ? $brain->promptPreview($c) : '';
        $ctx->eq([count($brain->contexts), $c['voice'] ?? null, array_column(va_sent_to($evo, '256771400601'), 2)], [1, ['seconds' => 4], [$brain->canned]],
                 'F01 the brain asked once with voice: {seconds: 4}, its answer sent');
        $ctx->t(strpos($prompt, 'VOICE MESSAGE JUST RECEIVED (4 s):') !== false && strpos($prompt, 'AUTOMATIC TRANSCRIPT') !== false,
                'F01 the prompt carries the VOICE MESSAGE block', substr($prompt, 0, 200));
        $ctx->t(strpos($r['log'], 'len=') !== false && strpos($r['log'], 'origin=voice') !== false, 'F01 the "in" line says origin=voice', $r['log']);
    });

    $ctx->run('F02 a typed turn: no voice key, no VOICE block (the prompts are 5.18.103\'s)', function () use ($ctx, $evo, $fresh) {
        $b = $fresh();
        va_text_turn($b, '256771400602', 'Do you sell routers?');
        $brain = new VaBrain($b['cfg']);
        $r = va_run($b, null, ['brain' => $brain]);
        $c = $brain->contexts[0] ?? [];
        $ctx->eq([count($brain->contexts), array_key_exists('voice', $c), strpos($brain->promptPreview($c), 'VOICE MESSAGE') !== false,
                  strpos($r['log'], 'origin=') !== false], [1, false, false, false], 'F02 no voice key, no block, no origin in the log');
    });

    $ctx->run('F03 F20 rule 1: a typed message waits for an earlier voice note still being heard (V28)', function () use ($ctx, $evo, $fresh) {
        $go = function (array $b, int $age, string $phone): array {
            $env = vm_envelope($phone);
            $conv = (int)(new ConversationService($b['data'], $b['pdo']))->importEvoMessage($env, 'sales');
            $mid = (int)InboundMedia::record($b['pdo'], $conv, InboundMedia::fromEvoMessage($env), 'Sales-UG', 'sales', (string)$env['key']['id']);
            $b['pdo']->prepare("UPDATE wa_media SET created_at = datetime('now', ?) WHERE id = ?")->execute(['-' . $age . ' seconds', $mid]);
            $t = va_text_turn($b, $phone, 'And the price?');
            $brain = new VaBrain($b['cfg']);
            va_run($b, null, ['brain' => $brain]);
            return [$t, $brain];
        };
        $b = $fresh();
        [$t, $brain] = $go($b, 10, '256771400603');
        $ev = vm_q($b, 'SELECT status, error, attempts FROM events WHERE id = ?', [$t['event']])[0] ?? [];
        $ctx->eq([count($brain->contexts), $ev['status'] ?? null, $ev['error'] ?? null, (int)($ev['attempts'] ?? -1)],
                 [0, 'pending', 'voice: an earlier voice note is being transcribed', 0], 'F03 a note 10 s old, pending: the text is deferred, the attempt count kept');
        $b = $fresh();
        [$t, $brain] = $go($b, 100, '256771400613');
        $ctx->eq(count($brain->contexts), 1, 'F03 control: the note 100 s old — past 90 s the text is answered');
        $b = vm_book($ctx, va_cfg($evo, vm_rung('handover')));
        [$t, $brain] = $go($b, 10, '256771400623');
        $ctx->eq(count($brain->contexts), 1, 'F03 control: R4 not on for this number — answered at once');
    });

    $ctx->run('F04 F20 rule 2: a voice turn after a later message was answered goes to a person (V29)', function () use ($ctx, $evo, $fresh) {
        $b = $fresh();
        $t = va_voice_turn($b, '256771400604');
        $cs = new ConversationService($b['data'], $b['pdo']);
        $cs->importEvoMessage(vm_envelope('256771400604', ['kind' => 'text', 'text' => 'hello?']), 'sales');
        $cs->storeMessage($t['conv'], ['direction' => 'out', 'role' => 'assistant', 'body' => 'VA earlier answer', 'agent_name' => 'DishNet AI',
                                       'wa_message_id' => 'VA-ANS-604', 'metadata' => json_encode(['channel' => 'sales'])]);
        $brain = new VaBrain($b['cfg']);
        va_run($b, null, ['brain' => $brain]);
        $esc = vm_q($b, "SELECT payload FROM events WHERE event_type = 'wa.escalation'");
        $ctx->eq([count($brain->contexts), array_map(function ($e) { return json_decode($e['payload'], true)['reason'] ?? ''; }, $esc), count(va_sent_to($evo, '256771400604'))],
                 [0, ['a voice note arrived before a message that has already been answered — read its transcript in the Inbox'], 0],
                 'F04 handed to a person, no AI answer, no holding line (it was answered)');
        $b = $fresh();
        $t = va_voice_turn($b, '256771400614');
        (new ConversationService($b['data'], $b['pdo']))->importEvoMessage(vm_envelope('256771400614', ['kind' => 'text', 'text' => 'hello?']), 'sales');
        $brain = new VaBrain($b['cfg']);
        va_run($b, null, ['brain' => $brain, 'batch' => 1]);
        $ctx->t(count($brain->contexts) >= 1, 'F04 control: a later message not yet answered — the voice turn is answered');
    });

    $ctx->run('F05 the guard\'s audit names the modality: voice for a transcript, text otherwise ((f))', function () use ($ctx, $evo, $fresh) {
        $b = $fresh();
        $w = new AiReplyWorker($b['store'], $b['cfg'], 45, 10);
        $g = new \ReflectionMethod(AiReplyWorker::class, 'guardReply'); $g->setAccessible(true);
        $bad = ['reply' => 'The kit is UGX 4,321,987 and your balance is UGX 7,654,321 on account 99887766.', 'escalate' => false];
        ob_start();
        try {
            $g->invoke($w, $bad, ['channel' => 'sales', 'message' => 'x', 'voice' => ['seconds' => 3]], '');
            $g->invoke($w, $bad, ['channel' => 'sales', 'message' => 'x'], '');
        } finally { ob_end_clean(); }
        $ev = (array)($b['store']->load('ai_security_events.json') ?? []);
        $mods = array_map(function ($e) { return (string)($e['modality'] ?? '?'); }, array_slice(array_values($ev), -2));
        $ctx->eq($mods, ['voice', 'text'], 'F05 two blocked replies: modality voice, then text');
    });

    $ctx->run('F06 an acknowledgement event that dies tells nobody: no hand-over, no holding line', function () use ($ctx, $evo, $fresh) {
        $b = vm_book($ctx, va_cfg($evo));
        qf_http('GET', $evo . '/__test/reset');
        $n = va_note($b, '256771400606');
        $b['pdo']->exec('DROP TABLE wa_voice_acks');                           // every decision now throws
        $b['pdo']->prepare('UPDATE events SET attempts = max_attempts - 1 WHERE id = ?')->execute([$n['event']]);
        va_run($b);
        $ctx->eq([vm_q($b, 'SELECT status FROM events WHERE id = ?', [$n['event']])[0]['status'] ?? null,
                  (int)vm_q($b, "SELECT COUNT(*) n FROM events WHERE event_type = 'wa.escalation'")[0]['n'], vm_texts($evo)],
                 ['dead', 0, []], 'F06 the event dead; nobody told, nothing sent (the watchdog still pages the note)');
    });

    $ctx->run('F07 the reply worker\'s escalate is the wrapper: the same statements as 221c9dc\'s (V34; test_handover_message\'s name and signature)', function () use ($ctx, $evo) {
        $base = vm_base($ctx);
        $ctx->t($base !== null, 'F07 Git supplied 221c9dc');
        if ($base === null) return;
        $drive = function (string $tree, bool $owner) use ($ctx, $evo): array {
            qf_http('GET', $evo . '/__test/reset');
            $data = $ctx->dir('ho-w');
            $o = [];
            exec('DN_VAULT_FILE=' . escapeshellarg((string)getenv('DN_VAULT_FILE')) . ' php ' . escapeshellarg(dirname(__DIR__) . '/voice_media/handover_drive.php')
                . ' --tree=' . escapeshellarg($tree) . ' --mode=worker --data=' . escapeshellarg($data) . ' --evo=' . escapeshellarg($evo)
                . ' --owner=' . ($owner ? '1' : '0') . ' 2>&1', $o, $rc);
            $j = json_decode((string)end($o), true);
            return is_array($j) ? $j : ['_rc' => $rc, '_out' => implode("\n", array_slice($o, -10))];
        };
        foreach ([false, true] as $owner) {
            $now = $drive($ctx->tree(), $owner);
            $then = $drive($base, $owner);
            $ctx->t(isset($then['events']) && count($then['events']) === 3, 'F07 control: 221c9dc\'s escalate ran (' . ($owner ? 'owner' : 'department') . ')', json_encode($then));
            foreach (['states', 'events', 'sends', 'stored', 'alerts'] as $k) {
                $ctx->eq($now[$k] ?? null, $then[$k] ?? null, 'F07 ' . ($owner ? 'an owner\'s number' : 'a department number') . ': the same ' . $k);
            }
        }
        $m = new \ReflectionMethod(AiReplyWorker::class, 'escalate');
        $ctx->eq([$m->isPrivate(), array_map(function ($p) { return $p->getName(); }, $m->getParameters())],
                 [true, ['convId', 'channel', 'phone', 'reason', 'alreadyAnswered']], 'F07 the name and signature test_handover_message reflects on are kept');
    });
}];
