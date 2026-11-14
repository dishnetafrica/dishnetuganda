<?php
declare(strict_types=1);
/**
 * g14_webhook_payment_delete.php — 5.18.105 (root docs/74 §H.1.11): group G14 of tests/test_log_mask.php section A.
 * TEST ONLY.
 *
 * LN-23, webhook.php's "Alert sent to accountant" line: payment.delete for payment 5123, which the fake uCRM no longer has
 * (a definite 404), with a collection record naming it — so the collection is voided and the first accountant with a
 * phone (+256700000123, fictitious) is alerted. Through the G12 harness, on Uganda.
 */
function lm_g14(QfCtx $ctx): void
{
    $ctx->run('G14 LN-23 webhook.php: a payment deleted in uCRM, the accountant alerted, webhook_log.json', function () use ($ctx) {
        $s = lm_wh_sandbox($ctx, 'lmg14', ['clients' => []]);
        $s->staff('acct', ['name' => 'Test Accountant', 'email' => 'accounts@example.test', 'role' => 'accountant', 'phone' => lm_num(23)]);
        $agent = $s->staff('agent', ['name' => 'Test Collector', 'email' => 'collector@example.test', 'role' => 'collection']);
        $s->store()->appendWithId('payment_collections.json', ['crm_payment_id' => 5123, 'retailer_id' => $agent, 'amount' => 50000,
            'currency' => 'UGX', 'status' => 'pending', 'customer_name' => 'Test Payer', 'created_at' => date('Y-m-d H:i:s')]);
        $r = lm_wh_fire($s, 'payment.delete', 'payment', 5123, 'lm-pd5123');
        $lines = lm_wh_lines($s);
        $texts = $s->texts();
        $s->stop();
        $ctx->t($r[0] === 200 && strpos((string)$r[1], 'collection voided') !== false, 'G14 control: the deletion was verified and the collection voided', (string)$r[1]);
        $ctx->eq(count(array_filter($texts, function ($t) { return strpos((string)$t['text'], 'CRM Payment Deleted') !== false; })), 1,
            'LN-23 control: the alert went to the accountant (the fake Evolution took it)');
        $f = lm_ln($ctx, 'LN-23', $lines, [lm_num(23)]);
        $ctx->t($f !== [] && strpos($f[0], '"message":"Alert sent to accountant"') !== false, 'LN-23 the line reads "Alert sent to accountant", and nothing after it', implode(' | ', $f));
    });
}
