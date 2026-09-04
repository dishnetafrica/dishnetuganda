<?php
declare(strict_types=1);
/**
 * wa_prune_history against a real (temp) database: the report changes nothing,
 * --delete-sudan removes only 249-prefixed conversations and their messages,
 * a backup exists before anything is deleted, --delete-all empties the inbox.
 */
$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }

$tmp = sys_get_temp_dir() . '/wa_prune_test_' . getmypid();
@mkdir($tmp, 0777, true);
$db = $tmp . '/plugin.sqlite3';
@unlink($db);
$pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE wa_conversations (
    id INTEGER PRIMARY KEY AUTOINCREMENT, phone TEXT NOT NULL, channel TEXT NOT NULL DEFAULT 'support',
    display_name TEXT, message_count INTEGER NOT NULL DEFAULT 0,
    last_message_at TEXT, created_at TEXT NOT NULL DEFAULT (datetime('now')))");
$pdo->exec("CREATE TABLE wa_messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT, conversation_id INTEGER NOT NULL, body TEXT NOT NULL)");
$pdo->exec("INSERT INTO wa_conversations (phone, channel, display_name, message_count, last_message_at)
            VALUES ('249900000001','sales','Khartoum test',2,'2026-08-26 12:31:00'),
                   ('249911111111','support','Juba office',1,'2026-08-20 09:00:00'),
                   ('256700000001','sales','Kampala customer',2,'2026-09-01 10:00:00')");
$pdo->exec("INSERT INTO wa_messages (conversation_id, body)
            VALUES (1,'hi'),(1,'price?'),(2,'hello'),(3,'webale'),(3,'plan?')");
$pdo = null;

$phpBin = PHP_BINARY;
$tool = dirname(__DIR__) . '/tools/wa_prune_history.php';
$run = function (string $extra) use ($phpBin, $tool, $tmp): array {
    $out = (string)shell_exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($tool)
        . ' --data ' . escapeshellarg($tmp) . ' ' . $extra . ' 2>&1; echo "EXIT:$?"');
    preg_match('/EXIT:(\d+)/', $out, $m);
    return [$out, (int)($m[1] ?? -1)];
};

echo "Report mode\n";
[$out, $code] = $run('');
t('exits 0', $code, 0);
t('names the Sudan bucket', strpos($out, 'Sudan (249') !== false, true);
t('names the Uganda bucket', strpos($out, 'Uganda (256') !== false, true);
t('counts all messages', strpos($out, 'wa_messages rows in total: 5') !== false, true);
$pdo = new PDO('sqlite:' . $db);
t('report deleted nothing', (int)$pdo->query('SELECT COUNT(*) FROM wa_conversations')->fetchColumn(), 3);
$pdo = null;

echo "\n--delete-sudan\n";
[$out, $code] = $run('--delete-sudan');
t('exits 0', $code, 0);
t('says what it deleted', strpos($out, 'deleted 2 conversation(s) and 3 message(s)') !== false, true);
t('a backup exists', count(glob($tmp . '/backup-wa-prune-*.sqlite3')) >= 1, true);
$pdo = new PDO('sqlite:' . $db);
t('only the Uganda conversation remains', $pdo->query('SELECT phone FROM wa_conversations')->fetchColumn(), '256700000001');
t('only its messages remain', (int)$pdo->query('SELECT COUNT(*) FROM wa_messages')->fetchColumn(), 2);
$pdo = null;
$backup = glob($tmp . '/backup-wa-prune-*.sqlite3')[0];
$bpdo = new PDO('sqlite:' . $backup);
t('the backup still holds all three conversations', (int)$bpdo->query('SELECT COUNT(*) FROM wa_conversations')->fetchColumn(), 3);
$bpdo = null;

echo "\n--delete-all\n";
[$out, $code] = $run('--delete-all');
t('exits 0', $code, 0);
$pdo = new PDO('sqlite:' . $db);
t('inbox empty', (int)$pdo->query('SELECT COUNT(*) FROM wa_conversations')->fetchColumn(), 0);
t('no orphan messages', (int)$pdo->query('SELECT COUNT(*) FROM wa_messages')->fetchColumn(), 0);
$pdo = null;

echo "\nMissing database\n";
$empty = $tmp . '/empty'; @mkdir($empty);
$out = (string)shell_exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($tool)
    . ' --data ' . escapeshellarg($empty) . ' 2>&1; echo "EXIT:$?"');
t('refuses cleanly', strpos($out, 'EXIT:1') !== false && strpos($out, 'No database') !== false, true);

array_map('unlink', glob($tmp . '/backup-wa-prune-*.sqlite3'));
@unlink($db); @rmdir($empty); @rmdir($tmp);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
