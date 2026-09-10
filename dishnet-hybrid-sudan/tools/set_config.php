<?php
declare(strict_types=1);
chdir(dirname(__DIR__));
/**
 * set_config.php — turn a plugin setting on or off from the terminal.
 *
 *   php tools/set_config.php                          what the AI flags are set to
 *   php tools/set_config.php --key <name> --value 1   set one
 *   php tools/set_config.php --key <name> --clear     back to the default
 *
 * Every feature added to the AI is gated on a config key whose absence means
 * the old behaviour, so South Sudan is never moved by a Uganda change. That is
 * the right design and it has one consequence: shipping a feature is not
 * enabling it, and there was no way to enable one without a browser.
 *
 * Secrets are refused. PluginConfig::saveOverrides rejects them at the write,
 * and they belong on the uCRM Configuration screen where they are stored
 * encrypted — never in a shell command, which lands in root's history.
 *
 * CLI only.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/PluginConfig.php';

$args  = array_slice($argv, 1);
$value = function (string $f) use ($args) {
    $i = array_search($f, $args, true);
    return ($i !== false && isset($args[$i + 1])) ? (string)$args[$i + 1] : '';
};

$dataDir = getenv('DN_DATA_DIR') ?: getDataDir($root);

// The flags that change how the assistant behaves. Listed so `set_config.php`
// with no arguments answers "what is switched on?", which is the question
// actually being asked at a terminal.
$FLAGS = [
    'ai_qualification'        => 'Qualify before recommending; route CCTV/VPN/servers to Business',
    'ai_hardware_expert'      => 'Know the dishes: coverage vs Wi-Fi, Ethernet per model, solar',
    'ai_sales_on_all_numbers' => 'Let every number answer a sales question',
    'ai_handover_message'     => 'What the customer hears when the AI hands over',
    'wa_human_cooldown_minutes' => 'How long the AI stays quiet after a colleague replies',
    'stock_statement'         => 'What to say about availability',
    'ai_currency'             => 'Currency prices are stated in',
];

$show = function () use ($root, $dataDir, $FLAGS) {
    $cfg = PluginConfig::load($root, $dataDir);
    echo "\n  AI SETTINGS\n\n";
    foreach ($FLAGS as $k => $what) {
        $raw = $cfg[$k] ?? null;
        $set = !($raw === null || $raw === '');
        $on  = $set && filter_var($raw, FILTER_VALIDATE_BOOLEAN);
        $shown = !$set ? 'not set (default)'
               : (is_numeric($raw) || in_array(strtolower((string)$raw), ['1','0','true','false'], true)
                    ? ($on ? 'ON' : 'OFF')
                    : '"' . mb_substr((string)$raw, 0, 40) . '"');
        printf("    %-27s %s\n", $k, $shown);
        printf("    %-27s %s\n\n", '', $what);
    }
};

$key = trim($value('--key'));
if ($key === '') {
    $show();
    echo "    php tools/set_config.php --key ai_qualification --value 1\n";
    echo "    php tools/set_config.php --key ai_qualification --clear\n\n";
    exit(0);
}

if (!array_key_exists($key, $FLAGS)) {
    echo "\n  \"" . $key . "\" is not one of the settings this tool manages.\n\n";
    echo "  It manages:\n";
    foreach (array_keys($FLAGS) as $k) echo "      " . $k . "\n";
    echo "\n  Anything else — and every secret — belongs on the uCRM\n";
    echo "  Configuration screen, where it is stored encrypted.\n\n";
    exit(1);
}

$clear = in_array('--clear', $args, true);
if (!$clear && !in_array('--value', $args, true)) {
    echo "\n  Give --value <v>, or --clear to return it to the default.\n\n";
    exit(1);
}
$new = $clear ? '' : $value('--value');

list($ok, $err) = PluginConfig::saveOverrides($dataDir, [$key => $new]);
if (!$ok) { echo "\n  Could not save: " . (string)$err . "\n\n"; exit(1); }

echo "\n  " . $key . ($clear ? ' cleared — back to the default.' : ' = ' . $new) . "\n";
$show();
exit(0);
