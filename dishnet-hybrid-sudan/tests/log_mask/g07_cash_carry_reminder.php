<?php
declare(strict_types=1);
/**
 * g07_cash_carry_reminder.php — 5.18.105 (root docs/74 §H.1.7): group G07 of tests/test_log_mask.php section A. TEST ONLY.
 *
 * LN-07, cron/cash_carry_reminder.php's "Reminded" line: one active collector (+256700000107, fictitious) holding USD 250
 * of customer collections, over the default carry limit of 100 (the snapshot is rebuilt from payment_collections by the
 * job itself); stdout captured. Extra: a second run the same day prints no "Reminded" line — the state dedup
 * ($state['reminded']) is untouched.
 */
function lm_g07(QfCtx $ctx): void
{
    $ctx->run('G07 LN-07 cron/cash_carry_reminder.php: an agent over the carry limit, stdout', function () use ($ctx) {
        $evo = qf_fake_evo();
        [$plug, $data] = lm_cli_box($ctx, ['tenant_profile' => 'uganda', 'timezone' => QF_TZ, 'evo_api_url' => $evo, 'evo_api_key' => 'lm-evo-key',
                                           'evo_instance_support' => 'lm-support', 'evo_instance_account' => 'lm-account',
                                           'evo_instance_sales' => 'lm-sales'], 'g07');
        $store = \SqliteStore::create($data);
        $id = (int)($store->appendWithId('retailers.json', ['name' => 'Test Collector', 'role' => 'collection', 'is_active' => true,
                                                             'phone' => lm_num(7), 'email' => 'collector@example.test'])['id'] ?? 0);
        $store->appendWithId('payment_collections.json', ['retailer_id' => $id, 'amount' => 250, 'currency' => 'USD', 'status' => 'pending',
                                                          'customer_name' => 'Test Payer', 'created_at' => date('Y-m-d H:i:s')]);
        $err = $ctx->dir('g07-log') . '/php.log';
        touch($err);
        [, $out] = lm_cli_run($plug, $data, 'cron/cash_carry_reminder.php', $err);
        $found = lm_ln($ctx, 'LN-07', lm_lines($out), [lm_num(7)]);
        $ctx->t($found !== [] && strpos($found[0], '[cash_carry] Reminded Test Collector: $250 exposure') !== false,
            'LN-07 the agent\'s name and the exposure stay', implode(' | ', $found) . ' ' . substr($out, -300));
        [, $out2] = lm_cli_run($plug, $data, 'cron/cash_carry_reminder.php', $err);
        $ctx->t(strpos($out2, 'Reminded ') === false, 'LN-07 a second run the same day prints no "Reminded" line (the state dedup is untouched)', substr($out2, -300));
    });
}
