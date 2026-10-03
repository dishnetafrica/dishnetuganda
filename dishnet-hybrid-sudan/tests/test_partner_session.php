<?php
declare(strict_types=1);
/**
 * test_partner_session.php — WS-A P4b (docs/50 §C/§G, docs/47 §10.3).
 *
 * Partner-portal accounts (dist_partner_users) and sessions
 * (dist_partner_sessions): an opaque 256-bit token stored only as an HMAC, 8 h,
 * revoked on logout / disable / role change, with status + scope re-read on
 * every request. Driven against a real temp SQLite.
 *
 *   A migration 081 — tables, columns, indexes (token_hash unique, phone partial)
 *   B PartnerAccounts — create, role validation, duplicate-phone refusal (P-B)
 *   C issue + authenticate — opaque token, scope derived from the user
 *   D HMAC-only storage — raw token never stored; wrong secret cannot authenticate
 *   E expiry — a past-expiry session is refused
 *   F revocation — logout, disable, role change each revoke live sessions
 *   G live re-read — disable/role/outlet bind on the next request (+ controls)
 *   H CSRF / cross-site — the cookie rule; cookie attributes (Strict/HttpOnly/Secure)
 *   I foreign / staff token refused — random and wrong-secret tokens
 *   J key derivation — fail closed with no secret; label-distinct from the raw secret
 *   K manifest version
 */
$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/DistributorRegistry.php';
require_once $root . '/lib/PartnerContext.php';
require_once $root . '/lib/PartnerSession.php';
require_once $root . '/lib/PartnerAccounts.php';

$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d !== '' ? "\n       $d" : '') . "\n"; } }
function nc(string $f): string { $o=''; foreach (token_get_all((string)file_get_contents($f)) as $k){ if(is_array($k)){ if(in_array($k[0],[T_COMMENT,T_DOC_COMMENT],true)) continue; $o.=$k[1]; } else $o.=$k; } return $o; }
function threw(callable $fn, string &$msg = null): bool { try { $fn(); return false; } catch (\Throwable $e) { $msg = $e->getMessage(); return true; } }
function reason(callable $fn): string { try { $fn(); return ''; } catch (PartnerSessionException $e) { return $e->reason(); } catch (\Throwable $e) { return 'other:' . $e->getMessage(); } }

$cfg = ['webhook_secret' => str_repeat('s', 40)];
$tmp = sys_get_temp_dir() . '/psess_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);
$store = SqliteStore::create($tmp);
$pdo = $store->getPdo();
$reg = DistributorRegistry::fromStore($store);
$acct = PartnerAccounts::fromStore($store);
$A = $reg->create(['legal_name' => 'Alpha Distributors Ltd', 'trading_name' => 'Alpha'], 'adm');
$B = $reg->create(['legal_name' => 'Beta Distributors Ltd', 'trading_name' => 'Beta'], 'adm');

echo "A. migration 081 — tables, columns, indexes\n";
foreach (['dist_partner_users', 'dist_partner_sessions'] as $t) {
    is_($pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$t'")->fetchColumn() === $t, "$t table created");
}
$ucols = array_column($pdo->query("PRAGMA table_info(dist_partner_users)")->fetchAll(\PDO::FETCH_ASSOC), 'name');
is_(in_array('partner_id', $ucols, true) && in_array('role', $ucols, true) && in_array('status', $ucols, true) && in_array('phone', $ucols, true) && in_array('totp_secret', $ucols, true), 'user columns present (partner_id, role, status, phone, totp_secret)');
$scols = array_column($pdo->query("PRAGMA table_info(dist_partner_sessions)")->fetchAll(\PDO::FETCH_ASSOC), 'name');
is_(in_array('token_hash', $scols, true) && !in_array('token', $scols, true), 'session stores token_hash and has NO raw token column');
foreach (['idx_dist_puser_phone', 'idx_dist_psess_user'] as $ix) {
    is_($pdo->query("SELECT name FROM sqlite_master WHERE type='index' AND name='$ix'")->fetchColumn() === $ix, "index '$ix' created");
}

echo "\nB. PartnerAccounts — create, role validation, duplicate-phone refusal\n";
$uA = $acct->create((int)$A['id'], ['role' => 'head_office', 'display_name' => 'Alice', 'phone' => '+256700000001'], 'adm');
is_(($uA['id'] ?? 0) >= 1, 'create returns a user id');
is_(threw(fn() => $acct->create((int)$A['id'], ['role' => 'superuser', 'phone' => '+256700000009'], 'adm')), 'an unknown role is refused');
is_(threw(fn() => $acct->create(999999, ['role' => 'head_office'], 'adm')), 'create refuses an unknown partner');
$m = '';
is_(threw(fn() => $acct->create((int)$B['id'], ['role' => 'head_office', 'phone' => '+256700000001'], 'adm'), $m), 'a duplicate phone is refused (P-B: never an upsert)');
is_(stripos($m, 'phone') !== false, '…the refusal names the phone', $m);
$uB = $acct->create((int)$B['id'], ['role' => 'head_office', 'phone' => '+256700000002'], 'adm');
is_(($acct->findByPhone('+256700000001')['id'] ?? 0) === $uA['id'], 'findByPhone resolves the account (the sign-in lookup)');
is_(count($acct->listForPartner((int)$A['id'])) === 1, 'listForPartner is scoped to the partner');

echo "\nC. issue + authenticate — opaque token, scope derived from the user\n";
$tok = PartnerSession::issue($pdo, $cfg, (int)$uA['id'], '1.2.3.4', 'UA');
is_(strlen($tok) === 64 && ctype_xdigit($tok), 'issue returns a 256-bit opaque hex token');
$r = PartnerSession::authenticate($pdo, $cfg, 'GET', ['HTTP_AUTHORIZATION' => 'Bearer ' . $tok]);
is_($r['ctx'] instanceof PartnerContext, 'authenticate returns a PartnerContext');
is_($r['ctx']->partnerId() === (int)$A['id'] && $r['ctx']->role() === 'head_office', 'the context is scoped to the user\'s partner and role (derived, not requested)');
is_($r['user_id'] === (int)$uA['id'], 'the session names its user');

echo "\nD. HMAC-only storage — raw token never stored; wrong secret cannot authenticate\n";
$row = $pdo->query("SELECT * FROM dist_partner_sessions WHERE user_id=".(int)$uA['id'])->fetch(\PDO::FETCH_ASSOC);
is_(($row['token_hash'] ?? '') === PartnerSession::hashToken($tok, $cfg), 'the stored token_hash is HMAC(token, K)');
$leak = false; foreach ($row as $v) { if (is_string($v) && $v !== '' && strpos($v, $tok) !== false) $leak = true; }
is_(!$leak, 'the raw token appears in NO column of the session row');
is_(reason(fn() => PartnerSession::authenticate($pdo, ['webhook_secret' => str_repeat('z', 40)], 'GET', ['HTTP_AUTHORIZATION' => 'Bearer ' . $tok])) === 'invalid',
    'the same token under a DIFFERENT server secret does not authenticate (hash mismatch)');

echo "\nE. expiry — a past-expiry session is refused\n";
$tokE = PartnerSession::issue($pdo, $cfg, (int)$uA['id']);
$pdo->prepare("UPDATE dist_partner_sessions SET expires_at = ? WHERE token_hash = ?")->execute([time() - 10, PartnerSession::hashToken($tokE, $cfg)]);
is_(reason(fn() => PartnerSession::authenticate($pdo, $cfg, 'GET', ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokE])) === 'expired', 'an expired session is refused');

echo "\nF. revocation — logout, disable, role change revoke live sessions\n";
$tokL = PartnerSession::issue($pdo, $cfg, (int)$uA['id']);
is_(PartnerSession::revokeByToken($pdo, $cfg, $tokL, 'logout') === 1, 'logout revokes exactly the session the token names');
is_(reason(fn() => PartnerSession::authenticate($pdo, $cfg, 'GET', ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokL])) === 'revoked', 'the logged-out token is refused');
$tokD = PartnerSession::issue($pdo, $cfg, (int)$uA['id']);
$acct->disable((int)$uA['id'], 'adm');
is_(reason(fn() => PartnerSession::authenticate($pdo, $cfg, 'GET', ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokD])) !== '', 'disabling the account refuses its live session');
// re-enable for the role-change check
$pdo->prepare("UPDATE dist_partner_users SET status='active' WHERE id=?")->execute([(int)$uA['id']]);
$tokR = PartnerSession::issue($pdo, $cfg, (int)$uA['id']);
$acct->setRole((int)$uA['id'], 'read_delegate', 'adm');
is_(reason(fn() => PartnerSession::authenticate($pdo, $cfg, 'GET', ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokR])) === 'revoked', 'a role change revokes the pre-change session');

echo "\nG. live re-read — disable/role/outlet bind on the next request (controls paired)\n";
// A fresh active head_office user for the live-read checks.
$uC = $acct->create((int)$A['id'], ['role' => 'head_office', 'phone' => '+256700000003'], 'adm');
$tokC = PartnerSession::issue($pdo, $cfg, (int)$uC['id']);
is_(PartnerSession::authenticate($pdo, $cfg, 'GET', ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokC])['ctx']->role() === 'head_office', 'control: the live session authenticates while the user is active head_office');
// Change the role directly (no revoke) — the NEXT request must reflect it from the live row.
$pdo->prepare("UPDATE dist_partner_users SET role='read_delegate' WHERE id=?")->execute([(int)$uC['id']]);
is_(PartnerSession::authenticate($pdo, $cfg, 'GET', ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokC])['ctx']->role() === 'read_delegate', 'scope is re-read live: the role change binds on the next request, no stale snapshot');
// Flip status directly (no revoke) — the live re-read refuses and auto-closes the row.
$pdo->prepare("UPDATE dist_partner_users SET status='disabled' WHERE id=?")->execute([(int)$uC['id']]);
is_(reason(fn() => PartnerSession::authenticate($pdo, $cfg, 'GET', ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokC])) === 'disabled', 'a directly-disabled account is refused by the live status re-read (not only by revoke)');
is_((int)$pdo->query("SELECT COUNT(*) FROM dist_partner_sessions WHERE token_hash='".PartnerSession::hashToken($tokC,$cfg)."' AND revoked_at IS NOT NULL")->fetchColumn() === 1, 'the refused session was auto-revoked so it cannot be retried');

echo "\nH. CSRF / cross-site — the cookie rule; cookie attributes\n";
is_(PartnerSession::cookieUseAllowed('GET', []) === true, 'GET may use a cookie (a link to the portal is a navigation)');
is_(PartnerSession::cookieUseAllowed('POST', []) === false, 'POST without the custom header is refused');
is_(PartnerSession::cookieUseAllowed('POST', ['HTTP_X_REQUESTED_WITH' => 'DishNet', 'HTTP_SEC_FETCH_SITE' => 'same-origin']) === true, 'POST with the header from our own page is allowed');
is_(PartnerSession::cookieUseAllowed('POST', ['HTTP_X_REQUESTED_WITH' => 'DishNet', 'HTTP_SEC_FETCH_SITE' => 'cross-site']) === false, 'POST from a cross-site context is refused even with the header');
// a cookie-borne token on a bare POST (no header) is refused BEFORE the token is looked up
is_(reason(fn() => PartnerSession::authenticate($pdo, $cfg, 'POST', [], [PartnerSession::COOKIE => $tok])) === 'cross_site', 'a cookie token on an unguarded POST is refused as cross_site');
$sessSrc = nc($root . '/lib/PartnerSession.php');
is_(strpos($sessSrc, "'samesite' => 'Strict'") !== false, 'the cookie is SameSite=Strict');
is_(strpos($sessSrc, "'httponly' => true") !== false, 'the cookie is HttpOnly');
is_(strpos($sessSrc, "'secure'   => self::isHttps()") !== false || strpos($sessSrc, "'secure' => self::isHttps()") !== false, 'the cookie is Secure on HTTPS');

echo "\nI. foreign / staff token refused\n";
is_(reason(fn() => PartnerSession::authenticate($pdo, $cfg, 'GET', ['HTTP_AUTHORIZATION' => 'Bearer ' . str_repeat('a', 64)])) === 'invalid', 'a random opaque token does not authenticate');
is_(reason(fn() => PartnerSession::authenticate($pdo, $cfg, 'GET', [])) === 'missing', 'no token at all is "missing"');
is_(reason(fn() => PartnerSession::authenticate($pdo, $cfg, 'GET', ['HTTP_AUTHORIZATION' => 'Bearer not-even-hex'])) === 'invalid', 'a non-session bearer value is refused');

echo "\nJ. key derivation — fail closed; label-distinct from the raw secret\n";
is_(threw(fn() => PartnerSession::sessionKey([])), 'no server secret → sessionKey throws (fail closed)');
is_(threw(fn() => PartnerSession::sessionKey(['webhook_secret' => 'short'])), 'a too-short secret → throws');
is_(threw(fn() => PartnerSession::issue($pdo, [], (int)$uB['id'])), 'issue with no secret throws (cannot mint an unverifiable token)');
$k = PartnerSession::sessionKey($cfg);
is_(ctype_xdigit($k) && strlen($k) === 64 && $k !== $cfg['webhook_secret'], 'the derived key is a 256-bit HMAC, not the raw secret (label-distinct, no reuse)');
is_(strpos($sessSrc, 'dn-partner-session-v1') !== false, 'the derivation uses a distinct label');

echo "\nK. manifest version\n";
$mani = json_decode((string)file_get_contents($root . '/manifest.json'), true);
is_(($mani['information']['version'] ?? '') === '5.18.67', 'manifest version is 5.18.67');

exec('rm -rf ' . escapeshellarg($tmp));
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
