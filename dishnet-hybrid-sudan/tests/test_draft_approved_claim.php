<?php
declare(strict_types=1);
/**
 * test_draft_approved_claim.php — 5.18.54, docs/46 row 47 (N-21): an invoice approved from a draft is announced once,
 * and a customer with no phone at approval is not left out for good.
 *
 * uCRM's automatic invoicing makes a draft and approves it, which raises invoice.draft_approved. Its handler took the
 * shared claim INV<number> before it read the customer's phone. With no phone, nothing was sent and the claim stayed,
 * so the 15-minute scanner, which honours the claim, never announced the invoice once a phone was added. invoice.add
 * reads the phone first; so does this handler now. docs/45 M11 also listed draft_approved as a path no test ran.
 *
 *    1. Uganda: a customer with a phone gets one WhatsApp for the invoice; uCRM delivering the event again sends nothing
 *    2. Uganda: a customer with no phone gets nothing, and no claim is taken; once a phone is added, the 15-minute
 *       scanner announces the invoice, once
 *    3. South Sudan: as in 5.18.53 — the claim taken with no phone, and the scanner then skips the invoice
 *    4. weakened copies, each caught
 *
 * Through the real plugin under php -S (tests/fixtures/staff_jobs_sandbox.php): the real webhook with uCRM's own key,
 * the real cron_invoice_notify.php, a fake uCRM and the fake Evolution. What this proves is what the plugin hands to
 * WhatsApp, never delivery. Every person, number and invoice is fictitious; nothing leaves the machine. `--no-mutants`
 * skips 4.
 */
$root = dirname(__DIR__);
$withMutants = !in_array('--no-mutants', $argv, true);
require_once __DIR__ . '/fixtures/staff_jobs_scenario.php';

$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; }
    else    { $fail++; echo "  FAIL {$m}" . ($d !== '' ? "\n       {$d}" : '') . "\n"; }
}

const DA_PHONE_15 = '256700000915';   // SjScenario's customer
const DA_PHONE_16 = '256700000916';   // the second customer's number, once it is added

/** The scenario, in one sandbox: two approvals and a redelivery; then a phone for customer 16, then the scanner. */
function da_run(string $tree, string $tenant): array
{
    // Quiet hours off: the scanner must be able to send whenever the suite runs (row 6 keeps them on Uganda).
    $base = ($tenant === 'uganda' ? ['tenant_profile' => 'uganda', 'timezone' => 'Africa/Kampala'] : ['timezone' => 'Africa/Juba'])
          + ['notify_quiet_from_hour' => 0, 'notify_quiet_until_hour' => 0];
    $s = SjSandbox::start($tree, $base, 'da' . substr($tenant, 0, 2));
    $crm = SjScenario::crm();
    $client16 = ['id' => 16, 'firstName' => 'Second', 'lastName' => 'Customer', 'street1' => 'Plot 10 Sandbox Road', 'city' => 'Kampala',
                 'isLead' => false, 'contacts' => [['email' => 'second@example.test', 'phone' => '', 'isBilling' => true]]];
    $crm['clients']['16'] = $client16;
    $inv = function (int $id, int $clientId): array {
        return ['id' => $id, 'number' => "DA-{$id}", 'clientId' => $clientId, 'status' => 1, 'total' => 150000.0,
                'amountToPay' => 150000.0, 'amountPaid' => 0.0, 'currencyCode' => 'UGX', 'maturityDate' => date('Y-m-d', time() + 14 * 86400),
                'createdDate' => date('c'), 'items' => [['label' => 'Services Plan Standard: Duration Monthly', 'total' => 150000.0]]];
    };
    $crm['invoices'] = ['5001' => $inv(5001, 15), '5002' => $inv(5002, 16)];
    $s->seedCrm($crm);
    $drain = function () use ($s): void { $s->http('GET', "{$s->base}?page=login"); };
    $texts = function (string $number, string $inv) use ($s): int {
        return count(array_filter($s->texts(), fn($t) => $t['number'] === $number && strpos((string)$t['text'], $inv) !== false));
    };
    $claims = function () use ($s): array {
        try { return array_column($s->q("SELECT dedup_key FROM notification_dedup WHERE dedup_key LIKE 'INVDA-%' ORDER BY dedup_key"), 'dedup_key'); }
        catch (\Throwable $e) { return []; }
    };

    $o = [];
    $o['fire1'] = $s->fire('invoice.draft_approved', 'invoice', 5001, 'da-5001')[0] ?? 0; $drain();
    $o['first'] = $texts(DA_PHONE_15, 'DA-5001');
    $o['fire2'] = $s->fire('invoice.draft_approved', 'invoice', 5001, 'da-5001-again')[0] ?? 0; $drain();
    $o['after_redelivery'] = $texts(DA_PHONE_15, 'DA-5001');
    $o['fire3'] = $s->fire('invoice.draft_approved', 'invoice', 5002, 'da-5002')[0] ?? 0; $drain();
    $o['nophone_texts'] = count(array_filter($s->texts(), fn($t) => strpos((string)$t['text'], 'DA-5002') !== false));
    $o['claims_after_webhook'] = $claims();

    // The phone is added in uCRM; the 15-minute scanner runs.
    $client16['contacts'][0]['phone'] = '+' . DA_PHONE_16;
    $s->seedCrm(['clients' => ['15' => $crm['clients']['15'], '16' => $client16]]);
    [$o['scan_rc'], $scanOut] = $s->run('cron_invoice_notify.php');
    $o['scan_out'] = substr($scanOut, 0, 400);
    $o['scanner_sent'] = $texts(DA_PHONE_16, 'DA-5002');
    $o['total_5001'] = $texts(DA_PHONE_15, 'DA-5001');
    $o['claims_after_scan'] = $claims();
    $s->stop();
    return $o;
}
function da_show(array $o): string { return substr((string)json_encode($o, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), 0, 900); }

// ── The cases, as functions of the plugin tree, so a weakened copy runs the very same ones ─────────────────────────
$caseUganda = function (string $tree): array {
    $o = da_run($tree, 'uganda');
    return [
        [$o['fire1'] === 200 && $o['first'] === 1, 'a customer with a phone: one WhatsApp for the approved invoice', da_show($o)],
        [$o['after_redelivery'] === 1 && $o['total_5001'] === 1, 'uCRM delivering the event again sends nothing more, nor does the scanner', da_show($o)],
        [$o['nophone_texts'] === 0 && !in_array('INVDA-5002', $o['claims_after_webhook'], true),
         'a customer with no phone: nothing sent, and no claim taken', da_show($o)],
        [$o['scanner_sent'] === 1 && in_array('INVDA-5002', $o['claims_after_scan'], true),
         'once a phone is added, the 15-minute scanner announces the invoice, once', da_show($o)],
    ];
};
$caseSouthSudan = function (string $tree): array {
    $o = da_run($tree, 'south-sudan');
    return [
        [$o['first'] === 1 && $o['after_redelivery'] === 1, 'a customer with a phone: one WhatsApp, as before', da_show($o)],
        [in_array('INVDA-5002', $o['claims_after_webhook'], true) && $o['scanner_sent'] === 0,
         'no phone: the claim is taken and the scanner skips the invoice, as in 5.18.53 (docs/46 §E)', da_show($o)],
    ];
};

// ══════════════════════════════════════════════════════════════════════════════
echo "\n1–2. Uganda\n";
foreach ($caseUganda($root) as [$ok, $m, $d]) is_($ok, $m, $d);

echo "\n3. South Sudan — as in 5.18.53\n";
foreach ($caseSouthSudan($root) as [$ok, $m, $d]) is_($ok, $m, $d);

echo "\n4. Weakened copies, each caught\n";
$failedWhere = function (array $triples, string $what): bool {
    foreach ($triples as [$ok, $m]) if (!$ok && strpos($m, $what) !== false) return true;
    return false;
};
$mutants = [
    'the claim taken before the phone again' => ['webhook.php',
        "        if (!\$_draftClaimLate && !\$notify->dedupMark(\$invLogKey)) {\n",
        "        if (!\$notify->dedupMark(\$invLogKey)) {\n",
        fn(string $t) => $failedWhere($caseUganda($t), 'no claim taken'), 'the no-phone invoice was claimed'],
    'no claim at the send' => ['webhook.php',
        "        if (\$phone && \$_draftClaimLate && !\$notify->dedupMark(\$invLogKey)) {\n",
        "        if (false) {\n",
        fn(string $t) => $failedWhere($caseUganda($t), 'sends nothing more'), 'the redelivered event sent a second WhatsApp'],
    'not gated' => ['lib/NotifyGate.php',
        "    public const EVERYWHERE = [];\n", "    public const EVERYWHERE = [self::DRAFT_CLAIM];\n",
        fn(string $t) => $failedWhere($caseSouthSudan($t), 'as in 5.18.53'), 'South Sudan took no claim'],
];
foreach ($withMutants ? $mutants : [] as $name => [$rel, $old, $new, $caught, $why]) {
    [$t, $n] = sj_weakened_copy($root, $rel, $old, $new);
    if ($n !== 1) {
        is_(false, "caught: {$name}", "the anchor was found {$n} times in {$rel}");
    } else {
        $ok = (bool)$caught($t);
        is_($ok, "caught: {$name}" . ($ok ? " ({$why})" : ''));
    }
    exec('rm -rf ' . escapeshellarg($t));
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
