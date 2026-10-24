<?php
// 5.18.100's rehearsal: its own reading of who the Salesperson numbers card may offer, read-only, on the live sandbox store
// — never through SalesNumbersAdmin, whose rule is what is being checked:
//   php rh100-oracle.php <plugin root> <data dir>
// The staff rows are loaded through the given root's own store (SqliteStore::load('retailers.json'), as the card loads
// them); the rule is this file's: a salesperson is an active row whose role, trimmed and lower-cased, is sales, sales_staff
// or field_agent (an administrator's account included, as 5.18.99); a technician an active row whose role is support,
// support_leader or support_engineer and that has no is_admin. Also the registry's numbers by their owner's kind
// (wa_channels, read with PDO, read-only) and wa_inbox_roles as the store row holds it. Prints JSON of counts and role
// names only — never a name, an id or a phone.
declare(strict_types=1);
[$_, $root, $data] = $argv + [null, '', ''];
if ((string)getenv('DN_VAULT_FILE') === '') { fwrite(STDERR, "refusing: DN_VAULT_FILE must point into the sandbox\n"); exit(2); }
if (!is_file($root . '/lib/SqliteStore.php') || !is_file($data . '/plugin.sqlite3')) { fwrite(STDERR, "refusing: no plugin or no store\n"); exit(2); }
foreach (['bootstrap_data', 'StoreInterface', 'JsonStore', 'SqliteStore'] as $l) require_once $root . '/lib/' . $l . '.php';
$GLOBALS['dataDir'] = $data;
$s = SqliteStore::create($data);
$SALES = ['sales', 'sales_staff', 'field_agent']; $TECH = ['support', 'support_leader', 'support_engineer'];
$kind = []; $o = ['rows' => 0, 'active' => 0, 'sales' => 0, 'tech' => 0, 'techadmin' => 0, 'unnamedtech' => 0];
foreach ((array)($s->load('retailers.json') ?? []) as $r) {
    if (!is_array($r) || (int)($r['id'] ?? 0) <= 0) continue;
    $o['rows']++; $id = (int)$r['id'];
    $role = strtolower(trim((string)($r['role'] ?? '')));
    if (empty($r['is_active'])) { $kind[$id] = 'inactive'; continue; }
    $o['active']++;
    if (in_array($role, $SALES, true)) { $kind[$id] = 'sales'; $o['sales']++; }
    elseif (in_array($role, $TECH, true)) {
        if (!empty($r['is_admin'])) { $kind[$id] = 'techadmin'; $o['techadmin']++; }
        else { $kind[$id] = 'tech'; $o['tech']++; if (trim((string)($r['name'] ?? '')) === '') $o['unnamedtech']++; }
    } else $kind[$id] = 'other';
}
$num = ['sales' => 0, 'tech' => 0, 'techadmin' => 0, 'inactive' => 0, 'other' => 0, 'unknown' => 0, 'none' => 0];
$p = new PDO('sqlite:' . $data . '/plugin.sqlite3', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
if ($p->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'wa_channels'")->fetchColumn() !== false) {
    foreach ($p->query('SELECT owner_type, owner_staff_id FROM wa_channels')->fetchAll(PDO::FETCH_NUM) as [$t, $sid]) {
        if ((string)$t !== 'staff') { $num['none']++; continue; }
        $num[$kind[(int)$sid] ?? 'unknown']++;
    }
}
$ns = []; foreach ($num as $k => $v) $ns[] = "$k:$v";
$o['numbers'] = implode(',', $ns);
$row = $p->query('SELECT data FROM kyc_config WHERE id = 0 LIMIT 1')->fetchColumn(); $p = null;
$cfg = is_string($row) ? (json_decode($row, true) ?: []) : null;
$o['inbox'] = $cfg === null ? 'norow' : (array_key_exists('wa_inbox_roles', $cfg) ? (trim((string)(is_scalar($cfg['wa_inbox_roles']) ? $cfg['wa_inbox_roles'] : '')) === '' ? 'empty' : 'set') : 'unset');
echo json_encode($o), "\n";
