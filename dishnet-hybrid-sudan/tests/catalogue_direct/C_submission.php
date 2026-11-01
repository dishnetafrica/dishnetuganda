<?php
declare(strict_types=1);
/**
 * Section C — 5.18.103 (root docs/72 §G.5–§G.7, G-7–G-9): the KYC submission (KycService::process) with the uCRM catalogue.
 * A direct sale priced and quoted by uCRM id; a Cash sale collecting exactly the total, refused on a changed total or a
 * changed item (the tokens); every refusal before anything is written; an unpriced Credit sale registered with its ids
 * (P1_DIRECT); the record field by field — no uCRM id in a plugin-id field, the cart rebuilt by the server; a form opened
 * before the switch read as 'app'; the switch off refusing the direct form; uCRM down; South Sudan untouched.
 */
$CD_SECTIONS['C'] = ['title' => 'the KYC submission with the uCRM catalogue (KycService)', 'run' => function (QfuCtx $c) {
    $wallet = [7 => 100000];
    $quotes = function () use ($c) { return $c->bodies('kyc', 'POST', '/clients/\d+/quotes'); };
    $creates = function () use ($c) { return $c->bodies('kyc', 'POST', '/clients'); };
    $app = function (array $r): array { $a = (array)($r['value']['apps'] ?? []); return $a === [] ? [] : (array)end($a); };
    $submit = function (array $post, ?array $records, array $o = []) use ($c, $wallet) {
        // the store's ucrm_catalogue.json is set before the submission: the child seeds it with the apps
        $o += ['wallet' => $wallet];
        $o['cfg'] = ($o['cfg'] ?? []);
        $body = cd_seed($records);
        $agent = $o['agent'] ?? ['id' => 7, 'name' => 'Test Agent', 'role' => 'sales', 'is_admin' => false];
        $post += ['action' => 'kyc_submit', 'customer_type' => 'StarLink', 'connectivity_type' => 'New Connection',
                  'firstname' => 'Test', 'lastname' => 'Cd' . mt_rand(100, 999), 'mobile' => '+256 700 002 ' . mt_rand(100, 999),
                  'email' => '', 'address_1' => 'Test Road', 'sales_type' => 'Credit', 'priority' => 'Low', 'sales_person' => 'Tester'];
        $post = array_filter($post, function ($v) { return $v !== null; });
        return $c->child($body . '
$w = new WalletService($s, $s->getPdo());
$k = new KycService($crm, $s, $w, new CrmQueue($s), $dir);
$r = $k->process(json_decode(' . var_export(json_encode($post), true) . ', true), [], json_decode(' . var_export(json_encode($agent), true) . ', true));
return ["success" => !empty($r["success"]), "message" => (string)($r["message"] ?? ""), "apps" => $s->load("kyc_applications.json") ?? [],
        "wallet" => $w->getBalance(' . (int)$agent['id'] . ')];
', $o);
    };
    $T = function (int $pid, string $label, $price, string $unit) { return cd_token($pid, $label, $price, $unit); };
    $SALE_TOKENS = implode(',', [$T(12, 'Residential Standard (TEST)', 100, 'month'), $T(CD_KIT, 'Standard Kit (TEST)', 1500, 'Pc'),
                                 $T(CD_INSTALL, 'Professional Installation', 200, 'Job')]);

    // ── C0 the positive control: a direct Credit sale, priced, quoted by uCRM id ─────────────────────────────────────
    cd_fake($c);
    $r = $submit(cd_post([CD_PLAN_RES, 12], [[CD_KIT, 2]]), cd_records(true));
    $a = $app($r);
    $q = $quotes()[0] ?? [];
    $c->t(!empty($r['value']['success']) && count($quotes()) === 1, 'C0 the control: a direct Credit sale is registered and quoted',
        json_encode([$r['value']['message'] ?? null, substr($r['out'], 0, 400), substr($r['errlog'], 0, 600)]));
    $c->eq(array_map(function ($i) { return [$i['productId'] ?? null, $i['label'], $i['price'], $i['quantity'], $i['unit']]; }, $q['items'] ?? []),
        [[12, 'Residential Standard (TEST)', 100, 1, 'month'], [CD_KIT, 'Standard Kit (TEST)', 1500, 2, 'Pc'], [CD_INSTALL, 'Professional Installation', 200, 1, 'Job']],
        'C0 the quotation\'s lines: the plan\'s product, the kit × 2, the installation — uCRM ids, names, prices, units');

    // ── C1 the stored record, field by field (§G.7, G-7) ─────────────────────────────────────────────────────────────
    $c->eq([$a['quote_sale_entry'] ?? null, $a['quote_lines_source'] ?? null, $a['quote_items_total'] ?? null],
        ['web-direct', 'ucrm-direct-5.18.103', 3300], 'C1 entry web-direct, the direct source, the total');
    $c->eq($a['quote_item_ids'] ?? null, ['plan' => ['sp' => CD_PLAN_RES, 'p' => 12], 'hw' => [['p' => CD_KIT, 'q' => 2]], 'install' => CD_INSTALL],
        'C1 quote_item_ids: the plan pair, the cart, the installation');
    $c->eq([$a['package_choice'] ?? 'x', $a['device_id'] ?? 'x', array_key_exists('quote_link_ids', $a), array_key_exists('quote_sale_cart', $a)],
        ['', '', false, false], 'C1 package_choice and device_id empty; no quote_link_ids, no quote_sale_cart');
    $c->eq(json_decode((string)($a['hw_cart_json'] ?? ''), true), [['id' => 0, 'title' => 'Standard Kit (TEST)', 'price' => 1500, 'qty' => 2]],
        'C1 hw_cart_json rebuilt by the server: id 0, uCRM\'s name and price, no ucrm_product_id');
    $c->eq([$a['offer_name'] ?? null, $a['offer_price'] ?? null, $a['device_title'] ?? null, $a['device_price'] ?? null, (string)($a['kitQty'] ?? '')],
        ['Residential Standard (TEST)', 100, 'Standard Kit (TEST)', 1500, '2'], 'C1 offer and device names and prices from uCRM; kitQty the first item\'s');
    $cr = $creates()[0] ?? [];
    $c->eq((string)($cr['note'] ?? ''), 'Standard Kit (TEST) / Residential Standard (TEST)', 'C1 the uCRM client\'s note carries uCRM\'s names');

    // ── C2 the browser's plugin-id fields are never kept, and never reach uCRM (G-7) ─────────────────────────────────
    cd_fake($c);
    $r = $submit(cd_post([CD_PLAN_RES, 12], [[CD_MINI, 1]], ['package_choice' => '1', 'device_id' => '3', 'kitQty' => '7',
        'hw_cart_json' => json_encode([['id' => 3, 'title' => 'Browser Kit', 'price' => 1, 'qty' => 1, 'ucrm_product_id' => 98]])]), cd_records(true));
    $a = $app($r);
    $c->eq([$a['package_choice'] ?? 'x', $a['device_id'] ?? 'x', (string)($a['kitQty'] ?? '')], ['', '', '1'], 'C2 the posted package_choice, device_id and kitQty are replaced');
    $c->eq(json_decode((string)($a['hw_cart_json'] ?? ''), true), [['id' => 0, 'title' => 'Mini Kit (TEST)', 'price' => 900, 'qty' => 1]],
        'C2 the posted hw_cart_json (a plugin id, a browser title and price, a product id) is replaced');
    $body = json_encode($creates()[0] ?? []);
    $c->t(strpos($body, 'Browser Kit') === false && !preg_match('/"value":"(1|3)"/', $body), 'C2 nothing the browser posted in those fields reaches the uCRM client create', $body);

    // ── C3 Cash: exactly the total; refused on a changed total, a changed item, missing tokens; nothing written ───────
    cd_fake($c);
    $r = $submit(cd_post([CD_PLAN_RES, 12], [[CD_KIT, 2]], ['sales_type' => 'Cash', 'ucrm_total_seen' => '3300', 'ucrm_items_seen' => $SALE_TOKENS]), cd_records(true));
    $a = $app($r);
    $c->eq([!empty($r['value']['success']), $a['amount_charged'] ?? null, $r['value']['wallet'] ?? null], [true, 3300, 100000 - 3300],
        'C3 control: a direct Cash sale collects exactly the quotation\'s total', json_encode($r['value']['message'] ?? $r['out']));
    $refusals = [
        'the total differs'        => [['ucrm_total_seen' => '3200', 'ucrm_items_seen' => $SALE_TOKENS], 'Prices in uCRM changed since the form was opened'],
        'no total'                 => [['ucrm_items_seen' => $SALE_TOKENS], 'did not send the total the form showed'],
        'an item changed (tokens)' => [['ucrm_total_seen' => '3300', 'ucrm_items_seen' => ($SALE_TOKENS[0] === '0' ? '1' : '0') . substr($SALE_TOKENS, 1)], 'An item on this sale changed in uCRM since the form was opened'],
        'no tokens'                => [['ucrm_total_seen' => '3300'], 'An item on this sale changed in uCRM since the form was opened'],
        'a token missing'          => [['ucrm_total_seen' => '3300', 'ucrm_items_seen' => substr($SALE_TOKENS, 0, 33)], 'An item on this sale changed in uCRM since the form was opened'],
    ];
    foreach ($refusals as $k => [$more, $text]) {
        cd_fake($c);
        $r = $submit(cd_post([CD_PLAN_RES, 12], [[CD_KIT, 2]], ['sales_type' => 'Cash'] + $more), cd_records(true));
        $c->t(empty($r['value']['success']) && strpos((string)($r['value']['message'] ?? ''), $text) !== false
            && ($r['value']['apps'] ?? null) === [] && ($r['value']['wallet'] ?? null) === 100000 && $creates() === [],
            "C3 Cash refused, nothing written, no money taken: {$k}", json_encode($r['value']['message'] ?? $r['out']));
    }
    cd_fake($c);
    $r = $submit(cd_post([CD_PLAN_RES, 12], [[CD_KIT, 2]], ['sales_type' => 'Cash', 'ucrm_total_seen' => '3300', 'ucrm_items_seen' => $SALE_TOKENS]), cd_records(true, null));
    $c->t(empty($r['value']['success']) && strpos((string)($r['value']['message'] ?? ''), 'No usable price in uCRM: the installation product is not set') === 0
        && ($r['value']['apps'] ?? null) === [], 'C3 Cash refused when the installation product is not set (CASH_UNPRICED, its reason)', json_encode($r['value']['message'] ?? ''));

    // ── C4 refused before anything is written, Cash or Credit: the switch off, no plan, a cart that cannot be read ────
    $before = [
        'the switch off'        => [cd_post([CD_PLAN_RES, 12], [[CD_KIT, 1]]), cd_records(false), 'This form was opened under the uCRM catalogue, which an admin has since turned off'],
        'no switch record'      => [cd_post([CD_PLAN_RES, 12], [[CD_KIT, 1]]), [], 'This form was opened under the uCRM catalogue, which an admin has since turned off'],
        'no plan'               => [cd_post([CD_PLAN_RES, 12], [[CD_KIT, 1]], ['ucrm_plan' => null]), cd_records(true), 'Please select a service plan.'],
        'a malformed plan'      => [cd_post([CD_PLAN_RES, 12], [[CD_KIT, 1]], ['ucrm_plan' => '6']), cd_records(true), 'Please select a service plan.'],
        'a cart not JSON'       => [cd_post([CD_PLAN_RES, 12], [[CD_KIT, 1]], ['ucrm_cart_json' => '[{p:1']), cd_records(true), 'The sale\'s hardware could not be read'],
        'a quantity of 100'     => [cd_post([CD_PLAN_RES, 12], [[CD_KIT, 100]]), cd_records(true), 'The sale\'s hardware could not be read'],
    ];
    foreach ($before as $k => [$post, $recs, $text]) {
        cd_fake($c);
        $r = $submit($post, $recs);
        $c->t(empty($r['value']['success']) && strpos((string)($r['value']['message'] ?? ''), $text) === 0 && ($r['value']['apps'] ?? null) === []
            && $creates() === [], "C4 refused before anything is written: {$k}", json_encode($r['value']['message'] ?? $r['out']));
    }

    // ── C5 an unpriced Credit sale: registered with its ids, P1_DIRECT, no quotation ──────────────────────────────────
    cd_fake($c);
    $r = $submit(cd_post([CD_PLAN_RES, 12], [[CD_KIT, 1]]), cd_records(true, null));
    $a = $app($r);
    $c->eq([!empty($r['value']['success']), array_key_exists('quote_items', $a) ? $a['quote_items'] : 'x', $a['quote_unpriced_why'] ?? null, $a['quote_refused'] ?? null, count($quotes())],
        [true, null, 'install_unset', 'P1', 0], 'C5 registered, no lines, why install_unset, P1, no quotation');
    $c->t(strpos((string)($a['quote_refused_text'] ?? ''), 'No quotation was made: the installation product is not set') !== false
        && strpos((string)($a['quote_refused_text'] ?? ''), 'Make the quotation in uCRM by hand, or — once the reason is put right — an admin makes it from the Orders card.') !== false,
        'C5 the text is P1_DIRECT\'s, with the reason', (string)($a['quote_refused_text'] ?? ''));
    $c->eq([$a['quote_item_ids']['hw'] ?? null, $a['quote_sale_entry'] ?? null, $a['package_choice'] ?? 'x'], [[['p' => CD_KIT, 'q' => 1]], 'web-direct', ''],
        'C5 the ids kept for the retry; web-direct; no plugin id');
    cd_fake($c);
    $r = $submit(cd_post([CD_PLAN_RES, 12], [[CD_KIT, 1]], ['install_by' => '']), cd_records(true));
    $a = $app($r);
    $c->t(strpos((string)($a['quote_refused_text'] ?? ''), 'No quotation was made: the sale does not say who installs') !== false
        && strpos((string)($a['quote_refused_text'] ?? ''), 'Make the quotation in uCRM (Send Quotation, or ask an admin).') !== false,
        'C5 a sale that does not say who installs keeps P1\'s text', (string)($a['quote_refused_text'] ?? ''));

    // ── C6 uCRM down at submission: Credit goes in unpriced with its ids; Cash refused ───────────────────────────────
    cd_fake($c, ['products_down' => true]);
    $r = $submit(cd_post([CD_PLAN_RES, 12], [[CD_KIT, 1]]), cd_records(true));
    $a = $app($r);
    $c->eq([!empty($r['value']['success']), $a['quote_unpriced_why'] ?? null, $a['quote_item_ids']['plan'] ?? null],
        [true, 'unreadable', ['sp' => CD_PLAN_RES, 'p' => 12]], 'C6 uCRM down: the Credit sale is registered unpriced, its ids kept', json_encode($r['value']['message'] ?? ''));
    cd_fake($c, ['products_down' => true]);
    $r = $submit(cd_post([CD_PLAN_RES, 12], [[CD_KIT, 1]], ['sales_type' => 'Cash', 'ucrm_total_seen' => '1800']), cd_records(true));
    $c->t(empty($r['value']['success']) && strpos((string)($r['value']['message'] ?? ''), 'No usable price in uCRM') === 0, 'C6 uCRM down: Cash refused');

    // ── C7 the switch on, a form opened before it (marker 5.18.102, plugin ids): read as 'app', Credit P1 ────────────
    cd_fake($c, ['catalogue' => true]);
    $r = $submit(['ucrm_quote_form' => '5.18.102', 'package_choice' => '1', 'device_id' => '3', 'install_by' => 'dishnet'], cd_records(true));
    $a = $app($r);
    $c->eq([!empty($r['value']['success']), $a['quote_sale_entry'] ?? null, (string)($a['package_choice'] ?? ''), $a['quote_refused'] ?? null, count($quotes())],
        [true, 'app', '1', 'P1', 0], 'C7 registered as app, its plugin plan id kept, P1, no quotation', json_encode($r['value']['message'] ?? $r['out']));
    $c->t(strpos((string)($a['quote_refused_text'] ?? ''), 'this sale did not come from the KYC form of this release') !== false, 'C7 the reason is the entry rule\'s');
    cd_fake($c, ['catalogue' => true]);
    $r = $submit(['ucrm_quote_form' => '5.18.102', 'package_choice' => '1', 'device_id' => '3', 'install_by' => 'dishnet', 'sales_type' => 'Cash',
                  'ucrm_total_seen' => '1800', 'ucrm_links_seen' => '1,2,3'], cd_records(true));
    $c->t(empty($r['value']['success']) && strpos((string)($r['value']['message'] ?? ''), 'No usable price in uCRM') === 0 && ($r['value']['apps'] ?? null) === [],
        'C7 … and as Cash, refused before anything is written', json_encode($r['value']['message'] ?? ''));
    // the control on C7: with the switch off, the same 5.18.102 post is priced by its links, as 5.18.102 prices it
    cd_fake($c, ['catalogue' => true]);
    $r = $submit(['ucrm_quote_form' => '5.18.102', 'package_choice' => '1', 'device_id' => '3', 'install_by' => 'dishnet'], cd_records(false));
    $a = $app($r);
    $c->eq([$a['quote_sale_entry'] ?? null, $a['quote_lines_source'] ?? null, count($quotes())], ['web', 'ucrm-5.18.102', 1],
        'C7 control: the switch off, the same post is 5.18.102\'s web sale, quoted by its links');

    // ── C8 South Sudan: the direct marker means nothing; the store is never opened ────────────────────────────────────
    $c->ctl('kyc', '/__test/reset?scenario=sudan');
    $r = $c->submit(cd_post([CD_PLAN_RES, 12], [[CD_KIT, 1]], ['package_choice' => '1', 'device_id' => '3', 'customer_type' => 'StarLink']),
        ['tenant' => 'south-sudan', 'wallet' => $wallet, 'links' => null]);
    $a = $app($r);
    $t = $c->child('return (int)$s->getPdo()->query("SELECT COUNT(*) FROM sqlite_master WHERE name = \'ucrm_catalogue\'")->fetchColumn();',
        ['tenant' => 'south-sudan', 'dir' => $r['dir'], 'tree' => $r['tree']]);
    $c->eq([!empty($r['value']['success']), array_key_exists('quote_item_ids', $a), array_key_exists('quote_sale_entry', $a), (string)($a['package_choice'] ?? ''), $t['value']],
        [true, false, false, '1', 0], 'C8 South Sudan: registered as before, no direct field stored, no ucrm_catalogue table', json_encode($r['value']['message'] ?? $r['out']));
}];
