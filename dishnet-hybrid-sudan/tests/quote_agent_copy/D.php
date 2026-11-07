<?php
declare(strict_types=1);
/**
 * quote_agent_copy D — the dispatcher (root docs/73 §H.5.8.3): send() only, its signature unchanged; the setter taken
 * and cleared once; the merge floor for the key 'quotation'; agent_rcpt / agent_code in the result. Run with the real
 * MailService against tests/fixtures/fake_smtp_server.php, each in a process of its own. TEST ONLY.
 */

/** The data every template is rendered with (the same for both trees). */
function qac_d_data(): array
{
    return ['first_name' => 'Test', 'quote_number' => 'QAC-D-1', 'total' => 1000, 'amount' => 1000, 'pdf_attached' => false,
        'invoice_number' => 'INV-QAC-1', 'due_date' => '9 Nov 2026', 'due_phrase' => 'due in 7 days', 'plan_name' => 'Test Plan',
        'install_date' => '10 Nov 2026', 'code' => '000000', 'ticket' => 'T-QAC-1', 'pay_url' => 'https://pay.example.test/x',
        'job' => 'J-QAC-1', 'link' => 'https://auth.example.test/x', 'reference' => 'R-QAC-1'];
}

$QAC_SECTIONS['D'] = ['title' => 'The dispatcher (§H.5.8.3)', 'run' => function (QfCtx $ctx) {
    qac_load($ctx);
    $tree = $ctx->tree();
    $base = qac_base($ctx);
    $A = QAC_AGENT;
    [$port, $tr] = qac_relay($ctx);
    $crm = ['client' => qac_client()];
    $send = function (string $key, string $dedupe, array $more = []) {
        return $more + ['key' => $key, 'client_id' => 901, 'name' => 'Test Customer', 'data' => qac_d_data(), 'dedupe' => $dedupe];
    };
    /** One dispatch step on a fresh book: [result, new sessions, book]. */
    $run = function (array $cfg, array $sends, string $agent = '', ?string $root = null, array $crmSpec = null, int $want = -1, ?int $p = null) use ($ctx, $tree, $port, $tr, $crm): array {
        $b = qac_book($ctx, $cfg, 'd', $p ?? $port);
        $n = count(qac_sessions($tr));
        $r = qac_drive($ctx, $root ?? $tree, $b, ['crm' => $crmSpec ?? $crm, 'steps' => [['do' => 'dispatch', 'agent_copy' => $agent, 'sends' => $sends]]]);
        $s = qac_c_new($tr, $n, $want >= 0 ? $want : count($sends));
        return [$r, $s, $b];
    };
    $cc = function (array $sess): ?string { return preg_match('/^Cc: (.*)$/mi', (string)($sess['data'] ?? ''), $m) ? trim($m[1]) : null; };

    $ctx->run('D0 positive control: agentCopy() then a quotation → one session carrying the agent, agent_rcpt accepted', function () use ($ctx, $run, $send, $A, $cc) {
        [$r, $s] = $run(qac_cfg('uganda', true), [$send('quotation', '9001')], $A['41']);
        $res = $r['steps'][0]['ret'][0] ?? [];
        $ctx->eq([$res['sent'] ?? null, $res['agent_rcpt'] ?? null, $s[0]['rcpt_to'] ?? null, $cc($s[0] ?? [])],
            [true, 'accepted', ['billing@cust.example.test', $A['41']], $A['41']], 'D0 sent, accepted, the To then the agent', json_encode($r['steps']) . $r['elog']);
    });

    $ctx->run('D1 no setter call: every catalogue key, reminder_due and both authorisation kinds as 221c9dc', function () use ($ctx, $run, $send, $base, $tree) {
        if ($base === null) { $ctx->t(false, 'D1 Git supplies 221c9dc — a failure, never a skip'); return; }
        $keys = ['quotation', 'payment_received', 'install_scheduled', 'welcome', 'invoice', 'service_paused', 'service_resumed', 'login_code', 'support_received'];
        $cfg = qac_cfg('uganda', true, ['email_cc_contacts' => '1', 'reminder_email_enabled' => '1', 'customer_email_install_auth_request' => '1',
            'customer_email_install_auth_confirmed' => '1'] + array_fill_keys(array_map(function ($k) { return 'customer_email_' . $k; }, $keys), '1'));
        $sends = [];
        foreach ($keys as $k) $sends[] = $send($k, 'd1-' . $k);
        $sends[] = ['kind' => 'reminder', 'client_id' => 901, 'name' => 'Test Customer', 'data' => qac_d_data(), 'dedupe' => 'd1-rem'];
        $sends[] = ['kind' => 'install', 'type' => 'request', 'email' => 'billing@cust.example.test', 'name' => 'Test Customer', 'data' => qac_d_data(), 'dedupe' => 'd1-ir'];
        $sends[] = ['kind' => 'install', 'type' => 'confirmed', 'email' => 'billing@cust.example.test', 'name' => 'Test Customer', 'data' => qac_d_data(), 'dedupe' => 'd1-ic'];
        [$rt, $st] = $run($cfg, $sends, '', $tree, null, 0);
        [$ro, $so] = $run($cfg, $sends, '', $base, null, 0);
        $sentN = count(array_filter((array)$rt['steps'][0]['ret'], function ($x) { return !empty($x['sent']); }));
        $ctx->t($sentN >= 10, 'D1 positive control: the sends went (' . $sentN . ' of 12 sent)', json_encode($rt['steps']));
        $ctx->eq([$rt['steps'], qac_mask($st)], [$ro['steps'], qac_mask($so)], 'D1 the results and the relay transcripts are 221c9dc\'s');
    });

    $ctx->run('D2 agentCopy() then another key: no Cc change, and the copy cleared', function () use ($ctx, $run, $send, $A, $cc) {
        [$r, $s] = $run(qac_cfg('uganda', true, ['customer_email_invoice' => '1']), [$send('invoice', 'INV-1'), $send('quotation', '9002')], $A['41']);
        $res = (array)$r['steps'][0]['ret'];
        $ctx->eq([$res[0]['sent'] ?? null, array_key_exists('agent_rcpt', $res[0] ?? []), $cc($s[0] ?? []), $s[0]['rcpt_to'] ?? null],
            [true, false, null, ['billing@cust.example.test']], 'D2 the invoice: no Cc, no agent_rcpt');
        $ctx->eq([$res[1]['sent'] ?? null, array_key_exists('agent_rcpt', $res[1] ?? []), $cc($s[1] ?? []), $s[1]['rcpt_to'] ?? null],
            [true, false, null, ['billing@cust.example.test']], 'D2 a following quotation on the same instance carries none');
    });

    $ctx->run('D3 agentCopy() then a quotation that returns early: cleared, no agent_rcpt', function () use ($ctx, $tree, $run, $send, $A, $cc, $port, $tr, $crm) {
        // already sent
        $b = qac_book($ctx, qac_cfg('uganda', true), 'd3', $port);
        qac_row($b, 9003, time() - 60, 'sent');
        $n = count(qac_sessions($tr));
        $r = qac_drive($ctx, $tree, $b, ['crm' => $crm, 'steps' => [['do' => 'dispatch', 'agent_copy' => $A['41'], 'sends' => [$send('quotation', '9003'), $send('quotation', '9004')]]]]);
        $s = qac_c_new($tr, $n, 1);
        $res = (array)$r['steps'][0]['ret'];
        $ctx->eq([$res[0]['reason'] ?? null, array_key_exists('agent_rcpt', $res[0] ?? []), count($s), $cc($s[0] ?? []), array_key_exists('agent_rcpt', $res[1] ?? [])],
            ['already sent', false, 1, null, false], 'D3 already sent: no agent_rcpt; the next quotation carries none');
        // no usable e-mail address
        [$r] = $run(qac_cfg('uganda', true), [$send('quotation', '9005'), $send('quotation', '9006')], $A['41'], null, ['client' => qac_client(['contacts' => []])], 0);
        $res = (array)$r['steps'][0]['ret'];
        $ctx->eq([$res[0]['reason'] ?? null, array_key_exists('agent_rcpt', $res[0] ?? []), array_key_exists('agent_rcpt', $res[1] ?? [])],
            ['no usable email address', false, false], 'D3 no usable e-mail address: no agent_rcpt, cleared');
        // plugin mail not configured
        $b = qac_book($ctx, qac_cfg('uganda', true), 'd3');
        $r = qac_drive($ctx, $tree, $b, ['crm' => $crm, 'steps' => [['do' => 'dispatch', 'agent_copy' => $A['41'], 'sends' => [$send('quotation', '9007')]]]]);
        $res = (array)$r['steps'][0]['ret'];
        $ctx->eq([$res[0]['reason'] ?? null, array_key_exists('agent_rcpt', $res[0] ?? [])], ['plugin mail is not configured', false], 'D3 plugin mail not configured: no agent_rcpt');
    });

    $ctx->run('D4 the floor: an address planted through the setter, bypassing decide, is dropped and reported', function () use ($ctx, $run, $send, $A, $cc) {
        $fill = qac_fill(999, ['other@cust.example.test'], $A['41']);
        $client = qac_client(['contacts' => array_merge([['email' => 'other@cust.example.test'], ['email' => 'billing@cust.example.test', 'isBilling' => true]],
            array_map(function ($e) { return ['email' => $e]; }, $fill))]);
        $cases = [
            'the To'              => ['Billing@Cust.example.test', 'same_as_customer', null],
            'a client copy'       => ['OTHER@cust.example.test', 'already_copied', null],
            'unusable'            => ["x@agents.example.test\r\nBcc: y@evil.example.test", 'agent_email_invalid', null],
            'a 999-octet line'    => [$A['41'], 'header_too_long', $client],
        ];
        $got = []; $want = [];
        foreach ($cases as $k => [$addr, $code, $cl]) {
            [$r, $s] = $run(qac_cfg('uganda', true, ['email_cc_contacts' => '1']), [$send('quotation', '9010')], $addr, null, $cl !== null ? ['client' => $cl] : null);
            $res = $r['steps'][0]['ret'][0] ?? [];
            $rc = $s[0]['rcpt_to'] ?? [];
            $got[$k] = [$res['sent'] ?? null, $res['agent_rcpt'] ?? null, $res['agent_code'] ?? null, in_array(strtolower(trim($addr)), array_map('strtolower', $rc), true) && $k !== 'the To' && $k !== 'a client copy',
                        strpos((string)($s[0]['data'] ?? ''), 'evil.example.test') !== false];
            $want[$k] = [true, 'excluded', $code, false, false];
        }
        $ctx->eq($got, $want, 'D4 each: sent, agent_rcpt excluded with its code, no RCPT of its own, nothing of it in the message');
        // The To as the dispatcher's own read gives it, stored in upper case (root docs/73 §H.5.4.5, §H.5.8.3); the agent planted in
        // lower case. Only the floor's own To comparison can refuse it (decide never ran): PW5b.
        $up = qac_client(['contacts' => [['email' => 'other@cust.example.test'], ['email' => 'BILLING@Cust.Example.Test', 'isBilling' => true]]]);
        [$r, $s] = $run(qac_cfg('uganda', true, ['email_cc_contacts' => '1']), [$send('quotation', '9014')], 'billing@cust.example.test', null, ['client' => $up]);
        $res = $r['steps'][0]['ret'][0] ?? [];
        $rc = array_map(function ($x) { return strtolower((string)$x); }, (array)($s[0]['rcpt_to'] ?? []));
        $ctx->eq([$res['sent'] ?? null, $res['agent_rcpt'] ?? null, $res['agent_code'] ?? null, count(array_keys($rc, 'billing@cust.example.test', true)), $cc($s[0] ?? [])],
            [true, 'excluded', 'same_as_customer', 1, 'other@cust.example.test'],
            'D4 the To stored in upper case, the agent in lower: sent, excluded as same_as_customer, the To one RCPT, the Cc the client\'s own', json_encode($r['steps']) . json_encode($s));
    });

    $ctx->run('D5 agent_rcpt from the relay', function () use ($ctx, $run, $send, $A) {
        [$r] = $run(qac_cfg('uganda', true), [$send('quotation', '9011')], strtoupper($A['41']));
        $ctx->eq($r['steps'][0]['ret'][0]['agent_rcpt'] ?? null, 'accepted', 'D5 the relay took the agent\'s RCPT: accepted (the address as handed in, any case)');
    });

    $ctx->run('D6 the source: construction, Reply-To and the signature of send() unchanged', function () use ($ctx, $tree) {
        $src = (string)file_get_contents($tree . '/lib/CustomerEmailDispatcher.php');
        $old = qac_base_file('lib/CustomerEmailDispatcher.php');
        $ctx->t($old !== null, 'D6 Git supplies 221c9dc\'s dispatcher');
        if ($old === null) return;
        $ctx->eq([substr_count($src, 'self::effectiveConfig($this->config)'), substr_count($src, 'EmailTemplate::replyTo('), substr_count($src, '$this->config  = self::effectiveConfig($config);')],
            [substr_count($old, 'self::effectiveConfig($this->config)'), substr_count($old, 'EmailTemplate::replyTo('), substr_count($old, '$this->config  = self::effectiveConfig($config);')],
            'D6 no new self::effectiveConfig($this->config), no new EmailTemplate::replyTo(, the constructor\'s one resolution kept');
        $sig = 'public function send(string $key, $to, string $name, array $data, string $dedupe = \'\', array $attachments = []): array';
        $ctx->eq([substr_count($src, $sig), substr_count($old, $sig)], [1, 1], 'D6 send()\'s signature unchanged');
        $fnOf = function (string $s, string $name): string {
            $p = strpos($s, 'public function ' . $name . '(');
            $q = $p === false ? false : strpos($s, "\n    }\n", $p);
            return ($p === false || $q === false) ? '' : substr($s, $p, $q - $p);
        };
        $ctx->eq([$fnOf($src, 'sendReminderDue') === $fnOf($old, 'sendReminderDue') && $fnOf($old, 'sendReminderDue') !== '',
                  $fnOf($src, 'sendInstallAuth') === $fnOf($old, 'sendInstallAuth') && $fnOf($old, 'sendInstallAuth') !== ''], [true, true],
            'D6 sendReminderDue() and sendInstallAuth() byte for byte 221c9dc\'s');
    });

    $ctx->run('D7 fault injection: mergeCc or rcptOutcome throwing', function () use ($ctx, $run, $send, $A, $cc) {
        [$r, $s] = $run(qac_cfg('uganda', true, ['email_cc_contacts' => '1']), [$send('quotation', '9012')], $A['41'], qac_stub($ctx, 'mergeCc'));
        $res = $r['steps'][0]['ret'][0] ?? [];
        $ctx->eq([$res['sent'] ?? null, $res['agent_rcpt'] ?? null, $res['agent_code'] ?? null, $cc($s[0] ?? []), $s[0]['rcpt_to'] ?? null],
            [true, 'excluded', 'error', 'other@cust.example.test', ['billing@cust.example.test', 'other@cust.example.test']],
            'D7 mergeCc throws: sent as 5.18.103 with the client\'s own Cc, agent_rcpt excluded, agent_code error');
        [$r, $s] = $run(qac_cfg('uganda', true), [$send('quotation', '9013')], $A['41'], qac_stub($ctx, 'rcptOutcome'));
        $res = $r['steps'][0]['ret'][0] ?? [];
        $ctx->eq([$res, $s[0]['rcpt_to'] ?? null], [['sent' => true, 'reason' => 'sent', 'to' => 'billing@cust.example.test'], ['billing@cust.example.test', $A['41']]],
            'D7 rcptOutcome throws: the copy sent, the result exactly 5.18.103\'s, no agent_rcpt');
    });
}];
