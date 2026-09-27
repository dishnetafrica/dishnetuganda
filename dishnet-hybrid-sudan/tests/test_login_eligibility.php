<?php
declare(strict_types=1);
/**
 * test_login_eligibility.php — who may ask for a sign-in code (Phase 2 of the
 * customer-login audit, plan §E.6 / §B.3-G; decisions D-14, D-15).
 *
 * The customer index (migration 074) carries the e-mail identifier and the
 * eligibility facts; one row builder fills them (lib/ClientSearchIndex.php,
 * used by cron_sync and the webhook). The gates read them: a lead or an
 * archived client is refused with the UNIFORM answer and an otp_ineligible
 * audit row; an unknown flag never refuses; the two keys flip the rule; the
 * e-mail identifier now matches a customer.
 */
$pass = 0; $fail = 0;
function t(string $n, $got, $want): void { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d !== '' ? "\n       $d" : '') . "\n"; } }
function codeNC(string $f): string {
    $o = '';
    foreach (token_get_all((string)file_get_contents($f)) as $k) {
        if (is_array($k)) { if (in_array($k[0], [T_COMMENT, T_DOC_COMMENT], true)) continue; $o .= $k[1]; }
        else $o .= $k;
    }
    return $o;
}
$root = dirname(__DIR__);
if (!getenv('DN_VAULT_FILE')) putenv('DN_VAULT_FILE=' . tempnam(sys_get_temp_dir(), 'dn-vault-'));
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/ClientSearchIndex.php';

$sandbox = sys_get_temp_dir() . '/dn_elig_' . getmypid(); $tmp = $sandbox . '/plugin'; $data = $tmp . '/data';
exec('rm -rf ' . escapeshellarg($sandbox)); @mkdir($data, 0700, true);
register_shutdown_function(function () use ($sandbox) { exec('rm -rf ' . escapeshellarg($sandbox)); });
exec(sprintf('cp -R %s/. %s/ 2>/dev/null', escapeshellarg($root), escapeshellarg($tmp)));
exec('rm -rf ' . escapeshellarg($data)); @mkdir($data, 0700, true);
file_put_contents($tmp . '/ucrm.json', json_encode(['pluginDataDir' => $data]));
$store = SqliteStore::create($data);
$pdo = $store->getPdo();

echo "\n1. Migration 074 and the row builder\n";
is_(is_file($root . '/migrations/074_client_search_index_flags.sql'), 'migration 074 exists');
is_(ClientSearchIndex::hasFlags($pdo), 'a fresh store has the flag columns (the migration ran at open)');
$cols = ClientSearchIndex::columns($pdo);
foreach (['email', 'is_lead', 'is_archived', 'is_active', 'client_type', 'has_service', 'has_invoice'] as $c) is_(in_array($c, $cols, true), "column $c");
$client = ['id' => 11, 'firstName' => 'Lead', 'lastName' => 'Person', 'isLead' => true, 'isArchived' => false, 'isActive' => true, 'clientType' => 1,
           'contacts' => [['phone' => '+256 701 000 011', 'email' => 'Lead.Person@Example.test']]];
$row = ClientSearchIndex::rowFor($client, [11 => ['Starlink Standard']], [11 => true]);
t('rowFor: id/name/phone/phone_norm', [$row['id'], $row['name'], $row['phone'], $row['phone_norm']], [11, 'Lead Person', '+256 701 000 011', '701000011']);
t('rowFor: e-mail lower-cased', $row['email'], 'lead.person@example.test');
t('rowFor: flags', [$row['is_lead'], $row['is_archived'], $row['is_active'], $row['client_type'], $row['has_service'], $row['has_invoice']], [1, 0, 1, 1, 1, 1]);
t('rowFor: unknown facts stay null', array_values(array_intersect_key(ClientSearchIndex::rowFor(['id' => 5, 'firstName' => 'X']), ['is_lead' => 1, 'is_archived' => 1, 'is_active' => 1, 'client_type' => 1, 'has_invoice' => 1])), [null, null, null, null, null]);
t('rowFor: has_service is 0 when the services cache is known and lists nothing', ClientSearchIndex::rowFor(['id' => 5], [9 => ['x']], null, true)['has_service'], 0);
t('rowFor: has_service is null when the services cache is unknown', ClientSearchIndex::rowFor(['id' => 5], [], null, false)['has_service'], null);
t('rowFor: no id → null', ClientSearchIndex::rowFor(['firstName' => 'X']), null);
ClientSearchIndex::upsertClient($store, $client);
$db = $pdo->query("SELECT id, email, is_lead, is_archived, has_service FROM client_search_index WHERE id = 11")->fetch(\PDO::FETCH_ASSOC);
t('upsertClient() wrote the row (services cache empty → has_service unknown)', [(int)$db['id'], $db['email'], (int)$db['is_lead'], (int)$db['is_archived'], $db['has_service']], [11, 'lead.person@example.test', 1, 0, null]);
// the rest of the estate, seeded the way cron_sync writes it
$seed = function (array $c, ?int $lead, ?int $arch, ?int $svc) use ($pdo): void {
    $pdo->prepare("REPLACE INTO client_search_index (id, name, phone, phone_norm, service, email, is_lead, is_archived, is_active, client_type, has_service, has_invoice, updated_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?, NULL, datetime('now'))")
        ->execute([$c['id'], $c['name'], $c['phone'], substr(preg_replace('/\D/', '', $c['phone']), -9), 'Starlink', $c['email'] ?? '', $lead, $arch, $svc]);
};
$seed(['id' => 12, 'name' => 'Archived One', 'phone' => '+256701000012'], 0, 1, 1);
$seed(['id' => 13, 'name' => 'Active Customer', 'phone' => '+256701000013', 'email' => 'customer@example.test'], 0, 0, 1);
$seed(['id' => 14, 'name' => 'Unsynced Row', 'phone' => '+256701000014'], null, null, null);
$seed(['id' => 16, 'name' => 'No Service Yet', 'phone' => '+256701000016'], 0, 0, 0);
$store->save('kyc_config.json', ['dry_run_mode' => true, 'data_dir' => $data, 'tenant_profile' => 'uganda',
    'wa_plugin_url' => 'http://127.0.0.1:1/', 'wa_app_key' => 'k', 'wa_auth_key' => 'a', 'app_otp_limit_per_hour' => 20, 'app_otp_ip_limit_per_hour' => 100]);
$ADMIN_TOKEN = 'TEST-ADMIN-' . bin2hex(random_bytes(12));
$store->appendWithId('retailers.json', ['name' => 'Test Admin', 'email' => 'admin@example.test', 'is_active' => true, 'is_admin' => true,
    'api_token' => $ADMIN_TOKEN, 'token_issued_at' => time(), 'password' => password_hash('x', PASSWORD_BCRYPT, ['cost' => 4])]);
unset($pdo, $store);

echo "\n2. The plugin under php -S\n";
$nonce = bin2hex(random_bytes(8)); file_put_contents($tmp . '/__nonce.txt', $nonce);
$probe = function (string $url): ?string { $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2, CURLOPT_PROXY => '']);
    $r = curl_exec($ch); $c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); return ($r === false || $c !== 200) ? null : (string)$r; };
$srv = null; $port = 0;
foreach (range(0, 9) as $slot) {
    $cand = 9200 + ((getmypid() + $slot * 31) % 180);
    $p = proc_open(sprintf('exec php -S 127.0.0.1:%d -t %s', $cand, escapeshellarg($tmp)), [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    $ours = false;
    for ($i = 0; $i < 60; $i++) { $got = $probe("http://127.0.0.1:{$cand}/__nonce.txt"); if ($got !== null) { $ours = trim($got) === $nonce; break; } usleep(100000); }
    if ($ours) { $srv = $p; $port = $cand; break; }
    proc_terminate($p); proc_close($p);
}
@unlink($tmp . '/__nonce.txt');
if ($srv === null) { echo "  FAIL could not start the plugin under php -S\n"; exit(1); }
register_shutdown_function(function () use (&$srv) { if (is_resource($srv)) { proc_terminate($srv); proc_close($srv); } });
$base = "http://127.0.0.1:{$port}/public.php";
is_(true, "server up on 127.0.0.1:{$port}");
$api = function (string $method, string $action, ?array $body = null, array $headers = []) use ($base): array {
    $ch = curl_init($base . '?page=api&action=' . $action);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_PROXY => '', CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers)]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $j = json_decode((string)$r, true);
    return ['code' => $r === false ? 0 : $code, 'json' => is_array($j) ? $j : null, 'raw' => (string)$r];
};
$q = function (string $sql, array $p = []) use ($data): int { $st = SqliteStore::create($data)->getPdo()->prepare($sql); $st->execute($p); return (int)$st->fetchColumn(); };
$dryCount = function () use ($data): int { return count(json_decode((string)@file_get_contents($data . '/dry_run_notification_log.json'), true) ?: []); };
$shape = function (array $r): array { $d = $r['json']['data'] ?? []; unset($d['server_time']); return [$r['code'], $r['json']['message'] ?? null, $d]; };
$setCfg = function (array $extra) use ($data): void { $s = SqliteStore::create($data); $c = $s->load('kyc_config.json'); $s->save('kyc_config.json', array_merge($c, $extra)); };

echo "\n3. The gates, with the defaults (leads refused, archived refused, service not required)\n";
$unknown = $api('POST', 'app_send_otp', ['phone' => '+256700000999']);
$lead    = $api('POST', 'app_send_otp', ['phone' => '+256701000011']);
t('a lead is answered exactly like an unknown number', $shape($lead), $shape($unknown));
t('…audited otp_ineligible reason lead', $q("SELECT COUNT(*) FROM app_audit_log WHERE action = 'otp_ineligible' AND details LIKE '%\"reason\":\"lead\"%'"), 1);
t('…and no code was sent', $dryCount(), 0);
$arch = $api('POST', 'app_send_otp', ['phone' => '+256701000012']);
t('an archived client is answered like an unknown number', $shape($arch), $shape($unknown));
t('…audited otp_ineligible reason archived', $q("SELECT COUNT(*) FROM app_audit_log WHERE action = 'otp_ineligible' AND details LIKE '%\"reason\":\"archived\"%'"), 1);
t('…no code', $dryCount(), 0);
$act = $api('POST', 'app_send_otp', ['phone' => '+256701000013']);
t('an active customer gets a code (same answer shape)', $shape($act), $shape($unknown));
t('…one send', $dryCount(), 1);
$uns = $api('POST', 'app_send_otp', ['phone' => '+256701000014']);
t('a row not yet synced (all flags NULL) is allowed — no lockout window', $dryCount(), 2);
$nos = $api('POST', 'app_send_otp', ['phone' => '+256701000016']);
t('a customer with no service is allowed while the service gate is off', $dryCount(), 3);

echo "\n4. The e-mail identifier matches through the new column\n";
$r = $api('POST', 'app_send_otp', ['email' => 'Customer@Example.TEST']);
t('an e-mail sign-in: 200 Code sent via Email.', [$r['code'], $r['json']['message'] ?? null], [200, 'Code sent via Email.']);
t('…the account was found (no otp_no_account_email row for it)', $q("SELECT COUNT(*) FROM app_audit_log WHERE action = 'otp_no_account_email' AND phone = 'customer@example.test'"), 0);
t('…the attempt was audited for the customer (sent, or failed to send: no mailer here)', $q("SELECT COUNT(*) FROM app_audit_log WHERE action IN ('otp_sent','otp_send_failed') AND phone = 'customer@example.test' AND crm_client_id = 13"), 1);
$r = $api('POST', 'app_send_otp', ['email' => 'nobody@example.test']);
t('an unknown e-mail: the same answer', [$r['code'], $r['json']['message'] ?? null], [200, 'Code sent via Email.']);
t('…audited otp_no_account_email', $q("SELECT COUNT(*) FROM app_audit_log WHERE action = 'otp_no_account_email' AND phone = 'nobody@example.test'"), 1);

echo "\n5. The two keys flip the rule\n";
$setCfg(['portal_login_allow_leads' => 'yes']);
$api('POST', 'app_send_otp', ['phone' => '+256701000011']);
t('portal_login_allow_leads=yes: the lead gets a code', $dryCount(), 4);
$setCfg(['portal_login_allow_leads' => 'no', 'portal_login_require_service' => 'yes']);
$api('POST', 'app_send_otp', ['phone' => '+256701000016']);
t('portal_login_require_service=yes: no service → refused', $q("SELECT COUNT(*) FROM app_audit_log WHERE action = 'otp_ineligible' AND details LIKE '%\"reason\":\"no_service\"%'"), 1);
t('…no code', $dryCount(), 4);
$api('POST', 'app_send_otp', ['phone' => '+256701000013']);
t('…while the customer with a service still gets one', $dryCount(), 5);
$api('POST', 'app_send_otp', ['phone' => '+256701000014']);
t('…and the unsynced row (has_service NULL) is still allowed', $dryCount(), 6);
$api('POST', 'app_send_otp', ['phone' => '+256701000012']);
t('archived is refused whatever the keys say', $q("SELECT COUNT(*) FROM app_audit_log WHERE action = 'otp_ineligible' AND details LIKE '%\"reason\":\"archived\"%'"), 2);
$setCfg(['portal_login_require_service' => 'no']);

echo "\n6. Staff see why\n";
$r = $api('GET', 'staff_login_lookup&phone=' . rawurlencode('+256701000011'), null, ['Authorization: Bearer ' . $ADMIN_TOKEN]);
t('the lead: eligible false, refused_because lead', [$r['json']['data']['accounts'][0]['eligible'] ?? null, $r['json']['data']['accounts'][0]['refused_because'] ?? null], [false, 'lead']);
t('…the flags are shown', (int)($r['json']['data']['accounts'][0]['flags']['is_lead'] ?? -1), 1);
t('…and the defaults in force', $r['json']['data']['eligibility_defaults'] ?? null, ['allow_leads' => false, 'require_service' => false]);
$r = $api('GET', 'staff_login_lookup&phone=' . rawurlencode('+256701000013'), null, ['Authorization: Bearer ' . $ADMIN_TOKEN]);
t('the active customer: eligible', $r['json']['data']['accounts'][0]['eligible'] ?? null, true);

echo "\n7. One row builder for every writer\n";
$wh = codeNC($root . '/webhook.php');
t('webhook.php upserts the verified client on client.add and client.edit', substr_count($wh, 'ClientSearchIndex::upsertClient($store, $client)'), 2);
$cs = codeNC($root . '/cron_sync.php');
is_(strpos($cs, 'ClientSearchIndex::upsertMany(') !== false && strpos($cs, 'REPLACE INTO client_search_index') === false, 'cron_sync.php writes the table through the row builder, no SQL of its own');
$app = codeNC($root . '/includes/api/api_customer_app.php');
is_(strpos($app, 'ca_login_eligibility($client, $config)') !== false && strpos($app, "'otp_ineligible'") !== false, 'app_send_otp gates after the lookup and audits the refusal');
is_(strpos($app, "portal_login_allow_leads'] ?? null, false") !== false && strpos($app, "portal_login_require_service'] ?? null, false") !== false, 'the defaults are the narrowest safe reading (no, no)');
$manifest = json_decode((string)file_get_contents($root . '/manifest.json'), true);
$keys = array_column($manifest['configuration'] ?? [], 'key');
foreach (['tenant_profile', 'portal_login_allow_leads', 'portal_login_require_service', 'app_jwt_ttl_days'] as $k) is_(in_array($k, $keys, true), "manifest declares $k");

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
