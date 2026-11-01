<?php
declare(strict_types=1);
/**
 * The weakened copies of section E (Send Quotation and the lead quotes with the uCRM catalogue, root docs/72 §G.9, G-14,
 * G-15): each is one fault planted in a copy of the plugin, a fault the code could really get — a check removed, a
 * condition inverted, a field trusted from the browser — and must fail section E on a FAIL line matching its pattern.
 * Appended to $QFU_MUTANTS by the runner. TEST ONLY.
 */
$QS  = 'lib/QuotationService.php';
$UQL = 'lib/UcrmQuoteLines.php';
$PL  = 'includes/post/post_leads.php';
$SQ  = 'tabs/sales/send_quote.php';
$QFU_MUTANTS = array_merge($QFU_MUTANTS, [
    // ── QuotationService: the re-pricing every send goes through (G-15) ──────────────────────────────────────────
    ['label' => 'ME1 Send Quotation sends its picked lines without reading uCRM again', 'file' => $QS,
     'old' => "        if (is_array(\$items) && \$items !== [] && \$this->directMode()) {",
     'new' => "        if (false && is_array(\$items) && \$items !== [] && \$this->directMode()) {",
     'sections' => 'E', 'pattern' => '/E0 the control: Send Quotation/'],
    ['label' => 'ME2 a lead quote (the page and the API) sends its picked lines without reading uCRM again', 'file' => $QS,
     'old' => "        if (\$items !== [] && \$this->directMode()) {",
     'new' => "        if (false && \$items !== [] && \$this->directMode()) {",
     'sections' => 'E', 'pattern' => '/E0 the control: a lead quote/'],
    ['label' => 'ME3 a picked line\'s posted price not compared with uCRM\'s', 'file' => $QS,
     'old' => "if (\$pk['price'] === null || abs(round((float)\$hit['price'], 2) - \$pk['price']) > 0.0001) {",
     'new' => "if (\$pk['price'] === null) {",
     'sections' => 'E', 'pattern' => '/E1 a picked kit posted at 1400/'],
    ['label' => 'ME4 a picked line sent with the label the browser posted', 'file' => $QS,
     'old' => "\$out[] = self::directSendLine(\$hit, \$pk['q']);",
     'new' => "\$out[] = ['label' => \$pk['label']] + self::directSendLine(\$hit, \$pk['q']);",
     'sections' => 'E', 'pattern' => '/E1 a picked kit posted with another label/'],
    ['label' => 'ME5 a typed line keeps the productId it carries', 'file' => $QS,
     'old' => "if (is_array(\$item)) unset(\$item['productId']);", 'new' => '',
     'sections' => 'E', 'pattern' => '/E3 the API/'],
    ['label' => 'ME6 a picked quantity above 99 accepted', 'file' => $QS,
     'old' => "            if (\$pk['p'] <= 0 || \$pk['q'] < 1 || \$pk['q'] > UcrmQuoteLines::MAX_QTY || (\$pk['kind'] === 'plan' && \$pk['sp'] <= 0)) {",
     'new' => "            if (\$pk['p'] <= 0 || \$pk['q'] < 1 || (\$pk['kind'] === 'plan' && \$pk['sp'] <= 0)) {",
     'sections' => 'E', 'pattern' => '/E2 a quantity of 100/'],
    ['label' => 'ME7 a send re-priced from the page\'s 60-second cache, not a fresh read', 'file' => $QS,
     'old' => "\$this->directOfferedMemo = UcrmQuoteLines::forDirectUse(\$this->crm, [], \$this->quoteOrg())",
     'new' => "\$this->directOfferedMemo = UcrmQuoteLines::forDirectPage(\$this->crm, [], \$this->quoteOrg(), \$this->dataDir)['uql']",
     'sections' => 'E', 'pattern' => '/E1 uCRM.s price changed after the page was drawn/'],
    ['label' => 'ME8 a direct page\'s marker refused where the quoting gate is off (South Sudan)', 'file' => $QS,
     'old' => "        if (\$cat === null) return '';", 'new' => "        if (\$cat === null) return 'off';",
     'sections' => 'E', 'pattern' => '/E10 South Sudan/'],
    ['label' => 'ME9 buildLeadItems ignores direct mode (5.18.102\'s plan rows and links)', 'file' => $QS,
     'old' => "            if (\$this->directMode()) return \$this->directLeadItems(\$lead);\n", 'new' => '',
     'sections' => 'E', 'pattern' => '/E7 a lead for "Residential Standard/'],
    ['label' => 'ME10 a manual quote\'s WhatsApp sent under a new event key', 'file' => $QS,
     'old' => "\$waSent = \$waByEvent ? true : \$this->sendWA(\$phone, \$msg, 'quote_manual');",
     'new' => "\$waSent = \$waByEvent ? true : \$this->sendWA(\$phone, \$msg, 'quote_direct');",
     'sections' => 'E', 'pattern' => '/E6 the manual send/'],
    // ── UcrmQuoteLines::offered(): what a picked id may be ───────────────────────────────────────────────────────
    ['label' => 'ME11 the installation product offered as hardware too', 'file' => $UQL,
     'old' => "if (\$pid <= 0 || isset(\$planProducts[\$pid]) || (\$installId !== null && \$pid === \$installId)) continue;",
     'new' => "if (\$pid <= 0 || isset(\$planProducts[\$pid])) continue;",
     'sections' => 'E', 'pattern' => '/E2 the installation picked as hardware/'],
    ['label' => 'ME12 a product kept off by an admin offered all the same', 'file' => $UQL,
     'old' => "                if (isset(\$off[\$pid])) {", 'new' => "                if (false && isset(\$off[\$pid])) {",
     'sections' => 'E', 'pattern' => '/E2 a product kept off/'],
    // ── includes/post/post_leads.php: the two handlers ───────────────────────────────────────────────────────────
    ['label' => 'ME13 Send Quotation\'s handler filters picked lines by their posted price', 'file' => $PL,
     'old' => "        if (\$sqForm === 'direct' && (\$sqLine = QuotationService::pickedLine(\$it)) !== null) { \$items[] = \$sqLine; continue; }   // 5.18.103 (§G.9)\n        \$price = (float)preg_replace('/[^0-9.]/', '', (string)(\$it['price'] ?? '0'));\n        if (\$price > 0) {",
     'new' => "        \$price = (float)preg_replace('/[^0-9.]/', '', (string)(\$it['price'] ?? '0'));\n        if (\$price <= 0) continue;\n        if (\$sqForm === 'direct' && (\$sqLine = QuotationService::pickedLine(\$it)) !== null) { \$items[] = \$sqLine; continue; }\n        if (\$price > 0) {",
     'sections' => 'E', 'pattern' => '/E5 Send Quotation: a picked plan posted at 0/'],
    ['label' => 'ME14 the lead quote\'s handler reads picked lines as typed ones', 'file' => $PL,
     'old' => "        if (\$sqForm === 'direct' && (\$sqLine = QuotationService::pickedLine(\$it)) !== null) { \$items[] = \$sqLine; continue; }   // 5.18.103 (§G.9)\n        \$price = (float)preg_replace('/[^0-9.]/', '', (string)(\$it['price'] ?? '0'));\n        if (\$price > 0 || !empty(\$it['label'])) {",
     'new' => "        \$price = (float)preg_replace('/[^0-9.]/', '', (string)(\$it['price'] ?? '0'));\n        if (\$price > 0 || !empty(\$it['label'])) {",
     'sections' => 'E', 'pattern' => '/E5 the lead quote: a picked plan posted at 0/'],
    ['label' => 'ME15 Send Quotation sends a page drawn under the switch after it was turned off', 'file' => $PL,
     'old' => "    if (\$sqForm === 'off') { flash('Failed: ' . QuotationService::DIRECT_OFF_TEXT, 'danger'); redirect('?page=dashboard&tab=leads'); }\n",
     'new' => '', 'sections' => 'E', 'pattern' => '/E10 the switch off: a direct page.s Send Quotation refused/'],
    // ── tabs/sales/send_quote.php: the page ──────────────────────────────────────────────────────────────────────
    ['label' => 'ME16 the manual form drawn without its marker', 'file' => $SQ,
     'old' => "id=\"manualItemsJson\" value=\"[]\">\n<?php if (\$sqDirect !== null):",
     'new' => "id=\"manualItemsJson\" value=\"[]\">\n<?php if (false):",
     'sections' => 'E', 'pattern' => '/marker/'],
    ['label' => 'ME17 a standard quotation that cannot be used drawn as a usable button', 'file' => $SQ,
     'old' => "<?= \$s['ok'] ? '' : ' disabled' ?>", 'new' => "<?= '' ?>",
     'sections' => 'E', 'pattern' => '/E8 a standard quotation whose kit uCRM archived/'],
    ['label' => 'ME18 the stale banner not drawn over the last good list', 'file' => $SQ,
     'old' => "<?php elseif (\$sqDirect['stale']):", 'new' => "<?php elseif (false):",
     'sections' => 'E', 'pattern' => '/E8 uCRM down with a last good list/'],
    ['label' => 'ME19 the page posts a picked plan without its service plan', 'file' => $SQ,
     'old' => "      if (l.kind === 'plan') it.sp = l.sp;", 'new' => '',
     'sections' => 'E', 'pattern' => '/E9 /'],
]);
