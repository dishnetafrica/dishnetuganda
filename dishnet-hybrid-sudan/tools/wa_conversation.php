<?php
declare(strict_types=1);
chdir(dirname(__DIR__));
/**
 * wa_conversation.php — read one thread, from the terminal.
 *
 *   php tools/wa_conversation.php --id 109
 *   php tools/wa_conversation.php --phone 211927797217 --last 40
 *   php tools/wa_conversation.php --id 109 --all
 *
 * Read-only. It exists because deciding what to do about a conversation
 * required opening the admin inbox in a browser, and the decisions that matter
 * — is this a real customer, is this our own handset, has a human replied —
 * are being made at a terminal at the time.
 *
 * sent_at is stored UTC (gmdate). Shown UTC and labelled, because a timestamp
 * whose zone you have to guess has already caused two wrong diagnoses here.
 *
 * CLI only.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';

$args  = array_slice($argv, 1);
$value = function (string $f) use ($args) {
    $i = array_search($f, $args, true);
    return ($i !== false && isset($args[$i + 1])) ? (string)$args[$i + 1] : '';
};

$dataDir = getenv('DN_DATA_DIR') ?: getDataDir($root);
$config  = PluginConfig::load($root, $dataDir);
$pdo     = SqliteStore::create($dataDir)->getPdo();

$id    = (int)$value('--id');
$phone = preg_replace('/\D+/', '', $value('--phone')) ?? '';
$last  = (int)($value('--last') ?: 25);
$all   = in_array('--all', $args, true);

if ($id <= 0 && $phone === '') {
    echo "\n  php tools/wa_conversation.php --id <n>\n";
    echo "  php tools/wa_conversation.php --phone <number> [--last 40] [--all]\n\n";
    $rows = $pdo->query(
        "SELECT id, phone, channel, state, message_count, last_message_at
           FROM wa_conversations ORDER BY last_message_at DESC LIMIT 15"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    echo "  MOST RECENT\n\n";
    printf("    %-6s %-16s %-9s %-13s %6s  %s\n", 'ID', 'PHONE', 'CHANNEL', 'STATE', 'MSGS', 'LAST (UTC)');
    foreach ($rows as $r) {
        printf("    c%-5d %-16s %-9s %-13s %6d  %s\n", (int)$r['id'], (string)$r['phone'],
               (string)$r['channel'], (string)$r['state'], (int)$r['message_count'],
               (string)($r['last_message_at'] ?: '—'));
    }
    echo "\n";
    exit(0);
}

if ($id > 0) {
    $st = $pdo->prepare("SELECT * FROM wa_conversations WHERE id = ?");
    $st->execute([$id]);
} else {
    $last9 = strlen($phone) >= 9 ? substr($phone, -9) : $phone;
    $st = $pdo->prepare(
        "SELECT * FROM wa_conversations
          WHERE replace(replace(replace(phone,'+',''),' ',''),'-','') LIKE ?
          ORDER BY message_count DESC LIMIT 1"
    );
    $st->execute(['%' . $last9]);
}
$c = $st->fetch(PDO::FETCH_ASSOC);
if (!$c) { echo "\n  No such conversation.\n\n"; exit(1); }

$cid    = (int)$c['id'];
$alert  = preg_replace('/\D+/', '', (string)($config['alert_whatsapp'] ?? '')) ?? '';
$cPhone = preg_replace('/\D+/', '', (string)$c['phone']) ?? '';
$isAlertTarget = $alert !== '' && strlen($alert) >= 9 && strlen($cPhone) >= 9
              && substr($alert, -9) === substr($cPhone, -9);

echo "\n  c{$cid}  " . (string)$c['phone'] . "  on " . (string)$c['channel'] . "\n\n";
printf("    %-16s %s\n", 'state',      (string)$c['state']);
printf("    %-16s %s\n", 'status',     (string)$c['status']);
printf("    %-16s %d\n", 'messages',   (int)$c['message_count']);
printf("    %-16s %s\n", 'first seen', (string)($c['created_at'] ?: '—'));
printf("    %-16s %s\n", 'last message', (string)($c['last_message_at'] ?: '—'));
printf("    %-16s %s\n", 'last human', (string)($c['last_human_reply_at'] ?: 'never'));
printf("    %-16s %s\n", 'in uCRM',
       $c['crm_client_id'] ? ('#' . (int)$c['crm_client_id'] . ' ' . (string)$c['crm_client_name'])
                           : 'not matched to a customer');
if ($isAlertTarget) {
    echo "\n    ⚠ THIS NUMBER IS THE HANDOVER ALERT TARGET.\n";
    echo "      Alerts land in this thread and the assistant answers them.\n";
}

$sql = "SELECT direction, role, agent_name, body, sent_at FROM wa_messages
         WHERE conversation_id = ? ORDER BY sent_at DESC, id DESC";
if (!$all) $sql .= " LIMIT " . max(1, $last);
$st = $pdo->prepare($sql);
$st->execute([$cid]);
$msgs = array_reverse($st->fetchAll(PDO::FETCH_ASSOC) ?: []);

$shown = count($msgs);
$total = (int)$c['message_count'];
echo "\n  " . ($all ? "ALL {$shown}" : "LAST {$shown} of {$total}") . " MESSAGES (times UTC)\n\n";

foreach ($msgs as $m) {
    $who = (string)$m['role'] === 'customer' ? 'THEM'
         : ((string)$m['role'] === 'assistant' ? 'AI'
         : strtoupper(substr((string)($m['agent_name'] ?: $m['role']), 0, 8)));
    $body = trim(preg_replace('/\s+/', ' ', (string)$m['body']) ?? '');
    printf("    %s  %-8s %s\n", (string)$m['sent_at'], $who, mb_substr($body, 0, 300));
    if (mb_strlen($body) > 300) echo "                                   …\n";
}
echo "\n";
exit(0);
