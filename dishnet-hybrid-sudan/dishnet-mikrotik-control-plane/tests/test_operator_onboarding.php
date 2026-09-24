<?php
/**
 * Operator onboarding from the Admin plane — migration 030, docs/125.
 *
 * What is proved here, by execution: migration 030's catalogue facts — who owns
 * and who may execute the three writers and their two internal helpers, that
 * the location writer takes no operator, that the idempotency store belongs to
 * dnb_def_prov and no login role holds any privilege on it (with the control
 * that an owner-created table WOULD have handed dnb_admin one); each writer's
 * behaviour, audit row and RULE I-1 replay; the race two identical requests run
 * against the replay check, closed inside the function; derive, never accept;
 * the three routes, their capability matrix, their refusals and their answers;
 * the panel's onboarding client; the manifest; the simulator on the new path.
 *
 * NOT PROVED, and not claimed: anything about a router, or about production.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/admin_identity_double.php';

use Dn\Admin\Capability;
use Dn\Admin\OnboardingAdmin;
use Dn\Admin\OnboardingRefused;
use Dn\Admin\StaffIdentity;
use Dn\Admin\StaffRole;
use Dn\Api\AdminRoutes;
use Dn\Db\Database;
use Dn\Http\Request;
use Dn\Http\Router;
use Dn\Plugin\Manifest;
use Dn\Runtime\Bindings;

$root = dirname(__DIR__);
$ins  = Database::inspector();     // BYPASSRLS fixture identity: reads state, proves nothing by itself
$aw   = Database::adminWrite();    // the Admin write identity the routes use
$ids  = seed_two_customers($ins);
$A = $ids['A']; $B = $ids['B'];
$on = OnboardingAdmin::on($aw);
$ABSENT = '00000000-0000-4000-8000-000000000000';
$W = ['mt_admin_operator_create(text,text,text)', 'mt_admin_service_create(uuid,text,text)',
      'mt_admin_site_create(uuid,text,text,text,text)'];
$H = ['mt_admin_idem_seen(text,text,text)', 'mt_admin_idem_claim(text,text,text,text)'];

$audit  = static fn(): int => (int) $ins->one('SELECT count(*)::int AS c FROM mt_audit_log')['c'];
$count  = static fn(string $t): int => (int) $ins->one("SELECT count(*)::int AS c FROM {$t}")['c'];
$last   = static fn(string $action): ?array => $ins->one(
    'SELECT * FROM mt_audit_log WHERE action = ? ORDER BY at DESC, id DESC LIMIT 1', [$action]);
$key    = static fn(string $tag = 'k'): string => 'ob-' . $tag . '-' . bin2hex(random_bytes(5));
$refused = static function (callable $fn, string $needle) {
    try { $fn(); } catch (OnboardingRefused $e) { return str_contains($e->getMessage(), $needle) ? true : $e->getMessage(); }
    catch (\Throwable $e) { return get_class($e) . ': ' . $e->getMessage(); }
    return 'did not refuse';
};
$hit = static function (Router $r, string $m, string $p, array $body = []) {
    $mm = $r->match($m, $p);
    if ($mm === null) { bad("no route {$m} {$p}"); return new \Dn\Http\Response(404); }
    return ($mm[0])(new Request($m, $p, [], $body, $mm[1], '127.0.0.1'));
};
$as = static fn(string $subject, StaffRole $role, ?OnboardingAdmin $o) => AdminRoutes::build(
    new FixedStaff(new StaffIdentity($subject, $role, 'test')), Bindings::defaults(), null, null, null, null, null, $o);

// ===========================================================================
t('1. MIGRATION 030 — the writers, the helpers, the store, and who may reach them');
foreach (array_merge($W, $H) as $f) {
    is_($ins->one('SELECT pg_get_userbyid(proowner) AS o FROM pg_proc WHERE oid = ?::regprocedure', [$f])['o'], 'dnb_def_prov', "{$f} is owned by dnb_def_prov");
    is_((int) $ins->one('SELECT count(*)::int AS n FROM pg_proc p, aclexplode(p.proacl) a WHERE p.oid = ?::regprocedure AND a.grantee = 0', [$f])['n'], 0, "{$f}: PUBLIC holds no EXECUTE");
}
foreach ($W as $f) {
    $p = $ins->one('SELECT prosecdef AS d, array_to_string(proconfig, \',\') AS c FROM pg_proc WHERE oid = ?::regprocedure', [$f]);
    is_([$p['d'], str_contains((string) $p['c'], 'search_path=public, pg_temp')], [true, true], "{$f}: SECURITY DEFINER with a fixed search_path");
    $who = array_column($ins->query("SELECT rolname FROM pg_roles WHERE rolname LIKE 'dnb%' AND has_function_privilege(rolname, ?, 'EXECUTE') ORDER BY 1", [$f]), 'rolname');
    is_($who, ['dnb_adminwrite', 'dnb_def_prov'], "{$f}: executable by dnb_adminwrite and its owner only — not dnb_admin, dnb_worker or dnb_app (docs/112 A-1)");
}
foreach ($H as $f) {
    is_($ins->one('SELECT prosecdef AS d FROM pg_proc WHERE oid = ?::regprocedure', [$f])['d'], false, "{$f} runs with its caller's privileges — it grants nothing");
    $who = array_column($ins->query("SELECT rolname FROM pg_roles WHERE rolname LIKE 'dnb%' AND has_function_privilege(rolname, ?, 'EXECUTE') ORDER BY 1", [$f]), 'rolname');
    is_($who, ['dnb_def_prov'], "{$f}: only its owner may call it");
}
$args = $ins->one("SELECT array_to_string(proargnames, ',') AS a FROM pg_proc WHERE oid = 'mt_admin_site_create(uuid,text,text,text,text)'::regprocedure")['a'];
is_($args, 'p_service,p_name,p_location,p_idempotency_key,p_actor', 'the location writer takes a service, a name, a description, a key and an actor — NO operator (derive, never accept)');
foreach ($W as $f) {
    is_(str_contains((string) $ins->one('SELECT prosrc FROM pg_proc WHERE oid = ?::regprocedure', [$f])['prosrc'], 'mt_current_customer'), false,
        "{$f} never reads a tenant context: the Admin plane names its target explicitly");
}
is_($ins->one("SELECT pg_get_userbyid(relowner) AS o, relrowsecurity AS r FROM pg_class WHERE oid = 'public.mt_admin_idempotency'::regclass"),
    ['o' => 'dnb_def_prov', 'r' => false], 'the store belongs to dnb_def_prov and has no row security — it has no tenant; privilege is its isolation');
$logins = $ins->query("SELECT rolname FROM pg_roles WHERE rolcanlogin AND NOT rolsuper AND rolname <> 'postgres' ORDER BY 1");
is_(count($logins) >= 7, true, 'CONTROL: the login roles are enumerated from pg_roles, not listed by hand (' . count($logins) . ')');
foreach ($logins as $lr) {
    $any = $ins->one("SELECT has_table_privilege(?, 'public.mt_admin_idempotency', 'SELECT') OR has_table_privilege(?, 'public.mt_admin_idempotency', 'INSERT')
                          OR has_table_privilege(?, 'public.mt_admin_idempotency', 'UPDATE') OR has_table_privilege(?, 'public.mt_admin_idempotency', 'DELETE')
                          OR has_table_privilege(?, 'public.mt_admin_idempotency', 'TRUNCATE') AS p", array_fill(0, 5, $lr['rolname']))['p'];
    is_($any, false, "{$lr['rolname']} holds no privilege on mt_admin_idempotency");
}
is_($ins->one("SELECT has_table_privilege('dnb_def_prov', 'public.mt_admin_idempotency', 'INSERT') AS p")['p'], true, 'CONTROL: its owner does — the check can say true');
$dflt = (int) $ins->one("SELECT count(*)::int AS n FROM pg_default_acl d, aclexplode(d.defaclacl) a
                          WHERE d.defaclrole = 'dnb'::regrole AND d.defaclobjtype = 'r'
                            AND a.grantee = 'dnb_admin'::regrole AND a.privilege_type = 'SELECT'")['n'];
is_($dflt >= 1, true, 'CONTROL ON THE CONTROL: a table the OWNER created would have handed dnb_admin SELECT by default (015) — which is why the store was created as dnb_def_prov');
$pol = array_column($ins->query("SELECT c.relname || '/' || p.polcmd::text AS x FROM pg_policy p JOIN pg_class c ON c.oid = p.polrelid
                                  WHERE 'dnb_def_prov'::regrole = ANY (p.polroles) AND c.relname IN ('mt_customers','mt_services','mt_sites') ORDER BY 1"), 'x');
is_($pol, ['mt_customers/a', 'mt_customers/r', 'mt_services/a', 'mt_services/r', 'mt_sites/a', 'mt_sites/r'],
    'dnb_def_prov: read and insert on operators, services and locations — no update, no delete (D-7)');
is_((int) $ins->one("SELECT count(*)::int AS n FROM information_schema.role_table_grants WHERE grantee = 'dnb_adminwrite'")['n'], 0,
    'dnb_adminwrite still holds no table privilege at all (W-3)');
$m030 = file_get_contents($root . '/migrations/030_admin_operator_onboarding.sql');
is_(str_contains($m030, 'docs/125') && str_contains($m030, 'RULE I-1') && str_contains($m030, 'SET LOCAL ROLE dnb_def_prov'), true,
    'the migration cites its review, RULE I-1, and creates the store as dnb_def_prov');

// ===========================================================================
t('2. CREATE AN OPERATOR — one row, one audit row; a replay writes nothing; a reused key is refused');
$k1 = $key('op'); $before = [$count('mt_customers'), $audit()];
$r1 = $on->createOperator('  Lakeside Lodge  ', $k1, 'staff:alice');
$op = $r1['customer'];
is_([$r1['replayed'], $op['name'], $op['status']], [false, 'Lakeside Lodge', 'active'], 'a new operator, its name trimmed, status active');
is_([$count('mt_customers') - $before[0], $audit() - $before[1]], [1, 1], 'exactly one operator and one audit row');
$a = $last('customer.created');
is_([$a['customer_id'], $a['actor'], $a['actor_kind'], $a['source']], [$op['id'], 'staff:alice', 'staff', 'admin'],
    'audited as customer.created by the staff actor passed in, actor_kind staff (no new kind)');
$r2 = $on->createOperator('Lakeside Lodge', $k1, 'staff:alice');
is_([$r2['replayed'], $r2['customer']['id']], [true, $op['id']], 'the same key and name: the first result, replayed');
is_([$count('mt_customers') - $before[0], $audit() - $before[1]], [1, 1], 'RULE I-1: the replay wrote no operator and no audit row');
is_($refused(fn() => $on->createOperator('Another Name', $k1, 'staff:alice'), 'different request'), true, 'the same key for another name is refused');
is_([$count('mt_customers') - $before[0], $audit() - $before[1]], [1, 1], 'and wrote nothing');
$st = $ins->one('SELECT endpoint, actor, request_digest, result FROM mt_admin_idempotency WHERE key = ?', [$k1]);
is_([$st['endpoint'], $st['actor'], (bool) preg_match('/^[0-9a-f]{64}$/', $st['request_digest']), json_decode($st['result'], true)['id'] ?? null],
    ['operator.create', 'staff:alice', true, $op['id']], 'the store holds the endpoint, the actor, a sha256 digest and the result');
is_($refused(fn() => $on->createOperator('   ', $key(), 'staff:alice'), 'name'), true, 'a blank name is refused');
is_($refused(fn() => $on->createOperator(str_repeat('n', 121), $key(), 'staff:alice'), '120'), true, 'a 121-character name is refused');
is_($refused(fn() => $on->createOperator('Fine Name', 'short', 'staff:alice'), 'idempotency key'), true, 'a key that is not 8-128 safe characters is refused');
throws_(fn() => $on->createOperator('Fine Name', $key(), '  '), 'identity of the staff member', 'a blank actor is refused before the database is asked');
throws_(fn() => $aw->one('SELECT mt_admin_operator_create(?,?,?)', ['Fine Name', $key(), ' ']), 'identity', 'and by the function itself');

// ===========================================================================
t('3. THE RACE — two identical requests at once: one operator, one audit row; the loser is answered as a replay');
$rk = $key('race'); $rname = 'Race Lodge ' . bin2hex(random_bytes(3));
$dsn = getenv('DNB_DSN') ?: '';
$host = preg_match('/host=([^;]+)/', $dsn, $mm) ? $mm[1] : '/var/tmp';
$port = preg_match('/port=([0-9]+)/', $dsn, $mm) ? $mm[1] : '55432';
$db   = preg_match('/dbname=([^;]+)/', $dsn, $mm) ? $mm[1] : 'dnb_test';
// One session, four commands: the claim is taken and HELD, uncommitted, for 1.5 s.
$env  = ['PGPASSWORD' => getenv('DNB_ADMINWRITE_PASS') ?: '', 'PATH' => getenv('PATH') ?: '/usr/bin:/bin'];
$pA = proc_open(['psql', '-h', $host, '-p', $port, '-U', 'dnb_adminwrite', '-d', $db, '-X', '-A', '-t', '-q',
                 '-c', 'BEGIN',
                 '-c', "SELECT mt_admin_operator_create('{$rname}', '{$rk}', 'staff:first') ->> 'replayed'",
                 '-c', 'SELECT pg_sleep(1.5)', '-c', 'COMMIT'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
usleep(500_000);                                  // A has claimed the key and holds it, uncommitted
$t0 = microtime(true);
try { $rB = $on->createOperator($rname, $rk, 'staff:second'); }
catch (\Throwable $e) { $rB = ['replayed' => 'threw: ' . $e->getMessage(), 'customer' => ['id' => null]]; }
$waited = microtime(true) - $t0;
$outA = trim(stream_get_contents($pipes[1])); $errA = trim(stream_get_contents($pipes[2]));
$exitA = proc_close($pA);
is_([$exitA, $errA], [0, ''], 'the first session committed cleanly');
is_($outA, 'false', 'the first request created the operator');
is_($waited > 0.5, true, sprintf('the second waited on the first session\'s claim (%.1f s) instead of racing past it', $waited));
is_($rB['replayed'], true, 'the second was answered as a replay of the first');
is_((int) $ins->one('SELECT count(*)::int AS n FROM mt_customers WHERE name = ?', [$rname])['n'], 1, 'ONE operator exists');
is_((int) $ins->one("SELECT count(*)::int AS n FROM mt_audit_log WHERE action = 'customer.created' AND target_id = ?", [$rB['customer']['id']])['n'], 1,
    'and ONE audit row, naming the first actor');

// ===========================================================================
t('4. START THE HOTSPOT SERVICE — for an active operator; replay-safe; a second service is a deliberate act');
$sk = $key('svc'); $before = [$count('mt_services'), $audit()];
$s1 = $on->startService($op['id'], $sk, 'staff:alice');
$svc = $s1['service'];
is_([$s1['replayed'], $svc['customer_id'], $svc['kind'], $svc['status']], [false, $op['id'], 'mikrotik_hotspot', 'active'], 'the service belongs to the target operator, the one legal kind, active');
$a = $last('service.created');
is_([$a['customer_id'], $a['actor'], $a['actor_kind'], $a['target_id'], json_decode($a['detail'], true)['operator'] ?? null],
    [$op['id'], 'staff:alice', 'staff', $svc['id'], $op['id']], 'audited as service.created with the staff actor');
$s2 = $on->startService($op['id'], $sk, 'staff:alice');
is_([$s2['replayed'], $s2['service']['id'], $count('mt_services') - $before[0], $audit() - $before[1]], [true, $svc['id'], 1, 1], 'a replay returns it and writes nothing');
is_($refused(fn() => $on->startService($A['customer'], $sk, 'staff:alice'), 'different request'), true, 'the same key for another operator is refused');
is_($on->startService($ABSENT, $key(), 'staff:alice'), null, 'an unknown operator: nothing, and nothing claimed');
$ins->exec("UPDATE mt_customers SET status = 'suspended' WHERE id = ?", [$A['customer']]);
is_($refused(fn() => $on->startService($A['customer'], $key(), 'staff:alice'), 'suspended'), true, 'a suspended operator gets no new service');
$ins->exec("UPDATE mt_customers SET status = 'active' WHERE id = ?", [$A['customer']]);
$s3 = $on->startService($op['id'], $key('svc2'), 'staff:alice');
is_([$s3['replayed'], $s3['service']['id'] !== $svc['id']], [false, true], 'a second service with a new key is a new, audited act (the schema is 1:N)');

// ===========================================================================
t('5. ADD A LOCATION — the operator is DERIVED from the service; nothing can name another one');
$lk = $key('site'); $before = [$count('mt_sites'), $audit()];
$l1 = $on->addLocation($svc['id'], ' Garden Bar ', '  by the pool  ', $lk, 'staff:alice');
$site = $l1['site'];
is_([$l1['replayed'], $site['customer_id'], $site['service_id'], $site['name'], $site['location']],
    [false, $op['id'], $svc['id'], 'Garden Bar', 'by the pool'], 'the location belongs to the service\'s operator — derived, never passed');
$a = $last('site.created');
is_([$a['customer_id'], $a['actor'], $a['actor_kind'], $a['target_id']], [$op['id'], 'staff:alice', 'staff', $site['id']], 'audited as site.created');
is_(json_decode($a['detail'], true), ['name' => 'Garden Bar', 'service' => $svc['id'], 'operator' => $op['id']], 'naming the operator and the service');
$l2 = $on->addLocation($svc['id'], 'Garden Bar', 'by the pool', $lk, 'staff:alice');
is_([$l2['replayed'], $l2['site']['id'], $count('mt_sites') - $before[0], $audit() - $before[1]], [true, $site['id'], 1, 1], 'a replay returns it and writes nothing');
is_($refused(fn() => $on->addLocation($svc['id'], 'Other Bar', null, $lk, 'staff:alice'), 'different request'), true, 'the same key for another location is refused');
$lB = $on->addLocation($B['service'], 'B annex', null, $key('siteB'), 'staff:alice');
is_($lB['site']['customer_id'], $B['customer'], 'naming B\'s service makes a location of B\'s — the service decides, so no cross-operator location can be made here');
is_($lB['site']['location'], null, 'a blank or absent description is stored as nothing');
is_($on->addLocation($ABSENT, 'Nowhere', null, $key(), 'staff:alice'), null, 'an unknown service: nothing, and nothing claimed');
$ins->exec("UPDATE mt_services SET status = 'ended', ended_at = now() WHERE id = ?", [$s3['service']['id']]);
is_($refused(fn() => $on->addLocation($s3['service']['id'], 'Late', null, $key(), 'staff:alice'), 'ended'), true, 'an ended service gets no new location');
$ins->exec("UPDATE mt_customers SET status = 'suspended' WHERE id = ?", [$op['id']]);
is_($refused(fn() => $on->addLocation($svc['id'], 'Paused', null, $key(), 'staff:alice'), 'suspended'), true, 'nor does a suspended operator\'s service');
$ins->exec("UPDATE mt_customers SET status = 'active' WHERE id = ?", [$op['id']]);
is_($refused(fn() => $on->addLocation($svc['id'], ' ', null, $key(), 'staff:alice'), 'name'), true, 'a blank name is refused');
is_($refused(fn() => $on->addLocation($svc['id'], 'Long', str_repeat('x', 201), $key(), 'staff:alice'), '200'), true, 'a 201-character description is refused');
is_($ins->one("SELECT convalidated AS v FROM pg_constraint WHERE conname = 'mt_sites_service_customer_fkey'")['v'], true,
    'and beneath the writer, 029\'s composite key still stands as the floor');

// ===========================================================================
t('6. THE ROUTES — capabilities, refusals, 201 new, 200 replay, and the actor from the session');
$sales = $as('sales-user', StaffRole::Sales, $on);
$admin = $as('admin-user', StaffRole::Admin, $on);
foreach ([['noc-user', StaffRole::Noc], ['support-user', StaffRole::Support]] as [$u, $role]) {
    $r = $as($u, $role, $on);
    foreach ([['/api/v1/admin/customers', Capability::CUSTOMERS_WRITE],
              ['/api/v1/admin/customers/' . $op['id'] . '/services', Capability::SERVICES_WRITE],
              ['/api/v1/admin/sites', Capability::SITES_WRITE]] as [$path, $cap]) {
        $res = $hit($r, 'POST', $path, ['name' => 'X', 'idempotency_key' => $key()]);
        is_([$res->status, $res->body['capability'] ?? null], [403, $cap], "{$role->value}: POST {$path} is 403 naming {$cap}");
    }
}
foreach ([StaffRole::Admin, StaffRole::Sales] as $role) {
    is_([$role->can(Capability::CUSTOMERS_WRITE), $role->can(Capability::SERVICES_WRITE), $role->can(Capability::SITES_WRITE)], [true, true, true],
        "{$role->value} holds customers.write, services.write and sites.write");
}
is_(in_array(Capability::SERVICES_WRITE, Capability::ALL, true) && in_array(Capability::SERVICES_WRITE, AdminRoutes::declaredCapabilities(), true), true,
    'services.write is a declared capability');

$ck = $key('route-op');
$res = $hit($sales, 'POST', '/api/v1/admin/customers', ['name' => 'Hilltop Inn', 'idempotency_key' => $ck]);
$rop = $res->body['customer'] ?? [];
is_([$res->status, $res->body['replayed'] ?? null, $rop['name'] ?? null], [201, false, 'Hilltop Inn'], 'POST /customers: 201, a new operator');
is_(array_keys($rop), ['id', 'name', 'ucrm_client_id', 'status', 'created_at'], 'answered through the Admin projection — no radius_ref');
is_($last('customer.created')['actor'], 'sales-user', 'the audit actor is the signed-in subject');
$res = $hit($sales, 'POST', '/api/v1/admin/customers', ['name' => 'Hilltop Inn', 'idempotency_key' => $ck]);
is_([$res->status, $res->body['replayed'] ?? null, $res->body['customer']['id'] ?? null], [200, true, $rop['id']], 'the same request again: 200, replayed, the same operator');
$res = $hit($sales, 'POST', '/api/v1/admin/customers', ['name' => 'Other Inn', 'idempotency_key' => $ck]);
is_([$res->status, str_contains($res->body['detail'] ?? '', 'different request')], [409, true], 'the key reused for another name: 409 with the reason');
foreach (['id', 'actor', 'created_by', 'status', 'created_at', 'radius_ref', 'ucrm_client_id', 'customer_id'] as $f) {
    $res = $hit($sales, 'POST', '/api/v1/admin/customers', ['name' => 'Forged', 'idempotency_key' => $key(), $f => 'x']);
    is_([$res->status, str_contains($res->body['error'] ?? $res->body['detail'] ?? json_encode($res->body), $f)], [400, true], "POST /customers carrying {$f}: 400, refused rather than ignored");
}
is_($hit($sales, 'POST', '/api/v1/admin/customers', ['name' => 'No Key'])->status, 400, 'no idempotency_key: 400');
is_($hit($sales, 'POST', '/api/v1/admin/customers', ['name' => str_repeat('n', 121), 'idempotency_key' => $key()])->status, 400, 'a 121-character name: 400');

$sp = '/api/v1/admin/customers/' . $rop['id'] . '/services';
$svk = $key('route-svc');
$res = $hit($sales, 'POST', $sp, ['idempotency_key' => $svk]);
$rsvc = $res->body['service'] ?? [];
is_([$res->status, $rsvc['customer_id'] ?? null, $rsvc['kind'] ?? null], [201, $rop['id'], 'mikrotik_hotspot'], 'POST …/services: 201, the operator\'s HotSpot service');
is_($hit($sales, 'POST', $sp, ['idempotency_key' => $svk])->status, 200, 'and 200 on the replay');
is_($hit($sales, 'POST', $sp, ['idempotency_key' => $key(), 'kind' => 'fibre'])->status, 400, 'another kind: 400');
is_($hit($sales, 'POST', $sp, ['idempotency_key' => $key(), 'customer_id' => $A['customer']])->status, 400, 'a customer_id in the body: 400 — the path is the target');
is_($hit($sales, 'POST', '/api/v1/admin/customers/' . $ABSENT . '/services', ['idempotency_key' => $key()])->status, 404, 'an unknown operator: 404');
is_($hit($sales, 'POST', '/api/v1/admin/customers/not-a-uuid/services', ['idempotency_key' => $key()])->status, 404, 'a malformed id: 404');

$lok = $key('route-site');
$res = $hit($sales, 'POST', '/api/v1/admin/sites', ['service_id' => $rsvc['id'], 'name' => 'Front desk', 'location' => 'ground floor', 'idempotency_key' => $lok]);
$rsite = $res->body['site'] ?? [];
is_([$res->status, $rsite['customer_id'] ?? null, $rsite['name'] ?? null], [201, $rop['id'], 'Front desk'], 'POST /sites: 201, the location, its operator derived');
is_($last('site.created')['actor'], 'sales-user', 'audited with the signed-in subject');
is_($hit($sales, 'POST', '/api/v1/admin/sites', ['service_id' => $rsvc['id'], 'name' => 'Front desk', 'location' => 'ground floor', 'idempotency_key' => $lok])->status, 200, 'and 200 on the replay');
foreach (['customer_id', 'operator', 'operator_id', 'actor', 'id'] as $f) {
    $res = $hit($sales, 'POST', '/api/v1/admin/sites', ['service_id' => $rsvc['id'], 'name' => 'Forged', 'idempotency_key' => $key(), $f => $A['customer']]);
    is_($res->status, 400, "POST /sites carrying {$f}: 400 — the operator is derived, never accepted");
}
is_($hit($sales, 'POST', '/api/v1/admin/sites', ['name' => 'No service', 'idempotency_key' => $key()])->status, 400, 'no service_id: 400');
$res = $hit($sales, 'POST', '/api/v1/admin/sites', ['service_id' => $ABSENT, 'name' => 'Nowhere', 'idempotency_key' => $key()]);
is_([$res->status, $res->body['detail'] ?? null], [409, 'no such service'], 'an unknown service: 409, no such service');
$ins->exec("UPDATE mt_customers SET status = 'suspended' WHERE id = ?", [$rop['id']]);
$res = $hit($admin, 'POST', '/api/v1/admin/sites', ['service_id' => $rsvc['id'], 'name' => 'Paused', 'idempotency_key' => $key()]);
is_([$res->status, str_contains($res->body['detail'] ?? '', 'suspended')], [409, true], 'a suspended operator: 409 with the reason');
$ins->exec("UPDATE mt_customers SET status = 'active' WHERE id = ?", [$rop['id']]);
$off = $as('sales-user', StaffRole::Sales, null);
is_([$hit($off, 'POST', '/api/v1/admin/customers', ['name' => 'X', 'idempotency_key' => $key()])->status,
     $hit($off, 'POST', '/api/v1/admin/customers', ['name' => 'X', 'idempotency_key' => $key()])->body['error'] ?? null],
    [501, 'onboarding_writes_unavailable'], 'a process without the Admin write connection answers 501 and says why');

// ===========================================================================
t('7. THE PANEL — a fourth client with exactly three writes; api.js untouched; no operator sent with a location');
$stripJs = static function (string $s): string {
    $s = preg_replace('#/\*.*?\*/#s', ' ', $s);
    return preg_replace('#(^|[^:])//.*$#m', '$1', $s);
};
$ojs = $stripJs(file_get_contents($root . '/panel/onboarding.js'));
$app = $stripJs(file_get_contents($root . '/panel/app.js'));
$api = $stripJs(file_get_contents($root . '/panel/api.js'));
preg_match_all("/send\('(\w+)',\s*'([^']*)'/", $ojs, $sends, PREG_SET_ORDER);
// Since docs/126 (J-1) a fourth: the operator's owner, POSTed under the operator's path.
is_(array_map(static fn($c) => $c[1] . ' ' . $c[2], $sends), ['POST /customers', "POST /customers/", 'POST /sites', "POST /customers/"],
    'onboarding.js issues exactly four requests, all POST: /customers, /customers/{id}/services, /sites, /customers/{id}/principals');
is_(str_contains($ojs, "'/principals'"), true, 'the fourth is the principals path (docs/126)');
is_(str_contains($ojs, "'/services'"), true, 'the second is the services path');
is_(preg_match_all('/\b(GET|DELETE|PATCH|PUT)\b/', $ojs) + substr_count($ojs, 'fetch('), 0, 'no other verb and no fetch of its own: it sends through routers.js');
is_(str_contains($ojs, "from './routers.js'"), true, 'it imports send from the router client rather than copying it');
preg_match('/addLocation\s*\([^)]*\)\s*\{(.*?)\n  \}/s', $ojs, $al);
is_([str_contains($al[1] ?? '', 'service_id'), (bool) preg_match('/customer|operator/i', $al[1] ?? 'customer')], [true, false],
    'addLocation sends the service and NOTHING that names an operator (derive, never accept)');
is_(substr_count($api, 'fetch(') === 1 && stripos($api, 'POST') === false, true, 'api.js still has one fetch and no POST — estate read-only');
foreach (['data-oform="operator"', 'data-oform="location"', 'data-oservice=', 'Nothing here contacts a router'] as $n) {
    is_(str_contains($app, $n), true, "app.js renders {$n}");
}
is_(preg_match_all('/data-oform="(operator|location)" data-key="\$\{esc\(freshKey\(\)\)\}"/', $app), 2, 'each form carries a key minted once per render');
is_(preg_match('/data-oservice="start"[^>]*data-key="\$\{esc\(freshKey\(\)\)\}"/', $app), 1, 'and so does the Start button');
foreach (['/password\s*[:=]/i', '/secret\s*[:=]/i', '/\btoken\s*[:=]\s*["\']/i'] as $re) {
    is_(preg_match($re, $ojs), 0, "onboarding.js assigns nothing credential-shaped ({$re})");
}
// Found by driving these screens in Chromium (docs/125 §D): `#gate{display:flex}` outranks the
// browser's own [hidden] rule, so after sign-in the empty gate stayed a full viewport tall above
// the panel — measured 860 px before the rule below, 0 after.
$htm = file_get_contents($root . '/panel/index.html');
is_(str_contains($htm, '#gate[hidden],.shell[hidden]{display:none}'), true, 'index.html makes hidden mean hidden for the gate and the shell');

// ===========================================================================
t('8. THE MANIFEST — three onboarding writes bound, /sites among them; principals bound since docs/126');
$m = Manifest::load($root . '/plugin/plugin.json');
$ob = array_values(array_filter($m->writeRoutes, static fn($r) => ($r['see'] ?? null) === 'docs/125'));
is_(array_map(static fn($r) => [$r['path'], $r['capability'], $r['function'], $r['role'], $r['gate']], $ob), [
    ['/customers', 'customers.write', 'mt_admin_operator_create', 'dnb_adminwrite', 'admin-write'],
    ['/customers/{customer_id}/services', 'services.write', 'mt_admin_service_create', 'dnb_adminwrite', 'admin-write'],
    ['/sites', 'sites.write', 'mt_admin_site_create', 'dnb_adminwrite', 'admin-write'],
], 'each names its capability, function, role and gate');
is_(str_contains($ob[2]['actor'] ?? '', 'DERIVED'), true, 'the location entry says the operator is derived');
is_(in_array('/sites', array_column($m->unboundWrites, 'path'), true), false, '/sites is no longer declared-unbound');
// Rewritten, not deleted, when docs/126 (J-1) bound it: principal creation is no longer declared-unbound.
is_(in_array('/customers/{customer_id}/principals', array_column($m->unboundWrites, 'path'), true), false, 'principal creation is no longer declared-unbound (docs/126, J-1)');
is_(in_array('/customers/{customer_id}/principals', array_column($m->writeRoutes, 'path'), true), true, 'it is bound (docs/126)');
is_($m->gateIsOpen('admin-write'), false, 'the admin-write gate is still not OPEN — partially bound, said so');

// ===========================================================================
t('9. THE SIMULATOR — operators, services and locations through the new writers');
$sim = strip_php_comments(file_get_contents($root . '/src/Plugin/Simulator.php'));
is_([str_contains($sim, 'createOperator('), str_contains($sim, 'startService('), str_contains($sim, 'addLocation(')], [true, true, true],
    'it calls the three onboarding writers');
is_(preg_match('/INSERT\s+INTO\s+mt_(services|sites)\b/i', $sim), 0, 'and inserts no service or location by hand any more');

// ===========================================================================
t('10. REPOSITORY STATE — 030 exists once and is applied');
$files = array_map('basename', glob($root . '/migrations/*.sql')); sort($files);
is_(array_values(array_filter($files, static fn($f) => str_starts_with($f, '030'))), ['030_admin_operator_onboarding.sql'], 'exactly one 030 file');
is_((int) $ins->one("SELECT count(*)::int AS n FROM mt_migrations WHERE filename = '030_admin_operator_onboarding.sql'")['n'], 1, 'and the ledger records it');
$doc = (string) @file_get_contents($root . '/../docs/125-OPERATOR-ONBOARDING-FROM-THE-ADMIN-PANEL.md');
// Rewritten, not deleted, when the staging result came in (docs/125 §F): the
// document now records staging, and must still say production holds none of it.
is_(str_contains($doc, 'REDEPLOYED on staging') && str_contains($doc, 'Not in production') && str_contains($doc, 'derive, never accept'), true, 'docs/125 records the staging result, says it is not in production, and states the rule');

exit(t_summary());
