<?php
declare(strict_types=1);
/** Weakened copies for section F of tests/test_catalogue_direct.php (5.18.103, root docs/72 §G.13): each a one-fault copy
 * of the plugin that section must catch. Appended to $QFU_MUTANTS by the runner. TEST ONLY. */
$QFU_MUTANTS = array_merge($QFU_MUTANTS, [
    ['label' => 'MF1 a non-admin\'s action accepted (the fresh re-read skipped)', 'file' => 'includes/post/post_sync.php',
     'old' => '        if (!is_array($_c3Fresh) || $_c3MeId <= 0 || (int)($_c3Fresh[\'id\'] ?? 0) !== $_c3MeId
            || empty($_c3Fresh[\'is_active\']) || empty($_c3Fresh[\'is_admin\'])) {',
     'new' => '        if (!is_array($_c3Fresh) || $_c3MeId <= 0) {', 'sections' => 'F', 'pattern' => '/F1 /'],
    ['label' => 'MF2 the own-page check skipped', 'file' => 'includes/post/post_sync.php',
     'old' => '        if (!(csrfCheck() && !CustomerSession::crossSite() && BankGate::fromOwnPage(\'POST\'))) {
            flash(\'The security check failed, so nothing was changed. Open the screen again and retry.\', \'danger\');
            redirect($_c3Back);',
     'new' => '        if (false) {
            flash(\'The security check failed, so nothing was changed. Open the screen again and retry.\', \'danger\');
            redirect($_c3Back);', 'sections' => 'F', 'pattern' => '/F1 /'],
    ['label' => 'MF3 turned on with no installation product', 'file' => 'includes/post/post_sync.php',
     'old' => '                    if (empty($_c3Off[\'install\'][\'ok\'])) {',
     'new' => '                    if (false) {', 'sections' => 'F', 'pattern' => '/F2 /'],
    ['label' => 'MF4 turned on with no plan offered', 'file' => 'includes/post/post_sync.php',
     'old' => '                    if ($_c3Off[\'plans\'] === []) {',
     'new' => '                    if (false) {', 'sections' => 'F', 'pattern' => '/F2 /'],
    ['label' => 'MF5 turning off needs uCRM', 'file' => 'includes/post/post_sync.php',
     'old' => '                    $_c3R = UcrmCatalogue::setMode($store, false, null, $_c3Actor, $_c3Cfg, $_c3Dir);',
     'new' => '                    if (empty($_c3Offered()[\'ok\'])) { flash(\'uCRM could not be read.\', \'danger\'); redirect($_c3Back); }
                    $_c3R = UcrmCatalogue::setMode($store, false, null, $_c3Actor, $_c3Cfg, $_c3Dir);', 'sections' => 'F', 'pattern' => '/F2 /'],
    ['label' => 'MF6 a link save accepted while the switch is on', 'file' => 'includes/post/post_sync.php',
     'old' => '            flash(\'Nothing was linked: on Uganda, plans and hardware now come straight from uCRM (the uCRM catalogue).\', \'warning\');
            redirect(\'?page=dashboard&tab=ucrm_catalogue\');',
     'new' => '', 'sections' => 'F', 'pattern' => '/F4 /'],
    ['label' => 'MF7 deleting a plan row ends its links while the switch is on', 'file' => 'includes/post/post_sync.php',
     'old' => '        if (!UcrmCatalogue::forConfig($store, is_array($config ?? null) ? $config : [], isset($dataDir) ? (string)$dataDir : null)->direct()) try {
            $_dlEnded = UcrmQuoteLinks::end($store, \'plan\',',
     'new' => '        try {
            $_dlEnded = UcrmQuoteLinks::end($store, \'plan\',', 'sections' => 'F', 'pattern' => '/F4 /'],
    ['label' => 'MF8 the Plans screen drawn while the switch is on', 'file' => 'tabs/sales/subscription_plans.php',
     'old' => 'if (UcrmCatalogue::forConfig($store, is_array($config ?? null) ? $config : [], isset($dataDir) ? (string)$dataDir : null)->direct()) {',
     'new' => 'if (false) {', 'sections' => 'F', 'pattern' => '/F5 /'],
    ['label' => 'MF9 a standard quotation saved without being judged against uCRM', 'file' => 'includes/post/post_sync.php',
     'old' => '                if (empty($_c3Priced[\'ok\'])) {',
     'new' => '                if (false) {', 'sections' => 'F', 'pattern' => '/F3 /'],
    ['label' => 'MF10 the installation product saved without being judged', 'file' => 'includes/post/post_sync.php',
     'old' => '                if (empty($_c3Off[\'ok\']) || empty($_c3Off[\'install\'][\'ok\'])) {',
     'new' => '                if (false) {', 'sections' => 'F', 'pattern' => '/F3 /'],
]);
