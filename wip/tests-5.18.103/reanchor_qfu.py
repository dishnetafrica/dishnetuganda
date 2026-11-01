# Re-anchor 5.18.102's weakened copies (tests/quote_from_ucrm) onto 5.18.103's code: each plants the SAME fault where the
# code now lives (its anchor moved: the direct branch, $directOn, the last good copy). Asserted: each old mutant text
# exactly once in its file; each new anchor exactly once in the release's target file; the result holds the new text.
import sys, os
T = sys.argv[1]            # .../dishnet-hybrid-sudan
Q = os.path.join(T, "tests/quote_from_ucrm")
def rd(p): return open(p, encoding="utf-8").read()
def code_once(rel, s):
    c = rd(os.path.join(T, rel)).count(s)
    assert c == 1, (rel, c, s[:90])
EDITS = []   # (mutants file, old php literal text, new php literal text, target file, new anchor as it is in the code)
E = EDITS.append
# MA10 — the page cache written on a failed read (5.18.103: $json first, then the cache and the last good copy)
E(("mutants.php",
   "'old' => \"        if (!empty(\\$cat['ok'])) {\\n            @file_put_contents(\", 'new' => \"        if (true) {\\n            @file_put_contents(\",",
   "'old' => \"        if (!empty(\\$cat['ok'])) {\\n            \\$json = json_encode(\", 'new' => \"        if (true) {\\n            \\$json = json_encode(\",",
   "lib/UcrmQuoteLines.php", "        if (!empty($cat['ok'])) {\n            $json = json_encode("))
# MC9b — saleFromApp's entry (5.18.103: the direct branch first)
E(("mutants.php",
   "'old' => \"            'entry'             => (\\$app['quote_sale_entry'] ?? null) === 'web' ? 'web' : 'app',\",\n     'new' => \"            'entry'             => ((\\$app['quote_sale_entry'] ?? null) === 'web' || (string)(\\$app['quote_sale_kit_qty'] ?? '1') !== '1') ? 'web' : 'app',\",",
   "'old' => \"            'entry'             => \\$direct ? UcrmQuoteLines::DIRECT_ENTRY : ((\\$app['quote_sale_entry'] ?? null) === 'web' ? 'web' : 'app'),\",\n     'new' => \"            'entry'             => \\$direct ? UcrmQuoteLines::DIRECT_ENTRY : (((\\$app['quote_sale_entry'] ?? null) === 'web' || (string)(\\$app['quote_sale_kit_qty'] ?? '1') !== '1') ? 'web' : 'app'),\",",
   "lib/KycService.php", "            'entry'             => $direct ? UcrmQuoteLines::DIRECT_ENTRY : (($app['quote_sale_entry'] ?? null) === 'web' ? 'web' : 'app'),"))
# ML38 (A) — saleFromPost's entry (5.18.103: computed before the array, by the switch)
E(("mutants.php",
   "'old' => \"            'entry'             => (\\$post['ucrm_quote_form'] ?? null) === UcrmQuoteLines::FORM_MARKER ? 'web' : 'app',\",\n     'new' => \"            'entry'             => 'web',\",",
   "'old' => \"            \\$entry = \\$marker === UcrmQuoteLines::FORM_MARKER ? 'web' : 'app';\",\n     'new' => \"            \\$entry = 'web';\",",
   "lib/KycService.php", "            $entry = $marker === UcrmQuoteLines::FORM_MARKER ? 'web' : 'app';"))
# ML38 (C) — every stored application read as web (the direct branch kept: the fault is 5.18.102's)
E(("mutants_C.php",
   "'old' => \"            'entry'             => (\\$app['quote_sale_entry'] ?? null) === 'web' ? 'web' : 'app',\",\n     'new' => \"            'entry'             => 'web',\",",
   "'old' => \"            'entry'             => \\$direct ? UcrmQuoteLines::DIRECT_ENTRY : ((\\$app['quote_sale_entry'] ?? null) === 'web' ? 'web' : 'app'),\",\n     'new' => \"            'entry'             => \\$direct ? UcrmQuoteLines::DIRECT_ENTRY : 'web',\",",
   "lib/KycService.php", "            'entry'             => $direct ? UcrmQuoteLines::DIRECT_ENTRY : (($app['quote_sale_entry'] ?? null) === 'web' ? 'web' : 'app'),"))
# ML59 (a) — no quote_sale_entry read as web
E(("mutants_C.php",
   "'old' => \"            'entry'             => (\\$app['quote_sale_entry'] ?? null) === 'web' ? 'web' : 'app',\",\n     'new' => \"            'entry'             => (\\$app['quote_sale_entry'] ?? 'web') === 'web' ? 'web' : 'app',\",",
   "'old' => \"            'entry'             => \\$direct ? UcrmQuoteLines::DIRECT_ENTRY : ((\\$app['quote_sale_entry'] ?? null) === 'web' ? 'web' : 'app'),\",\n     'new' => \"            'entry'             => \\$direct ? UcrmQuoteLines::DIRECT_ENTRY : ((\\$app['quote_sale_entry'] ?? 'web') === 'web' ? 'web' : 'app'),\",",
   "lib/KycService.php", "            'entry'             => $direct ? UcrmQuoteLines::DIRECT_ENTRY : (($app['quote_sale_entry'] ?? null) === 'web' ? 'web' : 'app'),"))
# ML59 (b) — the entry rule (5.18.103: an elseif after the direct branch)
E(("mutants_C.php",
   "'old' => \"        if (\\$sale['entry'] !== 'web') {\",\n     'new' => \"        if (\\$sale['entry'] !== 'web' && ",
   "'old' => \"        } elseif (\\$sale['entry'] !== 'web') {\",\n     'new' => \"        } elseif (\\$sale['entry'] !== 'web' && ",
   "lib/KycService.php", "        } elseif ($sale['entry'] !== 'web') {"))
# ML51 (C) retryLines — P1_UNLINKED not used (5.18.103: p1Text takes the why and the switch)
E(("mutants_C.php",
   "'old' => \"                    'text' => self::p1Text(['reason' => \\$reason, 'unlinked' => \\$unlinked, 'entry' => \\$sale['entry']])];\",",
   "'old' => \"                    'text' => self::p1Text(['reason' => \\$reason, 'unlinked' => \\$unlinked, 'entry' => \\$sale['entry'], 'why' => \\$why],\\n                        \\$cat->direct())];\",",
   "lib/KycService.php", "                    'text' => self::p1Text(['reason' => $reason, 'unlinked' => $unlinked, 'entry' => $sale['entry'], 'why' => $why],\n                        $cat->direct())];"))
# ML53 — the entry condition dropped (5.18.103: $entry, direct or web with the switch off)
E(("mutants_C.php",
   "'old' => \"        return (\\$app['quote_sale_entry'] ?? null) === 'web'\\n            && !empty(\\$app['crm_client_id'])\",",
   "'old' => \"        return (\\$entry === UcrmQuoteLines::DIRECT_ENTRY || (\\$entry === 'web' && !\\$directOn))\\n            && !empty(\\$app['crm_client_id'])\",",
   "lib/KycService.php", "        return ($entry === UcrmQuoteLines::DIRECT_ENTRY || ($entry === 'web' && !$directOn))\n            && !empty($app['crm_client_id'])"))
# ML53 — not an admin; the gate off (5.18.103: the button passes the switch) — three occurrences in mutants_C.php
OLDB = "<?php if (\\$ugOrders102 && !empty(\\$isAdmin) && KycService::canMakeQuotation(\\$a)):"
NEWB = "<?php if (\\$ugOrders102 && !empty(\\$isAdmin) && KycService::canMakeQuotation(\\$a, \\$ugDirect103) /* 5.18.103 (§G.8) */):"
for p in ("mutants.php", "mutants_C.php"):
    t = rd(os.path.join(Q, p)); before = t
    for (f, old, new, tgt, anc) in [e for e in EDITS if e[0] == p]:
        assert t.count(old) == 1, (p, t.count(old), old[:100])
        code_once(tgt, anc)
        t = t.replace(old, new); assert t.count(new) >= 1
    if p == "mutants_C.php":
        assert t.count(OLDB) == 2, t.count(OLDB)             # the admin copy's old, the gate-off copy's first edit's old
        t = t.replace(OLDB, NEWB); assert t.count(NEWB) == 2
        t2 = t.replace("\"<?php if (\\$ugOrders102 && KycService::canMakeQuotation(\\$a)):\"",
                       "\"<?php if (\\$ugOrders102 && KycService::canMakeQuotation(\\$a, \\$ugDirect103) /* 5.18.103 (§G.8) */):\"")
        assert t2 != t; t = t2
        t2 = t.replace("\"<?php if (!empty(\\$isAdmin) && KycService::canMakeQuotation(\\$a)):\"",
                       "\"<?php if (!empty(\\$isAdmin) && KycService::canMakeQuotation(\\$a, \\$ugDirect103) /* 5.18.103 (§G.8) */):\"")
        assert t2 != t; t = t2
        code_once("tabs/sales/applications.php", "<?php if ($ugOrders102 && !empty($isAdmin) && KycService::canMakeQuotation($a, $ugDirect103) /* 5.18.103 (§G.8) */):")
        code_once("tabs/sales/applications.php", "$ugQuoteLink102 = null;\n")
    assert t != before
    open(os.path.join(Q, p), "w", encoding="utf-8").write(t)
    print("rewrote", p)

# ── Phase 2: the copies the static check (plantable_qfu.php) found beyond the first ten, each the same fault where the
# code now lives. (mutants file, old literal text, new literal text, expected count in that file)
SUBS = [
    # ML79 — recorded on a refusal: the refusal line gained $claim in 5.18.102's round 11 (CK11-B2), after this copy was
    # written — it could not be planted on 5.18.102 either (static check on 1a0ea5f: the only one)
    ("mutants_C.php", "self::recordNoQuote(\\$this->store, \\$appId, \\$r['code'], \\$r['text']);",
     "self::recordNoQuote(\\$this->store, \\$appId, \\$r['code'], \\$r['text'], \\$claim);   // round 11 (CK11-B2): never before the claim", 2),
    # ML85k, ML89d, ML89e (C); MG26, MG28 (G) — the box's sentence: canMakeQuotation passes the switch (§G.8)
    ("mutants_C.php", "KycService::canMakeQuotation(\\$a) ? 'Do not make",
     "KycService::canMakeQuotation(\\$a, \\$ugDirect103) /* 5.18.103 (§G.8) */ ? 'Do not make", 3),
    ("mutants_C.php", "(KycService::canMakeQuotation(\\$a) || is_numeric(", "(KycService::canMakeQuotation(\\$a, \\$ugDirect103) || is_numeric(", 1),
    ("mutants_C.php", "(!empty(\\$isAdmin) && KycService::canMakeQuotation(\\$a)) || is_numeric(",
     "(!empty(\\$isAdmin) && KycService::canMakeQuotation(\\$a, \\$ugDirect103)) || is_numeric(", 1),
    ("mutants_G.php", "KycService::canMakeQuotation(\\$a) ? 'Do not make",
     "KycService::canMakeQuotation(\\$a, \\$ugDirect103) /* 5.18.103 (§G.8) */ ? 'Do not make", 3),
    # ML53 (G8) — the button with the gate off (its require moved out first, as before)
    ("mutants_G.php", "\"<?php if (\\$ugOrders102 && !empty(\\$isAdmin) && KycService::canMakeQuotation(\\$a)):\"",
     "\"<?php if (\\$ugOrders102 && !empty(\\$isAdmin) && KycService::canMakeQuotation(\\$a, \\$ugDirect103) /* 5.18.103 (§G.8) */):\"", 1),
    ("mutants_G.php", "\"<?php if (!empty(\\$isAdmin) && KycService::canMakeQuotation(\\$a)):\"",
     "\"<?php if (!empty(\\$isAdmin) && KycService::canMakeQuotation(\\$a, \\$ugDirect103) /* 5.18.103 (§G.8) */):\"", 1),
    # MF12 — a failed read kept for the page (5.18.103: $json first, then the cache and the last good copy), as MA10
    ("mutants_F.php", "'old' => \"        if (!empty(\\$cat['ok'])) {\\n            @file_put_contents(\\$file,\", 'new' => \"        if (true) {\\n            @file_put_contents(\\$file,\",",
     "'old' => \"        if (!empty(\\$cat['ok'])) {\\n            \\$json = json_encode(\", 'new' => \"        if (true) {\\n            \\$json = json_encode(\",", 1),
    # ML19, ML19i (L) — the delete hooks ungated (5.18.103: the catalogue's freeze between the require and the try)
    ("mutants_L.php", "            . \"        try {\\n            \\$_dlEnded = UcrmQuoteLinks::end(\\$store, '",
     "            . \"        require_once dirname(__DIR__, 2) . '/lib/UcrmCatalogue.php';    // 5.18.103 (root docs/72 §G G-11): frozen while on\\n\"\n"
     "            . \"        if (!UcrmCatalogue::forConfig(\\$store, is_array(\\$config ?? null) ? \\$config : [], isset(\\$dataDir) ? (string)\\$dataDir : null)->direct()) try {\\n            \\$_dlEnded = UcrmQuoteLinks::end(\\$store, '", 4),
]
for p in sorted({s[0] for s in SUBS}):
    t = rd(os.path.join(Q, p)); before = t
    for (f, old, new, n) in [s for s in SUBS if s[0] == p]:
        c = t.count(old)
        assert c == n, (p, c, n, old[:100])
        t = t.replace(old, new)
        assert t.count(new) >= n, (p, new[:100])
    assert t != before
    open(os.path.join(Q, p), "w", encoding="utf-8").write(t)
    print("rewrote", p)
code_once("tabs/sales/applications.php", "!empty($isAdmin) && KycService::canMakeQuotation($a, $ugDirect103) /* 5.18.103 (§G.8) */ ? 'Do not make")
code_once("lib/KycService.php", "                self::recordNoQuote($this->store, $appId, $r['code'], $r['text'], $claim);   // round 11 (CK11-B2): never before the claim\n                return self::madeAnswer(409, 'refused', null, 0, null, $r['text']);")
code_once("includes/post/post_sync.php", "        require_once dirname(__DIR__, 2) . '/lib/UcrmCatalogue.php';    // 5.18.103 (root docs/72 §G G-11): frozen while on\n        if (!UcrmCatalogue::forConfig($store, is_array($config ?? null) ? $config : [], isset($dataDir) ? (string)$dataDir : null)->direct()) try {\n            $_dlEnded = UcrmQuoteLinks::end($store, 'plan',")
print("phase 2 done")
