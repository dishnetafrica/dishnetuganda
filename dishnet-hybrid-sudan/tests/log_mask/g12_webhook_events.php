<?php
declare(strict_types=1);
/**
 * g12_webhook_events.php — 5.18.105 (root docs/74 §H.1.11): group G12 of tests/test_log_mask.php section A, and the
 * webhook harness G13 and G14 share. TEST ONLY.
 *
 * LN-16, LN-17, LN-18, LN-19, LN-21 and LN-22, webhook.php's lines in webhook_log.json (WH), through the served plugin
 * (tests/fixtures/staff_jobs_sandbox.php, as tests/test_notify_event_once.php fires uCRM's events: the real webhook, a
 * seeded fake uCRM, the fake Evolution, crm_webhook_key set so client.message is forwarded), on Uganda:
 *   LN-16  client.add for client 116 (+256700000116): "Welcome sent to Test Welcome";
 *   LN-17  invoice.add for client 117 (+256700000117) and for client 120 (no phone): data.phone "••••17" and "none";
 *          the CRM Events page (fqsub=crm_events) shows no form of any fixture number;
 *   LN-18  invoice.add of zero for client 118 (+256700000118): "Skipped — no phone or zero amount", data.phone "••••18";
 *          INV-1120 (client 120, no phone) takes the same branch, data.phone "none": the form is judged per entry;
 *   LN-19  service.add (status 3) for client 119 (+256700000119): "phone=••••19";
 *   LN-21  credit_note.add CN-4421 for client 121 (+256700000121): "Credit note notification SENT: #CN-4421 → Test Credited";
 *   LN-22  client.message for client 122 (+256700000122): "Client message forwarded to WA: Test Messaged".
 * Every person and number is fictitious.
 */

const LM_WH_KEY = 'lm-webhook-key-test';

/** A Uganda sandbox for uCRM's events: the plugin served, the fake uCRM seeded with $crm, crm_webhook_key set. */
function lm_wh_sandbox(QfCtx $ctx, string $tag, array $crm, array $cfg = []): SjSandbox
{
    require_once $ctx->root . '/tests/fixtures/staff_jobs_sandbox.php';
    $s = SjSandbox::start($ctx->root, $cfg + ['tenant_profile' => 'uganda', 'timezone' => QF_TZ, 'crm_webhook_key' => LM_WH_KEY], $tag);
    file_put_contents($s->plug . '/ucrm.json', json_encode(['pluginDataDir' => $s->data, 'pluginPublicUrl' => $s->base]));
    $s->seedCrm($crm);
    return $s;
}

/** uCRM's webhook, as uCRM sends it with crm_webhook_key (X-Crm-Key); then the served plugin drained (php -S: one at a time). */
function lm_wh_fire(SjSandbox $s, string $type, string $entity, int $id, string $uuid, array $extra = []): array
{
    $r = $s->http('POST', "{$s->base}?page=crm_webhook", ['changeType' => $type, 'entity' => $entity, 'entityId' => $id, 'uuid' => $uuid,
        'extraData' => ['entity' => ['id' => $id] + $extra]], ['Content-Type: application/json', 'X-Crm-Key: ' . LM_WH_KEY]);
    $s->http('GET', "{$s->base}?page=login");
    return $r;
}

/** A fictitious uCRM client with one contact; no phone when $phone is ''. */
function lm_client(int $id, string $first, string $last, string $phone, array $extra = []): array
{
    $c = ['email' => strtolower($last) . '@example.test', 'isBilling' => true];
    if ($phone !== '') $c['phone'] = $phone;
    return $extra + ['id' => $id, 'firstName' => $first, 'lastName' => $last, 'isLead' => false, 'contacts' => [$c]];
}

/** webhook_log.json's entries, each as a judged line (its received_at left out). */
function lm_wh_lines(SjSandbox $s): array
{
    return lm_json_entries($s->data . '/webhook_log.json');
}

function lm_g12(QfCtx $ctx): void
{
    $ctx->run('G12 LN-16 LN-17 LN-18 LN-19 LN-21 LN-22 webhook.php: uCRM\'s customer events, webhook_log.json', function () use ($ctx) {
        $inv = function (int $id, int $client, float $amount): array {
            return ['id' => $id, 'number' => 'INV-' . $id, 'clientId' => $client, 'status' => 1, 'total' => $amount, 'amountToPay' => $amount,
                    'amountPaid' => 0.0, 'currencyCode' => 'UGX', 'dueDate' => date('Y-m-d', time() + 14 * 86400) . 'T00:00:00+0300',
                    'createdDate' => date('c')];
        };
        $s = lm_wh_sandbox($ctx, 'lmg12', [
            'clients' => ['116' => lm_client(116, 'Test', 'Welcome', lm_num(16)), '117' => lm_client(117, 'Test', 'Invoiced', lm_num(17)),
                          '118' => lm_client(118, 'Test', 'Zero', lm_num(18)), '119' => lm_client(119, 'Test', 'Service', lm_num(19)),
                          '120' => lm_client(120, 'Test', 'Nophone', ''), '121' => lm_client(121, 'Test', 'Credited', lm_num(21)),
                          '122' => lm_client(122, 'Test', 'Messaged', lm_num(22))],
            'invoices' => ['1117' => $inv(1117, 117, 90000.0), '1118' => $inv(1118, 118, 0.0), '1120' => $inv(1120, 120, 50000.0)],
            'services' => ['619' => ['id' => 619, 'clientId' => 119, 'name' => 'Test Plan', 'status' => 3]],
            'credit_notes' => ['4421' => ['id' => 4421, 'number' => 'CN-4421', 'clientId' => 121, 'total' => 20000.0]],
        ]);
        $s->staff('admin', ['name' => 'Test Admin', 'email' => 'admin@example.test', 'role' => 'admin', 'is_admin' => true]);
        $codes = [
            lm_wh_fire($s, 'client.add', 'client', 116, 'lm-c116')[0],
            lm_wh_fire($s, 'invoice.add', 'invoice', 1117, 'lm-i1117')[0],
            lm_wh_fire($s, 'invoice.add', 'invoice', 1118, 'lm-i1118')[0],
            lm_wh_fire($s, 'invoice.add', 'invoice', 1120, 'lm-i1120')[0],
            lm_wh_fire($s, 'service.add', 'service', 619, 'lm-s619')[0],
            lm_wh_fire($s, 'credit_note.add', 'creditNote', 4421, 'lm-cn4421')[0],
            lm_wh_fire($s, 'client.message', 'client', 122, 'lm-m122', ['clientId' => 122, 'message' => 'Your installation is booked (test)'])[0],
        ];
        $lines = lm_wh_lines($s);
        $s->login('admin', 'admin@example.test', 'sj-password-1');
        $page = $s->page('admin', 'page=dashboard&tab=engage_failed_queue&fqsub=crm_events');
        $s->stop();
        $ctx->eq($codes, array_fill(0, 7, 200), 'G12 control: every event is answered 200');
        $all = [lm_num(16), lm_num(17), lm_num(18), lm_num(19), lm_num(21), lm_num(22)];

        lm_ln($ctx, 'LN-16', $lines, $all);

        $f17 = lm_ln($ctx, 'LN-17', $lines, $all, 3);
        $by = function (string $needle) use ($f17): string {
            foreach ($f17 as $l) if (strpos($l, $needle) !== false) return $l;
            return '';
        };
        $ctx->t(strpos($by('"invoice_num":"INV-1117"'), '"phone":"••••17"') !== false, 'LN-17 data.phone is "••••17" for a client with a fixture phone', $by('INV-1117'));
        $ctx->t(strpos($by('"invoice_num":"INV-1120"'), '"phone":"none"') !== false, 'LN-17 data.phone is "none" for a client with none (no "(EMPTY)")', $by('INV-1120'));
        $table = preg_match('#<table class="fq-table">.*?</table>#s', $page, $m) ? $m[0] : '';
        $ctx->t($table !== '' && strpos($table, '••••17') !== false, 'LN-17 control: the CRM Events page lists the entry, its phone as "••••17"', substr($page, 0, 300));
        $ctx->eq(lm_number_hits($table, $all), [], 'LN-17 the CRM Events page (fqsub=crm_events) shows no form of any fixture number');

        // Two entries take this branch (webhook.php's else of `if ($phone && $amount > 0)`): INV-1118 (client 118, a phone,
        // amount 0) and INV-1120 (client 120, no phone). A1-A3 judge both; the new form is asked of client 118's entry, and
        // client 120's must read "none", never 994d660's "(none)". lm_ln() would ask the form of both and fail correct code.
        $f18 = lm_judge($ctx, 'LN-18', $lines, LM_LINES['LN-18']['fixed'], $all, 2);
        $of18 = function (int $client) use ($f18): array {
            return array_values(array_filter($f18, function ($l) use ($client) { return strpos($l, '"data":{"client_id":' . $client . ',') !== false; }));
        };
        $ctx->eq([count($f18), count($of18(118)), count($of18(120))], [2, 1, 1],
            'LN-18 control: two skipped entries, one for client 118 (INV-1118, zero amount), one for client 120 (INV-1120, no phone)');
        lm_form($ctx, 'LN-18', $of18(118), LM_LINES['LN-18']['form'], 'client 118\'s entry, the zero-amount invoice, its class still skipped');
        lm_form($ctx, 'LN-18', $of18(120), '"phone":"none"', 'client 120\'s entry, no phone: "none", not 994d660\'s "(none)"');

        $f19 = lm_ln($ctx, 'LN-19', $lines, $all);
        $ctx->t($f19 !== [] && strpos($f19[0], 'Service.add logged (no notification - status=3, phone=••••19)') !== false,
            'LN-19 "Service.add logged (no notification - status=3, phone=••••19)"', implode(' | ', $f19));

        $f21 = lm_ln($ctx, 'LN-21', $lines, $all);
        $ctx->t($f21 !== [] && strpos($f21[0], 'Credit note notification SENT: #CN-4421 → Test Credited"') !== false,
            'LN-21 "Credit note notification SENT: #CN-4421 → <name>"', implode(' | ', $f21));

        lm_ln($ctx, 'LN-22', $lines, $all);
    });
}
