<?php
/**
 * notify_retry_probe.php — TEST ONLY (5.18.54, docs/46 row 30). One step of a failed-WhatsApp scenario, from the plugin
 * tree named, against the data directory beside it (the cron finds it there, as getDataDir does), with the Evolution URL
 * named: a local fake, so nothing leaves the machine. Prints one JSON line, the last: what the step did, and the
 * failure queue after it.
 *
 *   php notify_retry_probe.php <pluginRoot> <uganda|south-sudan> <step> <evolutionUrl> <dataDir> [timezone]
 *
 *   send:<kind>     one message, through the real notifier, with its real event name: receipt (paymentReceived),
 *                   reminder (ops_pre_due_d1), welcome (event_client_add), quote (ops_quote_created), staff
 *                   (ops_scheduling_job_assigned)
 *   job             one run of the real cron/notify_retry.php; first, every pending automatic try is made due. One run
 *                   per process: master.php includes a job once per process, and the script declares a function
 *   retry:<id>      a person's retry of one row (NotificationService::retryOne), as the Failed Queue calls it
 *   bulk            Retry All (NotificationService::retryBulk)
 *   seed:<json>     queue rows inserted as given, for states the fake cannot produce ('ago_min': the last attempt, that
 *                   many minutes ago, written in this install's zone)
 *   stuck-mode      a person's retry whose send throws, then an ordinary send that fails, in one process
 *   stale-success   a send that goes, then a person's retry of a document row for a number that has opted out of
 *                   everything, in one process
 */
[$_, $root, $tenant, $step, $url, $dd, $tz] = array_pad($argv, 7, '');
putenv('DN_VAULT_FILE=' . $dd . '/no-vault.json');   // no configuration vault may decide the country here

require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
$store = SqliteStore::create($dd);
$cfg = ['evo_api_url' => $url, 'evo_api_key' => 'FAKE-KEY', 'evo_instance_support' => 'fake_inst',
        'evo_instance_account' => 'fake_inst', 'dry_run_mode' => false];
if ($tenant === 'uganda') $cfg['tenant_profile'] = 'uganda';
if ($tz !== '') $cfg['timezone'] = $tz;
// After the store's first boot, which moves every JSON file in the directory into SQLite: what PluginConfig reads.
if (!is_file($dd . '/kyc_config.json')) file_put_contents($dd . '/kyc_config.json', json_encode($cfg));
require_once $root . '/lib/timezone.php';
if (function_exists('dn_tz_reset')) dn_tz_reset();
dn_tz_apply();
require_once $root . '/lib/NotificationService.php';
require_once $root . '/lib/ContactOptOut.php';
$pdo = $store->getPdo();
$ns  = new NotificationService($store, $cfg);
$out = ['step' => $step];
$phone = '256700000001';

[$verb, $arg] = array_pad(explode(':', $step, 2), 2, '');
try {
    if ($verb === 'send') {
        switch ($arg) {
            case 'receipt':  $ns->paymentReceived($phone, 'Test Customer', 50000, 'PAY-T1'); break;
            case 'reminder': $ns->sendVia(NotificationService::ACCOUNTS, $phone, 'FAKE reminder: your invoice is due tomorrow', 'ops_pre_due_d1'); break;
            case 'welcome':  $ns->sendVia(NotificationService::SUPPORT, $phone, 'FAKE welcome to DishNet', 'event_client_add'); break;
            case 'quote':    $ns->sendVia(NotificationService::SUPPORT, $phone, 'FAKE quotation #Q-1', 'ops_quote_created'); break;
            case 'staff':    $ns->sendVia(NotificationService::SUPPORT, '256700000002', 'FAKE job assigned', 'ops_scheduling_job_assigned', [], ContactOptOut::CLASS_STAFF); break;
            default: throw new \InvalidArgumentException('unknown kind ' . $arg);
        }
        $out['result'] = $ns->lastSendResult();
    } elseif ($verb === 'job') {
        require_once $root . '/lib/NotificationRetry.php';
        // Whatever automatic try is pending is made due now: its previous attempt is moved back by its gap.
        $hasQ = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'notification_queue'")->fetchColumn();
        foreach ($hasQ ? $pdo->query("SELECT id, attempts FROM notification_queue WHERE status = 'failed'")->fetchAll(PDO::FETCH_ASSOC) : [] as $r) {
            $gap = NotificationRetry::GAP_MIN[max(1, (int)$r['attempts'])] ?? 0;
            $pdo->prepare("UPDATE notification_queue SET last_attempt_at = ? WHERE id = ?")
                ->execute([date('Y-m-d H:i:s', time() - $gap * 60 - 5), (int)$r['id']]);
        }
        ob_start();
        (function () use ($root) { include $root . '/cron/notify_retry.php'; })();
        $out['run'] = trim((string)ob_get_clean());
    } elseif ($verb === 'retry') {
        $out['result'] = $ns->retryOne((int)$arg, 'Test Admin');
    } elseif ($verb === 'bulk') {
        $out['result'] = $ns->retryBulk('Test Admin', 50);
    } elseif ($verb === 'seed') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS notification_queue (id INTEGER PRIMARY KEY AUTOINCREMENT, sender TEXT NOT NULL DEFAULT 'support',
            phone TEXT NOT NULL, message TEXT NOT NULL, event TEXT DEFAULT NULL, vars TEXT DEFAULT NULL, status TEXT NOT NULL DEFAULT 'failed',
            http_code INTEGER DEFAULT NULL, error TEXT DEFAULT NULL, attempts INTEGER NOT NULL DEFAULT 1, last_attempt_at TEXT NOT NULL,
            retry_at TEXT DEFAULT NULL, retry_by TEXT DEFAULT NULL, created_at TEXT NOT NULL DEFAULT (datetime('now')))");
        foreach (json_decode($arg, true) ?: [] as $r) {
            // last_attempt_at is written with date(), in this install's zone, as the plugin writes it: 'ago_min' says how
            // long ago, and this process, which applied the zone, writes it.
            $ago = (int)($r['ago_min'] ?? 0);
            unset($r['ago_min']);
            $r += ['sender' => 'accounts', 'phone' => $phone, 'message' => 'FAKE seeded message', 'status' => 'failed',
                   'attempts' => 1, 'last_attempt_at' => date('Y-m-d H:i:s', time() - $ago * 60)];
            $cols = array_keys($r);
            $pdo->prepare('INSERT INTO notification_queue (' . implode(', ', $cols) . ') VALUES ('
                          . implode(', ', array_fill(0, count($cols), '?')) . ')')->execute(array_values($r));
        }
    } elseif ($verb === 'stuck-mode') {
        // A notifier whose first send throws: the retry's. Then one ordinary send that fails at the fake (it refuses).
        $boom = new class($store, $cfg) extends NotificationService {
            public int $calls = 0;
            public function sendVia(string $sender, string $toPhone, string $message, string $event = '', array $vars = [], string $class = ContactOptOut::CLASS_TRANSACTIONAL): void
            {
                if (++$this->calls === 1) throw new \RuntimeException('FAKE failure inside the send');
                parent::sendVia($sender, $toPhone, $message, $event, $vars, $class);
            }
        };
        $out['retry'] = $boom->retryOne((int)$arg, 'Test Admin');
        $boom->sendVia(NotificationService::ACCOUNTS, '256700000003', 'FAKE later receipt', 'ops_payment_received');
        $out['later'] = $boom->lastSendResult();
    } elseif ($verb === 'stale-success') {
        // A send that goes (the fake accepts it), then a person's retry of a document row whose recipient opted out.
        ContactOptOut::fromStore($store)->add('256700000009', ['scope' => ContactOptOut::SCOPE_ALL, 'reason' => 'test', 'source' => 'test', 'created_by' => 'test']);
        $ns->sendVia(NotificationService::ACCOUNTS, '256700000004', 'FAKE receipt that goes', 'ops_payment_received');
        $out['first'] = $ns->lastSendResult();
        $out['retry'] = $ns->retryOne((int)$arg, 'Test Admin');
    } else {
        throw new \InvalidArgumentException('unknown step ' . $step);
    }
} catch (\Throwable $e) {
    $out['exception'] = get_class($e) . ': ' . $e->getMessage();
}
try {
    $out['queue'] = $pdo->query("SELECT id, event, status, attempts, http_code, error, retry_by FROM notification_queue ORDER BY id")
                        ->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $out['queue'] = [];
}
echo "\n" . json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
