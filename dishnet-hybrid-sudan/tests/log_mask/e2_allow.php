<?php
declare(strict_types=1);
/**
 * e2_allow.php — 5.18.105 (root docs/74 §H.1.0 E2, §H.1.20, §H.3.4 E2): E2's list. TEST ONLY. Data, returned to
 * tests/log_mask/E_logging.php, which reads it from the judged root (so a weakened copy can take an entry out).
 *
 * Every log statement in runtime PHP (outside tests/, docs/ and Domain B), as E's reader finds it
 * (tests/log_mask/reader.php), that names a phone-carrying expression must be here. An entry is keyed by its file and
 * the statement's fixed words — its string literals in order, compared whole (whitespace collapsed, each trimmed, empty
 * ones dropped), with every variable, key read and nested call left out — never by line number: 5.18.105 moves lines
 * (webhook.php's loader after :129 and the markers of LN-16…LN-19 sit above K-01). The line beside an entry is 994d660's,
 * for the reader only. Each entry must match exactly one log statement in its file, and that statement must name such an
 * expression; an entry that matches none, or more than one, fails — so no entry goes stale.
 *
 * Reasons: (a) a sink that masks centrally (LN-24); (b) a known limit, South-Sudan-only or unreachable on Uganda
 * (§H.1.20); (c) not a phone number, and unchanged by 5.18.105.
 *
 * Not here, and never read by E: the dry-run capture (K-02, written with file_put_contents, lib/NotificationService.php:207)
 * and the two stores code reads back — NotificationService::writeLog's notification_audit_log.phone and
 * cron_quote_wa.php's quotes_log.json (§H.2.5, gap 10): writeLog and appendWithId are not loggers.
 */
return [
    // (a) masked centrally by LN-24: evoLog() masks every line before it writes evo_sync.log (§H.1.12)
    ['file' => 'cron_evo_sync.php', 'k' => 'LN-24', 'line' => 155, 'reason' => '(a) evoLog masks centrally (LN-24)',
     'fixed' => ['Syncing', '(', ')...']],
    ['file' => 'cron_evo_sync.php', 'k' => 'LN-24', 'line' => 204, 'reason' => '(a) evoLog masks centrally (LN-24)',
     'fixed' => ['→', ':', 'messages imported']],
    ['file' => 'cron_evo_sync.php', 'k' => 'LN-24', 'line' => 213, 'reason' => '(a) evoLog masks centrally (LN-24)',
     'fixed' => ['→ ERROR for', ':']],
    // (b) known limits (§H.1.20)
    ['file' => 'webhook.php', 'k' => 'K-01', 'line' => 2665, 'reason' => '(b) the job notification to a technician; tech_phone (K-01)',
     'fixed' => ['Job #', 'notification sent to', 'tech_phone', 'client', 'date']],
    ['file' => 'lib/FiberInstallService.php', 'k' => 'K-03', 'line' => 318, 'reason' => '(b) Delivery note skipped (K-03)',
     'fixed' => ['[FiberInstall] Delivery note skipped for job #', '— no KYC app found for phone']],
    ['file' => 'wa_webhook.php', 'k' => 'K-04', 'line' => 214, 'reason' => '(b) wa_webhook.php waLog (K-04)',
     'fixed' => ['skipped', 'Not usable:', 'phone']],
    ['file' => 'wa_webhook.php', 'k' => 'K-04', 'line' => 226, 'reason' => '(b) wa_webhook.php waLog (K-04)',
     'fixed' => ['message_received', 'Processed via WaMessageProcessor (', ')', 'phone', 'modality', 'action', 'replied', 'duplicate']],
    ['file' => 'wa_webhook.php', 'k' => 'K-04', 'line' => 241, 'reason' => '(b) wa_webhook.php waLog (K-04)',
     'fixed' => ['error', 'Exception:', 'phone', 'trace']],
    ['file' => 'cron_maintenance.php', 'k' => 'K-05', 'line' => 794, 'reason' => '(b) cron_maintenance.php mlog (K-05)',
     'fixed' => ['SENT: Invoice #', '→', '(', ')']],
    ['file' => 'cron_maintenance.php', 'k' => 'K-06', 'line' => 1163, 'reason' => '(b) cron_maintenance.php mlog (K-06)',
     'fixed' => ['Sent to', ': IN=', ', OUT=']],
    ['file' => 'cron_maintenance.php', 'k' => 'K-05', 'line' => 1266, 'reason' => '(b) cron_maintenance.php mlog (K-05)',
     'fixed' => ['Win-back sent:', '(', ') —', 'ended']],
    ['file' => 'cron/cashbook_summary.php', 'k' => 'K-07', 'line' => 192, 'reason' => '(b) cron/cashbook_summary.php cb_log (K-07)',
     'fixed' => ['Sent cashbook summary to', '(', ')']],
    ['file' => 'cron/staff_jobs_summary.php', 'k' => 'K-08', 'line' => 261, 'reason' => '(b) cron/staff_jobs_summary.php log_msg_staff_jobs (K-08)',
     'fixed' => ['Sent to', '(', ') — today:', 'overdue:']],
    ['file' => 'cron/job_assignment_notify.php', 'k' => 'K-09', 'line' => 90, 'reason' => '(b) cron/job_assignment_notify.php log_ja (K-09)',
     'fixed' => ['SEND FAILED to', ':', 'unknown']],
    ['file' => 'cron/job_assignment_notify.php', 'k' => 'K-09', 'line' => 226, 'reason' => '(b) cron/job_assignment_notify.php log_ja (K-09)',
     'fixed' => ['Job #', 'accepted → sent completion links to', '(', ')']],
    ['file' => 'cron/job_assignment_notify.php', 'k' => 'K-09', 'line' => 247, 'reason' => '(b) cron/job_assignment_notify.php log_ja (K-09)',
     'fixed' => ['Reassigned', 'New', 'job #', '→ notified', '(', ') [', ':', ']']],
    ['file' => 'cron/job_assignment_notify.php', 'k' => 'K-09', 'line' => 297, 'reason' => '(b) cron/job_assignment_notify.php log_ja (K-09)',
     'fixed' => ['Customer notified:', '(', ') — installation JOB-', 'by', 'on']],
    ['file' => 'cron_lte_usage.php', 'k' => 'K-10', 'line' => 136, 'reason' => '(b) cron_lte_usage.php clog_lte_usage (K-10)',
     'fixed' => ['WARNING', ':', '(', ') at', '%']],
    ['file' => 'dishnet_wa_pusher.php', 'k' => 'K-11', 'line' => 242, 'reason' => '(b) dishnet_wa_pusher.php pusherLog (K-11)',
     'fixed' => ['pkId=', ": skipped — invalid phone from remoteJid '", "'"]],
    ['file' => 'dishnet_wa_pusher.php', 'k' => 'K-11', 'line' => 302, 'reason' => '(b) dishnet_wa_pusher.php pusherLog (K-11)',
     'fixed' => ['pkId=', '[', ']: CURL error —']],
    ['file' => 'dishnet_wa_pusher.php', 'k' => 'K-11', 'line' => 308, 'reason' => '(b) dishnet_wa_pusher.php pusherLog (K-11)',
     'fixed' => ['pkId=', '[', '] [', ']: ✓ pushed — type=', 'text=']],
    ['file' => 'dishnet_wa_pusher.php', 'k' => 'K-11', 'line' => 314, 'reason' => '(b) dishnet_wa_pusher.php pusherLog (K-11)',
     'fixed' => ['pkId=', '[', ']: HTTP', '—']],
    ['file' => 'workers/lte/worker_jobs.php', 'k' => 'K-12', 'line' => 486, 'reason' => '(b) workers/lte echo (K-12)',
     'fixed' => ['[Would send to', ']:', '...\n']],
    ['file' => 'workers/lte/worker_report.php', 'k' => 'K-12', 'line' => 183, 'reason' => '(b) workers/lte echo (K-12)',
     'fixed' => ['Queued report for', '\n']],
    // (c) not a phone number, unchanged by 5.18.105 (§H.1.0 E2)
    ['file' => 'includes/api/api_lte.php', 'k' => 'c', 'line' => 750, 'reason' => '(c) $recipients holds e-mail addresses, on LTE',
     'fixed' => ['daily_summary', 'Daily summary email sent', 'To:']],
    ['file' => 'lib/KycService.php', 'k' => 'c', 'line' => 2660, 'reason' => '(c) $number is a quotation\'s number',
     'fixed' => ['[KycService] Quote #', '(', ') deferred to cron_quote_wa with PDF']],
];
