<?php
declare(strict_types=1);
/**
 * test_payment_webhook_flow.php — 5.18.54, docs/46 row 1 (D-1, C7).
 *
 * payment.add used to die right after the first receipt: webhook.php released a lock through $payLockFp, a variable
 * defined nowhere, and flock(null) is a TypeError. uCRM had already been answered, so nothing retried, and the work
 * after the receipt never ran — the "just paid" marker, the payment push, the Starlink instant restore, the app-cache
 * refresh and the Overdue Workbench close.
 *
 * This drives the REAL webhook under php -S against a seeded fake uCRM and the fake Evolution:
 *   - Uganda: every step after the receipt runs, one answer, no error, and a repeat sends no second receipt;
 *   - South Sudan: 5.18.53's path, byte for byte — including the crash, which is D-1 there too (docs/46 §E);
 *   - three weakened copies of the fix, each caught.
 */
$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d !== '' ? "\n       $d" : '') . "\n"; } }

$root = dirname(__DIR__);
if (!getenv('DN_VAULT_FILE')) putenv('DN_VAULT_FILE=' . tempnam(sys_get_temp_dir(), 'dn-vault-'));
require_once __DIR__ . '/fixtures/notify_harness.php';
/** Each tenant's zone, named here: a test file may pin one, a fixture may not (tests/test_timezone.php). */
function nh_zone(string $tenant): string { return $tenant === 'uganda' ? 'Africa/Kampala' : 'Africa/Juba'; }

$seed = [
    'clients'  => ['7' => ['id' => 7, 'firstName' => 'Test', 'lastName' => 'Payer', 'isLead' => false,
                           'contacts' => [['phone' => '256700000007', 'email' => 'payer@example.test', 'isBilling' => true]]]],
    'payments' => ['9101' => ['id' => 9101, 'clientId' => 7, 'amount' => 150000.0, 'currencyCode' => 'UGX',
                              'methodId' => 'd8c1eae9-d41d-479f-aeaf-38497975d7b3', 'methodName' => 'Mobile Money',
                              'note' => '', 'invoiceIds' => [9201]]],
    'invoices' => ['9201' => ['id' => 9201, 'clientId' => 7, 'number' => 'INV-9201', 'status' => 3,
                              'total' => 150000.0, 'amountPaid' => 150000.0, 'amountToPay' => 0.0, 'currencyCode' => 'UGX']],
];

/** One run: returns what an assertion needs. */
$run = function (string $pluginRoot, string $tenant, bool $twice = false) use ($seed): array {
    $h = NotifyHarness::start($pluginRoot, $tenant, ['timezone' => nh_zone($tenant)], 'payflow');
    $h->seedCrm($seed);
    $r1 = $h->fire('payment.add', 'payment', 9101, 'uuid-pay-1');
    $h->settle(2.0);
    $r2 = $twice ? $h->fire('payment.add', 'payment', 9101, 'uuid-pay-2') : null;
    if ($twice) $h->settle(1.5);
    $pdo = $h->pdo();
    $slog = [];
    try { $slog = $pdo->query("SELECT action, detail FROM sl_suspension_log WHERE client_id = 7")->fetchAll(PDO::FETCH_ASSOC); }
    catch (\Throwable $e) {}
    $crmPaths = array_map(fn($r) => $r['method'] . ' ' . $r['path'], $h->crmRequests());
    $out = [
        'r1' => $r1, 'r2' => $r2,
        'texts' => $h->evoTexts(),
        'marker' => is_file($h->dataDir . '/recent_payment_7.marker'),
        'slog' => $slog,
        'crm' => $crmPaths,
        'whlog' => $h->webhookLog(),
        'stderr' => $h->stderr(),
    ];
    $h->stop();
    return $out;
};

// ── 1. Uganda ─────────────────────────────────────────────────────────────────
echo "\n1. Uganda: payment.add carries on after the receipt\n";
$u = $run($root, 'uganda', true);
is_($u['r1'][0] === 200, 'uCRM is answered 200', (string)$u['r1'][0]);
is_(substr_count((string)$u['r1'][1], '"status"') === 1 && strpos((string)$u['r1'][1], 'payment.add processed.') !== false,
    'exactly one answer, the one sent before the delivery note', (string)$u['r1'][1]);
$receipts = array_values(array_filter($u['texts'], fn($t) => strpos($t['text'], 'Payment Received') !== false));
is_(count($receipts) === 1, 'one WhatsApp receipt, although payment.add arrived twice', (string)count($receipts));
is_(($receipts[0]['number'] ?? '') === '256700000007', 'to the customer\'s number');
is_($u['marker'], 'the "just paid" marker is written (C7: the redundant "Service Restored" is suppressed as designed)');
is_(count(array_filter($u['slog'], fn($r) => $r['action'] === 'webhook_skipped')) >= 1,
    'the Starlink instant restore ran (it found no suspended router, and said so)', json_encode($u['slog']));
is_(strpos($u['whlog'], 'App cache refresh:') !== false, 'the app-cache refresh ran');
is_(in_array('GET /invoices/9201', $u['crm'], true) || in_array('GET /billing/invoices/9201', $u['crm'], true),
    'the Overdue Workbench close looked up the paid invoice', implode(', ', $u['crm']));
is_(stripos($u['stderr'], 'Uncaught') === false && stripos($u['stderr'], 'TypeError') === false
    && stripos($u['stderr'], 'Fatal') === false, 'no uncaught error in the plugin\'s log', substr($u['stderr'], 0, 400));
is_(stripos($u['stderr'], 'headers already sent') === false && stripos($u['stderr'], 'Cannot modify header') === false
    && stripos($u['stderr'], 'Cannot set response code') === false,
    'no second answer attempted after the first (no header warning)', substr($u['stderr'], 0, 400));
is_(($u['r2'][0] ?? 0) === 200 && strpos((string)$u['r2'][1], 'payment.add processed.') !== false,
    'the repeat is answered 200 by the end of the case');
is_(strpos($u['whlog'], 'Payment notification SKIPPED') !== false, 'the repeat says the receipt was already sent');

// ── 2. South Sudan: 5.18.53, unchanged ────────────────────────────────────────
echo "\n2. South Sudan: 5.18.53's path, unchanged (D-1 there too: docs/46 §E)\n";
$s = $run($root, 'south-sudan');
is_($s['r1'][0] === 200 && strpos((string)$s['r1'][1], 'payment.add processed.') !== false, 'uCRM is answered 200, as before');
is_(count(array_filter($s['texts'], fn($t) => strpos($t['text'], 'Payment Received') !== false)) === 1, 'one receipt, as before');
is_(!$s['marker'], 'no marker: the request still ends at the lock release, as in 5.18.53');
is_(strpos($s['whlog'], 'App cache refresh:') === false, 'no app-cache refresh, as in 5.18.53');
is_(stripos($s['stderr'], 'flock()') !== false, 'the same TypeError as 5.18.53 (kept until approved)', substr($s['stderr'], 0, 300));

// ── 3. Weakened copies ────────────────────────────────────────────────────────
echo "\n3. Weakened copies of the fix, each caught\n";
$gate = '                if (NotifyGate::applies(NotifyGate::PAYMENT_FLOW, $config, $dataDir)) {' . "\n"
      . '                    $payResponded = true;' . "\n";
$mutants = [
    'the gate never opens (always the old path)' => [
        [$gate, '                if (false) {' . "\n" . '                    $payResponded = true;' . "\n"]],
    'Uganda still exits early, without the lock release' => [
        [$gate, '                if (NotifyGate::applies(NotifyGate::PAYMENT_FLOW, $config, $dataDir)) {' . "\n"
              . '                    $payResponded = true; exit;' . "\n"]],
    'the end of the case answers a second time' => [
        ["        if (!empty(\$payResponded)) exit;\n        whResp(200, 'payment.add processed.');",
         "        whResp(200, 'payment.add processed.');"]],
];
foreach ($mutants as $name => $patch) {
    $mroot = NotifyHarness::weakened($root, ['webhook.php' => $patch], 'payflow_wk');
    $m = $run($mroot, 'uganda');
    $caught = !$m['marker']
           || strpos($m['whlog'], 'App cache refresh:') === false
           || stripos($m['stderr'], 'headers already sent') !== false
           || stripos($m['stderr'], 'Cannot modify header') !== false
           || stripos($m['stderr'], 'Cannot set response code') !== false;
    is_($caught, "caught: {$name}");
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
