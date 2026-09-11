<?php
declare(strict_types=1);
/**
 * tools/email_setup.php — writes the plugin-only mail configuration
 * correctly and never leaks the password. (The actual SMTP send is
 * MailService, proven elsewhere; --test is not exercised here.)
 */
$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }

$root = dirname(__DIR__);
$tmp  = sys_get_temp_dir() . '/email_setup_test_' . getmypid();
exec('rm -rf ' . escapeshellarg($tmp));
@mkdir($tmp, 0777, true);

// Pre-existing settings must survive the merge.
file_put_contents($tmp . '/email_settings.json', json_encode(['recipients' => 'reports@dishnetafrica.com']));

$run = function (string $args) use ($root, $tmp): array {
    $cmd = 'DN_DATA_DIR=' . escapeshellarg($tmp) . ' php '
         . escapeshellarg($root . '/tools/email_setup.php') . ' ' . $args . ' 2>&1';
    exec($cmd, $out, $code);
    return [implode("\n", $out), $code];
};

echo "Configure-only run (Gmail defaults, plugin-only, quotes by plugin)\n";
[$out, $code] = $run("--user dishnetafrica@gmail.com --pass 'abcd efgh ijkl mnop'");
t('exits clean', $code, 0);
$s = json_decode((string)file_get_contents($tmp . '/email_settings.json'), true) ?: [];
t('plugin-only: uCRM mailer off', $s['use_ucrm_email'] ?? null, false);
t('quotations emailed by plugin', $s['quote_email_via_plugin'] ?? null, true);
t('gmail preset recognised', $s['smtp_preset'] ?? '', 'gmail');
t('host/port/enc defaults', [$s['smtp_host'] ?? '', $s['smtp_port'] ?? 0, $s['smtp_enc'] ?? ''],
  ['smtp.gmail.com', 587, 'tls']);
t('app-password spaces stripped', $s['smtp_pass'] ?? '', 'abcdefghijklmnop');
t('from defaults to the account', $s['smtp_from'] ?? '', 'dishnetafrica@gmail.com');
t('existing keys survive the merge', $s['recipients'] ?? '', 'reports@dishnetafrica.com');
t('password never printed', strpos($out, 'abcdefghijklmnop') === false && strpos($out, 'abcd efgh') === false, true);
t('masked tail shown instead', strpos($out, 'mnop') !== false, true);
t('says plugin-only', strpos($out, 'PLUGIN-ONLY') !== false, true);

echo "\nOverrides for the future mail server\n";
[$out, $code] = $run("--user billing@dishnetuganda.com --pass secret1234 --host mail.dishnetuganda.com --port 465 --enc ssl --from billing@dishnetuganda.com --keep-ucrm --no-quote");
t('exits clean', $code, 0);
$s = json_decode((string)file_get_contents($tmp . '/email_settings.json'), true) ?: [];
t('custom host honoured', [$s['smtp_host'], $s['smtp_port'], $s['smtp_enc']], ['mail.dishnetuganda.com', 465, 'ssl']);
t('no gmail preset for custom host', $s['smtp_preset'] ?? 'x', '');
t('--keep-ucrm keeps the uCRM-first mode', $s['use_ucrm_email'], true);
t('--no-quote leaves quotations on uCRM', $s['quote_email_via_plugin'], false);

echo "\nRefusals\n";
[$out, $code] = $run("--user not-an-email --pass x");
t('bad account refused', $code === 0, false);
[$out, $code] = $run("--user a@b.test --pass x --test not-an-email");
t('bad test address refused', $code === 0, false);
[$out, $code] = $run("--user a@b.test --pass x --enc weird");
t('bad encryption refused', $code === 0, false);

exec('rm -rf ' . escapeshellarg($tmp));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
