<?php
declare(strict_types=1);
/**
 * D — "Prepared by" on Uganda (docs/71 §I, D3): the client custom attribute keyed salesPerson, PATCHed after the client
 * is created and the company check passed, whether or not a quote follows. Never id 1, never South Sudan's. TEST ONLY.
 *
 * 5.18.102 D14 (docs/71 §R.9.1): the submissions carry the web form's marker (QfuCtx::submit's default) over the linked
 * seed, and D6's waiting application, marked lines and all, seeds quote_sale_entry web and its quote_link_ids (E-1): an
 * application stored without the key is the pre-deploy case, which every retry leaves unpriced. D3 gains a sale not
 * quoted because an item is not linked: the PATCH all the same.
 */

$QFU_SECTIONS['D'] = ['title' => '"Prepared by": the salesPerson PATCH, by key, after the company check, best effort', 'run' => function (QfuCtx $c) {
    $fresh = function (array $set = [], string $scenario = 'uganda') use ($c) {
        $c->ctl('kyc', '/__test/reset?scenario=' . $scenario);
        $c->ctl('kyc', '/__test/set', ['catalogue' => true] + $set);
    };
    // Only the "Prepared by" PATCH — one carrying attributes. (The payment's own PATCH {clientType: 2} is 5.18.101's.)
    $patches = fn() => array_values(array_filter($c->bodies('kyc', 'PATCH', '/clients/\d+'), fn($b) => isset($b['attributes'])));
    $order = function () use ($c): array {
        $out = [];
        foreach ($c->ctl('kyc', '/__test/log') as $e) {
            $p = preg_replace('#^/api/v2\.1#', '', (string)($e['path'] ?? ''));
            $r = ($e['method'] ?? '') . ' ' . $p;
            if ($r === 'PATCH /clients/901' && !isset($e['body']['attributes'])) continue;
            if (in_array($r, ['POST /clients', 'PATCH /clients/901', 'POST /clients/901/quotes', 'POST /payments'], true)) $out[] = $r;
        }
        return $out;
    };

    // D0 — the control
    $fresh(['sales_person_field' => 7]);
    $r = $c->submit(['install_by' => 'dishnet']);
    $c->eq([$patches(), $order()], [[['attributes' => [['customAttributeId' => 7, 'value' => 'Tester']]]], ['POST /clients', 'PATCH /clients/901', 'POST /clients/901/quotes']],
        'D0 the control: attribute #7 keyed salesPerson — one PATCH with the sales person, after the create and before the quote');
    $c->t(isset($r['value']['apps'][0]) && strpos(json_encode($c->bodies('kyc', 'POST', '/clients')), '"attributes"') === false,
        'D0 … and the create itself carries no attribute (an unknown id fails a whole create)');

    // D1 — absent / at id 1 only / ambiguous: no PATCH, the quote still made
    foreach ([
        'absent'           => [],
        'at id 1 only'     => ['sales_person_field' => 1],
        'two of them'      => ['sales_person_field' => 7, 'extra_fields' => [['id' => 8, 'name' => 'Sales Person 2', 'key' => 'salesPerson', 'attributeType' => 'client']]],
        'a tax field'      => ['extra_fields' => [['id' => 9, 'name' => 'EFRIS TIN', 'key' => 'salesPerson', 'attributeType' => 'client']]],
    ] as $what => $set) {
        $fresh($set);
        $r = $c->submit(['install_by' => 'dishnet']);
        $c->eq([count($patches()), count($c->bodies('kyc', 'POST', '/clients/\d+/quotes')), !empty($r['value']['success'])], [0, 1, true],
            "D1 the attribute {$what}: no PATCH clients/*, and the quote is still made");
    }
    $c->t(strpos((string)$r['errlog'], 'client #901: Prepared by not set') !== false && strpos((string)$r['errlog'], 'Tester') === false,
        'D1 … one line in PHP\'s log with ids only — never the sales person\'s name', (string)$r['errlog']);

    // D2 — a refused PATCH leaves the registration green
    $fresh(['sales_person_field' => 7, 'patch_refuse' => true]);
    $r = $c->submit(['install_by' => 'dishnet']);
    $c->eq([!empty($r['value']['success']), count($c->bodies('kyc', 'POST', '/clients/\d+/quotes'))], [true, 1], 'D2 uCRM refuses the PATCH: registered and quoted all the same');

    // D3 — no quote follows, the PATCH still does (the agent makes the quotation in uCRM, and it prints the attribute)
    $fresh(['sales_person_field' => 7]);
    $r = $c->submit([]);   // no installation answer: unpriced, Credit
    $c->eq([count($patches()), count($c->bodies('kyc', 'POST', '/clients/\d+/quotes'))], [1, 0], 'D3 an unpriced Credit sale: no quote, and the PATCH all the same');
    $fresh(['sales_person_field' => 7]);
    $r = $c->submit(['install_by' => 'dishnet'], ['cfg' => ['kyc_auto_quote_enabled' => false]]);
    $c->eq(count($patches()), 1, 'D3 automatic quotes switched off: the PATCH all the same');
    $fresh(['sales_person_field' => 7]);
    $r = $c->submit(['install_by' => 'dishnet'], ['links' => array_values(array_filter(qfu_links(), fn($l) => $l['id'] !== 2))]);
    $c->eq([count($patches()), count($c->bodies('kyc', 'POST', '/clients/\d+/quotes')), (end($r['value']['apps']) ?: [])['quote_refused'] ?? null], [1, 0, 'P1'],
        'D3 5.18.102 D14: a web sale whose kit is not linked — no quote (P1), and the PATCH all the same');

    // D4 — a misfiled client: no PATCH (the check must pass first)
    $fresh(['sales_person_field' => 7, 'force_org' => 3], 'two_orgs');
    $r = $c->submit(['install_by' => 'dishnet']);
    $c->eq([count($patches()), (end($r['value']['apps']) ?: [])['quote_refused'] ?? null], [0, 'R1'], 'D4 a client uCRM files under another company: R1, and no PATCH');

    // D5 — existing customers: never
    $fresh(['sales_person_field' => 7, 'clients' => [['id' => 901, 'organizationId' => 1, 'firstName' => 'Test', 'lastName' => 'Existing', 'contacts' => []]]]);
    $r = $c->submit(['customer_id' => '901', 'connectivity_type' => 'Additional Service', 'install_by' => 'dishnet']);
    $c->eq([count($patches()), count($c->bodies('kyc', 'POST', '/clients/\d+/quotes'))], [0, 1], 'D5 an existing customer\'s additional service: quoted, never PATCHed (the EFRIS TIN must not be at risk)');

    // D6 — the retry: after its create, before the payment and the quote
    $fresh(['sales_person_field' => 7]);
    $app = ['id' => 41, 'mobile' => '+256 700 002 041', 'firstname' => 'Test', 'lastname' => 'Retry', 'address_1' => 'Test Road',
            'customer_type' => 'StarLink', 'connectivity_type' => 'New Connection', 'package_choice' => '1', 'device_id' => '3', 'kitQty' => '1',
            'sales_type' => 'Cash', 'amount_charged' => 1800, 'sales_person' => '', 'retailer_name' => 'Retailer Name', 'priority' => 'Low',
            'crm_client_id' => null, 'crm_sync_status' => 'pending', 'submitted_at' => date('Y-m-d H:i:s'), 'is_lead' => false,
            'install_by' => 'dishnet', 'quote_created' => false, 'quote_lines_source' => 'ucrm-5.18.102', 'quote_items_total' => 1800,
            // 5.18.102 D14 (E-1, §R.4.4): a web application, with the link records its marked lines came from
            'quote_sale_entry' => 'web', 'quote_link_ids' => [1, 2, 3],
            'quote_items' => [['productId' => 12, 'label' => 'Residential Standard (TEST)', 'price' => 100, 'quantity' => 1, 'unit' => 'month'],
                              ['productId' => 55, 'label' => 'Standard Kit (TEST)', 'price' => 1500, 'quantity' => 1, 'unit' => 'Pc'],
                              ['productId' => 70, 'label' => 'Professional Installation', 'price' => 200, 'quantity' => 1, 'unit' => 'Job']]];
    $c->child('$x = new KycCrmSync($s, $crm, $s->load("kyc_config.json") ?? []); return $x->syncOne(41, false, "cron");', ['apps' => [$app]]);
    $c->eq([$order(), $patches()[0]['attributes'][0]['value'] ?? null],
        [['POST /clients', 'PATCH /clients/901', 'POST /payments', 'POST /clients/901/quotes'], 'Retailer Name'],
        'D6 the retry (cron): the PATCH after its create and before the payment and the quote; with no sales person recorded, the retailer\'s name');

    // D7 — South Sudan: never
    $fresh([], 'sudan');
    $r = $c->submit(['install_by' => 'dishnet', 'mobile' => '+211 900 000 777'], ['tenant' => 'south-sudan']);
    $c->eq(count($patches()), 0, 'D7 South Sudan: no PATCH (its create carries field 1 as before)');
}];
