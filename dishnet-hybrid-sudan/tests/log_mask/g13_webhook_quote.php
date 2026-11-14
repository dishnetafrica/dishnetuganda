<?php
declare(strict_types=1);
/**
 * g13_webhook_quote.php — 5.18.105 (root docs/74 §H.1.11): group G13 of tests/test_log_mask.php section A. TEST ONLY.
 *
 * LN-20, webhook.php's "preparing PDF+caption" line: quote.add on Uganda for a quotation typed in uCRM, as
 * tests/test_notify_quote_once.php fires it (the client filed under the quoting organization 1, which the fake uCRM lists
 * only when seeded), through the G12 harness. Quote Q-3420 for client 220 ("Test Quoted", +256700000120, fictitious).
 */
function lm_g13(QfCtx $ctx): void
{
    $ctx->run('G13 LN-20 webhook.php: quote.add on Uganda, webhook_log.json', function () use ($ctx) {
        $s = lm_wh_sandbox($ctx, 'lmg13', [
            'clients' => ['220' => lm_client(220, 'Test', 'Quoted', lm_num(20), ['organizationId' => 1])],
            'organizations' => [['id' => 1, 'name' => 'TEST Company One', 'selected' => true, 'currencyCode' => 'UGX']],
            'quotes' => ['3420' => lm_quote(3420, 220)],
        ]);
        $r = lm_wh_fire($s, 'quote.add', 'quote', 3420, 'lm-q3420');
        $lines = lm_wh_lines($s);
        $s->stop();
        $ctx->eq($r[0], 200, 'G13 control: quote.add is answered 200');
        $f = lm_ln($ctx, 'LN-20', $lines, [lm_num(20)]);
        $ctx->t($f !== [] && strpos($f[0], 'Quote #Q-3420 → preparing PDF+caption for Test Quoted"') !== false,
            'LN-20 "Quote #Q-3420 → preparing PDF+caption for <name>"', implode(' | ', $f));
    });
}
