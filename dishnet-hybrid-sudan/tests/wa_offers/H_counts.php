<?php
declare(strict_types=1);
/**
 * Section H — the per-offer counts and the reply and STOP marks (5.18.104, root docs/73 §H.4.12, §H.4.16 H). TEST ONLY.
 *
 * The one query; replied within 72 h (71 h counted, 73 h not); a STOP-only reply counted as STOP and not as replied; an
 * opt-out created in the window a STOP; each mark written once, so a later prune or a later reply never moves it; echo_late.
 */
$WO_SECTIONS['H'] = ['title' => 'Per-offer counts and the 72-hour marks (§H.4.12)', 'run' => function (QfCtx $ctx) {
    $T0 = wo_t0();

    $ctx->run('H01 the marks: replied within 72 h (71 h yes, 73 h no), STOP-only is STOP not replied, an opt-out is STOP, once each', function () use ($ctx, $T0) {
        $b = wo_book($ctx, wo_cfg(), 'h');
        $p = $b['pdo'];
        $o = wo_offer($b, $T0);
        $p->prepare("UPDATE wa_offers SET state = 'running', live_slot = 1, started_at = ? WHERE id = ?")->execute([UtcClock::stamp($T0), $o]);
        $ins = $p->prepare("INSERT INTO wa_offer_recipients (offer_id, phone, rank, conversation_id, channel, handset, last_in_at, state, sent_at, judged_at, day_max, variant,
                                wa_message_id, echo, claim_token, claimed_at, attempt) VALUES (?, ?, ?, ?, 'sales', 'i:sales-ug', ?, ?, ?, ?, 20, 1, ?, ?, 't', ?, 1)");
        $sent = UtcClock::stamp($T0);
        $mk = function (string $ph, int $rank, string $state = 'sent', string $echo = 'claimed') use ($b, $ins, $o, $sent, $T0): int {
            $c = wo_conv($b, $ph, 'sales');
            $ins->execute([$o, $ph, $rank, $c, UtcClock::stamp($T0 - 86400), $state, $sent, $sent, $state === 'sent' ? 'WO-H-' . $ph : null, $echo, $sent]);
            return $c;
        };
        $c1 = $mk('256700910101', 1); wo_in($b, $c1, 'Yes please, tell me more (test).', $T0 + 71 * 3600);
        $c2 = $mk('256700910102', 2); wo_in($b, $c2, 'Late answer (test).', $T0 + 73 * 3600);
        $c3 = $mk('256700910103', 3); wo_in($b, $c3, 'STOP', $T0 + 2 * 3600);
        $mk('256700910104', 4);
        $p->prepare("INSERT INTO contact_optouts (phone, channel, scope, reason, source, created_at) VALUES ('0700910104', '*', 'proactive', 'staff', 'admin', ?)")->execute([UtcClock::stamp($T0 + 5 * 3600)]);
        $mk('256700910105', 5, 'uncertain', 'late');
        $mk('256700910106', 6);
        $n = OfferStore::marksPass($p, TenantProfile::current($b['cfg'], $b['data']), $T0 + 72 * 3600 + 1800);
        $get = function (string $ph) use ($p): array { return $p->query("SELECT replied_at, stop_at FROM wa_offer_recipients WHERE phone = '{$ph}'")->fetch(\PDO::FETCH_NUM); };
        $ctx->eq($get('256700910106'), [null, null], 'H01 control: a recipient who wrote nothing has no mark');
        $ctx->eq($get('256700910101'), [UtcClock::stamp($T0 + 71 * 3600), null], 'H01 a reply 71 h after the send: replied_at');
        $ctx->eq($get('256700910102'), [null, null], 'H01 a reply 73 h after (stored already): no mark');
        $ctx->eq($get('256700910103'), [UtcClock::stamp($T0 + 2 * 3600), UtcClock::stamp($T0 + 2 * 3600)], 'H01 a STOP reply: both marks (counted under STOP)');
        $ctx->eq($get('256700910104'), [null, UtcClock::stamp($T0 + 5 * 3600)], 'H01 an opt-out created in the window, typed in another form: stop_at');
        $ctx->eq($n, 4, 'H01 four marks written');
        wo_in($b, $c1, 'Another later message (test).', $T0 + 71 * 3600 + 30);
        OfferStore::marksPass($p, TenantProfile::current($b['cfg'], $b['data']), $T0 + 72 * 3600 + 1900);
        $ctx->eq($get('256700910101')[0], UtcClock::stamp($T0 + 71 * 3600), 'H01 a mark is written once: a later pass never moves replied_at');
        $c = OfferStore::counts($p, $o);
        $ctx->eq([$c['sent'], $c['maybe_sent'], $c['replied_72h'], $c['stop_72h'], $c['echo_late'], $c['queued']], [5, 1, 1, 2, 1, 0],
                 'H01 the counts: 5 sent, 1 may have been sent, replied 1 (a STOP reply not counted as replied), STOP 2, echo_late 1');
    });
}];
