<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * wa_compare.php — put two WhatsApp numbers side by side, row by row.
 *
 *   php tools/wa_compare.php                     every mapped channel
 *   php tools/wa_compare.php sales support       just these two
 *
 * "Why does that number work and this one not" is a comparison, and answering
 * it from separate one-sided checks is how a whole day gets spent. Every row
 * here is read live and marked SAME or DIFFERENT, so the difference is found
 * rather than argued about.
 *
 * What it does NOT compare, because it does not exist in this architecture:
 * phone number ID, WhatsApp Business Account ID, access token. Those are Meta
 * Cloud API concepts. This runs on Evolution API with Baileys — QR-paired
 * WhatsApp Web sessions — where a channel is an instance name, and the phone
 * number lives in Evolution, never in the plugin.
 *
 * Read-only.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/EvolutionApiService.php';

$dataDir = getenv('DN_DATA_DIR') ?: getDataDir($root);
$config  = PluginConfig::load($root, $dataDir);
$evo     = new EvolutionApiService($config);
$pdo     = new PDO('sqlite:' . $dataDir . '/plugin.sqlite3');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$args = array_values(array_filter(array_slice($argv, 1), function ($a) { return $a[0] !== '-'; }));
$channels = $args ?: array_values(array_filter(EvolutionApiService::CHANNELS,
    function ($c) use ($evo) { return $evo->instanceFor($c) !== ''; }));

if (count($channels) < 2) {
    echo "\n  Need two mapped channels to compare. Mapped: "
       . implode(', ', $evo->configuredChannels()) . "\n\n";
    exit(1);
}

/** Owner number as Evolution reports it — the plugin holds no phone numbers. */
$owners = [];
foreach ($evo->listInstances() as $i) $owners[$i['name']] = (string)($i['phone'] ?? '');

$since = gmdate('Y-m-d H:i:s', time() - 86400);
$facts = [];

foreach ($channels as $ch) {
    $inst = $evo->instanceFor($ch);
    $f = ['channel' => $ch, 'instance' => $inst];

    $f['number'] = $owners[$inst] ?? '(not reported)';
    $state = $evo->connectionState($inst);
    $f['connection'] = $state ?? 'unreachable';

    $hook = $evo->findWebhook($inst);
    $url  = (string)($hook['url'] ?? ($hook['data']['url'] ?? ''));
    $f['webhook'] = $url === '' ? 'NOT REGISTERED'
                  : (strpos($url, 'evo_webhook') !== false ? 'ours' : 'points elsewhere');

    $q = $pdo->prepare("SELECT COUNT(*) FROM wa_conversations WHERE channel = ?");
    $q->execute([$ch]);
    $f['conversations'] = (string)(int)$q->fetchColumn();

    $q = $pdo->prepare("SELECT COUNT(*) FROM wa_messages m JOIN wa_conversations c ON c.id = m.conversation_id
                        WHERE c.channel = ? AND m.direction = 'in' AND m.sent_at > ?");
    $q->execute([$ch, $since]);
    $f['inbound 24h'] = (string)(int)$q->fetchColumn();

    $q = $pdo->prepare("SELECT COUNT(*) FROM wa_messages m JOIN wa_conversations c ON c.id = m.conversation_id
                        WHERE c.channel = ? AND m.role = 'assistant' AND m.sent_at > ?");
    $q->execute([$ch, $since]);
    $f['AI replies 24h'] = (string)(int)$q->fetchColumn();

    $q = $pdo->prepare("SELECT MAX(m.sent_at) FROM wa_messages m JOIN wa_conversations c ON c.id = m.conversation_id
                        WHERE c.channel = ? AND m.role = 'assistant'");
    $q->execute([$ch]);
    $f['last AI reply'] = (string)($q->fetchColumn() ?: 'never');

    // The one place the pipeline genuinely differs: the assistant's role text.
    $f['AI role'] = $ch === 'sales' ? 'SALES advisor' : ($ch === 'account' ? 'ACCOUNTS' : 'SUPPORT');
    $f['may sell'] = ($ch === 'sales'
        || filter_var($config['ai_sales_on_all_numbers'] ?? false, FILTER_VALIDATE_BOOLEAN)) ? 'yes' : 'no';
    $f['autoreply gate'] = ($ch === 'account' && empty($config['wa_accounts_autoreply_enabled']))
        ? 'DISABLED by wa_accounts_autoreply_enabled' : 'none';

    $facts[$ch] = $f;
}

$rows = array_keys(reset($facts));
$w    = 20;

echo "\n  ";
printf("%-{$w}s", '');
foreach ($channels as $ch) printf("%-26s", strtoupper($ch));
echo "\n  " . str_repeat('=', $w + 26 * count($channels) + 12) . "\n";

$diffs = [];
foreach ($rows as $r) {
    $vals = array_map(function ($ch) use ($facts, $r) { return (string)$facts[$ch][$r]; }, $channels);
    $same = count(array_unique($vals)) === 1;
    // Volume and timing differ by traffic, not by configuration.
    $informational = in_array($r, ['channel','instance','number','conversations','inbound 24h',
                                   'AI replies 24h','last AI reply','AI role'], true);
    printf("  %-{$w}s", $r);
    foreach ($vals as $v) printf("%-26s", mb_substr($v, 0, 25));
    if (!$same && !$informational) { echo '  <== DIFFERENT'; $diffs[] = $r; }
    echo "\n";
}

echo "\n";
if ($diffs === []) {
    echo "  No configuration difference between these numbers.\n";
    echo "  Receiving, queueing, processing and sending are identical code paths —\n";
    echo "  the pipeline never branches on which channel a message arrived on.\n";
    echo "  If one answers and the other does not, the cause is in the rows above\n";
    echo "  marked by volume: no inbound means Evolution is not delivering to us.\n\n";
} else {
    echo "  DIFFERENT: " . implode(', ', $diffs) . "\n\n";
    foreach ($diffs as $r) {
        if ($r === 'webhook')    echo "  webhook — Evolution has nowhere to post that number's messages,\n"
                                    . "            so the plugin never sees them. Fix: tools/wa_webhook_doctor.php\n";
        if ($r === 'connection') echo "  connection — a session that is not open receives nothing at all.\n"
                                    . "            Fix: tools/wa_connect.php --pair <instance> --number <msisdn>\n";
        if ($r === 'may sell')   echo "  may sell — one number answers sales questions and the other hands\n"
                                    . "            them over. Fix: tools/set_sales_everywhere.php --on\n";
    }
    echo "\n";
}
exit($diffs === [] ? 0 : 1);
