<?php
declare(strict_types=1);
/**
 * Section K — Part F's real pieces under P6's calls (5.18.104, root docs/73 §H.4.14, §H.4.16 K; rulings R2, R3, R18, R19).
 * TEST ONLY.
 *
 * With Part F's real WaSendBudget (and its real HumanTakeover, QuoteChase, ReminderDay, JobBudget and wa_seen_marks): one
 * spacing and one daily budget per handset across senders, the instance in any spelling; a chase due later today keeps its
 * slot; one automated customer message per person per Kampala day both ways, the phone compared in one form; no_phone;
 * the ledger's floor under the row CAS; offers in the handset's daily total; the admin's test recorded, never claimed, and
 * refused with the send ledger off; the 6–7 orphan; a person on our side through the real webhook, and an offer's own late
 * echo kept; the window's end with the in-flight bound; a quotation stored minutes before the judgement; a write in the
 * judgement's own second; a test's single settle and its recorded moment. The --check half of K (C-O1-*, C-O2-*, cases
 * KC01 …) is tests/wa_offers/k_checks.php, run at the end of this section; it is loaded with this file (B8-fix1), so K08,
 * K11 and K15 also read --check offers-live and --check offers-test over the rows their real runs leave.
 */
require_once __DIR__ . '/k_checks.php';

/** The sender over a book with Part F's real ledger (the default), the scripted gateway and the injected clock. */
function wo_ksender(QfCtx $ctx, array $b, WoClock $clk, $gw, array $seams = []): OfferSender
{
    return OfferSender::forInstall($ctx->tree(), $b['data'], $b['cfg'], $seams + ['store' => $b['store'], 'clock' => $clk,
        'gateway' => function () use ($gw) { return $gw; }, 'wait' => function (int $s): void {}]);
}

/** Part F's ledger for the book, on the injected clock. */
function wo_ledger_of(array $b, WoClock $clk): WaSendBudget
{
    return WaSendBudget::forInstall($b['cfg'], $b['data'], $b['store'], null, $clk);
}

/** The ledger rows of a ref: [kind, mode, outcome, handset, phone]. */
function wo_lrows(\PDO $pdo, string $ref): array
{
    $st = $pdo->prepare('SELECT kind, mode, outcome, handset, phone FROM wa_auto_sends WHERE ref = ? ORDER BY id');
    $st->execute([$ref]);
    return $st->fetchAll(\PDO::FETCH_NUM);
}

$WO_SECTIONS['K'] = ['title' => 'Part F\'s real ledger, hold and person rule under P6\'s calls (§H.4.14)', 'run' => function (QfCtx $ctx) {
    $T0 = wo_t0();

    $ctx->run('K01 one spacing per handset across senders: a chase, then an offer for the instance spelled otherwise, deferred spacing', function () use ($ctx, $T0) {
        [$b, $id, $log, , $gw, $clk] = wo_fx($ctx, ['256700940101' => 'sales', '256700940102' => 'account']);
        $clk->t = $T0 - 30;
        $L = wo_ledger_of($b, $clk);
        $c1 = $L->claim(' SALES-ug ', 'quote', 'qc:1:1', ['phone' => '256700940190']);
        $c2 = $L->claim('DishNet-UG', 'quote', 'qc:2:1', ['phone' => '256700940191']);
        $ctx->eq([!empty($c1['ok']), !empty($c2['ok'])], [true, true], 'K01 control: two chases claimed through Part F\'s path, on sales and on support');
        $L->settle((int)$c1['claim'], 'sent', ['wa_message_id' => 'WO-K01-1']);
        $L->settle((int)$c2['claim'], 'sent', ['wa_message_id' => 'WO-K01-2']);
        $clk->t = $T0;
        wo_ksender($ctx, $b, $clk, $gw)->runOnce(null);
        $ctx->eq([count(wo_calls($log, 'send')), wo_row($b['pdo'], '256700940101')['state'], wo_row($b['pdo'], '256700940102')['state']], [0, 'queued', 'queued'],
                 'K01 the offers on both handsets are deferred: nothing sent, both released');
        $sp = $b['pdo']->query("SELECT COUNT(*) FROM wa_auto_sends WHERE kind = 'offer'")->fetchColumn();
        $ctx->eq([(int)$sp, (int)$b['pdo']->query("SELECT COUNT(DISTINCT handset) FROM wa_auto_sends")->fetchColumn()], [0, 2],
                 'K01 no offer row written; the chases\' rows sit on two handsets (the mixed-case instance and account\'s shared one land on them)');
        $clk->t = $T0 + 400;
        wo_ksender($ctx, $b, $clk, $gw)->runOnce(null);
        $ctx->eq(count(wo_calls($log, 'send')), 2, 'K01 control: once the spacing has passed, both go');
    });

    $ctx->run('K02 a chase due later today keeps its slot: the offer is deferred when the day\'s budget is short', function () use ($ctx, $T0) {
        [$b, $id, $log, , $gw, $clk] = wo_fx($ctx, ['256700940201' => 'sales'], ['wa_send_daily_cap' => 2, 'offers_daily_max_per_handset' => 2]);
        foreach ([1, 2] as $i) {
            $c = wo_conv($b, '2567009402' . (10 + $i), 'sales');
            $b['pdo']->prepare("INSERT INTO followups (conversation_id, channel, phone, last_customer_at, kind, quote_key, due_at) VALUES (?, 'sales', ?, ?, 'quote', ?, ?)")
                     ->execute([$c, '2567009402' . (10 + $i), UtcClock::stamp($T0 - 86400), 'ref:Q-K02-' . $i, UtcClock::stamp($T0 + 3 * 3600)]);
        }
        wo_ksender($ctx, $b, $clk, $gw)->runOnce(null);
        $row = wo_row($b['pdo'], '256700940201');
        $ctx->eq([count(wo_calls($log, 'send')), $row['state'], $row['not_before']], [0, 'queued', UtcClock::stamp(qf_t('2026-11-03 08:00:00'))],
                 'K02 two chases due by the close, a daily cap of 2: the offer is deferred (reserved) to tomorrow\'s opening');
    });

    $ctx->run('K03 one automated message per person per Kampala day, both ways, the phone in one form', function () use ($ctx, $T0) {
        [$b, $id, $log, , $gw, $clk] = wo_fx($ctx, ['256700940301' => 'sales', '256700940302' => 'support']);
        $clk->t = $T0 - 3 * 3600;
        wo_ledger_of($b, $clk)->record('Sales-UG', 'reminder', 'rem:K03', 'sent', ['phone' => '0700940301']);   // recorded with the national form
        $clk->t = $T0;
        wo_ksender($ctx, $b, $clk, $gw)->runOnce(null);
        $ctx->eq([wo_row($b['pdo'], '256700940301')['state'], wo_row($b['pdo'], '256700940301')['not_before'], wo_row($b['pdo'], '256700940302')['state']],
                 ['queued', UtcClock::stamp(qf_t('2026-11-03 08:00:00')), 'sent'], 'K03 a reminder recorded today (national form) defers that phone\'s offer to tomorrow; the other is sent');
        $clk->t = $T0 + 3600;
        $e = wo_ledger_of($b, $clk)->claim('DishNet-UG', 'enquiry', 'fu:K03:1', ['phone' => '+256700940302']);
        $ctx->eq([$e['ok'], $e['defer'] ?? null], [false, 'phone_today'], 'K03 an offer claimed today defers that phone\'s enquiry claim (phone_today)');
        $ctx->eq(wo_ledger_of($b, $clk)->claim('i:sales-ug', 'offer', 'offer:9:9', ['in_flight_s' => 50])['defer'] ?? null, 'no_phone',
                 'K03 an offer claim without a phone: no_phone, final (ruling R2)');
    });

    $ctx->run('K04 the ledger\'s floor: a row forced back to queued after a SENT settle is answered already_sent, and nothing is sent twice', function () use ($ctx, $T0) {
        [$b, $id, $log, , $gw, $clk] = wo_fx($ctx, ['256700940401' => 'sales']);
        wo_ksender($ctx, $b, $clk, $gw)->runOnce(null);
        $r = wo_row($b['pdo'], '256700940401');
        $ctx->eq([$r['state'], wo_lrows($b['pdo'], 'offer:' . $id . ':' . $r['id'])], ['sent', [['offer', 'claim', 'sent', 'i:sales-ug', '256700940401']]],
                 'K04 control: sent, its ledger row under the stable ref settled sent with the phone');
        $p = $b['pdo'];
        $trig = [];
        foreach (['wor_final', 'wor_outcome_fixed'] as $t) { $trig[] = (string)$p->query("SELECT sql FROM sqlite_master WHERE name = '{$t}'")->fetchColumn(); $p->exec("DROP TRIGGER {$t}"); }
        $p->prepare("UPDATE wa_offer_recipients SET state = 'queued', sent_at = NULL, wa_message_id = NULL, echo = NULL, ledger_claim = NULL, variant = NULL WHERE id = ?")->execute([$r['id']]);
        foreach ($trig as $sql) $p->exec($sql);
        $ctx->eq([wo_row($p, '256700940401')['state'], count(wo_lrows($p, 'offer:' . $id . ':' . $r['id']))], ['queued', 1], 'K04 control: the row is queued again (the CAS bypassed), the ledger row stands');
        $clk->t = $T0 + 600;
        wo_ksender($ctx, $b, $clk, $gw)->runOnce(null);
        $r2 = wo_row($b['pdo'], '256700940401');
        $ctx->eq([$r2['state'], $r2['reason'], count(wo_calls($log, 'send'))], ['uncertain', 'ledger_already_sent', 1],
                 'K04 the second claim under the same ref: already_sent, uncertain(ledger_already_sent), one send in all');
        $ctx->eq([(int)wo_ledger_of($b, $clk)->counts('Sales-UG')['proactive_today'], count(wo_lrows($p, 'offer:' . $id . ':' . $r['id']))], [1, 1],
                 'K04 the offer counts once in the handset\'s daily total; no second ledger row was written');
    });

    $ctx->run('K05 the 6–7 orphan: a claim recorded by the ledger but not on the row is in_flight, then already_sent; never sent', function () use ($ctx, $T0) {
        foreach (['fresh' => [100, 'queued'], 'old' => [1000, 'uncertain']] as $what => [$age, $state]) {
            [$b, $id, $log, , $gw, $clk] = wo_fx($ctx, ['256700940501' => 'sales']);
            $r = wo_row($b['pdo'], '256700940501');
            $b['pdo']->prepare("INSERT INTO wa_auto_sends (handset, kind, ref, try, mode, proactive, phone, local_day, gap_s, claimed_at, win_from, win_to, cap_day, cap_hour)
                                VALUES ('i:sales-ug', 'offer', ?, 1, 'claim', 1, '256700940501', '2026-11-02', 60, ?, 8, 20, 60, 15)")
                     ->execute(['offer:' . $id . ':' . $r['id'], UtcClock::stamp($T0 - $age)]);
            wo_ksender($ctx, $b, $clk, $gw)->runOnce(null);
            $r2 = wo_row($b['pdo'], '256700940501');
            $ctx->eq([$r2['state'], count(wo_calls($log, 'send'))], [$state, 0], "K05 a ledger claim {$age} s old under the recipient's ref: {$state}, nothing sent");
            if ($what === 'old') $ctx->eq($r2['reason'], 'ledger_already_sent', 'K05 … after 900 s the ledger settled it and answered already_sent');
            else                 $ctx->eq($r2['not_before'], UtcClock::stamp($T0 - $age + 900), 'K05 … in flight: released to the ledger\'s retry_at');
        }
    });

    $ctx->run('K06 the admin\'s test end to end through record(): sent, recorded other, never deferred; refused with the send ledger off', function () use ($ctx, $T0) {
        $b = wo_book($ctx, wo_cfg(), 'k');
        $log = new WoLog(); $gw = new WoGateway($log);
        $id = wo_offer($b, $T0);
        $clk = new WoClock($T0);
        $L = wo_ledger_of($b, $clk);
        $ch = $L->claim('Sales-UG', 'quote', 'qc:K06', ['phone' => '256700940690']);
        $L->settle((int)$ch['claim'], 'sent');                         // a proactive send a moment ago: the spacing holds the handset
        $admin = ['id' => 7, 'name' => 'Sandbox Admin', 'role' => 'admin', 'is_admin' => true, 'status' => 'active', 'phone' => '0700940601'];
        $s = wo_ksender($ctx, $b, $clk, $gw);
        $r = $s->sendTest($id, 1, 'sales', $admin);
        $t = $b['pdo']->query("SELECT state, ledger, echo, staff_id FROM wa_offer_tests WHERE id = " . (int)$r['test_id'])->fetch(\PDO::FETCH_NUM);
        $t[3] = (int)$t[3];
        $ctx->eq([$r['ok'], $t], [true, ['sent', 'recorded', 'claimed', 7]], 'K06 the test is sent and recorded (ledger recorded), its staff id kept, never its number');
        $ledgerRows = (int)$b['pdo']->query('SELECT COUNT(*) FROM wa_auto_sends')->fetchColumn();
        $ctx->eq(wo_lrows($b['pdo'], 'offer_test:' . $r['test_id']), [['other', 'record', 'sent', 'i:sales-ug', null]], 'K06 the ledger row: kind other, mode record, never claimed, never deferred');
        $send = wo_calls($log, 'send')[0];
        $ctx->eq([$send[2], $send[5], $send[6]], ['256700940601', 'offer.test', 'staff'], 'K06 to the admin\'s own phone of record, echo offer.test, class staff');
        wo_setcfg($b, ['wa_send_ledger' => null]);
        $r = $s->sendTest($id, 2, 'sales', $admin);
        $ctx->eq([$r['code'], count(wo_calls($log, 'send')), (int)$b['pdo']->query('SELECT COUNT(*) FROM wa_offer_tests')->fetchColumn(),
                  (int)$b['pdo']->query('SELECT COUNT(*) FROM wa_auto_sends')->fetchColumn()], ['ledger_off', 1, 1, $ledgerRows],
                 'K06 after off sending (the request\'s configuration still reading it on): nothing sent, no test row, no ledger row (Q16-O01)');
    });

    $ctx->run('K07 a person on our side, each fixture through its real writer, is left out; an offer\'s own late echo is ours by the id settle() wrote', function () use ($ctx, $T0) {
        // Ruling R3 (D 222-234): Part O has no human_active clause of its own because every live writer of human_active stores
        // a person's row in the same request; so each fixture here is made by its real writer — the served evo_webhook.php
        // (a colleague's handset text: a Team row and human_active; a handset voice note: an 'out' mark), the one hand-over
        // writer Handover::escalate (needs_human and its wa.escalation event), and the Inbox's own routes wa_send_reply and
        // wa_send_image served by the judged tree in the sandbox (each a row in the colleague's name, and human_active).
        $b = wo_whbook($ctx, wo_cfg());
        if ($b['url'] === '') { $ctx->t(false, 'K07 control: the webhook is served'); return; }
        $now = time();
        foreach (['256700940701', '256700940702', '256700940703'] as $p) wo_warm($b, $p, $T0, 86400);
        $c4 = wo_warm($b, '256700940704', $T0, 10800);                       // the warmest: the offer's first send on the handset
        $prior = OfferStore::live($b['pdo']);                                // the webhook's book is shared by the process: an earlier case's offer ends first
        if ($prior !== null) OfferStore::stop($b['pdo'], (int)$prior['id'], 7, 'Sandbox Admin', $T0 - 120);
        $log = new WoLog(); $gw = new WoGateway($log);
        [$id, $st] = wo_started($b, $T0 - 60, $gw);
        $ctx->t($st['ok'], 'K07 control: an offer started on the webhook\'s book');
        $echo = [];
        $gw->results = [new OfferSendResult(OfferSendResult::SENT, '', 'WO-K07-OURS', false, 'late')];
        $gw->onSend = function (OfferMessage $m) use ($b, &$echo) {    // Evolution echoes the send before the gateway returns
            $echo = wo_echo($b['url'], $m->phone, 'WO-K07-OURS', $m->text);
        };
        $clk = new WoClock($T0);
        wo_ksender($ctx, $b, $clk, $gw)->runOnce(null);
        $r = wo_row($b['pdo'], '256700940704');
        $team = $b['pdo']->query("SELECT agent_name FROM wa_messages WHERE wa_message_id = 'WO-K07-OURS'")->fetchColumn();
        $ctx->eq([$r['state'], $echo[0] ?? 0, $team], ['sent', 200, 'Team'], 'K07 control: the offer was sent; its echo reached the webhook first and was stored as a handset row');
        $ctx->eq(wo_lrows($b['pdo'], 'offer:' . $id . ':' . $r['id'])[0][2] ?? null, 'sent', 'K07 control: its ledger row settled sent');
        $convOf = function (\PDO $pdo, string $phone): int {
            $q = $pdo->prepare('SELECT id FROM wa_conversations WHERE phone = ? ORDER BY id LIMIT 1');
            $q->execute([$phone]);
            return (int)$q->fetchColumn();
        };
        $stateOf = function (\PDO $pdo, int $conv): string {
            return (string)$pdo->query('SELECT state FROM wa_conversations WHERE id = ' . $conv)->fetchColumn();
        };
        // (1) a colleague types on the handset: the served webhook stores a Team row and makes the conversation human_active
        $ctx->eq(wo_echo($b['url'], '256700940701', 'WO-K07-TEAM', 'A colleague typed this on the handset (test).')[0], 200, 'K07 control: a colleague\'s handset text reached the webhook');
        $c1 = $convOf($b['pdo'], '256700940701');
        $ctx->eq([$b['pdo']->query("SELECT agent_name FROM wa_messages WHERE wa_message_id = 'WO-K07-TEAM'")->fetchColumn(), $stateOf($b['pdo'], $c1)], ['Team', 'human_active'],
                 'K07 the webhook\'s own writes: the person\'s row (Team) and human_active, in the one request');
        // (2) a handset voice note: the webhook's 'out' mark
        wo_post($b['url'], ['key' => ['remoteJid' => '256700940702@s.whatsapp.net', 'fromMe' => true, 'id' => 'WO-K07-AUDIO'], 'message' => ['audioMessage' => ['seconds' => 3]], 'messageTimestamp' => $now]);
        $ctx->eq((int)$b['pdo']->query("SELECT COUNT(*) FROM wa_seen_marks WHERE phone = '256700940702' AND direction = 'out'")->fetchColumn(), 1, 'K07 the webhook\'s out mark for the handset voice note');
        // (3) the hand-over, through its one writer: no alert number and no holding line are set, so nothing is sent
        require_once $ctx->tree() . '/lib/Handover.php';
        $c3 = $convOf($b['pdo'], '256700940703');
        Handover::escalate($b['pdo'], new EventBus($b['pdo']), $b['store'], $b['cfg'], EvolutionApiService::forStore($b['cfg'], $b['pdo'], $b['data'], 15),
                           new ConversationService($b['data'], $b['pdo']), $c3, 'sales', '256700940703', 'test', false, function (string $l, string $m): void {});
        $ev = (int)$b['pdo']->query("SELECT COUNT(*) FROM events WHERE event_type = 'wa.escalation' AND entity_type = 'conversation' AND entity_id = {$c3}")->fetchColumn();
        $ctx->eq([$stateOf($b['pdo'], $c3), $ev], ['needs_human', 1], 'K07 Handover::escalate\'s own writes: needs_human and one wa.escalation event');
        $Ls = OfferAudience::lists($b['pdo'], $b['cfg'], $b['data'], [], [], null, null, time() + 1);
        $ctx->eq([OfferAudience::excludedBy($Ls, '256700940701'), OfferAudience::excludedBy($Ls, '256700940702'), OfferAudience::excludedBy($Ls, '256700940703')],
                 ['person_handling', 'person_handling', 'person_handling'], 'K07 the webhook\'s Team row, its out mark and the hand-over: person_handling');
        $ctx->eq([$Ls['maps']['person_handling']['256700940701'] ?? '', $Ls['maps']['person_handling']['256700940702'] ?? '', $Ls['maps']['person_handling']['256700940703'] ?? ''],
                 ['team_message', 'handset_media', 'escalated'], 'K07 … each by HumanTakeover\'s own code');
        $ctx->eq(OfferAudience::excludedBy($Ls, '256700940704', (int)$r['id']), '', 'K07 the offer\'s own late echo is not a person: the ledger holds the id its settle wrote (O-D18, K2-08)');
        $since = UtcClock::stamp(time() - 3600);
        $ctx->eq([HumanTakeover::phoneSince($b['pdo'], '256700940701', $since), HumanTakeover::since($b['pdo'], '256700940701', [$c1], $since),
                  HumanTakeover::phonesSince($b['pdo'], $since)['256700940701'] ?? null, HumanTakeover::phoneSince($b['pdo'], '256700940704', $since)],
                 ['team_message', 'team_message', 'team_message', null], 'K07 on the webhook\'s fixture phoneSince, since() and phonesSince agree; the offer\'s echo is none of them');
        $one = OfferAudience::lists($b['pdo'], $b['cfg'], $b['data'], [], [], '256700940701', null, time() + 1);
        $ctx->eq(OfferAudience::excludedBy($one, '256700940701'), 'person_handling', 'K07 the single-phone form agrees with the bulk form');
        OfferStore::stop($b['pdo'], $id, 7, 'Sandbox Admin', $T0);
        unset($c4);

        // (4) the Inbox's own routes, served by the judged tree (the sandbox: public.php, the repository's fake Evolution)
        require_once dirname(__DIR__) . '/fixtures/staff_jobs_sandbox.php';
        $sb = SjSandbox::start($ctx->root, ['tenant_profile' => 'uganda', 'timezone' => QF_TZ, 'wa_send_ledger' => 'on'], 'wok');
        try {
            $sb->staff('admin', ['name' => 'Sandbox Colleague', 'email' => 'wo-k07@example.test', 'role' => 'admin', 'is_admin' => true]);
            $sp = $sb->store()->getPdo();
            $cs = new ConversationService($sb->data, $sp);
            $cv = [];
            foreach (['256700940705', '256700940706'] as $p) {
                $cv[$p] = (int)$cs->ensureConversation($p, 'sales')['id'];
                $cs->storeMessage($cv[$p], ['direction' => 'in', 'role' => 'customer', 'body' => 'Hello, a question (test)', 'wa_message_id' => 'WO-K07-IN-' . $p]);
            }
            $rt = $sb->api('admin', 'POST', 'wa_send_reply', ['conversation_id' => $cv['256700940705'], 'message' => 'A colleague answered from the Inbox (test).']);
            $ri = $sb->api('admin', 'POST', 'wa_send_image', ['conversation_id' => $cv['256700940706'], 'image_url' => 'http://127.0.0.1:9/wo-k07.jpg', 'caption' => 'A photo from the Inbox (test)']);
            $ctx->eq([$rt[0], $rt[2]['status'] ?? null, $ri[0], $ri[2]['status'] ?? null], [200, 'success', 200, 'success'], 'K07 control: the Inbox\'s reply and image routes answered as the Inbox panel calls them');
            $row = function (int $conv) use ($sp): array {
                return $sp->query("SELECT agent_name, event_key FROM wa_messages WHERE conversation_id = {$conv} AND direction = 'out' ORDER BY id DESC LIMIT 1")->fetch(\PDO::FETCH_NUM) ?: [];
            };
            $ctx->eq([$row($cv['256700940705']), $stateOf($sp, $cv['256700940705']), $row($cv['256700940706']), $stateOf($sp, $cv['256700940706'])],
                     [['Sandbox Colleague', 'wa_staff_reply'], 'human_active', ['Sandbox Colleague', 'wa_image_send'], 'human_active'],
                     'K07 the Inbox route\'s own writes: the colleague\'s row and human_active, for the text and the image');
            $Li = OfferAudience::lists($sp, $sb->cfg, $sb->data, [], [], null, null, time() + 1);
            $ctx->eq([OfferAudience::excludedBy($Li, '256700940705'), OfferAudience::excludedBy($Li, '256700940706'),
                      $Li['maps']['person_handling']['256700940705'] ?? '', $Li['maps']['person_handling']['256700940706'] ?? ''],
                     ['person_handling', 'person_handling', 'inbox_reply', 'inbox_reply'], 'K07 the Inbox\'s text and image (dated in Kampala): person_handling, inbox_reply');
            $since = UtcClock::stamp(time() - 3600);
            $ctx->eq([HumanTakeover::phoneSince($sp, '256700940705', $since), HumanTakeover::since($sp, '256700940705', [$cv['256700940705']], $since),
                      HumanTakeover::phonesSince($sp, $since)['256700940705'] ?? null], ['inbox_reply', 'inbox_reply', 'inbox_reply'],
                     'K07 on the Inbox\'s fixture phoneSince, since() and phonesSince agree');
        } finally {
            $sb->stop();
        }
    });

    $ctx->run('K08 the window\'s end (O-D9): claimed 10 s before the close through a 12 s gateway is answered window; 60 s and 55 s before go; --check offers-live PASS over each', function () use ($ctx) {
        $close = qf_t('2026-11-02 20:00:00');
        foreach ([[10, 12, 'queued'], [60, 12, 'sent'], [55, 47, 'sent']] as [$before, $takes, $state]) {
            [$b, $id, $log, , $gw, $clk] = wo_fx($ctx, ['256700940801' => 'sales']);
            $clk->t = $close - $before;
            $gw->onSend = function () use ($clk, $takes) { $clk->t += $takes; };
            wo_ksender($ctx, $b, $clk, $gw)->runOnce(null);
            $r = wo_row($b['pdo'], '256700940801');
            $ctx->eq($r['state'], $state, "K08 claimed {$before} s before the close through a {$takes} s gateway: {$state}");
            if ($state === 'sent') $ctx->t(UtcClock::parse((string)$r['sent_at']) <= $close, "K08 … its gateway returned by the close (sent_at " . ($close - UtcClock::parse((string)$r['sent_at'])) . ' s before it)');
            else $ctx->eq([$r['not_before'], count(wo_calls($log, 'send'))], [UtcClock::stamp(qf_t('2026-11-03 08:00:00')), 0], 'K08 … released to the next opening, nothing sent after the close');
            // B8-fix1 (B8-R04): C-O2-2 over the rows this real run left on Part F's real ledger, each judged by its ledger row's
            // claimed_at and the window that row recorded (D 7761: "C-O2-2 reads PASS over all three"); the echo seen as the
            // real gateway's claim leaves it (the scripted gateway writes no evo_webhook_seen row).
            wo_kseen($b);
            $c = wo_klive($b, $close + 3600);
            $ctx->eq([wo_st($c), wo_counted($c, 'claims outside their window'), wo_counted($c, 'returns after the close'), wo_counted($c, 'sends with no ledger row')],
                     ['PASS', 0, 0, 0], "K08 … --check offers-live over the rows the {$before} s run left: PASS, no claim outside its window, no return after the close", wo_detail($c));
        }
    });

    $ctx->run('K09 a quotation stored 2 minutes before the judgement, no tracker run between: quote_hold (quote_text_48h), nothing sent', function () use ($ctx, $T0) {
        [$b, $id, $log, , $gw, $clk] = wo_fx($ctx, ['256700940901' => 'sales', '256700940902' => 'sales']);
        $c = (int)wo_row($b['pdo'], '256700940901')['conversation_id'];
        $mid = wo_out($b, $c, "Your quotation (test)\n📋 *Ref:* Q-K09-1\n⏳ *Valid Until:* 20 Nov 2026", $T0 - 120, ['agent' => 'DishNet', 'event_key' => 'ops_quote_wa']);
        $ctx->t($mid > 0, 'K09 control: the quotation\'s own outbound row, as the real send stores it');
        wo_ksender($ctx, $b, $clk, $gw)->runOnce(null);
        $r = wo_row($b['pdo'], '256700940901');
        $ctx->eq([$r['state'], $r['reason']], ['skipped', 'quote_hold'], 'K09 the recipient is skipped quote_hold at the send');
        $ctx->eq(count(wo_calls($log, 'send')), 0, 'K09 … and the skip ends the handset\'s turn in that run (one judgement per handset per run)');
        $clk->t += 300;
        wo_ksender($ctx, $b, $clk, $gw)->runOnce(null);
        $ctx->eq([count(wo_calls($log, 'send')), wo_row($b['pdo'], '256700940902')['state']], [1, 'sent'], 'K09 … and the next run sends the handset\'s next recipient');
    });

    $ctx->run('K10 a write in the judgement\'s own second waits for the lock: stored after that second, so judge() read everything up to it', function () use ($ctx) {
        // On the real clock (shifted onto Monday 10:30 Kampala) with the sender's own wait. The second connection is started
        // from inside step 4's transaction (the store's first read of retailers.json there), says ready and then waits on the
        // book's lock. An attempt whose hook fell late in its second could commit after that second even without the wait,
        // and would prove nothing about it: such an attempt is made again (at most three), never counted.
        for ($try = 1; $try <= 3; $try++) {
            $shift = qf_t('2026-11-02 10:30:00') - time();
            [$b, $id, $log, $bud, $gw, $clk] = wo_fx($ctx, ['256700941001' => 'sales']);
            $store = new WoStore($b['store'], $b['data']);
            $b['store']->load('retailers.json');                     // the wrapper reads through a second connection of the test's own:
            $b['store']->load('kyc_config.json');                    // its tables made here, never inside step 4's lock (one connection in a real store)
            $db = $b['data'] . '/plugin.sqlite3';
            $ready = $ctx->dir('k10') . '/ready';
            $fired = 0.0;
            $store->onLoad = function (string $file) use ($db, $ready, &$fired): void {
                if ($file !== 'retailers.json' || $fired > 0) return;
                $fired = microtime(true);
                wo_bg('mark', $db, '256700941099', $ready);
            };
            $s = OfferSender::forInstall($ctx->tree(), $b['data'], $b['cfg'], ['store' => $store, 'clock' => function () use ($shift) { return time() + $shift; },
                 'gateway' => function () use ($gw) { return $gw; }, 'budget' => function () use ($bud) { return $bud; }]);
            wo_second_start();
            $s->runOnce(null);
            for ($i = 0; $i < 100 && (int)$b['pdo']->query("SELECT COUNT(*) FROM wa_seen_marks WHERE phone = '256700941099'")->fetchColumn() === 0; $i++) usleep(50000);
            if ($fired > 0 && $fired - floor($fired) < 0.5) break;
        }
        $judged = UtcClock::parse((string)wo_row($b['pdo'], '256700941001')['judged_at']) - $shift;
        $seen = UtcClock::parse((string)$b['pdo']->query("SELECT seen_at FROM wa_seen_marks WHERE phone = '256700941099'")->fetchColumn());
        $ctx->eq([$fired > 0, $seen > 0, (int)floor($fired) === $judged, wo_row($b['pdo'], '256700941001')['state']], [true, true, true, 'sent'],
                 'K10 control: the second connection was started inside step 4, in judged_at\'s own second, and wrote its mark; the recipient was sent');
        $ctx->t($seen > $judged, 'K10 the mark is stored in a later second than judged_at (seen_at ' . ($seen - $judged) . ' s after it): the lock was held past that second (try ' . $try . ')');
    });

    $ctx->run('K11 the test\'s one settle writes its ledger answer; its started_at is the moment before its read, not its INSERT\'s; --check offers-test PASS over it', function () use ($ctx, $T0) {
        wo_ladder_stub($ctx);
        $b = wo_book($ctx, wo_cfg(), 'k');
        wo_admins($b);
        foreach (['retailers.json', 'kyc_config.json'] as $f) $b['store']->load($f);        // the store's tables, made before the hold
        $log = new WoLog(); $gw = new WoGateway($log);
        $id = wo_offer($b, $T0);
        $store = new WoStore($b['store'], $b['data']);
        $bad = [];
        $store->pdo->always = function (string $q) use ($b, &$bad): void {
            $n = (int)$b['pdo']->query("SELECT COUNT(*) FROM wa_offer_tests WHERE state = 'sent' AND ledger IS NULL")->fetchColumn();
            if ($n > 0) $bad[] = substr($q, 0, 40);
        };
        $ready = $ctx->dir('k11') . '/ready';
        $hookAt = 0;
        $store->pdo->hooks[] = ['INSERT INTO wa_offer_tests', function () use ($b, $ready, &$hookAt) { $hookAt = time(); wo_bg('hold', $b['data'] . '/plugin.sqlite3', '4', $ready); }];
        // Part F's real ledger behind the test (B8-fix1, B8-R04): record() writes the offer_test row the check reads.
        $s = OfferSender::forInstall($ctx->tree(), $b['data'], $b['cfg'], ['store' => $store, 'clock' => function () { return time(); },
             'gateway' => function () use ($gw) { return $gw; }]);
        $t0 = time();
        $r = $s->sendTest($id, 1, 'sales', ['id' => 7, 'name' => 'Sandbox Admin', 'role' => 'admin', 'status' => 'active', 'phone' => '0700941101']);
        $row = $b['pdo']->query('SELECT state, ledger, started_at FROM wa_offer_tests WHERE id = ' . (int)$r['test_id'])->fetch(\PDO::FETCH_ASSOC);
        $ctx->eq([$r['ok'], $row['state'], $row['ledger'], $bad], [true, 'sent', 'recorded', []], 'K11 control: sent and recorded; it never read sent with its ledger empty');
        $ctx->t($hookAt > 0 && time() - $hookAt >= 3, 'K11 control: the INSERT waited about 4 s on a second connection\'s write lock');
        $ctx->t(UtcClock::parse((string)$row['started_at']) <= $t0 + 1, 'K11 started_at is the clock taken before its read (' . $row['started_at'] . '), not the INSERT\'s own time');
        $ctx->eq(wo_lrows($b['pdo'], 'offer_test:' . $r['test_id']), [['other', 'record', 'sent', 'i:sales-ug', null]], 'K11 control: Part F\'s real record() wrote its ledger row');
        $r2 = $s->sendTest($id, 2, 'sales', ['id' => 7, 'name' => 'Sandbox Admin', 'role' => 'admin', 'status' => 'active', 'phone' => '0700941101']);
        $b['pdo']->exec("INSERT OR IGNORE INTO evo_webhook_seen (message_id, instance, event) SELECT wa_message_id, 'Sales-UG', 'offer.test' FROM wa_offer_tests WHERE wa_message_id IS NOT NULL");
        $c = OfferSwitch::checkTest(wo_ro($b), $b['cfg'], $b['data'], time());
        $ctx->eq([$r2['ok'], wo_st($c), wo_counted($c, 'tests recorded')], [true, 'PASS', 2], 'K11 … with the second wording\'s test: --check offers-test PASS over the rows the two requests left', wo_detail($c));
    });

    $ctx->run('K12 a may-have-been-sent answer through Part F\'s real ledger: the ledger row uncertain with the id, the recipient uncertain (ruling R2)', function () use ($ctx) {
        [$b, $id, $log, , $gw, $clk] = wo_fx($ctx, ['256700941201' => 'sales']);
        $gw->results = [new OfferSendResult(OfferSendResult::MAYBE_SENT, 'may_have_been_sent', 'WO-K12-1', false, 'claimed')];
        wo_ksender($ctx, $b, $clk, $gw)->runOnce(null);
        $r = wo_row($b['pdo'], '256700941201');
        $ctx->eq([$r['state'], $r['reason'], count(wo_calls($log, 'send'))], ['uncertain', 'may_have_been_sent', 1], 'K12 control: one send, the recipient uncertain(may_have_been_sent)');
        $l = $b['pdo']->query("SELECT outcome, wa_message_id FROM wa_auto_sends WHERE ref = 'offer:{$id}:{$r['id']}'")->fetch(\PDO::FETCH_NUM);
        $ctx->eq($l, ['uncertain', 'WO-K12-1'], 'K12 the ledger row: uncertain, never sent, with the id the gateway gave');
    });

    $ctx->run('K13 the day\'s reminders not yet run: the sender ends the run before any claim, with Part F\'s real ledger behind it (ruling R8)', function () use ($ctx, $T0) {
        [$b, $id, $log, , $gw, $clk] = wo_fx($ctx, ['256700941301' => 'sales']);
        $b['store']->save('master_schedule.json', ['customer_reminders' => ['last_run' => qf_t('2026-11-01 09:05:00'), 'duration_ms' => 1000]]);
        $res = wo_ksender($ctx, $b, $clk, $gw)->runOnce(null);
        $r = wo_row($b['pdo'], '256700941301');
        $ctx->eq([$res['code'], $r['state'], $r['not_before'], (int)$b['pdo']->query('SELECT COUNT(*) FROM wa_auto_sends')->fetchColumn(), count(wo_calls($log, 'send'))],
                 ['reminders_pending', 'queued', null, 0, 0], 'K13 reminders_pending: the row untouched, nothing claimed, nothing sent');
        $b['store']->save('master_schedule.json', ['customer_reminders' => ['last_run' => qf_t('2026-11-02 09:05:00'), 'duration_ms' => 1000]]);
        $clk->t = $T0 + 60;
        wo_ksender($ctx, $b, $clk, $gw)->runOnce(null);
        $ctx->eq(wo_row($b['pdo'], '256700941301')['state'], 'sent', 'K13 control: once the day\'s reminders are recorded, it goes');
    });

    $ctx->run('K14 end to end through the real gateway and Part F\'s real ledger: one handset form from the rows to the ledger to the POST', function () use ($ctx, $T0) {
        $evo = qf_fake_evo();
        $ctx->t($evo !== '', 'K14 control: the fake Evolution is up');
        if ($evo === '') return;
        $b = wo_book($ctx, wo_cfg(['evo_api_url' => $evo]), 'k');
        wo_warm($b, '256700941401', $T0, 86400);
        wo_warm($b, '256700941402', $T0, 90000, 'account');
        [$id, $st] = wo_started($b, $T0 - 60);                              // built through the real gateway
        $ctx->t($st['ok'], 'K14 control: started with the gateway the sender builds');
        $before = count(qf_http('GET', $evo . '/__test/state')[1]['text_calls'] ?? []);
        $clk = new WoClock($T0);
        OfferSender::forInstall($ctx->tree(), $b['data'], $b['cfg'], ['store' => $b['store'], 'clock' => $clk, 'wait' => function (int $s): void {}])->runOnce(null);
        $calls = array_slice(qf_http('GET', $evo . '/__test/state')[1]['text_calls'] ?? [], $before);
        $rows = $b['pdo']->query("SELECT r.handset, r.state, a.handset, a.outcome FROM wa_offer_recipients r LEFT JOIN wa_auto_sends a ON a.ref = 'offer:' || r.offer_id || ':' || r.id
                                   WHERE r.offer_id = {$id} ORDER BY r.rank")->fetchAll(\PDO::FETCH_NUM);
        $ctx->eq($rows, [['i:sales-ug', 'sent', 'i:sales-ug', 'sent'], ['i:dishnet-ug', 'sent', 'i:dishnet-ug', 'sent']],
                 'K14 each recipient\'s handset is the ledger form, and its ledger row sits on the same handset');
        $got = array_map(function ($c) { return [$c['instance'] ?? '', $c['number'] ?? '']; }, $calls);
        sort($got);
        $ctx->eq($got, [['DishNet-UG', '256700941402'], ['Sales-UG', '256700941401']], 'K14 the POSTs went to each channel\'s instance (handsets in their own order)');
    });

    $ctx->run('K15 a test whose record() meets a second connection holding the book\'s write lock past its busy_timeout: sent, ledger error; while it is its wording\'s only test --check offers-test reads NOT_YET no_test and Start is refused; once a recorded test of that wording exists both go, the error counted (OM119, OM120)', function () use ($ctx, $T0) {
        // B8-fix1 (B8-R03): the error answer made by Part F's real record() under a real lock (D 7761: "a test whose record()
        // meets a second connection holding the book's write lock past its 5 s busy_timeout, released before the settle"),
        // not planted, and --check offers-test read over what the three requests left.
        wo_ladder_stub($ctx);
        $b = wo_book($ctx, wo_cfg(), 'k');
        wo_admins($b);
        foreach (['retailers.json', 'kyc_config.json'] as $f) $b['store']->load($f);
        wo_warm($b, '256700941501', $T0, 86400);
        $id = wo_offer($b, $T0 - 600);
        $admin = $b['store']->load('retailers.json')[0];
        $store = new WoStore($b['store'], $b['data']);
        $ready = $ctx->dir('k15') . '/ready';
        $held = 0.0;
        $store->pdo->hooks[] = ['INSERT OR IGNORE INTO wa_auto_sends', function () use ($b, $ready, &$held) {
            $held = microtime(true);
            wo_bg('hold', $b['data'] . '/plugin.sqlite3', '7', $ready);       // past the 5 s busy_timeout, released before the settle's own wait ends
        }];
        $gw = new WoGateway(new WoLog());
        $clk = new WoClock($T0 - 300);
        $s = OfferSender::forInstall($ctx->tree(), $b['data'], $b['cfg'], ['store' => $store, 'clock' => $clk, 'gateway' => function () use ($gw) { return $gw; }]);
        $t1 = $s->sendTest($id, 1, 'sales', $admin);
        $row = function (int $tid) use ($b): array {
            return $b['pdo']->query('SELECT state, ledger FROM wa_offer_tests WHERE id = ' . $tid)->fetch(\PDO::FETCH_NUM) ?: [];
        };
        $ctx->eq([$t1['ok'], $row((int)$t1['test_id']), count(wo_lrows($b['pdo'], 'offer_test:' . $t1['test_id']))], [true, ['sent', 'error'], 0],
                 'K15 control: the first wording\'s test sent; its record() met the lock and answered error, no ledger row (the hold fired: ' . ($held > 0 ? 'yes' : 'no') . ')');
        $clk->t += 120;
        $t2 = $s->sendTest($id, 2, 'sales', $admin);
        $ctx->eq($row((int)$t2['test_id']), ['sent', 'recorded'], 'K15 control: the second wording\'s test sent and recorded');
        $seen = function () use ($b): void {
            $b['pdo']->exec("INSERT OR IGNORE INTO evo_webhook_seen (message_id, instance, event) SELECT wa_message_id, 'Sales-UG', 'offer.test' FROM wa_offer_tests WHERE wa_message_id IS NOT NULL");
        };
        $seen();
        $c = OfferSwitch::checkTest(wo_ro($b), $b['cfg'], $b['data'], $clk->t + 60);
        $ctx->eq([wo_st($c), wo_counted($c, 'record() answered error'), wo_counted($c, 'tests recorded')], ['NOT_YET no_test', 1, 1],
                 'K15 while the error test is its wording\'s only test: --check offers-test NOT_YET no_test, the error counted (OM120)', wo_detail($c));
        $o = OfferStore::find($b['pdo'], $id);
        $r = OfferStore::start($b['pdo'], $b['cfg'], $b['data'], [], [], $id, (string)$o['content_hash'], 7, 'Sandbox Admin', $T0, $gw);
        $ctx->eq([$r['ok'], $r['code']], [false, 'untested'], 'K15 … and Start refused, untested (OM120)');
        $clk->t += 120;
        $t3 = $s->sendTest($id, 1, 'sales', $admin);
        $seen();
        $ctx->eq($row((int)$t3['test_id']), ['sent', 'recorded'], 'K15 control: a second test of the first wording, recorded');
        $c = OfferSwitch::checkTest(wo_ro($b), $b['cfg'], $b['data'], $clk->t + 60);
        $ctx->eq([wo_st($c), wo_counted($c, 'record() answered error'), wo_counted($c, 'tests recorded')], ['PASS', 1, 2],
                 'K15 once a recorded test of that wording exists: --check offers-test PASS, the error counted and printed (OM119)', wo_detail($c));
        $r = OfferStore::start($b['pdo'], $b['cfg'], $b['data'], [], [], $id, (string)$o['content_hash'], 7, 'Sandbox Admin', $T0, $gw);
        $ctx->eq([$r['ok'], $r['code']], [true, ''], 'K15 … and Start admitted');
    });

    // The --check half of K (C-O1-*, C-O2-*), stage B8: cases KC01 … in tests/wa_offers/k_checks.php (loaded at the top).
    wo_k_checks($ctx);
}];
