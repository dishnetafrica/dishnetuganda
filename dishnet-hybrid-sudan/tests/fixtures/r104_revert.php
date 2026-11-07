<?php
declare(strict_types=1);
/**
 * TEST ONLY — 5.18.104 (root docs/73 §H.2.14 "Tests re-pointed on purpose, never weakened"; coordinator.md items 8, 10a):
 * 5.18.103's text of the shared files 5.18.104 adds lines to, recovered from this release's by taking out exactly
 * 5.18.104's declared lines (each found exactly once, or null) — includes/navigation.php (Part F's my_quotes block and
 * Part O's wa_offers block), public.php (four lines), includes/post_handlers.php (one require line) and
 * tests/test_bank_store.php (its "091 last" re-point). For the tests that hold those files to an earlier release's bytes
 * through tests/fixtures/r103_revert.php — tests/test_bank_boundaries.php A and F, tests/test_cashbook_physical_list.php M
 * — which apply this first: r103_revert($rel, r104_revert($rel, $src)). The 5.18.104 lines themselves are held by
 * tests/test_quote_followup.php N (declared, marked, measured against 221c9dc from Git). Generated from Git and checked
 * there: 221c9dc's bytes come back exactly.
 */
const R104_REVERT = array (
  'includes/navigation.php' => 
  array (
    0 => 
    array (
      0 => '<?php /* 5.18.104 (root docs/73 §H.2.11.5): My quotations, drawn only while QuoteDailyList::pageOpen() — Uganda and the daily list
on. With the list\'s key absent (South Sudan, and Uganda until the list is switched on) nothing is loaded and nothing is drawn. */
$_mq104Nav = false;
if (!empty($config[\'quote_daily_list_enabled\'])) {
    require_once dirname(__DIR__) . \'/lib/QuoteDailyList.php\';
    $_mq104Nav = QuoteDailyList::pageOpen(is_array($config ?? null) ? $config : [], isset($dataDir) ? (string)$dataDir : null);
}
if ($_mq104Nav): ?>
<a href="?page=dashboard&tab=my_quotes" class="kyc-tab <?= $tab===\'my_quotes\'?\'active\':\'\' ?>">
    <span class="nav-icon"><i class="bi bi-clipboard-check"></i></span> My quotations
</a>
<?php endif; ?>
',
      1 => '',
    ),
    1 => 
    array (
      0 => '<?php /* 5.18.104 (root docs/73 §H.4.7): WhatsApp offers, Uganda only, drawn only while OfferSwitch::screen() — Uganda and
offers_test_enabled on. With the key absent (South Sudan, and Uganda until offers-test is switched on) nothing is loaded and
nothing is drawn. */
$_wo104Nav = false;
if (!empty($config[\'offers_test_enabled\'])) {
    require_once dirname(__DIR__) . \'/lib/OfferSwitch.php\';
    $_wo104Nav = OfferSwitch::screen(is_array($config ?? null) ? $config : [], isset($dataDir) ? (string)$dataDir : null);
}
if ($_wo104Nav): ?>
<a href="?page=dashboard&tab=wa_offers" class="kyc-tab <?= $tab===\'wa_offers\'?\'active\':\'\' ?>">
    <span class="nav-icon"><i class="bi bi-megaphone" style="color:#7C3AED;"></i></span> WhatsApp offers
</a>
<?php endif; ?>
',
      1 => '',
    ),
  ),
  'public.php' => 
  array (
    0 => 
    array (
      0 => '    \'my_quotes\'          => \'tabs/sales/my_quotes.php\',        // 5.18.104 (root docs/73 §H.2.11.5): My quotations; the page checks its gate itself
',
      1 => '',
    ),
    1 => 
    array (
      0 => '    \'wa_offers\'            => \'tabs/engage/wa_offers.php\',   // 5.18.104 (root docs/73 §H.4.7): WhatsApp offers, Uganda only; the tab guards itself
',
      1 => '',
    ),
    2 => 
    array (
      0 => '    \'wa_offers\'            => \'*admin\',   // 5.18.104 (root docs/73 §H.4.7): admins only, so the Staff permission matrix is unchanged
',
      1 => '',
    ),
    3 => 
    array (
      0 => '            \'my_quotes\'=>\'My quotations\',         // 5.18.104 (root docs/73 §H.2.11.5)
',
      1 => '',
    ),
  ),
  'includes/post_handlers.php' => 
  array (
    0 => 
    array (
      0 => 'require __DIR__ . \'/post/post_offers.php\';         // 5.18.104 (root docs/73 §H.4.7): the Offers screen\'s seven actions. OfferSwitch, the fresh admin, CSRF and own-page inside.
',
      1 => '',
    ),
  ),
  'tests/test_bank_store.php' => 
  array (
    0 => 
    array (
      0 => '// 5.18.104 (root docs/73 §H.2.14; coordinator items 2, 9b, 10c): re-pointed on purpose, never weakened — every migration
// applied, by name, 088 among them and 5.18.104\'s 091 the last (089-091 are 5.18.104\'s, held by quote_followup N)
$wantMig = array_map(\'basename\', glob($root . \'/migrations/*.sql\') ?: []); sort($wantMig);
t(\'SqliteStore::create() applies every migration, 088 among them, 091 last\', [$applied, in_array(\'088_bank_statements.sql\', $applied, true), (string)end($applied)],
    [$wantMig, true, \'091_wa_offers.sql\']);
',
      1 => 't(\'SqliteStore::create() applies every migration, 088 last\', [count($applied), (string)end($applied)],
    [count(glob($root . \'/migrations/*.sql\') ?: []), \'088_bank_statements.sql\']);
',
    ),
  ),
);

/** 5.18.103's text of $rel from this release's $src; $src itself for any other file; null when a declared change is not found exactly once. */
function r104_revert(string $rel, ?string $src): ?string
{
    if ($src === null) return null;
    if (!isset(R104_REVERT[$rel])) return $src;
    foreach (R104_REVERT[$rel] as $pair) {
        if (substr_count($src, $pair[0]) !== 1) return null;
        $src = str_replace($pair[0], $pair[1], $src);
    }
    return $src;
}
