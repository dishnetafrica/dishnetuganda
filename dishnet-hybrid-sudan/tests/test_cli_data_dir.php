<?php
/**
 * test_cli_data_dir.php — a tool must not read the wrong database.
 *
 * getDataDir() must never fail: the plugin has to start even when the plugins
 * root is not writable. Its LAST RESORT is a directory inside the plugin,
 * which uCRM deletes on upgrade and which an existing install does not use.
 *
 * On the live Uganda server, running a tool as www-data hit exactly that
 * fallback. The lucky outcome was a PDO stack trace. The unlucky one is a
 * writable fallback: the tool creates an EMPTY database, finds no hardware,
 * and reports "nothing to adopt" — a price tool declaring success against a
 * database it made up seconds earlier.
 *
 * The narrowness matters as much as the check. A first attempt at this
 * refused ANY directory with no database, which broke every first run and
 * every test fixture, and — worse — offered to point a WRITE tool at a
 * database it had found by globbing. Trusting DN_DATA_DIR and refusing only
 * the genuine fallback is the whole design.
 */
declare(strict_types=1);

$root = dirname(__DIR__);

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   $m\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

$tmp = sys_get_temp_dir() . '/dn_cdd_' . bin2hex(random_bytes(4));
$plug = $tmp . '/plugins/dishnet-hybrid-sudan';
@mkdir($plug . '/data', 0777, true);
@mkdir($tmp . '/plugins/.dishnet-hybrid-sudan-data', 0777, true);
// mktemp gives 0700; the unprivileged runs below must be able to descend.
@chmod($tmp, 0755);
@chmod($tmp . '/plugins', 0755);
@chmod($plug, 0755);
@chmod($plug . '/data', 0755);
@chmod($tmp . '/plugins/.dishnet-hybrid-sudan-data', 0755);

// Run cliDataDir in its own process: it exits, which a test cannot survive.
//
// $asUser matters. The fallback only happens to a user who cannot write the
// plugins root, and root can write anything — is_writable() returns true for
// root even on mode 0555. That asymmetry IS the bug: the same command worked
// as root and failed as www-data. So the refusal cases run as an unprivileged
// user, which is the only way to reach the code path they are about.
$asUser = trim((string)shell_exec('id -u')) === '0' ? 'nobody' : '';
$call = function (string $dataDir, string $pluginRoot, array $args = [], bool $drop = false)
        use ($root, $asUser) {
    $code = 'require ' . var_export($root . '/lib/bootstrap_data.php', true) . ';'
          . '$GLOBALS["argv"] = ' . var_export(array_merge(['tools/hardware_diff.php'], $args), true) . ';'
          . 'echo "DIR:" . cliDataDir(' . var_export($pluginRoot, true) . ');';
    $inner = ($dataDir !== '' ? 'DN_DATA_DIR=' . escapeshellarg($dataDir) . ' ' : '')
           . 'php -r ' . escapeshellarg($code);
    $cmd = ($drop && $asUser !== '')
         ? 'su -s /bin/sh ' . $asUser . ' -c ' . escapeshellarg($inner)
         : $inner;
    return (string)shell_exec($cmd . ' 2>&1; echo "EXIT:$?"');
};

echo "\nDN_DATA_DIR is trusted exactly as given\n";

// A directory named on the command line is a deliberate aim. Tests, first
// runs and one-off inspections all rely on it, and none of them have a
// database yet.
$empty = $tmp . '/somewhere-new';
@mkdir($empty, 0777, true);
$named = $call($empty, $plug);
is_(strpos($named, 'DIR:' . $empty) !== false,
    'an empty directory named explicitly is returned',
    'refusing this breaks every first run and every test fixture');
is_(strpos($named, 'EXIT:0') !== false, 'and nothing is refused');

file_put_contents($plug . '/data/plugin.sqlite3', 'x');
$good = $call($plug . '/data', $plug);
is_(strpos($good, 'DIR:' . $plug . '/data') !== false, 'and so is one with a database');
unlink($plug . '/data/plugin.sqlite3');

echo "\nThe fallback with no database is the one case refused\n";

// 1.2 MB so the size is legible in the output, the way a real one would be.
file_put_contents($tmp . '/plugins/.dishnet-hybrid-sudan-data/plugin.sqlite3', str_repeat('x', 1228800));
// Reach the fallback the way the server does: the plugins root unwritable,
// so getDataDir() lands inside the plugin, where there is no database.
@chmod($tmp . '/plugins', 0555);
$out = $call('', $plug, ['--adopt-ucrm', '--only', 'Starlink Standard Kit'], true);

is_(strpos($out, 'THIS IS NOT WHERE THE DATA LIVES') !== false,
    'it refuses rather than carrying on',
    'this is the case that silently reports an empty system as fact');
is_(strpos($out, 'EXIT:2') !== false, 'with a non-zero status', 'a script must be able to tell this failed');
is_(strpos($out, 'DIR:') === false, 'and returns nothing to the caller');
is_(strpos($out, $plug . '/data') !== false, 'it says where it would have looked');
is_(strpos($out, 'is not writable by this user') !== false,
    'and why it ended up there',
    'the operator needs to know it is a permissions problem, not a missing file');
is_(strpos($out, $tmp . '/plugins/.dishnet-hybrid-sudan-data') !== false,
    'and names the directory that does hold a database',
    'without this the operator is left guessing at a path');
is_(strpos($out, '1,200 KB') !== false,
    'with its size, which is how you tell the live one from a stray',
    'a 4 KB file and a 1.2 MB file are not the same finding');

// The re-run command must carry the ORIGINAL arguments. An operator who has
// to retype --only "Starlink Standard Kit" will retype it differently.
is_(strpos($out, 'DN_DATA_DIR=' . $tmp . '/plugins/.dishnet-hybrid-sudan-data') !== false,
    'it prints a command to re-run');
is_(strpos($out, "'--adopt-ucrm'") !== false && strpos($out, "'Starlink Standard Kit'") !== false,
    'carrying the arguments that were actually used',
    'retyping a scoped write by hand is how the wrong row gets changed');

// Never silently. Choosing for the operator is how a write lands in the
// wrong database.
is_(stripos($out, 'not chosen automatically') !== false,
    'and it is explicit that it did not choose for you');

echo "\nNothing is created on the way past\n";
is_(!is_file($plug . '/data/plugin.sqlite3'),
    'no empty database is left behind',
    'an invented database makes every later tool report an empty system as fact');

echo "\nWith nothing anywhere, it says so plainly\n";
@chmod($tmp . '/plugins', 0755);
unlink($tmp . '/plugins/.dishnet-hybrid-sudan-data/plugin.sqlite3');
@chmod($tmp . '/plugins', 0555);
$none = $call('', $plug, [], true);
is_(strpos($none, 'No database was found beside the plugin') !== false, 'it reports that too');
is_(strpos($none, 'EXIT:2') !== false, 'and still fails');
is_(strpos($none, 'DN_DATA_DIR=') === false,
    'without offering a command that would not work',
    'a suggested fix that cannot succeed wastes the next attempt');

echo "\nEvery CLI tool resolves through it\n";

$tools = glob($root . '/tools/*.php') ?: [];
$legacy = [];
foreach ($tools as $t) {
    $src = (string)file_get_contents($t);
    if (strpos($src, "getenv('DN_DATA_DIR') ?: getDataDir(") !== false) $legacy[] = basename($t);
}
is_($legacy === [],
    'no tool still resolves the data directory by hand',
    'left behind: ' . implode(', ', $legacy));

$viaGuard = 0;
foreach ($tools as $t) {
    if (strpos((string)file_get_contents($t), 'cliDataDir(') !== false) $viaGuard++;
}
is_($viaGuard >= 46, $viaGuard . ' tools go through the guard');

@chmod($tmp . '/plugins', 0755);
exec('rm -rf ' . escapeshellarg($tmp));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
