<?php
declare(strict_types=1);
/**
 * quote_agent_copy F — the ladder and the tool (root docs/73 §H.5.10). Stage B4 builds QuoteAgentCopy's side of the
 * interface (ruling R13's row P; IP-6): F1's precondition half — preconditions() on each of mailSetting()'s four `via`
 * values, in ruling R13's shape, reading a file and never the book or uCRM — and accountCounts(). The tool's own cases
 * (F1T: `on agent-cc`'s refusals; F2 the optional row; F3 skip refused; F4 the read-back) are stage B9's, with
 * lib/SalesFollowupLadder.php and tools/sales_followup.php (5.18.104 (root docs/73 §H.5.12)); F5 reads, with no
 * assertion of its own, tests/test_sales_followup_tool.php's TL7, TL14, TL25, TL28, TL08 and TL35. TEST ONLY.
 */
require_once dirname(__DIR__) . '/sales_followup_tool/_lib.php';   // 5.18.104 (root docs/73 §H.5.12): the tool's helpers
$QAC_SECTIONS['F'] = ['title' => 'Row 1b\'s own precondition and the account counts (§H.5.7, §H.5.10; ruling R13 row P)', 'run' => function (QfCtx $ctx) {
    qac_load($ctx);
    $tree = $ctx->tree();

    $ctx->run('F1 preconditions(): ok, code and one line for each of mailSetting()\'s four via values', function () use ($ctx, $tree) {
        $d = $ctx->dir('f1');
        file_put_contents($d . '/email_settings.json', json_encode(['smtp_host' => '127.0.0.1', 'smtp_from' => QAC_SENDER, 'use_ucrm_email' => false]));
        $plugin = QuoteAgentCopy::preconditions($d);
        $ctx->eq($plugin, ['ok' => true, 'code' => null, 'lines' => ['plugin mail: plugin SMTP']], 'F1 positive control: plugin SMTP → ok, no code, one line');
        $ctx->eq(QuoteAgentCopy::mailSetting($d), ['configured' => true, 'via' => 'plugin', 'sender' => QAC_SENDER], 'F1 mailSetting keeps its own shape (configured, via, sender)');
        $d = $ctx->dir('f1');
        file_put_contents($d . '/email_settings.json', json_encode(['use_ucrm_email' => true]));
        $ctx->eq(QuoteAgentCopy::preconditions($d), ['ok' => true, 'code' => null, 'lines' => ["plugin mail: uCRM's mailer, not asked"]], 'F1 use_ucrm_email on, no smtp_host → ok, "uCRM\'s mailer, not asked"');
        $d = $ctx->dir('f1');
        file_put_contents($d . '/email_settings.json', json_encode(['use_ucrm_email' => false, 'smtp_host' => ' ']));
        $ctx->eq(QuoteAgentCopy::preconditions($d), ['ok' => false, 'code' => 'mail_not_configured', 'lines' => ['plugin mail is not configured (Settings → System → Email)']],
            'F1 neither → not ok, mail_not_configured');
        $d = $ctx->dir('f1');
        $ctx->eq(QuoteAgentCopy::preconditions($d)['code'], 'mail_not_configured', 'F1 no file at all → mail_not_configured');
        foreach ([$plugin] as $p) $ctx->eq(array_keys($p), ['ok', 'code', 'lines'], 'F1 no key beside ruling R13\'s three');
        // 'unreadable': a file that exists but this process cannot read. Root reads every file, so the case runs as the
        // unprivileged uid 65534 (setpriv) on a copy of the one class, which needs nothing else.
        // Under the system's own temporary folder: every folder above it must be traversable by that uid, and the run's
        // own folders are 0700 (a weakened copy's run sits two of them deep).
        $w = (is_dir('/tmp') && is_writable('/tmp') ? '/tmp' : dirname($ctx->tmp)) . '/qac-unreadable-' . getmypid() . '-' . bin2hex(random_bytes(3));
        @mkdir($w . '/data', 0755, true);
        chmod($w, 0755); chmod($w . '/data', 0755);
        copy($tree . '/lib/QuoteAgentCopy.php', $w . '/QuoteAgentCopy.php');
        chmod($w . '/QuoteAgentCopy.php', 0644);
        file_put_contents($w . '/data/email_settings.json', json_encode(['smtp_host' => '127.0.0.1']));
        chmod($w . '/data/email_settings.json', 0600);
        $o = [];
        exec('setpriv --reuid=65534 --regid=65534 --clear-groups php -r ' . escapeshellarg('require ' . var_export($w . '/QuoteAgentCopy.php', true)
            . '; echo json_encode([QuoteAgentCopy::preconditions(' . var_export($w . '/data', true) . '), QuoteAgentCopy::mailSetting(' . var_export($w . '/data', true) . ')]);') . ' 2>&1', $o, $rc);
        exec('rm -rf ' . escapeshellarg($w));
        $got = json_decode(implode("\n", $o), true);
        if ($rc !== 0 || !is_array($got)) {
            echo "  note F1 limitation: the unreadable case could not run as an unprivileged user here (" . substr(implode(' ', $o), 0, 120) . ") — a stated limitation, not a pass\n";
            return;
        }
        $ctx->eq($got, [['ok' => false, 'code' => 'mail_not_configured', 'lines' => ['plugin mail is not configured (email_settings.json cannot be read)']],
                        ['configured' => false, 'via' => 'unreadable', 'sender' => 'not read']], 'F1 a file that exists but cannot be read → unreadable, mail_not_configured');
    });

    $ctx->run('F1b preconditions() reads a file only: no connection, no uCRM request', function () use ($ctx) {
        [$url, $log] = qac_counter($ctx);
        $d = $ctx->dir('f1b') . '/data';
        @mkdir($d, 0777, true);
        file_put_contents($d . '/email_settings.json', json_encode(['use_ucrm_email' => true, 'smtp_host' => '127.0.0.1']));
        file_put_contents(dirname($d) . '/ucrm.json', json_encode(['ucrmLocalUrl' => $url . '/', 'pluginAppKey' => 'qac-test-key']));
        $before = qac_counted($log);
        $p = QuoteAgentCopy::preconditions($d);
        $m = QuoteAgentCopy::mailSetting($d);
        $ctx->eq([$p['ok'], $m['via'], qac_counted($log) - $before, is_file($d . '/plugin.sqlite3')], [true, 'ucrm_not_asked', 0, false],
            'F1b uCRM\'s mailer read from the file, uCRM never asked, no book made');
    });

    $ctx->run('F6 accountCounts(): counts only, the company\'s mailbox counted for PQ4', function () use ($ctx) {
        $c = QuoteAgentCopy::accountCounts(qac_accounts(), ['reply_to' => QAC_REPLY, 'sender' => QAC_SENDER]);
        $ctx->eq($c, ['active_usable' => 9, 'active_unusable' => 1, 'administrators' => 1, 'company_mailbox' => 2],
            'F6 nine active with a usable address, one without, one administrator, two on the company\'s mailbox');
        $ctx->eq(QuoteAgentCopy::accountCounts([['is_active' => false, 'email' => 'x@agents.example.test', 'is_admin' => true]], ['sender' => 'not read']),
            ['active_usable' => 0, 'active_unusable' => 0, 'administrators' => 0, 'company_mailbox' => 0], 'F6 an inactive account counts nowhere; "not read" matches nobody');
    });

    // ── 5.18.104 (root docs/73 §H.5.12): F1T-F4, row 1b through Part F's tool (stage B9) ────────────────────────────
    require_once $tree . '/lib/SalesFollowupLadder.php';
    require_once $tree . '/lib/PluginConfig.php';
    $mail = function (array $b, array $s): void { file_put_contents($b['data'] . '/email_settings.json', json_encode($s)); };
    $smtp = ['smtp_host' => '127.0.0.1', 'smtp_from' => QAC_SENDER, 'use_ucrm_email' => false];
    $ran  = ['sf_report_ran_at' => '2026-11-01'];
    $p7   = ['quote_agent_cc_enabled' => '1', 'quote_agent_cc_since' => '2026-11-01 08:00:00'];
    $keys = function (array $b): array { return array_values(array_intersect(array_keys(sft_kyc($b)), ['quote_agent_cc_enabled', 'quote_agent_cc_since'])); };

    $ctx->run('F1T on agent-cc through the tool: before step 1 refused; no plugin mail refused; plugin SMTP or uCRM\'s mailer saved, uCRM never asked', function () use ($ctx, $tree, $mail, $smtp, $ran, $keys) {
        $b = sft_book($ctx, [], 'f1t'); $mail($b, $smtp);
        [$rc, $out] = sft_run($ctx, $b, ['on', 'agent-cc']);
        $ctx->eq([$rc, strpos($out, 'REFUSED order:report') !== false, $keys($b)], [2, true, []], 'F1T before step 1 has run: refused, nothing saved', $out);
        $b = sft_book($ctx, $ran, 'f1t'); $mail($b, ['use_ucrm_email' => false, 'smtp_host' => ' ']);
        [$rc, $out] = sft_run($ctx, $b, ['on', 'agent-cc']);
        $ctx->eq([$rc, strpos($out, 'REFUSED mail_not_configured') !== false, $keys($b)], [2, true, []], 'F1T neither smtp_host nor use_ucrm_email: refused, nothing saved', $out);
        $b = sft_book($ctx, $ran, 'f1t'); $mail($b, $smtp);
        [$rc, $out] = sft_run($ctx, $b, ['on', 'agent-cc']);
        $ctx->eq([$rc, $keys($b), (string)(sft_kyc($b)['quote_agent_cc_enabled'] ?? ''), QuoteAgentCopy::state($tree, $b['data'])['on']],
                 [0, ['quote_agent_cc_enabled', 'quote_agent_cc_since'], '1', true], 'F1T positive control: step 1 run, every later step off, plugin SMTP: saved, the copy reads on', $out);
        [$url, $log] = qac_counter($ctx);
        file_put_contents($tree . '/ucrm.json', json_encode(['ucrmLocalUrl' => $url . '/', 'pluginAppKey' => 'qac-test-key']));
        try {
            $b = sft_book($ctx, $ran, 'f1t'); $mail($b, ['use_ucrm_email' => true]);
            $before = qac_counted($log);
            [$rc, $out] = sft_run($ctx, $b, ['on', 'agent-cc']);
            $ctx->eq([$rc, strpos($out, "plugin mail: uCRM's mailer, not asked") !== false, $keys($b), qac_counted($log) - $before],
                     [0, true, ['quote_agent_cc_enabled', 'quote_agent_cc_since'], 0], 'F1T use_ucrm_email on, no smtp_host: saved, "uCRM\'s mailer, not asked", uCRM receives no request', $out);
        } finally { @unlink($tree . '/ucrm.json'); }
    });

    $ctx->run('F2 the optional row: never a Need, never required; at FAIL it holds later steps; off of any other step leaves it; --check', function () use ($ctx, $mail, $smtp, $ran, $p7, $keys) {
        $L = 'SalesFollowupLadder';
        $now = time();
        $c = sft_on_upto('report', $now);
        $chk = function (string $ac) { return sft_checker(['AC-ON' => $ac, 'L-ON' => 'PASS', 'VA-ON1' => 'PASS']); };
        $ctx->eq([$L::admit($c, 'ack', null, $now, $chk('PASS'))['ok'], $L::admit($c + $p7, 'ack', null, $now, $chk('PASS'))['ok'],
                  $L::admit($c + $p7, 'ack', null, $now, $chk('NOT_YET no_traffic'))['ok']], [true, true, true],
                 'F2 positive control: on ack admitted with agent-cc off, and with it on at PASS or NOT_YET');
        $ctx->eq($L::admit($c + $p7, 'ack', null, $now, $chk('FAIL decision_missing'))['code'], 'earlier_fail:agent-cc', 'F2 agent-cc on at FAIL: a later on refused');
        $ctx->eq($L::admit($c, 'ack', null, $now, $chk('FAIL decision_missing'))['ok'], true, 'F2 after off agent-cc (its keys absent): admitted again');
        $named = [];
        foreach ($L::steps() as $s) foreach ($L::row($s)['needs'] as $n) if ($n['step'] === 'agent-cc') $named[] = $s;
        $ctx->eq($named, [], 'F2 agent-cc is no row\'s Need');
        $hit = [];
        foreach (array_merge(array_diff($L::steps(), ['agent-cc']), ['sending']) as $s) {
            foreach ($L::offPlan($s) as [$kind, $name]) {
                $ks = $kind === 'ledger' ? $L::LEDGER_KEYS : $L::rowKeys($name);
                if (array_intersect($ks, ['quote_agent_cc_enabled', 'quote_agent_cc_since']) !== []) $hit[] = $s;
            }
        }
        $ctx->eq($hit, [], 'F2 off of every other step, off sending included, clears neither of P7\'s keys');
        $b = sft_book($ctx, sft_on_upto('tracking', $now) + $p7, 'f2'); $mail($b, $smtp);
        $out = '';
        foreach ([['off', 'tracking'], ['off', 'ack'], ['off', 'sending']] as $a) $out .= sft_run($ctx, $b, $a)[1];
        $ctx->eq($keys($b), ['quote_agent_cc_enabled', 'quote_agent_cc_since'], 'F2 off tracking, off ack and off sending through the tool leave P7\'s keys', $out);
        $b = sft_book($ctx, $ran, 'f2'); $mail($b, $smtp);
        [$rc, $out] = sft_run($ctx, $b, ['--check', 'agent-cc']);
        $ctx->eq([$rc, trim($out)], [0, 'agent-cc: OFF — not judged'], 'F2 --check agent-cc while off: OFF — not judged', $out);
        $b = sft_book($ctx, $ran + $p7, 'f2'); $mail($b, $smtp);
        [$rc, $out] = sft_run($ctx, $b, ['--check']);
        $ctx->eq([count(array_filter(explode("\n", trim($out)))), strpos($out, 'agent-cc: ') === 0], [1, true], 'F2 --check, steps 1 and 1b alone on: one line, AC-ON judged', $out);
        $b = sft_book($ctx, sft_on_upto('ack', $now) + $p7, 'f2'); $mail($b, $smtp);
        [$rc, $out] = sft_run($ctx, $b, ['--check']);
        $ctx->eq([count(array_filter(explode("\n", trim($out)))), strpos($out, 'ack: ') === 0], [1, true], 'F2 --check with step 2 on too: one line, step 2\'s, AC-ON not judged', $out);
    });

    $ctx->run('F3 skip agent-cc refused, nothing saved', function () use ($ctx, $ran) {
        $b = sft_book($ctx, $ran, 'f3');
        [$rc, $out] = sft_run($ctx, $b, ['skip', 'agent-cc', '--reason', 'not wanted (test)']);
        $ctx->eq([$rc, strpos($out, 'REFUSED optional') !== false, array_key_exists('sf_skip_agent-cc', sft_kyc($b))], [2, true, false], 'F3 skip agent-cc: refused, nothing saved', $out);
    });

    $ctx->run('F4 the read-back: off agent-cc, off all and the rollback leave the copy off; a key held elsewhere is reported; a write that does not read back is undone', function () use ($ctx, $tree, $mail, $smtp, $ran, $p7, $keys) {
        $now = time();
        foreach ([['off', 'agent-cc'], ['off', 'all'], ['off', 'all', '--rollback']] as $a) {
            $b = sft_book($ctx, $ran + $p7 + ['quote_agent_cc_admins' => '0'], 'f4'); $mail($b, $smtp);
            $on = QuoteAgentCopy::state($tree, $b['data'])['on'];
            [$rc, $out] = sft_run($ctx, $b, $a);
            $ctx->eq([$on, $rc, $keys($b), QuoteAgentCopy::state($tree, $b['data'])['on'], (string)(sft_kyc($b)['quote_agent_cc_admins'] ?? '')], [true, 0, [], false, '0'],
                     'F4 ' . implode(' ', $a) . ': P7\'s keys unset (never 0), the copy reads off, its setting kept', $out);
        }
        $b = sft_book($ctx, $ran + $p7, 'f4'); $mail($b, $smtp);
        file_put_contents($b['data'] . '/config.json', json_encode(['quote_agent_cc_enabled' => '1']));
        [$rc, $out] = sft_run($ctx, $b, ['off', 'agent-cc']);
        $ctx->eq([$rc, strpos($out, 'FAIL key_outside_overrides quote_agent_cc_enabled — held by <data>/config.json') !== false,
                  strpos($out, 'the agent copy still reads on') !== false, preg_match('/held by[^\n]*1/', $out)], [3, true, true, 0],
                 'F4 quote_agent_cc_enabled=1 planted in <data>/config.json: off agent-cc prints FAIL key_outside_overrides naming the file, not the value; exit 3', $out);
        [$rc, $out] = sft_run($ctx, $b, ['off', 'all', '--rollback']);
        $ctx->eq([$rc, strpos($out, 'FAIL key_outside_overrides') !== false], [3, true], 'F4 the same planted key: the rollback\'s final assertion fails, exit 3', $out);
        // A copy whose gate reads off whatever is stored: the write cannot be read back.
        require_once dirname(__DIR__) . '/fixtures/staff_jobs_sandbox.php';   // sj_weakened_copy()
        [$copy, $n] = sj_weakened_copy($ctx->root, 'lib/QuoteAgentCopy.php',
            "if (!StaffJobsGate::applies(\$cfg, \$dataDir) || (string)(\$cfg[self::KEY] ?? '') !== '1') return \$off;", 'return $off;');
        try {
            $ctx->eq($n, 1, 'F4 control: the stub tree whose gate reads off was made');
            $b = sft_book($ctx, $ran, 'f4'); $mail($b, $smtp);
            $e = array_filter(getenv(), function ($k) { return stripos((string)$k, 'proxy') === false; }, ARRAY_FILTER_USE_KEY);
            $pr = proc_open(['php', $copy . '/tools/sales_followup.php', 'on', 'agent-cc'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['DN_DATA_DIR' => $b['data']] + $e);
            fclose($pipes[0]); $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
            $rc = proc_close($pr);
            $ctx->eq([$rc, strpos($out, 'FAIL gate_reads_off') !== false, $keys($b)], [2, true, []], 'F4 an on whose write does not read back: FAIL gate_reads_off, both keys absent', $out);
        } finally { exec('rm -rf ' . escapeshellarg($copy)); }
        $plan = SalesFollowupLadder::offPlan('all');
        $ctx->eq([array_slice($plan, -2), array_values(array_intersect(SalesFollowupLadder::lastKeys(), ['quote_agent_cc_enabled', 'quote_agent_cc_since']))],
                 [[['ledger', 'sending'], ['last', 'records']], ['quote_agent_cc_enabled', 'quote_agent_cc_since']], 'F4 off all clears P7\'s keys after the ledger, with the record keys (ruling R11)');
    });
}];
