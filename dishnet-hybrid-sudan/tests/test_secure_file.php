<?php
/**
 * test_secure_file.php — a secret file the web process can still read.
 *
 * The bug this pins: email_settings.json was written chmod 0600 by a tool run
 * as root through docker exec. The webhook runs as the web user, could not
 * read it, and MailService reported "plugin mail is not configured" — while
 * the same file read perfectly from the command line. Every diagnostic said
 * the mailer was fine, because every diagnostic ran as root.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/lib/SecureFile.php';

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

$dir = sys_get_temp_dir() . '/sfx_' . bin2hex(random_bytes(4));
@mkdir($dir, 0755, true);
$file = $dir . '/email_settings.json';

echo "\nWriting a secret file\n";
$r = SecureFile::write($file, json_encode(['smtp_pass' => 's3cret']));
is_($r['ok'], 'the write succeeds', $r['error']);
is_(is_file($file), 'the file exists');
is_($r['mode'] === '0640', 'it is 0640 — private from other accounts, readable by its group',
    'mode was ' . $r['mode']);
is_(json_decode((string)file_get_contents($file), true)['smtp_pass'] === 's3cret',
    'and the contents survive intact');

echo "\nNo temporary file is left behind\n";
is_(glob($dir . '/*.tmp.*') === [], 'the atomic rename cleans up after itself');

echo "\nThe audit catches exactly the mode that caused the outage\n";
chmod($file, 0600);
$a = SecureFile::auditReadability($file);
is_($a['readable_by_others'] === false, '0600 is flagged as unreadable by other processes');
is_(strpos($a['why'], '0600') !== false, 'and the reason names the mode');
is_(stripos($a['why'], 'ONLY that user') !== false,
    'and explains it in terms of who can read it, not just the octal');

chmod($file, 0640);
is_(SecureFile::auditReadability($file)['readable_by_others'], '0640 passes');
chmod($file, 0644);
is_(SecureFile::auditReadability($file)['readable_by_others'], '0644 passes');

echo "\nAdopting an already-broken file repairs it\n";
chmod($file, 0600);
SecureFile::adopt($file, $dir);
is_(SecureFile::auditReadability($file)['readable_by_others'],
    'a file left at 0600 by an earlier run is made readable again');

echo "\nIt never throws on what it cannot control\n";
$missing = $dir . '/does_not_exist.json';
$a2 = SecureFile::auditReadability($missing);
is_($a2['readable_by_others'] === false && stripos($a2['why'], 'does not exist') !== false,
    'a missing file is reported, not treated as a permission fault');
try { SecureFile::adopt($missing, $dir); ok('adopting a missing file is a no-op'); }
catch (\Throwable $e) { bad('adopting a missing file threw', $e->getMessage()); }
is_(SecureFile::describeOwner($missing) === '(missing)', 'and its owner reads as missing');

echo "\nThe writers no longer lock the file to one user\n";
foreach (['tools/set_sent_copy.php', 'tools/email_setup.php'] as $rel) {
    $src = (string)file_get_contents($root . '/' . $rel);
    is_(strpos($src, 'chmod($file, 0600)') === false, "{$rel}: no bare 0600 chmod left");
    is_(strpos($src, 'SecureFile') !== false, "{$rel}: writes through SecureFile");
}

echo "\nMailService tells the two failures apart\n";
$ms = (string)file_get_contents($root . '/lib/MailService.php');
is_(strpos($ms, '!is_readable($emailFile)') !== false,
    'it checks readability separately from existence');
is_(strpos($ms, 'unreadableReason') !== false,
    'and keeps the reason, so "not configured" is not the only thing an operator sees');

@unlink($file); @rmdir($dir);
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
