<?php
declare(strict_types=1);
/**
 * The weakened copies of section H (tests/quote_from_ucrm/H_kyc_form.php): the Uganda KYC form. Each is one fault planted
 * in a copy of the plugin and must fail section H — where a pattern is given, on a FAIL line matching it. The browser
 * ones (MH9, MH10, MH15) are caught only by h_kyc_form_browser.js: where node or Playwright is missing they are not
 * caught, and the runner says so as a failure, never a skip. TEST ONLY. Appended to $QFU_MUTANTS by the runner.
 *
 * 5.18.102 D14 (docs/71 §R.9.3): MH7 re-anchored (forPage now takes the link judge) and MH14 re-anchored (the kit loop
 * chooses its cell by UcrmQuoteLinks::offeredKit, F-5). Added: ML22 (the plugin's name drawn for a priced, linked row —
 * kit and plan), ML24's H half (the installation found by its name), MH16 (the kit loop's own case-sensitive Fiber test)
 * and MH17 (the selection's link records not posted, a browser fault).
 */
$KF = 'tabs/sales/kyc_form.php';
$QFU_MUTANTS = array_merge($QFU_MUTANTS ?? [], [
    ['label' => 'MH1 the "DishNet installs" radio drawn enabled', 'file' => $KF,
     'old' => '<input type="radio" name="install_by" value="dishnet" disabled>',
     'new' => '<input type="radio" name="install_by" value="dishnet">', 'sections' => 'H', 'pattern' => '/H5/'],
    ['label' => 'MH2 the "Customer installs" radio marked required', 'file' => $KF,
     'old' => '<input type="radio" name="install_by" value="customer" disabled>',
     'new' => '<input type="radio" name="install_by" value="customer" disabled required>', 'sections' => 'H', 'pattern' => '/H5/'],
    ['label' => 'MH3 "Customer installs" preselected', 'file' => $KF,
     'old' => '<input type="radio" name="install_by" value="customer" disabled>',
     'new' => '<input type="radio" name="install_by" value="customer" disabled checked>', 'sections' => 'H', 'pattern' => '/H5/'],
    ['label' => 'MH4 the gate dropped from the ucrm_total_seen hunk (South Sudan draws it, and the banner)', 'file' => $KF,
     'old' => "<?php if (\$_q102 !== null): /* 5.18.102 (Uganda, docs/71 §G): the total the form showed, posted for the server's check */ ?>",
     'new' => "<?php if (true): /* 5.18.102 (Uganda, docs/71 §G): the total the form showed, posted for the server's check */ ?>",
     'sections' => 'H', 'pattern' => '/H11/'],
    ['label' => 'MH5 _kycFiberInstallFee declared on Uganda', 'file' => $KF,
     'old' => "<?php if (\$_q102 === null): /* 5.18.102 (Uganda, docs/71 §D.4)",
     'new' => "<?php if (true): /* 5.18.102 (Uganda, docs/71 §D.4)", 'sections' => 'H', 'pattern' => '/H6/'],
    // 5.18.102 D14 review (D14-UI-5): applyDefaults' keyword on uCRM's displayed names again (38991cbb's form)
    ['label' => 'MH-UI5a the default plan matched on uCRM\'s displayed name (data-name)', 'file' => $KF,
     'old' => "dataset.<?= \$_q102 !== null ? 'rowName' : 'name' /* 5.18.102 D14 review (D14-UI-5): the row's own name */ ?>||",
     'new' => "dataset.name||", 'sections' => 'H', 'pattern' => '/10\.1/'],
    ['label' => 'MH-UI5b the default kit (first pass) matched on uCRM\'s displayed title (data-title)', 'file' => $KF,
     'old' => "        var t = (rows[ri].dataset.<?= \$_q102 !== null ? 'rowTitle' : 'title' /* 5.18.102 D14 review (D14-UI-5): the row's own title */ ?> || '').toLowerCase();",
     'new' => "        var t = (rows[ri].dataset.title || '').toLowerCase();", 'sections' => 'H', 'pattern' => '/10\.1/'],
    ['label' => 'MH-UI5c data-row-name built from uCRM\'s name ($q102Name), not the row\'s', 'file' => $KF,
     'old' => "' data-row-name=\"' . h((string)\$sp['name']) . '\"'", 'new' => "' data-row-name=\"' . h((string)\$q102Name) . '\"'",
     'sections' => 'H', 'pattern' => '/10\.1/'],
    // 5.18.102 D14 review (D14-UI-3): the installation's reason back in the title only (38991cbb's card)
    ['label' => 'MH-UI3 the installation card\'s reason drawn only in its title', 'file' => $KF,
     'old' => "        <div class=\"q102-reason\" style=\"font-size:10px;color:#b45309;margin-top:2px;white-space:normal;\"><?= h((string)\$_q102['install']['r']) ?></div>\n",
     'new' => '', 'sections' => 'H', 'pattern' => '/H10 … the installation\'s reason is drawn as text/'],
    ['label' => 'MH6 a hardware row\'s reason drawn unescaped', 'file' => $KF,
     'old' => "      <div class=\"q102-reason\" style=\"font-size:10px;color:#b45309;margin-top:2px;white-space:normal;\"><?= h((string)\$q102Cell['r']) ?></div>",
     'new' => "      <div class=\"q102-reason\" style=\"font-size:10px;color:#b45309;margin-top:2px;white-space:normal;\"><?= (string)\$q102Cell['r'] ?></div>",
     'sections' => 'H', 'pattern' => '/H3/'],
    // re-anchored by D14: forPage takes the link judge as its fourth argument
    ['label' => 'MH7 the page reads uCRM with a plain resolver (no short timeouts, nothing reused)', 'file' => $KF,
     'old' => "        \$_q102Res = UcrmQuoteLines::forPage(UcrmQuoteLines::quoteClient(isset(\$crm) ? \$crm : null, is_array(\$config) ? \$config : []),\n            \$_q102Set, (string)\$dataDir, UcrmQuoteLinks::fromStore(\$store, \$_q102Set));",
     'new' => "        \$_q102Res = new UcrmQuoteLines(UcrmQuoteLines::quoteClient(isset(\$crm) ? \$crm : null, is_array(\$config) ? \$config : []),\n            \$_q102Set, UcrmQuoteLinks::fromStore(\$store, \$_q102Set));",
     'sections' => 'H', 'pattern' => '/H8/'],
    ['label' => 'MH8 the page cache never reused (every draw reads uCRM again)', 'file' => 'lib/UcrmQuoteLines.php',
     'old' => "        \$raw  = @file_get_contents(\$file);\n        if (is_string(\$raw)) {",
     'new' => "        \$raw  = @file_get_contents(\$file);\n        if (false && is_string(\$raw)) {",
     'sections' => 'H', 'pattern' => '/H8 a second draw/'],
    ['label' => 'MH9 Cash left enabled while the total is not priced', 'file' => $KF,
     'old' => 'if (cash) cash.disabled = no;', 'new' => 'if (cash) cash.disabled = false;', 'sections' => 'H', 'pattern' => '/H12 browser/'],
    ['label' => 'MH10 the total summed over the priced lines only (a partial total)', 'file' => $KF,
     'old' => "if (out.reason !== '') { out.status = 'unpriced'; return out; }", 'new' => '', 'sections' => 'H', 'pattern' => '/H12 browser/'],
    ['label' => 'MH11 a plan row priced from customer_price', 'file' => $KF,
     'old' => "    if (\$q102Cell !== null) \$spPrice = \$q102Cell['p'] !== null ? (float)\$q102Cell['p'] : 0.0;\n",
     'new' => '', 'sections' => 'H', 'pattern' => '/H0 data-price|H1/'],
    ['label' => 'MH12 an unpriced hardware row keeps the plugin\'s price', 'file' => $KF,
     'old' => "    if (\$q102Cell !== null) \$dnum = \$q102Cell['p'] !== null ? (float)\$q102Cell['p'] : 0.0;",
     'new' => "    if (\$q102Cell !== null) \$dnum = \$q102Cell['p'] !== null ? (float)\$q102Cell['p'] : \$dnum;",
     'sections' => 'H', 'pattern' => '/H0 data-price|H1/'],
    ['label' => 'MH13 no banner when uCRM cannot be read', 'file' => $KF,
     'old' => "<?php if (empty(\$_q102['ok'])): ?>", 'new' => '<?php if (false): ?>', 'sections' => 'H', 'pattern' => '/H9/'],
    // re-anchored by D14 (F-5): the kit loop chooses the kit's cell or the Fiber cell by UcrmQuoteLinks::offeredKit
    ['label' => 'MH14 Fiber hardware sent to the resolver instead of the Fiber cell (uCRM sells "WiFi ONU")', 'file' => $KF,
     'old' => "UcrmQuoteLinks::offeredKit(\$_q102D)\n                    ? \$_q102Cell(",
     'new' => "!empty(\$_q102D['is_active'])\n                    ? \$_q102Cell(",
     'sections' => 'H', 'pattern' => '/H14/'],
    ['label' => 'MH15 "Who installs?" never asked (Starlink + New Connection included)', 'file' => $KF,
     'old' => "  return s.type.toLowerCase() === 'starlink' && ['Shifting Connection', 'Ownership Change'].indexOf(s.conn) < 0;",
     'new' => '  return false;', 'sections' => 'H', 'pattern' => '/H12 browser/'],

    // ── 5.18.102 D14 (docs/71 §R.9.3) ───────────────────────────────────────────────────────────────────────────────
    ['label' => 'ML22 the form draws the plugin\'s name for a priced, linked kit row', 'file' => $KF,
     'old' => "    \$q102Title    = (\$q102Resolves && isset(\$q102Cell['l'])) ? \$q102Cell['l'] : \$d['title'];",
     'new' => "    \$q102Title    = \$d['title'];",
     'sections' => 'H', 'pattern' => '/H13|H7/'],
    ['label' => 'ML22 the form draws the plugin\'s name for a priced, linked plan row', 'file' => $KF,
     'old' => "    \$q102Name     = (\$q102Resolves && isset(\$q102Cell['l'])) ? \$q102Cell['l'] : \$sp['name'];",
     'new' => "    \$q102Name     = \$sp['name'];",
     'sections' => 'H', 'pattern' => '/H13/'],
    ['label' => 'ML24 (H) the installation still found by its name', 'file' => 'lib/UcrmQuoteLines.php',
     'old' => "        \$j = \$this->links->judge('install', null, \$this->orgId());\n        if (\$j['state'] !== 'ok') return self::linkRefusal(\$j['state'], self::INSTALL_ROW, 'Hardware');",
     'new' => "        \$j = \$this->links->judge('install', null, \$this->orgId());\n        if (\$j['state'] === 'none') {\n            foreach (\$cat['products'] as \$p) {\n                if ((string)(\$p['name'] ?? '') === self::INSTALL_NAME) return \$this->hardwareRules('install', null, self::INSTALL_ROW, (int)\$p['id'], null, true, 1);\n            }\n        }\n        if (\$j['state'] !== 'ok') return self::linkRefusal(\$j['state'], self::INSTALL_ROW, 'Hardware');",
     'sections' => 'H', 'pattern' => '/H10 the installation not linked/'],
    ['label' => 'MH16 the kit loop\'s own Fiber test, case-sensitive (a kit typed "FIBER" sent to the resolver)', 'file' => $KF,
     'old' => "UcrmQuoteLinks::offeredKit(\$_q102D)\n                    ? \$_q102Cell(",
     'new' => "(string)(\$_q102D['type'] ?? 'general') !== 'fiber'\n                    ? \$_q102Cell(",
     'sections' => 'H', 'pattern' => '/H14 an active kit row typed "FIBER"/'],
    ['label' => 'MH18 the Cash note keeps its "fix the product in uCRM" tail', 'file' => $KF,
     'old' => "'. Register as Credit, or ask an admin.' : '';",
     'new' => "'. Register as Credit, or ask an admin to fix the product in uCRM.' : '';",
     'sections' => 'H', 'pattern' => '/H16|H12 browser/'],
    ['label' => 'MH17 the selection\'s link records not posted (ucrm_links_seen left empty)', 'file' => $KF,
     'old' => "  if (linksSeen) linksSeen.value = q.status === 'priced' ? q.links.join(',') : '';",
     'new' => "  if (linksSeen) linksSeen.value = '';",
     'sections' => 'H', 'pattern' => '/H12 browser/'],
]);
