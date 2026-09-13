<?php
declare(strict_types=1);
/**
 * test_dr_kit_map.php — the pairing that must never be guessed.
 *
 * dishnet-data-report needs KIT=SL pairs typed into its Sync Status screen
 * before it will fetch telemetry for anything. South Sudan has been fed by
 * hand for years; Uganda never was, which is why it reports "Stale KITs 0 / 0"
 * and collects nothing.
 *
 * We already know every pair — equipment_assignments captures the serial and
 * the service line together at installation — so this exports rather than
 * retypes. The one thing it must never do is fill a gap: a guessed pairing
 * puts one customer's telemetry on another customer's row, and that figure
 * gets used to bill them.
 */
require_once dirname(__DIR__) . '/lib/StoreInterface.php';
require_once dirname(__DIR__) . '/lib/JsonStore.php';
require_once dirname(__DIR__) . '/lib/SqliteStore.php';
require_once dirname(__DIR__) . '/lib/StockService.php';
require_once dirname(__DIR__) . '/lib/MigrationRunner.php';
require_once dirname(__DIR__) . '/lib/EquipmentAssignment.php';

$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; } }

$base = sys_get_temp_dir() . '/dn_km_' . bin2hex(random_bytes(4));
@mkdir($base . '/plugins/dishnet-hybrid-sudan', 0777, true);
@mkdir($base . '/data', 0777, true);

$store = SqliteStore::create($base . '/data');
$pdo   = $store->getPdo();
$stock = StockService::fromStore($store, $base . '/data');
$stock->ensureTables();
foreach (MigrationRunner::splitStatements((string)file_get_contents(
        dirname(__DIR__) . '/migrations/068_equipment_assignments.sql')) as $q) $pdo->exec($q);
$cat = (int)($stock->saveCategory(['title' => 'Kit', 'sku' => 'K',
    'service_type' => 'starlink', 'track_mode' => 'serial'])['id'] ?? 0);

// A Mini dish (the case data-report cannot resolve on its own), a standard kit
// on a different account, and one whose service line was never recorded.
foreach ([['KIT4M03465049J8J', 11, 1, 'SL-DF-8603545-59125-61', 'ACC-DF-15973474-59163-60'],
          ['KIT404246364BX6',   7, 2, 'SL-DF-15754766-41032-7', 'ACC-DF-15744579-40001-43'],
          ['KITNOLINE0000099',  9, 3, '', '']] as [$sn, $cl, $sv, $sl, $ac]) {
    $u = (int)$stock->createUnit(['category_id' => $cat, 'serial_number' => $sn], 7, 'b')['id'];
    $stock->install($u, array_filter(['crm_client_id' => $cl, 'crm_service_id' => $sv,
        'starlink_service_line' => $sl, 'starlink_account' => $ac]), 7, 'b');
}

$run = static function (string $args = '') use ($base): array {
    $out = []; $rc = 0;
    exec(sprintf('DN_PLUGIN_ROOT=%s DN_DATA_DIR=%s php %s %s 2>&1',
        escapeshellarg($base . '/plugins/dishnet-hybrid-sudan'),
        escapeshellarg($base . '/data'),
        escapeshellarg(dirname(__DIR__) . '/tools/dr_kit_map.php'), $args), $out, $rc);
    return [implode("\n", $out), $rc];
};

echo "\nThe pairs we already know\n";
[$o, $rc] = $run();
t('it exits clean', $rc, 0);
is_(strpos($o, 'KIT4M03465049J8J=SL-DF-8603545-59125-61') !== false,
    'the Mini dish is paired', substr($o, 0, 400));
is_(strpos($o, 'KIT404246364BX6=SL-DF-15754766-41032-7') !== false,
    'and the standard kit');
is_(strpos($o, '2 pair(s)') !== false, 'two, counted');

echo "\nIt says which account each pair is on\n";
is_(strpos($o, 'ACC-DF-15973474-59163-60') !== false && strpos($o, 'ACC-DF-15744579-40001-43') !== false,
    'both accounts are named');
is_(strpos($o, 'cookie that cannot see the') !== false,
    'and why that matters — a correct pairing on an unreachable account still fetches nothing');

echo "\nWhat it refuses to invent\n";
is_(strpos($o, 'KITNOLINE0000099') !== false, 'the unmappable kit is listed');
is_(strpos($o, 'CANNOT be mapped') !== false, 'as unmappable, not omitted');
is_(strpos($o, 'no Starlink service line was recorded') !== false, 'with the reason');
is_(preg_match('/KITNOLINE0000099=/', $o) === 0,
    'and NO pair is emitted for it — a guess would bill the wrong customer');

echo "\n--paste is only the lines, so it can go straight into their box\n";
[$p, $prc] = $run('--paste');
t('it exits clean', $prc, 0);
$lines = array_values(array_filter(explode("\n", trim($p))));
t('exactly two lines', count($lines), 2);
foreach ($lines as $l) {
    is_((bool)preg_match('/^KIT[0-9A-Z]+=SL-[0-9A-Z-]+$/', $l), "clean pair: {$l}");
}
is_(strpos($p, 'pair(s)') === false && strpos($p, '─') === false,
    'and no headings a person would have to delete');

echo "\nNothing to map is said, not shown as success\n";
$empty = sys_get_temp_dir() . '/dn_km_e_' . bin2hex(random_bytes(4));
@mkdir($empty . '/plugins/dishnet-hybrid-sudan', 0777, true);
@mkdir($empty . '/data', 0777, true);
SqliteStore::create($empty . '/data');
$pdo2 = SqliteStore::create($empty . '/data')->getPdo();
foreach (MigrationRunner::splitStatements((string)file_get_contents(
        dirname(__DIR__) . '/migrations/068_equipment_assignments.sql')) as $q) $pdo2->exec($q);
$out2 = []; $rc2 = 0;
exec(sprintf('DN_PLUGIN_ROOT=%s DN_DATA_DIR=%s php %s --paste 2>&1',
    escapeshellarg($empty . '/plugins/dishnet-hybrid-sudan'), escapeshellarg($empty . '/data'),
    escapeshellarg(dirname(__DIR__) . '/tools/dr_kit_map.php')), $out2, $rc2);
t('--paste with nothing to say exits non-zero', $rc2, 1);
t('and prints no pairs', trim(preg_replace('/^\[.*$/m', '', implode("\n", $out2))), '');

echo "\nIt writes nothing\n";
is_(!is_dir($base . '/plugins/dishnet-data-report'),
    'it does not create the other plugin');
$before = md5_file($base . '/data/plugin.sqlite3');
$run();
t('and our own database is untouched', md5_file($base . '/data/plugin.sqlite3'), $before);

exec('rm -rf ' . escapeshellarg($base) . ' ' . escapeshellarg($empty));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
