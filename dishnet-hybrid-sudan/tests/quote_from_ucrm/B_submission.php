<?php
declare(strict_types=1);
/**
 * B — the KYC submission on Uganda (docs/71 §F.2, §F.3, §G, §H), through the real KycService::process() in a child
 * process, against fixtures/fake_ucrm_kyc.php with its TEST catalogue on. TEST ONLY.
 *
 * 5.18.102 D14 (docs/71 §R.9.1), rewritten on purpose: the seed is linked (tests/quote_from_ucrm/_lib.php: the plan row
 * "Home Plan (TEST)" → 12 and 6, the kit row → 55, the installation → 70), every post carries the web form's marker unless
 * it says otherwise, and a Cash post the record ids the form drew (ucrm_links_seen). The unpriced cases are made by
 * unlinking, or by deleting the linked product, no longer by removing the product spelled like the row; CASH_UNPRICED
 * ends "Register as Credit, or ask an admin."; quote_link_ids and quote_sale_entry are stored. New: B13 the links the
 * form drew (§R.4.7), B14 the entry and package reasons end at their cause (E-3), B15 P1_UNLINKED (§R.4.1).
 */

$QFU_SECTIONS['B'] = ['title' => 'The KYC submission: the cash of §H, the order of §F.2, the stored lines of §F.3, the posted total of §G, the links the form drew', 'run' => function (QfuCtx $c) {
    $fresh = function (array $set = [], string $scenario = 'uganda') use ($c) {
        $c->ctl('kyc', '/__test/reset?scenario=' . $scenario);
        $c->ctl('kyc', '/__test/set', ['catalogue' => true] + $set);
    };
    $wallet = [7 => 100000];
    $req = fn() => $c->requests('kyc');
    $quotes = fn() => $c->bodies('kyc', 'POST', '/clients/\d+/quotes');
    $payments = fn() => $c->bodies('kyc', 'POST', '/payments');
    $writes = fn() => array_values(array_filter($req(), fn($r) => strpos($r, 'GET ') !== 0));
    $app = function (array $r): array { $a = (array)($r['value']['apps'] ?? []); return $a === [] ? [] : (array)end($a); };
    // the default links (_lib.php) without one record: 2 the kit's, 3 the installation's
    $without = fn(int $id) => ['links' => array_values(array_filter(qfu_links(), fn($l) => $l['id'] !== $id))];
    // the record ids the form draws for the default sale: the plan, the kit, and the installation when DishNet installs
    $SEEN = '1,2,3';
    $NOT_LINKED = fn(string $row, string $screen) => "{$row} is not linked to a uCRM product yet — an admin links it on the {$screen} screen";
    // §R.4.1's texts, as the design gives them (never read from the code under test)
    $CU = fn(string $reason) => "No usable price in uCRM: {$reason}. Nothing was saved and no money was taken. Register as Credit, or ask an admin.";
    $RELINKED = 'An admin changed which uCRM product an item on this sale is linked to since the form was opened — reload the form. Nothing was saved and no money was taken.';
    $ENTRY = 'this sale did not come from the KYC form of this release (the retailer app, the mobile API, a form opened before the update, or an application saved before it), so it is not priced from uCRM';
    $P1_OWN = 'Make the quotation in uCRM (Send Quotation, or ask an admin).';
    $P1_UNLINKED = 'Do not make it by hand — once the item is linked, an admin makes it from the Orders card.';
    $PKG = ['packages' => [['id' => 9, 'name' => 'App Package (TEST)', 'amount' => 80, 'created_at' => '2026-10-01 09:20:00']]];
    $seed901 = ['clients' => [['id' => 901, 'organizationId' => 1, 'firstName' => 'Test', 'lastName' => 'Existing', 'contacts' => []]]];

    // B0 — the control: a priced Credit sale, quoted from uCRM's lines, in the order of §F.2
    $fresh();
    $served = $c->child('return $crm->get("products?limit=500");');
    $c->eq($served['value'], qfu_kyc_products(), 'B0 the fixture: the fake serves the TEST catalogue _lib.php describes — 13 spelled like the plan row, 54 the kit\'s twin, neither linked');
    // the service-plan list the summary reads (service-plans?limit=200, every row): the linked plan 6's key held once,
    // and no row keyed like the kit's or the installation's product (rules 6a and 7 judge this whole list)
    $sp = $c->child('return $crm->get("service-plans?limit=200");');
    $keys = [];
    foreach ((array)$sp['value'] as $row) $keys[] = preg_replace('/[^a-z0-9]+/', '', preg_replace('/\bstarlink\b/', '', strtolower((string)($row['name'] ?? ''))));
    $c->eq([count((array)$sp['value']), array_count_values($keys)['residentialstandardtest'] ?? 0, in_array('standardkittest', $keys, true), in_array('professionalinstallation', $keys, true)],
        [6, 1, false, false], 'B0 the fixture: the summary\'s service-plan list (27 Sep\'s five and plan 6) keys the linked plan once, and nothing like the kit or the installation');
    $fresh();
    $r = $c->submit(['install_by' => 'dishnet'], ['wallet' => $wallet]);
    $v = $r['value'];
    $q = $quotes()[0] ?? [];
    $c->t(!empty($v['success']) && count($quotes()) === 1, 'B0 the control: a Credit Starlink New Connection, DishNet installs, is registered and quoted', json_encode([$v['message'] ?? $r['out']]));
    $c->eq(array_map(fn($i) => [$i['productId'] ?? null, $i['label'], $i['price'], $i['quantity'], $i['unit']], $q['items'] ?? []),
        [[12, 'Residential Standard (TEST)', 100, 1, 'month'], [55, 'Standard Kit (TEST)', 1500, 1, 'Pc'], [70, 'Professional Installation', 200, 1, 'Job']],
        'B0 … the lines are the linked products\': ids, names, prices, units — not the plan row\'s name "Home Plan (TEST)" (nor product 13, spelled like it), not the kit\'s twin 54, not the plugin\'s 90 nor "USD 1"');
    $c->eq([$q['notes'] ?? null, $q['adminNotes'] ?? null], ['', 'KYC application #1 · registration · Sales: Tester · Connection: New Connection · Priority: Low · Install: DishNet'],
        'B0 … the customer\'s notes are empty; who sold it, the connection, priority and installation are in adminNotes; no PENDING');
    $c->eq($req(), ['GET /products', 'GET /service-plans', 'GET /clients', 'GET /organizations', 'GET /custom-attributes', 'POST /clients',
                    'POST /clients/901/quotes', 'PATCH /billing/quotes/77/send'],
        'B0 … in order: the catalogue first (once), then the phone check, the create, the quote');

    // B1 — §H on a new customer
    $fresh();
    $r = $c->submit(['package_choice' => '42', 'sales_type' => 'Cash', 'install_by' => 'dishnet', 'ucrm_total_seen' => '1800', 'ucrm_links_seen' => $SEEN], ['wallet' => $wallet]);
    $c->eq([$r['value']['success'], $r['value']['message'], count($r['value']['apps']), $req()], [false, 'Please select a valid package/plan.', 0, []],
        'B1 no plan row: refused as today, before uCRM is asked anything');
    // rewritten by D14 (§R.9.1): unpriced by unlinking the kit, and by deleting its linked product — no longer by removing
    // the product spelled like the row
    $fresh();
    $r = $c->submit(['sales_type' => 'Cash', 'install_by' => 'dishnet', 'ucrm_total_seen' => '1800', 'ucrm_links_seen' => $SEEN], ['wallet' => $wallet] + $without(2));
    $c->eq([$r['value']['success'], $r['value']['message'], count($r['value']['apps']), $r['value']['wallet'], $writes(), $r['value']['collections']],
        [false, $CU($NOT_LINKED('Standard Kit (TEST)', 'Hardware')), 0, 100000.0, [], []],
        'B1 unpriced Cash (the kit not linked): refused with the reason before any write — no application, no client, no debit, no collection; CASH_UNPRICED\'s new tail');
    $fresh(['products' => qfu_kyc_products([55 => null])]);
    $r = $c->submit(['sales_type' => 'Cash', 'install_by' => 'dishnet', 'ucrm_total_seen' => '1800', 'ucrm_links_seen' => $SEEN], ['wallet' => $wallet]);
    $c->eq([$r['value']['success'], $r['value']['message'], count($r['value']['apps']), $r['value']['wallet'], $writes()],
        [false, $CU('Standard Kit (TEST) is linked to a uCRM product that uCRM no longer lists — an admin links it again'), 0, 100000.0, []],
        'B1 unpriced Cash (the kit\'s linked product deleted in uCRM): refused, nothing written — its twin 54, spelled alike, is not taken instead');
    $fresh();
    $r = $c->submit(['sales_type' => 'Credit', 'install_by' => 'dishnet'], ['wallet' => $wallet] + $without(2));
    $a = $app($r);
    $c->eq([$r['value']['success'], count($quotes()), $a['quote_refused'] ?? null, (float)($a['amount_charged'] ?? -1), $a['is_lead'] ?? null,
            !empty($a['wa_quote_pending']), $payments(), $r['value']['wallet']],
        [true, 0, 'P1', 0.0, true, false, [], 100000.0],
        'B1 unpriced Credit: registered as a lead, no quote, P1 recorded, nothing collected, no wa_quote_pending');
    $c->t(strpos((string)($a['quote_refused_text'] ?? ''), $NOT_LINKED('Standard Kit (TEST)', 'Hardware')) !== false
        && strpos($r['value']['message'], 'No quotation was made') !== false && strpos($r['value']['message'], 'Selected plan has no pricing') === false,
        'B1 … the agent is told why, and never "Selected plan has no pricing"', $r['value']['message']);
    $fresh();
    $r = $c->submit(['sales_type' => 'Cash', 'install_by' => 'dishnet', 'ucrm_total_seen' => '1800', 'ucrm_links_seen' => $SEEN], ['wallet' => $wallet]);
    $a = $app($r);
    $pay = $payments()[0] ?? [];
    $col = $r['value']['collections'][0] ?? [];
    $c->eq([$r['value']['success'], (float)($a['amount_charged'] ?? 0), (float)($pay['amount'] ?? 0), $r['value']['wallet'],
            (float)($col['amount'] ?? 0), $a['is_lead'] ?? null],
        [true, 1800.0, 1800.0, 98200.0, 1800.0, false],
        'B1 priced Cash: the payment, amount_charged, the debit and the collection all equal the quotation\'s 1800', $r['value']['message']);
    $c->eq(array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $quotes()[0]['items'] ?? [])), 1800,
        'B1 … and the quotation totals 1800');

    // B2 — §H on an existing customer (the mobile API's path, customer_id set; here a hand-made post with the marker)
    $fresh($seed901);
    $r = $c->submit(['customer_id' => '901', 'connectivity_type' => 'Additional Service', 'sales_type' => 'Cash', 'install_by' => 'dishnet',
                     'ucrm_total_seen' => '1800', 'ucrm_links_seen' => $SEEN], ['wallet' => $wallet] + $without(2));
    $c->eq([$r['value']['success'], $r['value']['message'], count($r['value']['apps']), $r['value']['wallet'], $req()],
        [false, $CU($NOT_LINKED('Standard Kit (TEST)', 'Hardware')), 0, 100000.0, ['GET /clients/901', 'GET /products', 'GET /service-plans']],
        'B2 existing customer, unpriced Cash (the kit not linked): the company check first, then the catalogue, then refused before any write');
    $fresh($seed901);
    $r = $c->submit(['customer_id' => '901', 'connectivity_type' => 'Additional Service', 'sales_type' => 'Credit'], ['wallet' => $wallet]);
    $a = $app($r);
    $c->eq([$r['value']['success'], count($quotes()), $a['quote_refused'] ?? null, strpos($r['value']['message'], 'Application saved. No quotation was made') === 0],
        [true, 0, 'P1', true],
        'B2 existing customer, Credit, no installation answer (the API sends none): saved, no quote, P1, and the agent is told');
    $fresh($seed901);
    $r = $c->submit(['customer_id' => '901', 'connectivity_type' => 'Additional Service', 'sales_type' => 'Cash', 'install_by' => 'customer',
                     'ucrm_total_seen' => '1600', 'ucrm_links_seen' => '1,2', 'address_1' => 'Plot 9 Test Lane', 'address_2' => ''], ['wallet' => $wallet]);
    $a = $app($r);
    $q = $quotes()[0] ?? [];
    $c->eq([$r['value']['success'], $q['notes'] ?? null, (float)($a['amount_charged'] ?? 0), $r['value']['wallet'], array_column($q['items'] ?? [], 'productId')],
        [true, 'Installation site: Plot 9 Test Lane', 1600.0, 98400.0, [12, 55]],
        'B2 existing customer, priced Cash: the customer\'s notes carry only the installation site; the debit is the quotation\'s 1600', $r['value']['message']);
    $c->t(strpos((string)($q['adminNotes'] ?? ''), 'ADDITIONAL SERVICE — EXISTING CUSTOMER') === 0 && strpos((string)($q['adminNotes'] ?? ''), 'Install: Customer installs — kit only') !== false,
        'B2 … the rest of the block is staff\'s, with the installation answer', (string)($q['adminNotes'] ?? ''));
    $fresh(['clients' => [['id' => 901, 'organizationId' => 3, 'firstName' => 'Test', 'lastName' => 'Elsewhere', 'contacts' => []]]], 'two_orgs');
    $r = $c->submit(['customer_id' => '901', 'connectivity_type' => 'Additional Service', 'sales_type' => 'Cash', 'ucrm_total_seen' => '1'], ['wallet' => $wallet]);
    $c->t(!$r['value']['success'] && strpos($r['value']['message'], 'filed under') !== false && !in_array('GET /products', $req(), true),
        'B2 a misfiled existing customer is told to move them (R1) — never "no usable price", and the catalogue is not read', json_encode([$r['value']['message'], $req()]));

    // B3 — the posted total (D10); the links the form drew are right in each, so only the total differs
    $fresh();
    $r = $c->submit(['sales_type' => 'Cash', 'install_by' => 'dishnet', 'ucrm_total_seen' => '1800.00', 'ucrm_links_seen' => $SEEN], ['wallet' => $wallet]);
    $c->t(!empty($r['value']['success']), 'B3 the control: the sale with the total and the links the form showed goes in', $r['value']['message']);
    foreach ([
        'missing'          => [null, 'CASH_NO_TOTAL'],
        'with separators'  => ['1,800', 'CASH_NO_TOTAL'],
        'a stale tab'      => ['1799', 'CASH_CHANGED'],
        'one shilling over'=> ['1801.00', 'CASH_CHANGED'],
    ] as $what => [$seen, $code]) {
        $fresh();
        $r = $c->submit(['sales_type' => 'Cash', 'install_by' => 'dishnet', 'ucrm_total_seen' => $seen, 'ucrm_links_seen' => $SEEN], ['wallet' => $wallet]);
        $c->t(!$r['value']['success'] && $r['value']['message'] === UcrmQuoteLinesTexts::get($c, $code) && count($r['value']['apps']) === 0
            && (float)$r['value']['wallet'] === 100000.0 && $writes() === [], "B3 a Cash sale whose posted total is {$what}: refused before any write", $r['value']['message']);
    }
    $fresh();
    $r = $c->submit(['sales_type' => 'Cash', 'ucrm_total_seen' => '1600', 'ucrm_links_seen' => '1,2'], ['wallet' => $wallet]);
    $c->eq([$r['value']['success'], $r['value']['message']], [false, UcrmQuoteLinesTexts::get($c, 'CASH_NO_INSTALL')],
        'B3 a Cash Starlink New Connection that does not say who installs (the retailer app, the API): refused, naming the web KYC form');

    // B4 — a refusal writes nothing, not even a duplicate_confirmations row
    $dupApp = [['id' => 1, 'mobile' => '+256 700 001 999', 'firstname' => 'Other', 'lastname' => 'Person', 'sync_status' => 'synced', 'crm_client_id' => '555', 'submitted_at' => '2026-10-01 10:00:00']];
    $fresh();
    $r = $c->submit(['mobile' => '+256 700 001 999', 'duplicate_confirmed' => '1', 'sales_type' => 'Credit', 'install_by' => 'dishnet'], ['wallet' => $wallet, 'apps' => $dupApp]);
    $c->eq([$r['value']['success'], count($r['value']['dup'])], [true, 1], 'B4 the control: the same duplicate, priced, writes its one row');
    $fresh();
    $r = $c->submit(['mobile' => '+256 700 001 999', 'duplicate_confirmed' => '1', 'sales_type' => 'Cash', 'install_by' => 'dishnet', 'ucrm_total_seen' => '1800',
                     'ucrm_links_seen' => $SEEN], ['wallet' => $wallet, 'apps' => $dupApp] + $without(2));
    $c->eq([$r['value']['success'], count($r['value']['dup']), count($r['value']['apps'])], [false, 0, 1],
        'B4 a confirmed-duplicate Cash sale refused as unpriced (the kit not linked) leaves no duplicate_confirmations row');

    // B5 — what every application stores (§F.3), with the link records and the entry point (§R.4.2, §R.4.4)
    $fresh();
    $r = $c->submit(['install_by' => 'dishnet', 'sales_type' => 'Credit'], ['wallet' => $wallet]);
    $a = $app($r);
    $c->eq([$a['quote_lines_source'] ?? null, $a['quote_items_total'] ?? null, array_column((array)($a['quote_items'] ?? []), 'productId'),
            $a['install_by'] ?? null, $a['offer_price'] ?? null, $a['device_price'] ?? null, isset($a['quote_lines_read_at']),
            $a['quote_link_ids'] ?? null, $a['quote_sale_entry'] ?? null],
        ['ucrm-5.18.102', 1800, [12, 55, 70], 'dishnet', 100, 1500, true, [1, 2, 3], 'web'],
        'B5 the resolved lines, marked, with their total and read time; the installation answer; uCRM\'s prices in offer_price and device_price; quote_link_ids and quote_sale_entry web');
    $c->ctl('kyc', '/__test/reset?scenario=refuse');
    $c->ctl('kyc', '/__test/set', ['catalogue' => true]);
    $r = $c->submit(['install_by' => 'customer', 'sales_type' => 'Credit'], ['wallet' => $wallet]);
    $a = $app($r);
    $c->eq([$a['crm_sync_status'] ?? null, $a['quote_lines_source'] ?? null, array_column((array)($a['quote_items'] ?? []), 'productId'), $a['quote_link_ids'] ?? null],
        ['pending', 'ucrm-5.18.102', [12, 55], [1, 2]], 'B5 … whether or not the client create succeeded (here uCRM refused it); no installation link when the customer installs');

    // B6 — one read per submission: a price that changes between two reads cannot split the cash from the quote
    $fresh(['products' => qfu_kyc_products(), 'products_after_first' => qfu_kyc_products([55 => ['price' => 1700]])]);
    $r = $c->submit(['sales_type' => 'Cash', 'install_by' => 'dishnet', 'ucrm_total_seen' => '1800', 'ucrm_links_seen' => $SEEN], ['wallet' => $wallet]);
    $a = $app($r);
    $c->eq([count(array_filter($req(), fn($x) => $x === 'GET /products')), (float)($a['amount_charged'] ?? 0),
            array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $quotes()[0]['items'] ?? []))],
        [1, 1800.0, 1800], 'B6 the catalogue is read once per submission: the cash and the quotation are the same 1800');

    // B7/B8 — Fiber; Shifting and Ownership Change
    $fresh();
    $r = $c->submit(['customer_type' => 'Fiber', 'fiber_area' => 'Test Area', 'sales_type' => 'Credit'], ['wallet' => $wallet, 'cfg' => ['fiber_install_fee' => 100, 'fiber_install_product_id' => 244]]);
    $a = $app($r);
    $c->eq([count($quotes()), $a['quote_refused'] ?? null, strpos((string)($a['quote_refused_text'] ?? ''), 'Fiber is not priced from uCRM') !== false],
        [0, 'P1', true], 'B7 Fiber on Uganda: no quote (never product 244 or the Fiber fee), and why');
    foreach (['Shifting Connection', 'Ownership Change'] as $conn) {
        $fresh();
        $r = $c->submit(['connectivity_type' => $conn, 'sales_type' => 'Credit'], ['wallet' => $wallet]);
        $c->eq(array_column($quotes()[0]['items'] ?? [], 'productId'), [12, 55], "B8 {$conn}: priced with no installation answer and no installation line");
    }

    // B9 — the prefix is the customer's notes, and nothing internal is
    $fresh();
    $r = $c->submit(['install_by' => 'dishnet'], ['wallet' => $wallet, 'cfg' => ['kyc_quote_notes_prefix' => 'Thank you for choosing DishNet']]);
    $q = $quotes()[0] ?? [];
    $c->t(($q['notes'] ?? null) === 'Thank you for choosing DishNet' && preg_match('/PENDING|Auto-generated|Sales:|Priority:/', (string)($q['notes'] ?? '')) === 0,
        'B9 the Settings prefix is all the customer sees in NOTES', json_encode($q['notes'] ?? null));

    // B11 — 5.18.102, after the review (G1/X1): the answer carries who installs, because post_kyc.php and the mobile API
    // hand the staff messages (§E.2) that answer, not the stored application
    $by = fn(array $r) => array_key_exists('install_by', (array)($r['value']['data'] ?? [])) ? $r['value']['data']['install_by'] : '<none>';
    $fresh();
    $r1 = $c->submit(['install_by' => 'customer']);
    $fresh();
    $r2 = $c->submit(['install_by' => 'dishnet', 'sales_type' => 'Cash', 'ucrm_total_seen' => '1800', 'ucrm_links_seen' => $SEEN], ['wallet' => $wallet]);
    $fresh();
    $r3 = $c->submit(['install_by' => 'dishnet', 'connectivity_type' => 'Shifting Connection']);
    $fresh(['clients' => [['id' => 901, 'organizationId' => 1, 'firstname' => 'Test', 'lastname' => 'Existing', 'contacts' => []]]]);
    $r4 = $c->submit(['customer_id' => '901', 'connectivity_type' => 'Additional Service', 'install_by' => 'customer']);
    $fresh();
    $r5 = $c->submit(['install_by' => 'customer'], ['cfg' => ['ucrm_quote_organization_id' => 'off']]);
    $c->t(!empty($r1['value']['success']) && !empty($r2['value']['success']) && !empty($r4['value']['success']), 'B11 the control: each of those sales went in',
        json_encode([$r1['value']['message'] ?? '', $r2['value']['message'] ?? '', $r4['value']['message'] ?? '']));
    $c->eq([$by($r1), $by($r2), $by($r3), $by($r4), $by($r5)], ['customer', 'dishnet', '<none>', 'customer', '<none>'],
        'B11 the answer\'s data says who installs — a new customer (Credit, Cash) and an existing one; nothing where the sale asks no one, nor where the gate is off');

    // B12 — 5.18.102, after the review (X2): "uCRM's prices changed — reload the form" drops the page cache, so the reload
    // reads uCRM; any other refusal leaves it
    $cacheAt = function (string $why) use ($c): string {
        $d = $c->dir('data-b12');
        // the book first: a data folder with no plugin.sqlite3 imports every *.json on its first boot (SqliteStore's
        // migration renames each to .migrated) — production's store exists, so its page cache is never taken that way
        $c->child('return true;', ['dir' => $d]);
        file_put_contents($d . '/ucrm_quote_catalogue_cache.json', json_encode(['ok' => true, 'products' => [], 'plans' => [], 'at' => time(), 'why' => $why]));
        return $d;
    };
    $fresh();
    $d2 = $cacheAt('no total');
    $r0 = $c->submit(['sales_type' => 'Cash', 'install_by' => 'dishnet', 'ucrm_links_seen' => $SEEN], ['wallet' => $wallet, 'dir' => $d2]);
    $fresh();
    $d1 = $cacheAt('changed');
    $r = $c->submit(['sales_type' => 'Cash', 'install_by' => 'dishnet', 'ucrm_total_seen' => '1799', 'ucrm_links_seen' => $SEEN], ['wallet' => $wallet, 'dir' => $d1]);
    $c->eq([$r0['value']['message'] ?? null, is_file($d2 . '/ucrm_quote_catalogue_cache.json'), $r['value']['message'] ?? null, is_file($d1 . '/ucrm_quote_catalogue_cache.json')],
        [UcrmQuoteLinesTexts::get($c, 'CASH_NO_TOTAL'), true, UcrmQuoteLinesTexts::get($c, 'CASH_CHANGED'), false],
        'B12 refused for another reason (the control): the page cache stays; refused because uCRM\'s prices changed: it is gone');

    // B13 — 5.18.102 D14 (§R.4.7): the link records the form drew. A relink at the same price between drawing and
    // submitting passes the total check; the seen links do not.
    $cat57 = ['products' => qfu_kyc_products([57 => ['id' => 57, 'name' => 'Standard Kit Plus (TEST)', 'price' => 1500, 'unit' => 'Pc', 'taxable' => false]])];
    $relinked = ['links' => [qfu_link(1, 'plan', 1, QFU_PLAN_STAMP, 12, 6), qfu_ended(qfu_link(2, 'kit', 3, QFU_KIT_STAMP, 55)),
                             qfu_link(3, 'install', 0, '', 70), qfu_link(4, 'kit', 3, QFU_KIT_STAMP, 57)]];
    $fresh($cat57);
    $r = $c->submit(['sales_type' => 'Cash', 'install_by' => 'dishnet', 'ucrm_total_seen' => '1800', 'ucrm_links_seen' => $SEEN], ['wallet' => $wallet]);
    $c->eq([!empty($r['value']['success']), array_column($quotes()[0]['items'] ?? [], 'productId')], [true, [12, 55, 70]],
        'B13 the control: no relink — the seen links 1, 2, 3 are the links in force, accepted', $r['value']['message']);
    $fresh($cat57);
    $r = $c->submit(['sales_type' => 'Cash', 'install_by' => 'dishnet', 'ucrm_total_seen' => '1800', 'ucrm_links_seen' => '1,3,4'], ['wallet' => $wallet] + $relinked);
    $c->eq([!empty($r['value']['success']), array_column($quotes()[0]['items'] ?? [], 'productId'), (float)($app($r)['amount_charged'] ?? 0), $app($r)['quote_link_ids'] ?? null],
        [true, [12, 57, 70], 1800.0, [1, 3, 4]],
        'B13 the control: the form drawn after the relink (records 1, 3, 4) — accepted, quoted with what the links now say', $r['value']['message']);
    $fresh($cat57);
    $r = $c->submit(['sales_type' => 'Cash', 'install_by' => 'dishnet', 'ucrm_total_seen' => '1800', 'ucrm_links_seen' => $SEEN], ['wallet' => $wallet] + $relinked);
    $c->eq([$r['value']['success'], $r['value']['message'], count($r['value']['apps']), $r['value']['wallet'], $writes(), $r['value']['collections']],
        [false, $RELINKED, 0, 100000.0, [], []],
        'B13 a relink at the same price (55 → 57, both 1500) while the form was open: CASH_RELINKED, nothing written');
    $fresh($cat57);
    $r = $c->submit(['sales_type' => 'Credit', 'install_by' => 'dishnet', 'ucrm_links_seen' => $SEEN], ['wallet' => $wallet] + $relinked);
    $c->eq([!empty($r['value']['success']), array_column($quotes()[0]['items'] ?? [], 'productId')], [true, [12, 57, 70]],
        'B13 … Credit with the same relink: registered, and quoted by the links in force (no cash is taken)');
    $cancel = ['links' => [qfu_link(1, 'plan', 1, QFU_PLAN_STAMP, 12, 6), qfu_ended(qfu_link(2, 'kit', 3, QFU_KIT_STAMP, 55)),
                           qfu_ended(qfu_link(3, 'install', 0, '', 70)), qfu_link(4, 'kit', 3, QFU_KIT_STAMP, 54), qfu_link(5, 'install', 0, '', 71)]];
    $cat71 = ['products' => qfu_kyc_products([71 => ['id' => 71, 'name' => 'Installation Plus (TEST)', 'price' => 300, 'unit' => 'Job', 'taxable' => false]])];
    $fresh($cat71);
    $r = $c->submit(['sales_type' => 'Cash', 'install_by' => 'dishnet', 'ucrm_total_seen' => '1800', 'ucrm_links_seen' => '1,4,5'], ['wallet' => $wallet] + $cancel);
    $c->eq([!empty($r['value']['success']), (float)($app($r)['amount_charged'] ?? 0)], [true, 1800.0],
        'B13 the control: two relinks whose prices cancel out (kit 1500 → 1400, installation 200 → 300), the form drawn after them — accepted at 1800', $r['value']['message']);
    $fresh($cat71);
    $r = $c->submit(['sales_type' => 'Cash', 'install_by' => 'dishnet', 'ucrm_total_seen' => '1800', 'ucrm_links_seen' => $SEEN], ['wallet' => $wallet] + $cancel);
    $c->eq([$r['value']['success'], $r['value']['message'], count($r['value']['apps']), $writes()], [false, $RELINKED, 0, []],
        'B13 … the same two relinks while the form was open: the total still 1800, refused');
    foreach (['missing' => null, 'empty' => '', 'one record short' => '1,2', 'one record more' => '1,2,3,4', 'malformed' => '1;2;3'] as $what => $seen) {
        $fresh();
        $r = $c->submit(['sales_type' => 'Cash', 'install_by' => 'dishnet', 'ucrm_total_seen' => '1800', 'ucrm_links_seen' => $seen], ['wallet' => $wallet]);
        $c->eq([$r['value']['success'], $r['value']['message'], count($r['value']['apps']), $writes()], [false, $RELINKED, 0, []],
            "B13 the right total with the seen links {$what}: refused, nothing written");
    }

    // B14 — 5.18.102 D14 (E-3): the entry reason and the kyc_packages reason end at their cause. For Cash the remedy is
    // CASH_UNPRICED's, never "make the quotation"; the Credit twin of each records P1, whose own text says it.
    $fresh();
    $r = $c->submit(['sales_type' => 'Credit', 'install_by' => 'dishnet', 'ucrm_quote_form' => null]);
    $t1 = (string)($app($r)['quote_refused_text'] ?? '');
    $fresh();
    $r = $c->submit(['sales_type' => 'Credit', 'install_by' => 'dishnet', 'package_choice' => '9'], $PKG);
    $t2 = (string)($app($r)['quote_refused_text'] ?? '');
    $c->t(strpos($t1, $ENTRY . '. ' . $P1_OWN) !== false && strpos($t2, 'App Package (TEST) is a retailer-app package, which is not linked to uCRM. ' . $P1_OWN) !== false,
        'B14 the controls: the Credit twins record P1, and P1\'s own text says "Make the quotation in uCRM"', json_encode([$t1, $t2]));
    foreach ([
        'without the marker'                               => [['ucrm_links_seen' => $SEEN, 'ucrm_quote_form' => null], [], $ENTRY],
        'from a form opened before the update'             => [['ucrm_quote_form' => null], [], $ENTRY],
        'with the marker and a kyc_packages-only id (by hand)' => [['package_choice' => '9', 'ucrm_links_seen' => $SEEN], $PKG, 'App Package (TEST) is a retailer-app package, which is not linked to uCRM'],
    ] as $what => [$post, $o, $reason]) {
        $fresh();
        $r = $c->submit(['sales_type' => 'Cash', 'install_by' => 'dishnet', 'ucrm_total_seen' => '1800'] + $post, ['wallet' => $wallet] + $o);
        $m = (string)$r['value']['message'];
        $c->t(!$r['value']['success'] && $m === $CU($reason) && stripos($m, 'make the quotation') === false && count($r['value']['apps']) === 0 && $writes() === [],
            "B14 a Cash post {$what}: CASH_UNPRICED with that reason, which ends at its cause — no \"make the quotation\", nothing written", $m);
    }

    // B15 — 5.18.102 D14 (§R.4.1): P1_UNLINKED for a web sale whose reason is "not linked" — at submission new and existing
    $fresh(['products' => qfu_kyc_products([55 => ['archived' => true]])]);
    $r = $c->submit(['sales_type' => 'Credit', 'install_by' => 'dishnet']);
    $t = (string)($app($r)['quote_refused_text'] ?? '');
    $c->t(($app($r)['quote_refused'] ?? '') === 'P1' && strpos($t, 'Standard Kit (TEST) is linked to a uCRM product that is archived in uCRM — an admin links it again. ' . $P1_OWN) !== false
        && strpos($t, $P1_UNLINKED) === false, 'B15 the control: a web sale whose linked item is broken (archived) records P1 with P1\'s own text', $t);
    $fresh();
    $r = $c->submit(['sales_type' => 'Credit', 'install_by' => 'dishnet', 'ucrm_quote_form' => null], $without(2));
    $t = (string)($app($r)['quote_refused_text'] ?? '');
    $c->t(($app($r)['quote_refused'] ?? '') === 'P1' && strpos($t, $ENTRY . '. ' . $P1_OWN) !== false && strpos($t, $P1_UNLINKED) === false,
        'B15 the control: the same sale without the marker, the kit unlinked — P1\'s own text, with the entry reason', $t);
    foreach ([
        'an item unlinked (the kit)' => [$without(2), $NOT_LINKED('Standard Kit (TEST)', 'Hardware')],
        'the installation unlinked'  => [$without(3), $NOT_LINKED('Installation', 'Hardware')],
    ] as $what => [$o, $reason]) {
        $fresh();
        $r = $c->submit(['sales_type' => 'Credit', 'install_by' => 'dishnet'], $o);
        $a = $app($r);
        $t = (string)($a['quote_refused_text'] ?? '');
        $c->t(!empty($r['value']['success']) && ($a['quote_refused'] ?? '') === 'P1' && count($quotes()) === 0
            && strpos($t, 'No quotation was made: ' . $reason . '. ' . $P1_UNLINKED) !== false && strpos($t, $P1_OWN) === false,
            "B15 a web Credit sale with {$what}: P1 recorded with P1_UNLINKED's text", $t);
    }
    $fresh($seed901);
    $r = $c->submit(['customer_id' => '901', 'connectivity_type' => 'Shifting Connection', 'sales_type' => 'Credit', 'ucrm_quote_form' => null], $without(2));
    $t = (string)($app($r)['quote_refused_text'] ?? '');
    $c->t(($app($r)['quote_refused'] ?? '') === 'P1' && strpos($t, $P1_OWN) !== false && strpos($t, $P1_UNLINKED) === false,
        'B15 the control: an existing customer\'s post without the marker, the kit unlinked — P1\'s own text', $t);
    $fresh($seed901);
    $r = $c->submit(['customer_id' => '901', 'connectivity_type' => 'Shifting Connection', 'sales_type' => 'Credit'], $without(2));
    $t = (string)($app($r)['quote_refused_text'] ?? '');
    $c->t(!empty($r['value']['success']) && ($app($r)['quote_refused'] ?? '') === 'P1' && count($quotes()) === 0
        && strpos($t, 'No quotation was made: ' . $NOT_LINKED('Standard Kit (TEST)', 'Hardware') . '. ' . $P1_UNLINKED) !== false && strpos($t, $P1_OWN) === false,
        'B15 an existing customer\'s hand-made post with the marker (handleExisting), the kit unlinked: P1 with P1_UNLINKED\'s text', $t);

    // B10 — the gate: 0/off is as before, links left in the store ignored; South Sudan never reads Products
    $fresh();
    $r = $c->submit(['install_by' => 'dishnet'], ['wallet' => $wallet]);
    $c->t(in_array('GET /products', $req(), true) && count($quotes()) === 1, 'B10 the control: with the gate on the same sale reads Products and is quoted');
    $fresh();
    $r = $c->submit(['install_by' => 'dishnet'], ['wallet' => $wallet, 'cfg' => ['ucrm_quote_organization_id' => '0']]);
    $q = $quotes()[0] ?? [];
    $c->t(!in_array('GET /products', $req(), true) && ($q['items'][0]['price'] ?? null) == 90 && strpos((string)($q['notes'] ?? ''), 'Auto-generated') !== false
        && !isset($app($r)['quote_sale_entry']) && !isset($app($r)['quote_link_ids']),
        'B10 Uganda with ucrm_quote_organization_id = 0, the links left in the store: 5.18.101\'s quote (the plugin\'s 90, its notes), Products never read, nothing of D14 stored', json_encode($q));
    $c->ctl('kyc', '/__test/reset?scenario=sudan');
    $c->ctl('kyc', '/__test/set', ['catalogue' => true]);
    $r = $c->submit(['install_by' => 'dishnet', 'mobile' => '+211 900 000 123'], ['wallet' => $wallet, 'tenant' => 'south-sudan', 'links' => qfu_links()]);
    $c->t(!in_array('GET /products', $req(), true) && ($quotes()[0]['items'][0]['price'] ?? null) == 90 && !isset($app($r)['quote_sale_entry']),
        'B10 South Sudan, with a link store present: Products never read, the plugin\'s price as before', json_encode($req()));
}];

/** The texts B compares with, read from the judged tree's own class in a child (so a weakened copy is judged by its own). */
final class UcrmQuoteLinesTexts
{
    private static array $t = [];
    public static function get(QfuCtx $c, string $code): string
    {
        if (!isset(self::$t[$c->root])) {
            $out = [];
            exec('php -r ' . escapeshellarg('require ' . var_export($c->root . '/lib/UcrmQuoteLines.php', true) . '; echo json_encode(UcrmQuoteLines::TEXTS);'), $out);
            self::$t[$c->root] = json_decode(implode('', $out), true) ?: [];
        }
        return (string)(self::$t[$c->root][$code] ?? '');
    }
}
