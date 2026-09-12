<?php
declare(strict_types=1);
/**
 * test_timezone.php — one clock, named by configuration, and named correctly.
 *
 * The zone was the literal 'Africa/Juba' in 44 places. The comment above the
 * staff manual explained that this was harmless, because "Uganda shares UTC+3
 * with South Sudan, so every clock in the system is correct for Kampala —
 * only the identifier is inherited."
 *
 * That was false, and had been false since 31 January 2021, when South Sudan
 * left East Africa Time. Africa/Juba is CAT, UTC+2. Africa/Kampala is EAT,
 * UTC+3. Every one of those 44 sites was running an hour behind Kampala: cron
 * windows, invoice ageing, the cashbook day boundary, the "days overdue" a
 * customer is chased on.
 *
 * The first test below is the one that matters. It asserts the offsets are
 * DIFFERENT, from the tz database rather than from anybody's memory. Had it
 * existed, the comment claiming otherwise could never have been written.
 */
$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; } }

$root = dirname(__DIR__);
require_once $root . '/lib/timezone.php';

$dir = sys_get_temp_dir() . '/dn_tz_' . getmypid();
@mkdir($dir, 0700, true);
putenv('DN_DATA_DIR=' . $dir);
$cfgFile = $dir . '/kyc_config.json';
$setCfg = function (array $c) use ($cfgFile) { file_put_contents($cfgFile, json_encode($c)); dn_tz_reset(); };

echo "\nThe premise that made this a bug\n";
$off = function (string $z): int {
    return (new DateTime('2026-09-12 12:00:00', new DateTimeZone('UTC')))
         ->setTimezone(new DateTimeZone($z))->getOffset();
};
is_($off('Africa/Juba') !== $off('Africa/Kampala'),
    'Juba and Kampala are NOT the same offset',
    'if this ever fails, the tz database changed — re-read the whole file');
is_($off('Africa/Juba')    === 2 * 3600, 'Africa/Juba is UTC+2 (CAT)');
is_($off('Africa/Kampala') === 3 * 3600, 'Africa/Kampala is UTC+3 (EAT)');
is_($off('Africa/Kampala') - $off('Africa/Juba') === 3600,
    'the gap is exactly one hour — the size of the error that was shipped');

echo "\nAn install that configures nothing is untouched\n";
@unlink($cfgFile); dn_tz_reset();
is_(dn_tz() === 'Africa/Juba', 'no config file at all still means Africa/Juba');
$setCfg([]);
is_(dn_tz() === 'Africa/Juba', 'a config with no timezone key means Africa/Juba');
$setCfg(['timezone' => '']);
is_(dn_tz() === 'Africa/Juba', 'an empty timezone means Africa/Juba');
is_(dn_tz_obj()->getName() === 'Africa/Juba', 'dn_tz_obj() agrees');

echo "\nUganda names its own clock\n";
$setCfg(['timezone' => 'Africa/Kampala']);
is_(dn_tz() === 'Africa/Kampala', 'config is honoured');
is_(dn_tz_obj()->getName() === 'Africa/Kampala', 'dn_tz_obj() follows it');
dn_tz_apply();
is_(date_default_timezone_get() === 'Africa/Kampala', 'dn_tz_apply() sets the process');
is_(dn_tz(['timezone' => 'Africa/Nairobi']) === 'Africa/Nairobi',
    'an explicitly passed config wins over the file');

echo "\nA bad value degrades to the old behaviour, never to a crash\n";
$setCfg(['timezone' => 'Africa/Kampla']);           // the plausible typo
is_(dn_tz() === 'Africa/Juba', 'a misspelt zone falls back rather than throwing');
$setCfg(['timezone' => 'Mars/Olympus']);
is_(dn_tz() === 'Africa/Juba', 'so does a zone that does not exist');
$setCfg(['timezone' => 12345]);
is_(dn_tz() === 'Africa/Juba', 'so does a non-string');
file_put_contents($cfgFile, '{ not json at all'); dn_tz_reset();
is_(dn_tz() === 'Africa/Juba', 'so does an unparseable config file');
is_(dn_tz_valid('Africa/Kampala') && !dn_tz_valid('Nope/Nope') && !dn_tz_valid(''),
    'dn_tz_valid() separates real zones from typos');

echo "\nThe label states what the zone MEANS, not just its name\n";
is_(dn_tz_label(['timezone' => 'Africa/Kampala']) === 'Africa/Kampala — EAT (UTC+3)',
    'Kampala renders with its true offset');
is_(dn_tz_label(['timezone' => 'Africa/Juba']) === 'Africa/Juba — CAT (UTC+2)',
    'Juba renders with its true offset — the fact the old comment denied');
is_(strpos(dn_tz_label(['timezone' => 'Mars/Olympus']), 'Juba') !== false,
    'an invalid zone labels what is actually running');

echo "\nThe cache can be cleared, or a long-running worker never sees a change\n";
$setCfg(['timezone' => 'Africa/Kampala']);
is_(dn_tz() === 'Africa/Kampala', 'reads the new value after a reset');
file_put_contents($cfgFile, json_encode(['timezone' => 'Africa/Nairobi']));
is_(dn_tz() === 'Africa/Kampala', 'and caches it until told otherwise');
dn_tz_reset();
is_(dn_tz() === 'Africa/Nairobi', 'dn_tz_reset() genuinely clears BOTH caches',
    'a reset that only cleared one would pass the first read and fail here');

echo "\nNo production file hardcodes a zone any more\n";
// Test files are exempt BY DESIGN: pinning a zone is how they work.
// test_message_timestamps.php runs the whole file under Africa/Khartoum on
// purpose, because +2 is the condition the UTC-storage bug needed to appear.
// A test that followed the install's configuration would stop being a test.
$skip = ['/lib/timezone.php', '/vendor/', '/node_modules/'];
$hits = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = $f->getPathname();
    if (substr($p, -4) !== '.php') continue;
    foreach ($skip as $s) if (strpos($p, $s) !== false) continue 2;
    if (strncmp(basename($p), 'test_', 5) === 0) continue;
    foreach (file($p) as $i => $line) {
        // Prose may name a zone; code may not. Skip comment lines.
        $t = ltrim($line);
        if ($t === '' || $t[0] === '*' || strncmp($t, '//', 2) === 0 || strncmp($t, '/*', 2) === 0) continue;
        if (preg_match("#'(?:Africa|Europe|America|Asia|Australia|Pacific)/[A-Za-z_]+'#", $line, $m)) {
            $hits[] = basename($p) . ':' . ($i + 1) . '  ' . trim($line);
        }
    }
}
// FollowUpPolicy::TZ is the documented Uganda value the design doc names; it
// is a constant, not a behaviour — the window itself reads dn_tz().
$hits = array_values(array_filter($hits, function ($h) {
    return strpos($h, 'public const TZ') === false;
}));
is_($hits === [], 'no executable line pins a timezone identifier',
    $hits ? implode("\n       ", $hits) : '');

echo "\nThe follow-up window follows the install, not a literal\n";
$src = file_get_contents($root . '/lib/FollowUpPolicy.php');
is_(strpos($src, 'new \DateTimeZone(self::TZ)') === false,
    'withinSendingWindow no longer builds the zone from the constant');
is_(strpos($src, 'dn_tz_obj()') !== false, 'it uses dn_tz_obj()');
is_((bool)preg_match('/31 January 2021|31 Jan 2021/', $src),
    'and the comment records why the two zones differ');

@unlink($cfgFile); @rmdir($dir);
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
