<?php
/**
 * test_config_vault_store.php — a secret written by root that the web user
 * can still read.
 *
 * ConfigVault::refresh() writes 0600, which is correct when the web process
 * writes its own vault and wrong the moment a tool run as root through
 * docker exec writes it: the file becomes root-owned, nginx can no longer
 * read it, and every diagnostic says "not configured" while the value sits
 * there intact. That exact outage already happened once, to
 * email_settings.json, and this is the same shape.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/lib/ConfigVault.php';
require_once $root . '/lib/SecureFile.php';

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

$base = sys_get_temp_dir() . '/cvs_' . bin2hex(random_bytes(4));
$pluginRoot = $base . '/plugins/dishnet';
$dataDir    = $base . '/data';
@mkdir($pluginRoot, 0755, true);
@mkdir($dataDir, 0755, true);

echo "\nStoring a value\n";
$r = ConfigVault::store($pluginRoot, $dataDir, ['email_ai_mailbox_pw' => 'a-real-password']);
is_(!empty($r['ok']), 'the write succeeds', (string)($r['error'] ?? ''));
is_($r['stored'] === ['email_ai_mailbox_pw'], 'and reports what it stored');

$file = ConfigVault::path($pluginRoot, $dataDir);
is_(is_file($file), 'the vault file exists at ' . basename($file));
$v = json_decode((string)file_get_contents($file), true);
is_(($v['config']['email_ai_mailbox_pw'] ?? '') === 'a-real-password', 'with the value in it');

echo "\nAnd it is readable by the process that has to read it\n";
$mode = substr(sprintf('%o', fileperms($file)), -4);
is_($mode !== '0600', 'it is not the 0600 that caused the last outage', 'mode: ' . $mode);
$read = SecureFile::readableByOwnerOf($file, $dataDir);
is_(!empty($read['ok']), 'the owner of the data directory can read it',
    'mode: ' . $mode . ' — ' . (string)($read['why'] ?? ''));

echo "\nAnd the tool reads that verdict correctly\n";
$toolSrc = (string)file_get_contents($root . '/tools/set_mailbox_password.php');
is_(strpos($toolSrc, "!empty(\$read['ok'])") !== false,
    'it tests the ok field, not the array — which is always truthy');

echo "\nOther keys are not silently added\n";
$bad = ConfigVault::store($pluginRoot, $dataDir, ['some_new_key' => 'x']);
is_(empty($bad['ok']), 'a key outside VAULT_KEYS is refused');
is_(strpos((string)$bad['error'], 'some_new_key') !== false, 'and named in the error');
$v2 = json_decode((string)file_get_contents($file), true);
is_(!isset($v2['config']['some_new_key']), 'and nothing was written');

echo "\nWhat was already in the vault survives a new write\n";
ConfigVault::store($pluginRoot, $dataDir, ['email_ai_mailbox' => 'accounts@dishnetuganda.com']);
$v3 = json_decode((string)file_get_contents($file), true);
is_(($v3['config']['email_ai_mailbox_pw'] ?? '') === 'a-real-password',
    'the password is still there after storing something else');
is_(($v3['config']['email_ai_mailbox'] ?? '') === 'accounts@dishnetuganda.com',
    'alongside the new value');

echo "\nAn empty value clears rather than storing emptiness\n";
ConfigVault::store($pluginRoot, $dataDir, ['email_ai_mailbox_pw' => '']);
$v4 = json_decode((string)file_get_contents($file), true);
is_(!isset($v4['config']['email_ai_mailbox_pw']), 'the key is gone');
is_(($v4['config']['email_ai_mailbox'] ?? '') === 'accounts@dishnetuganda.com',
    'and the rest of the vault is untouched');

echo "\nThe tool will not take a password from the command line\n";
$src = (string)file_get_contents($root . '/tools/set_mailbox_password.php');
is_(strpos($src, 'Do not pass the password as an argument') !== false,
    'it refuses arguments outright');
is_(strpos($src, 'stty -echo') !== false, 'and hides typing when it asks');
is_(preg_match('/echo[^;]*\$pw\b/', $src) === 0, 'it never prints the password back',
    'a line echoes $pw');

@unlink($file);
@rmdir($dataDir); @rmdir($pluginRoot); @rmdir($base . '/plugins'); @rmdir($base);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
