<?php
declare(strict_types=1);
/**
 * g02_staff_ssp_report.php — 5.18.105 (root docs/74 §H.1.2): group G02 of tests/test_log_mask.php section A, and the
 * run section B repeats on 994d660's tree. TEST ONLY.
 *
 * LN-02, cron/staff_ssp_report.php's "Sent to" line, on a South Sudan sandbox where SSP is bookable (cashbook_currencies
 * USD,SSP): one active field staff member with a fictitious phone (+256700000102) and an SSP staff_ledger 'in' row, the
 * repository's fake Evolution accepting the send, the job run under php -d error_log=FILE. The control: on a UGX,USD book
 * the job says "SSP is not bookable on this install" and writes no "Sent to" line.
 */

/** The South Sudan configuration of the job's book; $book: cashbook_currencies. */
function lm_ssp_cfg(string $evo, string $book): array
{
    return ['tenant_profile' => 'south-sudan', 'timezone' => LM_TZ_SS, 'cashbook_currencies' => $book,
            'evo_api_url' => $evo, 'evo_api_key' => 'lm-evo-key', 'evo_instance_support' => 'lm-support',
            'evo_instance_account' => 'lm-account', 'evo_instance_sales' => 'lm-sales'];
}

/**
 * One run of cron/staff_ssp_report.php on a box of $src (the judged tree by default): [stdout, PHP's log lines, the fake
 * Evolution's text sends, the Message Log rows]. The fake Evolution is reset first.
 */
function lm_ssp_run(QfCtx $ctx, string $tag, string $book, string $src = ''): array
{
    $evo = qf_fake_evo();
    if ($evo === '') throw new \RuntimeException('the fake Evolution did not start');
    [$plug, $data] = lm_cli_box($ctx, lm_ssp_cfg($evo, $book), $tag, $src);
    $store = \SqliteStore::create($data);
    $id = (int)($store->appendWithId('retailers.json', ['name' => 'Test Field Staff', 'role' => 'support', 'is_active' => true,
                                                         'phone' => lm_num(2), 'email' => 'field@example.test'])['id'] ?? 0);
    lm_pdo($data)->prepare("INSERT INTO staff_ledger (staff_id, staff_name, direction, currency, amount, ssp_amount, category, source_type, idempotency_key)
                            VALUES (?, 'Test Field Staff', 'in', 'SSP', 0, 5000, 'advance', 'cash_advances', ?)")
        ->execute([$id, 'LM-ADV-' . $tag]);
    $err = $ctx->dir($tag . '-log') . '/php.log';
    touch($err);
    [, $out] = lm_cli_run($plug, $data, 'cron/staff_ssp_report.php', $err);
    $texts = (array)(qf_http('GET', $evo . '/__test/state')[1]['text_calls'] ?? []);
    $rows = [];
    try {
        $rows = lm_pdo($data)->query('SELECT sender, event, phone, preview, success, http_code, error FROM notification_audit_log ORDER BY id')
            ->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) { /* no row was written: an empty list */ }
    return ['out' => $out, 'log' => lm_lines((string)@file_get_contents($err)), 'texts' => $texts, 'rows' => $rows, 'id' => $id];
}

function lm_g02(QfCtx $ctx): void
{
    $ctx->run('G02 LN-02 cron/staff_ssp_report.php on a South Sudan book where SSP is bookable, PHP\'s log', function () use ($ctx) {
        $r = lm_ssp_run($ctx, 'g02', 'USD,SSP');
        $ctx->t(strpos($r['out'], 'Done. Sent: 1') !== false, 'LN-02 control: the job sent its one report', substr($r['out'], -300));
        $ctx->eq(count(array_filter($r['texts'], function ($t) { return strpos((string)$t['text'], 'Daily Cash Report') !== false; })), 1,
            'LN-02 control: the fake Evolution took the report');
        $found = lm_ln($ctx, 'LN-02', $r['log'], [lm_num(2)]);
        $ctx->t($found !== [] && strpos($found[0], '[staff_ssp_report] Sent to Test Field Staff  SSP=5000  USD=') !== false,
            'LN-02 the name and the balances stay (Q1)', implode(' | ', $found));
        $u = lm_ssp_run($ctx, 'g02u', 'UGX,USD');
        $ctx->t(strpos($u['out'], 'SSP is not bookable on this install') !== false
                && array_filter($u['log'], function ($l) { return strpos($l, '[staff_ssp_report] Sent to') !== false; }) === [],
            'LN-02 control: on a UGX,USD book the job says SSP is not bookable and writes no "Sent to" line', substr($u['out'], -300));
    });
}
