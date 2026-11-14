<?php
declare(strict_types=1);
/**
 * g04_wa_auto_reply.php — 5.18.105 (root docs/74 §H.1.4): group G04 of tests/test_log_mask.php section A. TEST ONLY.
 *
 * LN-04, WaAutoReplyService::createCrmJobForBidal()'s line. A direct call by reflection (the method is private) in a
 * process of its own (tests/log_mask/side_direct.php). The service builds its own uCRM client from the configuration, so
 * the fake uCRM is the repository's tests/fixtures/fake_ucrm_staff_jobs.php, told to give the next job id 4711; the
 * support leader is a retailers.json row with a fictitious phone (+256700000154); the customer's number is
 * +256700000104. Extra: "CRM Job #4711 created and assigned to" is present.
 */

/** The repository's fake uCRM (staff and jobs) on a free port with a state file of its own; its base URL, or ''. */
function lm_fake_ucrm_jobs(QfCtx $ctx, string $tag): string
{
    $stateFile = $ctx->dir($tag . '-ucrm') . '/state.json';
    [$port] = qf_serve(escapeshellarg($ctx->root . '/tests/fixtures/fake_ucrm_staff_jobs.php'), function (int $p): bool {
        return strpos(qf_http('GET', "http://127.0.0.1:{$p}/__test/state", null, [], 2)[2], 'FAKE-UCRM-STAFF-JOBS') === 0;
    }, 14400, ['FAKE_UCRM_STATE' => $stateFile]);
    return $port === 0 ? '' : "http://127.0.0.1:{$port}";
}

function lm_g04(QfCtx $ctx): void
{
    $ctx->run('G04 LN-04 lib/WaAutoReplyService.php: a CRM job for the support leader, PHP\'s log', function () use ($ctx) {
        $crm = lm_fake_ucrm_jobs($ctx, 'g04');
        $evo = qf_fake_evo();
        $ctx->t($crm !== '' && $evo !== '', 'LN-04 control: the fake uCRM and the fake Evolution are up');
        if ($crm === '' || $evo === '') return;
        qf_http('POST', $crm . '/__test/seed', ['next_job' => 4711]);
        $cfg = ['tenant_profile' => 'south-sudan', 'timezone' => LM_TZ_SS, 'crm_base_url' => $crm, 'crm_auth_token' => 'LM-TEST-KEY',
                'evo_api_url' => $evo, 'evo_api_key' => 'lm-evo-key', 'evo_instance_support' => 'lm-support', 'dry_run_mode' => false];
        [$tree, $data] = lm_cli_box($ctx, $cfg, 'g04');
        \SqliteStore::create($data)->appendWithId('retailers.json', ['name' => 'Test Bidal Leader', 'role' => 'support_leader', 'is_active' => true,
                                                      'ucrm_user_id' => 1099, 'phone' => lm_num(54), 'email' => 'leader@example.test']);
        $err = $ctx->dir('g04-log') . '/php.log';
        touch($err);
        $spec = $ctx->dir('g04-spec') . '/spec.json';
        file_put_contents($spec, json_encode(['root' => $tree, 'data' => $data, 'error_log' => $err, 'do' => 'bidal',
            'phone' => lm_forms(lm_num(4))[1], 'config' => $cfg + ['data_dir' => $data]]));
        [$res, $raw] = lm_side($spec);
        $ctx->eq($res['ret'] ?? null, true, 'LN-04 control: the job was created and the method returned true', $raw);
        $posted = array_values(array_filter((array)(qf_http('GET', $crm . '/__test/dump')[1]['requests'] ?? []), function ($r) {
            return ($r['method'] ?? '') === 'POST' && ($r['path'] ?? '') === '/scheduling/jobs';
        }));
        $ctx->eq(count($posted), 1, 'LN-04 control: one job posted to the fake uCRM');
        lm_ln($ctx, 'LN-04', lm_lines((string)@file_get_contents($err)), [lm_num(4), lm_num(54)]);
    });
}
