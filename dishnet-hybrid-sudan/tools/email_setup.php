<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * email_setup.php — configure the plugin's own mail system from the terminal
 * and prove it works with a real test email, in one command.
 *
 * Run inside the ucrm container, from the plugin directory:
 *
 *   php tools/email_setup.php --user dishnetafrica@gmail.com --test bhavin@dishnetafrica.com
 *
 * It will prompt for the password (a Gmail App Password — the 16-character
 * code from Google Account → Security → 2-Step Verification → App passwords;
 * your normal Gmail password will NOT work). Typing at the prompt keeps the
 * secret out of shell history; --pass '...' is accepted for scripting.
 *
 * What it writes (dataDir/email_settings.json — the same file the Settings
 * screen edits, existing keys preserved):
 *   use_ucrm_email          = false   plugin-only mail, uCRM mailer unused
 *   quote_email_via_plugin  = true    quotations emailed by the plugin, PDF attached
 *   smtp_host/port/enc/user/pass/from as given (defaults: Gmail, 587, TLS)
 *
 * Options: --host --port --enc(tls|ssl|none) --from override the Gmail
 * defaults — the same command later points at Stalwart/Brevo. --no-quote
 * leaves quotation email on uCRM. --test is optional; without it only the
 * configuration is written.
 *
 * The password is stored only in email_settings.json (plugin data dir),
 * exactly where the Settings screen stores it. It is never printed. CLI only.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/MailService.php';

$opt = getopt('', ['user:', 'pass:', 'test:', 'host:', 'port:', 'enc:', 'from:', 'no-quote', 'keep-ucrm']);
$user = trim((string)($opt['user'] ?? ''));
if ($user === '' || !filter_var($user, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php tools/email_setup.php --user you@gmail.com [--test someone@example.com]\n");
    fwrite(STDERR, "       [--host smtp.gmail.com] [--port 587] [--enc tls|ssl|none] [--from address]\n");
    fwrite(STDERR, "       [--pass 'app password'] [--no-quote] [--keep-ucrm]\n");
    exit(1);
}

$pass = (string)($opt['pass'] ?? '');
if ($pass === '') {
    echo "Password for {$user} (Gmail: the 16-character App Password): ";
    // Hide the typing where a real terminal allows it.
    $hadTty = function_exists('shell_exec') && trim((string)@shell_exec('stty -g 2>/dev/null')) !== '';
    if ($hadTty) @shell_exec('stty -echo 2>/dev/null');
    $pass = trim((string)fgets(STDIN));
    if ($hadTty) { @shell_exec('stty echo 2>/dev/null'); echo "\n"; }
}
$pass = str_replace(' ', '', $pass);   // Google shows app passwords in groups of four
if ($pass === '') { fwrite(STDERR, "No password given — nothing changed.\n"); exit(1); }

$host = trim((string)($opt['host'] ?? '')) ?: 'smtp.gmail.com';
$port = (int)(($opt['port'] ?? 0) ?: 587);
$enc  = strtolower(trim((string)($opt['enc'] ?? ''))) ?: 'tls';
if (!in_array($enc, ['tls', 'ssl', 'none'], true)) { fwrite(STDERR, "--enc must be tls, ssl or none\n"); exit(1); }
$from = trim((string)($opt['from'] ?? '')) ?: $user;
$test = trim((string)($opt['test'] ?? ''));
if ($test !== '' && !filter_var($test, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "--test is not a valid email address\n"); exit(1);
}

$dataDir = getenv('DN_DATA_DIR') ?: getDataDir($root);
$file    = rtrim($dataDir, '/') . '/email_settings.json';
$mask    = str_repeat('•', max(4, strlen($pass) - 4)) . substr($pass, -4);

echo "DishNet plugin email setup — " . gmdate('Y-m-d H:i') . " UTC\n\n";
echo "  data dir : {$dataDir}\n";
echo "  SMTP     : {$host}:{$port} " . strtoupper($enc) . "\n";
echo "  account  : {$user} (password {$mask})\n";
echo "  from     : {$from}\n";
echo "  mode     : " . (isset($opt['keep-ucrm']) ? 'uCRM mailer first, plugin SMTP fallback' : 'PLUGIN-ONLY (uCRM mailer not used)') . "\n";
echo "  quotes   : " . (isset($opt['no-quote']) ? 'emailed by uCRM (unchanged)' : 'emailed by the PLUGIN with the PDF attached') . "\n\n";

$existing = is_file($file) ? (json_decode((string)@file_get_contents($file), true) ?: []) : [];
$settings = array_merge($existing, [
    'use_ucrm_email'         => isset($opt['keep-ucrm']),
    'quote_email_via_plugin' => !isset($opt['no-quote']),
    'smtp_preset'            => $host === 'smtp.gmail.com' ? 'gmail' : '',
    'smtp_host'              => $host,
    'smtp_port'              => $port,
    'smtp_user'              => $user,
    'smtp_pass'              => $pass,
    'smtp_enc'               => $enc === 'none' ? '' : $enc,
    'smtp_from'              => $from,
]);

// Write-then-rename so a concurrent reader never sees a half-written file.
$tmp = $file . '.tmp.' . getmypid();
if (@file_put_contents($tmp, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) === false
    || !@rename($tmp, $file)) {
    @unlink($tmp);
    fwrite(STDERR, "FAIL: could not write {$file} — is the data directory writable?\n");
    exit(1);
}
require_once __DIR__ . '/../lib/SecureFile.php';
SecureFile::adopt($file);   // 0600 as root hides this from the web process
echo "  ✔ configuration written to email_settings.json\n";

if ($test === '') {
    echo "\nDone. Add --test you@example.com to also send a proof email.\n";
    exit(0);
}

echo "  … sending test email to {$test}\n\n";
$mail = new MailService($dataDir);
$r = $mail->send(
    $test,
    'DishNet Admin',
    'DishNet plugin email test — ' . gmdate('Y-m-d H:i') . ' UTC',
    '<p>This message was sent by the <b>DishNet plugin\'s own mail system</b> (no uCRM mailer involved).</p>'
    . '<ul><li>SMTP: ' . htmlspecialchars($host . ':' . $port . ' ' . strtoupper($enc)) . '</li>'
    . '<li>Account: ' . htmlspecialchars($user) . '</li>'
    . '<li>Quotation emails by plugin: ' . (!isset($opt['no-quote']) ? 'ON (PDF attached)' : 'off') . '</li></ul>'
    . '<p>If you are reading this, customer email is working.</p>',
    '',
    ['Reply-To' => $from]
);

foreach (($r['log'] ?? []) as $step) {
    $msg = (string)($step['msg'] ?? '');
    if ($pass !== '') $msg = str_replace([$pass, base64_encode($pass)], '••••', $msg);
    printf("  %s  %-12s %s\n", !empty($step['ok']) ? 'ok  ' : 'FAIL', (string)($step['step'] ?? ''), $msg);
}

if (!empty($r['ok'])) {
    echo "\nSETUP: PASS — test email queued for {$test}. Check the inbox (and spam, the first time).\n";
    exit(0);
}
echo "\nSETUP: FAIL — " . (string)($r['error'] ?? 'unknown') . "\n";
echo "The configuration IS saved; fix the credential or network issue and rerun with --test only.\n";
echo "Gmail checklist: 2-Step Verification ON, and the password must be an App Password, not your login password.\n";
exit(1);
