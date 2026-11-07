<?php
declare(strict_types=1);
/**
 * Weakened copies judged by section C (5.18.104, root docs/73 §H.4.16 C): the Offers screen, its menu entry and its seven
 * actions. Each is one anchored edit of the named file (or several, each found exactly once). OM55's A copy is re-judged
 * here by C's case; OM120's K copy is re-judged by C in mutants_KC.php. TEST ONLY.
 */
$PO = 'includes/post/post_offers.php';
$TB = 'tabs/engage/wa_offers.php';
$hitA = array_values(array_filter($M, function ($m) { return strpos($m['label'], 'OM55 ') === 0; }));
if (count($hitA) !== 1) throw new \LogicException('mutants_C: OM55 found ' . count($hitA) . ' times in the earlier files');
$om55 = $hitA[0];
$om55['label'] .= ' — again at the screen\'s Start';
$om55['sections'] = 'C';
$om55['cases'] = 'C0[17]';
$om55['pattern'] = '/C07 /';
$M = array_merge($M, [
    ['label' => 'OM4 the menu entry drawn without screen()', 'file' => 'includes/navigation.php',
     'old' => "if (\$_wo104Nav): ?>", 'new' => "if (true): ?>",
     'sections' => 'C', 'cases' => 'C0[12]', 'pattern' => '/C02 /'],
    ['label' => 'OM5 the tab drawn with the switch off', 'file' => $TB,
     'old' => "if (!OfferSwitch::screen(\$_woCfg, \$_woData)) {", 'new' => "if (false) {",
     'sections' => 'C', 'cases' => 'C0[12]', 'pattern' => '/C02 /'],
    ['label' => 'OM6 requireAdmin removed (the session account taken as it is)', 'file' => $PO,
     'old' => "    \$_ofMe   = \$auth->requireAdmin();", 'new' => "    \$_ofMe   = \$auth->currentRetailer() ?? [];",
     'sections' => 'C', 'cases' => 'C0[13]', 'pattern' => '/C03 /'],
    ['label' => 'OM7 the fresh admin re-read removed (the session copy trusted)', 'file' => $PO,
     'old' => "        \$_ofFresh = \$_ofMeId > 0 ? \$store->findOne('retailers.json', 'id', \$_ofMeId) : null;", 'new' => "        \$_ofFresh = \$_ofMe;",
     'sections' => 'C', 'cases' => 'C0[13]', 'pattern' => '/C03 /'],
    ['label' => 'OM8 the CSRF and own-page check removed', 'file' => $PO,
     'old' => "    if (!(csrfCheck() && !CustomerSession::crossSite() && BankGate::fromOwnPage('POST'))) {", 'new' => "    if (false) {",
     'sections' => 'C', 'cases' => 'C0[14]', 'pattern' => '/C04 /'],
    ['label' => 'OM52 the test sent to a number taken from the POST', 'file' => $PO,
     'old' => "->sendTest(\$_ofId, \$_ofVar, \$_ofCh, \$_ofFresh);",
     'new' => "->sendTest(\$_ofId, \$_ofVar, \$_ofCh, ['phone' => (string)(\$_POST['phone'] ?? '')] + \$_ofFresh);",
     'sections' => 'C', 'cases' => 'C(01|09)', 'pattern' => '/C09 /'],
    ['label' => 'OM53a Start without every wording tested', 'file' => 'lib/OfferStore.php',
     'old' => "            if (self::testedVariants(\$pdo, \$o, \$now) !== count(self::wordingsOf(\$o))) return \$fail('untested');\n", 'new' => '',
     'sections' => 'C', 'cases' => 'C0[1257]', 'pattern' => '/C07 /'],
    ['label' => 'OM53b Start on an old hash (the confirmed content not compared)', 'file' => 'lib/OfferStore.php',
     'old' => "WHERE id = ? AND state = 'draft' AND content_hash = ?\");\n                \$st->execute([\$at, \$byName, (int)\$set['offers_warm_days'], (int)\$set['offers_recent_days'], \$at, \$id, \$contentHash]);",
     'new' => "WHERE id = ? AND state = 'draft' AND ? <> ''\");\n                \$st->execute([\$at, \$byName, (int)\$set['offers_warm_days'], (int)\$set['offers_recent_days'], \$at, \$id, \$contentHash]);",
     'sections' => 'C', 'cases' => 'C0[1257]', 'pattern' => '/C07 /'],
    $om55,
    ['label' => 'OM113-C the offer_test action without WaSendBudget::on() (only sendTest\'s own read refuses)', 'file' => $PO,
     'old' => "    if (\$_ofAct === 'offer_test' && !WaSendBudget::on(\$_ofCfg, \$_ofData)) {", 'new' => "    if (false) {",
     'sections' => 'C', 'cases' => 'C(01|09)', 'pattern' => '/C09 /'],
    ['label' => 'OM-C1 the image\'s extension from the client\'s file name', 'file' => 'lib/OfferImage.php',
     'old' => "        \$name = 'offer-' . \$offerId . '.' . \$ext;",
     'new' => "        \$name = 'offer-' . \$offerId . '.' . strtolower((string)pathinfo((string)(\$upload['name'] ?? ''), PATHINFO_EXTENSION));",
     'sections' => 'C', 'cases' => 'C0[16]', 'pattern' => '/C06 /'],
    ['label' => 'OM-C2 Stop leaves the queued rows queued', 'file' => 'lib/OfferStore.php',
     'old' => "            \$q = \$pdo->prepare(\"UPDATE wa_offer_recipients SET state = 'skipped', reason = 'offer_stopped', settled_at = ?\n                                 WHERE offer_id = ? AND state = 'queued'\");",
     'new' => "            \$q = \$pdo->prepare(\"UPDATE wa_offer_recipients SET state = 'skipped', reason = 'offer_stopped', settled_at = ?\n                                 WHERE offer_id = ? AND state = 'queued' AND 0\");",
     'sections' => 'C', 'cases' => 'C0[1278]', 'pattern' => '/C08 /'],
    ['label' => 'OM-C3 the action\'s validation skipped (the store\'s own refusal answers "try again")', 'file' => $PO,
     'old' => "            \$_ofBad = OfferText::validate(\$_ofTitle, \$_ofWords);", 'new' => "            \$_ofBad = [];",
     'sections' => 'C', 'cases' => 'C0[15]', 'pattern' => '/C05 /'],
    ['label' => 'OM-C4 a refused image does not roll the draft back', 'file' => $PO,
     'old' => "                    if (!\$_ofImg['ok']) throw new \\RuntimeException('offer_save:image:' . \$_ofCode((string)\$_ofImg['code']));",
     'new' => "                    if (!\$_ofImg['ok']) { if (\$_ofOwn) \$_ofPdo->exec('COMMIT'); \$_ofOwn = false; throw new \\RuntimeException('offer_save:image:' . \$_ofCode((string)\$_ofImg['code'])); }",
     'sections' => 'C', 'cases' => 'C0[16]', 'pattern' => '/C06 /'],
    // B8-fix1 (B8-R02): the size rule of OfferImage::accept (D 7000-7007: "at most FlyerAsset::MAX_FILE_BYTES"), served and direct
    ['label' => 'OM-C6 the image size rule removed (a file over FlyerAsset::MAX_FILE_BYTES accepted)', 'file' => 'lib/OfferImage.php',
     'old' => "        if (\$bytes > FlyerAsset::MAX_FILE_BYTES) return ['ok' => false, 'code' => 'too_large'];\n", 'new' => '',
     'sections' => 'C', 'cases' => 'C0[16]', 'pattern' => '/C06 /'],
    ['label' => 'OM-C7 the image size rule off by one (a file of exactly 4 MB refused)', 'file' => 'lib/OfferImage.php',
     'old' => "if (\$bytes > FlyerAsset::MAX_FILE_BYTES)", 'new' => "if (\$bytes >= FlyerAsset::MAX_FILE_BYTES)",
     'sections' => 'C', 'cases' => 'C0[16]', 'pattern' => '/C06 /'],
    ['label' => 'OM-C5 the preview prints the customers\' numbers', 'file' => $TB,
     'old' => "  <p>Eligible now: <?= (int)\$_woAud['all_eligible'] ?>;",
     'new' => "  <p><?= \$_woH(implode(' ', array_column(\$_woAud['eligible'], 'phone'))) ?> Eligible now: <?= (int)\$_woAud['all_eligible'] ?>;",
     'sections' => 'C', 'cases' => 'C(01|07|10)', 'pattern' => '/C10 /'],
]);
unset($PO, $TB, $hitA, $om55);
