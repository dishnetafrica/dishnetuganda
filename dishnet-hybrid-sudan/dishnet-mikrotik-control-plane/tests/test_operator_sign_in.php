<?php
/**
 * Operator sign-in, phase 1 — docs/127 §B: migration 031 and the sign-in routes.
 *
 * What is proved here, by execution: the three authentication functions refuse
 * a person whose OPERATOR is not active — at code issue, at verification (a code
 * issued before a suspension does not open a session after it) and on every
 * request (a live session stops) — and reinstatement restores them (F-4); the
 * answers stay uniform for an unknown number, a suspended operator's person and
 * an active one (F-3); the sign-in routes apply the canonical phone form (F-5);
 * dnb_def_auth gained READ on mt_customers and nothing else. The control on the
 * control: the pre-031 function, swapped back inside a rolled-back transaction,
 * lets the suspended operator's session through — so the assertions above have
 * real subject matter.
 *
 * NOT PROVED, and not claimed: that a code reaches any phone (phase 2), that
 * the operator app is served (phase 3), anything about a router or production.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use Dn\Api\Routes;
use Dn\Auth\Authenticator;
use Dn\Db\Database;
use Dn\Http\Kernel;
use Dn\Http\Request;
use Dn\Tenancy\TenantContext;

$root = dirname(__DIR__);
$ins  = Database::inspector();     // BYPASSRLS fixture identity: sets up state, proves nothing by itself
$app  = Database::app();
$ids  = seed_two_customers($ins);
$A = $ids['A']; $B = $ids['B'];
$auth = new Authenticator($app);
$k    = new Kernel(Routes::build($auth), $app, $auth, new TenantContext($app));
$call = static fn(string $m, string $p, array $body = [], string $tok = '') => $k->handle(
    new Request($m, $p, $tok !== '' ? ['Authorization' => "Bearer {$tok}"] : [], $body, [], '10.0.0.8'));
$status = static fn(string $op, string $s) => $ins->exec('UPDATE mt_customers SET status = ? WHERE id = ?', [$s, $op]);
$phoneA = $ins->one('SELECT phone FROM mt_principals WHERE customer_id = ? AND kind = ? LIMIT 1', [$A['customer'], 'owner'])['phone'];
$codeRow = static fn(string $phone) => $ins->one('SELECT principal_id, customer_id FROM mt_auth_codes WHERE phone = ? ORDER BY created_at DESC LIMIT 1', [$phone]);
// The Authenticator's own keyed hash, for codes and tokens alike.
$hmac = static fn(string $secret): string => hash_hmac('sha256', $secret, getenv('DNB_TOKEN_PEPPER') ?: 'dev-pepper-not-for-production');
// Each block uses a fresh number's budget: the per-number limit is 5 codes per 15 minutes.
$reset = static fn(string $phone) => $ins->exec('DELETE FROM mt_auth_codes WHERE phone = ?', [$phone]);

// ===========================================================================
t('1. MIGRATION 031 — the three functions read the operator; dnb_def_auth may READ mt_customers, nothing more');
// mt_auth_issue_code is checked under its CURRENT signature: 032 added the
// sealed payload (docs/127 §C) and dropped the three-argument form, keeping
// 031's check of the operator — the assertions below still read it.
foreach (['mt_auth_issue_code(text,text,interval,text)', 'mt_auth_verify_code(text,text)', 'mt_auth_resolve_token(text)'] as $f) {
    $r = $ins->one("SELECT pg_get_userbyid(proowner) AS o, prosecdef AS d, position('mt_customers' IN prosrc) > 0 AS c
                      FROM pg_proc WHERE oid = ?::regprocedure", [$f]);
    is_([$r['o'], (bool) $r['d'], (bool) $r['c']], ['dnb_def_auth', true, true], "{$f}: dnb_def_auth, SECURITY DEFINER, reads mt_customers");
    $ex = $ins->query("SELECT r.rolname FROM pg_roles r WHERE r.rolcanlogin AND NOT r.rolsuper
                          AND has_function_privilege(r.oid, ?::regprocedure, 'EXECUTE') ORDER BY 1", [$f]);
    is_(array_column($ex, 'rolname'), ['dnb_app'], "{$f}: executable by dnb_app and no other login role, exactly as before");
}
$p = $ins->one("SELECT has_table_privilege('dnb_def_auth','mt_customers','SELECT') AS s, has_table_privilege('dnb_def_auth','mt_customers','INSERT') AS i,
                       has_table_privilege('dnb_def_auth','mt_customers','UPDATE') AS u, has_table_privilege('dnb_def_auth','mt_customers','DELETE') AS d");
is_([(bool) $p['s'], (bool) $p['i'], (bool) $p['u'], (bool) $p['d']], [true, false, false, false], 'dnb_def_auth reads mt_customers and cannot write it');
is_((int) $ins->one("SELECT count(*)::int AS n FROM mt_migrations WHERE filename = '031_sign_in_requires_active_operator.sql'")['n'], 1, 'the ledger records 031');

// ===========================================================================
t('2. AT ISSUE — a suspended operator\'s person is treated exactly as an unknown number');
$reset($phoneA);
$auth->issueCode($phoneA);
is_($codeRow($phoneA)['principal_id'] !== null, true, 'CONTROL: an active operator\'s owner gets a code row naming them');
$status($A['customer'], 'suspended');
$reset($phoneA);
$c = $auth->issueCode($phoneA);
is_($codeRow($phoneA), ['principal_id' => null, 'customer_id' => null], 'suspended: the code row names nobody, as for an unknown number');
is_($auth->verifyCode($phoneA, $c), null, 'and the code opens nothing');
$status($A['customer'], 'closed');
$reset($phoneA);
$auth->issueCode($phoneA);
is_($codeRow($phoneA), ['principal_id' => null, 'customer_id' => null], 'closed: the same — every status but active is refused, not only suspended');
$status($A['customer'], 'active');

// ===========================================================================
t('3. AT VERIFICATION — a code issued before the suspension does not open a session after it');
$reset($phoneA);
$c = $auth->issueCode($phoneA);
$status($A['customer'], 'suspended');
is_($auth->verifyCode($phoneA, $c), null, 'suspended between issue and verify: no session');
$status($A['customer'], 'closed');
is_($auth->verifyCode($phoneA, $c), null, 'closed between issue and verify: no session either');
$status($A['customer'], 'active');
// WHICH operator is checked: the one the session will act for — the code row's
// own. Nothing can move a person to another operator (P-C: no writer exists); the
// fixture identity does it here only inside transactions that are always rolled
// back, to prove which row the check reads. The code is not consumed by either.
$ins->exec('BEGIN');
$ins->exec('UPDATE mt_principals SET customer_id = ? WHERE phone = ?', [$B['customer'], $phoneA]);
$ctl = $ins->query('SELECT customer_id FROM mt_auth_verify_code(?, ?)', [$phoneA, $hmac($c)]);
$ins->exec('ROLLBACK');
$ins->exec('BEGIN');
$ins->exec('UPDATE mt_principals SET customer_id = ? WHERE phone = ?', [$B['customer'], $phoneA]);
$ins->exec("UPDATE mt_customers SET status = 'suspended' WHERE id = ?", [$A['customer']]);
$moved = $ins->query('SELECT customer_id FROM mt_auth_verify_code(?, ?)', [$phoneA, $hmac($c)]);
$ins->exec('ROLLBACK');
is_([array_column($ctl, 'customer_id'), $moved], [[$A['customer']], []],
    'the code acts for A: with A active it verifies for A (CONTROL); with A suspended it opens nothing, even with its person moved to an active operator');
$s = $auth->verifyCode($phoneA, $c);
is_([$s !== null, $s['customer_id'] ?? null], [true, $A['customer']], 'reinstated, the same unexpired code verifies — the check is live, not a one-way latch');

// ===========================================================================
t('4. ON EVERY REQUEST — a live session stops at the suspension and comes back at reinstatement (F-4)');
$tok = (string) ($s['token'] ?? '');     // a weakened 031 must fail these assertions, not crash the suite
is_($call('GET', '/api/v1/me', [], $tok)->status, 200, 'CONTROL: the owner\'s session answers GET /me');
$status($A['customer'], 'suspended');
is_($auth->resolve($tok), null, 'suspended: the session resolves to nobody');
is_($call('GET', '/api/v1/me', [], $tok)->status, 401, 'and GET /me is 401 on the very next request');
is_($call('GET', '/api/v1/me/sites', [], $tok)->status, 401, 'as is every other /me route');
is_((int) $ins->one('SELECT count(*)::int AS n FROM mt_auth_sessions WHERE token_hash = ? AND revoked_at IS NULL', [$hmac($tok)])['n'], 1,
    'the session itself is not revoked (F-4): the live check is the enforcement');
// Control on the control: the pre-031 resolver, swapped back inside a transaction
// that is always rolled back, lets the same suspended session through.
$ins->exec('BEGIN');
$ins->exec("CREATE OR REPLACE FUNCTION mt_auth_resolve_token(p_token_hash text)
RETURNS TABLE (principal_id uuid, customer_id uuid, kind text, capabilities text[])
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS \$\$
  SELECT s.principal_id, s.customer_id, p.kind, p.capabilities FROM mt_auth_sessions s
    JOIN mt_principals p ON p.id = s.principal_id
   WHERE s.token_hash = p_token_hash AND s.revoked_at IS NULL AND s.expires_at > now() AND p.status = 'active';
\$\$");
$old = (int) $ins->one('SELECT count(*)::int AS n FROM mt_auth_resolve_token(?)', [$hmac($tok)])['n'];
$ins->exec('ROLLBACK');
is_($old, 1, 'CONTROL ON THE CONTROL: the pre-031 resolver lets the suspended operator\'s session through');
is_((int) $ins->one('SELECT count(*)::int AS n FROM mt_auth_resolve_token(?)', [$hmac($tok)])['n'], 0, 'and after the rollback 031\'s resolver refuses it again');
// WHICH operator is checked: the one the session acts for (P-C), never the
// person's current row. Same method as §3 — rolled back, nothing can do this.
$ins->exec('BEGIN');
$ins->exec("UPDATE mt_customers SET status = 'active' WHERE id = ?", [$A['customer']]);
$ins->exec('UPDATE mt_principals SET customer_id = ? WHERE phone = ?', [$B['customer'], $phoneA]);
$ctl = $ins->query('SELECT customer_id FROM mt_auth_resolve_token(?)', [$hmac($tok)]);
$ins->exec("UPDATE mt_customers SET status = 'suspended' WHERE id = ?", [$A['customer']]);
$moved = $ins->query('SELECT customer_id FROM mt_auth_resolve_token(?)', [$hmac($tok)]);
$ins->exec('ROLLBACK');
is_([array_column($ctl, 'customer_id'), $moved], [[$A['customer']], []],
    'the session acts for A: with A active it resolves to A (CONTROL); with A suspended it resolves to nobody, even with its person moved to an active operator');
$status($A['customer'], 'active');
is_($call('GET', '/api/v1/me', [], $tok)->status, 200, 'reinstated: the unexpired session answers again');
$status($A['customer'], 'closed');
is_($call('GET', '/api/v1/me', [], $tok)->status, 401, 'closed: the session stops too');
$status($A['customer'], 'active');
$ins->exec('UPDATE mt_principals SET status = ? WHERE phone = ?', ['disabled', $phoneA]);
is_($auth->resolve($tok), null, 'REGRESSION: a disabled person is still refused, operator active or not');
$ins->exec('UPDATE mt_principals SET status = ? WHERE phone = ?', ['active', $phoneA]);

// ===========================================================================
t('5. UNIFORM ANSWERS — unknown number, suspended operator\'s person, active person (F-3)');
$reset($phoneA);
$unknown = '+256799000' . random_int(100, 999);
$reset($unknown);
$r1 = $call('POST', '/api/v1/auth/request-code', ['phone' => $unknown]);
$r2 = $call('POST', '/api/v1/auth/request-code', ['phone' => $phoneA]);
$status($A['customer'], 'suspended');
$reset($phoneA);
$r3 = $call('POST', '/api/v1/auth/request-code', ['phone' => $phoneA]);
$status($A['customer'], 'active');
is_([[$r1->status, $r1->body], [$r2->status, $r2->body], [$r3->status, $r3->body]],
    [[202, ['status' => 'sent']], [202, ['status' => 'sent']], [202, ['status' => 'sent']]],
    'the same 202 {status: sent} for all three — the endpoint is not a directory');
$v1 = $call('POST', '/api/v1/auth/verify', ['phone' => $unknown, 'code' => '000000']);
$v3 = $call('POST', '/api/v1/auth/verify', ['phone' => $phoneA, 'code' => '000000']);
is_([[$v1->status, $v1->body], [$v3->status, $v3->body]], [[401, ['error' => 'unauthenticated']], [401, ['error' => 'unauthenticated']]],
    'and the same 401 when the code is wrong');

// ===========================================================================
t('6. ONE PHONE FORM AT BOTH ENDS — the sign-in routes canonicalise (F-5, docs/126 D-4)');
$spaced = substr($phoneA, 0, 4) . ' ' . substr($phoneA, 4, 3) . '-' . substr($phoneA, 7, 3) . ' ' . substr($phoneA, 10);
is_($spaced !== $phoneA && str_replace([' ', '-'], '', $spaced) === $phoneA, true, "CONTROL: '{$spaced}' is the stored number, typed with separators");
$reset($phoneA);
putenv('DNB_EXPOSE_OTP=1');        // this process only: lets the suite read the code the route issued
$rc = $call('POST', '/api/v1/auth/request-code', ['phone' => $spaced]);
putenv('DNB_EXPOSE_OTP');
is_([$rc->status, isset($rc->body['dev_code'])], [202, true], 'request-code accepts the typed form');
is_($codeRow($phoneA)['principal_id'] !== null, true, 'and the code row is filed under the canonical number, naming the owner');
$vr = $call('POST', '/api/v1/auth/verify', ['phone' => '(' . $spaced . ')', 'code' => $rc->body['dev_code']]);
is_([$vr->status, isset($vr->body['token'])], [200, true], 'verify with yet another spelling signs the owner in');
$raw = 'not a number';
$ins->exec('DELETE FROM mt_auth_codes WHERE phone = ?', [$raw]);
$rr = $call('POST', '/api/v1/auth/request-code', ['phone' => $raw]);
is_([$rr->status, $rr->body], [202, ['status' => 'sent']], 'input with no canonical form: the same 202 — it passes on and matches nobody');
is_($codeRow($raw), ['principal_id' => null, 'customer_id' => null], 'filed as typed, naming nobody');

// ===========================================================================
t('7. REPOSITORY STATE');
$files = array_map('basename', glob($root . '/migrations/*.sql')); sort($files);
is_(array_values(array_filter($files, static fn($f) => str_starts_with($f, '031'))), ['031_sign_in_requires_active_operator.sql'], 'exactly one 031 file');
$doc = (string) @file_get_contents($root . '/../docs/127-OPERATOR-SIGN-IN-END-TO-END.md');
is_(str_contains($doc, 'F-J1-1') && str_contains($doc, 'SMS (Recommended)'), true, 'docs/127 records the finding and the operator\'s choice');

exit(t_summary());
