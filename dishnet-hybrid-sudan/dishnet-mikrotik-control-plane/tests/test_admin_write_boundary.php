<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use Dn\Db\Database;
use Dn\Devices\DeviceRegistry;
use Dn\Tenancy\TenantContext;

/**
 * The Admin WRITE boundary — migration 020, docs/84 W-1 / W-2 / W-3.
 *
 * No Admin write route is bound yet. These tests assert the floor such routes
 * will stand on, by execution rather than by reading the migration.
 */

$owner  = Database::owner();
$ins    = Database::inspector();          // BYPASSRLS — the only honest way to
                                          // read mt_audit_log across tenants
$admin  = Database::admin();
$app    = Database::app();
$ids    = seed_two_customers($ins);
$A      = $ids['A']; $B = $ids['B'];
$ctxApp = new TenantContext($app);

$serial = 0;
$stock  = function (Database $db = null) use (&$serial, $admin): string {
    $serial++;
    return $admin->one('SELECT id FROM mt_device_register(?,?,?,?,?,?)',
        ["SN-W{$serial}", 'RB951', '7.14', "pk{$serial}", "10.9.0.{$serial}",
         'staff:alice'])['id'];
};
$auditFor = fn(string $targetId): array => $ins->query(
    'SELECT action, actor, actor_kind, source, detail FROM mt_audit_log
      WHERE target_id = ? ORDER BY at', [$targetId]);

// ===========================================================================
// W-1. Audit is written by the function, in the same transaction
// ===========================================================================

t('W-1.1 — a successful mutation creates exactly the expected audit event');

$d1 = $stock();
$rows = $auditFor($d1);
is_(count($rows), 1, 'registering a device wrote one audit row, from inside the function');
is_($rows[0]['action'], 'device.registered', 'the action names what happened');
is_($rows[0]['actor'], 'staff:alice', 'the actor is the one the function was given');
is_($rows[0]['actor_kind'], 'staff', 'recorded as staff, not as a principal or the system');
$detail = json_decode($rows[0]['detail'], true);
is_($detail['serial'], 'SN-W1', 'the detail identifies the device');
is_(array_key_exists('wg_pubkey', $detail) || array_key_exists('tunnel_ip', $detail), false,
    'the trust anchor and tunnel address are NOT copied into the audit detail');

$admin->one('SELECT id FROM mt_device_assign(?,?,?,?,?)',
    [$d1, $A['customer'], $A['site'], 'Lobby AP', 'staff:alice']);
$rows = $auditFor($d1);
is_(count($rows), 2, 'assigning wrote a second row');
is_($rows[1]['action'], 'device.assigned', 'and named the assignment');
$detail = json_decode($rows[1]['detail'], true);
is_($detail['to_customer'], $A['customer'], 'the row records where the device went');
is_($detail['from_customer'], null, 'and where it came from — unowned stock');

$admin->one('SELECT mt_device_set_secret(?,?,?,?) AS ok',
    [$d1, 'dn-mgmt', 'sealed-value-here', 'staff:alice']);
$rows = $auditFor($d1);
is_($rows[2]['action'], 'device.secret_rotated', 'rotating the credential is audited');
is_(str_contains($rows[2]['detail'], 'sealed-value-here'), false,
    'the sealed secret is NOT in the audit detail — that it rotated is the fact, not what to');

$admin->one('SELECT mt_device_set_state(?,?,?) AS r', [$d1, 'shipped', 'staff:bob']);
$rows = $auditFor($d1);
is_($rows[3]['action'], 'device.state_changed', 'a state change is audited');
is_($rows[3]['actor'], 'staff:bob', 'by whoever performed THAT act, not the last one');
is_(json_decode($rows[3]['detail'], true)['from'], 'staged', 'with the state it left');

$admin->one('SELECT * FROM mt_device_set_wan(?,?,?)', [$d1, 'ether1', 'staff:carol']);
is_($auditFor($d1)[4]['action'], 'device.wan_established', 'establishing the WAN fact is audited');

$admin->one('SELECT mt_device_set_desired(?,?::jsonb,?) AS ok',
    [$d1, '{"ip/hotspot":{"x":"y"}}', 'staff:alice']);
$rows = $auditFor($d1);
is_($rows[5]['action'], 'device.desired_set', 'setting desired config is audited');
is_($rows[5]['detail'], '{}', 'but the free-form document itself is withheld (D-2)');

$newCust = $admin->one('SELECT mt_customer_create(?,?) AS id', ['Audited Ltd', 'staff:dave'])['id'];
$rows = $auditFor($newCust);
is_(count($rows), 1, 'onboarding a customer writes its own audit row');
is_($rows[0]['action'], 'customer.created', 'named as a creation');
is_($rows[0]['actor'], 'staff:dave', 'attributed to whoever onboarded them');

t('W-1.1 — all seven state-changing functions audit; none is left out');
$audited = $ins->query(
    "SELECT p.proname FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
      WHERE n.nspname = 'public' AND p.proname IN
            ('mt_device_register','mt_device_assign','mt_device_set_state',
             'mt_device_set_secret','mt_device_set_desired','mt_device_set_wan',
             'mt_customer_create')
        AND pg_get_functiondef(p.oid) LIKE '%mt_audit_write%'
      ORDER BY p.proname");
is_(array_column($audited, 'proname'),
    ['mt_customer_create','mt_device_assign','mt_device_register','mt_device_set_desired',
     'mt_device_set_secret','mt_device_set_state','mt_device_set_wan'],
    'every one of the seven calls the audit writer');

t('W-1.2 — a failed mutation creates no committed audit event');

$before = count($ins->query('SELECT id FROM mt_audit_log'));
$d2 = $stock();
$afterRegister = count($ins->query('SELECT id FROM mt_audit_log'));
is_($afterRegister, $before + 1, 'the registration that succeeded wrote exactly one row');

// A cross-customer assignment is refused by the W-2 constraint. The audit row
// this function writes must go down with it.
throws_(fn() => $admin->one('SELECT id FROM mt_device_assign(?,?,?,?,?)',
        [$d2, $A['customer'], $B['site'], 'stolen', 'staff:mallory']),
    'violates foreign key', 'a cross-customer assignment is refused');
is_(count($ins->query('SELECT id FROM mt_audit_log')), $afterRegister,
    'and left NO audit row behind — the refusal is not recorded as an act that happened');
is_(count($ins->query("SELECT id FROM mt_audit_log WHERE actor = 'staff:mallory'")), 0,
    'nothing at all under that actor');

// A mutation aimed at a row that does not exist must not be audited either.
$ghost = '00000000-0000-4000-8000-000000000000';
$admin->one('SELECT mt_device_set_secret(?,?,?,?) AS ok', [$ghost, 'u', 'v', 'staff:alice']);
is_(count($ins->query('SELECT id FROM mt_audit_log WHERE target_id = ?', [$ghost])), 0,
    'a no-op against a missing device writes no audit row');

t('W-1.3 — audit and mutation commit or roll back together');

$d3 = $stock();
$pdo = $admin->pdo();
$pdo->beginTransaction();
$admin->one('SELECT id FROM mt_device_assign(?,?,?,?,?)',
    [$d3, $A['customer'], $A['site'], 'Rolled Back AP', 'staff:alice']);
// Inside the open transaction the audit row is visible to this session...
$seenInTxn = $admin->query(
    'SELECT 1 FROM mt_audit_log WHERE target_id = ?', [$d3]);
$pdo->rollBack();
is_(count($ins->query('SELECT id FROM mt_devices WHERE id = ? AND site_id IS NOT NULL', [$d3])), 0,
    'after rollback the device is not assigned');
is_(count($ins->query("SELECT id FROM mt_audit_log
                        WHERE target_id = ? AND action = 'device.assigned'", [$d3])), 0,
    'and no audit row survives — they are one transaction, not two');

$pdo->beginTransaction();
$admin->one('SELECT id FROM mt_device_assign(?,?,?,?,?)',
    [$d3, $A['customer'], $A['site'], 'Committed AP', 'staff:alice']);
$pdo->commit();
is_(count($ins->query("SELECT id FROM mt_audit_log
                        WHERE target_id = ? AND action = 'device.assigned'", [$d3])), 1,
    'and on commit both survive together');

t('W-1.4 — the actor cannot be forged by a request parameter');

// The actor is a function ARGUMENT supplied by the Admin identity boundary.
// It is not read from a session setting, because a GUC is something an HTTP
// caller can set — which is exactly the forgery this prevents.
$admin->exec("SELECT set_config('app.actor', 'staff:mallory', false)");
$admin->exec("SELECT set_config('dn.actor', 'staff:mallory', false)");
$d4 = $stock();
$admin->one('SELECT mt_device_set_state(?,?,?) AS r', [$d4, 'shipped', 'staff:alice']);
$row = $ins->one("SELECT actor FROM mt_audit_log
                   WHERE target_id = ? AND action = 'device.state_changed'", [$d4]);
is_($row['actor'], 'staff:alice', 'the audit records the argument, never the session setting');
is_(count($ins->query("SELECT id FROM mt_audit_log WHERE actor = 'staff:mallory'")), 0,
    'the planted session value reached nothing');

// An unattributed act is refused rather than recorded as nobody.
foreach ([['mt_device_set_state(?,?,?)', [$d4, 'connected', '']],
          ['mt_device_set_state(?,?,?)', [$d4, 'connected', '   ']],
          ['mt_device_assign(?,?,?,?,?)', [$d4, $A['customer'], $A['site'], 'x', null]]] as [$sql, $args]) {
    throws_(fn() => $admin->one("SELECT * FROM {$sql} AS r", $args),
        'requires the identity', "a blank or null actor is refused: {$sql}");
}

// And no HTTP-facing role may write an audit row directly, so none of them can
// claim an act that never happened.
foreach (['dnb_app', 'dnb_admin', 'dnb_adminwrite', 'dnb_adminapi', 'dnb_worker'] as $role) {
    is_($ins->one('SELECT has_function_privilege(?,
            ?, ?) AS p',
          [$role, 'mt_audit_write(uuid,text,text,text,text,text,text,jsonb)', 'EXECUTE'])['p'],
        false, "{$role} cannot call the audit writer directly");
}
is_($ins->one("SELECT has_function_privilege('dnb_def_prov',
        'mt_audit_write(uuid,text,text,text,text,text,text,jsonb)','EXECUTE') AS p")['p'],
    true, 'only the provisioning definer owner can — so audit follows the act');

t('W-1.5 — the audit log remains append-only');

throws_(fn() => $ins->exec("UPDATE mt_audit_log SET actor = 'staff:eve'"),
    'not', 'even a BYPASSRLS inspector cannot rewrite an audit row');
throws_(fn() => $ins->exec('DELETE FROM mt_audit_log'),
    'not', 'nor delete one');
throws_(fn() => $owner->exec('TRUNCATE mt_audit_log'),
    'not', 'nor truncate the table');
// The audit writer itself is INSERT-only: it holds no SELECT, so a bug in it
// cannot become a way to read the whole estate's history.
is_($ins->one("SELECT has_table_privilege('dnb_def_audit','mt_audit_log','SELECT') AS p")['p'],
    false, 'the audit writer cannot read the audit log');
is_($ins->one("SELECT has_table_privilege('dnb_def_audit','mt_audit_log','INSERT') AS p")['p'],
    true, 'it can only append');
foreach (['UPDATE', 'DELETE', 'TRUNCATE'] as $priv) {
    is_($ins->one('SELECT has_table_privilege(?,?,?) AS p',
        ['dnb_def_audit', 'mt_audit_log', $priv])['p'], false,
        "and holds no {$priv}");
}

// ===========================================================================
// W-2. The customer/site invariant binds every writer
// ===========================================================================

t('W-2.1 — a same-customer assignment succeeds');

$d5 = $stock();
$r = $admin->one('SELECT id, site_id, customer_id FROM mt_device_assign(?,?,?,?,?)',
    [$d5, $A['customer'], $A['site'], 'Matched AP', 'staff:alice']);
is_($r['site_id'], $A['site'], 'the device took the site it was given');
is_($r['customer_id'], $A['customer'], 'under the customer that owns it');

t('W-2.2 — a cross-customer assignment fails');

$d6 = $stock();
throws_(fn() => $admin->one('SELECT id FROM mt_device_assign(?,?,?,?,?)',
        [$d6, $A['customer'], $B['site'], 'Mismatched', 'staff:alice']),
    'violates foreign key',
    "customer A's device cannot be pointed at customer B's site");
is_($ins->one('SELECT site_id FROM mt_devices WHERE id = ?', [$d6])['site_id'], null,
    'and the device is left untouched');

t('W-2.3 — unassigned stock stays valid where the lifecycle requires it');

$d7 = $stock();
$row = $ins->one('SELECT customer_id, site_id, state FROM mt_devices WHERE id = ?', [$d7]);
is_($row['customer_id'], null, 'a freshly registered device belongs to nobody');
is_($row['site_id'], null, 'and sits at no site');
is_($row['state'], 'staged', 'which is a legal state, not a violation');
// Assigned to a customer but not yet to a site: the ordinary intermediate step.
$r = $admin->one('SELECT site_id, customer_id FROM mt_device_assign(?,?,?,?,?)',
    [$d7, $A['customer'], null, 'Unsited AP', 'staff:alice']);
is_($r['customer_id'], $A['customer'], 'a device may be owned before it is sited');
is_($r['site_id'], null, 'with no site at all');

t('W-2.4 — a direct table UPDATE cannot create a cross-customer pairing');

// This is the measured defect the constraint exists for: mt_device_assign was
// never the only writer (docs/73 §1.1).
//
// The attempt is made as the BYPASSRLS inspector ON PURPOSE. Under FORCE RLS
// the owner's UPDATE matches ZERO ROWS and therefore raises nothing — it would
// pass this test while proving only that RLS hid the row. That silent no-op is
// the exact defect docs/73 §1.2 records, so the strongest writer available is
// used instead: if the constraint stops a BYPASSRLS session, it stops anyone.
//
// Positive control first — without it, a refusal could just be a miss.
is_($ins->exec('UPDATE mt_devices SET name = ? WHERE id = ?', ['reached', $d5]), 1,
    'CONTROL: the inspector really does reach this row (1 row updated)');

throws_(fn() => $ins->exec('UPDATE mt_devices SET site_id = ? WHERE id = ?',
        [$B['site'], $d5]),
    'violates foreign key',
    'a BYPASSRLS session cannot re-point a device at another customer\'s site');
is_($ins->one('SELECT site_id FROM mt_devices WHERE id = ?', [$d5])['site_id'], $A['site'],
    'and the row still carries its own customer\'s site');

// dnb_admin holds blanket table DML (migration 015) and is the realistic
// bypass, so it is attempted inside a tenant context where RLS admits it.
$ctxAdmin = new TenantContext($admin);
$adminBroke = false;
try {
    $ctxAdmin->run($A['customer'], fn(Database $db) =>
        $db->exec('UPDATE mt_devices SET site_id = ? WHERE id = ?', [$B['site'], $d5]));
    $adminBroke = ($ins->one('SELECT site_id FROM mt_devices WHERE id = ?',
        [$d5])['site_id'] === $B['site']);
} catch (\Throwable) { /* refused, which is the desired outcome */ }
is_($adminBroke, false, 'and neither can dnb_admin, which holds blanket table DML');

// The other half: a site set with no customer would slip past a MATCH SIMPLE
// foreign key entirely, so the CHECK closes it.
throws_(fn() => $ins->exec('UPDATE mt_devices SET customer_id = NULL WHERE id = ?', [$d5]),
    'violates check', 'nor can the customer be stripped while a site remains');
throws_(fn() => $ins->exec(
        'INSERT INTO mt_devices (serial, model, site_id, state) VALUES (?,?,?,?)',
        ['SN-NOCUST', 'RB951', $A['site'], 'registered']),
    'violates check', 'nor can such a row be inserted in the first place');
// Deleting the site must still work, and must leave the device owned.
$spare = $ins->one('INSERT INTO mt_sites (customer_id, service_id, name)
                    VALUES (?,?,?) RETURNING id',
                   [$A['customer'], $A['service'], 'spare site']);
$d9 = $stock();
$admin->one('SELECT id FROM mt_device_assign(?,?,?,?,?)',
    [$d9, $A['customer'], $spare['id'], 'On Spare', 'staff:alice']);
$ins->exec('DELETE FROM mt_sites WHERE id = ?', [$spare['id']]);
$after = $ins->one('SELECT site_id, customer_id FROM mt_devices WHERE id = ?', [$d9]);
is_($after['site_id'], null, 'deleting a site clears the device\'s site');
is_($after['customer_id'], $A['customer'], 'without orphaning it from its customer');

t('W-2.5 — dnb_app cannot bypass the invariant');

// Two independent refusals. Which one fires is not the point; that the request
// role cannot produce a cross-customer pairing by any route is.
$broke = false;
try {
    $ctxApp->run($A['customer'], fn(Database $db) =>
        $db->exec('UPDATE mt_devices SET site_id = ? WHERE id = ?', [$B['site'], $d5]));
    $left = $ins->one('SELECT site_id FROM mt_devices WHERE id = ?', [$d5])['site_id'];
    $broke = ($left === $B['site']);
} catch (\Throwable) { /* refused, which is the desired outcome */ }
is_($broke, false, 'the request role cannot re-point a device at another customer\'s site');
is_($ins->one('SELECT has_function_privilege(?,?,?) AS p',
    ['dnb_app', 'mt_device_assign(uuid,uuid,uuid,text,text)', 'EXECUTE'])['p'], false,
    'and cannot call the assignment function at all');

t('W-2.6 — the Admin write path cannot bypass it either');

$aw = null;
try { $aw = Database::adminWrite(); } catch (\Throwable $e) { bad('adminWrite() cannot connect: ' . $e->getMessage()); }
if ($aw !== null) {
    $d8 = $stock();
    throws_(fn() => $aw->one('SELECT id FROM mt_device_assign(?,?,?,?,?)',
            [$d8, $A['customer'], $B['site'], 'Mismatched', 'staff:alice']),
        'violates foreign key', 'dnb_adminwrite is refused the same cross-customer pairing');
    $r = $aw->one('SELECT site_id FROM mt_device_assign(?,?,?,?,?)',
        [$d8, $A['customer'], $A['site'], 'Matched', 'staff:alice']);
    is_($r['site_id'], $A['site'], 'and is allowed the matched one');
    is_(count($ins->query("SELECT id FROM mt_audit_log
                            WHERE target_id = ? AND action = 'device.assigned'", [$d8])), 1,
        'writing through the Admin identity audits exactly once');
}

// ===========================================================================
// W-3. The dedicated Admin write identity
// ===========================================================================

t('W-3 — dnb_adminwrite holds EXECUTE on the approved functions and nothing else');

$approved = [
    'mt_device_register(text,text,text,text,text,text)',
    'mt_device_assign(uuid,uuid,uuid,text,text)',
    'mt_device_set_state(uuid,text,text)',
    'mt_device_set_secret(uuid,text,text,text)',
    'mt_device_set_desired(uuid,jsonb,text)',
    'mt_device_set_wan(uuid,text,text)',
    'mt_customer_create(text,text)',
    // 028 (docs/121 D-5): the Admin-plane enqueue function docs/118 D-2 named.
    'mt_device_provision_request(uuid,text,text)',
];
foreach ($approved as $fn) {
    is_($ins->one('SELECT has_function_privilege(?,?,?) AS p',
        ['dnb_adminwrite', $fn, 'EXECUTE'])['p'], true, "EXECUTE on {$fn}");
}

$tableGrants = $ins->query(
    "SELECT table_name, privilege_type FROM information_schema.role_table_grants
      WHERE grantee = 'dnb_adminwrite'");
is_($tableGrants, [], 'zero table privileges — no INSERT, UPDATE, DELETE or even SELECT');

$defaults = $ins->query(
    "SELECT defaclacl::text FROM pg_default_acl
      WHERE defaclacl::text LIKE '%dnb_adminwrite%'");
is_($defaults, [], 'and no default privileges waiting to grant it any');

$attrs = $ins->one("SELECT rolsuper, rolbypassrls, rolcreaterole, rolcreatedb, rolcanlogin
                      FROM pg_roles WHERE rolname = 'dnb_adminwrite'");
is_($attrs['rolsuper'], false, 'not a superuser');
is_($attrs['rolbypassrls'], false, 'does not bypass RLS');
is_($attrs['rolcreaterole'], false, 'cannot create roles');
is_($attrs['rolcreatedb'], false, 'cannot create databases');
is_($attrs['rolcanlogin'], true, 'but can log in, being a connection identity');
is_($ins->one("SELECT count(*)::int c FROM pg_auth_members m
                 JOIN pg_roles r ON r.oid = m.roleid
                WHERE m.member = 'dnb_adminwrite'::regrole")['c'], 0,
    'and is a member of no other role, so it inherits no privilege');

t('W-3 — it can reach no other function, and no arbitrary SQL');

// The estate read projections belong to dnb_adminapi; the write identity must
// not quietly double as a reader.
foreach (['mt_admin_customers()', 'mt_admin_routers(text,text,int,int)'] as $probe) {
    $exists = $ins->one('SELECT count(*)::int c FROM pg_proc p
                           JOIN pg_namespace n ON n.oid = p.pronamespace
                          WHERE n.nspname = \'public\'
                            AND p.oid::regprocedure::text = ?', [$probe])['c'];
    if ($exists) {
        is_($ins->one('SELECT has_function_privilege(?,?,?) AS p',
            ['dnb_adminwrite', $probe, 'EXECUTE'])['p'], false,
            "no EXECUTE on the read projection {$probe}");
    }
}
is_($ins->one("SELECT has_schema_privilege('dnb_adminwrite','public','CREATE') AS p")['p'],
    false, 'it cannot create anything in the schema');

t('W-3 — dnb_admin keeps the privileges it already had');

// docs/84 F-3: revoking them needs its own dependency audit, so this increment
// must NOT have removed them. Asserted so a later accidental revoke is caught.
foreach (['INSERT', 'UPDATE', 'DELETE', 'SELECT'] as $priv) {
    is_($ins->one('SELECT has_table_privilege(?,?,?) AS p',
        ['dnb_admin', 'mt_devices', $priv])['p'], true,
        "dnb_admin still holds {$priv} on mt_devices — untouched by this migration");
}

t('W-3 — the unaudited signatures are gone, not merely superseded');

foreach (['mt_device_assign(uuid,uuid,uuid,text)',
          'mt_device_set_state(uuid,text)',
          'mt_device_set_secret(uuid,text,text)',
          'mt_device_set_desired(uuid,jsonb)'] as $old) {
    is_($ins->one("SELECT count(*)::int c FROM pg_proc p
                     JOIN pg_namespace n ON n.oid = p.pronamespace
                    WHERE n.nspname = 'public' AND p.oid::regprocedure::text = ?",
        [$old])['c'], 0, "the unaudited {$old} no longer exists");
}

t('W-3 — no Admin write route is bound yet, and none may forge an actor');

$routes = file_get_contents(__DIR__ . '/../src/Api/AdminRoutes.php');
$code   = strip_php_comments($routes);
foreach (['mt_device_assign', 'mt_device_set_state', 'mt_device_set_secret',
          'mt_device_set_desired', 'mt_device_set_wan', 'mt_device_register',
          'mt_customer_create'] as $fn) {
    is_(str_contains($code, $fn), false,
        "AdminRoutes does not call {$fn} yet — write binding is a later gate");
}
// The guard that matters when they ARE bound: the actor may never come from
// the request. This scans real code, not comments.
//
// glob('src/**/*.php') matches exactly one directory level and silently misses
// anything nested deeper — a scanner that finds nothing always passes. So the
// scan recurses, and says how much it covered.
$phpFiles = [];
$rii = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(__DIR__ . '/../src', FilesystemIterator::SKIP_DOTS));
foreach ($rii as $f) {
    if ($f->isFile() && $f->getExtension() === 'php') { $phpFiles[] = $f->getPathname(); }
}
is_(count($phpFiles) >= 50, true,
    'META: the scan reaches the whole source tree (' . count($phpFiles) . ' files)');

$scanned = 0;
foreach ($phpFiles as $file) {
    $c = strip_php_comments(file_get_contents($file));
    if (!preg_match('/mt_(device|customer)_[a-z_]+\(/', $c)) { continue; }
    $scanned++;
    foreach (['req->body', '_POST', '_GET', 'req->query'] as $src) {
        is_(preg_match('/mt_(device|customer)_[a-z_]+\([^;]{0,400}' . preg_quote($src, '/') . '/', $c), 0,
            basename($file) . ": no write function takes its actor from {$src}");
    }
}
is_($scanned > 0, true,
    'META: at least one file really does call a write function (' . $scanned . ' scanned)');

exit(t_summary());
