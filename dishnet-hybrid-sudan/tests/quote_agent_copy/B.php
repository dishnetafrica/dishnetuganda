<?php
declare(strict_types=1);
/**
 * quote_agent_copy B — the app path (root docs/73 §H.5.4.1, §H.5.5, §H.5.8.1): createCrmQuote → emailQuotePdf with
 * quote_email_via_plugin on, through the TestQuoteSvc seam over newMailService() (tests/test_mail_quote.php's recording
 * mailer) and the fake uCRM, each run in a process of its own (drive.php). B8 (impersonation) is built only if the owner
 * asks (PQ3), so it is not here. TEST ONLY; every account and address fictitious.
 */

/** One app quote step: client 901, the given actor. */
function qac_b_app(array $retailer, array $more = []): array
{
    return $more + ['do' => 'app', 'client_id' => 901, 'retailer' => $retailer, 'ref' => 'MAN-QAC-' . ($retailer['id'] ?? 0), 'where' => 'manual'];
}

/** The recorded mailer call with what P7 may change taken out: its Cc header. */
function qac_b_noCc(array $call): array
{
    unset($call['extraHeaders']['Cc']);
    return $call;
}

$QAC_SECTIONS['B'] = ['title' => 'The app path (§H.5.4.1, §H.5.5, §H.5.8.1)', 'run' => function (QfCtx $ctx) {
    qac_load($ctx);
    $tree = $ctx->tree();
    $base = qac_base($ctx);
    $A = QAC_AGENT;
    $crmSpec = ['client' => qac_client(), 'quote_id' => 7001];
    $p7cfg = function (array $more = []) { return qac_cfg('uganda', true, $more); };

    $ctx->run('B0 positive control: with P7 on, an active actor is copied in the one e-mail', function () use ($ctx, $tree, $crmSpec, $p7cfg, $A) {
        $b = qac_book($ctx, $p7cfg(), 'b0', 2525);
        $r = qac_drive($ctx, $tree, $b, ['crm' => $crmSpec, 'steps' => [qac_b_app(['id' => 41, 'name' => 'Test Agent One'])]]);
        $ctx->eq([count($r['mail']), $r['mail'][0]['extraHeaders']['Cc'] ?? null, $r['steps'][0]['ret']['ok'] ?? null], [1, $A['41'], true],
            'B0 one mailer call, Cc the actor, the quote made', json_encode($r['steps']) . ($r['broken'] ?? ''));
        $d = qac_decisions($b['pdo']);
        $ctx->eq([count($d), $d[0]['code'] ?? null, $d[0]['path'] ?? null, $d[0]['agent_id'] ?? null, $d[0]['source'] ?? null], [1, 'added', 'plugin', 41, 'actor'],
            'B0 one decision: added, path plugin, the actor');
    });

    $ctx->run('B1 P7 off: the mailer\'s call equals 221c9dc\'s, on eight fixtures; no table', function () use ($ctx, $tree, $base) {
        if ($base === null) { $ctx->t(false, 'B1 Git supplies 221c9dc — without it nothing can be compared: a failure, never a skip'); return; }
        $fx = [
            'the actor, cc off'          => [qac_cfg('uganda'), qac_client(), ['id' => 41, 'name' => 'Test Agent One'], []],
            'the actor, cc on'           => [qac_cfg('uganda', false, ['email_cc_contacts' => '1']), qac_client(), ['id' => 41, 'name' => 'Test Agent One'], []],
            'no actor id'                => [qac_cfg('uganda'), qac_client(), ['name' => 'Test Agent One'], []],
            'three contacts, cc on'      => [qac_cfg('uganda', false, ['email_cc_contacts' => '1']), qac_client(['contacts' => [['email' => 'A@Cust.example.test'],
                                             ['email' => 'billing@cust.example.test', 'isBilling' => true], ['email' => 'c@cust.example.test']]]), ['id' => 42, 'name' => 'Test Admin Two'], []],
            'an administrator'           => [qac_cfg('uganda'), qac_client(), ['id' => 42, 'name' => 'Test Admin Two'], []],
            'no customer e-mail'         => [qac_cfg('uganda'), qac_client(['contacts' => []]), ['id' => 41, 'name' => 'Test Agent One'], []],
            'the plugin send fails'      => [qac_cfg('uganda'), qac_client(), ['id' => 41, 'name' => 'Test Agent One'], ['rec_ok' => false]],
            'no number in uCRM\'s answer' => [qac_cfg('uganda'), qac_client(), ['id' => 41, 'name' => 'Test Agent One'], ['crm' => ['number' => null]]],
        ];
        $same = []; $tables = [];
        foreach ($fx as $k => [$cfg, $client, $ret, $extra]) {
            $crm = ($extra['crm'] ?? []) + ['client' => $client, 'quote_id' => 7101];
            unset($extra['crm']);
            $bt = qac_book($ctx, $cfg, 'b1', 2525);
            $bb = qac_book($ctx, $cfg, 'b1', 2525);
            $t = qac_drive($ctx, $tree, $bt, $extra + ['crm' => $crm, 'steps' => [qac_b_app($ret)]]);
            $o = qac_drive($ctx, $base, $bb, $extra + ['crm' => $crm, 'steps' => [qac_b_app($ret)]]);
            $same[$k] = [$t['mail'] === $o['mail'], $t['steps'] === $o['steps'], $t['crm'] === $o['crm'], $t['rc'] === 0 && $o['rc'] === 0];
            $tables[$k] = qac_tables($bt['pdo']);
        }
        $ctx->eq($same, array_fill_keys(array_keys($fx), [true, true, true, true]), 'B1 the mailer\'s call, the answer and the uCRM requests equal 221c9dc\'s, on each fixture');
        $ctx->eq($tables, array_fill_keys(array_keys($fx), []), 'B1 no quote_agent_* table exists');
    });

    $ctx->run('B2 on, with the actor: who is copied, and why not', function () use ($ctx, $tree, $crmSpec, $p7cfg, $A) {
        $steps = [
            'actor'          => [['id' => 41, 'name' => 'Test Agent One'], 'added', $A['41']],
            'reply-to'       => [['id' => 46, 'name' => 'Test Reply Six'], 'added', QAC_REPLY],
            'sender'         => [['id' => 47, 'name' => 'Test Sender Seven'], 'added', QAC_SENDER],
            'administrator'  => [['id' => 42, 'name' => 'Test Admin Two'], 'added', $A['42']],
            'the To'         => [['id' => 44, 'name' => 'Test Same Four'], 'same_as_customer', null],
            'inactive'       => [['id' => 43, 'name' => 'Test Gone Three'], 'agent_inactive', null],
            'session record' => [['id' => 48, 'name' => 'Test Renamed Eight', 'email' => 'old.eight@agents.example.test'], 'added', $A['48']],
            'no id'          => [['name' => 'Test Agent One', 'email' => $A['41']], 'no_agent', null],
            'no address'     => [['id' => 51, 'name' => 'Test No Mail'], 'agent_no_email', null],
        ];
        $b = qac_book($ctx, $p7cfg(), 'b2', 2525);
        $r = qac_drive($ctx, $tree, $b, ['crm' => $crmSpec, 'steps' => array_map(function ($s) { return qac_b_app($s[0]); }, array_values($steps))]);
        $d = qac_decisions($b['pdo']);
        $got = []; $want = [];
        foreach (array_keys($steps) as $i => $k) {
            $got[$k] = [$d[$i]['code'] ?? null, $r['mail'][$i]['extraHeaders']['Cc'] ?? null];
            $want[$k] = [$steps[$k][1], $steps[$k][2]];
        }
        $ctx->eq($got, $want, 'B2 each actor: its code and the Cc (the To compared case-insensitively; Reply-To and sender added; the row\'s address, never the session\'s)');
        // email_cc_contacts on: the client's contacts, then the agent; the agent also a client contact → already_copied.
        $b = qac_book($ctx, $p7cfg(['email_cc_contacts' => '1']), 'b2', 2525);
        $r = qac_drive($ctx, $tree, $b, ['crm' => $crmSpec, 'steps' => [qac_b_app(['id' => 41]), qac_b_app(['id' => 45])]]);
        $d = qac_decisions($b['pdo']);
        $ctx->eq([$r['mail'][0]['extraHeaders']['Cc'] ?? null, $d[0]['code'] ?? null], ['other@cust.example.test, ' . $A['41'], 'added'],
            'B2 cc on: the client\'s contacts, then the agent');
        $ctx->eq([$r['mail'][1]['extraHeaders']['Cc'] ?? null, $d[1]['code'] ?? null], ['other@cust.example.test', 'already_copied'],
            'B2 the agent also a client contact: already_copied, the header as P7 off');
        // The To in upper case: the comparison is case-insensitive (PW5).
        $b = qac_book($ctx, $p7cfg(), 'b2', 2525);
        $up = qac_client(['contacts' => [['email' => 'BILLING@CUST.EXAMPLE.TEST', 'isBilling' => true]]]);
        $r = qac_drive($ctx, $tree, $b, ['crm' => ['client' => $up, 'quote_id' => 7001], 'steps' => [qac_b_app(['id' => 44])]]);
        $d = qac_decisions($b['pdo']);
        $ctx->eq([$d[0]['code'] ?? null, $r['mail'][0]['extraHeaders']['Cc'] ?? null], ['same_as_customer', null], 'B2 the To in upper case, the agent in lower: same_as_customer');
        // set agent-cc-admins 0: an administrator is not copied.
        $b = qac_book($ctx, $p7cfg(['quote_agent_cc_admins' => '0']), 'b2', 2525);
        $r = qac_drive($ctx, $tree, $b, ['crm' => $crmSpec, 'steps' => [qac_b_app(['id' => 42]), qac_b_app(['id' => 41])]]);
        $d = qac_decisions($b['pdo']);
        $ctx->eq([[$d[0]['code'] ?? null, $r['mail'][0]['extraHeaders']['Cc'] ?? null], [$d[1]['code'] ?? null, $r['mail'][1]['extraHeaders']['Cc'] ?? null]],
            [['admin_account', null], ['added', $A['41']]], 'B2 agent-cc-admins 0: the administrator → admin_account; another actor still added');
    });

    $ctx->run('B3 one mailer call per quote; a failed plugin send hands over to uCRM\'s /send once; no address in a uCRM request', function () use ($ctx, $tree, $crmSpec, $p7cfg, $A) {
        $b = qac_book($ctx, $p7cfg(['email_cc_contacts' => '1']), 'b3', 2525);
        $r = qac_drive($ctx, $tree, $b, ['crm' => $crmSpec, 'steps' => [qac_b_app(['id' => 41]), qac_b_app(['id' => 42]), qac_b_app(['id' => 45])]]);
        $ctx->eq([count($r['mail']), array_map(function ($c) { return $c['toEmail']; }, $r['mail'])], [3, array_fill(0, 3, 'billing@cust.example.test')],
            'B3 exactly one mailer call per createCrmQuote, to the customer');
        $b = qac_book($ctx, $p7cfg(), 'b3', 2525);
        $r = qac_drive($ctx, $tree, $b, ['crm' => $crmSpec, 'rec_ok' => false, 'steps' => [qac_b_app(['id' => 41])]]);
        $sends = array_values(array_filter($r['crm'], function ($q) { return $q[0] === 'PATCH' && substr($q[1], -5) === '/send'; }));
        $d = qac_decisions($b['pdo']);
        $ctx->eq([count($r['mail']), count($sends), $d[0]['send_ok'] ?? null, $d[0]['code'] ?? null, $r['steps'][0]['ret']['email_error'] ?? null],
            [1, 1, 0, 'added', 'SMTP said no (test)'], 'B3 the plugin send failing: one mailer call, uCRM\'s /send once, decision send_ok 0, as 5.18.103 otherwise');
        $blob = json_encode($r['crm']);
        $ctx->eq(array_values(array_filter($A, function ($a) use ($blob) { return stripos($blob, $a) !== false; })), [], 'B3 no uCRM request path or body holds an agent\'s address');
    });

    $ctx->run('B4 the maker hand-off', function () use ($ctx, $tree, $crmSpec, $p7cfg) {
        $b = qac_book($ctx, $p7cfg(), 'b4', 2525);
        $r = qac_drive($ctx, $tree, $b, ['crm' => $crmSpec + ['post_hook' => 'read_makers'], 'steps' => [qac_b_app(['id' => 41])]]);
        $saw = $r['post_saw'][0] ?? [];
        $ctx->eq([count($r['post_saw']), $saw['maker_id'] ?? null, $saw['client_id'] ?? null, $saw['state'] ?? null, array_key_exists('quote_id', $saw) ? $saw['quote_id'] : 'absent'], [1, 41, 901, 'making', null],
            'B4 the POST handler sees the row: making, the actor\'s id, the client, no quote id');
        $m = qac_decisions($b['pdo'], 'quote_agent_makers');
        $ctx->eq([count($m), $m[0]['state'] ?? null, $m[0]['quote_id'] ?? null, is_int($m[0]['settled_at'] ?? null)], [1, 'made', 7001, true], 'B4 then made, with the quote id');
        foreach (['no_id' => 'uCRM\'s answer has no id', 'throw' => 'the POST throws'] as $how => $why) {
            $b = qac_book($ctx, $p7cfg(), 'b4', 2525);
            $r = qac_drive($ctx, $tree, $b, ['crm' => $crmSpec + ['post' => $how], 'steps' => [qac_b_app(['id' => 41])]]);
            $m = qac_decisions($b['pdo'], 'quote_agent_makers');
            $ctx->eq([count($m), $m[0]['state'] ?? null, $m[0]['quote_id'] ?? null, $r['steps'][0]['ret']['ok'] ?? null, count($r['mail'])], [1, 'failed', null, false, 0],
                "B4 {$why}: failed, and the answer as 5.18.103's (not made, no e-mail)");
        }
        // A throw after uCRM made the quote (the number's read fails): the catch's "failed" never undoes "made".
        $b = qac_book($ctx, $p7cfg(), 'b4', 2525);
        $r = qac_drive($ctx, $tree, $b, ['crm' => $crmSpec + ['number' => null, 'quote_get' => 'throw'], 'steps' => [qac_b_app(['id' => 41])]]);
        $m = qac_decisions($b['pdo'], 'quote_agent_makers');
        $ctx->eq([count($m), $m[0]['state'] ?? null, $m[0]['quote_id'] ?? null], [1, 'made', 7001], 'B4 a row already made is left made by the catch');
        $b = qac_book($ctx, qac_cfg('uganda'), 'b4', 2525);
        qac_drive($ctx, $tree, $b, ['crm' => $crmSpec, 'steps' => [qac_b_app(['id' => 41])]]);   // no read hook: the hook's own read would make the table
        $ctx->eq(qac_tables($b['pdo']), [], 'B4 nothing written while P7 is off');
        // markResult itself: only a row still making changes.
        $b = qac_book($ctx, $p7cfg(), 'b4', 2525);
        $id = (int)$b['store']->appendWithId('quote_agent_makers.json', ['client_id' => 901, 'maker_id' => 41, 'quote_id' => 7001, 'state' => 'made', 'at' => time(), 'settled_at' => time()])['id'];
        QuoteAgentCopy::markResult($b['store'], $id, 0);
        $ctx->eq($b['store']->findOne('quote_agent_makers.json', 'id', $id)['state'] ?? null, 'made', 'B4 markResult leaves a made row made');
    });

    $ctx->run('B5 a store that throws: the e-mail goes as without P7', function () use ($ctx, $tree, $base, $crmSpec, $p7cfg, $A) {
        $b = qac_book($ctx, $p7cfg(), 'b5', 2525);
        $r = qac_drive($ctx, $tree, $b, ['crm' => $crmSpec, 'store_faults' => ['read' => ['retailers.json', 'quote_agent_copies.json', 'quote_agent_makers.json']],
            'steps' => [qac_b_app(['id' => 41])]]);
        $d = qac_decisions($b['pdo']);
        $ctx->eq([count($r['mail']), isset($r['mail'][0]['extraHeaders']['Cc']), $d[0]['code'] ?? null, $r['steps'][0]['ret']['ok'] ?? null, $r['steps'][0]['ret']['emailed_by_plugin'] ?? null],
            [1, false, 'error', true, true], 'B5 reads throw: no copy, decision error, the e-mail sent by the plugin');
        if ($base !== null) {
            $bb = qac_book($ctx, $p7cfg(), 'b5', 2525);
            $o = qac_drive($ctx, $base, $bb, ['crm' => $crmSpec, 'steps' => [qac_b_app(['id' => 41])]]);
            $ctx->eq($r['mail'], $o['mail'], 'B5 … the mailer\'s call exactly as 221c9dc\'s');
        }
        $b = qac_book($ctx, $p7cfg(), 'b5', 2525);
        $r = qac_drive($ctx, $tree, $b, ['crm' => $crmSpec, 'store_faults' => ['write' => ['quote_agent_copies.json', 'quote_agent_makers.json']],
            'steps' => [qac_b_app(['id' => 41])]]);
        $ctx->eq([count($r['mail']), $r['mail'][0]['extraHeaders']['Cc'] ?? null, count(qac_decisions($b['pdo'])), $r['steps'][0]['ret']['ok'] ?? null],
            [1, $A['41'], 0, true], 'B5 writes throw: the copy still sent, the e-mail unchanged, no decision row');
        $ctx->t($ctx->logLines('[quote_agent_copy] decision not written (RuntimeException)') >= 1, 'B5 … the lost write is one fixed PluginLog line');
    });

    $ctx->run('B6 quote_mail.log\'s line is 5.18.103\'s in form', function () use ($ctx, $tree, $base, $crmSpec, $p7cfg) {
        if ($base === null) { $ctx->t(false, 'B6 Git supplies 221c9dc'); return; }
        $lines = [];
        foreach (['tree' => $tree, 'base' => $base] as $k => $root) {
            $b = qac_book($ctx, $p7cfg(), 'b6', 2525);
            qac_drive($ctx, $root, $b, ['crm' => $crmSpec, 'steps' => [qac_b_app(['id' => 41])]]);
            $lines[$k] = preg_replace('/^\[[0-9: -]{19}\]/m', '[stamp]', (string)@file_get_contents($b['data'] . '/quote_mail.log'));
        }
        $ctx->eq($lines['tree'], $lines['base'], 'B6 the line, its stamp masked, equals 221c9dc\'s');
        $ctx->t($lines['tree'] === "[stamp] QAC-7001 -> billing@cust.example.test sent\n", 'B6 … and reads "[stamp] <number> -> <To> sent"', json_encode($lines['tree']));
    });

    $ctx->run('B7 fault injection: each stub in turn', function () use ($ctx, $tree, $base, $crmSpec, $p7cfg, $A) {
        if ($base === null) { $ctx->t(false, 'B7 Git supplies 221c9dc'); return; }
        $bad = [];
        // uCRM making the quote (every call site), answering without an id, and the POST throwing (markResult's other two).
        foreach (['made' => [], 'no id' => ['post' => 'no_id'], 'POST throws' => ['post' => 'throw']] as $how => $crmMore) {
            $bb = qac_book($ctx, $p7cfg(), 'b7', 2525);
            $o = qac_drive($ctx, $base, $bb, ['crm' => $crmMore + $crmSpec, 'steps' => [qac_b_app(['id' => 41])]]);
            $oQ = qac_qemail($bb['pdo'], 7001);
            foreach (array_merge(['*'], qac_public_methods($ctx)) as $m) {
                $stub = qac_stub($ctx, $m);
                $b = qac_book($ctx, $p7cfg(), 'b7', 2525);
                $r = qac_drive($ctx, $stub, $b, ['crm' => $crmMore + $crmSpec, 'steps' => [qac_b_app(['id' => 41])]]);
                $q = qac_qemail($b['pdo'], 7001);
                $cc = $r['mail'][0]['extraHeaders']['Cc'] ?? null;
                $why = [];
                if ($r['steps'] !== $o['steps']) $why[] = 'answer ' . json_encode($r['steps']);
                if ($r['crm'] !== $o['crm']) $why[] = 'uCRM requests';
                if ($q !== $oQ) $why[] = 'QEMAIL';
                if (count($r['mail']) !== count($o['mail']) || ($o['mail'] !== [] && qac_b_noCc($r['mail'][0]) !== qac_b_noCc($o['mail'][0]))) $why[] = 'the e-mail';
                if (!in_array($cc, [null, $A['41']], true)) $why[] = 'Cc ' . json_encode($cc);
                if ($m === '*' && ($cc !== null || qac_decisions($b['pdo']) !== [])) $why[] = 'every method throwing left a copy or a decision';
                if (in_array($m, ['forActor', 'state'], true) && qac_decisions($b['pdo']) !== []) $why[] = 'a throw before the decision left one';
                if ($why !== []) $bad["{$how}: {$m}"] = implode('; ', $why);
            }
        }
        $ctx->eq($bad, [], 'B7 with every method throwing, and each public method throwing alone: the answer, the uCRM requests, QEMAIL and the e-mail are 221c9dc\'s (at most the agent added to Cc); a throw before the decision leaves none');
    });

    $ctx->run('B9 P7 builds no MailService: with use_ucrm_email on, uCRM is asked as often with P7 on as off', function () use ($ctx, $tree, $crmSpec) {
        [$url, $log] = qac_counter($ctx);
        [$port] = qac_relay($ctx);
        $n = [];
        foreach (['off' => false, 'on' => true] as $k => $on) {
            $b = qac_book($ctx, qac_cfg('uganda', $on), 'b9');
            qac_mail($b['data'], $port, ['use_ucrm_email' => true]);
            file_put_contents($b['dir'] . '/ucrm.json', json_encode(['ucrmLocalUrl' => $url . '/', 'ucrmPublicUrl' => $url . '/crm/', 'pluginAppKey' => 'qac-test-key']));
            $before = qac_counted($log);
            $r = qac_drive($ctx, $tree, $b, ['crm' => $crmSpec, 'mailer' => 'real', 'steps' => [qac_b_app(['id' => 41])]]);
            $n[$k] = [qac_counted($log) - $before, $r['steps'][0]['ret']['emailed_by_plugin'] ?? null];
        }
        $ctx->t($url !== '' && $n['off'][0] > 0, 'B9 positive control: the counter sees MailService ask uCRM\'s /settings', json_encode($n));
        $ctx->eq($n['on'], $n['off'], 'B9 the same number of uCRM requests with P7 on as off, and the e-mail sent either way');
    });
}];
