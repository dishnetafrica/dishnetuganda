<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * set_mailbox_password.php — the password for the mailbox the AI reads.
 *
 *   docker exec -it ucrm php .../tools/set_mailbox_password.php
 *
 * The -it matters: with a terminal attached, the password can be typed without
 * being echoed. Without one, it would appear on screen and in any recording of
 * that session, so this refuses to run.
 *
 * Two things it will never do. It will not take the password as an argument —
 * that puts it in the shell history and in the process list, where any other
 * user on the box can read it with ps. And it will not print it back, not even
 * masked beyond a length.
 *
 * The ordinary home for this is uCRM's own Configuration screen. This exists
 * for when uCRM is still holding an older manifest and shows no field to type
 * into: a secret with nowhere legitimate to go ends up in a command line, which
 * is worse than any of this.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/ConfigVault.php';
require_once $root . '/lib/SecureFile.php';

$dataDir = getenv('DN_DATA_DIR') ?: getDataDir($root);

// A password given as an argument is already compromised; say so rather than
// quietly accepting it.
foreach (array_slice($argv, 1) as $a) {
    if ($a !== '--clear') {
        echo "\n  Do not pass the password as an argument.\n";
        echo "  It stays in your shell history and is visible to anyone who runs ps.\n";
        echo "  Run this with no arguments and type it when asked.\n";
        echo "  If you have already typed it as an argument, change it on the mail\n";
        echo "  server before doing anything else.\n\n";
        exit(1);
    }
}

if (in_array('--clear', array_slice($argv, 1), true)) {
    $r = ConfigVault::store($root, $dataDir, ['email_ai_mailbox_pw' => '']);
    echo empty($r['ok']) ? "\n  Could not clear: {$r['error']}\n\n" : "\n  Cleared.\n\n";
    exit(empty($r['ok']) ? 1 : 0);
}

$stty = @shell_exec('stty -g 2>/dev/null');
if ($stty === null || trim((string)$stty) === '') {
    echo "\n  This is not an interactive terminal, so typing cannot be hidden.\n";
    echo "  Re-run it with a terminal attached:\n\n";
    echo "    docker exec -it ucrm php " . __FILE__ . "\n\n";
    exit(1);
}

echo "\n  Mailbox: " . (string)(json_decode((string)@file_get_contents($dataDir . '/kyc_config.json'), true)['email_ai_mailbox'] ?? '(not set — run set_inbound_mail.php first)') . "\n";
echo "  Password (not shown): ";
@shell_exec('stty -echo');
$pw = (string)fgets(STDIN);
@shell_exec('stty ' . trim((string)$stty));
echo "\n";

$pw = trim($pw, "\r\n");
if ($pw === '') { echo "\n  Nothing entered. Unchanged.\n\n"; exit(1); }

$r = ConfigVault::store($root, $dataDir, ['email_ai_mailbox_pw' => $pw]);
$pw = str_repeat("\0", strlen($pw));   // do not leave it lying in memory

if (empty($r['ok'])) { echo "\n  Could not store it: {$r['error']}\n\n"; exit(1); }

$file = ConfigVault::path($root, $dataDir);
$own  = SecureFile::describeOwner($file);
echo "\n  Stored.\n";
echo "    file   " . $file . "\n";
echo "    owner  " . $own . "\n";
// readableByOwnerOf returns a verdict, not a boolean. Read as a boolean it is
// always truthy, and this line would have reassured every time — including the
// one time it mattered.
$read = SecureFile::readableByOwnerOf($file, $dataDir);
echo !empty($read['ok'])
    ? "    readable by the web process (" . (string)$read['why'] . ")\n"
    : "    WARNING: the web process cannot read this file (" . (string)$read['why'] . ").\n"
    . "    Run: php tools/fix_file_permissions.php\n";
echo "\n  Next:  php tools/inbound_mail_run.php --dry\n\n";
exit(0);
