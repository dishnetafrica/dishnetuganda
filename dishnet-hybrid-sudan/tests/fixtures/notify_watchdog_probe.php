<?php
/**
 * notify_watchdog_probe.php — TEST ONLY (5.18.54, docs/46 row 32). One step of a watchdog scenario, from the plugin tree
 * named, against the data directory beside it (the job finds it there, as getDataDir does), with the Evolution URL named:
 * a local fake, so the administrator's WhatsApp goes nowhere. Prints one JSON line, the last: what the step did, the
 * watchdog's cooldown marks, and uCRM's log for the plugin (the tree's data/plugin.log).
 *
 *   php notify_watchdog_probe.php <pluginRoot> <uganda|south-sudan> <step> <evolutionUrl> <dataDir> [file-only]
 *
 * The settings go into both copies an install has — the database copy most scheduled jobs read, and the settings file the
 * webhook reads — unless `file-only`: then the database copy has none (docs/45 §2.3).
 *
 *   schedule:<json>  master.php's record, written as master writes it: {"job": {"ago": seconds, "ms": duration_ms}}
 *   seed:<json>      failure-queue rows: [{"status": "failed", "age": seconds since it was queued}, ...]
 *   run              one run of the real cron/notify_watchdog.php (one per process, as master includes a job)
 *   shift:<seconds>  every cooldown mark moved back by that much, as if that time had passed
 *   bare-send        two receipts sent by a notifier built from settings with the country but no WhatsApp connection, as
 *                    a scheduled job builds it from a database copy that lacks one (docs/45 §2.3)
 */
[$_, $root, $tenant, $step, $url, $dd, $copies] = array_pad($argv, 7, '');
putenv('DN_VAULT_FILE=' . $dd . '/no-vault.json');   // no configuration vault may decide the country here
@mkdir($root . '/data', 0700, true);                  // uCRM's log for the plugin lives there

require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
$store = SqliteStore::create($dd);
$cfg = ['evo_api_url' => $url, 'evo_api_key' => 'FAKE-KEY', 'evo_instance_support' => 'fake_inst',
        'evo_instance_account' => 'fake_inst', 'whatsapp_admin_phone' => '256700000777', 'dry_run_mode' => false];
if ($tenant === 'uganda') $cfg['tenant_profile'] = 'uganda';
if (!is_file($dd . '/kyc_config.json')) {
    file_put_contents($dd . '/kyc_config.json', json_encode($cfg));
    if ($copies !== 'file-only') $store->save('kyc_config.json', $cfg);
}
require_once $root . '/lib/timezone.php';
if (function_exists('dn_tz_reset')) dn_tz_reset();
dn_tz_apply();
$pdo = $store->getPdo();
$out = ['step' => $step];

[$verb, $arg] = array_pad(explode(':', $step, 2), 2, '');
try {
    if ($verb === 'schedule') {
        $sch = [];
        foreach (json_decode($arg, true) ?: [] as $job => $r) {
            $t = time() - (int)($r['ago'] ?? 0);
            $sch[$job] = ['last_run' => ($r['ago'] ?? null) === 'never' ? 1 : $t, 'last_run_at' => date('Y-m-d H:i:s', $t),
                          'duration_ms' => (int)($r['ms'] ?? 1200)];
        }
        $store->save('master_schedule.json', $sch);
    } elseif ($verb === 'seed') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS notification_queue (id INTEGER PRIMARY KEY AUTOINCREMENT, sender TEXT NOT NULL DEFAULT 'support',
            phone TEXT NOT NULL, message TEXT NOT NULL, event TEXT DEFAULT NULL, vars TEXT DEFAULT NULL, status TEXT NOT NULL DEFAULT 'failed',
            http_code INTEGER DEFAULT NULL, error TEXT DEFAULT NULL, attempts INTEGER NOT NULL DEFAULT 1, last_attempt_at TEXT NOT NULL,
            retry_at TEXT DEFAULT NULL, retry_by TEXT DEFAULT NULL, created_at TEXT NOT NULL DEFAULT (datetime('now')))");
        foreach (json_decode($arg, true) ?: [] as $i => $r) {
            $pdo->prepare("INSERT INTO notification_queue (phone, message, event, status, last_attempt_at, created_at)
                           VALUES (?, 'FAKE failed message', 'ops_payment_received', ?, ?, ?)")
                ->execute(['2567000001' . str_pad((string)$i, 2, '0', STR_PAD_LEFT), $r['status'] ?? 'failed', date('Y-m-d H:i:s'),
                           gmdate('Y-m-d H:i:s', time() - (int)($r['age'] ?? 0))]);
        }
    } elseif ($verb === 'run') {
        ob_start();
        (function () use ($root) { include $root . '/cron/notify_watchdog.php'; })();
        $out['run'] = trim((string)ob_get_clean());
    } elseif ($verb === 'shift') {
        // The cooldown bucket is the time divided by the cooldown: a mark from an earlier bucket is renamed to it.
        require_once $root . '/lib/NotifyWatchdog.php';
        $n = 0;
        foreach ($pdo->query("SELECT dedup_key FROM notification_dedup WHERE dedup_key LIKE 'WATCHDOG:%'")->fetchAll(PDO::FETCH_COLUMN) as $k) {
            $parts = explode(':', $k);
            $b = (int)array_pop($parts) - intdiv((int)$arg, NotifyWatchdog::COOLDOWN_SEC);
            $n += $pdo->prepare("UPDATE notification_dedup SET dedup_key = ? WHERE dedup_key = ?")->execute([implode(':', $parts) . ':' . $b, $k]) ? 1 : 0;
        }
        $out['shifted'] = $n;
    } elseif ($verb === 'bare-send') {
        require_once $root . '/lib/NotificationService.php';
        $bare = new NotificationService($store, $tenant === 'uganda' ? ['tenant_profile' => 'uganda'] : []);
        $bare->paymentReceived('256700000001', 'Test Customer', 50000, 'PAY-T1');
        $bare->paymentReceived('256700000002', 'Test Customer', 60000, 'PAY-T2');
        $out['result'] = $bare->lastSendResult();
    } else {
        throw new \InvalidArgumentException('unknown step ' . $step);
    }
} catch (\Throwable $e) {
    $out['exception'] = get_class($e) . ': ' . $e->getMessage();
}
try {
    $out['marks'] = $pdo->query("SELECT dedup_key FROM notification_dedup WHERE dedup_key LIKE 'WATCHDOG:%' ORDER BY dedup_key")->fetchAll(PDO::FETCH_COLUMN);
} catch (\Throwable $e) {
    $out['marks'] = [];
}
$log = (string)@file_get_contents($root . '/data/plugin.log');
$out['log'] = array_values(array_filter(explode("\n", $log), fn($l) => strpos($l, '[watchdog]') !== false));
$out['wa_log'] = array_values(array_filter(explode("\n", $log), fn($l) => strpos($l, '[whatsapp]') !== false));
echo "\n" . json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
