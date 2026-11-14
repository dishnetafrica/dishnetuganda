<?php
declare(strict_types=1);
/**
 * g05_bidal_summary.php — 5.18.105 (root docs/74 §H.1.5): group G05 of tests/test_log_mask.php section A. TEST ONLY.
 *
 * LN-05, cron/bidal_summary.php's "Morning summary sent to" line: the job run in a sandbox of its own (a copy of the judged
 * tree, its own book) with one support_leader row carrying a fictitious phone (+256700000105); stdout is captured (the
 * job's lines go to master's stdout, plugin.log). The repository's fake Evolution takes the summary.
 */
function lm_g05(QfCtx $ctx): void
{
    $ctx->run('G05 LN-05 cron/bidal_summary.php: the morning summary, stdout', function () use ($ctx) {
        $evo = qf_fake_evo();
        [$plug, $data] = lm_cli_box($ctx, ['tenant_profile' => 'uganda', 'timezone' => QF_TZ, 'evo_api_url' => $evo, 'evo_api_key' => 'lm-evo-key',
                                           'evo_instance_support' => 'lm-support', 'evo_instance_account' => 'lm-account',
                                           'evo_instance_sales' => 'lm-sales'], 'g05');
        \SqliteStore::create($data)->appendWithId('retailers.json', ['name' => 'Test Support Leader', 'role' => 'support_leader',
            'is_active' => true, 'phone' => lm_num(5), 'email' => 'leader@example.test']);
        $err = $ctx->dir('g05-log') . '/php.log';
        touch($err);
        [$rc, $out] = lm_cli_run($plug, $data, 'cron/bidal_summary.php', $err);
        $ctx->eq($rc, 0, 'LN-05 control: the job ran to its end', substr($out, -400));
        $found = lm_ln($ctx, 'LN-05', lm_lines($out), [lm_num(5)]);
        $ctx->t($found !== [] && strpos($found[0], 'Morning summary sent to Test Support Leader — pending:0 testing:0 done_today:0') !== false,
            'LN-05 the leader\'s name and the counts stay', implode(' | ', $found));
    });
}
