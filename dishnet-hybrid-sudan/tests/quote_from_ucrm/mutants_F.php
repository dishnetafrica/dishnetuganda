<?php
declare(strict_types=1);
/**
 * The weakened copies of section F (Send Quotation and lead quotes, docs/71 §J): each is one fault planted in a copy of
 * the plugin and must fail section F — on a FAIL line matching its pattern. Appended to $QFU_MUTANTS by the runner.
 * TEST ONLY.
 *
 * 5.18.102 D14 (docs/71 §R.9.3): MF8, MF9 and MF17 re-anchored — the lead step stays by key (MF9: quotePlanKeys now
 * collects every row of a key, so its filter by the one key reads differently). Added: ML20 (one answer per key) and
 * ML25's F half (a Fiber plan row priced through its link on Send Quotation).
 */
$QS  = 'lib/QuotationService.php';
$SQ  = 'tabs/sales/send_quote.php';
$UQL = 'lib/UcrmQuoteLines.php';
$QFU_MUTANTS = array_merge($QFU_MUTANTS, [
    ['label' => 'MF1 a line at 0 counted as priced', 'file' => $QS,
     'old' => "is_numeric(\$price))) && (float)\$price > 0) continue;", 'new' => "is_numeric(\$price))) && (float)\$price >= 0) continue;",
     'sections' => 'F', 'pattern' => '/F1 /'],
    ['label' => 'MF2 the lead quote sent with a line of no price', 'file' => $QS,
     'old' => "        // or sent.\n        if (\$this->quoteOrg()['on'] && (\$unpriced = \$this->unpricedLine(\$items)) !== '') {",
     'new' => "        // or sent.\n        if (false && (\$unpriced = \$this->unpricedLine(\$items)) !== '') {",
     'sections' => 'F', 'pattern' => '/F1 a lead quote with a line of no price/'],
    ['label' => 'MF3 the manual quote sent with a line of no price', 'file' => $QS,
     'old' => "        // or sent. Lines staff type are otherwise sent as typed (no productId).\n        if (\$this->quoteOrg()['on'] && (\$unpriced",
     'new' => "        // or sent. Lines staff type are otherwise sent as typed (no productId).\n        if (false && (\$unpriced",
     'sections' => 'F', 'pattern' => '/F1 Send Quotation/'],
    ['label' => 'MF4 a nameless line refused without a name', 'file' => $QS,
     'old' => "return (\$label !== '' ? \$label : 'A line') . ' has no price", 'new' => "return \$label . ' has no price",
     'sections' => 'F', 'pattern' => '/A line/'],
    ['label' => 'MF5 the customer\'s notes carry the reference and the agent again', 'file' => $QS,
     'old' => "            \$payload['notes']      = trim((\$notesPrefix !== '' ? \$notesPrefix . \"\\n\" : '') . trim(\$note));\n",
     'new' => '', 'sections' => 'F', 'pattern' => '/F2 /'],
    ['label' => 'MF6 adminNotes without the agent', 'file' => $QS,
     'old' => "\$payload['adminNotes'] = \"Plugin ref: {\$quoteRef} · Agent: \" . (\$retailer['name'] ?? 'Agent');",
     'new' => "\$payload['adminNotes'] = \"Plugin ref: {\$quoteRef}\";", 'sections' => 'F', 'pattern' => '/adminNotes/'],
    ['label' => 'MF7 a lead\'s notes sent in its WhatsApp', 'file' => $QS,
     'old' => "        if (\$this->quoteOrg()['on']) \$lead['notes'] = '';\n", 'new' => '',
     'sections' => 'F', 'pattern' => '/F3 /'],
    ['label' => 'MF8 a lead\'s line found by the old substring match at the plugin\'s price', 'file' => $QS,
     'old' => "            if (\$key === '') return [];\n            \$hit = \$this->quotePlanKeys(\$this->quoteLines(false), \$key)[\$key] ?? null;\n            return (\$hit !== null && !empty(\$hit['ok']) && is_array(\$hit['line'] ?? null)) ? [\$hit['line']] : [];",
     'new' => "            if (\$key === '') return [];\n            \$hit = \$this->quotePlanKeys(\$this->quoteLines(false), \$key)[\$key] ?? null;\n            if (\$hit === null || empty(\$hit['ok'])) { foreach (\$this->quotePlanRows() as \$r) if (stripos((string)(\$r['name'] ?? ''), \$interest) !== false) return [['label' => (string)\$r['name'], 'quantity' => 1, 'price' => (float)(\$r['customer_price'] ?? 0), 'unit' => 'month']]; }\n            return (\$hit !== null && !empty(\$hit['ok']) && is_array(\$hit['line'] ?? null)) ? [\$hit['line']] : [];",
     'sections' => 'F', 'pattern' => '/F4 /'],
    // re-anchored by D14: quotePlanKeys() gathers the rows of each key before it answers (§R.4.5)
    ['label' => 'MF9 every plan row read for one lead\'s key', 'file' => $QS,
     'old' => "if (\$key === '' || (\$only !== null && \$key !== \$only)) continue;",
     'new' => "if (\$key === '') continue;", 'sections' => 'F', 'pattern' => '/no uCRM request/'],
    ['label' => 'MF10 a lead\'s line read from the page\'s 60-second cache', 'file' => $QS,
     'old' => "\$hit = \$this->quotePlanKeys(\$this->quoteLines(false), \$key)[\$key] ?? null;",
     'new' => "\$hit = \$this->quotePlanKeys(\$this->quoteLines(true), \$key)[\$key] ?? null;", 'sections' => 'F', 'pattern' => '/read fresh/'],
    ['label' => 'MF11 the picker priced from customer_price on Uganda', 'file' => $SQ,
     'old' => "    \$plansJson   = json_encode(\$ugPick['plans'], \$ugJsonFlags) ?: '[]';\n", 'new' => '',
     'sections' => 'F', 'pattern' => '/F5 the picker/'],
    ['label' => 'MF12 a failed catalogue read kept for the page', 'file' => $UQL,
     'old' => "        if (!empty(\$cat['ok'])) {\n            \$json = json_encode(", 'new' => "        if (true) {\n            \$json = json_encode(",
     'sections' => 'F', 'pattern' => '/never kept/'],
    ['label' => 'MF13 the banner\'s reason unescaped', 'file' => $SQ,
     'old' => "<?= htmlspecialchars((string)(\$ugPick['reason'] ?: \"uCRM's products could not be read\")) ?>",
     'new' => "<?= (string)(\$ugPick['reason'] ?: \"uCRM's products could not be read\") ?>", 'sections' => 'F', 'pattern' => '/escaped \(htmlspecialchars\)/'],
    ['label' => 'MF14 uCRM\'s names written into the page\'s script unescaped', 'file' => $SQ,
     'old' => "    \$ugJsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE;",
     'new' => "    \$ugJsonFlags = JSON_INVALID_UTF8_SUBSTITUTE;", 'sections' => 'F', 'pattern' => '/JSON_HEX_TAG/'],
    ['label' => 'MF15 the customer\'s notes rewritten on every tenant', 'file' => $QS,
     'old' => "        if (\$this->quoteOrg()['on']) {\n            \$payload['notes']", 'new' => "        if (true) {\n            \$payload['notes']",
     'sections' => 'F', 'pattern' => '/F6 South Sudan/'],
    ['label' => 'MF16 the row escaping emitted on every tenant', 'file' => $SQ,
     'old' => "function addItem(ctx, label='', price=0, qty=1, unit='month') {\n<?php if (\$ugQuote): ?>",
     'new' => "function addItem(ctx, label='', price=0, qty=1, unit='month') {\n<?php if (true): ?>",
     'sections' => 'F', 'pattern' => '/F6 /'],
    ['label' => 'MF17 a lead pre-filled by its interest as typed, not its key', 'file' => $QS,
     'old' => "            \$leadKeys[(string)(int)(\$l['id'] ?? 0)] = PublicPriceFeed::planKey(\n                is_scalar(\$l['interest_plan'] ?? null) ? (string)\$l['interest_plan'] : '');",
     'new' => "            \$leadKeys[(string)(int)(\$l['id'] ?? 0)] = (\n                is_scalar(\$l['interest_plan'] ?? null) ? (string)\$l['interest_plan'] : '');",
     'sections' => 'F', 'pattern' => '/SQ_LEAD_KEYS/'],
    ['label' => 'MF18 the gate read as always off for the picker', 'file' => $SQ,
     'old' => "\$ugQuote = !empty(QuoteOrganization::setting(\$cfg, \$dataDir)['on']);", 'new' => '$ugQuote = false;',
     'sections' => 'F', 'pattern' => '/F5 /'],

    // ── 5.18.102 D14 (docs/71 §R.9.3) ───────────────────────────────────────────────────────────────────────────────
    ['label' => 'ML20 quotePlanKeys keeps one answer per key (the first of two rows spelled alike)', 'file' => $QS,
     'old' => "            if (count(\$rows) !== 1) continue;   // two rows spelled alike: no pre-fill (§R.4.5)\n", 'new' => '',
     'sections' => 'F', 'pattern' => '/F7 /'],
    ['label' => 'ML20 (as 5.18.102 was first built) one answer per key, which the picker shares too', 'edits' => [
        [$QS, "            if (count(\$rows) !== 1) continue;   // two rows spelled alike: no pre-fill (§R.4.5)\n", ''],
        [$QS, "            \$hit = \$lines->planLine(\$row);\n", "            \$hit = \$keyed[PublicPriceFeed::planKey(\$name)] ?? \$lines->planLine(\$row);\n"],
     ], 'sections' => 'F', 'pattern' => '/F7 the picker answers per row/'],
    ['label' => 'ML25 (F) a Fiber plan row priced through its link on Send Quotation', 'file' => $UQL,
     'old' => "        if (UcrmQuoteLinks::isFiber('plan', \$row)) return self::no(self::FIBER_REASON, 'fiber');\n", 'new' => '',
     'sections' => 'F', 'pattern' => '/F8 the Fiber row/'],
    // 5.18.102 D14 review (D14-UI-1): the lead hint's cause when two plan rows share the lead's spelling
    ['label' => 'ML-UI1a a key two rows hold not marked shared (the server)', 'file' => $QS,
     'old' => "foreach (\$held as \$k => \$n) if (\$n > 1) \$shared[] = (string)\$k;",
     'new' => "foreach (\$held as \$k => \$n) if (\$n > 2) \$shared[] = (string)\$k;",
     'sections' => 'F', 'pattern' => '/F7 … SQ_LEAD_SHARED|F7 … the hint, by the page/'],
    ['label' => 'ML-UI1b the page ignores the shared key (38991cbb\'s hint: "No plan in the list is spelled like…")', 'file' => $SQ,
     'old' => "key !== '' && SQ_LEAD_SHARED.indexOf(key) !== -1",
     'new' => "false && SQ_LEAD_SHARED.indexOf(key) !== -1",
     'sections' => 'F', 'pattern' => '/F7 … the hint, by the page/'],
    // 5.18.102 D14 review (D14-UI-2): the picker's alert keeps 38991cbb's tail beside a link reason
    ['label' => 'MF-alert the picker alert keeps its "fix the product in uCRM" tail beside a link reason', 'file' => $SQ,
     'old' => "It cannot be added from the list — ask an admin, or type the item and its price yourself.');",
     'new' => "It cannot be added from the list — ask an admin to fix the product in uCRM, or type the item and its price yourself.');",
     'sections' => 'F', 'pattern' => '/F5 … picking it alerts/'],
]);
