<?php
declare(strict_types=1);
chdir(dirname(__DIR__));
/**
 * crm_url_check.php — the address customers are actually sent to.
 *
 *   php tools/crm_url_check.php                  report
 *   php tools/crm_url_check.php --set <url>      set the override
 *   php tools/crm_url_check.php --clear          remove it
 *
 * Clicking https://crm.dishnetuganda.com/crm landed on port 8443. uCRM
 * builds its redirects and links from the address it was CONFIGURED with,
 * and behind a reverse proxy that is often the INTERNAL one — 8443 is what
 * the proxy forwards to, not something a browser can open.
 *
 * That address is not only a redirect. uCRM writes it into ucrm.json, this
 * plugin reads it, and it becomes every "View in CRM" link, every portal
 * link and every URL in a quote email. One wrong port, published in many
 * places, reaching customers.
 *
 * The real fix is in uCRM: Settings -> System -> Application, server domain
 * and port as a CUSTOMER's browser sees them. This tool finds the problem,
 * and --set works around it until that is done.
 *
 * 5.18.34. The override is kept in config.json AND the vault, and any second
 * copy (kyc_config.json, the settings store) is removed, so there is exactly
 * one value. Until then the admin screens, the customer portal and about a
 * hundred other readers never saw config.json at all, and kept :8443 with
 * the override set. The report now shows where the value is, what those
 * readers and the crons each build, and where /crm, /crm/ and /crm/login
 * land — the bare /crm is where the website's Customer Login went.
 *
 * CLI only.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/ConfigVault.php';
require_once $root . '/lib/crm_url.php';

$dataDir = cliDataDir($root);
$config  = PluginConfig::load($root, $dataDir);
$argv    = $argv ?? [];

/** Everything a generated link is built from, in one place. */
function dn_probe(string $url): array
{
    $u = parse_url($url);
    if (!is_array($u) || empty($u['host'])) return ['ok' => false, 'why' => 'not a URL'];
    $scheme = strtolower((string)($u['scheme'] ?? ''));
    $port   = isset($u['port']) ? (int)$u['port'] : ($scheme === 'https' ? 443 : 80);
    $normal = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
    return ['ok' => true, 'scheme' => $scheme, 'host' => $u['host'],
            'port' => $port, 'normal' => $normal, 'explicit' => isset($u['port'])];
}

/**
 * The settings store's copy of kyc_config.json — what public.php and most
 * crons read — or null when there is no store. Never creates one: a new
 * store imports and renames every *.json in the directory.
 */
function dn_store_copy(string $dataDir): ?array
{
    if (!is_file(rtrim($dataDir, '/') . '/plugin.sqlite3')) return null;
    try {
        $lib = dirname(__DIR__) . '/lib';
        require_once $lib . '/StoreInterface.php';
        require_once $lib . '/JsonStore.php';
        require_once $lib . '/SqliteStore.php';
        $c = SqliteStore::create($dataDir)->load('kyc_config.json');
        return is_array($c) ? $c : [];
    } catch (\Throwable $e) {
        return null;
    }
}

/** A JSON file's value for the key: null when the file or the key is absent. */
function dn_file_value(string $path, string $key): ?string
{
    if (!is_file($path)) return null;
    $d = json_decode((string)@file_get_contents($path), true);
    return is_array($d) && array_key_exists($key, $d) ? (string)$d[$key] : null;
}

/** The vault's copy, read without the side effects of loading config. */
function dn_vault_value(string $root, string $dataDir): ?string
{
    $d = json_decode((string)@file_get_contents(ConfigVault::path($root, $dataDir)), true);
    $v = is_array($d) ? ($d['config']['crm_public_url'] ?? null) : null;
    return $v === null ? null : (string)$v;
}

// ── --set / --clear ─────────────────────────────────────────────────────────
$setAt = array_search('--set', $argv, true);
$clear = in_array('--clear', $argv, true);
if ($setAt !== false || $clear) {
    $val = $clear ? '' : trim((string)($argv[$setAt + 1] ?? ''));
    if (!$clear) {
        $p = dn_probe($val);
        if (!$p['ok'] || ($p['scheme'] !== 'http' && $p['scheme'] !== 'https')) {
            echo "\n  \"" . $val . "\" is not an absolute http(s) URL.\n";
            echo "  Give the address a customer's browser uses, e.g.\n";
            echo "    php tools/crm_url_check.php --set https://crm.dishnetuganda.com\n\n";
            exit(1);
        }
    }

    // 1. config.json in the data directory: where this value has always lived.
    $file = rtrim($dataDir, '/') . '/config.json';
    $cur  = is_file($file) ? (array)json_decode((string)@file_get_contents($file), true) : [];
    if ($val === '') { unset($cur['crm_public_url']); } else { $cur['crm_public_url'] = $val; }
    if (@file_put_contents($file, json_encode($cur, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
        echo "\n  Could not write " . $file . "\n  Check it is writable by this user.\n\n";
        exit(1);
    }

    // 2. The vault, so a re-install that loses config.json does not lose this.
    //    An empty value removes it; nothing else can, the vault gap-fills.
    $v = ConfigVault::store($root, $dataDir, ['crm_public_url' => $val]);
    if (empty($v['ok'])) {
        echo "\n  config.json is updated, but the vault is NOT: " . ($v['error'] ?? 'unknown') . "\n";
        echo "  Run the same command again once that is fixed.\n\n";
        exit(1);
    }

    // 3. One value. A second copy in kyc_config.json or the settings store
    //    would win on some screens and lose on others.
    $inFile  = dn_file_value(rtrim($dataDir, '/') . '/kyc_config.json', 'crm_public_url') !== null;
    $store   = dn_store_copy($dataDir);
    $inStore = is_array($store) && array_key_exists('crm_public_url', $store);
    if ($inFile || $inStore) {
        [$ok, $err] = PluginConfig::saveOverrides($dataDir, ['crm_public_url' => '']);
        if (!$ok) {
            echo "\n  config.json and the vault are updated, but an older copy in\n";
            echo "  kyc_config.json could not be removed: " . $err . "\n\n";
            exit(1);
        }
        echo "\n  Removed an older copy from " . ($inFile ? 'kyc_config.json' : '')
           . ($inFile && $inStore ? ' and ' : '') . ($inStore ? 'the settings store' : '') . ".";
    }

    echo "\n  " . ($val === '' ? 'Override removed — links follow uCRM again.'
                               : 'Links will now be built on ' . $val) . "\n";
    echo "  Re-run with no arguments to see the result.\n\n";
    exit(0);
}

// ── Report ──────────────────────────────────────────────────────────────────
$json     = dn_ucrm_json();
$ucrmPub  = trim((string)($json['ucrmPublicUrl'] ?? ''));
$ucrmLoc  = trim((string)($json['ucrmLocalUrl'] ?? ''));
$plugPub  = trim((string)($json['pluginPublicUrl'] ?? ''));
$override = trim((string)($config['crm_public_url'] ?? ''));
$store    = dn_store_copy($dataDir);

echo "\n  WHAT uCRM REPORTS\n\n";
printf("    %-18s %s\n", 'ucrmPublicUrl', $ucrmPub !== '' ? $ucrmPub : '(not set)');
printf("    %-18s %s\n", 'ucrmLocalUrl',  $ucrmLoc !== '' ? $ucrmLoc : '(not set)');
printf("    %-18s %s\n", 'pluginPublicUrl', $plugPub !== '' ? $plugPub : '(not set)');

$problems = [];
$p = $ucrmPub !== '' ? dn_probe($ucrmPub) : ['ok' => false];
if ($p['ok'] && !$p['normal']) {
    echo "\n    ↑ port " . $p['port'] . " is not the standard port for " . $p['scheme'] . ".\n";
    echo "      Behind a reverse proxy that is usually the INTERNAL port: what the\n";
    echo "      proxy forwards to, not what a browser can open. Every link below\n";
    echo "      carries it, including the ones that reach customers, unless the\n";
    echo "      override is set.\n";
    if ($override === '') {
        $problems[] = 'uCRM publishes port ' . $p['port'] . ' and no override is set';
    }
}

echo "\n  WHERE THE OVERRIDE IS KEPT\n\n";
$shown = static fn(?string $v): string => $v === null ? '—' : ($v === '' ? '(empty)' : $v);
$inCfg   = dn_file_value(rtrim($dataDir, '/') . '/config.json', 'crm_public_url');
$inKyc   = dn_file_value(rtrim($dataDir, '/') . '/kyc_config.json', 'crm_public_url');
$inStore = is_array($store) && array_key_exists('crm_public_url', $store) ? (string)$store['crm_public_url'] : null;
$inVault = dn_vault_value($root, $dataDir);
printf("    %-18s %s\n", 'config.json', $shown($inCfg));
printf("    %-18s %s\n", 'vault', $shown($inVault));
printf("    %-18s %s\n", 'kyc_config.json', $shown($inKyc));
printf("    %-18s %s\n", 'settings store', $store === null ? '(no store here)' : $shown($inStore));
if ($inCfg !== null && $inCfg !== '' && ($inVault === null || $inVault === '')) {
    echo "\n    ↑ not in the vault yet: a re-install would lose it. Set it again\n";
    echo "      with --set, which writes both.\n";
    $problems[] = 'crm_public_url is not in the vault';
}
// config.json is the one home. A copy elsewhere wins wherever THAT copy is
// read -- kyc_config.json over config.json in a full load, the store on the
// screens -- so a stale one quietly beats the right one.
foreach (['kyc_config.json' => $inKyc, 'settings store' => $inStore] as $where => $v) {
    if ($v === null || trim($v) === '') continue;
    if ($inCfg !== null && trim($inCfg) !== '' && trim($v) !== trim($inCfg)) {
        echo "\n    ↑ " . $where . " holds a DIFFERENT value from config.json, which wins\n";
        echo "      wherever that copy is read. --set with the right address removes it.\n";
        $problems[] = $where . ' holds a different crm_public_url';
    } else {
        echo "\n    ↑ a copy also sits in " . $where . ". Harmless while it matches;\n";
        echo "      --set removes it, so there is one value.\n";
    }
}

echo "\n  WHAT THIS PLUGIN GENERATES\n\n";
// Two readers, because they used to disagree: the crons and the webhook load
// everything; the admin screens, the portal and the API read the store copy.
$readers = ['crons, webhook' => $config];
if (is_array($store)) $readers['screens, portal, API'] = $store;
printf("    %-18s %s\n", 'override',  $override !== '' ? $override : '(none — following uCRM)');
foreach ($readers as $who => $cfg) {
    echo "\n    as the " . $who . " build them:\n";
    printf("      %-16s %s\n", 'CRM home',  dn_crm_web($cfg) ?: '(nothing — no URL to build on)');
    printf("      %-16s %s\n", 'a client',  dn_crm_link($cfg, 'client/1'));
    printf("      %-16s %s\n", 'public.php', dn_plugin_public($cfg));
    printf("      %-16s %s\n", 'DPO return', dn_plugin_public($cfg) . '?page=dpo_return');
    printf("      %-16s %s\n", 'DPO push', dn_plugin_public($cfg) . '?page=dpo_push');
    $pp = dn_probe(dn_plugin_public($cfg));
    if ($pp['ok'] && !$pp['normal']) {
        $problems[] = 'the ' . $who . ' build links on port ' . $pp['port'];
    }
}
if ($override !== '' && dn_public_override($config) === '') {
    echo "\n    ↑ the override is set but unusable, so it is being IGNORED.\n";
    echo "      It must be an absolute http(s) URL, e.g. https://crm.dishnetuganda.com\n";
    $problems[] = 'crm_public_url is set to something unusable and is being ignored';
}

// ── Does the public address actually answer? ────────────────────────────────
$test = dn_crm_web($config);
if ($test !== '' && function_exists('curl_init')) {
    echo "\n  WHAT THAT ADDRESS ANSWERS\n\n";
    echo "    Asked from INSIDE the uCRM container. That request may never leave\n";
    echo "    the host, so it can reach the internal server directly and see a\n";
    echo "    redirect a customer's browser would not. Evidence about the server,\n";
    echo "    not proof about the outside world — confirm from a phone on mobile\n";
    echo "    data before concluding either way.\n\n";
    // /crm with no slash is what the website's Customer Login used. The
    // server in front of uCRM answers it with a redirect to /crm/ that carries
    // its own port. /crm/ and /crm/login skip that redirect.
    foreach ([$test . '/crm' => 'the bare /crm', $test . '/crm/' => 'with the slash',
              $test . '/crm/login' => 'the sign-in page'] as $url => $label) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true,
                                CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => false,
                                CURLOPT_FOLLOWLOCATION => false]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $loc  = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($code === 0) {
            printf("    %-46s unreachable: %s\n", $url, $err !== '' ? $err : 'no response');
            $problems[] = $url . ' does not answer from this server';
            continue;
        }
        printf("    %-46s %d%s\n", $url, $code, $loc !== '' ? '  ->  ' . $loc : '');
        if ($loc !== '') {
            $lp = dn_probe($loc);
            if ($lp['ok'] && !$lp['normal']) {
                echo "      ↑ " . $label . " redirects to port " . $lp['port'] . ": the link is\n";
                echo "        right, the server sends the browser elsewhere.\n";
                if ($code === 301) {
                    echo "        A 301 is PERMANENT — browsers cache it. Once the server is\n";
                    echo "        fixed, anyone who hit this keeps being redirected until they\n";
                    echo "        clear it. Re-test in a private window.\n";
                }
                $problems[] = $url . ' redirects to port ' . $lp['port']
                            . ' (seen from inside the container)';
            }
        }
    }
}

echo "\n";
if (!$problems) {
    echo "  Every generated link uses a standard port and the CRM answers on it.\n\n";
    exit(0);
}
echo "  NEEDS FIXING\n\n";
foreach ($problems as $x) echo "    - " . $x . "\n";
if ($override === '') {
    echo "\n  This makes every link the PLUGIN builds use the address customers can\n";
    echo "  reach. It does NOT stop uCRM redirecting, and does not change the links\n";
    echo "  uCRM itself puts in its own e-mails:\n\n";
    echo "    php tools/crm_url_check.php --set https://" . (dn_probe($ucrmPub)['host'] ?? 'crm.example.com') . "\n";
}
echo "\n  Redirects and uCRM's own e-mails are not this plugin's:\n\n";
echo "    uCRM    Settings -> System -> Application\n";
echo "              Server domain name   the host a customer types\n";
echo "              Server port          the port their browser opens (443 for https)\n\n";
echo "    UISP    if uCRM already shows the right values and the redirect stays,\n";
echo "            the port is UISP's. It is chosen at install and is NOT in the\n";
echo "            settings UI, so look at the install config and the container:\n\n";
echo "              grep -riE port /home/unms/app/unms.conf\n";
echo "              docker inspect unms --format \'{{range .Config.Env}}{{println .}}{{end}}\' | grep -i port\n\n";
echo "            Do NOT change UISP's own HTTPS port. Devices connect on it\n";
echo "            (docs/05): moving it takes routers offline.\n\n";
exit(1);
