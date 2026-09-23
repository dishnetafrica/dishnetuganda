<?php
/**
 * O-1 closed — migration 029, docs/124.
 *
 * A site's service must belong to the site's own operator. Before 029 mt_sites
 * carried two independent single-column foreign keys and nothing required them
 * to agree, so operator A could attach its site to operator B's service by
 * literal UUID, and B could then never end that service (docs/104-106).
 *
 * What is proved here, by execution: the constraint's exact shape; that the
 * attack is refused BY NAME for the customer-facing role under row security,
 * with the same-operator write accepted in the same transaction; that an
 * existing site cannot be re-pointed; that the refusal binds a role that
 * bypasses row security too (integrity is enforced BELOW it); that a service's
 * operator is fixed while a site references it (S-A, by constraint); that the
 * delete behaviour is unchanged; that isolation is unchanged; T-9, the control
 * on the control — drop the constraint inside a rolled-back transaction and the
 * identical attack succeeds; and, on a throwaway database, that the migration
 * run AS THE OWNER refuses a violating estate, names the rows, changes nothing
 * and leaves FORCE ROW LEVEL SECURITY on — while the superseded candidate DDL
 * cannot be applied by the owner at all, even to a clean estate.
 *
 * NOT PROVED, and not claimed: anything about production. This suite runs on
 * the development cluster; production application is its own act (docs/124).
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use Dn\Db\Database;
use Dn\Db\Migrator;
use Dn\Tenancy\TenantContext;

$root = dirname(__DIR__);
$ins  = Database::inspector();   // BYPASSRLS fixture identity: reads state, proves nothing by itself
$app  = Database::app();         // the customer-facing role, bound by row security
$ids  = seed_two_customers($ins);
$A = $ids['A']; $B = $ids['B'];
$FK = 'mt_sites_service_customer_fkey';
$UQ = 'mt_services_id_customer_key';
$MIG = '029_o1_site_service_same_operator.sql';

/** Run $fn as dnb_app inside $customer's context, in a transaction that is ALWAYS rolled back. */
$asOperator = static function (string $customer, callable $fn) use ($app) {
    $pdo = $app->pdo();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("SELECT set_config('app.customer_id', ?, true)")->execute([$customer]);
        return $fn($app);
    } finally {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
    }
};
/** One statement under a savepoint: ['ok', 'n', 'state', 'msg']. A refusal does not poison the transaction. */
$try = static function (Database $db, string $sql, array $params = []): array {
    try {
        $n = $db->attempt(static fn(Database $d) => $d->exec($sql, $params));
        return ['ok' => true, 'n' => $n, 'state' => null, 'msg' => ''];
    } catch (PDOException $e) {
        return ['ok' => false, 'n' => 0, 'state' => (string) ($e->errorInfo[0] ?? $e->getCode()), 'msg' => $e->getMessage()];
    }
};
$sites    = static fn(): int => (int) $ins->one('SELECT count(*)::int AS c FROM mt_sites')['c'];
$services = static fn(): int => (int) $ins->one('SELECT count(*)::int AS c FROM mt_services')['c'];
$audit    = static fn(): int => (int) $ins->one('SELECT count(*)::int AS c FROM mt_audit_log')['c'];
$cols = static function (Database $db, string $table, string $arr): array {
    // attnum arrays rendered as column names, in constraint order
    return array_column($db->query(
        "SELECT a.attname FROM unnest({$arr}) WITH ORDINALITY k(n, i)
           JOIN pg_attribute a ON a.attrelid = '{$table}'::regclass AND a.attnum = k.n ORDER BY k.i"), 'attname');
};

// ===========================================================================
t('1. MIGRATION FACTS — the composite key, its supporting UNIQUE, both old keys kept, FORCE restored');
$c = $ins->one("SELECT contype, convalidated, condeferrable, confdeltype, confupdtype, confmatchtype,
                       confrelid::regclass::text AS ref, conkey::int[] AS k, confkey::int[] AS fk
                  FROM pg_constraint WHERE conname = ? AND conrelid = 'mt_sites'::regclass", [$FK]);
is_($c !== null && $c['contype'] === 'f', true, "{$FK} exists on mt_sites and is a foreign key");
is_($c['convalidated'] ?? null, true, 'it is VALIDATED — enforced for every existing row, not declared NOT VALID');
is_($c['condeferrable'] ?? null, false, 'it is not deferrable: it binds at the end of the statement, as RESTRICT does');
is_([$c['confdeltype'] ?? null, $c['confupdtype'] ?? null, $c['confmatchtype'] ?? null], ['a', 'a', 's'],
    'ON DELETE / ON UPDATE left at NO ACTION and MATCH SIMPLE, as W-2 did (docs/106)');
is_($c['ref'] ?? null, 'mt_services', 'it references mt_services');
$k  = $ins->one("SELECT conkey::text AS a, confkey::text AS b FROM pg_constraint WHERE conname = ?", [$FK]);
is_($cols($ins, 'mt_sites', "'" . ($k['a'] ?? '{}') . "'::int2[]"), ['customer_id', 'service_id'], 'from (customer_id, service_id) on the site');
is_($cols($ins, 'mt_services', "'" . ($k['b'] ?? '{}') . "'::int2[]"), ['customer_id', 'id'], 'to (customer_id, id) on the service — the site and its service carry the SAME operator');
$u = $ins->one("SELECT contype, conkey::text AS a FROM pg_constraint WHERE conname = ? AND conrelid = 'mt_services'::regclass", [$UQ]);
is_($u['contype'] ?? null, 'u', "{$UQ} supports it (UNIQUE — an index, not a restriction: the primary key is strictly stronger)");
is_($cols($ins, 'mt_services', "'" . ($u['a'] ?? '{}') . "'::int2[]"), ['id', 'customer_id'], 'on (id, customer_id)');
$old = $ins->query("SELECT conname, confdeltype FROM pg_constraint WHERE conrelid = 'mt_sites'::regclass
                     AND conname IN ('mt_sites_customer_id_fkey', 'mt_sites_service_id_fkey') ORDER BY 1");
is_(array_column($old, 'conname'), ['mt_sites_customer_id_fkey', 'mt_sites_service_id_fkey'], 'both single-column foreign keys remain — the composite one is additive');
is_(array_column($old, 'confdeltype'), ['r', 'r'], 'and both still ON DELETE RESTRICT, untouched');
is_((int) $ins->one("SELECT count(*)::int AS n FROM pg_constraint WHERE conrelid = 'mt_sites'::regclass AND contype = 'c'")['n'], 0,
    'no CHECK on mt_sites: both columns are NOT NULL, so none can skip the key and a CHECK would imply a NULL case (docs/106)');
foreach (['mt_sites', 'mt_services'] as $tb) {
    $f = $ins->one('SELECT relrowsecurity AS r, relforcerowsecurity AS f FROM pg_class WHERE oid = ?::regclass', [$tb]);
    is_([$f['r'], $f['f']], [true, true], "{$tb}: row security still ENABLED and FORCED after 029 lifted FORCE inside its own transaction");
}
is_(rls_violations($ins), [], 'the catalogue-wide row-security guard still finds nothing unprotected');
is_((int) $ins->one('SELECT count(*)::int AS n FROM mt_migrations WHERE filename = ?', [$MIG])['n'], 1, "the ledger records {$MIG}");

$sql = (string) @file_get_contents($root . '/migrations/' . $MIG);   // '' when absent: the assertions below then fail, not the suite
$body = implode("\n", array_filter(explode("\n", $sql), static fn($l) => !str_starts_with(ltrim($l), '--')));
is_(substr_count($body, 'NO FORCE ROW LEVEL SECURITY'), 2, 'the file lifts FORCE on exactly two tables');
is_(preg_match_all('/^ALTER TABLE mt_(sites|services)\s+FORCE ROW LEVEL SECURITY;/m', $body), 2, 'and restores it on the same two');
$pNo = strpos($body, 'NO FORCE'); $pAdd = strpos($body, 'ADD CONSTRAINT mt_sites_service_customer_fkey');
$pRestore = strrpos($body, 'FORCE ROW LEVEL SECURITY;');
is_($pNo !== false && $pAdd !== false && $pNo < $pAdd && $pAdd < $pRestore, true, 'in that order: lift, validate, restore — all inside the one migration transaction');
is_(str_contains($body, 'SET LOCAL row_security = off'), true, 'the guard stays: a read still subject to row security ERRORS instead of validating a partial view');
is_(preg_match('/\bnot\s+valid\b|\bconcurrently\b|\bdrop\s+constraint\b|\bbypassrls\b|\bsuperuser\b/i', $body), 0,
    'no NOT VALID, no CONCURRENTLY, no dropped constraint, no widened role');
is_(preg_match('/\bmt_(devices|vouchers|voucher_batches|customers|principals)\b/', $body), 0,
    'it touches nothing but mt_sites and mt_services — only the proven defect is remediated (docs/105)');

// ===========================================================================
t('2. THE ATTACK — operator A names operator B\'s service; refused BY NAME, the same-operator write accepted');
$before = [$sites(), $audit()];
$r = $asOperator($A['customer'], static function (Database $db) use ($try, $A, $B) {
    $own   = $try($db, 'INSERT INTO mt_sites (customer_id, service_id, name) VALUES (?, ?, ?)', [$A['customer'], $A['service'], 'o1 own']);
    $cross = $try($db, 'INSERT INTO mt_sites (customer_id, service_id, name) VALUES (?, ?, ?)', [$A['customer'], $B['service'], 'o1 cross']);
    $seeB  = (int) $db->one('SELECT count(*)::int AS c FROM mt_services WHERE id = ?', [$B['service']])['c'];
    $seeA  = (int) $db->one('SELECT count(*)::int AS c FROM mt_services WHERE id = ?', [$A['service']])['c'];
    $who   = $db->one('SELECT current_user AS u, mt_current_customer()::text AS c');
    return compact('own', 'cross', 'seeB', 'seeA', 'who');
});
is_([$r['who']['u'], $r['who']['c']], ['dnb_app', $A['customer']], 'authorization context: dnb_app, operator A');
is_([$r['own']['ok'], $r['own']['n']], [true, 1], 'CONTROL: a site on A\'s own service — INSERT 0 1');
is_([$r['cross']['ok'], $r['cross']['state']], [false, '23503'], 'the cross-operator site is REFUSED (foreign-key violation)');
is_(str_contains($r['cross']['msg'], $FK), true, "and the refusal names {$FK}");
is_([$r['seeA'], $r['seeB']], [1, 0], 'row security unchanged: A reads its own service (1) and not B\'s (0) — the refusal happens below it');
is_([$sites(), $audit()], $before, 'residue: the transaction was rolled back; no site and no audit row remain');

// ===========================================================================
t('3. RE-POINTING — an existing site cannot be moved onto another operator\'s service');
$r = $asOperator($A['customer'], static function (Database $db) use ($try, $A, $B) {
    $s2    = $db->one("INSERT INTO mt_services (customer_id, kind) VALUES (?, 'mikrotik_hotspot') RETURNING id", [$A['customer']])['id'];
    $cross = $try($db, 'UPDATE mt_sites SET service_id = ? WHERE id = ?', [$B['service'], $A['site']]);
    $own   = $try($db, 'UPDATE mt_sites SET service_id = ? WHERE id = ?', [$s2, $A['site']]);
    return compact('cross', 'own');
});
is_([$r['cross']['ok'], $r['cross']['state'], str_contains($r['cross']['msg'], $FK)], [false, '23503', true],
    'moving A\'s site onto B\'s service is refused by name');
is_([$r['own']['ok'], $r['own']['n']], [true, 1], 'CONTROL: moving it onto A\'s own second service — UPDATE 1');
is_($ins->one('SELECT service_id::text AS s FROM mt_sites WHERE id = ?', [$A['site']])['s'], $A['service'], 'residue: the site still names its original service');

// ===========================================================================
t('4. BELOW ROW SECURITY — the refusal binds a role that bypasses row security as well');
$pdo = $ins->pdo(); $pdo->beginTransaction();
try {
    $who   = $ins->one("SELECT current_user AS u, (SELECT rolbypassrls FROM pg_roles WHERE rolname = current_user) AS b");
    $cross = $try($ins, 'INSERT INTO mt_sites (customer_id, service_id, name) VALUES (?, ?, ?)', [$A['customer'], $B['service'], 'o1 bypass']);
    $own   = $try($ins, 'INSERT INTO mt_sites (customer_id, service_id, name) VALUES (?, ?, ?)', [$A['customer'], $A['service'], 'o1 bypass own']);
} finally { $pdo->rollBack(); }
is_($who['b'], true, 'authorization context: the fixture identity, which bypasses row security');
is_([$cross['ok'], str_contains($cross['msg'], $FK)], [false, true], 'it too is refused by name — integrity is not a row-security property');
is_([$own['ok'], $own['n']], [true, 1], 'CONTROL: the same-operator insert succeeds for it');

// ===========================================================================
t('5. A SERVICE\'S OPERATOR IS FIXED WHILE A SITE REFERENCES IT (S-A, forbidden by constraint)');
$pdo->beginTransaction();
try {
    $move = $try($ins, 'UPDATE mt_services SET customer_id = ? WHERE id = ?', [$B['customer'], $A['service']]);
    $free = $ins->one("INSERT INTO mt_services (customer_id, kind) VALUES (?, 'mikrotik_hotspot') RETURNING id", [$A['customer']])['id'];
    $ctl  = $try($ins, 'UPDATE mt_services SET customer_id = ? WHERE id = ?', [$B['customer'], $free]);
} finally { $pdo->rollBack(); }
is_([$move['ok'], $move['state'], str_contains($move['msg'], $FK)], [false, '23503', true],
    're-papering a referenced service onto another operator is refused by the composite key (ON UPDATE NO ACTION)');
is_([$ctl['ok'], $ctl['n']], [true, 1], 'CONTROL: an unreferenced service can be re-papered — so the refusal above is the key, not the role');
is_($ins->one('SELECT customer_id::text AS c FROM mt_services WHERE id = ?', [$A['service']])['c'], $A['customer'], 'residue: the service still belongs to A');

// ===========================================================================
t('6. DELETE — unchanged: a referenced service still cannot be deleted; an unreferenced one can');
$r = $asOperator($B['customer'], static function (Database $db) use ($try, $B) {
    $del  = $try($db, 'DELETE FROM mt_services WHERE id = ?', [$B['service']]);
    $free = $db->one("INSERT INTO mt_services (customer_id, kind) VALUES (?, 'mikrotik_hotspot') RETURNING id", [$B['customer']])['id'];
    $ctl  = $try($db, 'DELETE FROM mt_services WHERE id = ?', [$free]);
    return compact('del', 'ctl');
});
is_([$r['del']['ok'], $r['del']['state']], [false, '23503'], 'B deleting a service its own site references is refused, as before');
$named = str_contains($r['del']['msg'], 'mt_sites_service_id_fkey') ? 'mt_sites_service_id_fkey'
       : (str_contains($r['del']['msg'], $FK) ? $FK : 'neither');
is_(in_array($named, ['mt_sites_service_id_fkey', $FK], true), true, "by a site foreign key ({$named} fired first)");
is_([$r['ctl']['ok'], $r['ctl']['n']], [true, 1], 'CONTROL (docs/105 C1): B deletes its own unreferenced service — DELETE 1');

// ===========================================================================
t('7. ISOLATION UNCHANGED — each operator sees only its own services and sites; no context sees nothing');
foreach ([['A', $A, $B], ['B', $B, $A]] as [$n, $me, $other]) {
    $v = $asOperator($me['customer'], static fn(Database $db) => [
        (int) $db->one('SELECT count(*)::int AS c FROM mt_sites WHERE id = ?', [$me['site']])['c'],
        (int) $db->one('SELECT count(*)::int AS c FROM mt_sites WHERE id = ?', [$other['site']])['c'],
        (int) $db->one('SELECT count(*)::int AS c FROM mt_services WHERE id = ?', [$me['service']])['c'],
        (int) $db->one('SELECT count(*)::int AS c FROM mt_services WHERE id = ?', [$other['service']])['c'],
    ]);
    is_($v, [1, 0, 1, 0], "operator {$n}: own site 1, other's 0; own service 1, other's 0");
}
$none = (new TenantContext($app))->runUnscoped(static fn(Database $db) =>
    (int) $db->one('SELECT count(*)::int AS c FROM mt_sites')['c']);
is_([$none, $sites() >= 2], [0, true], 'no tenant context reads 0 sites while the fixture identity sees them (the control)');

// ===========================================================================
t('8. T-9 — THE CONTROL ON THE CONTROL: without the constraint, the identical attack succeeds');
$shape = static function (bool $drop) use ($ins, $A, $B, $FK, $try): array {
    $p = $ins->pdo(); $p->beginTransaction();
    try {
        if ($drop) { $p->exec("ALTER TABLE mt_sites DROP CONSTRAINT IF EXISTS {$FK}"); }
        $p->exec('SET LOCAL ROLE dnb_app');
        $p->prepare("SELECT set_config('app.customer_id', ?, true)")->execute([$A['customer']]);
        $who = $ins->one('SELECT current_user AS u');
        $r = $try($ins, 'INSERT INTO mt_sites (customer_id, service_id, name) VALUES (?, ?, ?)', [$A['customer'], $B['service'], 'o1 t9']);
        return ['who' => $who['u']] + $r;
    } finally { $p->rollBack(); }
};
$without = $shape(true);
$with    = $shape(false);
is_($without['who'], 'dnb_app', 'the attack runs as dnb_app under row security, in operator A\'s context');
is_([$without['ok'], $without['n']], [true, 1], 'constraint removed: the cross-operator site is ACCEPTED — INSERT 0 1 (this is O-1)');
is_([$with['ok'], str_contains($with['msg'], $FK)], [false, true], 'the identical shape with the constraint present is refused by name');
$back = $ins->one('SELECT convalidated FROM pg_constraint WHERE conname = ?', [$FK]);
is_($back['convalidated'] ?? null, true, 'residue: both transactions rolled back; the constraint is present and validated');

// ===========================================================================
t('9. AS THE OWNER, ON A THROWAWAY DATABASE — 029 refuses a violating estate; the candidate could not run at all');
$mainDsn = getenv('DNB_DSN') ?: '';
$tmpDb   = 'dnb_o1m_' . bin2hex(random_bytes(4));
$tmpDir  = sys_get_temp_dir() . '/' . $tmpDb . '-mig028';
$ownerMain = Database::owner();
$ownerMain->pdo()->exec("CREATE DATABASE {$tmpDb}");
@mkdir($tmpDir, 0700, true);
foreach (glob($root . '/migrations/*.sql') as $f) {
    if (strcmp(basename($f), '029') < 0) { copy($f, $tmpDir . '/' . basename($f)); }
}
$res = [];
try {
    putenv('DNB_DSN=' . preg_replace('/dbname=[^;]+/', 'dbname=' . $tmpDb, $mainDsn));
    $own = Database::owner();
    $res['base'] = (new Migrator($own, $tmpDir))->run(true);
    $res['level'] = (int) $own->one('SELECT count(*)::int AS n FROM mt_migrations')['n'];
    $res['owner'] = $own->one("SELECT current_user AS u, rolsuper AS s, rolbypassrls AS b FROM pg_roles WHERE rolname = current_user");

    // A violating estate at level 028, built through the real paths: that is the defect, reachable.
    $adm = Database::admin(); $a2 = Database::app(); $t2 = new TenantContext($a2); $sup = Database::inspector();
    $ca = $adm->one('SELECT mt_customer_create(?, ?) AS id', ['O1M operator A', 'test:o1'])['id'];
    $cb = $adm->one('SELECT mt_customer_create(?, ?) AS id', ['O1M operator B', 'test:o1'])['id'];
    $sb = $t2->run($cb, static fn(Database $d) => $d->one("INSERT INTO mt_services (customer_id, kind) VALUES (?, 'mikrotik_hotspot') RETURNING id", [$cb])['id']);
    $sa = $t2->run($ca, static fn(Database $d) => $d->one("INSERT INTO mt_services (customer_id, kind) VALUES (?, 'mikrotik_hotspot') RETURNING id", [$ca])['id']);
    $t2->run($ca, static fn(Database $d) => $d->exec('INSERT INTO mt_sites (customer_id, service_id, name) VALUES (?, ?, ?)', [$ca, $sa, 'own']));

    // The superseded candidate DDL, as the owner, against this CLEAN estate: refused —
    // the measured reason 029 is not that file.
    try { $own->pdo()->exec(file_get_contents($root . '/tools/audit/o1_composite_fk.sql')); $res['cand'] = 'applied'; }
    catch (PDOException $e) { $res['cand'] = $e->getMessage(); }
    $own->pdo()->exec('ROLLBACK');   // the candidate opens BEGIN itself; the failure leaves that block aborted
    $res['candLeft'] = (int) $own->one("SELECT count(*)::int AS n FROM pg_constraint WHERE conname IN ('{$FK}', '{$UQ}')")['n'];

    $res['attack028'] = $t2->run($ca, static fn(Database $d) => $d->exec('INSERT INTO mt_sites (customer_id, service_id, name) VALUES (?, ?, ?)', [$ca, $sb, 'cross']));
    $res['ownerSees'] = (int) $own->one('SELECT count(*)::int AS n FROM mt_sites')['n'];
    $res['truth']     = (int) $sup->one('SELECT count(*)::int AS n FROM mt_sites')['n'];

    // Migration 029 as the owner, against the violating estate.
    try { $res['m029bad'] = (new Migrator($own, $root . '/migrations'))->run(true); }
    catch (PDOException $e) { $res['m029bad'] = $e->getMessage(); }
    $res['afterBad'] = [
        (int) $own->one('SELECT count(*)::int AS n FROM mt_migrations')['n'],
        (int) $own->one("SELECT count(*)::int AS n FROM pg_constraint WHERE conname IN ('{$FK}', '{$UQ}')")['n'],
        $own->one("SELECT bool_and(relrowsecurity AND relforcerowsecurity) AS f FROM pg_class WHERE relname IN ('mt_sites','mt_services')")['f'],
        (int) $sup->one('SELECT count(*)::int AS n FROM mt_sites')['n'],
    ];

    // The per-row decision (here: the cross site is removed), then 029 again.
    $sup->exec('DELETE FROM mt_sites WHERE service_id = ? AND customer_id = ?', [$sb, $ca]);
    $res['m029ok'] = (new Migrator($own, $root . '/migrations'))->run(true);
    $res['afterOk'] = [
        (int) $own->one('SELECT count(*)::int AS n FROM mt_migrations')['n'],
        $own->one("SELECT convalidated AS v FROM pg_constraint WHERE conname = '{$FK}'")['v'] ?? null,
        $own->one("SELECT bool_and(relrowsecurity AND relforcerowsecurity) AS f FROM pg_class WHERE relname IN ('mt_sites','mt_services')")['f'],
    ];
    $res['attack029'] = $t2->run($ca, static function (Database $d) use ($ca, $sb) {
        try { $d->exec('INSERT INTO mt_sites (customer_id, service_id, name) VALUES (?, ?, ?)', [$ca, $sb, 'cross again']); return 'accepted'; }
        catch (PDOException $e) { return $e->getMessage(); }
    });
    $res['control029'] = $t2->run($ca, static fn(Database $d) => $d->exec('INSERT INTO mt_sites (customer_id, service_id, name) VALUES (?, ?, ?)', [$ca, $sa, 'own again']));
} finally {
    $own = $adm = $a2 = $t2 = $sup = null;
    putenv('DNB_DSN=' . $mainDsn);
    Database::inspector()->pdo()->exec("DROP DATABASE IF EXISTS {$tmpDb} WITH (FORCE)");
    array_map('unlink', glob($tmpDir . '/*.sql') ?: []); @rmdir($tmpDir);
}
is_([count($res['base'] ?? []), $res['level'] ?? null], [28, 28], 'the throwaway database is built to level 028 by the Migrator itself');
is_([$res['owner']['u'] ?? null, $res['owner']['s'] ?? null, $res['owner']['b'] ?? null], ['dnb', false, false],
    'authorization context: the schema owner, NOT a superuser and NOT bypassing row security (017, F2)');
is_($res['attack028'] ?? null, 1, 'at 028 the attack is ACCEPTED through the real path — the defect was reachable');
is_([$res['ownerSees'] ?? null, $res['truth'] ?? null], [0, 2], 'the owner sees 0 sites under FORCE row security while 2 exist — why a naive migration would validate nothing');
is_(str_contains((string) ($res['cand'] ?? ''), 'query would be affected by row-level security policy'), true,
    'the superseded candidate DDL, run as the owner on a CLEAN estate, REFUSES — it could never have been the migration');
is_($res['candLeft'] ?? null, 0, 'and it left nothing behind');
is_(is_string($res['m029bad'] ?? null) && str_contains($res['m029bad'], 'O-1 (029): 1 site(s) point at another operator'), true,
    '029 as the owner REFUSES the violating estate and counts it');
is_(is_string($res['m029bad'] ?? null) && str_contains($res['m029bad'], 'nothing was changed'), true, 'and says nothing was changed');
is_($res['afterBad'] ?? null, [28, 0, true, 2], 'and nothing was: ledger 28, neither constraint present, FORCE still on, both sites still there');
is_($res['m029ok'] ?? null, [$MIG], 'after the per-row decision, 029 applies — and only 029');
is_($res['afterOk'] ?? null, [29, true, true], 'ledger 29, the key VALIDATED, FORCE restored');
is_(is_string($res['attack029'] ?? null) && str_contains($res['attack029'], $FK), true, 'the same real-path attack is now refused by name');
is_($res['control029'] ?? null, 1, 'CONTROL: the same-operator site is still accepted');
$gone = (int) $ins->one('SELECT count(*)::int AS n FROM pg_database WHERE datname = ?', [$tmpDb])['n'];
is_([$gone, is_dir($tmpDir)], [0, false], 'residue: the throwaway database and its migration copy are gone');

// ===========================================================================
t('10. REPOSITORY STATE — 029 is the last migration; the candidate says it is superseded');
$files = array_map('basename', glob($root . '/migrations/*.sql')); sort($files);
is_(end($files), $MIG, 'the last migration is 029');
is_((int) $ins->one('SELECT count(*)::int AS n FROM mt_migrations')['n'], 29, 'the ledger records 29');
$cand = file_get_contents($root . '/tools/audit/o1_composite_fk.sql');
is_(str_contains($cand, 'SUPERSEDED') && str_contains($cand, 'migration 029'), true, 'tools/audit/o1_composite_fk.sql is marked SUPERSEDED by migration 029');
is_(str_contains($sql, 'docs/124') && str_contains($sql, 'docs/123'), true, 'the migration cites its review and the census that authorised it');

exit(t_summary());
