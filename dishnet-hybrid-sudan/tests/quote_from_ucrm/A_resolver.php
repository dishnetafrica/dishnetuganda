<?php
declare(strict_types=1);
/**
 * A — the resolver, UcrmQuoteLines (docs/71 §D, §R.3, §R.4), in this process, against an in-memory client and store.
 * TEST ONLY.
 *
 * 5.18.102 D14 (docs/71 §R.9.1), rewritten on purpose: every item reaches uCRM through the link an admin made
 * (UcrmQuoteLinks, read from the store as every construction site reads it), never a name, never ucrm_product_id. The
 * plan row is "Home Plan (TEST)", spelled unlike its linked product "Residential Standard (TEST)"; product 13 is spelled
 * exactly like that row and never linked; 54 and 55 are two products spelled like the kit row, 55 linked. So every
 * priced case proves the link decided, not the spelling. A5–A7 are rewritten by §R.9.1 (an empty key, two products
 * spelled alike, a stored id), A8/A9 judge the linked service plan, A11 the linked product's name, A4/A15's reasons
 * follow §R.4.1; A21–A33 are new (the link states, packages, Fiber rows, the installation by link, re-pricing, the page
 * cache's shape, the summary's service-plan list, rule 8, the entry rule).
 */

/** A client that answers GET products / service-plans from arrays, and counts what it was asked. */
final class QfuFakeCrm
{
    public $products;
    public $plans;
    public array $calls = [];
    public ?array $timeouts = null;
    public function __construct($products, $plans) { $this->products = $products; $this->plans = $plans; }
    public function get(string $path)
    {
        $this->calls[] = $path;
        if (strpos($path, 'products') === 0) return $this->products;
        if (strpos($path, 'service-plans') === 0) return $this->plans;
        return null;
    }
    public function getBaseUrl(): string { return 'http://fake.test/api/v2.1'; }
    public function withTimeouts(int $c, int $t): self { $x = clone $this; $x->timeouts = [$c, $t]; $x->calls = &$this->calls; return $x; }
}

/** A store holding the plugin lists and the link records; findOne() by key, load() a whole list (null when absent). */
final class QfuFakeStore
{
    public array $d;
    public function __construct(array $d) { $this->d = $d; }
    public function findOne(string $f, string $k, $v)
    {
        foreach ($this->d[$f] ?? [] as $r) if ((string)($r[$k] ?? '') === (string)$v) return $r;
        return null;
    }
    public function load(string $f) { return $this->d[$f] ?? null; }
}

$QFU_SECTIONS['A'] = ['title' => 'The resolver (UcrmQuoteLines): read, rows, links, lines, every line, Fiber, reasons, totals, the summary\'s list, rule 8, the entry rule', 'run' => function (QfuCtx $c) {
    require_once $c->root . '/lib/UcrmQuoteLines.php';   // it loads lib/UcrmQuoteLinks.php itself (F-6)
    $ON = ['on' => true, 'id' => 1, 'source' => 'profile', 'error' => ''];
    $P = fn(int $id, string $n, $price, array $m = []) => qfu_product($id, $n, $price, $m);
    $SP = fn(int $id, string $n, $price, ?array $per = null) => qfu_plan($id, $n, $price, $per);
    // uCRM: 12 and service plan 6 the plan's; 13 spelled like the plan row, never linked; 54 and 55 spelled alike, 55
    // linked; 56 the mount's; 70 the installation's
    $PRODUCTS = [12 => $P(12, 'Residential Standard (TEST)', 100, ['unit' => 'Month']), 13 => $P(13, 'Home Plan (TEST)', 95, ['unit' => 'Month']),
                 54 => $P(54, 'Standard Kit (TEST)', 1400), 55 => $P(55, 'Standard Kit (TEST)', 1500), 56 => $P(56, 'Mount (TEST)', 50),
                 70 => $P(70, 'Professional Installation', 200, ['unit' => 'Job'])];
    // $cat([id => fields to change | a whole new product | null to remove], $plans): the catalogue uCRM serves
    $cat = function (array $prod = [], ?array $plans = null) use ($PRODUCTS, $SP): array {
        $p = $PRODUCTS;
        foreach ($prod as $id => $set) {
            if ($set === null) { unset($p[$id]); continue; }
            $p[$id] = isset($p[$id]) ? array_merge($p[$id], $set) : $set;
        }
        return ['products' => array_values($p), 'plans' => $plans ?? [$SP(6, 'Residential Standard (TEST)', 100)]];
    };
    $S = ['p1' => QFU_PLAN_STAMP, 'p2' => '2026-10-01 09:01:00', 'p3' => '2026-10-01 09:02:00', 'p4' => '2026-10-01 09:03:00',
          'p5' => '2026-10-01 09:04:00', 'k3' => QFU_KIT_STAMP, 'k4' => '2026-10-01 09:06:00', 'k5' => '2026-10-01 09:07:00',
          'k6' => '2026-10-01 09:08:00', 'pk9' => '2026-10-01 09:20:00'];
    $ROWS = [
        'subscription_plans.json' => [
            1 => ['id' => 1, 'name' => 'Home Plan (TEST)', 'customer_price' => 90, 'ucrm_product_id' => 99, 'is_active' => true, 'created_at' => $S['p1']],
            2 => ['id' => 2, 'name' => 'Starlink', 'customer_price' => 90, 'is_active' => true, 'created_at' => $S['p2']],
            3 => ['id' => 3, 'name' => 'Odd <b>Plan</b> & Co', 'customer_price' => 90, 'is_active' => true, 'created_at' => $S['p3']],
            4 => ['id' => 4, 'name' => 'Fibre Home (TEST)', 'type' => 'fiber', 'customer_price' => 90, 'is_active' => true, 'created_at' => $S['p4']],
            5 => ['id' => 5, 'name' => 'Residential Standard (TEST)', 'customer_price' => 90, 'is_active' => true, 'created_at' => $S['p5']],
        ],
        'kyc_packages.json' => [9 => ['id' => 9, 'name' => 'App Package (TEST)', 'amount' => 80, 'created_at' => $S['pk9']]],
        'kyc_devices.json' => [
            3 => ['id' => 3, 'title' => 'Standard Kit (TEST)', 'price' => 'USD 1', 'ucrm_product_id' => 98, 'is_active' => true, 'created_at' => $S['k3']],
            4 => ['id' => 4, 'title' => 'Mount (TEST)', 'price' => '7', 'is_active' => true, 'created_at' => $S['k4']],
            5 => ['id' => 5, 'title' => 'Residential Standard (TEST)', 'price' => '9', 'is_active' => true, 'created_at' => $S['k5']],
            6 => ['id' => 6, 'title' => 'Fibre ONU (TEST)', 'type' => 'fiber', 'price' => '4', 'is_active' => true, 'created_at' => $S['k6']],
        ],
    ];
    // The links: 1 plan row 1 → 12 and 6; 2 kit row 3 → 55; 3 the installation → 70; 4 kit row 4 → 56. Plan rows 2–5 and
    // the kit rows 5 and 6 are not linked.
    $LK = [1 => qfu_link(1, 'plan', 1, $S['p1'], 12, 6), 2 => qfu_link(2, 'kit', 3, $S['k3'], 55),
           3 => qfu_link(3, 'install', 0, '', 70), 4 => qfu_link(4, 'kit', 4, $S['k4'], 56)];
    // $L([id => a record | null]): the default links with records replaced, added or removed
    $L = function (array $chg = []) use ($LK): array {
        $l = $LK;
        foreach ($chg as $id => $r) { if ($r === null) unset($l[$id]); else $l[$id] = $r; }
        return array_values($l);
    };
    $sale = fn(array $o = []) => $o + ['package_choice' => 1, 'device_id' => 3, 'kitQty' => 1, 'hw_cart' => [],
        'customer_type' => 'StarLink', 'connectivity_type' => 'New Connection', 'install_by' => 'dishnet', 'entry' => 'web'];
    // The resolver over the rows and the links, its judge built by UcrmQuoteLinks::fromStore as every construction site
    // builds it. $o: 'links' (all records), 'rows' (file => [id => row | null]), 'setting'.
    $mkStore = function (array $o = []) use ($ROWS, $L): QfuFakeStore {
        $d = $ROWS;
        foreach ($o['rows'] ?? [] as $f => $chg) {
            foreach ($chg as $id => $row) { if ($row === null) unset($d[$f][$id]); else $d[$f][$id] = $row; }
        }
        $d = array_map('array_values', $d);
        $d['ucrm_quote_links.json'] = $o['links'] ?? $L();
        return new QfuFakeStore($d);
    };
    $res = function (array $catalogue, array $s, array $o = []) use ($ON, $mkStore) {
        $store = $mkStore($o);
        $set = $o['setting'] ?? $ON;
        $crm = new QfuFakeCrm($catalogue['products'], $catalogue['plans']);
        $u = new UcrmQuoteLines($crm, $set, UcrmQuoteLinks::fromStore($store, $set));
        return [$u->resolve($s, $store), $crm, $u, $store];
    };
    $NOT_LINKED = fn(string $row, string $screen) => "{$row} is not linked to a uCRM product yet — an admin links it on the {$screen} screen";

    // A0 — the control: a whole sale resolves through its links, with uCRM's ids, names and prices
    [$r0, $crm0, $u0] = $res($cat(), $sale());
    $c->eq([$r0['status'], array_column($r0['lines'], 'productId'), array_column($r0['lines'], 'price'), $r0['total']],
        ['priced', [12, 55, 70], [100.0, 1500.0, 200.0], 1800.0],
        'A0 the control: plan, kit and installation from their linked uCRM products, total 1800');
    $c->eq([$r0['lines'][0]['label'] ?? null, $r0['plan_label'] ?? null, $r0['plan_price'], $r0['device_price']],
        ['Residential Standard (TEST)', 'Residential Standard (TEST)', 100.0, 1500.0],
        'A0 … the label is the linked product\'s name, not the row\'s "Home Plan (TEST)"; the prices are uCRM\'s, not the plugin\'s 90 or "USD 1"');
    $c->eq([$r0['link_ids'] ?? null, $r0['entry'] ?? null, $r0['unlinked'] ?? null], [[1, 2, 3], 'web', false],
        'A0 … with the link records its lines came from (quote_link_ids), and the sale\'s entry point');

    // A1 — the read
    $c->eq($crm0->calls, ['products?limit=500', 'service-plans?limit=500'], 'A1 one read of each list, with limit=500');
    $u0->resolve($sale(), $mkStore());
    $c->eq([$u0->reads(), count($crm0->calls)], [1, 2], 'A1 … read once per instance: a second resolve asks nothing more');

    // A2 — what a read is
    [$r499] = $res(['products' => array_merge($cat()['products'], array_map(fn($i) => $P(2000 + $i, 'Filler ' . $i, 1), range(1, 493))),
                    'plans' => $cat()['plans']], $sale());
    $c->eq([count($cat()['products']) + 493, $r499['status']], [499, 'priced'], 'A2 the control: 499 rows is a whole list, and resolves');
    foreach ([
        'a raw answer'          => [['raw' => 'x'], $cat()['plans']],
        'an error (null)'       => [null, $cat()['plans']],
        'not a list'            => [['a' => $P(1, 'X', 1)], $cat()['plans']],
        'a full page'           => [array_map(fn($i) => $P(1000 + $i, 'Filler ' . $i, 1), range(1, 500)), $cat()['plans']],
        'plans unreadable'      => [$cat()['products'], ['raw' => '']],
        'a row that is no array'=> [[$P(12, 'Residential Standard (TEST)', 100), 'oops'], $cat()['plans']],
    ] as $what => [$pr, $pl]) {
        [$rx] = $res(['products' => $pr, 'plans' => $pl], $sale());
        $c->eq([$rx['status'], $rx['reason']], ['unpriced', "uCRM's products could not be read"], "A2 {$what}: unreadable, nothing used");
    }
    [$rf, $crmF2] = $res(['products' => null, 'plans' => $cat()['plans']], $sale());
    $c->eq($crmF2->calls, ['products?limit=500'], 'A2 … and once products could not be read, service plans are not asked for (one wait, not two)');

    // A3/A4 — the rows that count, judged on the linked product and service plan (§R.4.1's words)
    [$rx] = $res($cat([12 => ['organizationId' => 1, 'isActive' => true, 'archived' => false]]), $sale());
    $c->eq($rx['status'], 'priced', 'A4 the control: the linked product active, not archived, of the quoting organization — counts');
    foreach ([
        'isActive false'       => [['isActive' => false], 'not active'],
        'archived true'        => [['archived' => true], 'archived'],
        'another organization' => [['organizationId' => 2], "another organization's"],
    ] as $what => [$flag, $word]) {
        [$rx] = $res($cat([12 => $flag]), $sale());
        $c->eq([$rx['status'], $rx['reason']],
            ['unpriced', "Home Plan (TEST) is linked to a uCRM product that is {$word} in uCRM — an admin links it again"],
            "A4 the linked product with {$what} does not count: unpriced, with that word");
        [$rx] = $res($cat([], [$SP(6, 'Residential Standard (TEST)', 100) + $flag]), $sale());
        $c->eq([$rx['status'], $rx['reason']],
            ['unpriced', "Home Plan (TEST) is linked to a uCRM service plan that is {$word} in uCRM — an admin links it again"],
            "A4 the linked service plan with {$what}: unpriced, \"service plan\" for \"product\"");
    }
    [$rx] = $res($cat([55 => ['archived' => true]]), $sale());
    $c->eq($rx['reason'], 'Standard Kit (TEST) is linked to a uCRM product that is archived in uCRM — an admin links it again',
        'A4 a kit\'s linked product archived since: unpriced — its unlinked twin 54, spelled alike, is not taken instead');

    // A5/A6/A7 — rewritten by D14 (§R.9.1): no key, no spelling, no stored id; the link alone
    [$rx] = $res($cat(), $sale(['package_choice' => 2]), ['links' => $L([5 => qfu_link(5, 'plan', 2, $S['p2'], 12, 6)])]);
    $c->eq([$rx['status'], $rx['lines'][0]['productId'] ?? null, $rx['lines'][0]['label'] ?? null], ['priced', 12, 'Residential Standard (TEST)'],
        'A5 a row named only "Starlink" (an empty key) is priced through its link');
    [$rx] = $res($cat([80 => $P(80, 'Starlink', 10)]), $sale(['package_choice' => 2]));
    $c->eq([$rx['status'], $rx['reason'], $rx['unlinked'] ?? null], ['unpriced', $NOT_LINKED('Starlink', 'Plans'), true],
        'A5 … and with no link it is unpriced, though a product is named "Starlink"');
    $c->eq([$r0['lines'][1]['productId'] ?? null, $r0['lines'][1]['price'] ?? null], [55, 1500.0],
        'A6 two products spelled alike ("Standard Kit (TEST)", 54 and 55): the linked id, 55, is quoted');
    [$rx] = $res($cat(), $sale(), ['links' => $L([2 => qfu_ended($LK[2]), 5 => qfu_link(5, 'kit', 3, $S['k3'], 54)])]);
    $c->eq([$rx['status'], $rx['lines'][1]['productId'] ?? null, $rx['lines'][1]['price'] ?? null, $rx['link_ids'] ?? null], ['priced', 54, 1400.0, [1, 3, 5]],
        'A6 … relinked to its twin 54, the twin is quoted at its own price: the link chooses, never the spelling');
    $stored = $cat([99 => $P(99, 'Something Else (TEST)', 90), 98 => $P(98, 'Another Thing (TEST)', 2)]);
    [$rx] = $res($stored, $sale());
    $c->eq([$rx['status'], array_column($rx['lines'], 'productId')], ['priced', [12, 55, 70]],
        'A7 the rows\' stored ucrm_product_id (99, 98, both listed) is never read: the link decides');
    $c->t($r0['status'] === 'priced' && array_intersect([13, 54], array_column($r0['lines'], 'productId')) === [],
        'A7 … the product spelled exactly like the plan row ("Home Plan (TEST)", 13), and the kit\'s twin 54, both unlinked, are never used');
    [$rx] = $res($stored, $sale(), ['links' => $L([1 => null])]);
    $c->eq([$rx['status'], $rx['reason']], ['unpriced', $NOT_LINKED('Home Plan (TEST)', 'Plans')],
        'A7 … and neither ucrm_product_id nor the product spelled like the row rescues a plan with no link');
    [$rx] = $res($stored, $sale(), ['links' => $L([2 => null])]);
    $c->eq([$rx['status'], $rx['reason']], ['unpriced', $NOT_LINKED('Standard Kit (TEST)', 'Hardware')],
        'A7 … nor a kit with no link, though two products are spelled like it and its ucrm_product_id is listed');

    // A8/A9 — the plan line, by the linked service plan
    [$rx] = $res($cat([], []), $sale());
    $c->eq($rx['reason'], 'Home Plan (TEST) is linked to a uCRM service plan that uCRM no longer lists — an admin links it again',
        'A8 the linked service plan no longer listed: unpriced');
    [$rx] = $res($cat([], [$SP(7, 'Residential Standard (TEST)', 100)]), $sale());
    $c->eq($rx['reason'], 'Home Plan (TEST) is linked to a uCRM service plan that uCRM no longer lists — an admin links it again',
        'A8 … even with a service plan of the same name under another id: the link names 6');
    [$rx] = $res($cat([12 => null]), $sale());
    $c->eq($rx['reason'], 'Home Plan (TEST) is linked to a uCRM product that uCRM no longer lists — an admin links it again',
        'A8 the plan\'s linked product no longer listed: unpriced — product 13, spelled like the row, is not taken instead');
    [$rx] = $res($cat([], [$SP(6, 'Residential Standard (TEST)', 120)]), $sale());
    $c->eq($rx['reason'], "Home Plan (TEST)'s price in Products (100) differs from its service plan (120)",
        'A9 the linked product priced unlike its service plan\'s month: unpriced, both prices named');
    [$rx] = $res($cat([], [$SP(6, 'Residential Standard (TEST)', 0, [['period' => 3, 'price' => 100, 'enabled' => true]])]), $sale());
    $c->eq($rx['reason'], 'Home Plan (TEST) has no 1-month price in uCRM', 'A9 only a 3-month period, at the product\'s price: unpriced (never planPrice\'s cheapest period)');
    [$rx] = $res($cat([], [$SP(6, 'Residential Standard (TEST)', 0, [['period' => 1, 'price' => 90, 'enabled' => false], ['period' => 6, 'price' => 100, 'enabled' => true]])]), $sale());
    $c->eq($rx['status'], 'unpriced', 'A9 a disabled 1-month period and a 6-month one at the product\'s price: unpriced');
    [$rx] = $res($cat([], [['id' => 6, 'name' => 'Residential Standard (TEST)', 'price' => 100]]), $sale());
    $c->eq($rx['status'], 'unpriced', 'A9 a top-level price with no periods: unpriced');
    [$rx] = $res($cat([12 => ['price' => 100.004]]), $sale());
    $c->t($rx['status'] === 'unpriced', 'A9 … the comparison is at 2 dp, and a price with more decimals is unpriced');
    [$rx] = $res($cat([], [$SP(6, 'Residential Plus (TEST)', 100)]), $sale());
    $c->eq($rx['reason'], "Home Plan (TEST)'s uCRM product and service plan are not spelled alike, so the quotation's WhatsApp summary could not show it as monthly — an admin fixes the names in uCRM",
        'A9 the linked product and service plan spelled apart: unpriced (the summary could not show it as monthly)');

    // A10 — the plan line's unit
    $c->eq($r0['lines'][0]['unit'] ?? null, 'month', 'A10 the plan line\'s unit is always month, whatever the product says (Month)');
    [$rx] = $res($cat([12 => ['unit' => 'Pc']]), $sale());
    $c->eq($rx['lines'][0]['unit'] ?? null, 'month', 'A10 … even Pc');

    // A11 — kits and accessories, by their links; the kit rule judges the linked product's name
    [$rx] = $res($cat(), $sale(['device_id' => 5]), ['links' => $L([5 => qfu_link(5, 'kit', 5, $S['k5'], 56)])]);
    $c->eq([$rx['status'], $rx['lines'][1]['productId'] ?? null, $rx['lines'][1]['label'] ?? null], ['priced', 56, 'Mount (TEST)'],
        'A11 the control: a kit row spelled like a service plan, linked to a hardware product, is priced — the row\'s own spelling is not judged');
    [$rx] = $res($cat(), $sale(['device_id' => 4]), ['links' => $L([4 => qfu_link(4, 'kit', 4, $S['k4'], 12)])]);
    $c->eq([$rx['status'], $rx['reason']], ['unpriced', 'Mount (TEST) is spelled like a service plan in uCRM, so it cannot be quoted as hardware'],
        'A11 the kit rule, by the linked product\'s name: a kit row linked to a product spelled like a service plan is refused');
    [$rx] = $res($cat(), $sale(['hw_cart' => [['id' => '3', 'title' => 'BOGUS', 'price' => 1, 'qty' => 2], ['id' => '4', 'title' => 'x', 'price' => 'USD 9', 'qty' => 0]]]));
    $c->eq([array_column($rx['lines'], 'label'), array_column($rx['lines'], 'price'), array_column($rx['lines'], 'quantity'), $rx['link_ids'] ?? null],
        [['Residential Standard (TEST)', 'Standard Kit (TEST)', 'Mount (TEST)', 'Professional Installation'], [100.0, 1500.0, 50.0, 200.0], [1, 2, 1, 1], [1, 2, 3, 4]],
        'A11 the cart by id, each row by its link: the browser\'s title and price are never used; quantity at least 1');
    [$rx] = $res($cat(), $sale(['hw_cart' => [['id' => '77', 'qty' => 1]]]));
    $c->eq($rx['reason'], "an item in the cart is not in the plugin's hardware list", 'A11 a cart id the plugin does not hold: unpriced');
    [$rx] = $res($cat(), $sale(['hw_cart' => [['id' => '3', 'qty' => 1], ['id' => '4', 'qty' => 1]]]), ['links' => $L([4 => null])]);
    $c->eq([$rx['status'], $rx['reason'], $rx['unlinked'] ?? null], ['unpriced', $NOT_LINKED('Mount (TEST)', 'Hardware'), true],
        'A11 a cart item whose row is not linked: unpriced, and marked "not linked"');
    [$rx] = $res($cat(), $sale(['device_id' => 0]));
    $c->eq(array_column($rx['lines'], 'productId'), [12, 70], 'A11 no device: the plan (and installation) alone');

    // A12 — installation, by its link
    [$rx] = $res($cat(), $sale(['kitQty' => 2]));
    $c->eq([end($rx['lines'])['label'] ?? null, end($rx['lines'])['quantity'] ?? null, $rx['total']], ['Professional Installation', 1, 3300.0],
        'A12 DishNet installs: one installation line per application, even with two kits (D8)');
    [$rx] = $res($cat(), $sale(['install_by' => 'customer']));
    $c->eq([array_column($rx['lines'], 'productId'), $rx['total'], $rx['link_ids'] ?? null], [[12, 55], 1600.0, [1, 2]],
        'A12 the customer installs: no installation line, and no installation link among the records');
    foreach (['' => 'no answer', 'yes' => 'an answer that is neither'] as $v => $what) {
        [$rx] = $res($cat(), $sale(['install_by' => $v]));
        $c->eq([$rx['status'], $rx['reason']], ['unpriced', UcrmQuoteLines::NO_INSTALL_ANSWER], "A12 a Starlink New Connection with {$what}: unpriced, never guessed");
    }
    foreach (['Shifting Connection', 'Ownership Change'] as $conn) {
        [$rx] = $res($cat(), $sale(['connectivity_type' => $conn, 'install_by' => '']));
        $c->eq([$rx['status'], array_column($rx['lines'], 'productId')], ['priced', [12, 55]], "A12 {$conn}: no question and no line");
        $c->t(!UcrmQuoteLines::needsInstallAnswer('StarLink', $conn), "A12 … needsInstallAnswer says so for {$conn}");
    }
    $c->t(UcrmQuoteLines::needsInstallAnswer('StarLink', 'Additional Service') && !UcrmQuoteLines::needsInstallAnswer('LTE', 'New Connection'),
        'A12 the existing customer\'s additional service asks; LTE does not');
    [$rx] = $res($cat([70 => null]), $sale());
    $c->eq($rx['reason'], 'Installation is linked to a uCRM product that uCRM no longer lists — an admin links it again',
        'A12 DishNet installs, and the installation\'s linked product is no longer listed: unpriced');
    [$rx] = $res($cat(), $sale(), ['links' => $L([3 => null])]);
    $c->eq([$rx['status'], $rx['reason'], $rx['unlinked'] ?? null], ['unpriced', $NOT_LINKED('Installation', 'Hardware'), true],
        'A12 DishNet installs, and the installation is not linked: unpriced, and marked "not linked"');

    // A13 — every line, judged on the linked product (unchanged texts, §R.4.1)
    [$rx] = $res($cat([55 => ['invoiceLabel' => 'STANDARD kit (test)', 'taxId' => null, 'taxable' => false]]), $sale());
    $c->eq($rx['status'], 'priced', 'A13 the control: an invoice label spelled like the name, a null taxId and taxable false are fine');
    foreach ([
        [55, ['price' => 0], "Standard Kit (TEST)'s price in uCRM is 0", 'a kit at 0'],
        [70, ['price' => 0], "Installation's price in uCRM is 0", 'installation at 0'],
        [55, ['price' => 1500.555], "Standard Kit (TEST)'s price in uCRM has more than 2 decimals", 'more than 2 decimals'],
        [55, ['price' => null], 'Standard Kit (TEST) has no price in uCRM', 'no price'],
        [55, ['price' => 'abc'], 'Standard Kit (TEST) has no price in uCRM', 'a price that is not a number'],
        [55, ['taxable' => true], "Standard Kit (TEST) is set as taxable in uCRM; the cash would not equal the quotation's total", 'taxable'],
        [55, ['taxId' => 4], "Standard Kit (TEST) is set as taxable in uCRM; the cash would not equal the quotation's total", 'a taxId'],
        [70, ['taxable' => true], "Installation is set as taxable in uCRM; the cash would not equal the quotation's total", 'the installation taxable'],
        [12, ['taxable' => true], "Home Plan (TEST) is set as taxable in uCRM; the cash would not equal the quotation's total", 'the plan\'s product taxable'],
        [55, ['invoiceLabel' => 'Something Else'], "Standard Kit (TEST)'s invoice label in uCRM differs from its name", 'an invoice label of another key'],
    ] as [$id, $set, $why, $what]) {
        [$rx] = $res($cat([$id => $set]), $sale());
        $c->eq([$rx['status'], $rx['reason']], ['unpriced', $why], "A13 {$what}: unpriced");
    }
    [$rx] = $res($cat([12 => ['price' => 0]], [$SP(6, 'Residential Standard (TEST)', 0)]), $sale());
    $c->eq($rx['reason'], "Home Plan (TEST)'s price in uCRM is 0", 'A13 a plan mirror at 0 whose month is 0 too: unpriced (equal is not enough)');

    // A14 — Fiber
    [$rx] = $res($cat(), $sale(['customer_type' => 'Fiber']));
    $c->eq([$rx['status'], $rx['reason']], ['unpriced', 'Fiber is not priced from uCRM on this installation'], 'A14 Fiber on Uganda: always unpriced');

    // A15 — reasons are plain: the row's name passes through QuoteOrganization::plain
    [$rx] = $res($cat(), $sale(['package_choice' => 3]));
    $c->t($rx['status'] === 'unpriced' && strpbrk($rx['reason'], '<>&"') === false && $rx['reason'] === $NOT_LINKED('Odd bPlan/b  Co', 'Plans'),
        'A15 a row name with <b> and & reaches the "not linked" reason plain', $rx['reason']);
    [$rx] = $res($cat([12 => ['archived' => true]]), $sale(['package_choice' => 3]), ['links' => $L([5 => qfu_link(5, 'plan', 3, $S['p3'], 12, 6)])]);
    $c->t($rx['status'] === 'unpriced' && strpbrk($rx['reason'], '<>&"') === false && strpos($rx['reason'], 'Odd bPlan/b  Co is linked to a uCRM product that is archived') === 0,
        'A15 … and a linked row\'s reason too', $rx['reason']);

    // A16 — no plan row
    [$rx, $crmN, $uN] = $res($cat(), $sale(['package_choice' => 42]));
    $c->eq([$rx['status'], $uN->reads(), $crmN->calls], ['no_plan', 0, []], 'A16 no plan row: no_plan, and uCRM is not asked');

    // A17 — totals; the posted total; the posted link records (§R.4.7)
    $c->eq(UcrmQuoteLines::total([['price' => 0.1, 'quantity' => 3], ['price' => 0.2, 'quantity' => 1]]), 0.5, 'A17 the total: Σ price × quantity at 2 dp');
    $pt = [];
    foreach (['1800', '1800.00', ' 1800 ', '1799.99', '1,800', '2,499,000', '', '1800.0.0', '-1800'] as $v) $pt[$v] = UcrmQuoteLines::postedTotalMatches($v, 1800.0);
    $pt['null'] = UcrmQuoteLines::postedTotalMatches(null, 1800.0);
    $c->eq($pt, ['1800' => true, '1800.00' => true, ' 1800 ' => true, '1799.99' => false, '1,800' => false, '2,499,000' => false,
                 '' => false, '1800.0.0' => false, '-1800' => false, 'null' => false], 'A17 the posted total: a plain decimal equal at 2 dp, nothing else');
    $sl = [];
    foreach (['1,2,3', '1,2', '1,2,3,4', '', '1;2;3', '1,,2,3', 'x', '0'] as $v) $sl[$v] = UcrmQuoteLines::seenLinksMatch($v, [1, 2, 3]);
    $sl['null'] = UcrmQuoteLines::seenLinksMatch(null, [1, 2, 3]);
    $sl['array'] = UcrmQuoteLines::seenLinksMatch(['1', '2', '3'], [1, 2, 3]);
    $c->eq($sl, ['1,2,3' => true, '1,2' => false, '1,2,3,4' => false, '' => false, '1;2;3' => false, '1,,2,3' => false, 'x' => false, '0' => false,
                 'null' => false, 'array' => false], 'A17 the posted link records: exactly the resolved set, comma-joined digits; missing or malformed is no match');

    // A18 — drawing a page (the links are read live, never cached)
    $pageLinks = UcrmQuoteLinks::fromRecords($L(), array_values($ROWS['kyc_devices.json']));
    $pd = $c->dir('page');
    $crmP = new QfuFakeCrm($cat()['products'], $cat()['plans']);
    $u1 = UcrmQuoteLines::forPage($crmP, $ON, $pd, $pageLinks);
    $okRead = $u1->catalogue()['ok'];
    $u2 = UcrmQuoteLines::forPage($crmP, $ON, $pd, $pageLinks);
    $u2->catalogue();
    $c->eq([$okRead, count($crmP->calls), is_file($pd . '/' . UcrmQuoteLines::CACHE_FILE)], [true, 2, true], 'A18 a page draw reads once, and a second within 60 s reads nothing');
    $pd2 = $c->dir('page');
    $crmE = new QfuFakeCrm(null, null);
    UcrmQuoteLines::forPage($crmE, $ON, $pd2, $pageLinks)->catalogue();
    UcrmQuoteLines::forPage($crmE, $ON, $pd2, $pageLinks)->catalogue();
    $c->eq([is_file($pd2 . '/' . UcrmQuoteLines::CACHE_FILE), count($crmE->calls)], [false, 2], 'A18 a failure is never cached: the next draw asks again (one read each: products failed, so plans are not asked)');
    $crmT = new QfuFakeCrm($cat()['products'], $cat()['plans']);
    $seen = null;
    $crmT2 = new class($crmT, $seen) {
        private $inner; public $seen;
        public function __construct($i, &$s) { $this->inner = $i; }
        public function withTimeouts(int $a, int $b) { $this->seen = [$a, $b]; return $this->inner->withTimeouts($a, $b); }
        public function get(string $p) { return $this->inner->get($p); }
    };
    UcrmQuoteLines::forPage($crmT2, $ON, $c->dir('page'), $pageLinks)->catalogue();
    $c->eq($crmT2->seen, [3, 5], 'A18 page reads use 3 s to connect and 5 s in all');
    $cachedRaw = (string)@file_get_contents($pd . '/' . UcrmQuoteLines::CACHE_FILE);
    $c->t($cachedRaw !== '' && strpos($cachedRaw, 'linked_by') === false && strpos($cachedRaw, 'row_stamp') === false,
        'A18 … and the page cache holds uCRM\'s lists only, never a link record (the links are read live)');

    // A19 — the quote's own client
    require_once $c->root . '/lib/CrmApiClient.php';
    $plug = new CrmApiClient('http://plugin.test/api/v2.1', 'KEY', 'X-Auth-App-Key');
    $qc1 = UcrmQuoteLines::quoteClient($plug, []);
    $qc2 = UcrmQuoteLines::quoteClient($plug, ['crm_auth_token' => 'TOKEN']);
    $qc3 = UcrmQuoteLines::quoteClient($plug, ['crm_auth_token' => 'TOKEN', 'crm_base_url' => 'http://other.test/api/v2.1']);
    $c->eq([$qc1 === $plug, $qc2->getAuthHeader(), $qc2->getAppKey(), $qc2->getBaseUrl(), $qc3->getBaseUrl()],
        [true, 'x-auth-token', 'TOKEN', 'http://plugin.test/api/v2.1', 'http://other.test/api/v2.1'],
        'A19 the quote\'s client: the admin token when set (crm_base_url if given), else the plugin\'s own key');

    // A20 — confirming stored lines, by their link records (§R.4.4)
    $stC = $mkStore();
    $uC = new UcrmQuoteLines(new QfuFakeCrm($cat()['products'], $cat()['plans']), $ON, UcrmQuoteLinks::fromStore($stC, $ON));
    $c->eq($uC->confirmLines($r0['lines'], [1, 2, 3], $stC), '', 'A20 the control: stored lines confirmed by their link records, each in force, at the price stored');
    $c->eq($uC->confirmLines([['productId' => 404, 'label' => 'Gone <i>Kit</i>', 'price' => 1, 'quantity' => 1]], [2], $stC),
        'the uCRM link of Gone iKit/i was changed after this application was saved',
        'A20 a stored line whose product its link no longer names: refused, the label named plain, no figure');
    $c->eq($uC->confirmLines($r0['lines'], [], $stC), 'the uCRM link of Residential Standard (TEST) was changed after this application was saved',
        'A20 … and marked lines without quote_link_ids are refused the same way');

    // A21 — each link state of §R.4.1 (the control is A0)
    $rowP1 = $ROWS['subscription_plans.json'][1];
    [$rx] = $res($cat(), $sale(), ['rows' => ['subscription_plans.json' => [1 => array_diff_key($rowP1, ['created_at' => 1]) + ['imported_at' => $S['p1']]]]]);
    $c->eq($rx['status'], 'priced', 'A21 the control: a row stamped by the import tool (imported_at, no created_at) keeps its link');
    [$rx] = $res($cat(), $sale(['package_choice' => 5]));
    $c->eq([$rx['status'], $rx['reason'], $rx['unlinked'] ?? null], ['unpriced', $NOT_LINKED('Residential Standard (TEST)', 'Plans'), true],
        'A21 not linked: a plan row spelled exactly like a product and its service plan is unpriced, marked "not linked"');
    [$rx] = $res($cat(), $sale(['device_id' => 4]), ['links' => $L([4 => null])]);
    $c->eq([$rx['status'], $rx['reason'], $rx['unlinked'] ?? null], ['unpriced', $NOT_LINKED('Mount (TEST)', 'Hardware'), true],
        'A21 not linked: a kit row spelled exactly like a product ("Mount (TEST)", 56) is unpriced, the Hardware screen named');
    [$rx] = $res($cat(), $sale(), ['rows' => ['subscription_plans.json' => [1 => ['created_at' => '2026-10-20 08:00:00'] + $rowP1]]]);
    $c->eq([$rx['status'], $rx['reason'], $rx['unlinked'] ?? null],
        ['unpriced', 'Home Plan (TEST) was linked before this plugin row was replaced — an admin links it again', false],
        'A21 row replaced (another stamp under the same id): the old link is not used');
    [$rx] = $res($cat(), $sale(), ['rows' => ['kyc_devices.json' => [3 => ['created_at' => '2026-10-20 08:00:00'] + $ROWS['kyc_devices.json'][3]]]]);
    $c->eq($rx['reason'], 'Standard Kit (TEST) was linked before this plugin row was replaced — an admin links it again', 'A21 … a kit row too');
    [$rx] = $res($cat(), $sale(), ['links' => $L([5 => qfu_link(5, 'plan', 1, $S['p1'], 12, 6)])]);
    $c->eq([$rx['status'], $rx['reason']], ['unpriced', 'Home Plan (TEST) has two uCRM links recorded — an admin links it again'],
        'A21 two active links, both naming the same product: treated as no link at all (fail closed), one text');
    [$rx] = $res($cat(), $sale(), ['links' => $L([5 => qfu_link(5, 'install', 0, '', 70)])]);
    $c->eq($rx['reason'], 'Installation has two uCRM links recorded — an admin links it again', 'A21 … the installation\'s too');
    [$rx] = $res($cat(), $sale(), ['links' => $L([1 => qfu_link(1, 'plan', 1, $S['p1'], 12, 6, ['organization_id' => 2])])]);
    $c->eq([$rx['status'], $rx['reason']], ['unpriced', 'Home Plan (TEST) was linked for another quoting organization — an admin links it again'],
        'A21 linked under another quoting organization: not used');
    $org2 = array_map(fn($r) => ['organization_id' => 2] + $r, $L());
    [$rx] = $res($cat(), $sale(), ['links' => $org2, 'setting' => ['on' => true, 'id' => 2, 'source' => 'setting', 'error' => '']]);
    $c->eq($rx['status'], 'priced', 'A21 … the control: the same links, asked for organization 2 — the quoting organization is the caller\'s');
    [$rx] = $res($cat(), $sale(), ['links' => $L([1 => qfu_ended($LK[1], 'unlinked')])]);
    $c->eq([$rx['status'], $rx['reason'], $rx['unlinked'] ?? null], ['unpriced', $NOT_LINKED('Home Plan (TEST)', 'Plans'), true],
        'A21 ended only (unlinked): not linked — an ended record is never used');
    [$rx] = $res($cat(), $sale(), ['links' => $L([1 => qfu_ended($LK[1]), 5 => qfu_link(5, 'plan', 1, $S['p1'], 12, 6)])]);
    $c->eq([$rx['status'], $rx['link_ids'] ?? null], ['priced', [2, 3, 5]], 'A21 relinked: the record in force is used, the ended one is not');
    [$rx] = $res($cat(), $sale(), ['links' => $L([1 => qfu_ended($LK[1]), 5 => qfu_link(5, 'plan', 1, $S['p1'], 12, 6), 6 => qfu_ended(qfu_link(6, 'plan', 1, $S['p1'], 13, 6))])]);
    $c->eq([$rx['status'], $rx['link_ids'] ?? null], ['priced', [2, 3, 5]], 'A21 … whichever comes last in the store: the latest record is not the rule, the active one is');

    // A22 — a kyc_packages row is never priced by a plan link with its id (§R.4.2)
    $link9 = $L([5 => qfu_link(5, 'plan', 9, $S['pk9'], 12, 6)]);
    [$rx] = $res($cat(), $sale(['package_choice' => 9]), ['links' => $link9,
        'rows' => ['subscription_plans.json' => [9 => ['id' => 9, 'name' => 'Plan Nine (TEST)', 'is_active' => true, 'created_at' => $S['pk9']]]]]);
    $c->eq($rx['status'], 'priced', 'A22 the control: a subscription_plans row 9 with that link is priced through it');
    [$rx] = $res($cat(), $sale(['package_choice' => 9]), ['links' => $link9]);
    $c->eq([$rx['status'], $rx['reason'], $rx['offer']['id'] ?? null, $rx['unlinked'] ?? null],
        ['unpriced', 'App Package (TEST) is a retailer-app package, which is not linked to uCRM', 9, false],
        'A22 a kyc_packages row 9, with a plan link recorded for id 9 and its very stamp: never priced by it');

    // A23 — a Fiber row, linked, is still unpriced (§R.4.3)
    $l4 = $L([5 => qfu_link(5, 'plan', 4, $S['p4'], 12, 6)]);
    [$rx] = $res($cat(), $sale(['package_choice' => 4]), ['links' => $l4,
        'rows' => ['subscription_plans.json' => [4 => ['type' => 'starlink'] + $ROWS['subscription_plans.json'][4]]]]);
    $c->eq($rx['status'], 'priced', 'A23 the control: the same row and link, typed starlink, is priced');
    foreach (['fiber', 'FIBER'] as $t) {
        [$rx] = $res($cat(), $sale(['package_choice' => 4]), ['links' => $l4,
            'rows' => ['subscription_plans.json' => [4 => ['type' => $t] + $ROWS['subscription_plans.json'][4]]]]);
        $c->eq([$rx['status'], $rx['reason']], ['unpriced', 'Fiber is not priced from uCRM on this installation'], "A23 a plan row typed {$t}, linked: unpriced with the Fiber reason");
    }
    [$rx] = $res($cat(), $sale(['device_id' => 6]), ['links' => $L([5 => qfu_link(5, 'kit', 6, $S['k6'], 56)])]);
    $c->eq([$rx['status'], $rx['reason']], ['unpriced', 'Fiber is not priced from uCRM on this installation'], 'A23 a Fiber kit row, linked: unpriced with the Fiber reason');

    // A24 — the installation only by its link: "Professional Installation" by name is never used
    [$rx] = $res($cat([71 => $P(71, 'Installation Service (TEST)', 250, ['unit' => 'Job'])]), $sale(), ['links' => $L([3 => qfu_link(3, 'install', 0, '', 71)])]);
    $c->eq([$rx['status'], end($rx['lines'])['productId'] ?? null, end($rx['lines'])['label'] ?? null, $rx['total']],
        ['priced', 71, 'Installation Service (TEST)', 1850.0],
        'A24 the installation linked to "Installation Service (TEST)" (71) is quoted by it, though 70 is named "Professional Installation"');
    [$rx] = $res($cat(), $sale(), ['links' => $L([3 => null])]);
    $c->eq([$rx['status'], $rx['reason']], ['unpriced', $NOT_LINKED('Installation', 'Hardware')],
        'A24 … and with no installation link, the product named "Professional Installation" is not used');

    // A25 — a kit re-priced in uCRM is quoted at the new price (uCRM is the price list)
    [$rx] = $res($cat([55 => ['price' => 1700]]), $sale());
    $c->eq([$rx['status'], $rx['lines'][1]['price'] ?? null, $rx['total']], ['priced', 1700.0, 2000.0], 'A25 a kit re-priced in uCRM: quoted at the new price');

    // A26/A31 — the page cache's shape version (§R.3.2): it covers excluded, plan_keys, plan_key_of and plan_rows
    $good = (new UcrmQuoteLines(new QfuFakeCrm($cat()['products'], $cat()['plans']), $ON, $pageLinks))->catalogue();
    $shape = json_decode($cachedRaw, true)['shape'] ?? null;
    $draw = function (array $cache) use ($c, $cat, $ON, $pageLinks): int {
        $d = $c->dir('page');
        file_put_contents($d . '/' . UcrmQuoteLines::CACHE_FILE, json_encode($cache + ['at' => time()]));
        $k = new QfuFakeCrm($cat()['products'], $cat()['plans']);
        UcrmQuoteLines::forPage($k, $ON, $d, $pageLinks)->catalogue();
        return count($k->calls);
    };
    $c->eq([$shape !== null, $draw($good + ['shape' => $shape])], [true, 0], 'A26 the control: a fresh cache of this release\'s shape is used, nothing read');
    $c->eq($draw($good), 2, 'A26 a cache without the shape version (5.18.102 before D14 wrote one) is a miss: read again');
    $c->eq($draw(['shape' => $shape] + array_diff_key($good, ['excluded' => 1])), 2, 'A26 … and one missing what was filtered out');
    $c->eq($draw(['shape' => $shape] + array_diff_key($good, ['plan_keys' => 1])), 2, 'A31 the shape covers plan_keys: a cache without them is a miss');
    $c->eq($draw(['shape' => $shape] + array_diff_key($good, ['plan_rows' => 1])), 2, 'A31 … and the service-plan row count');

    // A27 — the summary's list (§R.3.1 rule 6a): no other service plan, counted or not, keyed like the linked one
    $TWIN = "Home Plan (TEST)'s uCRM service plan is spelled like another service plan in uCRM (active or not), so the quotation's WhatsApp summary could show the other one's monthly price — an admin renames one in uCRM";
    [$rx] = $res($cat([], [$SP(6, 'Residential Standard (TEST)', 100), $SP(7, 'Residential Premium (TEST)', 150)]), $sale());
    $c->eq($rx['status'], 'priced', 'A27 the control: a second service plan spelled otherwise — resolves');
    foreach (['a counted' => [], 'an archived' => ['archived' => true], 'an inactive' => ['isActive' => false], 'another organization\'s' => ['organizationId' => 2]] as $what => $flag) {
        [$rx] = $res($cat([], [$SP(6, 'Residential Standard (TEST)', 100), $SP(7, 'Starlink Residential Standard (TEST)', 150) + $flag]), $sale());
        $c->eq([$rx['status'], $rx['reason']], ['unpriced', $TWIN], "A27 {$what} service plan sharing the linked plan's key: unpriced");
    }

    // A28 — rule 7 over the unfiltered list: a kit, and the installation, spelled like an archived or inactive plan
    [$rx] = $res($cat([], [$SP(6, 'Residential Standard (TEST)', 100), $SP(8, 'Standard Kit Plan (TEST)', 10) + ['archived' => true]]), $sale());
    $c->eq($rx['status'], 'priced', 'A28 the control: an archived service plan spelled otherwise — the kit and the installation resolve');
    foreach (['a counted' => [], 'an archived' => ['archived' => true], 'an inactive' => ['isActive' => false]] as $what => $flag) {
        [$rx] = $res($cat([], [$SP(6, 'Residential Standard (TEST)', 100), $SP(8, 'Standard Kit (TEST)', 10) + $flag]), $sale());
        $c->eq([$rx['status'], $rx['reason']], ['unpriced', 'Standard Kit (TEST) is spelled like a service plan in uCRM, so it cannot be quoted as hardware'],
            "A28 the kit's linked product spelled like {$what} service plan: unpriced");
        [$rx] = $res($cat([], [$SP(6, 'Residential Standard (TEST)', 100), $SP(9, 'Professional Installation', 10) + $flag]), $sale());
        $c->eq([$rx['status'], $rx['reason']], ['unpriced', 'Installation is spelled like a service plan in uCRM, so it cannot be quoted as hardware'],
            "A28 the installation's linked product spelled like {$what} service plan: unpriced");
    }

    // A29 — rule 6b: the summary prints planPrice, so it must be the 1-month price
    $withTop = fn($top) => ['id' => 6, 'name' => 'Residential Standard (TEST)', 'price' => $top, 'periods' => [['period' => 1, 'price' => 100, 'enabled' => true]]];
    [$rx] = $res($cat([], [$withTop(100)]), $sale());
    $c->eq($rx['status'], 'priced', 'A29 the control: a top-level price equal to the 1-month price — resolves');
    [$rx] = $res($cat([], [$withTop(90)]), $sale());
    $c->eq([$rx['status'], $rx['reason']], ['unpriced', "Home Plan (TEST)'s uCRM service plan carries a price that is not its 1-month price, so the quotation's WhatsApp summary could show the wrong monthly price — an admin fixes the service plan in uCRM"],
        'A29 a service plan whose planPrice is not its 1-month price: unpriced');

    // A30 — rule 6c: the summary reads service-plans?limit=200
    $fill = fn(int $n) => array_merge([$SP(6, 'Residential Standard (TEST)', 100)], array_map(fn($i) => $SP(100 + $i, 'Filler Plan ' . $i, 1), range(1, $n)));
    $p5 = ['links' => $L([5 => qfu_link(5, 'plan', 2, $S['p2'], 12, 6)])];
    [$rx] = $res($cat([], $fill(198)), $sale());
    [$ry] = $res($cat([], $fill(198)), $sale(['package_choice' => 2]), $p5);
    $c->eq([count($fill(198)), $rx['status'], $ry['status']], [199, 'priced', 'priced'], 'A30 the control: 199 service plans — both linked plans resolve');
    $ROWS200 = 'uCRM lists 200 service plans or more, more than the quotation\'s WhatsApp summary reads — an admin removes unused service plans in uCRM';
    [$rx] = $res($cat([], $fill(199)), $sale());
    [$ry] = $res($cat([], $fill(199)), $sale(['package_choice' => 2]), $p5);
    $c->eq([$rx['status'], $rx['reason'], $ry['status'], $ry['reason']], ['unpriced', $ROWS200, 'unpriced', $ROWS200],
        'A30 200 service plans: every plan unpriced');

    // A32 — rule 8: the installation linked to an offered kit's product
    $r8 = ['links' => $L([3 => qfu_link(3, 'install', 0, '', 55)])];
    $kit3 = $ROWS['kyc_devices.json'][3];
    foreach ([
        'an inactive kit row'        => ['is_active' => false] + $kit3,
        'a Fiber kit row'            => ['type' => 'FIBER'] + $kit3,
        'a kit row of another stamp' => ['created_at' => '2026-10-20 08:00:00'] + $kit3,
    ] as $what => $row3) {
        [, , $u8] = $res($cat(), $sale(), $r8 + ['rows' => ['kyc_devices.json' => [3 => $row3]]]);
        $i8 = $u8->installLine();
        $c->eq([$i8['ok'], $i8['line']['productId'] ?? null], [true, 55], "A32 the control: the same link on {$what} leaves the installation priced");
    }
    [$rx, , $u8, $st8] = $res($cat(), $sale(), $r8);
    $RULE8 = 'Standard Kit (TEST) and the installation are linked to the same uCRM product — an admin links one of them again';
    $i8 = $u8->installLine();
    $k8 = $u8->kitLine($st8->findOne('kyc_devices.json', 'id', 3));
    $c->eq([$i8['ok'], $i8['reason'], $k8['ok'], $k8['reason'], $rx['status'], $rx['reason']], [false, $RULE8, false, $RULE8, 'unpriced', $RULE8],
        'A32 the installation linked to an offered kit\'s product (55): both unpriced, with the rule-8 reason');
    // A32 — 5.18.102 D14 review (D14-R8-INACTIVE-KIT): a sale holding a kit row deactivated with its link left in force —
    // never two lines with the installation's product (rule 8 at use, the kit side whatever the row's offered state)
    [$rq] = $res($cat(), $sale(), $r8 + ['rows' => ['kyc_devices.json' => [3 => ['is_active' => false] + $kit3]]]);
    $c->eq([$rq['status'], $rq['reason'], array_column($rq['lines'], 'productId')], ['unpriced', $RULE8, []],
        'A32 a sale holding an inactive kit row whose link names the installation\'s product: unpriced with the rule-8 reason, never two lines with product 55');
    [$rx] = $res($cat(), $sale(['device_id' => 4]), $r8);
    $c->eq([$rx['status'], $rx['reason']], ['unpriced', $RULE8], 'A32 … even in a sale without that kit: the installation itself is refused');

    // A33 — the entry rule (§R.4.2, E-1): in this process, then through KycService in a child
    $ENTRY = 'this sale did not come from the KYC form of this release (the retailer app, the mobile API, a form opened before the update, or an application saved before it), so it is not priced from uCRM';
    $noEntry = $sale();
    unset($noEntry['entry']);
    $ent = [];
    foreach (['app' => $sale(['entry' => 'app']), 'none' => $noEntry, 'WEB' => $sale(['entry' => 'WEB']), "''" => $sale(['entry' => ''])] as $what => $s) {
        [$rx, $crmX] = $res($cat(), $s);
        $ent[$what] = [$rx['status'], $rx['reason'], $rx['entry'] ?? null, count($crmX->calls)];
    }
    $c->eq($ent, ['app' => ['unpriced', $ENTRY, 'app', 0], 'none' => ['unpriced', $ENTRY, 'app', 0], 'WEB' => ['unpriced', $ENTRY, 'app', 0], "''" => ['unpriced', $ENTRY, 'app', 0]],
        'A33 a sale whose entry is not exactly web (app, none, WEB, empty) is unpriced with the entry reason, whatever is linked, and uCRM is not asked');
    [$rx] = $res($cat(), $sale(['package_choice' => 42, 'entry' => 'app']));
    $c->eq($rx['status'], 'no_plan', 'A33 … an id in neither table is still no_plan');

    $fresh = function (array $set = []) use ($c) {
        $c->ctl('kyc', '/__test/reset?scenario=uganda');
        $c->ctl('kyc', '/__test/set', ['catalogue' => true] + $set);
    };
    $quotes = fn() => $c->bodies('kyc', 'POST', '/clients/\d+/quotes');
    $last = function (array $r): array { $a = (array)($r['value']['apps'] ?? []); return $a === [] ? [] : (array)end($a); };
    $pk = ['packages' => [['id' => 1, 'name' => 'App Package (TEST)', 'amount' => 80, 'created_at' => '2026-10-01 09:20:00']]];
    $shift = ['connectivity_type' => 'Shifting Connection', 'sales_type' => 'Credit'];
    $fresh();
    $r = $c->submit($shift, $pk);
    $a = $last($r);
    $c->eq([!empty($r['value']['success']), array_column($quotes()[0]['items'] ?? [], 'productId'), $a['quote_sale_entry'] ?? null, $a['quote_link_ids'] ?? null],
        [true, [12, 55], 'web', [1, 2]],
        'A33 the control: a Shifting Connection on Credit with the marker 5.18.102 — plan id 1 also a kyc_packages id — is priced through the link and stored web', $r['out']);
    foreach (['no marker' => null, 'the marker 5.18.101' => '5.18.101', 'an empty marker' => '', 'a marker that is an array' => ['5.18.102']] as $what => $m) {
        $fresh();
        $r = $c->submit($shift + ['ucrm_quote_form' => $m], $pk);
        $a = $last($r);
        $c->eq([!empty($r['value']['success']), count($quotes()), $a['quote_refused'] ?? null, strpos((string)($a['quote_refused_text'] ?? ''), $ENTRY) !== false,
                $a['quote_sale_entry'] ?? null],
            [true, 0, 'P1', true, 'app'], "A33 {$what}: still registered, unpriced with the entry reason, no quote.add, stored app");
    }
    $fresh();
    $r = $c->submit($shift + ['ucrm_quote_form' => null]);
    $c->eq([!empty($r['value']['success']), count($quotes()), $last($r)['quote_refused'] ?? null], [true, 0, 'P1'],
        'A33 an id only in subscription_plans (the retailer app\'s mock ids), no marker: unpriced, not priced through the plan\'s link');
    foreach (['without' => null, 'with' => '5.18.102'] as $what => $m) {
        $fresh();
        $r = $c->submit(['package_choice' => '42', 'ucrm_quote_form' => $m] + $shift);
        $c->eq([$r['value']['success'] ?? null, $r['value']['message'] ?? null, count((array)($r['value']['apps'] ?? []))], [false, 'Please select a valid package/plan.', 0],
            "A33 an id in neither table, {$what} the marker: no_plan, refused as today");
    }
    $seed901 = ['clients' => [['id' => 901, 'organizationId' => 1, 'firstName' => 'Test', 'lastName' => 'Existing', 'contacts' => []]]];
    $fresh($seed901);
    $r = $c->submit(['customer_id' => '901'] + $shift);
    $c->eq([!empty($r['value']['success']), array_column($quotes()[0]['items'] ?? [], 'productId'), $last($r)['quote_sale_entry'] ?? null], [true, [12, 55], 'web'],
        'A33 the control: an existing customer\'s post carrying the marker (a hand-made web post) is priced through the links', $r['out']);
    $fresh($seed901);
    $r = $c->submit(['customer_id' => '901', 'ucrm_quote_form' => null] + $shift);
    $a = $last($r);
    $c->eq([!empty($r['value']['success']), count($quotes()), $a['quote_refused'] ?? null, strpos((string)($a['quote_refused_text'] ?? ''), $ENTRY) !== false, $a['quote_sale_entry'] ?? null],
        [true, 0, 'P1', true, 'app'], 'A33 a post with a customer_id and no marker, as the mobile API sends (handleExisting): unpriced, stored app');
    $fresh();
    $r = $c->submit($shift, ['links' => [qfu_link(1, 'plan', 1, QFU_PLAN_STAMP, 12, 6)]]);
    $a = $last($r);
    $c->eq([count($quotes()), $a['quote_refused'] ?? null, $a['quote_sale_entry'] ?? null], [0, 'P1', 'web'],
        'A33 quote_sale_entry is stored from resolve()\'s entry for an unpriced sale too: the marker, the kit unlinked — web');
    $back = $c->child('$o = [];
foreach (["web" => ["quote_sale_entry" => "web"], "absent" => [], "app" => ["quote_sale_entry" => "app"], "empty" => ["quote_sale_entry" => ""],
          "WEB" => ["quote_sale_entry" => "WEB"], "web " => ["quote_sale_entry" => "web "], "true" => ["quote_sale_entry" => true]] as $k => $a) {
    $o[$k] = KycService::saleFromApp($a + ["package_choice" => 1])["entry"] ?? null;
}
return $o;');
    $c->eq($back['value'], ['web' => 'web', 'absent' => 'app', 'app' => 'app', 'empty' => 'app', 'WEB' => 'app', 'web ' => 'app', 'true' => 'app'],
        'A33 saleFromApp reads it back: exactly "web" is web; absent, app, empty or any other value is app', $back['out']);
}];
