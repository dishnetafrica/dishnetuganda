<?php
declare(strict_types=1);
/**
 * quote_agent_copy H — South Sudan unchanged (root docs/73 §H.5.16): on a South Sudan book, 221c9dc (from Git) and this
 * tree each run createCrmQuote (quote_email_via_plugin on), whQuotationEmail (the quotation switch on), the dispatcher's
 * send() for every catalogue key, sendReminderDue and sendInstallAuth, against fake_smtp_server.php and the fake uCRM:
 * the transcripts, the uCRM requests, quote_mail.log, customer_email_log, the webhook log, the PHP error log and the
 * answers must be identical (1); the same with P7's three keys written ON by hand into South Sudan's configuration (2);
 * then no P7 table and QuoteAgentCopy never loaded (3); and a Uganda book with P7 off identical in the same way (4).
 * TEST ONLY; every name and address fictitious.
 */
$QAC_SECTIONS['H'] = ['title' => 'South Sudan, and Uganda with P7 off, as 221c9dc (§H.5.16)', 'run' => function (QfCtx $ctx) {
    qac_load($ctx);
    $tree = $ctx->tree();
    $base = qac_base($ctx);
    if ($base === null) { $ctx->t(false, 'H Git supplies 221c9dc — without it nothing can be compared: a failure, never a skip'); return; }
    [$port, $tr] = qac_relay($ctx);
    $keys = ['quotation', 'payment_received', 'install_scheduled', 'welcome', 'invoice', 'service_paused', 'service_resumed', 'login_code', 'support_received'];
    $sw = ['email_cc_contacts' => '1', 'reminder_email_enabled' => '1', 'customer_email_install_auth_request' => '1', 'customer_email_install_auth_confirmed' => '1']
        + array_fill_keys(array_map(function ($k) { return 'customer_email_' . $k; }, $keys), '1');
    $steps = [qac_b_app(['id' => 41, 'name' => 'Test Agent One']), qac_c_wh(8801)];
    $sends = [];
    foreach ($keys as $k) $sends[] = ['key' => $k, 'client_id' => 901, 'name' => 'Test Customer', 'data' => qac_d_data(), 'dedupe' => 'h-' . $k];
    $sends[] = ['kind' => 'reminder', 'client_id' => 901, 'name' => 'Test Customer', 'data' => qac_d_data(), 'dedupe' => 'h-rem'];
    $sends[] = ['kind' => 'install', 'type' => 'request', 'email' => 'billing@cust.example.test', 'name' => 'Test Customer', 'data' => qac_d_data(), 'dedupe' => 'h-ir'];
    $sends[] = ['kind' => 'install', 'type' => 'confirmed', 'email' => 'billing@cust.example.test', 'name' => 'Test Customer', 'data' => qac_d_data(), 'dedupe' => 'h-ic'];
    $steps[] = ['do' => 'dispatch', 'sends' => $sends];
    /** Everything a run leaves, with what varies from run to run masked. */
    $run = function (string $root, array $cfg) use ($ctx, $port, $tr, $steps): array {
        $b = qac_book($ctx, $cfg, 'h', $port);
        qac_app($b, ['crm_client_id' => 901, 'quote_id' => 8801, 'retailer_id' => 49]);
        $n = count(qac_sessions($tr));
        $r = qac_drive($ctx, $root, $b, ['crm' => ['client' => qac_client(), 'quote_id' => 7801], 'mailer' => 'real', 'steps' => $steps]);
        $s = array_slice(qac_sessions($tr, $n + 14), $n);
        $log = $b['pdo']->query('SELECT dedupe_key, template, recipient, status, error, sender FROM customer_email_log ORDER BY dedupe_key')->fetchAll(\PDO::FETCH_ASSOC);
        return [
            'answers' => $r['steps'], 'ucrm' => $r['crm'], 'whlog' => $r['whlog'], 'relay' => qac_mask($s),
            'quote_mail' => preg_replace('/^\[[0-9: -]{19}\]/m', '[t]', (string)@file_get_contents($b['data'] . '/quote_mail.log')),
            'customer_email_log' => $log, 'php_error_log' => qac_elog($r['elog']),
            '_tables' => qac_tables($b['pdo']), '_loaded' => $r['qac_loaded'], '_rc' => $r['rc'], '_sessions' => count($s),
        ];
    };
    $strip = function (array $x): array { return array_diff_key($x, ['_tables' => 1, '_loaded' => 1, '_rc' => 1, '_sessions' => 1]); };
    $cases = [
        '1 South Sudan'                          => qac_cfg('south-sudan', false, $sw),
        '2 South Sudan, P7\'s keys written ON'   => qac_cfg('south-sudan', true, $sw + ['quote_agent_cc_admins' => '1']),
        '4 Uganda, P7 off'                       => qac_cfg('uganda', false, $sw),
    ];
    foreach ($cases as $k => $cfg) {
        $ctx->run('H' . $k, function () use ($ctx, $run, $strip, $tree, $base, $cfg, $k) {
            $t = $run($tree, $cfg);
            $o = $run($base, $cfg);
            $ctx->t($t['_rc'] === 0 && $t['_sessions'] >= 12 && count($t['customer_email_log']) >= 10,
                "H{$k}: positive control — the runs sent ({$t['_sessions']} sessions, " . count($t['customer_email_log']) . ' log rows)');
            foreach (array_keys($strip($t)) as $part) {
                $ctx->eq($t[$part], $o[$part], "H{$k}: {$part} identical to 221c9dc's");
            }
            $ctx->eq([$t['_tables'], $t['_loaded']], [[], strpos($k, 'South Sudan') !== false ? false : $t['_loaded']],
                "H{$k}: no quote_agent_* table" . (strpos($k, 'South Sudan') !== false ? '; QuoteAgentCopy never loaded in the run\'s process' : ''));
        });
    }
}];
