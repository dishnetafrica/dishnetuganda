<?php
declare(strict_types=1);
/** Weakened copies for section C of tests/test_catalogue_direct.php (5.18.103, root docs/72 §G.13): each a one-fault copy
 * of the plugin that section must catch. Appended to $QFU_MUTANTS by the runner. TEST ONLY. */
$QFU_MUTANTS = array_merge($QFU_MUTANTS, [
    ['label' => 'MC1 the posted package_choice kept on a direct sale', 'file' => 'lib/KycService.php',
     'old' => '                $post[\'package_choice\'] = \'\';
                $post[\'device_id\']      = \'\';',
     'new' => '                $post[\'device_id\']      = \'\';', 'sections' => 'C', 'pattern' => '/C[12] /'],
    ['label' => 'MC2 the posted hw_cart_json kept on a direct sale', 'file' => 'lib/KycService.php',
     'old' => '                $post[\'hw_cart_json\']   = self::directCartJson($ug);',
     'new' => '', 'sections' => 'C', 'pattern' => '/C[12] /'],
    ['label' => 'MC3 a direct Cash sale not checked against the drawn items', 'file' => 'lib/KycService.php',
     'old' => '            return UcrmQuoteLines::seenItemsMatch($post[\'ucrm_items_seen\'] ?? null, (array)($res[\'tokens\'] ?? []))
                ? \'\' : UcrmQuoteLines::text(\'CASH_ITEM_CHANGED\');',
     'new' => '            return \'\';', 'sections' => 'C', 'pattern' => '/C3 /'],
    ['label' => 'MC4 a direct form accepted with the switch off', 'file' => 'lib/KycService.php',
     'old' => '            if ($ugForm && !$ugDirect) return [\'success\' => false, \'message\' => UcrmQuoteLines::text(\'DIRECT_OFF\')];',
     'new' => '', 'sections' => 'C', 'pattern' => '/C4 /'],
    ['label' => 'MC5 the direct marker read as web-direct whatever the switch', 'file' => 'lib/KycService.php',
     'old' => '        if ($directOn === true) {
            $entry = $marker === UcrmQuoteLines::DIRECT_MARKER ? UcrmQuoteLines::DIRECT_ENTRY : \'app\';',
     'new' => '        if ($marker === UcrmQuoteLines::DIRECT_MARKER) {
            $entry = UcrmQuoteLines::DIRECT_ENTRY;', 'sections' => 'C', 'pattern' => '/C[47] /'],
    ['label' => 'MC6 an unpriced direct sale stored without its ids', 'file' => 'lib/KycService.php',
     'old' => '            $rec[\'quote_item_ids\']   = $res[\'item_ids\'];',
     'new' => '            if ($res[\'status\'] === \'priced\') $rec[\'quote_item_ids\'] = $res[\'item_ids\'];', 'sections' => 'C', 'pattern' => '/C[56] /'],
    ['label' => 'MC7 the switch on, a form opened before it priced by its links', 'file' => 'lib/KycService.php',
     'old' => '            $entry = $marker === UcrmQuoteLines::DIRECT_MARKER ? UcrmQuoteLines::DIRECT_ENTRY : \'app\';',
     'new' => '            $entry = $marker === UcrmQuoteLines::DIRECT_MARKER ? UcrmQuoteLines::DIRECT_ENTRY : ($marker === UcrmQuoteLines::FORM_MARKER ? \'web\' : \'app\');', 'sections' => 'C', 'pattern' => '/C7 /'],
    ['label' => 'MC8 a direct sale stored with the entry web', 'file' => 'lib/KycService.php',
     'old' => '            $rec[\'quote_sale_entry\'] = UcrmQuoteLines::DIRECT_ENTRY;',
     'new' => '            $rec[\'quote_sale_entry\'] = \'web\';', 'sections' => 'C', 'pattern' => '/C[15] /'],
]);
