<?php
declare(strict_types=1);
/**
 * quote_agent_copy A — the rules, pure (root docs/73 §H.5.4, §H.5.7): usableAddress (A1), toOf against both paths'
 * own rules (A2), decide's order (A3), mergeCc (A4), rcptOutcome read from MailService's own source (A5), webhookAgent
 * and kycInFlight (A6), one meaning of the KYC agent (A7, IP-1) and afterSend (A8). TEST ONLY; every address fictitious.
 */
$QAC_SECTIONS['A'] = ['title' => 'The rules, pure (§H.5.4, §H.5.7)', 'run' => function (QfCtx $ctx) {
    qac_load($ctx);
    $tree = $ctx->tree();

    $ctx->run('A0 positive control: a plain address is usable and an agent is added', function () use ($ctx) {
        $ctx->eq(QuoteAgentCopy::usableAddress('agent.one@agents.example.test'), 'agent.one@agents.example.test', 'A0 usableAddress accepts a plain address');
        $d = QuoteAgentCopy::decide(qac_accounts()[0], 41, 'actor', '', 'billing@cust.example.test', [], true);
        $ctx->eq([$d['code'], $d['address']], ['added', 'agent.one@agents.example.test'], 'A0 decide adds an active agent with a usable address');
    });

    $ctx->run('A1 usableAddress', function () use ($ctx) {
        $ctx->eq(QuoteAgentCopy::usableAddress('  Agent.One@Agents.EXAMPLE.test '), 'agent.one@agents.example.test', 'A1 upper case lower-cased, trimmed');
        $bad = [
            'empty' => '', 'no @' => 'agent.agents.example.test', 'two @' => 'a@b@agents.example.test',
            'CR' => "agent@agents.exa\rmple.test", 'LF' => "agent@agents.example.test\nx", 'CR LF + a header' => "agent@agents.example.test\r\nBcc: x@evil.example.test",
            'a comma list' => 'a@agents.example.test,b@agents.example.test', 'a semicolon' => 'a@agents.example.test;b@agents.example.test',
            'angle brackets' => '<a@agents.example.test>', 'a display name' => 'Agent <a@agents.example.test>', 'a quote mark' => 'a"b@agents.example.test',
            'a space' => 'a b@agents.example.test', 'a tab' => "a\tb@agents.example.test", '255 characters' => str_repeat('a', 238) . '@agents.example.test',
            'quoted "a,b"' => '"a,b"@agents.example.test', 'quoted, escaped CR LF + a header' => "\"a\\\r\nBcc: x\"@agents.example.test",
            'a parenthesis' => 'a(b)@agents.example.test', 'a square bracket' => 'a@[127.0.0.1]', 'a backslash' => 'a\\b@agents.example.test',
        ];
        $got = [];
        foreach ($bad as $k => $v) $got[$k] = QuoteAgentCopy::usableAddress($v);
        $ctx->eq($got, array_fill_keys(array_keys($bad), ''), 'A1 each refused: ' . implode('; ', array_keys($bad)));
        $ctx->eq(QuoteAgentCopy::usableAddress(str_repeat('a', 64) . '@agents.example.test'), str_repeat('a', 64) . '@agents.example.test',
            'A1 control: a long but legal address is kept');
    });

    $ctx->run('A2 toOf equals emailQuotePdf\'s loop and the dispatcher\'s contactFor, on eight clients', function () use ($ctx, $tree) {
        $fx = [
            'billing first'        => ['contacts' => [['email' => 'a@cust.example.test'], ['email' => 'b@cust.example.test', 'isBilling' => true]]],
            'billing without mail' => ['contacts' => [['email' => 'a@cust.example.test'], ['email' => '', 'isBilling' => true]]],
            'first with mail'      => ['contacts' => [['email' => ''], ['email' => 'c@cust.example.test']]],
            'no contacts'          => ['contacts' => []],
            'contacts not a list'  => ['contacts' => 'x'],
            'none with mail'       => ['contacts' => [['phone' => '+256700000000'], ['email' => '']]],
            'two billing'          => ['contacts' => [['email' => 'd@cust.example.test', 'isBilling' => true], ['email' => 'e@cust.example.test', 'isBilling' => true]]],
            'billing upper case'   => ['contacts' => [['email' => 'F@Cust.example.test', 'isBilling' => true]]],
        ];
        $m = new ReflectionMethod('CustomerEmailDispatcher', 'contactFor');
        $m->setAccessible(true);
        $disp = []; $mine = []; $app = [];
        // emailQuotePdf's own loop, taken from the judged tree's file by its text and run on each client.
        $src = (string)file_get_contents($tree . '/lib/QuotationService.php');
        $a = strpos($src, '            $email = \'\';' . "\n" . '            $contacts = is_array($client[\'contacts\'] ?? null)');
        $z = $a === false ? false : strpos($src, "            if (\$email === '') return [false, 'customer has no email address in uCRM'];", $a);
        $loop = ($a === false || $z === false) ? '' : substr($src, $a, $z - $a);
        $ctx->t($loop !== '', 'A2 emailQuotePdf\'s To loop is found in lib/QuotationService.php');
        $run = $loop === '' ? null : eval('return function (array $client): string { ' . $loop . ' return $email; };');
        foreach ($fx as $k => $c) {
            $mine[$k] = QuoteAgentCopy::toOf($c);
            $disp[$k] = $m->invoke(null, $c)[0];
            $app[$k] = $run ? $run($c) : null;
        }
        $ctx->eq($mine, $disp, 'A2 toOf equals the dispatcher\'s contactFor on every fixture');
        $ctx->eq($mine, $app, 'A2 toOf equals emailQuotePdf\'s loop on every fixture');
    });

    $ctx->run('A3 decide: every code, in order', function () use ($ctx) {
        $long = [];
        for ($i = 0; $i < 60; $i++) $long[] = 'contact' . $i . '@cust.example.test';
        $act = ['id' => 41, 'name' => 'Test Agent One', 'email' => 'Agent.One@agents.example.test', 'is_active' => true];
        $cases = [
            // [row, to, already, admins, want] — each also matching every later rule it can
            'agent_unknown_id'    => [null, '', ['agent.one@agents.example.test'], false],
            'agent_inactive'      => [['is_active' => false, 'is_admin' => true, 'email' => ''], '', [], false],
            'admin_account'       => [['is_active' => true, 'is_admin' => true, 'email' => ''], '', [], false],
            'agent_no_email'      => [['is_active' => true, 'email' => '  '], '', [], true],
            'agent_email_invalid' => [['is_active' => true, 'email' => 'not an address'], '', [], true],
            'customer_unread'     => [$act, '', ['agent.one@agents.example.test'], true],
            'same_as_customer'    => [$act, 'AGENT.ONE@agents.example.test', array_merge(['agent.one@agents.example.test'], $long), true],
            'already_copied'      => [$act, 'billing@cust.example.test', array_merge(['AGENT.ONE@AGENTS.example.test'], $long), true],
            'header_too_long'     => [$act, 'billing@cust.example.test', $long, true],
            'added'               => [$act, 'billing@cust.example.test', ['other@cust.example.test'], true],
        ];
        $got = []; $want = [];
        foreach ($cases as $code => [$row, $to, $al, $adm]) {
            $got[$code] = QuoteAgentCopy::decide($row, 41, 'actor', '', $to, $al, $adm)['code'];
            $want[$code] = $code;
        }
        $ctx->eq($got, $want, 'A3 each code, on a row that also matches the later rules: the order is proved');
        $adm = ['id' => 42, 'email' => 'admin.two@agents.example.test', 'is_active' => true, 'is_admin' => true];
        $ctx->eq([QuoteAgentCopy::decide($adm, 42, 'actor', '', 'billing@cust.example.test', [], true)['code'],
                  QuoteAgentCopy::decide($adm, 42, 'actor', '', 'billing@cust.example.test', [], false)['code']],
            ['added', 'admin_account'], 'A3 an administrator: added while copied, admin_account when the owner narrowed it');
        foreach (['company Reply-To' => QAC_REPLY, 'sender' => QAC_SENDER] as $k => $addr) {
            $r = QuoteAgentCopy::decide(['email' => $addr, 'is_active' => true], 46, 'actor', '', 'billing@cust.example.test', [], true);
            $ctx->eq([$r['code'], $r['address']], ['added', $addr], "A3 an agent whose address is the {$k} is ADDED (P-D17, PQ4)");
        }
        $ctx->eq(QuoteAgentCopy::decide($act, 41, 'kyc', 'kyc_submitter', 'billing@cust.example.test', [], true),
            ['address' => 'agent.one@agents.example.test', 'code' => 'added', 'source' => 'kyc', 'rule' => 'kyc_submitter', 'agent_id' => 41],
            'A3 an added decision carries its address, source, rule and agent');
    });

    $ctx->run('A3b state(): the administrators\' setting — absent and 1 copy them, 0 and 2 narrow it', function () use ($ctx) {
        $got = [];
        foreach (['absent' => null, '1' => '1', '0' => '0', '2' => '2'] as $k => $v) {
            $b = qac_book($ctx, qac_cfg('uganda', true, ['quote_agent_cc_admins' => $v]), 'a3b');
            $got[$k] = QuoteAgentCopy::state($ctx->tree(), $b['data'])['admins'];
        }
        $ctx->eq($got, ['absent' => true, '1' => true, '0' => false, '2' => false], 'A3b admins: absent true, 1 true, 0 false, 2 false');
        $b = qac_book($ctx, qac_cfg('uganda', false), 'a3b');
        $ctx->eq(QuoteAgentCopy::state($ctx->tree(), $b['data'])['on'], false, 'A3b the key absent: off');
        $b = qac_book($ctx, qac_cfg('south-sudan', true), 'a3b');
        $ctx->eq(QuoteAgentCopy::state($ctx->tree(), $b['data'])['on'], false, 'A3b the key on, South Sudan: off (the second lock)');
        $b = qac_book($ctx, qac_cfg('uganda', true, ['quote_agent_cc_enabled' => 'yes']), 'a3b');
        $ctx->eq(QuoteAgentCopy::state($ctx->tree(), $b['data'])['on'], false, 'A3b only "1" is on');
        $b = qac_book($ctx, qac_cfg('uganda', true, ['kyc_auto_quote_enabled' => false]), 'a3b');
        $ctx->eq(QuoteAgentCopy::state($ctx->tree(), $b['data']), ['on' => true, 'admins' => true, 'auto_quote' => false], 'A3b automatic quoting off is read');
    });

    $ctx->run('A4 mergeCc', function () use ($ctx) {
        $own = ['Other@Cust.example.test', QAC_REPLY];
        [$l, $c] = QuoteAgentCopy::mergeCc($own, 'Agent.One@agents.example.test', 'billing@cust.example.test');
        $ctx->eq([$l, $c], [['Other@Cust.example.test', QAC_REPLY, 'agent.one@agents.example.test'], ''],
            'A4 the client\'s list byte-identical (a contact equal to the Reply-To included), the agent appended once, lower-cased');
        $ctx->eq(QuoteAgentCopy::mergeCc($own, 'OTHER@cust.example.test', 'billing@cust.example.test'), [$own, 'already_copied'], 'A4 a client contact → already_copied, list unchanged');
        $ctx->eq(QuoteAgentCopy::mergeCc($own, 'Billing@CUST.example.test', '"Test Customer" <billing@cust.example.test>'), [$own, 'same_as_customer'], 'A4 the To → same_as_customer');
        // The To itself in upper or mixed case: the floor's own comparison is case-insensitive (root docs/73 §H.5.4.5; PW5b).
        $ctx->eq([QuoteAgentCopy::mergeCc([], 'billing@cust.example.test', 'BILLING@Cust.Example.Test'),
                  QuoteAgentCopy::mergeCc($own, 'billing@cust.example.test', '"Test Customer" <BILLING@CUST.EXAMPLE.TEST>')],
            [[[], 'same_as_customer'], [$own, 'same_as_customer']], 'A4 the To in upper or mixed case (bare and named), the agent in lower → same_as_customer, the list unchanged');
        $ctx->eq(QuoteAgentCopy::mergeCc($own, "a@b.example.test\r\nBcc: x@evil.example.test", 'billing@cust.example.test'), [$own, 'agent_email_invalid'], 'A4 an unusable agent → agent_email_invalid');
        $ctx->eq(QuoteAgentCopy::mergeCc([], QAC_SENDER, 'billing@cust.example.test'), [[QAC_SENDER], ''], 'A4 the sender\'s address is added (P-D17)');
        // 998 and 999 octets: "Cc: " + list + ", " + agent.
        $agent = 'agent.one@agents.example.test';
        $mk = function (int $total) use ($agent): array {
            $fill = $total - strlen('Cc: ') - strlen(', ' . $agent);
            $local = $fill - strlen('@cust.example.test');
            return [str_repeat('c', $local) . '@cust.example.test'];
        };
        [$l998, $c998] = QuoteAgentCopy::mergeCc($mk(998), $agent, 'billing@cust.example.test');
        [$l999, $c999] = QuoteAgentCopy::mergeCc($mk(999), $agent, 'billing@cust.example.test');
        $ctx->eq([$c998, strlen('Cc: ' . implode(', ', $l998))], ['', 998], 'A4 a line of exactly 998 octets with the agent: appended');
        $ctx->eq([$c999, $l999], ['header_too_long', $mk(999)], 'A4 999 octets: header_too_long, the list unchanged');
    });

    $ctx->run('A5 rcptOutcome on MailService\'s own two message forms', function () use ($ctx, $tree) {
        $src = (string)file_get_contents($tree . '/lib/MailService.php');
        $ok = preg_match('/\$ccOk \? "(cc \{\$ccAddr\})" : "(cc \{\$ccAddr\} refused: \{\$ccResp\})"/', $src, $m) === 1;
        $ctx->t($ok, 'A5 lib/MailService.php writes "cc {$ccAddr}" and "cc {$ccAddr} refused: {$ccResp}"');
        if (!$ok) return;
        $a = 'agent.one@agents.example.test';
        $acc = str_replace('{$ccAddr}', $a, $m[1]);
        $ref = str_replace(['{$ccAddr}', '{$ccResp}'], [$a, '550 5.1.1 refused (test)'], $m[2]);
        $ctx->eq([QuoteAgentCopy::rcptOutcome([['step' => 'rcpt_cc', 'ok' => true, 'msg' => $acc]], $a),
                  QuoteAgentCopy::rcptOutcome([['step' => 'rcpt_cc', 'ok' => false, 'msg' => $ref]], $a),
                  QuoteAgentCopy::rcptOutcome([['step' => 'rcpt_cc', 'ok' => true, 'msg' => 'cc other@cust.example.test']], $a),
                  QuoteAgentCopy::rcptOutcome([], $a), QuoteAgentCopy::rcptOutcome([['step' => 'rcpt_cc', 'msg' => $acc]], '')],
            ['accepted', 'refused', 'none', 'none', 'none'], 'A5 accepted, refused, another address, no log, no agent');
    });

    $ctx->run('A6 webhookAgent and kycInFlight, step by step', function () use ($ctx, $tree) {
        $now = 1793000000;
        $R = qac_accounts();
        $ago = function (int $s) use ($now): string { return date('Y-m-d H:i:s', $now - $s); };
        $form = function (array $more = []) use ($ago): array {
            return $more + ['id' => 12, 'crm_client_id' => 901, 'quote_ref' => 'PENDING', 'quote_id' => null, 'submitted_at' => $ago(60), 'retailer_id' => 49];
        };
        $w = function (?array $first, array $mq, array $aq, array $mc, array $ac, bool $auto = true, ?string $notes = null) use ($R, $now) {
            return QuoteAgentCopy::webhookAgent($first, $mq, $aq, $mc, $ac, $R, $now, $auto, $notes);
        };
        $ctx->eq($w(null, [['state' => 'made', 'maker_id' => 41], ['state' => 'made', 'maker_id' => 42]], [], [], [])[3], 'agent_ambiguous', 'A6 step 2: two made makers → agent_ambiguous');
        $ctx->eq($w(null, [['state' => 'made', 'maker_id' => 41]], [], [], []), [41, 'maker', '', ''], 'A6 step 2: one made maker → that maker');
        $ctx->eq($w(null, [], [['quote_id' => 7, 'retailer_id' => 49], ['quote_id' => 7, 'retailer_id' => 50]], [], [])[3], 'agent_ambiguous', 'A6 step 3: two applications → agent_ambiguous');
        $ctx->eq($w(null, [], [['quote_id' => 'PENDING', 'retailer_id' => 49]], [], []), [null, 'none', '', 'no_agent'], 'A6 step 3: \'PENDING\' ignored');
        $ctx->eq($w(null, [], [], [['state' => 'making', 'maker_id' => 41, 'at' => $now - 60]], [$form()])[3], 'agent_ambiguous', 'A6 step 4: a maker and an application in flight → agent_ambiguous');
        $ctx->eq($w(null, [], [], [['state' => 'making', 'maker_id' => 41, 'at' => $now - 899]], []), [41, 'maker_in_flight', '', ''], 'A6 899 s in flight');
        $ctx->eq($w(null, [], [], [['state' => 'making', 'maker_id' => 41, 'at' => $now - 900]], []), [null, 'none', '', 'no_agent'], 'A6 900 s not');
        $ctx->eq(QuoteAgentCopy::kycInFlight($form(['submitted_at' => $ago(899)]), $now, true), true, 'A6 a form stamp 899 s old: in flight');
        $ctx->eq(QuoteAgentCopy::kycInFlight($form(['submitted_at' => $ago(900)]), $now, true), false, 'A6 900 s: not');
        $ctx->eq(QuoteAgentCopy::kycInFlight($form(['quote_created' => true]), $now, true), false, 'A6 quote_created true: not in flight');
        $ctx->eq(QuoteAgentCopy::kycInFlight($form(['quote_created' => true, 'quote_make_claim_ts' => $now - 5]), $now, true), false, 'A6 quote_created true: not in flight, whatever its claim says');
        $notBy = [];
        foreach (['quote_refused' => ['quote_refused' => 'refused (test)'], 'quote_error' => ['quote_error' => 'error (test)'],
                  'quote_debug' => ['quote_debug' => 'auto quoting off (test)'], 'crm_linked_existing' => ['crm_linked_existing' => true],
                  'no quote_ref' => ['quote_ref' => '']] as $k => $more) {
            $notBy[$k] = [QuoteAgentCopy::kycInFlight($form($more), $now, true),
                          QuoteAgentCopy::kycInFlight($form($more + ['submitted_at' => '2020-01-01 00:00:00', 'crm_synced_at' => $ago(30)]), $now, true)];
        }
        $ctx->eq($notBy, array_fill_keys(array_keys($notBy), [false, false]), 'A6 refused, errored, quote_debug, linked or quote_ref-less: not in flight by submitted_at or crm_synced_at');
        $ctx->eq([QuoteAgentCopy::kycInFlight($form(['submitted_at' => '2020-01-01 00:00:00', 'quote_refused' => 'x', 'quote_make_claim_ts' => $now - 30]), $now, true),
                  QuoteAgentCopy::kycInFlight($form(['submitted_at' => '2020-01-01 00:00:00', 'quote_error' => 'x', 'quote_make_claim_ts' => $now - 30]), $now, true),
                  QuoteAgentCopy::kycInFlight($form(['submitted_at' => '2020-01-01 00:00:00', 'quote_make_claim_ts' => $now + 1]), $now, true)],
            [true, true, true], 'A6 the claim stamp: in flight despite an older quote_refused or quote_error, and one second ahead of time()');
        $ctx->eq([QuoteAgentCopy::kycInFlight($form(), $now, false),
                  QuoteAgentCopy::kycInFlight($form(['submitted_at' => '2020-01-01 00:00:00', 'quote_make_claim_ts' => $now - 30]), $now, false)],
            [false, true], 'A6 automatic quoting off: not in flight by a stamp, in flight by a claim');
        $ctx->eq($w(null, [], [], [], [$form()], true, null), [49, 'kyc_in_flight', 'kyc_submitter', ''], 'A6 adminNotes absent: no condition');
        $ctx->eq($w(null, [], [], [], [$form()], true, 'Plugin ref:  · Agent: x'), [null, 'none', '', 'no_agent'], 'A6 adminNotes present without the mark: not a candidate');
        $ctx->eq($w(null, [], [], [], [$form()], true, 'KYC application #12'), [49, 'kyc_in_flight', 'kyc_submitter', ''], 'A6 adminNotes with the mark: a candidate');
        $ctx->eq([QuoteAgentCopy::notesMark('… for App #12', 'kyc', 1), QuoteAgentCopy::notesMark('… for App #12', 'kyc', 123),
                  QuoteAgentCopy::notesMark('… for App #12', 'kyc', 12), QuoteAgentCopy::notesMark('Plugin ref: MAN-1', 'maker', 0),
                  QuoteAgentCopy::notesMark('KYC application #12', 'maker', 0)],
            [false, false, true, true, false], 'A6 App #12 matches application 12, never 1 or 123; Plugin ref: marks a maker');
        $ctx->eq($w(['agent_id' => 41, 'rule' => 'kyc_sales_person', 'code' => 'added'], [['state' => 'made', 'maker_id' => 42]], [], [], []),
            [41, 'first_decision', 'kyc_sales_person', ''], 'A6 a first decision with an agent → that agent, source first_decision');
        $ctx->eq($w(['agent_id' => null, 'code' => 'agent_ambiguous'], [['state' => 'made', 'maker_id' => 42]], [], [], []),
            [null, 'first_decision', '', 'agent_ambiguous'], 'A6 … with agent_ambiguous → agent_ambiguous');
        $ctx->eq($w(['agent_id' => null, 'code' => 'error'], [['state' => 'made', 'maker_id' => 42]], [], [], []),
            [null, 'first_decision', '', 'no_agent'], 'A6 … with error → no_agent');
        $k = (string)file_get_contents($tree . '/lib/KycService.php');
        $ctx->eq(preg_match('/public const QUOTE_CLAIM_SECONDS = (\d+);/', $k, $mm) ? (int)$mm[1] : null, QuoteAgentCopy::IN_FLIGHT_SECONDS,
            'A6 IN_FLIGHT_SECONDS === KycService::QUOTE_CLAIM_SECONDS');
    });

    $ctx->run('A7 one meaning of the KYC agent (IP-1): quote_followup C\'s fixtures, and no rule of P7\'s own', function () use ($ctx, $tree) {
        require_once dirname(__DIR__) . '/quote_followup/C_owners.php';
        $got = []; $want = [];
        foreach (qf_kyc_agent_fixtures() as $name => $f) {
            $r = QuoteAgentCopy::webhookAgent(null, [], [$f['application'] + ['quote_id' => 7101]], [], [], $f['retailers'], time(), true, null);
            $got[$name] = $r[0];
            $want[$name] = QuoteSendEvidence::kycAgent($f['application'], $f['retailers'])['id'];
            $ctx->eq($want[$name], $f['want']['id'], "A7 the tracker's rule 1 gives the fixture's account ({$name})");
        }
        $ctx->eq($got, $want, 'A7 P7 and Part F\'s rule 1 give the same account id on every declared fixture');
        $lits = [];
        foreach (token_get_all((string)file_get_contents($tree . '/lib/QuoteAgentCopy.php')) as $tk) {
            if (is_array($tk) && $tk[0] === T_CONSTANT_ENCAPSED_STRING && in_array(trim($tk[1], '\'"'), ['sales_person', 'retailer_id', 'name'], true)) $lits[] = $tk[1];
        }
        $ctx->eq($lits, [], 'A7 lib/QuoteAgentCopy.php reads no sales_person, retailer_id or name itself');
        $code = '';
        foreach (token_get_all((string)file_get_contents($tree . '/lib/QuoteAgentCopy.php')) as $tk) {
            if (is_array($tk) && in_array($tk[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
            $code .= is_array($tk) ? $tk[1] : $tk;
        }
        $ctx->eq(substr_count($code, 'QuoteSendEvidence::kycAgent('), 2, 'A7 … it calls QuoteSendEvidence::kycAgent for both KYC steps (comments left out)');
    });

    $ctx->run('A8 afterSend', function () use ($ctx) {
        $p7 = ['address' => 'agent.one@agents.example.test', 'code' => 'added', 'source' => 'kyc', 'rule' => 'kyc_submitter', 'agent_id' => 49, 'decided_at' => '2026-11-02 07:00:00'];
        $ctx->eq(QuoteAgentCopy::afterSend($p7, ['sent' => false, 'reason' => 'already sent']), [['address' => ''] + ['code' => 'not_composed'] + $p7, 'none'],
            'A8 no agent_rcpt → not_composed, rcpt none');
        $codes = [];
        foreach (array_merge(QuoteAgentCopy::FLOOR_CODES, ['made_up']) as $c) {
            [$d, $rc] = QuoteAgentCopy::afterSend($p7, ['sent' => true, 'agent_rcpt' => 'excluded', 'agent_code' => $c]);
            $codes[$c] = [$d['code'], $rc, $d['address'], $d['decided_at']];
        }
        $want = [];
        foreach (QuoteAgentCopy::FLOOR_CODES as $c) $want[$c] = [$c, 'excluded', '', '2026-11-02 07:00:00'];
        $want['made_up'] = ['error', 'excluded', '', '2026-11-02 07:00:00'];
        $ctx->eq($codes, $want, 'A8 excluded with each floor code → that code, rcpt excluded; an unknown code → error');
        $kept = [];
        foreach (['accepted', 'refused', 'none'] as $rc) $kept[$rc] = QuoteAgentCopy::afterSend($p7, ['agent_rcpt' => $rc]);
        $ctx->eq($kept, ['accepted' => [$p7, 'accepted'], 'refused' => [$p7, 'refused'], 'none' => [$p7, 'none']], 'A8 accepted, refused and none kept');
        $ctx->eq(QuoteAgentCopy::afterSend(['address' => '', 'code' => 'no_agent'], ['agent_rcpt' => 'accepted']), [['address' => '', 'code' => 'no_agent'], 'none'],
            'A8 a skip stays a skip, rcpt none');
    });
}];
