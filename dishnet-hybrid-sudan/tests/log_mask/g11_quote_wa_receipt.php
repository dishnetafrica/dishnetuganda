<?php
declare(strict_types=1);
/**
 * g11_quote_wa_receipt.php — 5.18.105 (root docs/74 §H.1.10): group G11 of tests/test_log_mask.php section A. TEST ONLY.
 *
 * LN-15, cron_quote_wa.php's "Receipt PDF SENT" line in quote_wa_log.json, through the same harness as G10 (the served
 * plugin, the real cron run in the sandbox, the fake Evolution), with one receipt_pdf_queue.json entry carrying
 * +256700000115 (fictitious), queued two minutes ago. The receipt's PDF is uCRM's /api/v2.1/payments/{id}/pdf, which
 * tests/fixtures/fake_ucrm_staff_jobs.php does not serve: the sandbox's uCRM is lm_ucrm_wrapper(), a router generated in
 * the run's own folder that answers that path and hands every other request to that fake unchanged.
 */
function lm_g11(QfCtx $ctx): void
{
    $ctx->run('G11 LN-15 cron_quote_wa.php: the receipt PDF queue, quote_wa_log.json', function () use ($ctx) {
        require_once $ctx->root . '/tests/fixtures/staff_jobs_sandbox.php';
        $crm = lm_ucrm_wrapper($ctx, 'g11');
        $ctx->t($crm !== '', 'G11 control: the fake uCRM (with the receipt PDF) is up');
        if ($crm === '') return;
        $s = SjSandbox::start($ctx->root, ['tenant_profile' => 'uganda', 'timezone' => QF_TZ, 'crm_base_url' => $crm], 'lmg11');
        file_put_contents($s->plug . '/ucrm.json', json_encode(['pluginDataDir' => $s->data, 'pluginPublicUrl' => $s->base]));
        $s->store()->save('receipt_pdf_queue.json', [['payment_id' => 5115, 'phone' => lm_num(15), 'customer_name' => 'Test Receipt Customer',
                                                     'amount' => 150000, 'queued_at' => time() - 120, 'sent' => false]]);
        [$rc, $out] = $s->run('cron_quote_wa.php');
        $lines = lm_qwa_lines($s->data);
        $media = (array)($s->http('GET', "{$s->evo}/__test/state")[2]['media_calls'] ?? []);
        $s->stop();
        $ctx->eq(count(array_filter($media, function ($m) { return strpos((string)($m['caption'] ?? ''), 'Receipt #PAY-5115') === 0; })), 1,
            'LN-15 control: the receipt PDF went (the fake Evolution took it)', substr($out, 0, 300));
        $f = lm_ln($ctx, 'LN-15', $lines, [lm_num(15)]);
        $ctx->t($f !== [] && preg_match('/Receipt PDF SENT: PAY-5115 → Test Receipt Customer — \d+ KB"/u', $f[0]) === 1,
            'LN-15 "Receipt PDF SENT: PAY-5115 → <name> — <n> KB"', implode(' | ', $f));
    });
}
