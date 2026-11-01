<?php
declare(strict_types=1);
/** Weakened copies for section B of tests/test_catalogue_direct.php (5.18.103, root docs/72 §G.13): each a one-fault copy
 * of the plugin that section must catch. Appended to $QFU_MUTANTS by the runner. TEST ONLY. */
$QFU_MUTANTS = array_merge($QFU_MUTANTS, [
    ['label' => 'MB1 the plan pair counted after judging (a priced-0 twin ignored)', 'file' => 'lib/UcrmQuoteLines.php',
     'old' => '            if (is_array($prod) && PublicPriceFeed::planKey((string)($prod[\'name\'] ?? \'\')) === $key) $cands[] = $prod;',
     'new' => '            if (is_array($prod) && (float)($prod[\'price\'] ?? 0) > 0 && PublicPriceFeed::planKey((string)($prod[\'name\'] ?? \'\')) === $key) $cands[] = $prod;', 'sections' => 'B', 'pattern' => '/B1 /'],
    ['label' => 'MB2 a product kept off still offered', 'file' => 'lib/UcrmQuoteLines.php',
     'old' => '                if (isset($off[$pid])) {
                    $notOffered[]',
     'new' => '                if (false) {
                    $notOffered[]', 'sections' => 'B', 'pattern' => '/B2 /'],
    ['label' => 'MB3 the installation product also offered as hardware', 'file' => 'lib/UcrmQuoteLines.php',
     'old' => 'if ($pid <= 0 || isset($planProducts[$pid]) || ($installId !== null && $pid === $installId)) continue;',
     'new' => 'if ($pid <= 0 || isset($planProducts[$pid])) continue;', 'sections' => 'B', 'pattern' => '/B[03] /'],
    ['label' => 'MB4 a product spelled like a service plan offered as hardware', 'file' => 'lib/UcrmQuoteLines.php',
     'old' => '        if ($this->likePlan($product)) return self::no("{$shown} is spelled like a service plan in uCRM, so it cannot be quoted as hardware", \'like_plan\');
        return self::yes(self::line($product, max(1, $qty), \'piece\'), null);',
     'new' => '        return self::yes(self::line($product, max(1, $qty), \'piece\'), null);', 'sections' => 'B', 'pattern' => '/B[23] /'],
    ['label' => 'MB5 the token blind to the price', 'file' => 'lib/UcrmQuoteLines.php',
     'old' => '        return substr(hash(\'sha256\', (int)($line[\'productId\'] ?? 0) . \'|\' . (string)($line[\'label\'] ?? \'\') . \'|\' . $cents',
     'new' => '        return substr(hash(\'sha256\', (int)($line[\'productId\'] ?? 0) . \'|\' . (string)($line[\'label\'] ?? \'\') . \'|\' . \'x\'', 'sections' => 'B', 'pattern' => '/B[34] /'],
    ['label' => 'MB6 the token comparison accepting a subset', 'file' => 'lib/UcrmQuoteLines.php',
     'old' => '        return $want !== [] && $seen === $want;
    }

    /** 5.18.103 (§G.6): the plan pair',
     'new' => '        return $want !== [] && array_diff($seen, $want) === [];
    }

    /** 5.18.103 (§G.6): the plan pair', 'sections' => 'B', 'pattern' => '/B4 /'],
    // MB7 removes both caps: the one on an entry alone is also enforced by the one on the merged quantity (an entry of 100
    // is over 99 merged too), so removing it alone is an equivalent copy — measured on 1 Nov, MB7 as first written escaped.
    ['label' => 'MB7 the cart\'s quantity not capped', 'edits' => [
        ['lib/UcrmQuoteLines.php', 'if (!is_int($p) || $p <= 0 || $p > 999999999 || !is_int($q) || $q < 1 || $q > self::MAX_QTY) return null;',
                                   'if (!is_int($p) || $p <= 0 || $p > 999999999 || !is_int($q) || $q < 1) return null;'],
        ['lib/UcrmQuoteLines.php', '            if ($qty[$p] > self::MAX_QTY) return null;', '']], 'sections' => 'B', 'pattern' => '/B4 /'],
    ['label' => 'MB7b a product listed twice, merged over 99, accepted', 'file' => 'lib/UcrmQuoteLines.php',
     'old' => '            if ($qty[$p] > self::MAX_QTY) return null;', 'new' => '', 'sections' => 'B', 'pattern' => '/B4 /'],
    ['label' => 'MB8 stored direct lines confirmed without their price', 'file' => 'lib/UcrmQuoteLines.php',
     'old' => '            if (abs(round((float)$current[$pid][\'price\'], 2) - round((float)($l[\'price\'] ?? 0), 2)) > 0.0001) return $repriced($labels[$pid] ?? \'An item\');',
     'new' => '', 'sections' => 'B', 'pattern' => '/B5 /'],
    ['label' => 'MB9 a direct sale priced without saying who installs', 'file' => 'lib/UcrmQuoteLines.php',
     'old' => '                if ($by !== \'dishnet\' && $by !== \'customer\') return self::directNo($out, self::NO_INSTALL_ANSWER, \'no_install_answer\');',
     'new' => '', 'sections' => 'B', 'pattern' => '/B3 /'],
    ['label' => 'MB10 a standard quotation priced without an item that is no longer offered', 'file' => 'lib/UcrmQuoteLines.php',
     'old' => '            if ($hit === null) return $no(\'a hardware item in it (uCRM product #\' . (int)($h[\'p\'] ?? 0) . \') is not offered now — see the uCRM catalogue screen\');',
     'new' => '            if ($hit === null) continue;', 'sections' => 'B', 'pattern' => '/B6 /'],
    ['label' => 'MB11 the page cache shape left at 5.18.102\'s', 'file' => 'lib/UcrmQuoteLines.php',
     'old' => '    public const CACHE_SHAPE  = \'d103-1\';',
     'new' => '    public const CACHE_SHAPE  = \'d14-1\';', 'sections' => 'B', 'pattern' => '/B7 /'],
    ['label' => 'MB12 a sale priced directly whatever its entry', 'file' => 'lib/UcrmQuoteLines.php',
     'old' => '        if ($entry !== self::DIRECT_ENTRY) return self::directNo($out, self::ENTRY_REASON, \'entry\');',
     'new' => '', 'sections' => 'B', 'pattern' => '/B3 /'],
]);
