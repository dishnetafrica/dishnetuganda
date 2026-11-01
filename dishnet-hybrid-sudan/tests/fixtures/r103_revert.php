<?php
declare(strict_types=1);
/**
 * TEST ONLY — 5.18.103 (root docs/72 §G.13, rewritten on purpose): 5.18.102's text of the two shared files 5.18.103 adds
 * lines to, includes/navigation.php and public.php, recovered from this release's by taking out exactly 5.18.103's
 * declared lines (each found exactly once, or null). For the tests that hold those files to an earlier release's bytes —
 * tests/test_bank_boundaries.php A and tests/test_cashbook_physical_list.php M — which keep holding every other byte of
 * them; and, the same way, of tests/test_cash_in_hand_p3b_south_sudan.php, which 5.18.103 rewrites on purpose (it takes
 * the uCRM catalogue's menu entry out of its pages and counts it), for test_cashbook_physical_list.php M's keep-list.
 * The 5.18.103 lines themselves are held by tests/test_catalogue_direct.php I (declared, marked, measured against
 * 1a0ea5f from Git). Generated from Git and checked there: 1a0ea5f's bytes come back exactly.
 */
const R103_REVERT = [
    'includes/navigation.php' => [['<?php /* 5.18.103 (Uganda, root docs/72 §G.3): with the gate on, the uCRM catalogue for those who may see Plans or Hardware;
with its switch on, Plans and Hardware are not drawn (their screens show a card). The gate closed: nothing here changes. */
require_once dirname(__DIR__) . \'/lib/UcrmCatalogue.php\';
$_cat103Nav = UcrmCatalogue::forConfig($store, is_array($config ?? null) ? $config : [], isset($dataDir) ? (string)$dataDir : null); ?>
<?php if($can(\'plans\') && !$_cat103Nav->direct()): ?>
<a href="?page=dashboard&tab=subscription_plans" class="kyc-tab <?= $tab===\'subscription_plans\'?\'active\':\'\' ?>">
    <span class="nav-icon"><i class="bi bi-layers-fill"></i></span> Service Plans
</a>
<?php endif; ?>
<?php if($can(\'hardware\') && !$_cat103Nav->direct()): ?>
<a href="?page=dashboard&tab=hardware" class="kyc-tab <?= $tab===\'hardware\'?\'active\':\'\' ?>">
    <span class="nav-icon"><i class="bi bi-router-fill"></i></span> Hardware
</a>
<?php endif; ?>
<?php if($_cat103Nav->open() && ($can(\'plans\') || $can(\'hardware\'))): /* 5.18.103 (§G.3) */ ?>
<a href="?page=dashboard&tab=ucrm_catalogue" class="kyc-tab <?= $tab===\'ucrm_catalogue\'?\'active\':\'\' ?>">
    <span class="nav-icon"><i class="bi bi-box-seam"></i></span> uCRM catalogue
</a>
<?php endif; ?>
',
                                   '<?php if($can(\'plans\')): ?>
<a href="?page=dashboard&tab=subscription_plans" class="kyc-tab <?= $tab===\'subscription_plans\'?\'active\':\'\' ?>">
    <span class="nav-icon"><i class="bi bi-layers-fill"></i></span> Service Plans
</a>
<?php endif; ?>
<?php if($can(\'hardware\')): ?>
<a href="?page=dashboard&tab=hardware" class="kyc-tab <?= $tab===\'hardware\'?\'active\':\'\' ?>">
    <span class="nav-icon"><i class="bi bi-router-fill"></i></span> Hardware
</a>
<?php endif; ?>
']],
    'public.php'              => [['    \'ucrm_catalogue\'     => \'tabs/sales/ucrm_catalogue.php\',   // 5.18.103 (root docs/72 §G.3): Uganda\'s uCRM catalogue
', ''], ['    \'ucrm_catalogue\'     => [\'plans\', \'hardware\'],   // 5.18.103 (§G.3): who may see Plans or Hardware (admins by default)
', ''], ['            \'ucrm_catalogue\'=>\'uCRM catalogue\',   // 5.18.103 (root docs/72 §G.3)
', '']],
    // 5.18.103 (§G.13): the test that holds the Cashbook, landing and CEO pages to 5.18.94's bytes — its seven pieces
    'tests/test_cash_in_hand_p3b_south_sudan.php' => [
        [' * out of the comparison and checked against fixed text. A difference is reported by its position and length only.
 *
 * One block is taken out of the pages and counted instead (5.18.103, rewritten on purpose, root docs/72 §G.13): the uCRM
 * catalogue\'s menu entry, which includes/navigation.php draws on every dashboard page, for those who may see Plans or
 * Hardware, wherever the quoting organization is in force, whatever the catalogue\'s switch says (§G.3) — of these books,
 * the Uganda one. It is the exact text T3_CATALOGUE_NAV, taken out of this tree\'s pages only: each must carry it once
 * where this tree has lib/UcrmCatalogue.php and the book is UP, and never otherwise; f4b4c96\'s never. Two weakened copies
 * of navigation.php, the entry drawn on every tenant and the entry not drawn, show the count is not idle.
',
         ' * out of the comparison and checked against fixed text. A difference is reported by its position and length only.
'],
        ['const T3_STDERR     = "\\n\\0 the error stream \\0\\n";
// 5.18.103 (root docs/72 §G.3, §G.13): the uCRM catalogue\'s menu entry, exactly as includes/navigation.php draws it on a
// page other than the catalogue\'s own — the one block of these pages that changes where the quoting organization is in force.
const T3_CATALOGUE_NAV = "<a href=\\"?page=dashboard&tab=ucrm_catalogue\\" class=\\"kyc-tab \\">\\n"
                       . "    <span class=\\"nav-icon\\"><i class=\\"bi bi-box-seam\\"></i></span> uCRM catalogue\\n</a>\\n";
',
         'const T3_STDERR     = "\\n\\0 the error stream \\0\\n";
'],
        ['
        $outside = []; $sameStmt = 0; $navSeen = [];
',
         '
        $outside = []; $sameStmt = 0;
'],
        ['            [$a, $b] = t3_same_statement($a, $b, $old, $new, $sameStmt);
            if ($page) {   // 5.18.103: the uCRM catalogue\'s menu entry, out of this tree\'s page only, and counted (header)
                $navSeen[$lbl] = [substr_count($a, T3_CATALOGUE_NAV), substr_count($b, T3_CATALOGUE_NAV)];
                $b = str_replace(T3_CATALOGUE_NAV, \'\', $b);
            }
',
         '            [$a, $b] = t3_same_statement($a, $b, $old, $new, $sameStmt);
'],
        ['        }

        // 5.18.103: the uCRM catalogue\'s menu entry, counted where it was taken out (header)
        $navWant = ($tag === \'UP\' && is_file($root . \'/lib/UcrmCatalogue.php\')) ? 1 : 0;
        $navOff  = array_filter($navSeen, function ($p) use ($navWant) { return $p !== [0, $navWant]; });
        is_(count($navSeen) >= 10 && $navOff === [],
            "[{$tag}] the uCRM catalogue\'s menu entry (5.18.103), taken out of the comparison: " . ($navWant ? \'once on each\' : \'on none\')
            . \' of this tree\\\'s \' . count($navSeen) . " pages, on none of f4b4c96\'s", json_encode($navOff));
',
         '        }
'],
        ['    $CB = \'lib/CashbookService.php\'; $PAGE = \'tabs/accounts/cashbook.php\'; $CEO = \'tabs/admin/ceo_dashboard.php\'; $TOOL = \'tools/cash_in_hand.php\';
    $NAV = \'includes/navigation.php\';
    $NAVIF = "<?php if(\\$_cat103Nav->open() && (\\$can(\'plans\') || \\$can(\'hardware\'))): /* 5.18.103 (§G.3) */ ?>";
',
         '    $CB = \'lib/CashbookService.php\'; $PAGE = \'tabs/accounts/cashbook.php\'; $CEO = \'tabs/admin/ceo_dashboard.php\'; $TOOL = \'tools/cash_in_hand.php\';
'],
        ['            "if (true) {\\n    echo \\"\\\\n  the ledger\'s cash in hand"]]],
        // 5.18.103: the menu entry\'s count is not idle — drawn where the quoting organization is not in force, and not drawn
        [\'5.18.103: the uCRM catalogue\\\'s menu entry drawn on every tenant\', [\'SS\', \'SN\'], [[$NAV, $NAVIF,
            "<?php if(\\$can(\'plans\') || \\$can(\'hardware\')): /* 5.18.103 (§G.3) */ ?>"]]],
        [\'5.18.103: the uCRM catalogue\\\'s menu entry not drawn\', [\'UP\'], [[$NAV, $NAVIF, "<?php if(false): /* 5.18.103 (§G.3) */ ?>"]]],
',
         '            "if (true) {\\n    echo \\"\\\\n  the ledger\'s cash in hand"]]],
'],
    ],
];

/** 5.18.102's text of $rel from this release's $src; $src itself for any other file; null when a declared change is not found exactly once. */
function r103_revert(string $rel, string $src): ?string
{
    if (!isset(R103_REVERT[$rel])) return $src;
    foreach (R103_REVERT[$rel] as $pair) {
        if (substr_count($src, $pair[0]) !== 1) return null;
        $src = str_replace($pair[0], $pair[1], $src);
    }
    return $src;
}
