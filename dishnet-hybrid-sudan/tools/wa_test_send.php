<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * wa_test_send.php — send ONE real WhatsApp message, and say exactly what happened.
 *
 *   php tools/wa_test_send.php --to 211927797217
 *   php tools/wa_test_send.php --to 211927797217 --channel sales
 *   php tools/wa_test_send.php --to 211927797217 --instance dishnet_ug
 *   php tools/wa_test_send.php --to 211927797217 --text "custom message"
 *
 * THIS SENDS A REAL MESSAGE TO A REAL PHONE. It is the one tool here that is
 * not read-only, so it names the instance and the number it will send FROM
 * before it sends, and refuses rather than guessing when it cannot.
 *
 * Why a tool and not a one-liner: the plugin data directory is dot-prefixed,
 * config lives in two places that can disagree, and a channel name in config
 * can point at an instance the server does not have. A one-liner that gets
 * any of those wrong still prints something confident.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/ContactOptOut.php';
require_once $root . '/lib/EvolutionApiService.php';
require_once $root . '/lib/EvoWebhookGuard.php';

$args = array_slice($argv, 1);
$val  = function (string $flag) use ($args): string {
    $i = array_search($flag, $args, true);
    return ($i !== false && isset($args[$i + 1])) ? (string)$args[$i + 1] : '';
};

$to = preg_replace('/\D+/', '', $val('--to'));
if ($to === '') {
    echo "\n  Give --to <number in international form, digits only>\n\n";
    echo "      php tools/wa_test_send.php --to 211927797217\n\n";
    exit(1);
}

$dataDir = getDataDir($root);
$store   = SqliteStore::create($dataDir);

// Both sources, store winning — the same merge notify_doctor does, because a
// test that sends down a different path than production tests nothing.
$fromStore = $store->load('kyc_config.json') ?? [];
$fromFile  = [];
if (is_file($dataDir . '/kyc_config.json')) {
    $raw = @json_decode((string)@file_get_contents($dataDir . '/kyc_config.json'), true);
    if (is_array($raw)) $fromFile = $raw;
}
$config = $fromStore + $fromFile;

$evo = new EvolutionApiService($config);
if (!$evo->isConfigured()) { echo "\n  ✗ Evolution is not configured. Nothing sent.\n\n"; exit(1); }

// Which instance? An explicit one wins; otherwise the channel's.
// sendText() resolves the instance from the CHANNEL, so an explicit
// --instance has to be turned back into the channel that maps to it. Passing
// both without doing that would print one instance and send on another.
$wantInstance = trim($val('--instance'));
$channel      = trim($val('--channel')) ?: 'sales';
if ($wantInstance !== '') {
    $mapped = $evo->channelFor($wantInstance);
    if ($mapped === '') {
        echo "\n  ✗ '{$wantInstance}' is not wired to any channel, so there is no\n";
        echo "    configured path to send through it. Wire it to a channel first\n";
        echo "    (evo_instance_sales / _support / _account), or use --channel.\n\n";
        exit(1);
    }
    $channel = $mapped;
}
$instance = trim((string)($config['evo_instance_' . $channel] ?? ''));
if ($instance === '') { echo "\n  ✗ No instance configured for channel '{$channel}'. Nothing sent.\n\n"; exit(1); }

// Does the server actually have it, and what number is it? Checking first
// turns "the message never arrived" into a refusal that says why.
$list = $evo->listInstances();
$found = null;
foreach ($list as $row) if ((string)($row['name'] ?? '') === $instance) { $found = $row; break; }

echo "\n  SENDING ONE TEST MESSAGE\n  " . str_repeat('─', 72) . "\n";
printf("  %-14s %s\n", 'instance', $instance . '  (channel ' . $channel . ')');
if ($found === null) {
    printf("  %-14s %s\n\n", 'on the server', '✗ NOT FOUND');
    echo "  The server has: " . (
        $list === [] ? '(could not list instances)'
                     : implode(', ', array_map(fn($r) => (string)($r['name'] ?? '?'), $list))
    ) . "\n\n  Nothing sent.\n\n";
    exit(1);
}
printf("  %-14s %s\n", 'sending from', (string)($found['phone'] ?? ($found['number'] ?? 'unknown')));
printf("  %-14s %s\n", 'state', (string)($found['state'] ?? '?'));
printf("  %-14s %s\n", 'to', $to);

if (strtolower((string)($found['state'] ?? '')) !== 'open') {
    echo "\n  ⚠ That instance is not 'open', so the send will probably fail.\n";
}

$text = $val('--text') ?: ('DishNet test message — ' . gmdate('Y-m-d H:i:s') . ' UTC. '
      . 'Sent from the uCRM plugin via Evolution instance ' . $instance . '. No reply needed.');
printf("  %-14s %s\n\n", 'text', mb_strimwidth($text, 0, 60, '…'));

// CLASS_STAFF: a test to our own number is not marketing, and an opt-out on
// the destination must not make this silently report success.
$res  = $evo->sendText($channel, $to, $text, ContactOptOut::CLASS_STAFF);
$waId = (string)($res['data']['key']['id'] ?? $res['key']['id'] ?? '');

if ($waId !== '') {
    try { (new EvoWebhookGuard($store->getPdo(), $config))->claim($waId, $instance, 'wa_test_send'); }
    catch (\Throwable $e) { /* dedupe is a backstop */ }
}

$ok = empty($res['suppressed']) && (!isset($res['ok']) || !empty($res['ok'])) && empty($res['error']);
echo "  " . str_repeat('─', 72) . "\n";
if (!empty($res['suppressed'])) {
    echo "  ✗ SUPPRESSED — an opt-out blocked it: " . (string)($res['reason'] ?? '') . "\n\n";
    exit(1);
}
if ($ok) {
    echo "  ✓ ACCEPTED by Evolution" . ($waId !== '' ? "  (message id {$waId})" : '') . "\n";
    echo "    Accepted means Evolution took it — check the handset to confirm delivery.\n\n";
    exit(0);
}
echo "  ✗ FAILED: " . (string)($res['error'] ?? 'unknown error') . "\n";
if (!empty($res['status'])) echo "    HTTP " . (string)$res['status'] . "\n";
echo "\n";
exit(1);
