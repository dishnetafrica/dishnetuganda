<?php
declare(strict_types=1);
/**
 * g21_post_cashbook.php — 5.18.105 (root docs/74 §H.1.19): group G21 of tests/test_log_mask.php section A. TEST ONLY.
 *
 * LN-35, includes/post/post_cashbook.php's "Refund WA sent to" line in activity_log.json (ACT), through the served plugin
 * (tests/fixtures/staff_jobs_sandbox.php, which tests/test_staff_cash_chain.php already drives this file with) on a Uganda
 * book: an accountant records an 'out' Customer Refund whose description names client 123. uCRM is lm_ucrm_wrapper():
 * it answers POST refunds with an id (the fake uCRM §H.1.19 names) and hands clients/123 — a contact
 * carrying +256700000135, fictitious — to tests/fixtures/fake_ucrm_staff_jobs.php; the fake Evolution takes the WhatsApp.
 * The control: the refund was posted to uCRM (crm_refund_posted). The exception text at :616 is left (§H.2.2).
 *
 * The POST answers 500, not 302 — an older fault, the same on 994d660's own file (measured in this harness; 5.18.105
 * changes only LN-35's line here): after the entry is recorded, the refund posted and the WhatsApp sent (LN-35 written),
 * the handler calls TransactionIntegrityGuard::postSave() at includes/post/post_cashbook.php:638 (994d660: :637), but
 * the class is required only at :720, in a later handler, and nothing loads it on this path (no autoloader): Error
 * 'Class "TransactionIntegrityGuard" not found', caught by lib/error_handler.php. So the form is posted exactly as
 * SjSandbox::form() posts it, with Accept: application/json added — the error handler is the only code that reads Accept
 * (it then answers JSON with the message) — and the control accepts exactly that 500 and nothing else.
 */
function lm_g21(QfCtx $ctx): void
{
    $ctx->run('G21 LN-35 includes/post/post_cashbook.php: a customer refund with its WhatsApp, activity_log.json', function () use ($ctx) {
        require_once $ctx->root . '/tests/fixtures/staff_jobs_sandbox.php';
        $crm = lm_ucrm_wrapper($ctx, 'g21');
        $ctx->t($crm !== '', 'G21 control: the fake uCRM (with refunds) is up');
        if ($crm === '') return;
        qf_http('POST', $crm . '/__test/seed', ['clients' => ['123' => lm_client(123, 'Test', 'Refunded', lm_num(35))]]);
        $s = SjSandbox::start($ctx->root, ['tenant_profile' => 'uganda', 'timezone' => QF_TZ, 'crm_base_url' => $crm,
            'currency_symbol' => 'UGX', 'currency_code' => 'UGX', 'cashbook_base_currency' => 'UGX', 'cashbook_currencies' => 'UGX,USD'], 'lmg21');
        $s->staff('acct', ['name' => 'Test Accountant', 'email' => 'acct@example.test', 'role' => 'accountant']);
        $s->login('acct', 'acct@example.test', 'sj-password-1');
        preg_match('/name="_csrf" value="([^"]+)"/', $s->page('acct', 'page=dashboard&tab=cashbook'), $csrf);   // as SjSandbox::form()
        $r = $s->http('POST', "{$s->base}?page=dashboard&tab=cashbook", http_build_query(['_csrf' => html_entity_decode($csrf[1] ?? '', ENT_QUOTES),
                'action' => 'cashbook_add_entry', 'direction' => 'out', 'project' => 'dishnet', 'date' => date('Y-m-d'),
                'currency' => 'UGX', 'amount' => '150000', 'category' => 'Customer Refund', 'category_raw' => 'Customer Refund',
                'person' => '', 'description' => 'Refund for client 123 (test)', 'validation_ref' => '', 'validation_status' => 'na']),
            ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'], $s->jars['acct']);
        $lines = lm_json_entries($s->data . '/activity_log.json');
        $texts = $s->texts();
        $rows = (int)lm_pdo($s->data)->query("SELECT COUNT(*) FROM cb_ledger WHERE direction = 'out' AND amount = 150000 AND category = 'Customer Refund'")->fetchColumn();
        $s->stop();
        $ctx->eq($rows, 1, 'G21 control: the entry was recorded (cb_ledger: out, 150000, Customer Refund)');
        $ctx->t($r[0] === 500 && is_array($r[2]) && ($r[2]['message'] ?? '') === 'Uncaught: Class "TransactionIntegrityGuard" not found'
            && ($r[2]['file'] ?? '') === 'post_cashbook.php',
            'G21 control: the POST then answered 500 with exactly the older fault (TransactionIntegrityGuard not loaded, post_cashbook.php; 994d660\'s too)',
            $r[0] . ' ' . substr($r[1], 0, 300));
        $ctx->eq(count(array_filter($lines, function ($l) { return strpos($l, '"action":"crm_refund_posted"') !== false; })), 1,
            'LN-35 control: the refund was posted to uCRM (crm_refund_posted)');
        $ctx->eq(count(array_filter($texts, function ($t) { return strpos((string)$t['text'], 'Cash Refund') !== false; })), 1,
            'LN-35 control: the customer\'s WhatsApp went (the fake Evolution took it)');
        $f = lm_ln($ctx, 'LN-35', $lines, [lm_num(35)]);
        $ctx->t($f !== [] && strpos($f[0], '"title":"Refund WA sent to Test Refunded: $150,000.00"') !== false,
            'LN-35 the name and the amount stay', implode(' | ', $f));
    });
}
