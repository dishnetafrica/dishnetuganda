<?php
declare(strict_types=1);
/**
 * C — the retries on Uganda (docs/71 §F.4, D11): KycCrmSync (the cron and the admin's Retry) and the debug retry_quote.
 * A Cash application is never re-priced: it is quoted only when its lines total exactly the cash collected. TEST ONLY.
 *
 * 5.18.102 D14 (docs/71 §R.9.1), kept running over the linked seed (tests/quote_from_ucrm/_lib.php). Every marked-lines
 * fixture (C0, C3–C6, C8) seeds quote_sale_entry web and the quote_link_ids its lines came from (E-1, §R.4.4): an
 * application stored without the key is the pre-deploy case, which every retry leaves unpriced. Rewritten on purpose:
 * C2 (E-1: a 5.18.101 application is unpriced at its retry, with the entry reason; the same seeded web resolves by
 * link), C3 (§R.4.4: marked lines re-priced in uCRM since are refused, P2), C4 (§R.4.1's "no longer lists" reason), and
 * C8/C9's retry_quote, whose gated path is now KycService::makeQuotation (§R.4.8): the harness gives it svc() and the
 * admin account the API guard re-reads. C9's submission carries the marker (a hand-made web existing-customer post), and
 * (5.18.102 D14 review, T7) is made again without it: the kept sale then stays unpriced at its retry, with the entry reason.
 * 5.18.102 D14: C's new cases — the retries by link (C10–C12) and Make the quotation (C13–C27: the button, the
 * kyc_make_quote handler, retry_quote's gated path and the one implementation both call) — are c_cases.php's, run at the
 * end of this section through c_lib.php's router and proxy.
 */
require_once __DIR__ . '/c_cases.php';

$QFU_SECTIONS['C'] = ['title' => 'The retries: marked lines re-confirmed, lines resolved now, cash never re-priced', 'run' => function (QfuCtx $c) {
    $fresh = function (array $set = [], string $scenario = 'uganda') use ($c) {
        $c->ctl('kyc', '/__test/reset?scenario=' . $scenario);
        $c->ctl('kyc', '/__test/set', ['catalogue' => true] + $set);
    };
    $quotes = fn() => $c->bodies('kyc', 'POST', '/clients/\d+/quotes');
    $LINES = [['productId' => 12, 'label' => 'Residential Standard (TEST)', 'price' => 100, 'quantity' => 1, 'unit' => 'month'],
              ['productId' => 55, 'label' => 'Standard Kit (TEST)', 'price' => 1500, 'quantity' => 1, 'unit' => 'Pc'],
              ['productId' => 70, 'label' => 'Professional Installation', 'price' => 200, 'quantity' => 1, 'unit' => 'Job']];
    // An application waiting for uCRM, as the form saves one (5.18.102 marks its lines; 5.18.101's do not).
    $pending = function (array $over) {
        return $over + ['id' => 31, 'mobile' => '+256 700 002 031', 'firstname' => 'Test', 'lastname' => 'Retry', 'email' => '',
            'address_1' => 'Test Road', 'customer_type' => 'StarLink', 'connectivity_type' => 'New Connection', 'package_choice' => '1',
            'device_id' => '3', 'kitQty' => '1', 'sales_type' => 'Credit', 'sales_person' => 'Tester', 'retailer_name' => 'Test Agent',
            'priority' => 'Low', 'crm_client_id' => null, 'crm_sync_status' => 'pending', 'submitted_at' => date('Y-m-d H:i:s'),
            'is_lead' => true, 'amount_charged' => 0, 'quote_created' => false, 'status' => 'new'];
    };
    // 5.18.102 D14: marked lines carry the link records they came from (§R.4.4) — the default links 1, 2, 3
    $marked = fn(array $lines, $total, array $ids = [1, 2, 3]) => ['quote_items' => $lines, 'quote_lines_source' => 'ucrm-5.18.102',
                                           'quote_items_total' => $total, 'quote_lines_read_at' => date('Y-m-d H:i:s'), 'quote_link_ids' => $ids];
    // 5.18.102 D14 (E-1): an application this release saved from the web KYC form says so; seeded explicitly
    $WEB = ['quote_sale_entry' => 'web'];
    $ENTRY = 'this sale did not come from the KYC form of this release (the retailer app, the mobile API, a form opened before the update, or an application saved before it), so it is not priced from uCRM';
    $sync = function (array $app, array $o = []) use ($c) {
        return $c->child('
$sync = new KycCrmSync($s, $crm, $s->load("kyc_config.json") ?? []);
$r = $sync->syncOne(' . (int)$app['id'] . ', false, "admin:Tester");
return ["r" => $r, "app" => $s->findOne("kyc_applications.json", "id", ' . (int)$app['id'] . ')];
', $o + ['apps' => [$app]]);
    };
    $a = fn(array $res) => (array)($res['value']['app'] ?? []);

    // C0 — the control: marked lines, Credit — quoted exactly as stored
    $fresh();
    $res = $sync($pending($marked($LINES, 1800) + ['install_by' => 'dishnet'] + $WEB));
    $q = $quotes()[0] ?? [];
    $c->eq([!empty($res['value']['r']['ok']), array_column($q['items'] ?? [], 'productId'), array_column($q['items'] ?? [], 'price'), $q['notes'] ?? null],
        [true, [12, 55, 70], [100, 1500, 200], ''], 'C0 the control: a waiting application with marked lines is created and quoted with them');
    $c->eq($q['adminNotes'] ?? null, 'KYC application #31 · registration, reached uCRM later · Sales: Tester · Connection: New Connection · Priority: Low · Install: DishNet',
        'C0 … staff\'s notes say it reached uCRM later; nothing internal in the customer\'s');

    // C1 — 5.18.101's shape, Cash: never re-priced, never quoted at the plugin's prices
    $fresh();
    $old = [['label' => 'Residential Standard (TEST)', 'quantity' => 1, 'price' => 90.0, 'unit' => 'month', 'productId' => 12],
            ['label' => 'Standard Kit (TEST)', 'quantity' => 1, 'price' => 1500.0, 'unit' => 'piece', 'productId' => 55]];
    $res = $sync($pending(['quote_items' => $old, 'sales_type' => 'Cash', 'amount_charged' => 1590, 'is_lead' => false]));
    $c->eq([count($quotes()), $a($res)['quote_refused'] ?? null], [0, 'P2'],
        'C1 a 5.18.101 Cash application (lines with a productId, at the plugin\'s prices): no quote — its productIds are not trusted');
    $c->t(strpos((string)($a($res)['quote_refused_text'] ?? ''), 'paid UGX 1,590 at registration — make the quotation in uCRM for that amount') !== false,
        'C1 … the reason names the cash collected', (string)($a($res)['quote_refused_text'] ?? ''));
    $c->t(count($c->bodies('kyc', 'POST', '/payments')) === 1, 'C1 … the cash itself is still posted to uCRM, as before');

    // C2 — 5.18.101's shape, Credit. Rewritten on purpose by E-1 (§R.4.2): an application stored without quote_sale_entry
    // is unpriced at its retry with the entry reason, whatever is linked — no longer D11's rule by the installation answer.
    $fresh();
    $res = $sync($pending(['quote_items' => $old, 'connectivity_type' => 'Shifting Connection'] + $WEB));
    $c->eq(array_column($quotes()[0]['items'] ?? [], 'price'), [100, 1500], 'C2 the control: the same Credit Shifting Connection seeded web is resolved now, by link, at uCRM\'s prices');
    $fresh();
    $res = $sync($pending(['quote_items' => $old, 'connectivity_type' => 'Shifting Connection']));
    $c->eq([count($quotes()), $a($res)['quote_refused'] ?? null, strpos((string)($a($res)['quote_refused_text'] ?? ''), $ENTRY) !== false], [0, 'P1', true],
        'C2 a 5.18.101 Credit Shifting Connection, stored without quote_sale_entry: unpriced at its retry with the entry reason (E-1)');
    $fresh();
    $res = $sync($pending(['quote_items' => $old]));
    $c->eq([count($quotes()), $a($res)['quote_refused'] ?? null, strpos((string)($a($res)['quote_refused_text'] ?? ''), $ENTRY) !== false], [0, 'P1', true],
        'C2 … and a 5.18.101 Credit New Connection the same (E-1)');

    // C3 — marked Cash: quoted when the lines total the cash and each still stands in uCRM at its stored price
    $fresh();
    $res = $sync($pending($marked($LINES, 1800) + ['install_by' => 'dishnet', 'sales_type' => 'Cash', 'amount_charged' => 1800, 'is_lead' => false] + $WEB));
    $c->eq([count($quotes()), array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $quotes()[0]['items'] ?? []))], [1, 1800],
        'C3 marked Cash lines totalling the cash collected: quoted at what was paid');
    // rewritten on purpose by §R.4.4: a stored line whose price uCRM has changed since is refused (it was quoted as stored)
    $fresh(['products' => qfu_kyc_products([55 => ['price' => 1700]])]);
    $res = $sync($pending($marked($LINES, 1800) + ['install_by' => 'dishnet', 'sales_type' => 'Cash', 'amount_charged' => 1800, 'is_lead' => false] + $WEB));
    $c->eq([count($quotes()), $a($res)['quote_refused'] ?? null], [0, 'P2'],
        'C3 … the kit re-priced to 1700 in uCRM since: no quote, P2 — never re-priced, and never quoted at a price uCRM no longer has (§R.4.4)');
    $fresh();
    $res = $sync($pending($marked($LINES, 1800) + ['install_by' => 'dishnet', 'sales_type' => 'Cash', 'amount_charged' => 1600, 'is_lead' => false] + $WEB));
    $c->eq([count($quotes()), $a($res)['quote_refused'] ?? null], [0, 'P2'], 'C3 … and lines that do not total it: no quote (P2)');

    // C4 — a stored product since deleted
    // (the reason rewritten on purpose by §R.4.1: the linked product "no longer lists")
    $fresh(['products' => qfu_kyc_products([55 => null])]);
    $res = $sync($pending($marked($LINES, 1800) + ['install_by' => 'dishnet'] + $WEB));
    $c->eq([count($quotes()), $a($res)['quote_refused'] ?? null, strpos((string)($a($res)['quote_refused_text'] ?? ''), 'Standard Kit (TEST) is linked to a uCRM product that uCRM no longer lists — an admin links it again') !== false],
        [0, 'P1', true], 'C4 a marked line whose product has since been deleted: no quote, and which one (its twin 54, spelled alike, is not taken)');

    // C5 — over the auto-quote limit at the retry
    $fresh();
    $res = $sync($pending($marked($LINES, 1800) + ['install_by' => 'dishnet'] + $WEB), ['cfg' => ['kyc_auto_quote_max_amount' => 1000]]);
    $c->eq([count($quotes()), $a($res)['quote_refused'] ?? null, strpos((string)($a($res)['quote_refused_text'] ?? ''), 'over the auto-quote limit of UGX 1,000') !== false],
        [0, 'P3', true], 'C5 over kyc_auto_quote_max_amount: no quote, and the Orders card says so (P3)');

    // C6 — the company check refuses: recorded, and nothing after it
    $fresh(['force_org' => 3], 'two_orgs');
    $res = $sync($pending($marked($LINES, 1800) + ['install_by' => 'dishnet'] + $WEB), ['cfg' => []]);
    $c->eq([count($quotes()), $a($res)['quote_refused'] ?? null, in_array('PATCH /clients/901', $c->requests('kyc'), true)], [0, 'R1', false],
        'C6 a client uCRM files under another company: R1, no quote, no "Prepared by" PATCH');

    // C7 — the gate off: 5.18.101's retry, byte for byte in what it quotes
    $fresh();
    $res = $sync($pending(['quote_items' => $old]), ['cfg' => ['ucrm_quote_organization_id' => 'off']]);
    $c->eq(array_column($quotes()[0]['items'] ?? [], 'price'), [90, 1500], 'C7 Uganda with the setting off: the stored lines as before (the plugin\'s 90)');

    // C8 — the debug retry_quote, as an admin
    // 5.18.102 D14 (§R.4.8): retry_quote's gated path re-reads the admin's account fresh by id ($me2, the API guard's
    // record) and calls svc('kyc')->makeQuotation(): the harness gives it both, as the plugin's api.php does.
    $rq = function (array $app, array $o = []) use ($c) {
        return $c->child('
$act = "retry_quote"; $met = "GET"; $isAdmin = true; $_GET = ["app_id" => "' . (int)$app['id'] . '"];
$config = $s->load("kyc_config.json") ?? []; $dataDir = $dir; $store = $s;
$__rt = $s->load("retailers.json") ?? [];
$__rt[] = ["id" => 1, "name" => "Test Admin", "role" => "admin", "is_admin" => true, "is_active" => true];
$s->save("retailers.json", $__rt);
$me2 = ["id" => 1, "name" => "Test Admin", "is_admin" => true];
$GLOBALS["__qfu_kyc"] = new KycService($crm, $s, new WalletService($s, $s->getPdo()), new CrmQueue($s), $dir);
if (!function_exists("svc")) { function svc(string $n) { if ($n !== "kyc") throw new \RuntimeException("svc: " . $n); return $GLOBALS["__qfu_kyc"]; } }
$out = null;
$er2 = function ($m, $code = 400) use (&$out) { $out = ["code" => $code, "error" => $m]; throw new \RuntimeException("exit"); };
$ok2 = function ($d, $m = "") use (&$out) { $out = ["code" => 200, "data" => $d, "message" => $m]; throw new \RuntimeException("exit"); };
try { include $root . "/includes/api/api_cron_debug.php"; } catch (\RuntimeException $e) { if ($e->getMessage() !== "exit") throw $e; }
return ["out" => $out, "app" => $s->findOne("kyc_applications.json", "id", ' . (int)$app['id'] . ')];
', ['apps' => [$app], 'tree' => $c->tree(['includes']), 'cfg' => ['crm_auth_token' => 'FAKE-ADMIN-TOKEN'] + ($o['cfg'] ?? [])] + $o);
    };
    $created = fn(array $over) => $pending($over + ['crm_client_id' => '901', 'crm_sync_status' => 'synced']);
    $seed901 = ['clients' => [['id' => 901, 'organizationId' => 1, 'firstName' => 'Test', 'lastName' => 'Retry', 'contacts' => []]]];
    $fresh($seed901);
    $res = $rq($created($marked($LINES, 1800) + ['install_by' => 'dishnet', 'sales_type' => 'Cash', 'amount_charged' => 1800] + $WEB));
    $q = $quotes()[0] ?? [];
    $c->eq([$res['value']['out']['code'] ?? null, array_column($q['items'] ?? [], 'productId'), $q['notes'] ?? null, $q['adminNotes'] ?? null],
        [200, [12, 55, 70], '', 'Retry for App #31 · Sales: Tester'], 'C8 the control: retry_quote of a created, unquoted Cash application quotes its marked lines; nothing internal in the notes');
    $fresh($seed901);
    $res = $rq($created(['quote_items' => $old, 'sales_type' => 'Cash', 'amount_charged' => 1590]));
    $c->eq([$res['value']['out']['code'] ?? null, count($quotes()), $a($res)['quote_refused'] ?? null], [409, 0, 'P2'],
        'C8 retry_quote of a 5.18.101 Cash application: 409, no quote, the reason recorded');
    $fresh($seed901);
    $res = $rq($created(['customer_type' => 'Fiber', 'connectivity_type' => 'New Connection'] + $WEB), ['cfg' => ['fiber_install_fee' => 100]]);
    $c->eq([$res['value']['out']['code'] ?? null, count($quotes()), strpos((string)($res['value']['out']['error'] ?? ''), 'Fiber is not priced from uCRM') !== false],
        [409, 0, true], 'C8 retry_quote of a Fiber application on Uganda: 409 — never the Fiber fee');

    // C9 — 5.18.102, after the review (M1/U1): an existing customer's sale that uCRM could not price at submission keeps
    // the sale itself, so retry_quote quotes the sale that was made — every cart item at its quantity, the kit quantity —
    // never the device row × 1. (handleExisting stores no hw_cart_json or kitQty of its own.)
    $devC9 = [['id' => 3, 'title' => 'Standard Kit (TEST)', 'price' => 'USD 1', 'ucrm_product_id' => 98, 'is_active' => true, 'created_at' => QFU_KIT_STAMP],
              ['id' => 4, 'title' => 'Router (TEST)', 'price' => 'USD 1', 'is_active' => true, 'created_at' => '2026-10-01 09:06:00']];
    // 5.18.102 D14: the default links, and the router row's → 56
    $linksC9 = array_merge(qfu_links(), [qfu_link(4, 'kit', 4, '2026-10-01 09:06:00', 56)]);
    $catC9 = [qfu_product(12, 'Residential Standard (TEST)', 100), qfu_product(55, 'Standard Kit (TEST)', 1500),
              qfu_product(56, 'Router (TEST)', 300), qfu_product(70, 'Professional Installation', 200)];
    foreach ([
        'a cart (kit × 2, router × 1)' => [['device_id' => '3', 'kitQty' => '2', 'hw_cart_json' => json_encode([['id' => 3, 'qty' => 2], ['id' => 4, 'qty' => 1]])],
                                           [[12, 1], [55, 2], [56, 1]]],
        'one kit, quantity 2'          => [['device_id' => '3', 'kitQty' => '2'], [[12, 1], [55, 2]]],
    ] as $what => [$sale, $want]) {
        // 5.18.102 D14 review (T7): each sale twice — with the web form's marker (the retry resolves by link), and
        // without it (the mobile API's post: the kept sale stays unpriced at the retry, with the entry reason)
        foreach (['the marker' => [], 'without the marker' => ['ucrm_quote_form' => null]] as $mk => $more) {
            $fresh($seed901 + ['products_down' => true]);
            $sub = $c->submit(['customer_id' => '901', 'connectivity_type' => 'Additional Service', 'install_by' => 'customer'] + $sale + $more,
                              ['devices' => $devC9, 'links' => $linksC9]);
            $saved = (array)(end($sub['value']['apps']) ?: []);
            $c->ctl('kyc', '/__test/set', ['products_down' => false, 'products' => $catC9]);
            $res = $rq($saved, ['dir' => $sub['dir'], 'devices' => $devC9]);
            if ($more === []) {
                $q = $quotes()[0] ?? [];
                $got = array_map(fn($i) => [(int)($i['productId'] ?? 0), (int)($i['quantity'] ?? 0)], (array)($q['items'] ?? []));
                $c->eq([$saved['quote_refused'] ?? null, $saved['quote_sale_entry'] ?? null, $res['value']['out']['code'] ?? null, $got], ['P1', 'web', 200, $want],
                    "C9 an existing customer's sale unpriced at submission ({$what}), retried: quoted as sold");
                continue;
            }
            $rec = (array)($res['value']['app'] ?? []);
            $c->eq([$saved['quote_sale_entry'] ?? null, $saved['quote_sale_cart'] ?? null, $saved['quote_sale_kit_qty'] ?? null,
                    $res['value']['out']['code'] ?? null, strpos((string)($res['value']['out']['error'] ?? ''), $ENTRY) !== false,
                    count($quotes()), $rec['quote_refused'] ?? null, strpos((string)($rec['quote_refused_text'] ?? ''), $ENTRY) !== false],
                   ['app', json_decode($sale['hw_cart_json'] ?? '[]', true), '2', 409, true, 0, 'P1', true],
                   "C9 an existing customer's sale ({$what}) without the marker: stored app with its sale kept; retry_quote 409 with the entry reason, no POST (§R.9.1 C)");
        }
    }

    // C10–C27 — 5.18.102 D14 (docs/71 §R.9.1): the retries by link, and Make the quotation (c_cases.php)
    qfu_c_cases($c, ['sync' => $sync, 'pending' => $pending, 'marked' => $marked, 'LINES' => $LINES, 'ENTRY' => $ENTRY]);
}];
