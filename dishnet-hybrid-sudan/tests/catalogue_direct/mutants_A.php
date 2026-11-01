<?php
declare(strict_types=1);
/** Weakened copies for section A of tests/test_catalogue_direct.php (5.18.103, root docs/72 §G.13): each a one-fault copy
 * of the plugin that section must catch. Appended to $QFU_MUTANTS by the runner. TEST ONLY. */
$QFU_MUTANTS = array_merge($QFU_MUTANTS, [
    ['label' => 'MA1 turning on without checking the judged installation is the one in force', 'file' => 'lib/UcrmCatalogue.php',
     'old' => 'if ($on && ($judgedInstall === null || $now->installProductId() !== $judgedInstall)) {',
     'new' => 'if (false) {', 'sections' => 'A', 'pattern' => '/A2 /'],
    ['label' => 'MA2 the switch read on from any truthy value', 'file' => 'lib/UcrmCatalogue.php',
     'old' => 'return $m !== null && ($m[\'on\'] ?? null) === true;',
     'new' => 'return $m !== null && !empty($m[\'on\']);', 'sections' => 'A', 'pattern' => '/A3 /'],
    ['label' => 'MA3 the first of two switch records in force read as the switch', 'file' => 'lib/UcrmCatalogue.php',
     'old' => '        $m = $this->inForce(\'mode\');
        return count($m) === 1 ? $m[0] : null;',
     'new' => '        $m = $this->inForce(\'mode\');
        return $m !== [] ? $m[0] : null;', 'sections' => 'A', 'pattern' => '/A3 /'],
    ['label' => 'MA4 a write that does not re-read the gate inside the lock', 'file' => 'lib/UcrmCatalogue.php',
     'old' => '            if (!self::gateOpen(QuoteOrganization::setting($config, $dataDir))) {',
     'new' => '            if (false) {', 'sections' => 'A', 'pattern' => '/A[16] /'],
    ['label' => 'MA5 fromStore opening the store with the gate closed', 'file' => 'lib/UcrmCatalogue.php',
     'old' => '        if (!self::gateOpen($setting)) return new self([], false);
        // 5.18.103: a read never creates the table',
     'new' => '        // 5.18.103: a read never creates the table', 'sections' => 'A', 'pattern' => '/A1 /'],
    ['label' => 'MA6 a standard quotation\'s quantity not capped at 99', 'file' => 'lib/UcrmCatalogue.php',
     'old' => '$h[\'q\'] < 1 || $h[\'q\'] > self::MAX_QTY) {',
     'new' => '$h[\'q\'] < 1) {', 'sections' => 'A', 'pattern' => '/A5 /'],
    ['label' => 'MA7 no limit of ten standard quotations', 'file' => 'lib/UcrmCatalogue.php',
     'old' => 'if (count($now->standards()) >= self::MAX_STANDARDS) {',
     'new' => 'if (false) {', 'sections' => 'A', 'pattern' => '/A5 /'],
    ['label' => 'MA8 the installation product set though a standard quotation lists it as hardware', 'file' => 'lib/UcrmCatalogue.php',
     'old' => '                    if ((int)$h[\'p\'] === $productId) {',
     'new' => '                    if (false) {', 'sections' => 'A', 'pattern' => '/A5 /'],
    ['label' => 'MA9 names stored as given (not plain text)', 'file' => 'lib/UcrmCatalogue.php',
     'old' => '        $t = preg_replace(\'/[\\x00-\\x1F\\x7F<>&"]/u\', \'\', $t) ?? \'\';',
     'new' => '        $t = (string)$t;', 'sections' => 'A', 'pattern' => '/A[45] /'],
    ['label' => 'MA10 a read that creates the table', 'file' => 'lib/UcrmCatalogue.php',
     'old' => '                if ((int)$has === 0) return new self([], true);',
     'new' => '                if (false) return new self([], true);', 'sections' => 'A', 'pattern' => '/A7 /'],
]);
