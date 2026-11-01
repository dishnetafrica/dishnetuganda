<?php
declare(strict_types=1);
/**
 * Section R — 5.18.103 (root docs/72 §G.10, G-7, G-8): what 5.18.102 does with what 5.18.103 leaves, after a rollback.
 * 5.18.102's own lib/ and profiles/ are taken from Git (1a0ea5f) by this checkout and run in a child process: its
 * retryLines and canMakeQuotation on a direct application (read as 'app': P1, or P2 where cash was collected; no Make the
 * quotation), its KycCrmSync on a pending one (no quotation), and its handleNew on a direct form still open in a browser
 * (refused, nothing written). Each with its control: the same calls on a 5.18.102 web application.
 */
$CD_SECTIONS['R'] = ['title' => 'the rollback reading: 5.18.102, from Git, on what 5.18.103 leaves', 'run' => function (QfuCtx $c) {
    $checkout = dirname(__DIR__, 2);
    $top = trim((string)shell_exec('git -C ' . escapeshellarg($checkout) . ' rev-parse --show-toplevel 2>/dev/null'));
    $old = $c->dir('tree-1a0ea5f');
    $o = []; exec('git -C ' . escapeshellarg($top !== '' ? $top : $checkout) . ' archive -o ' . escapeshellarg($old . '.tar') . ' '
        . escapeshellarg('1a0ea5f:dishnet-hybrid-sudan') . ' lib profiles 2>&1 && tar -xf ' . escapeshellarg($old . '.tar') . ' -C ' . escapeshellarg($old) . ' 2>&1', $o, $rc);
    @mkdir($old . '/data', 0777, true);
    $c->t($rc === 0 && is_file($old . '/lib/KycService.php') && !is_file($old . '/lib/UcrmCatalogue.php'),
        'R0 the control: Git supplies 5.18.102\'s lib/ (no UcrmCatalogue) — a failure, never a skip', json_encode([$rc, $o]));
    if ($rc !== 0) return;
    $quotes = function () use ($c) { return $c->bodies('kyc', 'POST', '/clients/\d+/quotes'); };
    $LINES = [['productId' => 12, 'label' => 'Residential Standard (TEST)', 'price' => 100, 'quantity' => 1, 'unit' => 'month'],
              ['productId' => CD_KIT, 'label' => 'Standard Kit (TEST)', 'price' => 1500, 'quantity' => 1, 'unit' => 'Pc']];
    $directApp = function (array $over = []) use ($LINES) {
        return $over + ['id' => 61, 'mobile' => '+256 700 004 061', 'firstname' => 'Test', 'lastname' => 'Rollback', 'email' => '',
            'address_1' => 'Test Road', 'customer_type' => 'StarLink', 'connectivity_type' => 'New Connection', 'package_choice' => '',
            'device_id' => '', 'kitQty' => '1', 'sales_type' => 'Credit', 'sales_person' => 'Tester', 'retailer_name' => 'Test Agent',
            'priority' => 'Low', 'crm_client_id' => null, 'crm_sync_status' => 'pending', 'submitted_at' => date('Y-m-d H:i:s'),
            'is_lead' => true, 'amount_charged' => 0, 'quote_created' => false, 'status' => 'new', 'install_by' => 'customer',
            'quote_sale_entry' => 'web-direct', 'quote_item_ids' => ['plan' => ['sp' => CD_PLAN_RES, 'p' => 12], 'hw' => [['p' => CD_KIT, 'q' => 1]], 'install' => null],
            'quote_items' => $LINES, 'quote_lines_source' => 'ucrm-direct-5.18.103', 'quote_items_total' => 1600, 'offer_price' => 100, 'device_price' => 1500,
            'offer_name' => 'Residential Standard (TEST)', 'device_title' => 'Standard Kit (TEST)',
            'hw_cart_json' => json_encode([['id' => 0, 'title' => 'Standard Kit (TEST)', 'price' => 1500, 'qty' => 1]])];
    };

    // ── R1 5.18.102's retryLines and canMakeQuotation on a direct application: 'app' — P1, P2; no button ─────────────
    $c->ctl('kyc', '/__test/reset?scenario=uganda');
    $c->ctl('kyc', '/__test/set', ['catalogue' => true]);
    $r = $c->child('
$set = QuoteOrganization::setting($s->load("kyc_config.json") ?? [], $dir);
$u = new UcrmQuoteLines($crm, $set, UcrmQuoteLinks::fromStore($s, $set));
$app = json_decode(' . var_export(json_encode($directApp()), true) . ', true);
$cash = json_decode(' . var_export(json_encode($directApp(['sales_type' => 'Cash', 'amount_charged' => 1600])), true) . ', true);
$web = $app; $web["quote_sale_entry"] = "web"; $web["package_choice"] = "1"; $web["device_id"] = "3"; $web["hw_cart_json"] = null; unset($web["quote_lines_source"]);
$p1 = KycService::retryLines($app, $u, $s, $s->load("kyc_config.json") ?? []);
$p2 = KycService::retryLines($cash, $u, $s, $s->load("kyc_config.json") ?? []);
$ctl = KycService::retryLines($web, $u, $s, $s->load("kyc_config.json") ?? []);
$btn = function ($e) { return KycService::canMakeQuotation(["quote_sale_entry" => $e, "crm_client_id" => 901, "quote_refused" => "P1"]); };
return ["p1" => [$p1["ok"], $p1["code"], strpos($p1["text"], "did not come from the KYC form of this release") !== false],
        "p2" => [$p2["ok"], $p2["code"]], "ctl" => [$ctl["ok"], count($ctl["lines"])], "btn" => [$btn("web-direct"), $btn("web")]];
', ['tree' => $old]);
    $v = $r['value'];
    $c->eq($v['p1'] ?? null, [false, 'P1', true], 'R1 5.18.102\'s retry on a direct Credit application: the entry rule, P1 — no quotation', substr($r['errlog'] . $r['out'], 0, 600));
    $c->eq($v['p2'] ?? null, [false, 'P2'], 'R1 … on a direct Cash application: P2 — no quotation');
    $c->eq($v['ctl'] ?? null, [true, 2], 'R1 control: a 5.18.102 web application beside it is priced by its links');
    $c->eq($v['btn'] ?? null, [false, true], 'R1 5.18.102 offers no Make the quotation for a direct application (its control: a web one)');

    // ── R2 5.18.102's KycCrmSync on a pending direct application: created, never quoted ─────────────────────────────
    $c->ctl('kyc', '/__test/reset?scenario=uganda');
    $c->ctl('kyc', '/__test/set', ['catalogue' => true]);
    $r = $c->child('
$sync = new KycCrmSync($s, $crm, $s->load("kyc_config.json") ?? []);
$res = $sync->syncOne(61, false, "admin:Tester");
return ["app" => $s->findOne("kyc_applications.json", "id", 61)];
', ['tree' => $old, 'apps' => [$directApp()]]);
    $a = (array)($r['value']['app'] ?? []);
    $c->eq([count($quotes()), $a['quote_refused'] ?? null, !empty($a['crm_client_id'])], [0, 'P1', true],
        'R2 5.18.102\'s cron on a pending direct application: the client created, no quotation, P1', substr($r['errlog'], 0, 600));

    // ── R3 5.18.102's handleNew on a direct form still open in a browser: refused, nothing written ──────────────────
    $c->ctl('kyc', '/__test/reset?scenario=uganda');
    $c->ctl('kyc', '/__test/set', ['catalogue' => true]);
    $post = cd_post([CD_PLAN_RES, 12], [[CD_KIT, 1]], ['action' => 'kyc_submit', 'customer_type' => 'StarLink', 'connectivity_type' => 'New Connection',
        'firstname' => 'Test', 'lastname' => 'Open', 'mobile' => '+256 700 004 099', 'address_1' => 'Test Road', 'sales_type' => 'Credit',
        'priority' => 'Low', 'sales_person' => 'Tester']);
    $post = array_filter($post, function ($x) { return $x !== null; });
    $r = $c->child('
$w = new WalletService($s, $s->getPdo());
$k = new KycService($crm, $s, $w, new CrmQueue($s), $dir);
$res = $k->process(json_decode(' . var_export(json_encode($post), true) . ', true), [], ["id" => 7, "name" => "Test Agent", "role" => "sales", "is_admin" => false]);
return ["success" => !empty($res["success"]), "message" => (string)($res["message"] ?? ""), "apps" => $s->load("kyc_applications.json") ?? []];
', ['tree' => $old]);
    $creates = $c->bodies('kyc', 'POST', '/clients');
    $c->eq([$r['value']['success'] ?? null, $r['value']['message'] ?? null, $r['value']['apps'] ?? null, $creates], [false, 'Please select a valid package/plan.', [], []],
        'R3 5.18.102 refuses a direct form\'s post (no package_choice): nothing written, uCRM not asked', substr($r['errlog'] . $r['out'], 0, 600));
}];
