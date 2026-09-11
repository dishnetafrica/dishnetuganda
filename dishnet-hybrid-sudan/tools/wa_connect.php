<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * wa_connect.php — is each WhatsApp number actually connected, and re-pair it.
 *
 *   php tools/wa_connect.php                            state of every mapped number
 *   php tools/wa_connect.php --pair dishnet_richard     get a pairing code for it
 *
 * A registered webhook is not a working number. dishnet_richard held a correct
 * webhook while sitting at state=close, which means Evolution had nothing to
 * forward: the assistant would have looked silently broken on that number with
 * every check passing.
 *
 * Baileys sessions drop by themselves — that instance went from open to close
 * inside seven minutes. Re-pairing normally means standing in front of the
 * Evolution manager with the handset to scan a QR. A pairing code can be read
 * out over a phone call instead, and typed into the handset at
 * WhatsApp > Linked devices > Link with phone number.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/EvolutionApiService.php';

$dataDir = cliDataDir($root);
$config  = PluginConfig::load($root, $dataDir);
$evo     = new EvolutionApiService($config);

if (!$evo->isConfigured()) {
    echo "\n  Evolution is not configured here — no API URL, key or mapped instance.\n\n";
    exit(1);
}

$args  = array_slice($argv, 1);
$value = function (string $flag) use ($args) {
    $i = array_search($flag, $args, true);
    return ($i !== false && isset($args[$i + 1])) ? (string)$args[$i + 1] : '';
};

// ── Re-pair one instance ─────────────────────────────────────────────────
if ($value('--pair') !== '') {
    $inst = $value('--pair');
    $num  = $value('--number');

    $r = $evo->connect($inst, $num);
    if (empty($r['ok'])) {
        echo "\n  Evolution refused: " . json_encode($evo->getLastError()) . "\n\n";
        exit(1);
    }

    $code = (string)($r['pairing_code'] ?? '');
    echo "\n  " . $inst . "\n";
    if ($code !== '') {
        echo "\n  PAIRING CODE   " . $code . "\n\n";
        echo "  On the handset for that number:\n";
        echo "    WhatsApp > Linked devices > Link with phone number > enter the code\n";
        echo "  It expires in a couple of minutes.\n\n";
    } elseif (!empty($r['qr'])) {
        echo "\n  Evolution returned a QR image rather than a code.\n";
        echo "  Pass --number 2567XXXXXXXX to ask for a code instead, or scan the QR\n";
        echo "  from the Evolution manager.\n\n";
    } else {
        echo "\n  Evolution accepted the request but returned neither a code nor a QR.\n";
        echo "  It is usually already connected — check with this tool and no flags.\n\n";
    }
    exit(0);
}

// ── State of every mapped number ─────────────────────────────────────────
echo "\n  WHATSAPP NUMBERS\n";
echo "  " . str_repeat('-', 62) . "\n";

$down = [];
foreach ($evo->channelHealth() as $channel => $h) {
    printf("  %-9s %-20s %s\n", $channel, (string)$h['instance'],
        !empty($h['connected']) ? 'connected' : strtoupper((string)$h['state']));
    if (empty($h['connected'])) $down[] = (string)$h['instance'];
}

if ($down === []) {
    echo "\n  All mapped numbers are connected.\n\n";
    exit(0);
}

echo "\n  Not connected: " . implode(', ', $down) . "\n";
echo "  Evolution has nothing to forward for these, so the assistant will not\n";
echo "  answer on them however healthy the webhook looks.\n\n";
foreach ($down as $inst) {
    echo "    php tools/wa_connect.php --pair " . $inst . " --number 2567XXXXXXXX\n";
}
echo "\n";
exit(1);
