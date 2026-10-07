<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * channels.php — the WhatsApp channel registry, read-only (5.18.86, docs/65; multi-number Batch 1).
 *
 *   php tools/channels.php                       every channel, how it routes, and whether the registry is in effect
 *   php tools/channels.php --resolve <instance>  which channel a webhook from that Evolution instance would land on
 *   php tools/channels.php --trail <channel>     the append-only trail of one channel
 *
 * READ-ONLY. It changes nothing: no channel is created, switched or moved here (Batch 1 has no write path but the
 * registry's own methods, by design — docs/65 §M). It never prints a credential, and a business number only as its last
 * two digits. Instance names are printed: they are what the uCRM Configuration screen and the Evolution manager show,
 * and checking the mapping is what this tool is for.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/EvolutionApiService.php';
require_once $root . '/lib/ChannelRegistry.php';

$args = array_slice($argv, 1);
$opt = function (string $f) use ($args): ?string {
    $i = array_search($f, $args, true);
    return ($i !== false && isset($args[$i + 1])) ? (string)$args[$i + 1] : null;
};

$dataDir = cliDataDir($root);
$config  = PluginConfig::read($root, $dataDir);   // read(), not load(): this tool writes nothing, the vault included
$dbPath  = $dataDir . '/plugin.sqlite3';
if (!is_file($dbPath)) { fwrite(STDERR, "no plugin database in this data directory\n"); exit(1); }
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$reg       = new ChannelRegistry($pdo);
$flagOn    = filter_var($config[ChannelRegistry::FLAG] ?? false, FILTER_VALIDATE_BOOLEAN);
$inEffect  = ChannelRegistry::enabled($config, $dataDir);
$installed = $reg->available();
$cmap      = EvolutionApiService::configInstanceMap($config);

echo "\n  WHATSAPP CHANNELS\n\n";
printf("    %-34s %s\n", ChannelRegistry::FLAG, $flagOn ? 'ON' : 'OFF (absent means off)');
printf("    %-34s %s\n", 'registry in effect', $inEffect ? 'yes (Uganda, flag on)' : 'no — every number routes as configured');
printf("    %-34s %s\n", 'registry installed (migration 087)', $installed ? 'yes' : 'NO');
echo "\n";

if (!$installed) { echo "    The registry table is not here yet; the three department numbers route as configured.\n\n"; exit(0); }

$routing = $reg->routing($cmap);

if (($t = $opt('--trail')) !== null) {
    foreach ($reg->trail($t) as $r) {
        printf("    %s  %-10s %-14s %s → %s  (%s)\n", $r['created_at'], $r['action'], $r['actor'],
            $r['old_value'] ?? '—', $r['new_value'] ?? '—', $r['reason']);
    }
    echo "\n";
    exit(0);
}

if (($inst = $opt('--resolve')) !== null) {
    $evo = $inEffect ? EvolutionApiService::forStore($config, $pdo, $dataDir) : new EvolutionApiService($config);
    $ch  = $evo->channelFor($inst);
    $st  = $evo->instanceState($inst);
    if ($ch !== '')           echo "    → channel {$ch}" . ($inEffect ? ' (through the registry)' : ' (configuration)') . "\n\n";
    elseif ($st === 'refused') echo "    → REFUSED: the registry knows this instance and has switched its channel off (webhook answers channel_disabled)\n\n";
    else                       echo "    → UNKNOWN: no channel; the webhook answers unknown_instance and stores nothing\n\n";
    exit(0);
}

foreach ($reg->rows() as $id => $r) {
    $dept   = in_array($id, ChannelRegistry::DEPARTMENT, true);
    $inst   = $dept ? (string)($cmap[$id] ?? '') : (string)($r['evo_instance'] ?? '');
    $source = $dept ? 'configuration (evo_instance_' . $id . ')' : 'registry';
    $routes = isset($routing['channel_to_instance'][$id]) ? 'routed'
            : (in_array($id, $routing['conflicts'], true) ? 'NOT ROUTED — instance belongs to a department number'
            : ($inst === '' ? 'no instance' : 'refused (' . $r['status'] . ')'));
    $owner  = $r['owner_type'] . ($r['owner_staff_id'] !== null ? ' #' . $r['owner_staff_id'] : '')
            . ($r['owner_partner_id'] !== null ? ' #' . $r['owner_partner_id'] : '');
    printf("    %-14s %-8s %-9s ai=%-3s %s\n", $id, $r['role'], $r['status'], (int)$r['ai_enabled'] === 1 ? 'on' : 'off', $r['display_name']);
    printf("    %-14s instance %s — %s; %s\n", '', $inst !== '' ? $inst : '(none)', $source, $routes);
    printf("    %-14s owner %s, territory %s, portfolio %s, number %s\n\n", '', $owner,
        $r['territory_region_id'] !== null ? '#' . $r['territory_region_id'] : 'none', $r['portfolio_scope'],
        ChannelRegistry::mask($r['business_number']));
}
