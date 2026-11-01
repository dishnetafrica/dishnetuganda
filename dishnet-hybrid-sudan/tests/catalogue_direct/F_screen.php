<?php
declare(strict_types=1);
/**
 * Section F — 5.18.103 (root docs/72 §G.3, G-1, G-11): the uCRM catalogue screen and its five actions, through the judged
 * tree's own public.php gate and includes/post/post_sync.php (tests/quote_from_ucrm/l_run.php). The guards in 5.18.102's
 * order (an admin re-read fresh, the own-page check, the setting); turning on only when its three conditions hold, turning
 * off with uCRM down; the installation product, keeping off, standard quotations; the link store frozen while the switch
 * is on (saves, clears and deletes); the Plans and Hardware screens replaced by a card; South Sudan untouched.
 */
require_once dirname(__DIR__) . '/quote_from_ucrm/l_lib.php';

$CD_SECTIONS['F'] = ['title' => 'the uCRM catalogue screen and its actions', 'run' => function (QfuCtx $c) {
    $cat = function (string $dir): array {
        $r = qfu_l_rows_of($dir, 'ucrm_catalogue');
        return $r === null ? [] : array_values($r);
    };
    $inForce = function (array $recs, string $kind): array {
        return array_values(array_filter($recs, function ($r) use ($kind) { return ($r['kind'] ?? null) === $kind && array_key_exists('ended_at_ts', $r) && $r['ended_at_ts'] === null; }));
    };
    $seed = function (?array $records, array $o = []) use ($c): string {
        $dir = qfu_l_seed($c, $o);
        if ($records !== null) qfu_l_put($dir, 'ucrm_catalogue', $records);
        return $dir;
    };
    $post = function (string $dir, array $fields, array $o = []) use ($c): array { return qfu_l_post($c, $dir, $fields, $o); };
    $page = function (string $dir, string $file = 'ucrm_catalogue.php', bool $admin = true) use ($c): string {
        @unlink($dir . '/ucrm_quote_catalogue_last_good.json');
        return qfu_l_page($c, $dir, $file, [], $admin)['html'];
    };

    // ── F0 the positive control: the screen draws what the forms would offer ─────────────────────────────────────────
    cd_fake($c);
    $dir = $seed(cd_records(false));
    $h = $page($dir);
    $c->t(strpos($h, 'The switch: <span style="color:#9a3412;">OFF</span>') !== false && strpos($h, 'Plans offered (2)') !== false
        && strpos($h, 'Hardware offered (3)') !== false && strpos($h, 'Residential Standard (TEST)') !== false, 'F0 the control: the screen, the switch off, two plans and three hardware items', substr($h, 0, 400));
    $c->t(strpos($h, 'Lonely Plan (TEST)') !== false && strpos($h, 'no uCRM product is spelled like the service plan') !== false
        && strpos($h, 'Old Kit (TEST)') !== false, 'F0 not offered, with the reasons, the filtered rows named');
    $c->t(strpos($h, 'name="action" value="ucrm_cat_mode"') !== false && strpos($h, 'name="on" value="1"') !== false, 'F0 the admin sees Turn on (its conditions met)');
    $hA = $page($dir, 'ucrm_catalogue.php', false);
    $c->t(strpos($hA, 'Plans offered (2)') !== false && strpos($hA, 'name="action"') === false, 'F0 a non-admin sees the lists, and no control at all');

    // ── F1 the guards, in 5.18.102's order; nothing written by a refusal ─────────────────────────────────────────────
    cd_fake($c);
    $dir = $seed(cd_records(false));
    $before = $cat($dir);
    $r = $post($dir, ['action' => 'ucrm_cat_mode', 'on' => '1'], ['session' => QFU_L_AGENT]);
    $c->eq([$r['code'] ?? null, strpos((string)($r['buffer'] ?? ''), QFU_L_DENIED) !== false, $r['msg'], $r['requests'], $cat($dir) === $before],
        [403, true, '', [], true], 'F1 a non-admin: 403, no flash, uCRM not asked, nothing written');
    $r = $post($dir, ['action' => 'ucrm_cat_mode', 'on' => '1'], ['token' => false]);
    // the global CSRF gate (includes/post_handlers.php) answers first when no token is sent at all; the handler's own check after it
    $c->t(in_array($r['msg'], [QFU_L_SECURITY, 'Security token mismatch. Please try again.'], true) && $cat($dir) === $before, 'F1 no form token: refused, nothing written', $r['msg']);
    $r = $post($dir, ['action' => 'ucrm_cat_mode', 'on' => '1'], ['headers' => ['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_ORIGIN' => 'https://evil.example']]);
    $c->t($r['msg'] === QFU_L_SECURITY && $cat($dir) === $before, 'F1 a cross-site post: the security flash, nothing written', $r['msg']);
    $r = $post($dir, ['action' => 'ucrm_cat_mode', 'on' => '1'], ['cfg' => ['ucrm_quote_organization_id' => 'abc']]);
    $c->t(strpos($r['msg'], 'The company the app quotes under is not set correctly') === 0 && $cat($dir) === $before, 'F1 the setting invalid: one fixed flash, nothing written', $r['msg']);
    // the account re-read fresh (5.18.102's L6 cases): the session copy an admin, the stored row no longer one — refused at
    // once, nothing written, uCRM not asked; the control, a stored admin row behind a 400-second-old session copy, accepted
    foreach (['the stored row demoted (is_admin false)' => [['is_admin' => false] + QFU_L_ADMIN, QFU_L_AGENT],
              'the stored row inactive (is_active false)' => [['is_active' => false] + QFU_L_ADMIN, QFU_L_AGENT]] as $what => $rows) {
        cd_fake($c);
        $dir = $seed(cd_records(false), ['retailers' => $rows]);
        $before = $cat($dir);
        $r = $post($dir, ['action' => 'ucrm_cat_mode', 'on' => '1'], ['session' => QFU_L_ADMIN, 'session_age' => 5]);
        $c->eq([$r['code'] ?? null, strpos((string)($r['buffer'] ?? ''), QFU_L_DENIED) !== false, $r['msg'], $r['requests'], $cat($dir) === $before],
            [403, true, '', [], true], "F1 {$what}, the session copy an admin seconds old: 403, uCRM not asked, nothing written");
    }
    cd_fake($c);
    $dir = $seed(cd_records(false));
    $r = $post($dir, ['action' => 'ucrm_cat_mode', 'on' => '0'], ['session' => QFU_L_ADMIN, 'session_age' => 400]);
    $c->t(in_array($r['type'] ?? '', ['success', 'warning', 'info'], true), 'F1 the control: a stored admin row behind a 400-second-old session copy is accepted', json_encode([$r['type'] ?? null, $r['msg'] ?? null]));

    // ── F2 turning on: its three conditions; turning off needs no uCRM ───────────────────────────────────────────────
    cd_fake($c);
    $dir = $seed(cd_records(false, null));
    $r = $post($dir, ['action' => 'ucrm_cat_mode', 'on' => '1']);
    $c->t(strpos($r['msg'], 'installation product is not set') !== false && $inForce($cat($dir), 'mode')[0]['on'] === false, 'F2 the installation not set: not turned on', $r['msg']);
    cd_fake($c, ['service_plans' => cd_plans([CD_PLAN_RES => null, CD_PLAN_BIZ => null])]);
    $dir = $seed(cd_records(false));
    $r = $post($dir, ['action' => 'ucrm_cat_mode', 'on' => '1']);
    $c->t(strpos($r['msg'], 'No plan is offered from uCRM yet') === 0 && $inForce($cat($dir), 'mode')[0]['on'] === false, 'F2 no plan offered: not turned on', $r['msg']);
    cd_fake($c, ['products_down' => true]);
    $r = $post($dir, ['action' => 'ucrm_cat_mode', 'on' => '1']);
    $c->t(strpos($r['msg'], 'uCRM could not be read, so the uCRM catalogue was not turned on') === 0, 'F2 uCRM down: not turned on', $r['msg']);
    cd_fake($c, ['products' => cd_products([CD_INSTALL => ['taxable' => true]])]);
    $r = $post($dir, ['action' => 'ucrm_cat_mode', 'on' => '1']);
    $c->t(strpos($r['msg'], 'taxable') !== false && $inForce($cat($dir), 'mode')[0]['on'] === false, 'F2 the installation product failing its rules now: not turned on', $r['msg']);
    cd_fake($c);
    $r = $post($dir, ['action' => 'ucrm_cat_mode', 'on' => '1']);
    $m = $inForce($cat($dir), 'mode');
    $c->eq([strpos($r['msg'], 'The uCRM catalogue is on') === 0, count($m), $m[0]['on'] ?? null, $m[0]['by_id'] ?? null], [true, 1, true, 1],
        'F2 the three conditions met: turned on, by the admin re-read fresh', $r['msg']);
    cd_fake($c, ['products_down' => true]);
    $r = $post($dir, ['action' => 'ucrm_cat_mode', 'on' => '0']);
    $c->eq([strpos($r['msg'], 'The uCRM catalogue is off') === 0, $inForce($cat($dir), 'mode')[0]['on'] ?? null], [true, false], 'F2 turned off with uCRM down: no uCRM read needed', $r['msg']);

    // ── F3 the installation product, keeping off, standard quotations ───────────────────────────────────────────────
    cd_fake($c);
    $dir = $seed([]);
    $r = $post($dir, ['action' => 'ucrm_cat_install', 'product_id' => '80']);
    $c->t(strpos($r['msg'], 'Nothing was changed') === 0 && $inForce($cat($dir), 'install') === [], 'F3 a product priced 0 cannot be the installation', $r['msg']);
    $r = $post($dir, ['action' => 'ucrm_cat_install', 'product_id' => '17']);
    $c->t(strpos($r['msg'], 'Nothing was changed') === 0 && $inForce($cat($dir), 'install') === [], 'F3 a plan\'s product (spelled like a service plan) cannot be the installation', $r['msg']);
    $r = $post($dir, ['action' => 'ucrm_cat_install', 'product_id' => '70']);
    $i = $inForce($cat($dir), 'install');
    $c->eq([count($i), $i[0]['product_id'] ?? null, $i[0]['product_name'] ?? null], [1, 70, 'Professional Installation'], 'F3 the installation product set, its name from uCRM', $r['msg']);
    $r = $post($dir, ['action' => 'ucrm_cat_hidden', 'product_id' => (string)CD_ACC, 'hide' => '1']);
    $hid = $inForce($cat($dir), 'hidden');
    $c->eq([count($hid), $hid[0]['product_id'] ?? null, $hid[0]['product_name'] ?? null], [1, CD_ACC, 'Pipe Adapter (TEST)'], 'F3 a product kept off, named from uCRM (never the browser)');
    $post($dir, ['action' => 'ucrm_cat_hidden', 'product_id' => (string)CD_ACC, 'hide' => '0']);
    $c->eq($inForce($cat($dir), 'hidden'), [], 'F3 offered again');
    $std = ['action' => 'ucrm_cat_standard_save', 'title' => 'Mini + Residential', 'plan' => CD_PLAN_RES . ':12', 'hw_p' => [(string)CD_MINI, ''], 'hw_q' => ['1', '1'], 'install' => '1'];
    $r = $post($dir, $std);
    $s1 = $inForce($cat($dir), 'standard');
    $c->eq([count($s1), $s1[0]['hw'] ?? null, $s1[0]['install'] ?? null, $s1[0]['plan'] ?? null],
        [1, [['p' => CD_MINI, 'q' => 1]], true, ['sp' => CD_PLAN_RES, 'p' => 12]], 'F3 a standard quotation saved by ids (an empty row skipped)', $r['msg']);
    $r = $post($dir, ['plan' => '8:12'] + $std);
    $c->t(strpos($r['msg'], 'Nothing was saved: its plan is not offered now') === 0 && count($inForce($cat($dir), 'standard')) === 1, 'F3 its plan not offered: refused', $r['msg']);
    $r = $post($dir, ['hw_p' => ['80'], 'hw_q' => ['1']] + $std);
    $c->t(strpos($r['msg'], 'Nothing was saved') === 0 && count($inForce($cat($dir), 'standard')) === 1, 'F3 a hardware item not offered: refused', $r['msg']);
    $r = $post($dir, ['hw_q' => ['100', '1']] + $std);
    $c->t(strpos($r['msg'], 'Nothing was saved') === 0, 'F3 a quantity of 100: refused', $r['msg']);
    $r = $post($dir, ['standard_id' => (string)$s1[0]['id'], 'title' => 'Mini + Residential (v2)'] + $std);
    $s2 = $inForce($cat($dir), 'standard');
    $c->eq([count($s2), $s2[0]['title'] ?? null, $s2[0]['id'] !== $s1[0]['id']], [1, 'Mini + Residential (v2)', true], 'F3 edited: the old version ended, a new one in force');
    $post($dir, ['action' => 'ucrm_cat_standard_end', 'standard_id' => (string)$s2[0]['id']]);
    $c->eq($inForce($cat($dir), 'standard'), [], 'F3 stopped');

    // ── F4 the link store frozen while the switch is on: saves, clears and deletes leave it untouched (G-11) ─────────
    cd_fake($c);
    $links = [qfu_link(1, 'plan', 1, QFU_PLAN_STAMP, 12, 6), qfu_link(2, 'kit', 3, QFU_KIT_STAMP, 55)];
    $dir = $seed(cd_records(true), ['links' => $links]);
    $r = qfu_l_link($c, $dir, 'kit', 4, CD_MINI);
    $c->t(strpos($r['msg'], 'Nothing was linked: on Uganda, plans and hardware now come straight from uCRM') === 0 && qfu_l_links_of($dir) === $links,
        'F4 the switch on: a link save is refused, the store untouched', $r['msg']);
    $r = qfu_l_unlink($c, $dir, 'kit', 3);
    $c->t(strpos($r['msg'], 'Nothing was unlinked') === 0 && qfu_l_links_of($dir) === $links, 'F4 … an unlink refused', $r['msg']);
    $post($dir, ['action' => 'delete_plan', 'plan_id' => '1']);
    $c->eq(count(qfu_l_active(qfu_l_links_of($dir), 'plan', 1)), 1, 'F4 … deleting the plan row ends none of its links');
    $dir = $seed(cd_records(false), ['links' => $links]);
    $post($dir, ['action' => 'delete_plan', 'plan_id' => '1']);
    $c->eq(count(qfu_l_active(qfu_l_links_of($dir), 'plan', 1)), 0, 'F4 control: the switch off, deleting the plan row ends its links, as 5.18.102');

    // ── F5 the Plans and Hardware screens: a card while the switch is on, opening nothing; 5.18.102's otherwise ──────
    cd_fake($c);
    $dir = $seed(cd_records(true), ['links' => null]);
    foreach (['subscription_plans.php', 'hardware.php'] as $f) {
        $h = $page($dir, $f);
        $c->t(strpos($h, 'come straight from uCRM') !== false && strpos($h, 'tab=ucrm_catalogue') !== false && strpos($h, 'uCRM link') === false
            && !in_array('ucrm_quote_links', qfu_l_tables($dir), true), "F5 {$f}, the switch on: one card, no link store opened", substr($h, 0, 300));
    }
    $dir = $seed(cd_records(false), ['links' => null]);
    $h = $page($dir, 'subscription_plans.php');
    $c->t(strpos($h, 'come straight from uCRM') === false && strpos($h, 'uCRM link') !== false, 'F5 control: the switch off, 5.18.102\'s Plans screen');

    // ── F6 the screen lists the web registrations waiting with P1 (G-10) ────────────────────────────────────────────
    cd_fake($c);
    $dir = $seed(cd_records(false), ['apps' => [['id' => 51, 'quote_sale_entry' => 'web', 'quote_refused' => 'P1', 'quote_created' => false],
        ['id' => 52, 'quote_sale_entry' => 'web-direct', 'quote_refused' => 'P1'], ['id' => 53, 'quote_sale_entry' => 'web', 'quote_created' => true]]]);
    $h = $page($dir);
    $c->t(strpos($h, '1 registration(s) made before the switch are waiting with no quotation (application #51)') !== false,
        'F6 the switch off: the 5.18.102 web applications waiting with P1 are listed by id, only those', substr($h, strpos($h, 'registration') ?: 0, 200));

    // ── F7 South Sudan: the screen says it does not apply; the actions fall through; no table ───────────────────────
    $dir = qfu_l_seed($c, ['k' => 'ss']);
    $h = $page($dir);
    $c->t(strpos($h, 'applies only where quotations are made under the quoting organization') !== false && strpos($h, 'Plans offered') === false,
        'F7 South Sudan: the screen does not apply', substr($h, 0, 200));
    $r = $post($dir, ['action' => 'ucrm_cat_mode', 'on' => '0']);
    $c->t($r['msg'] === '' && !in_array('ucrm_catalogue', qfu_l_tables($dir), true), 'F7 South Sudan: an action falls through, nothing written, no table', $r['msg']);
}];
