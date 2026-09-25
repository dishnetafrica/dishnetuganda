<?php
/**
 * Router lifecycle and provisioning from the Admin plane — migration 028, docs/121.
 *
 * What is proved here, by execution against the database and the router the
 * code builds: migration 028's grants and who may execute what; RULE I-1 in
 * mt_device_set_state (a state the row already holds writes nothing); the
 * lifecycle route, with migration 012's trigger as the authority and every
 * refusal writing nothing; mt_device_provision_request over ALL NINE states,
 * its guards, its replay path and its conflict path; the action route; that
 * the queued job is delivered only by the worker, through its binding; that
 * the server inventory marks exactly one action available and the route
 * agrees; that the panel's router-write client reaches exactly four
 * operations, that the read-only client is untouched, and that every
 * lifecycle step the panel offers is one the trigger accepts; the manifest.
 *
 * NOT PROVED, and not claimed: anything about a MikroTik. A confirmed job under
 * SimulatedRouterOs proves the queue, not RouterOS. Nothing here is HARDWARE
 * VERIFIED (docs/119).
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/admin_identity_double.php';

use Dn\Admin\Capability;
use Dn\Admin\RouterAdmin;
use Dn\Admin\RouterRefused;
use Dn\Admin\StaffIdentity;
use Dn\Admin\StaffRole;
use Dn\Api\AdminRoutes;
use Dn\Db\Database;
use Dn\Delivery\DeliveryTarget;
use Dn\Delivery\NullDelivery;
use Dn\Delivery\SimulatedRouterOs;
use Dn\Devices\DeviceRegistry;
use Dn\Http\Request;
use Dn\Http\Router;
use Dn\Intents\IntentQueue;
use Dn\Jobs\IntentWorker;
use Dn\Network\SignalReport;
use Dn\Plugin\Manifest;
use Dn\Runtime\Bindings;
use Dn\Tenancy\TenantContext;

putenv('DNB_SECRET_KEY=test-key-for-suite-only');
$root   = dirname(__DIR__);
$ins    = Database::inspector();      // BYPASSRLS fixture identity: reads state, proves nothing
$aw     = Database::adminWrite();     // the Admin write identity the routes use
$apiDb  = Database::adminApi();       // the Admin read identity the panel uses
$workDb = Database::worker();
$ctxW   = new TenantContext($workDb);
$ids    = seed_two_customers($ins);
$A = $ids['A']; $B = $ids['B'];
$ABSENT = '00000000-0000-4000-8000-000000000000';

$ra  = RouterAdmin::on($aw);
$reg = new DeviceRegistry($aw);
$audit     = static fn(): int => (int) $ins->one('SELECT count(*)::int AS c FROM mt_audit_log')['c'];
$lastAudit = static fn(string $action): ?array => $ins->one(
    'SELECT * FROM mt_audit_log WHERE action = ? ORDER BY at DESC, id DESC LIMIT 1', [$action]);
$device    = static fn(string $id): ?array => $ins->one('SELECT * FROM mt_devices WHERE id = ?', [$id]);
$intent    = static fn(string $id): ?array => $ins->one('SELECT * FROM mt_intents WHERE id = ?', [$id]);
$jobsFor   = static fn(string $deviceId): int => (int) $ins->one(
    'SELECT count(*)::int AS c FROM mt_intents WHERE target_id = ?', [$deviceId])['c'];
$seq = 0;
$serial = static function () use (&$seq): string { return 'LP' . str_pad((string) (++$seq), 5, '0', STR_PAD_LEFT) . strtoupper(bin2hex(random_bytes(2))); };
$wgKey  = static fn(): string => rtrim(base64_encode(random_bytes(32)), '=') . '=';
$ipSeq  = 40;
$tunnel = static function () use (&$ipSeq): string { $ipSeq++; return '10.66.' . (1 + intdiv($ipSeq, 250)) . '.' . ($ipSeq % 250 + 1); };
$key    = static fn(string $tag = 'k'): string => 'lp-' . $tag . '-' . bin2hex(random_bytes(4));

/** Legal path from `staged` (what mt_device_register records) to each state. */
$PATH = ['staged' => [], 'shipped' => ['shipped'], 'connected' => ['connected'],
         'provisioned' => ['connected', 'provisioned'], 'active' => ['connected', 'provisioned', 'active'],
         'diverged' => ['connected', 'provisioned', 'diverged'], 'orphaned' => ['shipped', 'orphaned'],
         'decommissioned' => ['decommissioned']];
/** A fresh device walked to $to by legal transitions; assigned to A unless told otherwise. */
$fresh = static function (string $to, bool $assign = true, bool $withTunnel = true)
        use ($ra, $reg, $ins, $serial, $wgKey, $tunnel, $A, $PATH): array {
    if ($to === 'registered') {
        // `registered` is unreachable through the W-1 path (mt_device_register
        // records the bench act, so a router enters as `staged`). For the
        // instrument test a fixture row is manufactured — labelled as such.
        return $ins->one("INSERT INTO mt_devices (customer_id, serial, model, tunnel_ip) VALUES (?, ?, 'fixture', ?) RETURNING *",
            [$assign ? $A['customer'] : null, $serial(), $withTunnel ? $tunnel() : null]);
    }
    $d = $ra->register($serial(), 'hAP ax2', '7.14.3', $wgKey(), $withTunnel ? $tunnel() : null, 'noc-user');
    if ($assign) { $d = $ra->assign($d['id'], $A['customer'], $A['site'], null, 'noc-user'); }
    foreach ($PATH[$to] as $step) { $d = $reg->transition($d['id'], $step, 'noc-user'); }
    return $d;
};
$hit = static function (Router $r, string $m, string $p, array $body = []) {
    $mm = $r->match($m, $p);
    if ($mm === null) { bad("no route {$m} {$p}"); return new \Dn\Http\Response(404); }
    return ($mm[0])(new Request($m, $p, [], $body, $mm[1], '127.0.0.1'));
};
$as = static fn(string $subject, StaffRole $role, ?RouterAdmin $routers) => AdminRoutes::build(
    new FixedStaff(new StaffIdentity($subject, $role, 'test')), Bindings::defaults(), null, null, null, null, $routers);
$noc = $as('noc-user', StaffRole::Noc, $ra);

// ===========================================================================
t('1. MIGRATION 028 — what exists, who may execute it, and what the write role still cannot touch');
$fn = 'mt_device_provision_request(uuid,text,text)';
is_((int) $ins->one("SELECT count(*)::int c FROM pg_proc WHERE oid = ?::regprocedure", [$fn])['c'], 1, 'the enqueue function exists with the designed signature');
is_($ins->one("SELECT pg_get_userbyid(proowner) o FROM pg_proc WHERE oid = ?::regprocedure", [$fn])['o'], 'dnb_def_prov', 'owned by dnb_def_prov (docs/114 §K, docs/121 D-5)');
$who = array_column($ins->query("SELECT rolname FROM pg_roles WHERE rolname LIKE 'dnb%' AND has_function_privilege(rolname, ?, 'EXECUTE') ORDER BY 1", [$fn]), 'rolname');
is_($who, ['dnb_adminwrite', 'dnb_def_prov'], 'EXECUTE: dnb_adminwrite and the owner — never dnb_admin, dnb_worker or dnb_app (docs/112 A-1)');
is_((int) $ins->one("SELECT count(*)::int n FROM pg_proc p, aclexplode(p.proacl) a WHERE p.oid = ?::regprocedure AND a.grantee = 0", [$fn])['n'], 0, 'PUBLIC holds nothing on it');
is_($ins->query("SELECT 1 FROM information_schema.role_table_grants WHERE grantee = 'dnb_adminwrite'"), [], 'W-3 intact: dnb_adminwrite still holds zero table privileges');
$tp = static fn(string $p): bool => (bool) $ins->one("SELECT has_table_privilege('dnb_def_prov', 'mt_intents', ?) p", [$p])['p'];
is_([$tp('SELECT'), $tp('INSERT'), $tp('UPDATE'), $tp('DELETE')], [true, true, false, false], 'dnb_def_prov: SELECT and INSERT on mt_intents, nothing more (D-16)');
$pols = $ins->query("SELECT polname, polcmd::text cmd FROM pg_policy WHERE polrelid = 'mt_intents'::regclass AND 'dnb_def_prov'::regrole = ANY (polroles) ORDER BY 1");
is_(array_map(static fn($p) => $p['polname'] . ':' . $p['cmd'], $pols), ['dnb_def_prov_mt_intents_insert:a', 'dnb_def_prov_mt_intents_select:r'], 'its two policies, in migration 017\'s naming');
$ss = 'mt_device_set_state(uuid,text,text)';
is_($ins->one("SELECT pg_get_userbyid(proowner) o FROM pg_proc WHERE oid = ?::regprocedure", [$ss])['o'], 'dnb_def_prov', 'mt_device_set_state is still owned by dnb_def_prov');
is_([(bool) $ins->one("SELECT has_function_privilege('dnb_adminwrite', ?, 'EXECUTE') p", [$ss])['p'], (bool) $ins->one("SELECT has_function_privilege('dnb_admin', ?, 'EXECUTE') p", [$ss])['p']],
    [true, true], 'and CREATE OR REPLACE preserved its grants (dnb_adminwrite, and dnb_admin\'s pre-existing one)');
is_(str_contains((string) $ins->one("SELECT prosrc FROM pg_proc WHERE oid = ?::regprocedure", [$ss])['prosrc'], 'IF r.state = p_state THEN RETURN r; END IF;'), true, 'its body carries the RULE I-1 no-op (proved by execution next)');

// ===========================================================================
t('2. RULE I-1 IN mt_device_set_state — a state the row already holds writes nothing');
$d1 = $fresh('staged', assign: false);
$before = $audit();
$row = $ra->setState($d1['id'], 'shipped', 'noc-user');
is_([$row['state'], $audit() - $before], ['shipped', 1], 'CONTROL: a real change moves the row and writes exactly one audit row');
is_([$lastAudit('device.state_changed')['actor'], $lastAudit('device.state_changed')['detail']], ['noc-user', '{"to": "shipped", "from": "staged"}'], 'naming the actor, from and to');
$updated = $device($d1['id'])['updated_at'];
$before = $audit();
$row = $ra->setState($d1['id'], 'shipped', 'noc-user');
is_([$row['state'], $row['id'], $audit() - $before], ['shipped', $d1['id'], 0], 'the SAME state again: the row comes back, nothing is written');
is_($device($d1['id'])['updated_at'], $updated, 'not even updated_at moved — no UPDATE ran');
throws_(static fn() => $aw->one('SELECT * FROM mt_device_set_state(?,?,?)', [$d1['id'], 'shipped', '  ']), 'identity of whoever recorded it',
    'a blank actor is refused even on the no-op path');
is_($ra->setState($ABSENT, 'shipped', 'noc-user'), null, 'an unknown device is null, as for assign');

// ===========================================================================
t('3. THE LIFECYCLE ROUTE — records what a person saw; the trigger judges; every refusal writes nothing');
$res = $hit($noc, 'POST', '/api/v1/admin/routers', ['serial' => $serial(), 'model' => 'hAP ax3', 'tunnel_ip' => $tunnel(), 'wg_pubkey' => $wgKey()]);
is_($res->status, 201, 'NOC registers a router over the API');
$rt = $res->body['router'];
is_($rt['state'], 'staged', 'it enters as staged (the bench act)');
$before = $audit();
$res = $hit($noc, 'POST', "/api/v1/admin/routers/{$rt['id']}/state", ['state' => 'shipped']);
is_([$res->status, $res->body['router']['state'], $audit() - $before], [200, 'shipped', 1], 'staged → shipped: 200, one audit row');
is_($lastAudit('device.state_changed')['actor'], 'noc-user', 'the actor is the authenticated subject');
$res = $hit($noc, 'POST', "/api/v1/admin/routers/{$rt['id']}/state", ['state' => 'connected']);
is_([$res->status, $res->body['router']['state']], [200, 'connected'], 'shipped → connected');
$before = $audit();
$res = $hit($noc, 'POST', "/api/v1/admin/routers/{$rt['id']}/state", ['state' => 'connected']);
is_([$res->status, $res->body['router']['state'], $audit() - $before], [200, 'connected', 0], 'connected → connected through the route: 200 and NO audit row (RULE I-1)');
$before = $audit();
$res = $hit($noc, 'POST', "/api/v1/admin/routers/{$rt['id']}/state", ['state' => 'staged']);
is_([$res->status, $res->body['error'], str_contains($res->body['detail'], 'illegal device transition connected -> staged'), $audit() - $before],
    [409, 'refused', true, 0], "an illegal step is 409 with the TRIGGER's own reason, and nothing is written");
is_($device($rt['id'])['state'], 'connected', 'the row is unchanged');
foreach (['diverged', 'registered', 'bogus', 7, null] as $bad) {
    $res = $hit($noc, 'POST', "/api/v1/admin/routers/{$rt['id']}/state", $bad === null ? [] : ['state' => $bad]);
    is_([$res->status, str_contains($res->body['error'], 'may record')], [400, true], 'state ' . var_export($bad, true) . ' is 400 — not a word a person may record');
}
is_($hit($noc, 'POST', "/api/v1/admin/routers/{$rt['id']}/state", ['state' => 'provisioned', 'actor' => 'someone-else'])->status, 400, 'an actor in the body is refused, not ignored');
is_($hit($noc, 'POST', "/api/v1/admin/routers/{$rt['id']}/state", ['state' => 'provisioned', 'customer_id' => $A['customer']])->status, 400, 'so is a customer_id — assignment is another route');
is_($hit($noc, 'POST', "/api/v1/admin/routers/{$ABSENT}/state", ['state' => 'shipped'])->status, 404, 'an unknown device is 404');
is_($hit($noc, 'POST', '/api/v1/admin/routers/nope/state', ['state' => 'shipped'])->status, 404, 'a malformed id is 404');
foreach ([['sales', StaffRole::Sales], ['support', StaffRole::Support]] as [$name, $role]) {
    $res = $hit($as($name, $role, $ra), 'POST', "/api/v1/admin/routers/{$rt['id']}/state", ['state' => 'provisioned']);
    is_([$res->status, $res->body['capability']], [403, 'routers.lifecycle'], "{$name} cannot record a state (403 naming routers.lifecycle)");
}
is_($hit($as('admin-user', StaffRole::Admin, $ra), 'POST', "/api/v1/admin/routers/{$rt['id']}/state", ['state' => 'provisioned'])->status, 200, 'Admin can');
$res = $hit($as('noc-user', StaffRole::Noc, null), 'POST', "/api/v1/admin/routers/{$rt['id']}/state", ['state' => 'active']);
is_([$res->status, $res->body['error']], [501, 'router_writes_unavailable'], 'a process without an Admin write connection says so with 501');
$d3 = $fresh('staged', assign: false);
is_($hit($noc, 'POST', "/api/v1/admin/routers/{$d3['id']}/state", ['state' => 'decommissioned'])->body['router']['state'] ?? null, 'decommissioned', 'a router can be decommissioned from staged');
$before = $audit();
$res = $hit($noc, 'POST', "/api/v1/admin/routers/{$d3['id']}/state", ['state' => 'connected']);
is_([$res->status, str_contains($res->body['detail'], 'is decommissioned'), $audit() - $before], [409, true, 0], 'and nothing can be recorded for it afterwards');
foreach (StaffRole::cases() as $role) {
    is_($role->can(Capability::ROUTERS_LIFECYCLE), in_array($role, [StaffRole::Admin, StaffRole::Noc], true),
        "matrix: {$role->value} " . ($role->can(Capability::ROUTERS_LIFECYCLE) ? 'can' : 'cannot') . ' record a lifecycle state');
}
is_(in_array(Capability::ROUTERS_LIFECYCLE, AdminRoutes::declaredCapabilities(), true) && in_array(Capability::ROUTERS_LIFECYCLE, Capability::ALL, true), true,
    'routers.lifecycle is declared for the guard test and listed in Capability::ALL');

// ===========================================================================
t('4. mt_device_provision_request BY EXECUTION — nine states, four guards, replay and conflict');
$accepted = []; $refusedWith = [];
foreach (['registered', 'staged', 'shipped', 'connected', 'provisioned', 'active', 'diverged', 'orphaned', 'decommissioned'] as $st) {
    $d = $fresh($st);
    is_($device($d['id'])['state'], $st, "fixture: a router recorded {$st}");
    $before = $audit(); $jobsBefore = $jobsFor($d['id']);
    try {
        $out = $ra->requestProvision($d['id'], $key($st), 'noc-user');
        $accepted[] = $st;
        is_([$out['replayed'], $out['intent']['kind'], $audit() - $before, $jobsFor($d['id']) - $jobsBefore], [false, 'device.provision', 1, 1],
            "{$st}: accepted — one intent, one audit row");
    } catch (RouterRefused $e) {
        $refusedWith[$st] = $e->getMessage();
        is_([$audit() - $before, $jobsFor($d['id']) - $jobsBefore], [0, 0], "{$st}: refused — nothing queued, nothing audited ({$e->getMessage()})");
    }
}
sort($accepted);
$expected = DeliveryTarget::DELIVERABLE_STATES; sort($expected);
is_($accepted, $expected, 'the function accepts EXACTLY DeliveryTarget::DELIVERABLE_STATES — one list, proved by execution (D-7)');
is_(str_contains($refusedWith['decommissioned'] ?? '', 'is decommissioned'), true, 'decommissioned has its own reason');
is_(str_contains($refusedWith['staged'] ?? '', 'recorded as staged'), true, 'a non-deliverable state names itself');

$u = $fresh('connected', assign: false);
throws_(static fn() => $ra->requestProvision($u['id'], $key('un'), 'noc-user'), 'not assigned to an operator', 'an unassigned router is refused: an intent needs a tenant to run under');
$na = $fresh('connected', assign: true, withTunnel: false);
throws_(static fn() => $ra->requestProvision($na['id'], $key('na'), 'noc-user'), 'no management address', 'a router with no tunnel address is refused now, not failed later');
is_($ra->requestProvision($ABSENT, $key('ab'), 'noc-user'), null, 'an unknown device is null');
throws_(static fn() => $ra->requestProvision($u['id'], '   ', 'noc-user'), 'idempotency key', 'a blank key is refused in PHP');
throws_(static fn() => $aw->one('SELECT mt_device_provision_request(?,?,?) r', [$u['id'], '', 'noc-user']), 'idempotency key', 'and in SQL');
throws_(static fn() => $aw->one('SELECT mt_device_provision_request(?,?,?) r', [$u['id'], 'lp-x-1', ' ']), 'identity of whoever', 'a blank actor is refused in SQL');

$dv = $fresh('connected');
$k1 = $key('rp');
$before = $audit();
$first = $ra->requestProvision($dv['id'], $k1, 'noc-user');
$row = $intent($first['intent']['id']);
is_([$row['customer_id'], $row['kind'], $row['target_type'], $row['target_id'], $row['state'], $row['actor_kind'], $row['actor_principal_id'], $row['idempotency_key']],
    [$A['customer'], 'device.provision', 'device', $dv['id'], 'queued', 'staff', null, $k1], 'the intent: operator DERIVED from the device row, staff actor kind, no principal, the key');
is_(json_decode($row['payload'], true), ['device_id' => $dv['id']], 'the payload names the device and nothing else');
$a = $lastAudit('device.provision_requested');
is_([$audit() - $before, $a['actor'], $a['actor_kind'], $a['customer_id'], $a['target_id'], json_decode($a['detail'], true)['intent']],
    [1, 'noc-user', 'staff', $A['customer'], $dv['id'], $row['id']], 'one audit row: the subject, staff, the operator, the device, the intent');
$before = $audit();
$again = $ra->requestProvision($dv['id'], $k1, 'noc-user');
is_([$again['replayed'], $again['intent']['id'], $audit() - $before, $jobsFor($dv['id'])], [true, $row['id'], 0, 1], 'REPLAY: the same key returns the same intent, writes no audit row, adds no intent (RULE I-1)');
$dv2 = $fresh('connected');
$before = $audit();
throws_(static fn() => $ra->requestProvision($dv2['id'], $k1, 'noc-user'), 'already used for a different request', 'the same key for ANOTHER router of the same operator is a conflict');
is_([$audit() - $before, $jobsFor($dv2['id'])], [0, 0], 'and writes nothing');
$second = $ra->requestProvision($dv['id'], $key('rp2'), 'noc-user');
is_([$second['replayed'], $second['intent']['id'] !== $row['id'], $jobsFor($dv['id'])], [false, true, 2], 'a DIFFERENT key is a deliberate second request');

// ===========================================================================
t('5. THE ACTION ROUTE — push_config queues an intent; everything else says exactly why not');
$dr = $fresh('connected');
$ka = 'panel-' . bin2hex(random_bytes(8));
$before = $audit();
$res = $hit($noc, 'POST', "/api/v1/admin/routers/{$dr['id']}/actions", ['action' => 'push_config', 'idempotency_key' => $ka]);
is_([$res->status, $res->body['replayed'], $res->body['intent']['kind'], $res->body['intent']['state'], $res->body['intent']['target_id'], $audit() - $before],
    [202, false, 'device.provision', 'queued', $dr['id'], 1], '202: a device.provision intent is queued, one audit row');
foreach (['payload', 'last_error', 'claimed_by', 'idempotency_key'] as $withheld) {
    is_(array_key_exists($withheld, $res->body['intent']), false, "the response withholds {$withheld} (AdminProjection::intent)");
}
$queuedId = $res->body['intent']['id'];
$before = $audit();
$res = $hit($noc, 'POST', "/api/v1/admin/routers/{$dr['id']}/actions", ['action' => 'push_config', 'idempotency_key' => $ka]);
is_([$res->status, $res->body['replayed'], $res->body['intent']['id'], $audit() - $before, $jobsFor($dr['id'])], [200, true, $queuedId, 0, 1], '200 on replay: the same job, nothing written');
is_(in_array($queuedId, array_column($apiDb->query('SELECT id FROM mt_admin_intents()'), 'id'), true), true, 'the Admin read projection lists it, so the panel\'s Provisioning jobs can show it');
foreach ([[], ['idempotency_key' => 'short'], ['idempotency_key' => 'has space in it'], ['idempotency_key' => str_repeat('k', 129)]] as $bad) {
    $res = $hit($noc, 'POST', "/api/v1/admin/routers/{$dr['id']}/actions", ['action' => 'push_config'] + $bad);
    is_([$res->status, str_contains($res->body['error'], 'idempotency_key')], [400, true], 'a missing or malformed key is 400: ' . json_encode($bad));
}
$res = $hit($noc, 'POST', "/api/v1/admin/routers/{$dr['id']}/actions", ['action' => 'format_disk', 'idempotency_key' => $key('x')]);
is_([$res->status, str_contains($res->body['error'], 'push_config')], [400, true], 'an unknown action is 400 and lists the known ones');
foreach (SignalReport::actions() as $act) {
    if ($act['available']) { continue; }
    $before = $audit(); $jobsBefore = $jobsFor($dr['id']);
    $res = $hit($noc, 'POST', "/api/v1/admin/routers/{$dr['id']}/actions", ['action' => $act['key'], 'idempotency_key' => $key('u')]);
    is_([$res->status, $res->body['error'], $res->body['action'], $res->body['detail'], $audit() - $before, $jobsFor($dr['id']) - $jobsBefore],
        [501, 'router_action_not_available', $act['key'], $act['reason'], 0, 0], "{$act['key']}: 501 with the INVENTORY's reason, nothing queued");
}
foreach (['actor', 'staged_by', 'customer_id', 'device_id', 'kind', 'payload', 'state'] as $forged) {
    $res = $hit($noc, 'POST', "/api/v1/admin/routers/{$dr['id']}/actions", ['action' => 'push_config', 'idempotency_key' => $key('f'), $forged => 'x']);
    is_([$res->status, str_contains($res->body['error'], "{$forged} is not accepted")], [400, true], "a body carrying '{$forged}' is REFUSED, not ignored");
}
$un = $fresh('connected', assign: false);
$before = $audit();
$res = $hit($noc, 'POST', "/api/v1/admin/routers/{$un['id']}/actions", ['action' => 'push_config', 'idempotency_key' => $key('un')]);
is_([$res->status, str_contains($res->body['detail'], 'not assigned to an operator'), $audit() - $before, $jobsFor($un['id'])], [409, true, 0, 0], 'an unassigned router: 409 with the reason, nothing queued');
$stg = $fresh('staged');
$res = $hit($noc, 'POST', "/api/v1/admin/routers/{$stg['id']}/actions", ['action' => 'push_config', 'idempotency_key' => $key('st')]);
is_([$res->status, str_contains($res->body['detail'], 'recorded as staged'), $jobsFor($stg['id'])], [409, true, 0], 'a router still staged: 409 naming the state, nothing queued to fail later');
is_($hit($noc, 'POST', "/api/v1/admin/routers/{$ABSENT}/actions", ['action' => 'push_config', 'idempotency_key' => $key('a')])->status, 404, 'an unknown device is 404');
is_($hit($noc, 'POST', '/api/v1/admin/routers/nope/actions', ['action' => 'push_config', 'idempotency_key' => $key('a')])->status, 404, 'a malformed id is 404');
foreach ([['sales', StaffRole::Sales], ['support', StaffRole::Support]] as [$name, $role]) {
    $res = $hit($as($name, $role, $ra), 'POST', "/api/v1/admin/routers/{$dr['id']}/actions", ['action' => 'push_config', 'idempotency_key' => $key('r')]);
    is_([$res->status, $res->body['capability']], [403, 'routers.act'], "{$name} cannot queue an action (403 naming routers.act)");
}
is_($hit($as('admin-user', StaffRole::Admin, $ra), 'POST', "/api/v1/admin/routers/{$dr['id']}/actions", ['action' => 'push_config', 'idempotency_key' => $key('ad')])->status, 202, 'Admin can');
$res = $hit($as('noc-user', StaffRole::Noc, null), 'POST', "/api/v1/admin/routers/{$dr['id']}/actions", ['action' => 'push_config', 'idempotency_key' => $key('n')]);
is_([$res->status, $res->body['error']], [501, 'router_writes_unavailable'], 'a process without an Admin write connection says so with 501');
$src = strip_php_comments(file_get_contents($root . '/src/Api/AdminRoutes.php'));
is_(str_contains($src, 'mt_device_'), false, 'the route file names no SQL function — RouterAdmin does, on dnb_adminwrite');
is_(preg_match('/\$req->body\[[\'"](staged_by|actor|customer_id)[\'"]\]/', $src), 0, 'and reads no actor, staged_by or customer_id from a body for these writes');
foreach (['src/Api/AdminRoutes.php', 'src/Admin/RouterAdmin.php'] as $f) {
    $b = strip_php_comments(file_get_contents($root . '/' . $f));
    is_(str_contains($b, 'Dn\\Delivery') || str_contains($b, 'DeliveryPort') || str_contains($b, 'IntentWorker') || str_contains($b, 'RestClient'), false,
        "{$f} cannot reach the delivery boundary (F2)");
}

// ===========================================================================
t('6. END TO END — the queued job is delivered ONLY by the worker, through ITS binding');
$reg->setDesired($dr['id'], ['ip/hotspot/profile' => ['use-radius' => 'yes']], 'noc-user');
$stateBefore = $device($dr['id'])['state'];
$run = static fn(\Dn\Delivery\DeliveryPort $d, string $worker): array =>
    (new IntentWorker($workDb, $ctxW, new IntentQueue($workDb), $d, $worker))->runOnce(50);
$run(new NullDelivery(), 'w-lp:null');
$row = $intent($queuedId);
is_([$row['state'], (int) $row['attempts'], str_contains((string) $row['last_error'], 'no delivery path is configured')], ['queued', 1, true],
    'under NullDelivery the job is retryable — queued, one attempt spent, the reason recorded (the docs/114 §K proof)');
$ins->exec('UPDATE mt_intents SET next_attempt_at = now() WHERE id = ?', [$queuedId]);
$sim = new SimulatedRouterOs();
$run($sim, 'w-lp:simulated-routeros');
$row = $intent($queuedId);
is_([$row['state'], $row['confirmed_at'] !== null], ['confirmed', true], 'under SimulatedRouterOs it is confirmed — from the simulator\'s own memory');
is_($sim->applied($dr['id']), ['ip/hotspot/profile' => ['use-radius' => 'yes']], 'what the simulator believes it holds is the desired document the registry carried');
is_($device($dr['id'])['state'], $stateBefore, "the router's recorded state is UNCHANGED ({$stateBefore}): a confirmed delivery moves no device (docs/118 D-4)");
is_($lastAudit('intent.confirmed')['actor'], 'w-lp:simulated-routeros', 'the worker\'s audit row carries the SIMULATED binding, so nothing reads as a router answering');
is_($device($dr['id'])['last_seen_at'], null, 'last_seen_at is still written by nothing');

// ===========================================================================
t('7. THE INVENTORY — exactly one action available, and the route agrees with it');
$acts = SignalReport::actions();
is_(array_values(array_map(static fn($a) => $a['key'], array_filter($acts, static fn($a) => $a['available']))), ['push_config'], 'push_config is the one available action');
is_(count($acts), 4, 'four actions are declared');
foreach ($acts as $a) { is_(is_string($a['reason']) && strlen($a['reason']) > 20, true, "{$a['key']} carries a reason"); }
is_(str_contains($acts[0]['reason'], 'Nothing here contacts the router'), true, 'and the available one says plainly that nothing here contacts the router');
is_(SignalReport::summary()['actions_available'], 1, 'summary: one available, derived');

// ===========================================================================
t('8. THE PANEL — a third client for router writes; the read-only client untouched; every offered step is one the trigger accepts');
$stripJs = static function (string $s): string {
    $s = preg_replace('#/\*.*?\*/#s', ' ', $s);
    return preg_replace('#(^|[^:])//.*$#m', '$1', $s);
};
$rjs = file_get_contents($root . '/panel/routers.js');
$rcode = $stripJs($rjs);
$app = file_get_contents($root . '/panel/app.js');
$acode = $stripJs($app);
$apiJs = $stripJs(file_get_contents($root . '/panel/api.js'));
is_(substr_count($rcode, "send('POST'"), 4, 'routers.js issues exactly four requests');
preg_match_all("/send\('(\w+)',\s*'([^']*)'/", $rcode, $mm, PREG_SET_ORDER);
is_(count($mm), 4, 'CONTROL: the four are parsed');
foreach ($mm as $call) { is_([$call[1], str_starts_with($call[2], '/routers')], ['POST', true], "routers.js: {$call[1]} {$call[2]} — a router write under /routers"); }
is_(preg_match_all('/\b(GET|DELETE|PATCH|PUT)\b/', $rcode), 0, 'and no other HTTP verb');
is_(substr_count($rcode, 'fetch('), 1, 'one fetch call site, inside send()');
is_(preg_match_all('/\b(register|assign|setState|pushConfig)\s*\(/', $rcode), 4, 'the four methods: register, assign, setState, pushConfig');
is_(substr_count($apiJs, 'fetch('), 1, 'api.js still has exactly one fetch call site');
is_(stripos($apiJs, 'POST') === false && !str_contains($apiJs, 'method:'), true, 'and api.js still issues no POST — estate read-only');
is_(str_contains($acode, "from './routers.js'"), true, 'app.js imports the router-write client');
// app.js has a list() helper whose PARAMETER is called fetch and is called with
// no arguments; a global fetch takes a URL. Scan for the latter.
is_(preg_match('/\bfetch\s*\(\s*[^)\s]/', $acode), 0, 'app.js opens no fetch of its own (no fetch call carrying a URL)');
is_(preg_match('/\bfetch\s*\(\s*[^)\s]/', "fetch('/x')"), 1, 'CONTROL: the scan does match a real fetch call');
preg_match_all('/\broutersApi\.(\w+)\s*\(/', $acode, $calls);
$used = array_values(array_unique($calls[1])); sort($used);
is_($used, ['assign', 'pushConfig', 'register', 'setState'], 'app.js calls exactly the four router writes');
foreach (['data-rform="register"', 'data-rform="assign"', 'data-rstate=', 'data-raction=', 'Nothing here contacts the router'] as $needle) {
    is_(str_contains($acode, $needle), true, "app.js renders {$needle}");
}
is_(str_contains($acode, 'freshKey()'), true, 'the action key is minted once per rendered page');
is_(str_contains($rcode, 'crypto.randomUUID'), true, 'from crypto.randomUUID');
is_(preg_match('/data-raction=[^>]*data-key=/', $acode), 1, 'and travels on the live button');

preg_match('/export const NEXT_STATES = (\{.*\});/', $rjs, $nm);
$next = json_decode($nm[1] ?? '', true);
is_(is_array($next), true, 'NEXT_STATES parses as strict JSON');
$all = ['registered', 'staged', 'shipped', 'connected', 'provisioned', 'active', 'orphaned', 'diverged', 'decommissioned'];
$keys = array_keys($next); sort($keys); $sortedAll = $all; sort($sortedAll);
is_($keys, $sortedAll, 'it covers every one of the nine states');
$targets = array_values(array_unique(array_merge(...array_values($next)))); sort($targets);
$recordable = RouterAdmin::RECORDABLE_STATES; sort($recordable);
is_($targets, $recordable, 'and offers exactly the states a person may record — no diverged, no registered');
is_(in_array('diverged', $targets, true), false, 'CONTROL: diverged is never offered');
$pairs = 0;
foreach ($next as $from => $tos) {
    foreach ($tos as $to) {
        $d = $fresh($from);
        $after = $reg->transition($d['id'], $to, 'noc-user');
        is_($after['state'], $to, "the trigger accepts the offered step {$from} → {$to}");
        $pairs++;
    }
}
is_($pairs >= 18, true, "CONTROL: {$pairs} offered pairs were exercised by execution");
throws_(static fn() => $reg->transition($fresh('registered')['id'], 'active', 'noc-user'), 'illegal device transition', 'CONTROL: a pair the panel does NOT offer (registered → active) is refused by the trigger');
throws_(static fn() => $reg->transition($fresh('active')['id'], 'staged', 'noc-user'), 'illegal device transition', 'CONTROL: and so is active → staged');
foreach (['login.js', 'app.js', 'api.js', 'staff.js', 'routers.js'] as $f) {
    $js = $stripJs(file_get_contents($root . '/panel/' . $f));
    foreach (['/password\s*[:=]/i', '/secret\s*[:=]/i', '/\btoken\s*[:=]\s*["\']/i'] as $re) {
        is_(preg_match($re, $js), 0, "{$f} assigns nothing credential-shaped ({$re})");
    }
}

// ===========================================================================
t('9. THE MANIFEST — four bound router writes (then three onboarding writes, docs/125), four declared-unbound, the capability matrix');
$m = Manifest::load($root . '/plugin/plugin.json');
$routerWrites = array_slice($m->writeRoutes, 0, 4);
is_(array_map(static fn($r) => $r['path'], $routerWrites), ['/routers', '/routers/{device_id}/assign', '/routers/{device_id}/state', '/routers/{device_id}/actions'], 'the four bound router writes come first');
is_(array_map(static fn($r) => $r['function'], $routerWrites), ['mt_device_register', 'mt_device_assign', 'mt_device_set_state', 'mt_device_provision_request'], 'each naming its function');
is_(array_unique(array_map(static fn($r) => $r['role'], $m->writeRoutes)), ['dnb_adminwrite'], 'all on dnb_adminwrite');
is_(array_map(static fn($r) => $r['see'] ?? null, array_slice($routerWrites, 2)), ['docs/121', 'docs/121'], 'the two from 028 point at docs/121');
is_(array_map(static fn($r) => $r['path'], array_slice($m->writeRoutes, 4)), ['/customers', '/customers/{customer_id}/services', '/sites', '/customers/{customer_id}/principals'],
    'then the three onboarding writes of 030 (docs/125) and the principals route (docs/126)');
is_(array_map(static fn($r) => $r['path'], $m->unboundWrites), ['/plans', '/voucher-batches', '/sessions/{session_id}/disconnect'], 'three declared-unbound paths remain — /sites is bound since 030, principals since docs/126');
is_($m->apiSurface, 'estate read + operator/service/location/owner create + router register/assign/lifecycle/provision; identity read-write; SMS settings read-write (Admin)', 'the surface names all three groups — the SMS settings since 033 (docs/128)'); 
is_($m->gateIsOpen('admin-write'), false, 'the admin-write gate is still not OPEN — partially bound, said so');
is_(str_contains($m->requires['worker'], 'ONLY the worker delivers'), true, 'the worker requirement says the action needs the worker');
foreach ([StaffRole::Admin, StaffRole::Noc] as $r) { is_([$r->can('routers.lifecycle'), $r->can('routers.act')], [true, true], "{$r->value} holds lifecycle and act"); }
foreach ([StaffRole::Sales, StaffRole::Support] as $r) { is_([$r->can('routers.lifecycle'), $r->can('routers.act')], [false, false], "{$r->value} holds neither"); }

// ===========================================================================
t('10. REPOSITORY STATE — 028 is followed by 029 (O-1, docs/124), 030 (onboarding, docs/125), 031 and 032 (sign-in, docs/127) and 033 (SMS settings, docs/128); the ledger agrees; nothing claims hardware');
$files = array_map('basename', glob($root . '/migrations/*.sql')); sort($files);
is_(array_slice($files, -6), ['028_admin_router_lifecycle_and_provisioning.sql', '029_o1_site_service_same_operator.sql', '030_admin_operator_onboarding.sql',
                             '031_sign_in_requires_active_operator.sql', '032_sign_in_codes_by_sms.sql', '033_sms_settings_from_the_admin_panel.sql'],
    '028 is followed by 029 (O-1, docs/124), 030 (onboarding, docs/125), 031 and 032 (sign-in, docs/127) and 033 (SMS settings, docs/128)');
is_((int) $ins->one('SELECT count(*)::int n FROM mt_migrations')['n'], 33, 'the ledger records 33');
$m028 = file_get_contents($root . '/migrations/028_admin_router_lifecycle_and_provisioning.sql');
is_(str_contains($m028, 'docs/121') && str_contains($m028, 'RULE I-1') && str_contains($m028, 'ON CONFLICT (customer_id, idempotency_key)'), true, 'it cites its review, RULE I-1 and closes the race inside the function');
is_(preg_match('/10\.66/', (string) $ins->one("SELECT prosrc FROM pg_proc WHERE oid = ?::regprocedure", [$fn])['prosrc']), 0,
    'the management-network rule is NOT copied into the function body (it lives once, in TunnelAddress; the migration only SAYS so in a comment)');
$doc = file_get_contents($root . '/../docs/121-ADMIN-ROUTER-LIFECYCLE-AND-PROVISIONING.md');
is_(str_contains($doc, 'NOTHING in this document is HARDWARE VERIFIED'), true, 'docs/121 opens by withholding the label');
foreach (['src/Admin/RouterAdmin.php', 'src/Api/AdminRoutes.php', 'panel/routers.js', 'panel/app.js'] as $f) {
    $b = file_get_contents($root . '/' . $f);
    is_(preg_match('/hardware[- ]verified|works on (a )?real|contacted the router/i', $b), 0, basename($f) . ' claims no hardware result');
}

exit(t_summary());
