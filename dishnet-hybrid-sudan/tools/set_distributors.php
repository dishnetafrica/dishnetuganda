<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * set_distributors.php — turn the distributor pilot (WS-A, docs/49) on or off.
 *
 *   php tools/set_distributors.php --show
 *   php tools/set_distributors.php --on
 *   php tools/set_distributors.php --off
 *
 * The pilot is OFF by default. While off, NOTHING distributor is reachable —
 * the Distributors admin tab, the appoint / uCRM-link / territory / attribution
 * / notification actions, and the two uCRM-webhook draft hooks are all no-ops.
 *
 * Turning it on makes the Distributors tab and its actions available to admins
 * on the UGANDA install only (the whole feature is Uganda-gated; the flag does
 * nothing on South Sudan).
 *
 * NOTHING IS EVER SENT by the pilot: an event only DRAFTS an alert for a human
 * to approve, and approving a draft only QUEUES it — the bound transport is a
 * Null channel. Connecting a real WhatsApp number is a separate, later step,
 * not this switch.
 *
 * The switch lives in the same config store as every other switch
 * (kyc_config.json, key `distributors_enabled`), and this tool reads it back
 * after writing it — a switch that changes behaviour must never report a state
 * it has not verified (the 5.18.8 read-back lesson).
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/StaffJobsGate.php';

$dataDir = cliDataDir($root);

function dist_flag(array $c): bool { return !empty($c['distributors_enabled']); }

function dist_show(string $dataDir, array $config): void
{
    $on = dist_flag($config);
    $uganda = false;
    try { $uganda = StaffJobsGate::applies($config, $dataDir); } catch (\Throwable $e) { $uganda = false; }
    echo "\nDistributor pilot on this install:\n\n";
    printf("  %-24s %s\n", 'distributors_enabled', $on ? 'ON' : 'OFF');
    printf("  %-24s %s\n", 'tenant', $uganda ? 'Uganda' : 'not Uganda');
    printf("  %-24s %s\n", 'sending', 'NONE (draft to approve only; approving queues, it never sends)');
    echo "\n";
    if ($on && !$uganda) {
        echo "  Note: the flag is ON but this is not the Uganda install — the pilot is\n";
        echo "        Uganda-only, so nothing distributor is reachable here regardless.\n\n";
    } elseif ($on) {
        echo "  The Distributors tab and its actions are available to admins. Still nothing\n";
        echo "  is sent: alerts are drafted and queued on approval, never delivered.\n\n";
    } else {
        echo "  Off: nothing distributor is reachable. Turn on with:\n";
        echo "        php tools/set_distributors.php --on\n\n";
    }
}

$config = PluginConfig::load($root, $dataDir);

if (in_array('--show', $argv, true) || count($argv) === 1) {
    dist_show($dataDir, $config);
    exit(0);
}

$want = null;
if (in_array('--on', $argv, true))  $want = true;
if (in_array('--off', $argv, true)) $want = false;
if ($want === null) {
    fwrite(STDERR, "Nothing to do. Use --show, --on, or --off.\n");
    exit(1);
}

echo "\nBefore:\n"; dist_show($dataDir, $config);
list($ok, $err) = PluginConfig::saveOverrides($dataDir, ['distributors_enabled' => $want ? '1' : '']);
if (!$ok) { fwrite(STDERR, "FAILED: {$err}\n"); exit(1); }

// Read back from disk and verify — this is what the plugin will actually see.
$fresh = PluginConfig::load($root, $dataDir);
if (dist_flag($fresh) !== $want) {
    fwrite(STDERR, "FAILED — the file was written but distributors_enabled reads "
        . (dist_flag($fresh) ? 'ON' : 'OFF') . ", wanted " . ($want ? 'ON' : 'OFF') . "\n");
    fwrite(STDERR, "Something else supplies the value. Trace it: php tools/config_trace.php distributors_enabled\n");
    exit(1);
}
echo "After (read back from {$dataDir}/kyc_config.json):\n"; dist_show($dataDir, $fresh);
echo ($want
    ? "  The pilot is ON (Uganda only). Nothing is sent; alerts are drafted for approval.\n\n"
    : "  The pilot is OFF. Nothing distributor is reachable.\n\n");
exit(0);
