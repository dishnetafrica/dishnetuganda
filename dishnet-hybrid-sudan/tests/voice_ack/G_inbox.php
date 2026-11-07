<?php
declare(strict_types=1);
/**
 * Section G of tests/test_voice_ack.php — R3's machine transcript in the Inbox, never in the body (5.18.104, root docs/73
 * §H.3.11; R104-08, F10; V35), the webhook's acknowledgement event and R4's silence there (§H.3.4 (a), (d); A4's webhook
 * form), and STOP said in a voice note at R3 (§H.3.14). TEST ONLY. Through the real plugin under php -S (SjSandbox),
 * as an administrator's API call and as Evolution's webhook.
 */
$VA_SECTIONS['G'] = ['title' => 'The Inbox shows R3\'s transcript beside the note (Uganda only); the webhook queues P5 and stays silent at R4; STOP at R3', 'run' => function (QfCtx $ctx): void {
    va_load($ctx);
    require_once dirname(__DIR__) . '/fixtures/staff_jobs_sandbox.php';
    $plant = function (SjSandbox $s): array {
        $pdo = $s->store()->getPdo();
        $cs = new ConversationService($s->data, $pdo);
        $out = [];
        foreach (['handover' => 'VA transcript, line one' . "\n" . 'line two <b>', 'reply' => 'VA R4 transcript', 'dry' => null] as $rung => $text) {
            $env = vm_envelope('256771400701');
            $conv = (int)$cs->importEvoMessage($env, 'sales');
            $mid = (int)InboundMedia::record($pdo, $conv, InboundMedia::fromEvoMessage($env), 'sj-sales', 'sales', (string)$env['key']['id']);
            $pdo->prepare("UPDATE wa_media SET status = 'understood', voice_rung = ?, understanding = ? WHERE id = ?")
                ->execute([$rung === 'dry' ? 'handover' : $rung, $text, $mid]);
            $out[$rung] = (string)$env['key']['id'];
            $out['conv'] = $conv;
        }
        return $out;
    };
    $thread = function (SjSandbox $s, int $conv): array {
        $r = $s->api('admin', 'GET', 'wa_thread_messages', null, '&id=' . $conv);
        $by = [];
        foreach ((array)($r[2]['data']['messages'] ?? []) as $m) $by[(string)($m['wa_message_id'] ?? '')] = $m;
        return [$r[0], $by];
    };

    $ug = null;
    try {
        $ug = SjSandbox::start($ctx->root, ['tenant_profile' => 'uganda', 'timezone' => QF_TZ, 'ai_enabled' => '1', 'ai_provider' => 'claude',
                                            'claude_api_key' => 'va-never-called'], 'vag');
        $ug->staff('admin', ['name' => 'Sandbox Admin', 'email' => 'admin@example.test', 'role' => 'admin', 'is_admin' => true]);
        $ctx->run('G01 control: on Uganda the R3 transcript travels as its own field beside the [AUDIO] body (V35)', function () use ($ctx, $ug, $plant, $thread) {
            $p = $plant($ug);
            [$code, $by] = $thread($ug, $p['conv']);
            $ctx->eq([$code, $by[$p['handover']]['voice_transcript'] ?? null, $by[$p['handover']]['body'] ?? null],
                     [200, "VA transcript, line one\nline two <b>", '[AUDIO]'], 'G01 the handover row\'s transcript, the body untouched');
            $ctx->eq([array_key_exists('voice_transcript', $by[$p['reply']] ?? []), array_key_exists('voice_transcript', $by[$p['dry']] ?? [])], [false, false],
                     'G01 a reply-rung row and a handover row with no transcript carry no field');
            $ug->login('admin', 'admin@example.test', 'sj-password-1');
            $page = $ug->page('admin', 'page=dashboard&tab=wa_inbox');
            $ctx->eq([substr_count($page, 'if(m.voice_transcript){'), substr_count($page, 'renderChat')  > 0], [1, true], 'G01 the Uganda Inbox page draws the transcript line once');
        });

        $setCfg = function (array $ov) use ($ug): void {
            foreach ($ov as $k => $v) { if ($v === null) unset($ug->cfg[$k]); else $ug->cfg[$k] = $v; }
            $ug->store()->save('kyc_config.json', $ug->cfg);
            file_put_contents($ug->data . '/kyc_config.json', json_encode($ug->cfg));
        };
        $post = function (array $env) use ($ug): array {
            $r = $ug->http('POST', $ug->base . '?page=evo_webhook', ['event' => 'messages.upsert', 'instance' => 'sj-sales', 'data' => $env],
                ['Content-Type: application/json', 'X-DishNet-Token: ' . $ug->evoKey]);
            return is_array($r[2]) ? $r[2] : ['_code' => $r[0]];
        };
        $setCfg(['wa_send_ledger' => 'on', 'wa_voice_ack_enabled' => '1', 'wa_voice_ack_since' => '2026-11-01 00:00:00']);
        $ctx->run('G02 the webhook queues one acknowledgement event for a voice note, and its answer says so (§H.3.4)', function () use ($ctx, $ug, $post) {
            $env = vm_envelope('256771400702');
            $r = $post($env);
            $ev = $ug->q("SELECT entity_type, payload, created_by FROM events WHERE event_type = 'ai.voice_ack'");
            $p = json_decode((string)($ev[0]['payload'] ?? ''), true) ?: [];
            $ctx->eq([$r['ack_queued'] ?? null, array_key_exists('media_queued', $r), count($ev), $ev[0]['created_by'] ?? null, $p['wa_message_id'] ?? null,
                      $p['customer_phone'] ?? null, $p['whatsapp_instance'] ?? null, UtcClock::parse((string)($p['received_at'] ?? '')) > 0, $p['ptt'] ?? null],
                     [1, false, 1, 'evo_webhook', (string)$env['key']['id'], '256771400702', 'sj-sales', true, true],
                     'G02 ack_queued 1 (R1 off: no media_queued); one ai.voice_ack with the note\'s id, phone, instance, received_at and ptt');
        });
        $setCfg(vm_rung('reply', 'sales'));
        $ctx->run('G03 R4 answering the number: the webhook queues no acknowledgement (A4, the webhook\'s form)', function () use ($ctx, $ug, $post) {
            $before = (int)($ug->q("SELECT COUNT(*) n FROM events WHERE event_type = 'ai.voice_ack'")[0]['n'] ?? -1);
            $r = $post(vm_envelope('256771400703'));
            $after = (int)($ug->q("SELECT COUNT(*) n FROM events WHERE event_type = 'ai.voice_ack'")[0]['n'] ?? -1);
            $ctx->eq([$r['ack_queued'] ?? null, $after - $before], [0, 0], 'G03 ack_queued 0, no event');
        });
    } finally {
        if ($ug !== null) $ug->stop();
    }

    $ss = null;
    try {
        $ss = SjSandbox::start($ctx->root, ['tenant_profile' => 'south-sudan', 'timezone' => implode('/', ['Africa', 'Juba']), 'ai_enabled' => '1'], 'vas');   // the zone named in parts: tests/test_timezone.php scans this file
        $ss->staff('admin', ['name' => 'Sandbox Admin', 'email' => 'admin@example.test', 'role' => 'admin', 'is_admin' => true]);
        $ctx->run('G04 South Sudan: the Inbox API is 5.18.103\'s — no transcript field (V35)', function () use ($ctx, $ss, $plant, $thread) {
            $p = $plant($ss);
            [$code, $by] = $thread($ss, $p['conv']);
            $ctx->eq([$code, array_key_exists('voice_transcript', $by[$p['handover']] ?? ['x' => 1]), isset($by[$p['handover']])], [200, false, true],
                     'G04 the same rows: the message is there, without voice_transcript');
            $ss->login('admin', 'admin@example.test', 'sj-password-1');
            $page = $ss->page('admin', 'page=dashboard&tab=wa_inbox');
            $ctx->eq([substr_count($page, 'voice_transcript'), substr_count($page, 'wai-voice-tx'), substr_count($page, 'renderChat') > 0], [0, 0, true],
                     'G04 the South Sudan Inbox page carries no transcript line: 5.18.103\'s page');
        });
    } finally {
        if ($ss !== null) $ss->stop();
    }

    $ctx->run('G05 the Inbox renders the field labelled, escaped, under the note (§H.3.11)', function () use ($ctx) {
        $src = (string)file_get_contents($ctx->tree() . '/tabs/engage/wa_inbox.php');
        $want = "if(m.voice_transcript){ body+='<div class=\"wai-voice-tx\"><b>[voice message \\u2014 machine transcript for staff, check it against the audio]</b> '+esc(m.voice_transcript).replace(/\\n/g,'<br>')+'</div>'; }";
        $ctx->eq(substr_count($src, $want), 1, 'G05 the render line, once: the label, esc() of the transcript, line breaks');
        $ctx->t(strpos($src, $want) > strpos($src, "body='<span class=\"media-tag\">"), 'G05 after the media tag line');
    });

    $ctx->run('G06 STOP said in a voice note at R3: a proactive opt-out from the transcript, the phone never logged (§H.3.14)', function () use ($ctx) {
        $evo = qf_fake_evo();
        $oa = vm_fake_openai();
        $b = vm_book($ctx, vm_cfg($evo, vm_rung('handover')));
        $v = vm_voice($b, '256771400706');
        vm_media($evo, $v['wa'], ogg_opus(['seconds' => 2.0]));
        vm_oa_next($oa, [['status' => 200, 'body' => ['text' => 'Stop']]]);
        $run = vm_run($b, $oa);
        $oo = vm_q($b, "SELECT scope, channel, source, evidence FROM contact_optouts WHERE phone LIKE '%771400706'");
        $ctx->eq($oo, [['scope' => 'proactive', 'channel' => '*', 'source' => 'voice_keyword', 'evidence' => 'Stop']], 'G06 one proactive opt-out, source voice_keyword');
        $ctx->t(strpos($run['log'], '771400706') === false, 'G06 the log names no phone', $run['log']);
    });
}];
