<?php
// 5.18.97's rehearsal: ECO Bank's staff records, through the INSTALLED plugin's own store (appendWithId), as the staff
// screen keeps them — synthetic, every name RH97 and every address example.test:
//   admin     an active administrator: BK2 must show ECO Bank to them once the switch is on, and to nobody while it is off
//   inactive  an administrator switched off (is_active false): no reading counts them
//   demoted   an administrator since demoted to accountant, still active: their session copy may still say is_admin for
//             up to 300 s (the stale session) — the fresh record must refuse them (BK2's stale=0)
// The sandbox's own two staff (a technician and a seller) stay as they are: active, not administrators. Prints the three
// row ids as JSON, nothing else.
declare(strict_types=1);
[$_, $root, $data] = $argv + [null, '', ''];
if ((string)getenv('DN_VAULT_FILE') === '') { fwrite(STDERR, "refusing: DN_VAULT_FILE must point into the sandbox\n"); exit(2); }
if (!is_file($root . '/lib/SqliteStore.php') || !is_file($data . '/plugin.sqlite3')) { fwrite(STDERR, "refusing: no installed plugin or no store\n"); exit(2); }
foreach (['bootstrap_data', 'StoreInterface', 'JsonStore', 'SqliteStore'] as $l) require_once $root . '/lib/' . $l . '.php';
$GLOBALS['dataDir'] = $data;   // as the plugin's own bootstrap sets it
$s = SqliteStore::create($data);
$ids = [];
foreach ([['admin', 'RH97 Admin', 'admin', true, true], ['inactive', 'RH97 Inactive Admin', 'admin', true, false],
          ['demoted', 'RH97 Former Admin', 'accountant', false, true]] as [$k, $name, $role, $isAdmin, $active]) {
    $r = $s->appendWithId('retailers.json', ['name' => $name, 'email' => 'rh97-' . $k . '@example.test', 'role' => $role,
        'is_admin' => $isAdmin, 'is_active' => $active]);
    $ids[$k] = (int)($r['id'] ?? 0);
    if ($ids[$k] <= 0) { fwrite(STDERR, "step failed: $k\n"); exit(3); }
}
echo json_encode($ids), "\n";
