<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * followup_doctor.php — who would be followed up, and what stopped the rest.
 *
 *   php tools/followup_doctor.php            what the next scan and run would do
 *   php tools/followup_doctor.php --gates    show every gate decision, not just the stops
 *
 * Read-only. It never opens a follow-up, asks the assistant, or sends
 * anything. A check that COULD NOT RUN reports UNKNOWN — it never reports a
 * pass it did not establish.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/ConversationService.php';
require_once $root . '/lib/ContactOptOut.php';
require_once $root . '/lib/FollowUpPolicy.php';
require_once $root . '/lib/FollowUpService.php';

$showAll = in_array('--gates', $argv, true);
$dataDir = getDataDir($root);
$store   = SqliteStore::create($dataDir);
$config  = PluginConfig::load($root, $dataDir);
$pdo     = $store->getPdo();
$svc     = new FollowUpService($pdo);
$convSvc = new ConversationService($dataDir, $pdo);
$oo      = ContactOptOut::fromStore($store);
$now     = gmdate('Y-m-d H:i:s');
$enabled = !empty($config['followup_enabled']);

echo "\n  CUSTOMER FOLLOW-UPS (read-only)\n";
echo "  " . str_repeat('─', 72) . "\n";
printf("  %-24s %s\n", 'followup_enabled', $enabled ? 'on' : 'OFF — nothing runs');
$win = FollowUpPolicy::withinSendingWindow($now);
printf("  %-24s %s\n", 'sending window right now',
    $win['ok'] ? 'open' : 'closed (' . $win['reason'] . '), next ' . $win['next'] . ' UTC');
printf("  %-24s %s\n", 'now', $now . ' UTC');

// Does the schema exist? A missing table is not "no follow-ups".
$have = [];
foreach (['followups', 'followup_drafts', 'followup_sends', 'followup_events', 'contact_optouts'] as $t) {
    try { $pdo->query("SELECT 1 FROM {$t} LIMIT 1"); $have[$t] = true; }
    catch (\Throwable $e) { $have[$t] = false; }
}
$missing = array_keys(array_filter($have, static fn($v) => !$v));
if ($missing) {
    echo "\n  ✗ UNKNOWN — these tables do not exist: " . implode(', ', $missing) . "\n";
    echo "    The migrations have not run. Nothing below can be established.\n\n";
    exit(1);
}

$openRows = $svc->allOpen(500);
$pending  = $svc->pendingDrafts(500);
$approved = $svc->approvedDrafts(500);
echo "\n";
printf("  %-24s %d\n", 'open follow-ups', count($openRows));
printf("  %-24s %d\n", 'drafts awaiting a person', count($pending));
printf("  %-24s %d\n", 'approved, not yet sent', count($approved));
printf("  %-24s %d\n", 'live opt-outs', count($oo->live(500)));

// What the next scan would pick up.
$quietSince = gmdate('Y-m-d H:i:s', time() - (int)(FollowUpPolicy::SCHEDULE[1] * 3600));
$notBefore  = gmdate('Y-m-d H:i:s', time() - (int)(336 * 3600));
$st = $pdo->prepare(
    "SELECT c.* FROM wa_conversations c
       LEFT JOIN followups f ON f.conversation_id = c.id AND f.closed_at IS NULL
      WHERE f.id IS NULL AND c.status = 'active' AND c.state != 'human_active'
        AND c.last_customer_at IS NOT NULL
        AND c.last_customer_at <= ? AND c.last_customer_at >= ?
      ORDER BY c.last_customer_at DESC LIMIT 50");
$st->execute([$quietSince, $notBefore]);
$cands = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];

echo "\n  THE NEXT SCAN WOULD CONSIDER " . count($cands) . " QUIET CONVERSATION(S)\n";
echo "  " . str_repeat('─', 72) . "\n";
if (!$cands) {
    echo "  Nothing quiet enough. A conversation qualifies "
       . FollowUpPolicy::SCHEDULE[1] . "h after the customer's last message.\n";
}
$would = 0;
foreach ($cands as $c) {
    $why = [];
    $f = FollowUpPolicy::isFollowable($c, $now);
    if (!$f['ok']) $why[] = $f['reason'];
    $v = $oo->blocks((string)$c['phone'], (string)$c['channel'], ContactOptOut::CLASS_PROACTIVE);
    if ($v['blocked']) $why[] = $v['reason'];
    $lvl = FollowUpPolicy::contentLevel($c);
    if ($lvl === FollowUpPolicy::CONTENT_NONE) $why[] = 'identity ambiguous';

    if ($why === []) $would++;
    if ($why === [] || $showAll) {
        printf("  %-16s c%-5d %-9s %s\n",
            (string)$c['phone'], (int)$c['id'], $lvl,
            $why === [] ? 'would open a follow-up' : 'skipped: ' . implode('; ', $why));
    }
}
echo "\n  " . $would . " would open. " . (count($cands) - $would) . " would be skipped"
   . ($showAll ? '' : ' (--gates to see why)') . ".\n";

// What the next run would do with rows already open.
$due = $svc->due($now, 100);
echo "\n  THE NEXT RUN WOULD EVALUATE " . count($due) . " DUE FOLLOW-UP(S)\n";
echo "  " . str_repeat('─', 72) . "\n";
foreach ($due as $fu) {
    $conv = $convSvc->getConversation((int)$fu['conversation_id']);
    if ($conv === null) { printf("  fu%-5d conversation is gone\n", (int)$fu['id']); continue; }
    $msgs = $convSvc->getMessages((int)$fu['conversation_id'], 60);
    $g = FollowUpPolicy::gate([
        'enabled' => true, 'now' => $now, 'conv' => $conv, 'followup' => $fu,
        'opt_out' => $oo->blocks((string)$fu['phone'], (string)$fu['channel'],
                                 ContactOptOut::CLASS_PROACTIVE),
        'sent_today' => $svc->sentTodayOn((string)$fu['channel'], $now),
        'daily_cap'  => (int)($config['followup_daily_cap'] ?? 30),
        'thread_text' => implode("\n", array_map(static fn($m) => (string)($m['body'] ?? ''), $msgs)),
    ]);
    printf("  fu%-5d %-16s attempt %d/%d  %-8s %s\n",
        (int)$fu['id'], (string)$fu['phone'], (int)$fu['attempts'] + 1, (int)$fu['max_attempts'],
        $g['action'], $g['reason']);
}
if (!$due) echo "  Nothing due.\n";

if (!$enabled) {
    echo "\n  Remember: followup_enabled is OFF, so none of the above actually happens.\n";
}
echo "\n";
