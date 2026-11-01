<?php
declare(strict_types=1);
/**
 * Section D — 5.18.103 (root docs/72 §G.8, G-10): a direct application retried and quoted. Stored lines re-confirmed by
 * the rules (never "kept off"); an unpriced sale resolved again ("kept off" applying); the Cash rule (P2); P1_DIRECT; the
 * branch chosen by the record, never the switch; a 5.18.102 web application with the switch on (no Make the quotation, the
 * card's remedy); KycCrmSync's create-and-quote, and both gate-off builders refusing a direct record; the Orders card's
 * press and retry_quote through the judged tree's own handlers (tests/quote_from_ucrm/c_lib.php).
 */
require_once dirname(__DIR__) . '/quote_from_ucrm/c_lib.php';

$CD_SECTIONS['D'] = ['title' => 'retries, Make the quotation and the gate off, for a direct application', 'run' => function (QfuCtx $c) {
    $quotes = function () use ($c) { return $c->bodies('kyc', 'POST', '/clients/\d+/quotes'); };
    $LINES = [['productId' => 12, 'label' => 'Residential Standard (TEST)', 'price' => 100, 'quantity' => 1, 'unit' => 'month'],
              ['productId' => CD_KIT, 'label' => 'Standard Kit (TEST)', 'price' => 1500, 'quantity' => 2, 'unit' => 'Pc'],
              ['productId' => CD_INSTALL, 'label' => 'Professional Installation', 'price' => 200, 'quantity' => 1, 'unit' => 'Job']];
    $IDS = ['plan' => ['sp' => CD_PLAN_RES, 'p' => 12], 'hw' => [['p' => CD_KIT, 'q' => 2]], 'install' => CD_INSTALL];
    // A direct application as 5.18.103 stores one (§G.7): priced (lines) or not
    $direct = function (array $over = [], bool $priced = true) use ($LINES, $IDS) {
        $base = ['id' => 41, 'mobile' => '+256 700 003 041', 'firstname' => 'Test', 'lastname' => 'Direct', 'email' => '',
            'address_1' => 'Test Road', 'customer_type' => 'StarLink', 'connectivity_type' => 'New Connection', 'package_choice' => '',
            'device_id' => '', 'kitQty' => '2', 'sales_type' => 'Credit', 'sales_person' => 'Tester', 'retailer_name' => 'Test Agent',
            'priority' => 'Low', 'crm_client_id' => null, 'crm_sync_status' => 'pending', 'submitted_at' => date('Y-m-d H:i:s'),
            'is_lead' => true, 'amount_charged' => 0, 'quote_created' => false, 'status' => 'new', 'install_by' => 'dishnet',
            'quote_sale_entry' => 'web-direct', 'quote_item_ids' => $IDS, 'offer_name' => 'Residential Standard (TEST)',
            'hw_cart_json' => json_encode([['id' => 0, 'title' => 'Standard Kit (TEST)', 'price' => 1500, 'qty' => 2]])];
        $base += $priced
            ? ['quote_items' => $LINES, 'quote_lines_source' => 'ucrm-direct-5.18.103', 'quote_items_total' => 3300, 'offer_price' => 100, 'device_price' => 1500]
            : ['quote_items' => null, 'offer_price' => null, 'device_price' => null, 'quote_unpriced_why' => 'install_unset'];
        return $over + $base;
    };
    $retry = function (array $app, ?array $records, array $set = []) use ($c) {
        cd_fake($c, $set);
        $r = $c->child(cd_seed($records) . cd_uql_php() . '
return KycService::retryLines(json_decode(' . var_export(json_encode($app), true) . ', true), $__u, $s, $s->load("kyc_config.json") ?? [], $__cat);');
        return is_array($r['value']) ? $r['value'] : ['_err' => $r['out'] . $r['errlog']];
    };

    // ── D0 the positive control: stored lines re-confirmed and quoted as stored ──────────────────────────────────────
    $r = $retry($direct(), cd_records(true));
    $c->eq([$r['ok'] ?? null, $r['lines'] ?? null, $r['link_ids'] ?? null], [true, $LINES, []], 'D0 the control: a priced direct application\'s lines re-confirmed, as stored', (string)($r['_err'] ?? ''));

    // ── D1 confirmDirect at retry: a price changed, Cash (P2), kept off does not apply, the switch does not decide ────
    $r = $retry($direct(), cd_records(true), ['products' => cd_products([CD_KIT => ['price' => 1600]])]);
    $c->t(($r['ok'] ?? true) === false && ($r['code'] ?? '') === 'P1' && strpos((string)($r['text'] ?? ''), "Standard Kit (TEST)'s price in uCRM changed after this application was saved") !== false
        && strpos((string)($r['text'] ?? ''), 'Make the quotation in uCRM by hand, or — once the reason is put right') !== false && strpos((string)($r['text'] ?? ''), '1600') === false,
        'D1 a price changed since: P1_DIRECT, in words, no figure', (string)($r['text'] ?? ''));
    $r = $retry($direct(['sales_type' => 'Cash', 'amount_charged' => 3300]), cd_records(true));
    $c->eq([$r['ok'] ?? null, count($r['lines'] ?? [])], [true, 3], 'D1 Cash, lines totalling the cash collected: quoted');
    $r = $retry($direct(['sales_type' => 'Cash', 'amount_charged' => 3300]), cd_records(true), ['products' => cd_products([CD_KIT => ['price' => 1600]])]);
    $c->eq([$r['ok'] ?? null, $r['code'] ?? null], [false, 'P2'], 'D1 Cash, a price changed since: P2 — never re-priced');
    $r = $retry($direct(), cd_records(true, CD_INSTALL, [CD_KIT]));
    $c->eq($r['ok'] ?? null, true, 'D1 a priced sale whose kit is kept off since: still quoted (kept off decides what the form offers, not a sale already made)');
    $r = $retry($direct(), cd_records(false));
    $c->eq([$r['ok'] ?? null, $r['lines'] ?? null], [true, $LINES], 'D1 the switch off: the direct application is still retried by its uCRM ids (G-10)');
    $r = $retry($direct(), cd_records(true, 71));
    $c->eq($r['ok'] ?? null, true, 'D1 the installation product changed since (to one uCRM does not list): the stored one is judged, and still stands');
    $r = $retry($direct(['quote_item_ids' => ['plan' => ['sp' => 'x']]]), cd_records(true));
    $c->eq([$r['ok'] ?? null, $r['code'] ?? null], [false, 'P1'], 'D1 stored ids that cannot be read: not quoted');

    // ── D2 an unpriced direct sale resolved again: kept off applies; fixed, it is priced ─────────────────────────────
    $r = $retry($direct([], false), cd_records(true, null));
    $c->t(($r['ok'] ?? true) === false && strpos((string)($r['text'] ?? ''), 'the installation product is not set') !== false, 'D2 still unpriced while the installation is not set');
    $r = $retry($direct([], false), cd_records(true));
    $c->eq([$r['ok'] ?? null, array_column($r['lines'] ?? [], 'productId')], [true, [12, CD_KIT, CD_INSTALL]], 'D2 the installation set since: priced now, from uCRM');
    $r = $retry($direct([], false), cd_records(true, CD_INSTALL, [CD_KIT]));
    $c->t(($r['ok'] ?? true) === false && strpos((string)($r['text'] ?? ''), 'kept off the forms') !== false,
        'D2 its kit kept off since: stays unpriced on every automatic retry (kept off applies to an unpriced sale)', (string)($r['text'] ?? ''));

    // ── D3 a 5.18.102 web application unlinked: the switch decides its words and its button ──────────────────────────
    $web = ['id' => 42, 'quote_sale_entry' => 'web', 'package_choice' => '1', 'device_id' => '3', 'customer_type' => 'StarLink',
            'connectivity_type' => 'New Connection', 'install_by' => 'dishnet', 'sales_type' => 'Credit', 'quote_items' => null];
    $r = $retry($web, cd_records(true), ['catalogue' => true]);
    $c->eq($r['text'] ?? null, 'This registration was made before the uCRM catalogue was turned on, and its items are the plugin\'s, which are no longer linked — make its quotation in uCRM by hand.',
        'D3 the switch on: a 5.18.102 web sale left unlinked is told to be quoted in uCRM by hand');
    $r = $retry($web, cd_records(false), ['catalogue' => true]);
    $c->t(strpos((string)($r['text'] ?? ''), 'Do not make it by hand — once the item is linked, an admin makes it from the Orders card.') !== false,
        'D3 control: the switch off, the same sale keeps 5.18.102\'s P1_UNLINKED', (string)($r['text'] ?? ''));
    $r = $c->child('
$m = function ($e, $on) { return KycService::canMakeQuotation(["quote_sale_entry" => $e, "crm_client_id" => 901, "quote_refused" => "P1"], $on); };
return [$m("web-direct", true), $m("web-direct", false), $m("web", true), $m("web", false), $m("app", false), $m(null, false),
        KycService::canMakeQuotation(["quote_sale_entry" => "web", "crm_client_id" => 901, "quote_refused" => "P1"])];');
    $c->eq($r['value'], [true, true, false, true, false, false, true], 'D3 canMakeQuotation: web-direct always; web only with the switch off; app never; the old one-argument call as 5.18.102');

    // ── D4 KycCrmSync: a pending direct application created and quoted; with the gate off, never quoted ─────────────
    $sync = function (array $app, ?array $records, array $o = []) use ($c) {
        return $c->child(cd_seed($records) . '
$sync = new KycCrmSync($s, $crm, $s->load("kyc_config.json") ?? []);
$r = $sync->syncOne(' . (int)$app['id'] . ', false, "admin:Tester");
return ["r" => $r, "app" => $s->findOne("kyc_applications.json", "id", ' . (int)$app['id'] . ')];
', $o + ['apps' => [$app]]);
    };
    cd_fake($c);
    $res = $sync($direct(), cd_records(true));
    $a = (array)($res['value']['app'] ?? []);
    $c->eq([count($quotes()), array_column($quotes()[0]['items'] ?? [], 'productId'), array_key_exists('quote_link_ids', $a)], [1, [12, CD_KIT, CD_INSTALL], false],
        'D4 a pending direct application is created and quoted from its stored lines; no link ids recorded', substr($res['errlog'], 0, 600));
    cd_fake($c);
    $res = $sync($direct([], false), cd_records(true, null));
    $a = (array)($res['value']['app'] ?? []);
    $c->eq([count($quotes()), $a['quote_refused'] ?? null], [0, 'P1'], 'D4 a pending unpriced one still unpriced: created, no quotation, P1');
    cd_fake($c);
    $res = $sync($direct(), cd_records(true), ['cfg' => ['ucrm_quote_organization_id' => 'off']]);
    $a = (array)($res['value']['app'] ?? []);
    $c->t(count($quotes()) === 0 && ($a['quote_refused'] ?? null) === 'P1'
        && strpos((string)($a['quote_refused_text'] ?? ''), 'a sale from the uCRM catalogue is not quoted while the quoting organization is off') !== false,
        'D4 the quoting gate off: a direct application is never quoted by the gate-off builder (no free plan line)', (string)($a['quote_refused_text'] ?? ''));
    cd_fake($c);
    $res = $sync($direct(['quote_sale_entry' => 'app']), cd_records(true), ['cfg' => ['ucrm_quote_organization_id' => 'off']]);
    $c->eq(count($quotes()), 1, 'D4 control: the same record not marked web-direct is quoted by the gate-off builder (5.18.101\'s path)');

    // ── D5 the Orders card and retry_quote, through the judged tree's own handlers ───────────────────────────────────
    cd_fake($c);
    $srv = qfu_c_servers($c);
    $dir = qfu_c_seed($c, [$direct(['crm_client_id' => 901, 'crm_sync_status' => 'synced', 'quote_refused' => 'P1'], false)], ['links' => null]);
    qfu_c_table($c, $dir, 'ucrm_catalogue.json', cd_records(true));
    $c->ctl('kyc', '/__test/set', ['clients' => [['id' => 901, 'organizationId' => 1, 'firstName' => 'Test', 'lastName' => 'Direct', 'contacts' => []]]]);
    $p = qfu_c_press($c, $srv, $dir, 41);
    $a = qfu_c_app($dir, 41);
    $c->eq([count($quotes()), !empty($a['quote_created']), array_key_exists('quote_link_ids', $a)], [1, true, false],
        'D5 Make the quotation on an unpriced direct application, now priceable: made, from uCRM, no link ids', json_encode([$p['flash'] ?? null, substr($p['log'] ?? '', 0, 400)]));
    $dir2 = qfu_c_seed($c, [['id' => 42, 'quote_sale_entry' => 'web', 'crm_client_id' => 902, 'quote_refused' => 'P1', 'quote_refused_text' => 'old text',
        'package_choice' => '1', 'device_id' => '3', 'customer_type' => 'StarLink', 'connectivity_type' => 'New Connection', 'install_by' => 'dishnet',
        'sales_type' => 'Credit', 'quote_items' => null, 'quote_created' => false, 'mobile' => '+256 700 003 042']], ['links' => null]);
    qfu_c_table($c, $dir2, 'ucrm_catalogue.json', cd_records(true));
    cd_fake($c);
    $p = qfu_c_press($c, $srv, $dir2, 42);
    $flash = is_array($p['flash'] ?? null) ? (string)($p['flash']['msg'] ?? '') : (string)($p['flash'] ?? '');
    $c->t(count($quotes()) === 0 && strpos($flash, 'Make the quotation does not apply to application #42 now') !== false,
        'D5 the switch on: Make the quotation on a 5.18.102 web application is refused by the handler', json_encode($p['flash'] ?? null));
    // The Orders card as qfu_c_card() draws it, on a data directory holding the uCRM catalogue's records
    $card = function (array $apps, array $records) use ($c, $srv): string {
        $r = $c->child(cd_seed($records) . '
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
if (!function_exists("h")) { function h($s) { return htmlspecialchars((string)($s ?? ""), ENT_QUOTES, "UTF-8"); } }
if (!function_exists("csrfToken")) { function csrfToken() { return "TEST-CSRF"; } }
if (!function_exists("csrfField")) { function csrfField() { return "<input type=\\"hidden\\" name=\\"_csrf\\" value=\\"TEST-CSRF\\">"; } }
require_once $root . "/lib/currency.php";
$store = $s; $config = $s->load("kyc_config.json"); $dataDir = $dir; $isAdmin = true;
$_SERVER["HTTP_HOST"] = "crm.example.test"; $_GET = [];
$myApps = json_decode(' . var_export(json_encode($apps), true) . ', true);
ob_start(); include $root . "/tabs/sales/applications.php"; $html = ob_get_clean();
return ["html" => $html];
', ['tree' => $srv['tree'], 'links' => null]);
        return (string)($r['value']['html'] ?? ('NO OUTPUT ' . substr($r['errlog'], 0, 1500) . ' … ' . substr($r['out'], -1500)));
    };
    $webCard = ['id' => 42, 'firstname' => 'Test', 'lastname' => 'Window', 'mobile' => '+256 700 003 042', 'email' => '', 'address_1' => 'Test Road',
        'customer_type' => 'StarLink', 'connectivity_type' => 'New Connection', 'package_choice' => '1', 'device_id' => '3', 'kitQty' => '1',
        'install_by' => 'dishnet', 'sales_type' => 'Credit', 'sales_person' => 'Tester', 'retailer_name' => 'Test Agent', 'priority' => 'Low',
        'crm_client_id' => '902', 'crm_sync_status' => 'synced', 'submitted_at' => date('Y-m-d H:i:s'), 'status' => 'new', 'amount_charged' => 0,
        'is_lead' => true, 'quote_created' => false, 'quote_sale_entry' => 'web', 'quote_items' => null, 'quote_refused' => 'P1',
        'quote_refused_text' => 'OLD-TEXT once the item is linked'];
    $html = $card([$webCard], cd_records(true));
    $c->t(strpos($html, 'make its quotation in uCRM by hand') !== false && count(qfu_c_buttons($html)) === 0 && strpos($html, 'OLD-TEXT') === false,
        'D5 the card, the switch on: the remedy that works, no Make the quotation', substr($html, 0, 300));
    $html = $card([$webCard], cd_records(false));
    $c->t(strpos($html, 'OLD-TEXT') !== false && count(qfu_c_buttons($html)) === 1,
        'D5 control: the switch off, the stored text and the button, as 5.18.102', substr($html, 0, 300));
    $html = $card([$direct(['crm_client_id' => '904', 'crm_sync_status' => 'synced', 'quote_refused' => 'P1', 'quote_refused_text' => 'DIRECT-TEXT'], false)], cd_records(true));
    $c->t(strpos($html, 'DIRECT-TEXT') !== false && count(qfu_c_buttons($html)) === 1, 'D5 a direct application\'s card: its text and the button', substr($html, 0, 300));

    // ── D6 retry_quote with the gate off refuses a direct application ────────────────────────────────────────────────
    cd_fake($c);
    $dir3 = qfu_c_seed($c, [$direct(['crm_client_id' => 903, 'crm_sync_status' => 'synced'])], ['links' => null, 'cfg' => ['ucrm_quote_organization_id' => 'off', 'crm_auth_token' => 'TEST-TOKEN']]);
    $rq = qfu_c_retry($c, $srv, $dir3, 41);
    $c->t(count($quotes()) === 0 && $rq['code'] === 409 && strpos($rq['body'], 'a sale from the uCRM catalogue is not quoted while the quoting organization is off') !== false,
        'D6 retry_quote, the gate off: refused for a direct application, nothing posted', substr($rq['body'], 0, 300));
}];
