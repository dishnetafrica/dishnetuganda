<?php
declare(strict_types=1);
/**
 * test_cashbook_seeds.php — the cashbook offers names from THIS country.
 *
 * When a category has little real history, the person picker falls back to a
 * seed list compiled from South Sudan's BookKeeper: Juba branch sites, JEDCO
 * power, NRA tax, and airtime bought from Zain and VivaCell — neither of which
 * operates in Uganda, where the second network is Airtel.
 *
 * Nothing was ever rejected, which is why this went unnoticed: the list only
 * suggests. But on a fresh install there is no history at all, so the wrong
 * names are the ONLY names a clerk is offered while posting real money.
 *
 * The defaults must not move — the South Sudan install reads the same file.
 * So the test that matters most is the first one: unconfigured, the map is
 * byte-for-byte what it always was.
 */
$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; } }

require_once dirname(__DIR__) . '/lib/CashbookSeeds.php';

echo "\nAn install that configures nothing is untouched\n";
$d = CashbookSeeds::map([]);
is_($d === CashbookSeeds::DEFAULTS, 'no config returns the defaults unchanged');
is_(CashbookSeeds::map(null) === CashbookSeeds::DEFAULTS, 'a null config does too');
is_(count($d) === 34, 'all 34 categories survive', 'got ' . count($d));
is_(($d['Airtime'] ?? []) === ['MTN', 'Zain', 'VivaCell'],
    'the South Sudan airtime list is still the default');
is_(($d['Site Power'] ?? [])[0] === 'JEDCO', 'and Juba site power');
is_(in_array('NRA Audit', $d['Tax'] ?? [], true), 'and South Sudan tax');

echo "\nA category can be replaced\n";
$u = CashbookSeeds::map(['cashbook_seeds' => ['Airtime' => ['MTN', 'Airtel']]]);
is_(($u['Airtime'] ?? []) === ['MTN', 'Airtel'], 'Uganda gets MTN and Airtel');
is_(!in_array('Zain', $u['Airtime'], true) && !in_array('VivaCell', $u['Airtime'], true),
    'and the two networks that do not exist here are gone');
is_(count($u) === 34, 'replacing one category leaves the rest alone');
is_(($u['Tax'] ?? []) === ($d['Tax'] ?? []), 'an untouched category is identical');

echo "\nA category can be silenced, which is the honest state for staff lists\n";
$s = CashbookSeeds::map(['cashbook_seeds' => ['Salary' => [], 'Site Rent' => []]]);
is_(($s['Salary'] ?? null) === [], 'an empty array means offer nothing');
is_(array_key_exists('Salary', $s), 'the category still exists, it just has no suggestions');
is_(($s['Site Rent'] ?? null) === [], 'and again for sites');
is_(($s['Airtime'] ?? []) === ['MTN', 'Zain', 'VivaCell'], 'others keep their defaults');

echo "\nA category can be added\n";
$a = CashbookSeeds::map(['cashbook_seeds' => ['URA Levy' => ['URA']]]);
is_(($a['URA Levy'] ?? []) === ['URA'], 'a new category appears');
is_(count($a) === 35, 'alongside the existing 34');

echo "\nConfig arrives as JSON, so it may be anything\n";
is_(CashbookSeeds::map(['cashbook_seeds' => '{"Airtime":["MTN","Airtel"]}'])['Airtime']
    === ['MTN', 'Airtel'], 'a JSON string is decoded');
foreach ([
    'not json at all'                  => 'unparseable text',
    ''                                 => 'an empty string',
    '[1,2,3]'                          => 'a JSON list rather than an object',
] as $bad => $what) {
    is_(CashbookSeeds::map(['cashbook_seeds' => $bad]) === CashbookSeeds::DEFAULTS,
        "$what leaves the defaults standing");
}
is_(CashbookSeeds::map(['cashbook_seeds' => 42]) === CashbookSeeds::DEFAULTS,
    'a number leaves the defaults standing');
is_(CashbookSeeds::map(['cashbook_seeds' => ['Airtime' => 'MTN']]) === CashbookSeeds::DEFAULTS,
    'a category whose value is not a list is ignored, not flattened to characters');

echo "\nNames are cleaned without being invented\n";
$c = CashbookSeeds::map(['cashbook_seeds' => ['Airtime' => ['  MTN  ', '', 'Airtel', 'Airtel', null, ['x']]]]);
is_($c['Airtime'] === ['MTN', 'Airtel'],
    'trimmed, de-duplicated, blanks and non-scalars dropped',
    json_encode($c['Airtime']));

echo "\nThe site picker is the same story, in a second list\n";
is_(CashbookSeeds::sites([]) === CashbookSeeds::DEFAULT_SITES, 'unset returns the Juba defaults');
is_(count(CashbookSeeds::DEFAULT_SITES) === 25, 'all 25 sites survive');
is_(in_array('Konyo Konyo / Yatco', CashbookSeeds::DEFAULT_SITES, true), 'including the Juba markets');
is_(CashbookSeeds::sites(['cashbook_sites' => ['Kampala Office', 'Ntinda']])
    === ['Kampala Office', 'Ntinda'], 'an override replaces the list wholesale');
is_(CashbookSeeds::sites(['cashbook_sites' => []]) === [],
    'an empty list starts blank and fills from real history');
is_(CashbookSeeds::sites(['cashbook_sites' => '["A","B"]']) === ['A', 'B'], 'a JSON string works');
is_(CashbookSeeds::sites(['cashbook_sites' => 'garbage']) === CashbookSeeds::DEFAULT_SITES,
    'and garbage leaves the defaults standing');
is_(CashbookSeeds::sites(['cashbook_sites' => [' A ', 'A', '', null, 'B']]) === ['A', 'B'],
    'names are trimmed and de-duplicated');

echo "\nThe tab reads the helper rather than its own copy\n";
$tab = file_get_contents(dirname(__DIR__) . '/tabs/accounts/cashbook.php');
is_(strpos($tab, 'CashbookSeeds::map') !== false, 'cashbook.php calls CashbookSeeds::map');
is_(strpos($tab, "'Site Power'     => ['JEDCO'") === false,
    'and no longer carries the South Sudan array inline');
is_(substr_count($tab, 'VivaCell') === 0, 'VivaCell is gone from the tab');
is_(strpos($tab, 'CashbookSeeds::sites') !== false, 'and the site picker calls CashbookSeeds::sites');
is_(strpos($tab, "'Konyo Konyo / Yatco'") === false, 'with no inline Juba site list left');
is_(substr_count($tab, 'JEDCO') === 0, 'JEDCO is gone from the tab');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
