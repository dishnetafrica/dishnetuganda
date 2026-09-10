<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * set_sales_everywhere.php — can every number answer a sales question?
 *
 *   php tools/set_sales_everywhere.php          what it is set to now
 *   php tools/set_sales_everywhere.php --on     support and accounts can sell too
 *   php tools/set_sales_everywhere.php --off    each number keeps to its own role
 *
 * Each WhatsApp number is mapped to a channel — sales, support or accounts —
 * and the channel decides the assistant's role. Someone messaging the support
 * number to ask what internet costs was getting troubleshooting steps or a
 * handover, because the support role says nothing about plans or prices. Both
 * numbers could not be put on the sales channel either: evo_instance_sales
 * holds one instance name.
 *
 * With this on, the channel still decides the PRIMARY role; the other numbers
 * simply gain the ability to answer a sales question where it was asked,
 * under the sales role's own guardrails — real plans at real prices, monthly
 * and one-time never blended, coverage and install dates never confirmed.
 *
 * Off by default. South Sudan runs genuinely separate desks and its prompts
 * are unchanged unless this is set.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/EvolutionApiService.php';

$dataDir = getenv('DN_DATA_DIR') ?: getDataDir($root);
$config  = PluginConfig::load($root, $dataDir);
$args    = array_slice($argv, 1);

$on = function (array $c): bool {
    return filter_var($c['ai_sales_on_all_numbers'] ?? false, FILTER_VALIDATE_BOOLEAN);
};

if (!in_array('--on', $args, true) && !in_array('--off', $args, true)) {
    echo "\n  Sales answered on every number   " . ($on($config) ? 'YES' : 'NO (the default)') . "\n\n";
    $evo = new EvolutionApiService($config);
    foreach (EvolutionApiService::CHANNELS as $ch) {
        $inst = $evo->instanceFor($ch);
        if ($inst === '') continue;
        printf("    %-9s %-20s %s\n", $ch, $inst,
            $ch === 'sales' ? 'sells' : ($on($config) ? 'sells too' : 'hands sales over'));
    }
    echo "\n    php tools/set_sales_everywhere.php --on\n";
    echo "    php tools/set_sales_everywhere.php --off\n\n";
    exit(0);
}

$want = in_array('--on', $args, true);
list($ok, $err) = PluginConfig::saveOverrides($dataDir, ['ai_sales_on_all_numbers' => $want ? '1' : '0']);
if (!$ok) { echo "\n  Could not save: " . (string)$err . "\n\n"; exit(1); }

$fresh = PluginConfig::load($root, $dataDir);
echo "\n  Sales answered on every number: " . ($on($fresh) ? 'YES' : 'NO') . "\n";
echo $want
    ? "  Every mapped number can now advise on plans and quote real prices.\n"
    . "  Each still keeps its own primary role.\n\n"
    : "  Only the sales number sells; the others hand sales enquiries over.\n\n";
exit(0);
