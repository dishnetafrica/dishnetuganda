<?php
/**
 * The operator-owner login from the Admin plane — docs/126 (J-1).
 *
 * What is proved here, by execution: the route that creates an operator's
 * people is bound, capability-gated and refuses what the server derives; the
 * phone is required, stored in one canonical form and never returned; a
 * duplicate — including a second spelling of the same number, or a number
 * another operator's person holds — is refused as "phone unavailable" and
 * writes neither a second row nor a second audit row; an owner created this way
 * CAN sign in at the API level once a code reaches them, and reaches exactly
 * its own operator; the panel's fourth onboarding call; the manifest.
 *
 * Two gaps are ASSERTED, not hidden, so the suite fails the day either is
 * fixed and must be rewritten to the new truth: the sign-in side does not yet
 * apply the canonical form (docs/126 D-4), and sign-in ignores the operator's
 * status (F-J1-1).
 *
 * NOT PROVED, and not claimed: that a code reaches any phone — nothing
 * delivers one (docs/126 §B.1) — or anything about a router or production.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/admin_identity_double.php';

use Dn\Admin\AdminReader;
use Dn\Admin\Capability;
use Dn\Admin\OnboardingAdmin;
use Dn\Admin\StaffIdentity;
use Dn\Admin\StaffRole;
use Dn\Api\AdminRoutes;
use Dn\Api\Routes;
use Dn\Auth\Authenticator;
use Dn\Auth\OpCapability as C;
use Dn\Auth\Phone;
use Dn\Db\Database;
use Dn\Http\Kernel;
use Dn\Http\Request;
use Dn\Http\Router;
use Dn\Plugin\Manifest;
use Dn\Runtime\Bindings;
use Dn\Tenancy\TenantContext;

$root = dirname(__DIR__);
$ins  = Database::inspector();     // BYPASSRLS fixture identity: reads state, proves nothing by itself
$aw   = Database::adminWrite();
$app  = Database::app();
$ids  = seed_two_customers($ins);
$A = $ids['A']; $B = $ids['B'];
$on   = OnboardingAdmin::on($aw);
$read = new AdminReader(Database::adminApi());
$ABSENT = '00000000-0000-4000-8000-000000000000';

$audit  = static fn(): int => (int) $ins->one('SELECT count(*)::int AS c FROM mt_audit_log')['c'];
$people = static fn(): int => (int) $ins->one('SELECT count(*)::int AS c FROM mt_principals')['c'];
$last   = static fn(string $action): ?array => $ins->one(
    'SELECT * FROM mt_audit_log WHERE action = ? ORDER BY at DESC, id DESC LIMIT 1', [$action]);
$hit = static function (Router $r, string $m, string $p, array $body = []) {
    $mm = $r->match($m, $p);
    if ($mm === null) { bad("no route {$m} {$p}"); return new \Dn\Http\Response(404); }
    return ($mm[0])(new Request($m, $p, [], $body, $mm[1], '127.0.0.1'));
};
$as = static fn(string $subject, StaffRole $role, ?OnboardingAdmin $o) => AdminRoutes::build(
    new FixedStaff(new StaffIdentity($subject, $role, 'test')), Bindings::defaults(), $read, null, null, null, null, $o);
$path = static fn(string $op): string => '/api/v1/admin/customers/' . $op . '/principals';
// A fresh, valid number for every act, so no assertion depends on another's leftovers.
$n = 0;
$num = static function () use (&$n): string { $n++; return '+25677' . str_pad((string) (random_int(0, 99999) * 100 + $n % 100), 7, '0', STR_PAD_LEFT); };

// A fresh operator for this suite, through the real onboarding writer.
$op = $on->createOperator('Owner Login Hotel', 'j1-op-' . bin2hex(random_bytes(4)), 'test:j1')['customer'];

// ===========================================================================
t('1. THE ROUTE IS BOUND — capability-gated, and absent without the Admin write connection');
$sales = $as('sales-user', StaffRole::Sales, $on);
$admin = $as('admin-user', StaffRole::Admin, $on);
foreach ([['noc-user', StaffRole::Noc], ['support-user', StaffRole::Support]] as [$u, $role]) {
    $res = $hit($as($u, $role, $on), 'POST', $path($op['id']), ['display_name' => 'X', 'phone' => $num()]);
    is_([$res->status, $res->body['capability'] ?? null], [403, Capability::CUSTOMERS_WRITE], "{$role->value}: 403 naming customers.write");
}
foreach ([StaffRole::Admin, StaffRole::Sales] as $role) {
    is_($role->can(Capability::CUSTOMERS_WRITE), true, "{$role->value} holds customers.write");
}
$off = $as('sales-user', StaffRole::Sales, null);
$res = $hit($off, 'POST', $path($op['id']), ['display_name' => 'X', 'phone' => $num()]);
is_([$res->status, $res->body['error'] ?? null], [501, 'onboarding_writes_unavailable'],
    'a process without the Admin write connection answers 501 and says why — no longer the J-1 placeholder');

// ===========================================================================
t('2. REFUSALS — the server derives what it derives; the phone is the sign-in key');
$p0 = $people(); $a0 = $audit();
foreach (['id', 'actor', 'customer_id', 'operator', 'operator_id', 'status', 'created_at', 'last_login_at', 'credential_hash'] as $f) {
    $res = $hit($sales, 'POST', $path($op['id']), ['display_name' => 'Forged', 'phone' => $num(), $f => $B['customer']]);
    is_([$res->status, str_contains(json_encode($res->body), $f)], [400, true], "carrying {$f}: 400, refused rather than ignored");
}
is_($hit($sales, 'POST', $path($op['id']), ['display_name' => 'K', 'phone' => $num(), 'kind' => 'manager'])->status, 400, 'a kind other than owner or staff: 400');
is_($hit($sales, 'POST', $path($op['id']), ['phone' => $num()])->status, 400, 'no display_name: 400');
is_($hit($sales, 'POST', $path($op['id']), ['display_name' => str_repeat('n', 121), 'phone' => $num()])->status, 400, 'a 121-character name: 400');
is_($hit($sales, 'POST', $path($op['id']), ['display_name' => 'No Phone'])->status, 400, 'no phone: 400 — a login needs its key');
foreach (['0700123456', '256700123456', '+0700123456', '+256', '+2567001234567890', '+256 70O 123 456', 'phone', ''] as $bad) {
    $res = $hit($sales, 'POST', $path($op['id']), ['display_name' => 'Bad Phone', 'phone' => $bad]);
    is_([$res->status, str_contains($res->body['error'] ?? '', 'international form')], [400, true], "phone '{$bad}': 400, and the message says what form is wanted");
}
is_($hit($sales, 'POST', $path($op['id']), ['display_name' => 'O', 'phone' => $num(), 'capabilities' => [C::PLANS_READ]])->status, 400,
    'an owner with capabilities: 400 — an owner holds every capability');
is_($hit($sales, 'POST', $path($op['id']), ['display_name' => 'S', 'phone' => $num(), 'kind' => 'staff', 'capabilities' => ['op.everything']])->status, 400,
    'staff with an unknown capability: 400');
is_($hit($sales, 'POST', $path($op['id']), ['display_name' => 'S', 'phone' => $num(), 'kind' => 'staff', 'capabilities' => [C::STAFF_MANAGE]])->status, 400,
    'staff with op.staff.manage: 400 — it belongs to owners and cannot be granted');
is_($hit($sales, 'POST', $path($op['id']), ['display_name' => 'S', 'phone' => $num(), 'kind' => 'staff', 'capabilities' => 'op.plans.read'])->status, 400,
    'capabilities that are not a list: 400');
is_($hit($sales, 'POST', $path('not-a-uuid'), ['display_name' => 'X', 'phone' => $num()])->status, 404, 'a malformed operator id: 404');
is_($hit($sales, 'POST', $path($ABSENT), ['display_name' => 'X', 'phone' => $num()])->status, 404, 'an unknown operator: 404 — the foreign key refuses it');
is_([$people(), $audit()], [$p0, $a0], 'every refusal above wrote nothing: no principal, no audit row');

// ===========================================================================
t('3. AN OWNER IS CREATED — 201, the projection only, the phone canonical and never returned');
$typed = '+256 (772) 010-203';
$canon = '+256772010203';
is_(Phone::canonical($typed), $canon, 'spaces, dashes and parentheses are removed: one canonical form');
is_(Phone::canonical('0772 010 203'), null, 'CONTROL: a national form is refused, not guessed at');
$res = $hit($sales, 'POST', $path($op['id']), ['display_name' => 'Jane Namusoke', 'phone' => $typed]);
$pr  = $res->body['principal'] ?? [];
is_([$res->status, $pr['customer_id'] ?? null, $pr['kind'] ?? null, $pr['display_name'] ?? null, $pr['status'] ?? null, $pr['capabilities'] ?? null],
    [201, $op['id'], 'owner', 'Jane Namusoke', 'active', []], '201: an active owner of the operator named in the path');
is_(array_keys($pr), ['id', 'customer_id', 'kind', 'display_name', 'status', 'capabilities', 'created_at', 'last_login_at'],
    'answered through the Admin projection: exactly its eight fields');
$json = json_encode($res->body);
foreach (['phone', '772010203', '772', '010-203'] as $needle) {
    is_(str_contains($json, $needle), false, "the answer carries no '{$needle}'");
}
$row = $ins->one('SELECT * FROM mt_principals WHERE id = ?', [$pr['id']]);
is_([$row['phone'], $row['customer_id'], $row['kind'], $row['capabilities']], [$canon, $op['id'], 'owner', '{}'],
    'stored: the canonical phone, the target operator, kind owner, no capability list');
$au = $last('principal.created');
$det = json_decode((string) $au['detail'], true);
is_([$au['actor'], $au['actor_kind'], $au['target_id'], $au['customer_id'], $det['operator'] ?? null, $det['target_kind'] ?? null],
    ['sales-user', 'staff', $pr['id'], $op['id'], $op['id'], 'owner'],
    'one audit row: the signed-in subject, actor_kind staff, the operator in the detail');
is_(str_contains((string) $au['detail'], '772010203'), false, 'the audit detail carries no phone number');

// ===========================================================================
t('4. A REPLAY IS REFUSED, NEVER DUPLICATED — and the refusal says nothing about where');
$p1 = $people(); $a1 = $audit();
$res = $hit($sales, 'POST', $path($op['id']), ['display_name' => 'Jane Namusoke', 'phone' => $typed]);
is_([$res->status, $res->body['detail'] ?? null], [409, 'phone unavailable'], 'the same request again: 409, phone unavailable');
$res = $hit($sales, 'POST', $path($op['id']), ['display_name' => 'Jane N.', 'phone' => $canon]);
is_([$res->status, $res->body['detail'] ?? null], [409, 'phone unavailable'], 'another spelling of the same number: the same refusal — one key, not two');
$res = $hit($admin, 'POST', $path($B['customer']), ['display_name' => 'Someone Else', 'phone' => '+256 772 010 203']);
is_([$res->status, $res->body['detail'] ?? null], [409, 'phone unavailable'], "the number under ANOTHER operator: the same refusal (P-B)");
$j = json_encode($res->body);
is_(str_contains($j, $op['id']) || str_contains($j, 'Owner Login Hotel') || str_contains($j, 'Jane'), false,
    'and it names neither the operator nor the person who holds the number');
is_([$people(), $audit()], [$p1, $a1], 'three refused attempts: no second row, no second audit row (RULE I-1 holds)');

// ===========================================================================
t('5. STAFF FROM THE ADMIN PLANE — a list of op.* capabilities, stored as given');
$res = $hit($admin, 'POST', $path($op['id']), ['display_name' => 'Front Desk', 'phone' => $num(), 'kind' => 'staff',
                                              'capabilities' => C::PRESETS['seller']]);
$st = $res->body['principal'] ?? [];
$sorted = static function (array $a): array { sort($a); return $a; };
is_([$res->status, $st['kind'] ?? null, $sorted($st['capabilities'] ?? [])], [201, 'staff', $sorted(C::PRESETS['seller'])],
    '201: a staff member holding exactly the seller preset');
is_(json_decode((string) $last('principal.created')['detail'], true)['target_kind'] ?? null, 'staff', 'audited as staff');

// ===========================================================================
t('6. THE OWNER CAN SIGN IN — once a code reaches them — and reaches exactly its own operator');
$auth = new Authenticator($app);
$code = $auth->issueCode($canon);                        // what a delivery channel would send
$sess = $auth->verifyCode($canon, $code);
is_([$sess !== null, $sess['principal_id'] ?? null, $sess['customer_id'] ?? null], [true, $pr['id'], $op['id']],
    'the code for the canonical number verifies: a session for this owner, of this operator');
$who = $auth->resolve($sess['token']);
is_([$who['kind'] ?? null, $who['customer_id'] ?? null], ['owner', $op['id']], 'resolved live: kind owner, the operator — nothing from the request');
$k = new Kernel(Routes::build($auth), $app, $auth, new TenantContext($app));
$me = $k->handle(new Request('GET', '/api/v1/me', ['Authorization' => 'Bearer ' . $sess['token']], [], [], '10.0.0.9'));
is_([$me->status, $me->body['customer']['name'] ?? null], [200, 'Owner Login Hotel'], 'GET /me as that owner: 200, its own operator');
$other = $k->handle(new Request('GET', '/api/v1/me/sites', ['Authorization' => 'Bearer ' . $sess['token']], [], [], '10.0.0.9'));
is_(array_filter($other->body['sites'] ?? [], static fn($s) => ($s['id'] ?? '') === $A['site']), [],
    "and it cannot see another operator's location");

// Both gaps this suite asserted when J-1 was built were closed by docs/127
// phase 1 (migration 031, and the canonical form in the Authenticator). The
// assertions are REWRITTEN to the new truth, not deleted; the full proofs are in
// tests/test_operator_sign_in.php.
$spaced = '+256 772 010 203';
$c2 = $auth->issueCode($spaced);
$s2 = $auth->verifyCode($spaced, $c2);
is_([$s2 !== null, $s2['principal_id'] ?? null], [true, $pr['id']],
    'CLOSED (D-4, docs/127 F-5): typed with spaces, the same owner signs in — one form at both ends');
$ins->exec("UPDATE mt_customers SET status = 'suspended' WHERE id = ?", [$op['id']]);
$c3 = $auth->issueCode($canon);
is_([$auth->verifyCode($canon, $c3), $auth->resolve($s2['token'])], [null, null],
    'CLOSED (F-J1-1, migration 031): the owner of a SUSPENDED operator can no longer sign in, and a live session stops');
$ins->exec("UPDATE mt_customers SET status = 'active' WHERE id = ?", [$op['id']]);

// ===========================================================================
t('7. THE PANEL — a fourth onboarding call; the phone sent once, never read back');
$stripJs = static function (string $s): string {
    $s = preg_replace('#/\*.*?\*/#s', ' ', $s);
    return preg_replace('#(^|[^:])//.*$#m', '$1', $s);
};
$ob  = $stripJs(file_get_contents($root . '/panel/onboarding.js'));
$api = $stripJs(file_get_contents($root . '/panel/api.js'));
$app_js = $stripJs(file_get_contents($root . '/panel/app.js'));
preg_match_all('/^\s{2}(\w+)\s*\(/m', $ob, $mm);
is_($mm[1], ['createOperator', 'startService', 'addLocation', 'addOwner'], 'onboarding.js has exactly four methods, addOwner the fourth');
is_(substr_count($ob, "send('POST'"), 4, 'and exactly four POSTs');
is_(preg_match("#addOwner\(operatorId, name, phone\)\s*\{\s*return send\('POST', '/customers/' \+ encodeURIComponent\(operatorId\) \+ '/principals',\s*\{ kind: 'owner', display_name: name, phone \}\);#", $ob), 1,
    'addOwner posts kind owner, the name and the phone — to the operator named in the path, and nothing else');
is_(preg_match('/\b(actor|status|customer_id|capabilities)\b/', substr($ob, (int) strpos($ob, 'addOwner('))), 0,
    'it sends no actor, status, customer_id or capabilities');
is_(str_contains($api, "principals()    { return this.get('/api/v1/admin/principals', 'principal'); }"), true, 'api.js reads the principals projection');
is_(substr_count($api, 'fetch(') === 1 && stripos($api, 'POST') === false, true, 'and api.js still has one fetch and no POST');
is_(preg_match('/onboardingApi\.addOwner\(f\.dataset\.id, name, val\(\'phone\'\)\)/', $app_js), 1, 'the owner form calls addOwner with the page\'s operator');
is_(preg_match('/\.phone\b/', $app_js), 0, 'app.js never reads a phone from any response');
is_(str_contains(file_get_contents($root . '/panel/app.js'), 'Nobody can sign in yet'), true,
    'the form says plainly that nobody can sign in yet');

// ===========================================================================
t('8. THE MANIFEST — eight estate writes bound, the principals route among them; three unbound');
$m = Manifest::load($root . '/plugin/plugin.json');
$bound = array_values(array_filter($m->writeRoutes, static fn($w) => $w['path'] === '/customers/{customer_id}/principals'));
is_(count($m->writeRoutes), 8, 'eight bound estate writes');
is_([$bound[0]['function'] ?? null, $bound[0]['role'] ?? null, $bound[0]['gate'] ?? null, $bound[0]['see'] ?? null, $bound[0]['capability'] ?? null],
    ['mt_admin_principal_create', 'dnb_adminwrite', 'admin-write', 'docs/126', 'customers.write'], 'the principals route, on dnb_adminwrite, through migration 027\'s function');
is_(array_map(static fn($r) => $r['path'], $m->unboundWrites), ['/plans', '/voucher-batches', '/sessions/{session_id}/disconnect'],
    'three declared-unbound paths remain');

// ===========================================================================
t('9. REPOSITORY STATE — no migration; the review exists and says what is missing');
$files = array_map('basename', glob($root . '/migrations/*.sql')); sort($files);
// J-1 itself added no migration; 031 is docs/127's, and it does not touch the creator.
$defs = array_values(array_filter($files, static fn($f) => preg_match('/CREATE (OR REPLACE )?FUNCTION mt_admin_principal_create\b/',
                                     (string) file_get_contents($root . '/migrations/' . $f)) === 1));
is_($defs, ['027_operator_staff_capabilities.sql'], 'the creator J-1 binds is defined in 027 and nowhere after');
$doc = (string) @file_get_contents($root . '/../docs/126-OPERATOR-OWNER-LOGIN-J1.md');
is_(str_contains($doc, 'The code is delivered nowhere') && str_contains($doc, 'F-J1-1') && str_contains($doc, 'Nothing checks the operator'), true,
    'docs/126 records both findings');

exit(t_summary());
