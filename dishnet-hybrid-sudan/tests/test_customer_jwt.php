<?php
declare(strict_types=1);
/**
 * test_customer_jwt.php — the customer-portal signing keys and token contract
 * (Phase 2 of the customer-login audit, plan §E.1–E.3, decisions D-1, D-9, D-10).
 *
 * Proves: a key set is generated once and vaulted; new tokens carry kid, iss
 * and aud; a token signed with the legacy derivation (a constant on an install
 * where webhook_secret and crm_auth_token are empty) is REFUSED; so are an
 * unknown kid, a wrong issuer, a wrong audience and a foreign algorithm;
 * rotation keeps the old key verifying until pruned; the tool prints no secret.
 */
$pass = 0; $fail = 0;
function t(string $n, $got, $want): void { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d !== '' ? "\n       $d" : '') . "\n"; } }
function throws(callable $f, string $needle = ''): bool { try { $f(); return false; } catch (\Throwable $e) { return $needle === '' || strpos($e->getMessage(), $needle) !== false; } }

$root = dirname(__DIR__);
if (!getenv('DN_VAULT_FILE')) putenv('DN_VAULT_FILE=' . tempnam(sys_get_temp_dir(), 'dn-vault-'));
@unlink((string)getenv('DN_VAULT_FILE'));
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/ConfigVault.php';
require_once $root . '/lib/JwtAuth.php';
require_once $root . '/lib/CustomerJwtKeys.php';
require_once $root . '/lib/PluginConfig.php';

$dir = sys_get_temp_dir() . '/dn_cjwt_' . getmypid(); @mkdir($dir, 0700, true);
putenv('DN_DATA_DIR=' . $dir);
$store = SqliteStore::create($dir);
$store->save('kyc_config.json', ['dry_run_mode' => true]);
$config = $store->load('kyc_config.json');

echo "1. Provisioning\n";
is_(!CustomerJwtKeys::provisioned($config), 'a fresh install has no customer key');
is_(throws(function () use ($config) { JwtAuth::forCustomers($config); }, 'not provisioned'), 'forCustomers() refuses to sign with no key');
is_(CustomerJwtKeys::ensure($store, $config), 'ensure() provisions a key set');
t('the first key id is k1', CustomerJwtKeys::activeKid($config), 'k1');
$keys = CustomerJwtKeys::keys($config);
is_(isset($keys['k1']) && preg_match('/^[0-9a-f]{64}$/', $keys['k1']) === 1, 'k1 is 64 hex characters (32 random bytes)');
$stored = $store->load('kyc_config.json');
is_(is_string($stored['customer_jwt_keys'] ?? null) && json_decode($stored['customer_jwt_keys'], true) === $keys, 'the store holds the key map as a JSON string');
$before = $keys['k1'];
is_(CustomerJwtKeys::ensure($store, $config) && CustomerJwtKeys::keys($config)['k1'] === $before, 'a second ensure() changes nothing');
$vault = json_decode((string)file_get_contents((string)getenv('DN_VAULT_FILE')), true);
is_(($vault['config']['customer_jwt_keys'] ?? null) === $stored['customer_jwt_keys'] && ($vault['config']['customer_jwt_active_kid'] ?? null) === 'k1', 'the vault holds the keys and the active kid (a re-install restores them)');
foreach (['customer_jwt_keys', 'customer_jwt_active_kid', 'customer_jwt_key_dates', 'tenant_profile'] as $k) is_(in_array($k, ConfigVault::VAULT_KEYS, true), "ConfigVault::VAULT_KEYS carries $k");
is_(in_array('customer_jwt_keys', PluginConfig::SECRET_KEYS, true), 'PluginConfig::SECRET_KEYS carries customer_jwt_keys');
t('redacted() shows [set], never the value', PluginConfig::redacted(['customer_jwt_keys' => $stored['customer_jwt_keys']])['customer_jwt_keys'], '[set]');

echo "2. The restore path: a lost store copy comes back from the vault\n";
$store->save('kyc_config.json', ['dry_run_mode' => true]);     // the re-install: store wiped, vault kept
$cfg2 = $store->load('kyc_config.json');
is_(!CustomerJwtKeys::provisioned($cfg2), 'the wiped store has no key');
is_(CustomerJwtKeys::ensure($store, $cfg2), 'ensure() succeeds on the wiped store');
t('…and restores the SAME k1 from the vault, so nobody is signed out', CustomerJwtKeys::keys($cfg2)['k1'] ?? null, $before);
$config = $store->load('kyc_config.json');

echo "3. The token contract\n";
$jwt = JwtAuth::forCustomers($config, 3600);
t('kid() is the active key', $jwt->kid(), 'k1');
$tok = $jwt->issue(['sub' => 7, 'kind' => 'app', 'phone' => '+256772123456', 'login_mode' => 'phone']);
[$h64, $c64] = explode('.', $tok);
$hdr = json_decode(base64_decode(strtr($h64, '-_', '+/')), true); $cl = json_decode(base64_decode(strtr($c64, '-_', '+/')), true);
t('header alg', $hdr['alg'] ?? null, 'HS256');
t('header kid', $hdr['kid'] ?? null, 'k1');
t('claim iss', $cl['iss'] ?? null, JwtAuth::customerIssuer());
t('claim aud', $cl['aud'] ?? null, 'customer-portal');
is_(isset($cl['jti'], $cl['iat'], $cl['exp']) && $cl['exp'] - $cl['iat'] === 3600, 'jti/iat/exp present, exp = iat + ttl');
$back = $jwt->verify($tok);
t('verify() returns the claims', $back['sub'] ?? null, 7);
t('login_mode travels in the token', $back['login_mode'] ?? null, 'phone');

echo "4. What is refused\n";
$legacy = JwtAuth::fromConfig(['webhook_secret' => '', 'crm_auth_token' => '']);
$legacyTok = $legacy->issue(['sub' => 7, 'kind' => 'app', 'phone' => '+256772123456']);
is_(throws(function () use ($jwt, $legacyTok) { $jwt->verify($legacyTok); }, 'Unknown JWT key id'), 'a legacy-derived token (no kid) is refused — E3-a');
$constantKey = hash('sha256', '||DishNet-Hybrid-JWT-v2-2026');
$forged = (new JwtAuth($constantKey, 3600))->issue(['sub' => 7, 'kind' => 'app']);
is_(throws(function () use ($jwt, $forged) { $jwt->verify($forged); }), 'a token signed with the constant anyone can read in the source is refused');
// an unknown kid with a valid-looking signature under some other key
$other = new JwtAuth(bin2hex(random_bytes(32)), 3600);
$otherTok = $other->issue(['sub' => 7, 'kind' => 'app']);
$parts = explode('.', $otherTok); $parts[0] = rtrim(strtr(base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT', 'kid' => 'k9'])), '+/', '-_'), '=');
is_(throws(function () use ($jwt, $parts) { $jwt->verify(implode('.', $parts)); }, 'Unknown JWT key id'), 'an unknown kid is refused before any signature check');
// wrong issuer / audience under the RIGHT key
$right = new JwtAuth($keys['k1'], 3600);
$mk = function (array $claims) use ($right): string {
    $h = rtrim(strtr(base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT', 'kid' => 'k1'])), '+/', '-_'), '=');
    $c = rtrim(strtr(base64_encode(json_encode($claims + ['iat' => time(), 'exp' => time() + 600, 'jti' => 'x'])), '+/', '-_'), '=');
    $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', "$h.$c", $GLOBALS['keys']['k1'], true)), '+/', '-_'), '=');
    return "$h.$c.$sig";
};
is_(throws(function () use ($jwt, $mk) { $jwt->verify($mk(['sub' => 7, 'kind' => 'app', 'iss' => 'someone-else', 'aud' => 'customer-portal'])); }, 'issuer or audience'), 'a wrong issuer is refused even under the right key');
is_(throws(function () use ($jwt, $mk) { $jwt->verify($mk(['sub' => 7, 'kind' => 'app', 'iss' => JwtAuth::customerIssuer(), 'aud' => 'data-report'])); }, 'issuer or audience'), 'a wrong audience is refused even under the right key');
is_(!throws(function () use ($jwt, $mk) { $jwt->verify($mk(['sub' => 7, 'kind' => 'app', 'iss' => JwtAuth::customerIssuer(), 'aud' => 'customer-portal'])); }), 'control: the right key, issuer and audience verify');
$p = explode('.', $tok); $p[0] = rtrim(strtr(base64_encode(json_encode(['alg' => 'none', 'typ' => 'JWT', 'kid' => 'k1'])), '+/', '-_'), '=');
is_(throws(function () use ($jwt, $p) { $jwt->verify(implode('.', $p)); }, 'Unsupported JWT header'), 'alg=none is refused');
$expired = $mk(['sub' => 7, 'kind' => 'app', 'iss' => JwtAuth::customerIssuer(), 'aud' => 'customer-portal', 'exp' => time() - 100]);
is_(throws(function () use ($jwt, $expired) { $jwt->verify($expired); }, 'expired'), 'an expired token is refused');
$tampered = substr($tok, 0, -3) . 'abc';
is_(throws(function () use ($jwt, $tampered) { $jwt->verify($tampered); }), 'a tampered signature is refused');

echo "5. Rotation and pruning\n";
$new = CustomerJwtKeys::rotate($store, $config);
t('rotate() adds k2 and makes it active', $new, 'k2');
t('activeKid is k2', CustomerJwtKeys::activeKid($config), 'k2');
$jwt2 = JwtAuth::forCustomers($config, 3600);
is_(!throws(function () use ($jwt2, $tok) { $jwt2->verify($tok); }), 'a token signed with k1 still verifies after the rotation (nobody is signed out)');
$tok2 = $jwt2->issue(['sub' => 7, 'kind' => 'app']);
t('new tokens carry k2', json_decode(base64_decode(strtr(explode('.', $tok2)[0], '-_', '+/')), true)['kid'] ?? null, 'k2');
t('prune() right after the rotation removes nothing (k1 tokens may still be live)', CustomerJwtKeys::prune($store, $config), []);
$dates = json_decode($store->load('kyc_config.json')['customer_jwt_key_dates'], true);
$future = ($dates['k2'] ?? time()) + CustomerJwtKeys::ttlSeconds($config) + 1;
t('prune() once every k1 token has expired removes k1', CustomerJwtKeys::prune($store, $config, $future), ['k1']);
is_(throws(function () use ($config, $tok) { JwtAuth::forCustomers($config, 3600)->verify($tok); }, 'Unknown JWT key id'), '…and a k1 token is refused from then on');
is_(!throws(function () use ($config, $tok2) { JwtAuth::forCustomers($config, 3600)->verify($tok2); }), 'while k2 tokens still verify');
t('rotate() again numbers k3', CustomerJwtKeys::rotate($store, $config), 'k3');
$desc = CustomerJwtKeys::describe($config);
is_(count($desc) === 2 && $desc[1]['kid'] === 'k3' && $desc[1]['active'] === true, 'describe() lists kids, the active one and dates');
is_(strpos(json_encode($desc), CustomerJwtKeys::keys($config)['k3']) === false, '…and never a secret');

echo "6. The tool prints no secret\n";
$env = 'DN_DATA_DIR=' . escapeshellarg($dir) . ' DN_VAULT_FILE=' . escapeshellarg((string)getenv('DN_VAULT_FILE'));
$out = shell_exec("$env php " . escapeshellarg($root . '/tools/customer_jwt_key.php') . ' 2>&1');
is_(is_string($out) && strpos($out, 'k3') !== false && strpos($out, 'ACTIVE') !== false, 'the tool lists the active key id', (string)$out);
$leak = false; foreach (CustomerJwtKeys::keys($config) as $secret) if (strpos((string)$out, $secret) !== false) $leak = true;
is_(!$leak, 'the tool output contains no key');
$out2 = shell_exec("$env php " . escapeshellarg($root . '/tools/customer_jwt_key.php') . ' --rotate 2>&1');
is_(is_string($out2) && strpos($out2, 'k4') !== false, '--rotate reports the new key id', (string)$out2);
$leak = false; foreach (CustomerJwtKeys::keys($store->load('kyc_config.json')) as $secret) if (strpos((string)$out2, $secret) !== false) $leak = true;
is_(!$leak, '--rotate prints no key');
$src = file_get_contents($root . '/tools/customer_jwt_key.php');
is_(strpos($src, "\$r['kid']") !== false && !preg_match('/echo[^;]*keys\(\)/', $src), 'the tool source prints describe() rows only');

echo "7. The malformed store value never yields a key\n";
t('a non-hex key is ignored', CustomerJwtKeys::keys(['customer_jwt_keys' => json_encode(['k1' => 'not-hex'])]), []);
t('a bad kid is ignored', CustomerJwtKeys::keys(['customer_jwt_keys' => json_encode(['evil' => str_repeat('a', 64)])]), []);
t('an unparseable value is ignored', CustomerJwtKeys::keys(['customer_jwt_keys' => '{not json']), []);
t('a kid the map lacks is not active', CustomerJwtKeys::provisioned(['customer_jwt_keys' => json_encode(['k1' => str_repeat('a', 64)]), 'customer_jwt_active_kid' => 'k2']), false);

exec('rm -rf ' . escapeshellarg($dir));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
