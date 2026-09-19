<?php
declare(strict_types=1);
chdir(dirname(__DIR__));
/**
 * wa_clock_repair.php — put WhatsApp message rows written on the wrong clock
 * back on the right one.
 *
 *   php tools/wa_clock_repair.php          (report only)
 *   php tools/wa_clock_repair.php --fix    (repair)
 *
 * wa_messages.sent_at is UTC. One writer — the notification record in
 * NotificationService — used date() instead of gmdate(), and under the
 * Africa/Kampala zone the cron runs in that stamped its rows three hours
 * ahead. Three things went wrong with those rows: the Inbox showed a
 * reminder after messages that were sent later; the model's history was
 * ordered the same way; and the conversation's last_agent_at sat in the
 * future, so the watchdog read a waiting customer as answered.
 *
 * The repair is safe by construction. created_at is written by SQLite
 * itself (datetime('now'), UTC) at the moment of insertion, and a message
 * cannot have been sent after the row recording it was created. Any row
 * whose sent_at is more than half an hour AFTER its own created_at is
 * wrong, and created_at is the truth for it. Rows with sent_at earlier
 * than created_at (imports of older history) are left alone. Afterwards
 * the touched conversations get last_message_at, last_customer_at and
 * last_agent_at recomputed from their rows.
 *
 * Prints counts only. No message content, phone or name is shown.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';

$fix     = in_array('--fix', array_slice($argv, 1), true);
$dataDir = cliDataDir($root);
$store   = SqliteStore::create($dataDir);
$pdo     = $store->getPdo();

$has = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'wa_messages'")->fetchColumn();
if (!$has) {
    echo "wa_clock_repair: no wa_messages table in {$dataDir} — nothing to do\n";
    exit(0);
}

$where = "sent_at IS NOT NULL AND created_at IS NOT NULL AND sent_at > datetime(created_at, '+30 minutes')";

$rows  = (int)$pdo->query("SELECT COUNT(*) FROM wa_messages WHERE {$where}")->fetchColumn();
$convs = $pdo->query("SELECT DISTINCT conversation_id FROM wa_messages WHERE {$where}")->fetchAll(PDO::FETCH_COLUMN);
$byAgent = $pdo->query(
    "SELECT COALESCE(agent_name, '(none)') AS who, COUNT(*) AS n FROM wa_messages WHERE {$where} GROUP BY who ORDER BY n DESC"
)->fetchAll(PDO::FETCH_ASSOC);

echo "wa_clock_repair: data directory {$dataDir}\n";
echo "messages stamped more than 30 min after their own creation: {$rows}\n";
foreach ($byAgent as $r) {
    echo sprintf("  %-20s %d\n", $r['who'], (int)$r['n']);
}
echo "conversations affected: " . count($convs) . "\n";

if ($rows === 0) {
    echo "nothing to repair\n";
    exit(0);
}
if (!$fix) {
    echo "dry run — nothing changed. Add --fix to set sent_at = created_at on those rows\n"
       . "and recompute last_message_at / last_customer_at / last_agent_at for the affected conversations.\n";
    exit(0);
}

$pdo->beginTransaction();
try {
    $fixed = $pdo->exec("UPDATE wa_messages SET sent_at = created_at WHERE {$where}");

    $recalc = $pdo->prepare(
        "UPDATE wa_conversations SET
            last_message_at  = (SELECT MAX(sent_at) FROM wa_messages WHERE conversation_id = :id),
            last_customer_at = (SELECT MAX(sent_at) FROM wa_messages WHERE conversation_id = :id AND direction = 'in'),
            last_agent_at    = (SELECT MAX(sent_at) FROM wa_messages WHERE conversation_id = :id AND direction = 'out'),
            updated_at       = datetime('now')
          WHERE id = :id"
    );
    $touched = 0;
    foreach ($convs as $cid) {
        $recalc->execute([':id' => (int)$cid]);
        $touched += $recalc->rowCount();
    }
    $pdo->commit();
    echo "repaired: {$fixed} message rows; recomputed: {$touched} conversations\n";
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "wa_clock_repair: failed, nothing changed — " . $e->getMessage() . "\n");
    exit(1);
}

$left = (int)$pdo->query("SELECT COUNT(*) FROM wa_messages WHERE {$where}")->fetchColumn();
echo "remaining rows ahead of their creation: {$left}\n";
exit($left === 0 ? 0 : 1);
