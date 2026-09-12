<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * notify_doctor.php — which way does outbound WhatsApp actually leave?
 *
 *   php tools/notify_doctor.php
 *
 * Read-only. It sends nothing.
 *
 * ── WHY THIS EXISTS ──────────────────────────────────────────────────────
 *
 * NotificationService posts to WASender and begins:
 *
 *     if (!$this->enabled || empty($toPhone)) return;
 *
 * `enabled` needs wa_plugin_url AND wa_app_key AND wa_auth_key. If any is
 * missing every send in the plugin returns having done nothing — no message,
 * no exception, no log line saying a send was skipped. 169 call sites across
 * the plugin go through it: OTP logins, invoice notices, lead alerts, cash
 * declarations, technician dispatch. All of them fail the same silent way.
 *
 * A check that cannot be established says UNKNOWN. It never reports a pass it
 * did not prove.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/currency.php';
require_once $root . '/lib/CustomerContact.php';
require_once $root . '/lib/ContactOptOut.php';
require_once $root . '/lib/EvolutionApiService.php';

$dataDir = getDataDir($root);
$store   = SqliteStore::create($dataDir);

// Read BOTH, and say which answered. PluginConfig::saveOverrides() writes the
// JSON file; SqliteStore serves an imported copy. They are normally the same
// thing, but a doctor that silently reads one of two possible sources can
// report "not configured" about a box that is configured — which is exactly
// the wrong answer to give about an outage.
$fromStore = $store->load('kyc_config.json') ?? [];
$fromFile  = [];
$cfgPath   = $dataDir . '/kyc_config.json';
if (is_file($cfgPath)) {
    $raw = @json_decode((string)@file_get_contents($cfgPath), true);
    if (is_array($raw)) $fromFile = $raw;
}
$config = $fromStore + $fromFile;          // store wins, file fills gaps
$cfgSrc = sprintf('store %d key(s), file %d key(s)', count($fromStore), count($fromFile));

$line = str_repeat('─', 72);
echo "\n  OUTBOUND WHATSAPP (read-only)\n  {$line}\n";
printf("  %-22s %s\n", 'data directory', $dataDir);
printf("  %-22s %s\n", 'config source', $cfgSrc);
printf("  %-22s %s\n\n", 'config', $config === [] ? '⚠ EMPTY — nothing below is meaningful' : count($config) . ' keys');
if ($fromStore !== [] && $fromFile !== []) {
    $diff = [];
    foreach (['wa_plugin_url','wa_app_key','wa_auth_key','evo_api_url','evo_instance_support'] as $k) {
        $a = trim((string)($fromStore[$k] ?? '')); $b = trim((string)($fromFile[$k] ?? ''));
        if ($a !== $b) $diff[] = $k;
    }
    if ($diff) printf("  ⚠ store and file DISAGREE on: %s\n\n", implode(', ', $diff));
}

// ── WASender ────────────────────────────────────────────────────────────
$waUrl = trim((string)($config['wa_plugin_url'] ?? ''));
$waApp = trim((string)($config['wa_app_key']   ?? ''));
$waAut = trim((string)($config['wa_auth_key']  ?? ''));
$waOn  = $waUrl !== '' && $waApp !== '' && $waAut !== '';

echo "  WASENDER (NotificationService — 169 call sites)\n  {$line}\n";
printf("    %-20s %s\n", 'wa_plugin_url', $waUrl !== '' ? $waUrl : '— empty');
printf("    %-20s %s\n", 'wa_app_key',    $waApp !== '' ? 'set' : '— empty');
printf("    %-20s %s\n", 'wa_auth_key',   $waAut !== '' ? 'set' : '— empty');
printf("    %-20s %s\n\n", 'enabled', $waOn ? 'YES' : 'NO — every send returns silently');

// ── Evolution ───────────────────────────────────────────────────────────
$evo = new EvolutionApiService($config);
echo "  EVOLUTION (AI replies, follow-ups, technician dispatch)\n  {$line}\n";
printf("    %-20s %s\n", 'evo_api_url', trim((string)($config['evo_api_url'] ?? '')) ?: '— empty');
printf("    %-20s %s\n", 'evo_api_key', trim((string)($config['evo_api_key'] ?? '')) !== '' ? 'set' : '— empty');
printf("    %-20s %s\n", 'isConfigured', $evo->isConfigured() ? 'YES' : 'NO');
foreach (['sales', 'support', 'account'] as $ch) {
    $inst = trim((string)($config['evo_instance_' . $ch] ?? ''));
    $state = '';
    if ($inst !== '' && $evo->isConfigured()) {
        try { $state = (string)($evo->connectionState($inst) ?? ''); }
        catch (\Throwable $e) { $state = 'UNKNOWN (' . $e->getMessage() . ')'; }
    }
    printf("    %-20s %-24s %s\n", 'instance ' . $ch,
        $inst !== '' ? $inst : '— empty',
        $inst === '' ? '' : ($state !== '' ? $state : 'UNKNOWN — could not reach the API'));
}
echo "\n";

// ── What each kind of message actually does today ───────────────────────
$supportInst = trim((string)($config['evo_instance_support'] ?? ''));
$jobPath = ($evo->isConfigured() && $supportInst !== '')
         ? 'Evolution / ' . $supportInst
         : ($waOn ? 'WASender fallback' : '✗ NOTHING IS SENT');

echo "  WHAT HAPPENS NOW\n  {$line}\n";
printf("    %-34s %s\n", 'technician job dispatch', $jobPath);
printf("    %-34s %s\n", 'AI replies / follow-ups',
    $evo->isConfigured() ? 'Evolution' : '✗ Evolution not configured');
printf("    %-34s %s\n", 'everything else (OTP, invoices, alerts)',
    $waOn ? 'WASender' : '✗ NOTHING IS SENT — silently');
printf("    %-34s %s\n\n", 'support number printed in messages', CustomerContact::support($config));

// ── Evidence, not just configuration ────────────────────────────────────
echo "  THE QUEUE SAYS\n  {$line}\n";
try {
    $pdo = $store->getPdo();
    $rows = $pdo->query("SELECT status, COUNT(*) c FROM notification_queue GROUP BY status")
                ->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    if ($rows === []) {
        echo "    notification_queue is empty — no evidence either way.\n";
    } else {
        foreach ($rows as $r) printf("    %-12s %d\n", (string)$r['status'], (int)$r['c']);
    }
    $last = $pdo->query("SELECT created_at, status FROM notification_queue
                          ORDER BY id DESC LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
    if ($last) printf("    %-12s %s (%s)\n", 'most recent', (string)$last['created_at'], (string)$last['status']);
} catch (\Throwable $e) {
    echo "    UNKNOWN — could not read notification_queue: " . $e->getMessage() . "\n";
}

// ── The verdict, stated plainly ─────────────────────────────────────────
echo "\n  {$line}\n";
if (!$waOn && !$evo->isConfigured()) {
    echo "  ✗ NEITHER SENDER IS CONFIGURED. Nothing outbound leaves this box.\n\n";
} elseif (!$waOn) {
    echo "  ⚠ WASender is OFF, so every send outside Evolution is silently dropped:\n";
    echo "    OTP logins, invoice notices, lead alerts, cash declarations.\n";
    echo "    Evolution carries only AI replies, follow-ups and job dispatch.\n\n";
} elseif (!$evo->isConfigured()) {
    echo "  ⚠ Evolution is OFF. AI replies and follow-ups cannot send.\n\n";
} else {
    echo "  ✓ Both senders are configured.\n\n";
}
