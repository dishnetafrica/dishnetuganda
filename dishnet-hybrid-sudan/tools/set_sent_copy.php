<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * set_sent_copy.php — make every email the platform sends appear in the Sent
 * folder of a real mailbox, readable in webmail.
 *
 *   php tools/set_sent_copy.php --user accounts@dishnetuganda.com --test
 *   php tools/set_sent_copy.php --off
 *   php tools/set_sent_copy.php --show
 *
 * Prompts for the mailbox password (hidden). Defaults the IMAP host to
 * mail.<domain of the user address> and the folder to Sent.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/SentCopy.php';

$opt     = getopt('', ['user:', 'pass:', 'host:', 'port:', 'folder:', 'test', 'off', 'show']);
$dataDir = getenv('DN_DATA_DIR') ?: getDataDir($root);
$file    = rtrim($dataDir, '/') . '/email_settings.json';
$es      = is_file($file) ? (json_decode((string)@file_get_contents($file), true) ?: []) : [];

$save = function (array $es) use ($file) {
    $tmp = $file . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, json_encode($es, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) === false
        || !@rename($tmp, $file)) { @unlink($tmp); fwrite(STDERR, "could not write {$file}\n"); exit(1); }
    @chmod($file, 0600);
};

if (isset($opt['show'])) {
    echo "Sent-copy settings:\n";
    foreach (['sent_copy_enabled','sent_copy_host','sent_copy_port','sent_copy_user','sent_copy_folder'] as $k) {
        printf("  %-18s %s\n", substr($k, 10), var_export($es[$k] ?? null, true));
    }
    printf("  %-18s %s\n", 'pass', isset($es['sent_copy_pass']) && $es['sent_copy_pass'] !== '' ? '(set)' : '(not set)');
    exit(0);
}

if (isset($opt['off'])) {
    $es['sent_copy_enabled'] = false;
    $save($es);
    echo "Sent-copy DISABLED. Platform emails will no longer be filed in the Sent folder.\n";
    exit(0);
}

$user = trim((string)($opt['user'] ?? ($es['sent_copy_user'] ?? '')));
if ($user === '' || !filter_var($user, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php tools/set_sent_copy.php --user mailbox@domain [--test]\n");
    exit(1);
}
$host = trim((string)($opt['host'] ?? ($es['sent_copy_host'] ?? '')));
if ($host === '') $host = 'mail.' . substr(strrchr($user, '@'), 1);
$port   = (int)($opt['port'] ?? ($es['sent_copy_port'] ?? 993)) ?: 993;
$folder = trim((string)($opt['folder'] ?? ($es['sent_copy_folder'] ?? 'Sent'))) ?: 'Sent';

$pass = (string)($opt['pass'] ?? '');
if ($pass === '' && !empty($es['sent_copy_pass']) && !isset($opt['user'])) {
    $pass = (string)$es['sent_copy_pass'];         // keep the stored one
}
if ($pass === '') {
    echo "Mailbox password for {$user}: ";
    $tty = trim((string)@shell_exec('stty -g 2>/dev/null')) !== '';
    if ($tty) @shell_exec('stty -echo 2>/dev/null');
    $pass = trim((string)fgets(STDIN));
    if ($tty) { @shell_exec('stty echo 2>/dev/null'); echo "\n"; }
}
if ($pass === '') { fwrite(STDERR, "No password given — nothing changed.\n"); exit(1); }

$es = array_merge($es, [
    'sent_copy_enabled' => true,
    'sent_copy_host'    => $host,
    'sent_copy_port'    => $port,
    'sent_copy_user'    => $user,
    'sent_copy_pass'    => $pass,
    'sent_copy_folder'  => $folder,
]);
$save($es);

echo "\nSent-copy ENABLED\n";
echo "  IMAP    : {$host}:{$port} (TLS)\n";
echo "  Mailbox : {$user}\n";
echo "  Folder  : {$folder}\n";

if (!isset($opt['test'])) {
    echo "\nAdd --test to file a test message and prove it works.\n";
    exit(0);
}

$raw = "From: DishNet <{$user}>\r\n"
     . "To: {$user}\r\n"
     . "Subject: [TEST] Sent-folder archive check — " . gmdate('Y-m-d H:i') . " UTC\r\n"
     . "Date: " . date('r') . "\r\n"
     . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n"
     . "If you can read this in the Sent folder, every email the DishNet platform\r\n"
     . "sends from now on will appear here too.\r\n";

echo "\n  … filing a test message\n";
$r = SentCopy::append($es, $raw);
if (!empty($r['ok'])) {
    echo "  PASS — filed in \"{$r['folder']}\"\n";
    if (!empty($r['created'])) echo "  (created the \"{$r['created']}\" folder — it did not exist yet)\n";
    echo "\nOpen https://webmail." . substr(strrchr($user, '@'), 1) . " → Sent. It is there.\n";
    exit(0);
}
echo "  FAIL — {$r['error']}\n";
if (!empty($r['listed'])) {
    echo "\n  Folders this mailbox actually has:\n";
    foreach ($r['listed'] as $f) echo "    - {$f}\n";
    echo "  Rerun with --folder \"<one of those>\" if none of them is a Sent folder.\n";
}
echo "\nThe password is saved; fix the cause and rerun with --test only.\n";
exit(1);
