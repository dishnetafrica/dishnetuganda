<?php
declare(strict_types=1);
/**
 * test_partner_auth.php — WS-A P4c (docs/50, docs/47 §10.3, docs/49 §13).
 *
 * Distributor-portal sign-in: a one-time login code to the verified number, THEN
 * a TOTP authenticator (D-9b). Driven against a real temp SQLite.
 *
 *   A migration 082 — tables, the totp_confirmed column
 *   B TOTP primitive — RFC 6238 vectors, base32, ±1 window
 *   C request code — uniform for unknown; code stored HASHED for a known active user
 *   D sign-in — BOTH factors required; code consumed only on full success
 *   E TOTP enrolment — begin/confirm; a confirmed authenticator cannot be silently reset
 *   F rate + decaying lock — per-user/per-ip send caps; fail lock engages then decays
 *   G anti-enumeration — unknown phone answered exactly like a wrong code
 *   H fail-closed key — no server secret → refuse; label-distinct
 *   I canonical phone — national and international forms resolve the same account
 *   J manifest version
 */
$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/TenantProfile.php';
require_once $root . '/lib/PhoneNumber.php';
require_once $root . '/lib/Totp.php';
require_once $root . '/lib/DistributorRegistry.php';
require_once $root . '/lib/PartnerAccounts.php';
require_once $root . '/lib/PartnerAuth.php';

$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d !== '' ? "\n       $d" : '') . "\n"; } }
function nc(string $f): string { $o=''; foreach (token_get_all((string)file_get_contents($f)) as $k){ if(is_array($k)){ if(in_array($k[0],[T_COMMENT,T_DOC_COMMENT],true)) continue; $o.=$k[1]; } else $o.=$k; } return $o; }
function threw(callable $fn): bool { try { $fn(); return false; } catch (\Throwable $e) { return true; } }

$UG  = TenantProfile::load('uganda');
$cfg = ['webhook_secret' => str_repeat('s', 40)];
$NOW = 1700000000;
$tmp = sys_get_temp_dir() . '/pauth_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);
$store = SqliteStore::create($tmp);
$pdo = $store->getPdo();
$reg = DistributorRegistry::fromStore($store);
$acct = PartnerAccounts::fromStore($store);
$A = $reg->create(['legal_name' => 'Alpha Distributors Ltd', 'trading_name' => 'Alpha'], 'adm');
$uA = $acct->create((int)$A['id'], ['role' => 'head_office', 'display_name' => 'Alice', 'phone' => '+256700000001'], 'adm');
$uid = (int)$uA['id'];

echo "A. migration 082 — tables + totp_confirmed column\n";
foreach (['dist_partner_otp', 'dist_partner_rate'] as $t) {
    is_($pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$t'")->fetchColumn() === $t, "$t table created");
}
$ucols = array_column($pdo->query("PRAGMA table_info(dist_partner_users)")->fetchAll(\PDO::FETCH_ASSOC), 'name');
is_(in_array('totp_confirmed', $ucols, true), 'dist_partner_users gained totp_confirmed (082, additive)');
$ocols = array_column($pdo->query("PRAGMA table_info(dist_partner_otp)")->fetchAll(\PDO::FETCH_ASSOC), 'name');
is_(in_array('code_hash', $ocols, true) && !in_array('code', $ocols, true), 'dist_partner_otp stores code_hash and has NO raw code column');

echo "\nB. TOTP primitive — RFC 6238 vectors, base32, window\n";
$rfc = Totp::base32encode('12345678901234567890');
is_($rfc === 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'base32 of the RFC ASCII key matches the published value');
is_(Totp::at($rfc, 59) === '287082' && Totp::at($rfc, 1111111109) === '081804', 'RFC 6238 SHA1 6-digit vectors match');
is_(Totp::verify($rfc, '287082', 59) && Totp::verify($rfc, Totp::at($rfc, 59), 80), 'verify accepts the code and a ±1 step');
is_(!Totp::verify($rfc, '287082', 100000), 'verify rejects a code far outside the window');
is_(!Totp::verify($rfc, '000000', 59) && !Totp::verify($rfc, 'abc', 59), 'a wrong or non-numeric code is rejected');

echo "\nC. request code — uniform for unknown; hashed for a known active user\n";
$unknown = PartnerAuth::requestLoginCode($pdo, $cfg, '0700999999', $UG, '1.1.1.1', $NOW);
is_($unknown['status'] === 'sent' && $unknown['delivery'] === null, 'an unknown number returns status=sent with NO delivery (reveals nothing)');
is_((int)$pdo->query("SELECT COUNT(*) FROM dist_partner_otp")->fetchColumn() === 0, 'no code row is written for an unknown number');
$req = PartnerAuth::requestLoginCode($pdo, $cfg, '0700000001', $UG, '1.1.1.1', $NOW);
is_($req['status'] === 'sent' && is_array($req['delivery']) && (int)$req['delivery']['user_id'] === $uid, 'a known active number yields a delivery to that account');
$code = (string)$req['delivery']['code'];
is_(preg_match('/^\d{6}$/', $code) === 1, 'the code is 6 digits');
$otpRow = $pdo->query("SELECT * FROM dist_partner_otp WHERE user_id=$uid")->fetch(\PDO::FETCH_ASSOC);
$leak = false; foreach ($otpRow as $v) { if (is_string($v) && $v !== '' && strpos($v, $code) !== false) $leak = true; }
is_(!$leak && $otpRow['code_hash'] === PartnerAuth::hashCode($code, $cfg), 'the code is stored only as HMAC(code,K) — the raw code is in no column');

echo "\nD. sign-in — BOTH factors required; code consumed only on full success\n";
$bad = PartnerAuth::signIn($pdo, $cfg, '0700000001', '000000', '', $UG, '1.1.1.1', $NOW);
is_(($bad['ok'] ?? null) === false && ($bad['reason'] ?? '') === 'invalid', 'a wrong code is refused (uniform invalid)');
$needEnrol = PartnerAuth::signIn($pdo, $cfg, '0700000001', $code, '', $UG, '1.1.1.1', $NOW);
is_(($needEnrol['ok'] ?? null) === false && ($needEnrol['need'] ?? '') === 'totp_enrol', 'a correct code with no authenticator yet asks to enrol (code NOT consumed)');
is_((int)$pdo->query("SELECT COUNT(*) FROM dist_partner_otp WHERE user_id=$uid")->fetchColumn() === 1, 'the code is still present (full sign-in has not happened)');
$en = PartnerAuth::beginEnrol($pdo, $uid);
is_(isset($en['secret'], $en['uri']) && strpos($en['uri'], 'otpauth://totp/') === 0, 'beginEnrol returns a secret and an otpauth URI');
$secret = (string)$en['secret'];
$totp = Totp::at($secret, $NOW);
is_(PartnerAuth::confirmEnrol($pdo, $uid, $totp, $NOW) === true, 'confirmEnrol accepts a valid authenticator code');
$needTotp = PartnerAuth::signIn($pdo, $cfg, '0700000001', $code, '', $UG, '1.1.1.1', $NOW);
is_(($needTotp['need'] ?? '') === 'totp', 'once enrolled, a correct code alone asks for the TOTP (second factor)');
$wrongTotp = PartnerAuth::signIn($pdo, $cfg, '0700000001', $code, '111111', $UG, '1.1.1.1', $NOW);
is_(($wrongTotp['reason'] ?? '') === 'invalid', 'a wrong TOTP is refused');
$ok = PartnerAuth::signIn($pdo, $cfg, '0700000001', $code, $totp, $UG, '1.1.1.1', $NOW);
is_(($ok['ok'] ?? null) === true && (int)$ok['user_id'] === $uid, 'code + valid TOTP signs in');
is_((int)$pdo->query("SELECT COUNT(*) FROM dist_partner_otp WHERE user_id=$uid")->fetchColumn() === 0, 'the code is consumed on full success');
$replay = PartnerAuth::signIn($pdo, $cfg, '0700000001', $code, $totp, $UG, '1.1.1.1', $NOW);
is_(($replay['ok'] ?? null) === false, 'replaying the consumed code + TOTP is refused');

echo "\nE. TOTP enrolment — a confirmed authenticator cannot be silently reset\n";
is_(threw(fn() => PartnerAuth::beginEnrol($pdo, $uid)), 'beginEnrol is refused once the authenticator is confirmed (no silent reset)');
is_(PartnerAuth::confirmEnrol($pdo, $uid, '000000', $NOW) === true, 'confirmEnrol is idempotent once confirmed (no re-verify needed)');

echo "\nF. rate + decaying lock\n";
// fresh account for the rate tests, with tight caps
$uB = $acct->create((int)$A['id'], ['role' => 'head_office', 'phone' => '+256700000002'], 'adm');
$ub = (int)$uB['id'];
$rc = $cfg + ['dist_portal_send_per_hour_user' => 2, 'dist_portal_send_per_hour_ip' => 50];
for ($i = 0; $i < 2; $i++) { $r = PartnerAuth::requestLoginCode($pdo, $rc, '0700000002', $UG, '9.9.9.9', $NOW); }
is_(($r['delivery'] ?? null) !== null, 'the account may request up to its per-hour cap');
$over = PartnerAuth::requestLoginCode($pdo, $rc, '0700000002', $UG, '9.9.9.9', $NOW);
is_(($over['status'] === 'sent') && ($over['delivery'] === null) && !empty($over['throttled']), 'beyond the per-account cap: still uniform "sent", but throttled with no delivery');
// per-ip cap, measured with unknown numbers so the per-user cap never applies
$ic = $cfg + ['dist_portal_send_per_hour_ip' => 3];
for ($i = 0; $i < 3; $i++) { PartnerAuth::requestLoginCode($pdo, $ic, '070012345' . $i, $UG, '8.8.8.8', $NOW); }
$ipOver = PartnerAuth::requestLoginCode($pdo, $ic, '0700123499', $UG, '8.8.8.8', $NOW);
is_(!empty($ipOver['throttled']), 'the per-address cap throttles enumeration from one IP');
// decaying lock
$lc = $cfg + ['dist_portal_fail_threshold' => 3, 'dist_portal_lock_base' => 100, 'dist_portal_code_max_attempts' => 50];
$uC = $acct->create((int)$A['id'], ['role' => 'head_office', 'phone' => '+256700000003'], 'adm');
$uc = (int)$uC['id'];
PartnerAuth::requestLoginCode($pdo, $lc, '0700000003', $UG, '7.7.7.7', $NOW);
is_(PartnerAuth::lockStatus($pdo, $lc, $uc, $NOW)['locked'] === false, 'control: a fresh account is not locked');
for ($i = 0; $i < 3; $i++) { PartnerAuth::signIn($pdo, $lc, '0700000003', '000000', '', $UG, '7.7.7.7', $NOW); }
$locked = PartnerAuth::lockStatus($pdo, $lc, $uc, $NOW);
is_($locked['locked'] === true && $locked['retry_in'] > 0, 'enough failures engage the lock, with a retry_in');
is_((PartnerAuth::signIn($pdo, $lc, '0700000003', '000000', '', $UG, '7.7.7.7', $NOW)['reason'] ?? '') === 'locked', 'a locked account is refused with reason=locked');
is_(PartnerAuth::lockStatus($pdo, $lc, $uc, $NOW + 200)['locked'] === false, 'the lock DECAYS — it clears once the window elapses (not a permanent lock)');

echo "\nG. anti-enumeration — unknown phone answered exactly like a wrong code\n";
$u1 = PartnerAuth::signIn($pdo, $cfg, '0700888888', '123456', '', $UG, '2.2.2.2', $NOW);
is_(($u1['ok'] ?? null) === false && ($u1['reason'] ?? '') === 'invalid' && !isset($u1['need']), 'signing in with an unknown number gives the same invalid as a wrong code (no disclosure)');

echo "\nH. fail-closed key — no server secret → refuse; label-distinct\n";
is_(threw(fn() => PartnerAuth::codeKey([])), 'codeKey throws with no server secret');
is_(threw(fn() => PartnerAuth::requestLoginCode($pdo, [], '0700000001', $UG, '1.1.1.1', $NOW)), 'requestLoginCode refuses with no secret (cannot mint an unverifiable code)');
$k = PartnerAuth::codeKey($cfg);
is_(ctype_xdigit($k) && strlen($k) === 64 && $k !== $cfg['webhook_secret'], 'the code key is a derived 256-bit HMAC, not the raw secret');
$src = nc($root . '/lib/PartnerAuth.php');
is_(strpos($src, 'dn-partner-otp-v1') !== false, 'the code key uses a distinct label (no reuse with the session key)');

echo "\nI. canonical phone — national and international forms resolve the same account\n";
PartnerAuth::requestLoginCode($pdo, $cfg, '+256700000001', $UG, '3.3.3.3', $NOW + 4000); // international form
$intlRow = $pdo->query("SELECT user_id FROM dist_partner_otp WHERE user_id=$uid")->fetchColumn();
is_((int)$intlRow === $uid, 'the international form resolves the account the national form created');

echo "\nJ. manifest version\n";
$mani = json_decode((string)file_get_contents($root . '/manifest.json'), true);
is_(($mani['information']['version'] ?? '') === '5.18.79', 'manifest version is 5.18.71');

exec('rm -rf ' . escapeshellarg($tmp));
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
