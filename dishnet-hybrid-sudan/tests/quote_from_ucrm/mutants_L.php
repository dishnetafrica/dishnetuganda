<?php
declare(strict_types=1);
/**
 * The weakened copies section L catches (5.18.102 D14, docs/71 §R.9.3: every copy whose "Caught by" names L). Each is
 * one fault planted in a copy of the plugin, judged by test_quote_from_ucrm.php --sections=[L], and must fail L on a FAIL
 * line matching its pattern. A copy whose anchor is not found exactly once is a failure of the run, never a skip.
 * TEST ONLY.
 *
 * [label, file, old, new, sections, pattern]; 'edits' => [[file, old, new], …] where one fault has to be planted in more
 * than one place (ML46: the read moved into the lock; ML66: the organization kept by the constructor) — each anchor is
 * still asserted to be found exactly once.
 *
 * Where §R.9.3 names L beside another section (ML3, ML14, ML15, ML25, ML27–ML31, ML62), this file holds L's half; the
 * other half is that section's own file (mutants.php, mutants_G.php, mutants_I.php). ML14's, ML27's, ML30's and ML31's L
 * halves weaken the save only ($atUse), so the use-time half of each stays caught by A, not by this file.
 */
$L  = 'lib/UcrmQuoteLinks.php';
$U  = 'lib/UcrmQuoteLines.php';
$K  = 'lib/KycService.php';
$PS = 'includes/post/post_sync.php';
$SP = 'tabs/sales/subscription_plans.php';
$HW = 'tabs/sales/hardware.php';   // 5.18.102 D14 review (T2): the Hardware screen's own copy of the picker

// the save's and the unlink's own-page check, and their account steps (each anchor unique by its own variables)
$SAVE_CHECK  = "        if (!(csrfCheck() && !CustomerSession::crossSite() && BankGate::fromOwnPage('POST'))) {\n"
             . "            flash('The security check failed, so nothing was changed. Open the screen again and retry.', 'danger');\n"
             . "            redirect(\$_lkBack);";
$CLEAR_CHECK = "        if (!(csrfCheck() && !CustomerSession::crossSite() && BankGate::fromOwnPage('POST'))) {\n"
             . "            flash('The security check failed, so nothing was changed. Open the screen again and retry.', 'danger');\n"
             . "            redirect(\$_ulBack);";
$checkTail = function (string $back): string {
    return "            flash('The security check failed, so nothing was changed. Open the screen again and retry.', 'danger');\n            redirect(\${$back});";
};

$QFU_MUTANTS = array_merge($QFU_MUTANTS ?? [], [
    // ── the store and the judge ─────────────────────────────────────────────────────────────────────────────────────
    ['label' => 'ML3 (L) row_stamp ignored: a reused id judged linked by the old record', 'file' => $L,
     'old' => "        if (\$had !== \$stamp) return ['state' => 'replaced', 'record' => \$rec, 'records' => \$active];\n", 'new' => '',
     'sections' => 'L', 'pattern' => '/L3 \(a\)|L12 row replaced/'],
    ['label' => 'ML16 (L) a relink leaves the old record active (ended_at written, ended_at_ts not)', 'file' => $L,
     'old' => "                \$records[\$i]['ended_at_ts'] = \$ts;\n                \$records[\$i]['ended_by_id'] = \$actorId;\n                \$records[\$i]['ended_why']   = self::WHY_RELINKED;",
     'new' => "                \$records[\$i]['ended_by_id'] = \$actorId;\n                \$records[\$i]['ended_why']   = self::WHY_RELINKED;",
     'sections' => 'L', 'pattern' => '/L3 \((a|b|c)\)|L2 a relink/'],
    ['label' => 'ML34 (L) the no-op compares only product_id and service_plan_id', 'file' => $L,
     'old' => "                if (is_numeric(\$m['product_id'] ?? null) && (int)\$m['product_id'] === \$productId && \$samePlan\n"
            . "                    && (is_scalar(\$m['row_stamp'] ?? null) ? (string)\$m['row_stamp'] : '') === (\$item === 'install' ? '' : self::rowStamp(\$row))\n"
            . "                    && is_numeric(\$m['organization_id'] ?? null) && (int)\$m['organization_id'] === \$orgId) {",
     'new' => "                if (is_numeric(\$m['product_id'] ?? null) && (int)\$m['product_id'] === \$productId && \$samePlan) {",
     'sections' => 'L', 'pattern' => '/L3 \((a|b)\)/'],
    ['label' => 'ML35 (L) a relink ends only the first of two active records', 'file' => $L,
     'old' => "            foreach (\$mine as \$m) \$endIds[(int)(\$m['id'] ?? 0)] = true;",
     'new' => "            foreach (array_slice(\$mine, 0, 1) as \$m) \$endIds[(int)(\$m['id'] ?? 0)] = true;",
     'sections' => 'L', 'pattern' => '/L3 \(c\)/'],
    ['label' => 'ML35 (L) an unlink or a delete hook ends only one active record', 'file' => $L,
     'old' => "                \$ended[] = is_numeric(\$r['id'] ?? null) ? (int)\$r['id'] : 0;\n",
     'new' => "                \$ended[] = is_numeric(\$r['id'] ?? null) ? (int)\$r['id'] : 0;\n                break;\n",
     'sections' => 'L', 'pattern' => '/L5 (unlink|a delete) over two|L5 … and deleting the kit row/'],
    ['label' => 'ML35 (L) a save no-ops while two records are active (the first naming the product)', 'file' => $L,
     'old' => "            if (count(\$mine) === 1) {", 'new' => "            if (count(\$mine) >= 1) {",
     'sections' => 'L', 'pattern' => '/L3 \(c\)/'],
    ['label' => 'ML36 (L) the save accepts an offered kit linked to the installation\'s product (rule 8\'s install side dropped)', 'file' => $L,
     'old' => "        if (\$i['state'] === 'ok' && (int)(\$i['record']['product_id'] ?? 0) === \$productId) \$out['install'] = true;\n", 'new' => '',
     'sections' => 'L', 'pattern' => '/L8 rule 8: an offered kit linked/'],
    ['label' => 'ML36 (L) the save accepts the installation linked to an offered kit\'s product (rule 8\'s kit side dropped)', 'file' => $L,
     'old' => "            if (\$k['state'] === 'ok' && (int)(\$k['record']['product_id'] ?? 0) === \$productId) \$out['kits'][] = \$row;\n", 'new' => '',
     'sections' => 'L', 'pattern' => '/L8 rule 8: the installation linked|L4 L-rule8-crosswise: refused/'],
    ['label' => 'ML47 (L) fromRecords ignores row_stamp: a kit record takes its row\'s current stamp (a replaced row judged linked)', 'file' => $L,
     'old' => "        return new self(\$records, \$kitRows);",
     'new' => "        \$byId = [];\n        foreach (\$kitRows as \$k) if (is_array(\$k) && isset(\$k['id'])) \$byId[(int)\$k['id']] = \$k;\n"
            . "        foreach (\$records as \$i => \$r) {\n"
            . "            if (is_array(\$r) && (\$r['item'] ?? '') === 'kit' && isset(\$byId[(int)(\$r['row_id'] ?? 0)])) \$records[\$i]['row_stamp'] = self::rowStamp(\$byId[(int)\$r['row_id']]);\n"
            . "        }\n        return new self(\$records, \$kitRows);",
     'sections' => 'L', 'pattern' => '/L12 row replaced: fromRecords/'],
    ['label' => 'ML52 (L) the active rule reads ended_at instead of ended_at_ts', 'file' => $L,
     'old' => "            if ((\$r['ended_at_ts'] ?? null) !== null) continue;", 'new' => "            if ((\$r['ended_at'] ?? null) !== null) continue;",
     'sections' => 'L', 'pattern' => '/L2 /'],
    ['label' => 'ML62 (L) fromStore opens the store with the gate off', 'file' => $L,
     'old' => "        if (empty(\$setting['on'])) return new self([], []);",
     'new' => "        if (empty(\$setting['on'])) { \$store->load(self::FILE); return new self([], []); }",
     'sections' => 'L', 'pattern' => '/L12 the contract: fromStore/'],
    ['label' => 'ML63 (L) records() leaves out the ended records', 'file' => $L,
     'old' => "        return \$this->records;",
     'new' => "        return array_values(array_filter(\$this->records, function (\$r) { return (\$r['ended_at_ts'] ?? null) === null; }));",
     'sections' => 'L', 'pattern' => '/L12 the contract: records/'],
    ['label' => 'ML63 (L) records() returns the records in part (some keys only)', 'file' => $L,
     'old' => "        return \$this->records;",
     'new' => "        return array_map(function (\$r) { return array_intersect_key(\$r, array_flip(['id', 'item', 'row_id', 'product_id', 'service_plan_id', 'ended_at_ts'])); }, \$this->records);",
     'sections' => 'L', 'pattern' => '/L12 the contract: records/'],
    ['label' => 'ML66 (L) the judge keeps the quoting organization from fromStore\'s $setting instead of its caller\'s', 'sections' => 'L',
     'edits' => [
        [$L, "        if (empty(\$setting['on'])) return new self([], []);\n        \$records = \$store->load(self::FILE);",
             "        if (empty(\$setting['on'])) return new self([], []);\n        \$GLOBALS['__qfu_ml66_org'] = \$setting['id'] ?? null;\n        \$records = \$store->load(self::FILE);"],
        [$L, "        \$org = \$rec['organization_id'] ?? null;\n",
             "        \$org = \$rec['organization_id'] ?? null;\n        if (array_key_exists('__qfu_ml66_org', \$GLOBALS)) \$orgId = \$GLOBALS['__qfu_ml66_org'];\n"],
     ], 'pattern' => '/L12 the organization comes from the caller/'],
    ['label' => 'ML67 (L) rule 8\'s "offered" narrower than offeredKit (is_active === true)', 'file' => $L,
     'old' => "            if (!self::offeredKit(\$row)) continue;",
     'new' => "            if ((\$row['is_active'] ?? null) !== true || strtolower((string)(\$row['type'] ?? 'general')) === 'fiber') continue;",
     'sections' => 'L', 'pattern' => '/L13 rule 8 blocks .*is_active 1/'],
    ['label' => 'ML67 (L) rule 8\'s "offered" wider than offeredKit (Fiber compared case-sensitively)', 'file' => $L,
     'old' => "            if (!self::offeredKit(\$row)) continue;",
     'new' => "            if (empty(\$row['is_active']) || (string)(\$row['type'] ?? 'general') === 'fiber') continue;",
     'sections' => 'L', 'pattern' => '/L13 rule 8 does not block for a kit row stored with type (FIBER|Fiber)/'],
    ['label' => 'ML25 (L) a Fiber plan row linkable (isFiber false for plans)', 'file' => $L,
     'old' => "        if (\$item === 'plan') return strtolower((string)(\$row['type'] ?? 'starlink')) === 'fiber';",
     'new' => "        if (\$item === 'plan') return false;",
     'sections' => 'L', 'pattern' => '/L8 rule 2, a Fiber plan row/'],
    ['label' => 'ML25 (L) a Fiber kit row linkable (isFiber false for kits)', 'file' => $L,
     'old' => "        if (\$item === 'kit')  return strtolower((string)(\$row['type'] ?? 'general')) === 'fiber';",
     'new' => "        if (\$item === 'kit')  return false;",
     'sections' => 'L', 'pattern' => '/L8 rule 2, a Fiber kit row/'],

    // ── the rules at save (UcrmQuoteLines, the save's half: $atUse false) ──────────────────────────────────────────
    ['label' => 'ML10 (L) the save accepts an inactive product (counts() ignores isActive)', 'file' => $U,
     'old' => "        if (array_key_exists('isActive', \$row) && \$row['isActive'] === false) return 'inactive';\n", 'new' => '',
     'sections' => 'L', 'pattern' => '/L8 rule 4, an inactive product/'],
    ['label' => 'ML10 (L) the save accepts an archived product (counts() ignores archived)', 'file' => $U,
     'old' => "        if (array_key_exists('archived', \$row) && \$row['archived'] === true) return 'archived';\n", 'new' => '',
     'sections' => 'L', 'pattern' => '/L8 rule 4, an archived product/'],
    ['label' => 'ML10 (L) the save accepts another organization\'s product (the organization rule dropped)', 'file' => $U,
     'old' => "            if (!is_int(\$org) || (int)\$row['organizationId'] !== \$org) return 'organization';\n", 'new' => '',
     'sections' => 'L', 'pattern' => '/L8 rule 4, another organization/'],
    ['label' => 'ML11 (L) the save accepts a taxable product', 'file' => $U,
     'old' => "        if (!empty(\$p['taxable']) || (array_key_exists('taxId', \$p) && \$p['taxId'] !== null)) {", 'new' => '        if (false) {',
     'sections' => 'L', 'pattern' => '/L8 rule 5, (taxable|a taxId)/'],
    ['label' => 'ML11 (L) the save accepts a price of 0', 'file' => $U,
     'old' => "        if (\$f <= 0) return \"{\$shown}'s price in uCRM is 0\";\n", 'new' => '',
     'sections' => 'L', 'pattern' => '/L8 rule 5, a price of 0/'],
    ['label' => 'ML11 (L) the save accepts more than 2 decimals', 'file' => $U,
     'old' => "        if (abs(\$f * 100 - round(\$f * 100)) > 0.000001) return \"{\$shown}'s price in uCRM has more than 2 decimals\";\n", 'new' => '',
     'sections' => 'L', 'pattern' => '/L8 rule 5, more than 2 decimals/'],
    ['label' => 'ML11 (L) the save accepts a differing invoice label', 'file' => $U,
     'old' => "        if (\$label !== '' && PublicPriceFeed::planKey(\$label) !== PublicPriceFeed::planKey((string)(\$p['name'] ?? ''))) {", 'new' => '        if (false) {',
     'sections' => 'L', 'pattern' => '/L8 rule 5, an invoice label/'],
    ['label' => 'ML12 (L) the save accepts a plan whose product price differs from the service plan\'s 1-month price', 'file' => $U,
     'old' => "        if (abs(\$price - \$month) > 0.0001) {", 'new' => "        if (\$atUse && abs(\$price - \$month) > 0.0001) {",
     'sections' => 'L', 'pattern' => '/L8 rule 6, the product\'s price not/'],
    ['label' => 'ML13 (L) the save accepts a plan product spelled unlike its service plan', 'file' => $U,
     'old' => "        if (\$key === '' || PublicPriceFeed::planKey((string)(\$product['name'] ?? '')) !== \$key) {",
     'new' => "        if (\$atUse && (\$key === '' || PublicPriceFeed::planKey((string)(\$product['name'] ?? '')) !== \$key)) {",
     'sections' => 'L', 'pattern' => '/L8 rule 6, product and service plan spelled apart/'],
    ['label' => 'ML14 (L) (the save) a kit or the installation linked to a product spelled like a service plan', 'file' => $U,
     'old' => "        if (\$this->likePlan(\$product)) {", 'new' => "        if (\$atUse && \$this->likePlan(\$product)) {",
     'sections' => 'L', 'pattern' => '/L8 rule 7, (a kit|the installation) spelled/'],
    ['label' => 'ML27 (L) the save accepts a service plan whose key another counted service plan shares', 'file' => $U,
     'old' => "        if (\$key !== '' && (int)(\$cat['plan_keys'][\$key] ?? 0) !== 1) {", 'new' => "        if (\$atUse && \$key !== '' && (int)(\$cat['plan_keys'][\$key] ?? 0) !== 1) {",
     'sections' => 'L', 'pattern' => '/L8 rule 6\(a\), another counted/'],
    ['label' => 'ML28 (L) plan_keys over the counted plans only: the save accepts a plan an archived or another organization\'s plan shares', 'file' => $U,
     'old' => "        foreach (\$plans as \$p) {\n            if (!is_array(\$p)) continue;",
     'new' => "        foreach (\$plans as \$p) {\n            if (!is_array(\$p) || !\$this->counts(\$p)) continue;",
     'sections' => 'L', 'pattern' => '/L8 rule 6\(a\), (an archived|another organization)/'],
    ['label' => 'ML29 (L) rule 7 over the counted plans only: the save accepts a kit spelled like an archived or inactive plan', 'file' => $U,
     'old' => "        return \$k !== '' && isset(\$this->catalogue()['plan_keys'][\$k]);",
     'new' => "        foreach (\$this->catalogue()['plans'] as \$q) { if (is_array(\$q) && PublicPriceFeed::planKey((string)(\$q['name'] ?? '')) === \$k) return \$k !== ''; }\n        return false;",
     'sections' => 'L', 'pattern' => '/L8 rule 7, (a kit spelled like an (archived|inactive)|the installation)/'],
    ['label' => 'ML30 (L) the save accepts a plan whose planPrice differs from its 1-month price', 'file' => $U,
     'old' => "        if (\$summaryPrice === null || abs(round(\$summaryPrice, 2) - \$month) > 0.0001) {",
     'new' => "        if (\$atUse && (\$summaryPrice === null || abs(round(\$summaryPrice, 2) - \$month) > 0.0001)) {",
     'sections' => 'L', 'pattern' => '/L8 rule 6\(b\)/'],
    ['label' => 'ML31 (L) the save accepts a plan when uCRM lists 200 service plans', 'file' => $U,
     'old' => "        if ((int)\$cat['plan_rows'] >= self::SUMMARY_PLAN_LIMIT) {", 'new' => "        if (\$atUse && (int)\$cat['plan_rows'] >= self::SUMMARY_PLAN_LIMIT) {",
     'sections' => 'L', 'pattern' => '/L8 rule 6\(c\)/'],

    // ── the two actions (post_sync.php) ─────────────────────────────────────────────────────────────────────────────
    // ML8: the admin requirement removed whole — requireAdmin and the fresh record's admin flag — each anchor asserted once
    ['label' => 'ML8 (L) a link saved without requireAdmin (any signed-in staff member, the admin flag not read)', 'sections' => 'L',
     'edits' => [[$PS, "        \$_lkMe = \$auth->requireAdmin();", "        \$_lkMe = \$auth->requireLogin();"],
                 [$PS, "            || empty(\$_lkFresh['is_active']) || empty(\$_lkFresh['is_admin'])) {", "            || empty(\$_lkFresh['is_active'])) {"]],
     'pattern' => '/L6 ucrm_link_save: a non-admin/'],
    ['label' => 'ML8 (L) an unlink without requireAdmin (any signed-in staff member, the admin flag not read)', 'sections' => 'L',
     'edits' => [[$PS, "        \$_ulMe = \$auth->requireAdmin();", "        \$_ulMe = \$auth->requireLogin();"],
                 [$PS, "            || empty(\$_ulFresh['is_active']) || empty(\$_ulFresh['is_admin'])) {", "            || empty(\$_ulFresh['is_active'])) {"]],
     'pattern' => '/L6 ucrm_link_clear: a non-admin/'],
    ['label' => 'ML48 (L) the link save trusts requireAdmin\'s session copy (no fresh re-read)', 'file' => $PS,
     'old' => "            \$_lkFresh = \$_lkMeId > 0 ? \$store->findOne('retailers.json', 'id', \$_lkMeId) : null;", 'new' => "            \$_lkFresh = \$_lkMe;",
     'sections' => 'L', 'pattern' => '/L6 ucrm_link_save, the (stored row|row deleted|account read)/'],
    ['label' => 'ML48 (L) the unlink trusts requireAdmin\'s session copy (no fresh re-read)', 'file' => $PS,
     'old' => "            \$_ulFresh = \$_ulMeId > 0 ? \$store->findOne('retailers.json', 'id', \$_ulMeId) : null;", 'new' => "            \$_ulFresh = \$_ulMe;",
     'sections' => 'L', 'pattern' => '/L6 ucrm_link_clear, the (stored row|row deleted|account read)/'],
    ['label' => 'ML41 (L) the own-page check (fromOwnPage) removed from the link save', 'file' => $PS,
     'old' => $SAVE_CHECK, 'new' => "        if (!(csrfCheck() && !CustomerSession::crossSite())) {\n" . $checkTail('_lkBack'),
     'sections' => 'L', 'pattern' => '/L7 ucrm_link_save \((ii|iii|iv|v)\)/'],
    ['label' => 'ML41 (L) the own-page check (fromOwnPage) removed from the unlink', 'file' => $PS,
     'old' => $CLEAR_CHECK, 'new' => "        if (!(csrfCheck() && !CustomerSession::crossSite())) {\n" . $checkTail('_ulBack'),
     'sections' => 'L', 'pattern' => '/L7 ucrm_link_clear \((ii|iii|iv|v)\)/'],
    ['label' => 'ML42 (L) the link save relies on the global gate alone (its three checks removed)', 'file' => $PS,
     'old' => $SAVE_CHECK, 'new' => "        if (false) {\n" . $checkTail('_lkBack'),
     'sections' => 'L', 'pattern' => '/L7 ucrm_link_save \(i\)/'],
    ['label' => 'ML42 (L) the unlink relies on the global gate alone (its three checks removed)', 'file' => $PS,
     'old' => $CLEAR_CHECK, 'new' => "        if (false) {\n" . $checkTail('_ulBack'),
     'sections' => 'L', 'pattern' => '/L7 ucrm_link_clear \(i\)/'],
    ['label' => 'ML43 (L) the link save\'s csrfCheck() removed', 'file' => $PS,
     'old' => $SAVE_CHECK, 'new' => "        if (!(!CustomerSession::crossSite() && BankGate::fromOwnPage('POST'))) {\n" . $checkTail('_lkBack'),
     'sections' => 'L', 'pattern' => '/L7 ucrm_link_save \(i\)/'],
    ['label' => 'ML43 (L) the link save\'s csrfCheck() result discarded', 'file' => $PS,
     'old' => $SAVE_CHECK, 'new' => "        csrfCheck();\n        if (!(true && !CustomerSession::crossSite() && BankGate::fromOwnPage('POST'))) {\n" . $checkTail('_lkBack'),
     'sections' => 'L', 'pattern' => '/L7 ucrm_link_save \(i\)/'],
    ['label' => 'ML43 (L) the unlink\'s csrfCheck() removed', 'file' => $PS,
     'old' => $CLEAR_CHECK, 'new' => "        if (!(!CustomerSession::crossSite() && BankGate::fromOwnPage('POST'))) {\n" . $checkTail('_ulBack'),
     'sections' => 'L', 'pattern' => '/L7 ucrm_link_clear \(i\)/'],
    ['label' => 'ML9 (L) the save validated against the page cache (forPage instead of a fresh read)', 'file' => $PS,
     'old' => "            \$_lkLines = new UcrmQuoteLines(UcrmQuoteLines::quoteClient(\$_lkCrm, is_array(\$config ?? null) ? \$config : []),\n"
            . "                \$_lkSet, UcrmQuoteLinks::fromStore(\$store, \$_lkSet));",
     'new' => "            \$_lkLines = UcrmQuoteLines::forPage(UcrmQuoteLines::quoteClient(\$_lkCrm, is_array(\$config ?? null) ? \$config : []),\n"
            . "                \$_lkSet, (string)\$dataDir, UcrmQuoteLinks::fromStore(\$store, \$_lkSet));",
     'sections' => 'L', 'pattern' => '/L9 uCRM now/'],
    ['label' => 'ML15 (L) the link save writes the plugin row (its ucrm_product_id)', 'file' => $PS,
     'old' => "        \$_lkKind  = (string)(\$_lkRes['kind'] ?? '');\n",
     'new' => "        \$_lkKind  = (string)(\$_lkRes['kind'] ?? '');\n"
            . "        if (\$_lkKind === 'linked' && \$_lkRow !== null) {\n"
            . "            \$_lkFile = \$_lkItem === 'plan' ? 'subscription_plans.json' : 'kyc_devices.json';\n"
            . "            \$_lkAll = (array)\$store->load(\$_lkFile);\n"
            . "            foreach (\$_lkAll as \$_i => \$_r) if ((int)(\$_r['id'] ?? 0) === \$_lkRowId) \$_lkAll[\$_i]['ucrm_product_id'] = \$_lkPid;\n"
            . "            \$store->save(\$_lkFile, \$_lkAll);\n"
            . "        }\n",
     'sections' => 'L', 'pattern' => '/L10/'],
    ['label' => 'ML15 (L) the link save writes the plugin row (uCRM\'s name over the row\'s)', 'file' => $PS,
     'old' => "        \$_lkKind  = (string)(\$_lkRes['kind'] ?? '');\n",
     'new' => "        \$_lkKind  = (string)(\$_lkRes['kind'] ?? '');\n"
            . "        if (\$_lkKind === 'linked' && \$_lkRow !== null) {\n"
            . "            \$store->updateOne(\$_lkItem === 'plan' ? 'subscription_plans.json' : 'kyc_devices.json', 'id', \$_lkRowId, [\$_lkItem === 'plan' ? 'name' : 'title' => \$_lkProdName]);\n"
            . "        }\n",
     'sections' => 'L', 'pattern' => '/L10/'],
    ['label' => 'ML40 (L) the relink\'s count reads the new active record instead of the ended one', 'file' => $PS,
     'old' => "                \$_lkN = UcrmQuoteLinks::countQuoted(\$store, \$_lkSet, \$_lkEnded);",
     'new' => "                \$_lkN = UcrmQuoteLinks::countQuoted(\$store, \$_lkSet, [(int)((\$_lkRes['record'] ?? [])['id'] ?? 0)]);",
     'sections' => 'L', 'pattern' => '/L18 a relink/'],
    ['label' => 'ML44 (L) a flash carries the raw product name', 'file' => $PS,
     'old' => "        \$_lkWhat  = 'uCRM product #' . (int)\$_lkPid . ' (' . \$_lkPlain(\$_lkProdName, 'no name') . ')'",
     'new' => "        \$_lkWhat  = 'uCRM product #' . (int)\$_lkPid . ' (' . \$_lkProdName . ')'",
     'sections' => 'L', 'pattern' => '/L17/'],
    ['label' => 'ML46 (L) the uCRM read and the rules run inside the withLock callback', 'sections' => 'L',
     'edits' => [
        [$PS, "            \$_lkCand = \$_lkLines->candidate(\$_lkItem, \$_lkRow, \$_lkPid, \$_lkItem === 'plan' ? \$_lkSpid : null);\n            \$_lkCat  = \$_lkLines->catalogue();",
              "            \$_lkCand = ['ok' => true, 'line' => ['label' => '']];\n            \$_lkCat  = ['plans' => []];"],
        [$PS, "                'service_plan_name' => \$_lkSpName,\n            ], \$_lkFresh,",
              "                'service_plan_name' => \$_lkSpName,\n                '__inside'          => function () use (\$_lkLines, \$_lkItem, \$_lkRow, \$_lkPid, \$_lkSpid) { return \$_lkLines->candidate(\$_lkItem, \$_lkRow, \$_lkPid, \$_lkItem === 'plan' ? \$_lkSpid : null); },\n            ], \$_lkFresh,"],
        [$L, "            if (\$orgId === null) return \$refuse('changed', \$changed);\n",
             "            if (\$orgId === null) return \$refuse('changed', \$changed);\n            if (isset(\$link['__inside'])) { \$chk = (\$link['__inside'])(); if (empty(\$chk['ok'])) return \$refuse('changed', (string)(\$chk['reason'] ?? '')); }\n"],
     ], 'pattern' => '/L4 L-race … and/'],
    ['label' => 'ML19 (L) a gate-off path loads the link store (delete_plan\'s hook ungated: the table created on South Sudan)', 'file' => $PS,
     'old' => "    if (\$delId > 0 && QuoteOrganization::setting(is_array(\$config ?? null) ? \$config : [], isset(\$dataDir) ? (string)\$dataDir : null)['on']) {\n"
            . "        require_once dirname(__DIR__, 2) . '/lib/UcrmQuoteLines.php';   // loads lib/UcrmQuoteLinks.php (F-6)\n"
            . "        require_once dirname(__DIR__, 2) . '/lib/UcrmCatalogue.php';    // 5.18.103 (root docs/72 §G G-11): frozen while on\n"
            . "        if (!UcrmCatalogue::forConfig(\$store, is_array(\$config ?? null) ? \$config : [], isset(\$dataDir) ? (string)\$dataDir : null)->direct()) try {\n            \$_dlEnded = UcrmQuoteLinks::end(\$store, 'plan',",
     'new' => "    if (\$delId > 0) {\n"
            . "        require_once dirname(__DIR__, 2) . '/lib/UcrmQuoteLines.php';   // loads lib/UcrmQuoteLinks.php (F-6)\n"
            . "        require_once dirname(__DIR__, 2) . '/lib/UcrmCatalogue.php';    // 5.18.103 (root docs/72 §G G-11): frozen while on\n"
            . "        if (!UcrmCatalogue::forConfig(\$store, is_array(\$config ?? null) ? \$config : [], isset(\$dataDir) ? (string)\$dataDir : null)->direct()) try {\n            \$_dlEnded = UcrmQuoteLinks::end(\$store, 'plan',",
     'sections' => 'L', 'pattern' => '/L19 (South Sudan|Uganda with the setting 0): after every/'],   // narrowed (T6): the absence line, never the control's
    // 5.18.102 D14 review (T6): L19's posts must be shown to run — each copy below stops a post before its handler
    ['label' => 'ML19h (L) the global gate refuses every post (no L19 post reaches its handler)', 'file' => 'includes/post_handlers.php',
     'old' => "\$cbAction === '' && !\$hasTabAction && !csrfCheck()) {", 'new' => "\$cbAction === '' && !\$hasTabAction && (!csrfCheck() || true)) {",
     'sections' => 'L', 'pattern' => '/L19 (South Sudan|Uganda with the setting 0): the control/'],
    ['label' => 'ML19i (L) delete_hardware never reached (its block skipped) and its hook ungated', 'edits' => [
        [$PS, "(\$_POST['action']??'')==='delete_hardware'){", "(\$_POST['action']??'')==='delete_hardware_x'){"],
        [$PS, "    if (\$delId > 0 && QuoteOrganization::setting(is_array(\$config ?? null) ? \$config : [], isset(\$dataDir) ? (string)\$dataDir : null)['on']) {\n"
            . "        require_once dirname(__DIR__, 2) . '/lib/UcrmQuoteLines.php';   // loads lib/UcrmQuoteLinks.php (F-6)\n"
            . "        require_once dirname(__DIR__, 2) . '/lib/UcrmCatalogue.php';    // 5.18.103 (root docs/72 §G G-11): frozen while on\n"
            . "        if (!UcrmCatalogue::forConfig(\$store, is_array(\$config ?? null) ? \$config : [], isset(\$dataDir) ? (string)\$dataDir : null)->direct()) try {\n            \$_dlEnded = UcrmQuoteLinks::end(\$store, 'kit',",
              "    if (\$delId > 0) {\n"
            . "        require_once dirname(__DIR__, 2) . '/lib/UcrmQuoteLines.php';   // loads lib/UcrmQuoteLinks.php (F-6)\n"
            . "        require_once dirname(__DIR__, 2) . '/lib/UcrmCatalogue.php';    // 5.18.103 (root docs/72 §G G-11): frozen while on\n"
            . "        if (!UcrmCatalogue::forConfig(\$store, is_array(\$config ?? null) ? \$config : [], isset(\$dataDir) ? (string)\$dataDir : null)->direct()) try {\n            \$_dlEnded = UcrmQuoteLinks::end(\$store, 'kit',"]],
     'sections' => 'L', 'pattern' => '/L19 (South Sudan|Uganda with the setting 0): the control/'],
    ['label' => 'ML23 (L) delete_plan on Uganda leaves the link active', 'file' => $PS,
     'old' => "            \$_dlEnded = UcrmQuoteLinks::end(\$store, 'plan', \$delId, UcrmQuoteLinks::WHY_DELETED, (array)(\$auth->currentRetailer() ?? []));",
     'new' => "            \$_dlEnded = [];",
     'sections' => 'L', 'pattern' => '/L5 (the control: deleting a plan|a delete over two)/'],

    // ── the screens (subscription_plans.php) ────────────────────────────────────────────────────────────────────────
    ['label' => 'ML17 (L) the picker pre-selects the row\'s stored ucrm_product_id', 'file' => $SP,
     'old' => "                    \$preP = \$j['state'] === 'ok' ? (int)(\$j['record']['product_id'] ?? 0) : 0;",
     'new' => "                    \$preP = \$j['state'] === 'ok' ? (int)(\$j['record']['product_id'] ?? 0) : (int)((\$row ?? [])['ucrm_product_id'] ?? 0);",
     'sections' => 'L', 'pattern' => '/L14 the picker pre-selects nothing: plan row 3/'],
    ['label' => 'ML17 (L) the picker pre-selects the product spelled like the row', 'file' => $SP,
     'old' => "(\$pid === \$preP ? ' checked' : '')",
     'new' => "((\$pid === \$preP || (\$rowKey !== '' && \$k === \$rowKey)) ? ' checked' : '')",
     'sections' => 'L', 'pattern' => '/L14 the picker pre-selects nothing: plan row 1/'],
    // 5.18.102 D14 review (T2): the same two faults planted in hardware.php's picker
    ['label' => 'ML17 (L) the Hardware picker pre-selects the row\'s stored ucrm_product_id', 'file' => $HW,
     'old' => "                    \$preP = \$j['state'] === 'ok' ? (int)(\$j['record']['product_id'] ?? 0) : 0;",
     'new' => "                    \$preP = \$j['state'] === 'ok' ? (int)(\$j['record']['product_id'] ?? 0) : (int)((\$row ?? [])['ucrm_product_id'] ?? 0);",
     'sections' => 'L', 'pattern' => '/L14 the picker pre-selects nothing: kit row 4/'],
    ['label' => 'ML17 (L) the Hardware picker pre-selects the product spelled like the row', 'file' => $HW,
     'old' => "(\$pid === \$preP ? ' checked' : '')",
     'new' => "((\$pid === \$preP || (\$rowKey !== '' && \$k === \$rowKey)) ? ' checked' : '')",
     'sections' => 'L', 'pattern' => '/L14 the picker pre-selects nothing: kit row 3/'],
    ['label' => 'ML45 (L) h() dropped on the picker\'s product name', 'file' => $SP,
     'old' => "                            . \$_lk102Prod(\$pid) . ' <strong>' . h(\$pname) . '</strong> · '",
     'new' => "                            . \$_lk102Prod(\$pid) . ' <strong>' . \$pname . '</strong> · '",
     'sections' => 'L', 'pattern' => '/L17/'],
    ['label' => 'ML45 (L) h() dropped on the link column\'s product name', 'file' => $SP,
     'old' => "' <span style=\"font-weight:600;\">' . h((string)\$line['label']) . '</span> · '",
     'new' => "' <span style=\"font-weight:600;\">' . (string)\$line['label'] . '</span> · '",
     'sections' => 'L', 'pattern' => '/L17/'],
    ['label' => 'ML61 (L) a second text for two active records, on the Plans screen', 'file' => $SP,
     'old' => "                    return implode(' ', \$ids) . \$reasonDiv((string)\$_lk102Ans(\$item, \$row, true)['reason']) . \$acts;",
     'new' => "                    return implode(' ', \$ids) . \$reasonDiv('Two uCRM links are recorded for this item — link it again') . \$acts;",
     'sections' => 'L', 'pattern' => '/L16 two active records/'],
    ['label' => 'ML78 (L) the link column for two active records shows one record\'s id', 'file' => $SP,
     'old' => "                    foreach (\$j['records'] as \$r) \$ids[] = \$_lk102Prod((int)(\$r['product_id'] ?? 0));",
     'new' => "                    foreach (array_slice(\$j['records'], 0, 1) as \$r) \$ids[] = \$_lk102Prod((int)(\$r['product_id'] ?? 0));",
     'sections' => 'L', 'pattern' => '/L16 … the link column shows both/'],
    ['label' => 'ML78 (L) the link column for two active records draws no Change or Unlink', 'file' => $SP,
     'old' => "                    return implode(' ', \$ids) . \$reasonDiv((string)\$_lk102Ans(\$item, \$row, true)['reason']) . \$acts;",
     'new' => "                    return implode(' ', \$ids) . \$reasonDiv((string)\$_lk102Ans(\$item, \$row, true)['reason']);",
     'sections' => 'L', 'pattern' => '/L16 … the link column shows both/'],

    // ── what was quoted under a link (KycService::makeQuotation, the shared implementation) ─────────────────────────
    ['label' => 'ML39 (L) a retry (retry_quote, Make the quotation) does not record quote_link_ids', 'file' => $K,
     'old' => "                    'quote_link_ids'   => UcrmQuoteLines::linkIds(\$r['link_ids'] ?? []),\n", 'new' => '',   // re-anchored (D14-MQ-THROW-AFTER-POST)
     'sections' => 'L', 'pattern' => '/L18 … each carries quote_link_ids/'],
    // 5.18.102 D14 review (T1): a direct load of the link store moved out from behind each KYC path's gate — L19b's
    // South Sudan and setting-0 runs must see the table created
    ['label' => 'ML19b (L) handleNew loads the link store before its gate', 'file' => $K,
     'old' => "        if (\$ugOrg['on']) {\n", 'new' => "        \$this->store->load('ucrm_quote_links.json');\n        if (\$ugOrg['on']) {\n",
     'sections' => 'L', 'pattern' => '/L19b (South Sudan|Uganda with the setting 0)/'],
    ['label' => 'ML19c (L) handleExisting loads the link store before its gate', 'file' => $K,
     'old' => "        \$ug = null;\n        if (\$quoteOrg['on']) {\n", 'new' => "        \$ug = null;\n        \$this->store->load('ucrm_quote_links.json');\n        if (\$quoteOrg['on']) {\n",
     'sections' => 'L', 'pattern' => '/L19b (South Sudan|Uganda with the setting 0)/'],
    ['label' => 'ML19d (L) syncOne loads the link store before its gate', 'file' => 'lib/KycCrmSync.php',
     'old' => "        if (\$ugCheck !== null) {\n", 'new' => "        \$this->store->load('ucrm_quote_links.json');\n        if (\$ugCheck !== null) {\n",
     'sections' => 'L', 'pattern' => '/L19b (South Sudan|Uganda with the setting 0)/'],
    ['label' => 'ML19e (L) retry_quote loads the link store before its gate', 'file' => 'includes/api/api_cron_debug.php',
     'old' => "        if (\$_qoRetry['on']) {\n            // The account re-read fresh by id",
     'new' => "        \$store->load('ucrm_quote_links.json');\n        if (\$_qoRetry['on']) {\n            // The account re-read fresh by id",
     'sections' => 'L', 'pattern' => '/L19b (South Sudan|Uganda with the setting 0)/'],
    ['label' => 'ML19f (L) a lead quote\'s lines load the link store before the gate', 'file' => 'lib/QuotationService.php',
     'old' => "        if (\$this->quoteOrg()['on']) {\n            self::needQuoteLines();",
     'new' => "        \$this->store->load('ucrm_quote_links.json');\n        if (\$this->quoteOrg()['on']) {\n            self::needQuoteLines();",
     'sections' => 'L', 'pattern' => '/L19b (South Sudan|Uganda with the setting 0)/'],
    ['label' => 'ML19g (L) Send Quotation loads the link store before its gate', 'file' => 'tabs/sales/send_quote.php',
     'old' => "if (\$ugQuote) {\n", 'new' => "\$store->load('ucrm_quote_links.json');\nif (\$ugQuote) {\n",
     'sections' => 'L', 'pattern' => '/L19b (South Sudan|Uganda with the setting 0)/'],
    // 5.18.102 D14 review round 2 (FX-tests-1): L19b's paths must be shown to run to their end — each copy below stops one
    // after its gate (a fatal, or an early answer that still exits 0)
    ['label' => 'ML19j (L) Send Quotation stops after its gate (a fatal before the page is drawn)', 'file' => 'tabs/sales/send_quote.php',
     'old' => "    \$ugLeadShared = json_encode(array_values(\$ugPick['shared_keys']), \$ugJsonFlags) ?: '[]';\n}\n",
     'new' => "    \$ugLeadShared = json_encode(array_values(\$ugPick['shared_keys']), \$ugJsonFlags) ?: '[]';\n}\nthrow new \\RuntimeException('TEST: Send Quotation stops after its gate');\n",
     'sections' => 'L', 'pattern' => '/L19b the control/'],
    ['label' => 'ML19k (L) retry_quote answers before its gate-off path runs', 'file' => 'includes/api/api_cron_debug.php',
     'old' => "        // Build items from app data\n",
     'new' => "        if (!\$_qoRetry['on']) \$er2('TEST: stopped before the gate-off path', 400);\n        // Build items from app data\n",
     'sections' => 'L', 'pattern' => '/L19b (South Sudan|Uganda with the setting 0)/'],
    // 5.18.102 D14 review round 2 (FX-money-1, FX-flows-1): the kit half of rule 8 at save put back to offered rows only —
    // each copy is the 72d534d3 line, in one of the save's two checks
    ['label' => 'FXM1a (L) the save\'s check before the lock (candidate) refuses the installation\'s product to offered kit rows only', 'file' => $U,
     'old' => "        if (\$item === 'kit' && \$clash['install']) {",
     'new' => "        if (\$item === 'kit' && (\$atUse || UcrmQuoteLinks::offeredKit(\$row ?? [])) && \$clash['install']) {",
     'sections' => 'L', 'pattern' => "/L8 rule 8: in an inactive kit row's picker/"],
    ['label' => 'FXM1b (L) set()\'s check inside the lock refuses the installation\'s product to offered kit rows only', 'file' => $L,
     'old' => "                if (\$item === 'kit' && \$clash['install']) {",
     'new' => "                if (\$item === 'kit' && self::offeredKit(\$row) && \$clash['install']) {",
     'sections' => 'L', 'pattern' => "/L8 rule 8, crosswise: an inactive kit row/"],
    // 5.18.102 D14 review (G2): the save with the gate on but the quoting-organization setting invalid
    ['label' => 'ML-R6 (L) the save does not refuse an invalid quoting-organization setting before the uCRM read', 'file' => $PS,
     'old' => "        if (!is_int(\$_lkSet['id'] ?? null)) {\n", 'new' => "        if (false) {\n",
     'sections' => 'L', 'pattern' => "/L-R6 setting '(abc|1x)'/"],
    // 5.18.102 D14 review round 2 (FX-money-2): the setting check moved ahead of rule 1 (the handler built to §R.1.4 as it
    // read) — the setting's text then reaches a non-admin and a cross-site post; only L-R6b can see it
    ['label' => 'ML-R6b (L) the save checks the quoting-organization setting before the admin and own-page checks', 'sections' => 'L',
     'edits' => [
        [$PS, "        if (!is_int(\$_lkSet['id'] ?? null)) {\n            error_log('[post_sync] 5.18.102 ucrm_link_save: refused, the quoting-organization setting is invalid, by #' . \$_lkMeId);\n"
            . "            flash('The company the app quotes under is not set correctly, so nothing was linked. Tell an admin.', 'danger');\n            redirect(\$_lkBack);\n        }\n", ''],
        [$PS, "        \$_lkBack = \$_lkItem === 'plan' ? '?page=dashboard&tab=subscription_plans' : '?page=dashboard&tab=hardware';\n",
              "        \$_lkBack = \$_lkItem === 'plan' ? '?page=dashboard&tab=subscription_plans' : '?page=dashboard&tab=hardware';\n"
            . "        if (!is_int(QuoteOrganization::setting(is_array(\$config ?? null) ? \$config : [], isset(\$dataDir) ? (string)\$dataDir : null)['id'] ?? null)) {\n"
            . "            flash('The company the app quotes under is not set correctly, so nothing was linked. Tell an admin.', 'danger');\n            redirect(\$_lkBack);\n        }\n"],
     ], 'pattern' => '/L-R6b/'],
]);

