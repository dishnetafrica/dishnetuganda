<?php
declare(strict_types=1);
/**
 * test_kit_sl_map.php — the pairings a person types.
 *
 * The lines in here are real ones, taken from the map the South Sudan
 * installation runs on. That map is not decoration: its own sync log reports
 * "Source 3.5 (manual KIT→SL map): 295 gap-filled" out of 367 service lines,
 * so four fifths of a working fleet reach Starlink only because somebody typed
 * the pairing in. This file pins the two behaviours that make that safe —
 * a malformed line is refused rather than guessed at, and a line that
 * contradicts the install record loses to it and is reported.
 */
require_once dirname(__DIR__) . '/lib/SiblingPlugin.php';
require_once dirname(__DIR__) . '/lib/KitSlMap.php';

$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; } }

function mapDir(): string {
    $d = sys_get_temp_dir() . '/dn_map_' . bin2hex(random_bytes(4));
    @mkdir($d, 0777, true);
    return $d;
}

echo "\n── PARSING ────────────────────────────────────────────────────────\n";

// Verbatim from the South Sudan map.
$real = "KIT303053206=SL-DF-10670815-91149-8\n"
      . "KIT4M00805240MPS=SL-DF-9226335-70163-55\n"
      . "# a comment, and a blank line follow\n\n"
      . "  KIT4M03465049J8J = SL-DF-8603545-59125-61  \n";
$p = KitSlMap::parse($real);
t('three real pairs parse', count($p['pairs']), 3);
t('comments and blanks are not errors', $p['errors'], []);
t('surrounding whitespace is trimmed',
  $p['pairs']['KIT4M03465049J8J'] ?? '', 'SL-DF-8603545-59125-61');
t('read counts only the lines that carried something', $p['read'], 3);

$p = KitSlMap::parse("KIT303053206 SL-DF-10670815-91149-8");
t('a missing "=" is refused, not guessed', $p['pairs'], []);
is_(strpos($p['errors'][0] ?? '', 'no "="') !== false, 'and says what was missing');

// The one that matters: a pair that would file real usage under the wrong kit.
$p = KitSlMap::parse("KIT303053206=KIT303053207");
t('a kit on the service-line side is refused', $p['pairs'], []);
is_(strpos($p['errors'][0] ?? '', 'not a service line') !== false,
    'because that would put one customer\'s gigabytes on another\'s bill');

$p = KitSlMap::parse("SL-DF-1-2-3=SL-DF-4-5-6");
t('a service line on the kit side is refused too', $p['pairs'], []);

$p = KitSlMap::parse("KIT303053206=SL-DF-10670815-91149-8\nKIT303053206=SL-DF-9226335-70163-55");
t('a kit mapped twice keeps the first', $p['pairs']['KIT303053206'], 'SL-DF-10670815-91149-8');
is_($p['errors'] !== [], 'and the second is reported rather than dropped in silence');

$p = KitSlMap::parse("KIT303053206=SL-DF-1-2-3\nKIT4M00805240MPS=SL-DF-1-2-3");
t('one service line cannot belong to two kits', count($p['pairs']), 1);

echo "\n── STORING ────────────────────────────────────────────────────────\n";

$dir = mapDir();
$m   = new KitSlMap($dir);
t('an absent file is an empty map, not an error', $m->count(), 0);
t('and reads back as an empty box', $m->asText(), '');

$r = $m->replace($real);
is_(!empty($r['ok']), 'a paste is stored');
t('with the pairs that parsed', $r['stored'], 3);
is_(is_file($dir . '/' . KitSlMap::FILE), 'in our own data directory, which survives an upgrade');

$again = new KitSlMap($dir);
t('it survives a reload', $again->count(), 3);
t('and answers by kit', $again->lineFor('KIT303053206'), 'SL-DF-10670815-91149-8');
t('and by service line', $again->kitFor('SL-DF-10670815-91149-8'), 'KIT303053206');
t('an unmapped kit answers empty, not false', $again->lineFor('KIT000'), '');
is_(strpos($again->asText(), 'KIT303053206=SL-DF-10670815-91149-8') !== false,
    'and round-trips into the box in the same format it was pasted in');

// An empty box must not silently discard an afternoon's typing.
$r = $again->replace('');
is_(empty($r['ok']), 'an empty paste is refused');
t('and the stored map is untouched', (new KitSlMap($dir))->count(), 3);

$r = $again->replace("# nothing but a comment\n");
is_(empty($r['ok']), 'a paste that parses to nothing is refused too');
t('map still intact', (new KitSlMap($dir))->count(), 3);

// Saving replaces, so a removed line is really removed.
$m2 = new KitSlMap($dir);
$m2->replace("KIT303053206=SL-DF-10670815-91149-8");
t('saving replaces rather than merges', (new KitSlMap($dir))->count(), 1);

echo "\n── APPLYING ───────────────────────────────────────────────────────\n";

// A plugin tree with a data-report sibling whose cache knows the account.
$base    = sys_get_temp_dir() . '/dn_mapenv_' . bin2hex(random_bytes(4));
$plugins = $base . '/plugins';
@mkdir($plugins . '/dishnet-data-report/data', 0777, true);
@mkdir($plugins . '/dishnet-hybrid-sudan', 0777, true);
putenv('DN_PLUGIN_ROOT=' . $plugins . '/dishnet-hybrid-sudan');
SiblingPlugin::reset();
file_put_contents($plugins . '/dishnet-data-report/data/sl_svc_cache.json', json_encode([
    'SL-DF-16046613-35504-0' => ['service_line' => 'SL-DF-16046613-35504-0',
                                 'account_number' => 'ACC-DF-15973474-59163-60'],
]));

$dir2 = mapDir();
$map  = new KitSlMap($dir2);
$map->replace("KIT404246364BX6=SL-DF-16046613-35504-0\nKITNOTFITTED=SL-DF-1-2-3");

$assignments = [
    // bound at install, but nobody recorded the line or the account
    ['kit_serial' => 'KIT404246364BX6', 'starlink_service_line' => '', 'starlink_account' => ''],
];
$g = $map->apply($assignments, new StarlinkServiceState());
t('the missing service line is filled from the map', $g['filled_lines'], 1);
t('the missing account is filled from the service cache', $g['filled_accounts'], 1);
t('so the endpoint now has both halves it needs',
  [$g['assignments'][0]['starlink_service_line'], $g['assignments'][0]['starlink_account']],
  ['SL-DF-16046613-35504-0', 'ACC-DF-15973474-59163-60']);
t('a mapped kit nothing is fitted to is listed, because a typo looks like this',
  $g['unused'], ['KITNOTFITTED']);

// The install record wins.
$g = $map->apply([['kit_serial' => 'KIT404246364BX6',
                   'starlink_service_line' => 'SL-DF-99999999-00000-0',
                   'starlink_account'      => 'ACC-DF-15973474-59163-60']],
                 new StarlinkServiceState());
t('a recorded service line is NOT overwritten by the map',
  $g['assignments'][0]['starlink_service_line'], 'SL-DF-99999999-00000-0');
t('nothing was counted as filled', $g['filled_lines'], 0);
t('and the disagreement is reported so somebody can settle it',
  $g['disagreements'], [['kit' => 'KIT404246364BX6',
                         'assignment' => 'SL-DF-99999999-00000-0',
                         'map'        => 'SL-DF-16046613-35504-0']]);

// A recorded account is not second-guessed either.
$g = $map->apply([['kit_serial' => 'KIT404246364BX6', 'starlink_service_line' => '',
                   'starlink_account' => 'ACC-SOMETHING-ELSE']], new StarlinkServiceState());
t('a recorded account is left alone', $g['assignments'][0]['starlink_account'], 'ACC-SOMETHING-ELSE');
t('so only the line was filled', [$g['filled_lines'], $g['filled_accounts']], [1, 0]);

// An empty map must be a no-op, not a wipe.
$empty = new KitSlMap(mapDir());
$g = $empty->apply($assignments, new StarlinkServiceState());
t('an empty map changes nothing', $g['assignments'], $assignments);

echo "\n── WIRED IN ───────────────────────────────────────────────────────\n";

$cron = (string)file_get_contents(dirname(__DIR__) . '/cron/starlink_usage.php');
is_(strpos($cron, 'new KitSlMap(') !== false, 'the hourly collector applies the map');
is_(strpos($cron, "\$_su_gap['assignments']") !== false, 'and collects the gap-filled set');
is_(strpos($cron, 'keeping the install record') !== false,
    'and logs a disagreement rather than silently preferring one side');
// The word appears in the cron's own note ("never exit()"), so ask the parser
// rather than the text: an exit here would abort every cron master.php has
// scheduled after it in the same tick.
$exits = 0;
foreach (token_get_all($cron) as $tok) { if (is_array($tok) && $tok[0] === T_EXIT) $exits++; }
t('and still returns rather than exiting — master.php includes it', $exits, 0);

$tab = (string)file_get_contents(dirname(__DIR__) . '/tabs/admin/starlink_session.php');
is_(strpos($tab, "value=\"map\"") !== false, 'the admin page has a box to paste it into');
is_(strpos($tab, '$ssMap->asText()') !== false, 'prefilled with the current map, so an edit is an edit');
is_(strpos($tab, 'csrfCheck()') !== false, 'and the save is CSRF-checked like every other action here');
is_(preg_match('/ss_action.{0,40}===\s*\'map\'/s', $tab) === 1, 'behind its own action');

$tool = (string)file_get_contents(dirname(__DIR__) . '/tools/usage_collect.php');
is_(strpos($tool, 'new KitSlMap(') !== false,
    'and usage_collect.php shows the same set the cron will collect');

// The claim the admin page used to make, which the evidence overturned.
is_(strpos($tab, 'lasts minutes, and using it does not extend it') === false,
    'the overturned "a token lasts minutes" claim is gone from the page');
is_(strpos($tab, 'auto-refreshed') !== false,
    'replaced by what the working installation actually reports');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
