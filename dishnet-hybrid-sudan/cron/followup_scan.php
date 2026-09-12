<?php
declare(strict_types=1);

/**
 * followup_scan.php — find conversations that have gone quiet. SQL only.
 *
 * No model call, no network, no send. That is what lets it run every cycle
 * inside master.php's budget: the expensive question is only ever asked of a
 * row this job has already decided is worth asking about.
 *
 * It opens a followups row with a due_at. It never sends anything, and it
 * cannot: nothing in this file can reach Evolution.
 */
if (PHP_SAPI !== 'cli') return;

$pluginRoot = dirname(__DIR__);
require_once $pluginRoot . '/lib/error_handler.php';
require_once $pluginRoot . '/lib/bootstrap_data.php';
require_once $pluginRoot . '/lib/StoreInterface.php';
require_once $pluginRoot . '/lib/JsonStore.php';
require_once $pluginRoot . '/lib/SqliteStore.php';
require_once $pluginRoot . '/lib/PluginConfig.php';
require_once $pluginRoot . '/lib/ContactOptOut.php';
require_once $pluginRoot . '/lib/FollowUpPolicy.php';
require_once $pluginRoot . '/lib/FollowUpService.php';

$dataDir = getDataDir($pluginRoot);
$store   = SqliteStore::create($dataDir);
$config  = PluginConfig::load($pluginRoot, $dataDir);

if (empty($config['followup_enabled'])) return;   // quiet: the default

$pdo = $store->getPdo();
$svc = new FollowUpService($pdo);
$oo  = ContactOptOut::fromStore($store);
$now = gmdate('Y-m-d H:i:s');

$maxAge  = (float)($config['followup_max_age_hours'] ?? 336);   // two weeks
$perScan = (int)($config['followup_scan_limit'] ?? 25);

// Candidates: the customer spoke last, long enough ago, and no follow-up is
// open. LEFT JOIN rather than NOT IN — the partial index makes it cheap and a
// NULL in a NOT IN subquery would quietly return nothing at all.
$sql = "SELECT c.* FROM wa_conversations c
          LEFT JOIN followups f
                 ON f.conversation_id = c.id AND f.closed_at IS NULL
         WHERE f.id IS NULL
           AND c.status = 'active'
           AND c.state != 'human_active'
           AND c.last_customer_at IS NOT NULL
           AND c.last_customer_at <= ?
           AND c.last_customer_at >= ?
         ORDER BY c.last_customer_at DESC
         LIMIT ?";

$quietSince = gmdate('Y-m-d H:i:s', strtotime($now . ' UTC') - (int)(FollowUpPolicy::SCHEDULE[1] * 3600));
$notBefore  = gmdate('Y-m-d H:i:s', strtotime($now . ' UTC') - (int)($maxAge * 3600));

$opened = 0; $skipped = 0;
try {
    $st = $pdo->prepare($sql);
    $st->execute([$quietSince, $notBefore, $perScan]);
    $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {
    error_log('[followup_scan] ' . $e->getMessage());
    return;
}

foreach ($rows as $conv) {
    $f = FollowUpPolicy::isFollowable($conv, $now, $maxAge);
    if (!$f['ok']) { $skipped++; continue; }

    // Opted out before we even open a row, so an opted-out customer never
    // acquires follow-up state at all.
    $v = $oo->blocks((string)$conv['phone'], (string)$conv['channel'],
                     ContactOptOut::CLASS_PROACTIVE);
    if ($v['blocked']) {
        $svc->log(null, (int)$conv['id'], 'skipped', $v['reason'], 'scan');
        $skipped++;
        continue;
    }

    // Identity we would be guessing at is not worth opening a row for.
    if (FollowUpPolicy::contentLevel($conv) === FollowUpPolicy::CONTENT_NONE) {
        $svc->log(null, (int)$conv['id'], 'skipped',
            'several customers share this number', 'scan');
        $skipped++;
        continue;
    }

    $r = $svc->open($conv);
    if ($r['ok'] && $r['created']) $opened++;
}

if ($opened || $skipped) {
    echo date('Y-m-d H:i:s') . " [followup_scan] opened={$opened} skipped={$skipped}\n";
}
