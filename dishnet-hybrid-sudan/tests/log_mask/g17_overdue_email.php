<?php
declare(strict_types=1);
/**
 * g17_overdue_email.php — 5.18.105 (root docs/74 §H.1.14): group G17 of tests/test_log_mask.php section A. TEST ONLY.
 *
 * LN-26 and LN-27, cron_overdue_email.php's WhatsApp lines (OVD: its stdout, and overdue_cron_status.json's `output`),
 * through tests/test_notify_ladder_record.php's harness (tests/fixtures/staff_jobs_sandbox.php, the real job run in the
 * sandbox, postpaid — billing_model unset — and an SMTP setting so the ladder starts), with invoices 50 days overdue
 * (stage 3, WhatsApp only):
 *   LN-26  INV-1126 for client 126 ("Test Overdue", +256700000126): "WHATSAPP stage 3 (wa_midpoint) → Test Overdue — INV-1126 …";
 *   LN-27  INV-1127 for client 127, whose uCRM contact phone comes back as a JSON number (256700000127) — the job declares
 *          strict_types, so its sender throws (a TypeError at NotificationService::send()): "WHATSAPP ERROR stage 3 → INV-1127: …".
 * Both written forms of the job's log are judged. Every person and number is fictitious.
 */
function lm_g17(QfCtx $ctx): void
{
    $ctx->run('G17 LN-26 LN-27 cron_overdue_email.php: a WhatsApp stage, and one whose sender throws, stdout and the status file', function () use ($ctx) {
        require_once $ctx->root . '/tests/fixtures/staff_jobs_sandbox.php';
        $s = SjSandbox::start($ctx->root, ['tenant_profile' => 'uganda', 'timezone' => QF_TZ], 'lmg17');
        $due = (new \DateTime('now', new \DateTimeZone(QF_TZ)))->modify('-50 days')->format('Y-m-d') . 'T00:00:00+0300';
        $inv = function (int $id, int $client) use ($due): array {
            return ['id' => $id, 'number' => 'INV-' . $id, 'clientId' => $client, 'status' => 1, 'total' => 100000.0, 'amountToPay' => 100000.0,
                    'dueDate' => $due, 'currencyCode' => 'UGX'];
        };
        $s->seedCrm([
            'clients' => ['126' => ['id' => 126, 'firstName' => 'Test', 'lastName' => 'Overdue', 'contacts' => [['phone' => lm_num(26)]]],
                          // the phone as a JSON number, not a string: the sender's typed parameter refuses it (strict_types)
                          '127' => ['id' => 127, 'firstName' => 'Test', 'lastName' => 'Throwing', 'contacts' => [['phone' => (int)lm_forms(lm_num(27))[1]]]]],
            'invoices' => ['1126' => $inv(1126, 126), '1127' => $inv(1127, 127)],
        ]);
        file_put_contents($s->data . '/email_settings.json', json_encode(['smtp_host' => '127.0.0.1', 'smtp_port' => 9, 'smtp_enc' => '',
            'smtp_user' => '', 'smtp_pass' => '', 'smtp_from' => 'accounts@example.test']));
        [$rc, $out] = $s->run('cron_overdue_email.php');
        $status = json_decode((string)@file_get_contents($s->data . '/overdue_cron_status.json'), true);
        $statusOut = is_array($status) ? (string)($status['output'] ?? '') : '';
        $s->stop();
        $nums = [lm_num(26), lm_num(27)];
        $lines = lm_lines($out);
        $f26 = lm_ln($ctx, 'LN-26', $lines, $nums, 1, 'stdout');
        $ctx->t($f26 !== [] && preg_match('/^WHATSAPP stage 3 \(wa_midpoint\) → Test Overdue — INV-1126 \S/u', $f26[0]) === 1,
            'LN-26 the name and the invoice, no number', implode(' | ', $f26));
        $f27 = lm_ln($ctx, 'LN-27', $lines, $nums, 1, 'stdout');
        $ctx->t($f27 !== [] && strpos($f27[0], 'WHATSAPP ERROR stage 3 → INV-1127: ') === 0,
            'LN-27 the invoice number, not the customer\'s number', implode(' | ', $f27));
        // overdue_cron_status.json's `output` is the same lines, flushed every ten lines or two seconds (olog): whichever of
        // the two it holds when the run ends are judged too, and it must hold the run's opening line.
        $st = lm_lines($statusOut);
        $ctx->t(array_filter($st, function ($l) { return strpos($l, '=== Overdue Follow-up Cron') === 0; }) !== [],
            'G17 control: overdue_cron_status.json holds the run\'s output', substr($statusOut, 0, 300));
        lm_judge($ctx, 'LN-26', $st, 'WHATSAPP stage ', $nums, 0, 'overdue_cron_status.json output');
        lm_judge($ctx, 'LN-27', $st, 'WHATSAPP ERROR stage ', $nums, 0, 'overdue_cron_status.json output');
    });
}
