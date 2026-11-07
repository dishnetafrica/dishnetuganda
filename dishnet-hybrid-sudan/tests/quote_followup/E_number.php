<?php
declare(strict_types=1);
/**
 * Section E of tests/test_quote_followup.php — which number, F5 (5.18.104, root docs/73 §H.2.9.2, §H.2.9.5, §H.2.10.2,
 * §H.2.10.3's first holds, §H.2.14 E; A13, C10). TEST ONLY.
 *
 * Spoke-to on the exact phone (never the last nine digits) inside (sent_at − 30 d, sent_at], the later one winning and a
 * tie going to a sales-role channel; never wrote (the cold chase off, and on: the quotation's own conversation on its
 * Evolution channel); `accounts` → `account` and wa_force_accounts; a salesperson's own number held (OwnedNumberHold);
 * a partner's number; AutomationPolicy's final and transient refusals; ambiguous; a colleague's number — through
 * QuoteTracker's own reads: the row it derives, number() and openingHold(). Every person and number is fictitious.
 */

$QF_SECTIONS['E'] = ['title' => 'Which number, F5 (§H.2.9.5, §H.2.10.2)', 'run' => function (QfCtx $ctx) {
    $tree = $ctx->tree();
    foreach (['StoreInterface', 'SqliteStore', 'UtcClock', 'QuoteSendEvidence', 'QuoteTracker'] as $l) require_once $tree . '/lib/' . $l . '.php';
    $S = '2026-11-02 07:00:00';
    $at = '2026-11-02 11:00:00';

    $ctx->run('E01 spoke-to is the exact phone, never the last nine digits (QM13)', function () use ($ctx, $S, $at) {
        $b = $ctx->book(qf_tcfg(), 'e'); $p = $b['pdo'];
        $sent = qf_conv($p, '256772501701', 'support');
        $sales = qf_conv($p, '256772501701', 'sales');
        qf_in($p, $sales, 'Do you sell kits? (test)', '2026-10-31 09:00:00');
        $other = qf_conv($p, '211772501701', 'support');            // the same last nine digits, another country's number
        qf_in($p, $other, 'A later message (test)', '2026-11-02 06:00:00');
        qf_qmsg($p, $sent, 'quote_manual', qf_ref_text('Q-7001'), $S);
        qf_track($b, $at);
        $r = qf_trow($p, 'ref:Q-7001');
        $ctx->eq([(int)$r['spoke_conv_id'], $r['spoke_channel']], [$sales, 'sales'], 'E01 the conversation on the exact phone, not the later one sharing its last nine digits');
        qf_in($p, $other, 'yes please (test)', '2026-11-02 08:00:00');
        qf_track($b, '2026-11-02 12:00:00');
        $ctx->eq(qf_trow($p, 'ref:Q-7001')['status'], 'open', 'E01 a reply from that other number is not this customer\'s');
        $ctx->eq(QuoteTracker::convIds($p, '256772501701', $sent), [$sent, $sales], 'E01 the conversations of p are those of the exact phone (and the quotation\'s own)');
    });

    $ctx->run('E02 the window (sent_at − 30 d, sent_at]: older, or only after the send, is never wrote', function () use ($ctx, $S, $at) {
        $b = $ctx->book(qf_tcfg(), 'e'); $p = $b['pdo'];
        $a = qf_conv($p, '256772501711'); qf_in($p, $a, 'old (test)', '2026-10-02 06:59:59'); qf_qmsg($p, $a, 'quote_manual', qf_ref_text('Q-7011'), $S);
        $c = qf_conv($p, '256772501712'); qf_in($p, $c, 'edge (test)', '2026-10-03 07:00:01'); qf_qmsg($p, $c, 'quote_manual', qf_ref_text('Q-7012'), $S);
        $d = qf_conv($p, '256772501713'); qf_qmsg($p, $d, 'quote_manual', qf_ref_text('Q-7013'), $S); qf_in($p, $d, 'after (test)', '2026-11-02 07:30:00');
        qf_track($b, $at);
        $ctx->eq([qf_trow($p, 'ref:Q-7011')['spoke_conv_id'], (int)qf_trow($p, 'ref:Q-7012')['spoke_conv_id'], qf_trow($p, 'ref:Q-7013')['spoke_conv_id']],
                 [null, $c, null], 'E02 31 days before: never wrote; just inside 30 days: spoke; a first message after the send: never wrote');
    });

    $ctx->run('E03 the later conversation wins; a tie goes to a sales-role channel', function () use ($ctx, $S, $at) {
        $b = $ctx->book(qf_tcfg(), 'e'); $p = $b['pdo'];
        $sup = qf_conv($p, '256772501721', 'support'); $sal = qf_conv($p, '256772501721', 'sales');
        qf_in($p, $sup, 'x (test)', '2026-11-01 10:00:00'); qf_in($p, $sal, 'y (test)', '2026-11-01 10:00:00');
        qf_qmsg($p, $sup, 'quote_manual', qf_ref_text('Q-7021'), $S);
        $sup2 = qf_conv($p, '256772501722', 'support'); $sal2 = qf_conv($p, '256772501722', 'sales');
        qf_in($p, $sal2, 'x (test)', '2026-11-01 10:00:00'); qf_in($p, $sup2, 'y (test)', '2026-11-01 10:00:01');
        qf_qmsg($p, $sup2, 'quote_manual', qf_ref_text('Q-7022'), $S);
        $st = qf_conv($p, '256772501723', 'support', 'staff'); qf_in($p, $st, 'z (test)', '2026-11-01 11:00:00');
        $cu = qf_conv($p, '256772501723', 'sales'); qf_in($p, $cu, 'z (test)', '2026-11-01 10:00:00');
        qf_qmsg($p, $cu, 'quote_manual', qf_ref_text('Q-7023'), $S);
        qf_track($b, $at);
        $ctx->eq([(int)qf_trow($p, 'ref:Q-7021')['spoke_conv_id'], (int)qf_trow($p, 'ref:Q-7022')['spoke_conv_id'], (int)qf_trow($p, 'ref:Q-7023')['spoke_conv_id']],
                 [$sal, $sup2, $cu], 'E03 a tie: sales; a second later: that one; a staff-filed conversation never');
    });

    $ctx->run('E04 never wrote: the cold chase off holds it; on, the quotation\'s own conversation on its Evolution channel (QM31)', function () use ($ctx, $S, $at) {
        $b = $ctx->book(qf_tcfg(), 'e'); $p = $b['pdo'];
        $acc = qf_conv($p, '256772501731', 'accounts');
        qf_qmsg($p, $acc, 'ops_quote_wa', qf_ref_text('Q-7031'), $S);
        $sup = qf_conv($p, '256772501732', 'support');
        qf_qmsg($p, $sup, 'ops_quote_wa', qf_ref_text('Q-7032'), $S);
        qf_track($b, $at);
        $r1 = qf_trow($p, 'ref:Q-7031'); $r2 = qf_trow($p, 'ref:Q-7032');
        $ctx->eq([$r1['sent_channel'], $r2['sent_channel']], ['account', 'support'], 'E04 a quotation filed under accounts left from account; support from support');
        $ctx->eq(QuoteTracker::number($p, $b['cfg'], $b['data'], $r1), ['conv_id' => null, 'channel' => null, 'hold' => 'never_wrote', 'wait' => null, 'cold' => true],
                 'E04 never wrote, the cold chase off: never_wrote');
        $cold = $b['cfg'] + ['quote_chase_cold' => '1'];
        $ctx->eq([QuoteTracker::number($p, $cold, $b['data'], $r1), QuoteTracker::number($p, $cold, $b['data'], $r2)],
                 [['conv_id' => $acc, 'channel' => 'account', 'hold' => null, 'wait' => null, 'cold' => true],
                  ['conv_id' => $sup, 'channel' => 'support', 'hold' => null, 'wait' => null, 'cold' => true]],
                 'E04 the cold chase on: the quotation\'s own conversation, on account (never accounts, which has no instance) or support');
    });

    $ctx->run('E05 wa_force_accounts: the quotation left from account', function () use ($ctx, $S, $at) {
        $b = $ctx->book(qf_tcfg(['wa_force_accounts' => '1']), 'e'); $p = $b['pdo'];
        qf_qmsg($p, qf_conv($p, '256772501741', 'support'), 'quote_manual', qf_ref_text('Q-7041'), $S);
        qf_track($b, $at);
        $ctx->eq(qf_trow($p, 'ref:Q-7041')['sent_channel'], 'account', 'E05 force-accounts on: account');
    });

    $registry = function (\PDO $p): void {
        $p->exec("INSERT OR REPLACE INTO wa_channels (channel_id, evo_instance, display_name, role, owner_type, owner_staff_id) VALUES ('sales-902', 'Test-Own-Line', 'Test own line', 'sales', 'staff', 81)");
        $p->exec("INSERT OR REPLACE INTO wa_channels (channel_id, evo_instance, display_name, role, owner_type, owner_partner_id) VALUES ('retailer-903', 'Test-Partner-Line', 'Test partner line', 'sales', 'partner', 5)");
    };

    $ctx->run('E06 a salesperson\'s own number: owned_number, unless wa_followups_on_owned_numbers lifts it (QM7)', function () use ($ctx, $S, $at, $registry) {
        $b = $ctx->book(qf_tcfg(['multi_number_channels_enabled' => '1']), 'e'); $p = $b['pdo'];
        $registry($p);
        qf_staff($p, 81, 'Test Line Owner');
        $c = qf_conv($p, '256772501751', 'sales-902');
        qf_in($p, $c, 'hello (test)', '2026-11-01 09:00:00');
        qf_qmsg($p, qf_conv($p, '256772501751', 'support'), 'quote_manual', qf_ref_text('Q-7051'), $S);
        qf_track($b, $at);
        $r = qf_trow($p, 'ref:Q-7051');
        $ctx->eq([$r['spoke_channel'], $r['owner_source'], QuoteTracker::number($p, $b['cfg'], $b['data'], $r)['hold']], ['sales-902', 'channel', 'owned_number'],
                 'E06 spoke on a salesperson\'s own number (owner rule 4): held, owned_number');
        $lift = QuoteTracker::number($p, $b['cfg'] + ['wa_followups_on_owned_numbers' => '1'], $b['data'], $r);
        $ctx->eq([$lift['hold'], $lift['conv_id'], $lift['channel']], [null, $c, 'sales-902'], 'E06 control: the hold lifted, that number and conversation');
    });

    $ctx->run('E07 a partner\'s number is never chased (A13; QM53)', function () use ($ctx, $S, $at, $registry) {
        $b = $ctx->book(qf_tcfg(), 'e'); $p = $b['pdo'];
        $registry($p);
        $c = qf_conv($p, '256772501761', 'retailer-903');
        qf_in($p, $c, 'hello (test)', '2026-11-01 09:00:00');
        qf_qmsg($p, qf_conv($p, '256772501761', 'support'), 'quote_manual', qf_ref_text('Q-7061'), $S);
        $c2 = qf_conv($p, '256772501762', 'support');
        qf_in($p, $c2, 'hello (test)', '2026-11-01 09:00:00');
        qf_qmsg($p, $c2, 'quote_manual', qf_ref_text('Q-7062'), $S);
        qf_track($b, $at);
        $ctx->eq([QuoteTracker::number($p, $b['cfg'], $b['data'], qf_trow($p, 'ref:Q-7061'))['hold'], QuoteTracker::number($p, $b['cfg'], $b['data'], qf_trow($p, 'ref:Q-7062'))['hold'],
                  qf_trow($p, 'ref:Q-7061')['owner_source']], ['partner_number', null, 'none'],
                 'E07 spoke on a partner\'s number: partner_number (and it names no owner: none at 1 h); on support: no hold');
    });

    $ctx->run('E08 AutomationPolicy: a final refusal holds with its code, a transient one waits', function () use ($ctx, $S, $at) {
        $b = $ctx->book(qf_tcfg(['multi_number_channels_enabled' => '1']), 'e'); $p = $b['pdo'];
        $c = qf_conv($p, '256772501771', 'support');
        qf_in($p, $c, 'hello (test)', '2026-11-01 09:00:00');
        qf_qmsg($p, $c, 'quote_manual', qf_ref_text('Q-7071'), $S);
        qf_track($b, $at);
        $r = qf_trow($p, 'ref:Q-7071');
        $ok = QuoteTracker::number($p, $b['cfg'], $b['data'], $r);
        $ctx->eq([$ok['hold'], $ok['wait'], $ok['conv_id'], $ok['channel']], [null, null, $c, 'support'], 'E08 control: the registry on, support active and its assistant on: that conversation');
        $p->exec("UPDATE wa_channels SET ai_enabled = 0 WHERE channel_id = 'support'");
        $ctx->eq(QuoteTracker::number($p, $b['cfg'], $b['data'], $r)['hold'], 'channel_refused:channel_assistant_disabled', 'E08 its assistant off: final, held with the code');
        $p->exec("UPDATE wa_channels SET ai_enabled = 1, status = 'paused' WHERE channel_id = 'support'");
        $w = QuoteTracker::number($p, $b['cfg'], $b['data'], $r);
        $ctx->eq([$w['hold'], $w['wait']], [null, 'channel_paused'], 'E08 the number paused: transient, it waits');
    });

    $ctx->run('E09 the opening\'s first holds: ambiguous, and a colleague\'s number (status colleague at creation)', function () use ($ctx, $S, $at) {
        $b = $ctx->book(qf_tcfg(), 'e'); $p = $b['pdo'];
        $p->exec('ALTER TABLE wa_conversations ADD COLUMN crm_link_method TEXT DEFAULT NULL');
        $c = qf_conv($p, '256772501781', 'support');
        $p->prepare("UPDATE wa_conversations SET crm_client_id = 5, crm_link_method = 'ambiguous' WHERE id = ?")->execute([$c]);
        qf_in($p, $c, 'hello (test)', '2026-11-01 09:00:00');
        qf_qmsg($p, $c, 'quote_manual', qf_ref_text('Q-7081'), $S);
        $b['store']->save('retailers.json', [['id' => 91, 'name' => 'Test Colleague', 'is_active' => true, 'role' => 'support', 'phone' => '+256772501782']]);
        qf_qmsg($p, qf_conv($p, '256772501782', 'support'), 'quote_manual', qf_ref_text('Q-7082'), $S);
        $c3 = qf_conv($p, '256772501783', 'support');
        qf_qmsg($p, $c3, 'quote_manual', qf_ref_text('Q-7083'), $S);
        qf_track($b, $at);
        $r1 = qf_trow($p, 'ref:Q-7081'); $r2 = qf_trow($p, 'ref:Q-7082'); $r3 = qf_trow($p, 'ref:Q-7083');
        $ctx->eq([$r2['status'], $r2['status_reason'], $r1['status']], ['colleague', 'colleague_number', 'open'], 'E09 a colleague\'s number: status colleague when the row is made');
        $ctx->eq([QuoteTracker::openingHold($p, $b['cfg'], $b['data'], $r1, $b['store']), QuoteTracker::openingHold($p, $b['cfg'], $b['data'], $r2, $b['store']),
                  QuoteTracker::openingHold($p, $b['cfg'], $b['data'], $r3, $b['store'])], ['ambiguous', 'colleague', null],
                 'E09 openingHold(): ambiguous (FollowUpPolicy::contentLevel none), colleague, and none for a plain customer');
        $b['store']->save('retailers.json', [['id' => 91, 'name' => 'Test Colleague', 'is_active' => true, 'role' => 'support', 'phone' => '+256772501782'],
                                             ['id' => 92, 'name' => 'Test New Colleague', 'is_active' => true, 'role' => 'accountant', 'phone' => '0772501783']]);
        $ctx->eq(QuoteTracker::openingHold($p, $b['cfg'], $b['data'], $r3, $b['store']), 'colleague', 'E09 a number that became a colleague\'s since: colleague');
    });
}];
