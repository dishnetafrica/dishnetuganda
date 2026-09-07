<?php
declare(strict_types=1);
/**
 * The forced first-login password change must actually be completable.
 *
 * The live bug: accounts are created with the default password and
 * must_change_pwd=true; the "Set Your Password" modal has NO current-password
 * field by design, but the API demanded one unconditionally — every new staff
 * member was locked out on the modal. And the CRIT-05 token rotation was
 * being stripped for self-service callers, so the old token survived a
 * password change.
 */
require_once dirname(__DIR__) . '/lib/StoreInterface.php';
require_once dirname(__DIR__) . '/lib/SqliteStore.php';
require_once dirname(__DIR__) . '/lib/RetailerAuth.php';

$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }

$tmp = sys_get_temp_dir() . '/fpwd_test_' . getmypid();
@mkdir($tmp, 0777, true);
$store = SqliteStore::create($tmp);
$auth  = new RetailerAuth($store);

$rec = $store->appendWithId('retailers.json', [
    'name' => 'New Staff', 'phone' => '+256700000001', 'is_active' => true,
    'password' => password_hash('123456', PASSWORD_BCRYPT, ['cost' => 10]),
    'must_change_pwd' => true,
    'api_token' => 'OLDTOKEN-' . str_repeat('a', 55), 'token_issued_at' => 1,
]);
$rid = (int)$rec['id'];

echo "First-login state\n";
t('default password verifies', $auth->verifyPassword($rid, '123456'), true);
t('record carries must_change_pwd', !empty($store->findOne('retailers.json', 'id', $rid)['must_change_pwd']), true);

echo "\nSelf-service password change (the modal's exact call shape)\n";
$ok = $auth->updateRetailer($rid, ['password' => 'Str0ng!Password'], false);
t('update accepted', $ok, true);
$row = $store->findOne('retailers.json', 'id', $rid);
t('forced flag cleared on the RECORD', empty($row['must_change_pwd']), true);
t('old default no longer verifies', $auth->verifyPassword($rid, '123456'), false);
t('new password verifies', $auth->verifyPassword($rid, 'Str0ng!Password'), true);
t('CRIT-05: API token actually rotated for a self-service change',
  ($row['api_token'] ?? '') !== '' && strpos((string)$row['api_token'], 'OLDTOKEN-') === false, true);
t('token_issued_at refreshed', (int)($row['token_issued_at'] ?? 0) > 1, true);

echo "\nPrivilege strip still holds for non-admin callers\n";
$auth->updateRetailer($rid, ['is_admin' => 1, 'role' => 'admin', 'wallet' => 999999], false);
$row = $store->findOne('retailers.json', 'id', $rid);
t('is_admin not self-escalatable', empty($row['is_admin']), true);
t('role not self-escalatable', ($row['role'] ?? '') !== 'admin', true);
t('wallet not self-writable', (float)($row['wallet'] ?? 0), 0.0);

echo "\nThe 5-minute session cache cannot haunt a changed password\n";
// currentRetailer() serves a cached copy under $_SESSION['kyc_retailer'];
// simulate the exact live bug: record cleared, cache still carries the flag.
$_SESSION['kyc_retailer'] = [
    'id' => $rid,
    'cached_record'   => array_merge($row, ['must_change_pwd' => true]),
    'cache_refreshed' => time(),
];
$cur = $auth->currentRetailer();
t('fresh cache serves the stale flag (the haunting)', !empty($cur['must_change_pwd']), true);
// The busted cache (what change_password now does) forces a DB refresh.
$_SESSION['kyc_retailer']['cache_refreshed'] = 0;
$cur = $auth->currentRetailer();
t('busted cache re-reads the RECORD — flag gone', empty($cur['must_change_pwd']), true);
unset($_SESSION['kyc_retailer']);

echo "\nAPI contract (source guards)\n";
$api = (string)file_get_contents(dirname(__DIR__) . '/includes/api/api_retailer.php');
t('handler reads the authoritative RECORD flag',
  strpos($api, "\$recNow      = \$store->findOne('retailers.json', 'id', \$rid)") !== false, true);
t('current password demanded ONLY outside the forced first-run change',
  strpos($api, 'if (!$forcedFirst) {') !== false, true);
t('current password still verified on ordinary profile changes',
  strpos($api, "verifyPassword(\$rid, \$curPwd)) \$er2('Current password is incorrect.')") !== false, true);
$pub = (string)file_get_contents(dirname(__DIR__) . '/public.php');
t('modal reloads after success so the rotated token is picked up',
  strpos($pub, 'location.reload(); }, 800);') !== false, true);
t('handler busts the REAL session cache key (kyc_retailer)',
  strpos($api, "\$_SESSION['kyc_retailer']['cache_refreshed'] = 0;") !== false
  && strpos($api, "\$_SESSION['dn_retailer']['must_change_pwd']") === false, true);
t('render gate re-checks the record before showing the modal',
  strpos($pub, "\$_fpFresh = \$store->findOne('retailers.json', 'id'") !== false, true);

exec('rm -rf ' . escapeshellarg($tmp));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
