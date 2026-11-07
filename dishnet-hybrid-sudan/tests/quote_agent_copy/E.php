<?php
declare(strict_types=1);
/**
 * quote_agent_copy E — the relay (root docs/73 §H.5.4.6): the real MailService against tests/fixtures/fake_smtp_refuse.php.
 * A refused agent RCPT costs nothing (E1); a slow one only time (E2); a malformed stored address never reaches the
 * header or a RCPT (E3); the To goes first (E4); the residual whole-send failures are pinned (E5, E6); the 998-octet line
 * (E7). TEST ONLY; every address fictitious.
 */
$QAC_SECTIONS['E'] = ['title' => 'The relay (§H.5.4.6)', 'run' => function (QfCtx $ctx) {
    qac_load($ctx);
    $tree = $ctx->tree();
    $A = QAC_AGENT;
    $crm = ['client' => qac_client(), 'quote_id' => 7501];
    /** A book on a relay with $rules; [book, port, transcript]. */
    $on = function (?array $rules, array $more = [], ?array $accounts = null, string $tag = 'e') use ($ctx): array {
        [$port, $tr] = qac_relay($ctx, $rules ?? []);
        $b = qac_book($ctx, qac_cfg('uganda', ($more['_p7'] ?? true), array_diff_key($more, ['_p7' => 1])), $tag, $port, $accounts);
        return [$b, $port, $tr];
    };
    $sends = function (array $r): array { return array_values(array_filter($r['crm'], function ($q) { return $q[0] === 'PATCH' && substr($q[1], -5) === '/send'; })); };
    $qemail = function (array $b, int $id): int { return (int)$b['pdo']->query("SELECT COUNT(*) FROM notification_dedup WHERE dedup_key = 'QEMAIL{$id}'")->fetchColumn(); };
    $dataN = function (array $s): int { return count(array_filter((array)($s['commands'] ?? []), function ($c) { return strtoupper((string)$c) === 'DATA'; })); };

    $ctx->run('E0 positive control: the refusing relay delivers a message that names no listed address', function () use ($ctx, $tree, $on, $crm, $A) {
        [$b, , $tr] = $on(['refuse' => ['nobody@agents.example.test']]);
        $r = qac_drive($ctx, $tree, $b, ['crm' => $crm, 'mailer' => 'real', 'steps' => [qac_b_app(['id' => 41])]]);
        $s = qac_sessions($tr, 1);
        $ctx->eq([count($s), $s[0]['result'] ?? null, $s[0]['rcpt_to'] ?? null, $r['steps'][0]['ret']['emailed_by_plugin'] ?? null],
            [1, 'queued', ['billing@cust.example.test', $A['41']], true], 'E0 one session, queued, the To and the agent', json_encode($r['steps']) . $r['elog']);
    });

    $ctx->run('E1 the agent\'s RCPT refused with 550: the customer\'s message delivered once, nothing else changes', function () use ($ctx, $tree, $on, $crm, $A, $sends, $qemail) {
        [$b, , $tr] = $on(['refuse' => [$A['41']]]);
        $r = qac_drive($ctx, $tree, $b, ['crm' => $crm, 'mailer' => 'real', 'steps' => [qac_b_app(['id' => 41])]]);
        $s = qac_sessions($tr, 1);
        $d = qac_decisions($b['pdo']);
        $ctx->eq([count($s), $s[0]['result'] ?? null, $s[0]['rcpt_to'] ?? null, $s[0]['refused'] ?? null], [1, 'queued', ['billing@cust.example.test'], [$A['41']]],
            'E1 app path: one session, DATA accepted, the customer a recipient, the agent refused');
        $ctx->eq([$d[0]['code'] ?? null, $d[0]['rcpt'] ?? null, $d[0]['send_ok'] ?? null, $qemail($b, 7501), count($sends($r)), $r['steps'][0]['ret']['emailed_by_plugin'] ?? null],
            ['added', 'refused', 1, 1, 0, true], 'E1 app path: added, rcpt refused, send_ok 1; the claim kept; no uCRM /send');
        [$b, , $tr] = $on(['refuse' => [$A['49']]]);
        qac_app($b, ['crm_client_id' => 901, 'quote_id' => 7502, 'retailer_id' => 49]);
        qac_drive($ctx, $tree, $b, ['crm' => ['client' => qac_client()], 'steps' => [qac_c_wh(7502)]]);
        $s = qac_sessions($tr, 1);
        $d = qac_decisions($b['pdo']);
        $row = $b['pdo']->query("SELECT status FROM customer_email_log WHERE dedupe_key = 'quotation:7502'")->fetchColumn();
        $ctx->eq([count($s), $s[0]['result'] ?? null, $d[0]['code'] ?? null, $d[0]['rcpt'] ?? null, $d[0]['send_ok'] ?? null, $qemail($b, 7502), $row],
            [1, 'queued', 'added', 'refused', 1, 1, 'sent'], 'E1 webhook path: delivered once, added/refused/sent, the claim kept, customer_email_log sent');
    });

    $ctx->run('E2 the agent\'s RCPT answered after 3 s: delivered once, one DATA', function () use ($ctx, $tree, $on, $crm, $A, $dataN) {
        [$b, , $tr] = $on(['delay' => [$A['41'] => 3]]);
        $t0 = microtime(true);
        qac_drive($ctx, $tree, $b, ['crm' => $crm, 'mailer' => 'real', 'steps' => [qac_b_app(['id' => 41])]]);
        $dt = microtime(true) - $t0;
        $s = qac_sessions($tr, 1);
        $d = qac_decisions($b['pdo']);
        $ctx->eq([count($s), $s[0]['result'] ?? null, $dataN($s[0] ?? []), $d[0]['rcpt'] ?? null, $d[0]['send_ok'] ?? null], [1, 'queued', 1, 'accepted', 1],
            'E2 one session, one DATA, accepted');
        echo '  note E2 the run took ' . number_format($dt, 1) . " s with the agent's RCPT answered after 3 s (documentation)\n";
    });

    $ctx->run('E3 malformed addresses stored on the account: no RCPT and no header line carries them', function () use ($ctx, $tree, $on, $crm) {
        $evil = [61 => "a@agents.example.test\r\nBcc: x@evil.example.test", 62 => 'a@agents.example.test,b@evil.example.test',
                 63 => 'Agent <a@evil.example.test>', 64 => '"a,b"@evil.example.test', 65 => "\"a\\\r\nBcc: x\"@evil.example.test"];
        $acc = qac_accounts();
        foreach ($evil as $id => $e) $acc[] = ['id' => $id, 'name' => 'Test Evil ' . $id, 'email' => $e, 'is_active' => true];
        [$b, , $tr] = $on(null, [], $acc);
        $r = qac_drive($ctx, $tree, $b, ['crm' => $crm, 'mailer' => 'real', 'steps' => array_map(function ($id) { return qac_b_app(['id' => $id]); }, array_keys($evil))]);
        $s = qac_sessions($tr, count($evil));
        $d = qac_decisions($b['pdo']);
        $blob = json_encode($s);
        $ctx->eq([count($s), count($d), array_unique(array_map(function ($x) { return $x['code'] ?? ''; }, $d)), strpos($blob, 'evil.example.test'),
                  array_map(function ($x) { return $x['rcpt_to']; }, $s)],
            [5, 5, ['agent_email_invalid'], false, array_fill(0, 5, ['billing@cust.example.test'])],
            'E3 five sends: agent_email_invalid each, only the customer as recipient, nothing of the stored value in any transcript');
    });

    $ctx->run('E4 the RCPT order: the To first', function () use ($ctx, $tree, $on, $crm, $A) {
        [$b, , $tr] = $on(null, ['email_cc_contacts' => '1']);
        qac_drive($ctx, $tree, $b, ['crm' => $crm, 'mailer' => 'real', 'steps' => [qac_b_app(['id' => 41])]]);
        $s = qac_sessions($tr, 1);
        $rc = array_values(array_filter((array)($s[0]['commands'] ?? []), function ($c) { return stripos((string)$c, 'RCPT TO:') === 0; }));
        $ctx->eq($rc, ['RCPT TO:<billing@cust.example.test>', 'RCPT TO:<other@cust.example.test>', 'RCPT TO:<' . $A['41'] . '>'],
            'E4 the To, the client\'s copy, then the agent');
    });

    $ctx->run('E5 a 16-second stall on the agent\'s RCPT fails the send exactly as the same stall on a client contact (P7 off)', function () use ($ctx, $tree, $on, $crm, $A, $sends) {
        [$b, , $tr] = $on(['delay' => [$A['41'] => 16]]);
        $r = qac_drive($ctx, $tree, $b, ['crm' => $crm, 'mailer' => 'real', 'steps' => [qac_b_app(['id' => 41])]]);
        [$b0, , $tr0] = $on(['delay' => ['other@cust.example.test' => 16]], ['_p7' => false, 'email_cc_contacts' => '1']);
        $r0 = qac_drive($ctx, $tree, $b0, ['crm' => $crm, 'mailer' => 'real', 'steps' => [qac_b_app(['id' => 41])]]);
        $ctx->eq([$r['steps'][0]['ret'] ?? null, count($sends($r))], [$r0['steps'][0]['ret'] ?? null, count($sends($r0))],
            'E5 the same answer as the control (not e-mailed by the plugin, its error), and uCRM\'s /send once each');
        $ctx->eq([count($sends($r)), $r['steps'][0]['ret']['emailed_by_plugin'] ?? null, count(qac_sessions($tr, 1)), qac_decisions($b['pdo'])[0]['send_ok'] ?? null],
            [1, false, 1, 0], 'E5 P7 does not retry: one session, uCRM\'s /send once, the decision send_ok 0');
    });

    $ctx->run('E6 the relay rejects the end of DATA when the agent was a recipient (P7S-01)', function () use ($ctx, $tree, $on, $crm, $A, $sends, $qemail) {
        [$b, , $tr] = $on(['data554' => [$A['49']]]);
        qac_app($b, ['crm_client_id' => 901, 'quote_id' => 7506, 'retailer_id' => 49]);
        $r = qac_drive($ctx, $tree, $b, ['crm' => ['client' => qac_client()], 'steps' => [qac_c_wh(7506)]]);
        $s = qac_sessions($tr, 1);
        $d = qac_decisions($b['pdo']);
        $ctx->eq([count($s), $s[0]['result'] ?? null, $qemail($b, 7506), $d[0]['code'] ?? null, $d[0]['send_ok'] ?? null, $d[0]['rcpt'] ?? null],
            [1, 'rejected', 0, 'added', 0, 'accepted'], 'E6 path 3: rejected, the claim released, the decision added, send_ok 0, rcpt accepted');
        $c = QuoteAgentCopy::check(qac_ro($b['data']), $tree, $b['data'], time() + 600);
        $ctx->eq([$c['status'], $c['counts']['p3_failed_carrying_copy_rcpt_accepted'] ?? null],
            ['PASS', 1], 'E6 AC-ON reads PASS, "failed carrying a copy" 1 on path 3 at rcpt accepted (ruling R18)', json_encode($c));
        $ctx->t(in_array('path 3: failed carrying a copy 1 — a lost e-mail: resend with tools/quote_email_send.php', $c['notes'], true), 'E6 … with the line "a lost e-mail: resend with tools/quote_email_send.php"', json_encode($c['notes']));
        [$b, , $tr] = $on(['data554' => [$A['41']]]);
        $r = qac_drive($ctx, $tree, $b, ['crm' => $crm, 'mailer' => 'real', 'steps' => [qac_b_app(['id' => 41])]]);
        $ctx->eq([count($sends($r)), $r['steps'][0]['ret']['emailed_by_plugin'] ?? null, qac_decisions($b['pdo'])[0]['send_ok'] ?? null], [1, false, 0],
            'E6 path 1: handed over to uCRM\'s /send once');
        [$b, , $tr] = $on(['data554' => [$A['49']]], ['_p7' => false]);
        qac_app($b, ['crm_client_id' => 901, 'quote_id' => 7507, 'retailer_id' => 49]);
        qac_drive($ctx, $tree, $b, ['crm' => ['client' => qac_client()], 'steps' => [qac_c_wh(7507)]]);
        $s = qac_sessions($tr, 1);
        $ctx->eq([count($s), $s[0]['result'] ?? null], [1, 'queued'], 'E6 control: the same message without the agent is delivered');
    });

    $ctx->run('E7 the Cc line: one octet past 998 → no copy; one contact fewer → added', function () use ($ctx, $tree, $on, $A) {
        $fill = qac_fill(999, ['other@cust.example.test'], $A['41']);
        $mk = function (array $fill): array {
            return qac_client(['contacts' => array_merge([['email' => 'other@cust.example.test'], ['email' => 'billing@cust.example.test', 'isBilling' => true]],
                array_map(function ($e) { return ['email' => $e]; }, $fill))]);
        };
        $res = [];
        foreach (['on' => true, 'off' => false] as $k => $p7) {
            [$b, , $tr] = $on(null, ['_p7' => $p7, 'email_cc_contacts' => '1']);
            $r = qac_drive($ctx, $tree, $b, ['crm' => ['client' => $mk($fill), 'quote_id' => 7508], 'mailer' => 'real', 'steps' => [qac_b_app(['id' => 41])]]);
            $res[$k] = [qac_mask(qac_sessions($tr, 1)), qac_decisions($b['pdo'])];
        }
        $ctx->eq([$res['on'][0], $res['on'][1][0]['code'] ?? null], [$res['off'][0], 'header_too_long'], 'E7 999 octets: header_too_long, the transcript exactly as P7 off');
        [$b, , $tr] = $on(null, ['email_cc_contacts' => '1']);
        qac_drive($ctx, $tree, $b, ['crm' => ['client' => $mk(array_slice($fill, 0, -1)), 'quote_id' => 7509], 'mailer' => 'real', 'steps' => [qac_b_app(['id' => 41])]]);
        $s = qac_sessions($tr, 1);
        $line = preg_match('/^(Cc: .*)$/mi', (string)($s[0]['data'] ?? ''), $m) ? rtrim($m[1], "\r") : '';
        $ctx->eq([qac_decisions($b['pdo'])[0]['code'] ?? null, strlen($line) <= 998, substr($line, -strlen($A['41']))], ['added', true, $A['41']],
            'E7 one contact fewer: added, the line at most 998 octets, the agent last');
    });
}];
