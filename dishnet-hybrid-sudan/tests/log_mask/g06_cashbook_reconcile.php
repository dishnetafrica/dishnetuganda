<?php
declare(strict_types=1);
/**
 * g06_cashbook_reconcile.php — 5.18.105 (root docs/74 §H.1.6): group G06 of tests/test_log_mask.php section A. TEST ONLY.
 *
 * LN-06, cron/cashbook_reconcile.php's "Sent to" line: the nightly audit run in a sandbox of its own with one active
 * accountant row carrying a fictitious phone (+256700000106); the repository's fake Evolution takes the audit; stdout is
 * captured (plugin.log).
 *
 * The book is made as a live one stands, because a book fresh from the migrations stops the audit before its send — an
 * older fault, the same on 994d660's own file (measured in this harness, 5.18.105 changes neither line):
 *   cron/cashbook_reconcile.php:225  PDOException "no such table: main.cash_expenses" — the staff_cash_position view
 *       (migration 033) reads the keyed tables payment_collections, cash_expenses and cash_handovers, which the store
 *       creates on their first load or save;
 *   cron/cashbook_reconcile.php:310  PDOException "no such column: currency" — migration 007's cb_ledger stub has no
 *       currency column; CashbookService adds it (initTable) the first time the cashbook is opened.
 * So the three tables are loaded (each exists and is empty, as tests/test_field_cash_in_currency.php does) and
 * CashbookService is constructed once on the box's book; nothing else is seeded.
 */
function lm_g06(QfCtx $ctx): void
{
    $ctx->run('G06 LN-06 cron/cashbook_reconcile.php: the nightly audit sent to the accountant, stdout', function () use ($ctx) {
        $evo = qf_fake_evo();
        [$plug, $data] = lm_cli_box($ctx, ['tenant_profile' => 'uganda', 'timezone' => QF_TZ, 'evo_api_url' => $evo, 'evo_api_key' => 'lm-evo-key',
                                           'evo_instance_support' => 'lm-support', 'evo_instance_account' => 'lm-account',
                                           'evo_instance_sales' => 'lm-sales'], 'g06');
        \SqliteStore::create($data)->appendWithId('retailers.json', ['name' => 'Test Accountant', 'role' => 'accountant',
            'is_active' => true, 'phone' => lm_num(6), 'email' => 'accounts@example.test']);
        $book = \SqliteStore::create($data);
        foreach (['payment_collections.json', 'cash_expenses.json', 'cash_handovers.json'] as $t) $book->load($t);   // each exists, empty
        lm_lib($ctx, 'CashbookService');
        new \CashbookService($book, $data);   // its initTable(): cb_ledger's columns, as the first opening of the cashbook adds them
        $cols = array_column($book->getPdo()->query('PRAGMA table_info(cb_ledger)')->fetchAll(\PDO::FETCH_ASSOC), 'name');
        $ctx->t(in_array('currency', $cols, true) && (int)$book->getPdo()->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name IN ('payment_collections','cash_expenses','cash_handovers')")->fetchColumn() === 3,
            'LN-06 control: the book stands as a live one does (cb_ledger.currency, the three keyed tables)');
        $err = $ctx->dir('g06-log') . '/php.log';
        touch($err);
        [$rc, $out] = lm_cli_run($plug, $data, 'cron/cashbook_reconcile.php', $err);
        $ctx->t(strpos($out, '[reconcile] Done.') !== false, 'LN-06 control: the audit ran to its end', substr($out, -500));
        $ctx->eq(count(array_filter((array)(qf_http('GET', $evo . '/__test/state')[1]['text_calls'] ?? []), function ($t) {
            return strpos((string)$t['number'], substr(lm_forms(lm_num(6))[1], -9)) !== false;
        })), 1, 'LN-06 control: the fake Evolution took the audit for the accountant');
        lm_ln($ctx, 'LN-06', lm_lines($out), [lm_num(6)]);
    });
}
