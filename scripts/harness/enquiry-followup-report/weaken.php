<?php
declare(strict_types=1);
/**
 * weaken.php REPORT BUILD NOW — weakened copies of the report; test.php must catch every one.
 *
 * Each copy changes one thing (the anchor must occur exactly once, and the copy must differ from the original), then
 * test.php runs on it. A copy counts as caught only when test.php exits non-zero AND the failures include the named
 * check, so a copy that merely crashes is not mistaken for the test seeing the defect. A control first: the original
 * passes. Exit 0 only when the control passes and every copy is caught for its named reason.
 */
[$report, $build, $nowIso] = array_slice($argv, 1, 3) + [null, null, null];
if (!$report || !$build || !$nowIso) { fwrite(STDERR, "usage: weaken.php REPORT BUILD NOW\n"); exit(2); }
$test = __DIR__ . '/test.php';
$src = (string)file_get_contents($report);
$tmp = sys_get_temp_dir() . '/efr-weaken-' . getmypid() . '-' . bin2hex(random_bytes(3));
mkdir($tmp, 0700);
register_shutdown_function(function () use ($tmp): void { exec('rm -rf ' . escapeshellarg($tmp)); });
$runTest = function (string $file) use ($test, $build, $nowIso): array {
    $o = []; exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($test) . ' ' . escapeshellarg($file) . ' '
        . escapeshellarg($build) . ' ' . escapeshellarg($nowIso) . ' 2>&1', $o, $rc);
    return [$rc, implode("\n", $o)];
};

$copies = require __DIR__ . '/copies.php';

$pass = 0; $fail = 0;
[$rc, $out] = $runTest($report);
if ($rc === 0 && preg_match('/^(\d+) passed, 0 failed$/m', $out)) { $pass++; echo "  ok   control: the original passes\n"; }
else { $fail++; echo "  FAIL control: the original does not pass (rc=$rc)\n$out\n"; }

foreach ($copies as $name => [$edits, $needle]) {
    $s = $src;
    foreach ($edits as [$old, $new]) {
        $n = substr_count($s, $old);
        if ($n !== 1) { $fail++; echo "  FAIL $name: anchor found $n times, not once — " . substr(trim($old), 0, 70) . "\n"; continue 2; }
        $s = str_replace($old, $new, $s);
    }
    if ($s === $src) { $fail++; echo "  FAIL $name: the copy does not differ\n"; continue; }
    $file = $tmp . '/' . substr(md5($name), 0, 8) . '.php';
    file_put_contents($file, $s);
    $lint = []; exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $lint, $lrc);
    if ($lrc !== 0) { $fail++; echo "  FAIL $name: the copy does not parse\n"; continue; }
    [$rc, $out] = $runTest($file);
    $named = false;
    foreach (explode("\n", $out) as $line) if (strpos($line, '  FAIL ' . $needle) === 0) { $named = true; break; }
    preg_match('/^(\d+) passed, (\d+) failed$/m', $out, $m);
    if ($rc !== 0 && $named) { $pass++; echo "  ok   $name: caught (" . ($m[2] ?? '?') . " checks fail, among them \"$needle\")\n"; }
    else { $fail++; echo "  FAIL $name: not caught for its reason (rc=$rc, named=" . ($named ? 'yes' : 'no') . ")\n"; }
}
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
