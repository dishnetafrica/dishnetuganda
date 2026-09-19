<?php
declare(strict_types=1);
/**
 * test_wa_lifecycle_tool.php — the WhatsApp lifecycle test sends every sample
 * to the one number it was given, and nobody else.
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

$tmp = sys_get_temp_dir() . '/wa_lifecycle_' . getmypid();
exec('rm -rf ' . escapeshellarg($tmp)); @mkdir($tmp, 0777, true);
register_shutdown_function(function () use ($tmp) { exec('rm -rf ' . escapeshellarg($tmp)); });
SqliteStore::create($tmp);
// A sender that passes the readiness check; dry-run keeps it off the network.
file_put_contents($tmp . '/kyc_config.json', json_encode([
    'wa_plugin_url' => 'http://127.0.0.1:1/', 'wa_app_key' => 'k', 'wa_auth_key' => 'a', 'ai_currency' => 'UGX',
]));
$run = function (string $args) use ($root, $tmp): array {
    $out = []; $rc = 0;
    exec(sprintf('DN_DATA_DIR=%s DN_VAULT_FILE=%s php %s %s 2>&1', escapeshellarg($tmp), escapeshellarg((string)getenv('DN_VAULT_FILE')),
                 escapeshellarg($root . '/tools/wa_lifecycle_test.php'), $args), $out, $rc);
    return [$rc, implode("\n", $out)];
};
$log = function () use ($tmp): array { return json_decode((string)@file_get_contents($tmp . '/dry_run_notification_log.json'), true) ?: []; };

echo "\n1. Refuses to run without a number\n";
[$rc, $out] = $run('--dry-run');
t('exit code 1',                                  $rc, 1);
is_(strpos($out, 'Usage') !== false,              'and prints the usage');

echo "\n2. Dry run: every sample, the header and the footer, all to the one number\n";
[$rc, $out] = $run('--to "+211 927 797 217" --dry-run --pause 0 --name "Family Shoppers"');   // spaces and a plus sign, as a person types it
t('exit code 0',                                  $rc, 0);
is_(strpos($out, 'DRY RUN — nothing is sent') !== false, 'the mode is stated');
$entries = $log();
t('twelve messages logged: ten samples, header, footer', count($entries), 12);
$phones = array_unique(array_map(fn($e) => (string)($e['phone'] ?? ''), $entries));
t('all to the number given, digits only',         $phones, ['211927797217']);
$events = array_map(fn($e) => (string)($e['event'] ?? ''), $entries);
foreach (['ops_invoice_created', 'ops_pre_due_d7', 'ops_pre_due_d3', 'ops_pre_due_d1', 'ops_low_balance', 'ops_payment_received',
          'ops_invoice_auto_paid', 'ops_invoice_partial_credit', 'ops_installation_scheduled', 'ops_renewal_reminder'] as $ev) {
    is_(in_array($ev, $events, true), "sample logged: {$ev}");
}
t('the header comes first and the footer last',
  [$events[0], $events[count($events) - 1]], ['wa_lifecycle_test', 'wa_lifecycle_test']);
is_(strpos((string)($entries[0]['message'] ?? ''), 'TEST') !== false,      'the header says TEST');
is_(strpos((string)($entries[0]['message'] ?? ''), 'invented data') !== false, 'and that the data is invented');
$texts = implode("\n", array_map(fn($e) => (string)($e['message'] ?? ''), $entries));
is_(strpos($texts, 'Family Shoppers') !== false,                           'the samples address the name given');
is_(strpos($texts, 'INV-TEST-') !== false,                                 'and name invented invoices');
is_(preg_match('/\[\d+\/10\]\s+invoice\s+ops_invoice_created\s+DRY RUN/', $out) === 1, 'each message is reported with its outcome', $out);

echo "\n3. --only narrows the run\n";
@unlink($tmp . '/dry_run_notification_log.json');
[$rc, $out] = $run('--to 256700000001 --dry-run --pause 0 --only invoice,receipt');
t('exit code 0',                                  $rc, 0);
$events = array_map(fn($e) => (string)($e['event'] ?? ''), $log());
t('two samples plus header and footer',           count($events), 4);
is_(in_array('ops_invoice_created', $events, true) && in_array('ops_payment_received', $events, true) && !in_array('ops_pre_due_d7', $events, true),
    'only the two asked for');
[$rc, $out] = $run('--to 256700000001 --dry-run --only nonsense');
t('an unknown key is refused',                    $rc, 1);

echo "\n4. The tool touches no customer record\n";
$src = (string)file_get_contents($root . '/tools/wa_lifecycle_test.php');
is_(strpos($src, '->post(') === false && strpos($src, '->patch(') === false && strpos($src, 'CrmApiClient') === false,
    'no uCRM write, no uCRM client at all');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
