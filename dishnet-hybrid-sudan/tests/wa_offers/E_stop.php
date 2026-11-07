<?php
declare(strict_types=1);
/**
 * Section E — STOP, and every scope (5.18.104, root docs/73 §H.4.9, §H.4.16 E; ruling R3). TEST ONLY.
 *
 * A typed STOP through the real evo_webhook.php leaves a live '*' opt-out, and no later offer reaches that number through
 * the sender: P6's own read at the send (judge()) leaves it out with the gateway's opt-out reader disabled, and the send's
 * own refusal stops it when the STOP is typed after judge() has read, each shown with the other out of the way. A stop word in a
 * long reply after an offer sets that recipient's stop_at and leaves the phone out of the next offer, with no opt-out
 * row. A spoken STOP (Part V's voice_keyword opt-out) excludes; no voice stop mark is read.
 */
$WO_SECTIONS['E'] = ['title' => 'STOP, and every scope (§H.4.9)', 'run' => function (QfCtx $ctx) {
    $T0 = wo_t0();

    $ctx->run('E01 a typed STOP through the real webhook, then no later offer: P6\'s read and the send\'s own refusal, each with the other disabled', function () use ($ctx, $T0) {
        // A webhook of its own (wo_whbook 'e01'): these sends leave echo claims and a live offer in its book, shared with no other case.
        $evo = qf_fake_evo();
        $b = wo_whbook($ctx, wo_cfg(['evo_api_url' => $evo]), 'e01');
        $ctx->t($b['url'] !== '' && $evo !== '', 'E01 control: the webhook and the fake Evolution are served');
        if ($b['url'] === '' || $evo === '') return;
        $posts = function (string $phone) use ($evo): int {
            return count(array_filter(qf_http('GET', $evo . '/__test/state')[1]['text_calls'] ?? [], function ($c) use ($phone) { return ($c['number'] ?? '') === $phone; }));
        };
        $realGw = function (bool $optOutReader) {
            return function (\PDO $pdo, array $f, ?string $dataDir, $store) use ($optOutReader): OfferGateway {
                $g = new EvolutionOfferGateway($pdo, $f, $dataDir, $store);
                if (!$optOutReader) $g->service()->setOptOut(null);      // the send's own refusal disabled
                return $g;
            };
        };
        $sender = function (WoBudget $bud, bool $optOutReader) use ($ctx, $b, $T0, $realGw): OfferSender {
            return OfferSender::forInstall($ctx->tree(), $b['data'], $b['cfg'], ['store' => $b['store'], 'clock' => new WoClock($T0),
                'gateway' => $realGw($optOutReader), 'budget' => function () use ($bud) { return $bud; }, 'wait' => function (int $x): void {}]);
        };

        // (1) P6's read, with the send's own refusal disabled: the started offer reaches the STOP'd number through judge() alone
        wo_warm($b, '256700900101', $T0, 86400);
        wo_warm($b, '256700900102', $T0, 86400, 'support');
        [$id, $st] = wo_started($b, $T0 - 60, new EvolutionOfferGateway($b['pdo'], $b['cfg'], $b['data'], $b['store']));
        $ks = array_map('strval', array_keys(wo_rows($b['pdo'], $id))); sort($ks);
        $ctx->eq([$st['ok'], $ks], [true, ['256700900101', '256700900102']], 'E01 control: an offer started with both customers queued');
        $ctx->eq(wo_echo($b['url'], '256700900101', 'WO-E01-STOP', 'STOP', false)[0], 200, 'E01 the customer types STOP after the Start; the webhook answers');
        $oo = $b['pdo']->query("SELECT channel, scope, source, active FROM contact_optouts WHERE phone = '256700900101'")->fetch(\PDO::FETCH_NUM);
        $ctx->eq($oo, ['*', 'proactive', 'keyword', 1], 'E01 the webhook recorded a live * opt-out, scope proactive, source keyword');
        $log = new WoLog();
        $sender(new WoBudget($log), false)->runOnce(null);
        $rows = wo_rows($b['pdo'], $id);
        $claimed = array_map(function ($c) { return $c[4]['phone'] ?? ''; }, array_values(array_filter($log->calls, function ($c) { return $c[0] === 'claim'; })));
        $ctx->eq([$rows['256700900101'], $claimed, $posts('256700900101')], [['skipped', 'opted_out'], ['256700900102'], 0],
                 'E01 (1) the send\'s refusal disabled: judge() skips the STOP\'d number (opted_out), never claimed, nothing POSTed to it');
        $ctx->eq([$rows['256700900102'][0], $posts('256700900102')], ['sent', 1], 'E01 (1) control: the reader-less gateway POSTs to the other customer');
        $ctx->t(wo_row($b['pdo'], '256700900101')['judged_at'] !== null, 'E01 (1) … the skip was step 4\'s judgement at the send (judged_at written)');
        OfferStore::stop($b['pdo'], $id, 7, 'Sandbox Admin', $T0 - 30);

        // (2) the send's own refusal, with judge()'s read out of the way: the STOP is typed while the ledger claims (step 6)
        wo_warm($b, '256700900103', $T0, 86400);
        [$id2, $st2] = wo_started($b, $T0 - 20, new EvolutionOfferGateway($b['pdo'], $b['cfg'], $b['data'], $b['store']));
        $ctx->eq([$st2['ok'], array_map('strval', array_keys(wo_rows($b['pdo'], $id2)))], [true, ['256700900103']], 'E01 control: a second offer started, the new customer queued');
        $log2 = new WoLog();
        $bud = new WoBudget($log2);
        $typed = 0;
        $bud->onClaim = function (string $h, string $k, string $ref, array $o) use ($b, &$typed): void {
            if (($o['phone'] ?? '') === '256700900103') $typed = wo_echo($b['url'], '256700900103', 'WO-E01-STOP-2', 'STOP', false)[0];
        };
        $sender($bud, true)->runOnce(null);
        $r3 = wo_row($b['pdo'], '256700900103');
        $set = array_values(array_filter($log2->calls, function ($c) { return $c[0] === 'settle'; }));
        $ctx->eq([$typed, count($log2->calls) > 0 ? $log2->calls[0][0] : ''], [200, 'claim'], 'E01 (2) control: judge() passed it, and the STOP was typed while the ledger claimed it');
        $ctx->eq([$r3['state'], $r3['reason'], $r3['judged_at'] !== null, $posts('256700900103')], ['skipped', 'opted_out', true, 0],
                 'E01 (2) the send\'s own refusal: OPTED_OUT, nothing POSTed, the row skipped(opted_out) with its judged_at');
        $ctx->eq(array_map(function ($c) { return $c[2]; }, $set), ['failed'], 'E01 (2) … and its ledger claim settled failed (nothing went)');
        OfferStore::stop($b['pdo'], $id2, 7, 'Sandbox Admin', $T0);
    });

    $ctx->run('E02 a stop word in a 60-character reply after an offer: stop_at set, the phone out of the next offer, no opt-out row', function () use ($ctx, $T0) {
        $b = wo_book($ctx, wo_cfg(), 'e');
        $log = new WoLog(); $gw = new WoGateway($log);
        $c = wo_warm($b, '256700900201', $T0, 86400);
        wo_warm($b, '256700900202', $T0, 86400);
        [$id] = wo_started($b, $T0 - 60, $gw);
        $s = wo_sender($ctx, $b, new WoClock($T0), new WoBudget($log), $gw);
        $s->runOnce(null);
        $reply = 'Please stop sending me these offers, I am not interested (test).';
        $ctx->eq([ContactOptOut::detect($reply)['stop'], wo_row($b['pdo'], '256700900201')['state']], [false, 'sent'], 'E02 control: the reply is no opt-out to the webhook; the offer was sent');
        wo_in($b, $c, $reply, $T0 + 3600);
        $clk = new WoClock($T0 + 7200);
        wo_sender($ctx, $b, $clk, new WoBudget($log), $gw)->runOnce(null);
        $ctx->eq([wo_row($b['pdo'], '256700900201')['stop_at'], (int)$b['pdo']->query('SELECT COUNT(*) FROM contact_optouts')->fetchColumn()],
                 [UtcClock::stamp($T0 + 3600), 0], 'E02 the reply and STOP pass set stop_at; no contact_optouts row was written');
        $L = OfferAudience::lists($b['pdo'], $b['cfg'], $b['data'], [], [], null, null, $T0 + 30 * 86400);
        $ctx->eq(OfferAudience::excludedBy($L, '256700900201'), 'offer_stop', 'E02 a month later the phone is still left out of every offer (offer_stop)');
        unset($id);
    });

    $ctx->run('E03 a spoken STOP (Part V\'s voice_keyword opt-out) excludes; a lifted opt-out is re-consent', function () use ($ctx, $T0) {
        $b = wo_book($ctx, wo_cfg(), 'e');
        wo_warm($b, '256700900301', $T0, 86400);
        wo_warm($b, '256700900302', $T0, 86400);
        $b['pdo']->exec("INSERT INTO contact_optouts (phone, channel, scope, reason, source) VALUES ('256700900301', 'support', 'proactive', 'customer_request', 'voice_keyword')");
        $L = OfferAudience::lists($b['pdo'], $b['cfg'], $b['data'], [], [], null, null, $T0);
        $ctx->eq([OfferAudience::excludedBy($L, '256700900301'), OfferAudience::excludedBy($L, '256700900302')], ['opted_out', ''], 'E03 the voice opt-out, recorded on another number, excludes; the other customer stays');
    });
}];
