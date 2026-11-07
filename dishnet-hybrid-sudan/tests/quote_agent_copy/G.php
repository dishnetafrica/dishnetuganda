<?php
declare(strict_types=1);
/**
 * quote_agent_copy G — the check, AC-ON (root docs/73 §H.5.10.4; rulings R13, R18): read-only over a query_only
 * connection; each decision judged only by what its own row recorded and placed by its decided_at; what P7 could not
 * have known printed, never a FAIL. Planted books, and books made by the real senders (drive.php). TEST ONLY; every
 * number, id and address fictitious.
 */

/** A book for the check with P7's since-stamp at $since (unix time). */
function qac_g_book(QfCtx $ctx, int $since, array $more = [], ?int $port = null): array
{
    return qac_book($ctx, qac_cfg('uganda', true, $more + ['quote_agent_cc_since' => gmdate('Y-m-d H:i:s', $since)]), 'g', $port);
}

/** G2's PASS fixture: a copy and a skip on each path, each e-mail with its decision. */
function qac_g2(array $b, int $since): void
{
    $at = function (int $o) use ($since): string { return gmdate('Y-m-d H:i:s', $since + $o); };
    qac_plant($b, ['path' => 'plugin', 'quote_id' => 734501, 'number' => 'QAC-NUM-55501', 'decided_at' => $at(1000)]);
    qac_line($b, $since + 1003, 'QAC-NUM-55501');
    qac_plant($b, ['path' => 'webhook', 'quote_id' => 734512, 'number' => 'QAC-NUM-55512', 'source' => 'kyc', 'rule' => 'kyc_submitter', 'agent_id' => 49, 'decided_at' => $at(1100)]);
    qac_row($b, 734512, $since + 1101);
    qac_plant($b, ['path' => 'plugin', 'quote_id' => 734502, 'number' => 'QAC-NUM-55502', 'outcome' => 'skipped', 'code' => 'same_as_customer', 'agent_id' => 44, 'rcpt' => 'none', 'decided_at' => $at(1200)]);
    qac_line($b, $since + 1201, 'QAC-NUM-55502');
    qac_plant($b, ['path' => 'webhook', 'quote_id' => 734513, 'number' => 'QAC-NUM-55513', 'outcome' => 'skipped', 'code' => 'not_composed', 'source' => 'maker', 'agent_id' => 41, 'rcpt' => 'none', 'send_ok' => 0, 'decided_at' => $at(1300)]);
}

$QAC_SECTIONS['G'] = ['title' => 'The check, AC-ON (§H.5.10.4; rulings R13, R18)', 'run' => function (QfCtx $ctx) {
    qac_load($ctx);
    $tree = $ctx->tree();
    $A = QAC_AGENT;
    $S = time() - 86400;                      // the since-stamp of the planted books
    $N = $S + 3600;                           // their now: the window is [S+300, S+3300]
    $chk = function (array $b, int $now) use ($tree): array { return QuoteAgentCopy::check(qac_ro($b['data']), $tree, $b['data'], $now); };

    $ctx->run('G2 positive control: a PASS fixture, with its counts by path, code and rcpt', function () use ($ctx, $chk, $S, $N) {
        $b = qac_g_book($ctx, $S);
        qac_g2($b, $S);
        $c = $chk($b, $N);
        $ctx->eq($c['status'], 'PASS', 'G2 PASS', json_encode($c));
        $k = $c['counts'];
        $ctx->eq([$k['p1_decisions'] ?? null, $k['p1_added'] ?? null, $k['p1_skipped_same_as_customer'] ?? null, $k['p1_rcpt_accepted'] ?? null, $k['p1_sent_carrying_copy'] ?? null,
                  $k['p3_decisions'] ?? null, $k['p3_added'] ?? null, $k['p3_skipped_not_composed'] ?? null, $k['p3_rcpt_accepted'] ?? null, $k['p3_failed_without_copy'] ?? null,
                  $k['p3_source_kyc'] ?? null, $k['no_log_line'] ?? null, $k['forgotten_resend_tool'] ?? null],
            [2, 1, 1, 1, 1, 2, 1, 1, 1, 1, 1, 0, 0], 'G2 the counts by path, code, rcpt and source');
        $ctx->t(in_array('plugin mail: plugin SMTP', $c['notes'], true) || in_array('plugin mail is not configured (Settings → System → Email)', $c['notes'], true),
            'G2 plugin mail, as it reads now, is a note line', json_encode($c['notes']));
    });

    $ctx->run('G1 an empty book: NOT_YET no_traffic, nothing written, no table made', function () use ($ctx, $tree, $S, $N) {
        $b = qac_g_book($ctx, $S);
        $ro = qac_ro($b['data']);
        $before = $ro->query('SELECT name FROM sqlite_master ORDER BY name')->fetchAll(\PDO::FETCH_COLUMN);
        $c = QuoteAgentCopy::check($ro, $tree, $b['data'], $N);
        $after = $ro->query('SELECT name FROM sqlite_master ORDER BY name')->fetchAll(\PDO::FETCH_COLUMN);
        $ctx->eq([$c['status'], $after === $before, (int)$ro->query('SELECT total_changes()')->fetchColumn(), qac_tables($b['pdo'])],
            ['NOT_YET no_traffic', true, 0, []], 'G1 no_traffic; sqlite_master and total_changes() unchanged; no quote_agent_* table');
    });

    $ctx->run('G3 each FAIL by one planted row or line; none from a failed send or plugin mail', function () use ($ctx, $chk, $S, $N) {
        $at = function (int $o) use ($S): string { return gmdate('Y-m-d H:i:s', $S + $o); };
        $cases = [
            'a path-3 sent row with no decision' => ['FAIL decision_missing', function ($b) use ($S) { qac_row($b, 734520, $S + 1400); }],
            '… while another quote\'s extra decision would balance the count' => ['FAIL decision_missing', function ($b) use ($S, $at) {
                qac_row($b, 734520, $S + 1400);
                qac_plant($b, ['path' => 'webhook', 'quote_id' => 734521, 'number' => 'x', 'source' => 'maker', 'decided_at' => $at(1400)]); }],
            'a path-1 line whose number has no decision' => ['FAIL decision_missing', function ($b) use ($S) { qac_line($b, $S + 1500, 'QAC-NUM-55530'); }],
            '… while another quote\'s extra path-1 decision would balance the count' => ['FAIL decision_missing', function ($b) use ($S, $at) {
                qac_line($b, $S + 1500, 'QAC-NUM-55530');
                qac_plant($b, ['path' => 'plugin', 'quote_id' => 734531, 'number' => 'QAC-NUM-55531', 'decided_at' => $at(1500)]); }],
            'two lines of one number with one decision' => ['FAIL decision_missing', function ($b) use ($S) { qac_line($b, $S + 1600, 'QAC-NUM-55501'); }],
            'added with no agent' => ['FAIL added_without_agent', function ($b) use ($at) { qac_plant($b, ['quote_id' => 734540, 'number' => 'n1', 'agent_id' => null, 'decided_at' => $at(1700)]); }],
            'added from source in_flight' => ['FAIL added_without_agent', function ($b) use ($at) { qac_plant($b, ['quote_id' => 734541, 'number' => 'n2', 'source' => 'in_flight', 'decided_at' => $at(1700)]); }],
            'added from source none' => ['FAIL added_without_agent', function ($b) use ($at) { qac_plant($b, ['quote_id' => 734542, 'number' => 'n3', 'source' => 'none', 'decided_at' => $at(1700)]); }],
            'added from a source outside the list' => ['FAIL added_without_agent', function ($b) use ($at) { qac_plant($b, ['quote_id' => 734543, 'number' => 'n4', 'source' => 'guess', 'decided_at' => $at(1700)]); }],
            'a code outside the list' => ['FAIL unknown_code', function ($b) use ($at) { qac_plant($b, ['quote_id' => 734550, 'number' => 'n5', 'outcome' => 'skipped', 'code' => 'mystery', 'decided_at' => $at(1800)]); }],
            'an rcpt outside the list' => ['FAIL unknown_code', function ($b) use ($at) { qac_plant($b, ['quote_id' => 734551, 'number' => 'n6', 'rcpt' => 'maybe', 'decided_at' => $at(1800)]); }],
            'a decided_at that is not a UTC stamp' => ['FAIL unknown_code', function ($b) { qac_plant($b, ['quote_id' => 734552, 'number' => 'n7', 'decided_at' => 'yesterday']); }],
            'a failed send carrying a copy' => ['PASS', function ($b) use ($at) { qac_plant($b, ['quote_id' => 734560, 'number' => 'n8', 'path' => 'webhook', 'source' => 'maker', 'send_ok' => 0, 'rcpt' => 'none', 'decided_at' => $at(1900)]); }],
        ];
        $got = []; $want = [];
        foreach ($cases as $k => [$st, $plant]) {
            $b = qac_g_book($ctx, $S);
            qac_g2($b, $S);
            $plant($b);
            $got[$k] = $chk($b, $N)['status'];
            $want[$k] = $st;
        }
        $b = qac_g_book($ctx, $S, ['quote_agent_cc_since' => 'not a stamp']);
        qac_g2($b, $S);
        $got['since unreadable'] = $chk($b, $N)['status'];
        $want['since unreadable'] = 'FAIL since_unreadable';
        $b = qac_g_book($ctx, $S);
        qac_g2($b, $S);
        @unlink($b['data'] . '/email_settings.json');
        $got['plugin mail switched off'] = $chk($b, $N)['status'];
        $want['plugin mail switched off'] = 'PASS';
        $ctx->eq($got, $want, 'G3 each planted fault → its FAIL; a failed send and plugin mail switched off → PASS');
    });

    $ctx->run('G3b CustomerEmailDispatcher::forget() of a sent quotation in the window: PASS, "forgotten (resend tool)" 1', function () use ($ctx, $chk, $S, $N) {
        $b = qac_g_book($ctx, $S);
        qac_g2($b, $S);
        CustomerEmailDispatcher::forget($b['pdo'], 'quotation', '734512');
        $c = $chk($b, $N);
        $ctx->eq([$c['status'], $c['counts']['forgotten_resend_tool'] ?? null, in_array('forgotten (resend tool): 1', $c['notes'], true)], ['PASS', 1, true], 'G3b PASS, forgotten 1, printed');
    });

    $ctx->run('G4 NOT_YET too_soon within 600 s of on', function () use ($ctx, $chk) {
        $now = time();
        $b = qac_g_book($ctx, $now - 500);
        qac_g2($b, $now - 2000);
        $ctx->eq([$chk($b, $now)['status'], $chk($b, $now + 100)['status']], ['NOT_YET too_soon', 'NOT_YET no_traffic'],
            'G4 500 s after on: too_soon; 600 s after: the window opens (empty here: no_traffic)');
    });

    // Rule 1 (root docs/73 §H.5.10.4; rulings R3, R18) reads the since-stamp as the other parts read theirs — UtcClock::parse, or unix digits as
    // QuoteTracker reads quote_tracking_since — so a correct switch-on never reads since_unreadable for its stamp's form.
    $ctx->run('G13 the since-stamp in each form the codebase reads: the same verdict as the storage form, never since_unreadable', function () use ($ctx, $chk, $S, $N) {
        $b = qac_g_book($ctx, $S);
        qac_g2($b, $S);
        $ref = $chk($b, $N);
        $ctx->eq($ref['status'], 'PASS', 'G13 positive control: the storage form Y-m-d H:i:s reads PASS', json_encode($ref));
        $kla = (new \DateTime('@' . $S))->setTimezone(new \DateTimeZone(QF_TZ))->format('Y-m-d\\TH:i:sP');
        $forms = [
            'ISO 8601 with Z'          => gmdate('Y-m-d\\TH:i:s\\Z', $S),
            'ISO 8601 with +00:00'     => gmdate('Y-m-d\\TH:i:s', $S) . '+00:00',
            'a trailing UTC'           => gmdate('Y-m-d H:i:s', $S) . ' UTC',
            'the Kampala offset +03:00' => $kla,
            'unix seconds in digits'   => (string)$S,
            'unix seconds, a JSON int' => $S,
            'the storage form, padded' => ' ' . gmdate('Y-m-d H:i:s', $S) . ' ',
        ];
        $got = []; $want = [];
        foreach ($forms as $k => $v) {
            $b = qac_g_book($ctx, $S, ['quote_agent_cc_since' => $v]);
            qac_g2($b, $S);
            $got[$k] = $chk($b, $N);
            $want[$k] = $ref;
        }
        $ctx->eq($got, $want, 'G13 each form: the storage form\'s status, counts and notes (an offset honoured as written)');
        $now = time();
        $b = qac_g_book($ctx, $now - 500, ['quote_agent_cc_since' => gmdate('Y-m-d\\TH:i:s\\Z', $now - 500)]);
        qac_g2($b, $now - 2000);
        $ctx->eq($chk($b, $now)['status'], 'NOT_YET too_soon', 'G13 an ISO since-stamp 500 s old: too_soon, never since_unreadable');
        $bad = ['not a stamp' => 'not a stamp', 'empty' => '', 'blank' => '   ', 'zero' => '0', 'JSON true' => true, 'JSON false' => false,
                'a list' => ['2026-11-05 10:00:00'], 'absent' => null];
        $got = []; $want = [];
        foreach ($bad as $k => $v) {
            $cfg = qac_cfg('uganda', true);
            if ($v === null) unset($cfg['quote_agent_cc_since']); else $cfg['quote_agent_cc_since'] = $v;
            $b = qac_book($ctx, $cfg, 'g');
            qac_g2($b, $S);
            $got[$k] = $chk($b, $N)['status'];
            $want[$k] = 'FAIL since_unreadable';
        }
        $ctx->eq($got, $want, 'G13 what is no time at all still reads since_unreadable (the rule kept)');
    });

    $ctx->run('G5 the check prints no address, no id and no quote number', function () use ($ctx, $chk, $S, $N) {
        $b = qac_g_book($ctx, $S);
        qac_g2($b, $S);
        qac_row($b, 734520, $S + 1400);
        $out = json_encode([$chk($b, $N), $chk(qac_g_book($ctx, $S), $N)], JSON_UNESCAPED_UNICODE);
        $hit = array_values(array_filter(['@', '734501', '734512', '734502', '734513', '734520', 'QAC-NUM', 'cust.example', 'agents.example'], function ($n) use ($out) { return strpos($out, $n) !== false; }));
        $ctx->eq($hit, [], 'G5 none of the fixture ids, numbers or addresses appear', $out);
    });

    $ctx->run('G6 a webhook send, --clear-claim, then the webhook again: PASS, "sent again" 1', function () use ($ctx, $tree, $chk) {
        [$port] = qac_relay($ctx);
        $b = qac_book($ctx, qac_cfg('uganda', true), 'g6', $port);
        qac_app($b, ['crm_client_id' => 901, 'quote_id' => 8601, 'retailer_id' => 49]);
        qac_drive($ctx, $tree, $b, ['crm' => ['client' => qac_client()], 'steps' => [qac_c_wh(8601), ['do' => 'forget', 'quote_id' => 8601], qac_c_wh(8601)]]);
        $d = qac_decisions($b['pdo']);
        $c = $chk($b, time() + 600);
        $ctx->eq([count($d), $d[1]['source'] ?? null, $d[1]['code'] ?? null, $c['status'], $c['counts']['sent_again'] ?? null], [2, 'first_decision', 'added', 'PASS', 1],
            'G6 two decisions, the second by first_decision; PASS, sent again 1', json_encode($c));
    });

    $ctx->run('G7 a copied send that failed before the agent\'s RCPT: PASS, counted at rcpt none, with its path\'s line', function () use ($ctx, $tree, $chk, $A) {
        $res = [];
        foreach (['no relay listening' => null, 'the customer\'s RCPT refused' => ['refuse' => ['billing@cust.example.test']]] as $k => $rules) {
            $port = 1;
            if ($rules !== null) [$port] = qac_relay($ctx, $rules);
            $b = qac_book($ctx, qac_cfg('uganda', true), 'g7', $port);
            qac_app($b, ['crm_client_id' => 901, 'quote_id' => 8701, 'retailer_id' => 49]);
            qac_drive($ctx, $tree, $b, ['crm' => ['client' => qac_client()], 'steps' => [qac_c_wh(8701)]]);
            $c = $chk($b, time() + 600);
            $res["path 3, {$k}"] = [$c['status'], $c['counts']['p3_failed_carrying_copy_rcpt_none'] ?? null,
                in_array('path 3: failed carrying a copy 1 — a lost e-mail: resend with tools/quote_email_send.php', $c['notes'], true)];
            $b = qac_book($ctx, qac_cfg('uganda', true), 'g7', $port);
            $r = qac_drive($ctx, $tree, $b, ['crm' => ['client' => qac_client(), 'quote_id' => 8702], 'mailer' => 'real', 'steps' => [qac_b_app(['id' => 41])]]);
            $c = $chk($b, time() + 600);
            $res["path 1, {$k}"] = [$c['status'], $c['counts']['p1_failed_carrying_copy_rcpt_none'] ?? null,
                in_array("path 1: failed carrying a copy 1 — handed to uCRM's /send", $c['notes'], true),
                count(array_filter($r['crm'], function ($q) { return $q[0] === 'PATCH' && substr($q[1], -5) === '/send'; }))];
        }
        $ctx->eq($res, ['path 3, no relay listening' => ['PASS', 1, true], 'path 1, no relay listening' => ['PASS', 1, true, 1],
                        'path 3, the customer\'s RCPT refused' => ['PASS', 1, true], 'path 1, the customer\'s RCPT refused' => ['PASS', 1, true, 1]],
            'G7 each: PASS, "failed carrying a copy" 1 at rcpt none, its line; path 1 hands over to uCRM\'s /send once');
    });

    $ctx->run('G8 correct copies, then plugin mail switched off, then uCRM\'s mailer: PASS each, printed', function () use ($ctx, $tree, $chk) {
        $b = qac_book($ctx, qac_cfg('uganda', true), 'g8', 2525);
        qac_drive($ctx, $tree, $b, ['crm' => ['client' => qac_client(), 'quote_id' => 8801], 'steps' => [qac_b_app(['id' => 41]), qac_b_app(['id' => 42])]]);
        $c0 = $chk($b, time() + 600);
        file_put_contents($b['data'] . '/email_settings.json', json_encode(['use_ucrm_email' => false, 'smtp_host' => '', 'quote_email_via_plugin' => true]));
        $c1 = $chk($b, time() + 600);
        [$url, $log] = qac_counter($ctx);
        file_put_contents($b['dir'] . '/ucrm.json', json_encode(['ucrmLocalUrl' => $url . '/', 'pluginAppKey' => 'qac-test-key']));
        file_put_contents($b['data'] . '/email_settings.json', json_encode(['use_ucrm_email' => true, 'quote_email_via_plugin' => true]));
        $before = qac_counted($log);
        $c2 = $chk($b, time() + 600);
        $ctx->eq([$c0['status'], $c1['status'], in_array('plugin mail is not configured (Settings → System → Email)', $c1['notes'], true),
                  $c2['status'], in_array("plugin mail: uCRM's mailer, not asked", $c2['notes'], true), qac_counted($log) - $before],
            ['PASS', 'PASS', true, 'PASS', true, 0], 'G8 PASS before; PASS with "not configured" printed; PASS with "uCRM\'s mailer, not asked" and no uCRM request');
    });

    $ctx->run('G9 an administrator\'s copy, then set agent-cc-admins 0: PASS', function () use ($ctx, $tree, $chk) {
        $b = qac_book($ctx, qac_cfg('uganda', true), 'g9', 2525);
        qac_drive($ctx, $tree, $b, ['crm' => ['client' => qac_client(), 'quote_id' => 8901], 'steps' => [qac_b_app(['id' => 42])]]);
        $ctx->eq(qac_decisions($b['pdo'])[0]['code'] ?? null, 'added', 'G9 the administrator copied with the setting absent');
        qac_set_cfg($b, $b['cfg'] + ['quote_agent_cc_admins' => '0']);
        $ctx->eq($chk($b, time() + 600)['status'], 'PASS', 'G9 after set agent-cc-admins 0: PASS');
    });

    $ctx->run('G10 an added copy, then its account deactivated, re-addressed and made an administrator: PASS after each', function () use ($ctx, $tree, $chk) {
        $b = qac_book($ctx, qac_cfg('uganda', true), 'g10', 2525);
        qac_drive($ctx, $tree, $b, ['crm' => ['client' => qac_client(), 'quote_id' => 9101], 'steps' => [qac_b_app(['id' => 41])]]);
        $acc = qac_accounts();
        $st = [];
        $acc[0]['is_active'] = false; $b['store']->save('retailers.json', $acc); $st['deactivated'] = $chk($b, time() + 600)['status'];
        $acc[0]['email'] = 'not an address'; $b['store']->save('retailers.json', $acc); $st['re-addressed'] = $chk($b, time() + 600)['status'];
        $acc[0]['is_active'] = true; $acc[0]['is_admin'] = true; $b['store']->save('retailers.json', $acc);
        qac_set_cfg($b, $b['cfg'] + ['quote_agent_cc_admins' => '0']);
        $st['an administrator, admins 0'] = $chk($b, time() + 600)['status'];
        $ctx->eq($st, ['deactivated' => 'PASS', 're-addressed' => 'PASS', 'an administrator, admins 0' => 'PASS'], 'G10 PASS after each change');
    });

    $ctx->run('G12 skips recorded by the real senders pair their e-mails: PASS', function () use ($ctx, $tree, $chk) {
        [$port] = qac_relay($ctx);
        $b = qac_book($ctx, qac_cfg('uganda', true), 'g12', $port);
        qac_drive($ctx, $tree, $b, ['crm' => ['client' => qac_client(), 'quote_id' => 9401], 'steps' => [qac_b_app(['id' => 44]), qac_b_app(['id' => 43]), qac_c_wh(9451)]]);
        $codes = array_map(function ($d) { return $d['code'] ?? ''; }, qac_decisions($b['pdo']));
        $c = $chk($b, time() + 600);
        $ctx->eq([$codes, $c['status']], [['same_as_customer', 'agent_inactive', 'no_agent'], 'PASS'], 'G12 three skips, each with its decision: PASS', json_encode($c));
    });

    $ctx->run('G11 one copy\'s two stamps on either side of the window\'s lower edge: PASS', function () use ($ctx, $tree, $chk, $A) {
        [$port] = qac_relay($ctx, ['delay' => [$A['41'] => 3]]);
        $b = qac_book($ctx, qac_cfg('uganda', true), 'g11', $port);
        qac_drive($ctx, $tree, $b, ['crm' => ['client' => qac_client(), 'quote_id' => 9201], 'mailer' => 'real', 'steps' => [qac_b_app(['id' => 41])]]);
        $line = (string)@file_get_contents($b['data'] . '/quote_mail.log');
        $L = preg_match('/^\[([0-9: -]{19})\] (\S+) -> /', $line, $m) ? (int)strtotime($m[1] . ' UTC') : 0;
        $d = qac_decisions($b['pdo'])[0] ?? [];
        $D = strtotime((string)($d['decided_at'] ?? '') . ' UTC');
        qac_set_cfg($b, $b['cfg'] + ['quote_agent_cc_since' => gmdate('Y-m-d H:i:s', $L - 300)]);
        $c = $chk($b, $L + 300);
        $ctx->eq([$L > 0, $L - $D >= 3, $d['number'] ?? null, $c['status']], [true, true, $m[2] ?? 'x', 'PASS'],
            'G11 driven: the decision at least 3 s before its line, the line on the lower edge, the row\'s number the line\'s → PASS', json_encode([$c, $L - $D]));
        // Planted: path-1 decisions at since+299 and +290, lines at +300 and +305; a webhook decision at +299, its row at +300.
        $S = time() - 7200;
        $at = function (int $o) use ($S): string { return gmdate('Y-m-d H:i:s', $S + $o); };
        $b = qac_g_book($ctx, $S);
        qac_plant($b, ['path' => 'plugin', 'quote_id' => 9301, 'number' => 'QAC-NUM-93', 'decided_at' => $at(299)]);
        qac_line($b, $S + 300, 'QAC-NUM-93');
        qac_plant($b, ['path' => 'plugin', 'quote_id' => 9302, 'number' => 'QAC-NUM-94', 'decided_at' => $at(290)]);
        qac_line($b, $S + 305, 'QAC-NUM-94');
        qac_plant($b, ['path' => 'webhook', 'quote_id' => 9303, 'number' => 'QAC-NUM-95', 'source' => 'maker', 'decided_at' => $at(299)]);
        qac_row($b, 9303, $S + 300);
        $c = $chk($b, $S + 605);
        $ctx->eq([$c['status'], $c['counts']['no_log_line'] ?? null], ['PASS', 0], 'G11 planted on the edge, both paths: PASS, "no log line" 0', json_encode($c));
        $b = qac_g_book($ctx, $S);
        foreach ([9311, 9312] as $i => $q) {
            qac_plant($b, ['path' => 'plugin', 'quote_id' => $q, 'number' => 'MAN-QAC-77', 'decided_at' => $at(400 + $i)]);
            qac_line($b, $S + 402 + $i, 'MAN-QAC-77');
        }
        $ctx->eq($chk($b, $S + 1000)['status'], 'PASS', 'G11 two quotes of one fallback reference, each with its decision and its line: PASS');
    });
}];
