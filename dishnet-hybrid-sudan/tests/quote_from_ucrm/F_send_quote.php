<?php
declare(strict_types=1);
/**
 * F — Send Quotation and lead quotes on Uganda (docs/71 §J): lib/QuotationService.php and tabs/sales/send_quote.php,
 * through the entry points staff use — the page, includes/post/post_leads.php, and the service's own methods the API
 * calls — on a copy of the judged tree, against fixtures/fake_ucrm_quote_org.php and the fake Evolution. Where the gate is
 * off (South Sudan, and Uganda with ucrm_quote_organization_id 0 or off) every page, request, WhatsApp text and store row
 * is byte for byte what 5.18.101's release commit (f2055baf) produces. TEST ONLY.
 *
 * 5.18.102 D14 (docs/71 §R.4.5, §R.9.1 F): a lead reaches uCRM in two steps — its interest plan (free text) to a plugin
 * plan row by key, a spelling step that pre-fills only when exactly one row holds that key; then that row's own LINK
 * (ucrm_quote_links.json), never its name. So every scenario's store carries links (record 1: the row "TEST Home" →
 * product 12 "TEST Plan" and its service plan 1), the row is spelled unlike its linked product, and the catalogue holds
 * an unlinked decoy, product 18 / service plan 7 "TEST Home", that a name match would quote; ucrm_product_id 18 is wrong
 * on purpose. Where the gate is off the same link store is present, and must be ignored (F6).
 */
require_once __DIR__ . '/f_lib.php';

/** 5.18.102 D14: the plan rows' stamps (docs/71 §R.1.1 row_stamp), carried by their links. */
const QFU_F_STAMP = '2026-10-01 08:00:0';

/** 5.18.102 D14: one link record of section F's store (docs/71 §R.1.1), active, under the quoting organization 1. */
function qfu_f_link(int $id, int $rowId, int $productId, int $servicePlanId): array
{
    return ['id' => $id, 'item' => 'plan', 'row_id' => $rowId, 'row_stamp' => QFU_F_STAMP . $rowId, 'product_id' => $productId,
            'service_plan_id' => $servicePlanId, 'organization_id' => 1, 'row_name' => 'TEST row ' . $rowId,
            'product_name' => 'TEST product ' . $productId, 'service_plan_name' => 'TEST service plan ' . $servicePlanId,
            'linked_by_id' => 1, 'linked_by_name' => 'TEST Admin', 'linked_at' => '2026-10-01 10:00:00', 'ended_at' => null,
            'ended_by_id' => null, 'ended_why' => null, 'linked_at_ts' => 1790848800 + $id, 'ended_at_ts' => null];
}

/**
 * One run of the steps on one tree for one scenario (qcj_scenario: ug, ss, ss_keyset, ug_off, ug_off_word): every
 * step's result and the uCRM requests it made, the WhatsApp texts, the store's leads / quotes_log / wa_sent_quotes, and
 * PHP's log. $o['down']: uCRM's products answer 503.
 */
function qfu_f_run(QfuCtx $c, string $tree, string $scen, array $o = []): array
{
    $srv  = qfu_servers($c);
    $fake = $srv['fake'];
    $evo  = $srv['evo'];
    $sc   = qcj_scenario($scen);
    $work = $c->dir('f-' . $scen);
    $orgs = qcj_orgs($sc['orgs']);
    foreach ($orgs as $i => $org) $orgs[$i]['selected'] = ((int)$org['id'] === (int)$sc['selected']);
    $corg = $sc['corg'];
    qcj_fake($fake, '/__test/reset');
    qcj_fake($fake, '/__test/set', [
        'organizations' => $orgs,
        'clients'  => [qcj_client(16, $corg, 'Leadone'), qcj_client(17, $corg, 'Manual'), qcj_client(19, $corg, 'Lte')],
        'products' => [
            ['id' => 12, 'name' => 'TEST Plan', 'price' => 250000, 'unit' => 'Month', 'taxable' => false],
            ['id' => 13, 'name' => 'TEST Plus', 'price' => 0, 'unit' => 'Month', 'taxable' => false],
            ['id' => 14, 'name' => 'TEST <b>Gold</b> & Co', 'price' => 400000, 'unit' => 'Month', 'taxable' => false],
            ['id' => 55, 'name' => 'TEST Kit', 'price' => 1500, 'unit' => 'Pc', 'taxable' => false],
            ['id' => 70, 'name' => 'Professional Installation', 'price' => 200, 'unit' => 'Job', 'taxable' => false],
            // 5.18.102 D14: the twins' two products, the Fiber row's, and the decoy spelled like the plan row "TEST Home"
            ['id' => 15, 'name' => 'TEST Twin Alpha', 'price' => 300000, 'unit' => 'Month', 'taxable' => false],
            ['id' => 16, 'name' => 'TEST Twin Beta', 'price' => 350000, 'unit' => 'Month', 'taxable' => false],
            ['id' => 17, 'name' => 'TEST Fiber Fifty', 'price' => 120000, 'unit' => 'Month', 'taxable' => false],
            ['id' => 18, 'name' => 'TEST Home', 'price' => 777000, 'unit' => 'Month', 'taxable' => false],
        ],
        'service_plans' => [
            ['id' => 1, 'name' => 'TEST Plan', 'periods' => [['period' => 1, 'price' => 250000, 'enabled' => true]]],
            ['id' => 2, 'name' => 'TEST Plus', 'periods' => [['period' => 1, 'price' => 0, 'enabled' => true]]],
            ['id' => 3, 'name' => 'TEST <b>Gold</b> & Co', 'periods' => [['period' => 1, 'price' => 400000, 'enabled' => true]]],
            ['id' => 4, 'name' => 'TEST Twin Alpha', 'periods' => [['period' => 1, 'price' => 300000, 'enabled' => true]]],
            ['id' => 5, 'name' => 'TEST Twin Beta', 'periods' => [['period' => 1, 'price' => 350000, 'enabled' => true]]],
            ['id' => 6, 'name' => 'TEST Fiber Fifty', 'periods' => [['period' => 1, 'price' => 120000, 'enabled' => true]]],
            ['id' => 7, 'name' => 'TEST Home', 'periods' => [['period' => 1, 'price' => 777000, 'enabled' => true]]],
        ],
        'products_down' => !empty($o['down']),
        'log' => [],
    ]);
    qcj_http('GET', "{$evo}/__test/reset");

    $cfg = ['kyc_quote_send_via_crm' => '1', 'kyc_quote_notes_prefix' => 'TEST prefix for customers'];
    if ($sc['set'] !== null) $cfg['ucrm_quote_organization_id'] = $sc['set'];
    $ctx = qcj_book(['tree' => $tree, 'dir' => $work, 'fake' => $fake, 'evo' => $evo, 'tenant' => $sc['tenant'],
                     'timezone' => QFU_TZ, 'cfg' => $cfg]);
    $agent = (int)$ctx['agent'];
    qcj_php($ctx, '
        require_once "$TREE/lib/StoreInterface.php"; require_once "$TREE/lib/JsonStore.php"; require_once "$TREE/lib/SqliteStore.php";
        $s = SqliteStore::create($DATA);
        $st = $ARGS["stamp"];
        $s->save("subscription_plans.json", [
            ["id" => 1, "name" => "TEST Home", "customer_price" => 100, "ucrm_product_id" => 18, "is_active" => true, "created_at" => $st . "1"],
            ["id" => 2, "name" => "TEST Plus", "customer_price" => 120, "is_active" => true, "created_at" => $st . "2"],
            ["id" => 3, "name" => "TEST Plan Max", "customer_price" => 300, "is_active" => true, "created_at" => $st . "3"],
            ["id" => 5, "name" => "TEST <b>Gold</b> & Co", "customer_price" => 0, "is_active" => true, "created_at" => $st . "5"],
            ["id" => 6, "name" => "TEST Twin", "customer_price" => 130, "is_active" => true, "created_at" => $st . "6"],
            ["id" => 7, "name" => "test twin", "customer_price" => 140, "is_active" => true, "created_at" => $st . "7"],
            ["id" => 8, "name" => "TEST Fiber 50", "type" => "fiber", "customer_price" => 150, "is_active" => true, "created_at" => $st . "8"],
            ["id" => 9, "name" => "TEST Fifty Starlink", "type" => "starlink", "customer_price" => 160, "is_active" => true, "created_at" => $st . "9"],
        ]);
        // 5.18.102 D14 (docs/71 §R.1): the links, on every scenario — where the gate is off they must be ignored (F6).
        // Row 3 "TEST Plan Max" is not linked; rows 6 and 7 are spelled alike and linked to different products; row 8 is
        // Fiber, and row 9 (Starlink) carries the very same link, the control.
        $s->save("ucrm_quote_links.json", $ARGS["links"]);
        $n = "INTERNAL AI summary: TEST lead notes — never to the customer";
        $a = (int)$ARGS["agent"];
        $s->save("leads.json", [
            ["id" => 1, "customer_name" => "TEST Leadone", "phone" => "+256700000116", "crm_client_id" => 16, "service_type" => "starlink",
             "interest_plan" => "TEST Home", "status" => "open", "notes" => $n, "retailer_id" => $a],
            ["id" => 2, "customer_name" => "TEST Leadtwo", "phone" => "+256700000117", "crm_client_id" => 0, "service_type" => "starlink",
             "interest_plan" => "test plan max", "status" => "open", "notes" => $n, "retailer_id" => $a],
            ["id" => 3, "customer_name" => "TEST Leadthree", "phone" => "+256700000118", "crm_client_id" => 0, "service_type" => "starlink",
             "interest_plan" => "Plan", "status" => "open", "notes" => $n, "retailer_id" => $a],
            ["id" => 4, "customer_name" => "TEST Leadfour", "phone" => "+256700000119", "crm_client_id" => 0, "service_type" => "fiber",
             "interest_plan" => "", "status" => "open", "notes" => $n, "retailer_id" => $a],
            ["id" => 5, "customer_name" => "TEST Leadfive", "phone" => "+256700000120", "crm_client_id" => 0, "service_type" => "starlink",
             "interest_plan" => "TEST Plus", "status" => "open", "notes" => $n, "retailer_id" => $a],
            ["id" => 6, "customer_name" => "TEST Leadsix", "phone" => "+256700000121", "crm_client_id" => 0, "service_type" => "starlink",
             "interest_plan" => "TEST <b>Gold</b> & Co", "status" => "open", "notes" => $n, "retailer_id" => $a],
            ["id" => 7, "customer_name" => "TEST Leadseven", "phone" => "+256700000122", "crm_client_id" => 0, "service_type" => "starlink",
             "interest_plan" => "TEST Twin", "status" => "open", "notes" => $n, "retailer_id" => $a],
        ]);
        qcj_out(true);', ['agent' => $agent, 'stamp' => QFU_F_STAMP, 'links' => [qfu_f_link(1, 1, 12, 1), qfu_f_link(2, 2, 13, 2),
            qfu_f_link(3, 5, 14, 3), qfu_f_link(4, 6, 15, 4), qfu_f_link(5, 7, 16, 5), qfu_f_link(6, 8, 17, 6), qfu_f_link(7, 9, 17, 6)]]);

    $libs = '
require_once "$TREE/lib/currency.php";
require_once "$TREE/lib/StoreInterface.php"; require_once "$TREE/lib/JsonStore.php"; require_once "$TREE/lib/SqliteStore.php";
$dataDir = $DATA; $_PLUGIN_ROOT = $TREE;
$store  = SqliteStore::create($dataDir);
$config = $store->load("kyc_config.json") ?: [];
$rbac   = null;
$__who  = $store->findOne("retailers.json", "id", (int)$ARGS["who"]) ?? [];
';
    $page = function (array $get) use ($ctx, $libs, $agent): string {
        $r = qcj_php($ctx, $libs . '
function csrfField(): string { return \'<input type="hidden" name="_csrf" value="TEST-CSRF">\'; }
$retailer = $__who; $isAdmin = !empty($retailer["is_admin"]); $userRole = (string)($retailer["role"] ?? "");
$_GET = $ARGS["get"];
ob_start(); include "$TREE/tabs/sales/send_quote.php"; $html = ob_get_clean();
qcj_out(["html" => $html]);', ['who' => $agent, 'get' => $get]);
        return is_array($r['out']) ? (string)$r['out']['html'] : ('NO OUTPUT: ' . substr($r['raw'], 0, 2000));
    };
    $post = function (array $p) use ($ctx, $agent): array {
        $r = qcj_post($ctx, 'post_leads.php', $p, $agent);
        unset($r['_raw']);
        return $r;
    };
    $svc = function (string $call, array $args, array $cfgOver = []) use ($ctx, $libs, $agent) {
        $r = qcj_php($ctx, $libs . '
require_once "$TREE/lib/QuotationService.php";
$q = new QuotationService($store, $DATA, array_merge($config, $ARGS["cfg"]));
$a = $ARGS["a"];
switch ($ARGS["call"]) {
    case "manual": qcj_out($q->sendManualQuote($a, $__who)); break;
    case "lead":   qcj_out($q->sendLeadQuote($store->findOne("leads.json", "id", (int)$a["lead"]), $a["items"], $__who, (bool)$a["crm"])); break;
    case "build":  qcj_out($q->buildLeadItems($store->findOne("leads.json", "id", (int)$a["lead"]))); break;
    case "lte":    qcj_out($q->createCrmQuote((int)$a["client"], $a["items"], $a["ref"], $__who, (string)$a["note"], "lte")); break;
}', ['who' => $agent, 'call' => $call, 'a' => $args, 'cfg' => (object)$cfgOver]);
        return $r['out'] ?? ['raw' => substr($r['raw'], 0, 2000)];
    };

    $out = ['steps' => []];
    $mark = count(qcj_fake_log($fake));
    $step = function (string $name, $result) use (&$out, &$mark, $fake) {
        $log = qcj_fake_log($fake);
        $out['steps'][$name] = ['result' => $result, 'requests' => qcj_reqs($log, $mark),
                                'bodies' => array_values(array_map(function ($e) { return $e['body'] ?? null; },
                                    array_filter(array_slice($log, $mark), function ($e) { return ($e['method'] ?? '') !== 'GET'; })))];
        $mark = count($log);
    };
    $cache = $ctx['data'] . '/ucrm_quote_catalogue_cache.json';
    $out['cache_before'] = is_file($cache);
    $step('P1', $page(['qmode' => 'lead']));
    $out['cache_after_P1'] = is_file($cache);
    $step('P2', $page(['qmode' => 'lead']));
    $step('P3', $page(['qmode' => 'manual']));
    foreach ([1, 2, 3, 4, 5, 6, 7] as $id) $step('B' . $id, $svc('build', ['lead' => $id]));

    $kit  = json_encode([['label' => 'TEST Kit', 'quantity' => 1, 'price' => 1500, 'unit' => 'piece']]);
    $zero = json_encode([['label' => 'TEST Kit', 'quantity' => 1, 'price' => 1500, 'unit' => 'piece'],
                         ['label' => 'TEST <i>Mount</i>', 'quantity' => 1, 'price' => '', 'unit' => 'piece']]);
    $step('A1', $post(['action' => 'send_lead_quote', 'lead_id' => '1', 'quote_items_json' => '[]', 'create_crm_quote' => '1']));
    $step('A2', $post(['action' => 'send_lead_quote', 'lead_id' => '3', 'quote_items_json' => '[]', 'create_crm_quote' => '0']));
    $step('A3', $post(['action' => 'send_lead_quote', 'lead_id' => '4', 'quote_items_json' => '[]', 'create_crm_quote' => '0']));
    $step('A4', $post(['action' => 'send_lead_quote', 'lead_id' => '2', 'quote_items_json' => $zero, 'create_crm_quote' => '0']));
    $step('A5', $post(['action' => 'send_lead_quote', 'lead_id' => '1', 'quote_items_json' => $kit, 'create_crm_quote' => '1']));
    // 5.18.102 D14 (§R.4.5): a lead whose interest two plan rows spell alike — no pre-fill, the items asked for by hand
    $step('A6', $post(['action' => 'send_lead_quote', 'lead_id' => '7', 'quote_items_json' => '[]', 'create_crm_quote' => '0']));
    $step('M1', $post(['action' => 'send_manual_quote', 'mq_crm_client_id' => '17', 'mq_customer_name' => '', 'mq_customer_phone' => '',
                       'mq_create_crm' => '1', 'mq_note' => 'TEST footer for the customer', 'mq_items_json' => $kit]));
    $step('M2', $post(['action' => 'send_manual_quote', 'mq_crm_client_id' => '17', 'mq_customer_name' => '', 'mq_customer_phone' => '',
                       'mq_create_crm' => '1', 'mq_note' => '', 'mq_items_json' => $zero]));
    $kitLine = ['label' => 'TEST Kit', 'quantity' => 1, 'price' => 1500, 'unit' => 'piece'];
    $step('S1', $svc('manual', ['crm_client_id' => 17, 'items' => [$kitLine, ['label' => 'TEST "Mount" <b>x</b>', 'quantity' => 1, 'price' => 0, 'unit' => 'piece']],
                                'note' => '', 'create_crm_quote' => true]));
    $step('S2', $svc('manual', ['crm_client_id' => 17, 'items' => [['label' => 'TEST Cable', 'quantity' => 2, 'unit' => 'm']], 'create_crm_quote' => true]));
    $step('S3', $svc('manual', ['crm_client_id' => 17, 'items' => [$kitLine, ['label' => '', 'quantity' => 1, 'price' => '0', 'unit' => 'piece']],
                                'create_crm_quote' => true]));
    $step('S4', $svc('manual', ['crm_client_id' => 17, 'items' => [['label' => 'TEST Bracket', 'quantity' => 1, 'price' => -5, 'unit' => 'piece']],
                                'create_crm_quote' => true]));
    $step('S5', $svc('lead', ['lead' => 5, 'items' => [['label' => 'TEST Pole', 'quantity' => 1, 'price' => ' ', 'unit' => 'piece']], 'crm' => false]));
    $step('S6', $svc('lead', ['lead' => 5, 'items' => [$kitLine], 'crm' => false]));
    $step('L1', $svc('lte', ['client' => 19, 'ref' => 'LTE-TEST-1', 'note' => '', 'items' => [['label' => 'TEST LTE', 'quantity' => 1, 'price' => 300, 'unit' => 'month']]]));
    $step('L2', $svc('lte', ['client' => 19, 'ref' => 'LTE-TEST-2', 'note' => '', 'items' => [['label' => 'TEST LTE', 'quantity' => 1, 'price' => 300, 'unit' => 'month']]],
                     ['kyc_quote_notes_prefix' => '']));
    $step('P4', $page(['qmode' => 'lead']));   // after the sends: the page as staff see it next

    $out['cache_after'] = is_file($cache);
    $out['texts'] = array_map(function ($t) { return ['number' => (string)($t['number'] ?? ''), 'text' => (string)($t['text'] ?? '')]; },
        qcj_evo_texts($evo));
    $out['tables'] = qcj_tables($ctx['data'], ['leads', 'quotes_log', 'wa_sent_quotes']);
    $out['errlog'] = is_file($ctx['errlog']) ? (string)file_get_contents($ctx['errlog']) : '';
    $out['_paths'] = [$ctx['data'] => 'DATA', $work => 'WORK', $tree => 'TREE'];
    return $out;
}

/** The JSON a page assigns to `const NAME = …;` (one line), decoded — null when absent. */
function qfu_f_const(string $html, string $name)
{
    if (!preg_match('/^const ' . preg_quote($name, '/') . '\s*=\s*(.*);$/m', $html, $m)) return null;
    return json_decode($m[1], true);
}

$QFU_SECTIONS['F'] = ['title' => 'Send Quotation and lead quotes: uCRM\'s plan prices, no line at 0, notes for the customer only, gate off unchanged', 'run' => function (QfuCtx $c) {
    $trees = qfu_trees($c);
    $srv = qfu_servers($c);
    if ($srv['fake'] === '' || $srv['evo'] === '') { $c->t(false, 'F the fake uCRM and the fake Evolution started'); return; }
    $c->t($trees['old'] !== null, 'F Git supplies f2055baf (5.18.101\'s release commit) — without it nothing below can be compared: a failure, never a skip');

    $ug = qfu_f_run($c, $trees['new'], 'ug');
    $S = $ug['steps'];
    $body = function (string $step, int $i = 0) use (&$S) { return $S[$step]['bodies'][$i] ?? null; };
    $leads = []; foreach ((array)($ug['tables']['leads'] ?? []) as $l) $leads[(int)$l['id']] = $l;
    $log = (array)($ug['tables']['quotes_log'] ?? []);
    $NOTE = 'INTERNAL AI summary: TEST lead notes — never to the customer';
    $DOT = "\u{00B7}";

    // F0 — the control: a lead naming a plan row is quoted through that row's link, at uCRM's price, with uCRM's product.
    // 5.18.102 D14 (rewritten on purpose, docs/71 §R.4.5): lead → plugin row "TEST Home" by key → that row's link → #12
    // "TEST Plan"; never the decoy #18 spelled like the row, never ucrm_product_id (18), never customer_price (100).
    $q = $body('A1') ?? [];
    $c->eq([$S['A1']['result']['flash'][0]['type'] ?? null, $q['invoiceItems'] ?? null],
        ['success', [['productId' => 12, 'label' => 'TEST Plan', 'price' => 250000, 'quantity' => 1, 'unit' => 'month']]],
        'F0 the control: a lead for "TEST Home", no items typed — quoted through the row\'s link with uCRM\'s product #12 "TEST Plan" at 250000 (never #18 spelled like the row, never customer_price 100)');
    $c->eq($S['A1']['requests'], ['GET /products?limit=500', 'GET /service-plans?limit=500', 'GET /clients/16', 'POST /billing/quotes',
                                  'PATCH /billing/quotes/3500/send', 'GET /organizations'],
        'F0 … the catalogue read fresh for the quote being sent (no page cache), then the company check, the quote and its send');
    $c->t(($leads[1]['status'] ?? '') === 'quoted', 'F0 … and the lead is marked quoted');

    // F1 — no line at price 0: refused, named, nothing written or sent
    $c->eq([$S['A4']['result']['flash'] ?? null, $S['A4']['requests'], $leads[2]['status'] ?? null],
        [[['msg' => 'Quote failed: TEST iMount/i has no price — enter one or remove it', 'type' => 'danger']], [], 'open'],
        'F1 a lead quote with a line of no price: refused naming that line (its label made plain), no uCRM request, the lead still open');
    $c->eq([$S['S1']['result'], $S['S1']['requests']], [['ok' => false, 'error' => 'TEST Mount bx/b has no price — enter one or remove it'], []],
        'F1 Send Quotation (the API\'s manual quote) with a line at 0: the same refusal, before the company check');
    $c->eq($S['S2']['result'], ['ok' => false, 'error' => 'TEST Cable has no price — enter one or remove it'], 'F1 … a line with no price at all: refused');
    $c->eq($S['S3']['result'], ['ok' => false, 'error' => 'A line has no price — enter one or remove it'], 'F1 … a line with no label and "0": named "A line"');
    $c->eq($S['S4']['result'], ['ok' => false, 'error' => 'TEST Bracket has no price — enter one or remove it'], 'F1 … a negative price: refused');
    $c->eq([$S['S5']['result'], $S['S5']['requests']], [['ok' => false, 'error' => 'TEST Pole has no price — enter one or remove it'], []],
        'F1 … a lead quote through the API with a blank price: refused');
    $refs = array_column($log, 'quote_ref');
    $c->t(count($log) === 5 && count(array_filter($log, function ($r) { return ($r['customer_name'] ?? '') === 'TEST Leadtwo'; })) === 0,
        'F1 … no quotes_log row for any refusal: five rows, for the five quotes that were sent (A1, A5, M1, M2, S6)', json_encode($refs));
    $texts = $ug['texts'];
    $c->t(count($texts) === 5 && count(array_filter($texts, function ($t) { return $t['number'] === '256700000117' && strpos($t['text'], 'Mount') !== false; })) === 0,
        'F1 … and no WhatsApp for a refusal: five texts, for the five quotes sent to a customer\'s WhatsApp', json_encode(array_column($texts, 'number')));
    // The panel's manual form drops a line priced 0 before the service sees it (includes/post/post_leads.php, unchanged
    // since 5.18.101): the quote goes without it, and the page's own guard stops such a submit first (send_quote.php).
    $c->eq(array_column((array)($body('M2')['invoiceItems'] ?? []), 'label'), ['TEST Kit'],
        'F1 … the panel\'s manual form (post_leads.php, unchanged) drops a 0 line before the service; the quote carries the kit only');

    // F2 — the notes print on the customer's quotation: the prefix and the sender's own note, nothing internal
    $refRe = '/^Plugin ref: (LEAD|MAN)-\d{6}-\d{3} ' . $DOT . ' Agent: TEST Agent$/u';
    $c->eq([$body('A1')['notes'] ?? null, $body('A5')['notes'] ?? null], ['TEST prefix for customers', 'TEST prefix for customers'],
        'F2 a lead\'s quotation: notes are the notes prefix alone (no "Ref:", no agent)');
    $c->t((bool)preg_match($refRe, (string)($body('A1')['adminNotes'] ?? '')), 'F2 … adminNotes carry the plugin\'s reference and the agent, joined by " · " (U+00B7)',
        (string)($body('A1')['adminNotes'] ?? ''));
    $c->eq($body('M1')['notes'] ?? null, "TEST prefix for customers\nTEST footer for the customer",
        'F2 Send Quotation with a "Note / Footer message": the prefix, then the note');
    $c->t((bool)preg_match($refRe, (string)($body('M1')['adminNotes'] ?? '')), 'F2 … its adminNotes: "Plugin ref: MAN-… · Agent: TEST Agent"', (string)($body('M1')['adminNotes'] ?? ''));
    $c->eq([$body('L1')['notes'] ?? null, $body('L1')['adminNotes'] ?? null], ['TEST prefix for customers', 'Plugin ref: LTE-TEST-1 ' . $DOT . ' Agent: TEST Agent'],
        'F2 an LTE quotation (createCrmQuote): the prefix alone; the reference and the agent in adminNotes');
    $c->eq($body('L2')['notes'] ?? null, '', 'F2 … no prefix and no note: notes are empty (the template\'s NOTES box hides)');
    $c->t(strpos(json_encode(array_column(array_merge(...array_map(function ($s) { return $s['bodies']; }, array_values($S))), 'notes'), JSON_UNESCAPED_UNICODE), 'Ref:') === false,
        'F2 … and no quotation\'s notes carry "Ref:" or the agent');

    // F3 — a lead's notes are internal: never in the customer's WhatsApp, and the saved lead keeps them
    $leadTexts = array_values(array_filter($texts, function ($t) { return in_array($t['number'], ['256700000116', '256700000120'], true); }));
    $c->t(count($leadTexts) === 3 && count(array_filter($leadTexts, function ($t) use ($NOTE) { return strpos($t['text'], 'INTERNAL AI summary') !== false; })) === 0,
        'F3 the three lead WhatsApps (A1, A5, S6) carry none of the lead\'s notes', json_encode(array_column($leadTexts, 'number')));
    $c->t(count(array_filter($leads, function ($l) use ($NOTE) { return ($l['notes'] ?? '') !== $NOTE; })) === 0,
        'F3 … and every lead row still holds its notes (only the message\'s copy was cleared)');

    // F4 — buildLeadItems: the one plan row of exactly the lead's key, then that row's link to uCRM, or none
    $c->eq([$S['B1']['result'], $S['B1']['requests']],
        [[['productId' => 12, 'label' => 'TEST Plan', 'price' => 250000, 'quantity' => 1, 'unit' => 'month']], ['GET /products?limit=500', 'GET /service-plans?limit=500']],
        'F4 a lead for "TEST Home": the row\'s link gives uCRM\'s line, productId 12 included, read fresh');
    $c->eq([$S['B2']['result'], count($S['B2']['requests'])], [[], 2], 'F4 "test plan max" (a plan row with no uCRM link): no line');
    $c->eq([$S['B3']['result'], $S['B3']['requests']], [[], []], 'F4 "Plan" (contained in "TEST Plan Max", the old substring match): no line, and no uCRM request — no plan row has that key');
    $c->eq([$S['B4']['result'], $S['B4']['requests']], [[], []], 'F4 a Fiber lead naming no plan: no line (never the Fiber fee), and no uCRM request');
    $c->eq([$S['B5']['result'], count($S['B5']['requests'])], [[], 2], 'F4 "TEST Plus", a plan uCRM prices at 0: no line (never a placeholder at 0)');
    $c->eq(array_column((array)$S['B6']['result'], 'productId'), [14], 'F4 "TEST <b>Gold</b> & Co": its own product, by its key');
    $c->eq([$S['A2']['result']['flash'] ?? null, $S['A2']['requests'], $S['A3']['requests']],
        [[['msg' => 'Quote failed: No pricing items could be determined for this lead. Please add items manually.', 'type' => 'danger']], [], []],
        'F4 … so a lead quote with no items for such a lead asks for them by hand, and asks uCRM nothing');

    // F5 — the page: the picker priced from uCRM, read once and kept 60 s, the exact-key pre-fill built by the server
    $h1 = (string)$S['P1']['result'];
    $plans = qfu_f_const($h1, 'PLANS');
    $byName = []; foreach ((array)$plans as $p) $byName[(string)$p['name']] = $p;
    $byId = []; foreach ((array)$plans as $p) $byId[(int)($p['id'] ?? 0)] = $p;
    $c->eq([$S['P1']['requests'], $S['P2']['requests'], $S['P3']['requests'], $ug['cache_before'], $ug['cache_after_P1']],
        [['GET /products?limit=500', 'GET /service-plans?limit=500'], [], [], false, true],
        'F5 the first draw reads products and service plans once; the next draws within 60 s read nothing (a successful read is kept)');
    $price = function (string $n) use ($byName) { return array_key_exists($n, $byName) ? $byName[$n]['price'] : 'absent'; };
    $c->eq([$price('TEST Plan'), $price('TEST Plus'), $price('TEST Plan Max'), $price('TEST <b>Gold</b> & Co')],
        [250000, null, null, 400000],
        'F5 the picker: the row "TEST Home" as uCRM\'s "TEST Plan" at 250000 (not 100), TEST Plus and TEST Plan Max with no price, the 0-priced plugin row priced from uCRM');
    // 5.18.102 D14 (§R.5): a priced row is listed by uCRM's name, an unpriced one by the plugin's — row 1 by its link's
    $c->eq([$byId[1]['name'] ?? null, $byId[1]['price'] ?? null, $price('TEST Home'), $byId[3]['name'] ?? null],
        ['TEST Plan', 250000, 'absent', 'TEST Plan Max'],
        'F5 … row 1 is drawn as its linked product "TEST Plan", never as the plugin\'s "TEST Home" (nor the decoy #18 at 777000); the unlinked row 3 by its own name');
    $c->t(strpos((string)($byId[3]['reason'] ?? ''), 'TEST Plan Max is not linked to a uCRM product yet') === 0,
        'F5 … and the unlinked row says so: "TEST Plan Max is not linked to a uCRM product yet — …"', (string)($byId[3]['reason'] ?? ''));
    $c->t(($byName['TEST Plus']['reason'] ?? '') !== '' && ($byName['TEST Plan Max']['reason'] ?? '') !== '',
        'F5 … each unpriced plan carries why', json_encode([$byName['TEST Plus']['reason'] ?? null, $byName['TEST Plan Max']['reason'] ?? null]));
    $c->t(strpos($h1, "p.name + ' — not priced in uCRM'") !== false, 'F5 … and is listed "<name> — not priced in uCRM", never pickable into a line');
    // 5.18.102 D14 review (D14-UI-2, §R.4.1): the picker's alert wraps a resolver reason, which carries its own remedy
    $c->t(strpos($h1, "+ '. It cannot be added from the list — ask an admin, or type the item and its price yourself.');") !== false
        && strpos($h1, 'fix the product in uCRM') === false,
        'F5 … picking it alerts its reason, then "ask an admin, or type the item and its price yourself" — never "fix the product in uCRM" beside a link reason (§R.4.1)');
    $lp = qfu_f_const($h1, 'SQ_LEAD_PLANS');
    $lk = qfu_f_const($h1, 'SQ_LEAD_KEYS');
    $c->eq([$lk['1'] ?? null, $lk['3'] ?? null, $lk['4'] ?? null, $lp['testhome']['price'] ?? null, $lp['testhome']['name'] ?? null, array_key_exists('plan', (array)$lp)],
        ['testhome', 'plan', '', 250000, 'TEST Plan', false],
        'F5 SQ_LEAD_KEYS maps each lead to its plan key and SQ_LEAD_PLANS each plan key to its one row\'s link — "testhome" to uCRM\'s "TEST Plan"; "plan" is not a key');
    preg_match('/^const PLANS = .*$/m', $h1, $pl);
    $c->t(isset($pl[0]) && strpos($pl[0], '<b>') === false && strpos($pl[0], '\u003Cb\u003E') !== false,
        'F5 uCRM\'s names reach the page\'s script escaped (JSON_HEX_TAG): no "<b>" in PLANS');
    $c->t(strpos($h1, 'so the plan list shows no prices') === false, 'F5 … no banner when uCRM answered');

    // F5 — uCRM's products cannot be read: the banner, escaped; nothing kept; nothing pre-filled
    $dn = qfu_f_run($c, $trees['new'], 'ug', ['down' => true]);
    $D = $dn['steps'];
    $hd = (string)$D['P1']['result'];
    $c->t(strpos($hd, "uCRM&#039;s products could not be read, so the plan list shows no prices and a lead's plan is not filled in.") !== false,
        'F5 uCRM\'s products answer 503: the page says so, the reason escaped (htmlspecialchars)');
    $c->eq([$D['P1']['requests'], $D['P2']['requests'], $dn['cache_after']],
        [['GET /products?limit=500'], ['GET /products?limit=500'], false],
        'F5 … one read per draw (service plans not asked once products failed), and a failed read is never kept');
    $c->eq([$D['B1']['result'], $D['A1']['result']['flash'][0]['msg'] ?? null, $D['A1']['bodies']],
        [[], 'Quote failed: No pricing items could be determined for this lead. Please add items manually.', []],
        'F5 … a lead for "TEST Home" gets no line, and its quote asks for the items by hand: nothing sent');

    // F7 — 5.18.102 D14 (docs/71 §R.4.5): two plan rows spelled alike ("TEST Twin", "test twin"), linked to different
    // products. The picker answers per row; the lead pre-fill, a spelling step, finds two rows and fills in nothing.
    // The control: a key one row holds pre-fills (F5's "testhome"), and each twin, by itself, is priced through its link.
    $c->eq([$lk['1'] ?? null, $lp['testhome']['price'] ?? null], ['testhome', 250000],
        'F7 the control: a lead key exactly one plan row holds is pre-filled from that row\'s link (SQ_LEAD_PLANS "testhome")');
    $c->eq([[$byId[6]['name'] ?? null, $byId[6]['price'] ?? null], [$byId[7]['name'] ?? null, $byId[7]['price'] ?? null]],
        [['TEST Twin Alpha', 300000], ['TEST Twin Beta', 350000]],
        'F7 the picker answers per row: "TEST Twin" through its link at #15\'s 300000, "test twin" through its own at #16\'s 350000 — never one answer for the key');
    $c->eq([$lk['7'] ?? null, array_key_exists('testtwin', (array)$lp)], ['testtwin', false],
        'F7 … the lead naming "TEST Twin" has the key "testtwin", and SQ_LEAD_PLANS holds nothing for it: two rows spelled alike, no pre-fill');
    // 5.18.102 D14 review (D14-UI-1): the page names the cause — the server marks the keys two or more rows hold, and the
    // page's own pre-fill (sqUgLeadPrefill, run in node over the drawn script) says "more than one plan … spelled like"
    $c->eq(qfu_f_const($h1, 'SQ_LEAD_SHARED'), ['testtwin'],
        'F7 … SQ_LEAD_SHARED names "testtwin", the key two rows hold — not "testhome" (one row) or "plan" (none)');
    $nodeF = trim((string)shell_exec('command -v node 2>/dev/null'));
    if ($nodeF === '') {
        echo "  note F limitation: node is not available here; the lead hint's text (sqUgLeadPrefill) was not run — a stated limitation, not a pass\n";
    } else {
        $fd = $c->dir('f-hint');
        $js = preg_match('#<script>\s*(const PLANS = .*?)</script>#s', $h1, $sm) ? $sm[1] : '';
        file_put_contents($fd . '/page.js', $js);
        file_put_contents($fd . '/run.js', <<<'JS'
const vm = require('vm'), fs = require('fs');
function any() { const f = function () { return P; };
  const P = new Proxy(f, { get(t, k) { if (k === Symbol.toPrimitive) return () => ''; if (k === 'then') return undefined; return P; },
                           set() { return true; }, apply() { return P; } }); return P; }
const hint = { style: { display: 'none' }, textContent: '' };
const ctx = { console, document: { getElementById: (id) => id === 'leadPlanHint' ? hint : any(), querySelector: () => any(),
              querySelectorAll: () => [], addEventListener() {}, createElement: () => any(), body: any() },
              alert() {}, setTimeout() {}, location: any(), navigator: any(), fetch: () => any() };
ctx.window = ctx;
vm.createContext(ctx);
const out = {};
try {
  vm.runInContext(fs.readFileSync(process.argv[2], 'utf8'), ctx);
  vm.runInContext('var __added = []; addItem = function (c, l, p, q, u) { __added.push([c, l, p, q, u]); }; recalc = function () {};', ctx);
  for (const id of [7, 3, 1]) {
    hint.style.display = 'none'; hint.textContent = '';
    vm.runInContext('__added = []; sqUgLeadPrefill(' + id + ');', ctx);
    out[id] = { hint: hint.style.display === 'none' ? false : hint.textContent, added: vm.runInContext('__added', ctx) };
  }
} catch (e) { out.error = String(e && e.stack || e); }
process.stdout.write(JSON.stringify(out));
JS
        );
        $ran = json_decode((string)shell_exec(escapeshellarg($nodeF) . ' ' . escapeshellarg($fd . '/run.js') . ' ' . escapeshellarg($fd . '/page.js') . ' 2>&1'), true);
        $c->eq([$js !== '', $ran['error'] ?? null, $ran['7']['hint'] ?? 'absent', $ran['7']['added'] ?? 'absent'],
            [true, null, 'More than one plan on the Plans screen is spelled like this lead\'s interest (TEST Twin), so no line was added — add the items yourself.', []],
            'F7 … the hint, by the page\'s own sqUgLeadPrefill: lead 7 ("TEST Twin") is told more than one plan on the Plans screen is spelled like its interest — never "no plan in the list is spelled like" it');
        $c->eq([$ran['3']['hint'] ?? 'absent', $ran['3']['added'] ?? 'absent', $ran['1']['hint'] ?? 'absent', $ran['1']['added'] ?? 'absent'],
            ['No plan in the list is spelled like this lead\'s interest (Plan), so no line was added — add the items yourself.', [], false, [['lead', 'TEST Plan', 250000, 1, 'month']]],
            'F7 … the hint\'s controls: lead 3 ("Plan", no row) keeps "No plan in the list is spelled like…", lead 1 ("TEST Home", one row) gets one line "TEST Plan" at 250000 and no hint');
    }
    $c->eq([$S['B7']['result'], $S['B7']['requests']], [[], []],
        'F7 … buildLeadItems gives that lead no line, and asks uCRM nothing (no row was chosen to resolve)');
    $c->eq([$S['A6']['result']['flash'] ?? null, $S['A6']['requests'], $S['A6']['bodies']],
        [[['msg' => 'Quote failed: No pricing items could be determined for this lead. Please add items manually.', 'type' => 'danger']], [], []],
        'F7 … so its lead quote asks for the items by hand — "add items manually" — and nothing is sent');

    // F8 — 5.18.102 D14 (docs/71 §R.4.3): a Fiber plan row is listed without a price even when it is linked. The control:
    // the very same link (product #17, service plan 6) on a Starlink row is priced.
    $c->eq([$byId[9]['name'] ?? null, $byId[9]['price'] ?? null], ['TEST Fiber Fifty', 120000],
        'F8 the control: the Starlink row carrying the link #17 / service plan 6 is priced at 120000, as "TEST Fiber Fifty"');
    $c->eq([$byId[8]['name'] ?? null, $byId[8]['price'] ?? null, $byId[8]['reason'] ?? null],
        ['TEST Fiber 50', null, 'Fiber is not priced from uCRM on this installation'],
        'F8 the Fiber row "TEST Fiber 50", linked the same way: listed by its own name, without a price, with the Fiber reason');

    // F6 — gate off: byte for byte 5.18.101
    if ($trees['old'] === null) return;
    $cmp = function (array $a, array $b): array {
        $pa = $a['_paths']; $pb = $b['_paths']; unset($a['_paths'], $b['_paths']);
        return [qfu_norm($a, $pa), qfu_norm($b, $pb)];
    };
    foreach (['ss' => 'South Sudan', 'ss_keyset' => 'South Sudan with the setting 1', 'ug_off' => 'Uganda with the setting 0', 'ug_off_word' => 'Uganda with the setting off'] as $scen => $what) {
        [$a, $b] = $cmp(qfu_f_run($c, $trees['old'], $scen), qfu_f_run($c, $trees['new'], $scen));
        $nReq = 0; foreach ($a['steps'] as $s) $nReq += count($s['requests']);
        $d = qfu_first_diff($a, $b);
        $c->t($d === '' && $nReq > 10 && count($a['texts']) > 3,
            "F6 {$what}, with a link store present (ignored): every page, uCRM request, WhatsApp text and store row as f2055baf's ({$nReq} requests, " . count($a['texts']) . ' texts)', $d);
        if ($scen === 'ss') {
            $c->t(count(array_filter($a['texts'], function ($t) use ($NOTE) { return strpos($t['text'], $NOTE) !== false; })) > 0,
                'F6 … the control: there the lead\'s notes do reach its WhatsApp, as before (so F3 measures a real change)');
        }
    }
    [$a, $b] = $cmp(qfu_f_run($c, $trees['old'], 'ug'), $ug);
    $c->t(qfu_first_diff($a, $b) !== '', 'F6 the control on the comparison: on Uganda (gate on) the same steps do differ from f2055baf\'s');
}];
