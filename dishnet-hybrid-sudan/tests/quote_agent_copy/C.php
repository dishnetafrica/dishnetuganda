<?php
declare(strict_types=1);
/**
 * quote_agent_copy C — the webhook path (root docs/73 §H.5.4.3, §H.5.5, §H.5.8.2): webhook.php's own whQuotationEmail,
 * called directly in a process of its own (drive.php takes the function from the tree's file by its tokens), with the
 * fake uCRM, the real dispatcher and tests/fixtures/fake_smtp_server.php. TEST ONLY; every account and address fictitious.
 */

/** One webhook step for quote $qid of client 901. */
function qac_c_wh(int $qid, array $more = []): array
{
    return $more + ['do' => 'webhook', 'quote_id' => $qid, 'client_id' => 901, 'name' => 'Test Customer', 'quote_num' => 'QAC-' . $qid,
        'amount' => 1000.0, 'quote' => ['id' => $qid, 'status' => 1]];
}

/** A relay session with the agent taken out (its RCPT and its place in the Cc header), for the fault-injection comparison. */
function qac_c_noAgent(array $s, string $agent): array
{
    $s['rcpt_to'] = array_values(array_filter((array)($s['rcpt_to'] ?? []), function ($a) use ($agent) { return strcasecmp((string)$a, $agent) !== 0; }));
    $s['commands'] = array_values(array_filter((array)($s['commands'] ?? []), function ($c) use ($agent) { return stripos((string)$c, '<' . $agent . '>') === false; }));
    $s['data'] = preg_replace('/^Cc: (.*)$/mi', 'Cc: $1', str_replace([', ' . $agent, 'Cc: ' . $agent . "\r\n"], ['', ''], (string)($s['data'] ?? '')));
    return qac_mask($s);
}

/** The relay's new sessions since $n. */
function qac_c_new(string $tr, int $n, int $want = 1): array
{
    return array_slice(qac_sessions($tr, $n + $want), $n);
}

$QAC_SECTIONS['C'] = ['title' => 'The webhook path (§H.5.4.3, §H.5.5, §H.5.8.2)', 'run' => function (QfCtx $ctx) {
    qac_load($ctx);
    $tree = $ctx->tree();
    $base = qac_base($ctx);
    $A = QAC_AGENT;
    [$port, $tr] = qac_relay($ctx);
    $crm = ['client' => qac_client()];
    $on = function (array $more = []) { return qac_cfg('uganda', true, $more); };
    $ago = function (int $s): string { return date('Y-m-d H:i:s', time() - $s); };
    /** Run one webhook call on a fresh book; [result, the new sessions, decisions, book]. */
    $one = function (array $cfg, callable $plant, int $qid = 8001, array $spec = [], ?array $accounts = null) use ($ctx, $tree, $port, $tr, $crm): array {
        $b = qac_book($ctx, $cfg, 'c', $port, $accounts);
        $plant($b);
        $n = count(qac_sessions($tr));
        $r = qac_drive($ctx, $spec['root'] ?? $tree, $b, ($spec + ['crm' => $crm, 'steps' => [qac_c_wh($qid, $spec['step'] ?? [])]]));
        $s = qac_c_new($tr, $n, (int)($spec['want_sessions'] ?? 1));
        return [$r, $s, qac_decisions($b['pdo']), $b];
    };
    $cc = function (array $s): ?string { return preg_match('/^Cc: (.*)$/mi', (string)($s[0]['data'] ?? ''), $m) ? trim($m[1]) : null; };

    $ctx->run('C0 positive control: a KYC application holding the quote id → its submitter copied, one session, one decision', function () use ($ctx, $one, $on, $A, $cc) {
        [$r, $s, $d] = $one($on(), function ($b) { qac_app($b, ['crm_client_id' => 901, 'quote_id' => 8001, 'retailer_id' => 49, 'sales_person' => '']); });
        $ctx->eq([count($s), $s[0]['rcpt_to'] ?? null, $cc($s)], [1, ['billing@cust.example.test', $A['49']], $A['49']], 'C0 one session: the To, then the agent; Cc the agent',
            json_encode($r['whlog']) . $r['elog']);
        $ctx->eq([count($d), $d[0]['code'] ?? null, $d[0]['source'] ?? null, $d[0]['rule'] ?? null, $d[0]['agent_id'] ?? null, $d[0]['rcpt'] ?? null, $d[0]['send_ok'] ?? null],
            [1, 'added', 'kyc', 'kyc_submitter', 49, 'accepted', 1], 'C0 one decision: added, kyc, kyc_submitter, rcpt accepted, sent');
    });

    $ctx->run('C1 off: the transcript equals 221c9dc\'s; no table', function () use ($ctx, $one, $base) {
        if ($base === null) { $ctx->t(false, 'C1 Git supplies 221c9dc — a failure, never a skip'); return; }
        $plant = function ($b) { qac_app($b, ['crm_client_id' => 901, 'quote_id' => 8001, 'retailer_id' => 49]); };
        foreach (['cc off' => [], 'cc on' => ['email_cc_contacts' => '1']] as $k => $more) {
            [$rt, $st, , $bt] = $one(qac_cfg('uganda', false, $more), $plant);
            [$ro, $so] = $one(qac_cfg('uganda', false, $more), $plant, 8001, ['root' => $base]);
            $ctx->eq([qac_mask($st), $rt['whlog'], $rt['crm']], [qac_mask($so), $ro['whlog'], $ro['crm']], "C1 {$k}: the relay transcript, the webhook log and the uCRM requests are 221c9dc's");
            $ctx->eq(qac_tables($bt['pdo']), [], "C1 {$k}: no quote_agent_* table");
        }
    });

    $ctx->run('C2 a made maker; a KYC application by quote_followup C\'s fixture; already_copied through ccEnabled', function () use ($ctx, $one, $on, $A, $cc) {
        [, $s, $d] = $one($on(), function ($b) {
            $b['store']->appendWithId('quote_agent_makers.json', ['client_id' => 901, 'maker_id' => 41, 'quote_id' => 8001, 'state' => 'made', 'at' => time() - 5, 'settled_at' => time() - 4]);
        });
        $ctx->eq([$cc($s), $d[0]['source'] ?? null, $d[0]['agent_id'] ?? null], [$A['41'], 'maker', 41], 'C2 a made maker for the quote id → that maker, source maker');
        require_once dirname(__DIR__) . '/quote_followup/C_owners.php';
        $got = []; $want = [];
        foreach (qf_kyc_agent_fixtures() as $name => $f) {
            $acc = array_map(function ($r) { return $r + ['email' => 'r' . $r['id'] . '@agents.example.test']; }, $f['retailers']);
            [, $s, $d] = $one($on(), function ($b) use ($f) { qac_app($b, $f['application'] + ['crm_client_id' => 901, 'quote_id' => 8001]); }, 8001, [], $acc);
            $got[$name] = [$d[0]['agent_id'] ?? null, $d[0]['code'] ?? null, $cc($s)];
            $id = $f['want']['id'];
            $want[$name] = [$id, $id === null ? 'no_agent' : 'added', $id === null ? null : 'r' . $id . '@agents.example.test'];
        }
        $ctx->eq($got, $want, 'C2 each declared fixture: the account quote_followup C gives, copied (none → no_agent, no Cc)');
        [, $s, $d] = $one($on(['email_cc_contacts' => '1']), function ($b) { qac_app($b, ['crm_client_id' => 901, 'quote_id' => 8001, 'retailer_id' => 45]); });
        $ctx->eq([$d[0]['code'] ?? null, $d[0]['rcpt'] ?? null, $cc($s)], ['already_copied', 'none', 'other@cust.example.test'],
            'C2 email_cc_contacts on and the agent a client contact → already_copied, the client\'s own Cc only');
    });

    $ctx->run('C3 in flight', function () use ($ctx, $one, $on, $A, $ago, $cc) {
        $form = function (array $more = []) use ($ago) {
            return $more + ['crm_client_id' => 901, 'quote_ref' => 'PENDING', 'quote_id' => null, 'submitted_at' => $ago(60), 'retailer_id' => 49];
        };
        $cases = [
            'a form application 60 s old'          => [[$form()], [], $A['49'], 'kyc_in_flight'],
            '901 s old'                            => [[$form(['submitted_at' => $ago(901)])], [], null, 'none'],
            'refused'                              => [[$form(['quote_refused' => 'refused (test)'])], [], null, 'none'],
            'a claim stamp 60 s old, old app'      => [[$form(['submitted_at' => '2025-10-01 09:00:00', 'quote_make_claim_ts' => time() - 60])], [], $A['49'], 'kyc_in_flight'],
            '… with an earlier quote_refused'      => [[$form(['submitted_at' => '2025-10-01 09:00:00', 'quote_make_claim_ts' => time() - 60, 'quote_refused' => 'x'])], [], $A['49'], 'kyc_in_flight'],
            '… with an earlier quote_error'        => [[$form(['submitted_at' => '2025-10-01 09:00:00', 'quote_make_claim_ts' => time() - 60, 'quote_error' => 'x'])], [], $A['49'], 'kyc_in_flight'],
            'the uCRM retry: crm_synced_at 60 s'   => [[$form(['submitted_at' => '2025-10-01 09:00:00', 'crm_synced_at' => $ago(60), 'quote_ref' => 'KYC-REF-1'])], [], $A['49'], 'kyc_in_flight'],
            'a making maker 60 s old'              => [[], [['client_id' => 901, 'maker_id' => 41, 'quote_id' => null, 'state' => 'making', 'at' => time() - 60, 'settled_at' => null]], $A['41'], 'maker_in_flight'],
            'one of each'                          => [[$form()], [['client_id' => 901, 'maker_id' => 41, 'quote_id' => null, 'state' => 'making', 'at' => time() - 60, 'settled_at' => null]], null, 'in_flight'],
            'two applications'                     => [[$form(), $form(['retailer_id' => 50])], [], null, 'in_flight'],
            'quote_created true on a fresh form'   => [[$form(['quote_created' => true])], [], null, 'none'],
        ];
        $got = []; $want = [];
        foreach ($cases as $k => [$apps, $makers, $agent, $src]) {
            [, $s, $d] = $one($on(), function ($b) use ($apps, $makers) {
                foreach ($apps as $a) qac_app($b, $a);
                foreach ($makers as $m) $b['store']->appendWithId('quote_agent_makers.json', $m);
            });
            $got[$k] = [$cc($s), $d[0]['source'] ?? null, $d[0]['code'] ?? null];
            $want[$k] = [$agent, $src, $agent !== null ? 'added' : ($src === 'in_flight' ? 'agent_ambiguous' : 'no_agent')];
        }
        $ctx->eq($got, $want, 'C3 each case: the copy, the source, the code');
    });

    $ctx->run('C3b positive evidence only: each → no_agent and no Cc', function () use ($ctx, $one, $on, $ago, $cc) {
        $form = function (array $more = []) use ($ago) {
            return $more + ['crm_client_id' => 901, 'quote_ref' => 'PENDING', 'quote_id' => null, 'submitted_at' => $ago(60), 'retailer_id' => 49];
        };
        $cases = [
            'linked to an existing client'     => [$form(['submitted_at' => '2025-10-01 09:00:00', 'crm_synced_at' => $ago(60), 'crm_linked_existing' => true]), [], []],
            'auto quoting off in the file'     => [$form(), ['kyc_auto_quote_enabled' => false], ['config' => qac_cfg('uganda', true)]],
            'auto quoting off in the request'  => [$form(), [], ['config' => qac_cfg('uganda', true, ['kyc_auto_quote_enabled' => false])]],
            'quote_debug set'                  => [$form(['quote_debug' => 'auto quoting off (test)']), [], []],
            'quote_ref empty'                  => [$form(['quote_ref' => '']), [], []],
            'adminNotes without the mark'      => [$form(), [], ['step' => ['quote' => ['id' => 8001, 'status' => 1, 'adminNotes' => 'Plugin ref:  · Agent: someone']]]],
            'the gate-off retry_quote'         => [$form(['submitted_at' => '2025-10-01 09:00:00', 'quote_error' => 'gate off (test)']), [], []],
        ];
        $got = [];
        foreach ($cases as $k => [$app, $cfgMore, $spec]) {
            [, $s, $d] = $one($on($cfgMore), function ($b) use ($app) { qac_app($b, $app); }, 8001, $spec);
            $got[$k] = [$d[0]['code'] ?? null, $cc($s), count($s)];
        }
        $ctx->eq($got, array_fill_keys(array_keys($cases), ['no_agent', null, 1]), 'C3b each: no_agent, no Cc, the e-mail sent');
        // The mark, present: the same application counts (a control on the adminNotes case).
        [, $s, $d] = $one($on(), function ($b) use ($form) { qac_app($b, $form()); }, 8001,
            ['step' => ['quote' => ['id' => 8001, 'status' => 1, 'adminNotes' => 'KYC application #1']]]);
        $ctx->eq([$d[0]['code'] ?? null, $d[0]['source'] ?? null], ['added', 'kyc_in_flight'], 'C3b control: the same application with its mark in adminNotes is copied');
    });

    $ctx->run('C4 a quote typed in uCRM for a client whose only application is 30 days old: no_agent', function () use ($ctx, $one, $on, $ago, $cc) {
        [, $s, $d] = $one($on(), function ($b) use ($ago) {
            qac_app($b, ['crm_client_id' => 901, 'quote_id' => 7999, 'quote_ref' => 'OLD-1', 'quote_created' => true, 'submitted_at' => $ago(30 * 86400), 'retailer_id' => 49]);
        });
        $ctx->eq([$d[0]['code'] ?? null, $d[0]['source'] ?? null, $cc($s)], ['no_agent', 'none', null], 'C4 the client\'s first application is never used');
    });

    $ctx->run('C5 the QEMAIL claim held by the app path: nothing sent, nothing recorded', function () use ($ctx, $one, $on) {
        [$r, $s, $d] = $one($on(), function ($b) {
            qac_app($b, ['crm_client_id' => 901, 'quote_id' => 8001, 'retailer_id' => 49]);
            CustomerEmailDispatcher::claimOnce($b['pdo'], 'QEMAIL8001');
        }, 8001, ['want_sessions' => 0]);
        $ctx->eq([count($s), $d, end($r['whlog'])[1] ?? null], [0, [], 'Quotation email for #8001 already claimed — skipping'], 'C5 no session, no decision, the claim\'s log line');
    });

    $ctx->run('C6 the source assertions of test_quotation_audit §5 and test_mail_quote hold on this tree', function () use ($ctx, $tree) {
        $wh = (string)file_get_contents($tree . '/webhook.php');
        $qs = (string)file_get_contents($tree . '/lib/QuotationService.php');
        $ctx->t(strpos($wh, "], (string)\$quoteId, \$atts);") !== false && strpos($wh, "], '', \$atts);") === false, 'C6 the dispatcher is handed the quote id as its dedupe key');
        $callPos = strpos($wh, 'whQuotationEmail($quoteId');
        $gatePos = strpos($wh, 'if ($phone && $amount > 0) {', $callPos ?: 0);
        $ctx->t($callPos !== false && $gatePos !== false && $callPos < $gatePos, 'C6 quote.add calls whQuotationEmail before the phone gate');
        $ctx->t(strpos($wh, 'QEMAIL{$quoteId}') !== false && strpos($qs, 'QEMAIL{$quoteId}') !== false, 'C6 both entry points claim the same key');
        $ctx->t(preg_match('/releaseClaim\\(\\$pdo, "QEMAIL\\{\\$quoteId\\}"\\);\s*\n\s*whLog[^\n]*NOT sent/', $wh) === 1, 'C6 the failure branch releases the claim');
        $ctx->t(preg_match('/catch \\(\\\\Throwable \\$e\\) \\{\s*\n\s*if \\(isset\\(\\$pdo\\)[^\n]*releaseClaim/', $wh) === 1, 'C6 a thrown exception releases it too');
        $ctx->t(strpos($wh, 'QuotePdfSource::fetch($crm, $dataDir, $config, $quoteId, $client, $quote)') !== false, 'C6 the webhook passes the quote it holds');
    });

    $ctx->run('C7 one decision per call, as the dispatcher carried it out', function () use ($ctx, $one, $on) {
        $t0 = time();
        [, $s, $d] = $one($on(), function ($b) { qac_app($b, ['crm_client_id' => 901, 'quote_id' => 8001, 'retailer_id' => 49]); }, 8001, ['step' => ['quote_num' => 'QAC-NUM-77']]);
        $t1 = time();
        $at = $d[0]['decided_at'] ?? '';
        $atT = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $at) ? strtotime($at . ' UTC') : 0;
        $ctx->eq([count($d), $d[0]['path'] ?? null, $d[0]['rcpt'] ?? null, $d[0]['number'] ?? null, $atT >= $t0 && $atT <= $t1, array_keys($d[0] ?? [])],
            [1, 'webhook', 'accepted', 'QAC-NUM-77', true, ['quote_id', 'number', 'path', 'outcome', 'code', 'source', 'rule', 'agent_id', 'rcpt', 'send_ok', 'decided_at', 'id']],
            'C7 one decision: path webhook, the dispatcher\'s rcpt, the number the e-mail names, a UTC decided_at taken within the call');
        [, $s, $d] = $one($on(), function ($b) {
            qac_app($b, ['crm_client_id' => 901, 'quote_id' => 8001, 'retailer_id' => 49]);
            qac_row($b, 8001, time() - 60, 'sent');
        }, 8001, ['want_sessions' => 0]);
        $ctx->eq([count($s), count($d), $d[0]['code'] ?? null, $d[0]['rcpt'] ?? null, $d[0]['outcome'] ?? null], [0, 1, 'not_composed', 'none', 'skipped'],
            'C7 the dispatcher returning early (already sent) → not_composed');
    });

    $ctx->run('C8 a throwing store inside forWebhook: the e-mail as without P7, the claim kept, code error', function () use ($ctx, $one, $on, $base) {
        $plant = function ($b) { qac_app($b, ['crm_client_id' => 901, 'quote_id' => 8001, 'retailer_id' => 49]); };
        [$r, $s, $d, $b] = $one($on(), $plant, 8001, ['store_faults' => ['read' => ['quote_agent_copies.json', 'kyc_applications.json', 'retailers.json']]]);
        [, $s0] = $one(qac_cfg('uganda'), $plant);
        $q = (int)$b['pdo']->query("SELECT COUNT(*) FROM notification_dedup WHERE dedup_key = 'QEMAIL8001'")->fetchColumn();
        $ctx->eq([qac_mask($s), $q, $d[0]['code'] ?? null], [qac_mask($s0), 1, 'error'], 'C8 the transcript as P7 off, QEMAIL kept, the decision error');
    });

    $ctx->run('C9 the first decision fixes the resolution (P7S-05)', function () use ($ctx, $tree, $port, $tr, $crm, $on, $ago, $cc) {
        $b = qac_book($ctx, $on(), 'c9', 1);   // port 1: nothing listens — the first send fails
        $r1 = qac_drive($ctx, $tree, $b, ['crm' => $crm, 'steps' => [qac_c_wh(8009)]]);
        $d1 = qac_decisions($b['pdo']);
        $ctx->eq([count($d1), $d1[0]['code'] ?? null, $d1[0]['send_ok'] ?? null], [1, 'no_agent', 0],
            'C9 run 1: a quote typed in uCRM → no_agent, the send fails');
        $ctx->t(strpos((string)(end($r1['whlog'])[1] ?? ''), 'Quotation email NOT sent') === 0, 'C9 … and the webhook logs NOT sent (the claim released)');
        // The handler's own write: the quote id on the client's 30-day-old application.
        qac_app($b, ['crm_client_id' => 901, 'quote_id' => 8009, 'quote_ref' => 'QAC-8009', 'quote_created' => true, 'submitted_at' => $ago(30 * 86400), 'retailer_id' => 49]);
        qac_mail($b['data'], $port);
        $n = count(qac_sessions($tr));
        qac_drive($ctx, $tree, $b, ['crm' => $crm, 'steps' => [qac_c_wh(8009)]]);
        $s = qac_c_new($tr, $n);
        $d = qac_decisions($b['pdo']);
        $ctx->eq([count($d), $d[1]['code'] ?? null, $d[1]['source'] ?? null, $cc($s), count($s)], [2, 'no_agent', 'first_decision', null, 1],
            'C9 run 2 of the same quote: no_agent, source first_decision, no Cc — the application the webhook wrote is never read');
    });

    $ctx->run('C10 P7 builds no MailService: uCRM is asked as often with P7 on as off', function () use ($ctx, $tree, $port, $crm) {
        [$url, $log] = qac_counter($ctx);
        $n = [];
        foreach (['off' => false, 'on' => true] as $k => $p7) {
            $b = qac_book($ctx, qac_cfg('uganda', $p7), 'c10');
            qac_app($b, ['crm_client_id' => 901, 'quote_id' => 8010, 'retailer_id' => 49]);
            qac_mail($b['data'], $port, ['use_ucrm_email' => true]);
            file_put_contents($b['dir'] . '/ucrm.json', json_encode(['ucrmLocalUrl' => $url . '/', 'ucrmPublicUrl' => $url . '/crm/', 'pluginAppKey' => 'qac-test-key']));
            $before = qac_counted($log);
            qac_drive($ctx, $tree, $b, ['crm' => $crm, 'steps' => [qac_c_wh(8010)]]);
            $n[$k] = qac_counted($log) - $before;
        }
        $ctx->t($url !== '' && $n['off'] > 0, 'C10 positive control: the dispatcher\'s MailService asks uCRM', json_encode($n));
        $ctx->eq($n['on'], $n['off'], 'C10 the same number of uCRM requests with P7 on as off');
    });

    $ctx->run('C11 the webhook\'s client read failed, or changed before the dispatcher\'s', function () use ($ctx, $one, $on, $A, $cc) {
        $plant = function ($b) { qac_app($b, ['crm_client_id' => 901, 'quote_id' => 8011, 'retailer_id' => 49]); };
        [, $s, $d] = $one($on(), $plant, 8011, ['step' => ['client' => []]]);
        $ctx->eq([$d[0]['code'] ?? null, count($s), $s[0]['rcpt_to'] ?? null, $cc($s)], ['customer_unread', 1, ['billing@cust.example.test'], null],
            'C11 the webhook\'s read failed ([]): customer_unread, the e-mail sent by the dispatcher\'s own read, no Cc');
        $later = qac_client(['contacts' => [['email' => 'billing@cust.example.test', 'isBilling' => true], ['email' => strtoupper($A['49'])]]]);
        [, $s, $d] = $one($on(['email_cc_contacts' => '1']), $plant, 8011, ['crm' => ['client' => $later], 'step' => ['client' => qac_client()]]);
        $ctx->eq([$d[0]['code'] ?? null, $d[0]['rcpt'] ?? null, $d[0]['outcome'] ?? null, $s[0]['rcpt_to'] ?? null],
            ['already_copied', 'excluded', 'skipped', ['billing@cust.example.test', $A['49']]],
            'C11 the agent a client contact by the dispatcher\'s read: already_copied, rcpt excluded, never added (the agent got it as a contact)');
    });

    $ctx->run('C12 fault injection: each stub in turn', function () use ($ctx, $one, $on, $base, $A) {
        if ($base === null) { $ctx->t(false, 'C12 Git supplies 221c9dc'); return; }
        $plant = function ($b) { qac_app($b, ['crm_client_id' => 901, 'quote_id' => 8012, 'retailer_id' => 49]); };
        [$o, $so, , $bo] = $one($on(), $plant, 8012, ['root' => $base]);
        $oQ = (int)$bo['pdo']->query("SELECT COUNT(*) FROM notification_dedup WHERE dedup_key = 'QEMAIL8012'")->fetchColumn();
        $bad = [];
        foreach (array_merge(['*'], qac_public_methods($ctx)) as $m) {
            [$r, $s, $d, $b] = $one($on(), $plant, 8012, ['root' => qac_stub($ctx, $m)]);
            $q = (int)$b['pdo']->query("SELECT COUNT(*) FROM notification_dedup WHERE dedup_key = 'QEMAIL8012'")->fetchColumn();
            $why = [];
            if (count($s) !== 1 || qac_c_noAgent($s[0], $A['49']) !== qac_c_noAgent($so[0], $A['49'])) $why[] = 'transcript';
            if ($r['crm'] !== $o['crm']) $why[] = 'uCRM requests';
            if ($r['whlog'] !== $o['whlog']) $why[] = 'webhook log ' . json_encode($r['whlog']);
            if ($q !== $oQ) $why[] = 'QEMAIL';
            if ($m === '*' && ($d !== [] || count($s[0]['rcpt_to'] ?? []) !== 1)) $why[] = 'every method throwing left a copy or a decision';
            if (in_array($m, ['forWebhook', 'state'], true) && $d !== []) $why[] = 'a throw before the decision left one';
            if ($why !== []) $bad[$m] = implode('; ', $why);
        }
        $ctx->eq($bad, [], 'C12 every stub: the transcript (at most the agent added), the uCRM requests, the webhook log and QEMAIL are 221c9dc\'s; a throw before the decision leaves none');
    });
}];
