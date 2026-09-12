<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * notify_doctor.php — which way does outbound WhatsApp actually leave?
 *
 *   php tools/notify_doctor.php           what happens to outbound messages now
 *   php tools/notify_doctor.php --fix     repair a channel pointing at an instance
 *                                         the server does not have
 *
 * Read-only WITHOUT --fix. It never sends a message either way.
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
$FIX = in_array('--fix', array_slice($argv, 1), true);

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
// PluginConfig::load() merges config.json then kyc_config.json, FILE LAST, so
// the file wins — and that is what webhook.php and followup_send.php read.
// But 36 crons, job_assignment_notify among them, read $store->load() and see
// the store ALONE. Two readers with opposite precedence, so a single merged
// view is a fiction that flatters whichever one happens to be right.
$config     = $fromFile + $fromStore;      // canonical: PluginConfig::load order
$storeOnly  = $fromStore;                  // what the 36 store-only crons see
$cfgSrc = sprintf('file %d key(s) [canonical], store %d key(s) [36 crons read this alone]',
                  count($fromFile), count($fromStore));

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
    if ($diff) {
        echo "  ⚠ store and file DISAGREE. The file is canonical for PluginConfig::load\n";
        echo "    readers (webhook, follow-ups); the store is ALL that 36 crons see:\n";
        printf("      %-24s %-28s %s\n", '', 'file (canonical)', 'store (36 crons)');
        foreach ($diff as $k) {
            printf("      %-24s %-28s %s\n", $k,
                trim((string)($fromFile[$k] ?? '')) ?: '—',
                trim((string)($fromStore[$k] ?? '')) ?: '—');
        }
        echo "\n";
    }
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

// Ask the server what it actually has. A config key naming an instance is not
// evidence the instance exists — evo_instance_support can name anything, and
// a name that resolves to nothing fails at send time, not at configuration.
if ($evo->isConfigured()) {
    echo "  INSTANCES THE SERVER ACTUALLY HAS\n  {$line}\n";
    try {
        $list = $evo->listInstances();
        $GLOBALS['__dn_list'] = $list;
        $notOpen = [];
        if ($list === []) {
            echo "    UNKNOWN — the API returned no list (unreachable, or no instances).\n";
        } else {
            printf("    %-24s %-12s %-18s %s\n", 'NAME', 'STATE', 'PHONE', 'USED AS');
            $usedBy = [];
            foreach (['sales', 'support', 'account'] as $ch) {
                $n = trim((string)($config['evo_instance_' . $ch] ?? ''));
                if ($n !== '') $usedBy[$n][] = $ch;
            }
            foreach ($list as $row) {
                $n = (string)($row['name'] ?? '');
                $st = strtolower((string)($row['state'] ?? ''));
                if (isset($usedBy[$n]) && $st !== 'open') {
                    $notOpen[] = $n . ' (' . $st . ') serving ' . implode(', ', $usedBy[$n]);
                }
                printf("    %-24s %-12s %-18s %s\n", $n,
                    (string)($row['state'] ?? '?'),
                    (string)($row['phone'] ?? ($row['number'] ?? '—')),
                    isset($usedBy[$n]) ? implode(', ', $usedBy[$n]) : '— not wired to a channel');
            }
            foreach ($notOpen as $w) {
                printf("    ⚠ %s — not 'open', so sends on that channel will fail\n", $w);
            }
            // Named in config but absent from the server: the silent failure.
            foreach ($usedBy as $n => $chs) {
                $found = false;
                foreach ($list as $row) if ((string)($row['name'] ?? '') === $n) { $found = true; break; }
                if (!$found) printf("    ✗ %-22s %s\n", $n,
                    'NAMED for ' . implode(', ', $chs) . ' BUT DOES NOT EXIST HERE');
            }
        }
    } catch (\Throwable $e) {
        echo "    UNKNOWN — " . $e->getMessage() . "\n";
    }
    echo "\n";
}

// ── Repair, only when asked, and only on evidence ───────────────────────
//
// The rule is narrow on purpose: a channel is repaired ONLY when the store's
// value names an instance the server does not have AND the file's value names
// one it does. That is not a preference between two config sources — it is one
// value that cannot possibly work and one that demonstrably can. Anything less
// clear-cut is reported and left alone.
if ($FIX && $evo->isConfigured()) {
    $have = [];
    foreach (($GLOBALS['__dn_list'] ?? []) as $row) {
        $n = trim((string)($row['name'] ?? ''));
        if ($n !== '') $have[$n] = (string)($row['state'] ?? '?');
    }
    echo "  REPAIR\n  {$line}\n";
    if ($have === []) {
        echo "    Refusing: the server returned no instance list, so there is no\n";
        echo "    evidence to repair against.\n\n";
    } else {
        $fixed = 0;
        foreach (['sales', 'support', 'account'] as $ch) {
            $k     = 'evo_instance_' . $ch;
            $live  = trim((string)($fromStore[$k] ?? ''));
            $onDisk= trim((string)($fromFile[$k]  ?? ''));
            if ($onDisk === '' || $live === $onDisk) continue;
            // A store that has NOTHING for this channel is the case that let
            // set_evolution.php --account look like it worked: it writes the
            // file, the file is canonical for PluginConfig::load, and the 36
            // store-only crons — cron_invoice_notify and cron_quote_wa among
            // them — never see it. Copying is safe on the same evidence as a
            // repair: the file names an instance this server actually has.
            if ($live === '') {
                if (!isset($have[$onDisk])) {
                    printf("    %-22s file '%s' is not on this server — not copied\n", $ch, $onDisk);
                    continue;
                }
                $cfgNew = $fromStore; $cfgNew[$k] = $onDisk;
                $store->save('kyc_config.json', $cfgNew);
                $fromStore = $cfgNew;
                printf("    ✓ %-20s (store had none) → %s  (%s)\n", $ch, $onDisk, $have[$onDisk]);
                $fixed++;
                continue;
            }
            if (isset($have[$live]))   continue;            // live one works — leave it
            if (!isset($have[$onDisk])) {                   // neither works — say so
                printf("    %-22s store '%s' and file '%s' are BOTH absent — not repaired\n",
                       $ch, $live, $onDisk);
                continue;
            }
            $cfgNew = $fromStore; $cfgNew[$k] = $onDisk;
            $store->save('kyc_config.json', $cfgNew);
            $fromStore = $cfgNew;
            printf("    ✓ %-20s %s → %s  (%s, and the old name is not on this server)\n",
                   $ch, $live, $onDisk, $have[$onDisk]);
            $fixed++;
        }
        // The store-only crons need the connection keys too, not just the
        // instance name. A store with an instance and no API URL is still a
        // cron that cannot send.
        $synced = [];
        foreach (['evo_api_url', 'evo_api_key'] as $k) {
            $f = trim((string)($fromFile[$k] ?? ''));
            $t = trim((string)($fromStore[$k] ?? ''));
            if ($f !== '' && $t === '') { $fromStore[$k] = $f; $synced[] = $k; }
        }
        if ($synced !== []) {
            $store->save('kyc_config.json', $fromStore);
            printf("    ✓ copied into the store for the 36 store-only crons: %s\n",
                   implode(', ', $synced));
            $fixed += count($synced);
        }
        echo $fixed === 0
            ? "    Nothing to repair.\n\n"
            : "\n    Repaired {$fixed}. Re-run without --fix to confirm.\n\n";
        if ($fixed > 0) $config = $fromStore + $fromFile;
    }
}

// ── What each kind of message actually does today ───────────────────────
$supportInst = trim((string)($config['evo_instance_support'] ?? ''));
$jobPath = ($evo->isConfigured() && $supportInst !== '')
         ? 'Evolution / ' . $supportInst
         : ($waOn ? 'WASender fallback' : '✗ NOTHING IS SENT');

// NotificationService::sendVia() prefers Evolution and keeps WASender as the
// fallback, mapping its own sender names onto Evolution channels:
//   support  → evo_instance_support      accounts → evo_instance_account
// and wa_force_accounts routes everything to account. So each class of
// message has to be reported separately — one verdict for the whole service
// is what made this doctor claim "nothing is sent" about traffic that sends.
$force    = ($config['wa_force_accounts'] ?? false) === true
         || in_array((string)($config['wa_force_accounts'] ?? ''), ['1'], true);
$instFor  = function (string $sender) use ($config, $force): string {
    $ch = $force ? 'account' : ($sender === 'accounts' ? 'account' : 'support');
    return trim((string)($config['evo_instance_' . $ch] ?? ''));
};
$pathFor  = function (string $sender) use ($instFor, $evo, $waOn): string {
    $i = $instFor($sender);
    if ($evo->isConfigured() && $i !== '') return 'Evolution / ' . $i;
    return $waOn ? 'WASender' : '✗ NOTHING IS SENT — silently';
};

$supportPath = $pathFor('support');
$accountPath = $pathFor('accounts');

// The store-only readers get their own verdict. This is where the technician
// dispatch actually lives, and a merged view hid that it cannot send.
// Re-read: --fix may have just written these, and a stale copy would report
// MISSING about keys that now exist — telling the operator the repair failed.
$storeOnly  = $store->load('kyc_config.json') ?? $storeOnly;
$evoStore   = new EvolutionApiService($storeOnly);
$storeCan   = $evoStore->isConfigured()
              && trim((string)($storeOnly['evo_instance_support'] ?? '')) !== '';

echo "  WHAT THE 36 STORE-ONLY CRONS SEE\n  {$line}\n";
printf("    %-30s %s\n", 'evo_api_url', trim((string)($storeOnly['evo_api_url'] ?? '')) ?: '— MISSING');
printf("    %-30s %s\n", 'evo_api_key', trim((string)($storeOnly['evo_api_key'] ?? '')) !== '' ? 'set' : '— MISSING');
printf("    %-30s %s\n", 'evo_instance_support', trim((string)($storeOnly['evo_instance_support'] ?? '')) ?: '— MISSING');
printf("    %-30s %s\n\n", 'so Evolution is', $storeCan ? 'USABLE' : '✗ NOT USABLE — they fall back to WASender');
if (!$storeCan) {
    echo "    ⚠ cron/job_assignment_notify.php is one of these. Technician dispatch\n";
    echo "      therefore still sends NOTHING, whatever the merged view below says.\n";
    echo "      --fix copies the evo_* keys from the file into the store.\n\n";
}

echo "  WHAT HAPPENS NOW (PluginConfig::load readers)\n  {$line}\n";
printf("    %-38s %s\n", 'technician job dispatch', $jobPath);
printf("    %-38s %s\n", 'AI replies / follow-ups',
    $evo->isConfigured() ? 'Evolution' : '✗ Evolution not configured');
printf("    %-38s %s\n", 'support-channel messages (27 sites)', $supportPath);
printf("    %-38s %s\n", 'accounts-channel messages (20 sites)', $accountPath);
printf("    %-38s %s\n", 'PDFs and images (sendDocument/Image)', $accountPath);
printf("    %-38s %s\n\n", 'support number printed in messages', CustomerContact::support($config));

if ($accountPath !== $supportPath && strpos($accountPath, 'NOTHING') !== false) {
    echo "    ⚠ evo_instance_account is empty, so the 20 accounts-channel messages\n";
    echo "      — invoices, due dates, credits, service end, leave decisions —\n";
    echo "      still go nowhere. Point it at an instance, or at the same one as\n";
    echo "      support if one number serves both.\n\n";
}

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
$paths = [$supportPath, $accountPath, $jobPath];
$dead  = array_filter($paths, fn($p) => strpos($p, 'NOTHING') !== false);
if (!$evo->isConfigured() && !$waOn) {
    echo "  ✗ NEITHER SENDER IS CONFIGURED. Nothing outbound leaves this box.\n\n";
} elseif ($dead === []) {
    echo "  ✓ Every message class has a live path.\n";
    if (!$waOn) echo "    WASender is off, but nothing depends on it except PDFs and images.\n";
    echo "\n";
} else {
    printf("  ⚠ %d of 3 message classes still send NOTHING, silently. See above.\n\n", count($dead));
}
