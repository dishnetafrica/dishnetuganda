<?php
declare(strict_types=1);
/**
 * Section G — the gateway and its Evolution driver (5.18.104, root docs/73 §H.4.11, §H.4.16 G; O-D11, O-D18). TEST ONLY.
 *
 * Against the repository's fake Evolution (and tests/wa_offers/evo_stub.php for the answers it cannot give): the text and
 * image bodies as sendText/sendMedia build them; the echo claimed inside send(), so an echo arriving during the settle is
 * dropped by the real evo_webhook.php as ours (no Team row, no hand-over); an echo that beats the claim is 'late'; no id is
 * 'none'; handsetFor() in the ledger form; each failure by its fixed code, never transport text; NotificationService never
 * touched.
 */

/** The copy's gateway over a book, its configuration pointing at $evo. */
function wo_evo_gw(array $b, string $evo, array $over = []): EvolutionOfferGateway
{
    $cfg = array_merge($b['cfg'], ['evo_api_url' => $evo], $over);
    return new EvolutionOfferGateway($b['pdo'], $cfg, $b['data'], $b['store']);
}

$WO_SECTIONS['G'] = ['title' => 'The gateway: Evolution now, the Meta Cloud API as interface only (§H.4.11)', 'run' => function (QfCtx $ctx) {
    $T0 = wo_t0();
    $line = 'Reply STOP to stop these messages.';
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==');

    $ctx->run('G01 the text and the image as sendText/sendMedia build them; the echo claimed at once (offer.send)', function () use ($ctx, $line, $png) {
        $evo = qf_fake_evo();
        $ctx->t($evo !== '', 'G01 control: the fake Evolution is up');
        if ($evo === '') return;
        $b = wo_book($ctx, wo_cfg(['evo_api_url' => $evo]), 'g');
        $gw = wo_evo_gw($b, $evo);
        $ctx->eq([$gw->name(), $gw->handsetFor('sales'), $gw->handsetFor('account'), $gw->handsetFor('marketing')], ['evolution', 'i:sales-ug', 'i:dishnet-ug', ''],
                 'G01 handsetFor(): the ledger form, support and account one handset, an unmapped channel \'\'');
        $text = OfferText::render('A fictitious offer wording for the gateway test.', OfferText::STOP_LINE);
        $r = $gw->send(new OfferMessage('sales', '256700800101', $text, null, 'offer.send', ContactOptOut::CLASS_PROACTIVE));
        $st = qf_http('GET', $evo . '/__test/state')[1];
        $ctx->eq([$r->outcome, $r->messageId, $r->echo], ['sent', 'FAKE-EVO-MSG-1', 'claimed'], 'G01 a text: SENT with its id, the echo claimed');
        $ctx->eq([$st['text_calls'][0]['instance'] ?? null, $st['text_calls'][0]['number'] ?? null, $st['text_calls'][0]['text'] ?? null],
                 ['Sales-UG', '256700800101', "A fictitious offer wording for the gateway test.\n\n" . $line], 'G01 … POSTed to the channel\'s instance with the STOP line');
        $seen = $b['pdo']->query("SELECT instance, event FROM evo_webhook_seen WHERE message_id = 'FAKE-EVO-MSG-1'")->fetch(\PDO::FETCH_NUM);
        $ctx->eq($seen, ['Sales-UG', 'offer.send'], 'G01 the echo claim: evo_webhook_seen holds the id with event offer.send');
        $r = $gw->send(new OfferMessage('support', '256700800102', $text, ['payload' => base64_encode($png), 'mime' => 'image/png'], 'offer.test', ContactOptOut::CLASS_STAFF));
        $st = qf_http('GET', $evo . '/__test/state')[1];
        $mc = $st['media_calls'][0] ?? [];
        $ctx->eq([$r->outcome, $mc['instance'] ?? null, $mc['mediatype'] ?? null, $mc['caption'] ?? null, $mc['media_prefix'] ?? null],
                 ['sent', 'DishNet-UG', 'image', $text, substr(base64_encode($png), 0, 48)], 'G01 an image: one sendMedia, base64 (never a URL), the wording and STOP line as its caption');
        $ctx->eq($b['pdo']->query("SELECT event FROM evo_webhook_seen WHERE message_id = 'FAKE-EVO-MEDIA-1'")->fetchColumn(), 'offer.test', 'G01 … its echo claimed as offer.test');
    });

    $ctx->run('G02 the echo arrives during the settle: dropped by the real webhook as ours — no Team row, no hand-over', function () use ($ctx, $T0) {
        $evo = qf_fake_evo();
        $b = wo_whbook($ctx, wo_cfg(['evo_api_url' => $evo]));
        $ctx->t($b['url'] !== '' && $evo !== '', 'G02 control: the webhook and the fake Evolution are served');
        if ($b['url'] === '' || $evo === '') return;
        $log = new WoLog();
        wo_warm($b, '256700800201', $T0, 86400);
        $gw = wo_evo_gw($b, $evo);
        [$id, $r] = wo_started($b, $T0 - 60, $gw);
        $ctx->eq($r['ok'], true, 'G02 control: an offer started');
        $bud = new WoBudget($log);
        $posted = [];
        $bud->onSettle = function ($claim, $result, $o) use ($b, &$posted) {
            $posted[] = wo_echo($b['url'], '256700800201', (string)($o['wa_message_id'] ?? ''), 'echo')[0];
        };
        $s = OfferSender::forInstall($ctx->tree(), $b['data'], $b['cfg'], ['store' => $b['store'], 'clock' => new WoClock($T0),
             'budget' => function () use ($bud) { return $bud; }, 'wait' => function (int $x): void {}]);
        $s->runOnce(null);
        $row = wo_row($b['pdo'], '256700800201');
        $ctx->eq([$row['state'] ?? null, $row['echo'] ?? null, $posted], ['sent', 'claimed', [200]], 'G02 the run sent it, echo claimed; the webhook answered the echo posted during the settle');
        $team = (int)$b['pdo']->query("SELECT COUNT(*) FROM wa_messages WHERE agent_name = 'Team' AND wa_message_id = " . $b['pdo']->quote((string)$row['wa_message_id']))->fetchColumn();
        $conv = $b['pdo']->query('SELECT state FROM wa_conversations WHERE id = ' . (int)$row['conversation_id'])->fetchColumn();
        $ctx->eq([$team, $conv === 'human_active'], [0, false], 'G02 no Team row with the offer\'s id, and the conversation is not handed to a person');
        $agent = $b['pdo']->query('SELECT agent_name FROM wa_messages WHERE wa_message_id = ' . $b['pdo']->quote((string)$row['wa_message_id']))->fetchColumn();
        $ctx->eq($agent, 'DishNet Plugin', 'G02 the offer is stored as DishNet Plugin (humanRepliedSince excludes it; O-D12)');
    });

    $ctx->run('G03 the echo that beats the claim: late, and the Team row it left is counted', function () use ($ctx, $T0) {
        $stub = wo_stub('ok');                                         // its next id is WO-STUB-MSG-1, never stored in this book before
        $b = wo_whbook($ctx, wo_cfg(['evo_api_url' => qf_fake_evo()]));
        if ($b['url'] === '' || $stub === '') { $ctx->t(false, 'G03 control: the webhook and the stub Evolution are served'); return; }
        wo_conv($b, '256700800301', 'sales');
        $ctx->eq(wo_echo($b['url'], '256700800301', 'WO-STUB-MSG-1', 'An echo first (test)')[0], 200, 'G03 control: the echo reached the webhook first');
        $gw = wo_evo_gw($b, $stub);
        $r = $gw->send(new OfferMessage('sales', '256700800301', 'A wording that went (test).', null, 'offer.send', ContactOptOut::CLASS_PROACTIVE));
        $ctx->eq([$r->outcome, $r->messageId, $r->echo], ['sent', 'WO-STUB-MSG-1', 'late'], 'G03 the claim found the id already there: echo late');
        $ctx->eq((int)$b['pdo']->query("SELECT COUNT(*) FROM wa_messages WHERE agent_name = 'Team' AND wa_message_id = 'WO-STUB-MSG-1'")->fetchColumn(), 1,
                 'G03 … the Team row the webhook stored stands (what C-O2-8 counts)');
    });

    $ctx->run('G04 each failure by its fixed code: no id, HTTP 400, HTTP 504, no instance, not configured, an opt-out, a policy refusal', function () use ($ctx) {
        $stub = wo_stub('noid');
        $ctx->t($stub !== '', 'G04 control: the stub Evolution is up');
        if ($stub === '') return;
        $b = wo_book($ctx, wo_cfg(['evo_api_url' => $stub]), 'g');
        $m = function (string $ch = 'sales', string $p = '256700800401') { return new OfferMessage($ch, $p, 'A wording (test).', null, 'offer.send', ContactOptOut::CLASS_PROACTIVE); };
        $gw = wo_evo_gw($b, $stub);
        $r = $gw->send($m());
        $ctx->eq([$r->outcome, $r->code, $r->echo], ['maybe_sent', 'no_message_id', 'none'], 'G04 ok with no id: MAYBE_SENT no_message_id, echo none');
        wo_stub('http400');
        $r = $gw->send($m());
        $ctx->eq([$r->outcome, $r->code], ['not_sent', 'http_4xx'], 'G04 HTTP 400: NOT_SENT http_4xx');
        wo_stub('http504');
        $r = $gw->send($m());
        $ctx->eq([$r->outcome, $r->code], ['maybe_sent', 'may_have_been_sent'], 'G04 HTTP 504 on the POST: MAYBE_SENT may_have_been_sent');
        wo_stub('http500');
        $r = $gw->send($m());
        $ctx->eq([$r->outcome, $r->code, strpos($r->code, 'Sales') === false], ['not_sent', 'send_failed', true],
                 'G04 HTTP 500 on the POST: NOT_SENT send_failed — a fixed code, never the error text with its instance name');
        $r = $gw->send($m('marketing'));
        $ctx->eq([$r->outcome, $r->code], ['not_sent', 'no_instance'], 'G04 a channel with no instance: NOT_SENT no_instance');
        $r = wo_evo_gw($b, '', ['evo_api_key' => ''])->send($m());
        $ctx->eq([$r->outcome, $r->code], ['not_sent', 'not_configured'], 'G04 no Evolution configured: NOT_SENT not_configured');
        wo_stub('ok');
        (new ContactOptOut($b['pdo']))->add('256700800402', ['channel' => '*']);
        $r = $gw->send($m('sales', '256700800402'));
        $calls = qf_http('GET', $stub . '/__calls')[1];
        $ctx->eq([$r->outcome, $r->code, $calls], ['opted_out', 'opted_out', []], 'G04 an opted-out number: OPTED_OUT, refused before any POST');
        $r = $gw->send(new OfferMessage('sales', '256700800402', 'A test to our own staff (test).', null, 'offer.test', ContactOptOut::CLASS_STAFF));
        $ctx->eq($r->outcome, 'sent', 'G04 control: class staff is never refused by an opt-out');
        $reg = wo_book($ctx, wo_cfg(['evo_api_url' => $stub, 'multi_number_channels_enabled' => '1']), 'g');
        $r = wo_evo_gw($reg, $stub, ['multi_number_channels_enabled' => '1'])->send($m('sales-404'));
        $ctx->eq([$r->outcome, $r->code, $r->transient], ['refused', 'unknown_channel', false], 'G04 the policy refuses an unknown channel: REFUSED unknown_channel, final');
        foreach ([$r] as $x) $ctx->t((bool)preg_match('/^[a-z_:]*$/', $x->code), 'G04 every code is a fixed code (no transport text, no instance name)');
    });

    $ctx->run('G05 NotificationService is never called: no notification_queue row, no Message Log row', function () use ($ctx, $T0) {
        $evo = qf_fake_evo();
        $b = wo_book($ctx, wo_cfg(['evo_api_url' => $evo]), 'g');
        wo_warm($b, '256700800501', $T0, 86400);
        $gw = wo_evo_gw($b, $evo);
        wo_stub('http400');
        [$id] = wo_started($b, $T0 - 60, $gw);
        $log = new WoLog();
        $bud = new WoBudget($log);
        $fail = wo_evo_gw($b, wo_stub('http400'));
        $s = OfferSender::forInstall($ctx->tree(), $b['data'], $b['cfg'], ['store' => $b['store'], 'clock' => new WoClock($T0),
             'gateway' => function () use ($fail) { return $fail; }, 'budget' => function () use ($bud) { return $bud; }, 'wait' => function (int $x): void {}]);
        $s->runOnce(null);
        $ctx->eq(wo_row($b['pdo'], '256700800501')['state'], 'failed', 'G05 control: the send failed (HTTP 400) and is failed, never retried');
        $q = function (string $t) use ($b): int {
            $e = $b['pdo']->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = " . $b['pdo']->quote($t))->fetchColumn();
            return (int)$e === 0 ? 0 : (int)$b['pdo']->query("SELECT COUNT(*) FROM [{$t}]")->fetchColumn();
        };
        $ctx->eq([$q('notification_queue'), $q('notification_log')], [0, 0], 'G05 no notify_retry row and no Message Log row');
        unset($id);
    });
}];
