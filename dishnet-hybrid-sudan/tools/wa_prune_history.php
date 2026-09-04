<?php
declare(strict_types=1);

/**
 * wa_prune_history.php — inspect and clean the WhatsApp inbox (wa_conversations
 * + wa_messages), for when one country's install inherits another's history.
 *
 * Run inside the ucrm container, from the plugin directory:
 *
 *   php tools/wa_prune_history.php                  report only, change nothing
 *   php tools/wa_prune_history.php --delete-sudan   delete conversations whose phone starts 249
 *   php tools/wa_prune_history.php --delete-all     delete EVERY conversation (fresh inbox)
 *
 * Every delete first copies plugin.sqlite3 aside inside the data directory;
 * the report names the backup so the delete is reversible by copying it back.
 * CLI only.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
chdir($root);
require_once $root . '/lib/error_handler.php';
require_once $root . '/lib/bootstrap_data.php';

$MODE = 'report';
$dataDir = null;
$args = array_slice($argv, 1);
for ($i = 0; $i < count($args); $i++) {
    if ($args[$i] === '--delete-sudan') $MODE = 'sudan';
    elseif ($args[$i] === '--delete-all') $MODE = 'all';
    elseif ($args[$i] === '--data') $dataDir = (string)($args[$i + 1] ?? '');
}
if ($dataDir === null || $dataDir === '') $dataDir = getDataDir($root);

$dbFile = $dataDir . '/plugin.sqlite3';
if (!is_file($dbFile)) { echo "No database at {$dbFile} — nothing to prune.\n"; exit(1); }
$pdo = new PDO('sqlite:' . $dbFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$hasConv = (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='wa_conversations'")->fetchColumn();
if (!$hasConv) { echo "No wa_conversations table — the inbox is already empty.\n"; exit(0); }

// ── Report ──────────────────────────────────────────────────────────────────
echo "WhatsApp inbox — {$dbFile}\n\n";
$rows = $pdo->query("
    SELECT CASE WHEN phone LIKE '249%' THEN 'Sudan (249…)'
                WHEN phone LIKE '256%' THEN 'Uganda (256…)'
                ELSE 'other' END AS country,
           channel,
           COUNT(*) AS convs,
           COALESCE(SUM(message_count), 0) AS msgs,
           MIN(COALESCE(last_message_at, created_at)) AS first_seen,
           MAX(COALESCE(last_message_at, created_at)) AS last_seen
    FROM wa_conversations
    GROUP BY country, channel
    ORDER BY country, channel
")->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) { echo "  inbox is empty.\n"; exit(0); }
printf("  %-14s %-10s %6s %7s  %-19s %-19s\n", 'country', 'channel', 'convs', 'msgs', 'oldest', 'newest');
foreach ($rows as $r) {
    printf("  %-14s %-10s %6d %7d  %-19s %-19s\n",
        $r['country'], $r['channel'], (int)$r['convs'], (int)$r['msgs'],
        (string)$r['first_seen'], (string)$r['last_seen']);
}
$msgTotal = (int)$pdo->query("SELECT COUNT(*) FROM wa_messages")->fetchColumn();
echo "\n  wa_messages rows in total: {$msgTotal}\n";

if ($MODE === 'report') {
    echo "\nNothing changed. To clean:\n";
    echo "  --delete-sudan   removes the Sudan (249…) conversations and their messages\n";
    echo "  --delete-all     removes every conversation for a fresh launch inbox\n";
    exit(0);
}

// ── Backup, then delete ─────────────────────────────────────────────────────
$backup = $dataDir . '/backup-wa-prune-' . gmdate('Ymd-His') . '.sqlite3';
try {
    $pdo->exec("VACUUM INTO " . $pdo->quote($backup));
} catch (\Throwable $e) {
    if (!@copy($dbFile, $backup)) { echo "FAILED to back up the database — refusing to delete.\n"; exit(1); }
}
echo "\n  backup written: {$backup}\n";

$where = $MODE === 'sudan' ? "phone LIKE '249%'" : '1=1';
$doomed = (int)$pdo->query("SELECT COUNT(*) FROM wa_conversations WHERE {$where}")->fetchColumn();
if ($doomed === 0) { echo "  nothing matches — inbox unchanged.\n"; exit(0); }

$pdo->exec('BEGIN');
$m = $pdo->exec("DELETE FROM wa_messages WHERE conversation_id IN (SELECT id FROM wa_conversations WHERE {$where})");
$c = $pdo->exec("DELETE FROM wa_conversations WHERE {$where}");
$pdo->exec('COMMIT');
echo "  deleted {$c} conversation(s) and {$m} message(s)"
   . ($MODE === 'sudan' ? ' with Sudan (249…) numbers' : '') . ".\n";
try { $pdo->exec('VACUUM'); } catch (\Throwable $e) { /* size only, not correctness */ }

echo "  To undo: stop writes briefly and copy the backup over plugin.sqlite3.\n";
exit(0);
