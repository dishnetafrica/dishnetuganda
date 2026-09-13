<?php
declare(strict_types=1);
/**
 * test_kit_intake_screen.php — the review screen, and the boundaries it holds.
 *
 * The danger with a screen like this is not that it renders wrong. It is that
 * it quietly becomes a second way to assign a kit — one that skips the
 * validation the CLI path enforces, or that acts on a stale page, or that
 * treats a deleted text field as an instruction to release equipment.
 *
 * These assert the boundaries, not the styling.
 */
$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; } }

$root   = dirname(__DIR__);
$page   = (string)file_get_contents($root . '/tabs/admin/kit_intake.php');
$lib    = (string)file_get_contents($root . '/lib/KitAttributeIntake.php');
$pub    = (string)file_get_contents($root . '/public.php');
$nav    = (string)file_get_contents($root . '/includes/navigation.php');
$master = (string)file_get_contents($root . '/cron/master.php');

echo "\nIt is a review screen, not a background mechanism\n";
// The whole architecture rests on this: nothing binds because time passed.
is_(strpos($master, 'kit_intake') === false,
    'no cron entry schedules it');
is_(strpos($master, 'KitAttributeIntake') === false,
    'and no cron uses the intake library at all');
$crons = glob($root . '/cron/*.php') ?: [];
$auto  = [];
foreach ($crons as $c) {
    if (strpos((string)file_get_contents($c), 'KitAttributeIntake') !== false) $auto[] = basename($c);
}
t('no scheduled script touches it', $auto, []);

echo "\nThere is exactly one verb, and it only ever creates\n";
is_(substr_count($page, "'ki_action'") >= 1, 'one action name');
is_(preg_match("/\\\$_POST\\['ki_action'\\]\s*\?\?\s*''\s*\)\s*===\s*'bind'/", $page) === 1,
    'and it is "bind"');
is_(strpos($page, '->release(') === false,        'the screen cannot release an assignment');
is_(strpos($page, 'replaceUnit') === false,       'nor replace a unit');
is_(preg_match('/UPDATE\s+equipment_assignments/i', $page) === 0,
    'and it never writes the assignment table directly');
is_(strpos($page, '$kiIntake->apply(') !== false,
    'the only write goes through KitAttributeIntake::apply()');
is_(strpos($lib, '$this->ea->assign(') !== false,
    'which goes through EquipmentAssignment::assign — the same door assign_kit.php uses');

echo "\nA stale page cannot bind something that has changed\n";
// Somebody leaves the screen open; the kit gets assigned elsewhere; they press
// Bind. The POST is a request, not an instruction.
is_(preg_match('/\$fresh\s*=\s*\$kiIntake->scan\(\);/', $page) === 1,
    'the POST re-scans before acting');
is_(preg_match("/foreach \(\\\$fresh\['proposals'\] as \\\$p\)/", $page) === 1,
    'and looks for the posted row in the FRESH proposals');
is_(strpos($page, 'the page was out of date') !== false,
    'a row that is no longer proposable is refused, and says why');
is_(preg_match("/ki_unit|unit_id'\]\s*=\s*\(int\)\(\\\$_POST/", $page) === 0,
    'no unit id is taken from the request — it is re-derived server-side');

echo "\nDeleting or editing the field still cannot move a kit\n";
// The screen exposes no path to it, because the library has none.
is_(strpos($lib, "'action' => 'assign'") !== false, 'the library proposes only assignments');
foreach (['held_elsewhere', 'service_taken', 'not_in_stock'] as $r) {
    is_(strpos($lib, "'reason' => '" . $r . "'") !== false, "and refuses with $r");
}
is_(strpos($page, 'cannot release a kit or move it') !== false,
    'and the screen states the boundary where an operator will read it');

echo "\nEvery refusal gets words and a corrective action\n";
foreach (['held_elsewhere', 'not_in_stock', 'service_taken',
          'service_missing', 'service_differs'] as $reason) {
    is_(preg_match("/case '" . $reason . "': return \[/", $page) === 1,
        "$reason is explained");
}
is_(strpos($page, 'What it means:') !== false, 'each says what it means');
is_(strpos($page, 'What to do:') !== false,    'and what to do about it');
is_(strpos($page, 'Receive the kit on') !== false,
    'not_in_stock points at the Stock screen rather than at nothing');

echo "\nThe three states are all present, with the fields asked for\n";
is_(strpos($page, 'Already bound · agreement') !== false, 'agreement');
is_(strpos($page, 'Ready to bind') !== false,             'ready to bind');
is_(strpos($page, '>Refused<') !== false,                 'refused');
foreach (['Customer', 'Customer ID', 'Service', 'Service ID', 'Assignment ID'] as $f) {
    is_(strpos($page, '<label>' . $f . '</label>') !== false, "shows $f");
}
is_(strpos($page, 'Would create:') !== false,
    'and a proposal states exactly what would be created');

echo "\nAdmin only, CSRF-checked, escaped, and reachable\n";
is_(strpos($page, "\$retailer['is_admin']") !== false, 'the page guards itself');
is_(strpos($pub, "'kit_intake'           => '*admin'") !== false, 'the permission map agrees');
is_(strpos($pub, "'kit_intake'       => 'tabs/admin/kit_intake.php'") !== false, 'the route exists');
is_(strpos($pub, "'id'=>'kit_intake'") !== false, 'it is listed for admins');
is_(strpos($nav, 'tab=kit_intake') !== false, 'and a person can reach it from the sidebar');
is_(strpos($page, 'csrfCheck()') !== false, 'the bind checks a token');
is_(strpos($page, 'csrfField()') !== false, 'and the form carries one');
is_(preg_match("/REQUEST_METHOD'\] \?\? 'GET'\) === 'POST'/", $page) === 1, 'POST only');
is_(strpos($page, 'confirm(') !== false, 'and binding asks first');

// Same linter as the DPO screen: every dynamic echo escaped or cast.
preg_match_all('/<\?=\s*(.+?)\s*\?>/s', $page, $mm);
$raw = [];
foreach ($mm[1] as $e) {
    $e = trim($e);
    if ($e === '') continue;
    if (preg_match('/^\$h\(|^\(int\)|^count\(|^urlencode\(/', $e)) continue;
    if (preg_match("/^'[^\\$]*'$/", $e)) continue;
    $raw[] = $e;
}
t('nothing dynamic reaches HTML unescaped', $raw, []);

echo "\nThe Starlink collector is untouched by this increment\n";
is_(strpos($page, 'StarlinkUsage') === false && strpos($page, 'StarlinkSessionStore') === false,
    'the screen never opens a Starlink session');
is_(strpos($lib, 'Starlink') === false || strpos($lib, 'StarlinkUsage') === false,
    'and neither does the library');
is_(strpos($page, 'SiblingPlugin') === false
    && preg_match('#[\'"][^\'"]*/(plugins|_plugins)/#', $page) === 0
    && preg_match('#dishnet-(data-report|starlink-finance)/#', $page) === 0,
    'and it never reads or writes another plugin\'s files');

echo "\nAn unreachable uCRM does not read as \"nothing to do\"\n";
// Three empty sections would tell an operator everything is fine when in fact
// nothing was read. Empty must never stand in for unknown.
require_once $root . '/lib/KitAttributeIntake.php';
require_once $root . '/lib/EquipmentAssignment.php';
final class DeafCrm { public function get(string $p) { return null; }
                      public function isConfigured(): bool { return true; } }
$db = new PDO('sqlite::memory:');
$db->exec('CREATE TABLE equipment_assignments (id INTEGER PRIMARY KEY, crm_client_id INT,
           crm_service_id INT, kit_serial TEXT, released_at TEXT)');
$db->exec('CREATE TABLE stock_units (id INTEGER PRIMARY KEY, serial_number TEXT)');
$deaf = (new KitAttributeIntake($db, new EquipmentAssignment($db), new DeafCrm()))->scan();
is_(!$deaf['reachable'], 'a silent uCRM is reported as unreachable');
t('and nothing is claimed to have been read', $deaf['services'], 0);
is_(strpos($page, 'uCRM did not answer') !== false,
    'the screen says so rather than showing three empty sections');
is_(strpos($page, 'mean <em>unknown</em>') !== false,
    'and spells out that empty means unknown here');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
