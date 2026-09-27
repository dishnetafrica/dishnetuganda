<?php
declare(strict_types=1);
/**
 * Jobs audit — sandbox end-to-end trace (READ-ONLY audit instrument; docs/43 §6).
 *
 *   php scripts/harness/jobs-trace/trace.php
 *
 * Runs the REAL plugin code — public.php?page=crm_webhook (webhook.php), the staff
 * API (public.php?page=api), public.php?page=evo_webhook and the two job crons —
 * against a fake uCRM and the repository's fake Evolution server, all on 127.0.0.1.
 * Every person, phone, e-mail and job is fictitious. Nothing leaves this machine.
 *
 * Each line states what the current code DOES; "ok" means the observation matched
 * what the code reading predicted, not that the behaviour is right.
 */
$root = dirname(__DIR__, 3) . '/dishnet-hybrid-sudan';
$here = __DIR__;
$pass = 0; $fail = 0; $trace = [];
function ok(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d !== '' ? "\n       $d" : '') . "\n"; } }
function note(string $k, string $v): void { global $trace; $trace[$k] = $v; echo "  ..   $k: $v\n"; }

foreach (['http_proxy', 'https_proxy', 'HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY', 'all_proxy'] as $v) putenv($v);
$vault = tempnam(sys_get_temp_dir(), 'dn-vault-trace-'); putenv('DN_VAULT_FILE=' . $vault);
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';

$sb = sys_get_temp_dir() . '/jobs_trace_' . getmypid(); $plug = $sb . '/plugin'; $data = $plug . '/data';
exec('rm -rf ' . escapeshellarg($sb)); @mkdir($plug, 0700, true);
exec(sprintf('cp -R %s/. %s/ 2>/dev/null', escapeshellarg($root), escapeshellarg($plug)));
exec('rm -rf ' . escapeshellarg($data)); @mkdir($data, 0700, true);
file_put_contents($plug . '/ucrm.json', json_encode(['pluginDataDir' => $data]));
$procs = [];
register_shutdown_function(function () use (&$procs, $sb, $vault) {
    foreach ($procs as $p) { if (is_resource($p)) { proc_terminate($p); proc_close($p); } }
    exec('rm -rf ' . escapeshellarg($sb)); @unlink($vault);
    foreach (glob(sys_get_temp_dir() . '/fake_evo_state_*.json') ?: [] as $f) @unlink($f);
});

$http = function (string $method, string $url, $body = null, array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_PROXY => '', CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
    $r = curl_exec($ch); $c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$r === false ? 0 : $c, (string)$r, json_decode((string)$r, true)];
};
$env = array_filter(getenv(), fn($k) => stripos($k, 'proxy') === false, ARRAY_FILTER_USE_KEY);
$start = function (string $cmd, callable $isUp, int $base, array $extraEnv = []) use (&$procs, $env): int {
    foreach (range(0, 9) as $slot) {
        $port = $base + ((getmypid() + $slot * 13) % 150);
        $p = proc_open(str_replace('{PORT}', (string)$port, $cmd), [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
                       $pipes, null, array_merge($env, $extraEnv));
        $up = false; for ($i = 0; $i < 60 && !$up; $i++) { usleep(100000); $up = $isUp($port); }
        if ($up) { $procs[] = $p; return $port; }
        if (is_resource($p)) { proc_terminate($p); proc_close($p); }
    }
    return 0;
};

// ── Fake uCRM ────────────────────────────────────────────────────────────────
$crmState = $sb . '/fake_ucrm.json';
$crmPort = $start(sprintf('exec php -S 127.0.0.1:{PORT} %s', escapeshellarg($here . '/fake_ucrm_jobs.php')),
    function (int $p) use ($http): bool { return strpos($http('GET', "http://127.0.0.1:{$p}/__test/state")[1], 'FAKE-UCRM-JOBS') !== false; },
    11200, ['FAKE_UCRM_STATE' => $crmState]);
if (!$crmPort) { echo "FAIL fake uCRM did not start\n"; exit(1); }
$crm = "http://127.0.0.1:{$crmPort}";
$dump = function () use ($http, $crm): array { return $http('GET', "$crm/__test/dump")[2] ?? []; };
$reqs = function (string $method, string $pathRe) use ($dump): array {
    return array_values(array_filter($dump()['requests'] ?? [], fn($r) => $r['method'] === $method && preg_match($pathRe, $r['path'])));
};
$job = function (int $id, string $title, ?int $user, array $extra = []): array {
    return array_merge(['id' => $id, 'title' => $title, 'description' => 'sandbox job', 'clientId' => 15, 'assignedUserId' => $user,
        'date' => '2026-10-05T00:00:00+0300', 'timeFrom' => '2026-10-05T09:00:00+0300', 'timeTo' => '2026-10-05T11:00:00+0300',
        'duration' => 120, 'status' => 0, 'address' => 'Plot 1 Test Road, Kampala', 'gpsLat' => 0.3136, 'gpsLon' => 32.5811], $extra);
};
$http('POST', "$crm/__test/seed", [
    'users' => [
        '10' => ['id' => 10, 'username' => 'sb-admin', 'firstName' => 'Sandbox', 'lastName' => 'Admin', 'email' => 'admin@example.test'],
        '11' => ['id' => 11, 'username' => 'sb-tech1', 'firstName' => 'Tech', 'lastName' => 'One',   'email' => 'tech1@example.test', 'phone' => '+256 700 000 111'],
        '12' => ['id' => 12, 'username' => 'sb-tech2', 'firstName' => 'Tech', 'lastName' => 'Two',   'email' => 'tech2@example.test', 'phone' => ''],
        '13' => ['id' => 13, 'username' => 'sb-tech3', 'firstName' => 'Tech', 'lastName' => 'Three', 'email' => 'tech3@example.test', 'phone' => '0700 000 113'],
    ],
    'clients' => ['15' => ['id' => 15, 'firstName' => 'Test', 'lastName' => 'Client', 'companyName' => '', 'clientType' => 1,
        'street1' => 'Plot 1 Test Road', 'street2' => 'Kampala', 'gpsLat' => 0.3136, 'gpsLon' => 32.5811,
        'contacts' => [['phone' => '+256 700 000 915', 'email' => 'client@example.test']]]],
    'jobs' => [
        '901' => $job(901, 'Router replacement', 11),
        '902' => $job(902, 'Troubleshooting visit', 12),
        '903' => $job(903, 'Site survey', 13),
        '904' => $job(904, 'Equipment delivery', null),
        '905' => $job(905, 'Follow-up call', 11),
        '906' => $job(906, 'Starlink installation', 11, ['status' => 1]),
        '907' => $job(907, 'Troubleshooting visit', 11, ['status' => 1]),
    ],
]);

// ── Fake Evolution (the repository's own fixture) ────────────────────────────
$evoPort = $start(sprintf('exec php -S 127.0.0.1:{PORT} %s', escapeshellarg($root . '/tests/fixtures/fake_evo_server.php')),
    function (int $p) use ($http): bool { return strpos($http('GET', "http://127.0.0.1:{$p}/__test/state")[1], 'FAKE-EVO-TEST') !== false; },
    11400);
if (!$evoPort) { echo "FAIL fake Evolution did not start\n"; exit(1); }
$evo = "http://127.0.0.1:{$evoPort}";
$http('GET', "$evo/__test/reset");
$texts = function () use ($http, $evo): array { return $http('GET', "$evo/__test/state")[2]['text_calls'] ?? []; };

// ── The plugin's store: configuration and staff ──────────────────────────────
$store = SqliteStore::create($data);
$WHK = 'trace-webhook-secret'; $EVOK = str_repeat('ab', 32);
file_put_contents($data . '/webhook_secret', $EVOK); @chmod($data . '/webhook_secret', 0600);
$cfg = [
    'data_dir' => $data, 'tenant_profile' => 'uganda', 'timezone' => 'Africa/Kampala', 'dry_run_mode' => false,
    'crm_base_url' => $crm, 'crm_auth_token' => 'TRACE-KEY',
    'webhook_secret' => $WHK,
    'evo_api_url' => $evo, 'evo_api_key' => 'trace-evo-key',
    'evo_instance_support' => 'ug-support', 'evo_instance_account' => 'ug-account', 'evo_instance_sales' => 'ug-sales',
    'whatsapp_admin_phone' => '+256 700 000 119',
    'customer_emails_enabled' => '0',
];
$store->save('kyc_config.json', $cfg);
file_put_contents($data . '/kyc_config.json', json_encode($cfg));   // PluginConfig::load and dn_tz() read the file
$tok = [];
$staff = function (string $key, array $row) use ($store, &$tok): int {
    $tok[$key] = 'TRACE-' . strtoupper($key) . '-' . bin2hex(random_bytes(8));
    $rec = $store->appendWithId('retailers.json', $row + ['is_active' => true, 'api_token' => $tok[$key],
        'token_issued_at' => time(), 'password' => password_hash('x', PASSWORD_BCRYPT, ['cost' => 4])]);
    return (int)($rec['id'] ?? 0);
};
// the seed-map e-mail is taken from the source and never printed
$apiSrc = (string)file_get_contents($root . '/includes/api/api_scheduling.php');
preg_match('/\$seedMap\s*=\s*\[(.*?)\];/s', $apiSrc, $sm);
preg_match_all("/'([^']+@[^']+)'\s*=>\s*(\d+)/", $sm[1] ?? '', $pairs, PREG_SET_ORDER);
$seedPair = null; foreach ($pairs as $pp) { if ((int)$pp[2] !== 1) { $seedPair = $pp; break; } }
$ids = [];
$ids['admin']  = $staff('admin',  ['name' => 'Sandbox Admin', 'email' => 'admin@example.test', 'role' => 'admin', 'is_admin' => true, 'phone' => '+256 700 000 110', 'ucrm_user_id' => 10]);
$ids['t1']     = $staff('t1',     ['name' => 'Tech One', 'email' => 'tech1@example.test', 'role' => 'support', 'phone' => '+256 700 000 111', 'ucrm_user_id' => 11]);
$ids['t2']     = $staff('t2',     ['name' => 'Tech Two', 'email' => 'tech2@example.test', 'role' => 'support', 'phone' => '0700 000 112', 'ucrm_user_id' => 12]);
$ids['acct']   = $staff('acct',   ['name' => 'Sandbox Accountant', 'email' => 'acct@example.test', 'role' => 'accountant', 'phone' => '+256 700 000 114']);
$ids['ret']    = $staff('ret',    ['name' => 'Sandbox Retailer', 'email' => 'retailer@example.test', 'role' => 'retailer', 'phone' => '+256 700 000 115']);
$ids['lead']   = $staff('lead',   ['name' => 'Sandbox Leader', 'email' => 'lead@example.test', 'role' => 'support_leader', 'phone' => '+256 700 000 116', 'ucrm_user_id' => 17]);
$ids['seeded'] = $staff('seeded', ['name' => 'Sandbox Seeded', 'email' => (string)($seedPair[1] ?? 'none@example.test'), 'role' => 'support', 'phone' => '+256 700 000 118', 'ucrm_user_id' => 18]);
unset($store);
// Read the table itself: SqliteStore caches retailers.json per process, and the
// writes being observed happen in the server process, not this one.
$row = function (int $id) use ($data): array {
    $pdo = new \PDO('sqlite:' . $data . '/plugin.sqlite3');
    foreach ($pdo->query('SELECT * FROM retailers')->fetchAll(\PDO::FETCH_ASSOC) as $r) {
        $d = isset($r['data']) ? (json_decode((string)$r['data'], true) ?: []) : $r;
        $d['id'] = (int)($r['id'] ?? $d['id'] ?? 0);
        if ($d['id'] === $id) return $d;
    }
    return [];
};
$q = function (string $sql, array $p = []) use ($data): array {
    $st = SqliteStore::create($data)->getPdo()->prepare($sql); $st->execute($p); return $st->fetchAll(\PDO::FETCH_ASSOC);
};

// ── The plugin under php -S (exec disabled: no worker is spawned) ────────────
$nonce = bin2hex(random_bytes(8)); file_put_contents($plug . '/__nonce.txt', $nonce);
$plugPort = $start(sprintf('exec php -d disable_functions=exec -S 127.0.0.1:{PORT} -t %s', escapeshellarg($plug)),
    function (int $p) use ($http, $nonce): bool { return trim($http('GET', "http://127.0.0.1:{$p}/__nonce.txt")[1]) === $nonce; },
    11600, ['DN_VAULT_FILE' => $vault, 'DN_DATA_DIR' => $data]);
@unlink($plug . '/__nonce.txt');
if (!$plugPort) { echo "FAIL the plugin did not start\n"; exit(1); }
$base = "http://127.0.0.1:{$plugPort}/public.php";
$fire = function (string $changeType, string $entity, int $id, string $uuid) use ($http, $base, $WHK): array {
    return $http('POST', "$base?page=crm_webhook", ['changeType' => $changeType, 'entity' => $entity, 'entityId' => $id,
        'uuid' => $uuid, 'extraData' => ['entity' => ['id' => $id]]], ['Content-Type: application/json', 'X-Ucrm-Key: ' . $WHK]);
};
$api = function (string $who, string $method, string $action, ?array $body = null, string $qs = '') use ($http, $base, &$tok): array {
    return $http($method, "$base?page=api&action={$action}{$qs}", $body, ['Content-Type: application/json', 'Authorization: Bearer ' . $tok[$who]]);
};
$whlog = function () use ($data): string { return (string)@file_get_contents($data . '/webhook_log.json'); };
$audit = function (string $event) use ($q): array { return $q("SELECT event, phone, success, http_code FROM notification_audit_log WHERE event = ? ORDER BY id", [$event]); };

echo "\nA. uCRM job.add webhook (path B) — the real public.php?page=crm_webhook route\n";
$n0 = count($texts());
$r = $fire('job.add', 'job', 901, 'trace-901-a');
ok($r[0] === 200, 'job.add 901 accepted', $r[1]);
$t = $texts(); $new = array_slice($t, $n0);
ok(count($new) === 1, 'one WhatsApp for job 901 (uCRM user 11 carries a phone)');
ok(($new[0]['instance'] ?? '') === 'ug-support', 'sent on the SUPPORT instance');
ok(($new[0]['number'] ?? '') === '256700000111', 'to the uCRM user\'s phone, digits only', $new[0]['number'] ?? '');
note('A1 message', str_replace("\n", ' | ', (string)($new[0]['text'] ?? '')));
$ua = $reqs('GET', '#^/users/#'); note('A1 uCRM user endpoint called', implode(',', array_unique(array_map(fn($x) => $x['path'], $ua))));
$aa = $audit('job_assigned'); ok(count($aa) === 1 && (int)$aa[0]['success'] === 1, 'Message Log (notification_audit_log) row job_assigned, success 1');

$n0 = count($texts());
$r = $fire('job.add', 'job', 902, 'trace-902');
ok(count($texts()) === $n0, 'job 902: NOTHING sent — uCRM user 12 has no phone');
ok(strpos($whlog(), 'No phone found') !== false, 'webhook log says "No phone found"');
$fallbackErr = '';
try { SqliteStore::create($data)->getPdo()->prepare('SELECT phone FROM retailers WHERE email = ? LIMIT 1')->execute(['tech2@example.test']); }
catch (\Throwable $e) { $fallbackErr = $e->getMessage(); }
ok($fallbackErr !== '', 'the fallback lookup "SELECT phone FROM retailers WHERE email = ?" fails on this store', $fallbackErr);
note('A2 fallback error', $fallbackErr);
ok(($row($ids['t2'])['phone'] ?? '') !== '', '…although staff row Tech Two, same e-mail, HAS a phone');

$n0 = count($texts());
$fire('job.add', 'job', 903, 'trace-903');
$new = array_slice($texts(), $n0);
ok(count($new) === 1 && $new[0]['number'] === '0700000113', 'job 903: a national-form phone goes out as 0700000113 — no country code', json_encode($new));

$n0 = count($texts());
$fire('job.add', 'job', 904, 'trace-904');
ok(count($texts()) === $n0 && strpos($whlog(), 'No user assigned') !== false, 'job 904 (unassigned): nothing sent, "No user assigned" logged');

$http('GET', "$evo/__test/fail_next?n=1");
$n0 = count($texts());
$fire('job.add', 'job', 905, 'trace-905');
ok(count($texts()) === $n0, 'job 905: Evolution refused the send (test control)');
$a5 = $q("SELECT success, http_code FROM notification_audit_log WHERE event = 'job_assigned' ORDER BY id DESC LIMIT 1");
ok((int)($a5[0]['success'] ?? 1) === 0, 'Message Log records success 0 for 905');
$qrow = $q("SELECT status, event FROM notification_queue WHERE event = 'job_assigned'");
ok(count($qrow) === 1 && $qrow[0]['status'] === 'failed', 'a failed row waits in notification_queue (manual retry only)');
ok(substr_count($whlog(), 'Job #905 notification sent') === 1, 'yet the webhook log says "Job #905 notification sent"');

$n0 = count($texts());
$r1 = $fire('job.add', 'job', 901, 'trace-901-b');
$r2 = $fire('job.add', 'job', 901, 'trace-901-b');
$dups = count($texts()) - $n0;
note('A6 same job 901 delivered twice more (new uuid, then that uuid again)', "{$dups} more WhatsApp(s); answers: " . substr($r1[1], 0, 80) . ' / ' . substr($r2[1], 0, 80));

$http('POST', "$crm/__test/seed", ['jobs' => array_replace($dump()['jobs'], ['901' => $job(901, 'Router replacement', 12)])]);
$n0 = count($texts());
$r = $fire('edit', 'job', 901, 'trace-901-edit');
ok(count($texts()) === $n0, 'a reassignment (uCRM "edit" on job 901, now user 12) sends nothing');
note('A7 answer to the edit event', substr($r[1], 0, 160));
$http('POST', "$crm/__test/seed", ['jobs' => array_replace($dump()['jobs'], ['901' => $job(901, 'Router replacement', 11)])]);

echo "\nB. create_job — the My Jobs \"+ New Job\" API (path C)\n";
$n0 = count($texts()); $p0 = count($reqs('POST', '#^/scheduling/jobs$#'));
$r = $api('acct', 'POST', 'create_job', ['title' => 'Payment collection', 'date' => '2026-10-06', 'time' => '09:00', 'duration' => 60,
    'description' => 'sandbox', 'crm_client_id' => 15, 'engineer_ids' => [11], 'tasks' => ['Collect payment', 'Issue receipt'], 'notify_wa' => 1]);
ok($r[0] === 200 && (int)($r[2]['data']['created'] ?? 0) === 1, 'an ACCOUNTANT (not support, not admin) created a job', $r[1]);
$posted = $reqs('POST', '#^/scheduling/jobs$#'); $pb = end($posted)['body'] ?? [];
ok(count($posted) === $p0 + 1, 'one POST scheduling/jobs to uCRM');
note('B1 body sent to uCRM', json_encode(array_intersect_key($pb, array_flip(['title', 'date', 'status', 'assignedUserId', 'clientId', 'address', 'duration']))));
ok(($pb['date'] ?? '') === '2026-10-06T09:00:00.000Z', 'date sent as 09:00 **UTC** ("Z") — 12:00 in Kampala');
ok((int)($pb['status'] ?? -1) === 1, 'status 1 (Open) — so the Accept button (status 0 only) never shows for it');
ok(count($reqs('POST', '#/job-tasks$#')) === 2, 'two tasks created');
$newJob = (int)($r[2]['data']['jobs'][0]['job_id'] ?? 0);
$new = array_slice($texts(), $n0);
ok(count($new) === 1 && $new[0]['number'] === '256700000111' && $new[0]['instance'] === 'ug-support', 'one WhatsApp to Tech One on the support instance', json_encode($new));
note('B1 message', str_replace("\n", ' | ', (string)($new[0]['text'] ?? '')));
ok(($r[2]['data']['jobs'][0]['notified'] ?? null) === true, 'the answer says notified: true');
ok(count($audit('ops_scheduling_job_assigned')) === 1, 'Message Log row ops_scheduling_job_assigned');

$n0 = count($texts());
$fire('job.add', 'job', $newJob, 'trace-new-' . $newJob);
$again = array_slice($texts(), $n0);
ok(count($again) === 1 && $again[0]['number'] === '256700000111', "uCRM's job.add for that same job #{$newJob} sends a SECOND, differently worded message");

$n0 = count($texts());
$r = $api('ret', 'POST', 'create_job', ['title' => 'Site survey', 'date' => '2026-10-07', 'engineer_ids' => [12], 'notify_wa' => 1]);
$new = array_slice($texts(), $n0);
ok($r[0] === 200, 'a RETAILER account can create and assign a job too');
ok(count($new) === 1 && $new[0]['number'] === '0700000112', 'Tech Two stored as 0700 000 112 is messaged at 0700000112 — no country code', json_encode($new));

$n0 = count($texts());
$r = $api('admin', 'POST', 'create_job', ['title' => 'Follow-up', 'date' => '2026-10-07', 'engineer_ids' => [99], 'notify_wa' => 1]);
ok($r[0] === 200 && ($r[2]['data']['jobs'][0]['notified'] ?? null) === false && count($texts()) === $n0, 'an unmapped uCRM user 99: job created, notified false, nothing sent');
$n0 = count($texts());
$r = $api('admin', 'POST', 'create_job', ['title' => 'Follow-up', 'date' => '2026-10-07', 'engineer_ids' => [11], 'notify_wa' => 0]);
ok($r[0] === 200 && count($texts()) === $n0, 'notify_wa 0 (box unticked): nothing sent');

echo "\nC. What staff do with a job afterwards (API)\n";
$r = $api('ret', 'GET', 'scheduling_job_detail', null, '&job_id=907');
ok($r[0] === 200 && (int)($r[2]['data']['job']['id'] ?? 0) === 907, 'a RETAILER reads job 907 (assigned to Tech One): no ownership check');
note('C1 job detail client block', json_encode($r[2]['data']['client'] ?? null) . ' (the handler reads job.client.id; this fake, like uCRM v1 docs, carries clientId)');
$p0 = count($reqs('PATCH', '#^/scheduling/jobs/907$#'));
$r = $api('ret', 'POST', 'scheduling_job_update', ['job_id' => 907, 'status' => 'closed']);
$pt = $reqs('PATCH', '#^/scheduling/jobs/907$#');
ok($r[0] === 200 && count($pt) === $p0 + 1 && (int)(end($pt)['body']['status'] ?? -1) === 2, 'a RETAILER closes job 907 in uCRM (status 2): no ownership check');

$n0 = count($texts());
$r = $api('t1', 'POST', 'scheduling_job_update', ['job_id' => 901, 'status' => 'open', 'notify_accept' => 1]);
$new = array_slice($texts(), $n0);
ok($r[0] === 200, 'Tech One accepts job 901 (status 0 → 1)');
note('C2 accept messages', implode(', ', array_map(fn($x) => $x['number'] . '@' . $x['instance'], $new)));

$n0 = count($texts());
$photo = 'data:image/jpeg;base64,' . str_repeat('A', 6000);
$r = $api('acct', 'POST', 'scheduling_complete', ['job_id' => 906, 'comment' => 'sandbox completion', 'lat' => 0.3136, 'lon' => 32.5811,
    'photos' => [['type' => 'site', 'data_url' => $photo]]]);
ok($r[0] === 200, 'an ACCOUNTANT completes job 906 "Starlink installation" (assigned to Tech One)', $r[1]);
$comp = SqliteStore::create($data)->load('job_completions.json') ?? [];
$thumb = (string)(end($comp)['photos'][0]['thumb'] ?? '');
ok(strlen($photo) > 6000 && strlen($thumb) === 200, 'the photo is kept as its first 200 characters only (' . strlen($photo) . ' sent) and never reaches uCRM');
ok(count($reqs('POST', '#^/scheduling/jobs/906/job-comments$#')) === 1, 'the comment goes to uCRM as a job comment');
$iq = SqliteStore::create($data)->load('job_invoice_queue.json') ?? [];
ok(count($iq) === 1, 'an install-type title puts the job in the Invoice Queue');
$new = array_slice($texts(), $n0);
note('C3 completion messages', implode(', ', array_map(fn($x) => $x['number'] . '@' . $x['instance'], $new)));
ok(in_array('256700000114@ug-support', array_map(fn($x) => $x['number'] . '@' . $x['instance'], $new), true), 'the "Job Completed" message goes to whoever pressed Complete (here the accountant), not the assignee');

$before = (int)($row($ids['seeded'])['ucrm_user_id'] ?? 0);
$r = $api('ret', 'GET', 'scheduling_clear_cache');
$after = (int)($row($ids['seeded'])['ucrm_user_id'] ?? 0);
ok($r[0] === 200, 'a RETAILER may press "Clear Cache" (the handler says admin only, and checks nothing)');
ok($seedPair !== null && $before === 18 && $after === (int)$seedPair[2], "…which rewrote a staff member's uCRM user id from 18 to {$after} (hard-coded South Sudan map; e-mail withheld)");

echo "\nD. A technician answers on WhatsApp (evo_webhook)\n";
$payload = ['event' => 'messages.upsert', 'instance' => 'ug-support', 'data' => [
    'key' => ['id' => 'TRACE-IN-1', 'fromMe' => false, 'remoteJid' => '256700000111@s.whatsapp.net'],
    'message' => ['conversation' => 'Accepted job 901, on my way'], 'messageTimestamp' => time(), 'pushName' => 'Tech One']];
$r = $http('POST', "$base?page=evo_webhook", $payload, ['Content-Type: application/json', 'X-DishNet-Token: ' . $EVOK]);
ok($r[0] === 200 && (int)($r[2]['queued'] ?? 0) === 1, "the technician's reply is queued for the AI assistant", $r[1]);
$tables = array_column($q("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE '%event%'"), 'name');
$ev = [];
foreach ($tables as $tb) { try { $ev = array_merge($ev, $q("SELECT * FROM {$tb} WHERE payload LIKE '%256700000111%'")); } catch (\Throwable $e) {} }
note('D1 queued event', count($ev) . ' row(s) in ' . implode(',', $tables) . '; type ' . (string)($ev[0]['event_type'] ?? $ev[0]['type'] ?? '?'));
$cv = $q("SELECT id, phone, channel FROM wa_conversations WHERE phone LIKE '%700000111%'");
ok(count($cv) >= 1, 'it is filed as a customer conversation on the support channel', json_encode($cv));
$stafflink = false; foreach ($q("SELECT * FROM wa_conversations WHERE phone LIKE '%700000111%'") as $c) { foreach ($c as $k => $v) if (stripos((string)$k, 'staff') !== false || stripos((string)$k, 'retailer') !== false) $stafflink = true; }
note('D2 staff marker on that conversation', $stafflink ? 'a staff/retailer column exists' : 'none — nothing marks it as a colleague');
ok(count($reqs('PATCH', '#^/scheduling/jobs/901$#')) === 1, 'job 901 in uCRM changed only through the app\'s Accept (1 PATCH), not by the WhatsApp reply');

echo "\nE. The two job crons, run directly in the sandbox\n";
$out = []; $rc = 0;
exec(sprintf('cd %s && DN_DATA_DIR=%s DN_VAULT_FILE=%s php cron/staff_jobs_summary.php 2>&1', escapeshellarg($plug), escapeshellarg($data), escapeshellarg($vault)), $out, $rc);
$o = implode("\n", $out);
ok(strpos($o, 'TypeError') !== false || strpos($o, 'must be of type string') !== false, 'staff_jobs_summary (daily 07:00): stops with a TypeError', substr($o, 0, 300));
note('E1 first error line', substr((string)preg_replace('/\s+/', ' ', (string)(preg_grep('/Error/', $out) ? array_values(preg_grep('/Error/', $out))[0] : $o)), 0, 220));

$n0 = count($texts());
$out = []; exec(sprintf('cd %s && DN_DATA_DIR=%s DN_VAULT_FILE=%s php cron/job_assignment_notify.php 2>&1', escapeshellarg($plug), escapeshellarg($data), escapeshellarg($vault)), $out, $rc);
$new = array_slice($texts(), $n0);
$toClient = array_values(array_filter($new, fn($x) => $x['number'] === '256700000915'));
$toTech   = array_values(array_filter($new, fn($x) => $x['number'] !== '256700000915'));
note('E2 disabled cron, if it ran', count($toTech) . ' staff message(s), ' . count($toClient) . ' to the CUSTOMER (+256 700 000 915, fictitious)');
if ($toClient) note('E2 customer message', str_replace("\n", ' | ', substr((string)$toClient[0]['text'], 0, 220)));
if ($toTech) note('E2 staff message', str_replace("\n", ' | ', substr((string)$toTech[0]['text'], 0, 260)));

echo "\nE3. The technician message's date line under the two zones the plugin can run in\n";
foreach (['Africa/Kampala', 'Africa/Juba'] as $z) {
    $o2 = shell_exec(sprintf('php -r %s', escapeshellarg('date_default_timezone_set(' . var_export($z, true) . '); echo date("D, M j Y", strtotime("2026-10-05T00:00:00+0300")), " ", date("g:i A", strtotime("2026-10-05T09:00:00+0300"));')));
    note("E3 {$z}", trim((string)$o2));
}

echo "\nF. Static facts\n";
$master = (string)file_get_contents($root . '/cron/master.php');
ok((bool)preg_match("#^\s*//\s*'job_assign'\s*=>#m", $master), "master.php: 'job_assign' is commented out");
$rt = (string)file_get_contents($root . '/includes/routes.php');
ok(strpos($rt, "__DIR__.'/retailer/index.html'") !== false || strpos($rt, "__DIR__ . '/retailer/index.html'") !== false, 'routes.php serves includes/retailer/index.html…');
ok(!is_dir($root . '/includes/retailer'), '…and includes/retailer/ does not exist');

printf("\n%d observed as read, %d not.\n", $pass, $fail);
if (getenv('TRACE_NOTES')) file_put_contents((string)getenv('TRACE_NOTES'), json_encode($trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
exit($fail ? 1 : 0);
