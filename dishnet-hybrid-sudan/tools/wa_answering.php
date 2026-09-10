<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * wa_answering.php — is this number answering customers, right now?
 *
 *   php tools/wa_answering.php                  every mapped number
 *   php tools/wa_answering.php --channel sales  just one
 *
 * Written because that question took six separate queries to answer, and each
 * one told a half-truth on its own. The assistant was replying 64 times an
 * hour while five customers sat in silence; the cron was healthy while the
 * worker saw no work; Evolution returned ok while nothing reached anyone.
 *
 * So this checks the whole path and refuses to call it healthy unless every
 * part of it is:
 *
 *   the instance is connected            — a closed one receives nothing
 *   the webhook is ours                  — Evolution must know where to post
 *   messages are arriving                — inbound in the recent window
 *   replies are going out                — assistant messages, not just events
 *   nothing is queued and unclaimed      — a stuck queue looks like silence
 *   nobody is waiting unanswered         — the only measure a customer feels
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

$args = array_slice($argv, 1);
$only = '';
$i = array_search('--channel', $args, true);
if ($i !== false && isset($args[$i + 1])) $only = (string)$args[$i + 1];

$pdo = new PDO('sqlite:' . $dataDir . '/plugin.sqlite3');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/** Timestamps are stored UTC; compare in UTC. */
$since = gmdate('Y-m-d H:i:s', time() - 3600);
$ago   = function (string $ts): string {
    $s = time() - strtotime($ts . ' UTC');
    if ($s < 0)     return 'just now';
    if ($s < 90)    return $s . 's ago';
    if ($s < 5400)  return (int)round($s / 60) . 'm ago';
    return (int)round($s / 3600) . 'h ago';
};

$channels = $only !== '' ? [$only] : EvolutionApiService::CHANNELS;
$anyBad   = false;

foreach ($channels as $channel) {
    $instance = $evo->instanceFor($channel);
    if ($instance === '') continue;

    echo "\n  " . strtoupper($channel) . " — " . $instance . "\n";
    echo "  " . str_repeat('─', 62) . "\n";

    $problems = [];

    // 1. Connected?
    $state = $evo->connectionState($instance);
    $open  = ($state === 'open');
    printf("  %-24s %s\n", 'connection', $open ? 'open' : strtoupper((string)($state ?? 'unreachable')));
    if (!$open) $problems[] = 'the instance is not connected, so WhatsApp delivers nothing to us';

    // 2. Webhook ours?
    $hook = $evo->findWebhook($instance);
    $url  = (string)($hook['url'] ?? ($hook['data']['url'] ?? ''));
    $ours = $url !== '' && strpos($url, 'evo_webhook') !== false;
    printf("  %-24s %s\n", 'webhook', $ours ? 'registered' : ($url === '' ? 'NOT REGISTERED' : 'points elsewhere'));
    if (!$ours) $problems[] = 'Evolution has nowhere to post incoming messages';

    // 3-4. Traffic in the last hour.
    $in = $pdo->prepare("SELECT COUNT(*) FROM wa_messages m
        JOIN wa_conversations c ON c.id = m.conversation_id
        WHERE c.channel = ? AND m.direction = 'in' AND m.sent_at > ?");
    $in->execute([$channel, $since]);
    $inbound = (int)$in->fetchColumn();

    $out = $pdo->prepare("SELECT COUNT(*) n, MAX(m.sent_at) last FROM wa_messages m
        JOIN wa_conversations c ON c.id = m.conversation_id
        WHERE c.channel = ? AND m.role = 'assistant' AND m.sent_at > ?");
    $out->execute([$channel, $since]);
    $r = $out->fetch(PDO::FETCH_ASSOC) ?: [];
    $replies = (int)($r['n'] ?? 0);
    $lastAt  = (string)($r['last'] ?? '');

    printf("  %-24s %d\n", 'customer messages (1h)', $inbound);
    printf("  %-24s %d%s\n", 'AI replies (1h)', $replies,
        $lastAt !== '' ? '   last ' . $ago($lastAt) : '');
    if ($inbound > 0 && $replies === 0) {
        $problems[] = 'customers wrote and the assistant answered none of them';
    }

    // 5. Queue.
    $q = $pdo->prepare("SELECT COUNT(*) FROM events
        WHERE event_type = 'ai.reply' AND status IN ('pending','processing')");
    $q->execute();
    $stuck = (int)$q->fetchColumn();
    printf("  %-24s %d\n", 'queued, not answered', $stuck);
    if ($stuck > 3) $problems[] = $stuck . ' events are queued and not being worked';

    // 6. Who is waiting. The only measure the customer actually feels.
    $w = $pdo->prepare("SELECT c.id, c.phone, c.state, c.last_message_at,
            (SELECT m.direction FROM wa_messages m WHERE m.conversation_id = c.id
              ORDER BY m.id DESC LIMIT 1) AS last_dir
        FROM wa_conversations c WHERE c.channel = ?
        ORDER BY c.last_message_at DESC LIMIT 60");
    $w->execute([$channel]);
    $waiting = [];
    foreach ($w->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if (($c['last_dir'] ?? '') !== 'in') continue;
        if (time() - strtotime($c['last_message_at'] . ' UTC') < 300) continue;   // still fresh
        $waiting[] = $c;
    }
    printf("  %-24s %d\n", 'waiting > 5 min', count($waiting));
    foreach (array_slice($waiting, 0, 6) as $c) {
        printf("      c%-5s %-15s %-12s %s\n",
            $c['id'], $c['phone'], $c['state'], $ago($c['last_message_at']));
    }
    if ($waiting !== []) {
        $problems[] = count($waiting) . ' customer(s) wrote last and have had no answer';
    }

    echo "\n";
    if ($problems === []) {
        echo "  ✓ ANSWERING — connected, receiving, and replying, with nobody waiting.\n";
    } else {
        $anyBad = true;
        echo "  ✗ NOT FULLY ANSWERING\n";
        foreach ($problems as $p) echo "      - " . $p . "\n";
    }
}

echo "\n";
exit($anyBad ? 1 : 0);
