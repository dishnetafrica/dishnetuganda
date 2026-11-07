<?php
declare(strict_types=1);
/**
 * Section D — the audience: who, from which handset, and every exclusion (5.18.104, root docs/73 §H.4.8, §H.4.16 D;
 * rulings R3, R13, R18). TEST ONLY.
 *
 * Each exclusion row of §H.4.8 positively (left out, and for the reason named) and negatively (kept); the one phone form;
 * the chosen conversation, ties, the order and the size cut; and the store-free form on a query_only connection with
 * storedRows() over it. The audience is built with the copy's real EvolutionOfferGateway over the configuration's
 * instances, except where a salesperson's or a partner's number needs a routing the fixture names (the scripted gateway).
 */

/** The audience at T0: [build answer, the eligible phones]. */
function wo_aud(array $b, array $retailers = [], array $kyc = [], string $size = 'all', ?OfferGateway $gw = null, ?int $now = null, ?\PDO $pdo = null): array
{
    $a = OfferAudience::build($pdo ?? $b['pdo'], $b['cfg'], $b['data'], $retailers, $kyc, $size, $now ?? wo_t0(), $gw);
    return [$a, array_map(function ($e) { return $e['phone']; }, $a['eligible'])];
}

/** The reason the lists leave $phone out at T0, '' when none (the list exclusions and the number rule). */
function wo_why(array $b, $phone, array $retailers = [], array $kyc = []): string
{
    return OfferAudience::excludedBy(OfferAudience::lists($b['pdo'], $b['cfg'], $b['data'], $retailers, $kyc, null, null, wo_t0()), (string)$phone);
}

/** A ledger row as Part F's senders leave it: a record, or a claim (with the limits it applied), at $t. */
function wo_ledger(\PDO $pdo, string $kind, string $ref, ?string $phone, int $t, ?string $outcome, string $mode = 'record', string $handset = 'i:sales-ug'): void
{
    $proactive = in_array($kind, WaSendBudget::PROACTIVE, true) ? 1 : 0;
    $claim = $mode === 'claim';
    $pdo->prepare('INSERT INTO wa_auto_sends (handset, kind, ref, try, mode, proactive, phone, local_day, gap_s, claimed_at, outcome, settled_at,
                                              win_from, win_to, cap_day, cap_hour)
                   VALUES (?, ?, ?, 1, ?, ?, ?, ?, 60, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$handset, $kind, $ref, $mode, $proactive, $phone, WaSendBudget::localDay($t), UtcClock::stamp($t), $outcome,
                   $outcome === null ? null : UtcClock::stamp($t + 5), $claim ? 8 : null, $claim ? 20 : null, $claim ? 60 : null, $claim ? 15 : null]);
}

$WO_SECTIONS['D'] = ['title' => 'The audience and every exclusion (§H.4.8; rulings R3, R13, R18)', 'run' => function (QfCtx $ctx) {
    $T0 = wo_t0();
    $D = 86400;

    $ctx->run('D01 warm customers on Evolution numbers: eligible, warmest first, the handset in the ledger form', function () use ($ctx, $T0, $D) {
        $b = wo_book($ctx, wo_cfg(), 'd');
        wo_warm($b, '256700500101', $T0, 3 * $D);
        wo_warm($b, '256700500102', $T0, 1 * $D, 'support');
        wo_warm($b, '256700500103', $T0, 2 * $D, 'account');
        [$a, $ph] = wo_aud($b);
        $ctx->eq($ph, ['256700500102', '256700500103', '256700500101'], 'D01 control: three warm customers, the most recent chat first');
        $ctx->eq($a['by_handset'], ['i:dishnet-ug' => 2, 'i:sales-ug' => 1], 'D01 support and account share one handset, in the ledger form i:<instance>');
        $ctx->eq([$a['left_out'], $a['queued']], [[], 3], 'D01 nobody left out, three would be queued');
        $ctx->eq(array_map(function ($e) { return $e['handset']; }, $a['eligible']), ['i:dishnet-ug', 'i:dishnet-ug', 'i:sales-ug'], 'D01 each row carries WaSendBudget::handset(instance)');
    });

    $ctx->run('D02 opted out: any live opt-out, any channel, scope or source, in the one form; a lifted one kept', function () use ($ctx, $T0, $D) {
        $b = wo_book($ctx, wo_cfg(), 'd');
        $oo = new ContactOptOut($b['pdo']);
        foreach (['256700500201', '256700500202', '256700500203', '256700500204', '256700500205', '256700500206'] as $p) wo_warm($b, $p, $T0, $D);
        $oo->add('256700500201', ['channel' => 'support', 'scope' => 'proactive']);              // another number's, channel-specific
        $oo->add('256700500202', ['channel' => '*', 'scope' => 'all']);
        $oo->add('0700500203', ['channel' => '*']);                                               // typed in the national form
        $oo->add('256700500204', ['channel' => 'sales', 'source' => 'voice_keyword']);            // Part V's spoken STOP
        $oo->add('256700500205', ['channel' => '*']);
        $oo->lift('256700500205', '*', 'test');                                                   // re-consent
        [$a, $ph] = wo_aud($b);
        $ctx->eq($ph, ['256700500205', '256700500206'], 'D02 control: the lifted opt-out and the untouched customer stay');
        foreach (['256700500201' => 'a channel-specific opt-out on another number', '256700500202' => 'scope all',
                  '256700500203' => 'an opt-out typed in the national form', '256700500204' => 'a voice-source opt-out (Part V)'] as $p => $what) {
            $ctx->eq(wo_why($b, $p), 'opted_out', "D02 {$what}: opted_out");
        }
        $ctx->eq($a['left_out'], ['opted_out' => 4], 'D02 four left out as opted_out');
    });

    $ctx->run('D03 colleagues: any account of retailers.json (any role, any status), or a conversation filed staff', function () use ($ctx, $T0, $D) {
        $b = wo_book($ctx, wo_cfg(), 'd');
        foreach (['256700500301', '256700500302', '256700500303', '256700500304', '256700500305'] as $p) wo_warm($b, $p, $T0, $D);
        $rows = [['id' => 31, 'name' => 'Dealer Test', 'role' => 'retailer', 'status' => 'active', 'phone' => '0700500301'],
                 ['id' => 32, 'name' => 'Former Staff', 'role' => 'support', 'status' => 'inactive', 'phone' => '+256700500302'],
                 ['id' => 33, 'name' => 'Field Agent', 'role' => 'field_agent', 'status' => 'active', 'phone' => '256700500303']];
        wo_conv($b, '256700500304', 'support', ['category' => 'staff']);
        [$a, $ph] = wo_aud($b, $rows);
        $ctx->eq($ph, ['256700500305'], 'D03 control: the one customer who is nobody of ours stays');
        $ctx->eq([wo_why($b, '256700500301', $rows), wo_why($b, '256700500302', $rows), wo_why($b, '256700500303', $rows), wo_why($b, '256700500304', $rows)],
                 ['colleague', 'colleague', 'colleague', 'colleague'], 'D03 a dealer, an inactive staff member, a field agent and a conversation filed staff: colleague');
        $ctx->eq($a['left_out'], ['colleague' => 4], 'D03 four left out as colleague');
    });

    $ctx->run('D04 DishNet\'s own numbers: alert recipients and lines with the registry off and on; one saved only in the store; one Evolution reported', function () use ($ctx, $T0, $D) {
        foreach (['off' => [], 'on' => ['multi_number_channels_enabled' => '1']] as $reg => $extra) {
            $b = wo_book($ctx, wo_cfg(['alert_whatsapp' => '+256700500401'] + $extra), 'd');
            foreach (['256700500401', '256700500402', '256700500403', '256700500404'] as $p) wo_warm($b, $p, $T0, $D);
            $b['store']->save('wa_evo_numbers.json', [['instance' => 'Sales-UG', 'number' => '+256700500403']]);
            $kyc = ['wa_support_number' => '0700500402'];
            [$a, $ph] = wo_aud($b, [], $kyc);
            $ctx->eq($ph, ['256700500404'], "D04 control (registry {$reg}): the customer stays");
            $ctx->eq([wo_why($b, '256700500401', [], $kyc), wo_why($b, '256700500402', [], $kyc), wo_why($b, '256700500403', [], $kyc)],
                     ['dishnet_number', 'dishnet_number', 'dishnet_number'],
                     "D04 registry {$reg}: an alert recipient, a line saved only in the store's kyc_config.json, a number Evolution reported");
        }
    });

    $ctx->run('D05 partner contacts in every form, an unreadable one on its last nine digits (counted _loose)', function () use ($ctx, $T0, $D) {
        $b = wo_book($ctx, wo_cfg(), 'd');
        foreach (['256700500501', '256700500502', '256700500503', '256725005041', '256700500505'] as $p) wo_warm($b, $p, $T0, $D);
        $ins = $b['pdo']->prepare('INSERT INTO dist_contacts (partner_id, phone) VALUES (?, ?)');
        $ins->execute([1, '+256700500501']); $ins->execute([1, '0700500502']); $ins->execute([2, '256700500503']);
        $ins->execute([2, '07250050419']);                                                        // 11 digits from a 0: PhoneNumber reads no form
        [$a, $ph] = wo_aud($b);
        $ctx->eq($ph, ['256700500505', '256725005041'], 'D05 control: the customers who are no partner contacts stay');
        $ctx->eq([wo_why($b, '256700500501'), wo_why($b, '256700500502'), wo_why($b, '256700500503')], ['partner_contact', 'partner_contact', 'partner_contact'],
                 'D05 a contact stored with +, in the national form and in the international form: each partner_contact');
        $ctx->eq(wo_why($b, '256725005041'), '', 'D05 the unreadable contact 07250050419 (last nine 250050419) does not match 256725005041');
        $ctx->eq($a['loose'], ['partner_contact_loose' => 1], 'D05 the unreadable contact is counted partner_contact_loose');
        wo_warm($b, '256250050419', $T0, $D);
        $ctx->eq(wo_why($b, '256250050419'), 'partner_contact', 'D05 … and leaves out a candidate whose last nine digits are 250050419');
    });

    $ctx->run('D06 a partner\'s number held; a salesperson\'s own number held unless offers_on_owned_numbers; the policy\'s final and transient refusals', function () use ($ctx, $T0, $D) {
        $b = wo_book($ctx, wo_cfg(), 'd');
        $b['pdo']->exec("INSERT INTO wa_channels (channel_id, evo_instance, display_name, role, owner_type, owner_partner_id, status) VALUES ('partner-001', 'Partner-UG', 'Partner (test)', 'sales', 'partner', 5, 'active')");
        $b['pdo']->exec("INSERT INTO wa_channels (channel_id, evo_instance, display_name, role, owner_type, owner_staff_id, status) VALUES ('sales-002', 'Sales-Two', 'Sales two (test)', 'sales', 'staff', 31, 'active')");
        $gw = new WoGateway(new WoLog(), ['sales' => 'i:sales-ug', 'support' => 'i:dishnet-ug', 'partner-001' => 'i:partner-ug', 'sales-002' => 'i:sales-two', 'support-9' => 'i:support-9']);
        wo_warm($b, '256700500601', $T0, $D, 'partner-001');
        wo_warm($b, '256700500602', $T0, $D, 'sales-002');
        wo_warm($b, '256700500603', $T0, $D);
        $gw->refusals['support-9'] = 'channel_disabled';
        wo_warm($b, '256700500604', $T0, $D, 'support-9');
        $gw->refusals['support'] = 'internal_numbers_incomplete';
        wo_warm($b, '256700500605', $T0, $D, 'support');
        [$a, $ph] = wo_aud($b, [], [], 'all', $gw);
        $ctx->eq($ph, ['256700500603', '256700500605'], 'D06 control: the department customer, and the one whose refusal may clear, stay');
        $ctx->eq($a['left_out'], ['channel_refused:channel_disabled' => 1, 'owned_number' => 1, 'partner_number' => 1],
                 'D06 a partner\'s number, a salesperson\'s own number and a final refusal are each left out by name');
        $ctx->eq($a['waiting'], ['channel_waiting:internal_numbers_incomplete' => 1], 'D06 the transient refusal is counted channel_waiting and stays eligible');
        wo_setcfg($b, ['offers_on_owned_numbers' => 1]);
        [, $ph] = wo_aud($b, [], [], 'all', $gw);
        $ctx->eq($ph, ['256700500602', '256700500603', '256700500605'], 'D06 with offers_on_owned_numbers 1 the salesperson\'s customer is allowed; the partner\'s never');
    });

    $ctx->run('D07 Part F\'s hold (ruling R3): an open tracked quotation, an open quote follow-up, a quote chase within 48 h', function () use ($ctx, $T0, $D) {
        $b = wo_book($ctx, wo_cfg(), 'd');
        $c1 = wo_warm($b, '256700500701', $T0, $D);
        wo_warm($b, '256700500702', $T0, $D);
        wo_warm($b, '256700500703', $T0, $D);
        $b['pdo']->prepare("INSERT INTO quote_tracker (quote_key, quote_ref, phone, evidence_msg_id, evidence_event, made_by, sent_at, sent_conv_id, sent_channel,
                                                       valid_until, created_at) VALUES ('ref:Q-TEST-1', 'Q-TEST-1', '256700500701', 1, 'ops_quote_wa', 'manual', ?, ?, 'support', '2026-11-20', ?)")
            ->execute([UtcClock::stamp($T0 - 2 * $D), $c1, UtcClock::stamp($T0 - 2 * $D)]);
        wo_ledger($b['pdo'], 'quote', 'qc:1:1', '256700500702', $T0 - 47 * 3600, 'sent', 'claim');
        [$a, $ph] = wo_aud($b);
        $ctx->eq($ph, ['256700500703'], 'D07 control: the customer with no quotation stays');
        $ctx->eq([wo_why($b, '256700500701'), wo_why($b, '256700500702')], ['quote_hold', 'quote_hold'],
                 'D07 an open tracked quotation, and a quote chase sent 47 h ago: quote_hold');
    });

    $ctx->run('D08 follow-ups: open, or sent within 48 h (49 h kept), and a send seen through the ledger alone', function () use ($ctx, $T0, $D) {
        $b = wo_book($ctx, wo_cfg(), 'd');
        $p = $b['pdo'];
        $phones = ['256700500801', '256700500802', '256700500803', '256700500804', '256700500805', '256700500806', '256700500807'];
        $conv = [];
        foreach ($phones as $ph) $conv[$ph] = wo_warm($b, $ph, $T0, $D);
        $fu = function (string $ph, ?string $closed) use ($p, $conv, $T0): int {
            $p->prepare("INSERT INTO followups (conversation_id, channel, phone, last_customer_at, opened_at, closed_at) VALUES (?, 'sales', ?, ?, ?, ?)")
              ->execute([$conv[$ph], $ph, UtcClock::stamp($T0 - 9 * 86400), UtcClock::stamp($T0 - 9 * 86400), $closed]);
            return (int)$p->lastInsertId();
        };
        $fu('256700500801', null);
        $f2 = $fu('256700500802', UtcClock::stamp($T0 - 3600));
        $p->prepare("INSERT INTO followup_sends (followup_id, attempt, sent_at, channel, phone, body, decided_by) VALUES (?, 1, ?, 'sales', ?, 'x', 'staff:test')")
          ->execute([$f2, UtcClock::stamp($T0 - 47 * 3600), '256700500802']);
        $f3 = $fu('256700500803', UtcClock::stamp($T0 - 3600));
        $p->prepare("INSERT INTO followup_sends (followup_id, attempt, sent_at, channel, phone, body, decided_by) VALUES (?, 1, ?, 'sales', ?, 'x', 'staff:test')")
          ->execute([$f3, UtcClock::stamp($T0 - 49 * 3600), '256700500803']);
        wo_ledger($p, 'enquiry', 'fu:9:1', '256700500804', $T0 - 47 * 3600, 'uncertain');           // a may-have-been-sent, no followup_sends row
        wo_ledger($p, 'enquiry', 'fu:10:1', '256700500805', $T0 - 47 * 3600, null, 'claim');        // in flight
        wo_ledger($p, 'enquiry', 'fu:11:1', '256700500806', $T0 - 47 * 3600, 'not_sent');           // certainly not sent
        [$a, $ph] = wo_aud($b);
        $ctx->eq($ph, ['256700500803', '256700500806', '256700500807'], 'D08 control: a send 49 h ago, a send settled not_sent and the untouched customer stay');
        $ctx->eq([wo_why($b, '256700500801'), wo_why($b, '256700500802')], ['followup_open', 'followup_recent'], 'D08 an open follow-up; a follow-up sent 47 h ago');
        $ctx->eq([wo_why($b, '256700500804'), wo_why($b, '256700500805')], ['followup_recent', 'followup_recent'],
                 'D08 an enquiry settled uncertain, and one in flight, 47 h ago, seen through the ledger alone: followup_recent');
    });

    $ctx->run('D09 a recent offer: sent 6 days ago left out, 8 days ago kept; a claimed one at any age left out', function () use ($ctx, $T0, $D) {
        $b = wo_book($ctx, wo_cfg(), 'd');
        $p = $b['pdo'];
        foreach (['256700500901', '256700500902', '256700500903', '256700500904'] as $ph) wo_warm($b, $ph, $T0, $D);
        $old = wo_offer($b, $T0 - 20 * $D, ['title' => 'An earlier offer (test)']);
        $p->prepare("UPDATE wa_offers SET state = 'running', live_slot = 1, started_at = ? WHERE id = ?")->execute([UtcClock::stamp($T0 - 20 * $D), $old]);
        $r = "INSERT INTO wa_offer_recipients (offer_id, phone, rank, conversation_id, channel, handset, last_in_at, state, sent_at, judged_at, day_max, variant,
                                               wa_message_id, echo, claim_token, claimed_at, attempt) VALUES (?, ?, ?, 1, 'sales', 'i:sales-ug', ?, ?, ?, ?, 20, 1, ?, 'claimed', 't', ?, 1)";
        $p->prepare($r)->execute([$old, '256700500901', 1, UtcClock::stamp($T0 - 9 * $D), 'sent', UtcClock::stamp($T0 - 6 * $D), UtcClock::stamp($T0 - 6 * $D), 'WO-D09-1', UtcClock::stamp($T0 - 6 * $D)]);
        $p->prepare($r)->execute([$old, '256700500902', 2, UtcClock::stamp($T0 - 9 * $D), 'sent', UtcClock::stamp($T0 - 8 * $D), UtcClock::stamp($T0 - 8 * $D), 'WO-D09-2', UtcClock::stamp($T0 - 8 * $D)]);
        $p->prepare($r)->execute([$old, '256700500903', 3, UtcClock::stamp($T0 - 9 * $D), 'claimed', null, null, null, UtcClock::stamp($T0 - 19 * $D)]);
        wo_offer($b, $T0, ['title' => 'This offer, being prepared (test)']);                    // the offer the audience is for
        [, $ph] = wo_aud($b);
        $ctx->eq($ph, ['256700500902', '256700500904'], 'D09 control: an offer 8 days ago (recent_days 7) and the untouched customer stay');
        $ctx->eq([wo_why($b, '256700500901'), wo_why($b, '256700500903')], ['recent_offer', 'recent_offer'], 'D09 an offer 6 days ago, and one claimed 19 days ago: recent_offer');
    });

    $ctx->run('D10 stop words at any length over the warm window; a voice note holds the whole window; an unread inbound mark', function () use ($ctx, $T0, $D) {
        $b = wo_book($ctx, wo_cfg(), 'd');
        $p = $b['pdo'];
        $stop = 'Please stop sending me these messages now, thank you (test).';                // 60 characters, detect() ignores it
        $c = wo_warm($b, '256700501001', $T0, $D); wo_in($b, $c, $stop, $T0 - 20 * $D);
        $c = wo_warm($b, '256700501002', $T0, $D); wo_in($b, $c, $stop, $T0 - 31 * $D);
        $c = wo_warm($b, '256700501003', $T0, $D); wo_in($b, $c, '[AUDIO]', $T0 - 29 * $D, ['media_type' => 'audio']);
        $c = wo_warm($b, '256700501004', $T0, $D); wo_in($b, $c, '[AUDIO]', $T0 - 31 * $D, ['media_type' => 'audio']);
        wo_warm($b, '256700501005', $T0, $D);
        $p->prepare("INSERT INTO wa_seen_marks (direction, phone, channel, instance, kind, wa_message_id, seen_at) VALUES ('in', '256700501005', 'sales', 'Sales-UG', 'ephemeralMessage', 'WO-D10-M1', ?)")
          ->execute([UtcClock::stamp($T0 - 10 * $D)]);
        wo_warm($b, '256700501006', $T0, $D);
        $p->prepare("INSERT INTO wa_seen_marks (direction, phone, channel, instance, kind, wa_message_id, seen_at) VALUES ('in', '256700501006', 'sales', 'Sales-UG', 'ephemeralMessage', 'WO-D10-M2', ?)")
          ->execute([UtcClock::stamp($T0 - 31 * $D)]);
        $ctx->eq(ContactOptOut::detect($stop)['stop'], false, 'D10 control: the 60-character STOP sentence is no opt-out to the live webhook');
        [, $ph] = wo_aud($b);
        $ctx->eq($ph, ['256700501002', '256700501004', '256700501006'], 'D10 control: a stop 31 days ago, a voice note 31 days ago (a later text inside), a mark 31 days ago stay');
        $ctx->eq([wo_why($b, '256700501001'), wo_why($b, '256700501003'), wo_why($b, '256700501005')], ['stop_words', 'recent_voice_note', 'unread_inbound'],
                 'D10 a STOP sentence 20 days ago; a voice note 29 days ago (the whole warm window, ruling R3); an inbound mark 10 days ago');
    });

    $ctx->run('D11 a person on our side (HumanTakeover, C7), escalation words, an ambiguous identity', function () use ($ctx, $T0, $D) {
        $b = wo_book($ctx, wo_cfg(), 'd');
        $p = $b['pdo'];
        $c = wo_warm($b, '256700501101', $T0, $D); wo_out($b, $c, 'A colleague typed this on the handset (test).', $T0 - 2 * 3600, ['agent' => 'Team']);
        $c = wo_warm($b, '256700501102', $T0, $D); wo_out($b, $c, 'An automated follow-up (test).', $T0 - 2 * 3600, ['agent' => 'DishNet']);
        $c = wo_warm($b, '256700501103', $T0, $D); wo_out($b, $c, 'A colleague, four days ago (test).', $T0 - 4 * $D, ['agent' => 'Team']);
        wo_warm($b, '256700501104', $T0, $D, 'support');
        $p->prepare("INSERT INTO wa_seen_marks (direction, phone, channel, instance, kind, wa_message_id, seen_at) VALUES ('out', '256700501104', 'support', 'DishNet-UG', 'audioMessage', 'WO-D11-OUT', ?)")
          ->execute([UtcClock::stamp($T0 - 3600)]);
        $c = wo_warm($b, '256700501105', $T0, $D);
        $p->prepare("INSERT INTO events (event_type, entity_type, entity_id, payload, created_at, created_by) VALUES ('wa.escalation', 'conversation', ?, '{}', ?, 'test')")
          ->execute([$c, UtcClock::stamp($T0 - 3600)]);
        $p->prepare("UPDATE wa_conversations SET state = 'needs_human' WHERE id = ?")->execute([$c]);
        $c = wo_warm($b, '256700501106', $T0, $D); wo_in($b, $c, 'I will report you to consumer protection about this (test).', $T0 - 3 * $D);
        wo_warm($b, '256700501107', $T0, $D);
        wo_conv($b, '256700501107', 'support', ['crm_link_method' => 'ambiguous']);
        wo_warm($b, '256700501108', $T0, $D);
        [, $ph] = wo_aud($b);
        $ctx->eq($ph, ['256700501102', '256700501103', '256700501108'], 'D11 control: an automated DishNet row is no person; a colleague four days ago is past 72 h; the untouched customer stays');
        $ctx->eq([wo_why($b, '256700501101'), wo_why($b, '256700501104'), wo_why($b, '256700501105')], ['person_handling', 'person_handling', 'person_handling'],
                 'D11 a Team row 2 h ago, a handset voice note (an out mark), an escalation: person_handling');
        $ctx->eq([wo_why($b, '256700501106'), wo_why($b, '256700501107')], ['escalation_words', 'ambiguous_identity'], 'D11 a complaint; an ambiguous identity on any conversation');
    });

    $ctx->run('D12 the number rule: +211 and other foreign numbers, LID-length digits; marketing and accounts never count', function () use ($ctx, $T0, $D) {
        $b = wo_book($ctx, wo_cfg(), 'd');
        wo_warm($b, '211912345678', $T0, $D);
        wo_warm($b, '254712345678', $T0, $D);
        wo_warm($b, '123456789012345', $T0, $D);
        wo_warm($b, '256700501201', $T0, $D, 'marketing');
        wo_warm($b, '256700501202', $T0, $D, 'accounts');
        wo_warm($b, '256700501203', $T0, $D);
        [$a, $ph] = wo_aud($b);
        $ctx->eq($ph, ['256700501203'], 'D12 control: the Uganda customer on an Evolution number stays');
        $ctx->eq($a['left_out'], ['not_uganda_number' => 2, 'south_sudan_number' => 1], 'D12 +211 counted apart; +254 and a 15-digit LID left out as not_uganda_number');
        $ctx->eq($a['all_eligible'] + array_sum($a['left_out']), 4, 'D12 the marketing and accounts conversations are no candidates at all');
    });

    $ctx->run('D13 the chosen conversation: the latest, ties sales then support; the order and the size cut at 100 and 200', function () use ($ctx, $T0, $D) {
        $b = wo_book($ctx, wo_cfg(), 'd');
        $c1 = wo_conv($b, '256700501301', 'sales');   wo_in($b, $c1, 'older on sales (test)', $T0 - 3 * $D);
        $c2 = wo_conv($b, '256700501301', 'support'); wo_in($b, $c2, 'newer on support (test)', $T0 - 2 * $D);
        $t3 = $T0 - 4 * $D;
        $c3 = wo_conv($b, '256700501302', 'support'); wo_in($b, $c3, 'tie (test)', $t3);
        $c4 = wo_conv($b, '256700501302', 'sales');   wo_in($b, $c4, 'tie (test)', $t3);
        [$a] = wo_aud($b);
        $by = [];
        foreach ($a['eligible'] as $e) $by[$e['phone']] = [$e['conversation_id'], $e['channel']];
        $ctx->eq($by, ['256700501301' => [$c2, 'support'], '256700501302' => [$c4, 'sales']], 'D13 the latest conversation; on a tie, sales before support');
        $b = wo_book($ctx, wo_cfg(), 'd');
        for ($i = 0; $i < 205; $i++) wo_warm($b, sprintf('2567006%05d', $i), $T0, 3600 + $i * 600);
        [$a100, $p100] = wo_aud($b, [], [], '100');
        [, $p200] = wo_aud($b, [], [], '200');
        [, $pall] = wo_aud($b, [], [], 'all');
        $ctx->eq([count($p100), count($p200), count($pall), $a100['all_eligible']], [100, 200, 205, 205], 'D13 the size cut: 100, 200, all');
        $ctx->eq([$p100[0], $p100[99], $p200[199]], ['256700600000', '256700600099', '256700600199'], 'D13 warmest first');
    });

    $ctx->run('D14 store-free (ruling R13): on a query_only connection with storedRows() over it, exactly what the store\'s rows leave out', function () use ($ctx, $T0, $D) {
        $b = wo_book($ctx, wo_cfg(['multi_number_channels_enabled' => '1']), 'd');
        $b['pdo']->exec("INSERT INTO wa_channels (channel_id, evo_instance, display_name, role, owner_type, owner_staff_id, status) VALUES ('sales-002', 'Sales-Two', 'Sales two (test)', 'sales', 'staff', 41, 'active')");
        $b['store']->save('retailers.json', [['id' => 41, 'name' => 'Line Owner', 'role' => 'sales', 'status' => 'active', 'phone' => '0700501401']]);
        $kyc = $b['store']->load('kyc_config.json');
        $kyc['wa_accounts_number'] = '+256700501402';
        $b['store']->save('kyc_config.json', $kyc);
        $b['store']->save('wa_evo_numbers.json', [['instance' => 'DishNet-UG', 'number' => '+256700501403']]);
        foreach (['256700501401', '256700501402', '256700501403', '256700501404'] as $p) wo_warm($b, $p, $T0, $D);
        $withStore = wo_aud($b, $b['store']->load('retailers.json'), $b['store']->load('kyc_config.json'));
        $fresh = $ctx->dir('d14');
        $b['pdo']->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        copy($b['data'] . '/plugin.sqlite3', $fresh . '/plugin.sqlite3');
        $ro = new \PDO('sqlite:' . $fresh . '/plugin.sqlite3');
        $ro->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $ro->exec('PRAGMA query_only = ON');
        $rows = OfferAudience::storedRows($ro);
        $cfg = $b['cfg'];
        $ro0 = (int)$ro->query('SELECT total_changes()')->fetchColumn();
        $a = OfferAudience::build($ro, $cfg, $fresh, $rows['retailers'], $rows['kyc_config'], 'all', $T0);
        $L = OfferAudience::lists($ro, $cfg, $fresh, $rows['retailers'], $rows['kyc_config'], null, null, $T0);
        $ctx->eq($withStore[1], ['256700501404'], 'D14 control: given the store\'s rows, the line owner, the stored line and the reported number are left out');
        $ctx->eq([array_map(function ($e) { return $e['phone']; }, $a['eligible']), $a['left_out']], [$withStore[1], $withStore[0]['left_out']],
                 'D14 on $ro with storedRows(): the same eligible list and the same reasons');
        $ctx->eq(OfferAudience::excludedBy($L, '256700501402'), 'dishnet_number', 'D14 lists() on $ro: a number saved only in the store\'s kyc_config.json');
        $ctx->eq((int)$ro->query('SELECT total_changes()')->fetchColumn() - $ro0, 0, 'D14 total_changes() == 0 on $ro');
        $ctx->eq(array_values(array_filter(scandir($fresh), function ($f) { return $f !== '.' && $f !== '..' && strpos($f, 'plugin.sqlite3') !== 0; })), [],
                 'D14 nothing opened a store in the data directory (no migration log, no file beside the book and its journal)');
        $rp = new \ReflectionProperty('SqliteStore', 'loadCache');
        $rp->setAccessible(true);
        $mine = array_values(array_filter(array_keys((array)$rp->getValue()), function ($k) use ($fresh) { return strpos((string)$k, rtrim($fresh, '/') . '|') === 0; }));
        $ctx->eq($mine, [], 'D14 no SqliteStore over the data directory loaded a collection (the store\'s own load cache holds nothing of it)');
    });

    $ctx->run('D15 the partner list at a moment (Q16-O06): lists() given $atUtc bounds dist_contacts by created_at', function () use ($ctx, $T0, $D) {
        $b = wo_book($ctx, wo_cfg(), 'd');
        foreach (['256700501501', '256700501502'] as $p) wo_warm($b, $p, $T0, $D);
        $b['pdo']->prepare('INSERT INTO dist_contacts (partner_id, phone, created_at) VALUES (1, ?, ?)')->execute(['256700501501', UtcClock::stamp($T0 - 3600)]);
        $b['pdo']->prepare('INSERT INTO dist_contacts (partner_id, phone, created_at) VALUES (1, ?, ?)')->execute(['256700501502', UtcClock::stamp($T0 + 3600)]);
        $at = OfferAudience::lists($b['pdo'], $b['cfg'], $b['data'], [], [], null, UtcClock::stamp($T0));
        $now = OfferAudience::lists($b['pdo'], $b['cfg'], $b['data'], [], [], null, null, $T0);
        $ctx->eq([OfferAudience::excludedBy($at, '256700501501'), OfferAudience::excludedBy($at, '256700501502')], ['partner_contact', ''],
                 'D15 given $atUtc: the contact created before it is left out, the one created after it is kept');
        $ctx->eq([OfferAudience::excludedBy($now, '256700501501'), OfferAudience::excludedBy($now, '256700501502')], ['partner_contact', 'partner_contact'],
                 'D15 given null: both are left out');
    });

    $ctx->run('D16 judge() at the send: an erased row, a cooled phone, a move, awaiting a reply, a fresh exclusion, ok', function () use ($ctx, $T0, $D) {
        $b = wo_book($ctx, wo_cfg(), 'd');
        $gw = new WoGateway(new WoLog());
        $c = wo_warm($b, '256700501601', $T0, $D);
        $row = ['id' => 0, 'phone' => '256700501601', 'conversation_id' => $c, 'channel' => 'sales', 'handset' => 'i:sales-ug'];
        $j = function (array $r) use ($b, $gw, $T0) { return OfferAudience::judge($b['pdo'], $b['cfg'], $b['data'], [], [], $r, UtcClock::stamp($T0), $gw); };
        $ctx->eq($j($row)['outcome'], 'ok', 'D16 control: a warm customer answered a day ago: ok');
        $ctx->eq($j(['phone' => null] + $row)['code'], 'erased', 'D16 an erased row (NULL phone): skip(erased)');
        $c2 = wo_conv($b, '256700501602', 'sales'); wo_in($b, $c2, 'old (test)', $T0 - 40 * $D);
        $ctx->eq($j(['phone' => '256700501602', 'conversation_id' => $c2] + $row)['code'], 'cooled', 'D16 no inbound in the warm window: skip(cooled)');
        $c3 = wo_conv($b, '256700501601', 'support'); wo_in($b, $c3, 'now on support (test)', $T0 - 3 * 3600); wo_out($b, $c3, 'answered (test)', $T0 - 3 * 3600 + 60);
        $m = $j($row);
        $ctx->eq([$m['outcome'], $m['conversation_id'] ?? null, $m['handset'] ?? null], ['move', $c3, 'i:dishnet-ug'], 'D16 the customer wrote on another handset since: move');
        $c4 = wo_conv($b, '256700501603', 'sales'); wo_in($b, $c4, 'a question (test)', $T0 - 30 * 60);
        $w = $j(['phone' => '256700501603', 'conversation_id' => $c4] + $row);
        $ctx->eq([$w['outcome'], $w['code'], $w['retry_at']], ['defer', 'awaiting_reply', $T0 + 90 * 60], 'D16 a question 30 minutes old, unanswered: defer(awaiting_reply) to 120 minutes after it');
        (new ContactOptOut($b['pdo']))->add('256700501601', ['channel' => '*']);
        $ctx->eq($j(['conversation_id' => $c3, 'channel' => 'support', 'handset' => 'i:dishnet-ug'] + $row)['code'], 'opted_out', 'D16 an opt-out since the start, read fresh: skip(opted_out)');
    });
}];
