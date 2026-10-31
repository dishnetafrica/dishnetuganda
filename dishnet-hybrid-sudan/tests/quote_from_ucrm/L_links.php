<?php
declare(strict_types=1);
/**
 * L — the links (5.18.102 D14, docs/71 §R, §R.9.1 "New section L"): the store ucrm_quote_links.json and its record, the
 * active marker, the no-op rule, history, the write under withLock with the uCRM read outside it, the race and the
 * row-changed cases, two active records (save, unlink, delete), the two actions (ucrm_link_save, ucrm_link_clear: the
 * admin on the fresh account record, the own-page check, the global gate's cb_action skip, every §R.3.1 refusal writing
 * nothing beside its accepted control, a fresh read never the page cache), provenance, only the store written, rule 8,
 * the judge (fromStore and fromRecords answering alike over every link state, the organization from the caller,
 * offeredKit), the picker, the link column, one text for two active records, escaping, what was quoted under a link,
 * and the gate off never creating the table on South Sudan or on Uganda with the setting 0.
 *
 * Every request runs the judged tree's own code in a process of its own (l_lib.php, l_run.php): the POSTs through the
 * global CSRF gate and includes/post/post_sync.php on a real SqliteStore, the screens as the dashboard includes them,
 * against the fake uCRM (fixtures/fake_ucrm_kyc.php) serving the section's catalogue. What a request left is read back
 * straight from plugin.sqlite3; what reached uCRM, from the fake's log. Every person, number and document is
 * fictitious. No time zone is named in this file: the time stamps are judged in the runner's QFU_TZ.
 */
require_once __DIR__ . '/l_lib.php';

$QFU_SECTIONS['L'] = ['title' => 'The links: the store, the two actions, the judge, the picker, the link column, the gate off', 'run' => function (QfuCtx $c) {
    if ($c->fake('kyc') === 0) { $c->t(false, 'L the fake uCRM started'); return; }

    // The spec's plain() (docs/71 §R.2.3; QuoteOrganization.php:228-232): <, >, & and " removed, control characters too.
    $plain = function (string $s): string {
        $s = str_replace(['<', '>', '&', '"'], '', $s);
        $s = (string)preg_replace('/[\x00-\x1F\x7F]/u', '', $s);
        return mb_substr(trim($s), 0, 80);
    };
    $h = function (string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };
    $say = function (string $reason): string { return 'Nothing was linked: ' . rtrim($reason, '.') . '.'; };
    $links = function (string $dir) { return qfu_l_links_of($dir); };
    $j = function ($v): string { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); };
    $ok = function (array $r): bool { return ($r['type'] ?? '') === 'success'; };
    // One item's answer, by the judged tree's resolver over the store's links and the fake's catalogue (a probe, read only)
    $resolve = function (string $dir, string $item, int $rowId, string $k = 'ug') use ($c): array {
        $r = $c->child('
$cfg = json_decode(' . var_export(json_encode(qfu_l_cfg($k)), true) . ', true) + ($s->load("kyc_config.json") ?? []);
$set = QuoteOrganization::setting($cfg, $dir);
$ql = new UcrmQuoteLines($crm, $set, UcrmQuoteLinks::fromStore($s, $set));
if (' . var_export($item, true) . ' === "install") return $ql->installLine();
$row = $s->findOne(' . var_export($item === 'plan' ? 'subscription_plans.json' : 'kyc_devices.json', true) . ', "id", ' . (int)$rowId . ');
if (!is_array($row)) return ["ok" => false, "reason" => "no row"];
return ' . var_export($item, true) . ' === "plan" ? $ql->planLine($row) : $ql->kitLine($row);
', ['dir' => $dir, 'tree' => qfu_l_tree($c), 'tenant' => $k === 'ss' ? 'south-sudan' : 'uganda', 'cfg' => [], 'links' => null,
    'plans' => [], 'devices' => []]);
        return is_array($r['value']) ? $r['value'] : ['ok' => false, 'reason' => 'PROBE FAILED ' . substr($r['out'] . $r['errlog'], 0, 500)];
    };
    $rows = qfu_l_rows();
    $R = [
        'plan1' => 'Home Plan (TEST)', 'plan3' => 'Spare Plan (TEST)', 'kit3' => 'Standard Kit (TEST)', 'kit4' => 'Mini Kit (TEST) (plugin)',
    ];
    $RES = 'Residential Standard (TEST)';
    $linked = function (string $row, int $pid, string $pname, ?int $spid = null, ?string $spname = null): string {
        return $row . ' is linked to uCRM product #' . $pid . ' (' . $pname . ')' . ($spid !== null ? ' and service plan #' . $spid . ' (' . $spname . ')' : '') . '.';
    };
    $DEF = [qfu_link(1, 'plan', 1, QFU_PLAN_STAMP, 12, 6), qfu_link(2, 'kit', 3, QFU_KIT_STAMP, 55), qfu_link(3, 'install', 0, '', 70)];

    // ── L0 the positive control: the harness reaches the handler, and a link is written ──────────────────────────────
    qfu_l_fake($c);
    $d0 = qfu_l_seed($c, ['links' => []]);
    $tok = qfu_l_token($c, $d0);
    $c->t((bool)preg_match('/^\d{8}:[0-9a-f]{40}$/', $tok), 'L0 the control: the judged tree\'s csrfToken() gives the day\'s form token for the data directory', $tok);
    $before = $links($d0);
    $r = qfu_l_link($c, $d0, 'plan', 1, 12, 6);
    $c->eq([$r['location'], $r['type'], $r['msg'], $r['requests']],
        ['?page=dashboard&tab=subscription_plans', 'success', $linked($R['plan1'], 12, $RES, 6, $RES), ['GET /products', 'GET /service-plans']],
        'L0 the control: an admin links plan row 1 to product 12 and service plan 6 — the flash names both, uCRM was read, back to the Plans screen');
    $c->eq([$before, count((array)$links($d0))], [[], 1], 'L0 … the store held no record before, and holds one now');

    // ── L1 the store and its record (§R.1.1): one record per act of linking, every key, provenance ────────────────────
    $recs = (array)$links($d0);
    $rec = $recs[0] ?? [];
    $KEYS = ['id', 'item', 'row_id', 'row_stamp', 'product_id', 'service_plan_id', 'organization_id', 'row_name', 'product_name',
             'service_plan_name', 'linked_by_id', 'linked_by_name', 'linked_at', 'ended_at', 'ended_by_id', 'ended_why', 'linked_at_ts', 'ended_at_ts'];
    $k1 = array_keys($rec); sort($k1); $k2 = $KEYS; sort($k2);
    $c->eq($k1, $k2, 'L1 the record carries exactly §R.1.1\'s keys');
    $c->eq(array_diff_key($rec, ['linked_at' => 1, 'linked_at_ts' => 1]), [
        'id' => 1, 'item' => 'plan', 'row_id' => 1, 'row_stamp' => QFU_PLAN_STAMP, 'product_id' => 12, 'service_plan_id' => 6,
        'organization_id' => 1, 'row_name' => $R['plan1'], 'product_name' => $RES, 'service_plan_name' => $RES,
        'linked_by_id' => 1, 'linked_by_name' => 'Test Admin', 'ended_at' => null, 'ended_by_id' => null, 'ended_why' => null, 'ended_at_ts' => null],
        'L1 … its values: the row by id and stamp (created_at), uCRM\'s ids, the quoting organization 1, the names at link time, the admin, active');
    $c->t(is_int($rec['linked_at_ts'] ?? null) && is_string($rec['linked_at'] ?? null) && (bool)preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $rec['linked_at']),
        'L1 … linked_at a local "Y-m-d H:i:s" string, linked_at_ts an integer', $j($rec));
    $pdo = new \PDO('sqlite:' . $d0 . '/plugin.sqlite3');
    $cols = array_map(function ($x) { return $x['name']; }, $pdo->query('PRAGMA table_info(ucrm_quote_links)')->fetchAll(\PDO::FETCH_ASSOC));
    $c->eq($cols, ['id', 'data'], 'L1 the collection is the table ucrm_quote_links in plugin.sqlite3, rows id and data (SqliteStore\'s record table)');
    $pdo = null;
    $r = qfu_l_link($c, $d0, 'kit', 3, 55);
    $r2 = qfu_l_link($c, $d0, 'install', 0, 70);
    $recs = (array)$links($d0);
    $c->eq([$ok($r), $ok($r2), count($recs), array_column($recs, 'id')], [true, true, 3, [1, 2, 3]],
        'L1 the control: a kit and the installation linked too — one record per act of linking, each with its own id');
    $c->eq([$recs[1]['item'] ?? null, $recs[1]['row_id'] ?? null, $recs[1]['row_stamp'] ?? null, $recs[1]['product_id'] ?? null,
            array_key_exists('service_plan_id', $recs[1] ?? []) ? $recs[1]['service_plan_id'] : 'absent',
            array_key_exists('service_plan_name', $recs[1] ?? []) ? $recs[1]['service_plan_name'] : 'absent', $recs[1]['product_name'] ?? null],
        ['kit', 3, QFU_KIT_STAMP, 55, null, null, 'Standard Kit (TEST)'], 'L1 … a kit\'s record: its row and stamp, service_plan_id and its name null');
    $c->eq([$recs[2]['item'] ?? null, $recs[2]['row_id'] ?? null, $recs[2]['row_stamp'] ?? null, $recs[2]['row_name'] ?? null, $recs[2]['product_id'] ?? null],
        ['install', 0, '', 'Installation', 70], 'L1 … the installation\'s record: row 0, stamp \'\', named "Installation"');
    // the stamp: created_at, else imported_at, else '' (UcrmQuoteLinks::rowStamp)
    $dS = qfu_l_seed($c, ['links' => [], 'devices' => array_merge($rows['devices'], [
        ['id' => 6, 'title' => 'Imported Kit (TEST)', 'type' => 'general', 'is_active' => true, 'imported_at' => '2026-09-30 12:00:00'],
        ['id' => 7, 'title' => 'Unstamped Kit (TEST)', 'type' => 'general', 'is_active' => true]])]);
    $ra = qfu_l_link($c, $dS, 'kit', 6, 56);
    $rb = qfu_l_link($c, $dS, 'kit', 7, 56);
    $sr = (array)$links($dS);
    $c->eq([$ok($ra), $ok($rb), $sr[0]['row_stamp'] ?? null, $sr[1]['row_stamp'] ?? null], [true, true, '2026-09-30 12:00:00', ''],
        'L1 row_stamp is the row\'s created_at, else its imported_at, else \'\'');
    // provenance from the fresh account record, never the session copy: the session says another name
    $stale = ['name' => 'Stale Name (TEST)'] + QFU_L_ADMIN2;
    $kitBefore = $recs[1] ?? [];
    $r = qfu_l_link($c, $d0, 'kit', 3, 54, null, ['session' => $stale]);
    $recs = (array)$links($d0);
    $new = end($recs);
    $old = $recs[1] ?? [];
    $c->t($ok($r) && $stale['name'] !== QFU_L_ADMIN2['name'], 'L1 the control: the relinking admin\'s session copy carries another name than the store', $r['msg']);
    $c->eq([$new['linked_by_id'] ?? null, $new['linked_by_name'] ?? null, $old['ended_by_id'] ?? null, $old['ended_why'] ?? null],
        [2, 'Test Admin Two', 2, 'relinked'], 'L1 provenance: linked_by_id and linked_by_name, and the ended record\'s ended_by_id, are the fresh account record\'s');
    $c->eq(array_diff_key($old, ['ended_at' => 1, 'ended_at_ts' => 1, 'ended_by_id' => 1, 'ended_why' => 1]),
        array_diff_key($kitBefore, ['ended_at' => 1, 'ended_at_ts' => 1, 'ended_by_id' => 1, 'ended_why' => 1]),
        'L1 history kept: the ended record is still in the store, every other key as it was written before the relink');
    $c->eq([array_key_exists('id', $kitBefore), array_key_exists('ended_at_ts', $kitBefore) ? $kitBefore['ended_at_ts'] : 'absent', count($recs)], [true, null, 4],
        'L1 … the control: before the relink the record was active, and the relink added one');

    // ── L-R6 (5.18.102 D14 review, G2; 5.18.99 R6): the gate on but the quoting-organization setting invalid — every save
    // refuses naming the setting, before any uCRM read or lock, and writes nothing (never "changed … try again")
    $R6L = 'The company the app quotes under is not set correctly, so nothing was linked. Tell an admin.';
    foreach (['abc', '1x'] as $bad) {
        $cfgBad = ['ucrm_quote_organization_id' => $bad];
        $dI = qfu_l_seed($c, ['links' => $DEF, 'cfg' => $cfgBad]);
        $before = $links($dI);
        foreach ([['plan', 1, 12, 6], ['kit', 4, 56, null], ['kit', 3, 57, null], ['install', 0, 70, null]] as [$it, $row, $pid, $sp]) {
            $r = qfu_l_link($c, $dI, $it, $row, $pid, $sp, ['cfg' => $cfgBad]);
            $c->eq([$r['type'], $r['msg'], $r['requests']], ['danger', $R6L, []],
                "L-R6 setting '{$bad}': saving {$it} #{$row} refuses naming the setting, no uCRM read");
        }
        $c->eq($links($dI), $before, "L-R6 setting '{$bad}': the store is unchanged");
    }
    // the control: the same seed with the setting 1 links after a uCRM read
    $dC = qfu_l_seed($c, ['links' => $DEF, 'cfg' => ['ucrm_quote_organization_id' => '1']]);
    $r = qfu_l_link($c, $dC, 'kit', 4, 56, null, ['cfg' => ['ucrm_quote_organization_id' => '1']]);
    $c->eq([$r['type'], $r['requests'], count((array)$links($dC))], ['success', ['GET /products', 'GET /service-plans'], 4],
        'L-R6 the control: setting 1, the same save links after a uCRM read (one record more)');
    // L-R6b (5.18.102 D14 review round 2, FX-money-2): the setting is checked after the admin and own-page checks (the
    // handler's step 2a), so with the setting 'abc' a non-admin is still answered 403 and a cross-site post the security
    // flash — neither is told the setting is wrong; nothing written, uCRM not asked. Its control is L-R6's admin above.
    $cfgBad = ['ucrm_quote_organization_id' => 'abc'];
    $dO = qfu_l_seed($c, ['links' => $DEF, 'cfg' => $cfgBad]);
    $was = $links($dO);
    $r = qfu_l_link($c, $dO, 'kit', 4, 56, null, ['cfg' => $cfgBad, 'session' => QFU_L_AGENT]);
    $c->eq([$r['code'], strpos((string)($r['buffer'] ?? ''), QFU_L_DENIED) !== false, strpos((string)($r['buffer'] ?? ''), $R6L) !== false, $r['msg'], $r['requests'], $links($dO)],
        [403, true, false, '', [], $was], "L-R6b setting 'abc': a non-admin is answered 403, never the setting's text; nothing written, uCRM not asked");
    $r = qfu_l_link($c, $dO, 'kit', 4, 56, null, ['cfg' => $cfgBad, 'headers' => ['HTTP_SEC_FETCH_SITE' => 'cross-site']]);
    $c->eq([$r['msg'], $r['type'], $r['requests'], $links($dO)], [QFU_L_SECURITY, 'danger', [], $was],
        "L-R6b setting 'abc': a cross-site post gets the security flash, never the setting's text; nothing written, uCRM not asked");

    // ── L2 time and the active marker (§R.1.1): ended_at_ts null is active; the zone is the runner's QFU_TZ ──────────
    $tz = new \DateTimeZone(QFU_TZ);
    $off = $tz->getOffset(new \DateTime('now', new \DateTimeZone('UTC')));
    $c->t($off !== 0, 'L2 the control: the runner\'s zone is not UTC, so a local string and the epoch tell themselves apart', (string)$off);
    $dT = qfu_l_seed($c, ['links' => []]);
    $t0 = time();
    $r = qfu_l_link($c, $dT, 'plan', 1, 12, 6);
    $t1 = time();
    $tr = (array)$links($dT);
    $ts = (int)($tr[0]['linked_at_ts'] ?? 0);
    $local = (new \DateTime('@' . $ts))->setTimezone($tz)->format('Y-m-d H:i:s');
    $c->t($ok($r) && $ts >= $t0 - 1 && $ts <= $t1 + 1, 'L2 linked_at_ts is time() at the save, within a few seconds, whatever the zone', "{$t0} ≤ {$ts} ≤ {$t1}");
    $c->eq([$tr[0]['linked_at'] ?? null, ($tr[0]['linked_at'] ?? null) === gmdate('Y-m-d H:i:s', $ts)], [$local, false],
        'L2 … and linked_at is that instant as a local string in the process zone, not UTC\'s');
    $r = qfu_l_link($c, $dT, 'plan', 1, 90, 21);
    $tr = (array)$links($dT);
    $c->eq([$ok($r), $tr[0]['ended_at_ts'] ?? null, $tr[0]['ended_at'] ?? null, $tr[0]['ended_why'] ?? null],
        [true, $tr[1]['linked_at_ts'] ?? 'x', $tr[1]['linked_at'] ?? 'x', 'relinked'],
        'L2 a relink writes ended_at and ended_at_ts together on the record it ends, in the same lock (the new record\'s instant)');
    // hand-seeded: ended_at_ts set with ended_at null → ended; ended_at set with ended_at_ts null → active
    $dA = qfu_l_seed($c, ['links' => [
        qfu_link(1, 'plan', 1, QFU_PLAN_STAMP, 12, 6, ['ended_at' => null, 'ended_at_ts' => 1790935200, 'ended_why' => 'unlinked']),
        qfu_link(2, 'kit', 3, QFU_KIT_STAMP, 55, null, ['ended_at' => '2026-10-02 10:00:00', 'ended_at_ts' => null]),
    ]]);
    $ak = $resolve($dA, 'kit', 3);
    $ap = $resolve($dA, 'plan', 1);
    $c->eq([$ak['ok'] ?? null, $ak['link'] ?? null], [true, 2], 'L2 the control: a record with ended_at set but ended_at_ts null is active — the kit resolves through it');
    $c->eq([$ap['ok'] ?? null, $ap['reason'] ?? null], [false, $R['plan1'] . ' is not linked to a uCRM product yet — an admin links it on the Plans screen'],
        'L2 a record with ended_at_ts set and ended_at null is ended: the plan is not linked (the date strings are never read by the active rule)');
    $pg = qfu_l_page($c, $dA, 'subscription_plans.php');
    $c->t(strpos(qfu_l_cell($pg['html'], 'plan', $R['plan1']), 'not linked — Cash sales of it are refused') !== false, 'L2 … and its link column says not linked', qfu_l_cell($pg['html'], 'plan', $R['plan1']));

    // ── L3 the no-op rule (§R.1.4): a no-op only when the active record would itself resolve now ──────────────────────
    qfu_l_fake($c);
    $dN = qfu_l_seed($c, ['links' => $DEF]);
    $b = $links($dN);
    $r = qfu_l_link($c, $dN, 'plan', 1, 12, 6);
    $c->eq([$r['type'], $r['msg'], $links($dN)], ['info', $R['plan1'] . ' is already linked to uCRM product #12 (' . $RES . ') and service plan #6 (' . $RES . ') — nothing was changed.', $b],
        'L3 the control: a current, resolving link relinked to the same product is a no-op — "already linked", no new record, none ended');
    $relinkCheck = function (string $dir, string $label, array $was, array $r, string $k = 'ug', array $rowsTo = []) use ($c, $links, $resolve, $j) {
        $now = (array)$links($dir);
        $act = qfu_l_active($now, 'plan', (int)($rowsTo['row'] ?? 1));
        $oldActive = array_column(qfu_l_active($was, 'plan', (int)($rowsTo['row'] ?? 1)), 'id');
        $endedNow = [];
        foreach ($now as $x) if (in_array($x['id'], $oldActive, true)) $endedNow[$x['id']] = [$x['ended_why'], $x['ended_at_ts'] !== null, $x['ended_at'] !== null];
        $res = $resolve($dir, 'plan', (int)($rowsTo['row'] ?? 1), $k);
        $c->eq([count($now) - count($was), $endedNow, count($act), $res['ok'] ?? null, strpos($r['msg'], 'already linked') === false, ($r['type'] ?? '')],
            [1, array_fill_keys($oldActive, ['relinked', true, true]), 1, true, true, 'success'],
            "L3 {$label}: a new record is written, every earlier active record ended (relinked), exactly one active, the item resolves, the flash is not \"already linked\"",
            $j([$r['msg'], $res]));
    };
    // (a) the row deleted with the gate off and re-added under the reused id: the stamp differs
    $dRa = qfu_l_seed($c, ['links' => [qfu_link(1, 'plan', 1, QFU_PLAN_STAMP, 12, 6), qfu_link(2, 'plan', 3, QFU_L_STAMP3, 12, 6)]]);
    $del = qfu_l_post($c, $dRa, ['action' => 'delete_plan', 'plan_id' => '3'], ['cfg' => qfu_l_cfg('ug_off')]);
    $add = qfu_l_post($c, $dRa, ['action' => 'save_plan', 'plan_id' => '0', 'plan_name' => 'Spare Plan (TEST)', 'customer_price' => '80',
                                 'starlink_cost' => '40', 'plan_active' => '1', 'plan_type' => 'starlink']);
    $p3 = qfu_l_rows_of($dRa, 'subscription_plans')[3] ?? [];
    $was = (array)$links($dRa);
    $c->t(($del['msg'] ?? '') === 'Plan deleted.' && ($p3['created_at'] ?? QFU_L_STAMP3) !== QFU_L_STAMP3 && count(qfu_l_active($was, 'plan', 3)) === 1,
        'L3 (a) the control: plan row 3 deleted with the gate off (its link left active) and a new row added under the reused id 3, with a new stamp',
        $j([$del['msg'], $add['msg'], $p3]));
    $rep = $resolve($dRa, 'plan', 3);
    $c->eq($rep['reason'] ?? null, 'Spare Plan (TEST) was linked before this plugin row was replaced — an admin links it again',
        'L3 (a) … a reused id is caught by its stamp: the old link is not used ("replaced")');
    $pk = qfu_l_page($c, $dRa, 'subscription_plans.php', ['ucrm_link_plan' => '3']);
    $radios = qfu_l_radios(qfu_l_panel($pk['html']));
    $checked = [];
    foreach ($radios as $nm => $vals) foreach ($vals as $v => $st) if ($st[0]) $checked[] = "{$nm}={$v}";
    $c->eq([count($radios['product_id'] ?? []) > 0, $checked], [true, []], 'L3 (a) … the picker lists the products and pre-selects nothing for the replaced row');
    $r = qfu_l_link($c, $dRa, 'plan', 3, 12, 6);
    $relinkCheck($dRa, '(a) relinked to the same product', $was, $r, 'ug', ['row' => 3]);
    // (b) the quoting organization changed (the setting 2), the fake uCRM returning no organizationId
    $dRb = qfu_l_seed($c, ['links' => $DEF]);
    $was = (array)$links($dRb);
    $c->eq($resolve($dRb, 'plan', 1, 'ug2')['reason'] ?? null, 'Home Plan (TEST) was linked for another quoting organization — an admin links it again',
        'L3 (b) the control: under the quoting organization 2 the link made under 1 is not used');
    $r = qfu_l_link($c, $dRb, 'plan', 1, 12, 6, ['cfg' => qfu_l_cfg('ug2')]);
    $relinkCheck($dRb, '(b) relinked to the same product under the new organization', $was, $r, 'ug2');
    $nb = (array)$links($dRb);
    $c->eq((int)(end($nb)['organization_id'] ?? 0), 2, 'L3 (b) … and the fresh record carries the organization in force, 2');
    // (c) = L-two-active: two hand-seeded active records, the first naming the chosen product
    $dRc = qfu_l_seed($c, ['links' => [qfu_link(1, 'plan', 1, QFU_PLAN_STAMP, 12, 6), qfu_link(2, 'plan', 1, QFU_PLAN_STAMP, 13, 6), qfu_link(3, 'kit', 3, QFU_KIT_STAMP, 55)]]);
    $was = (array)$links($dRc);
    $c->eq([count(qfu_l_active($was, 'plan', 1)), $resolve($dRc, 'plan', 1)['reason'] ?? null], [2, 'Home Plan (TEST) has two uCRM links recorded — an admin links it again'],
        'L3 (c) L-two-active, the control: two active records for plan row 1 (one naming product 12) — treated as no link at all');
    $r = qfu_l_link($c, $dRc, 'plan', 1, 12, 6);
    $relinkCheck($dRc, '(c) L-two-active, saved to the product one of them names', $was, $r);

    // ── L4 the write under withLock, the uCRM read outside it (§R.1.4): L-race, L-row-changed, L-rule8-crosswise ────────
    $dW = qfu_l_seed($c, ['links' => [], 'apps' => []]);
    $ctl = qfu_l_link($c, $dW, 'kit', 3, 55);
    $c->t($ok($ctl) && ($ctl['hook'] ?? null) === [], 'L4 the control: with no other writer the save links (no hook planted)', $ctl['msg']);
    $hookRace = '$pdo2->prepare("INSERT INTO kyc_applications (id, data) VALUES (?, ?)")->execute([900, '
              . var_export(json_encode(['id' => 900, 'firstname' => 'Test', 'lastname' => 'Race', 'status' => 'new']), true) . ']);';
    $was = (array)$links($dW);
    $r = qfu_l_link($c, $dW, 'kit', 3, 54, null, ['hook' => $hookRace]);
    $now = (array)$links($dW);
    $apps = (array)qfu_l_rows_of($dW, 'kyc_applications');
    $c->t(!empty($r['hook'][0]['ok']) && isset($apps[900]), 'L4 L-race, the control: another connection committed a write to kyc_applications while uCRM answered the products read',
        $j([$r['hook'] ?? null, array_keys($apps)]));
    $either = ($ok($r) && count(qfu_l_active($now, 'kit', 3)) === 1) || ($r['msg'] === QFU_L_RETRY && $now === $was);
    $c->t($either, 'L4 L-race: the save either links with exactly one active record, or refuses with the try-again text and changes nothing', $j([$r['msg'], $now]));
    $c->eq([$ok($r), count(qfu_l_active($now, 'kit', 3)), (int)(qfu_l_active($now, 'kit', 3)[0]['product_id'] ?? 0)], [true, 1, 54],
        'L4 L-race … and, the read being outside the lock, it links: the lock\'s snapshot is taken after the other write committed');
    // L-row-changed: the row deleted and re-added (same id, a new stamp) between the validation and the lock
    $hookRow = '$pdo2->beginTransaction(); $pdo2->exec("DELETE FROM subscription_plans WHERE id = 1");'
             . ' $pdo2->prepare("INSERT INTO subscription_plans (id, data) VALUES (?, ?)")->execute([1, '
             . var_export(json_encode(['id' => 1, 'name' => 'Home Plan (TEST)', 'type' => 'starlink', 'customer_price' => 90, 'is_active' => true,
                                       'created_at' => '2026-10-28 08:00:00']), true) . ']); $pdo2->commit();';
    $was = (array)$links($dW);
    $r = qfu_l_link($c, $dW, 'plan', 1, 12, 6, ['hook' => $hookRow]);
    $p1 = qfu_l_rows_of($dW, 'subscription_plans')[1] ?? [];
    $c->t(!empty($r['hook'][0]['ok']) && ($p1['created_at'] ?? '') === '2026-10-28 08:00:00', 'L4 L-row-changed, the control: the plan row was replaced (a new stamp) while uCRM answered', $j($r['hook'] ?? null));
    $c->eq([$r['type'], $r['msg'], $links($dW)], ['danger', 'The plan changed while it was being linked — nothing was linked; try again.', $was],
        'L4 L-row-changed: the save refuses inside the lock and writes nothing');
    // L-rule8-crosswise: while the installation's save reads uCRM, another connection commits a kit link to that product
    $dX = qfu_l_seed($c, ['links' => []]);
    $kitRec = qfu_link(50, 'kit', 4, QFU_L_STAMP4, 56, null, ['row_name' => 'Mini Kit (TEST) (plugin)', 'product_name' => 'Mini Kit (TEST)']);
    $hookX = '$pdo2->prepare("INSERT INTO ucrm_quote_links (id, data) VALUES (?, ?)")->execute([50, ' . var_export(json_encode($kitRec), true) . ']);';
    $r = qfu_l_link($c, $dX, 'install', 0, 56, null, ['hook' => $hookX]);
    $now = (array)$links($dX);
    $c->t(!empty($r['hook'][0]['ok']), 'L4 L-rule8-crosswise, the control: another connection committed kit row 4\'s link to product 56 during the installation\'s read', $j($r['hook'] ?? null));
    $c->eq([$r['type'], $r['msg'], $now], ['danger', $say('Mini Kit (TEST) is linked to the hardware row Mini Kit (TEST) (plugin), so the installation cannot be linked to it'), [$kitRec]],
        'L4 L-rule8-crosswise: refused inside the lock with rule 8\'s text, nothing written (only the other connection\'s record)');
    $dX2 = qfu_l_seed($c, ['links' => []]);
    $r = qfu_l_link($c, $dX2, 'install', 0, 56);
    $c->eq([$r['msg'], count(qfu_l_active($links($dX2), 'install', 0))], [$linked('Installation', 56, 'Mini Kit (TEST)'), 1],
        'L4 L-rule8-crosswise, the control: with no crosswise write the installation is linked to the same product');
    // a lock that fails: nothing linked / unlinked — try again, and nothing changed
    $dF = qfu_l_seed($c, ['links' => $DEF]);
    $was = $links($dF);
    $r = qfu_l_link($c, $dF, 'kit', 3, 54, null, ['faults' => ['links_lock' => true]]);
    $r2 = qfu_l_unlink($c, $dF, 'kit', 3, ['faults' => ['links_lock' => true]]);
    $c->eq([$r['msg'], $r2['msg'], $links($dF)], [QFU_L_RETRY, QFU_L_URETRY, $was],
        'L4 the lock itself failing: the save answers "Nothing was linked — try again", unlink "Nothing was unlinked — try again", nothing changed');
    $r3 = qfu_l_unlink($c, $dF, 'kit', 3);
    $c->t($ok($r3) || $r3['type'] === 'warning', 'L4 … the control: the same unlink with the lock working ends the record', $r3['msg']);

    // ── L5 unlink and delete over two active records; deletion and stamps (§R.1.3, §R.1.4) ─────────────────────────────
    $two = [qfu_link(1, 'plan', 1, QFU_PLAN_STAMP, 12, 6), qfu_link(2, 'plan', 1, QFU_PLAN_STAMP, 13, 6),
            qfu_link(3, 'kit', 4, QFU_L_STAMP4, 56), qfu_link(4, 'kit', 4, QFU_L_STAMP4, 54)];
    $one = [qfu_link(1, 'plan', 1, QFU_PLAN_STAMP, 12, 6), qfu_link(3, 'kit', 4, QFU_L_STAMP4, 56)];
    $whys = function (array $recs, string $item, int $rowId): array {
        $o = [];
        foreach ($recs as $x) if (($x['item'] ?? '') === $item && (int)($x['row_id'] ?? -1) === $rowId) $o[(int)$x['id']] = [$x['ended_why'], $x['ended_at_ts'] !== null];
        return $o;
    };
    $dU1 = qfu_l_seed($c, ['links' => $one]);
    $r = qfu_l_unlink($c, $dU1, 'plan', 1);
    $c->eq([$r['msg'], $whys((array)$links($dU1), 'plan', 1)],
        ['Home Plan (TEST) is no longer linked to uCRM. Cash sales of it are refused until it is linked again. 0 application(s) were quoted under the previous link (0 Cash, 0 Credit). Review them in uCRM. <a href="?page=dashboard&amp;tab=applications&amp;quote_link=1">Orders quoted under link #1</a>',
         [1 => ['unlinked', true]]], 'L5 the control: unlink over one active record ends it (unlinked)');
    $dU2 = qfu_l_seed($c, ['links' => $two]);
    $r = qfu_l_unlink($c, $dU2, 'plan', 1);
    $c->eq([$whys((array)$links($dU2), 'plan', 1), count(qfu_l_active($links($dU2), 'plan', 1)), $whys((array)$links($dU2), 'kit', 4)],
        [[1 => ['unlinked', true], 2 => ['unlinked', true]], 0, [3 => [null, false], 4 => [null, false]]],
        'L5 unlink over two active records ends both (unlinked); none of the item remains active; another item is untouched');
    $dD1 = qfu_l_seed($c, ['links' => $one]);
    $r = qfu_l_post($c, $dD1, ['action' => 'delete_plan', 'plan_id' => '1']);
    $c->eq([$r['msg'], $whys((array)$links($dD1), 'plan', 1)], ['Plan deleted.', [1 => ['row deleted', true]]],
        'L5 the control: deleting a plan with the gate on ends its one active record (row deleted)');
    $dD2 = qfu_l_seed($c, ['links' => $two]);
    $r = qfu_l_post($c, $dD2, ['action' => 'delete_plan', 'plan_id' => '1']);
    $c->eq([$r['msg'], $whys((array)$links($dD2), 'plan', 1), count(qfu_l_active($links($dD2), 'plan', 1))],
        ['Plan deleted.', [1 => ['row deleted', true], 2 => ['row deleted', true]], 0], 'L5 a delete over two active records ends both (row deleted)');
    $r = qfu_l_post($c, $dD2, ['action' => 'delete_hardware', 'hw_id' => '4']);
    $c->eq([$r['msg'], $whys((array)$links($dD2), 'kit', 4)], ['Hardware deleted.', [3 => ['row deleted', true], 4 => ['row deleted', true]]],
        'L5 … and deleting the kit row ends both of its active records');

    // ── L6 the two actions: an admin, judged on the fresh account record; absent with the gate off (§R.2.1) ────────────
    $acts = [
        'ucrm_link_save'  => function (string $dir, array $o = []) use ($c) { return qfu_l_link($c, $dir, 'kit', 3, 54, null, $o); },
        'ucrm_link_clear' => function (string $dir, array $o = []) use ($c) { return qfu_l_unlink($c, $dir, 'kit', 3, $o); },
    ];
    foreach ($acts as $act => $go) {
        $d = qfu_l_seed($c, ['links' => $DEF]);
        $r = $go($d);
        $c->t(in_array($r['type'], ['success', 'warning'], true) && $r['requests'] !== null, "L6 {$act}, the control: a fresh admin is accepted", $r['msg']);
        $was = $links($d);
        $r = $go($d, ['session' => QFU_L_AGENT]);
        $c->eq([$r['code'], strpos((string)($r['buffer'] ?? ''), QFU_L_DENIED) !== false, $r['msg'], $r['location'], $r['requests'], $links($d)],
            [403, true, '', null, [], $was], "L6 {$act}: a non-admin is answered 403, the store unchanged, uCRM not asked");
        $fresh = [
            'the stored row demoted (is_admin false), the session copy an admin seconds old' => ['retailers' => [['is_admin' => false] + QFU_L_ADMIN, QFU_L_AGENT], 'age' => 5],
            'the stored row inactive (is_active false)' => ['retailers' => [['is_active' => false] + QFU_L_ADMIN, QFU_L_AGENT], 'age' => 5],
            'the row deleted: findOne null, currentRetailer falling back to the session cache' => ['retailers' => [QFU_L_AGENT], 'age' => 400],
            'the account read throwing' => ['retailers' => [QFU_L_ADMIN, QFU_L_AGENT], 'age' => 5, 'faults' => ['find_retailer' => true]],
        ];
        foreach ($fresh as $what => $f) {
            $d = qfu_l_seed($c, ['links' => $DEF, 'retailers' => $f['retailers']]);
            $was = $links($d);
            $r = $go($d, ['session' => QFU_L_ADMIN, 'session_age' => $f['age']] + (isset($f['faults']) ? ['faults' => $f['faults']] : []));
            $c->eq([$r['code'], strpos((string)($r['buffer'] ?? ''), QFU_L_DENIED) !== false, $r['msg'], $r['requests'], $links($d)], [403, true, '', [], $was],
                "L6 {$act}, {$what}: refused at once (403), the store unchanged");
        }
        $d = qfu_l_seed($c, ['links' => $DEF]);
        $r = $go($d, ['session' => QFU_L_ADMIN, 'session_age' => 400]);
        $c->t(in_array($r['type'], ['success', 'warning'], true), "L6 {$act}, the control: a session copy 400 s old over a stored admin row is accepted (the fallback alone refuses nothing)", $r['msg']);
        foreach (['ss' => 'South Sudan', 'ug_off' => 'Uganda with the setting 0'] as $k => $where) {
            $d = qfu_l_seed($c, ['k' => $k, 'links' => null]);
            $r = $go($d, ['session' => QFU_L_AGENT]);
            $c->eq([$r['end'], $r['code'], $r['msg'], $r['location'], $r['requests'], in_array('ucrm_quote_links', qfu_l_tables($d), true)],
                ['fell through', false, '', null, [], false], "L6 {$act} on {$where}: absent — not handled (an unknown action), no 403, nothing read or written");
        }
    }

    // ── L7 the security check, made by each action itself (§R.2.1); the global gate skipped by cb_action ───────────────
    $EVIL = 'https://evil.example.test';
    $THIS = 'https://crm.example.test';
    foreach (['ucrm_link_save', 'ucrm_link_clear'] as $act) {
        $dG = qfu_l_seed($c, ['links' => $DEF]);
        $send = function (array $o, array $more = []) use ($c, $dG, $act) {
            return $act === 'ucrm_link_save' ? qfu_l_link($c, $dG, 'kit', 3, 56, null, $o + ['more' => $more]) : qfu_l_unlink($c, $dG, 'kit', 3, $o + ['more' => $more]);
        };
        $was = $links($dG);
        $g = $send(['token' => false, 'headers' => ['HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_ORIGIN' => $THIS]]);
        $c->eq([$g['msg'], $links($dG), $g['requests']], ['Security token mismatch. Please try again.', $was, []],
            "L7 {$act}, the control: with no _csrf and no tab-action field the global gate itself refuses");
        $cases = [
            '(i) no _csrf, cb_action=x, Sec-Fetch-Site same-origin' => [['token' => false, 'headers' => ['HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_ORIGIN' => $THIS]], ['cb_action' => 'x']],
            '(i) no _csrf, sc_action=x, Sec-Fetch-Site same-origin' => [['token' => false, 'headers' => ['HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_ORIGIN' => $THIS]], ['sc_action' => 'x']],
            '(ii) a valid token, Sec-Fetch-Site cross-site' => [['headers' => ['HTTP_SEC_FETCH_SITE' => 'cross-site']], []],
            '(ii) a valid token, Sec-Fetch-Site same-site' => [['headers' => ['HTTP_SEC_FETCH_SITE' => 'same-site']], []],
            '(iii) a valid token, no Sec-Fetch-Site, an Origin of another host' => [['headers' => ['HTTP_ORIGIN' => $EVIL]], []],
            '(iii) a valid token, no Sec-Fetch-Site, Origin: null' => [['headers' => ['HTTP_ORIGIN' => 'null']], []],
            '(iv) a valid token, no Sec-Fetch-Site or Origin, a Referer of another host' => [['headers' => ['HTTP_REFERER' => $EVIL . '/?page=dashboard']], []],
            '(v) a valid token and none of the three headers' => [['headers' => []], []],
        ];
        foreach ($cases as $what => [$o, $more]) {
            $was = $links($dG);
            $r = $send($o, $more);
            $c->eq([$r['msg'], $r['type'], $links($dG), $r['requests']], [QFU_L_SECURITY, 'danger', $was, []],
                "L7 {$act} {$what}: refused with the one fixed flash, the store unchanged, no request to uCRM");
        }
        $accept = [
            'a valid token and Sec-Fetch-Site same-origin' => ['HTTP_SEC_FETCH_SITE' => 'same-origin'],
            'no Sec-Fetch-Site, an Origin naming this host' => ['HTTP_ORIGIN' => $THIS],
            'only a Referer naming this host' => ['HTTP_REFERER' => $THIS . '/?page=dashboard&tab=hardware'],
        ];
        $i = 0;
        foreach ($accept as $what => $hd) {
            $d = $act === 'ucrm_link_save' ? $dG : qfu_l_seed($c, ['links' => $DEF]);
            $r = $act === 'ucrm_link_save' ? qfu_l_link($c, $d, 'kit', 3, [54, 55, 54][$i++], null, ['headers' => $hd])
                                           : qfu_l_unlink($c, $d, 'kit', 3, ['headers' => $hd]);
            $c->t(in_array($r['type'], ['success', 'warning'], true) && $r['msg'] !== QFU_L_SECURITY, "L7 {$act}, the control accepted: {$what}", $r['msg']);
        }
    }

    // ── L8 every §R.3.1 refusal writes nothing, each beside its accepted control ───────────────────────────────────────
    qfu_l_fake($c, qfu_l_products([58 => qfu_product(58, 'Label Ok Kit (TEST)', 500, ['invoiceLabel' => 'LABEL OK KIT (TEST)'])]));
    $dR = qfu_l_seed($c, ['links' => [qfu_link(3, 'install', 0, '', 70)]]);
    $try = function (string $label, string $item, int $row, int $pid, ?int $spid, string $want, array $o = []) use ($c, $dR, $links) {
        $was = $links($dR);
        $r = qfu_l_link($c, $dR, $item, $row, $pid, $spid, $o);
        $c->eq([$r['type'], $r['msg'], $links($dR)], ['danger', $want, $was], "L8 {$label}: refused with its reason, nothing written");
        return $r;
    };
    $accepted = function (string $label, string $item, int $row, int $pid, ?int $spid = null, array $o = []) use ($c, $dR, $links) {
        $r = qfu_l_link($c, $dR, $item, $row, $pid, $spid, $o);
        $a = qfu_l_active($links($dR), $item, $item === 'install' ? 0 : $row);
        $c->t(in_array($r['type'], ['success', 'info'], true) && count($a) === 1 && (int)$a[0]['product_id'] === $pid,
            "L8 {$label}: the control accepted — linked to product #{$pid}", $r['msg']);
    };
    // rule 2: the row in its own table, not Fiber
    $accepted('rule 2, the control', 'kit', 3, 55);
    $try('rule 2, a plan row that is not in the table', 'plan', 9, 12, 6, 'Nothing was linked. Plan #9 is not on this screen any more.');
    $try('rule 2, a Fiber plan row', 'plan', 2, 12, 6, $say('Fiber is not priced from uCRM on this installation'));
    $try('rule 2, a Fiber kit row', 'kit', 5, 56, null, $say('Fiber is not priced from uCRM on this installation'));
    // rule 3: a fresh read, unreadable
    $c->ctl('kyc', '/__test/set', ['products_down' => true]);
    $try('rule 3, uCRM\'s products unreadable', 'kit', 3, 54, null, "uCRM's products could not be read — nothing was linked.");
    $c->ctl('kyc', '/__test/set', ['products_down' => false]);
    $accepted('rule 3, the control (readable again)', 'kit', 3, 54);
    // rule 4: among the counted products
    $try('rule 4, a product uCRM does not list', 'kit', 3, 999, null, 'uCRM does not list product #999 — nothing was linked.');
    $try('rule 4, an archived product', 'kit', 3, 80, null, 'uCRM product #80 is archived in uCRM — nothing was linked.');
    $try('rule 4, an inactive product', 'kit', 3, 81, null, 'uCRM product #81 is not active in uCRM — nothing was linked.');
    $try('rule 4, another organization\'s product', 'kit', 3, 82, null, 'uCRM product #82 is another organization\'s in uCRM — nothing was linked.');
    $accepted('rule 4, the control: a product of the quoting organization 1', 'kit', 3, 57);
    // rule 5: badProduct
    $K3 = $R['kit3'];
    $try('rule 5, taxable', 'kit', 3, 83, null, $say("{$K3} is set as taxable in uCRM; the cash would not equal the quotation's total"));
    $try('rule 5, a taxId', 'kit', 3, 84, null, $say("{$K3} is set as taxable in uCRM; the cash would not equal the quotation's total"));
    $try('rule 5, a price of 0', 'kit', 3, 85, null, $say("{$K3}'s price in uCRM is 0"));
    $try('rule 5, more than 2 decimals', 'kit', 3, 86, null, $say("{$K3}'s price in uCRM has more than 2 decimals"));
    $try('rule 5, an invoice label that differs', 'kit', 3, 87, null, $say("{$K3}'s invoice label in uCRM differs from its name"));
    $try('rule 5, no price', 'kit', 3, 88, null, $say("{$K3} has no price in uCRM"));
    $accepted('rule 5, the control: an invoice label keyed like the name', 'kit', 3, 58);
    // rule 6 (a plan): the service plan
    $P1 = $R['plan1'];
    $try('rule 6, a service plan uCRM does not list', 'plan', 1, 12, 999, 'uCRM does not list service plan #999 — nothing was linked.');
    $try('rule 6, an archived service plan', 'plan', 1, 29, 18, 'uCRM service plan #18 is archived in uCRM — nothing was linked.');
    $try('rule 6, an inactive service plan', 'plan', 1, 30, 19, 'uCRM service plan #19 is not active in uCRM — nothing was linked.');
    $try('rule 6, another organization\'s service plan', 'plan', 1, 31, 20, 'uCRM service plan #20 is another organization\'s in uCRM — nothing was linked.');
    $twin = $say("{$P1}'s uCRM service plan is spelled like another service plan in uCRM (active or not), so the quotation's WhatsApp summary could show the other one's monthly price — an admin renames one in uCRM");
    $try('rule 6(a), another counted service plan shares the key', 'plan', 1, 23, 9, $twin);
    $try('rule 6(a), an archived service plan shares the key', 'plan', 1, 24, 11, $twin);
    $try('rule 6(a), another organization\'s service plan shares the key', 'plan', 1, 25, 13, $twin);
    $try('rule 6, no 1-month period', 'plan', 1, 27, 16, $say("{$P1} has no 1-month price in uCRM"));
    $try('rule 6, two 1-month periods', 'plan', 1, 28, 17, $say("{$P1} has no 1-month price in uCRM"));
    $try('rule 6(b), the service plan\'s own price not its 1-month price', 'plan', 1, 26, 15,
        $say("{$P1}'s uCRM service plan carries a price that is not its 1-month price, so the quotation's WhatsApp summary could show the wrong monthly price — an admin fixes the service plan in uCRM"));
    $try('rule 6, the product\'s price not the 1-month price', 'plan', 1, 21, 7, $say("{$P1}'s price in Products (120) differs from its service plan (110)"));
    $try('rule 6, product and service plan spelled apart', 'plan', 1, 22, 8,
        $say("{$P1}'s uCRM product and service plan are not spelled alike, so the quotation's WhatsApp summary could not show it as monthly — an admin fixes the names in uCRM"));
    $accepted('rule 6, the control', 'plan', 1, 12, 6);
    // each rule-6 refusal beside its control: the duplicate renamed, the price or the spelling fixed
    qfu_l_fake($c, qfu_l_products([58 => qfu_product(58, 'Label Ok Kit (TEST)', 500)]), qfu_l_plans([
        10 => ['name' => 'Twin Plan Two (TEST)'], 12 => ['name' => 'Promo Plan Old (TEST)'], 14 => ['name' => 'Corp Plan Other (TEST)'],
        15 => ['price' => 180], 7 => ['periods' => [['period' => 1, 'price' => 120, 'enabled' => true]]], 8 => ['name' => 'Family Pack (TEST)'],
        16 => ['periods' => [['period' => 1, 'price' => 190, 'enabled' => true]]]]));
    $accepted('rule 6(a), the control: the counted twin renamed', 'plan', 1, 23, 9);
    $accepted('rule 6(a), the control: the archived twin renamed', 'plan', 1, 24, 11);
    $accepted('rule 6(a), the control: another organization\'s twin renamed', 'plan', 1, 25, 13);
    $accepted('rule 6(b), the control: its own price equal to its 1-month price', 'plan', 1, 26, 15);
    $accepted('rule 6, the control: the 1-month price equal to the product\'s', 'plan', 1, 21, 7);
    $accepted('rule 6, the control: spelled alike', 'plan', 1, 22, 8);
    $accepted('rule 6, the control: a 1-month period', 'plan', 1, 27, 16);
    // rule 6(c): 200 service plans or more
    $fill = function (int $n): array {
        $p = qfu_l_plans();
        for ($i = count($p) + 1; $i <= $n; $i++) $p[] = ['id' => 1000 + $i, 'name' => 'Filler ' . $i . ' (TEST)', 'periods' => [['period' => 1, 'price' => 1, 'enabled' => true]]];
        return $p;
    };
    qfu_l_fake($c, null, $fill(200));
    $try('rule 6(c), uCRM lists 200 service plans', 'plan', 1, 90, 21,
        $say('uCRM lists 200 service plans or more, more than the quotation\'s WhatsApp summary reads — an admin removes unused service plans in uCRM'));
    qfu_l_fake($c, null, $fill(199));
    $accepted('rule 6(c), the control: 199 service plans', 'plan', 1, 90, 21);
    // rule 7: a kit or the installation spelled like a service plan, counted or not
    qfu_l_fake($c);
    $like = $say("{$K3} is spelled like a service plan in uCRM, so it cannot be quoted as hardware");
    $try('rule 7, a kit spelled like a counted service plan', 'kit', 3, 12, null, $like);
    $try('rule 7, a kit spelled like an archived service plan', 'kit', 3, 29, null, $like);
    $try('rule 7, a kit spelled like an inactive service plan', 'kit', 3, 30, null, $like);
    $try('rule 7, a kit spelled like another organization\'s service plan', 'kit', 3, 31, null, $like);
    $try('rule 7, the installation spelled like an archived service plan', 'install', 0, 29, null, $say('Installation is spelled like a service plan in uCRM, so it cannot be quoted as hardware'));
    qfu_l_fake($c, null, qfu_l_plans([6 => ['name' => 'Residential Standard Renamed (TEST)'], 18 => ['name' => 'Archived Other (TEST)'],
                                      19 => ['name' => 'Inactive Other (TEST)'], 20 => ['name' => 'Other Org Other (TEST)']]));
    $accepted('rule 7, the control: the service plan renamed (counted)', 'kit', 3, 12);
    $accepted('rule 7, the control: the archived one renamed', 'kit', 4, 29);
    $accepted('rule 7, the control: the inactive one renamed', 'kit', 3, 30);
    $accepted('rule 7, the control: another organization\'s renamed', 'kit', 4, 31);
    $accepted('rule 7, the control: the installation', 'install', 0, 71);
    // rule 8: the installation's product and an offered kit's
    qfu_l_fake($c);
    $dE = qfu_l_seed($c, ['links' => [qfu_link(2, 'kit', 3, QFU_KIT_STAMP, 55), qfu_link(3, 'install', 0, '', 70)]]);
    $was = $links($dE);
    $r = qfu_l_link($c, $dE, 'kit', 4, 70);
    $c->eq([$r['msg'], $links($dE)], [$say('Professional Installation is the installation\'s uCRM product, so a hardware row cannot be linked to it. If Mini Kit (TEST) (plugin) is the installation itself, deactivate it'), $was],
        'L8 rule 8: an offered kit linked to the installation\'s product is refused, naming the other item, nothing written');
    $r = qfu_l_link($c, $dE, 'install', 0, 55);
    $c->eq([$r['msg'], $links($dE)], [$say('Standard Kit (TEST) is linked to the hardware row Standard Kit (TEST), so the installation cannot be linked to it'), $was],
        'L8 rule 8: the installation linked to an offered kit\'s product is refused, naming the kit row, nothing written');
    $r = qfu_l_link($c, $dE, 'kit', 4, 56);
    $r2 = qfu_l_link($c, $dE, 'install', 0, 71);
    $c->t($ok($r) && $ok($r2), 'L8 rule 8, the controls: the kit linked to another product, the installation to one no offered kit has', $j([$r['msg'], $r2['msg']]));
    // 5.18.102 D14 review round 2 (FX-money-1, FX-flows-1): the kit half of rule 8 at save too, whatever the row's offered
    // state, as kitLine applies it at use — an inactive kit row is refused the installation's product at the save, in its
    // picker and inside the lock, and is never told to deactivate itself; the installation's own save is unchanged
    $devI = $rows['devices'];
    foreach ($devI as $iI => $dvI) if ((int)($dvI['id'] ?? 0) === 4) $devI[$iI]['is_active'] = false;
    $R8I = $say("Professional Installation is the installation's uCRM product, so a hardware row cannot be linked to it");
    $dI = qfu_l_seed($c, ['links' => [qfu_link(3, 'install', 0, '', 70)], 'devices' => $devI]);
    $wasI = $links($dI);
    $c->eq([(qfu_l_rows_of($dI, 'kyc_devices')[4] ?? [])['is_active'] ?? 'absent', count(qfu_l_active($wasI, 'install', 0))], [false, 1],
        'L8 rule 8, an inactive kit row, the control: kit row 4 is stored inactive and the installation is linked (to product 70)');
    $r = qfu_l_link($c, $dI, 'kit', 4, 70);
    $c->eq([$r['type'], $r['msg'], $r['requests'] !== [], $links($dI)], ['danger', $R8I, true, $wasI],
        'L8 rule 8: an inactive kit row linked to the installation\'s product is refused too (no "deactivate it"), nothing written');
    $r = qfu_l_link($c, $dI, 'kit', 4, 56);
    $c->t($ok($r) && count(qfu_l_active($links($dI), 'kit', 4)) === 1, 'L8 rule 8, the control: the inactive kit row linked to another product is accepted', $j($r['msg']));
    $hrI = qfu_l_radios(qfu_l_panel(qfu_l_page($c, $dI, 'hardware.php', ['ucrm_link_hw' => '4'])['html']));
    $c->eq([$hrI['product_id'][70][1] ?? 'absent', $hrI['product_id'][56][1] ?? 'absent'], [true, false],
        'L8 rule 8: in an inactive kit row\'s picker the installation\'s product is disabled; another is not');
    // … and inside the lock: another connection commits the installation's link to 70 during the inactive kit's uCRM read
    $dIX = qfu_l_seed($c, ['links' => [], 'devices' => $devI]);
    $insI = qfu_link(60, 'install', 0, '', 70, null, ['row_name' => 'Installation', 'product_name' => 'Professional Installation']);
    $hookI = '$pdo2->prepare("INSERT INTO ucrm_quote_links (id, data) VALUES (?, ?)")->execute([60, ' . var_export(json_encode($insI), true) . ']);';
    $r = qfu_l_link($c, $dIX, 'kit', 4, 70, null, ['hook' => $hookI]);
    $c->t(!empty($r['hook'][0]['ok']), 'L8 rule 8, crosswise, the control: another connection committed the installation\'s link to product 70 during the inactive kit\'s read', $j($r['hook'] ?? null));
    $c->eq([$r['type'], $r['msg'], (array)$links($dIX)], ['danger', $R8I, [$insI]],
        'L8 rule 8, crosswise: an inactive kit row\'s save is refused inside the lock, nothing written (only the other connection\'s record)');

    // ── L9 a fresh read, never the page cache (§R.3.1 rule 3, §R.2.3) ──────────────────────────────────────────────────
    qfu_l_fake($c);
    $dC = qfu_l_seed($c, ['links' => $DEF]);
    qfu_l_page($c, $dC, 'hardware.php');   // the page's forPage() writes its 60 s cache, product 56 counted in it
    $cache = json_decode((string)@file_get_contents($dC . '/ucrm_quote_catalogue_cache.json'), true);
    $inCache = in_array(56, array_map(function ($p) { return (int)($p['id'] ?? 0); }, (array)($cache['products'] ?? [])), true);
    $c->t(!empty($cache['ok']) && $inCache && (time() - (int)($cache['at'] ?? 0)) < 60, 'L9 the control: a fresh page cache holds product 56 as counted', $j(array_keys((array)$cache)));
    $c->ctl('kyc', '/__test/set', ['products' => qfu_l_products([56 => ['archived' => true]])]);
    $was = $links($dC);
    $r = qfu_l_link($c, $dC, 'kit', 4, 56);
    $c->eq([$r['msg'], $links($dC), in_array('GET /products', $r['requests'], true)], ['uCRM product #56 is archived in uCRM — nothing was linked.', $was, true],
        'L9 uCRM now says 56 is archived: the save reads uCRM afresh and refuses, though the page cache still counts it');
    $pk = qfu_l_panel(qfu_l_req($c, $dC, ['entry' => 'page', 'file' => 'hardware.php', 'get' => ['ucrm_link_hw' => '4'], 'admin' => true, 'session' => QFU_L_ADMIN])['html'] ?? '');
    $c->t($pk !== '' && !isset(qfu_l_radios($pk)['product_id'][56]) && isset(qfu_l_radios($pk)['product_id'][55]),
        'L9 … and the picker, drawn with the cache still in place, lists 56 no more (its own fresh read)', substr($pk, 0, 300));
    // the reverse: the cache says archived, uCRM says counted
    $dC2 = qfu_l_seed($c, ['links' => $DEF]);
    qfu_l_page($c, $dC2, 'hardware.php');
    $c->ctl('kyc', '/__test/set', ['products' => qfu_l_products()]);
    $cache = json_decode((string)@file_get_contents($dC2 . '/ucrm_quote_catalogue_cache.json'), true);
    $c->t(isset($cache['excluded']['products'][56]), 'L9 the control: a fresh page cache holding 56 as archived', $j($cache['excluded'] ?? null));
    $r = qfu_l_link($c, $dC2, 'kit', 4, 56);
    $c->t($ok($r), 'L9 uCRM now counts 56: the save reads uCRM afresh and links, though the page cache says archived', $r['msg']);

    // ── L10 only the store written, never a plugin row (§R.1.2, §R.5) ──────────────────────────────────────────────────
    qfu_l_fake($c);
    $dO = qfu_l_seed($c, ['links' => [], 'apps' => []]);
    $b = qfu_l_dump($dO);
    $r = qfu_l_link($c, $dO, 'plan', 1, 12, 6);
    $r2 = qfu_l_link($c, $dO, 'kit', 3, 55);
    $a = qfu_l_dump($dO);
    $changed = [];
    foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $t) if (($a[$t] ?? 'none') !== ($b[$t] ?? 'none')) $changed[] = $t;
    $c->eq([$ok($r), $ok($r2), $changed], [true, true, ['ucrm_quote_links']], 'L10 a plan and a kit linked: of the whole store only ucrm_quote_links changed (the control: it did change)');
    $c->eq([$a['subscription_plans'][1]['ucrm_product_id'] ?? null, $a['subscription_plans'][1]['name'] ?? null, $a['kyc_devices'][3]['ucrm_product_id'] ?? null,
            $a['kyc_devices'][3]['title'] ?? null], [99, 'Home Plan (TEST)', 98, 'Standard Kit (TEST)'],
        'L10 … the plugin rows keep their names and the deliberately wrong ucrm_product_id 99 / 98: no id, no uCRM name copied');

    // ── L12 the judge: fromStore and fromRecords answer alike over every link state; the contract; the organization ────
    $SC = [
        'not linked'      => [],
        'resolves'        => $DEF,
        'row replaced'    => [qfu_link(1, 'plan', 1, '2026-09-01 08:00:00', 12, 6), qfu_link(2, 'kit', 3, '2026-09-01 08:00:00', 55), qfu_link(3, 'install', 0, '', 70)],
        'two active'      => [qfu_link(1, 'plan', 1, QFU_PLAN_STAMP, 12, 6), qfu_link(2, 'plan', 1, QFU_PLAN_STAMP, 12, 6), qfu_link(3, 'kit', 3, QFU_KIT_STAMP, 55),
                              qfu_link(4, 'kit', 3, QFU_KIT_STAMP, 54), qfu_link(5, 'install', 0, '', 70), qfu_link(6, 'install', 0, '', 71)],
        'other organization' => [qfu_link(1, 'plan', 1, QFU_PLAN_STAMP, 12, 6, ['organization_id' => 2]), qfu_link(2, 'kit', 3, QFU_KIT_STAMP, 55, null, ['organization_id' => 2]),
                                 qfu_link(3, 'install', 0, '', 70, null, ['organization_id' => 2])],
        'ended only'      => [qfu_ended($DEF[0], 'unlinked'), qfu_ended($DEF[1], 'unlinked'), qfu_ended($DEF[2], 'row deleted')],
        'relinked'        => [qfu_ended($DEF[0]), qfu_ended($DEF[1]), qfu_ended($DEF[2]), qfu_link(4, 'plan', 1, QFU_PLAN_STAMP, 90, 21), qfu_link(5, 'kit', 3, QFU_KIT_STAMP, 54),
                              qfu_link(6, 'install', 0, '', 71)],
        'product archived' => [$DEF[0], qfu_link(2, 'kit', 3, QFU_KIT_STAMP, 80), $DEF[2]],
        'product unlisted, taxable, spelled like a plan' => [qfu_link(1, 'plan', 1, QFU_PLAN_STAMP, 23, 9), qfu_link(2, 'kit', 3, QFU_KIT_STAMP, 999),
                              qfu_link(3, 'kit', 4, QFU_L_STAMP4, 83), qfu_link(4, 'install', 0, '', 12), qfu_link(5, 'plan', 3, QFU_L_STAMP3, 21, 7)],
        'spelled apart, no 1-month price' => [qfu_link(1, 'plan', 1, QFU_PLAN_STAMP, 22, 8), qfu_link(2, 'plan', 3, QFU_L_STAMP3, 27, 16), qfu_link(3, 'kit', 3, QFU_KIT_STAMP, 81)],
        'Fiber rows linked' => [qfu_link(1, 'plan', 2, '2026-10-01 09:00:30', 12, 6), qfu_link(2, 'kit', 5, '2026-10-01 09:07:00', 56)],
        'rule 8'          => [$DEF[0], $DEF[1], qfu_link(3, 'install', 0, '', 56), qfu_link(4, 'kit', 4, QFU_L_STAMP4, 56)],
        'rule 8, the kit row inactive'  => [$DEF[0], $DEF[1], qfu_link(3, 'install', 0, '', 56), qfu_link(4, 'kit', 4, QFU_L_STAMP4, 56)],
        'rule 8, the kit row Fiber'     => [$DEF[0], $DEF[1], qfu_link(3, 'install', 0, '', 56), qfu_link(4, 'kit', 4, QFU_L_STAMP4, 56)],
        'rule 8, another stamp'         => [$DEF[0], $DEF[1], qfu_link(3, 'install', 0, '', 56), qfu_link(4, 'kit', 4, '2026-09-01 08:00:00', 56)],
    ];
    $kitsFor = function (string $name) use ($rows): array {
        $d = $rows['devices'];
        if ($name === 'rule 8, the kit row inactive') $d[1]['is_active'] = false;
        if ($name === 'rule 8, the kit row Fiber') $d[1]['type'] = 'Fiber';
        return $d;
    };
    $scen = [];
    foreach ($SC as $name => $recs) $scen[$name] = ['links' => $recs, 'devices' => $kitsFor($name)];
    $dJ = qfu_l_seed($c, ['links' => null]);
    $jr = $c->child(str_replace('@@ARGS@@', var_export(json_encode(['sc' => $scen, 'plans' => $rows['plans']]), true), <<<'PHP'
$A = json_decode(@@ARGS@@, true);
$set = ['on' => true, 'id' => 1, 'source' => 'setting', 'error' => ''];
$cat = (new UcrmQuoteLines($crm, $set, UcrmQuoteLinks::fromRecords([], [])))->catalogue();
$names = function ($st) { return $st->getPdo()->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN); };
$ans = function (UcrmQuoteLinks $l, $st) use ($set, $cat) {
    $q = new UcrmQuoteLines(null, $set, $l, $cat);
    $o = [];
    foreach ($st->load('subscription_plans.json') as $r) $o['plan ' . $r['id']] = $q->planLine($r);
    foreach ($st->load('kyc_devices.json') as $r) $o['kit ' . $r['id']] = $q->kitLine($r);
    $o['install'] = $q->installLine();
    return $o;
};
$out = ['cat_ok' => !empty($cat['ok'])];
$i = 0;
foreach ($A['sc'] as $name => $sc) {
    $st = SqliteStore::create($dir . '/judge-' . (++$i));
    $st->save('subscription_plans.json', $A['plans']);
    $st->save('kyc_devices.json', $sc['devices']);
    $t0 = $names($st);
    $kits = $st->load('kyc_devices.json');
    $fr = UcrmQuoteLinks::fromRecords($sc['links'], $kits);
    $byRecords = $ans($fr, $st);
    $t1 = $names($st);
    $st->save('ucrm_quote_links.json', $sc['links']);
    $byStore = $ans(UcrmQuoteLinks::fromStore($st, $set), $st);
    $out['sc'][$name] = ['store' => $byStore, 'records' => $byRecords, 'no_table' => $t0 === $t1 && !in_array('ucrm_quote_links', $t1, true),
                         'kit_shape' => array_map(function ($k) { return [is_int($k['id'] ?? null), array_key_exists('title', $k)]; }, $kits)];
}
// the contract (E-5): fromStore with the gate off opens nothing; records() every record whole
$st = SqliteStore::create($dir . '/judge-off');
$st->save('kyc_devices.json', $A['sc']['rule 8']['devices']);
$t0 = $names($st);
$off = UcrmQuoteLinks::fromStore($st, ['on' => false, 'id' => null]);
$out['off'] = ['tables_same' => $t0 === $names($st), 'records' => $off->records(), 'judge' => $off->judge('install', null, 1)['state'],
               'rule8' => $off->rule8(56, 1)];
$onSt = SqliteStore::create($dir . '/judge-on');
$t0 = $names($onSt);
$on = UcrmQuoteLinks::fromStore($onSt, $set);
$out['on_created'] = !in_array('ucrm_quote_links', $t0, true) && in_array('ucrm_quote_links', $names($onSt), true);
$st = SqliteStore::create($dir . '/judge-records');
$st->save('ucrm_quote_links.json', $A['sc']['relinked']['links']);
$out['stored'] = $st->load('ucrm_quote_links.json');
$out['records'] = UcrmQuoteLinks::fromStore($st, $set)->records();
// the organization comes from the caller (F-4)
$st = SqliteStore::create($dir . '/judge-org');
$st->save('subscription_plans.json', $A['plans']);
$st->save('kyc_devices.json', $A['sc']['resolves']['devices']);
$st->save('ucrm_quote_links.json', $A['sc']['resolves']['links']);
$row = $st->findOne('subscription_plans.json', 'id', 1);
$fs1 = UcrmQuoteLinks::fromStore($st, $set);
$frr = UcrmQuoteLinks::fromRecords($st->load('ucrm_quote_links.json'), $st->load('kyc_devices.json'));
$set2 = ['on' => true, 'id' => 2, 'source' => 'setting', 'error' => ''];
$fs2 = UcrmQuoteLinks::fromStore($st, $set2);
$line = function (UcrmQuoteLinks $l, array $s) use ($cat, $row) { $q = new UcrmQuoteLines(null, $s, $l, $cat); $a = $q->planLine($row); return [$a['ok'], $a['reason']]; };
$out['org'] = [
    'fromStore' => [$fs1->judge('plan', $row, 1)['state'], $fs1->judge('plan', $row, 2)['state'], $line($fs1, $set), $line($fs1, $set2)],
    'fromRecords' => [$frr->judge('plan', $row, 1)['state'], $frr->judge('plan', $row, 2)['state'], $line($frr, $set), $line($frr, $set2)],
    'built under 2, asked with 1' => [$fs2->judge('plan', $row, 1)['state'], $fs2->judge('plan', $row, 2)['state']],
];
return $out;
PHP), ['dir' => $dJ, 'tree' => qfu_l_tree($c), 'cfg' => [], 'links' => null, 'plans' => [], 'devices' => []]);
    $jv = $jr['value'];
    $c->t(is_array($jv) && !empty($jv['cat_ok']) && count((array)($jv['sc'] ?? [])) === count($SC), 'L12 the control: the judge probe read the catalogue and ran every link state', substr($jr['out'] . $jr['errlog'], 0, 800));
    $NL = function (string $row, string $screen) { return "{$row} is not linked to a uCRM product yet — an admin links it on the {$screen} screen"; };
    $expect = [
        'not linked' => ['plan 1' => $NL($P1, 'Plans'), 'kit 3' => $NL($K3, 'Hardware'), 'install' => $NL('Installation', 'Hardware')],
        'resolves' => ['plan 1' => '', 'kit 3' => '', 'install' => ''],
        'row replaced' => ['plan 1' => "{$P1} was linked before this plugin row was replaced — an admin links it again", 'kit 3' => "{$K3} was linked before this plugin row was replaced — an admin links it again", 'install' => ''],
        'two active' => ['plan 1' => "{$P1} has two uCRM links recorded — an admin links it again", 'kit 3' => "{$K3} has two uCRM links recorded — an admin links it again", 'install' => 'Installation has two uCRM links recorded — an admin links it again'],
        'other organization' => ['plan 1' => "{$P1} was linked for another quoting organization — an admin links it again", 'kit 3' => "{$K3} was linked for another quoting organization — an admin links it again", 'install' => 'Installation was linked for another quoting organization — an admin links it again'],
        'ended only' => ['plan 1' => $NL($P1, 'Plans'), 'kit 3' => $NL($K3, 'Hardware'), 'install' => $NL('Installation', 'Hardware')],
        'relinked' => ['plan 1' => '', 'kit 3' => '', 'install' => ''],
        'product archived' => ['plan 1' => '', 'kit 3' => "{$K3} is linked to a uCRM product that is archived in uCRM — an admin links it again", 'install' => ''],
        'product unlisted, taxable, spelled like a plan' => [
            'plan 1' => "{$P1}'s uCRM service plan is spelled like another service plan in uCRM (active or not), so the quotation's WhatsApp summary could show the other one's monthly price — an admin renames one in uCRM",
            'kit 3' => "{$K3} is linked to a uCRM product that uCRM no longer lists — an admin links it again",
            'kit 4' => "Mini Kit (TEST) (plugin) is set as taxable in uCRM; the cash would not equal the quotation's total",
            'install' => 'Installation is spelled like a service plan in uCRM, so it cannot be quoted as hardware',
            'plan 3' => "Spare Plan (TEST)'s price in Products (120) differs from its service plan (110)"],
        'spelled apart, no 1-month price' => [
            'plan 1' => "{$P1}'s uCRM product and service plan are not spelled alike, so the quotation's WhatsApp summary could not show it as monthly — an admin fixes the names in uCRM",
            'plan 3' => 'Spare Plan (TEST) has no 1-month price in uCRM',
            'kit 3' => "{$K3} is linked to a uCRM product that is not active in uCRM — an admin links it again"],
        'Fiber rows linked' => ['plan 2' => 'Fiber is not priced from uCRM on this installation', 'kit 5' => 'Fiber is not priced from uCRM on this installation'],
        'rule 8' => ['kit 4' => 'Mini Kit (TEST) (plugin) and the installation are linked to the same uCRM product — an admin links one of them again', 'install' => 'Mini Kit (TEST) (plugin) and the installation are linked to the same uCRM product — an admin links one of them again'],
        'rule 8, the kit row inactive' => ['install' => ''],
        'rule 8, the kit row Fiber' => ['install' => ''],
        'rule 8, another stamp' => ['install' => ''],
    ];
    foreach ($SC as $name => $_) {
        $s = $jv['sc'][$name] ?? [];
        $c->eq($s['records'] ?? 'none', $s['store'] ?? 'none2', "L12 {$name}: fromRecords() and fromStore() give identical planLine, kitLine and installLine answers");
        $got = [];
        foreach ($expect[$name] as $item => $_r) $got[$item] = (string)($s['store'][$item]['reason'] ?? 'NONE');
        $c->eq($got, $expect[$name], "L12 {$name} … and the answer is the state's own (§R.4.1)");
        $c->t(($s['no_table'] ?? false) === true && array_unique(array_map('json_encode', (array)($s['kit_shape'] ?? []))) === [json_encode([true, true])],
            "L12 {$name} … fromRecords() created no table (sqlite_master unchanged), and it was given kyc_devices' stored rows, an int id and title among them");
    }
    $c->eq($jv['on_created'] ?? null, true, 'L12 the contract, the control: fromStore() with the gate on creates the table on a store without it');
    $c->eq($jv['off'] ?? null, ['tables_same' => true, 'records' => [], 'judge' => 'none', 'rule8' => ['install' => false, 'kits' => []]],
        'L12 the contract: fromStore() with $setting[\'on\'] false opens nothing (sqlite_master unchanged), holds no record and no row');
    $c->eq([count((array)($jv['stored'] ?? [])) === 6, $jv['records'] ?? null], [true, $jv['stored'] ?? 'x'],
        'L12 the contract: records() returns every record whole, the ended ones included, with every key');
    $c->eq($jv['org'] ?? null, [
        'fromStore' => ['ok', 'organization', [true, ''], [false, "{$P1} was linked for another quoting organization — an admin links it again"]],
        'fromRecords' => ['ok', 'organization', [true, ''], [false, "{$P1} was linked for another quoting organization — an admin links it again"]],
        'built under 2, asked with 1' => ['ok', 'organization']],
        'L12 the organization comes from the caller (F-4): each judge asked with the record\'s organization resolves, with another gives the other-organization reason; one built under 2 answers by the id it is asked with');

    // ── L13 offered, as the form offers (F-5): rule 8 by UcrmQuoteLinks::offeredKit ───────────────────────────────────
    $variants = [
        'is_active 1, type general' => [['is_active' => 1, 'type' => 'general'], true],
        'no type'                    => [['is_active' => true], true],
        'type FIBER'                 => [['is_active' => true, 'type' => 'FIBER'], false],
        'type Fiber'                 => [['is_active' => true, 'type' => 'Fiber'], false],
        'is_active 0'                => [['is_active' => 0, 'type' => 'general'], false],
        "is_active '0'"              => [['is_active' => '0', 'type' => 'general'], false],
    ];
    $vrows = [];
    $vi = 20;
    foreach ($variants as $what => [$f, $offered]) {
        $vi++;
        $row = ['id' => $vi, 'title' => "Variant Kit {$vi} (TEST)", 'price' => 'USD 1', 'created_at' => '2026-10-01 10:00:' . $vi] + $f;
        $vrows[$what] = $row;
        $d = qfu_l_seed($c, ['links' => [qfu_link(1, 'kit', $vi, $row['created_at'], 56)], 'devices' => array_merge($rows['devices'], [$row])]);
        $was = $links($d);
        $r = qfu_l_link($c, $d, 'install', 0, 56);
        if ($offered) {
            $c->eq([$r['msg'], $links($d)], [$say("Mini Kit (TEST) is linked to the hardware row Variant Kit {$vi} (TEST), so the installation cannot be linked to it"), $was],
                "L13 rule 8 blocks the installation's link to the product of a kit row stored with {$what} (offered)");
        } else {
            $c->eq([$r['type'], count(qfu_l_active($links($d), 'install', 0))], ['success', 1], "L13 rule 8 does not block for a kit row stored with {$what} (not offered): the installation is linked");
        }
    }
    $dV = qfu_l_seed($c, ['links' => $DEF, 'devices' => array_merge($rows['devices'], array_values($vrows))]);
    $fm = qfu_l_form($c, $dV);
    $pr = $c->child('$o = []; foreach ($s->load("kyc_devices.json") as $r) $o[(string)$r["id"]] = UcrmQuoteLinks::offeredKit($r); return $o;',
        ['dir' => $dV, 'tree' => qfu_l_tree($c), 'cfg' => [], 'links' => null, 'plans' => [], 'devices' => []]);
    $c->t(is_array($fm['q102']) && !empty($fm['q102']['ok']) && is_array($pr['value']), 'L13 the control: the KYC form drew its kits, and offeredKit() answered for every stored row', substr($fm['res']['errlog'] ?? '', 0, 400));
    $FIB = ['p' => null, 'r' => 'Fiber is not priced from uCRM on this installation'];
    foreach ($vrows as $what => $row) {
        $cell = $fm['q102']['kits'][(string)$row['id']] ?? null;
        $drawn = $cell === null ? 'not drawn' : ($cell === $FIB ? 'the Fiber cell' : (array_key_exists('l', (array)$cell) ? 'a kit cell' : 'other'));
        $off = $pr['value'][(string)$row['id']] ?? null;
        $c->eq([$off, $drawn], [$variants[$what][1], $variants[$what][1] ? 'a kit cell' : (empty($row['is_active']) ? 'not drawn' : 'the Fiber cell')],
            "L13 offeredKit() and the form's kit loop agree for a row stored with {$what}");
    }

    // ── L14 the picker (§R.2.3) ─────────────────────────────────────────────────────────────────────────────────────
    qfu_l_fake($c);
    $plansP = $rows['plans'];
    $plansP[2]['ucrm_product_id'] = 13;   // plan row 3, unlinked, carrying a stored id that names a listed product
    $dP = qfu_l_seed($c, ['links' => $DEF, 'plans' => $plansP]);
    $pp = qfu_l_page($c, $dP, 'subscription_plans.php', ['ucrm_link_plan' => '1']);
    $pn = qfu_l_panel($pp['html']);
    $rd = qfu_l_radios($pn);
    $counted = [];
    foreach (qfu_l_products() as $p) {
        if (($p['archived'] ?? false) === true || ($p['isActive'] ?? true) === false || (isset($p['organizationId']) && $p['organizationId'] !== 1)) continue;
        $counted[] = (int)$p['id'];
    }
    sort($counted);
    $listed = array_keys($rd['product_id'] ?? []);
    sort($listed);
    $c->t($pn !== '' && strpos($pn, 'name="action" value="ucrm_link_save"') !== false && strpos($pn, 'name="_csrf"') !== false,
        'L14 the control: an admin asking for plan row 1\'s picker gets its panel, its form posting ucrm_link_save with the token inside it', substr($pp['html'], 0, 300));
    $c->eq($listed, $counted, 'L14 the picker lists the counted products only — the archived, the inactive and another organization\'s are not listed');
    $ids = [];
    foreach ($counted as $id) if (strpos(qfu_l_entry($pn, 'product_id', $id), '/crm/system/items/products/' . $id . '"') === false || strpos(qfu_l_entry($pn, 'product_id', $id), '>#' . $id . ' 🔗</a>') === false) $ids[] = $id;
    $c->eq($ids, [], 'L14 … each with its uCRM id as the "#id 🔗" link to its uCRM page');
    $spListed = array_keys($rd['service_plan_id'] ?? []);
    sort($spListed);
    $c->eq($spListed, [6, 7, 8, 9, 10, 11, 13, 15, 16, 17, 21], 'L14 … and the counted service plans only, with their ids');
    $e54 = qfu_l_entry($pn, 'product_id', 54);
    $e55 = qfu_l_entry($pn, 'product_id', 55);
    $c->t(strpos($e54, 'another product in uCRM is spelled the same (#55)') !== false && strpos($e55, 'another product in uCRM is spelled the same (#54)') !== false,
        'L14 twins are marked by the product\'s own key: 54 and 55 each name the other', $e54);
    $c->eq([$rd['product_id'][54] ?? null, $rd['product_id'][55] ?? null], [[false, false], [false, false]], 'L14 … the mark neither disables nor selects them');
    $sp = function (int $id) use ($pn) { return qfu_l_entry($pn, 'service_plan_id', $id); };
    $c->t(strpos($sp(9), 'another service plan in uCRM is spelled the same (#10)') !== false && strpos($sp(10), 'another service plan in uCRM is spelled the same (#9)') !== false
        && strpos($sp(11), 'another service plan in uCRM is spelled the same (#12)') !== false && strpos($sp(13), 'another service plan in uCRM is spelled the same (#14)') !== false,
        'L14 service plans are marked by their own key, over the whole list (an archived twin and another organization\'s counted)', $sp(11));
    $why = function (string $entry): string { return preg_match('#<div style="color:\#b91c1c;font-size:11px;">([^<]*)</div>#', $entry, $m) ? html_entity_decode($m[1], ENT_QUOTES) : ''; };
    $disabledSp = [];
    foreach (($rd['service_plan_id'] ?? []) as $id => $st) if ($st[1]) $disabledSp[] = $id;
    sort($disabledSp);
    $c->eq($disabledSp, [9, 10, 11, 13, 15, 16, 17], 'L14 the unusable service plans are listed but disabled: rule 6(a)\'s twins, 6(b), no single 1-month price');
    $c->eq([$why($sp(9)), $why($sp(15)), $why($sp(16)), $why($sp(6))], [
        "Twin Plan (TEST)'s uCRM service plan is spelled like another service plan in uCRM (active or not), so the quotation's WhatsApp summary could show the other one's monthly price — an admin renames one in uCRM",
        "Odd Plan (TEST)'s uCRM service plan carries a price that is not its 1-month price, so the quotation's WhatsApp summary could show the wrong monthly price — an admin fixes the service plan in uCRM",
        'Quarter Plan (TEST) has no 1-month price in uCRM', ''], 'L14 … each with its reason; a usable one with none');
    $disabledP = [];
    foreach (($rd['product_id'] ?? []) as $id => $st) if ($st[1]) $disabledP[] = $id;
    sort($disabledP);
    $c->eq($disabledP, [83, 84, 85, 86, 87, 88], 'L14 in a plan\'s product list the products failing rule 5 are disabled');
    $c->eq($why(qfu_l_entry($pn, 'product_id', 85)), "Free Kit (TEST)'s price in uCRM is 0", 'L14 … with their reason');
    $pre = [];
    foreach ($rd as $nm => $vals) foreach ($vals as $v => $st) if ($st[0]) $pre[] = "{$nm}={$v}";
    $c->eq($pre, ['product_id=12', 'service_plan_id=6'], 'L14 the control: the item\'s own current link (single, current stamp, current organization) is pre-selected, and nothing else');
    // a kit's list and the installation's: rule 7 and rule 8 disable
    $hp = qfu_l_panel(qfu_l_page($c, $dP, 'hardware.php', ['ucrm_link_hw' => '4'])['html']);
    $hr = qfu_l_radios($hp);
    $dis = function (array $ids) use ($hr) { $o = []; foreach ($ids as $i) $o[$i] = $hr['product_id'][$i][1] ?? 'absent'; return $o; };
    $c->eq($dis([12, 29, 30, 31, 70, 55, 56]), [12 => true, 29 => true, 30 => true, 31 => true, 70 => true, 55 => false, 56 => false],
        'L14 in a kit\'s list: products spelled like any listed service plan (counted, archived, inactive, another organization\'s) and the installation\'s product are disabled; others not');
    $c->eq([$why(qfu_l_entry($hp, 'product_id', 70)), $why(qfu_l_entry($hp, 'product_id', 29))],
        ["Professional Installation is the installation's uCRM product, so a hardware row cannot be linked to it. If Mini Kit (TEST) (plugin) is the installation itself, deactivate it.",
         'Archived Basic (TEST) is spelled like a service plan in uCRM, so it cannot be quoted as hardware'], 'L14 … with rule 8\'s and rule 7\'s reasons');
    $ip = qfu_l_panel(qfu_l_page($c, $dP, 'hardware.php', ['ucrm_link_install' => '1'])['html']);
    $ir = qfu_l_radios($ip);
    $c->eq([$ir['product_id'][55][1] ?? 'absent', $why(qfu_l_entry($ip, 'product_id', 55)), $ir['product_id'][71][1] ?? 'absent', $ir['product_id'][70][0] ?? 'absent'],
        [true, 'Standard Kit (TEST) is linked to the hardware row Standard Kit (TEST), so the installation cannot be linked to it.', false, true],
        'L14 in the installation\'s list the product of an offered kit\'s active link is disabled (rule 8); its own link 70 pre-selected');
    // uCRM unreadable: the panel says so and shows no list
    $c->ctl('kyc', '/__test/set', ['products_down' => true]);
    $pd = qfu_l_panel(qfu_l_page($c, $dP, 'subscription_plans.php', ['ucrm_link_plan' => '1'])['html']);
    $c->ctl('kyc', '/__test/set', ['products_down' => false]);
    $c->eq([$pd !== '', strpos($pd, $h("uCRM's products could not be read — nothing can be linked now; try again")) !== false, qfu_l_radios($pd)], [true, true, []],
        'L14 uCRM unreadable: the panel says "uCRM\'s products could not be read — nothing can be linked now; try again" and shows no list');
    // 6(c): every service plan disabled when uCRM lists 200
    qfu_l_fake($c, null, $fill(200));
    $p200 = qfu_l_radios(qfu_l_panel(qfu_l_page($c, $dP, 'subscription_plans.php', ['ucrm_link_plan' => '1'])['html']));
    $en = [];
    foreach (($p200['service_plan_id'] ?? []) as $id => $st) if (!$st[1]) $en[] = $id;
    $c->eq([count($p200['service_plan_id'] ?? []) > 100, $en], [true, []], 'L14 rule 6(c): with 200 service plans listed every one is disabled');
    qfu_l_fake($c);
    // pre-selects nothing but the current link: unlinked (spelled like the row, a stored id), two active, other organization
    $noPre = function (string $label, string $dir, array $get, string $file = 'subscription_plans.php') use ($c) {
        $rr = qfu_l_radios(qfu_l_panel(qfu_l_page($c, $dir, $file, $get)['html']));
        $on = [];
        foreach ($rr as $nm => $vals) foreach ($vals as $v => $st) if ($st[0]) $on[] = "{$nm}={$v}";
        $c->eq([count($rr['product_id'] ?? []) > 5, $on], [true, []], "L14 the picker pre-selects nothing: {$label}");
    };
    $dQ = qfu_l_seed($c, ['links' => [], 'plans' => $plansP]);
    $e13 = qfu_l_entry(qfu_l_panel(qfu_l_page($c, $dQ, 'subscription_plans.php', ['ucrm_link_plan' => '1'])['html']), 'product_id', 13);
    $c->t(strpos($e13, 'spelled like this plan') !== false, 'L14 the control: product 13 is marked "spelled like this plan" for plan row 1 (a hint)', $e13);
    $noPre('plan row 1 unlinked — never product 13, spelled like it', $dQ, ['ucrm_link_plan' => '1']);
    $noPre('plan row 3 unlinked — never product 13, its stored ucrm_product_id', $dQ, ['ucrm_link_plan' => '3']);
    $noPre('two active records', qfu_l_seed($c, ['links' => [qfu_link(1, 'plan', 1, QFU_PLAN_STAMP, 12, 6), qfu_link(2, 'plan', 1, QFU_PLAN_STAMP, 12, 6)]]), ['ucrm_link_plan' => '1']);
    $noPre('a link made for another quoting organization', qfu_l_seed($c, ['links' => [qfu_link(1, 'plan', 1, QFU_PLAN_STAMP, 12, 6, ['organization_id' => 2])]]), ['ucrm_link_plan' => '1']);
    // 5.18.102 D14 review (T2): the Hardware screen draws its own copy of the picker (hardware.php): the same four cases for a kit
    $devQ = $rows['devices'];
    $devQ[1]['ucrm_product_id'] = 56;   // kit row 4, unlinked, carrying a stored id that names a listed product
    $dK = qfu_l_seed($c, ['links' => [], 'devices' => $devQ]);
    $e55 = qfu_l_entry(qfu_l_panel(qfu_l_page($c, $dK, 'hardware.php', ['ucrm_link_hw' => '3'])['html']), 'product_id', 55);
    $c->t(strpos($e55, 'spelled like this kit') !== false && strpos(qfu_l_entry(qfu_l_panel(qfu_l_page($c, $dK, 'hardware.php', ['ucrm_link_hw' => '4'])['html']), 'product_id', 56), '"56"') !== false,
        'L14 the control: product 55 is marked "spelled like this kit" for kit row 3 (a hint), and product 56 is listed for kit row 4', $e55);
    $noPre('kit row 3 unlinked — never 54 or 55, spelled like it', $dK, ['ucrm_link_hw' => '3'], 'hardware.php');
    $noPre('kit row 4 unlinked — never product 56, its stored ucrm_product_id', $dK, ['ucrm_link_hw' => '4'], 'hardware.php');
    $noPre('a kit with two active records', qfu_l_seed($c, ['links' => [qfu_link(1, 'kit', 3, QFU_KIT_STAMP, 55), qfu_link(2, 'kit', 3, QFU_KIT_STAMP, 55)]]), ['ucrm_link_hw' => '3'], 'hardware.php');
    $noPre('a kit link made for another quoting organization', qfu_l_seed($c, ['links' => [qfu_link(1, 'kit', 3, QFU_KIT_STAMP, 55, null, ['organization_id' => 2])]]), ['ucrm_link_hw' => '3'], 'hardware.php');
    $kr = qfu_l_radios(qfu_l_panel(qfu_l_page($c, $dP, 'hardware.php', ['ucrm_link_hw' => '3'])['html']));
    $kOn = [];
    foreach ($kr as $nm => $vals) foreach ($vals as $v => $st) if ($st[0]) $kOn[] = "{$nm}={$v}";
    $c->eq($kOn, ['product_id=55'], 'L14 the control: a kit\'s own current link (single, current stamp, current organization) is pre-selected on Hardware, and nothing else — not its twin 54');
    $np = qfu_l_page($c, $dP, 'subscription_plans.php', ['ucrm_link_plan' => '1'], false);
    $c->t(strpos($np['html'], 'value="ucrm_link_save"') === false && strpos($np['html'], 'id="ucrm-link"') === false && strpos($np['html'], 'uCRM link') !== false,
        'L14 the picker is drawn for admins only: a non-admin asking for it gets the screen with its link column and no panel');

    // ── L15 the link column (§R.2.2) ────────────────────────────────────────────────────────────────────────────────
    $plans15 = array_merge($rows['plans'], [['id' => 4, 'name' => 'Lonely Plan (TEST)', 'type' => 'starlink', 'customer_price' => 70, 'is_active' => true, 'created_at' => '2026-10-01 09:02:00']]);
    $d15 = qfu_l_seed($c, ['plans' => $plans15, 'links' => [$DEF[0], qfu_link(2, 'plan', 3, QFU_L_STAMP3, 21, 7, ['product_name' => 'Starter (TEST)', 'service_plan_name' => 'Starter (TEST)']),
        qfu_link(3, 'kit', 3, QFU_KIT_STAMP, 55), qfu_link(4, 'kit', 4, QFU_L_STAMP4, 80, null, ['product_name' => 'Archived Kit (TEST)'])]]);
    $pg = qfu_l_page($c, $d15, 'subscription_plans.php');
    $cOk = qfu_l_cell($pg['html'], 'plan', $P1);
    $c->t(strpos($cOk, '>#12 🔗</a>') !== false && strpos($cOk, $RES) !== false && strpos($cOk, 'service plan #6') !== false && strpos($cOk, '/month') !== false
        && strpos($cOk, '✓ quotes') !== false && strpos($cOk, 'linked by Test Admin, 2026-10-01') !== false && strpos($cOk, '>Change</a>') !== false,
        'L15 the control: a linked row that resolves shows uCRM\'s product id link, name and price, the service plan and "/month", ✓ quotes, who linked it', $cOk);
    $cBad = qfu_l_cell($pg['html'], 'plan', 'Spare Plan (TEST)');
    $c->t(strpos($cBad, '>#21 🔗</a>') !== false && strpos($cBad, 'service plan #7') !== false && strpos($cBad, 'as recorded at link time: Starter (TEST) · Starter (TEST)') !== false
        && strpos($cBad, $h("Spare Plan (TEST)'s price in Products (120) differs from its service plan (110)")) !== false && strpos($cBad, '>Change</a>') !== false
        && strpos($cBad, 'value="ucrm_link_clear"') !== false && strpos($cBad, '✓ quotes') === false,
        'L15 a linked plan that does not resolve: the product\'s "#id 🔗" link and the service plan\'s id beside the reason, the names as recorded, Change and Unlink', $cBad);
    $c->t(strpos(qfu_l_cell($pg['html'], 'plan', 'Lonely Plan (TEST)'), 'not linked — Cash sales of it are refused') !== false
        && strpos(qfu_l_cell($pg['html'], 'plan', 'Lonely Plan (TEST)'), 'ucrm_link_plan=4#ucrm-link') !== false, 'L15 an unlinked row: amber "not linked — Cash sales of it are refused" and Link');
    $cF = qfu_l_cell($pg['html'], 'plan', 'Fiber Home (TEST)');
    $c->t(strpos($cF, 'Fiber: not priced from uCRM on this installation') !== false && strpos($cF, 'ucrm_link_plan=') === false, 'L15 a Fiber row: grey, and no Link', $cF);
    $c->t(strpos($pg['html'], '⚠️ 1 active item(s) not linked') !== false, 'L15 the badge counts the active, non-Fiber plans with no link in force (1: the linked one that does not resolve is linked)');
    $hw = qfu_l_page($c, $d15, 'hardware.php');
    $cK = qfu_l_cell($hw['html'], 'kit', $R['kit4']);
    $c->t(strpos($cK, '>#80 🔗</a>') !== false && strpos($cK, $h('Mini Kit (TEST) (plugin) is linked to a uCRM product that is archived in uCRM — an admin links it again')) !== false
        && strpos($cK, 'service plan #') === false, 'L15 a linked kit that does not resolve: its product\'s "#id 🔗" link beside the reason, no service plan', $cK);
    $c->t(strpos(qfu_l_cell($hw['html'], 'install', ''), 'not linked — Cash sales of it are refused') !== false, 'L15 the installation card, unlinked: not linked');
    $na = qfu_l_page($c, $d15, 'subscription_plans.php', [], false);
    $cN = qfu_l_cell($na['html'], 'plan', 'Spare Plan (TEST)');
    $c->t(strpos($cN, '>#21 🔗</a>') !== false && strpos($cN, '>Change</a>') === false && strpos($cN, 'ucrm_link_clear') === false
        && strpos(qfu_l_cell($na['html'], 'plan', 'Lonely Plan (TEST)'), 'ucrm_link_plan=') === false, 'L15 a non-admin sees each link and its state, read-only: no Link, Change or Unlink', $cN);

    // ── L16 one text for two active records (E-4, F-11) ─────────────────────────────────────────────────────────────
    $two16 = $SC['two active'];
    $d16 = qfu_l_seed($c, ['links' => $two16]);
    $TWO = "{$P1} has two uCRM links recorded — an admin links it again";
    $c16 = qfu_l_cell(qfu_l_page($c, $d16, 'subscription_plans.php')['html'], 'plan', $P1);
    $reasonDiv = preg_match_all('#<div style="color:\#b91c1c;font-size:11px;margin-top:3px;white-space:normal;">([^<]*)</div>#', $c16, $m16) ? array_map(function ($x) { return html_entity_decode($x, ENT_QUOTES); }, $m16[1]) : [];
    $fm16 = qfu_l_form($c, $d16);
    $res16 = $resolve($d16, 'plan', 1);
    $c->eq([$reasonDiv, $fm16['q102']['plans']['1']['r'] ?? null, $res16['reason'] ?? null], [[$TWO], $TWO, $TWO],
        'L16 two active records: the link column, the KYC form\'s reason and the resolver\'s answer carry §R.4.1\'s one text, and no other');
    $c->t(substr_count($c16, '>#12 🔗</a>') === 2 && strpos($c16, '>Change</a>') !== false && strpos($c16, 'value="ucrm_link_clear"') !== false,
        'L16 … the link column shows both records\' product ids, each as its "#id 🔗" link, with Change and Unlink', $c16);
    $c16k = qfu_l_cell(qfu_l_page($c, $d16, 'hardware.php')['html'], 'kit', $K3);
    $c->t(strpos($c16k, '>#55 🔗</a>') !== false && strpos($c16k, '>#54 🔗</a>') !== false && strpos($c16k, $h("{$K3} has two uCRM links recorded — an admin links it again")) !== false,
        'L16 … and the same for a kit: both product ids, 55 and 54, and the one text', $c16k);

    // ── L17 escaping: a product and a service plan whose names carry <b>, &, " and ' ──────────────────────────────────
    $NAME = 'Res <b>Max</b> & "Co" \'TEST\'';
    $KNAME = 'Kit <b>Pro</b> & "Co" \'TEST\'';
    $ROOF = 'Roof\'s <b>Mount</b> & "Co" (TEST)';   // a plugin row's own name with markup: the Unlink confirmation's text
    $dev17 = $rows['devices'];
    $dev17[1]['title'] = $ROOF;
    $d17 = qfu_l_seed($c, ['links' => [], 'devices' => $dev17]);
    $raw = function (string $s): bool { return strpos($s, '<b>') !== false || strpos($s, '"Co"') !== false; };
    $f1 = qfu_l_link($c, $d17, 'plan', 3, 90, 21);
    $f2 = qfu_l_link($c, $d17, 'plan', 3, 90, 21);
    $f3 = qfu_l_link($c, $d17, 'install', 0, 91);
    $f4 = qfu_l_link($c, $d17, 'kit', 3, 91);
    $c->eq([$f1['type'], $f2['type'], $f3['type'], $f4['type']], ['success', 'info', 'success', 'danger'], 'L17 the control: linked, relinked to the same product, linked, refused');
    $c->eq([$f1['msg'], $f2['msg'], $f4['msg']], [
        $linked('Spare Plan (TEST)', 90, $plain($NAME), 21, $plain($NAME)),
        'Spare Plan (TEST) is already linked to uCRM product #90 (' . $plain($NAME) . ') and service plan #21 (' . $plain($NAME) . ') — nothing was changed.',
        $say($plain($KNAME) . " is the installation's uCRM product, so a hardware row cannot be linked to it. If Standard Kit (TEST) is the installation itself, deactivate it")],
        'L17 every flash names the product stripped by plain(), never raw');
    $pg17 = qfu_l_page($c, $d17, 'subscription_plans.php', ['ucrm_link_plan' => '3']);
    $hw17 = qfu_l_page($c, $d17, 'hardware.php', ['ucrm_link_hw' => '3']);
    $c->t(strpos(qfu_l_cell($pg17['html'], 'plan', 'Spare Plan (TEST)'), $h($NAME)) !== false && strpos(qfu_l_entry(qfu_l_panel($pg17['html']), 'product_id', 90), $h($NAME)) !== false
        && strpos(qfu_l_entry(qfu_l_panel($pg17['html']), 'service_plan_id', 21), $h($NAME)) !== false && strpos(qfu_l_cell($hw17['html'], 'install', ''), $h($KNAME)) !== false
        && strpos(qfu_l_entry(qfu_l_panel($hw17['html']), 'product_id', 91), $h($KNAME)) !== false,
        'L17 the control: the names do appear on the screens, escaped — the link column, the picker\'s product and service plan, the installation card');
    $f6 = qfu_l_link($c, $d17, 'kit', 4, 56);
    $hw17b = qfu_l_page($c, $d17, 'hardware.php');
    $conf = preg_match('#<form method="POST" style="display:inline;" data-confirm="([^"]*)" onsubmit="([^"]*)">(?:(?!</form>).)*name="item" value="kit"><input type="hidden" name="row_id" value="4">#s', $hw17b['html'], $mc) ? $mc : [];
    $c->eq([$f6['type'], $conf[1] ?? null, $conf[2] ?? null, $raw($f6['msg']), strpos($hw17b['html'], '<b>Mount</b>') !== false],
        ['success', $h('Unlink ' . $ROOF . '? Cash sales of it will be refused until it is linked again.'), 'return confirm(this.dataset.confirm);', false, false],
        'L17 a row whose own name carries markup: its Unlink confirmation travels escaped in data-confirm, read by this.dataset.confirm — never in the on* attribute; its flash and the screen hold no raw markup');
    $f5 = qfu_l_unlink($c, $d17, 'plan', 3);
    $pg17b = qfu_l_page($c, $d17, 'subscription_plans.php', ['ucrm_link_plan' => '3']);
    $c->eq([$raw($f1['msg']), $raw($f2['msg']), $raw($f3['msg']), $raw($f4['msg']), $raw($f5['msg']), $raw($pg17['html']), $raw($hw17['html']), $raw($pg17b['html'])],
        array_fill(0, 8, false), 'L17 after each — linked, relinked, unlinked, refused — no flash and no screen holds a raw <b> or "Co"');
    $c->t(strpos($f3['msg'], $plain($KNAME)) !== false && $plain($KNAME) !== $KNAME, 'L17 … the flash carries the stripped name (the control: stripping changed it)', $f3['msg']);

    // ── L18 what was quoted under a link (§R.4.4, §R.2.3) ───────────────────────────────────────────────────────────
    $q = function (int $id, string $type, ?int $qid, $ids) {
        $a = ['id' => $id, 'firstname' => 'Test', 'lastname' => 'Quoted' . $id, 'status' => 'new', 'customer_type' => 'StarLink', 'sales_type' => $type,
              'crm_client_id' => (string)(800 + $id), 'crm_sync_status' => 'synced'];
        if ($qid !== null) $a['quote_id'] = $qid;
        if ($ids !== null) $a['quote_link_ids'] = $ids;
        return $a;
    };
    $apps18 = [$q(1, 'Cash', 501, [1, 2]), $q(2, 'Cash', 502, [1]), $q(3, 'Credit', 503, [1]), $q(4, 'Credit', 504, [2]), $q(5, 'Cash', null, [1]), $q(6, 'Credit', 506, null)];
    $d18 = qfu_l_seed($c, ['links' => $DEF, 'apps' => $apps18]);
    $r = qfu_l_link($c, $d18, 'plan', 1, 90, 21);
    $c->eq($r['msg'], $linked($P1, 90, $plain($NAME), 21, $plain($NAME)) . ' 3 application(s) were quoted under the previous link (2 Cash, 1 Credit). Review them in uCRM. <a href="?page=dashboard&amp;tab=applications&amp;quote_link=1">Orders quoted under link #1</a>',
        'L18 a relink over seeded Cash and Credit applications: 3 quoted under the ended record (2 Cash, 1 Credit), with its Orders link');
    $r = qfu_l_unlink($c, $d18, 'kit', 3);
    $c->t(strpos($r['msg'], ' 2 application(s) were quoted under the previous link (1 Cash, 1 Credit).') !== false && strpos($r['msg'], 'quote_link=2"') !== false,
        'L18 an unlink: 2 quoted under the kit\'s record (1 Cash, 1 Credit)', $r['msg']);
    $hist = qfu_l_panel(qfu_l_page($c, $d18, 'subscription_plans.php', ['ucrm_link_plan' => '1'])['html']);
    $c->t(strpos($hist, 'Earlier links (1)') !== false && strpos($hist, '>#12 🔗</a>') !== false && strpos($hist, 'relinked') !== false
        && strpos($hist, 'quoted under it 2 Cash, 1 Credit') !== false && strpos($hist, 'quote_link=1"') !== false, 'L18 the ended record\'s history is drawn on the panel, with its counts', $hist);
    // the Orders filter
    $o1 = qfu_l_page($c, $d18, 'applications.php', ['quote_link' => '1']);
    $all = qfu_l_page($c, $d18, 'applications.php');
    $names = function (string $html) { preg_match_all('#Quoted(\d+)#', $html, $m); return array_values(array_unique(array_map('intval', $m[1]))); };
    $c->eq([$names($all['html']), $names($o1['html']), strpos($o1['html'], 'Applications quoted under uCRM link #1') !== false], [[1, 2, 3, 4, 5, 6], [1, 2, 3], true],
        'L18 the Orders filter lists only the applications quoted under link #1 (the control: the page lists all six)');
    $o2 = qfu_l_page($c, $d18, 'applications.php', ['quote_link' => '1'], false);
    $c->eq([$o2['code'], strpos($o2['html'], 'Access denied — admin only.') !== false, $names($o2['html'])], [403, true, []], 'L18 the Orders filter answers 403 for a non-admin');
    foreach (['ss' => 'South Sudan', 'ug_off' => 'Uganda with the setting 0'] as $k => $where) {
        $dk = qfu_l_seed($c, ['k' => $k, 'links' => null, 'apps' => $apps18]);
        $w = qfu_l_page($c, $dk, 'applications.php', ['quote_link' => '1']);
        $wo = qfu_l_page($c, $dk, 'applications.php');
        $c->eq([$w['html'] === $wo['html'], $names($w['html'])], [true, [1, 2, 3, 4, 5, 6]], "L18 on {$where} the Orders filter is absent: the page is the same with or without it");
    }
    // quote_link_ids recorded by retry_quote and by Make the quotation, after linking
    $web = function (int $id) {
        return ['id' => $id, 'firstname' => 'Test', 'lastname' => 'Web' . $id, 'status' => 'new', 'customer_type' => 'StarLink',
                'connectivity_type' => 'Shifting Connection', 'sales_type' => 'Credit', 'package_choice' => '1', 'device_id' => '3', 'kitQty' => '1',
                'crm_client_id' => (string)(900 + $id), 'crm_sync_status' => 'synced', 'quote_sale_entry' => 'web', 'quote_refused' => 'P1',
                'quote_refused_text' => 'No quotation was made: Home Plan (TEST) is not linked to a uCRM product yet — an admin links it on the Plans screen. Do not make it by hand — once the item is linked, an admin makes it from the Orders card.',
                'quote_refused_at' => '2026-10-27 10:00:00', 'submitted_at' => '2026-10-27 10:00:00', 'mobile' => '+256700009' . (200 + $id), 'sales_person' => 'Test Agent'];
    };
    qfu_l_fake($c, null, null, ['clients' => [['id' => 901, 'organizationId' => 1, 'firstName' => 'Test', 'lastName' => 'Web1'],
                                              ['id' => 902, 'organizationId' => 1, 'firstName' => 'Test', 'lastName' => 'Web2']]]);
    $fake = 'http://127.0.0.1:' . $c->fake('kyc') . '/api/v2.1';
    $d18q = qfu_l_seed($c, ['links' => $DEF, 'apps' => [$web(1), $web(2)], 'cfg' => ['crm_auth_token' => 'TEST-TOKEN', 'crm_base_url' => $fake]]);
    $rt = qfu_l_req($c, $d18q, ['entry' => 'retry', 'app_id' => 1, 'me2' => QFU_L_ADMIN, 'session' => QFU_L_ADMIN]);
    $mk = qfu_l_post($c, $d18q, ['action' => 'kyc_make_quote', 'app_id' => '2']);
    $ap = (array)qfu_l_rows_of($d18q, 'kyc_applications');
    $c->t(($rt['answer']['status'] ?? '') === 'success' && !empty($ap[1]['quote_id']) && strpos($mk['msg'], 'was made in uCRM for application #2') !== false && !empty($ap[2]['quote_id']),
        'L18 the control: retry_quote quoted application 1, and Make the quotation application 2, after linking', $j([$rt['answer'] ?? $rt['end'], $mk['msg'], substr($rt['errlog'] . $mk['errlog'], 0, 600)]));
    $c->eq([$ap[1]['quote_link_ids'] ?? 'none', $ap[2]['quote_link_ids'] ?? 'none'], [[1, 2], [1, 2]],
        'L18 … each carries quote_link_ids, the link records its lines came from (the plan\'s and the kit\'s)');

    // ── L19 the gate off never opens the store (§R.1.5, §R.8) ───────────────────────────────────────────────────────
    $dOn = qfu_l_seed($c, ['links' => null]);
    $t0 = qfu_l_tables($dOn);
    qfu_l_page($c, $dOn, 'subscription_plans.php');
    $c->t(!in_array('ucrm_quote_links', $t0, true) && in_array('ucrm_quote_links', qfu_l_tables($dOn), true),
        'L19 the control: on Uganda with the gate on, drawing the Plans screen creates ucrm_quote_links (the check can see it)', $j(qfu_l_tables($dOn)));
    foreach (['ss' => 'South Sudan', 'ug_off' => 'Uganda with the setting 0'] as $k => $where) {
        $d = qfu_l_seed($c, ['k' => $k, 'links' => null, 'apps' => [$web(1)]]);
        $done = []; $ends = [];
        foreach ([['subscription_plans.php', ['ucrm_link_plan' => '1']], ['hardware.php', ['ucrm_link_hw' => '3']], ['hardware.php', ['ucrm_link_install' => '1']],
                  ['applications.php', ['quote_link' => '1']]] as [$file, $get]) {
            $p = qfu_l_page($c, $d, $file, $get);
            $done[] = $file . (($p['end'] ?? '') === 'drawn' && strlen($p['html']) > 500 ? '' : ' (NOT DRAWN)');   // round 2 (FX-tests-1)
        }
        $fmk = qfu_l_form($c, $d);
        $done[] = 'kyc_form.php' . ((($fmk['res'] ?? [])['end'] ?? '') === 'drawn' && strlen($fmk['html']) > 500 ? '' : ' (NOT DRAWN)');
        foreach ([['action' => 'ucrm_link_save', 'item' => 'plan', 'row_id' => '1', 'product_id' => '12', 'service_plan_id' => '6'],
                  ['action' => 'ucrm_link_clear', 'item' => 'kit', 'row_id' => '3'], ['action' => 'kyc_make_quote', 'app_id' => '1'],
                  ['action' => 'save_plan', 'plan_id' => '1', 'plan_name' => 'Home Plan (TEST)', 'customer_price' => '90', 'starlink_cost' => '40', 'plan_active' => '1'],
                  ['action' => 'save_hardware', 'hw_id' => '3', 'hw_title' => 'Standard Kit (TEST)', 'hw_sell' => '10', 'hw_buy' => '5', 'hw_active' => '1'],
                  ['action' => 'delete_plan', 'plan_id' => '3'], ['action' => 'delete_hardware', 'hw_id' => '4']] as $f) {
            $r = qfu_l_post($c, $d, $f);
            $done[] = $f['action'] . ' (' . ($r['end'] ?? '') . ')';
            $ends[$f['action']] = [(string)($r['end'] ?? ''), $r['msg']];
        }
        // 5.18.102 D14 review (T6): the control on the posts — each one ran: past the global gate, the three D14 actions
        // falling through (their blocks skipped whole, §R.8), the four others reaching their handlers past requireAdmin()
        $c->eq($ends, ['ucrm_link_save' => ['fell through', ''], 'ucrm_link_clear' => ['fell through', ''], 'kyc_make_quote' => ['fell through', ''],
                       'save_plan' => ['handled', 'Plan updated.'], 'save_hardware' => ['handled', 'Hardware updated.'],
                       'delete_plan' => ['handled', 'Plan deleted.'], 'delete_hardware' => ['handled', 'Hardware deleted.']],
            "L19 {$where}: the control — every action was posted past the global gate: the three D14 actions fall through (their blocks skipped whole, §R.8), the four others reach their handlers");
        $c->eq([in_array('ucrm_quote_links', qfu_l_tables($d), true), count(array_filter($done, function ($x) { return strpos($x, 'NOT DRAWN') !== false; }))], [false, 0],
            "L19 {$where}: after every page and action — " . implode(', ', $done) . " — sqlite_master has no ucrm_quote_links");
    }

    // ── L19b 5.18.102 D14 review (T1): the gate off never opens the store on the KYC paths either (§R.9.1 L, §R.1.5,
    // §R.8) — the submission (new, existing), the cron retry, retry_quote, Send Quotation and a lead quote's lines, each
    // run on a store without the table. The control on every path: on Uganda with the gate on, each one creates it.
    $pend = ['id' => 31, 'mobile' => '+256 700 002 031', 'firstname' => 'Test', 'lastname' => 'Retry', 'email' => '', 'address_1' => 'Test Road',
             'customer_type' => 'StarLink', 'connectivity_type' => 'New Connection', 'package_choice' => '1', 'device_id' => '3', 'kitQty' => '1',
             'sales_type' => 'Credit', 'sales_person' => 'Tester', 'retailer_name' => 'Test Agent', 'priority' => 'Low', 'crm_client_id' => null,
             'crm_sync_status' => 'pending', 'submitted_at' => '2026-10-27 10:00:00', 'is_lead' => true, 'amount_charged' => 0,
             'quote_created' => false, 'status' => 'new', 'quote_sale_entry' => 'web'];
    $sub = function (string $d, string $k) use ($c) { return ['dir' => $d, 'tree' => qfu_l_tree($c), 'tenant' => $k === 'ss' ? 'south-sudan' : 'uganda', 'links' => null]; };
    $kyc = [
        'kyc_submit, a new customer' => fn($d, $k) => $c->submit(['install_by' => 'dishnet', 'mobile' => '+256 700 003 ' . ['ug' => '101', 'ss' => '102', 'ug_off' => '103'][$k]], $sub($d, $k))['rc'],
        'kyc_submit, an existing customer' => fn($d, $k) => $c->submit(['customer_id' => '901', 'connectivity_type' => 'Additional Service', 'sales_type' => 'Credit', 'install_by' => 'dishnet'], $sub($d, $k))['rc'],
        'syncOne' => fn($d, $k) => $c->child('$x = new KycCrmSync($s, $crm, $s->load("kyc_config.json") ?? []); return $x->syncOne(31, false, "cron");', $sub($d, $k))['rc'],
        // 5.18.102 D14 review round 2 (FX-tests-1): each path must be shown to run to its end. retry_quote ends with an
        // answer from its handler ($er2 and $ok2 exit 0, so the exit code alone cannot say it ran); with the gate off it
        // must be 5.18.101's quotation, made — an early answer would leave the gate-off path unrun
        'retry_quote' => function ($d, $k) use ($c) {
            $x = qfu_l_req($c, $d, ['entry' => 'retry', 'app_id' => 1, 'me2' => QFU_L_ADMIN, 'session' => QFU_L_ADMIN]);
            if ($x['rc'] !== 0) return $x['rc'];
            return ($x['end'] ?? '') === 'handled' && is_array($x['answer'] ?? null)
                && ($k === 'ug' || ($x['answer']['status'] ?? '') === 'success') ? 0 : 1;
        },
        // … and the page drawn to its end: 'drawn' is set only after the include returns (l_run.php), never on a fatal,
        // whose message would otherwise stand in for the page's HTML; the length still refuses an early "Access denied"
        'send_quote.php' => function ($d, $k) use ($c) {
            $p = qfu_l_page($c, $d, 'send_quote.php');
            return ($p['end'] ?? '') === 'drawn' && strlen($p['html']) > 500 ? 0 : ($p['rc'] ?: 1);
        },
        "a lead quote's lines" => fn($d, $k) => $c->child('require_once "$root/lib/QuotationService.php"; $q = new QuotationService($s, $dir, $s->load("kyc_config.json") ?? []); return $q->buildLeadItems(["interest_plan" => "Home Plan (TEST)", "service_type" => "starlink", "phone" => "+256700003199"]);', $sub($d, $k))['rc'],
    ];
    $made = [];
    foreach (['ug', 'ss', 'ug_off'] as $k) foreach ($kyc as $what => $run) {
        qfu_l_fake($c, null, null, ['clients' => [['id' => 901, 'organizationId' => 1, 'firstName' => 'Test', 'lastName' => 'Web1']]]);   // a fresh fake per path
        $d = qfu_l_seed($c, ['k' => $k, 'links' => null, 'apps' => [$web(1), ['mobile' => '+256 700 002 ' . ['ug' => '031', 'ss' => '032', 'ug_off' => '033'][$k]] + $pend],
                             'cfg' => ['crm_auth_token' => 'TEST-TOKEN', 'crm_base_url' => $fake]]);
        $t0 = in_array('ucrm_quote_links', qfu_l_tables($d), true);
        $rc = $run($d, $k);
        $made[$k][$what] = $t0 ? 'BEFORE' : (in_array('ucrm_quote_links', qfu_l_tables($d), true) ? 'created' : 'absent') . ($rc === 0 ? '' : " (rc {$rc})");
    }
    $c->eq($made['ug'], array_fill_keys(array_keys($kyc), 'created'),
        'L19b the control: on Uganda with the gate on, each KYC path creates ucrm_quote_links (each reaches its gate, and ran to its end)');
    foreach (['ss' => 'South Sudan', 'ug_off' => 'Uganda with the setting 0'] as $k => $where) {
        $c->eq($made[$k], array_fill_keys(array_keys($kyc), 'absent'),
            "L19b {$where}: the KYC submission (new, existing), syncOne, retry_quote, Send Quotation and a lead quote's lines — sqlite_master has no ucrm_quote_links");
    }
}];
