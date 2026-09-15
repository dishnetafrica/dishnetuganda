<?php
declare(strict_types=1);
/**
 * test_switch_tool_readback.php — the e-mail switch tool tells the truth
 * about what it just did.
 *
 * On 15 Sep the operator ran `set_customer_emails.php --all-off`. It cleared
 * every switch, then displayed them all still ON and warned that eight events
 * would e-mail customers. The dispatcher's disk snapshot was a one-shot static
 * filled by the "Before" display and reused by the "After" one. The stop had
 * worked; the read-back lied, and the operator was left with the opposite
 * picture of what customers would receive.
 */
$pass = 0; $fail = 0;
function t(string $n, $got, $want): void { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d !== '' ? "\n       $d" : '') . "\n"; } }

$root = dirname(__DIR__);
if (!getenv('DN_VAULT_FILE')) putenv('DN_VAULT_FILE=' . tempnam(sys_get_temp_dir(), 'dn-vault-'));
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/CustomerEmailDispatcher.php';

$tmp = sys_get_temp_dir() . '/switch_readback_' . getmypid();
exec('rm -rf ' . escapeshellarg($tmp)); @mkdir($tmp, 0777, true);
register_shutdown_function(function () use ($tmp) { exec('rm -rf ' . escapeshellarg($tmp)); });
SqliteStore::create($tmp);   // the store first, as a real install has it
$file = $tmp . '/kyc_config.json';
$tool = function (string $args) use ($root, $tmp): array {
    $out = []; $rc = 0;
    exec(sprintf('DN_DATA_DIR=%s DN_VAULT_FILE=%s php %s %s 2>&1', escapeshellarg($tmp), escapeshellarg((string)getenv('DN_VAULT_FILE')),
                 escapeshellarg($root . '/tools/set_customer_emails.php'), $args), $out, $rc);
    return [$rc, implode("\n", $out)];
};
$after = function (string $out): string { $p = strpos($out, 'After ('); return $p === false ? '' : substr($out, $p); };

// ═════════════════════════════════════════════════
echo "\n1. The dispatcher's view of the disk follows the file within one process\n";
// ═════════════════════════════════════════════════
$GLOBALS['dataDir'] = $tmp;
file_put_contents($file, json_encode(['customer_emails_enabled' => '1', 'customer_email_invoice' => '1', 'pad' => 'x']));
t('switch read as ON from the file',            CustomerEmailDispatcher::enabled('invoice', []), true);
file_put_contents($file, json_encode(['pad' => 'a different size']));
t('...and as off once the file no longer says so — same process, no restart', CustomerEmailDispatcher::enabled('invoice', []), false);
file_put_contents($file, json_encode(['customer_emails_enabled' => '1', 'customer_email_welcome' => '1']));
t('...and follows a third write too',            CustomerEmailDispatcher::enabled('welcome', []), true);

// ═════════════════════════════════════════════════
echo "\n2. The tool's After display is the state on disk\n";
// ═════════════════════════════════════════════════
file_put_contents($file, json_encode(['customer_emails_enabled' => '1', 'customer_email_quotation' => '1', 'crm_public_url' => 'https://x.example']));
[$rc, $out] = $tool('--on invoice');
t('--on invoice succeeds',                        $rc, 0);
is_(preg_match('/^\s*invoice\s+ON\b/m', $after($out)) === 1, 'After shows invoice ON', $after($out));
[$rc, $out] = $tool('--all-off');
t('--all-off succeeds',                           $rc, 0);
is_(strpos($after($out), 'MASTER SWITCH   OFF') !== false,       'After shows the master OFF');
is_(preg_match('/^\s*(quotation|invoice)\s+ON\b/m', $after($out)) === 0, 'After shows no event ON');
is_(strpos($out, 'No customer will receive a lifecycle email') !== false, 'and says so in words');
is_(strpos($out, 'will now email real customers') === false,     'and does not warn about sends that cannot happen');
$disk = json_decode((string)file_get_contents($file), true) ?: [];
is_(!isset($disk['customer_emails_enabled']) && !isset($disk['customer_email_quotation']) && !isset($disk['customer_email_invoice']),
    'the switches are gone from the file');
t('other settings in the file are untouched',    $disk['crm_public_url'] ?? null, 'https://x.example');
[$rc, $out] = $tool('--show');
is_(strpos($out, 'MASTER SWITCH   OFF') !== false,               'a fresh --show agrees');

// ═════════════════════════════════════════════════
echo "\n3. When something else still supplies the old value, the tool says FAILED\n";
// ═════════════════════════════════════════════════
// data/config.json is merged before kyc_config.json and is not the tool's to
// edit: a switch held there survives --all-off, and the operator must hear it.
file_put_contents($tmp . '/config.json', json_encode(['customer_emails_enabled' => '1', 'customer_email_invoice' => '1']));
file_put_contents($file, json_encode(['customer_email_invoice' => '1']));
[$rc, $out] = $tool('--all-off');
t('the tool exits non-zero',                      $rc, 1);
is_(strpos($out, 'FAILED') !== false,                            'and prints FAILED');
is_(strpos($out, 'master reads ON, wanted off') !== false,       'naming the switch that would not turn off', $out);
is_(strpos($out, 'config_trace.php') !== false,                  'and points at the tracer');
@unlink($tmp . '/config.json');

// ═════════════════════════════════════════════════
echo "\n4. Turning one event on with the master off is not reported as a failure\n";
// ═════════════════════════════════════════════════
file_put_contents($file, json_encode(['crm_public_url' => 'https://x.example']));
[$rc, $out] = $tool('--on invoice');
t('it succeeds',                                  $rc, 0);
is_(strpos($out, 'MASTER SWITCH   OFF') !== false && strpos($out, 'No customer will receive') !== false,
    'and the display makes clear nothing is sent until the master is on');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
