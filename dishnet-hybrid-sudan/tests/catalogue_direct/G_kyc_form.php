<?php
declare(strict_types=1);
/**
 * Section G — 5.18.103 (root docs/72 §G.5, G-7, G-9, G-12): the KYC form (tabs/sales/kyc_form.php) with the uCRM catalogue
 * switched on — "direct mode". The page drawn in a child (g_lib.php, as 5.18.102's section H draws it): uCRM's plan pairs
 * as ucrm_plan radios, uCRM's hardware by product id with each item's token, kits before accessories, the installation
 * cell, the direct marker, and no package_choice, device_id, hw_cart_json or ucrm_links_seen anywhere; no Fiber card; the
 * not-offered note; uCRM's names escaped; the standard quotations, usable and disabled with their reason. uCRM down: the
 * last good list (Cash unavailable), or nothing to choose from. The switch off, the quoting gate off and South Sudan: the
 * page byte for byte as 1a0ea5f (5.18.102, from Git) draws it from the same data. Then the pages in headless Chromium
 * (g_kyc_form_browser.js) — the standard quotations, the one total, the tokens posted, the caps — and the two sales the
 * browser posted, submitted through KycService::process: priced and accepted, Credit and Cash, so the form and the server
 * agree on the tokens. Every name, number and price is fictitious.
 */
require_once __DIR__ . '/g_lib.php';

$CD_SECTIONS['G'] = ['title' => 'the KYC form with the uCRM catalogue', 'run' => function (QfuCtx $c) {
    if ($c->fake('kyc') === 0) { $c->t(false, 'G the fake uCRM started'); return; }

    // ── the fixture: _lib.php's catalogue, plus a product named with markup characters and one an admin keeps off ────
    $CABLE = 'Cable <b>"&\' (TEST)';
    $PRODUCTS = cd_products([90 => ['id' => 90, 'name' => $CABLE, 'price' => 75, 'unit' => 'Pc', 'taxable' => false],
                             58 => ['id' => 58, 'name' => 'Wall Bracket (TEST)', 'price' => 120, 'unit' => 'Pc', 'taxable' => false]]);
    $fresh = function (array $set = []) use ($c, $PRODUCTS) { cd_fake($c, $set + ['products' => $PRODUCTS]); };
    $STD = [cd_std('Mini + Residential (TEST)', CD_PLAN_RES, 12, [['p' => CD_MINI, 'q' => 1]], true),                         // id 10
            cd_std('Kit x2 + Business (TEST)', CD_PLAN_BIZ, 17, [['p' => CD_KIT, 'q' => 2], ['p' => CD_ACC, 'q' => 3]], false),  // id 11
            cd_std('Old Kit bundle (TEST)', CD_PLAN_RES, 12, [['p' => 81, 'q' => 1]], true),                                      // id 12: 81 is archived
            cd_std('Bad <i>Title</i> (TEST)', CD_PLAN_RES, 12, [['p' => CD_MINI, 'q' => 1]], false)];                             // id 13: not plain text
    $RECORDS = array_merge(cd_records(true, CD_INSTALL, [], $STD), [cd_rec(20, 'hidden', ['product_id' => 58, 'product_name' => 'Wall Bracket (TEST)'])]);
    $TOK = ['p6_12' => cd_token(12, 'Residential Standard (TEST)', 100, 'month'), 'p7_17' => cd_token(17, 'Business Plus (TEST)', 300, 'month'),
            'k90' => cd_token(90, $CABLE, 75, 'Pc'), 'k56' => cd_token(CD_MINI, 'Mini Kit (TEST)', 900, 'Pc'),
            'k55' => cd_token(CD_KIT, 'Standard Kit (TEST)', 1500, 'Pc'), 'k57' => cd_token(CD_ACC, 'Pipe Adapter (TEST)', 50, 'Pc'),
            'inst' => cd_token(CD_INSTALL, 'Professional Installation', 200, 'Job')];
    $LEGACY = ['package_choice', 'device_id', 'hw_cart_json', 'ucrm_links_seen'];
    $legacyAnywhere = function (string $html) use ($LEGACY): array {
        $hit = [];
        foreach ($LEGACY as $n) if (preg_match('/\bname\s*=\s*\\\\?["\']?' . $n . '\b/', $html)) $hit[] = $n;
        return $hit;
    };
    $ends = function (string $html): bool { return strpos($html, 'function kycFormSubmit') !== false && strpos($html, 'id="wPackageList"') !== false; };
    $q102Of = function (string $html): ?array { return preg_match('/^var _q102 = (.*);$/m', $html, $m) ? json_decode($m[1], true) : null; };
    $stdOf = function (string $html): ?array { return preg_match('/^var _q103Std = (.*);$/m', $html, $m) ? json_decode($m[1], true) : null; };
    $button = function (string $html, int $id): string {
        return preg_match('#<button type="button" class="q103-std" data-std="' . $id . '".*?</button>#s', $html, $m) ? $m[0] : '';
    };
    $tree = cd_g_tree($c);

    // ── G0 the positive control: the direct page draws to its end from one short read ────────────────────────────────
    $fresh();
    $d0 = cd_g_draw($c, ['tree' => $tree, 'records' => $RECORDS]);
    $h = $d0['html'];
    $c->t($ends($h) && $d0['rc'] === 0 && strpos($h, 'value="5.18.103-direct"') !== false,
        'G0 the control: the Uganda form, the switch on, draws to its end in direct mode', substr($d0['out'] . $d0['errlog'], -800));
    $c->eq([$d0['requests'], $d0['timeouts'], $d0['cache'], $d0['last_good'], cd_g_warnings($d0['errlog'])],
        [['GET /products', 'GET /service-plans'], [[3, 5]], true, true, []],
        'G0 one read on a withTimeouts(3, 5) client — products, service plans; the page cache and the last good copy kept; no PHP warning');

    // ── G1 the rows: uCRM's plan pairs, uCRM's hardware by id with tokens, the installation cell, no plugin row ───────
    preg_match_all('#<input type="radio" name="ucrm_plan" value="([^"]*)"#', $h, $pm);
    $c->eq([$pm[1], cd_g_ids($h, 'pkg-opt')], [['6:12', '7:17'], ['6:12', '7:17']],
        'G1 one ucrm_plan radio per offered plan, valued "<servicePlanId>:<productId>" — exactly 6:12 and 7:17, none for "Lonely Plan" (no product), none for the plugin\'s plan row');
    $c->eq(cd_g_ids($h, 'hw-row'), ['90', (string)CD_MINI, (string)CD_KIT, (string)CD_ACC],
        'G1 the hardware rows, data-id the uCRM product id: the kits by price (75, 900, 1,500), then the accessory (50) — never the plans\' products, the installation (70), the one kept off (58), one priced 0 (80), the archived one (81) or the plugin\'s kit row (3)');
    $rowsOk = []; $bad = [];
    foreach ([['hw-row', '90', $TOK['k90'], '75.00', 'kit'], ['hw-row', (string)CD_MINI, $TOK['k56'], '900.00', 'kit'],
              ['hw-row', (string)CD_KIT, $TOK['k55'], '1500.00', 'kit'], ['hw-row', (string)CD_ACC, $TOK['k57'], '50.00', 'accessory'],
              ['pkg-opt', '6:12', $TOK['p6_12'], '100.00', null], ['pkg-opt', '7:17', $TOK['p7_17'], '300.00', null]] as [$cl, $id, $tok, $price, $grp]) {
        $rh = cd_g_row($h, $cl, $id);
        $got = [cd_g_attr($rh, 'data-token'), cd_g_attr($rh, 'data-price'), $grp === null ? null : cd_g_attr($rh, 'data-group')];
        if ($got !== [$tok, $price, $grp]) $bad[] = "{$cl} {$id}: " . json_encode($got);
    }
    $c->eq($bad, [], 'G1 each row carries data-token = the item\'s token (cd_token: product id, uCRM label, cents, unit), uCRM\'s price, and its group');
    $kitsAt = strpos($h, '>Kits</div>'); $accAt = strpos($h, '>Accessories</div>');
    $c->t($kitsAt !== false && $accAt !== false && $kitsAt < strpos($h, 'data-id="90"') && strpos($h, 'data-id="' . CD_KIT . '"') < $accAt && $accAt < strpos($h, 'data-id="' . CD_ACC . '"'),
        'G1 … under "Kits" and then "Accessories" (the website\'s split)');
    $q = $q102Of($h);
    $c->eq([array_keys((array)($q['plans'] ?? [])), array_keys((array)($q['kits'] ?? [])), $q['ok'] ?? null],
        [['6:12', '7:17'], [90, CD_MINI, CD_KIT, CD_ACC], true], 'G1 the price cells q102Quote() totals are keyed by the same direct ids; uCRM read now');
    $cellTok = [$q['plans']['6:12']['t'] ?? null, $q['plans']['7:17']['t'] ?? null, $q['kits']['90']['t'] ?? null, $q['kits'][(string)CD_MINI]['t'] ?? null,
                $q['kits'][(string)CD_KIT]['t'] ?? null, $q['kits'][(string)CD_ACC]['t'] ?? null];
    $c->eq($cellTok, [$TOK['p6_12'], $TOK['p7_17'], $TOK['k90'], $TOK['k56'], $TOK['k55'], $TOK['k57']], 'G1 … each cell carries the same token as its row');
    $c->eq([$q['install']['p'] ?? null, $q['install']['l'] ?? null, $q['install']['t'] ?? null], [200, 'Professional Installation', $TOK['inst']],
        'G1 the installation cell: the installation product in force, at uCRM\'s 200, with its token');
    $box = preg_match('#<div id="q102Install" style="display:none;[^"]*">(.*?)<div id="q102InstallErr"#s', $h, $bm) ? $bm[1] : '';
    preg_match_all('#<input[^>]*name="install_by"[^>]*>#', $box, $ins);
    $c->t(strpos($box, '<div class="wrc-sub">Professional Installation · UGX 200.00</div>') !== false && count($ins[0]) === 2
        && strpos($box, 'data-link') === false && strpos($box, 'not priced in uCRM') === false,
        'G1 "Who installs?": the DishNet option names the installation product and its price; two install_by radios; no link record', $box);
    $c->t(strpos($h, 'Home Plan (TEST)') === false && strpos($h, 'data-link=') === false && strpos($h, 'data-row-') === false,
        'G1 none of the plugin\'s rows is drawn: not its plan "Home Plan (TEST)", no data-link, no data-row-name / data-row-title');

    // ── G1 the fields (G-7, G-9): the direct form's own, the marker; never a legacy one ──────────────────────────────
    $names = cd_g_names($h);
    $c->eq([$names['ucrm_cart_json'] ?? 0, $names['ucrm_total_seen'] ?? 0, $names['ucrm_items_seen'] ?? 0, $names['ucrm_quote_form'] ?? 0, $names['ucrm_plan'] ?? 0],
        [1, 1, 1, 1, 2], 'G1 ucrm_cart_json, ucrm_total_seen, ucrm_items_seen and ucrm_quote_form drawn once each; ucrm_plan on the two radios');
    $c->eq(substr_count($h, '<input type="hidden" name="ucrm_quote_form" value="5.18.103-direct">'), 1, 'G1 the marker: ucrm_quote_form = 5.18.103-direct');
    $c->eq([array_values(array_intersect($LEGACY, array_keys($names))), $legacyAnywhere($h)], [[], []],
        'G1 no input named package_choice, device_id, hw_cart_json or ucrm_links_seen — not in the markup, not in a script string');
    $c->t(strpos($h, 'name="customer_type" value="Fiber"') === false && strpos($h, 'name="customer_type" value="StarLink"') !== false,
        'G1 no Fiber card (Starlink and DishNet 4G only)');

    // ── G1 escaping, the not-offered note, the standard quotations ───────────────────────────────────────────────────
    $r90 = cd_g_row($h, 'hw-row', '90');
    $c->t(strpos($h, 'Cable <b>') === false && cd_g_attr($r90, 'data-title') === 'Cable &lt;b&gt;&quot;&amp;&#039; (TEST)'
        && strpos($r90, '>Cable &lt;b&gt;&quot;&amp;&#039; (TEST)</div>') !== false && ($q['kits']['90']['l'] ?? null) === $CABLE
        && strpos($h, '"l":' . json_encode($CABLE, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) !== false,
        'G1 uCRM\'s name with <b>"&\' is escaped in data-title, in the row\'s text and in the script\'s cell — never raw', $r90);
    $c->t(strpos($h, '1 uCRM service plan and 3 uCRM products are not offered on this form.') !== false
        && strpos($h, 'An admin sees them, and why, on the uCRM catalogue screen.') !== false && strpos($h, 'tab=ucrm_catalogue') === false,
        'G1 the not-offered note: how many (the plan with no product; 58 kept off, 80 priced 0, 81 archived), and that an admin sees them');
    $b10 = $button($h, 10); $b11 = $button($h, 11); $b12 = $button($h, 12);
    $c->t(strpos($b10, ' disabled') === false && strpos($b10, '>Mini + Residential (TEST)</span>') !== false && strpos($b10, 'UGX 1,200 · includes installation') !== false
        && strpos($b11, ' disabled') === false && strpos($b11, 'UGX 3,450</span>') !== false && strpos($b11, 'includes installation') === false,
        'G1 two usable standard quotations, each with its live total: 1,200 (100 + 900 + the installation 200, "includes installation"); 3,450 (300 + 1,500 × 2 + 50 × 3)', $b10 . $b11);
    $why12 = 'a hardware item in it (uCRM product #81) is not offered now — see the uCRM catalogue screen';
    $c->t(strpos($b12, ' disabled title="' . $why12 . '"') !== false && strpos($b12, 'Cannot be used now: ' . $why12) !== false
        && strpos($b12, '>Old Kit bundle (TEST)</span>') !== false,
        'G1 the third disabled, with its reason in its title and as text', $b12);
    $c->t($button($h, 13) === '' && strpos($h, 'Bad <i>') === false && strpos($h, 'Bad &lt;i&gt;') === false,
        'G1 a stored standard quotation whose title is not plain text is not drawn at all (UcrmCatalogue reads it as none)');
    $c->eq(array_keys((array)$stdOf($h)), [10, 11], 'G1 only the usable standard quotations reach the script (_q103Std): 10 and 11, never 12');
    $c->eq(($stdOf($h)['10'] ?? null), ['title' => 'Mini + Residential (TEST)', 'plan' => '6:12', 'hw' => [['p' => CD_MINI, 'q' => 1]], 'install' => true],
        'G1 … by ids and quantities only, never a price');
    $fresh();
    $dA = cd_g_draw($c, ['tree' => $tree, 'records' => $RECORDS, 'admin' => true]);
    $c->t(strpos($dA['html'], 'The <a href="?page=dashboard&amp;tab=ucrm_catalogue"') !== false, 'G1 control: for a viewer who may see the Plans screen, the note links the uCRM catalogue screen');
    // the installation product not set: a standard quotation that includes it cannot be used; one that does not, can
    $fresh();
    $dN = cd_g_draw($c, ['tree' => $tree, 'records' => cd_records(true, null, [], $STD)]);
    $boxN = preg_match('#<div id="q102Install" style="display:none;[^"]*">(.*?)<div id="q102InstallErr"#s', $dN['html'], $bm) ? $bm[1] : '';
    $unset = 'the installation product is not set — an admin sets it on the uCRM catalogue screen';
    $c->t(strpos($button($dN['html'], 10), ' disabled title="' . $unset . '"') !== false && strpos($button($dN['html'], 11), ' disabled') === false
        && array_keys((array)$stdOf($dN['html'])) === [11],
        'G1 the installation product not set: the standard quotation that includes it is disabled with that reason; the other stays usable');
    $c->t(strpos($boxN, 'Installation not priced in uCRM — no quotation, Cash not possible') !== false && strpos($boxN, $unset) !== false,
        'G1 … and the DishNet option says the installation is not priced, with the reason', $boxN);

    // ── G2 uCRM down: the last good list (stale), or — never read — nothing to choose from ───────────────────────────
    $fresh();
    $dS0 = cd_g_draw($c, ['tree' => $tree, 'records' => $RECORDS]);
    $lg0 = (string)@file_get_contents($dS0['dir'] . '/ucrm_quote_catalogue_last_good.json');
    @unlink($dS0['dir'] . '/ucrm_quote_catalogue_cache.json');            // the 60-second page cache gone: the page must read
    $fresh(['products_down' => true]);
    $dS = cd_g_draw($c, ['tree' => $tree, 'records' => $RECORDS, 'dir' => $dS0['dir']]);
    $hS = $dS['html'];
    $qS = $q102Of($hS);
    $c->t($ends($hS) && strpos($hS, '<strong>uCRM could not be read — the list may be out of date; Cash is unavailable; Credit registrations still go in.</strong>') !== false
        && strpos($hS, 'id="q102Banner"') === false, 'G2 uCRM down with a last good copy: the stale banner, the form drawn to its end', substr($dS['out'] . $dS['errlog'], -600));
    $c->eq([cd_g_ids($hS, 'hw-row'), cd_g_ids($hS, 'pkg-opt'), cd_g_attr(cd_g_row($hS, 'hw-row', (string)CD_KIT), 'data-token')],
        [cd_g_ids($h, 'hw-row'), cd_g_ids($h, 'pkg-opt'), $TOK['k55']], 'G2 … the last good list\'s rows drawn, with their tokens');
    $c->eq([$qS['ok'] ?? null, $qS['reason'] ?? null], [false, 'uCRM could not be read — the list may be out of date'],
        'G2 … but not as uCRM\'s now: the cells read not ok, so there is no total and Cash is disabled (in the browser, 8.x)');
    $c->eq([$dS['requests'], $dS['cache'], (string)@file_get_contents($dS['dir'] . '/ucrm_quote_catalogue_last_good.json') === $lg0 && $lg0 !== ''],
        [['GET /products'], false, true], 'G2 … one failed read, nothing cached, the last good copy left as it was');
    $fresh(['products_down' => true]);
    $dV = cd_g_draw($c, ['tree' => $tree, 'records' => $RECORDS]);
    $hV = $dV['html'];
    preg_match_all('#<button type="button" class="q103-std" data-std="\d+"( disabled)?#', $hV, $bv);
    $c->t($ends($hV) && strpos($hV, '<strong>uCRM could not be read, and no earlier list of its plans and hardware is kept — there is nothing to choose from, so no registration can be made here until it can.</strong>') !== false,
        'G2 uCRM never read: the banner says there is nothing to choose from', substr($dV['out'] . $dV['errlog'], -600));
    $c->eq([cd_g_ids($hV, 'hw-row'), cd_g_ids($hV, 'pkg-opt'), strpos($hV, 'No hardware is offered from uCRM.') !== false, $bv[1], $stdOf($hV), $dV['last_good']],
        [[], [], false, [' disabled', ' disabled', ' disabled'], [], false],
        'G2 … no plan and no hardware row, every standard quotation disabled, none in the script, no last good copy written');
    $c->eq([$legacyAnywhere($hV), substr_count($hV, 'value="5.18.103-direct"')], [[], 1], 'G2 … still the direct form: its marker, and no legacy field');

    // ── G3 the switch off, the quoting gate off, South Sudan: 1a0ea5f's page, byte for byte, from the same data ───────
    $top = trim((string)shell_exec('git -C ' . escapeshellarg(dirname(__DIR__, 2)) . ' rev-parse --show-toplevel 2>/dev/null'));
    $old = $c->dir('tree-1a0ea5f');
    $go = []; $grc = 1;
    if ($top !== '') {
        exec('git -C ' . escapeshellarg($top) . ' archive -o ' . escapeshellarg($old . '.tar') . ' ' . escapeshellarg('1a0ea5f:dishnet-hybrid-sudan')
            . ' lib profiles tabs 2>&1 && tar -xf ' . escapeshellarg($old . '.tar') . ' -C ' . escapeshellarg($old) . ' 2>&1', $go, $grc);
    }
    @mkdir($old . '/data', 0777, true);
    $oldForm = (string)@file_get_contents($old . '/tabs/sales/kyc_form.php');
    $c->t($grc === 0 && strpos($oldForm, '_q102') !== false && strpos($oldForm, '_q103') === false && !is_file($old . '/lib/UcrmCatalogue.php'),
        'G3 Git supplies 1a0ea5f\'s lib, profiles and tabs — 5.18.102\'s form (_q102, no _q103) — a failure, never a skip', implode("\n", $go));
    if ($grc === 0) {
        $fresh();
        foreach ([
            'Uganda, the switch off'                        => ['records' => cd_records(false, CD_INSTALL, [CD_ACC], $STD)],
            'Uganda, no switch record'                      => ['records' => []],
            'Uganda, the switch on, the gate off (off)'     => ['records' => $RECORDS, 'cfg' => ['ucrm_quote_organization_id' => 'off']],
            'Uganda, the switch on, the gate off (0)'       => ['records' => $RECORDS, 'cfg' => ['ucrm_quote_organization_id' => '0']],
            'South Sudan'                                   => ['tenant' => 'south-sudan'],
            'South Sudan, the switch-on records stored'     => ['tenant' => 'south-sudan', 'records' => $RECORDS],
        ] as $what => $o) {
            $n = cd_g_draw($c, $o + ['tree' => $tree]);
            $p = cd_g_draw($c, $o + ['tree' => $old]);
            $c->t($n['html'] !== '' && $n['html'] === $p['html'], "G3 {$what}: the page is 1a0ea5f's, byte for byte",
                'new ' . strlen($n['html']) . ' bytes, old ' . strlen($p['html']) . '; first difference at byte ' . strspn($n['html'] ^ $p['html'], "\0")
                . ': ' . substr($n['html'], max(0, strspn($n['html'] ^ $p['html'], "\0") - 80), 200));
            $c->eq([cd_g_warnings($n['errlog']), $n['requests'] === $p['requests']], [[], true], "G3 {$what}: no PHP warning; the same uCRM requests as 1a0ea5f's");
            if ($what === 'Uganda, the switch off') {
                $names0 = cd_g_names($n['html']);
                $c->t(strpos($n['html'], 'data-link="1"') !== false && strpos($n['html'], 'name="ucrm_quote_form" value="5.18.102"') !== false
                    && strpos($n['html'], 'name="customer_type" value="Fiber"') !== false && strpos($n['html'], '5.18.103-direct') === false
                    && strpos($n['html'], 'q103') === false,
                    'G3 … it is 5.18.102\'s page priced through its links (data-link, the 5.18.102 marker, the Fiber card) — nothing of direct mode');
                $c->eq([($names0['package_choice'] ?? 0) > 0, $names0['device_id'] ?? 0, $names0['hw_cart_json'] ?? 0, $names0['ucrm_links_seen'] ?? 0],
                    [true, 2, 2, 1], 'G3 the control on G1\'s negative: the switch off, package_choice, device_id, hw_cart_json and ucrm_links_seen are all drawn');
            }
            if ($what === 'South Sudan') {
                $c->eq([$n['requests'], in_array('ucrm_catalogue', (array)$n['tables'], true)], [[], false],
                    'G3 South Sudan: no uCRM request, and the page creates no ucrm_catalogue table');
            }
            if (strpos($what, 'gate off') !== false) $c->eq($n['requests'], [], "G3 {$what}: no uCRM request");
        }
        $on = cd_g_draw($c, ['tree' => $tree, 'records' => $RECORDS]);
        $onp = cd_g_draw($c, ['tree' => $old, 'records' => $RECORDS]);
        $c->t($on['html'] !== '' && $onp['html'] !== '' && $on['html'] !== $onp['html'], 'G3 the control on the comparison: with the switch on, the page is not 1a0ea5f\'s');
    }

    // ── G4 in headless Chromium: the direct page and the stale one ──────────────────────────────────────────────────
    $nd = cd_g_node();
    if ($nd === null) {
        echo "  note G limitation: node or Playwright is not available here; the form's behaviour in a browser (g_kyc_form_browser.js)"
            . " and the submissions of what it posts (G5) were not run — a stated limitation, not a pass\n";
        return;
    }
    $pages = $c->dir('g-pages');
    file_put_contents("$pages/cd_on.html", $h);
    file_put_contents("$pages/cd_stale.html", $hS);
    file_put_contents("$pages/expect.json", json_encode(['tokens' => $TOK]));
    $script = __DIR__ . '/g_kyc_form_browser.js';
    $sock = @stream_socket_server('tcp://127.0.0.1:0', $en, $es);
    $bport = $sock ? (int)substr(strrchr((string)stream_socket_get_name($sock, false), ':'), 1) : 0;
    if ($sock) fclose($sock);
    $want = preg_match_all('/^\s*ok\(/m', (string)file_get_contents($script));
    $out = [];
    exec('cd ' . escapeshellarg($pages) . ' && QFU_H_PAGES=' . escapeshellarg($pages) . ' QFU_H_PORT=' . $bport . ' NODE_PATH=' . escapeshellarg($nd['path'])
        . ' timeout 300 ' . escapeshellarg($nd['node']) . ' ' . escapeshellarg($script) . ' 2>&1', $out, $brc);
    $np = $nf = 0;
    foreach ($out as $l) {
        if (strpos($l, 'PASS ') === 0) { $np++; $c->t(true, 'G4 browser ' . substr($l, 5)); }
        elseif (strpos($l, 'FAIL ') === 0) { $nf++; $c->t(false, 'G4 browser ' . substr($l, 5)); }
    }
    $c->t($brc === 0 && $np + $nf === $want && $want === 55, "G4 the browser check ran all {$want} of its checks to the end (exit {$brc})",
        "passed {$np}, failed {$nf}, of {$want}\n" . implode("\n", array_slice($out, -15)));

    // ── G5 what the browser posted, submitted: priced and accepted — the form and the server agree on the tokens ─────
    $postedOf = function (string $f) use ($pages): ?array {
        $p = json_decode((string)@file_get_contents("$pages/$f"), true);
        return is_array($p) && $p !== [] ? $p : null;
    };
    $credit = $postedOf('posted_credit.json');
    $cash = $postedOf('posted_cash.json');
    $c->t($credit !== null && $cash !== null, 'G5 the browser wrote the two posts it made (Credit, Cash)');
    if ($credit === null || $cash === null) return;
    // QfuCtx::submit()'s defaults (tests/quote_from_ucrm/_lib.php) for keys a post lacks: the browser's posts lack only the
    // two kept out below, so what KycService::process reads is the browser's post exactly
    $DEFAULTS = ['action', 'customer_type', 'connectivity_type', 'firstname', 'lastname', 'mobile', 'email', 'address_1', 'package_choice',
                 'device_id', 'kitQty', 'sales_type', 'priority', 'sales_person', 'ucrm_quote_form'];
    $c->eq([array_values(array_diff($DEFAULTS, array_keys($credit))), array_values(array_diff($DEFAULTS, array_keys($cash)))],
        [['package_choice', 'device_id'], ['package_choice', 'device_id']],
        'G5 the posts carry every field submit() would default but package_choice and device_id, which are kept out: the browser\'s posts, exactly');
    $quotes = function () use ($c) { return $c->bodies('kyc', 'POST', '/clients/\d+/quotes'); };
    // QfuCtx::submit() over a data folder whose ucrm_catalogue.json is the page's; package_choice and device_id kept out (a
    // key given as null is not posted), so the post is exactly the browser's but for submit()'s defaults for keys it lacks
    $submit = function (array $posted) use ($c, $RECORDS) {
        $seed = $c->child(cd_seed($RECORDS) . 'return true;');
        return $c->submit($posted + ['package_choice' => null, 'device_id' => null], ['dir' => $seed['dir'], 'wallet' => [7 => 100000]]);
    };
    $app = function (array $r): array { $a = (array)($r['value']['apps'] ?? []); return $a === [] ? [] : (array)end($a); };

    $fresh();
    $r = $submit($credit);
    $a = $app($r);
    $q1 = $quotes()[0] ?? [];
    $c->t(!empty($r['value']['success']) && count($quotes()) === 1, 'G5 the Credit post the browser made is accepted and quoted',
        json_encode([$r['value']['message'] ?? null, substr($r['out'], 0, 300), substr($r['errlog'], 0, 400)]));
    $c->eq([$a['quote_sale_entry'] ?? null, $a['quote_lines_source'] ?? null, $a['quote_items_total'] ?? null, $a['package_choice'] ?? 'x', $a['device_id'] ?? 'x'],
        ['web-direct', 'ucrm-direct-5.18.103', 1200, '', ''], 'G5 … stored web-direct, priced from uCRM at the 1,200 the form showed; no plugin id kept');
    $c->eq(array_map(function ($i) { return [$i['productId'] ?? null, $i['price'] ?? null, $i['quantity'] ?? null]; }, $q1['items'] ?? []),
        [[12, 100, 1], [CD_MINI, 900, 1], [CD_INSTALL, 200, 1]], 'G5 … its quotation: the plan\'s product, the Mini Kit, the installation — the standard quotation\'s items');

    $fresh();
    $r = $submit($cash);
    $a = $app($r);
    $c->eq([!empty($r['value']['success']), $a['amount_charged'] ?? null, $r['value']['wallet'] ?? null, $a['quote_items_total'] ?? null, count($quotes())],
        [true, 3650, 100000 - 3650, 3650, 1],
        'G5 the Cash post, with the browser\'s ucrm_total_seen and ucrm_items_seen: accepted, exactly the total shown collected, quoted',
        json_encode([$r['value']['message'] ?? null, substr($r['errlog'], 0, 400)]));
    $c->eq($a['quote_item_ids'] ?? null, ['plan' => ['sp' => CD_PLAN_BIZ, 'p' => 17], 'hw' => [['p' => CD_KIT, 'q' => 2], ['p' => CD_ACC, 'q' => 3]], 'install' => CD_INSTALL],
        'G5 … its uCRM ids as the browser chose them (ucrm_plan, ucrm_cart_json, install_by)');
    // the control on G5: one token the form drew for another item, in place of the installation's — refused, nothing written
    $swapped = ['ucrm_items_seen' => implode(',', array_map(function ($t) use ($TOK) { return $t === $TOK['inst'] ? $TOK['k90'] : $t; },
        explode(',', (string)($cash['ucrm_items_seen'] ?? ''))))] + $cash;
    $fresh();
    $r = $submit($swapped);
    $c->t($swapped['ucrm_items_seen'] !== ($cash['ucrm_items_seen'] ?? null) && empty($r['value']['success'])
        && strpos((string)($r['value']['message'] ?? ''), 'An item on this sale changed in uCRM since the form was opened') === 0
        && ($r['value']['apps'] ?? null) === [] && ($r['value']['wallet'] ?? null) === 100000 && $quotes() === [],
        'G5 the control: the same Cash post with one token not of its items — refused, nothing written, no money taken', json_encode($r['value']['message'] ?? $r['out']));
}];
