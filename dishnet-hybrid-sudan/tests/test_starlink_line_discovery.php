<?php
declare(strict_types=1);
/**
 * test_starlink_line_discovery.php — asking Starlink which kit is on which line.
 *
 * `tools/dr_kit_map.php --paste` exported exactly ONE pair for Uganda: fifteen
 * service lines in the cache, one pairing, because we only know a pairing where
 * somebody recorded both halves at the install. The other fourteen were going
 * to be typed by hand, and that is a chore for a machine.
 *
 * Starlink's own service-line listing nests each line's terminal inside the
 * line. The pairing is already in the payload. What this pins is that we read
 * it by STRUCTURE rather than by field name — the smallest subtree holding one
 * SL-… and a KIT-… is the pair — so a renamed field cannot silently empty the
 * map, and that a line with two terminals is handed to a person rather than
 * guessed at.
 */
require_once dirname(__DIR__) . '/lib/StarlinkLineDiscovery.php';

$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; } }

echo "\n── PAIRING BY STRUCTURE ───────────────────────────────────────────\n";

/** The webagg shape: results, each line carrying its own terminals. */
$listing = ['content' => ['results' => [
    ['serviceLineNumber' => 'SL-DF-16046613-35504-0',
     'accountNumber'     => 'ACC-DF-15973474-59163-60',
     'active'            => true,
     'userTerminals'     => [['kitSerialNumber' => 'KIT404246364BX6',
                              'terminalId' => 'ut01301694-01e07c1c-59d52912']]],
    ['serviceLineNumber' => 'SL-DF-16046614-35505-1',
     'accountNumber'     => 'ACC-DF-15973474-59163-60',
     'active'            => true,
     'userTerminals'     => [['kitSerialNumber' => 'KIT303053206']]],
]]];

$r = StarlinkLineDiscovery::pairsFrom($listing);
t('both lines are paired with their own terminal', $r['pairs'],
  ['KIT404246364BX6' => 'SL-DF-16046613-35504-0',
   'KIT303053206'    => 'SL-DF-16046614-35505-1']);
t('and both lines are reported as seen', count($r['lines']), 2);
t('nothing is ambiguous', $r['ambiguous'], []);

// The whole point: we never named a field, so renaming them changes nothing.
$renamed = ['data' => ['items' => [
    ['sl_num' => 'SL-DF-16046613-35504-0',
     'terminals' => [['serial' => 'KIT404246364BX6']]],
]]];
t('a renamed field pairs exactly the same, because no name is read',
  StarlinkLineDiscovery::pairsFrom($renamed)['pairs'],
  ['KIT404246364BX6' => 'SL-DF-16046613-35504-0']);

// A flat record, terminal alongside rather than nested.
t('a flat record works too',
  StarlinkLineDiscovery::pairsFrom([['line' => 'SL-1-2-3', 'kit' => 'KIT999888777']])['pairs'],
  ['KIT999888777' => 'SL-1-2-3']);

echo "\n── WHAT IT REFUSES TO GUESS ───────────────────────────────────────\n";

// A swapped dish: two terminals, and the payload does not say which is fitted.
$swapped = ['content' => ['results' => [
    ['serviceLineNumber' => 'SL-DF-16046613-35504-0',
     'userTerminals' => [['kitSerialNumber' => 'KIT404246364BX6'],
                         ['kitSerialNumber' => 'KIT303053206']]],
]]];
$r = StarlinkLineDiscovery::pairsFrom($swapped);
t('two terminals on one line is not paired', $r['pairs'], []);
t('it is handed to a person, with both candidates',
  $r['ambiguous'], ['SL-DF-16046613-35504-0' => ['KIT303053206', 'KIT404246364BX6']]);

// Uganda's actual situation: no terminal registered yet.
$pending = ['content' => ['results' => [
    ['serviceLineNumber' => 'SL-DF-16046613-35504-0',
     'pendingActivation' => true, 'hasTelemetryAccess' => false,
     'userTerminals' => []],
]]];
$r = StarlinkLineDiscovery::pairsFrom($pending);
t('a line with no terminal yields no pair', $r['pairs'], []);
t('but the line is still reported as seen', $r['lines'], ['SL-DF-16046613-35504-0']);
is_(($r['notes']['SL-DF-16046613-35504-0']['pendingActivation'] ?? null) === true,
    'and the reason is carried back — pendingActivation, not a bug in our reading');

// The sort of thing that would quietly corrupt a bill.
$noisy = ['accountNumber' => 'ACC-DF-1-2-3',
          'results' => [['serviceLineNumber' => 'SL-A-1-1', 'userTerminals' => [['kit' => 'KITAAA111']]],
                        ['serviceLineNumber' => 'SL-B-2-2', 'userTerminals' => [['kit' => 'KITBBB222']]]]];
t('many lines at one level never cross-pair',
  StarlinkLineDiscovery::pairsFrom($noisy)['pairs'],
  ['KITAAA111' => 'SL-A-1-1', 'KITBBB222' => 'SL-B-2-2']);

t('an empty payload is empty, not an error', StarlinkLineDiscovery::pairsFrom([])['pairs'], []);
t('a payload with no identifiers at all is empty too',
  StarlinkLineDiscovery::pairsFrom(['content' => ['results' => [['foo' => 'bar']]]])['pairs'], []);

echo "\n── STORING ────────────────────────────────────────────────────────\n";

$dir = sys_get_temp_dir() . '/dn_disc_' . bin2hex(random_bytes(4));
@mkdir($dir, 0777, true);

t('nothing stored reads as empty', StarlinkLineDiscovery::load($dir), []);
$w = StarlinkLineDiscovery::save($dir, ['KIT404246364BX6' => 'SL-DF-16046613-35504-0']);
is_(!empty($w['ok']), 'a discovery is stored');
t('and reads back', StarlinkLineDiscovery::load($dir),
  ['KIT404246364BX6' => 'SL-DF-16046613-35504-0']);
is_(StarlinkLineDiscovery::discoveredAt($dir) !== '', 'with when it was asked');

// One dead session must not erase a fleet's worth of good pairings.
$w = StarlinkLineDiscovery::save($dir, []);
is_(empty($w['ok']), 'an empty discovery is refused over a populated file');
t('and the stored pairs survive', count(StarlinkLineDiscovery::load($dir)), 1);

echo "\n── THE TWO LAYERS ─────────────────────────────────────────────────\n";

require_once dirname(__DIR__) . '/lib/KitSlMap.php';
$mdir = sys_get_temp_dir() . '/dn_two_' . bin2hex(random_bytes(4));
@mkdir($mdir, 0777, true);
$map = new KitSlMap($mdir);
$map->replace("KIT303053206=SL-TYPED-1-1");
$map->overlay(['KIT303053206' => 'SL-STARLINK-9-9', 'KIT404246364BX6' => 'SL-DF-16046613-35504-0']);

t('what a person typed wins over what Starlink reported',
  $map->lineFor('KIT303053206'), 'SL-TYPED-1-1');
t('and Starlink fills what nobody typed',
  $map->lineFor('KIT404246364BX6'), 'SL-DF-16046613-35504-0');
t('the source of each is knowable', [$map->sourceFor('KIT303053206'),
                                     $map->sourceFor('KIT404246364BX6'),
                                     $map->sourceFor('KITNOTHING')],
  ['typed', 'discovered', '']);
t('the box still shows only what a person typed', $map->asText(), 'KIT303053206=SL-TYPED-1-1');

$g = $map->apply([
    ['kit_serial' => 'KIT303053206',    'starlink_service_line' => '', 'starlink_account' => 'ACC-1-2-3'],
    ['kit_serial' => 'KIT404246364BX6', 'starlink_service_line' => '', 'starlink_account' => 'ACC-1-2-3'],
]);
t('both get filled', $g['filled_lines'], 2);
t('counted by which layer answered', [$g['filled_typed'], $g['filled_discovered']], [1, 1]);
t('and the disagreement between the layers is surfaced, not settled quietly',
  $g['conflicts'], [['kit' => 'KIT303053206', 'typed' => 'SL-TYPED-1-1',
                     'discovered' => 'SL-STARLINK-9-9']]);

echo "\n── WIRED IN ───────────────────────────────────────────────────────\n";

$cron = (string)file_get_contents(dirname(__DIR__) . '/cron/starlink_usage.php');
is_(strpos($cron, 'StarlinkLineDiscovery') !== false, 'the hourly collector asks Starlink itself');
is_(strpos($cron, '->overlay(') !== false, 'and lays the answer under the typed map');
is_(strpos($cron, 'left for a person rather than guessed') !== false,
    'and logs an ambiguous line rather than picking one');
$exits = 0;
foreach (token_get_all($cron) as $tok) { if (is_array($tok) && $tok[0] === T_EXIT) $exits++; }
t('still returns rather than exiting — master.php includes it', $exits, 0);

$tab = (string)file_get_contents(dirname(__DIR__) . '/tabs/admin/starlink_session.php');
is_(strpos($tab, 'value="discover"') !== false, 'the admin page has a button to ask now');
is_(preg_match('/ss_action.{0,40}===\s*\'discover\'/s', $tab) === 1, 'behind its own action');
is_(substr_count($tab, 'csrfCheck()') >= 5, 'CSRF-checked like every other action here');
is_(strpos($tab, 'pendingActivation') !== false,
    'and explains the one thing asking cannot fix');

$tool = (string)file_get_contents(dirname(__DIR__) . '/tools/usage_collect.php');
is_(strpos($tool, '--discover') !== false, 'the tool can ask on demand');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
