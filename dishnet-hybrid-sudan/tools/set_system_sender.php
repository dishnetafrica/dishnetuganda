<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * set_system_sender.php — the address system mail is sent FROM.
 *
 *   php tools/set_system_sender.php --show
 *   php tools/set_system_sender.php --set no-reply@dishnetuganda.com
 *   php tools/set_system_sender.php --set '"DishNet Africa" <no-reply@dishnetuganda.com>'
 *   php tools/set_system_sender.php --clear
 *
 * A login code is not correspondence. It is generated, it is valid for ten
 * minutes, and nobody should answer it — so it should not arrive from the
 * mailbox a customer answers. This sets the address OTP mail goes out as,
 * in the header AND in the SMTP envelope, so replies and bounces both land
 * where they belong instead of in the sales inbox.
 *
 * Quotations, invoices and every other customer email are untouched: those
 * ARE correspondence and keep the ordinary sender.
 *
 * Unset — which is every install that has never run this — changes nothing.
 * That is the Sudan case, and it stays byte-for-byte as it was.
 *
 * This edits ONE key. It reads the file, changes that key, writes it back,
 * and keeps a timestamped backup of what was there before. The SMTP host,
 * account and password are not touched, not re-typed, and not at risk.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/MailService.php';
require_once $root . '/lib/SecureFile.php';
require_once $root . '/lib/EmailSettingsWriter.php';

$dataDir = cliDataDir($root);
$file    = rtrim($dataDir, '/') . '/email_settings.json';

$args = array_slice($argv, 1);
$has  = function (string $f) use ($args): bool { return in_array($f, $args, true); };
$val  = function (string $f) use ($args): string {
    $i = array_search($f, $args, true);
    return ($i !== false && isset($args[$i + 1])) ? (string)$args[$i + 1] : '';
};

// An unknown flag is a typo or a plugin older than the command. Either way,
// silently doing nothing while reporting success is the worst answer.
foreach ($args as $i => $a) {
    if (strpos($a, '--') !== 0) continue;
    if (!in_array($a, ['--show', '--set', '--clear', '--yes'], true)) {
        fwrite(STDERR, "\n  Unknown option: {$a}\n\n");
        fwrite(STDERR, "  Known options are --show, --set <address>, --clear.\n");
        fwrite(STDERR, "  If you expected this one to work, the plugin on this\n");
        fwrite(STDERR, "  server is older than the command you were given.\n\n");
        exit(2);
    }
}

$settings = is_file($file)
    ? (json_decode((string)@file_get_contents($file), true) ?: [])
    : [];
$current = (string)($settings['system_from'] ?? '');

$report = function () use ($file, $settings, $current): void {
    $ordinary = (string)($settings['smtp_from'] ?? $settings['smtp_user'] ?? '');
    echo "\n  SYSTEM SENDER — what OTP mail goes out as\n\n";
    printf("    %-18s %s\n", 'file', $file);
    printf("    %-18s %s\n", 'system_from', $current !== '' ? $current : '(not set)');
    printf("    %-18s %s\n", 'ordinary sender', $ordinary !== '' ? $ordinary : '(not set)');
    echo "\n";
    if ($current === '') {
        echo "  Not set — OTP mail goes out as the ordinary sender, as it always has.\n\n";
    } else {
        echo "  OTP mail is sent as " . MailService::bareAddress($current) . ", header and envelope.\n";
        echo "  Everything else still goes out as " . ($ordinary ?: 'the ordinary sender') . ".\n\n";
    }
};

if ($args === [] || $has('--show')) {
    $report();
    if ($args === [] || (count($args) === 1 && $has('--show'))) exit(0);
}

$clear = $has('--clear');
$want  = trim($val('--set'));

if ($clear && $want !== '') {
    fwrite(STDERR, "  --set and --clear contradict each other. Pick one.\n\n");
    exit(2);
}
if (!$clear && $want === '') {
    fwrite(STDERR, "  Nothing to do. Use --set <address>, --clear, or --show.\n\n");
    exit(2);
}

$new = '';
if (!$clear) {
    $new = MailService::normalizeFrom($want);
    if ($new === '') {
        fwrite(STDERR, "\n  Not a usable sender address: {$want}\n\n");
        fwrite(STDERR, "  Expected something like no-reply@dishnetuganda.com, or\n");
        fwrite(STDERR, "  '\"DishNet Africa\" <no-reply@dishnetuganda.com>' with quotes.\n\n");
        exit(1);
    }
    // The relay decides this, not us — but a sender on a domain the relay has
    // never been told about is the single most common way this ends in a
    // rejected MAIL FROM, and saying so now costs nothing.
    $ordinaryDomain = strtolower((string)strrchr((string)($settings['smtp_from'] ?? ''), '@'));
    $newDomain      = strtolower((string)strrchr(MailService::bareAddress($new), '@'));
    if ($ordinaryDomain !== '' && $newDomain !== '' && $ordinaryDomain !== $newDomain) {
        echo "\n  Note: {$newDomain} is a different domain from the ordinary sender's\n";
        echo "  ({$ordinaryDomain}). Your relay has to be willing to send as it, or\n";
        echo "  MAIL FROM will be rejected. Send a test after this and check.\n";
    }
}

if ($new === $current) {
    echo "\n  Already " . ($new === '' ? 'unset' : $new) . ". Nothing written.\n\n";
    exit(0);
}

// Back up before touching it. This file holds the working SMTP credentials;
// a bad write here takes all customer email down, and "restore from the
// nightly zip" is not a recovery plan at 9am.
$b = EmailSettingsWriter::backup($dataDir);
if (!$b['ok']) {
    fwrite(STDERR, "\n  " . $b['error'] . "\n\n");
    exit(1);
}
if ($b['path'] !== '') echo "\n  backup: " . basename($b['path']) . "\n";

if ($clear) {
    unset($settings['system_from']);
} else {
    $settings['system_from'] = $new;
}

// Write-then-rename, so a reader mid-write never sees half a file.
$tmp = $file . '.tmp.' . getmypid();
$json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($json === false
    || @file_put_contents($tmp, $json, LOCK_EX) === false
    || !@rename($tmp, $file)) {
    @unlink($tmp);
    fwrite(STDERR, "\n  FAIL: could not write {$file}\n\n");
    exit(1);
}
// Written as root, this file would be invisible to the web process. Hand it
// back to whoever owns the data directory — that is the process that reads it.
SecureFile::adopt($file, $dataDir);

echo "\n  ✔ system_from " . ($clear ? 'cleared' : 'set to ' . $new) . "\n\n";
if (!$clear) {
    echo "  Prove it end to end — this sends a real OTP-shaped email:\n";
    echo "    php tools/otp_email_test.php --to you@example.com\n\n";
}
exit(0);
