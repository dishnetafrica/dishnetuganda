<?php
declare(strict_types=1);
/**
 * lines.php — 5.18.105 (root docs/74 §H.1.1-§H.1.19, §H.1.0): the 35 masked lines, LN-01…LN-35, each with its file, its
 * section, 994d660's text and 5.18.105's text, exactly as the build wrote them. TEST ONLY.
 *
 * Read by section A (the weakened line copies: the new text found once in the judged file, replaced once by the old one,
 * the old text then present once — §H.1.0 "The weakened copy"), section D (D7's hunk judge and the LM-G1-style block
 * check: the new text as one block once, the old text nowhere) and section E (E1's added lines). 'new' and 'old' are each
 * one exact block of the file, indentation included where a block spans lines; a block that occurs other than once is a
 * failure of the test that asks, never a skip.
 *
 * 'fixed' is the line's fixed words, common to the old and the new form, by which A1 (the positive control) finds the
 * written line whichever form is in the file; 'form' is the new form's own words (each LN's extra assertion). Every
 * person and number in these tests is fictitious.
 */

const LM_LINES = [
    'LN-01' => ['file' => 'evo_webhook.php', 'sec' => '§H.1.1',
        'old' => <<<'OLD'
error_log('[evo_webhook] opt-out recorded for ' . $phone
OLD,
        'new' => <<<'NEW'
error_log('[evo_webhook] opt-out recorded for conversation ' . ($convId ? '#' . (int)$convId : '(not stored)')
NEW,
        'fixed' => '[evo_webhook] opt-out recorded for ', 'form' => 'opt-out recorded for conversation #'],
    'LN-02' => ['file' => 'cron/staff_ssp_report.php', 'sec' => '§H.1.2',
        'old' => <<<'OLD'
error_log("[staff_ssp_report] Sent to {$staffName} ({$phone})  SSP={$sspBal}  USD={$usdExposure}");
OLD,
        'new' => <<<'NEW'
error_log("[staff_ssp_report] Sent to {$staffName}  SSP={$sspBal}  USD={$usdExposure}");
NEW,
        'fixed' => '[staff_ssp_report] Sent to ', 'form' => 'SSP='],
    'LN-03' => ['file' => 'lib/QuotationService.php', 'sec' => '§H.1.3',
        'old' => <<<'OLD'
error_log("[QuotationService] WA send failed to {$phone}: " . $e->getMessage());
OLD,
        'new' => <<<'NEW'
error_log('[QuotationService] WA send failed (' . $event . '): ' . PluginLog::mask($e->getMessage()));
NEW,
        'fixed' => '[QuotationService] WA send failed ', 'form' => '[QuotationService] WA send failed (quote_manual): '],
    'LN-04' => ['file' => 'lib/WaAutoReplyService.php', 'sec' => '§H.1.4',
        'old' => <<<'OLD'
(uid:{$bidalUcrmId}) for {$phone}");
OLD,
        'new' => <<<'NEW'
(uid:{$bidalUcrmId})");
NEW,
        'fixed' => 'CRM Job #4711 created and assigned to', 'form' => '(uid:'],
    'LN-05' => ['file' => 'cron/bidal_summary.php', 'sec' => '§H.1.5',
        'old' => <<<'OLD'
log_msg_bidal_summary("Morning summary sent to {$leader['name']} ({$leader['phone']}) — pending:{$pending} testing:{$testing} done_today:{$completedToday}");
OLD,
        'new' => <<<'NEW'
log_msg_bidal_summary("Morning summary sent to {$leader['name']} — pending:{$pending} testing:{$testing} done_today:{$completedToday}");
NEW,
        'fixed' => '[bidal_summary] Morning summary sent to ', 'form' => ' — pending:'],
    'LN-06' => ['file' => 'cron/cashbook_reconcile.php', 'sec' => '§H.1.6',
        'old' => <<<'OLD'
rclog('Sent to ' . $rec['name'] . ' (' . $phone . ')');
OLD,
        'new' => <<<'NEW'
rclog('Sent to ' . $rec['name']);
NEW,
        'fixed' => '[reconcile] Sent to ', 'form' => '[reconcile] Sent to Test Accountant'],
    'LN-07' => ['file' => 'cron/cash_carry_reminder.php', 'sec' => '§H.1.7',
        'old' => <<<'OLD'
_cclog("Reminded {$name} ({$phone}): \${$exposure} exposure");
OLD,
        'new' => <<<'NEW'
_cclog("Reminded {$name}: \${$exposure} exposure");
NEW,
        'fixed' => '[cash_carry] Reminded ', 'form' => '[cash_carry] Reminded Test Collector: $'],
    'LN-08' => ['file' => 'cron/wa_watchdog.php', 'sec' => '§H.1.8',
        'old' => <<<'OLD'
echo "wa_watchdog: alerted for conv {$c['id']} ({$who}, {$mins} min)\n";
OLD,
        'new' => <<<'NEW'
echo "wa_watchdog: alerted for conv {$c['id']} ({$mins} min)\n";
NEW,
        'fixed' => 'wa_watchdog: alerted for conv ', 'form' => ' min)'],
    'LN-09' => ['file' => 'cron_invoice_notify.php', 'sec' => '§H.1.9',
        'old' => <<<'OLD'
ilog("SENT: #{$invoiceNum} " . dn_code($config) . " {$total} → {$fullName} ({$phone})");
OLD,
        'new' => <<<'NEW'
ilog("SENT: #{$invoiceNum} " . dn_code($config) . " {$total} → {$fullName} (client #{$clientId})");
NEW,
        'fixed' => 'SENT: #', 'form' => '(client #'],
    'LN-10' => ['file' => 'cron_invoice_notify.php', 'sec' => '§H.1.9',
        'old' => <<<'OLD'
ilog("RENEWAL REMIND: {$c['svcName']} \${$c['price']} -> {$custName} ({$custPhone}) renews {$c['nextBilling']}");
OLD,
        'new' => <<<'NEW'
ilog("RENEWAL REMIND: {$c['svcName']} \${$c['price']} -> {$custName} (client #{$c['clientId']}) renews {$c['nextBilling']}");
NEW,
        'fixed' => 'RENEWAL REMIND: ', 'form' => '(client #'],
    'LN-11' => ['file' => 'cron_quote_wa.php', 'sec' => '§H.1.10',
        'old' => <<<'OLD'
qwa_log(qwa_said($notify, 'TEXT', $_qwaSaid) . " KYC app #{$appId} quote #{$quoteId} {$quoteRef} → {$phone}");
OLD,
        'new' => <<<'NEW'
qwa_log(qwa_said($notify, 'TEXT', $_qwaSaid) . " KYC app #{$appId} quote #{$quoteId} {$quoteRef}");
NEW,
        'fixed' => 'TEXT SENT KYC app #', 'form' => 'TEXT SENT KYC app #'],
    'LN-12' => ['file' => 'cron_quote_wa.php', 'sec' => '§H.1.10',
        'old' => <<<'OLD'
qwa_log(qwa_said($notify, 'PDF', $_qwaSaid) . " KYC app #{$appId} quote #{$quoteId} {$quoteRef} → {$phone}");
OLD,
        'new' => <<<'NEW'
qwa_log(qwa_said($notify, 'PDF', $_qwaSaid) . " KYC app #{$appId} quote #{$quoteId} {$quoteRef}");
NEW,
        'fixed' => 'PDF SENT KYC app #', 'form' => 'PDF SENT KYC app #'],
    'LN-13' => ['file' => 'cron_quote_wa.php', 'sec' => '§H.1.10',
        'old' => <<<'OLD'
qwa_log(qwa_said($notify, 'TEXT', $_qwaSaid) . " UCRM quote #{$qId} {$qNumber} → {$phone} ({$clientName})");
OLD,
        'new' => <<<'NEW'
qwa_log(qwa_said($notify, 'TEXT', $_qwaSaid) . " UCRM quote #{$qId} {$qNumber} → {$clientName}");
NEW,
        'fixed' => 'TEXT SENT UCRM quote #', 'form' => ' → Test Quote Customer'],
    'LN-14' => ['file' => 'cron_quote_wa.php', 'sec' => '§H.1.10',
        'old' => <<<'OLD'
qwa_log(qwa_said($notify, 'PDF', $_qwaSaid) . " from pending queue: #{$pqNum} → {$pqName} ({$pqPhone})");
OLD,
        'new' => <<<'NEW'
qwa_log(qwa_said($notify, 'PDF', $_qwaSaid) . " from pending queue: #{$pqNum} → {$pqName}");
NEW,
        'fixed' => ' from pending queue: #', 'form' => ' → Test Pending Customer'],
    'LN-15' => ['file' => 'cron_quote_wa.php', 'sec' => '§H.1.10',
        'old' => <<<'OLD'
qwa_log("Receipt PDF SENT: PAY-{$rPayId} → {$rName} ({$rPhone}) — " . round(strlen(base64_decode($pdfRaw))/1024) . " KB");
OLD,
        'new' => <<<'NEW'
qwa_log("Receipt PDF SENT: PAY-{$rPayId} → {$rName} — " . round(strlen(base64_decode($pdfRaw))/1024) . " KB");
NEW,
        'fixed' => 'Receipt PDF SENT: PAY-', 'form' => ' → Test Receipt Customer — '],
    'LN-16' => ['file' => 'webhook.php', 'sec' => '§H.1.11',
        'old' => <<<'OLD'
whLog($changeType, "Welcome sent to {$name} ({$phone})"
OLD,
        'new' => <<<'NEW'
whLog($changeType, "Welcome sent to {$name}"
NEW,
        'fixed' => 'Welcome sent to ', 'form' => 'Welcome sent to Test Welcome'],
    'LN-17' => ['file' => 'webhook.php', 'sec' => '§H.1.11',
        'old' => <<<'OLD'
'phone'        => $phone ?: '(EMPTY)',
OLD,
        'new' => <<<'NEW'
'phone'        => PluginLog::tail((string)$phone),
NEW,
        'fixed' => 'Invoice notification check', 'form' => '"phone":"'],
    'LN-18' => ['file' => 'webhook.php', 'sec' => '§H.1.11',
        'old' => <<<'OLD'
'client_id' => $clientId, 'phone' => $phone ?: '(none)', 'amount' => $amount,
OLD,
        'new' => <<<'NEW'
'client_id' => $clientId, 'phone' => PluginLog::tail((string)$phone), 'amount' => $amount,
NEW,
        'fixed' => 'Skipped — no phone or zero amount', 'form' => '"phone":"••••18"'],
    'LN-19' => ['file' => 'webhook.php', 'sec' => '§H.1.11',
        'old' => <<<'OLD'
whLog($changeType, "Service.add logged (no notification - status={$status}, phone=" . ($phone ?: 'none') . ")");
OLD,
        'new' => <<<'NEW'
whLog($changeType, "Service.add logged (no notification - status={$status}, phone=" . PluginLog::tail((string)$phone) . ")");
NEW,
        'fixed' => 'Service.add logged (no notification - status=', 'form' => 'phone=••••19)'],
    'LN-20' => ['file' => 'webhook.php', 'sec' => '§H.1.11',
        'old' => <<<'OLD'
whLog($changeType, "Quote #{$quoteNum} → preparing PDF+caption for {$name} ({$phone})", ['amount' => $amountFmt]);
OLD,
        'new' => <<<'NEW'
whLog($changeType, "Quote #{$quoteNum} → preparing PDF+caption for {$name}", ['amount' => $amountFmt]);
NEW,
        'fixed' => ' → preparing PDF+caption for ', 'form' => 'preparing PDF+caption for Test Quoted'],
    'LN-21' => ['file' => 'webhook.php', 'sec' => '§H.1.11',
        'old' => <<<'OLD'
whLog($changeType, "Credit note notification SENT: #{$cnNum} → {$name} ({$phone})");
OLD,
        'new' => <<<'NEW'
whLog($changeType, "Credit note notification SENT: #{$cnNum} → {$name}");
NEW,
        'fixed' => 'Credit note notification SENT: #', 'form' => ' → Test Credited'],
    'LN-22' => ['file' => 'webhook.php', 'sec' => '§H.1.11',
        'old' => <<<'OLD'
whLog($changeType, "Client message forwarded to WA: {$name} ({$phone})");
OLD,
        'new' => <<<'NEW'
whLog($changeType, "Client message forwarded to WA: {$name}");
NEW,
        'fixed' => 'Client message forwarded to WA: ', 'form' => 'Client message forwarded to WA: Test Messaged'],
    'LN-23' => ['file' => 'webhook.php', 'sec' => '§H.1.11',
        'old' => <<<'OLD'
whLog($changeType, "Alert sent to accountant: {$acctPhone}");
OLD,
        'new' => <<<'NEW'
whLog($changeType, "Alert sent to accountant");
NEW,
        'fixed' => 'Alert sent to accountant', 'form' => 'Alert sent to accountant'],
    'LN-24' => ['file' => 'cron_evo_sync.php', 'sec' => '§H.1.12',
        'old' => <<<'OLD'
$line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
OLD,
        'new' => <<<'NEW'
$line = '[' . date('Y-m-d H:i:s') . '] ' . PluginLog::mask($msg) . PHP_EOL;
NEW,
        'fixed' => 'Syncing ', 'form' => 'Syncing <number> (<e-mail>)...'],
    'LN-25' => ['file' => 'cron_wa_sync.php', 'sec' => '§H.1.13',
        'old' => <<<'OLD'
wsLog("  {$inbound['modality']} from {$phone} on {$channel}: {$res['action']}"
OLD,
        'new' => <<<'NEW'
wsLog("  {$inbound['modality']} from conv #" . (int)($conv['id'] ?? 0) . " on {$channel}: {$res['action']}"
NEW,
        'fixed' => ' on support: ', 'form' => ' from conv #'],
    'LN-26' => ['file' => 'cron_overdue_email.php', 'sec' => '§H.1.14',
        'old' => <<<'OLD'
olog("WHATSAPP stage {$matchedStage['id']} ({$matchedStage['label']}) → {$fullName} {$phone} — {$invNum} {$amtFmt}");
OLD,
        'new' => <<<'NEW'
olog("WHATSAPP stage {$matchedStage['id']} ({$matchedStage['label']}) → {$fullName} — {$invNum} {$amtFmt}");
NEW,
        'fixed' => 'WHATSAPP stage ', 'form' => ' → Test Overdue — '],
    'LN-27' => ['file' => 'cron_overdue_email.php', 'sec' => '§H.1.14',
        'old' => <<<'OLD'
olog("WHATSAPP ERROR stage {$matchedStage['id']} → {$phone}: " . $e->getMessage());
OLD,
        'new' => <<<'NEW'
olog("WHATSAPP ERROR stage {$matchedStage['id']} → {$invNum}: " . $e->getMessage());
NEW,
        'fixed' => 'WHATSAPP ERROR stage ', 'form' => ' → INV-'],
    'LN-28' => ['file' => 'cron_leads.php', 'sec' => '§H.1.15',
        'old' => <<<'OLD'
clog_leads("  ⟲ Lead #{$l['id']} ({$l['customer_name']}) reassigned from {$oldAgName} → {$newAg['name']} ({$hoursIdle}h idle)");
OLD,
        'new' => <<<'NEW'
clog_leads("  ⟲ Lead #{$l['id']} reassigned from {$oldAgName} → {$newAg['name']} ({$hoursIdle}h idle)");
NEW,
        'fixed' => ' reassigned from ', 'form' => 'reassigned from '],
    'LN-29' => ['file' => 'cron_leads.php', 'sec' => '§H.1.15',
        'old' => <<<'OLD'
clog_leads("  ⚠ Lead #{$l['id']} ({$l['customer_name']}) flagged stale ({$hoursIdle}h idle)");
OLD,
        'new' => <<<'NEW'
clog_leads("  ⚠ Lead #{$l['id']} flagged stale ({$hoursIdle}h idle)");
NEW,
        'fixed' => ' flagged stale (', 'form' => ' flagged stale ('],
    'LN-30' => ['file' => 'cron_lead_alerts.php', 'sec' => '§H.1.16',
        'old' => <<<'OLD'
TASK A: Notified {$agent['name']} → {$leadName}\n";
OLD,
        'new' => <<<'NEW'
TASK A: Notified {$agent['name']} → lead #{$leadId}\n";
NEW,
        'fixed' => 'TASK A: Notified ', 'form' => ' → lead #'],
    'LN-31' => ['file' => 'cron_lead_alerts.php', 'sec' => '§H.1.16',
        'old' => <<<'OLD'
TASK B: 45-min warning to {$agent['name']} → {$leadName}\n";
OLD,
        'new' => <<<'NEW'
TASK B: 45-min warning to {$agent['name']} → lead #{$leadId}\n";
NEW,
        'fixed' => 'TASK B: 45-min warning to ', 'form' => ' → lead #'],
    'LN-32' => ['file' => 'cron_lead_alerts.php', 'sec' => '§H.1.16',
        'old' => <<<'OLD'
TASK C: Escalated {$leadName} ({$agent['name']}) to supervisor\n";
OLD,
        'new' => <<<'NEW'
TASK C: Escalated lead #{$leadId} ({$agent['name']}) to supervisor\n";
NEW,
        'fixed' => 'TASK C: Escalated ', 'form' => 'TASK C: Escalated lead #'],
    'LN-33' => ['file' => 'includes/notify_actions.php', 'sec' => '§H.1.17',
        'old' => <<<'OLD'
"Install confirmation sent to {$name}", "Phone:{$phone} Date:{$date}");
OLD,
        'new' => <<<'NEW'
"Install confirmation sent to {$name}", "Date:{$date}");
NEW,
        'fixed' => '"action":"notify_install"', 'form' => '"detail":"Date:'],
    'LN-34' => ['file' => 'includes/post/post_kyc.php', 'sec' => '§H.1.18',
        'old' => <<<'OLD'
implode(', ', array_map(fn($f,$v) => "{$f}: '{$v['from']}' → '{$v['to']}'", array_keys($changes), $changes))
OLD,
        'new' => <<<'NEW'
implode(', ', array_map(fn($f,$v) => in_array($f, ['mobile', 'note'], true) ? "{$f}: changed" : "{$f}: '{$v['from']}' → '{$v['to']}'", array_keys($changes), $changes))
NEW,
        'fixed' => '"action":"kyc_edit"', 'form' => 'mobile: changed, note: changed'],
    'LN-35' => ['file' => 'includes/post/post_cashbook.php', 'sec' => '§H.1.19',
        'old' => <<<'OLD'
"Refund WA sent to {$refundName} ({$refundPhone}): \${$_refAmt}", '');
OLD,
        'new' => <<<'NEW'
"Refund WA sent to {$refundName}: \${$_refAmt}", '');
NEW,
        'fixed' => '"action":"crm_refund_wa_sent"', 'form' => 'Refund WA sent to Test Refunded: $'],
];

/**
 * The four other runtime hunks (§H.1.0 "Counts"): webhook.php's loader, tail(), and the report's two. D7's declared
 * exceptions and lines are in tests/log_mask/D_boundary.php.
 */
const LM_OTHER_RUNTIME = [
    'webhook.php'                     => '§H.1.11 (the loader after :129)',
    'lib/PluginLog.php'               => '§H.1.22 (PluginLog::tail())',
    'tools/sales_followup_report.php' => '§H.1.23 (the closure after :663, and :707)',
];

/** The 19 log files (§H.1.0 "Files"). */
function lm_log_files(): array
{
    $f = [];
    foreach (LM_LINES as $ln) $f[$ln['file']] = true;
    return array_keys($f);
}

/** The LNs of one file, in order. */
function lm_lines_of(string $file): array
{
    return array_keys(array_filter(LM_LINES, function ($l) use ($file) { return $l['file'] === $file; }));
}
