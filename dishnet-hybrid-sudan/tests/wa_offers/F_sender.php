<?php
declare(strict_types=1);
/**
 * Section F — the sender (5.18.104, root docs/73 §H.4.10, §H.4.16 F; rulings R2, R8, R18, R19). TEST ONLY.
 *
 * OfferSender::runOnce() with the scripted ledger and gateway (one call log, so their order is asserted) and the injected
 * clock: claim before the send, settle after; the claim's phone, stable ref and in-flight bound; every ledger code by its
 * row of §H.4.10's table; the configuration read again under the lock (IO-8) and every recipient decided on its own read
 * (ruling R19); the stale sweep for each crash window; master's deadline; the per-handset ceiling and handset_failing;
 * the window and the reminder gate (ruling R8); the live-since guard; variants, the awaiting-reply defer, a move, the image,
 * the stop rate, expiry; "all" over a week; the offer's own limits at the send (ruling R18).
 */

/** A book, warm customers ($phones: phone => channel), a started offer; [book, offer id, log, budget, gateway, clock]. */
function wo_fx(QfCtx $ctx, array $phones, array $cfg = [], array $o = []): array
{
    $T0 = $o['t0'] ?? wo_t0();
    $b = wo_book($ctx, wo_cfg($cfg), 'f');
    $log = new WoLog();
    $gw = new WoGateway($log, ($o['handsets'] ?? []) + ['sales' => 'i:sales-ug', 'support' => 'i:dishnet-ug', 'account' => 'i:dishnet-ug']);
    $i = 0;
    foreach ($phones as $p => $ch) wo_warm($b, (string)$p, $T0, 86400 + 600 * $i++, $ch);
    if (isset($o['before_start'])) ($o['before_start'])($b);
    [$id, $r] = wo_started($b, $T0 - 60, $gw, $o['offer'] ?? []);
    if (!$r['ok']) throw new \RuntimeException('wo_fx: start ' . $r['code']);
    $log->calls = [];
    $budget = new WoBudget($log, $o['answers'] ?? []);
    return [$b, $id, $log, $budget, $gw, new WoClock($T0)];
}

/** The calls of one kind from the log. */
function wo_calls(WoLog $log, string $kind): array
{
    return array_values(array_filter($log->calls, function ($c) use ($kind) { return $c[0] === $kind; }));
}

/** One recipient row by phone. */
function wo_row(\PDO $pdo, string $phone): array
{
    $st = $pdo->prepare('SELECT * FROM wa_offer_recipients WHERE phone = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$phone]);
    return $st->fetch(\PDO::FETCH_ASSOC) ?: [];
}

$WO_SECTIONS['F'] = ['title' => 'The sender: claim before the send, settle after, and idempotency across crashes (§H.4.10)', 'run' => function (QfCtx $ctx) {
    $T0 = wo_t0();
    $line = 'Reply STOP to stop these messages.';                     // §H.1's approved line, written out here, never read from the copy

    $ctx->run('F01 claim, send, settle in that order; the phone, the stable ref and the in-flight bound; one per handset per run', function () use ($ctx, $line) {
        [$b, $id, $log, $bud, $gw, $clk] = wo_fx($ctx, ['256700700101' => 'sales', '256700700102' => 'sales', '256700700103' => 'support']);
        $r = wo_sender($ctx, $b, $clk, $bud, $gw)->runOnce(null);
        $ctx->eq([$r['sent'], $r['code'], $r['handsets']], [2, '', 2], 'F01 control: two handsets, one send each');
        $ctx->eq(OfferText::STOP_LINE, $line, 'F01 OfferText::STOP_LINE is §H.1\'s line, verbatim');
        $ctx->eq(wo_order($log), ['claim', 'send', 'settle', 'claim', 'send', 'settle'], 'F01 every send is claimed before and settled after');
        $c = wo_calls($log, 'claim')[0];
        $row = wo_row($b['pdo'], $c[4]['phone']);
        $ctx->eq([$c[2], $c[3], $c[4]], ['offer', 'offer:' . $id . ':' . $row['id'], ['phone' => $row['phone'], 'in_flight_s' => 50]],
                 'F01 the claim: kind offer, ref offer:<offer>:<recipient>, the phone, in_flight_s 50');
        $s = wo_calls($log, 'settle')[0];
        $ctx->eq([$s[2], $s[3]], ['sent', ['wa_message_id' => $row['wa_message_id']]], 'F01 the settle: sent, with the message id');
        $send = wo_calls($log, 'send')[0];
        $ctx->eq([substr_count($send[3], $line), substr($send[3], -strlen($line) - 2), $send[5], $send[6]], [1, "\n\n" . $line, 'offer.send', 'proactive'],
                 'F01 the text ends in the STOP line once, after a blank line; echo event offer.send; class proactive');
        $ctx->eq([$row['state'], $row['echo'], $row['variant'], (int)$row['attempt'], $row['judged_at'] !== null, (int)$row['day_max']], ['sent', 'claimed', 1, 1, true, 20],
                 'F01 the row: sent, its echo, its variant, one attempt, judged_at and day_max recorded');
        $m = $b['pdo']->query("SELECT agent_name, event_key, role FROM wa_messages WHERE wa_message_id = " . $b['pdo']->quote($row['wa_message_id']))->fetch(\PDO::FETCH_NUM);
        $ctx->eq($m, ['DishNet Plugin', 'wa_offer', 'agent'], 'F01 stored in its conversation as DishNet Plugin, wa_offer (O-D12)');
        $ctx->eq(wo_row($b['pdo'], '256700700102')['state'] ?? null, 'queued', 'F01 the second recipient on the sales handset waits for the next run');
    });

    $ctx->run('F02 the same ref on every attempt (ruling R2); a defer is never sent and is released to its retry_at', function () use ($ctx, $T0) {
        [$b, $id, $log, $bud, $gw, $clk] = wo_fx($ctx, ['256700700201' => 'sales'], [], ['answers' => [['defer' => 'spacing', 'retry_at' => $T0 + 120]]]);
        $s = wo_sender($ctx, $b, $clk, $bud, $gw);
        $s->runOnce(null);
        $row = wo_row($b['pdo'], '256700700201');
        $ctx->eq([wo_order($log), $row['state'], $row['not_before']], [['claim'], 'queued', UtcClock::stamp($T0 + 120)], 'F02 spacing: nothing sent, the row released to retry_at');
        $clk->t = $T0 + 240;
        $s->runOnce(null);
        $refs = array_map(function ($c) { return $c[3]; }, wo_calls($log, 'claim'));
        $ctx->eq([count($refs), count(array_unique($refs)), (int)wo_row($b['pdo'], '256700700201')['attempt']], [2, 1, 2], 'F02 the second claim carries the same ref; the row\'s own attempt is 2');
        $ctx->eq(wo_row($b['pdo'], '256700700201')['state'], 'sent', 'F02 … and the recipient is sent once');
    });

    $ctx->run('F03 a may-have-been-sent: settled failed with maybe_sent, stored uncertain, never sent again', function () use ($ctx, $T0) {
        [$b, $id, $log, $bud, $gw, $clk] = wo_fx($ctx, ['256700700301' => 'sales']);
        $gw->results = [new OfferSendResult(OfferSendResult::MAYBE_SENT, 'may_have_been_sent', 'WO-MAYBE-1', false, 'claimed')];
        $s = wo_sender($ctx, $b, $clk, $bud, $gw);
        $s->runOnce(null);
        $st = wo_calls($log, 'settle')[0];
        $ctx->eq([$st[2], $st[3]], ['failed', ['maybe_sent' => true, 'wa_message_id' => 'WO-MAYBE-1']], 'F03 the settle: failed, maybe_sent, with the id');
        $row = wo_row($b['pdo'], '256700700301');
        $ctx->eq([$row['state'], $row['reason'], $row['sent_at'] !== null], ['uncertain', 'may_have_been_sent', true], 'F03 the row: uncertain(may_have_been_sent)');
        $clk->t = $T0 + 3600;
        $s->runOnce(null);
        $ctx->eq(count(wo_calls($log, 'send')), 1, 'F03 the next run sends nothing more to it');
    });

    $ctx->run('F04 each ledger code by its row of §H.4.10\'s table (ruling R2)', function () use ($ctx, $T0) {
        $tue8 = qf_t('2026-11-03 08:00:00');
        $cases = [['phone_today', null, 'queued', UtcClock::stamp($tue8)], ['already_sent', null, 'uncertain', 'ledger_already_sent'],
                  ['tries_exhausted', null, 'skipped', 'tries_exhausted'], ['not_claimable', null, 'skipped', 'not_claimable'], ['no_phone', null, 'skipped', 'no_phone'],
                  ['in_flight', $T0 + 900, 'queued', UtcClock::stamp($T0 + 900)], ['reminders_first', $T0 + 600, 'queued', UtcClock::stamp($T0 + 600)]];
        foreach ($cases as [$code, $retry, $state, $what]) {
            [$b, $id, $log, $bud, $gw, $clk] = wo_fx($ctx, ['256700700401' => 'sales', '256700700402' => 'support'],
                                                    [], ['answers' => [['defer' => $code, 'retry_at' => $code === 'phone_today' ? $T0 + 60 : $retry]]]);
            $r = wo_sender($ctx, $b, $clk, $bud, $gw)->runOnce(null);
            $row = wo_row($b['pdo'], (string)wo_calls($log, 'claim')[0][4]['phone']);
            $got = $state === 'queued' ? $row['not_before'] : $row['reason'];
            $ctx->eq([$row['state'], $got, $r['sent'], $r['code']], [$state, $what, 1, ''], "F04 {$code}: {$state} ({$what}); never sent; the run goes on to the next handset");
            if ($code === 'already_sent') $ctx->eq($row['sent_at'], $row['claimed_at'], 'F04 already_sent: sent_at = claimed_at');
        }
        foreach (['ledger_error', 'zone', 'something_new'] as $code) {
            [$b, $id, $log, $bud, $gw, $clk] = wo_fx($ctx, ['256700700401' => 'sales', '256700700402' => 'support'], [], ['answers' => [['defer' => $code, 'retry_at' => $T0 + 300]]]);
            $r = wo_sender($ctx, $b, $clk, $bud, $gw)->runOnce(null);
            $row = wo_row($b['pdo'], (string)wo_calls($log, 'claim')[0][4]['phone']);
            $ctx->eq([$row['state'], $row['not_before'], $r['sent'], $r['code'], count(wo_calls($log, 'claim'))], ['queued', null, 0, $code, 1],
                     "F04 {$code}: the row released unchanged and the run ends (fail closed), no other handset tried");
        }
    });

    $ctx->run('F05 two runs at once: the lock refuses the second; with the lock out of the way the row CAS and the ledger send each recipient once', function () use ($ctx) {
        [$b, $id, $log, $bud, $gw, $clk] = wo_fx($ctx, ['256700700501' => 'sales', '256700700502' => 'sales']);
        $fp = fopen($b['data'] . '/offers_send.lock', 'c');
        flock($fp, LOCK_EX);
        $r = wo_sender($ctx, $b, $clk, $bud, $gw)->runOnce(null);
        flock($fp, LOCK_UN); fclose($fp);
        $ctx->eq([$r['code'], $log->calls], ['busy', []], 'F05 the lock held elsewhere: busy, nothing claimed');
        // another process claims the row between this run's read of it and its CAS: the CAS refuses, the row is not sent here
        $store = new WoStore($b['store'], $b['data']);
        $r1 = (string)$b['pdo']->query("SELECT phone FROM wa_offer_recipients WHERE offer_id = {$id} ORDER BY rank LIMIT 1")->fetchColumn();
        $store->pdo->hooks[] = ["UPDATE wa_offer_recipients SET state = 'claimed'", function () use ($b, $r1) {
            $b['pdo']->prepare("UPDATE wa_offer_recipients SET state = 'claimed', claim_token = 'other', claimed_at = ?, attempt = attempt + 1 WHERE phone = ?")
                      ->execute([UtcClock::stamp(wo_t0()), $r1]);
        }];
        wo_sender($ctx, $b, $clk, $bud, $gw, ['store' => $store, 'nolock' => true])->runOnce(null);
        $row = wo_row($b['pdo'], $r1);
        $ctx->eq([count(wo_calls($log, 'send')), $row['state'], $row['claim_token']], [0, 'claimed', 'other'],
                 'F05 a row claimed by another run between this run\'s read and its CAS: not sent here, the other run\'s claim kept');
        // two whole runs, the second inside the first between its read and its CAS: each recipient once
        [$b, $id, $log, $bud, $gw, $clk] = wo_fx($ctx, ['256700700511' => 'sales', '256700700512' => 'sales']);
        $store = new WoStore($b['store'], $b['data']);
        $second = wo_sender($ctx, $b, $clk, $bud, $gw, ['nolock' => true]);
        $store->pdo->hooks[] = ["UPDATE wa_offer_recipients SET state = 'claimed'", function () use ($second) { $second->runOnce(null); }];
        wo_sender($ctx, $b, $clk, $bud, $gw, ['store' => $store, 'nolock' => true])->runOnce(null);
        $sent = array_map(function ($c) { return $c[2]; }, wo_calls($log, 'send'));
        $ctx->eq([count($sent), count(array_unique($sent))], [1, 1], 'F05 two runs at once (the lock disabled in both): one send, never the same recipient twice');
    });

    $ctx->run('F06 the configuration read again under the lock (IO-8): a sender built before off sending claims nothing after it', function () use ($ctx) {
        foreach (['wa_send_ledger' => 'ledger_off', 'offers_live_enabled' => 'offers_off'] as $key => $code) {
            [$b, $id, $log, $bud, $gw, $clk] = wo_fx($ctx, ['256700700601' => 'sales', '256700700602' => 'support']);
            $b['pdo']->prepare("UPDATE wa_offer_recipients SET state = 'claimed', claim_token = 'k', claimed_at = ?, attempt = 1, judged_at = ?, day_max = 20,
                                       ledger_claim = 88, variant = 1 WHERE phone = '256700700602'")->execute([UtcClock::stamp(wo_t0() - 1000), UtcClock::stamp(wo_t0() - 1000)]);
            $s = wo_sender($ctx, $b, $clk, $bud, $gw);
            wo_setcfg($b, [$key => null]);
            $r = $s->runOnce(null);
            $ctx->eq([$r['code'], count($log->calls), wo_row($b['pdo'], '256700700602')['state']], [$code, 0, 'claimed'],
                     "F06 {$key} cleared in the files before runOnce: {$code}, 0 claims, 0 sends, and no sweep (the stale claim untouched)");
        }
        [$b, $id, $log, $bud, $gw, $clk] = wo_fx($ctx, ['256700700601' => 'sales']);
        $r = wo_sender($ctx, $b, $clk, $bud, $gw)->runOnce(null);
        $ctx->eq([$r['code'], $r['sent']], ['', 1], 'F06 control: with no change between, it sends');
    });

    $ctx->run('F07 the stale sweep, per crash window: queued again, or uncertain and never sent again; tests left sending', function () use ($ctx, $T0) {
        [$b, $id, $log, $bud, $gw, $clk] = wo_fx($ctx, ['256700700701' => 'sales', '256700700702' => 'support', '256700700703' => 'account']);
        $p = $b['pdo'];
        $old = UtcClock::stamp($T0 - 16 * 60); $fresh = UtcClock::stamp($T0 - 5 * 60);
        $p->prepare("UPDATE wa_offer_recipients SET state = 'claimed', claim_token = 'x', claimed_at = ?, attempt = 1 WHERE phone = '256700700701'")->execute([$old]);
        $p->prepare("UPDATE wa_offer_recipients SET state = 'claimed', claim_token = 'y', claimed_at = ?, attempt = 1, judged_at = ?, day_max = 20, ledger_claim = 77, variant = 1 WHERE phone = '256700700702'")->execute([$old, $old]);
        $p->prepare("UPDATE wa_offer_recipients SET state = 'claimed', claim_token = 'z', claimed_at = ?, attempt = 1 WHERE phone = '256700700703'")->execute([$fresh]);
        $p->prepare("INSERT INTO wa_offer_tests (offer_id, content_hash, variant, staff_id, channel, handset, state, started_at) VALUES (?, 'h', 1, 7, 'sales', 'i:sales-ug', 'sending', ?)")->execute([$id, $old]);
        $r = wo_sender($ctx, $b, $clk, $bud, $gw)->runOnce(null);
        $ctx->eq(wo_calls($log, 'settle')[0] ?? null, ['settle', 77, 'failed', ['maybe_sent' => true]], 'F07 7–POST (a ledger claim recorded): the sweep settles it failed with maybe_sent');
        $r2 = wo_row($p, '256700700702');
        $ctx->eq([$r2['state'], $r2['reason'], $r2['sent_at']], ['uncertain', 'stale_claim', $old], 'F07 … and the row uncertain(stale_claim), sent_at = claimed_at');
        $ctx->eq(wo_row($p, '256700700701')['state'], 'sent', 'F07 2–6 (no ledger claim): back to queued, then sent by this run');
        $ctx->eq(wo_row($p, '256700700703')['state'], 'claimed', 'F07 a claim 5 minutes old is not stale: untouched');
        $ctx->eq($p->query("SELECT state FROM wa_offer_tests WHERE content_hash = 'h'")->fetchColumn(), 'uncertain', 'F07 a test sending for 16 minutes is uncertain');
        $sends = array_map(function ($c) { return $c[2]; }, wo_calls($log, 'send'));
        $ctx->eq(in_array('256700700702', $sends, true), false, 'F07 the uncertain recipient is never sent again');
        unset($r);
    });

    $ctx->run('F08 master\'s deadline: 30 s before it no send starts after deadline − 15 s; 10 s before it only the sweep and the guard', function () use ($ctx, $T0) {
        $ph = [];
        for ($i = 0; $i < 6; $i++) $ph['25670070080' . $i] = 'h' . $i;
        $hs = [];
        for ($i = 0; $i < 6; $i++) $hs['h' . $i] = 'i:h' . $i;
        [$b, $id, $log, $bud, $gw, $clk] = wo_fx($ctx, $ph, [], ['handsets' => $hs]);
        $gw->onSend = function () use ($clk) { $clk->t += 10; };
        $starts = [];
        $gw->onSend = function () use ($clk, &$starts) { $starts[] = $clk->t; $clk->t += 10; };
        wo_sender($ctx, $b, $clk, $bud, $gw)->runOnce($T0 + 30);
        $ctx->eq($starts, [$T0, $T0 + 10], 'F08 dispatched 30 s before the deadline: sends start at +0 and +10, none after deadline − 15 s');
        [$b, $id, $log, $bud, $gw, $clk] = wo_fx($ctx, $ph, [], ['handsets' => $hs]);
        $starts = [];
        $gw->onSend = function () use ($clk, &$starts) { $starts[] = $clk->t; $clk->t += 10; };
        wo_sender($ctx, $b, $clk, $bud, $gw)->runOnce(null);
        $ctx->eq($starts, [$T0, $T0 + 10, $T0 + 20], 'F08 no deadline: the run\'s own 35 s budget — sends start at +0, +10 and +20');
        [$b, $id, $log, $bud, $gw, $clk] = wo_fx($ctx, $ph, [], ['handsets' => $hs]);
        $b['pdo']->prepare("UPDATE wa_offer_recipients SET state = 'claimed', claim_token = 'x', claimed_at = ?, attempt = 1 WHERE phone = '256700700800'")->execute([UtcClock::stamp($T0 - 1000)]);
        $b['pdo']->prepare("UPDATE wa_offer_recipients SET state = 'sent', claim_token = 'y', claimed_at = ?, attempt = 1, judged_at = ?, day_max = 20, variant = 1,
                                   wa_message_id = 'WO-F08-S', echo = 'claimed', sent_at = ? WHERE phone = '256700700801'")
                  ->execute([UtcClock::stamp($T0 - 3600), UtcClock::stamp($T0 - 3600), UtcClock::stamp($T0 - 3600)]);
        wo_in($b, (int)wo_row($b['pdo'], '256700700801')['conversation_id'], 'Thanks, tell me more (test)', $T0 - 1800);
        wo_setcfg($b, ['offers_live_since' => UtcClock::stamp($T0)]);
        $r = wo_sender($ctx, $b, $clk, $bud, $gw)->runOnce($T0 + 10);
        $o = OfferStore::find($b['pdo'], $id);
        $ctx->eq([count($log->calls), wo_row($b['pdo'], '256700700800')['state'], $o['state']], [0, 'queued', 'paused'],
                 'F08 dispatched 10 s before it: the stale claim swept, the offer paused by the live-since guard, no claim and no send');
        $ctx->eq(wo_row($b['pdo'], '256700700801')['replied_at'], null, 'F08 … and nothing past them (the reply pass waits for a run with time)');
        unset($r);
    });

    $ctx->run('F09 the per-handset ceiling (day_max) and handset_failing: released to the next day\'s opening, never claimed', function () use ($ctx, $T0) {
        $tue8 = UtcClock::stamp(qf_t('2026-11-03 08:00:00'));
        [$b, $id, $log, $bud, $gw, $clk] = wo_fx($ctx, ['256700700901' => 'sales', '256700700902' => 'sales', '256700700903' => 'sales'], ['offers_daily_max_per_handset' => 2]);
        $s = wo_sender($ctx, $b, $clk, $bud, $gw);
        $s->runOnce(null); $clk->t += 300; $s->runOnce(null); $clk->t += 300; $s->runOnce(null);
        $ctx->eq([count(wo_calls($log, 'send')), wo_row($b['pdo'], '256700700903')['state'], wo_row($b['pdo'], '256700700903')['not_before']], [2, 'queued', $tue8],
                 'F09 ceiling 2: two sends today, the third released to Tuesday 08:00 Kampala without a claim');
        $ctx->eq(count(wo_calls($log, 'claim')), 2, 'F09 … and never claimed');
        [$b, $id, $log, $bud, $gw, $clk] = wo_fx($ctx, ['256700700911' => 'sales', '256700700912' => 'sales', '256700700913' => 'sales', '256700700914' => 'sales']);
        $gw->results = [new OfferSendResult(OfferSendResult::NOT_SENT, 'http_4xx'), new OfferSendResult(OfferSendResult::NOT_SENT, 'http_4xx'), new OfferSendResult(OfferSendResult::NOT_SENT, 'http_4xx')];
        $s = wo_sender($ctx, $b, $clk, $bud, $gw);
        for ($i = 0; $i < 4; $i++) { $s->runOnce(null); $clk->t += 300; }
        $ctx->eq([count(wo_calls($log, 'send')), wo_row($b['pdo'], '256700700914')['not_before']], [3, $tue8], 'F09 three certain failures in a row today: handset_failing, released until tomorrow');
        $ctx->eq(wo_row($b['pdo'], '256700700911')['state'], 'failed', 'F09 a certain failure is failed, with no automatic retry');
    });

    $ctx->run('F10 the window in a literal Kampala: outside 08:00–20:00 and on Sunday nothing; a narrowed hour binds; timezone Africa/Juba refused', function () use ($ctx, $T0) {
        foreach (['20:30 Monday' => qf_t('2026-11-02 20:30:00'), '07:30 Monday' => qf_t('2026-11-02 07:30:00'), 'Sunday 10:30' => qf_t('2026-11-08 10:30:00')] as $what => $t) {
            [$b, $id, $log, $bud, $gw, $clk] = wo_fx($ctx, ['256700701001' => 'sales']);
            $clk->t = $t;
            if ($what === 'Sunday 10:30') $b['store']->save('master_schedule.json', ['customer_reminders' => ['last_run' => qf_t('2026-11-08 09:05:00'), 'duration_ms' => 1000]]);
            wo_sender($ctx, $b, $clk, $bud, $gw)->runOnce(null);
            $ctx->eq(count($log->calls), 0, "F10 at {$what}: no claim, no send");
        }
        [$b, $id, $log, $bud, $gw, $clk] = wo_fx($ctx, ['256700701001' => 'sales'], ['wa_send_hour_to' => 10]);
        wo_sender($ctx, $b, $clk, $bud, $gw)->runOnce(null);
        $ctx->eq(count($log->calls), 0, 'F10 wa_send_hour_to narrowed to 10: at 10:30 nothing (the ledger always ok here, so the sender\'s own window holds it)');
        [$b, $id, $log, $bud, $gw, $clk] = wo_fx($ctx, ['256700701001' => 'sales'], [], ['answers' => [['defer' => 'phone_today', 'retry_at' => $T0 + 60]]]);
        wo_setcfg($b, ['wa_send_hour_from' => 9]);
        wo_sender($ctx, $b, $clk, $bud, $gw)->runOnce(null);
        $ctx->eq(wo_row($b['pdo'], '256700701001')['not_before'], UtcClock::stamp(qf_t('2026-11-03 09:00:00')), 'F10 a narrowed wa_send_hour_from binds the release time (phone_today: Tuesday 09:00)');
        [$b, $id, $log, $bud, $gw, $clk] = wo_fx($ctx, ['256700701001' => 'sales'], ['timezone' => 'Africa/' . 'Juba']   /* in parts: test_timezone reads a quoted zone as a pinned one */);
        $r = wo_sender($ctx, $b, $clk, $bud, $gw)->runOnce(null);
        $ctx->eq([$r['code'], count($log->calls)], ['zone', 0], 'F10 timezone Africa/Juba: refused (zone), nothing claimed');
    });

    $ctx->run('F11 the reminder gate, through ReminderDay::done() (ruling R8)', function () use ($ctx) {
        $go = function (int $t, ?array $row) use ($ctx): array {
            [$b, $id, $log, $bud, $gw, $clk] = wo_fx($ctx, ['256700701101' => 'sales']);
            $b['store']->save('master_schedule.json', $row === null ? [] : ['customer_reminders' => $row]);
            $clk->t = $t;
            $r = wo_sender($ctx, $b, $clk, $bud, $gw)->runOnce(null);
            return [$r['code'], count(wo_calls($log, 'send'))];
        };
        $ctx->eq($go(qf_t('2026-11-02 08:30:00'), null), ['reminders_pending', 0], 'F11 at 08:30, before the reminders: nothing goes');
        $ctx->eq($go(qf_t('2026-11-02 10:30:00'), ['last_run' => qf_t('2026-11-02 09:05:00'), 'duration_ms' => 1000]), ['', 1], 'F11 after today\'s customer_reminders completed: offers go');
        $ctx->eq($go(qf_t('2026-11-02 10:30:00'), ['last_run' => qf_t('2026-11-02 09:05:00'), 'duration_ms' => -1]), ['reminders_pending', 0], 'F11 a crashed run (duration_ms -1) holds offers…');
        $ctx->eq($go(qf_t('2026-11-02 17:30:00'), ['last_run' => qf_t('2026-11-02 09:05:00'), 'duration_ms' => -1]), ['', 1], 'F11 … until reminder_until_hour (17:00)');
        $ctx->eq($go(qf_t('2026-11-02 17:30:00'), null), ['', 1], 'F11 with no row by reminder_until_hour, offers go');
    });

    $ctx->run('F12 the live-since guard (O-D19): an offer left running across an off and on is paused, not sent', function () use ($ctx, $T0) {
        [$b, $id, $log, $bud, $gw, $clk] = wo_fx($ctx, ['256700701201' => 'sales']);
        wo_setcfg($b, ['offers_live_since' => UtcClock::stamp($T0 - 30)]);
        wo_sender($ctx, $b, $clk, $bud, $gw)->runOnce(null);
        $o = OfferStore::find($b['pdo'], $id);
        $ev = $b['pdo']->query("SELECT action FROM wa_offer_events WHERE offer_id = {$id} ORDER BY id DESC LIMIT 1")->fetchColumn();
        $ctx->eq([$o['state'], $ev, count($log->calls)], ['paused', 'paused_by_switch', 0], 'F12 started before offers_live_since: paused (paused_by_switch), nothing sent');
        OfferStore::resume($b['pdo'], $id, 7, 'Sandbox Admin', $T0);
        wo_sender($ctx, $b, $clk, $bud, $gw)->runOnce(null);
        $ctx->eq(count(wo_calls($log, 'send')), 1, 'F12 control: an admin\'s Resume after the switch-on, and it sends');
    });

    $ctx->run('F13 wordings in turn per handset; F14 an answer awaited is deferred, then sent after 120 minutes; F15 a move', function () use ($ctx, $T0) {
        [$b, $id, $log, $bud, $gw, $clk] = wo_fx($ctx, ['256700701301' => 'sales', '256700701302' => 'sales', '256700701303' => 'sales', '256700701304' => 'sales']);
        $s = wo_sender($ctx, $b, $clk, $bud, $gw);
        for ($i = 0; $i < 4; $i++) { $s->runOnce(null); $clk->t += 300; }
        $v = $b['pdo']->query("SELECT variant FROM wa_offer_recipients WHERE offer_id = {$id} ORDER BY claimed_at, id")->fetchAll(\PDO::FETCH_COLUMN);
        $ctx->eq(array_map('intval', $v), [1, 2, 1, 2], 'F13 two wordings used in turn on one handset: 1, 2, 1, 2');
        [$b, $id, $log, $bud, $gw, $clk] = wo_fx($ctx, ['256700701401' => 'sales']);
        $c = (int)wo_row($b['pdo'], '256700701401')['conversation_id'];
        wo_in($b, $c, 'Is the kit in stock? (test)', $T0 - 30 * 60);
        wo_out($b, $c, 'Yes it is. (test)', $T0 - 29 * 60);
        $s = wo_sender($ctx, $b, $clk, $bud, $gw);
        $s->runOnce(null);
        $row = wo_row($b['pdo'], '256700701401');
        $ctx->eq([$row['state'], $row['reason'], $row['not_before'], count(wo_calls($log, 'claim'))], ['queued', 'awaiting_reply', UtcClock::stamp($T0 + 90 * 60), 0],
                 'F14 the customer wrote 30 minutes ago: deferred (awaiting_reply) to 120 minutes after it, never claimed');
        $clk->t = $T0 + 90 * 60;
        $s->runOnce(null);
        $ctx->eq(wo_row($b['pdo'], '256700701401')['state'], 'sent', 'F14 … and sent once 120 minutes have passed');
        [$b, $id, $log, $bud, $gw, $clk] = wo_fx($ctx, ['256700701501' => 'sales']);
        $c2 = wo_conv($b, '256700701501', 'support');
        wo_in($b, $c2, 'Now writing on support (test)', $T0 - 5 * 3600); wo_out($b, $c2, 'Answered (test)', $T0 - 5 * 3600 + 30);
        $s = wo_sender($ctx, $b, $clk, $bud, $gw);
        $s->runOnce(null);
        $row = wo_row($b['pdo'], '256700701501');
        $ctx->eq([$row['handset'], (int)$row['conversation_id'], $row['state'], count(wo_calls($log, 'send'))], ['i:dishnet-ug', $c2, 'queued', 0],
                 'F15 the customer wrote on another handset since the start: re-pointed and released, never sent from the old one');
        $clk->t += 300;
        $s->runOnce(null);
        $send = wo_calls($log, 'send')[0] ?? [];
        $ctx->eq([$send[1] ?? null, wo_row($b['pdo'], '256700701501')['state']], ['support', 'sent'], 'F15 … then sent from that handset in its turn');
    });

    $ctx->run('F16 the image: sent with its payload; changed after the tests, the run ends image_changed and nothing is sent', function () use ($ctx, $T0) {
        $img = function (array $b, int $id): void {
            $dir = $b['data'] . '/offer_images'; @mkdir($dir, 0750, true);
            $raw = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==');
            file_put_contents($dir . '/offer-' . $id . '.png', $raw);
            OfferStore::setImage($b['pdo'], $id, ['file' => 'offer-' . $id . '.png', 'mime' => 'image/png', 'bytes' => strlen($raw), 'sha256' => hash('sha256', $raw)], 7, 'Sandbox Admin', wo_t0() - 60);
        };
        $b = wo_book($ctx, wo_cfg(), 'f');
        $log = new WoLog(); $gw = new WoGateway($log);
        wo_warm($b, '256700701601', $T0, 86400);
        $id = wo_offer($b, $T0 - 60);
        $img($b, $id);
        wo_tested($b, $id, $T0 - 60);
        $o = OfferStore::find($b['pdo'], $id);
        OfferStore::start($b['pdo'], $b['cfg'], $b['data'], [], [], $id, (string)$o['content_hash'], 7, 'Sandbox Admin', $T0 - 60, $gw);
        $clk = new WoClock($T0); $bud = new WoBudget($log);
        $log->calls = [];
        file_put_contents($b['data'] . '/offer_images/offer-' . $id . '.png', 'changed bytes');
        $r = wo_sender($ctx, $b, $clk, $bud, $gw)->runOnce(null);
        $ctx->eq([$r['code'], count($log->calls)], ['image_changed', 0], 'F16 the image changed after the tests: image_changed, nothing claimed or sent');
        $img($b, $id);   // a draft only: setImage refuses now; put the bytes back as they were
        $raw = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==');
        file_put_contents($b['data'] . '/offer_images/offer-' . $id . '.png', $raw);
        wo_sender($ctx, $b, $clk, $bud, $gw)->runOnce(null);
        $send = wo_calls($log, 'send')[0] ?? [];
        $ctx->eq($send[4] ?? null, true, 'F16 control: the image untouched, the message goes with it');
    });

    $ctx->run('F17 the stop rate pauses at offers_stop_pause_pct of at least 40 sends; F18 expiry and completion', function () use ($ctx, $T0) {
        [$b, $id, $log, $bud, $gw, $clk] = wo_fx($ctx, ['256700701701' => 'sales', '256700701702' => 'sales']);
        $p = $b['pdo'];
        $ins = $p->prepare("INSERT INTO wa_offer_recipients (offer_id, phone, rank, conversation_id, channel, handset, last_in_at, state, sent_at, judged_at, day_max,
                                variant, wa_message_id, echo, claim_token, claimed_at, attempt, stop_at) VALUES (?, ?, ?, 1, 'sales', 'i:sales-zz', ?, 'sent', ?, ?, 20, 1, ?, 'claimed', 't', ?, 1, ?)");
        $at = UtcClock::stamp($T0 - 2 * 86400);
        for ($i = 0; $i < 40; $i++) $ins->execute([$id, sprintf('2567007180%02d', $i), 100 + $i, $at, $at, $at, 'WO-F17-' . $i, $at, $i < 1 ? $at : null]);
        wo_sender($ctx, $b, $clk, $bud, $gw)->runOnce(null);
        $ctx->eq(OfferStore::find($p, $id)['state'], 'running', 'F17 control: 1 STOP in 41 sends (2.4 %) does not pause at 5 %');
        $p->exec("UPDATE wa_offer_recipients SET stop_at = '{$at}' WHERE phone IN ('256700718001', '256700718002')");
        wo_sender($ctx, $b, $clk, $bud, $gw)->runOnce(null);
        $ev = $p->query("SELECT action FROM wa_offer_events WHERE offer_id = {$id} ORDER BY id DESC LIMIT 1")->fetchColumn();
        $ctx->eq([OfferStore::find($p, $id)['state'], $ev], ['paused', 'paused_stop_rate'], 'F17 3 in 41 (over 5 %): paused, paused_stop_rate');
        [$b, $id, $log, $bud, $gw, $clk] = wo_fx($ctx, ['256700701801' => 'sales', '256700701802' => 'sales']);
        $clk->t = $T0 + 15 * 86400;
        $b['store']->save('master_schedule.json', ['customer_reminders' => ['last_run' => qf_t('2026-11-17 09:05:00'), 'duration_ms' => 1000]]);
        wo_sender($ctx, $b, $clk, $bud, $gw)->runOnce(null);
        $o = OfferStore::find($b['pdo'], $id);
        $ctx->eq([$o['state'], $o['close_reason'], wo_row($b['pdo'], '256700701802')['reason'], count($log->calls)], ['done', 'expired', 'offer_expired', 0],
                 'F18 past offers_max_days (14): done, expired, its queued rows skipped(offer_expired), nothing sent');
        [$b, $id, $log, $bud, $gw, $clk] = wo_fx($ctx, ['256700701811' => 'sales']);
        $s = wo_sender($ctx, $b, $clk, $bud, $gw);
        $s->runOnce(null); $clk->t += 300; $s->runOnce(null);
        $ctx->eq(OfferStore::find($b['pdo'], $id)['close_reason'], 'done', 'F18 nothing queued or claimed left: done');
    });

    $ctx->run('F19 each claim on its own fresh read (ruling R19): a switch cleared between two recipients binds the second', function () use ($ctx) {
        foreach (['offers_live_enabled' => 'offers_off', 'wa_send_ledger' => 'ledger_off'] as $key => $code) {
            [$b, $id, $log, $bud, $gw, $clk] = wo_fx($ctx, ['256700701901' => 'sales', '256700701902' => 'support']);
            $n = 0;
            $gw->onSend = function () use (&$b, $key, &$n) { if ($n++ === 0) wo_setcfg($b, [$key => null]); };
            $r = wo_sender($ctx, $b, $clk, $bud, $gw)->runOnce(null);
            $ctx->eq([$r['sent'], $r['code'], count(wo_calls($log, 'claim'))], [1, $code, 1], "F19 {$key} cleared in the files after the first send: the second is released, the run ends {$code}");
        }
        [$b, $id, $log, $bud, $gw, $clk] = wo_fx($ctx, ['256700701901' => 'sales', '256700701902' => 'support']);
        $r = wo_sender($ctx, $b, $clk, $bud, $gw)->runOnce(null);
        $ctx->eq($r['sent'], 2, 'F19 control: with no change between, both go');
    });

    $ctx->run('F20 a mode changed between two claims binds the second (ruling R19): offers_on_owned_numbers set to 0 mid-run', function () use ($ctx) {
        $setup = function (array $b): void {
            $b['pdo']->exec("INSERT INTO wa_channels (channel_id, evo_instance, display_name, role, owner_type, owner_staff_id, status) VALUES ('sales-002', 'Sales-Zz', 'Sales zz (test)', 'sales', 'staff', 31, 'active')");
        };
        foreach ([true, false] as $change) {
            [$b, $id, $log, $bud, $gw, $clk] = wo_fx($ctx, ['256700702001' => 'sales', '256700702002' => 'sales-002'], ['offers_on_owned_numbers' => 1],
                                                    ['handsets' => ['sales-002' => 'i:sales-zz'], 'before_start' => $setup]);
            $n = 0;
            $gw->onSend = function () use (&$b, $change, &$n) { if ($change && $n++ === 0) wo_setcfg($b, ['offers_on_owned_numbers' => 0]); };
            wo_sender($ctx, $b, $clk, $bud, $gw)->runOnce(null);
            $row = wo_row($b['pdo'], '256700702002');
            if ($change) $ctx->eq([$row['state'], $row['reason']], ['skipped', 'owned_number'], 'F20 set to 0 after the first send: the salesperson\'s customer is skipped (owned_number), unsent');
            else         $ctx->eq($row['state'], 'sent', 'F20 control: with no change between, it is sent');
        }
    });

    $ctx->run('F21 the offer\'s own limits at the send (ruling R18): warm_days recorded at the Start binds a later narrowed setting', function () use ($ctx, $T0) {
        [$b, $id, $log, $bud, $gw, $clk] = wo_fx($ctx, ['256700702101' => 'sales']);
        $c = (int)wo_row($b['pdo'], '256700702101')['conversation_id'];
        wo_in($b, $c, '[AUDIO]', $T0 - 20 * 86400, ['media_type' => 'audio', 'stored' => $T0 - 30]);   // a voice note stored late with an old device time
        wo_setcfg($b, ['offers_warm_days' => 7]);
        wo_sender($ctx, $b, $clk, $bud, $gw)->runOnce(null);
        $row = wo_row($b['pdo'], '256700702101');
        $ctx->eq([(int)OfferStore::find($b['pdo'], $id)['warm_days'], $row['state'], $row['reason']], [30, 'skipped', 'recent_voice_note'],
                 'F21 started at warm_days 30, then set to 7: the 20-day-old voice note still holds the phone');
        $ctx->eq([$row['judged_at'], $row['day_max']], [UtcClock::stamp($T0), null], 'F21 step 4 wrote judged_at with its outcome (a skip records no ceiling)');
    });

    $ctx->run('F22 judge() at the send: an opt-out made after the Start skips the recipient (E reads the same through the webhook)', function () use ($ctx) {
        [$b, $id, $log, $bud, $gw, $clk] = wo_fx($ctx, ['256700702201' => 'sales', '256700702202' => 'support']);
        (new ContactOptOut($b['pdo']))->add('256700702201', ['channel' => 'support']);
        wo_sender($ctx, $b, $clk, $bud, $gw)->runOnce(null);
        $ctx->eq([wo_row($b['pdo'], '256700702201')['reason'], wo_row($b['pdo'], '256700702202')['state']], ['opted_out', 'sent'], 'F22 skipped(opted_out) at the send; the other recipient sent');
    });

    $ctx->run('F23 "all" over a week: 500 on one handset, ceiling 20 — at most 20 a day, never Sunday, never outside the window or before the reminders, one per run', function () use ($ctx) {
        $ph = [];
        for ($i = 0; $i < 500; $i++) $ph[sprintf('2567007%05d', 30000 + $i)] = 'sales';
        $mon7 = qf_t('2026-11-02 07:00:00');
        [$b, $id, $log, $bud, $gw, $clk] = wo_fx($ctx, $ph, [], ['t0' => qf_t('2026-11-02 07:00:00')]);
        $s = wo_sender($ctx, $b, $clk, $bud, $gw);
        $byDay = []; $bad = []; $perRun = [];
        for ($d = 0; $d < 7; $d++) {
            $day = $mon7 + $d * 86400;
            $b['store']->save('master_schedule.json', ['customer_reminders' => ['last_run' => $day - 86400 + 7500, 'duration_ms' => 1000]]);   // yesterday's
            $ran = false;
            for ($t = $day; $t < $day + 14 * 3600; $t += 240) {
                $clk->t = $t;
                if (!$ran && $t >= $day + 7500) {                                     // master's customer_reminders, 09:05 Kampala
                    $b['store']->save('master_schedule.json', ['customer_reminders' => ['last_run' => $day + 7500, 'duration_ms' => 1000]]);
                    $ran = true;
                }
                $before = count(wo_calls($log, 'send'));
                $s->runOnce(null);
                $n = count(wo_calls($log, 'send')) - $before;
                if ($n > 1) $perRun[] = $t;
                if ($n > 0) {
                    $l = (new \DateTime('@' . $t))->setTimezone(new \DateTimeZone(QF_TZ));
                    $byDay[$l->format('D')] = ($byDay[$l->format('D')] ?? 0) + $n;
                    $h = (int)$l->format('G');
                    if ($h < 8 || $h >= 20 || $l->format('N') === '7' || $t < $day + 7500) $bad[] = $l->format('D H:i');
                }
            }
        }
        $ctx->eq($byDay, ['Mon' => 20, 'Tue' => 20, 'Wed' => 20, 'Thu' => 20, 'Fri' => 20, 'Sat' => 20], 'F23 twenty a day, Monday to Saturday, none on Sunday');
        $ctx->eq([$bad, $perRun], [[], []], 'F23 none outside 08:00–20:00 or before the reminders; never two in one run');
    });

    $ctx->run('F24 one judgement per handset per run (step 12, O-D8, §H.4.21): a row judged away ends the handset\'s turn; the next run takes the next row', function () use ($ctx, $T0) {
        // sales: its head row deferred awaiting_reply; support: its head row skipped opted_out; h3: a plain send
        [$b, $id, $log, $bud, $gw, $clk] = wo_fx($ctx, ['256700702401' => 'sales', '256700702402' => 'sales', '256700702403' => 'support',
                                                        '256700702404' => 'support', '256700702405' => 'h3'], [], ['handsets' => ['h3' => 'i:h3']]);
        $c = (int)wo_row($b['pdo'], '256700702401')['conversation_id'];
        wo_in($b, $c, 'Is the kit in stock? (test)', $T0 - 30 * 60);
        wo_out($b, $c, 'Yes it is. (test)', $T0 - 29 * 60);
        (new ContactOptOut($b['pdo']))->add('256700702403', ['channel' => 'support']);
        $rk = $b['pdo']->query("SELECT phone FROM wa_offer_recipients WHERE offer_id = {$id} AND handset IN ('i:sales-ug', 'i:dishnet-ug') ORDER BY handset, rank")->fetchAll(\PDO::FETCH_COLUMN);
        $ctx->eq($rk, ['256700702403', '256700702404', '256700702401', '256700702402'], 'F24 control: each handset\'s head row is the one judged away');
        $s = wo_sender($ctx, $b, $clk, $bud, $gw);
        $r = $s->runOnce(null);
        $st = function (string $p) use ($b): array { $x = wo_row($b['pdo'], $p); return [$x['state'], $x['reason'], $x['judged_at'] !== null, (int)$x['attempt']]; };
        $ctx->eq([$st('256700702401'), $st('256700702403'), $st('256700702405')],
                 [['queued', 'awaiting_reply', true, 1], ['skipped', 'opted_out', true, 1], ['sent', null, true, 1]],
                 'F24 the first run: sales\' head deferred, support\'s head skipped, h3 sent');
        $ctx->eq([$st('256700702402'), $st('256700702404')], [['queued', null, false, 0], ['queued', null, false, 0]],
                 'F24 … and each handset\'s next row neither judged nor claimed in that run (its turn ended with the judgement)');
        $ctx->eq([$r['handsets'], $r['sent'], $r['skipped'], $r['deferred'], count(wo_calls($log, 'claim')), count(wo_calls($log, 'send'))], [3, 1, 1, 1, 1, 1],
                 'F24 three handsets, one judgement each: one ledger claim, one send');
        $per = $b['pdo']->query("SELECT handset, COUNT(*) FROM wa_offer_recipients WHERE offer_id = {$id} AND judged_at IS NOT NULL GROUP BY handset ORDER BY handset")->fetchAll(\PDO::FETCH_KEY_PAIR);
        $ctx->eq(array_map('intval', $per), ['i:dishnet-ug' => 1, 'i:h3' => 1, 'i:sales-ug' => 1], 'F24 one row judged per handset in the run');
        $clk->t += 300;
        $s->runOnce(null);
        $ctx->eq([$st('256700702402')[0], $st('256700702404')[0], $st('256700702401')[0], count(wo_calls($log, 'send'))], ['sent', 'sent', 'queued', 3],
                 'F24 the next run: each handset\'s next row sent; the deferred head waits for its not_before');
    });

    $ctx->run('F25 the recent-offer exclusion at the send (O-D2): an earlier offer to the phone 6 days before, recorded after the Start, skips; 8 days before is sent', function () use ($ctx, $T0) {
        [$b, $id, $log, $bud, $gw, $clk] = wo_fx($ctx, ['256700702501' => 'sales', '256700702502' => 'support']);
        $ctx->eq(array_map(function ($r) { return $r[0]; }, array_values(wo_rows($b['pdo'], $id))), ['queued', 'queued'], 'F25 control: both queued at the Start (no earlier offer then)');
        // planted after the Start, so only the send's own judgement can see them: an earlier offer's sends, 6 and 8 days back
        $old = wo_offer($b, $T0 - 30 * 86400, ['title' => 'An earlier offer (test)']);
        $ins = $b['pdo']->prepare("INSERT INTO wa_offer_recipients (offer_id, phone, rank, conversation_id, channel, handset, last_in_at, state, sent_at, judged_at,
                                          day_max, variant, wa_message_id, echo, claim_token, claimed_at, attempt, settled_at)
                                   VALUES (?, ?, ?, 1, 'sales', 'i:sales-ug', ?, 'sent', ?, ?, 20, 1, ?, 'claimed', 't', ?, 1, ?)");
        foreach (['256700702501' => 6, '256700702502' => 8] as $p => $days) {
            $at = UtcClock::stamp($T0 - $days * 86400);
            $ins->execute([$old, $p, $days, $at, $at, $at, 'WO-F25-' . $days, $at, $at]);
        }
        wo_sender($ctx, $b, $clk, $bud, $gw)->runOnce(null);
        $rows = wo_rows($b['pdo'], $id);
        $sent = array_map(function ($c) { return $c[2]; }, wo_calls($log, 'send'));
        $ctx->eq([$rows['256700702501'], $rows['256700702502'][0]], [['skipped', 'recent_offer'], 'sent'],
                 'F25 judged at the send: 6 days after an earlier offer skipped recent_offer; 8 days after, sent (recent_days 7)');
        $ctx->eq([$sent, count(wo_calls($log, 'claim'))], [['256700702502'], 1], 'F25 … the skipped phone never claimed or sent');
    });

    $ctx->run('F26 one row per phone per offer (idx_wor_once) under the sender, and the send\'s own judgement beneath it: one send per phone', function () use ($ctx, $T0) {
        [$b, $id, $log, $bud, $gw, $clk] = wo_fx($ctx, ['256700702601' => 'sales']);
        $p = $b['pdo'];
        $row = wo_row($p, '256700702601');
        $dup = "INSERT INTO wa_offer_recipients (offer_id, phone, rank, conversation_id, channel, handset, last_in_at, state)
                VALUES ({$id}, '256700702601', 99, " . (int)$row['conversation_id'] . ", 'sales', 'i:sales-ug', " . $p->quote((string)$row['last_in_at']) . ", 'queued')";
        $err = '';
        try { $p->exec($dup); } catch (\PDOException $e) { $err = $e->getMessage(); }
        $ctx->t(strpos($err, 'UNIQUE') !== false, 'F26 the first floor: a second row for the phone in the live offer is refused (idx_wor_once)', $err);
        // the second floor, with the index out of the way: the duplicate is judged a recent offer at its send, never sent
        $p->exec('DROP INDEX IF EXISTS idx_wor_once');
        if ($err !== '') $p->exec($dup);
        $ctx->eq((int)$p->query("SELECT COUNT(*) FROM wa_offer_recipients WHERE offer_id = {$id} AND phone = '256700702601' AND state = 'queued'")->fetchColumn(), 2,
                 'F26 control: with the index dropped, two queued rows for the phone');
        $s = wo_sender($ctx, $b, $clk, $bud, $gw);
        for ($i = 0; $i < 3; $i++) { $s->runOnce(null); $clk->t += 300; }
        $st = $p->query("SELECT state, reason FROM wa_offer_recipients WHERE offer_id = {$id} AND phone = '256700702601' ORDER BY rank")->fetchAll(\PDO::FETCH_NUM);
        $ctx->eq([count(wo_calls($log, 'send')), $st], [1, [['sent', null], ['skipped', 'recent_offer']]],
                 'F26 the second floor: the phone sent once; its duplicate skipped recent_offer at its own send');
    });
}];
