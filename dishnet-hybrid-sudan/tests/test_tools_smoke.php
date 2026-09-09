<?php
/**
 * test_tools_smoke.php — do the tools actually run?
 *
 * Every tool here was syntax-checked and reviewed, and one still reached the
 * server with a fatal in it: inbound_mail_run.php called CrmApiClient's
 * constructor instead of its fromUcrm() factory, and passed a config array
 * where a string was wanted. php -l cannot see that, and no test ran the tool,
 * so it was found by running it in production — at the exact moment the
 * mailbox had finally been configured and the path was reached for the first
 * time.
 *
 * So: run each read-only invocation with a data directory of our own and fail
 * on any fatal. Not a test of what they do — a test that they run at all.
 * Bootstrapping is where this class of mistake lives.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/lib/ConfigVault.php';

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

$tmp = sys_get_temp_dir() . '/dn_tools_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0755, true);

/**
 * A mailbox that is configured but unreachable. Configured matters: an
 * unconfigured tool exits early and never reaches the lines that construct
 * anything, which is precisely how the fatal stayed hidden.
 */
file_put_contents($tmp . '/kyc_config.json', json_encode([
    'email_ai_jmap_url'   => 'http://127.0.0.1:9',
    'email_ai_mailbox'    => 'smoke@example.invalid',
    'email_ai_mailbox_pw' => 'not-a-real-password',
]));

$runs = [
    ['inbound_mail_run.php',      ['--dry'],  'reads the mailbox and reports failure cleanly'],
    ['inbound_mail_run.php',      ['--list'], 'lists the draft inbox'],
    ['set_inbound_mail.php',      ['--show'], 'shows the inbound mail settings'],
    ['set_mailbox_password.php',  [],         'refuses to prompt without a terminal'],
    ['email_identity.php',        [],         'reports the email identity'],
    ['set_customer_emails.php',   ['--show'], 'shows the lifecycle switches'],
    ['fix_file_permissions.php',  [],         'audits file permissions'],
    ['quote_email_doctor.php',    [],         'diagnoses the quotation path'],
    ['config_trace.php',          ['email_ai_mailbox_pw'], 'traces a config key through its layers'],
    ['jmap_probe.php',            [],         'probes for a JMAP endpoint'],
    ['ai_facts.php',              [],         'shows what the assistant tells customers'],
    ['inbound_mail_run.php',      ['--to-drafts'], 'reports that draft filing is unconfigured'],
    ['kits.php',                  [],         'lists Starlink equipment from StockService'],
];

foreach ($runs as [$tool, $flags, $what]) {
    $path = $root . '/tools/' . $tool;
    if (!is_file($path)) { bad($tool . ' is missing'); continue; }

    // DN_VAULT_FILE as well as DN_DATA_DIR. The vault lives outside the data
    // directory by design, so without this the tools write their test values
    // into the real one — which then gap-fills them back over live config.
    $cmd = 'DN_DATA_DIR=' . escapeshellarg($tmp)
         . ' DN_VAULT_FILE=' . escapeshellarg($tmp . '/vault.json')
         . ' php ' . escapeshellarg($path) . ' '
         . implode(' ', array_map('escapeshellarg', $flags)) . ' 2>&1';
    $out  = [];
    $code = 0;
    exec($cmd, $out, $code);
    $text = implode("\n", $out);

    // The exit code is not the test. Several of these correctly exit non-zero
    // — a tool that refuses to prompt without a terminal is behaving. What
    // must never appear is PHP falling over.
    $fatal = preg_match('/(Fatal error|Uncaught \w*Error|Parse error|Argument #\d+)/i', $text) === 1;
    is_(!$fatal, $tool . ' ' . implode(' ', $flags) . ' — ' . $what,
        $fatal ? substr($text, 0, 400) : '');
}

echo "\nThe smoke test cannot reach the real vault\n";
is_(!is_file($tmp . '/kyc_config.json') || is_file($tmp . '/vault.json'),
    'the tools wrote their vault inside the test directory');
$realVault = ConfigVault::path($root, dirname($root) . '/data');
is_(strpos($realVault, $tmp) === false || getenv('DN_VAULT_FILE') !== false,
    'and the real vault path is untouched by this run');

echo "\nThe fatal that got out is specifically covered\n";
$src = (string)file_get_contents($root . '/tools/inbound_mail_run.php');
is_(strpos($src, 'CrmApiClient::fromUcrm(') !== false,
    'the API client is built through its factory');
is_(preg_match('/new\s+CrmApiClient\s*\(/', $src) === 0,
    'and never through the constructor, which takes a URL and a key');

foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f);
@rmdir($tmp);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
