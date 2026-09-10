<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * wa_send_test.php — send one message down the real path and show the answer.
 *
 *   php tools/wa_send_test.php --to <a handset you hold>
 *   php tools/wa_send_test.php --to <your number> --channel support --text "hello"
 *
 * The assistant's log proves it produced a reply and that sendText returned
 * ok. Neither proves WhatsApp delivered anything: ok means only that Evolution
 * answered under HTTP 400, and nothing records what it said afterwards. Twenty
 * customers were answered by that measure while the number looked silent.
 *
 * This uses the same EvolutionApiService the worker uses, on the same
 * configured instance, and prints Evolution's raw reply — the message id and
 * status it hands back, or the error it refuses with.
 *
 * It really does send a WhatsApp message, so --to is required and never
 * defaulted.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/EvolutionApiService.php';

$dataDir = getenv('DN_DATA_DIR') ?: getDataDir($root);
$config  = PluginConfig::load($root, $dataDir);
$evo     = new EvolutionApiService($config);

$args  = array_slice($argv, 1);
$value = function (string $f, string $d = '') use ($args) {
    $i = array_search($f, $args, true);
    return ($i !== false && isset($args[$i + 1])) ? (string)$args[$i + 1] : $d;
};

$to      = $value('--to');
$channel = $value('--channel', 'sales');
$text    = $value('--text', 'DishNet test message — please ignore.');

if ($to === '') {
    echo "\n  Which number should this go to?\n\n";
    echo "    php tools/wa_send_test.php --to <a handset you hold> [--channel sales]\n\n";
    echo "  It sends a real WhatsApp message, so there is no default — and use a\n";
    echo "  phone you actually hold. A worked example in documentation is a real,\n";
    echo "  dialable number belonging to somebody: one was pasted straight from a\n";
    echo "  help text and a stranger received a test message.\n\n";
    exit(1);
}

// A pasted placeholder reaches Evolution as whatever digits survive: the
// literal 2567XXXXXXXX became "2567", and Evolution answered with a puzzling
// 400 about a jid that does not exist. Say so here instead.
$digits = preg_replace('/\D+/', '', $to);
if (strlen($digits) < 9) {
    echo "\n  \"" . $to . "\" is not a phone number — it reduces to " . ($digits ?: 'nothing') . ".\n";
    echo "  Use a handset you hold, with the country code and no plus.\n";
    echo "  Not an example from a help text — those belong to real people.\n\n";
    exit(1);
}

$instance = $evo->instanceFor($channel);
if ($instance === '') {
    echo "\n  No instance is mapped to the '" . $channel . "' channel.\n\n";
    exit(1);
}

$state = $evo->connectionState($instance);
echo "\n  channel    " . $channel . "\n";
echo "  instance   " . $instance . "  (" . ($state ?? 'unreachable') . ")\n";
echo "  to         " . $to . "\n";

$r = $evo->sendText($channel, $to, $text);

echo "\n  ok         " . (!empty($r['ok']) ? 'yes' : 'NO') . "\n";
echo "  http       " . (string)($r['http'] ?? '?') . "\n";
if (!empty($r['error'])) echo "  error      " . (string)$r['error'] . "\n";

// Evolution's own verdict. The message id proves it accepted the message as a
// real one; its absence on an "ok" response is exactly the case worth seeing.
$d  = $r['data'] ?? [];
$id = (string)($d['key']['id'] ?? '');
echo "  message id " . ($id !== '' ? $id : '(none returned)') . "\n";
if (isset($d['status'])) echo "  status     " . (string)$d['status'] . "\n";

echo "\n  " . json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";

if (!empty($r['ok']) && $id === '') {
    echo "  Evolution accepted this without returning a message id. That is the\n";
    echo "  shape of a send that goes nowhere, and it is what the worker treats\n";
    echo "  as success.\n\n";
}
exit(empty($r['ok']) ? 1 : 0);
