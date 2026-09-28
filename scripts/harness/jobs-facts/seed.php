<?php
/**
 * seed.php — builds the fake install the jobs-facts rehearsal reads.
 *
 *   php seed.php <plugin dir> <data dir> <fake uCRM url> <fake Evolution url> <uCRM seed out> <scenario>
 *
 * Every personal value is a CANARY: the rehearsal asserts that none of them reaches the
 * report's output. Two staff e-mails are taken from the installed plugin's own hard-coded
 * map, so the "maps force" line is exercised against the real maps; they are canaries too.
 */
[$_, $plugin, $pdd, $ucrm, $evo, $seedOut, $scenario] = $argv + [6 => 'normal'];
require_once $plugin . '/lib/StoreInterface.php';
require_once $plugin . '/lib/SqliteStore.php';
require_once $plugin . '/lib/ConversationService.php';
require_once $plugin . '/lib/EventBus.php';

// the two map e-mails (never printed by anything)
$src = (string)file_get_contents($plugin . '/public.php');
$p = strpos($src, '$_seedMapUpgrade'); $o = strpos($src, '[', $p); $c = strpos($src, '];', $o);
preg_match_all("/'([^'\\s]+@[^'\\s]+)'\\s*=>\\s*(\\d+)/", substr($src, $o, $c - $o), $m, PREG_SET_ORDER);
$e1 = null; $e5 = null;
foreach ($m as $x) { if ((int)$x[2] === 1 && !$e1) $e1 = strtolower($x[1]); if ((int)$x[2] === 1581 && !$e5) $e5 = strtolower($x[1]); }
if (!$e1 || !$e5) { fwrite(STDERR, "seed: the installed map no longer holds ids 1 and 1581\n"); exit(2); }
file_put_contents($seedOut . '.canaries', implode("\n", [$e1, $e5]) . "\n");

@mkdir($pdd, 0700, true); @mkdir($plugin . '/data', 0700, true);
file_put_contents($plugin . '/ucrm.json', json_encode([
    'pluginDataDir' => '/data/ucrm/data/plugins/.dishnet-hybrid-sudan-data',
    'ucrmLocalUrl'  => rtrim($ucrm, '/') . '/',
    'ucrmPublicUrl' => 'https://canary-host.example/crm/',
    'pluginAppKey'  => 'CANARY-APPKEY-abcdefghijklmnopqrstuvwxyz0123456789',
]));
file_put_contents($plugin . '/.deployed-commit', "e076632\n");

$cfg = [
    'tenant_profile' => 'uganda', 'dry_run_mode' => false,
    'evo_api_url' => $evo, 'evo_api_key' => 'CANARY-EVO-KEY-0123456789abcdef0123456789',
    'evo_instance_support' => 'canary-support-inst', 'evo_instance_account' => 'canary-account-inst',
    'whatsapp_admin_phone' => '+256 774 567 890',
];
// 'juba': neither a timezone nor a tenant profile — the Uganda profile alone would already give Kampala
// 'ugx': production's shape on 27 Sep — no tenant_profile and no timezone setting; the currency UGX selects uganda
if ($scenario === 'juba') unset($cfg['tenant_profile']);
elseif ($scenario === 'ugx') { unset($cfg['tenant_profile']); $cfg['currency_code'] = 'UGX'; }
else $cfg['timezone'] = 'Africa/Kampala';
$store = SqliteStore::create($pdd);          // first: a first boot renames any *.json it finds to *.json.migrated
$store->save('kyc_config.json', $cfg);
file_put_contents($pdd . '/kyc_config.json', json_encode($cfg));   // what PluginConfig and dn_tz() read
$pdo = $store->getPdo();

$tok = function (string $who): string { return 'CANARYTOK' . $who . str_repeat('9f', 20); };
$staff = [
    ['name' => 'Canary Adminname',   'email' => $e1, 'role' => 'admin', 'is_admin' => true, 'phone' => '+256 771 000 001', 'ucrm_user_id' => 1, 'api_token' => $tok('A'), 'token_issued_at' => time() - 10 * 86400],
    ['name' => 'Canary Accountname', 'email' => 'canary.accounts@canary-mail.test', 'role' => 'accountant', 'phone' => '+256 771 234 567', 'api_token' => $tok('B'), 'token_issued_at' => time() - 3 * 86400],
    ['name' => 'Canary Agentname',   'email' => 'canary.agent@canary-mail.test', 'role' => 'support', 'is_employee' => false, 'phone' => '0772 345 678'],
    ['name' => 'Canary Supportname', 'email' => 'canary.support@canary-mail.test', 'role' => 'support', 'phone' => '+211 912 345 678', 'ucrm_user_id' => 81],
    ['name' => 'Canary Ownername',   'email' => $e5, 'role' => 'support', 'phone' => '+256 773 456 789', 'ucrm_user_id' => 1581, 'must_change_pwd' => true, 'api_token' => $tok('E'), 'token_issued_at' => time() - 100 * 86400],
];
foreach ($staff as $s) $store->appendWithId('retailers.json', $s + ['is_active' => true, 'password' => '$2y$04$CANARYHASHcanaryhashcanaryhashcanaryhashcanaryhash12']);

$pdo->exec("CREATE TABLE IF NOT EXISTS notification_audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, sender TEXT, event TEXT, phone TEXT, preview TEXT,
            success INTEGER NOT NULL DEFAULT 0, http_code INTEGER, error TEXT, sent_at TEXT NOT NULL DEFAULT (datetime('now')))");
$ins = $pdo->prepare('INSERT INTO notification_audit_log (sender, event, phone, preview, success, http_code, error, sent_at) VALUES (?,?,?,?,?,?,?,?)');
foreach ([
    ['support', 'job_assigned', '256771000001', 'Canary preview one', 1, 200, null, '2026-09-20 10:00:00'],
    ['support', 'job_assigned', '256771000001', 'Canary preview two', 1, 200, null, '2026-09-21 10:00:00'],
    ['support', 'job_assigned', '256771000001', 'Canary preview three', 0, 500, 'Canary error text', '2026-09-22 10:00:00'],
    ['support', 'ops_scheduling_job_assigned', '256773456789', 'Canary preview four', 1, 200, null, '2026-09-23 10:00:00'],
    ['accounts', 'invoice_created', '256779999999', 'Canary preview five', 1, 200, null, '2026-09-01 10:00:00'],
] as $r) $ins->execute($r);
$pdo->exec("CREATE TABLE IF NOT EXISTS notification_queue (id INTEGER PRIMARY KEY AUTOINCREMENT, sender TEXT NOT NULL DEFAULT 'support', phone TEXT NOT NULL,
            message TEXT NOT NULL, event TEXT DEFAULT NULL, vars TEXT DEFAULT NULL, status TEXT NOT NULL DEFAULT 'failed', http_code INTEGER DEFAULT NULL,
            error TEXT DEFAULT NULL, attempts INTEGER NOT NULL DEFAULT 1, last_attempt_at TEXT NOT NULL, retry_at TEXT DEFAULT NULL, retry_by TEXT DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now')))");
$pdo->prepare("INSERT INTO notification_queue (sender, phone, message, event, status, http_code, error, last_attempt_at) VALUES (?,?,?,?,?,?,?,?)")
    ->execute(['support', '256771000001', 'Canary queued message for Canary Techname', 'job_assigned', 'failed', 500, 'Canary err', '2026-09-22 10:00:00']);

$conv = new ConversationService($pdd, $pdo);
$bus  = new EventBus($pdo);
foreach (['256771234567', '256770000042'] as $ph) {
    $cv = $conv->ensureConversation($ph, 'support');
    $bus->emit('ai.reply', 'conversation', (int)$cv['id'], ['channel' => 'support', 'customer_phone' => $ph, 'message' => 'Canary inbound text'], 3, 'evo_webhook');
}

$store->save('master_schedule.json', ['staff_jobs' => ['last_run' => time(), 'last_run_at' => '2026-09-27 07:00:04', 'duration_ms' => 12],
                                      'jobs_cache' => ['last_run' => time(), 'last_run_at' => '2026-09-27 10:50:00', 'duration_ms' => 800]]);
$store->save('scheduling_cache_meta.json', ['last_sync' => time(), 'last_sync_ts' => '2026-09-27 10:50:00', 'plugin_version' => '5.18.49']);
$store->save('scheduling_jobs_cache.json', [['id' => 501, 'title' => 'Canary Install for Zebra Client'], ['id' => 502, 'title' => 'Canary repair visit Zebra']]);
$store->save('job_completions.json', [['job_id' => 501, 'technician' => 'Canary Techname', 'comment' => 'Canary completion note']]);
$store->save('job_invoice_queue.json', [['id' => 1, 'client_name' => 'Canary Zebra Client', 'status' => 'pending']]);

// The lines webhook.php really writes (whLog, newest first). One uCRM delivery is several lines: "normalize" turns
// uCRM's changeType/entity into job.edit, "_debug", then "Received UCRM webhook: job.edit", then the handler's own. On
// 5.18.51 a job.edit or job.delete has no handler: its line is "Unhandled event type — logged only". The newest three
// deliveries are M4 as 5.18.51 logs it (docs/44 §16.17); the older ones are 5.18.49's job.add lines.
$wlRows = [
    ['job.delete', 'Unhandled event type — logged only', ['entity_id' => 601], '2026-09-28 10:05:02'],
    ['job.delete', 'Received UCRM webhook: job.delete', ['entity_id' => 601, 'uuid' => 'canary-uuid-3'], '2026-09-28 10:05:02'],
    ['_debug', 'Services initialized', ['crm_configured' => 'YES'], '2026-09-28 10:05:02'],
    ['normalize', 'Normalized changeType: delete/job → job.delete', ['original_changeType' => 'delete', 'entity_type' => 'job', 'normalized' => 'job.delete'], '2026-09-28 10:05:02'],
    ['job.edit', 'Unhandled event type — logged only', ['entity_id' => 601], '2026-09-28 10:03:00'],
    ['job.edit', 'Received UCRM webhook: job.edit', ['entity_id' => 601, 'uuid' => 'canary-uuid-2'], '2026-09-28 10:03:00'],
    ['_debug', 'Services initialized', ['crm_configured' => 'YES'], '2026-09-28 10:03:00'],
    ['normalize', 'Normalized changeType: edit/job → job.edit', ['original_changeType' => 'edit', 'entity_type' => 'job', 'normalized' => 'job.edit'], '2026-09-28 10:03:00'],
    ['job.add', 'Job #601 — WhatsApp skipped: job notifications are not switched on yet', ['job_title' => 'Canary test job Zebra'], '2026-09-28 10:00:01'],
    ['job.add', 'Received UCRM webhook: job.add', ['entity_id' => 601, 'uuid' => 'canary-uuid-1'], '2026-09-28 10:00:01'],
    ['normalize', 'Normalized changeType: insert/job → job.add', ['original_changeType' => 'insert', 'entity_type' => 'job', 'normalized' => 'job.add'], '2026-09-28 10:00:01'],
    ['job.add', 'Job #502 — No phone found for Canary Supportname', ['tech_email' => 'canary.support@canary-mail.test'], '2026-09-25 11:00:00'],
    ['job.add', 'Received UCRM webhook: job.add', ['entity_id' => 502], '2026-09-25 11:00:00'],
    ['job.add', 'Job #501 notification sent to Canary Adminname', ['tech_phone' => '+256 771 000 001', 'client' => 'Canary Zebra Client'], '2026-09-24 11:00:00'],
    ['job.add', 'Received UCRM webhook: job.add', ['entity_id' => 501], '2026-09-24 11:00:00'],
    ['quote.add', 'Quote for Canary Zebra Client', [], '2026-09-23 11:00:00'],
    ['job.add', 'Job #503 — No user assigned', ['job_title' => 'Canary payment collection Zebra'], '2026-09-22 11:00:00'],
    ['job.add', 'Received UCRM webhook: job.add', ['entity_id' => 503], '2026-09-22 11:00:00'],
];
$wl = []; $id = count($wlRows);
foreach ($wlRows as [$ev, $msg, $data, $at]) $wl[] = ['id' => $id--, 'event' => $ev, 'message' => $msg, 'data' => $data, 'received_at' => $at];
file_put_contents($pdd . '/webhook_log.json', json_encode($wl));
file_put_contents($plugin . '/data/plugin.log', implode("\n", [
    '[2026-09-26 07:00:03] [master] RUN staff_jobs',
    '[2026-09-26 07:00:03] [master] ERROR staff_jobs: CrmApiClient::__construct(): Argument #1 ($baseUrl) must be of type string, array given, called in /data/ucrm/data/plugins/dishnet-hybrid-sudan/cron/staff_jobs_summary.php on line 47',
    '[2026-09-26 07:00:03] [master] DONE staff_jobs in 12ms',
    '[2026-09-27 07:00:04] [master] RUN staff_jobs',
    '[2026-09-27 07:00:04] [master] ERROR staff_jobs: CrmApiClient::__construct(): Argument #1 ($baseUrl) must be of type string, array given, called in /data/ucrm/data/plugins/dishnet-hybrid-sudan/cron/staff_jobs_summary.php on line 47',
    '[2026-09-27 07:00:04] [master] DONE staff_jobs in 12ms',
]) . "\n");

$job = function (int $id, string $title, ?int $user, int $status, ?int $client, string $date): array {
    return ['id' => $id, 'title' => $title, 'description' => 'Canary description', 'clientId' => $client, 'assignedUserId' => $user,
            'date' => $date, 'duration' => 60, 'status' => $status, 'address' => 'Canary Plot 99 Zebra Road', 'gpsLat' => 0.31, 'gpsLon' => 32.58];
};
file_put_contents($seedOut, json_encode([
    'admins' => [
        ['id' => 1,  'username' => 'canaryuadmin', 'firstName' => 'Canary', 'lastName' => 'Uadminname', 'email' => $e1],
        ['id' => 81, 'username' => 'canaryusupp', 'firstName' => 'Canary', 'lastName' => 'Usupportname', 'email' => 'canary.support@canary-mail.test', 'phone' => ''],
        ['id' => 7,  'username' => 'canaryuother', 'firstName' => 'Canary', 'lastName' => 'Uothername', 'email' => 'canary.other@canary-mail.test', 'phone' => '+256 775 000 007'],
    ],
    'jobs' => [
        $job(501, 'Canary Install for Zebra Client', 1, 1, 42, '2026-09-20T00:00:00+0300'),
        $job(502, 'Canary repair visit Zebra', 81, 0, 42, '2026-09-28T00:00:00+0300'),
        $job(503, 'Canary payment collection Zebra', null, 2, null, '2026-09-10T00:00:00+0300'),
        $job(504, 'Canary site survey Zebra', 99, 1, 43, '2026-09-29T00:00:00+0300'),
    ],
    'endpoints' => [
        ['id' => 1, 'url' => 'https://canary-host.example/crm/_plugins/dishnet-hybrid-sudan/public.php?page=crm_webhook', 'isActive' => true, 'anyEvent' => true, 'eventTypes' => []],
        ['id' => 2, 'url' => 'https://other-canary.example/hook', 'isActive' => true, 'anyEvent' => false, 'eventTypes' => ['client.add']],
    ],
]));
echo "seeded\n";
