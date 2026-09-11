<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * otp_email_test.php — send one real login-code email and say what it was.
 *
 *   php tools/otp_email_test.php --to you@example.com
 *
 * "How do I know it worked" deserves a better answer than "check your inbox".
 * An inbox shows the From HEADER. It does not show the ENVELOPE sender —
 * the line of SMTP conversation the relay routes bounces by — and those two
 * disagreeing is the exact failure this feature exists to fix. A message
 * that looks right and still sends its bounces to the sales mailbox is the
 * thing that goes unnoticed for months.
 *
 * So this prints the conversation. Every SMTP step, in order, with the
 * sender the relay was actually given, and whether the relay accepted it.
 *
 * It builds the message through lib/OtpEmail.php, which is the same code the
 * login API runs. Nothing here is an imitation of the real path.
 *
 * The code in the mail is a random six digits that logs nobody in. No OTP is
 * stored, no account is touched, no rate limit is spent.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/OtpEmail.php';

$args = array_slice($argv, 1);
$val  = function (string $f) use ($args): string {
    $i = array_search($f, $args, true);
    return ($i !== false && isset($args[$i + 1])) ? (string)$args[$i + 1] : '';
};

foreach ($args as $a) {
    if (strpos($a, '--') !== 0) continue;
    if (!in_array($a, ['--to', '--name'], true)) {
        fwrite(STDERR, "\n  Unknown option: {$a}\n");
        fwrite(STDERR, "  Known options are --to <address> and --name <name>.\n\n");
        exit(2);
    }
}

$to = trim($val('--to'));
if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "\n  usage: php tools/otp_email_test.php --to you@example.com\n\n");
    exit(2);
}
$name = trim($val('--name')) ?: 'DishNet Admin';

$dataDir = cliDataDir($root);
$config  = PluginConfig::load($root, $dataDir);
$code    = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

$settingsFile = rtrim($dataDir, '/') . '/email_settings.json';
$es = is_file($settingsFile)
    ? (json_decode((string)@file_get_contents($settingsFile), true) ?: []) : [];
$systemFrom = (string)($es['system_from'] ?? '');
$ordinary   = (string)($es['smtp_from'] ?? $es['smtp_user'] ?? '');
$pass       = (string)($es['smtp_pass'] ?? '');

echo "\n  OTP EMAIL TEST — " . gmdate('Y-m-d H:i') . " UTC\n\n";
printf("    %-16s %s\n", 'to', $to);
printf("    %-16s %s\n", 'system_from', $systemFrom !== '' ? $systemFrom : '(not set)');
printf("    %-16s %s\n", 'ordinary sender', $ordinary !== '' ? $ordinary : '(not set)');
echo "\n";
if ($systemFrom === '') {
    echo "  No system sender configured, so this goes out as the ordinary sender —\n";
    echo "  the same as every other customer email. To change that:\n";
    echo "    php tools/set_system_sender.php --set no-reply@yourdomain\n\n";
}

$r = OtpEmail::send($config, $dataDir, $to, $name, $code, 10);

echo "  SMTP CONVERSATION\n";
$mailFrom = '';
foreach (($r['log'] ?? []) as $step) {
    $stepName = (string)($step['step'] ?? '');
    $msg      = (string)($step['msg'] ?? '');
    // The password and its base64 both appear verbatim in an AUTH exchange.
    if ($pass !== '') $msg = str_replace([$pass, base64_encode($pass)], '••••••', $msg);
    // A multi-line SMTP reply is separated by CRLF, and a bare \r left in the
    // string sends the terminal's cursor back to column 0 — which overwrote
    // the previous row and made the EHLO line vanish from this very report.
    $msg = trim(preg_replace('/[\r\n]+/', ' / ', $msg) ?? $msg);
    printf("    %-5s %-14s %s\n", !empty($step['ok']) ? 'ok' : 'FAIL', $stepName,
           strlen($msg) > 90 ? substr($msg, 0, 87) . '…' : $msg);
    if ($stepName === 'mail_from') $mailFrom = $msg;
}

$used = ($r['from'] ?? '') !== ''
    ? MailService::bareAddress((string)$r['from'])
    : MailService::bareAddress($ordinary);

echo "\n";
if ($r['ok']) {
    echo "  ✔ ACCEPTED BY THE RELAY\n\n";
    printf("    %-16s %s\n", 'From header', $used);
    // The envelope is the point of the whole exercise, so name the address,
    // not the relay's "250 Ok" — a response code reads like a result and
    // answers a different question.
    printf("    %-16s %s\n", 'envelope sender',
           $used . ($mailFrom !== '' ? '  (relay said: ' . $mailFrom . ')' : ''));
    echo "\n";
    echo "  What to check in the inbox:\n";
    echo "    1. The From line reads {$used} — that is the header.\n";
    echo "    2. Open the message source (Gmail: ⋮ → Show original) and find\n";
    echo "       Return-Path. It should also read {$used}. That is the envelope,\n";
    echo "       and it is the one that decides where bounces go.\n";
    echo "    3. Hit Reply. It should offer the Reply-To address, not {$used}.\n\n";
    echo "  Accepted is not delivered. A relay can accept a message and bounce\n";
    echo "  it afterwards — if it does not arrive in a few minutes, the relay's\n";
    echo "  own log is the place to look, not this tool.\n\n";
    exit(0);
}

echo "  ✘ NOT SENT\n\n";
echo "    " . (string)$r['error'] . "\n\n";
if (stripos((string)$r['error'], 'MAIL FROM') !== false && $systemFrom !== '') {
    echo "  MAIL FROM was rejected and a system sender is configured. That is\n";
    echo "  the relay refusing to send as " . MailService::bareAddress($systemFrom) . ".\n";
    echo "  Either authorise that address with the relay, or clear it:\n";
    echo "    php tools/set_system_sender.php --clear\n\n";
}
exit(1);
