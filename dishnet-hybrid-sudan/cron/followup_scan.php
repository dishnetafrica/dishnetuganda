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

// 5.18.50 (docs/44 J8, M1): on Uganda a colleague's conversation — filed 'staff', or from an active staff account's
// whole number — is never opened. Every other install: $colleagues is null and nothing here changes.
require_once $pluginRoot . '/lib/ColleagueNumbers.php';
$colleagues = ColleagueNumbers::forInstall($config, $dataDir, $store);

// 5.18.89 (docs/65 §AA item 6): with the channel registry on, a conversation on a salesperson's own number is never
// opened — they follow up personally. It is left out of the query below, so it takes none of this scan's places.
// wa_followups_on_owned_numbers lifts the hold. Registry off: nothing is held and the query is unchanged.
require_once $pluginRoot . '/lib/OwnedNumberHold.php';
$ownedHold = OwnedNumberHold::forInstall($config, $dataDir, $pdo);
[$holdSql, $holdArgs] = $ownedHold->sqlExclusion('c.channel');

// 5.18.90 (docs/65 §AD): with the channel registry on, a conversation on a number that may send nothing automated — its
// assistant off, the number paused or switched off, a salesperson's number not verified or the department numbers not
// all recorded — is never opened, and takes none of this scan's places; nor is a conversation filed 'staff'. Per row, a
// conversation with one of DishNet's own numbers is never opened either. Registry off: nothing is added.
require_once $pluginRoot . '/lib/AutomationPolicy.php';
$autoPolicy = AutomationPolicy::forInstall($config, $dataDir, $pdo, $store);
[$autoSql, $autoArgs] = $autoPolicy->sqlExclusion('c.channel');
if ($autoPolicy->active()) $autoSql .= " AND COALESCE(c.category, '') <> 'staff'";
$holdSql .= $autoSql;
$holdArgs = array_merge($holdArgs, $autoArgs);

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
           AND c.last_customer_at >= ?" . $holdSql . "
         ORDER BY c.last_customer_at DESC
         LIMIT ?";

$quietSince = gmdate('Y-m-d H:i:s', strtotime($now . ' UTC') - (int)(FollowUpPolicy::SCHEDULE[1] * 3600));
$notBefore  = gmdate('Y-m-d H:i:s', strtotime($now . ' UTC') - (int)($maxAge * 3600));

// THE BACKLOG GUARD.
//
// On the day this is switched on, every conversation that has ever gone quiet
// qualifies at once. On the live box that was 50 people — including a thread
// that turned out to be two colleagues testing the assistant, and numbers in
// three other countries. Fifty strangers receiving a follow-up in one morning
// is not a soft launch, it is the thing everybody fears about letting an AI
// talk to customers.
//
// followup_not_before is a floor on the customer's last message. Set it to
// the moment you switch on and the system only ever considers enquiries that
// have gone quiet SINCE — the backlog is left alone, and the first follow-ups
// are conversations you can still remember.
$floor = trim((string)($config['followup_not_before'] ?? ''));
if ($floor !== '' && $floor > $notBefore) $notBefore = $floor;

$opened = 0; $skipped = 0;
try {
    $st = $pdo->prepare($sql);
    $st->execute(array_merge([$quietSince, $notBefore], $holdArgs, [$perScan]));
    $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {
    error_log('[followup_scan] ' . $e->getMessage());
    return;
}

foreach ($rows as $conv) {
    $f = FollowUpPolicy::isFollowable($conv, $now, $maxAge, $colleagues !== null);
    if (!$f['ok']) { $skipped++; continue; }
    if ($ownedHold->holds((string)$conv['channel'])) { $skipped++; continue; }   // the query already left these out
    if ($colleagues !== null && $colleagues->isColleague((string)$conv['phone'])) {
        $svc->log(null, (int)$conv['id'], 'skipped', "a colleague's number", 'scan');
        $skipped++;
        continue;
    }
    $own = $autoPolicy->senderClass((string)$conv['channel'], (string)$conv['phone']);   // 5.18.90 (docs/65 §AD)
    if ($own !== '') {
        $svc->log(null, (int)$conv['id'], 'skipped', 'a DishNet number (' . $own . ')', 'scan');
        $skipped++;
        continue;
    }

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
