<?php
declare(strict_types=1);
/**
 * Section B — 5.18.103 (root docs/72 §G.4, §G.6, §G.8, G-3–G-6, G-12): what the forms offer (UcrmQuoteLines::offered()),
 * a direct sale priced (resolveDirect()), stored lines confirmed (confirmDirect()), a standard quotation priced
 * (standardFrom()), the token and the parsers, and the page cache's shape and last good copy. Each through the child
 * process on the judged tree, against the fake uCRM serving the direct-mode catalogue of _lib.php.
 */
$CD_SECTIONS['B'] = ['title' => 'what the forms offer, and a direct sale priced (UcrmQuoteLines)', 'run' => function (QfuCtx $c) {
    $offered = function (array $set = [], ?int $install = CD_INSTALL, array $kept = []) use ($c): array {
        cd_fake($c, $set);
        $r = $c->child(cd_uql_php() . '
$o = $__u->offered(' . var_export($install, true) . ', ' . var_export($kept, true) . ');
$o["reads"] = $__u->reads();
return $o;
');
        return is_array($r['value']) ? $r['value'] : ['_err' => $r['out'] . $r['errlog']];
    };
    $why = function (array $o, string $kind, int $id): ?string {
        foreach ((array)($o['not_offered'] ?? []) as $n) if ($n['kind'] === $kind && (int)$n['id'] === $id) return (string)$n['why'];
        return null;
    };

    // ── B0 the positive control: the catalogue read once, two plan pairs, the hardware, the installation ────────────
    $o = $offered();
    $c->eq($o['ok'] ?? null, true, 'B0 the catalogue reads', (string)($o['_err'] ?? ''));
    $c->eq($o['reads'] ?? null, 1, 'B0 one read for the whole answer');
    $c->eq(array_map(function ($p) { return [$p['sp'], $p['p'], $p['price'], $p['unit']]; }, $o['plans'] ?? []),
        [[CD_PLAN_RES, 12, 100, 'month'], [CD_PLAN_BIZ, 17, 300, 'month']], 'B0 the plan pairs (G-4), by price: product line, the 1-month price, unit month');
    $c->eq(array_map(function ($h) { return $h['p']; }, $o['hardware'] ?? []), [CD_ACC, CD_MINI, CD_KIT],
        'B0 the hardware (G-5), by price; the plans\' products, the installation, a product priced 0 and an archived one left out');
    $c->eq([$o['install']['ok'] ?? null, $o['install']['p'] ?? null, $o['install']['line']['unit'] ?? null], [true, CD_INSTALL, 'Job'],
        'B0 the installation product, judged by its own rules');
    $c->eq([$why($o, 'plan', 8), $why($o, 'product', 80), $why($o, 'product', 81)], ['plan_no_product', 'bad', 'archived'],
        'B0 not offered, each with its why: a plan no product is spelled like, a product priced 0, an archived one');
    $nm = '';
    foreach ((array)($o['not_offered'] ?? []) as $n) if ((int)$n['id'] === 81) $nm = (string)$n['name'];
    $c->eq($nm, 'Old Kit (TEST)', 'B0 a row uCRM filters out is named, not only numbered (excluded_names)');
    $c->t(strlen((string)($o['plans'][0]['token'] ?? '')) === 16, 'B0 every item carries its 16-hex token');

    // ── B1 the plan pair (G-4): counted before judging, exactly one product, same price, the service plan's rules ────
    $o = $offered(['products' => cd_products([18 => ['id' => 18, 'name' => 'Business  Plus TEST', 'price' => 0, 'unit' => 'Pc']])]);
    $c->eq($why($o, 'plan', CD_PLAN_BIZ), 'plan_two_products', 'B1 two products spelled like a plan — one priced 0 — the plan is not offered (counted before judging)');
    $o = $offered(['products' => cd_products([17 => ['price' => 299]])]);
    $c->eq($why($o, 'plan', CD_PLAN_BIZ), 'price_differs', 'B1 the product\'s price differs from the 1-month price: not offered');
    $o = $offered(['service_plans' => cd_plans([CD_PLAN_BIZ => ['periods' => [['period' => 1, 'price' => 300, 'enabled' => false]]]])]);
    $c->eq($why($o, 'plan', CD_PLAN_BIZ), 'no_month', 'B1 no enabled 1-month price: not offered');
    $o = $offered(['service_plans' => cd_plans([9 => ['id' => 9, 'name' => 'Starlink Business Plus (TEST)', 'periods' => [['period' => 1, 'price' => 300, 'enabled' => true]], 'isActive' => false]])]);
    $c->eq($why($o, 'plan', CD_PLAN_BIZ), 'plan_twin', 'B1 another service plan, inactive, spelled alike: not offered');
    $o = $offered(['products' => cd_products([17 => ['taxable' => true]])]);
    $c->eq($why($o, 'plan', CD_PLAN_BIZ), 'bad', 'B1 the plan\'s product taxable: not offered');
    $o = $offered(['products' => cd_products([17 => ['organizationId' => 3]])]);
    $c->eq($why($o, 'plan', CD_PLAN_BIZ), 'plan_no_product', 'B1 the plan\'s only product is another organization\'s: not offered');

    // ── B2 hardware (G-5, G-6): the rules, rule 7, the installation, kept off ─────────────────────────────────────────
    $o = $offered([], CD_INSTALL, [CD_MINI]);
    $c->eq([$why($o, 'product', CD_MINI), in_array(CD_MINI, array_column($o['hardware'] ?? [], 'p'), true)], ['kept_off', false],
        'B2 a product kept off is not offered, and says so');
    $o = $offered([], null);
    $c->eq([in_array(CD_INSTALL, array_column($o['hardware'] ?? [], 'p'), true), $o['install']['ok'] ?? null, $o['install']['why'] ?? null],
        [true, false, 'install_unset'], 'B2 with no installation product set, it is offered as hardware like any product, and the installation is unset');
    $o = $offered(['products' => cd_products([CD_ACC => ['price' => 50.555]])]);
    $c->eq($why($o, 'product', CD_ACC), 'bad', 'B2 more than 2 decimals: not offered');
    $o = $offered(['products' => cd_products([CD_ACC => ['invoiceLabel' => 'Something else']])]);
    $c->eq($why($o, 'product', CD_ACC), 'bad', 'B2 an invoice label unlike the name: not offered');
    $o = $offered(['products' => cd_products([CD_ACC => ['name' => 'Lonely Plan TEST', 'price' => 60]])]);   // 60: not the plan's 50, so no pair
    $c->eq($why($o, 'product', CD_ACC), 'like_plan', 'B2 a product spelled like a service plan (rule 7): never hardware');
    $o = $offered(['products' => cd_products([CD_INSTALL => ['price' => 0]])]);
    $c->eq([$o['install']['ok'] ?? null, $o['install']['why'] ?? null], [false, 'bad'], 'B2 the installation product priced 0: not usable');
    $o = $offered(['products' => cd_products([CD_INSTALL => ['name' => 'Business Plus (TEST) install']])]);
    $c->eq($o['install']['ok'] ?? null, true, 'B2 control: an installation product spelled unlike any plan is usable');
    $o = $offered(['products' => cd_products([CD_INSTALL => ['archived' => true]])]);
    $c->t(($o['install']['ok'] ?? true) === false && strpos((string)($o['install']['reason'] ?? ''), 'archived in uCRM') !== false
        && strpos((string)($o['install']['reason'] ?? ''), 'link') === false,
        'B2 an archived installation product: not usable, said in direct mode\'s words (no link to make, G-3)', (string)($o['install']['reason'] ?? ''));
    $o = $offered(['products_down' => true]);
    $c->eq([$o['ok'] ?? null, $o['plans'] ?? null, $o['hardware'] ?? null], [false, [], []], 'B2 uCRM down: nothing offered (fail closed)');

    // ── B3 resolveDirect (§G.6): priced, every refusal, the names kept, the tokens ──────────────────────────────────
    $resolve = function (array $sale, array $set = [], ?int $install = CD_INSTALL, array $kept = []) use ($c): array {
        cd_fake($c, $set);
        $sale += ['direct_plan' => ['sp' => CD_PLAN_RES, 'p' => 12], 'direct_cart' => [['p' => CD_KIT, 'q' => 2]],
                  'customer_type' => 'StarLink', 'connectivity_type' => 'New Connection', 'install_by' => 'dishnet', 'entry' => 'web-direct'];
        $r = $c->child(cd_uql_php() . 'return $__u->resolveDirect(json_decode(' . var_export(json_encode($sale), true) . ', true), '
            . var_export($install, true) . ', ' . var_export($kept, true) . ');');
        return is_array($r['value']) ? $r['value'] : ['_err' => $r['out'] . $r['errlog']];
    };
    $r = $resolve([]);
    $c->eq([$r['status'] ?? null, $r['total'] ?? null], ['priced', 3300], 'B3 control: plan 100 + 2 × kit 1500 + installation 200', (string)($r['_err'] ?? ''));
    $c->eq(array_map(function ($l) { return [$l['productId'], $l['price'], $l['quantity'], $l['unit']]; }, $r['lines'] ?? []),
        [[12, 100, 1, 'month'], [CD_KIT, 1500, 2, 'Pc'], [CD_INSTALL, 200, 1, 'Job']], 'B3 the lines, uCRM\'s prices and units (a plan: month)');
    $c->eq($r['item_ids'] ?? null, ['plan' => ['sp' => CD_PLAN_RES, 'p' => 12], 'hw' => [['p' => CD_KIT, 'q' => 2]], 'install' => CD_INSTALL], 'B3 item_ids');
    $c->eq([$r['link_ids'] ?? null, $r['offer']['name'] ?? null, $r['device']['title'] ?? null, $r['plan_price'] ?? null, $r['device_price'] ?? null],
        [[], 'Residential Standard (TEST)', 'Standard Kit (TEST)', 100, 1500], 'B3 no link ids; the names the application keeps; the plan and first hardware prices');
    $c->eq($r['tokens'] ?? null, [cd_token(12, 'Residential Standard (TEST)', 100, 'month'), cd_token(CD_KIT, 'Standard Kit (TEST)', 1500, 'Pc'),
        cd_token(CD_INSTALL, 'Professional Installation', 200, 'Job')], 'B3 the tokens: sha256 over id, label, cents and unit, first 16 hex');
    $c->eq($r['cart_rows'] ?? null, [['title' => 'Standard Kit (TEST)', 'price' => 1500, 'qty' => 2]], 'B3 the cart rows the application keeps (no id)');
    $r = $resolve(['install_by' => 'customer']);
    $c->eq([$r['status'] ?? null, $r['total'] ?? null, array_key_exists('install', (array)($r['item_ids'] ?? [])) ? $r['item_ids']['install'] : 'x'], ['priced', 3100, null], 'B3 the customer installs: no installation line');
    $cases = [
        'entry app'        => [['entry' => 'app'], 'entry'],
        'Fiber'            => [['customer_type' => 'Fiber'], 'fiber'],
        'no install answer'=> [['install_by' => ''], 'no_install_answer'],
        'kept off'         => [['direct_cart' => [['p' => CD_MINI, 'q' => 1]]], 'kept_off', [], CD_INSTALL, [CD_MINI]],
        'install as hw'    => [['direct_cart' => [['p' => CD_INSTALL, 'q' => 1]]], 'install_product'],
        'archived hw'      => [['direct_cart' => [['p' => 81, 'q' => 1]]], 'unlisted'],
        'unknown hw'       => [['direct_cart' => [['p' => 999, 'q' => 1]]], 'unlisted'],
        'priced 0 hw'      => [['direct_cart' => [['p' => 80, 'q' => 1]]], 'bad'],
        'plan as hw'       => [['direct_cart' => [['p' => 17, 'q' => 1]]], 'like_plan'],
        'plan not offered' => [['direct_plan' => ['sp' => 8, 'p' => 12]], 'plan_no_product'],
        'plan swapped'     => [['direct_plan' => ['sp' => CD_PLAN_RES, 'p' => 17]], 'plan_product_changed'],
        'install unset'    => [[], 'install_unset', [], null],
        'unreadable'       => [[], 'unreadable', ['products_down' => true]],
    ];
    foreach ($cases as $k => $cs) {
        $r = $resolve($cs[0], $cs[2] ?? [], array_key_exists(3, $cs) ? $cs[3] : CD_INSTALL, $cs[4] ?? []);
        $c->eq([$r['status'] ?? null, $r['why'] ?? null], ['unpriced', $cs[1]], "B3 unpriced: {$k}", (string)($r['reason'] ?? ($r['_err'] ?? '')));
    }
    $r = $resolve(['direct_plan' => null]);
    $c->eq($r['status'] ?? null, 'no_plan', 'B3 no plan pair: no_plan');
    $r = $resolve(['direct_cart' => null]);
    $c->eq([$r['status'] ?? null, $r['why'] ?? null], ['invalid', 'cart'], 'B3 a cart that could not be read: invalid, never priced');
    $r = $resolve(['direct_cart' => [['p' => CD_MINI, 'q' => 1]]], ['products_down' => true]);
    $c->eq([$r['item_ids']['hw'] ?? null, $r['cart_rows'] ?? null], [[['p' => CD_MINI, 'q' => 1]], [['title' => '', 'price' => null, 'qty' => 1]]],
        'B3 uCRM down: the ids still kept for the retry, no names');

    // ── B4 the parsers, the token and the set comparison ────────────────────────────────────────────────────────────
    $r = $c->child('
return [
  "cart" => [UcrmQuoteLines::parseCart(\'[{"p":5,"q":2},{"p":5,"q":3}]\'), UcrmQuoteLines::parseCart(\'[{"p":5,"q":100}]\'),
             UcrmQuoteLines::parseCart(\'[{"p":5,"q":60},{"p":5,"q":40}]\'), UcrmQuoteLines::parseCart(\'[{"p":0,"q":1}]\'),
             UcrmQuoteLines::parseCart(\'{"a":1}\'), UcrmQuoteLines::parseCart("x"), UcrmQuoteLines::parseCart(""),
             UcrmQuoteLines::parseCart(json_encode(array_fill(0, 31, ["p" => 5, "q" => 1]))), UcrmQuoteLines::parseCart(\'[{"p":"7","q":"2"}]\')],
  "plan" => [UcrmQuoteLines::parsePlan("6:12"), UcrmQuoteLines::parsePlan("6:0"), UcrmQuoteLines::parsePlan("6"), UcrmQuoteLines::parsePlan(" 6:12 "),
             UcrmQuoteLines::parsePlan("06:12"), UcrmQuoteLines::parsePlan(["6:12"])],
  "seen" => [UcrmQuoteLines::seenItemsMatch("aaaaaaaaaaaaaaaa,bbbbbbbbbbbbbbbb", ["bbbbbbbbbbbbbbbb", "aaaaaaaaaaaaaaaa"]),
             UcrmQuoteLines::seenItemsMatch("aaaaaaaaaaaaaaaa,aaaaaaaaaaaaaaaa", ["aaaaaaaaaaaaaaaa"]),
             UcrmQuoteLines::seenItemsMatch("aaaaaaaaaaaaaaaa", ["aaaaaaaaaaaaaaaa", "bbbbbbbbbbbbbbbb"]),
             UcrmQuoteLines::seenItemsMatch("", []), UcrmQuoteLines::seenItemsMatch(null, ["aaaaaaaaaaaaaaaa"]),
             UcrmQuoteLines::seenItemsMatch("AAAAAAAAAAAAAAAA", ["aaaaaaaaaaaaaaaa"])],
  "tok" => [UcrmQuoteLines::token(["productId" => 12, "label" => "X", "price" => 100, "unit" => "Month"]),
            UcrmQuoteLines::token(["productId" => 12, "label" => "X", "price" => 100.0, "unit" => "Month"]),
            UcrmQuoteLines::token(["productId" => 12, "label" => "X", "price" => 100.01, "unit" => "Month"]),
            UcrmQuoteLines::token(["productId" => 12, "label" => "Y", "price" => 100, "unit" => "Month"])],
  "ids" => [UcrmQuoteLines::itemIds(["plan" => ["sp" => 6, "p" => 12], "hw" => [["p" => 55, "q" => 2]], "install" => 70]),
            UcrmQuoteLines::itemIds(["plan" => ["sp" => 6, "p" => 12], "hw" => [["p" => 55, "q" => 2], ["p" => 55, "q" => 1]], "install" => null]),
            UcrmQuoteLines::itemIds(["plan" => ["sp" => "6", "p" => 12], "hw" => [], "install" => null]),
            UcrmQuoteLines::itemIds(["plan" => ["sp" => 6, "p" => 12], "hw" => [], "install" => "70"])],
];');
    $v = $r['value'];
    $c->eq($v['cart'] ?? null, [[['p' => 5, 'q' => 5]], null, null, null, null, null, [], null, [['p' => 7, 'q' => 2]]],
        'B4 parseCart: merged, q over 99, merged over 99, id 0, an object, not JSON, empty, 31 entries, digit strings');
    $c->eq($v['plan'] ?? null, [['sp' => 6, 'p' => 12], null, null, ['sp' => 6, 'p' => 12], null, null], 'B4 parsePlan');
    $c->eq($v['seen'] ?? null, [true, true, false, false, false, false], 'B4 seenItemsMatch compares sets; empty, missing and malformed are false');
    $c->t(($v['tok'][0] ?? 'a') === ($v['tok'][1] ?? 'b') && ($v['tok'][0] ?? '') !== ($v['tok'][2] ?? '') && ($v['tok'][0] ?? '') !== ($v['tok'][3] ?? ''),
        'B4 the token: 100 and 100.0 alike (cents); a cent or a letter changes it');
    $c->eq($v['ids'] ?? null, [['plan' => ['sp' => 6, 'p' => 12], 'hw' => [['p' => 55, 'q' => 2]], 'install' => 70], null, null, null],
        'B4 itemIds: well formed; a product twice, a string id and a string installation are refused');

    // ── B5 confirmDirect (§G.8): the rules, never kept off; a price change said in words; the installation as stored ──
    $confirm = function (array $set, array $lines, array $ids) use ($c): string {
        cd_fake($c, $set);
        $r = $c->child(cd_uql_php() . 'return $__u->confirmDirect(json_decode(' . var_export(json_encode($lines), true) . ', true), json_decode('
            . var_export(json_encode($ids), true) . ', true));');
        return is_string($r['value']) ? $r['value'] : 'ERR ' . $r['out'] . $r['errlog'];
    };
    $lines = [['productId' => 12, 'label' => 'Residential Standard (TEST)', 'price' => 100, 'quantity' => 1, 'unit' => 'Month'],
              ['productId' => CD_MINI, 'label' => 'Mini Kit (TEST)', 'price' => 900, 'quantity' => 1, 'unit' => 'Pc'],
              ['productId' => CD_INSTALL, 'label' => 'Professional Installation', 'price' => 200, 'quantity' => 1, 'unit' => 'Job']];
    $ids = ['plan' => ['sp' => CD_PLAN_RES, 'p' => 12], 'hw' => [['p' => CD_MINI, 'q' => 1]], 'install' => CD_INSTALL];
    $c->eq($confirm([], $lines, $ids), '', 'B5 control: the stored lines confirm');
    $got = $confirm(['products' => cd_products([CD_MINI => ['price' => 950]])], $lines, $ids);
    $c->t($got !== '' && strpos($got, 'changed') !== false && !preg_match('/9[05]0/', $got), 'B5 a price changed: refused, in words, never the figure', $got);
    $c->eq($confirm(['products' => cd_products([CD_MINI => ['archived' => true]])], $lines, $ids) !== '', true, 'B5 an item archived since: refused');
    $c->eq($confirm(['products' => cd_products([CD_INSTALL => null, 71 => ['id' => 71, 'name' => 'New Install', 'price' => 200, 'unit' => 'Job']])], $lines, $ids) !== '',
        true, 'B5 the stored installation product gone: refused');
    $c->eq($confirm([], array_slice($lines, 0, 2), $ids) !== '', true, 'B5 a line missing against the ids: refused');
    $c->eq($confirm([], $lines, ['install' => null] + $ids) !== '', true, 'B5 a line not among the ids: refused');

    // ── B6 standardFrom (G-12): whole or nothing ─────────────────────────────────────────────────────────────────────
    cd_fake($c);
    $r = $c->child(cd_uql_php() . '
$o = $__u->offered(70, [57]);
$std = ["title" => "T", "plan" => ["sp" => 6, "p" => 12], "hw" => [["p" => 56, "q" => 2]], "install" => true];
return [
  "ok"      => UcrmQuoteLines::standardFrom($std, $o),
  "plan"    => UcrmQuoteLines::standardFrom(["plan" => ["sp" => 8, "p" => 12]] + $std, $o)["ok"],
  "kept"    => UcrmQuoteLines::standardFrom(["hw" => [["p" => 57, "q" => 1]]] + $std, $o)["ok"],
  "inst"    => UcrmQuoteLines::standardFrom($std, ["install" => ["ok" => false, "reason" => "x"]] + $o)["ok"],
  "noinst"  => UcrmQuoteLines::standardFrom(["install" => false] + $std, ["install" => ["ok" => false, "reason" => "x"]] + $o)["ok"],
  "down"    => UcrmQuoteLines::standardFrom($std, ["ok" => false, "reason" => "down"])["ok"],
];');
    $v = $r['value'];
    $c->eq([$v['ok']['ok'] ?? null, $v['ok']['total'] ?? null, count($v['ok']['lines'] ?? [])], [true, 2100, 3], 'B6 control: 100 + 2 × 900 + 200, three lines');
    $c->eq([$v['plan'] ?? null, $v['kept'] ?? null, $v['inst'] ?? null, $v['noinst'] ?? null, $v['down'] ?? null], [false, false, false, true, false],
        'B6 unusable when its plan, a hardware item (kept off) or the installation is not offered; without installation it needs none');

    // ── B7 the page cache's shape and the last good copy (§G.3, §G.5) ────────────────────────────────────────────────
    cd_fake($c);
    $r = $c->child('
$set = QuoteOrganization::setting($s->load("kyc_config.json") ?? [], $dir);
$lk = UcrmQuoteLinks::fromRecords([], []);
$a = UcrmQuoteLines::forDirectPage($crm, [], $set, $dir);
$okA = !empty($a["uql"]->catalogue()["ok"]);
$cache = json_decode((string)file_get_contents($dir . "/ucrm_quote_catalogue_cache.json"), true);
$hasLast = is_file($dir . "/ucrm_quote_catalogue_last_good.json");
UcrmQuoteLines::dropPageCache($dir);
$lastKept = is_file($dir . "/ucrm_quote_catalogue_last_good.json");
$old = $cache; $old["shape"] = "d14-1"; $old["at"] = time();
file_put_contents($dir . "/ucrm_quote_catalogue_cache.json", json_encode($old));
$down = new class { public function get($u) { return null; } public function withTimeouts($a, $b) { return $this; } };
$b = UcrmQuoteLines::forDirectPage($down, [], $set, $dir);
return ["okA" => $okA, "shape" => $cache["shape"] ?? null, "names" => isset($cache["excluded_names"]), "hasLast" => $hasLast,
        "lastKept" => $lastKept, "staleB" => $b["stale"], "okB" => !empty($b["uql"]->catalogue()["ok"]), "readsB" => $b["uql"]->reads()];
');
    $v = $r['value'];
    $c->eq([$v['okA'] ?? null, $v['shape'] ?? null, $v['names'] ?? null, $v['hasLast'] ?? null], [true, 'd103-1', true, true],
        'B7 a successful page read writes the cache (shape d103-1, with excluded_names) and the last good copy');
    $c->eq($v['lastKept'] ?? null, true, 'B7 dropping the page cache keeps the last good copy');
    $c->eq([$v['staleB'] ?? null, $v['okB'] ?? null, $v['readsB'] ?? null], [true, true, 0],
        'B7 a 5.18.102 cache (shape d14-1) is a miss; with uCRM down the last good copy is drawn, stale, read from nothing');
}];
