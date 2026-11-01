<?php
declare(strict_types=1);
/**
 * Section A — 5.18.103 (root docs/72 §G.3, G-1, G-2): the uCRM catalogue's store, lib/UcrmCatalogue.php. The gate (nothing
 * opened where it is closed, no table created), the readers (fail safe: two records in force read as none), every write
 * under the lock with its refusals, the gate re-read inside the lock, and the provenance and history each write keeps.
 */
$CD_SECTIONS['A'] = ['title' => 'the uCRM catalogue store (UcrmCatalogue)', 'run' => function (QfuCtx $c) {
    cd_fake($c);
    $admin = ['id' => 4, 'name' => 'Test Admin'];

    // ── A0 the positive control: on Uganda the store opens, reads and writes ─────────────────────────────────────────
    $r = $c->child('
$set = QuoteOrganization::setting($s->load("kyc_config.json") ?? [], $dir);
$cat = UcrmCatalogue::fromStore($s, $set);
$w = UcrmCatalogue::setInstall($s, 70, "Professional Installation", ["id" => 4, "name" => "Test Admin"], $s->load("kyc_config.json") ?? [], $dir);
$after = UcrmCatalogue::fromStore($s, $set);
return ["gate" => UcrmCatalogue::gateOpen($set), "open" => $cat->open(), "w" => $w["kind"], "install" => $after->installProductId(),
        "rec" => $after->installRecord()];
');
    $v = $r['value'];
    $c->eq($v['gate'] ?? null, true, 'A0 the gate is open on Uganda (the profile\'s quoting organization 1)');
    $c->eq($v['open'] ?? null, true, 'A0 the store opens');
    $c->eq([$v['w'] ?? null, $v['install'] ?? null], ['set', 70], 'A0 the installation product is set and read back');
    $c->eq([$v['rec']['by_id'] ?? null, $v['rec']['by_name'] ?? null, is_int($v['rec']['at_ts'] ?? null), array_key_exists('ended_at_ts', (array)($v['rec'] ?? [])) ? $v['rec']['ended_at_ts'] : 'x'],
        [4, 'Test Admin', true, null], 'A0 the record carries who and when, in force');

    // ── A1 the gate closed: South Sudan, and Uganda with the quoting organization off — nothing opened, no table ─────
    foreach ([['south-sudan', []], ['uganda', ['ucrm_quote_organization_id' => 'off']]] as [$tenant, $cfg]) {
        $r = $c->child('
$set = QuoteOrganization::setting($s->load("kyc_config.json") ?? [], $dir);
$cat = UcrmCatalogue::fromStore($s, $set);
$fc  = UcrmCatalogue::forConfig($s, $s->load("kyc_config.json") ?? [], $dir);
$t = $s->getPdo()->query("SELECT COUNT(*) FROM sqlite_master WHERE name = \'ucrm_catalogue\'")->fetchColumn();
$w = UcrmCatalogue::setMode($s, false, null, ["id" => 4, "name" => "A"], $s->load("kyc_config.json") ?? [], $dir);
return ["open" => $cat->open(), "fc" => $fc->open(), "direct" => $fc->direct(), "table_before_write" => (int)$t, "w" => $w["kind"]];
', ['tenant' => $tenant, 'cfg' => $cfg]);
        $v = $r['value'];
        $c->eq([$v['open'] ?? null, $v['fc'] ?? null, $v['direct'] ?? null], [false, false, false], "A1 {$tenant}" . ($cfg ? ' (off)' : '') . ': closed, opens nothing, the switch reads off');
        $c->eq($v['table_before_write'] ?? null, 0, "A1 {$tenant}" . ($cfg ? ' (off)' : '') . ': no ucrm_catalogue table is created by reading');
        $c->eq($v['w'] ?? null, 'gate', "A1 {$tenant}" . ($cfg ? ' (off)' : '') . ': a write is refused by the gate inside the lock');
    }

    // ── A2 the switch: on needs the judged installation to be the one in force; off never needs anything ─────────────
    $r = $c->child(cd_seed([]) . '
$cfg = $s->load("kyc_config.json") ?? [];
$set = QuoteOrganization::setting($cfg, $dir);
$a = ["id" => 4, "name" => "Test Admin"];
$out = [];
$out[] = UcrmCatalogue::setMode($s, true, null, $a, $cfg, $dir)["kind"];          // nothing judged
$out[] = UcrmCatalogue::setMode($s, true, 70, $a, $cfg, $dir)["kind"];            // judged 70, none in force
UcrmCatalogue::setInstall($s, 70, "Inst", $a, $cfg, $dir);
$out[] = UcrmCatalogue::setMode($s, true, 71, $a, $cfg, $dir)["kind"];            // judged another
$out[] = UcrmCatalogue::fromStore($s, $set)->direct();
$out[] = UcrmCatalogue::setMode($s, true, 70, $a, $cfg, $dir)["kind"];
$out[] = UcrmCatalogue::fromStore($s, $set)->direct();
$out[] = UcrmCatalogue::setMode($s, true, 70, $a, $cfg, $dir)["kind"];
$out[] = UcrmCatalogue::setMode($s, false, null, $a, $cfg, $dir)["kind"];
$out[] = UcrmCatalogue::fromStore($s, $set)->direct();
$out[] = UcrmCatalogue::setMode($s, false, null, $a, $cfg, $dir)["kind"];
$h = UcrmCatalogue::fromStore($s, $set)->history("mode");
$out[] = count($h);
$out[] = count(array_filter($h, function ($r) { return $r["ended_at_ts"] === null; }));
return $out;
');
    $c->eq($r['value'], ['changed', 'changed', 'changed', false, 'on', true, 'already', 'off', false, 'already', 2, 1],
        'A2 on refused unless the judged installation is the one in force; on, already, off, already; history kept, one in force');

    // ── A3 the readers fail safe ─────────────────────────────────────────────────────────────────────────────────────
    $r = $c->child('
$two = UcrmCatalogue::fromRecords([
    ["id" => 1, "kind" => "mode", "on" => true, "ended_at_ts" => null],
    ["id" => 2, "kind" => "mode", "on" => true, "ended_at_ts" => null],
    ["id" => 3, "kind" => "install", "product_id" => 70, "ended_at_ts" => null],
    ["id" => 4, "kind" => "install", "product_id" => 71, "ended_at_ts" => null],
]);
$str = UcrmCatalogue::fromRecords([["id" => 1, "kind" => "mode", "on" => "1", "ended_at_ts" => null],
                                   ["id" => 2, "kind" => "install", "product_id" => "70", "ended_at_ts" => null]]);
$ended = UcrmCatalogue::fromRecords([["id" => 1, "kind" => "mode", "on" => true, "ended_at_ts" => 5]]);
$closed = UcrmCatalogue::fromRecords([["id" => 1, "kind" => "mode", "on" => true, "ended_at_ts" => null]], false);
return [$two->direct(), $two->installProductId(), $str->direct(), $str->installProductId(), $ended->direct(), $closed->direct()];
');
    $c->eq($r['value'], [false, null, false, null, false, false],
        'A3 two switch or installation records in force, a non-boolean on, a string id, an ended record, a closed store: off / not set');

    // ── A4 keeping a product off, and offering it again ──────────────────────────────────────────────────────────────
    $r = $c->child(cd_seed([]) . '
$cfg = $s->load("kyc_config.json") ?? [];
$set = QuoteOrganization::setting($cfg, $dir);
$a = ["id" => 4, "name" => "Test Admin"];
$k = [];
$k[] = UcrmCatalogue::setHidden($s, 57, true, "Pipe <b>Adapter</b>", $a, $cfg, $dir)["kind"];
$k[] = UcrmCatalogue::setHidden($s, 57, true, "x", $a, $cfg, $dir)["kind"];
$k[] = UcrmCatalogue::setHidden($s, 56, true, "Mini", $a, $cfg, $dir)["kind"];
$ids1 = UcrmCatalogue::fromStore($s, $set)->hiddenIds();
$name = UcrmCatalogue::fromStore($s, $set)->hiddenRecords()[0]["product_name"] ?? null;
$k[] = UcrmCatalogue::setHidden($s, 57, false, "", $a, $cfg, $dir)["kind"];
$k[] = UcrmCatalogue::setHidden($s, 57, false, "", $a, $cfg, $dir)["kind"];
return ["k" => $k, "ids1" => $ids1, "ids2" => UcrmCatalogue::fromStore($s, $set)->hiddenIds(), "name" => $name,
        "hist" => count(UcrmCatalogue::fromStore($s, $set)->history("hidden"))];
');
    $v = $r['value'];
    $c->eq($v['k'] ?? null, ['hidden', 'already', 'hidden', 'shown', 'already'], 'A4 kept off, already, another, offered again, already');
    $c->eq([$v['ids1'] ?? null, $v['ids2'] ?? null], [[56, 57], [56]], 'A4 the ids kept off, before and after offering one again');
    $c->eq($v['name'] ?? null, 'Pipe bAdapter/b', 'A4 a stored name is plain text (no markup characters)');
    $c->eq($v['hist'] ?? null, 2, 'A4 offering again ends the record; no new record is written for it');

    // ── A5 standard quotations: the shape, the limit of ten, replacing, the clash with the installation, stopping ────
    $r = $c->child(cd_seed([]) . '
$cfg = $s->load("kyc_config.json") ?? [];
$set = QuoteOrganization::setting($cfg, $dir);
$a = ["id" => 4, "name" => "Test Admin"];
$ok = ["title" => "Mini + Residential", "plan" => ["sp" => 6, "p" => 12], "hw" => [["p" => 56, "q" => 1]], "install" => true];
$bad = [
  "no title"   => ["title" => "  "] + $ok,
  "no plan"    => ["plan" => ["sp" => 6]] + $ok,
  "q 0"        => ["hw" => [["p" => 56, "q" => 0]]] + $ok,
  "q 100"      => ["hw" => [["p" => 56, "q" => 100]]] + $ok,
  "q string"   => ["hw" => [["p" => 56, "q" => "1"]]] + $ok,
  "twice"      => ["hw" => [["p" => 56, "q" => 1], ["p" => 56, "q" => 2]]] + $ok,
  "11 items"   => ["hw" => array_map(function ($i) { return ["p" => 100 + $i, "q" => 1]; }, range(1, 11))] + $ok,
  "install ?"  => ["install" => "yes"] + $ok,
];
$out = [];
foreach ($bad as $k => $std) $out[$k] = UcrmCatalogue::saveStandard($s, null, $std, $a, $cfg, $dir)["kind"];
$out["first"] = UcrmCatalogue::saveStandard($s, null, $ok, $a, $cfg, $dir);
$first = (int)$out["first"]["record"]["id"];
for ($i = 2; $i <= 10; $i++) UcrmCatalogue::saveStandard($s, null, ["title" => "S" . $i] + $ok, $a, $cfg, $dir);
$out["eleventh"] = UcrmCatalogue::saveStandard($s, null, ["title" => "S11"] + $ok, $a, $cfg, $dir)["kind"];
$out["replace"] = UcrmCatalogue::saveStandard($s, $first, ["title" => "Mini + Residential v2"] + $ok, $a, $cfg, $dir);
$out["replace_ended"] = UcrmCatalogue::saveStandard($s, $first, $ok, $a, $cfg, $dir)["kind"];
$out["count"] = count(UcrmCatalogue::fromStore($s, $set)->standards());
UcrmCatalogue::setInstall($s, 70, "Inst", $a, $cfg, $dir);
$out["clash"] = UcrmCatalogue::saveStandard($s, null, ["hw" => [["p" => 70, "q" => 1]]] + $ok, $a, $cfg, $dir)["kind"];
$out["install_clash"] = UcrmCatalogue::setInstall($s, 56, "Mini", $a, $cfg, $dir)["kind"];
$out["end"] = UcrmCatalogue::endStandard($s, (int)$out["replace"]["record"]["id"], $a, $cfg, $dir)["kind"];
$out["end_again"] = UcrmCatalogue::endStandard($s, (int)$out["replace"]["record"]["id"], $a, $cfg, $dir)["kind"];
$out["count2"] = count(UcrmCatalogue::fromStore($s, $set)->standards());
$out["title"] = UcrmCatalogue::saveStandard($s, null, ["title" => "  A  <b>&\"x\"  "] + $ok, $a, $cfg, $dir)["record"]["title"] ?? null;
return $out;
');
    $v = $r['value'];
    foreach (['no title', 'no plan', 'q 0', 'q 100', 'q string', 'twice', '11 items', 'install ?'] as $k) {
        $c->eq($v[$k] ?? null, 'invalid', "A5 refused before the lock: {$k}");
    }
    $c->eq([$v['first']['kind'] ?? null, $v['first']['record']['hw'] ?? null], ['saved', [['p' => 56, 'q' => 1]]], 'A5 a well-formed standard quotation is saved by its ids');
    $c->t(!array_key_exists('price', (array)($v['first']['record'] ?? [])) && strpos(json_encode($v['first']['record'] ?? []), 'price') === false,
        'A5 no price is stored (G-12)');
    $c->eq($v['eleventh'] ?? null, 'full', 'A5 an eleventh is refused');
    $c->eq([$v['replace']['kind'] ?? null, $v['replace_ended'] ?? null, $v['count'] ?? null], ['saved', 'changed', 10],
        'A5 replacing ends the old version (a second replace of it is refused); still ten in force');
    $c->eq([$v['clash'] ?? null, $v['install_clash'] ?? null], ['clash', 'clash'],
        'A5 the installation product as hardware is refused, both ways round');
    $c->eq([$v['end'] ?? null, $v['end_again'] ?? null, $v['count2'] ?? null], ['ended', 'changed', 9], 'A5 stopping one, and again');
    $c->eq($v['title'] ?? null, 'A bx', 'A5 the title is stored as plain text, spaces collapsed');

    // ── A6 the gate re-read inside the lock: the quoting organization turned off between the read and the write ──────
    $r = $c->child(cd_seed(cd_records(false)) . '
$cfg = $s->load("kyc_config.json") ?? [];
$off = ["ucrm_quote_organization_id" => "off"] + $cfg;
$w = UcrmCatalogue::setMode($s, true, 70, ["id" => 4, "name" => "A"], $off, $dir);
$after = UcrmCatalogue::fromStore($s, QuoteOrganization::setting($cfg, $dir));
return [$w["kind"], $after->direct(), count($after->records())];
');
    $c->eq($r['value'], ['gate', false, 2], 'A6 the write re-reads the gate inside the lock: refused, nothing written');

    // ── A7 on Uganda, the gate open, a read never creates the table; the first write does (and the control: it reads) ─
    $r = $c->child('
$set = QuoteOrganization::setting($s->load("kyc_config.json") ?? [], $dir);
$has = function () use ($s) { return (int)$s->getPdo()->query("SELECT COUNT(*) FROM sqlite_master WHERE name = \'ucrm_catalogue\'")->fetchColumn(); };
$cat = UcrmCatalogue::fromStore($s, $set);
$fc  = UcrmCatalogue::forConfig($s, $s->load("kyc_config.json") ?? [], $dir);
$before = [$cat->open(), $cat->direct(), $fc->open(), $has()];
UcrmCatalogue::setHidden($s, 57, true, "x", ["id" => 4, "name" => "A"], $s->load("kyc_config.json") ?? [], $dir);
return [$before, $has(), UcrmCatalogue::fromStore($s, $set)->hiddenIds()];
');
    $c->eq($r['value'], [[true, false, true, 0], 1, [57]], 'A7 Uganda: reading opens the store but creates no table (the switch reads off); the first write creates it, and it is read back');
}];
