<?php
declare(strict_types=1);
/**
 * test_config_one_truth.php — a CLI config change reaches every reader.
 *
 * kyc_config.json is read two ways and they disagreed.
 *
 *   PluginConfig::load()  merges config.json then kyc_config.json, FILE LAST,
 *                         so the file wins. 76 files read this way.
 *   $store->load(...)     serves the copy SQLite imported once and never
 *                         refreshed. 66 files read THIS way, including
 *                         public.php, api/index.php, cron/master.php and
 *                         every cron under it.
 *
 * saveOverrides() — what set_config.php and set_evolution.php call — wrote
 * only the file. So a CLI change was invisible to 66 files, and the tool that
 * said SAVED was telling the truth about a copy half the plugin never reads.
 *
 * Observed, not hypothetical: `set_evolution.php --account dishnet_ug`
 * reported SAVED while cron_invoice_notify, cron_overdue_email and
 * cron_quote_wa — all store readers — carried on sending nothing.
 */
$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; } }

$root = dirname(__DIR__);
foreach (['StoreInterface','JsonStore','SqliteStore','PluginConfig'] as $c) require_once $root . '/lib/' . $c . '.php';

$dir = sys_get_temp_dir() . '/dn_cfg1_' . bin2hex(random_bytes(4));
@mkdir($dir, 0777, true);
$store = SqliteStore::create($dir);

// A key the store already holds, written the way the 22 admin screens write it.
$store->save('kyc_config.json', ['set_by_a_screen' => 'keep me', 'evo_instance_support' => 'old_value']);

echo "\nA CLI save reaches the store, not just the file\n";
[$ok, $err] = PluginConfig::saveOverrides($dir, ['evo_instance_account' => 'dishnet_ug']);
is_($ok === true, 'the save succeeds', (string)$err);
$file = json_decode((string)file_get_contents($dir . '/kyc_config.json'), true) ?: [];
is_(($file['evo_instance_account'] ?? '') === 'dishnet_ug', 'the file has it');
$fromStore = $store->load('kyc_config.json');
is_(($fromStore['evo_instance_account'] ?? '') === 'dishnet_ug',
    'and so does the store — the 66 store readers now see it',
    json_encode($fromStore));

echo "\nWithout deleting what the screens put there\n";
is_(($fromStore['set_by_a_screen'] ?? '') === 'keep me',
    'a store-only key survives the mirror',
    'twenty-two screens write the store directly; replacing it would lose them');

echo "\nAn override written by CLI wins over a stale store value\n";
[$ok2] = PluginConfig::saveOverrides($dir, ['evo_instance_support' => 'dishnet_ug']);
$fromStore = $store->load('kyc_config.json');
is_($ok2 === true && ($fromStore['evo_instance_support'] ?? '') === 'dishnet_ug',
    'the store value is replaced, not merged around',
    json_encode($fromStore['evo_instance_support'] ?? null));

echo "\nClearing an override clears it on BOTH sides\n";
[$ok3] = PluginConfig::saveOverrides($dir, ['evo_instance_account' => '']);
$file  = json_decode((string)file_get_contents($dir . '/kyc_config.json'), true) ?: [];
$fromStore = $store->load('kyc_config.json');
is_($ok3 === true, 'the clear succeeds');
is_(!array_key_exists('evo_instance_account', $file), 'gone from the file');
is_(!array_key_exists('evo_instance_account', $fromStore),
    'and gone from the store — otherwise --clear changes nothing for 66 files',
    json_encode($fromStore));
is_(($fromStore['set_by_a_screen'] ?? '') === 'keep me', 'and the screen key is still untouched');

echo "\nBoth readers now agree on what was written\n";
[$ok4] = PluginConfig::saveOverrides($dir, ['timezone' => 'Africa/Kampala']);
$viaPlugin = PluginConfig::load($root, $dir);
$viaStore  = $store->load('kyc_config.json');
is_(($viaPlugin['timezone'] ?? '') === 'Africa/Kampala', 'PluginConfig::load sees it');
is_(($viaStore['timezone']  ?? '') === 'Africa/Kampala', 'and $store->load sees it too');

echo "\nA data directory with no store yet keeps its file\n";
// This caught a real hazard: mirroring used to call SqliteStore::create(),
// whose first-boot migration imports every *.json and renames it .migrated —
// so saving into a fresh directory carried off the file just written.
$ro = sys_get_temp_dir() . '/dn_cfg1_ro_' . bin2hex(random_bytes(4));
@mkdir($ro, 0777, true);
[$ok5, $err5] = PluginConfig::saveOverrides($ro, ['timezone' => 'Africa/Kampala']);
is_($ok5 === true, 'the save reports success', (string)$err5);
is_(is_file($ro . '/kyc_config.json'),
    'and the canonical file SURVIVES — no store was created to swallow it',
    implode(', ', array_diff(scandir($ro), ['.','..'])));
is_(!is_file($ro . '/plugin.sqlite3'), 'no database was created as a side effect');

echo "\nSecrets are still refused\n";
[$ok6, $err6] = PluginConfig::saveOverrides($dir, ['admin_token' => 'nope']);
is_($ok6 === false, 'admin_token is rejected');
is_(strpos((string)$err6, 'uCRM Configuration') !== false, 'pointing at the right screen', (string)$err6);

foreach ([$dir, $ro] as $d) { @array_map('unlink', glob($d . '/*') ?: []); @rmdir($d); }
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
