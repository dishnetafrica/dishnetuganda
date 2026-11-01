<?php
declare(strict_types=1);
/** Weakened copies for section D of tests/test_catalogue_direct.php (5.18.103, root docs/72 §G.13): each a one-fault copy
 * of the plugin that section must catch. Appended to $QFU_MUTANTS by the runner. TEST ONLY. */
$QFU_MUTANTS = array_merge($QFU_MUTANTS, [
    ['label' => 'MD1 stored direct lines quoted without being confirmed', 'file' => 'lib/KycService.php',
     'old' => '                $reason = $uql->confirmDirect($app[\'quote_items\'], $app[\'quote_item_ids\'] ?? null);',
     'new' => '                $reason = \'\';', 'sections' => 'D', 'pattern' => '/D1 /'],
    ['label' => 'MD2 an unpriced direct sale retried without "kept off"', 'file' => 'lib/KycService.php',
     'old' => '                $res = $uql->resolveDirect($sale, $cat->installProductId(), $cat->hiddenIds(), true);',
     'new' => '                $res = $uql->resolveDirect($sale, $cat->installProductId(), $cat->hiddenIds(), false);', 'sections' => 'D', 'pattern' => '/D2 /'],
    ['label' => 'MD3 Make the quotation offered for a 5.18.102 web application with the switch on', 'file' => 'lib/KycService.php',
     'old' => '        return ($entry === UcrmQuoteLines::DIRECT_ENTRY || ($entry === \'web\' && !$directOn))',
     'new' => '        return ($entry === UcrmQuoteLines::DIRECT_ENTRY || $entry === \'web\')', 'sections' => 'D', 'pattern' => '/D[35] /'],
    ['label' => 'MD4 the gate-off builder quoting a direct application', 'file' => 'lib/KycCrmSync.php',
     'old' => '        } elseif ($autoQuote && empty($app[\'quote_created\']) && ($app[\'quote_sale_entry\'] ?? null) === UcrmQuoteLines::DIRECT_ENTRY) {',
     'new' => '        } elseif (false) {', 'sections' => 'D', 'pattern' => '/D4 /'],
    ['label' => 'MD5 retry_quote\'s gate-off path quoting a direct application', 'file' => 'includes/api/api_cron_debug.php',
     'old' => '        if (($app[\'quote_sale_entry\'] ?? null) === \'web-direct\') {',
     'new' => '        if (false) {', 'sections' => 'D', 'pattern' => '/D6 /'],
    ['label' => 'MD6 a 5.18.102 web application told to wait for a link with the switch on', 'file' => 'lib/KycService.php',
     'old' => '        if ($directOn && !empty($res[\'unlinked\']) && ($res[\'entry\'] ?? null) === \'web\') return UcrmQuoteLines::text(\'WEB_SWITCH_ON\');',
     'new' => '', 'sections' => 'D', 'pattern' => '/D3 /'],
    ['label' => 'MD7 the Orders card keeps the stranded record\'s old text', 'file' => 'tabs/sales/applications.php',
     'old' => 'if ($ugDirect103 && ($a[\'quote_sale_entry\'] ?? null) === \'web\' && ($a[\'quote_refused\'] ?? null) === \'P1\' && !empty($a[\'quote_refused_text\'])) $a[\'quote_refused_text\'] = UcrmQuoteLines::text(\'WEB_SWITCH_ON\'); ?>',
     'new' => '?>', 'sections' => 'D', 'pattern' => '/D5 /'],
    ['label' => 'MD8 a direct application\'s P1 not marked as direct', 'file' => 'lib/KycService.php',
     'old' => '            $code = ($res[\'why\'] ?? \'\') === \'no_install_answer\' ? \'P1\' : \'P1_DIRECT\';',
     'new' => '            $code = \'P1\';', 'sections' => 'D', 'pattern' => '/D1 /'],
]);
