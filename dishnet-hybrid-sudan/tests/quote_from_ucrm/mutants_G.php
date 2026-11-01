<?php
declare(strict_types=1);
/**
 * The weakened copies of section G (the documents and screens, docs/71 §E.2, §F.1, §F.6, §K): each is one fault planted
 * in a copy of the plugin and must fail section G — on a FAIL line matching its pattern. Appended to $QFU_MUTANTS by the
 * runner. TEST ONLY.
 *
 * 5.18.102 D14 (docs/71 §R.9.3): MG8–MG12, MG18 and MG19 carried as they are. Added: the G halves of ML15 (the link
 * save writes the plugin row: G3), ML18 (a link column, picker or action on a gate-off tree: G8), ML49 and ML50 (Product
 * Mapping's claims with the gate on: G9), ML53 and ML79 (Make the quotation drawn, or its handler run, with the gate off:
 * G8 — G8 cannot see a retry's write, so ML79's G8 half is the handler's); and MG22–MG25 for what G3
 * and G4 gain (Auto-Map and set_product_mapping refused on Uganda, hw_ucrm_product_id ignored there, a kit's own save
 * never touching the link store).
 */
$PDF = 'lib/PluginQuotePdf.php';
$DEL = 'lib/DeliveryPdfService.php';
$PS  = 'includes/post/post_sync.php';
$API = 'includes/api/api_products_admin.php';
$NS  = 'lib/NotificationService.php';
$QFU_MUTANTS = array_merge($QFU_MUTANTS, [
    ['label' => 'MG1 the Uganda header on every tenant', 'file' => $PDF,
     'old' => "        if (\$ug) {\n            \$html = str_replace('Internet Service Provider &middot; {{HEADER_LOCALITY}}',",
     'new' => "        if (true) {\n            \$html = str_replace('Internet Service Provider &middot; {{HEADER_LOCALITY}}',",
     'sections' => 'G', 'pattern' => '/G1 the fallback PDF\'s HTML/'],
    ['label' => 'MG2 item 1 always QuoteTaxLine::TEXT', 'file' => $PDF,
     'old' => "(\$hasTax ? 'VAT is itemised in the totals.' : htmlspecialchars(QuoteTaxLine::TEXT))",
     'new' => "htmlspecialchars(QuoteTaxLine::TEXT)", 'sections' => 'G', 'pattern' => '/G1 a quote that carries tax/'],
    ['label' => 'MG3 a tax\'s name unescaped', 'file' => $PDF,
     'old' => "border-bottom:1px solid #eee;\">' . htmlspecialchars((string)\$ugTax['name']) . '</td>'",
     'new' => "border-bottom:1px solid #eee;\">' . (string)\$ugTax['name'] . '</td>'", 'sections' => 'G', 'pattern' => '/escaped/'],
    ['label' => 'MG4 the discount by the subtotal alone', 'file' => $PDF,
     'old' => "\$ugDiscount = round(\$subtotal + \$ugTaxTotal - \$total, 2);", 'new' => "\$ugDiscount = round(\$subtotal - \$total, 2);",
     'sections' => 'G', 'pattern' => '/the rows add up/'],
    ['label' => 'MG5 the withdrawn terms kept on Uganda', 'file' => $PDF,
     'old' => "            \$termsHtml  = \$this->buildUgandaTerms(\$isStarlink, \$isFiber, \$ugTaxLines !== []);\n", 'new' => '',
     'sections' => 'G', 'pattern' => '/G1 (exactly five terms|none of the withdrawn|a quote with no tax)/'],
    ['label' => 'MG6 the Delivery PDF generated, then not sent', 'file' => $DEL,
     'edits' => [
        [$DEL, "        if (QuoteOrganization::setting(\$this->config, \$this->dataDir)['on']) {\n            return ['ok' => false, 'skipped' => 'uganda',",
               "        if (false) {\n            return ['ok' => false, 'skipped' => 'uganda',"],
        [$DEL, "        file_put_contents(\$pdfPath . '.meta', json_encode([",
               "        if (QuoteOrganization::setting(\$this->config, \$this->dataDir)['on']) return ['ok' => false, 'skipped' => 'uganda', 'sent' => false, 'pdf_path' => '', 'filename' => '', 'error' => 'skipped: no Delivery Acknowledgment PDF on Uganda (docs/71 §K.2)'];\n        file_put_contents(\$pdfPath . '.meta', json_encode(["],
     ], 'sections' => 'G', 'pattern' => '/G2 … no file written/'],
    ['label' => 'MG7 the Delivery PDF\'s refusal read as sent', 'file' => $DEL,
     'old' => "            return ['ok' => false, 'skipped' => 'uganda',", 'new' => "            return ['ok' => true, 'skipped' => 'uganda',",
     'sections' => 'G', 'pattern' => '/G2 Uganda/'],
    ['label' => 'MG8 a saved plan pushed to uCRM on Uganda', 'file' => $PS,
     'old' => "        if (!\$ugNoPush && \$crmSync->isConfigured()) {", 'new' => "        if (\$crmSync->isConfigured()) {",
     'sections' => 'G', 'pattern' => '/G3 Uganda, plan/'],
    ['label' => 'MG9 a saved kit pushed to uCRM on Uganda', 'file' => $PS,
     'old' => "        if (!\$ugNoPush && \$crmHw->isConfigured()) {", 'new' => "        if (\$crmHw->isConfigured()) {",
     'sections' => 'G', 'pattern' => '/G3 Uganda, hardware/'],
    ['label' => 'MG10 the plan saved with a green "done"', 'file' => $PS,
     'old' => "\$ucrmMsg, \$ugNoPush ? 'warning' : 'success');", 'new' => "\$ucrmMsg, 'success');",
     'sections' => 'G', 'pattern' => '/G3 Uganda, plan/'],
    ['label' => 'MG11 the bulk push let through on Uganda', 'file' => $API,
     'old' => "Answered after the admin-only gate above. Elsewhere unchanged.\n        require_once dirname(__DIR__, 2) . '/lib/QuoteOrganization.php';\n        if (QuoteOrganization::setting(",
     'new' => "Answered after the admin-only gate above. Elsewhere unchanged.\n        require_once dirname(__DIR__, 2) . '/lib/QuoteOrganization.php';\n        if (false && QuoteOrganization::setting(",
     'sections' => 'G', 'pattern' => '/G4 Uganda, push_products_to_ucrm/'],
    ['label' => 'MG12 the plan sync refused before the admin check', 'file' => $API,
     'edits' => [
        [$API, "    if (\$act === 'sync_plan_to_ucrm' && \$met === 'POST') {\n        if (!\$isAdmin) \$er2('Admin only.', 403);\n",
               "    if (\$act === 'sync_plan_to_ucrm' && \$met === 'POST') {\n"],
        [$API, "        \$body   = json_decode(file_get_contents('php://input'), true) ?? [];\n        \$planId",
               "        if (!\$isAdmin) \$er2('Admin only.', 403);\n        \$body   = json_decode(file_get_contents('php://input'), true) ?? [];\n        \$planId"],
     ], 'sections' => 'G', 'pattern' => '/sync_plan_to_ucrm \(not an administrator\): still 403/'],
    ['label' => 'MG13 the prefix hint on every tenant', 'file' => 'tabs/admin/settings.php',
     'old' => "<?php if (\$qnUg): ?>\n            <div class=\"st-hint\">Printed", 'new' => "<?php if (true): ?>\n            <div class=\"st-hint\">Printed",
     'sections' => 'G', 'pattern' => '/G5 (the control|the Registration)/'],
    ['label' => 'MG14 any stored value shown as an install answer', 'file' => 'tabs/sales/applications.php',
     'old' => "in_array(\$a['install_by'] ?? null, ['dishnet', 'customer'], true)", 'new' => "!empty(\$a['install_by'])",
     'sections' => 'G', 'pattern' => '/G6 Uganda/'],
    ['label' => 'MG15 the mismatch shown on every tenant', 'file' => 'tabs/sales/applications.php',
     'old' => "<?php if (\$ugOrders102 && is_array(\$a['quote_mismatch'] ?? null)):", 'new' => "<?php if (is_array(\$a['quote_mismatch'] ?? null)):",
     'sections' => 'G', 'pattern' => '/G6 (the control|the Orders)/'],
    ['label' => 'MG16 the install line in South Sudan\'s messages', 'file' => $NS,
     'old' => "            if (empty(\\QuoteOrganization::setting((array)\$this->cfgForContacts, \$dir)['on'])) return '';\n", 'new' => '',
     'sections' => 'G', 'pattern' => '/G7 the staff messages/'],
    ['label' => 'MG17 "hand over the kit" before the customer has paid', 'file' => $NS,
     'old' => "        if (\$hasPaid && \$installBy === 'customer') \$nextStep", 'new' => "        if (\$installBy === 'customer') \$nextStep",
     'sections' => 'G', 'pattern' => '/G7 "Next"/'],
    ['label' => 'MG18 the refused-push line on every tenant', 'file' => 'tabs/sales/subscription_plans.php',
     'old' => "(string)\$dataDir : null)['on']): ?>\n        if (d.status === 'error')", 'new' => "(string)\$dataDir : null)['on'] || true): ?>\n        if (d.status === 'error')",
     'sections' => 'G', 'pattern' => '/G8 (the control: subscription_plans|subscription_plans\.php —)/'],
    ['label' => 'MG19 product_mapping reports a refused push as done', 'file' => 'tabs/sales/product_mapping.php',
     'old' => "    if (res.status === 'error') { showNotice('', 'warn'); document.getElementById('pm-notice').textContent = res.message || 'Not done.'; return; }\n", 'new' => '',
     'sections' => 'G', 'pattern' => '/G8 Uganda, product_mapping/'],
    // after the review (docs/71 §P.4, X4): what the card says when the quotation could not be read back, or differs at the same total
    ['label' => 'MG20 a read-back that failed shown as a mismatch', 'file' => 'tabs/sales/applications.php',
     'old' => "            ? 'The quotation could not be read back from uCRM — check it there.'",
     'new' => "            ? 'The quotation uCRM made does not match what was sent — check it in uCRM.'",
     'sections' => 'G', 'pattern' => '/could not be read back/'],
    ['label' => 'MG21 other lines at the same total shown as "totals X, not the X sent"', 'file' => 'tabs/sales/applications.php',
     'old' => "            : (is_numeric(\$qmSent) && abs((float)\$qmMade - (float)\$qmSent) > 0.0001",
     'new' => "            : (is_numeric(\$qmSent)",
     'sections' => 'G', 'pattern' => '/other lines at the same total/'],

    // ── 5.18.102 D14 (docs/71 §R.9.3, §R.9.1 G) ─────────────────────────────────────────────────────────────────────
    ['label' => 'ML15 (G3) the link save writes the plugin row (its ucrm_product_id)', 'file' => $PS,
     'old' => "        \$_lkKind  = (string)(\$_lkRes['kind'] ?? '');\n",
     'new' => "        \$_lkKind  = (string)(\$_lkRes['kind'] ?? '');\n"
            . "        if (\$_lkKind === 'linked' && \$_lkRow !== null) {\n"
            . "            \$_lkFile = \$_lkItem === 'plan' ? 'subscription_plans.json' : 'kyc_devices.json';\n"
            . "            \$_lkAll = (array)\$store->load(\$_lkFile);\n"
            . "            foreach (\$_lkAll as \$_i => \$_r) if ((int)(\$_r['id'] ?? 0) === \$_lkRowId) \$_lkAll[\$_i]['ucrm_product_id'] = \$_lkPid;\n"
            . "            \$store->save(\$_lkFile, \$_lkAll);\n"
            . "        }\n",
     'sections' => 'G', 'pattern' => '/G3 … and the plugin\'s rows are as they were/'],
    ['label' => 'ML18 (G8) the Plans screen\'s link column and picker drawn with the gate off', 'file' => 'tabs/sales/subscription_plans.php',
     'old' => "        if (!empty(\$_lk102Set['on'])) {\n            require_once dirname(__DIR__, 2) . '/lib/UcrmQuoteLines.php';",
     'new' => "        if (true) {\n            require_once dirname(__DIR__, 2) . '/lib/UcrmQuoteLines.php';",
     'sections' => 'G', 'pattern' => '/G8 subscription_plans\.php, with a link store present/'],
    ['label' => 'ML18 (G8) the Hardware screen\'s link column, picker and installation card drawn with the gate off', 'file' => 'tabs/sales/hardware.php',
     'old' => "        if (!empty(\$_lk102Set['on'])) {\n            require_once dirname(__DIR__, 2) . '/lib/UcrmQuoteLines.php';",
     'new' => "        if (true) {\n            require_once dirname(__DIR__, 2) . '/lib/UcrmQuoteLines.php';",
     'sections' => 'G', 'pattern' => '/G8 hardware\.php, with a link store present/'],
    ['label' => 'ML18 (G8) ucrm_link_save handled with the gate off', 'file' => $PS,
     'old' => "    if (QuoteOrganization::setting(is_array(\$config ?? null) ? \$config : [], isset(\$dataDir) ? (string)\$dataDir : null)['on']) {\n        \$_lkItem = is_string(",
     'new' => "    if (true) {\n        \$_lkItem = is_string(",
     'sections' => 'G', 'pattern' => '/G8 POST ucrm_link_save/'],
    ['label' => 'ML18 (G8) ucrm_link_clear handled with the gate off', 'file' => $PS,
     'old' => "    if (QuoteOrganization::setting(is_array(\$config ?? null) ? \$config : [], isset(\$dataDir) ? (string)\$dataDir : null)['on']) {\n        \$_ulItem = is_string(",
     'new' => "    if (true) {\n        \$_ulItem = is_string(",
     'sections' => 'G', 'pattern' => '/G8 POST ucrm_link_clear/'],
    ['label' => 'ML79 (G8) Make the quotation\'s handler run with the gate off (where it would record quote_made_by_id)', 'file' => $PS,
     'old' => "    if (QuoteOrganization::setting(is_array(\$config ?? null) ? \$config : [], isset(\$dataDir) ? (string)\$dataDir : null)['on']) {\n        \$_mqBack = '?page=dashboard&tab=applications';",
     'new' => "    if (true) {\n        \$_mqBack = '?page=dashboard&tab=applications';",
     'sections' => 'G', 'pattern' => '/G8 POST kyc_make_quote/'],
    ['label' => 'ML49 Product Mapping\'s green banner ("Quotes will reference real products") drawn with the gate on', 'file' => 'tabs/sales/product_mapping.php',
     'old' => "<?php if (\$_pm102): /* 5.18.102 (Uganda, D14, docs/71 §R.2.6): one neutral line in the server's words, whatever the counts */ ?>",
     'new' => "<?php if (false): /* 5.18.102 (Uganda, D14, docs/71 §R.2.6): one neutral line in the server's words, whatever the counts */ ?>",
     'sections' => 'G', 'pattern' => '/G9 Uganda: neither/'],
    ['label' => 'ML50 Product Mapping\'s header ("quotes reference real billing items") left ungated with the gate on', 'file' => 'tabs/sales/product_mapping.php',
     'old' => "<?php if (\$_pm102): /* 5.18.102 (Uganda, D14, docs/71 §R.2.6): quotations use only the links made on the Plans and Hardware screens */ ?>",
     'new' => "<?php if (false): /* 5.18.102 (Uganda, D14, docs/71 §R.2.6): quotations use only the links made on the Plans and Hardware screens */ ?>",
     'sections' => 'G', 'pattern' => '/G9 Uganda: neither/'],
    ['label' => 'ML53 (G8) Make the quotation drawn with the gate off', 'edits' => [
        ['tabs/sales/applications.php', "if (\$ugOrders102) {\n    require_once dirname(__DIR__, 2) . '/lib/KycService.php';",
                                        "require_once dirname(__DIR__, 2) . '/lib/KycService.php';\nif (\$ugOrders102) {"],
        ['tabs/sales/applications.php', "<?php if (\$ugOrders102 && !empty(\$isAdmin) && KycService::canMakeQuotation(\$a, \$ugDirect103) /* 5.18.103 (§G.8) */):",
                                        "<?php if (!empty(\$isAdmin) && KycService::canMakeQuotation(\$a, \$ugDirect103) /* 5.18.103 (§G.8) */):"],
     ], 'sections' => 'G', 'pattern' => '/G8 (South Sudan|Uganda with the setting (0|off)): the same web P1|G8 the Orders page/'],
    ['label' => 'MG22 Auto-Map let through on Uganda', 'file' => $API,
     'old' => "quotes never read under D14 but 5.18.101 would send after a rollback. Answered after the admin-only gate above.\n        require_once dirname(__DIR__, 2) . '/lib/QuoteOrganization.php';\n        if (QuoteOrganization::setting(",
     'new' => "quotes never read under D14 but 5.18.101 would send after a rollback. Answered after the admin-only gate above.\n        require_once dirname(__DIR__, 2) . '/lib/QuoteOrganization.php';\n        if (false && QuoteOrganization::setting(",
     'sections' => 'G', 'pattern' => '/G4 Uganda, auto_map_products/'],
    ['label' => 'MG23 set_product_mapping let through on Uganda', 'file' => $API,
     'old' => "screens, and a ucrm_product_id written here would become productId after a rollback (§R.1.2).\n        require_once dirname(__DIR__, 2) . '/lib/QuoteOrganization.php';\n        if (QuoteOrganization::setting(",
     'new' => "screens, and a ucrm_product_id written here would become productId after a rollback (§R.1.2).\n        require_once dirname(__DIR__, 2) . '/lib/QuoteOrganization.php';\n        if (false && QuoteOrganization::setting(",
     'sections' => 'G', 'pattern' => '/G4 Uganda, set_product_mapping/'],
    ['label' => 'MG24 save_hardware honours a typed UCRM Product ID on Uganda', 'file' => $PS,
     'old' => "    if (QuoteOrganization::setting(is_array(\$config ?? null) ? \$config : [], \$dataDir ?? null)['on']) \$manualUcrmId = 0;\n", 'new' => '',
     'sections' => 'G', 'pattern' => '/G3 Uganda, (hardware mapped|new hardware), a UCRM Product ID 77 typed/'],
    ['label' => 'MG25 a kit\'s own save ends its uCRM link', 'file' => $PS,
     'old' => "    \$store->save('kyc_devices.json', \$hw);\n    flash((\$hwId > 0 ? 'Hardware updated.'",
     'new' => "    require_once dirname(__DIR__, 2) . '/lib/UcrmQuoteLines.php';\n    if (\$savedHwId > 0) UcrmQuoteLinks::end(\$store, 'kit', (int)\$savedHwId, UcrmQuoteLinks::WHY_UNLINKED, []);\n"
            . "    \$store->save('kyc_devices.json', \$hw);\n    flash((\$hwId > 0 ? 'Hardware updated.'",
     'sections' => 'G', 'pattern' => '/G3 … and none of them asked it for ucrm_quote_links/'],
    // 5.18.102 D14 review round 3 (CK-flows-2): the gate-off card stays f2055baf's
    // (round 5, CK5-T6: with the sentence calling nothing too — else the page dies on KycService, unloaded with the gate
    //  off, and G8 sees a fatal error, not the box)
    ['label' => 'MG26 the "did not finish" box drawn with the gate off', 'edits' => [
        ['tabs/sales/applications.php', "<?php if (\$ugOrders102 && (!empty(\$a['quote_unconfirmed_at'])", "<?php if ((!empty(\$a['quote_unconfirmed_at'])"],
        ['tabs/sales/applications.php', "!empty(\$isAdmin) && KycService::canMakeQuotation(\$a, \$ugDirect103) /* 5.18.103 (§G.8) */ ? 'Do not make", "!empty(\$isAdmin) ? 'Do not make"]],
     'sections' => 'G', 'pattern' => '/G8 the Orders page with an application whose last Make the quotation went unconfirmed/'],
    // 5.18.102 D14 review round 6 (CK6-F1): the in-flight box with the gate off (its constant inlined: KycService is not loaded)
    ['label' => 'MG27 the "press in flight" box drawn with the gate off', 'edits' => [
        ['tabs/sales/applications.php', "<?php if (\$ugOrders102 && empty(\$a['quote_created']) && is_numeric(\$a['quote_make_claim_ts']", "<?php if (empty(\$a['quote_created']) && is_numeric(\$a['quote_make_claim_ts']"],
        ['tabs/sales/applications.php', "< KycService::QUOTE_CLAIM_SECONDS): /* 5.18.102 D14 review round 6", "< 900): /* 5.18.102 D14 review round 6"]],
     'sections' => 'G', 'pattern' => '/G8 the Orders page with an application whose Make the quotation is in flight/'],
    // 5.18.102 D14 review round 9 (CK9-T8): the stale-claim half of the "did not finish" box with the gate off (guarded so
    // that KycService, not loaded there, is never named)
    ['label' => 'MG28 the "did not finish" box drawn with the gate off for an expired claim', 'edits' => [
        ['tabs/sales/applications.php', "<?php if (\$ugOrders102 && (!empty(\$a['quote_unconfirmed_at']) || KycService::claimLeftUnsettled(\$a)) && empty(\$a['quote_created'])):",
            "<?php if (((\$ugOrders102 && !empty(\$a['quote_unconfirmed_at'])) || (is_numeric(\$a['quote_make_claim_ts'] ?? null) && time() - (int)\$a['quote_make_claim_ts'] >= 900 && (\$ugOrders102 ? KycService::claimLeftUnsettled(\$a) : true))) && empty(\$a['quote_created'])):"],
        ['tabs/sales/applications.php', "!empty(\$isAdmin) && KycService::canMakeQuotation(\$a, \$ugDirect103) /* 5.18.103 (§G.8) */ ? 'Do not make", "!empty(\$isAdmin) && \$ugOrders102 && KycService::canMakeQuotation(\$a, \$ugDirect103) /* 5.18.103 (§G.8) */ ? 'Do not make"]],
     'sections' => 'G', 'pattern' => '/G8 the Orders page with an application whose claim expired/'],
]);
