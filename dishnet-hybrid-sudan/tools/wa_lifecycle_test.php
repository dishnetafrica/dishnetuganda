<?php
declare(strict_types=1);
chdir(dirname(__DIR__));
/**
 * wa_lifecycle_test.php — send every customer WhatsApp message the plugin
 * composes, with invented data, to ONE number. The twin of
 * email_lifecycle_test.php.
 *
 *   php tools/wa_lifecycle_test.php --to 256705993348
 *   php tools/wa_lifecycle_test.php --to 256705993348 --dry-run          (log only, nothing sent)
 *   php tools/wa_lifecycle_test.php --to 256705993348 --only invoice,receipt
 *   php tools/wa_lifecycle_test.php --to 256705993348 --name "Family Shoppers" --pause 3
 *
 * A header message announces the test and a footer closes it, so the person
 * holding the phone cannot mistake the samples for statements about a real
 * account. Every message names an invented invoice (INV-TEST-…) and reference.
 *
 * Covers the messages NotificationService composes as functions. Four
 * customer texts live inline in webhook.php (account created, service
 * activated, suspended, restored) and the quotation WhatsApp is composed by
 * cron_quote_wa.php with its PDF; those are exercised by the real uCRM actions
 * on a test client, not here.
 *
 * Read-only against the business: no customer, invoice or CRM record is touched.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/currency.php';
require_once $root . '/lib/CustomerContact.php';
require_once $root . '/lib/NotificationService.php';

$opt  = getopt('', ['to:', 'only:', 'dry-run', 'pause:', 'name:']);
$to   = preg_replace('/[^0-9]/', '', (string)($opt['to'] ?? ''));
$dry  = isset($opt['dry-run']);
$wait = isset($opt['pause']) ? max(0, (int)$opt['pause']) : 2;
$name = trim((string)($opt['name'] ?? ''));
if ($name === '') $name = 'Test Customer';
if (strlen($to) < 9) {
    fwrite(STDERR, "Usage: php tools/wa_lifecycle_test.php --to 2567XXXXXXXX [--dry-run] [--only key,key] [--name \"Who\"] [--pause N]\n");
    exit(1);
}

$dataDir = cliDataDir($root);
$store   = SqliteStore::create($dataDir);
// The same merge webhook.php makes: the store copy first, the files behind it.
$config  = (array)($store->load('kyc_config.json') ?: []) + PluginConfig::load($root, $dataDir);
$config['data_dir'] = $dataDir;
$notify  = new NotificationService($store, $config);
if ($dry) $notify->setDryRunMode(true);
$cur  = function_exists('dn_code') ? dn_code($config) : 'UGX';
$plan = 'Residential (up to 400 Mbps)';
$inv  = 'INV-TEST-0001';

// The journey, in the order a customer would live it. Each entry: key for
// --only, the event the message is logged under, when it really fires, and
// the call with invented data.
$JOURNEY = [
    ['invoice',     'ops_invoice_created',        'Invoice issued for the next period',
        function () use ($notify, $to, $name, $inv, $plan) { $notify->invoiceCreated($to, $name, $inv, 329000.0, '20 September 2026', $plan); }],
    ['due_7',       'ops_pre_due_d7',             'Seven days before the due date (daily cron)',
        function () use ($notify, $to, $name, $inv, $plan, $cur) { $notify->invoiceDue7Days($to, $name, $inv, 329000.0, $cur, '20 September 2026', $plan); }],
    ['due_3',       'ops_pre_due_d3',             'Three days before the due date',
        function () use ($notify, $to, $name, $inv, $plan, $cur) { $notify->invoiceDue3Days($to, $name, $inv, 329000.0, $cur, '20 September 2026', $plan); }],
    ['due_1',       'ops_pre_due_d1',             'The day before the due date',
        function () use ($notify, $to, $name, $inv, $plan, $cur) { $notify->invoiceDueTomorrow($to, $name, $inv, 329000.0, $cur, '20 September 2026', $plan); }],
    ['low_balance', 'ops_low_balance',            'Balance alert before a pause (maintenance cron)',
        function () use ($notify, $to, $name, $inv, $plan) { $notify->lowBalanceWarning($to, $name, 329000.0, '20 September 2026', $inv, $plan); }],
    ['receipt',     'ops_payment_received',       'Payment recorded against the account',
        function () use ($notify, $to, $name, $inv) { $notify->paymentReceived($to, $name, 329000.0, 'PAY-TEST-0001', $inv, 0.0); }],
    ['auto_paid',   'ops_invoice_auto_paid',      'Invoice covered in full by account credit',
        function () use ($notify, $to, $name, $plan) { $notify->invoiceAutoPaid($to, $name, 'INV-TEST-0002', 329000.0, 0.0, $plan); }],
    ['partial',     'ops_invoice_partial_credit', 'Invoice partly covered by account credit',
        function () use ($notify, $to, $name, $plan) { $notify->invoicePartialCredit($to, $name, 'INV-TEST-0003', 329000.0, 100000.0, 229000.0, '20 October 2026', $plan); }],
    ['install',     'ops_installation_scheduled', 'Installation booked',
        function () use ($notify, $to, $name, $plan) { $notify->installationScheduled($to, $name, 'Joseph (technician)', 'Monday 5 October 2026, 9:00 AM - 12:00 PM', $plan, 'JOB-TEST-701'); }],
    ['renewal',     'ops_renewal_reminder',       'Renewal reminder (sent only when renewal_reminders_enabled is set)',
        function () use ($notify, $to, $name, $plan) { $notify->renewalReminder($to, $name, $plan, 329000.0, '25 October 2026'); }],
];
$only = array_filter(array_map('trim', explode(',', (string)($opt['only'] ?? ''))));
if ($only) {
    $known = array_column($JOURNEY, 0);
    foreach ($only as $k) if (!in_array($k, $known, true)) { fwrite(STDERR, "Unknown key: {$k}\nKnown: " . implode(', ', $known) . "\n"); exit(1); }
    $JOURNEY = array_values(array_filter($JOURNEY, fn($s) => in_array($s[0], $only, true)));
}

// What happened to the last message with this event, from the service's own
// records: the dry-run log when nothing is sent, the audit log otherwise.
$outcome = function (string $event) use ($dry, $dataDir, $store, $to): string {
    if ($dry) {
        $log = json_decode((string)@file_get_contents($dataDir . '/dry_run_notification_log.json'), true) ?: [];
        for ($i = count($log) - 1; $i >= 0; $i--) {
            if (($log[$i]['event'] ?? '') === $event) return 'DRY RUN — logged, not sent';
        }
        return 'DRY RUN — NOT logged: the sender is not configured, or the number is opted out (tools/notify_doctor.php)';
    }
    try {
        $q = $store->getPdo()->prepare("SELECT success, http_code, error FROM notification_audit_log WHERE event = ? AND phone LIKE ? ORDER BY rowid DESC LIMIT 1");
        $q->execute([$event, '%' . substr($to, -9)]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if (!$r) return 'no record — nothing was attempted: sender not configured, or the number is opted out (tools/notify_doctor.php)';
        return !empty($r['success']) ? 'SENT (HTTP ' . (string)($r['http_code'] ?? '?') . ')'
                                     : 'FAILED — ' . (string)($r['error'] ?: ('HTTP ' . (string)($r['http_code'] ?? '?')));
    } catch (\Throwable $e) { return 'no audit table — outcome unknown'; }
};

$senderKeys = array_filter(['evo_instance_account', 'evo_instance_support', 'evo_api_url', 'wa_plugin_url'], fn($k) => trim((string)($config[$k] ?? '')) !== '');
echo "DishNet — customer WhatsApp lifecycle test\n" . str_repeat('=', 74) . "\n";
echo "To:       +{$to}\nAs:       {$name}\nMode:     " . ($dry ? 'DRY RUN — nothing is sent' : 'LIVE — messages will be delivered') . "\n";
echo "Sender:   " . ($senderKeys ? 'configured (' . implode(', ', $senderKeys) . ')' : 'NOT CONFIGURED — nothing can be sent') . "\n";
echo "Messages: " . count($JOURNEY) . " samples, plus a header and a footer\n" . str_repeat('=', 74) . "\n";

$n = count($JOURNEY);
$notify->sendVia('accounts', $to,
    "🧪 *TEST — DishNet system check*\n\nThe next {$n} messages are samples with invented data: the customer, invoice numbers and amounts are not real. Nothing is owed and nothing has changed on any account.\n— DishNet",
    'wa_lifecycle_test');
printf("\n[header]  %s\n", $outcome('wa_lifecycle_test'));
if (!$dry && $wait > 0) sleep($wait);

$i = 0;
foreach ($JOURNEY as [$key, $event, $when, $call]) {
    $i++;
    $call();
    printf("[%d/%d] %-12s %-28s %s\n        when: %s\n", $i, $n, $key, $event, $outcome($event), $when);
    if (!$dry && $wait > 0 && $i < $n) sleep($wait);
}

$notify->sendVia('accounts', $to,
    "🧪 *TEST complete* — {$n} sample messages. Account created, service activated, paused and resumed, and the quotation with its PDF are sent by the real uCRM events; test those on a test client.\n— DishNet",
    'wa_lifecycle_test');
printf("[footer]  %s\n", $outcome('wa_lifecycle_test'));
echo str_repeat('=', 74) . "\n";
exit(0);
