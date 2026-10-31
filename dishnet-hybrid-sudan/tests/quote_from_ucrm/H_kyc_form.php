<?php
declare(strict_types=1);
/**
 * H — the KYC form on Uganda (docs/71 §E.1, §G; tabs/sales/kyc_form.php): uCRM's prices on the rows, "Who installs?",
 * ucrm_total_seen, the banner, the page's read (short timeouts, reused 60 s, a failure never kept), Fiber; where the gate
 * is off, the page byte for byte as f2055baf drew it. Then the same pages in headless Chromium (h_kyc_form_browser.js).
 *
 * The page is included in a child PHP process (QfuCtx::child) with the stubs the panel gives it — h(), csrfField(),
 * $store, $config, $dataDir, $devices, $retailer, $myWallet — and $crm a CrmApiClient on the 'kyc' fake that records
 * withTimeouts(). f2055baf's tree comes from Git (git archive), never from the working tree. TEST ONLY: every name,
 * number and address is fictitious.
 *
 * 5.18.102 D14 (docs/71 §R.5, §R.4.7, §R.9.1 H): every row reaches uCRM through its LINK (ucrm_quote_links.json), and a
 * linked row that resolves is drawn with uCRM's product name — in its text, data-name / data-title, and so the cart, the
 * summary, step 5 and what is posted. The plugin rows are spelled unlike their linked products ("… (plugin)"), so a
 * row drawn with the plugin's name shows. The installation's product is linked too, and named unlike "Professional
 * Installation", whose unlinked twin (#32) sits in the catalogue: its label can only come from the link. The same link
 * store is seeded on every draw, the gate-off ones included (H11: ignored there).
 */

$QFU_SECTIONS['H'] = ['title' => 'The KYC form on Uganda: uCRM\'s prices, "Who installs?", the page\'s read; f2055baf\'s page where the gate is off; in a browser', 'run' => function (QfuCtx $c) {
    $port = $c->fake('kyc');
    if ($port === 0) { $c->t(false, 'H the fake uCRM started'); return; }

    // ── the fixture: the form agent's (docs/71 §L.1), plus a Fiber kit uCRM does sell and an apostrophe in a title ──
    // 5.18.102 D14: linked — the plugin rows spelled unlike their uCRM products ("… (plugin)"), the installation linked to
    // #31 "Installation by DishNet (TEST)" while an unlinked #32 is named "Professional Installation"
    $PRODUCTS = [
        qfu_product(11, 'Starlink Standard Kit', 2499000, ['unit' => 'piece']),
        qfu_product(12, 'Starlink Mini Kit', 1100000),
        qfu_product(21, 'Residential', 250000),
        qfu_product(22, 'Residential Lite', 180000.5),
        qfu_product(23, 'Business Priority', 900000),
        qfu_product(24, 'WiFi ONU', 40000),                       // a Fiber kit uCRM prices: still not priced on Uganda
        qfu_product(25, 'Cable <b>Pro</b> & "Co" (uCRM)', 10000), // HTML in uCRM's own name
        qfu_product(26, 'TEST Fiber Box', 50000),                 // linked to a kit row typed FIBER (F-5)
        qfu_product(27, 'TEST Dish Mount (uCRM)', 30000),         // linked to a kit row stored with no type (F-5)
        qfu_product(28, 'FIBER 50', 66000),
        qfu_product(31, 'Installation by DishNet (TEST)', 150000, ['unit' => 'job']),
        qfu_product(32, 'Professional Installation', 150000, ['unit' => 'job']),   // never linked: a name match would take it
        qfu_product(41, 'Old Archived Kit', 5, ['archived' => true]),
    ];
    $PLANS = [
        ['id' => 1, 'name' => 'Starlink Residential', 'periods' => [['period' => 1, 'price' => 250000, 'enabled' => true], ['period' => 3, 'price' => 700000, 'enabled' => true]]],
        qfu_plan(2, 'Residential Lite', 180000.5),
        qfu_plan(3, 'Business Priority', 950000),                 // its product says 900000: unpriced
        qfu_plan(4, 'FIBER 50', 66000),
    ];
    $ST = '2026-10-01 08:00:';
    $SUBPLANS = [
        ['id' => 1, 'name' => 'Starlink Residential (plugin)', 'type' => 'starlink', 'customer_price' => 99, 'is_active' => true, 'speed' => '100 Mbps', 'created_at' => $ST . '01'],
        ['id' => 2, 'name' => 'Residential Lite (plugin)', 'type' => 'starlink', 'customer_price' => 88, 'is_active' => true, 'created_at' => $ST . '02'],
        ['id' => 3, 'name' => 'Business Priority (plugin)', 'type' => 'starlink', 'customer_price' => 77, 'is_active' => true, 'created_at' => $ST . '03'],
        ['id' => 4, 'name' => 'FIBER:50', 'type' => 'fiber', 'customer_price' => 66, 'is_active' => true, 'created_at' => $ST . '04'],
        ['id' => 5, 'name' => 'LTE 10GB', 'type' => 'lte', 'customer_price' => 55, 'is_active' => true, 'created_at' => $ST . '05'],
        ['id' => 6, 'name' => 'Inactive Plan', 'type' => 'starlink', 'customer_price' => 44, 'is_active' => false, 'created_at' => $ST . '06'],
    ];
    $DEVICES = [
        ['id' => 101, 'title' => 'Starlink Mini Kit (plugin)', 'type' => 'starlink', 'price' => '500', 'is_active' => true, 'sku' => 'MINI', 'created_at' => $ST . '11'],
        ['id' => 102, 'title' => 'Starlink Standard Kit (plugin)', 'type' => 'starlink', 'price' => '600', 'is_active' => true, 'created_at' => $ST . '12'],
        ['id' => 103, 'title' => 'Router Mesh', 'type' => 'general', 'price' => '50', 'is_active' => true, 'created_at' => $ST . '13'],
        ['id' => 104, 'title' => 'WiFi ONU', 'type' => 'fiber', 'price' => '40', 'is_active' => true, 'created_at' => $ST . '14'],
        ['id' => 105, 'title' => 'Inactive Kit', 'type' => 'starlink', 'price' => '30', 'is_active' => false, 'created_at' => $ST . '15'],
        ['id' => 106, 'title' => 'Cable <b>Pro</b> & "Co"', 'type' => 'starlink', 'price' => '10', 'is_active' => true, 'created_at' => $ST . '16'],
        ['id' => 107, 'title' => "TEST Roof's Mount", 'type' => 'starlink', 'price' => '20', 'is_active' => true, 'created_at' => $ST . '17'],
        ['id' => 108, 'title' => 'TEST Fiber Box (plugin)', 'type' => 'FIBER', 'price' => '45', 'is_active' => true, 'created_at' => $ST . '18'],
        ['id' => 109, 'title' => 'TEST Dish Mount (plugin)', 'price' => '25', 'is_active' => 1, 'created_at' => $ST . '19'],
    ];
    // 5.18.102 D14 (docs/71 §R.1.1): the links. Router Mesh (103), the Roof's Mount (107) and LTE 10GB (plan 5) are not
    // linked; plan 3 is linked but does not resolve (its product and service plan prices differ).
    $L = function (int $id, string $item, int $row, int $pid, ?int $sp = null) use ($ST, $SUBPLANS, $DEVICES): array {
        $stamp = '';
        foreach ($item === 'plan' ? $SUBPLANS : $DEVICES as $r) if ((int)$r['id'] === $row) $stamp = (string)$r['created_at'];
        return qfu_link($id, $item, $row, $stamp, $pid, $sp);
    };
    $LINKS = [$L(1, 'plan', 1, 21, 1), $L(2, 'plan', 2, 22, 2), $L(3, 'plan', 3, 23, 3), $L(4, 'plan', 4, 28, 4),
              $L(5, 'kit', 101, 12), $L(6, 'kit', 102, 11), $L(7, 'kit', 104, 24), $L(8, 'kit', 106, 25), $L(9, 'install', 0, 31),
              $L(10, 'kit', 108, 26), $L(11, 'kit', 109, 27)];
    $NOINST = array_values(array_filter($LINKS, fn($r) => $r['item'] !== 'install'));
    $fresh = function (array $set = []) use ($c, $PRODUCTS, $PLANS) {
        $c->ctl('kyc', '/__test/reset?scenario=uganda');
        $c->ctl('kyc', '/__test/set', $set + ['products' => $PRODUCTS, 'service_plans' => $PLANS]);
    };

    // The page, drawn in a child. 'tree' the tree to draw from (default: a copy of the judged tree's lib, profiles, tabs);
    // 'dir' a data folder to reuse (a second draw); 'wallet'. Returns html, requests (the fake's log for this draw),
    // timeouts (each withTimeouts() call), cache (the page cache file exists), errlog.
    $newTree = $c->tree(['tabs']);
    $draw = function (array $o = []) use ($c, $port, $SUBPLANS, $DEVICES, $newTree, $LINKS): array {
        $c->ctl('kyc', '/__test/set', ['log' => []]);
        $args = ['url' => "http://127.0.0.1:{$port}/api/v2.1", 'wallet' => $o['wallet'] ?? 10000000];
        $body = str_replace('@@ARGS@@', var_export(json_encode($args), true), <<<'PHP'
$A = json_decode(@@ARGS@@, true);
ini_set('display_errors', '0');
require_once "$root/lib/currency.php";
if (!function_exists('h')) { function h($s): string { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('csrfField')) { function csrfField(): string { return '<input type="hidden" name="_csrf" value="TEST-CSRF">'; } }
if (!class_exists('QfuHCrm', false)) {
    // the plugin's client, recording each withTimeouts() — the page's read must be the short one
    class QfuHCrm extends CrmApiClient {
        public static $t = [];
        public function withTimeouts(int $a, int $b): CrmApiClient { self::$t[] = [$a, $b]; return parent::withTimeouts($a, $b); }
    }
}
$crm      = new QfuHCrm($A['url'], 'FAKE-TEST-KEY', 'X-Auth-App-Key');
$store    = $s;
$config   = $s->load('kyc_config.json') ?? [];
$dataDir  = $dir;
$devices  = $s->load('kyc_devices.json') ?? [];
$retailer = ['api_token' => 'TEST-TOKEN', 'name' => 'Test Agent'];
$myWallet = ['balance' => (float)$A['wallet']];
$_SESSION = [];
ob_start();
include "$root/tabs/sales/kyc_form.php";
$html = (string)ob_get_clean();
file_put_contents("$dir/page.html", $html);
return ['timeouts' => QfuHCrm::$t, 'cache' => is_file("$dir/ucrm_quote_catalogue_cache.json")];
PHP);
        $tenant = $o['tenant'] ?? 'uganda';
        // 5.18.102 D14: the link store, seeded on every draw ('links' overrides it) — gate-off draws included
        $r = $c->child($body, ['tree' => $o['tree'] ?? $newTree, 'tenant' => $tenant, 'plans' => $SUBPLANS, 'devices' => $DEVICES,
                               'links' => $o['links'] ?? $LINKS,
                               'cfg' => ($o['cfg'] ?? []) + ['sales_persons' => ['Referral A'], 'fiber_install_fee' => 100]]
                              + (isset($o['dir']) ? ['dir' => $o['dir']] : []));
        return ['html' => (string)@file_get_contents($r['dir'] . '/page.html'), 'requests' => $c->requests('kyc'),
                'timeouts' => $r['value']['timeouts'] ?? null, 'cache' => $r['value']['cache'] ?? null,
                'errlog' => $r['errlog'], 'dir' => $r['dir'], 'out' => $r['out'], 'rc' => $r['rc']];
    };
    // One row of the PHP-drawn lists, for data-id $id: from its opening tag to the next row of its list, or the list's end
    // (hardware: #wHardwareList to the cart; plans: #wPackageList to #wNoPlanMsg). '' when the row is not drawn.
    $row = function (string $html, string $class, $id): string {
        [$from, $to] = $class === 'hw-row' ? ['id="wHardwareList"', '<!-- Cart -->'] : ['id="wPackageList"', 'id="wNoPlanMsg"'];
        $a = strpos($html, $from);
        $b = $a === false ? false : strpos($html, $to, $a);
        if ($a === false || $b === false) return '';
        $list = substr($html, $a, $b - $a);
        $re = '#<div class="' . $class . '[^"]*"\s+data-id="' . preg_quote((string)$id, '#') . '".*?(?=<div class="' . $class . '[ "]|$)#s';
        return preg_match($re, $list, $m) ? $m[0] : '';
    };
    $attr = function (string $rowHtml, string $name): ?string {
        return preg_match('#\b' . preg_quote($name, '#') . '="([^"]*)"#', $rowHtml, $m) ? $m[1] : null;
    };
    $warnings = fn(string $log): array => array_values(array_filter(explode("\n", $log), fn($l) => preg_match('/PHP (Warning|Notice|Fatal|Parse)/', $l)));
    $ends = fn(string $html): bool => strpos($html, 'function kycFormSubmit') !== false && strpos($html, 'id="wPackageList"') !== false;

    // ── H0 — the control: Uganda, uCRM readable ─────────────────────────────────────────────────────────────────────
    $fresh();
    $d0 = $draw();
    $h = $d0['html'];
    $c->t($ends($h) && $d0['rc'] === 0, 'H0 the control: the Uganda form draws to its end with the catalogue read', substr($d0['out'] . $d0['errlog'], -800));
    $planPrices = []; foreach ([1, 2, 3, 4, 5] as $id) $planPrices[$id] = $attr($row($h, 'pkg-opt', $id), 'data-price');
    $kitPrices = [];  foreach ([101, 102, 103, 104, 106, 107, 108, 109] as $id) $kitPrices[$id] = $attr($row($h, 'hw-row', $id), 'data-price');
    $c->eq([$planPrices, $kitPrices],
        [[1 => '250000', 2 => '180000.5', 3 => '0', 4 => '0', 5 => '0'],
         [101 => '1100000', 102 => '2499000', 103 => '0', 104 => '0', 106 => '10000', 107 => '0', 108 => '0', 109 => '30000']],
        'H0 data-price is uCRM\'s price, through each row\'s link, on every plan and hardware row that resolves (plan 1, 2; Mini, Standard Kit, the Cable, the Dish Mount), 0 on each row uCRM does not price');
    $c->t($row($h, 'pkg-opt', 6) === '' && $row($h, 'hw-row', 105) === '', 'H0 … inactive rows are not drawn');
    $c->t(strpos($h, 'UGX 250,000.00') !== false || strpos($h, 'UGX250,000.00') !== false, 'H0 … and the plan row shows uCRM\'s 250,000.00');

    // H1 — never the plugin's prices
    $c->t(!preg_match('/data-price="(99|88|77|500|600|50|40|10|20)"/', $h), 'H1 no row carries the plugin\'s customer_price or the hardware row\'s price (99/88/77, 500/600/50/40/10/20)');

    // H2 — an unpriced row reads "not priced in uCRM", with its reason; a priced one does not
    $unpricedOk = true; $why = [];
    foreach ([[1, 'pkg-opt'], [2, 'pkg-opt'], [3, 'pkg-opt'], [4, 'pkg-opt'], [5, 'pkg-opt'], [101, 'hw-row'], [102, 'hw-row'], [103, 'hw-row'], [104, 'hw-row'], [106, 'hw-row'], [107, 'hw-row']] as [$id, $cl]) {
        $rh = $row($h, $cl, $id);
        $zero = $attr($rh, 'data-price') === '0';
        $says = substr_count($rh, '>not priced in uCRM<') === 1 && strpos($rh, 'class="q102-reason"') !== false;
        if ($zero !== $says || ($zero && strpos($rh, 'class="q102-unpriced"') === false)) { $unpricedOk = false; $why[] = "{$cl} {$id}"; }
    }
    $c->t($unpricedOk, 'H2 every row at data-price 0 — and only those — reads "not priced in uCRM" with a reason', implode(', ', $why));
    // 5.18.102 D14 (rewritten on purpose, docs/71 §R.4.1): an unlinked row's reason is the link's, not the old name match's
    $c->t(strpos($row($h, 'hw-row', 103), 'Router Mesh is not linked to a uCRM product yet — an admin links it on the Hardware screen') !== false,
        'H2 … Router Mesh (not linked): "Router Mesh is not linked to a uCRM product yet — an admin links it on the Hardware screen"');

    // H3 — the reason is escaped where it is drawn (a title with an apostrophe: the reason carries it).
    // 5.18.102 D14 (rewritten on purpose, docs/71 §R.4.1): the row is not linked, so the reason is the link's text.
    $r107 = $row($h, 'hw-row', 107);
    $c->eq([substr_count($r107, 'TEST Roof&#039;s Mount is not linked to a uCRM product yet'), substr_count($r107, "TEST Roof's Mount is not linked")], [2, 0],
        'H3 a reason carrying an apostrophe is escaped where it is drawn — the title attribute and the visible note');

    // H4 — Fiber: unpriced although uCRM sells "WiFi ONU", and the plan too; with the Fiber reason
    $c->t(strpos($row($h, 'hw-row', 104), 'Fiber is not priced from uCRM on this installation') !== false
        && strpos($row($h, 'pkg-opt', 4), 'Fiber is not priced from uCRM on this installation') !== false,
        'H4 Fiber rows are not priced on Uganda — the WiFi ONU uCRM prices at 40,000 included — with the Fiber reason');

    // H5 — "Who installs?": present, disabled, never required, nothing preselected, in a display:none container
    preg_match_all('#<input[^>]*name="install_by"[^>]*>#', $h, $ins);
    $insOk = count($ins[0]) === 2;
    foreach ($ins[0] as $tag) $insOk = $insOk && preg_match('/\sdisabled[\s>]/', $tag) && !preg_match('/\b(required|checked)\b/', $tag);
    $c->t($insOk, 'H5 "Who installs?": two install_by radios (dishnet, customer), both disabled, neither required nor checked', implode(' | ', $ins[0]));
    $box = preg_match('#<div id="q102Install" style="display:none;[^"]*">(.*?)<div id="q102InstallErr"#s', $h, $bm) ? $bm[1] : '';
    $c->t(substr_count($box, 'name="install_by"') === 2 && strpos($box, 'value="dishnet"') !== false && strpos($box, 'value="customer"') !== false,
        'H5 … both inside #q102Install, drawn display:none (the page shows it for Starlink + New Connection only)');
    // 5.18.102 D14 (§R.2.4): the label comes from the installation's link — #31 — never from the name "Professional Installation"
    $c->t(strpos($box, 'Installation by DishNet (TEST) · UGX') !== false && strpos($box, '150,000.00') !== false && strpos($box, 'Installation not priced in uCRM') === false
        && strpos($box, 'Professional Installation') === false,
        'H5 … the DishNet option names the linked installation product, "Installation by DishNet (TEST)", and its uCRM price — never "Professional Installation"', $box);

    // H6 — the posted total's field; no Fiber fee; no banner when uCRM was read
    // (the old Fiber branches still read it behind typeof — it is the declaration that must be absent)
    $feeDecl = '/\\bvar\\s+_kycFiberInstallFee\\s*=/';
    $c->eq([substr_count($h, '<input type="hidden" name="ucrm_total_seen"     id="q102TotalSeen" value="">'), preg_match_all($feeDecl, $h),
            strpos($h, 'id="q102Banner"') !== false],
        [1, 0, false], 'H6 the hidden ucrm_total_seen (#q102TotalSeen, empty) is drawn once; var _kycFiberInstallFee is not declared; no banner');

    // H16 — 5.18.102 D14 (docs/71 §R.4.1): the Cash note's and the submit alert's tails — a link reason carries its own
    // remedy, so "to fix the product in uCRM" is gone from both (the browser check, 1.15 and 5.3, sees them drawn)
    $c->eq([substr_count($h, "'. Register as Credit, or ask an admin.'"), substr_count($h, 'Register as Credit (step 4), or ask an admin.'),
            strpos($h, 'fix the product in uCRM') !== false],
        [1, 1, false], 'H16 the Cash note ends "Register as Credit, or ask an admin." and the submit alert "Register as Credit (step 4), or ask an admin." — no "fix the product in uCRM"');

    // H7 — the title with HTML in it is drawn as text.
    // 5.18.102 D14 (rewritten on purpose, docs/71 §R.5): the row resolves through its link, so it is drawn with uCRM's
    // name — which carries HTML too — escaped in its data-title and its text; never raw.
    $r106 = $row($h, 'hw-row', 106);
    $c->t(strpos($h, 'Cable <b>Pro</b>') === false
        && strpos($r106, 'data-title="Cable &lt;b&gt;Pro&lt;/b&gt; &amp; &quot;Co&quot; (uCRM)"') !== false
        && strpos($r106, '>Cable &lt;b&gt;Pro&lt;/b&gt; &amp; &quot;Co&quot; (uCRM)</div>') !== false,
        'H7 a linked row whose uCRM name contains <b> and quotes is drawn with that name, escaped in data-title and its text (never "Cable <b>Pro</b>" raw)', $r106);

    // H8 — the read: one short-timeout client, products then service plans; reused within 60 s; no warnings
    $c->eq([$d0['timeouts'], $d0['requests'], $d0['cache']], [[[3, 5]], ['GET /products', 'GET /service-plans'], true],
        'H8 the first draw reads once, on a withTimeouts(3, 5) client: GET products, GET service-plans; the read is kept');
    $d0b = $draw(['dir' => $d0['dir']]);
    $c->eq([$d0b['requests'], $d0b['timeouts'], $d0b['html'] === $h], [[], [], true], 'H8 a second draw within 60 s reads nothing from uCRM and draws the same page');
    $c->eq([$warnings($d0['errlog']), $warnings($d0b['errlog'])], [[], []], 'H8 … neither draw logs a PHP warning or notice');

    // H9 — uCRM unreadable: the banner; the page draws to its end; nothing kept; the next draw tries again
    $fresh(['products_down' => true]);
    $d9 = $draw();
    $h9 = $d9['html'];
    $c->t($ends($h9) && strpos($h9, "<strong>uCRM's prices could not be read — Cash sales are not possible until it can; Credit registrations still go in.</strong>") !== false,
        'H9 products answer 503: the banner, and the form still draws to its end');
    $c->eq([$d9['requests'], $d9['cache'], $attr($row($h9, 'pkg-opt', 1), 'data-price'), $attr($row($h9, 'hw-row', 101), 'data-price')],
        [['GET /products'], false, '0', '0'], 'H9 … one request (service plans are not asked for after products failed), nothing kept, every row at 0');
    $c->t(substr_count($row($h9, 'hw-row', 101), 'uCRM&#039;s products could not be read') === 2, 'H9 … each row gives the reason, escaped');
    $d9b = $draw(['dir' => $d9['dir']]);
    $c->eq($d9b['requests'], ['GET /products'], 'H9 … a failed read is never reused: the next draw asks again');

    // H10 — the installation not priced: the DishNet option says so, with the reason.
    // 5.18.102 D14 (rewritten on purpose, docs/71 §R.2.4, §R.4.1): the installation is found by its link alone. Not linked
    // → its own reason, although a product named "Professional Installation" (#32) is listed and priced; linked to a
    // product uCRM no longer lists → that reason. The control is H5: linked and listed, it is priced.
    $fresh();
    $d10 = $draw(['links' => $NOINST]);
    $box10 = preg_match('#<div id="q102Install" style="display:none;[^"]*">(.*?)<div id="q102InstallErr"#s', $d10['html'], $bm) ? $bm[1] : '';
    $c->t(strpos($box10, 'Installation not priced in uCRM — no quotation, Cash not possible') !== false
        && strpos($box10, 'title="Installation is not linked to a uCRM product yet — an admin links it on the Hardware screen"') !== false,
        'H10 the installation not linked (#32 "Professional Installation" listed all the same): "Installation not priced in uCRM — no quotation, Cash not possible", with the link\'s reason', $box10);
    // 5.18.102 D14 review (D14-UI-3, docs/71 §R.6): the reason is drawn as text on the card — strip_tags drops every
    // attribute, so a reason only in title (which a phone never shows) does not count
    $vis10 = html_entity_decode(strip_tags($box10), ENT_QUOTES, 'UTF-8');
    $c->t(strpos($vis10, 'Installation is not linked to a uCRM product yet — an admin links it on the Hardware screen') !== false,
        'H10 … the installation\'s reason is drawn as text on the card, not only in its title, which a phone never shows (docs/71 §R.6)', $vis10);
    $fresh(['products' => array_values(array_filter($PRODUCTS, fn($p) => $p['id'] !== 31))]);
    $d10b = $draw();
    $box10b = preg_match('#<div id="q102Install" style="display:none;[^"]*">(.*?)<div id="q102InstallErr"#s', $d10b['html'], $bm) ? $bm[1] : '';
    $c->t(strpos($box10b, 'Installation not priced in uCRM — no quotation, Cash not possible') !== false
        && strpos($box10b, 'title="Installation is linked to a uCRM product that uCRM no longer lists — an admin links it again"') !== false,
        'H10 … linked to #31, which uCRM no longer lists: unpriced, "Installation is linked to a uCRM product that uCRM no longer lists — an admin links it again"', $box10b);

    // H13 — 5.18.102 D14 (docs/71 §R.5): a linked row that resolves is drawn with uCRM's product name — its text and its
    // data-name / data-title; a row that does not (unlinked, or linked and broken) with the plugin's own name and the reason.
    $nameOf = function (string $rh): string { return preg_match('#<div style="font-(?:size:13px;font-weight:700|weight:700;font-size:13px)[^"]*">([^<]*)</div>#', $rh, $m) ? $m[1] : ''; };
    $r1 = $row($h, 'pkg-opt', 1); $r101 = $row($h, 'hw-row', 101);
    $c->eq([[$attr($r1, 'data-name'), $nameOf($r1)], [$attr($r101, 'data-title'), $nameOf($r101)]],
        [['Residential', 'Residential'], ['Starlink Mini Kit', 'Starlink Mini Kit']],
        'H13 a linked plan and kit that resolve are drawn with uCRM\'s names — data-name / data-title and the row\'s text: "Residential", "Starlink Mini Kit"');
    // 5.18.102 D14 review (D14-UI-5), rewritten on purpose: the plugin's name now sits in data-row-name / data-row-title,
    // the keyword applyDefaults matches (never drawn); every other part of those rows still carries none of it
    $c->t(strpos((string)preg_replace('# data-row-(?:name|title)="[^"]*"#', '', $row($h, 'pkg-opt', 1) . $row($h, 'pkg-opt', 2) . $row($h, 'hw-row', 101) . $row($h, 'hw-row', 102)), '(plugin)') === false,
        'H13 … never the plugin\'s own "… (plugin)" names, anywhere in those rows but the keyword attribute');
    $c->eq([$attr($r1, 'data-row-name'), $attr($r101, 'data-row-title')], ['Starlink Residential (plugin)', 'Starlink Mini Kit (plugin)'],
        'H13 … and that keyword attribute is the row\'s own name: data-row-name / data-row-title (5.18.102 D14 review, D14-UI-5)');
    $r3 = $row($h, 'pkg-opt', 3); $r103 = $row($h, 'hw-row', 103);
    $c->eq([[$attr($r3, 'data-name'), $nameOf($r3)], [$attr($r103, 'data-title'), $nameOf($r103)]],
        [['Business Priority (plugin)', 'Business Priority (plugin)'], ['Router Mesh', 'Router Mesh']],
        'H13 the control: a linked plan that does not resolve, and an unlinked kit, are drawn with the plugin\'s names');
    $c->t(strpos($r3, 'Business Priority (plugin)&#039;s price in Products (900,000) differs from its service plan (950,000)') !== false,
        'H13 … with their reasons (plan 3: its product and service plan prices differ)', $r3);
    $badName = []; $nRows = 0;
    foreach ([[$SUBPLANS, 'pkg-opt', 'data-name', 'name'], [$DEVICES, 'hw-row', 'data-title', 'title']] as [$rows, $cl, $an, $nk]) {
        foreach ($rows as $pr) {
            $rh = $row($h, $cl, $pr['id']);
            if ($rh === '') continue;   // not drawn (inactive)
            $nRows++;
            $drawn = html_entity_decode((string)$attr($rh, $an), ENT_QUOTES, 'UTF-8');
            $priced = $attr($rh, 'data-price') !== '0';
            if ($priced ? $drawn === $pr[$nk] : $drawn !== $pr[$nk]) $badName[] = "{$cl} {$pr['id']}";
        }
    }
    $c->eq([$badName, $nRows], [[], 13], 'H13 across all 13 rows drawn (5 plans, 8 hardware rows): each priced row is drawn with a name that is not the plugin\'s (uCRM\'s), each unpriced one with the plugin\'s own');

    // H14 — 5.18.102 D14 (F-5): the kit loop chooses the kit's own cell or the Fiber cell by UcrmQuoteLinks::offeredKit. A row
    // typed "FIBER" gets the Fiber cell itself (no label, no link), although it is linked; one stored with is_active 1 and
    // no type is drawn as a kit, priced through its link. The control: a Starlink kit's cell carries its label and link.
    $q102 = preg_match('/^var _q102 = (.*);$/m', $h, $qm) ? json_decode($qm[1], true) : null;
    $FIB = 'Fiber is not priced from uCRM on this installation';
    $c->eq([$q102['kits']['101']['l'] ?? null, $q102['kits']['101']['k'] ?? null], ['Starlink Mini Kit', 5],
        'H14 the control: the Mini Kit\'s cell carries uCRM\'s label and its link record (5)');
    $c->eq([$q102['kits']['108'] ?? 'absent', $q102['kits']['104'] ?? 'absent'], [['p' => null, 'r' => $FIB], ['p' => null, 'r' => $FIB]],
        'H14 an active kit row typed "FIBER" (108), linked to #26, gets the Fiber cell itself — exactly {p: null, r: the Fiber reason} — as does the fiber one (104)');
    $c->t(strpos($row($h, 'hw-row', 108), $FIB) !== false && $attr($row($h, 'hw-row', 108), 'data-price') === '0' && $attr($row($h, 'hw-row', 108), 'data-link') === null,
        'H14 … drawn at 0 with the Fiber reason and no data-link');
    $c->eq([$q102['kits']['109']['p'] ?? null, $q102['kits']['109']['l'] ?? null, $q102['kits']['109']['k'] ?? null, $attr($row($h, 'hw-row', 109), 'data-type')],
        [30000, 'TEST Dish Mount (uCRM)', 11, 'general'],
        'H14 a row stored with is_active 1 and no type is offered as a kit: priced through its link (#27, 30,000), drawn as "general"');

    // H15 — 5.18.102 D14 (docs/71 §R.4.7, §R.4.2): data-link on each row whose link resolves, and the two posted fields —
    // ucrm_links_seen, and the web form's own marker ucrm_quote_form = 5.18.102. Drawn only with the gate on (H11 checks
    // they are absent where it is off).
    $c->eq([$attr($row($h, 'pkg-opt', 1), 'data-link'), $attr($row($h, 'pkg-opt', 2), 'data-link'), $attr($row($h, 'hw-row', 101), 'data-link'),
            $attr($row($h, 'hw-row', 102), 'data-link'), $attr($row($h, 'hw-row', 106), 'data-link'), $attr($row($h, 'hw-row', 109), 'data-link')],
        ['1', '2', '5', '6', '8', '11'], 'H15 each row whose link resolves carries its link record id as data-link');
    $c->eq([$attr($row($h, 'pkg-opt', 3), 'data-link'), $attr($row($h, 'hw-row', 103), 'data-link'), $attr($row($h, 'hw-row', 107), 'data-link')],
        [null, null, null], 'H15 … and no other row does: plan 3 (linked, not resolving), Router Mesh and the Roof\'s Mount (not linked)');
    $c->t(preg_match('#<label class="wiz-radio-card" onclick="q102InstallPick\(this\)" data-link="9">#', $h) === 1,
        'H15 … the "DishNet installs" option carries the installation\'s link record (9)');
    $c->eq([substr_count($h, '<input type="hidden" name="ucrm_links_seen"     id="q102LinksSeen" value="">'),
            substr_count($h, '<input type="hidden" name="ucrm_quote_form" value="5.18.102">'), substr_count($h, 'ucrm_quote_form')],
        [1, 1, 1], 'H15 ucrm_links_seen (#q102LinksSeen, empty until a total) and the marker ucrm_quote_form = 5.18.102 are each drawn once');

    // H11 — the gate off: f2055baf's page, byte for byte; no read; nothing kept; no warning
    $old = $c->dir('f2055baf');
    $top = trim((string)shell_exec('git -C ' . escapeshellarg(__DIR__) . ' rev-parse --show-toplevel 2>/dev/null'));
    exec('git -C ' . escapeshellarg($top) . ' archive f2055baf dishnet-hybrid-sudan/lib dishnet-hybrid-sudan/profiles dishnet-hybrid-sudan/tabs'
        . ' | tar -x -C ' . escapeshellarg($old) . ' 2>&1', $gout, $grc);
    $oldTree = $old . '/dishnet-hybrid-sudan';
    if ($top === '' || $grc !== 0 || !is_file($oldTree . '/tabs/sales/kyc_form.php')) {
        $c->t(false, 'H11 Git supplies f2055baf\'s tree — without it the gate-off page cannot be compared: a failure, never a skip', implode("\n", $gout));
    } else {
        $c->t(strpos((string)file_get_contents($oldTree . '/tabs/sales/kyc_form.php'), '_q102') === false, 'H11 f2055baf\'s kyc_form.php is the one before 5.18.102 (no _q102)');
        $fresh();
        foreach ([
            'South Sudan'                         => ['tenant' => 'south-sudan'],
            'South Sudan, the org key 1'          => ['tenant' => 'south-sudan', 'cfg' => ['ucrm_quote_organization_id' => '1']],
            'Uganda, ucrm_quote_organization_id 0' => ['cfg' => ['ucrm_quote_organization_id' => '0']],
            'Uganda, ucrm_quote_organization_id off' => ['cfg' => ['ucrm_quote_organization_id' => 'off']],
        ] as $what => $o) {
            $n = $draw($o);
            $p = $draw($o + ['tree' => $oldTree]);
            if ($what === 'South Sudan') $ssHtml = $n['html'];
            $c->t($n['html'] !== '' && $n['html'] === $p['html'], "H11 {$what}: the page is f2055baf's, byte for byte",
                'new ' . strlen($n['html']) . ' bytes, old ' . strlen($p['html']) . '; first difference at byte ' . strspn($n['html'] ^ $p['html'], "\0"));
            $c->eq([$n['requests'], $n['timeouts'], $n['cache'], $warnings($n['errlog'])], [[], [], false, []],
                "H11 {$what}: no uCRM request, no withTimeouts, no cache file, no warning");
            // 5.18.102 D14: with a link store present, none of D14's form fields or attributes (their presence on Uganda is H15)
            $c->eq([strpos($n['html'], 'data-link=') !== false, strpos($n['html'], 'ucrm_links_seen') !== false, strpos($n['html'], 'ucrm_quote_form') !== false],
                [false, false, false], "H11 {$what}: no data-link, no ucrm_links_seen, no ucrm_quote_form marker — the link store present is ignored");
        }
        $c->t(preg_match_all($feeDecl, $ssHtml ?? '') === 1 && strpos($ssHtml ?? '', 'var _kycFiberInstallFee = 100;') !== false,
            'H11 the control on H6: South Sudan\'s page declares var _kycFiberInstallFee = 100 (fiber_install_fee), as before');
        // the control on the comparison: with the gate on, the two pages differ
        $on  = $draw();
        $onp = $draw(['tree' => $oldTree]);
        $c->t($on['html'] !== '' && $onp['html'] !== '' && $on['html'] !== $onp['html'], 'H11 the control: with the gate on (Uganda), the page is not f2055baf\'s');
    }

    // ── H12 — in a browser: the pages drawn above, driven in headless Chromium ─────────────────────────────────────
    $script = __DIR__ . '/h_kyc_form_browser.js';
    $node = trim((string)shell_exec('command -v node 2>/dev/null'));
    $nodePath = trim((string)(getenv('NODE_PATH') ?: ''));
    foreach (['/opt/node22/lib/node_modules', '/usr/lib/node_modules', '/usr/local/lib/node_modules'] as $cand) {
        if (is_dir($cand . '/playwright')) { $nodePath = $nodePath === '' ? $cand : $nodePath . ':' . $cand; break; }
    }
    $haveIt = false;
    if ($node !== '') {
        exec('NODE_PATH=' . escapeshellarg($nodePath) . ' ' . escapeshellarg($node) . " -e \"require('playwright')\" 2>/dev/null", $po, $prc);
        $haveIt = $prc === 0;
    }
    if (!$haveIt) {
        echo "  note H limitation: node or Playwright is not available here (node: " . ($node === '' ? 'none' : $node)
            . "); the form's behaviour in a browser (h_kyc_form_browser.js) was not run — a stated limitation, not a pass\n";
        return;
    }
    $pages = $c->dir('h-pages');
    $fresh();
    file_put_contents("$pages/new_ug_on.html", $draw()['html']);
    $fresh();
    file_put_contents("$pages/new_ug_lowwallet.html", $draw(['wallet' => 100])['html']);
    $fresh(['products_down' => true]);
    file_put_contents("$pages/new_ug_unread.html", $draw()['html']);
    $fresh();   // 5.18.102 D14: the installation not linked (#32 "Professional Installation" listed all the same)
    file_put_contents("$pages/new_ug_noinst.html", $draw(['links' => $NOINST])['html']);
    // 5.18.102 D14 review (D14-UI-5): the linked plan 1 and kit 101 named in uCRM without the default's keywords
    // ("residential", "mini"), while the unlinked plan 2 and kit 102 still carry them in the plugin's names
    $neutral = array_map(function ($p) {
        if ($p['id'] === 21) return qfu_product(21, 'Standard Plan (TEST)', 250000);
        if ($p['id'] === 12) return qfu_product(12, 'Dish Kit (TEST)', 1100000);
        return $p;
    }, $PRODUCTS);
    $fresh(['products' => $neutral, 'service_plans' => array_merge([qfu_plan(1, 'Standard Plan (TEST)', 250000)], array_slice($PLANS, 1))]);
    file_put_contents("$pages/new_ug_neutral.html", $draw(['links' => array_values(array_filter($LINKS, fn($r) => !in_array($r['id'], [2, 6], true)))])['html']);
    $sock = @stream_socket_server('tcp://127.0.0.1:0', $en, $es);
    $bport = $sock ? (int)substr(strrchr((string)stream_socket_get_name($sock, false), ':'), 1) : 0;
    if ($sock) fclose($sock);
    $want = preg_match_all('/^\s*ok\(/m', (string)file_get_contents($script));
    $out = [];
    exec('cd ' . escapeshellarg($pages) . ' && QFU_H_PAGES=' . escapeshellarg($pages) . ' QFU_H_PORT=' . $bport . ' NODE_PATH=' . escapeshellarg($nodePath)
        . ' timeout 300 ' . escapeshellarg($node) . ' ' . escapeshellarg($script) . ' 2>&1', $out, $brc);
    $np = $nf = 0;
    foreach ($out as $l) {
        if (strpos($l, 'PASS ') === 0) { $np++; $c->t(true, 'H12 browser ' . substr($l, 5)); }
        elseif (strpos($l, 'FAIL ') === 0) { $nf++; $c->t(false, 'H12 browser ' . substr($l, 5)); }
    }
    $c->t($brc === 0 && $np + $nf === $want && $want === 71, "H12 the browser check ran all {$want} of its checks to the end (exit {$brc})",
        "passed {$np}, failed {$nf}, of {$want}\n" . implode("\n", array_slice($out, -15)));
}];
