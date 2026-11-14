<?php
declare(strict_types=1);
/**
 * g09_invoice_notify.php — 5.18.105 (root docs/74 §H.1.9): group G09 of tests/test_log_mask.php section A. TEST ONLY.
 *
 * LN-09 and LN-10, the two lines of cron_invoice_notify.php that write to invoice_notify_cron.log (INV, written only on
 * Uganda): NotifyHarness, as tests/test_reminders_one_path.php's scan runs it (a tree whose entry script is a real copy,
 * finding the harness's data directory through ucrm.json; quiet hours set away from the present hour), against the
 * seeded fake uCRM (tests/fixtures/fake_ucrm_notify.php):
 *   LN-09  an unclaimed invoice raised an hour ago for client 909, whose contact phone is +256700000109 (fictitious):
 *          "SENT: #INV-1109 … → Test Invoiced (client #909)";
 *   LN-10  in the same run, the renewal pass after its first-run stamp (renewal_remind_log.json's _last_run yesterday):
 *          an active service renewing in five days for client 910 (+256700000110): "RENEWAL REMIND: … (client #910) …".
 *
 * The stamp is written in the code's own form: one row, the keyed object, as cron_invoice_notify.php saves it
 * ($store->save('renewal_remind_log.json', $renewalLog), lines 338 and 373). On the tree as shipped that stamp is never
 * read back — an older fault, the same on 994d660's own files (measured in this harness; 5.18.105 changes neither
 * lib/SqliteStore.php nor the renewal pass but for LN-10's line):
 *   lib/SqliteStore.php:92 $FLAT_TABLES has no 'renewal_remind_log', so load() returns the row as a one-element list;
 *   cron_invoice_notify.php:236 then reads no _last_run, :266 makes every run a first run, and :330-339 log
 *   "RENEWAL DRY-RUN (first run, NOT sent)" and stamp again — every run, for ever; the real send (:340-375, LN-10's
 *   line at :358) is never reached. The store says so itself at the save: "… is not in $FLAT_TABLES — its keys will be
 *   stripped when it is read back. Add the table to that list."
 * So the drive's own tree (a copy, as before) takes that one entry in its lib/SqliteStore.php — anchored, asserted —
 * and nothing else; cron_invoice_notify.php is the judged file byte for byte. The control after it runs the tree as
 * shipped on the stamp the code itself wrote: a first-run dry run again (why the entry is planted).
 */
function lm_g09(QfCtx $ctx): void
{
    $ctx->run('G09 LN-09 LN-10 cron_invoice_notify.php: a new invoice and a renewal, invoice_notify_cron.log', function () use ($ctx) {
        require_once $ctx->root . '/tests/fixtures/notify_harness.php';
        $hour = (int)(new \DateTimeImmutable('now', new \DateTimeZone(QF_TZ)))->format('G');
        $h = NotifyHarness::start($ctx->root, 'uganda', ['timezone' => QF_TZ, 'notify_quiet_from_hour' => ($hour + 2) % 24,
            'notify_quiet_until_hour' => ($hour + 4) % 24, 'renewal_reminders_enabled' => true], 'lmg09');
        $today = new \DateTimeImmutable('today', new \DateTimeZone(QF_TZ));
        $h->seedCrm([
            'clients'  => ['909' => ['id' => 909, 'firstName' => 'Test', 'lastName' => 'Invoiced', 'contacts' => [['phone' => lm_num(9), 'email' => 'invoiced@example.test']]],
                           '910' => ['id' => 910, 'firstName' => 'Test', 'lastName' => 'Renewing', 'contacts' => [['phone' => lm_num(10)]]]],
            'invoices' => ['1109' => ['id' => 1109, 'number' => 'INV-1109', 'clientId' => 909, 'status' => 1, 'total' => 90000.0,
                                      'amountToPay' => 90000.0, 'amountPaid' => 0.0, 'currencyCode' => 'UGX',
                                      'dueDate' => $today->modify('+14 days')->format('Y-m-d') . 'T00:00:00+0300',
                                      'createdDate' => (new \DateTimeImmutable('-1 hour'))->format('Y-m-d\TH:i:sO')]],
            'services' => ['610' => ['id' => 610, 'clientId' => 910, 'name' => 'Test Home Plan', 'price' => 50.0, 'status' => 1,
                                     'nextInvoicingDay' => $today->modify('+5 days')->format('Y-m-d') . 'T00:00:00+0300']],
        ]);
        \SqliteStore::create($h->dataDir)->save('renewal_remind_log.json', ['_last_run' => $today->modify('-1 day')->format('Y-m-d')]);
        $tree = NotifyHarness::weakened($ctx->root, ['cron_invoice_notify.php' => [], 'lib/SqliteStore.php' => [lm_flat_patch('renewal_remind_log')]],
            'lmg09_tree', ['ucrm.json' => json_encode(['pluginDataDir' => $h->dataDir])]);
        $run = function (string $t) use ($h): string {
            return (string)shell_exec('DN_DATA_DIR=' . escapeshellarg($h->dataDir) . ' timeout 120 ' . escapeshellarg(PHP_BINARY) . ' '
                . escapeshellarg($t . '/cron_invoice_notify.php') . ' 2>&1');
        };
        $out = $run($tree);
        $logRaw = (string)@file_get_contents($h->dataDir . '/invoice_notify_cron.log');
        $log = lm_lines($logRaw);
        $texts = $h->evoTexts();
        // the control: the tree as shipped, on the stamp the code itself wrote just now, is on its first run again
        $shipped = NotifyHarness::weakened($ctx->root, ['cron_invoice_notify.php' => []], 'lmg09_ship',
            ['ucrm.json' => json_encode(['pluginDataDir' => $h->dataDir])]);
        $run($shipped);
        $again = lm_lines(substr((string)@file_get_contents($h->dataDir . '/invoice_notify_cron.log'), strlen($logRaw)));
        $h->stop();
        $ctx->t(array_filter($log, function ($l) { return strpos($l, 'Fetched ') === 0; }) !== [], 'LN-09 control: the scan ran (its Fetched line)',
            substr(implode(' | ', $log) . ' ' . $out, 0, 600));
        $ctx->eq(count(array_filter($texts, function ($t) { return strpos((string)$t['text'], 'INV-1109') !== false; })) >= 1, true,
            'LN-09 control: the new invoice was announced');
        $f9 = lm_ln($ctx, 'LN-09', $log, [lm_num(9), lm_num(10)]);
        $ctx->t($f9 !== [] && preg_match('/^SENT: #INV-1109 \S+ 90000 → Test Invoiced \(client #909\)$/u', $f9[0]) === 1,
            'LN-09 "SENT: #<n> … (client #<id>)"', implode(' | ', $f9));
        $ctx->eq(count(array_filter($texts, function ($t) { return strpos((string)($t['number'] ?? ''), substr(lm_forms(lm_num(10))[1], -9)) !== false; })), 1,
            'LN-10 control: the renewal reminder went to client 910 (the fake Evolution took it)');
        $ctx->t(array_filter($again, function ($l) { return strpos($l, 'RENEWAL DRY-RUN (first run, NOT sent): Test Home Plan') === 0; }) !== []
            && array_filter($again, function ($l) { return strpos($l, 'RENEWAL REMIND: ') === 0; }) === [],
            'LN-10 control: on the tree as shipped the stamp the code wrote is not read back — a first-run dry run again (the older fault, 994d660\'s too)',
            substr(implode(' | ', $again), 0, 600));
        $f10 = lm_ln($ctx, 'LN-10', $log, [lm_num(9), lm_num(10)]);
        $ctx->t($f10 !== [] && strpos($f10[0], 'RENEWAL REMIND: Test Home Plan $50 -> Test Renewing (client #910) renews ') === 0,
            'LN-10 "RENEWAL REMIND: … (client #<id>) renews …"', implode(' | ', $f10));
    });
}
