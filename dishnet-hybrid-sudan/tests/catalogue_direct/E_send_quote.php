<?php
declare(strict_types=1);
/**
 * Section E — 5.18.103 (root docs/72 §G.9, G-14, G-15): Send Quotation and the lead quotes with the uCRM catalogue's
 * switch on ("direct mode"), through the entry points staff and the app use — the page (tabs/sales/send_quote.php,
 * drawn here and driven in headless Chromium), the two handlers of includes/post/post_leads.php, the mobile API's two
 * blocks of api/index.php, and QuotationService::buildLeadItems() — on a copy of the judged tree, against the fake uCRM
 * of the quoting organization and the fake Evolution (tests/catalogue_direct/e_lib.php).
 *
 *   E0  the control: a manual quote and a lead quote with picked lines — a plan by its service plan and product, hardware,
 *       the installation — reach uCRM's billing/quotes as {productId, label, price, quantity, unit} from a fresh read
 *   E1  the posted label and price of a picked line never decide; a lower price refuses the send, nothing written
 *   E2  a picked id that is not offered (kept off, priced 0, archived, the installation or a plan's product picked as
 *       hardware, a plan not offered) refuses the whole send with its reason
 *   E3  a typed line carrying a productId loses it in direct mode (the switch off keeps it: E10)
 *   E4  the mobile API's sends re-price the same way (G-15)
 *   E5  the handlers: a picked line posted at price 0 is not dropped by their price filter
 *   E6  F12 / G-14: each send one quotes_log row, type lead / manual, sent_by; the events quote_lead / quote_manual
 *   E7  buildLeadItems: the one offered plan keyed exactly like the lead's interest, else nothing
 *   E8  the page: the pickers, the standard quotations, the marker, the stale and unread banners
 *   E9  the page in a browser: a standard quotation pressed, posted through the handler, quoted in uCRM
 *   E10 the switch off (with settings, and with no table) and South Sudan (with and without records): every page, request,
 *       WhatsApp text and store row as 1a0ea5f's (5.18.102 live, from Git); a direct page's post refused with the
 *       switch off, its marker ignored on South Sudan
 *
 * Every refusal asserts nothing was created or sent and no quotes_log row; every negative has its control. Every name,
 * number and price is fictitious.
 */
require_once __DIR__ . '/e_lib.php';

$CD_SECTIONS['E'] = ['title' => 'Send Quotation and lead quotes with the uCRM catalogue', 'run' => function (QfuCtx $c) {
    $trees = cde_trees($c);
    $c->t($trees['old'] !== null, 'E0 the comparison tree: Git supplies 1a0ea5f (5.18.102 live) — without it nothing in E10 can be compared: a failure, never a skip');
    $MK  = '5.18.103-direct';
    $S1  = cd_std('Mini + Residential (TEST)', CD_PLAN_RES, 12, [['p' => CD_MINI, 'q' => 1]], true);
    $S2  = cd_std('Old Kit bundle (TEST)', CD_PLAN_RES, 12, [['p' => 81, 'q' => 1]], false);   // product 81 is archived in uCRM
    $REC = cd_records(true, CD_INSTALL, [CD_ACC], [$S1, $S2]);                                // the switch on; the adapter kept off

    // The lines uCRM's billing/quotes receives, and a line as the page posts it once picked ({kind, sp, p, q, label, price}).
    $line = function (int $p, string $label, $price, int $q, string $unit): array {
        return ['productId' => $p, 'label' => $label, 'price' => $price, 'quantity' => $q, 'unit' => $unit];
    };
    $RES  = $line(12, 'Residential Standard (TEST)', 100, 1, 'month');
    $BIZ  = $line(17, 'Business Plus (TEST)', 300, 1, 'month');
    $KIT  = function (int $q) use ($line) { return $line(CD_KIT, 'Standard Kit (TEST)', 1500, $q, 'Pc'); };
    $MINI = function (int $q) use ($line) { return $line(CD_MINI, 'Mini Kit (TEST)', 900, $q, 'Pc'); };
    $INST = $line(CD_INSTALL, 'Professional Installation', 200, 1, 'Job');
    $pick = function (array $l, string $kind, array $over = []): array {
        $p = ['kind' => $kind, 'p' => $l['productId'], 'q' => $l['quantity'], 'label' => $l['label'], 'price' => $l['price'],
              'quantity' => $l['quantity'], 'unit' => $l['unit']];
        if ($kind === 'plan') $p['sp'] = $l['productId'] === 12 ? CD_PLAN_RES : CD_PLAN_BIZ;
        return array_merge($p, $over);
    };
    $pRES = $pick($RES, 'plan');
    $pBIZ = $pick($BIZ, 'plan');
    $pKIT = function (int $q, array $over = []) use ($pick, $KIT) { return $pick($KIT($q), 'item', $over); };
    $pMINI = function (int $q) use ($pick, $MINI) { return $pick($MINI($q), 'item'); };
    $pINST = $pick($INST, 'install');
    // the handlers' posts, as the page's forms send them (a key given as null is not posted)
    $manual = function (array $items, array $more = []) use ($MK): array {
        return array_merge(['action' => 'send_manual_quote', 'ucrm_quote_form' => $MK, 'mq_crm_client_id' => '17', 'mq_customer_name' => '',
                            'mq_customer_phone' => '', 'mq_create_crm' => '1', 'mq_note' => '', 'mq_items_json' => json_encode($items)], $more);
    };
    $lead = function (int $id, array $items, string $crm = '1', array $more = []) use ($MK): array {
        return array_merge(['action' => 'send_lead_quote', 'ucrm_quote_form' => $MK, 'lead_id' => (string)$id,
                            'quote_items_json' => json_encode($items), 'create_crm_quote' => $crm], $more);
    };
    $READ = ['GET /products?limit=500', 'GET /service-plans?limit=500'];   // a refusal's whole conversation with uCRM: the fresh read
    $SENT = function (int $client) use ($READ): array {
        return array_merge($READ, ["GET /clients/{$client}", 'POST /billing/quotes', 'PATCH /billing/quotes/N/send', 'GET /organizations']);
    };
    $PRICE = 'a price changed in uCRM since the page was opened — reload';
    $OFF   = 'This quotation page was opened under the uCRM catalogue, which an admin has since turned off — reload the page. Nothing was sent.';
    // a refusal: its flash, its requests, and nothing created, sent or logged
    $refused = function (array $S, string $flash, string $what, ?array $reqs = null) use ($c, $READ): void {
        $c->eq([$S['result']['flash'] ?? $S['result'], $S['requests'], $S['texts'], $S['quotes'], $S['events']],
            [[['msg' => $flash, 'type' => 'danger']], $reqs ?? $READ, [], [], []],
            $what . ' — no quotation made, no WhatsApp, no quotes_log row, no event');
    };
    // a send: the lines uCRM received, one quotes_log row of its type by the agent with those lines, one event of its type
    $sent = function (array $S, string $type, array $items, string $what) use ($c): void {
        $q = $S['quotes'][0] ?? [];
        $c->eq([$S['bodies'][0]['invoiceItems'] ?? null, count($S['quotes']), $q['type'] ?? null, $q['sent_by'] ?? null, $q['items'] ?? null, $S['events']],
            [$items, 1, $type, 'TEST Agent', $items, ['quote_' . $type]], $what);
    };
    $leadStatus = function (array $ctx, int $id): ?string {
        foreach ((array)(qcj_tables($ctx['data'], ['leads'])['leads'] ?? []) as $l) if ((int)$l['id'] === $id) return (string)($l['status'] ?? '');
        return null;
    };
    $touchCache = function (array $ctx): void { file_put_contents($ctx['data'] . '/' . CDE_CACHE, '{"TEST":"a page cache"}'); };
    $setFake = function (array $ctx, array $set): void { qcj_fake($ctx['fake'], '/__test/set', $set); };

    // ── E0 the positive control: picked lines reach uCRM at uCRM's labels and prices, from a fresh read ──────────────
    $D = cde_book($c, $trees['new'], ['records' => $REC]);
    $P1 = cde_measure($D, function () use ($D) { return cde_page($D, ['qmode' => 'lead']); });
    $P2 = cde_measure($D, function () use ($D) { return cde_page($D, ['qmode' => 'manual']); });
    $c->t(strpos($P1['result'], 'name="ucrm_quote_form" value="5.18.103-direct"') !== false && $P1['cache_after'],
        'E0 the page drew in direct mode (its marker), and kept its 60-second cache', substr($P1['result'], 0, 300));
    $M1 = cde_measure($D, function () use ($D, $manual, $pRES, $pKIT, $pINST) { return cde_post($D, $manual([$pRES, $pKIT(2), $pINST])); });
    $c->eq([$M1['result']['flash'][0]['type'] ?? null, $M1['bodies'][0]['invoiceItems'] ?? null], ['success', [$RES, $KIT(2), $INST]],
        'E0 the control: Send Quotation with a plan, two kits and the installation picked — uCRM gets each line as {productId, label, price, quantity, unit}',
        json_encode($M1['result']));
    $c->eq([$M1['cache_before'], $M1['requests']], [true, $SENT(17)],
        'E0 … read from uCRM fresh for the send although the page\'s cache was there, then the company check, the quotation and its send');
    $c->t((bool)preg_match('/^Plugin ref: MAN-\d{6}-\d{3} · Agent: TEST Agent$/u', (string)($M1['bodies'][0]['adminNotes'] ?? ''))
        && ($M1['bodies'][0]['notes'] ?? null) === 'TEST prefix for customers',
        'E0 … the Ref marker as before: notes the prefix alone, adminNotes "Plugin ref: MAN-… · Agent: TEST Agent"', json_encode($M1['bodies'][0] ?? null));
    $L1 = cde_measure($D, function () use ($D, $lead, $pRES, $pMINI) { return cde_post($D, $lead(1, [$pRES, $pMINI(1)])); });
    $c->eq([$L1['result']['flash'][0]['type'] ?? null, $L1['requests'], $L1['bodies'][0]['invoiceItems'] ?? null, $leadStatus($D, 1)],
        ['success', $SENT(16), [$RES, $MINI(1)], 'quoted'],
        'E0 the control: a lead quote with a plan and a kit picked — read fresh, quoted in uCRM with uCRM\'s lines, the lead marked quoted', json_encode($L1['result']));

    // ── E6 F12 / G-14: every send through sendLeadQuote / sendManualQuote, as before ────────────────────────────────
    $sent($M1, 'manual', [$RES, $KIT(2), $INST], 'E6 the manual send: one quotes_log row, type manual, sent_by the agent, uCRM\'s lines; the event quote_manual');
    $sent($L1, 'lead', [$RES, $MINI(1)], 'E6 the lead send: one quotes_log row, type lead, sent_by the agent; the event quote_lead');
    $c->t(count($M1['texts']) === 1 && $M1['texts'][0]['number'] === '256700000117' && strpos($M1['texts'][0]['text'], 'Standard Kit (TEST)') !== false,
        'E6 … and one WhatsApp to the customer, carrying uCRM\'s names', json_encode($M1['texts'], JSON_UNESCAPED_UNICODE));

    // ── E1 the posted label and price of a picked line never decide ─────────────────────────────────────────────────
    $X = cde_measure($D, function () use ($D, $manual, $pRES, $pKIT) { return cde_post($D, $manual([$pRES, $pKIT(1, ['price' => 1400])])); });
    $refused($X, 'Failed: ' . $PRICE, 'E1 a picked kit posted at 1400 (uCRM: 1500): refused "' . $PRICE . '"');
    $c->eq([$X['cache_before'], $X['cache_after']], [true, false], 'E1 … and the page cache is dropped, so the reload reads uCRM');
    $X = cde_measure($D, function () use ($D, $manual, $pKIT) { return cde_post($D, $manual([$pKIT(1, ['price' => null])])); });
    $refused($X, 'Failed: ' . $PRICE, 'E1 a picked kit posted with no price: refused the same');
    $X = cde_measure($D, function () use ($D, $manual, $pRES, $pKIT) { return cde_post($D, $manual([$pRES, $pKIT(1, ['label' => 'Hacked Kit (TEST)'])])); });
    $c->eq([$X['bodies'][0]['invoiceItems'] ?? null, strpos(json_encode($X), 'Hacked') === false], [[$RES, $KIT(1)], true],
        'E1 a picked kit posted with another label at uCRM\'s price: quoted with uCRM\'s label — the posted one is in no quotation, WhatsApp or log row');
    // the send reads uCRM itself, never the page's cache: the page drawn at 1500, uCRM now 1600
    cde_page($D, ['qmode' => 'manual']);
    $setFake($D, ['products' => cde_products([CD_KIT => ['price' => 1600]])]);
    $X = cde_measure($D, function () use ($D, $manual, $pKIT) { return cde_post($D, $manual([$pKIT(1)])); });
    $refused($X, 'Failed: ' . $PRICE, 'E1 uCRM\'s price changed after the page was drawn (1500 → 1600, the page\'s cache still 1500): the kit posted as drawn is refused');
    $X = cde_measure($D, function () use ($D, $manual, $pKIT) { return cde_post($D, $manual([$pKIT(1, ['price' => 1600])])); });
    $c->eq($X['bodies'][0]['invoiceItems'] ?? null, [$line(CD_KIT, 'Standard Kit (TEST)', 1600, 1, 'Pc')], 'E1 … control: posted at uCRM\'s new price, it is quoted at 1600');
    $setFake($D, ['products' => cde_products()]);

    // ── E2 a picked id that is not offered refuses the whole send, with its reason ──────────────────────────────────
    $touchCache($D);
    $X = cde_measure($D, function () use ($D, $manual, $pRES, $pick, $line) { return cde_post($D, $manual([$pRES, $pick($line(CD_ACC, 'Pipe Adapter (TEST)', 50, 1, 'Pc'), 'item')])); });
    $refused($X, 'Failed: Pipe Adapter (TEST) is kept off the forms by an admin', 'E2 a product kept off, picked beside a good plan: the whole send refused with its reason');
    $c->eq([$X['cache_before'], $X['cache_after']], [true, false], 'E2 … the page cache dropped');
    $bad = [
        [80, 'item', "Failed: Free Thing (TEST)'s price in uCRM is 0", 'a product priced 0'],
        [81, 'item', 'Failed: Old Kit (TEST) is archived in uCRM', 'an archived product'],
        [CD_INSTALL, 'item', 'Failed: Professional Installation is the installation product, so it cannot be quoted as hardware — add it as the installation', 'the installation picked as hardware'],
        [12, 'item', "Failed: Residential Standard (TEST) is a service plan's product in uCRM — add it from the plan list", 'a plan\'s product picked as hardware'],
        [CD_KIT, 'install', 'Failed: the installation product was changed on the uCRM catalogue screen since the page was opened — reload', 'a kit posted as the installation'],
    ];
    foreach ($bad as [$p, $kind, $flash, $what]) {
        $X = cde_measure($D, function () use ($D, $manual, $pRES, $p, $kind) {
            return cde_post($D, $manual([$pRES, ['kind' => $kind, 'p' => $p, 'q' => 1, 'label' => 'TEST as drawn', 'price' => 100]]));
        });
        $refused($X, $flash, "E2 {$what}: refused with its reason");
    }
    $X = cde_measure($D, function () use ($D, $manual, $pRES) { return cde_post($D, $manual([['sp' => 8] + $pRES])); });
    $refused($X, 'Failed: no uCRM product is spelled like the service plan Lonely Plan (TEST), so it cannot be a quotation line',
        'E2 a service plan that is not offered (no product spelled like it): refused with its reason');
    $X = cde_measure($D, function () use ($D, $manual, $pRES, $pKIT) { return cde_post($D, $manual([$pKIT(1, ['q' => 100]), $pRES])); });
    $refused($X, 'Failed: a line picked from the uCRM catalogue could not be read — reload the page', 'E2 a quantity of 100 (the limit is 99): refused before uCRM is asked', []);
    $X = cde_measure($D, function () use ($D, $lead, $pRES, $pick, $line) { return cde_post($D, $lead(3, [$pRES, $pick($line(CD_ACC, 'Pipe Adapter (TEST)', 50, 1, 'Pc'), 'item')], '0')); });
    $refused($X, 'Quote failed: Pipe Adapter (TEST) is kept off the forms by an admin', 'E2 the lead quote with a product kept off: refused the same');
    $c->eq($leadStatus($D, 3), 'open', 'E2 … the lead stays open');

    // ── E5 the handlers: a picked line is never dropped for its posted price ────────────────────────────────────────
    $X = cde_measure($D, function () use ($D, $manual, $pRES, $pKIT) { return cde_post($D, $manual([['price' => 0] + $pRES, $pKIT(1)])); });
    $refused($X, 'Failed: ' . $PRICE, 'E5 Send Quotation: a picked plan posted at 0 beside a good kit reaches the re-pricing and is refused — never dropped, which would send the kit alone');
    $X = cde_measure($D, function () use ($D, $lead, $pRES) { return cde_post($D, $lead(2, [['price' => 0, 'label' => ''] + $pRES], '0')); });
    $refused($X, 'Quote failed: ' . $PRICE, 'E5 the lead quote: a picked plan posted at 0 with no label reaches the re-pricing and is refused — never dropped, which would build the lead\'s own plan in its place');
    $X = cde_measure($D, function () use ($D, $manual, $pRES) { return cde_post($D, $manual([['price' => ' '] + $pRES])); });
    $refused($X, 'Failed: ' . $PRICE, 'E5 a picked plan posted with a blank price: refused the same');

    // ── E3 + E4 the mobile API's two methods (api/index.php), re-pricing as the page does; typed lines lose productId ─
    $A = cde_measure($D, function () use ($D, $pRES) {
        return cde_api($D, 'send_manual_quote', ['crm_client_id' => 17, 'create_crm_quote' => true,
            'items' => [$pRES, ['label' => 'Cable (TEST)', 'quantity' => 2, 'price' => 25, 'unit' => 'm', 'productId' => CD_KIT]]]);
    });
    $c->eq([$A['result']['ok'] ?? null, $A['requests'], $A['bodies'][0]['invoiceItems'] ?? null],
        [true, $SENT(17), [$RES, ['label' => 'Cable (TEST)', 'quantity' => 2, 'price' => 25, 'unit' => 'm']]],
        'E3 the API\'s manual quote: the picked plan re-read from uCRM, the typed line sent as typed — its productId (55) dropped (G-15)', json_encode($A['result']));
    $sent($A, 'manual', [$RES, ['label' => 'Cable (TEST)', 'quantity' => 2, 'price' => 25, 'unit' => 'm']], 'E6 the API\'s manual send: one quotes_log row, type manual, the event quote_manual');
    $A = cde_measure($D, function () use ($D, $pRES) {
        return cde_api($D, 'send_manual_quote', ['crm_client_id' => 17, 'create_crm_quote' => true,
            'items' => [$pRES, ['label' => 'Bracket (TEST)', 'quantity' => 1, 'price' => 0, 'unit' => 'piece', 'productId' => CD_KIT]]]);
    });
    $c->eq([$A['result'], $A['requests'], $A['texts'], $A['quotes'], $A['events']],
        [['ok' => false, 'code' => 422, 'error' => 'Bracket (TEST) has no price — enter one or remove it'], $READ, [], [], []],
        'E3 … a typed line at 0 beside a picked plan: still refused by 5.18.102\'s price-0 rule, naming it — nothing made, sent or logged');
    $A = cde_measure($D, function () use ($D, $pKIT) { return cde_api($D, 'send_manual_quote', ['crm_client_id' => 17, 'create_crm_quote' => true, 'items' => [$pKIT(1, ['price' => 1400])]]); });
    $c->eq([$A['result'], $A['requests'], $A['texts'], $A['quotes'], $A['events']], [['ok' => false, 'code' => 422, 'error' => $PRICE], $READ, [], [], []],
        'E4 the API\'s manual quote with a picked kit at 1400: 422 "' . $PRICE . '", nothing made, sent or logged');
    $A = cde_measure($D, function () use ($D, $pINST) { return cde_api($D, 'send_lead_quote', ['lead_id' => 1, 'create_crm_quote' => true, 'items' => [['price' => 150] + $pINST]]); });
    $c->eq([$A['result'], $A['requests'], $A['texts'], $A['quotes'], $A['events']], [['ok' => false, 'code' => 422, 'error' => $PRICE], $READ, [], [], []],
        'E4 the API\'s lead quote with the installation posted at 150 (uCRM: 200): 422, nothing made, sent or logged');
    $A = cde_measure($D, function () use ($D, $pick, $line) { return cde_api($D, 'send_lead_quote', ['lead_id' => 1, 'create_crm_quote' => true,
        'items' => [$pick($line(CD_ACC, 'Pipe Adapter (TEST)', 50, 1, 'Pc'), 'item')]]); });
    $c->eq([$A['result'], $A['quotes']], [['ok' => false, 'code' => 422, 'error' => 'Pipe Adapter (TEST) is kept off the forms by an admin'], []],
        'E4 the API\'s lead quote with a product kept off: 422 with its reason, no log row');
    $A = cde_measure($D, function () use ($D, $pBIZ, $pMINI) { return cde_api($D, 'send_lead_quote', ['lead_id' => 1, 'create_crm_quote' => true, 'items' => [$pBIZ, $pMINI(3)]]); });
    $c->eq([$A['result']['ok'] ?? null, $A['requests']], [true, $SENT(16)], 'E4 control: the API\'s lead quote with uCRM\'s prices is quoted', json_encode($A['result']));
    $sent($A, 'lead', [$BIZ, $MINI(3)], 'E6 the API\'s lead send: uCRM\'s lines, one quotes_log row, type lead, the event quote_lead');

    // ── E7 buildLeadItems in direct mode: the one offered plan keyed exactly like the interest, else nothing ─────────
    $B = [];
    foreach ([1, 2, 3, 4, 5, 6, 7] as $id) $B[$id] = cde_measure($D, function () use ($D, $id) { return cde_build($D, $id); });
    $c->eq([$B[1]['result'], $B[1]['requests']], [[$RES], $READ], 'E7 a lead for "Residential Standard (TEST)": the offered plan of that key, read fresh');
    $c->eq($B[2]['result'], [$RES], 'E7 … "residential-standard test", spelled otherwise with the same key: the same line (a key, never a name)');
    $c->eq($B[7]['result'], [$BIZ], 'E7 … "Business Plus (TEST)": its own plan');
    $c->eq([$B[3]['result'], $B[3]['requests']], [[], $READ], 'E7 "Lonely Plan (TEST)", a uCRM service plan not offered (no product spelled like it): no line');
    $c->eq([$B[4]['result'], $B[4]['requests']], [[], []], 'E7 a lead naming no plan: no line, and uCRM is not asked');
    $c->eq($B[5]['result'], [], 'E7 "TEST Plan", the plugin\'s plan row (linked in 5.18.102 to product 12): no line — direct mode reads no plugin row and no link (control: E10, the switch off)');
    $c->eq($B[6]['result'], [], 'E7 "Plus (TEST)", contained in "Business Plus (TEST)" but not its key: no line');
    $setFake($D, ['service_plans' => cd_plans([9 => ['id' => 9, 'name' => 'Business-Plus TEST', 'periods' => [['period' => 1, 'price' => 300, 'enabled' => true]], 'downloadSpeed' => 100]])]);
    $c->eq(cde_build($D, 7), [], 'E7 two uCRM service plans spelled like "Business Plus (TEST)": neither offered, no line');
    $setFake($D, ['service_plans' => cd_plans(), 'products' => cde_products([18 => ['id' => 18, 'name' => 'Business Plus TEST', 'price' => 300, 'unit' => 'Month', 'taxable' => false]])]);
    $c->eq(cde_build($D, 7), [], 'E7 two uCRM products spelled like that plan: not offered, no line');
    $setFake($D, ['products' => cde_products()]);
    $X = cde_measure($D, function () use ($D, $lead) { return cde_post($D, $lead(2, [], '0')); });
    $c->eq([$X['result']['flash'][0]['type'] ?? null, $X['requests'], $X['quotes'][0]['items'] ?? null, $X['quotes'][0]['type'] ?? null, $X['events']],
        ['success', array_merge($READ, ['GET /organizations']), [$RES], 'lead', ['quote_lead']],
        'E7 a lead quote with no items for lead 2 (no uCRM client): its line built from the fresh read — uCRM\'s plan, logged as a lead quote; uCRM asked only for the letterhead', json_encode($X['result']));
    $X = cde_measure($D, function () use ($D, $lead) { return cde_post($D, $lead(5, [], '0')); });
    $refused($X, 'Quote failed: No pricing items could be determined for this lead. Please add items manually.',
        'E7 a lead quote with no items for "TEST Plan" (no offered plan of its key): the items asked for by hand');

    // ── E8 the page in direct mode ────────────────────────────────────────────────────────────────────────────────
    $hL = (string)$P1['result'];
    $hM = (string)$P2['result'];
    $plansWant = [['6:12', 'Residential Standard (TEST) — UGX 100 / month · 100 Mbps', false], ['7:17', 'Business Plus (TEST) — UGX 300 / month · 100 Mbps', false]];
    $hwWant = [['item:56', 'Mini Kit (TEST) — UGX 900 / Pc', false], ['item:55', 'Standard Kit (TEST) — UGX 1,500 / Pc', false],
               ['item:91', 'Kit &lt;b&gt;Pro&lt;/b&gt; &amp; &quot;Co&quot; (TEST) — UGX 2,000 / Pc', false], ['install:70', 'Professional Installation — UGX 200 / Job', false]];
    foreach (['lead' => $hL, 'manual' => $hM] as $mode => $h) {
        $c->eq([cde_options($h, '— Add a plan from uCRM —'), cde_options($h, '— Add hardware or the installation from uCRM —')], [[$plansWant], [$hwWant]],
            "E8 the {$mode} form's pickers: the two offered plans (value sp:p); the offered hardware and the installation — never the product kept off, priced 0, archived, or a plan's product");
    }
    $c->t(substr_count($hM, '<form method="post" id="frmManual">') === 1 && strpos(substr($hM, (int)strpos($hM, 'id="frmManual"')), 'name="ucrm_quote_form" value="5.18.103-direct"') !== false
        && strpos(substr($hL, (int)strpos($hL, 'id="frmLeadQuote"')), 'name="ucrm_quote_form" value="5.18.103-direct"') !== false,
        'E8 each form carries the marker ucrm_quote_form = 5.18.103-direct');
    $c->t(strpos($hM, '<button type="button" class="sq-btn sq-btn-ghost sq-btn-sm" onclick="sqDirectStandard(\'manual\', 0)">Mini + Residential (TEST) — UGX 1,200</button>') !== false,
        'E8 a usable standard quotation: one button, its title and live total (100 + 900 + 200)');
    $c->t(strpos($hM, 'onclick="sqDirectStandard(\'manual\', 1)" disabled>Old Kit bundle (TEST)</button>') !== false
        && strpos($hM, 'Old Kit bundle (TEST) cannot be used: a hardware item in it (uCRM product #81) is not offered now — see the uCRM catalogue screen') !== false,
        'E8 a standard quotation whose kit uCRM archived: its button disabled, and the reason said (never a partial bundle)');
    $SQ = cde_const($hM, 'SQ_DIRECT');
    $c->eq(cde_sorted([$SQ['standards'][0]['lines'] ?? null, $SQ['standards'][1]['ok'] ?? null, $SQ['standards'][1]['lines'] ?? null, $SQ['marker'] ?? null, $SQ['max_qty'] ?? null]),
        cde_sorted([[array_diff_key($pRES, ['quantity' => 1]), array_diff_key($pMINI(1), ['quantity' => 1]) + ['sp' => null], array_diff_key($pINST, ['quantity' => 1]) + ['sp' => null]],
                    false, [], $MK, 99]),
        'E8 the standard quotation\'s lines, as the page posts them once pressed: the plan by sp:p, the kit, the installation, at uCRM\'s prices');
    preg_match('/^const SQ_DIRECT = .*$/m', $hM, $sqLine);
    $c->t(strpos($hM, '<b>Pro</b>') === false && isset($sqLine[0]) && strpos($sqLine[0], trim(json_encode('<b>Pro', JSON_HEX_TAG), '"')) !== false,
        'E8 uCRM\'s markup never reaches the page as HTML: escaped in the lists, hex-escaped (JSON_HEX_TAG) in the script');
    $c->t(strpos($hM, "const PLANS = [];") !== false && strpos($hM, 'so the plan list shows no prices') === false,
        'E8 5.18.102\'s plan picker is not read (PLANS empty), nor its banner drawn');
    // uCRM down, the last good list kept: drawn from it, said to be out of date
    @unlink($D['data'] . '/' . CDE_CACHE);
    $setFake($D, ['products_down' => true]);
    $St = cde_measure($D, function () use ($D) { return cde_page($D, ['qmode' => 'manual']); });
    $hs = (string)$St['result'];
    $c->eq([strpos($hs, 'uCRM could not be read just now, so the uCRM lists below are the last ones read and may be out of date') !== false,
            cde_options($hs, '— Add a plan from uCRM —'), $St['requests']], [true, [$plansWant], ['GET /products?limit=500']],
        'E8 uCRM down with a last good list: the banner says the lists may be out of date; the lists are the last ones read');
    $X = cde_measure($D, function () use ($D, $manual, $pRES) { return cde_post($D, $manual([$pRES])); });
    $refused($X, "Failed: uCRM's products could not be read", 'E8 … and a send then is refused — every picked line is read from uCRM again', ['GET /products?limit=500']);
    @unlink($D['data'] . '/' . CDE_CACHE);
    @unlink($D['data'] . '/' . CDE_LAST_GOOD);
    $hn = cde_page($D, ['qmode' => 'manual']);
    $c->t(strpos($hn, "uCRM&#039;s products could not be read, so nothing can be picked from uCRM.") !== false
        && cde_options($hn, '— Add a plan from uCRM —') === [] && strpos($hn, 'Nothing can be picked from uCRM right now — type the items yourself.') !== false
        && strpos($hn, 'onclick="sqDirectStandard(\'manual\', 0)" disabled>Mini + Residential (TEST)</button>') !== false,
        'E8 uCRM down with no last good list: the banner, nothing to pick, the standard quotation disabled', substr($hn, (int)strpos($hn, 'sq-direct'), 600));
    $c->t(strpos($hs, 'nothing can be picked from uCRM') === false && strpos($hn, 'may be out of date') === false && strpos($hM, 'may be out of date') === false,
        'E8 control: each banner only in its own state');
    $setFake($D, ['products_down' => false]);
    // the standard quotation's lines as drawn, posted through the handler: one quotation, one row, the existing event
    $X = cde_measure($D, function () use ($D, $manual, $SQ) { return cde_post($D, $manual((array)($SQ['standards'][0]['lines'] ?? []))); });
    $sent($X, 'manual', [$RES, $MINI(1), $INST], 'E8 the standard quotation\'s lines as drawn, sent: uCRM\'s three lines, one quotes_log row (manual), the event quote_manual (G-14)');

    // ── E9 the page in a browser: a standard quotation pressed, a lead picked, posted, quoted ───────────────────────
    $stepsM = [
        <<<'JS'
(() => ({ rows: document.querySelectorAll('#manualItemsList .sq-item-row').length,
          marks: Array.from(document.querySelectorAll('#manualItemsList .sq-typed-mark')).map(e => e.textContent),
          marker: (document.querySelector('#frmManual input[name="ucrm_quote_form"]') || {}).value || null }))()
JS,
        <<<'JS'
(() => { const b = Array.from(document.querySelectorAll('#frmManual .sq-direct button'));
  const s1 = b.find(x => x.textContent.indexOf('Mini + Residential (TEST)') === 0);
  const s2 = b.find(x => x.textContent.indexOf('Old Kit bundle (TEST)') === 0);
  if (!s1 || !s2) return { missing: b.map(x => x.textContent) };
  s1.click();
  const after = document.getElementById('manualItemsJson').value;
  s2.click();
  return { s1: !s1.disabled, s2: s2.disabled, json: JSON.parse(after), same: document.getElementById('manualItemsJson').value === after,
           shown: Array.from(document.querySelectorAll('#manualItemsList .sq-item-row')).map(r => r.querySelector('span').textContent),
           inputs: Array.from(document.querySelectorAll('#manualItemsList .sq-item-row')).map(r => Array.from(r.querySelectorAll('input')).map(i => i.className)),
           total: document.getElementById('manualTotal').textContent }; })()
JS,
        <<<'JS'
(() => { const hw = document.querySelectorAll('#frmManual .sq-direct select')[1];
  hw.value = 'item:55'; hw.dispatchEvent(new Event('change'));
  hw.value = 'item:55'; hw.dispatchEvent(new Event('change'));
  return { reset: hw.value, lines: JSON.parse(document.getElementById('manualItemsJson').value).filter(l => l.p === 55) }; })()
JS,
        <<<'JS'
(() => { const row = Array.from(document.querySelectorAll('#manualItemsList .sq-item-row.sq-picked')).find(r => r.textContent.indexOf('Standard Kit (TEST)') !== -1);
  const q = row.querySelector('.qty'); q.value = '150'; q.dispatchEvent(new Event('input'));
  const sent = JSON.parse(document.getElementById('manualItemsJson').value).find(l => l.p === 55);
  return [sent.q, sent.quantity, q.value]; })()
JS,
        <<<'JS'
(() => { addItem('manual', 'Cable (TEST)', 25, 2, 'm');
  return { typed: JSON.parse(document.getElementById('manualItemsJson').value).filter(l => !('kind' in l)),
           marks: document.querySelectorAll('#manualItemsList .sq-typed-mark').length }; })()
JS,
    ];
    $stepsL = [
        <<<'JS'
(() => { const s = document.getElementById('leadPicker'); const out = {};
  for (const id of ['1', '2', '3', '5', '6']) { s.value = id; onLeadPick(s);
    const h = document.getElementById('leadPlanHint');
    out[id] = { json: JSON.parse(document.getElementById('leadItemsJson').value), hint: h && h.style.display !== 'none' ? h.textContent : null }; }
  s.value = '1'; onLeadPick(s);
  out.json1 = JSON.parse(document.getElementById('leadItemsJson').value);
  out.marker = (document.querySelector('#frmLeadQuote input[name="ucrm_quote_form"]') || {}).value || null;
  return out; })()
JS,
    ];
    $br = cde_browser($c, [['html' => $hM, 'steps' => $stepsM], ['html' => $hL, 'steps' => $stepsL]]);
    if (!$br['ran']) {
        echo "  note E limitation: {$br['why']}; the page was not driven in a browser (E9) — a stated limitation, not a pass\n";
    } else {
        $pm = $br['pages'][0] ?? [];
        $pl = $br['pages'][1] ?? [];
        $rm = (array)($pm['results'] ?? []);
        $rl = (array)($pl['results'][0] ?? []);
        $c->eq([$br['error'], $pm['errors'] ?? null, $pm['dialogs'] ?? null, $pl['errors'] ?? null, $pl['dialogs'] ?? null], [null, [], [], [], []],
            'E9 the two pages ran in Chromium without an error or a dialog');
        $c->eq($rm[0] ?? null, ['rows' => 1, 'marks' => ['typed — not from uCRM'], 'marker' => $MK],
            'E9 the manual form opens with one typed row, marked "typed — not from uCRM" on the page, and its marker');
        $std = $rm[1] ?? [];
        $c->eq(cde_sorted([$std['s1'] ?? null, $std['s2'] ?? null, $std['json'] ?? null, $std['same'] ?? null, $std['shown'] ?? null, $std['inputs'] ?? null, $std['total'] ?? null]),
            cde_sorted([true, true, [$pRES, $pMINI(1), $pINST], true, ['Residential Standard (TEST)', 'Mini Kit (TEST)', 'Professional Installation'], [['qty'], ['qty'], ['qty']], '1200.00']),
            'E9 pressing the standard quotation fills its three lines — uCRM\'s names, read-only, each with its uCRM ids and drawn price; the disabled one does nothing',
            json_encode($std, JSON_UNESCAPED_UNICODE));
        $c->eq(cde_sorted($rm[2] ?? null), cde_sorted(['reset' => '', 'lines' => [$pKIT(2)]]), 'E9 a kit picked twice from the hardware list: one line, quantity 2');
        $c->eq($rm[3] ?? null, [99, 99, '99'], 'E9 a quantity typed as 150 is sent as 99, and the box shows it');
        $c->eq(cde_sorted($rm[4] ?? null), cde_sorted(['typed' => [['label' => 'Cable (TEST)', 'quantity' => 2, 'price' => 25, 'unit' => 'm']], 'marks' => 1]),
            'E9 a line typed by hand is marked on the page, and posted as typed — no mark in its label, no uCRM id');
        $c->eq(cde_sorted([$rl['1'] ?? null, $rl['2'] ?? null, $rl['json1'] ?? null, $rl['marker'] ?? null]),
            cde_sorted([['json' => [$pRES], 'hint' => null], ['json' => [$pRES], 'hint' => null], [$pRES], $MK]),
            'E9 picking lead 1, and lead 2 (spelled otherwise, the same key): the offered plan of its key, as a picked line');
        $c->eq([$rl['3'] ?? null, $rl['5']['json'] ?? null, $rl['6']['json'] ?? null],
            [['json' => [], 'hint' => 'No plan offered from uCRM is spelled like this lead\'s interest (Lonely Plan (TEST)), so no line was added — pick one below, or add the items yourself.'], [], []],
            'E9 lead 3 (no offered plan of its key): no line, and the hint says why; leads 5 ("TEST Plan", a plugin row) and 6 ("Plus (TEST)"): no line');
        // what the browser built, posted through the handlers as the forms post it (the marker as the page drew it)
        $mkM = is_string($rm[0]['marker'] ?? null) ? $rm[0]['marker'] : null;
        $X = cde_measure($D, function () use ($D, $manual, $std, $mkM) { return cde_post($D, $manual((array)($std['json'] ?? []), ['ucrm_quote_form' => $mkM])); });
        $sent($X, 'manual', [$RES, $MINI(1), $INST], 'E9 … the standard quotation pressed in the browser, posted: quoted in uCRM with its three lines, one quotes_log row, quote_manual');
        $mkL = is_string($rl['marker'] ?? null) ? $rl['marker'] : null;
        $X = cde_measure($D, function () use ($D, $lead, $rl, $mkL) { return cde_post($D, $lead(1, (array)($rl['json1'] ?? []), '1', ['ucrm_quote_form' => $mkL])); });
        $sent($X, 'lead', [$RES], 'E9 … lead 1\'s line from the browser, posted: quoted in uCRM, one quotes_log row, quote_lead');
    }

    // ── E10 the switch off and South Sudan: 1a0ea5f's, byte for byte; a direct page's post refused or ignored ──────
    if ($trees['old'] === null) return;
    $typed = ['label' => 'Cable (TEST)', 'quantity' => 2, 'price' => 25, 'unit' => 'm'];
    $run = function (string $tree, string $scen) use ($c, $REC, $S1, $manual, $lead, $typed, $pRES, $pBIZ): array {
        $o = ['off' => ['records' => cd_records(false, CD_INSTALL, [CD_ACC], [$S1])], 'none' => [],
              'ss' => ['tenant' => 'south-sudan'], 'ss_on' => ['tenant' => 'south-sudan', 'records' => $REC]][$scen];
        $ctx = cde_book($c, $tree, $o);
        $st = [];
        $st['P_lead']   = cde_measure($ctx, function () use ($ctx) { return cde_page($ctx, ['qmode' => 'lead']); });
        $st['P_manual'] = cde_measure($ctx, function () use ($ctx) { return cde_page($ctx, ['qmode' => 'manual']); });
        $st['T1'] = cde_measure($ctx, function () use ($ctx, $manual, $typed) { return cde_post($ctx, $manual([$typed], ['ucrm_quote_form' => null])); });
        $st['T2'] = cde_measure($ctx, function () use ($ctx, $typed) {
            return cde_api($ctx, 'send_manual_quote', ['crm_client_id' => 17, 'create_crm_quote' => true, 'items' => [$typed + ['productId' => CD_KIT]]]);
        });
        $st['T3'] = cde_measure($ctx, function () use ($ctx, $lead) { return cde_post($ctx, $lead(5, [], '0', ['ucrm_quote_form' => null])); });
        $st['B5'] = cde_measure($ctx, function () use ($ctx) { return cde_build($ctx, 5); });
        if ($scen === 'ss' || $scen === 'ss_on') {   // South Sudan: a direct page's marker is read as no marker at all
            $st['T4m'] = cde_measure($ctx, function () use ($ctx, $manual, $pRES) { return cde_post($ctx, $manual([$pRES])); });
            $st['T4l'] = cde_measure($ctx, function () use ($ctx, $lead, $pBIZ) { return cde_post($ctx, $lead(7, [$pBIZ], '0')); });
        }
        $out = ['steps' => $st, 'tables' => qcj_tables($ctx['data'], ['leads', 'quotes_log', 'ucrm_catalogue']),
                '_paths' => [$ctx['data'] => 'DATA', $ctx['dir'] => 'WORK', $tree => 'TREE']];
        return [$out, $ctx];
    };
    $cmp = function (array $a, array $b): string {
        $pa = $a['_paths']; $pb = $b['_paths']; unset($a['_paths'], $b['_paths']);
        return qfu_first_diff(qfu_norm($a, $pa), qfu_norm($b, $pb));
    };
    $news = [];
    foreach (['off' => 'Uganda, the switch off (installation, kept-off and a standard quotation stored)', 'none' => 'Uganda, no uCRM catalogue table (the deploy\'s state)',
              'ss' => 'South Sudan', 'ss_on' => 'South Sudan with the switch-on records stored'] as $scen => $what) {
        [$a] = $run($trees['old'], $scen);
        [$b, $ctx] = $run($trees['new'], $scen);
        $news[$scen] = [$b, $ctx];
        $nReq = 0; foreach ($a['steps'] as $s) $nReq += count($s['requests']);
        $nTxt = 0; foreach ($a['steps'] as $s) $nTxt += count($s['texts']);
        $d = $cmp($a, $b);
        $c->t($d === '' && $nReq >= 4 && $nTxt >= 3, "E10 {$what}: both pages, every uCRM request, WhatsApp text and store row as 1a0ea5f's ({$nReq} requests, {$nTxt} texts)", $d);
    }
    [$off, $offCtx] = $news['off'];
    $c->eq([$off['steps']['T2']['result']['ok'] ?? null, $off['steps']['T2']['bodies'][0]['invoiceItems'] ?? null], [true, [$typed + ['productId' => CD_KIT]]],
        'E10 control for E3: with the switch off the API\'s typed line keeps its productId 55, as 5.18.102 sends it');
    $c->eq([$off['steps']['B5']['result'], $news['none'][0]['steps']['B5']['result']], [[$RES], [$RES]],
        'E10 control for E7: with the switch off, the lead naming the plugin row "TEST Plan" gets that row\'s linked uCRM line, as 5.18.102 does');
    $c->eq([strpos((string)$off['steps']['P_manual']['result'], 'ucrm_quote_form') === false, strpos((string)$off['steps']['P_manual']['result'], 'sqDirect') === false],
        [true, true], 'E10 the switch off: the page carries no marker and none of direct mode\'s script');
    $c->eq([$news['ss'][0]['tables']['ucrm_catalogue'], ($news['ss_on'][0]['steps']['T4m']['bodies'][0]['invoiceItems'] ?? null)],
        [null, [['label' => 'Residential Standard (TEST)', 'quantity' => 1, 'price' => 100, 'unit' => 'month']]],
        'E10 South Sudan: no read creates the uCRM catalogue\'s table, and a direct page\'s line is read as 5.18.102 reads a typed one (marker ignored)');
    // a page drawn under the switch, posted after it was turned off — or where there is no table: refused, nothing sent
    foreach (['off' => 'the switch off', 'none' => 'no uCRM catalogue table'] as $scen => $what) {
        $ctx = $news[$scen][1];
        $X = cde_measure($ctx, function () use ($ctx, $manual, $pRES) { return cde_post($ctx, $manual([$pRES])); });
        $refused($X, 'Failed: ' . $OFF, "E10 {$what}: a direct page's Send Quotation refused (\"…turned off — reload the page. Nothing was sent.\") before anything is read or sent", []);
        $X = cde_measure($ctx, function () use ($ctx, $lead, $pRES) { return cde_post($ctx, $lead(7, [$pRES], '0')); });
        $refused($X, 'Quote failed: ' . $OFF, "E10 {$what}: a direct page's lead quote refused the same", []);
        $c->eq($news[$scen][0]['steps']['T1']['result']['flash'][0]['type'] ?? null, 'success', "E10 … control: there the same handler sends a post without the marker");
    }
    // F12: the quotes_log row of a direct send has the shape 5.18.102's has
    $k = function ($r) { $r = (array)$r; unset($r['__id']); $k = array_keys($r); sort($k); return $k; };
    $c->eq($k($M1['quotes'][0] ?? []), $k($off['steps']['T1']['quotes'][0] ?? ['none']), 'E6 a direct send\'s quotes_log row carries exactly the fields of a 5.18.102 send\'s');
    // the control on the comparison: with the switch on the page is not 1a0ea5f's
    $O = cde_book($c, $trees['old'], ['records' => $REC]);
    $hO = cde_page($O, ['qmode' => 'manual']);
    $c->t($hO !== '' && strpos($hO, 'NO OUTPUT') !== 0 && strpos($hO, 'ucrm_quote_form') === false && qfu_first_diff(qfu_norm($hO, [$O['data'] => 'DATA']), qfu_norm($hM, [$D['data'] => 'DATA'])) !== '',
        'E10 the control on the comparison: with the switch on, the page differs from 1a0ea5f\'s (which ignores the uCRM catalogue)');
}];
