<?php
declare(strict_types=1);
chdir(dirname(__DIR__));
/**
 * set_evolution.php — point the plugin at Evolution, from the command line.
 *
 *   php tools/set_evolution.php                     what is configured, and whether it answers
 *   php tools/set_evolution.php --url <base url>    set the API URL
 *   php tools/set_evolution.php --key -             read the API key from stdin
 *   php tools/set_evolution.php --sales <name> --support <name>
 *
 * uCRM's Configuration screen is the ordinary place for these. This exists
 * for when that screen is awkward to reach mid-outage, and it writes through
 * the same path the plugin's own settings page uses: kyc_config.json, which
 * is merged LAST at load, so what is set here wins over the uCRM form until
 * it is cleared with an empty value.
 *
 * THE KEY IS NEVER AN ARGUMENT. A secret on the command line is in the
 * shell history and visible in `ps` to every user on the box. --key reads
 * from stdin so the value never becomes a command:
 *
 *   read -rsp 'Evolution API key: ' K; echo
 *   printf '%s' "$K" | docker exec -i -u nginx ucrm \
 *     php /data/.../tools/set_evolution.php --key -
 *   unset K
 *
 * CLI only.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/EvolutionApiService.php';

$dataDir = cliDataDir($root);
$args    = array_slice($argv ?? [], 1);
$val     = function (string $flag) use ($args) {
    $i = array_search($flag, $args, true);
    return $i === false ? null : (string)($args[$i + 1] ?? '');
};

$changed = [];

// ── The API URL ─────────────────────────────────────────────────────────────
$url = $val('--url');
if ($url !== null) {
    $url = EvolutionApiService::normaliseBaseUrl($url);
    if ($url === '') {
        echo "\n  Give a base URL, e.g. --url https://evo.dishnetuganda.com\n\n";
        exit(1);
    }
    // The API key travels in an 'apikey:' request header on every single
    // call. Over http that header crosses the network in clear, and the key
    // controls every WhatsApp session this company has.
    if (stripos($url, 'http://') === 0 && !in_array('--allow-insecure', $args, true)) {
        echo "\n  Refusing " . $url . "\n\n";
        echo "  The API key is sent as a header on every request. Over http it\n";
        echo "  travels in clear, and that key controls every WhatsApp session\n";
        echo "  here. Use https — the proxy already terminates TLS for this host.\n\n";
        echo "  If this really is a private address with no TLS, and you accept\n";
        echo "  that, add --allow-insecure.\n\n";
        exit(1);
    }
    $cur = PluginConfig::load($root, $dataDir);
    list($ok, $err) = PluginConfig::saveEvolutionCredentials($dataDir, $url, '');
    if (!$ok) { echo "\n  " . $err . "\n\n"; exit(1); }
    $changed[] = 'API URL  ' . $url;
}

// ── The API key, from stdin only ────────────────────────────────────────────
if (in_array('--key', $args, true)) {
    $where = $val('--key');
    if ($where !== '-' && $where !== '' && $where !== null) {
        echo "\n  Do not pass the key as an argument — it lands in your shell\n";
        echo "  history and is visible in `ps` to everyone on this machine.\n\n";
        echo "  Read it into a variable and pipe it instead:\n\n";
        echo "    read -rsp 'Evolution API key: ' K; echo\n";
        echo "    printf '%s' \"\$K\" | docker exec -i -u nginx ucrm \\\n";
        echo "      php /data/ucrm/data/plugins/dishnet-hybrid-sudan/tools/set_evolution.php --key -\n";
        echo "    unset K\n\n";
        exit(1);
    }
    $key = trim((string)stream_get_contents(STDIN));
    if ($key === '') {
        echo "\n  Nothing arrived on stdin, so nothing was changed.\n\n";
        exit(1);
    }
    $existing = PluginConfig::load($root, $dataDir);
    $keepUrl  = (string)($existing['evo_api_url'] ?? '');
    list($ok, $err) = PluginConfig::saveEvolutionCredentials($dataDir, $keepUrl, $key);
    if (!$ok) { echo "\n  " . $err . "\n\n"; exit(1); }
    $changed[] = 'API key  stored, ' . strlen($key) . ' characters (not shown)';
}

// ── Instance names ──────────────────────────────────────────────────────────
$map = [];
foreach (['--sales' => 'evo_instance_sales', '--support' => 'evo_instance_support',
          '--account' => 'evo_instance_account'] as $flag => $key) {
    $v = $val($flag);
    if ($v !== null) $map[$key] = trim($v);
}
if ($map) {
    list($ok, $err) = PluginConfig::saveOverrides($dataDir, $map);
    if (!$ok) { echo "\n  " . $err . "\n\n"; exit(1); }
    foreach ($map as $k => $v) {
        $changed[] = str_pad(substr($k, 13), 8) . ' ' . ($v === '' ? '(cleared)' : $v);
    }
}

if ($changed) {
    echo "\n  SAVED\n\n";
    foreach ($changed as $c) echo "    " . $c . "\n";
    echo "\n  Written to kyc_config.json, which is merged last — this now\n";
    echo "  overrides the uCRM Configuration screen for these fields.\n";
}

// ── What is configured now, and does it answer? ─────────────────────────────
$config = PluginConfig::load($root, $dataDir);
$u = trim((string)($config['evo_api_url'] ?? ''));
$k = trim((string)($config['evo_api_key'] ?? ''));

echo "\n  EVOLUTION\n\n";
printf("    %-10s %s\n", 'url', $u !== '' ? $u : '(not set)');
printf("    %-10s %s\n", 'key', $k !== '' ? 'set, ' . strlen($k) . ' characters' : '(not set)');
foreach (['sales' => 'evo_instance_sales', 'support' => 'evo_instance_support',
          'account' => 'evo_instance_account'] as $label => $key) {
    $v = trim((string)($config[$key] ?? ''));
    if ($v !== '') printf("    %-10s %s\n", $label, $v);
}

if ($u === '' || $k === '') {
    echo "\n  Both the URL and the key are needed before anything can be checked.\n\n";
    exit(1);
}

echo "\n  WHAT EVOLUTION HAS\n\n";
// The service normalises Evolution's two response shapes and its two words
// for connected, so this does not have to know which build is running.
$svc  = new EvolutionApiService($config);
$rows = $svc->listInstances();
if (!$rows) {
    echo "    Nothing came back.\n\n";
    echo "    Either the URL is not Evolution, the key is wrong, or no instances\n";
    echo "    have been created yet. Create them with the exact names above.\n\n";
    exit(1);
}

$have = [];
foreach ($rows as $r) {
    $have[$r['name']] = $r;
    printf("    %-24s %-12s %s\n", $r['name'], $r['state'],
           $r['phone'] !== '' ? $r['phone'] : ($r['connected'] ? '' : 'not paired'));
}

$want = array_filter([
    'sales'   => trim((string)($config['evo_instance_sales'] ?? '')),
    'support' => trim((string)($config['evo_instance_support'] ?? '')),
    'account' => trim((string)($config['evo_instance_account'] ?? '')),
]);

$missing = [];
foreach ($want as $role => $name) {
    if (!isset($have[$name])) $missing[] = $role . ' expects "' . $name . '"';
}
echo "\n";
if ($missing) {
    echo "  NOT FOUND\n\n";
    foreach ($missing as $m) echo "    " . $m . "\n";
    echo "\n  The names must match exactly — Evolution is asked for them by name,\n";
    echo "  so a different spelling means the assistant answers on nothing.\n\n";
    exit(1);
}
// Existing is not the same as usable: an instance can sit unpaired for days
// and the assistant answers on nothing while everything looks configured.
$unpaired = [];
foreach ($want as $role => $name) {
    if (!($have[$name]['connected'] ?? false)) $unpaired[] = $role . ' (' . $name . ')';
}
if ($unpaired) {
    echo "  NOT PAIRED\n\n";
    foreach ($unpaired as $x) echo "    " . $x . "\n";
    echo "\n  These exist but are not connected to a phone, so nothing is\n";
    echo "  forwarded and the assistant answers on them however healthy the\n";
    echo "  rest of this looks. Scan the QR, then:\n\n";
    echo "    php tools/wa_connect.php\n\n";
    exit(1);
}
echo "  Every configured instance exists and is paired.\n\n";
exit(0);
