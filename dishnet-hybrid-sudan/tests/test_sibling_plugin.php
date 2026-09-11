<?php
declare(strict_types=1);
/**
 * test_sibling_plugin.php — reading another plugin's data honestly.
 *
 * Two other plugins hold data this one needs, and neither is in this
 * repository. Twenty-two call sites reached into them by hand:
 *
 *     foreach ([dirname(__DIR__, 3) . '/dishnet-data-report/data/x.json',
 *               dirname(__DIR__, 2) . '/../dishnet-data-report/data/x.json'] as $p) {
 *         if (file_exists($p)) { ... }
 *     }
 *     $total = 0;
 *
 * Not one of them logged anything, so "the plugin was renamed", "its format
 * changed" and "there are genuinely no routers" all produced the same
 * screen: a zero, rendered confidently.
 *
 * And every one of them looked in <plugin>/data — which is the directory
 * uCRM DELETES when a plugin is upgraded. This plugin lost its own database
 * to that repeatedly before anyone connected the two events, which is why
 * getDataDir() moved to the .<plugin>-data sibling that survives. If the
 * other plugins made the same move, those reads have been finding nothing
 * ever since and reporting zero about it.
 *
 * The distinction these assertions exist to protect: null means COULD NOT
 * READ and [] means READ IT, IT IS EMPTY. Collapsing those is the bug.
 */
require_once dirname(__DIR__) . '/lib/SiblingPlugin.php';

$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; } }

// A plugins directory, laid out the way uCRM lays one out.
$base = sys_get_temp_dir() . '/dn_sib_' . bin2hex(random_bytes(4));
@mkdir($base . '/dishnet-hybrid-sudan', 0777, true);
$GLOBALS['_PLUGIN_ROOT'] = $base . '/dishnet-hybrid-sudan';
SiblingPlugin::reset();

t('the plugins directory is the plugin root\'s parent', SiblingPlugin::pluginsDir(), $base);

// ── Nothing installed ───────────────────────────────────────────────────────
echo "\nA sibling that is not there\n";
is_(SiblingPlugin::readJson('dishnet-data-report', 'wifi_router_map.json') === null,
    'reads as null — NOT as an empty dataset');
is_(SiblingPlugin::dataDir('dishnet-data-report') === null, 'it has no data directory');
is_(SiblingPlugin::installed('dishnet-data-report') === false, 'and is not installed');
$m = SiblingPlugin::misses();
is_(isset($m['dishnet-data-report/wifi_router_map.json']), 'the failure is recorded');
is_(strpos($m['dishnet-data-report/wifi_router_map.json']['why'], 'not installed') !== false,
    'saying the plugin is not installed');
// The collapse to [] must be something a call site asks for, not the default.
t('readJsonOrEmpty is the caller opting in to an empty array',
  SiblingPlugin::readJsonOrEmpty('dishnet-data-report', 'wifi_router_map.json'), []);

// ── Installed, legacy data directory ────────────────────────────────────────
echo "\nA sibling with data in the directory uCRM deletes on upgrade\n";
@mkdir($base . '/dishnet-data-report/data', 0777, true);
file_put_contents($base . '/dishnet-data-report/data/wifi_router_map.json',
                  json_encode(['R1' => ['kit_serial' => 'KIT-001']]));
SiblingPlugin::reset();
$d = SiblingPlugin::readJson('dishnet-data-report', 'wifi_router_map.json');
is_(is_array($d) && isset($d['R1']), 'it is still found there — an un-upgraded plugin still works');
$survey = SiblingPlugin::survey(['dishnet-data-report']);
is_($survey[0]['legacy'] === true,
    'and is flagged legacy, because the next upgrade of that plugin deletes it');

// ── The surviving directory wins ────────────────────────────────────────────
echo "\nThe directory that survives an upgrade is preferred\n";
@mkdir($base . '/.dishnet-data-report-data', 0777, true);
file_put_contents($base . '/.dishnet-data-report-data/wifi_router_map.json',
                  json_encode(['R2' => ['kit_serial' => 'KIT-002']]));
SiblingPlugin::reset();
$d = SiblingPlugin::readJson('dishnet-data-report', 'wifi_router_map.json');
is_(isset($d['R2']) && !isset($d['R1']),
    'the .<plugin>-data copy is the one read, not the one inside the plugin');
is_(SiblingPlugin::survey(['dishnet-data-report'])[0]['legacy'] === false,
    'and it is no longer flagged legacy');

// ── A file that is there but unusable ───────────────────────────────────────
echo "\nBroken data is not empty data\n";
file_put_contents($base . '/.dishnet-data-report-data/broken.json', '{not json at all');
SiblingPlugin::reset();
is_(SiblingPlugin::readJson('dishnet-data-report', 'broken.json') === null, 'invalid JSON reads as null');
$why = SiblingPlugin::misses()['dishnet-data-report/broken.json']['why'] ?? '';
is_(strpos($why, 'not valid JSON') !== false, 'and says so', $why);

file_put_contents($base . '/.dishnet-data-report-data/empty.json', '[]');
SiblingPlugin::reset();
t('a genuinely empty file reads as an empty array, not null',
  SiblingPlugin::readJson('dishnet-data-report', 'empty.json'), []);
is_(SiblingPlugin::misses() === [], 'and is not recorded as a failure — it worked');

// ── Preference order across plugins ─────────────────────────────────────────
echo "\nTwo plugins hold the same file\n";
@mkdir($base . '/.dishnet-starlink-finance-data', 0777, true);
file_put_contents($base . '/.dishnet-starlink-finance-data/sl_kits.json',
                  json_encode([['kit_number' => 'FROM-FINANCE']]));
file_put_contents($base . '/.dishnet-data-report-data/sl_kits.json',
                  json_encode([['kit_number' => 'FROM-REPORT']]));
SiblingPlugin::reset();
$k = SiblingPlugin::readJsonFromAny(['dishnet-starlink-finance', 'dishnet-data-report'], 'sl_kits.json');
t('the caller\'s first preference wins', $k[0]['kit_number'], 'FROM-FINANCE');
SiblingPlugin::reset();
$k = SiblingPlugin::readJsonFromAny(['dishnet-data-report', 'dishnet-starlink-finance'], 'sl_kits.json');
t('and the other order gives the other one', $k[0]['kit_number'], 'FROM-REPORT');

// An installed-but-never-synced plugin leaves an empty file behind. Stopping
// there would hide the plugin that actually has the data.
file_put_contents($base . '/.dishnet-data-report-data/sl_kits.json', '[]');
SiblingPlugin::reset();
$k = SiblingPlugin::readJsonFromAny(['dishnet-data-report', 'dishnet-starlink-finance'], 'sl_kits.json');
t('an empty file falls through to the next plugin', $k[0]['kit_number'], 'FROM-FINANCE');

// ── Paths, for callers that need one ────────────────────────────────────────
echo "\nResolving a path rather than reading it\n";
SiblingPlugin::reset();
$p = SiblingPlugin::path('dishnet-data-report', 'wifi_router_map.json');
is_(is_string($p) && is_file($p), 'a path comes back for a file that exists');
is_(strpos((string)$p, '/.dishnet-data-report-data/') !== false,
    'pointing at the surviving directory');
is_(SiblingPlugin::path('dishnet-data-report', 'nope.json') === null, 'and null for one that does not');
// The migrated call sites feed the result straight into file_exists(), and
// PHP 8.1 deprecates null there — on a page that runs every request that is
// a notice per request per file. '' is falsy and raises nothing.
t('pathOrEmpty gives a string a legacy call site can use',
  SiblingPlugin::pathOrEmpty('dishnet-data-report', 'nope.json'), '');
is_(file_exists(SiblingPlugin::pathOrEmpty('dishnet-data-report', 'nope.json')) === false,
    'and file_exists() on it is false without a deprecation');
is_(SiblingPlugin::pathOrEmpty('dishnet-data-report', 'wifi_router_map.json') !== '',
    'while a file that exists still comes back');

// ── It cannot be pointed out of the data directory ──────────────────────────
echo "\nIt stays inside the other plugin's data directory\n";
file_put_contents($base . '/secret.json', json_encode(['do' => 'not read me']));
SiblingPlugin::reset();
is_(SiblingPlugin::readJson('dishnet-data-report', '../../secret.json') === null,
    'a traversing file name is refused');
is_(SiblingPlugin::path('dishnet-data-report', '../../secret.json') === null,
    'and so is a traversing path lookup');
is_(SiblingPlugin::dataDir('../..') === null, 'and a traversing plugin name');

// ── One log line per failure, not one per loop iteration ────────────────────
echo "\nA repeated failure is counted, not repeated\n";
SiblingPlugin::reset();
for ($i = 0; $i < 5; $i++) SiblingPlugin::readJson('dishnet-data-report', 'missing.json');
t('five reads, one recorded miss', count(SiblingPlugin::misses()), 1);
t('with the count on it', SiblingPlugin::misses()['dishnet-data-report/missing.json']['count'], 5);

// ── No call site builds its own cross-plugin path any more ──────────────────
echo "\nNothing builds these paths by hand any more\n";
$offenders = [];
foreach (['lib', 'includes', 'tabs', 'cron', 'tools'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/' . $dir));
    foreach ($it as $f) {
        if (!$f->isFile() || substr($f->getFilename(), -4) !== '.php') continue;
        if ($f->getFilename() === 'SiblingPlugin.php') continue;
        foreach (file($f->getPathname()) as $n => $line) {
            // The directory that gets deleted on upgrade, spelled out in code.
            if (preg_match("#dishnet-(?:data-report|starlink-finance)/data/#", $line)
                && strpos(ltrim($line), '//') !== 0 && strpos(ltrim($line), '*') !== 0) {
                $offenders[] = $f->getFilename() . ':' . ($n + 1) . ' ' . trim($line);
            }
        }
    }
}
is_($offenders === [],
    'no file hardcodes <plugin>/data — the directory uCRM deletes on upgrade',
    implode("\n       ", array_slice($offenders, 0, 8)));

// A path assigned to a variable and then given to file_exists() must be the
// string variant. null there is a deprecation notice on every request.
$unsafe = [];
foreach (['lib', 'includes', 'tabs', 'cron', 'tools'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/' . $dir));
    foreach ($it as $f) {
        if (!$f->isFile() || substr($f->getFilename(), -4) !== '.php') continue;
        if ($f->getFilename() === 'SiblingPlugin.php') continue;
        $lines = file($f->getPathname());
        foreach ($lines as $n => $line) {
            if (!preg_match('/(\$\w+)\s*=\s*SiblingPlugin::path\(/', $line, $mm)) continue;
            $var = preg_quote($mm[1], '/');
            $ahead = implode('', array_slice($lines, $n + 1, 6));
            if (preg_match('/(is_file|file_exists|filemtime|file_get_contents)\s*\(\s*' . $var . '\b/', $ahead)
                && !preg_match('/' . $var . '\s*(!==|===)\s*null/', $ahead)) {
                $unsafe[] = $f->getFilename() . ':' . ($n + 1) . ' ' . trim($line);
            }
        }
    }
}
is_($unsafe === [],
    'no resolved path reaches file_exists() as a possible null',
    implode("\n       ", array_slice($unsafe, 0, 6)));

exec('rm -rf ' . escapeshellarg($base));
echo "\n  {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
