<?php
declare(strict_types=1);
/**
 * Section OH of tests/test_quote_followup.php — the offers hold and the phone-level takeover (5.18.104, root docs/73
 * §H.2.7.1, §H.2.10.10, §H.2.14 OH; rulings R3, R18). TEST ONLY.
 *
 * Built in stage B1: the phone-level half, HumanTakeover::phoneSince() and phonesSince() — parity with since() on one
 * fixture, staff conversations left out, the upper bound on every clause by each source's storage time, a gmdate('c')
 * argument normalised, an unreadable stamp answering unreadable_time from every form. Stage B2b adds the offers-hold half,
 * QuoteChase::offerHolds()/offerHold() (§H.2.10.10; QM64-QM80): each reason on any conversation of the phone, the uncertain
 * day-7 chase held 48 h from its claimed_at, a not_sent claim never, the past-moment form bounded by each source's storage
 * time (the tracker's created_at, the follow-up's opened_at, the ledger's claimed_at and settled_at, the quotation row's
 * created_at), and a read failure throwing, never "no hold".
 */

$QF_SECTIONS['OH'] = ['title' => 'The offers hold and the phone-level takeover (ruling R3)', 'run' => function (QfCtx $ctx) {
    $tree = $ctx->tree();
    foreach (['StoreInterface', 'SqliteStore', 'UtcClock', 'HumanTakeover'] as $l) require_once $tree . '/lib/' . $l . '.php';
    // qf_conv(), qf_msg(), qf_mark() and qf_escalation() are F_stops.php's: the runner loads every section file before it runs any.

    $ctx->run('OH01 parity: phoneSince() and phonesSince() give since()\'s answer on one fixture', function () use ($ctx) {
        $b = $ctx->book(QF_UG, 'oh'); $p = $b['pdo'];
        $S = '2026-11-02 10:00:00'; $A = '2026-11-02 11:00:00';
        $c1 = qf_conv($p, '256772500901', 'support'); $c2 = qf_conv($p, '256772500901', 'sales');
        qf_msg($p, $c2, ['agent_name' => 'Team', 'wa_message_id' => 'WA-OH1', 'sent_at' => $A, 'created_at' => $A]);
        $c3 = qf_conv($p, '256772500902'); qf_msg($p, $c3, ['agent_name' => 'Sandbox Staff', 'event_key' => 'wa_staff_reply', 'sent_at' => $A, 'created_at' => $A]);
        $c4 = qf_conv($p, '256772500903'); qf_mark($p, 'out', '256772500903', 'support', 'WA-OH1-M', $A);
        $c5 = qf_conv($p, '256772500904'); qf_escalation($p, $c5, $A);
        $c6 = qf_conv($p, '256772500905', 'support', null, 'human_active');   // a sticky state, nothing since: no clause names it
        $want = [];
        foreach (['256772500901' => [$c1, $c2], '256772500902' => [$c3], '256772500903' => [$c4], '256772500904' => [$c5], '256772500905' => [$c6]] as $ph => $ids) {
            $want[$ph] = HumanTakeover::since($p, (string)$ph, $ids, $S);
        }
        $ctx->eq($want, ['256772500901' => 'team_message', '256772500902' => 'inbox_reply', '256772500903' => 'handset_media', '256772500904' => 'escalated', '256772500905' => null],
                 'OH01 control: since() on each phone\'s conversations');
        $got = [];
        foreach (array_keys($want) as $ph) $got[$ph] = HumanTakeover::phoneSince($p, (string)$ph, $S);
        $ctx->eq($got, $want, 'OH01 phoneSince() on every conversation of the phone gives the same');
        $bulk = HumanTakeover::phonesSince($p, $S);
        $ctx->eq($bulk, array_filter($want, function ($v) { return $v !== null; }), 'OH01 phonesSince() gives the same for every phone at once; a phone with none is absent');
    });

    $ctx->run('OH02 staff conversations are left out (HT17)', function () use ($ctx) {
        $b = $ctx->book(QF_UG, 'oh'); $p = $b['pdo'];
        $c = qf_conv($p, '256772500911', 'support', 'staff');
        qf_msg($p, $c, ['agent_name' => 'Team', 'wa_message_id' => 'WA-OH2', 'sent_at' => '2026-11-02 11:00:00']);
        qf_mark($p, 'out', '256772500911', 'support', 'WA-OH2-M', '2026-11-02 11:00:00');
        $ctx->eq([HumanTakeover::phoneSince($p, '256772500911', '2026-11-02 10:00:00'), HumanTakeover::phonesSince($p, '2026-11-02 10:00:00')], [null, []],
                 'OH02 a colleague\'s own number (category staff): neither form names it');
        $c2 = qf_conv($p, '256772500911', 'sales');
        qf_msg($p, $c2, ['agent_name' => 'Team', 'wa_message_id' => 'WA-OH2b', 'sent_at' => '2026-11-02 11:00:00']);
        $ctx->eq(HumanTakeover::phonesSince($p, '2026-11-02 10:00:00'), ['256772500911' => 'team_message'], 'OH02 control: the same phone on a customer conversation is named');
    });

    $ctx->run('OH03 the upper bound, on every clause, by each source\'s storage time (HT16, HT20; ruling R18)', function () use ($ctx) {
        $b = $ctx->book(QF_UG, 'oh'); $p = $b['pdo'];
        $S = '2026-11-02 10:00:00'; $U = '2026-11-02 12:00:00'; $late = '2026-11-02 12:30:00'; $in = '2026-11-02 11:00:00';
        $cases = [
            'team' => function (int $c, string $ph, string $stored) use ($p, $in) { qf_msg($p, $c, ['agent_name' => 'Team', 'wa_message_id' => 'WA-' . $ph, 'sent_at' => $in, 'created_at' => $stored]); },
            'inbox' => function (int $c, string $ph, string $stored) use ($p, $in) { qf_msg($p, $c, ['agent_name' => 'Staff Name', 'event_key' => 'wa_staff_reply', 'sent_at' => $in, 'created_at' => $stored]); },
            'mark' => function (int $c, string $ph, string $stored) use ($p) { qf_mark($p, 'out', $ph, 'support', 'WA-M-' . $ph, $stored); },
            'escalation' => function (int $c, string $ph, string $stored) use ($p) { qf_escalation($p, $c, $stored); },
        ];
        $n = 20; $lateGot = []; $inGot = [];
        foreach ($cases as $what => $plant) {
            $phL = '2567725009' . ($n++); $cL = qf_conv($p, $phL); $plant($cL, $phL, $late);
            $phI = '2567725009' . ($n++); $cI = qf_conv($p, $phI); $plant($cI, $phI, $in);
            $lateGot[$what] = [HumanTakeover::phoneSince($p, $phL, $S, $U), HumanTakeover::phonesSince($p, $S, $U)[$phL] ?? null];
            $inGot[$what] = HumanTakeover::phoneSince($p, $phI, $S, $U) !== null && (HumanTakeover::phonesSince($p, $S, $U)[$phI] ?? null) !== null;
        }
        $ctx->eq($lateGot, ['team' => [null, null], 'inbox' => [null, null], 'mark' => [null, null], 'escalation' => [null, null]],
                 'OH03 a row (its sent_at inside, its created_at after the bound), a mark and an escalation stored after the bound: not counted by either form');
        $ctx->eq($inGot, ['team' => true, 'inbox' => true, 'mark' => true, 'escalation' => true], 'OH03 control: each stored inside the bound is counted by both');
    });

    $ctx->run('OH04 the time arguments: normalised, and unreadable answers unreadable_time for every covered phone (HT18)', function () use ($ctx) {
        $b = $ctx->book(QF_UG, 'oh'); $p = $b['pdo'];
        $c = qf_conv($p, '256772500941'); qf_msg($p, $c, ['agent_name' => 'Team', 'wa_message_id' => 'WA-OH4', 'sent_at' => '2026-11-02 10:30:00']);
        qf_conv($p, '256772500942'); qf_conv($p, '256772500943', 'support', 'staff');
        $iso = gmdate('c', (int)strtotime('2026-11-02 10:00:00 UTC'));
        $ctx->eq([HumanTakeover::phoneSince($p, '256772500941', $iso), HumanTakeover::phonesSince($p, $iso)], ['team_message', ['256772500941' => 'team_message']],
                 'OH04 a gmdate(\'c\') moment is normalised in both forms');
        $ctx->eq([HumanTakeover::phoneSince($p, '256772500941', 'garbage'), HumanTakeover::phoneSince($p, '256772500941', '2026-11-02 10:00:00', 'garbage')],
                 ['unreadable_time', 'unreadable_time'], 'OH04 phoneSince(): an unreadable since or until answers unreadable_time');
        $bulk = HumanTakeover::phonesSince($p, 'garbage'); ksort($bulk);
        $ctx->eq($bulk, ['256772500941' => 'unreadable_time', '256772500942' => 'unreadable_time'],
                 'OH04 phonesSince(): every covered phone (staff left out) maps to unreadable_time, so a caller that leaves each out leaves out every customer');
    });

    // ── the offers hold (§H.2.10.10; ruling R3), stage B2b ───────────────────────────────────────────────────────────
    require_once $tree . '/lib/QuoteChase.php';
    $M = '2026-11-04 10:00:00';   // the moment an offer is judged, UTC
    $at = function (int $hours) use ($M): string { return gmdate('Y-m-d H:i:s', strtotime($M . ' UTC') + $hours * 3600); };
    $tracker = function (\PDO $p, string $ref, string $phone, int $conv, array $o) use ($at): void {
        $o += ['sent_at' => $at(-30), 'created_at' => $at(-29), 'status' => 'open', 'status_at' => null];
        $p->prepare("INSERT INTO quote_tracker (quote_key, quote_ref, phone, evidence_msg_id, evidence_event, made_by, sent_at, sent_conv_id, sent_channel,
                                                valid_until, status, status_at, created_at)
                     VALUES (?, ?, ?, 1, 'quote_manual', 'manual', ?, ?, 'support', '2026-11-30', ?, ?, ?)")
          ->execute(['ref:' . $ref, $ref, $phone, $o['sent_at'], $conv, $o['status'], $o['status_at'], $o['created_at']]);
    };
    $qfu = function (\PDO $p, int $conv, string $phone, string $opened, ?string $closed = null, string $key = ''): void {
        $p->prepare("INSERT INTO followups (conversation_id, channel, phone, last_customer_at, kind, quote_key, opened_at, closed_at, close_reason)
                     VALUES (?, 'support', ?, ?, 'quote', ?, ?, ?, ?)")
          ->execute([$conv, $phone, $opened, $key === '' ? null : $key, $opened, $closed, $closed === null ? null : 'replied']);
    };
    $claim = function (\PDO $p, string $phone, string $claimedAt, ?string $outcome, ?string $settledAt = null, string $ref = '') : void {
        $p->prepare("INSERT INTO wa_auto_sends (handset, kind, ref, mode, proactive, phone, local_day, gap_s, claimed_at, outcome, settled_at,
                                                win_from, win_to, cap_day, cap_hour)
                     VALUES ('i:dishnet-ug', 'quote', ?, 'claim', 1, ?, '2026-11-03', 60, ?, ?, ?, 8, 20, 60, 15)")
          ->execute([$ref === '' ? 'fu:' . mt_rand(1000, 999999) . ':3' : $ref, $phone, $claimedAt, $outcome, $settledAt]);
    };

    $ctx->run('OH05 each reason, on any conversation of the phone; offerHolds() is offerHold() for every phone (QM64, QM74)', function () use ($ctx, $M, $at, $tracker, $qfu, $claim) {
        $b = $ctx->book(QF_UG, 'oh'); $p = $b['pdo'];
        $a = qf_conv($p, '256772502601', 'support'); qf_conv($p, '256772502601', 'sales');
        $tracker($p, 'Q-OH1', '256772502601', $a, []);
        $bs = qf_conv($p, '256772502602', 'support'); $bsales = qf_conv($p, '256772502602', 'sales');
        $tracker($p, 'Q-OH2', '256772502602', $bs, ['status' => 'expired', 'status_at' => $at(-5)]);
        $qfu($p, $bsales, '256772502602', $at(-20));
        $c = qf_conv($p, '256772502603', 'support');
        $tracker($p, 'Q-OH3', '256772502603', $c, ['status' => 'expired', 'status_at' => $at(-5)]);
        $claim($p, '256772502603', $at(-10), 'sent', $at(-10));
        qf_conv($p, '256772502604', 'support'); $dsales = qf_conv($p, '256772502604', 'sales');
        qf_qmsg($p, $dsales, 'quote_manual', qf_ref_text('Q-OH4'), $at(-2), $at(-2));
        $e = qf_conv($p, '256772502605', 'support');
        qf_msg($p, $e, ['body' => 'Thank you for your order (test)', 'agent_name' => 'DishNet Plugin', 'event_key' => 'quote_manual', 'sent_at' => $at(-2), 'created_at' => $at(-2)]);
        $one = [];
        foreach (['256772502601', '256772502602', '256772502603', '256772502604', '256772502605'] as $ph) $one[$ph] = QuoteChase::offerHold($p, $ph, $M);
        $ctx->eq($one, ['256772502601' => 'quote_open', '256772502602' => 'quote_followup_open', '256772502603' => 'quote_sent_48h',
                        '256772502604' => 'quote_text_48h', '256772502605' => ''],
                 'OH05 an open tracked quotation; an open quote follow-up on the phone\'s other conversation; a quote claimed within 48 h; the quotation\'s own row on another conversation; a text without a quotation marker holds nothing');
        $ctx->eq(QuoteChase::offerHolds($p, $M), array_filter($one, function ($v) { return $v !== ''; }), 'OH05 offerHolds(): the same, for every phone at once');
        $ctx->eq(QuoteChase::offerHold($p, '056772502601', $M) . '|' . QuoteChase::offerHold($p, '211772502601', $M), '|',
                 'OH05 never the last nine digits: another number sharing them is not held');
    });

    $ctx->run('OH06 an uncertain day-7 chase holds 48 h from its claimed_at, and not after; not_sent never; in flight does (QM66, QM69)', function () use ($ctx, $M, $at, $tracker, $qfu, $claim) {
        $b = $ctx->book(QF_UG, 'oh'); $p = $b['pdo'];
        $c = qf_conv($p, '256772502611');
        $tracker($p, 'Q-OH11', '256772502611', $c, ['sent_at' => $at(-200), 'created_at' => $at(-199), 'status' => 'expired', 'status_at' => $at(-30)]);
        $qfu($p, $c, '256772502611', $at(-199), $at(-30), 'ref:Q-OH11');
        $claim($p, '256772502611', $at(-47), 'uncertain', $at(-47));
        $c2 = qf_conv($p, '256772502612'); $claim($p, '256772502612', $at(-10), 'not_sent', $at(-10));
        $c3 = qf_conv($p, '256772502613'); $claim($p, '256772502613', $at(-1), null);
        $ctx->eq([QuoteChase::offerHold($p, '256772502611', $M), QuoteChase::offerHold($p, '256772502611', $at(2)),
                  QuoteChase::offerHold($p, '256772502612', $M), QuoteChase::offerHold($p, '256772502613', $M)],
                 ['quote_sent_48h', '', '', 'quote_sent_48h'],
                 'OH06 uncertain, no followup_sends row, its tracker expired and its follow-up closed: held 47 h after its claim, not at 49 h; a not_sent claim never; a claim in flight does');
    });

    $ctx->run('OH07 the past-moment form, each source bounded by its storage time (ruling R18; QM65, QM72, QM75, QM80)', function () use ($ctx, $M, $at, $tracker, $qfu, $claim) {
        $b = $ctx->book(QF_UG, 'oh'); $p = $b['pdo'];
        $c1 = qf_conv($p, '256772502621'); $tracker($p, 'Q-OH21', '256772502621', $c1, ['status' => 'answered', 'status_at' => $at(1)]);
        $c2 = qf_conv($p, '256772502622'); $claim($p, '256772502622', $at(-1), 'not_sent', $at(1));
        $c3 = qf_conv($p, '256772502623'); $qfu($p, $c3, '256772502623', $at(1));
        $c4 = qf_conv($p, '256772502624'); $qfu($p, $c4, '256772502624', $at(-1), $at(1));
        $c5 = qf_conv($p, '256772502625'); qf_qmsg($p, $c5, 'quote_manual', qf_ref_text('Q-OH25'), gmdate('Y-m-d H:i:s', strtotime($M . ' UTC') - 120), gmdate('Y-m-d H:i:s', strtotime($M . ' UTC') - 120));
        $tracker($p, 'Q-OH25', '256772502625', $c5, ['sent_at' => gmdate('Y-m-d H:i:s', strtotime($M . ' UTC') - 120), 'created_at' => $at(2), 'status' => 'answered', 'status_at' => $at(3)]);
        $c6 = qf_conv($p, '256772502626'); qf_qmsg($p, $c6, 'quote_manual', qf_ref_text('Q-OH26'), $at(-49), $at(-49));
        $c7 = qf_conv($p, '256772502627'); $tracker($p, 'Q-OH27', '256772502627', $c7, ['sent_at' => $at(-50), 'created_at' => $at(1)]);
        $c8 = qf_conv($p, '256772502628'); $tracker($p, 'Q-OH28', '256772502628', $c8, ['sent_at' => $at(-50), 'created_at' => $at(-49)]);
        $c9 = qf_conv($p, '256772502629'); qf_qmsg($p, $c9, 'quote_manual', qf_ref_text('Q-OH29'), $at(-1), $at(1));
        $h = function (string $ph, string $when) use ($p): string { return QuoteChase::offerHold($p, $ph, $when); };
        $ctx->eq(['answered after' => [$h('256772502621', $M), $h('256772502621', $at(2))],
                  'not_sent after' => [$h('256772502622', $M), $h('256772502622', $at(2))],
                  'opened after' => $h('256772502623', $M), 'opened before, closed after' => $h('256772502624', $M),
                  'quotation 2 min before' => $h('256772502625', $M), 'quotation 49 h before' => $h('256772502626', $M),
                  'tracker created after' => $h('256772502627', $M), 'tracker created before' => $h('256772502628', $M),
                  'quotation stored after' => $h('256772502629', $M)],
                 ['answered after' => ['quote_open', ''], 'not_sent after' => ['quote_sent_48h', ''], 'opened after' => '',
                  'opened before, closed after' => 'quote_followup_open', 'quotation 2 min before' => 'quote_text_48h', 'quotation 49 h before' => '',
                  'tracker created after' => '', 'tracker created before' => 'quote_open', 'quotation stored after' => ''],
                 'OH07 held at the moment as the sender could read it then: a status, a not_sent settle and a close after it do not undo the hold; a follow-up opened, a tracker row created or a quotation row stored after it hold nothing; the quotation 2 min before holds by its own row though the tracker derived its row later; 49 h before, nothing');
    });

    $ctx->run('OH08 the moment normalised; unreadable answers unreadable_time; a read failure throws, never "no hold" (QM67)', function () use ($ctx, $M, $at, $tracker) {
        $b = $ctx->book(QF_UG, 'oh'); $p = $b['pdo'];
        $c = qf_conv($p, '256772502631'); $tracker($p, 'Q-OH31', '256772502631', $c, []);
        qf_conv($p, '256772502632'); qf_conv($p, '256772502633', 'support', 'staff');
        $ctx->eq([QuoteChase::offerHold($p, '256772502631', gmdate('c', strtotime($M . ' UTC'))), QuoteChase::offerHold($p, '256772502631', 'garbage')],
                 ['quote_open', 'unreadable_time'], 'OH08 a gmdate(\'c\') moment is normalised; an unreadable one answers unreadable_time');
        $bulk = QuoteChase::offerHolds($p, 'garbage'); ksort($bulk);
        $ctx->eq($bulk, ['256772502631' => 'unreadable_time', '256772502632' => 'unreadable_time'], 'OH08 the bulk form: every covered phone (staff left out) unreadable_time');
        $p->exec('DROP TABLE wa_auto_sends');
        $threw = [false, false];
        try { QuoteChase::offerHolds($p, $M); } catch (\Throwable $e) { $threw[0] = true; }
        try { QuoteChase::offerHold($p, '256772502632', $M); } catch (\Throwable $e) { $threw[1] = true; }
        $ctx->eq($threw, [true, true], 'OH08 a read that fails (the ledger gone) throws from both forms');
    });
}];
