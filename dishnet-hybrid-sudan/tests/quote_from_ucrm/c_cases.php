<?php
declare(strict_types=1);
/**
 * Section C's cases for 5.18.102 D14 (docs/71 §R.9.1 "C", "C, Make the quotation"; §R.4.2, §R.4.4, §R.4.8, E-1, E-2,
 * F-1, F-2, F-7…F-10, F-13): the retries by link, and Make the quotation — the Orders card's button, the
 * kyc_make_quote handler, retry_quote's gated path and the one implementation both call (KycService::makeQuotation).
 * Loaded by C_retries.php; the helpers are c_lib.php's. Every section and case opens with its accepted control, and every
 * zero or absence is paired with a case that is not zero. TEST ONLY; every person, number and document is fictitious.
 *
 * Labels: C10 saved before the deploy · C11 relinked, re-priced, taxable · C12 an app-origin registration · C13 the
 * button's gate · C14 admin · C15 the own-page check · C16 only the id · C17 the guard · C18 the shared implementation
 * · C19 the company check once · C20 no token · C21 a double press · C22 the claim's removal · C23 each kind's answer ·
 * C24 escaping · C25 quote_error cleared · C26 who made it · C27 P1_UNLINKED at all three sites · C28 rule 8 at use, a
 * kit row deactivated with its link left in force (5.18.102 D14 review, D14-R8-INACTIVE-KIT).
 */
require_once __DIR__ . '/c_lib.php';

function qfu_c_cases(QfuCtx $c, array $x): void
{
    $sync = $x['sync']; $pending = $x['pending']; $marked = $x['marked']; $LINES = $x['LINES']; $ENTRY = $x['ENTRY'];
    $srv = qfu_c_servers($c);
    $c->t($srv['router'] !== '' && $srv['proxy'] !== '', 'C10 the control: the router and the proxy section C runs the handlers through started, from this run');
    if ($srv['router'] === '' || $srv['proxy'] === '') return;

    $CLIENT = ['id' => 901, 'organizationId' => 1, 'firstName' => 'Test', 'lastName' => 'Window', 'contacts' => []];
    // A fresh fake uCRM — the TEST catalogue, client 901 on file under the quoting organization — and the proxy's controls off.
    $up = function (array $set = [], string $scenario = 'uganda') use ($c, $srv, $CLIENT) {
        foreach (['hold', 'list_down', 'quote_id', 'post_lost', 'hold_client'] as $k) qfu_c_ctl($srv, $k, false);
        @unlink($srv['ctl'] . '/arrived_client');
        $c->ctl('kyc', '/__test/reset?scenario=' . $scenario);
        $c->ctl('kyc', '/__test/set', array_merge(['catalogue' => true, 'clients' => [$CLIENT]], $set));
    };
    $reqs   = fn(): array => $c->requests('kyc');
    $posts  = fn(): array => $c->bodies('kyc', 'POST', '/clients/\d+/quotes');
    $postTo = fn(): array => array_values(array_filter($reqs(), fn($r) => preg_match('#^POST /clients/\d+/quotes$#', $r)));
    $sends  = fn(): int => count(array_filter($reqs(), fn($r) => preg_match('#^PATCH /billing/quotes/[^ ]*/send$#', $r)));
    $pids   = fn(array $q): array => array_map(fn($i) => (int)($i['productId'] ?? 0), (array)($q['items'] ?? []));
    $TOK    = ['crm_auth_token' => 'FAKE-ADMIN-TOKEN'];
    $UG     = $TOK;
    $BACK   = '?page=dashboard&tab=applications';
    $UNL_KIT = 'Standard Kit (TEST) is not linked to a uCRM product yet — an admin links it on the Hardware screen';
    $UNL_INS = 'Installation is not linked to a uCRM product yet — an admin links it on the Hardware screen';
    $P1U = fn(string $r): string => 'No quotation was made: ' . $r . '. Do not make it by hand — once the item is linked, an admin makes it from the Orders card.';
    $P1  = fn(string $r): string => 'No quotation was made: ' . $r . '. Make the quotation in uCRM (Send Quotation, or ask an admin).';
    $FOUND_T = 'uCRM already has a quotation for this client made after this application was saved — no second one is made';
    // 5.18.102 D14 review (D14-MQ-THROW-AFTER-POST): retry_quote's 500 for a Throwable from the method
    $RQ_THROW = 'The quotation may not have been made — open the application before you retry; a retry never makes a second one.';
    $UNREAD_T = 'uCRM\'s quotations for this client could not be read — no quotation was made';
    $CLAIM_T = 'another quotation is being made for this application';
    // 5.18.102 D14 review round 2 (FX-flows-2): uCRM's answer held no id — "not confirmed", never "not made"
    $ERR_T   = 'uCRM did not confirm the quotation — it may have been made; a retry never makes a second one';
    $FL = [
        'made'       => fn(int $id, int $q = 77): string => "Quotation #{$q} was made in uCRM for application #{$id}. Its WhatsApp follows once uCRM's PDF is ready.",
        'found'      => fn(int $id): string => "uCRM already has a quotation for this client made after application #{$id} was saved — no second one was made.",
        'unreadable' => fn(int $id): string => "uCRM's quotations for this client could not be read, so no quotation was made for application #{$id} — try again.",
        'refused'    => fn(int $id): string => "No quotation was made for application #{$id} — its card says why.",
        'claimed'    => fn(int $id): string => "A quotation is being made for application #{$id} — wait, then open the Orders screen again.",
        'error'      => fn(int $id): string => "uCRM did not confirm the quotation for application #{$id}. It may have been made: try Make the quotation again later — it never makes a second one.",
        // 5.18.102 D14 review (D14-MQ-THROW-AFTER-POST): a Throwable may come after the POST, so it never says none was made
        'throwable'  => fn(int $id): string => "Making the quotation for application #{$id} did not finish. Open the Orders screen again before you retry: uCRM may already have it, and Make the quotation never makes a second one.",
        'conditions' => fn(int $id): string => "Make the quotation does not apply to application #{$id} now. Nothing was changed.",
    ];
    // A web KYC registration made in the window (docs/71 §R.6): created in uCRM as #901, unpriced because the kit was not
    // linked yet, P1 with P1_UNLINKED's text. Its sale was kept (quote_sale_cart / quote_sale_kit_qty) for the retry.
    $win = function (array $over = []) use ($P1U, $UNL_KIT): array {
        return $over + ['id' => 31, 'firstname' => 'Test', 'lastname' => 'Window', 'mobile' => '+256 700 003 031', 'email' => '',
            'address_1' => 'Test Road', 'customer_type' => 'StarLink', 'connectivity_type' => 'New Connection', 'package_choice' => '1',
            'device_id' => '3', 'kitQty' => '1', 'install_by' => 'dishnet', 'sales_type' => 'Credit', 'sales_person' => 'Tester',
            'retailer_name' => 'Test Agent', 'priority' => 'Low', 'crm_client_id' => '901', 'crm_sync_status' => 'synced',
            'submitted_at' => date('Y-m-d H:i:s'), 'status' => 'new', 'amount_charged' => 0, 'is_lead' => true, 'quote_created' => false,
            'quote_sale_entry' => 'web', 'quote_items' => null, 'offer_price' => null, 'device_price' => null, 'quote_sale_cart' => [],
            'quote_sale_kit_qty' => '1', 'quote_refused' => 'P1',
            'quote_refused_text' => 'The customer is in uCRM as #901. ' . $P1U($UNL_KIT) . ' Do not register this customer again.',
            'quote_refused_at' => date('Y-m-d H:i:s', time() - 3600), 'quote_refused_ts' => time() - 3600];   // round 9 (CK9-B1): recordNoQuote's epoch
    };
    $seed = fn(array $apps, array $o = []): string => qfu_c_seed($c, $apps, $o + ['cfg' => $UG, 'tree' => $srv['tree']]);
    $app  = fn(string $dir, int $id = 31): array => qfu_c_app($dir, $id);
    $hasClaim = fn(array $a): bool => array_key_exists('quote_make_claim_ts', $a);
    // 5.18.102 D14 review round 3 (CK-flows-2): what the Orders card says of application 31, drawn for an admin on Uganda
    // Round 5 (CK5-B2, CK5-T2, CK5-T3): the box's heading and its first sentence; and whether the card was drawn at all —
    // a page that died draws no box either, so every expectation also says the card reached its bottom row
    $UNCONF_H = '⚠ Making the last quotation did not finish';
    $UNCONF_S = '<div>It may have been made in uCRM. ';
    // Round 6 (CK6-F1): a press in flight; (CK6-F2) the card's own bottom row, never the stylesheet's rule for it
    $INFL_H = '⏳ Making a quotation for this application has not finished';
    $INFL_S = '<div>Do not make one by hand in uCRM: wait, then open the Orders screen again.</div>';
    // Round 11 (CK11-T4): each box matched whole — its opening, its heading, its one line, its close — so nothing may be
    // added anywhere inside it
    $BOXO = '<div style="margin-top:8px;padding:8px 10px;border-radius:10px;font-size:11px;color:#7c2d12;background:#FFF7ED;border:1px solid #FED7AA;">';
    $boxRe = fn(string $heading, string $line): string => '#' . preg_quote($BOXO, '#') . '\s*' . preg_quote('<div style="font-weight:800;margin-bottom:3px;">' . $heading . '</div>', '#')
        . '\s*<div>' . $line . '</div>\s*</div>#';
    $cardHtml = fn(string $d, bool $admin = true): string => qfu_c_card($c, $srv, [$app($d)], ['tenant_profile' => 'uganda'], $admin);
    $cardOf = function (string $d, bool $admin = true) use ($cardHtml, $UNCONF_H, $UNCONF_S, $INFL_H, $INFL_S, $boxRe): array {
        $h = $cardHtml($d, $admin);
        return ['unconfirmed' => strpos($h, $UNCONF_H) !== false && strpos($h, $UNCONF_S) !== false && preg_match($boxRe($UNCONF_H, 'It may have been made in uCRM\. [^<]*'), $h) === 1,
                'none_made' => strpos($h, '⚠ No quotation was made') !== false && preg_match($boxRe('⚠ No quotation was made', '[^<]*'), $h) === 1,
                'found' => strpos($h, '— no second one was made</div>') !== false, 'buttons' => count(qfu_c_buttons($h)),
                'drawn' => substr_count($h, '<div class="app-card-bottom">') === 1,
                // round 10 (CK10-T3): the in-flight box to its close, so nothing may be added inside it
                'inflight' => strpos($h, $INFL_H) !== false && preg_match($boxRe($INFL_H, preg_quote(substr($INFL_S, 5, -6), '#')), $h) === 1];
    };
    $CARD = fn(bool $u, bool $n, bool $f, int $b, bool $i = false): array => ['unconfirmed' => $u, 'none_made' => $n, 'found' => $f, 'buttons' => $b, 'drawn' => true, 'inflight' => $i];

    // ── C10 every application saved before the deploy is unpriced at its retry (E-1, §R.4.2) ─────────────────────
    // With every item linked. Each stored without quote_sale_entry; its control the same seeded 'web'.
    $pkg = [['id' => 1, 'name' => 'Retailer Package (TEST)', 'price' => 50, 'is_active' => true]];
    $c10 = [
        'a Credit Starlink New Connection'                       => [['install_by' => 'dishnet'], [], 'P1', [12, 55, 70]],
        'a Shifting Connection whose plan id is also a kyc_packages id' => [['connectivity_type' => 'Shifting Connection'], ['packages' => $pkg], 'P1', [12, 55]],
        'a Fiber sale'                                           => [['customer_type' => 'Fiber', 'install_by' => 'dishnet'], [], 'P1', 'fiber'],
        'a Cash one with cash collected'                         => [['install_by' => 'dishnet', 'sales_type' => 'Cash', 'amount_charged' => 1800, 'is_lead' => false], [], 'P2', [12, 55, 70]],
        'a Cash one with none collected'                         => [['install_by' => 'dishnet', 'sales_type' => 'Cash', 'amount_charged' => 0], [], 'P1', [12, 55, 70]],
        'a hand-seeded marked one, its lines confirmable'        => [$marked($LINES, 1800) + ['install_by' => 'dishnet'], [], 'P1', [12, 55, 70]],
    ];
    $c->ctl('kyc', '/__test/reset?scenario=uganda');
    foreach ($c10 as $what => [$over, $o, $code, $want]) {
        foreach (['cron retry' => 'sync', 'retry_quote' => 'rq'] as $where => $how) {
            foreach (['web (the control)' => 'web', 'stored without quote_sale_entry' => null] as $how2 => $entry) {
                $up();
                $fields = $over + ($entry !== null ? ['quote_sale_entry' => $entry] : []);
                if ($how === 'sync') {
                    $res = $sync($pending($fields + ['id' => 31]), $o);
                    $rec = (array)($res['value']['app'] ?? []);
                    $ans = !empty($res['value']['r']['ok']) ? 200 : 0;
                } else {
                    $row = $win($fields);
                    if ($entry === null) unset($row['quote_sale_entry']);
                    $dir = $seed([$row], $o);
                    $r = qfu_c_retry($c, $srv, $dir, 31);
                    $rec = $app($dir);
                    $ans = $r['code'];
                }
                $q = $posts()[0] ?? [];
                if ($entry === 'web') {
                    $ok = $want === 'fiber'
                        ? [count($posts()), strpos((string)($rec['quote_refused_text'] ?? ''), 'Fiber is not priced from uCRM') !== false] === [0, true]
                        : $pids($q) === $want && count($posts()) === 1;
                    $c->t($ok, "C10 the control, at the {$where}: {$what} seeded web is " . ($want === 'fiber' ? 'unpriced by the Fiber rule' : 'resolved by link and quoted once'),
                        json_encode([$ans, $pids($q), $rec['quote_refused'] ?? null, $rec['quote_refused_text'] ?? null, count($posts())]));
                } else {
                    $txt = (string)($rec['quote_refused_text'] ?? '');
                    $got = [count($posts()), $rec['quote_refused'] ?? null, $code === 'P1' ? strpos($txt, $ENTRY) !== false : strpos($txt, 'paid UGX 1,800 at registration') !== false,
                            strpos($txt, 'Do not make it by hand') !== false, $hasClaim($rec), array_key_exists('quote_made_via', $rec)];
                    $c->eq($got, [0, $code, true, false, false, false], "C10 at the {$where}, {$what} {$how2}: no quotation, {$code}" . ($code === 'P1' ? ' with the entry reason' : ' (cash collected)') . ' (E-1)');
                }
            }
        }
    }
    foreach (['app', '', 'WEB'] as $v) {
        foreach (['cron retry', 'retry_quote'] as $where) {
            $up();
            if ($where === 'cron retry') {
                $rec = (array)($sync($pending(['install_by' => 'dishnet', 'quote_sale_entry' => $v, 'id' => 31]))['value']['app'] ?? []);
            } else {
                $dir = $seed([$win(['quote_sale_entry' => $v])]);
                qfu_c_retry($c, $srv, $dir, 31);
                $rec = $app($dir);
            }
            $c->eq([count($posts()), $rec['quote_refused'] ?? null, strpos((string)($rec['quote_refused_text'] ?? ''), $ENTRY) !== false], [0, 'P1', true],
                "C10 at the {$where}, quote_sale_entry " . json_encode($v) . ' is unpriced like none: P1 with the entry reason');
        }
    }

    // ── C11 relinked, re-priced or made taxable between submission and retry (§R.4.4) ───────────────────────────────
    $relinked = [qfu_link(1, 'plan', 1, QFU_PLAN_STAMP, 12, 6), qfu_ended(qfu_link(2, 'kit', 3, QFU_KIT_STAMP, 55)),
                 qfu_link(3, 'install', 0, '', 70), qfu_link(4, 'kit', 3, QFU_KIT_STAMP, 55)];
    $c11 = [
        'nothing changed (the control)' => [[], null, ''],
        'the kit relinked (to the same product, at the same price)' => [[], $relinked, 'the uCRM link of Standard Kit (TEST) was changed after this application was saved'],
        'the kit re-priced in uCRM'     => [['products' => qfu_kyc_products([55 => ['price' => 1700]])], null, 'Standard Kit (TEST)\'s price in uCRM changed after this application was saved'],
        'the kit made taxable in uCRM'  => [['products' => qfu_kyc_products([55 => ['taxable' => true]])], null, 'is set as taxable'],
    ];
    foreach ($c11 as $what => [$set, $links, $reason]) {
        foreach (['Cash' => ['sales_type' => 'Cash', 'amount_charged' => 1800, 'is_lead' => false], 'Credit' => []] as $st => $more) {
            foreach (['cron retry', 'retry_quote'] as $where) {
                $up($set);
                $fields = $marked($LINES, 1800) + ['install_by' => 'dishnet', 'quote_sale_entry' => 'web'] + $more;
                $o = $links !== null ? ['links' => $links] : [];
                if ($where === 'cron retry') {
                    $rec = (array)($sync($pending($fields + ['id' => 31]), $o)['value']['app'] ?? []);
                } else {
                    $dir = $seed([$win($fields)], $o);
                    qfu_c_retry($c, $srv, $dir, 31);
                    $rec = $app($dir);
                }
                if ($reason === '') {
                    $c->eq([count($posts()), $pids($posts()[0] ?? [])], [1, [12, 55, 70]], "C11 the control, {$st}, at the {$where}: marked lines whose links and prices stand are quoted as stored");
                    continue;
                }
                $txt = (string)($rec['quote_refused_text'] ?? '');
                $c->eq([count($posts()), $rec['quote_refused'] ?? null, $st === 'Cash' ? strpos($txt, 'paid UGX 1,800') !== false : strpos($txt, $reason) !== false],
                    [0, $st === 'Cash' ? 'P2' : 'P1', true], "C11 {$what}, {$st}, at the {$where}: no quotation, " . ($st === 'Cash' ? 'P2' : 'P1 naming it'));
            }
        }
    }

    // ── C12 an app-origin registration stays unpriced after the plan is linked; no Make the quotation for it ───────
    $appOrigin = $win(['id' => 32, 'quote_sale_entry' => 'app', 'quote_refused_text' => 'The customer is in uCRM as #901. ' . $P1($ENTRY) . ' Do not register this customer again.']);
    $up();
    $dir = $seed([$win(['id' => 32])]);
    $r = qfu_c_retry($c, $srv, $dir, 32);
    $c->eq([$r['code'], count($posts())], [200, 1], 'C12 the control: the same registration stored web, every item linked, is quoted by retry_quote');
    $up();
    $dir = $seed([$appOrigin]);
    $r = qfu_c_retry($c, $srv, $dir, 32);
    $rec = $app($dir, 32);
    $c->eq([$r['code'], count($posts()), $rec['quote_refused'] ?? null, strpos((string)($r['json']['message'] ?? ''), $ENTRY) !== false, strpos((string)($rec['quote_refused_text'] ?? ''), 'Do not make it by hand') !== false],
        [409, 0, 'P1', true, false], 'C12 retry_quote of an app-origin registration, every item linked: 409, no quotation, P1 with the entry reason');
    $up();
    $rec = (array)($sync($pending(['id' => 32, 'install_by' => 'dishnet', 'quote_sale_entry' => 'app']))['value']['app'] ?? []);
    $c->eq([count($posts()), $rec['quote_refused'] ?? null, strpos((string)($rec['quote_refused_text'] ?? ''), $ENTRY) !== false], [0, 'P1', true],
        'C12 … and the cron retry (KycCrmSync, its line 364) the same');
    $html = qfu_c_card($c, $srv, [$appOrigin, $win(['id' => 33])], ['tenant_profile' => 'uganda'], true);
    $c->eq(array_map(fn($b) => $b['fields']['app_id'] ?? null, qfu_c_buttons($html)), ['33'],
        'C12 … and the Orders card draws no Make the quotation for it, while the web registration beside it has one (the control)');

    // ── C13 the button's gate (§R.4.8: Uganda with the gate on, an admin, the five conditions) ─────────────────────
    $ok13 = $win(['id' => 41]);
    $fails = [
        'entry app' => ['quote_sale_entry' => 'app'], 'entry absent' => ['quote_sale_entry' => null], 'no client' => ['crm_client_id' => null],
        'P2' => ['quote_refused' => 'P2'], 'P3' => ['quote_refused' => 'P3'], 'an R-code' => ['quote_refused' => 'R1'],
        'quote_created' => ['quote_created' => true, 'quote_id' => 5], 'quote_found_in_ucrm' => ['quote_found_in_ucrm' => ['number' => 'Q-5', 'at' => date('Y-m-d H:i:s')]],
    ];
    $set13 = [$ok13]; $n = 42;
    foreach ($fails as $what => $f) {
        $row = array_merge($win(['id' => $n]), $f);
        if (array_key_exists('quote_sale_entry', $f) && $f['quote_sale_entry'] === null) unset($row['quote_sale_entry']);
        $set13[] = $row; $n++;
    }
    $html = qfu_c_card($c, $srv, $set13, ['tenant_profile' => 'uganda'], true);
    $btn = qfu_c_buttons($html);
    $c->eq(array_map(fn($b) => $b['fields']['app_id'] ?? null, $btn), ['41'],
        'C13 Uganda, gate on, an admin: Make the quotation on the one card that meets the five conditions, on none of the eight that fail one (' . implode(', ', array_keys($fails)) . ')');
    $b0 = $btn[0] ?? ['form' => '', 'fields' => [], 'button_attrs' => '', 'label' => ''];
    $c->eq([array_keys($b0['fields']), $b0['fields']['action'] ?? null, $b0['label'], preg_match('/\bname=/', $b0['button_attrs']), preg_match('/^<form method="POST"/', $b0['form']),
            strpos($b0['form'], 'b.disabled=true') !== false, strpos($b0['form'], 'name="_csrf"') < strpos($b0['form'], '</form>')],
        [['_csrf', 'action', 'app_id'], 'kyc_make_quote', 'Make the quotation', 0, 1, true, true],
        'C13 … a POST form with csrfField() inside it carrying action and app_id and nothing else; its button has no name and disables itself on submit');
    foreach ($fails as $what => $f) {
        $row = array_merge($win(['id' => 41]), $f);
        if (array_key_exists('quote_sale_entry', $f) && $f['quote_sale_entry'] === null) unset($row['quote_sale_entry']);
        $c->eq(count(qfu_c_buttons(qfu_c_card($c, $srv, [$row, $win(['id' => 99])], ['tenant_profile' => 'uganda'], true))), 1,
            "C13 … {$what}: not drawn on that card alone (the card beside it, which meets them, keeps its own)");
    }
    // The handler checks the same five conditions again (step 5): a press for each failing card is refused there, nothing
    // written and nothing sent — the card hides the button behind the guard's line too, so the re-check is what holds.
    $up();
    $d13 = $seed([$ok13]);
    $r = qfu_c_press($c, $srv, $d13, 41);
    $c->eq([$r['flash'], count($posts())], [$FL['made'](41), 1], 'C13 the control: a press for the card that meets the five conditions makes the quotation');
    foreach ($fails as $what => $f) {
        $row = array_merge($win(['id' => 41]), $f);
        if (array_key_exists('quote_sale_entry', $f) && $f['quote_sale_entry'] === null) unset($row['quote_sale_entry']);
        $up();
        $d13 = $seed([$row]);
        $b13 = $app($d13, 41);
        $r = qfu_c_press($c, $srv, $d13, 41);
        $c->eq([$r['flash'], $app($d13, 41) === $b13, $reqs()], [$FL['conditions'](41), true, []],
            "C13 … a press for the card that fails on {$what}: refused by the conditions again (step 5), nothing written, nothing sent");
    }
    $html = qfu_c_card($c, $srv, [$ok13], ['tenant_profile' => 'uganda'], false);
    $c->eq([count(qfu_c_buttons($html)), substr_count($html, 'bi-cloud-check')], [0, 1], 'C13 … not drawn for a staff member who is not an admin (the card itself is)');
    foreach (['South Sudan' => ['tenant_profile' => 'south-sudan', 'currency_code' => 'USD', 'currency_symbol' => '$'],
              'Uganda with the setting 0' => ['tenant_profile' => 'uganda', 'ucrm_quote_organization_id' => '0'],
              'Uganda with the setting off' => ['tenant_profile' => 'uganda', 'ucrm_quote_organization_id' => 'off']] as $what => $cfg) {
        $html = qfu_c_card($c, $srv, [$ok13], $cfg, true);
        $c->eq([count(qfu_c_buttons($html)), strpos($html, 'kyc_make_quote') !== false, substr_count($html, 'bi-cloud-check'), strpos($html, 'Fatal') !== false],
            [0, false, 1, false], "C13 … {$what}: not drawn, nor named anywhere on the page, and the page itself is whole");
    }

    // ── C14 admin: kyc_make_quote and retry_quote judge the account re-read fresh by id (§R.2.1, §R.4.8 step 2, §R.16)
    $stale = ['demoted' => [QFU_C_ADMIN, ['is_admin' => false]], 'inactive' => [QFU_C_ADMIN, ['is_active' => false]],
              'deleted' => null, 'whose read throws' => 'throw'];
    $up();
    $dir = $seed([$win()]);
    $r = qfu_c_press($c, $srv, $dir, 31);
    $c->eq([$r['flash'], count($posts())], [$FL['made'](31), 1], 'C14 the control: a fresh admin is accepted — the quotation is made');
    foreach (['signed out' => null, 'not an admin' => QFU_C_AGENT] as $what => $who) {
        $up();
        $dir = $seed([$win()]);
        $before = $app($dir);
        $r = qfu_c_press($c, $srv, $dir, 31, [], ['session' => $who]);
        $want = $who === null ? [0, '?page=login', '', true, []] : [403, '', '', true, []];
        $c->eq([$who === null ? 0 : $r['code'], $r['location'], $r['flash'], $app($dir) === $before, $reqs()], $want,
            "C14 kyc_make_quote, {$what}: requireAdmin's own answer (" . ($who === null ? 'the sign-in redirect' : '403') . '), nothing written, nothing sent to uCRM');
        if ($who !== null) $c->t(strpos($r['body'], 'Access denied — admin only.') !== false, 'C14 … the 403 says why');
    }
    foreach ($stale as $what => $row) {
        $rets = [QFU_C_AGENT];
        if (is_array($row)) $rets[] = array_merge($row[0], $row[1]);
        if ($row === 'throw') $rets[] = QFU_C_ADMIN;   // the row is there and active: only its read fails
        $up();
        $dir = $seed([$win()], ['retailers' => $rets]);
        $before = $app($dir);
        $r = qfu_c_press($c, $srv, $dir, 31, [], $row === 'throw' ? ['faults_handler' => ['find_retailer' => true]] : []);
        $c->eq([$r['code'], strpos($r['body'], 'Access denied — admin only.') !== false, $r['flash'], $app($dir) === $before, $reqs()], [403, true, '', true, []],
            "C14 kyc_make_quote, a session whose cached record says admin (refreshed seconds ago) but whose stored row is {$what}: 403, nothing written, nothing sent");
        $up();
        $dir = $seed([$win()], ['retailers' => $rets]);
        $before = $app($dir);
        $r = qfu_c_retry($c, $srv, $dir, 31, $row === 'throw' ? ['faults_handler' => ['find_retailer' => true]] : []);
        $c->eq([$r['code'], $r['json']['message'] ?? null, count($posts()), $app($dir) === $before], [403, 'Admin only.', 0, true],
            "C14 retry_quote's gated path, the API guard's record an admin but the stored row {$what}: 403, no quotation, nothing written");
    }
    $up();
    $dir = $seed([$win()]);
    $r = qfu_c_retry($c, $srv, $dir, 31);
    $c->eq([$r['code'], count($posts())], [200, 1], 'C14 the control: retry_quote for a fresh admin makes the quotation');

    // ── C15 the own-page check, made by the handler itself (§R.4.8 step 3; L's refusals (i)–(v)) ────────────────
    $host = $srv['router'];
    $ref15 = [
        '(i) no _csrf, with cb_action=x'                         => [['_csrf' => null, 'cb_action' => 'x'], ['Sec-Fetch-Site: same-origin']],
        '(i) no _csrf, with sc_action=x'                         => [['_csrf' => null, 'sc_action' => 'x'], ['Sec-Fetch-Site: same-origin']],
        '(i) no _csrf at all'                                    => [['_csrf' => null], ['Sec-Fetch-Site: same-origin']],
        '(i) a malformed _csrf'                                  => [['_csrf' => 'TEST:not-a-token'], ['Sec-Fetch-Site: same-origin']],
        '(ii) a valid token, Sec-Fetch-Site cross-site'          => [[], ['Sec-Fetch-Site: cross-site']],
        '(ii) a valid token, Sec-Fetch-Site same-site'           => [[], ['Sec-Fetch-Site: same-site']],
        '(iii) a valid token, an Origin of another host'         => [[], ['Origin: http://evil.example.test']],
        '(iii) a valid token, Origin: null'                      => [[], ['Origin: null']],
        '(iv) a valid token, a Referer of another host'          => [[], ['Referer: http://evil.example.test/page']],
        '(v) a valid token and none of the three headers'        => [[], []],
    ];
    foreach (['a valid token with Sec-Fetch-Site same-origin' => ['Sec-Fetch-Site: same-origin'],
              'no Sec-Fetch-Site, an Origin naming this host'   => ['Origin: ' . $host],
              'only a Referer naming this host'                 => ['Referer: ' . $host . '/public.php?page=dashboard&tab=applications']] as $what => $hdr) {
        $up();
        $d2 = $seed([$win()]);
        $r = qfu_c_press($c, $srv, $d2, 31, [], ['headers' => $hdr]);
        $c->eq([$r['flash'], count($posts())], [$FL['made'](31), 1], "C15 the control, {$what}: accepted, one quotation");
    }

    $up();
    $dir = $seed([$win()]);
    $before = $app($dir);
    foreach ($ref15 as $what => [$more, $hdr]) {
        $up();
        $r = qfu_c_press($c, $srv, $dir, 31, $more, ['headers' => $hdr]);
        $c->eq([$r['flash'], $r['location'], $app($dir) === $before, $reqs()], [QFU_C_FLASH_SECURITY, $BACK, true, []],
            "C15 {$what}: refused with the security check's flash, nothing written, nothing sent to uCRM");
    }
    // ── C16 nothing from the request but the application's number (F-2, §R.4.8 step 4) ─────────────────────────
    $two = [$win(['id' => 30, 'crm_client_id' => '902', 'lastname' => 'Other']), $win()];
    $set902 = ['clients' => [$CLIENT, ['id' => 902, 'organizationId' => 1, 'firstName' => 'Test', 'lastName' => 'Other', 'contacts' => []]]];
    $up($set902);
    $dir = $seed($two);
    $r = qfu_c_press($c, $srv, $dir, 31);
    $c->eq([$r['flash'], $postTo(), $pids($posts()[0] ?? []), (int)($posts()[0]['items'][1]['price'] ?? 0)], [$FL['made'](31), ['POST /clients/901/quotes'], [12, 55, 70], 1500],
        'C16 the control: action, _csrf and app_id alone — one POST, to the stored client #901, with the lines the store\'s links resolve (the linked products 12, 55, 70)');
    $c->eq([qfu_c_app($dir, 30)['quote_created'] ?? null, $hasClaim(qfu_c_app($dir, 30))], [false, false], 'C16 … the other application in the store is untouched');
    $up($set902);
    $dir = $seed($two);
    $before = qfu_c_apps($dir);
    foreach (['crm_client_id naming another client' => ['crm_client_id' => '902'], 'amount_charged' => ['amount_charged' => '1800'],
              'quote_sale_entry' => ['quote_sale_entry' => 'web'], 'items' => ['items' => [['productId' => 13, 'price' => 1, 'quantity' => 1]]]] as $what => $more) {
        $up($set902);
        $r = qfu_c_press($c, $srv, $dir, 31, $more);
        $c->eq([$r['flash'], $r['location'], qfu_c_apps($dir) === $before, $reqs()], [QFU_C_FLASH_EXTRA, $BACK, true, []],
            "C16 one field more ({$what}): refused with step 4's flash, nothing written, nothing sent");
    }
    foreach (['31a', '-31', '3.1', ' 31', '', '0x1f'] as $bad) {
        $up($set902);
        $r = qfu_c_press($c, $srv, $dir, 31, ['app_id' => $bad]);
        $c->eq([$r['flash'], qfu_c_apps($dir) === $before, $reqs()], [QFU_C_FLASH_EXTRA, true, []], 'C16 an app_id that is not digits only (' . json_encode($bad) . '): refused the same way');
    }
    $up($set902);
    $r = qfu_c_press($c, $srv, $dir, 31, ['app_id' => ['31']]);
    $c->eq([$r['flash'], qfu_c_apps($dir) === $before, $reqs()], [QFU_C_FLASH_EXTRA, true, []], 'C16 … nor an app_id posted as a list');
    $up();
    $d2 = $seed([$win(['id' => 32, 'quote_sale_entry' => 'app'])]);
    $b2 = qfu_c_apps($d2);
    $r = qfu_c_press($c, $srv, $d2, 32);
    $c->eq([$r['flash'], qfu_c_apps($d2) === $b2, $reqs()], [$FL['conditions'](32), true, []],
        'C16 a post for an application stored as app: refused by the conditions (step 5), nothing written, nothing sent');
    foreach ([1800 => [1, null], 1600 => [0, 'P2']] as $amt => [$n, $code]) {
        $up();
        $d2 = $seed([$win(['sales_type' => 'Cash', 'amount_charged' => $amt, 'is_lead' => false])]);
        $r = qfu_c_press($c, $srv, $d2, 31);
        $rec = $app($d2);
        $c->eq([count($posts()), $n === 1 ? null : ($rec['quote_refused'] ?? null), $r['flash']], [$n, $code, $n === 1 ? $FL['made'](31) : $FL['refused'](31)],
            "C16 a web Cash application with cash collected, stored amount_charged {$amt} against lines totalling 1800: " . ($n === 1 ? 'quoted' : 'P2, nothing posted'));
    }

    // ── C17 R-M4's guard: never a second quotation (§R.4.4) ─────────────────────────────────────────────────────
    // A real window registration: the web form's Credit sale with the kit not yet linked, then the kit linked.
    $up();
    $c->ctl('kyc', '/__test/reset?scenario=uganda');   // uCRM creates the client: none on file before
    $c->ctl('kyc', '/__test/set', ['catalogue' => true]);
    $sub = $c->submit(['install_by' => 'dishnet'], ['links' => [qfu_link(1, 'plan', 1, QFU_PLAN_STAMP, 12, 6), qfu_link(3, 'install', 0, '', 70)],
                                                    'cfg' => $UG, 'tree' => $srv['tree']]);
    $reg = (array)(end($sub['value']['apps']) ?: []);
    $regId = (int)($reg['id'] ?? 0);
    $c->eq([$reg['quote_sale_entry'] ?? null, $reg['quote_refused'] ?? null, strpos((string)($reg['quote_refused_text'] ?? ''), $P1U($UNL_KIT)) !== false, (string)($reg['crm_client_id'] ?? ''), count($posts())],
        ['web', 'P1', true, '901', 0], 'C17 the window registration: a web Credit sale with the kit unlinked is registered with no quotation, P1_UNLINKED\'s text');
    $regDir = $sub['dir'];
    qfu_c_table($c, $regDir, 'ucrm_quote_links.json', qfu_links());
    qfu_c_table($c, $regDir, 'retailers.json', [QFU_C_ADMIN, QFU_C_AGENT]);
    file_put_contents($regDir . '/csrf_secret.key', str_repeat('ab', 32));
    $quoteNow = fn(int $client = 901, ?string $made = null, string $number = 'Q-5') => ['quotes' => ['5' => ['id' => 5, 'number' => $number,
        'clientId' => $client, 'status' => 0, 'items' => [], 'total' => 0, 'createdDate' => $made ?? date('c')]]];
    foreach (['Make the quotation' => 'press', 'retry_quote, on a second copy' => 'retry'] as $what => $how) {
        $d = qfu_c_copy($c, $regDir);
        $up();
        $r = $how === 'press' ? qfu_c_press($c, $srv, $d, $regId) : qfu_c_retry($c, $srv, $d, $regId);
        $rec = $app($d, $regId);
        $c->eq([count($posts()), $postTo(), $pids($posts()[0] ?? []), !empty($rec['quote_created']), $rec['quote_link_ids'] ?? null],
            [1, ['POST /clients/901/quotes'], [12, 55, 70], true, [1, 2, 3]], "C17 the control, {$what}: once linked, the window registration is quoted exactly once, by the links");
        $d = qfu_c_copy($c, $regDir);
        $up($quoteNow());
        $before = $app($d, $regId);
        $r = $how === 'press' ? qfu_c_press($c, $srv, $d, $regId) : qfu_c_retry($c, $srv, $d, $regId);
        $rec = $app($d, $regId);
        $c->eq([$how === 'press' ? $r['flash'] : [$r['code'], $r['json']['message'] ?? null], count($posts()), $sends(),
                !empty($rec['quote_created']), array_key_exists('wa_quote_pending', $rec), array_keys(qfu_c_delta($before, $rec)), $rec['quote_found_in_ucrm']['number'] ?? null],
            [$how === 'press' ? $FL['found']($regId) : [409, $FOUND_T], 0, 0, false, false, ['quote_found_in_ucrm'], 'Q-5'],
            "C17 {$what}, the same registration quoted by hand in uCRM first: refused (the guard), no POST, no PATCH …/send, no quote_created, no wa_quote_pending — only quote_found_in_ucrm recorded");
        if ($how === 'press') {
            $html = qfu_c_card($c, $srv, [$rec], ['tenant_profile' => 'uganda'], true);
            $c->eq([strpos($html, 'A quotation was made in uCRM after registration (#Q-5) — no second one was made') !== false, count(qfu_c_buttons($html)),
                    count(qfu_c_buttons(qfu_c_card($c, $srv, [$before], ['tenant_profile' => 'uganda'], true)))], [true, 0, 1],
                'C17 … the card then shows the quotation found, and no Make the quotation (before the press it had one: the control)');
            // 5.18.102 D14 review (D14-UI-4): the found card no longer prints the registration's refusal text beneath its line
            $fb = qfu_c_card($c, $srv, [$before], ['tenant_profile' => 'uganda'], true);
            $c->eq([strpos($html, 'No quotation was made') !== false, strpos($html, 'an admin makes it from the Orders card') !== false,
                    strpos($fb, 'No quotation was made') !== false, strpos($fb, 'an admin makes it from the Orders card') !== false], [false, false, true, true],
                'C17 … and the found card no longer says no quotation was made, nor that an admin makes it from the Orders card (before the press it said both: the control)');
        }
    }
    // an existing customer: only a quotation made on or after submitted_at counts (a date that cannot be read counts as after)
    $ex = $win(['id' => 51, 'is_additional_service' => true, 'connectivity_type' => 'Shifting Connection', 'install_by' => null]);
    unset($ex['install_by']);
    foreach (['only an older quotation' => [date('c', time() - 86400), 1], 'a quotation made after it was saved' => [date('c', time() + 120), 0],
              'a quotation whose date cannot be read' => ['TEST not a date', 0]] as $what => [$when, $n]) {
        foreach (['press', 'retry'] as $how) {
            $up($quoteNow(901, $when));
            $d = $seed([$ex]);
            $r = $how === 'press' ? qfu_c_press($c, $srv, $d, 51) : qfu_c_retry($c, $srv, $d, 51);
            $c->eq([count($posts()), !empty($app($d, 51)['quote_found_in_ucrm'])], [$n, $n === 0],
                "C17 an existing customer (a Shifting Connection), {$what}, " . ($how === 'press' ? 'at the button' : 'at retry_quote') . ': ' . ($n ? 'still quoted once' : 'refused by the guard'));
        }
    }
    foreach (['press', 'retry'] as $how) {
        $up($quoteNow(902));
        $d = $seed([$win()]);
        $r = $how === 'press' ? qfu_c_press($c, $srv, $d, 31) : qfu_c_retry($c, $srv, $d, 31);
        $c->eq([count($posts()), $postTo()], [1, ['POST /clients/901/quotes']],
            'C17 another client\'s quotation in uCRM\'s answer, ' . ($how === 'press' ? 'at the button' : 'at retry_quote') . ': ignored — quoted once (the control on the guard)');
        $up();
        qfu_c_ctl($srv, 'list_down', true);
        $d = $seed([$win()]);
        $before = $app($d);
        $r = $how === 'press' ? qfu_c_press($c, $srv, $d, 31) : qfu_c_retry($c, $srv, $d, 31);
        $c->eq([$how === 'press' ? $r['flash'] : [$r['code'], $r['json']['message'] ?? null], count($posts()), $app($d) === $before],
            [$how === 'press' ? $FL['unreadable'](31) : [409, $UNREAD_T], 0, true],
            'C17 uCRM\'s quotation list unreadable, ' . ($how === 'press' ? 'at the button' : 'at retry_quote') . ': refused, no POST, nothing written at all');
        qfu_c_ctl($srv, 'list_down', false);
    }

    // ── C18 the shared implementation, used by both entry points (F-1) ──────────────────────────────────────────
    $gated = function (string $file, string $from, string $to) use ($c): string {
        $src = (string)@file_get_contents($c->root . '/' . $file);
        $i = strpos($src, $from);
        $j = $i === false ? false : strpos($src, $to, $i + strlen($from));
        return ($i === false || $j === false || substr_count($src, $from) !== 1) ? '' : substr($src, $i, $j - $i);
    };
    $blocks = [
        'retry_quote\'s gated block' => $gated('includes/api/api_cron_debug.php', "        if (\$_qoRetry['on']) {\n            // The account re-read fresh by id", '        // Build items from app data'),
        'kyc_make_quote'             => $gated('includes/post/post_sync.php', "if (\$_SERVER['REQUEST_METHOD'] === 'POST' && (\$_POST['action'] ?? '') === 'kyc_make_quote') {", "if (\$_SERVER['REQUEST_METHOD']==='POST' && (\$_POST['action']??'')==='sim_inbound') {"),
    ];
    foreach ($blocks as $what => $blk) {
        $code = (string)preg_replace('#//[^\n]*#', '', $blk);
        $c->eq([$blk !== '', substr_count($code, '->makeQuotation('), preg_match('/->post\(|postQuote\(|readBack\(|->patch\(|sendQuoteViaCrm\(|retryLines\(|new UcrmQuoteLines/', $code)],
            [true, 1, 0], "C18 {$what} calls ->makeQuotation( once, and neither posts, reads back, sends nor resolves a quotation itself");
    }
    foreach (['on' => [], 'off' => ['kyc_quote_send_via_crm' => '0']] as $sendMode => $cfgMore) {
        $base = $seed([$win(['quote_error' => null])], ['cfg' => $UG + $cfgMore]);
        $runs = [];
        foreach (['orders_card' => 'press', 'retry_quote' => 'retry'] as $via => $how) {
            $d = qfu_c_copy($c, $base);
            $up();
            $before = $app($d);
            $r = $how === 'press' ? qfu_c_press($c, $srv, $d, 31) : qfu_c_retry($c, $srv, $d, 31);
            $after = $app($d);
            $runs[$via] = ['delta' => qfu_c_untimed(qfu_c_delta($before, $after)), 'via' => $after['quote_made_via'] ?? null,
                           'paths' => array_values(array_filter($reqs(), fn($q) => preg_match('#^(POST|PATCH) #', $q))), 'lines' => array_map(fn($b) => $b['items'] ?? null, $posts())];
        }
        $strip = function (array $d) { unset($d['quote_made_via']); return $d; };
        $c->eq([$runs['orders_card']['via'], $runs['retry_quote']['via']], ['orders_card', 'retry_quote'], "C18 (send {$sendMode}) quote_made_via names each entry point (F-13)");
        $c->eq($strip($runs['orders_card']['delta']), $strip($runs['retry_quote']['delta']), "C18 (send {$sendMode}) the same application quoted once through each, on two copies of one store: the same writes, the times apart");
        $c->t(count($runs['orders_card']['delta']) >= 8, "C18 (send {$sendMode}) … and those writes are the quotation's (the control: not two empty deltas)", json_encode($runs['orders_card']['delta']));
        $c->eq($runs['orders_card']['paths'], $runs['retry_quote']['paths'], "C18 (send {$sendMode}) … the same POST and PATCH paths in the fake uCRM's log");
        $c->eq([$runs['orders_card']['lines'], count($runs['orders_card']['lines'])], [$runs['retry_quote']['lines'], 1], "C18 (send {$sendMode}) … the same posted lines, one POST each");
        $c->eq(in_array('PATCH /billing/quotes/77/send', $runs['orders_card']['paths'], true), $sendMode === 'on',
            "C18 (send {$sendMode}) uCRM's send follows kyc_quote_send_via_crm at both" . ($sendMode === 'on' ? ' (the control: on, PATCH …/send)' : ': off, neither PATCHes …/send'));
    }

    // ── C19 the company check, asked once (F-1) ─────────────────────────────────────────────────────────────────
    foreach (['press' => 'the button\'s (postQuote\'s)', 'retry' => 'retry_quote\'s own'] as $how => $whose) {
        $up();
        $d = $seed([$win()]);
        $how === 'press' ? qfu_c_press($c, $srv, $d, 31) : qfu_c_retry($c, $srv, $d, 31);
        $log = $reqs();
        $at = array_search('POST /clients/901/quotes', $log, true);
        $c->eq([$at !== false, count(array_keys(array_slice($log, 0, $at === false ? 0 : $at), 'GET /clients/901'))], [true, 1],
            "C19 " . ($how === 'press' ? 'at the button' : 'at retry_quote') . ": exactly one GET clients/901 before the POST, {$whose}");
        $up(['clients' => [['organizationId' => 3] + $CLIENT]], 'two_orgs');
        $d = $seed([$win()]);
        $r = $how === 'press' ? qfu_c_press($c, $srv, $d, 31) : qfu_c_retry($c, $srv, $d, 31);
        $rec = $app($d);
        $c->eq([count($posts()), $how === 'press' ? [$r['flash'], $rec['quote_refused'] ?? null] : $r['code']], [0, $how === 'press' ? [$FL['refused'](31), 'R1'] : 409],
            'C19 … a client filed under another organization: refused ' . ($how === 'press' ? 'at the button (R1 recorded)' : 'at retry_quote (409)') . ', no POST');
        // 5.18.102 D14 review round 4 (CK4-F4): a refusal is not an unconfirmed answer — no quote_unconfirmed_at, no box
        $k = $cardOf($d);
        $c->eq([array_key_exists('quote_unconfirmed_at', $rec), $k['unconfirmed'], $k], [false, false, $how === 'press' ? $CARD(false, true, false, 0) : $CARD(false, true, false, 1)],
            'C19 … ' . ($how === 'press' ? 'at the button the refusal records no quote_unconfirmed_at, so the card draws no "did not finish" box but the refusal (R1), and no button'
                : 'at retry_quote its own company check refuses before Make the quotation runs (409): no quote_unconfirmed_at, no box; the card as it was, the stored refusal and the button'));
    }

    // Round 11 (CK11-T2): the same double press while the first's claim is stamped ahead of the clock (a refusal recorded
    // 30 s ahead): the second is answered claimed, and exactly one quotation is made
    $up();
    qfu_c_ctl($srv, 'hold', true);
    $rt = time() + 30;
    $d = $seed([$win(['quote_refused_ts' => $rt, 'quote_refused_at' => date('Y-m-d H:i:s', $rt)])]);
    $first = qfu_c_start(qfu_c_press($c, $srv, $d, 31, [], ['async' => true]));
    $held = qfu_c_until($first, fn() => is_file($srv['ctl'] . '/arrived'));
    $mid = $app($d);
    $r2 = qfu_c_press($c, $srv, $d, 31);
    qfu_c_ctl($srv, 'hold', false);
    $r1 = qfu_c_finish($first);
    $c->eq([$held, $mid['quote_make_claim_ts'] ?? null, $r2['flash'], $r1['flash'], count($posts())], [true, $rt + 1, $FL['claimed'](31), $FL['made'](31), 1],
        'C21 (b) a second press while the first, its claim stamped a second after a refusal 30 s ahead of the clock, is held at its POST: answered claimed; one quotation');
    // ── C20 no token needed (§R.4.8 step 6) ─────────────────────────────────────────────────────────────────────
    $up();
    $d = $seed([$win()], ['cfg' => []]);
    $r = qfu_c_press($c, $srv, $d, 31);
    $log = $c->ctl('kyc', '/__test/log');
    $postAuth = array_values(array_map(fn($e) => $e['auth'] ?? null, array_filter($log, fn($e) => ($e['method'] ?? '') === 'POST' && preg_match('#/clients/\d+/quotes$#', (string)($e['path'] ?? '')))));
    $c->eq([$r['flash'], $postAuth], [$FL['made'](31), ['app-key']], 'C20 crm_auth_token unset: Make the quotation quotes through the plugin\'s own client (its app key)');
    $up();
    $d = $seed([$win()], ['cfg' => []]);
    $r = qfu_c_retry($c, $srv, $d, 31);
    $c->eq([$r['code'], $r['json']['message'] ?? null, count($posts())], [400, 'No admin auth token configured in Settings.', 0], 'C20 … while retry_quote still answers 400');

    // ── C21 a double press (F-9) ────────────────────────────────────────────────────────────────────────────────
    foreach (['a second press' => 'press', 'retry_quote' => 'retry'] as $what => $second) {
        $up();
        qfu_c_ctl($srv, 'hold', true);
        $d = $seed([$win()]);
        $first = qfu_c_start(qfu_c_press($c, $srv, $d, 31, [], ['async' => true]));
        $held = qfu_c_until($first, fn() => is_file($srv['ctl'] . '/arrived'));
        $mid = $app($d);
        $r2 = $second === 'press' ? qfu_c_press($c, $srv, $d, 31) : qfu_c_retry($c, $srv, $d, 31);
        $after2 = $app($d);
        qfu_c_ctl($srv, 'hold', false);
        $r1 = qfu_c_finish($first);
        $c->t($held && $hasClaim($mid), "C21 the control, {$what}: the first press is held at its POST, its claim taken", json_encode([$held, $mid['quote_make_claim_ts'] ?? null]));
        $c->eq([$second === 'press' ? $r2['flash'] : [$r2['code'], $r2['json']['message'] ?? null], $after2['quote_make_claim_ts'] ?? null],
            [$second === 'press' ? $FL['claimed'](31) : [409, $CLAIM_T], $mid['quote_make_claim_ts'] ?? 'none'],
            "C21 {$what} while the fake uCRM holds the first's POST: answered claimed, and it removes nothing — the first's claim is still there");
        $c->eq([$r1['flash'], count($posts()), $hasClaim($app($d))], [$FL['made'](31), 1, false], "C21 … the first then makes the quotation: exactly one quotation, and no claim left");
        if ($second === 'press') {
            $up();
            $r3 = qfu_c_press($c, $srv, $d, 31);
            $c->eq([$r3['flash'], $reqs()], [$FL['conditions'](31), []], 'C21 … a further press after the first returned is refused by the conditions (quote_created)');
        }
    }
    foreach ([901 => false, 600 => true] as $age => $blocks) {   // §R.9.1: 901 s does not block (the control), 600 s still does (the seed's own seconds would make 899 a race)
        foreach (['press', 'retry'] as $how) {
            $up();
            $d = $seed([$win(['quote_make_claim_ts' => time() - $age])]);
            $before = $app($d);
            $r = $how === 'press' ? qfu_c_press($c, $srv, $d, 31) : qfu_c_retry($c, $srv, $d, 31);
            if ($blocks) {
                $c->eq([$how === 'press' ? $r['flash'] : [$r['code'], $r['json']['message'] ?? null], count($posts()), $app($d) === $before],
                    [$how === 'press' ? $FL['claimed'](31) : [409, $CLAIM_T], 0, true], "C21 a claim seeded {$age} s old still blocks " . ($how === 'press' ? 'a press' : 'retry_quote') . ': claimed, nothing written, no POST');
            } else {
                $c->eq([count($posts()), $hasClaim($app($d))], [1, false], "C21 a claim seeded {$age} s old does not block " . ($how === 'press' ? 'a press' : 'retry_quote') . ' (900 s): quoted, and no claim left');
            }
        }
    }

    // ── C22 the claim's removal (F-9) ───────────────────────────────────────────────────────────────────────────
    $up();
    $d = $seed([$win()]);
    $r = qfu_c_press($c, $srv, $d, 31);
    $c->eq([$r['flash'], $hasClaim($app($d)), !empty($app($d)['quote_created'])], [$FL['made'](31), false, true], 'C22 the control: a press with no fault planted makes the quotation and leaves no claim');
    foreach (['press', 'retry'] as $how) {
        $up();
        $d = $seed([$win()]);
        $before = $app($d);
        $r = $how === 'press' ? qfu_c_press($c, $srv, $d, 31, [], ['faults_kyc' => ['links_load' => true]]) : qfu_c_retry($c, $srv, $d, 31, ['faults_kyc' => ['links_load' => true]]);
        $c->eq([$how === 'press' ? $r['flash'] : [$r['code'], $r['json']['message'] ?? null], count($posts()), $hasClaim($app($d)), $app($d) === $before],
            [$how === 'press' ? $FL['throwable'](31) : [500, $RQ_THROW], 0, false, true],
            'C22 a Throwable inside the method after the claim, ' . ($how === 'press' ? 'at the button: its Throwable flash' : 'at retry_quote: 500 with its fixed text') . ', and the claim is gone');
    }
    // 5.18.102 D14 review (D14-MQ-THROW-AFTER-POST): a Throwable AFTER postQuote's POST made the quotation is never
    // answered "No quotation was made". (a) makeQuotation's step-5 write fails: postQuote has stored quote_id,
    // quote_created and wa_quote_pending, so the answer is "made", and the log names the ids to restore.
    foreach (['press', 'retry'] as $how) {
        $up();
        $d = $seed([$win()]);
        $f = ['faults_kyc' => ['after_post' => 'quote_link_ids']];
        $r = $how === 'press' ? qfu_c_press($c, $srv, $d, 31, [], $f) : qfu_c_retry($c, $srv, $d, 31, $f);
        $rec = $app($d);
        $c->eq([$how === 'press' ? $r['flash'] : $r['code'], count($posts()), !empty($rec['quote_created']), (int)($rec['quote_id'] ?? 0), !empty($rec['wa_quote_pending']), $hasClaim($rec)],
            [$how === 'press' ? $FL['made'](31) : 200, 1, true, 77, true, false],
            'C22 a Throwable at makeQuotation\'s step-5 write, after the POST made quotation #77, ' . ($how === 'press' ? 'at the button: "made"' : 'at retry_quote: 200')
            . ' — never "no quotation"; one POST, quote_created, no claim left');
        $want = '[KycService] Make the quotation, application #31 (' . ($how === 'press' ? 'orders_card' : 'retry_quote')
              . '): quotation #77 made; its record (link ids 1,2,3) could not be written (RuntimeException)';
        $c->t(strpos($r['errlog'], $want) !== false && !preg_match('/Test Window|\+256|700 003|Tester|Test Agent|Test Admin/', $r['errlog']),
            'C22 … logged with ids only: the quotation and the link ids an admin restores', $r['errlog']);
    }
    // (b) a Throwable inside postQuote after its POST (its quote_id write): the answer never says no quotation exists,
    // and a second press finds the quotation in uCRM (R-M4's guard) — still one POST.
    foreach (['press', 'retry'] as $how) {
        $up();
        $d = $seed([$win()]);
        $f = ['faults_kyc' => ['after_post' => 'quote_id']];
        $r = $how === 'press' ? qfu_c_press($c, $srv, $d, 31, [], $f) : qfu_c_retry($c, $srv, $d, 31, $f);
        $said = $how === 'press' ? (string)$r['flash'] : (string)($r['json']['message'] ?? '');
        $c->eq([$how === 'press' ? $r['flash'] : [$r['code'], $said], count($posts()), strpos($said, 'No quotation was made') === 0, $hasClaim($app($d))],
            [$how === 'press' ? $FL['throwable'](31) : [500, $RQ_THROW], 1, false, false],
            'C22 a Throwable inside postQuote after its POST made the quotation, ' . ($how === 'press' ? 'at the button' : 'at retry_quote')
            . ': its Throwable text, which never begins "No quotation was made"; one POST, no claim left');
        // 5.18.102 D14 review round 4 (CK4-F1): the card under that text says the same — quote_unconfirmed_at recorded
        $c->eq([is_string($app($d)['quote_unconfirmed_at'] ?? null), $cardOf($d)], [true, $CARD(true, false, false, 1)],
            'C22 … quote_unconfirmed_at recorded, and the card says "Making the last quotation did not finish", never "No quotation was made"; the button stays');
        $r2 = qfu_c_press($c, $srv, $d, 31);
        $c->eq([$r2['flash'], count($posts())], [$FL['found'](31), 1],
            'C22 … then a second press (after ' . ($how === 'press' ? 'the button' : 'retry_quote') . ') finds the quotation in uCRM: no second one, still one POST');
    }
    // 5.18.102 D14 review round 5: (b) again — (CK5-T4) an \Error, not an \Exception, after the POST; (CK5-T5) the catch's own
    // key write failing too: logged with ids only, and postQuote's Throwable is what goes on
    $up();
    $d = $seed([$win()]);
    $r = qfu_c_press($c, $srv, $d, 31, [], ['faults_kyc' => ['after_post_error' => 'quote_id']]);
    $c->eq([$r['flash'], count($posts()), is_string($app($d)['quote_unconfirmed_at'] ?? null), $cardOf($d), $hasClaim($app($d))],
        [$FL['throwable'](31), 1, true, $CARD(true, false, false, 1), false],
        'C22 (b) an \\Error (not an \\Exception) inside postQuote after its POST: its Throwable flash, quote_unconfirmed_at recorded, the card says "did not finish"; one POST, no claim left');
    $r2 = qfu_c_press($c, $srv, $d, 31);
    $c->eq([$r2['flash'], count($posts())], [$FL['found'](31), 1], 'C22 (b) … after the \\Error a second press finds the quotation: still one POST');
    foreach (['press', 'retry'] as $how) {
        $up();
        $d = $seed([$win()]);
        $f = ['faults_kyc' => ['after_post' => ['quote_id', 'quote_unconfirmed_at']]];
        $r = $how === 'press' ? qfu_c_press($c, $srv, $d, 31, [], $f) : qfu_c_retry($c, $srv, $d, 31, $f);
        $want = '[KycService] Make the quotation, application #31 (' . ($how === 'press' ? 'orders_card' : 'retry_quote') . '): quote_unconfirmed_at could not be recorded (RuntimeException)';
        $c->eq([$how === 'press' ? $r['flash'] : [$r['code'], $r['json']['message'] ?? null], count($posts()), array_key_exists('quote_unconfirmed_at', $app($d)), $hasClaim($app($d))],
            [$how === 'press' ? $FL['throwable'](31) : [500, $RQ_THROW], 1, false, false],
            'C22 (b) a Throwable inside postQuote after its POST and the catch\'s own key write failing too, ' . ($how === 'press' ? 'at the button' : 'at retry_quote')
            . ': postQuote\'s Throwable goes on; one POST, no key, no claim left');
        $c->t(strpos($r['errlog'], $want) !== false && !preg_match('/Test Window|\+256|700 003|Tester|Test Agent|Test Admin/', $r['errlog']),
            'C22 (b) … the failed key write is logged, ids only (' . ($how === 'press' ? 'at the button' : 'at retry_quote') . ')', $r['errlog']);
    }
    // 5.18.102 D14 review round 6 (CK6-FT2): postQuote's \Error, then the key write's RuntimeException — the caller's line
    // names the \Error, the Throwable that went on, never the write's
    foreach (['press', 'retry'] as $how) {
        $up();
        $d = $seed([$win()]);
        $f = ['faults_kyc' => ['after_post_error' => 'quote_id', 'after_post' => 'quote_unconfirmed_at']];
        $r = $how === 'press' ? qfu_c_press($c, $srv, $d, 31, [], $f) : qfu_c_retry($c, $srv, $d, 31, $f);
        $tail = ': making the quotation did not finish, it may have been made (';
        $c->eq([$how === 'press' ? $r['flash'] : [$r['code'], $r['json']['message'] ?? null], count($posts()), strpos($r['errlog'], $tail . 'Error)') !== false,
                strpos($r['errlog'], $tail . 'RuntimeException)') !== false, strpos($r['errlog'], 'quote_unconfirmed_at could not be recorded (RuntimeException)') !== false],
            [$how === 'press' ? $FL['throwable'](31) : [500, $RQ_THROW], 1, true, false, true],
            'C22 (b) … the caller names postQuote\'s Throwable (an \\Error), never the failed key write\'s, ' . ($how === 'press' ? 'at the button' : 'at retry_quote'));
    }
    // (c) 5.18.102 D14 review round 2 (FX-flows-2): uCRM makes the quotation and its answer is lost (the proxy's 504, as a
    // 30 s timeout or a gateway in front of uCRM would give): the 'error' answer says "did not confirm … it may have been
    // made", never "did not make", no claim is left, and a second press finds the quotation (R-M4) — still one POST.
    foreach (['press', 'retry'] as $how) {
        $up();
        qfu_c_ctl($srv, 'post_lost', true);
        $d = $seed([$win()]);
        $k0 = $cardOf($d);   // 5.18.102 D14 review round 3 (CK-flows-2): the control — before the press the card says none was made
        $r = $how === 'press' ? qfu_c_press($c, $srv, $d, 31) : qfu_c_retry($c, $srv, $d, 31);
        qfu_c_ctl($srv, 'post_lost', false);
        $said = $how === 'press' ? (string)$r['flash'] : (string)($r['json']['message'] ?? '');
        $c->eq([$how === 'press' ? $said : [$r['code'], $said], count($posts()), preg_match('/did not make|No quotation was made/', $said), $hasClaim($app($d))],
            [$how === 'press' ? $FL['error'](31) : [500, $ERR_T], 1, 0, false],
            'C22 uCRM made the quotation and its answer was lost (504), ' . ($how === 'press' ? 'at the button' : 'at retry_quote')
            . ': "did not confirm … it may have been made", never "did not make"; one POST, no claim left');
        $c->eq([$k0, $cardOf($d)], [$CARD(false, true, false, 1), $CARD(true, false, false, 1)],
            'C22 … the Orders card ' . ($how === 'press' ? 'the flash lands on' : 'after retry_quote') . ' says "Making the last quotation did not finish", never'
            . ' "No quotation was made", and still draws Make the quotation (the control: before the press it said "No quotation was made")');
        $r2 = qfu_c_press($c, $srv, $d, 31);
        $c->eq([$r2['flash'], count($posts())], [$FL['found'](31), 1],
            'C22 … then a second press (after ' . ($how === 'press' ? 'the button' : 'retry_quote') . ') finds the quotation the lost answer made: no second one, still one POST');
        $c->eq([$cardOf($d), array_key_exists('quote_unconfirmed_at', $app($d))], [$CARD(false, false, true, 0), true],
            'C22 … and the card then draws the quotation found, not the "did not finish" box, and no button; the found answer records quote_found_in_ucrm and nothing else (the key stays, outranked)');
    }
    // 5.18.102 D14 review round 5 (CK5-B1): the 'error' answer's own key write failing — logged with ids only, and the answer
    // stays 'error' ("did not confirm … it may have been made"), never the Throwable's; a second press finds the quotation
    // Round 6 (CK6-FT3): with the write failing by an \Exception and by an \Error
    foreach (['press', 'retry'] as $how) foreach (['after_post' => 'RuntimeException', 'after_post_error' => 'Error'] as $fk => $cls) {
        $up();
        qfu_c_ctl($srv, 'post_lost', true);
        $d = $seed([$win()]);
        $f = ['faults_kyc' => [$fk => 'quote_unconfirmed_at']];
        $r = $how === 'press' ? qfu_c_press($c, $srv, $d, 31, [], $f) : qfu_c_retry($c, $srv, $d, 31, $f);
        qfu_c_ctl($srv, 'post_lost', false);
        $want = '[KycService] Make the quotation, application #31 (' . ($how === 'press' ? 'orders_card' : 'retry_quote') . '): quote_unconfirmed_at could not be recorded (' . $cls . ')';
        $c->eq([$how === 'press' ? $r['flash'] : [$r['code'], $r['json']['message'] ?? null], count($posts()), strpos($r['errlog'], $want) !== false, $hasClaim($app($d))],
            [$how === 'press' ? $FL['error'](31) : [500, $ERR_T], 1, true, false],
            'C22 (c) the lost answer\'s own key write failing (' . $cls . '), ' . ($how === 'press' ? 'at the button' : 'at retry_quote') . ': still the "error" answer, the failed write logged; one POST, no claim left');
        $c->t(!preg_match('/Test Window|\+256|700 003|Tester|Test Agent|Test Admin/', $r['errlog']), 'C22 (c) … that log carries ids only', $r['errlog']);
        $r2 = qfu_c_press($c, $srv, $d, 31);
        $c->eq([$r2['flash'], count($posts())], [$FL['found'](31), 1], 'C22 (c) … after the failed key write a second press finds the quotation: no second one, still one POST');
    }
    // (d) 5.18.102 D14 review round 3 (CK-flows-2): uCRM refused the POST and made nothing ('error'): the card says "did not
    // confirm" as in (c). A press that cannot read uCRM's quotations keeps that (nothing more is known); a press whose guard
    // finds none removes it — and the quotation is made, or the card says why none was, from that press's own refusal.
    $up(['refuse_quotes' => true]);
    $d = $seed([$win()]);
    $r = qfu_c_press($c, $srv, $d, 31);
    $e1 = $app($d);
    $c->eq([$r['flash'], is_string($e1['quote_unconfirmed_at'] ?? null), $cardOf($d)], [$FL['error'](31), true, $CARD(true, false, false, 1)],
        'C22 (d) uCRM refused the POST (no id): the "error" flash, quote_unconfirmed_at recorded, and the card says "did not finish" with Make the quotation');
    qfu_c_ctl($srv, 'list_down', true);
    $r = qfu_c_press($c, $srv, $d, 31);
    qfu_c_ctl($srv, 'list_down', false);
    $c->eq([$r['flash'], $app($d)['quote_unconfirmed_at'] ?? null, $cardOf($d)], [$FL['unreadable'](31), $e1['quote_unconfirmed_at'] ?? 'unset', $CARD(true, false, false, 1)],
        'C22 (d) … a press that cannot read uCRM\'s quotations keeps quote_unconfirmed_at as it was, and the card still says "did not finish"');
    $up();
    $r = qfu_c_press($c, $srv, $d, 31);
    $e3 = $app($d);
    $c->eq([$r['flash'], array_key_exists('quote_unconfirmed_at', $e3), !empty($e3['quote_created']), $cardOf($d)], [$FL['made'](31), false, true, $CARD(false, false, false, 0)],
        'C22 (d) … a press whose guard finds no quotation removes quote_unconfirmed_at (the key, not a null) and makes it: the card draws neither box');
    $up();
    $d = $seed([$win(['quote_unconfirmed_at' => '2026-10-30 09:00:00'])], ['links' => [qfu_link(1, 'plan', 1, QFU_PLAN_STAMP, 12, 6), qfu_link(3, 'install', 0, '', 70)]]);
    $k0 = $cardOf($d);
    $r = qfu_c_press($c, $srv, $d, 31);
    $e4 = $app($d);
    $c->eq([$k0, $r['flash'], array_key_exists('quote_unconfirmed_at', $e4), strpos((string)($e4['quote_refused_text'] ?? ''), $P1U($UNL_KIT)) !== false, $cardOf($d), count($posts())],
        [$CARD(true, false, false, 1), $FL['refused'](31), false, true, $CARD(false, true, false, 1), 0],
        'C22 (d) … with the kit unlinked since, a press whose guard finds none removes it and records its own refusal: the card says why no quotation was made (the control: before the press it said "did not finish"); no POST');
    // 5.18.102 D14 review round 4 (CK4-F4, CK4-F2, CK4-F3)
    $UNC = ['quote_unconfirmed_at' => '2026-10-30 09:00:00'];
    $up();
    $d = $seed([$win($UNC)]);
    $ha = $cardHtml($d); $hg = $cardHtml($d, false);
    $c->eq([$cardOf($d, false), strpos($ha, 'Do not make one by hand in uCRM: Make the quotation looks there first and never makes a second one.') !== false, strpos($hg, 'Make the quotation looks there first and never makes a second one.') !== false, strpos($hg, 'Do not make one by hand in uCRM before an admin has looked there for it.') !== false],
        [$CARD(true, false, false, 0), true, false, true],
        'C22 (d) … an agent\'s card says "did not finish" too (never "No quotation was made"), with no button and a sentence that names none; an admin\'s names Make the quotation');
    $d = $seed([$win($UNC + ['quote_created' => true, 'quote_id' => 77])]);
    $c->eq($cardOf($d), $CARD(false, false, false, 0), 'C22 (d) … quote_unconfirmed_at left beside quote_created (the quotation was made): no box, no button');
    $d = $seed([$win($UNC), $win(['id' => 32, 'crm_client_id' => '902', 'quote_unconfirmed_at' => '2026-10-30 08:00:00'])]);
    $r = qfu_c_press($c, $srv, $d, 31);
    $c->eq([$r['flash'], array_key_exists('quote_unconfirmed_at', $app($d, 31)), $app($d, 32)['quote_unconfirmed_at'] ?? null], [$FL['made'](31), false, '2026-10-30 08:00:00'],
        'C22 (d) … a press on #31 whose guard finds none removes #31\'s key only: #32 keeps its own');
    // 5.18.102 D14 review round 5 (CK5-T1): the key held from an earlier press, then a press whose answer goes unconfirmed
    // again — the guard removes it and the answer records it afresh, so it is never lost
    foreach (['its answer lost again' => ['post_lost', []], 'a Throwable after its POST' => [null, ['faults_kyc' => ['after_post' => 'quote_id']]]] as $what => [$ctl, $f]) {
        $up();
        $d = $seed([$win($UNC)]);
        if ($ctl !== null) qfu_c_ctl($srv, $ctl, true);
        $r = qfu_c_press($c, $srv, $d, 31, [], $f);
        if ($ctl !== null) qfu_c_ctl($srv, $ctl, false);
        $e = $app($d);
        $c->eq([$r['flash'], count($posts()), is_string($e['quote_unconfirmed_at'] ?? null) && $e['quote_unconfirmed_at'] !== $UNC['quote_unconfirmed_at'], $cardOf($d)],
            [$ctl !== null ? $FL['error'](31) : $FL['throwable'](31), 1, true, $CARD(true, false, false, 1)],
            "C22 (d) … the key held from an earlier press, then {$what}: recorded afresh (a new time), so never lost — the card still says \"did not finish\"; one POST");
        $r2 = qfu_c_press($c, $srv, $d, 31);
        $c->eq([$r2['flash'], count($posts())], [$FL['found'](31), 1], "C22 (d) … {$what}: a second press finds the quotation, still one POST");
    }
    // 5.18.102 D14 review round 5 (CK5-B3): the key's removal throwing (the press's second lock) stops the press — never a fresh
    // refusal recorded under a box that hides it
    $up();
    $d = $seed([$win($UNC)], ['links' => [qfu_link(1, 'plan', 1, QFU_PLAN_STAMP, 12, 6), qfu_link(3, 'install', 0, '', 70)]]);
    $b = $app($d);
    $r = qfu_c_press($c, $srv, $d, 31, [], ['faults_kyc' => ['second_lock' => true]]);
    $e = $app($d);
    $c->eq([$r['flash'], count($posts()), $e['quote_unconfirmed_at'] ?? null, $e['quote_refused_text'] ?? null, $e['quote_refused_at'] ?? null, $hasClaim($e)],
        [$FL['throwable'](31), 0, $UNC['quote_unconfirmed_at'], $b['quote_refused_text'] ?? 'unset', $b['quote_refused_at'] ?? 'unset', false],
        'C22 (d) … the key\'s removal throwing: the press stops with its Throwable flash — no POST, the key and the earlier refusal as they were, no claim left');
    $up(['refuse_quotes' => true]);
    $w = $win(['quote_error' => '{"http_code":503}']); unset($w['quote_refused'], $w['quote_refused_text'], $w['quote_refused_at']);
    $d = $seed([$w]);
    $k0 = $cardOf($d);
    $r = qfu_c_retry($c, $srv, $d, 31);
    $h = $cardHtml($d);
    $c->eq([$k0, [$r['code'], $r['json']['message'] ?? null], $cardOf($d), strpos($h, 'Do not make one by hand in uCRM before an admin has looked there for it.') !== false, strpos($h, 'Make the quotation looks there first and never makes a second one.') !== false],
        [$CARD(false, false, false, 0), [500, $ERR_T], $CARD(true, false, false, 0), true, false],
        'C22 (d) … retry_quote\'s \'error\' on an application Make the quotation does not apply to (no refusal recorded): the box, no button, and a sentence that names none (the control: no box before)');
    // a stale read: retry_quote reads the application, then waits at its company check while another request records
    // quote_unconfirmed_at; its guard finds none and its lines refuse (Cash, 1,600 collected, the lines total 1,800): P2
    foreach (['written while retry_quote waited' => true, 'the control: there when retry_quote read it' => false] as $what => $stale) {
        $up();
        $d = $seed([$win(['sales_type' => 'Cash', 'amount_charged' => 1600, 'is_lead' => false] + ($stale ? [] : $UNC))]);
        $held = null;
        if ($stale) {
            qfu_c_ctl($srv, 'hold_client', true);
            $a = qfu_c_start(qfu_c_retry($c, $srv, $d, 31, ['async' => true]));
            $held = qfu_c_until($a, function () use ($srv) { return is_file($srv['ctl'] . '/arrived_client'); });
            qfu_c_put($d, $UNC + $app($d));
            qfu_c_ctl($srv, 'hold_client', false);
            $r = qfu_c_finish($a);
        } else {
            $r = qfu_c_retry($c, $srv, $d, 31);
        }
        $e = $app($d);
        $c->eq([$held, $r['code'], $e['quote_refused'] ?? null, count($posts()), array_key_exists('quote_unconfirmed_at', $e), $cardOf($d)],
            [$stale ? true : null, 409, 'P2', 0, false, $CARD(false, true, false, 0)],
            "C22 (d) … quote_unconfirmed_at {$what}: the guard finds none, so the key is removed whatever the read held; the card says why (P2), not \"did not finish\"");
    }
    // (e) 5.18.102 D14 review round 6 (CK6-F1): while a press is in flight (its claim live, its POST held at the proxy) the
    // card never goes back to the stored refusal, whose P1 text may say to make the quotation in uCRM: it says the press has
    // not finished, to an admin and an agent alike, with or without a key from an earlier press; after the press it shows
    // what the press did. The controls: a claim inside QUOTE_CLAIM_SECONDS draws the box, one past it the stored refusal.
    foreach (['holding the key from an earlier press' => $UNC, 'with no key' => []] as $what => $k0) {
        $up();
        $d = $seed([$win($k0)]);
        qfu_c_ctl($srv, 'hold', true);
        $first = qfu_c_start(qfu_c_press($c, $srv, $d, 31, [], ['async' => true]));
        $held = qfu_c_until($first, fn() => is_file($srv['ctl'] . '/arrived'));
        $ka = $cardOf($d); $kg = $cardOf($d, false);
        qfu_c_ctl($srv, 'hold', false);
        $r1 = qfu_c_finish($first);
        $c->eq([$held, $ka, $kg], [true, $CARD(false, false, false, 1, true), $CARD(false, false, false, 0, true)],
            "C22 (e) a press in flight, {$what}: the admin's and the agent's cards say making a quotation has not finished — never \"No quotation was made\" or \"did not finish\"");
        $c->eq([$r1['flash'], count($posts()), $cardOf($d)], [$FL['made'](31), 1, $CARD(false, false, false, 0)],
            "C22 (e) … {$what}: then the press makes the quotation, and the card draws no box and no button");
    }
    foreach ([600 => true, 901 => false] as $age => $inflight) {
        $up();
        $d = $seed([$win(['quote_make_claim_ts' => time() - $age])]);
        $c->eq($cardOf($d), $inflight ? $CARD(false, false, false, 1, true) : $CARD(true, false, false, 1),
            "C22 (e) … a claim {$age} s old: " . ($inflight ? 'the card says making a quotation has not finished'
                : 'past QUOTE_CLAIM_SECONDS a claim still there is a request that died: "did not finish", never the stored refusal (round 7, CK7-F1)'));
    }
    // Round 7 (CK7-T5): just inside the age, the card and the method agree — the box, and a press answered "claimed"
    $up();
    $d = $seed([$win(['quote_make_claim_ts' => time() - 870])]);
    $k870 = $cardOf($d);
    $r = qfu_c_press($c, $srv, $d, 31);
    $c->eq([$k870, $r['flash'], count($posts())], [$CARD(false, false, false, 1, true), $FL['claimed'](31), 0],
        'C22 (e) … a claim 870 s old (QUOTE_CLAIM_SECONDS − 30): the card says making a quotation has not finished, and a press is answered "claimed" with no POST');
    // Round 7 (CK7-T4, T6, T7): the box's place — ahead of the key's box, behind the found line, never beside a made quotation
    $d = $seed([$win($UNC + ['quote_make_claim_ts' => time() - 600])]);
    $c->eq([$cardOf($d), $cardOf($d, false)], [$CARD(false, false, false, 1, true), $CARD(false, false, false, 0, true)],
        'C22 (e) … the key from an earlier press and a live claim: the cards say making a quotation has not finished, not "did not finish"');
    $d = $seed([$win(['quote_found_in_ucrm' => ['number' => 'Q-5', 'at' => '2026-10-30 10:00:00'], 'quote_make_claim_ts' => time() - 10])]);
    $c->eq($cardOf($d), $CARD(false, false, true, 0), 'C22 (e) … a quotation found and a live claim: the found line, and no in-flight box');
    $d = $seed([$win(['quote_created' => true, 'quote_id' => 77, 'quote_make_claim_ts' => time() - 10])]);
    $c->eq($cardOf($d), $CARD(false, false, false, 0), 'C22 (e) … a quotation made and a live claim: no box at all');
    $ADM8 = 'Do not make one by hand in uCRM: Make the quotation looks there first and never makes a second one.';
    $AGT8 = 'Do not make one by hand in uCRM before an admin has looked there for it.';
    // Round 9 (CK9-T3, CK9-T4): the box's second sentence and the refusal box's text, each whole — never a substring
    // round 10 (CK10-T3): each read to the box's own close, so a line added inside the box after it reads <none>
    // round 11 (CK11-T4): each read from the box's opening to its close
    $sent2 = fn(string $h): string => preg_match($boxRe($UNCONF_H, 'It may have been made in uCRM\. ([^<]*)'), $h, $m) ? html_entity_decode($m[1], ENT_QUOTES) : '<none>';
    $refT  = fn(string $h): string => preg_match($boxRe('⚠ No quotation was made', '([^<]*)'), $h, $m) ? html_entity_decode($m[1], ENT_QUOTES) : '<none>';
    // a press that died after its POST (uCRM holds its quotation #77): its claim left, no key
    $died = function () use ($up, $seed, $win, $c, $srv): string {
        $up();
        $d = $seed([$win()]);
        qfu_c_press($c, $srv, $d, 31, [], ['faults_kyc' => ['fatal_after_post' => 'quote_id']]);
        return $d;
    };
    // (f) 5.18.102 D14 review round 7 (CK7-F1): a press that DIES after its POST (memory exhausted at postQuote's quote_id
    // write: no catch and no finally run) leaves its claim and no key. While the claim is young the card says making a
    // quotation has not finished; past QUOTE_CLAIM_SECONDS, "did not finish" — never "No quotation was made"; then a
    // press finds the quotation (R-M4), still one POST
    foreach (['with no key' => [], 'holding the key from an earlier press' => $UNC] as $what => $k0) {
        $up();
        $d = $seed([$win($k0)]);
        $r = qfu_c_press($c, $srv, $d, 31, [], ['faults_kyc' => ['fatal_after_post' => 'quote_id']]);
        $e = $app($d);
        $c->eq([(bool)preg_match('/Allowed memory size/', $r['errlog']), count($posts()), $hasClaim($e), array_key_exists('quote_unconfirmed_at', $e), !empty($e['quote_created']), $cardOf($d), $cardOf($d, false)],
            [true, 1, true, false, false, $CARD(false, false, false, 1, true), $CARD(false, false, false, 0, true)],
            "C22 (f) a press that dies after its POST, {$what}: its claim left and no key; the cards say making a quotation has not finished");
        qfu_c_put($d, ['quote_make_claim_ts' => time() - 901] + $e);
        $c->eq([$cardOf($d), $cardOf($d, false)], [$CARD(true, false, false, 1), $CARD(true, false, false, 0)],
            "C22 (f) … {$what}: its claim past QUOTE_CLAIM_SECONDS, the cards say \"did not finish\" — never \"No quotation was made\"");
        // Round 8 (CK8-F3): each card's second sentence, whole
        $ha = $cardHtml($d); $hg = $cardHtml($d, false);
        $c->eq([$sent2($ha), $sent2($hg)], [$ADM8, $AGT8],   // round 9 (CK9-T4): whole, so nothing may be added to either
            "C22 (f) … {$what}: the admin's card names Make the quotation, the agent's names none (each sentence whole)");
        $r2 = qfu_c_press($c, $srv, $d, 31);
        $c->eq([$r2['flash'], count($posts())], [$FL['found'](31), 1], "C22 (f) … {$what}: a press then finds the quotation, still one POST");
    }
    // Round 8 (CK8-F1): the claim a press that died left is the only marker; a press that cannot settle it never loses it —
    // the expired claim becomes the key when the next press takes it over, and only a guard that finds none removes the key
    // Round 9 (CK9-T5, CK9-T6): a claim long past QUOTE_CLAIM_SECONDS as well, and a press whose every updateOne carrying the
    // key fails — the claim becomes the key under the claim's own lock, never by a later write that may fail
    foreach ([901, 7200] as $age) {
        foreach (['cannot read uCRM\'s quotations' => ['list_down', [], $FL['unreadable'](31)],
                  'cannot read uCRM\'s quotations, every write of the key by updateOne failing' => ['list_down', ['faults_kyc' => ['after_post' => 'quote_unconfirmed_at']], $FL['unreadable'](31)],
                  'finds the quotation and its found write throws' => [null, ['faults_kyc' => ['after_post' => 'quote_found_in_ucrm']], $FL['throwable'](31)]] as $what => [$ctl, $f, $fl]) {
            if ($age !== 901) $what .= " (its claim {$age} s old)";
            $up();
            $d = $seed([$win()]);
            qfu_c_press($c, $srv, $d, 31, [], ['faults_kyc' => ['fatal_after_post' => 'quote_id']]);
            // the registration's refusal stays before the claim, as it always is (10 minutes before it, whatever its age)
            qfu_c_put($d, ['quote_make_claim_ts' => time() - $age, 'quote_refused_ts' => time() - $age - 600,
                'quote_refused_at' => date('Y-m-d H:i:s', time() - $age - 600)] + $app($d));
            if ($ctl !== null) qfu_c_ctl($srv, $ctl, true);
            $r = qfu_c_press($c, $srv, $d, 31, [], $f);
            if ($ctl !== null) qfu_c_ctl($srv, $ctl, false);
            $e = $app($d);
            $c->eq([$r['flash'], count($posts()), is_string($e['quote_unconfirmed_at'] ?? null), $hasClaim($e), $cardOf($d), $cardOf($d, false)],
                [$fl, 1, true, false, $CARD(true, false, false, 1), $CARD(true, false, false, 0)],
                "C22 (f) … after a press that died, a press that {$what}: the expired claim became the key, so the cards still say \"did not finish\"; one POST");
            $r2 = qfu_c_press($c, $srv, $d, 31);
            $c->eq([$r2['flash'], count($posts())], [$FL['found'](31), 1], "C22 (f) … then a press that can read finds the quotation, still one POST ({$what})");
        }
    }
    // Round 8 (CK8-F2): an expired claim that a refusal followed — a refusal whose claim removal failed: the refusal holds,
    // and a press that cannot settle it does not turn the claim into the key; the control, the refusal recorded before it
    $P2T = 'No quotation was made: the customer paid UGX 1,600 at registration — make the quotation in uCRM for that amount.';
    // Round 9 (CK9-T2, CK9-T3): every kind of refusal a press records, not P2 alone — P1 (the lines) and R2 (the company
    // check) — judged by quote_refused_ts (CK9-B1), and the refusal's own text, whole, on both cards
    $R2T = 'No quotation was made: client #901 is filed under another company in uCRM — move it to the quoting company, then retry.';
    $REF9 = ['P2' => [['sales_type' => 'Cash', 'amount_charged' => 1600, 'is_lead' => false], $P2T, 0],
             'P1' => [[], 'The customer is in uCRM as #901. ' . $P1U($UNL_KIT) . ' Do not register this customer again.', 1],
             'R2' => [[], $R2T, 0]];
    foreach ($REF9 as $code => [$over, $txt, $btn]) {
        foreach (['after' => true, 'before' => false] as $when => $holds) {
            $up();
            $cl = time() - 901;
            $at = $holds ? $cl : $cl - 3600;
            $d = $seed([$win($over + ['quote_refused' => $code, 'quote_refused_text' => $txt,
                'quote_refused_at' => date('Y-m-d H:i:s', $at), 'quote_refused_ts' => $at, 'quote_make_claim_ts' => $cl])]);
            $c->eq([$cardOf($d), $cardOf($d, false)], $holds ? [$CARD(false, true, false, $btn), $CARD(false, true, false, 0)] : [$CARD(true, false, false, $btn), $CARD(true, false, false, 0)],
                "C22 (f) … an expired claim, a {$code} refusal recorded {$when} it: " . ($holds ? 'the refusal holds' : '"did not finish"'));
            if ($holds) {
                $c->eq([$refT($cardHtml($d)), $refT($cardHtml($d, false))], [$txt, $txt],
                    "C22 (f) … an expired claim, a {$code} refusal recorded after it: its text on both cards, whole");
            }
            qfu_c_ctl($srv, 'list_down', true);
            $r = qfu_c_retry($c, $srv, $d, 31);
            qfu_c_ctl($srv, 'list_down', false);
            $e = $app($d);
            $c->eq([[$r['code'], $r['json']['message'] ?? null], array_key_exists('quote_unconfirmed_at', $e), $hasClaim($e), $cardOf($d)],
                [[409, $UNREAD_T], !$holds, false, $holds ? $CARD(false, true, false, $btn) : $CARD(true, false, false, $btn)],
                "C22 (f) … {$code} {$when}: retry_quote cannot read uCRM's quotations — " . ($holds ? 'the claim is not turned into the key, and the refusal still holds' : 'the claim became the key, and the cards still say "did not finish"'));
        }
    }
    // Round 8 (CK8-F3): retry_quote dies after its POST on an application Make the quotation does not apply to (no refusal
    // recorded): past QUOTE_CLAIM_SECONDS both cards say "did not finish", with the sentence that names no button
    $up();
    $w8 = $win(['quote_error' => '{"http_code":503}']); unset($w8['quote_refused'], $w8['quote_refused_text'], $w8['quote_refused_at']);
    $d = $seed([$w8]);
    $r = qfu_c_retry($c, $srv, $d, 31, ['faults_kyc' => ['fatal_after_post' => 'quote_id']]);
    qfu_c_put($d, ['quote_make_claim_ts' => time() - 901] + $app($d));
    $ha = $cardHtml($d); $hg = $cardHtml($d, false);
    $c->eq([count($posts()), $cardOf($d), $cardOf($d, false), $sent2($ha), $sent2($hg)],
        [1, $CARD(true, false, false, 0), $CARD(true, false, false, 0), $AGT8, $AGT8],
        'C22 (f) … retry_quote that died after its POST on an application the button does not apply to: past QUOTE_CLAIM_SECONDS both cards say "did not finish", with the sentence that names no button');
    // Round 9 (CK9-T7): then a retry_quote that cannot read uCRM's quotations — the claim, on an application with no refusal
    // recorded at all, becomes the key
    qfu_c_ctl($srv, 'list_down', true);
    $r = qfu_c_retry($c, $srv, $d, 31);
    qfu_c_ctl($srv, 'list_down', false);
    $e = $app($d);
    $c->eq([[$r['code'], $r['json']['message'] ?? null], is_string($e['quote_unconfirmed_at'] ?? null), $hasClaim($e), count($posts()), $cardOf($d), $cardOf($d, false)],
        [[409, $UNREAD_T], true, false, 1, $CARD(true, false, false, 0), $CARD(true, false, false, 0)],
        'C22 (f) … then a retry_quote that cannot read uCRM\'s quotations: the claim became the key, both cards still say "did not finish"; one POST');
    // (g) 5.18.102 D14 review round 9 (CK9-B1, CK9-T1): a press that died after its POST, its claim expired, and a refusal
    // recorded BEFORE the claim — just before, days before (the registration's), or 30 minutes before with its local time
    // written an hour ahead of the reader's zone (Africa/Kampala read in Africa/Juba). Judged by quote_refused_ts, the cards
    // say "did not finish"; a press that cannot read keeps the key; then a press finds the quotation; one POST
    foreach (['1 s before it' => [1, 0], 'two days before it (the registration\'s)' => [2 * 86400, 0],
              '400 days before it (round 10, CK10-T4)' => [400 * 86400, 0],
              '30 min before it, its local time written an hour ahead of the reader\'s zone' => [1800, 3600]] as $what => [$gap, $shift]) {
        $d = $died();
        $cl = time() - 901;
        qfu_c_put($d, ['quote_make_claim_ts' => $cl, 'quote_refused_ts' => $cl - $gap, 'quote_refused_at' => date('Y-m-d H:i:s', $cl - $gap + $shift)] + $app($d));
        $c->eq([$cardOf($d), $cardOf($d, false)], [$CARD(true, false, false, 1), $CARD(true, false, false, 0)],
            "C22 (g) a press that died after its POST, its claim expired, a refusal recorded {$what}: the cards say \"did not finish\"");
        qfu_c_ctl($srv, 'list_down', true);
        $r = qfu_c_press($c, $srv, $d, 31);
        qfu_c_ctl($srv, 'list_down', false);
        $e = $app($d);
        $c->eq([$r['flash'], count($posts()), is_string($e['quote_unconfirmed_at'] ?? null), $cardOf($d), $cardOf($d, false)],
            [$FL['unreadable'](31), 1, true, $CARD(true, false, false, 1), $CARD(true, false, false, 0)],
            "C22 (g) … {$what}: a press that cannot read uCRM's quotations keeps the key; one POST");
        $r2 = qfu_c_press($c, $srv, $d, 31);
        $c->eq([$r2['flash'], count($posts())], [$FL['found'](31), 1], "C22 (g) … {$what}: then a press finds the quotation, still one POST");
    }
    // Round 10 (CK10-B1): a refusal stamped at or after the moment of the next claim — another request's in the claim's own
    // second, or a clock that stepped back — and a press that then dies after its POST: its claim is stamped after the
    // refusal, so with both aged the cards say "did not finish", never the refusal's "make it in uCRM"
    // Round 11 (CK11-T5, T6, T7): from the start of a second, so the press falls in the refusal's own second; the refusal's
    // local time written an hour behind its epoch (which alone decides); and the cards read before any ageing
    foreach (['in the claim\'s own second' => 0, '5 s ahead of the clock' => 5] as $what => $ahead) {
        $up();
        $d = $seed([$win()]);
        usleep(1000000 - (int)round(fmod(microtime(true), 1.0) * 1000000) + 2000);
        $rt = time() + $ahead;
        qfu_c_put($d, ['quote_refused_ts' => $rt, 'quote_refused_at' => date('Y-m-d H:i:s', $rt - 3600)] + $app($d));
        qfu_c_press($c, $srv, $d, 31, [], ['faults_kyc' => ['fatal_after_post' => 'quote_id']]);
        $e = $app($d);
        $cl = $e['quote_make_claim_ts'] ?? null;
        $c->eq([count($posts()), $cl], [1, $rt + 1],
            "C22 (g) a refusal recorded {$what}, then a press that dies after its POST: its claim is stamped one second after the refusal");
        $c->eq([$cardOf($d), $cardOf($d, false)], [$CARD(false, false, false, 1, true), $CARD(false, false, false, 0, true)],
            "C22 (g) … a refusal recorded {$what}: right after the press died, its claim stamped ahead, the cards say making a quotation has not finished");
        $sh = (int)$cl - (time() - 901);   // both moved back together until the claim is 901 s old
        qfu_c_put($d, ['quote_make_claim_ts' => (int)$cl - $sh, 'quote_refused_ts' => $rt - $sh] + $e);
        $c->eq([$cardOf($d), $cardOf($d, false)], [$CARD(true, false, false, 1), $CARD(true, false, false, 0)],
            "C22 (g) … a refusal recorded {$what}: with the claim and the refusal aged together, the cards say \"did not finish\"");
    }
    // Round 11 (CK11-B1): a refusal epoch far ahead (ten years) or corrupt (PHP_INT_MAX) never holds a claim back — a press
    // posts, its claim stamped now — and never settles one: the press died after its POST, its claim 901 s old, "did not finish"
    foreach (['ten years ahead' => time() + 10 * 365 * 86400, 'of PHP_INT_MAX' => PHP_INT_MAX] as $what => $far) {
        $up();
        $d = $seed([$win(['quote_refused_ts' => $far])]);
        $t0 = time();
        qfu_c_press($c, $srv, $d, 31, [], ['faults_kyc' => ['fatal_after_post' => 'quote_id']]);
        $e = $app($d);
        $cl = $e['quote_make_claim_ts'] ?? null;
        $c->eq([count($posts()), is_int($cl) && $cl >= $t0 && $cl <= time()], [1, true],
            "C22 (g) a refusal epoch {$what}: a press is not held back by it — it posts, its claim stamped now");
        qfu_c_put($d, ['quote_make_claim_ts' => (int)$cl - 901] + $e);
        $c->eq([$cardOf($d), $cardOf($d, false)], [$CARD(true, false, false, 1), $CARD(true, false, false, 0)],
            "C22 (g) … a refusal epoch {$what}: the press died after its POST, its claim 901 s old — the cards say \"did not finish\"");
    }
    // Round 11 (CK11-B2, CK11-T3): a refusal recorded 30 s ahead, then a press that refuses — its lines (P1) or the company
    // check (R1). Its claim's removal working: no claim is left, the refusal is drawn, and the next press is not answered
    // claimed. Its claim's removal failing (the third lock): its own refusal is stamped at or after its claim, so with both
    // aged the refusal holds
    foreach (['whose lines are refused' => [['catalogue' => false], 'uganda', 'P1', 1],
              'the company check refuses' => [['clients' => [['organizationId' => 3] + $CLIENT]], 'two_orgs', 'R1', 0]] as $what => [$set, $scn, $code, $btn]) {
        $up($set, $scn);
        $rt = time() + 30;
        $d = $seed([$win(['quote_refused_ts' => $rt, 'quote_refused_at' => date('Y-m-d H:i:s', $rt)])]);
        $r = qfu_c_press($c, $srv, $d, 31);
        $e = $app($d);
        $r2 = qfu_c_press($c, $srv, $d, 31);
        $c->eq([$r['flash'], $e['quote_refused'] ?? null, $hasClaim($e), $cardOf($d, false), $r2['flash']],
            // the next press: refused again for P1; for R1 Make the quotation no longer applies — never "claimed"
            [$FL['refused'](31), $code, false, $CARD(false, true, false, 0), $code === 'P1' ? $FL['refused'](31) : $FL['conditions'](31)],
            "C22 (g) a refusal 30 s ahead, then a press {$what}: its claim stamped ahead is removed, the refusal ({$code}) drawn, and the next press is not answered claimed");
        $up($set, $scn);
        $d = $seed([$win(['quote_refused_ts' => $rt, 'quote_refused_at' => date('Y-m-d H:i:s', $rt)])]);
        qfu_c_press($c, $srv, $d, 31, [], ['faults_kyc' => ['lock_n' => 3]]);
        $e = $app($d);
        $ts9 = $e['quote_refused_ts'] ?? null;
        $cl9 = $e['quote_make_claim_ts'] ?? null;
        $c->eq([$e['quote_refused'] ?? null, $cl9, is_int($ts9) && $ts9 >= $cl9], [$code, $rt + 1, true],
            "C22 (g) a refusal 30 s ahead, then a press {$what} whose claim's removal fails: its own refusal stamped at or after its claim");
        $sh = (int)$cl9 - (time() - 901);
        qfu_c_put($d, ['quote_make_claim_ts' => (int)$cl9 - $sh, 'quote_refused_ts' => (int)$ts9 - $sh] + $e);
        $c->eq([$cardOf($d), $cardOf($d, false)], [$CARD(false, true, false, $btn), $CARD(false, true, false, 0)],
            "C22 (g) … a press {$what} whose claim's removal failed, the claim and the refusal aged together: the refusal holds");
    }
    // Round 9 (CK9-B1): the two refusals a press records carry quote_refused_ts, this press's own time. A press whose lines
    // are refused, and one the company check refuses, each with its claim's removal failing (the third lock of the
    // applications: the claim, the key's removal, the claim's removal): past QUOTE_CLAIM_SECONDS the refusal holds
    // Round 10 (CK10-T1, CK10-T2): a P2 refusal (the lines do not total the cash collected) as well; the epoch never before
    // the press's own claim; and the claim and the epoch aged together, as they stand in the store
    foreach (['whose lines are refused' => [['catalogue' => false], 'uganda', 'P1', 1, []],
              'whose lines do not total the cash collected' => [[], 'uganda', 'P2', 0, ['sales_type' => 'Cash', 'amount_charged' => 1600, 'is_lead' => false]],
              'the company check refuses' => [['clients' => [['organizationId' => 3] + $CLIENT]], 'two_orgs', 'R1', 0, []]] as $what => [$set, $scn, $code, $btn, $over]) {
        $up($set, $scn);
        $d = $seed([$win($over)]);
        $t0 = time();
        $r = qfu_c_press($c, $srv, $d, 31, [], ['faults_kyc' => ['lock_n' => 3]]);
        $e = $app($d);
        $ts9 = $e['quote_refused_ts'] ?? null;
        $cl9 = $e['quote_make_claim_ts'] ?? null;
        $c->eq([$r['flash'], count($posts()), $e['quote_refused'] ?? null, $hasClaim($e), is_int($ts9) && $ts9 >= $t0 && $ts9 <= time(),
                is_int($cl9) && is_int($ts9) && $ts9 >= $cl9, strpos($r['errlog'], 'the claim could not be removed') !== false],
            [$FL['refused'](31), 0, $code, true, true, true, true],
            "C22 (g) a press {$what}, its claim's removal failing: the refusal ({$code}) records quote_refused_ts, this press's own time, never before its claim; the claim is left");
        qfu_c_put($d, ['quote_make_claim_ts' => (int)$cl9 - 901, 'quote_refused_ts' => (int)$ts9 - 901] + $e);
        $c->eq([$cardOf($d), $cardOf($d, false), $refT($cardHtml($d, false)) === (string)($e['quote_refused_text'] ?? '')],
            [$CARD(false, true, false, $btn), $CARD(false, true, false, 0), true],
            "C22 (g) … a press {$what}: past QUOTE_CLAIM_SECONDS the refusal holds on both cards, its text whole");
    }
    $up();
    qfu_c_ctl($srv, 'list_down', true);
    $d = $seed([$win()]);
    $r = qfu_c_press($c, $srv, $d, 31, [], ['faults_kyc' => ['second_lock' => true]]);
    $left = $app($d);
    $logLine = '[KycService] Make the quotation, application #31: the claim could not be removed; it expires by its age';
    $c->eq([$r['flash'], $hasClaim($left), strpos($r['errlog'], $logLine) !== false], [$FL['unreadable'](31), true, true],
        'C22 a removal that throws: logged, the answer unchanged (unreadable), the claim left behind');
    $c->t(!preg_match('/Test Window|\+256|700 003|Tester|Test Agent|Test Admin/', $r['errlog']), 'C22 … the log carries ids only — no name, no number', $r['errlog']);
    qfu_c_ctl($srv, 'list_down', false);
    foreach ([600 => true, 901 => false] as $age => $blocks) {   // 600 first: the 901 press makes the quotation
        $up();
        qfu_c_put($d, ['quote_make_claim_ts' => time() - $age] + $left);
        $r = qfu_c_press($c, $srv, $d, 31);
        $c->eq([$r['flash'], count($posts())], $blocks ? [$FL['claimed'](31), 0] : [$FL['made'](31), 1],
            "C22 … the claim it left, aged {$age} s by hand: " . ($blocks ? 'blocks a press' : 'no longer blocks one'));
    }

    // ── C23 each kind's answer, and C22's removal after each (F-7, F-9) ─────────────────────────────────────────
    $kinds = [
        'made'       => [[], [], null],
        'found'      => [$quoteNow(), [], null],
        'unreadable' => [[], [], 'list_down'],
        'refused'    => [[], ['links' => [qfu_link(1, 'plan', 1, QFU_PLAN_STAMP, 12, 6), qfu_link(3, 'install', 0, '', 70)]], null],
        'claimed'    => [[], ['claim' => true], null],
        'error'      => [['refuse_quotes' => true], [], null],
    ];
    $flashes = []; $answers = [];
    foreach ($kinds as $kind => [$set, $o0, $ctl]) {
        foreach (['press', 'retry'] as $how) {
            $up($set);
            if ($ctl !== null) qfu_c_ctl($srv, $ctl, true);
            $o = $o0;
            $claim = !empty($o['claim']) ? time() - 30 : null;
            unset($o['claim']);
            $d = $seed([$win($claim !== null ? ['quote_make_claim_ts' => $claim] : [])], $o);
            $before = $app($d);
            $r = $how === 'press' ? qfu_c_press($c, $srv, $d, 31) : qfu_c_retry($c, $srv, $d, 31);
            $after = $app($d);
            if ($ctl !== null) qfu_c_ctl($srv, $ctl, false);
            if ($how === 'press') {
                $flashes[$kind] = $r['flash'];
                $c->eq($r['flash'], $FL[$kind](31), "C23 {$kind}: the button's own flash");
            } else {
                $answers[$kind] = [$r['code'], $r['json']['message'] ?? null];
                $want = ['made' => [200, 'Quote #77 created! PDF will be sent via WhatsApp in ~5 min.'], 'found' => [409, $FOUND_T], 'unreadable' => [409, $UNREAD_T],
                         'refused' => [409, $after['quote_refused_text'] ?? 'the refusal recorded'], 'claimed' => [409, $CLAIM_T], 'error' => [500, $ERR_T]][$kind];
                $c->eq($answers[$kind], $want, "C23 {$kind}: retry_quote's own answer and code");
            }
            if ($kind === 'claimed') {
                $c->eq([$after['quote_make_claim_ts'] ?? null, $after === $before], [$claim, true], "C22 after {$kind} (" . ($how === 'press' ? 'button' : 'retry_quote') . '): the claim it did not take is still there, and nothing else changed');
            } else {
                $c->eq([$hasClaim($after), in_array($kind, ['unreadable'], true) ? $after === $before : true], [false, true],
                    "C22 after {$kind} (" . ($how === 'press' ? 'button' : 'retry_quote') . '): no quote_make_claim_ts key' . ($kind === 'unreadable' ? ', and the record as before, key for key' : ''));
            }
            if ($kind === 'refused') $c->t($how !== 'press' || strpos((string)($after['quote_refused_text'] ?? ''), $P1U($UNL_KIT)) !== false, 'C23 refused: the card says why (P1_UNLINKED\'s text recorded)', (string)($after['quote_refused_text'] ?? ''));
        }
    }
    $c->eq([count($flashes), count(array_unique($flashes))], [6, 6], 'C23 no two kinds share a flash');
    $c->eq(count(array_filter($answers, fn($a) => $a[0] === 409)), 4, 'C23 four kinds answer 409 at retry_quote (claimed, found, unreadable, refused), each with its own text');

    // ── C24 escaping (F-8) ──────────────────────────────────────────────────────────────────────────────────────
    $up();
    $d = $seed([$win()]);
    $r = qfu_c_press($c, $srv, $d, 31);
    $c->eq($r['flash'], $FL['made'](31), 'C24 the control: an ordinary quote id is shown as it is');
    $up();
    qfu_c_ctl($srv, 'quote_id', '77<b>x</b>');
    $d = $seed([$win()]);
    $r = qfu_c_press($c, $srv, $d, 31);
    $c->eq([$r['flash'], strpos($r['flash'], '<') === false, (string)($app($d)['quote_id'] ?? '')], [$FL['made'](31), true, '77<b>x</b>'],
        'C24 uCRM answers the POST with the id "77<b>x</b>" (stored as uCRM gave it): the flash reads "Quotation #77" and holds no <b>');
    qfu_c_ctl($srv, 'quote_id', false);
    foreach (['Q-5' => 'Q-5', '<b>Q1</b>' => '&lt;b&gt;Q1&lt;/b&gt;'] as $num => $drawn) {
        $html = qfu_c_card($c, $srv, [$win(['quote_found_in_ucrm' => ['number' => $num, 'at' => date('Y-m-d H:i:s')]])], ['tenant_profile' => 'uganda'], true);
        $c->eq([strpos($html, '(#' . $drawn . ') — no second one was made') !== false, strpos($html, '<b>Q1') === false],
            [true, true], 'C24 ' . ($num === 'Q-5' ? 'the control: an ordinary number is drawn as it is' : 'a quotation found numbered <b>Q1</b>: the card draws it escaped'));
    }

    // ── C25 quote_error cleared (F-10) ──────────────────────────────────────────────────────────────────────────
    foreach (['press' => 'the button', 'retry' => 'retry_quote'] as $how => $what) {
        $up();
        $d = $seed([$win(['quote_error' => '{"http_code":503}'])]);
        $had = $app($d)['quote_error'] ?? null;
        $how === 'press' ? qfu_c_press($c, $srv, $d, 31) : qfu_c_retry($c, $srv, $d, 31);
        $rec = $app($d);
        $c->eq([$had, !empty($rec['quote_created']), array_key_exists('quote_error', $rec) ? $rec['quote_error'] : 'no key'], ['{"http_code":503}', true, null],
            "C25 an application carrying quote_error from an earlier failed POST, quoted through {$what}: quote_error null");
    }

    // ── C26 who made it (F-13) ──────────────────────────────────────────────────────────────────────────────────
    foreach (['press' => ['orders_card', 'the button'], 'retry' => ['retry_quote', 'retry_quote']] as $how => [$via, $what]) {
        $up();
        $d = $seed([$win()]);
        $how === 'press' ? qfu_c_press($c, $srv, $d, 31) : qfu_c_retry($c, $srv, $d, 31);
        $rec = $app($d);
        $c->eq([$rec['quote_made_by_id'] ?? null, $rec['quote_made_via'] ?? null], [1, $via], "C26 a quotation made through {$what} records quote_made_by_id (the admin, #1) and quote_made_via {$via}");
    }
    foreach ($kinds as $kind => [$set, $o0, $ctl]) {
        if ($kind === 'made') continue;
        foreach (['press', 'retry'] as $how) {
            $up($set);
            if ($ctl !== null) qfu_c_ctl($srv, $ctl, true);
            $o = $o0;
            $claim = !empty($o['claim']) ? time() - 30 : null;
            unset($o['claim']);
            $d = $seed([$win($claim !== null ? ['quote_make_claim_ts' => $claim] : [])], $o);
            $how === 'press' ? qfu_c_press($c, $srv, $d, 31) : qfu_c_retry($c, $srv, $d, 31);
            if ($ctl !== null) qfu_c_ctl($srv, $ctl, false);
            $rec = $app($d);
            $c->eq([array_key_exists('quote_made_by_id', $rec), array_key_exists('quote_made_via', $rec)], [false, false],
                "C26 … neither key on a refusal ({$kind}, " . ($how === 'press' ? 'button' : 'retry_quote') . ')');
        }
    }
    $up();
    $rec = (array)($sync($pending(['install_by' => 'dishnet', 'quote_sale_entry' => 'web', 'id' => 31]))['value']['app'] ?? []);
    $c->eq([!empty($rec['quote_created']), array_key_exists('quote_made_by_id', $rec), array_key_exists('quote_made_via', $rec)], [true, false, false],
        'C26 … nor by the cron retry, which quotes (the control: quote_created)');
    // 5.18.102 D14 review (T3): the cron retry of an unmarked web application records the link records its lines came from
    $c->eq($rec['quote_link_ids'] ?? null, [1, 2, 3],
        'C26 … and the cron retry records quote_link_ids, the link records its lines came from (docs/71 §R.4.4)');
    $up();
    $d = $seed([$win(['quote_sale_entry' => 'web'])], ['cfg' => $UG + ['ucrm_quote_organization_id' => 'off']]);
    $r = qfu_c_retry($c, $srv, $d, 31);
    $rec = $app($d);
    $c->eq([$r['code'], !empty($rec['quote_created']), array_key_exists('quote_made_by_id', $rec), array_key_exists('quote_made_via', $rec), array_key_exists('quote_make_claim_ts', $rec)],
        [200, true, false, false, false], 'C26 … nor with the gate off: retry_quote quotes by 5.18.101\'s path and records neither key, nor a claim');
    $up();
    $d = $seed([$win()], ['cfg' => $UG + ['ucrm_quote_organization_id' => 'off']]);
    $before = $app($d);
    $r = qfu_c_press($c, $srv, $d, 31);
    $c->eq([$r['result']['end'] ?? null, $r['flash'], $app($d) === $before, $reqs()], ['fell through', '', true, []],
        'C26 … and with the gate off kyc_make_quote is not handled at all: it falls through as an unknown action, nothing written, nothing sent');

    // ── C27 P1_UNLINKED at all three sites (§R.4.1, E-2) ────────────────────────────────────────────────────────
    $noKit  = [qfu_link(1, 'plan', 1, QFU_PLAN_STAMP, 12, 6), qfu_link(3, 'install', 0, '', 70)];
    $noIns  = [qfu_link(1, 'plan', 1, QFU_PLAN_STAMP, 12, 6), qfu_link(2, 'kit', 3, QFU_KIT_STAMP, 55)];
    $endKit = [qfu_link(1, 'plan', 1, QFU_PLAN_STAMP, 12, 6), qfu_ended(qfu_link(2, 'kit', 3, QFU_KIT_STAMP, 55), 'unlinked'), qfu_link(3, 'install', 0, '', 70)];
    $broken = ['products' => qfu_kyc_products([55 => ['archived' => true]])];
    $txtOf = fn(array $rec): string => (string)($rec['quote_refused_text'] ?? '');
    $up();
    $s = $c->submit(['install_by' => 'dishnet']);
    $rec = (array)(end($s['value']['apps']) ?: []);
    $c->eq([count($posts()), $pids($posts()[0] ?? []), $rec['quote_refused'] ?? null], [1, [12, 55, 70], null],
        'C27 the control: the same web Credit sale with every item linked is quoted at submission');
    // submission new (handleNew)
    foreach (['an item unlinked' => [$noKit, $UNL_KIT], 'the installation unlinked' => [$noIns, $UNL_INS]] as $what => [$links, $reason]) {
        $up();
        $s = $c->submit(['install_by' => 'dishnet'], ['links' => $links]);
        $rec = (array)(end($s['value']['apps']) ?: []);
        $c->eq([$rec['quote_refused'] ?? null, strpos($txtOf($rec), $P1U($reason)) !== false, count($posts())], ['P1', true, 0],
            "C27 submission new (handleNew), a web Credit sale with {$what}: P1 with P1_UNLINKED's text");
    }
    $up();
    $s = $c->submit(['install_by' => 'dishnet', 'ucrm_quote_form' => null], ['links' => $noKit]);
    $rec = (array)(end($s['value']['apps']) ?: []);
    $c->eq([$rec['quote_refused'] ?? null, strpos($txtOf($rec), $P1($ENTRY)) !== false, strpos($txtOf($rec), 'Do not make it by hand') !== false], ['P1', true, false],
        'C27 … the control, the same sale without the marker: P1\'s own text (the entry reason)');
    $up($broken);
    $s = $c->submit(['install_by' => 'dishnet']);
    $rec = (array)(end($s['value']['apps']) ?: []);
    $c->eq([$rec['quote_refused'] ?? null, strpos($txtOf($rec), 'No quotation was made: Standard Kit (TEST) is linked to a uCRM product that is archived') !== false,
            strpos($txtOf($rec), 'Make the quotation in uCRM (Send Quotation, or ask an admin).') !== false], ['P1', true, true],
        'C27 … the control, an item linked but broken (its product archived): P1\'s own text');
    // submission existing (handleExisting): a hand-made post with the marker and a customer_id
    $exPost = ['customer_id' => '901', 'connectivity_type' => 'Additional Service', 'install_by' => 'customer'];
    foreach (['the marker, the kit unlinked' => [[], $noKit, 'P1U'], 'no marker (the control)' => [['ucrm_quote_form' => null], $noKit, 'P1'],
              'the marker, the kit linked but broken (the control)' => [[], null, 'broken']] as $what => [$more, $links, $want]) {
        $up($want === 'broken' ? $broken : []);
        $s = $c->submit($exPost + $more, $links !== null ? ['links' => $links] : []);
        $rec = (array)(end($s['value']['apps']) ?: []);
        $ok = $want === 'P1U' ? strpos($txtOf($rec), $P1U($UNL_KIT)) !== false
            : ($want === 'P1' ? strpos($txtOf($rec), $P1($ENTRY)) !== false : (strpos($txtOf($rec), 'archived') !== false && strpos($txtOf($rec), 'Make the quotation in uCRM') !== false));
        $c->eq([$rec['quote_refused'] ?? null, $ok, count($posts())], ['P1', true, 0],
            "C27 submission existing (handleExisting), {$what}: " . ($want === 'P1U' ? 'P1 with P1_UNLINKED\'s text' : 'P1\'s own text'));
    }
    // retryLines: a web registration still unlinked, retried by the cron retry, by retry_quote and by Make the quotation
    foreach (['not linked at all' => $noKit, 'its only link ended (unlinked)' => $endKit] as $how2 => $links) {
        foreach (['cron retry', 'retry_quote', 'Make the quotation'] as $where) {
            $up();
            if ($where === 'cron retry') {
                $rec = (array)($sync($pending(['install_by' => 'dishnet', 'quote_sale_entry' => 'web', 'id' => 31]), ['links' => $links])['value']['app'] ?? []);
            } else {
                $d = $seed([$win(['quote_refused_text' => 'TEST earlier text'])], ['links' => $links]);
                $where === 'retry_quote' ? qfu_c_retry($c, $srv, $d, 31) : qfu_c_press($c, $srv, $d, 31);
                $rec = $app($d);
            }
            $c->eq([$rec['quote_refused'] ?? null, strpos($txtOf($rec), $P1U($UNL_KIT)) !== false, count($posts())], ['P1', true, 0],
                "C27 retryLines, a web registration whose kit is {$how2}, at the {$where}: P1 with P1_UNLINKED's text");
        }
    }
    foreach (['stored without the key' => [['quote_sale_entry' => null], $noKit, []], 'linked but broken' => [[], null, $broken]] as $what => [$f, $links, $set]) {
        foreach (['cron retry', 'retry_quote'] as $where) {
            $up($set);
            $o = $links !== null ? ['links' => $links] : [];
            if ($where === 'cron retry') {
                $row = $pending(['install_by' => 'dishnet', 'quote_sale_entry' => 'web', 'id' => 31]);
            } else {
                $row = $win();
            }
            if (array_key_exists('quote_sale_entry', $f)) unset($row['quote_sale_entry']);
            if ($where === 'cron retry') {
                $rec = (array)($sync($row, $o)['value']['app'] ?? []);
            } else {
                $d = $seed([$row], $o);
                qfu_c_retry($c, $srv, $d, 31);
                $rec = $app($d);
            }
            $c->eq([$rec['quote_refused'] ?? null, strpos($txtOf($rec), 'Make the quotation in uCRM (Send Quotation, or ask an admin).') !== false, strpos($txtOf($rec), 'Do not make it by hand') !== false],
                ['P1', true, false], "C27 retryLines, the control: {$what}, at the {$where}: P1's own text");
        }
    }

    // ── C28 rule 8 at use: a kit row deactivated with its link left in force (5.18.102 D14 review, D14-R8-INACTIVE-KIT) ─
    // §R.6 step 7's own order: the import-made kit row was linked to the installation's product, then deactivated so the
    // installation could be linked to it — the kit's link stays in force. A stored cart (or marked lines) holding that
    // row is never quoted with the installation's product twice: P1 with the rule-8 reason, no quote.add.
    $kitOff = [['is_active' => false] + qfu_rows()['devices'][0]];
    $r8Same = [qfu_link(1, 'plan', 1, QFU_PLAN_STAMP, 12, 6), qfu_link(2, 'kit', 3, QFU_KIT_STAMP, 55), qfu_link(3, 'install', 0, '', 55)];
    $RULE8C = 'Standard Kit (TEST) and the installation are linked to the same uCRM product — an admin links one of them again';
    $cart28 = ['quote_sale_cart' => [['id' => 3, 'qty' => 1]]];
    $LINES8 = [$LINES[0], $LINES[1], ['productId' => 55, 'label' => 'Standard Kit (TEST)', 'price' => 1500, 'quantity' => 1, 'unit' => 'Job']];
    $c28 = [
        'its stored cart, the installation linked to another product (the control)' => [$cart28, qfu_links(), [12, 55, 70]],
        'its stored cart, the installation linked to the inactive kit\'s product'    => [$cart28, $r8Same, null],
        'marked lines with ids 1, 2, 3, the installation linked elsewhere (the control)' => [$marked($LINES, 1800), qfu_links(), [12, 55, 70]],
        'marked lines carrying the kit\'s and the installation\'s record ids (both product 55)' => [$marked($LINES8, 3100), $r8Same, null],
    ];
    foreach ($c28 as $what => [$fields, $links, $want]) {
        foreach (['cron retry', 'retry_quote', 'Make the quotation'] as $where) {
            $up();
            $o = ['devices' => $kitOff, 'links' => $links];
            if ($where === 'cron retry') {
                $rec = (array)($sync($pending($fields + ['install_by' => 'dishnet', 'quote_sale_entry' => 'web', 'id' => 31]), $o)['value']['app'] ?? []);
            } else {
                $d = $seed([$win($fields + ['quote_refused_text' => 'TEST earlier text'])], $o);
                $where === 'retry_quote' ? qfu_c_retry($c, $srv, $d, 31) : qfu_c_press($c, $srv, $d, 31);
                $rec = $app($d);
            }
            if ($want !== null) {
                $c->eq([count($posts()), $pids($posts()[0] ?? []), !empty($rec['quote_created'])], [1, $want, true],
                    "C28 the control, at the {$where}: a web registration with the kit row inactive, {$what}, is quoted once by its links");
                continue;
            }
            $c->eq([count($postTo()), $rec['quote_refused'] ?? null, strpos($txtOf($rec), $P1($RULE8C)) !== false, !empty($rec['quote_created'])], [0, 'P1', true, false],
                "C28 rule 8 at use, at the {$where}: a web registration with the kit row inactive, {$what} — no quotation, P1 with the rule-8 reason, never the installation's product twice");
        }
    }
}
