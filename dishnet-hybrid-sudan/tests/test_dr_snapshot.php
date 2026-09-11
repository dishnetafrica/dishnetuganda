<?php
declare(strict_types=1);
/**
 * test_dr_snapshot.php — the upgrade that strands blocked customers.
 *
 * dishnet-data-report keeps everything it knows in <plugin>/data, which uCRM
 * DELETES when the plugin is upgraded. Its own backup writes to
 * <plugin>/data/backups — inside the directory being deleted — and its file
 * list omits the two that matter most:
 *
 *   wifi_test_block_state.json   who is currently blocked
 *   wifi_router_map.json         which router is behind which dish
 *
 * Blocking a customer changes their SSID and password. Lose that first file
 * while people are blocked and they stay cut off, with nothing anywhere
 * recording which routers to go and fix. Not "degraded" — a customer
 * permanently off the internet and no way to find them.
 *
 * So the snapshot goes into THIS plugin's data directory, which survives an
 * upgrade of either plugin, and takes both.
 */
require_once dirname(__DIR__) . '/lib/SiblingPlugin.php';

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; } }

// A plugins directory with both plugins in it, laid out as uCRM lays one out.
$base = sys_get_temp_dir() . '/dn_drsnap_' . bin2hex(random_bytes(4));
$hyb  = $base . '/dishnet-hybrid-sudan';
$drD  = $base . '/dishnet-data-report/data';
$data = $base . '/.dishnet-hybrid-sudan-data';
@mkdir($hyb, 0777, true); @mkdir($drD, 0777, true); @mkdir($data, 0777, true);

file_put_contents($drD . '/wifi_test_block_state.json',
    json_encode(['Router-118' => ['blocked_at' => '2026-09-11', 'kit' => 'KIT401723651PG7'],
                 'Router-204' => ['blocked_at' => '2026-09-10', 'kit' => 'KIT999999999ZZ9']]));
file_put_contents($drD . '/wifi_router_map.json',
    json_encode(['Router-118' => ['kit_serial' => 'KIT401723651PG7']]));
file_put_contents($drD . '/dr_accounts.json', json_encode(['ACC-1' => ['cookie' => 'encrypted']]));
file_put_contents($drD . '/sl_usage.json', json_encode(['KIT401723651PG7' => ['gb' => 42]]));

// The real tool, told where the staged plugins directory is. __DIR__ resolves
// through symlinks, so linking the code into the staging area would send it
// straight back to the real checkout — DN_PLUGIN_ROOT is the seam that works.
$runIn = function (string $args = '') use ($root, $hyb, $data): array {
    $out = []; $rc = 0;
    exec(sprintf('DN_PLUGIN_ROOT=%s DN_DATA_DIR=%s php %s %s 2>&1',
         escapeshellarg($hyb), escapeshellarg($data),
         escapeshellarg($root . '/tools/dr_snapshot.php'), $args), $out, $rc);
    return [implode("\n", $out), $rc];
};

// ── The survey ──────────────────────────────────────────────────────────────
echo "\nWhat it would take\n";
[$o, $rc] = $runIn();
is_(strpos($o, 'wifi_test_block_state.json') !== false, 'the block state is listed', substr($o, 0, 300));
is_(strpos($o, 'wifi_router_map.json') !== false, 'and the router map');
is_(strpos($o, 'dr_accounts.json') !== false, 'and the Starlink accounts');
is_(strpos($o, 'cannot be reconstructed') !== false,
    'saying which one cannot be rebuilt at all');
is_($rc === 0, 'a survey exits clean');
is_(!is_dir($data . '/dr_snapshots'), 'and writes nothing without --save');

echo "\nIt refuses to be quiet about blocked customers\n";
is_(strpos($o, '2 router(s) are BLOCKED right now') !== false,
    'it counts who is blocked', substr($o, -400));
is_(strpos($o, 'which routers to restore') !== false,
    'and says what upgrading now would cost');

// ── Taking one ──────────────────────────────────────────────────────────────
echo "\nTaking a snapshot\n";
[$o, $rc] = $runIn('--save');
is_($rc === 0, 'it succeeds', $o);
$snaps = glob($data . '/dr_snapshots/*') ?: [];
t('one snapshot directory', count($snaps), 1);
$files = glob($snaps[0] . '/*.json') ?: [];
t('four files taken', count($files), 4);
$names = array_map('basename', $files);
sort($names);
t('including both the ones its own backup omits', $names,
  ['dr_accounts.json', 'sl_usage.json', 'wifi_router_map.json', 'wifi_test_block_state.json']);
$saved = json_decode((string)file_get_contents($snaps[0] . '/wifi_test_block_state.json'), true);
is_(isset($saved['Router-118']), 'and the block state came across intact');

// The whole point: it is OUTSIDE the directory uCRM deletes.
is_(strpos(realpath($snaps[0]), realpath($base . '/dishnet-data-report')) !== 0,
    'THE SNAPSHOT IS NOT INSIDE THE PLUGIN uCRM REPLACES', realpath($snaps[0]));

// ── The upgrade ─────────────────────────────────────────────────────────────
echo "\nThe upgrade that deletes everything\n";
exec('rm -rf ' . escapeshellarg($base . '/dishnet-data-report/data'));
@mkdir($drD, 0777, true);   // uCRM puts back an empty plugin
is_(!is_file($drD . '/wifi_test_block_state.json'), 'the block state is gone');
SiblingPlugin::reset();

[$o, $rc] = $runIn('--list');
is_(strpos($o, basename($snaps[0])) !== false, 'the snapshot is still listed', $o);

[$o, $rc] = $runIn('--restore ' . basename($snaps[0]));
is_($rc === 1 && strpos($o, '--yes') !== false, 'a restore asks before overwriting', $o);
is_(!is_file($drD . '/wifi_test_block_state.json'), 'and changed nothing yet');

[$o, $rc] = $runIn('--restore ' . basename($snaps[0]) . ' --yes');
is_($rc === 0, 'the restore succeeds', $o);
$back = json_decode((string)@file_get_contents($drD . '/wifi_test_block_state.json'), true);
is_(is_array($back) && isset($back['Router-118']) && isset($back['Router-204']),
    'BOTH BLOCKED ROUTERS ARE KNOWN AGAIN — those customers can be restored');
is_(is_file($drD . '/dr_accounts.json'), 'and the Starlink session too, so no re-login');

echo "\nWhat it will not do\n";
[$o, $rc] = $runIn('--restore ../../../etc');
is_($rc === 2, 'a snapshot name that climbs out of the directory is refused', $o);
[$o, $rc] = $runIn('--restore nope');
is_($rc === 1 && stripos($o, 'No snapshot') !== false, 'an unknown name is reported', $o);
[$o, $rc] = $runIn('--snapshot');
is_($rc === 2 && strpos($o, 'Unknown option') !== false, 'and a typed flag is named', $o);

// A restore over live files keeps what it replaces.
file_put_contents($drD . '/wifi_router_map.json', json_encode(['Router-NEW' => []]));
$runIn('--restore ' . basename($snaps[0]) . ' --yes');
is_(count(glob($drD . '/wifi_router_map.json.before-restore.*')) === 1,
    'restoring the wrong snapshot does not destroy the current state either');

exec('rm -rf ' . escapeshellarg($base));
echo "\n  {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
