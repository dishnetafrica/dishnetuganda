<?php
declare(strict_types=1);
/**
 * g19_lead_alerts.php — 5.18.105 (root docs/74 §H.1.16): group G19 of tests/test_log_mask.php section A. TEST ONLY.
 *
 * LN-30, LN-31 and LN-32, cron_lead_alerts.php's three task lines (master's stdout, plugin.log): one active agent, and
 * three assigned AI leads named by their numbers (+256700000130 … 132, fictitious), timed so that task A (not yet told),
 * task B (told, 50 minutes: inside the 45-minute warning) and task C (told and warned, 70 minutes: past the 60-minute
 * deadline, the supervisor named by lead_supervisor_phone) each fire once — the stamps written in the job's zone
 * (lm_ai_lead(), g18_leads.php); written in UTC, every lead reads older by the zone's offset, task B never fires and task
 * C fires twice. One weakened copy per line (mutants_A.php).
 */
function lm_g19(QfCtx $ctx): void
{
    $ctx->run('G19 LN-30 LN-31 LN-32 cron_lead_alerts.php: tasks A, B and C, stdout', function () use ($ctx) {
        $evo = qf_fake_evo();
        [$plug, $data] = lm_cli_box($ctx, ['tenant_profile' => 'uganda', 'timezone' => QF_TZ, 'evo_api_url' => $evo, 'evo_api_key' => 'lm-evo-key',
                                           'evo_instance_support' => 'lm-support', 'evo_instance_account' => 'lm-account',
                                           'evo_instance_sales' => 'lm-sales', 'lead_supervisor_phone' => lm_num(82)], 'g19');
        $store = \SqliteStore::create($data);
        $a = (int)($store->appendWithId('retailers.json', ['name' => 'Test Agent', 'role' => 'sales', 'is_active' => true,
                                                            'phone' => lm_num(80), 'email' => 'agent@example.test'])['id'] ?? 0);
        $ids = [];
        foreach ([30 => [2 / 60, []], 31 => [50 / 60, ['wa_assigned_notified' => 1]],
                  32 => [70 / 60, ['wa_assigned_notified' => 1, 'wa_45min_sent' => 1]]] as $xx => [$hours, $flags]) {
            $ids[$xx] = (int)($store->appendWithId('leads.json', lm_ai_lead(lm_forms(lm_num($xx))[1], $a, 'Test Agent', $hours, $flags))['id'] ?? 0);
        }
        $err = $ctx->dir('g19-log') . '/php.log';
        touch($err);
        [, $out] = lm_cli_run($plug, $data, 'cron_lead_alerts.php', $err);
        $lines = lm_lines($out);
        $nums = [lm_num(30), lm_num(31), lm_num(32)];
        $ctx->t(strpos($out, 'Done — A:1 immediate, B:1 warnings, C:1 escalations') !== false, 'G19 control: each task fired once', substr($out, -400));
        $f = lm_ln($ctx, 'LN-30', $lines, $nums);
        $ctx->eq($f[0] ?? '', 'TASK A: Notified Test Agent → lead #' . $ids[30], 'LN-30 "TASK A: Notified <agent> → lead #<id>"');
        $f = lm_ln($ctx, 'LN-31', $lines, $nums);
        $ctx->eq($f[0] ?? '', 'TASK B: 45-min warning to Test Agent → lead #' . $ids[31], 'LN-31 "TASK B: 45-min warning to <agent> → lead #<id>"');
        $f = lm_ln($ctx, 'LN-32', $lines, $nums);
        $ctx->eq($f[0] ?? '', 'TASK C: Escalated lead #' . $ids[32] . ' (Test Agent) to supervisor', 'LN-32 "TASK C: Escalated lead #<id> (<agent>) to supervisor"');
    });
}
