<?php
declare(strict_types=1);
/**
 * quote_agent_copy I — privacy and logging (root docs/73 §H.5.9; C8): every record, PluginLog line, PHP error-log line,
 * log file and printed output P7's paths produce is scanned. None holds a fixture agent's address, a fixture account's
 * name or a phone; P7's own records hold no '@' at all; a decision row has exactly the eleven declared fields beside the
 * store's id, and a maker row its six. TEST ONLY; every name and address fictitious.
 */
$QAC_SECTIONS['I'] = ['title' => 'Privacy and logging (§H.5.9)', 'run' => function (QfCtx $ctx) {
    qac_load($ctx);
    $tree = $ctx->tree();
    $A = QAC_AGENT;
    $agents = array_values($A);
    $names = QAC_NAMES;
    $seen = ['records' => [], 'makers' => [], 'logs' => [], 'printed' => []];

    $ctx->run('I0 positive control: the scenarios ran and left P7 records to scan', function () use ($ctx, $tree, &$seen) {
        [$port, $tr] = qac_relay($ctx, ['refuse' => [QAC_AGENT['49']]]);
        $books = [];
        // The app path: added, a skip, a failed send, a lost write.
        $b = qac_book($ctx, qac_cfg('uganda', true, ['email_cc_contacts' => '1']), 'i', $port);
        $r1 = qac_drive($ctx, $tree, $b, ['crm' => ['client' => qac_client(), 'quote_id' => 7901], 'mailer' => 'real',
            'steps' => [qac_b_app(['id' => 41]), qac_b_app(['id' => 44]), qac_b_app(['id' => 45])]]);
        $books[] = [$b, $r1];
        $b2 = qac_book($ctx, qac_cfg('uganda', true), 'i', $port);
        $r2 = qac_drive($ctx, $tree, $b2, ['crm' => ['client' => qac_client(), 'quote_id' => 7911], 'rec_ok' => false, 'steps' => [qac_b_app(['id' => 42])]]);
        $books[] = [$b2, $r2];
        $b3 = qac_book($ctx, qac_cfg('uganda', true), 'i', $port);
        $r3 = qac_drive($ctx, $tree, $b3, ['crm' => ['client' => qac_client(), 'quote_id' => 7921], 'store_faults' => ['write' => ['quote_agent_copies.json', 'quote_agent_makers.json']],
            'steps' => [qac_b_app(['id' => 41])]]);
        $books[] = [$b3, $r3];
        // The webhook path: added and refused at the relay, not composed, the floor.
        $b4 = qac_book($ctx, qac_cfg('uganda', true, ['email_cc_contacts' => '1']), 'i', $port);
        qac_app($b4, ['crm_client_id' => 901, 'quote_id' => 7931, 'retailer_id' => 49]);
        qac_app($b4, ['crm_client_id' => 901, 'quote_id' => 7932, 'retailer_id' => 45]);
        qac_app($b4, ['crm_client_id' => 901, 'quote_id' => 7933, 'retailer_id' => 41]);
        qac_row($b4, 7933, time() - 60);
        $r4 = qac_drive($ctx, $tree, $b4, ['crm' => ['client' => qac_client()], 'steps' => [qac_c_wh(7931), qac_c_wh(7932), qac_c_wh(7933)]]);
        $books[] = [$b4, $r4];
        foreach ($books as [$bk, $r]) {
            $seen['records'] = array_merge($seen['records'], qac_decisions($bk['pdo']));
            $seen['makers'] = array_merge($seen['makers'], qac_decisions($bk['pdo'], 'quote_agent_makers'));
            $seen['logs'][] = $r['elog'];
            $seen['logs'][] = json_encode($r['whlog'], JSON_UNESCAPED_UNICODE);
            $seen['logs'][] = (string)@file_get_contents($bk['data'] . '/quote_mail.log');
            $seen['printed'][] = json_encode(QuoteAgentCopy::check(qac_ro($bk['data']), $tree, $bk['data'], time() + 600), JSON_UNESCAPED_UNICODE);
        }
        $pl = (string)@file_get_contents($tree . '/data/plugin.log');
        $seen['plugin_log'] = implode("\n", array_filter(explode("\n", $pl), function ($l) { return strpos($l, '[quote_agent_copy]') !== false; }));
        $ctx->t(count($seen['records']) >= 6 && count($seen['makers']) >= 3 && $seen['plugin_log'] !== '',
            'I0 ' . count($seen['records']) . ' decisions, ' . count($seen['makers']) . ' maker rows and P7\'s PluginLog lines to scan');
    });

    $ctx->run('I1 the records: exactly the declared fields, no address, name or phone', function () use ($ctx, &$seen, $names) {
        $want = ['quote_id', 'number', 'path', 'outcome', 'code', 'source', 'rule', 'agent_id', 'rcpt', 'send_ok', 'decided_at', 'id'];
        $shapes = array_unique(array_map(function ($d) { return implode(',', array_keys($d)); }, $seen['records']));
        $ctx->eq(array_values($shapes), [implode(',', $want)], 'I1 every decision row: the eleven declared fields beside the store\'s id');
        $mk = array_unique(array_map(function ($d) { return implode(',', array_keys($d)); }, $seen['makers']));
        $ctx->eq(array_values($mk), ['client_id,maker_id,quote_id,state,at,settled_at,id'], 'I1 every maker row: its six fields beside the store\'s id');
        // Epoch times (the maker rows' at and settled_at) are times, not numbers of anyone: left out of the phone scan only.
        $blob = json_encode([$seen['records'], array_map(function ($m) { return array_diff_key($m, ['at' => 1, 'settled_at' => 1]); }, $seen['makers'])], JSON_UNESCAPED_UNICODE);
        $hit = array_values(array_filter(array_merge(['@'], $names), function ($n) use ($blob) { return stripos($blob, $n) !== false; }));
        $ctx->eq([$hit, preg_match('/\d{9,}/', $blob)], [[], 0], 'I1 no @, no account name, no phone-like number in any P7 record');
    });

    $ctx->run('I2 the logs and the printed output', function () use ($ctx, &$seen, $agents, $names) {
        $logs = implode("\n", $seen['logs']);
        $ctx->eq(array_values(array_filter($agents, function ($a) use ($logs) { return $a !== 'billing@cust.example.test' && $a !== 'other@cust.example.test' && stripos($logs, $a) !== false; })), [],
            'I2 no agent\'s address in the PHP error logs, the webhook log or quote_mail.log');
        $p = implode("\n", $seen['printed']) . "\n" . $seen['plugin_log'];
        $hit = array_values(array_filter(array_merge(['@'], $names), function ($n) use ($p) { return stripos($p, $n) !== false; }));
        $ctx->eq($hit, [], 'I2 no @ and no account name in AC-ON\'s output or P7\'s PluginLog lines');
        $ctx->t((bool)preg_match('/^\[[^\]]+\] \[quote_agent_copy\] (decision|maker|maker result) not written \(\w+\)$/m', $seen['plugin_log']),
            'I2 a lost write is one fixed line: what, and the exception\'s class', $seen['plugin_log']);
    });
}];
